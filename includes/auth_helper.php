<?php
// includes/auth_helper.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth_guard.php';

/**
 * Reusable user authentication handler
 * 
 * @param mysqli $conn Database connection
 * @param string $email User email
 * @param string $password Plaintext password
 * @param string|null $portal 'resident', 'staff', 'admin', or null (universal)
 * @return array Result array with success, redirect, and optional error
 */
function authenticatePortalUser($conn, $email, $password, $portal = null) {
    $email = trim($conn->real_escape_string($email));
    
    if (empty($email) || empty($password)) {
        return ['success' => false, 'error' => 'Please enter both email and password.'];
    }
    
    $res = $conn->query("SELECT id, role, estate_id, password, name, first_name FROM users WHERE email = '$email'");
    if (!$res || $res->num_rows === 0) {
        return ['success' => false, 'error' => 'Invalid email or password.'];
    }
    
    $user = $res->fetch_assoc();
    if (!password_verify($password, $user['password'])) {
        return ['success' => false, 'error' => 'Invalid email or password.'];
    }
    
    $role = $user['role'];
    
    // Role-specific portal validation
    if ($portal === 'resident') {
        if ($role !== 'resident' && !in_array($role, ['admin', 'superadmin', 'manager'])) {
            return [
                'success' => false, 
                'error' => "This account does not have Resident access. Please sign in via the Staff Console."
            ];
        }
    } elseif ($portal === 'staff') {
        $is_staff_valid = false;
        if (in_array($role, ['staff', 'security', 'accountant', 'technician', 'admin', 'superadmin', 'manager'])) {
            $is_staff_valid = true;
        } else {
            $uid = intval($user['id']);
            $st_chk = $conn->query("SELECT id, role, role_id FROM estate_staff WHERE user_id = $uid AND status = 'active'");
            if ($st_chk && $st_chk->num_rows > 0) {
                $is_staff_valid = true;
            }
        }
        if (!$is_staff_valid) {
            return [
                'success' => false, 
                'error' => "This account is not registered as Estate Staff. Please sign in via the Resident Portal."
            ];
        }
    } elseif ($portal === 'admin') {
        if (!in_array($role, ['admin', 'superadmin', 'manager'])) {
            return [
                'success' => false, 
                'error' => "Administrative privileges required. Please sign in via the Resident or Staff portal."
            ];
        }
    }
    
    // Set authenticated session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['estate_id'] = $user['estate_id'];
    $_SESSION['name'] = $user['name'];

    // Check and populate estate_staff details in session
    $st_chk = $conn->query("SELECT id, role, role_id FROM estate_staff WHERE user_id = " . intval($user['id']) . " AND status = 'active' LIMIT 1");
    if ($st_chk && $st_row = $st_chk->fetch_assoc()) {
        $_SESSION['is_staff'] = true;
        if (!empty($st_row['role_id'])) $_SESSION['role_id'] = $st_row['role_id'];
        if (!empty($st_row['role'])) $_SESSION['staff_role_title'] = $st_row['role'];
    }
    
    $portal_name = $portal ? ucfirst($portal) . " Portal" : "Web Portal";
    logAudit($conn, "User Login", "Auth", "Logged in via $portal_name successfully.");
    
    // Determine destination
    if ($portal === 'resident' && in_array($role, ['resident', 'admin', 'superadmin', 'manager'])) {
        $redirect = 'resident/index';
    } elseif ($portal === 'staff' || (!empty($_SESSION['is_staff']) && $role !== 'superadmin' && $role !== 'admin' && $role !== 'manager')) {
        $redirect = 'staff/index';
    } else {
        if ($role === 'superadmin') {
            $redirect = 'superadmin/index';
        } elseif (in_array($role, ['admin', 'manager'])) {
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
        'role' => $role, 
        'name' => $user['name']
    ];
}
