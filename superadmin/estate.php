<?php
// superadmin/estate.php - one estate: lifecycle, subscription, module overrides, billing, support, audit, operations
$page_title = 'Estate';
require_once __DIR__ . '/includes/super_header.php';
require_once __DIR__ . '/../includes/Lifecycle.php';
require_once __DIR__ . '/../includes/Support.php';
require_once __DIR__ . '/../includes/TenantStats.php';

$id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
$estate = Tenancy::find($id);
if (!$estate) { redirectWithFlash('estates', null, 'Estate not found.'); }
$base = "estate?id=$id";
$tab = preg_replace('/[^a-z]/', '', $_GET['tab'] ?? 'overview');

// ------------------------------------------------------------------ actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $a = $_POST['action'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    try {
        switch ($a) {
            case 'update_profile':
                sa_require_role(['superadmin']);
                require_once __DIR__ . '/includes/estate_profile.php';
                $updated = sa_update_estate_profile($platform, $id, $_POST);
                redirectWithFlash($base, "Estate '$updated' updated successfully!");
                break;
            case 'override':
                sa_require_role(['superadmin']);
                $k = $_POST['module_key'] ?? ''; $state = $_POST['state'] ?? 'clear';
                $m = $platform->prepare("SELECT 1 FROM modules WHERE module_key = ?"); $m->bind_param('s', $k); $m->execute();
                if (!$m->get_result()->num_rows) throw new RuntimeException('Unknown module.');
                $before = $platform->query("SELECT state FROM estate_module_overrides WHERE estate_id = $id AND module_key = '" . $platform->real_escape_string($k) . "'")->fetch_assoc();
                if ($state === 'clear') { $d = $platform->prepare("DELETE FROM estate_module_overrides WHERE estate_id = ? AND module_key = ?"); $d->bind_param('is', $id, $k); $d->execute(); }
                else {
                    if (!in_array($state, ['on', 'off'], true)) throw new RuntimeException('Bad state.');
                    $u = $platform->prepare("INSERT INTO estate_module_overrides (estate_id, module_key, state, reason, set_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE state = VALUES(state), reason = VALUES(reason), set_by = VALUES(set_by)");
                    $u->bind_param('isssi', $id, $k, $state, $reason, $SA['id']); $u->execute();
                }
                Entitlements::flush($id); Tenancy::mirror($id);
                Audit::log('module.estate_override', ['estate_id' => $id, 'module' => 'modules', 'entity_type' => 'module', 'entity_id' => $k, 'details' => "Override for $k: " . ($before['state'] ?? 'none') . ' -> ' . $state . ($reason ? " ($reason)" : ''), 'before' => ['state' => $before['state'] ?? null], 'after' => ['state' => $state]]);
                redirectWithFlash("$base&tab=modules", "Module override saved for $k.");
            case 'feature_override':
                sa_require_role(['superadmin']);
                $catalog = FeatureCatalog::all();
                $cur = FeatureCatalog::overrides($id);
                $changed = 0;
                foreach (($_POST['fo'] ?? []) as $slug => $state) {
                    if (!isset($catalog[$slug]) || !in_array($state, ['on', 'off', 'clear'], true)) continue;
                    $old = $cur[$slug] ?? 'clear';
                    if ($old === $state) continue;
                    if ($state === 'clear') { $d = $platform->prepare("DELETE FROM estate_feature_overrides WHERE estate_id = ? AND feature_slug = ?"); $d->bind_param('is', $id, $slug); $d->execute(); }
                    else {
                        $u = $platform->prepare("INSERT INTO estate_feature_overrides (estate_id, feature_slug, state, reason, set_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE state = VALUES(state), reason = VALUES(reason), set_by = VALUES(set_by)");
                        $u->bind_param('isssi', $id, $slug, $state, $reason, $SA['id']); $u->execute();
                    }
                    Audit::log('feature.estate_override', ['estate_id' => $id, 'module' => 'modules', 'entity_type' => 'feature', 'entity_id' => $slug, 'details' => "Feature override for $slug: $old -> $state" . ($reason ? " ($reason)" : ''), 'before' => ['state' => $old], 'after' => ['state' => $state]]);
                    $changed++;
                }
                Entitlements::flush($id); Tenancy::mirror($id);
                redirectWithFlash("$base&tab=modules", $changed ? "$changed feature override(s) saved." : 'No feature changes.');
            case 'suspend':
                sa_require_role(['superadmin']);
                if (!sa_confirmed($estate['name'])) throw new RuntimeException('Type the estate name to confirm suspension.');
                Lifecycle::suspend($id, 'Suspended by platform admin' . ($reason ? ": $reason" : ''));
                redirectWithFlash($base, 'Estate suspended. Data is preserved for the retention period.');
            case 'reactivate':
                sa_require_role(['superadmin']);
                Lifecycle::reactivate($id, $reason ?: 'manual reactivation', max(1, intval($_POST['days'] ?? 30)));
                redirectWithFlash($base, 'Estate reactivated.');
            case 'extend_trial':
                sa_require_role(['superadmin']);
                $d = max(1, min(90, intval($_POST['days'] ?? 7)));
                $platform->query("UPDATE estates SET trial_ends_at = GREATEST(COALESCE(trial_ends_at, NOW()), NOW()) + INTERVAL $d DAY WHERE id = $id AND status = 'trial'");
                Audit::log('estate.trial_extended', ['estate_id' => $id, 'module' => 'estates', 'details' => "Trial extended by $d days. $reason"]);
                redirectWithFlash($base, "Trial extended by $d days.");
            case 'request_deletion':
                sa_require_role(['superadmin']);
                if (!sa_confirmed('DELETE ' . $estate['name'])) throw new RuntimeException('Confirmation phrase did not match.');
                Lifecycle::requestDeletion($id, $SA['id'], $reason);
                redirectWithFlash($base, 'Estate queued for deletion. Permanent deletion still needs approval after the final warning period.');
            case 'approve_deletion':
                sa_require_role(['superadmin']);
                if (!sa_confirmed('PERMANENTLY DELETE ' . $estate['name'])) throw new RuntimeException('Confirmation phrase did not match.');
                Lifecycle::approveAndDelete($id, $SA['id']);
                redirectWithFlash('estates', 'Estate permanently deleted. A final backup and the financial records were kept.');
            case 'cancel_deletion':
                sa_require_role(['superadmin']);
                $platform->query("UPDATE deletion_requests SET status='rejected' WHERE estate_id = $id AND status IN ('awaiting_approval','approved')");
                EstateService::setStatus($id, 'retention', ['deletion_scheduled_at' => null], 'Deletion cancelled' . ($reason ? ": $reason" : ''));
                redirectWithFlash($base, 'Deletion cancelled; estate returned to retention.');
            case 'backup':
                $b = BackupService::backup($id, 'manual', $SA['id']);
                redirectWithFlash("$base&tab=backups", 'Backup created (' . round($b['size_bytes'] / 1024) . ' KB).');
            case 'resend_verification':
                EstateService::resendVerification($estate['central_admin_email']);
                redirectWithFlash($base, 'Verification email re-sent.');
            case 'set_plan': // manual plan assignment (no payment) e.g. for sponsored estates
                sa_require_role(['superadmin']);
                $pid = intval($_POST['plan_id']); $cy = $_POST['cycle'] === 'annual' ? 'annual' : 'monthly'; $days = max(1, intval($_POST['days'] ?? 30));
                $plan = Plans::get($pid); if (!$plan) throw new RuntimeException('Plan not found.');
                $platform->query("UPDATE subscriptions SET status='cancelled', cancelled_at=NOW() WHERE estate_id = $id AND status IN ('pending_payment')");
                $snap = json_encode(Plans::snapshot($pid) + ['addons' => []]); $v = intval($plan['version']); $lim = intval($plan['resident_limit']);
                $until = date('Y-m-d H:i:s', time() + $days * 86400);
                $st = $platform->prepare("INSERT INTO subscriptions (estate_id, plan_id, plan_version, plan_snapshot_json, billing_cycle, status, resident_limit, period_start, period_end, next_billing_date) VALUES (?,?,?,?,?,'active',?,NOW(),?,?)");
                $st->bind_param('iiississ', $id, $pid, $v, $snap, $cy, $lim, $until, $until); $st->execute();
                $platform->query("UPDATE subscriptions SET status='cancelled' WHERE estate_id = $id AND id <> " . intval($platform->insert_id) . " AND status IN ('active','renewed','grace_period','past_due','suspended')");
                $platform->query("UPDATE estates SET current_subscription_id = " . intval($platform->insert_id ?: 0) . " WHERE id = $id");
                $newSub = Billing::currentSubscription($id); $platform->query("UPDATE estates SET current_subscription_id = " . intval($newSub['id']) . " WHERE id = $id");
                EstateService::setStatus($id, 'active', ['trial_ends_at' => null], "Plan assigned manually: {$plan['name']} - $reason");
                Billing::event($id, $newSub['id'], 'subscription_change', "Plan assigned by platform admin: {$plan['name']} ($cy) until " . date('j M Y', strtotime($until)));
                Audit::log('subscription.assigned_manually', ['estate_id' => $id, 'module' => 'billing', 'details' => "{$plan['name']} $cy for $days days. $reason"]);
                Entitlements::flush($id);
                redirectWithFlash("$base&tab=billing", 'Plan assigned.');
            case 'credit':
                sa_require_role(['superadmin', 'finance']);
                Billing::addCredit($id, abs(floatval($_POST['amount'])), $reason ?: 'Manual credit', $SA['id']);
                redirectWithFlash("$base&tab=billing", 'Credit added; it will be applied to the next invoice.');
            case 'refund':
                sa_require_role(['superadmin', 'finance']);
                if (!$reason) throw new RuntimeException('A reason is required for a refund record.');
                Billing::recordRefund($id, intval($_POST['invoice_id']) ?: null, abs(floatval($_POST['amount'])), $reason, $SA['email']);
                redirectWithFlash("$base&tab=billing", 'Refund recorded in the ledger.');
            case 'notes':
                $st = $platform->prepare("UPDATE estates SET notes = ? WHERE id = ?"); $n = trim($_POST['notes'] ?? ''); $st->bind_param('si', $n, $id); $st->execute();
                Audit::log('estate.notes_updated', ['estate_id' => $id, 'module' => 'estates', 'details' => 'Internal notes updated']);
                redirectWithFlash($base, 'Notes saved.');
            case 'ticket_status':
                $tid = intval($_POST['ticket_id']); $ts = $_POST['status'] ?? 'open';
                if (in_array($ts, ['open', 'in_progress', 'awaiting_customer', 'resolved', 'closed'], true)) $platform->query("UPDATE support_tickets SET status = '$ts' WHERE id = $tid AND estate_id = $id");
                redirectWithFlash("$base&tab=support", 'Ticket updated.');
        }
    } catch (Throwable $ex) { redirectWithFlash($base . ($tab !== 'overview' ? "&tab=$tab" : ''), null, $ex->getMessage()); }
}

