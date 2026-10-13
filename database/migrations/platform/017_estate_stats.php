<?php
// Cached per-tenant live statistics + database health (refreshed by TenantStats).
return function (mysqli $db) {
    $db->query("CREATE TABLE IF NOT EXISTS estate_stats (
        estate_id BIGINT PRIMARY KEY,
        residents INT NOT NULL DEFAULT 0,
        staff INT NOT NULL DEFAULT 0,
        admins INT NOT NULL DEFAULT 0,
        open_maintenance INT NOT NULL DEFAULT 0,
        unpaid_invoices INT NOT NULL DEFAULT 0,
        last_login_at DATETIME NULL,
        db_ok TINYINT(1) NOT NULL DEFAULT 1,
        db_error VARCHAR(255) NULL,
        checked_at DATETIME NOT NULL,
        INDEX idx_stats_checked (checked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
