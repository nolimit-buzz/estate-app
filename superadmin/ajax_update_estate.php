<?php
// superadmin/ajax_update_estate.php - Instant AJAX Estate Configuration Updates
header('Content-Type: application/json');
require_once __DIR__ . '/includes/super_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$estate_id = intval($_POST['estate_id'] ?? 0);
if ($estate_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid estate ID.']);
    exit;
}

// Fetch current estate
$check = $conn->query("SELECT * FROM estates WHERE id = $estate_id LIMIT 1");
if (!$check || $check->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Estate not found.']);
    exit;
}
$currentEstate = $check->fetch_assoc();

$name = trim($conn->real_escape_string($_POST['name'] ?? $currentEstate['name']));
$domain_prefix = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['domain_prefix'] ?? $currentEstate['domain_prefix'])));
$status = in_array($_POST['status'] ?? '', ['active', 'suspended', 'trial', 'maintenance', 'expired']) ? $_POST['status'] : $currentEstate['status'];
$plan = in_array($_POST['plan'] ?? '', ['starter', 'growth', 'enterprise']) ? $_POST['plan'] : $currentEstate['plan'];
$billing_cycle = in_array($_POST['billing_cycle'] ?? '', ['monthly', 'annual']) ? $_POST['billing_cycle'] : ($currentEstate['billing_cycle'] ?? 'monthly');
$max_residents = intval($_POST['max_residents'] ?? $currentEstate['max_residents']);
$max_guards = intval($_POST['max_guards'] ?? $currentEstate['max_guards']);
$contact_email = trim($conn->real_escape_string($_POST['contact_email'] ?? $currentEstate['contact_email']));
$contact_phone = trim($conn->real_escape_string($_POST['contact_phone'] ?? $currentEstate['contact_phone']));
$custom_domain = trim($conn->real_escape_string($_POST['custom_domain'] ?? ($currentEstate['custom_domain'] ?? '')));

// Check domain prefix collision
if ($domain_prefix !== $currentEstate['domain_prefix']) {
    $colChk = $conn->query("SELECT id FROM estates WHERE domain_prefix = '$domain_prefix' AND id != $estate_id");
    if ($colChk && $colChk->num_rows > 0) {
        echo json_encode(['success' => false, 'error' => "Subdomain prefix '$domain_prefix' is already in use by another estate."]);
        exit;
    }
}

$updateSql = "UPDATE estates SET 
    name = '$name',
    domain_prefix = '$domain_prefix',
    status = '$status',
    plan = '$plan',
    billing_cycle = '$billing_cycle',
    max_residents = $max_residents,
    max_guards = $max_guards,
    contact_email = '$contact_email',
    contact_phone = '$contact_phone',
    custom_domain = '$custom_domain'
WHERE id = $estate_id";

if ($conn->query($updateSql)) {
    logAudit($conn, "Estate Updated", "SaaS Management", "Super Admin updated configuration for estate '$name' (ID: $estate_id, Plan: $plan, Status: $status).");
    echo json_encode([
        'success' => true,
        'message' => "Estate '$name' updated successfully.",
        'estate' => [
            'id' => $estate_id,
            'name' => $name,
            'domain_prefix' => $domain_prefix,
            'status' => $status,
            'plan' => $plan,
            'max_residents' => $max_residents,
            'max_guards' => $max_guards
        ]
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database update failed: ' . $conn->error]);
}
