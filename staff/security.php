<?php
// staff/security.php - Detailed Security Gate Pass Control & Shift Activity Logs
require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/Mailer.php';
require_once '../includes/vehicle_helper.php';

requirePermission('visitors.view_log');

$estate_id = get_estate_id();
$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Fetch Staff User Information
$me_res = $conn->query("SELECT * FROM users WHERE id = $user_id LIMIT 1");
$current_staff = ($me_res && $me_row = $me_res->fetch_assoc()) ? $me_row : ['name' => 'Gate Officer', 'email' => ''];
$current_user_name = $current_staff['name'] ?? 'Gate Officer';

// Fetch Guard's Current Duty Shift & Assigned Post
$today_str = date('Y-m-d');
$now_str = date('Y-m-d H:i:s');
$current_time_str = date('H:i:s');

$my_duty = $conn->query("SELECT sr.*, sp.post_name, sp.phone_extension, ss.name as shift_name, ss.start_time, ss.end_time, ss.color_code
                         FROM security_roster sr
                         LEFT JOIN security_posts sp ON sr.post_id = sp.id
                         LEFT JOIN security_shifts ss ON sr.shift_id = ss.id
                         WHERE sr.estate_id = $estate_id 
                           AND sr.user_id = $user_id 
                           AND (sr.duty_date = '$today_str' OR (sr.start_datetime <= '$now_str' AND sr.end_datetime >= '$now_str'))
                         ORDER BY (CASE WHEN sr.status = 'on_duty' THEN 0 ELSE 1 END), sr.start_datetime ASC LIMIT 1")->fetch_assoc();

// Determine Active Duty Shift & Post Name
$active_shift_name = 'General Security Duty';
$active_roster_id = null;
$active_post_name = 'Main Entrance Gate';

if (!empty($my_duty)) {
    $active_shift_name = !empty($my_duty['shift_name']) ? $my_duty['shift_name'] : 'Assigned Shift';
    $active_roster_id = intval($my_duty['id']);
    $active_post_name = !empty($my_duty['post_name']) ? $my_duty['post_name'] : 'Main Entrance Gate';
} else {
    // Detect shift template matching current time
    $time_shift = $conn->query("SELECT name FROM security_shifts WHERE estate_id = $estate_id AND is_active = 1 AND 
        ((start_time <= end_time AND '$current_time_str' BETWEEN start_time AND end_time) OR 
         (start_time > end_time AND ('$current_time_str' >= start_time OR '$current_time_str' <= end_time))) LIMIT 1");
    if ($time_shift && $ts_row = $time_shift->fetch_assoc()) {
        $active_shift_name = $ts_row['name'];
    }
}

// Available Gates and Shifts for Dropdowns
$posts_res = $conn->query("SELECT post_name FROM security_posts WHERE estate_id = $estate_id AND status = 'active' ORDER BY post_name ASC");
$posts_list = [];
if ($posts_res && $posts_res->num_rows > 0) {
    while ($pr = $posts_res->fetch_assoc()) $posts_list[] = $pr['post_name'];
}
if (empty($posts_list)) {
    $posts_list = ['Main Entrance Gate', 'North Pedestrian Gate', 'South Perimeter Gate', 'Main Gate'];
}

$shifts_res = $conn->query("SELECT name FROM security_shifts WHERE estate_id = $estate_id AND is_active = 1 ORDER BY name ASC");
$shifts_list = [];
if ($shifts_res && $shifts_res->num_rows > 0) {
    while ($sr = $shifts_res->fetch_assoc()) $shifts_list[] = $sr['name'];
}

// Handle Form Submissions (Check-In / Check-Out)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $error = "CSRF Verification failed.";
    } else {
        // 1. Process Check-In by Passcode
        if (isset($_POST['process_check_in'])) {
            $code = strtoupper(trim($_POST['visitor_code'] ?? ''));
            $gate = $conn->real_escape_string($_POST['gate_name'] ?? $active_post_name);
            $vehicle_plate = $conn->real_escape_string(strtoupper(trim($_POST['vehicle_plate'] ?? '')));
            $guard_notes = $conn->real_escape_string(trim($_POST['guard_notes'] ?? ''));
            $shift_name_escaped = $conn->real_escape_string($active_shift_name);
            $roster_id_val = $active_roster_id ? $active_roster_id : "NULL";
            
            $res = $conn->query("SELECT * FROM visitors WHERE estate_id = $estate_id AND (visitor_code = '$code' OR id = " . intval($code) . ") AND status IN ('pre_registered', 'confirmed')");
            if ($res && $res->num_rows > 0) {
                $vis = $res->fetch_assoc();
                $v_id = intval($vis['id']);
                
                $sql = "UPDATE visitors SET 
                        status = 'entered', 
                        entry_time = NOW(), 
                        entry_processed_by = $user_id, 
                        entry_shift_name = '$shift_name_escaped',
                        entry_roster_id = $roster_id_val,
                        entry_gate = '$gate'" . 
                        (!empty($vehicle_plate) ? ", vehicle_plate = '$vehicle_plate'" : "") .
                        (!empty($guard_notes) ? ", guard_notes = '$guard_notes'" : "") . "
                        WHERE id = $v_id AND estate_id = $estate_id";
                if ($conn->query($sql)) {
                    // Send instant gate arrival alert to resident
                    EstateMailer::sendVisitorArrivalAlert($conn, $v_id, $gate);

                    $audit_text = "Checked in visitor {$vis['name']} (Code: $code) at $gate by Officer $current_user_name (Shift: $active_shift_name)." . (!empty($vehicle_plate) ? " Vehicle: $vehicle_plate" : "");
                    logAudit($conn, "Visitor Check-In", "Security", $audit_text);
                    $_SESSION['success_message'] = "Visitor '{$vis['name']}' checked in successfully at $gate under {$active_shift_name} (Resident alerted via email)!";
                    header("Location: security");
                    exit;
                } else {
                    $error = "Error updating entry: " . $conn->error;
                }
            } else {
                $error = "Invalid or expired visitor passcode '$code'. Please verify with the resident.";
            }
        }
        
        // 2. Process Check-Out
        elseif (isset($_POST['process_check_out'])) {
            $v_id = intval($_POST['visitor_id'] ?? 0);
            $gate = $conn->real_escape_string($_POST['gate_name'] ?? $active_post_name);
            $exit_reason = $conn->real_escape_string(trim($_POST['exit_reason'] ?? 'Normal Departure'));
            $exit_notes = $conn->real_escape_string(trim($_POST['exit_notes'] ?? ''));
            $shift_name_escaped = $conn->real_escape_string($active_shift_name);
            $roster_id_val = $active_roster_id ? $active_roster_id : "NULL";
            
            $res = $conn->query("SELECT * FROM visitors WHERE id = $v_id AND estate_id = $estate_id");
            if ($res && $res->num_rows > 0) {
                $vis = $res->fetch_assoc();
                $notes_append = !empty($exit_notes) ? "\n[Exit Note by $current_user_name]: $exit_notes" : "";
                
                $sql = "UPDATE visitors SET 
                        status = 'checked_out', 
                        exit_time = NOW(), 
                        exit_processed_by = $user_id, 
                        exit_shift_name = '$shift_name_escaped',
                        exit_roster_id = $roster_id_val,
                        exit_gate = '$gate',
                        exit_reason = '$exit_reason'" .
                        (!empty($notes_append) ? ", guard_notes = CONCAT(COALESCE(guard_notes, ''), '" . $conn->real_escape_string($notes_append) . "')" : "") . "
                        WHERE id = $v_id AND estate_id = $estate_id";
                if ($conn->query($sql)) {
                    $audit_text = "Checked out visitor {$vis['name']} at $gate by Officer $current_user_name (Shift: $active_shift_name). Reason: $exit_reason";
                    logAudit($conn, "Visitor Check-Out", "Security", $audit_text);
                    $_SESSION['success_message'] = "Visitor '{$vis['name']}' checked out successfully at $gate!";
                    header("Location: security");
                    exit;
                } else {
                    $error = "Error processing check-out: " . $conn->error;
                }
            }
        }

        // 3. Process Resident Vehicle Gate Movement
        elseif (isset($_POST['process_vehicle_movement'])) {
            $v_id = intval($_POST['vehicle_id'] ?? 0);
            $direction = ($_POST['direction'] ?? '') === 'exit' ? 'exit' : 'entry';
            $gate = $conn->real_escape_string($_POST['gate_name'] ?? $active_post_name);
            $shift = $conn->real_escape_string($_POST['shift_name'] ?? $active_shift_name);
            $notes = $conn->real_escape_string(trim($_POST['vehicle_notes'] ?? ''));
            $reg_num = trim($_POST['reg_number'] ?? '');
            
            if (recordVehicleGateLog($conn, $estate_id, $v_id, $direction, $gate, $shift, $user_id, $notes)) {
                $_SESSION['success_message'] = "Vehicle $reg_num clearance recorded: " . strtoupper($direction) . " at $gate under $shift!";
            } else {
                $_SESSION['error_message'] = "Could not record vehicle gate movement.";
            }
            header("Location: security?search_vehicle=" . urlencode($reg_num));
            exit;
        }
    }
}

