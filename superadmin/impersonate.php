<?php
// superadmin/impersonate.php - Instant Estate Impersonation Engine
require_once '../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
    redirectWithFlash('../login', null, 'Super Administrator access required.');
}

$estate_id = intval($_GET['estate_id'] ?? 0);
if ($estate_id <= 0) {
    redirectWithFlash('index', null, 'Invalid estate specified for impersonation.');
}

// Verify target estate exists
$res = $conn->query("SELECT id, name, status FROM estates WHERE id = $estate_id LIMIT 1");
if (!$res || $res->num_rows === 0) {
    redirectWithFlash('index', null, 'Estate record not found.');
}

$estate = $res->fetch_assoc();

// Set impersonation context
$_SESSION['impersonated_estate_id'] = $estate_id;

logAudit($conn, "Super Admin Impersonation", "System", "Super Admin entered estate '{$estate['name']}' (ID: $estate_id) in operator mode.");

redirectWithFlash('../admin/index', "Switched into '{$estate['name']}' (ID: #$estate_id) in Super Admin Operator Mode. You can manage data or return to Super Admin anytime.");
