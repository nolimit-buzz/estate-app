<?php
// staff/sidebar.php
$current_page = str_replace('.php', '', basename($_SERVER['PHP_SELF']));
$user_name = $_SESSION['name'] ?? 'Staff Member';
$user_role = !empty($_SESSION['staff_role_title']) ? $_SESSION['staff_role_title'] : ucfirst($_SESSION['role'] ?? 'staff');

$branding = function_exists('get_estate_branding') ? get_estate_branding($conn) : [];
$estate_name = $branding['estate_name'] ?? 'Main Estate';
$estate_logo_url = $branding['estate_logo_url'] ?? '';

$stf_initials = 'ST';
if (!empty($user_name)) {
    $parts = explode(' ', trim($user_name));
    $stf_initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <a href="../staff/index" class="sidebar-brand-ring-logo" title="Estate Platform"></a>
    </div>

    <nav class="sidebar-nav">
        <ul class="sidebar-menu">
            <li class="nav-label">Staff Console</li>
            <li>
                <a href="../staff/index" class="<?php echo ($current_page == 'index') ? 'active' : ''; ?>" data-title="Overview Dashboard">
                    <i class="fa-solid fa-gauge"></i>
                    <span>Overview</span>
                </a>
            </li>
            <li>
                <a href="../staff/roster" class="<?php echo ($current_page == 'roster') ? 'active' : ''; ?>" data-title="Duty Roster &amp; Clock In">
                    <i class="fa-solid fa-calendar-check text-teal" style="color: #0d9488;"></i>
                    <span>Duty Roster</span>
                </a>
            </li>
            <li>
                <a href="../staff/community_chat" class="<?php echo ($current_page == 'community_chat') ? 'active' : ''; ?>" data-title="Estate Forum">
                    <i class="fa-solid fa-comments"></i>
                    <span>Community</span>
                </a>
            </li>

            <!-- Security & Gate Operations -->
            <?php if (hasPermission('visitors.view_log') || hasPermission('visitors.check_in_out') || hasPermission('incidents.create') || hasPermission('incidents.view') || isStaffRole()): ?>
            <li class="nav-label">Security &amp; Gate</li>
            <li>
                <a href="../staff/security" class="<?php echo ($current_page == 'security') ? 'active' : ''; ?>" data-title="Gate Visitor Passes">
                    <i class="fa-solid fa-shield-halved"></i>
                    <span>Visitor Passes</span>
                </a>
            </li>
            <li>
                <a href="../staff/incidents" class="<?php echo ($current_page == 'incidents') ? 'active' : ''; ?>" data-title="Incident &amp; Occurrence Book">
                    <i class="fa-solid fa-book-skull text-danger"></i>
                    <span>Incidents</span>
                </a>
            </li>
            <li>
                <a href="../staff/policies" class="<?php echo ($current_page == 'policies') ? 'active' : ''; ?>" data-title="Estate Rules &amp; Fines">
                    <i class="fa-solid fa-scale-balanced text-warning"></i>
                    <span>Rules &amp; Fines</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Finance, Billing & Receipts -->
            <?php if (hasPermission('finance.view_invoices') || hasPermission('finance.create_invoice') || hasPermission('finance.record_payment') || hasPermission('finance.view_receipts') || hasPermission('charges.manage')): ?>
            <li class="nav-label">Finance &amp; Invoices</li>
            <li>
                <a href="../staff/finance" class="<?php echo ($current_page == 'finance') ? 'active' : ''; ?>" data-title="Bills &amp; Receipts">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                    <span>Bills &amp; Dues</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Resident Registration & Onboarding -->
            <?php if (hasPermission('residents.view') || hasPermission('residents.manage') || hasPermission('tenancies.manage')): ?>
            <li class="nav-label">Resident Management</li>
            <li>
                <a href="../staff/directory" class="<?php echo ($current_page == 'directory') ? 'active' : ''; ?>" data-title="Resident Directory">
                    <i class="fa-solid fa-address-book"></i>
                    <span>Directory</span>
                </a>
            </li>
            <li>
                <a href="../staff/residents" class="<?php echo ($current_page == 'residents') ? 'active' : ''; ?>" data-title="Resident Registration">
                    <i class="fa-solid fa-users"></i>
                    <span>Residents</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Field Work Orders & Maintenance -->
            <?php if (hasPermission('maintenance.view_assigned') || hasPermission('maintenance.update_status')): ?>
            <li class="nav-label">Field Work Orders</li>
            <li>
                <a href="../staff/maintenance" class="<?php echo ($current_page == 'maintenance') ? 'active' : ''; ?>" data-title="My Work Orders">
                    <i class="fa-solid fa-hammer"></i>
                    <span>Work Orders</span>
                </a>
            </li>
            <li>
                <a href="../staff/artisans" class="<?php echo ($current_page == 'artisans') ? 'active' : ''; ?>" data-title="Verified Artisans">
                    <i class="fa-solid fa-wrench"></i>
                    <span>Artisans</span>
                </a>
            </li>
            <?php endif; ?>

            <li class="nav-label">Account</li>
            <li>
                <a href="../logout" class="text-danger" data-title="Sign Out">
                    <i class="fa-solid fa-power-off"></i>
                    <span>Sign Out</span>
                </a>
            </li>
        </ul>
    </nav>

    <div class="sidebar-footer-actions">
        <a href="../staff/roster" class="sidebar-footer-btn" title="Help & Support">
            <i class="fa-regular fa-circle-question"></i>
        </a>
        <a href="../logout" class="sidebar-footer-btn text-danger" title="Sign Out">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
        </a>
    </div>
</aside>

<main class="main-content">
    <?php 
    if (function_exists('renderMobileAppShell')) {
        renderMobileAppShell('staff', $current_page, $page_title ?? '');
    }
    ?>

    <header class="top-bar">
        <div class="d-flex align-items-center gap-3">
            <div class="toggle-sidebar d-md-none" style="cursor: pointer;">
                <i class="fa-solid fa-bars fs-5"></i>
            </div>

            <!-- Modern Dribbble Search Bar with ⌘ K -->
            <form action="../staff/security" method="GET" class="desktop-topbar-search topbar-search-box m-0" onsubmit="if(!this.q.value.trim()){return false;}">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" name="q" placeholder="Search passes, visitors, plates..." autocomplete="off">
                <span class="topbar-kbd-pill" title="Press Ctrl+K or ⌘K">⌘ K</span>
            </form>
        </div>

        <div class="topbar-right-actions ms-auto">
            <!-- Messages / Community Forum Chat Icon -->
            <a href="../staff/community_chat" class="topbar-action-circle" title="Messages &amp; Forum">
                <i class="fa-regular fa-comment-dots"></i>
            </a>

            <!-- Notification Bell with Dot -->
            <div class="dropdown position-relative">
                <a href="../staff/security" class="topbar-action-circle" title="Security Gatehouse &amp; Notifications">
                    <i class="fa-regular fa-bell"></i>
                    <span class="topbar-badge-dot"></span>
                </a>
            </div>

            <!-- Emergency Direct Hotlines Button -->
            <button type="button" class="estate-hotline-btn btn btn-outline-danger btn-sm rounded-pill px-2 px-sm-3 d-inline-flex align-items-center gap-2" title="Direct Emergency Hotlines">
                <i class="fa-solid fa-phone-volume text-danger hotline-pulse-icon"></i>
                <span class="fw-semibold d-none d-sm-inline">Hotlines</span>
            </button>

            <!-- User Profile Dropdown (Welcome back [Name]) -->
            <div class="dropdown">
                <button type="button" class="btn p-0 border-0 topbar-user-profile-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="topbar-user-initials-avatar">
                        <?php echo $stf_initials; ?>
                    </div>
                    <div class="topbar-user-details d-none d-sm-flex">
                        <span class="topbar-user-welcome">Welcome back</span>
                        <span class="topbar-user-fullname"><?php echo htmlspecialchars($user_name); ?></span>
                    </div>
                    <i class="fa-solid fa-chevron-down text-muted ms-1" style="font-size: 0.65rem;"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4 p-2 mt-2" style="min-width: 220px; z-index: 1055;">
                    <div class="px-3 py-2 border-bottom mb-1">
                        <div class="fw-bold text-dark small"><?php echo htmlspecialchars($user_name); ?></div>
                        <div class="text-muted" style="font-size: 0.72rem;"><?php echo htmlspecialchars($_SESSION['email'] ?? 'staff@estate.com'); ?></div>
                        <span class="badge bg-teal mt-1" style="background: #0d9488; font-size: 0.65rem;"><?php echo htmlspecialchars($user_role); ?></span>
                    </div>
                    <a href="../staff/roster" class="dropdown-item py-2 px-3 rounded-2 small d-flex align-items-center gap-2">
                        <i class="fa-solid fa-calendar-check text-teal" style="color: #0d9488;"></i> My Duty Roster
                    </a>
                    <a href="../staff/security" class="dropdown-item py-2 px-3 rounded-2 small d-flex align-items-center gap-2">
                        <i class="fa-solid fa-qrcode text-primary"></i> Scan Passes
                    </a>
                    <?php if (function_exists('isAdminRole') && isAdminRole()): ?>
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
            <div class="estate-header-brand" title="Estate: <?php echo htmlspecialchars($estate_name); ?>">
                <?php if (!empty($estate_logo_url)): ?>
                    <img src="<?php echo htmlspecialchars($estate_logo_url); ?>" alt="Estate Logo" class="estate-header-logo">
                <?php else: ?>
                    <div class="estate-header-fallback">
                        <i class="fa-solid fa-tree-city"></i>
                    </div>
                <?php endif; ?>
                <div class="estate-header-info">
                    <span class="estate-header-tag">Estate</span>
                    <span class="estate-header-name"><?php echo htmlspecialchars($estate_name); ?></span>
                </div>
            </div>
        </div>
    </header>

    <div class="content-wrapper p-3 p-md-4">
