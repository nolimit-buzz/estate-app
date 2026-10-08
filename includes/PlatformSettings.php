<?php
// includes/PlatformSettings.php - DB-backed platform configuration (grace period, retention, OTP expiry...).
// Nothing here is hardcoded in UI code: defaults are seeded by migration and edited by the Super Admin.
require_once __DIR__ . '/Tenancy.php';

class PlatformSettings {
    private static $cache = null;

    private static function load() {
        if (self::$cache !== null) return;
        self::$cache = [];
        try {
            $r = Tenancy::platform()->query("SELECT setting_key, setting_value FROM platform_settings");
            while ($r && $row = $r->fetch_assoc()) self::$cache[$row['setting_key']] = $row['setting_value'];
        } catch (Throwable $e) { /* platform not migrated yet */ }
    }
    public static function get($key, $default = null) { self::load(); return array_key_exists($key, self::$cache) && self::$cache[$key] !== null ? self::$cache[$key] : $default; }
    public static function int($key, $default = 0) { return intval(self::get($key, $default)); }
    public static function bool($key, $default = false) { return in_array(strtolower((string)self::get($key, $default ? '1' : '0')), ['1', 'true', 'yes', 'on'], true); }
    public static function csvInts($key, $default = '') {
        $v = trim((string)self::get($key, $default));
        if ($v === '') return [];
        $a = array_map('intval', array_filter(array_map('trim', explode(',', $v)), 'strlen'));
        rsort($a);
        return $a;
    }
    public static function set($key, $value, $by = null) {
        $st = Tenancy::platform()->prepare("UPDATE platform_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?");
        $st->bind_param('sis', $value, $by, $key);
        $st->execute();
        self::$cache = null;
        return $st->affected_rows >= 0;
    }
    public static function all($group = null) {
        $sql = "SELECT * FROM platform_settings" . ($group ? " WHERE group_name = '" . Tenancy::platform()->real_escape_string($group) . "'" : '') . " ORDER BY group_name, sort_order, setting_key";
        $out = [];
        $r = Tenancy::platform()->query($sql);
        while ($r && $row = $r->fetch_assoc()) $out[] = $row;
        return $out;
    }
    public static function flush() { self::$cache = null; }
}
