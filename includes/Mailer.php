<?php
// includes/Mailer.php - Core Estate Mailer & Notification Service
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/WhatsApp.php';
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

class EstateMailer {
    private static $initialized = false;

    /**
     * Auto-ensure required database tables and columns exist
     */
    public static function ensureDatabaseTables($conn) {
        if (self::$initialized) return;
        self::$initialized = true;

        if (!$conn || !($conn instanceof mysqli)) return;

        // Ensure WhatsApp tables and configuration
        EstateWhatsApp::ensureDatabaseTables($conn);

        // 1. Email Logs Table
        $conn->query("CREATE TABLE IF NOT EXISTS email_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            estate_id INT DEFAULT 1,
            recipient_email VARCHAR(191) NOT NULL,
            recipient_name VARCHAR(191) NULL,
            subject VARCHAR(255) NOT NULL,
            template VARCHAR(64) NOT NULL,
            reference_id VARCHAR(64) NULL,
            status ENUM('sent', 'failed') NOT NULL DEFAULT 'sent',
            error_message TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (estate_id),
            INDEX (recipient_email),
            INDEX (status),
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 2. Add email column to visitors table if not present
        $col_check = $conn->query("SHOW COLUMNS FROM visitors LIKE 'email'");
        if ($col_check && $col_check->num_rows == 0) {
            $conn->query("ALTER TABLE visitors ADD COLUMN email VARCHAR(191) NULL AFTER phone");
        }

        // 3. Password Resets Table
        $conn->query("CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            estate_id INT DEFAULT 1,
            email VARCHAR(191) NOT NULL,
            code VARCHAR(10) NOT NULL,
            token VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (email),
            INDEX (token),
            INDEX (code),
            INDEX (expires_at),
            INDEX (used)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 4. Ensure Users security & status columns exist
        $col_status = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
        if ($col_status && $col_status->num_rows == 0) {
            $conn->query("ALTER TABLE users ADD COLUMN status ENUM('active', 'disabled', 'suspended') NOT NULL DEFAULT 'active' AFTER role");
        }

        $col_force = $conn->query("SHOW COLUMNS FROM users LIKE 'force_password_change'");
        if ($col_force && $col_force->num_rows == 0) {
            $conn->query("ALTER TABLE users ADD COLUMN force_password_change TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
        }

        $col_pwd_chg = $conn->query("SHOW COLUMNS FROM users LIKE 'password_changed_at'");
        if ($col_pwd_chg && $col_pwd_chg->num_rows == 0) {
            $conn->query("ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL AFTER force_password_change");
        }

        // 5. Ensure estate_penalties table exists
        $conn->query("CREATE TABLE IF NOT EXISTS estate_penalties (
            id INT AUTO_INCREMENT PRIMARY KEY,
            estate_id INT NOT NULL DEFAULT 1,
            zone_id INT NULL,
            penalty_ref VARCHAR(50) NOT NULL,
            policy_id INT NULL,
            incident_id INT NULL,
            recipient_type ENUM('resident', 'artisan', 'visitor', 'staff', 'other') NOT NULL DEFAULT 'resident',
            user_id INT NULL,
            artisan_id INT NULL,
            visitor_id INT NULL,
            recipient_name VARCHAR(150) NOT NULL,
            recipient_phone VARCHAR(50) NULL,
            recipient_email VARCHAR(191) NULL,
            property_or_unit VARCHAR(150) NULL,
            vehicle_plate VARCHAR(50) NULL,
            violation_title VARCHAR(200) NOT NULL,
            violation_code VARCHAR(50) NULL,
            violation_details TEXT NOT NULL,
            offence_date DATETIME NOT NULL,
            location VARCHAR(150) NULL,
            punishment_type VARCHAR(50) NOT NULL DEFAULT 'fine',
            fine_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            due_date DATE NULL,
            invoice_id INT NULL,
            status ENUM('pending', 'paid', 'waived', 'disputed', 'escalated') NOT NULL DEFAULT 'pending',
            notes TEXT NULL,
            issued_by INT NULL,
            issued_by_name VARCHAR(150) NULL,
            email_dispatched TINYINT(1) DEFAULT 0,
            whatsapp_dispatched TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (estate_id),
            INDEX (penalty_ref),
            INDEX (user_id),
            INDEX (artisan_id),
            INDEX (status),
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 6. Ensure system_settings default keys exist for current estate
        $estate_id = get_estate_id();
        $default_settings = [
            'smtp_enabled' => '1',
            'smtp_host' => 'estate.nolimitbuzz.com.ng',
            'smtp_port' => '465',
            'smtp_encryption' => 'ssl', // 'tls', 'ssl', or 'none'
            'smtp_user' => 'estate@estate.nolimitbuzz.com.ng',
            'smtp_pass' => '%LH},GPb;%6f{LS@',
            'smtp_from_name' => 'No limit Buzz',
            'smtp_from_email' => 'estate@estate.nolimitbuzz.com.ng',
            'smtp_reply_to' => 'estate@estate.nolimitbuzz.com.ng',
            'notify_on_invoice' => '1',
            'notify_on_receipt' => '1',
            'notify_on_visitor_pass' => '1',
            'notify_on_visitor_arrival' => '1',
            'notify_on_welcome' => '1',
            'notify_on_penalty' => '1',
            'notify_on_artisan_pass' => '1',
            'require_resident_visitor_confirmation' => '1'
        ];

        foreach ($default_settings as $k => $v) {
            $chk = $conn->query("SELECT 1 FROM system_settings WHERE estate_id = $estate_id AND setting_key = '$k' LIMIT 1");
            if (!$chk || $chk->num_rows == 0) {
                $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES ($estate_id, '$k', '$v')");
            }
        }
    }

    /**
     * Resolve fully qualified Base Application URL for links inside emails
     */
    public static function getBaseUrl() {
        if (isset($_SERVER['HTTP_HOST'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'];
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            // Locate /Estate/ or subfolder
            if (stripos($script, '/Estate/') !== false) {
                $pos = stripos($script, '/Estate/');
                $sub = substr($script, 0, $pos + 8);
                return $protocol . $host . $sub;
            } else {
                $dir = dirname($script);
                $dir = ($dir === '/' || $dir === '\\') ? '/' : rtrim(str_replace('\\', '/', $dir), '/') . '/';
                return $protocol . $host . $dir;
            }
        }
        return 'http://localhost/Estate/';
    }

    /**
     * Retrieve all Estate branding and configuration details
     */
    public static function getEstateSettings($conn, $estate_id = null) {
        if (!$estate_id) $estate_id = get_estate_id();
        self::ensureDatabaseTables($conn);

        $settings = [];
        $res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE estate_id = $estate_id");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $settings[$r['setting_key']] = $r['setting_value'];
            }
        }

        // Estate table info if available
        $estate_row = null;
        $est_chk = $conn->query("SELECT * FROM estates WHERE id = $estate_id LIMIT 1");
        if ($est_chk && $est_chk->num_rows > 0) {
            $estate_row = $est_chk->fetch_assoc();
        }

        $base_url = self::getBaseUrl();
        $logo_path = $settings['estate_logo'] ?? '';
        $full_logo_url = '';
        if (!empty($logo_path)) {
            $logo_clean = ltrim(str_replace('../', '', $logo_path), '/');
            $full_logo_url = $base_url . $logo_clean;
        }

        return [
            'estate_id' => $estate_id,
            'estate_name' => $settings['estate_name'] ?? ($estate_row['name'] ?? 'Estate Management'),
            'estate_location' => $settings['estate_location'] ?? ($estate_row['address'] ?? ''),
            'estate_motto' => $settings['estate_motto'] ?? 'Excellence in Living & Secure Community',
            'theme_color' => !empty($settings['theme_color']) ? $settings['theme_color'] : '#2563eb',
            'currency_symbol' => $settings['currency_symbol'] ?? '₦',
            'office_email' => $settings['office_email'] ?? 'support@estate.com',
            'office_phone' => $settings['office_phone'] ?? '+234 800 000 0000',
            'estate_logo_url' => $full_logo_url,
            'smtp_enabled' => ($settings['smtp_enabled'] ?? '0') === '1',
            'smtp_host' => $settings['smtp_host'] ?? '',
            'smtp_port' => intval($settings['smtp_port'] ?? 587),
            'smtp_encryption' => strtolower($settings['smtp_encryption'] ?? 'tls'),
            'smtp_user' => $settings['smtp_user'] ?? '',
            'smtp_pass' => $settings['smtp_pass'] ?? '',
            'smtp_from_name' => !empty($settings['smtp_from_name']) ? $settings['smtp_from_name'] : ($settings['estate_name'] ?? 'Estate Admin'),
            'smtp_from_email' => !empty($settings['smtp_from_email']) ? $settings['smtp_from_email'] : ($settings['office_email'] ?? 'noreply@estate.com'),
            'smtp_reply_to' => !empty($settings['smtp_reply_to']) ? $settings['smtp_reply_to'] : ($settings['office_email'] ?? ''),
            'notify_on_invoice' => ($settings['notify_on_invoice'] ?? '1') === '1',
            'notify_on_receipt' => ($settings['notify_on_receipt'] ?? '1') === '1',
            'notify_on_visitor_pass' => ($settings['notify_on_visitor_pass'] ?? '1') === '1',
            'notify_on_visitor_arrival' => ($settings['notify_on_visitor_arrival'] ?? '1') === '1',
            'notify_on_welcome' => ($settings['notify_on_welcome'] ?? '1') === '1',
            'notify_on_penalty' => ($settings['notify_on_penalty'] ?? '1') === '1',
            'notify_on_artisan_pass' => ($settings['notify_on_artisan_pass'] ?? '1') === '1',
            'base_url' => $base_url
        ];
    }

    /**
     * Send Custom HTML Email (used for artisan onboarding, verification, custom system alerts)
     */
    public static function sendCustomEmail($conn, $toEmail = '', $toName = '', $subject = '', $htmlContent = '', $template = 'custom', $refId = null, $estate_id = null) {
        // If called without $conn as first argument
        if (!($conn instanceof mysqli)) {
            $args = func_get_args();
            $toEmail = $args[0] ?? '';
            $toName = $args[1] ?? '';
            $subject = $args[2] ?? '';
            $htmlContent = $args[3] ?? '';
            $template = $args[4] ?? 'custom';
            $refId = $args[5] ?? null;
            $estate_id = $args[6] ?? null;
            global $conn;
        }

        if (!$conn) return false;

        $estate_info = self::getEstateSettings($conn, $estate_id);
        $estate_id = $estate_info['estate_id'];

        if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            self::logEmail($conn, $estate_id, $toEmail, $toName, $subject, $template, $refId, 'failed', 'Invalid recipient email address');
            return false;
        }

        // Auto-wrap in standard responsive layout if not already a styled box or HTML document
        $finalHtml = $htmlContent;
        if (stripos($htmlContent, '<html') === false && stripos($htmlContent, '<!doctype') === false) {
            if (stripos($htmlContent, 'max-width') === false && stripos($htmlContent, 'email-container') === false) {
                $preheader = substr(strip_tags($htmlContent), 0, 100);
                $finalHtml = self::wrapTemplate($preheader, $htmlContent, $estate_info);
            }
        }

        return self::sendMail($toEmail, $toName, $subject, $finalHtml, $template, $refId, $estate_id);
    }

    /**
     * Core Low-Level Email Dispatcher using PHPMailer
     */
    public static function sendMail($toEmail, $toName = '', $subject = '', $htmlContent = '', $template = 'general', $refId = null, $estate_id = null) {
        global $conn;
        // Allow calling sendMail($conn, $toEmail, $toName, ...) gracefully
        if ($toEmail instanceof mysqli) {
            $conn = $toEmail;
            $args = func_get_args();
            $toEmail = $args[1] ?? '';
            $toName = $args[2] ?? '';
            $subject = $args[3] ?? '';
            $htmlContent = $args[4] ?? '';
            $template = $args[5] ?? 'general';
            $refId = $args[6] ?? null;
            $estate_id = $args[7] ?? null;
        }

        if (!$conn) return false;

        $estate_info = self::getEstateSettings($conn, $estate_id);
        $estate_id = $estate_info['estate_id'];

        if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            self::logEmail($conn, $estate_id, $toEmail, $toName, $subject, $template, $refId, 'failed', 'Invalid recipient email address');
            return false;
        }

        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';

        try {
            if ($estate_info['smtp_enabled'] && !empty($estate_info['smtp_host'])) {
                // SMTP Transport
                $mail->isSMTP();
                $mail->Host = $estate_info['smtp_host'];
                $mail->SMTPAuth = !empty($estate_info['smtp_user']);
                $mail->Username = $estate_info['smtp_user'];
                $mail->Password = $estate_info['smtp_pass'];
                $mail->Port = $estate_info['smtp_port'];

                if ($estate_info['smtp_encryption'] === 'ssl') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                } elseif ($estate_info['smtp_encryption'] === 'tls') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                } else {
                    $mail->SMTPSecure = false;
                    $mail->SMTPAutoTLS = false;
                }

                $mail->Timeout = 6;
                if (function_exists('set_time_limit')) {
                    @set_time_limit(60);
                }
                // SSL verification options for local dev/self-signed certs
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    ]
                ];
            } else {
                // Fallback to PHP native mail()
                $mail->isMail();
            }

