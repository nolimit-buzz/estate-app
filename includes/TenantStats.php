<?php
// includes/TenantStats.php - live per-tenant statistics + database health, read straight from each estate's own database.
// Results are cached in the control-plane table `estate_stats` so list/dashboard pages stay fast as estates grow.
require_once __DIR__ . '/Tenancy.php';

class TenantStats {
    const DEFAULT_MAX_AGE = 300; // seconds

    /** Estates whose tenant database can be (or should be) opened. */
    private static function openable(array $e) {
        return !empty($e['db_name']) && !in_array($e['status'], ['deleted', 'archived'], true);
    }

    private static function scalar(mysqli $t, $sql, $default = 0) {
        try {
            $r = $t->query($sql);
            if ($r && $row = $r->fetch_row()) return $row[0] ?? $default;
        } catch (Throwable $x) { /* table/column missing in this tenant: report 0 rather than failing the whole probe */ }
        return $default;
    }

    /** Open the estate's own database and gather numbers. Never throws; returns ['db_ok'=>0,'db_error'=>..] on failure. */
    public static function collect(array $estate) {
        $out = ['estate_id' => intval($estate['id']), 'residents' => 0, 'staff' => 0, 'admins' => 0, 'open_maintenance' => 0,
                'unpaid_invoices' => 0, 'last_login_at' => null, 'db_ok' => 1, 'db_error' => null, 'checked_at' => date('Y-m-d H:i:s')];
        if (!self::openable($estate)) {
            $out['db_ok'] = 0;
            $out['db_error'] = empty($estate['db_name']) ? 'No environment provisioned' : 'Estate removed';
            return $out;
        }
        try {
            $t = Tenancy::openTenant($estate);
        } catch (Throwable $e) {
            $out['db_ok'] = 0;
            $out['db_error'] = substr($e->getMessage(), 0, 250);
            return $out;
        }
        $out['residents'] = intval(self::scalar($t, "SELECT COUNT(*) FROM residents WHERE status = 'active'"));
        $out['admins'] = intval(self::scalar($t, "SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'"));
        $out['staff'] = intval(self::scalar($t, "SELECT COUNT(*) FROM users WHERE role NOT IN ('admin','resident') AND status = 'active'"));
        $out['open_maintenance'] = intval(self::scalar($t, "SELECT COUNT(*) FROM maintenance_requests WHERE status IN ('open','in_progress')"));
        $out['unpaid_invoices'] = intval(self::scalar($t, "SELECT COUNT(*) FROM invoices WHERE status IN ('unpaid','partially_paid','overdue')"));
        $out['last_login_at'] = self::scalar($t, "SELECT MAX(last_login_at) FROM users", null);
        try { $t->close(); } catch (Throwable $x) {}
        return $out;
    }

    /** Collect + persist. Also keeps estates.resident_count (used for billing limits) in step. */
    public static function refresh($estateId) {
        $estate = Tenancy::find($estateId);
        if (!$estate) return null;
        $s = self::collect($estate);
        $p = Tenancy::platform();
        $st = $p->prepare("INSERT INTO estate_stats (estate_id, residents, staff, admins, open_maintenance, unpaid_invoices, last_login_at, db_ok, db_error, checked_at)
            VALUES (?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE residents=VALUES(residents), staff=VALUES(staff), admins=VALUES(admins), open_maintenance=VALUES(open_maintenance),
            unpaid_invoices=VALUES(unpaid_invoices), last_login_at=VALUES(last_login_at), db_ok=VALUES(db_ok), db_error=VALUES(db_error), checked_at=VALUES(checked_at)");
        $st->bind_param('iiiiiisiss', $s['estate_id'], $s['residents'], $s['staff'], $s['admins'], $s['open_maintenance'], $s['unpaid_invoices'],
            $s['last_login_at'], $s['db_ok'], $s['db_error'], $s['checked_at']);
        $st->execute();
        if ($s['db_ok']) {
            $p->query("UPDATE estates SET resident_count = " . intval($s['residents']) . ", resident_count_at = NOW() WHERE id = " . intval($estateId));
        } else {
            self::reportDown($estate, $s['db_error']);
        }
        return $s;
    }

    /** Log an unreachable tenant once per hour (not on every refresh) so Monitoring shows it without flooding. */
    private static function reportDown(array $estate, $err) {
        if (!self::openable($estate)) return; // nothing to alert about a removed/unprovisioned estate
        $id = intval($estate['id']);
        $r = Tenancy::platform()->query("SELECT 1 FROM system_errors WHERE category = 'tenant' AND estate_id = $id AND resolved = 0 AND message LIKE 'Tenant database unreachable%' AND created_at > NOW() - INTERVAL 1 HOUR LIMIT 1");
        if (!$r || !$r->num_rows) Tenancy::logError('tenant', 'Tenant database unreachable: ' . $err, $id);
    }

    /** Refresh at most $limit estates whose cache is older than $maxAge seconds (or missing). Returns number refreshed. */
    public static function refreshStale($maxAge = self::DEFAULT_MAX_AGE, $limit = 6) {
        $maxAge = max(30, intval($maxAge)); $limit = max(1, intval($limit));
        $r = Tenancy::platform()->query("SELECT e.id FROM estates e LEFT JOIN estate_stats s ON s.estate_id = e.id
            WHERE e.db_name IS NOT NULL AND e.status NOT IN ('deleted','archived') AND (s.checked_at IS NULL OR s.checked_at < NOW() - INTERVAL $maxAge SECOND)
            ORDER BY s.checked_at IS NULL DESC, s.checked_at ASC LIMIT $limit");
        $ids = [];
        while ($r && $row = $r->fetch_assoc()) $ids[] = intval($row['id']);
        foreach ($ids as $id) self::refresh($id);
        return count($ids);
    }

    /** Force-refresh every live estate ("Refresh now"). Returns [refreshed, failed]. */
    public static function refreshAll() {
        $r = Tenancy::platform()->query("SELECT id FROM estates WHERE db_name IS NOT NULL AND status NOT IN ('deleted','archived')");
        $ids = [];
        while ($r && $row = $r->fetch_assoc()) $ids[] = intval($row['id']);
        $ok = 0; $bad = 0;
        foreach ($ids as $id) { $s = self::refresh($id); if ($s && $s['db_ok']) $ok++; else $bad++; }
        return [$ok, $bad];
    }

    public static function get($estateId) {
        $id = intval($estateId);
        $r = Tenancy::platform()->query("SELECT * FROM estate_stats WHERE estate_id = $id");
        return $r ? ($r->fetch_assoc() ?: null) : null;
    }

    /** Estates with an unreachable tenant database (for "Needs attention"). */
    public static function down() {
        $out = [];
        $r = Tenancy::platform()->query("SELECT e.id, e.name, s.db_error, s.checked_at FROM estate_stats s JOIN estates e ON e.id = s.estate_id
            WHERE s.db_ok = 0 AND e.db_name IS NOT NULL AND e.status NOT IN ('deleted','archived') ORDER BY e.name");
        while ($r && $row = $r->fetch_assoc()) $out[] = $row;
        return $out;
    }

    public static function lastChecked() {
        $r = Tenancy::platform()->query("SELECT MAX(checked_at) m FROM estate_stats");
        return $r ? ($r->fetch_assoc()['m'] ?? null) : null;
    }
}
