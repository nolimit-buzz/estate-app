<?php
// admin/finance.php
require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/Mailer.php';
include '../includes/header.php';
include '../includes/sidebar.php';

requirePermission('finance.view_invoices');

$estate_id = get_estate_id();
$success = "";
$error = "";

// Handle Resend Invoice / Receipt Email Trigger
if (isset($_GET['resend_inv'])) {
    $r_inv_id = intval($_GET['resend_inv']);
    if (EstateMailer::sendInvoiceEmail($conn, $r_inv_id)) {
        $success = "Invoice email dispatched to resident successfully!";
    } else {
        $error = "Failed to dispatch invoice email. Please check your SMTP settings and logs.";
    }
}
if (isset($_GET['resend_rec'])) {
    $r_rec_no = $_GET['resend_rec'];
    if (EstateMailer::sendReceiptEmail($conn, $r_rec_no)) {
        $success = "Receipt notification email dispatched to resident successfully!";
    } else {
        $error = "Failed to dispatch receipt email. Please check your SMTP settings and logs.";
    }
}

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
        $inv_total = floatval($inv['amount']);
        $current_balance = floatval($inv['balance'] > 0 ? $inv['balance'] : $inv_total);
        
        // Accept partial/installment amount or settle full balance
        $submitted_amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        if ($submitted_amount <= 0 || $submitted_amount > $current_balance) {
            $pay_amount = $current_balance;
        } else {
            $pay_amount = $submitted_amount;
        }
        
        $prev_paid = floatval($inv['amount_paid'] ?? 0);
        $new_amount_paid = $prev_paid + $pay_amount;
        $new_balance = max(0, $inv_total - $new_amount_paid);
        $new_status = ($new_balance <= 0.009) ? 'paid' : 'partially_paid';
        $prop_id = !empty($inv['property_id']) ? intval($inv['property_id']) : "NULL";
        $inv_zone_id = !empty($inv['zone_id']) ? intval($inv['zone_id']) : "NULL";
        
        // Update Invoice status
        $conn->query("UPDATE invoices SET status = '$new_status', amount_paid = $new_amount_paid, balance = $new_balance WHERE id = $invoice_id AND estate_id = $estate_id");
        
        // Create Payment record for the exact amount received
        $desc_note = ($new_status === 'partially_paid') ? "Manual Installment (Bal: ₦" . number_format($new_balance, 2) . ")" : "Manual Full Settlement";
        $conn->query("INSERT INTO payments (estate_id, zone_id, user_id, invoice_id, property_id, amount, type, payment_method, status, payment_reference, transaction_ref, paid_at, description) 
                      VALUES ($estate_id, $inv_zone_id, $res_user_id, $invoice_id, $prop_id, $pay_amount, '{$inv['title']}', '$payment_method', 'paid', '$ref', '$ref', NOW(), '$desc_note')");
        $payment_id = $conn->insert_id;
        
        // Update invoice_installments milestones if applicable
        $inst_res = $conn->query("SELECT id, amount, amount_paid FROM invoice_installments WHERE invoice_id = $invoice_id AND status != 'paid' ORDER BY installment_number ASC LIMIT 1");
        if ($inst_res && $inst_row = $inst_res->fetch_assoc()) {
            $inst_id = $inst_row['id'];
            $inst_paid_new = floatval($inst_row['amount_paid']) + $pay_amount;
            $inst_st = ($inst_paid_new >= floatval($inst_row['amount']) - 0.01) ? 'paid' : 'partially_paid';
            $conn->query("UPDATE invoice_installments SET amount_paid = $inst_paid_new, status = '$inst_st', paid_at = NOW() WHERE id = $inst_id");
        }
        
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
                      VALUES ($estate_id, '$receipt_no', $payment_id, $res_user_id, $prop_id, $pay_amount, $user_id, NOW())");
        
        // Dispatch Email Receipt to Resident
        EstateMailer::sendReceiptEmail($conn, $receipt_no);

        $admin_info = $conn->query("SELECT name, role FROM users WHERE id = $user_id LIMIT 1")->fetch_assoc();
        $staff_label = ($admin_info['name'] ?? 'Staff') . ' (' . ucfirst($admin_info['role'] ?? 'Admin') . ')';

        $inv_label = $inv['invoice_number'] ?: ('INV-' . $invoice_id);
        $pay_type_str = ($new_status === 'partially_paid') ? "Installment Payment of ₦" . number_format($pay_amount, 2) . " (Remaining Balance: ₦" . number_format($new_balance, 2) . ")" : "Full Settlement of ₦" . number_format($pay_amount, 2);
        logAudit($conn, "Manual Payment Recorded", "Finance", "Invoice #$inv_label: $pay_type_str. Receipt: $receipt_no issued by $staff_label via $payment_method");
        $success = "Payment of ₦" . number_format($pay_amount, 2) . " recorded successfully! Receipt #<strong style='font-family:monospace;'>$receipt_no</strong> issued by $staff_label for Invoice #<strong style='font-family:monospace;'>$inv_label</strong>.";
        if ($new_status === 'partially_paid') {
            $success .= " Invoice status updated to <strong>PARTIALLY PAID</strong> (Remaining balance: ₦" . number_format($new_balance, 2) . ").";
        }
    }
}

