<?php
// includes/Tenancy.php - tenant resolution, database-per-tenant connections and provisioning.
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/Crypto.php';
require_once __DIR__ . '/Migrator.php';
require_once __DIR__ . '/CpanelApi.php';

class Tenancy {
    /** @var mysqli|null control-plane connection */
    private static $platform = null;
    private static $admin = null;
    /** @var array|null current estate row from the control plane */
    public static $estate = null;
    /** @var mysqli|null active tenant connection (also exposed as global $conn) */
    public static $conn = null;
    public static $mode = 'multi';

    // ---------------------------------------------------------------- connections
    public static function connect($user, $pass, $db = null) {
        $c = new mysqli(env_get('DB_HOST', 'localhost'), $user, $pass, $db ?: null);
        $c->set_charset('utf8mb4');
        $c->query("SET time_zone = '" . date('P') . "'"); // keep MySQL NOW() and PHP time() in the same zone
        return $c;
    }

    public static function platform() {
        if (self::$platform === null) {
            self::$platform = self::connect(env_get('DB_USER', 'root'), env_get('DB_PASS', ''), env_get('PLATFORM_DB', 'estate_platform'));
        }
        return self::$platform;
    }

    /** Privileged connection used ONLY for provisioning / dropping tenant databases. */
    public static function admin() {
        if (self::$admin === null) {
            self::$admin = self::connect(env_get('DB_ADMIN_USER', env_get('DB_USER', 'root')), env_get('DB_ADMIN_PASS', env_get('DB_PASS', '')));
        }
        return self::$admin;
    }

    // cPanel mode: names carry the account prefix cPanel enforces (e.g. cpuser_t7 / cpuser_u7).
    public static function tenantDbPrefix() { return CpanelApi::enabled() ? CpanelApi::prefix() . 't' : env_get('TENANT_DB_PREFIX', 'estate_t_'); }
    public static function tenantUserPrefix() { return CpanelApi::enabled() ? CpanelApi::prefix() . 'u' : 'et_'; }
    public static function tenantDbName($id) { return self::tenantDbPrefix() . intval($id); }
    public static function tenantUser($id) { return self::tenantUserPrefix() . intval($id); }

    // ---------------------------------------------------------------- lookup
    public static function find($id) {
        $id = intval($id);
        $st = self::platform()->prepare("SELECT * FROM estates WHERE id = ? LIMIT 1");
        $st->bind_param('i', $id);
        $st->execute();
        return $st->get_result()->fetch_assoc() ?: null;
    }

    public static function findByPrefix($prefix) {
        $prefix = strtolower(trim($prefix));
        if ($prefix === '') return null;
        $st = self::platform()->prepare("SELECT * FROM estates WHERE domain_prefix = ? LIMIT 1");
        $st->bind_param('s', $prefix);
        $st->execute();
        return $st->get_result()->fetch_assoc() ?: null;
    }

    public static function resolveFromHost() {
        $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') return null;
        $base = strtolower(trim(env_get('BASE_DOMAIN', '')));
        if ($base !== '' && substr($host, -strlen($base) - 1) === '.' . $base) {
            $label = substr($host, 0, -strlen($base) - 1);
            if ($label !== '' && $label !== 'www' && strpos($label, '.') === false) return self::findByPrefix($label);
        }
        if ($base === '' || $host !== $base) {
            $st = self::platform()->prepare("SELECT * FROM estates WHERE custom_domain = ? LIMIT 1");
            $st->bind_param('s', $host);
            $st->execute();
            return $st->get_result()->fetch_assoc() ?: null;
        }
        return null;
    }

    // ---------------------------------------------------------------- boot
    /** Statuses whose tenant database must not be opened. */
    private static function isOpenable(array $e) {
        return !empty($e['db_name']) && !in_array($e['status'], ['deleted', 'archived'], true);
    }

