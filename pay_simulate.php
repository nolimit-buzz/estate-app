<?php
// pay_simulate.php - LOCAL DEVELOPMENT ONLY payment simulator (used when no Paystack key is configured and APP_ENV=local).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Billing.php';
require_once __DIR__ . '/includes/public_layout.php';
if (!Billing::simulationAllowed()) { http_response_code(404); exit('Not found'); }

$ref = preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['ref'] ?? $_POST['ref'] ?? '');
$p = Tenancy::platform();
$st = $p->prepare("SELECT p.*, i.invoice_no, e.name estate_name, e.domain_prefix FROM payments p JOIN invoices i ON i.id = p.invoice_id JOIN estates e ON e.id = p.estate_id WHERE p.reference = ? AND p.gateway = 'simulator' AND p.entry_type = 'payment'");
$st->bind_param('s', $ref); $st->execute();
$pay = $st->get_result()->fetch_assoc();
if (!$pay) { http_response_code(404); exit('Unknown payment reference'); }

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $pay['status'] === 'pending') {
    $outcome = $_POST['outcome'] ?? 'cancel';
    $map = ['success' => ['success', null], 'fail' => ['failed', 'Card declined (simulated)'], 'cancel' => ['cancelled', 'Cancelled by payer']];
    [$status, $why] = $map[$outcome] ?? $map['cancel'];
    Billing::applyPaymentResult($ref, $status, 'SIM-' . strtoupper(bin2hex(random_bytes(4))), $status === 'success' ? $pay['amount'] : null, ['simulated' => true], $why);
    $estate = Tenancy::find($pay['estate_id']);
    $_SESSION['flash_success'] = $status === 'success' ? 'Payment received. Your subscription is active.' : null;
    if ($status !== 'success') $_SESSION['flash_error'] = 'Payment was not completed.';
    header('Location: ' . Notifier::estateUrl($estate, 'admin/subscription'));
    exit;
}
public_head('Payment simulator');
public_nav('simple');
?>
<div class="auth-shell"><div></div><main class="auth-main"><div class="form-card narrow">
    <span class="eyebrow">Simulator &middot; local only</span>
    <h1>Pay <?php echo pl_e(Billing::money($pay['amount'])); ?></h1>
    <p class="muted"><?php echo pl_e($pay['estate_name']); ?> &middot; invoice <?php echo pl_e($pay['invoice_no']); ?><br>Reference <code><?php echo pl_e($ref); ?></code></p>
    <?php if ($pay['status'] !== 'pending'): ?><div class="alert info">This payment is already <?php echo pl_e($pay['status']); ?>.</div><?php else: ?>
    <form method="post"><input type="hidden" name="ref" value="<?php echo pl_e($ref); ?>">
        <button class="btn btn-primary btn-block" name="outcome" value="success">Simulate successful payment</button>
        <button class="btn btn-ghost btn-block" style="margin-top:10px" name="outcome" value="fail">Simulate failed payment</button>
        <button class="btn btn-ghost btn-block" style="margin-top:10px" name="outcome" value="cancel">Cancel</button>
    </form><?php endif; ?>
</div></main></div></body></html>
