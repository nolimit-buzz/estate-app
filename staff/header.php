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
    <link rel="stylesheet" href="../css/mobile_app.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/hotline.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Global In-House Notification & Modal Alert Engine -->
    <script src="../js/estate_notifications.js?v=<?php echo time(); ?>"></script>
    <script src="../js/theme.js"></script>
    <script src="../js/searchable_select.js?v=<?php echo time(); ?>" defer></script>
    <script src="../js/mobile_app.js?v=<?php echo time(); ?>" defer></script>
    <!-- Universal Anti-Duplicate & Single-Click Submission Engine -->
    <script src="../js/anti_duplicate.js?v=<?php echo time(); ?>"></script>
    <!-- Real-time Emergency & Panic Alarm Engine -->
    <script>window.ESTATE_IS_STAFF_OR_ADMIN = true; window.ESTATE_EMERGENCY_URL = '../staff/security';</script>
    <script src="../js/emergency_alarm.js?v=<?php echo time(); ?>"></script>
    <!-- Direct Emergency Hotline Modal Engine -->
    <script src="../js/hotline_modal.js?v=<?php echo time(); ?>" defer></script>

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
    <div class="staff-navbar">
        <div class="d-flex align-items-center gap-3">
            <i class="fa-solid fa-shield-cat text-teal fs-4" style="color: #2dd4bf;"></i>
            <span class="fw-bold fs-5 text-white">Estate Staff Portal</span>
            <span class="staff-badge"><?php echo htmlspecialchars($user_role); ?></span>
        </div>

        <!-- Desktop Quick Search (Image 3 Style) -->
        <form action="../staff/security" method="GET" class="desktop-topbar-search m-0" onsubmit="if(!this.q.value.trim()){return false;}" style="background: rgba(255, 255, 255, 0.1); border-color: rgba(255, 255, 255, 0.2);">
            <i class="fa-solid fa-magnifying-glass text-teal" style="color: #2dd4bf;"></i>
            <input type="text" name="q" placeholder="Search visitor pass code, plate, artisan..." autocomplete="off" style="color: #ffffff;">
        </form>

        <div class="d-flex align-items-center gap-2 gap-sm-3">
            <!-- Emergency Hotline Button -->
            <button type="button" class="estate-hotline-btn btn btn-outline-danger btn-sm rounded-pill px-2 px-sm-3 d-inline-flex align-items-center gap-2" title="Direct Emergency Hotlines (Call without triggering alarm)">
                <i class="fa-solid fa-phone-volume text-danger hotline-pulse-icon"></i>
                <span class="fw-semibold d-none d-sm-inline">Hotlines</span>
            </button>

            <button type="button" class="theme-toggle-btn" title="Toggle Day/Night Mode">
                <i class="fa-solid fa-moon"></i>
                <span class="theme-text d-none d-sm-inline">Dark Mode</span>
            </button>

            <!-- User Profile Dropdown (Image 3 Style) -->
            <div class="dropdown">
                <button type="button" class="btn p-0 border-0 d-flex align-items-center gap-2 text-decoration-none" data-bs-toggle="dropdown" aria-expanded="false" style="background: transparent;">
                    <div class="text-end d-none d-sm-block">
                        <div class="user-name fw-bold text-white" style="font-size: 0.84rem; line-height: 1.2;"><?php echo htmlspecialchars($user_name); ?></div>
                        <small class="text-teal text-uppercase" style="color: #5eead4; font-size: 0.68rem; letter-spacing: 0.04em;"><?php echo htmlspecialchars($user_role); ?></small>
                    </div>
                    <?php 
                    $stf_initials = 'ST';
                    if (!empty($user_name)) {
                        $parts = explode(' ', trim($user_name));
                        $stf_initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                    }
                    ?>
                    <div class="user-avatar" style="width: 36px; height: 36px; border-radius: 8px; background: #0d9488; color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; border: 1px solid rgba(255,255,255,0.2);">
                        <?php echo $stf_initials; ?>
                    </div>
                    <i class="fa-solid fa-chevron-down text-white-50" style="font-size: 0.65rem;"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4 p-2 mt-2" style="min-width: 220px; z-index: 1055;">
                    <div class="px-3 py-2 border-bottom mb-1">
                        <div class="fw-bold text-dark small"><?php echo htmlspecialchars($user_name); ?></div>
                        <div class="text-muted" style="font-size: 0.72rem;"><?php echo htmlspecialchars($_SESSION['email'] ?? 'staff@estate.com'); ?></div>
                    </div>
                    <a href="../staff/roster" class="dropdown-item py-2 px-3 rounded-2 small d-flex align-items-center gap-2">
                        <i class="fa-solid fa-calendar-check text-teal" style="color: #0d9488;"></i> My Duty Roster
                    </a>
                    <a href="../staff/security" class="dropdown-item py-2 px-3 rounded-2 small d-flex align-items-center gap-2">
                        <i class="fa-solid fa-qrcode text-primary"></i> Scan Passes
                    </a>
                    <?php if (isAdminRole()): ?>
                        <a href="../admin/index" class="dropdown-item py-2 px-3 rounded-2 small d-flex align-items-center gap-2">
                            <i class="fa-solid fa-user-gear text-info"></i> Admin View
                        </a>
                    <?php endif; ?>
                    <a href="../change_password" class="dropdown-item py-2 px-3 rounded-2 small d-flex align-items-center gap-2">
                        <i class="fa-solid fa-key text-warning"></i> Change Password
                    </a>
                    <div class="dropdown-divider my-1"></div>
                    <a href="../logout" class="dropdown-item py-2 px-3 rounded-2 small d-flex align-items-center gap-2 text-danger">
                        <i class="fa-solid fa-right-from-bracket"></i> Sign Out
                    </a>
                </div>
            </div>

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