$estate = Tenancy::find($id);
$sub = Billing::currentSubscription($id);
$residents = Billing::residentCount($estate);
$page_title = $estate['name'];
require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';
$tabs = ['overview' => 'Overview', 'modules' => 'Modules', 'billing' => 'Billing', 'support' => 'Support', 'audit' => 'Activity', 'backups' => 'Backups'];
$daysLeft = Lifecycle::retentionDaysLeft($estate);
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div><h4 class="mb-0"><?= e($estate['name']) ?> <?= sa_badge($estate['status']) ?></h4>
        <div class="small text-secondary">Code <code><?= e($estate['domain_prefix']) ?></code> &middot; registered <?= sa_d($estate['created_at']) ?> &middot; <?= e(EstateService::statusLabel($estate['onboarding_status'])) ?></div></div>
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-primary btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#editEstateModal">
            <i class="fa-solid fa-pen-to-square me-1"></i> Edit Estate Details
        </button>
        <?php if (!empty($estate['db_name']) && !in_array($estate['status'], ['deleted', 'archived'])): ?>
        <button class="btn btn-warning btn-sm fw-semibold" data-bs-toggle="collapse" data-bs-target="#impBox">
            <i class="fa-solid fa-right-to-bracket me-1"></i> Enter as Central Admin
        </button>
        <?php endif; ?>
    </div>
