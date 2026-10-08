<?php
// superadmin/estates.php - every registered estate with lifecycle, plan, usage and payment state
$page_title = 'Estates';
require_once __DIR__ . '/includes/super_header.php';

$types = ''; $vals = [];
$where = sa_where([
    'status' => ['col' => 'e.status', 'op' => '='], 'onboarding' => ['col' => 'e.onboarding_status', 'op' => '='],
    'q' => ['col' => 'e.name', 'op' => 'like'], 'plan' => ['col' => 'p.id', 'op' => '='],
], $types, $vals);
$sql = "FROM estates e
    LEFT JOIN subscriptions s ON s.id = (SELECT MAX(id) FROM subscriptions WHERE estate_id = e.id AND status <> 'cancelled')
    LEFT JOIN plans p ON p.id = s.plan_id
    LEFT JOIN (SELECT estate_id, MAX(status='pending') has_pending, SUM(status='failed') failed FROM payments GROUP BY estate_id) pm ON pm.estate_id = e.id
    $where";
$run = function ($q) use ($platform, $types, $vals) { $st = $platform->prepare($q); if ($types) $st->bind_param($types, ...$vals); $st->execute(); return $st->get_result(); };

$cols = "e.id, e.name, e.domain_prefix, e.status, e.onboarding_status, e.resident_count, e.created_at, e.trial_ends_at, e.retention_ends_at, p.name plan_name, s.billing_cycle, s.status sub_status, s.resident_limit, s.next_billing_date, pm.has_pending, pm.failed";
if (isset($_GET['export'])) {
    $rows = [];
    $r = $run("SELECT $cols $sql ORDER BY e.id DESC");
    while ($x = $r->fetch_assoc()) $rows[] = [$x['id'], $x['name'], $x['domain_prefix'], $x['status'], $x['onboarding_status'], $x['plan_name'], $x['billing_cycle'], $x['sub_status'], $x['resident_count'], $x['resident_limit'], $x['next_billing_date'], $x['created_at']];
    Audit::log('report.exported', ['estate_id' => null, 'module' => 'reports', 'details' => 'Estates CSV export']);
    sa_csv('estates_' . date('Ymd') . '.csv', ['ID', 'Estate', 'Code', 'Status', 'Onboarding', 'Plan', 'Cycle', 'Subscription', 'Residents', 'Resident limit', 'Next billing', 'Registered'], $rows);
}
[$off, $lim, $pg] = sa_page(25);
$total = intval($run("SELECT COUNT(*) c $sql")->fetch_assoc()['c']);
$res = $run("SELECT $cols $sql ORDER BY e.id DESC LIMIT $lim OFFSET $off");
$plans = $platform->query("SELECT id, name FROM plans ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';
$statuses = EstateService::ESTATE_STATUSES;
$onb = ['registration_started', 'email_verification_pending', 'central_admin_verification_pending', 'onboarding_pending', 'support_review', 'plan_selected', 'payment_pending', 'payment_confirmed', 'active', 'suspended', 'grace_period', 'deletion_pending'];
?>
<form class="card-custom p-3 mb-3 row g-2 align-items-end" method="get">
    <div class="col-12 col-md-3"><label class="form-label small mb-1">Search</label><input class="form-control form-control-sm" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Estate name"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label><select class="form-select form-select-sm" name="status"><option value="">All</option><?php foreach ($statuses as $s): ?><option value="<?= $s ?>" <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= e(EstateService::statusLabel($s)) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-1">Onboarding</label><select class="form-select form-select-sm" name="onboarding"><option value="">All</option><?php foreach ($onb as $s): ?><option value="<?= $s ?>" <?= ($_GET['onboarding'] ?? '') === $s ? 'selected' : '' ?>><?= e(EstateService::statusLabel($s)) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Plan</label><select class="form-select form-select-sm" name="plan"><option value="">All</option><?php foreach ($plans as $p): ?><option value="<?= $p['id'] ?>" <?= ($_GET['plan'] ?? '') == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2 d-flex gap-2"><button class="btn btn-sm btn-primary flex-fill">Filter</button><a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 1]))) ?>">CSV</a></div>
</form>
<div class="card-custom"><div class="table-responsive"><table class="table align-middle mb-0">
<thead><tr><th>Estate</th><th>Status</th><th class="d-none d-md-table-cell">Onboarding</th><th>Plan</th><th class="d-none d-lg-table-cell">Residents</th><th class="d-none d-lg-table-cell">Payment</th><th class="d-none d-md-table-cell">Registered</th></tr></thead><tbody>
<?php while ($x = $res->fetch_assoc()): ?>
<tr>
  <td><a class="fw-semibold" href="estate?id=<?= $x['id'] ?>"><?= e($x['name']) ?></a><div class="small text-secondary"><?= e($x['domain_prefix']) ?></div></td>
  <td><?= sa_badge($x['status']) ?><?php if ($x['status'] === 'trial' && $x['trial_ends_at']): ?><div class="small text-secondary">ends <?= sa_d($x['trial_ends_at']) ?></div><?php endif; ?><?php if ($x['retention_ends_at'] && in_array($x['status'], ['suspended','retention'])): ?><div class="small text-danger"><?= max(0, (int)ceil((strtotime($x['retention_ends_at']) - time()) / 86400)) ?> days retention left</div><?php endif; ?></td>
  <td class="d-none d-md-table-cell small"><?= e(EstateService::statusLabel($x['onboarding_status'])) ?></td>
  <td><?= $x['plan_name'] ? e($x['plan_name']) . '<div class="small text-secondary">' . e($x['billing_cycle']) . '</div>' : '<span class="text-secondary">—</span>' ?></td>
  <td class="d-none d-lg-table-cell"><?= intval($x['resident_count']) ?><?= $x['resident_limit'] ? ' / ' . intval($x['resident_limit']) : '' ?></td>
  <td class="d-none d-lg-table-cell"><?= $x['sub_status'] ? sa_badge($x['sub_status']) : '—' ?><?php if ($x['failed']): ?> <span class="badge text-bg-danger"><?= intval($x['failed']) ?> failed</span><?php endif; ?></td>
  <td class="d-none d-md-table-cell small text-secondary"><?= sa_d($x['created_at']) ?></td>
</tr>
<?php endwhile; if (!$total): ?><tr><td colspan="7" class="text-secondary text-center py-4">No estates match.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?= sa_pager($total, $lim, $pg) ?>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
