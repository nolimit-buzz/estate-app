<!-- includes/sidebar.php -->
<?php
// Fetch Estate Branding & Company Details
$branding = get_estate_branding($conn);
$estate_name = $branding['estate_name'] ?? 'Main Estate';
$estate_logo_url = $branding['estate_logo_url'] ?? '';
$app_company_name = $branding['app_company_name'] ?? 'NoLimitBuzz';
$app_company_logo_url = $branding['app_company_logo_url'] ?? '';

$current_page = str_replace('.php', '', basename($_SERVER['PHP_SELF']));
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
            
            <li class="nav-label">Real Estate & Assets</li>
            <li><a href="../admin/zones" class="<?php echo ($current_page == 'zones') ? 'active' : ''; ?>"><i class="fa-solid fa-layer-group"></i> Zones & Sectors</a></li>
            <li><a href="../admin/properties" class="<?php echo ($current_page == 'properties' || $current_page == 'property_details') ? 'active' : ''; ?>"><i class="fa-solid fa-city"></i> Properties & Units</a></li>
            <li><a href="../admin/owners" class="<?php echo ($current_page == 'owners') ? 'active' : ''; ?>"><i class="fa-solid fa-id-card-clip"></i> Property Owners</a></li>
            <li><a href="../admin/residents" class="<?php echo ($current_page == 'residents' || $current_page == 'resident_timeline') ? 'active' : ''; ?>"><i class="fa-solid fa-users"></i> Residents Registry</a></li>
            <li><a href="../admin/directory" class="<?php echo ($current_page == 'directory') ? 'active' : ''; ?>"><i class="fa-solid fa-address-book"></i> Member Directory</a></li>
            <li><a href="../admin/broadcasts" class="<?php echo ($current_page == 'broadcasts') ? 'active' : ''; ?>"><i class="fa-solid fa-bullhorn"></i> Broadcasts &amp; Notices</a></li>
            <li><a href="../admin/community_chat" class="<?php echo ($current_page == 'community_chat') ? 'active' : ''; ?>"><i class="fa-solid fa-comments"></i> Estate Forum</a></li>
            <li><a href="../admin/staff" class="<?php echo ($current_page == 'staff') ? 'active' : ''; ?>"><i class="fa-solid fa-user-gear"></i> Estate Staff</a></li>
            <li><a href="../admin/archives" class="<?php echo ($current_page == 'archives') ? 'active' : ''; ?>"><i class="fa-solid fa-box-archive"></i> Archives Vault</a></li>
            <li><a href="../admin/policies" class="<?php echo ($current_page == 'policies') ? 'active' : ''; ?>"><i class="fa-solid fa-gavel"></i> Policies &amp; Bylaws</a></li>
            
            <li class="nav-label">Finance & Facilities</li>
            <li><a href="../admin/finance" class="<?php echo ($current_page == 'finance' || $current_page == 'receipt') ? 'active' : ''; ?>"><i class="fa-solid fa-wallet"></i> Finance Hub</a></li>
            <li><a href="../admin/charges" class="<?php echo ($current_page == 'charges') ? 'active' : ''; ?>"><i class="fa-solid fa-tags"></i> Charge Catalog</a></li>
            <li><a href="../admin/billing_config" class="<?php echo ($current_page == 'billing_config') ? 'active' : ''; ?>"><i class="fa-solid fa-sliders"></i> Billing & Installments</a></li>
            <li><a href="../admin/maintenance" class="<?php echo ($current_page == 'maintenance') ? 'active' : ''; ?>"><i class="fa-solid fa-screwdriver-wrench"></i> Maintenance Dispatch</a></li>
            
            <li class="nav-label">Portals</li>
            <li><a href="../zone/index" target="_blank"><i class="fa-solid fa-network-wired"></i> Zone Portal <i class="fa-solid fa-arrow-up-right-from-square ms-auto small opacity-50"></i></a></li>
            <li><a href="../resident/index" target="_blank"><i class="fa-solid fa-house-chimney-user"></i> Resident Portal <i class="fa-solid fa-arrow-up-right-from-square ms-auto small opacity-50"></i></a></li>

            <li class="nav-label">Security & System</li>
            <li><a href="../admin/emergency" class="<?php echo ($current_page == 'emergency') ? 'active' : ''; ?> text-danger fw-bold"><i class="fa-solid fa-truck-medical text-danger"></i> Emergency &amp; Panic Hub</a></li>
            <li><a href="../admin/incidents" class="<?php echo ($current_page == 'incidents') ? 'active' : ''; ?>"><i class="fa-solid fa-book-skull text-danger"></i> Incident &amp; Occurrence Book</a></li>
            <li><a href="../admin/roster" class="<?php echo ($current_page == 'roster') ? 'active' : ''; ?>"><i class="fa-solid fa-calendar-check"></i> Security Duty Roster</a></li>
            <li><a href="../admin/security" class="<?php echo ($current_page == 'security') ? 'active' : ''; ?>"><i class="fa-solid fa-shield-halved"></i> Gate Visitor Passes</a></li>
            <li><a href="../admin/email_logs" class="<?php echo ($current_page == 'email_logs') ? 'active' : ''; ?>"><i class="fa-solid fa-envelope-open-text"></i> Email Logs</a></li>
            <li><a href="../admin/audit_logs" class="<?php echo ($current_page == 'audit_logs') ? 'active' : ''; ?>"><i class="fa-solid fa-clock-rotate-left"></i> System Audit</a></li>
            <li><a href="../admin/settings" class="<?php echo ($current_page == 'settings') ? 'active' : ''; ?>"><i class="fa-solid fa-sliders"></i> Settings</a></li>
            <li><a href="../logout"><i class="fa-solid fa-arrow-right-from-bracket text-danger"></i> Logout</a></li>
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
                <span class="mature-badge mature-badge-slate py-1 px-2">
                    <i class="fa-solid fa-shield-halved text-primary me-1"></i> Admin Portal
                </span>
                <span class="text-secondary opacity-50">•</span>
                <span class="d-inline-flex align-items-center gap-1 text-success" style="font-size: 0.75rem;">
                    <i class="fa-solid fa-circle" style="font-size: 0.45rem;"></i> System Live
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
                    <div class="user-name fw-bold" style="font-size: 0.85rem; line-height: 1.2;"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin User'); ?></div>
                    <small class="text-secondary text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.04em;"><?php echo htmlspecialchars($_SESSION['role'] ?? 'Administrator'); ?></small>
                </div>
                <?php 
                $initials = 'AD';
                if (!empty($_SESSION['name'])) {
                    $parts = explode(' ', trim($_SESSION['name']));
                    $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                }
                ?>
                <div class="user-avatar" style="width: 36px; height: 36px; border-radius: 8px; background: #0f172a; color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; border: 1px solid rgba(255,255,255,0.1);">
                    <?php echo $initials; ?>
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
