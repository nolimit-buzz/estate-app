<?php
// superadmin/ajax_toggle_estate_status.php - Update Estate Lifecycle Status
require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'superadmin') {
    echo json_encode(['success' => false, 'error' => 'Super Administrator access required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST method required.']);
    exit;
}

$estate_id = intval($_POST['estate_id'] ?? 0);
$new_status = trim($_POST['status'] ?? '');
$allowed = ['active', 'suspended', 'trial', 'maintenance', 'expired'];

if (!in_array($new_status, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status value.']);
    exit;
}

if ($estate_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid estate ID.']);
    exit;
}

$stEsc = $conn->real_escape_string($new_status);
if ($conn->query("UPDATE estates SET status = '$stEsc' WHERE id = $estate_id")) {
    logAudit($conn, "Estate Status Changed", "SaaS Life-Cycle", "Super Admin set estate #$estate_id status to '$new_status'.");
    echo json_encode([
        'success' => true,
        'estate_id' => $estate_id,
        'status' => $new_status,
        'message' => "Estate status updated to " . ucfirst($new_status) . "."
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
}
