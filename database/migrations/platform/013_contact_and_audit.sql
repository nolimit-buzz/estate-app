-- Contact inquiries table for public contact form
CREATE TABLE IF NOT EXISTS contact_inquiries (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    subject VARCHAR(255) NOT NULL,
    message LONGTEXT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    status ENUM('new', 'read', 'responded', 'closed') DEFAULT 'new',
    responded_at TIMESTAMP NULL,
    response_message LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add trial user limit setting if not exists
INSERT IGNORE INTO platform_settings (setting_key, setting_value, value_type, group_name, label, description)
VALUES
    ('trial_user_limit', '5', 'int', 'trial', 'Trial User Limit', 'Maximum number of users allowed during trial period'),
    ('storage_limit_enforcement', '1', 'bool', 'billing', 'Enforce Storage Limits', 'Block uploads when storage limit is exceeded');
