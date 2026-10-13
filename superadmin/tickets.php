<?php
// superadmin/tickets.php - support & onboarding queue (every row opens the ticket workspace)
$page_title = 'Support tickets';
require_once __DIR__ . '/includes/super_header.php';
require_once __DIR__ . '/../includes/Support.php';

$showDeleted = !empty($_GET['show_deleted']);
$estateGate = $showDeleted ? '' : " AND e.status NOT IN ('deleted','archived')";

// ---- filters (all prepared)
$w = ['1=1']; $types = ''; $vals = [];
$status = $_GET['status'] ?? '';
if ($status !== '' && in_array($status, Support::STATUSES, true)) { $w[] = 't.status = ?'; $types .= 's'; $vals[] = $status; }
elseif ($status === '') { $w[] = "t.status IN ('open','in_progress','awaiting_customer')"; }
$cat = $_GET['category'] ?? '';
if ($cat !== '' && in_array($cat, Support::CATEGORIES, true)) { $w[] = 't.category = ?'; $types .= 's'; $vals[] = $cat; }
$prio = $_GET['priority'] ?? '';
if ($prio !== '' && in_array($prio, Support::PRIORITIES, true)) { $w[] = 't.priority = ?'; $types .= 's'; $vals[] = $prio; }
$asg = $_GET['assignee'] ?? '';
if ($asg === 'me') { $w[] = 't.assigned_to = ?'; $types .= 'i'; $vals[] = intval($SA['id']); }
elseif ($asg === 'none') { $w[] = 't.assigned_to IS NULL'; }
elseif (ctype_digit((string)$asg)) { $w[] = 't.assigned_to = ?'; $types .= 'i'; $vals[] = intval($asg); }
if (!empty($_GET['estate_id'])) { $w[] = 't.estate_id = ?'; $types .= 'i'; $vals[] = intval($_GET['estate_id']); }
$q = trim($_GET['q'] ?? '');
if ($q !== '') { $w[] = '(t.subject LIKE ? OR t.ticket_no LIKE ? OR e.name LIKE ?)'; $types .= 'sss'; $like = '%' . $q . '%'; array_push($vals, $like, $like, $like); }
$where = 'WHERE ' . implode(' AND ', $w) . $estateGate;

