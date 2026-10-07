<?php
// superadmin/includes/super_header.php - Super Admin SaaS Shell Header
require_once __DIR__ . '/super_auth.php';

$super_page = str_replace('.php', '', basename($_SERVER['PHP_SELF']));
$page_title = $page_title ?? 'SaaS Command Center';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - EstateHQ Super Admin Core</title>
    
    <!-- Google Fonts: Plus Jakarta Sans / Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6.4.0 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --saas-sidebar-bg: #0b1120;
            --saas-sidebar-subbg: #070c17;
            --saas-sidebar-hover: rgba(255, 255, 255, 0.05);
            --saas-sidebar-active: rgba(59, 130, 246, 0.12);
            --saas-sidebar-border: rgba(255, 255, 255, 0.07);
            --saas-sidebar-text: #94a3b8;
            --saas-sidebar-text-active: #ffffff;
            --saas-accent: #3b82f6;
            --saas-accent-glow: rgba(59, 130, 246, 0.25);
            --saas-main-bg: #f8fafc;
            --sidebar-width: 280px;
        }

        body {
            font-family: 'Plus Jakarta Sans', 'Outfit', sans-serif;
            background-color: var(--saas-main-bg);
            color: #0f172a;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        h1, h2, h3, h4, h5, h6 {
            font-family: 'Outfit', sans-serif;
            letter-spacing: -0.02em;
        }

        /* SaaS App Layout */
        #super-layout {
            display: flex;
            min-height: 100vh;
            width: 100%;
            position: relative;
        }

        /* Sidebar Styling */
        #super-sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, #0d1527 0%, #060913 100%);
            border-right: 1px solid var(--saas-sidebar-border);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 1040;
            transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 6px 0 28px rgba(0, 0, 0, 0.2);
        }

        #super-main {
            flex: 1;
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            background: var(--saas-main-bg);
            transition: margin-left 0.25s cubic-bezier(0.16, 1, 0.3, 1), width 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        /* Sidebar Brand */
        .sidebar-brand {
            padding: 1.35rem 1.4rem;
            border-bottom: 1px solid var(--saas-sidebar-border);
            display: flex;
            align-items: center;
            gap: 0.85rem;
            background: rgba(0, 0, 0, 0.25);
        }

        .brand-icon-box {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.25rem;
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.45);
        }

        .brand-title {
            font-size: 1.2rem;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.1;
            margin: 0;
            letter-spacing: -0.02em;
        }

        .brand-subtitle {
            font-size: 0.65rem;
            font-weight: 700;
            color: #38bdf8;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 3px;
        }

        .pulse-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background-color: #10b981;
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            animation: pulse-green 2s infinite;
        }

        @keyframes pulse-green {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        /* Nav List */
        .sidebar-nav-container {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 0.85rem;
        }

        .sidebar-nav-container::-webkit-scrollbar {
            width: 4px;
        }
        .sidebar-nav-container::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.12);
            border-radius: 10px;
        }

        .nav-category {
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #64748b;
            padding: 0.85rem 0.75rem 0.35rem 0.75rem;
            margin-top: 0.35rem;
        }

        .nav-link-super {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            padding: 0.65rem 0.9rem;
            border-radius: 10px;
            color: var(--saas-sidebar-text);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 600;
            transition: all 0.15s ease-in-out;
            margin-bottom: 3px;
            position: relative;
        }

        .nav-link-super i {
            font-size: 1rem;
            width: 20px;
            text-align: center;
            color: #64748b;
            transition: color 0.15s ease;
        }

        .nav-link-super:hover {
            color: #ffffff;
            background: var(--saas-sidebar-hover);
        }

        .nav-link-super:hover i {
            color: #38bdf8;
        }

        .nav-link-super.active {
            color: #ffffff;
            background: linear-gradient(90deg, rgba(59, 130, 246, 0.22) 0%, rgba(59, 130, 246, 0.06) 100%);
            border-left: 3px solid #3b82f6;
            font-weight: 700;
            box-shadow: inset 0 0 14px rgba(59, 130, 246, 0.15);
        }

        .nav-link-super.active i {
            color: #38bdf8;
        }

        /* Sidebar Footer / User Profile */
        .sidebar-user-footer {
            padding: 0.95rem 1.15rem;
            border-top: 1px solid var(--saas-sidebar-border);
            background: rgba(0, 0, 0, 0.3);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* Topbar Styling */
        #super-topbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid #e2e8f0;
            padding: 0.75rem 1.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1030;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
            width: 100%;
        }

        .topbar-search-input {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 9999px;
            padding: 0.45rem 1rem 0.45rem 2.25rem;
            font-size: 0.85rem;
            width: 260px;
            transition: all 0.2s;
        }

        .topbar-search-input:focus {
            background: #ffffff;
            width: 320px;
            border-color: #3b82f6;
            outline: none;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
        }

        /* Content Area */
        .super-content-body {
            padding: 1.75rem 2rem;
            flex: 1;
        }

        /* Cards & Metrics */
        .metric-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            padding: 1.4rem 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
            transition: all 0.2s ease-in-out;
            position: relative;
            overflow: hidden;
        }

        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 20px -5px rgba(0, 0, 0, 0.05);
            border-color: #cbd5e1;
        }

        .card-custom {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
            overflow: hidden;
        }

        /* Status Pills */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .status-badge.status-active {
            background: #dcfce7;
            color: #15803d;
        }
        .status-badge.status-suspended {
            background: #fee2e2;
            color: #b91c1c;
        }
        .status-badge.status-trial {
            background: #fef9c3;
            color: #854d0e;
        }
        .status-badge.status-maintenance {
            background: #f3e8ff;
            color: #7e22ce;
        }

        /* Mobile Overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 1035;
        }

        @media (max-width: 991.98px) {
            #super-sidebar {
                transform: translateX(-100%);
            }
            #super-sidebar.show {
                transform: translateX(0);
            }
            #super-main {
                margin-left: 0;
                width: 100%;
            }
            .sidebar-overlay.show {
                display: block;
            }
            .super-content-body {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>

<div id="super-layout">
    <!-- Mobile Backdrop -->
    <div class="sidebar-overlay" id="sidebarBackdrop" onclick="toggleSuperSidebar()"></div>
