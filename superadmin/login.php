<?php
// superadmin/login.php - platform operator sign-in (separate from every estate's login)
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/RateLimit.php';

if (!empty($_SESSION['platform_user_id'])) { header('Location: index'); exit; }
$error = '';
if (($_GET['error'] ?? '') === 'disabled') $error = 'This account is disabled.';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0';
    $wait = max(RateLimit::lockedFor('sa_login', $email), RateLimit::lockedFor('sa_login_ip', $ip));
    if ($wait > 0) {
        $error = 'Too many failed attempts. Try again in ' . RateLimit::humanWait($wait) . '.';
    } else {
        $st = $platform->prepare("SELECT * FROM platform_users WHERE email = ? LIMIT 1");
        $st->bind_param('s', $email);
        $st->execute();
        $u = $st->get_result()->fetch_assoc();
        if ($u && $u['status'] === 'active' && password_verify($pw, $u['password'])) {
            RateLimit::clear('sa_login', $email);
            // never mix an estate session with a platform session
            foreach (['user_id', 'role', 'estate_id', 'name', 'zone_id', 'is_staff', 'role_id', 'pending_estate_id', 'impersonated_estate_id'] as $k) unset($_SESSION[$k]);
            $ref = SessionGuard::onLogin();
            $_SESSION['platform_user_id'] = intval($u['id']);
            $_SESSION['platform_user_name'] = $u['name'];
            $up = $platform->prepare("UPDATE platform_users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?");
            $up->bind_param('si', $ip, $u['id']);
            $up->execute();
            Audit::login('login_success', true, ['user_type' => 'platform', 'user_id' => $u['id'], 'email' => $email, 'name' => $u['name'], 'role' => 'superadmin', 'session_ref' => $ref]);
            if (!empty($u['last_login_ip']) && $u['last_login_ip'] !== $ip) {
                Audit::alert('new_ip_superadmin_login', "Platform admin $email signed in from a new IP ($ip), previously {$u['last_login_ip']}.", 'medium', ['user_ref' => $email]);
            }
            Audit::log('platform.login', ['estate_id' => null, 'module' => 'auth', 'details' => "$email signed in", 'actor' => ['platform', $u['id'], $u['name'], $u['role']]]);
            header('Location: index');
            exit;
        }
        $locked = RateLimit::hit('sa_login', $email, 5, 15);
        RateLimit::hit('sa_login_ip', $ip, 15, 15);
        Audit::login('login_failed', false, ['user_type' => 'platform', 'email' => $email, 'role' => 'superadmin', 'reason' => $u ? 'bad_password' : 'unknown_user']);
        if ($locked) Audit::alert('platform_login_lockout', "Repeated failed platform sign-ins for $email", 'high', ['user_ref' => $email]);
        $error = 'Invalid email or password.';
    }
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Platform sign-in</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/saas.css"></head>
<body><div class="auth-shell"><div></div><main class="auth-main"><div class="form-card narrow">
    <span class="eyebrow">Platform administration</span><h1>Sign in</h1>
    <?php if ($error): ?><div class="alert err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if (!empty($flash_error)): ?><div class="alert err"><?php echo htmlspecialchars($flash_error); ?></div><?php endif; ?>
    <form method="post">
        <div class="field"><label for="email">Email</label><input id="email" type="email" name="email" required autofocus></div>
        <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" required></div>
        <button class="btn btn-primary btn-block" type="submit">Sign in</button>
    </form>
</div></main></div></body></html>
