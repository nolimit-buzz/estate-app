<?php
// superadmin/includes/super_sidebar.php - Dedicated Super Admin Enterprise Sidebar
$super_active = basename($_SERVER['PHP_SELF'], '.php');
$admin_name = $_SESSION['name'] ?? 'Super Admin';
$admin_email = $_SESSION['email'] ?? 'superadmin@admin.com';

// Fetch quick statistics for badges
$tot_est_cnt = 0;
$active_est_cnt = 0;
if (isset($conn)) {
    $e_c = $conn->query("SELECT COUNT(*) as c, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as act FROM estates");
    if ($e_c) {
        $row = $e_c->fetch_assoc();
        $tot_est_cnt = intval($row['c'] ?? 0);
        $active_est_cnt = intval($row['act'] ?? 0);
    }
}

$is_impersonating = is_impersonating_estate();
$impersonated_name = get_impersonated_estate_name($conn);
?>
<!-- SUPER ADMIN DEDICATED SAAS SIDEBAR -->
<aside id="super-sidebar">
    <!-- Brand Header -->
    <div class="sidebar-brand">
        <div class="brand-icon-box">
            <i class="fa-solid fa-layer-group"></i>
        </div>
        <div>
            <h5 class="brand-title">EstateHQ</h5>
            <div class="brand-subtitle">
                <span class="pulse-dot"></span>
                <span>SaaS Control Tower</span>
            </div>
        </div>
    </div>

    <!-- Navigation List -->
    <div class="sidebar-nav-container">
        
        <?php if ($is_impersonating): ?>
            <!-- Impersonation Alert in Sidebar -->
            <div class="p-2.5 mb-3 rounded-3" style="background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.3);">
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="badge bg-warning text-dark font-monospace" style="font-size: 0.65rem;">IMPERSONATING</span>
                    <a href="exit_impersonation" class="text-warning text-decoration-none small fw-bold" title="Exit Impersonation">&times; Exit</a>
                </div>
                <div class="text-white small fw-bold text-truncate" style="font-size: 0.78rem;">
                    <?= htmlspecialchars($impersonated_name) ?>
                </div>
                <a href="../admin/index" class="btn btn-warning btn-sm w-100 mt-2 py-1 fw-bold rounded-2 text-dark" style="font-size: 0.72rem;">
                    <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Open Portal
                </a>
            </div>
        <?php endif; ?>

        <!-- SECTION 1: PLATFORM OVERVIEW -->
        <div class="nav-category">Platform Overview</div>
        
        <a href="index" class="nav-link-super <?= ($super_active === 'index') ? 'active' : '' ?>">
            <i class="fa-solid fa-chart-pie"></i>
            <span>Dashboard &amp; KPIs</span>
        </a>

        <!-- SECTION 2: TENANT MANAGEMENT -->
        <div class="nav-category">Tenant Operations</div>

        <a href="index#estates-table-card" class="nav-link-super">
            <i class="fa-solid fa-building-user"></i>
            <span>Tenant Estates</span>
            <span class="badge bg-primary bg-opacity-25 text-white ms-auto rounded-pill px-2 py-0.5" style="font-size: 0.7rem;">
                <?= $tot_est_cnt ?>
            </span>
        </a>

        <a href="create_estate" class="nav-link-super <?= ($super_active === 'create_estate') ? 'active' : '' ?>">
            <i class="fa-solid fa-circle-plus"></i>
            <span>Provision New Estate</span>
        </a>

        <a href="modules" class="nav-link-super <?= ($super_active === 'modules') ? 'active' : '' ?>">
            <i class="fa-solid fa-sliders"></i>
            <span>Global Module Matrix</span>
        </a>

        <!-- SECTION 3: INFRASTRUCTURE & TELECOM -->
        <div class="nav-category">Infrastructure &amp; Telecom</div>

        <a href="gateways" class="nav-link-super <?= ($super_active === 'gateways') ? 'active' : '' ?>">
            <i class="fa-solid fa-tower-cell"></i>
            <span>USSD &amp; Africa's Talking</span>
        </a>

        <a href="../admin/branding" class="nav-link-super">
            <i class="fa-solid fa-palette"></i>
            <span>White-Label Studio</span>
        </a>

        <!-- SECTION 4: GOVERNANCE & AUDIT -->
        <div class="nav-category">Governance &amp; Logs</div>

        <a href="audit" class="nav-link-super <?= ($super_active === 'audit') ? 'active' : '' ?>">
            <i class="fa-solid fa-clock-rotate-left"></i>
            <span>Global Audit Trail</span>
        </a>

        <a href="admins" class="nav-link-super <?= ($super_active === 'admins') ? 'active' : '' ?>">
            <i class="fa-solid fa-user-shield"></i>
            <span>Super Administrators</span>
        </a>

        <!-- SECTION 5: TENANT JUMP -->
        <div class="nav-category">Quick Tenant Jump</div>

        <a href="impersonate?estate_id=1" class="nav-link-super" style="color: #f59e0b; background: rgba(245, 158, 11, 0.08); border-left: 2px solid #f59e0b;">
            <i class="fa-solid fa-key" style="color: #f59e0b;"></i>
            <span>Jump Into Main Estate</span>
        </a>

        <a href="../logout" class="nav-link-super text-danger mt-2" style="background: rgba(239, 68, 68, 0.08);">
            <i class="fa-solid fa-right-from-bracket text-danger"></i>
            <span>Sign Out</span>
        </a>
    </div>

    <!-- User Profile Footer -->
    <div class="sidebar-user-footer">
        <div class="d-flex align-items-center gap-2.5">
            <div style="width: 36px; height: 36px; border-radius: 10px; background: #1e293b; border: 1px solid rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; font-weight: 800; color: #38bdf8; font-size: 0.85rem;">
                <?= strtoupper(substr($admin_name, 0, 2)) ?>
            </div>
            <div style="line-height: 1.25;">
                <div class="text-white small fw-bold" style="font-size: 0.82rem;"><?= htmlspecialchars($admin_name) ?></div>
                <small class="text-secondary" style="font-size: 0.68rem; color: #94a3b8 !important;">Super Administrator</small>
            </div>
        </div>
        <a href="../logout" class="btn btn-sm btn-outline-danger border-0 p-1.5 rounded-circle" title="Logout">
            <i class="fa-solid fa-power-off"></i>
        </a>
    </div>
</aside>
