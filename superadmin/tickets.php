<?php
// superadmin/tickets.php - support & onboarding queue
$page_title = 'Support tickets';
require_once __DIR__ . '/includes/super_header.php';
$types = ''; $vals = [];
$where = sa_where(['status' => ['col' => 't.status', 'op' => '='], 'category' => ['col' => 't.category', 'op' => '='], 'estate_id' => ['col' => 't.estate_id', 'op' => '='], 'q' => ['col' => 't.subject', 'op' => 'like']], $types, $vals);
if (!isset($_GET['status']) && !$where) { $where = " WHERE t.status IN ('open','in_progress','awaiting_customer')"; }
$run = function ($q) use ($platform, $types, $vals) { $st = $platform->prepare($q); if ($types) $st->bind_param($types, ...$vals); $st->execute(); return $st->get_result(); };
[$off, $lim, $pg] = sa_page(30);
$total = intval($run("SELECT COUNT(*) c FROM support_tickets t $where")->fetch_assoc()['c']);
$res = $run("SELECT t.*, e.name estate_name FROM support_tickets t JOIN estates e ON e.id = t.estate_id $where ORDER BY FIELD(t.priority,'urgent','high','normal','low'), t.updated_at DESC LIMIT $lim OFFSET $off");
require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';
?>
<form class="card-custom p-3 mb-3 row g-2 align-items-end" method="get">
 <div class="col-6 col-md-3"><label class="form-label small mb-1">Status</label><select name="status" class="form-select form-select-sm"><option value="">Open queue</option><?php foreach (['open', 'in_progress', 'awaiting_customer', 'resolved', 'closed'] as $s): ?><option value="<?= $s ?>" <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?></select></div>
 <div class="col-6 col-md-3"><label class="form-label small mb-1">Category</label><select name="category" class="form-select form-select-sm"><option value="">All</option><?php foreach (['onboarding', 'billing', 'technical', 'module_request', 'plan_change', 'other'] as $s): ?><option <?= ($_GET['category'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
 <div class="col-8 col-md-4"><label class="form-label small mb-1">Search</label><input name="q" class="form-control form-control-sm" value="<?= e($_GET['q'] ?? '') ?>"></div><div class="col-4 col-md-2"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
</form>
<div class="card-custom"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Ticket</th><th>Estate</th><th class="d-none d-md-table-cell">Category</th><th>Status</th><th class="d-none d-md-table-cell">Updated</th></tr></thead><tbody>
<?php while ($t = $res->fetch_assoc()): ?><tr><td><a class="fw-semibold" href="ticket?id=<?= $t['id'] ?>"><?= e($t['ticket_no']) ?></a><div class="small"><?= e($t['subject']) ?></div></td><td class="small"><a href="estate?id=<?= $t['estate_id'] ?>"><?= e($t['estate_name']) ?></a></td><td class="small d-none d-md-table-cell"><?= e($t['category']) ?><?= $t['priority'] !== 'normal' ? ' · ' . e($t['priority']) : '' ?></td><td><?= sa_badge($t['status']) ?></td><td class="small d-none d-md-table-cell"><?= sa_dt($t['updated_at']) ?></td></tr>
<?php endwhile; if (!$total) echo '<tr><td colspan="5" class="text-center text-secondary py-4">No tickets.</td></tr>'; ?></tbody></table></div></div>
<?= sa_pager($total, $lim, $pg) ?>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
