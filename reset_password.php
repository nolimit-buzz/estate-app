<?php
// reset_password.php - Set New Password After OTP Verification
require_once 'config.php';
require_once 'includes/Mailer.php';

// Auto-ensure tables exist
EstateMailer::ensureDatabaseTables($conn);

// If already logged in, redirect
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

// Determine token and email from session or query param
$token = $_SESSION['verified_reset_token'] ?? ($_POST['token'] ?? ($_GET['token'] ?? ''));
$email = $_SESSION['verified_reset_email'] ?? ($_POST['email'] ?? ($_GET['email'] ?? ''));

$error = '';
$success = '';

// Validate token in database
if (empty($token) || empty($email)) {
    header("Location: forgot_password");
    exit;
}

$esc_token = $conn->real_escape_string($token);
$esc_email = $conn->real_escape_string($email);

$token_chk = $conn->query("SELECT id, expires_at, used FROM password_resets 
                           WHERE email = '$esc_email' AND token = '$esc_token' 
                           AND used = 0 AND expires_at > NOW() 
                           ORDER BY id DESC LIMIT 1");

if (!$token_chk || $token_chk->num_rows === 0) {
    header("Location: forgot_password?error=session_expired");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($password) || empty($confirm_password)) {
        $error = "Please fill in both password fields.";
    } elseif (strlen($password) < 8) {
        $error = "Your new password must be at least 8 characters long.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match. Please verify and try again.";
    } else {
        // Hash password securely
        $hash = password_hash($password, PASSWORD_BCRYPT);
        
        // Update user's password in database and clear force_password_change flag
        $upd_user = $conn->query("UPDATE users SET password = '$hash', force_password_change = 0, password_changed_at = NOW() WHERE email = '$esc_email'");
        
        if ($upd_user) {
            // Mark token as used
            $conn->query("UPDATE password_resets SET used = 1 WHERE email = '$esc_email'");

            // Fetch user info for confirmation email
            $u_res = $conn->query("SELECT name, estate_id FROM users WHERE email = '$esc_email' LIMIT 1");
            $u_data = ($u_res && $u_res->num_rows > 0) ? $u_res->fetch_assoc() : [];
            $u_name = $u_data['name'] ?? 'User';
            $u_estate = intval($u_data['estate_id'] ?? $estate_id);

            // Send Security Confirmation Email
            EstateMailer::sendPasswordChangedConfirmation($conn, $email, $u_name, $u_estate);

            // Audit log
            logAudit($conn, "Password Reset Completed", "Auth", "Password successfully updated for $email");

            // Clean up session tokens
            unset($_SESSION['verified_reset_token'], $_SESSION['verified_reset_email'], $_SESSION['reset_email_pending'], $_SESSION['reset_token_pending']);

            // Redirect to login with flash success
            redirectWithFlash("login", "Your password has been successfully reset! You can now sign in with your new password.");
            exit;
        } else {
            $error = "Error updating password. Please try again or contact support.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password - <?php echo htmlspecialchars($estate_name); ?></title>
    
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

    <style>
        .pwd-strength-bar {
            height: 4px;
            width: 100%;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 6px;
        }
        .pwd-strength-fill {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
        }
        .toggle-password-btn {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 0.95rem;
            padding: 4px;
        }
        .toggle-password-btn:hover {
            color: #f8fafc;
        }
    </style>
</head>
<body class="landing-page">
    <div class="auth-page" style="background-image: url('images/hero_estate.jpg');">
        <div class="auth-overlay"></div>
        
        <div class="auth-card" style="max-width: 460px;">
            <a href="login" class="auth-back-link">
                <i class="fa-solid fa-arrow-left"></i> Cancel &amp; Sign In
            </a>
            
            <div class="auth-header">
                <div class="d-flex justify-content-center mb-3">
                    <?php if ($estate_logo): ?>
                        <img src="<?php echo htmlspecialchars($estate_logo); ?>" alt="Logo" style="height: 48px; border-radius: 8px;">
                    <?php else: ?>
                        <div class="glass-icon-circle hero-icon-circle glass-icon-emerald" style="width: 54px; height: 54px; min-width: 54px; min-height: 54px; font-size: 1.45rem; margin: 0 auto;">
                            <i class="fa-solid fa-lock-open"></i>
                        </div>
                    <?php endif; ?>
                </div>
                
                <h2 class="auth-title">Create New Password</h2>
                <p class="auth-subtitle">Identity verified for <strong style="color: #60a5fa;"><?php echo htmlspecialchars($email); ?></strong>.<br>Enter your new secure password below.</p>
            </div>

            <?php if ($error): ?>
                <div class="auth-error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" id="resetPasswordForm">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">

                <!-- New Password -->
                <div class="auth-input-group">
                    <label for="password">New Password</label>
                    <div class="auth-input-wrapper position-relative" style="position: relative;">
                        <i class="fa-solid fa-lock"></i>
                        <input type="password" id="password" name="password" class="auth-input" placeholder="Min. 8 characters" required autofocus oninput="checkStrength(this.value)">
                        <button type="button" class="toggle-password-btn" onclick="toggleVisibility('password', this)" title="Show/Hide">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                    <div class="pwd-strength-bar">
                        <div id="strengthFill" class="pwd-strength-fill"></div>
                    </div>
                    <small id="strengthText" style="display: block; font-size: 0.72rem; color: #94a3b8; margin-top: 4px;">Minimum 8 characters</small>
                </div>

                <!-- Confirm Password -->
                <div class="auth-input-group" style="margin-top: 1.25rem;">
                    <label for="confirm_password">Confirm New Password</label>
                    <div class="auth-input-wrapper position-relative" style="position: relative;">
                        <i class="fa-solid fa-lock-check"></i>
                        <input type="password" id="confirm_password" name="confirm_password" class="auth-input" placeholder="Repeat new password" required oninput="checkMatch()">
                        <button type="button" class="toggle-password-btn" onclick="toggleVisibility('confirm_password', this)" title="Show/Hide">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                    <small id="matchText" style="display: block; font-size: 0.72rem; color: #94a3b8; margin-top: 4px;"></small>
                </div>

                <button type="submit" id="submitBtn" class="auth-btn auth-btn-admin" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); margin-top: 1.5rem;">
                    <span>Update Password</span>
                    <i class="fa-solid fa-check"></i>
                </button>
            </form>
        </div>
    </div>

    <script>
        function toggleVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fa-regular fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'fa-regular fa-eye';
            }
        }

        function checkStrength(val) {
            const fill = document.getElementById('strengthFill');
            const txt = document.getElementById('strengthText');
            let score = 0;

            if (val.length >= 8) score++;
            if (/[A-Z]/.test(val)) score++;
            if (/[0-9]/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;

            if (val.length === 0) {
                fill.style.width = '0%';
                txt.textContent = 'Minimum 8 characters';
                txt.style.color = '#94a3b8';
            } else if (val.length < 8) {
                fill.style.width = '25%';
                fill.style.background = '#ef4444';
                txt.textContent = 'Too short (min. 8 characters required)';
                txt.style.color = '#ef4444';
            } else if (score <= 2) {
                fill.style.width = '50%';
                fill.style.background = '#f59e0b';
                txt.textContent = 'Fair (try mixing letters, numbers & symbols)';
                txt.style.color = '#f59e0b';
            } else if (score === 3) {
                fill.style.width = '75%';
                fill.style.background = '#3b82f6';
                txt.textContent = 'Good password';
                txt.style.color = '#3b82f6';
            } else {
                fill.style.width = '100%';
                fill.style.background = '#10b981';
                txt.textContent = 'Strong password! ✓';
                txt.style.color = '#10b981';
            }
            checkMatch();
        }

        function checkMatch() {
            const p1 = document.getElementById('password').value;
            const p2 = document.getElementById('confirm_password').value;
            const txt = document.getElementById('matchText');

            if (!p2) {
                txt.textContent = '';
            } else if (p1 === p2) {
                txt.textContent = 'Passwords match ✓';
                txt.style.color = '#10b981';
            } else {
                txt.textContent = 'Passwords do not match';
                txt.style.color = '#ef4444';
            }
        }
    </script>
</body>
</html>
