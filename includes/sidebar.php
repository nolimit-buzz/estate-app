<!-- includes/sidebar.php -->
<?php
// Fetch Estate Branding & Company Details
$branding = get_estate_branding($conn);
$estate_name = $branding['estate_name'] ?? 'Main Estate';
$estate_logo_url = $branding['estate_logo_url'] ?? '';
$app_company_name = $branding['app_company_name'] ?? 'NoLimitBuzz';
$app_company_logo_url = $branding['app_company_logo_url'] ?? '';

$current_page = str_replace('.php', '', basename($_SERVER['PHP_SELF']));

// Fetch unread notification counts and pending requests for Administrator
$sb_uid = intval($_SESSION['user_id'] ?? 0);
$sb_eid = function_exists('get_estate_id') ? get_estate_id() : 1;
$sb_unread_count = 0;
$sb_pending_requests_cnt = 0;
$sb_pending_artisans_cnt = 0;
$sb_recent_notifs = [];
if ($sb_uid > 0 && isset($conn)) {
    $unr_res = $conn->query("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = $sb_uid AND estate_id = $sb_eid AND is_read = 0 AND type NOT IN ('estate_broadcast', 'zone_notice')");
    if ($unr_res) $sb_unread_count = intval($unr_res->fetch_assoc()['cnt'] ?? 0);

    $pen_res = $conn->query("SELECT COUNT(*) as cnt FROM contact_change_requests WHERE estate_id = $sb_eid AND status = 'pending'");
    if ($pen_res) $sb_pending_requests_cnt = intval($pen_res->fetch_assoc()['cnt'] ?? 0);

    $art_res = $conn->query("SELECT COUNT(*) as cnt FROM artisans WHERE estate_id = $sb_eid AND verification_status = 'pending'");
    if ($art_res) $sb_pending_artisans_cnt = intval($art_res->fetch_assoc()['cnt'] ?? 0);

    $rec_res = $conn->query("SELECT * FROM notifications WHERE user_id = $sb_uid AND estate_id = $sb_eid AND type NOT IN ('estate_broadcast', 'zone_notice') ORDER BY created_at DESC LIMIT 5");
    if ($rec_res) {
        while ($r = $rec_res->fetch_assoc()) $sb_recent_notifs[] = $r;
    }
}
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <div class="logo d-flex align-items-center">
            <?php if(!empty($app_company_logo_url)): ?>
                <img src="<?php echo htmlspecialchars($app_company_logo_url); ?>" alt="<?php echo htmlspecialchars($app_company_name); ?>" style="height: 32px; border-radius: 6px; object-fit: contain;">
                <div class="d-flex flex-column ms-2 text-start">
                    <span style="font-size: 1.02rem; font-weight: 800; letter-spacing: -0.01em; line-height: 1.2; color: inherit;"><?php echo htmlspecialchars($app_company_name); ?></span>
                    <span style="font-size: 0.65rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Estate Platform</span>
                </div>
            <?php else: ?>
                <div style="width: 34px; height: 34px; border-radius: 8px; background: rgba(59, 130, 246, 0.12); color: var(--primary-color, #2563eb); display: flex; align-items: center; justify-content: center; font-size: 1rem;">
                    <i class="fa-solid fa-building-user"></i>
                </div>
                <div class="d-flex flex-column ms-2 text-start">
                    <span style="font-size: 1.02rem; font-weight: 800; letter-spacing: -0.01em; line-height: 1.2; color: inherit;"><?php echo htmlspecialchars($app_company_name); ?></span>
                    <span style="font-size: 0.65rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Estate Platform</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <nav class="sidebar-nav">
        <ul>
            <li class="nav-label">Core Operations</li>
            <li><a href="../admin/index" class="<?php echo ($current_page == 'index') ? 'active' : ''; ?>"><i class="fa-solid fa-chart-pie"></i> Dashboard</a></li>
            <li>
                <a href="../admin/notifications" class="<?php echo ($current_page == 'notifications') ? 'active' : ''; ?> d-flex align-items-center">
                    <i class="fa-solid fa-bell"></i> Action &amp; Notifications
                    <?php if ($sb_unread_count > 0 || $sb_pending_requests_cnt > 0): ?>
                        <span class="badge bg-danger rounded-pill ms-auto" style="font-size: 0.65rem; padding: 2px 7px;">
                            <?php echo ($sb_unread_count + $sb_pending_requests_cnt); ?>
                        </span>
                    <?php endif; ?>
                </a>
            </li>
            
            <li class="nav-label">Real Estate & Assets</li>
            <?php if (isModuleEnabled('zonal_divisions')): ?>
                <li><a href="../admin/zones" class="<?php echo ($current_page == 'zones') ? 'active' : ''; ?>"><i class="fa-solid fa-layer-group"></i> Zones & Sectors</a></li>
            <?php endif; ?>
            <li><a href="../admin/properties" class="<?php echo ($current_page == 'properties' || $current_page == 'property_details') ? 'active' : ''; ?>"><i class="fa-solid fa-city"></i> Properties & Units</a></li>
            <li><a href="../admin/owners" class="<?php echo ($current_page == 'owners') ? 'active' : ''; ?>"><i class="fa-solid fa-id-card-clip"></i> Property Owners</a></li>
            <li>
                <a href="../admin/residents" class="<?php echo ($current_page == 'residents' || $current_page == 'resident_timeline') ? 'active' : ''; ?> d-flex align-items-center">
                    <i class="fa-solid fa-users"></i> Residents Registry
                    <?php if ($sb_pending_requests_cnt > 0): ?>
                        <span class="badge bg-warning text-dark rounded-pill ms-auto" style="font-size: 0.65rem; padding: 2px 7px;" title="<?php echo $sb_pending_requests_cnt; ?> pending contact change request(s)">
                            <?php echo $sb_pending_requests_cnt; ?> req
                        </span>
                    <?php endif; ?>
                </a>
            </li>
            <li><a href="../admin/directory" class="<?php echo ($current_page == 'directory') ? 'active' : ''; ?>"><i class="fa-solid fa-address-book"></i> Member Directory</a></li>
            <?php if (isModuleEnabled('broadcast_messaging')): ?>
                <li><a href="../admin/broadcasts" class="<?php echo ($current_page == 'broadcasts') ? 'active' : ''; ?>"><i class="fa-solid fa-bullhorn"></i> Broadcasts &amp; Notices</a></li>
            <?php endif; ?>
            <li><a href="../admin/community_chat" class="<?php echo ($current_page == 'community_chat') ? 'active' : ''; ?>"><i class="fa-solid fa-comments"></i> Estate Forum</a></li>
            <li><a href="../admin/staff" class="<?php echo ($current_page == 'staff') ? 'active' : ''; ?>"><i class="fa-solid fa-user-gear"></i> Estate Staff</a></li>
            <li><a href="../admin/archives" class="<?php echo ($current_page == 'archives') ? 'active' : ''; ?>"><i class="fa-solid fa-box-archive"></i> Archives Vault</a></li>
            <?php if (isModuleEnabled('bylaws_policies')): ?>
                <li><a href="../admin/policies" class="<?php echo ($current_page == 'policies') ? 'active' : ''; ?>"><i class="fa-solid fa-gavel"></i> Policies &amp; Bylaws</a></li>
            <?php endif; ?>
            
            <?php if (isModuleEnabled('billing_invoicing')): ?>
                <li class="nav-label">Finance & Facilities</li>
                <li><a href="../admin/finance" class="<?php echo ($current_page == 'finance' || $current_page == 'receipt') ? 'active' : ''; ?>"><i class="fa-solid fa-wallet"></i> Finance Hub</a></li>
                <li><a href="../admin/charges" class="<?php echo ($current_page == 'charges') ? 'active' : ''; ?>"><i class="fa-solid fa-tags"></i> Charge Catalog</a></li>
                <li><a href="../admin/billing_config" class="<?php echo ($current_page == 'billing_config') ? 'active' : ''; ?>"><i class="fa-solid fa-sliders"></i> Billing & Installments</a></li>
            <?php endif; ?>

            <li><a href="../admin/maintenance" class="<?php echo ($current_page == 'maintenance') ? 'active' : ''; ?>"><i class="fa-solid fa-screwdriver-wrench"></i> Maintenance Dispatch</a></li>
            <?php if (isModuleEnabled('artisan_marketplace')): ?>
                <li>
                    <a href="../admin/artisans" class="<?php echo ($current_page == 'artisans') ? 'active' : ''; ?> d-flex align-items-center">
                        <i class="fa-solid fa-user-check"></i> Artisan Registry
                        <?php if ($sb_pending_artisans_cnt > 0): ?>
                            <span class="badge bg-warning text-dark rounded-pill ms-auto" style="font-size: 0.65rem; padding: 2px 7px;" title="<?php echo $sb_pending_artisans_cnt; ?> pending artisan verification(s)">
                                <?php echo $sb_pending_artisans_cnt; ?> new
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endif; ?>
            
            <li class="nav-label">Portals</li>
            <?php if (isModuleEnabled('zonal_divisions')): ?>
                <li><a href="../zone/index" target="_blank"><i class="fa-solid fa-network-wired"></i> Zone Portal <i class="fa-solid fa-arrow-up-right-from-square ms-auto small opacity-50"></i></a></li>
            <?php endif; ?>
            <li><a href="../resident/index" target="_blank"><i class="fa-solid fa-house-chimney-user"></i> Resident Portal <i class="fa-solid fa-arrow-up-right-from-square ms-auto small opacity-50"></i></a></li>

            <li class="nav-label">Security & System</li>
            <?php if (isModuleEnabled('emergency_sos')): ?>
                <li><a href="../admin/emergency" class="<?php echo ($current_page == 'emergency') ? 'active' : ''; ?> text-danger fw-bold"><i class="fa-solid fa-truck-medical text-danger"></i> Emergency &amp; Panic Hub</a></li>
            <?php endif; ?>
            <li><a href="../admin/incidents" class="<?php echo ($current_page == 'incidents') ? 'active' : ''; ?>"><i class="fa-solid fa-book-skull text-danger"></i> Incident &amp; Occurrence Book</a></li>
            <?php if (isModuleEnabled('security_patrol')): ?>
                <li><a href="../admin/roster" class="<?php echo ($current_page == 'roster') ? 'active' : ''; ?>"><i class="fa-solid fa-calendar-check"></i> Security Duty Roster</a></li>
            <?php endif; ?>
            <?php if (isModuleEnabled('visitor_passes')): ?>
                <li><a href="../admin/security" class="<?php echo ($current_page == 'security') ? 'active' : ''; ?>"><i class="fa-solid fa-shield-halved"></i> Gate Visitor Passes</a></li>
            <?php endif; ?>
            <?php if (isModuleEnabled('ussd_offline_sync')): ?>
                <li><a href="../admin/africastalking" class="<?php echo ($current_page == 'africastalking') ? 'active' : ''; ?>"><i class="fa-solid fa-signal text-primary"></i> USSD &amp; Offline Sync</a></li>
            <?php endif; ?>
            <li><a href="../admin/email_logs" class="<?php echo ($current_page == 'email_logs') ? 'active' : ''; ?>"><i class="fa-solid fa-envelope-open-text"></i> Email Logs</a></li>
            <li><a href="../admin/whatsapp_logs" class="<?php echo ($current_page == 'whatsapp_logs') ? 'active' : ''; ?>"><i class="fa-brands fa-whatsapp text-success"></i> WhatsApp Logs</a></li>
            <li><a href="../admin/users" class="<?php echo ($current_page == 'users') ? 'active' : ''; ?>"><i class="fa-solid fa-user-shield"></i> User Accounts &amp; Security</a></li>
            <li><a href="../admin/audit_logs" class="<?php echo ($current_page == 'audit_logs') ? 'active' : ''; ?>"><i class="fa-solid fa-clock-rotate-left"></i> System Audit</a></li>
            
            <li class="nav-label">Settings &amp; White-Label</li>
            <li><a href="../admin/branding" class="<?php echo ($current_page == 'branding') ? 'active' : ''; ?>"><i class="fa-solid fa-paintbrush text-info"></i> White-Label &amp; Branding</a></li>
            <li><a href="../admin/settings" class="<?php echo ($current_page == 'settings') ? 'active' : ''; ?>"><i class="fa-solid fa-sliders"></i> Settings</a></li>
            
            <?php if (($_SESSION['role'] ?? '') === 'superadmin' || is_impersonating_estate()): ?>
                <li class="nav-label text-warning">Super Admin HQ</li>
                <li><a href="../superadmin/index" style="color: #f59e0b; font-weight: 700;"><i class="fa-solid fa-layer-group text-warning"></i> SaaS Command Center</a></li>
            <?php endif; ?>

            <li><a href="../logout"><i class="fa-solid fa-arrow-right-from-bracket text-danger"></i> Logout</a></li>
        </ul>
    </nav>
