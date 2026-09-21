<?php
// resident/finance.php
require_once '../config.php';
require_once '../includes/Paystack.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index");
    exit;
}

$user_id = intval($_SESSION['user_id']);
$estate_id = get_estate_id();

// Fetch Resident Info
$user_res = $conn->query("SELECT * FROM users WHERE id = $user_id AND estate_id = $estate_id");
$user = $user_res->fetch_assoc();

// Fetch Resident's Zone and Dedicated Paystack Virtual Account
$res_zone = $conn->query("
    SELECT z.id as zone_id, z.name as zone_name, z.code as zone_code,
           z.paystack_bank_name, z.paystack_account_number, z.paystack_account_name, z.paystack_subaccount_code 
    FROM residents r
    JOIN flats f ON r.flat_id = f.id
    JOIN buildings b ON f.building_id = b.id
    JOIN streets s ON b.street_id = s.id
    JOIN zones z ON s.zone_id = z.id
    WHERE r.user_id = $user_id AND r.estate_id = $estate_id AND r.status = 'active'
    LIMIT 1
")->fetch_assoc();

// Fetch Paystack Public Key
$paystack = new Paystack($conn);
$paystack_public = $paystack->getPublicKey();

// Handle Manual Bank Transfer Proof Upload (Offline Payment)
$message = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_bank_transfer'])) {
    $invoice_id = intval($_POST['invoice_id']);
    $ref = $conn->real_escape_string($_POST['transfer_ref']);
    $amount = floatval($_POST['amount']);
    
    // Create pending payment record (status column)
    $conn->query("INSERT INTO payments (estate_id, user_id, invoice_id, amount, type, payment_method, status, payment_reference, transaction_ref, description) 
                  VALUES ($estate_id, $user_id, $invoice_id, $amount, 'Estate Charge', 'bank_transfer', 'pending', '$ref', '$ref', 'Manual Bank Transfer Proof Pending Admin Approval')");
    $conn->query("UPDATE invoices SET status = 'pending' WHERE id = $invoice_id AND estate_id = $estate_id");
    
    logAudit($conn, "Bank Transfer Proof Submitted", "Finance", "User $user_id submitted transfer ref: $ref for Invoice #$invoice_id");
    $message = "Bank transfer reference submitted successfully! Admin will verify and issue your receipt.";
}

// Fetch Invoices with Zonal Paystack Remittance Account Details and Billing Settings
$invoices_res = $conn->query("
    SELECT i.*, 
           ec.allow_installments as charge_allow_installments,
           z.id as zone_id, z.name as zone_name, z.code as zone_code,
           COALESCE(z.paystack_bank_name, '" . ($res_zone['paystack_bank_name'] ?? '') . "') as paystack_bank_name,
           COALESCE(z.paystack_account_number, '" . ($res_zone['paystack_account_number'] ?? '') . "') as paystack_account_number,
           COALESCE(z.paystack_account_name, '" . ($res_zone['paystack_account_name'] ?? '') . "') as paystack_account_name,
           COALESCE(z.paystack_subaccount_code, '" . ($res_zone['paystack_subaccount_code'] ?? '') . "') as paystack_subaccount_code,
           COALESCE(zs.allow_installments, 1) as zone_allow_installments,
           COALESCE(zs.min_first_payment_percent, 40) as min_first_payment_percent,
           COALESCE(zs.max_subsequent_payments, 3) as subsequent_payments_count
    FROM invoices i 
    LEFT JOIN estate_charges ec ON i.charge_id = ec.id
    LEFT JOIN flats f ON i.flat_id = f.id 
    LEFT JOIN buildings b ON f.building_id = b.id 
    LEFT JOIN streets s ON b.street_id = s.id 
    LEFT JOIN zones z ON (i.zone_id = z.id OR s.zone_id = z.id)
    LEFT JOIN zonal_billing_settings zs ON zs.zone_id = z.id
    WHERE i.user_id = $user_id AND i.estate_id = $estate_id 
    ORDER BY i.due_date DESC
");

// Fetch Completed Payments & Receipts
$payments_res = $conn->query("SELECT p.*, r.receipt_number, i.invoice_number 
                               FROM payments p 
                               LEFT JOIN receipts r ON r.payment_id = p.id 
                               LEFT JOIN invoices i ON p.invoice_id = i.id
                               WHERE p.user_id = $user_id AND p.estate_id = $estate_id 
                               ORDER BY p.created_at DESC");

// Total Outstanding & Paid
$totals = $conn->query("SELECT 
    COALESCE(SUM(CASE WHEN status != 'paid' THEN balance ELSE 0 END), 0) as outstanding,
    COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) as total_paid
    FROM invoices WHERE user_id = $user_id AND estate_id = $estate_id")->fetch_assoc();

include 'header.php';
include 'sidebar.php';
?>
<script src="https://js.paystack.co/v1/inline.js"></script>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h1 class="h4 font-bold text-slate-900 m-0" style="letter-spacing: -0.02em;">
                <i class="fa-solid fa-file-invoice-dollar text-primary me-2"></i> Bills & Invoices
            </h1>
            <span class="mature-badge mature-badge-sky">Financial Portal</span>
        </div>
        <p class="text-secondary small mb-0">Manage estate assessments, settle outstanding invoices, and access official verified receipts.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="receipts" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
            <i class="fa-solid fa-receipt me-1"></i> My Receipts
        </a>
    </div>
</div>

<div class="d-flex flex-column gap-4">
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert" style="background: rgba(16, 185, 129, 0.12); color: #065f46; border-left: 4px solid #10b981 !important;">
            <i class="fa-solid fa-circle-check me-2"></i> <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ==========================================
         FINANCIAL METRICS RIBBON & VIRTUAL ACCOUNT
         ========================================== -->
    <div class="row g-3">
        <div class="col-12 col-md-4">
            <div class="resident-kpi-card kpi-accent-danger h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="kpi-title">Total Outstanding</span>
                    <div class="kpi-icon-wrap" style="background: rgba(239, 68, 68, 0.1); color: #ef4444; border-color: rgba(239, 68, 68, 0.25);">
                        <i class="fa-solid fa-hourglass-half"></i>
                    </div>
                </div>
                <div class="kpi-value text-danger" style="font-size: 2rem; font-weight: 800; letter-spacing: -0.02em;">
                    ₦<?= number_format($totals['outstanding'], 2) ?>
                </div>
                <div class="kpi-meta justify-content-between mt-2 pt-2 border-top border-light-subtle">
                    <span>Active Dues Balance</span>
                    <span class="mature-badge <?= ($totals['outstanding'] > 0) ? 'mature-badge-crimson' : 'mature-badge-emerald' ?>">
                        <?= ($totals['outstanding'] > 0) ? 'Payment Due' : 'Cleared' ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="resident-kpi-card kpi-accent-success h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="kpi-title">Total Settled Dues</span>
                    <div class="kpi-icon-wrap" style="background: rgba(16, 185, 129, 0.1); color: #10b981; border-color: rgba(16, 185, 129, 0.25);">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                </div>
                <div class="kpi-value text-success" style="font-size: 2rem; font-weight: 800; letter-spacing: -0.02em;">
                    ₦<?= number_format($totals['total_paid'], 2) ?>
                </div>
                <div class="kpi-meta justify-content-between mt-2 pt-2 border-top border-light-subtle">
                    <span>Verified Realized Payments</span>
                    <span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-shield-check me-1"></i>Reconciled</span>
                </div>
            </div>
        </div>

        <?php if (!empty($res_zone['paystack_account_number'])): ?>
        <div class="col-12 col-md-4">
            <div class="resident-kpi-card h-100" style="border-left: 4px solid #0284c7; background: linear-gradient(135deg, rgba(2, 132, 199, 0.04) 0%, rgba(56, 189, 248, 0.08) 100%);">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="kpi-title" style="color: #0369a1;"><i class="fa-solid fa-building-columns me-1"></i> Zone Virtual Account</span>
                    <span class="mature-badge mature-badge-sky"><?= htmlspecialchars($res_zone['zone_name'] ?? 'Zone') ?></span>
                </div>
                <div class="d-flex align-items-center justify-content-between mt-1 mb-2">
                    <div>
                        <div style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 1.55rem; font-weight: 800; color: #0284c7; letter-spacing: 0.06em;">
                            <?= htmlspecialchars($res_zone['paystack_account_number']) ?>
                        </div>
                        <div class="small text-secondary fw-medium">
                            <?= htmlspecialchars($res_zone['paystack_bank_name'] ?? 'Wema Bank (Paystack)') ?> &bull; <?= htmlspecialchars($res_zone['paystack_account_name'] ?? 'Zone') ?>
                        </div>
                    </div>
                    <button type="button" onclick="copyAccountText('<?= htmlspecialchars($res_zone['paystack_account_number']) ?>', this)" class="btn btn-sm btn-primary rounded-pill px-3 fw-semibold">
                        <i class="fa-regular fa-copy me-1"></i> Copy
                    </button>
                </div>
                <div class="kpi-meta pt-2 border-top border-light-subtle small text-secondary">
                    <span>Direct Zone Treasury Remittance</span>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="col-12 col-md-4">
            <div class="resident-kpi-card h-100" style="border-left: 4px solid #8b5cf6;">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="kpi-title">Payment Channels</span>
                    <div class="kpi-icon-wrap" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6; border-color: rgba(139, 92, 246, 0.25);">
                        <i class="fa-solid fa-bolt"></i>
                    </div>
                </div>
                <div class="fw-bold fs-5 text-slate-900 mt-2 mb-1">Instant Online Gateway</div>
                <div class="small text-secondary">Pay instantly using Debit Cards, USSD, Bank Transfer, or NQR via Paystack.</div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ==========================================
         OUTSTANDING & ISSUED INVOICES TABLE
         ========================================== -->
    <div class="resident-glass-panel">
        <div class="resident-card-header">
            <div class="resident-card-title">
                <i class="fa-solid fa-file-invoice-dollar text-primary"></i> Outstanding & Issued Invoices
            </div>
            <span class="mature-badge mature-badge-slate"><?= ($invoices_res) ? $invoices_res->num_rows : 0 ?> Records</span>
        </div>
        <div class="table-responsive">
            <table class="table dashboard-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Charge Title</th>
                        <th>Amount Details</th>
                        <th>Due Date</th>
                        <th>Status</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($invoices_res && $invoices_res->num_rows > 0): ?>
                        <?php while ($inv = $invoices_res->fetch_assoc()): ?>
                            <?php 
                                $inv_display_no = $inv['invoice_number'] ?: ('INV-' . sprintf("%04d", $inv['id'])); 
                                $inv_bank = $inv['paystack_bank_name'] ?: ($res_zone['paystack_bank_name'] ?? '');
                                $inv_acct = $inv['paystack_account_number'] ?: ($res_zone['paystack_account_number'] ?? '');
                                $inv_acct_name = $inv['paystack_account_name'] ?: ($res_zone['paystack_account_name'] ?? '');
                                $inv_subacct = $inv['paystack_subaccount_code'] ?: ($res_zone['paystack_subaccount_code'] ?? '');
                                $inv_zone_name = $inv['zone_name'] ?: ($res_zone['zone_name'] ?? '');
                                $inv_zone_id = $inv['zone_id'] ?: ($res_zone['zone_id'] ?? 0);

                                $inv_total = floatval($inv['amount']);
                                $inv_paid = floatval($inv['amount_paid'] ?? 0);
                                $inv_balance = floatval($inv['balance'] > 0 ? $inv['balance'] : max(0, $inv_total - $inv_paid));
                                $charge_allows_inst = isset($inv['charge_allow_installments']) ? intval($inv['charge_allow_installments']) : 1;
                                $zone_allows_inst = intval($inv['zone_allow_installments'] ?? 1);
                                $can_installments = ($zone_allows_inst && $charge_allows_inst && $inv_balance > 0 && $inv['status'] !== 'paid');
                                $first_pct = floatval($inv['min_first_payment_percent'] ?? 40);

                                $status_badge_class = 'mature-badge-slate';
                                if ($inv['status'] === 'paid') $status_badge_class = 'mature-badge-emerald';
                                elseif ($inv['status'] === 'unpaid' || $inv['status'] === 'overdue') $status_badge_class = 'mature-badge-crimson';
                                elseif ($inv['status'] === 'partially_paid' || $inv['status'] === 'pending') $status_badge_class = 'mature-badge-amber';
                            ?>
                            <tr>
                                <td>
                                    <span class="mature-badge mature-badge-primary font-monospace" style="font-size: 0.78rem;">
                                        <?= htmlspecialchars($inv_display_no) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-semibold text-slate-900"><?= htmlspecialchars($inv['title']) ?></div>
                                    <?php if ($can_installments): ?>
                                        <span class="mature-badge mature-badge-purple mt-1" style="font-size: 0.68rem;">
                                            <i class="fa-solid fa-chart-pie me-1"></i> Installments (<?= intval($first_pct) ?>% 1st)
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-slate-900">₦<?= number_format($inv_total, 2) ?></div>
                                    <?php if ($inv_paid > 0): ?>
                                        <div class="small text-success fw-semibold">Paid: ₦<?= number_format($inv_paid, 2) ?></div>
                                        <div class="small text-danger fw-bold">Bal: ₦<?= number_format($inv_balance, 2) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-secondary">
                                    <i class="fa-regular fa-calendar me-1"></i>
                                    <?= $inv['due_date'] ? date('M j, Y', strtotime($inv['due_date'])) : 'N/A' ?>
                                </td>
                                <td>
                                    <span class="mature-badge <?= $status_badge_class ?>">
                                        <?= htmlspecialchars(str_replace('_', ' ', strtoupper($inv['status']))) ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ($inv['status'] != 'paid'): ?>
                                        <div class="d-inline-flex gap-1 justify-content-end">
                                            <a href="../pay_invoice?id=<?= $inv['id'] ?>" class="btn btn-sm btn-primary rounded-pill px-3 fw-semibold">
                                                <i class="fa-solid fa-credit-card me-1"></i> <?= $can_installments ? 'Pay / Split' : 'Pay Now' ?>
                                            </a>
                                            <button type="button" onclick="payInvoice(<?= $inv['id'] ?>, '<?= htmlspecialchars(addslashes($inv['title'])) ?>', <?= $inv_balance ?>, '<?= htmlspecialchars(addslashes($inv_display_no)) ?>', '<?= htmlspecialchars(addslashes($inv_bank)) ?>', '<?= htmlspecialchars(addslashes($inv_acct)) ?>', '<?= htmlspecialchars(addslashes($inv_acct_name)) ?>', '<?= htmlspecialchars(addslashes($inv_subacct)) ?>', '<?= htmlspecialchars(addslashes($inv_zone_name)) ?>', <?= intval($inv_zone_id) ?>)" class="btn btn-sm btn-light border rounded-circle" style="width: 32px; height: 32px; padding: 0;" title="Quick Pay Channels">
                                                <i class="fa-solid fa-bolt text-warning"></i>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <a href="receipt?invoice_id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-success rounded-pill px-3 fw-semibold">
                                            <i class="fa-solid fa-receipt me-1"></i> Receipt
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center py-4 text-secondary small">No invoices generated for your account yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ==========================================
         PAYMENT HISTORY & SETTLED RECEIPTS TABLE
         ========================================== -->
    <div class="resident-glass-panel">
        <div class="resident-card-header">
            <div class="resident-card-title">
                <i class="fa-solid fa-clock-rotate-left text-primary"></i> Verified Payment History & Proof of Settlement
            </div>
            <a href="receipts" class="btn btn-sm btn-outline-primary rounded-pill px-3" style="font-size: 0.8rem;">
                All Receipts <i class="fa-solid fa-arrow-right ms-1"></i>
            </a>
        </div>
        <div class="table-responsive">
            <table class="table dashboard-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Receipt ID</th>
                        <th>Invoice #</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Paid Date</th>
                        <th>Status</th>
                        <th style="text-align: right;">Receipt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($payments_res && $payments_res->num_rows > 0): ?>
                        <?php while ($pay = $payments_res->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <span class="mature-badge mature-badge-sky font-monospace" style="font-size: 0.75rem;">
                                        <i class="fa-solid fa-receipt me-1"></i><?= htmlspecialchars($pay['receipt_number'] ?? 'Pending') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="font-monospace text-secondary small">
                                        <?= htmlspecialchars($pay['invoice_number'] ?: ($pay['invoice_id'] ? ('INV-' . $pay['invoice_id']) : 'Direct')) ?>
                                    </span>
                                </td>
                                <td class="small fw-semibold text-slate-900"><?= htmlspecialchars($pay['type'] . ' - ' . ($pay['description'] ?? '')) ?></td>
                                <td>
                                    <span class="fw-bold text-success font-monospace">₦<?= number_format($pay['amount'], 2) ?></span>
                                </td>
                                <td>
                                    <span class="mature-badge mature-badge-slate">
                                        <?= htmlspecialchars(strtoupper(str_replace(['paystack_', '_'], ['', ' '], $pay['payment_method']))) ?>
                                    </span>
                                </td>
                                <td class="small text-secondary">
                                    <i class="fa-regular fa-calendar-check me-1"></i>
                                    <?= date('M j, Y h:i A', strtotime($pay['created_at'])) ?>
                                </td>
                                <td>
                                    <span class="mature-badge mature-badge-emerald">
                                        <i class="fa-solid fa-check me-1"></i><?= htmlspecialchars(ucfirst($pay['status'])) ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <?php if (!empty($pay['receipt_number'])): ?>
                                        <a href="receipt?receipt_no=<?= urlencode($pay['receipt_number']) ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3" style="font-size: 0.8rem;">
                                            <i class="fa-solid fa-print me-1"></i> View
                                        </a>
                                    <?php else: ?>
                                        <span class="small text-secondary">Processing</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="text-center py-4 text-secondary small">No payment history recorded yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ==========================================
     MODERN FROSTED GLASS PAY MODAL
     ========================================== -->
<div id="payModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); z-index: 1050; align-items: center; justify-content: center; padding: 1.5rem 1rem;">
    <div class="resident-glass-panel" style="max-width: 520px; width: 100%; border-radius: 20px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); overflow: hidden;">
        <div class="p-4 border-bottom d-flex justify-content-between align-items-start" style="background: rgba(255, 255, 255, 0.4);">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                    <span id="modalInvoiceNumber" class="mature-badge mature-badge-primary font-monospace">INV-0000</span>
                    <span class="mature-badge mature-badge-crimson">Unpaid</span>
                    <span id="modalZoneBadge" class="mature-badge mature-badge-emerald" style="display:none;"></span>
                </div>
                <h3 id="modalTitle" class="h5 font-bold text-slate-900 m-0">Pay Invoice</h3>
            </div>
            <button type="button" onclick="document.getElementById('payModal').style.display='none'" class="btn-close" aria-label="Close"></button>
        </div>
        
        <div class="p-4">
            <!-- Amount Card -->
            <div class="p-3 rounded-3 text-center mb-4 border" style="background: rgba(59, 130, 246, 0.05); border-color: rgba(59, 130, 246, 0.2) !important;">
                <div class="small text-uppercase fw-semibold text-secondary" style="letter-spacing: 0.05em;">Total Amount Due</div>
                <div id="modalAmount" class="fw-bold text-primary mt-1" style="font-size: 2.25rem; font-family: 'Outfit', sans-serif;">₦0.00</div>
            </div>

            <!-- Tab Switcher -->
            <div id="modalTabsContainer" class="d-grid gap-1 p-1 rounded-3 mb-3" style="grid-template-columns: repeat(3, 1fr); background: rgba(15, 23, 42, 0.06);">
                <button type="button" id="tabBtnDVA" onclick="switchPayTab('dva')" class="btn btn-sm fw-bold rounded-2 text-center" style="font-size: 0.8rem; background: white; color: #0f172a; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <i class="fa-solid fa-building-columns text-primary me-1"></i> Zone DVA
                </button>
                <button type="button" id="tabBtnOnline" onclick="switchPayTab('online')" class="btn btn-sm fw-semibold rounded-2 text-center text-secondary" style="font-size: 0.8rem;">
                    <i class="fa-solid fa-bolt text-warning me-1"></i> Paystack
                </button>
                <button type="button" id="tabBtnOffline" onclick="switchPayTab('offline')" class="btn btn-sm fw-semibold rounded-2 text-center text-secondary" style="font-size: 0.8rem;">
                    <i class="fa-solid fa-receipt me-1"></i> Manual
                </button>
            </div>

            <!-- Panel 1: Zone Virtual Bank Account (Paystack DVA) -->
            <div id="payPanelDVA">
                <div class="p-3 rounded-3 mb-3 text-white" style="background: linear-gradient(135deg, #0b1329 0%, #1e293b 100%); border: 1px solid rgba(56, 189, 248, 0.3);">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-uppercase fw-bold text-slate-400" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                            <i class="fa-solid fa-building-columns text-info me-1"></i> Dedicated Virtual Account
                        </span>
                        <span id="dvaBankBadge" class="mature-badge mature-badge-sky">Wema Bank (Paystack DVA)</span>
                    </div>

                    <div class="mb-2">
                        <div class="small text-slate-400 text-uppercase" style="font-size: 0.7rem;">Account Number</div>
                        <div class="d-flex align-items-center justify-content-between mt-1">
                            <span id="dvaAccountNumber" class="font-monospace text-info fw-bold" style="font-size: 1.65rem; letter-spacing: 0.08em;">0000000000</span>
                            <button type="button" onclick="copyDvaAccount(this)" class="btn btn-sm btn-info rounded-pill px-3 fw-bold text-dark" style="font-size: 0.8rem;">
                                <i class="fa-regular fa-copy me-1"></i> Copy
                            </button>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-end pt-2 border-top border-slate-700 small">
                        <div>
                            <div class="text-slate-400" style="font-size: 0.7rem;">Beneficiary Name</div>
                            <div id="dvaAccountName" class="fw-bold text-white">Estate Zone</div>
                        </div>
                        <div class="text-end">
                            <div class="text-slate-400" style="font-size: 0.7rem;">Target Zone</div>
                            <div id="dvaZoneName" class="fw-bold text-success">Zone 1</div>
                        </div>
                    </div>
                </div>

                <div class="p-3 rounded-3 small border" style="background: rgba(16, 185, 129, 0.08); border-color: rgba(16, 185, 129, 0.25) !important; color: #065f46; line-height: 1.45;">
                    <div class="fw-bold mb-1"><i class="fa-solid fa-circle-check text-success me-1"></i> Transfer Instructions:</div>
                    Open your banking app &bull; Transfer the exact amount to the <strong>Dedicated Virtual Account</strong> above &bull; Funds remit automatically to the Zone ledger.
                </div>
            </div>

            <!-- Panel 2: Online Paystack Channels -->
            <div id="payPanelOnline" style="display: none;">
                <div class="mb-3">
                    <div class="small fw-bold text-uppercase text-secondary mb-2" style="font-size: 0.75rem;">Supported Channels</div>
                    <div class="d-flex flex-wrap gap-1">
                        <span class="mature-badge mature-badge-primary"><i class="fa-solid fa-credit-card me-1"></i> Debit Cards</span>
                        <span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-mobile-screen-button me-1"></i> USSD</span>
                        <span class="mature-badge mature-badge-amber"><i class="fa-solid fa-building-columns me-1"></i> Bank Transfer</span>
                        <span class="mature-badge mature-badge-purple"><i class="fa-solid fa-qrcode me-1"></i> NQR</span>
                    </div>
                </div>

                <button type="button" id="payOnlineBtn" onclick="triggerPaystackInline()" class="btn btn-primary w-100 py-3 fw-bold rounded-3 shadow-sm d-flex align-items-center justify-content-center gap-2">
                    <i class="fa-solid fa-shield-halved"></i> <span id="payOnlineBtnText">Pay with Paystack Checkout</span>
                </button>

                <div class="text-center mt-3">
                    <img src="https://paystack.com/assets/payment/img/paystack-badge-cards.png" alt="Secured by Paystack" style="height: 22px; opacity: 0.85;">
                </div>
            </div>

            <!-- Panel 3: Offline / Manual Bank Transfer Proof -->
            <div id="payPanelOffline" style="display: none;">
                <div class="alert alert-info border-0 small mb-3">
                    If you made an offline deposit or transfer to the physical estate account, submit the transaction reference or teller number below for administrator clearance.
                </div>
                <form method="POST">
                    <input type="hidden" name="invoice_id" id="modalInvoiceId">
                    <input type="hidden" name="amount" id="modalFormAmount">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Bank Transfer Reference / Session ID / Teller #</label>
                        <input type="text" name="transfer_ref" placeholder="e.g. TRF/20260901/10045" required class="form-control">
                    </div>
                    <button type="submit" name="submit_bank_transfer" class="btn btn-dark w-100 py-2 fw-semibold rounded-3">
                        <i class="fa-solid fa-paper-plane me-1"></i> Submit Reference for Verification
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

    <script>
        let currentInvoice = { id: 0, title: '', amount: 0, number: '', bankName: '', accountNumber: '', accountName: '', subaccount: '', zoneName: '', zoneId: 0 };
        const userEmail = <?= json_encode($user['email'] ?? 'resident@estate.com') ?>;
        const userName = <?= json_encode($user['name'] ?? 'Resident User') ?>;
        const paystackPubKey = <?= json_encode($paystack_public) ?>;

        function copyAccountText(text, btn) {
            EstateDialog.copy(text, 'Account number copied: ' + text);
        }

        function copyDvaAccount(btn) {
            const acct = document.getElementById('dvaAccountNumber').innerText.trim();
            copyAccountText(acct, btn);
        }

        function switchPayTab(tab) {
            const tabDVA = document.getElementById('tabBtnDVA');
            const tabOnline = document.getElementById('tabBtnOnline');
            const tabOffline = document.getElementById('tabBtnOffline');
            const panelDVA = document.getElementById('payPanelDVA');
            const panelOnline = document.getElementById('payPanelOnline');
            const panelOffline = document.getElementById('payPanelOffline');

            // Reset tabs styling
            [tabDVA, tabOnline, tabOffline].forEach(btn => {
                if (btn) {
                    btn.style.background = 'transparent';
                    btn.style.color = '#64748b';
                    btn.style.boxShadow = 'none';
                }
            });

            // Hide panels
            if (panelDVA) panelDVA.style.display = 'none';
            if (panelOnline) panelOnline.style.display = 'none';
            if (panelOffline) panelOffline.style.display = 'none';

            if (tab === 'dva') {
                tabDVA.style.background = 'white';
                tabDVA.style.color = '#0f172a';
                tabDVA.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
                if (panelDVA) panelDVA.style.display = 'block';
            } else if (tab === 'online') {
                tabOnline.style.background = 'white';
                tabOnline.style.color = '#0f172a';
                tabOnline.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
                if (panelOnline) panelOnline.style.display = 'block';
            } else {
                tabOffline.style.background = 'white';
                tabOffline.style.color = '#0f172a';
                tabOffline.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
                if (panelOffline) panelOffline.style.display = 'block';
            }
        }

        function payInvoice(id, title, amount, number, bankName, accountNum, accountName, subaccount, zoneName, zoneId) {
            currentInvoice = {
                id: id,
                title: title,
                amount: amount,
                number: number || ('INV-' + id),
                bankName: bankName || 'Wema Bank (Paystack DVA)',
                accountNumber: accountNum || '',
                accountName: accountName || 'Estate Zone Account',
                subaccount: subaccount || '',
                zoneName: zoneName || 'Assigned Zone',
                zoneId: zoneId || 0
            };

            document.getElementById('modalTitle').innerText = title;
            document.getElementById('modalInvoiceNumber').innerText = currentInvoice.number;
            document.getElementById('modalAmount').innerText = "₦" + amount.toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('payOnlineBtnText').innerText = "Pay ₦" + amount.toLocaleString('en-US', {minimumFractionDigits: 2}) + " with Paystack";
            document.getElementById('modalInvoiceId').value = id;
            document.getElementById('modalFormAmount').value = amount;

            const badge = document.getElementById('modalZoneBadge');
            if (zoneName) {
                badge.innerText = zoneName;
                badge.style.display = 'inline-block';
            } else {
                badge.style.display = 'none';
            }

            // Populate DVA Card
            const tabDVA = document.getElementById('tabBtnDVA');
            if (currentInvoice.accountNumber) {
                document.getElementById('dvaAccountNumber').innerText = currentInvoice.accountNumber;
                document.getElementById('dvaBankBadge').innerText = currentInvoice.bankName;
                document.getElementById('dvaAccountName').innerText = currentInvoice.accountName;
                document.getElementById('dvaZoneName').innerText = currentInvoice.zoneName;
                tabDVA.style.display = 'block';
                switchPayTab('dva');
            } else {
                tabDVA.style.display = 'none';
                switchPayTab('online');
            }

            document.getElementById('payModal').style.display = 'flex';
        }

        function triggerPaystackInline() {
            if (!paystackPubKey) {
                EstateDialog.alert({
                    title: 'Gateway Notice',
                    message: "Paystack Public Key is not configured in Estate System Settings yet. Please use the Dedicated Virtual Account or contact Estate Admin.",
                    type: 'warning'
                });
                return;
            }

            const btn = document.getElementById('payOnlineBtn');
            const originalText = document.getElementById('payOnlineBtnText').innerText;
            btn.disabled = true;
            document.getElementById('payOnlineBtnText').innerText = "Opening Checkout...";

            let setupOptions = {
                key: paystackPubKey,
                email: userEmail,
                amount: Math.round(currentInvoice.amount * 100), // In kobo
                currency: "NGN",
                ref: 'EST_' + Math.floor((Math.random() * 1000000000) + 1),
                channels: ['card', 'bank', 'ussd', 'qr', 'mobile_money', 'bank_transfer'],
                metadata: {
                    custom_fields: [
                        { display_name: "Invoice ID", variable_name: "invoice_id", value: currentInvoice.id },
                        { display_name: "Invoice Number", variable_name: "invoice_number", value: currentInvoice.number },
                        { display_name: "Resident Name", variable_name: "resident_name", value: userName },
                        { display_name: "Charge Title", variable_name: "charge_title", value: currentInvoice.title },
                        { display_name: "Zone ID", variable_name: "zone_id", value: currentInvoice.zoneId },
                        { display_name: "Zone Remittance Account", variable_name: "zone_account", value: currentInvoice.accountNumber }
                    ]
                },
                callback: function(response) {
                    window.location.href = "../verify_payment?reference=" + encodeURIComponent(response.reference) + "&invoice_id=" + encodeURIComponent(currentInvoice.id);
                },
                onClose: function() {
                    btn.disabled = false;
                    document.getElementById('payOnlineBtnText').innerText = originalText;
                }
            };

            // Direct Paystack subaccount routing for in-zone remittance
            if (currentInvoice.subaccount && currentInvoice.subaccount.startsWith('SUB_')) {
                setupOptions.subaccount = currentInvoice.subaccount;
            }

            let handler = PaystackPop.setup(setupOptions);
            handler.openIframe();
        }
    </script>
<?php include 'footer.php'; ?>
