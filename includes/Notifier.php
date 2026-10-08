<?php
// includes/Notifier.php - platform notifications (registration, OTP, invitations, billing, lifecycle).
// Templates live in the DB (email_templates) and are editable by the Super Admin; sending is queued with retry
// and failures surface on the Super Admin monitoring screen.
require_once __DIR__ . '/Tenancy.php';
require_once __DIR__ . '/PlatformSettings.php';
require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

class Notifier {
    public static function baseUrl() { return rtrim(env_get('APP_URL', 'http://localhost/Estate'), '/'); }

    /** Absolute URL of a page inside an estate (subdomain if configured, else carries ?estate=code). */
    public static function estateUrl(array $estate, $path = 'login') {
        $base = trim(env_get('BASE_DOMAIN', ''));
        $path = ltrim($path, '/');
        if ($base !== '') {
            $scheme = parse_url(self::baseUrl(), PHP_URL_SCHEME) ?: 'https';
            return $scheme . '://' . $estate['domain_prefix'] . '.' . $base . '/' . $path;
        }
        return self::baseUrl() . '/' . $path . (strpos($path, '?') === false ? '?' : '&') . 'estate=' . urlencode($estate['domain_prefix']);
    }

    public static function baseVars() {
        return [
            'company' => PlatformSettings::get('site_company_name', 'Estate Platform'),
            'support_email' => PlatformSettings::get('site_support_email', ''),
            'support_phone' => PlatformSettings::get('site_support_phone', ''),
        ];
    }

    public static function template($key) {
        $st = Tenancy::platform()->prepare("SELECT * FROM email_templates WHERE template_key = ? AND is_active = 1");
        $st->bind_param('s', $key);
        $st->execute();
        return $st->get_result()->fetch_assoc() ?: null;
    }

