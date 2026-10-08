<?php
// verify_email.php - step 2: confirm the Central Admin's email with a one-time code or the emailed link.
// An account is NOT active, and cannot sign in, until this succeeds.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_layout.php';

$errors = []; $notice = ''; $stage = 'otp'; $done = null;
$email = strtolower(trim($_POST['email'] ?? $_SESSION['verify_email'] ?? ''));
$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['token'] ?? $_POST['token'] ?? ''));

try {
    $action = $_POST['action'] ?? '';
    if ($token && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $res = EstateService::confirmVerification('', '', $token);
        if ($res['ok']) {
            if ($res['needs_password']) { $_SESSION['verified_vid'] = intval($res['verification_id']); $stage = 'password'; }
            else { $done = EstateService::completeRegistration($res['verification_id']); $stage = 'done'; }
        } else { $errors[] = $res['error']; }
    } elseif ($action === 'verify') {
        $res = EstateService::confirmVerification($email, $_POST['otp'] ?? '');
        if ($res['ok']) {
            if ($res['needs_password']) { $_SESSION['verified_vid'] = intval($res['verification_id']); $stage = 'password'; }
            else { $done = EstateService::completeRegistration($res['verification_id']); $stage = 'done'; }
        } else { $errors[] = $res['error']; }
    } elseif ($action === 'set_password') {
        $vid = intval($_SESSION['verified_vid'] ?? 0);
        $pw = (string)($_POST['password'] ?? '');
        if (!$vid) { $errors[] = 'Your verification session expired. Please verify again.'; }
        elseif ($pw !== (string)($_POST['password2'] ?? '')) { $errors[] = 'The two passwords do not match.'; $stage = 'password'; }
        else { $done = EstateService::completeRegistration($vid, $pw); unset($_SESSION['verified_vid']); $stage = 'done'; }
    } elseif ($action === 'resend') {
        EstateService::resendVerification($email);
        $notice = 'If that address is awaiting verification, a new code has been sent.';
    }
} catch (InvalidArgumentException $e) { $errors[] = $e->getMessage(); if ($action === 'set_password') $stage = 'password'; }
catch (Throwable $e) { error_log('verify_email: ' . $e->getMessage()); $errors[] = 'Something went wrong. Please try again.'; }

if ($stage === 'done') {
    unset($_SESSION['verify_email']);
    $est = $done['estate'];
    $_SESSION['flash_success'] = 'Email verified. Sign in to continue setting up ' . $est['name'] . '.';
    header('Location: ' . Notifier::estateUrl($est, 'login'));
    exit;
}
$devHint = env_get('APP_ENV', 'production') === 'local';
public_head('Verify your email - ' . pl_company());
public_nav('simple');
?>
<div class="auth-shell"><div></div>
<main class="auth-main">
<div class="form-card narrow">
    <span class="eyebrow">Step 2 of 3</span>
    <?php foreach ($errors as $er): ?><div class="alert err"><?php echo pl_e($er); ?></div><?php endforeach; ?>
    <?php if ($notice): ?><div class="alert ok"><?php echo pl_e($notice); ?></div><?php endif; ?>
<?php if ($stage === 'password'): ?>
    <h1>Create your password</h1>
    <p class="muted">Email confirmed. Choose a password to finish activating your Central Admin account.</p>
    <form method="post"><input type="hidden" name="action" value="set_password">
        <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="new-password"><div class="hint">At least <?php echo max(8, PlatformSettings::int('min_password_length', 10)); ?> characters with letters and numbers.</div></div>
        <div class="field"><label for="password2">Confirm password</label><input id="password2" type="password" name="password2" required autocomplete="new-password"></div>
        <button class="btn btn-primary btn-block" type="submit">Activate account</button>
    </form>
<?php else: ?>
    <h1>Check your email</h1>
    <p class="muted">We sent a 6-digit code to <strong><?php echo pl_e($email ?: 'your email address'); ?></strong>. It expires in <?php echo PlatformSettings::int('otp_expiry_minutes', 10); ?> minutes.</p>
    <?php if ($devHint): ?><div class="alert info">Local mode: emails are written to <code>storage/logs/mail.log</code>.</div><?php endif; ?>
    <form method="post"><input type="hidden" name="action" value="verify">
        <?php if (!$email): ?><div class="field"><label for="email">Email</label><input id="email" type="email" name="email" required></div><?php else: ?><input type="hidden" name="email" value="<?php echo pl_e($email); ?>"><?php endif; ?>
        <div class="field"><label for="otp">Verification code</label><input id="otp" class="otp" name="otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required autofocus></div>
        <button class="btn btn-primary btn-block" type="submit">Verify email</button>
    </form>
    <form method="post" style="margin-top:14px;text-align:center"><input type="hidden" name="action" value="resend"><input type="hidden" name="email" value="<?php echo pl_e($email); ?>">
        <button class="btn btn-ghost btn-sm" type="submit">Resend code</button></form>
<?php endif; ?>
</div>
</main></div>
</body></html>
