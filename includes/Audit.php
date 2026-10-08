<?php
// includes/Audit.php - platform audit trail + login/security logging.
require_once __DIR__ . '/Tenancy.php';

class Audit {
    public static function ip() { return $_SERVER['REMOTE_ADDR'] ?? ''; }
    public static function ua() { return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255); }

    public static function device($ua = null) {
        $ua = $ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $browser = 'Unknown browser';
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'] as $k => $v) {
            if (stripos($ua, $k) !== false) { $browser = $v; break; }
        }
        $os = 'Unknown OS';
        foreach (['Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iOS', 'Mac OS' => 'macOS', 'Linux' => 'Linux'] as $k => $v) {
            if (stripos($ua, $k) !== false) { $os = $v; break; }
        }
        return "$browser / $os";
    }

    /** Who is acting right now (platform admin, tenant user or system). */
    public static function actor() {
        if (!empty($_SESSION['platform_user_id'])) {
            $imp = !empty($_SESSION['impersonated_estate_id']);
            return ['platform', intval($_SESSION['platform_user_id']), $_SESSION['platform_user_name'] ?? 'Platform admin', 'superadmin' . ($imp ? ' (impersonating)' : '')];
        }
        if (!empty($_SESSION['user_id'])) return ['tenant', intval($_SESSION['user_id']), $_SESSION['name'] ?? '', $_SESSION['role'] ?? ''];
        return ['system', null, 'system', null];
    }

    /**
     * Record an audit event in the control plane.
     * @param string $action  e.g. "plan.updated"
     * @param array  $o       estate_id, module, entity_type, entity_id, details, before, after, actor (override)
     */
    public static function log($action, array $o = []) {
        try {
            [$type, $aid, $aname, $arole] = $o['actor'] ?? self::actor();
            $estate = array_key_exists('estate_id', $o) ? $o['estate_id'] : (Tenancy::currentId() ?: null);
            $before = isset($o['before']) ? json_encode($o['before'], JSON_UNESCAPED_UNICODE) : null;
            $after = isset($o['after']) ? json_encode($o['after'], JSON_UNESCAPED_UNICODE) : null;
            $module = $o['module'] ?? null; $et = $o['entity_type'] ?? null;
            $eid = isset($o['entity_id']) ? (string)$o['entity_id'] : null;
            $details = $o['details'] ?? null;
            $ip = self::ip(); $ua = self::ua();
            $st = Tenancy::platform()->prepare("INSERT INTO platform_audit_logs (actor_type, actor_id, actor_name, actor_role, estate_id, action, module, entity_type, entity_id, details, before_json, after_json, ip_address, user_agent)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->bind_param('sississsssssss', $type, $aid, $aname, $arole, $estate, $action, $module, $et, $eid, $details, $before, $after, $ip, $ua);
            $st->execute();
            return true;
        } catch (Throwable $e) {
            error_log('Audit::log failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Record a login/security event. */
    public static function login($event, $success, array $o = []) {
        try {
            $ref = $o['session_ref'] ?? null;
            $userType = $o['user_type'] ?? 'tenant';
            $uid = $o['user_id'] ?? null; $email = $o['email'] ?? null; $name = $o['name'] ?? null;
            $role = $o['role'] ?? null; $estate = $o['estate_id'] ?? null; $reason = $o['reason'] ?? null;
            $ip = self::ip(); $ua = self::ua(); $dev = self::device();
            $s = $success ? 1 : 0;
            $st = Tenancy::platform()->prepare("INSERT INTO platform_login_logs (event_type, user_type, user_id, email, user_name, role, estate_id, success, failure_reason, ip_address, user_agent, device, session_ref)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->bind_param('ssisssiisssss', $event, $userType, $uid, $email, $name, $role, $estate, $s, $reason, $ip, $ua, $dev, $ref);
            $st->execute();
            return Tenancy::platform()->insert_id;
        } catch (Throwable $e) { error_log('Audit::login failed: ' . $e->getMessage()); return 0; }
    }

    public static function logout() {
        try {
            $ref = $_SESSION['login_session_ref'] ?? null;
            if ($ref) {
                $st = Tenancy::platform()->prepare("UPDATE platform_login_logs SET logged_out_at = NOW() WHERE session_ref = ? AND logged_out_at IS NULL");
                $st->bind_param('s', $ref);
                $st->execute();
            }
            [, , $n, $r] = self::actor();
            self::login('logout', true, ['user_id' => $_SESSION['user_id'] ?? ($_SESSION['platform_user_id'] ?? null), 'name' => $n, 'role' => $r, 'estate_id' => Tenancy::currentId() ?: null, 'user_type' => !empty($_SESSION['platform_user_id']) ? 'platform' : 'tenant', 'session_ref' => $ref]);
        } catch (Throwable $e) {}
    }

    /** Raise a security alert (deduplicated within 10 minutes). */
    public static function alert($type, $message, $severity = 'medium', array $o = []) {
        try {
            $p = Tenancy::platform();
            $estate = $o['estate_id'] ?? null; $ref = $o['user_ref'] ?? null; $ip = $o['ip'] ?? self::ip();
            $chk = $p->prepare("SELECT id FROM security_alerts WHERE alert_type = ? AND COALESCE(user_ref,'') = COALESCE(?,'') AND COALESCE(ip_address,'') = COALESCE(?,'') AND status = 'open' AND created_at > (NOW() - INTERVAL 10 MINUTE) LIMIT 1");
            $chk->bind_param('sss', $type, $ref, $ip);
            $chk->execute();
            if ($chk->get_result()->num_rows) return;
            $ctx = isset($o['context']) ? json_encode($o['context']) : null;
            $st = $p->prepare("INSERT INTO security_alerts (alert_type, severity, estate_id, user_ref, ip_address, message, context_json) VALUES (?,?,?,?,?,?,?)");
            $st->bind_param('ssissss', $type, $severity, $estate, $ref, $ip, $message, $ctx);
            $st->execute();
        } catch (Throwable $e) { error_log('Audit::alert failed: ' . $e->getMessage()); }
    }
}

/**
 * Legacy-compatible audit logger used throughout the existing portals.
 * Writes to the tenant's own audit_logs (kept for estate admins) AND the platform audit trail.
 */
function logAudit($conn, $action, $module, $details = "") {
    $ok = false;
    try {
        if ($conn instanceof mysqli && !Tenancy::isPlatformConn($conn)) {
            $user_id = !empty($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
            $estate_id = Tenancy::currentId() ?: null;
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $st = $conn->prepare("INSERT INTO audit_logs (estate_id, user_id, action, module, details, ip_address) VALUES (?,?,?,?,?,?)");
            $st->bind_param('iissss', $estate_id, $user_id, $action, $module, $details, $ip);
            $ok = $st->execute();
        }
    } catch (Throwable $e) { error_log('logAudit tenant write failed: ' . $e->getMessage()); }
    Audit::log($action, ['module' => $module, 'details' => $details]);
    return $ok;
}
