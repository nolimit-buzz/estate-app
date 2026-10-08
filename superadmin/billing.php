<?php
// superadmin/billing.php - subscription revenue, invoices and the immutable payment ledger across all estates
$page_title = 'Billing & payments';
require_once __DIR__ . '/includes/super_header.php';
sa_require_role(['superadmin', 'finance']);
$view = $_GET['view'] ?? 'payments';
$types = ''; $vals = [];
if ($view === 'invoices') {
    $where = sa_where(['estate_id' => ['col' => 'i.estate_id', 'op' => '='], 'status' => ['col' => 'i.status', 'op' => '='], 'kind' => ['col' => 'i.kind', 'op' => '='], 'from' => ['col' => 'i.created_at', 'op' => 'from'], 'to' => ['col' => 'i.created_at', 'op' => 'to']], $types, $vals);
    $from = "FROM invoices i JOIN estates e ON e.id = i.estate_id $where"; $sel = "i.*, e.name estate_name"; $order = 'i.id DESC';
} else {
    $where = sa_where(['estate_id' => ['col' => 'p.estate_id', 'op' => '='], 'status' => ['col' => 'p.status', 'op' => '='], 'entry_type' => ['col' => 'p.entry_type', 'op' => '='], 'from' => ['col' => 'p.created_at', 'op' => 'from'], 'to' => ['col' => 'p.created_at', 'op' => 'to']], $types, $vals);
    $from = "FROM payments p JOIN estates e ON e.id = p.estate_id LEFT JOIN invoices i ON i.id = p.invoice_id $where"; $sel = "p.*, e.name estate_name, i.invoice_no"; $order = 'p.id DESC';
}
$run = function ($q) use ($platform, $types, $vals) { $st = $platform->prepare($q); if ($types) $st->bind_param($types, ...$vals); $st->execute(); return $st->get_result(); };
if (isset($_GET['export'])) {
    $rows = []; $r = $run("SELECT $sel $from ORDER BY $order LIMIT 20000");
    while ($x = $r->fetch_assoc()) $rows[] = $view === 'invoices' ? [$x['invoice_no'], $x['estate_name'], $x['kind'], $x['status'], $x['subtotal'], $x['credit_applied'], $x['total'], $x['due_date'], $x['paid_at'], $x['created_at']] : [$x['created_at'], $x['estate_name'], $x['invoice_no'], $x['entry_type'], $x['status'], $x['gateway'], $x['reference'], $x['amount'], $x['failure_reason']];
    Audit::log('report.exported', ['estate_id' => null, 'module' => 'reports', 'details' => "Billing $view CSV export"]);
    sa_csv("billing_{$view}_" . date('Ymd') . '.csv', $view === 'invoices' ? ['Invoice', 'Estate', 'Type', 'Status', 'Subtotal', 'Credit', 'Total', 'Due', 'Paid', 'Created'] : ['Time', 'Estate', 'Invoice', 'Entry', 'Status', 'Gateway', 'Reference', 'Amount', 'Failure reason'], $rows);
}
[$off, $lim, $pg] = sa_page(40);
$total = intval($run("SELECT COUNT(*) c $from")->fetch_assoc()['c']);
$res = $run("SELECT $sel $from ORDER BY $order LIMIT $lim OFFSET $off");
$sum = $platform->query("SELECT COALESCE(SUM(CASE WHEN entry_type='payment' AND status='success' THEN amount END),0) paid, COALESCE(SUM(CASE WHEN entry_type='refund' THEN amount END),0) refunds, SUM(entry_type='payment' AND status='failed') failed, SUM(entry_type='payment' AND status='pending') pending FROM payments")->fetch_assoc();
$estates = $platform->query("SELECT id, name FROM estates ORDER BY name")->fetch_all(MYSQLI_ASSOC);
require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';
?>
<div class="row g-3 mb-3">
 <div class="col-6 col-lg-3"><div class="metric-card"><div class="small text-secondary">Collected</div><div class="fs-5 fw-bold"><?= sa_money($sum['paid']) ?></div></div></div>
 <div class="col-6 col-lg-3"><div class="metric-card"><div class="small text-secondary">Refunds</div><div class="fs-5 fw-bold"><?= sa_money(abs($sum['refunds'])) ?></div></div></div>
 <div class="col-6 col-lg-3"><div class="metric-card"><div class="small text-secondary">Failed</div><div class="fs-5 fw-bold text-danger"><?= intval($sum['failed']) ?></div></div></div>
 <div class="col-6 col-lg-3"><div class="metric-card"><div class="small text-secondary">Pending</div><div class="fs-5 fw-bold"><?= intval($sum['pending']) ?></div></div></div>
