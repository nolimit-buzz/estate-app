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
<aside class="sidebar">
    <div class="sidebar-header">
        <div class="logo d-flex align-items-center gap-2">
            <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(168, 85, 247, 0.15); color: #9333ea; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; border: 1px solid rgba(168, 85, 247, 0.3);">
                <i class="fa-solid fa-layer-group"></i>
            </div>
            <div>
                <span style="font-size: 0.95rem; font-weight: 700; display: block; line-height: 1.2; letter-spacing: -0.01em; color: #0f172a;"><?php echo htmlspecialchars($zone_name_display); ?></span>
                <span class="badge bg-purple-100 text-purple-700" style="font-size: 0.65rem; background: rgba(168, 85, 247, 0.12); color: #7e22ce;"><?php echo htmlspecialchars($zone_code_display); ?></span>
            </div>
        </div>
    </div>
    
    <nav class="sidebar-nav">
        <ul>
            <li class="nav-label">Core Operations</li>
            <li><a href="index" class="<?php echo ($current_page == 'index') ? 'active' : ''; ?>"><i class="fa-solid fa-chart-pie"></i> Zone Dashboard</a></li>
            <li>
                <a href="notifications" class="<?php echo ($current_page == 'notifications') ? 'active' : ''; ?> d-flex align-items-center">
                    <i class="fa-solid fa-bell"></i> Action &amp; Notifications
                    <?php if ($zb_unread_count > 0 || $zb_pending_requests_cnt > 0): ?>
                        <span class="badge bg-danger rounded-pill ms-auto" style="font-size: 0.65rem; padding: 2px 7px;">
                            <?php echo ($zb_unread_count + $zb_pending_requests_cnt); ?>
                        </span>
                    <?php endif; ?>
                </a>
            </li>
            
            <li class="nav-label">Zonal Assets & Directory</li>
            <li><a href="properties" class="<?php echo ($current_page == 'properties' || $current_page == 'property_details') ? 'active' : ''; ?>"><i class="fa-solid fa-road"></i> Streets & Units</a></li>
            <li>
                <a href="residents" class="<?php echo ($current_page == 'residents' || $current_page == 'resident_timeline') ? 'active' : ''; ?> d-flex align-items-center">
                    <i class="fa-solid fa-users"></i> Zone Residents
                    <?php if ($zb_pending_requests_cnt > 0): ?>
                        <span class="badge bg-warning text-dark rounded-pill ms-auto" style="font-size: 0.65rem; padding: 2px 7px;" title="<?php echo $zb_pending_requests_cnt; ?> pending contact change request(s)">
                            <?php echo $zb_pending_requests_cnt; ?> req
                        </span>
                    <?php endif; ?>
                </a>
            </li>
            <li><a href="archives" class="<?php echo ($current_page == 'archives') ? 'active' : ''; ?>"><i class="fa-solid fa-box-archive"></i> Zonal Archives</a></li>
            <li><a href="policies" class="<?php echo ($current_page == 'policies') ? 'active' : ''; ?>"><i class="fa-solid fa-gavel"></i> Zonal &amp; Central Policies</a></li>
            <li><a href="broadcasts" class="<?php echo ($current_page == 'broadcasts') ? 'active' : ''; ?>"><i class="fa-solid fa-bullhorn"></i> Zonal Notices &amp; Broadcasts</a></li>
            <li><a href="community_chat" class="<?php echo ($current_page == 'community_chat') ? 'active' : ''; ?>"><i class="fa-solid fa-comments"></i> Estate Forum</a></li>
            <li><a href="emergency" class="<?php echo ($current_page == 'emergency') ? 'active' : ''; ?> text-danger fw-bold"><i class="fa-solid fa-truck-medical text-danger"></i> Emergency &amp; Panic Hub</a></li>
            <li><a href="directory" class="<?php echo ($current_page == 'directory') ? 'active' : ''; ?>"><i class="fa-solid fa-address-book"></i> Estate Directory</a></li>
            <li><a href="artisans" class="<?php echo ($current_page == 'artisans') ? 'active' : ''; ?>"><i class="fa-solid fa-wrench"></i> Verified Artisans</a></li>
            <li><a href="owners" class="<?php echo ($current_page == 'owners') ? 'active' : ''; ?>"><i class="fa-solid fa-user-tie"></i> Property Owners</a></li>
            
            <li class="nav-label">Local Billing & Reports</li>
            <li><a href="charges" class="<?php echo ($current_page == 'charges') ? 'active' : ''; ?>"><i class="fa-solid fa-list-check"></i> Local Levies & Dues</a></li>
            <li><a href="billing_config" class="<?php echo ($current_page == 'billing_config') ? 'active' : ''; ?>"><i class="fa-solid fa-sliders"></i> Billing & Installments</a></li>
            <li><a href="finance" class="<?php echo ($current_page == 'finance' || $current_page == 'receipt') ? 'active' : ''; ?>"><i class="fa-solid fa-file-invoice-dollar"></i> Invoices & Billing</a></li>
            <li><a href="reports" class="<?php echo ($current_page == 'reports') ? 'active' : ''; ?>"><i class="fa-solid fa-chart-simple"></i> Zonal Reports</a></li>
            
            <li class="nav-label">Session</li>
            <li><a href="../logout"><i class="fa-solid fa-right-from-bracket text-danger"></i> Logout</a></li>
        </ul>
    </nav>
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
                <span class="mature-badge py-1 px-2.5 rounded-pill" style="background: rgba(168, 85, 247, 0.12); color: #7e22ce; font-weight: 600; font-size: 0.8rem; border: 1px solid rgba(168, 85, 247, 0.25);">
                    <i class="fa-solid fa-layer-group me-1 text-purple"></i> <?php echo htmlspecialchars($zone_name_display); ?>
                </span>
                <span class="text-secondary opacity-50">•</span>
                <span class="d-inline-flex align-items-center gap-1 text-success" style="font-size: 0.75rem;">
                    <i class="fa-solid fa-circle" style="font-size: 0.45rem;"></i> Zonal Isolation Active
                </span>
            </div>
        </div>

        <!-- Desktop Quick Search (Image 3 Style) -->
        <form action="residents" method="GET" class="desktop-topbar-search m-0" onsubmit="if(!this.q.value.trim()){return false;}">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="q" placeholder="Search zonal residents, units, streets..." autocomplete="off">
        </form>

        <div class="d-flex align-items-center gap-2 gap-sm-3">
            <!-- Notification Bell with Quick Action Dropdown -->
            <div class="dropdown position-relative">
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-circle position-relative d-inline-flex align-items-center justify-content-center" id="zoneNotifBellBtn" data-bs-toggle="dropdown" aria-expanded="false" style="width: 36px; height: 36px; min-width: 36px; min-height: 36px; aspect-ratio: 1 / 1 !important; flex-shrink: 0 !important; padding: 0;" title="Zonal Notifications &amp; Actions">
                    <i class="fa-solid fa-bell text-secondary"></i>
                    <?php if ($zb_unread_count > 0 || $zb_pending_requests_cnt > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger border border-white" style="font-size: 0.65rem; padding: 0.25em 0.5em;">
                            <?php echo ($zb_unread_count + $zb_pending_requests_cnt); ?>
                        </span>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4 p-0 mt-2" style="width: 330px; max-width: 90vw; z-index: 1055; overflow: hidden;">
                    <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-slate-900" style="font-size: 0.9rem;">
                            <i class="fa-solid fa-bell text-purple me-1" style="color: #7e22ce;"></i> Zonal Notifications
                        </span>
                        <a href="notifications" class="small text-decoration-none fw-semibold" style="color: #7e22ce;">Action Center &rarr;</a>
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
                                    <div style="width: 28px; height: 28px; border-radius: 6px; background: rgba(168, 85, 247, 0.1); color: #7e22ce; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.75rem; margin-top: 2px;">
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
            <button type="button" class="estate-hotline-btn btn btn-outline-danger btn-sm rounded-pill px-2 px-sm-3 d-inline-flex align-items-center gap-2" title="Direct Emergency Hotlines (Call directly without triggering alarm)">
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
                        <div class="user-name fw-bold" style="font-size: 0.84rem; line-height: 1.2;"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Zone Admin'); ?></div>
                        <small class="text-secondary text-uppercase" style="font-size: 0.68rem; letter-spacing: 0.04em;">Zone Administrator</small>
                    </div>
                    <?php 
                    $initials = 'ZA';
                    if (!empty($_SESSION['name'])) {
                        $parts = explode(' ', trim($_SESSION['name']));
                        $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                    }
                    ?>
                    <div class="user-avatar" style="width: 36px; height: 36px; border-radius: 8px; background: #581c87; color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; border: 1px solid rgba(255,255,255,0.15);">
                        <?php echo $initials; ?>
                    </div>
                    <i class="fa-solid fa-chevron-down text-muted" style="font-size: 0.65rem;"></i>
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
                    <div class="estate-header-fallback" style="background: linear-gradient(135deg, #7e22ce 0%, #6b21a8 100%);">
                        <i class="fa-solid fa-tree-city"></i>
                    </div>
                <?php endif; ?>
                <div class="estate-header-info">
                    <span class="estate-header-tag" style="color: #a855f7;">Estate</span>
                    <span class="estate-header-name"><?php echo htmlspecialchars($estate_name); ?></span>
                </div>
            </div>
        </div>
    </header>
    <div class="content-wrapper">
