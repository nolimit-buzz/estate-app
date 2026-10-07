<?php
// superadmin/exit_impersonation.php - Return from Estate to Super Admin
require_once '../config.php';

if (isset($_SESSION['impersonated_estate_id'])) {
    $prev_id = intval($_SESSION['impersonated_estate_id']);
    unset($_SESSION['impersonated_estate_id']);
    logAudit($conn, "Exit Impersonation", "System", "Super Admin exited estate ID $prev_id back to Super Admin console.");
}

redirectWithFlash('index', "Exited estate mode. Returned to Super Admin Command Center.");
