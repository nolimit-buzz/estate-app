<?php
/**
 * Paystack API Wrapper for EstateAdmin
 */
class Paystack {
    private $secret_key;
    private $public_key;
    private $base_url = "https://api.paystack.co";

    public function __construct($conn) {
        // Fetch keys from database
        $estate_id = get_estate_id();
        $settings = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('paystack_secret_key', 'paystack_public_key') AND estate_id = $estate_id");
        while ($row = $settings->fetch_assoc()) {
            if ($row['setting_key'] == 'paystack_secret_key') $this->secret_key = $row['setting_value'];
            if ($row['setting_key'] == 'paystack_public_key') $this->public_key = $row['setting_value'];
        }
    }

    /**
     * Verify a transaction reference
     */
    public function verifyTransaction($reference) {
        if (empty($this->secret_key)) {
            throw new Exception("Paystack Secret Key is not configured.");
        }

        $url = $this->base_url . "/transaction/verify/" . rawurlencode($reference);
        return $this->sendRawRequest($url);
    }

    /**
     * Send GET request returning raw decoded API result
     */
    public function sendRawRequest($url) {
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer " . $this->secret_key,
                "Cache-Control: no-cache",
            ),
            CURLOPT_SSL_VERIFYPEER => false // For local dev environments
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            throw new Exception("cURL Error: " . $err);
        }

        $result = json_decode($response, true);
        if (!$result || !isset($result['status']) || !$result['status']) {
            throw new Exception("Paystack Error: " . ($result['message'] ?? 'Unknown error'));
        }

        return $result;
    }

    /**
     * Send GET request to Paystack (returns data property)
     */
    private function sendRequest($url) {
        $res = $this->sendRawRequest($url);
        return $res['data'] ?? $res;
    }

    public function getPublicKey() {
        return $this->public_key;
    }
}