// Fetch Staff Account Activity KPI Counters
$my_stats = $conn->query("SELECT 
    COUNT(CASE WHEN entry_processed_by = $user_id AND DATE(entry_time) = '$today_str' THEN 1 END) as my_entries_today,
    COUNT(CASE WHEN exit_processed_by = $user_id AND DATE(exit_time) = '$today_str' THEN 1 END) as my_exits_today,
    COUNT(CASE WHEN entry_processed_by = $user_id OR exit_processed_by = $user_id THEN 1 END) as my_total_all_time,
    COUNT(CASE WHEN status = 'entered' THEN 1 END) as total_inside_now
    FROM visitors WHERE estate_id = $estate_id")->fetch_assoc();

$my_entries_today = intval($my_stats['my_entries_today'] ?? 0);
$my_exits_today = intval($my_stats['my_exits_today'] ?? 0);
$my_total_all_time = intval($my_stats['my_total_all_time'] ?? 0);
$total_inside_now = intval($my_stats['total_inside_now'] ?? 0);

// Fetch Active Visitors inside the estate
$inside_visitors = $conn->query("SELECT v.*, u.name as resident_name, u.phone as resident_phone, f.number as flat_number, 
    e_staff.name as entry_staff_name,
    TIMESTAMPDIFF(MINUTE, v.entry_time, NOW()) as duration_minutes 
    FROM visitors v 
    LEFT JOIN users u ON v.resident_id = u.id 
    LEFT JOIN flats f ON v.flat_id = f.id 
    LEFT JOIN users e_staff ON v.entry_processed_by = e_staff.id 
    WHERE v.estate_id = $estate_id AND v.status = 'entered' 
    ORDER BY v.entry_time DESC");

// Fetch Expected Pre-Registered Visitors
$expected_visitors = $conn->query("SELECT v.*, u.name as resident_name, f.number as flat_number 
    FROM visitors v 
    LEFT JOIN users u ON v.resident_id = u.id 
    LEFT JOIN flats f ON v.flat_id = f.id 
    WHERE v.estate_id = $estate_id AND v.status = 'pre_registered' 
    ORDER BY v.id DESC LIMIT 20");

// Fetch "My Actions & Shift Logs" (Visitors logged specifically by this logged-in staff member)
$my_logs_res = $conn->query("SELECT v.*, u.name as resident_name, u.phone as resident_phone, f.number as flat_number,
    e_staff.name as entry_staff_name, x_staff.name as exit_staff_name
    FROM visitors v 
    LEFT JOIN users u ON v.resident_id = u.id 
    LEFT JOIN flats f ON v.flat_id = f.id 
    LEFT JOIN users e_staff ON v.entry_processed_by = e_staff.id 
    LEFT JOIN users x_staff ON v.exit_processed_by = x_staff.id 
    WHERE v.estate_id = $estate_id 
      AND (v.entry_processed_by = $user_id OR v.exit_processed_by = $user_id) 
    ORDER BY COALESCE(v.exit_time, v.entry_time, v.created_at) DESC LIMIT 60");

// Fetch Filtered Gate History Log
$filter_gate = $conn->real_escape_string(trim($_GET['filter_gate'] ?? ''));
$filter_shift = $conn->real_escape_string(trim($_GET['filter_shift'] ?? ''));
$filter_date = $conn->real_escape_string(trim($_GET['filter_date'] ?? ''));
$filter_my_only = isset($_GET['filter_my_only']) && $_GET['filter_my_only'] == '1';
$search_q = $conn->real_escape_string(trim($_GET['search_q'] ?? ''));

$hist_where = ["v.estate_id = $estate_id", "v.status IN ('entered', 'checked_out', 'exited_without_confirmation', 'cancelled')"];
if (!empty($filter_gate)) {
    $hist_where[] = "(v.entry_gate = '$filter_gate' OR v.exit_gate = '$filter_gate')";
}
if (!empty($filter_shift)) {
    $hist_where[] = "(v.entry_shift_name = '$filter_shift' OR v.exit_shift_name = '$filter_shift')";
}
if (!empty($filter_date)) {
    $hist_where[] = "(DATE(v.entry_time) = '$filter_date' OR DATE(v.exit_time) = '$filter_date' OR DATE(v.created_at) = '$filter_date')";
}
if ($filter_my_only) {
    $hist_where[] = "(v.entry_processed_by = $user_id OR v.exit_processed_by = $user_id)";
}
if (!empty($search_q)) {
    $hist_where[] = "(v.visitor_code LIKE '%$search_q%' OR v.name LIKE '%$search_q%' OR v.phone LIKE '%$search_q%' OR v.vehicle_plate LIKE '%$search_q%' OR u.name LIKE '%$search_q%')";
}
$hist_sql = "SELECT v.*, u.name as resident_name, u.phone as resident_phone, f.number as flat_number,
    e_staff.name as entry_staff_name, x_staff.name as exit_staff_name
    FROM visitors v 
    LEFT JOIN users u ON v.resident_id = u.id 
    LEFT JOIN flats f ON v.flat_id = f.id 
    LEFT JOIN users e_staff ON v.entry_processed_by = e_staff.id 
    LEFT JOIN users x_staff ON v.exit_processed_by = x_staff.id 
    WHERE " . implode(' AND ', $hist_where) . " 
    ORDER BY COALESCE(v.exit_time, v.entry_time, v.created_at) DESC LIMIT 100";
$history_visitors = $conn->query($hist_sql);

// Vehicle Search & Gate Clearance
$searched_vehicle_q = trim($_GET['search_vehicle'] ?? '');
$vehicle_dossier = null;
$vehicle_search_error = '';
if (!empty($searched_vehicle_q)) {
    $vehicle_dossier = getVehicleDossier($conn, $estate_id, $searched_vehicle_q);
    if (!$vehicle_dossier) {
        $vehicle_search_error = "No registered vehicle found matching '" . htmlspecialchars($searched_vehicle_q) . "'. Please check the plate number or confirm registration.";
    }
}

// Fetch Today's Vehicle Gate Movement Logs
$today_vehicle_logs = $conn->query("
    SELECT vgl.*, v.model, v.color, v.custom_id, u.name as officer_name, f.number as flat_number
    FROM vehicle_gate_logs vgl
    LEFT JOIN vehicles v ON vgl.vehicle_id = v.id
    LEFT JOIN flats f ON v.flat_id = f.id
    LEFT JOIN users u ON vgl.processed_by = u.id
    WHERE vgl.estate_id = $estate_id AND DATE(vgl.logged_at) = '$today_str'
    ORDER BY vgl.logged_at DESC LIMIT 15
");

// Total vehicles in estate for KPI badge
$estate_vehicles_count = $conn->query("SELECT COUNT(*) as c FROM vehicles WHERE estate_id = $estate_id")->fetch_assoc()['c'] ?? 0;

include 'header.php';
include 'sidebar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h2 class="h4 font-bold text-slate-800 m-0"><i class="fa-solid fa-shield-halved me-2 text-teal" style="color: #0d9488;"></i> Gate Pass Control &amp; Duty Shift Logs</h2>
        <p class="text-secondary small mb-0">Security-conscious gate clearance, active guard duty shift binding, and officer audit logs.</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="incidents" class="btn btn-sm btn-outline-danger rounded-pill px-3 shadow-sm">
            <i class="fa-solid fa-triangle-exclamation me-1 text-danger"></i> Log Incident
        </a>
        <a href="roster" class="btn btn-sm btn-outline-dark rounded-pill px-3 shadow-sm">
            <i class="fa-solid fa-calendar-check me-1 text-teal"></i> My Duty Schedule
        </a>
    </div>
</div>

<!-- ==========================================
     GUARD DUTY ATTENDANCE STRIP (Active Shift Info)
     ========================================== -->
<?php 
$is_on_duty = !empty($my_duty) && ($my_duty['status'] === 'on_duty');
$is_completed = !empty($my_duty) && ($my_duty['status'] === 'completed');
$is_scheduled = !empty($my_duty) && ($my_duty['status'] === 'scheduled');
?>
<div class="card border-0 shadow-sm rounded-4 p-3 mb-4" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #ffffff;">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div style="width: 46px; height: 46px; border-radius: 50%; background: rgba(13, 148, 136, 0.2); border: 2px solid #0d9488; color: #5eead4; display: flex; align-items: center; justify-content: center; font-size: 1.3rem;">
                <i class="fa-solid fa-user-shield"></i>
            </div>
            <div>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                    <?php if ($is_on_duty): ?>
                        <span class="badge bg-success rounded-pill px-2.5 py-1 small"><i class="fa-solid fa-circle-check me-1"></i> ON DUTY NOW</span>
                    <?php elseif ($is_completed): ?>
                        <span class="badge bg-info text-dark rounded-pill px-2.5 py-1 small"><i class="fa-solid fa-flag-checkered me-1"></i> SHIFT COMPLETED</span>
                    <?php elseif ($is_scheduled): ?>
                        <span class="badge bg-warning text-dark rounded-pill px-2.5 py-1 small"><i class="fa-solid fa-clock me-1"></i> SCHEDULED TODAY</span>
                    <?php else: ?>
                        <span class="badge bg-secondary rounded-pill px-2.5 py-1 small"><i class="fa-solid fa-shield me-1"></i> GENERAL SHIFT MODE</span>
                    <?php endif; ?>
                    <strong class="text-white fs-6"><?php echo htmlspecialchars($active_post_name); ?></strong>
                    <span class="badge bg-white bg-opacity-10 text-teal border border-secondary border-opacity-25">
                        <i class="fa-solid fa-clock me-1 text-teal"></i> <?php echo htmlspecialchars($active_shift_name); ?>
                    </span>
                    <span class="text-slate-400 small">&bull; Logged Officer: <strong><?php echo htmlspecialchars($current_user_name); ?></strong></span>
                </div>
                <div class="text-slate-300 small">
                    All visitor entry/exit passes processed by your account will automatically be stamped with your Officer Profile and the <strong><?php echo htmlspecialchars($active_shift_name); ?></strong> audit log.
                </div>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2">
            <?php if ($is_scheduled): ?>
                <button type="button" class="btn btn-sm btn-success rounded-pill px-3 fw-bold shadow-sm" onclick="quickClockInOut(<?php echo $my_duty['id']; ?>, 'clock_in')">
                    <i class="fa-solid fa-arrow-right-to-bracket me-1"></i> Clock In Shift
                </button>
            <?php elseif ($is_on_duty): ?>
                <button type="button" class="btn btn-sm btn-danger rounded-pill px-3 fw-bold shadow-sm" onclick="quickClockInOut(<?php echo $my_duty['id']; ?>, 'clock_out')">
                    <i class="fa-solid fa-arrow-right-from-bracket me-1"></i> Clock Out Shift
                </button>
            <?php endif; ?>
            <a href="roster" class="btn btn-sm btn-outline-light rounded-pill px-3">
                <i class="fa-solid fa-calendar me-1"></i> Full Schedule
            </a>
        </div>
    </div>
</div>

<!-- ==========================================
     STAFF ACCOUNT ACTIVITY KPI STRIP
     ========================================== -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-3 p-3 bg-white border-start border-4 border-teal" style="border-left-color: #0d9488 !important;">
            <div class="text-secondary small fw-semibold text-uppercase">Checked In by Me (Today)</div>
            <div class="d-flex align-items-center justify-content-between mt-1">
                <span class="h3 fw-bold text-slate-800 mb-0"><?php echo $my_entries_today; ?></span>
                <span class="badge bg-teal bg-opacity-10 text-teal rounded-circle p-2" style="color: #0d9488;"><i class="fa-solid fa-right-to-bracket fs-5"></i></span>
            </div>
            <small class="text-muted">Cleared arrivals</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-3 p-3 bg-white border-start border-4 border-danger">
            <div class="text-secondary small fw-semibold text-uppercase">Checked Out by Me (Today)</div>
            <div class="d-flex align-items-center justify-content-between mt-1">
                <span class="h3 fw-bold text-slate-800 mb-0"><?php echo $my_exits_today; ?></span>
                <span class="badge bg-danger bg-opacity-10 text-danger rounded-circle p-2"><i class="fa-solid fa-right-from-bracket fs-5"></i></span>
            </div>
            <small class="text-muted">Processed exits</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-3 p-3 bg-white border-start border-4 border-primary">
            <div class="text-secondary small fw-semibold text-uppercase">Inside Estate Now</div>
            <div class="d-flex align-items-center justify-content-between mt-1">
                <span class="h3 fw-bold text-slate-800 mb-0"><?php echo $total_inside_now; ?></span>
                <span class="badge bg-primary bg-opacity-10 text-primary rounded-circle p-2"><i class="fa-solid fa-street-view fs-5"></i></span>
            </div>
            <small class="text-muted">Active guest passes</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm rounded-3 p-3 bg-white border-start border-4 border-dark">
            <div class="text-secondary small fw-semibold text-uppercase">My Total Passes Handled</div>
            <div class="d-flex align-items-center justify-content-between mt-1">
                <span class="h3 fw-bold text-slate-800 mb-0"><?php echo $my_total_all_time; ?></span>
                <span class="badge bg-dark bg-opacity-10 text-dark rounded-circle p-2"><i class="fa-solid fa-fingerprint fs-5"></i></span>
            </div>
            <small class="text-muted">Personal audit lifetime</small>
        </div>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- ==========================================
     VEHICLE & CAR STICKER SECURITY CLEARANCE SCANNER
     ========================================== -->
<div class="card border-0 shadow-sm rounded-3 mb-4" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: white; border: 1px solid #334155 !important;">
    <div class="card-body p-4">
        <div class="row align-items-center mb-3">
            <div class="col-lg-7">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge rounded-pill px-3 py-1.5" style="background: #f59e0b; color: #0f172a; font-weight: 800;">
                        <i class="fa-solid fa-car-side me-1"></i> VEHICLE SCANNER
                    </span>
                    <span class="text-slate-400 small">&bull; Estate Gate Access &amp; Sticker Verification</span>
                </div>
                <h4 class="fw-bold mb-1 text-white" style="letter-spacing: -0.02em;">Resident Vehicle &amp; Car Sticker Clearance</h4>
                <p class="mb-0 text-slate-300 small">Scan or enter vehicle license plate number or Car Sticker ID for instant owner identification, flat unit, and gate clearance.</p>
            </div>
            <div class="col-lg-5 mt-3 mt-lg-0 text-lg-end">
                <span class="badge bg-white bg-opacity-10 text-slate-300 px-3 py-2 border border-secondary border-opacity-25">
                    <i class="fa-solid fa-shield-halved text-warning me-1"></i> Total Registered Vehicles: <strong><?php echo $estate_vehicles_count; ?></strong>
                </span>
            </div>
        </div>

        <!-- SEARCH INPUT -->
        <form method="GET" action="security" class="position-relative" id="vehicleSearchForm">
            <div class="input-group input-group-lg shadow-sm">
                <span class="input-group-text bg-white border-0 text-slate-400">
                    <i class="fa-solid fa-magnifying-glass text-warning fs-5"></i>
                </span>
                <input type="text" name="search_vehicle" id="vehicleSearchInput" class="form-control border-0 font-monospace fw-bold text-uppercase" placeholder="ENTER LICENSE PLATE (E.G. KJA-849-XA) OR CAR ID (VEH-00001)" value="<?php echo htmlspecialchars($searched_vehicle_q); ?>" style="letter-spacing: 1.5px; font-size: 1.05rem;" autocomplete="off">
                <button type="submit" class="btn px-4 fw-bold text-dark" style="background: #f59e0b;">
                    <i class="fa-solid fa-shield-check me-1"></i> Verify Vehicle
                </button>
                <?php if (!empty($searched_vehicle_q)): ?>
                    <a href="security" class="btn btn-outline-light px-3 fw-semibold">
                        <i class="fa-solid fa-times me-1"></i> Clear
                    </a>
                <?php endif; ?>
            </div>
            <!-- Live Autocomplete Suggestions Box -->
            <div id="vehicleSuggestions" class="list-group position-absolute w-100 shadow-lg" style="display: none; z-index: 1050; top: 100%; margin-top: 4px; border-radius: 8px; overflow: hidden; max-height: 280px; overflow-y: auto;"></div>
        </form>
    </div>
</div>

<?php if ($vehicle_search_error): ?>
    <div class="alert alert-warning alert-dismissible fade show rounded-3 p-3 mb-4 shadow-sm" role="alert">
        <div class="d-flex align-items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation fs-5 text-warning"></i>
            <div>
                <strong>Vehicle Clearance Notice:</strong> <?php echo $vehicle_search_error; ?>
                <div class="small mt-1 text-secondary">Tip: Double check spacing or hyphens, or ensure the vehicle is registered under Resident Registration.</div>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($vehicle_dossier): ?>
    <!-- DETAILED VEHICLE CLEARANCE & RESIDENT DOSSIER -->
    <div class="card border-0 shadow-sm rounded-3 mb-4 bg-white" style="border-top: 4px solid #f59e0b !important; border-left: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0;">
        <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <!-- License Plate Chip -->
                <div style="background: #eff6ff; border: 2px solid #3b82f6; color: #1e40af; font-family: 'Chakra Petch', sans-serif, monospace; font-size: 1.4rem; font-weight: 800; letter-spacing: 2px; padding: 2px 14px; border-radius: 6px; box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);">
                    <i class="fa-solid fa-car me-1 text-primary" style="font-size: 1rem;"></i>
                    <?php echo htmlspecialchars($vehicle_dossier['reg_number']); ?>
                </div>
                <span class="badge bg-dark font-monospace fs-6 px-3 py-2">
                    <i class="fa-solid fa-fingerprint text-warning me-1"></i> <?php echo htmlspecialchars($vehicle_dossier['custom_id']); ?>
                </span>
                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 fs-6 px-3 py-2">
                    <i class="fa-solid fa-circle-check me-1"></i> VERIFIED RESIDENT PASS
                </span>
            </div>

            <div class="d-flex align-items-center gap-2">
                <a href="../car_sticker.php?id=<?php echo $vehicle_dossier['id']; ?>" target="_blank" class="btn btn-sm btn-warning text-dark fw-bold rounded-pill px-3 shadow-sm">
                    <i class="fa-solid fa-id-card me-1"></i> View / Print Official Sticker
                </a>
                <a href="security" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                    <i class="fa-solid fa-xmark me-1"></i> Close Dossier
                </a>
            </div>
        </div>

        <div class="card-body p-4">
            <div class="row g-4">
                <!-- Column 1: Vehicle Specifications -->
                <div class="col-md-4 border-end">
                    <div class="text-secondary small fw-bold text-uppercase mb-2" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                        <i class="fa-solid fa-car text-warning me-1"></i> Vehicle Specifications
                    </div>
                    
                    <div class="d-flex gap-3 align-items-center mb-3">
                        <?php if (!empty($vehicle_dossier['image_path'])): ?>
                            <img src="<?php echo htmlspecialchars($vehicle_dossier['image_path']); ?>" alt="Vehicle Photo" class="rounded-3 shadow-sm border" style="width: 80px; height: 80px; object-fit: cover;">
                        <?php else: ?>
                            <div class="rounded-3 d-flex align-items-center justify-content-center bg-light border text-secondary" style="width: 80px; height: 80px;">
                                <i class="fa-solid fa-car-side fa-2x text-slate-400"></i>
                            </div>
                        <?php endif; ?>

                        <div>
                            <h5 class="fw-bold text-slate-900 mb-0"><?php echo htmlspecialchars($vehicle_dossier['model'] ?? 'Standard Vehicle'); ?></h5>
                            <div class="text-secondary small mt-1">
                                <span class="badge bg-light text-dark border me-1"><?php echo ucfirst(htmlspecialchars($vehicle_dossier['type'] ?? 'car')); ?></span>
                                <span>Color: <strong><?php echo htmlspecialchars($vehicle_dossier['color'] ?? 'Unspecified'); ?></strong></span>
                            </div>
                            <div class="text-muted small mt-1">
                                Reg Date: <?php echo !empty($vehicle_dossier['registration_date']) ? date('M d, Y', strtotime($vehicle_dossier['registration_date'])) : 'N/A'; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($vehicle_dossier['particulars_path'])): ?>
                        <div class="mt-2">
                            <a href="<?php echo htmlspecialchars($vehicle_dossier['particulars_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill py-1 px-3">
                                <i class="fa-solid fa-file-lines me-1"></i> Vehicle Particulars Document
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Column 2: Registered Owner & Host -->
                <div class="col-md-4 border-end">
                    <div class="text-secondary small fw-bold text-uppercase mb-2" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                        <i class="fa-solid fa-user-shield text-teal me-1" style="color: #0d9488;"></i> Registered Vehicle Owner
                    </div>

                    <div class="d-flex gap-3 align-items-center mb-3">
                        <?php if (!empty($vehicle_dossier['owner']['image_path'])): ?>
                            <img src="<?php echo htmlspecialchars($vehicle_dossier['owner']['image_path']); ?>" alt="Owner Photo" class="rounded-circle shadow-sm border" style="width: 60px; height: 60px; object-fit: cover;">
                        <?php else: ?>
                            <div class="rounded-circle d-flex align-items-center justify-content-center bg-teal bg-opacity-10 border border-teal text-teal" style="width: 60px; height: 60px; color: #0d9488;">
                                <i class="fa-solid fa-user fs-4"></i>
                            </div>
                        <?php endif; ?>

                        <div>
                            <h5 class="fw-bold text-slate-900 mb-0"><?php echo htmlspecialchars($vehicle_dossier['owner']['name'] ?? 'Registered Resident'); ?></h5>
                            <div class="small font-monospace text-muted"><?php echo htmlspecialchars($vehicle_dossier['owner']['custom_id'] ?? 'RES-00000'); ?> &bull; <?php echo ucfirst(htmlspecialchars($vehicle_dossier['owner']['type'] ?? 'head')); ?></div>
                        </div>
                    </div>

                    <div class="small d-flex flex-column gap-1 text-slate-700">
                        <?php if (!empty($vehicle_dossier['owner']['phone'])): ?>
                            <div class="d-flex align-items-center justify-content-between">
                                <span><i class="fa-solid fa-phone text-success me-2"></i><strong><?php echo htmlspecialchars($vehicle_dossier['owner']['phone']); ?></strong></span>
                                <div>
                                    <a href="tel:<?php echo htmlspecialchars($vehicle_dossier['owner']['phone']); ?>" class="btn btn-sm btn-outline-primary py-0 px-2 rounded-pill" title="Call Owner"><i class="fa-solid fa-phone-volume"></i> Call</a>
                                    <?php 
                                    $wa_clean = preg_replace('/[^0-9]/', '', $vehicle_dossier['owner']['phone']);
                                    if (substr($wa_clean, 0, 1) === '0') $wa_clean = '234' . substr($wa_clean, 1);
                                    ?>
                                    <a href="https://wa.me/<?php echo $wa_clean; ?>" target="_blank" class="btn btn-sm btn-outline-success py-0 px-2 rounded-pill" title="WhatsApp"><i class="fa-brands fa-whatsapp"></i> Chat</a>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($vehicle_dossier['owner']['email'])): ?>
                            <div class="text-truncate"><i class="fa-solid fa-envelope text-secondary me-2"></i><?php echo htmlspecialchars($vehicle_dossier['owner']['email']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Column 3: Flat Location & Household -->
                <div class="col-md-4">
                    <div class="text-secondary small fw-bold text-uppercase mb-2" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                        <i class="fa-solid fa-house-chimney text-primary me-1"></i> Allocated Unit &amp; Household
                    </div>

                    <div class="p-3 rounded-3 bg-light border mb-2">
                        <div class="fw-bold text-slate-800 fs-5 mb-0">
                            Unit <?php echo htmlspecialchars($vehicle_dossier['flat_number'] ?? 'N/A'); ?>
                            <span class="small text-secondary fw-normal">(Floor <?php echo htmlspecialchars($vehicle_dossier['floor'] ?? '1'); ?>)</span>
                        </div>
                        <div class="text-secondary small">
                            <i class="fa-solid fa-building me-1"></i> <?php echo htmlspecialchars($vehicle_dossier['building_name'] ?? 'Main Block'); ?>
                        </div>
                        <div class="text-secondary small">
                            <i class="fa-solid fa-road me-1"></i> <?php echo htmlspecialchars($vehicle_dossier['street_name'] ?? 'Central Ave'); ?> &bull; <strong><?php echo htmlspecialchars($vehicle_dossier['zone_name'] ?? 'Zone'); ?></strong>
                        </div>
                    </div>

                    <?php if (!empty($vehicle_dossier['household'])): ?>
                        <div class="small">
                            <span class="text-secondary fw-semibold">Household Residents (<?php echo count($vehicle_dossier['household']); ?>):</span>
                            <div class="d-flex flex-wrap gap-1 mt-1">
                                <?php foreach ($vehicle_dossier['household'] as $member): ?>
                                    <span class="badge bg-white text-dark border py-1 px-2">
                                        <?php echo htmlspecialchars($member['name']); ?> (<?php echo ucfirst($member['type'] ?? 'member'); ?>)
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- GATE ACTION STRIP -->
            <div class="mt-4 pt-3 border-top">
                <form action="security" method="POST" class="row g-2 align-items-center">
                    <?php echo renderCSRFField(); ?>
                    <input type="hidden" name="vehicle_id" value="<?php echo $vehicle_dossier['id']; ?>">
                    <input type="hidden" name="reg_number" value="<?php echo htmlspecialchars($vehicle_dossier['reg_number']); ?>">

                    <div class="col-md-3">
                        <label class="form-label small fw-bold text-slate-700 m-0">Gate Post</label>
                        <select name="gate_name" class="form-select form-select-sm">
                            <?php foreach ($posts_list as $p_opt): ?>
                                <option value="<?php echo htmlspecialchars($p_opt); ?>" <?php echo ($p_opt === $active_post_name) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p_opt); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-slate-700 m-0">Observations / Luggage Notes (Optional)</label>
                        <input type="text" name="vehicle_notes" class="form-control form-control-sm" placeholder="e.g. Cleared pass, trunk inspected">
                    </div>

                    <div class="col-md-5 text-md-end pt-3 pt-md-3">
                        <button type="submit" name="process_vehicle_movement" value="entry" class="btn btn-success fw-bold px-4 py-2 rounded-pill shadow-sm">
                            <i class="fa-solid fa-right-to-bracket me-1"></i> Log Vehicle Entry (IN)
                        </button>
                        <button type="submit" name="process_vehicle_movement" value="exit" class="btn btn-danger fw-bold px-4 py-2 rounded-pill shadow-sm ms-2">
                            <i class="fa-solid fa-right-from-bracket me-1"></i> Log Vehicle Exit (OUT)
                        </button>
                    </div>
                </form>
            </div>

            <!-- RECENT MOVEMENTS FOR THIS VEHICLE -->
            <?php if (!empty($vehicle_dossier['recent_gate_logs'])): ?>
                <div class="mt-3 pt-3 border-top">
                    <div class="text-secondary small fw-bold text-uppercase mb-2" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                        <i class="fa-solid fa-clock-rotate-left me-1"></i> Recent Gate Activity for <?php echo htmlspecialchars($vehicle_dossier['reg_number']); ?>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.85rem;">
                            <thead class="table-light text-secondary">
                                <tr>
                                    <th>Direction</th>
                                    <th>Gate Post</th>
                                    <th>Shift / Guard Officer</th>
                                    <th>Timestamp</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vehicle_dossier['recent_gate_logs'] as $log): ?>
                                    <tr>
                                        <td>
                                            <?php if ($log['direction'] === 'entry'): ?>
                                                <span class="badge bg-success rounded-pill px-2.5 py-1"><i class="fa-solid fa-arrow-down-long me-1"></i> ENTRY</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger rounded-pill px-2.5 py-1"><i class="fa-solid fa-arrow-up-long me-1"></i> EXIT</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($log['gate_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($log['shift_name'] ?? 'Duty'); ?> &bull; <?php echo htmlspecialchars($log['officer_name'] ?? 'Officer'); ?></td>
                                        <td><?php echo date('M d, Y h:i A', strtotime($log['logged_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($log['notes'] ?: '—'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
<?php endif; ?>

<!-- TODAY'S VEHICLE MOVEMENTS LOG STRIP (RECENT) -->
<?php if ($today_vehicle_logs && $today_vehicle_logs->num_rows > 0): ?>
    <div class="card border-0 shadow-sm rounded-3 mb-4 bg-white">
        <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
            <h6 class="card-title fw-bold m-0 text-slate-800">
                <i class="fa-solid fa-arrows-left-right text-teal me-2" style="color: #0d9488;"></i> Today's Vehicle Gate Clearances
                <span class="badge rounded-pill ms-1" style="background: #0d9488;"><?php echo $today_vehicle_logs->num_rows; ?></span>
            </h6>
            <small class="text-muted">Live Gate Traffic Stream</small>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.86rem;">
                <thead class="bg-light text-secondary small text-uppercase">
                    <tr>
                        <th>Direction</th>
                        <th>License Plate &amp; Car ID</th>
                        <th>Vehicle &amp; Flat</th>
                        <th>Gate Post &amp; Officer</th>
                        <th>Timestamp</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($vlog = $today_vehicle_logs->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <?php if ($vlog['direction'] === 'entry'): ?>
                                    <span class="badge bg-success rounded-pill px-2.5 py-1"><i class="fa-solid fa-arrow-down-long me-1"></i> ENTRY</span>
                                <?php else: ?>
                                    <span class="badge bg-danger rounded-pill px-2.5 py-1"><i class="fa-solid fa-arrow-up-long me-1"></i> EXIT</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="security?search_vehicle=<?php echo urlencode($vlog['reg_number']); ?>" class="fw-bold font-monospace text-primary text-decoration-none">
                                    <?php echo htmlspecialchars($vlog['reg_number']); ?>
                                </a>
                                <div class="small font-monospace text-muted"><?php echo htmlspecialchars($vlog['custom_id'] ?? ''); ?></div>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark"><?php echo htmlspecialchars($vlog['model'] ?? 'Vehicle'); ?></div>
                                <div class="small text-secondary">Unit <?php echo htmlspecialchars($vlog['flat_number'] ?? 'N/A'); ?></div>
                            </td>
                            <td>
                                <div><strong><?php echo htmlspecialchars($vlog['gate_name']); ?></strong></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($vlog['officer_name'] ?? 'Guard'); ?></div>
                            </td>
                            <td><?php echo date('h:i A', strtotime($vlog['logged_at'])); ?></td>
                            <td><?php echo htmlspecialchars($vlog['notes'] ?: '—'); ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <!-- Verification Scanner Card -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm" style="border-top: 4px solid #0d9488 !important;">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h5 class="card-title fw-bold m-0 text-slate-800"><i class="fa-solid fa-qrcode me-2" style="color: #0d9488;"></i> Gate Verification Scanner</h5>
                <span class="badge bg-light text-secondary border small"><i class="fa-solid fa-shield me-1 text-teal"></i> Shift Bound</span>
            </div>
            <div class="card-body pt-1">
                <form action="security" method="POST">
                    <?php echo renderCSRFField(); ?>
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-slate-700">Visitor Pass Code (Passcode)</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-light"><i class="fa-solid fa-key text-muted"></i></span>
                            <input type="text" name="visitor_code" class="form-control text-uppercase fw-bold letter-spacing-1" placeholder="e.g. EST-7K42P9" required style="letter-spacing: 2px;">
                        </div>
                        <div class="form-text small">Enter the 6-character access pass code generated by the resident.</div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold text-slate-700 small">Gate Clearance Post</label>
                            <select name="gate_name" class="form-select form-select-sm">
                                <?php foreach ($posts_list as $post_opt): ?>
                                    <option value="<?php echo htmlspecialchars($post_opt); ?>" <?php echo ($post_opt === $active_post_name) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($post_opt); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label fw-semibold text-slate-700 small">Vehicle Reg Plate (Optional)</label>
                            <input type="text" name="vehicle_plate" class="form-control form-select-sm text-uppercase font-monospace" placeholder="e.g. KJA-849-XA">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-slate-700 small">Inspection Observations / Items Declared (Optional)</label>
                        <input type="text" name="guard_notes" class="form-control form-control-sm" placeholder="e.g. Contractor toolkit verified, luggage inspected">
                    </div>

                    <div class="p-2 mb-3 rounded-2 bg-light border d-flex align-items-center justify-content-between small text-secondary">
                        <span><i class="fa-solid fa-id-badge text-teal me-1"></i> Clear as: <strong><?php echo htmlspecialchars($current_user_name); ?></strong></span>
                        <span class="badge bg-secondary"><?php echo htmlspecialchars($active_shift_name); ?></span>
                    </div>

                    <?php if (hasPermission('visitors.check_in_out')): ?>
                        <button type="submit" name="process_check_in" class="btn btn-primary btn-lg w-100 fw-bold" style="background: #0d9488; border: none;">
                            <i class="fa-solid fa-right-to-bracket me-2"></i> Approve Gate Entry &amp; Log Shift
                        </button>
                    <?php else: ?>
                        <div class="alert alert-warning small mb-0"><i class="fa-solid fa-lock me-1"></i> Your staff role does not have gate check-in permissions.</div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- Active Visitors Currently Inside -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h5 class="card-title fw-bold m-0 text-slate-800">
                    <i class="fa-solid fa-street-view me-2 text-teal" style="color: #0d9488;"></i> Currently Inside Estate 
                    <span class="badge rounded-pill ms-1" style="background: #0d9488;"><?php echo $inside_visitors ? $inside_visitors->num_rows : 0; ?></span>
                </h5>
                <small class="text-muted">Real-Time Estate Presence</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table align-middle table-hover mb-0">
                        <thead class="bg-light text-secondary small text-uppercase sticky-top">
                            <tr>
                                <th>Visitor / Code</th>
                                <th>Host / Flat</th>
                                <th>Entry &amp; Guard Shift</th>
                                <th>Stay Duration</th>
                                <th class="text-end">Checkout</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($inside_visitors && $inside_visitors->num_rows > 0): ?>
                                <?php while ($vis = $inside_visitors->fetch_assoc()): ?>
                                    <?php 
                                    $duration_mins = intval($vis['duration_minutes']);
                                    $is_overstay = ($duration_mins > 720); // > 12 hours
                                    ?>
                                    <tr class="<?php echo $is_overstay ? 'table-danger' : ''; ?>">
                                        <td>
                                            <div class="fw-bold text-slate-800"><?php echo htmlspecialchars($vis['name']); ?></div>
                                            <span class="badge bg-dark font-monospace"><?php echo htmlspecialchars($vis['visitor_code'] ?? 'N/A'); ?></span>
                                            <?php if (!empty($vis['vehicle_plate'])): ?>
                                                <span class="badge bg-light text-dark border font-monospace ms-1"><i class="fa-solid fa-car me-1"></i><?php echo htmlspecialchars($vis['vehicle_plate']); ?></span>
                                            <?php endif; ?>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars($vis['phone'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($vis['resident_name'] ?? 'Resident'); ?></div>
                                            <small class="text-muted">Flat <?php echo htmlspecialchars($vis['flat_number'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td>
                                            <small class="fw-bold text-slate-800 d-block"><?php echo date('h:i A', strtotime($vis['entry_time'])); ?></small>
                                            <small class="text-secondary d-block"><i class="fa-solid fa-door-open me-1"></i><?php echo htmlspecialchars($vis['entry_gate'] ?? 'Main Gate'); ?></small>
                                            <span class="badge bg-secondary bg-opacity-25 text-slate-700" style="font-size: 0.68rem;">
                                                <i class="fa-solid fa-shield me-1"></i><?php echo htmlspecialchars($vis['entry_shift_name'] ?? 'General Shift'); ?>
                                            </span>
                                            <?php if (!empty($vis['entry_staff_name'])): ?>
                                                <small class="text-muted d-block" style="font-size: 0.7rem;">Officer: <?php echo htmlspecialchars($vis['entry_staff_name']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $is_overstay ? 'bg-danger' : 'bg-info text-dark'; ?>">
                                                <?php echo formatDuration($duration_mins); ?>
                                            </span>
                                            <?php if ($is_overstay): ?>
                                                <small class="text-danger fw-bold d-block"><i class="fa-solid fa-triangle-exclamation"></i> Overstay Flag</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="../gate_pass?id=<?php echo $vis['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary me-1" title="Print Gate Pass">
                                                <i class="fa-solid fa-print"></i>
                                            </a>
                                            <?php if (hasPermission('visitors.check_in_out')): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="openCheckoutModal(<?php echo $vis['id']; ?>, '<?php echo htmlspecialchars(addslashes($vis['name'])); ?>', '<?php echo htmlspecialchars(addslashes($vis['visitor_code'])); ?>')">
                                                    <i class="fa-solid fa-right-from-bracket me-1"></i> Check Out
                                                </button>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">View Only</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">No visitors currently inside the estate.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     TABS: 1. PRE-REGISTERED | 2. MY LOGS & SHIFT | 3. ESTATE HISTORY
     ========================================== -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 border-0">
        <ul class="nav nav-tabs card-header-tabs" id="securityTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active fw-bold" id="my-logs-tab" data-bs-toggle="tab" data-bs-target="#my_logs" type="button">
                    <i class="fa-solid fa-user-check me-1 text-teal" style="color: #0d9488;"></i> My Activity &amp; Shift Log (Logged by Me)
                    <span class="badge bg-teal ms-1" style="background: #0d9488;"><?php echo $my_logs_res ? $my_logs_res->num_rows : 0; ?></span>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-bold" id="expected-tab" data-bs-toggle="tab" data-bs-target="#expected" type="button">
                    <i class="fa-solid fa-clipboard-check me-1 text-primary"></i> Pre-Registered Arrivals
                    <span class="badge bg-primary ms-1"><?php echo $expected_visitors ? $expected_visitors->num_rows : 0; ?></span>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-bold" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button">
                    <i class="fa-solid fa-history me-1 text-secondary"></i> All Estate Gate History &amp; Chain of Custody
                </button>
            </li>
        </ul>
    </div>
    <div class="card-body p-0">
        <div class="tab-content" id="securityTabsContent">

            <!-- TAB 1: MY PROCESSED LOGS (Logged Done by this Staff Member) -->
            <div class="tab-pane fade show active" id="my_logs" role="tabpanel">
                <div class="p-3 bg-light border-bottom d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <span class="fw-bold text-slate-800"><i class="fa-solid fa-shield-cat me-1 text-teal"></i> Logged by Officer: <?php echo htmlspecialchars($current_user_name); ?></span>
                        <span class="text-muted small ms-2">&bull; High-accountability trail of all entries &amp; exits verified under your credentials</span>
                    </div>
                    <div class="text-secondary small">
                        Showing last 60 gate actions processed by you
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle table-hover mb-0">
                        <thead class="bg-light text-secondary small text-uppercase">
                            <tr>
                                <th>Passcode</th>
                                <th>Visitor Details</th>
                                <th>Destination / Host</th>
                                <th>Action Done by You</th>
                                <th>Guard Duty Shift &amp; Gate</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($my_logs_res && $my_logs_res->num_rows > 0): ?>
                                <?php while ($ml = $my_logs_res->fetch_assoc()): ?>
                                    <?php 
                                    $is_my_entry = ($ml['entry_processed_by'] == $user_id);
                                    $is_my_exit = ($ml['exit_processed_by'] == $user_id);
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-dark font-monospace fs-6"><?php echo htmlspecialchars($ml['visitor_code'] ?? 'N/A'); ?></span>
                                            <?php if (!empty($ml['vehicle_plate'])): ?>
                                                <span class="badge bg-light text-dark border font-monospace d-block mt-1"><i class="fa-solid fa-car me-1"></i><?php echo htmlspecialchars($ml['vehicle_plate']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-slate-800"><?php echo htmlspecialchars($ml['name']); ?></div>
                                            <small class="text-muted"><i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($ml['phone'] ?? 'No Phone'); ?></small>
                                            <?php if (!empty($ml['guard_notes'])): ?>
                                                <div class="small text-muted fst-italic mt-0.5"><i class="fa-solid fa-note-sticky text-warning me-1"></i><?php echo htmlspecialchars($ml['guard_notes']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($ml['resident_name'] ?? 'N/A'); ?></div>
                                            <small class="text-muted">Flat <?php echo htmlspecialchars($ml['flat_number'] ?? 'N/A'); ?></small>
                                            <div class="small text-muted"><i class="fa-regular fa-compass me-1"></i><?php echo htmlspecialchars($ml['purpose'] ?? 'Visit'); ?></div>
                                        </td>
                                        <td>
                                            <?php if ($is_my_entry): ?>
                                                <div class="badge bg-success bg-opacity-15 text-success mb-1">
                                                    <i class="fa-solid fa-right-to-bracket me-1"></i> Entry Cleared by You
                                                </div>
                                                <div class="small fw-bold text-slate-700"><?php echo date('d M, h:i A', strtotime($ml['entry_time'])); ?></div>
                                            <?php endif; ?>
                                            <?php if ($is_my_exit): ?>
                                                <div class="badge bg-danger bg-opacity-15 text-danger mb-1 mt-1">
                                                    <i class="fa-solid fa-right-from-bracket me-1"></i> Exit Cleared by You
                                                </div>
                                                <div class="small fw-bold text-slate-700"><?php echo date('d M, h:i A', strtotime($ml['exit_time'])); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div><i class="fa-solid fa-shield text-teal me-1"></i> <strong><?php echo htmlspecialchars($is_my_entry ? ($ml['entry_shift_name'] ?? 'General Shift') : ($ml['exit_shift_name'] ?? 'General Shift')); ?></strong></div>
                                            <small class="text-muted"><i class="fa-solid fa-location-dot me-1"></i> Gate: <?php echo htmlspecialchars($is_my_entry ? ($ml['entry_gate'] ?? 'Main Gate') : ($ml['exit_gate'] ?? 'Main Gate')); ?></small>
                                        </td>
                                        <td>
                                            <?php if ($ml['status'] == 'entered'): ?>
                                                <span class="badge bg-primary">Currently Inside</span>
                                            <?php elseif ($ml['status'] == 'checked_out'): ?>
                                                <span class="badge bg-secondary">Checked Out</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark border"><?php echo htmlspecialchars(ucfirst($ml['status'])); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="../gate_pass?id=<?php echo $ml['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Print Pass">
                                                <i class="fa-solid fa-print"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-dark ms-1" onclick="viewAuditTrail(<?php echo htmlspecialchars(json_encode($ml)); ?>)" title="View Audit Trail">
                                                <i class="fa-solid fa-eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="fa-solid fa-clipboard-user fs-2 d-block mb-2 text-slate-400"></i>
                                        You have not processed any visitor passes yet today. Verify an arrival or checkout to populate your activity log.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 2: PRE-REGISTERED ARRIVALS -->
            <div class="tab-pane fade" id="expected" role="tabpanel">
                <div class="table-responsive">
                    <table class="table align-middle table-hover mb-0">
                        <thead class="bg-light text-secondary small text-uppercase">
                            <tr>
                                <th>Passcode</th>
                                <th>Visitor Name</th>
                                <th>Host Resident</th>
                                <th>Purpose</th>
                                <th>Expected Arrival</th>
                                <th class="text-end">Quick Clearance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($expected_visitors && $expected_visitors->num_rows > 0): ?>
                                <?php while ($exp = $expected_visitors->fetch_assoc()): ?>
                                    <tr>
                                        <td><span class="badge bg-dark font-monospace px-2.5 py-1.5 fs-6"><?php echo htmlspecialchars($exp['visitor_code'] ?? 'N/A'); ?></span></td>
                                        <td>
                                            <div class="fw-bold text-slate-800"><?php echo htmlspecialchars($exp['name']); ?></div>
                                            <small class="text-muted"><i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($exp['phone'] ?? 'No Phone'); ?></small>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($exp['resident_name'] ?? 'N/A'); ?></div>
                                            <small class="text-muted">Flat <?php echo htmlspecialchars($exp['flat_number'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($exp['purpose'] ?? 'Visit'); ?></span></td>
                                        <td>
                                            <small class="fw-semibold text-slate-700">
                                                <?php echo $exp['expected_arrival'] ? date('d M, h:i A', strtotime($exp['expected_arrival'])) : 'Today'; ?>
                                            </small>
                                        </td>
                                        <td class="text-end">
                                            <a href="../gate_pass?id=<?php echo $exp['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary me-1" title="Print Gate Pass">
                                                <i class="fa-solid fa-print"></i>
                                            </a>
                                            <?php if (hasPermission('visitors.check_in_out')): ?>
                                                <form action="security" method="POST" class="d-inline">
                                                    <?php echo renderCSRFField(); ?>
                                                    <input type="hidden" name="visitor_code" value="<?php echo htmlspecialchars($exp['visitor_code']); ?>">
                                                    <input type="hidden" name="gate_name" value="<?php echo htmlspecialchars($active_post_name); ?>">
                                                    <button type="submit" name="process_check_in" class="btn btn-sm btn-success fw-semibold">
                                                        <i class="fa-solid fa-check me-1"></i> Quick Check-In
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No pre-registered visitors pending check-in.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 3: COMPLETE ESTATE HISTORY & CHAIN OF CUSTODY -->
            <div class="tab-pane fade" id="history" role="tabpanel">
                <!-- Search & Filter Controls -->
                <div class="p-3 bg-light border-bottom">
                    <form method="GET" action="security" class="row g-2 align-items-center">
                        <div class="col-md-3">
                            <input type="text" name="search_q" class="form-control form-control-sm" placeholder="Search Code, Name, Vehicle Plate..." value="<?php echo htmlspecialchars($_GET['search_q'] ?? ''); ?>">
                        </div>
                        <div class="col-md-2">
                            <select name="filter_gate" class="form-select form-select-sm">
                                <option value="">All Gate Posts</option>
                                <?php foreach ($posts_list as $gp): ?>
                                    <option value="<?php echo htmlspecialchars($gp); ?>" <?php echo ($filter_gate === $gp) ? 'selected' : ''; ?>><?php echo htmlspecialchars($gp); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="filter_shift" class="form-select form-select-sm">
                                <option value="">All Shifts</option>
                                <?php foreach ($shifts_list as $sh): ?>
                                    <option value="<?php echo htmlspecialchars($sh); ?>" <?php echo ($filter_shift === $sh) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sh); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="date" name="filter_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filter_date); ?>">
                        </div>
                        <div class="col-md-2 d-flex align-items-center">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="filter_my_only" value="1" id="filterMyOnlySwitch" <?php echo $filter_my_only ? 'checked' : ''; ?>>
                                <label class="form-check-label small fw-semibold" for="filterMyOnlySwitch">Only My Actions</label>
                            </div>
                        </div>
                        <div class="col-md-1 text-end">
                            <button type="submit" class="btn btn-sm btn-primary w-100"><i class="fa-solid fa-filter"></i> Filter</button>
                        </div>
                    </form>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle table-hover mb-0">
                        <thead class="bg-light text-secondary small text-uppercase">
                            <tr>
                                <th>Passcode / Vehicle</th>
                                <th>Visitor &amp; Host</th>
                                <th>Entry Clearance &amp; Shift</th>
                                <th>Exit Clearance &amp; Shift</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($history_visitors && $history_visitors->num_rows > 0): ?>
                                <?php while ($h = $history_visitors->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div class="font-monospace fw-bold text-slate-800"><?php echo htmlspecialchars($h['visitor_code'] ?? 'N/A'); ?></div>
                                            <?php if (!empty($h['vehicle_plate'])): ?>
                                                <span class="badge bg-light text-dark border font-monospace mt-1"><i class="fa-solid fa-car me-1"></i><?php echo htmlspecialchars($h['vehicle_plate']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-slate-800"><?php echo htmlspecialchars($h['name']); ?></div>
                                            <small class="text-muted">Host: <?php echo htmlspecialchars($h['resident_name'] ?? 'N/A'); ?> (Flat <?php echo htmlspecialchars($h['flat_number'] ?? 'N/A'); ?>)</small>
                                        </td>
                                        <td>
                                            <?php if (!empty($h['entry_time'])): ?>
                                                <small class="d-block fw-bold text-slate-800"><?php echo date('d M, h:i A', strtotime($h['entry_time'])); ?></small>
                                                <span class="badge bg-secondary bg-opacity-25 text-slate-700" style="font-size: 0.68rem;">
                                                    <i class="fa-solid fa-shield me-1"></i><?php echo htmlspecialchars($h['entry_shift_name'] ?? 'General Shift'); ?>
                                                </span>
                                                <small class="text-muted d-block" style="font-size: 0.72rem;">Officer: <?php echo htmlspecialchars($h['entry_staff_name'] ?? 'Gate Officer'); ?> (<?php echo htmlspecialchars($h['entry_gate'] ?? 'Main Gate'); ?>)</small>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($h['exit_time'])): ?>
                                                <small class="d-block fw-bold text-slate-800"><?php echo date('d M, h:i A', strtotime($h['exit_time'])); ?></small>
                                                <span class="badge bg-secondary bg-opacity-25 text-slate-700" style="font-size: 0.68rem;">
                                                    <i class="fa-solid fa-shield me-1"></i><?php echo htmlspecialchars($h['exit_shift_name'] ?? 'General Shift'); ?>
                                                </span>
                                                <small class="text-muted d-block" style="font-size: 0.72rem;">Officer: <?php echo htmlspecialchars($h['exit_staff_name'] ?? 'Gate Officer'); ?> (<?php echo htmlspecialchars($h['exit_gate'] ?? 'Main Gate'); ?>)</small>
                                            <?php else: ?>
                                                <span class="text-muted small">Still Inside / Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($h['status'] == 'entered'): ?>
                                                <span class="badge bg-primary">Inside</span>
                                            <?php elseif ($h['status'] == 'checked_out'): ?>
                                                <span class="badge bg-secondary">Checked Out</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark border"><?php echo htmlspecialchars(ucfirst($h['status'])); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="../gate_pass?id=<?php echo $h['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary me-1" title="Print Gate Pass">
                                                <i class="fa-solid fa-print"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-dark" onclick="viewAuditTrail(<?php echo htmlspecialchars(json_encode($h)); ?>)" title="Security Forensics">
                                                <i class="fa-solid fa-fingerprint"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No gate history records match your filter criteria.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ==========================================
     CHECKOUT CONFIRMATION MODAL
     ========================================== -->
<div class="modal fade" id="checkoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form action="security" method="POST">
                <?php echo renderCSRFField(); ?>
                <input type="hidden" name="process_check_out" value="1">
                <input type="hidden" name="visitor_id" id="modalVisitorId">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-right-from-bracket me-2"></i> Process Gate Check-Out</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3 text-slate-700">Approve exit clearance for visitor: <strong id="modalVisitorName"></strong> (<span class="font-monospace fw-bold" id="modalVisitorCode"></span>)</p>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Exit Gate Location</label>
                        <select name="gate_name" class="form-select form-select-sm">
                            <?php foreach ($posts_list as $post_opt): ?>
                                <option value="<?php echo htmlspecialchars($post_opt); ?>" <?php echo ($post_opt === $active_post_name) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($post_opt); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Reason / Departure Type</label>
                        <select name="exit_reason" class="form-select form-select-sm">
                            <option value="Normal Visit Completion">Normal Visit Completion</option>
                            <option value="Host Accompanied Exit">Host Accompanied Exit</option>
                            <option value="Contractor / Service Completed">Contractor / Service Completed</option>
                            <option value="Emergency Evacuation">Emergency Evacuation</option>
                            <option value="Overstay Flagged Clearance">Overstay Flagged Clearance</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Handover / Exit Notes (Optional)</label>
                        <textarea name="exit_notes" class="form-control form-control-sm" rows="2" placeholder="e.g. Returned temporary gate visitor badge..."></textarea>
                    </div>

                    <div class="p-2 rounded-2 bg-light border text-secondary small">
                        <i class="fa-solid fa-shield-check text-teal me-1"></i> Recorded by Officer: <strong><?php echo htmlspecialchars($current_user_name); ?></strong> &bull; Shift: <strong><?php echo htmlspecialchars($active_shift_name); ?></strong>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-danger fw-bold px-4">
                        <i class="fa-solid fa-check me-1"></i> Confirm Clearance &amp; Exit
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==========================================
     SECURITY AUDIT & FORENSICS MODAL
     ========================================== -->
<div class="modal fade" id="securityAuditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-fingerprint me-2 text-teal" style="color: #5eead4;"></i> Gate Pass Chain of Custody &amp; Forensics</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="auditModalBody">
                <!-- Content loaded dynamically via JS -->
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// Clock In / Out Ajax Function
async function quickClockInOut(rosterId, type) {
    let handover = '';
    if (type === 'clock_out') {
        handover = await EstateDialog.prompt({
            title: 'Shift Handover & Clock Out',
            message: "Optional shift handover notes or observations for the next officer:",
            inputType: 'textarea',
            placeholder: 'e.g. Handed over gate keys and visitor logs to incoming guard...',
            confirmText: 'Clock Out'
        });
        if (handover === null) return;
    }

    const formData = new FormData();
    formData.append('action', 'clock_in_out');
    formData.append('roster_id', rosterId);
    formData.append('type', type);
    formData.append('handover_notes', handover || '');

    fetch('../api/roster_query.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                EstateDialog.toast({ type: 'success', message: res.message || 'Attendance status recorded.' });
                setTimeout(() => location.reload(), 1000);
            } else {
                EstateDialog.alert({ title: 'Clock Error', message: res.error || 'Failed to update attendance.', type: 'danger' });
            }
        })
        .catch(err => {
            console.error(err);
            EstateDialog.toast({ type: 'error', message: 'Network error updating attendance.' });
        });
}

// Checkout Modal Handler
function openCheckoutModal(visId, name, code) {
    document.getElementById('modalVisitorId').value = visId;
    document.getElementById('modalVisitorName').innerText = name;
    document.getElementById('modalVisitorCode').innerText = code;
    const modal = new bootstrap.Modal(document.getElementById('checkoutModal'));
    modal.show();
}

// View Full Audit Trail Modal Handler
function viewAuditTrail(v) {
    const body = document.getElementById('auditModalBody');
    const entryTime = v.entry_time ? new Date(v.entry_time).toLocaleString() : 'Not Yet Entered';
    const exitTime = v.exit_time ? new Date(v.exit_time).toLocaleString() : (v.status === 'entered' ? 'Currently Inside' : 'Not Exited');
    const createdAt = v.created_at ? new Date(v.created_at).toLocaleString() : 'N/A';

    body.innerHTML = `
        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
            <div>
                <span class="badge bg-dark font-monospace fs-6">${v.visitor_code || 'N/A'}</span>
                <h5 class="fw-bold mb-0 mt-1">${v.name}</h5>
                <small class="text-muted">Phone: ${v.phone || 'N/A'}</small>
            </div>
            <div class="text-end">
                <span class="badge bg-primary px-3 py-1.5 text-uppercase">${v.status.replace('_', ' ')}</span>
                ${v.vehicle_plate ? `<div class="font-monospace small mt-1"><i class="fa-solid fa-car me-1 text-primary"></i>${v.vehicle_plate}</div>` : ''}
            </div>
        </div>

        <div class="timeline p-2">
            <!-- 1. Pass Creation -->
            <div class="d-flex gap-3 mb-3">
                <div class="text-primary fs-4"><i class="fa-solid fa-ticket"></i></div>
                <div>
                    <strong class="d-block text-slate-800">1. Pass Issued / Pre-registered</strong>
                    <div class="small text-muted">Created: ${createdAt}</div>
                    <div class="small text-secondary">Host Resident: <strong>${v.resident_name || 'Resident'}</strong> (Flat ${v.flat_number || 'N/A'})</div>
                    <div class="small text-secondary">Purpose: ${v.purpose || 'Personal Visit'}</div>
                </div>
            </div>

            <!-- 2. Entry Clearance -->
            <div class="d-flex gap-3 mb-3">
                <div class="text-success fs-4"><i class="fa-solid fa-door-open"></i></div>
                <div>
                    <strong class="d-block text-slate-800">2. Gate Entry Clearance &amp; Duty Stamp</strong>
                    <div class="small text-muted">Cleared At: ${entryTime}</div>
                    <div class="small text-secondary">Processing Officer: <strong>${v.entry_staff_name || 'Gate Officer'}</strong> (Staff ID #${v.entry_processed_by || 'N/A'})</div>
                    <div class="small text-secondary">Guard Duty Shift: <strong>${v.entry_shift_name || 'General Shift'}</strong></div>
                    <div class="small text-secondary">Gate Post: <strong>${v.entry_gate || 'Main Gate'}</strong></div>
                    ${v.vehicle_plate ? `<div class="small text-secondary">Vehicle Reg: <strong>${v.vehicle_plate}</strong></div>` : ''}
                    ${v.guard_notes ? `<div class="small text-muted fst-italic mt-1 bg-light p-2 rounded border"><i class="fa-solid fa-note-sticky text-warning me-1"></i>${v.guard_notes}</div>` : ''}
                </div>
            </div>

            <!-- 3. Exit Clearance -->
            <div class="d-flex gap-3 mb-2">
                <div class="text-danger fs-4"><i class="fa-solid fa-door-closed"></i></div>
                <div>
                    <strong class="d-block text-slate-800">3. Gate Exit Clearance</strong>
                    <div class="small text-muted">Exit Time: ${exitTime}</div>
                    ${v.exit_time ? `
                        <div class="small text-secondary">Processing Officer: <strong>${v.exit_staff_name || 'Gate Officer'}</strong> (Staff ID #${v.exit_processed_by || 'N/A'})</div>
                        <div class="small text-secondary">Guard Duty Shift: <strong>${v.exit_shift_name || 'General Shift'}</strong></div>
                        <div class="small text-secondary">Exit Gate: <strong>${v.exit_gate || 'Main Gate'}</strong></div>
                        <div class="small text-secondary">Departure Reason: <strong>${v.exit_reason || 'Normal Departure'}</strong></div>
                    ` : `<div class="small text-muted fst-italic">Visitor is currently inside the estate. Exit stamp pending.</div>`}
                </div>
            </div>
        </div>
    `;
    const modal = new bootstrap.Modal(document.getElementById('securityAuditModal'));
    modal.show();
}

// Vehicle Live Autocomplete
(function() {
    const input = document.getElementById('vehicleSearchInput');
    const box = document.getElementById('vehicleSuggestions');
    if (!input || !box) return;

    let debounceTimer = null;

    input.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const q = this.value.trim();
        if (q.length < 2) {
            box.style.display = 'none';
            box.innerHTML = '';
            return;
        }

        debounceTimer = setTimeout(() => {
            fetch('../api/search_vehicle.php?mode=suggestions&q=' + encodeURIComponent(q))
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.suggestions && data.suggestions.length > 0) {
                        let html = '';
                        data.suggestions.forEach(v => {
                            html += `
                                <a href="security?search_vehicle=${encodeURIComponent(v.reg_number)}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2 px-3">
                                    <div>
                                        <span class="fw-bold font-monospace text-primary me-2">${v.reg_number}</span>
                                        <span class="badge bg-dark font-monospace me-2">${v.custom_id || ''}</span>
                                        <span class="small text-slate-700">${v.model || 'Vehicle'} (${v.color || 'Car'})</span>
                                    </div>
                                    <div class="text-end small text-muted">
                                        <div><strong>${v.owner_name || 'Resident'}</strong></div>
                                        <div class="text-secondary">Unit ${v.flat_number || 'N/A'} &bull; ${v.building_name || ''}</div>
                                    </div>
                                </a>
                            `;
                        });
                        box.innerHTML = html;
                        box.style.display = 'block';
                    } else {
                        box.style.display = 'none';
                        box.innerHTML = '';
                    }
                })
                .catch(() => {
                    box.style.display = 'none';
                });
        }, 220);
    });

    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !box.contains(e.target)) {
            box.style.display = 'none';
        }
    });
})();
</script>

<?php include 'footer.php'; ?>
