<?php
// includes/auth_helper.php - estate (tenant) user authentication
require_once __DIR__ . '/../config.php'; // boots hardened session + tenant
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/RateLimit.php';

/**
 * Reusable user authentication handler (tenant users only - the super admin has its own login).
 *
 * @param mysqli $conn Tenant database connection
 * @param string $email User email
 * @param string $password Plaintext password
 * @param string|null $portal 'resident', 'staff', 'admin', 'zone' or null (universal)
 * @return array Result array with success, redirect, and optional error
 */
function authenticatePortalUser($conn, $email, $password, $portal = null) {
    try {
        if (!$conn || !($conn instanceof mysqli) || !Tenancy::hasTenant()) {
            return ['success' => false, 'error' => 'Your estate could not be identified. Please enter your estate code first.'];
        }
        $estate_id = Tenancy::currentId();
        $email = strtolower(trim((string)$email));
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if ($email === '' || $password === '' || $password === null) {
            return ['success' => false, 'error' => 'Please enter both email and password.'];
        }

        // Rate limiting: per account (within this estate) and per IP
        $acctKey = $estate_id . ':' . $email;
        $wait = max(RateLimit::lockedFor('login_acct', $acctKey), RateLimit::lockedFor('login_ip', $ip));
        if ($wait > 0) {
            Audit::login('login_blocked', false, ['email' => $email, 'estate_id' => $estate_id, 'reason' => 'rate_limited']);
            return ['success' => false, 'error' => 'Too many failed attempts. Please try again in ' . RateLimit::humanWait($wait) . ' or reset your password.'];
        }

        $stmt = $conn->prepare("SELECT id, role, estate_id, zone_id, password, name, first_name, status, force_password_change, last_login_ip FROM users WHERE LOWER(email) = ? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        $fail = function ($reason, $userRow = null) use ($acctKey, $ip, $email, $estate_id) {
            $locked = RateLimit::hit('login_acct', $acctKey);
            $lockedIp = RateLimit::hit('login_ip', $ip, max(10, PlatformSettings::int('login_max_attempts', 5) * 3));
            Audit::login('login_failed', false, ['email' => $email, 'user_id' => $userRow['id'] ?? null, 'role' => $userRow['role'] ?? null, 'estate_id' => $estate_id, 'reason' => $reason]);
            if ($locked || $lockedIp) {
                Audit::alert('repeated_failed_logins', "Repeated failed sign-ins for $email (estate #$estate_id) - account/IP temporarily locked.", 'high', ['estate_id' => $estate_id, 'user_ref' => $email]);
            }
            return ['success' => false, 'error' => 'Invalid email or password.'];
        };

        if (!$user) {
            password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG'); // equalise timing
            return $fail('unknown_user');
        }

        // 1. Account state
        $status = strtolower($user['status'] ?? 'active');
        if (in_array($status, ['pending', 'invited'], true)) {
            if (password_verify($password, $user['password'])) {
                return ['success' => false, 'error' => 'Your account is not active yet. Please verify your email or accept your invitation.'];
            }
            return $fail('bad_password_pending', $user);
        }
        if (in_array($status, ['disabled', 'suspended', 'inactive'], true)) {
            Audit::login('login_failed', false, ['email' => $email, 'user_id' => $user['id'], 'role' => $user['role'], 'estate_id' => $estate_id, 'reason' => 'account_disabled']);
            return ['success' => false, 'error' => 'This account has been deactivated. Please contact Estate Central Administration.'];
        }

        // 2. Validate Password
        if (!password_verify($password, $user['password'])) {
            return $fail('bad_password', $user);
        }
        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
            $nh = password_hash($password, PASSWORD_DEFAULT);
            $uh = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $uh->bind_param('si', $nh, $user['id']);
            $uh->execute();
        }

        $role = $user['role'];
        if ($role === 'superadmin') $role = 'admin'; // platform super admins never live inside an estate

        // Role-specific portal validation
        if ($portal === 'resident') {
            if ($role !== 'resident' && !in_array($role, ['admin', 'manager'])) {
                return ['success' => false, 'error' => "This account does not have Resident access. Please sign in via the Staff Console."];
            }
        } elseif ($portal === 'staff') {
            $is_staff_valid = in_array($role, ['staff', 'security', 'accountant', 'technician', 'admin', 'manager']);
            if (!$is_staff_valid) {
                $uid = intval($user['id']);
                $st_chk = $conn->query("SELECT id FROM estate_staff WHERE user_id = $uid AND status = 'active'");
                $is_staff_valid = ($st_chk && $st_chk->num_rows > 0);
            }
            if (!$is_staff_valid) {
                return ['success' => false, 'error' => "This account is not registered as Estate Staff. Please sign in via the Resident Portal."];
            }
        } elseif ($portal === 'admin') {
            if (!in_array($role, ['admin', 'manager', 'zone_admin'])) {
                return ['success' => false, 'error' => "Administrative privileges required. Please sign in via the Resident or Staff portal."];
            }
        } elseif ($portal === 'zone') {
            if ($role !== 'zone_admin' && !in_array($role, ['admin'])) {
                return ['success' => false, 'error' => "Zonal Administrative privileges required. Please sign in via the appropriate portal."];
            }
        }

        // Validate Zone Admin assignment
        $zone_session = [];
        if ($role === 'zone_admin') {
            if (empty($user['zone_id'])) {
                return ['success' => false, 'error' => "This account is not assigned to any active zone. Please contact Central Administration."];
            }
            $zid = intval($user['zone_id']);
            $z_chk = $conn->query("SELECT id, name, code, status FROM zones WHERE id = $zid LIMIT 1");
            if (!$z_chk || $z_chk->num_rows === 0) {
                return ['success' => false, 'error' => "Assigned zone record could not be found. Please contact Central Administration."];
            }
            $z_row = $z_chk->fetch_assoc();
            if ($z_row['status'] !== 'active') {
                return ['success' => false, 'error' => "Assigned zone '{$z_row['name']}' is currently inactive. Please contact Central Administration."];
            }
            $zone_session = ['zone_id' => intval($z_row['id']), 'zone_name' => $z_row['name'], 'zone_code' => $z_row['code']];
        } elseif (!empty($user['zone_id'])) {
            $zid = intval($user['zone_id']);
            $z_chk = $conn->query("SELECT id, name, code FROM zones WHERE id = $zid LIMIT 1");
            if ($z_chk && $z_row = $z_chk->fetch_assoc()) {
                $zone_session = ['zone_id' => intval($z_row['id']), 'zone_name' => $z_row['name'], 'zone_code' => $z_row['code']];
            }
        }

        // ---- Authenticated: rotate the session id, then populate the session
        RateLimit::clear('login_acct', $acctKey);
        $ref = SessionGuard::onLogin();
        unset($_SESSION['pending_estate_id'], $_SESSION['platform_user_id'], $_SESSION['platform_user_name'], $_SESSION['impersonated_estate_id']); // never mix platform + estate identities
        foreach ($zone_session as $k => $v) $_SESSION[$k] = $v;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $role;
        $_SESSION['estate_id'] = $estate_id;
        $_SESSION['name'] = $user['name'];
        $_SESSION['force_password_change'] = intval($user['force_password_change'] ?? 0);

        $st_chk = $conn->query("SELECT id, role, role_id FROM estate_staff WHERE user_id = " . intval($user['id']) . " AND status = 'active' LIMIT 1");
        if ($st_chk && $st_row = $st_chk->fetch_assoc()) {
            $_SESSION['is_staff'] = true;
            if (!empty($st_row['role_id'])) $_SESSION['role_id'] = $st_row['role_id'];
            if (!empty($st_row['role'])) $_SESSION['staff_role_title'] = $st_row['role'];
        }

        $uu = $conn->prepare("UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?");
        $uu->bind_param('si', $ip, $user['id']);
        $uu->execute();

        Audit::login('login_success', true, ['user_id' => $user['id'], 'email' => $email, 'name' => $user['name'], 'role' => $role, 'estate_id' => $estate_id, 'session_ref' => $ref]);
        // Unusual login: administrators signing in from a previously unseen IP
        if (in_array($role, ['admin', 'manager', 'zone_admin'], true) && PlatformSettings::bool('alert_new_ip_for_admins', true)
            && !empty($user['last_login_ip']) && $user['last_login_ip'] !== $ip) {
            Audit::alert('new_ip_admin_login', "Administrator $email signed in from a new IP ($ip), previously {$user['last_login_ip']}.", 'low', ['estate_id' => $estate_id, 'user_ref' => $email]);
        }

        $portal_name = $portal ? ucfirst($portal) . " Portal" : "Web Portal";
        logAudit($conn, "User Login", "Auth", "Logged in via $portal_name successfully.");

        // Determine destination
        if (!empty($_SESSION['force_password_change'])) {
            $redirect = ($portal ? '../' : '') . 'change_password';
        } elseif ($role === 'zone_admin') {
            $redirect = ($portal === 'zone') ? 'index' : 'zone/index';
        } elseif ($portal === 'resident' && in_array($role, ['resident', 'admin', 'manager'])) {
            $redirect = 'resident/index';
        } elseif ($portal === 'staff' || (!empty($_SESSION['is_staff']) && $role !== 'admin' && $role !== 'manager')) {
            $redirect = 'staff/index';
        } else {
            if (in_array($role, ['admin', 'manager'])) {
                $redirect = 'admin/index';
            } elseif ($role === 'resident') {
                $redirect = 'resident/index';
            } else {
                $redirect = 'staff/index';
            }
        }

        return [
            'success' => true,
            'redirect' => $redirect,
            'force_password_change' => !empty($_SESSION['force_password_change']),
            'role' => $role,
            'name' => $user['name']
        ];
    } catch (Throwable $e) {
        error_log("Authentication Error: " . $e->getMessage());
        return ['success' => false, 'error' => "We could not sign you in right now. Please try again shortly."];
    }
}