    public static function openTenant(array $e) {
        $pass = Crypto::decrypt($e['db_pass_enc']);
        return self::connect($e['db_user'], $pass, $e['db_name']);
    }

    /**
     * Resolve the tenant for this request and set the global $conn.
     * Order: super-admin impersonation -> authenticated session -> host -> login estate-code step.
     * There is deliberately NO default tenant.
     */
    public static function boot() {
        self::$mode = strtolower(env_get('TENANCY_MODE', 'multi'));
        if (self::$mode === 'legacy') {
            // Single database compatibility mode (pre-SaaS). Tenant is always #1.
            self::$conn = self::connect(env_get('DB_USER', 'root'), env_get('DB_PASS', ''), env_get('LEGACY_DB', 'estate'));
            self::$estate = ['id' => 1, 'name' => 'Main Estate', 'status' => 'active'];
            return self::$conn;
        }

        $platform = self::platform();
        if (isset($_GET['switch_estate']) && empty($_SESSION['user_id'])) unset($_SESSION['pending_estate_id']);
        $hostEstate = self::resolveFromHost();
        if (!$hostEstate && !empty($_GET['estate']) && empty($_SESSION['user_id']) && is_string($_GET['estate'])) {
            $byCode = self::findByPrefix($_GET['estate']);
            if ($byCode && !in_array($byCode['status'], ['deleted', 'archived'], true)) $_SESSION['pending_estate_id'] = intval($byCode['id']);
        }
        $estate = null;

        if (!empty($_SESSION['platform_user_id']) && !empty($_SESSION['impersonated_estate_id'])) {
            $estate = self::find($_SESSION['impersonated_estate_id']);
        } elseif (!empty($_SESSION['estate_id'])) {
            $estate = self::find($_SESSION['estate_id']);
            // A session minted for one estate must never be honoured on another estate's host.
            if ($estate && $hostEstate && intval($hostEstate['id']) !== intval($estate['id'])) {
                $_SESSION = [];
                $estate = $hostEstate;
            }
        } elseif ($hostEstate) {
            $estate = $hostEstate;
        } elseif (!empty($_SESSION['pending_estate_id'])) {
            $estate = self::find($_SESSION['pending_estate_id']);
        }

        if ($estate && self::isOpenable($estate)) {
            try {
                self::$conn = self::openTenant($estate);
                self::$estate = $estate;
                $GLOBALS['conn'] = self::$conn;
                return self::$conn;
            } catch (Throwable $e) {
                error_log('Tenant connect failed for estate ' . $estate['id'] . ': ' . $e->getMessage());
                self::logError('tenant', 'Tenant database connection failed: ' . $e->getMessage(), $estate['id']);
            }
        }
        // No tenant: $conn is the control plane (public pages / super admin only).
        self::$estate = null;
        self::$conn = $platform;
        $GLOBALS['conn'] = self::$conn;
        return self::$conn;
    }

    /** Machine endpoints (webhooks / USSD) switch the request onto a specific tenant once they have identified it. */
    public static function useEstate($id) {
        $e = self::find($id);
        if (!$e || !self::isOpenable($e) || in_array($e['status'], ['pending'], true)) return false;
        try {
            self::$conn = self::openTenant($e);
            self::$estate = $e;
            $GLOBALS['conn'] = self::$conn;
            return true;
        } catch (Throwable $x) { return false; }
    }

    /** Iterate live tenants (used by endpoints that must locate the owning estate, e.g. virtual-account transfers). */
    public static function eachLiveEstate(callable $fn) {
        $r = self::platform()->query("SELECT * FROM estates WHERE db_name IS NOT NULL AND status IN ('active','grace_period','trial','onboarding')");
        while ($r && $e = $r->fetch_assoc()) {
            try { $c = self::openTenant($e); if ($fn($e, $c) === true) return $e; } catch (Throwable $x) {}
        }
        return null;
    }

