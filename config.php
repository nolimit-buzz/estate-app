<?php
// -------------------------------------------------------------------------
// BOOTSTRAP: environment, hardened session, tenant resolution, shared services.
// Credentials live in .env (never in source). $conn is the TENANT database of the
// current estate (database-per-tenant); $platform is the control-plane database.
// -------------------------------------------------------------------------
require_once __DIR__ . '/includes/env.php';
require_once __DIR__ . '/includes/Tenancy.php';
require_once __DIR__ . '/includes/PlatformSettings.php';
require_once __DIR__ . '/includes/Audit.php';
require_once __DIR__ . '/includes/Entitlements.php';
require_once __DIR__ . '/includes/Csrf.php';
require_once __DIR__ . '/includes/SessionGuard.php';
require_once __DIR__ . '/includes/Invitations.php';   // also loads Notifier, EstateService

// Start Session globally (secure cookie flags, idle/absolute timeouts)
SessionGuard::start();

try {
    $platform = Tenancy::platform();
    $conn = Tenancy::boot();
} catch (Throwable $e) {
    error_log('Bootstrap DB error: ' . $e->getMessage());
    http_response_code(503);
    die("<h3>Service temporarily unavailable</h3><p>The database could not be reached. If you run this on XAMPP, make sure the <strong>MySQL</strong> service is started.</p>");
}

// Machine APIs cannot run without a resolved tenant (webhooks resolve their own tenant explicitly).
if (!Tenancy::hasTenant() && Tenancy::$mode !== 'legacy'
    && strpos(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/api/') !== false
    && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['paystack_webhook.php', 'kapso_webhook.php', 'ussd.php', 'platform_webhook.php'], true)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Estate could not be identified. Please sign in again.']);
    exit;
}

define('ASSET_VER', (string)max(@filemtime(__DIR__ . '/css/style.css'), @filemtime(__DIR__ . '/css/landing.css'), @filemtime(__DIR__ . '/js/mobile_app.js')));

// CSRF: reject unsafe requests without a valid token, and auto-inject tokens into rendered forms/AJAX.
Csrf::enforce();
if (PHP_SAPI !== 'cli' && !defined('NO_CSRF_INJECT')) ob_start(['Csrf', 'inject']);

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

    // Hardened Content Security Policy with strict controls
    // Note: unsafe-inline in style-src is needed for legitimate styling but should be audited
    header("Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self' https://*.paystack.co; frame-ancestors 'self'; img-src 'self' data: https: blob:; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:; style-src 'self' https://fonts.googleapis.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://js.paystack.co; connect-src 'self' https://api.paystack.co https://*.paystack.co; media-src 'self'; upgrade-insecure-requests;");
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

// logAudit() now lives in includes/Audit.php (tenant audit_logs + platform audit trail).

// Global Tenant Helper: the id of the estate whose database $conn is connected to (0 = no tenant).
// There is intentionally NO default estate - an unidentified visitor never reaches any estate's data.
function get_estate_id() {
    return Tenancy::currentId();
}

// Impersonation Helper (platform super admin viewing an estate; acts through that estate's Central Admin account)
function is_impersonating_estate() {
    return !empty($_SESSION['platform_user_id']) && !empty($_SESSION['impersonated_estate_id']);
}

// Get Impersonated Estate Meta
function get_impersonated_estate_name($conn = null) {
    if (!is_impersonating_estate()) return null;
    return Tenancy::$estate['name'] ?? ('Estate #' . intval($_SESSION['impersonated_estate_id']));
}

// Platform module catalogue (managed by the Super Admin in the control-plane `modules` table).
function getAllPlatformModules() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        $r = Tenancy::platform()->query("SELECT module_key, name, description, icon, category FROM modules ORDER BY sort_order");
        while ($r && $row = $r->fetch_assoc()) {
            $cache[$row['module_key']] = ['name' => $row['name'], 'desc' => $row['description'], 'icon' => $row['icon'], 'category' => $row['category']];
        }
    } catch (Throwable $e) { error_log('getAllPlatformModules: ' . $e->getMessage()); }
    return $cache;
}
// Module Feature Flag Gatekeeper - delegates to the entitlement engine (global -> estate override -> plan -> add-on).
function isModuleEnabled($module_key, $estate_id = null) {
    if (Tenancy::$mode === 'legacy') return true;
    if (!$estate_id) $estate_id = get_estate_id();
    if (!$estate_id) return false;
    return Entitlements::allowed($estate_id, $module_key);
}

