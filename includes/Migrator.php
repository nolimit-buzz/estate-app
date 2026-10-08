<?php
// includes/Migrator.php - tiny idempotent migration runner for platform and tenant databases.
// Files: NNN_name.sql (multi-statement SQL) or NNN_name.php (returns function(mysqli $db)).
class Migrator {
    public static function ensureTable(mysqli $db) {
        $db->query("CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(150) PRIMARY KEY,
            applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** Apply all pending migrations from $dir. Returns list of applied names. */
    public static function run(mysqli $db, $dir, $log = null) {
        self::ensureTable($db);
        $done = [];
        $r = $db->query("SELECT migration FROM schema_migrations");
        while ($r && $row = $r->fetch_assoc()) $done[$row['migration']] = true;

        $files = array_merge(glob(rtrim($dir, '/\\') . '/*.sql') ?: [], glob(rtrim($dir, '/\\') . '/*.php') ?: []);
        usort($files, fn($a, $b) => strcmp(basename($a), basename($b)));
        $applied = [];
        foreach ($files as $f) {
            $name = basename($f);
            if (isset($done[$name])) continue;
            if (substr($f, -4) === '.sql') {
                $sql = file_get_contents($f);
                if ($db->multi_query($sql)) {
                    do { if ($res = $db->store_result()) $res->free(); } while ($db->more_results() && $db->next_result());
                }
                if ($db->errno) throw new RuntimeException("Migration $name failed: " . $db->error);
            } else {
                $fn = require $f;
                if (is_callable($fn)) $fn($db);
            }
            $st = $db->prepare("INSERT INTO schema_migrations (migration) VALUES (?)");
            $st->bind_param('s', $name);
            $st->execute();
            $applied[] = $name;
            if ($log) $log("applied $name");
        }
        return $applied;
    }

    public static function columnExists(mysqli $db, $table, $col) {
        $r = $db->query("SHOW COLUMNS FROM `$table` LIKE '" . $db->real_escape_string($col) . "'");
        return $r && $r->num_rows > 0;
    }
    public static function tableExists(mysqli $db, $table) {
        $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
        return $r && $r->num_rows > 0;
    }
    public static function addColumn(mysqli $db, $table, $col, $definition) {
        if (!self::tableExists($db, $table)) return;
        if (!self::columnExists($db, $table, $col)) $db->query("ALTER TABLE `$table` ADD COLUMN `$col` $definition");
    }
}
