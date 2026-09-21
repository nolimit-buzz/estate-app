<!-- zone/sidebar.php - Zonal Management Portal Navigation -->
<?php
$current_page = str_replace('.php', '', basename($_SERVER['PHP_SELF']));
$zone_name_display = $_SESSION['zone_name'] ?? 'Zone Portal';
$zone_code_display = $_SESSION['zone_code'] ?? 'ZONE';

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
            
            <li class="nav-label">Zonal Assets & Directory</li>
            <li><a href="properties" class="<?php echo ($current_page == 'properties' || $current_page == 'property_details') ? 'active' : ''; ?>"><i class="fa-solid fa-road"></i> Streets & Units</a></li>
            <li><a href="residents" class="<?php echo ($current_page == 'residents' || $current_page == 'resident_timeline') ? 'active' : ''; ?>"><i class="fa-solid fa-users"></i> Zone Residents</a></li>
            <li><a href="archives" class="<?php echo ($current_page == 'archives') ? 'active' : ''; ?>"><i class="fa-solid fa-box-archive"></i> Zonal Archives</a></li>
            <li><a href="policies" class="<?php echo ($current_page == 'policies') ? 'active' : ''; ?>"><i class="fa-solid fa-gavel"></i> Zonal &amp; Central Policies</a></li>
            <li><a href="broadcasts" class="<?php echo ($current_page == 'broadcasts') ? 'active' : ''; ?>"><i class="fa-solid fa-bullhorn"></i> Zonal Notices &amp; Broadcasts</a></li>
            <li><a href="community_chat" class="<?php echo ($current_page == 'community_chat') ? 'active' : ''; ?>"><i class="fa-solid fa-comments"></i> Estate Forum</a></li>
            <li><a href="emergency" class="<?php echo ($current_page == 'emergency') ? 'active' : ''; ?> text-danger fw-bold"><i class="fa-solid fa-truck-medical text-danger"></i> Emergency &amp; Panic Hub</a></li>
            <li><a href="directory" class="<?php echo ($current_page == 'directory') ? 'active' : ''; ?>"><i class="fa-solid fa-address-book"></i> Estate Directory</a></li>
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
        <div class="d-flex align-items-center gap-2 gap-sm-3">
            <button type="button" class="theme-toggle-btn" title="Toggle Day/Night Mode">
                <i class="fa-solid fa-moon"></i>
                <span class="theme-text d-none d-sm-inline">Dark Mode</span>
            </button>
            <div class="user-menu" style="display: flex; align-items: center; gap: 0.65rem;">
                <div class="text-end d-none d-sm-block">
                    <div class="user-name fw-bold" style="font-size: 0.85rem; line-height: 1.2;"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Zone Admin'); ?></div>
                    <small class="text-secondary text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.04em;">Zone Administrator</small>
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
