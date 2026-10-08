<?php
// includes/EstateService.php - estate lifecycle: registration, verification, status transitions.
require_once __DIR__ . '/Tenancy.php';
require_once __DIR__ . '/PlatformSettings.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/Notifier.php';
require_once __DIR__ . '/RateLimit.php';

class EstateService {
    const RESERVED = ['www', 'admin', 'api', 'app', 'mail', 'smtp', 'ftp', 'superadmin', 'support', 'billing', 'status', 'platform', 'login', 'signup', 'static', 'cdn', 'assets', 'demo', 'test', 'estate', 'localhost', 'staging'];

    const ESTATE_STATUSES = ['pending', 'onboarding', 'trial', 'active', 'grace_period', 'suspended', 'retention', 'deletion_pending', 'deleted', 'archived'];

    public static function slugify($s) {
        $s = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $s), '-'));
        return substr($s ?: 'estate', 0, 40);
    }

    public static function prefixAvailable($prefix) {
        if ($prefix === '' || !preg_match('/^[a-z0-9]([a-z0-9-]{1,38}[a-z0-9])$/', $prefix) || in_array($prefix, self::RESERVED, true)) return false;
        return Tenancy::findByPrefix($prefix) === null;
    }

    public static function uniquePrefix($base) {
        $base = self::slugify($base);
        $p = $base; $i = 2;
        while (!self::prefixAvailable($p)) { $p = substr($base, 0, 34) . '-' . $i++; if ($i > 200) throw new RuntimeException('No free estate code'); }
        return $p;
    }

    public static function validatePassword($pw) {
        $min = max(8, PlatformSettings::int('min_password_length', 10));
        if (strlen($pw) < $min) return "Password must be at least $min characters.";
        if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) return 'Password must include letters and numbers.';
        return null;
    }

    public static function emailTakenAnywhere($email) {
        $st = Tenancy::platform()->prepare("SELECT id FROM estates WHERE LOWER(central_admin_email) = ? AND status NOT IN ('deleted','archived') LIMIT 1");
        $e = strtolower($email);
        $st->bind_param('s', $e);
        $st->execute();
        return $st->get_result()->num_rows > 0;
    }

    /**
     * Register a new estate: platform record -> EMPTY isolated tenant DB -> first Central Admin (unverified).
     * @param array $in estate_name, organisation_type, address, city, state, country, contact_phone, estate_code (optional),
     *                  admin_name, admin_email, admin_phone, password (optional when created by the Super Admin), want_trial
     * @return array ['estate'=>row, 'otp'=>..., 'token'=>...]
     */
    public static function register(array $in, $via = 'public') {
        $name = trim($in['estate_name'] ?? '');
        $adminName = trim($in['admin_name'] ?? '');
        $email = strtolower(trim($in['admin_email'] ?? ''));
        if ($name === '' || strlen($name) > 150) throw new InvalidArgumentException('Please enter the estate name.');
        if ($adminName === '') throw new InvalidArgumentException('Please enter the administrator name.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Please enter a valid email address.');
        $password = (string)($in['password'] ?? '');
        $needsPassword = ($password === '');
        if (!$needsPassword) { $err = self::validatePassword($password); if ($err) throw new InvalidArgumentException($err); }
        if (self::emailTakenAnywhere($email)) throw new InvalidArgumentException('That email address is already used to manage another estate.');

        $prefix = trim($in['estate_code'] ?? '') !== '' ? strtolower(trim($in['estate_code'])) : self::uniquePrefix($name);
        if (!self::prefixAvailable($prefix)) throw new InvalidArgumentException('That estate code is not available. Please choose another.');

        $p = Tenancy::platform();
        $phone = trim($in['contact_phone'] ?? ''); $addr = trim($in['address'] ?? ''); $city = trim($in['city'] ?? '');
        $state = trim($in['state'] ?? ''); $country = trim($in['country'] ?? 'Nigeria') ?: 'Nigeria'; $org = trim($in['organisation_type'] ?? '');
        $onb = json_encode(['want_trial' => !empty($in['want_trial'])]);
        $st = $p->prepare("INSERT INTO estates (name, domain_prefix, organisation_type, address, city, state, country, contact_email, contact_phone, status, onboarding_status, created_via, central_admin_email, onboarding_json)
            VALUES (?,?,?,?,?,?,?,?,?,'pending','registration_started',?,?,?)");
        $st->bind_param('ssssssssssss', $name, $prefix, $org, $addr, $city, $state, $country, $email, $phone, $via, $email, $onb);
        $st->execute();
        $estateId = $p->insert_id;

        try {
            Tenancy::provision($estateId);
            $estate = Tenancy::find($estateId);
            $tenant = Tenancy::openTenant($estate);
            $hash = password_hash($needsPassword ? bin2hex(random_bytes(24)) : $password, PASSWORD_DEFAULT);
            $adminPhone = trim($in['admin_phone'] ?? '');
            $parts = preg_split('/\s+/', $adminName, 2);
            $fn = $parts[0]; $ln = $parts[1] ?? '';
            $u = $tenant->prepare("INSERT INTO users (estate_id, name, first_name, last_name, email, phone, password, role, status, force_password_change) VALUES (?,?,?,?,?,?,?,'admin','pending',0)");
            $u->bind_param('issssss', $estateId, $adminName, $fn, $ln, $email, $adminPhone, $hash);
            $u->execute();
            $userId = $tenant->insert_id;
            $tenant->close();
        } catch (Throwable $e) {
            Tenancy::logError('tenant', 'Registration provisioning failed: ' . $e->getMessage(), $estateId, 'critical');
            // Roll back the half-created estate so nothing orphaned remains.
            try { Tenancy::dropTenant($estateId); } catch (Throwable $x) {}
            $p->query("DELETE FROM estates WHERE id = " . intval($estateId));
            throw new RuntimeException('We could not create your estate environment. Please try again or contact support.');
        }

        [$otp, $token] = self::issueVerification($estateId, $userId, $email, $needsPassword, 'registration');
        self::setOnboarding($estateId, 'email_verification_pending');
        $estate = Tenancy::find($estateId);
        Notifier::send('registration_welcome', $email, $adminName, [
            'otp' => $otp, 'expiry_minutes' => PlatformSettings::int('otp_expiry_minutes', 10),
            'link' => Notifier::baseUrl() . '/verify_email?token=' . $token,
        ], $estate);
        Audit::log('estate.created', ['estate_id' => $estateId, 'module' => 'estates', 'entity_type' => 'estate', 'entity_id' => $estateId,
            'details' => "Estate '$name' registered via $via; empty tenant database provisioned; Central Admin $email awaiting verification.",
            'after' => ['name' => $name, 'code' => $prefix, 'admin_email' => $email], 'actor' => self::registrationActor()]);
        return ['estate' => $estate, 'otp' => $otp, 'token' => $token, 'user_id' => $userId];
    }

    private static function registrationActor() {
        return !empty($_SESSION['platform_user_id']) ? Audit::actor() : ['system', null, 'public sign-up', 'visitor'];
    }

    /** Creates OTP + link token. @return array [otp, token] */
    public static function issueVerification($estateId, $userId, $email, $needsPassword, $purpose = 'registration', $expiryMinutes = null) {
        $p = Tenancy::platform();
        $email = strtolower($email);
        $p->query("UPDATE email_verifications SET used_at = NOW() WHERE email = '" . $p->real_escape_string($email) . "' AND purpose = '" . $p->real_escape_string($purpose) . "' AND used_at IS NULL");
        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $token = Crypto::token(24);
        $oh = hash('sha256', $otp . '|' . $email); $th = Crypto::hashToken($token);
        $exp = $expiryMinutes ?? ($purpose === 'password_reset' ? PlatformSettings::int('password_reset_expiry_minutes', 30) : PlatformSettings::int('otp_expiry_minutes', 10));
        $np = $needsPassword ? 1 : 0; $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $st = $p->prepare("INSERT INTO email_verifications (purpose, estate_id, user_id, email, otp_hash, token_hash, needs_password, expires_at, ip_address) VALUES (?,?,?,?,?,?,?, NOW() + INTERVAL ? MINUTE, ?)");
        $st->bind_param('siisssiis', $purpose, $estateId, $userId, $email, $oh, $th, $np, $exp, $ip);
        $st->execute();
        return [$otp, $token];
    }

    /** Look up a pending verification by link token (no state change). */
    public static function verificationByToken($token, $purpose = 'registration') {
        $th = Crypto::hashToken($token);
        $st = Tenancy::platform()->prepare("SELECT * FROM email_verifications WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
        $st->bind_param('ss', $th, $purpose);
        $st->execute();
        return $st->get_result()->fetch_assoc() ?: null;
    }

    /**
     * Confirm email using the OTP (+email) or link token. Rate limited; OTP burns after max attempts.
     * @return array ['ok'=>bool,'error'=>string,'estate'=>row,'needs_password'=>bool,'verification_id'=>int]
     */
    public static function confirmVerification($email, $otp, $token = null, $purpose = 'registration') {
        $p = Tenancy::platform();
        $email = strtolower(trim($email));
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0';
        if (RateLimit::lockedFor('otp', $ip . '|' . $email) > 0) return ['ok' => false, 'error' => 'Too many attempts. Please wait a few minutes before trying again.'];

        if ($token) {
            $row = self::verificationByToken($token, $purpose);
            if ($row) { $email = $row['email']; }
        } else {
            $st = $p->prepare("SELECT * FROM email_verifications WHERE email = ? AND purpose = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1");
            $st->bind_param('ss', $email, $purpose);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
        }
        if (!$row) { RateLimit::hit('otp', $ip . '|' . $email, 8, 15); return ['ok' => false, 'error' => 'This verification code is invalid or has expired. Request a new one.']; }
        if (strtotime($row['expires_at']) < time()) return ['ok' => false, 'error' => 'This code has expired. Please request a new one.'];
        $maxAttempts = PlatformSettings::int('otp_max_attempts', 5);
        if (intval($row['attempts']) >= $maxAttempts) return ['ok' => false, 'error' => 'Too many incorrect attempts. Please request a new code.'];

        if (!$token) {
            $given = hash('sha256', preg_replace('/\D/', '', (string)$otp) . '|' . $email);
            if (!hash_equals($row['otp_hash'], $given)) {
                $p->query("UPDATE email_verifications SET attempts = attempts + 1 WHERE id = " . intval($row['id']));
                RateLimit::hit('otp', $ip . '|' . $email, 8, 15);
                $left = max(0, $maxAttempts - intval($row['attempts']) - 1);
                Audit::login('otp_failed', false, ['email' => $email, 'estate_id' => $row['estate_id'], 'reason' => 'bad_otp']);
                return ['ok' => false, 'error' => "Incorrect code. $left attempt(s) left."];
            }
        }
        $estate = Tenancy::find($row['estate_id']);
        return ['ok' => true, 'row' => $row, 'estate' => $estate, 'needs_password' => (bool)$row['needs_password'], 'verification_id' => $row['id']];
    }

    /** Finish registration verification: activate Central Admin, advance onboarding, open onboarding ticket. */
    public static function completeRegistration($verificationId, $newPassword = null) {
        $p = Tenancy::platform();
        $row = $p->query("SELECT * FROM email_verifications WHERE id = " . intval($verificationId) . " AND used_at IS NULL")->fetch_assoc();
        if (!$row) throw new RuntimeException('Verification not found');
        $estate = Tenancy::find($row['estate_id']);
        $tenant = Tenancy::openTenant($estate);
        $uid = intval($row['user_id']);
        if ($row['needs_password']) {
            $err = self::validatePassword((string)$newPassword);
            if ($err) throw new InvalidArgumentException($err);
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $s = $tenant->prepare("UPDATE users SET password = ?, status = 'active', email_verified_at = NOW(), password_changed_at = NOW() WHERE id = ?");
            $s->bind_param('si', $hash, $uid);
        } else {
            $s = $tenant->prepare("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = ?");
            $s->bind_param('i', $uid);
        }
        $s->execute();
        $admin = $tenant->query("SELECT id, name, email FROM users WHERE id = $uid")->fetch_assoc();
        $tenant->close();
        $p->query("UPDATE email_verifications SET used_at = NOW() WHERE id = " . intval($verificationId));
        RateLimit::clear('otp', ($_SERVER['REMOTE_ADDR'] ?? '0') . '|' . $row['email']);

        $onb = json_decode($estate['onboarding_json'] ?? '{}', true) ?: [];
        // Estate leaves "pending": either a trial (if chosen & enabled) or the onboarding workflow
        if (!empty($onb['want_trial']) && PlatformSettings::bool('trial_enabled', true)) {
            self::startTrial($estate['id']);
        } else {
            self::setStatus($estate['id'], 'onboarding', [], 'Central Admin verified email');
        }
        self::setOnboarding($estate['id'], 'onboarding_pending');
        require_once __DIR__ . '/Support.php';
        Support::createOnboardingTicket(Tenancy::find($estate['id']), $admin);
        Audit::log('user.email_verified', ['estate_id' => $estate['id'], 'module' => 'auth', 'entity_type' => 'user', 'entity_id' => $uid,
            'details' => "Central Admin {$admin['email']} verified their email; account activated.", 'actor' => ['tenant', $uid, $admin['name'], 'admin']]);
        return ['estate' => Tenancy::find($estate['id']), 'admin' => $admin];
    }

    public static function resendVerification($email) {
        $email = strtolower(trim($email));
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0';
        if (RateLimit::lockedFor('otp_resend', $ip . '|' . $email) > 0) return false;
        RateLimit::hit('otp_resend', $ip . '|' . $email, 3, 10, 10);
        $st = Tenancy::platform()->prepare("SELECT * FROM email_verifications WHERE email = ? AND purpose = 'registration' ORDER BY id DESC LIMIT 1");
        $st->bind_param('s', $email);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if (!$row) return true; // do not reveal whether the address exists
        $estate = Tenancy::find($row['estate_id']);
        if (!$estate) return true;
        $tenant = Tenancy::openTenant($estate);
        $u = $tenant->query("SELECT id, name, status FROM users WHERE id = " . intval($row['user_id']))->fetch_assoc();
        $tenant->close();
        if (!$u || $u['status'] !== 'pending') return true;
        [$otp, $token] = self::issueVerification($estate['id'], $u['id'], $email, (bool)$row['needs_password'], 'registration');
        Notifier::send('registration_welcome', $email, $u['name'], ['otp' => $otp, 'expiry_minutes' => PlatformSettings::int('otp_expiry_minutes', 10), 'link' => Notifier::baseUrl() . '/verify_email?token=' . $token], $estate);
        return true;
    }

    // ------------------------------------------------------------ status handling
    public static function setOnboarding($estateId, $status) {
        $st = Tenancy::platform()->prepare("UPDATE estates SET onboarding_status = ? WHERE id = ?");
        $st->bind_param('si', $status, $estateId);
        $st->execute();
    }

    public static function startTrial($estateId) {
        $days = max(1, PlatformSettings::int('trial_days', 14));
        $st = Tenancy::platform()->prepare("UPDATE estates SET trial_ends_at = NOW() + INTERVAL ? DAY WHERE id = ?");
        $st->bind_param('ii', $days, $estateId);
        $st->execute();
        self::setStatus($estateId, 'trial', [], "Free trial started ($days days)");
        $e = Tenancy::find($estateId);
        Notifier::toCentralAdmins('trial_started', $e, ['trial_days' => $days, 'trial_ends' => date('j M Y', strtotime($e['trial_ends_at']))]);
    }

    /**
     * Central, audited status transition. Keeps timestamps, tenant mirror and onboarding status consistent.
     */
    public static function setStatus($estateId, $newStatus, array $fields = [], $reason = '') {
        if (!in_array($newStatus, self::ESTATE_STATUSES, true)) throw new InvalidArgumentException("Bad status $newStatus");
        $p = Tenancy::platform();
        $old = Tenancy::find($estateId);
        if (!$old) return false;
        $sets = ['status = ?']; $types = 's'; $vals = [$newStatus];
        $now = date('Y-m-d H:i:s');
        $map = [
            'suspended' => ['suspended_at' => $now], 'retention' => [], 'grace_period' => [],
            'deletion_pending' => ['deletion_scheduled_at' => $now], 'deleted' => ['deleted_at' => $now],
        ];
        $extra = array_merge($map[$newStatus] ?? [], $fields);
        if ($newStatus === 'active') $extra = array_merge(['suspended_at' => null, 'grace_ends_at' => null, 'retention_ends_at' => null, 'deletion_scheduled_at' => null], $extra);
        foreach ($extra as $col => $val) {
            if (!preg_match('/^[a-z_]+$/', $col)) continue;
            $sets[] = "$col = ?"; $types .= 's'; $vals[] = $val;
        }
        $types .= 'i'; $vals[] = $estateId;
        $st = $p->prepare('UPDATE estates SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $st->bind_param($types, ...$vals);
        $st->execute();

        $onbMap = ['suspended' => 'suspended', 'retention' => 'suspended', 'grace_period' => 'grace_period', 'deletion_pending' => 'deletion_pending', 'active' => 'active'];
        if (isset($onbMap[$newStatus])) self::setOnboarding($estateId, $onbMap[$newStatus]);

        Entitlements::flush($estateId);
        Tenancy::mirror($estateId);
        if ($old['status'] !== $newStatus) {
            Audit::log('estate.status_changed', ['estate_id' => $estateId, 'module' => 'estates', 'entity_type' => 'estate', 'entity_id' => $estateId,
                'details' => "Status {$old['status']} -> $newStatus" . ($reason ? " ($reason)" : ''),
                'before' => ['status' => $old['status']], 'after' => ['status' => $newStatus]]);
            $p->query("INSERT INTO billing_events (estate_id, event_type, title) VALUES (" . intval($estateId) . ", 'status_change', '" . $p->real_escape_string("Estate status: {$old['status']} → $newStatus" . ($reason ? " ($reason)" : '')) . "')");
        }
        return true;
    }

    public static function statusLabel($s) { return ucwords(str_replace('_', ' ', $s)); }
}
