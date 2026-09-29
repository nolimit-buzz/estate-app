<?php
// forgot_password.php - Estate Portal Password Recovery
require_once 'config.php';
require_once 'includes/Mailer.php';

// Auto-ensure required tables exist
EstateMailer::ensureDatabaseTables($conn);

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

// Fetch Estate Details for branding
$estate_id = get_estate_id();
$sys_res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE estate_id = $estate_id");
$sys = [];
if ($sys_res) {
    while ($row = $sys_res->fetch_assoc()) {
        $sys[$row['setting_key']] = $row['setting_value'];
    }
}
$estate_name = $sys['estate_name'] ?? 'EstateAdmin';
$estate_logo = $sys['estate_logo'] ?? '';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $esc_email = $conn->real_escape_string($email);
        
        // 1. Rate Limiting Check: Prevent spamming within 60 seconds
        $recent_chk = $conn->query("SELECT id, created_at FROM password_resets 
                                    WHERE email = '$esc_email' 
                                    AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND) 
                                    ORDER BY id DESC LIMIT 1");
        if ($recent_chk && $recent_chk->num_rows > 0) {
            $error = "A verification code was recently sent to this email. Please wait at least 60 seconds before requesting a new code.";
        } else {
            // 2. Check if user exists in the database
            $u_res = $conn->query("SELECT id, name, first_name, email, status, estate_id FROM users WHERE email = '$esc_email' LIMIT 1");
            
            if ($u_res && $u_res->num_rows > 0) {
                $user = $u_res->fetch_assoc();
                
                // Account deactivation check
                if (isset($user['status']) && in_array(strtolower($user['status']), ['disabled', 'suspended', 'inactive'])) {
                    $error = "This account is currently deactivated. Please contact Estate Administration for assistance.";
                } else {
                    // 3. Generate 6-digit OTP code & secure token
                    $otp = sprintf("%06d", random_int(100000, 999999));
                    $token = bin2hex(random_bytes(32));
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                    $u_estate_id = intval($user['estate_id'] ?? $estate_id);

                    // 4. Invalidate any existing unused tokens for this email
                    $conn->query("UPDATE password_resets SET used = 1 WHERE email = '$esc_email' AND used = 0");

                    // 5. Insert new reset entry with 15-minute expiration
                    $ins = $conn->query("INSERT INTO password_resets (estate_id, email, code, token, expires_at, used, ip_address, created_at)
                                         VALUES ($u_estate_id, '$esc_email', '$otp', '$token', DATE_ADD(NOW(), INTERVAL 15 MINUTE), 0, '$ip', NOW())");

                    if ($ins) {
                        // 6. Build Direct Reset URL
                        $base_url = EstateMailer::getBaseUrl();
                        $direct_link = $base_url . "verify_code.php?email=" . urlencode($email) . "&token=" . $token;

                        // 7. Dispatch Email
                        $userName = $user['name'] ?? ($user['first_name'] ?? 'Resident / Member');
                        EstateMailer::sendPasswordResetOtp($conn, $email, $userName, $otp, $direct_link, $u_estate_id);

                        // Audit log
                        logAudit($conn, "Password Reset Requested", "Auth", "Password reset OTP requested for $email");

                        // Store email in session for verify page
                        $_SESSION['reset_email_pending'] = $email;
                        $_SESSION['reset_token_pending'] = $token;

                        // Redirect smoothly to verification page
                        header("Location: verify_code?email=" . urlencode($email));
                        exit;
                    } else {
                        $error = "Unable to process password reset request. Please try again.";
                    }
                }
            } else {
                // To avoid email enumeration while preserving user friendliness,
                // store session and redirect to verify screen with feedback
                $_SESSION['reset_email_pending'] = $email;
                header("Location: verify_code?email=" . urlencode($email));
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?php echo htmlspecialchars($estate_name); ?></title>
    
    <!-- Google Fonts: Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Landing & Auth Styles -->
    <link rel="stylesheet" href="css/landing.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/estate_notifications.css?v=<?php echo time(); ?>">
    <script src="js/estate_notifications.js?v=<?php echo time(); ?>"></script>
</head>
<body class="landing-page">
    <div class="auth-page" style="background-image: url('images/hero_estate.jpg');">
        <div class="auth-overlay"></div>
        
        <div class="auth-card">
            <a href="login" class="auth-back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to Sign In
            </a>
            
            <div class="auth-header">
                <div class="d-flex justify-content-center mb-3">
                    <?php if ($estate_logo): ?>
                        <img src="<?php echo htmlspecialchars($estate_logo); ?>" alt="Logo" style="height: 48px; border-radius: 8px;">
                    <?php else: ?>
                        <div class="glass-icon-circle hero-icon-circle glass-icon-blue" style="width: 54px; height: 54px; min-width: 54px; min-height: 54px; font-size: 1.45rem; margin: 0 auto;">
                            <i class="fa-solid fa-key"></i>
                        </div>
                    <?php endif; ?>
                </div>
                
                <h2 class="auth-title">Reset Password</h2>
                <p class="auth-subtitle">Enter your registered email address and we will send you a 6-digit verification code to reset your password.</p>
            </div>

            <?php if ($error): ?>
                <div class="auth-error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="auth-input-group">
                    <label for="email">Registered Email Address</label>
                    <div class="auth-input-wrapper">
                        <i class="fa-solid fa-envelope"></i>
                        <input type="email" id="email" name="email" class="auth-input" placeholder="name@domain.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ($_GET['email'] ?? '')); ?>" required autofocus>
                    </div>
                </div>

                <button type="submit" class="auth-btn auth-btn-admin" style="background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); margin-top: 1rem;">
                    <span>Send Verification Code</span>
                    <i class="fa-solid fa-paper-plane"></i>
                </button>
            </form>

            <div style="margin-top: 1.75rem; text-align: center; border-top: 1px solid rgba(255, 255, 255, 0.1); padding-top: 1.25rem;">
                <span style="font-size: 0.85rem; color: var(--brand-text-muted);">Remember your password?</span>
                <a href="login" style="color: #60a5fa; font-weight: 600; text-decoration: none; margin-left: 6px; font-size: 0.85rem;">
                    Sign In
                </a>
            </div>
        </div>
    </div>
</body>
</html>
