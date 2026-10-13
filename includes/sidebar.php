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
<aside class="sidebar sidebar-named">
    <div class="sidebar-header">
        <a href="../admin/index" class="sidebar-brand-ring" title="<?php echo htmlspecialchars($estate_name); ?>">
            <i class="fa-solid fa-circle-notch"></i>
        </a>
        <div class="d-flex flex-column" style="min-width: 0;">
            <h5 class="sidebar-brand-title text-truncate"><?php echo htmlspecialchars($estate_name); ?></h5>
            <div class="sidebar-brand-sub">
                <span style="width: 6px; height: 6px; border-radius: 50%; background: #10b981; display: inline-block;"></span>
                <span>Central Admin</span>
            </div>
        </div>
    </div>
    
    <nav class="sidebar-nav">
        <ul>
            <?php if (is_impersonating_estate()): ?>
                <li class="nav-section-label text-warning" style="color: #f59e0b !important;">SaaS Governance</li>
                <li>
                    <a href="../superadmin/index" class="text-warning fw-bold d-flex align-items-center" style="background: rgba(245, 158, 11, 0.08); border-left: 3px solid #f59e0b; border-radius: 8px; margin-bottom: 8px;" title="Super Admin Console">
                        <i class="fa-solid fa-bolt text-warning"></i>
                        <span class="nav-name-tag">Super Admin Console</span>
                        <span class="badge bg-warning text-dark ms-auto" style="font-size: 0.65rem;">SAAS</span>
                    </a>
                </li>
            <?php endif; ?>

            <li class="nav-section-label">Core Operations</li>
            <li>
                <a href="../admin/index" class="<?php echo ($current_page == 'index') ? 'active' : ''; ?>" title="Dashboard">
                    <i class="fa-solid fa-chart-pie"></i>
                    <span class="nav-name-tag">Dashboard</span>
                </a>
            </li>
            <li>
                <a href="../admin/notifications" class="<?php echo ($current_page == 'notifications') ? 'active' : ''; ?> d-flex align-items-center" title="Action & Notifications">
                    <i class="fa-solid fa-bell"></i>
                    <span class="nav-name-tag">Action &amp; Notifications</span>
                    <?php if ($sb_unread_count > 0 || $sb_pending_requests_cnt > 0): ?>
                        <span class="badge bg-danger rounded-pill ms-auto">
                            <?php echo ($sb_unread_count + $sb_pending_requests_cnt); ?>
                        </span>
                    <?php endif; ?>
                </a>
            </li>
            
            <li class="nav-section-label">Real Estate &amp; Assets</li>
            <?php if (isModuleEnabled('zonal_divisions')): ?>
                <li>
                    <a href="../admin/zones" class="<?php echo ($current_page == 'zones') ? 'active' : ''; ?>" title="Zones & Sectors">
                        <i class="fa-solid fa-layer-group"></i>
                        <span class="nav-name-tag">Zones &amp; Sectors</span>
                    </a>
                </li>
            <?php endif; ?>
            <li>
                <a href="../admin/properties" class="<?php echo ($current_page == 'properties' || $current_page == 'property_details') ? 'active' : ''; ?>" title="Properties & Units">
                    <i class="fa-solid fa-city"></i>
                    <span class="nav-name-tag">Properties &amp; Units</span>
                </a>
            </li>
            <li>
                <a href="../admin/owners" class="<?php echo ($current_page == 'owners') ? 'active' : ''; ?>" title="Property Owners">
                    <i class="fa-solid fa-id-card-clip"></i>
                    <span class="nav-name-tag">Property Owners</span>
                </a>
            </li>
            <li>
                <a href="../admin/residents" class="<?php echo ($current_page == 'residents' || $current_page == 'resident_timeline') ? 'active' : ''; ?> d-flex align-items-center" title="Residents Registry">
                    <i class="fa-solid fa-users"></i>
                    <span class="nav-name-tag">Residents Registry</span>
                    <?php if ($sb_pending_requests_cnt > 0): ?>
                        <span class="badge bg-warning text-dark rounded-pill ms-auto" title="<?php echo $sb_pending_requests_cnt; ?> pending contact change request(s)">
                            <?php echo $sb_pending_requests_cnt; ?> req
                        </span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="../admin/directory" class="<?php echo ($current_page == 'directory') ? 'active' : ''; ?>" title="Member Directory">
                    <i class="fa-solid fa-address-book"></i>
                    <span class="nav-name-tag">Member Directory</span>
                </a>
            </li>
            <?php if (isModuleEnabled('broadcast_messaging')): ?>
                <li>
                    <a href="../admin/broadcasts" class="<?php echo ($current_page == 'broadcasts') ? 'active' : ''; ?>" title="Broadcasts & Notices">
                        <i class="fa-solid fa-bullhorn"></i>
                        <span class="nav-name-tag">Broadcasts &amp; Notices</span>
                    </a>
                </li>
            <?php endif; ?>
            <li>
                <a href="../admin/community_chat" class="<?php echo ($current_page == 'community_chat') ? 'active' : ''; ?>" title="Estate Forum">
                    <i class="fa-solid fa-comments"></i>
                    <span class="nav-name-tag">Estate Forum</span>
                </a>
            </li>
            <li>
                <a href="../admin/staff" class="<?php echo ($current_page == 'staff') ? 'active' : ''; ?>" title="Estate Staff">
                    <i class="fa-solid fa-user-gear"></i>
                    <span class="nav-name-tag">Estate Staff</span>
                </a>
            </li>
            <li>
                <a href="../admin/archives" class="<?php echo ($current_page == 'archives') ? 'active' : ''; ?>" title="Archives Vault">
                    <i class="fa-solid fa-box-archive"></i>
                    <span class="nav-name-tag">Archives Vault</span>
                </a>
            </li>
            <?php if (isModuleEnabled('bylaws_policies')): ?>
                <li>
                    <a href="../admin/policies" class="<?php echo ($current_page == 'policies') ? 'active' : ''; ?>" title="Policies & Bylaws">
                        <i class="fa-solid fa-gavel"></i>
                        <span class="nav-name-tag">Policies &amp; Bylaws</span>
                    </a>
                </li>
            <?php endif; ?>
            
            <?php if (isModuleEnabled('billing_invoicing')): ?>
                <li class="nav-section-label">Finance &amp; Facilities</li>
                <li>
                    <a href="../admin/finance" class="<?php echo ($current_page == 'finance' || $current_page == 'receipt') ? 'active' : ''; ?>" title="Finance Hub">
                        <i class="fa-solid fa-wallet"></i>
                        <span class="nav-name-tag">Finance Hub</span>
                    </a>
                </li>
                <li>
                    <a href="../admin/charges" class="<?php echo ($current_page == 'charges') ? 'active' : ''; ?>" title="Charge Catalog">
                        <i class="fa-solid fa-tags"></i>
                        <span class="nav-name-tag">Charge Catalog</span>
                    </a>
                </li>
                <li>
                    <a href="../admin/billing_config" class="<?php echo ($current_page == 'billing_config') ? 'active' : ''; ?>" title="Billing & Installments">
                        <i class="fa-solid fa-sliders"></i>
                        <span class="nav-name-tag">Billing &amp; Installments</span>
                    </a>
                </li>
            <?php endif; ?>

            <li>
                <a href="../admin/maintenance" class="<?php echo ($current_page == 'maintenance') ? 'active' : ''; ?>" title="Maintenance Dispatch">
                    <i class="fa-solid fa-screwdriver-wrench"></i>
                    <span class="nav-name-tag">Maintenance Dispatch</span>
                </a>
            </li>
            <?php if (isModuleEnabled('artisan_marketplace')): ?>
                <li>
                    <a href="../admin/artisans" class="<?php echo ($current_page == 'artisans') ? 'active' : ''; ?> d-flex align-items-center" title="Artisan Registry">
                        <i class="fa-solid fa-user-check"></i>
                        <span class="nav-name-tag">Artisan Registry</span>
                        <?php if ($sb_pending_artisans_cnt > 0): ?>
                            <span class="badge bg-warning text-dark rounded-pill ms-auto" title="<?php echo $sb_pending_artisans_cnt; ?> pending artisan verification(s)">
                                <?php echo $sb_pending_artisans_cnt; ?> new
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endif; ?>
            
            <li class="nav-section-label">Portals</li>
            <?php if (isModuleEnabled('zonal_divisions')): ?>
                <li>
                    <a href="../zone/index" target="_blank" title="Zone Portal">
                        <i class="fa-solid fa-network-wired"></i>
                        <span class="nav-name-tag">Zone Portal</span>
                        <i class="fa-solid fa-arrow-up-right-from-square ms-auto small opacity-50" style="font-size: 10px;"></i>
                    </a>
                </li>
            <?php endif; ?>
            <li>
                <a href="../resident/index" target="_blank" title="Resident Portal">
                    <i class="fa-solid fa-house-chimney-user"></i>
                    <span class="nav-name-tag">Resident Portal</span>
                    <i class="fa-solid fa-arrow-up-right-from-square ms-auto small opacity-50" style="font-size: 10px;"></i>
                </a>
            </li>

            <li class="nav-section-label">Security &amp; System</li>
            <?php if (isModuleEnabled('emergency_sos')): ?>
                <li>
                    <a href="../admin/emergency" class="<?php echo ($current_page == 'emergency') ? 'active' : ''; ?> text-danger fw-bold" title="Emergency & Panic Hub">
                        <i class="fa-solid fa-truck-medical text-danger"></i>
                        <span class="nav-name-tag">Emergency Hub</span>
                    </a>
                </li>
            <?php endif; ?>
            <li>
                <a href="../admin/incidents" class="<?php echo ($current_page == 'incidents') ? 'active' : ''; ?>" title="Incident & Occurrence Book">
                    <i class="fa-solid fa-book-skull text-danger"></i>
                    <span class="nav-name-tag">Incident Book</span>
                </a>
            </li>
            <?php if (isModuleEnabled('security_patrol')): ?>
                <li>
                    <a href="../admin/roster" class="<?php echo ($current_page == 'roster') ? 'active' : ''; ?>" title="Security Duty Roster">
                        <i class="fa-solid fa-calendar-check"></i>
                        <span class="nav-name-tag">Security Duty Roster</span>
                    </a>
                </li>
            <?php endif; ?>
            <?php if (isModuleEnabled('visitor_passes')): ?>
                <li>
                    <a href="../admin/security" class="<?php echo ($current_page == 'security') ? 'active' : ''; ?>" title="Gate Visitor Passes">
                        <i class="fa-solid fa-shield-halved"></i>
                        <span class="nav-name-tag">Gate Visitor Passes</span>
                    </a>
                </li>
            <?php endif; ?>
            <?php if (isModuleEnabled('ussd_offline_sync')): ?>
                <li>
                    <a href="../admin/africastalking" class="<?php echo ($current_page == 'africastalking') ? 'active' : ''; ?>" title="USSD & Offline Sync">
                        <i class="fa-solid fa-signal text-primary"></i>
                        <span class="nav-name-tag">USSD &amp; Offline Sync</span>
                    </a>
                </li>
            <?php endif; ?>
            <li>
                <a href="../admin/email_logs" class="<?php echo ($current_page == 'email_logs') ? 'active' : ''; ?>" title="Email Logs">
                    <i class="fa-solid fa-envelope-open-text"></i>
                    <span class="nav-name-tag">Email Logs</span>
                </a>
            </li>
            <li>
                <a href="../admin/whatsapp_logs" class="<?php echo ($current_page == 'whatsapp_logs') ? 'active' : ''; ?>" title="WhatsApp Logs">
                    <i class="fa-brands fa-whatsapp text-success"></i>
                    <span class="nav-name-tag">WhatsApp Logs</span>
                </a>
            </li>
            <li>
                <a href="../admin/users" class="<?php echo ($current_page == 'users') ? 'active' : ''; ?>" title="User Accounts & Security">
                    <i class="fa-solid fa-user-shield"></i>
                    <span class="nav-name-tag">User Accounts</span>
                </a>
            </li>
            <li>
                <a href="../admin/audit_logs" class="<?php echo ($current_page == 'audit_logs') ? 'active' : ''; ?>" title="System Audit">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <span class="nav-name-tag">System Audit</span>
                </a>
            </li>
            
            <li class="nav-section-label">Settings &amp; White-Label</li>
            <li>
                <a href="../admin/branding" class="<?php echo ($current_page == 'branding') ? 'active' : ''; ?>" title="White-Label & Branding">
                    <i class="fa-solid fa-paintbrush text-info"></i>
                    <span class="nav-name-tag">Branding</span>
                </a>
            </li>
            <li>
                <a href="../admin/settings" class="<?php echo ($current_page == 'settings') ? 'active' : ''; ?>" title="Settings">
                    <i class="fa-solid fa-sliders"></i>
                    <span class="nav-name-tag">Settings</span>
                </a>
            </li>

            <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'manager'], true)): ?>
                <li class="nav-section-label">Subscription &amp; Support</li>
                <li>
                    <a href="../admin/subscription" class="<?php echo ($current_page == 'subscription') ? 'active' : ''; ?>" title="Subscription & Billing">
                        <i class="fa-solid fa-receipt"></i>
                        <span class="nav-name-tag">Subscription</span>
                    </a>
                </li>
                <li>
                    <a href="../admin/onboarding" class="<?php echo ($current_page == 'onboarding') ? 'active' : ''; ?>" title="Onboarding">
                        <i class="fa-solid fa-list-check"></i>
                        <span class="nav-name-tag">Onboarding</span>
                    </a>
                </li>
                <li>
                    <a href="../admin/invitations" class="<?php echo ($current_page == 'invitations') ? 'active' : ''; ?>" title="Invitations">
                        <i class="fa-solid fa-envelope-open-text"></i>
                        <span class="nav-name-tag">Invitations</span>
                    </a>
                </li>
                <li>
                    <a href="../admin/support" class="<?php echo ($current_page == 'support') ? 'active' : ''; ?>" title="Support">
                        <i class="fa-solid fa-life-ring"></i>
                        <span class="nav-name-tag">Support</span>
                    </a>
                </li>
                <li>
                    <a href="../admin/export" class="<?php echo ($current_page == 'export') ? 'active' : ''; ?>" title="Data Export">
                        <i class="fa-solid fa-file-export"></i>
                        <span class="nav-name-tag">Data Export</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if (is_impersonating_estate()): ?>
                <li class="nav-section-label text-warning">Super Admin HQ</li>
                <li>
                    <a href="../superadmin/index" style="color: #f59e0b; font-weight: 700;" title="SaaS Command Center">
                        <i class="fa-solid fa-layer-group text-warning"></i>
                        <span class="nav-name-tag">SaaS Command Center</span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </nav>
    <div class="sidebar-footer-actions">
        <div class="sidebar-user-block">
            <div class="sidebar-user-avatar">
                <?php
                $initials = 'CA';
                if (!empty($_SESSION['name'])) {
                    $parts = explode(' ', trim($_SESSION['name']));
                    $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                }
                echo htmlspecialchars($initials);
                ?>
            </div>
            <div class="sidebar-user-text">
                <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Central Admin'); ?></div>
                <div class="sidebar-user-role"><?php echo htmlspecialchars(ucfirst($_SESSION['role'] ?? 'Admin')); ?></div>
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
        renderMobileAppShell('admin', $current_page, $page_title ?? '');
    }
    ?>
    <header class="top-bar">
        <div class="d-flex align-items-center gap-3">
            <div class="toggle-sidebar d-md-none" style="cursor: pointer;">
                <i class="fa-solid fa-bars fs-5"></i>
            </div>
            
            <!-- Modern Dribbble Search Bar with ⌘ K -->
            <form action="../admin/residents" method="GET" class="desktop-topbar-search topbar-search-box m-0" onsubmit="if(!this.q.value.trim()){return false;}">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" name="q" placeholder="Search..." autocomplete="off">
                <span class="topbar-kbd-pill" title="Press Ctrl+K or ⌘K">⌘ K</span>
            </form>
        </div>

        <div class="topbar-right-actions ms-auto">
            <!-- Messages / Community Forum Chat Icon -->
            <a href="../admin/community_chat" class="topbar-action-circle" title="Messages & Forum">
                <i class="fa-regular fa-comment-dots"></i>
            </a>

            <!-- Notification Bell with Quick Action Dropdown -->
            <div class="dropdown position-relative">
                <button type="button" class="topbar-action-circle border-0" id="topNotifBellBtn" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications & Pending Requests">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($sb_unread_count > 0 || $sb_pending_requests_cnt > 0): ?>
                        <span class="topbar-badge-dot"></span>
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
            <button type="button" class="estate-hotline-btn btn btn-outline-danger btn-sm rounded-pill px-2 px-sm-3 d-inline-flex align-items-center gap-2" title="Direct Emergency Hotlines">
                <i class="fa-solid fa-phone-volume text-danger hotline-pulse-icon"></i>
                <span class="fw-semibold d-none d-sm-inline">Hotlines</span>
            </button>

            <!-- User Profile Dropdown (Welcome back Wade Warren) -->
            <div class="dropdown">
                <?php 
                $initials = 'WW';
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
                        <span class="topbar-user-fullname"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Wade Warren'); ?></span>
                    </div>
                    <i class="fa-solid fa-chevron-down text-muted ms-1" style="font-size: 0.65rem;"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4 p-2 mt-2" style="min-width: 220px; z-index: 1055;">
                    <div class="px-3 py-2 border-bottom mb-1">
                        <div class="fw-bold text-dark small"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin User'); ?></div>
                        <div class="text-muted" style="font-size: 0.72rem;"><?php echo htmlspecialchars($_SESSION['email'] ?? 'admin@estate.com'); ?></div>
                    </div>
                    <?php if (is_impersonating_estate()): ?>
                        <a href="../superadmin/index" class="dropdown-item py-2 px-3 rounded-2 small d-flex align-items-center gap-2 text-warning fw-bold" style="background: rgba(245, 158, 11, 0.08);">
                            <i class="fa-solid fa-bolt text-warning"></i> Super Admin Console
                        </a>
                    <?php endif; ?>
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
