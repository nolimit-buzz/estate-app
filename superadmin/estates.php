<?php
// superadmin/estates.php - every registered estate with lifecycle, plan, usage and payment state
require_once __DIR__ . '/includes/super_auth.php';

// Handle Quick Edit Estate Submission (validated, shared with estate.php)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['update_estate_quick'])) {
    sa_require_role(['superadmin']);
    require_once __DIR__ . '/includes/estate_profile.php';
    try {
        $updated = sa_update_estate_profile($platform, intval($_POST['estate_id'] ?? 0), $_POST);
        redirectWithFlash('estates', "Estate '$updated' details updated successfully!");
    } catch (Throwable $ex) {
        redirectWithFlash('estates', null, $ex->getMessage());
    }
}

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
    LEFT JOIN estate_stats st ON st.estate_id = e.id
    $where";
$run = function ($q) use ($platform, $types, $vals) { $st = $platform->prepare($q); if ($types) $st->bind_param($types, ...$vals); $st->execute(); return $st->get_result(); };

$cols = "e.id, e.name, e.domain_prefix, e.contact_email, e.contact_phone, e.city, e.address, e.notes, e.status, e.onboarding_status, e.resident_count, e.created_at, e.trial_ends_at, e.retention_ends_at, e.db_name, p.name plan_name, s.billing_cycle, s.status sub_status, s.resident_limit, s.next_billing_date, pm.has_pending, pm.failed, st.db_ok, st.db_error";
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
<thead><tr><th>Estate</th><th>Status</th><th class="d-none d-md-table-cell">Onboarding</th><th>Plan</th><th class="d-none d-lg-table-cell">Residents</th><th class="d-none d-lg-table-cell">Payment</th><th class="d-none d-md-table-cell">Registered</th><th class="text-end">Actions</th></tr></thead><tbody>
<?php while ($x = $res->fetch_assoc()): ?>
<tr onclick="window.location.href='estate?id=<?= $x['id'] ?>'" style="cursor: pointer;" title="Click to view and manage <?= e($x['name']) ?>">
  <td><a class="fw-semibold text-decoration-none text-dark" href="estate?id=<?= $x['id'] ?>" onclick="event.stopPropagation();"><?= e($x['name']) ?></a>
    <div class="small text-secondary font-monospace"><?= e($x['domain_prefix']) ?>
      <?php if (empty($x['db_name'])): ?>
        <span class="badge bg-secondary-subtle text-secondary rounded-pill ms-1" style="font-size:0.65rem;">No DB</span>
      <?php elseif (isset($x['db_ok']) && $x['db_ok'] == 0): ?>
        <span class="badge bg-danger-subtle text-danger rounded-pill ms-1" style="font-size:0.65rem;"><i class="fa-solid fa-triangle-exclamation me-1"></i>DB Error</span>
      <?php else: ?>
        <span class="badge bg-success-subtle text-success rounded-pill ms-1" style="font-size:0.65rem;"><i class="fa-solid fa-circle text-success me-1" style="font-size:5px;"></i>DB Live</span>
      <?php endif; ?>
    </div></td>
  <td><?= sa_badge($x['status']) ?><?php if ($x['status'] === 'trial' && $x['trial_ends_at']): ?><div class="small text-secondary">ends <?= sa_d($x['trial_ends_at']) ?></div><?php endif; ?><?php if ($x['retention_ends_at'] && in_array($x['status'], ['suspended','retention'])): ?><div class="small text-danger"><?= max(0, (int)ceil((strtotime($x['retention_ends_at']) - time()) / 86400)) ?> days retention left</div><?php endif; ?></td>
  <td class="d-none d-md-table-cell small"><?= e(EstateService::statusLabel($x['onboarding_status'])) ?></td>
  <td><?= $x['plan_name'] ? e($x['plan_name']) . '<div class="small text-secondary">' . e($x['billing_cycle']) . '</div>' : '<span class="text-secondary">—</span>' ?></td>
  <td class="d-none d-lg-table-cell"><?= intval($x['resident_count']) ?><?= $x['resident_limit'] ? ' / ' . intval($x['resident_limit']) : '' ?></td>
  <td class="d-none d-lg-table-cell"><?= $x['sub_status'] ? sa_badge($x['sub_status']) : '—' ?><?php if ($x['failed']): ?> <span class="badge text-bg-danger"><?= intval($x['failed']) ?> failed</span><?php endif; ?></td>
  <td class="d-none d-md-table-cell small text-secondary"><?= sa_d($x['created_at']) ?></td>
  <td class="text-end" onclick="event.stopPropagation();">
    <div class="d-inline-flex gap-1">
      <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-2.5 py-0.5 small fw-semibold" data-bs-toggle="modal" data-bs-target="#quickEditEstateModal" onclick="openEditEstateModal(<?= htmlspecialchars(json_encode($x), ENT_QUOTES) ?>)" title="Quick Edit Estate">
        <i class="fa-solid fa-pen-to-square"></i> Edit
      </button>
      <a href="estate?id=<?= $x['id'] ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-2.5 py-0.5 small" title="Inspect & Configure Estate">
        <i class="fa-solid fa-gear"></i> Manage
      </a>
    </div>
  </td>