$run = function ($sql) use ($platform, $types, $vals) {
    $st = $platform->prepare($sql);
    if ($types) $st->bind_param($types, ...$vals);
    $st->execute();
    return $st->get_result();
};
[$off, $lim, $pg] = sa_page(30);
$total = intval($run("SELECT COUNT(*) c FROM support_tickets t JOIN estates e ON e.id = t.estate_id $where")->fetch_assoc()['c']);
$res = $run("SELECT t.*, e.name estate_name, e.status estate_status, au.name assignee_name
    FROM support_tickets t JOIN estates e ON e.id = t.estate_id LEFT JOIN platform_users au ON au.id = t.assigned_to $where
    ORDER BY FIELD(t.status,'open','in_progress','awaiting_customer','resolved','closed'), FIELD(t.priority,'urgent','high','normal','low'), t.updated_at DESC
    LIMIT $lim OFFSET $off");

// ---- KPI strip (always global, live estates only)
$k = $platform->query("SELECT SUM(t.status IN ('open','in_progress')) needs, SUM(t.status = 'awaiting_customer') waiting,
    SUM(t.status IN ('open','in_progress') AND t.assigned_to IS NULL) unassigned, SUM(t.status IN ('open','in_progress') AND t.priority IN ('urgent','high')) urgent
    FROM support_tickets t JOIN estates e ON e.id = t.estate_id WHERE e.status NOT IN ('deleted','archived')")->fetch_assoc();
$team = $platform->query("SELECT id, name FROM platform_users WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';
$chip = fn($label, $n, $icon, $tone, $href) => '<a href="' . $href . '" class="tk-kpi tk-' . $tone . ' text-decoration-none"><i class="fa-solid ' . $icon . '"></i><span><b>' . intval($n) . '</b><small>' . $label . '</small></span></a>';
?>
<style>
.tk-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px}
.tk-kpi{display:flex;align-items:center;gap:12px;padding:14px 16px;border-radius:16px;background:#fff;border:1px solid #eef0f6;box-shadow:0 1px 3px rgba(20,20,60,.04);color:#1e1b4b;transition:transform .15s,box-shadow .15s}
.tk-kpi:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(20,20,60,.08);color:#1e1b4b}
.tk-kpi i{width:38px;height:38px;border-radius:12px;display:grid;place-items:center;font-size:15px}
.tk-kpi b{display:block;font-size:1.35rem;line-height:1.1}.tk-kpi small{color:#6b7280}
.tk-warn i{background:#fff4e0;color:#d97706}.tk-info i{background:#e8f0ff;color:#2563eb}.tk-mute i{background:#f1f2f6;color:#6b7280}.tk-danger i{background:#fde8e8;color:#dc2626}
tr.tk-row{cursor:pointer;transition:background .12s}tr.tk-row:hover{background:#f5f7ff}tr.tk-row:focus-visible{outline:2px solid #6366f1;outline-offset:-2px}
.tk-prio{display:inline-block;font-size:.68rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;padding:2px 8px;border-radius:999px}
.tk-prio.urgent{background:#fde8e8;color:#b91c1c}.tk-prio.high{background:#fff0e0;color:#c2410c}.tk-prio.normal{background:#eef2ff;color:#4338ca}.tk-prio.low{background:#f1f2f6;color:#6b7280}
.tk-dead{opacity:.6}
</style>
<div class="tk-kpis">
    <?= $chip('Needs action', $k['needs'] ?? 0, 'fa-inbox', 'warn', 'tickets?assignee=') ?>
    <?= $chip('Unassigned', $k['unassigned'] ?? 0, 'fa-user-plus', 'info', 'tickets?assignee=none') ?>
    <?= $chip('High / urgent', $k['urgent'] ?? 0, 'fa-fire', 'danger', 'tickets?priority=urgent') ?>
    <?= $chip('Awaiting estate', $k['waiting'] ?? 0, 'fa-hourglass-half', 'mute', 'tickets?status=awaiting_customer') ?>
</div>
<form class="card-custom p-3 mb-3 row g-2 align-items-end" method="get" id="tkFilter">
  <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label><select name="status" class="form-select form-select-sm"><option value="">Open queue</option><?php foreach (Support::STATUSES as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-1">Category</label><select name="category" class="form-select form-select-sm"><option value="">All</option><?php foreach (Support::CATEGORIES as $s): ?><option value="<?= $s ?>" <?= $cat === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-1">Priority</label><select name="priority" class="form-select form-select-sm"><option value="">Any</option><?php foreach (Support::PRIORITIES as $s): ?><option value="<?= $s ?>" <?= $prio === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-1">Assignee</label><select name="assignee" class="form-select form-select-sm"><option value="">Anyone</option><option value="me" <?= $asg === 'me' ? 'selected' : '' ?>>Me</option><option value="none" <?= $asg === 'none' ? 'selected' : '' ?>>Unassigned</option><?php foreach ($team as $u): ?><option value="<?= $u['id'] ?>" <?= (string)$asg === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-8 col-md-2"><label class="form-label small mb-1">Search</label><input name="q" class="form-control form-control-sm" placeholder="No., subject, estate" value="<?= e($q) ?>"></div>
  <div class="col-4 col-md-2"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
  <div class="col-12"><div class="form-check small"><input class="form-check-input" type="checkbox" name="show_deleted" value="1" id="tkDel" <?= $showDeleted ? 'checked' : '' ?> onchange="this.form.submit()"><label class="form-check-label text-secondary" for="tkDel">Show tickets from deleted / archived estates (read-only)</label></div></div>
  <?php if (!empty($_GET['estate_id'])): ?><input type="hidden" name="estate_id" value="<?= intval($_GET['estate_id']) ?>"><?php endif; ?>
</form>
<div class="card-custom"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Ticket</th><th>Estate</th><th class="d-none d-md-table-cell">Category</th><th>Priority</th><th>Status</th><th class="d-none d-lg-table-cell">Assignee</th><th class="d-none d-md-table-cell">Updated</th></tr></thead><tbody>
<?php $n = 0; while ($t = $res->fetch_assoc()): $n++; $dead = in_array($t['estate_status'], ['deleted', 'archived'], true); ?>
<tr class="tk-row <?= $dead ? 'tk-dead' : '' ?>" tabindex="0" data-href="ticket?id=<?= intval($t['id']) ?>" role="link" aria-label="Open ticket <?= e($t['ticket_no']) ?>">
  <td><a class="fw-semibold text-decoration-none" href="ticket?id=<?= intval($t['id']) ?>"><?= e($t['ticket_no']) ?></a><div class="small text-secondary text-truncate" style="max-width:340px"><?= e($t['subject']) ?></div></td>
  <td class="small"><a href="estate?id=<?= intval($t['estate_id']) ?>" class="text-decoration-none"><?= e($t['estate_name']) ?></a><?= $dead ? ' <span class="badge text-bg-dark">removed</span>' : '' ?></td>
  <td class="small d-none d-md-table-cell"><?= e(ucwords(str_replace('_', ' ', $t['category']))) ?></td>
  <td><span class="tk-prio <?= e($t['priority']) ?>"><?= e($t['priority']) ?></span></td>
  <td><?= sa_badge($t['status']) ?></td>
  <td class="small d-none d-lg-table-cell"><?= $t['assignee_name'] ? e($t['assignee_name']) : '<span class="text-secondary">Unassigned</span>' ?></td>
  <td class="small d-none d-md-table-cell"><?= sa_dt($t['updated_at']) ?></td></tr>
<?php endwhile; if (!$n) echo '<tr><td colspan="7" class="text-center text-secondary py-5"><i class="fa-solid fa-circle-check text-success fs-3 d-block mb-2"></i>No tickets match.</td></tr>'; ?></tbody></table></div></div>
<?= sa_pager($total, $lim, $pg) ?>
<script>
document.querySelectorAll('tr.tk-row').forEach(function (r) {
  r.addEventListener('click', function (ev) { if (ev.target.closest('a,button,input,select')) return; location.href = r.dataset.href; });
  r.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' && !ev.target.closest('a,button')) location.href = r.dataset.href; });
});
</script>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
