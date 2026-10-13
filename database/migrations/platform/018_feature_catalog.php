<?php
// Grouped feature catalogue (Finance, Properties, Security & Incidents ...). Each feature is a tenant permission slug.
// Plans select features (fixed plan price, no per-module price); estates may only assign features their plan includes.
return function (mysqli $db) {
    $db->query("CREATE TABLE IF NOT EXISTS feature_groups (
        group_key VARCHAR(60) PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        icon VARCHAR(40) NOT NULL DEFAULT 'fa-cube',
        module_key VARCHAR(60) NULL,
        sort_order INT NOT NULL DEFAULT 100
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE IF NOT EXISTS features (
        slug VARCHAR(100) PRIMARY KEY,
        group_key VARCHAR(60) NOT NULL,
        name VARCHAR(150) NOT NULL,
        description VARCHAR(255) NULL,
        sort_order INT NOT NULL DEFAULT 100,
        INDEX idx_features_group (group_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE IF NOT EXISTS plan_features (
        plan_id BIGINT NOT NULL,
        feature_slug VARCHAR(100) NOT NULL,
        PRIMARY KEY (plan_id, feature_slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->query("CREATE TABLE IF NOT EXISTS estate_feature_overrides (
        estate_id BIGINT NOT NULL,
        feature_slug VARCHAR(100) NOT NULL,
        state ENUM('on','off') NOT NULL,
        reason VARCHAR(255) NULL,
        set_by BIGINT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (estate_id, feature_slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // group label => [icon, module_key gating the group (NULL = core, in every plan), order]
    $groups = [
        'Finance'               => ['fa-coins', 'billing_invoicing', 10],
        'Gate & Visitor Passes' => ['fa-shield-halved', 'visitor_passes', 20],
        'Maintenance'           => ['fa-hammer', 'maintenance_complaints', 30],
        'Properties'            => ['fa-building', 'property_management', 40],
        'Residents'             => ['fa-users', 'resident_management', 50],
        'Roster & Attendance'   => ['fa-calendar-check', 'security_patrol', 60],
        'Security & Incidents'  => ['fa-book-skull', 'security_patrol', 70],
        'Settings'              => ['fa-sliders', null, 80],
        'Staff'                 => ['fa-user-tie', 'staff_management', 90],
        'Visitors'              => ['fa-shield-halved', 'visitor_passes', 100],
        'Zones'                 => ['fa-cube', 'zonal_divisions', 110],
    ];
    $seed = json_decode(@file_get_contents(__DIR__ . '/../../tenant_seed.json'), true);
    $rows = $seed['permissions']['rows'] ?? [];

    $gs = $db->prepare("INSERT IGNORE INTO feature_groups (group_key, name, icon, module_key, sort_order) VALUES (?,?,?,?,?)");
    foreach ($groups as $label => [$icon, $mod, $ord]) {
        $key = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $label), '_'));
        $gs->bind_param('ssssi', $key, $label, $icon, $mod, $ord);
        $gs->execute();
    }
    $fs = $db->prepare("INSERT IGNORE INTO features (slug, group_key, name, description, sort_order) VALUES (?,?,?,?,?)");
    $i = 0;
    foreach ($rows as $r) {
        [, $name, $slug, $group, $desc] = $r;
        $key = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $group), '_'));
        if (!isset($groups[$group])) {
            $gk = $db->prepare("INSERT IGNORE INTO feature_groups (group_key, name, icon, module_key, sort_order) VALUES (?,?,'fa-cube',NULL,200)");
            $gk->bind_param('ss', $key, $group); $gk->execute();
        }
        $i += 10;
        $fs->bind_param('ssssi', $slug, $key, $name, $desc, $i);
        $fs->execute();
    }

    // Backfill: every plan gets the features of groups whose module it already includes (+ core groups).
    $db->query("INSERT IGNORE INTO plan_features (plan_id, feature_slug)
        SELECT pm.plan_id, f.slug FROM plan_modules pm
        JOIN feature_groups g ON g.module_key = pm.module_key COLLATE utf8mb4_unicode_ci
        JOIN features f ON f.group_key = g.group_key
        WHERE pm.inclusion = 'included'");
    $db->query("INSERT IGNORE INTO plan_features (plan_id, feature_slug)
        SELECT p.id, f.slug FROM plans p
        JOIN feature_groups g ON g.module_key IS NULL
        JOIN features f ON f.group_key = g.group_key");
};
