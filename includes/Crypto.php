<?php
// includes/Crypto.php - AES-256-GCM helpers for secrets stored in the platform DB (tenant DB passwords, gateway keys).
require_once __DIR__ . '/env.php';

class Crypto {
    private static function key() {
        $k = env_get('APP_KEY', '');
        if (strpos($k, 'base64:') === 0) $k = base64_decode(substr($k, 7));
        if (strlen($k) < 32) throw new RuntimeException('APP_KEY missing or too short. Set it in .env');
        return substr(hash('sha256', $k, true), 0, 32);
    }
    public static function encrypt($plain) {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt((string)$plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $ct);
    }
    public static function decrypt($blob) {
        if ($blob === null || $blob === '') return '';
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 29) return '';
        $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $pt === false ? '' : $pt;
    }
    public static function token($bytes = 32) { return bin2hex(random_bytes($bytes)); }
    public static function hashToken($t) { return hash('sha256', (string)$t); }
}