</div>
<div class="collapse mb-3" id="impBox"><form method="post" action="impersonate" class="card-custom p-3 d-flex flex-wrap gap-2 align-items-end"><input type="hidden" name="estate_id" value="<?= $id ?>">
    <div class="flex-grow-1"><label class="form-label small mb-1">Reason (audit log)</label><input class="form-control form-control-sm" name="reason" required placeholder="e.g. onboarding support ticket TK-..."></div><button class="btn btn-warning btn-sm">Enter</button></form></div>

<ul class="nav nav-pills mb-3 flex-nowrap overflow-auto"><?php foreach ($tabs as $k => $l): ?><li class="nav-item"><a class="nav-link py-1 <?= $tab === $k ? 'active' : '' ?>" href="<?= $base ?>&tab=<?= $k ?>"><?= $l ?></a></li><?php endforeach; ?></ul>

<?php if ($tab === 'overview'): $liveStats = !empty($estate['db_name']) && !in_array($estate['status'], ['deleted', 'archived'], true) ? TenantStats::collect($estate) : null; ?>
<div class="row g-3">
  <div class="col-lg-4"><div class="card-custom p-3 h-100"><h6 class="fw-bold">Subscription</h6>
    <?php if ($sub): $snap = json_decode($sub['plan_snapshot_json'] ?? '', true); ?>
      <dl class="row small mb-0">
        <dt class="col-5">Plan</dt><dd class="col-7"><?= e($sub['plan_name']) ?> (<?= e($sub['billing_cycle']) ?>)</dd>
        <dt class="col-5">Status</dt><dd class="col-7"><?= sa_badge($sub['status']) ?></dd>
        <dt class="col-5">Residents</dt><dd class="col-7"><?= $residents ?><?= $sub['resident_limit'] ? ' of ' . intval($sub['resident_limit']) : ' (unlimited)' ?></dd>
        <dt class="col-5">Period</dt><dd class="col-7"><?= sa_d($sub['period_start']) ?> – <?= sa_d($sub['period_end']) ?></dd>
        <dt class="col-5">Next billing</dt><dd class="col-7"><?= sa_d($sub['next_billing_date']) ?></dd>
        <?php if ($sub['grace_ends_at']): ?><dt class="col-5">Grace ends</dt><dd class="col-7 text-warning"><?= sa_dt($sub['grace_ends_at']) ?></dd><?php endif; ?>
        <?php if ($sub['pending_plan_id']): ?><dt class="col-5">Scheduled change</dt><dd class="col-7"><?= e(Plans::get($sub['pending_plan_id'])['name']) ?> on <?= sa_d($sub['pending_effective_at']) ?></dd><?php endif; ?>
      </dl>
    <?php else: ?><p class="text-secondary small mb-0">No subscription yet. <?php if ($estate['status'] === 'trial'): ?>Trial ends <?= sa_d($estate['trial_ends_at']) ?>.<?php endif; ?></p><?php endif; ?>
  </div></div>
  <div class="col-lg-4"><div class="card-custom p-3 h-100">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h6 class="fw-bold mb-0">Tenant Database</h6>
      <?php if ($liveStats): ?>
        <span class="badge bg-<?= $liveStats['db_ok'] ? 'success' : 'danger' ?>-subtle text-<?= $liveStats['db_ok'] ? 'success' : 'danger' ?> rounded-pill fw-semibold">
          <i class="fa-solid fa-circle text-<?= $liveStats['db_ok'] ? 'success' : 'danger' ?> me-1" style="font-size:7px;"></i><?= $liveStats['db_ok'] ? 'Live' : 'Unreachable' ?>
        </span>
      <?php endif; ?>
    </div>
    <?php if ($liveStats): ?>
      <?php if (!$liveStats['db_ok']): ?>
        <div class="alert alert-danger py-2 small mb-2"><?= e($liveStats['db_error'] ?: 'Connection failed') ?></div>
      <?php endif; ?>
      <dl class="row small mb-0">
        <dt class="col-6">Active Residents</dt><dd class="col-6 fw-bold"><?= intval($liveStats['residents']) ?></dd>
        <dt class="col-6">Central Admins</dt><dd class="col-6"><?= intval($liveStats['admins']) ?></dd>
        <dt class="col-6">Staff Accounts</dt><dd class="col-6"><?= intval($liveStats['staff']) ?></dd>
        <dt class="col-6">Open Maintenance</dt><dd class="col-6"><?= intval($liveStats['open_maintenance']) ?></dd>
        <dt class="col-6">Unpaid Invoices</dt><dd class="col-6"><?= intval($liveStats['unpaid_invoices']) ?></dd>
        <dt class="col-6">Last Login</dt><dd class="col-6"><?= sa_dt($liveStats['last_login_at']) ?></dd>
      </dl>
    <?php else: ?>
      <p class="text-secondary small mb-0">No tenant database environment provisioned.</p>
    <?php endif; ?>
  </div></div>
  <div class="col-lg-4"><div class="card-custom p-3 h-100"><h6 class="fw-bold">Lifecycle</h6>
    <dl class="row small mb-0">
      <dt class="col-5">Central Admin</dt><dd class="col-7"><?= e($estate['central_admin_email'] ?: $estate['contact_email']) ?></dd>
      <dt class="col-5">Environment</dt><dd class="col-7"><?= $estate['db_name'] ? '<code>' . e($estate['db_name']) . '</code>' : 'none' ?></dd>
      <?php if ($estate['suspended_at']): ?><dt class="col-5">Suspended</dt><dd class="col-7"><?= sa_dt($estate['suspended_at']) ?></dd><?php endif; ?>
      <?php if ($estate['retention_ends_at'] && in_array($estate['status'], ['suspended', 'retention', 'deletion_pending'])): ?><dt class="col-5">Retention</dt><dd class="col-7 text-danger fw-semibold"><?= $daysLeft ?> day(s) left (until <?= sa_d($estate['retention_ends_at']) ?>)</dd><?php endif; ?>
      <?php if ($estate['status'] === 'deletion_pending'): ?><dt class="col-5">Earliest deletion</dt><dd class="col-7"><?= sa_dt($estate['deletion_scheduled_at']) ?></dd><?php endif; ?>
      <?php if ($estate['trial_ends_at']): ?><dt class="col-5">Trial ends</dt><dd class="col-7"><?= sa_dt($estate['trial_ends_at']) ?></dd><?php endif; ?>
      <dt class="col-5">Phone</dt><dd class="col-7"><?= e($estate['contact_phone']) ?></dd>
      <dt class="col-5">Location</dt><dd class="col-7"><?= e(trim($estate['address'] . ', ' . $estate['city'] . ', ' . $estate['state'], ', ')) ?></dd>
    </dl>
  </div></div>
  <div class="col-12"><div class="card-custom p-3"><h6 class="fw-bold">Operations <span class="text-secondary small fw-normal">(all logged)</span></h6>
    <div class="d-flex flex-wrap gap-2">
    <?php $nm = e($estate['name']); $can = $SA['role'] === 'superadmin'; ?>
    <?php if ($can && in_array($estate['status'], ['active', 'trial', 'grace_period', 'onboarding'])): ?><button class="btn btn-outline-danger btn-sm" onclick="saConfirm({action:'<?= $base ?>',title:'Suspend estate',message:'Residents, staff and admins will lose access immediately. Data is preserved for the retention period.',phrase:<?= json_encode($estate['name']) ?>,button:'Suspend',fields:{action:'suspend',id:'<?= $id ?>'}})">Suspend</button><?php endif; ?>
    <?php if ($can && in_array($estate['status'], ['suspended', 'retention', 'deletion_pending', 'trial', 'onboarding'])): ?><button class="btn btn-outline-success btn-sm" onclick="saConfirm({action:'<?= $base ?>',title:'Reactivate estate',message:'Grants a complimentary period and restores access. Normal reactivation happens when the estate pays.',button:'Reactivate',fields:{action:'reactivate',id:'<?= $id ?>',days:'30'}})">Reactivate (30 days)</button><?php endif; ?>
    <?php if ($can && $estate['status'] === 'trial'): ?><button class="btn btn-outline-secondary btn-sm" onclick="saConfirm({action:'<?= $base ?>',title:'Extend trial',message:'Extend the trial by 7 days?',button:'Extend',fields:{action:'extend_trial',id:'<?= $id ?>',days:'7'}})">Extend trial +7d</button><?php endif; ?>
    <?php if ($estate['status'] === 'pending' || $estate['onboarding_status'] === 'email_verification_pending'): ?><form method="post" class="d-inline"><input type="hidden" name="action" value="resend_verification"><button class="btn btn-outline-secondary btn-sm">Resend verification</button></form><?php endif; ?>
    <form method="post" class="d-inline"><input type="hidden" name="action" value="backup"><button class="btn btn-outline-secondary btn-sm" <?= $estate['db_name'] ? '' : 'disabled' ?>>Backup now</button></form>
    <?php if ($can && in_array($estate['status'], ['suspended', 'retention'])): ?><button class="btn btn-outline-danger btn-sm" onclick="saConfirm({action:'<?= $base ?>',title:'Queue for deletion',message:'Starts the final warning period. Nothing is deleted until you approve after that.',phrase:<?= json_encode('DELETE ' . $estate['name']) ?>,button:'Queue deletion',fields:{action:'request_deletion',id:'<?= $id ?>'}})">Queue deletion</button><?php endif; ?>
    <?php if ($can && $estate['status'] === 'deletion_pending'): ?>
      <button class="btn btn-danger btn-sm" onclick="saConfirm({action:'<?= $base ?>',title:'Permanently delete estate',message:'A final backup is taken, then the estate database is destroyed. Invoices, payments and audit records are kept. This cannot be undone.',phrase:<?= json_encode('PERMANENTLY DELETE ' . $estate['name']) ?>,button:'Delete permanently',fields:{action:'approve_deletion',id:'<?= $id ?>'}})">Approve &amp; delete permanently</button>
      <form method="post" class="d-inline"><input type="hidden" name="action" value="cancel_deletion"><button class="btn btn-outline-secondary btn-sm">Cancel deletion</button></form>
    <?php endif; ?>
    </div>
    <form method="post" class="mt-3"><input type="hidden" name="action" value="notes"><label class="form-label small">Internal notes (never shown to the estate)</label><textarea class="form-control form-control-sm" name="notes" rows="2"><?= e($estate['notes']) ?></textarea><button class="btn btn-sm btn-light border mt-2">Save notes</button></form>
  </div></div>
