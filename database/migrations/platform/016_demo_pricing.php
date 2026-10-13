<?php
// Add demo pricing configuration
return function ($db) {
    // Add demo pricing columns to modules
    $result = $db->query("DESCRIBE modules");
    $cols = array_column($result->fetch_all(MYSQLI_ASSOC), 'Field');

    if (!in_array('demo_price_monthly', $cols)) {
        $db->query("ALTER TABLE modules ADD COLUMN demo_price_monthly DECIMAL(10,2) NULL AFTER `default_price`");
        $db->query("ALTER TABLE modules ADD COLUMN demo_price_annual DECIMAL(10,2) NULL AFTER `demo_price_monthly`");
    }

    // Add demo pricing settings
    $settings = [
        ['demo_pricing_enabled', '1', 'bool', 'demo', 'Enable Demo Pricing', 'Display module pricing on landing page for demo purposes'],
        ['demo_trial_duration_days', '14', 'int', 'demo', 'Demo Trial Duration', 'Number of days for demo/trial period'],
        ['demo_resident_limit', '25', 'int', 'demo', 'Demo Resident Limit', 'Maximum residents allowed during demo'],
        ['demo_user_limit', '5', 'int', 'demo', 'Demo User Limit', 'Maximum staff/admin users during demo'],
    ];

    foreach ($settings as $s) {
        $res = $db->query("SELECT setting_key FROM platform_settings WHERE setting_key = '{$s[0]}' LIMIT 1");
        if (!$res || $res->num_rows === 0) {
            $db->query("INSERT INTO platform_settings (setting_key, setting_value, value_type, group_name, label, description) VALUES ('{$s[0]}', '{$s[1]}', '{$s[2]}', '{$s[3]}', '{$s[4]}', '{$s[5]}')");
        }
    }

    // Create demo_estates tracking table
    $db->query("CREATE TABLE IF NOT EXISTS demo_estates (
        id BIGINT PRIMARY KEY AUTO_INCREMENT,
        estate_id INT NOT NULL UNIQUE,
        demo_start_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        demo_expires_at TIMESTAMP NULL DEFAULT NULL,
        conversion_status ENUM('pending', 'converted', 'expired') DEFAULT 'pending',
        converted_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (estate_id) REFERENCES estates(id) ON DELETE CASCADE,
        INDEX idx_status (conversion_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    return true;
};
