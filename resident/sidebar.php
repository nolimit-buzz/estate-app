<?php
// resident/sidebar.php
if (!isset($conn)) {
    require_once __DIR__ . '/../config.php';
}

$current_page = str_replace('.php', '', basename($_SERVER['PHP_SELF']));

// Fetch Estate Brand Details
$branding = get_estate_branding($conn);
$estate_name = $branding['estate_name'] ?? 'Main Estate';
$estate_logo_url = $branding['estate_logo_url'] ?? '';
$app_company_name = $branding['app_company_name'] ?? 'NoLimitBuzz';
$app_company_logo_url = $branding['app_company_logo_url'] ?? '';
$user_name = $_SESSION['name'] ?? 'Resident';

// Fetch Resident Avatar & Property Unit
$res_avatar = '';
$res_unit_label = '';
$s_uid = intval($_SESSION['user_id'] ?? 0);
if ($s_uid) {
    $av_res = $conn->query("SELECT r.image_path, f.number as flat_no, b.name as building_name 
                           FROM residents r 
                           LEFT JOIN flats f ON r.flat_id = f.id 
                           LEFT JOIN buildings b ON f.building_id = b.id 
                           WHERE r.user_id = $s_uid AND r.estate_id = " . get_estate_id() . " 
                           ORDER BY r.id DESC LIMIT 1");
    if ($av_res && $av_row = $av_res->fetch_assoc()) {
        $raw_av = $av_row['image_path'] ?? '';
        if (!empty($raw_av)) {
            if (file_exists($raw_av)) {
                $res_avatar = $raw_av;
            } elseif (file_exists('../' . ltrim($raw_av, './'))) {
                $res_avatar = '../' . ltrim($raw_av, './');
            }
        }
        if (!empty($av_row['flat_no'])) {
            $res_unit_label = (!empty($av_row['building_name']) ? $av_row['building_name'] . ' • ' : '') . 'Unit ' . $av_row['flat_no'];
        }
    }
}

// Fetch unread notices count & total notifications
$unread_notices_cnt = 0;
$unread_all_notifs_cnt = 0;
$res_pending_contact_req = null;
$res_recent_notifs = [];
if ($s_uid) {
    $e_id = get_estate_id();
    // Count active broadcast notices directly from estate_announcements
    $un_res = $conn->query("SELECT COUNT(id) as cnt FROM estate_announcements WHERE estate_id = $e_id AND status = 'active'");
    if ($un_res) {
        $unread_notices_cnt = intval($un_res->fetch_assoc()['cnt'] ?? 0);
    }

    // Unread system notifications only (excluding broadcasts)
    $all_un_res = $conn->query("SELECT COUNT(id) as cnt FROM notifications WHERE user_id = $s_uid AND estate_id = $e_id AND is_read = 0 AND type NOT IN ('estate_broadcast', 'zone_notice')");
    if ($all_un_res) {
        $unread_all_notifs_cnt = intval($all_un_res->fetch_assoc()['cnt'] ?? 0);
    }

    $p_req_res = $conn->query("SELECT * FROM contact_change_requests WHERE user_id = $s_uid AND estate_id = $e_id AND status = 'pending' LIMIT 1");
    if ($p_req_res && $p_req_res->num_rows > 0) {
        $res_pending_contact_req = $p_req_res->fetch_assoc();
    }

    $rec_res = $conn->query("SELECT * FROM notifications WHERE user_id = $s_uid AND estate_id = $e_id AND type NOT IN ('estate_broadcast', 'zone_notice') ORDER BY id DESC LIMIT 5");
    if ($rec_res) {
        while ($rn = $rec_res->fetch_assoc()) {
            $res_recent_notifs[] = $rn;
        }
    }
}
?>

<aside class="sidebar sidebar-named">
    <div class="sidebar-header">
        <a href="index" class="sidebar-brand-ring" title="<?php echo htmlspecialchars($estate_name); ?>">
            <i class="fa-solid fa-circle-notch"></i>
        </a>
        <div class="d-flex flex-column" style="min-width: 0;">
            <h5 class="sidebar-brand-title text-truncate"><?php echo htmlspecialchars($estate_name); ?></h5>
            <div class="sidebar-brand-sub">
                <span style="width: 6px; height: 6px; border-radius: 50%; background: #10b981; display: inline-block;"></span>
                <span>Resident Portal</span>
            </div>
        </div>
    </div>
    
    <nav class="sidebar-nav">
        <ul>
            <li class="nav-section-label">Core Operations</li>
            <li>
                <a href="index" class="<?php echo ($current_page == 'index') ? 'active' : ''; ?>" title="Overview Dashboard">
                    <i class="fa-solid fa-chart-pie"></i>
                    <span class="nav-name-tag">Overview Dashboard</span>
                </a>
            </li>
            <li>
                <a href="notifications" class="<?php echo ($current_page == 'notifications') ? 'active' : ''; ?> d-flex align-items-center" title="Action & Notifications">
                    <i class="fa-solid fa-bell"></i>
                    <span class="nav-name-tag">Action &amp; Notifications</span>
                    <?php if ($unread_all_notifs_cnt > 0 || $res_pending_contact_req): ?>
                        <span class="badge <?php echo ($res_pending_contact_req ? 'bg-warning text-dark' : 'bg-danger'); ?> rounded-pill ms-auto" style="font-size: 0.68rem;">
                            <?php echo $unread_all_notifs_cnt > 0 ? $unread_all_notifs_cnt : '1 req'; ?>
                        </span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="notices" class="<?php echo ($current_page == 'notices') ? 'active' : ''; ?> d-flex align-items-center" title="Notices & Broadcasts">
                    <i class="fa-solid fa-bullhorn"></i>
                    <span class="nav-name-tag">Notices &amp; Broadcasts</span>
                    <?php if ($unread_notices_cnt > 0): ?>
                        <span class="badge bg-danger rounded-pill ms-auto" style="font-size: 0.68rem;"><?php echo $unread_notices_cnt; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <li class="nav-section-label">Property &amp; Living</li>
            <li>
                <a href="property" class="<?php echo ($current_page == 'property') ? 'active' : ''; ?>" title="My Property Details">
                    <i class="fa-solid fa-building-user"></i>
                    <span class="nav-name-tag">My Property Details</span>
                </a>
            </li>
            <li>
                <a href="household" class="<?php echo ($current_page == 'household') ? 'active' : ''; ?>" title="Vehicles, Pets &amp; Domestic Staff">
                    <i class="fa-solid fa-car-side"></i>
                    <span class="nav-name-tag">Vehicles, Pets &amp; Staff</span>
                </a>
            </li>
            <li>
                <a href="visitors" class="<?php echo ($current_page == 'visitors') ? 'active' : ''; ?>" title="Visitor Access Passes">
                    <i class="fa-solid fa-id-card-clip"></i>
                    <span class="nav-name-tag">Visitor Access Passes</span>
                </a>
            </li>
            <li>
                <a href="report_issue" class="<?php echo ($current_page == 'report_issue') ? 'active' : ''; ?>" title="Maintenance Requests">
                    <i class="fa-solid fa-screwdriver-wrench"></i>
                    <span class="nav-name-tag">Maintenance Requests</span>
                </a>
            </li>
            <li>
                <a href="artisans" class="<?php echo ($current_page == 'artisans') ? 'active' : ''; ?>" title="Verified Artisans">
                    <i class="fa-solid fa-user-gear text-primary"></i>
                    <span class="nav-name-tag">Verified Artisans</span>
                </a>
            </li>

            <li class="nav-section-label">Finance &amp; Safety</li>
            <li>
                <a href="finance" class="<?php echo ($current_page == 'finance') ? 'active' : ''; ?>" title="Bills & Invoices">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                    <span class="nav-name-tag">Bills &amp; Invoices</span>
                </a>
            </li>
            <li>
                <a href="receipts" class="<?php echo ($current_page == 'receipts') ? 'active' : ''; ?>" title="My Receipts">
                    <i class="fa-solid fa-receipt"></i>
                    <span class="nav-name-tag">My Receipts</span>
                </a>
            </li>
            <li>
                <a href="emergency" class="<?php echo ($current_page == 'emergency') ? 'active' : ''; ?> text-danger fw-bold" title="Emergency & Panic SOS">
                    <i class="fa-solid fa-truck-medical text-danger"></i>
                    <span class="nav-name-tag text-danger">Emergency &amp; Panic SOS</span>
                </a>
            </li>

            <li class="nav-section-label">Community &amp; Bylaws</li>
            <li>
                <a href="community_chat" class="<?php echo ($current_page == 'community_chat') ? 'active' : ''; ?>" title="Estate Forum">
                    <i class="fa-solid fa-comments"></i>
                    <span class="nav-name-tag">Estate Forum</span>
                </a>
            </li>
            <li>
                <a href="directory" class="<?php echo ($current_page == 'directory') ? 'active' : ''; ?>" title="Estate Directory">
                    <i class="fa-solid fa-address-book"></i>
                    <span class="nav-name-tag">Estate Directory</span>
                </a>
            </li>
            <li>
                <a href="policies" class="<?php echo ($current_page == 'policies') ? 'active' : ''; ?>" title="Rules & Penalties">
                    <i class="fa-solid fa-book-bookmark text-primary"></i>
                    <span class="nav-name-tag">Rules &amp; Penalties</span>
                </a>
            </li>
            <li>
                <a href="settings" class="<?php echo ($current_page == 'settings') ? 'active' : ''; ?>" title="Portal Settings">
                    <i class="fa-solid fa-sliders"></i>
                    <span class="nav-name-tag">Portal Settings</span>
                </a>
            </li>

            <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'superadmin', 'manager'])): ?>
            <li class="nav-section-label text-primary">Management</li>
            <li>
                <a href="../admin/index" class="text-primary fw-bold" title="Return to Admin">
                    <i class="fa-solid fa-arrow-left"></i>
                    <span class="nav-name-tag">Return to Admin</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>
    <div class="sidebar-footer-actions">
        <div class="sidebar-user-block">
            <div class="sidebar-user-avatar">
                <?php
                $res_initials = 'RE';
                if (!empty($user_name)) {
                    $parts = explode(' ', trim($user_name));
                    $res_initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                }
                echo htmlspecialchars($res_initials);
                ?>
            </div>
            <div class="sidebar-user-text">
                <div class="sidebar-user-name"><?php echo htmlspecialchars($user_name); ?></div>
                <div class="sidebar-user-role"><?php echo htmlspecialchars(!empty($res_unit_label) ? $res_unit_label : 'Resident'); ?></div>
            </div>
        </div>
        <div class="sidebar-footer-links">
            <a href="../change_password" class="sidebar-footer-link" title="Change Password">
                <i class="fa-solid fa-key" style="font-size: 11px;"></i> Password
            </a>
            <a href="../logout" class="sidebar-footer-link text-danger" title="Sign Out">
                <i class="fa-solid fa-right-from-bracket" style="font-size: 11px;"></i> Sign out
            </a>
        </div>
    </div>
