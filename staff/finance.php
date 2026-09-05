<?php
// staff/finance.php
require_once '../config.php';
require_once '../includes/auth_guard.php';

requireLogin();
if (!isStaffRole() && !isAdminRole()) {
    header("Location: ../index");
    exit;
}

// Ensure staff has at least one finance permission
if (!hasPermission('finance.view_invoices') && !hasPermission('finance.create_invoice') && !hasPermission('finance.record_payment') && !hasPermission('finance.view_receipts') && !hasPermission('charges.manage')) {
    header("Location: index?error=unauthorized");
    exit;
}

$estate_id = get_estate_id();
$message = "";
$message_type = "success";

// Handle Recording Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    if (!hasPermission('finance.record_payment')) {
        $message = "You do not have permission to record payments.";
        $message_type = "danger";
    } else {
        $invoice_id = intval($_POST['invoice_id']);
        $payment_method = $conn->real_escape_string($_POST['payment_method']);
        $ref = $conn->real_escape_string($_POST['transaction_ref'] ?: ('MAN-' . time()));
        $submitted_receipt_no = isset($_POST['receipt_number']) ? trim($_POST['receipt_number']) : '';
        
        $inv_res = $conn->query("SELECT * FROM invoices WHERE id = $invoice_id AND estate_id = $estate_id");
        if ($inv_res && $inv_res->num_rows > 0) {
            $inv = $inv_res->fetch_assoc();
            $res_user_id = $inv['user_id'];
            $amount = $inv['amount'];
            
            // Mark invoice paid
            $conn->query("UPDATE invoices SET status = 'paid', amount_paid = $amount, balance = 0 WHERE id = $invoice_id AND estate_id = $estate_id");
            
            // Record payment (status column)
            $conn->query("INSERT INTO payments (estate_id, user_id, invoice_id, amount, type, payment_method, status, payment_reference, transaction_ref, paid_at, description) 
                          VALUES ($estate_id, $res_user_id, $invoice_id, $amount, '{$inv['title']}', '$payment_method', 'paid', '$ref', '$ref', NOW(), 'Staff Recorded Payment')");
            $payment_id = $conn->insert_id;
            
            // Issue auto sequential receipt
            if (!empty($submitted_receipt_no)) {
                $candidate_receipt_no = $conn->real_escape_string($submitted_receipt_no);
                $chk = $conn->query("SELECT id FROM receipts WHERE receipt_number = '$candidate_receipt_no' LIMIT 1");
                if (!$chk || $chk->num_rows == 0) {
                    $receipt_no = $candidate_receipt_no;
                } else {
                    $receipt_no = generateReceiptNumber($conn);
                }
            } else {
                $receipt_no = generateReceiptNumber($conn);
            }

            $prop_id = !empty($inv['property_id']) ? intval($inv['property_id']) : 'NULL';
            $conn->query("INSERT INTO receipts (estate_id, receipt_number, payment_id, resident_id, property_id, amount, issued_by, issued_at) 
                          VALUES ($estate_id, '$receipt_no', $payment_id, $res_user_id, $prop_id, $amount, $user_id, NOW())");
            
            $staff_info = $conn->query("SELECT name, role FROM users WHERE id = $user_id LIMIT 1")->fetch_assoc();
            $staff_name = $staff_info['name'] ?? 'Staff';
            $staff_role = ucfirst($staff_info['role'] ?? 'staff');

            $inv_num = $inv['invoice_number'] ?: ('INV-' . $invoice_id);
            logAudit($conn, "Staff Payment Recorded", "Staff Finance", "Staff $staff_name ($staff_role) recorded payment for invoice #$inv_num. Receipt: $receipt_no via $payment_method");
            $message = "Payment recorded successfully! Receipt #<strong>$receipt_no</strong> generated for Invoice #<strong>$inv_num</strong>.";
        }
    }
}

// Handle Creating Single Bill / Invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_invoice'])) {
    if (!hasPermission('finance.create_invoice')) {
        $message = "You do not have permission to create invoices.";
        $message_type = "danger";
    } else {
        $target_user_id = intval($_POST['user_id']);
        $title = $conn->real_escape_string($_POST['title']);
        $amount = floatval($_POST['amount']);
        $due_date = $conn->real_escape_string($_POST['due_date']);
        $charge_id = !empty($_POST['charge_id']) ? intval($_POST['charge_id']) : 'NULL';
        
        // Find resident flat
        $r_chk = $conn->query("SELECT flat_id FROM residents WHERE user_id = $target_user_id AND estate_id = $estate_id LIMIT 1");
        $flat_id = ($r_chk && $r_chk->num_rows > 0) ? intval($r_chk->fetch_assoc()['flat_id']) : 'NULL';
        
        $inv_no = generateInvoiceNumber($conn);
        $sql = "INSERT INTO invoices (estate_id, invoice_number, charge_id, user_id, property_id, title, amount, subtotal, balance, status, due_date, created_at) 
                VALUES ($estate_id, '$inv_no', $charge_id, $target_user_id, $flat_id, '$title', $amount, $amount, $amount, 'unpaid', '$due_date', NOW())";
        if ($conn->query($sql)) {
            $inv_id = $conn->insert_id;
            logAudit($conn, "Staff Invoice Created", "Staff Finance", "Staff created invoice #$inv_no (ID $inv_id) for user #$target_user_id");
            $message = "Bill / Invoice $inv_no created successfully!";
        } else {
            $message = "Error generating invoice: " . $conn->error;
            $message_type = "danger";
        }
    }
}