</div>

<?php elseif ($tab === 'modules'): $mx = Entitlements::matrix($id); ?>
<div class="alert alert-info small">Decision order: <strong>Global OFF</strong> always wins → <strong>estate override</strong> (this screen) → <strong>subscription plan</strong> (+ purchased add-ons). Estate lifecycle also gates access (suspended estates have none).</div>
<div class="card-custom"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Module</th><th class="d-none d-md-table-cell">Global</th><th>In plan</th><th>Override</th><th>Result</th><th></th></tr></thead><tbody>
<?php foreach ($mx as $m): ?><tr>
  <td class="fw-semibold"><?= e($m['name']) ?><div class="small text-secondary d-md-none"><?= $m['global_enabled'] ? '' : 'Globally disabled' ?></div></td>
  <td class="d-none d-md-table-cell"><?= $m['global_enabled'] ? sa_badge('on') : sa_badge('off') ?></td>
  <td><?= $m['in_plan'] ? 'Yes' : ($m['addon'] ? 'Add-on' : '<span class="text-secondary">No</span>') ?></td>
  <td><?= $m['override'] ? sa_badge($m['override']) : '<span class="text-secondary">—</span>' ?></td>
  <td><?= $m['allowed'] ? sa_badge('on') : sa_badge('off') ?><div class="small text-secondary"><?= e(Entitlements::sourceLabel($m['source'])) ?></div></td>
  <td class="text-end"><?php if ($SA['role'] === 'superadmin'): ?><form method="post" class="d-flex gap-1 justify-content-end"><input type="hidden" name="action" value="override"><input type="hidden" name="module_key" value="<?= e($m['module_key']) ?>">
      <select name="state" class="form-select form-select-sm" style="width:auto"><option value="on">Force ON</option><option value="off">Force OFF</option><option value="clear" selected>Follow plan</option></select>
      <input name="reason" class="form-control form-control-sm d-none d-lg-block" style="width:150px" placeholder="Reason"><button class="btn btn-sm btn-primary">Save</button></form><?php endif; ?></td>
