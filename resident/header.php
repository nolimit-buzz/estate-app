<?php
// resident/header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

$user_id = intval($_SESSION['user_id']);
$estate_id = get_estate_id();
$user_name = $_SESSION['name'] ?? 'Resident';

// Fetch Custom Theme Color if stored
$theme_color = '#3b82f6';
$res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'theme_color' AND estate_id = $estate_id");
if ($res && $row = $res->fetch_assoc()) {
    $theme_color = $row['setting_value'];
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
$primary_light = adjustBrightness($primary, 230);
$primary_rgb = hex2rgb($primary);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Portal - Estate Management</title>
    
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
    
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Global In-House Notification & Modal Alert Engine -->
    <script src="../js/estate_notifications.js?v=<?php echo time(); ?>"></script>
    <!-- Global Theme Switcher Script -->
    <script src="../js/theme.js"></script>
    <script src="../js/searchable_select.js?v=<?php echo time(); ?>" defer></script>
    <!-- Real-time Emergency & Panic Alarm Engine -->
    <script>window.ESTATE_IS_STAFF_OR_ADMIN = false; window.ESTATE_EMERGENCY_URL = 'emergency';</script>
    <script src="../js/emergency_alarm.js?v=<?php echo time(); ?>"></script>
    <script src="../js/panic_dispatcher.js?v=<?php echo time(); ?>"></script>

    <style>
        :root {
            --primary-color: <?php echo $primary; ?>;
            --primary-dark: <?php echo $primary_dark; ?>;
            --primary-darker: <?php echo $primary_darker; ?>;
            --primary-light: <?php echo $primary_light; ?>;
            --primary-rgb: <?php echo $primary_rgb; ?>;
        }

        /* Floating Resident SOS Panic Trigger */
        .floating-panic-btn {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 99990;
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            color: #ffffff;
            border: 3px solid #ffffff;
            box-shadow: 0 8px 25px rgba(220, 38, 38, 0.5);
            border-radius: 50px;
            padding: 12px 22px;
            font-weight: 800;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            animation: floatingPulse 2.5s infinite;
        }
        .floating-panic-btn:hover {
            transform: scale(1.08) translateY(-3px);
            box-shadow: 0 12px 30px rgba(220, 38, 38, 0.7);
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #ffffff;
        }
        @keyframes floatingPulse {
            0% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.7); }
            70% { box-shadow: 0 0 0 14px rgba(220, 38, 38, 0); }
            100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0); }
        }
    </style>
</head>
<body>
    <!-- Persistent Floating Panic SOS Button for Residents -->
    <button type="button" class="floating-panic-btn btn-estate-panic-trigger" title="Trigger Instant Emergency SOS">
        <span style="font-size: 1.25rem;">🚨</span>
        <span>SOS PANIC</span>
    </button>
    <div class="app-container">
