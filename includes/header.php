<?php
// includes/header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login?error=unauthenticated");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Real Estate Admin</title>
    
    <!-- Google Fonts: Outfit for a premium look -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/estate_notifications.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/estate_glassmorphism.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/mobile_app.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/hotline.css?v=<?php echo time(); ?>">
    
    <!-- Font Awesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Global In-House Notification & Modal Alert Engine -->
    <script src="../js/estate_notifications.js?v=<?php echo time(); ?>"></script>
    <!-- Global Theme Switcher Script -->
    <script src="../js/theme.js"></script>
    <script src="../js/searchable_select.js?v=<?php echo time(); ?>" defer></script>
    <script src="../js/mobile_app.js?v=<?php echo time(); ?>" defer></script>
    <!-- Universal Anti-Duplicate & Single-Click Submission Engine -->
    <script src="../js/anti_duplicate.js?v=<?php echo time(); ?>"></script>
    <!-- Real-time Emergency & Panic Alarm Engine -->
    <script>window.ESTATE_IS_STAFF_OR_ADMIN = true; window.ESTATE_EMERGENCY_URL = '../admin/emergency';</script>
    <script src="../js/emergency_alarm.js?v=<?php echo time(); ?>"></script>
    <!-- Direct Emergency Hotline Modal Engine -->
    <script src="../js/hotline_modal.js?v=<?php echo time(); ?>" defer></script>

    <?php require_once __DIR__ . '/mobile_nav.php'; ?>

    <?php
    // Fetch Theme Color
    $theme_color = '#3b82f6'; // Default
    if (isset($conn)) {
        $estate_id = get_estate_id();
        $res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'theme_color' AND estate_id = $estate_id");
        if ($res && $row = $res->fetch_assoc()) {
            $theme_color = $row['setting_value'];
        }
    }
    
    // Helper to calculate shades
    if (!function_exists('adjustBrightness')) {
        function adjustBrightness($hex, $steps) {
            $steps = max(-255, min(255, $steps));
            $hex = str_replace('#', '', $hex);
            if (strlen($hex) == 3) $hex = str_repeat(substr($hex, 0, 1), 2) . str_repeat(substr($hex, 1, 1), 2) . str_repeat(substr($hex, 2, 1), 2);
            
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));

            $r = max(0, min(255, $r + $steps));
            $g = max(0, min(255, $g + $steps));
            $b = max(0, min(255, $b + $steps));

            return '#' . str_pad(dechex($r), 2, '0', STR_PAD_LEFT) . str_pad(dechex($g), 2, '0', STR_PAD_LEFT) . str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
        }
    }
    
    if (!function_exists('hex2rgb')) {
        function hex2rgb($hex) {
            $hex = str_replace('#', '', $hex);
            if (strlen($hex) == 3) $hex = str_repeat(substr($hex, 0, 1), 2) . str_repeat(substr($hex, 1, 1), 2) . str_repeat(substr($hex, 2, 1), 2);
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            return "$r, $g, $b";
        }
    }

    $primary = $theme_color;
    $primary_dark = adjustBrightness($primary, -30);
    $primary_darker = adjustBrightness($primary, -50);
    $primary_light = adjustBrightness($primary, 230); // Very light for backgrounds
    $primary_rgb = hex2rgb($primary);
    ?>
    <style>
        :root {
            --primary-color: <?php echo $primary; ?>;
            --primary-dark: <?php echo $primary_dark; ?>;
            --primary-darker: <?php echo $primary_darker; ?>;
            --primary-light: <?php echo $primary_light; ?>;
            --primary-rgb: <?php echo $primary_rgb; ?>;
        }
    </style>
</head>
<body>
<?php if (function_exists('is_impersonating_estate') && is_impersonating_estate()): ?>
    <div style="background: linear-gradient(90deg, #92400e, #d97706); color: #ffffff; padding: 10px 24px; font-weight: 600; font-size: 0.88rem; display: flex; align-items: center; justify-content: space-between; z-index: 999999; position: sticky; top: 0; box-shadow: 0 4px 15px rgba(0,0,0,0.25);">
        <div style="display: flex; align-items: center; gap: 10px;">
            <i class="fa-solid fa-triangle-exclamation fs-5 text-warning"></i>
            <span>
                <strong>SUPER ADMIN OPERATOR MODE:</strong> Managing <strong><?php echo htmlspecialchars(get_impersonated_estate_name($conn)); ?></strong> (Tenant ID: #<?php echo get_estate_id(); ?>).
            </span>
        </div>
        <div style="display: flex; gap: 10px; align-items: center;">
            <a href="../superadmin/index" class="btn btn-sm btn-light rounded-pill px-3 py-1 fw-bold text-dark shadow-sm" style="font-size: 0.78rem;">
                <i class="fa-solid fa-layer-group me-1"></i> Switch Estate
            </a>
            <a href="../superadmin/exit_impersonation" class="btn btn-sm btn-dark rounded-pill px-3 py-1 fw-bold shadow-sm" style="font-size: 0.78rem;">
                <i class="fa-solid fa-right-from-bracket me-1"></i> Exit Impersonation
            </a>
        </div>
    </div>
<?php endif; ?>
    <div class="app-container">
