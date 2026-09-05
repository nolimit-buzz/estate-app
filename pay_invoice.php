<?php
// pay_invoice.php
require_once 'config.php';
require_once 'includes/Paystack.php';

if (!isset($_GET['id'])) {
    die("<h3>Invalid Request</h3><p>No invoice ID provided.</p>");
}

$invoice_id = intval($_GET['id']);
$estate_id = get_estate_id();
$invoice_result = $conn->query("SELECT i.*, u.name, u.email FROM invoices i JOIN users u ON i.user_id = u.id WHERE i.id = $invoice_id AND i.estate_id = $estate_id");

if ($invoice_result->num_rows == 0) {
    die("<h3>Invoice Not Found</h3><p>The invoice you are looking for does not exist.</p>");
}

$invoice = $invoice_result->fetch_assoc();

// Use Paystack class to get public key
$paystack = new Paystack($conn);
$paystack_public = $paystack->getPublicKey();

// Calculate subtotal and fee optionally, currently we just charge invoice amount
$amount_in_kobo = $invoice['amount'] * 100;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Invoice #INV-<?= sprintf("%04d", $invoice['id']) ?> - Estate Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
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
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
            width: 100%;
            max-width: 480px;
            padding: 3rem 2.5rem;
            text-align: center;
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
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 1.5rem;
        }
        .status-unpaid { background: #fee2e2; color: #b91c1c; }
        .status-paid { background: #dcfce7; color: #15803d; }
        .status-partial { background: #fef3c7; color: #b45309; }
        .status-cancelled { background: #f1f5f9; color: #475569; }

        h1 {
            font-family: 'Space Grotesk', sans-serif;
            margin: 0 0 0.5rem;
            font-size: 1.5rem;
            color: #0f172a;
        }
        .invoice-title {
            color: #64748b;
            font-size: 1.1rem;
            margin-bottom: 2rem;
        }
        .amount-display {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 3.5rem;
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
            margin-bottom: 2rem;
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
    </style>
</head>
<body>
    <div class="payment-card">
        <span class="status-badge status-<?= $invoice['status'] ?>"><?= $invoice['status'] ?></span>
        
        <?php $inv_display = $invoice['invoice_number'] ?: ('INV-' . sprintf("%04d", $invoice['id'])); ?>
        <h1>Invoice #<?= htmlspecialchars($inv_display) ?></h1>
        <div class="invoice-title"><?= htmlspecialchars($invoice['title']) ?></div>
        
        <div class="amount-display">
            <span>₦</span><?= number_format($invoice['amount'], 2) ?>
        </div>

        <div class="details-grid">
            <div class="detail-row">
                <span class="detail-label">Billed To</span>
                <span class="detail-value"><?= htmlspecialchars($invoice['name']) ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Date Issued</span>
                <span class="detail-value"><?= date('M j, Y', strtotime($invoice['created_at'])) ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Due Date</span>
                <span class="detail-value"><?= date('M j, Y', strtotime($invoice['due_date'])) ?></span>
            </div>
        </div>

        <?php if ($invoice['status'] === 'paid'): ?>
            <div style="color: #10b981; font-weight: 600; font-size: 1.1rem; margin-bottom: 1rem;">
                <svg style="width: 32px; height: 32px; vertical-align: middle; margin-right: 8px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                This invoice has been paid.
            </div>
            <a href="resident/receipt?invoice_id=<?= $invoice['id'] ?>" style="color: #3b82f6; text-decoration: none; font-weight: 500;">View Receipt</a>
        <?php elseif ($invoice['status'] === 'cancelled'): ?>
            <div style="color: #ef4444; font-weight: 600; font-size: 1.1rem;">This invoice was cancelled.</div>
        <?php else: ?>
            <?php if (empty($paystack_public)): ?>
                <div class="alert-warning">
                    <strong>Payment Offline!</strong><br>
                    Paystack is not configured. Please contact the administrator.
                </div>
                <button class="btn-pay" disabled>Pay Now</button>
            <?php else: ?>
                <div style="display: flex; justify-content: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1.25rem; font-size: 0.75rem;">
                    <span style="background: #eff6ff; color: #1e40af; padding: 3px 8px; border-radius: 9999px; font-weight: 600;">💳 Debit Card</span>
                    <span style="background: #ecfdf5; color: #065f46; padding: 3px 8px; border-radius: 9999px; font-weight: 600;">📱 USSD Code</span>
                    <span style="background: #fef3c7; color: #92400e; padding: 3px 8px; border-radius: 9999px; font-weight: 600;">🏦 Bank Transfer</span>
                </div>
                <form id="paymentForm">
                    <button type="submit" class="btn-pay">Pay ₦<?= number_format($invoice['amount'], 2) ?> Securely</button>
                </form>
                <div style="font-size: 0.8rem; color: #64748b; margin-top: 0.75rem;">
                    Pay via Debit Card, Bank Transfer, or USSD (*737#, *966#, etc.)
                </div>
                <script src="https://js.paystack.co/v1/inline.js"></script>
                <script>
                    const paymentForm = document.getElementById('paymentForm');
                    paymentForm.addEventListener("submit", payWithPaystack, false);

                    function payWithPaystack(e) {
                        e.preventDefault();
                        
                        // Disable button to prevent double clicks
                        const btn = e.target.querySelector('button');
                        btn.disabled = true;
                        btn.innerText = 'Opening Checkout...';

                        let handler = PaystackPop.setup({
                            key: '<?= $paystack_public ?>',
                            email: '<?= htmlspecialchars($invoice['email']) ?>',
                            amount: <?= $amount_in_kobo ?>,
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
                                    }
                                ]
                            },
                            callback: function(response) {
                                window.location.href = "verify_payment?reference=" + encodeURIComponent(response.reference) + "&invoice_id=<?= $invoice['id'] ?>";
                            },
                            onClose: function() {
                                btn.disabled = false;
                                btn.innerText = 'Pay ₦<?= number_format($invoice['amount'], 2) ?> Securely';
                            }
                        });
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
