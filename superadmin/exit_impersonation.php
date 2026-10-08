<?php
require_once __DIR__ . '/includes/super_auth.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_SESSION['impersonated_estate_id'])) {
    $eid = intval($_SESSION['impersonated_estate_id']);
    Audit::log('estate.impersonation_ended', ['estate_id' => $eid, 'module' => 'security', 'details' => 'Left estate']);
    foreach (['impersonated_estate_id', 'user_id', 'role', 'estate_id', 'zone_id', 'zone_name', 'zone_code', 'is_staff', 'role_id', 'force_password_change'] as $k) unset($_SESSION[$k]);
    $_SESSION['name'] = $SA['name'];
    header("Location: estate?id=$eid");
    exit;
}
header('Location: index');