</tr><?php endforeach; ?></tbody></table></div></div>

<?php
require_once dirname(__DIR__) . '/includes/FeatureGrid.php';
$fOv = FeatureCatalog::overrides($id); $fAllowed = []; $fSrc = [];
foreach (FeatureCatalog::all() as $fs => $_f) { $dec = Entitlements::featureDecide($id, $fs); $fAllowed[$fs] = $dec['allowed']; $fSrc[$fs] = $dec['source']; }
?>
<h6 class="fw-bold mt-4">Features by module group</h6>
<p class="small text-secondary">Ticked = the estate can currently use it (plan + overrides). The estate can only assign ticked features to its roles; the rest are faint and locked for them. Use the dropdown to force a feature on or off for this estate only.</p>
<form method="post">
  <input type="hidden" name="action" value="feature_override">
  <?= render_feature_grid(FeatureCatalog::grouped(), [
      'name' => 'seen', 'readonly' => true, 'checked' => $fAllowed,
      'extra' => function ($f) use ($fOv, $fSrc, $SA) {
          $s = $fOv[$f['slug']] ?? 'clear';
          $h = '<br><small class="text-secondary">' . e(Entitlements::sourceLabel($fSrc[$f['slug']])) . '</small>';
          if ($SA['role'] === 'superadmin') {
              $h .= '<select name="fo[' . e($f['slug']) . ']" class="form-select form-select-sm mt-1" style="width:auto" onclick="event.stopPropagation()">'
                  . '<option value="clear"' . ($s === 'clear' ? ' selected' : '') . '>Follow plan</option>'
                  . '<option value="on"' . ($s === 'on' ? ' selected' : '') . '>Force ON</option>'
                  . '<option value="off"' . ($s === 'off' ? ' selected' : '') . '>Force OFF</option></select>';
          }
          return $h;
      },
  ]) ?>
  <?php if ($SA['role'] === 'superadmin'): ?><div class="d-flex gap-2 mt-3"><input name="reason" class="form-control form-control-sm" style="max-width:320px" placeholder="Reason (recorded in audit log)"><button class="btn btn-primary btn-sm">Save feature overrides</button></div><?php endif; ?>
