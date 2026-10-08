<?php
// superadmin/reports.php - filterable, exportable platform reports
$page_title = 'Reports & exports';
require_once __DIR__ . '/includes/super_header.php';
sa_require_role(['superadmin', 'finance', 'support']);

$reports = [
    'subscriptions' => ['Subscriptions', "SELECT e.name Estate, p.name Plan, s.billing_cycle Cycle, s.status Status, s.resident_limit 'Resident limit', e.resident_count Residents, s.period_start 'Period start', s.period_end 'Period end', s.next_billing_date 'Next billing' FROM subscriptions s JOIN estates e ON e.id = s.estate_id JOIN plans p ON p.id = s.plan_id WHERE s.status <> 'cancelled' ORDER BY e.name"],
    'payments' => ['Payments', "SELECT p.created_at Time, e.name Estate, i.invoice_no Invoice, p.entry_type Entry, p.status Status, p.gateway Gateway, p.reference Reference, p.amount Amount FROM payments p JOIN estates e ON e.id = p.estate_id LEFT JOIN invoices i ON i.id = p.invoice_id ORDER BY p.id DESC"],
    'estates' => ['Estates', "SELECT id ID, name Estate, domain_prefix Code, status Status, onboarding_status Onboarding, resident_count Residents, contact_email Email, contact_phone Phone, created_at Registered, suspended_at Suspended, retention_ends_at 'Retention ends' FROM estates ORDER BY id"],
    'residents' => ['Resident counts', "SELECT e.name Estate, e.status Status, e.resident_count Residents, s.resident_limit 'Plan limit', e.resident_count_at 'Counted at' FROM estates e LEFT JOIN subscriptions s ON s.id = e.current_subscription_id WHERE e.status NOT IN ('deleted','archived') ORDER BY e.resident_count DESC"],
    'module_usage' => ['Module usage', "SELECT m.name Module, m.category Category, m.global_enabled 'Globally on', (SELECT COUNT(DISTINCT s.estate_id) FROM subscriptions s JOIN plan_modules pm ON pm.plan_id = s.plan_id AND pm.inclusion = 'included' WHERE pm.module_key = m.module_key AND s.status IN ('active','renewed','grace_period')) 'Estates via plan', (SELECT COUNT(*) FROM estate_addon_modules a WHERE a.module_key = m.module_key) 'Add-ons sold', (SELECT COUNT(*) FROM estate_module_overrides o WHERE o.module_key = m.module_key AND o.state='on') 'Forced ON', (SELECT COUNT(*) FROM estate_module_overrides o WHERE o.module_key = m.module_key AND o.state='off') 'Forced OFF' FROM modules m ORDER BY m.sort_order"],
    'tickets' => ['Support tickets', "SELECT t.ticket_no Ticket, e.name Estate, t.category Category, t.subject Subject, t.priority Priority, t.status Status, t.created_at Opened, t.updated_at Updated FROM support_tickets t JOIN estates e ON e.id = t.estate_id ORDER BY t.id DESC"],
    'invitations' => ['Invitations', "SELECT e.name Estate, i.email Email, i.role Role, i.status Status, i.created_at Sent, i.expires_at Expires, i.accepted_at Accepted FROM invitations i JOIN estates e ON e.id = i.estate_id ORDER BY i.id DESC"],
];

$rk = $_GET['r'] ?? '';
$estate_filter = $_GET['estate_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build dynamic WHERE clauses
$where_clauses = [];
if ($estate_filter) {
    $estate_filter = intval($estate_filter);
    $where_clauses[] = "e.id = $estate_filter";
}
if ($status_filter && in_array($status_filter, ['active', 'pending', 'failed', 'open'])) {
    $where_clauses[] = "s.status = '$status_filter' OR p.status = '$status_filter' OR t.status = '$status_filter'";
}

// Store filter state in session
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['report_filters'] = [
        'rk' => $rk,
        'estate_id' => $estate_filter,
        'date_from' => $date_from,
        'date_to' => $date_to,
        'status' => $status_filter
    ];
}

