<?php
// superadmin/includes/super_sidebar.php - Clear, categorized sidebar with prominent icon name tags
$sa_active = basename($_SERVER['PHP_SELF'], '.php');
$sa_open = ['tickets' => 0, 'alerts' => 0, 'errors' => 0];
require_once __DIR__ . '/../../includes/Support.php';
$r = $platform->query("SELECT (SELECT COUNT(*) FROM security_alerts WHERE status = 'open') a, (SELECT COUNT(*) FROM system_errors WHERE resolved = 0 AND severity IN ('error','critical')) e");
if ($r && $row = $r->fetch_assoc()) $sa_open = ['tickets' => Support::queueCount(), 'alerts' => intval($row['a']), 'errors' => intval($row['e'])];

$sa_nav = [
    'Overview' => [
        ['index', 'Dashboard', 'fa-table-cells-large']
    ],
    'Estates & Support' => [
        ['estates', 'All Estates', 'fa-building'],
        ['create_estate', 'Register Estate', 'fa-circle-plus'],
        ['tickets', 'Support Tickets', 'fa-ticket', $sa_open['tickets']]
    ],
    'Commercial & Plans' => [
        ['plans', 'Plans & Pricing', 'fa-tags'],
        ['modules', 'Modules', 'fa-cubes'],
        ['billing', 'Billing & Payments', 'fa-credit-card'],
        ['reports', 'Reports & Exports', 'fa-chart-pie']
    ],
    'Security & Audit' => [
        ['audit', 'Audit Log', 'fa-shield-halved'],
        ['logins', 'Login Activity', 'fa-user-clock'],
        ['alerts', 'Security Alerts', 'fa-triangle-exclamation', $sa_open['alerts']],
        ['monitoring', 'System Monitoring', 'fa-chart-line', $sa_open['errors']]
    ],
    'Platform Config' => [
        ['settings', 'System Settings', 'fa-gear'],
        ['admins', 'Platform Admins', 'fa-user-shield'],
        ['templates', 'Email Templates', 'fa-envelope-open-text'],
        ['gateways', 'USSD & Gateways', 'fa-network-wired'],
        ['backups', 'Backups & Snapshots', 'fa-database']
    ],
];
?>
<aside class="sidebar sidebar-named">
    <!-- Brand Header with Ring Logo and Title -->
    <div class="sidebar-header">
        <a href="index" class="sidebar-brand-ring" title="<?= e(PlatformSettings::get('site_company_name', 'Control Center')) ?>">
            <i class="fa-solid fa-circle-notch"></i>
        </a>
        <div class="d-flex flex-column" style="min-width: 0;">
            <h5 class="sidebar-brand-title"><?= e(PlatformSettings::get('site_company_name', 'Platform')) ?></h5>
            <div class="sidebar-brand-sub">
                <span style="width: 6px; height: 6px; border-radius: 50%; background: #10b981; display: inline-block;"></span>
                <span>Control Centre</span>
            </div>
        </div>
    </div>

    <!-- Navigation List with Visible Name Tags -->
    <nav class="sidebar-nav">
        <?php if (is_impersonating_estate()): ?>
        <div class="p-2.5 mb-3 rounded-3" style="background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.3);">
            <span class="badge bg-warning text-dark" style="font-size: .65rem; font-weight: 700;">VIEWING AS ESTATE</span>
            <div class="text-dark small fw-bold text-truncate mt-1"><?= e(get_impersonated_estate_name()) ?></div>
            <a href="../admin/index" class="btn btn-warning btn-sm w-100 mt-2 fw-bold text-dark py-1" style="font-size: 0.75rem;">Open estate portal</a>
            <form method="post" action="exit_impersonation" class="mt-1"><button class="btn btn-outline-warning btn-sm w-100 py-1" style="font-size: 0.72rem;">Exit</button></form>
        </div>
        <?php endif; ?>

        <ul>
            <?php foreach ($sa_nav as $section => $items): ?>
                <li class="nav-section-label"><?= e($section) ?></li>
                <?php foreach ($items as $item): 
                    $href = $item[0];
                    $nameTag = $item[1];
                    $icon = $item[2];
                    $badge = $item[3] ?? 0;
                    $isActive = ($sa_active === $href);
                ?>
                <li>
                    <a href="<?= $href ?>" class="<?= $isActive ? 'active' : '' ?>" title="<?= e($nameTag) ?>">
                        <i class="fa-solid <?= $icon ?>"></i>
                        <span class="nav-name-tag"><?= e($nameTag) ?></span>
                        <?php if (!empty($badge)): ?>
                            <span class="badge bg-danger rounded-pill"><?= intval($badge) ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </ul>
    </nav>

    <!-- Footer Actions: User Profile, Password, Logout -->
    <div class="sidebar-footer-actions">
        <div class="sidebar-user-block">
            <div class="sidebar-user-avatar">
                <?php
                $initials = 'PO';
                if (!empty($SA['name'])) {
                    $parts = explode(' ', trim($SA['name']));
                    $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                }
                echo e($initials);
                ?>
            </div>
            <div class="sidebar-user-text">
                <div class="sidebar-user-name"><?= e($SA['name'] ?? 'Platform Owner') ?></div>
                <div class="sidebar-user-role"><?= e(ucfirst($SA['role'] ?? 'Superadmin')) ?></div>
            </div>
        </div>
        <div class="sidebar-footer-links">
            <a href="change_password" class="sidebar-footer-link" title="Change Password">
                <i class="fa-solid fa-key" style="font-size: 11px;"></i> Password
            </a>
            <a href="../logout" class="sidebar-footer-link text-danger" title="Sign Out">
                <i class="fa-solid fa-right-from-bracket" style="font-size: 11px;"></i> Sign out
            </a>
        </div>
    </div>
</aside>
