<?php
// includes/dashboard_subscription.php - Central Admin dashboard block: subscription, usage, billing, modules, tickets, setup tasks.
// Estate-scoped by construction (reads only the current estate's control-plane rows). Hidden from non-admin roles.
if (!in_array($_SESSION['role'] ?? '', ['admin', 'manager'], true)) return;
require_once __DIR__ . '/SubscriptionStatus.php';
$__s = SubscriptionStatus::summary();
if (!$__s) return;
$__sym = PlatformSettings::get('currency_symbol', '₦');
$__sub = $__s['sub'];
$__enabled = count(array_filter(Entitlements::matrix($__s['estate']['id']), fn($m) => $m['allowed']));
$__pct = $__s['limit'] > 0 ? min(100, round($__s['residents'] / $__s['limit'] * 100)) : 0;
$__todo = array_filter(SubscriptionStatus::checklist(), fn($c) => !$c[1]);
$__statusLabel = ucwords(str_replace('_', ' ', $__s['status']));
?>
<div class="row g-3 mb-4">
  <div class="col-12 col-md-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
    <div class="small text-muted">Subscription</div>
    <div class="fs-5 fw-bold"><?php echo $__sub ? htmlspecialchars($__sub['plan_name']) : ($__s['status'] === 'trial' ? 'Free trial' : 'No plan yet'); ?></div>
    <div class="small"><?php echo htmlspecialchars($__statusLabel); ?><?php echo $__sub ? ' · ' . ucfirst($__sub['billing_cycle']) : ''; ?></div>
    <a class="small" href="subscription">Manage plan</a>
  </div></div></div>
  <div class="col-12 col-md-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
    <div class="small text-muted">Resident usage</div>
    <div class="fs-5 fw-bold"><?php echo number_format($__s['residents']); ?><span class="text-muted fs-6"> / <?php echo $__s['limit'] ? number_format($__s['limit']) : '∞'; ?></span></div>
    <?php if ($__s['limit']): ?><div class="progress mt-2" style="height:6px"><div class="progress-bar <?php echo $__pct >= 90 ? 'bg-danger' : ($__pct >= 75 ? 'bg-warning' : ''); ?>" style="width:<?php echo $__pct; ?>%"></div></div><?php endif; ?>
  </div></div></div>
  <div class="col-12 col-md-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
    <div class="small text-muted">Billing</div>
    <?php if ($__s['open_invoice']): ?>
      <div class="fs-5 fw-bold"><?php echo $__sym . number_format($__s['open_invoice']['total'], 2); ?></div><div class="small text-danger">due <?php echo date('j M Y', strtotime($__s['open_invoice']['due_date'])); ?></div><a class="small" href="subscription">Pay now</a>
    <?php elseif ($__sub && $__sub['next_billing_date']): ?>
      <div class="fs-5 fw-bold"><?php echo date('j M Y', strtotime($__sub['next_billing_date'])); ?></div><div class="small text-muted">next billing date · <?php echo htmlspecialchars(ucfirst($__s['payment_status'])); ?></div>
    <?php else: ?><div class="fs-5 fw-bold">—</div><div class="small text-muted"><?php echo $__s['trial_days_left'] !== null ? $__s['trial_days_left'] . ' trial day(s) left' : 'Nothing due'; ?></div><?php endif; ?>
  </div></div></div>
  <div class="col-12 col-md-6 col-xl-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
    <div class="small text-muted">Modules &amp; support</div>
    <div class="fs-5 fw-bold"><?php echo $__enabled; ?> enabled</div>
    <div class="small"><a href="support"><?php echo intval($__s['open_tickets']); ?> open ticket(s)</a></div>
  </div></div></div>
  <?php if ($__todo): ?>
  <div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body d-flex flex-wrap gap-3 align-items-center">
    <strong class="me-2">To finish setting up:</strong>
    <?php foreach ($__todo as [$label, $done, $link]): ?><a class="badge rounded-pill text-bg-light border text-decoration-none py-2 px-3" href="<?php echo $link ?: 'onboarding'; ?>"><?php echo htmlspecialchars($label); ?> →</a><?php endforeach; ?>
  </div></div></div>
  <?php endif; ?>
</div>
