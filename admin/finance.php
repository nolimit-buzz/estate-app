<?php
// admin/finance.php
require_once '../config.php';
require_once '../includes/auth_guard.php';
include '../includes/header.php';
include '../includes/sidebar.php';

requirePermission('finance.view_invoices');

$estate_id = get_estate_id();
$success = "";
$error = "";

// Handle Manual Payment Approval / Recording
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['record_manual_payment'])) {
    $invoice_id = intval($_POST['invoice_id']);
    $payment_method = $conn->real_escape_string($_POST['payment_method']);
    $ref = $conn->real_escape_string($_POST['transaction_ref'] ?: ('MAN-' . time()));
    $submitted_receipt_no = isset($_POST['receipt_number']) ? trim($_POST['receipt_number']) : '';
    $user_id = $_SESSION['user_id'] ?? null;
    
    // Fetch Invoice
    $inv_res = $conn->query("SELECT * FROM invoices WHERE id = $invoice_id AND estate_id = $estate_id");
    if ($inv_res && $inv_res->num_rows > 0) {
        $inv = $inv_res->fetch_assoc();
        $res_user_id = $inv['user_id'];
        $amount = $inv['amount'];
        $prop_id = !empty($inv['property_id']) ? intval($inv['property_id']) : "NULL";
        
        // Update Invoice status to paid
        $conn->query("UPDATE invoices SET status = 'paid', amount_paid = $amount, balance = 0 WHERE id = $invoice_id AND estate_id = $estate_id");
        
        // Create Payment record
        $conn->query("INSERT INTO payments (estate_id, user_id, invoice_id, property_id, amount, type, payment_method, status, payment_reference, transaction_ref, paid_at, description) 
                      VALUES ($estate_id, $res_user_id, $invoice_id, $prop_id, $amount, '{$inv['title']}', '$payment_method', 'paid', '$ref', '$ref', NOW(), 'Manual Offline Payment')");
        $payment_id = $conn->insert_id;
        
        // Generate or assign unique auto receipt number
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
        
        $conn->query("INSERT INTO receipts (estate_id, receipt_number, payment_id, resident_id, property_id, amount, issued_by, issued_at) 
                      VALUES ($estate_id, '$receipt_no', $payment_id, $res_user_id, $prop_id, $amount, $user_id, NOW())");
        
        $admin_info = $conn->query("SELECT name, role FROM users WHERE id = $user_id LIMIT 1")->fetch_assoc();
        $staff_label = ($admin_info['name'] ?? 'Staff') . ' (' . ucfirst($admin_info['role'] ?? 'Admin') . ')';

        $inv_label = $inv['invoice_number'] ?: ('INV-' . $invoice_id);
        logAudit($conn, "Manual Payment Recorded", "Finance", "Invoice #$inv_label marked paid. Receipt: $receipt_no issued by $staff_label via $payment_method");
        $success = "Payment recorded successfully! Receipt #<strong style='font-family:monospace;'>$receipt_no</strong> issued by $staff_label for Invoice #<strong style='font-family:monospace;'>$inv_label</strong>.";
    }
}

// Handle Bulk & Single Invoicing Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_invoice'])) {
    $target_type = $_POST['target_type']; // 'single', 'street', 'all'
    $title = $conn->real_escape_string($_POST['title']);
    $amount = floatval($_POST['amount']);
    $due_date = $conn->real_escape_string($_POST['due_date']);
    $charge_id = !empty($_POST['charge_id']) ? intval($_POST['charge_id']) : 'NULL';
    
    $residents_to_bill = [];
    if ($target_type === 'single') {
        $u_id = intval($_POST['user_id']);
        if ($u_id) $residents_to_bill[] = $u_id;
    } elseif ($target_type === 'street') {
        $street_id = intval($_POST['street_id']);
        $res = $conn->query("SELECT DISTINCT r.user_id FROM residents r JOIN flats f ON r.flat_id = f.id JOIN buildings b ON f.building_id = b.id WHERE b.street_id = $street_id AND r.estate_id = $estate_id AND r.status = 'active'");
        while ($row = $res->fetch_assoc()) $residents_to_bill[] = $row['user_id'];
    } elseif ($target_type === 'all') {
        $res = $conn->query("SELECT id FROM users WHERE role = 'resident' AND estate_id = $estate_id");
        while ($row = $res->fetch_assoc()) $residents_to_bill[] = $row['id'];
    }
    
    $count = 0;
    foreach ($residents_to_bill as $r_user_id) {
        $inv_no = generateInvoiceNumber($conn);
        $sql = "INSERT INTO invoices (estate_id, invoice_number, user_id, charge_id, title, amount, subtotal, balance, issue_date, due_date, status) 
                VALUES ($estate_id, '$inv_no', $r_user_id, $charge_id, '$title', $amount, $amount, $amount, CURRENT_DATE(), '$due_date', 'unpaid')";
        if ($conn->query($sql)) $count++;
    }
    
    logAudit($conn, "Invoices Generated", "Finance", "Generated $count invoice(s) for title: $title (₦$amount)");
    $success = "Successfully generated $count invoice(s)!";
}

// Fetch Executive Stats
$today_collection = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE estate_id = $estate_id AND status = 'paid' AND DATE(paid_at) = CURRENT_DATE()")->fetch_assoc()['total'] ?? 0;
$month_collection = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE estate_id = $estate_id AND status = 'paid' AND MONTH(paid_at) = MONTH(CURRENT_DATE()) AND YEAR(paid_at) = YEAR(CURRENT_DATE())")->fetch_assoc()['total'] ?? 0;
$outstanding_stat = $conn->query("SELECT COALESCE(SUM(balance), 0) as total FROM invoices WHERE estate_id = $estate_id AND status != 'paid'")->fetch_assoc()['total'] ?? 0;
$overdue_stat = $conn->query("SELECT COALESCE(SUM(balance), 0) as total FROM invoices WHERE estate_id = $estate_id AND status != 'paid' AND due_date < CURRENT_DATE()")->fetch_assoc()['total'] ?? 0;

$total_residents = $conn->query("SELECT COUNT(id) as cnt FROM users WHERE role = 'resident' AND estate_id = $estate_id")->fetch_assoc()['cnt'] ?? 0;
$paying_residents = $conn->query("SELECT COUNT(DISTINCT user_id) as cnt FROM invoices WHERE estate_id = $estate_id AND status = 'paid'")->fetch_assoc()['cnt'] ?? 0;
$unpaid_residents = max(0, $total_residents - $paying_residents);

// Fetch Payment Method Breakdown Report
$channel_report = $conn->query("SELECT payment_method, COALESCE(SUM(amount), 0) as total, COUNT(id) as cnt 
                                FROM payments WHERE estate_id = $estate_id AND status = 'paid' 
                                GROUP BY payment_method");
$method_totals = [];
while ($row = $channel_report->fetch_assoc()) {
    $method_totals[$row['payment_method']] = $row;
}

// Fetch Recent Invoices with Receipt & Issuing Staff Info
$invoices_result = $conn->query("SELECT i.*, u.name as resident_name, 
                                        r.receipt_number, r.issued_at, r.issued_by,
                                        u_staff.name as issued_by_name, u_staff.role as issued_by_role
                                  FROM invoices i 
                                  JOIN users u ON i.user_id = u.id 
                                  LEFT JOIN payments p ON i.id = p.invoice_id
                                  LEFT JOIN receipts r ON r.payment_id = p.id
                                  LEFT JOIN users u_staff ON r.issued_by = u_staff.id
                                  WHERE i.estate_id = $estate_id 
                                  ORDER BY i.created_at DESC LIMIT 100");

// Data for Form
$residents_result = $conn->query("SELECT id, name FROM users WHERE role = 'resident' AND estate_id = $estate_id ORDER BY name ASC");
$streets_result = $conn->query("SELECT id, name FROM streets WHERE estate_id = $estate_id ORDER BY name ASC");
$charges_catalog = $conn->query("SELECT id, name, amount FROM estate_charges WHERE estate_id = $estate_id AND status = 'Active' ORDER BY name ASC");

// Fetch Dynamic Payment Methods with Creator Staff Info
$pm_res = $conn->query("SELECT pm.*, u.name as creator_name, u.role as creator_role 
                        FROM payment_methods pm 
                        LEFT JOIN users u ON pm.created_by = u.id 
                        WHERE pm.estate_id = $estate_id AND pm.status = 'active' 
                        ORDER BY pm.is_system DESC, pm.id ASC");
$payment_methods_list = [];
if ($pm_res) {
    while ($pm = $pm_res->fetch_assoc()) {
        $payment_methods_list[] = $pm;
    }
}
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h1>Estate Finance & Accounting Control</h1>
        <p style="color: #64748b; margin: 0;">Central financial hub: Bulk invoicing, collections, reconciliation, and receipts</p>
    </div>
    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
        <button type="button" onclick="openPaymentMethodsModal()" class="btn" style="background:#f8fafc;color:#334155;padding:10px 15px;border-radius:5px;text-decoration:none;font-weight:600; border:1px solid #cbd5e1; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
            <i class="fa-solid fa-credit-card" style="color: #0284c7;"></i> Payment Methods
        </button>
        <a href="charges" class="btn" style="background:#f1f5f9;color:#475569;padding:10px 15px;border-radius:5px;text-decoration:none;font-weight:600;"><i class="fa-solid fa-list-check"></i> Charge Catalog</a>
        <button onclick="document.getElementById('invoiceModal').style.display='flex'" class="btn btn-primary" style="background:#3b82f6;color:#fff;padding:10px 15px;border-radius:5px;border:none;cursor:pointer; font-weight:600; box-shadow: 0 2px 4px rgba(59, 130, 246, 0.3);">+ Bulk / Single Invoice</button>
    </div>
</div>

<?php if ($success): ?>
    <div style="background-color: #dcfce7; color: #166534; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; border: 1px solid #bbf7d0; font-weight: 600;">
        <i class="fa-solid fa-circle-check" style="margin-right: 6px;"></i> <?= $success ?>
    </div>
<?php endif; ?>

<!-- Executive KPIs -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin-bottom: 2rem;">
    <div class="glass" style="padding: 1.25rem; border-radius: 0.75rem; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white;">
        <h3 style="margin: 0; font-size: 0.85rem; opacity: 0.9; text-transform: uppercase;">Today's Collection</h3>
        <p style="font-size: 1.8rem; font-weight: 700; margin: 0.3rem 0 0;">₦<?= number_format($today_collection, 2) ?></p>
    </div>
    <div class="glass" style="padding: 1.25rem; border-radius: 0.75rem; background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color: white;">
        <h3 style="margin: 0; font-size: 0.85rem; opacity: 0.9; text-transform: uppercase;">This Month's Revenue</h3>
        <p style="font-size: 1.8rem; font-weight: 700; margin: 0.3rem 0 0;">₦<?= number_format($month_collection, 2) ?></p>
    </div>
    <div class="glass" style="padding: 1.25rem; border-radius: 0.75rem; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white;">
        <h3 style="margin: 0; font-size: 0.85rem; opacity: 0.9; text-transform: uppercase;">Total Outstanding</h3>
        <p style="font-size: 1.8rem; font-weight: 700; margin: 0.3rem 0 0;">₦<?= number_format($outstanding_stat, 2) ?></p>
    </div>
    <div class="glass" style="padding: 1.25rem; border-radius: 0.75rem; background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white;">
        <h3 style="margin: 0; font-size: 0.85rem; opacity: 0.9; text-transform: uppercase;">Overdue Amount</h3>
        <p style="font-size: 1.8rem; font-weight: 700; margin: 0.3rem 0 0;">₦<?= number_format($overdue_stat, 2) ?></p>
    </div>
</div>

<!-- Financial Channel Breakdown Report -->
<div class="glass" style="padding: 1.5rem; border-radius: 1rem; background: #fff; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 2rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
        <h3 style="margin: 0; color: #1e293b; font-size: 1.1rem; display: flex; align-items: center; gap: 0.5rem;">
            <i class="fa-solid fa-chart-pie" style="color: #3b82f6;"></i> Payment Method Reconciliation Breakdown
        </h3>
        <button type="button" onclick="openPaymentMethodsModal()" style="background: #f0fdf4; border: 1px solid #86efac; color: #166534; padding: 6px 14px; border-radius: 6px; font-size: 0.82rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
            <i class="fa-solid fa-plus-circle"></i> + Add / Manage Payment Types
        </button>
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
        <?php 
        $methods = [
            'paystack_card' => 'Paystack Card', 
            'paystack_transfer' => 'Paystack Transfer', 
            'paystack_ussd' => 'Paystack USSD', 
            'bank_transfer' => 'Bank Transfer', 
            'cash' => 'Cash / Counter',
            'pos' => 'POS Terminal'
        ];
        foreach ($payment_methods_list as $custom_pm) {
            if (!isset($methods[$custom_pm['code']])) {
                $methods[$custom_pm['code']] = $custom_pm['name'];
            }
        }
        foreach ($methods as $m_key => $m_label):
            $m_data = $method_totals[$m_key] ?? ['total' => 0, 'cnt' => 0];
        ?>
            <div style="background: #f8fafc; padding: 1rem; border-radius: 0.5rem; border: 1px solid #e2e8f0;">
                <div style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase;"><?= htmlspecialchars($m_label) ?></div>
                <div style="font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-top: 0.25rem;">₦<?= number_format($m_data['total'], 2) ?></div>
                <div style="font-size: 0.75rem; color: #94a3b8;"><?= $m_data['cnt'] ?> Transactions</div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Invoices Table -->
<div class="glass" style="padding: 2rem; border-radius: 1rem; background: rgba(255,255,255,0.95); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
    <h2 style="margin-top: 0; border-bottom: 1px solid #e2e8f0; padding-bottom: 1rem;">Issued Resident Invoices</h2>
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 0.95em;">
            <thead>
                <tr style="border-bottom: 2px solid #e2e8f0; text-align: left; color: #64748b;">
                    <th style="padding: 12px 10px;">Invoice #</th>
                    <th style="padding: 12px 10px;">Resident Name</th>
                    <th style="padding: 12px 10px;">Charge Title</th>
                    <th style="padding: 12px 10px;">Amount</th>
                    <th style="padding: 12px 10px;">Due Date</th>
                    <th style="padding: 12px 10px;">Status</th>
                    <th style="padding: 12px 10px; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if($invoices_result->num_rows > 0): ?>
                    <?php while($row = $invoices_result->fetch_assoc()): ?>
                    <?php $inv_display_no = $row['invoice_number'] ?: ('INV-' . sprintf("%04d", $row['id'])); ?>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 12px 10px; font-family: monospace; font-weight: 600; color: #2563eb;"><?= htmlspecialchars($inv_display_no) ?></td>
                        <td style="padding: 12px 10px; font-weight: 500;"><?= htmlspecialchars($row['resident_name']) ?></td>
                        <td style="padding: 12px 10px; color: #475569;"><?= htmlspecialchars($row['title']) ?></td>
                        <td style="padding: 12px 10px; font-weight: 700; color: #0f172a;">₦<?= number_format($row['amount'], 2) ?></td>
                        <td style="padding: 12px 10px; color: #64748b;"><?= date('M j, Y', strtotime($row['due_date'])) ?></td>
                        <td style="padding: 12px 10px;">
                            <span style="padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; background: <?= $row['status'] == 'paid' ? '#dcfce7; color: #166534;' : ($row['status'] == 'unpaid' ? '#fee2e2; color: #991b1b;' : '#fef3c7; color: #92400e;') ?>">
                                <?= $row['status'] ?>
                            </span>
                        </td>
                        <td style="padding: 12px 10px; text-align: right;">
                            <?php if ($row['status'] != 'paid'): ?>
                                <button type="button" onclick="openRecordPaymentModal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['resident_name'])) ?>', <?= $row['amount'] ?>, '<?= htmlspecialchars(addslashes($inv_display_no)) ?>')" style="background:#10b981;color:white;border:none;padding:6px 12px;border-radius:6px;cursor:pointer;font-weight:600;font-size:0.8rem;display:inline-flex;align-items:center;gap:4px;box-shadow:0 2px 4px rgba(16,185,129,0.25);">
                                    <i class="fa-solid fa-receipt"></i> Record Offline Payment
                                </button>
                            <?php else: ?>
                                <a href="../resident/receipt?invoice_id=<?= $row['id'] ?>" target="_blank" style="color: #2563eb; text-decoration: none; font-weight: 600; font-size: 0.85rem; display:inline-flex; align-items:center; gap:4px; padding: 5px 10px; background:#eff6ff; border-radius: 6px;">
                                    <i class="fa-solid fa-receipt"></i> View Receipt
                                </a>
                                <?php if (!empty($row['issued_by_name'])): ?>
                                    <div style="font-size: 0.72rem; color: #475569; margin-top: 3px; font-weight: 500;">
                                        <i class="fa-solid fa-user-check text-success"></i> <?= htmlspecialchars($row['issued_by_name']) ?> (<?= ucfirst($row['issued_by_role'] ?? 'Staff') ?>)
                                    </div>
                                <?php elseif (!empty($row['receipt_number'])): ?>
                                    <div style="font-size: 0.72rem; color: #059669; margin-top: 3px; font-weight: 500;">
                                        <i class="fa-solid fa-bolt"></i> Electronic Gateway
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align: center; padding: 2rem; color: #94a3b8;">No invoices found. Generate one to get started.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Bulk / Single Invoicing Modal -->
<div id="invoiceModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15, 23, 42, 0.6); z-index:1000; align-items:center; justify-content:center; backdrop-filter: blur(4px);">
    <div style="background:#fff; padding:2.5rem; border-radius:1rem; width:100%; max-width: 520px; margin: 5vh auto; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);">
        <h2 style="margin-top:0; margin-bottom:1.5rem; color: #1e293b;">Generate Estate Invoices</h2>
        <form method="POST">
            <div style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom:0.5rem; font-weight: 600; color: #475569;">Invoicing Target</label>
                <select name="target_type" id="target_type" onchange="toggleTargetFields()" required style="width:100%; padding:0.75rem; border-radius:0.5rem; border:1px solid #cbd5e1; outline: none;">
                    <option value="single">Single Resident</option>
                    <option value="street">All Residents on Particular Street</option>
                    <option value="all">All Active Estate Residents (Bulk)</option>
                </select>
            </div>

            <div id="field_single" style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom:0.5rem; font-weight: 500; color: #475569;">Select Resident</label>
                <select name="user_id" style="width:100%; padding:0.75rem; border-radius:0.5rem; border:1px solid #cbd5e1; outline: none;">
                    <option value="">Select Resident...</option>
                    <?php 
                    if ($residents_result) {
                        $residents_result->data_seek(0);
                        while($r = $residents_result->fetch_assoc()) {
                            echo "<option value='{$r['id']}'>" . htmlspecialchars($r['name']) . "</option>";
                        }
                    }
                    ?>
                </select>
            </div>

            <div id="field_street" style="margin-bottom: 1rem; display: none;">
                <label style="display:block; margin-bottom:0.5rem; font-weight: 500; color: #475569;">Select Street</label>
                <select name="street_id" style="width:100%; padding:0.75rem; border-radius:0.5rem; border:1px solid #cbd5e1; outline: none;">
                    <option value="">Select Street...</option>
                    <?php 
                    if ($streets_result) {
                        $streets_result->data_seek(0);
                        while($st = $streets_result->fetch_assoc()) {
                            echo "<option value='{$st['id']}'>" . htmlspecialchars($st['name']) . "</option>";
                        }
                    }
                    ?>
                </select>
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom:0.5rem; font-weight: 500; color: #475569;">Select Charge Catalog Item (Optional)</label>
                <select id="charge_catalog_select" onchange="autoFillCharge()" style="width:100%; padding:0.75rem; border-radius:0.5rem; border:1px solid #cbd5e1; outline: none;">
                    <option value="">-- Custom Charge --</option>
                    <?php 
                    if ($charges_catalog) {
                        $charges_catalog->data_seek(0);
                        while($c = $charges_catalog->fetch_assoc()) {
                            echo "<option value='{$c['id']}' data-name='" . htmlspecialchars($c['name']) . "' data-amount='{$c['amount']}'>" . htmlspecialchars($c['name']) . " (₦" . number_format($c['amount']) . ")</option>";
                        }
                    }
                    ?>
                </select>
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom:0.5rem; font-weight: 500; color: #475569;">Invoice Title</label>
                <input type="text" name="title" id="inv_title" placeholder="e.g. 2026 Annual Service Charge" required style="width:100%; padding:0.75rem; border-radius:0.5rem; border:1px solid #cbd5e1; outline: none;">
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom:0.5rem; font-weight: 500; color: #475569;">Amount (₦)</label>
                <input type="number" step="0.01" name="amount" id="inv_amount" placeholder="0.00" required style="width:100%; padding:0.75rem; border-radius:0.5rem; border:1px solid #cbd5e1; outline: none;">
            </div>

            <div style="margin-bottom: 2rem;">
                <label style="display:block; margin-bottom:0.5rem; font-weight: 500; color: #475569;">Due Date</label>
                <input type="date" name="due_date" required value="<?= date('Y-m-d', strtotime('+30 days')) ?>" style="width:100%; padding:0.75rem; border-radius:0.5rem; border:1px solid #cbd5e1; outline: none;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" onclick="document.getElementById('invoiceModal').style.display='none'" style="padding:0.75rem 1.5rem; border:none; background:#f1f5f9; color: #475569; border-radius:0.5rem; cursor:pointer; font-weight:600;">Cancel</button>
                <button type="submit" name="generate_invoice" style="padding:0.75rem 1.5rem; border:none; background:#3b82f6; color:#fff; border-radius:0.5rem; cursor:pointer; font-weight:600;">Generate Invoice(s)</button>
            </div>
        </form>
    </div>
</div>

<!-- Record Payment Modal -->
<div id="recordModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15, 23, 42, 0.6); z-index:1000; align-items:center; justify-content:center; backdrop-filter: blur(4px);">
    <div style="background:#fff; padding:2rem; border-radius:1rem; width:100%; max-width: 520px; margin: 4vh auto; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15); max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
            <div>
                <h2 style="margin: 0; color: #1e293b; font-size: 1.25rem;">Record Payment & Issue Receipt</h2>
                <p style="margin: 0.2rem 0 0; color: #64748b; font-size: 0.8rem;">Each transaction receives a distinct Invoice ID and unique Receipt ID</p>
            </div>
            <button type="button" onclick="closeRecordPaymentModal()" style="background: #f1f5f9; border: none; width: 32px; height: 32px; border-radius: 50%; font-size: 1.2rem; line-height: 1; cursor: pointer; color: #64748b; display: flex; align-items: center; justify-content: center;">&times;</button>
        </div>

        <form method="POST" id="manualPaymentForm">
            <input type="hidden" name="invoice_id" id="rec_invoice_id">

            <!-- Card displaying separate Invoice ID, Resident and Amount -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 1rem; margin-bottom: 1.25rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem;">
                    <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #64748b;">Invoice ID / Number</span>
                    <span id="rec_invoice_number_badge" style="font-family: monospace; font-weight: 700; background: #eff6ff; color: #1d4ed8; padding: 3px 8px; border-radius: 4px; font-size: 0.88rem; border: 1px solid #bfdbfe;">INV-000000</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem;">
                    <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #64748b;">Resident</span>
                    <span id="rec_resident_name" style="font-weight: 600; color: #1e293b; font-size: 0.9rem;">-</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #64748b;">Total Amount</span>
                    <span id="rec_amount" style="font-weight: 800; color: #059669; font-size: 1.2rem;">₦0.00</span>
                </div>
            </div>

            <!-- Auto-Generated Receipt ID Field -->
            <div style="margin-bottom: 1.25rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                    <label style="display:block; font-weight: 600; color: #334155; font-size: 0.85rem;">
                        <i class="fa-solid fa-receipt text-primary me-1"></i> Auto-Generated Receipt ID
                    </label>
                    <button type="button" onclick="refreshReceiptNumber()" title="Generate fresh receipt number" style="background: none; border: none; color: #2563eb; font-size: 0.78rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                        <i class="fa-solid fa-arrows-rotate" id="refreshReceiptIcon"></i> Regenerate
                    </button>
                </div>
                <div style="position: relative;">
                    <input type="text" name="receipt_number" id="rec_receipt_number" readonly style="width:100%; padding:0.75rem; border-radius:0.5rem; border:1px solid #93c5fd; background: #eff6ff; font-family: monospace; font-weight: 700; color: #1e40af; font-size: 1rem; outline: none;">
                </div>
                <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 0.25rem;">
                    Auto-generated for non-Paystack offline payment. Stored independently from the Invoice ID.
                </small>
            </div>

            <!-- Dynamic Payment Method Selection -->
            <div style="margin-bottom: 1.25rem;">
                <label style="display:block; font-weight: 600; color: #334155; font-size: 0.85rem; margin-bottom: 0.35rem;">Payment Method</label>
                <select name="payment_method" id="rec_payment_method" onchange="handlePaymentMethodChange(this.value)" required style="width:100%; padding:0.75rem; border-radius:0.5rem; border:1px solid #cbd5e1; outline: none; font-size: 0.95rem; background: #fff;">
                    <?php if (!empty($payment_methods_list)): ?>
                        <?php foreach ($payment_methods_list as $pm): ?>
                            <option value="<?= htmlspecialchars($pm['code']) ?>"><?= htmlspecialchars($pm['name']) ?></option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="cash">Cash / Counter</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="pos">POS Terminal</option>
                        <option value="cheque">Cheque</option>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Transaction Reference / Teller Number (Auto-Filled) -->
            <div style="margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                    <label style="display:block; font-weight: 600; color: #334155; font-size: 0.85rem; margin-bottom: 0;">Transaction Reference / Teller Number</label>
                    <span style="font-size: 0.72rem; color: #059669; font-weight: 700; background: #dcfce7; padding: 2px 7px; border-radius: 4px;">
                        <i class="fa-solid fa-bolt me-1"></i> Auto-Filled
                    </span>
                </div>
                <input type="text" name="transaction_ref" id="rec_transaction_ref" required style="width:100%; padding:0.75rem; border-radius:0.5rem; border:1px solid #cbd5e1; outline: none; font-size: 0.95rem; font-family: monospace; font-weight: 600; color: #0f172a; background: #f8fafc;">
                <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 0.25rem;">
                    Auto-generated from payment type & Receipt ID. Click confirm without manual typing.
                </small>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" onclick="closeRecordPaymentModal()" style="padding:0.75rem 1.5rem; border:none; background:#f1f5f9; color: #475569; border-radius:0.5rem; cursor:pointer; font-weight:600;">Cancel</button>
                <button type="submit" name="record_manual_payment" style="padding:0.75rem 1.5rem; border:none; background:#10b981; color:#fff; border-radius:0.5rem; cursor:pointer; font-weight:600; display: inline-flex; align-items: center; gap: 0.4rem; box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.3);">
                    <i class="fa-solid fa-receipt"></i> Confirm & Generate Receipt
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Manage & Add Payment Types Modal (On Main Page) -->
<div id="paymentMethodsModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15, 23, 42, 0.6); z-index:1000; align-items:center; justify-content:center; backdrop-filter: blur(4px);">
    <div style="background:#fff; padding:2rem; border-radius:1rem; width:100%; max-width: 520px; margin: 5vh auto; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15); max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
            <div>
                <h3 style="margin: 0; color: #1e293b; font-size: 1.25rem; font-weight: 700;">
                    <i class="fa-solid fa-credit-card text-primary me-1"></i> Payment Types & Channels
                </h3>
                <p style="margin: 0.2rem 0 0; color: #64748b; font-size: 0.82rem;">Manage offline payment methods accepted across the estate</p>
            </div>
            <button type="button" onclick="closePaymentMethodsModal()" style="background: #f1f5f9; border: none; width: 32px; height: 32px; border-radius: 50%; font-size: 1.2rem; line-height: 1; cursor: pointer; color: #64748b; display: flex; align-items: center; justify-content: center;">&times;</button>
        </div>

        <!-- Add New Payment Type Form on Page -->
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 1.25rem; margin-bottom: 1.5rem;">
            <label style="display: block; font-size: 0.85rem; font-weight: 700; color: #1e293b; margin-bottom: 0.4rem;">Add New Payment Type</label>
            <div style="display: flex; gap: 0.5rem;">
                <input type="text" id="page_new_pm_name" placeholder="e.g. Direct Debit, USSD Voucher, Agency Slip" style="flex: 1; padding: 0.7rem 0.85rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; font-size: 0.9rem; outline: none;">
                <button type="button" onclick="saveNewPaymentMethodFromPage()" style="padding: 0.7rem 1.25rem; background: #10b981; color: white; border: none; border-radius: 0.5rem; font-weight: 700; font-size: 0.9rem; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
                    <i class="fa-solid fa-plus"></i> Add
                </button>
            </div>
            <div id="page_add_pm_msg" style="font-size: 0.78rem; margin-top: 0.4rem;"></div>
        </div>

        <!-- Current Payment Methods List -->
        <div>
            <h4 style="font-size: 0.85rem; text-transform: uppercase; font-weight: 700; color: #64748b; margin-bottom: 0.75rem; letter-spacing: 0.05em;">Active Accepted Payment Types</h4>
            <div id="paymentMethodsListContainer" style="display: flex; flex-direction: column; gap: 0.5rem;">
                <?php foreach ($payment_methods_list as $pm): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 0.5rem;">
                        <div>
                            <div>
                                <span style="font-weight: 600; color: #1e293b; font-size: 0.92rem;"><?= htmlspecialchars($pm['name']) ?></span>
                                <span style="font-family: monospace; font-size: 0.75rem; color: #64748b; margin-left: 6px; background: #f1f5f9; padding: 2px 6px; border-radius: 4px;"><?= htmlspecialchars($pm['code']) ?></span>
                            </div>
                            <div style="font-size: 0.75rem; color: #64748b; margin-top: 3px;">
                                <?php if (!empty($pm['creator_name'])): ?>
                                    <i class="fa-solid fa-user-tag text-primary me-1"></i> Created by: <strong style="color: #334155;"><?= htmlspecialchars($pm['creator_name']) ?></strong> (<?= ucfirst($pm['creator_role'] ?? 'Staff') ?>) &bull; <?= date('M d, Y', strtotime($pm['created_at'])) ?>
                                <?php else: ?>
                                    <i class="fa-solid fa-shield-halved text-secondary me-1"></i> Default System Method
                                <?php endif; ?>
                            </div>
                        </div>
                        <span style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; padding: 2px 8px; border-radius: 9999px; background: #dcfce7; color: #166534;">Active</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="display: flex; justify-content: flex-end; margin-top: 1.5rem; border-top: 1px solid #f1f5f9; padding-top: 1rem;">
            <button type="button" onclick="closePaymentMethodsModal()" style="padding: 0.65rem 1.25rem; border: none; background: #f1f5f9; color: #475569; border-radius: 0.5rem; cursor: pointer; font-weight: 600;">Close</button>
        </div>
    </div>
</div>

<script>
function toggleTargetFields() {
    let target = document.getElementById('target_type').value;
    document.getElementById('field_single').style.display = (target === 'single') ? 'block' : 'none';
    document.getElementById('field_street').style.display = (target === 'street') ? 'block' : 'none';
}

function autoFillCharge() {
    let sel = document.getElementById('charge_catalog_select');
    let opt = sel.options[sel.selectedIndex];
    if (opt && opt.value) {
        document.getElementById('inv_title').value = opt.getAttribute('data-name');
        document.getElementById('inv_amount').value = opt.getAttribute('data-amount');
    }
}

function getMethodPrefix(code) {
    switch(code) {
        case 'cash': return 'CSH';
        case 'bank_transfer': return 'TRF';
        case 'pos': return 'POS';
        case 'cheque': return 'CHQ';
        default: 
            return (code ? code.replace(/[^a-zA-Z0-9]/g, '').slice(0, 4).toUpperCase() : 'TXN') || 'TXN';
    }
}

function updateAutoTransactionRef() {
    const sel = document.getElementById('rec_payment_method');
    const method = sel ? sel.value : 'cash';
    const recInput = document.getElementById('rec_receipt_number');
    const receiptNo = recInput ? recInput.value : '';
    const suffix = receiptNo ? receiptNo.replace(/^REC-/, '') : '';
    const prefix = getMethodPrefix(method);
    const refInput = document.getElementById('rec_transaction_ref');
    if (refInput) {
        refInput.value = prefix + '-' + (suffix || Math.floor(100000 + Math.random() * 900000));
    }
}

function openRecordPaymentModal(invId, resName, amount, invNumber) {
    document.getElementById('rec_invoice_id').value = invId;
    document.getElementById('rec_invoice_number_badge').innerText = invNumber || ('INV-' + invId);
    document.getElementById('rec_resident_name').innerText = resName;
    document.getElementById('rec_amount').innerText = "₦" + parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2});
    
    // Auto-generate fresh receipt ID and auto-fill transaction reference
    refreshReceiptNumber();
    
    document.getElementById('recordModal').style.display = 'flex';
}

function closeRecordPaymentModal() {
    document.getElementById('recordModal').style.display = 'none';
}

function refreshReceiptNumber() {
    const icon = document.getElementById('refreshReceiptIcon');
    if (icon) icon.classList.add('fa-spin');
    
    fetch('../api/payment_methods?action=generate_receipt_number')
        .then(res => res.json())
        .then(data => {
            if (data.success && data.receipt_number) {
                document.getElementById('rec_receipt_number').value = data.receipt_number;
                updateAutoTransactionRef();
            }
            if (icon) icon.classList.remove('fa-spin');
        })
        .catch(() => {
            const d = new Date();
            const yy = String(d.getFullYear()).slice(-2);
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const dd = String(d.getDate()).padStart(2, '0');
            const rnd = Math.floor(100 + Math.random() * 900);
            document.getElementById('rec_receipt_number').value = `REC-${yy}${mm}${dd}-${rnd}`;
            updateAutoTransactionRef();
            if (icon) icon.classList.remove('fa-spin');
        });
}

function handlePaymentMethodChange(val) {
    updateAutoTransactionRef();
}

// Main Page Payment Methods Modal Controls
function openPaymentMethodsModal() {
    document.getElementById('paymentMethodsModal').style.display = 'flex';
    document.getElementById('page_new_pm_name').focus();
}

function closePaymentMethodsModal() {
    document.getElementById('paymentMethodsModal').style.display = 'none';
    document.getElementById('page_add_pm_msg').innerHTML = '';
}

function saveNewPaymentMethodFromPage() {
    const input = document.getElementById('page_new_pm_name');
    const msg = document.getElementById('page_add_pm_msg');
    const name = input.value.trim();
    if (!name) {
        msg.innerHTML = '<span style="color:#ef4444;">Please enter a payment type name.</span>';
        return;
    }

    msg.innerHTML = '<span style="color:#64748b;">Saving payment type...</span>';
    fetch('../api/payment_methods', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: name })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.method) {
            msg.innerHTML = '<span style="color:#15803d; font-weight: 600;"><i class="fa-solid fa-check"></i> ' + (data.message || 'Payment method added!') + '</span>';
            
            // Add to modal list view
            const listContainer = document.getElementById('paymentMethodsListContainer');
            if (listContainer) {
                const item = document.createElement('div');
                item.style.cssText = 'display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: #f0fdf4; border: 1px solid #86efac; border-radius: 0.5rem;';
                const creatorTag = data.method.creator_name 
                    ? `<div style="font-size: 0.75rem; color: #166534; margin-top: 3px;"><i class="fa-solid fa-user-tag me-1"></i> Created by: <strong>${data.method.creator_name}</strong> (${data.method.creator_role || 'Staff'}) &bull; Just now</div>`
                    : '';
                item.innerHTML = `<div><div><span style="font-weight: 600; color: #1e293b; font-size: 0.92rem;">${data.method.name}</span><span style="font-family: monospace; font-size: 0.75rem; color: #64748b; margin-left: 6px; background: #ffffff; padding: 2px 6px; border-radius: 4px;">${data.method.code}</span></div>${creatorTag}</div><span style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; padding: 2px 8px; border-radius: 9999px; background: #dcfce7; color: #166534;">Active</span>`;
                listContainer.prepend(item);
            }

            // Also add to recordModal select
            const sel = document.getElementById('rec_payment_method');
            if (sel) {
                let found = false;
                for (let i = 0; i < sel.options.length; i++) {
                    if (sel.options[i].value === data.method.code) {
                        found = true;
                        break;
                    }
                }
                if (!found) {
                    const opt = document.createElement('option');
                    opt.value = data.method.code;
                    opt.textContent = data.method.name;
                    sel.appendChild(opt);
                }
            }

            input.value = '';
            setTimeout(() => {
                msg.innerHTML = '';
            }, 2500);
        } else {
            msg.innerHTML = '<span style="color:#ef4444;">' + (data.message || 'Could not add payment method.') + '</span>';
        }
    })
    .catch(err => {
        msg.innerHTML = '<span style="color:#ef4444;">Error communicating with server.</span>';
    });
}
</script>

<?php include '../includes/footer.php'; ?>