</form>

<?php elseif ($tab === 'billing'): $h = Billing::history($id); $plans = $platform->query("SELECT id,name FROM plans WHERE status='active' ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC); ?>
<div class="row g-3 mb-3">
 <?php if ($SA['role'] === 'superadmin'): ?><div class="col-lg-6"><form method="post" class="card-custom p-3"><input type="hidden" name="action" value="set_plan"><h6 class="fw-bold">Assign plan manually (no payment)</h6>
   <div class="row g-2"><div class="col-6"><select name="plan_id" class="form-select form-select-sm"><?php foreach ($plans as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
   <div class="col-3"><select name="cycle" class="form-select form-select-sm"><option>monthly</option><option>annual</option></select></div><div class="col-3"><input name="days" type="number" value="30" min="1" class="form-control form-control-sm" title="days"></div>
   <div class="col-12"><input name="reason" class="form-control form-control-sm" placeholder="Reason (audit)" required></div><div class="col-12"><button class="btn btn-sm btn-primary">Assign</button></div></div></form></div><?php endif; ?>
 <div class="col-lg-6"><form method="post" class="card-custom p-3"><input type="hidden" name="action" value="credit"><h6 class="fw-bold">Add credit</h6>
   <div class="row g-2"><div class="col-4"><input name="amount" type="number" step="0.01" min="0.01" class="form-control form-control-sm" placeholder="Amount" required></div><div class="col-8"><input name="reason" class="form-control form-control-sm" placeholder="Reason" required></div><div class="col-12"><button class="btn btn-sm btn-outline-primary">Add credit</button></div></div></form></div>
</div>
<div class="card-custom mb-3"><div class="p-3 pb-0"><h6 class="fw-bold">Invoices</h6></div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Invoice</th><th>Type</th><th>Total</th><th>Status</th><th>Due</th><th></th></tr></thead><tbody>
<?php foreach ($h['invoices'] as $i): ?><tr><td><?= e($i['invoice_no']) ?></td><td class="small"><?= e($i['kind']) ?></td><td><?= sa_money($i['total']) ?></td><td><?= sa_badge($i['status']) ?></td><td class="small"><?= sa_d($i['due_date']) ?></td>
  <td><?php if ($i['status'] === 'paid' && $SA['role'] !== 'support'): ?><button class="btn btn-sm btn-link text-danger p-0" onclick="saConfirm({action:'<?= $base ?>&tab=billing',title:'Record refund for <?= e($i['invoice_no']) ?>',message:'Appends a refund entry to the immutable ledger.',button:'Record refund',fields:{action:'refund',id:'<?= $id ?>',invoice_id:'<?= $i['id'] ?>',amount:'<?= $i['total'] ?>'}})">Refund</button><?php endif; ?></td></tr><?php endforeach; if (!$h['invoices']) echo '<tr><td colspan="6" class="text-secondary">No invoices.</td></tr>'; ?></tbody></table></div></div>
<div class="card-custom"><div class="p-3 pb-0"><h6 class="fw-bold">Billing history</h6></div><div class="table-responsive"><table class="table table-sm mb-0"><tbody>
<?php foreach ($h['events'] as $ev): ?><tr><td class="small text-secondary text-nowrap"><?= sa_dt($ev['created_at']) ?></td><td class="small"><?= e($ev['title']) ?></td><td class="small text-end"><?= $ev['amount'] !== null ? sa_money($ev['amount']) : '' ?></td></tr><?php endforeach; ?></tbody></table></div></div>

<?php elseif ($tab === 'support'): $tickets = Support::forEstate($id); ?>
<div class="card-custom"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Ticket</th><th>Subject</th><th>Priority</th><th>Status</th><th>Assignee</th><th>Updated</th></tr></thead><tbody>
<?php foreach ($tickets as $t): ?>
<tr style="cursor:pointer;" onclick="location.href='ticket?id=<?= intval($t['id']) ?>'" title="Open ticket <?= e($t['ticket_no']) ?>">
  <td><a class="fw-semibold text-decoration-none" href="ticket?id=<?= intval($t['id']) ?>" onclick="event.stopPropagation();"><?= e($t['ticket_no']) ?></a></td>
  <td><?= e($t['subject']) ?></td>
  <td><span class="badge text-bg-<?= $t['priority'] === 'urgent' ? 'danger' : ($t['priority'] === 'high' ? 'warning' : 'secondary') ?> rounded-pill"><?= e(ucfirst($t['priority'])) ?></span></td>
  <td><?= sa_badge($t['status']) ?></td>
  <td class="small"><?= !empty($t['assignee_name']) ? e($t['assignee_name']) : '<span class="text-secondary">Unassigned</span>' ?></td>
  <td class="small"><?= sa_dt($t['updated_at']) ?></td>
</tr>
<?php endforeach; if (!$tickets) echo '<tr><td colspan="6" class="text-secondary text-center py-4">No tickets recorded for this estate.</td></tr>'; ?>
</tbody></table></div></div>

<?php elseif ($tab === 'audit'): $r = $platform->query("SELECT * FROM platform_audit_logs WHERE estate_id = $id ORDER BY id DESC LIMIT 100"); ?>
<div class="card-custom"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Details</th></tr></thead><tbody>
<?php while ($x = $r->fetch_assoc()): ?><tr><td class="small text-nowrap"><?= sa_dt($x['created_at']) ?></td><td class="small"><?= e($x['actor_name']) ?><div class="text-secondary" style="font-size:.7rem"><?= e($x['actor_role']) ?></div></td><td class="small"><code><?= e($x['action']) ?></code></td><td class="small"><?= e($x['details']) ?></td></tr><?php endwhile; ?></tbody></table></div></div>
<a class="btn btn-sm btn-link" href="audit?estate_id=<?= $id ?>">Open full audit log</a>

<?php elseif ($tab === 'backups'): $r = $platform->query("SELECT * FROM backups WHERE estate_id = $id ORDER BY id DESC LIMIT 50"); ?>
<div class="card-custom"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Created</th><th>Kind</th><th>Size</th><th>Status</th><th></th></tr></thead><tbody>
<?php while ($b = $r->fetch_assoc()): ?><tr><td class="small"><?= sa_dt($b['created_at']) ?></td><td><?= e($b['kind']) ?></td><td class="small"><?= round($b['size_bytes'] / 1024) ?> KB</td><td><?= sa_badge($b['status']) ?></td>
 <td><?php if ($b['status'] === 'ok' && $SA['role'] === 'superadmin' && $estate['db_name']): ?><button class="btn btn-sm btn-outline-danger" onclick="saConfirm({action:'backups',title:'Restore backup #<?= $b['id'] ?>',message:'The CURRENT database of this estate will be replaced by this backup (a safety backup is taken first).',phrase:<?= json_encode('RESTORE ' . $estate['domain_prefix']) ?>,button:'Restore',fields:{action:'restore',backup_id:'<?= $b['id'] ?>',estate_id:'<?= $id ?>'}})">Restore</button><?php endif; ?></td></tr><?php endwhile; ?></tbody></table></div></div>
<?php endif; ?>

<!-- Edit Estate Details Modal -->
<div class="modal fade" id="editEstateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header border-bottom p-3.5">
                <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2">
                    <i class="fa-solid fa-pen-to-square text-primary"></i>
                    <span>Edit Estate: <span class="text-primary"><?= e($estate['name']) ?></span></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_profile">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label small fw-semibold">Estate Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" value="<?= e($estate['name']) ?>" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold">Estate Code / Prefix <span class="text-danger">*</span></label>
                            <input type="text" name="domain_prefix" class="form-control font-monospace" value="<?= e($estate['domain_prefix']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Contact Email</label>
                            <input type="email" name="contact_email" class="form-control" value="<?= e($estate['contact_email']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Contact Phone</label>
                            <input type="text" name="contact_phone" class="form-control" value="<?= e($estate['contact_phone']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">City</label>
                            <input type="text" name="city" class="form-control" value="<?= e($estate['city']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">State</label>
                            <input type="text" name="state" class="form-control" value="<?= e($estate['state'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Country</label>
                            <input type="text" name="country" class="form-control" value="<?= e($estate['country'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Organisation type</label>
                            <input type="text" name="organisation_type" class="form-control" value="<?= e($estate['organisation_type'] ?? '') ?>" placeholder="e.g. Residential estate">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Custom domain</label>
                            <input type="text" name="custom_domain" class="form-control font-monospace" value="<?= e($estate['custom_domain'] ?? '') ?>" placeholder="portal.example.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Trial ends</label>
                            <input type="datetime-local" name="trial_ends_at" class="form-control" value="<?= !empty($estate['trial_ends_at']) ? e(date('Y-m-d\TH:i', strtotime($estate['trial_ends_at']))) : '' ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Status</label>
                            <select name="status" class="form-select">
                                <?php foreach (EstateService::ESTATE_STATUSES as $st_opt): ?>
                                    <option value="<?= $st_opt ?>" <?= $estate['status'] === $st_opt ? 'selected' : '' ?>><?= e(EstateService::statusLabel($st_opt)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Address</label>
                            <input type="text" name="address" class="form-control" value="<?= e($estate['address']) ?>" placeholder="Physical street address">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Internal Notes</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Administrative remarks, billing notes..."><?= e($estate['notes']) ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top p-3 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary px-3 fw-semibold">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
