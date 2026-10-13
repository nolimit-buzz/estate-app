<?php
// Support ticket workflow: internal notes, first-response tracking, attachments table.
// Idempotent replacement for the old 014_ticket_enhancements.sql (which was never applied and carried an unsafe FK).
return function (mysqli $db) {
    Migrator::addColumn($db, 'support_tickets', 'assigned_to', 'BIGINT NULL');
    Migrator::addColumn($db, 'support_tickets', 'priority', "ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal'");
    Migrator::addColumn($db, 'support_tickets', 'first_response_at', 'DATETIME NULL');
    Migrator::addColumn($db, 'support_tickets', 'closed_at', 'DATETIME NULL');
    Migrator::addColumn($db, 'ticket_messages', 'is_internal', 'TINYINT(1) NOT NULL DEFAULT 0');

    $idx = function ($table, $name, $cols) use ($db) {
        $r = $db->query("SHOW INDEX FROM `$table` WHERE Key_name = '" . $db->real_escape_string($name) . "'");
        if (!$r || !$r->num_rows) $db->query("ALTER TABLE `$table` ADD INDEX `$name` ($cols)");
    };
    $idx('support_tickets', 'idx_tickets_queue', 'status, priority');
    $idx('support_tickets', 'idx_tickets_assigned', 'assigned_to');
    $idx('ticket_messages', 'idx_msgs_ticket_internal', 'ticket_id, is_internal');

    $db->query("CREATE TABLE IF NOT EXISTS ticket_attachments (
        id BIGINT PRIMARY KEY AUTO_INCREMENT,
        ticket_id BIGINT NOT NULL,
        message_id BIGINT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(512) NOT NULL,
        file_size INT NULL,
        mime_type VARCHAR(100) NULL,
        uploaded_by_type ENUM('estate','platform') NOT NULL,
        uploaded_by_id BIGINT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_att_ticket (ticket_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
