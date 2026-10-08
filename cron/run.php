<?php
// cron/run.php - run every 15 minutes:  php cron/run.php   (Windows Task Scheduler / Linux cron)
// Also callable over HTTP with ?token=CRON_TOKEN for hosts without shell cron.
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../includes/env.php';
    $t = env_get('CRON_TOKEN', '');
    if ($t === '' || !hash_equals($t, (string)($_GET['token'] ?? ''))) { http_response_code(403); exit('forbidden'); }
}
define('NO_CSRF_INJECT', true);
chdir(dirname(__DIR__));
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Lifecycle.php';
foreach (Lifecycle::run() as $line) echo date('c') . ' ' . $line . "\n";
// nightly backups at the first run after 02:00
if (PlatformSettings::bool('backup_enabled', true) && (int)date('G') >= 2 && (int)date('G') < 5) {
    $p = Tenancy::platform();
    $r = $p->query("SELECT e.id FROM estates e WHERE e.db_name IS NOT NULL AND e.status NOT IN ('deleted','archived','pending')
        AND NOT EXISTS (SELECT 1 FROM backups b WHERE b.estate_id = e.id AND b.kind='scheduled' AND b.status='ok' AND b.created_at > NOW() - INTERVAL 20 HOUR)");
    while ($e = $r->fetch_assoc()) {
        try { BackupService::backup($e['id'], 'scheduled'); echo date('c') . " backup estate {$e['id']} ok\n"; }
        catch (Throwable $ex) { echo date('c') . " backup estate {$e['id']} FAILED: " . $ex->getMessage() . "\n"; }
    }
    echo date('c') . ' pruned ' . BackupService::prune() . " old backup(s)\n";
}
