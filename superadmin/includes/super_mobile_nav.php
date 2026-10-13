<?php
// superadmin/includes/super_mobile_nav.php - Platform owner mobile app shell.
// Mirrors the admin/zone mobile UI (includes/mobile_nav.php) using the shared css/mobile_app.css
// and js/mobile_app.js. Presentation only: it re-uses the sidebar's nav array and counters.

if (!function_exists('renderSuperMobileShell')) {
    function renderSuperMobileShell(array $nav, array $open, string $current) {
        global $SA;

        $company   = PlatformSettings::get('site_company_name', 'Platform');
        $name      = $SA['name'] ?? 'Platform Owner';
        $role      = ucfirst($SA['role'] ?? 'Superadmin');
        $parts     = explode(' ', trim($name));
        $initials  = strtoupper(substr($parts[0] ?? 'P', 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
        if ($initials === '') { $initials = 'PO'; }

        $is_subpage = ($current !== 'index' && $current !== '');
        $page_title = ucwords(str_replace('_', ' ', $current));
        foreach ($nav as $items) {
            foreach ($items as $it) {
                if ($it[0] === $current) { $page_title = $it[1]; }
            }
        }
        $alert_dot = !empty($open['alerts']) || !empty($open['errors']);
        ?>
        <div class="mobile-only">
            <?php if ($is_subpage): ?>
                <header class="mobile-subpage-header">
                    <a href="index" class="mobile-subpage-back" title="Go Back">
                        <i class="fa-solid fa-arrow-left"></i>
                        <span><?= e($page_title) ?></span>
                    </a>
                    <div class="mobile-header-actions">
                        <button type="button" class="mobile-icon-btn theme-toggle-btn" title="Toggle Day/Night Mode" aria-label="Toggle Theme">
                            <i class="fa-solid fa-moon"></i>
                        </button>
                        <a href="alerts" class="mobile-icon-btn position-relative" title="Security Alerts">
                            <i class="fa-regular fa-bell"></i>
                            <?php if ($alert_dot): ?><span class="mobile-badge-dot"></span><?php endif; ?>
                        </a>
                    </div>
                </header>
            <?php else: ?>
                <header class="mobile-app-header">
                    <a href="index" class="mobile-brand-link" title="<?= e($company) ?>">
                        <div class="mobile-brand-icon"><i class="fa-solid fa-circle-notch"></i></div>
                        <div class="mobile-brand-info">
                            <span class="mobile-brand-title"><?= e($company) ?></span>
                        </div>
                    </a>
                    <div class="mobile-header-actions">
                        <a href="tickets" class="mobile-icon-btn position-relative" title="Support Tickets">
                            <i class="fa-regular fa-comment-dots"></i>
                            <?php if (!empty($open['tickets'])): ?><span class="mobile-badge-dot"></span><?php endif; ?>
                        </a>
                        <a href="alerts" class="mobile-icon-btn position-relative" title="Security Alerts">
                            <i class="fa-regular fa-bell"></i>
                            <?php if ($alert_dot): ?><span class="mobile-badge-dot"></span><?php endif; ?>
                        </a>
                        <button type="button" class="mobile-icon-btn theme-toggle-btn d-none d-sm-inline-flex" title="Toggle Day/Night Mode" aria-label="Toggle Theme">
                            <i class="fa-solid fa-moon"></i>
                        </button>
                        <a href="settings" class="mobile-user-profile-right" title="System Settings">
                            <div class="mobile-user-meta text-end d-flex flex-column justify-content-center">
                                <span class="mobile-user-name"><?= e($name) ?></span>
                                <span class="mobile-user-role-badge"><?= e($role) ?></span>
                            </div>
                            <div class="mobile-avatar-wrap">
                                <div class="mobile-avatar-fallback"><?= e($initials) ?></div>
                            </div>
                        </a>
                    </div>
                </header>
            <?php endif; ?>
        </div>

        <nav class="mobile-bottom-nav mobile-only" aria-label="Mobile Navigation">
            <a href="index" class="mobile-tab-item <?= $current === 'index' ? 'active' : '' ?>">
                <i class="fa-solid fa-table-cells-large"></i>
                <span class="tab-label">Home</span>
            </a>
            <a href="estates" class="mobile-tab-item <?= in_array($current, ['estates', 'estate', 'create_estate'], true) ? 'active' : '' ?>">
                <i class="fa-solid fa-building"></i>
                <span class="tab-label">Estates</span>
            </a>
            <a href="tickets" class="mobile-tab-item <?= in_array($current, ['tickets', 'ticket'], true) ? 'active' : '' ?>">
                <i class="fa-solid fa-ticket"></i>
                <span class="tab-label">Tickets</span>
            </a>
            <a href="billing" class="mobile-tab-item <?= $current === 'billing' ? 'active' : '' ?>">
                <i class="fa-solid fa-credit-card"></i>
                <span class="tab-label">Billing</span>
            </a>
            <a href="javascript:void(0)" class="mobile-tab-item" onclick="openMobileActionSheet()">
                <i class="fa-solid fa-bars-staggered"></i>
                <span class="tab-label">All Menu</span>
            </a>
        </nav>

        <div class="mobile-sheet-backdrop mobile-only" id="mobileActionSheetBackdrop">
            <div class="mobile-action-sheet" id="mobileActionSheet">
                <div class="mobile-sheet-handle"></div>
                <div class="mobile-sheet-header">
                    <div class="d-flex align-items-center gap-2">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(37, 99, 235, 0.12); color: var(--mob-primary); display: flex; align-items: center; justify-content: center; font-size: 0.95rem;">
                            <i class="fa-solid fa-circle-notch"></i>
                        </div>
                        <div>
                            <h3 class="mobile-sheet-title" style="font-size: 0.95rem; line-height: 1.2;"><?= e($company) ?></h3>
                            <span style="font-size: 0.68rem; color: var(--mob-text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;">Platform Control Centre</span>
                        </div>
                    </div>
                    <button type="button" class="mobile-sheet-close" id="mobileSheetCloseBtn" aria-label="Close">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div class="mobile-drawer-theme-card mb-3">
                    <div class="d-flex align-items-center gap-2.5" style="min-width: 0;">
                        <div class="mobile-drawer-theme-icon flex-shrink-0"><i class="fa-solid fa-circle-half-stroke"></i></div>
                        <div style="min-width: 0;">
                            <div class="mobile-drawer-theme-title text-truncate">Interface Mode</div>
                            <div class="mobile-drawer-theme-desc text-truncate">Switch day or night theme</div>
                        </div>
                    </div>
                    <button type="button" class="theme-toggle-btn mobile-theme-switch-pill" title="Toggle Day/Night Mode">
                        <i class="fa-solid fa-moon"></i>
                        <span class="theme-text">Dark Mode</span>
                    </button>
                </div>

                <?php if (function_exists('is_impersonating_estate') && is_impersonating_estate()): ?>
                <div class="mobile-drawer-section">
                    <div class="mobile-drawer-section-title">Viewing as <?= e(get_impersonated_estate_name()) ?></div>
                    <div class="mobile-sheet-grid">
                        <a href="../admin/index" class="mobile-action-tile">
                            <span class="mobile-tile-icon"><i class="fa-solid fa-arrow-up-right-from-square text-warning"></i></span>
                            <span class="mobile-tile-label">Open estate portal</span>
                        </a>
                        <form method="post" action="exit_impersonation" class="m-0" style="display: contents;">
                            <button type="submit" class="mobile-action-tile w-100 border-0 bg-transparent">
                                <span class="mobile-tile-icon" style="background: rgba(245, 158, 11, 0.15);"><i class="fa-solid fa-user-slash text-warning"></i></span>
                                <span class="mobile-tile-label">Exit impersonation</span>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <?php foreach ($nav as $section => $items): ?>
                <div class="mobile-drawer-section">
                    <div class="mobile-drawer-section-title"><?= e($section) ?></div>
                    <div class="mobile-sheet-grid">
                        <?php foreach ($items as $it):
                            $badge = intval($it[3] ?? 0);
                        ?>
                        <a href="<?= e($it[0]) ?>" class="mobile-action-tile <?= $current === $it[0] ? 'active' : '' ?>">
                            <span class="mobile-tile-icon"><i class="fa-solid <?= e($it[2]) ?> text-primary"></i></span>
                            <span class="mobile-tile-label"><?= e($it[1]) ?></span>
                            <?php if ($badge > 0): ?>
                                <span class="badge bg-danger rounded-pill mobile-tile-badge"><?= $badge ?></span>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="mobile-drawer-section">
                    <div class="mobile-drawer-section-title">Account</div>
                    <div class="mobile-sheet-grid">
                        <a href="change_password" class="mobile-action-tile <?= $current === 'change_password' ? 'active' : '' ?>">
                            <span class="mobile-tile-icon"><i class="fa-solid fa-key text-warning"></i></span>
                            <span class="mobile-tile-label">Change Password</span>
                        </a>
                        <a href="../logout" class="mobile-action-tile">
                            <span class="mobile-tile-icon" style="background: rgba(239, 68, 68, 0.1);"><i class="fa-solid fa-arrow-right-from-bracket text-danger"></i></span>
                            <span class="mobile-tile-label text-danger">Sign Out</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
