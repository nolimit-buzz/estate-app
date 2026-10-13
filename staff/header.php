<?php
// staff/header.php
require_once __DIR__ . '/../config.php'; // boots hardened session + tenant
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth_guard.php';

requireLogin();
if (!isStaffRole() && !isAdminRole()) {
    header("Location: ../index");
    exit;
}

$user_name = $_SESSION['name'] ?? 'Staff Member';
$user_role = !empty($_SESSION['staff_role_title']) ? $_SESSION['staff_role_title'] : ucfirst($_SESSION['role'] ?? 'staff');

// Fetch Estate Branding Details
$branding = get_estate_branding($conn);
$estate_name = $branding['estate_name'] ?? 'Main Estate';
$estate_logo_url = $branding['estate_logo_url'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Portal - Estate Management</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=<?php echo ASSET_VER; ?>">
    <link rel="stylesheet" href="../css/estate_notifications.css?v=<?php echo ASSET_VER; ?>">
    <link rel="stylesheet" href="../css/estate_glassmorphism.css?v=<?php echo ASSET_VER; ?>">
    <link rel="stylesheet" href="../css/mobile_app.css?v=<?php echo ASSET_VER; ?>">
    <link rel="stylesheet" href="../css/hotline.css?v=<?php echo ASSET_VER; ?>">
    <link rel="stylesheet" href="../css/dashboard-theme.css?v=<?php echo ASSET_VER; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Global In-House Notification & Modal Alert Engine -->
    <script src="../js/estate_notifications.js?v=<?php echo ASSET_VER; ?>"></script>
    <script src="../js/theme.js"></script>
    <script src="../js/searchable_select.js?v=<?php echo ASSET_VER; ?>" defer></script>
    <script src="../js/mobile_app.js?v=<?php echo ASSET_VER; ?>" defer></script>
    <!-- Universal Anti-Duplicate & Single-Click Submission Engine -->
    <script src="../js/anti_duplicate.js?v=<?php echo ASSET_VER; ?>"></script>
    <!-- Real-time Emergency & Panic Alarm Engine -->
    <script>window.ESTATE_IS_STAFF_OR_ADMIN = true; window.ESTATE_EMERGENCY_URL = '../staff/security';</script>
    <script src="../js/emergency_alarm.js?v=<?php echo ASSET_VER; ?>"></script>
    <!-- Direct Emergency Hotline Modal Engine -->
    <script src="../js/hotline_modal.js?v=<?php echo ASSET_VER; ?>" defer></script>

    <?php require_once __DIR__ . '/../includes/mobile_nav.php'; ?>
    <style>
        :root {
            --primary-color: #0f766e;
            --primary-dark: #0d9488;
            --primary-light: #ccfbf1;
            --bs-body-font-family: 'Outfit', sans-serif;
            --bs-font-sans-serif: 'Outfit', sans-serif;
        }
        body, button, input, select, textarea { font-family: 'Outfit', sans-serif; background-color: #f8fafc; }
        .staff-navbar { background: #0f172a; color: white; padding: 0.75rem 1.5rem; display: flex; align-items: center; justify-content: space-between; border-bottom: 3px solid #0d9488; }
        .staff-badge { background: #0d9488; color: white; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; }
    </style>
</head>
<body>
    <div class="app-container">
