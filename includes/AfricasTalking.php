<?php
// includes/AfricasTalking.php - Africa's Talking USSD & SMS Gateway Integration Service
require_once __DIR__ . '/../config.php';

class EstateAfricasTalking
{
    private static $initialized = false;
    
    // Africa's Talking API Base Endpoints
    const LIVE_SMS_ENDPOINT    = 'https://api.africastalking.com/version1/messaging';
    const SANDBOX_SMS_ENDPOINT = 'https://api.sandbox.africastalking.com/version1/messaging';

    /**
     * Ensure database tables for USSD sessions, USSD logs, and Offline sync queue exist
     */
    public static function ensureDatabaseTables($conn)
    {
        if (self::$initialized) return;
        self::$initialized = true;

        if (!$conn || !($conn instanceof mysqli)) return;

        // 1. USSD Sessions table (stores temporary session states & multi-step USSD inputs)
        $conn->query("CREATE TABLE IF NOT EXISTS ussd_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            estate_id INT DEFAULT 1,
            session_id VARCHAR(100) NOT NULL,
            phone_number VARCHAR(32) NOT NULL,
            service_code VARCHAR(32) NULL,
            user_id INT NULL,
            user_role VARCHAR(32) NULL,
            current_step VARCHAR(64) NOT NULL DEFAULT 'menu',
            temp_data TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY (session_id),
            INDEX (phone_number),
            INDEX (estate_id),
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 2. USSD Logs table (stores history of USSD sessions, inputs, outputs, execution)
        $conn->query("CREATE TABLE IF NOT EXISTS ussd_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            estate_id INT DEFAULT 1,
            session_id VARCHAR(100) NOT NULL,
            phone_number VARCHAR(32) NOT NULL,
            service_code VARCHAR(32) NULL,
            network_code VARCHAR(32) NULL,
            user_input TEXT NULL,
            response_type ENUM('CON', 'END') NOT NULL DEFAULT 'CON',
            response_text TEXT NOT NULL,
            status ENUM('success', 'failed', 'timeout') NOT NULL DEFAULT 'success',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (estate_id),
            INDEX (phone_number),
            INDEX (session_id),
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 3. Offline Sync Logs table (stores records synced from offline gate terminals)
        $conn->query("CREATE TABLE IF NOT EXISTS offline_sync_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            estate_id INT DEFAULT 1,
            terminal_id VARCHAR(64) NOT NULL DEFAULT 'gate_terminal_1',
            guard_user_id INT NULL,
            action_type VARCHAR(64) NOT NULL,
            visitor_id INT NULL,
            visitor_code VARCHAR(32) NULL,
            offline_timestamp DATETIME NOT NULL,
            synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            sync_status ENUM('synced', 'conflict', 'ignored') NOT NULL DEFAULT 'synced',
            details TEXT NULL,
            INDEX (estate_id),
            INDEX (visitor_code),
            INDEX (synced_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 4. Default System Settings for Africa's Talking
        $estate_id = function_exists('get_estate_id') ? get_estate_id() : 1;
        $default_settings = [
            'at_ussd_enabled' => '1',
            'at_environment' => 'sandbox', // 'sandbox' or 'live'
            'at_username' => 'sandbox',
            'at_api_key' => '',
            'at_ussd_code' => '*384*777#',
            'at_sms_sender_id' => '',
            'at_sms_notifications_enabled' => '1',
            'at_notify_pass_on_ussd' => '1',
            'at_notify_emergency_on_ussd' => '1'
        ];

        foreach ($default_settings as $key => $val) {
            $chk = $conn->query("SELECT 1 FROM system_settings WHERE estate_id = $estate_id AND setting_key = '$key'");
            if (!$chk || $chk->num_rows === 0) {
                $k_esc = $conn->real_escape_string($key);
                $v_esc = $conn->real_escape_string($val);
                $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES ($estate_id, '$k_esc', '$v_esc')");
            }
        }
    }

    /**
     * Get Africa's Talking Configuration for given or active estate
     */
    public static function getConfig($conn, $estate_id = null)
    {
        if (!$estate_id && function_exists('get_estate_id')) {
            $estate_id = get_estate_id();
        }
        $estate_id = intval($estate_id ?: 1);

        $config = [
            'estate_id' => $estate_id,
            'enabled' => false,
            'environment' => 'sandbox',
            'username' => 'sandbox',
            'api_key' => '',
            'ussd_code' => '*384*777#',
            'sms_sender_id' => '',
            'sms_enabled' => true,
            'notify_pass_on_ussd' => true,
            'notify_emergency_on_ussd' => true
        ];

        $keys = [
            'at_ussd_enabled', 'at_environment', 'at_username', 'at_api_key', 
            'at_ussd_code', 'at_sms_sender_id', 'at_sms_notifications_enabled',
            'at_notify_pass_on_ussd', 'at_notify_emergency_on_ussd'
        ];
        $in_clause = "'" . implode("','", $keys) . "'";
        $res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE estate_id = $estate_id AND setting_key IN ($in_clause)");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $k = $row['setting_key'];
                $v = $row['setting_value'];
                if ($k === 'at_ussd_enabled') $config['enabled'] = ($v === '1');
                if ($k === 'at_environment') $config['environment'] = ($v === 'live') ? 'live' : 'sandbox';
                if ($k === 'at_username') $config['username'] = !empty($v) ? $v : 'sandbox';
                if ($k === 'at_api_key') $config['api_key'] = $v;
                if ($k === 'at_ussd_code') $config['ussd_code'] = !empty($v) ? $v : '*384*777#';
                if ($k === 'at_sms_sender_id') $config['sms_sender_id'] = $v;
                if ($k === 'at_sms_notifications_enabled') $config['sms_enabled'] = ($v === '1');
                if ($k === 'at_notify_pass_on_ussd') $config['notify_pass_on_ussd'] = ($v === '1');
                if ($k === 'at_notify_emergency_on_ussd') $config['notify_emergency_on_ussd'] = ($v === '1');
            }
        }

        return $config;
    }

    /**
     * Normalize international phone numbers for telecom gateway lookup
     * Handles 080..., +234..., 234...
     */
    public static function normalizePhone($phone, $defaultCountryCode = '234')
    {
        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (empty($clean)) return '';

        // If local Nigerian number starting with 0
        if (strpos($clean, '0') === 0 && strlen($clean) === 11) {
            $clean = $defaultCountryCode . substr($clean, 1);
        }
        // If without + but has country code
        if (strpos($clean, $defaultCountryCode) === 0) {
            return '+' . $clean;
        }
        // If 10 digits (some international formats)
        if (strlen($clean) === 10) {
            return '+' . $defaultCountryCode . $clean;
        }

        return '+' . $clean;
    }

    /**
     * Send Outbound SMS via Africa's Talking REST API
     */
    public static function sendSMS($conn, $toPhone, $message, $estate_id = null)
    {
        self::ensureDatabaseTables($conn);
        $config = self::getConfig($conn, $estate_id);

        if (!$config['sms_enabled'] || empty($config['api_key'])) {
            return [
                'success' => false,
                'error' => 'Africa\'s Talking SMS is disabled or API Key is missing.'
            ];
        }

        $formattedTo = self::normalizePhone($toPhone);
        if (empty($formattedTo)) {
            return ['success' => false, 'error' => 'Invalid phone number format'];
        }

        $endpoint = ($config['environment'] === 'live') ? self::LIVE_SMS_ENDPOINT : self::SANDBOX_SMS_ENDPOINT;
        $username = ($config['environment'] === 'live') ? $config['username'] : 'sandbox';

        $postFields = [
            'username' => $username,
            'to' => $formattedTo,
            'message' => $message
        ];

        if (!empty($config['sms_sender_id']) && $config['environment'] === 'live') {
            $postFields['from'] = $config['sms_sender_id'];
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'apiKey: ' . $config['api_key']
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'cURL Error: ' . $curlError];
        }

        $decoded = json_decode($response, true);
        $isSuccess = ($httpCode >= 200 && $httpCode < 300);

        return [
            'success' => $isSuccess,
            'http_code' => $httpCode,
            'response' => $decoded ?: $response
        ];
    }

    /**
     * Log a USSD event to ussd_logs table
     */
    public static function logUSSD($conn, $estate_id, $sessionId, $phoneNumber, $serviceCode, $networkCode, $userInput, $responseType, $responseText, $status = 'success')
    {
        self::ensureDatabaseTables($conn);
        $e_id = intval($estate_id ?: 1);
        $s_id = $conn->real_escape_string($sessionId);
        $p_no = $conn->real_escape_string($phoneNumber);
        $sc = $conn->real_escape_string($serviceCode ?? '');
        $nc = $conn->real_escape_string($networkCode ?? '');
        $inp = $conn->real_escape_string($userInput ?? '');
        $r_type = in_array($responseType, ['CON', 'END']) ? $responseType : 'CON';
        $r_txt = $conn->real_escape_string($responseText);
        $st = $conn->real_escape_string($status);

        $sql = "INSERT INTO ussd_logs (estate_id, session_id, phone_number, service_code, network_code, user_input, response_type, response_text, status) 
                VALUES ($e_id, '$s_id', '$p_no', '$sc', '$nc', '$inp', '$r_type', '$r_txt', '$st')";
        return $conn->query($sql);
    }
}
