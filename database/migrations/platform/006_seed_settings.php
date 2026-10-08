<?php
// Seeds platform_settings with DEFAULT values only. Every value is editable by the Super Admin.
return function (mysqli $db) {
    $rows = [
        // key, value, type, group, label, description
        ['grace_period_days', '15', 'int', 'billing', 'Payment grace period (days)', 'Days after a monthly payment due date during which the estate stays accessible with warnings (recommended 15-20).'],
        ['grace_applies_annual', '0', 'bool', 'billing', 'Apply grace period to annual plans', 'If off, annual subscriptions suspend on the due date.'],
        ['retention_days', '45', 'int', 'lifecycle', 'Data retention after suspension (days)', 'Estate data is preserved this long after suspension so the estate can reactivate.'],
        ['financial_retention_years', '7', 'int', 'lifecycle', 'Financial record retention (years)', 'Invoices, payments and audit records are kept this long even after an estate is deleted.'],
        ['trial_days', '14', 'int', 'lifecycle', 'Trial length (days)', 'Duration of a free trial before the estate is moved to Payment Pending.'],
        ['trial_enabled', '1', 'bool', 'lifecycle', 'Allow free trials', 'Show the trial option on the public sign-up.'],
        ['trial_resident_limit', '25', 'int', 'lifecycle', 'Trial resident limit', 'Maximum residents while on trial.'],
        ['reminder_days_before_due', '7,3,1', 'csv', 'billing', 'Renewal reminders (days before due)', 'Comma-separated days before the due date to email the Central Admin.'],
        ['grace_reminder_every_days', '5', 'int', 'billing', 'Grace-period reminder interval (days)', 'How often reminders are sent while in the grace period.'],
        ['suspension_warning_days', '2', 'int', 'billing', 'Final suspension warning (days before)', 'Days before the grace period ends to send the final suspension warning.'],
        ['retention_warning_days', '30,14,7', 'csv', 'lifecycle', 'Retention warnings (days remaining)', 'Warn the Central Admin when this many retention days remain.'],
        ['final_deletion_warning_days', '3', 'int', 'lifecycle', 'Final deletion warning (days before)', 'Last warning before an estate becomes eligible for permanent deletion.'],
        ['otp_expiry_minutes', '10', 'int', 'security', 'OTP expiry (minutes)', 'One-time passcodes expire after this many minutes.'],
        ['otp_max_attempts', '5', 'int', 'security', 'OTP max attempts', 'Failed OTP entries allowed before the code is invalidated.'],
        ['invitation_expiry_days', '7', 'int', 'security', 'Invitation expiry (days)', 'Invitation links expire after this many days.'],
        ['password_reset_expiry_minutes', '30', 'int', 'security', 'Password reset expiry (minutes)', 'Reset links/codes expire after this many minutes.'],
        ['login_max_attempts', '5', 'int', 'security', 'Login attempts before lockout', 'Consecutive failed logins before the account/IP is locked.'],
        ['login_lockout_minutes', '15', 'int', 'security', 'Lockout duration (minutes)', 'How long a lockout lasts.'],
        ['session_idle_minutes', '60', 'int', 'security', 'Session idle timeout (minutes)', 'Users are signed out after this much inactivity.'],
        ['session_absolute_hours', '12', 'int', 'security', 'Session maximum lifetime (hours)', 'Hard limit on a session regardless of activity.'],
        ['alert_failed_logins_threshold', '5', 'int', 'security', 'Security alert: failed logins', 'Raise an alert after this many failures from one IP/account.'],
        ['alert_new_ip_for_admins', '1', 'bool', 'security', 'Alert on new IP for admins', 'Raise an alert when an admin signs in from a new IP address.'],
        ['notify_email_enabled', '1', 'bool', 'notifications', 'Send platform emails', 'Master switch for lifecycle / billing emails.'],
        ['notify_super_admin_email', '', 'string', 'notifications', 'Super Admin alert email', 'Where critical system alerts are emailed (blank = off).'],
        ['backup_enabled', '1', 'bool', 'backup', 'Automatic backups', 'Nightly per-estate backups run by cron/backup.php.'],
        ['backup_retention_days', '30', 'int', 'backup', 'Backup retention (days)', 'Scheduled backups older than this are removed.'],
        ['export_enabled', '1', 'bool', 'backup', 'Allow estate data export', 'Central Admins can download their estate data.'],
        ['currency', 'NGN', 'string', 'billing', 'Billing currency', 'ISO currency code used on invoices.'],
        ['currency_symbol', '₦', 'string', 'billing', 'Currency symbol', 'Shown next to prices.'],
        ['invoice_due_days', '7', 'int', 'billing', 'Invoice payment window (days)', 'Days a newly issued invoice stays payable.'],
        ['site_company_name', 'Estate Platform', 'string', 'site', 'Company name', 'Shown on the public website and in emails.'],
        ['site_tagline', 'Modern estate management for secure, well-run communities.', 'string', 'site', 'Tagline', 'Hero headline support text.'],
        ['site_about', 'We build software that helps residential estates run smoothly - from visitor access and security to dues collection and community communication.', 'text', 'site', 'About the company', 'Company profile paragraph.'],
        ['site_services', 'Estate software|Security & gate technology|Onboarding & training|Managed support', 'csv', 'site', 'Services (separated by |)', 'Short list of company services.'],
        ['site_support_email', 'support@example.com', 'string', 'site', 'Support email', ''],
        ['site_support_phone', '+234 800 000 0000', 'string', 'site', 'Support phone', ''],
        ['site_address', 'Lagos, Nigeria', 'string', 'site', 'Office address', ''],
        ['site_website', '', 'string', 'site', 'Company website', ''],
        ['site_facebook', '', 'string', 'site', 'Facebook URL', ''],
        ['site_x', '', 'string', 'site', 'X / Twitter URL', ''],
        ['site_instagram', '', 'string', 'site', 'Instagram URL', ''],
        ['site_linkedin', '', 'string', 'site', 'LinkedIn URL', ''],
        ['site_support_hours', 'Mon-Fri, 8am - 6pm WAT', 'string', 'site', 'Support hours', ''],
    ];
    $st = $db->prepare("INSERT IGNORE INTO platform_settings (setting_key, setting_value, value_type, group_name, label, description, sort_order) VALUES (?,?,?,?,?,?,?)");
    foreach ($rows as $i => $r) {
        $o = ($i + 1) * 10;
        $st->bind_param('ssssssi', $r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $o);
        $st->execute();
    }
};
