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

// Fetch unread notices count
$unread_notices_cnt = 0;
if ($s_uid) {
    $un_res = $conn->query("SELECT COUNT(id) as cnt FROM notifications WHERE user_id = $s_uid AND estate_id = " . get_estate_id() . " AND is_read = 0 AND (type = 'estate_broadcast' OR type = 'zone_notice')");
    if ($un_res) {
        $unread_notices_cnt = intval($un_res->fetch_assoc()['cnt'] ?? 0);
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

        <div class="d-flex align-items-center gap-2 gap-sm-3">
            <!-- Day & Night Mode Theme Toggle Button -->
            <button type="button" class="theme-toggle-btn" title="Toggle Day/Night Mode">
                <i class="fa-solid fa-moon"></i>
                <span class="theme-text d-none d-sm-inline">Dark Mode</span>
            </button>

            <a href="settings" class="btn btn-outline-secondary btn-sm rounded-pill px-3" style="font-size: 0.8rem; font-weight: 500;">
                <i class="fa-solid fa-user-gear me-1"></i> Profile
            </a>
            <a href="../logout" class="btn btn-outline-danger btn-sm rounded-pill px-3" style="font-size: 0.8rem; font-weight: 500;">
                <i class="fa-solid fa-right-from-bracket me-1"></i> Logout
            </a>

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
