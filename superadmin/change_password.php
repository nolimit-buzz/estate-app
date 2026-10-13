<?php
// superadmin/change_password.php - mandatory on first sign-in, available any time.
require_once __DIR__ . '/includes/super_auth.php';
$error = ''; $ok = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $cur = (string)($_POST['current'] ?? ''); $new = (string)($_POST['password'] ?? '');
    $row = $platform->query("SELECT password FROM platform_users WHERE id = " . intval($SA['id']))->fetch_assoc();
    if (!password_verify($cur, $row['password'])) $error = 'Current password is incorrect.';
    elseif ($new !== (string)($_POST['password2'] ?? '')) $error = 'The new passwords do not match.';
    elseif ($e = EstateService::validatePassword($new)) $error = $e;
    elseif (password_verify($new, $row['password'])) $error = 'Choose a password different from the current one.';
    else {
        $h = password_hash($new, PASSWORD_DEFAULT);
        $st = $platform->prepare("UPDATE platform_users SET password = ?, force_password_change = 0 WHERE id = ?");
        $st->bind_param('si', $h, $SA['id']);
        $st->execute();
        Audit::log('platform.password_changed', ['estate_id' => null, 'module' => 'auth', 'details' => $SA['email'] . ' changed their password']);
        redirectWithFlash('index', 'Password updated.');
    }
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Change password</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/saas.css">    <link rel="stylesheet" href="../css/dashboard-theme-public.css?v=<?php echo defined('ASSET_VER') ? ASSET_VER : time(); ?>">
</head>
<body><div class="auth-shell"><div></div><main class="auth-main"><div class="form-card narrow">
    <h1>Change password</h1>
    <?php if ($SA['force_password_change']): ?><div class="alert info">For security you must set a new password before continuing.</div><?php endif; ?>
    <?php if ($error): ?><div class="alert err"><?php echo e($error); ?></div><?php endif; ?>
    <form method="post">
        <div class="field"><label>Current password</label><input type="password" name="current" required></div>
        <div class="field"><label>New password</label><input type="password" name="password" required autocomplete="new-password"></div>
        <div class="field"><label>Confirm new password</label><input type="password" name="password2" required autocomplete="new-password"></div>
        <button class="btn btn-primary btn-block" type="submit">Update password</button>
        <p style="text-align:center;margin-top:14px"><a href="../logout">Sign out</a></p>
    </form>
</div></main></div></body></html>
