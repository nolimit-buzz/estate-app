<?php
// includes/BackupService.php - per-estate backups, estate-locked restore, and Central Admin data export.
require_once __DIR__ . '/Tenancy.php';
require_once __DIR__ . '/PlatformSettings.php';
require_once __DIR__ . '/Audit.php';

class BackupService {
    public static function dir($estateId) {
        $d = dirname(__DIR__) . '/storage/backups/' . intval($estateId);
        if (!is_dir($d)) { @mkdir($d, 0775, true); }
        $root = dirname(__DIR__) . '/storage';
        foreach ([$root, $root . '/backups', $root . '/exports'] as $p) {
            if (is_dir($p) && !is_file($p . '/.htaccess')) @file_put_contents($p . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        return $d;
    }

    private static function bin($name, $settingKey) {
        $custom = trim((string)PlatformSettings::get($settingKey, ''));
        if ($custom !== '' && is_file($custom)) return $custom;
        $exe = $name . (stripos(PHP_OS, 'WIN') === 0 ? '.exe' : '');
        foreach (['C:/xampp/mysql/bin/' . $exe, '/usr/bin/' . $exe, '/usr/local/bin/' . $exe, '/opt/lampp/bin/' . $exe] as $c) if (is_file($c)) return $c;
        return $exe; // rely on PATH
    }

    private static function optionsFile(array $estate) {
        $f = tempnam(sys_get_temp_dir(), 'myc');
        $pass = Crypto::decrypt($estate['db_pass_enc']);
        file_put_contents($f, "[client]\nuser=\"" . $estate['db_user'] . "\"\npassword=\"" . str_replace(['\\', '"'], ['\\\\', '\\"'], $pass) . "\"\nhost=\"" . env_get('DB_HOST', 'localhost') . "\"\n");
        return $f;
    }

    /** @return array backup row */
    public static function backup($estateId, $kind = 'scheduled', $byId = null) {
        $estate = Tenancy::find($estateId);
        if (!$estate || empty($estate['db_name'])) throw new RuntimeException('Estate has no database to back up.');
        $dir = self::dir($estateId);
        $file = $dir . '/' . $estate['db_name'] . '_' . date('Ymd_His') . '_' . $kind . '.sql.gz';
        $opt = self::optionsFile($estate);
        $cmd = '"' . self::bin('mysqldump', 'mysqldump_path') . '" --defaults-extra-file="' . $opt . '" --single-transaction --routines --triggers --default-character-set=utf8mb4 ' . escapeshellarg($estate['db_name']) . ' 2>&1';
        $proc = popen($cmd, 'rb');
        if (!$proc) { @unlink($opt); throw new RuntimeException('Could not start mysqldump.'); }
        $gz = gzopen($file, 'wb9');
        gzwrite($gz, "-- ESTATE_BACKUP estate_id=" . intval($estateId) . " db=" . $estate['db_name'] . " created=" . date('c') . " kind=$kind\n");
        $bytes = 0; $head = '';
        while (!feof($proc)) { $chunk = fread($proc, 65536); if ($chunk === false || $chunk === '') continue; if (strlen($head) < 400) $head .= $chunk; gzwrite($gz, $chunk); $bytes += strlen($chunk); }
        $code = pclose($proc); gzclose($gz); @unlink($opt);
        if ($bytes < 200 || stripos($head, 'mysqldump: ') === 0 || stripos($head, 'error') === 0) {
            @unlink($file);
            Tenancy::logError('backup', 'Backup failed for estate ' . $estateId . ': ' . substr(trim($head), 0, 200), $estateId, 'error');
            throw new RuntimeException('Backup failed: ' . substr(trim($head), 0, 160));
        }
        $sum = hash_file('sha256', $file); $size = filesize($file); $rel = 'storage/backups/' . intval($estateId) . '/' . basename($file);
        $st = Tenancy::platform()->prepare("INSERT INTO backups (estate_id, db_name, file_path, size_bytes, checksum, kind, created_by) VALUES (?,?,?,?,?,?,?)");
        $st->bind_param('ississi', $estateId, $estate['db_name'], $rel, $size, $sum, $kind, $byId);
        $st->execute();
        $id = Tenancy::platform()->insert_id;
        Audit::log('backup.created', ['estate_id' => $estateId, 'module' => 'backup', 'entity_type' => 'backup', 'entity_id' => $id, 'details' => "$kind backup (" . round($size / 1024) . " KB)"]);
        return Tenancy::platform()->query("SELECT * FROM backups WHERE id = $id")->fetch_assoc();
    }

    /** Restore a backup into ITS OWN estate only. Takes a safety backup first. */
    public static function restore($backupId, $estateId, $byId = null) {
        $p = Tenancy::platform();
        $b = $p->query("SELECT * FROM backups WHERE id = " . intval($backupId) . " AND status = 'ok'")->fetch_assoc();
        $estate = Tenancy::find($estateId);
        if (!$b || !$estate) throw new RuntimeException('Backup or estate not found.');
        if (intval($b['estate_id']) !== intval($estateId) || $b['db_name'] !== $estate['db_name']) throw new RuntimeException('Refused: this backup belongs to a different estate and cannot be restored here.');
        $path = dirname(__DIR__) . '/' . $b['file_path'];
        if (!is_file($path) || hash_file('sha256', $path) !== $b['checksum']) throw new RuntimeException('Backup file is missing or failed its integrity check.');
        // verify the embedded header matches too
        $gz = gzopen($path, 'rb'); $first = gzgets($gz, 400); gzclose($gz);
        if (!preg_match('/estate_id=(\d+) db=(\S+)/', (string)$first, $m) || intval($m[1]) !== intval($estateId) || $m[2] !== $estate['db_name']) throw new RuntimeException('Refused: backup header does not match this estate.');

        self::backup($estateId, 'pre_restore', $byId);
        $db = $estate['db_name'];
        $viaCpanel = CpanelApi::enabled();
        if ($viaCpanel) {
            // No MySQL admin on shared hosting: empty the tenant database using the tenant's own user instead.
            $t = Tenancy::openTenant($estate);
            $t->query("SET FOREIGN_KEY_CHECKS=0");
            $r = $t->query("SELECT table_name, table_type FROM information_schema.tables WHERE table_schema = DATABASE()");
            while ($row = $r->fetch_row()) $t->query(($row[1] === 'VIEW' ? 'DROP VIEW `' : 'DROP TABLE `') . str_replace('`', '', $row[0]) . '`');
            $t->query("SET FOREIGN_KEY_CHECKS=1");
            $t->close();
        } else {
            $adm = Tenancy::admin();
            $adm->query("DROP DATABASE `$db`");
            $adm->query("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
        $tmp = tempnam(sys_get_temp_dir(), 'rst');
        $in = gzopen($path, 'rb'); $out = fopen($tmp, 'wb');
        while (!gzeof($in)) fwrite($out, gzread($in, 262144));
        gzclose($in); fclose($out);
        $opt = $viaCpanel ? self::optionsFile($estate) : tempnam(sys_get_temp_dir(), 'myc');
        if (!$viaCpanel) file_put_contents($opt, "[client]\nuser=\"" . env_get('DB_ADMIN_USER', env_get('DB_USER', 'root')) . "\"\npassword=\"" . env_get('DB_ADMIN_PASS', env_get('DB_PASS', '')) . "\"\nhost=\"" . env_get('DB_HOST', 'localhost') . "\"\n");
        $cmd = '"' . self::bin('mysql', 'mysql_path') . '" --defaults-extra-file="' . $opt . '" ' . escapeshellarg($db) . ' < "' . $tmp . '" 2>&1';
        $outp = shell_exec($cmd);
        @unlink($tmp); @unlink($opt);
        // privileges are tied to the DB name and survive DROP DATABASE; re-grant to be safe
        try { if (!$viaCpanel && $estate['db_user'] !== env_get('DB_USER', 'root')) $adm->query("GRANT ALL PRIVILEGES ON `$db`.* TO '{$estate['db_user']}'@'localhost'"); } catch (Throwable $e) {}
        Tenancy::mirror($estateId);
        Audit::log('backup.restored', ['estate_id' => $estateId, 'module' => 'backup', 'entity_type' => 'backup', 'entity_id' => $backupId, 'details' => 'Estate database restored from backup #' . $backupId . ($outp ? ' (' . substr(trim($outp), 0, 150) . ')' : '')]);
        return true;
    }

    public static function prune() {
        $days = max(1, PlatformSettings::int('backup_retention_days', 30));
        $p = Tenancy::platform();
        $r = $p->query("SELECT * FROM backups WHERE kind IN ('scheduled','pre_restore') AND status = 'ok' AND created_at < NOW() - INTERVAL $days DAY");
        $n = 0;
        while ($b = $r->fetch_assoc()) { @unlink(dirname(__DIR__) . '/' . $b['file_path']); $p->query("UPDATE backups SET status = 'deleted' WHERE id = " . intval($b['id'])); $n++; }
        return $n;
    }

    /** Central Admin data export: ZIP with one CSV per tenant table (system lookups included, secrets excluded). */
    public static function exportEstate($estateId, $byId = null, $byName = null) {
        if (!class_exists('ZipArchive')) throw new RuntimeException('ZIP support (php_zip) is not enabled on this server.');
        $estate = Tenancy::find($estateId);
        if (!$estate || empty($estate['db_name'])) throw new RuntimeException('Nothing to export.');
        $t = Tenancy::openTenant($estate);
        $skip = ['password_resets', 'schema_migrations'];
        $dir = dirname(__DIR__) . '/storage/exports'; self::dir($estateId);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $file = $dir . '/estate_' . intval($estateId) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($file, ZipArchive::CREATE);
        $tables = [];
        $r = $t->query("SHOW TABLES"); while ($row = $r->fetch_row()) $tables[] = $row[0];
        $tmpFiles = [];
        foreach ($tables as $tb) {
            if (in_array($tb, $skip, true)) continue;
            $res = $t->query("SELECT * FROM `$tb`");
            if (!$res) continue;
            $tmp = tempnam(sys_get_temp_dir(), 'exp'); $tmpFiles[] = $tmp;
            $o = fopen($tmp, 'w'); fwrite($o, "\xEF\xBB\xBF");
            $fields = array_map(fn($f) => $f->name, $res->fetch_fields());
            $secretCols = array_flip(array_filter($fields, fn($f) => preg_match('/^(password|token|secret|api_key)/i', $f)));
            fputcsv($o, array_diff($fields, array_keys($secretCols)));
            while ($row = $res->fetch_assoc()) {
                foreach ($secretCols as $c => $_) unset($row[$c]);
                if ($tb === 'system_settings' && preg_match('/(pass|secret|key|token)/i', (string)($row['setting_key'] ?? ''))) $row['setting_value'] = '[redacted]';
                fputcsv($o, array_map(fn($v) => (is_string($v) && $v !== '' && strpos('=+-@', $v[0]) !== false) ? "'" . $v : $v, $row));
            }
            fclose($o);
            $zip->addFile($tmp, $tb . '.csv');
        }
        $zip->addFromString('README.txt', "Data export for estate #{$estate['id']} ({$estate['name']}) generated " . date('c') . ".\nOne CSV per table. Passwords, tokens and gateway secrets are excluded.\n");
        $zip->close();
        foreach ($tmpFiles as $f) @unlink($f);
        $t->close();
        $rel = 'storage/exports/' . basename($file);
        $st = Tenancy::platform()->prepare("INSERT INTO data_exports (estate_id, requested_by, requested_by_name, file_path) VALUES (?,?,?,?)");
        $st->bind_param('iiss', $estateId, $byId, $byName, $rel);
        $st->execute();
        Audit::log('estate.data_exported', ['estate_id' => $estateId, 'module' => 'backup', 'details' => 'Estate data export generated']);
        return ['id' => Tenancy::platform()->insert_id, 'path' => $file];
    }
}
