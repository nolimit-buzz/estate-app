<?php
// resident/sidebar.php
if (!isset($conn)) {
    require_once __DIR__ . '/../config.php';
}

$current_page = str_replace('.php', '', basename($_SERVER['PHP_SELF']));

// Fetch Estate Brand Details
$sys_res = $conn->query("SELECT * FROM system_settings WHERE estate_id = " . get_estate_id());
$sys = [];
if ($sys_res) {
    while ($row = $sys_res->fetch_assoc()) {
        $sys[$row['setting_key']] = $row['setting_value'];
    }
}
$estate_name = $sys['estate_name'] ?? 'Resident Portal';
$estate_logo = $sys['estate_logo'] ?? '';
$user_name = $_SESSION['name'] ?? 'Resident';

// Fetch Resident Avatar
$res_avatar = '';
$s_uid = intval($_SESSION['user_id'] ?? 0);
if ($s_uid) {
    $av_res = $conn->query("SELECT image_path FROM residents WHERE user_id = $s_uid ORDER BY id DESC LIMIT 1");
    if ($av_res && $av_row = $av_res->fetch_assoc()) {
        $raw_av = $av_row['image_path'] ?? '';
        if (!empty($raw_av)) {
            if (file_exists($raw_av)) {
                $res_avatar = $raw_av;
            } elseif (file_exists('../' . ltrim($raw_av, './'))) {
                $res_avatar = '../' . ltrim($raw_av, './');
            }
        }
    }
}
?>

<aside class="sidebar">
    <div class="sidebar-header">
        <div class="logo d-flex align-items-center">
            <?php if ($estate_logo): ?>
                <img src="<?php echo htmlspecialchars($estate_logo); ?>" alt="Logo" style="height: 32px; border-radius: 4px;">
                <span style="font-size: 1.05rem; font-weight: 700; margin-left: 0.5rem; color: var(--primary-color);"><?php echo htmlspecialchars($estate_name); ?></span>
            <?php else: ?>
                <i class="fa-solid fa-house-user text-primary fs-4 me-2"></i>
                <span style="font-size: 1.05rem; font-weight: 700;"><?php echo htmlspecialchars($estate_name); ?></span>
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
                <img src="<?php echo htmlspecialchars($res_avatar); ?>" alt="Profile" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 2px solid var(--primary-color);">
            <?php else: ?>
                <div style="width: 38px; height: 38px; border-radius: 50%; background: #e2e8f0; display: flex; align-items: center; justify-content: center; color: #64748b;">
                    <i class="fa-solid fa-user"></i>
                </div>
            <?php endif; ?>
            <div class="d-flex flex-column justify-content-center">
                <h5 class="m-0 fw-bold" style="font-size: 1rem; line-height: 1.2;">Resident Portal</h5>
                <small class="text-secondary" style="font-size: 0.78rem; line-height: 1.2;">Welcome back, <strong><?php echo htmlspecialchars($user_name); ?></strong></small>
            </div>
        </div>

        <div class="d-flex align-items-center gap-3">
            <!-- Day & Night Mode Theme Toggle Button -->
            <button type="button" class="theme-toggle-btn" title="Toggle Day/Night Mode">
                <i class="fa-solid fa-moon"></i>
                <span class="theme-text d-none d-sm-inline">Dark Mode</span>
            </button>

            <a href="settings" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                <i class="fa-solid fa-user-gear me-1"></i> Profile
            </a>
            <a href="../logout" class="btn btn-outline-danger btn-sm rounded-pill px-3">
                <i class="fa-solid fa-right-from-bracket me-1"></i> Logout
            </a>
        </div>
    </header>

    <div class="content-wrapper p-4">
