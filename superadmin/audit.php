<?php
// superadmin/audit.php - platform-wide audit trail: who did what, when, to which estate. Filterable + CSV.
$page_title = 'Audit log';
require_once __DIR__ . '/includes/super_header.php';

$types = ''; $vals = [];
$where = sa_where([
    'estate_id' => ['col' => 'a.estate_id', 'op' => '='], 'actor_type' => ['col' => 'a.actor_type', 'op' => '='], 'action' => ['col' => 'a.action', 'op' => 'like'],
    'actor' => ['col' => 'a.actor_name', 'op' => 'like'], 'module' => ['col' => 'a.module', 'op' => '='], 'from' => ['col' => 'a.created_at', 'op' => 'from'], 'to' => ['col' => 'a.created_at', 'op' => 'to'],
    'q' => ['col' => 'a.details', 'op' => 'like'],
], $types, $vals);
$run = function ($q) use ($platform, $types, $vals) { $st = $platform->prepare($q); if ($types) $st->bind_param($types, ...$vals); $st->execute(); return $st->get_result(); };
$from = "FROM platform_audit_logs a LEFT JOIN estates e ON e.id = a.estate_id $where";
if (isset($_GET['export'])) {
    $rows = []; $r = $run("SELECT a.*, e.name estate_name $from ORDER BY a.id DESC LIMIT 20000");
    while ($x = $r->fetch_assoc()) $rows[] = [$x['created_at'], $x['actor_type'], $x['actor_name'], $x['actor_role'], $x['estate_name'], $x['action'], $x['module'], $x['entity_type'], $x['entity_id'], $x['details'], $x['ip_address']];
    Audit::log('report.exported', ['estate_id' => null, 'module' => 'reports', 'details' => 'Audit log CSV export']);
    sa_csv('audit_' . date('Ymd_His') . '.csv', ['When', 'Actor type', 'Actor', 'Role', 'Estate', 'Action', 'Module', 'Entity', 'Entity id', 'Details', 'IP'], $rows);
}
[$off, $lim, $pg] = sa_page(50);
$total = intval($run("SELECT COUNT(*) c $from")->fetch_assoc()['c']);
$res = $run("SELECT a.*, e.name estate_name $from ORDER BY a.id DESC LIMIT $lim OFFSET $off");
$estates = $platform->query("SELECT id, name FROM estates ORDER BY name")->fetch_all(MYSQLI_ASSOC);
require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';
$g = fn($k) => e($_GET[$k] ?? '');
?>
<form class="card-custom p-3 mb-3 row g-2 align-items-end" method="get">
 <div class="col-6 col-md-3"><label class="form-label small mb-1">Estate</label><select name="estate_id" class="form-select form-select-sm"><option value="">All</option><?php foreach ($estates as $x): ?><option value="<?= $x['id'] ?>" <?= ($_GET['estate_id'] ?? '') == $x['id'] ? 'selected' : '' ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></div>
 <div class="col-6 col-md-2"><label class="form-label small mb-1">Actor type</label><select name="actor_type" class="form-select form-select-sm"><option value="">All</option><?php foreach (['platform', 'tenant', 'system'] as $t): ?><option <?= ($_GET['actor_type'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
 <div class="col-6 col-md-2"><label class="form-label small mb-1">User</label><input class="form-control form-control-sm" name="actor" value="<?= $g('actor') ?>"></div>
 <div class="col-6 col-md-2"><label class="form-label small mb-1">Action</label><input class="form-control form-control-sm" name="action" value="<?= $g('action') ?>" placeholder="e.g. plan."></div>
 <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label><input type="date" class="form-control form-control-sm" name="from" value="<?= $g('from') ?>"></div>
 <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label><input type="date" class="form-control form-control-sm" name="to" value="<?= $g('to') ?>"></div>
 <div class="col-12 col-md-3"><label class="form-label small mb-1">Details contain</label><input class="form-control form-control-sm" name="q" value="<?= $g('q') ?>"></div>
 <div class="col-12 col-md-3 d-flex gap-2"><button class="btn btn-sm btn-primary flex-fill">Filter</button><a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 1]))) ?>">CSV</a></div>
</form>
<div class="card-custom"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>When</th><th>Who</th><th>Estate</th><th>Action</th><th class="d-none d-md-table-cell">Details</th></tr></thead><tbody>
<?php while ($x = $res->fetch_assoc()): ?><tr>
 <td class="small text-nowrap"><?= sa_dt($x['created_at']) ?></td>
 <td class="small"><?= e($x['actor_name'] ?: '—') ?><div class="text-secondary" style="font-size:.7rem"><?= e($x['actor_type'] . ($x['actor_role'] ? ' · ' . $x['actor_role'] : '')) ?> · <?= e($x['ip_address']) ?></div></td>
 <td class="small"><?= $x['estate_id'] ? '<a href="estate?id=' . $x['estate_id'] . '">' . e($x['estate_name'] ?: '#' . $x['estate_id']) . '</a>' : '—' ?></td>
 <td class="small"><code><?= e($x['action']) ?></code></td>
 <td class="small d-none d-md-table-cell"><?= e($x['details']) ?><?php if ($x['before_json'] || $x['after_json']): ?><details class="mt-1"><summary class="text-primary" style="cursor:pointer">changes</summary><pre class="small mb-0" style="white-space:pre-wrap;max-width:520px"><?= e(($x['before_json'] ? "before: " . substr($x['before_json'], 0, 600) . "\n" : '') . ($x['after_json'] ? "after: " . substr($x['after_json'], 0, 600) : '')) ?></pre></details><?php endif; ?></td></tr>
<?php endwhile; ?></tbody></table></div></div>
<?= sa_pager($total, $lim, $pg) ?>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
