<?php
// database/migrate_saas.php - Automated Multi-Tenant SaaS Schema Upgrader
require_once __DIR__ . '/../config.php';

echo "=== STARTING MULTI-TENANT SAAS MIGRATION ===\n";

// 1. Upgrade `estates` table columns
$estateCols = [
    'status' => "ENUM('active', 'suspended', 'trial', 'maintenance', 'expired') DEFAULT 'active' AFTER `domain_prefix`",
    'plan' => "ENUM('starter', 'growth', 'enterprise') DEFAULT 'starter' AFTER `status`",
    'subscription_expires_at' => "DATETIME NULL AFTER `plan`",
    'billing_cycle' => "ENUM('monthly', 'annual') DEFAULT 'monthly' AFTER `subscription_expires_at`",
    'contact_email' => "VARCHAR(150) NULL AFTER `billing_cycle`",
    'contact_phone' => "VARCHAR(30) NULL AFTER `contact_email`",
    'max_residents' => "INT DEFAULT 250 AFTER `contact_phone`",
    'max_guards' => "INT DEFAULT 15 AFTER `max_residents`",
    'custom_domain' => "VARCHAR(150) NULL AFTER `max_guards`"
];

foreach ($estateCols as $col => $definition) {
    $check = $conn->query("SHOW COLUMNS FROM `estates` LIKE '$col'");
    if ($check && $check->num_rows === 0) {
        $alterSql = "ALTER TABLE `estates` ADD COLUMN `$col` $definition";
        if ($conn->query($alterSql)) {
            echo " [OK] Added column `estates`.`$col`\n";
        } else {
            echo " [ERR] Failed to add column `$col`: " . $conn->error . "\n";
        }
    } else {
        echo " [SKIP] Column `estates`.`$col` already exists.\n";
    }
}

// 2. Create `estate_modules` feature flags table
$modTableSql = "CREATE TABLE IF NOT EXISTS `estate_modules` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `estate_id` INT NOT NULL,
  `module_key` VARCHAR(60) NOT NULL,
  `is_enabled` TINYINT(1) DEFAULT 1,
  `disabled_reason` VARCHAR(255) NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_estate_module` (`estate_id`, `module_key`),
  CONSTRAINT `fk_mod_estate` FOREIGN KEY (`estate_id`) REFERENCES `estates`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($modTableSql)) {
    echo " [OK] Table `estate_modules` created / verified.\n";
} else {
    echo " [ERR] Failed creating `estate_modules`: " . $conn->error . "\n";
}

// 3. Define Standard Platform Modules
$standardModules = [
    'visitor_passes'      => 'Visitor Management & Gate Pass Codes',
    'vehicle_registry'    => 'Vehicle Registry & RFID/Sticker Clearance',
    'billing_invoicing'   => 'Dues, Levies, Invoices & Paystack Checkout',
    'ussd_offline_sync'   => 'Africa\'s Talking USSD & Offline Terminal Sync',
    'security_patrol'     => 'Security Guard Roster & Gate Shifts',
    'emergency_sos'       => 'Emergency Panic SOS Alerts & Roster',
    'broadcast_messaging' => 'Community SMS, WhatsApp & Email Broadcasts',
    'artisan_marketplace' => 'Vetted Artisans & Handyman Directory',
    'bylaws_policies'     => 'Estate Bylaws, Petitions & Policy Violations',
    'zonal_divisions'     => 'Zonal Administrative Sub-Divisions'
];

// 4. Seed default module records for all existing estates
$estates = $conn->query("SELECT id, name FROM estates");
if ($estates) {
    while ($estate = $estates->fetch_assoc()) {
        $eid = intval($estate['id']);
        echo " -> Seeding modules for Estate #$eid ({$estate['name']})...\n";
        foreach ($standardModules as $mKey => $mTitle) {
            $checkM = $conn->query("SELECT id FROM `estate_modules` WHERE `estate_id` = $eid AND `module_key` = '$mKey'");
            if ($checkM && $checkM->num_rows === 0) {
                $insSql = "INSERT INTO `estate_modules` (`estate_id`, `module_key`, `is_enabled`, `disabled_reason`) 
                           VALUES ($eid, '$mKey', 1, NULL)";
                $conn->query($insSql);
                echo "    + Activated module '$mKey'\n";
            }
        }
    }
}

// 5. Ensure Super Admin Account Exists
$saRes = $conn->query("SELECT id FROM users WHERE role = 'superadmin' LIMIT 1");
if ($saRes && $saRes->num_rows === 0) {
    $saPass = password_hash('superadmin123', PASSWORD_DEFAULT);
    $insSa = "INSERT INTO users (estate_id, name, first_name, last_name, email, password, role, status) 
              VALUES (1, 'Super Administrator', 'Super', 'Administrator', 'superadmin@admin.com', '$saPass', 'superadmin', 'active')";
    if ($conn->query($insSa)) {
        echo " [OK] Created default Super Admin account (superadmin@admin.com / superadmin123)\n";
    }
} else {
    echo " [SKIP] Super Admin account already verified.\n";
}

echo "=== SAAS MIGRATION COMPLETED SUCCESSFULLY ===\n";
