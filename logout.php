<?php
// logout.php - records the logout, then fully destroys the session
require_once __DIR__ . '/config.php';
if (!empty($_SESSION['user_id']) || !empty($_SESSION['platform_user_id'])) {
    Audit::logout();
    if (!empty($_SESSION['user_id']) && Tenancy::hasTenant()) logAudit($conn, 'User Logout', 'Auth', 'Signed out.');
}
$wasPlatform = !empty($_SESSION['platform_user_id']);
SessionGuard::destroy();
header('Location: ' . ($wasPlatform ? 'superadmin/login' : 'index'));
exit;