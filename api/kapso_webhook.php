<?php
// api/kapso_webhook.php - Kapso / WhatsApp Cloud API Webhook Listener
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/WhatsApp.php';

header('Content-Type: application/json; charset=UTF-8');

// Ensure database tables
EstateWhatsApp::ensureDatabaseTables($conn);

// -------------------------------------------------------------
// 1. WEBHOOK VERIFICATION (GET REQUEST FROM KAPSO / META)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? ($_GET['hub']['mode'] ?? '');
    $token = $_GET['hub_verify_token'] ?? ($_GET['hub']['verify_token'] ?? '');
    $challenge = $_GET['hub_challenge'] ?? ($_GET['hub']['challenge'] ?? '');

    // Check against configured verification tokens in system_settings
    $token_esc = $conn->real_escape_string($token);
    $chk = $conn->query("SELECT estate_id FROM system_settings WHERE setting_key = 'kapso_webhook_verify_token' AND setting_value = '$token_esc' LIMIT 1");

    if ($mode === 'subscribe' && $chk && $chk->num_rows > 0) {
        http_response_code(200);
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }

    http_response_code(403);
    echo json_encode(['error' => 'Verification token mismatch or invalid mode']);
    exit;
}

// -------------------------------------------------------------
// 2. INCOMING EVENT HANDLING (POST REQUEST)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawPayload = file_get_contents('php://input');
    $payload = json_decode($rawPayload, true);

    if (!$payload) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        exit;
    }

    // Process Message Status Updates (sent -> delivered -> read -> failed)
    if (isset($payload['entry'])) {
        foreach ($payload['entry'] as $entry) {
            if (isset($entry['changes'])) {
                foreach ($entry['changes'] as $change) {
                    $val = $change['value'] ?? [];
                    
                    if (isset($val['statuses'])) {
                        foreach ($val['statuses'] as $st) {
                            $wamid = $conn->real_escape_string($st['id'] ?? '');
                            $status = strtolower($st['status'] ?? '');
                            $errorMsg = null;

                            if (!empty($st['errors']) && is_array($st['errors'])) {
                                $errorMsg = $conn->real_escape_string($st['errors'][0]['message'] ?? ($st['errors'][0]['title'] ?? 'Delivery failed'));
                            }

                            if (!empty($wamid) && in_array($status, ['sent', 'delivered', 'read', 'failed'])) {
                                $updateSql = "UPDATE whatsapp_logs SET status = '$status'";
                                if ($errorMsg) {
                                    $updateSql .= ", error_message = '$errorMsg'";
                                }
                                $updateSql .= " WHERE response_id = '$wamid'";
                                $conn->query($updateSql);
                            }
                        }
                    }
                }
            }
        }
    }

    // Return 200 OK acknowledgment
    http_response_code(200);
    echo json_encode(['status' => 'acknowledged']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