            // Sender
            $fromEmail = !empty($estate_info['smtp_from_email']) ? $estate_info['smtp_from_email'] : 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $fromName = !empty($estate_info['smtp_from_name']) ? $estate_info['smtp_from_name'] : $estate_info['estate_name'];
            $mail->setFrom($fromEmail, $fromName);

            if (!empty($estate_info['smtp_reply_to'])) {
                $mail->addReplyTo($estate_info['smtp_reply_to'], $fromName);
            }

            // Recipient
            $mail->addAddress($toEmail, $toName ?: $toEmail);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlContent;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<p>', '</p>', '</tr>'], "\n", $htmlContent));

            $mail->send();
            self::logEmail($conn, $estate_id, $toEmail, $toName, $subject, $template, $refId, 'sent', null);
            return true;
        } catch (Exception $e) {
            $errorMsg = $mail->ErrorInfo ?: $e->getMessage();
            self::logEmail($conn, $estate_id, $toEmail, $toName, $subject, $template, $refId, 'failed', $errorMsg);
            return false;
        }
    }

    /**
     * Record dispatch in audit table email_logs
     */
    private static function logEmail($conn, $estate_id, $email, $name, $subject, $template, $refId, $status, $error) {
        $email = $conn->real_escape_string($email ?: '');
        $name = $conn->real_escape_string($name ?: '');
        $subject = $conn->real_escape_string($subject ?: '');
        $template = $conn->real_escape_string($template ?: 'general');
        $refId = $conn->real_escape_string($refId ?: '');
        $status = $conn->real_escape_string($status);
        $error = $conn->real_escape_string($error ?: '');

        $conn->query("INSERT INTO email_logs (estate_id, recipient_email, recipient_name, subject, template, reference_id, status, error_message, created_at)
                      VALUES ($estate_id, '$email', '$name', '$subject', '$template', '$refId', '$status', " . ($error ? "'$error'" : "NULL") . ", NOW())");
    }

    /**
     * Universal Responsive Email Wrapper (Mature Executive Corporate Aesthetic)
     */
    public static function wrapTemplate($preheader, $bodyContent, $estate_info) {
        $name = htmlspecialchars($estate_info['estate_name'] ?? 'Estate Management');
        $motto = htmlspecialchars($estate_info['estate_motto'] ?? '');
        $logo = $estate_info['estate_logo_url'] ?? ($estate_info['logo_url'] ?? '');
        $phone = htmlspecialchars($estate_info['office_phone'] ?? '');
        $email = htmlspecialchars($estate_info['office_email'] ?? ($estate_info['support_email'] ?? ''));
        $location = htmlspecialchars($estate_info['estate_location'] ?? '');
        $year = date('Y');
        $now_str = date('M d, Y h:i A');

        $logoHtml = !empty($logo) 
            ? "<img src='$logo' alt='$name' style='max-height:40px;max-width:180px;display:block;border-radius:4px;margin-bottom:6px;'>" 
            : "<div style='font-size:19px;font-weight:700;color:#0f172a;letter-spacing:-0.02em;'>$name</div>";

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$name</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
        @media only screen and (max-width: 620px) {
            .email-container { width: 100% !important; margin: auto !important; border-radius: 0 !important; }
            .content-padding { padding: 24px 20px !important; }
            .header-padding { padding: 20px 20px 16px 20px !important; }
            .btn-block { display: block !important; width: 100% !important; text-align: center !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;color:#334155;">
    <!-- Preheader for preview text in email clients -->
    <div style="display:none;font-size:1px;color:#f8fafc;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;">
        $preheader
    </div>

    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f8fafc;padding:32px 0 48px 0;">
        <tr>
            <td align="center">
                <table border="0" cellpadding="0" cellspacing="0" width="600" class="email-container" style="background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 4px 12px rgba(15,23,42,0.04);">
                    
                    <!-- Top Accent Bar -->
                    <tr>
                        <td style="height:3px;background-color:#0f172a;line-height:3px;font-size:3px;">&nbsp;</td>
                    </tr>

                    <!-- Executive Header -->
                    <tr>
                        <td class="header-padding" align="left" style="background:#ffffff;padding:26px 36px 20px 36px;border-bottom:1px solid #f1f5f9;">
                            $logoHtml
                            <div style="color:#64748b;font-size:11px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;margin-top:4px;">$motto &bull; Estate Administration</div>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td class="content-padding" style="padding:32px 36px;background-color:#ffffff;font-size:14px;line-height:1.6;color:#334155;">
                            $bodyContent
                        </td>
                    </tr>

                    <!-- Corporate Footer -->
                    <tr>
                        <td style="background-color:#f8fafc;padding:24px 36px;border-top:1px solid #e2e8f0;text-align:left;font-size:12px;color:#64748b;line-height:1.6;">
                            <div style="font-weight:700;color:#0f172a;font-size:13px;margin-bottom:2px;">$name &bull; Central Operations Directorate</div>
                            <div>$location</div>
                            <div style="margin-top:6px;color:#475569;">
                                Tel: <strong style="color:#0f172a;">$phone</strong> &bull; Support: <a href="mailto:$email" style="color:#0f172a;text-decoration:underline;font-weight:600;">$email</a>
                            </div>
                            <div style="margin-top:16px;padding-top:12px;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;line-height:1.5;">
                                CONFIDENTIALITY &amp; SYSTEM AUTOMATION NOTICE: This electronic transmission was generated automatically by the official estate management portal on $now_str. It contains authorized and privileged information intended strictly for the specified recipient. If you have received this transmission in error, please notify estate administration immediately and delete all copies.
                                <div style="margin-top:6px;">&copy; $year $name. All rights reserved.</div>
                            </div>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }

    // =========================================================================
    // HIGH-LEVEL NOTIFICATION TRIGGERS
    // =========================================================================

    /**
     * 1. Send Invoice Notification Email (Mature Corporate Aesthetic)
     */
    public static function sendInvoiceEmail($conn, $invoice_id) {
        $estate_info = self::getEstateSettings($conn);
        if (!$estate_info['notify_on_invoice']) return false;

        $invoice_id = intval($invoice_id);
        $query = "SELECT inv.*, u.name as resident_name, u.email as resident_email, u.phone as resident_phone,
                         f.number as flat_number, b.name as building_name
                  FROM invoices inv
                  JOIN users u ON inv.user_id = u.id
                  LEFT JOIN residents r ON r.user_id = u.id AND r.estate_id = inv.estate_id
                  LEFT JOIN flats f ON r.flat_id = f.id
                  LEFT JOIN buildings b ON f.building_id = b.id
                  WHERE inv.id = $invoice_id LIMIT 1";

        $res = $conn->query($query);
        if (!$res || $res->num_rows === 0) return false;
        $inv = $res->fetch_assoc();

        if (empty($inv['resident_email'])) return false;

        $curr = $estate_info['currency_symbol'];
        $amount_fmt = $curr . number_format($inv['amount'], 2);
        $balance_fmt = $curr . number_format($inv['balance'], 2);
        $due_date_fmt = !empty($inv['due_date']) ? date('M d, Y', strtotime($inv['due_date'])) : 'On Presentation';
        $issue_date_fmt = !empty($inv['issue_date']) ? date('M d, Y', strtotime($inv['issue_date'])) : date('M d, Y');
        $inv_no = htmlspecialchars($inv['invoice_number'] ?: ('INV-' . $inv['id']));
        $pay_url = $estate_info['base_url'] . 'pay_invoice.php?inv=' . urlencode($inv_no);

        $unit_str = !empty($inv['flat_number']) ? ("Flat " . htmlspecialchars($inv['flat_number']) . ", " . htmlspecialchars($inv['building_name'] ?? '')) : 'Resident Unit';

        $body = <<<HTML
        <div style="margin-bottom:16px;">
            <span style="display:inline-block;padding:3px 10px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:0.08em;color:#334155;text-transform:uppercase;">Official Billing Assessment</span>
            <h2 style="font-size:20px;font-weight:700;color:#0f172a;margin:12px 0 6px 0;letter-spacing:-0.02em;">Invoice #$inv_no</h2>
        </div>

        <p style="font-size:14px;color:#334155;margin:0 0 16px 0;line-height:1.6;">
            Dear <strong>{$inv['resident_name']}</strong> ($unit_str),<br>
            Please be advised that an official billing assessment has been generated and posted to your account for <strong>{$inv['title']}</strong>.
        </p>

        <!-- Detailed Invoice Table -->
        <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:6px;background:#fafbfc;overflow:hidden;margin:20px 0;">
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Invoice Reference:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:700;font-family:monospace;color:#0f172a;">$inv_no</td>
            </tr>
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Charge Description:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:600;color:#1e293b;">{$inv['title']}</td>
            </tr>
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Assessment Date:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:500;color:#334155;">$issue_date_fmt</td>
            </tr>
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Payment Due Date:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:700;color:#0f172a;">$due_date_fmt</td>
            </tr>
            <tr>
                <td style="padding:14px 16px;font-size:13px;font-weight:700;color:#0f172a;text-transform:uppercase;letter-spacing:0.04em;background:#f1f5f9;">Total Amount Due:</td>
                <td align="right" style="padding:14px 16px;font-size:18px;font-weight:800;font-family:monospace;color:#0f172a;background:#f1f5f9;">$amount_fmt</td>
            </tr>
        </table>

        <!-- Pay Button CTA -->
        <div style="text-align:center;margin:28px 0 20px 0;">
            <a href="$pay_url" class="btn-block" style="background-color:#0f172a;color:#ffffff;text-decoration:none;padding:12px 28px;font-size:14px;font-weight:600;border-radius:6px;display:inline-block;letter-spacing:0.01em;">
                Pay Invoice Online ($amount_fmt) &rarr;
            </a>
        </div>

        <p style="font-size:12px;color:#64748b;line-height:1.5;margin-bottom:0;text-align:center;">
            You can also complete this transaction via bank transfer or through your resident dashboard. If you have already remitted this payment, please disregard this notice.
        </p>
HTML;

        $preheader = "Invoice #$inv_no for $amount_fmt is now due on $due_date_fmt.";
        $html = self::wrapTemplate($preheader, $body, $estate_info);

        $mail_sent = self::sendMail($inv['resident_email'], $inv['resident_name'], "Invoice #$inv_no: {$inv['title']}", $html, 'invoice', $inv_no, $inv['estate_id']);
        
        // Also dispatch to WhatsApp via Kapso
        EstateWhatsApp::sendInvoiceWhatsApp($conn, $invoice_id);

        return $mail_sent;
    }

    /**
     * 2. Send Payment Receipt Notification Email (Mature Corporate Aesthetic)
     */
    public static function sendReceiptEmail($conn, $receipt_id_or_number) {
        $estate_info = self::getEstateSettings($conn);
        if (!$estate_info['notify_on_receipt']) return false;

        $receipt_safe = $conn->real_escape_string(trim($receipt_id_or_number));
        $where = is_numeric($receipt_id_or_number) ? "(r.id = $receipt_safe OR r.receipt_number = '$receipt_safe')" : "r.receipt_number = '$receipt_safe'";

        $query = "SELECT r.*, p.payment_method, p.transaction_ref, p.payment_reference, p.paid_at, p.type as payment_title,
                         u.name as resident_name, u.email as resident_email,
                         f.number as flat_number, b.name as building_name,
                         staff.name as staff_name
                  FROM receipts r
                  LEFT JOIN payments p ON r.payment_id = p.id
                  JOIN users u ON r.resident_id = u.id
                  LEFT JOIN flats f ON r.property_id = f.id
                  LEFT JOIN buildings b ON f.building_id = b.id
                  LEFT JOIN users staff ON r.issued_by = staff.id
                  WHERE $where LIMIT 1";

        $res = $conn->query($query);
        if (!$res || $res->num_rows === 0) return false;
        $rec = $res->fetch_assoc();

        if (empty($rec['resident_email'])) return false;

        $curr = $estate_info['currency_symbol'];
        $amount_fmt = $curr . number_format($rec['amount'], 2);
        $receipt_no = htmlspecialchars($rec['receipt_number']);
        $receipt_url = $estate_info['base_url'] . 'resident/receipt.php?receipt_no=' . urlencode($receipt_no);
        $paid_date = !empty($rec['issued_at']) ? date('M d, Y h:i A', strtotime($rec['issued_at'])) : date('M d, Y');
        $channel = strtoupper(str_replace(['_', '-'], ' ', $rec['payment_method'] ?? 'Online Payment'));
        $processed_by = !empty($rec['staff_name']) ? $rec['staff_name'] : 'Automated Gateway Verification';

        $body = <<<HTML
        <div style="margin-bottom:16px;">
            <span style="display:inline-block;padding:3px 10px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:0.08em;color:#065f46;text-transform:uppercase;">Official Payment Receipt &bull; Confirmed</span>
            <h2 style="font-size:20px;font-weight:700;color:#0f172a;margin:12px 0 6px 0;letter-spacing:-0.02em;">Receipt #$receipt_no</h2>
        </div>

        <p style="font-size:14px;color:#334155;margin:0 0 16px 0;line-height:1.6;">
            Dear <strong>{$rec['resident_name']}</strong>,<br>
            Thank you for your payment. Your transaction has been confirmed and successfully credited to your property ledger.
        </p>

        <!-- Detailed Receipt Ledger Table -->
        <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:6px;background:#fafbfc;overflow:hidden;margin:20px 0;">
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Receipt Number:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:700;font-family:monospace;color:#0f172a;">$receipt_no</td>
            </tr>
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Settlement For:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:600;color:#1e293b;">{$rec['payment_title']}</td>
            </tr>
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Payment Channel:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:600;color:#1e293b;">$channel</td>
            </tr>
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Transaction Ref:</td>
                <td align="right" style="padding:10px 16px;font-size:12px;font-family:monospace;color:#475569;">{$rec['transaction_ref']}</td>
            </tr>
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Timestamp:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:500;color:#1e293b;">$paid_date</td>
            </tr>
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Clearance Officer:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:500;color:#1e293b;">$processed_by</td>
            </tr>
            <tr>
                <td style="padding:14px 16px;font-size:13px;font-weight:700;color:#065f46;text-transform:uppercase;letter-spacing:0.04em;background:#ecfdf5;">Amount Cleared:</td>
                <td align="right" style="padding:14px 16px;font-size:18px;font-weight:800;font-family:monospace;color:#047857;background:#ecfdf5;">$amount_fmt</td>
            </tr>
        </table>

        <!-- Receipt CTA -->
        <div style="text-align:center;margin:28px 0 20px 0;">
            <a href="$receipt_url" class="btn-block" style="background-color:#0f172a;color:#ffffff;text-decoration:none;padding:12px 28px;font-size:14px;font-weight:600;border-radius:6px;display:inline-block;letter-spacing:0.01em;">
                View &amp; Print Official Voucher &rarr;
            </a>
        </div>
HTML;

        $preheader = "Payment Confirmation: Receipt #$receipt_no for $amount_fmt has been processed successfully.";
        $html = self::wrapTemplate($preheader, $body, $estate_info);

        $mail_sent = self::sendMail($rec['resident_email'], $rec['resident_name'], "Payment Receipt #$receipt_no - $amount_fmt", $html, 'receipt', $receipt_no, $rec['estate_id']);

        // Also dispatch to WhatsApp via Kapso
        EstateWhatsApp::sendReceiptWhatsApp($conn, $receipt_no);

        return $mail_sent;
    }

    /**
     * 3. Send Visitor Digital Pass Email (to Resident and/or Visitor)
     */
    public static function sendVisitorPassEmail($conn, $visitor_id) {
        $estate_info = self::getEstateSettings($conn);
        if (!$estate_info['notify_on_visitor_pass']) return false;

        $v_id = intval($visitor_id);
        $query = "SELECT v.*, u.name as resident_name, u.email as resident_email, u.phone as resident_phone,
                         f.number as flat_number, b.name as building_name, s.name as street_name
                  FROM visitors v
                  LEFT JOIN users u ON v.resident_id = u.id
                  LEFT JOIN flats f ON v.flat_id = f.id
                  LEFT JOIN buildings b ON f.building_id = b.id
                  LEFT JOIN streets s ON b.street_id = s.id
                  WHERE v.id = $v_id LIMIT 1";

        $res = $conn->query($query);
        if (!$res || $res->num_rows === 0) return false;
        $v = $res->fetch_assoc();

        $visitor_code = htmlspecialchars($v['visitor_code']);
        $pass_url = $estate_info['base_url'] . 'gate_pass.php?code=' . urlencode($visitor_code);
        $visitor_name = htmlspecialchars($v['name']);
        $arrival = !empty($v['expected_arrival']) ? date('M d, Y h:i A', strtotime($v['expected_arrival'])) : 'Anytime Today';
        $location_str = "Flat " . ($v['flat_number'] ?? 'Unit') . ", " . ($v['building_name'] ?? '') . " (" . ($v['street_name'] ?? 'Estate') . ")";

        // QR Code image using dynamic Google Charts API
        $qr_url = "https://chart.googleapis.com/chart?chs=180x180&cht=qr&chl=" . urlencode($visitor_code) . "&choe=UTF-8";

        // Dispatch 1: To Visitor (if email provided)
        if (!empty($v['email']) && filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
            $visitorBody = <<<HTML
            <div style="margin-bottom:16px;">
                <span style="display:inline-block;padding:3px 10px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:0.08em;color:#334155;text-transform:uppercase;">Gate Access Authorization</span>
                <h2 style="font-size:20px;font-weight:700;color:#0f172a;margin:12px 0 6px 0;letter-spacing:-0.02em;">Visitor Gate Clearance Pass</h2>
            </div>

            <p style="font-size:14px;color:#334155;margin:0 0 16px 0;line-height:1.6;">
                Hello <strong>$visitor_name</strong>,<br>
                You have been registered for authorized entry to <strong>{$estate_info['estate_name']}</strong>. Please present the gate code below or display this pass at the security gate upon arrival:
            </p>

            <!-- Pass Ticket Box -->
            <div style="background:#fafbfc;border:1px solid #cbd5e1;border-radius:8px;padding:24px;text-align:center;margin:20px 0;">
                <div style="font-size:11px;font-weight:700;letter-spacing:0.1em;color:#64748b;text-transform:uppercase;">Gate Access Code</div>
                <div style="font-size:32px;font-weight:800;letter-spacing:4px;color:#0f172a;font-family:monospace;margin:10px 0 14px 0;">$visitor_code</div>
                
                <div style="margin:12px auto;">
                    <img src="$qr_url" alt="Pass QR Code" width="130" height="130" style="border:1px solid #cbd5e1;padding:6px;background:#ffffff;border-radius:6px;display:inline-block;">
                </div>

                <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px;text-align:left;font-size:13px;border-top:1px solid #e2e8f0;padding-top:12px;">
                    <tr>
                        <td style="padding:4px 0;color:#64748b;font-weight:600;">Authorized Host:</td>
                        <td align="right" style="padding:4px 0;color:#0f172a;font-weight:600;">{$v['resident_name']}</td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0;color:#64748b;font-weight:600;">Destination:</td>
                        <td align="right" style="padding:4px 0;color:#0f172a;">$location_str</td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0;color:#64748b;font-weight:600;">Expected Arrival:</td>
                        <td align="right" style="padding:4px 0;color:#0f172a;">$arrival</td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0;color:#64748b;font-weight:600;">Visit Purpose:</td>
                        <td align="right" style="padding:4px 0;color:#0f172a;">{$v['purpose']}</td>
                    </tr>
                </table>
            </div>

            <div style="text-align:center;margin:24px 0 20px 0;">
                <a href="$pass_url" class="btn-block" style="background-color:#0f172a;color:#ffffff;text-decoration:none;padding:12px 28px;font-size:14px;font-weight:600;border-radius:6px;display:inline-block;letter-spacing:0.01em;">
                    Open Digital Pass Slip &rarr;
                </a>
            </div>

            <div style="background:#f8fafc;border-left:3px solid #334155;padding:12px 14px;font-size:12px;color:#475569;line-height:1.5;">
                <strong>Security Instructions:</strong> Valid government photo ID is mandatory at the security checkpoint. The maximum estate speed limit is 20 km/h. Please park only in designated visitor bays.
            </div>
HTML;
            $preheader = "Your digital visitor pass code for {$estate_info['estate_name']} is $visitor_code.";
            $html = self::wrapTemplate($preheader, $visitorBody, $estate_info);
            self::sendMail($v['email'], $visitor_name, "Visitor Gate Pass: $visitor_code", $html, 'visitor_pass', $visitor_code, $v['estate_id']);
        }

        // Dispatch 2: Confirmation copy to Resident
        if (!empty($v['resident_email'])) {
            $residentBody = <<<HTML
            <div style="margin-bottom:16px;">
                <span style="display:inline-block;padding:3px 10px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:0.08em;color:#334155;text-transform:uppercase;">Visitor Pre-Registration</span>
                <h2 style="font-size:20px;font-weight:700;color:#0f172a;margin:12px 0 6px 0;letter-spacing:-0.02em;">Gate Pass Generated: $visitor_name</h2>
            </div>

            <p style="font-size:14px;color:#334155;margin:0 0 16px 0;line-height:1.6;">
                Dear <strong>{$v['resident_name']}</strong>,<br>
                You have successfully generated an official visitor clearance pass for <strong>$visitor_name</strong>.
            </p>

            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:6px;background:#fafbfc;overflow:hidden;margin:20px 0;">
                <tr style="border-bottom:1px solid #edf2f7;">
                    <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Visitor Name:</td>
                    <td align="right" style="padding:10px 16px;font-size:13px;font-weight:700;color:#0f172a;">$visitor_name</td>
                </tr>
                <tr style="border-bottom:1px solid #edf2f7;">
                    <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Access Code:</td>
                    <td align="right" style="padding:10px 16px;font-size:16px;font-weight:800;font-family:monospace;color:#0f172a;">$visitor_code</td>
                </tr>
                <tr style="border-bottom:1px solid #edf2f7;">
                    <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Expected Arrival:</td>
                    <td align="right" style="padding:10px 16px;font-size:13px;font-weight:600;color:#1e293b;">$arrival</td>
                </tr>
                <tr>
                    <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Purpose:</td>
                    <td align="right" style="padding:10px 16px;font-size:13px;font-weight:500;color:#1e293b;">{$v['purpose']}</td>
                </tr>
            </table>

            <div style="text-align:center;margin:24px 0 20px 0;">
                <a href="$pass_url" class="btn-block" style="background-color:#0f172a;color:#ffffff;text-decoration:none;padding:12px 28px;font-size:14px;font-weight:600;border-radius:6px;display:inline-block;letter-spacing:0.01em;">
                    Share / View Digital Pass &rarr;
                </a>
            </div>

            <p style="font-size:12px;color:#64748b;text-align:center;margin-bottom:0;">
                You will receive an automatic security notification the moment $visitor_name completes gate verification.
            </p>
HTML;
            $preheader = "Pass created for $visitor_name (Code: $visitor_code).";
            $html = self::wrapTemplate($preheader, $residentBody, $estate_info);
            self::sendMail($v['resident_email'], $v['resident_name'], "Pass Created: $visitor_name (Code: $visitor_code)", $html, 'visitor_pass', $visitor_code, $v['estate_id']);
        }

        // Also dispatch digital pass to WhatsApp via Kapso (to visitor and/or resident host)
        EstateWhatsApp::sendVisitorPassWhatsApp($conn, $v_id);

        return true;
    }

    /**
     * 4. Send Instant Visitor Gate Arrival Alert to Resident (Mature Corporate Aesthetic)
     */
    public static function sendVisitorArrivalAlert($conn, $visitor_id, $gate_name = 'Main Gate') {
        $estate_info = self::getEstateSettings($conn);
        if (!$estate_info['notify_on_visitor_arrival']) return false;

        $v_id = intval($visitor_id);
        $query = "SELECT v.*, u.name as resident_name, u.email as resident_email 
                  FROM visitors v 
                  JOIN users u ON v.resident_id = u.id 
                  WHERE v.id = $v_id LIMIT 1";
        $res = $conn->query($query);
        if (!$res || $res->num_rows === 0) return false;
        $v = $res->fetch_assoc();

        if (empty($v['resident_email'])) return false;

        $visitor_name = htmlspecialchars($v['name']);
        $visitor_code = htmlspecialchars($v['visitor_code']);
        $gate = htmlspecialchars($gate_name ?: ($v['entry_gate'] ?? 'Main Gate'));
        $time_now = date('h:i A');

        $body = <<<HTML
        <div style="margin-bottom:16px;">
            <span style="display:inline-block;padding:3px 10px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:0.08em;color:#1e40af;text-transform:uppercase;">Security Checkpoint Arrival</span>
            <h2 style="font-size:20px;font-weight:700;color:#0f172a;margin:12px 0 6px 0;letter-spacing:-0.02em;">Visitor Checked In at $gate</h2>
        </div>

        <p style="font-size:14px;color:#334155;line-height:1.6;margin:0 0 16px 0;">
            Dear <strong>{$v['resident_name']}</strong>,<br>
            Your visitor <strong>$visitor_name</strong> (Pass: <code style="font-weight:700;color:#0f172a;background:#f1f5f9;padding:2px 6px;border-radius:4px;">$visitor_code</code>) has completed physical verification at <strong>$gate</strong> at <strong>$time_now</strong> and is proceeding to your residence.
        </p>

        <div style="background:#f8fafc;border-left:3px solid #334155;padding:12px 14px;margin:20px 0;font-size:12px;color:#475569;line-height:1.5;">
            <strong>Security Notice:</strong> If you did not authorize this visit, please contact the gate security command immediately via intercom or the emergency desk.
        </div>
HTML;
        $preheader = "Visitor Arrival: $visitor_name has just checked in at $gate.";
        $html = self::wrapTemplate($preheader, $body, $estate_info);

        $mail_sent = self::sendMail($v['resident_email'], $v['resident_name'], "Visitor Arrived: $visitor_name at $gate", $html, 'visitor_arrival', $visitor_code, $v['estate_id']);

        // Also dispatch instant gate arrival alert to WhatsApp via Kapso
        EstateWhatsApp::sendVisitorArrivalAlertWhatsApp($conn, $v_id, $gate);

        return $mail_sent;
    }

    /**
     * 5. Send Welcome & Credentials Email to New Resident or Staff (Mature Corporate Aesthetic)
     */
    public static function sendWelcomeCredentialsEmail($conn, $user_id, $plain_password) {
        $estate_info = self::getEstateSettings($conn);
        if (!$estate_info['notify_on_welcome']) return false;

        $user_id = intval($user_id);
        $res = $conn->query("SELECT * FROM users WHERE id = $user_id LIMIT 1");
        if (!$res || $res->num_rows === 0) return false;
        $user = $res->fetch_assoc();

        if (empty($user['email'])) return false;

        $login_url = $estate_info['base_url'] . 'login.php';
        $role_label = ucfirst($user['role'] ?? 'Resident');

        $body = <<<HTML
        <div style="margin-bottom:16px;">
            <span style="display:inline-block;padding:3px 10px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:0.08em;color:#334155;text-transform:uppercase;">Portal Onboarding &bull; Access Credentials</span>
            <h2 style="font-size:20px;font-weight:700;color:#0f172a;margin:12px 0 6px 0;letter-spacing:-0.02em;">Welcome to {$estate_info['estate_name']}</h2>
        </div>

        <p style="font-size:14px;color:#334155;margin:0 0 16px 0;line-height:1.6;">
            Dear <strong>{$user['name']}</strong>,<br>
            Your official estate portal account has been established with authorized access as a <strong>$role_label</strong>.
        </p>

        <!-- Credentials Box -->
        <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:6px;background:#fafbfc;overflow:hidden;margin:20px 0;">
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Portal URL:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:600;"><a href="$login_url" style="color:#0f172a;text-decoration:underline;">$login_url</a></td>
            </tr>
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Assigned Role:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:600;color:#1e293b;">$role_label</td>
            </tr>
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Login Email:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:700;font-family:monospace;color:#0f172a;">{$user['email']}</td>
            </tr>
            <tr>
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Temporary Password:</td>
                <td align="right" style="padding:10px 16px;font-size:14px;font-weight:700;font-family:monospace;color:#0f172a;">$plain_password</td>
            </tr>
        </table>

        <div style="text-align:center;margin:24px 0 20px 0;">
            <a href="$login_url" class="btn-block" style="background-color:#0f172a;color:#ffffff;text-decoration:none;padding:12px 28px;font-size:14px;font-weight:600;border-radius:6px;display:inline-block;letter-spacing:0.01em;">
                Sign In to Your Portal &rarr;
            </a>
        </div>

        <div style="background:#f8fafc;border-left:3px solid #334155;padding:12px 14px;font-size:12px;color:#475569;line-height:1.5;">
            <strong>Security Advisory:</strong> For account privacy and security compliance, you will be prompted to set a permanent private password upon your first sign in.
        </div>
HTML;
        $preheader = "Welcome to {$estate_info['estate_name']} - Your Portal Login Credentials";
        $html = self::wrapTemplate($preheader, $body, $estate_info);

        $mail_sent = self::sendMail($user['email'], $user['name'], "Welcome to {$estate_info['estate_name']} - Login Credentials", $html, 'welcome', $user['id'], $user['estate_id']);

        // Also dispatch welcome onboarding credentials to WhatsApp via Kapso
        EstateWhatsApp::sendWelcomeCredentialsWhatsApp($conn, $user_id, $plain_password);

        return $mail_sent;
    }

    /**
     * 6. Send Broadcast Email to multiple recipients
     */
    public static function sendBroadcastEmail($conn, $subject, $content, $target_role = 'resident', $estate_id = null, $zone_id = null) {
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $estate_info = self::getEstateSettings($conn, $estate_id);
        $estate_id = $estate_info['estate_id'];

        $target_role = $conn->real_escape_string($target_role);
        $normalized_role = ($target_role === 'residents' || $target_role === 'tenants') ? 'resident' : $target_role;

        if ($zone_id && intval($zone_id) > 0) {
            $zid = intval($zone_id);
            // Zone targeted users
            $sql = "SELECT DISTINCT u.id, u.name, u.email 
                    FROM users u
                    JOIN residents r ON r.user_id = u.id
                    JOIN flats f ON r.flat_id = f.id
                    JOIN buildings b ON f.building_id = b.id
                    JOIN streets s ON b.street_id = s.id
                    WHERE u.estate_id = $estate_id AND s.zone_id = $zid AND u.email IS NOT NULL AND u.email != ''";
            if ($target_role !== 'all') {
                $sql .= " AND u.role = '$normalized_role'";
            }
            $res = $conn->query($sql);
        } else {
            $where = ($target_role === 'all') ? "estate_id = $estate_id" : "estate_id = $estate_id AND role = '$normalized_role'";
            $res = $conn->query("SELECT name, email FROM users WHERE $where AND email IS NOT NULL AND email != ''");
        }

        if (!$res || $res->num_rows === 0) return 0;

        $count = 0;
        $start_time = time();

        if ($estate_info['smtp_enabled'] && !empty($estate_info['smtp_host'])) {
            $mail = new PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = $estate_info['smtp_host'];
            $mail->SMTPAuth = !empty($estate_info['smtp_user']);
            $mail->Username = $estate_info['smtp_user'];
            $mail->Password = $estate_info['smtp_pass'];
            $mail->Port = $estate_info['smtp_port'];

            if ($estate_info['smtp_encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($estate_info['smtp_encryption'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            }

            $mail->Timeout = 6;
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];
            $mail->SMTPKeepAlive = true;

            $fromEmail = !empty($estate_info['smtp_from_email']) ? $estate_info['smtp_from_email'] : 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $fromName = !empty($estate_info['smtp_from_name']) ? $estate_info['smtp_from_name'] : $estate_info['estate_name'];
            $mail->setFrom($fromEmail, $fromName);

            if (!empty($estate_info['smtp_reply_to'])) {
                $mail->addReplyTo($estate_info['smtp_reply_to'], $fromName);
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;

            // Connect ONCE to the SMTP server
            try {
                $mail->smtpConnect();
            } catch (\Exception $e) {
                // If initial connection fails, exit fast without blocking or freezing!
                self::logEmail($conn, $estate_id, 'broadcast@estate', 'Broadcast Group', $subject, 'broadcast', null, 'failed', 'SMTP Connection Failed: ' . $e->getMessage());
                return 0;
            }

            while ($u = $res->fetch_assoc()) {
                // Safety: time budget cap (never exceed 40s in loop)
                if ((time() - $start_time) > 40) {
                    break;
                }

                $toEmail = $u['email'];
                if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }

                $body = <<<HTML
                <div style="margin-bottom:16px;">
                    <span style="display:inline-block;padding:3px 10px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:0.08em;color:#334155;text-transform:uppercase;">Official Estate Advisory</span>
                    <h2 style="font-size:20px;font-weight:700;color:#0f172a;margin:12px 0 6px 0;letter-spacing:-0.02em;">$subject</h2>
                </div>
                <div style="font-size:14px;color:#334155;line-height:1.7;">
                    Dear <strong>{$u['name']}</strong>,<br><br>
                    $content
                </div>
HTML;
                $preheader = substr(strip_tags($content), 0, 100);
                $html = self::wrapTemplate($preheader, $body, $estate_info);

                try {
                    $mail->clearAddresses();
                    $mail->clearAttachments();
                    $mail->addAddress($toEmail, $u['name'] ?: $toEmail);
                    $mail->Body = $html;
                    $mail->AltBody = strip_tags($content);

                    if ($mail->send()) {
                        $count++;
                        self::logEmail($conn, $estate_id, $toEmail, $u['name'], $subject, 'broadcast', null, 'sent', null);
                    }
                } catch (\Exception $e) {
                    self::logEmail($conn, $estate_id, $toEmail, $u['name'], $subject, 'broadcast', null, 'failed', $e->getMessage());
                }
            }

            $mail->smtpClose();
        } else {
            while ($u = $res->fetch_assoc()) {
                if ((time() - $start_time) > 30) {
                    break;
                }
                $body = <<<HTML
                <div style="margin-bottom:16px;">
                    <span style="display:inline-block;padding:3px 10px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:0.08em;color:#334155;text-transform:uppercase;">Official Estate Advisory</span>
                    <h2 style="font-size:20px;font-weight:700;color:#0f172a;margin:12px 0 6px 0;letter-spacing:-0.02em;">$subject</h2>
                </div>
                <div style="font-size:14px;color:#334155;line-height:1.7;">
                    Dear <strong>{$u['name']}</strong>,<br><br>
                    $content
                </div>
HTML;
                $preheader = substr(strip_tags($content), 0, 100);
                $html = self::wrapTemplate($preheader, $body, $estate_info);
                if (self::sendMail($u['email'], $u['name'], $subject, $html, 'broadcast', null, $estate_id)) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * 7. Interactive SMTP Connection & Delivery Test
     */
    public static function testSmtpConnection($conn, $estate_id, $testRecipient) {
        $estate_info = self::getEstateSettings($conn, $estate_id);

        $now_str = date('Y-m-d H:i:s');
        $testBody = <<<HTML
        <div style="text-align:left;margin-bottom:20px;">
            <span style="display:inline-block;padding:3px 10px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:0.08em;color:#334155;text-transform:uppercase;">Diagnostic Verification</span>
            <h2 style="font-size:20px;font-weight:700;color:#0f172a;margin:12px 0 6px 0;letter-spacing:-0.02em;">SMTP Diagnostic Test Successful</h2>
            <p style="font-size:14px;color:#334155;line-height:1.6;margin:0 0 16px 0;">
                The email delivery subsystem for <strong>{$estate_info['estate_name']}</strong> has been verified. Invoices, receipts, gate passes, and emergency alerts are operational.
            </p>
        </div>
        <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:6px;background:#fafbfc;overflow:hidden;margin:20px 0;">
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">SMTP Host:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:700;font-family:monospace;color:#0f172a;">{$estate_info['smtp_host']}</td>
            </tr>
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">SMTP Port:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:700;font-family:monospace;color:#0f172a;">{$estate_info['smtp_port']} ({$estate_info['smtp_encryption']})</td>
            </tr>
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Authorized Sender:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:600;color:#1e293b;">{$estate_info['smtp_from_email']}</td>
            </tr>
            <tr>
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Verification Timestamp:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;color:#64748b;">$now_str</td>
            </tr>
        </table>
HTML;
        $html = self::wrapTemplate("SMTP Diagnostic Test Message", $testBody, $estate_info);
        return self::sendMail($testRecipient, "Administrator", "SMTP Test Verification - {$estate_info['estate_name']}", $html, 'test_diagnostic', null, $estate_id);
    }

    /**
     * 8. Send Self-Service Password Reset OTP & Direct Link (Mature Corporate Aesthetic)
     */
    public static function sendPasswordResetOtp($conn, $toEmail, $toName, $otpCode, $resetLink, $estate_id = null) {
        $estate_info = self::getEstateSettings($conn, $estate_id);
        $safeName = htmlspecialchars($toName ?: 'Resident / Member');
        $safeOtp = htmlspecialchars($otpCode);
        $safeLink = htmlspecialchars($resetLink);

        $body = <<<HTML
        <div style="margin-bottom:16px;">
            <span style="display:inline-block;padding:3px 10px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:0.08em;color:#334155;text-transform:uppercase;">Account Security &bull; Authentication Verification</span>
            <h2 style="font-size:20px;font-weight:700;color:#0f172a;margin:12px 0 6px 0;letter-spacing:-0.02em;">Password Reset Verification</h2>
        </div>

        <p style="font-size:14px;color:#334155;line-height:1.6;margin:0 0 16px 0;">
            Dear <strong>$safeName</strong>,<br>
            A password reset request was initiated for your estate portal profile. To authenticate this request, enter the single-use verification code below:
        </p>

        <!-- OTP Display Box -->
        <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:6px;background:#fafbfc;overflow:hidden;margin:20px 0;">
            <tr>
                <td align="center" style="padding:24px 16px;">
                    <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:8px;">One-Time Verification Code</div>
                    <div style="font-size:32px;font-weight:800;letter-spacing:8px;font-family:Consolas,Monaco,monospace;color:#0f172a;">$safeOtp</div>
                    <div style="font-size:12px;color:#64748b;margin-top:8px;">Valid for 15 minutes only &bull; Do not disclose to third parties</div>
                </td>
            </tr>
        </table>

        <div style="text-align:center;margin:24px 0 20px 0;">
            <a href="$safeLink" class="btn-block" style="background-color:#0f172a;color:#ffffff;text-decoration:none;padding:12px 28px;font-size:14px;font-weight:600;border-radius:6px;display:inline-block;letter-spacing:0.01em;">
                Reset Password Online &rarr;
            </a>
        </div>

        <div style="background:#f8fafc;border-left:3px solid #334155;padding:12px 14px;font-size:12px;color:#475569;line-height:1.5;">
            <strong>Security Advisory:</strong> If you did not request this password reset, please contact Central Estate Administration or Security immediately. No changes have been made to your credentials.
        </div>
HTML;

        $preheader = "Your One-Time Password Reset Code is $otpCode (Valid for 15 minutes)";
        $html = self::wrapTemplate($preheader, $body, $estate_info);
        $mail_sent = self::sendMail($toEmail, $toName, "Password Reset Code: $otpCode - {$estate_info['estate_name']}", $html, 'password_reset_otp', null, $estate_id);

        // Also dispatch to WhatsApp if user has a registered phone number
        $u_phone_chk = $conn->query("SELECT phone FROM users WHERE email = '" . $conn->real_escape_string($toEmail) . "' LIMIT 1");
        if ($u_phone_chk && $u_p = $u_phone_chk->fetch_assoc()) {
            if (!empty($u_p['phone'])) {
                EstateWhatsApp::sendPasswordResetOtpWhatsApp($conn, $u_p['phone'], $toName, $otpCode, $resetLink, $estate_id);
            }
        }

        return $mail_sent;
    }

    /**
     * 9. Send Admin Instant Reset Notification (Mature Corporate Aesthetic)
     */
    public static function sendAdminPasswordResetNotification($conn, $toEmail, $toName, $tempPassword, $loginUrl, $estate_id = null) {
        $estate_info = self::getEstateSettings($conn, $estate_id);
        $safeName = htmlspecialchars($toName ?: 'Resident / Member');
        $safeEmail = htmlspecialchars($toEmail);
        $safeTemp = htmlspecialchars($tempPassword);
        $safeUrl = htmlspecialchars($loginUrl);

        $body = <<<HTML
        <div style="margin-bottom:16px;">
            <span style="display:inline-block;padding:3px 10px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:0.08em;color:#334155;text-transform:uppercase;">Central Administration &bull; Credentials Update</span>
            <h2 style="font-size:20px;font-weight:700;color:#0f172a;margin:12px 0 6px 0;letter-spacing:-0.02em;">Temporary Credentials Issued</h2>
        </div>

        <p style="font-size:14px;color:#334155;line-height:1.6;margin:0 0 16px 0;">
            Dear <strong>$safeName</strong>,<br>
            Your account access credentials have been reset by Central Administration. Use the temporary sign-in credentials below:
        </p>

        <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:6px;background:#fafbfc;overflow:hidden;margin:20px 0;">
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Sign In Email:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:700;font-family:monospace;color:#0f172a;">$safeEmail</td>
            </tr>
            <tr>
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Temporary Password:</td>
                <td align="right" style="padding:10px 16px;font-size:14px;font-weight:800;font-family:monospace;color:#0f172a;">$safeTemp</td>
            </tr>
        </table>

        <div style="text-align:center;margin:24px 0 20px 0;">
            <a href="$safeUrl" class="btn-block" style="background-color:#0f172a;color:#ffffff;text-decoration:none;padding:12px 28px;font-size:14px;font-weight:600;border-radius:6px;display:inline-block;letter-spacing:0.01em;">
                Sign In to Portal &rarr;
            </a>
        </div>

        <div style="background:#f8fafc;border-left:3px solid #334155;padding:12px 14px;font-size:12px;color:#475569;line-height:1.5;">
            <strong>Mandatory Action:</strong> For account compliance and privacy, you will be prompted to establish a private permanent password immediately upon logging in.
        </div>
HTML;

        $preheader = "Your temporary password for {$estate_info['estate_name']} has been issued by Administration";
        $html = self::wrapTemplate($preheader, $body, $estate_info);
        $mail_sent = self::sendMail($toEmail, $toName, "Temporary Access Credentials - {$estate_info['estate_name']}", $html, 'admin_password_reset', null, $estate_id);

        // Also dispatch to WhatsApp if user has a registered phone number
        $u_phone_chk = $conn->query("SELECT phone FROM users WHERE email = '" . $conn->real_escape_string($toEmail) . "' LIMIT 1");
        if ($u_phone_chk && $u_p = $u_phone_chk->fetch_assoc()) {
            if (!empty($u_p['phone'])) {
                EstateWhatsApp::sendAdminPasswordResetWhatsApp($conn, $u_p['phone'], $toName, $tempPassword, $loginUrl, $estate_id);
            }
        }

        return $mail_sent;
    }

    /**
     * 10. Send Password Changed Confirmation (Mature Corporate Aesthetic)
     */
    public static function sendPasswordChangedConfirmation($conn, $toEmail, $toName, $estate_id = null) {
        $estate_info = self::getEstateSettings($conn, $estate_id);
        $safeName = htmlspecialchars($toName ?: 'Resident / Member');
        $dateStr = date('M d, Y h:i A');

        $body = <<<HTML
        <div style="margin-bottom:16px;">
            <span style="display:inline-block;padding:3px 10px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:0.08em;color:#334155;text-transform:uppercase;">Security Confirmation &bull; Credentials Update</span>
            <h2 style="font-size:20px;font-weight:700;color:#0f172a;margin:12px 0 6px 0;letter-spacing:-0.02em;">Password Changed Successfully</h2>
        </div>

        <p style="font-size:14px;color:#334155;line-height:1.6;margin:0 0 16px 0;">
            Dear <strong>$safeName</strong>,<br>
            This is an automated security notice confirming that the password for your <strong>{$estate_info['estate_name']}</strong> portal account was successfully updated on <strong>$dateStr</strong>.
        </p>

        <div style="background:#f8fafc;border-left:3px solid #334155;padding:12px 14px;font-size:12px;color:#475569;line-height:1.5;">
            <strong>Did not make this change?</strong> If you did not authorize this credential change, contact the Estate Security Command or Central Administration immediately to secure your account.
        </div>
HTML;

        $preheader = "Security Alert: Your password for {$estate_info['estate_name']} was changed";
        $html = self::wrapTemplate($preheader, $body, $estate_info);
        return self::sendMail($toEmail, $toName, "Security Alert: Password Changed - {$estate_info['estate_name']}", $html, 'password_changed', null, $estate_id);
    }

    /**
     * 11. Send Official Artisan Clearance & Gate Pass Email (Mature Corporate Aesthetic)
     */
    public static function sendArtisanPassEmail($conn, $artisan_data_or_id) {
        $estate_info = self::getEstateSettings($conn);
        if (!$estate_info['notify_on_artisan_pass']) return false;

        $pass_data = [];
        if (is_numeric($artisan_data_or_id)) {
            $id = intval($artisan_data_or_id);
            $vq = $conn->query("SELECT v.*, u.name as resident_name, u.email as resident_email, u.phone as resident_phone,
                                       f.number as flat_number, b.name as building_name, s.name as street_name
                                FROM visitors v
                                LEFT JOIN users u ON v.resident_id = u.id
                                LEFT JOIN flats f ON v.flat_id = f.id
                                LEFT JOIN buildings b ON f.building_id = b.id
                                LEFT JOIN streets s ON b.street_id = s.id
                                WHERE v.id = $id LIMIT 1");
            if ($vq && $vq->num_rows > 0) {
                $v = $vq->fetch_assoc();
                $pass_data = [
                    'pass_id' => $v['id'],
                    'pass_code' => $v['visitor_code'],
                    'artisan_name' => $v['name'],
                    'artisan_phone' => $v['phone'],
                    'artisan_email' => $v['email'],
                    'trade' => $v['purpose'],
                    'scheduled_date' => $v['expected_arrival'],
                    'resident_name' => $v['resident_name'],
                    'resident_email' => $v['resident_email'],
                    'resident_phone' => $v['resident_phone'],
                    'unit_str' => "Flat " . ($v['flat_number'] ?? 'Unit') . ", " . ($v['building_name'] ?? '') . " (" . ($v['street_name'] ?? 'Estate') . ")",
                    'estate_id' => $v['estate_id']
                ];
            }
        } elseif (is_array($artisan_data_or_id)) {
            $pass_data = $artisan_data_or_id;
        }

        if (empty($pass_data)) return false;

        $artisan_name = htmlspecialchars($pass_data['artisan_name'] ?? 'Artisan');
        $pass_code = htmlspecialchars($pass_data['pass_code'] ?? 'ART-PASS');
        $trade = htmlspecialchars($pass_data['trade'] ?? 'Maintenance & Repair');
        $arrival = !empty($pass_data['scheduled_date']) ? date('M d, Y h:i A', strtotime($pass_data['scheduled_date'])) : 'Valid for Scheduled Date';
        $resident_name = htmlspecialchars($pass_data['resident_name'] ?? 'Host Resident');
        $unit_str = htmlspecialchars($pass_data['unit_str'] ?? 'Resident Unit');
        $pass_url = $estate_info['base_url'] . 'gate_pass.php?code=' . urlencode($pass_code);

        // 1. Email to Artisan (if email provided)
        if (!empty($pass_data['artisan_email'])) {
            $artisanBody = <<<HTML
            <div style="margin-bottom:16px;">
                <span style="display:inline-block;padding:3px 10px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:0.08em;color:#334155;text-transform:uppercase;">Authorized Contractor / Artisan Gate Pass</span>
                <h2 style="font-size:20px;font-weight:700;color:#0f172a;margin:12px 0 6px 0;letter-spacing:-0.02em;">Gate Clearance Pass: $pass_code</h2>
            </div>

            <p style="font-size:14px;color:#334155;margin:0 0 16px 0;line-height:1.6;">
                Dear <strong>$artisan_name</strong>,<br>
                You have been authorized for gated entry into <strong>{$estate_info['estate_name']}</strong> to provide technical service. Present the verification code below to security personnel at the entry gate.
            </p>

            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:6px;background:#fafbfc;overflow:hidden;margin:20px 0;">
                <tr style="border-bottom:1px solid #edf2f7;">
                    <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Clearance Code:</td>
                    <td align="right" style="padding:10px 16px;font-size:18px;font-weight:800;font-family:monospace;color:#0f172a;">$pass_code</td>
                </tr>
                <tr style="border-bottom:1px solid #edf2f7;">
                    <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Host / Client:</td>
                    <td align="right" style="padding:10px 16px;font-size:13px;font-weight:600;color:#0f172a;">$resident_name</td>
                </tr>
                <tr style="border-bottom:1px solid #edf2f7;">
                    <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Work Location:</td>
                    <td align="right" style="padding:10px 16px;font-size:13px;font-weight:500;color:#334155;">$unit_str</td>
                </tr>
                <tr style="border-bottom:1px solid #edf2f7;">
                    <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Service Scope:</td>
                    <td align="right" style="padding:10px 16px;font-size:13px;font-weight:600;color:#1e293b;">$trade</td>
                </tr>
                <tr>
                    <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Entry Schedule:</td>
                    <td align="right" style="padding:10px 16px;font-size:13px;font-weight:600;color:#0f172a;">$arrival</td>
                </tr>
            </table>

            <div style="text-align:center;margin:24px 0 20px 0;">
                <a href="$pass_url" class="btn-block" style="background-color:#0f172a;color:#ffffff;text-decoration:none;padding:12px 28px;font-size:14px;font-weight:600;border-radius:6px;display:inline-block;letter-spacing:0.01em;">
                    View Digital Artisan Pass &rarr;
                </a>
            </div>

            <div style="background:#f8fafc;border-left:3px solid #334155;padding:12px 14px;font-size:12px;color:#475569;line-height:1.5;">
                <strong>Security Protocols for Artisans:</strong>
                <ul style="margin:6px 0 0 0;padding-left:18px;">
                    <li>Valid physical government-issued photo ID is mandatory at the security checkpoint.</li>
                    <li>All heavy machinery, power tools, and high-value materials must be declared and logged upon entry.</li>
                    <li>Estate operating work hours for artisans: Monday &ndash; Saturday, 08:00 AM &ndash; 06:00 PM. No loud mechanical work permitted on Sundays.</li>
                </ul>
            </div>
HTML;
            $preheader = "Artisan Gate Clearance Pass: $pass_code for {$estate_info['estate_name']}.";
            $html = self::wrapTemplate($preheader, $artisanBody, $estate_info);
            self::sendMail($pass_data['artisan_email'], $artisan_name, "Artisan Gate Clearance: $pass_code", $html, 'artisan_pass', $pass_code, $pass_data['estate_id'] ?? null);
        }

        // 2. Email Confirmation to Resident Host
        if (!empty($pass_data['resident_email'])) {
            $hostBody = <<<HTML
            <div style="margin-bottom:16px;">
                <span style="display:inline-block;padding:3px 10px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:0.08em;color:#334155;text-transform:uppercase;">Artisan Clearance Pass Issued</span>
                <h2 style="font-size:20px;font-weight:700;color:#0f172a;margin:12px 0 6px 0;letter-spacing:-0.02em;">Gate Pass Generated: $artisan_name</h2>
            </div>

            <p style="font-size:14px;color:#334155;margin:0 0 16px 0;line-height:1.6;">
                Dear <strong>$resident_name</strong>,<br>
                An official artisan gate clearance pass has been generated for <strong>$artisan_name</strong> ($trade).
            </p>

            <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:6px;background:#fafbfc;overflow:hidden;margin:20px 0;">
                <tr style="border-bottom:1px solid #edf2f7;">
                    <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Artisan Name:</td>
                    <td align="right" style="padding:10px 16px;font-size:13px;font-weight:700;color:#0f172a;">$artisan_name</td>
                </tr>
                <tr style="border-bottom:1px solid #edf2f7;">
                    <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Passcode:</td>
                    <td align="right" style="padding:10px 16px;font-size:16px;font-weight:800;font-family:monospace;color:#0f172a;">$pass_code</td>
                </tr>
                <tr style="border-bottom:1px solid #edf2f7;">
                    <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Scheduled Window:</td>
                    <td align="right" style="padding:10px 16px;font-size:13px;font-weight:600;color:#1e293b;">$arrival</td>
                </tr>
                <tr>
                    <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Service Description:</td>
                    <td align="right" style="padding:10px 16px;font-size:13px;font-weight:500;color:#1e293b;">$trade</td>
                </tr>
            </table>

            <div style="text-align:center;margin:24px 0 20px 0;">
                <a href="$pass_url" class="btn-block" style="background-color:#0f172a;color:#ffffff;text-decoration:none;padding:12px 28px;font-size:14px;font-weight:600;border-radius:6px;display:inline-block;letter-spacing:0.01em;">
                    Share / View Digital Pass &rarr;
                </a>
            </div>

            <p style="font-size:12px;color:#64748b;text-align:center;margin-bottom:0;">
                You will receive an automatic security arrival alert the moment $artisan_name clears the gate checkpoint.
            </p>
HTML;
            $preheader = "Artisan pass issued for $artisan_name (Code: $pass_code).";
            $html = self::wrapTemplate($preheader, $hostBody, $estate_info);
            self::sendMail($pass_data['resident_email'], $resident_name, "Artisan Gate Pass Issued: $artisan_name ($pass_code)", $html, 'artisan_pass', $pass_code, $pass_data['estate_id'] ?? null);
        }

        // Also trigger WhatsApp dispatch
        EstateWhatsApp::sendArtisanPassWhatsApp($conn, $artisan_data_or_id);

        return true;
    }

    /**
     * 12. Send Official Penalty / Infraction Assessment Notice (Mature Corporate Aesthetic)
     */
    public static function sendPenaltyNoticeEmail($conn, $penalty_id_or_data) {
        $estate_info = self::getEstateSettings($conn);
        if (!$estate_info['notify_on_penalty']) return false;

        $p = [];
        if (is_numeric($penalty_id_or_data)) {
            $pid = intval($penalty_id_or_data);
            $q = $conn->query("SELECT * FROM estate_penalties WHERE id = $pid LIMIT 1");
            if ($q && $q->num_rows > 0) {
                $p = $q->fetch_assoc();
            }
        } elseif (is_array($penalty_id_or_data)) {
            $p = $penalty_id_or_data;
        }

        if (empty($p)) return false;

        $pref = htmlspecialchars($p['penalty_ref'] ?: ('PEN-' . $p['id']));
        $recipient_name = htmlspecialchars($p['recipient_name'] ?? 'Resident / Member');
        $recipient_email = trim($p['recipient_email'] ?? '');
        $curr = $estate_info['currency_symbol'];
        $fine_fmt = $curr . number_format($p['fine_amount'] ?? 0, 2);
        $due_date_fmt = !empty($p['due_date']) ? date('M d, Y', strtotime($p['due_date'])) : 'Within 7 Business Days';
        $offence_date_fmt = !empty($p['offence_date']) ? date('M d, Y h:i A', strtotime($p['offence_date'])) : date('M d, Y');
        $violation_title = htmlspecialchars($p['violation_title'] ?? 'Estate Regulation Infraction');
        $violation_code = htmlspecialchars($p['violation_code'] ?? 'INF-RULE');
        $location = htmlspecialchars($p['location'] ?? 'Estate Premises');
        $details = nl2br(htmlspecialchars($p['violation_details'] ?? ''));
        $unit_str = htmlspecialchars($p['property_or_unit'] ?? 'Estate Resident Unit');
        $pay_url = $estate_info['base_url'] . 'pay_invoice.php?penalty=' . urlencode($pref);

        $body = <<<HTML
        <div style="margin-bottom:16px;">
            <span style="display:inline-block;padding:3px 10px;background:#fef2f2;border:1px solid #fecaca;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:0.08em;color:#991b1b;text-transform:uppercase;">Official Infraction Notice &bull; Penalty Assessment</span>
            <h2 style="font-size:20px;font-weight:700;color:#0f172a;margin:12px 0 6px 0;letter-spacing:-0.02em;">Infraction Notice #$pref</h2>
        </div>

        <p style="font-size:14px;color:#334155;margin:0 0 16px 0;line-height:1.6;">
            Dear <strong>$recipient_name</strong> ($unit_str),<br>
            Please be formally advised that an official regulatory infraction has been cited and recorded by Estate Security &amp; Compliance Directorate against your account.
        </p>

        <!-- Infraction Summary Ledger -->
        <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:6px;background:#fafbfc;overflow:hidden;margin:20px 0;">
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Notice Reference:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:700;font-family:monospace;color:#0f172a;">$pref</td>
            </tr>
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Violation Category:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:700;color:#991b1b;">$violation_title ($violation_code)</td>
            </tr>
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Incident Timestamp:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:500;color:#334155;">$offence_date_fmt</td>
            </tr>
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Recorded Location:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:500;color:#334155;">$location</td>
            </tr>
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Settlement Due Date:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:700;color:#0f172a;">$due_date_fmt</td>
            </tr>
            <tr>
                <td style="padding:14px 16px;font-size:13px;font-weight:700;color:#0f172a;text-transform:uppercase;letter-spacing:0.04em;background:#f1f5f9;">Assessed Fine Amount:</td>
                <td align="right" style="padding:14px 16px;font-size:18px;font-weight:800;font-family:monospace;color:#991b1b;background:#f1f5f9;">$fine_fmt</td>
            </tr>
        </table>

        <!-- Details Narrative -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #0f172a;padding:14px 16px;border-radius:4px;margin:20px 0;font-size:13px;color:#334155;line-height:1.6;">
            <strong style="color:#0f172a;display:block;margin-bottom:6px;text-transform:uppercase;font-size:11px;letter-spacing:0.06em;">Citation Details / Compliance Notes:</strong>
            $details
        </div>

        <!-- Settle Fine CTA -->
        <div style="text-align:center;margin:28px 0 20px 0;">
            <a href="$pay_url" class="btn-block" style="background-color:#0f172a;color:#ffffff;text-decoration:none;padding:12px 28px;font-size:14px;font-weight:600;border-radius:6px;display:inline-block;letter-spacing:0.01em;">
                Remit Assessed Penalty ($fine_fmt) &rarr;
            </a>
        </div>

        <div style="background:#fffbeb;border:1px solid #fef3c7;border-radius:4px;padding:12px 14px;font-size:12px;color:#92400e;line-height:1.5;">
            <strong>Right of Appeal:</strong> Under Section 4 of Estate Governance Regulations, you have 5 business days from the receipt of this notice to file a formal appeal or request review with the Estate Disciplinary &amp; Compliance Committee. Failure to remit assessed fines prior to the due date may result in suspension of non-essential estate utilities or automated barrier clearance.
        </div>
HTML;
        $preheader = "Official Infraction Notice #$pref - Fine Assessment: $fine_fmt.";
        $html = self::wrapTemplate($preheader, $body, $estate_info);

        $mail_sent = false;
        if (!empty($recipient_email)) {
            $mail_sent = self::sendMail($recipient_email, $recipient_name, "Infraction Notice #$pref: $violation_title", $html, 'penalty', $pref, $p['estate_id'] ?? null);
            if ($mail_sent && !empty($p['id'])) {
                $conn->query("UPDATE estate_penalties SET email_dispatched = 1 WHERE id = " . intval($p['id']));
            }
        }

        // Also dispatch to WhatsApp via Kapso
        EstateWhatsApp::sendPenaltyNoticeWhatsApp($conn, $penalty_id_or_data);

        return $mail_sent;
    }

    /**
     * 13. Send Emergency / Panic Alert Email to Security, Admin and Residents (Mature Corporate Aesthetic)
     */
    public static function sendEmergencyAlertEmail($conn, $title, $description, $location, $estate_id = null, $alert_code = null) {
        $estate_info = self::getEstateSettings($conn, $estate_id);
        $estate_id = $estate_info['estate_id'];

        $alert_code = $alert_code ?: ('EMG-' . strtoupper(substr(md5(uniqid()), 0, 6)));
        $time_now = date('M d, Y h:i A');

        $body = <<<HTML
        <div style="margin-bottom:16px;">
            <span style="display:inline-block;padding:4px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:4px;font-size:11px;font-weight:800;letter-spacing:0.08em;color:#b91c1c;text-transform:uppercase;">Priority Incident &bull; Emergency Response Advisory</span>
            <h2 style="font-size:20px;font-weight:700;color:#0f172a;margin:12px 0 6px 0;letter-spacing:-0.02em;">$title</h2>
        </div>

        <p style="font-size:14px;color:#334155;margin:0 0 16px 0;line-height:1.6;">
            This is an urgent security notification generated by the <strong>{$estate_info['estate_name']}</strong> emergency command response network.
        </p>

        <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:6px;background:#fafbfc;overflow:hidden;margin:20px 0;">
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Incident Reference:</td>
                <td align="right" style="padding:10px 16px;font-size:14px;font-weight:800;font-family:monospace;color:#b91c1c;">$alert_code</td>
            </tr>
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Incident Category:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:700;color:#0f172a;">$title</td>
            </tr>
            <tr style="border-bottom:1px solid #edf2f7;">
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Incident Location:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:700;color:#0f172a;">$location</td>
            </tr>
            <tr>
                <td style="padding:10px 16px;font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Reported Timestamp:</td>
                <td align="right" style="padding:10px 16px;font-size:13px;font-weight:500;color:#334155;">$time_now</td>
            </tr>
        </table>

        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #b91c1c;padding:14px 16px;border-radius:4px;margin:20px 0;font-size:13px;color:#334155;line-height:1.6;">
            <strong style="color:#0f172a;display:block;margin-bottom:6px;text-transform:uppercase;font-size:11px;letter-spacing:0.06em;">Situation Brief:</strong>
            $description
        </div>

        <div style="background:#f8fafc;border-left:3px solid #334155;padding:12px 14px;font-size:12px;color:#475569;line-height:1.5;">
            <strong>Immediate Directives:</strong> Estate security personnel and quick-response dispatch units have been notified. Please maintain calm, comply with on-duty patrol instructions, and keep emergency telephone channels clear.
        </div>
HTML;
        $preheader = "EMERGENCY ADVISORY: $title at $location (Code: $alert_code).";
        $html = self::wrapTemplate($preheader, $body, $estate_info);

        // Fetch security and admin personnel
        $recipients = [];
        $res = $conn->query("SELECT name, email FROM users WHERE estate_id = $estate_id AND role IN ('admin', 'superadmin', 'security', 'guard') AND email IS NOT NULL AND email != ''");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $recipients[] = $r;
            }
        }

        $sent = 0;
        foreach ($recipients as $rec) {
            if (self::sendMail($rec['email'], $rec['name'], "EMERGENCY ADVISORY: $title [$alert_code]", $html, 'emergency', $alert_code, $estate_id)) {
                $sent++;
            }
        }

        // Also trigger WhatsApp dispatch
        EstateWhatsApp::sendEmergencyAlertWhatsApp($conn, $title, $description, $location, $estate_id, $alert_code);

        return $sent;
    }
}
