<?php
// includes/CpanelApi.php - minimal cPanel UAPI client used when DB_PROVISIONER=cpanel (shared / reseller hosting
// where the app has no MySQL admin account and must create databases through cPanel instead).
require_once __DIR__ . '/env.php';

class CpanelApi {
    /** True when tenant databases must be created through cPanel instead of plain SQL. */
    public static function enabled() { return strtolower(env_get('DB_PROVISIONER', 'sql')) === 'cpanel'; }

    /** cPanel truncates the account name to 8 chars when it prefixes database and user names. */
    public static function prefix() {
        $p = env_get('CPANEL_DB_PREFIX', '');
        if ($p !== '') return $p;
        return substr(strtolower(env_get('CPANEL_USER', '')), 0, 8) . '_';
    }

    /** Call a UAPI function. Returns the decoded "data" or throws with cPanel's own error text. */
    public static function call($module, $function, array $params = []) {
        $user = env_get('CPANEL_USER', '');
        $token = env_get('CPANEL_API_TOKEN', '');
        if ($user === '' || $token === '') throw new RuntimeException('CPANEL_USER / CPANEL_API_TOKEN are not set in .env');
        $host = env_get('CPANEL_HOST', 'localhost');
        $port = intval(env_get('CPANEL_PORT', '2083'));
        $url = "https://$host:$port/execute/" . rawurlencode($module) . '/' . rawurlencode($function);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Authorization: cpanel ' . $user . ':' . $token],
            // The server often calls itself by a name the certificate does not cover.
            CURLOPT_SSL_VERIFYPEER => env_get('CPANEL_VERIFY_SSL', '0') === '1',
            CURLOPT_SSL_VERIFYHOST => env_get('CPANEL_VERIFY_SSL', '0') === '1' ? 2 : 0,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) throw new RuntimeException("cPanel API unreachable ($host:$port): $err");
        $j = json_decode($body, true);
        if (!is_array($j)) throw new RuntimeException("cPanel API returned an unreadable response (HTTP $code)");
        if (empty($j['status'])) {
            $msg = !empty($j['errors']) ? implode('; ', (array)$j['errors']) : "HTTP $code";
            throw new RuntimeException("cPanel $module::$function failed: $msg");
        }
        return $j['data'] ?? null;
    }

    public static function createDatabase($name) { self::call('Mysql', 'create_database', ['name' => $name]); }
    public static function deleteDatabase($name) { self::call('Mysql', 'delete_database', ['name' => $name]); }
    public static function createUser($name, $password) { self::call('Mysql', 'create_user', ['name' => $name, 'password' => $password]); }
    public static function deleteUser($name) { self::call('Mysql', 'delete_user', ['name' => $name]); }
    public static function grantAll($user, $database) {
        self::call('Mysql', 'set_privileges_on_database', ['user' => $user, 'database' => $database, 'privileges' => 'ALL PRIVILEGES']);
    }
}