</tr>
<?php endwhile; if (!$total): ?><tr><td colspan="8" class="text-secondary text-center py-4">No estates match.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?= sa_pager($total, $lim, $pg) ?>

<!-- Quick Edit Estate Modal -->
<div class="modal fade" id="quickEditEstateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header border-bottom p-3.5">
                <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2">
                    <i class="fa-solid fa-pen-to-square text-primary"></i>
                    <span>Edit Estate: <span id="quickEditEstateTitle" class="text-primary"></span></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="update_estate_quick" value="1">
                <input type="hidden" name="estate_id" id="quickEditEstateId">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label small fw-semibold">Estate Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="quickEditEstateName" class="form-control" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold">Estate Code / Prefix <span class="text-danger">*</span></label>
                            <input type="text" name="domain_prefix" id="quickEditEstatePrefix" class="form-control font-monospace" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Contact Email</label>
                            <input type="email" name="contact_email" id="quickEditEstateEmail" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Contact Phone</label>
                            <input type="text" name="contact_phone" id="quickEditEstatePhone" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">City</label>
                            <input type="text" name="city" id="quickEditEstateCity" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Status</label>
                            <select name="status" id="quickEditEstateStatus" class="form-select">
                                <option value="active">Active</option>
                                <option value="trial">Trial</option>
                                <option value="grace_period">Grace Period</option>
                                <option value="suspended">Suspended</option>
                                <option value="retention">Retention</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Address</label>
                            <input type="text" name="address" id="quickEditEstateAddress" class="form-control" placeholder="Physical street address">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Internal Notes</label>
                            <textarea name="notes" id="quickEditEstateNotes" class="form-control" rows="2" placeholder="Administrative remarks, account history..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top p-3 d-flex justify-content-between">
                    <a href="#" id="quickEditEstateManageLink" class="btn btn-sm btn-outline-secondary">
                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Open Full Estate Manager
                    </a>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary px-3 fw-semibold">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditEstateModal(estate) {
    if (typeof estate === 'string') {
        try { estate = JSON.parse(estate); } catch(e) {}
    }
    const el = document.getElementById('quickEditEstateModal');
    if (!el) return;
    document.getElementById('quickEditEstateId').value = estate.id || '';
    document.getElementById('quickEditEstateTitle').textContent = estate.name || '';
    document.getElementById('quickEditEstateName').value = estate.name || '';
    document.getElementById('quickEditEstatePrefix').value = estate.domain_prefix || '';
    document.getElementById('quickEditEstateEmail').value = estate.contact_email || '';
    document.getElementById('quickEditEstatePhone').value = estate.contact_phone || '';
    document.getElementById('quickEditEstateCity').value = estate.city || '';
    document.getElementById('quickEditEstateAddress').value = estate.address || '';
    document.getElementById('quickEditEstateStatus').value = estate.status || 'active';
    document.getElementById('quickEditEstateNotes').value = estate.notes || '';
    document.getElementById('quickEditEstateManageLink').href = 'estate?id=' + (estate.id || '');

    if (window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(el).show();
    } else if (window.openModal) {
        window.openModal('quickEditEstateModal');
    }
}
</script>

<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
