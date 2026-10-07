<?php
// Database Configuration
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root'); 
define('DB_PASSWORD', '');  
define('DB_NAME', 'estate');

// Attempt to connect to MySQL database
try {
    $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
} catch (mysqli_sql_exception $e) {
    die("<h3>Database Connection Error</h3><p>Could not connect to MySQL. Please ensure the <strong>MySQL</strong> service is started in your XAMPP Control Panel.</p><small>(" . htmlspecialchars($e->getMessage()) . ")</small>");
}

// Set charset to UTF-8
$conn->set_charset("utf8");

// Start Session globally
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -------------------------------------------------------------------------
// GLOBAL SECURITY HARDENING HEADERS
// Protects against clickjacking, MIME sniffing, XSS, and unauthorized embeds
// -------------------------------------------------------------------------
if (!headers_sent()) {
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: camera=(), microphone=(), geolocation=(self)");
    
    // Send HSTS if running over HTTPS
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    if ($is_https) {
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
    }

    // Balanced Content Security Policy allowing required stylesheets, fonts, icons and CDNs
    header("Content-Security-Policy: default-src 'self' 'unsafe-inline' 'unsafe-eval' https: data: blob:; img-src 'self' data: https: blob:; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://js.paystack.co; connect-src 'self' https://api.paystack.co https://*.paystack.co;");
}

// Universal Flash Messaging Helpers
if (!function_exists('setFlashMessage')) {
    function setFlashMessage($type, $message) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['flash_' . $type] = $message;
    }
}

if (!function_exists('getFlashMessage')) {
    function getFlashMessage($type) {
        if (isset($_SESSION['flash_' . $type])) {
            $msg = $_SESSION['flash_' . $type];
            unset($_SESSION['flash_' . $type]);
            return $msg;
        }
        return null;
    }
}

if (!function_exists('redirectWithFlash')) {
    function redirectWithFlash($url, $successMessage = null, $errorMessage = null) {
        if ($successMessage !== null) {
            $_SESSION['flash_message'] = $successMessage;
            $_SESSION['flash_success'] = $successMessage;
        }
        if ($errorMessage !== null) {
            $_SESSION['flash_error'] = $errorMessage;
        }
        header("Location: " . $url);
        exit;
    }
}

// Auto-extract and clear session flash messages into global variables for views
$flash_message = $_SESSION['flash_message'] ?? ($_SESSION['flash_success'] ?? null);
$flash_error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_message'], $_SESSION['flash_success'], $_SESSION['flash_error']);

