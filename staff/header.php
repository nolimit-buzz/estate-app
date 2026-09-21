<?php
// staff/header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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
    <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/estate_notifications.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/estate_glassmorphism.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Global In-House Notification & Modal Alert Engine -->
    <script src="../js/estate_notifications.js?v=<?php echo time(); ?>"></script>
    <script src="../js/theme.js"></script>
    <script src="../js/searchable_select.js?v=<?php echo time(); ?>" defer></script>
    <!-- Universal Anti-Duplicate & Single-Click Submission Engine -->
    <script src="../js/anti_duplicate.js?v=<?php echo time(); ?>"></script>
    <!-- Real-time Emergency & Panic Alarm Engine -->
    <script>window.ESTATE_IS_STAFF_OR_ADMIN = true; window.ESTATE_EMERGENCY_URL = '../staff/security';</script>
    <script src="../js/emergency_alarm.js?v=<?php echo time(); ?>"></script>
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
    <div class="staff-navbar">
        <div class="d-flex align-items-center gap-3">
            <i class="fa-solid fa-shield-cat text-teal fs-4" style="color: #2dd4bf;"></i>
            <span class="fw-bold fs-5 text-white">Estate Staff Portal</span>
            <span class="staff-badge"><?php echo htmlspecialchars($user_role); ?></span>
        </div>
        <div class="d-flex align-items-center gap-2 gap-sm-3">
            <button type="button" class="theme-toggle-btn" title="Toggle Day/Night Mode">
                <i class="fa-solid fa-moon"></i>
                <span class="theme-text d-none d-sm-inline">Dark Mode</span>
            </button>
            <span class="text-slate-300 small me-2"><i class="fa-solid fa-user-circle me-1"></i> <?php echo htmlspecialchars($user_name); ?></span>
            <?php if (isAdminRole()): ?>
                <a href="../admin/index" class="btn btn-sm btn-outline-light me-2"><i class="fa-solid fa-user-gear"></i> Admin View</a>
            <?php endif; ?>
            <a href="../logout" class="btn btn-sm btn-danger"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>

            <!-- Estate Branding Badge at EXTREME Right Corner -->
            <div class="vr opacity-25 d-none d-sm-block my-1" style="height: 24px;"></div>
            <div class="estate-header-brand" title="Estate: <?php echo htmlspecialchars($estate_name); ?>" style="background: rgba(255, 255, 255, 0.1); border-color: rgba(255, 255, 255, 0.2);">
                <?php if (!empty($estate_logo_url)): ?>
                    <img src="<?php echo htmlspecialchars($estate_logo_url); ?>" alt="Estate Logo" class="estate-header-logo">
                <?php else: ?>
                    <div class="estate-header-fallback" style="background: #0d9488;">
                        <i class="fa-solid fa-tree-city"></i>
                    </div>
                <?php endif; ?>
                <div class="estate-header-info">
                    <span class="estate-header-tag" style="color: #5eead4;">Estate</span>
                    <span class="estate-header-name" style="color: #ffffff;"><?php echo htmlspecialchars($estate_name); ?></span>
                </div>
            </div>
        </div>
    </div>
    <div class="app-container">
