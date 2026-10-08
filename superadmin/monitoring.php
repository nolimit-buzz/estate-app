<?php
// superadmin/monitoring.php - failed jobs, payment errors, email / invitation failures, webhook problems
$page_title = 'System monitoring';
require_once __DIR__ . '/includes/super_header.php';
require_once __DIR__ . '/../includes/Lifecycle.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'resolve') { $id = intval($_POST['id']); $platform->query("UPDATE system_errors SET resolved = 1, resolved_by = {$SA['id']}, resolved_at = NOW() WHERE id = $id"); }
    elseif ($a === 'resolve_cat') { $c = $platform->real_escape_string($_POST['category'] ?? ''); $platform->query("UPDATE system_errors SET resolved = 1, resolved_by = {$SA['id']}, resolved_at = NOW() WHERE category = '$c' AND resolved = 0"); }
    elseif ($a === 'retry_email') { $id = intval($_POST['id']); $platform->query("UPDATE email_queue SET status='queued', attempts=0, next_attempt_at=NOW() WHERE id = $id AND status='failed'"); Notifier::deliver($id); }
    elseif ($a === 'retry_all_email') { $platform->query("UPDATE email_queue SET status='queued', attempts=0, next_attempt_at=NOW() WHERE status='failed'"); $n = Notifier::flushQueue(100); redirectWithFlash('monitoring', "Retried failed emails ($n delivered)."); }
    elseif ($a === 'run_lifecycle') { $out = Lifecycle::run(); Audit::log('system.lifecycle_run_manual', ['estate_id' => null, 'module' => 'system', 'details' => 'Lifecycle job triggered manually']); redirectWithFlash('monitoring', 'Lifecycle run complete: ' . implode(' | ', $out)); }
    redirectWithFlash('monitoring', 'Updated.');
}
require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';
$cats = $platform->query("SELECT category, COUNT(*) c, SUM(severity IN ('error','critical')) bad FROM system_errors WHERE resolved = 0 GROUP BY category")->fetch_all(MYSQLI_ASSOC);
$q = $platform->query("SELECT SUM(status='queued') queued, SUM(status='failed') failed, SUM(status='sent' AND sent_at > NOW() - INTERVAL 1 DAY) sent24 FROM email_queue")->fetch_assoc();
$cat = $_GET['category'] ?? '';
$where = "WHERE resolved = 0" . ($cat ? " AND category = '" . $platform->real_escape_string($cat) . "'" : '');
$errs = $platform->query("SELECT x.*, e.name estate_name FROM system_errors x LEFT JOIN estates e ON e.id = x.estate_id $where ORDER BY x.id DESC LIMIT 100");
?>
<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="metric-card"><div class="small text-secondary">Emails queued</div><div class="fs-4 fw-bold"><?= intval($q['queued']) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="metric-card"><div class="small text-secondary">Emails failed</div><div class="fs-4 fw-bold text-danger"><?= intval($q['failed']) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="metric-card"><div class="small text-secondary">Sent (24h)</div><div class="fs-4 fw-bold"><?= intval($q['sent24']) ?></div></div></div>
  <div class="col-6 col-lg-3"><form method="post" class="metric-card h-100 d-flex flex-column justify-content-between"><div class="small text-secondary">Scheduled jobs</div><button class="btn btn-sm btn-outline-primary mt-2" name="action" value="run_lifecycle">Run lifecycle now</button></form></div>
</div>
<div class="d-flex flex-wrap gap-2 mb-3"><a class="btn btn-sm <?= !$cat ? 'btn-primary' : 'btn-outline-secondary' ?>" href="monitoring">All</a><?php foreach ($cats as $c): ?><a class="btn btn-sm <?= $cat === $c['category'] ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?category=<?= e($c['category']) ?>"><?= e(ucfirst($c['category'])) ?> <span class="badge text-bg-<?= $c['bad'] ? 'danger' : 'secondary' ?>"><?= intval($c['c']) ?></span></a><?php endforeach; ?>
 <?php if ($q['failed']): ?><form method="post" class="ms-auto"><button class="btn btn-sm btn-outline-danger" name="action" value="retry_all_email">Retry all failed emails</button></form><?php endif; ?></div>
<div class="card-custom mb-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Severity</th><th>Category</th><th>Issue</th><th class="d-none d-md-table-cell">Estate</th><th>When</th><th></th></tr></thead><tbody>
<?php $n = 0; while ($x = $errs->fetch_assoc()): $n++; ?><tr><td><?= sa_badge($x['severity'] === 'error' || $x['severity'] === 'critical' ? 'failed' : 'pending') ?></td><td class="small"><?= e($x['category']) ?></td><td class="small"><?= e($x['message']) ?></td><td class="small d-none d-md-table-cell"><?= $x['estate_id'] ? '<a href="estate?id=' . $x['estate_id'] . '">' . e($x['estate_name'] ?: '#' . $x['estate_id']) . '</a>' : '—' ?></td><td class="small text-nowrap"><?= sa_dt($x['created_at']) ?></td>
 <td><form method="post"><input type="hidden" name="id" value="<?= $x['id'] ?>"><button class="btn btn-sm btn-link p-0" name="action" value="resolve">Resolve</button></form></td></tr><?php endwhile; if (!$n) echo '<tr><td colspan="6" class="text-secondary text-center py-3">No unresolved issues.</td></tr>'; ?></tbody></table></div></div>
<div class="row g-3"><div class="col-lg-6"><div class="card-custom p-3"><h6 class="fw-bold">Failed emails</h6><div class="table-responsive"><table class="table table-sm mb-0"><tbody>
<?php $r = $platform->query("SELECT * FROM email_queue WHERE status = 'failed' ORDER BY id DESC LIMIT 10"); $n = 0; while ($x = $r->fetch_assoc()): $n++; ?><tr><td class="small"><?= e($x['to_email']) ?><div class="text-danger" style="font-size:.7rem"><?= e($x['last_error']) ?></div></td><td class="small"><?= e($x['template_key']) ?></td><td><form method="post"><input type="hidden" name="id" value="<?= $x['id'] ?>"><button class="btn btn-sm btn-link p-0" name="action" value="retry_email">Retry</button></form></td></tr><?php endwhile; if (!$n) echo '<tr><td class="text-secondary small">None.</td></tr>'; ?></tbody></table></div></div></div>
<div class="col-lg-6"><div class="card-custom p-3"><h6 class="fw-bold">Recent job runs</h6><div class="table-responsive"><table class="table table-sm mb-0"><tbody>
<?php $r = $platform->query("SELECT * FROM job_runs ORDER BY id DESC LIMIT 12"); while ($x = $r->fetch_assoc()): ?><tr><td class="small"><?= e($x['job_name']) ?></td><td><?= sa_badge($x['status']) ?></td><td class="small text-secondary"><?= e(substr((string)$x['message'], 0, 60)) ?></td><td class="small text-nowrap"><?= sa_dt($x['started_at']) ?></td></tr><?php endwhile; ?></tbody></table></div></div></div></div>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
