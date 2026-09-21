<?php
/**
 * Paystack API Wrapper for EstateAdmin
 * Supports Transactions, Dedicated Virtual Accounts (DVA / Dedicated NUBAN), and Subaccounts
 */
class Paystack {
    private $secret_key;
    private $public_key;
    private $base_url = "https://api.paystack.co";
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
        $estate_id = get_estate_id();
        $settings = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('paystack_secret_key', 'paystack_public_key') AND estate_id = $estate_id");
        if ($settings) {
            while ($row = $settings->fetch_assoc()) {
                if ($row['setting_key'] == 'paystack_secret_key') $this->secret_key = $row['setting_value'];
                if ($row['setting_key'] == 'paystack_public_key') $this->public_key = $row['setting_value'];
            }
        }
    }

    public function getPublicKey() {
        return $this->public_key;
    }

    public function getSecretKey() {
        return $this->secret_key;
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
     * Send GET request to Paystack endpoint
     */
    public function getRequest($endpoint) {
        return $this->sendRawRequest($this->base_url . $endpoint);
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
     * Send POST request returning decoded API result
     */
    public function postRequest($endpoint, $payload = []) {
        if (empty($this->secret_key)) {
            throw new Exception("Paystack Secret Key is not configured.");
        }

        $url = $this->base_url . $endpoint;
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => array(
                "Authorization: Bearer " . $this->secret_key,
                "Content-Type: application/json",
                "Cache-Control: no-cache",
            ),
            CURLOPT_SSL_VERIFYPEER => false
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            throw new Exception("cURL Error: " . $err);
        }

        $result = json_decode($response, true);
        if (!$result || !isset($result['status']) || !$result['status']) {
            throw new Exception("Paystack Error: " . ($result['message'] ?? 'API error'));
        }

        return $result;
    }

    /**
     * Create or Fetch Paystack Customer
     */
    public function createCustomer($email, $first_name, $last_name, $phone = '') {
        $payload = [
            'email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name
        ];
        if (!empty($phone)) {
            $payload['phone'] = $phone;
        }

        return $this->postRequest('/customer', $payload);
    }

    /**
     * Create Dedicated Virtual Account (DVA) for Customer
     */
    public function createDedicatedAccount($customer_code, $preferred_bank = 'wema-bank') {
        $payload = [
            'customer' => $customer_code,
            'preferred_bank' => $preferred_bank
        ];

        return $this->postRequest('/dedicated_account', $payload);
    }

    /**
     * Get list of supported Nigerian banks from Paystack
     */
    public function getBanks() {
        try {
            $res = $this->getRequest('/bank?country=nigeria&currency=NGN');
            if (isset($res['data']) && is_array($res['data']) && count($res['data']) > 0) {
                return $res['data'];
            }
        } catch (Exception $e) {
            // Fallback list of top banks
        }
        return [
            ['name' => 'Access Bank', 'code' => '044', 'slug' => 'access-bank'],
            ['name' => 'Guaranty Trust Bank (GTBank)', 'code' => '058', 'slug' => 'guaranty-trust-bank'],
            ['name' => 'Zenith Bank', 'code' => '057', 'slug' => 'zenith-bank'],
            ['name' => 'First Bank of Nigeria', 'code' => '011', 'slug' => 'first-bank-of-nigeria'],
            ['name' => 'United Bank For Africa (UBA)', 'code' => '033', 'slug' => 'united-bank-for-africa'],
            ['name' => 'Wema Bank', 'code' => '035', 'slug' => 'wema-bank'],
            ['name' => 'Fidelity Bank', 'code' => '070', 'slug' => 'fidelity-bank'],
            ['name' => 'First City Monument Bank (FCMB)', 'code' => '214', 'slug' => 'first-city-monument-bank'],
            ['name' => 'Stanbic IBTC Bank', 'code' => '221', 'slug' => 'stanbic-ibtc-bank'],
            ['name' => 'Sterling Bank', 'code' => '232', 'slug' => 'sterling-bank'],
            ['name' => 'Polaris Bank', 'code' => '076', 'slug' => 'polaris-bank'],
            ['name' => 'Union Bank of Nigeria', 'code' => '032', 'slug' => 'union-bank-of-nigeria'],
            ['name' => 'Providus Bank', 'code' => '101', 'slug' => 'providus-bank'],
            ['name' => 'Kuda Bank', 'code' => '090267', 'slug' => 'kuda-bank'],
            ['name' => 'OPay Digital Services', 'code' => '100004', 'slug' => 'paycom'],
            ['name' => 'PalmPay', 'code' => '100033', 'slug' => 'palmpay'],
            ['name' => 'Moniepoint MFB', 'code' => '090405', 'slug' => 'moniepoint-mfb-ng'],
            ['name' => 'VFD Microfinance Bank', 'code' => '566', 'slug' => 'vfd'],
            ['name' => 'Jaiz Bank', 'code' => '301', 'slug' => 'jaiz-bank'],
            ['name' => 'Lotus Bank', 'code' => '303', 'slug' => 'lotus-bank'],
            ['name' => 'TAJ Bank', 'code' => '302', 'slug' => 'taj-bank']
        ];
    }

    /**
     * Resolve 10-digit NUBAN account name against NIBSS via Paystack API
     */
    public function resolveAccountNumber($account_number, $bank_code) {
        return $this->getRequest("/bank/resolve?account_number=" . rawurlencode($account_number) . "&bank_code=" . rawurlencode($bank_code));
    }

    /**
     * Create Subaccount for automatic split/remittance directly on Paystack
     */
    public function createSubaccount($business_name, $settlement_bank, $account_number, $percentage_charge = 0, $description = '', $primary_contact_email = '', $primary_contact_phone = '') {
        $payload = [
            'business_name' => $business_name,
            'settlement_bank' => $settlement_bank,
            'account_number' => $account_number,
            'percentage_charge' => floatval($percentage_charge)
        ];
        if (!empty($description)) $payload['description'] = $description;
        if (!empty($primary_contact_email)) $payload['primary_contact_email'] = $primary_contact_email;
        if (!empty($primary_contact_phone)) $payload['primary_contact_phone'] = $primary_contact_phone;

        return $this->postRequest('/subaccount', $payload);
    }

    /**
     * Generate Resilient Fallback Virtual Account for Dev / Test / Offline Mode
     */
    public static function generateMockVirtualAccount($conn, $zone_name, $zone_code, $estate_name = 'Estate') {
        // Generate a 10-digit NUBAN starting with 99 (standard virtual account prefix in Nigeria)
        do {
            $random_digits = str_pad(mt_rand(10000000, 99999999), 8, '0', STR_PAD_LEFT);
            $nuban = '99' . $random_digits;
            $chk = $conn->query("SELECT id FROM zones WHERE paystack_account_number = '$nuban' LIMIT 1");
        } while ($chk && $chk->num_rows > 0);

        $clean_zone = preg_replace('/[^a-zA-Z0-9]/', '', $zone_code);
        return [
            'bank_name' => 'Wema Bank (Paystack DVA)',
            'bank_code' => '035',
            'account_number' => $nuban,
            'account_name' => $zone_name . ' / ' . $estate_name,
            'customer_code' => 'CUS_ZN_' . strtolower($clean_zone) . '_' . substr($nuban, -4),
            'dva_id' => 'DVA_' . mt_rand(100000, 999999),
            'subaccount_code' => 'SUB_' . strtolower($clean_zone) . '_' . substr($nuban, -4),
            'mode' => 'mock'
        ];
    }

    /**
     * High-level Coordinator: Provision and assign a Paystack Subaccount / Dedicated Account to a Zone.
     * If settlement bank & account provided, creates live Subaccount on Paystack.
     * Also registers Customer on Paystack and attempts DVA if enabled.
     */
    public static function provisionZoneVirtualAccount($conn, $zone_id, $estate_id, $zone_name, $zone_email, $zone_phone, $zone_code, $settlement_bank = '', $settlement_account = '', $settlement_bank_name = '') {
        $paystack = new self($conn);
        
        // Fetch Estate Name for account title
        $e_res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'estate_name' AND estate_id = $estate_id LIMIT 1");
        $estate_name = ($e_res && $e_res->num_rows > 0) ? $e_res->fetch_assoc()['setting_value'] : 'Estate Management';

        $account_data = null;
        $is_live = false;
        $customer_code = '';
        $subaccount_code = '';
        $live_subaccount_created = false;
        $api_error_notice = '';

        // If not provided in args, check if zone already has settlement bank details stored
        if (empty($settlement_bank) || empty($settlement_account)) {
            $z_existing = $conn->query("SELECT paystack_bank_code, paystack_account_number, paystack_bank_name FROM zones WHERE id = $zone_id LIMIT 1");
            if ($z_existing && $z_existing->num_rows > 0) {
                $zr = $z_existing->fetch_assoc();
                if (empty($settlement_bank) && !empty($zr['paystack_bank_code'])) {
                    $settlement_bank = $zr['paystack_bank_code'];
                }
                if (empty($settlement_account) && !empty($zr['paystack_account_number']) && !str_starts_with($zr['paystack_account_number'], '99')) {
                    $settlement_account = $zr['paystack_account_number'];
                }
                if (empty($settlement_bank_name) && !empty($zr['paystack_bank_name'])) {
                    $settlement_bank_name = $zr['paystack_bank_name'];
                }
            }
        }

        // Try Paystack API if secret key configured
        if (!empty($paystack->getSecretKey())) {
            $contact_email = !empty($zone_email) ? $zone_email : ('zone_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $zone_code)) . '@estate.com');

            // 1. Create/Ensure Customer on Paystack
            try {
                $customer_res = $paystack->createCustomer($contact_email, $zone_name, 'Zone', $zone_phone);
                if (isset($customer_res['data']['customer_code'])) {
                    $customer_code = $customer_res['data']['customer_code'];
                }
            } catch (Exception $e) {
                error_log("Paystack createCustomer Notice: " . $e->getMessage());
            }

            // 2. Create Live Subaccount on Paystack if settlement bank & account provided
            if (!empty($settlement_bank) && !empty($settlement_account)) {
                try {
                    $sub_res = $paystack->createSubaccount(
                        $zone_name . ' Settlement',
                        $settlement_bank,
                        $settlement_account,
                        0,
                        $zone_name . ' In-Estate Collection Subaccount',
                        $contact_email,
                        $zone_phone
                    );

                    if (isset($sub_res['data']['subaccount_code'])) {
                        $sub = $sub_res['data'];
                        $subaccount_code = $sub['subaccount_code'];
                        $account_data = [
                            'bank_name' => $sub['settlement_bank'] ?? ($settlement_bank_name ?: 'Settlement Bank'),
                            'bank_code' => $settlement_bank,
                            'account_number' => $sub['account_number'] ?? $settlement_account,
                            'account_name' => $sub['account_name'] ?? ($zone_name . ' Settlement'),
                            'customer_code' => $customer_code,
                            'dva_id' => strval($sub['id'] ?? ''),
                            'subaccount_code' => $subaccount_code,
                            'mode' => 'live_subaccount'
                        ];
                        $is_live = true;
                        $live_subaccount_created = true;
                    }
                } catch (Exception $sub_ex) {
                    $api_error_notice = $sub_ex->getMessage();
                    error_log("Paystack Subaccount Error for Zone #$zone_id: " . $sub_ex->getMessage());
                }
            }

            // 3. Attempt Dedicated Virtual Account (DVA) if customer code exists and no subaccount yet
            if (!$live_subaccount_created && !empty($customer_code)) {
                try {
                    $dva_res = $paystack->createDedicatedAccount($customer_code, 'wema-bank');
                    if (isset($dva_res['data']) && !empty($dva_res['data']['account_number'])) {
                        $d = $dva_res['data'];
                        $account_data = [
                            'bank_name' => $d['bank']['name'] ?? 'Wema Bank',
                            'bank_code' => '035',
                            'account_number' => $d['account_number'],
                            'account_name' => $d['account_name'] ?? ($zone_name . ' / ' . $estate_name),
                            'customer_code' => $customer_code,
                            'dva_id' => strval($d['id'] ?? ''),
                            'subaccount_code' => 'SUB_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $zone_code)),
                            'mode' => 'live_dva'
                        ];
                        $is_live = true;
                    }
                } catch (Exception $dva_ex) {
                    // Dedicated NUBAN not active on standard test account
                    error_log("Paystack DVA Notice for Zone #$zone_id: " . $dva_ex->getMessage());
                }
            }
        }

        // 4. If no live subaccount or DVA was established, use resilient generator
        if (!$account_data) {
            $account_data = self::generateMockVirtualAccount($conn, $zone_name, $zone_code, $estate_name);
            if (!empty($customer_code)) {
                $account_data['customer_code'] = $customer_code;
            }
            if (!empty($settlement_bank)) {
                $account_data['bank_code'] = $settlement_bank;
            }
            if (!empty($settlement_bank_name)) {
                $account_data['bank_name'] = $settlement_bank_name;
            }
            if (!empty($settlement_account)) {
                $account_data['account_number'] = $settlement_account;
            }
        }

        // Attach any API error notice for UI feedback
        $account_data['api_error'] = $api_error_notice;
        $account_data['is_live'] = $is_live;

        // 5. Update Zone record in database
        $b_code = $conn->real_escape_string($account_data['bank_code'] ?? '035');
        $b_name = $conn->real_escape_string($account_data['bank_name'] ?? '');
        $a_num = $conn->real_escape_string($account_data['account_number'] ?? '');
        $a_name = $conn->real_escape_string($account_data['account_name'] ?? '');
        $c_code = $conn->real_escape_string($account_data['customer_code'] ?? '');
        $d_id = $conn->real_escape_string($account_data['dva_id'] ?? '');
        $s_code = $conn->real_escape_string($account_data['subaccount_code'] ?? '');

        $sql = "UPDATE zones SET 
                    paystack_bank_code = '$b_code',
                    paystack_bank_name = '$b_name',
                    paystack_account_number = '$a_num',
                    paystack_account_name = '$a_name',
                    paystack_customer_code = '$c_code',
                    paystack_dva_id = '$d_id',
                    paystack_subaccount_code = '$s_code',
                    paystack_assigned_at = NOW()
                WHERE id = $zone_id AND estate_id = $estate_id";
        $conn->query($sql);

        return $account_data;
    }
}
