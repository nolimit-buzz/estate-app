<?php
// api/resolve_bank.php - Resolves Nigerian Bank Account details via Paystack
require_once '../config.php';
require_once '../includes/Paystack.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => false, 'message' => 'Unauthorized']);
    exit;
}

$account_number = trim($_GET['account_number'] ?? '');
$bank_code = trim($_GET['bank_code'] ?? '');

if (strlen($account_number) !== 10 || empty($bank_code)) {
    echo json_encode(['status' => false, 'message' => 'Provide a valid 10-digit account number and select a bank.']);
    exit;
}

try {
    $paystack = new Paystack($conn);
    $res = $paystack->resolveAccountNumber($account_number, $bank_code);
    if (isset($res['data']['account_name'])) {
        echo json_encode([
            'status' => true,
            'account_name' => $res['data']['account_name'],
            'account_number' => $res['data']['account_number'] ?? $account_number
        ]);
    } else {
        echo json_encode(['status' => false, 'message' => 'Could not resolve account name. Check parameters.']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => false, 'message' => $e->getMessage()]);
}
