<?php
// Database Configuration
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root'); 
define('DB_PASSWORD', '');  
define('DB_NAME', 'estate');

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("ERROR: Could not connect. " . $conn->connect_error);
}

// Set charset to UTF-8
$conn->set_charset("utf8");

// Start Session globally
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Global Audit Logger
function logAudit($conn, $action, $module, $details = "") {
    $user_id = $_SESSION['user_id'] ?? null;
    $estate_id = get_estate_id();
    $action = $conn->real_escape_string($action);
    $module = $conn->real_escape_string($module);
    $details = $conn->real_escape_string($details);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    
    // NOTE: If audit_logs has estate_id, append it 
    $sql = "INSERT INTO audit_logs (estate_id, user_id, action, module, details, ip_address) 
            VALUES (" . ($estate_id ? $estate_id : "NULL") . ", " . ($user_id ? $user_id : "NULL") . ", '$action', '$module', '$details', '$ip')";
    return $conn->query($sql);
}

// Global Tenant Helper
function get_estate_id() {
    if (isset($_SESSION['estate_id'])) {
        return intval($_SESSION['estate_id']);
    }
    return 1; // Default fallback to Main Estate
}

// Permission Check Helper
function hasPermission($permission_slug) {
    global $conn;
    if (!isset($_SESSION['user_id'])) return false;
    
    $role = $_SESSION['role'] ?? 'resident';
    if ($role === 'superadmin' || $role === 'admin') return true;
    
    $estate_id = get_estate_id();
    $permission_slug = $conn->real_escape_string($permission_slug);
    
    $query = "SELECT rp.permission_id 
              FROM role_permissions rp
              JOIN roles r ON rp.role_id = r.id
              JOIN permissions p ON rp.permission_id = p.id
              WHERE r.slug = '$role' AND r.estate_id = $estate_id AND p.slug = '$permission_slug'";
    $res = $conn->query($query);
    return ($res && $res->num_rows > 0);
}

function requirePermission($permission_slug) {
    if (!hasPermission($permission_slug)) {
        header("Location: ../admin/index?error=unauthorized");
        exit;
    }
}

// Helper to format minutes into human readable duration
function formatDuration($minutes) {
    if ($minutes === null || $minutes === '') return '-';
    $minutes = intval($minutes);
    if ($minutes < 0) return '0 minutes';
    if ($minutes < 60) {
        return $minutes . ' ' . ($minutes == 1 ? 'minute' : 'minutes');
    }
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    if ($mins == 0) {
        return $hours . ' ' . ($hours == 1 ? 'hour' : 'hours');
    }
    return $hours . ' ' . ($hours == 1 ? 'hour' : 'hours') . ' ' . $mins . ' ' . ($mins == 1 ? 'minute' : 'minutes');
}

// Global Collision-Proof Custom ID Generator
if (!function_exists('generateCustomID')) {
    function generateCustomID($conn, $table, $prefix) {
        $res = $conn->query("SELECT custom_id FROM $table WHERE custom_id LIKE '$prefix-%'");
        $max_num = 0;
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if (preg_match('/' . preg_quote($prefix, '/') . '-0*(\d+)/', $row['custom_id'], $m)) {
                    $num = intval($m[1]);
                    if ($num > $max_num) $max_num = $num;
                }
            }
        }
        
        $id_res = $conn->query("SELECT MAX(id) as max_id FROM $table");
        if ($id_res && $row = $id_res->fetch_assoc()) {
            $max_id = intval($row['max_id'] ?? 0);
            if ($max_id > $max_num) $max_num = $max_id;
        }
        
        $next = $max_num + 1;
        
        do {
            $candidate = $prefix . '-' . str_pad($next, 5, '0', STR_PAD_LEFT);
            $check = $conn->query("SELECT id FROM $table WHERE custom_id = '$candidate' LIMIT 1");
            if ($check && $check->num_rows > 0) {
                $next++;
            } else {
                return $candidate;
            }
        } while ($next < $max_num + 1000);
        
        return $candidate;
    }
}

// Global Auto Invoice Number Generator (Format: INV-YYMMDD-XXX)
if (!function_exists('generateInvoiceNumber')) {
    function generateInvoiceNumber($conn) {
        $datePart = date('ymd');
        $attempts = 0;
        do {
            $randPart = str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT);
            $candidate = "INV-$datePart-$randPart";
            $check = $conn->query("SELECT id FROM invoices WHERE invoice_number = '$candidate' LIMIT 1");
            if (!$check || $check->num_rows == 0) {
                return $candidate;
            }
            $attempts++;
        } while ($attempts < 1000);
        
        return "INV-$datePart-" . mt_rand(1000, 9999);
    }
}

// Global Auto Receipt Number Generator (Format: REC-YYMMDD-XXX)
if (!function_exists('generateReceiptNumber')) {
    function generateReceiptNumber($conn) {
        $datePart = date('ymd');
        $attempts = 0;
        do {
            $randPart = str_pad(mt_rand(100, 999), 3, '0', STR_PAD_LEFT);
            $candidate = "REC-$datePart-$randPart";
            $check = $conn->query("SELECT id FROM receipts WHERE receipt_number = '$candidate' LIMIT 1");
            if (!$check || $check->num_rows == 0) {
                return $candidate;
            }
            $attempts++;
        } while ($attempts < 1000);
        
        return "REC-$datePart-" . mt_rand(1000, 9999);
    }
}
?>
