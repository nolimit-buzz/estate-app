<?php
// superadmin/alerts.php - security alerts (repeated failures, new-IP admin logins, lockouts, impersonation)
$page_title = 'Security alerts';
require_once __DIR__ . '/includes/super_header.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $id = intval($_POST['id'] ?? 0); $to = $_POST['to'] ?? '';
    if (in_array($to, ['acknowledged', 'resolved', 'open'], true)) {
        $st = $platform->prepare("UPDATE security_alerts SET status = ?, resolved_by = ?, resolved_at = IF(? = 'resolved', NOW(), NULL) WHERE id = ?");
        $st->bind_param('sisi', $to, $SA['id'], $to, $id); $st->execute();
        Audit::log('security_alert.' . $to, ['estate_id' => null, 'module' => 'security', 'entity_type' => 'alert', 'entity_id' => $id, 'details' => "Alert #$id marked $to"]);
    }
    redirectWithFlash('alerts' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''), 'Alert updated.');
}
$status = $_GET['status'] ?? 'open';
$types = ''; $vals = [];
$where = $status === 'all' ? '' : " WHERE a.status = '" . $platform->real_escape_string($status) . "'";
[$off, $lim, $pg] = sa_page(40);
$total = intval($platform->query("SELECT COUNT(*) c FROM security_alerts a $where")->fetch_assoc()['c']);
$res = $platform->query("SELECT a.*, e.name estate_name FROM security_alerts a LEFT JOIN estates e ON e.id = a.estate_id $where ORDER BY a.id DESC LIMIT $lim OFFSET $off");
require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';
?>
<ul class="nav nav-pills mb-3"><?php foreach (['open', 'acknowledged', 'resolved', 'all'] as $s): ?><li class="nav-item"><a class="nav-link py-1 <?= $status === $s ? 'active' : '' ?>" href="?status=<?= $s ?>"><?= ucfirst($s) ?></a></li><?php endforeach; ?></ul>
<div class="card-custom"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Severity</th><th>Alert</th><th class="d-none d-md-table-cell">Estate</th><th>When</th><th></th></tr></thead><tbody>
<?php while ($x = $res->fetch_assoc()): ?><tr><td><?= sa_badge($x['severity']) ?></td>
 <td class="small"><strong><?= e(str_replace('_', ' ', $x['alert_type'])) ?></strong><div><?= e($x['message']) ?></div><div class="text-secondary" style="font-size:.7rem"><?= e($x['ip_address']) ?></div></td>
 <td class="small d-none d-md-table-cell"><?= $x['estate_id'] ? '<a href="estate?id=' . $x['estate_id'] . '">' . e($x['estate_name'] ?: '#' . $x['estate_id']) . '</a>' : '—' ?></td>
 <td class="small text-nowrap"><?= sa_dt($x['created_at']) ?></td>
 <td class="text-end text-nowrap"><?php if ($x['status'] === 'open'): ?><form method="post" class="d-inline"><input type="hidden" name="id" value="<?= $x['id'] ?>"><button name="to" value="acknowledged" class="btn btn-sm btn-outline-secondary">Acknowledge</button> <button name="to" value="resolved" class="btn btn-sm btn-outline-success">Resolve</button></form><?php elseif ($x['status'] === 'acknowledged'): ?><form method="post" class="d-inline"><input type="hidden" name="id" value="<?= $x['id'] ?>"><button name="to" value="resolved" class="btn btn-sm btn-outline-success">Resolve</button></form><?php else: echo sa_badge($x['status']); endif; ?></td></tr>
<?php endwhile; if (!$total) echo '<tr><td colspan="5" class="text-secondary text-center py-4">No alerts.</td></tr>'; ?></tbody></table></div></div>
<?= sa_pager($total, $lim, $pg) ?>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
