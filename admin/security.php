<?php
// admin/security.php
require_once '../config.php';
require_once '../includes/auth_guard.php';

if (isZoneAdminRole()) {
    header("Location: ../zone/index?error=security_access_denied");
    exit;
}

require_once '../includes/Mailer.php';
require_once '../includes/vehicle_helper.php';
requirePermission('visitors.view_log');

include '../includes/header.php';
include '../includes/sidebar.php';

$estate_id = get_estate_id();
$user_id = $_SESSION['user_id'];

// Fetch Estate Gate Policy Settings
$sys_gate_res = $conn->query("SELECT setting_value FROM system_settings WHERE estate_id = $estate_id AND setting_key = 'require_resident_visitor_confirmation' LIMIT 1");
$require_resident_confirmation = ($sys_gate_res && $sys_gate_res->num_rows > 0) ? ($sys_gate_res->fetch_assoc()['setting_value'] == '1') : true;
$can_bypass_confirmation = hasPermission('visitors.bypass_confirmation') || isAdminRole();

$message = "";
$error = "";

// Determine Active Duty Shift Template for Audit Log Stamping
$time_str_now = date('H:i:s');
$shift_lookup = $conn->query("SELECT name FROM security_shifts WHERE estate_id = $estate_id AND is_active = 1 AND 
    ((start_time <= end_time AND '$time_str_now' BETWEEN start_time AND end_time) OR 
     (start_time > end_time AND ('$time_str_now' >= start_time OR '$time_str_now' <= end_time))) LIMIT 1");
$active_shift_stamp = ($shift_lookup && $ts = $shift_lookup->fetch_assoc()) ? $ts['name'] : 'General Security Shift';

// POST ACTIONS: PROCESS RESIDENT VEHICLE GATE ENTRY/EXIT
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action_type']) && $_POST['action_type'] == 'process_vehicle_movement') {
    $v_id = intval($_POST['vehicle_id'] ?? 0);
    $direction = ($_POST['direction'] ?? '') === 'exit' ? 'exit' : 'entry';
    $entry_gate = $conn->real_escape_string($_POST['entry_gate'] ?? 'Main Gate');
    $notes = $conn->real_escape_string(trim($_POST['guard_notes'] ?? ''));
    $reg = trim($_POST['reg_number'] ?? '');
    
    if (recordVehicleGateLog($conn, $estate_id, $v_id, $direction, $entry_gate, $active_shift_stamp, $user_id, $notes)) {
        $message = "Vehicle $reg clearance recorded: " . strtoupper($direction) . " at $entry_gate under $active_shift_stamp!";
    } else {
        $error = "Could not record vehicle clearance.";
    }
}

// -------------------------------------------------------------
// POST ACTIONS: PROCESS ENTRY
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action_type']) && $_POST['action_type'] == 'process_entry') {
    $v_id = intval($_POST['visitor_id']);
    $entry_gate = $conn->real_escape_string($_POST['entry_gate'] ?? 'Main Gate');
    $vehicle_plate = $conn->real_escape_string(strtoupper(trim($_POST['vehicle_plate'] ?? '')));
    $guard_notes = $conn->real_escape_string(trim($_POST['guard_notes'] ?? ''));
    
    $check_v = $conn->query("SELECT * FROM visitors WHERE id = $v_id AND estate_id = $estate_id");
    if ($check_v && $check_v->num_rows > 0) {
        $v_row = $check_v->fetch_assoc();
        if ($v_row['status'] == 'pre_registered') {
            $conn->query("UPDATE visitors SET 
                status = 'entered', 
                entry_time = NOW(), 
                entry_gate = '$entry_gate', 
                entry_shift_name = '$active_shift_stamp',
                entry_processed_by = $user_id" .
                (!empty($vehicle_plate) ? ", vehicle_plate = '$vehicle_plate'" : "") .
                (!empty($guard_notes) ? ", guard_notes = '$guard_notes'" : "") . "
                WHERE id = $v_id");
            // Send instant gate arrival alert to resident
            EstateMailer::sendVisitorArrivalAlert($conn, $v_id, $entry_gate);
            logAudit($conn, "Visitor Entry Recorded", "Security", "Visitor {$v_row['name']} (Code: {$v_row['visitor_code']}) entered via $entry_gate during $active_shift_stamp");
            $message = "Entry recorded for visitor '{$v_row['name']}' at $entry_gate under $active_shift_stamp (Resident alerted via email)!";
        } else {
            $error = "Visitor status is already '{$v_row['status']}'.";
        }
    } else {
        $error = "Visitor not found.";
    }
}

// -------------------------------------------------------------
// POST ACTIONS: WALKIN REGISTRATION & IMMEDIATE ENTRY
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action_type']) && $_POST['action_type'] == 'walkin_entry') {
    $first_name = $conn->real_escape_string($_POST['first_name']);
    $last_name = $conn->real_escape_string($_POST['last_name']);
    $full_name = trim($first_name . ' ' . $last_name);
    $phone = $conn->real_escape_string($_POST['phone']);
    $resident_id = intval($_POST['resident_id']);
    $purpose = $conn->real_escape_string($_POST['purpose']);
    $entry_gate = $conn->real_escape_string($_POST['entry_gate'] ?? 'Main Gate');
    $vehicle_plate = $conn->real_escape_string(strtoupper(trim($_POST['vehicle_plate'] ?? '')));
    $guard_notes = $conn->real_escape_string(trim($_POST['guard_notes'] ?? ''));
    
    // Fetch Resident flat_id
    $res_info = $conn->query("SELECT flat_id FROM residents WHERE user_id = $resident_id AND estate_id = $estate_id LIMIT 1")->fetch_assoc();
    $flat_id = $res_info['flat_id'] ?? null;
    
    $rand_str = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
    $visitor_code = 'EST-' . $rand_str;
    
    $sql = "INSERT INTO visitors (estate_id, resident_id, flat_id, first_name, last_name, name, phone, purpose, visitor_code, entry_time, entry_gate, entry_shift_name, vehicle_plate, guard_notes, entry_processed_by, status) 
            VALUES ($estate_id, $resident_id, " . ($flat_id ? $flat_id : "NULL") . ", '$first_name', '$last_name', '$full_name', '$phone', '$purpose', '$visitor_code', NOW(), '$entry_gate', '$active_shift_stamp', " . (!empty($vehicle_plate) ? "'$vehicle_plate'" : "NULL") . ", " . (!empty($guard_notes) ? "'$guard_notes'" : "NULL") . ", $user_id, 'entered')";
    
    if ($conn->query($sql)) {
        $inserted_walkin_id = $conn->insert_id;
        // Send arrival alert to resident
        EstateMailer::sendVisitorArrivalAlert($conn, $inserted_walkin_id, $entry_gate);
        logAudit($conn, "Walk-in Visitor Registered", "Security", "Walk-in visitor $full_name registered and checked in at $entry_gate during $active_shift_stamp with code: $visitor_code");
        $message = "Walk-in visitor '$full_name' registered & checked in! Pass Code: <strong class='font-monospace text-primary'>$visitor_code</strong> <a href='../gate_pass?id=$inserted_walkin_id' target='_blank' class='btn btn-sm btn-primary ms-2 rounded-pill'><i class='fa-solid fa-print me-1'></i> Print Gate Pass</a>";
    } else {
        $error = "Error registering walk-in visitor: " . $conn->error;
    }
}

// -------------------------------------------------------------
// POST ACTIONS: PROCESS CHECKOUT / EXIT
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action_type']) && $_POST['action_type'] == 'process_exit') {
    $v_id = intval($_POST['visitor_id']);
    $exit_gate = $conn->real_escape_string($_POST['exit_gate'] ?? 'Main Gate');
    $exit_reason = isset($_POST['exit_reason']) ? $conn->real_escape_string($_POST['exit_reason']) : null;
    
    $check_v = $conn->query("SELECT * FROM visitors WHERE id = $v_id AND estate_id = $estate_id");
    if ($check_v && $check_v->num_rows > 0) {
        $v_row = $check_v->fetch_assoc();
        if (in_array($v_row['status'], ['entered', 'confirmed'])) {
            $is_confirmed = !empty($v_row['resident_confirmed_at']) || !$require_resident_confirmation || $can_bypass_confirmation;
            
            if ($is_confirmed) {
                // Confirmed Exit or Direct Clearance
                $conn->query("UPDATE visitors SET status = 'checked_out', exit_time = NOW(), exit_gate = '$exit_gate', exit_shift_name = '$active_shift_stamp', exit_processed_by = $user_id" . (!empty($exit_reason) ? ", exit_reason = '$exit_reason'" : "") . " WHERE id = $v_id");
                logAudit($conn, "Visitor Checked Out", "Security", "Visitor {$v_row['name']} checked out via $exit_gate during $active_shift_stamp" . (!$require_resident_confirmation ? " (Direct Clearance Mode)" : ""));
                $message = "Exit approved for visitor '{$v_row['name']}'!";
            } else {
                // Unconfirmed Exit (Requires Reason)
                if (empty($exit_reason)) {
                    $exit_reason = "Resident not at home";
                }
                $conn->query("UPDATE visitors SET status = 'exited_without_confirmation', exit_time = NOW(), exit_reason = '$exit_reason', exit_gate = '$exit_gate', exit_shift_name = '$active_shift_stamp', exit_processed_by = $user_id WHERE id = $v_id");
                logAudit($conn, "Visitor Exited Unconfirmed", "Security", "Visitor {$v_row['name']} exited without confirmation via $exit_gate during $active_shift_stamp. Reason: $exit_reason");
                $message = "Exit recorded for unconfirmed visitor '{$v_row['name']}'. Reason: $exit_reason";
            }
        } else {
            $error = "Visitor is not inside the estate (Status: '{$v_row['status']}').";
        }
    } else {
        $error = "Visitor not found.";
    }
}

// -------------------------------------------------------------
// FILTER CONTROLS FOR ANALYTICS & AUDIT LOGS
// -------------------------------------------------------------
$filter_resident = isset($_GET['filter_resident']) ? intval($_GET['filter_resident']) : 0;
$filter_status = isset($_GET['filter_status']) ? $conn->real_escape_string($_GET['filter_status']) : '';
$filter_gate = isset($_GET['filter_gate']) ? $conn->real_escape_string($_GET['filter_gate']) : '';
$filter_shift = isset($_GET['filter_shift']) ? $conn->real_escape_string(trim($_GET['filter_shift'])) : '';
$filter_staff = isset($_GET['filter_staff']) ? intval($_GET['filter_staff']) : 0;
$filter_date_from = isset($_GET['filter_date_from']) ? $conn->real_escape_string($_GET['filter_date_from']) : '';
$filter_date_to = isset($_GET['filter_date_to']) ? $conn->real_escape_string($_GET['filter_date_to']) : '';
$search_code = isset($_GET['search_code']) ? $conn->real_escape_string(trim($_GET['search_code'])) : '';

$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'gate_manager';

