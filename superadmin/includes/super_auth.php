<?php
// superadmin/includes/super_auth.php - strict platform (control-plane) authentication guard.
// Super admins live in the platform_users table and NEVER inside an estate database.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/ui.php';

if (empty($_SESSION['platform_user_id'])) {
    header('Location: login');
    exit;
}

// Re-validate against the DB on every request (disabled accounts are cut off immediately)
$__st = $platform->prepare("SELECT id, name, email, role, status, force_password_change FROM platform_users WHERE id = ? LIMIT 1");
$__id = intval($_SESSION['platform_user_id']);
$__st->bind_param('i', $__id);
$__st->execute();
$SA = $__st->get_result()->fetch_assoc();
if (!$SA || $SA['status'] !== 'active') {
    SessionGuard::destroy();
    header('Location: login?error=disabled');
    exit;
}
$_SESSION['platform_user_name'] = $SA['name'];
$_SESSION['platform_user_email'] = $SA['email'];
$_SESSION['platform_role'] = $SA['role'];
// Compatibility for shared templates
$_SESSION['name'] = $_SESSION['name'] ?? $SA['name'];

$__self = basename($_SERVER['PHP_SELF']);
if ($SA['force_password_change'] && !in_array($__self, ['change_password.php', '../logout.php'], true)) {
    header('Location: change_password');
    exit;
}

/** Role gate for pages that only some platform roles may use. */
function sa_require_role(array $roles) {
    global $SA;
    if (!in_array($SA['role'], $roles, true)) {
        http_response_code(403);
        echo '<!doctype html><title>Forbidden</title><body style="font-family:system-ui;padding:40px"><h2>Access denied</h2><p>Your platform role does not allow this action.</p><a href="index">Back</a>';
        exit;
    }
}
