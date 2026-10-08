<?php
// superadmin/includes/super_sidebar.php - grouped, text-first navigation (works as an off-canvas drawer on mobile)
$sa_active = basename($_SERVER['PHP_SELF'], '.php');
$sa_open = ['tickets' => 0, 'alerts' => 0, 'errors' => 0];
$r = $platform->query("SELECT (SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress')) t, (SELECT COUNT(*) FROM security_alerts WHERE status = 'open') a, (SELECT COUNT(*) FROM system_errors WHERE resolved = 0 AND severity IN ('error','critical')) e");
if ($r && $row = $r->fetch_assoc()) $sa_open = ['tickets' => intval($row['t']), 'alerts' => intval($row['a']), 'errors' => intval($row['e'])];
$sa_nav = [
    'Overview' => [['index', 'Dashboard']],
    'Estates' => [['estates', 'All estates'], ['create_estate', 'Register an estate'], ['tickets', 'Support tickets', $sa_open['tickets']]],
    'Commercial' => [['plans', 'Plans & pricing'], ['modules', 'Modules'], ['billing', 'Billing & payments'], ['reports', 'Reports & exports']],
    'Security & logs' => [['audit', 'Audit log'], ['logins', 'Login activity'], ['alerts', 'Security alerts', $sa_open['alerts']], ['monitoring', 'System monitoring', $sa_open['errors']]],
    'Platform' => [['settings', 'System settings'], ['templates', 'Email templates'], ['gateways', 'USSD & gateways'], ['backups', 'Backups'], ['admins', 'Platform admins']],
];
?>
<aside id="super-sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon-box"><i class="fa-solid fa-layer-group"></i></div>
        <div><h5 class="brand-title"><?= e(PlatformSettings::get('site_company_name', 'Platform')) ?></h5>
            <div class="brand-subtitle"><span class="pulse-dot"></span><span>Control centre</span></div></div>
    </div>
    <div class="sidebar-nav-container">
        <?php if (is_impersonating_estate()): ?>
        <div class="p-2 mb-3 rounded-3" style="background: rgba(245,158,11,.15); border: 1px solid rgba(245,158,11,.3);">
            <span class="badge bg-warning text-dark" style="font-size:.65rem;">VIEWING AS ESTATE</span>
            <div class="text-white small fw-bold text-truncate mt-1"><?= e(get_impersonated_estate_name()) ?></div>
            <a href="../admin/index" class="btn btn-warning btn-sm w-100 mt-2 fw-bold text-dark">Open estate portal</a>
            <form method="post" action="exit_impersonation" class="mt-1"><button class="btn btn-outline-warning btn-sm w-100">Exit</button></form>
        </div>
        <?php endif; ?>
        <?php foreach ($sa_nav as $group => $items): ?>
            <div class="nav-category"><?= e($group) ?></div>
            <?php foreach ($items as $it): ?>
                <a href="<?= $it[0] ?>" class="nav-link-super <?= $sa_active === $it[0] ? 'active' : '' ?>">
                    <span><?= e($it[1]) ?></span>
                    <?php if (!empty($it[2])): ?><span class="badge bg-danger ms-auto rounded-pill"><?= intval($it[2]) ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
    <div class="sidebar-user-footer">
        <div style="line-height:1.25;min-width:0">
            <div class="text-white small fw-bold text-truncate"><?= e($SA['name']) ?></div>
            <small style="color:#94a3b8;font-size:.68rem"><?= e(ucfirst($SA['role'])) ?></small>
        </div>
        <a href="change_password" class="btn btn-sm btn-outline-secondary border-0 text-secondary" title="Change password">Password</a>
        <a href="../logout" class="btn btn-sm btn-outline-danger border-0">Sign out</a>
    </div>
</aside>
