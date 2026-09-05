<?php
// api/paystack_webhook.php
require_once '../config.php';

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$input = file_get_contents('php://input');

// Retrieve Paystack Secret Key from settings
$estate_id = 1; // Default
$s_res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'paystack_secret_key' AND estate_id = $estate_id");
$secret_key = ($s_res && $s_res->num_rows > 0) ? $s_res->fetch_assoc()['setting_value'] : '';

// Validate Paystack Signature if Secret Key is configured
if (!empty($secret_key) && isset($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'])) {
    $signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'];
    if ($signature !== hash_hmac('sha512', $input, $secret_key)) {
        http_response_code(400);
        exit("Invalid Signature");
    }
}

$event = json_decode($input, true);
if (!$event || !isset($event['event'])) {
    http_response_code(400);
    exit;
}

if ($event['event'] === 'charge.success') {
    $tx = $event['data'];
    $reference = $conn->real_escape_string($tx['reference']);
    $amount_paid = $tx['amount'] / 100;
    $channel = $tx['channel'] ?? 'card';
    $gateway_id = $tx['id'] ?? null;
    
    // Extract metadata
    $invoice_id = isset($tx['metadata']['custom_fields']) ? null : null;
    if (isset($tx['metadata']['custom_fields'])) {
        foreach ($tx['metadata']['custom_fields'] as $field) {
            if ($field['variable_name'] === 'invoice_id') {
                $invoice_id = intval($field['value']);
                break;
            }
        }
    }
    
    if ($invoice_id) {
        $inv_res = $conn->query("SELECT * FROM invoices WHERE id = $invoice_id");
        if ($inv_res && $inv_res->num_rows > 0) {
            $inv = $inv_res->fetch_assoc();
            if ($inv['status'] !== 'paid') {
                $user_id = $inv['user_id'];
                $estate_id = $inv['estate_id'];
                $prop_id = $inv['property_id'];
                
                $conn->begin_transaction();
                try {
                    // Update Invoice
                    $conn->query("UPDATE invoices SET status = 'paid', amount_paid = $amount_paid, balance = 0 WHERE id = $invoice_id");
                    
                    // Create Payment
                    $pay_method = 'paystack_' . $channel;
                    $channel_name = strtoupper(str_replace('_', ' ', $channel));
                    $desc = "Paystack Webhook Confirmed Payment (" . $channel_name . ") for Invoice #" . ($inv['invoice_number'] ?: ('INV-' . $invoice_id));
                    $conn->query("INSERT INTO payments (estate_id, user_id, invoice_id, property_id, amount, type, payment_method, status, payment_reference, transaction_ref, paid_at, description) 
                                  VALUES ($estate_id, $user_id, $invoice_id, " . ($prop_id ? $prop_id : "NULL") . ", $amount_paid, '{$inv['title']}', '$pay_method', 'paid', '$reference', '$reference', NOW(), '$desc')");
                    $payment_id = $conn->insert_id;
                    
                    // Transaction Log
                    $tx_json = $conn->real_escape_string(json_encode($tx));
                    $conn->query("INSERT INTO payment_transactions (payment_id, gateway, gateway_reference, gateway_transaction_id, channel, amount, currency, gateway_response, verified_at) 
                                  VALUES ($payment_id, 'Paystack', '$reference', '$gateway_id', '$channel', $amount_paid, 'NGN', '$tx_json', NOW())");
                    
                    // Generate Auto Sequential Receipt Number
                    $receipt_no = generateReceiptNumber($conn);
                    $conn->query("INSERT INTO receipts (estate_id, receipt_number, payment_id, resident_id, property_id, amount, issued_at) 
                                  VALUES ($estate_id, '$receipt_no', $payment_id, $user_id, " . ($prop_id ? $prop_id : "NULL") . ", $amount_paid, NOW())");
                    
                    $conn->commit();
                    logAudit($conn, "Paystack Webhook Processed", "Finance", "Webhook marked invoice #$invoice_id paid (Ref: $reference)");
                } catch (Exception $e) {
                    $conn->rollback();
                }
            }
        }
    }
}

http_response_code(200);
echo json_encode(['status' => 'success']);
?>
