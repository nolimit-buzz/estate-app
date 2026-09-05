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

// Fetch Invoices
$invoices_res = $conn->query("SELECT * FROM invoices WHERE user_id = $user_id AND estate_id = $estate_id ORDER BY due_date DESC");

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

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 font-bold text-slate-800 m-0"><i class="fa-solid fa-file-invoice-dollar text-primary me-2"></i> Bills & Invoices</h2>
        <p class="text-secondary small mb-0">Manage your estate dues, pay invoices, and view payment history.</p>
    </div>
</div>

<style>
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; }
    .kpi-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1.5rem; }

    .card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1.5rem; }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 1rem; }
    .card-title { font-family: 'Outfit', sans-serif; font-size: 1.15rem; font-weight: 700; }

    .badge-paid { background: #dcfce7; color: #15803d; }
    .badge-unpaid { background: #fee2e2; color: #b91c1c; }
    .badge-pending { background: #fef3c7; color: #b45309; }

    .btn-action { padding: 0.5rem 1rem; border-radius: 0.5rem; border: none; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; transition: all 0.2s; }
    .btn-pay { background: #10b981; color: white; }
    .btn-pay:hover { background: #059669; }
    .btn-receipt { background: #3b82f6; color: white; }
    .btn-receipt:hover { background: #2563eb; }

    /* Modal */
    .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.6); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
    .modal-body { background: white; border-radius: 1rem; padding: 2rem; max-width: 500px; width: 90%; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
</style>

<div class="d-flex flex-column gap-4">


        <?php if ($message): ?>
            <div style="background: #dcfce7; color: #15803d; padding: 1rem; border-radius: 0.5rem; font-weight: 600; border: 1px solid #bbf7d0;">
                <i class="fa-solid fa-circle-check" style="margin-right: 6px;"></i> <?= $message ?>
            </div>
        <?php endif; ?>

        <div class="kpi-grid">
            <div class="kpi-card" style="border-left: 4px solid #ef4444;">
                <div style="color: var(--text-muted); font-weight: 600; font-size: 0.85rem; text-transform: uppercase;">Total Outstanding</div>
                <div style="font-family: 'Outfit'; font-size: 2.25rem; font-weight: 700; color: #dc2626; margin-top: 0.25rem;">₦<?= number_format($totals['outstanding'], 2) ?></div>
            </div>
            <div class="kpi-card" style="border-left: 4px solid #10b981;">
                <div style="color: var(--text-muted); font-weight: 600; font-size: 0.85rem; text-transform: uppercase;">Total Paid</div>
                <div style="font-family: 'Outfit'; font-size: 2.25rem; font-weight: 700; color: #059669; margin-top: 0.25rem;">₦<?= number_format($totals['total_paid'], 2) ?></div>
            </div>
        </div>

        <!-- Invoices & Bills Table -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fa-solid fa-file-invoice-dollar" style="color: var(--primary); margin-right: 8px;"></i> Outstanding & Issued Invoices</div>
            </div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Charge Title</th>
                            <th>Amount</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($invoices_res && $invoices_res->num_rows > 0): ?>
                            <?php while ($inv = $invoices_res->fetch_assoc()): ?>
                                <?php $inv_display_no = $inv['invoice_number'] ?: ('INV-' . sprintf("%04d", $inv['id'])); ?>
                                <tr>
                                    <td style="font-family: monospace; font-weight: 600; color: #2563eb;"><?= htmlspecialchars($inv_display_no) ?></td>
                                    <td style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($inv['title']) ?></td>
                                    <td style="font-weight: 700; color: #0f172a;">₦<?= number_format($inv['amount'], 2) ?></td>
                                    <td><?= $inv['due_date'] ? date('M j, Y', strtotime($inv['due_date'])) : 'N/A' ?></td>
                                    <td>
                                        <span class="badge badge-<?= $inv['status'] ?>">
                                            <?= htmlspecialchars(str_replace('_', ' ', $inv['status'])) ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <?php if ($inv['status'] != 'paid'): ?>
                                            <button onclick="payInvoice(<?= $inv['id'] ?>, '<?= htmlspecialchars(addslashes($inv['title'])) ?>', <?= $inv['amount'] ?>, '<?= htmlspecialchars(addslashes($inv_display_no)) ?>')" class="btn-action btn-pay">
                                                <i class="fa-solid fa-credit-card"></i> Pay Now
                                            </button>
                                        <?php else: ?>
                                            <a href="receipt?invoice_id=<?= $inv['id'] ?>" class="btn-action btn-receipt">
                                                <i class="fa-solid fa-receipt"></i> View Receipt
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2rem;">No invoices generated for your account yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Payment History Table -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fa-solid fa-clock-rotate-left" style="color: #8b5cf6; margin-right: 8px;"></i> Verified Payment History & Receipts</div>
            </div>
            <div style="overflow-x: auto;">
                <table>
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
                                    <td style="font-family: monospace; font-weight: 700; color: #0284c7;">
                                        <?= htmlspecialchars($pay['receipt_number'] ?? 'Pending') ?>
                                    </td>
                                    <td style="font-family: monospace; font-weight: 600; color: #64748b;">
                                        <?= htmlspecialchars($pay['invoice_number'] ?: ($pay['invoice_id'] ? ('INV-' . $pay['invoice_id']) : 'N/A')) ?>
                                    </td>
                                    <td><?= htmlspecialchars($pay['type'] . ' - ' . ($pay['description'] ?? '')) ?></td>
                                    <td style="font-weight: 700; color: #15803d;">₦<?= number_format($pay['amount'], 2) ?></td>
                                    <td><span style="font-size: 0.8rem; background: #f1f5f9; padding: 2px 8px; border-radius: 4px; font-weight: 600; text-transform: uppercase;"><?= htmlspecialchars(str_replace(['paystack_', '_'], ['', ' '], $pay['payment_method'])) ?></span></td>
                                    <td><?= date('M j, Y h:i A', strtotime($pay['created_at'])) ?></td>
                                    <td><span class="badge badge-<?= $pay['status'] ?>"><?= htmlspecialchars(ucfirst($pay['status'])) ?></span></td>
                                    <td style="text-align: right;">
                                        <?php if (!empty($pay['receipt_number'])): ?>
                                            <a href="receipt?receipt_no=<?= urlencode($pay['receipt_number']) ?>" class="btn-action btn-receipt">
                                                <i class="fa-solid fa-print"></i> View / Print
                                            </a>
                                        <?php else: ?>
                                            <span style="font-size: 0.8rem; color: var(--text-muted);">Awaiting Admin</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align: center; color: var(--text-muted); padding: 2rem;">No payment history recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modern Interactive Pay Modal -->
    <div id="payModal" class="modal">
        <div class="modal-body" style="max-width: 540px; border-radius: 1.25rem; padding: 2rem; position: relative;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.25rem;">
                <div>
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
                        <span id="modalInvoiceNumber" style="font-family: monospace; font-size: 0.8rem; font-weight: 700; background: #eff6ff; color: #2563eb; padding: 3px 8px; border-radius: 6px;">INV-0000</span>
                        <span style="font-size: 0.75rem; background: #fee2e2; color: #b91c1c; font-weight: 700; padding: 2px 7px; border-radius: 9999px; text-transform: uppercase;">Unpaid</span>
                    </div>
                    <h3 id="modalTitle" style="font-family: 'Outfit', sans-serif; font-size: 1.35rem; font-weight: 700; color: #0f172a; margin: 0;">Pay Invoice</h3>
                </div>
                <button onclick="document.getElementById('payModal').style.display='none'" style="background: #f1f5f9; border: none; width: 32px; height: 32px; border-radius: 50%; font-size: 1.25rem; line-height: 1; cursor: pointer; color: #64748b; display: flex; align-items: center; justify-content: center;">&times;</button>
            </div>
            
            <!-- Amount Card -->
            <div style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); padding: 1.25rem; border-radius: 0.85rem; margin-bottom: 1.25rem; text-align: center; border: 1px solid #e2e8f0;">
                <div style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; color: #64748b;">Total Amount Due</div>
                <div id="modalAmount" style="font-family: 'Outfit', sans-serif; font-size: 2.25rem; font-weight: 800; color: #0f172a; margin-top: 0.25rem;">₦0.00</div>
            </div>

            <!-- Tab Switcher -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; background: #f1f5f9; padding: 4px; border-radius: 0.6rem; margin-bottom: 1.25rem;">
                <button type="button" id="tabBtnOnline" onclick="switchPayTab('online')" style="padding: 0.6rem 0.5rem; border: none; border-radius: 0.45rem; font-weight: 700; font-size: 0.85rem; cursor: pointer; background: white; color: #0f172a; box-shadow: 0 1px 3px rgba(0,0,0,0.1); transition: all 0.2s;">
                    <i class="fa-solid fa-bolt" style="color: #0284c7; margin-right: 4px;"></i> Online (Paystack)
                </button>
                <button type="button" id="tabBtnOffline" onclick="switchPayTab('offline')" style="padding: 0.6rem 0.5rem; border: none; border-radius: 0.45rem; font-weight: 600; font-size: 0.85rem; cursor: pointer; background: transparent; color: #64748b; transition: all 0.2s;">
                    <i class="fa-solid fa-receipt" style="margin-right: 4px;"></i> Manual Transfer Proof
                </button>
            </div>

            <!-- Panel 1: Online Paystack Channels -->
            <div id="payPanelOnline">
                <div style="margin-bottom: 1rem;">
                    <div style="font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 0.5rem; letter-spacing: 0.05em;">Supported Payment Channels</div>
                    <div style="display: flex; flex-wrap: wrap; gap: 0.4rem;">
                        <span style="font-size: 0.75rem; background: #eff6ff; color: #1e40af; padding: 4px 8px; border-radius: 6px; font-weight: 600;"><i class="fa-solid fa-credit-card me-1"></i> Debit Cards (Mastercard, Visa, Verve)</span>
                        <span style="font-size: 0.75rem; background: #ecfdf5; color: #065f46; padding: 4px 8px; border-radius: 6px; font-weight: 600;"><i class="fa-solid fa-mobile-screen-button me-1"></i> USSD (*737#, *966#, *901#, etc.)</span>
                        <span style="font-size: 0.75rem; background: #fef3c7; color: #92400e; padding: 4px 8px; border-radius: 6px; font-weight: 600;"><i class="fa-solid fa-building-columns me-1"></i> Instant Bank Transfer</span>
                        <span style="font-size: 0.75rem; background: #f5f3ff; color: #5b21b6; padding: 4px 8px; border-radius: 6px; font-weight: 600;"><i class="fa-solid fa-qrcode me-1"></i> NQR & Mobile Money</span>
                    </div>
                </div>

                <button type="button" id="payOnlineBtn" onclick="triggerPaystackInline()" style="width: 100%; padding: 1rem; background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color: white; border: none; border-radius: 0.75rem; font-weight: 700; font-size: 1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 0.5rem; box-shadow: 0 10px 15px -3px rgba(2, 132, 199, 0.35); transition: all 0.2s;">
                    <i class="fa-solid fa-shield-halved"></i> <span id="payOnlineBtnText">Pay with Paystack (Card, USSD, Transfer)</span>
                </button>

                <div style="margin-top: 1rem; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 0.5rem; padding: 0.75rem; font-size: 0.8rem; color: #475569; line-height: 1.45;">
                    <div style="font-weight: 700; color: #0f172a; margin-bottom: 0.2rem;"><i class="fa-regular fa-lightbulb text-warning me-1"></i> How to pay with USSD or Bank Transfer:</div>
                    1. Click the button above to launch the secure Paystack window.<br>
                    2. Choose <strong>"Transfer"</strong> to get an automated temporary bank account (pay via your mobile banking app, verified instantly), OR choose <strong>"USSD"</strong> to select your bank and dial the displayed code.
                </div>

                <div style="margin-top: 1rem; text-align: center;">
                    <img src="https://paystack.com/assets/payment/img/paystack-badge-cards.png" alt="Secured by Paystack" style="height: 24px; opacity: 0.75;">
                </div>
            </div>

            <!-- Panel 2: Offline / Manual Bank Transfer Proof -->
            <div id="payPanelOffline" style="display: none;">
                <div style="background: #eff6ff; border-left: 3px solid #3b82f6; padding: 0.75rem; border-radius: 0.35rem; font-size: 0.82rem; color: #1e3a8a; margin-bottom: 1rem;">
                    If you transferred directly to the estate's physical bank account, enter the transaction reference / teller number below for management approval.
                </div>
                <form method="POST">
                    <input type="hidden" name="invoice_id" id="modalInvoiceId">
                    <input type="hidden" name="amount" id="modalFormAmount">
                    <div style="margin-bottom: 1rem;">
                        <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; color: #334155;">Bank Transfer Reference / Session ID / Teller #</label>
                        <input type="text" name="transfer_ref" placeholder="e.g. TRF/20260901/10045" required style="width: 100%; padding: 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; font-size: 0.95rem;">
                    </div>
                    <button type="submit" name="submit_bank_transfer" class="btn-action" style="width: 100%; justify-content: center; background: #0f172a; color: white; padding: 0.85rem; border-radius: 0.5rem; font-size: 0.95rem;">
                        <i class="fa-solid fa-paper-plane me-1"></i> Submit Reference for Verification
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        let currentInvoice = { id: 0, title: '', amount: 0, number: '' };
        const userEmail = <?= json_encode($user['email'] ?? 'resident@estate.com') ?>;
        const userName = <?= json_encode($user['name'] ?? 'Resident User') ?>;
        const paystackPubKey = <?= json_encode($paystack_public) ?>;

        function switchPayTab(tab) {
            const tabOnline = document.getElementById('tabBtnOnline');
            const tabOffline = document.getElementById('tabBtnOffline');
            const panelOnline = document.getElementById('payPanelOnline');
            const panelOffline = document.getElementById('payPanelOffline');

            if (tab === 'online') {
                tabOnline.style.background = 'white';
                tabOnline.style.color = '#0f172a';
                tabOnline.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
                tabOffline.style.background = 'transparent';
                tabOffline.style.color = '#64748b';
                tabOffline.style.boxShadow = 'none';
                panelOnline.style.display = 'block';
                panelOffline.style.display = 'none';
            } else {
                tabOffline.style.background = 'white';
                tabOffline.style.color = '#0f172a';
                tabOffline.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
                tabOnline.style.background = 'transparent';
                tabOnline.style.color = '#64748b';
                tabOnline.style.boxShadow = 'none';
                panelOnline.style.display = 'none';
                panelOffline.style.display = 'block';
            }
        }

        function payInvoice(id, title, amount, number) {
            currentInvoice = { id, title, amount, number: number || ('INV-' + id) };
            document.getElementById('modalTitle').innerText = title;
            document.getElementById('modalInvoiceNumber').innerText = currentInvoice.number;
            document.getElementById('modalAmount').innerText = "₦" + amount.toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('payOnlineBtnText').innerText = "Pay ₦" + amount.toLocaleString('en-US', {minimumFractionDigits: 2}) + " with Paystack";
            document.getElementById('modalInvoiceId').value = id;
            document.getElementById('modalFormAmount').value = amount;
            switchPayTab('online');
            document.getElementById('payModal').style.display = 'flex';
        }

        function triggerPaystackInline() {
            if (!paystackPubKey) {
                alert("Paystack Public Key is not configured in Estate System Settings yet. Please use Manual Bank Transfer or contact Estate Admin.");
                return;
            }

            const btn = document.getElementById('payOnlineBtn');
            const originalText = document.getElementById('payOnlineBtnText').innerText;
            btn.disabled = true;
            document.getElementById('payOnlineBtnText').innerText = "Opening Checkout...";

            let handler = PaystackPop.setup({
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
                        { display_name: "Charge Title", variable_name: "charge_title", value: currentInvoice.title }
                    ]
                },
                callback: function(response) {
                    window.location.href = "../verify_payment?reference=" + encodeURIComponent(response.reference) + "&invoice_id=" + encodeURIComponent(currentInvoice.id);
                },
                onClose: function() {
                    btn.disabled = false;
                    document.getElementById('payOnlineBtnText').innerText = originalText;
                }
            });
            handler.openIframe();
        }
    </script>
<?php include 'footer.php'; ?>
