<?php
// includes/SessionGuard.php - hardened PHP sessions: secure cookie flags, idle + absolute timeouts, id rotation on login.
class SessionGuard {
    public static function isHttps() {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 0) == 443) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    public static function start() {
        if (session_status() !== PHP_SESSION_NONE || PHP_SAPI === 'cli') return;
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('ESTATESESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        $idle = 60 * self::setting('session_idle_minutes', 60);
        $abs = 3600 * self::setting('session_absolute_hours', 12);
        if (!empty($_SESSION['user_id']) || !empty($_SESSION['platform_user_id'])) {
            $last = $_SESSION['last_activity'] ?? $now;
            $born = $_SESSION['session_started'] ?? $now;
            if (($now - $last) > $idle || ($now - $born) > $abs) {
                $reason = ($now - $born) > $abs ? 'session_expired' : 'idle_timeout';
                $_SESSION = [];
                $_SESSION['flash_error'] = 'Your session expired for security. Please sign in again.';
                $_SESSION['timeout_reason'] = $reason;
            }
        }
        if (!empty($_SESSION['user_id']) || !empty($_SESSION['platform_user_id'])) {
            $_SESSION['last_activity'] = $now;
            $_SESSION['session_started'] = $_SESSION['session_started'] ?? $now;
        }
    }

    /** Call right after successful authentication. */
    public static function onLogin() {
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION['session_started'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['login_session_ref'] = bin2hex(random_bytes(16));
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['login_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
        return $_SESSION['login_session_ref'];
    }

    public static function destroy() {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    }

    private static function setting($key, $default) {
        try {
            require_once __DIR__ . '/PlatformSettings.php';
            return max(1, PlatformSettings::int($key, $default));
        } catch (Throwable $e) { return $default; }
    }
}
