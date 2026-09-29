<?php
// verify_code.php - Verify OTP for Password Reset
require_once 'config.php';
require_once 'includes/Mailer.php';

// Auto-ensure tables exist
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

$email = trim($_GET['email'] ?? ($_SESSION['reset_email_pending'] ?? ''));
$token_param = trim($_GET['token'] ?? ($_SESSION['reset_token_pending'] ?? ''));

$error = '';
$success = '';

// Direct link verification (if user clicked direct link from email with token)
if (!empty($token_param) && !empty($email) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $esc_email = $conn->real_escape_string($email);
    $esc_token = $conn->real_escape_string($token_param);

    $tok_chk = $conn->query("SELECT id, token, expires_at, used FROM password_resets 
                             WHERE email = '$esc_email' AND token = '$esc_token' AND used = 0 
                             AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    if ($tok_chk && $tok_chk->num_rows > 0) {
        $_SESSION['verified_reset_token'] = $esc_token;
        $_SESSION['verified_reset_email'] = $email;
        header("Location: reset_password.php");
        exit;
    }
}

// Handle Form Submission for 6-Digit Code
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    // If entered via individual digit boxes or single input
    $code = trim($_POST['code'] ?? '');
    if (empty($code) && isset($_POST['digit_1'])) {
        $code = trim($_POST['digit_1'] . $_POST['digit_2'] . $_POST['digit_3'] . $_POST['digit_4'] . $_POST['digit_5'] . $_POST['digit_6']);
    }

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please provide the email address associated with your account.";
    } elseif (empty($code) || strlen($code) < 6) {
        $error = "Please enter the complete 6-digit verification code.";
    } else {
        $esc_email = $conn->real_escape_string($email);
        $esc_code = $conn->real_escape_string($code);

        // Check if code matches an active reset request
        $chk = $conn->query("SELECT id, token, expires_at, used FROM password_resets 
                             WHERE email = '$esc_email' AND code = '$esc_code' AND used = 0 
                             ORDER BY id DESC LIMIT 1");

        if ($chk && $chk->num_rows > 0) {
            $reset_row = $chk->fetch_assoc();
            
            // Check if expired
            if (strtotime($reset_row['expires_at']) < time()) {
                $error = "This verification code has expired (15-minute time limit exceeded). Please request a new code.";
            } else {
                // Code is valid and active!
                $_SESSION['verified_reset_token'] = $reset_row['token'];
                $_SESSION['verified_reset_email'] = $email;
                
                logAudit($conn, "Reset OTP Verified", "Auth", "Password reset code verified for $email");

                header("Location: reset_password.php");
                exit;
            }
        } else {
            $error = "Invalid verification code. Please check your email and try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Code - <?php echo htmlspecialchars($estate_name); ?></title>
    
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
        .otp-inputs-wrapper {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: 1.5rem 0;
        }
        .otp-digit-box {
            width: 48px;
            height: 56px;
            font-size: 1.6rem;
            font-weight: 700;
            text-align: center;
            border-radius: 10px;
            background: rgba(17, 24, 39, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #ffffff;
            font-family: monospace;
            transition: all 0.2s ease;
        }
        .otp-digit-box:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3);
            background: rgba(30, 41, 59, 0.95);
        }
        .timer-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.82rem;
            color: #f59e0b;
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.2);
            padding: 4px 12px;
            border-radius: 9999px;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body class="landing-page">
    <div class="auth-page" style="background-image: url('images/hero_estate.jpg');">
        <div class="auth-overlay"></div>
        
        <div class="auth-card" style="max-width: 460px;">
            <a href="forgot_password" class="auth-back-link">
                <i class="fa-solid fa-arrow-left"></i> Change Email
            </a>
            
            <div class="auth-header">
                <div class="d-flex justify-content-center mb-3">
                    <?php if ($estate_logo): ?>
                        <img src="<?php echo htmlspecialchars($estate_logo); ?>" alt="Logo" style="height: 48px; border-radius: 8px;">
                    <?php else: ?>
                        <div class="glass-icon-circle hero-icon-circle glass-icon-blue" style="width: 54px; height: 54px; min-width: 54px; min-height: 54px; font-size: 1.45rem; margin: 0 auto;">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                    <?php endif; ?>
                </div>
                
                <h2 class="auth-title">Verify Code</h2>
                <p class="auth-subtitle">
                    We sent a 6-digit security code to<br>
                    <strong style="color: #60a5fa; word-break: break-all;"><?php echo htmlspecialchars($email ?: 'your email address'); ?></strong>
                </p>

                <div class="timer-badge">
                    <i class="fa-solid fa-clock"></i>
                    <span>Code expires in <span id="countdownTimer">15:00</span></span>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="auth-error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" id="otpForm">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                <input type="hidden" name="code" id="fullOtpCode" value="">

                <div class="otp-inputs-wrapper">
                    <input type="text" maxlength="1" class="otp-digit-box" id="digit_1" name="digit_1" inputmode="numeric" pattern="[0-9]*" autofocus autocomplete="one-time-code">
                    <input type="text" maxlength="1" class="otp-digit-box" id="digit_2" name="digit_2" inputmode="numeric" pattern="[0-9]*">
                    <input type="text" maxlength="1" class="otp-digit-box" id="digit_3" name="digit_3" inputmode="numeric" pattern="[0-9]*">
                    <input type="text" maxlength="1" class="otp-digit-box" id="digit_4" name="digit_4" inputmode="numeric" pattern="[0-9]*">
                    <input type="text" maxlength="1" class="otp-digit-box" id="digit_5" name="digit_5" inputmode="numeric" pattern="[0-9]*">
                    <input type="text" maxlength="1" class="otp-digit-box" id="digit_6" name="digit_6" inputmode="numeric" pattern="[0-9]*">
                </div>

                <button type="submit" class="auth-btn auth-btn-admin" style="background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);">
                    <span>Verify Code &amp; Continue</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>

            <div style="margin-top: 1.75rem; text-align: center; border-top: 1px solid rgba(255, 255, 255, 0.1); padding-top: 1.25rem;">
                <span style="font-size: 0.85rem; color: var(--brand-text-muted);">Didn't receive the code?</span>
                <a href="forgot_password?email=<?php echo urlencode($email); ?>" style="color: #60a5fa; font-weight: 600; text-decoration: none; margin-left: 6px; font-size: 0.85rem;">
                    Resend Code
                </a>
            </div>
        </div>
    </div>

    <script>
        // Smooth OTP Auto-Focus & Paste Handling
        const inputs = Array.from(document.querySelectorAll('.otp-digit-box'));
        const fullOtpInput = document.getElementById('fullOtpCode');
        const otpForm = document.getElementById('otpForm');

        function updateFullCode() {
            fullOtpInput.value = inputs.map(i => i.value).join('');
        }

        inputs.forEach((input, index) => {
            input.addEventListener('input', (e) => {
                const val = e.target.value.replace(/[^0-9]/g, '');
                e.target.value = val ? val.slice(-1) : '';
                updateFullCode();

                if (val && index < inputs.length - 1) {
                    inputs[index + 1].focus();
                }
                
                // Auto-submit if all 6 digits are filled
                if (fullOtpInput.value.length === 6) {
                    otpForm.submit();
                }
            });

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !e.target.value && index > 0) {
                    inputs[index - 1].focus();
                }
            });

            input.addEventListener('paste', (e) => {
                e.preventDefault();
                const pasteData = (e.clipboardData || window.clipboardData).getData('text').trim().replace(/[^0-9]/g, '');
                if (pasteData) {
                    const digits = pasteData.slice(0, 6).split('');
                    digits.forEach((d, i) => {
                        if (inputs[i]) inputs[i].value = d;
                    });
                    updateFullCode();
                    const nextFocus = Math.min(digits.length, inputs.length - 1);
                    inputs[nextFocus].focus();
                    if (digits.length === 6) {
                        otpForm.submit();
                    }
                }
            });
        });

        // 15-Minute Countdown Timer
        let durationSeconds = 15 * 60;
        const timerDisplay = document.getElementById('countdownTimer');
        const timerInterval = setInterval(() => {
            durationSeconds--;
            if (durationSeconds <= 0) {
                clearInterval(timerInterval);
                timerDisplay.textContent = 'Expired';
                timerDisplay.style.color = '#ef4444';
            } else {
                const minutes = Math.floor(durationSeconds / 60);
                const seconds = durationSeconds % 60;
                timerDisplay.textContent = `${minutes}:${seconds < 10 ? '0' : ''}${seconds}`;
            }
        }, 1000);
    </script>
</body>
</html>
