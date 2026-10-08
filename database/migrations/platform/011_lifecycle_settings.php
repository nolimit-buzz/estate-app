<?php
return function (mysqli $db) {
    $st = $db->prepare("INSERT IGNORE INTO platform_settings (setting_key, setting_value, value_type, group_name, label, description, sort_order) VALUES (?,?,?,?,?,?,960)");
    foreach ([
        ['suspension_hold_days', '7', 'int', 'lifecycle', 'Suspended hold period (days)', 'Days an estate stays in the Suspended state (support outreach) before moving to Retention. Total preservation is still governed by the retention period.'],
        ['renewal_invoice_days', '10', 'int', 'billing', 'Issue renewal invoice (days before due)', 'Renewal invoices are generated this many days before the period ends.'],
        ['mysqldump_path', '', 'string', 'backup', 'mysqldump path (optional)', 'Leave blank to auto-detect (XAMPP/system PATH).'],
        ['mysql_path', '', 'string', 'backup', 'mysql client path (optional)', 'Leave blank to auto-detect (XAMPP/system PATH).'],
        ['deletion_requires_second_admin', '0', 'bool', 'lifecycle', 'Deletion needs a second super admin', 'The admin who requests permanent deletion cannot also approve it.'],
    ] as $r) { $st->bind_param('ssssss', $r[0], $r[1], $r[2], $r[3], $r[4], $r[5]); $st->execute(); }
};
