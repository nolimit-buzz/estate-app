<?php
// superadmin/logins.php - login & security events across every estate (and the platform)
$page_title = 'Login activity';
require_once __DIR__ . '/includes/super_header.php';
$types = ''; $vals = [];
$where = sa_where([
    'estate_id' => ['col' => 'l.estate_id', 'op' => '='], 'email' => ['col' => 'l.email', 'op' => 'like'], 'role' => ['col' => 'l.role', 'op' => '='], 'event' => ['col' => 'l.event_type', 'op' => '='],
    'success' => ['col' => 'l.success', 'op' => '='], 'user_type' => ['col' => 'l.user_type', 'op' => '='], 'ip' => ['col' => 'l.ip_address', 'op' => 'like'],
    'from' => ['col' => 'l.logged_in_at', 'op' => 'from'], 'to' => ['col' => 'l.logged_in_at', 'op' => 'to'],
], $types, $vals);
$run = function ($q) use ($platform, $types, $vals) { $st = $platform->prepare($q); if ($types) $st->bind_param($types, ...$vals); $st->execute(); return $st->get_result(); };
$from = "FROM platform_login_logs l LEFT JOIN estates e ON e.id = l.estate_id $where";
if (isset($_GET['export'])) {
    $rows = []; $r = $run("SELECT l.*, e.name estate_name $from ORDER BY l.id DESC LIMIT 20000");
    while ($x = $r->fetch_assoc()) $rows[] = [$x['logged_in_at'], $x['event_type'], $x['success'] ? 'success' : 'failure', $x['failure_reason'], $x['email'], $x['user_name'], $x['role'], $x['estate_name'], $x['ip_address'], $x['device'], $x['logged_out_at']];
    Audit::log('report.exported', ['estate_id' => null, 'module' => 'reports', 'details' => 'Login activity CSV export']);
    sa_csv('logins_' . date('Ymd_His') . '.csv', ['Time', 'Event', 'Result', 'Reason', 'Email', 'Name', 'Role', 'Estate', 'IP', 'Device', 'Logged out'], $rows);
}
[$off, $lim, $pg] = sa_page(50);
$total = intval($run("SELECT COUNT(*) c $from")->fetch_assoc()['c']);
$res = $run("SELECT l.*, e.name estate_name $from ORDER BY l.id DESC LIMIT $lim OFFSET $off");
$estates = $platform->query("SELECT id, name FROM estates ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$events = array_column($platform->query("SELECT DISTINCT event_type FROM platform_login_logs ORDER BY 1")->fetch_all(MYSQLI_NUM), 0);
require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';
$g = fn($k) => e($_GET[$k] ?? '');
?>
<form class="card-custom p-3 mb-3 row g-2 align-items-end" method="get">
 <div class="col-6 col-md-3"><label class="form-label small mb-1">Estate</label><select name="estate_id" class="form-select form-select-sm"><option value="">All</option><?php foreach ($estates as $x): ?><option value="<?= $x['id'] ?>" <?= ($_GET['estate_id'] ?? '') == $x['id'] ? 'selected' : '' ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></div>
 <div class="col-6 col-md-3"><label class="form-label small mb-1">User (email)</label><input class="form-control form-control-sm" name="email" value="<?= $g('email') ?>"></div>
 <div class="col-6 col-md-2"><label class="form-label small mb-1">Role</label><input class="form-control form-control-sm" name="role" value="<?= $g('role') ?>" placeholder="admin, resident…"></div>
 <div class="col-6 col-md-2"><label class="form-label small mb-1">Result</label><select name="success" class="form-select form-select-sm"><option value="">All</option><option value="1" <?= ($_GET['success'] ?? '') === '1' ? 'selected' : '' ?>>Success</option><option value="0" <?= ($_GET['success'] ?? '') === '0' ? 'selected' : '' ?>>Failure</option></select></div>
 <div class="col-6 col-md-2"><label class="form-label small mb-1">Event</label><select name="event" class="form-select form-select-sm"><option value="">All</option><?php foreach ($events as $ev): ?><option <?= ($_GET['event'] ?? '') === $ev ? 'selected' : '' ?>><?= e($ev) ?></option><?php endforeach; ?></select></div>
 <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label><input type="date" class="form-control form-control-sm" name="from" value="<?= $g('from') ?>"></div>
 <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label><input type="date" class="form-control form-control-sm" name="to" value="<?= $g('to') ?>"></div>
 <div class="col-6 col-md-2"><label class="form-label small mb-1">IP</label><input class="form-control form-control-sm" name="ip" value="<?= $g('ip') ?>"></div>
 <div class="col-12 col-md-3 d-flex gap-2"><button class="btn btn-sm btn-primary flex-fill">Filter</button><a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 1]))) ?>">CSV</a></div>
</form>
<div class="card-custom"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Time</th><th>User</th><th class="d-none d-md-table-cell">Estate</th><th>Event</th><th class="d-none d-lg-table-cell">Device / IP</th><th class="d-none d-lg-table-cell">Signed out</th></tr></thead><tbody>
<?php while ($x = $res->fetch_assoc()): ?><tr>
 <td class="small text-nowrap"><?= sa_dt($x['logged_in_at']) ?></td>
 <td class="small"><?= e($x['email'] ?: $x['user_name']) ?><div class="text-secondary" style="font-size:.7rem"><?= e($x['role']) ?><?= $x['user_type'] === 'platform' ? ' · platform' : '' ?></div></td>
 <td class="small d-none d-md-table-cell"><?= e($x['estate_name'] ?: '—') ?></td>
 <td class="small"><?= $x['success'] ? sa_badge('success') : sa_badge('failed') ?> <span class="text-secondary"><?= e($x['event_type']) ?></span><?= $x['failure_reason'] ? '<div class="text-danger" style="font-size:.7rem">' . e($x['failure_reason']) . '</div>' : '' ?></td>
 <td class="small d-none d-lg-table-cell"><?= e($x['device']) ?><div class="text-secondary" style="font-size:.7rem"><?= e($x['ip_address']) ?></div></td>
 <td class="small d-none d-lg-table-cell"><?= sa_dt($x['logged_out_at']) ?></td></tr>
<?php endwhile; ?></tbody></table></div></div>
<?= sa_pager($total, $lim, $pg) ?>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
