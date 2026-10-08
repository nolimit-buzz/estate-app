<?php
// superadmin/impersonate.php - POST only. A platform admin enters an estate as its Central Admin (support / onboarding).
// The tenant session is minted from the estate's own admin account; the platform identity stays attached for auditing.
require_once __DIR__ . '/includes/super_auth.php';
sa_require_role(['superadmin', 'support']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: estates'); exit; }
$estateId = intval($_POST['estate_id'] ?? 0);
$estate = Tenancy::find($estateId);
if (!$estate || empty($estate['db_name']) || in_array($estate['status'], ['deleted', 'archived'], true)) redirectWithFlash('estates', null, 'That estate has no accessible environment.');
$reason = trim($_POST['reason'] ?? '');
if ($reason === '') redirectWithFlash("estate?id=$estateId", null, 'Please enter a reason for entering the estate (recorded in the audit log).');
$t = Tenancy::openTenant($estate);
$admin = $t->query("SELECT id, name, role FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id LIMIT 1")->fetch_assoc();
if (!$admin) redirectWithFlash("estate?id=$estateId", null, 'This estate has no active Central Admin yet (account not verified).');
$_SESSION['impersonated_estate_id'] = $estateId;
$_SESSION['user_id'] = intval($admin['id']);
$_SESSION['role'] = 'admin';
$_SESSION['estate_id'] = $estateId;
$_SESSION['name'] = $admin['name'];
$_SESSION['force_password_change'] = 0;
Audit::log('estate.impersonation_started', ['estate_id' => $estateId, 'module' => 'security', 'entity_type' => 'estate', 'entity_id' => $estateId, 'details' => "Entered as Central Admin {$admin['name']}. Reason: $reason"]);
Audit::alert('impersonation', "{$SA['email']} entered estate '{$estate['name']}' (reason: $reason)", 'low', ['estate_id' => $estateId, 'user_ref' => $SA['email']]);
header('Location: ../admin/index');
exit;
