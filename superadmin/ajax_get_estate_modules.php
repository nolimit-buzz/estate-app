<?php
// superadmin/ajax_get_estate_modules.php - Fetch module status dictionary for an estate
require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'superadmin') {
    echo json_encode(['success' => false, 'error' => 'Super Administrator access required.']);
    exit;
}

$estate_id = intval($_GET['estate_id'] ?? 0);
if ($estate_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid estate ID.']);
    exit;
}

$e_res = $conn->query("SELECT id, name, plan, status FROM estates WHERE id = $estate_id LIMIT 1");
if (!$e_res || $e_res->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Estate not found.']);
    exit;
}

$estate = $e_res->fetch_assoc();
$platformModules = getAllPlatformModules();

// Query active flags in estate_modules
$dbFlags = [];
$res = $conn->query("SELECT module_key, is_enabled, disabled_reason FROM estate_modules WHERE estate_id = $estate_id");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $dbFlags[$r['module_key']] = [
            'is_enabled' => intval($r['is_enabled']) === 1,
            'reason' => $r['disabled_reason'] ?? ''
        ];
    }
}

$resultList = [];
foreach ($platformModules as $key => $meta) {
    $isEnabled = isset($dbFlags[$key]) ? $dbFlags[$key]['is_enabled'] : true;
    $reason = isset($dbFlags[$key]) ? $dbFlags[$key]['reason'] : '';

    $resultList[] = [
        'key' => $key,
        'name' => $meta['name'],
        'desc' => $meta['desc'],
        'icon' => $meta['icon'],
        'category' => $meta['category'],
        'is_enabled' => $isEnabled,
        'disabled_reason' => $reason
    ];
}

echo json_encode([
    'success' => true,
    'estate' => $estate,
    'modules' => $resultList
]);