    public static function currentId() { return self::$estate ? intval(self::$estate['id']) : 0; }
    public static function hasTenant() { return self::$estate !== null && self::$conn !== self::$platform && self::$mode !== 'platform'; }
    public static function isPlatformConn($c) { return $c === self::$platform; }

    // ---------------------------------------------------------------- provisioning
    private static function randomPassword($len = 28) {
        $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $s = '';
        for ($i = 0; $i < $len; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
        return $s;
    }

    /** Create an EMPTY isolated database + dedicated MySQL user for an estate, load the clean schema and system defaults. */
    public static function provision($estateId) {
        $estate = self::find($estateId);
        if (!$estate) throw new RuntimeException('Estate not found');
        if (!empty($estate['db_name'])) throw new RuntimeException('Estate already provisioned');

        $dbName = self::tenantDbName($estateId);
        $dbUser = self::tenantUser($estateId);
        $dbPass = self::randomPassword();
        $usedDedicatedUser = true;

        if (CpanelApi::enabled()) {
            // Shared / reseller hosting: no MySQL admin exists, so cPanel creates the database and user for us.
            try {
                CpanelApi::createDatabase($dbName);
                CpanelApi::createUser($dbUser, $dbPass);
                CpanelApi::grantAll($dbUser, $dbName);
            } catch (Throwable $x) {
                try { CpanelApi::deleteDatabase($dbName); } catch (Throwable $y) {}
                try { CpanelApi::deleteUser($dbUser); } catch (Throwable $y) {}
                throw new RuntimeException('Failed to create tenant database through cPanel: ' . $x->getMessage());
            }
            self::connect($dbUser, $dbPass, $dbName)->close(); // sanity check
            $tenant = self::connect($dbUser, $dbPass, $dbName); // the tenant's own user owns its schema
        } else {
            $adm = self::admin();

            $adm->query("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            // In production, dedicated tenant users are mandatory for isolation.
            // Fallback to shared app account is a security risk and is NOT permitted.
            $hostPart = in_array(env_get('DB_HOST', 'localhost'), ['localhost', '127.0.0.1', '::1'], true) ? 'localhost' : '%';
            $adm->query("CREATE USER IF NOT EXISTS '$dbUser'@'$hostPart' IDENTIFIED BY '" . $adm->real_escape_string($dbPass) . "'");
            $adm->query("ALTER USER '$dbUser'@'$hostPart' IDENTIFIED BY '" . $adm->real_escape_string($dbPass) . "'");
            $adm->query("GRANT ALL PRIVILEGES ON `$dbName`.* TO '$dbUser'@'$hostPart'");
            $adm->query("FLUSH PRIVILEGES");
            if ($adm->errno) {
                // Clean up the database if user creation fails
                $adm->query("DROP DATABASE IF EXISTS `$dbName`");
                throw new RuntimeException('Failed to create dedicated database user for tenant isolation: ' . $adm->error);
            }
            self::connect($dbUser, $dbPass, $dbName)->close(); // sanity check

            // Load clean schema
            $tenant = self::connect(env_get('DB_ADMIN_USER', env_get('DB_USER', 'root')), env_get('DB_ADMIN_PASS', env_get('DB_PASS', '')), $dbName);
        }
        $schema = file_get_contents(dirname(__DIR__) . '/database/tenant_schema.sql');
        if ($tenant->multi_query($schema)) {
            do { if ($r = $tenant->store_result()) $r->free(); } while ($tenant->more_results() && $tenant->next_result());
        }
        if ($tenant->errno) throw new RuntimeException('Schema load failed: ' . $tenant->error);

        self::tenantMigrate($tenant);
        self::seed($tenant, intval($estateId), $estate);

        $enc = Crypto::encrypt($dbPass);
        $st = self::platform()->prepare("UPDATE estates SET db_name = ?, db_user = ?, db_pass_enc = ?, provisioned_at = NOW() WHERE id = ?");
        $st->bind_param('sssi', $dbName, $dbUser, $enc, $estateId);
        $st->execute();
        $tenant->close();
        return ['db' => $dbName, 'user' => $dbUser, 'dedicated_user' => $usedDedicatedUser];
    }

    public static function tenantMigrate(mysqli $tenant) {
        return Migrator::run($tenant, dirname(__DIR__) . '/database/migrations/tenant');
    }

    /** Apply pending tenant migrations to every provisioned tenant. */
    public static function migrateAllTenants($log = null) {
        $r = self::platform()->query("SELECT * FROM estates WHERE db_name IS NOT NULL AND status NOT IN ('deleted','archived')");
        while ($e = $r->fetch_assoc()) {
            try {
                $c = self::openTenant($e);
                $applied = self::tenantMigrate($c);
                if ($log) $log("estate {$e['id']}: " . (count($applied) ? implode(', ', $applied) : 'up to date'));
                $c->close();
            } catch (Throwable $ex) {
                if ($log) $log("estate {$e['id']} FAILED: " . $ex->getMessage());
                self::logError('tenant', "Tenant migration failed: " . $ex->getMessage(), $e['id']);
            }
        }
    }

    /** Insert mirrored estate row + system defaults. Creates NO users, residents or sample data. */
    public static function seed(mysqli $t, $estateId, array $estate) {
        $t->query("SET FOREIGN_KEY_CHECKS=0");
        $st = $t->prepare("INSERT INTO estates (id, name, domain_prefix, status) VALUES (?, ?, ?, 'pending') ON DUPLICATE KEY UPDATE name = VALUES(name)");
        $st->bind_param('iss', $estateId, $estate['name'], $estate['domain_prefix']);
        $st->execute();

        $seedFile = dirname(__DIR__) . '/database/tenant_seed.json';
        $seed = is_readable($seedFile) ? json_decode(file_get_contents($seedFile), true) : [];
        foreach ($seed as $table => $def) {
            if (!Migrator::tableExists($t, $table) || empty($def['rows'])) continue;
            $cols = $def['columns'];
            $estIdx = array_search('estate_id', $cols, true);
            $colSql = '`' . implode('`,`', $cols) . '`';
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $ins = $t->prepare("INSERT IGNORE INTO `$table` ($colSql) VALUES ($ph)");
            foreach ($def['rows'] as $row) {
                if ($def['estate_scoped'] && $estIdx !== false) $row[$estIdx] = $estateId;
                $types = str_repeat('s', count($row));
                $ins->bind_param($types, ...$row);
                $ins->execute();
            }
        }

        // Minimal system settings (no credentials, nothing copied from another estate)
        $defaults = [
            'estate_name' => $estate['name'],
            'currency_symbol' => '₦',
            'primary_color' => '#3b82f6',
            'contact_email' => $estate['contact_email'] ?? '',
            'contact_phone' => $estate['contact_phone'] ?? '',
            'smtp_enabled' => '0',
            'notify_on_invoice' => '1', 'notify_on_receipt' => '1', 'notify_on_visitor_pass' => '1',
            'notify_on_visitor_arrival' => '1', 'notify_on_welcome' => '1', 'notify_on_penalty' => '1',
            'notify_on_artisan_pass' => '1', 'require_resident_visitor_confirmation' => '1',
        ];
        $ss = $t->prepare("INSERT IGNORE INTO system_settings (estate_id, setting_key, setting_value) VALUES (?, ?, ?)");
        foreach ($defaults as $k => $v) {
            $ss->bind_param('iss', $estateId, $k, $v);
            $ss->execute();
        }
        $t->query("SET FOREIGN_KEY_CHECKS=1");
        // Mark baseline estate columns
        $t->query("UPDATE estates SET status = 'pending' WHERE id = " . intval($estateId));
    }

    /** Push status / plan / limits from the control plane into the tenant's mirrored estates row. */
    public static function mirror($estateId) {
        $e = self::find($estateId);
        if (!$e || empty($e['db_name']) || in_array($e['status'], ['deleted', 'archived'], true)) return false;
        try {
            $t = ($estateId == self::currentId() && self::$conn && !self::isPlatformConn(self::$conn)) ? self::$conn : self::openTenant($e);
            $plan = null; $cycle = null; $expires = null; $maxRes = 0;
            $sr = self::platform()->query("SELECT s.billing_cycle, s.period_end, s.resident_limit, p.name FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.estate_id = " . intval($estateId) . " AND s.status IN ('active','renewed','grace_period','past_due','suspended') ORDER BY s.id DESC LIMIT 1");
            if ($sr && $s = $sr->fetch_assoc()) { $plan = $s['name']; $cycle = $s['billing_cycle']; $expires = $s['period_end']; $maxRes = intval($s['resident_limit']); }
            $st = $t->prepare("INSERT INTO estates (id, name, domain_prefix, status, plan, billing_cycle, subscription_expires_at, contact_email, contact_phone, max_residents, custom_domain, address)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE name=VALUES(name), status=VALUES(status), plan=VALUES(plan), billing_cycle=VALUES(billing_cycle),
                subscription_expires_at=VALUES(subscription_expires_at), contact_email=VALUES(contact_email), contact_phone=VALUES(contact_phone),
                max_residents=VALUES(max_residents), custom_domain=VALUES(custom_domain), address=VALUES(address)");
            $id = intval($estateId);
            $st->bind_param('issssssssiss', $id, $e['name'], $e['domain_prefix'], $e['status'], $plan, $cycle, $expires, $e['contact_email'], $e['contact_phone'], $maxRes, $e['custom_domain'], $e['address']);
            $st->execute();
            return true;
        } catch (Throwable $ex) {
            self::logError('tenant', 'Mirror sync failed: ' . $ex->getMessage(), $estateId);
            return false;
        }
    }

    /** DROP the tenant database and user. Callers must have verified approval + final backup. */
    public static function dropTenant($estateId) {
        $e = self::find($estateId);
        if (!$e || empty($e['db_name'])) return false;
        $db = preg_replace('/[^A-Za-z0-9_]/', '', $e['db_name']);
        $user = preg_replace('/[^A-Za-z0-9_]/', '', $e['db_user'] ?? '');
        if (strpos($db, self::tenantDbPrefix()) !== 0) throw new RuntimeException('Refusing to drop non-tenant database');
        if (CpanelApi::enabled()) {
            CpanelApi::deleteDatabase($db);
            if ($user !== '' && strpos($user, self::tenantUserPrefix()) === 0) {
                try { CpanelApi::deleteUser($user); } catch (Throwable $x) {}
            }
            return true;
        }
        $adm = self::admin();
        $adm->query("DROP DATABASE IF EXISTS `$db`");
        if ($user !== '' && $user !== env_get('DB_USER', 'root') && strpos($user, 'et_') === 0) {
            try { $adm->query("DROP USER IF EXISTS '$user'@'localhost'"); $adm->query("DROP USER IF EXISTS '$user'@'%'"); } catch (Throwable $x) {}
        }
        return true;
    }

    // ---------------------------------------------------------------- misc
    public static function logError($category, $message, $estateId = null, $severity = 'error', $context = null) {
        try {
            $p = self::platform();
            $ctx = $context !== null ? json_encode($context) : null;
            $st = $p->prepare("INSERT INTO system_errors (category, severity, estate_id, message, context_json) VALUES (?,?,?,?,?)");
            $msg = substr($message, 0, 500);
            $st->bind_param('ssiss', $category, $severity, $estateId, $msg, $ctx);
            $st->execute();
        } catch (Throwable $e) { error_log('logError failed: ' . $e->getMessage()); }
    }
}

/** Convenience accessors */
function platform_db() { return Tenancy::platform(); }
