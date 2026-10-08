<?php
// includes/RateLimit.php - DB-backed throttling for login / OTP / reset / invitation endpoints.
require_once __DIR__ . '/PlatformSettings.php';

class RateLimit {
    /** @return int seconds remaining on lockout (0 = allowed) */
    public static function lockedFor($bucket, $identifier) {
        $p = Tenancy::platform();
        $id = strtolower(substr($identifier, 0, 191));
        $st = $p->prepare("SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), locked_until)) AS s FROM rate_limits WHERE bucket = ? AND identifier = ? AND locked_until IS NOT NULL");
        $st->bind_param('ss', $bucket, $id);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        return $r ? intval($r['s']) : 0;
    }

    /** Register a failed attempt. Returns true when this attempt triggered a lockout. */
    public static function hit($bucket, $identifier, $max = null, $lockMinutes = null, $windowMinutes = 15) {
        $max = $max ?? PlatformSettings::int('login_max_attempts', 5);
        $lockMinutes = $lockMinutes ?? PlatformSettings::int('login_lockout_minutes', 15);
        $p = Tenancy::platform();
        $id = strtolower(substr($identifier, 0, 191));
        $st = $p->prepare("INSERT INTO rate_limits (bucket, identifier, attempts, window_start) VALUES (?,?,1,NOW())
            ON DUPLICATE KEY UPDATE
              attempts = IF(window_start < (NOW() - INTERVAL ? MINUTE) OR (locked_until IS NOT NULL AND locked_until < NOW()), 1, attempts + 1),
              window_start = IF(window_start < (NOW() - INTERVAL ? MINUTE) OR (locked_until IS NOT NULL AND locked_until < NOW()), NOW(), window_start),
              locked_until = IF(window_start < (NOW() - INTERVAL ? MINUTE) OR (locked_until IS NOT NULL AND locked_until < NOW()), NULL, locked_until)");
        $st->bind_param('ssiii', $bucket, $id, $windowMinutes, $windowMinutes, $windowMinutes);
        $st->execute();
        $q = $p->prepare("SELECT attempts FROM rate_limits WHERE bucket = ? AND identifier = ?");
        $q->bind_param('ss', $bucket, $id);
        $q->execute();
        $a = intval(($q->get_result()->fetch_assoc())['attempts'] ?? 0);
        if ($a >= $max) {
            $u = $p->prepare("UPDATE rate_limits SET locked_until = NOW() + INTERVAL ? MINUTE WHERE bucket = ? AND identifier = ?");
            $u->bind_param('iss', $lockMinutes, $bucket, $id);
            $u->execute();
            return true;
        }
        return false;
    }

    public static function clear($bucket, $identifier) {
        $id = strtolower(substr($identifier, 0, 191));
        $st = Tenancy::platform()->prepare("DELETE FROM rate_limits WHERE bucket = ? AND identifier = ?");
        $st->bind_param('ss', $bucket, $id);
        $st->execute();
    }

    public static function humanWait($seconds) {
        return $seconds >= 60 ? ceil($seconds / 60) . ' minute(s)' : $seconds . ' second(s)';
    }
}