// Require Module or redirect with an explanatory message
function requireModule($module_key, $redirect_url = null) {
    if (isModuleEnabled($module_key)) return;
    if (session_status() === PHP_SESSION_NONE) session_start();
    $script = str_replace('\\', '/', $_SERVER["SCRIPT_NAME"] ?? "");
    if ($redirect_url === null) {
        foreach (["admin", "zone", "staff", "resident"] as $d) if (strpos($script, "/$d/") !== false) { $redirect_url = "../$d/index"; break; }
        if ($redirect_url === null) $redirect_url = strpos($script, "/api/") !== false ? null : "index";
    }
    $dec = Entitlements::decide(get_estate_id(), $module_key);
    $_SESSION["flash_error"] = "This feature (" . htmlspecialchars($module_key) . ") is not available on your estate plan (" . Entitlements::sourceLabel($dec["source"]) . "). Contact your Central Admin or upgrade your plan.";
    if ($redirect_url === null || strpos($script, "/api/") !== false) {
        http_response_code(403);
        header("Content-Type: application/json");
        echo json_encode(["success" => false, "error" => "Module not available on this plan"]);
        exit;
    }
    header("Location: " . $redirect_url);
    exit;
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
        
        $platformName = class_exists('PlatformSettings') ? PlatformSettings::get('site_company_name', 'Estate Platform') : 'Estate Platform';
        $platformLogo = class_exists('PlatformSettings') ? PlatformSettings::get('site_logo', '') : '';
        
        $data = [
            'estate_id' => $estate_id,
            'estate_name' => $estate_id > 0 ? 'Main Estate' : $platformName,
            'estate_logo' => $platformLogo,
            'estate_logo_url' => '',
            'app_company_name' => $platformName,
            'app_company_logo' => $platformLogo,
            'app_company_logo_url' => '',
            'theme_color' => '#3b82f6'
        ];
        
        if ($conn instanceof mysqli) {
            try {
                // Control plane DB has no system_settings table; only query tenant tables if connected to a tenant DB
                if (class_exists('Tenancy') && Tenancy::isPlatformConn($conn) && !Tenancy::hasTenant()) {
                    if ($estate_id > 0) {
                        $e_res = $conn->query("SELECT name FROM estates WHERE id = $estate_id LIMIT 1");
                        if ($e_res && $e_row = $e_res->fetch_assoc()) {
                            if (!empty($e_row['name'])) $data['estate_name'] = $e_row['name'];
                        }
                    }
                } else {
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
            } catch (Throwable $e) {
                // Silently fallback on missing tables or DB errors
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

// NOTE: runtime CREATE TABLE / demo-data seeding was removed. Tenant schema = database/tenant_schema.sql + database/migrations/tenant,
// system defaults = database/tenant_seed.json (applied once at provisioning). Helper files are still loaded for their functions.
require_once __DIR__ . '/includes/emergency_roster_init.php';
require_once __DIR__ . '/includes/policy_init.php';

// -------------------------------------------------------------------------
// PLAN LIMITS (resident capacity / staff-account cap) - read from the estate's subscription terms, never hardcoded
// -------------------------------------------------------------------------
if (!function_exists('estatePlanLimit')) {
    /** @return int|null limit (0/null = unlimited) for 'resident_limit' or 'user_limit' */
    function estatePlanLimit($which) {
        if (Tenancy::$mode === 'legacy' || !Tenancy::$estate) return null;
        $id = intval(Tenancy::$estate['id']);
        $p = Tenancy::platform();
        $sub = $p->query("SELECT resident_limit, plan_snapshot_json FROM subscriptions WHERE estate_id = $id AND status IN ('active','renewed','grace_period','past_due','suspended') ORDER BY id DESC LIMIT 1")->fetch_assoc();
        if ($sub) {
            if ($which === 'resident_limit') return intval($sub['resident_limit']);
            $snap = json_decode($sub['plan_snapshot_json'] ?? '', true);
            return intval($snap['plan']['user_limit'] ?? 0);
        }
        if ((Tenancy::$estate['status'] ?? '') === 'trial') return $which === 'resident_limit' ? PlatformSettings::int('trial_resident_limit', 25) : PlatformSettings::int('trial_user_limit', 5);
        return null; // onboarding estates: no cap until a plan is chosen
    }
}
if (!function_exists('residentLimitMessage')) {
    /** Returns an error message when adding another resident would exceed the plan, else null. */
    function residentLimitMessage($conn, $adding = 1) {
        $limit = estatePlanLimit('resident_limit');
        if (!$limit) return null;
        $n = intval($conn->query("SELECT COUNT(*) c FROM residents WHERE status = 'active'")->fetch_assoc()['c']);
        if ($n + $adding > $limit) return "Resident limit reached: your plan allows $limit residents and $n are already registered. Upgrade your plan under Subscription & Billing to add more.";
        return null;
    }
}
if (!function_exists('userLimitMessage')) {
    /** Cap on staff/admin accounts (non-resident users). */
    function userLimitMessage($conn) {
        $limit = estatePlanLimit('user_limit');
        if (!$limit) return null;
        $n = intval($conn->query("SELECT COUNT(*) c FROM users WHERE role <> 'resident' AND status IN ('active','invited','pending')")->fetch_assoc()['c']);
        if ($n >= $limit) return "Staff account limit reached: your plan allows $limit staff/admin accounts ($n in use). Upgrade your plan to add more.";
        return null;
    }
}

if (!function_exists('storageLimitMessage')) {
    /** Check if estate has exceeded storage limit and return message if over. */
    function storageLimitMessage($conn) {
        if (!PlatformSettings::bool('storage_limit_enforcement', true)) return null;

        $id = intval(Tenancy::$estate['id'] ?? 0);
        if (!$id) return null;

        $p = Tenancy::platform();
        $sub = $p->query("SELECT storage_limit_mb FROM subscriptions WHERE estate_id = $id AND status IN ('active','renewed','grace_period','past_due','suspended') ORDER BY id DESC LIMIT 1")->fetch_assoc();
        $limit = $sub ? intval($sub['storage_limit_mb']) : 0;

        if ($limit === 0) return null; // unlimited

        // Calculate current usage (uploads, backups, exports)
        $uploads_size = 0;
        $uploads_dir = __DIR__ . '/uploads';
        if (is_dir($uploads_dir)) {
            foreach (scandir($uploads_dir) as $f) {
                if ($f !== '.' && $f !== '..') {
                    $path = $uploads_dir . '/' . $f;
                    if (is_file($path)) $uploads_size += filesize($path);
                }
            }
        }
        $uploads_mb = round($uploads_size / 1024 / 1024, 2);

        if ($uploads_mb >= $limit) {
            return "Storage limit exceeded: your plan allows {$limit} MB and you are using {$uploads_mb} MB. Delete old files or upgrade your plan.";
        } elseif ($uploads_mb > $limit * 0.9) {
            return "Storage limit warning: you are using {$uploads_mb} MB of {$limit} MB. Consider upgrading soon.";
        }
        return null;
    }
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
