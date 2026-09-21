<?php
// zone/finance.php - Zonal Finance & Treasury Console (Strictly Scoped)
require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/Mailer.php';

requireZoneAccess();

$zone_id = get_current_zone_id();
$estate_id = get_estate_id();
$success = $flash_message ?? "";
$error = $flash_error ?? "";

$zone_meta = $conn->query("SELECT * FROM zones WHERE id = $zone_id AND estate_id = $estate_id LIMIT 1")->fetch_assoc();

// -------------------------------------------------------------
// HANDLE RESEND INVOICE / RECEIPT EMAIL TRIGGERS
// -------------------------------------------------------------
if (isset($_GET['resend_inv'])) {
    $r_inv_id = intval($_GET['resend_inv']);
    // Verify invoice belongs to this zone
    $chk_inv = $conn->query("
        SELECT i.id 
        FROM invoices i 
        LEFT JOIN flats f ON i.flat_id = f.id 
        LEFT JOIN buildings b ON f.building_id = b.id 
        LEFT JOIN streets s ON b.street_id = s.id 
        WHERE i.id = $r_inv_id AND (i.zone_id = $zone_id OR s.zone_id = $zone_id) AND i.estate_id = $estate_id 
        LIMIT 1
    ");
    if ($chk_inv && $chk_inv->num_rows > 0) {
        EstateMailer::sendInvoiceEmail($conn, $r_inv_id);
        redirectWithFlash('finance', "Invoice notification re-sent successfully!");
    }
}

if (isset($_GET['resend_rec'])) {
    $rec_no = $conn->real_escape_string($_GET['resend_rec']);
    EstateMailer::sendReceiptEmail($conn, $rec_no);
    redirectWithFlash('finance', "Official Receipt #$rec_no re-sent successfully!");
}

// -------------------------------------------------------------
// POST ACTIONS: MANUAL PAYMENT RECORDING & INVOICING DISPATCH (PRG Protected)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. RECORD MANUAL / OFFLINE PAYMENT & ISSUE RECEIPT
    if (isset($_POST['record_manual_payment'])) {
        $invoice_id = intval($_POST['invoice_id']);
        $payment_method = $conn->real_escape_string($_POST['payment_method'] ?? 'bank_transfer');
        $ref = $conn->real_escape_string($_POST['transaction_ref'] ?: ('ZN-MAN-' . time()));
        $submitted_receipt_no = isset($_POST['receipt_number']) ? trim($_POST['receipt_number']) : '';
        $user_id = $_SESSION['user_id'] ?? null;
        
        // Fetch and verify Invoice belongs to this zone
        $inv_res = $conn->query("
            SELECT i.*, u.name as resident_name 
            FROM invoices i 
            LEFT JOIN users u ON i.user_id = u.id 
            LEFT JOIN flats f ON i.flat_id = f.id 
            LEFT JOIN buildings b ON f.building_id = b.id 
            LEFT JOIN streets s ON b.street_id = s.id 
            WHERE i.id = $invoice_id AND (i.zone_id = $zone_id OR s.zone_id = $zone_id) AND i.estate_id = $estate_id 
            LIMIT 1
        ");

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
            
            // Update Invoice status
            $conn->query("UPDATE invoices SET status = '$new_status', amount_paid = $new_amount_paid, balance = $new_balance WHERE id = $invoice_id");
            
            // Create Payment record for the exact amount received
            $desc_note = ($new_status === 'partially_paid') ? "Manual Zonal Installment (Bal: ₦" . number_format($new_balance, 2) . ")" : "Manual Zonal Full Settlement";
            $conn->query("INSERT INTO payments (estate_id, user_id, invoice_id, property_id, amount, type, payment_method, status, payment_reference, transaction_ref, paid_at, description) 
                          VALUES ($estate_id, $res_user_id, $invoice_id, $prop_id, $pay_amount, '{$inv['title']}', '$payment_method', 'paid', '$ref', '$ref', NOW(), '$desc_note')");
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
            $staff_label = ($admin_info['name'] ?? 'Zone Admin') . ' (Zonal Admin)';

            $inv_label = $inv['invoice_number'] ?: ('INV-' . $invoice_id);
            $pay_type_str = ($new_status === 'partially_paid') ? "Installment Payment of ₦" . number_format($pay_amount, 2) . " (Remaining Balance: ₦" . number_format($new_balance, 2) . ")" : "Full Settlement of ₦" . number_format($pay_amount, 2);
            logAudit($conn, "Zone Payment Recorded", "Finance", "Invoice #$inv_label: $pay_type_str. Receipt: $receipt_no issued by $staff_label via $payment_method in Zone #$zone_id");
            $success = "Payment of ₦" . number_format($pay_amount, 2) . " recorded successfully! Receipt #<strong style='font-family:monospace;'>$receipt_no</strong> issued for Invoice #<strong style='font-family:monospace;'>$inv_label</strong>.";
            if ($new_status === 'partially_paid') {
                $success .= " Invoice status updated to <strong>PARTIALLY PAID</strong> (Remaining balance: ₦" . number_format($new_balance, 2) . ").";
            }
            redirectWithFlash('finance', $success);
        } else {
            redirectWithFlash('finance', null, "Unauthorized operation. The selected invoice does not belong to your zone.");
        }
    }

    // 2. GENERATE INVOICES (SINGLE RESIDENT, PARTICULAR STREET, OR BULK ZONE)
    elseif (isset($_POST['generate_invoice'])) {
        $target_type = $_POST['target_type'] ?? 'single'; // 'single', 'street', 'all'
        $title = $conn->real_escape_string(trim($_POST['title'] ?? ''));
        $amount = floatval($_POST['amount'] ?? 0);
        $due_date = $conn->real_escape_string($_POST['due_date'] ?? date('Y-m-d', strtotime('+14 days')));
        $charge_id = !empty($_POST['charge_id']) ? intval($_POST['charge_id']) : 'NULL';
        
        $targets = []; // Array of ['user_id' => ..., 'flat_id' => ...]

        if ($target_type === 'single') {
            $u_id = intval($_POST['user_id']);
            if ($u_id) {
                // Verify resident is strictly in this zone and find flat_id
                $res = $conn->query("
                    SELECT r.user_id, r.flat_id 
                    FROM residents r 
                    JOIN flats f ON r.flat_id = f.id 
                    JOIN buildings b ON f.building_id = b.id 
                    JOIN streets s ON b.street_id = s.id 
                    WHERE r.user_id = $u_id AND s.zone_id = $zone_id AND r.estate_id = $estate_id AND r.status = 'active'
                    LIMIT 1
                ");
                if ($res && $res->num_rows > 0) {
                    $targets[] = $res->fetch_assoc();
                } else {
                    redirectWithFlash('finance', null, "Selected resident does not reside in your zone.");
                }
            }
        } elseif ($target_type === 'street') {
            $street_id = intval($_POST['street_id']);
            // Verify street belongs to this zone
            $res = $conn->query("
                SELECT DISTINCT r.user_id, r.flat_id 
                FROM residents r 
                JOIN flats f ON r.flat_id = f.id 
                JOIN buildings b ON f.building_id = b.id 
                JOIN streets s ON b.street_id = s.id 
                WHERE b.street_id = $street_id AND s.zone_id = $zone_id AND r.estate_id = $estate_id AND r.status = 'active'
            ");
            while ($row = $res->fetch_assoc()) {
                $targets[] = $row;
            }
        } elseif ($target_type === 'all') {
            // Bill all active residents across this zone
            $res = $conn->query("
                SELECT DISTINCT r.user_id, r.flat_id 
                FROM residents r 
                JOIN flats f ON r.flat_id = f.id 
                JOIN buildings b ON f.building_id = b.id 
                JOIN streets s ON b.street_id = s.id 
                WHERE s.zone_id = $zone_id AND r.estate_id = $estate_id AND r.status = 'active'
            ");
            while ($row = $res->fetch_assoc()) {
                $targets[] = $row;
            }
        }

        if (!empty($targets) && $amount > 0 && !empty($title)) {
            // Check if installments are allowed for this charge and this zone
            $charge_allows_inst = 1;
            if (!empty($charge_id) && $charge_id !== 'NULL') {
                $chk_c = $conn->query("SELECT allow_installments FROM estate_charges WHERE id = $charge_id LIMIT 1");
                if ($chk_c && $crow = $chk_c->fetch_assoc()) {
                    $charge_allows_inst = intval($crow['allow_installments'] ?? 1);
                }
            }

            // Fetch Zonal Billing Settings
            $z_settings = $conn->query("SELECT * FROM zonal_billing_settings WHERE zone_id = $zone_id LIMIT 1")->fetch_assoc();

            $count = 0;
            foreach ($targets as $tg) {
                $r_user_id = intval($tg['user_id']);
                $r_flat_id = !empty($tg['flat_id']) ? intval($tg['flat_id']) : "NULL";
                $inv_no = generateInvoiceNumber($conn);
                
                $sql = "INSERT INTO invoices (estate_id, zone_id, user_id, flat_id, charge_id, invoice_number, title, amount, subtotal, balance, issue_date, due_date, status) 
                        VALUES ($estate_id, $zone_id, $r_user_id, $r_flat_id, $charge_id, '$inv_no', '$title', $amount, $amount, $amount, CURRENT_DATE(), '$due_date', 'unpaid')";
                if ($conn->query($sql)) {
                    $new_inv_id = $conn->insert_id;

                    // Automatically generate installment schedule if enabled
                    if ($z_settings && $z_settings['allow_installments'] && $charge_allows_inst) {
                        $first_pct = floatval($z_settings['min_first_payment_percent'] ?? 40.00);
                        $subsequent_times = intval($z_settings['max_subsequent_payments'] ?? 3);
                        $interval_days = intval($z_settings['installment_interval_days'] ?? 30);
                        $split_mode = $z_settings['split_mode'] ?? 'equal_remainder';

                        // 1st Milestone (Initial Downpayment)
                        $first_amt = round($amount * ($first_pct / 100), 2);
                        $conn->query("INSERT INTO invoice_installments (estate_id, zone_id, invoice_id, installment_number, title, percentage, amount, due_date, status) 
                                      VALUES ($estate_id, $zone_id, $new_inv_id, 1, '1st Installment (Downpayment)', $first_pct, $first_amt, '$due_date', 'pending')");

                        // Subsequent Milestones
                        $rem_pct = 100.00 - $first_pct;
                        $rem_amt = $amount - $first_amt;
                        $sub_parts = [];
                        if ($split_mode === 'custom_percentages' && !empty($z_settings['subsequent_percentages'])) {
                            $raw_p = explode(',', $z_settings['subsequent_percentages']);
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
                                          VALUES ($estate_id, $zone_id, $new_inv_id, $s_num, '{$s_num}{$suffix} Installment', $sp, $s_amt, '$s_due', 'pending')");
                        }
                    }

                    // Dispatch Invoice Email to Resident
                    EstateMailer::sendInvoiceEmail($conn, $new_inv_id);
                    $count++;
                }
            }
            
            logAudit($conn, "Zone Invoices Generated", "Finance", "Generated $count zonal invoice(s) for title: $title (₦$amount) in Zone #$zone_id");
            redirectWithFlash('finance', "Successfully generated and dispatched $count zonal invoice(s)!");
        } else {
            redirectWithFlash('finance', null, "No active residents found in the selected target scope, or invalid amount.");
        }
    }
}

// -------------------------------------------------------------
// EXECUTIVE FINANCIAL STATS (4 PILLARS STRICTLY SCOPED TO ZONE)
// -------------------------------------------------------------
$today_stat_res = $conn->query("
    SELECT COALESCE(SUM(p.amount), 0) as total 
    FROM payments p 
    JOIN invoices i ON p.invoice_id = i.id 
    LEFT JOIN flats f ON i.flat_id = f.id 
    LEFT JOIN buildings b ON f.building_id = b.id 
    LEFT JOIN streets s ON b.street_id = s.id 
    WHERE p.estate_id = $estate_id AND p.status = 'paid' 
      AND (i.zone_id = $zone_id OR s.zone_id = $zone_id) 
      AND DATE(p.paid_at) = CURRENT_DATE()
");
$today_collection = $today_stat_res ? (float)$today_stat_res->fetch_assoc()['total'] : 0;

$month_stat_res = $conn->query("
    SELECT COALESCE(SUM(p.amount), 0) as total 
    FROM payments p 
    JOIN invoices i ON p.invoice_id = i.id 
    LEFT JOIN flats f ON i.flat_id = f.id 
    LEFT JOIN buildings b ON f.building_id = b.id 
    LEFT JOIN streets s ON b.street_id = s.id 
    WHERE p.estate_id = $estate_id AND p.status = 'paid' 
      AND (i.zone_id = $zone_id OR s.zone_id = $zone_id) 
      AND MONTH(p.paid_at) = MONTH(CURRENT_DATE()) AND YEAR(p.paid_at) = YEAR(CURRENT_DATE())
");
$month_collection = $month_stat_res ? (float)$month_stat_res->fetch_assoc()['total'] : 0;

$outstanding_stat_res = $conn->query("
    SELECT COALESCE(SUM(i.balance), 0) as total, COUNT(DISTINCT i.user_id) as pending_residents 
    FROM invoices i 
    LEFT JOIN flats f ON i.flat_id = f.id 
    LEFT JOIN buildings b ON f.building_id = b.id 
    LEFT JOIN streets s ON b.street_id = s.id 
    WHERE i.estate_id = $estate_id AND (i.zone_id = $zone_id OR s.zone_id = $zone_id) 
      AND i.status != 'paid'
");
$out_data = $outstanding_stat_res ? $outstanding_stat_res->fetch_assoc() : ['total' => 0, 'pending_residents' => 0];
$outstanding_stat = (float)($out_data['total'] ?? 0);
$unpaid_residents = (int)($out_data['pending_residents'] ?? 0);

$overdue_stat_res = $conn->query("
    SELECT COALESCE(SUM(i.balance), 0) as total 
    FROM invoices i 
    LEFT JOIN flats f ON i.flat_id = f.id 
    LEFT JOIN buildings b ON f.building_id = b.id 
    LEFT JOIN streets s ON b.street_id = s.id 
    WHERE i.estate_id = $estate_id AND (i.zone_id = $zone_id OR s.zone_id = $zone_id) 
      AND i.status != 'paid' AND i.due_date < CURRENT_DATE()
");
$overdue_stat = $overdue_stat_res ? (float)$overdue_stat_res->fetch_assoc()['total'] : 0;

// -------------------------------------------------------------
// PAYMENT METHOD RECONCILIATION BREAKDOWN FOR THIS ZONE
// -------------------------------------------------------------
$channel_report = $conn->query("
    SELECT p.payment_method, COALESCE(SUM(p.amount), 0) as total, COUNT(p.id) as cnt 
    FROM payments p 
    JOIN invoices i ON p.invoice_id = i.id 
    LEFT JOIN flats f ON i.flat_id = f.id 
    LEFT JOIN buildings b ON f.building_id = b.id 
    LEFT JOIN streets s ON b.street_id = s.id 
    WHERE p.estate_id = $estate_id AND p.status = 'paid' 
      AND (i.zone_id = $zone_id OR s.zone_id = $zone_id) 
    GROUP BY p.payment_method
");
$method_totals = [];
if ($channel_report) {
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
}

// -------------------------------------------------------------
// FETCH ISSUED INVOICES WITH RECEIPT & ISSUING STAFF DETAILS
// -------------------------------------------------------------
$invoices_result = $conn->query("
    SELECT i.*, u.name as resident_name, u.email as resident_email, u.phone as resident_phone,
           f.number as flat_number, b.name as building_name, s.name as street_name,
           r.receipt_number, r.issued_at, r.issued_by,
           u_staff.name as issued_by_name, u_staff.role as issued_by_role
    FROM invoices i 
    LEFT JOIN users u ON i.user_id = u.id 
    LEFT JOIN flats f ON i.flat_id = f.id 
    LEFT JOIN buildings b ON f.building_id = b.id 
    LEFT JOIN streets s ON b.street_id = s.id 
    LEFT JOIN payments p ON i.id = p.invoice_id AND p.status = 'paid'
    LEFT JOIN receipts r ON r.payment_id = p.id
    LEFT JOIN users u_staff ON r.issued_by = u_staff.id
    WHERE i.estate_id = $estate_id AND (i.zone_id = $zone_id OR s.zone_id = $zone_id) 
    ORDER BY i.created_at DESC LIMIT 150
");

// -------------------------------------------------------------
// FETCH REALIZED PAYMENTS LEDGER FOR THIS ZONE
// -------------------------------------------------------------
$payments_result = $conn->query("
    SELECT p.*, u.name as resident_name, i.invoice_number, r.receipt_number,
           f.number as flat_number, b.name as building_name, s.name as street_name
    FROM payments p 
    JOIN invoices i ON p.invoice_id = i.id 
    LEFT JOIN users u ON p.user_id = u.id 
    LEFT JOIN flats f ON i.flat_id = f.id 
    LEFT JOIN buildings b ON f.building_id = b.id 
    LEFT JOIN streets s ON b.street_id = s.id 
    LEFT JOIN receipts r ON r.payment_id = p.id 
    WHERE p.estate_id = $estate_id AND p.status = 'paid' 
      AND (i.zone_id = $zone_id OR s.zone_id = $zone_id) 
    ORDER BY p.id DESC LIMIT 150
");

// -------------------------------------------------------------
// DATA FOR INVOICING MODAL (SCOPED STRICTLY TO ZONE)
// -------------------------------------------------------------
// Active residents in zone
$residents_result = $conn->query("
    SELECT DISTINCT u.id, u.name, f.number as flat_number, b.name as building_name, s.name as street_name 
    FROM residents r 
    JOIN users u ON r.user_id = u.id 
    JOIN flats f ON r.flat_id = f.id 
    JOIN buildings b ON f.building_id = b.id 
    JOIN streets s ON b.street_id = s.id 
    WHERE s.zone_id = $zone_id AND r.estate_id = $estate_id AND r.status = 'active' 
    ORDER BY u.name ASC
");

// Streets in zone
$streets_result = $conn->query("
    SELECT id, name 
    FROM streets 
    WHERE zone_id = $zone_id AND estate_id = $estate_id 
    ORDER BY name ASC
");

// Charges catalog (Local zonal charges + Active Central Baseline charges)
$charges_catalog = $conn->query("
    SELECT id, name, amount, frequency, (CASE WHEN zone_id = $zone_id THEN 'Zone Levy' ELSE 'Estate Standard' END) as charge_tag 
    FROM estate_charges 
    WHERE (zone_id = $zone_id OR (zone_id IS NULL AND status = 'Active')) AND estate_id = $estate_id AND status = 'Active' 
    ORDER BY name ASC
");

// Fetch accepted Payment Methods
$pm_res = $conn->query("
    SELECT pm.*, u.name as creator_name, u.role as creator_role 
    FROM payment_methods pm 
    LEFT JOIN users u ON pm.created_by = u.id 
    WHERE pm.estate_id = $estate_id AND pm.status = 'active' 
    ORDER BY pm.is_system DESC, pm.id ASC
");
$payment_methods_list = [];
if ($pm_res) {
    while ($pm = $pm_res->fetch_assoc()) {
        $payment_methods_list[] = $pm;
    }
}

include 'header.php';
include 'sidebar.php';
?>

<div class="page-header-futuristic mb-4">
    <div>
        <div class="header-breadcrumbs">
            <span>Treasury</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span>Zonal Billing & Collections</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span class="active">Finance Hub</span>
        </div>
        <h1 class="page-title">Zonal Finance & Collections Hub</h1>
        <p class="page-subtitle">Complete treasury console for <?php echo htmlspecialchars($_SESSION['zone_name'] ?? 'your zone'); ?>: targeted billing, collection reconciliation, receipts, and offline ledger.</p>
    </div>
    <div class="header-actions">
        <button type="button" onclick="exportFinanceCSV()" class="btn btn-sm btn-outline-secondary" style="display:inline-flex; align-items:center; gap:0.4rem; padding:0.55rem 0.9rem; font-weight:600;">
            <i class="fa-solid fa-file-export"></i> Export Ledger
        </button>
        <button type="button" onclick="openPaymentMethodsModal()" class="btn btn-sm btn-outline-secondary" style="display:inline-flex; align-items:center; gap:0.4rem; padding:0.55rem 0.9rem; font-weight:600;">
            <i class="fa-solid fa-credit-card"></i> Payment Channels
        </button>
        <a href="charges" class="btn btn-sm btn-outline-secondary" style="display:inline-flex; align-items:center; gap:0.4rem; padding:0.55rem 0.9rem; font-weight:600; text-decoration:none;">
            <i class="fa-solid fa-list-check"></i> Charge Catalog
        </a>
        <button onclick="document.getElementById('invoiceModal').style.display='flex'" class="btn btn-sm text-white" style="background: #6b21a8; border: none; padding:0.55rem 1.1rem; font-weight:600; border-radius:0.45rem; display:inline-flex; align-items:center; gap:0.4rem;">
            <i class="fa-solid fa-plus"></i> Invoicing Dispatch
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

<?php if (!empty($zone_meta['paystack_account_number'])): ?>
<!-- ==========================================
     PAYSTACK DEDICATED VIRTUAL ACCOUNT BANNER
     ========================================== -->
<div class="mature-card p-3 mb-4" style="background: linear-gradient(135deg, #ffffff 0%, #f0f9ff 100%); border: 1px solid #bae6fd; border-left: 5px solid #0284c7; border-radius: 0.85rem; box-shadow: 0 4px 12px -2px rgba(2, 132, 199, 0.08);">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: #0284c7; color: white; display: flex; align-items: center; justify-content: center; font-size: 1.35rem; box-shadow: 0 4px 10px rgba(2, 132, 199, 0.3);">
                <i class="fa-solid fa-building-columns"></i>
            </div>
            <div>
                <div class="d-flex align-items-center gap-2">
                    <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 800; color: #0369a1; letter-spacing: 0.06em;">Zonal Paystack Virtual Account (Direct Remittance)</span>
                    <span class="badge" style="background: #e0f2fe; color: #0369a1; font-weight: 700; font-size: 0.72rem;"><?= htmlspecialchars($zone_meta['paystack_bank_name'] ?? 'Wema Bank') ?></span>
                </div>
                <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                    <span style="font-family: monospace; font-size: 1.45rem; font-weight: 800; color: #0f172a; letter-spacing: 0.06em;" id="zoneDvaNumber"><?= htmlspecialchars($zone_meta['paystack_account_number']) ?></span>
                    <span class="text-secondary small fw-semibold">&bull; <?= htmlspecialchars($zone_meta['paystack_account_name'] ?? 'Zone Account') ?></span>
                    <?php if (!empty($zone_meta['paystack_subaccount_code'])): ?>
                        <span class="badge bg-white text-secondary border font-monospace" style="font-size: 0.72rem;"><?= htmlspecialchars($zone_meta['paystack_subaccount_code']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($zone_meta['paystack_account_number']) ?>'); this.innerHTML='<i class=\'fa-solid fa-check me-1\'></i> Copied!'; setTimeout(() => this.innerHTML='<i class=\'fa-regular fa-copy me-1\'></i> Copy NUBAN', 2000);" class="btn btn-sm btn-outline-primary" style="font-weight: 700; border-radius: 0.5rem; padding: 0.5rem 0.95rem;">
                <i class="fa-regular fa-copy me-1"></i> Copy NUBAN
            </button>
            <div class="mature-badge mature-badge-emerald px-2 py-1" style="font-size: 0.75rem;">
                <i class="fa-solid fa-bolt me-1"></i> Auto-Remittance Active
            </div>
        </div>
    </div>
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
                <div class="kpi-icon-wrap" style="color: #059669; background: rgba(5, 150, 105, 0.1);">
                    <i class="fa-solid fa-receipt"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Zonal Collections Today</span>
                <span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-bolt me-1"></i> Live Inflow</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: 100%; background: #059669;"></div>
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
                <div class="kpi-icon-wrap" style="color: #7e22ce; background: rgba(126, 34, 206, 0.1);">
                    <i class="fa-solid fa-vault"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Month-to-Date Volume</span>
                <span class="mature-badge mature-badge-sky"><?= date('F') ?></span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: 75%; background: #7e22ce;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 3: Total Outstanding -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Zonal Outstanding Dues</span>
                    <div class="kpi-value">₦<?= number_format($outstanding_stat, 2) ?></div>
                </div>
                <div class="kpi-icon-wrap" style="color: #d97706; background: rgba(217, 119, 6, 0.1);">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span><?= $unpaid_residents ?> resident(s) pending</span>
                <span class="mature-badge mature-badge-amber">Active Dues</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?= ($outstanding_stat > 0) ? '65%' : '0%'; ?>; background: #d97706;"></div>
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
                <div class="kpi-icon-wrap" style="color: #dc2626; background: rgba(220, 38, 38, 0.1);">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Requires Zonal Follow-up</span>
                <span class="mature-badge <?= ($overdue_stat > 0) ? 'mature-badge-crimson' : 'mature-badge-emerald'; ?>">
                    <?= ($overdue_stat > 0) ? 'Past Due Date' : 'Clean Ledger'; ?>
                </span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?= ($overdue_stat > 0) ? '45%' : '0%'; ?>; background: #dc2626;"></div>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     PAYMENT METHOD RECONCILIATION MATRIX
     ========================================== -->
<div class="mature-card mb-4">
    <div class="mature-card-header">
        <div>
            <h3 class="mature-card-title">
                <i class="fa-solid fa-chart-pie text-secondary"></i> Zonal Payment Channel Breakdown
            </h3>
            <p style="margin: 0.2rem 0 0; font-size: 0.8rem; color: var(--text-muted);">Realized collections across electronic channels, zonal cash desk, POS machines, and direct bank transfers.</p>
        </div>
        <button type="button" onclick="openPaymentMethodsModal()" class="btn btn-sm btn-outline-secondary" style="font-size: 0.8rem;">
            <i class="fa-solid fa-plus me-1"></i> Manage Types
        </button>
    </div>
    <div class="mature-card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 1rem;">
            <?php 
            $default_channels = [
                'bank_transfer' => 'Bank Transfer',
                'cash' => 'Cash / Zonal Office',
                'pos' => 'POS Terminal',
                'paystack' => 'Paystack Online',
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

<!-- ==========================================
     DUAL LEDGER TABS (INVOICES VS PAYMENTS)
     ========================================== -->
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

<!-- ==========================================
     TAB 1: INVOICES LEDGER
     ========================================== -->
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
                <button class="filter-btn-pill" onclick="setInvoiceStatusFilter('paid', this)">
                    <i class="fa-solid fa-circle-check me-1"></i> Paid
                </button>
                <button class="filter-btn-pill" onclick="setInvoiceStatusFilter('overdue', this)">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i> Overdue
                </button>
            </div>
            <div class="position-relative" style="min-width: 280px;">
                <i class="fa-solid fa-magnifying-glass position-absolute text-muted" style="top: 50%; left: 0.85rem; transform: translateY(-50%); font-size: 0.85rem;"></i>
                <input type="text" id="invoiceSearchInput" class="form-control ps-5" placeholder="Search resident, invoice #, street, title..." onkeyup="filterInvoicesTable()">
            </div>
        </div>
    </div>

    <div class="mature-card mb-4">
        <div class="mature-card-header">
            <div>
                <h2 class="mature-card-title">
                    <i class="fa-solid fa-file-invoice-dollar text-secondary"></i> Issued Zonal Invoices
                </h2>
                <p style="margin: 0.25rem 0 0; font-size: 0.8rem; color: var(--text-muted);">Zonal billing records, payment reconciliation, and automated digital receipts.</p>
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
                            <th>Resident & Location</th>
                            <th>Charge Title</th>
                            <th>Amount</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="invoicesTableBody">
                        <?php if($invoices_result && $invoices_result->num_rows > 0): ?>
                            <?php while($row = $invoices_result->fetch_assoc()): ?>
                            <?php 
                                $inv_display_no = $row['invoice_number'] ?: ('INV-' . sprintf("%04d", $row['id'])); 
                                $status_val = strtolower($row['status']);
                                $search_meta = strtolower($inv_display_no . ' ' . $row['resident_name'] . ' ' . ($row['flat_number'] ?? '') . ' ' . ($row['street_name'] ?? '') . ' ' . $row['title'] . ' ' . $row['amount']);
                            ?>
                            <tr class="invoice-row" data-status="<?= htmlspecialchars($status_val) ?>" data-search="<?= htmlspecialchars($search_meta) ?>">
                                <td>
                                    <span class="id-chip" style="background: rgba(126, 34, 206, 0.08); color: #7e22ce; border-color: rgba(126, 34, 206, 0.2);"><?= htmlspecialchars($inv_display_no) ?></span>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--text-color); font-size: 0.92rem;"><?= htmlspecialchars($row['resident_name'] ?: 'Resident') ?></div>
                                    <?php if (!empty($row['flat_number']) || !empty($row['street_name'])): ?>
                                        <div style="font-size: 0.76rem; color: var(--text-muted); margin-top: 2px;">
                                            <i class="fa-solid fa-house-chimney me-1"></i>
                                            <?= !empty($row['flat_number']) ? 'Unit ' . htmlspecialchars($row['flat_number']) . ' &bull; ' : '' ?>
                                            <?= htmlspecialchars($row['street_name'] ?? '') ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-size: 0.86rem; color: var(--text-muted); font-weight: 500;"><?= htmlspecialchars($row['title']) ?></span>
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
                                        <span class="mature-badge mature-badge-amber" style="background: rgba(245, 158, 11, 0.12); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.3);">
                                            <i class="fa-solid fa-chart-pie me-1"></i> PARTIAL
                                        </span>
                                        <div style="font-size: 0.72rem; color: #b45309; margin-top: 3px; font-weight: 600;">
                                            Paid: ₦<?= number_format($row['amount_paid'], 2) ?><br>Bal: ₦<?= number_format($row['balance'], 2) ?>
                                        </div>
                                    <?php elseif ($status_val == 'unpaid'): ?>
                                        <span class="mature-badge mature-badge-crimson"><i class="fa-solid fa-circle-xmark me-1"></i> UNPAID</span>
                                    <?php elseif ($status_val == 'overdue'): ?>
                                        <span class="mature-badge mature-badge-crimson"><i class="fa-solid fa-triangle-exclamation me-1"></i> OVERDUE</span>
                                    <?php else: ?>
                                        <span class="mature-badge mature-badge-amber"><?= strtoupper($row['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <?php if ($status_val != 'paid'): ?>
                                        <?php $effective_bal = floatval($row['balance'] > 0 ? $row['balance'] : $row['amount']); ?>
                                        <button type="button" onclick="openRecordPaymentModal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['resident_name'])) ?>', <?= $row['amount'] ?>, '<?= htmlspecialchars(addslashes($inv_display_no)) ?>', <?= $effective_bal ?>)" class="btn btn-sm text-white" style="background:#059669; border:none; padding:0.35rem 0.75rem; border-radius:0.4rem; cursor:pointer; font-weight:600; font-size:0.8rem; display:inline-flex; align-items:center; gap:4px;">
                                            <i class="fa-solid fa-receipt"></i> Record Pay
                                        </button>
                                        <a href="finance.php?resend_inv=<?= $row['id'] ?>" title="Resend Invoice Email" class="btn btn-sm btn-outline-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.8rem; display: inline-flex; align-items: center; margin-left: 4px;" onclick="return confirm('Resend invoice notification to resident?');">
                                            <i class="fa-solid fa-envelope"></i>
                                        </a>
                                    <?php else: ?>
                                        <div style="display: inline-flex; align-items: center; gap: 0.35rem;">
                                            <a href="receipt?id=<?= $row['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" style="font-weight: 600; font-size: 0.8rem; display:inline-flex; align-items:center; gap:4px; padding: 0.35rem 0.75rem;">
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
                                                <i class="fa-solid fa-bolt"></i> Electronic Inflow
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="text-align: center; padding: 3.5rem 1rem; color: var(--text-muted);">No invoices found for this zone. Click "Invoicing Dispatch" above to generate your first bill.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     TAB 2: REALIZED PAYMENTS LEDGER
     ========================================== -->
<div id="payments_tab" class="finance-tab-content" style="display: none;">
    <div class="mature-card mb-4">
        <div class="mature-card-header">
            <div>
                <h2 class="mature-card-title">
                    <i class="fa-solid fa-receipt text-secondary"></i> Realized Transactions & Collections Ledger
                </h2>
                <p style="margin: 0.25rem 0 0; font-size: 0.8rem; color: var(--text-muted);">Verified settled collections, counter receipts, and bank transfers for <?= htmlspecialchars($_SESSION['zone_name'] ?? 'this zone') ?>.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="tech-chip"><i class="fa-solid fa-money-bill-transfer"></i> Total: <?= $payments_result ? $payments_result->num_rows : 0 ?></span>
                <button type="button" onclick="exportPaymentsCSV()" class="btn btn-sm btn-outline-secondary" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;">
                    <i class="fa-solid fa-file-arrow-down me-1"></i> Export Payments
                </button>
            </div>
        </div>
        
        <div class="mature-card-body p-0">
            <div class="table-responsive">
                <table class="table dashboard-table align-middle" id="paymentsTable">
                    <thead>
                        <tr>
                            <th style="width: 140px;">Payment Ref</th>
                            <th>Resident & Unit</th>
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
                                    <span class="id-chip"><?= htmlspecialchars($p_row['reference'] ?: ($p_row['transaction_ref'] ?: ('PAY-' . $p_row['id']))) ?></span>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--text-color); font-size: 0.92rem;"><?= htmlspecialchars($p_row['resident_name'] ?: 'Resident') ?></div>
                                    <?php if (!empty($p_row['flat_number'])): ?>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);">Unit <?= htmlspecialchars($p_row['flat_number']) ?> &bull; <?= htmlspecialchars($p_row['street_name'] ?? '') ?></div>
                                    <?php endif; ?>
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
                                    <a href="receipt?id=<?= $p_row['invoice_id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" style="font-weight: 600; font-size: 0.78rem;">
                                        <i class="fa-solid fa-receipt text-primary me-1"></i> View Receipt
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="text-align: center; padding: 3.5rem 1rem; color: var(--text-muted);">No realized payments recorded yet for this zone.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     BULK & SINGLE INVOICING MODAL
     ========================================== -->
<div id="invoiceModal" class="custom-modal-backdrop" style="display:none;">
    <div class="modal-content mature-card" style="max-width: 520px; border-radius: 0.85rem; border: 1px solid var(--border-color); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <div class="modal-header" style="border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; margin-bottom: 1.25rem;">
            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <div style="width: 36px; height: 36px; border-radius: 0.45rem; background: rgba(126, 34, 206, 0.1); color: #7e22ce; display: flex; align-items: center; justify-content: center; font-size: 1rem;">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                </div>
                <div>
                    <h2 style="margin: 0; font-size: 1.15rem; font-weight: 700; color: var(--text-color);">Issue Zonal Invoices</h2>
                    <p style="margin: 0.15rem 0 0; font-size: 0.78rem; color: var(--text-muted);">Bill single resident, an entire street, or bulk zone occupants</p>
                </div>
            </div>
            <button type="button" onclick="document.getElementById('invoiceModal').style.display='none'" style="background: none; border: none; font-size: 1.15rem; color: var(--text-muted); cursor: pointer;"><i class="fa-solid fa-times"></i></button>
        </div>

        <form method="POST">
            <input type="hidden" name="generate_invoice" value="1">
            <div style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom:0.35rem; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em;">Invoicing Target <span class="text-danger">*</span></label>
                <select name="target_type" id="target_type" onchange="toggleTargetFields()" required class="form-control" style="border-radius:0.5rem; font-size: 0.92rem;">
                    <option value="single">Single Resident in Zone</option>
                    <option value="street">All Residents on Particular Street in Zone</option>
                    <option value="all">All Active Zone Residents (Bulk Assessment)</option>
                </select>
            </div>

            <div id="field_single" style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom:0.35rem; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em;">Select Resident <span class="text-danger">*</span></label>
                <select name="user_id" id="target_user_id" class="form-control" style="border-radius:0.5rem; font-size: 0.92rem;">
                    <option value="">-- Choose Resident --</option>
                    <?php 
                    if ($residents_result) {
                        $residents_result->data_seek(0);
                        while($r = $residents_result->fetch_assoc()) {
                            $loc = (!empty($r['flat_number']) ? 'Unit ' . $r['flat_number'] . ' &bull; ' : '') . ($r['street_name'] ?? '');
                            echo "<option value='{$r['id']}'>" . htmlspecialchars($r['name']) . " (" . htmlspecialchars($loc) . ")</option>";
                        }
                    }
                    ?>
                </select>
            </div>

            <div id="field_street" style="margin-bottom: 1rem; display: none;">
                <label style="display:block; margin-bottom:0.35rem; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em;">Select Street in Zone <span class="text-danger">*</span></label>
                <select name="street_id" id="target_street_id" class="form-control" style="border-radius:0.5rem; font-size: 0.92rem;">
                    <option value="">-- Choose Street --</option>
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
                <label style="display:block; margin-bottom:0.35rem; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em;">Select Charge Catalog Item (Optional)</label>
                <select name="charge_id" id="charge_catalog_select" onchange="autoFillCharge()" class="form-control" style="border-radius:0.5rem; font-size: 0.92rem;">
                    <option value="">-- Custom Zonal Invoice --</option>
                    <?php 
                    if ($charges_catalog) {
                        $charges_catalog->data_seek(0);
                        while($c = $charges_catalog->fetch_assoc()) {
                            echo "<option value='{$c['id']}' data-name='" . htmlspecialchars($c['name']) . "' data-amount='{$c['amount']}'>" . htmlspecialchars($c['name']) . " (₦" . number_format($c['amount']) . ") [" . $c['charge_tag'] . "]</option>";
                        }
                    }
                    ?>
                </select>
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom:0.35rem; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em;">Invoice Title / Description <span class="text-danger">*</span></label>
                <input type="text" name="title" id="inv_title" placeholder="e.g. October Security & Waste Levy" required class="form-control" style="border-radius:0.5rem; font-size: 0.92rem;">
            </div>

            <div class="row g-2 mb-3">
                <div class="col-7">
                    <label style="display:block; margin-bottom:0.35rem; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em;">Amount (₦) <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text" style="background: #f8fafc; border-color: #cbd5e1; font-weight: 800; color: #334155; border-radius: 0.5rem 0 0 0.5rem; font-size: 0.95rem;">₦</span>
                        <input type="number" step="0.01" min="1" name="amount" id="inv_amount" placeholder="10000.00" required class="form-control" style="border-radius: 0 0.5rem 0.5rem 0; font-size: 0.92rem; font-weight:600; border-color: #cbd5e1;">
                    </div>
                </div>
                <div class="col-5">
                    <label style="display:block; margin-bottom:0.35rem; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em;">Due Date</label>
                    <input type="date" name="due_date" required value="<?= date('Y-m-d', strtotime('+14 days')) ?>" class="form-control" style="border-radius:0.5rem; font-size: 0.92rem;">
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; border-top: 1px solid var(--border-color); padding-top: 1.25rem;">
                <button type="button" onclick="document.getElementById('invoiceModal').style.display='none'" class="btn btn-outline-secondary" style="font-weight: 600; padding: 0.6rem 1.25rem;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 600; padding: 0.6rem 1.5rem; background: #6b21a8; border-color: #6b21a8;">Generate & Send Invoice(s)</button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================
     RECORD MANUAL PAYMENT & AUTO-RECEIPT MODAL
     ========================================== -->
<div id="recordModal" class="custom-modal-backdrop" style="display:none;">
    <div class="modal-content mature-card" style="max-width: 520px; border-radius: 0.85rem; border: 1px solid var(--border-color); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); max-height: 90vh; overflow-y: auto;">
        <div class="modal-header" style="border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; margin-bottom: 1.25rem;">
            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <div style="width: 36px; height: 36px; border-radius: 0.45rem; background: rgba(16, 185, 129, 0.1); color: #10b981; display: flex; align-items: center; justify-content: center; font-size: 1rem;">
                    <i class="fa-solid fa-receipt"></i>
                </div>
                <div>
                    <h2 style="margin: 0; font-size: 1.15rem; font-weight: 700; color: var(--text-color);">Record Payment & Issue Receipt</h2>
                    <p style="margin: 0.15rem 0 0; font-size: 0.78rem; color: var(--text-muted);">Unique Invoice ID and independent digital Receipt ID</p>
                </div>
            </div>
            <button type="button" onclick="closeRecordPaymentModal()" style="background: none; border: none; font-size: 1.15rem; color: var(--text-muted); cursor: pointer;"><i class="fa-solid fa-times"></i></button>
        </div>

        <form method="POST" id="manualPaymentForm">
            <input type="hidden" name="record_manual_payment" value="1">
            <input type="hidden" name="invoice_id" id="rec_invoice_id">

            <!-- Card displaying separate Invoice ID, Resident and Amount -->
            <div style="background: rgba(148, 163, 184, 0.05); border: 1px solid var(--border-color); border-radius: 0.75rem; padding: 1rem; margin-bottom: 1.25rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem;">
                    <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted);">Invoice Number</span>
                    <span id="rec_invoice_number_badge" style="font-family: monospace; font-weight: 700; background: rgba(126, 34, 206, 0.1); color: #7e22ce; padding: 3px 8px; border-radius: 4px; font-size: 0.88rem; border: 1px solid rgba(126, 34, 206, 0.25);">INV-000000</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem;">
                    <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted);">Resident</span>
                    <span id="rec_resident_name" style="font-weight: 600; color: var(--text-color); font-size: 0.9rem;">-</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted);">Current Outstanding Balance</span>
                    <span id="rec_amount" style="font-weight: 800; color: #059669; font-size: 1.2rem;">₦0.00</span>
                </div>
            </div>

            <!-- Collection Amount Input (Full Settlement or Partial Installment) -->
            <div style="margin-bottom: 1.25rem;">
                <label style="display:block; font-weight: 600; color: var(--text-color); font-size: 0.82rem; margin-bottom: 0.35rem;">
                    Payment Amount Received (₦) <span class="text-danger">*</span>
                </label>
                <div class="input-group">
                    <span class="input-group-text fw-bold">₦</span>
                    <input type="number" step="0.01" min="1" name="amount" id="rec_payment_amount" required class="form-control" style="font-size: 1.1rem; font-weight: 700; color: #059669; border-radius: 0 0.5rem 0.5rem 0;">
                </div>
                <small style="color: var(--text-muted); font-size: 0.74rem; display: block; margin-top: 0.25rem;">
                    Enter full balance or partial installment amount. Invoice status automatically tracks remaining balance.
                </small>
            </div>

            <!-- Auto-Generated Receipt ID Field -->
            <div style="margin-bottom: 1.25rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                    <label style="display:block; font-weight: 600; color: var(--text-color); font-size: 0.82rem; margin-bottom: 0;">
                        <i class="fa-solid fa-receipt text-primary me-1"></i> Auto-Generated Receipt ID
                    </label>
                    <button type="button" onclick="refreshReceiptNumber()" title="Generate fresh receipt number" style="background: none; border: none; color: #2563eb; font-size: 0.78rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 4px;">
                        <i class="fa-solid fa-arrows-rotate" id="refreshReceiptIcon"></i> Regenerate
                    </button>
                </div>
                <div style="position: relative;">
                    <input type="text" name="receipt_number" id="rec_receipt_number" readonly class="form-control" style="border-radius:0.5rem; background: rgba(59, 130, 246, 0.08); font-family: monospace; font-weight: 700; color: #1e40af; font-size: 1rem; border-color: rgba(59, 130, 246, 0.3);">
                </div>
                <small style="color: var(--text-muted); font-size: 0.74rem; display: block; margin-top: 0.25rem;">
                    Stored independently from the Invoice ID and automatically printed on the customer receipt.
                </small>
            </div>

            <!-- Dynamic Payment Method Selection -->
            <div style="margin-bottom: 1.25rem;">
                <label style="display:block; font-weight: 600; color: var(--text-color); font-size: 0.82rem; margin-bottom: 0.35rem;">Payment Method</label>
                <select name="payment_method" id="rec_payment_method" onchange="handlePaymentMethodChange(this.value)" required class="form-control" style="border-radius:0.5rem; font-size: 0.92rem;">
                    <?php if (!empty($payment_methods_list)): ?>
                        <?php foreach ($payment_methods_list as $pm): ?>
                            <option value="<?= htmlspecialchars($pm['code']) ?>"><?= htmlspecialchars($pm['name']) ?></option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="bank_transfer" selected>Bank Transfer</option>
                        <option value="cash">Cash / Counter</option>
                        <option value="pos">POS Terminal</option>
                        <option value="cheque">Cheque</option>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Transaction Reference / Teller Number (Auto-Filled) -->
            <div style="margin-bottom: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                    <label style="display:block; font-weight: 600; color: var(--text-color); font-size: 0.82rem; margin-bottom: 0;">Transaction Reference / Teller Number</label>
                    <span style="font-size: 0.72rem; color: #059669; font-weight: 700; background: rgba(16, 185, 129, 0.12); padding: 2px 7px; border-radius: 4px;">
                        <i class="fa-solid fa-bolt me-1"></i> Auto-Filled
                    </span>
                </div>
                <input type="text" name="transaction_ref" id="rec_transaction_ref" required class="form-control" style="border-radius:0.5rem; font-size: 0.92rem; font-family: monospace; font-weight: 600;">
                <small style="color: var(--text-muted); font-size: 0.74rem; display: block; margin-top: 0.25rem;">
                    Auto-generated from channel prefix & Receipt ID. Click confirm without manual typing.
                </small>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; border-top: 1px solid var(--border-color); padding-top: 1.25rem;">
                <button type="button" onclick="closeRecordPaymentModal()" class="btn btn-outline-secondary" style="font-weight: 600; padding: 0.6rem 1.25rem;">Cancel</button>
                <button type="submit" class="btn btn-success" style="font-weight: 600; padding: 0.6rem 1.5rem; background: #059669; border-color: #059669; display: inline-flex; align-items: center; gap: 0.4rem;">
                    <i class="fa-solid fa-receipt"></i> Confirm & Issue Receipt
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================
     PAYMENT CHANNELS / METHODS MODAL
     ========================================== -->
<div id="paymentMethodsModal" class="custom-modal-backdrop" style="display:none;">
    <div class="modal-content mature-card" style="max-width: 520px; border-radius: 0.85rem; border: 1px solid var(--border-color); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); max-height: 90vh; overflow-y: auto;">
        <div class="modal-header" style="border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; margin-bottom: 1.25rem;">
            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <div style="width: 36px; height: 36px; border-radius: 0.45rem; background: rgba(59, 130, 246, 0.1); color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 1rem;">
                    <i class="fa-solid fa-credit-card"></i>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 1.15rem; font-weight: 700; color: var(--text-color);">Payment Types & Channels</h3>
                    <p style="margin: 0.15rem 0 0; font-size: 0.78rem; color: var(--text-muted);">Accepted settlement methods for offline & online collections</p>
                </div>
            </div>
            <button type="button" onclick="closePaymentMethodsModal()" style="background: none; border: none; font-size: 1.15rem; color: var(--text-muted); cursor: pointer;"><i class="fa-solid fa-times"></i></button>
        </div>

        <!-- Add New Payment Type Form -->
        <div style="background: rgba(148, 163, 184, 0.06); border: 1px solid var(--border-color); border-radius: 0.75rem; padding: 1.25rem; margin-bottom: 1.5rem;">
            <label style="display: block; font-size: 0.82rem; font-weight: 700; color: var(--text-color); margin-bottom: 0.4rem;">Add Accepted Payment Method</label>
            <div style="display: flex; gap: 0.5rem;">
                <input type="text" id="page_new_pm_name" placeholder="e.g. Direct Debit, USSD Voucher, Agency Slip" class="form-control" style="flex: 1; border-radius: 0.5rem; font-size: 0.9rem;">
                <button type="button" onclick="saveNewPaymentMethodFromPage()" class="btn btn-success" style="padding: 0.6rem 1.25rem; font-weight: 700; font-size: 0.88rem; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; background: #059669; border-color: #059669;">
                    <i class="fa-solid fa-plus"></i> Add
                </button>
            </div>
            <div id="page_add_pm_msg" style="font-size: 0.78rem; margin-top: 0.4rem;"></div>
        </div>

        <!-- Current Payment Methods List -->
        <div>
            <h4 style="font-size: 0.82rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 0.75rem; letter-spacing: 0.05em;">Active Accepted Payment Types</h4>
            <div id="paymentMethodsListContainer" style="display: flex; flex-direction: column; gap: 0.5rem;">
                <?php foreach ($payment_methods_list as $pm): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 0.5rem;">
                        <div>
                            <div>
                                <span style="font-weight: 600; color: var(--text-color); font-size: 0.92rem;"><?= htmlspecialchars($pm['name']) ?></span>
                                <span style="font-family: monospace; font-size: 0.75rem; color: var(--text-muted); margin-left: 6px; background: rgba(148, 163, 184, 0.1); padding: 2px 6px; border-radius: 4px;"><?= htmlspecialchars($pm['code']) ?></span>
                            </div>
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 3px;">
                                <?php if (!empty($pm['creator_name'])): ?>
                                    <i class="fa-solid fa-user-tag text-primary me-1"></i> Configured by: <strong><?= htmlspecialchars($pm['creator_name']) ?></strong> &bull; <?= date('M d, Y', strtotime($pm['created_at'])) ?>
                                <?php else: ?>
                                    <i class="fa-solid fa-shield-halved text-secondary me-1"></i> Standard System Method
                                <?php endif; ?>
                            </div>
                        </div>
                        <span style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; padding: 2px 8px; border-radius: 9999px; background: rgba(16, 185, 129, 0.15); color: #059669;">Active</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="display: flex; justify-content: flex-end; margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1rem;">
            <button type="button" onclick="closePaymentMethodsModal()" class="btn btn-outline-secondary" style="padding: 0.6rem 1.25rem; font-weight: 600;">Close</button>
        </div>
    </div>
</div>

<script>
function toggleTargetFields() {
    let target = document.getElementById('target_type').value;
    document.getElementById('field_single').style.display = (target === 'single') ? 'block' : 'none';
    document.getElementById('field_street').style.display = (target === 'street') ? 'block' : 'none';
    
    // Set HTML5 required attributes accordingly
    const userInput = document.getElementById('target_user_id');
    const streetInput = document.getElementById('target_street_id');
    if (userInput) userInput.required = (target === 'single');
    if (streetInput) streetInput.required = (target === 'street');
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
    const method = sel ? sel.value : 'bank_transfer';
    const recInput = document.getElementById('rec_receipt_number');
    const receiptNo = recInput ? recInput.value : '';
    const suffix = receiptNo ? receiptNo.replace(/^REC-/, '') : '';
    const prefix = getMethodPrefix(method);
    const refInput = document.getElementById('rec_transaction_ref');
    if (refInput) {
        refInput.value = prefix + '-' + (suffix || Math.floor(100000 + Math.random() * 900000));
    }
}

function openRecordPaymentModal(invId, resName, amount, invNumber, balance) {
    document.getElementById('rec_invoice_id').value = invId;
    document.getElementById('rec_invoice_number_badge').innerText = invNumber || ('INV-' + invId);
    document.getElementById('rec_resident_name').innerText = resName;
    const effectiveBal = (balance !== undefined && balance !== null && parseFloat(balance) > 0) ? parseFloat(balance) : parseFloat(amount);
    document.getElementById('rec_amount').innerText = "₦" + effectiveBal.toLocaleString('en-US', {minimumFractionDigits: 2});
    const amtInput = document.getElementById('rec_payment_amount');
    if (amtInput) {
        amtInput.value = effectiveBal.toFixed(2);
        amtInput.max = effectiveBal.toFixed(2);
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

// Payment Methods Modal Controls
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

    msg.innerHTML = '<span style="color:var(--text-muted);">Saving payment type...</span>';
    fetch('../api/payment_methods', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: name })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.method) {
            msg.innerHTML = '<span style="color:#059669; font-weight: 600;"><i class="fa-solid fa-check"></i> ' + (data.message || 'Payment method added!') + '</span>';
            
            // Add to modal list view
            const listContainer = document.getElementById('paymentMethodsListContainer');
            if (listContainer) {
                const item = document.createElement('div');
                item.style.cssText = 'display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 0.5rem;';
                const creatorTag = data.method.creator_name 
                    ? `<div style="font-size: 0.75rem; color: #059669; margin-top: 3px;"><i class="fa-solid fa-user-tag me-1"></i> Configured by: <strong>${data.method.creator_name}</strong> &bull; Just now</div>`
                    : '';
                item.innerHTML = `<div><div><span style="font-weight: 600; color: var(--text-color); font-size: 0.92rem;">${data.method.name}</span><span style="font-family: monospace; font-size: 0.75rem; color: var(--text-muted); margin-left: 6px; background: rgba(148, 163, 184, 0.1); padding: 2px 6px; border-radius: 4px;">${data.method.code}</span></div>${creatorTag}</div><span style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; padding: 2px 8px; border-radius: 9999px; background: rgba(16, 185, 129, 0.15); color: #059669;">Active</span>`;
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
    link.setAttribute('download', 'zonal_invoices_ledger_' + new Date().toISOString().slice(0, 10) + '.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Export Payments to CSV
function exportPaymentsCSV() {
    const rows = document.querySelectorAll('#paymentsTable tr');
    let csv = [];
    
    rows.forEach(row => {
        if (row.style.display === 'none') return;
        const cols = row.querySelectorAll('th, td');
        let rowData = [];
        // Extract first 6 columns (exclude Receipt column)
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
    link.setAttribute('download', 'zonal_payments_ledger_' + new Date().toISOString().slice(0, 10) + '.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php include 'footer.php'; ?>
