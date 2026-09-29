<?php
// change_password.php - Forced Password Change on First Login
require_once 'config.php';
require_once 'includes/auth_guard.php';
require_once 'includes/Mailer.php';

// Enforce login
if (!isset($_SESSION['user_id'])) {
    header("Location: login?error=unauthenticated");
    exit;
}

$uid = intval($_SESSION['user_id']);
$estate_id = get_estate_id();

// Fetch Estate Details for branding
$sys_res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE estate_id = $estate_id");
$sys = [];
if ($sys_res) {
    while ($row = $sys_res->fetch_assoc()) {
        $sys[$row['setting_key']] = $row['setting_value'];
    }
}
$estate_name = $sys['estate_name'] ?? 'Estate Portal';
$estate_logo = $sys['estate_logo'] ?? '';

// Fetch user details from database
$u_res = $conn->query("SELECT id, name, email, password, role, force_password_change FROM users WHERE id = $uid LIMIT 1");
if (!$u_res || $u_res->num_rows === 0) {
    header("Location: logout");
    exit;
}
$user = $u_res->fetch_assoc();

// If the user already changed their password and has force_password_change == 0,
// and they just visited this page voluntarily, determine their destination
$is_forced = intval($user['force_password_change'] ?? 0) === 1;

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = "Please fill in all password fields.";
    } elseif (!password_verify($current_password, $user['password'])) {
        $error = "The current/temporary password you entered is incorrect.";
    } elseif (strlen($new_password) < 8) {
        $error = "Your new password must be at least 8 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New password and confirmation do not match.";
    } elseif ($current_password === $new_password) {
        $error = "Your new password must be different from your temporary password.";
    } else {
        // Hash new password
        $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
        
        $upd = $conn->query("UPDATE users SET password = '$new_hash', force_password_change = 0, password_changed_at = NOW() WHERE id = $uid");
        if ($upd) {
            $_SESSION['force_password_change'] = 0;

            // Send Security Confirmation Email
            EstateMailer::sendPasswordChangedConfirmation($conn, $user['email'], $user['name'], $estate_id);

            // Audit log
            logAudit($conn, "Password Changed", "Auth", "User changed password (forced reset completed)");

            // Determine redirect URL
            $role = $_SESSION['role'] ?? 'resident';
            if ($role === 'superadmin') {
                $dest = 'superadmin/index';
            } elseif (in_array($role, ['admin', 'manager'])) {
                $dest = 'admin/index';
            } elseif ($role === 'zone_admin') {
                $dest = 'zone/index';
            } elseif ($role === 'resident') {
                $dest = 'resident/index';
            } else {
                $dest = 'staff/index';
            }

            redirectWithFlash($dest, "Your new password has been established successfully! Welcome to your estate portal.");
            exit;
        } else {
            $error = "Database error while updating password. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - <?php echo htmlspecialchars($estate_name); ?></title>
    
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
        .security-notice-card {
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 1.5rem;
            text-align: left;
        }
        .security-notice-card strong {
            color: #fbbf24;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.88rem;
        }
        .security-notice-card p {
            color: #fde68a;
            font-size: 0.78rem;
            margin: 4px 0 0 0;
            line-height: 1.45;
        }
    </style>
</head>
<body class="landing-page">
    <div class="auth-page" style="background-image: url('images/hero_estate.jpg');">
        <div class="auth-overlay"></div>
        
        <div class="auth-card" style="max-width: 480px;">
            <div class="auth-header">
                <div class="d-flex justify-content-center mb-3">
                    <?php if ($estate_logo): ?>
                        <img src="<?php echo htmlspecialchars($estate_logo); ?>" alt="Logo" style="height: 48px; border-radius: 8px;">
                    <?php else: ?>
                        <div class="glass-icon-circle hero-icon-circle glass-icon-amber" style="width: 54px; height: 54px; min-width: 54px; min-height: 54px; font-size: 1.45rem; margin: 0 auto;">
                            <i class="fa-solid fa-shield-keyhole"></i>
                        </div>
                    <?php endif; ?>
                </div>
                
                <h2 class="auth-title">Mandatory Password Update</h2>
                <p class="auth-subtitle">Signed in as <strong style="color: #60a5fa;"><?php echo htmlspecialchars($user['email']); ?></strong></p>
            </div>

            <?php if ($is_forced): ?>
                <div class="security-notice-card">
                    <strong><i class="fa-solid fa-triangle-exclamation"></i> Security Requirement</strong>
                    <p>Your password was recently reset by Central Administration. To protect your account, you must create a new private password before accessing your dashboard.</p>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="auth-error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST">
                <!-- Current / Temporary Password -->
                <div class="auth-input-group">
                    <label for="current_password">Current / Temporary Password</label>
                    <div class="auth-input-wrapper position-relative" style="position: relative;">
                        <i class="fa-solid fa-key"></i>
                        <input type="password" id="current_password" name="current_password" class="auth-input" placeholder="Enter temporary password" required autofocus>
                        <button type="button" class="toggle-password-btn" onclick="toggleVisibility('current_password', this)" title="Show/Hide">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                </div>

                <!-- New Password -->
                <div class="auth-input-group" style="margin-top: 1.25rem;">
                    <label for="new_password">New Secure Password</label>
                    <div class="auth-input-wrapper position-relative" style="position: relative;">
                        <i class="fa-solid fa-lock"></i>
                        <input type="password" id="new_password" name="new_password" class="auth-input" placeholder="Min. 8 characters" required oninput="checkStrength(this.value)">
                        <button type="button" class="toggle-password-btn" onclick="toggleVisibility('new_password', this)" title="Show/Hide">
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

                <button type="submit" class="auth-btn auth-btn-admin" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); margin-top: 1.5rem;">
                    <span>Save Password &amp; Enter Dashboard</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>

            <div style="margin-top: 1.5rem; text-align: center; border-top: 1px solid rgba(255, 255, 255, 0.1); padding-top: 1rem;">
                <a href="logout" style="color: #ef4444; font-size: 0.82rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out and try later
                </a>
            </div>
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
                txt.textContent = 'Fair password';
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
            const p1 = document.getElementById('new_password').value;
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