if ($rk && isset($reports[$rk]) && isset($_GET['export'])) {
    $query = $reports[$rk][1];
    if ($where_clauses) {
        $query .= ' AND ' . implode(' AND ', $where_clauses);
    }
    $r = $platform->query($query . ' LIMIT 50000');
    $heads = array_map(fn($f) => $f->name, $r->fetch_fields());
    $rows = $r->fetch_all(MYSQLI_NUM);
    Audit::log('report.exported', ['estate_id' => null, 'module' => 'reports', 'details' => "Report '{$reports[$rk][0]}' exported with filters (" . count($rows) . ' rows)']);
    sa_csv($rk . '_' . date('Ymd') . '.csv', $heads, $rows);
}
require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';
?>
<div class="row g-3 mb-3"><?php foreach ($reports as $k => $d): ?><div class="col-6 col-lg-3"><a class="metric-card d-block text-decoration-none <?= $rk === $k ? 'border-primary' : '' ?>" href="?r=<?= $k ?>"><div class="fw-semibold text-dark"><?= e($d[0]) ?></div><div class="small text-secondary">View · export CSV</div></a></div><?php endforeach; ?>
 <div class="col-6 col-lg-3"><a class="metric-card d-block text-decoration-none" href="logins"><div class="fw-semibold text-dark">Login activity</div><div class="small text-secondary">Filters · CSV</div></a></div>
 <div class="col-6 col-lg-3"><a class="metric-card d-block text-decoration-none" href="audit"><div class="fw-semibold text-dark">Audit log</div><div class="small text-secondary">Filters · CSV</div></a></div></div>

<?php if ($rk && isset($reports[$rk])): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <h6 class="mb-3 fw-bold">Report Filters</h6>
        <form method="get" class="row g-3 align-items-end">
            <input type="hidden" name="r" value="<?= htmlspecialchars($rk) ?>">

            <div class="col-md-3">
                <label class="form-label small">Estate</label>
                <select name="estate_id" class="form-select form-select-sm">
                    <option value="">All estates</option>
                    <?php $es = $platform->query("SELECT id, name FROM estates WHERE status NOT IN ('deleted','archived') ORDER BY name"); while ($e = $es->fetch_assoc()): ?>
                    <option value="<?= $e['id'] ?>" <?= $estate_filter == $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label small">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="failed" <?= $status_filter === 'failed' ? 'selected' : '' ?>>Failed</option>
                    <option value="open" <?= $status_filter === 'open' ? 'selected' : '' ?>>Open</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($date_from) ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label small">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($date_to) ?>">
            </div>

            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100">Apply Filters</button>
            </div>
        </form>
        <?php if ($estate_filter || $date_from || $date_to || $status_filter): ?>
        <div class="mt-2">
            <a href="?r=<?= urlencode($rk) ?>" class="btn btn-sm btn-outline-secondary">Clear Filters</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php $query = $reports[$rk][1]; if ($where_clauses) $query .= ' AND ' . implode(' AND ', $where_clauses); $r = $platform->query($query . ' LIMIT 200'); $heads = array_map(fn($f) => $f->name, $r->fetch_fields()); ?>
<div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0 fw-bold"><?= e($reports[$rk][0]) ?></h6><a class="btn btn-sm btn-outline-primary" href="?r=<?= e($rk) ?>&estate_id=<?= urlencode($estate_filter) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>&status=<?= urlencode($status_filter) ?>&export=1">Download CSV</a></div>
<div class="card-custom"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><?php foreach ($heads as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php while ($row = $r->fetch_row()): ?><tr><?php foreach ($row as $c): ?><td class="small"><?= e($c) ?></td><?php endforeach; ?></tr><?php endwhile; ?></tbody></table></div></div>
<p class="small text-secondary mt-2">Showing up to 200 rows; the CSV contains the full report.</p>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