// Build SQL Where Clauses
$where_clauses = ["v.estate_id = $estate_id"];
if ($filter_resident > 0) $where_clauses[] = "v.resident_id = $filter_resident";
if (!empty($filter_status)) $where_clauses[] = "v.status = '$filter_status'";
if (!empty($filter_gate)) $where_clauses[] = "(v.entry_gate = '$filter_gate' OR v.exit_gate = '$filter_gate')";
if (!empty($filter_shift)) $where_clauses[] = "(v.entry_shift_name = '$filter_shift' OR v.exit_shift_name = '$filter_shift')";
if ($filter_staff > 0) $where_clauses[] = "(v.entry_processed_by = $filter_staff OR v.exit_processed_by = $filter_staff)";
if (!empty($filter_date_from)) $where_clauses[] = "DATE(v.entry_time) >= '$filter_date_from'";
if (!empty($filter_date_to)) $where_clauses[] = "DATE(v.entry_time) <= '$filter_date_to'";
if (!empty($search_code)) $where_clauses[] = "(v.visitor_code LIKE '%$search_code%' OR v.name LIKE '%$search_code%' OR v.phone LIKE '%$search_code%' OR v.vehicle_plate LIKE '%$search_code%')";

$where_sql = implode(' AND ', $where_clauses);

// -------------------------------------------------------------
// AGGREGATE KPI METRICS
// -------------------------------------------------------------
$kpi_query = "SELECT 
    COUNT(v.id) AS total_visitors,
    SUM(CASE WHEN DATE(v.entry_time) = CURRENT_DATE() THEN 1 ELSE 0 END) AS total_today,
    SUM(CASE WHEN v.status IN ('entered', 'confirmed') THEN 1 ELSE 0 END) AS currently_inside,
    SUM(CASE WHEN v.status = 'checked_out' AND DATE(v.exit_time) = CURRENT_DATE() THEN 1 ELSE 0 END) AS checked_out_today,
    SUM(CASE WHEN v.status = 'exited_without_confirmation' AND DATE(v.exit_time) = CURRENT_DATE() THEN 1 ELSE 0 END) AS unconfirmed_exits_today,
    AVG(CASE WHEN v.exit_time IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, v.entry_time, v.exit_time) ELSE NULL END) AS avg_duration_minutes,
    MAX(CASE WHEN v.exit_time IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, v.entry_time, v.exit_time) ELSE NULL END) AS max_duration_minutes
    FROM visitors v
    WHERE v.estate_id = $estate_id";
$kpis = $conn->query($kpi_query)->fetch_assoc();

// Fetch Visitors Currently Inside Estate
$inside_query = "SELECT v.*, u.name as resident_name, f.number as flat_number, b.name as building_name,
                        u_entry.name as entry_staff_name, u_entry.role as entry_staff_role,
                        TIMESTAMPDIFF(MINUTE, v.entry_time, NOW()) AS minutes_inside
                 FROM visitors v
                 LEFT JOIN users u ON v.resident_id = u.id
                 LEFT JOIN flats f ON v.flat_id = f.id
                 LEFT JOIN buildings b ON f.building_id = b.id
                 LEFT JOIN users u_entry ON v.entry_processed_by = u_entry.id
                 WHERE v.estate_id = $estate_id AND v.status IN ('entered', 'confirmed')
                 ORDER BY v.entry_time DESC";
$inside_res = $conn->query($inside_query);

// Fetch Pre-Registered Visitors
$prereg_query = "SELECT v.*, u.name as resident_name, f.number as flat_number
                 FROM visitors v
                 LEFT JOIN users u ON v.resident_id = u.id
                 LEFT JOIN flats f ON v.flat_id = f.id
                 WHERE v.estate_id = $estate_id AND v.status = 'pre_registered'
                 ORDER BY v.created_at DESC LIMIT 20";
$prereg_res = $conn->query($prereg_query);

// Fetch Full Visitor Audit Log with TIMESTAMPDIFF calculation
$audit_query = "SELECT v.*, 
                       u_res.name AS resident_name, 
                       f.number AS flat_number, 
                       b.name AS building_name,
                       s.name AS street_name,
                       u_entry.name AS entry_staff_name,
                       u_entry.role AS entry_staff_role,
                       u_exit.name AS exit_staff_name,
                       u_exit.role AS exit_staff_role,
                       TIMESTAMPDIFF(MINUTE, v.entry_time, v.exit_time) AS duration_minutes
                FROM visitors v
                LEFT JOIN users u_res ON v.resident_id = u_res.id
                LEFT JOIN flats f ON v.flat_id = f.id
                LEFT JOIN buildings b ON f.building_id = b.id
                LEFT JOIN streets s ON b.street_id = s.id
                LEFT JOIN users u_entry ON v.entry_processed_by = u_entry.id
                LEFT JOIN users u_exit ON v.exit_processed_by = u_exit.id
                WHERE $where_sql
                ORDER BY v.created_at DESC";
$audit_res = $conn->query($audit_query);

// Fetch Resident list for selects & filters
$residents_list = $conn->query("SELECT u.id, u.name, f.number as flat_number FROM users u JOIN residents r ON u.id = r.user_id LEFT JOIN flats f ON r.flat_id = f.id WHERE u.estate_id = $estate_id GROUP BY u.id ORDER BY u.name ASC");

// Fetch Staff list for gate manager filter
$staff_list = $conn->query("SELECT id, name FROM users WHERE estate_id = $estate_id AND role IN ('admin', 'manager', 'staff', 'security') ORDER BY name ASC");

// Fetch Shifts list for audit filter
$shifts_list_admin = $conn->query("SELECT name FROM security_shifts WHERE estate_id = $estate_id AND is_active = 1 ORDER BY name ASC");

// Fetch Today's & Recent Gate Activity for Live Stream on Gate Manager
$today_str = date('Y-m-d');
$today_filter_gate = isset($_GET['today_gate']) ? $conn->real_escape_string($_GET['today_gate']) : '';
$today_filter_shift = isset($_GET['today_shift']) ? $conn->real_escape_string($_GET['today_shift']) : '';
$today_filter_status = isset($_GET['today_status']) ? $conn->real_escape_string($_GET['today_status']) : '';
$today_filter_scope = isset($_GET['today_scope']) ? $_GET['today_scope'] : 'auto';
$today_search = isset($_GET['today_q']) ? $conn->real_escape_string(trim($_GET['today_q'])) : '';

$today_activity_count = $conn->query("SELECT COUNT(*) as c FROM visitors WHERE estate_id = $estate_id AND (DATE(entry_time) = '$today_str' OR DATE(exit_time) = '$today_str' OR DATE(created_at) = '$today_str')")->fetch_assoc()['c'] ?? 0;

$effective_scope = $today_filter_scope;
if ($effective_scope === 'auto') {
    $effective_scope = ($today_activity_count > 0) ? 'today' : 'recent';
}

$today_where = ["v.estate_id = $estate_id"];
if ($effective_scope === 'today') {
    $today_where[] = "(DATE(v.entry_time) = '$today_str' OR DATE(v.exit_time) = '$today_str' OR (DATE(v.created_at) = '$today_str' AND v.status IN ('entered', 'checked_out', 'exited_without_confirmation')))";
} else {
    $today_where[] = "v.status IN ('entered', 'checked_out', 'exited_without_confirmation', 'confirmed')";
}

if (!empty($today_filter_gate)) {
    $today_where[] = "(v.entry_gate = '$today_filter_gate' OR v.exit_gate = '$today_filter_gate')";
}
if (!empty($today_filter_shift)) {
    $today_where[] = "(v.entry_shift_name = '$today_filter_shift' OR v.exit_shift_name = '$today_filter_shift')";
}
if (!empty($today_filter_status)) {
    $today_where[] = "v.status = '$today_filter_status'";
}
if (!empty($today_search)) {
    $today_where[] = "(v.visitor_code LIKE '%$today_search%' OR v.name LIKE '%$today_search%' OR v.phone LIKE '%$today_search%' OR v.vehicle_plate LIKE '%$today_search%' OR u_res.name LIKE '%$today_search%')";
}

$today_stream_query = "SELECT v.*, 
                              u_res.name AS resident_name, 
                              f.number AS flat_number, 
                              b.name AS building_name,
                              u_entry.name AS entry_staff_name,
                              u_exit.name AS exit_staff_name,
                              TIMESTAMPDIFF(MINUTE, v.entry_time, COALESCE(v.exit_time, NOW())) AS duration_minutes
                       FROM visitors v
                       LEFT JOIN users u_res ON v.resident_id = u_res.id
                       LEFT JOIN flats f ON v.flat_id = f.id
                       LEFT JOIN buildings b ON f.building_id = b.id
                       LEFT JOIN users u_entry ON v.entry_processed_by = u_entry.id
                       LEFT JOIN users u_exit ON v.exit_processed_by = u_exit.id
                       WHERE " . implode(' AND ', $today_where) . "
                       ORDER BY COALESCE(v.exit_time, v.entry_time, v.created_at) DESC LIMIT 50";
$today_stream_res = $conn->query($today_stream_query);

// Vehicle Clearance & Sticker Queries
$searched_vehicle_q = trim($_GET['search_vehicle'] ?? '');
$vehicle_dossier = !empty($searched_vehicle_q) ? getVehicleDossier($conn, $estate_id, $searched_vehicle_q) : null;
$estate_vehicles_count = $conn->query("SELECT COUNT(*) as c FROM vehicles WHERE estate_id = $estate_id")->fetch_assoc()['c'] ?? 0;

