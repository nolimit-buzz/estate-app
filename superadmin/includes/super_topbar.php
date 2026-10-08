<?php
// superadmin/includes/super_topbar.php
$sa_counts = $platform->query("SELECT COUNT(*) c, SUM(status='active') a FROM estates WHERE status NOT IN ('deleted','archived')")->fetch_assoc();
?>
<main id="super-main">
    <header id="super-topbar">
        <div class="d-flex align-items-center gap-3">
            <button type="button" class="btn btn-sm btn-light border d-lg-none" onclick="toggleSuperSidebar()">Menu</button>
            <div>
                <h6 class="fw-bold mb-0 fs-6"><?= e($page_title ?? 'Dashboard') ?></h6>
                <div class="small text-secondary" style="font-size:.72rem"><?= intval($sa_counts['c']) ?> estates &middot; <?= intval($sa_counts['a']) ?> active</div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="create_estate" class="btn btn-sm btn-primary d-none d-sm-inline-flex">Register estate</a>
            <a href="estates" class="btn btn-sm btn-outline-secondary">Estates</a>
        </div>
    </header>
    <?php if (is_impersonating_estate()): ?>
    <div class="px-3 py-2 text-white d-flex flex-wrap align-items-center justify-content-between gap-2" style="background:#b45309;font-size:.85rem">
        <span><strong>Impersonation active:</strong> acting inside <strong><?= e(get_impersonated_estate_name()) ?></strong>. Every action is audit-logged.</span>
        <form method="post" action="exit_impersonation"><button class="btn btn-sm btn-light fw-bold">Exit</button></form>
    </div>
    <?php endif; ?>
    <div class="super-content-body">
        <?= sa_flash() ?>
