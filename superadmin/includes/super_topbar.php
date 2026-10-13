<?php
// superadmin/includes/super_topbar.php - floating pill topbar matching Dribbble design
$sa_counts = $platform->query("SELECT COUNT(*) c, SUM(status='active') a FROM estates WHERE status NOT IN ('deleted','archived')")->fetch_assoc();
?>
<main class="main-content">
    <header class="top-bar">
        <div class="d-flex align-items-center gap-3">
            <div class="toggle-sidebar d-md-none" style="cursor: pointer;">
                <i class="fa-solid fa-bars fs-5"></i>
            </div>
            
            <!-- Modern Dribbble Search Bar with ⌘ K -->
            <form action="estates" method="GET" class="desktop-topbar-search topbar-search-box m-0" onsubmit="if(!this.q.value.trim()){return false;}">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" name="q" placeholder="Search estates, tickets, admins..." autocomplete="off">
                <span class="topbar-kbd-pill" title="Press Ctrl+K or ⌘K">⌘ K</span>
            </form>
        </div>

        <div class="topbar-right-actions ms-auto">
            <!-- Messages / Ticket Icon -->
            <a href="tickets" class="topbar-action-circle position-relative" title="Support Tickets">
                <i class="fa-regular fa-comment-dots"></i>
                <?php if (!empty($sa_open['tickets'])): ?>
                    <span class="topbar-badge-dot"></span>
                <?php endif; ?>
            </a>

            <!-- Alerts / Notifications Bell -->
            <a href="alerts" class="topbar-action-circle position-relative" title="Security Alerts & Monitoring">
                <i class="fa-regular fa-bell"></i>
                <?php if (!empty($sa_open['alerts']) || !empty($sa_open['errors'])): ?>
                    <span class="topbar-badge-dot"></span>
                <?php endif; ?>
            </a>

            <!-- User Profile Dropdown (Welcome back Wade Warren) -->
            <div class="dropdown">
                <?php 
                $initials = 'SA';
                if (!empty($SA['name'])) {
                    $parts = explode(' ', trim($SA['name']));
                    $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                }
                ?>
                <button type="button" class="btn p-0 border-0 topbar-user-profile-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="topbar-user-initials-avatar">
                        <?= e($initials) ?>
                    </div>
                    <div class="topbar-user-details d-none d-sm-flex">
                        <span class="topbar-user-welcome">Welcome back</span>
                        <span class="topbar-user-fullname"><?= e($SA['name'] ?? 'Super Admin') ?></span>
                    </div>
                    <i class="fa-solid fa-chevron-down text-muted ms-1" style="font-size: 0.65rem;"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4 p-2 mt-2" style="min-width: 220px; z-index: 1055;">
                    <div class="px-3 py-2 border-bottom mb-1">
                        <div class="fw-bold text-dark small"><?= e($SA['name'] ?? 'Super Admin') ?></div>
                        <div class="text-muted" style="font-size: 0.72rem;"><?= e($SA['email'] ?? 'superadmin@platform.com') ?></div>
                    </div>
                    <a href="settings" class="dropdown-item py-2 px-3 rounded-2 small d-flex align-items-center gap-2">
                        <i class="fa-solid fa-sliders text-secondary"></i> System Settings
                    </a>
                    <a href="change_password" class="dropdown-item py-2 px-3 rounded-2 small d-flex align-items-center gap-2">
                        <i class="fa-solid fa-key text-warning"></i> Change Password
                    </a>
                    <div class="dropdown-divider my-1"></div>
                    <a href="../logout" class="dropdown-item py-2 px-3 rounded-2 small d-flex align-items-center gap-2 text-danger">
                        <i class="fa-solid fa-right-from-bracket"></i> Sign Out
                    </a>
                </div>
            </div>
        </div>
    </header>

    <?php if (is_impersonating_estate()): ?>
    <div class="px-4 py-2 text-white d-flex flex-wrap align-items-center justify-content-between gap-2 mx-4 mb-3 rounded-3" style="background:#b45309;font-size:.85rem">
        <span><strong>Impersonation active:</strong> acting inside <strong><?= e(get_impersonated_estate_name()) ?></strong>. Every action is audit-logged.</span>
        <form method="post" action="exit_impersonation"><button class="btn btn-sm btn-light fw-bold">Exit</button></form>
    </div>
    <?php endif; ?>

    <div class="content-wrapper">
        <?= sa_flash() ?>
