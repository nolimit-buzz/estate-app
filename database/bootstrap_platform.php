<?php
// database/bootstrap_platform.php - CLI installer / upgrader for the SaaS control plane.
//   php database/bootstrap_platform.php                 create platform DB, run migrations, ensure first super admin
//   php database/bootstrap_platform.php --adopt-legacy  also register the existing single-tenant DB as estate #1 (copies data)
//   php database/bootstrap_platform.php --migrate-tenants   apply pending tenant migrations to every tenant DB
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/Tenancy.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$args = $argv ?? [];
$say = function ($m) { echo $m . "\n"; };

$platformDb = env_get('PLATFORM_DB', 'estate_platform');
if (CpanelApi::enabled()) {
    // Shared hosting: the platform database is created by hand in cPanel -> MySQL Databases.
    try { Tenancy::platform(); }
    catch (Throwable $e) {
        $say("Cannot open platform database `$platformDb` as `" . env_get('DB_USER', '') . "`: " . $e->getMessage());
        $say("Create it in cPanel -> MySQL Databases, add the user with ALL PRIVILEGES, then set PLATFORM_DB / DB_USER / DB_PASS in .env");
        exit(1);
    }
    $adm = null;
} else {
    $adm = Tenancy::admin();
    $adm->query("CREATE DATABASE IF NOT EXISTS `$platformDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}
$say("platform database `$platformDb` ready");

$p = Tenancy::platform();
$applied = Migrator::run($p, __DIR__ . '/migrations/platform', $say);
$say(count($applied) ? 'platform migrations applied: ' . count($applied) : 'platform schema up to date');

// ---- first super admin
$have = $p->query("SELECT COUNT(*) c FROM platform_users")->fetch_assoc()['c'];
if (!$have) {
    $email = '';
    foreach ($args as $a) if (strpos($a, '--superadmin-email=') === 0) $email = substr($a, 19);
    if ($email === '') $email = env_get('SUPERADMIN_EMAIL', '');
    if ($email === '') {
        // fall back to the first legacy super admin address
        try {
            $l = Tenancy::connect(env_get('DB_USER', 'root'), env_get('DB_PASS', ''), env_get('LEGACY_DB', 'estate'));
            $r = $l->query("SELECT email FROM users WHERE role = 'superadmin' ORDER BY id LIMIT 1");
            if ($r && $row = $r->fetch_assoc()) $email = $row['email'];
        } catch (Throwable $e) {}
    }
    if ($email === '') $email = 'owner@example.com';
    $pass = bin2hex(random_bytes(6)) . 'A!';
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $name = env_get('SUPERADMIN_NAME', 'Platform Owner');
    $st = $p->prepare("INSERT INTO platform_users (name, email, password, role, force_password_change) VALUES (?,?,?,'superadmin',1)");
    $st->bind_param('sss', $name, $email, $hash);
    $st->execute();
    $say("\n=== FIRST SUPER ADMIN CREATED (shown once) ===\n  email:    $email\n  password: $pass\n  (you must change it on first login)\n");
}

// ---- adopt legacy single-tenant database as estate #1
if (in_array('--adopt-legacy', $args, true)) {
    $legacy = env_get('LEGACY_DB', 'estate');
    if ($p->query("SELECT id FROM estates WHERE id = 1")->num_rows) {
        $say('estate #1 already registered - skipping adoption');
    } else {
        $say("adopting legacy database `$legacy` as estate #1 ...");
        $le = $adm->query("SELECT * FROM `$legacy`.estates WHERE id = 1")->fetch_assoc() ?: ['name' => 'Main Estate', 'domain_prefix' => 'main', 'contact_email' => null, 'contact_phone' => null, 'custom_domain' => null];
        $name = $le['name']; $prefix = $le['domain_prefix'] ?: 'main';
        $ce = $le['contact_email'] ?? null; $cp = $le['contact_phone'] ?? null; $cd = $le['custom_domain'] ?: null;
        $st = $p->prepare("INSERT INTO estates (id, name, domain_prefix, custom_domain, contact_email, contact_phone, status, onboarding_status) VALUES (1,?,?,?,?,?,'active','active')");
        $st->bind_param('sssss', $name, $prefix, $cd, $ce, $cp);
        $st->execute();

        // Provision (creates empty DB + user), then copy the legacy rows across.
        $info = Tenancy::provision(1);
        $dst = $info['db'];
        $adm->query("SET FOREIGN_KEY_CHECKS=0");
        $tables = [];
        $r = $adm->query("SHOW TABLES FROM `$legacy`");
        while ($row = $r->fetch_row()) $tables[] = $row[0];
        $copied = 0;
        foreach ($tables as $t) {
            if ($t === 'schema_migrations') continue;
            $exists = $adm->query("SHOW TABLES FROM `$dst` LIKE '" . $adm->real_escape_string($t) . "'")->num_rows;
            if (!$exists) { $say("  ! table $t not in tenant schema - skipped"); continue; }
            $adm->query("DELETE FROM `$dst`.`$t`");
            $srcCols = []; $dstCols = [];
            $q = $adm->query("SHOW COLUMNS FROM `$legacy`.`$t`"); while ($c = $q->fetch_assoc()) $srcCols[] = $c['Field'];
            $q = $adm->query("SHOW COLUMNS FROM `$dst`.`$t`"); while ($c = $q->fetch_assoc()) $dstCols[] = $c['Field'];
            $cols = array_values(array_intersect($srcCols, $dstCols));
            $list = '`' . implode('`,`', $cols) . '`';
            $adm->query("INSERT INTO `$dst`.`$t` ($list) SELECT $list FROM `$legacy`.`$t`");
            $n = $adm->affected_rows;
            $copied += $n;
        }
        $adm->query("SET FOREIGN_KEY_CHECKS=1");
        $say("  copied $copied rows across " . count($tables) . " tables");

        // Super-admin identities do not belong inside an estate: demote them to estate admins; the
        // well-known default account is disabled.
        $t = Tenancy::openTenant(Tenancy::find(1));
        $t->query("UPDATE users SET status = 'disabled' WHERE email = 'superadmin@admin.com' AND role = 'superadmin'");
        $t->query("UPDATE users SET role = 'admin' WHERE role = 'superadmin'");
        $t->query("UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE status = 'active'");
        // Legacy plaintext SMTP/gateway secrets stay in this estate's own system_settings (its own database).

        // Subscription: legacy estate is grandfathered on the Premium plan for one year (no payment taken).
        $plan = $p->query("SELECT * FROM plans WHERE slug = 'premium' LIMIT 1")->fetch_assoc();
        $mods = [];
        $mr = $p->query("SELECT module_key, inclusion FROM plan_modules WHERE plan_id = " . intval($plan['id']));
        while ($m = $mr->fetch_assoc()) $mods[] = $m;
        $snap = json_encode(['plan' => $plan, 'modules' => $mods]);
        $limit = intval($plan['resident_limit']);
        $ins = $p->prepare("INSERT INTO subscriptions (estate_id, plan_id, plan_version, plan_snapshot_json, billing_cycle, status, resident_limit, period_start, period_end, next_billing_date)
            VALUES (1, ?, ?, ?, 'annual', 'active', ?, NOW(), NOW() + INTERVAL 1 YEAR, NOW() + INTERVAL 1 YEAR)");
        $pv = intval($plan['version']); $pid = intval($plan['id']);
        $ins->bind_param('iisi', $pid, $pv, $snap, $limit);
        $ins->execute();
        $sid = $p->insert_id;
        $p->query("UPDATE estates SET current_subscription_id = $sid, resident_count = (SELECT 0) WHERE id = 1");
        $p->query("INSERT INTO billing_events (estate_id, subscription_id, event_type, title) VALUES (1, $sid, 'subscription_change', 'Legacy estate migrated and grandfathered on Premium (1 year, no charge)')");
        $p->query("INSERT INTO platform_audit_logs (actor_type, actor_name, estate_id, action, module, details) VALUES ('system','bootstrap',1,'estate.adopted_legacy','estates','Legacy single-tenant database registered as estate #1 and copied into {$dst}')");
        Tenancy::mirror(1);
        $say("estate #1 registered: db=$dst user={$info['user']}  (original `$legacy` left untouched as a backup)");
    }
}

if (in_array('--migrate-tenants', $args, true) || in_array('--adopt-legacy', $args, true)) {
    Tenancy::migrateAllTenants($say);
}
$say('done');
