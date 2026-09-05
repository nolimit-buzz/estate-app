<?php
// includes/auth_guard.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';

// Generate CSRF Token
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Render CSRF Input Field
function renderCSRFField() {
    $token = generateCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

// Verify CSRF Token
function verifyCSRFToken() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            $_SESSION['error_message'] = "Invalid session token (CSRF check failed). Please try again.";
            return false;
        }
    }
    return true;
}

// Enforce User Login
function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../login?error=unauthenticated");
        exit;
    }
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

function isResidentRole() {
    $role = $_SESSION['role'] ?? '';
    return $role === 'resident';
}

function getRoleRedirectPath($role) {
    if ($role === 'superadmin') return '../superadmin/index';
    if (in_array($role, ['admin', 'manager'])) return '../admin/index';
    if ($role === 'resident') return '../resident/index';
    return '../staff/index';
}

// Enforce Admin Access (For /admin pages)
function requireAdminAccess() {
    requireLogin();
    if (!isAdminRole()) {
        $redirect = getRoleRedirectPath($_SESSION['role'] ?? '');
        header("Location: $redirect");
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
