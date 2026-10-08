<?php
return function (mysqli $db) {
    $rows = [
        ['trial_plan_slug', 'professional', 'string', 'lifecycle', 'Trial plan (slug)', 'Which plan\'s modules a trial estate can use.'],
        ['company_legal_footer', '', 'string', 'site', 'Email footer note', 'Optional legal line shown at the bottom of platform emails.'],
        ['allow_public_signup', '1', 'bool', 'lifecycle', 'Allow public estate sign-up', 'If off, only the Super Admin can create estates.'],
        ['module_pricing_public', '1', 'bool', 'site', 'Show module prices publicly', 'Display indicative module prices on the landing page.'],
        ['proration_enabled', '1', 'bool', 'billing', 'Prorate mid-cycle upgrades', 'Charge only the difference when upgrading mid-cycle.'],
    ];
    $st = $db->prepare("INSERT IGNORE INTO platform_settings (setting_key, setting_value, value_type, group_name, label, description, sort_order) VALUES (?,?,?,?,?,?,900)");
    foreach ($rows as $r) { $st->bind_param('ssssss', $r[0], $r[1], $r[2], $r[3], $r[4], $r[5]); $st->execute(); }
};
