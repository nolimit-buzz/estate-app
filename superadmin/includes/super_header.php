<?php
// superadmin/includes/super_header.php - Super Admin SaaS Shell Header
require_once __DIR__ . '/super_auth.php';

$super_page = str_replace('.php', '', basename($_SERVER['PHP_SELF']));
$page_title = $page_title ?? 'Platform Command Center';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - Platform Control</title>
    
    <!-- Google Fonts: Plus Jakarta Sans / Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6.4.0 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Mobile app shell (same as admin/zone portals) -->
    <link rel="stylesheet" href="../css/mobile_app.css?v=<?php echo defined('ASSET_VER') ? ASSET_VER : time(); ?>">

    <!-- Dribbble Design System Theme -->
    <link rel="stylesheet" href="../css/dashboard-theme.css?v=<?php echo defined('ASSET_VER') ? ASSET_VER : time(); ?>">
</head>
<body class="superadmin-portal">

<div class="app-container has-named-sidebar">