// Handle Bulk & Single Invoicing Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_invoice'])) {
    $target_type = $_POST['target_type']; // 'single', 'street', 'all'
    $title = $conn->real_escape_string(trim($_POST['title']));
    $amount = floatval($_POST['amount']);
    $due_date = $conn->real_escape_string($_POST['due_date']);
    $charge_id = !empty($_POST['charge_id']) ? intval($_POST['charge_id']) : 'NULL';
    
    // Check if selected charge allows installments
    $charge_allows_inst = 1;
    if (!empty($charge_id) && $charge_id !== 'NULL') {
        $chk_c = $conn->query("SELECT allow_installments FROM estate_charges WHERE id = $charge_id LIMIT 1");
        if ($chk_c && $crow = $chk_c->fetch_assoc()) {
            $charge_allows_inst = intval($crow['allow_installments'] ?? 1);
        }
    }

    $targets = [];
    if ($target_type === 'single') {
        $u_id = intval($_POST['user_id']);
        if ($u_id) {
            $chk_r = $conn->query("
                SELECT r.user_id, r.flat_id, s.zone_id 
                FROM residents r 
                LEFT JOIN flats f ON r.flat_id = f.id 
                LEFT JOIN buildings b ON f.building_id = b.id 
                LEFT JOIN streets s ON b.street_id = s.id 
                WHERE r.user_id = $u_id AND r.estate_id = $estate_id 
                LIMIT 1
            ");
            if ($chk_r && $row_r = $chk_r->fetch_assoc()) {
                $targets[] = $row_r;
            } else {
                $targets[] = ['user_id' => $u_id, 'flat_id' => null, 'zone_id' => null];
            }
        }
    } elseif ($target_type === 'street') {
        $street_id = intval($_POST['street_id']);
        $res = $conn->query("
            SELECT DISTINCT r.user_id, r.flat_id, s.zone_id 
            FROM residents r 
            JOIN flats f ON r.flat_id = f.id 
            JOIN buildings b ON f.building_id = b.id 
            JOIN streets s ON b.street_id = s.id 
            WHERE b.street_id = $street_id AND r.estate_id = $estate_id AND r.status = 'active'
        ");
        while ($row = $res->fetch_assoc()) {
            $targets[] = $row;
        }
    } elseif ($target_type === 'all') {
        $res = $conn->query("
            SELECT u.id as user_id, r.flat_id, s.zone_id 
            FROM users u 
            LEFT JOIN residents r ON (u.id = r.user_id AND r.status = 'active') 
            LEFT JOIN flats f ON r.flat_id = f.id 
            LEFT JOIN buildings b ON f.building_id = b.id 
            LEFT JOIN streets s ON b.street_id = s.id 
            WHERE u.role = 'resident' AND u.estate_id = $estate_id
        ");
        while ($row = $res->fetch_assoc()) {
            $targets[] = $row;
        }
    }
    
    $count = 0;
    foreach ($targets as $tg) {
        $r_user_id = intval($tg['user_id']);
        $r_flat_id = !empty($tg['flat_id']) ? intval($tg['flat_id']) : "NULL";
        $r_zone_id = !empty($tg['zone_id']) ? intval($tg['zone_id']) : "NULL";
        $inv_no = generateInvoiceNumber($conn);
        
        $sql = "INSERT INTO invoices (estate_id, zone_id, user_id, flat_id, charge_id, invoice_number, title, amount, subtotal, balance, issue_date, due_date, status) 
                VALUES ($estate_id, $r_zone_id, $r_user_id, $r_flat_id, $charge_id, '$inv_no', '$title', $amount, $amount, $amount, CURRENT_DATE(), '$due_date', 'unpaid')";
        if ($conn->query($sql)) {
            $new_inv_id = $conn->insert_id;
            
            // Fetch appropriate billing settings (zone-specific or central estate default)
            $target_zone_int = !empty($tg['zone_id']) ? intval($tg['zone_id']) : 0;
            $b_settings = null;
            if ($target_zone_int > 0) {
                $zs_res = $conn->query("SELECT * FROM zonal_billing_settings WHERE zone_id = $target_zone_int LIMIT 1");
                if ($zs_res && $zs_res->num_rows > 0) $b_settings = $zs_res->fetch_assoc();
            }
            if (!$b_settings) {
                $zs_res = $conn->query("SELECT * FROM zonal_billing_settings WHERE zone_id = 0 LIMIT 1");
                if ($zs_res && $zs_res->num_rows > 0) $b_settings = $zs_res->fetch_assoc();
            }
            
            // Automatically generate installment schedule if enabled
            if ($b_settings && $b_settings['allow_installments'] && $charge_allows_inst) {
                $first_pct = floatval($b_settings['min_first_payment_percent'] ?? 40.00);
                $subsequent_times = intval($b_settings['max_subsequent_payments'] ?? 3);
                $interval_days = intval($b_settings['installment_interval_days'] ?? 30);
                $split_mode = $b_settings['split_mode'] ?? 'equal_remainder';
                
                // 1st Milestone (Initial Downpayment)
                $first_amt = round($amount * ($first_pct / 100), 2);
                $conn->query("INSERT INTO invoice_installments (estate_id, zone_id, invoice_id, installment_number, title, percentage, amount, due_date, status) 
                              VALUES ($estate_id, $target_zone_int, $new_inv_id, 1, '1st Installment (Downpayment)', $first_pct, $first_amt, '$due_date', 'pending')");
                
                // Subsequent Milestones
                $rem_pct = 100.00 - $first_pct;
                $rem_amt = $amount - $first_amt;
                $sub_parts = [];
                if ($split_mode === 'custom_percentages' && !empty($b_settings['subsequent_percentages'])) {
                    $raw_p = explode(',', $b_settings['subsequent_percentages']);
                    foreach ($raw_p as $rp) {
                        $v = floatval(trim($rp));
                        if ($v > 0) $sub_parts[] = $v;
                    }
                }
                if (empty($sub_parts)) {
                    $eq_pct = round($rem_pct / $subsequent_times, 2);
                    for ($s = 0; $s < $subsequent_times; $s++) $sub_parts[] = $eq_pct;
                }
                
                $allocated_amt = 0;
                foreach ($sub_parts as $sidx => $sp) {
                    $s_num = $sidx + 2;
                    $is_last = ($sidx === count($sub_parts) - 1);
                    $s_amt = $is_last ? ($rem_amt - $allocated_amt) : round($amount * ($sp / 100), 2);
                    $allocated_amt += $s_amt;
                    $days_offset = ($sidx + 1) * $interval_days;
                    $s_due = date('Y-m-d', strtotime($due_date . " +$days_offset days"));
                    $suffix = ($s_num == 2) ? 'nd' : (($s_num == 3) ? 'rd' : 'th');
                    
                    $conn->query("INSERT INTO invoice_installments (estate_id, zone_id, invoice_id, installment_number, title, percentage, amount, due_date, status) 
                                  VALUES ($estate_id, $target_zone_int, $new_inv_id, $s_num, '{$s_num}{$suffix} Installment', $sp, $s_amt, '$s_due', 'pending')");
                }
            }
            
            // Dispatch Invoice Email to Resident
            EstateMailer::sendInvoiceEmail($conn, $new_inv_id);
            $count++;
        }
    }
    
    logAudit($conn, "Invoices Generated", "Finance", "Generated $count invoice(s) for title: $title (₦$amount)");
    $success = "Successfully generated and dispatched $count invoice(s)!";
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
    $raw_method = strtolower(trim($row['payment_method'] ?? ''));
    $normalized_key = $raw_method;
    if (strpos($raw_method, 'paystack') === 0 || $raw_method === 'card' || $raw_method === 'online') {
        $normalized_key = 'paystack';
    } elseif ($raw_method === 'transfer' || $raw_method === 'wire' || $raw_method === 'bank_transfer' || $raw_method === 'bank') {
        $normalized_key = 'bank_transfer';
    } elseif ($raw_method === 'cash' || $raw_method === 'counter') {
        $normalized_key = 'cash';
    } elseif ($raw_method === 'pos' || $raw_method === 'terminal') {
        $normalized_key = 'pos';
    } elseif ($raw_method === 'cheque' || $raw_method === 'check') {
        $normalized_key = 'cheque';
    }

    if (!isset($method_totals[$normalized_key])) {
        $method_totals[$normalized_key] = ['total' => 0, 'cnt' => 0, 'payment_method' => $normalized_key];
    }
    $method_totals[$normalized_key]['total'] += floatval($row['total']);
    $method_totals[$normalized_key]['cnt'] += intval($row['cnt']);
}

// Fetch Recent Invoices with Receipt & Issuing Staff Info + Installment Count
$invoices_result = $conn->query("SELECT i.*, u.name as resident_name, 
                                        r.receipt_number, r.issued_at, r.issued_by,
                                        u_staff.name as issued_by_name, u_staff.role as issued_by_role,
                                        (SELECT COUNT(*) FROM invoice_installments ii WHERE ii.invoice_id = i.id) as installment_count,
                                        (SELECT COUNT(*) FROM invoice_installments ii WHERE ii.invoice_id = i.id AND ii.status = 'paid') as installments_paid
                                  FROM invoices i 
                                  JOIN users u ON i.user_id = u.id 
                                  LEFT JOIN payments p ON i.id = p.invoice_id
                                  LEFT JOIN receipts r ON r.payment_id = p.id
                                  LEFT JOIN users u_staff ON r.issued_by = u_staff.id
                                  WHERE i.estate_id = $estate_id 
                                  GROUP BY i.id
                                  ORDER BY i.created_at DESC LIMIT 100");

// Fetch Realized Payments Ledger
$payments_result = $conn->query("
    SELECT p.*, u.name as resident_name, i.invoice_number, r.receipt_number 
    FROM payments p 
    LEFT JOIN users u ON p.user_id = u.id 
    LEFT JOIN invoices i ON p.invoice_id = i.id 
    LEFT JOIN receipts r ON r.payment_id = p.id 
    WHERE p.estate_id = $estate_id 
    ORDER BY p.id DESC LIMIT 100
");

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

<div class="page-header-futuristic mb-4">
    <div>
        <div class="header-breadcrumbs">
            <span>Treasury</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span>Billing & Collections</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span class="active">Control Hub</span>
        </div>
        <h1 class="page-title">Finance & Billing Operations</h1>
        <p class="page-subtitle">Central treasury console: automated billing, multi-channel collections, ledger reconciliation, and receipts.</p>
    </div>
    <div class="header-actions">
        <button type="button" onclick="exportFinanceCSV()" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-file-export me-1"></i> Export Ledger
        </button>
        <button type="button" onclick="openPaymentMethodsModal()" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-credit-card me-1"></i> Payment Channels
        </button>
        <a href="charges" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-list-check me-1"></i> Charge Catalog
        </a>
        <button onclick="document.getElementById('invoiceModal').style.display='flex'" class="btn btn-sm text-white" style="background: #0f172a;">
            <i class="fa-solid fa-plus me-1"></i> Invoicing Dispatch
        </button>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert mature-card p-3 mb-4" style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); color: #059669; border-radius: 0.65rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fa-solid fa-circle-check" style="font-size: 1.1rem;"></i>
        <div style="font-weight: 500; font-size: 0.9rem;"><?= $success ?></div>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert mature-card p-3 mb-4" style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; border-radius: 0.65rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fa-solid fa-circle-exclamation" style="font-size: 1.1rem;"></i>
        <div style="font-weight: 500; font-size: 0.9rem;"><?= $error ?></div>
    </div>
<?php endif; ?>

<!-- ==========================================
     EXECUTIVE FINANCIAL KPIS (4 PILLARS)
     ========================================== -->
<div class="row g-3 mb-4">
    <!-- Pillar 1: Today's Collection -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Today's Collections</span>
                    <div class="kpi-value">₦<?= number_format($today_collection, 2) ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-receipt"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Current Settlement Date</span>
                <span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-bolt me-1"></i> Live Inflow</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: 100%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 2: Month Collection -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Month's Realization (<?= date('M Y') ?>)</span>
                    <div class="kpi-value">₦<?= number_format($month_collection, 2) ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-vault"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Month-to-Date Volume</span>
                <span class="mature-badge mature-badge-sky"><?= date('F') ?></span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: 75%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 3: Total Outstanding -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Total Outstanding Dues</span>
                    <div class="kpi-value">₦<?= number_format($outstanding_stat, 2) ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span><?= $unpaid_residents ?> resident(s) pending</span>
                <span class="mature-badge mature-badge-amber">Active Dues</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?= ($outstanding_stat > 0) ? '65%' : '0%'; ?>;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 4: Overdue Delinquency -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Overdue Delinquency</span>
                    <div class="kpi-value">₦<?= number_format($overdue_stat, 2) ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Requires Notice Follow-up</span>
                <span class="mature-badge <?= ($overdue_stat > 0) ? 'mature-badge-crimson' : 'mature-badge-emerald'; ?>">
                    <?= ($overdue_stat > 0) ? 'Past Due Date' : 'Clean Ledger'; ?>
                </span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?= ($overdue_stat > 0) ? '40%' : '0%'; ?>;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Financial Channel Breakdown Report -->
<div class="mature-card mb-4">
    <div class="mature-card-header">
        <div>
            <h3 class="mature-card-title">
                <i class="fa-solid fa-chart-pie text-secondary"></i> Payment Method Reconciliation Matrix
            </h3>
            <p style="margin: 0.2rem 0 0; font-size: 0.8rem; color: var(--text-muted);">Aggregated distribution across electronic gateways, counter cash, POS terminals, and wire transfers.</p>
        </div>
        <button type="button" onclick="openPaymentMethodsModal()" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-plus me-1"></i> Manage Types
        </button>
    </div>
    <div class="mature-card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 1rem;">
            <?php 
            $default_channels = [
                'paystack' => 'Paystack Online',
                'cash' => 'Cash / Counter',
                'bank_transfer' => 'Bank Transfer',
                'pos' => 'POS Terminal',
                'cheque' => 'Cheque'
            ];
            foreach ($payment_methods_list as $pm) {
                if (!isset($default_channels[$pm['code']])) {
                    $default_channels[$pm['code']] = $pm['name'];
                }
            }
            foreach ($default_channels as $m_key => $m_label): 
                $m_data = $method_totals[$m_key] ?? ['total' => 0, 'cnt' => 0];
            ?>
                <div style="background: rgba(148, 163, 184, 0.05); padding: 0.85rem 1rem; border-radius: 8px; border: 1px solid var(--border-color);">
                    <div style="font-size: 0.72rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em;"><?= htmlspecialchars($m_label) ?></div>
                    <div style="font-size: 1.15rem; font-weight: 700; color: var(--text-color); margin-top: 0.25rem;">₦<?= number_format($m_data['total'], 2) ?></div>
                    <div style="font-size: 0.74rem; color: var(--text-muted); margin-top: 2px;"><i class="fa-solid fa-hashtag" style="opacity: 0.5;"></i> <?= $m_data['cnt'] ?> Transactions</div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Dual Ledger Tabs (Invoices vs Realized Payments) -->
<div class="futuristic-tabs">
    <button class="futuristic-tab-btn tab-btn active" onclick="openFinanceTab(event, 'invoices_tab')">
        <i class="fa-solid fa-file-invoice-dollar"></i> Issued Invoices 
        <span class="tech-chip" style="padding: 1px 6px;"><?= $invoices_result ? $invoices_result->num_rows : 0 ?></span>
    </button>
    <button class="futuristic-tab-btn tab-btn" onclick="openFinanceTab(event, 'payments_tab')">
        <i class="fa-solid fa-receipt"></i> Realized Payments 
        <span class="tech-chip" style="padding: 1px 6px;"><?= $payments_result ? $payments_result->num_rows : 0 ?></span>
    </button>
</div>

<!-- TAB 1: INVOICES LEDGER -->
<div id="invoices_tab" class="finance-tab-content">
    <div class="futuristic-filter-bar mb-3">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-stretch align-items-md-center gap-3">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <button class="filter-btn-pill active" onclick="setInvoiceStatusFilter('all', this)">
                    <i class="fa-solid fa-list-ul me-1"></i> All (<?= $invoices_result ? $invoices_result->num_rows : 0 ?>)
                </button>
                <button class="filter-btn-pill" onclick="setInvoiceStatusFilter('unpaid', this)">
                    <i class="fa-solid fa-clock me-1"></i> Unpaid
                </button>
                <button class="filter-btn-pill" onclick="setInvoiceStatusFilter('partially_paid', this)">
                    <i class="fa-solid fa-chart-pie me-1"></i> Partially Paid
                </button>
                <button class="filter-btn-pill" onclick="setInvoiceStatusFilter('paid', this)">
                    <i class="fa-solid fa-circle-check me-1"></i> Paid
                </button>
                <button class="filter-btn-pill" onclick="setInvoiceStatusFilter('overdue', this)">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i> Overdue
                </button>
            </div>
            <div class="position-relative" style="min-width: 280px;">
                <i class="fa-solid fa-magnifying-glass position-absolute text-muted" style="top: 50%; left: 0.85rem; transform: translateY(-50%); font-size: 0.85rem;"></i>
                <input type="text" id="invoiceSearchInput" class="form-control ps-5" placeholder="Search by resident, invoice #, charge title..." onkeyup="filterInvoicesTable()">
            </div>
        </div>
    </div>

    <div class="mature-card mb-4">
        <div class="mature-card-header">
            <div>
                <h2 class="mature-card-title">
                    <i class="fa-solid fa-file-invoice-dollar text-secondary"></i> Issued Resident Invoices
                </h2>
                <p style="margin: 0.25rem 0 0; font-size: 0.8rem; color: var(--text-muted);">Comprehensive billing records, installment milestone progress, and automated receipt issuance.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="tech-chip"><i class="fa-solid fa-file-invoice"></i> Showing: <span id="visibleInvoiceCount"><?= $invoices_result ? $invoices_result->num_rows : 0 ?></span></span>
                <button type="button" onclick="exportFinanceCSV()" class="btn btn-sm btn-outline-secondary" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;">
                    <i class="fa-solid fa-file-arrow-down me-1"></i> Export CSV
                </button>
            </div>
        </div>
        
        <div class="mature-card-body p-0">
            <div class="table-responsive">
                <table class="table dashboard-table align-middle" id="invoicesTable">
                    <thead>
                        <tr>
                            <th style="width: 130px;">Invoice #</th>
                            <th>Resident Name</th>
                            <th>Charge Title</th>
                            <th>Amount</th>
                            <th>Due Date</th>
                            <th>Status & Milestones</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="invoicesTableBody">
                        <?php if($invoices_result && $invoices_result->num_rows > 0): ?>
                            <?php while($row = $invoices_result->fetch_assoc()): ?>
                            <?php 
                                $inv_display_no = $row['invoice_number'] ?: ('INV-' . sprintf("%04d", $row['id'])); 
                                $status_val = strtolower($row['status']);
                                $inv_amount = floatval($row['amount']);
                                $inv_paid = floatval($row['amount_paid'] ?? 0);
                                $inv_balance = floatval($row['balance'] > 0 ? $row['balance'] : max(0, $inv_amount - $inv_paid));
                                $has_milestones = intval($row['installment_count'] ?? 0) > 0;
                                $search_meta = strtolower($inv_display_no . ' ' . $row['resident_name'] . ' ' . $row['title'] . ' ' . $row['amount'] . ' ' . $row['status']);
                            ?>
                            <tr class="invoice-row" data-status="<?= htmlspecialchars($status_val) ?>" data-search="<?= htmlspecialchars($search_meta) ?>">
                                <td>
                                    <span class="id-chip"><?= htmlspecialchars($inv_display_no) ?></span>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--text-color); font-size: 0.92rem;"><?= htmlspecialchars($row['resident_name']) ?></div>
                                </td>
                                <td>
                                    <span style="font-size: 0.86rem; color: var(--text-muted); font-weight: 500;"><?= htmlspecialchars($row['title']) ?></span>
                                    <?php if ($has_milestones): ?>
                                        <div class="mt-1">
                                            <span class="badge bg-light text-primary border" style="font-size: 0.68rem; font-weight: 600;">
                                                <i class="fa-solid fa-chart-pie me-1"></i> <?= intval($row['installments_paid']) ?>/<?= intval($row['installment_count']) ?> Milestones
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-weight: 700; color: var(--text-color); font-size: 0.92rem;">₦<?= number_format($row['amount'], 2) ?></span>
                                </td>
                                <td style="font-size: 0.82rem; color: var(--text-muted);">
                                    <?= date('M j, Y', strtotime($row['due_date'])) ?>
                                </td>
                                <td>
                                    <?php if ($status_val == 'paid'): ?>
                                        <span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-circle-check me-1"></i> PAID</span>
                                    <?php elseif ($status_val == 'partially_paid'): ?>
                                        <span class="mature-badge mature-badge-amber"><i class="fa-solid fa-clock-rotate-left me-1"></i> PARTIALLY PAID</span>
                                        <div style="font-size: 0.72rem; color: #059669; margin-top: 3px; font-weight: 600;">
                                            Paid: ₦<?= number_format($inv_paid, 2) ?>
                                        </div>
                                        <div style="font-size: 0.72rem; color: #ef4444; font-weight: 600;">
                                            Bal: ₦<?= number_format($inv_balance, 2) ?>
                                        </div>
                                    <?php elseif ($status_val == 'unpaid'): ?>
                                        <span class="mature-badge mature-badge-crimson"><i class="fa-solid fa-circle-xmark me-1"></i> UNPAID</span>
                                    <?php elseif ($status_val == 'overdue'): ?>
                                        <span class="mature-badge mature-badge-crimson"><i class="fa-solid fa-triangle-exclamation me-1"></i> OVERDUE</span>
                                    <?php else: ?>
                                        <span class="mature-badge mature-badge-slate"><?= strtoupper($row['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <?php if ($status_val != 'paid'): ?>
                                        <button type="button" onclick="openRecordPaymentModal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['resident_name'])) ?>', <?= $inv_balance ?>, '<?= htmlspecialchars(addslashes($inv_display_no)) ?>', <?= $inv_amount ?>, <?= $inv_paid ?>)" class="btn btn-sm" style="background:#0f172a; color:white; border:none; padding:0.35rem 0.75rem; border-radius:0.4rem; cursor:pointer; font-weight:600; font-size:0.8rem; display:inline-flex; align-items:center; gap:4px;">
                                            <i class="fa-solid fa-receipt"></i> Record Pay
                                        </button>
                                        <a href="finance.php?resend_inv=<?= $row['id'] ?>" title="Resend Invoice Email" class="btn btn-sm btn-outline-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.8rem; display: inline-flex; align-items: center; margin-left: 4px;" onclick="return confirm('Send invoice notification to resident?');">
                                            <i class="fa-solid fa-envelope"></i>
                                        </a>
                                    <?php else: ?>
                                        <div style="display: inline-flex; align-items: center; gap: 0.35rem;">
                                            <a href="../resident/receipt?invoice_id=<?= $row['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" style="font-weight: 600; font-size: 0.8rem; display:inline-flex; align-items:center; gap:4px; padding: 0.35rem 0.75rem;">
                                                <i class="fa-solid fa-receipt text-primary"></i> Receipt
                                            </a>
                                            <?php if (!empty($row['receipt_number'])): ?>
                                                <a href="finance.php?resend_rec=<?= urlencode($row['receipt_number']) ?>" title="Resend Receipt Email" class="btn btn-sm btn-outline-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.8rem; display: inline-flex; align-items: center;" onclick="return confirm('Resend receipt email to resident?');">
                                                    <i class="fa-solid fa-envelope"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($row['issued_by_name'])): ?>
                                            <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 3px; font-weight: 500;">
                                                <i class="fa-solid fa-user-check" style="color: #10b981;"></i> <?= htmlspecialchars($row['issued_by_name']) ?>
                                            </div>
                                        <?php elseif (!empty($row['receipt_number'])): ?>
                                            <div style="font-size: 0.72rem; color: #10b981; margin-top: 3px; font-weight: 500;">
                                                <i class="fa-solid fa-bolt"></i> Electronic Gateway
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="text-align: center; padding: 3.5rem 1rem; color: var(--text-muted);">No invoices found. Click "Invoicing Dispatch" above to generate your first invoice.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- TAB 2: REALIZED PAYMENTS LEDGER -->
<div id="payments_tab" class="finance-tab-content" style="display: none;">
    <div class="mature-card mb-4">
        <div class="mature-card-header">
            <div>
                <h2 class="mature-card-title">
                    <i class="fa-solid fa-receipt text-secondary"></i> Realized Transactions & Payments Ledger
                </h2>
                <p style="margin: 0.25rem 0 0; font-size: 0.8rem; color: var(--text-muted);">Verified settled inflows, electronic gateway confirmations, and issued receipts.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="tech-chip"><i class="fa-solid fa-money-bill-transfer"></i> Total: <?= $payments_result ? $payments_result->num_rows : 0 ?></span>
            </div>
        </div>
        
        <div class="mature-card-body p-0">
            <div class="table-responsive">
                <table class="table dashboard-table align-middle">
                    <thead>
                        <tr>
                            <th style="width: 140px;">Payment Ref</th>
                            <th>Resident</th>
                            <th>Invoice Ref</th>
                            <th>Amount Paid</th>
                            <th>Method</th>
                            <th>Settlement Date</th>
                            <th style="text-align: right;">Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($payments_result && $payments_result->num_rows > 0): ?>
                            <?php while($p_row = $payments_result->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <span class="id-chip"><?= htmlspecialchars($p_row['reference'] ?: ('PAY-' . $p_row['id'])) ?></span>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--text-color); font-size: 0.92rem;"><?= htmlspecialchars($p_row['resident_name'] ?: 'Resident') ?></div>
                                </td>
                                <td>
                                    <span class="id-chip" style="font-size: 0.72rem;"><?= htmlspecialchars($p_row['invoice_number'] ?: ('INV-' . $p_row['invoice_id'])) ?></span>
                                </td>
                                <td>
                                    <span style="font-weight: 700; color: #059669; font-size: 0.95rem;">₦<?= number_format($p_row['amount'], 2) ?></span>
                                </td>
                                <td>
                                    <span class="mature-badge mature-badge-slate text-uppercase">
                                        <?= htmlspecialchars(str_replace('_', ' ', $p_row['payment_method'] ?: 'Payment')) ?>
                                    </span>
                                </td>
                                <td style="font-size: 0.82rem; color: var(--text-muted);">
                                    <?= date('M j, Y h:i A', strtotime($p_row['paid_at'] ?: $p_row['created_at'])) ?>
                                </td>
                                <td style="text-align: right;">
                                    <a href="../resident/receipt?invoice_id=<?= $p_row['invoice_id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" style="font-weight: 600; font-size: 0.78rem;">
                                        <i class="fa-solid fa-receipt text-primary me-1"></i> View Receipt
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="text-align: center; padding: 3.5rem 1rem; color: var(--text-muted);">No realized payments recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
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
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem;" id="rec_breakdown_row">
                    <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #64748b;">Total Value / Paid</span>
                    <span id="rec_total_val" style="font-weight: 600; color: #334155; font-size: 0.88rem;">₦0.00</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #64748b;">Current Outstanding Balance</span>
                    <span id="rec_amount" style="font-weight: 800; color: #059669; font-size: 1.2rem;">₦0.00</span>
                </div>
            </div>

            <!-- Collection Amount Input (Full Settlement or Partial Installment) -->
            <div style="margin-bottom: 1.25rem;">
                <label style="display:block; font-weight: 600; color: #334155; font-size: 0.85rem; margin-bottom: 0.35rem;">
                    Payment Amount Received (₦) <span class="text-danger">*</span>
                </label>
                <div class="input-group">
                    <span class="input-group-text fw-bold" style="background: #f8fafc; border-color: #cbd5e1;">₦</span>
                    <input type="number" step="0.01" min="1" name="amount" id="rec_payment_amount" required class="form-control" style="font-size: 1.1rem; font-weight: 700; color: #059669; border-color: #cbd5e1; border-radius: 0 0.5rem 0.5rem 0;">
                </div>
                <small style="color: #64748b; font-size: 0.74rem; display: block; margin-top: 0.25rem;">
                    Enter full balance or partial milestone amount. Invoice status automatically tracks remaining balance.
                </small>
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

function openRecordPaymentModal(invId, resName, amount, invNumber, totalAmount, amountPaid) {
    document.getElementById('rec_invoice_id').value = invId;
    document.getElementById('rec_invoice_number_badge').innerText = invNumber || ('INV-' + invId);
    document.getElementById('rec_resident_name').innerText = resName;
    document.getElementById('rec_amount').innerText = "₦" + parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2});
    
    if (totalAmount) {
        let paidTxt = amountPaid > 0 ? ` (Paid: ₦${parseFloat(amountPaid).toLocaleString('en-US', {minimumFractionDigits: 2})})` : '';
        document.getElementById('rec_total_val').innerText = "₦" + parseFloat(totalAmount).toLocaleString('en-US', {minimumFractionDigits: 2}) + paidTxt;
        document.getElementById('rec_breakdown_row').style.display = 'flex';
    } else {
        document.getElementById('rec_breakdown_row').style.display = 'none';
    }
    
    const amtInput = document.getElementById('rec_payment_amount');
    if (amtInput) {
        amtInput.value = parseFloat(amount).toFixed(2);
        amtInput.max = parseFloat(amount);
    }
    
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

// Dual Ledger Tab Switching
function openFinanceTab(evt, tabId) {
    const tabContents = document.querySelectorAll('.finance-tab-content');
    tabContents.forEach(el => el.style.display = 'none');
    
    const tabButtons = document.querySelectorAll('.futuristic-tab-btn');
    tabButtons.forEach(btn => btn.classList.remove('active'));
    
    const target = document.getElementById(tabId);
    if (target) {
        target.style.display = 'block';
    }
    if (evt && evt.currentTarget) {
        evt.currentTarget.classList.add('active');
    }
}

// Invoice Ledger Status Filtering & Live Search
let currentInvoiceStatusFilter = 'all';

function setInvoiceStatusFilter(status, btn) {
    currentInvoiceStatusFilter = status;
    const filterBtns = btn.closest('.futuristic-filter-bar').querySelectorAll('.filter-btn-pill');
    filterBtns.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    filterInvoicesTable();
}

function filterInvoicesTable() {
    const searchInput = document.getElementById('invoiceSearchInput');
    const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
    const rows = document.querySelectorAll('#invoicesTableBody .invoice-row');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const rowStatus = row.getAttribute('data-status') || '';
        const rowSearch = row.getAttribute('data-search') || '';
        
        const matchesStatus = (currentInvoiceStatusFilter === 'all') || (rowStatus === currentInvoiceStatusFilter);
        const matchesSearch = !query || rowSearch.includes(query);
        
        if (matchesStatus && matchesSearch) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
    const counterEl = document.getElementById('visibleInvoiceCount');
    if (counterEl) {
        counterEl.innerText = visibleCount;
    }
}

// Export Invoices to CSV
function exportFinanceCSV() {
    const rows = document.querySelectorAll('#invoicesTable tr');
    let csv = [];
    
    rows.forEach(row => {
        if (row.style.display === 'none') return;
        const cols = row.querySelectorAll('th, td');
        let rowData = [];
        // Extract first 6 columns (exclude Actions column)
        for (let i = 0; i < Math.min(cols.length, 6); i++) {
            let text = cols[i].innerText.replace(/(\r\n|\n|\r)/gm, ' ').replace(/\s+/g, ' ').trim();
            text = text.replace(/"/g, '""');
            rowData.push('"' + text + '"');
        }
        if (rowData.length > 0) {
            csv.push(rowData.join(','));
        }
    });
    
    const csvContent = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv.join('\n'));
    const link = document.createElement('a');
    link.setAttribute('href', csvContent);
    link.setAttribute('download', 'estate_invoices_ledger_' + new Date().toISOString().slice(0, 10) + '.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php include '../includes/footer.php'; ?>
