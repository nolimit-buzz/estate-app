<?php
// api/paystack_webhook.php
require_once '../config.php';
require_once '../includes/Mailer.php';

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
    
    // Check if already processed
    $chk = $conn->query("SELECT id FROM payments WHERE payment_reference = '$reference' OR transaction_ref = '$reference' LIMIT 1");
    if ($chk && $chk->num_rows > 0) {
        http_response_code(200);
        exit(json_encode(['status' => 'already_processed']));
    }

    // Extract metadata
    $invoice_id = null;
    $meta_zone_id = null;
    if (isset($tx['metadata']['custom_fields']) && is_array($tx['metadata']['custom_fields'])) {
        foreach ($tx['metadata']['custom_fields'] as $field) {
            if ($field['variable_name'] === 'invoice_id') {
                $invoice_id = intval($field['value']);
            } elseif ($field['variable_name'] === 'zone_id') {
                $meta_zone_id = intval($field['value']);
            }
        }
    }

    // Dedicated NUBAN / Virtual Account detection
    $dva_account = $tx['receiver_account_number'] 
        ?? ($tx['authorization']['receiver_bank_account_number'] ?? ($tx['dedicated_account']['account_number'] ?? null));
    $customer_code = $tx['customer']['customer_code'] ?? null;
    $customer_email = $conn->real_escape_string($tx['customer']['email'] ?? '');

    $zone = null;
    if (!empty($dva_account)) {
        $dva_clean = $conn->real_escape_string($dva_account);
        $z_res = $conn->query("SELECT id, name, estate_id FROM zones WHERE paystack_account_number = '$dva_clean' LIMIT 1");
        if ($z_res && $z_res->num_rows > 0) {
            $zone = $z_res->fetch_assoc();
        }
    }
    if (!$zone && !empty($customer_code)) {
        $c_code_clean = $conn->real_escape_string($customer_code);
        $z_res = $conn->query("SELECT id, name, estate_id FROM zones WHERE paystack_customer_code = '$c_code_clean' LIMIT 1");
        if ($z_res && $z_res->num_rows > 0) {
            $zone = $z_res->fetch_assoc();
        }
    }
    if (!$zone && $meta_zone_id) {
        $z_res = $conn->query("SELECT id, name, estate_id FROM zones WHERE id = $meta_zone_id LIMIT 1");
        if ($z_res && $z_res->num_rows > 0) {
            $zone = $z_res->fetch_assoc();
        }
    }

    // Scenario A: Standard Invoice Payment
    if ($invoice_id) {
        $inv_res = $conn->query("
            SELECT i.*, COALESCE(i.zone_id, s.zone_id) as resolved_zone_id, z.name as zone_name 
            FROM invoices i 
            LEFT JOIN flats f ON i.flat_id = f.id 
            LEFT JOIN buildings b ON f.building_id = b.id 
            LEFT JOIN streets s ON b.street_id = s.id 
            LEFT JOIN zones z ON (COALESCE(i.zone_id, s.zone_id) = z.id)
            WHERE i.id = $invoice_id LIMIT 1
        ");
        if ($inv_res && $inv_res->num_rows > 0) {
            $inv = $inv_res->fetch_assoc();
            if ($inv['status'] !== 'paid') {
                $user_id = $inv['user_id'];
                $estate_id = $inv['estate_id'];
                $prop_id = $inv['property_id'];
                $assigned_zone_id = !empty($inv['resolved_zone_id']) ? intval($inv['resolved_zone_id']) : ($zone['id'] ?? "NULL");
                $zone_name_str = $inv['zone_name'] ?: ($zone['name'] ?? '');
                
                $conn->begin_transaction();
                try {
                    // Update Invoice
                    $conn->query("UPDATE invoices SET status = 'paid', amount_paid = $amount_paid, balance = 0 WHERE id = $invoice_id");
                    
                    // Create Payment with zone_id
                    $pay_method = 'paystack_' . $channel;
                    $channel_name = strtoupper(str_replace('_', ' ', $channel));
                    $zone_info = !empty($zone_name_str) ? " [Remitted to $zone_name_str]" : "";
                    $desc = "Paystack Webhook Confirmed Payment (" . $channel_name . ") for Invoice #" . ($inv['invoice_number'] ?: ('INV-' . $invoice_id)) . $zone_info;
                    
                    $conn->query("INSERT INTO payments (estate_id, zone_id, user_id, invoice_id, property_id, amount, type, payment_method, status, payment_reference, transaction_ref, paid_at, description) 
                                  VALUES ($estate_id, " . ($assigned_zone_id !== "NULL" ? $assigned_zone_id : "NULL") . ", $user_id, $invoice_id, " . ($prop_id ? $prop_id : "NULL") . ", $amount_paid, '{$inv['title']}', '$pay_method', 'paid', '$reference', '$reference', NOW(), '$desc')");
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
                    
                    // Dispatch Payment Receipt Email to Resident
                    EstateMailer::sendReceiptEmail($conn, $receipt_no);

                    logAudit($conn, "Paystack Webhook Processed", "Finance", "Webhook marked invoice #$invoice_id paid (Ref: $reference)$zone_info");
                } catch (Exception $e) {
                    $conn->rollback();
                }
            }
        }
    } 
    // Scenario B: Direct Virtual Account Bank Transfer without prior invoice metadata
    elseif ($zone) {
        $zone_id_val = intval($zone['id']);
        $estate_id_val = intval($zone['estate_id']);

        // Find resident user by email
        $user_res = $conn->query("SELECT id FROM users WHERE email = '$customer_email' AND estate_id = $estate_id_val LIMIT 1");
        $user_id_val = ($user_res && $user_res->num_rows > 0) ? intval($user_res->fetch_assoc()['id']) : 0;

        // Check if user has an outstanding unpaid invoice in this zone
        $open_inv_query = "SELECT id, title, invoice_number, property_id FROM invoices WHERE status != 'paid' AND estate_id = $estate_id_val AND zone_id = $zone_id_val";
        if ($user_id_val > 0) {
            $open_inv_query .= " AND user_id = $user_id_val";
        }
        $open_inv_query .= " ORDER BY due_date ASC LIMIT 1";
        $open_inv_res = $conn->query($open_inv_query);

        $target_inv_id = "NULL";
        $target_prop_id = "NULL";
        $charge_title = "Zone Virtual Account Remittance";

        if ($open_inv_res && $open_inv_res->num_rows > 0) {
            $open_inv = $open_inv_res->fetch_assoc();
            $target_inv_id = intval($open_inv['id']);
            $target_prop_id = !empty($open_inv['property_id']) ? intval($open_inv['property_id']) : "NULL";
            $charge_title = $open_inv['title'];
        }

        $conn->begin_transaction();
        try {
            if ($target_inv_id !== "NULL") {
                $conn->query("UPDATE invoices SET status = 'paid', amount_paid = $amount_paid, balance = 0 WHERE id = $target_inv_id");
            }

            $desc = "Direct Transfer to Zone Virtual Account ({$zone['name']})";
            $conn->query("INSERT INTO payments (estate_id, zone_id, user_id, invoice_id, property_id, amount, type, payment_method, status, payment_reference, transaction_ref, paid_at, description) 
                          VALUES ($estate_id_val, $zone_id_val, " . ($user_id_val ?: "NULL") . ", $target_inv_id, $target_prop_id, $amount_paid, '$charge_title', 'paystack_dedicated_nuban', 'paid', '$reference', '$reference', NOW(), '$desc')");
            $payment_id = $conn->insert_id;

            $tx_json = $conn->real_escape_string(json_encode($tx));
            $conn->query("INSERT INTO payment_transactions (payment_id, gateway, gateway_reference, gateway_transaction_id, channel, amount, currency, gateway_response, verified_at) 
                          VALUES ($payment_id, 'Paystack', '$reference', '$gateway_id', 'dedicated_nuban', $amount_paid, 'NGN', '$tx_json', NOW())");

            $receipt_no = generateReceiptNumber($conn);
            $conn->query("INSERT INTO receipts (estate_id, receipt_number, payment_id, resident_id, property_id, amount, issued_at) 
                          VALUES ($estate_id_val, '$receipt_no', $payment_id, " . ($user_id_val ?: "NULL") . ", $target_prop_id, $amount_paid, NOW())");

            $conn->commit();

            if ($user_id_val > 0) {
                EstateMailer::sendReceiptEmail($conn, $receipt_no);
            }

            logAudit($conn, "Zone Virtual Account Remittance", "Finance", "Direct DVA payment of ₦" . number_format($amount_paid, 2) . " remitted to {$zone['name']} (Ref: $reference). Receipt: $receipt_no");
        } catch (Exception $e) {
            $conn->rollback();
        }
    }
}

http_response_code(200);
echo json_encode(['status' => 'success']);
?>
