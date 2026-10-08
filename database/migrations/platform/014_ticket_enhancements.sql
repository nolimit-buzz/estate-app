-- Support ticket enhancements: assignee, internal notes, attachments

-- Ensure support_tickets table has all required columns
ALTER TABLE support_tickets
ADD COLUMN IF NOT EXISTS assigned_to BIGINT NULL AFTER `status`,
ADD COLUMN IF NOT EXISTS internal_notes LONGTEXT NULL AFTER `priority`,
ADD COLUMN IF NOT EXISTS priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal' AFTER `category`,
ADD COLUMN IF NOT EXISTS sla_response_by TIMESTAMP NULL AFTER `updated_at`,
ADD COLUMN IF NOT EXISTS sla_resolution_by TIMESTAMP NULL AFTER `sla_response_by`,
ADD COLUMN IF NOT EXISTS first_response_at TIMESTAMP NULL AFTER `sla_resolution_by`,
ADD INDEX IF NOT EXISTS idx_assigned_to (assigned_to),
ADD INDEX IF NOT EXISTS idx_priority (priority),
ADD FOREIGN KEY (assigned_to) REFERENCES platform_users(id) ON DELETE SET NULL;

-- Ticket attachments table
CREATE TABLE IF NOT EXISTS ticket_attachments (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    ticket_id BIGINT NOT NULL,
    message_id BIGINT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(512) NOT NULL,
    file_size INT,
    mime_type VARCHAR(100),
    uploaded_by_type ENUM('estate', 'platform') NOT NULL,
    uploaded_by_id BIGINT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES ticket_messages(id) ON DELETE CASCADE,
    INDEX idx_ticket (ticket_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enhance ticket_messages for internal notes vs public replies
ALTER TABLE ticket_messages
ADD COLUMN IF NOT EXISTS is_internal BOOLEAN DEFAULT FALSE AFTER `author_type`,
ADD COLUMN IF NOT EXISTS edited_at TIMESTAMP NULL AFTER `created_at`,
ADD COLUMN IF NOT EXISTS edited_by_type ENUM('estate', 'platform') NULL AFTER `edited_at`,
ADD COLUMN IF NOT EXISTS edited_by_id BIGINT NULL AFTER `edited_by_type`,
ADD INDEX IF NOT EXISTS idx_internal (is_internal);

-- Add platform_users if not exists (for ticket assignment)
CREATE TABLE IF NOT EXISTS platform_users (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    password VARCHAR(255),
    role ENUM('superadmin', 'support', 'finance') DEFAULT 'support',
    status ENUM('active', 'inactive', 'disabled') DEFAULT 'active',
    force_password_change BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_role (role),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
