<?php
// pay_invoice.php
require_once 'config.php';
require_once 'includes/Paystack.php';

if (!isset($_GET['id'])) {
    die("<h3>Invalid Request</h3><p>No invoice ID provided.</p>");
}

$invoice_id = intval($_GET['id']);
$estate_id = get_estate_id();
$invoice_result = $conn->query("
    SELECT i.*, u.name, u.email,
           z.id as zone_id, z.name as zone_name, z.code as zone_code,
           z.paystack_bank_name, z.paystack_account_number, z.paystack_account_name, z.paystack_subaccount_code
    FROM invoices i 
    JOIN users u ON i.user_id = u.id 
    LEFT JOIN flats f ON i.flat_id = f.id 
    LEFT JOIN buildings b ON f.building_id = b.id 
    LEFT JOIN streets s ON b.street_id = s.id 
    LEFT JOIN zones z ON (i.zone_id = z.id OR s.zone_id = z.id)
    WHERE i.id = $invoice_id AND i.estate_id = $estate_id 
    LIMIT 1
");

if ($invoice_result->num_rows == 0) {
    die("<h3>Invoice Not Found</h3><p>The invoice you are looking for does not exist.</p>");
}

$invoice = $invoice_result->fetch_assoc();

// Use Paystack class to get public key
$paystack = new Paystack($conn);
$paystack_public = $paystack->getPublicKey();

// Balance and installment calculations
$inv_amount = floatval($invoice['amount']);
$amount_paid = floatval($invoice['amount_paid'] ?? 0);
$balance = floatval($invoice['balance'] > 0 ? $invoice['balance'] : max(0, $inv_amount - $amount_paid));

$resolved_zone_id = intval($invoice['zone_id'] ?? 0);
$z_settings = null;
if ($resolved_zone_id > 0) {
    $zs_res = $conn->query("SELECT * FROM zonal_billing_settings WHERE zone_id = $resolved_zone_id LIMIT 1");
    if ($zs_res && $zs_res->num_rows > 0) $z_settings = $zs_res->fetch_assoc();
}
if (!$z_settings) {
    $zs_res = $conn->query("SELECT * FROM zonal_billing_settings WHERE zone_id = 0 LIMIT 1");
    if ($zs_res && $zs_res->num_rows > 0) $z_settings = $zs_res->fetch_assoc();
}

$charge_allows_inst = 1;
if (!empty($invoice['charge_id'])) {
    $c_chk = $conn->query("SELECT allow_installments FROM estate_charges WHERE id = " . intval($invoice['charge_id']) . " LIMIT 1");
    if ($c_chk && $crow = $c_chk->fetch_assoc()) {
        $charge_allows_inst = intval($crow['allow_installments'] ?? 1);
    }
}

$allow_installments = ($z_settings && $z_settings['allow_installments'] && $charge_allows_inst && $balance > 0);

// Fetch existing installment milestones
$installments_list = [];
$inst_res = $conn->query("SELECT * FROM invoice_installments WHERE invoice_id = $invoice_id ORDER BY installment_number ASC");
if ($inst_res) {
    while ($ir = $inst_res->fetch_assoc()) $installments_list[] = $ir;
}

// Compute default next installment amount
$next_installment_amount = 0;
if (!empty($installments_list)) {
    foreach ($installments_list as $inst) {
        if ($inst['status'] !== 'paid') {
            $due_part = floatval($inst['amount']) - floatval($inst['amount_paid']);
            if ($due_part > 0) {
                $next_installment_amount = min($balance, $due_part);
                break;
            }
        }
    }
}
if ($next_installment_amount <= 0 && $allow_installments) {
    $first_pct = floatval($z_settings['min_first_payment_percent'] ?? 40);
    $next_installment_amount = min($balance, round($inv_amount * ($first_pct / 100), 2));
}

// Initial active payment amount is next installment if partially paid or installments allowed, or full balance
$initial_pay_amount = ($allow_installments && $next_installment_amount > 0 && $next_installment_amount < $balance) ? $next_installment_amount : $balance;
$amount_in_kobo = round($initial_pay_amount * 100);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Invoice #INV-<?= sprintf("%04d", $invoice['id']) ?> - Estate Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/estate_notifications.css">
    <script src="js/estate_notifications.js"></script>
    <style>
        body, button, input, select {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            color: #1e293b;
        }
        .payment-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 1.5rem;
            padding: 2.5rem;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
            text-align: center;
            border: 1px solid rgba(226, 232, 240, 0.8);
            margin: 2rem;
            position: relative;
            overflow: hidden;
        }
        .payment-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, #3b82f6, #10b981);
        }
        .status-badge {
            display: inline-block;
            padding: 0.35rem 1rem;
            border-radius: 2rem;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 1.5rem;
        }
        .status-unpaid {
            background: #fee2e2;
            color: #ef4444;
        }
        .status-pending {
            background: #fef3c7;
            color: #d97706;
        }
        .status-paid {
            background: #dcfce7;
            color: #10b981;
        }
        .status-overdue {
            background: #fee2e2;
            color: #b91c1c;
        }
        .status-partial { background: #fef3c7; color: #b45309; }
        .status-cancelled { background: #f1f5f9; color: #475569; }

        h1 {
            font-family: 'Outfit', sans-serif;
            margin: 0 0 0.5rem;
            font-size: 1.5rem;
            color: #0f172a;
        }
        .invoice-title {
            color: #64748b;
            font-size: 1rem;
            margin-bottom: 1.5rem;
        }
        .amount-display {
            font-family: 'Outfit', sans-serif;
            font-size: 2.5rem;
            font-weight: 700;
            color: #0f172a;
            margin: 2rem 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .amount-display span {
            font-size: 1.5rem;
            margin-right: 0.25rem;
            color: #64748b;
            margin-top: 1rem;
        }
        .details-grid {
            text-align: left;
            background: #f8fafc;
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            font-size: 0.95rem;
        }
        .detail-row:last-child {
            margin-bottom: 0;
        }
        .detail-label {
            color: #64748b;
        }
        .detail-value {
            font-weight: 500;
            color: #1e293b;
        }
        .btn-pay {
            display: block;
            width: 100%;
            padding: 1rem;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 0.75rem;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.3);
        }
        .btn-pay:hover {
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 15px 20px -3px rgba(59, 130, 246, 0.4);
        }
        .btn-pay:disabled {
            background: #cbd5e1;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .alert-warning {
            background: #fffbeb;
            color: #b45309;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            border: 1px solid #fde68a;
        }
        .dva-card {
            background: #f0fdf4;
            border: 1px solid #86efac;
            border-radius: 0.85rem;
            padding: 1rem 1.25rem;
            text-align: left;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="payment-card">
        <span class="status-badge status-<?= $invoice['status'] ?>"><?= str_replace('_', ' ', $invoice['status']) ?></span>
        
        <?php $inv_display = $invoice['invoice_number'] ?: ('INV-' . sprintf("%04d", $invoice['id'])); ?>
        <h1>Invoice #<?= htmlspecialchars($inv_display) ?></h1>
        <div class="invoice-title"><?= htmlspecialchars($invoice['title']) ?></div>
        
        <div class="amount-display" id="displayActiveAmount">
            <span>₦</span><span id="amountText"><?= number_format($initial_pay_amount, 2) ?></span>
        </div>

        <?php if ($amount_paid > 0): ?>
            <div style="font-size: 0.85rem; color: #64748b; margin-top: -0.85rem; margin-bottom: 1.25rem;">
                Total Bill: ₦<?= number_format($inv_amount, 2) ?> &bull; Already Paid: <strong style="color: #059669;">₦<?= number_format($amount_paid, 2) ?></strong>
            </div>
        <?php endif; ?>

        <!-- Installment Plan Choice (If Allowed by Zone) -->
        <?php if ($allow_installments && $next_installment_amount > 0 && $next_installment_amount < $balance): ?>
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 0.85rem; padding: 1rem; margin-bottom: 1.5rem; text-align: left;">
                <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em; margin-bottom: 0.5rem; display: flex; align-items: center; justify-content: space-between;">
                    <span><i class="fa-solid fa-chart-pie me-1" style="color: #7e22ce;"></i> Zonal Installment Plan Available</span>
                    <span style="font-size: 0.68rem; background: rgba(126, 34, 206, 0.1); color: #7e22ce; padding: 2px 6px; border-radius: 4px; font-weight: 700;">Flexible</span>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                    <!-- Option 1: Installment -->
                    <div id="card_opt_inst" onclick="setPayChoice('inst')" style="border: 2px solid #7e22ce; background: rgba(126, 34, 206, 0.06); border-radius: 0.65rem; padding: 0.65rem; cursor: pointer; transition: all 0.2s;">
                        <div style="display: flex; align-items: center; gap: 0.4rem;">
                            <input type="radio" name="pay_mode" id="radio_inst" value="inst" checked style="accent-color: #7e22ce; cursor: pointer;">
                            <span style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: #7e22ce;">Pay Installment</span>
                        </div>
                        <div style="font-weight: 800; font-size: 1.05rem; color: #7e22ce; margin-top: 0.25rem;">
                            ₦<?= number_format($next_installment_amount, 2) ?>
                        </div>
                        <small style="font-size: 0.7rem; color: #64748b; display: block; margin-top: 2px;">Next Scheduled Due</small>
                    </div>

                    <!-- Option 2: Full Balance -->
                    <div id="card_opt_full" onclick="setPayChoice('full')" style="border: 2px solid #e2e8f0; background: white; border-radius: 0.65rem; padding: 0.65rem; cursor: pointer; transition: all 0.2s;">
                        <div style="display: flex; align-items: center; gap: 0.4rem;">
                            <input type="radio" name="pay_mode" id="radio_full" value="full" style="accent-color: #7e22ce; cursor: pointer;">
                            <span style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: #64748b;">Pay Full Balance</span>
                        </div>
                        <div style="font-weight: 800; font-size: 1.05rem; color: #0f172a; margin-top: 0.25rem;">
                            ₦<?= number_format($balance, 2) ?>
                        </div>
                        <small style="font-size: 0.7rem; color: #10b981; font-weight: 600; display: block; margin-top: 2px;">100% Settle</small>
                    </div>
                </div>

                <!-- Milestone Timeline List -->
                <?php if (!empty($installments_list)): ?>
                    <div style="margin-top: 0.85rem; border-top: 1px dashed #cbd5e1; padding-top: 0.75rem;">
                        <div style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 0.35rem;">Installment Schedule</div>
                        <div style="display: flex; flex-direction: column; gap: 0.35rem;">
                            <?php foreach ($installments_list as $milestone): ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.75rem; background: white; padding: 4px 8px; border-radius: 4px; border: 1px solid #f1f5f9;">
                                    <span>
                                        <?php if ($milestone['status'] === 'paid'): ?>
                                            <i class="fa-solid fa-circle-check text-success me-1"></i>
                                            <del style="color: #94a3b8;"><?= htmlspecialchars($milestone['title']) ?></del>
                                        <?php else: ?>
                                            <i class="fa-regular fa-clock text-warning me-1"></i>
                                            <strong><?= htmlspecialchars($milestone['title']) ?></strong>
                                        <?php endif; ?>
                                        <span class="text-muted">(Due <?= date('M d', strtotime($milestone['due_date'])) ?>)</span>
                                    </span>
                                    <span style="font-weight: 700; color: <?= $milestone['status'] === 'paid' ? '#10b981' : '#0f172a' ?>;">
                                        ₦<?= number_format($milestone['amount'], 2) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="details-grid">
            <div class="detail-row">
                <span class="detail-label">Billed To</span>
                <span class="detail-value"><?= htmlspecialchars($invoice['name']) ?></span>
            </div>
            <?php if (!empty($invoice['zone_name'])): ?>
            <div class="detail-row">
                <span class="detail-label">Zone Sector</span>
                <span class="detail-value" style="color: #7e22ce; font-weight: 600;"><?= htmlspecialchars($invoice['zone_name']) ?> (<?= htmlspecialchars($invoice['zone_code'] ?? '') ?>)</span>
            </div>
            <?php endif; ?>
            <div class="detail-row">
                <span class="detail-label">Date Issued</span>
                <span class="detail-value"><?= date('M j, Y', strtotime($invoice['created_at'])) ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Due Date</span>
                <span class="detail-value"><?= date('M j, Y', strtotime($invoice['due_date'])) ?></span>
            </div>
        </div>

        <?php if ($invoice['status'] === 'paid' || $balance <= 0): ?>
            <div style="color: #10b981; font-weight: 600; font-size: 1.1rem; margin-bottom: 1rem;">
                <svg style="width: 32px; height: 32px; vertical-align: middle; margin-right: 8px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                This invoice has been fully paid.
            </div>
            <a href="resident/receipt?invoice_id=<?= $invoice['id'] ?>" style="color: #3b82f6; text-decoration: none; font-weight: 500;">View Official Receipt</a>
        <?php elseif ($invoice['status'] === 'cancelled'): ?>
            <div style="color: #ef4444; font-weight: 600; font-size: 1.1rem;">This invoice was cancelled.</div>
        <?php else: ?>

            <?php if (!empty($invoice['paystack_account_number'])): ?>
                <!-- Dedicated Zone Virtual Account Bank Transfer Box -->
                <div class="dva-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                        <span style="font-size: 0.72rem; font-weight: 800; text-transform: uppercase; color: #166534; letter-spacing: 0.04em;">
                            <i class="fa-solid fa-building-columns me-1"></i> Zone Dedicated Bank Account
                        </span>
                        <span style="font-size: 0.68rem; background: #dcfce7; color: #15803d; font-weight: 700; padding: 2px 6px; border-radius: 4px;">Direct Remittance</span>
                    </div>
                    <div style="font-size: 0.8rem; color: #166534; margin-bottom: 0.6rem;">
                        Transfer <strong id="dvaTransferAmount">₦<?= number_format($initial_pay_amount, 2) ?></strong> directly to your Zone's account via any mobile banking app:
                    </div>
                    <div style="background: white; border: 1px solid #bbf7d0; border-radius: 0.5rem; padding: 0.75rem; margin-bottom: 0.4rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.25rem;">
                            <span style="font-size: 0.75rem; color: #64748b;">Bank Name:</span>
                            <span style="font-weight: 700; color: #0f172a; font-size: 0.88rem;"><?= htmlspecialchars($invoice['paystack_bank_name'] ?: 'Wema Bank') ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.25rem;">
                            <span style="font-size: 0.75rem; color: #64748b;">Account Number:</span>
                            <div style="display: flex; align-items: center; gap: 0.4rem;">
                                <span style="font-family: monospace; font-size: 1.1rem; font-weight: 800; color: #059669;"><?= htmlspecialchars($invoice['paystack_account_number']) ?></span>
                                <button type="button" onclick="EstateDialog.copy('<?= htmlspecialchars($invoice['paystack_account_number']) ?>', 'Copied account number to clipboard');" style="background: none; border: none; color: #059669; cursor: pointer; padding: 2px 4px;" title="Copy Account Number">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 0.75rem; color: #64748b;">Account Name:</span>
                            <span style="font-weight: 600; color: #334155; font-size: 0.8rem;"><?= htmlspecialchars($invoice['paystack_account_name'] ?: ($invoice['zone_name'] . ' / Estate')) ?></span>
                        </div>
                    </div>
                    <small style="color: #15803d; font-size: 0.72rem; display: block;">
                        <i class="fa-solid fa-circle-check me-1"></i> Payments made to this account are recognized and remitted to <strong><?= htmlspecialchars($invoice['zone_name'] ?? 'Zone') ?></strong>.
                    </small>
                </div>
            <?php endif; ?>

            <?php if (empty($paystack_public)): ?>
                <div class="alert-warning">
                    <strong>Payment Offline!</strong><br>
                    Paystack is not configured. Please use the direct transfer details above or contact the administrator.
                </div>
                <button class="btn-pay" disabled>Pay Now</button>
            <?php else: ?>
                <div style="display: flex; justify-content: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1.25rem; font-size: 0.75rem;">
                    <span style="background: #eff6ff; color: #1e40af; padding: 3px 8px; border-radius: 9999px; font-weight: 600;">💳 Debit Card</span>
                    <span style="background: #ecfdf5; color: #065f46; padding: 3px 8px; border-radius: 9999px; font-weight: 600;">📱 USSD Code</span>
                    <span style="background: #fef3c7; color: #92400e; padding: 3px 8px; border-radius: 9999px; font-weight: 600;">🏦 Bank Transfer</span>
                </div>
                <form id="paymentForm">
                    <button type="submit" class="btn-pay" id="paystackSubmitBtn">Pay ₦<span id="btnPayAmount"><?= number_format($initial_pay_amount, 2) ?></span> with Paystack</button>
                </form>
                <div style="font-size: 0.8rem; color: #64748b; margin-top: 0.75rem;">
                    Instant confirmation via Debit Card, USSD (*737#, *966#, etc.), or Online Transfer
                </div>
                <script src="https://js.paystack.co/v1/inline.js"></script>
                <script>
                    let currentPayAmount = <?= $initial_pay_amount ?>;
                    const balanceAmount = <?= $balance ?>;
                    const instAmount = <?= $next_installment_amount > 0 ? $next_installment_amount : $balance ?>;

                    function setPayChoice(mode) {
                        const cardInst = document.getElementById('card_opt_inst');
                        const cardFull = document.getElementById('card_opt_full');
                        const radioInst = document.getElementById('radio_inst');
                        const radioFull = document.getElementById('radio_full');

                        if (mode === 'inst') {
                            currentPayAmount = instAmount;
                            if (radioInst) radioInst.checked = true;
                            if (cardInst) {
                                cardInst.style.borderColor = '#7e22ce';
                                cardInst.style.background = 'rgba(126, 34, 206, 0.06)';
                            }
                            if (cardFull) {
                                cardFull.style.borderColor = '#e2e8f0';
                                cardFull.style.background = 'white';
                            }
                        } else {
                            currentPayAmount = balanceAmount;
                            if (radioFull) radioFull.checked = true;
                            if (cardFull) {
                                cardFull.style.borderColor = '#7e22ce';
                                cardFull.style.background = 'rgba(126, 34, 206, 0.06)';
                            }
                            if (cardInst) {
                                cardInst.style.borderColor = '#e2e8f0';
                                cardInst.style.background = 'white';
                            }
                        }

                        const formatted = currentPayAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                        document.getElementById('amountText').innerText = formatted;
                        document.getElementById('btnPayAmount').innerText = formatted;
                        const dvaAmtEl = document.getElementById('dvaTransferAmount');
                        if (dvaAmtEl) dvaAmtEl.innerText = '₦' + formatted;
                    }

                    const paymentForm = document.getElementById('paymentForm');
                    paymentForm.addEventListener("submit", payWithPaystack, false);

                    function payWithPaystack(e) {
                        e.preventDefault();
                        
                        const btn = e.target.querySelector('button');
                        btn.disabled = true;
                        btn.innerText = 'Opening Checkout...';

                        const amountInKobo = Math.round(currentPayAmount * 100);

                        const paystackConfig = {
                            key: '<?= $paystack_public ?>',
                            email: '<?= htmlspecialchars($invoice['email']) ?>',
                            amount: amountInKobo,
                            currency: 'NGN',
                            ref: 'EST_' + Math.floor((Math.random() * 1000000000) + 1),
                            channels: ['card', 'bank', 'ussd', 'qr', 'mobile_money', 'bank_transfer'],
                            metadata: {
                                custom_fields: [
                                    {
                                        display_name: "Invoice ID",
                                        variable_name: "invoice_id",
                                        value: <?= $invoice['id'] ?>
                                    },
                                    {
                                        display_name: "Invoice Number",
                                        variable_name: "invoice_number",
                                        value: <?= json_encode($inv_display) ?>
                                    },
                                    {
                                        display_name: "Resident Name",
                                        variable_name: "resident_name",
                                        value: <?= json_encode($invoice['name']) ?>
                                    },
                                    {
                                        display_name: "Zone ID",
                                        variable_name: "zone_id",
                                        value: <?= intval($invoice['zone_id'] ?? 0) ?>
                                    },
                                    {
                                        display_name: "Payment Type",
                                        variable_name: "payment_type",
                                        value: (currentPayAmount < balanceAmount ? "Installment Payment" : "Full Settlement")
                                    }
                                ]
                            },
                            callback: function(response) {
                                window.location.href = "verify_payment?reference=" + encodeURIComponent(response.reference) + "&invoice_id=<?= $invoice['id'] ?>";
                            },
                            onClose: function() {
                                btn.disabled = false;
                                const formatted = currentPayAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                btn.innerHTML = 'Pay ₦<span id="btnPayAmount">' + formatted + '</span> with Paystack';
                            }
                        };

                        <?php if (!empty($invoice['paystack_subaccount_code'])): ?>
                            paystackConfig.subaccount = '<?= htmlspecialchars($invoice['paystack_subaccount_code']) ?>';
                        <?php endif; ?>

                        let handler = PaystackPop.setup(paystackConfig);
                        handler.openIframe();
                    }
                </script>
                <div style="margin-top: 1.5rem; text-align: center;">
                    <img src="https://paystack.com/assets/payment/img/paystack-badge-cards.png" alt="Secured by Paystack" style="height: 30px; opacity: 0.7;">
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