// All registered vehicles in estate
$all_vehicles_res = $conn->query("
    SELECT v.*, 
           f.number as flat_number, f.floor,
           b.name as building_name, s.name as street_name, z.name as zone_name,
           u.name as owner_name, u.phone as owner_phone, u.email as owner_email,
           r.custom_id as resident_custom_id
    FROM vehicles v
    LEFT JOIN flats f ON v.flat_id = f.id
    LEFT JOIN buildings b ON f.building_id = b.id
    LEFT JOIN streets s ON b.street_id = s.id
    LEFT JOIN zones z ON s.zone_id = z.id
    LEFT JOIN residents r ON r.flat_id = f.id AND (r.type = 'head' OR r.type IS NULL)
    LEFT JOIN users u ON r.user_id = u.id
    WHERE v.estate_id = $estate_id
    GROUP BY v.id
    ORDER BY v.id DESC
");

// Vehicle Movement Activity Stream
$vehicle_logs_admin = $conn->query("
    SELECT vgl.*, v.model, v.color, v.custom_id, u.name as officer_name, f.number as flat_number
    FROM vehicle_gate_logs vgl
    LEFT JOIN vehicles v ON vgl.vehicle_id = v.id
    LEFT JOIN flats f ON v.flat_id = f.id
    LEFT JOIN users u ON vgl.processed_by = u.id
    WHERE vgl.estate_id = $estate_id
    ORDER BY vgl.logged_at DESC LIMIT 30
");
?>

<div class="page-header-futuristic mb-4">
    <div>
        <div class="header-breadcrumbs">
            <a href="index">Dashboard</a>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span>Perimeter Operations</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span class="active">Gate & Security</span>
        </div>
        <h1 class="page-title">Security Command &amp; Gate Control</h1>
        <p class="page-subtitle">Real-time visitor processing, automated gate pass verification, access duration radar, and audit trails.</p>
    </div>
    <div class="header-actions">
        <a href="security?tab=audit_logs" class="btn btn-sm btn-primary text-white shadow-sm">
            <i class="fa-solid fa-clipboard-list me-1"></i> Full Gate Logs Archive
        </a>
        <a href="roster" class="btn btn-sm btn-outline-primary">
            <i class="fa-solid fa-calendar-check me-1"></i> Duty Roster &amp; Live Guards
        </a>
        <a href="generate_id" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-id-card me-1"></i> ID Cards
        </a>
        <button class="btn btn-sm text-white" style="background: #0f172a;" data-bs-toggle="modal" data-bs-target="#walkinModal">
            <i class="fa-solid fa-user-plus me-1"></i> Register Walk-in
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert mature-card p-3 mb-4" style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); color: #059669; border-radius: 0.65rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fa-solid fa-circle-check" style="font-size: 1.1rem;"></i>
        <div style="font-weight: 500; font-size: 0.9rem;"><?= htmlspecialchars($message) ?></div>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert mature-card p-3 mb-4" style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; border-radius: 0.65rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fa-solid fa-circle-exclamation" style="font-size: 1.1rem;"></i>
        <div style="font-weight: 500; font-size: 0.9rem;"><?= htmlspecialchars($error) ?></div>
    </div>
<?php endif; ?>

<!-- ==========================================
     EXECUTIVE KPI METRICS RIBBON (4 PILLARS)
     ========================================== -->
<div class="row g-3 mb-4">
    <!-- Pillar 1: Currently Inside -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Currently Inside</span>
                    <div class="kpi-value"><?= number_format($kpis['currently_inside'] ?? 0) ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-user-clock"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Active On-Premises</span>
                <span class="mature-badge mature-badge-primary">Live Inside</span>
            </div>
            <div class="kpi-progress-bar">
                <?php 
                $inside_count = intval($kpis['currently_inside'] ?? 0);
                $today_count = intval($kpis['total_today'] ?? 0);
                $inside_fill = ($today_count > 0) ? min(100, round(($inside_count / $today_count) * 100)) : ($inside_count > 0 ? 50 : 10);
                ?>
                <div class="kpi-progress-fill" style="width: <?= max(10, $inside_fill) ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 2: Total Admitted Today -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Total Admitted Today</span>
                    <div class="kpi-value"><?= number_format($today_count) ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-door-open"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Checked In Today</span>
                <span class="mature-badge mature-badge-emerald">Logged Flow</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?= min(100, max(20, $today_count * 8)) ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 3: Average Visit Duration -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Average Duration</span>
                    <div class="kpi-value"><?= formatDuration($kpis['avg_duration_minutes'] ?? 0) ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-stopwatch"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Stay Duration</span>
                <span class="mature-badge mature-badge-slate">Max <?= formatDuration($kpis['max_duration_minutes'] ?? 0) ?></span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?= min(100, max(15, intval($kpis['avg_duration_minutes'] ?? 0))) ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 4: Unconfirmed Exits -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Unconfirmed Exits</span>
                    <div class="kpi-value"><?= number_format($kpis['unconfirmed_exits_today'] ?? 0) ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-user-ninja"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span><?= !$require_resident_confirmation ? 'Policy' : 'Resident Absent' ?></span>
                <span class="mature-badge <?= !$require_resident_confirmation ? 'mature-badge-sky' : (($kpis['unconfirmed_exits_today'] ?? 0) > 0 ? 'mature-badge-amber' : 'mature-badge-emerald') ?>">
                    <?= !$require_resident_confirmation ? 'Direct Clearance' : (($kpis['unconfirmed_exits_today'] ?? 0) > 0 ? 'Requires Review' : 'Zero Exceptions') ?>
                </span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?= min(100, max(10, intval($kpis['unconfirmed_exits_today'] ?? 0) * 25)) ?>%;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Mode Switcher Tabs Navigation -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div class="d-flex align-items-center gap-2">
        <a class="filter-btn-pill <?= ($active_tab == 'gate_manager') ? 'active' : '' ?>" href="security?tab=gate_manager">
            <i class="fa-solid fa-door-closed me-1"></i> Gate Manager &amp; Live Logs
        </a>
        <a class="filter-btn-pill <?= ($active_tab == 'vehicles') ? 'active' : '' ?>" href="security?tab=vehicles">
            <i class="fa-solid fa-car me-1"></i> Vehicles &amp; Car Stickers
        </a>
        <a class="filter-btn-pill <?= ($active_tab == 'audit_logs') ? 'active' : '' ?>" href="security?tab=audit_logs">
            <i class="fa-solid fa-list-check me-1"></i> Full Visitor Audit &amp; All-Time Archive
        </a>
    </div>
    <?php if ($active_tab == 'gate_manager'): ?>
        <a href="security?tab=audit_logs" class="btn btn-sm btn-outline-primary rounded-pill px-3 shadow-sm fw-semibold">
            <i class="fa-solid fa-clock-rotate-left me-1"></i> View Full Historical Logs Archive &rarr;
        </a>
    <?php endif; ?>
</div>

<?php if ($active_tab == 'gate_manager'): ?>
    <!-- GATE MANAGER INTERFACE -->
    
    <!-- Code Look up & Verification Banner -->
    <div class="mature-card p-4 mb-4" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: white; border: 1px solid #334155;">
        <div class="row align-items-center">
            <div class="col-md-7">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="mature-badge mature-badge-primary">
                        <i class="fa-solid fa-qrcode me-1"></i> Quick Gate Scanner
                    </span>
                    <span class="text-slate-400 small">&bull; Real-Time Verification</span>
                </div>
                <h4 class="fw-bold mb-1 text-white" style="letter-spacing: -0.02em;">Gate Pass Code Verification</h4>
                <p class="mb-0 text-slate-300 small">Enter visitor's 6-character access pass code (e.g. <strong>EST-7K42P9</strong>) to verify entry or approve exit clearance.</p>
            </div>
            <div class="col-md-5 mt-3 mt-md-0">
                <form method="GET" action="security" class="d-flex gap-2">
                    <input type="hidden" name="tab" value="gate_manager">
                    <input type="text" name="search_code" class="form-control border-0 shadow-none font-monospace fw-bold text-uppercase" placeholder="ENTER CODE (E.G. EST-7K42P9)" value="<?= htmlspecialchars($search_code) ?>" style="letter-spacing: 2px; background: rgba(255,255,255,0.95); color: #0f172a;">
                    <button type="submit" class="btn text-white px-4 fw-semibold" style="background: #2563eb;">
                        <i class="fa-solid fa-shield-check me-1"></i> Verify
                    </button>
                </form>
            </div>
        </div>
    </div>

    <?php if (!empty($search_code)): ?>
        <?php 
            $search_res = $conn->query("SELECT v.*, u.name as resident_name, u.phone as resident_phone, f.number as flat_number, b.name as building_name 
                                       FROM visitors v 
                                       LEFT JOIN users u ON v.resident_id = u.id 
                                       LEFT JOIN flats f ON v.flat_id = f.id 
                                       LEFT JOIN buildings b ON f.building_id = b.id 
                                       WHERE v.estate_id = $estate_id AND (v.visitor_code = '$search_code' OR v.visitor_code = 'EST-$search_code')
                                       LIMIT 1");
        ?>
        <div class="mature-card p-4 mb-4" style="border-left: 4px solid #2563eb !important;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mature-card-title m-0">
                    <i class="fa-solid fa-id-card text-primary"></i> Verification Result for "<?= htmlspecialchars($search_code) ?>"
                </h5>
                <a href="security?tab=gate_manager" class="btn btn-sm btn-outline-secondary">Clear Search</a>
            </div>
            <?php if ($search_res && $search_res->num_rows > 0): ?>
                <?php $sv = $search_res->fetch_assoc(); ?>
                <div class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <div class="text-secondary small text-uppercase fw-semibold" style="font-size: 0.72rem;">Visitor Profile</div>
                        <div class="h5 fw-bold text-slate-900 mb-1 mt-0.5"><?= htmlspecialchars($sv['name']) ?></div>
                        <div class="mature-badge mature-badge-primary font-monospace fw-bold mb-1"><?= htmlspecialchars($sv['visitor_code']) ?></div>
                        <div class="small text-secondary"><i class="fa-solid fa-phone me-1 text-success"></i><?= htmlspecialchars($sv['phone'] ?? 'No Phone') ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-secondary small text-uppercase fw-semibold" style="font-size: 0.72rem;">Host &amp; Destination</div>
                        <div class="fw-bold text-slate-900 mt-0.5"><?= htmlspecialchars($sv['resident_name'] ?? 'N/A') ?></div>
                        <div class="small text-secondary">Unit <?= htmlspecialchars($sv['flat_number'] ?? 'N/A') ?> (<?= htmlspecialchars($sv['building_name'] ?? 'Main') ?>)</div>
                        <div class="small text-muted mt-0.5"><i class="fa-regular fa-compass me-1"></i><?= htmlspecialchars($sv['purpose'] ?? 'Personal') ?></div>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <div class="mb-2">
                            <?php if ($sv['status'] == 'confirmed'): ?>
                                <span class="mature-badge mature-badge-emerald px-3 py-1.5"><i class="fa-solid fa-circle-check me-1"></i> Confirmed Inside</span>
                            <?php elseif ($sv['status'] == 'entered'): ?>
                                <?php if (!$require_resident_confirmation): ?>
                                    <span class="mature-badge mature-badge-sky px-3 py-1.5"><i class="fa-solid fa-shield-halved me-1"></i> Direct Clearance Inside</span>
                                <?php else: ?>
                                    <span class="mature-badge mature-badge-amber px-3 py-1.5"><i class="fa-solid fa-clock me-1"></i> Pending Host Confirmation</span>
                                <?php endif; ?>
                            <?php elseif ($sv['status'] == 'pre_registered'): ?>
                                <span class="mature-badge mature-badge-sky px-3 py-1.5"><i class="fa-solid fa-ticket me-1"></i> Pre-Registered</span>
                            <?php else: ?>
                                <span class="mature-badge mature-badge-slate px-3 py-1.5"><?= strtoupper(str_replace('_', ' ', $sv['status'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex justify-content-md-end gap-2">
                            <?php if ($sv['status'] == 'pre_registered'): ?>
                                <a href="../gate_pass?id=<?= $sv['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                    <i class="fa-solid fa-print me-1"></i> Pass
                                </a>
                                <form method="POST" class="d-inline-block">
                                    <input type="hidden" name="action_type" value="process_entry">
                                    <input type="hidden" name="visitor_id" value="<?= $sv['id'] ?>">
                                    <input type="hidden" name="entry_gate" value="Main Gate">
                                    <button type="submit" class="btn btn-sm btn-success fw-semibold">
                                        <i class="fa-solid fa-door-open me-1"></i> Approve Gate Entry
                                    </button>
                                </form>
                            <?php elseif (in_array($sv['status'], ['entered', 'confirmed'])): ?>
                                <a href="../gate_pass?id=<?= $sv['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                    <i class="fa-solid fa-print me-1"></i> Pass
                                </a>
                                <button type="button" class="btn btn-sm btn-danger fw-semibold" data-bs-toggle="modal" data-bs-target="#exitModal<?= $sv['id'] ?>">
                                    <i class="fa-solid fa-door-closed me-1"></i> Approve Gate Exit
                                </button>
                            <?php else: ?>
                                <a href="../gate_pass?id=<?= $sv['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                    <i class="fa-solid fa-print me-1"></i> View Pass
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="text-danger fw-semibold"><i class="fa-solid fa-circle-xmark me-2"></i> No visitor pass record found matching code "<?= htmlspecialchars($search_code) ?>".</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Currently Inside Estate Table -->
        <div class="col-lg-8">
            <div class="mature-card mb-4 h-100">
                <div class="mature-card-header">
                    <div>
                        <h3 class="mature-card-title">
                            <i class="fa-solid fa-user-clock text-info"></i> Visitors Currently Inside Estate
                        </h3>
                        <p class="text-secondary small mb-0">Active visitor population currently on-premises</p>
                    </div>
                    <span class="mature-badge mature-badge-primary">
                        <?= $inside_res ? $inside_res->num_rows : 0 ?> Active Inside
                    </span>
                </div>
                <div class="mature-card-body p-0">
                    <div class="table-responsive">
                        <table class="table dashboard-table align-middle">
                            <thead>
                                <tr>
                                    <th class="ps-4">Visitor &amp; Code</th>
                                    <th>Resident / Unit</th>
                                    <th>Entry Radar</th>
                                    <th>Host Confirmation</th>
                                    <th class="text-end pe-4">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($inside_res && $inside_res->num_rows > 0): ?>
                                    <?php while ($iv = $inside_res->fetch_assoc()): ?>
                                        <?php $is_conf = !empty($iv['resident_confirmed_at']); ?>
                                        <tr>
                                            <td class="ps-4">
                                                <div class="fw-bold text-slate-900"><?= htmlspecialchars($iv['name']) ?></div>
                                                <div class="mature-badge mature-badge-slate font-monospace" style="font-size: 0.72rem;"><?= htmlspecialchars($iv['visitor_code']) ?></div>
                                            </td>
                                            <td>
                                                <div class="fw-semibold text-slate-900"><?= htmlspecialchars($iv['resident_name'] ?? 'N/A') ?></div>
                                                <div class="text-secondary small">Unit <?= htmlspecialchars($iv['flat_number'] ?? '-') ?></div>
                                            </td>
                                            <td>
                                                <div class="fw-bold text-slate-800"><?= date('h:i A', strtotime($iv['entry_time'])) ?></div>
                                                <div class="text-secondary small"><?= formatDuration($iv['minutes_inside']) ?> ago &bull; <?= htmlspecialchars($iv['entry_gate'] ?? 'Main Gate') ?></div>
                                                <div class="small text-primary mt-0.5" style="font-size:0.72rem; font-weight:600;">
                                                    <i class="fa-solid fa-user-shield me-1"></i><?= htmlspecialchars($iv['entry_staff_name'] ?? 'Gate Guard') ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($is_conf): ?>
                                                    <span class="mature-badge mature-badge-emerald">
                                                        <i class="fa-solid fa-check-circle me-1"></i> Confirmed (<?= date('h:i A', strtotime($iv['resident_confirmed_at'])) ?>)
                                                    </span>
                                                <?php elseif (!$require_resident_confirmation): ?>
                                                    <span class="mature-badge mature-badge-sky">
                                                        <i class="fa-solid fa-shield-halved me-1"></i> Direct Clearance
                                                    </span>
                                                <?php else: ?>
                                                    <span class="mature-badge mature-badge-amber">
                                                        <i class="fa-solid fa-clock me-1"></i> Awaiting Host
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-4 text-nowrap">
                                                <a href="../gate_pass?id=<?= $iv['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm" title="Print Gate Pass">
                                                    <i class="fa-solid fa-print"></i>
                                                </a>
                                                <button type="button" class="btn btn-danger btn-sm fw-semibold ms-1" data-bs-toggle="modal" data-bs-target="#exitModal<?= $iv['id'] ?>">
                                                    <i class="fa-solid fa-right-from-bracket me-1"></i> Checkout
                                                </button>
                                            </td>
                                        </tr>

                                        <!-- Exit Checkout Modal -->
                                        <div class="modal fade" id="exitModal<?= $iv['id'] ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content mature-card rounded-4 border-0 shadow">
                                                    <form method="POST">
                                                        <input type="hidden" name="action_type" value="process_exit">
                                                        <input type="hidden" name="visitor_id" value="<?= $iv['id'] ?>">
                                                        <div class="modal-header border-bottom">
                                                            <h5 class="modal-title fw-bold text-slate-900"><i class="fa-solid fa-door-closed text-danger me-2"></i> Approve Visitor Gate Exit</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body p-4">
                                                            <div class="bg-light p-3 rounded-3 mb-3 border">
                                                                <div class="fw-bold text-slate-900 h6 mb-1"><?= htmlspecialchars($iv['name']) ?></div>
                                                                <div class="text-muted small">Code: <strong class="font-monospace text-primary"><?= htmlspecialchars($iv['visitor_code']) ?></strong></div>
                                                                <div class="text-muted small">Resident: <strong><?= htmlspecialchars($iv['resident_name'] ?? 'N/A') ?></strong> (Unit <?= htmlspecialchars($iv['flat_number'] ?? '-') ?>)</div>
                                                                <div class="text-muted small">Entered: <strong><?= date('h:i A', strtotime($iv['entry_time'])) ?></strong></div>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label fw-semibold small text-secondary">Exit Gate</label>
                                                                <select name="exit_gate" class="form-select">
                                                                    <option value="Main Gate">Main Gate</option>
                                                                    <option value="North Gate">North Gate</option>
                                                                    <option value="South Gate">South Gate</option>
                                                                    <option value="Pedestrian Gate">Pedestrian Gate</option>
                                                                </select>
                                                            </div>

                                                            <?php 
                                                            $bypass_or_not_required = !$require_resident_confirmation || $can_bypass_confirmation;
                                                            ?>
                                                            <?php if ($is_conf || $bypass_or_not_required): ?>
                                                                <div class="alert mature-card p-3 mb-0" style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); color: #059669;" role="alert">
                                                                    <div class="d-flex align-items-center gap-2">
                                                                        <i class="fa-solid fa-circle-check fs-5 text-success"></i>
                                                                        <div class="small">
                                                                            <?php if ($is_conf): ?>
                                                                                <strong>Host Confirmed!</strong><br>
                                                                                Verified by resident at <?= date('h:i A', strtotime($iv['resident_confirmed_at'])) ?>. Standard checkout will be logged.
                                                                            <?php else: ?>
                                                                                <strong>Direct Gate Exit Clearance</strong><br>
                                                                                Estate policy allows direct gate exit without resident confirmation code. Standard checkout will be logged.
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            <?php else: ?>
                                                                <div class="alert mature-card p-3 mb-3" style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.3); color: #b45309;" role="alert">
                                                                    <div class="small">
                                                                        <i class="fa-solid fa-triangle-exclamation me-1"></i> <strong>Resident Absent / Unconfirmed</strong><br>
                                                                        Visitor is checking out without digital resident confirmation. Select reason:
                                                                    </div>
                                                                </div>

                                                                <div class="mb-3">
                                                                    <label class="form-label fw-semibold small text-secondary">Select Exit Exception Reason</label>
                                                                    <select name="exit_reason" class="form-select" required>
                                                                        <option value="Resident not at home">Resident not at home</option>
                                                                        <option value="Resident unavailable / did not answer">Resident unavailable / did not answer</option>
                                                                        <option value="Wrong address / Invalid resident name">Wrong address / Invalid resident name</option>
                                                                        <option value="Visitor choice / Left before meeting">Visitor choice / Left before meeting</option>
                                                                        <option value="Other">Other reason</option>
                                                                    </select>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="modal-footer border-top">
                                                            <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-sm btn-danger fw-semibold px-4">
                                                                <i class="fa-solid fa-check-double me-1"></i> Approve Gate Exit
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-5 text-secondary">
                                            <i class="fa-solid fa-shield-check fs-2 text-muted mb-2 d-block"></i>
                                            No visitors currently inside the estate perimeter.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pre-registered Visitors Awaiting Entry -->
        <div class="col-lg-4">
            <div class="mature-card h-100">
                <div class="mature-card-header">
                    <div>
                        <h3 class="mature-card-title">
                            <i class="fa-solid fa-list-check text-primary"></i> Expected Pre-Registered
                        </h3>
                        <p class="text-secondary small mb-0">Booked passes awaiting gate arrival</p>
                    </div>
                    <span class="mature-badge mature-badge-sky"><?= $prereg_res ? $prereg_res->num_rows : 0 ?> Pending</span>
                </div>
                <div class="mature-card-body p-0">
                    <?php if ($prereg_res && $prereg_res->num_rows > 0): ?>
                        <div class="list-group list-group-flush">
                            <?php while ($pv = $prereg_res->fetch_assoc()): ?>
                                <div class="list-group-item p-3 border-bottom border-light-subtle">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="fw-bold text-slate-900"><?= htmlspecialchars($pv['name']) ?></span>
                                        <span class="mature-badge mature-badge-slate font-monospace"><?= htmlspecialchars($pv['visitor_code']) ?></span>
                                    </div>
                                    <div class="small text-secondary mb-2">
                                        Visiting: <strong><?= htmlspecialchars($pv['resident_name'] ?? 'N/A') ?></strong> (Unit <?= htmlspecialchars($pv['flat_number'] ?? '-') ?>)
                                    </div>
                                    <div class="d-flex gap-2">
                                        <form method="POST" class="flex-grow-1">
                                            <input type="hidden" name="action_type" value="process_entry">
                                            <input type="hidden" name="visitor_id" value="<?= $pv['id'] ?>">
                                            <input type="hidden" name="entry_gate" value="Main Gate">
                                            <button type="submit" class="btn btn-sm btn-success w-100 fw-semibold">
                                                <i class="fa-solid fa-door-open me-1"></i> Admit Visitor
                                            </button>
                                        </form>
                                        <a href="../gate_pass?id=<?= $pv['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Print Gate Pass">
                                            <i class="fa-solid fa-print"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="p-5 text-center text-muted small">
                            <i class="fa-regular fa-calendar-check fs-2 text-muted mb-2 d-block"></i>
                            No pending pre-registered visitors.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ==========================================
         LIVE GATE ACTIVITY STREAM (TODAY / RECENT)
         ========================================== -->
    <div class="mature-card mt-4 mb-4" style="border-top: 3px solid #0d9488 !important;">
        <div class="mature-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="d-flex align-items-center gap-2">
                    <h3 class="mature-card-title m-0">
                        <i class="fa-solid fa-tower-broadcast text-teal" style="color: #0d9488;"></i> 
                        <?= ($effective_scope === 'today') ? "Today's Live Gate Activity Log" : "Recent Gate Movements & Activity Stream" ?>
                    </h3>
                    <span class="mature-badge <?= ($effective_scope === 'today') ? 'mature-badge-emerald' : 'mature-badge-primary' ?>">
                        <i class="fa-solid fa-circle me-1" style="font-size: 0.5rem;"></i>
                        <?= ($effective_scope === 'today') ? ($today_activity_count . " Processed Today (" . date('d M Y') . ")") : "Live Gate Logbook" ?>
                    </span>
                </div>
                <p class="text-secondary small mb-0 mt-1">Live real-time stream of all arrivals, departures, duty shifts, and gate clearances recorded for the day.</p>
            </div>

            <div class="d-flex align-items-center gap-2 flex-wrap">
                <!-- Scope Switcher (Today vs Recent) -->
                <div class="btn-group btn-group-sm" role="group">
                    <a href="security?tab=gate_manager&today_scope=today" class="btn btn-sm <?= ($effective_scope === 'today') ? 'btn-dark' : 'btn-outline-secondary' ?>">
                        <i class="fa-solid fa-calendar-day me-1"></i> Today Only (<?= $today_activity_count ?>)
                    </a>
                    <a href="security?tab=gate_manager&today_scope=recent" class="btn btn-sm <?= ($effective_scope === 'recent') ? 'btn-dark' : 'btn-outline-secondary' ?>">
                        <i class="fa-solid fa-clock-rotate-left me-1"></i> All Recent Logs
                    </a>
                </div>

                <!-- Prominent All Logs Archive Button -->
                <a href="security?tab=audit_logs" class="btn btn-sm text-white px-3 shadow-sm rounded-pill fw-semibold" style="background: #2563eb;">
                    <i class="fa-solid fa-database me-1"></i> Full Gate Logs Archive &rarr;
                </a>
            </div>
        </div>

        <!-- Quick Live Filter Bar for the Day -->
        <div class="p-3 bg-light border-bottom border-top">
            <form method="GET" action="security" class="row g-2 align-items-center">
                <input type="hidden" name="tab" value="gate_manager">
                <input type="hidden" name="today_scope" value="<?= htmlspecialchars($effective_scope) ?>">
                
                <div class="col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                        <input type="text" name="today_q" class="form-control" placeholder="Search Passcode, Name, Vehicle Plate..." value="<?= htmlspecialchars($today_search) ?>">
                    </div>
                </div>

                <div class="col-md-3">
                    <select name="today_gate" class="form-select form-select-sm">
                        <option value="">All Gate Posts</option>
                        <option value="Main Gate" <?= ($today_filter_gate === 'Main Gate') ? 'selected' : '' ?>>Main Gate</option>
                        <option value="North Gate" <?= ($today_filter_gate === 'North Gate') ? 'selected' : '' ?>>North Gate</option>
                        <option value="South Gate" <?= ($today_filter_gate === 'South Gate') ? 'selected' : '' ?>>South Gate</option>
                        <option value="Pedestrian Gate" <?= ($today_filter_gate === 'Pedestrian Gate') ? 'selected' : '' ?>>Pedestrian Gate</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <select name="today_shift" class="form-select form-select-sm">
                        <option value="">All Guard Shifts</option>
                        <?php if ($shifts_list_admin): ?>
                            <?php $shifts_list_admin->data_seek(0); ?>
                            <?php while ($sh = $shifts_list_admin->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($sh['name']) ?>" <?= ($today_filter_shift === $sh['name']) ? 'selected' : '' ?>><?= htmlspecialchars($sh['name']) ?></option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-primary w-100 fw-semibold"><i class="fa-solid fa-filter me-1"></i> Filter</button>
                    <a href="security?tab=gate_manager&today_scope=<?= htmlspecialchars($effective_scope) ?>" class="btn btn-sm btn-outline-secondary" title="Reset Filters"><i class="fa-solid fa-rotate-left"></i></a>
                </div>
            </form>
        </div>

        <!-- Live Stream Table -->
        <div class="mature-card-body p-0">
            <div class="table-responsive">
                <table class="table dashboard-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">Passcode &amp; Vehicle</th>
                            <th>Visitor Details</th>
                            <th>Destination Host</th>
                            <th>Entry Radar &amp; Duty Shift</th>
                            <th>Exit Clearance &amp; Shift</th>
                            <th>Stay Duration</th>
                            <th>Status</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($today_stream_res && $today_stream_res->num_rows > 0): ?>
                            <?php while ($tv = $today_stream_res->fetch_assoc()): ?>
                                <?php 
                                    $t_duration = formatDuration($tv['duration_minutes']);
                                    $t_st = $tv['status'];
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="mature-badge mature-badge-slate font-monospace fw-bold" style="font-size: 0.75rem;"><?= htmlspecialchars($tv['visitor_code'] ?? 'N/A') ?></div>
                                        <?php if (!empty($tv['vehicle_plate'])): ?>
                                            <div class="mature-badge mature-badge-primary font-monospace mt-1" style="font-size: 0.68rem;">
                                                <i class="fa-solid fa-car me-1"></i><?= htmlspecialchars($tv['vehicle_plate']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-slate-900"><?= htmlspecialchars($tv['name']) ?></div>
                                        <div class="small text-secondary"><i class="fa-solid fa-phone me-1 text-success"></i><?= htmlspecialchars($tv['phone'] ?? 'No Phone') ?></div>
                                        <?php if (!empty($tv['guard_notes'])): ?>
                                            <div class="small text-muted fst-italic mt-0.5"><i class="fa-solid fa-note-sticky text-warning me-1"></i><?= htmlspecialchars($tv['guard_notes']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-slate-900"><?= htmlspecialchars($tv['resident_name'] ?? 'N/A') ?></div>
                                        <div class="small text-secondary">Unit <?= htmlspecialchars($tv['flat_number'] ?? '-') ?> (<?= htmlspecialchars($tv['building_name'] ?? 'Main') ?>)</div>
                                        <div class="small text-muted mt-0.5"><i class="fa-regular fa-compass me-1"></i><?= htmlspecialchars($tv['purpose'] ?? 'Visit') ?></div>
                                    </td>
                                    <td>
                                        <?php if (!empty($tv['entry_time'])): ?>
                                            <div class="fw-bold text-slate-800"><?= date('h:i A', strtotime($tv['entry_time'])) ?></div>
                                            <div class="small text-secondary"><i class="fa-solid fa-door-open me-1 text-teal"></i><?= htmlspecialchars($tv['entry_gate'] ?? 'Main Gate') ?></div>
                                            <div class="small text-primary mt-0.5" style="font-size:0.72rem; font-weight:600;">
                                                <i class="fa-solid fa-user-shield me-1"></i><?= htmlspecialchars($tv['entry_staff_name'] ?? 'Gate Guard') ?>
                                            </div>
                                            <?php if (!empty($tv['entry_shift_name'])): ?>
                                                <div class="small text-muted" style="font-size:0.68rem;">
                                                    <i class="fa-solid fa-shield text-teal me-1"></i><?= htmlspecialchars($tv['entry_shift_name']) ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">Pending Entry</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($tv['exit_time'])): ?>
                                            <div class="fw-bold text-slate-800"><?= date('h:i A', strtotime($tv['exit_time'])) ?></div>
                                            <div class="small text-secondary"><i class="fa-solid fa-door-closed me-1 text-danger"></i><?= htmlspecialchars($tv['exit_gate'] ?? 'Main Gate') ?></div>
                                            <div class="small text-danger mt-0.5" style="font-size:0.72rem; font-weight:600;">
                                                <i class="fa-solid fa-user-shield me-1"></i><?= htmlspecialchars($tv['exit_staff_name'] ?? 'Gate Guard') ?>
                                            </div>
                                            <?php if (!empty($tv['exit_shift_name'])): ?>
                                                <div class="small text-muted" style="font-size:0.68rem;">
                                                    <i class="fa-solid fa-shield me-1"></i><?= htmlspecialchars($tv['exit_shift_name']) ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif ($t_st === 'entered'): ?>
                                            <span class="mature-badge mature-badge-sky"><i class="fa-solid fa-circle me-1" style="font-size: 0.5rem;"></i>Currently Inside</span>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold text-slate-800">
                                        <?= $t_duration ?>
                                    </td>
                                    <td>
                                        <?php if ($t_st == 'checked_out'): ?>
                                            <span class="mature-badge mature-badge-emerald">Checked Out</span>
                                        <?php elseif ($t_st == 'exited_without_confirmation'): ?>
                                            <span class="mature-badge mature-badge-amber" title="Reason: <?= htmlspecialchars($tv['exit_reason'] ?? 'None') ?>">Exited Unconfirmed</span>
                                        <?php elseif ($t_st == 'confirmed'): ?>
                                            <span class="mature-badge mature-badge-primary">Confirmed</span>
                                        <?php elseif ($t_st == 'entered'): ?>
                                            <span class="mature-badge mature-badge-sky">Inside</span>
                                        <?php else: ?>
                                            <span class="mature-badge mature-badge-slate"><?= ucfirst($t_st) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4 text-nowrap">
                                        <a href="../gate_pass?id=<?= $tv['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm me-1" title="Print Gate Pass">
                                            <i class="fa-solid fa-print"></i>
                                        </a>
                                        <button type="button" class="btn btn-light btn-sm rounded-circle" onclick="viewAuditCard(<?= htmlspecialchars(json_encode([
                                            'id' => $tv['id'],
                                            'code' => $tv['visitor_code'],
                                            'name' => $tv['name'],
                                            'phone' => $tv['phone'],
                                            'resident' => $tv['resident_name'],
                                            'flat' => $tv['flat_number'],
                                            'purpose' => $tv['purpose'],
                                            'entry' => $tv['entry_time'] ? date('d M Y, h:i A', strtotime($tv['entry_time'])) : 'N/A',
                                            'entry_gate' => $tv['entry_gate'],
                                            'entry_staff' => $tv['entry_staff_name'] ?? 'Security Staff',
                                            'entry_shift' => $tv['entry_shift_name'] ?? 'General Shift',
                                            'vehicle_plate' => $tv['vehicle_plate'] ?? '',
                                            'confirmed' => $tv['resident_confirmed_at'] ? date('d M Y, h:i A', strtotime($tv['resident_confirmed_at'])) : 'No Confirmation',
                                            'exit' => $tv['exit_time'] ? date('d M Y, h:i A', strtotime($tv['exit_time'])) : 'Inside Estate',
                                            'exit_gate' => $tv['exit_gate'],
                                            'exit_staff' => $tv['exit_staff_name'] ?? 'Security Staff',
                                            'exit_shift' => $tv['exit_shift_name'] ?? 'General Shift',
                                            'duration' => $t_duration,
                                            'reason' => $tv['exit_reason'] ?? 'N/A',
                                            'status' => strtoupper(str_replace('_', ' ', $tv['status']))
                                        ])) ?>)" title="View Detailed Audit Pass">
                                            <i class="fa-solid fa-eye text-primary"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-secondary">
                                    <i class="fa-solid fa-clock-rotate-left fs-2 text-muted mb-2 d-block"></i>
                                    <?php if ($effective_scope === 'today'): ?>
                                        No gate movements recorded yet today (<?= date('d M Y') ?>). 
                                        <div class="mt-2">
                                            <a href="security?tab=gate_manager&today_scope=recent" class="btn btn-sm btn-outline-primary me-2">
                                                <i class="fa-solid fa-history me-1"></i> View Previous Recorded Logs
                                            </a>
                                            <a href="security?tab=audit_logs" class="btn btn-sm btn-primary">
                                                <i class="fa-solid fa-database me-1"></i> All-Time Archive
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        No visitor log records found matching your filter criteria.
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="p-3 bg-light border-top d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="small text-muted">
                <i class="fa-solid fa-shield-halved text-teal me-1"></i> Logs are linked to the active guard on duty, duty shift, and timestamped in the estate audit trail.
            </div>
            <a href="security?tab=audit_logs" class="btn btn-sm btn-outline-primary fw-semibold rounded-pill">
                <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Open Full Detailed Historical Archive &rarr;
            </a>
        </div>
    </div>

<?php elseif ($active_tab == 'vehicles'): ?>
    <!-- REGISTERED VEHICLES & CAR STICKERS TAB -->

    <!-- Vehicle Search & Scanner Banner -->
    <div class="mature-card p-4 mb-4" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: white; border: 1px solid #334155;">
        <div class="row align-items-center">
            <div class="col-md-7">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="mature-badge mature-badge-amber">
                        <i class="fa-solid fa-car-side me-1"></i> Vehicle &amp; Sticker Control
                    </span>
                    <span class="text-slate-400 small">&bull; Real-Time Vehicle Clearance &amp; Stickers</span>
                </div>
                <h4 class="fw-bold mb-1 text-white" style="letter-spacing: -0.02em;">Resident Vehicle &amp; Car Sticker Lookup</h4>
                <p class="mb-0 text-slate-300 small">Enter vehicle license plate (e.g. <strong>KJA-849-XA</strong>) or Car Sticker ID (e.g. <strong>VEH-00001</strong>) to view complete owner, flat, and vehicle records.</p>
            </div>
            <div class="col-md-5 mt-3 mt-md-0">
                <form method="GET" action="security" class="d-flex gap-2">
                    <input type="hidden" name="tab" value="vehicles">
                    <input type="text" name="search_vehicle" class="form-control border-0 shadow-none font-monospace fw-bold text-uppercase" placeholder="ENTER PLATE OR CAR ID" value="<?= htmlspecialchars($searched_vehicle_q) ?>" style="letter-spacing: 2px; background: rgba(255,255,255,0.95); color: #0f172a;">
                    <button type="submit" class="btn text-dark px-4 fw-bold" style="background: #f59e0b;">
                        <i class="fa-solid fa-magnifying-glass me-1"></i> Search
                    </button>
                    <?php if (!empty($searched_vehicle_q)): ?>
                        <a href="security?tab=vehicles" class="btn btn-outline-light">Clear</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <?php if (!empty($searched_vehicle_q)): ?>
        <?php if ($vehicle_dossier): ?>
            <!-- VEHICLE VERIFICATION DOSSIER CARD -->
            <div class="mature-card p-4 mb-4" style="border-left: 4px solid #f59e0b !important;">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="d-flex align-items-center gap-2">
                        <div style="background: #eff6ff; border: 2px solid #3b82f6; color: #1e40af; font-family: 'Chakra Petch', sans-serif, monospace; font-size: 1.3rem; font-weight: 800; letter-spacing: 2px; padding: 2px 14px; border-radius: 6px;">
                            <i class="fa-solid fa-car me-1 text-primary"></i> <?= htmlspecialchars($vehicle_dossier['reg_number']) ?>
                        </div>
                        <span class="mature-badge mature-badge-slate font-monospace fw-bold fs-6">
                            <?= htmlspecialchars($vehicle_dossier['custom_id']) ?>
                        </span>
                        <span class="mature-badge mature-badge-emerald">
                            <i class="fa-solid fa-circle-check me-1"></i> Verified Resident Vehicle
                        </span>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="../car_sticker.php?id=<?= $vehicle_dossier['id'] ?>" target="_blank" class="btn btn-sm btn-warning text-dark fw-bold rounded-pill px-3 shadow-sm">
                            <i class="fa-solid fa-id-card me-1"></i> View / Print Sticker
                        </a>
                        <a href="security?tab=vehicles" class="btn btn-sm btn-outline-secondary">Clear</a>
                    </div>
                </div>

                <div class="row g-4 pt-2">
                    <div class="col-md-4 border-end">
                        <div class="text-secondary small text-uppercase fw-semibold" style="font-size: 0.72rem;">Vehicle Profile</div>
                        <div class="h5 fw-bold text-slate-900 mb-1 mt-1"><?= htmlspecialchars($vehicle_dossier['model'] ?? 'Standard Vehicle') ?></div>
                        <div class="small text-secondary mb-1">
                            Type: <strong><?= ucfirst(htmlspecialchars($vehicle_dossier['type'] ?? 'car')) ?></strong> &bull; Color: <strong><?= htmlspecialchars($vehicle_dossier['color'] ?? 'Unspecified') ?></strong>
                        </div>
                        <div class="small text-muted">Registered: <?= !empty($vehicle_dossier['registration_date']) ? date('M d, Y', strtotime($vehicle_dossier['registration_date'])) : 'N/A' ?></div>
                        <?php if (!empty($vehicle_dossier['particulars_path'])): ?>
                            <div class="mt-2">
                                <a href="<?= htmlspecialchars($vehicle_dossier['particulars_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2 rounded-pill small">
                                    <i class="fa-solid fa-file-lines me-1"></i> View Particulars
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-4 border-end">
                        <div class="text-secondary small text-uppercase fw-semibold" style="font-size: 0.72rem;">Registered Owner</div>
                        <div class="h5 fw-bold text-slate-900 mb-1 mt-1"><?= htmlspecialchars($vehicle_dossier['owner']['name'] ?? 'N/A') ?></div>
                        <div class="small text-secondary mb-1"><i class="fa-solid fa-id-card me-1 text-primary"></i> <?= htmlspecialchars($vehicle_dossier['owner']['custom_id'] ?? 'N/A') ?></div>
                        <div class="small text-secondary">
                            <i class="fa-solid fa-phone me-1 text-success"></i> <?= htmlspecialchars($vehicle_dossier['owner']['phone'] ?? 'No Phone') ?>
                            <?php if (!empty($vehicle_dossier['owner']['phone'])): ?>
                                <a href="tel:<?= htmlspecialchars($vehicle_dossier['owner']['phone']) ?>" class="ms-1 text-primary text-decoration-none fw-bold"><i class="fa-solid fa-phone-volume"></i> Call</a>
                            <?php endif; ?>
                        </div>
                        <div class="small text-muted text-truncate"><i class="fa-solid fa-envelope me-1 text-muted"></i> <?= htmlspecialchars($vehicle_dossier['owner']['email'] ?? '') ?></div>
                    </div>

                    <div class="col-md-4">
                        <div class="text-secondary small text-uppercase fw-semibold" style="font-size: 0.72rem;">Residence Unit</div>
                        <div class="h5 fw-bold text-slate-900 mb-1 mt-1">Unit <?= htmlspecialchars($vehicle_dossier['flat_number'] ?? 'N/A') ?></div>
                        <div class="small text-secondary"><?= htmlspecialchars($vehicle_dossier['building_name'] ?? 'Main Block') ?> (Floor <?= htmlspecialchars($vehicle_dossier['floor'] ?? '1') ?>)</div>
                        <div class="small text-secondary"><?= htmlspecialchars($vehicle_dossier['street_name'] ?? 'Street') ?> &bull; <strong><?= htmlspecialchars($vehicle_dossier['zone_name'] ?? 'Zone') ?></strong></div>
                    </div>
                </div>

                <!-- Gate Movement Action Form -->
                <div class="mt-4 pt-3 border-top">
                    <form method="POST" action="security?tab=vehicles" class="row g-2 align-items-center">
                        <input type="hidden" name="action_type" value="process_vehicle_movement">
                        <input type="hidden" name="vehicle_id" value="<?= $vehicle_dossier['id'] ?>">
                        <input type="hidden" name="reg_number" value="<?= htmlspecialchars($vehicle_dossier['reg_number']) ?>">

                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-slate-700 m-0">Gate Post</label>
                            <input type="text" name="entry_gate" class="form-control form-control-sm" value="Main Gate">
                        </div>

                        <div class="col-md-5">
                            <label class="form-label small fw-bold text-slate-700 m-0">Observations / Notes (Optional)</label>
                            <input type="text" name="guard_notes" class="form-control form-control-sm" placeholder="e.g. Officer verification, trunk cleared">
                        </div>

                        <div class="col-md-4 text-md-end pt-3">
                            <button type="submit" name="direction" value="entry" class="btn btn-sm btn-success fw-bold px-3 rounded-pill shadow-sm">
                                <i class="fa-solid fa-right-to-bracket me-1"></i> Log Entry (IN)
                            </button>
                            <button type="submit" name="direction" value="exit" class="btn btn-sm btn-danger fw-bold px-3 rounded-pill shadow-sm ms-1">
                                <i class="fa-solid fa-right-from-bracket me-1"></i> Log Exit (OUT)
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning p-3 mb-4 rounded-3">
                <i class="fa-solid fa-triangle-exclamation me-2"></i> No vehicle record found matching "<strong><?= htmlspecialchars($searched_vehicle_q) ?></strong>".
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- VEHICLES KPI CARDS -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-4">
            <div class="kpi-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="kpi-title">Total Registered Vehicles</span>
                        <div class="kpi-value"><?= number_format($estate_vehicles_count) ?></div>
                    </div>
                    <div class="kpi-icon-wrap" style="background: rgba(245, 158, 11, 0.15); color: #d97706;">
                        <i class="fa-solid fa-car-side"></i>
                    </div>
                </div>
                <div class="kpi-meta justify-content-between mt-2">
                    <span>Active Estate Fleet</span>
                    <span class="mature-badge mature-badge-amber">Resident Vehicles</span>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-4">
            <div class="kpi-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="kpi-title">Active Stickers Issued</span>
                        <div class="kpi-value"><?= number_format($estate_vehicles_count) ?></div>
                    </div>
                    <div class="kpi-icon-wrap" style="background: rgba(16, 185, 129, 0.15); color: #059669;">
                        <i class="fa-solid fa-id-card"></i>
                    </div>
                </div>
                <div class="kpi-meta justify-content-between mt-2">
                    <span>Authorized Clearance</span>
                    <span class="mature-badge mature-badge-emerald">100% Active</span>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-4">
            <div class="kpi-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="kpi-title">Gate Clearances Stream</span>
                        <div class="kpi-value"><?= $vehicle_logs_admin ? $vehicle_logs_admin->num_rows : 0 ?></div>
                    </div>
                    <div class="kpi-icon-wrap" style="background: rgba(37, 99, 235, 0.15); color: #2563eb;">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                </div>
                <div class="kpi-meta justify-content-between mt-2">
                    <span>Total Activity Logs</span>
                    <span class="mature-badge mature-badge-primary">Gate Records</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ALL REGISTERED VEHICLES TABLE -->
    <div class="mature-card mb-4">
        <div class="mature-card-header d-flex justify-content-between align-items-center">
            <div>
                <h3 class="mature-card-title">
                    <i class="fa-solid fa-car text-warning me-2"></i> All Registered Vehicles &amp; Stickers
                </h3>
                <p class="text-secondary small mb-0">Complete list of registered vehicles, owners, allocated flats, and official car stickers.</p>
            </div>
            <span class="mature-badge mature-badge-slate">Total: <?= $all_vehicles_res ? $all_vehicles_res->num_rows : 0 ?></span>
        </div>
        <div class="mature-card-body p-0">
            <div class="table-responsive">
                <table class="table dashboard-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-4">Car ID &amp; Plate</th>
                            <th>Vehicle Make &amp; Model</th>
                            <th>Color &amp; Type</th>
                            <th>Allocated Residence</th>
                            <th>Registered Owner</th>
                            <th>Sticker Status</th>
                            <th class="text-end pe-4">Sticker Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($all_vehicles_res && $all_vehicles_res->num_rows > 0): ?>
                            <?php while ($v = $all_vehicles_res->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="mature-badge mature-badge-primary font-monospace fw-bold" style="font-size: 0.85rem; letter-spacing: 1px;">
                                            <?= htmlspecialchars($v['reg_number']) ?>
                                        </div>
                                        <div class="small font-monospace text-muted mt-0.5"><?= htmlspecialchars($v['custom_id'] ?? 'VEH-00000') ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-slate-900"><?= htmlspecialchars($v['model'] ?? 'Standard Vehicle') ?></div>
                                        <div class="small text-muted">Reg: <?= !empty($v['registration_date']) ? date('M d, Y', strtotime($v['registration_date'])) : '—' ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-slate-800"><?= htmlspecialchars($v['color'] ?? 'Unspecified') ?></div>
                                        <span class="mature-badge mature-badge-slate" style="font-size: 0.72rem;"><?= ucfirst(htmlspecialchars($v['type'] ?? 'car')) ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-slate-900">Unit <?= htmlspecialchars($v['flat_number'] ?? 'N/A') ?></div>
                                        <div class="small text-secondary"><?= htmlspecialchars($v['building_name'] ?? '') ?> &bull; <?= htmlspecialchars($v['street_name'] ?? '') ?></div>
                                        <div class="small text-muted"><?= htmlspecialchars($v['zone_name'] ?? '') ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-slate-900"><?= htmlspecialchars($v['owner_name'] ?? 'N/A') ?></div>
                                        <div class="small text-secondary"><i class="fa-solid fa-phone text-success me-1"></i><?= htmlspecialchars($v['owner_phone'] ?? 'No phone') ?></div>
                                    </td>
                                    <td>
                                        <span class="mature-badge mature-badge-emerald">
                                            <i class="fa-solid fa-circle-check me-1"></i> Active
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="d-inline-flex gap-1">
                                            <a href="../car_sticker.php?id=<?= $v['id'] ?>" target="_blank" class="btn btn-sm btn-outline-warning text-dark fw-bold rounded-pill px-3 shadow-sm" title="View &amp; Print Sticker">
                                                <i class="fa-solid fa-id-card me-1"></i> Sticker
                                            </a>
                                            <a href="security?tab=vehicles&search_vehicle=<?= urlencode($v['reg_number']) ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3" title="Clearance Dossier">
                                                <i class="fa-solid fa-shield-check me-1"></i> Dossier
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-car-side fs-2 mb-2 d-block text-secondary"></i>
                                    No registered vehicles found in the estate yet.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- VEHICLE GATE ACTIVITY HISTORY -->
    <?php if ($vehicle_logs_admin && $vehicle_logs_admin->num_rows > 0): ?>
        <div class="mature-card mb-4">
            <div class="mature-card-header d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="mature-card-title">
                        <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i> Recent Vehicle Gate Clearances Log
                    </h3>
                    <p class="text-secondary small mb-0">Chronological history of registered vehicle arrivals and departures.</p>
                </div>
            </div>
            <div class="mature-card-body p-0">
                <div class="table-responsive">
                    <table class="table dashboard-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">Movement</th>
                                <th>Vehicle Plate &amp; ID</th>
                                <th>Model &amp; Flat</th>
                                <th>Gate Post</th>
                                <th>Duty Officer</th>
                                <th>Timestamp</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($vlog = $vehicle_logs_admin->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4">
                                        <?php if ($vlog['direction'] === 'entry'): ?>
                                            <span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-arrow-down me-1"></i> ENTRY</span>
                                        <?php else: ?>
                                            <span class="mature-badge mature-badge-amber"><i class="fa-solid fa-arrow-up me-1"></i> EXIT</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold font-monospace text-primary"><?= htmlspecialchars($vlog['reg_number']) ?></div>
                                        <div class="small font-monospace text-muted"><?= htmlspecialchars($vlog['custom_id'] ?? '') ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-slate-800"><?= htmlspecialchars($vlog['model'] ?? 'Vehicle') ?></div>
                                        <div class="small text-secondary">Unit <?= htmlspecialchars($vlog['flat_number'] ?? 'N/A') ?></div>
                                    </td>
                                    <td><strong><?= htmlspecialchars($vlog['gate_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($vlog['officer_name'] ?? 'Guard') ?></td>
                                    <td><?= date('M d, Y h:i A', strtotime($vlog['logged_at'])) ?></td>
                                    <td><?= htmlspecialchars($vlog['notes'] ?: '—') ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php elseif ($active_tab == 'audit_logs'): ?>
    <!-- FULL AUDIT LOGS & ANALYTICS TAB -->
    
    <!-- Filter Controls Bar -->
    <div class="futuristic-filter-bar mb-4">
        <form method="GET" action="security" class="row g-3 align-items-end">
            <input type="hidden" name="tab" value="audit_logs">
            
            <div class="col-md-3">
                <label class="form-label small fw-semibold text-secondary mb-1">Resident Host</label>
                <select name="filter_resident" class="form-select form-select-sm">
                    <option value="0">All Residents</option>
                    <?php if ($residents_list): ?>
                        <?php while ($r = $residents_list->fetch_assoc()): ?>
                            <option value="<?= $r['id'] ?>" <?= ($filter_resident == $r['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($r['name']) ?> (Unit <?= htmlspecialchars($r['flat_number'] ?? '-') ?>)
                            </option>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-semibold text-secondary mb-1">Visit Status</label>
                <select name="filter_status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="checked_out" <?= ($filter_status == 'checked_out') ? 'selected' : '' ?>>Checked Out</option>
                    <option value="exited_without_confirmation" <?= ($filter_status == 'exited_without_confirmation') ? 'selected' : '' ?>>Exited Unconfirmed</option>
                    <option value="confirmed" <?= ($filter_status == 'confirmed') ? 'selected' : '' ?>>Confirmed (Inside)</option>
                    <option value="entered" <?= ($filter_status == 'entered') ? 'selected' : '' ?>>Entered (Pending Conf.)</option>
                    <option value="pre_registered" <?= ($filter_status == 'pre_registered') ? 'selected' : '' ?>>Pre-Registered</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-semibold text-secondary mb-1">Gate Portal</label>
                <select name="filter_gate" class="form-select form-select-sm">
                    <option value="">All Gates</option>
                    <option value="Main Gate" <?= ($filter_gate == 'Main Gate') ? 'selected' : '' ?>>Main Gate</option>
                    <option value="North Gate" <?= ($filter_gate == 'North Gate') ? 'selected' : '' ?>>North Gate</option>
                    <option value="South Gate" <?= ($filter_gate == 'South Gate') ? 'selected' : '' ?>>South Gate</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-semibold text-secondary mb-1">Duty Shift</label>
                <select name="filter_shift" class="form-select form-select-sm">
                    <option value="">All Shifts</option>
                    <?php if ($shifts_list_admin): ?>
                        <?php while ($sh = $shifts_list_admin->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($sh['name']) ?>" <?= ($filter_shift == $sh['name']) ? 'selected' : '' ?>><?= htmlspecialchars($sh['name']) ?></option>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-semibold text-secondary mb-1">Gate Officer</label>
                <select name="filter_staff" class="form-select form-select-sm">
                    <option value="0">All Staff</option>
                    <?php if ($staff_list): ?>
                        <?php while ($s = $staff_list->fetch_assoc()): ?>
                            <option value="<?= $s['id'] ?>" <?= ($filter_staff == $s['id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small fw-semibold text-secondary mb-1">Date</label>
                <input type="date" name="filter_date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date_from) ?>">
            </div>

            <div class="col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm w-100 fw-semibold" title="Apply Filter"><i class="fa-solid fa-filter"></i></button>
                <a href="security?tab=audit_logs" class="btn btn-outline-secondary btn-sm" title="Reset Filters"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </form>
    </div>

    <!-- Audit Log Table -->
    <div class="mature-card mb-4">
        <div class="mature-card-header">
            <div>
                <h3 class="mature-card-title">
                    <i class="fa-solid fa-clipboard-list text-primary"></i> Comprehensive Visitor Audit Trail
                </h3>
                <p class="text-secondary small mb-0">Immutable record of perimeter entries, departures, and security staff clearance</p>
            </div>
            <span class="mature-badge mature-badge-slate">
                <?= $audit_res ? $audit_res->num_rows : 0 ?> Records Logged
            </span>
        </div>
        <div class="mature-card-body p-0">
            <div class="table-responsive">
                <table class="table dashboard-table align-middle">
                    <thead>
                        <tr>
                            <th class="ps-4">Visitor &amp; Code</th>
                            <th>Host / Unit</th>
                            <th>Entry Radar</th>
                            <th>Host Confirmation</th>
                            <th>Exit Clearance</th>
                            <th>Stay Duration</th>
                            <th>Status</th>
                            <th class="text-end pe-4">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($audit_res && $audit_res->num_rows > 0): ?>
                            <?php while ($av = $audit_res->fetch_assoc()): ?>
                                <?php 
                                    $duration_txt = formatDuration($av['duration_minutes']);
                                    $st = $av['status'];
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold text-slate-900"><?= htmlspecialchars($av['name']) ?></div>
                                        <div class="mature-badge mature-badge-slate font-monospace" style="font-size: 0.72rem;"><?= htmlspecialchars($av['visitor_code'] ?? 'N/A') ?></div>
                                        <div class="small text-secondary mt-0.5"><?= htmlspecialchars($av['phone'] ?? '-') ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-slate-900"><?= htmlspecialchars($av['resident_name'] ?? 'N/A') ?></div>
                                        <div class="small text-secondary">Unit <?= htmlspecialchars($av['flat_number'] ?? '-') ?> (<?= htmlspecialchars($av['building_name'] ?? 'Main') ?>)</div>
                                    </td>
                                    <td>
                                        <?php if ($av['entry_time']): ?>
                                            <div class="fw-bold text-slate-800"><?= date('d M, h:i A', strtotime($av['entry_time'])) ?></div>
                                            <div class="small text-secondary"><i class="fa-solid fa-torii-gate me-1 text-primary"></i><?= htmlspecialchars($av['entry_gate'] ?? 'Main Gate') ?></div>
                                            <div class="small text-primary mt-0.5" style="font-size:0.72rem; font-weight:600;">
                                                <i class="fa-solid fa-user-shield me-1"></i>In: <?= htmlspecialchars($av['entry_staff_name'] ?? 'Gate Guard') ?>
                                            </div>
                                            <?php if (!empty($av['entry_shift_name'])): ?>
                                                <div class="small text-muted" style="font-size:0.68rem;">
                                                    <i class="fa-solid fa-shield text-teal me-1"></i><?= htmlspecialchars($av['entry_shift_name']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($av['vehicle_plate'])): ?>
                                                <div class="mature-badge mature-badge-slate font-monospace mt-1" style="font-size:0.68rem;">
                                                    <i class="fa-solid fa-car me-1"></i><?= htmlspecialchars($av['vehicle_plate']) ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($av['resident_confirmed_at']): ?>
                                            <span class="mature-badge mature-badge-emerald">
                                                <i class="fa-solid fa-check-circle me-1"></i> <?= date('d M, h:i A', strtotime($av['resident_confirmed_at'])) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="mature-badge mature-badge-slate">
                                                <?= !$require_resident_confirmation ? 'Not Required' : 'No Confirmation' ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($av['exit_time']): ?>
                                            <div class="fw-bold text-slate-800"><?= date('d M, h:i A', strtotime($av['exit_time'])) ?></div>
                                            <div class="small text-secondary"><i class="fa-solid fa-torii-gate me-1 text-danger"></i><?= htmlspecialchars($av['exit_gate'] ?? 'Main Gate') ?></div>
                                            <div class="small text-danger mt-0.5" style="font-size:0.72rem; font-weight:600;">
                                                <i class="fa-solid fa-user-shield me-1"></i>Out: <?= htmlspecialchars($av['exit_staff_name'] ?? 'Gate Guard') ?>
                                            </div>
                                            <?php if (!empty($av['exit_shift_name'])): ?>
                                                <div class="small text-muted" style="font-size:0.68rem;">
                                                    <i class="fa-solid fa-shield me-1"></i><?= htmlspecialchars($av['exit_shift_name']) ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="mature-badge mature-badge-sky"><i class="fa-solid fa-circle me-1" style="font-size: 0.5rem;"></i>Inside Estate</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold text-slate-800">
                                        <?= $duration_txt ?>
                                    </td>
                                    <td>
                                        <?php if ($st == 'checked_out'): ?>
                                            <span class="mature-badge mature-badge-emerald">Checked Out</span>
                                        <?php elseif ($st == 'exited_without_confirmation'): ?>
                                            <span class="mature-badge mature-badge-amber" title="Reason: <?= htmlspecialchars($av['exit_reason'] ?? 'None') ?>">Exited Unconfirmed</span>
                                        <?php elseif ($st == 'confirmed'): ?>
                                            <span class="mature-badge mature-badge-primary">Confirmed</span>
                                        <?php elseif ($st == 'entered'): ?>
                                            <span class="mature-badge mature-badge-sky">Inside</span>
                                        <?php else: ?>
                                            <span class="mature-badge mature-badge-slate"><?= ucfirst($st) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4 text-nowrap">
                                        <a href="../gate_pass?id=<?= $av['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm me-1" title="Print Gate Pass">
                                            <i class="fa-solid fa-print"></i>
                                        </a>
                                    <button class="btn btn-light btn-sm rounded-circle" onclick="viewAuditCard(<?= htmlspecialchars(json_encode([
                                        'id' => $av['id'],
                                        'code' => $av['visitor_code'],
                                        'name' => $av['name'],
                                        'phone' => $av['phone'],
                                        'resident' => $av['resident_name'],
                                        'flat' => $av['flat_number'],
                                        'purpose' => $av['purpose'],
                                        'entry' => $av['entry_time'] ? date('d M Y, h:i A', strtotime($av['entry_time'])) : 'N/A',
                                        'entry_gate' => $av['entry_gate'],
                                        'entry_staff' => $av['entry_staff_name'] ?? 'Security Staff',
                                        'entry_shift' => $av['entry_shift_name'] ?? 'General Shift',
                                        'vehicle_plate' => $av['vehicle_plate'] ?? '',
                                        'confirmed' => $av['resident_confirmed_at'] ? date('d M Y, h:i A', strtotime($av['resident_confirmed_at'])) : 'No Confirmation',
                                        'exit' => $av['exit_time'] ? date('d M Y, h:i A', strtotime($av['exit_time'])) : 'Inside Estate',
                                        'exit_gate' => $av['exit_gate'],
                                        'exit_staff' => $av['exit_staff_name'] ?? 'Security Staff',
                                        'exit_shift' => $av['exit_shift_name'] ?? 'General Shift',
                                        'duration' => $duration_txt,
                                        'reason' => $av['exit_reason'] ?? 'N/A',
                                        'status' => strtoupper(str_replace('_', ' ', $av['status']))
                                    ])) ?>)">
                                        <i class="fa-solid fa-eye text-primary"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">No visitor audit logs match the current filters.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

</div>

<!-- Walk-in Visitor Registration Modal -->
<div class="modal fade" id="walkinModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <form action="security" method="POST">
                <input type="hidden" name="action_type" value="walkin_entry">
                <div class="modal-header border-bottom">
                    <h5 class="modal-title font-outfit font-weight-700"><i class="fa-solid fa-user-plus text-primary me-2"></i> Register Walk-in Visitor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label font-weight-600 small">First Name</label>
                            <input type="text" name="first_name" class="form-control" required placeholder="e.g. John">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label font-weight-600 small">Last Name</label>
                            <input type="text" name="last_name" class="form-control" required placeholder="e.g. Doe">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label font-weight-600 small">Phone Number</label>
                            <input type="text" name="phone" class="form-control" placeholder="080XXXXXXXX">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label font-weight-600 small">Vehicle Reg (Optional)</label>
                            <input type="text" name="vehicle_plate" class="form-control font-monospace text-uppercase" placeholder="e.g. KJA-849-XA">
                        </div>
                        <div class="col-12">
                            <label class="form-label font-weight-600 small">Host Resident</label>
                            <select name="resident_id" class="form-select" required>
                                <option value="">Select Resident Host</option>
                                <?php 
                                    $res_modal = $conn->query("SELECT u.id, u.name, f.number as flat_number FROM users u JOIN residents r ON u.id = r.user_id LEFT JOIN flats f ON r.flat_id = f.id WHERE u.estate_id = $estate_id GROUP BY u.id ORDER BY u.name ASC");
                                    if ($res_modal):
                                        while ($rm = $res_modal->fetch_assoc()):
                                ?>
                                    <option value="<?= $rm['id'] ?>"><?= htmlspecialchars($rm['name']) ?> (Unit <?= htmlspecialchars($rm['flat_number'] ?? '-') ?>)</option>
                                <?php endwhile; endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label font-weight-600 small">Purpose of Visit</label>
                            <select name="purpose" class="form-select">
                                <option value="Personal Visit">Personal Visit</option>
                                <option value="Delivery / Dispatch">Delivery / Dispatch</option>
                                <option value="Maintenance / Repairs">Maintenance / Repairs</option>
                                <option value="Business / Meeting">Business / Meeting</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label font-weight-600 small">Entry Gate</label>
                            <select name="entry_gate" class="form-select">
                                <option value="Main Gate">Main Gate</option>
                                <option value="North Gate">North Gate</option>
                                <option value="South Gate">South Gate</option>
                                <option value="Pedestrian Gate">Pedestrian Gate</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label font-weight-600 small">Officer Notes / Observations</label>
                            <input type="text" name="guard_notes" class="form-control" placeholder="Optional gate inspection notes...">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top bg-light">
                    <button type="button" class="btn btn-secondary rounded-pill font-weight-600" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill font-weight-600 px-4">
                        <i class="fa-solid fa-check me-1"></i> Register &amp; Approve Entry
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Detailed Audit Pass Modal -->
<div class="modal fade" id="auditCardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-bottom">
                <h5 class="modal-title font-outfit font-weight-700"><i class="fa-solid fa-id-card text-primary me-2"></i> Visitor Audit Pass Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="text-center p-3 mb-3 rounded-4" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: white;">
                    <div class="text-uppercase small text-muted-light" style="font-size: 0.75rem; color: #94a3b8;">Visitor Gate Pass Code</div>
                    <div class="h2 font-monospace font-weight-700 text-info my-1" id="aCode">EST-XXXXXX</div>
                    <div class="h5 font-weight-700 text-white mb-0" id="aName">Visitor Name</div>
                    <div class="small text-slate-300" id="aPhone">08012345678</div>
                    <div class="badge bg-light text-dark font-monospace mt-1" id="aVehicleRow" style="display:none;"><i class="fa-solid fa-car me-1"></i><span id="aVehicle"></span></div>
                </div>

                <div class="border rounded-3 p-3 mb-3 bg-light">
                    <div class="row g-2">
                        <div class="col-6"><span class="text-muted small">Resident:</span> <strong class="text-dark d-block" id="aResident">John Doe</strong></div>
                        <div class="col-6"><span class="text-muted small">Property:</span> <strong class="text-dark d-block" id="aFlat">Flat 12</strong></div>
                        <div class="col-12 mt-2"><span class="text-muted small">Purpose:</span> <strong class="text-dark d-block" id="aPurpose">Personal Visit</strong></div>
                    </div>
                </div>

                <div class="timeline-box px-2">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small font-weight-600">ENTRY:</span>
                        <span class="fw-bold text-dark" id="aEntry">-</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small font-weight-600">PROCESSED BY:</span>
                        <span class="fw-bold text-dark" id="aEntryStaff">-</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small font-weight-600">ENTRY SHIFT:</span>
                        <span class="fw-bold text-primary" id="aEntryShift">-</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small font-weight-600">RESIDENT CONFIRMATION:</span>
                        <span class="fw-bold text-success" id="aConfirmed">-</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small font-weight-600">EXIT:</span>
                        <span class="fw-bold text-dark" id="aExit">-</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small font-weight-600">EXIT SHIFT:</span>
                        <span class="fw-bold text-danger" id="aExitShift">-</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small font-weight-600">DURATION:</span>
                        <span class="fw-bold text-primary" id="aDuration">-</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2" id="aReasonRow">
                        <span class="text-muted small font-weight-600">EXIT REASON:</span>
                        <span class="fw-bold text-danger" id="aReason">-</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted small font-weight-600">STATUS:</span>
                        <span class="badge bg-dark rounded-pill" id="aStatus">-</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top bg-light">
                <a id="aPrintPassBtn" href="#" target="_blank" class="btn btn-primary rounded-pill px-4">
                    <i class="fa-solid fa-print me-1"></i> Print Official Gate Pass
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function viewAuditCard(data) {
    document.getElementById('aCode').innerText = data.code || 'N/A';
    document.getElementById('aName').innerText = data.name;
    document.getElementById('aPhone').innerText = 'Phone: ' + (data.phone || 'N/A');
    document.getElementById('aResident').innerText = data.resident || 'N/A';
    document.getElementById('aFlat').innerText = 'Flat ' + (data.flat || '-');
    document.getElementById('aPurpose').innerText = data.purpose || 'Personal';
    document.getElementById('aEntry').innerText = data.entry + ' (' + (data.entry_gate || 'Main Gate') + ')';
    document.getElementById('aEntryStaff').innerText = data.entry_staff;
    document.getElementById('aEntryShift').innerText = data.entry_shift || 'General Shift';
    document.getElementById('aConfirmed').innerText = data.confirmed;
    document.getElementById('aExit').innerText = data.exit + ' (' + (data.exit_gate || 'Main Gate') + ')';
    document.getElementById('aExitShift').innerText = data.exit_shift || 'General Shift';
    document.getElementById('aDuration').innerText = data.duration;
    document.getElementById('aStatus').innerText = data.status;
    
    if (data.vehicle_plate && data.vehicle_plate !== '') {
        document.getElementById('aVehicleRow').style.display = 'inline-block';
        document.getElementById('aVehicle').innerText = data.vehicle_plate;
    } else {
        document.getElementById('aVehicleRow').style.display = 'none';
    }

    if (data.id) {
        document.getElementById('aPrintPassBtn').href = '../gate_pass?id=' + data.id;
    } else if (data.code) {
        document.getElementById('aPrintPassBtn').href = '../gate_pass?code=' + encodeURIComponent(data.code);
    }
    
    if (data.reason && data.reason !== 'N/A') {
        document.getElementById('aReasonRow').style.display = 'flex';
        document.getElementById('aReason').innerText = data.reason;
    } else {
        document.getElementById('aReasonRow').style.display = 'none';
    }
    
    var modal = new bootstrap.Modal(document.getElementById('auditCardModal'));
    modal.show();
}
</script>

<?php include '../includes/footer.php'; ?>
