<?php
// Tenant DB: widen mirrored-estate enums, add verification/invitation columns to users.
return function (mysqli $db) {
    if (Migrator::tableExists($db, 'estates')) {
        $db->query("ALTER TABLE estates MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'active'");
        $db->query("ALTER TABLE estates MODIFY COLUMN plan VARCHAR(80) NULL");
        $db->query("ALTER TABLE estates MODIFY COLUMN billing_cycle VARCHAR(20) NULL");
        Migrator::addColumn($db, 'estates', 'address', 'VARCHAR(255) NULL');
    }
    if (Migrator::tableExists($db, 'users')) {
        $db->query("ALTER TABLE users MODIFY COLUMN status ENUM('active','disabled','suspended','pending','invited') NOT NULL DEFAULT 'active'");
        Migrator::addColumn($db, 'users', 'email_verified_at', 'DATETIME NULL');
        Migrator::addColumn($db, 'users', 'invited_by', 'INT NULL');
        Migrator::addColumn($db, 'users', 'last_login_at', 'DATETIME NULL');
        Migrator::addColumn($db, 'users', 'last_login_ip', 'VARCHAR(45) NULL');
        Migrator::addColumn($db, 'users', 'force_password_change', 'TINYINT(1) NOT NULL DEFAULT 0');
        Migrator::addColumn($db, 'users', 'password_changed_at', 'DATETIME NULL');
    }
};