</div>
<ul class="nav nav-pills mb-3"><li class="nav-item"><a class="nav-link py-1 <?= $view === 'payments' ? 'active' : '' ?>" href="?view=payments">Payment ledger</a></li><li class="nav-item"><a class="nav-link py-1 <?= $view === 'invoices' ? 'active' : '' ?>" href="?view=invoices">Invoices</a></li></ul>
<form class="card-custom p-3 mb-3 row g-2 align-items-end" method="get"><input type="hidden" name="view" value="<?= e($view) ?>">
 <div class="col-12 col-md-3"><label class="form-label small mb-1">Estate</label><select name="estate_id" class="form-select form-select-sm"><option value="">All</option><?php foreach ($estates as $x): ?><option value="<?= $x['id'] ?>" <?= ($_GET['estate_id'] ?? '') == $x['id'] ? 'selected' : '' ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></div>
 <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option><?php foreach (($view === 'invoices' ? ['pending', 'paid', 'failed', 'expired', 'cancelled', 'refunded'] : ['pending', 'success', 'failed', 'expired', 'cancelled', 'refunded']) as $s): ?><option <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
 <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= e($_GET['from'] ?? '') ?>"></div>
 <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= e($_GET['to'] ?? '') ?>"></div>
 <div class="col-6 col-md-3 d-flex gap-2"><button class="btn btn-sm btn-primary flex-fill">Filter</button><a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 1]))) ?>">CSV</a></div></form>
<div class="card-custom"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
<?php if ($view === 'invoices'): ?><thead><tr><th>Invoice</th><th>Estate</th><th class="d-none d-md-table-cell">Type</th><th>Total</th><th>Status</th><th class="d-none d-md-table-cell">Due</th></tr></thead><tbody>
<?php while ($x = $res->fetch_assoc()): ?><tr><td><?= e($x['invoice_no']) ?></td><td class="small"><a href="estate?id=<?= $x['estate_id'] ?>&tab=billing"><?= e($x['estate_name']) ?></a></td><td class="small d-none d-md-table-cell"><?= e($x['kind']) ?></td><td><?= sa_money($x['total']) ?></td><td><?= sa_badge($x['status']) ?></td><td class="small d-none d-md-table-cell"><?= sa_d($x['due_date']) ?></td></tr><?php endwhile; ?>
<?php else: ?><thead><tr><th>Time</th><th>Estate</th><th class="d-none d-md-table-cell">Invoice</th><th>Amount</th><th>Status</th><th class="d-none d-lg-table-cell">Reference</th></tr></thead><tbody>
<?php while ($x = $res->fetch_assoc()): ?><tr><td class="small text-nowrap"><?= sa_dt($x['created_at']) ?></td><td class="small"><a href="estate?id=<?= $x['estate_id'] ?>&tab=billing"><?= e($x['estate_name']) ?></a><div class="text-secondary" style="font-size:.7rem"><?= e($x['entry_type']) ?> · <?= e($x['gateway']) ?></div></td><td class="small d-none d-md-table-cell"><?= e($x['invoice_no']) ?></td><td><?= sa_money($x['amount']) ?></td><td><?= sa_badge($x['status']) ?><?= $x['failure_reason'] ? '<div class="text-danger" style="font-size:.7rem">' . e($x['failure_reason']) . '</div>' : '' ?></td><td class="small d-none d-lg-table-cell"><code><?= e($x['reference']) ?></code></td></tr><?php endwhile; ?><?php endif; ?>
</tbody></table></div></div>
<?= sa_pager($total, $lim, $pg) ?>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