    public static function fill($text, array $vars, $escape = true) {
        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function ($m) use ($vars, $escape) {
            if (!array_key_exists($m[1], $vars)) return '';
            $v = (string)$vars[$m[1]];
            if (!$escape) return $v;
            return (substr($m[1], -5) === '_html') ? $v : (preg_match('/(^|_)(link|url)$/', $m[1]) ? htmlspecialchars($v, ENT_QUOTES) : htmlspecialchars($v));
        }, $text);
    }

    /** Branded HTML shell around a message body. */
    public static function layout($subject, $bodyHtml, array $estate = null) {
        $v = self::baseVars();
        $company = htmlspecialchars($v['company']);
        $ctx = $estate ? '<div style="font-size:12px;color:#64748b;margin-bottom:6px">' . htmlspecialchars($estate['name']) . '</div>' : '';
        $contact = trim(($v['support_email'] ? htmlspecialchars($v['support_email']) : '') . ($v['support_phone'] ? ' &middot; ' . htmlspecialchars($v['support_phone']) : ''), ' &middot;');
        $legal = PlatformSettings::get('company_legal_footer', '');
        return '<!doctype html><html><body style="margin:0;background:#f4f6fb;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#0f172a">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="100%" style="max-width:560px;background:#ffffff;border:1px solid #e2e8f0;border-radius:14px" cellpadding="0" cellspacing="0">'
            . '<tr><td style="padding:22px 28px;border-bottom:1px solid #e2e8f0;font-weight:700;font-size:18px;color:#2563eb">' . $company . '</td></tr>'
            . '<tr><td style="padding:26px 28px;font-size:15px;line-height:1.6">' . $ctx . $bodyHtml . '</td></tr>'
            . '<tr><td style="padding:18px 28px;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b">'
            . ($contact ? 'Need help? ' . $contact . '<br>' : '') . ($legal ? htmlspecialchars($legal) . '<br>' : '') . '&copy; ' . date('Y') . ' ' . $company
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /**
     * Render + queue a template email. Returns queue id (0 on failure to queue).
     * @param array|null $estate platform estates row (for branding context)
     */
    public static function queue($templateKey, $toEmail, $toName, array $vars = [], array $estate = null, $dedupeKey = null) {
        if (!PlatformSettings::bool('notify_email_enabled', true) && !in_array($templateKey, ['otp_code', 'registration_welcome', 'password_reset', 'invitation'], true)) return 0;
        $tpl = self::template($templateKey);
        if (!$tpl) { Tenancy::logError('email', "Missing email template '$templateKey'", $estate['id'] ?? null); return 0; }
        $p = Tenancy::platform();
        if ($dedupeKey) {
            $c = $p->prepare("SELECT id FROM email_queue WHERE dedupe_key = ? LIMIT 1");
            $c->bind_param('s', $dedupeKey);
            $c->execute();
            if ($c->get_result()->num_rows) return 0;
        }
        $vars = array_merge(self::baseVars(), $estate ? ['estate_name' => $estate['name']] : [], ['name' => $toName ?: 'there'], $vars);
        $subject = html_entity_decode(strip_tags(self::fill($tpl['subject'], $vars)), ENT_QUOTES);
        $body = self::layout($subject, self::fill($tpl['body_html'], $vars), $estate);
        $eid = $estate['id'] ?? null;
        $st = $p->prepare("INSERT INTO email_queue (estate_id, template_key, to_email, to_name, subject, body_html, dedupe_key, next_attempt_at) VALUES (?,?,?,?,?,?,?,NOW())");
        $st->bind_param('issssss', $eid, $templateKey, $toEmail, $toName, $subject, $body, $dedupeKey);
        $st->execute();
        return $p->insert_id;
    }

    /** Queue and try to deliver immediately. */
    public static function send($templateKey, $toEmail, $toName, array $vars = [], array $estate = null, $dedupeKey = null) {
        $id = self::queue($templateKey, $toEmail, $toName, $vars, $estate, $dedupeKey);
        if ($id) self::deliver($id);
        return $id > 0;
    }

    public static function deliver($queueId) {
        $p = Tenancy::platform();
        $q = $p->query("SELECT * FROM email_queue WHERE id = " . intval($queueId) . " AND status <> 'sent'")->fetch_assoc();
        if (!$q) return false;
        try {
            self::smtp($q['to_email'], $q['to_name'], $q['subject'], $q['body_html']);
            $p->query("UPDATE email_queue SET status='sent', sent_at=NOW(), attempts=attempts+1, last_error=NULL WHERE id=" . intval($queueId));
            return true;
        } catch (Throwable $e) {
            $attempts = intval($q['attempts']) + 1;
            $msg = substr($e->getMessage(), 0, 480);
            $status = $attempts >= 5 ? 'failed' : 'queued';
            $delay = min(60 * $attempts * $attempts, 3600);
            $st = $p->prepare("UPDATE email_queue SET status=?, attempts=?, last_error=?, next_attempt_at = NOW() + INTERVAL ? SECOND WHERE id=?");
            $st->bind_param('sisii', $status, $attempts, $msg, $delay, $queueId);
            $st->execute();
            $t = $q['template_key'] ?: 'email';
            Tenancy::logError(in_array($t, ['invitation'], true) ? 'invitation' : 'email', "Email '$t' to {$q['to_email']} failed: $msg", $q['estate_id'] ? intval($q['estate_id']) : null, $status === 'failed' ? 'error' : 'warning');
            return false;
        }
    }

    /** Cron: retry queued mail. */
    public static function flushQueue($limit = 50) {
        $r = Tenancy::platform()->query("SELECT id FROM email_queue WHERE status='queued' AND (next_attempt_at IS NULL OR next_attempt_at <= NOW()) ORDER BY id LIMIT " . intval($limit));
        $n = 0;
        while ($r && $row = $r->fetch_assoc()) { if (self::deliver($row['id'])) $n++; }
        return $n;
    }

    private static function smtp($toEmail, $toName, $subject, $html) {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid recipient address');
        // Local development: always keep a readable copy so OTP / invite links can be retrieved without SMTP.
        if (env_get('APP_ENV', 'production') === 'local') {
            $dir = dirname(__DIR__) . '/storage/logs';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            @file_put_contents($dir . '/mail.log', "==== " . date('c') . " TO: $toEmail ($toName)\nSUBJECT: $subject\n" . trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace('/<a [^>]*href="([^"]+)"[^>]*>/i', '[$1] ', $html))))) . "\n\n", FILE_APPEND);
        }
        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $host = env_get('PLATFORM_SMTP_HOST', '');
        if (env_get('PLATFORM_SMTP_ENABLED', '0') === '1' && $host !== '') {
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = intval(env_get('PLATFORM_SMTP_PORT', 465));
            $user = env_get('PLATFORM_SMTP_USER', '');
            $mail->SMTPAuth = $user !== '';
            $mail->Username = $user;
            $mail->Password = env_get('PLATFORM_SMTP_PASS', '');
            $enc = strtolower(env_get('PLATFORM_SMTP_ENCRYPTION', 'ssl'));
            $mail->SMTPSecure = $enc === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($enc === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : false);
            $mail->Timeout = 8;
        } elseif (env_get('APP_ENV', 'production') === 'local') {
            return; // dev: logged above, nothing to send
        } else {
            $mail->isMail();
        }
        $mail->setFrom(env_get('PLATFORM_MAIL_FROM', 'noreply@localhost'), env_get('PLATFORM_MAIL_FROM_NAME', PlatformSettings::get('site_company_name', 'Estate Platform')));
        $mail->addAddress($toEmail, (string)$toName);
        $reply = PlatformSettings::get('site_support_email', '');
        if ($reply) $mail->addReplyTo($reply);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = trim(html_entity_decode(strip_tags($html)));
        $mail->send();
    }

    /** Email every active Central Admin of an estate (reads tenant DB). */
    public static function toCentralAdmins($templateKey, array $estate, array $vars = [], $dedupeKey = null) {
        $sent = 0;
        foreach (self::centralAdmins($estate) as $a) {
            $d = $dedupeKey ? $dedupeKey . ':' . $a['id'] : null;
            if (self::send($templateKey, $a['email'], $a['name'], $vars, $estate, $d)) $sent++;
        }
        return $sent;
    }

    public static function centralAdmins(array $estate) {
        $out = [];
        try {
            $t = Tenancy::openTenant($estate);
            $r = $t->query("SELECT id, name, email FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id ASC");
            while ($r && $row = $r->fetch_assoc()) $out[] = $row;
            $t->close();
        } catch (Throwable $e) {}
        if (!$out && !empty($estate['contact_email'])) $out[] = ['id' => 0, 'name' => $estate['name'], 'email' => $estate['contact_email']];
        return $out;
    }

    public static function notifySuperAdmin($subject, $message) {
        $to = PlatformSettings::get('notify_super_admin_email', '');
        if (!$to) return;
        self::send('security_alert', $to, 'Platform admin', ['message' => $subject . ' - ' . $message]);
    }
}
