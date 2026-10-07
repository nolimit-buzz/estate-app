<?php
// superadmin/includes/super_auth.php - Strict Super Admin Authentication Guard
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config.php';

// 1. Verify User is Logged In
if (!isset($_SESSION['user_id'])) {
    redirectWithFlash('../login', null, 'Please sign in to access the Super Admin console.');
}

// 2. Real-time Role Verification against Database
if (isset($conn)) {
    $uid = intval($_SESSION['user_id']);
    $roleChk = $conn->query("SELECT role, name, email FROM users WHERE id = $uid LIMIT 1");
    if ($roleChk && $rRow = $roleChk->fetch_assoc()) {
        $_SESSION['role'] = $rRow['role'];
        $_SESSION['name'] = $rRow['name'];
        $_SESSION['email'] = $rRow['email'];
    }
}

// 3. Allow Super Administrator or Root Developer (User #1)
$isSuperAdmin = (($_SESSION['role'] ?? '') === 'superadmin' || intval($_SESSION['user_id'] ?? 0) === 1);

if (!$isSuperAdmin) {
    redirectWithFlash('../admin/index', null, 'Access Denied: Super Administrator privileges required.');
}
