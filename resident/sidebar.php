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

<aside class="sidebar">
    <div class="sidebar-header">
        <div class="logo d-flex align-items-center">
            <?php if (!empty($app_company_logo_url)): ?>
                <img src="<?php echo htmlspecialchars($app_company_logo_url); ?>" alt="<?php echo htmlspecialchars($app_company_name); ?>" style="height: 32px; border-radius: 6px; object-fit: contain;">
                <div class="d-flex flex-column ms-2 text-start">
                    <span style="font-size: 1.02rem; font-weight: 800; letter-spacing: -0.01em; line-height: 1.2; color: inherit;"><?php echo htmlspecialchars($app_company_name); ?></span>
                    <span style="font-size: 0.65rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Resident Portal</span>
                </div>
            <?php else: ?>
                <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(var(--primary-rgb), 0.12); color: var(--primary-color); display: flex; align-items: center; justify-content: center; margin-right: 0.5rem;">
                    <i class="fa-solid fa-house-user fs-6"></i>
                </div>
                <div class="d-flex flex-column text-start">
                    <span style="font-size: 1.02rem; font-weight: 800; letter-spacing: -0.01em; line-height: 1.2; color: inherit;"><?php echo htmlspecialchars($app_company_name); ?></span>
                    <span style="font-size: 0.65rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Resident Portal</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <nav class="sidebar-nav">
        <ul>
            <li class="nav-label">Resident Navigation</li>
            <li>
                <a href="index" class="<?php echo ($current_page == 'index') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-chart-pie"></i> Overview Dashboard
                </a>
            </li>
            <li>
                <a href="notifications" class="<?php echo ($current_page == 'notifications') ? 'active' : ''; ?> d-flex align-items-center justify-content-between">
                    <span><i class="fa-solid fa-bell"></i> Action &amp; Notifications</span>
                    <?php if ($unread_all_notifs_cnt > 0 || $res_pending_contact_req): ?>
                        <span class="badge <?php echo ($res_pending_contact_req ? 'bg-warning text-dark' : 'bg-danger'); ?> rounded-pill" style="font-size: 0.68rem;">
                            <?php echo $unread_all_notifs_cnt > 0 ? $unread_all_notifs_cnt : '1 req'; ?>
                        </span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="notices" class="<?php echo ($current_page == 'notices') ? 'active' : ''; ?> d-flex align-items-center justify-content-between">
                    <span><i class="fa-solid fa-bullhorn"></i> Notices &amp; Broadcasts</span>
                    <?php if ($unread_notices_cnt > 0): ?>
                        <span class="badge bg-danger rounded-pill" style="font-size: 0.68rem;"><?php echo $unread_notices_cnt; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="policies" class="<?php echo ($current_page == 'policies') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-book-bookmark text-primary"></i> Rules &amp; Penalties
                </a>
            </li>
            <li>
                <a href="finance" class="<?php echo ($current_page == 'finance') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-file-invoice-dollar"></i> Bills & Invoices
                </a>
            </li>
            <li>
                <a href="receipts" class="<?php echo ($current_page == 'receipts') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-receipt"></i> My Receipts
                </a>
            </li>
            <li>
                <a href="emergency" class="<?php echo ($current_page == 'emergency') ? 'active' : ''; ?> text-danger fw-bold">
                    <i class="fa-solid fa-truck-medical text-danger"></i> Emergency &amp; Panic SOS
                </a>
            </li>
            <li>
                <a href="visitors" class="<?php echo ($current_page == 'visitors') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-id-card-clip"></i> Visitor Access Passes
                </a>
            </li>
            <li>
                <a href="property" class="<?php echo ($current_page == 'property') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-building-user"></i> My Property Details
                </a>
            </li>
            <li>
                <a href="report_issue" class="<?php echo ($current_page == 'report_issue') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-screwdriver-wrench"></i> Maintenance Requests
                </a>
            </li>
            <li>
                <a href="artisans" class="<?php echo ($current_page == 'artisans') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-user-gear text-primary"></i> Verified Artisans
                </a>
            </li>
            <li>
                <a href="community_chat" class="<?php echo ($current_page == 'community_chat') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-comments"></i> Estate Forum
                </a>
            </li>
            <li>
                <a href="directory" class="<?php echo ($current_page == 'directory') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-address-book"></i> Estate Directory
                </a>
            </li>

            <li class="nav-label">Preferences</li>
            <li>
                <a href="settings" class="<?php echo ($current_page == 'settings') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-sliders"></i> Portal Settings
                </a>
            </li>

            <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'superadmin', 'manager'])): ?>
            <li class="nav-label">Management</li>
            <li>
                <a href="../admin/index" class="text-primary fw-bold">
                    <i class="fa-solid fa-arrow-left"></i> Return to Admin
                </a>
            </li>
            <?php endif; ?>

            <li class="nav-label">Account</li>
            <li>
                <a href="../logout" class="text-danger">
                    <i class="fa-solid fa-right-from-bracket"></i> Sign Out
                </a>
            </li>
        </ul>
    </nav>
</aside>

