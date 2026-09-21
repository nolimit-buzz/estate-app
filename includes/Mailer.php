<?php
// includes/Mailer.php - Core Estate Mailer & Notification Service
require_once __DIR__ . '/../config.php';
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

        // 3. Ensure system_settings default keys exist for current estate
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
            'base_url' => $base_url
        ];
    }

    /**
     * Core Low-Level Email Dispatcher using PHPMailer
     */
    public static function sendMail($toEmail, $toName, $subject, $htmlContent, $template = 'general', $refId = null, $estate_id = null) {
        global $conn;
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

                $mail->Timeout = 15;
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
     * Universal Responsive Email Wrapper
     */
    public static function wrapTemplate($preheader, $bodyContent, $estate_info) {
        $color = $estate_info['theme_color'];
        $name = htmlspecialchars($estate_info['estate_name']);
        $motto = htmlspecialchars($estate_info['estate_motto']);
        $logo = $estate_info['estate_logo_url'];
        $phone = htmlspecialchars($estate_info['office_phone']);
        $email = htmlspecialchars($estate_info['office_email']);
        $location = htmlspecialchars($estate_info['estate_location']);
        $year = date('Y');

        $logoHtml = !empty($logo) 
            ? "<img src='$logo' alt='$name' style='max-height:48px;max-width:180px;display:block;margin:0 auto 10px auto;border-radius:6px;'>" 
            : "<div style='font-size:22px;font-weight:700;color:#ffffff;letter-spacing:-0.5px;'>$name</div>";

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
            .email-container { width: 100% !important; margin: auto !important; }
            .content-padding { padding: 20px 16px !important; }
            .btn-block { display: block !important; width: 100% !important; text-align: center !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;color:#1e293b;">
    <!-- Preheader for preview text in email clients -->
    <div style="display:none;font-size:1px;color:#f1f5f9;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;">
        $preheader
    </div>

    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f1f5f9;padding:24px 0 40px 0;">
        <tr>
            <td align="center">
                <table border="0" cellpadding="0" cellspacing="0" width="600" class="email-container" style="background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 10px 25px -5px rgba(0,0,0,0.05), 0 8px 10px -6px rgba(0,0,0,0.04);border:1px solid #e2e8f0;">
                    
                    <!-- Header -->
                    <tr>
                        <td align="center" style="background:linear-gradient(135deg, $color 0%, #0f172a 100%);padding:28px 24px;text-align:center;">
                            $logoHtml
                            <div style="color:#e2e8f0;font-size:13px;font-weight:500;letter-spacing:0.3px;margin-top:4px;">$motto</div>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td class="content-padding" style="padding:32px 36px;background-color:#ffffff;">
                            $bodyContent
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#f8fafc;padding:24px 36px;border-top:1px solid #e2e8f0;text-align:center;font-size:12px;color:#64748b;line-height:1.6;">
                            <div style="font-weight:600;color:#334155;margin-bottom:4px;">$name</div>
                            <div>$location</div>
                            <div style="margin-top:8px;">
                                <a href="mailto:$email" style="color:$color;text-decoration:none;font-weight:500;">$email</a> &bull; 
                                <span style="color:#475569;">$phone</span>
                            </div>
                            <div style="margin-top:16px;padding-top:12px;border-top:1px dashed #cbd5e1;font-size:11px;color:#94a3b8;">
                                &copy; $year $name. All rights reserved. This is an automated notification from your estate portal.
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
     * 1. Send Invoice Notification Email
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
        $due_date_fmt = date('M d, Y', strtotime($inv['due_date']));
        $inv_no = htmlspecialchars($inv['invoice_number'] ?: ('INV-' . $inv['id']));
        $pay_url = $estate_info['base_url'] . 'pay_invoice.php?inv=' . urlencode($inv_no);
        $color = $estate_info['theme_color'];

        $unit_str = !empty($inv['flat_number']) ? ("Flat " . htmlspecialchars($inv['flat_number']) . ", " . htmlspecialchars($inv['building_name'] ?? '')) : 'Resident';

        $body = <<<HTML
        <div style="font-size:18px;font-weight:700;color:#0f172a;margin-bottom:8px;">New Invoice Generated</div>
        <p style="font-size:14px;color:#475569;margin-top:0;line-height:1.5;">
            Dear <strong>{$inv['resident_name']}</strong> ($unit_str),<br>
            A new billing invoice has been issued to your account for <strong>{$inv['title']}</strong>.
        </p>

        <!-- Invoice Highlight Card -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;margin:20px 0;padding:16px;">
            <tr>
                <td style="padding:8px 12px;font-size:13px;color:#64748b;">Invoice Number:</td>
                <td align="right" style="padding:8px 12px;font-size:14px;font-weight:700;font-family:monospace;color:#0f172a;">$inv_no</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;font-size:13px;color:#64748b;">Item / Description:</td>
                <td align="right" style="padding:8px 12px;font-size:13px;font-weight:600;color:#1e293b;">{$inv['title']}</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;font-size:13px;color:#64748b;">Due Date:</td>
                <td align="right" style="padding:8px 12px;font-size:13px;font-weight:600;color:#dc2626;">$due_date_fmt</td>
            </tr>
            <tr style="border-top:1px dashed #cbd5e1;">
                <td style="padding:12px;font-size:15px;font-weight:700;color:#0f172a;">Amount Due:</td>
                <td align="right" style="padding:12px;font-size:20px;font-weight:800;color:$color;">$amount_fmt</td>
            </tr>
        </table>

        <!-- Pay Button CTA -->
        <div style="text-align:center;margin:28px 0 20px 0;">
            <a href="$pay_url" class="btn-block" style="background-color:$color;color:#ffffff;text-decoration:none;padding:14px 32px;font-size:15px;font-weight:600;border-radius:8px;display:inline-block;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);">
                Pay Now Online ($amount_fmt) &rarr;
            </a>
        </div>

        <p style="font-size:12px;color:#64748b;text-align:center;margin-bottom:0;">
            You can also make a bank transfer or pay via your resident dashboard. If you have already made this payment, please disregard this notice.
        </p>
HTML;

        $preheader = "Invoice #$inv_no for $amount_fmt is now due on $due_date_fmt.";
        $html = self::wrapTemplate($preheader, $body, $estate_info);

        return self::sendMail($inv['resident_email'], $inv['resident_name'], "Invoice #$inv_no - {$inv['title']}", $html, 'invoice', $inv_no, $inv['estate_id']);
    }

    /**
     * 2. Send Payment Receipt Notification Email
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
        $color = $estate_info['theme_color'];
        $paid_date = !empty($rec['issued_at']) ? date('M d, Y h:i A', strtotime($rec['issued_at'])) : date('M d, Y');
        $channel = strtoupper(str_replace(['_', '-'], ' ', $rec['payment_method'] ?? 'Online'));
        $processed_by = !empty($rec['staff_name']) ? $rec['staff_name'] : 'System Automated Gateway';

        $body = <<<HTML
        <div style="text-align:center;margin-bottom:20px;">
            <div style="display:inline-block;background:#ecfdf5;border:1px solid #10b981;color:#047857;padding:6px 16px;border-radius:20px;font-size:13px;font-weight:700;">
                &#10003; PAYMENT SUCCESSFUL
            </div>
            <div style="font-size:20px;font-weight:800;color:#0f172a;margin-top:10px;">Official Payment Receipt</div>
            <div style="font-size:14px;color:#64748b;">Receipt #<strong style="font-family:monospace;color:#0f172a;">$receipt_no</strong></div>
        </div>

        <p style="font-size:14px;color:#475569;line-height:1.5;">
            Dear <strong>{$rec['resident_name']}</strong>,<br>
            Thank you for your payment. Your transaction has been received and credited to your account.
        </p>

        <!-- Receipt Table -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;margin:20px 0;padding:16px;">
            <tr>
                <td style="padding:8px 12px;font-size:13px;color:#64748b;">Amount Paid:</td>
                <td align="right" style="padding:8px 12px;font-size:18px;font-weight:800;color:#10b981;">$amount_fmt</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;font-size:13px;color:#64748b;">Payment For:</td>
                <td align="right" style="padding:8px 12px;font-size:13px;font-weight:600;color:#1e293b;">{$rec['payment_title']}</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;font-size:13px;color:#64748b;">Payment Method:</td>
                <td align="right" style="padding:8px 12px;font-size:13px;font-weight:600;color:#1e293b;">$channel</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;font-size:13px;color:#64748b;">Transaction Reference:</td>
                <td align="right" style="padding:8px 12px;font-size:12px;font-family:monospace;color:#64748b;">{$rec['transaction_ref']}</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;font-size:13px;color:#64748b;">Date & Time:</td>
                <td align="right" style="padding:8px 12px;font-size:13px;font-weight:500;color:#1e293b;">$paid_date</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;font-size:13px;color:#64748b;">Authorized By:</td>
                <td align="right" style="padding:8px 12px;font-size:13px;font-weight:500;color:#1e293b;">$processed_by</td>
            </tr>
        </table>

        <!-- Receipt CTA -->
        <div style="text-align:center;margin:28px 0 20px 0;">
            <a href="$receipt_url" class="btn-block" style="background-color:$color;color:#ffffff;text-decoration:none;padding:14px 32px;font-size:15px;font-weight:600;border-radius:8px;display:inline-block;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);">
                View &amp; Print Official Receipt &rarr;
            </a>
        </div>
HTML;

        $preheader = "Payment Confirmation: Receipt #$receipt_no for $amount_fmt has been processed successfully.";
        $html = self::wrapTemplate($preheader, $body, $estate_info);

        return self::sendMail($rec['resident_email'], $rec['resident_name'], "Payment Receipt #$receipt_no - $amount_fmt", $html, 'receipt', $receipt_no, $rec['estate_id']);
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
        $color = $estate_info['theme_color'];
        $visitor_name = htmlspecialchars($v['name']);
        $arrival = !empty($v['expected_arrival']) ? date('M d, Y h:i A', strtotime($v['expected_arrival'])) : 'Anytime Today';
        $location_str = "Flat " . ($v['flat_number'] ?? 'Unit') . ", " . ($v['building_name'] ?? '') . " (" . ($v['street_name'] ?? 'Estate') . ")";

        // QR Code image using dynamic Google Charts API
        $qr_url = "https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=" . urlencode($visitor_code) . "&choe=UTF-8";

        // Dispatch 1: To Visitor (if email provided)
        if (!empty($v['email']) && filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
            $visitorBody = <<<HTML
            <div style="text-align:center;margin-bottom:16px;">
                <div style="font-size:18px;font-weight:700;color:#0f172a;">Official Visitor Gate Pass</div>
                <div style="font-size:13px;color:#64748b;margin-top:4px;">Present this pass or code at the estate security gate.</div>
            </div>

            <!-- Pass Ticket Box -->
            <div style="background:#f8fafc;border:2px dashed $color;border-radius:12px;padding:24px;text-align:center;margin:20px 0;">
                <div style="font-size:12px;font-weight:700;letter-spacing:1px;color:#64748b;text-transform:uppercase;">Gate Access Code</div>
                <div style="font-size:32px;font-weight:800;letter-spacing:3px;color:$color;font-family:monospace;margin:8px 0;">$visitor_code</div>
                
                <div style="margin:16px auto;">
                    <img src="$qr_url" alt="Pass QR Code" width="140" height="140" style="border:1px solid #cbd5e1;padding:6px;background:#ffffff;border-radius:8px;">
                </div>

                <div style="font-size:13px;color:#475569;margin-top:12px;">
                    <strong>Host:</strong> {$v['resident_name']}<br>
                    <strong>Destination:</strong> $location_str<br>
                    <strong>Expected Time:</strong> $arrival<br>
                    <strong>Purpose:</strong> {$v['purpose']}
                </div>
            </div>

            <div style="text-align:center;margin:24px 0 16px 0;">
                <a href="$pass_url" class="btn-block" style="background-color:$color;color:#ffffff;text-decoration:none;padding:12px 28px;font-size:14px;font-weight:600;border-radius:8px;display:inline-block;">
                    Open Digital Gate Pass &rarr;
                </a>
            </div>
            <div style="font-size:12px;color:#64748b;text-align:center;">Security Gate Rules: Please keep your photo ID ready upon arrival. Speed limit inside estate is 20km/h.</div>
HTML;
            $preheader = "Your digital visitor pass code for {$estate_info['estate_name']} is $visitor_code.";
            $html = self::wrapTemplate($preheader, $visitorBody, $estate_info);
            self::sendMail($v['email'], $visitor_name, "Visitor Gate Pass: $visitor_code", $html, 'visitor_pass', $visitor_code, $v['estate_id']);
        }

        // Dispatch 2: Confirmation copy to Resident
        if (!empty($v['resident_email'])) {
            $residentBody = <<<HTML
            <div style="font-size:18px;font-weight:700;color:#0f172a;margin-bottom:8px;">Visitor Pass Created</div>
            <p style="font-size:14px;color:#475569;margin-top:0;line-height:1.5;">
                Dear <strong>{$v['resident_name']}</strong>,<br>
                You have successfully pre-registered visitor <strong>$visitor_name</strong>.
            </p>

            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;margin:20px 0;padding:16px;">
                <tr>
                    <td style="padding:8px 12px;font-size:13px;color:#64748b;">Visitor Name:</td>
                    <td align="right" style="padding:8px 12px;font-size:14px;font-weight:700;color:#0f172a;">$visitor_name</td>
                </tr>
                <tr>
                    <td style="padding:8px 12px;font-size:13px;color:#64748b;">Access Code:</td>
                    <td align="right" style="padding:8px 12px;font-size:18px;font-weight:800;font-family:monospace;color:$color;">$visitor_code</td>
                </tr>
                <tr>
                    <td style="padding:8px 12px;font-size:13px;color:#64748b;">Expected Arrival:</td>
                    <td align="right" style="padding:8px 12px;font-size:13px;font-weight:600;color:#1e293b;">$arrival</td>
                </tr>
                <tr>
                    <td style="padding:8px 12px;font-size:13px;color:#64748b;">Purpose:</td>
                    <td align="right" style="padding:8px 12px;font-size:13px;font-weight:500;color:#1e293b;">{$v['purpose']}</td>
                </tr>
            </table>

            <div style="text-align:center;margin:24px 0 16px 0;">
                <a href="$pass_url" class="btn-block" style="background-color:$color;color:#ffffff;text-decoration:none;padding:12px 28px;font-size:14px;font-weight:600;border-radius:8px;display:inline-block;">
                    Share / View Gate Pass &rarr;
                </a>
            </div>
            <p style="font-size:12px;color:#64748b;text-align:center;">You will receive an automatic security notification the moment $visitor_name arrives at the gate.</p>
HTML;
            $preheader = "Pass created for $visitor_name (Code: $visitor_code).";
            $html = self::wrapTemplate($preheader, $residentBody, $estate_info);
            self::sendMail($v['resident_email'], $v['resident_name'], "Pass Created: $visitor_name (Code: $visitor_code)", $html, 'visitor_pass', $visitor_code, $v['estate_id']);
        }

        return true;
    }

    /**
     * 4. Send Instant Visitor Gate Arrival Alert to Resident
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
        <div style="text-align:center;margin-bottom:16px;">
            <div style="display:inline-block;background:#eff6ff;border:1px solid #3b82f6;color:#1d4ed8;padding:6px 16px;border-radius:20px;font-size:13px;font-weight:700;">
                &#128276; GATE ARRIVAL ALERT
            </div>
            <div style="font-size:20px;font-weight:800;color:#0f172a;margin-top:10px;">Your Visitor Has Arrived</div>
        </div>

        <p style="font-size:15px;color:#334155;line-height:1.6;">
            Dear <strong>{$v['resident_name']}</strong>,<br>
            Your visitor <strong>$visitor_name</strong> (Pass: <code style="font-weight:700;color:#2563eb;">$visitor_code</code>) has just checked in at <strong>$gate</strong> at <strong>$time_now</strong> and is proceeding to your residence.
        </p>

        <div style="background:#f8fafc;border-left:4px solid #3b82f6;padding:14px 18px;margin:20px 0;border-radius:4px;font-size:13px;color:#475569;">
            <strong>Security Notice:</strong> If you were not expecting this visitor, please contact the gate security immediately via your intercom or estate helpdesk.
        </div>
HTML;
        $preheader = "Visitor Arrival: $visitor_name has just checked in at $gate.";
        $html = self::wrapTemplate($preheader, $body, $estate_info);

        return self::sendMail($v['resident_email'], $v['resident_name'], "Visitor Arrived: $visitor_name at $gate", $html, 'visitor_arrival', $visitor_code, $v['estate_id']);
    }

    /**
     * 5. Send Welcome & Credentials Email to New Resident or Staff
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
        $color = $estate_info['theme_color'];

        $body = <<<HTML
        <div style="font-size:18px;font-weight:700;color:#0f172a;margin-bottom:8px;">Welcome to {$estate_info['estate_name']}</div>
        <p style="font-size:14px;color:#475569;margin-top:0;line-height:1.5;">
            Dear <strong>{$user['name']}</strong>,<br>
            Your official portal account has been created with access as a <strong>$role_label</strong>.
        </p>

        <!-- Credentials Box -->
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;margin:20px 0;padding:16px;">
            <tr>
                <td style="padding:8px 12px;font-size:13px;color:#64748b;">Portal Web Address:</td>
                <td align="right" style="padding:8px 12px;font-size:13px;font-weight:600;"><a href="$login_url" style="color:$color;">$login_url</a></td>
            </tr>
            <tr>
                <td style="padding:8px 12px;font-size:13px;color:#64748b;">Login Email:</td>
                <td align="right" style="padding:8px 12px;font-size:14px;font-weight:700;font-family:monospace;color:#0f172a;">{$user['email']}</td>
            </tr>
            <tr>
                <td style="padding:8px 12px;font-size:13px;color:#64748b;">Temporary Password:</td>
                <td align="right" style="padding:8px 12px;font-size:14px;font-weight:700;font-family:monospace;color:#dc2626;">$plain_password</td>
            </tr>
        </table>

        <div style="text-align:center;margin:24px 0 20px 0;">
            <a href="$login_url" class="btn-block" style="background-color:$color;color:#ffffff;text-decoration:none;padding:13px 32px;font-size:14px;font-weight:600;border-radius:8px;display:inline-block;">
                Log In to Your Portal &rarr;
            </a>
        </div>

        <p style="font-size:12px;color:#64748b;text-align:center;">
            For security purposes, please change your password immediately after logging in.
        </p>
HTML;
        $preheader = "Welcome to {$estate_info['estate_name']} - Your Portal Login Credentials";
        $html = self::wrapTemplate($preheader, $body, $estate_info);

        return self::sendMail($user['email'], $user['name'], "Welcome to {$estate_info['estate_name']} - Login Credentials", $html, 'welcome', $user['id'], $user['estate_id']);
    }

    /**
     * 6. Send Broadcast Email to multiple recipients
     */
    public static function sendBroadcastEmail($conn, $subject, $content, $target_role = 'resident', $estate_id = null, $zone_id = null) {
        $estate_info = self::getEstateSettings($conn, $estate_id);
        $estate_id = $estate_info['estate_id'];

        $target_role = $conn->real_escape_string($target_role);

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
            if ($target_role !== 'all' && $target_role !== 'residents' && $target_role !== 'tenants') {
                $sql .= " AND u.role = '$target_role'";
            }
            $res = $conn->query($sql);
        } else {
            $where = ($target_role === 'all') ? "estate_id = $estate_id" : "estate_id = $estate_id AND role = '$target_role'";
            $res = $conn->query("SELECT name, email FROM users WHERE $where AND email IS NOT NULL AND email != ''");
        }

        if (!$res) return 0;

        $count = 0;
        while ($u = $res->fetch_assoc()) {
            $body = <<<HTML
            <div style="font-size:18px;font-weight:700;color:#0f172a;margin-bottom:12px;">$subject</div>
            <div style="font-size:14px;color:#334155;line-height:1.7;">
                Dear {$u['name']},<br><br>
                $content
            </div>
HTML;
            $preheader = substr(strip_tags($content), 0, 100);
            $html = self::wrapTemplate($preheader, $body, $estate_info);
            if (self::sendMail($u['email'], $u['name'], $subject, $html, 'broadcast', null, $estate_id)) {
                $count++;
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
        <div style="text-align:center;margin-bottom:16px;">
            <div style="display:inline-block;background:#ecfdf5;border:1px solid #10b981;color:#047857;padding:6px 16px;border-radius:20px;font-size:13px;font-weight:700;">
                &#10003; TEST EMAIL SUCCESSFUL
            </div>
            <div style="font-size:20px;font-weight:800;color:#0f172a;margin-top:10px;">SMTP Configuration Verified</div>
        </div>
        <p style="font-size:14px;color:#334155;line-height:1.6;text-align:center;">
            Congratulations! Your email system for <strong>{$estate_info['estate_name']}</strong> is configured properly and ready to deliver invoices, payment receipts, gate passes, and security alerts.
        </p>
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;margin:20px 0;padding:16px;">
            <tr>
                <td style="padding:6px 12px;font-size:13px;color:#64748b;">SMTP Host:</td>
                <td align="right" style="padding:6px 12px;font-size:13px;font-weight:600;font-family:monospace;">{$estate_info['smtp_host']}</td>
            </tr>
            <tr>
                <td style="padding:6px 12px;font-size:13px;color:#64748b;">SMTP Port:</td>
                <td align="right" style="padding:6px 12px;font-size:13px;font-weight:600;font-family:monospace;">{$estate_info['smtp_port']} ({$estate_info['smtp_encryption']})</td>
            </tr>
            <tr>
                <td style="padding:6px 12px;font-size:13px;color:#64748b;">From Address:</td>
                <td align="right" style="padding:6px 12px;font-size:13px;font-weight:600;">{$estate_info['smtp_from_email']}</td>
            </tr>
            <tr>
                <td style="padding:6px 12px;font-size:13px;color:#64748b;">Timestamp:</td>
                <td align="right" style="padding:6px 12px;font-size:13px;color:#64748b;">$now_str</td>
            </tr>
        </table>
HTML;
        $html = self::wrapTemplate("SMTP Diagnostic Test Message", $testBody, $estate_info);
        return self::sendMail($testRecipient, "Administrator", "SMTP Test Verification - {$estate_info['estate_name']}", $html, 'test_diagnostic', null, $estate_id);
    }
}
