<?php
// admin/subscription.php - Central Admin: plan, usage, modules, invoices, payment, plan change
require_once '../config.php';
require_once '../includes/auth_guard.php';
requireAdminAccess();
require_once '../includes/SubscriptionStatus.php';
if (!in_array($_SESSION['role'] ?? '', ['admin', 'manager'], true)) { header('Location: index'); exit; }

$estate = Tenancy::find(Tenancy::currentId());
$eid = intval($estate['id']);
$adminEmail = $_SESSION['email'] ?? ($conn->query("SELECT email FROM users WHERE id = " . intval($_SESSION['user_id']))->fetch_assoc()['email'] ?? '');
$error = ''; $preview = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        if ($_SESSION['role'] !== 'admin' && !is_impersonating_estate()) throw new RuntimeException('Only the Central Admin can change billing.');
        if ($a === 'select_plan') {
            $res = Billing::selectPlan($eid, intval($_POST['plan_id']), $_POST['cycle'] ?? 'monthly', (array)($_POST['addons'] ?? []));
            $url = Billing::startPayment($res['invoice'], $adminEmail);
            header('Location: ' . $url); exit;
        } elseif ($a === 'pay') {
            $inv = Billing::invoice(intval($_POST['invoice_id']));
            if (!$inv || intval($inv['estate_id']) !== $eid) throw new RuntimeException('Invoice not found.');
            header('Location: ' . Billing::startPayment($inv, $adminEmail)); exit;
        } elseif ($a === 'preview_change') {
            $preview = Billing::planChangePreview($eid, intval($_POST['plan_id']), $_POST['cycle'] ?? 'monthly', (array)($_POST['addons'] ?? []));
            $preview['plan_id'] = intval($_POST['plan_id']); $preview['cycle_in'] = $_POST['cycle'] ?? 'monthly';
        } elseif ($a === 'confirm_change') {
            $r = Billing::requestPlanChange($eid, intval($_POST['plan_id']), $_POST['cycle'] ?? 'monthly', (array)($_POST['addons'] ?? []));
            if ($r['invoice'] && $r['invoice']['status'] === 'pending') { header('Location: ' . Billing::startPayment($r['invoice'], $adminEmail)); exit; }
            redirectWithFlash('subscription', $r['scheduled'] ? 'Plan change scheduled for the end of your current billing period.' : 'Plan changed.');
        } elseif ($a === 'cancel_change') {
            Tenancy::platform()->query("UPDATE subscriptions SET pending_plan_id=NULL, pending_cycle=NULL, pending_effective_at=NULL WHERE estate_id = $eid");
            logAudit($conn, 'Plan change cancelled', 'Billing', 'Scheduled plan change cancelled by Central Admin');
            redirectWithFlash('subscription', 'Scheduled change cancelled.');
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$S = SubscriptionStatus::summary();
$sub = $S['sub']; $snap = $S['snapshot'];
$hist = Billing::history($eid);
$plans = Billing::publicPlans();
$matrix = Entitlements::matrix($eid);
$pct = $S['limit'] > 0 ? min(100, round($S['residents'] / $S['limit'] * 100)) : 0;
$sym = PlatformSettings::get('currency_symbol', '₦');
$canPick = !$sub || in_array($sub['status'], ['pending_payment', 'cancelled', 'expired']) || in_array($S['status'], ['trial', 'onboarding', 'pending']);
$changing = $sub && in_array($sub['status'], ['active', 'renewed', 'grace_period', 'past_due']);
$suspended = in_array($S['status'], ['suspended', 'retention', 'deletion_pending']);

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="page-header-futuristic mb-4"><div>
    <h1 class="page-title">Subscription &amp; Billing</h1>
    <p class="page-subtitle">Your plan, usage, enabled modules and payment history.</p></div></div>

<?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($suspended): ?>
<div class="alert alert-danger"><strong>Your estate is suspended.</strong> Residents and staff cannot sign in. Your data is safe and will be kept until <strong><?php echo date('j M Y', strtotime($S['estate']['retention_ends_at'])); ?></strong> (<?php echo intval($S['retention_days_left']); ?> days left). Pay below to restore everything instantly, or <a href="export">export your data</a>.</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-lg-7"><div class="card border-0 shadow-sm h-100"><div class="card-body">
    <h6 class="fw-bold mb-3">Current subscription</h6>
    <?php if ($sub): ?>
    <div class="row small g-2">
      <div class="col-6"><div class="text-muted">Plan</div><div class="fw-bold fs-5"><?php echo htmlspecialchars($sub['plan_name']); ?></div></div>
      <div class="col-6"><div class="text-muted">Billing cycle</div><div class="fw-bold"><?php echo ucfirst($sub['billing_cycle']); ?></div></div>
      <div class="col-6"><div class="text-muted">Subscription status</div><div class="fw-bold"><?php echo ucwords(str_replace('_', ' ', $sub['status'])); ?></div></div>
      <div class="col-6"><div class="text-muted">Payment status</div><div class="fw-bold"><?php echo ucfirst($S['payment_status']); ?></div></div>
      <div class="col-6"><div class="text-muted">Current period</div><div><?php echo $sub['period_start'] ? date('j M Y', strtotime($sub['period_start'])) . ' – ' . date('j M Y', strtotime($sub['period_end'])) : '—'; ?></div></div>
      <div class="col-6"><div class="text-muted">Next billing / renewal</div><div class="fw-bold"><?php echo $sub['next_billing_date'] ? date('j M Y', strtotime($sub['next_billing_date'])) : '—'; ?></div></div>
      <?php if ($S['status'] === 'grace_period'): ?><div class="col-12"><div class="alert alert-warning py-2 mb-0">Grace period ends <strong><?php echo date('j M Y', strtotime($S['estate']['grace_ends_at'])); ?></strong> (<?php echo intval($S['grace_days_left']); ?> days).</div></div><?php endif; ?>
      <?php if ($sub['pending_plan_id']): $np = Plans::get($sub['pending_plan_id']); ?><div class="col-12"><div class="alert alert-info py-2 mb-0 d-flex justify-content-between align-items-center">Scheduled change to <strong><?php echo htmlspecialchars($np['name']); ?></strong> on <?php echo date('j M Y', strtotime($sub['pending_effective_at'])); ?>
        <form method="post" class="m-0"><button class="btn btn-sm btn-outline-secondary" name="action" value="cancel_change">Cancel</button></form></div></div><?php endif; ?>
    </div>
    <?php else: ?>
      <p class="mb-1"><?php echo $S['status'] === 'trial' ? "You are on a free trial ({$S['trial_days_left']} day(s) left)." : 'No plan selected yet.'; ?> Choose a plan below.</p>
    <?php endif; ?>
    <hr>
    <div class="d-flex justify-content-between small"><span>Resident usage</span><strong><?php echo number_format($S['residents']); ?><?php echo $S['limit'] ? ' of ' . number_format($S['limit']) : ' (unlimited)'; ?></strong></div>
    <?php if ($S['limit']): ?><div class="progress mt-1" style="height:8px"><div class="progress-bar <?php echo $pct >= 90 ? 'bg-danger' : ($pct >= 75 ? 'bg-warning' : 'bg-primary'); ?>" style="width:<?php echo $pct; ?>%"></div></div><?php endif; ?>
  </div></div></div>

  <div class="col-lg-5"><div class="card border-0 shadow-sm h-100"><div class="card-body">
    <h6 class="fw-bold mb-3">Amount due</h6>
    <?php if ($S['open_invoice']): $oi = $S['open_invoice']; ?>
      <div class="fs-3 fw-bold"><?php echo $sym . number_format($oi['total'], 2); ?></div>
      <div class="small text-muted mb-3">Invoice <?php echo htmlspecialchars($oi['invoice_no']); ?> · due <?php echo date('j M Y', strtotime($oi['due_date'])); ?><?php echo $oi['status'] === 'failed' ? ' · last attempt failed' : ''; ?></div>
      <form method="post"><input type="hidden" name="invoice_id" value="<?php echo $oi['id']; ?>"><button class="btn btn-primary w-100" name="action" value="pay"><?php echo $suspended ? 'Pay to reactivate' : 'Pay now'; ?></button></form>
      <?php if (Billing::simulationAllowed()): ?><div class="small text-muted mt-2">Local mode: payment is simulated.</div><?php endif; ?>
    <?php else: ?><p class="text-muted mb-0">Nothing due right now.<?php if ($sub && $sub['next_billing_date']): ?> Your next invoice is issued before <?php echo date('j M Y', strtotime($sub['next_billing_date'])); ?>.<?php endif; ?></p><?php endif; ?>
  </div></div></div>
</div>

<?php if ($preview): ?>
<div class="card border-0 shadow-sm mb-4"><div class="card-body">
  <h6 class="fw-bold">Confirm plan change to <?php echo htmlspecialchars($preview['new']['plan']['name']); ?> (<?php echo $preview['new']['cycle']; ?>)</h6>
  <ul class="small">
    <li>Effective: <strong><?php echo $preview['mode'] === 'immediate' ? 'immediately' : 'at the end of your current period (' . date('j M Y', strtotime($preview['effective'])) . ')'; ?></strong></li>
    <li>Amount to pay now: <strong><?php echo $sym . number_format($preview['charge'], 2); ?></strong><?php echo $preview['mode'] === 'immediate' ? ' (price difference' . (PlatformSettings::bool('proration_enabled', true) ? ', prorated' : '') . ')' : ''; ?></li>
    <li>Resident limit: <?php echo $preview['new']['plan']['resident_limit'] ? number_format($preview['new']['plan']['resident_limit']) : 'unlimited'; ?> (you currently have <?php echo $preview['residents']; ?>)</li>
    <?php if ($preview['gained_modules']): ?><li class="text-success">Modules gained: <?php echo htmlspecialchars(implode(', ', $preview['gained_modules'])); ?></li><?php endif; ?>
    <?php if ($preview['lost_modules']): ?><li class="text-danger">Modules you will lose: <?php echo htmlspecialchars(implode(', ', $preview['lost_modules'])); ?></li><?php endif; ?>
    <?php foreach ($preview['problems'] as $pb): ?><li class="text-danger fw-bold"><?php echo htmlspecialchars($pb); ?></li><?php endforeach; ?>
  </ul>
  <?php if (!$preview['problems']): ?><form method="post"><input type="hidden" name="plan_id" value="<?php echo $preview['plan_id']; ?>"><input type="hidden" name="cycle" value="<?php echo htmlspecialchars($preview['cycle_in']); ?>">
    <?php foreach ($_POST['addons'] ?? [] as $ad): ?><input type="hidden" name="addons[]" value="<?php echo htmlspecialchars($ad); ?>"><?php endforeach; ?>
    <button class="btn btn-primary" name="action" value="confirm_change"><?php echo $preview['mode'] === 'immediate' ? 'Confirm & pay' : 'Confirm change'; ?></button> <a href="subscription" class="btn btn-light">Cancel</a></form><?php endif; ?>
</div></div>
<?php endif; ?>

<?php if ($canPick || $changing): ?>
<div class="card border-0 shadow-sm mb-4"><div class="card-body">
  <h6 class="fw-bold mb-1"><?php echo $changing ? 'Change plan' : 'Choose your plan'; ?></h6>
  <p class="small text-muted"><?php echo $changing ? 'Upgrades apply immediately (you pay the difference); downgrades start at the end of your billing period.' : 'Pick the plan that fits your number of residents. Payment activates your modules automatically.'; ?></p>
  <div class="row g-3">
  <?php $rec = Billing::recommendPlan($S['residents']); foreach ($plans as $pl):
      $fits = !intval($pl['resident_limit']) || intval($pl['resident_limit']) >= $S['residents']; $isCur = $sub && intval($sub['plan_id']) === intval($pl['id']);
      $opt = array_filter($pl['modules'], fn($m) => $m['inclusion'] === 'optional'); ?>
    <div class="col-md-4"><form method="post" class="border rounded-3 p-3 h-100 <?php echo $rec && $rec['id'] == $pl['id'] ? 'border-primary' : ''; ?>">
      <input type="hidden" name="plan_id" value="<?php echo $pl['id']; ?>">
      <div class="fw-bold"><?php echo htmlspecialchars($pl['name']); ?> <?php echo $isCur ? '<span class="badge bg-success">current</span>' : ($rec && $rec['id'] == $pl['id'] ? '<span class="badge bg-primary">recommended</span>' : ''); ?></div>
      <div class="small text-muted mb-2"><?php echo $pl['resident_limit'] ? 'Up to ' . number_format($pl['resident_limit']) . ' residents' : 'Unlimited residents'; ?></div>
      <select name="cycle" class="form-select form-select-sm mb-2"><option value="monthly"><?php echo $sym . number_format($pl['monthly_price']); ?> / month</option><option value="annual"><?php echo $sym . number_format($pl['annual_price']); ?> / year</option></select>
      <?php if ($opt): ?><div class="small mb-2"><div class="text-muted">Optional add-ons (monthly price):</div><?php foreach ($opt as $o): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="addons[]" value="<?php echo htmlspecialchars($o['module_key']); ?>" id="ad<?php echo $pl['id'] . $o['module_key']; ?>"><label class="form-check-label" for="ad<?php echo $pl['id'] . $o['module_key']; ?>"><?php echo htmlspecialchars(getAllPlatformModules()[$o['module_key']]['name'] ?? $o['module_key']); ?> (+<?php echo $sym . number_format($o['price']); ?>)</label></div><?php endforeach; ?></div><?php endif; ?>
      <?php if (!$fits): ?><div class="small text-danger mb-2">Too small for your <?php echo $S['residents']; ?> residents.</div><?php endif; ?>
      <button class="btn btn-sm <?php echo $isCur ? 'btn-outline-secondary' : 'btn-primary'; ?> w-100" name="action" value="<?php echo $changing ? 'preview_change' : 'select_plan'; ?>" <?php echo ($fits && !$isCur) || ($changing && !$isCur && $fits) ? '' : 'disabled'; ?>><?php echo $changing ? 'Review change' : 'Select & pay'; ?></button>
    </form></div>
  <?php endforeach; ?></div>
</div></div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-lg-5"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h6 class="fw-bold">Modules</h6><ul class="list-unstyled small mb-0">
    <?php foreach ($matrix as $m): if (!$m['global_enabled']) continue; ?><li class="d-flex justify-content-between py-1 border-bottom"><span><?php echo htmlspecialchars($m['name']); ?></span><span class="<?php echo $m['allowed'] ? 'text-success' : 'text-muted'; ?>"><?php echo $m['allowed'] ? 'Enabled' : 'Not in plan'; ?></span></li><?php endforeach; ?></ul></div></div></div>
  <div class="col-lg-7"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h6 class="fw-bold">Invoices</h6><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Invoice</th><th>Type</th><th>Total</th><th>Status</th><th>Date</th></tr></thead><tbody>
    <?php foreach ($hist['invoices'] as $i): ?><tr><td><?php echo htmlspecialchars($i['invoice_no']); ?></td><td class="small"><?php echo htmlspecialchars($i['kind']); ?></td><td><?php echo $sym . number_format($i['total'], 2); ?></td><td><?php echo ucfirst($i['status']); ?></td><td class="small"><?php echo date('j M Y', strtotime($i['created_at'])); ?></td></tr><?php endforeach; if (!$hist['invoices']) echo '<tr><td colspan="5" class="text-muted">No invoices yet.</td></tr>'; ?></tbody></table></div></div></div></div>
</div>

<div class="card border-0 shadow-sm mb-5"><div class="card-body"><h6 class="fw-bold">Billing history</h6><div class="table-responsive" style="max-height:340px;overflow:auto"><table class="table table-sm mb-0"><tbody>
<?php foreach ($hist['events'] as $ev): ?><tr><td class="small text-muted text-nowrap"><?php echo date('j M Y H:i', strtotime($ev['created_at'])); ?></td><td class="small"><?php echo htmlspecialchars($ev['title']); ?></td><td class="small text-end"><?php echo $ev['amount'] !== null ? $sym . number_format($ev['amount'], 2) : ''; ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
<?php include '../includes/footer.php'; ?>
