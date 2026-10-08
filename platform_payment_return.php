<?php
// platform_payment_return.php - Paystack redirects the payer here after a SUBSCRIPTION payment.
// The result is always re-verified server-side with Paystack; the query string is never trusted.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Billing.php';
$ref = preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['reference'] ?? $_GET['trxref'] ?? '');
$st = Tenancy::platform()->prepare("SELECT estate_id FROM payments WHERE reference = ? LIMIT 1");
$st->bind_param('s', $ref); $st->execute();
$row = $st->get_result()->fetch_assoc();
if (!$row) { header('Location: login'); exit; }
$ok = Billing::verifyAndApply($ref);
$estate = Tenancy::find($row['estate_id']);
$status = Tenancy::platform()->query("SELECT status FROM payments WHERE reference = '" . Tenancy::platform()->real_escape_string($ref) . "'")->fetch_assoc()['status'] ?? 'pending';
$_SESSION[$status === 'success' ? 'flash_success' : 'flash_error'] = $status === 'success' ? 'Payment received. Your subscription is active.' : ($status === 'pending' ? 'We are confirming your payment. This page will update shortly.' : 'The payment was not completed.');
header('Location: ' . Notifier::estateUrl($estate, 'admin/subscription'));
