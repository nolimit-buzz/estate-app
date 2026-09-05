<?php
// verify_payment.php
require_once 'config.php';
require_once 'includes/Paystack.php';

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
        
        $inv_res = $conn->query("SELECT * FROM invoices WHERE id = $invoice_id AND estate_id = $estate_id");
        if ($inv_res && $inv_res->num_rows > 0) {
            $inv = $inv_res->fetch_assoc();
            $user_id = $inv['user_id'];
            $prop_id = $inv['property_id'];
            
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
                // Update Invoice
                $conn->query("UPDATE invoices SET status = 'paid', amount_paid = $amount_paid, balance = 0 WHERE id = $invoice_id AND estate_id = $estate_id");
                
                // Record Payment (status column in payments table)
                $pay_method = 'paystack_' . $channel;
                $stmt = $conn->prepare("INSERT INTO payments (estate_id, user_id, invoice_id, property_id, amount, type, payment_method, status, payment_reference, transaction_ref, paid_at, description) VALUES (?, ?, ?, ?, ?, ?, ?, 'paid', ?, ?, NOW(), ?)");
                $channel_name = strtoupper(str_replace('_', ' ', $channel));
                $desc = "Paystack Online Payment (" . $channel_name . ") for Invoice #" . ($inv['invoice_number'] ?: ('INV-' . $invoice_id));
                $stmt->bind_param("iiiidsssss", $estate_id, $user_id, $invoice_id, $prop_id, $amount_paid, $inv['title'], $pay_method, $reference, $reference, $desc);
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
                logAudit($conn, "Paystack Payment Verified", "Finance", "Verified payment reference: $reference for Invoice #$invoice_id via $channel. Generated Receipt: $receipt_no");
                
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
