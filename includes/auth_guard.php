<?php
// includes/auth_guard.php
require_once __DIR__ . '/../config.php'; // boots hardened session + tenant

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/ModuleRegistry.php';

// CSRF helpers (token is shared with includes/Csrf.php, which enforces it globally)
function generateCSRFToken() { return Csrf::token(); }
function renderCSRFField() { return Csrf::field(); }
function verifyCSRFToken() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Csrf::verify()) {
        $_SESSION['error_message'] = "Invalid session token (CSRF check failed). Please try again.";
        return false;
    }
    return true;
}
// Routes that stay reachable while an estate is suspended (so the Central Admin can renew / export).
function suspendedAllowedRoutes() {
    return ['suspended', 'logout', 'change_password', 'admin/subscription', 'admin/billing_pay', 'admin/export', 'admin/support', 'api/platform_pay'];
}

// Enforce User Login & Security Guard
function requireLogin() {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $isInSubdir = (strpos($script, '/admin/') !== false || strpos($script, '/resident/') !== false || strpos($script, '/zone/') !== false || strpos($script, '/staff/') !== false || strpos($script, '/api/') !== false);
    $prefix = $isInSubdir ? '../' : '';
    $isApi = strpos($script, '/api/') !== false;

    $deny = function ($reason) use ($prefix, $isApi) {
        if ($isApi) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
        header("Location: {$prefix}login?error=$reason");
        exit;
    };

    if (!isset($_SESSION['user_id'])) $deny('unauthenticated');

    // Tenant integrity: the session must belong to the estate whose database we are connected to.
    if (!Tenancy::hasTenant() || intval($_SESSION['estate_id'] ?? 0) !== Tenancy::currentId()) {
        if (!(is_impersonating_estate() && Tenancy::hasTenant())) {
            $_SESSION = [];
            $deny('unauthenticated');
        }
    }

    global $conn;
    $route = ModuleRegistry::currentRoute();

    // Active Database Status & Force Password Change Check
    if ($conn && isset($_SESSION['user_id'])) {
        $uid = intval($_SESSION['user_id']);
        $u_chk = $conn->query("SELECT status, force_password_change FROM users WHERE id = $uid LIMIT 1");
        if ($u_chk && $u_row = $u_chk->fetch_assoc()) {
            // If account was disabled/suspended/not yet verified by admin in real-time
            if (isset($u_row['status']) && in_array(strtolower($u_row['status']), ['disabled', 'suspended', 'inactive', 'pending', 'invited'])) {
                SessionGuard::destroy();
                if ($isApi) $deny('account_disabled');
                header("Location: {$prefix}login?error=account_disabled");
                exit;
            }
            $_SESSION['force_password_change'] = intval($u_row['force_password_change'] ?? 0);
        } elseif (!is_impersonating_estate()) {
            SessionGuard::destroy();
            $deny('unauthenticated');
        }
    }

    // Multi-Tenant Estate Lifecycle & Suspension Guard (status comes from the control plane)
    $estate = Tenancy::$estate;
    $blocked = ['suspended', 'retention', 'deletion_pending'];
    if ($estate && in_array($estate['status'], $blocked, true) && !is_impersonating_estate() && !in_array($route, suspendedAllowedRoutes(), true)) {
        if ($isApi) { http_response_code(402); header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Estate subscription suspended']); exit; }
        header("Location: {$prefix}suspended");
        exit;
    }
    if ($estate && in_array($estate['status'], ['pending', 'onboarding'], true) && ($_SESSION['role'] ?? '') !== 'resident') {
        // Onboarding estates only have core modules; Entitlements handles module gating below.
    }

    // Intercept forced password change
    if (!empty($_SESSION['force_password_change'])) {
        $current = basename($_SERVER['PHP_SELF']);
        if ($current !== 'change_password.php' && $current !== 'logout.php') {
            header("Location: {$prefix}change_password");
            exit;
        }
    }

    // Module gating: one enforcement point for every portal page and API
    ModuleRegistry::enforceCurrent();
}
// Role Helper Functions
function isAdminRole() {
    $role = $_SESSION['role'] ?? '';
    return in_array($role, ['superadmin', 'admin', 'manager']);
}

function isStaffRole() {
    $role = $_SESSION['role'] ?? '';
    if (in_array($role, ['staff', 'security', 'accountant', 'technician'])) return true;
    if ($role === 'resident' || $role === 'superadmin') return false;
    if (isset($_SESSION['is_staff']) && $_SESSION['is_staff']) return true;
    if (!empty($_SESSION['user_id'])) {
        global $conn;
        $uid = intval($_SESSION['user_id']);
        $chk = $conn->query("SELECT id, role_id FROM estate_staff WHERE user_id = $uid AND status = 'active'");
        if ($chk && $chk->num_rows > 0) {
            $_SESSION['is_staff'] = true;
            $row = $chk->fetch_assoc();
            if (!empty($row['role_id'])) $_SESSION['role_id'] = $row['role_id'];
            return true;
        }
    }
    return false;
}

function isZoneAdminRole() {
    $role = $_SESSION['role'] ?? '';
    return $role === 'zone_admin';
}

function get_current_zone_id() {
    global $conn;
    $role = $_SESSION['role'] ?? '';
    if (isset($_GET['switch_zone_id']) && (in_array($role, ['superadmin', 'admin', 'manager']))) {
        $sw_id = intval($_GET['switch_zone_id']);
        if ($conn) {
            $z_res = $conn->query("SELECT id, name, code FROM zones WHERE id = $sw_id LIMIT 1");
            if ($z_res && $z_row = $z_res->fetch_assoc()) {
                $_SESSION['zone_id'] = intval($z_row['id']);
                $_SESSION['zone_name'] = $z_row['name'];
                $_SESSION['zone_code'] = $z_row['code'];
            }
        }
    }
    if (isset($_SESSION['zone_id']) && !empty($_SESSION['zone_id'])) {
        return intval($_SESSION['zone_id']);
    }
    if (in_array($role, ['superadmin', 'admin', 'manager']) && $conn) {
        $z_res = $conn->query("SELECT id, name, code FROM zones ORDER BY id ASC LIMIT 1");
        if ($z_res && $z_row = $z_res->fetch_assoc()) {
            $_SESSION['zone_id'] = intval($z_row['id']);
            $_SESSION['zone_name'] = $z_row['name'];
            $_SESSION['zone_code'] = $z_row['code'];
            return intval($z_row['id']);
        }
    }
    return 0;
}

function isResidentRole() {
    $role = $_SESSION['role'] ?? '';
    return $role === 'resident';
}

function getRoleRedirectPath($role) {
    if ($role === 'superadmin') return '../superadmin/index';
    if (in_array($role, ['admin', 'manager'])) return '../admin/index';
    if ($role === 'zone_admin') return '../zone/index';
    if ($role === 'resident') return '../resident/index';
    return '../staff/index';
}

// Enforce Admin Access (For /admin pages)
function requireAdminAccess() {
    requireLogin();
    if (isZoneAdminRole()) {
        header("Location: ../zone/index");
        exit;
    }
    if (!isAdminRole()) {
        $redirect = getRoleRedirectPath($_SESSION['role'] ?? '');
        header("Location: $redirect");
        exit;
    }
}

// Enforce Zone Admin Access (For /zone pages)
function requireZoneAccess() {
    requireLogin();
    $role = $_SESSION['role'] ?? '';
    if ($role !== 'zone_admin' && !in_array($role, ['superadmin', 'admin'])) {
        header("Location: ../zone/login?error=unauthorized");
        exit;
    }
    if ($role === 'zone_admin' && empty($_SESSION['zone_id'])) {
        header("Location: ../zone/login?error=no_zone_assigned");
        exit;
    }
}

// Permission Slug Check (Used in Admin & Staff portals)
if (!function_exists('hasPermission')) {
    function hasPermission($permission_slug) {
        global $conn;
        if (!isset($_SESSION['user_id'])) return false;
        
        $role = $_SESSION['role'] ?? 'resident';
        if ($role === 'superadmin' || $role === 'admin') return true;
        
        $estate_id = get_estate_id();
        $permission_slug = $conn->real_escape_string($permission_slug);
        
        // Priority 1: Check by role_id (direct FK to roles)
        $role_id = $_SESSION['role_id'] ?? null;
        if (!$role_id && isset($_SESSION['user_id'])) {
            $uid = intval($_SESSION['user_id']);
            $st_res = $conn->query("SELECT role_id FROM estate_staff WHERE user_id = $uid AND status = 'active' LIMIT 1");
            if ($st_res && $st_row = $st_res->fetch_assoc()) {
                $role_id = $st_row['role_id'];
                $_SESSION['role_id'] = $role_id;
            }
        }
        
        if ($role_id) {
            $query = "SELECT rp.permission_id 
                      FROM role_permissions rp
                      JOIN permissions p ON rp.permission_id = p.id
                      WHERE rp.role_id = " . intval($role_id) . " AND p.slug = '$permission_slug'";
            $res = $conn->query($query);
            if ($res && $res->num_rows > 0) return true;
        }
        
        // Priority 2: Check by role slug or name
        $query = "SELECT rp.permission_id 
                  FROM role_permissions rp
                  JOIN roles r ON rp.role_id = r.id
                  JOIN permissions p ON rp.permission_id = p.id
                  WHERE (r.slug = '$role' OR r.name = '$role') AND r.estate_id = $estate_id AND p.slug = '$permission_slug'";
        $res = $conn->query($query);
        return ($res && $res->num_rows > 0);
    }
}

if (!function_exists('requirePermission')) {
    function requirePermission($permission_slug) {
        requireLogin();
        if (!hasPermission($permission_slug)) {
            $fallback = isStaffRole() ? '../staff/index' : '../admin/index';
            header("Location: $fallback?error=unauthorized");
            exit;
        }
    }
}
?>
