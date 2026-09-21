<?php
// zone/header.php - Zonal Management Portal Header
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header("Location: login?error=unauthenticated");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zonal Admin Console - <?php echo htmlspecialchars($_SESSION['zone_name'] ?? 'Zone'); ?></title>
    
    <!-- Google Fonts: Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/estate_notifications.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/estate_glassmorphism.css?v=<?php echo time(); ?>">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Global In-House Notification & Modal Alert Engine -->
    <script src="../js/estate_notifications.js?v=<?php echo time(); ?>"></script>
    <!-- Global Theme Switcher Script -->
    <script src="../js/theme.js"></script>
    <script src="../js/searchable_select.js?v=<?php echo time(); ?>" defer></script>
    <!-- Universal Anti-Duplicate & Single-Click Submission Engine -->
    <script src="../js/anti_duplicate.js?v=<?php echo time(); ?>"></script>
    <!-- Real-time Emergency & Panic Alarm Engine -->
    <script>window.ESTATE_IS_STAFF_OR_ADMIN = true; window.ESTATE_EMERGENCY_URL = 'emergency';</script>
    <script src="../js/emergency_alarm.js?v=<?php echo time(); ?>"></script>

    <?php
    $theme_color = '#7e22ce'; // Purple theme for Zone Admin
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
    $primary_light = adjustBrightness($primary, 230);
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
    <div class="app-container">
