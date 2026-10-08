<?php
// superadmin/index.php - platform dashboard
$page_title = 'Dashboard';
require_once __DIR__ . '/includes/super_header.php';
require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';

$one = fn($sql) => $platform->query($sql)->fetch_assoc();
$st = $one("SELECT COUNT(*) total,
    SUM(status='active') active_c, SUM(status='trial') trial_c, SUM(status='grace_period') grace_c, SUM(status='suspended') susp_c,
    SUM(status='retention') ret_c, SUM(status='deletion_pending') del_c, SUM(status IN ('pending','onboarding')) onb_c, COALESCE(SUM(resident_count),0) residents
    FROM estates WHERE status NOT IN ('deleted','archived')");
$rev = $one("SELECT COALESCE(SUM(total),0) all_time, COALESCE(SUM(CASE WHEN paid_at >= DATE_FORMAT(NOW(),'%Y-%m-01') THEN total END),0) this_month FROM invoices WHERE status = 'paid'");
$mrr = $one("SELECT COALESCE(SUM(i.subtotal / IF(s.billing_cycle='annual',12,1)),0) mrr, COALESCE(SUM(s.billing_cycle='annual'),0) annual_c
    FROM subscriptions s JOIN invoices i ON i.id = (SELECT MAX(id) FROM invoices WHERE subscription_id = s.id AND status = 'paid' AND kind IN ('subscription','renewal'))
    WHERE s.status IN ('active','renewed','grace_period','past_due')");
$pay = $one("SELECT SUM(status='failed' AND created_at > NOW() - INTERVAL 30 DAY) failed_c, SUM(status='pending') pending_c FROM payments WHERE entry_type='payment'");
$tix = $one("SELECT COUNT(*) c FROM support_tickets WHERE status IN ('open','in_progress','awaiting_customer')");
$alerts = $one("SELECT COUNT(*) c FROM security_alerts WHERE status = 'open'");
$errs = $one("SELECT COUNT(*) c FROM system_errors WHERE resolved = 0 AND severity IN ('error','critical')");
$stat = fn($label, $value, $sub = '', $href = null) => '<div class="col-6 col-lg-3"><a ' . ($href ? 'href="' . $href . '"' : '') . ' class="metric-card d-block text-decoration-none h-100"><div class="small text-secondary">' . e($label) . '</div><div class="fs-3 fw-bold text-dark">' . $value . '</div><div class="small text-secondary">' . $sub . '</div></a></div>';
?>
<div class="row g-3 mb-4">
    <?= $stat('Total estates', intval($st['total']), intval($st['onb_c']) . ' onboarding', 'estates') ?>
    <?= $stat('Active', intval($st['active_c']), intval($st['trial_c']) . ' on trial', 'estates?status=active') ?>
    <?= $stat('Grace period', intval($st['grace_c']), 'payment overdue', 'estates?status=grace_period') ?>
    <?= $stat('Suspended', intval($st['susp_c']) + intval($st['ret_c']), intval($st['del_c']) . ' pending deletion', 'estates?status=suspended') ?>
    <?= $stat('Residents', number_format($st['residents']), 'across all estates') ?>
    <?= $stat('Monthly recurring revenue', sa_money($mrr['mrr']), intval($mrr['annual_c']) . ' annual subscriptions', 'billing') ?>
    <?= $stat('Revenue this month', sa_money($rev['this_month']), 'all time ' . sa_money($rev['all_time']), 'billing') ?>
    <?= $stat('Payments', intval($pay['failed_c']) . ' failed', intval($pay['pending_c']) . ' pending', 'billing') ?>
    <?= $stat('Open tickets', intval($tix['c']), 'support queue', 'tickets') ?>
    <?= $stat('Security alerts', intval($alerts['c']), 'open', 'alerts') ?>
    <?= $stat('System errors', intval($errs['c']), 'unresolved', 'monitoring') ?>
    <?= $stat('Trial estates', intval($st['trial_c']), 'convert or expire', 'estates?status=trial') ?>
</div>

<div class="row g-3">
    <div class="col-lg-6"><div class="card-custom p-3 h-100">
        <h6 class="fw-bold">Recent registrations</h6>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Estate</th><th>Status</th><th>Registered</th></tr></thead><tbody>
        <?php $r = $platform->query("SELECT id,name,status,created_at FROM estates ORDER BY id DESC LIMIT 6"); while ($x = $r->fetch_assoc()): ?>
            <tr><td><a href="estate?id=<?= $x['id'] ?>"><?= e($x['name']) ?></a></td><td><?= sa_badge($x['status']) ?></td><td class="small text-secondary"><?= sa_d($x['created_at']) ?></td></tr>
        <?php endwhile; ?></tbody></table></div>
    </div></div>
    <div class="col-lg-6"><div class="card-custom p-3 h-100">
        <h6 class="fw-bold">Security alerts <a class="small fw-normal" href="alerts">view all</a></h6>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0"><tbody>
        <?php $r = $platform->query("SELECT * FROM security_alerts WHERE status='open' ORDER BY id DESC LIMIT 6"); $n = 0; while ($x = $r->fetch_assoc()): $n++; ?>
            <tr><td><?= sa_badge($x['severity']) ?></td><td class="small"><?= e($x['message']) ?></td><td class="small text-secondary text-nowrap"><?= sa_dt($x['created_at']) ?></td></tr>
        <?php endwhile; if (!$n) echo '<tr><td class="text-secondary small">No open alerts.</td></tr>'; ?></tbody></table></div>
    </div></div>
    <div class="col-lg-6"><div class="card-custom p-3 h-100">
        <h6 class="fw-bold">Recent sign-ins <a class="small fw-normal" href="logins">view all</a></h6>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>User</th><th>Result</th><th>When</th></tr></thead><tbody>
        <?php $r = $platform->query("SELECT * FROM platform_login_logs WHERE event_type IN ('login_success','login_failed','login_blocked') ORDER BY id DESC LIMIT 8"); while ($x = $r->fetch_assoc()): ?>
            <tr><td class="small"><?= e($x['email'] ?: $x['user_name']) ?><div class="text-secondary" style="font-size:.7rem"><?= e($x['ip_address']) ?></div></td><td><?= $x['success'] ? sa_badge('success') : sa_badge('failed') ?></td><td class="small text-secondary"><?= sa_dt($x['logged_in_at']) ?></td></tr>
        <?php endwhile; ?></tbody></table></div>
    </div></div>
    <div class="col-lg-6"><div class="card-custom p-3 h-100">
        <h6 class="fw-bold">Needs attention</h6>
        <ul class="list-unstyled small mb-0">
        <?php
        $items = [];
        $r = $platform->query("SELECT id,name,retention_ends_at FROM estates WHERE status IN ('retention','suspended') ORDER BY retention_ends_at LIMIT 5");
        while ($x = $r->fetch_assoc()) $items[] = ['Suspended: <a href="estate?id=' . $x['id'] . '">' . e($x['name']) . '</a>' . ($x['retention_ends_at'] ? ' - data kept until ' . sa_d($x['retention_ends_at']) . ' (' . max(0, (int)ceil((strtotime($x['retention_ends_at']) - time()) / 86400)) . ' days left)' : '')];
        $r = $platform->query("SELECT id,name FROM estates WHERE status = 'deletion_pending'");
        while ($x = $r->fetch_assoc()) $items[] = ['Awaiting deletion approval: <a href="estate?id=' . $x['id'] . '">' . e($x['name']) . '</a>'];
        $r = $platform->query("SELECT id,name,trial_ends_at FROM estates WHERE status='trial' AND trial_ends_at < NOW() + INTERVAL 3 DAY");
        while ($x = $r->fetch_assoc()) $items[] = ['Trial ending ' . sa_d($x['trial_ends_at']) . ': <a href="estate?id=' . $x['id'] . '">' . e($x['name']) . '</a>'];
        $r = $platform->query("SELECT category, COUNT(*) c FROM system_errors WHERE resolved = 0 GROUP BY category");
        while ($x = $r->fetch_assoc()) $items[] = ['<a href="monitoring">' . intval($x['c']) . ' unresolved ' . e($x['category']) . ' issue(s)</a>'];
        foreach ($items as $i) echo '<li class="py-1 border-bottom">' . $i[0] . '</li>';
        if (!$items) echo '<li class="text-secondary">Nothing needs attention.</li>';
        ?></ul>
    </div></div>
</div>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
