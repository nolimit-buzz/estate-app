<?php
// superadmin/ajax_toggle_module.php - Real-Time Module Feature Flag Toggler
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
$module_key = trim($_POST['module_key'] ?? '');
$is_enabled = isset($_POST['is_enabled']) && ($_POST['is_enabled'] === '1' || $_POST['is_enabled'] === 1 || $_POST['is_enabled'] === 'true') ? 1 : 0;
$disabled_reason = trim($_POST['disabled_reason'] ?? '');

$validModules = getAllPlatformModules();
if (!array_key_exists($module_key, $validModules)) {
    echo json_encode(['success' => false, 'error' => 'Unknown module key.']);
    exit;
}

if ($estate_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid estate ID.']);
    exit;
}

// Upsert into estate_modules
$mKeyEsc = $conn->real_escape_string($module_key);
$reasonEsc = $conn->real_escape_string($disabled_reason);

$check = $conn->query("SELECT id FROM estate_modules WHERE estate_id = $estate_id AND module_key = '$mKeyEsc' LIMIT 1");
if ($check && $check->num_rows > 0) {
    $sql = "UPDATE estate_modules 
            SET is_enabled = $is_enabled, disabled_reason = '$reasonEsc' 
            WHERE estate_id = $estate_id AND module_key = '$mKeyEsc'";
} else {
    $sql = "INSERT INTO estate_modules (estate_id, module_key, is_enabled, disabled_reason) 
            VALUES ($estate_id, '$mKeyEsc', $is_enabled, '$reasonEsc')";
}

if ($conn->query($sql)) {
    $modName = $validModules[$module_key]['name'];
    $stateStr = $is_enabled ? 'ENABLED' : 'DISABLED';
    logAudit($conn, "Module Toggle", "SaaS Feature Flags", "Super Admin set module '{$modName}' to {$stateStr} for estate ID #{$estate_id}.");

    echo json_encode([
        'success' => true,
        'estate_id' => $estate_id,
        'module_key' => $module_key,
        'module_name' => $modName,
        'is_enabled' => $is_enabled,
        'message' => "Module '{$modName}' successfully " . ($is_enabled ? 'activated' : 'deactivated') . " for this estate."
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
}
