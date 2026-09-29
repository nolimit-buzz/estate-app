<?php
// includes/WhatsApp.php - Core Estate WhatsApp & Kapso Notification Service
require_once __DIR__ . '/../config.php';

class EstateWhatsApp
{
    private static $initialized = false;
    const KAPSO_API_BASE = 'https://api.kapso.ai/meta/whatsapp/v24.0';
    const KAPSO_PLATFORM_BASE = 'https://api.kapso.ai/platform/v1';

    /**
     * Auto-ensure required database tables and default configuration exist
     */
    public static function ensureDatabaseTables($conn)
    {
        if (self::$initialized)
            return;
        self::$initialized = true;

        if (!$conn || !($conn instanceof mysqli))
            return;

        // 1. WhatsApp Logs Table
        $conn->query("CREATE TABLE IF NOT EXISTS whatsapp_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            estate_id INT DEFAULT 1,
            recipient_phone VARCHAR(32) NOT NULL,
            recipient_name VARCHAR(191) NULL,
            message_type VARCHAR(64) NOT NULL DEFAULT 'text',
            template VARCHAR(64) NOT NULL DEFAULT 'general',
            reference_id VARCHAR(64) NULL,
            message_body TEXT NOT NULL,
            media_url TEXT NULL,
            status ENUM('sent', 'failed', 'delivered', 'read') NOT NULL DEFAULT 'sent',
            response_id VARCHAR(191) NULL,
            error_message TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (estate_id),
            INDEX (recipient_phone),
            INDEX (status),
            INDEX (template),
            INDEX (reference_id),
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 2. Default System Settings for Kapso WhatsApp
        $estate_id = function_exists('get_estate_id') ? get_estate_id() : 1;
        $default_token = bin2hex(random_bytes(16));

        $default_settings = [
            'whatsapp_enabled' => '0',
            'whatsapp_provider' => 'kapso',
            'kapso_api_key' => '',
            'kapso_phone_number_id' => '',
            'kapso_webhook_verify_token' => $default_token,
            'whatsapp_from_name' => 'Estate Central Admin',
            'whatsapp_notify_on_invoice' => '1',
            'whatsapp_notify_on_receipt' => '1',
            'whatsapp_notify_on_visitor_pass' => '1',
            'whatsapp_notify_on_visitor_arrival' => '1',
            'whatsapp_notify_on_welcome' => '1',
            'whatsapp_notify_on_emergency' => '1',
            'whatsapp_notify_on_artisan_pass' => '1',
            'whatsapp_notify_on_penalty' => '1',
            'whatsapp_notify_on_broadcast' => '1'
        ];

        foreach ($default_settings as $k => $v) {
            $chk = $conn->query("SELECT 1 FROM system_settings WHERE estate_id = $estate_id AND setting_key = '$k' LIMIT 1");
            if (!$chk || $chk->num_rows == 0) {
                $v_esc = $conn->real_escape_string($v);
                $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES ($estate_id, '$k', '$v_esc')");
            }
        }
    }

    /**
     * Resolve fully qualified Base Application URL for links inside WhatsApp messages
     */
    public static function getBaseUrl()
    {
        if (isset($_SERVER['HTTP_HOST'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'];
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            if (stripos($script, '/Estate/') !== false) {
                $pos = stripos($script, '/Estate/');
                $sub = substr($script, 0, $pos + 8);
                return $protocol . $host . $sub;
            } else {
                $dir = dirname($script);
                $dir = ($dir === '/' || $dir === '\\') ? '/' : rtrim(str_replace('\\', '/', $dir), '/') . '/';
                return $protocol . $host . $dir;
            }
        }
        return 'http://localhost/Estate/';
    }

    /**
     * Normalize and format phone number for WhatsApp E.164 (without leading +)
     * e.g.: '08031234567' -> '2348031234567'
     *       '+234 803 123 4567' -> '2348031234567'
     *       '+1 (555) 012-3456' -> '15550123456'
     */
    public static function formatPhoneNumber($phone)
    {
        if (empty($phone))
            return '';
        $digits = preg_replace('/[^0-9]/', '', (string) $phone);
        if (empty($digits))
            return '';

        // Handle Nigerian 11-digit local numbers starting with 0
        if (strlen($digits) === 11 && strpos($digits, '0') === 0) {
            return '234' . substr($digits, 1);
        }

        // Handle leading double zeros '00'
        if (strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        }

        // Standard validation: E.164 numbers are usually 9 to 15 digits
        if (strlen($digits) < 9 || strlen($digits) > 15) {
            return '';
        }

        return $digits;
    }

    /**
     * Retrieve all WhatsApp / Kapso configuration details for an estate
     */
    public static function getWhatsAppSettings($conn, $estate_id = null)
    {
        if (!$estate_id && function_exists('get_estate_id')) {
            $estate_id = get_estate_id();
        }
        if (!$estate_id)
            $estate_id = 1;

        self::ensureDatabaseTables($conn);

        $settings = [];
        $res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE estate_id = $estate_id");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $settings[$r['setting_key']] = $r['setting_value'];
            }
        }

        $estate_row = null;
        $est_chk = $conn->query("SELECT * FROM estates WHERE id = $estate_id LIMIT 1");
        if ($est_chk && $est_chk->num_rows > 0) {
            $estate_row = $est_chk->fetch_assoc();
        }

        $base_url = self::getBaseUrl();

        return [
            'estate_id' => $estate_id,
            'estate_name' => $settings['estate_name'] ?? ($estate_row['name'] ?? 'Estate Management'),
            'currency_symbol' => $settings['currency_symbol'] ?? '₦',
            'office_phone' => $settings['office_phone'] ?? '+234 800 000 0000',
            'office_email' => $settings['office_email'] ?? 'support@estate.com',
            'base_url' => $base_url,
            'whatsapp_enabled' => ($settings['whatsapp_enabled'] ?? '0') === '1',
            'whatsapp_provider' => $settings['whatsapp_provider'] ?? 'kapso',
            'kapso_api_key' => trim($settings['kapso_api_key'] ?? ''),
            'kapso_phone_number_id' => trim($settings['kapso_phone_number_id'] ?? ''),
            'kapso_webhook_verify_token' => $settings['kapso_webhook_verify_token'] ?? '',
            'whatsapp_from_name' => !empty($settings['whatsapp_from_name']) ? $settings['whatsapp_from_name'] : ($settings['estate_name'] ?? 'Estate Administration'),
            'notify_on_invoice' => ($settings['whatsapp_notify_on_invoice'] ?? '1') === '1',
            'notify_on_receipt' => ($settings['whatsapp_notify_on_receipt'] ?? '1') === '1',
            'notify_on_visitor_pass' => ($settings['whatsapp_notify_on_visitor_pass'] ?? '1') === '1',
            'notify_on_visitor_arrival' => ($settings['whatsapp_notify_on_visitor_arrival'] ?? '1') === '1',
            'notify_on_welcome' => ($settings['whatsapp_notify_on_welcome'] ?? '1') === '1',
            'notify_on_emergency' => ($settings['whatsapp_notify_on_emergency'] ?? '1') === '1',
            'notify_on_artisan_pass' => ($settings['whatsapp_notify_on_artisan_pass'] ?? '1') === '1',
            'notify_on_penalty' => ($settings['whatsapp_notify_on_penalty'] ?? '1') === '1',
            'notify_on_broadcast' => ($settings['whatsapp_notify_on_broadcast'] ?? '1') === '1',
            'kapso_business_account_id' => $settings['kapso_business_account_id'] ?? '1157218086854130',
            'kapso_sender_phone' => $settings['kapso_sender_phone'] ?? '+234 903 647 7098',
            'webhook_url' => $base_url . 'api/kapso_webhook.php'
        ];
    }

    /**
     * Core Low-Level Message Dispatcher via Kapso WhatsApp API
     *
     * @param mysqli $conn
     * @param string $toPhone
     * @param string $toName
     * @param string $messageBody
     * @param string $template
     * @param string|null $refId
     * @param int|null $estate_id
     * @param string|null $mediaUrl
     * @param string $mediaType 'text'|'image'|'document'
     * @param bool $ignoreEnabledCheck Set true for test diagnostics
     * @return array ['success' => bool, 'response_id' => string|null, 'error' => string|null]
     */
    public static function sendMessage($conn, $toPhone, $toName, $messageBody, $template = 'general', $refId = null, $estate_id = null, $mediaUrl = null, $mediaType = 'text', $ignoreEnabledCheck = false)
    {
        if (!$conn || !($conn instanceof mysqli)) {
            return ['success' => false, 'error' => 'Database connection unavailable'];
        }

        $config = self::getWhatsAppSettings($conn, $estate_id);
        $estate_id = $config['estate_id'];

        if (!$ignoreEnabledCheck && !$config['whatsapp_enabled']) {
            return ['success' => false, 'error' => 'WhatsApp direct dispatch is currently disabled in settings'];
        }

        $formattedPhone = self::formatPhoneNumber($toPhone);
        if (empty($formattedPhone)) {
            $err = "Invalid phone number format: '$toPhone'";
            self::logWhatsApp($conn, $estate_id, $toPhone, $toName, $mediaType, $template, $refId, $messageBody, $mediaUrl, 'failed', null, $err);
            return ['success' => false, 'error' => $err];
        }

        if (empty($config['kapso_api_key'])) {
            $err = 'Kapso Project API Key is not configured. Please enter your API key in Settings > WhatsApp.';
            self::logWhatsApp($conn, $estate_id, $formattedPhone, $toName, $mediaType, $template, $refId, $messageBody, $mediaUrl, 'failed', null, $err);
            return ['success' => false, 'error' => $err];
        }

        if (empty($config['kapso_phone_number_id'])) {
            $err = 'Kapso WhatsApp Phone Number ID is not configured in Settings > WhatsApp.';
            self::logWhatsApp($conn, $estate_id, $formattedPhone, $toName, $mediaType, $template, $refId, $messageBody, $mediaUrl, 'failed', null, $err);
            return ['success' => false, 'error' => $err];
        }

        // Build Payload
        $endpoint = rtrim(self::KAPSO_API_BASE, '/') . '/' . rawurlencode($config['kapso_phone_number_id']) . '/messages';

        if ($mediaType === 'image' && !empty($mediaUrl)) {
            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $formattedPhone,
                'type' => 'image',
                'image' => [
                    'link' => $mediaUrl,
                    'caption' => $messageBody
                ]
            ];
        } elseif ($mediaType === 'document' && !empty($mediaUrl)) {
            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $formattedPhone,
                'type' => 'document',
                'document' => [
                    'link' => $mediaUrl,
                    'caption' => $messageBody,
                    'filename' => basename(parse_url($mediaUrl, PHP_URL_PATH) ?: 'Document.pdf')
                ]
            ];
        } else {
            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $formattedPhone,
                'type' => 'text',
                'text' => [
                    'preview_url' => true,
                    'body' => $messageBody
                ]
            ];
        }

        $jsonPayload = json_encode($payload);

        // Execute cURL request to Kapso
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-API-Key: ' . $config['kapso_api_key']
            ],
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $err = "cURL Network Error: " . $curlError;
            self::logWhatsApp($conn, $estate_id, $formattedPhone, $toName, $mediaType, $template, $refId, $messageBody, $mediaUrl, 'failed', null, $err);
            return ['success' => false, 'error' => $err];
        }

        $resultData = json_decode($response, true);

        // Check for Kapso / Meta successful response (200 OK or 201 Created)
        if ($httpCode >= 200 && $httpCode < 300) {
            $wamid = null;
            if (isset($resultData['messages'][0]['id'])) {
                $wamid = $resultData['messages'][0]['id'];
            }
            self::logWhatsApp($conn, $estate_id, $formattedPhone, $toName, $mediaType, $template, $refId, $messageBody, $mediaUrl, 'sent', $wamid, null);
            return ['success' => true, 'response_id' => $wamid, 'data' => $resultData];
        } else {
            // Error extracted from Kapso / Meta JSON
            $errorDetail = 'HTTP ' . $httpCode;
            if (isset($resultData['error']['message'])) {
                $errorDetail .= ': ' . $resultData['error']['message'];
                if (isset($resultData['error']['error_data']['details'])) {
                    $errorDetail .= ' (' . $resultData['error']['error_data']['details'] . ')';
                }
            } elseif (isset($resultData['message'])) {
                $errorDetail .= ': ' . $resultData['message'];
            } else {
                $errorDetail .= ': ' . substr($response, 0, 200);
            }

            // Check for 24-hour customer service window restriction
            if (stripos($response, '24-hour') !== false || stripos($response, 'outside the 24-hour') !== false) {
                // Check if estate_notice template is approved and attempt automatic fallback
                $tplCheck = self::checkTemplateStatus($conn, 'estate_notice', $estate_id);
                if (!empty($tplCheck['approved'])) {
                    $tplParams = [
                        $toName ?: 'Resident',
                        substr(strip_tags(str_replace(["\r", "\n"], ' ', $messageBody)), 0, 100),
                        $config['estate_name']
                    ];
                    $tplRes = self::sendTemplateMessage($conn, $formattedPhone, $toName, 'estate_notice', 'en_US', $tplParams, $refId, $estate_id);
                    if ($tplRes['success']) {
                        return $tplRes;
                    }
                }

                $sender = $config['kapso_sender_phone'] ?? '+234 903 647 7098';
                $cleanSender = preg_replace('/[^0-9]/', '', $sender);
                $errorDetail = "WhatsApp 24-Hour Policy: Recipient has not messaged your estate number ($sender) in the past 24 hours. Send 'Hi' to $sender (https://wa.me/{$cleanSender}?text=Hi) to open the 24-hour messaging window.";
            }

            self::logWhatsApp($conn, $estate_id, $formattedPhone, $toName, $mediaType, $template, $refId, $messageBody, $mediaUrl, 'failed', null, $errorDetail);
            $cleanSender = preg_replace('/[^0-9]/', '', $config['kapso_sender_phone'] ?? '2349036477098');
            return [
                'success' => false, 
                'error' => $errorDetail, 
                'data' => $resultData,
                'action_url' => "https://wa.me/{$cleanSender}?text=Hi",
                'sender_phone' => $config['kapso_sender_phone'] ?? '+234 903 647 7098'
            ];
        }
    }

    /**
     * Helper to send plain text message with flexible argument order
     */
    public static function sendTextMessage($conn, $toPhone, $messageBody, $template = 'general', $refId = null, $toName = '', $estate_id = null)
    {
        return self::sendMessage($conn, $toPhone, $toName, $messageBody, $template, $refId, $estate_id);
    }

    private static $templateCache = [];

    /**
     * Check Meta / Kapso approval status of a message template
     */
    public static function checkTemplateStatus($conn, $templateName = 'estate_notice', $estate_id = null) {
        if (isset(self::$templateCache[$templateName])) {
            return self::$templateCache[$templateName];
        }

        $config = self::getWhatsAppSettings($conn, $estate_id);
        $wabaId = $config['kapso_business_account_id'] ?: '1157218086854130';

        if (empty($config['kapso_api_key'])) {
            self::$templateCache[$templateName] = ['status' => 'unconfigured', 'approved' => false];
            return self::$templateCache[$templateName];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => rtrim(self::KAPSO_API_BASE, '/') . '/' . rawurlencode($wabaId) . '/message_templates',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-API-Key: ' . $config['kapso_api_key']
            ],
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $res = curl_exec($ch);
        curl_close($ch);

        if (!$res) {
            self::$templateCache[$templateName] = ['status' => 'error', 'approved' => false];
            return self::$templateCache[$templateName];
        }
        $json = json_decode($res, true);

        if (isset($json['data']) && is_array($json['data'])) {
            foreach ($json['data'] as $tpl) {
                if (($tpl['name'] ?? '') === $templateName) {
                    $status = strtoupper($tpl['status'] ?? 'UNKNOWN');
                    self::$templateCache[$templateName] = [
                        'name' => $templateName,
                        'status' => $status,
                        'approved' => ($status === 'APPROVED')
                    ];
                    return self::$templateCache[$templateName];
                }
            }
        }

        self::$templateCache[$templateName] = ['status' => 'not_found', 'approved' => false];
        return self::$templateCache[$templateName];
    }

    /**
     * Send approved WhatsApp Template Message (can be sent outside the 24-hour window)
     */
    public static function sendTemplateMessage($conn, $toPhone, $toName, $templateName, $languageCode = 'en_US', $bodyParams = [], $refId = null, $estate_id = null) {
        $config = self::getWhatsAppSettings($conn, $estate_id);
        $estate_id = $config['estate_id'];
        $formattedPhone = self::formatPhoneNumber($toPhone);

        if (empty($formattedPhone)) {
            return ['success' => false, 'error' => "Invalid phone number: '$toPhone'"];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $formattedPhone,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $languageCode]
            ]
        ];

        if (!empty($bodyParams)) {
            $paramObjects = [];
            foreach ($bodyParams as $p) {
                $paramObjects[] = ['type' => 'text', 'text' => (string)$p];
            }
            $payload['template']['components'] = [
                [
                    'type' => 'body',
                    'parameters' => $paramObjects
                ]
            ];
        }

        $endpoint = rtrim(self::KAPSO_API_BASE, '/') . '/' . rawurlencode($config['kapso_phone_number_id']) . '/messages';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-API-Key: ' . $config['kapso_api_key']
            ],
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $resultData = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            $wamid = $resultData['messages'][0]['id'] ?? null;
            self::logWhatsApp($conn, $estate_id, $formattedPhone, $toName, 'template', $templateName, $refId, "Template: $templateName (" . implode(', ', $bodyParams) . ")", null, 'sent', $wamid, null);
            return ['success' => true, 'response_id' => $wamid, 'data' => $resultData];
        } else {
            $err = $resultData['error']['message'] ?? ($resultData['message'] ?? 'Template send failed');
            self::logWhatsApp($conn, $estate_id, $formattedPhone, $toName, 'template', $templateName, $refId, "Template: $templateName", null, 'failed', null, $err);
            return ['success' => false, 'error' => $err, 'data' => $resultData];
        }
    }

    /**
     * Record dispatch in audit table whatsapp_logs
     */
    public static function logWhatsApp($conn, $estate_id, $phone, $name, $msgType, $template, $refId, $body, $mediaUrl, $status, $responseId, $error)
    {
        $estate_id = intval($estate_id);
        $phone = $conn->real_escape_string($phone ?: '');
        $name = $conn->real_escape_string($name ?: '');
        $msgType = $conn->real_escape_string($msgType ?: 'text');
        $template = $conn->real_escape_string($template ?: 'general');
        $refId = $conn->real_escape_string($refId ?: '');
        $body = $conn->real_escape_string($body ?: '');
        $mediaUrl = !empty($mediaUrl) ? "'" . $conn->real_escape_string($mediaUrl) . "'" : "NULL";
        $status = $conn->real_escape_string($status ?: 'sent');
        $responseId = !empty($responseId) ? "'" . $conn->real_escape_string($responseId) . "'" : "NULL";
        $error = !empty($error) ? "'" . $conn->real_escape_string($error) . "'" : "NULL";

        $conn->query("INSERT INTO whatsapp_logs 
            (estate_id, recipient_phone, recipient_name, message_type, template, reference_id, message_body, media_url, status, response_id, error_message, created_at)
            VALUES ($estate_id, '$phone', '$name', '$msgType', '$template', '$refId', '$body', $mediaUrl, '$status', $responseId, $error, NOW())");
    }

    // =========================================================================
    // HIGH-LEVEL NOTIFICATION TRIGGERS (MATCHING ESTATE MAILER)
    // =========================================================================

    /**
     * 1. Send Invoice Notification via WhatsApp
     */
    public static function sendInvoiceWhatsApp($conn, $invoice_id)
    {
        $config = self::getWhatsAppSettings($conn);
        if (!$config['whatsapp_enabled'] || !$config['notify_on_invoice'])
            return false;

        $invoice_id = intval($invoice_id);
        $query = "SELECT inv.*, u.name as resident_name, u.phone as resident_phone,
                         f.number as flat_number, b.name as building_name
                  FROM invoices inv
                  JOIN users u ON inv.user_id = u.id
                  LEFT JOIN residents r ON r.user_id = u.id AND r.estate_id = inv.estate_id
                  LEFT JOIN flats f ON r.flat_id = f.id
                  LEFT JOIN buildings b ON f.building_id = b.id
                  WHERE inv.id = $invoice_id LIMIT 1";

        $res = $conn->query($query);
        if (!$res || $res->num_rows === 0)
            return false;
        $inv = $res->fetch_assoc();

        if (empty($inv['resident_phone']))
            return false;

        $curr = $config['currency_symbol'];
        $amount_fmt = $curr . number_format($inv['amount'], 2);
        $due_date_fmt = date('M d, Y', strtotime($inv['due_date']));
        $inv_no = $inv['invoice_number'] ?: ('INV-' . $inv['id']);
        $pay_url = $config['base_url'] . 'pay_invoice.php?inv=' . urlencode($inv_no);
        $estate_name = $config['estate_name'];
        $unit_str = !empty($inv['flat_number']) ? ("Flat " . $inv['flat_number'] . (!empty($inv['building_name']) ? (", " . $inv['building_name']) : '')) : 'Resident';

        $msg = "🧾 *INVOICE NOTIFICATION*\n" .
            "--------------------------------\n" .
            "🏛️ *Estate:* $estate_name\n" .
            "🔢 *Invoice #:* $inv_no\n" .
            "👤 *Recipient:* {$inv['resident_name']} ($unit_str)\n\n" .
            "📌 *Description:* {$inv['title']}\n" .
            "💰 *Amount Due:* *$amount_fmt*\n" .
            "📅 *Due Date:* $due_date_fmt\n\n" .
            "💳 *Pay Online Now:*\n$pay_url\n\n" .
            "--------------------------------\n" .
            "_Thank you for your prompt payment! If you have already paid, please disregard this notice._";

        $result = self::sendMessage($conn, $inv['resident_phone'], $inv['resident_name'], $msg, 'invoice', $inv_no, $inv['estate_id']);
        return $result['success'];
    }

    /**
     * 2. Send Payment Receipt Notification via WhatsApp
     */
    public static function sendReceiptWhatsApp($conn, $receipt_id_or_number)
    {
        $config = self::getWhatsAppSettings($conn);
        if (!$config['whatsapp_enabled'] || !$config['notify_on_receipt'])
            return false;

        $receipt_safe = $conn->real_escape_string(trim($receipt_id_or_number));
        $where = is_numeric($receipt_id_or_number) ? "(r.id = $receipt_safe OR r.receipt_number = '$receipt_safe')" : "r.receipt_number = '$receipt_safe'";

        $query = "SELECT r.*, p.payment_method, p.transaction_ref, p.paid_at, p.type as payment_title,
                         u.name as resident_name, u.phone as resident_phone,
                         f.number as flat_number, b.name as building_name
                  FROM receipts r
                  LEFT JOIN payments p ON r.payment_id = p.id
                  JOIN users u ON r.resident_id = u.id
                  LEFT JOIN flats f ON r.property_id = f.id
                  LEFT JOIN buildings b ON f.building_id = b.id
                  WHERE $where LIMIT 1";

        $res = $conn->query($query);
        if (!$res || $res->num_rows === 0)
            return false;
        $rec = $res->fetch_assoc();

        if (empty($rec['resident_phone']))
            return false;

        $curr = $config['currency_symbol'];
        $amount_fmt = $curr . number_format($rec['amount'], 2);
        $receipt_no = $rec['receipt_number'];
        $receipt_url = $config['base_url'] . 'resident/receipt.php?receipt_no=' . urlencode($receipt_no);
        $estate_name = $config['estate_name'];
        $paid_date = !empty($rec['issued_at']) ? date('M d, Y h:i A', strtotime($rec['issued_at'])) : date('M d, Y');
        $channel = strtoupper(str_replace(['_', '-'], ' ', $rec['payment_method'] ?? 'Online'));

        $msg = "✅ *OFFICIAL PAYMENT RECEIPT*\n" .
            "--------------------------------\n" .
            "🏛️ *Estate:* $estate_name\n" .
            "🧾 *Receipt #:* $receipt_no\n\n" .
            "Dear *{$rec['resident_name']}*,\n" .
            "Thank you! Your payment has been received and credited to your account.\n\n" .
            "💰 *Amount Paid:* *$amount_fmt*\n" .
            "📌 *Payment For:* {$rec['payment_title']}\n" .
            "💳 *Method:* $channel\n" .
            "🔢 *Reference:* {$rec['transaction_ref']}\n" .
            "📅 *Date:* $paid_date\n\n" .
            "📄 *View & Print Official Receipt:*\n$receipt_url\n\n" .
            "--------------------------------\n" .
            "_This is an automated confirmation from {$estate_name}._";

        $result = self::sendMessage($conn, $rec['resident_phone'], $rec['resident_name'], $msg, 'receipt', $receipt_no, $rec['estate_id']);
        return $result['success'];
    }

    /**
     * 3. Send Visitor Digital Pass via WhatsApp (to Visitor and/or Resident Host)
     */
    public static function sendVisitorPassWhatsApp($conn, $visitor_id)
    {
        $config = self::getWhatsAppSettings($conn);
        if (!$config['whatsapp_enabled'] || !$config['notify_on_visitor_pass'])
            return false;

        $v_id = intval($visitor_id);
        $query = "SELECT v.*, u.name as resident_name, u.phone as resident_phone,
                         f.number as flat_number, b.name as building_name, s.name as street_name
                  FROM visitors v
                  LEFT JOIN users u ON v.resident_id = u.id
                  LEFT JOIN flats f ON v.flat_id = f.id
                  LEFT JOIN buildings b ON f.building_id = b.id
                  LEFT JOIN streets s ON b.street_id = s.id
                  WHERE v.id = $v_id LIMIT 1";

        $res = $conn->query($query);
        if (!$res || $res->num_rows === 0)
            return false;
        $v = $res->fetch_assoc();

        $visitor_code = $v['visitor_code'];
        $pass_url = $config['base_url'] . 'gate_pass.php?code=' . urlencode($visitor_code);
        $visitor_name = $v['name'];
        $arrival = !empty($v['expected_arrival']) ? date('M d, Y h:i A', strtotime($v['expected_arrival'])) : 'Anytime Today';
        $location_str = "Flat " . ($v['flat_number'] ?? 'Unit') . ", " . ($v['building_name'] ?? '') . " (" . ($v['street_name'] ?? 'Estate') . ")";
        $estate_name = $config['estate_name'];

        $dispatched = false;

        // Dispatch 1: To Visitor (if visitor phone provided)
        if (!empty($v['phone'])) {
            $visitorMsg = "🎟️ *VISITOR GATE ACCESS PASS*\n" .
                "--------------------------------\n" .
                "🏛️ *Estate:* $estate_name\n\n" .
                "Hello *$visitor_name*,\n" .
                "You have been pre-registered for entry. Please present this passcode or open your digital pass upon arrival at the security gate:\n\n" .
                "🔐 *ACCESS CODE:* *$visitor_code*\n\n" .
                "👤 *Host:* {$v['resident_name']}\n" .
                "📍 *Destination:* $location_str\n" .
                "🕒 *Expected:* $arrival\n" .
                "📝 *Purpose:* {$v['purpose']}\n\n" .
                "📱 *Open Digital Pass & QR Code:*\n$pass_url\n\n" .
                "--------------------------------\n" .
                "_Please keep a valid photo ID ready. Speed limit within estate is 20km/h._";

            $r1 = self::sendMessage($conn, $v['phone'], $visitor_name, $visitorMsg, 'visitor_pass', $visitor_code, $v['estate_id']);
            if ($r1['success'])
                $dispatched = true;
        }

        // Dispatch 2: Confirmation copy to Resident Host
        if (!empty($v['resident_phone'])) {
            $residentMsg = "🎟️ *VISITOR PASS CREATED*\n" .
                "--------------------------------\n" .
                "🏛️ *Estate:* $estate_name\n\n" .
                "Dear *{$v['resident_name']}*,\n" .
                "You have successfully pre-registered visitor *$visitor_name*.\n\n" .
                "🔐 *Access Code:* *$visitor_code*\n" .
                "🕒 *Expected Arrival:* $arrival\n" .
                "📝 *Purpose:* {$v['purpose']}\n\n" .
                "📱 *Share / View Digital Gate Pass:*\n$pass_url\n\n" .
                "--------------------------------\n" .
                "_You will receive an automatic WhatsApp security alert the moment $visitor_name arrives at the gate._";

            $r2 = self::sendMessage($conn, $v['resident_phone'], $v['resident_name'], $residentMsg, 'visitor_pass', $visitor_code, $v['estate_id']);
            if ($r2['success'])
                $dispatched = true;
        }

        return $dispatched;
    }

    /**
     * 4. Send Instant Visitor Gate Arrival Alert via WhatsApp to Resident
     */
    public static function sendVisitorArrivalAlertWhatsApp($conn, $visitor_id, $gate_name = 'Main Gate')
    {
        $config = self::getWhatsAppSettings($conn);
        if (!$config['whatsapp_enabled'] || !$config['notify_on_visitor_arrival'])
            return false;

        $v_id = intval($visitor_id);
        $query = "SELECT v.*, u.name as resident_name, u.phone as resident_phone 
                  FROM visitors v 
                  JOIN users u ON v.resident_id = u.id 
                  WHERE v.id = $v_id LIMIT 1";
        $res = $conn->query($query);
        if (!$res || $res->num_rows === 0)
            return false;
        $v = $res->fetch_assoc();

        if (empty($v['resident_phone']))
            return false;

        $visitor_name = $v['name'];
        $visitor_code = $v['visitor_code'];
        $gate = $gate_name ?: ($v['entry_gate'] ?? 'Main Gate');
        $time_now = date('h:i A');
        $estate_name = $config['estate_name'];

        $msg = "🔔 *VISITOR GATE ARRIVAL ALERT*\n" .
            "--------------------------------\n" .
            "🏛️ *Estate:* $estate_name\n\n" .
            "Dear *{$v['resident_name']}*,\n" .
            "Your visitor *$visitor_name* (Pass: *$visitor_code*) has just checked in at *$gate* at *$time_now* and is proceeding to your residence.\n\n" .
            "⚠️ *Security Notice:*\n" .
            "If you were not expecting this visitor, please contact the gate security immediately via your intercom or the estate emergency channel.\n" .
            "--------------------------------";

        $result = self::sendMessage($conn, $v['resident_phone'], $v['resident_name'], $msg, 'visitor_arrival', $visitor_code, $v['estate_id']);
        return $result['success'];
    }

    /**
     * 5. Send Welcome Credentials via WhatsApp
     */
    public static function sendWelcomeCredentialsWhatsApp($conn, $user_id, $plain_password)
    {
        $config = self::getWhatsAppSettings($conn);
        if (!$config['whatsapp_enabled'] || !$config['notify_on_welcome'])
            return false;

        $user_id = intval($user_id);
        $res = $conn->query("SELECT * FROM users WHERE id = $user_id LIMIT 1");
        if (!$res || $res->num_rows === 0)
            return false;
        $user = $res->fetch_assoc();

        if (empty($user['phone']))
            return false;

        $login_url = $config['base_url'] . 'login.php';
        $role_label = ucfirst($user['role'] ?? 'Resident');
        $estate_name = $config['estate_name'];

        $msg = "🏡 *WELCOME TO $estate_name*\n" .
            "--------------------------------\n" .
            "Dear *{$user['name']}*,\n" .
            "Your official estate portal account has been created with access as a *$role_label*.\n\n" .
            "🌐 *Portal Address:*\n$login_url\n\n" .
            "📧 *Login Email:* {$user['email']}\n" .
            "🔑 *Temporary Password:* *$plain_password*\n\n" .
            "🚀 *Sign in directly:*\n$login_url\n\n" .
            "--------------------------------\n" .
            "_For account security, you will be prompted to set a new private password upon your first sign in._";

        $result = self::sendMessage($conn, $user['phone'], $user['name'], $msg, 'welcome', $user['id'], $user['estate_id']);
        return $result['success'];
    }

    /**
     * 6. Send Password Reset OTP Code via WhatsApp
     */
    public static function sendPasswordResetOtpWhatsApp($conn, $toPhone, $toName, $otpCode, $resetLink, $estate_id = null)
    {
        $config = self::getWhatsAppSettings($conn, $estate_id);
        if (!$config['whatsapp_enabled'])
            return false;

        $estate_name = $config['estate_name'];

        $msg = "🔒 *PASSWORD RESET VERIFICATION*\n" .
            "--------------------------------\n" .
            "🏛️ *Estate:* $estate_name\n\n" .
            "Dear *{$toName}*,\n" .
            "A password reset request was initiated for your estate portal account.\n\n" .
            "Your One-Time Verification Code is:\n" .
            "🔢 *$otpCode*\n" .
            "_(Valid for 15 minutes only)_\n\n" .
            "Or reset directly using this secure link:\n$resetLink\n\n" .
            "--------------------------------\n" .
            "_If you did not request this reset, please ignore this message. Your account remains completely secure._";

        $result = self::sendMessage($conn, $toPhone, $toName, $msg, 'password_reset_otp', null, $estate_id);
        return $result['success'];
    }

    /**
     * 7. Send Administrative Password Reset Notification via WhatsApp
     */
    public static function sendAdminPasswordResetWhatsApp($conn, $toPhone, $toName, $tempPassword, $loginUrl, $estate_id = null)
    {
        $config = self::getWhatsAppSettings($conn, $estate_id);
        if (!$config['whatsapp_enabled'])
            return false;

        $estate_name = $config['estate_name'];

        $msg = "🔑 *TEMPORARY PASSWORD ISSUED*\n" .
            "--------------------------------\n" .
            "🏛️ *Estate:* $estate_name\n\n" .
            "Dear *{$toName}*,\n" .
            "Your account access credentials have been reset by Central Administration.\n\n" .
            "🔑 *Temporary Password:* *$tempPassword*\n" .
            "🌐 *Login URL:* $loginUrl\n\n" .
            "⚠️ *Important:* You will be prompted to set your new private password immediately upon logging in.\n" .
            "--------------------------------";

        $result = self::sendMessage($conn, $toPhone, $toName, $msg, 'admin_password_reset', null, $estate_id);
        return $result['success'];
    }

    /**
     * 8. Send Broadcast Message via WhatsApp to multiple users
     */
    public static function sendBroadcastWhatsApp($conn, $subject, $content, $target_role = 'resident', $estate_id = null, $zone_id = null)
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $config = self::getWhatsAppSettings($conn, $estate_id);
        $estate_id = $config['estate_id'];
        $estate_name = $config['estate_name'];

        if (!$config['whatsapp_enabled'])
            return 0;

        $target_role = $conn->real_escape_string($target_role);
        $normalized_role = ($target_role === 'residents' || $target_role === 'tenants') ? 'resident' : $target_role;

        if ($zone_id && intval($zone_id) > 0) {
            $zid = intval($zone_id);
            $sql = "SELECT DISTINCT u.id, u.name, u.phone 
                    FROM users u
                    JOIN residents r ON r.user_id = u.id
                    JOIN flats f ON r.flat_id = f.id
                    JOIN buildings b ON f.building_id = b.id
                    JOIN streets s ON b.street_id = s.id
                    WHERE u.estate_id = $estate_id AND s.zone_id = $zid AND u.phone IS NOT NULL AND u.phone != ''";
            if ($target_role !== 'all') {
                $sql .= " AND u.role = '$normalized_role'";
            }
            $res = $conn->query($sql);
        } else {
            $where = ($target_role === 'all') ? "estate_id = $estate_id" : "estate_id = $estate_id AND role = '$normalized_role'";
            $res = $conn->query("SELECT name, phone FROM users WHERE $where AND phone IS NOT NULL AND phone != ''");
        }

        if (!$res || $res->num_rows === 0)
            return 0;

        $plain_content = strip_tags($content);
        $count = 0;
        $start_time = time();

        while ($u = $res->fetch_assoc()) {
            if ((time() - $start_time) > 40) {
                break;
            }

            $msg = "📢 *ESTATE ANNOUNCEMENT*\n" .
                "--------------------------------\n" .
                "🏛️ *Estate:* $estate_name\n" .
                "📌 *Subject:* *$subject*\n\n" .
                "Dear *{$u['name']}*,\n\n" .
                $plain_content . "\n\n" .
                "--------------------------------\n" .
                "_Official advisory from {$estate_name} Administration._";

            $r = self::sendMessage($conn, $u['phone'], $u['name'], $msg, 'broadcast', null, $estate_id);
            if ($r['success']) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 9. Send Emergency / Panic Alert via WhatsApp
     */
    public static function sendEmergencyAlertWhatsApp($conn, $title, $description, $location, $estate_id = null, $alert_code = null)
    {
        $config = self::getWhatsAppSettings($conn, $estate_id);
        $estate_id = $config['estate_id'];
        $estate_name = $config['estate_name'];

        if (!$config['whatsapp_enabled'] || !$config['notify_on_emergency'])
            return 0;

        $alert_code = $alert_code ?: ('EMG-' . strtoupper(substr(md5(uniqid()), 0, 6)));
        $time_now = date('h:i A, M d, Y');

        $msg = "🚨 *ESTATE EMERGENCY ALERT* 🚨\n" .
            "--------------------------------\n" .
            "🏛️ *Estate:* $estate_name\n" .
            "📌 *Incident Code:* *$alert_code*\n" .
            "⚠️ *Category:* *$title*\n" .
            "📍 *Location:* $location\n" .
            "🕒 *Time:* $time_now\n\n" .
            "📝 *Details:* $description\n\n" .
            "--------------------------------\n" .
            "⚡ *Security team and quick response guards have been mobilized.*";

        // Query guards, emergency contacts, and admins with valid phones
        $recipients = [];

        // 1. Configured emergency contacts
        $c_res = $conn->query("SELECT label as name, phone_number as phone FROM estate_emergency_contacts WHERE estate_id = $estate_id AND is_active = 1");
        if ($c_res) {
            while ($row = $c_res->fetch_assoc()) {
                if (!empty($row['phone']))
                    $recipients[] = $row;
            }
        }

        // 2. Active security staff
        $s_res = $conn->query("SELECT u.name, u.phone FROM users u WHERE u.estate_id = $estate_id AND u.role IN ('admin', 'superadmin', 'security', 'guard') AND u.phone IS NOT NULL AND u.phone != ''");
        if ($s_res) {
            while ($row = $s_res->fetch_assoc()) {
                $recipients[] = $row;
            }
        }

        $sent = 0;
        $seen = [];
        foreach ($recipients as $rec) {
            $formatted = self::formatPhoneNumber($rec['phone']);
            if (empty($formatted) || isset($seen[$formatted]))
                continue;
            $seen[$formatted] = true;

            $r = self::sendMessage($conn, $formatted, $rec['name'], $msg, 'emergency', $alert_code, $estate_id);
            if ($r['success'])
                $sent++;
        }

        return $sent;
    }

    /**
     * 10. Live Kapso WhatsApp Diagnostic Test
     */
    public static function testKapsoConnection($conn, $estate_id, $testRecipientPhone)
    {
        $config = self::getWhatsAppSettings($conn, $estate_id);
        $estate_name = $config['estate_name'];
        $now_str = date('Y-m-d H:i:s');
        $phone_id = $config['kapso_phone_number_id'] ?: 'Not Specified';
        $sender = $config['kapso_sender_phone'] ?? '+234 903 647 7098';
        $cleanSender = preg_replace('/[^0-9]/', '', $sender);

        // 1. Check if estate_notice template is approved
        $tplCheck = self::checkTemplateStatus($conn, 'estate_notice', $estate_id);
        if (!empty($tplCheck['approved'])) {
            $tplParams = [
                'Administrator',
                "Test verification completed successfully on $now_str",
                $estate_name
            ];
            $tRes = self::sendTemplateMessage($conn, $testRecipientPhone, 'Administrator', 'estate_notice', 'en_US', $tplParams, 'TEST-DIAGNOSTIC', $estate_id);
            if ($tRes['success']) {
                return ['success' => true, 'response_id' => $tRes['response_id'] ?? null, 'method' => 'template'];
            }
        }

        // 2. Otherwise send standard text message (works once user has messaged the number)
        $testMsg = "🧪 *KAPSO WHATSAPP DIAGNOSTIC TEST*\n" .
            "--------------------------------\n" .
            "🏛️ *Estate:* $estate_name\n" .
            "✅ *Status:* Direct Dispatch Connected Successfully!\n\n" .
            "Congratulations! Your estate WhatsApp delivery system is fully configured with Kapso and verified.\n\n" .
            "📱 *Phone Number ID:* $phone_id\n" .
            "🕒 *Timestamp:* $now_str\n\n" .
            "--------------------------------\n" .
            "_Automated billing invoices, payment receipts, visitor gate passes, and arrival alerts will now be delivered instantly to WhatsApp!_";

        $res = self::sendMessage($conn, $testRecipientPhone, "Administrator", $testMsg, 'test_diagnostic', null, $estate_id, null, 'text', true);
        if (!$res['success'] && (stripos($res['error'] ?? '', '24-hour') !== false)) {
            $res['error'] = "WhatsApp 24-Hour Policy: Since this recipient has not messaged your estate number ($sender) in the past 24 hours, WhatsApp requires you to open the session first. Please send 'Hi' to $sender (https://wa.me/{$cleanSender}?text=Hi) from the test phone, then click Send Test Message again.";
            $res['action_url'] = "https://wa.me/{$cleanSender}?text=Hi";
            $res['sender_phone'] = $sender;
        }
        return $res;
    }

    /**
     * 11. Send Official Artisan Clearance & Gate Pass via WhatsApp (to Artisan & Host Resident)
     */
    public static function sendArtisanPassWhatsApp($conn, $artisan_data_or_id)
    {
        $config = self::getWhatsAppSettings($conn);
        if (!$config['whatsapp_enabled'] || !$config['notify_on_artisan_pass'])
            return false;

        $pass_data = [];
        if (is_numeric($artisan_data_or_id)) {
            $id = intval($artisan_data_or_id);
            $vq = $conn->query("SELECT v.*, u.name as resident_name, u.phone as resident_phone,
                                       f.number as flat_number, b.name as building_name, s.name as street_name
                                FROM visitors v
                                LEFT JOIN users u ON v.resident_id = u.id
                                LEFT JOIN flats f ON v.flat_id = f.id
                                LEFT JOIN buildings b ON f.building_id = b.id
                                LEFT JOIN streets s ON b.street_id = s.id
                                WHERE v.id = $id LIMIT 1");
            if ($vq && $vq->num_rows > 0) {
                $v = $vq->fetch_assoc();
                $pass_data = [
                    'pass_id' => $v['id'],
                    'pass_code' => $v['visitor_code'],
                    'artisan_name' => $v['name'],
                    'artisan_phone' => $v['phone'],
                    'trade' => $v['purpose'],
                    'scheduled_date' => $v['expected_arrival'],
                    'resident_name' => $v['resident_name'],
                    'resident_phone' => $v['resident_phone'],
                    'unit_str' => "Flat " . ($v['flat_number'] ?? 'Unit') . ", " . ($v['building_name'] ?? '') . " (" . ($v['street_name'] ?? 'Estate') . ")",
                    'estate_id' => $v['estate_id']
                ];
            }
        } elseif (is_array($artisan_data_or_id)) {
            $pass_data = $artisan_data_or_id;
        }

        if (empty($pass_data))
            return false;

        $estate_name = $config['estate_name'];
        $pass_code = $pass_data['pass_code'] ?? 'ART-PASS';
        $pass_url = $config['base_url'] . 'gate_pass.php?code=' . urlencode($pass_code);
        $arrival = !empty($pass_data['scheduled_date']) ? date('M d, Y h:i A', strtotime($pass_data['scheduled_date'])) : 'Valid for Scheduled Date';
        $artisan_name = $pass_data['artisan_name'] ?? 'Artisan';
        $resident_name = $pass_data['resident_name'] ?? 'Resident Host';
        $unit_str = $pass_data['unit_str'] ?? 'Resident Unit';
        $trade = $pass_data['trade'] ?? 'Maintenance & Technical Services';
        $dispatched = false;

        // 1. Dispatch to Artisan's WhatsApp
        if (!empty($pass_data['artisan_phone'])) {
            $msg = "🛠️ *ESTATE ARTISAN CLEARANCE PASS*\n" .
                "--------------------------------\n" .
                "🏛️ *Estate:* $estate_name\n\n" .
                "Hello *$artisan_name*,\n" .
                "You have been authorized for gated entry to carry out maintenance / technical work. Present this clearance code at the security checkpoint upon arrival:\n\n" .
                "🔐 *CLEARANCE CODE:* *$pass_code*\n\n" .
                "👤 *Client / Host:* $resident_name\n" .
                "📍 *Destination:* $unit_str\n" .
                "🕒 *Scheduled Time:* $arrival\n" .
                "🔧 *Trade / Purpose:* $trade\n\n" .
                "📱 *Open Digital Pass:*\n$pass_url\n\n" .
                "--------------------------------\n" .
                "⚠️ *Security Compliance:*\n" .
                "• Valid physical photo ID is mandatory at checkpoint.\n" .
                "• Heavy power tools / equipment must be declared at the gate.\n" .
                "• Permitted work hours: Mon - Sat, 08:00 AM - 06:00 PM.";

            $r1 = self::sendMessage($conn, $pass_data['artisan_phone'], $artisan_name, $msg, 'artisan_pass', $pass_code, $pass_data['estate_id'] ?? null);
            if ($r1['success'])
                $dispatched = true;
        }

        // 2. Dispatch Confirmation to Resident Host WhatsApp
        if (!empty($pass_data['resident_phone'])) {
            $hostMsg = "🛠️ *ARTISAN PASS ISSUED*\n" .
                "--------------------------------\n" .
                "🏛️ *Estate:* $estate_name\n\n" .
                "Dear *$resident_name*,\n" .
                "An authorized artisan gate clearance pass has been issued for *$artisan_name* ($trade).\n\n" .
                "🔐 *Access Code:* *$pass_code*\n" .
                "🕒 *Scheduled:* $arrival\n" .
                "📍 *Destination:* $unit_str\n\n" .
                "📱 *Share / View Digital Pass:*\n$pass_url\n\n" .
                "--------------------------------\n" .
                "_You will receive an automatic security arrival alert the moment $artisan_name checks in at the gate._";

            $r2 = self::sendMessage($conn, $pass_data['resident_phone'], $resident_name, $hostMsg, 'artisan_pass', $pass_code, $pass_data['estate_id'] ?? null);
            if ($r2['success'])
                $dispatched = true;
        }

        return $dispatched;
    }

    /**
     * 12. Send Official Penalty / Infraction Assessment via WhatsApp
     */
    public static function sendPenaltyNoticeWhatsApp($conn, $penalty_id_or_data)
    {
        $config = self::getWhatsAppSettings($conn);
        if (!$config['whatsapp_enabled'] || !$config['notify_on_penalty'])
            return false;

        $p = [];
        if (is_numeric($penalty_id_or_data)) {
            $pid = intval($penalty_id_or_data);
            $q = $conn->query("SELECT * FROM estate_penalties WHERE id = $pid LIMIT 1");
            if ($q && $q->num_rows > 0) {
                $p = $q->fetch_assoc();
            }
        } elseif (is_array($penalty_id_or_data)) {
            $p = $penalty_id_or_data;
        }

        if (empty($p) || empty($p['recipient_phone']))
            return false;

        $estate_name = $config['estate_name'];
        $curr = $config['currency_symbol'];
        $fine_fmt = $curr . number_format($p['fine_amount'] ?? 0, 2);
        $pref = $p['penalty_ref'] ?: ('PEN-' . $p['id']);
        $due_date = !empty($p['due_date']) ? date('M d, Y', strtotime($p['due_date'])) : 'Within 7 Business Days';
        $offence_date = !empty($p['offence_date']) ? date('M d, Y h:i A', strtotime($p['offence_date'])) : date('M d, Y');
        $pay_url = $config['base_url'] . 'pay_invoice.php?penalty=' . urlencode($pref);
        $details = strip_tags($p['violation_details'] ?? '');

        $msg = "⚖️ *OFFICIAL NOTICE OF INFRACTION*\n" .
            "--------------------------------\n" .
            "🏛️ *Estate:* $estate_name\n" .
            "🔢 *Notice Reference:* *$pref*\n\n" .
            "Dear *{$p['recipient_name']}*,\n" .
            "An official regulatory infraction has been cited and recorded by Estate Compliance Directorate:\n\n" .
            "⚠️ *Violation:* {$p['violation_title']} ({$p['violation_code']})\n" .
            "🕒 *Date & Time:* $offence_date\n" .
            "📍 *Location:* " . ($p['location'] ?: 'Estate Premises') . "\n" .
            "🏠 *Unit / Property:* " . ($p['property_or_unit'] ?: 'N/A') . "\n\n" .
            "📝 *Citation Brief:* $details\n\n" .
            "💰 *Assessed Penalty:* *$fine_fmt*\n" .
            "📅 *Settlement Due Date:* $due_date\n\n" .
            "💳 *Remit Fine Online:*\n$pay_url\n\n" .
            "--------------------------------\n" .
            "_Formal appeals must be submitted in writing to the Compliance Committee within 5 business days._";

        $result = self::sendMessage($conn, $p['recipient_phone'], $p['recipient_name'], $msg, 'penalty', $pref, $p['estate_id'] ?? null);
        if ($result['success'] && !empty($p['id'])) {
            $conn->query("UPDATE estate_penalties SET whatsapp_dispatched = 1 WHERE id = " . intval($p['id']));
        }
        return $result['success'];
    }

    /**
     * 13. Send General Notification / In-App Notice via WhatsApp
     */
    public static function sendNotificationWhatsApp($conn, $toPhone, $toName, $title, $message, $refId = null, $estate_id = null)
    {
        $config = self::getWhatsAppSettings($conn, $estate_id);
        if (!$config['whatsapp_enabled'])
            return false;

        $estate_name = $config['estate_name'];
        $clean_msg = strip_tags($message);

        $body = "🔔 *ESTATE NOTIFICATION*\n" .
            "--------------------------------\n" .
            "🏛️ *Estate:* $estate_name\n" .
            "📌 *Subject:* *$title*\n\n" .
            "Dear *{$toName}*,\n\n" .
            $clean_msg . "\n\n" .
            "--------------------------------\n" .
            "_Official notification from {$estate_name} Administration._";

        $result = self::sendMessage($conn, $toPhone, $toName, $body, 'notification', $refId, $estate_id);
        return $result['success'];
    }
}