// Fetch Invoices
$status_filter = $_GET['status'] ?? 'all';
$filter_sql = "";
if ($status_filter === 'paid') $filter_sql = " AND i.status = 'paid'";
elseif ($status_filter === 'unpaid') $filter_sql = " AND i.status = 'unpaid'";
elseif ($status_filter === 'overdue') $filter_sql = " AND i.status = 'unpaid' AND i.due_date < CURDATE()";

$invoices = $conn->query("SELECT i.*, u.name as resident_name, u.email as resident_email, f.number as flat_number, b.name as building_name 
    FROM invoices i 
    JOIN users u ON i.user_id = u.id 
    LEFT JOIN flats f ON i.property_id = f.id 
    LEFT JOIN buildings b ON f.building_id = b.id 
    WHERE i.estate_id = $estate_id $filter_sql 
    ORDER BY i.id DESC");

// Fetch Residents for Invoice Form
$residents_res = $conn->query("SELECT r.user_id, u.name, f.number as flat_number, b.name as building_name 
    FROM residents r 
    JOIN users u ON r.user_id = u.id 
    LEFT JOIN flats f ON r.flat_id = f.id 
    LEFT JOIN buildings b ON f.building_id = b.id 
    WHERE r.estate_id = $estate_id AND r.status = 'active' 
    ORDER BY u.name ASC");

// Fetch Charges
$charges_res = $conn->query("SELECT * FROM estate_charges WHERE estate_id = $estate_id AND is_active = 1 ORDER BY name ASC");

// Fetch Dynamic Payment Methods
$staff_pm_res = $conn->query("SELECT * FROM payment_methods WHERE estate_id = $estate_id AND status = 'active' ORDER BY is_system DESC, name ASC");
$staff_pm_list = [];
if ($staff_pm_res) {
    while ($pm = $staff_pm_res->fetch_assoc()) {
        $staff_pm_list[] = $pm;
    }
}

include 'header.php';
include 'sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 font-bold text-slate-800 m-0">Finance & Collections Console</h2>
        <p class="text-secondary small mb-0">View resident billing records, record collections, and confirm receipts.</p>
    </div>
    <div class="d-flex gap-2">
        <?php if (hasPermission('finance.create_invoice')): ?>
            <button class="btn btn-primary" onclick="openInvoiceModal()" style="background: #0f766e; border: none;">
                <i class="fa-solid fa-plus me-1"></i> Create Bill / Invoice
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i> <?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Quick Filter Badges -->
<div class="d-flex gap-2 mb-3">
    <a href="?status=all" class="btn btn-sm <?php echo ($status_filter == 'all') ? 'btn-dark' : 'btn-outline-secondary'; ?>">All Invoices</a>
    <a href="?status=unpaid" class="btn btn-sm <?php echo ($status_filter == 'unpaid') ? 'btn-danger' : 'btn-outline-danger'; ?>">Unpaid / Pending</a>
    <a href="?status=paid" class="btn btn-sm <?php echo ($status_filter == 'paid') ? 'btn-success' : 'btn-outline-success'; ?>">Paid & Confirmed</a>
    <a href="?status=overdue" class="btn btn-sm <?php echo ($status_filter == 'overdue') ? 'btn-warning' : 'btn-outline-warning'; ?>">Overdue Bills</a>
</div>

<div class="card border-0 shadow-sm p-4 bg-white rounded-3">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="table-light">
                <tr style="color: #64748b; font-size: 0.85rem; text-transform: uppercase;">
                    <th>#ID</th>
                    <th>Resident / Property</th>
                    <th>Invoice Title</th>
                    <th>Amount</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($invoices && $invoices->num_rows > 0): ?>
                    <?php while($inv = $invoices->fetch_assoc()): ?>
                    <tr>
                        <td style="font-family: monospace; font-weight: 600; color: #0284c7;"><?php echo htmlspecialchars($inv['invoice_number'] ?: ('INV-' . str_pad($inv['id'], 5, '0', STR_PAD_LEFT))); ?></td>
                        <td>
                            <div class="fw-bold text-slate-800"><?php echo htmlspecialchars($inv['resident_name']); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars(($inv['building_name'] ?? 'Block') . ' Flat ' . ($inv['flat_number'] ?? 'N/A')); ?></small>
                        </td>
                        <td>
                            <div class="fw-semibold text-slate-800"><?php echo htmlspecialchars($inv['title']); ?></div>
                        </td>
                        <td>
                            <div class="fw-bold text-slate-900">₦<?php echo number_format($inv['amount'], 2); ?></div>
                        </td>
                        <td class="small <?php echo ($inv['status'] != 'paid' && strtotime($inv['due_date']) < time()) ? 'text-danger fw-bold' : 'text-muted'; ?>">
                            <?php echo date('M d, Y', strtotime($inv['due_date'])); ?>
                        </td>
                        <td>
                            <?php if ($inv['status'] === 'paid'): ?>
                                <span class="badge bg-success-subtle text-success text-uppercase" style="font-size: 0.72rem; font-weight: 700;">
                                    <i class="fa-solid fa-check-circle me-1"></i> Paid
                                </span>
                            <?php elseif ($inv['status'] === 'partially_paid'): ?>
                                <span class="badge bg-warning-subtle text-warning-emphasis text-uppercase" style="font-size: 0.72rem; font-weight: 700;">
                                    Partially Paid
                                </span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger text-uppercase" style="font-size: 0.72rem; font-weight: 700;">
                                    Unpaid
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($inv['status'] === 'paid'): ?>
                                <?php if (hasPermission('finance.view_receipts')): ?>
                                    <a href="../admin/receipt?id=<?php echo $inv['id']; ?>" target="_blank" class="btn btn-sm btn-outline-success fw-semibold" title="View / Print Receipt">
                                        <i class="fa-solid fa-receipt me-1"></i> Receipt
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if (hasPermission('finance.record_payment')): ?>
                                    <button onclick='openPaymentModal(<?php echo json_encode($inv); ?>)' class="btn btn-sm btn-primary fw-semibold" style="background: #0f766e; border: none;">
                                        <i class="fa-solid fa-money-bill-transfer me-1"></i> Record Payment
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">No invoices found matching current filter.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Record Payment Modal -->
<div id="payment-modal" class="custom-modal-backdrop" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 1050; align-items: center; justify-content: center; padding: 1.5rem 1rem; overflow-y: auto;">
    <div class="modal-content bg-white rounded-4 shadow-lg p-4" style="max-width: 500px; width: 100%;">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3">
            <div>
                <h5 class="fw-bold text-slate-800 m-0">Confirm & Record Payment</h5>
                <small class="text-muted">Separate Invoice ID and Auto-Generated Receipt ID</small>
            </div>
            <button type="button" class="btn-close" onclick="closePaymentModal()"></button>
        </div>
        <form method="POST">
            <input type="hidden" name="invoice_id" id="pay_inv_id">
            
            <div class="p-3 bg-light rounded-3 mb-3 border">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted small text-uppercase fw-semibold">Invoice #</span>
                    <span id="pay_inv_no_badge" class="badge bg-primary-subtle text-primary fw-bold font-monospace" style="font-size: 0.85rem;">INV-00000</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted small text-uppercase fw-semibold">Resident</span>
                    <strong id="pay_res_name" class="text-dark small"></strong>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted small text-uppercase fw-semibold">Charge</span>
                    <span id="pay_title" class="text-dark small fw-medium"></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                    <span class="text-muted small text-uppercase fw-semibold">Amount Due</span>
                    <span class="h5 font-bold text-success m-0" id="pay_amount"></span>
                </div>
            </div>

            <!-- Auto-Generated Receipt ID -->
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label fw-semibold small mb-0"><i class="fa-solid fa-receipt text-primary me-1"></i> Auto-Generated Receipt ID</label>
                    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none text-primary fw-semibold" onclick="refreshStaffReceiptNumber()" style="font-size: 0.78rem;">
                        <i class="fa-solid fa-arrows-rotate me-1" id="staffRefreshIcon"></i> Regenerate
                    </button>
                </div>
                <input type="text" name="receipt_number" id="pay_receipt_number" readonly class="form-control font-monospace fw-bold text-primary" style="background:#eff6ff; border-color:#93c5fd;">
                <small class="text-muted" style="font-size: 0.72rem;">Unique Receipt ID auto-generated separate from the Invoice ID.</small>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold small">Payment Method</label>
                <select name="payment_method" id="staff_payment_method" onchange="handleStaffMethodChange(this.value)" class="form-select" required>
                    <?php if (!empty($staff_pm_list)): ?>
                        <?php foreach ($staff_pm_list as $pm): ?>
                            <option value="<?php echo htmlspecialchars($pm['code']); ?>"><?php echo htmlspecialchars($pm['name']); ?></option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="bank_transfer">Bank Transfer / EFT</option>
                        <option value="pos">POS Terminal / Debit Card</option>
                        <option value="cash">Cash at Estate Office</option>
                        <option value="cheque">Cheque</option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label fw-semibold small mb-0">Transaction Reference / Receipt Ref</label>
                    <span class="badge bg-success-subtle text-success fw-bold" style="font-size: 0.72rem;">
                        <i class="fa-solid fa-bolt me-1"></i> Auto-Filled
                    </span>
                </div>
                <input type="text" name="transaction_ref" id="staff_transaction_ref" class="form-control font-monospace fw-semibold" style="background: #f8fafc;" required>
                <small class="text-muted" style="font-size: 0.72rem;">Auto-generated from payment type and Receipt ID. No manual typing required.</small>
            </div>

            <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                <button type="button" class="btn btn-light" onclick="closePaymentModal()">Cancel</button>
                <button type="submit" name="record_payment" class="btn btn-success px-4 fw-semibold">Confirm Payment & Issue Receipt</button>
            </div>
        </form>
    </div>
</div>

<!-- Create Bill / Invoice Modal -->
<div id="invoice-modal" class="custom-modal-backdrop" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 1050; align-items: center; justify-content: center; padding: 1.5rem 1rem; overflow-y: auto;">
    <div class="modal-content bg-white rounded-4 shadow-lg p-4" style="max-width: 520px; width: 100%;">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3">
            <h5 class="fw-bold text-slate-800 m-0">Create New Bill / Invoice</h5>
            <button type="button" class="btn-close" onclick="closeInvoiceModal()"></button>
        </div>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label fw-semibold small">Select Resident</label>
                <select name="user_id" class="form-select" required>
                    <option value="">-- Choose Resident --</option>
                    <?php if ($residents_res): while($r = $residents_res->fetch_assoc()): ?>
                        <option value="<?php echo $r['user_id']; ?>">
                            <?php echo htmlspecialchars($r['name']); ?> (Flat <?php echo htmlspecialchars($r['flat_number'] ?? 'N/A'); ?>)
                        </option>
                    <?php endwhile; endif; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold small">Charge Template (Optional)</label>
                <select name="charge_id" class="form-select" onchange="autoFillCharge(this)">
                    <option value="">-- Custom Charge --</option>
                    <?php if ($charges_res): while($c = $charges_res->fetch_assoc()): ?>
                        <option value="<?php echo $c['id']; ?>" data-amount="<?php echo $c['amount']; ?>" data-title="<?php echo htmlspecialchars($c['name']); ?>">
                            <?php echo htmlspecialchars($c['name']); ?> (₦<?php echo number_format($c['amount'], 2); ?>)
                        </option>
                    <?php endwhile; endif; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold small">Bill Title / Description</label>
                <input type="text" name="title" id="inv_title_input" required placeholder="e.g. Estate Service Charge - September" class="form-control">
            </div>

            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Amount (₦)</label>
                    <input type="number" step="0.01" name="amount" id="inv_amount_input" required class="form-control" placeholder="0.00">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Due Date</label>
                    <input type="date" name="due_date" value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>" required class="form-control">
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                <button type="button" class="btn btn-light" onclick="closeInvoiceModal()">Cancel</button>
                <button type="submit" name="create_invoice" class="btn btn-primary px-4 fw-semibold" style="background: #0f766e; border: none;">Generate Bill</button>
            </div>
        </form>
    </div>
</div>

<script>
function getStaffMethodPrefix(code) {
    switch(code) {
        case 'cash': return 'CSH';
        case 'bank_transfer': return 'TRF';
        case 'pos': return 'POS';
        case 'cheque': return 'CHQ';
        default: 
            return (code ? code.replace(/[^a-zA-Z0-9]/g, '').slice(0, 4).toUpperCase() : 'TXN') || 'TXN';
    }
}

function updateStaffAutoTransactionRef() {
    const sel = document.getElementById('staff_payment_method');
    const method = sel ? sel.value : 'cash';
    const recInput = document.getElementById('pay_receipt_number');
    const receiptNo = recInput ? recInput.value : '';
    const suffix = receiptNo ? receiptNo.replace(/^REC-/, '') : '';
    const prefix = getStaffMethodPrefix(method);
    const refInput = document.getElementById('staff_transaction_ref');
    if (refInput) {
        refInput.value = prefix + '-' + (suffix || Math.floor(100000 + Math.random() * 900000));
    }
}

function handleStaffMethodChange(val) {
    updateStaffAutoTransactionRef();
}

function refreshStaffReceiptNumber() {
    const icon = document.getElementById('staffRefreshIcon');
    if (icon) icon.classList.add('fa-spin');
    
    fetch('../api/payment_methods?action=generate_receipt_number')
        .then(res => res.json())
        .then(data => {
            if (data.success && data.receipt_number) {
                document.getElementById('pay_receipt_number').value = data.receipt_number;
                updateStaffAutoTransactionRef();
            }
            if (icon) icon.classList.remove('fa-spin');
        })
        .catch(() => {
            const d = new Date();
            const yy = String(d.getFullYear()).slice(-2);
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const dd = String(d.getDate()).padStart(2, '0');
            const rnd = Math.floor(100 + Math.random() * 900);
            document.getElementById('pay_receipt_number').value = `REC-${yy}${mm}${dd}-${rnd}`;
            updateStaffAutoTransactionRef();
            if (icon) icon.classList.remove('fa-spin');
        });
}

function openPaymentModal(inv) {
    document.getElementById('payment-modal').style.display = 'flex';
    document.getElementById('pay_inv_id').value = inv.id;
    document.getElementById('pay_inv_no_badge').innerText = inv.invoice_number || ('INV-' + inv.id);
    document.getElementById('pay_res_name').innerText = inv.resident_name + ' (Flat ' + (inv.flat_number || 'N/A') + ')';
    document.getElementById('pay_title').innerText = inv.title;
    document.getElementById('pay_amount').innerText = '₦' + parseFloat(inv.amount).toLocaleString('en-US', {minimumFractionDigits: 2});
    
    // Auto generate receipt ID and auto-fill transaction ref
    refreshStaffReceiptNumber();
}

function closePaymentModal() {
    document.getElementById('payment-modal').style.display = 'none';
}

function openInvoiceModal() {
    document.getElementById('invoice-modal').style.display = 'flex';
}

function closeInvoiceModal() {
    document.getElementById('invoice-modal').style.display = 'none';
}

function autoFillCharge(select) {
    const opt = select.options[select.selectedIndex];
    if (opt.dataset.title) {
        document.getElementById('inv_title_input').value = opt.dataset.title;
    }
    if (opt.dataset.amount) {
        document.getElementById('inv_amount_input').value = opt.dataset.amount;
    }
}
</script>

<?php include 'footer.php'; ?>