// -------------------------------------------------------------------------
// UNIVERSAL ANTI-DUPLICATE / ANTI-DOUBLE-SUBMIT POST GUARD
// Prevents duplicate inserts caused by double-clicking, browser refreshing (F5),
// or rapid resubmissions across the entire application.
// -------------------------------------------------------------------------
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && session_status() === PHP_SESSION_ACTIVE) {
    $req_uri = $_SERVER['REQUEST_URI'] ?? '';
    // Bypass external webhooks and machine-to-machine APIs
    if (strpos($req_uri, '/api/') === false) {
        // 1. One-Time Form Token Deduplication
        if (!empty($_POST['__form_token'])) {
            $token = strval($_POST['__form_token']);
            if (isset($_SESSION['used_form_tokens'][$token])) {
                // Token has already been processed! Block duplicate execution & redirect cleanly to GET
                header("Location: " . $req_uri);
                exit;
            }
            if (!isset($_SESSION['used_form_tokens']) || !is_array($_SESSION['used_form_tokens'])) {
                $_SESSION['used_form_tokens'] = [];
            }
            $_SESSION['used_form_tokens'][$token] = time();
            if (count($_SESSION['used_form_tokens']) > 100) {
                $_SESSION['used_form_tokens'] = array_slice($_SESSION['used_form_tokens'], -100, null, true);
            }
        }

        // 2. Rapid Duplicate POST Fingerprint Check (Catch concurrent clicks within 2.0s)
        $post_copy = $_POST;
        unset($post_copy['__form_token']);
        if (!empty($post_copy)) {
            $fp = md5($req_uri . '|' . ($_SESSION['user_id'] ?? '0') . '|' . serialize($post_copy));
            $now = microtime(true);
            if (isset($_SESSION['last_post_fingerprint']) && $_SESSION['last_post_fingerprint'] === $fp) {
                if (($now - ($_SESSION['last_post_time'] ?? 0)) < 2.0) {
                    header("Location: " . $req_uri);
                    exit;
                }
            }
            $_SESSION['last_post_fingerprint'] = $fp;
            $_SESSION['last_post_time'] = $now;
        }
    }
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

// Global Tenant Helper (Supports Super Admin Impersonation & Subdomains)
function get_estate_id() {
    // 1. If Super Admin is actively impersonating an estate:
    if (isset($_SESSION['impersonated_estate_id']) && (isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin')) {
        return intval($_SESSION['impersonated_estate_id']);
    }
    // 2. Standard authenticated session estate
    if (isset($_SESSION['estate_id'])) {
        return intval($_SESSION['estate_id']);
    }
    // 3. Subdomain-based tenant resolution (e.g. sunrise.estateapp.com)
    if (isset($_SERVER['HTTP_HOST'])) {
        static $resolved_subdomain_id = null;
        if ($resolved_subdomain_id !== null) {
            return $resolved_subdomain_id;
        }
        $host = strtolower($_SERVER['HTTP_HOST']);
        $parts = explode('.', $host);
        if (count($parts) >= 3 && $parts[0] !== 'www' && $parts[0] !== 'localhost') {
            global $conn;
            if ($conn instanceof mysqli) {
                $sub = $conn->real_escape_string($parts[0]);
                $chk = $conn->query("SELECT id FROM estates WHERE domain_prefix = '$sub' LIMIT 1");
                if ($chk && $r = $chk->fetch_assoc()) {
                    $resolved_subdomain_id = intval($r['id']);
                    return $resolved_subdomain_id;
                }
            }
        }
    }
    return 1; // Default fallback to Main Estate
}

// Impersonation Helper
function is_impersonating_estate() {
    return (isset($_SESSION['impersonated_estate_id']) && (isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin'));
}

// Get Impersonated Estate Meta
function get_impersonated_estate_name($conn = null) {
    if (!is_impersonating_estate()) return null;
    $eid = intval($_SESSION['impersonated_estate_id']);
    if (!$conn) {
        global $conn;
    }
    if ($conn instanceof mysqli) {
        $res = $conn->query("SELECT name FROM estates WHERE id = $eid LIMIT 1");
        if ($res && $r = $res->fetch_assoc()) return $r['name'];
    }
    return "Estate #$eid";
}

// Global Standard SaaS Platform Modules
function getAllPlatformModules() {
    return [
        'visitor_passes' => [
            'name' => 'Visitor & Gate Pass Management',
            'desc' => 'Generate visitor passcodes, check-in guests, entry/exit logs & QR codes',
            'icon' => 'fa-ticket',
            'category' => 'Security & Access'
        ],
        'vehicle_registry' => [
            'name' => 'Vehicle Registry & Stickers',
            'desc' => 'Resident vehicle verification, RFID/car tags, parking & gate logs',
            'icon' => 'fa-car-side',
            'category' => 'Security & Access'
        ],
        'billing_invoicing' => [
            'name' => 'Invoicing, Dues & Paystack',
            'desc' => 'Estate dues, levies, automated invoices, receipts & Paystack online gateway',
            'icon' => 'fa-file-invoice-dollar',
            'category' => 'Finance'
        ],
        'ussd_offline_sync' => [
            'name' => 'Africa\'s Talking USSD & Offline Terminal',
            'desc' => 'Zero-internet GSM USSD shortcode (*384#) access & local terminal browser caching',
            'icon' => 'fa-tower-cell',
            'category' => 'Telephony & Hardware'
        ],
        'security_patrol' => [
            'name' => 'Security Guard Roster & Shifts',
            'desc' => 'Security posts, guard shift roster assignment, and duty checkpoints',
            'icon' => 'fa-user-shield',
            'category' => 'Operations'
        ],
        'emergency_sos' => [
            'name' => 'Emergency SOS & Panic Alerts',
            'desc' => 'One-tap resident emergency panic alarms & guard mobilization alerts',
            'icon' => 'fa-bell-exclamation',
            'category' => 'Operations'
        ],
        'broadcast_messaging' => [
            'name' => 'Broadcast SMS, WhatsApp & Email',
            'desc' => 'Automated community announcements, meeting notices & circular broadcasts',
            'icon' => 'fa-bullhorn',
            'category' => 'Communications'
        ],
        'artisan_marketplace' => [
            'name' => 'Vetted Artisans & Service Directory',
            'desc' => 'Directory of approved electricians, plumbers, painters & domestic handymen',
            'icon' => 'fa-wrench',
            'category' => 'Community'
        ],
        'bylaws_policies' => [
            'name' => 'Estate Bylaws & Policy Penalties',
            'desc' => 'Publish estate rules, curfew policies, pet regulations & violation fines',
            'icon' => 'fa-book-bookmark',
            'category' => 'Governance'
        ],
        'zonal_divisions' => [
            'name' => 'Zonal Sub-Committees & Zones',
            'desc' => 'Zone-level administrative accounts, zonal levies, and street clusters',
            'icon' => 'fa-layer-group',
            'category' => 'Governance'
        ]
    ];
}

// Module Feature Flag Gatekeeper
function isModuleEnabled($module_key, $estate_id = null) {
    global $conn;
    if (!$estate_id) $estate_id = get_estate_id();
    $estate_id = intval($estate_id);

    static $module_cache = [];
    if (!isset($module_cache[$estate_id])) {
        $module_cache[$estate_id] = [];
        if ($conn instanceof mysqli) {
            $m_res = $conn->query("SELECT module_key, is_enabled FROM estate_modules WHERE estate_id = $estate_id");
            if ($m_res) {
                while ($mrow = $m_res->fetch_assoc()) {
                    $module_cache[$estate_id][$mrow['module_key']] = (intval($mrow['is_enabled']) === 1);
                }
            }
        }
    }

    if (array_key_exists($module_key, $module_cache[$estate_id])) {
        return $module_cache[$estate_id][$module_key];
    }

    // Default open if not explicitly disabled
    return true;
}

// Require Module or Redirect
function requireModule($module_key, $redirect_url = '../admin/index') {
    if (!isModuleEnabled($module_key)) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['flash_error'] = "The requested module ('$module_key') is not enabled for this estate plan. Contact the platform administrator to activate this feature.";
        header("Location: " . $redirect_url);
        exit;
    }
}


// Global Media URL Resolver (Normalizes uploads paths across any folder depth)
if (!function_exists('get_media_url')) {
    function get_media_url($path) {
        if (empty($path)) return '';
        if (preg_match('/^(https?:|\/\/|data:)/i', $path)) {
            return $path;
        }
        $clean = preg_replace('/^(\.\.\/|\.\/|\/)+/', '', $path);
        
        $estate_root = str_replace('\\', '/', realpath(__DIR__));
        $script_file = str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $current_dir = !empty($script_file) ? dirname($script_file) : str_replace('\\', '/', realpath(getcwd()));
        
        $prefix = '';
        if ($current_dir && strpos($current_dir, $estate_root) === 0) {
            $sub = trim(str_replace($estate_root, '', $current_dir), '/');
            $depth = empty($sub) ? 0 : count(explode('/', $sub));
            $prefix = str_repeat('../', $depth);
        }
        return $prefix . $clean;
    }
}

// Global Estate Branding Helper for Multi-Tenant Header & Theme Display
if (!function_exists('get_estate_branding')) {
    function get_estate_branding($conn, $estate_id = null) {
        if (!$estate_id) $estate_id = get_estate_id();
        $estate_id = intval($estate_id);
        
        static $branding_cache = [];
        if (isset($branding_cache[$estate_id])) {
            return $branding_cache[$estate_id];
        }
        
        $data = [
            'estate_id' => $estate_id,
            'estate_name' => 'Main Estate',
            'estate_logo' => '',
            'estate_logo_url' => '',
            'app_company_name' => 'NoLimitBuzz',
            'app_company_logo' => '',
            'app_company_logo_url' => '',
            'theme_color' => '#3b82f6'
        ];
        
        if ($conn instanceof mysqli) {
            $e_res = $conn->query("SELECT name FROM estates WHERE id = $estate_id LIMIT 1");
            if ($e_res && $e_row = $e_res->fetch_assoc()) {
                if (!empty($e_row['name'])) {
                    $data['estate_name'] = $e_row['name'];
                }
            }
            
            $s_res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE estate_id = $estate_id AND setting_key IN ('estate_name', 'estate_logo', 'app_company_name', 'app_company_logo', 'theme_color')");
            if ($s_res) {
                while ($s_row = $s_res->fetch_assoc()) {
                    $key = $s_row['setting_key'];
                    $val = $s_row['setting_value'];
                    if (!empty($val)) {
                        $data[$key] = $val;
                    }
                }
            }
        }
        
        if (!empty($data['estate_logo'])) {
            $data['estate_logo_url'] = get_media_url($data['estate_logo']);
        }
        if (!empty($data['app_company_logo'])) {
            $data['app_company_logo_url'] = get_media_url($data['app_company_logo']);
        }
        
        $branding_cache[$estate_id] = $data;
        return $data;
    }
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

// Initialize Security Roster & Emergency/Panic Alert Engine
if (file_exists(__DIR__ . '/includes/emergency_roster_init.php')) {
    require_once __DIR__ . '/includes/emergency_roster_init.php';
    if (isset($conn) && $conn instanceof mysqli) {
        initEmergencyAndRosterTables($conn);
    }
}

// Initialize Estate & Zonal Policies, Offences & Punishments Engine
if (file_exists(__DIR__ . '/includes/policy_init.php')) {
    require_once __DIR__ . '/includes/policy_init.php';
    if (isset($conn) && $conn instanceof mysqli) {
        initPolicyAndOffenceTables($conn);
    }
}

// -------------------------------------------------------------------------
// CONTACT CHANGE REQUESTS & DUPLICATE PREVENTION HELPERS
// -------------------------------------------------------------------------
if (isset($conn) && $conn instanceof mysqli) {
    $conn->query("
        CREATE TABLE IF NOT EXISTS contact_change_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            estate_id INT NOT NULL,
            zone_id INT NULL,
            user_id INT NOT NULL,
            resident_id INT NULL,
            current_email VARCHAR(100) NULL,
            requested_email VARCHAR(100) NULL,
            current_phone VARCHAR(20) NULL,
            requested_phone VARCHAR(20) NULL,
            reason TEXT NULL,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            admin_notes TEXT NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_estate (estate_id),
            INDEX idx_zone (zone_id),
            INDEX idx_user (user_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

if (!function_exists('isEmailTakenInEstate')) {
    function isEmailTakenInEstate($conn, $email, $estate_id, $exclude_user_id = null) {
        $email = trim($email);
        if (empty($email)) return false;
        $esc_email = $conn->real_escape_string($email);
        $estate_id = intval($estate_id);
        
        $sql = "SELECT u.id, u.name, u.role, u.phone, r.custom_id as res_id, r.status as res_status 
                FROM users u 
                LEFT JOIN residents r ON u.id = r.user_id AND r.estate_id = $estate_id 
                WHERE LOWER(u.email) = LOWER('$esc_email') AND (u.estate_id = $estate_id OR u.estate_id IS NULL)";
        
        if ($exclude_user_id) {
            $sql .= " AND u.id != " . intval($exclude_user_id);
        }
        $sql .= " LIMIT 1";
        
        $res = $conn->query($sql);
        if ($res && $row = $res->fetch_assoc()) {
            return $row;
        }
        return false;
    }
}

if (!function_exists('isPhoneTakenInEstate')) {
    function isPhoneTakenInEstate($conn, $phone, $estate_id, $exclude_user_id = null) {
        $phone = trim($phone);
        if (empty($phone)) return false;
        $clean_phone = preg_replace('/[^0-9]/', '', $phone);
        if (empty($clean_phone)) return false;
        
        $esc_phone = $conn->real_escape_string($phone);
        $estate_id = intval($estate_id);
        
        $sql = "SELECT u.id, u.name, u.email, u.phone, u.role, r.custom_id as res_id, r.status as res_status 
                FROM users u 
                LEFT JOIN residents r ON u.id = r.user_id AND r.estate_id = $estate_id 
                WHERE (u.phone = '$esc_phone' OR REPLACE(REPLACE(REPLACE(REPLACE(u.phone, ' ', ''), '-', ''), '+', ''), '(', '') LIKE '%$clean_phone%') 
                  AND (u.estate_id = $estate_id OR u.estate_id IS NULL)";
        
        if ($exclude_user_id) {
            $sql .= " AND u.id != " . intval($exclude_user_id);
        }
        $sql .= " LIMIT 1";
        
        $res = $conn->query($sql);
        if ($res && $row = $res->fetch_assoc()) {
            return $row;
        }
        
        // Also check household_staff phone
        $h_sql = "SELECT id, name, phone, role FROM household_staff 
                  WHERE (phone = '$esc_phone' OR REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') = '$clean_phone') 
                    AND estate_id = $estate_id AND status != 'inactive' LIMIT 1";
        $h_res = $conn->query($h_sql);
        if ($h_res && $h_row = $h_res->fetch_assoc()) {
            $h_row['role'] = 'household_staff (' . $h_row['role'] . ')';
            return $h_row;
        }
        
        return false;
    }
}
?>
