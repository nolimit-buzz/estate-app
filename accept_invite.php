<?php
// accept_invite.php - invitees activate their account here. Tokens are single use and expire.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_layout.php';

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['token'] ?? $_POST['token'] ?? ''));
$ctx = Invitations::lookup($token);
$errors = []; $declined = false;

if ($ctx && $ctx['inv']['status'] === 'pending' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0';
    if (RateLimit::lockedFor('invite', $ip) > 0) {
        $errors[] = 'Too many attempts. Please wait a few minutes.';
    } elseif (($_POST['action'] ?? '') === 'decline') {
        Invitations::decline($token);
        $ctx = Invitations::lookup($token); $declined = true;
    } else {
        $pw = (string)($_POST['password'] ?? '');
        if ($pw !== (string)($_POST['password2'] ?? '')) {
            $errors[] = 'The two passwords do not match.';
        } else {
            try {
                $res = Invitations::accept($token, $pw);
                $_SESSION['flash_success'] = 'Welcome aboard! Your account is active - sign in with your email and new password.';
                header('Location: ' . Notifier::estateUrl($res['estate'], 'login'));
                exit;
            } catch (InvalidArgumentException $e) { $errors[] = $e->getMessage(); }
            catch (Throwable $e) { RateLimit::hit('invite', $ip, 10, 15); $errors[] = $e->getMessage(); $ctx = Invitations::lookup($token); }
        }
    }
}
$state = $ctx ? $ctx['inv']['status'] : 'invalid';
$msgs = ['invalid' => 'This invitation link is not valid.', 'expired' => 'This invitation has expired. Ask your administrator to send a new one.',
    'accepted' => 'This invitation has already been used. You can sign in with your account.', 'declined' => 'You declined this invitation.', 'cancelled' => 'This invitation was cancelled by the administrator.'];
public_head('Accept invitation - ' . pl_company());
public_nav('simple');
?>
<div class="auth-shell"><div></div>
<main class="auth-main">
<div class="form-card narrow">
<?php if ($state !== 'pending'): ?>
    <h1>Invitation</h1>
    <div class="alert <?php echo $state === 'accepted' ? 'ok' : 'err'; ?>"><?php echo pl_e($msgs[$state] ?? $msgs['invalid']); ?></div>
    <a class="btn btn-ghost btn-block" href="<?php echo $ctx && $ctx['estate'] ? pl_e(Notifier::estateUrl($ctx['estate'], 'login')) : 'login'; ?>">Go to sign in</a>
<?php else: $inv = $ctx['inv']; $est = $ctx['estate']; ?>
    <h1>Join <?php echo pl_e($est['name']); ?></h1>
    <p class="muted"><?php echo pl_e($inv['invited_by_name'] ?: 'The estate administrator'); ?> invited <strong><?php echo pl_e($inv['email']); ?></strong> as <strong><?php echo pl_e(Invitations::roleLabel($inv['role'])); ?></strong>. This link works once and expires <?php echo pl_e(date('j M Y, g:ia', strtotime($inv['expires_at']))); ?>.</p>
    <?php foreach ($errors as $er): ?><div class="alert err"><?php echo pl_e($er); ?></div><?php endforeach; ?>
    <form method="post"><input type="hidden" name="token" value="<?php echo pl_e($token); ?>">
        <div class="field"><label>Email</label><input value="<?php echo pl_e($inv['email']); ?>" disabled></div>
        <div class="field"><label for="password">Create a password</label><input id="password" type="password" name="password" required autocomplete="new-password"><div class="hint">At least <?php echo max(8, PlatformSettings::int('min_password_length', 10)); ?> characters with letters and numbers.</div></div>
        <div class="field"><label for="password2">Confirm password</label><input id="password2" type="password" name="password2" required autocomplete="new-password"></div>
        <button class="btn btn-primary btn-block" type="submit" name="action" value="accept">Accept invitation</button>
    </form>
    <form method="post" style="margin-top:12px;text-align:center" onsubmit="return confirm('Decline this invitation?')"><input type="hidden" name="token" value="<?php echo pl_e($token); ?>"><button class="btn btn-ghost btn-sm" name="action" value="decline" type="submit">Decline</button></form>
<?php endif; ?>
</div>
</main></div>
</body></html>