</aside>

<main class="main-content">
    <?php 
    if (function_exists('renderMobileAppShell')) {
        renderMobileAppShell('resident', $current_page, $page_title ?? '');
    }
    ?>
    <header class="top-bar">
        <div class="d-flex align-items-center gap-3">
            <div class="toggle-sidebar d-md-none" style="cursor: pointer;">
                <i class="fa-solid fa-bars fs-5"></i>
            </div>

            <!-- Modern Dribbble Search Bar with ⌘ K -->
            <form action="artisans" method="GET" class="desktop-topbar-search topbar-search-box m-0" onsubmit="if(!this.q.value.trim()){return false;}">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" name="q" placeholder="Search..." autocomplete="off">
                <span class="topbar-kbd-pill" title="Press Ctrl+K or ⌘K">⌘ K</span>
            </form>
        </div>

        <div class="topbar-right-actions ms-auto">
            <!-- Messages / Community Forum Chat Icon -->
            <a href="chat" class="topbar-action-circle" title="Messages & Forum">
                <i class="fa-regular fa-comment-dots"></i>
            </a>

            <!-- Notification Bell with Quick Action Dropdown -->
            <div class="dropdown position-relative">
                <button type="button" class="topbar-action-circle border-0" id="residentNotifBellBtn" data-bs-toggle="dropdown" aria-expanded="false" title="My Notifications &amp; Requests">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unread_all_notifs_cnt > 0 || $res_pending_contact_req): ?>
                        <span class="topbar-badge-dot"></span>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4 p-0 mt-2" style="width: 330px; max-width: 90vw; z-index: 1055; overflow: hidden;">
                    <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-slate-900" style="font-size: 0.9rem;">
                            <i class="fa-solid fa-bell text-primary me-1"></i> Notifications
                        </span>
                        <a href="notifications" class="small text-decoration-none fw-semibold text-primary">Notification Hub &rarr;</a>
                    </div>
                    <div class="p-0" style="max-height: 290px; overflow-y: auto;">
                        <?php if ($res_pending_contact_req): ?>
                            <a href="notifications" class="dropdown-item p-3 border-bottom d-flex align-items-start gap-2.5" style="background: #fffcf5; white-space: normal;">
                                <div class="glass-icon-circle hero-icon-circle glass-icon-light-amber" style="width: 32px; height: 32px; min-width: 32px; min-height: 32px; font-size: 0.85rem;">
                                    <i class="fa-solid fa-hourglass-half"></i>
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <div class="fw-bold text-warning-emphasis" style="font-size: 0.82rem;">Contact Update Under Review</div>
                                    <div class="small text-muted" style="font-size: 0.75rem;">Awaiting Central / Zonal Admin approval</div>
                                    <div class="mt-1"><span class="badge bg-warning text-dark" style="font-size: 0.65rem;">Track Status &rarr;</span></div>
                                </div>
                            </a>
                        <?php endif; ?>

                        <?php if (!empty($res_recent_notifs)): ?>
                            <?php foreach ($res_recent_notifs as $rn): 
                                $is_unr = ($rn['is_read'] == 0);
                            ?>
                                <a href="notifications" class="dropdown-item p-3 border-bottom d-flex align-items-start gap-2.5" style="white-space: normal; background: <?php echo $is_unr ? '#f8fafc' : '#ffffff'; ?>;">
                                    <div style="width: 8px; height: 8px; border-radius: 50%; background: <?php echo $is_unr ? '#6b4eff' : 'transparent'; ?>; margin-top: 6px; flex-shrink: 0;"></div>
                                    <div style="flex: 1; min-width: 0;">
                                        <div class="fw-semibold text-dark text-truncate" style="font-size: 0.82rem;"><?php echo htmlspecialchars($rn['title']); ?></div>
                                        <div class="text-secondary small text-truncate" style="font-size: 0.75rem;"><?php echo htmlspecialchars($rn['message']); ?></div>
                                        <div class="text-muted" style="font-size: 0.68rem; margin-top: 2px;">
                                            <i class="fa-regular fa-clock me-1"></i><?php echo date('M j, g:i a', strtotime($rn['created_at'])); ?>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php elseif (!$res_pending_contact_req): ?>
                            <div class="p-4 text-center text-muted small">
                                <i class="fa-regular fa-bell-slash fs-4 d-block mb-2 text-secondary opacity-50"></i>
                                No notifications yet
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="p-2 bg-light text-center border-top">
                        <a href="notifications" class="btn btn-sm btn-link text-decoration-none fw-semibold text-primary p-0" style="font-size: 0.8rem;">
                            View All Notifications
                        </a>
                    </div>
                </div>
            </div>

            <!-- User Profile Dropdown (Welcome back [Name]) -->
            <div class="dropdown">
                <?php 
                $res_initials = 'R';
                if (!empty($user_name)) {
                    $parts = explode(' ', trim($user_name));
                    $res_initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                }
                ?>
                <button type="button" class="btn p-0 border-0 topbar-user-profile-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="topbar-user-initials-avatar">
                        <?php echo $res_initials; ?>
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
                        <div class="text-muted" style="font-size: 0.72rem;"><?php echo htmlspecialchars($_SESSION['email'] ?? 'resident@estate.com'); ?></div>
                    </div>
                    <a href="settings" class="dropdown-item py-2 px-3 rounded-2 small d-flex align-items-center gap-2">
                        <i class="fa-solid fa-user-gear text-secondary"></i> My Account & Contact
                    </a>
                    <a href="property" class="dropdown-item py-2 px-3 rounded-2 small d-flex align-items-center gap-2">
                        <i class="fa-solid fa-house-chimney text-primary"></i> Flat & Tenancy
                    </a>
                    <a href="finance" class="dropdown-item py-2 px-3 rounded-2 small d-flex align-items-center gap-2">
                        <i class="fa-solid fa-file-invoice-dollar text-success"></i> My Bills & Invoices
                    </a>
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