<main class="main-content">
    <?php 
    if (function_exists('renderMobileAppShell')) {
        renderMobileAppShell('resident', $current_page, $page_title ?? '');
    }
    ?>
    <header class="top-header top-bar d-flex align-items-center justify-content-between px-4">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-light border-0 toggle-sidebar d-md-none" type="button" style="padding: 0.25rem 0.5rem;">
                <i class="fa-solid fa-bars fs-5"></i>
            </button>
            <?php if (!empty($res_avatar)): ?>
                <div style="position: relative;">
                    <img src="<?php echo htmlspecialchars($res_avatar); ?>" alt="Profile" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid var(--primary-color); box-shadow: 0 0 10px rgba(var(--primary-rgb), 0.3);">
                    <span style="position: absolute; bottom: 0; right: 0; width: 10px; height: 10px; background: #10b981; border: 2px solid #ffffff; border-radius: 50%;"></span>
                </div>
            <?php else: ?>
                <div style="position: relative;">
                    <div style="width: 40px; height: 40px; border-radius: 50%; background: #e2e8f0; display: flex; align-items: center; justify-content: center; color: #64748b; font-weight: 600;">
                        <i class="fa-solid fa-user"></i>
                    </div>
                    <span style="position: absolute; bottom: 0; right: 0; width: 10px; height: 10px; background: #10b981; border: 2px solid #ffffff; border-radius: 50%;"></span>
                </div>
            <?php endif; ?>
            <div class="d-flex flex-column justify-content-center">
                <div class="d-flex align-items-center gap-2">
                    <h5 class="m-0 fw-bold" style="font-size: 0.98rem; line-height: 1.2;">Resident Portal</h5>
                    <span class="mature-badge mature-badge-emerald d-none d-sm-inline-flex" style="font-size: 0.65rem; padding: 0.15rem 0.45rem;">
                        <i class="fa-solid fa-circle me-1" style="font-size: 0.4rem;"></i> Active
                    </span>
                </div>
                <small class="text-secondary" style="font-size: 0.78rem; line-height: 1.2;">
                    Welcome back, <strong><?php echo htmlspecialchars($user_name); ?></strong>
                    <?php if (!empty($res_unit_label)): ?>
                        <span class="d-none d-md-inline">&bull; <span class="text-primary font-monospace"><?php echo htmlspecialchars($res_unit_label); ?></span></span>
                    <?php endif; ?>
                </small>
            </div>
        </div>

        <!-- Desktop Quick Search (Image 3 Style) -->
        <form action="artisans" method="GET" class="desktop-topbar-search m-0" onsubmit="if(!this.q.value.trim()){return false;}">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="q" placeholder="Search artisans, passes, bills, rules..." autocomplete="off">
        </form>

        <div class="d-flex align-items-center gap-2 gap-sm-3">
            <!-- Emergency Direct Hotlines Button (No Alarm) -->
            <button type="button" class="estate-hotline-btn btn btn-outline-danger btn-sm rounded-pill px-2 px-sm-3 d-inline-flex align-items-center gap-2" title="Direct Emergency Hotlines (Call directly without triggering alarm)">
                <i class="fa-solid fa-phone-volume text-danger hotline-pulse-icon"></i>
                <span class="fw-semibold d-none d-sm-inline">Hotlines</span>
            </button>

            <!-- Day & Night Mode Theme Toggle Button -->
            <button type="button" class="theme-toggle-btn" title="Toggle Day/Night Mode">
                <i class="fa-solid fa-moon"></i>
                <span class="theme-text d-none d-sm-inline">Dark Mode</span>
            </button>

            <!-- Notification Bell with Quick Action Dropdown -->
            <div class="dropdown position-relative">
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-circle position-relative d-inline-flex align-items-center justify-content-center" id="residentNotifBellBtn" data-bs-toggle="dropdown" aria-expanded="false" style="width: 36px; height: 36px; min-width: 36px; min-height: 36px; aspect-ratio: 1 / 1 !important; flex-shrink: 0 !important; padding: 0;" title="My Notifications &amp; Requests">
                    <i class="fa-solid fa-bell text-secondary"></i>
                    <?php if ($unread_all_notifs_cnt > 0 || $res_pending_contact_req): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger border border-white" style="font-size: 0.65rem; padding: 0.25em 0.5em;">
                            <?php echo max(1, $unread_all_notifs_cnt); ?>
                        </span>
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
                                    <div style="width: 8px; height: 8px; border-radius: 50%; background: <?php echo $is_unr ? '#2563eb' : 'transparent'; ?>; margin-top: 6px; flex-shrink: 0;"></div>
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

            <!-- User Profile Dropdown (Image 3 Style) -->
            <div class="dropdown">
                <button type="button" class="btn p-0 border-0 d-flex align-items-center gap-2 text-decoration-none" data-bs-toggle="dropdown" aria-expanded="false" style="background: transparent;">
                    <div class="text-end d-none d-sm-block">
                        <div class="user-name fw-bold" style="font-size: 0.84rem; line-height: 1.2;"><?php echo htmlspecialchars($user_name); ?></div>
                        <small class="text-secondary text-uppercase" style="font-size: 0.68rem; letter-spacing: 0.04em;"><?php echo htmlspecialchars($res_unit_label ?: 'Resident'); ?></small>
                    </div>
                    <?php 
                    $res_initials = 'R';
                    if (!empty($user_name)) {
                        $parts = explode(' ', trim($user_name));
                        $res_initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                    }
                    ?>
                    <div class="user-avatar" style="width: 36px; height: 36px; border-radius: 8px; background: #2563eb; color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; border: 1px solid rgba(255,255,255,0.15);">
                        <?php echo $res_initials; ?>
                    </div>
                    <i class="fa-solid fa-chevron-down text-muted" style="font-size: 0.65rem;"></i>
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
