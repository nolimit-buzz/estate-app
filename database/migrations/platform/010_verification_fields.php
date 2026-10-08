<?php
return function (mysqli $db) {
    Migrator::addColumn($db, 'email_verifications', 'needs_password', 'TINYINT(1) NOT NULL DEFAULT 0');
    Migrator::addColumn($db, 'email_verifications', 'payload_json', 'TEXT NULL');
    Migrator::addColumn($db, 'estates', 'onboarding_json', 'TEXT NULL');
    Migrator::addColumn($db, 'estates', 'created_via', "VARCHAR(20) NOT NULL DEFAULT 'public'");
    Migrator::addColumn($db, 'estates', 'central_admin_email', 'VARCHAR(191) NULL');
    $st = $db->prepare("INSERT IGNORE INTO platform_settings (setting_key, setting_value, value_type, group_name, label, description, sort_order) VALUES (?,?,?,?,?,?,950)");
    foreach ([
        ['unverified_cleanup_days', '7', 'int', 'lifecycle', 'Remove unverified sign-ups after (days)', 'Estates whose Central Admin never verified their email are removed after this many days.'],
        ['signup_rate_limit_per_hour', '5', 'int', 'security', 'Sign-ups per IP per hour', 'Throttle for the public registration form.'],
        ['min_password_length', '10', 'int', 'security', 'Minimum password length', 'Applies to Central Admin registration, reset and invitation acceptance.'],
    ] as $r) { $st->bind_param('ssssss', $r[0], $r[1], $r[2], $r[3], $r[4], $r[5]); $st->execute(); }
};
