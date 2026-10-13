<!-- zone/sidebar.php - Zonal Management Portal Navigation -->
<?php
$current_page = str_replace('.php', '', basename($_SERVER['PHP_SELF']));
$zone_name_display = $_SESSION['zone_name'] ?? 'Zone Portal';
$zone_code_display = $_SESSION['zone_code'] ?? 'ZONE';

// Fetch unread notification counts and pending zonal requests
$zb_uid = intval($_SESSION['user_id'] ?? 0);
$zb_zid = intval($_SESSION['zone_id'] ?? 0);
$zb_eid = function_exists('get_estate_id') ? get_estate_id() : 1;
$zb_unread_count = 0;
$zb_pending_requests_cnt = 0;
$zb_recent_notifs = [];
if ($zb_uid > 0 && isset($conn)) {
    $unr_res = $conn->query("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = $zb_uid AND estate_id = $zb_eid AND is_read = 0");
    if ($unr_res) $zb_unread_count = intval($unr_res->fetch_assoc()['cnt'] ?? 0);

    $pen_res = $conn->query("
        SELECT COUNT(DISTINCT ccr.id) as cnt 
        FROM contact_change_requests ccr
        LEFT JOIN residents r ON ccr.resident_id = r.id OR (r.user_id = ccr.user_id AND r.estate_id = ccr.estate_id)
        LEFT JOIN flats f ON r.flat_id = f.id
        LEFT JOIN buildings b ON f.building_id = b.id
        LEFT JOIN streets s ON b.street_id = s.id
        WHERE ccr.estate_id = $zb_eid 
          AND (ccr.zone_id = $zb_zid OR s.zone_id = $zb_zid)
          AND ccr.status = 'pending'
    ");
    if ($pen_res) $zb_pending_requests_cnt = intval($pen_res->fetch_assoc()['cnt'] ?? 0);

    $rec_res = $conn->query("SELECT * FROM notifications WHERE user_id = $zb_uid AND estate_id = $zb_eid ORDER BY created_at DESC LIMIT 5");
    if ($rec_res) {
        while ($r = $rec_res->fetch_assoc()) $zb_recent_notifs[] = $r;
    }
}

// Fetch Estate Branding Details
$branding = get_estate_branding($conn);
$estate_name = $branding['estate_name'] ?? 'Main Estate';
$estate_logo_url = $branding['estate_logo_url'] ?? '';
?>
<aside class="sidebar sidebar-named">
    <div class="sidebar-header">
        <a href="index" class="sidebar-brand-ring" title="<?php echo htmlspecialchars($estate_name); ?>">
            <i class="fa-solid fa-circle-notch"></i>
        </a>
        <div class="d-flex flex-column" style="min-width: 0;">
            <h5 class="sidebar-brand-title text-truncate"><?php echo htmlspecialchars($zone_name_display); ?></h5>
            <div class="sidebar-brand-sub">
                <span style="width: 6px; height: 6px; border-radius: 50%; background: #6b4eff; display: inline-block;"></span>
                <span>Zonal Admin (<?= htmlspecialchars($zone_code_display) ?>)</span>
            </div>
        </div>
    </div>
    
    <nav class="sidebar-nav">
        <ul>
            <li class="nav-section-label">Core Operations</li>
            <li>
                <a href="index" class="<?php echo ($current_page == 'index') ? 'active' : ''; ?>" title="Zone Dashboard">
                    <i class="fa-solid fa-chart-pie"></i>
                    <span class="nav-name-tag">Zone Dashboard</span>
                </a>
            </li>
            <li>
                <a href="notifications" class="<?php echo ($current_page == 'notifications') ? 'active' : ''; ?> d-flex align-items-center" title="Action & Notifications">
                    <i class="fa-solid fa-bell"></i>
                    <span class="nav-name-tag">Action &amp; Notifications</span>
                    <?php if ($zb_unread_count > 0 || $zb_pending_requests_cnt > 0): ?>
                        <span class="badge bg-danger rounded-pill ms-auto" style="font-size: 0.65rem; padding: 2px 7px;">
                            <?php echo ($zb_unread_count + $zb_pending_requests_cnt); ?>
                        </span>
                    <?php endif; ?>
                </a>
            </li>
            
            <li class="nav-section-label">Zonal Assets &amp; Directory</li>
            <li>
                <a href="properties" class="<?php echo ($current_page == 'properties' || $current_page == 'property_details') ? 'active' : ''; ?>" title="Streets & Units">
                    <i class="fa-solid fa-road"></i>
                    <span class="nav-name-tag">Streets &amp; Units</span>
                </a>
            </li>
            <li>
                <a href="residents" class="<?php echo ($current_page == 'residents' || $current_page == 'resident_timeline') ? 'active' : ''; ?> d-flex align-items-center" title="Zone Residents">
                    <i class="fa-solid fa-users"></i>
                    <span class="nav-name-tag">Zone Residents</span>
                    <?php if ($zb_pending_requests_cnt > 0): ?>
                        <span class="badge bg-warning text-dark rounded-pill ms-auto" style="font-size: 0.65rem; padding: 2px 7px;" title="<?php echo $zb_pending_requests_cnt; ?> pending contact change request(s)">
                            <?php echo $zb_pending_requests_cnt; ?> req
                        </span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="archives" class="<?php echo ($current_page == 'archives') ? 'active' : ''; ?>" title="Zonal Archives">
                    <i class="fa-solid fa-box-archive"></i>
                    <span class="nav-name-tag">Zonal Archives</span>
                </a>
            </li>
            <li>
                <a href="policies" class="<?php echo ($current_page == 'policies') ? 'active' : ''; ?>" title="Zonal & Central Policies">
                    <i class="fa-solid fa-gavel"></i>
                    <span class="nav-name-tag">Zonal &amp; Central Policies</span>
                </a>
            </li>
            <li>
                <a href="broadcasts" class="<?php echo ($current_page == 'broadcasts') ? 'active' : ''; ?>" title="Zonal Notices & Broadcasts">
                    <i class="fa-solid fa-bullhorn"></i>
                    <span class="nav-name-tag">Zonal Notices &amp; Broadcasts</span>
                </a>
            </li>
            <li>
                <a href="community_chat" class="<?php echo ($current_page == 'community_chat') ? 'active' : ''; ?>" title="Estate Forum">
                    <i class="fa-solid fa-comments"></i>
                    <span class="nav-name-tag">Estate Forum</span>
                </a>
            </li>
            <li>
                <a href="emergency" class="<?php echo ($current_page == 'emergency') ? 'active' : ''; ?> text-danger fw-bold" title="Emergency & Panic Hub">
                    <i class="fa-solid fa-truck-medical text-danger"></i>
                    <span class="nav-name-tag text-danger">Emergency &amp; Panic Hub</span>
                </a>
            </li>
            <li>
                <a href="directory" class="<?php echo ($current_page == 'directory') ? 'active' : ''; ?>" title="Estate Directory">
                    <i class="fa-solid fa-address-book"></i>
                    <span class="nav-name-tag">Estate Directory</span>
                </a>
            </li>
            <li>
                <a href="artisans" class="<?php echo ($current_page == 'artisans') ? 'active' : ''; ?>" title="Verified Artisans">
                    <i class="fa-solid fa-wrench"></i>
                    <span class="nav-name-tag">Verified Artisans</span>
                </a>
            </li>
            <li>
                <a href="owners" class="<?php echo ($current_page == 'owners') ? 'active' : ''; ?>" title="Property Owners">
                    <i class="fa-solid fa-user-tie"></i>
                    <span class="nav-name-tag">Property Owners</span>
                </a>
            </li>
            
            <li class="nav-section-label">Local Billing &amp; Reports</li>
            <li>
                <a href="charges" class="<?php echo ($current_page == 'charges') ? 'active' : ''; ?>" title="Local Levies & Dues">
                    <i class="fa-solid fa-list-check"></i>
                    <span class="nav-name-tag">Local Levies &amp; Dues</span>
                </a>
            </li>
            <li>
                <a href="billing_config" class="<?php echo ($current_page == 'billing_config') ? 'active' : ''; ?>" title="Billing & Installments">
                    <i class="fa-solid fa-sliders"></i>
                    <span class="nav-name-tag">Billing &amp; Installments</span>
                </a>
            </li>
            <li>
                <a href="finance" class="<?php echo ($current_page == 'finance' || $current_page == 'receipt') ? 'active' : ''; ?>" title="Invoices & Billing">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                    <span class="nav-name-tag">Invoices &amp; Billing</span>
                </a>
            </li>
            <li>
                <a href="reports" class="<?php echo ($current_page == 'reports') ? 'active' : ''; ?>" title="Zonal Reports">
                    <i class="fa-solid fa-chart-simple"></i>
                    <span class="nav-name-tag">Zonal Reports</span>
                </a>
            </li>
        </ul>
    </nav>
    <div class="sidebar-footer-actions">
        <div class="sidebar-user-block">
            <div class="sidebar-user-avatar" style="background: rgba(107, 78, 255, 0.15); color: #6b4eff;">
                <?php
                $z_initials = 'ZA';
                if (!empty($_SESSION['name'])) {
                    $parts = explode(' ', trim($_SESSION['name']));
                    $z_initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                }
                echo htmlspecialchars($z_initials);
                ?>
            </div>
            <div class="sidebar-user-text">
                <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Zone Admin'); ?></div>
                <div class="sidebar-user-role"><?php echo htmlspecialchars($zone_code_display . ' Admin'); ?></div>
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
        renderMobileAppShell('zone', $current_page, $page_title ?? '');
    }
    ?>
    <header class="top-bar">
        <div class="d-flex align-items-center gap-3">
            <div class="toggle-sidebar">
                <i class="fa-solid fa-bars"></i>
            </div>
            <div class="d-none d-md-flex align-items-center gap-2 text-secondary small">
                <span class="mature-badge py-1 px-2.5 rounded-pill" style="background: rgba(107, 78, 255, 0.12); color: #6b4eff; font-weight: 600; font-size: 0.8rem; border: 1px solid rgba(107, 78, 255, 0.25);">
                    <i class="fa-solid fa-layer-group me-1 text-purple"></i> <?php echo htmlspecialchars($zone_name_display); ?>
                </span>
                <span class="text-secondary opacity-50">•</span>
                <span class="d-inline-flex align-items-center gap-1 text-success" style="font-size: 0.75rem;">
                    <i class="fa-solid fa-circle" style="font-size: 0.45rem;"></i> Zonal Isolation Active
                </span>
            </div>
        </div>

        <!-- Modern Dribbble Search Bar with ⌘ K -->
        <form action="residents" method="GET" class="desktop-topbar-search topbar-search-box m-0" onsubmit="if(!this.q.value.trim()){return false;}">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="q" placeholder="Search..." autocomplete="off">
            <span class="topbar-kbd-pill" title="Press Ctrl+K or ⌘K">⌘ K</span>
        </form>

        <div class="topbar-right-actions ms-auto">
            <!-- Messages / Community Forum Chat Icon -->
            <a href="community_chat" class="topbar-action-circle" title="Messages & Forum">
                <i class="fa-regular fa-comment-dots"></i>
            </a>

            <!-- Notification Bell with Quick Action Dropdown -->
            <div class="dropdown position-relative">
                <button type="button" class="topbar-action-circle border-0" id="zoneNotifBellBtn" data-bs-toggle="dropdown" aria-expanded="false" title="Zonal Notifications &amp; Actions">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($zb_unread_count > 0 || $zb_pending_requests_cnt > 0): ?>
                        <span class="topbar-badge-dot"></span>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4 p-0 mt-2" style="width: 330px; max-width: 90vw; z-index: 1055; overflow: hidden;">
                    <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-slate-900" style="font-size: 0.9rem;">
                            <i class="fa-solid fa-bell text-purple me-1" style="color: #6b4eff;"></i> Zonal Notifications
                        </span>
                        <a href="notifications" class="small text-decoration-none fw-semibold" style="color: #6b4eff;">Action Center &rarr;</a>
                    </div>
                    <div class="p-0" style="max-height: 290px; overflow-y: auto;">
                        <?php if ($zb_pending_requests_cnt > 0): ?>
                            <a href="residents?tab=contact_requests" class="dropdown-item p-3 border-bottom d-flex align-items-start gap-2.5" style="background: #fffcf5; white-space: normal;">
                                <div class="glass-icon-circle hero-icon-circle glass-icon-light-amber" style="width: 32px; height: 32px; min-width: 32px; min-height: 32px; font-size: 0.85rem;">
                                    <i class="fa-solid fa-id-card-clip"></i>
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <div class="fw-bold text-warning-emphasis" style="font-size: 0.82rem;">
                                        <?php echo $zb_pending_requests_cnt; ?> Pending Zonal Request(s)
                                    </div>
                                    <div class="small text-muted" style="font-size: 0.75rem;">Resident email/phone change in this zone</div>
                                    <div class="mt-1"><span class="badge bg-warning text-dark" style="font-size: 0.65rem;">Review &amp; Authorize &rarr;</span></div>
                                </div>
                            </a>
                        <?php endif; ?>

                        <?php if (!empty($zb_recent_notifs)): ?>
                            <?php foreach ($zb_recent_notifs as $rn): 
                                $is_unr = ($rn['is_read'] == 0);
                            ?>
                            <a href="notifications" class="dropdown-item p-2.5 border-bottom d-flex align-items-start gap-2" style="white-space: normal; <?php echo $is_unr ? 'background: #fdf4ff;' : ''; ?>">
                                <div style="width: 28px; height: 28px; border-radius: 6px; background: rgba(107, 78, 255, 0.1); color: #6b4eff; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.75rem; margin-top: 2px;">
                                    <i class="fa-solid <?php echo stripos($rn['title'], 'Contact') !== false ? 'fa-id-card-clip text-warning' : 'fa-bell'; ?>"></i>
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <div class="fw-semibold text-truncate <?php echo $is_unr ? 'text-dark' : 'text-secondary'; ?>" style="font-size: 0.8rem;"><?php echo htmlspecialchars($rn['title']); ?></div>
                                    <div class="small text-muted text-truncate" style="font-size: 0.73rem;"><?php echo htmlspecialchars($rn['message']); ?></div>
                                    <div class="text-muted" style="font-size: 0.68rem;"><?php echo date('M d, H:i', strtotime($rn['created_at'])); ?></div>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        <?php elseif ($zb_pending_requests_cnt === 0): ?>
                            <div class="text-center py-4 text-muted small">
                                <i class="fa-regular fa-bell-slash fs-4 d-block mb-1 opacity-50"></i>
                                No new zonal notifications
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="p-2 text-center bg-light border-top">
                        <a href="notifications" class="small text-secondary fw-semibold text-decoration-none">
                            Go to Zonal Action Center <i class="fa-solid fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Emergency Direct Hotlines Button (No Alarm) -->
            <button type="button" class="estate-hotline-btn btn btn-outline-danger btn-sm rounded-pill px-2 px-sm-3 d-inline-flex align-items-center gap-2" title="Direct Emergency Hotlines">
                <i class="fa-solid fa-phone-volume text-danger hotline-pulse-icon"></i>
                <span class="fw-semibold d-none d-sm-inline">Hotlines</span>
            </button>

            <!-- User Profile Dropdown (Welcome back [Name]) -->
            <div class="dropdown">
                <?php 
                $initials = 'ZA';
                if (!empty($_SESSION['name'])) {
                    $parts = explode(' ', trim($_SESSION['name']));
                    $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                }
                ?>
                <button type="button" class="btn p-0 border-0 topbar-user-profile-btn" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="topbar-user-initials-avatar">
                        <?php echo $initials; ?>
                    </div>
                    <div class="topbar-user-details d-none d-sm-flex">
                        <span class="topbar-user-welcome">Welcome back</span>
                        <span class="topbar-user-fullname"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Zone Admin'); ?></span>
                    </div>
                    <i class="fa-solid fa-chevron-down text-muted ms-1" style="font-size: 0.65rem;"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4 p-2 mt-2" style="min-width: 220px; z-index: 1055;">
                    <div class="px-3 py-2 border-bottom mb-1">
                        <div class="fw-bold text-dark small"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Zone Admin'); ?></div>
                        <div class="text-muted" style="font-size: 0.72rem;"><?php echo htmlspecialchars($_SESSION['email'] ?? 'zone@estate.com'); ?></div>
                    </div>
                    <a href="properties" class="dropdown-item py-2 px-3 rounded-2 small d-flex align-items-center gap-2">
                        <i class="fa-solid fa-road text-secondary"></i> Zonal Properties
                    </a>
                    <a href="reports" class="dropdown-item py-2 px-3 rounded-2 small d-flex align-items-center gap-2">
                        <i class="fa-solid fa-chart-pie text-primary"></i> Zonal Analytics
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
                    <div class="estate-header-fallback" style="background: linear-gradient(135deg, #6b4eff 0%, #5a3fe6 100%);">
                        <i class="fa-solid fa-tree-city"></i>
                    </div>
                <?php endif; ?>
                <div class="estate-header-info">
                    <span class="estate-header-tag" style="color: #6b4eff;">Estate</span>
                    <span class="estate-header-name"><?php echo htmlspecialchars($estate_name); ?></span>
                </div>
            </div>
        </div>
    </header>
    <div class="content-wrapper">
