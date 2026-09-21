<?php
// verify_payment.php
require_once 'config.php';
require_once 'includes/auth_guard.php';
require_once 'includes/Paystack.php';
require_once 'includes/Mailer.php';

if (!isset($_GET['reference']) || !isset($_GET['invoice_id'])) {
    die("<h3>Invalid Request</h3><p>Missing transaction reference or invoice ID.</p>");
}

$reference = $conn->real_escape_string($_GET['reference']);
$invoice_id = intval($_GET['invoice_id']);
$estate_id = get_estate_id();

try {
    $paystack = new Paystack($conn);
    $data = $paystack->verifyTransaction($reference);

    // Normalize payload from Paystack
    $tx_data = null;
    if (isset($data['data']) && is_array($data['data'])) {
        if (($data['status'] === true || $data['status'] === 'success') && ($data['data']['status'] === 'success')) {
            $tx_data = $data['data'];
        }
    } elseif (isset($data['status']) && $data['status'] === 'success') {
        $tx_data = $data;
    }

    if ($tx_data) {
        $amount_paid = $tx_data['amount'] / 100;
        $channel = $tx_data['channel'] ?? 'card';
        $gateway_ref = $tx_data['reference'];
        $gateway_id = $tx_data['id'] ?? null;
        
        $inv_res = $conn->query("
            SELECT i.*, 
                   COALESCE(i.zone_id, s.zone_id) as resolved_zone_id,
                   z.name as zone_name
            FROM invoices i 
            LEFT JOIN flats f ON i.flat_id = f.id 
            LEFT JOIN buildings b ON f.building_id = b.id 
            LEFT JOIN streets s ON b.street_id = s.id 
            LEFT JOIN zones z ON (COALESCE(i.zone_id, s.zone_id) = z.id)
            WHERE i.id = $invoice_id AND i.estate_id = $estate_id 
            LIMIT 1
        ");
        if ($inv_res && $inv_res->num_rows > 0) {
            $inv = $inv_res->fetch_assoc();
            $user_id = $inv['user_id'];
            $prop_id = $inv['property_id'];
            $zone_id = !empty($inv['resolved_zone_id']) ? intval($inv['resolved_zone_id']) : null;
            
            // Check if already processed
            $chk_pay = $conn->query("SELECT p.id, r.receipt_number FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id WHERE p.payment_reference = '$reference' OR p.transaction_ref = '$reference'");
            if ($chk_pay && $chk_pay->num_rows > 0) {
                $pay_row = $chk_pay->fetch_assoc();
                $receipt_no = $pay_row['receipt_number'];
                header("Location: resident/receipt?receipt_no=" . urlencode($receipt_no));
                exit;
            }
            
            $conn->begin_transaction();
            try {
                // Calculate installment / partial payment progression
                $inv_total = floatval($inv['amount']);
                $prev_paid = floatval($inv['amount_paid'] ?? 0);
                $new_amount_paid = $prev_paid + $amount_paid;
                $new_balance = max(0, $inv_total - $new_amount_paid);
                $new_status = ($new_balance <= 0.009) ? 'paid' : 'partially_paid';
                
                // Update Invoice
                $conn->query("UPDATE invoices SET status = '$new_status', amount_paid = $new_amount_paid, balance = $new_balance WHERE id = $invoice_id AND estate_id = $estate_id");
                
                // Update invoice_installments milestones if applicable
                $inst_res = $conn->query("SELECT id, amount, amount_paid FROM invoice_installments WHERE invoice_id = $invoice_id AND status != 'paid' ORDER BY installment_number ASC LIMIT 1");
                if ($inst_res && $inst_row = $inst_res->fetch_assoc()) {
                    $inst_id = $inst_row['id'];
                    $inst_paid_new = floatval($inst_row['amount_paid']) + $amount_paid;
                    $inst_st = ($inst_paid_new >= floatval($inst_row['amount']) - 0.01) ? 'paid' : 'partially_paid';
                    $conn->query("UPDATE invoice_installments SET amount_paid = $inst_paid_new, status = '$inst_st', paid_at = NOW() WHERE id = $inst_id");
                }
                
                // Record Payment with zone_id for direct zonal revenue tracking
                $pay_method = 'paystack_' . $channel;
                $channel_name = strtoupper(str_replace('_', ' ', $channel));
                $zone_info = !empty($inv['zone_name']) ? " [Remitted to {$inv['zone_name']}]" : "";
                $inst_tag = ($new_status === 'partially_paid') ? " (Installment: Bal ₦" . number_format($new_balance, 2) . ")" : " (Full Settlement)";
                $desc = "Paystack Online Payment (" . $channel_name . ") for Invoice #" . ($inv['invoice_number'] ?: ('INV-' . $invoice_id)) . $zone_info . $inst_tag;
                
                $stmt = $conn->prepare("INSERT INTO payments (estate_id, zone_id, user_id, invoice_id, property_id, amount, type, payment_method, status, payment_reference, transaction_ref, paid_at, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?, ?, NOW(), ?)");
                $stmt->bind_param("iiiiidsssss", $estate_id, $zone_id, $user_id, $invoice_id, $prop_id, $amount_paid, $inv['title'], $pay_method, $reference, $reference, $desc);
                $stmt->execute();
                $payment_id = $conn->insert_id;
                
                // Record Gateway Transaction Audit
                $tx_response_json = $conn->real_escape_string(json_encode($tx_data));
                $conn->query("INSERT INTO payment_transactions (payment_id, gateway, gateway_reference, gateway_transaction_id, channel, amount, currency, gateway_response, verified_at) 
                              VALUES ($payment_id, 'Paystack', '$gateway_ref', '$gateway_id', '$channel', $amount_paid, 'NGN', '$tx_response_json', NOW())");
                
                // Generate Auto Sequential Receipt Number
                $receipt_no = generateReceiptNumber($conn);
                $conn->query("INSERT INTO receipts (estate_id, receipt_number, payment_id, resident_id, property_id, amount, issued_at) 
                              VALUES ($estate_id, '$receipt_no', $payment_id, $user_id, " . ($prop_id ? $prop_id : "NULL") . ", $amount_paid, NOW())");
                
                $conn->commit();
                
                // Dispatch Payment Receipt Email to Resident
                EstateMailer::sendReceiptEmail($conn, $receipt_no);

                logAudit($conn, "Paystack Payment Verified", "Finance", "Verified payment reference: $reference for Invoice #$invoice_id via $channel$zone_info. Generated Receipt: $receipt_no");
                
                header("Location: resident/receipt?receipt_no=" . urlencode($receipt_no));
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                die("Database Update Error: " . $e->getMessage());
            }
        } else {
            die("Invoice not found.");
        }
    } else {
        die("<h3>Payment Verification Failed</h3><p>The transaction could not be verified by Paystack.</p>");
    }
} catch (Exception $e) {
    die("<h3>Verification Error</h3><p>" . htmlspecialchars($e->getMessage()) . "</p>");
}
?>