</aside>
<main class="main-content">
    <?php 
    if (function_exists('renderMobileAppShell')) {
        renderMobileAppShell('admin', $current_page, $page_title ?? '');
    }
    ?>
    <header class="top-bar">
        <div class="d-flex align-items-center gap-3">
            <div class="toggle-sidebar">
                <i class="fa-solid fa-bars"></i>
            </div>
            <div class="d-none d-xl-flex align-items-center gap-2 text-secondary small">
                <span class="mature-badge mature-badge-slate py-1 px-2">
                    <i class="fa-solid fa-shield-halved text-primary me-1"></i> Central Admin
                </span>
                <span class="text-secondary opacity-50">•</span>
                <span class="d-inline-flex align-items-center gap-1 text-success" style="font-size: 0.75rem;">
                    <i class="fa-solid fa-circle" style="font-size: 0.45rem;"></i> System Live
                </span>
            </div>
        </div>

        <!-- Desktop Quick Search (Image 3 Style) -->
        <form action="../admin/residents" method="GET" class="desktop-topbar-search m-0" onsubmit="if(!this.q.value.trim()){return false;}">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="q" placeholder="Search anything (residents, passes, units, artisans)..." autocomplete="off">
        </form>

        <div class="d-flex align-items-center gap-2 gap-sm-3">
            <!-- Notification Bell with Quick Action Dropdown -->
            <div class="dropdown position-relative">
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-circle position-relative d-inline-flex align-items-center justify-content-center" id="topNotifBellBtn" data-bs-toggle="dropdown" aria-expanded="false" style="width: 36px; height: 36px; min-width: 36px; min-height: 36px; aspect-ratio: 1 / 1 !important; flex-shrink: 0 !important; padding: 0;" title="Notifications &amp; Pending Requests">
                    <i class="fa-solid fa-bell text-secondary"></i>
                    <?php if ($sb_unread_count > 0 || $sb_pending_requests_cnt > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger border border-white" style="font-size: 0.65rem; padding: 0.25em 0.5em;">
                            <?php echo ($sb_unread_count + $sb_pending_requests_cnt); ?>
                        </span>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4 p-0 mt-2" style="width: 330px; max-width: 90vw; z-index: 1055; overflow: hidden;">
                    <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-slate-900" style="font-size: 0.9rem;">
                            <i class="fa-solid fa-bell text-primary me-1"></i> Notifications &amp; Actions
                        </span>
                        <a href="../admin/notifications" class="small text-primary text-decoration-none fw-semibold">Action Center &rarr;</a>
                    </div>
                    <div class="p-0" style="max-height: 290px; overflow-y: auto;">
                        <?php if ($sb_pending_requests_cnt > 0): ?>
                            <a href="../admin/residents?tab=contact_requests" class="dropdown-item p-3 border-bottom d-flex align-items-start gap-2.5" style="background: #fffcf5; white-space: normal;">
                                <div class="glass-icon-circle hero-icon-circle glass-icon-light-amber" style="width: 32px; height: 32px; min-width: 32px; min-height: 32px; font-size: 0.85rem;">
                                    <i class="fa-solid fa-id-card-clip"></i>
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <div class="fw-bold text-warning-emphasis" style="font-size: 0.82rem;">
                                        <?php echo $sb_pending_requests_cnt; ?> Pending Contact Change(s)
                                    </div>
                                    <div class="small text-muted" style="font-size: 0.75rem;">Resident email/phone change waiting for review</div>
                                    <div class="mt-1"><span class="badge bg-warning text-dark" style="font-size: 0.65rem;">Review in Registry &rarr;</span></div>
                                </div>
                            </a>
                        <?php endif; ?>

                        <?php if (!empty($sb_recent_notifs)): ?>
                            <?php foreach ($sb_recent_notifs as $rn): 
                                $is_unr = ($rn['is_read'] == 0);
                            ?>
                                <a href="../admin/notifications" class="dropdown-item p-2.5 border-bottom d-flex align-items-start gap-2" style="white-space: normal; <?php echo $is_unr ? 'background: #f8fafc;' : ''; ?>">
                                    <div class="glass-icon-circle hero-icon-circle glass-icon-light-blue" style="width: 30px; height: 30px; min-width: 30px; min-height: 30px; font-size: 0.75rem; margin-top: 2px;">
                                        <i class="fa-solid <?php echo stripos($rn['title'], 'Contact') !== false ? 'fa-id-card-clip text-warning' : 'fa-bell'; ?>"></i>
                                    </div>
                                    <div style="flex: 1; min-width: 0;">
                                        <div class="fw-semibold text-truncate <?php echo $is_unr ? 'text-dark' : 'text-secondary'; ?>" style="font-size: 0.8rem;"><?php echo htmlspecialchars($rn['title']); ?></div>
                                        <div class="small text-muted text-truncate" style="font-size: 0.73rem;"><?php echo htmlspecialchars($rn['message']); ?></div>
                                        <div class="text-muted" style="font-size: 0.68rem;"><?php echo date('M d, H:i', strtotime($rn['created_at'])); ?></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php elseif ($sb_pending_requests_cnt === 0): ?>
                            <div class="text-center py-4 text-muted small">
                                <i class="fa-regular fa-bell-slash fs-4 d-block mb-1 opacity-50"></i>
                                No new notifications
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="p-2 text-center bg-light border-top">
                        <a href="../admin/notifications" class="small text-secondary fw-semibold text-decoration-none">
                            Go to Full Notifications Hub <i class="fa-solid fa-arrow-right ms-1"></i>
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
                        <div class="user-name fw-bold" style="font-size: 0.84rem; line-height: 1.2;"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin User'); ?></div>
                        <small class="text-secondary text-uppercase" style="font-size: 0.68rem; letter-spacing: 0.04em;"><?php echo htmlspecialchars($_SESSION['role'] ?? 'Administrator'); ?></small>
                    </div>
                    <?php 
                    $initials = 'AD';
                    if (!empty($_SESSION['name'])) {
                        $parts = explode(' ', trim($_SESSION['name']));
                        $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                    }
                    ?>
                    <div class="user-avatar" style="width: 36px; height: 36px; border-radius: 8px; background: #0f172a; color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; border: 1px solid rgba(255,255,255,0.12);">
                        <?php echo $initials; ?>
                    </div>
                    <i class="fa-solid fa-chevron-down text-muted" style="font-size: 0.65rem;"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4 p-2 mt-2" style="min-width: 220px; z-index: 1055;">
                    <div class="px-3 py-2 border-bottom mb-1">
                        <div class="fw-bold text-dark small"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin User'); ?></div>
                        <div class="text-muted" style="font-size: 0.72rem;"><?php echo htmlspecialchars($_SESSION['email'] ?? 'admin@estate.com'); ?></div>
                    </div>
                    <a href="../admin/settings" class="dropdown-item py-2 px-3 rounded-2 small d-flex align-items-center gap-2">
                        <i class="fa-solid fa-sliders text-secondary"></i> System Settings
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
            <a href="../admin/settings" class="estate-header-brand" title="Estate: <?php echo htmlspecialchars($estate_name); ?> (Click to manage in Settings)">
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
            </a>
        </div>
    </header>
    <div class="content-wrapper">
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Submenu toggle logic
    const submenuToggles = document.querySelectorAll('.submenu-toggle');
    submenuToggles.forEach(toggle => {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            const parent = this.closest('.has-dropdown');
            parent.classList.toggle('active');
            
            // Handle display style for smooth transition if needed, 
            // but CSS display: block/none on .active is usually enough.
            const submenu = parent.querySelector('.sidebar-submenu');
            if (parent.classList.contains('active')) {
                submenu.style.display = 'block';
            } else {
                submenu.style.display = 'none';
            }
        });
    });
});
</script>
