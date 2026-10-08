<?php
// api/platform_webhook.php - Paystack webhook for SUBSCRIPTION payments (platform account).
// Authenticated by HMAC signature only; every event is re-verified against Paystack before it changes anything.
define('NO_CSRF_INJECT', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Billing.php';
header('Content-Type: application/json');

$secret = env_get('PLATFORM_PAYSTACK_SECRET', '');
$body = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
if ($secret === '' || $sig === '' || !hash_equals(hash_hmac('sha512', $body, $secret), $sig)) {
    http_response_code(401);
    Tenancy::logError('webhook', 'Platform webhook rejected: bad or missing signature', null, 'warning', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    echo json_encode(['status' => 'invalid signature']);
    exit;
}
$event = json_decode($body, true) ?: [];
$ref = $event['data']['reference'] ?? '';
try {
    if ($ref && in_array($event['event'] ?? '', ['charge.success', 'charge.failed'], true)) Billing::verifyAndApply($ref);
} catch (Throwable $e) { Tenancy::logError('webhook', 'Platform webhook processing failed: ' . $e->getMessage(), null, 'error'); http_response_code(500); echo json_encode(['status' => 'error']); exit; }
echo json_encode(['status' => 'ok']);
