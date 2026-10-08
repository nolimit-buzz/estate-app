<?php
// Tenant migration: Add user_agent to audit_logs for comprehensive tracking
return function ($db) {
    $class = new class {
        public static function up($db) {
            $result = $db->query("DESCRIBE audit_logs");
            $cols = array_column($result->fetch_all(MYSQLI_ASSOC), 'Field');

            if (!in_array('user_agent', $cols)) {
                $db->query("ALTER TABLE audit_logs ADD COLUMN user_agent VARCHAR(500) NULL AFTER ip_address");
                return true;
            }
            return false;
        }

        public static function down($db) {
            $db->query("ALTER TABLE audit_logs DROP COLUMN user_agent");
        }
    };

    return $class::up($db);
};
