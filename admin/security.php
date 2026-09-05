<?php
// admin/security.php
require_once '../config.php';
require_once '../includes/auth_guard.php';
requirePermission('visitors.view_log');

include '../includes/header.php';
include '../includes/sidebar.php';

$estate_id = get_estate_id();
$user_id = $_SESSION['user_id'];

$message = "";
$error = "";

// -------------------------------------------------------------
// POST ACTIONS: PROCESS ENTRY
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action_type']) && $_POST['action_type'] == 'process_entry') {
    $v_id = intval($_POST['visitor_id']);
    $entry_gate = $conn->real_escape_string($_POST['entry_gate'] ?? 'Main Gate');
    
    $check_v = $conn->query("SELECT * FROM visitors WHERE id = $v_id AND estate_id = $estate_id");
    if ($check_v && $check_v->num_rows > 0) {
        $v_row = $check_v->fetch_assoc();
        if ($v_row['status'] == 'pre_registered') {
            $conn->query("UPDATE visitors SET status = 'entered', entry_time = NOW(), entry_gate = '$entry_gate', entry_processed_by = $user_id WHERE id = $v_id");
            logAudit($conn, "Visitor Entry Recorded", "Security", "Visitor {$v_row['name']} (Code: {$v_row['visitor_code']}) entered via $entry_gate");
            $message = "Entry recorded for visitor '{$v_row['name']}' at $entry_gate!";
        } else {
            $error = "Visitor status is already '{$v_row['status']}'.";
        }
    } else {
        $error = "Visitor not found.";
    }
}

// -------------------------------------------------------------
// POST ACTIONS: WALK-IN REGISTRATION & IMMEDIATE ENTRY
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action_type']) && $_POST['action_type'] == 'walkin_entry') {
    $first_name = $conn->real_escape_string($_POST['first_name']);
    $last_name = $conn->real_escape_string($_POST['last_name']);
    $full_name = trim($first_name . ' ' . $last_name);
    $phone = $conn->real_escape_string($_POST['phone']);
    $resident_id = intval($_POST['resident_id']);
    $purpose = $conn->real_escape_string($_POST['purpose']);
    $entry_gate = $conn->real_escape_string($_POST['entry_gate'] ?? 'Main Gate');
    
    // Fetch Resident flat_id
    $res_info = $conn->query("SELECT flat_id FROM residents WHERE user_id = $resident_id AND estate_id = $estate_id LIMIT 1")->fetch_assoc();
    $flat_id = $res_info['flat_id'] ?? null;
    
    $rand_str = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
    $visitor_code = 'EST-' . $rand_str;
    
    $sql = "INSERT INTO visitors (estate_id, resident_id, flat_id, first_name, last_name, name, phone, purpose, visitor_code, entry_time, entry_gate, entry_processed_by, status) 
            VALUES ($estate_id, $resident_id, " . ($flat_id ? $flat_id : "NULL") . ", '$first_name', '$last_name', '$full_name', '$phone', '$purpose', '$visitor_code', NOW(), '$entry_gate', $user_id, 'entered')";
    
    if ($conn->query($sql)) {
        $inserted_walkin_id = $conn->insert_id;
        logAudit($conn, "Walk-in Visitor Registered", "Security", "Walk-in visitor $full_name registered and checked in at $entry_gate with code: $visitor_code");
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
            $is_confirmed = !empty($v_row['resident_confirmed_at']);
            
            if ($is_confirmed) {
                // Confirmed Exit
                $conn->query("UPDATE visitors SET status = 'checked_out', exit_time = NOW(), exit_gate = '$exit_gate', exit_processed_by = $user_id WHERE id = $v_id");
                logAudit($conn, "Visitor Checked Out", "Security", "Visitor {$v_row['name']} checked out via $exit_gate");
                $message = "Exit approved for confirmed visitor '{$v_row['name']}'!";
            } else {
                // Unconfirmed Exit (Requires Reason)
                if (empty($exit_reason)) {
                    $exit_reason = "Resident not at home";
                }
                $conn->query("UPDATE visitors SET status = 'exited_without_confirmation', exit_time = NOW(), exit_reason = '$exit_reason', exit_gate = '$exit_gate', exit_processed_by = $user_id WHERE id = $v_id");
                logAudit($conn, "Visitor Exited Unconfirmed", "Security", "Visitor {$v_row['name']} exited without confirmation via $exit_gate. Reason: $exit_reason");
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
if ($filter_staff > 0) $where_clauses[] = "(v.entry_processed_by = $filter_staff OR v.exit_processed_by = $filter_staff)";
if (!empty($filter_date_from)) $where_clauses[] = "DATE(v.entry_time) >= '$filter_date_from'";
if (!empty($filter_date_to)) $where_clauses[] = "DATE(v.entry_time) <= '$filter_date_to'";
if (!empty($search_code)) $where_clauses[] = "(v.visitor_code LIKE '%$search_code%' OR v.name LIKE '%$search_code%' OR v.phone LIKE '%$search_code%')";

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
?>

<div class="page-header d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 font-outfit font-weight-700 text-dark mb-1"><i class="fa-solid fa-shield-halved text-primary me-2"></i>Security & Gate Management</h1>
        <p class="text-muted mb-0">Visitor verification, gate entry/exit control, duration tracking, and complete audit trail.</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-primary btn-sm rounded-pill px-3 font-weight-600" data-bs-toggle="modal" data-bs-target="#walkinModal">
            <i class="fa-solid fa-user-plus me-1"></i> Register Walk-in Visitor
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Top Metrics Overview -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 p-3 bg-gradient-primary text-white position-relative overflow-hidden" style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-uppercase font-weight-600 text-muted-light small tracking-wider" style="font-size: 0.75rem; color: #94a3b8;">Currently Inside</div>
                    <div class="h2 font-outfit font-weight-700 mb-0 text-info mt-1"><?= number_format($kpis['currently_inside'] ?? 0) ?></div>
                </div>
                <div class="rounded-circle p-3 bg-dark-subtle text-info">
                    <i class="fa-solid fa-user-clock fs-3"></i>
                </div>
            </div>
            <div class="mt-2 small text-slate-300" style="font-size: 0.8rem; color: #cbd5e1;">Active visitors inside estate</div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-uppercase font-weight-600 text-muted small" style="font-size: 0.75rem;">Total Today</div>
                    <div class="h2 font-outfit font-weight-700 mb-0 text-dark mt-1"><?= number_format($kpis['total_today'] ?? 0) ?></div>
                </div>
                <div class="rounded-circle p-3 bg-light text-primary">
                    <i class="fa-solid fa-door-open fs-3"></i>
                </div>
            </div>
            <div class="mt-2 small text-muted" style="font-size: 0.8rem;">Entered today</div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-uppercase font-weight-600 text-muted small" style="font-size: 0.75rem;">Average Duration</div>
                    <div class="h2 font-outfit font-weight-700 mb-0 text-success mt-1"><?= formatDuration($kpis['avg_duration_minutes'] ?? 0) ?></div>
                </div>
                <div class="rounded-circle p-3 bg-light text-success">
                    <i class="fa-solid fa-stopwatch fs-3"></i>
                </div>
            </div>
            <div class="mt-2 small text-muted" style="font-size: 0.8rem;">Longest: <?= formatDuration($kpis['max_duration_minutes'] ?? 0) ?></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-uppercase font-weight-600 text-muted small" style="font-size: 0.75rem;">Unconfirmed Exits</div>
                    <div class="h2 font-outfit font-weight-700 mb-0 text-warning mt-1"><?= number_format($kpis['unconfirmed_exits_today'] ?? 0) ?></div>
                </div>
                <div class="rounded-circle p-3 bg-light text-warning">
                    <i class="fa-solid fa-user-ninja fs-3"></i>
                </div>
            </div>
            <div class="mt-2 small text-muted" style="font-size: 0.8rem;">Resident absent at exit</div>
        </div>
    </div>
</div>

<!-- Tabs Navigation -->
<ul class="nav nav-pills custom-pills mb-4" id="securityTabs" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?= ($active_tab == 'gate_manager') ? 'active' : '' ?>" href="security?tab=gate_manager">
            <i class="fa-solid fa-gate me-2"></i> Gate Manager Interface
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= ($active_tab == 'audit_logs') ? 'active' : '' ?>" href="security?tab=audit_logs">
            <i class="fa-solid fa-list-check me-2"></i> Full Visitor Audit & Analytics
        </a>
    </li>
</ul>

<?php if ($active_tab == 'gate_manager'): ?>
    <!-- GATE MANAGER INTERFACE -->
    
    <!-- Code Look up & Verification Banner -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 p-4" style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color: white;">
        <div class="row align-items-center">
            <div class="col-md-7">
                <h4 class="font-outfit font-weight-700 mb-2"><i class="fa-solid fa-qrcode me-2"></i> Gate Pass Code Verification</h4>
                <p class="mb-0 text-white-50">Enter visitor's 6-character access pass code (e.g. <strong>EST-7K42P9</strong>) to verify entry or approve exit.</p>
            </div>
            <div class="col-md-5 mt-3 mt-md-0">
                <form method="GET" action="security" class="d-flex gap-2">
                    <input type="hidden" name="tab" value="gate_manager">
                    <input type="text" name="search_code" class="form-control form-control-lg border-0 shadow-none font-monospace fw-bold text-uppercase" placeholder="Enter Code (e.g. EST-7K42P9)" value="<?= htmlspecialchars($search_code) ?>" style="letter-spacing: 2px;">
                    <button type="submit" class="btn btn-dark btn-lg px-4 font-weight-600"><i class="fa-solid fa-search"></i> Verify</button>
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
        <div class="card border-0 shadow-sm rounded-4 mb-4 p-4 bg-white border-start border-4 border-primary">
            <h5 class="font-outfit font-weight-700 text-dark mb-3"><i class="fa-solid fa-id-card text-primary me-2"></i> Verification Result for "<?= htmlspecialchars($search_code) ?>"</h5>
            <?php if ($search_res && $search_res->num_rows > 0): ?>
                <?php $sv = $search_res->fetch_assoc(); ?>
                <div class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <div class="text-muted small text-uppercase font-weight-600">Visitor Name</div>
                        <div class="h5 font-weight-700 text-dark mb-1"><?= htmlspecialchars($sv['name']) ?></div>
                        <div class="text-primary font-monospace font-weight-700"><?= htmlspecialchars($sv['visitor_code']) ?></div>
                        <div class="small text-muted"><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($sv['phone'] ?? 'No Phone') ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small text-uppercase font-weight-600">Visiting Resident & Flat</div>
                        <div class="fw-bold text-dark"><?= htmlspecialchars($sv['resident_name'] ?? 'N/A') ?></div>
                        <div class="small text-muted">Flat: <?= htmlspecialchars($sv['flat_number'] ?? 'N/A') ?> (<?= htmlspecialchars($sv['building_name'] ?? 'Main') ?>)</div>
                        <div class="small text-muted">Purpose: <?= htmlspecialchars($sv['purpose'] ?? 'Personal') ?></div>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <div class="mb-2">
                            <span class="badge rounded-pill bg-<?= $sv['status'] == 'confirmed' ? 'success' : ($sv['status'] == 'entered' ? 'warning' : 'info') ?> px-3 py-2 text-uppercase">
                                Status: <?= str_replace('_', ' ', $sv['status']) ?>
                            </span>
                        </div>
                        <?php if ($sv['status'] == 'pre_registered'): ?>
                            <a href="../gate_pass?id=<?= $sv['id'] ?>" target="_blank" class="btn btn-outline-primary font-weight-600 rounded-pill px-3 me-1">
                                <i class="fa-solid fa-print me-1"></i> Print Pass
                            </a>
                            <form method="POST" class="d-inline-block">
                                <input type="hidden" name="action_type" value="process_entry">
                                <input type="hidden" name="visitor_id" value="<?= $sv['id'] ?>">
                                <input type="hidden" name="entry_gate" value="Main Gate">
                                <button type="submit" class="btn btn-success font-weight-600 rounded-pill px-4">
                                    <i class="fa-solid fa-door-open me-1"></i> Approve Gate Entry
                                </button>
                            </form>
                        <?php elseif (in_array($sv['status'], ['entered', 'confirmed'])): ?>
                            <a href="../gate_pass?id=<?= $sv['id'] ?>" target="_blank" class="btn btn-outline-primary font-weight-600 rounded-pill px-3 me-1">
                                <i class="fa-solid fa-print me-1"></i> Print Pass
                            </a>
                            <button type="button" class="btn btn-danger font-weight-600 rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#exitModal<?= $sv['id'] ?>">
                                <i class="fa-solid fa-door-closed me-1"></i> Process Gate Exit
                            </button>
                        <?php else: ?>
                            <a href="../gate_pass?id=<?= $sv['id'] ?>" target="_blank" class="btn btn-outline-secondary font-weight-600 rounded-pill px-3 me-2">
                                <i class="fa-solid fa-print me-1"></i> Print Pass
                            </a>
                            <div class="text-muted small d-inline-block">This visitor pass is completed or cancelled.</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="text-danger font-weight-600"><i class="fa-solid fa-circle-xmark me-2"></i> No visitor pass found matching code "<?= htmlspecialchars($search_code) ?>".</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Currently Inside Estate Table -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                    <h5 class="font-outfit font-weight-700 mb-0 text-dark">
                        <i class="fa-solid fa-user-clock text-info me-2"></i> Visitors Currently Inside Estate
                    </h5>
                    <span class="badge bg-info rounded-pill px-3"><?= $inside_res ? $inside_res->num_rows : 0 ?> Visitors</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Visitor</th>
                                <th>Resident / Property</th>
                                <th>Entry Time</th>
                                <th>Resident Confirmation</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($inside_res && $inside_res->num_rows > 0): ?>
                                <?php while ($iv = $inside_res->fetch_assoc()): ?>
                                    <?php $is_conf = !empty($iv['resident_confirmed_at']); ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($iv['name']) ?></div>
                                            <div class="font-monospace text-primary small font-weight-700"><?= htmlspecialchars($iv['visitor_code']) ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= htmlspecialchars($iv['resident_name'] ?? 'N/A') ?></div>
                                            <div class="text-muted small">Flat <?= htmlspecialchars($iv['flat_number'] ?? '-') ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-bold"><?= date('h:i A', strtotime($iv['entry_time'])) ?></div>
                                            <div class="text-muted small"><?= formatDuration($iv['minutes_inside']) ?> ago &bull; <?= htmlspecialchars($iv['entry_gate'] ?? 'Main Gate') ?></div>
                                            <div class="small text-primary mt-1" style="font-size:0.75rem; font-weight:600;">
                                                <i class="fa-solid fa-user-shield me-1"></i>In: <?= htmlspecialchars($iv['entry_staff_name'] ?? 'Gate Guard') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($is_conf): ?>
                                                <span class="badge bg-success-subtle text-success border border-success rounded-pill px-2 py-1">
                                                    <i class="fa-solid fa-check-circle me-1"></i> Confirmed (<?= date('h:i A', strtotime($iv['resident_confirmed_at'])) ?>)
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning-subtle text-warning border border-warning rounded-pill px-2 py-1">
                                                    <i class="fa-solid fa-clock me-1"></i> Pending Confirmation
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <a href="../gate_pass?id=<?= $iv['id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm rounded-pill px-3 me-1" title="Print Gate Pass">
                                                <i class="fa-solid fa-print"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-danger btn-sm rounded-pill font-weight-600 px-3" data-bs-toggle="modal" data-bs-target="#exitModal<?= $iv['id'] ?>">
                                                <i class="fa-solid fa-right-from-bracket me-1"></i> Checkout
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Exit Checkout Modal -->
                                    <div class="modal fade" id="exitModal<?= $iv['id'] ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content rounded-4 border-0 shadow">
                                                <form method="POST">
                                                    <input type="hidden" name="action_type" value="process_exit">
                                                    <input type="hidden" name="visitor_id" value="<?= $iv['id'] ?>">
                                                    <div class="modal-header border-bottom">
                                                        <h5 class="modal-title font-outfit font-weight-700"><i class="fa-solid fa-door-closed text-danger me-2"></i> Approve Visitor Gate Exit</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body p-4">
                                                        <div class="bg-light p-3 rounded-3 mb-3">
                                                            <div class="fw-bold text-dark h5 mb-1"><?= htmlspecialchars($iv['name']) ?></div>
                                                            <div class="text-muted small">Code: <strong class="font-monospace text-primary"><?= htmlspecialchars($iv['visitor_code']) ?></strong></div>
                                                            <div class="text-muted small">Resident: <strong><?= htmlspecialchars($iv['resident_name'] ?? 'N/A') ?></strong> (Flat <?= htmlspecialchars($iv['flat_number'] ?? '-') ?>)</div>
                                                            <div class="text-muted small">Entered: <strong><?= date('h:i A', strtotime($iv['entry_time'])) ?></strong></div>
                                                        </div>

                                                        <div class="mb-3">
                                                            <label class="form-label font-weight-600 small">Exit Gate</label>
                                                            <select name="exit_gate" class="form-select">
                                                                <option value="Main Gate">Main Gate</option>
                                                                <option value="North Gate">North Gate</option>
                                                                <option value="South Gate">South Gate</option>
                                                                <option value="Pedestrian Gate">Pedestrian Gate</option>
                                                            </select>
                                                        </div>

                                                        <?php if ($is_conf): ?>
                                                            <div class="alert alert-success d-flex align-items-center mb-0" role="alert">
                                                                <i class="fa-solid fa-circle-check fs-4 me-3 text-success"></i>
                                                                <div>
                                                                    <strong>Resident Confirmed!</strong><br>
                                                                    Resident verified visitor at <?= date('h:i A', strtotime($iv['resident_confirmed_at'])) ?>. Standard checkout will be recorded.
                                                                </div>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="alert alert-warning mb-3" role="alert">
                                                                <i class="fa-solid fa-triangle-exclamation me-1"></i> <strong>Resident Unavailable / Unconfirmed</strong><br>
                                                                This visitor is exiting without resident confirmation. Select the exit reason below:
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label font-weight-600 small">Select Exit Reason</label>
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
                                                    <div class="modal-footer border-top bg-light">
                                                        <button type="button" class="btn btn-secondary rounded-pill font-weight-600" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" class="btn btn-danger rounded-pill font-weight-600 px-4">
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
                                    <td colspan="5" class="text-center py-4 text-muted">No visitors currently inside the estate.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Pre-registered Visitors Awaiting Entry -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="font-outfit font-weight-700 mb-0 text-dark">
                        <i class="fa-solid fa-list-check text-primary me-2"></i> Expected Pre-Registered
                    </h5>
                </div>
                <div class="list-group list-group-flush">
                    <?php if ($prereg_res && $prereg_res->num_rows > 0): ?>
                        <?php while ($pv = $prereg_res->fetch_assoc()): ?>
                            <div class="list-group-item p-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-dark"><?= htmlspecialchars($pv['name']) ?></span>
                                    <span class="font-monospace text-primary small font-weight-700"><?= htmlspecialchars($pv['visitor_code']) ?></span>
                                </div>
                                <div class="small text-muted mb-2">Visiting: <strong><?= htmlspecialchars($pv['resident_name'] ?? 'N/A') ?></strong> (Flat <?= htmlspecialchars($pv['flat_number'] ?? '-') ?>)</div>
                                <div class="d-flex gap-2">
                                    <form method="POST" class="flex-grow-1">
                                        <input type="hidden" name="action_type" value="process_entry">
                                        <input type="hidden" name="visitor_id" value="<?= $pv['id'] ?>">
                                        <input type="hidden" name="entry_gate" value="Main Gate">
                                        <button type="submit" class="btn btn-success btn-sm w-100 rounded-pill font-weight-600">
                                            <i class="fa-solid fa-door-open me-1"></i> Process Gate Entry
                                        </button>
                                    </form>
                                    <a href="../gate_pass?id=<?= $pv['id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm rounded-pill px-3" title="Print Gate Pass">
                                        <i class="fa-solid fa-print"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="p-4 text-center text-muted small">No pending pre-registered visitors.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- FULL AUDIT LOGS & ANALYTICS TAB -->
    
    <!-- Filter Controls Bar -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 p-3 bg-white">
        <form method="GET" action="security" class="row g-3 align-items-end">
            <input type="hidden" name="tab" value="audit_logs">
            
            <div class="col-md-2">
                <label class="form-label small font-weight-600">Resident</label>
                <select name="filter_resident" class="form-select form-select-sm">
                    <option value="0">All Residents</option>
                    <?php if ($residents_list): ?>
                        <?php while ($r = $residents_list->fetch_assoc()): ?>
                            <option value="<?= $r['id'] ?>" <?= ($filter_resident == $r['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($r['name']) ?> (Flat <?= htmlspecialchars($r['flat_number'] ?? '-') ?>)
                            </option>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small font-weight-600">Status</label>
                <select name="filter_status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="checked_out" <?= ($filter_status == 'checked_out') ? 'selected' : '' ?>>Checked Out</option>
                    <option value="exited_without_confirmation" <?= ($filter_status == 'exited_without_confirmation') ? 'selected' : '' ?>>Exited Without Confirmation</option>
                    <option value="confirmed" <?= ($filter_status == 'confirmed') ? 'selected' : '' ?>>Confirmed (Inside)</option>
                    <option value="entered" <?= ($filter_status == 'entered') ? 'selected' : '' ?>>Entered (Pending Conf.)</option>
                    <option value="pre_registered" <?= ($filter_status == 'pre_registered') ? 'selected' : '' ?>>Pre-Registered</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small font-weight-600">Gate</label>
                <select name="filter_gate" class="form-select form-select-sm">
                    <option value="">All Gates</option>
                    <option value="Main Gate" <?= ($filter_gate == 'Main Gate') ? 'selected' : '' ?>>Main Gate</option>
                    <option value="North Gate" <?= ($filter_gate == 'North Gate') ? 'selected' : '' ?>>North Gate</option>
                    <option value="South Gate" <?= ($filter_gate == 'South Gate') ? 'selected' : '' ?>>South Gate</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label small font-weight-600">Gate Staff</label>
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
                <label class="form-label small font-weight-600">Date From</label>
                <input type="date" name="filter_date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date_from) ?>">
            </div>

            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm rounded-pill w-100 font-weight-600"><i class="fa-solid fa-filter me-1"></i> Filter</button>
                <a href="security?tab=audit_logs" class="btn btn-outline-secondary btn-sm rounded-pill font-weight-600"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </form>
    </div>

    <!-- Audit Log Table -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
            <h5 class="font-outfit font-weight-700 mb-0 text-dark"><i class="fa-solid fa-clipboard-list text-primary me-2"></i> Comprehensive Visitor Audit Trail</h5>
            <span class="text-muted small">Showing <?= $audit_res ? $audit_res->num_rows : 0 ?> records</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th>Visitor & Code</th>
                        <th>Resident & Flat</th>
                        <th>Entry Time & Gate</th>
                        <th>Resident Confirmation</th>
                        <th>Exit Time & Gate</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th class="text-end">Details</th>
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
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($av['name']) ?></div>
                                    <div class="font-monospace text-primary small font-weight-700"><?= htmlspecialchars($av['visitor_code'] ?? 'N/A') ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars($av['phone'] ?? '-') ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($av['resident_name'] ?? 'N/A') ?></div>
                                    <div class="small text-muted">Flat <?= htmlspecialchars($av['flat_number'] ?? '-') ?> (<?= htmlspecialchars($av['building_name'] ?? 'Main') ?>)</div>
                                </td>
                                <td>
                                    <?php if ($av['entry_time']): ?>
                                        <div class="fw-bold text-dark"><?= date('d M, h:i A', strtotime($av['entry_time'])) ?></div>
                                        <div class="small text-muted"><i class="fa-solid fa-torii-gate me-1"></i><?= htmlspecialchars($av['entry_gate'] ?? 'Main Gate') ?></div>
                                        <div class="small text-primary mt-1" style="font-size:0.75rem; font-weight:600;">
                                            <i class="fa-solid fa-user-shield me-1"></i>In: <?= htmlspecialchars($av['entry_staff_name'] ?? 'Gate Guard') ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($av['resident_confirmed_at']): ?>
                                        <span class="badge bg-success-subtle text-success border border-success rounded-pill px-2 py-1">
                                            <i class="fa-solid fa-check-circle me-1"></i> <?= date('d M, h:i A', strtotime($av['resident_confirmed_at'])) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary rounded-pill px-2 py-1">
                                            No Confirmation
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($av['exit_time']): ?>
                                        <div class="fw-bold text-dark"><?= date('d M, h:i A', strtotime($av['exit_time'])) ?></div>
                                        <div class="small text-muted"><i class="fa-solid fa-torii-gate me-1"></i><?= htmlspecialchars($av['exit_gate'] ?? 'Main Gate') ?></div>
                                        <div class="small text-danger mt-1" style="font-size:0.75rem; font-weight:600;">
                                            <i class="fa-solid fa-user-shield me-1"></i>Out: <?= htmlspecialchars($av['exit_staff_name'] ?? 'Gate Guard') ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning">Inside Estate</span>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-bold text-dark">
                                    <?= $duration_txt ?>
                                </td>
                                <td>
                                    <?php if ($st == 'checked_out'): ?>
                                        <span class="badge bg-success rounded-pill px-3 py-1">Checked Out</span>
                                    <?php elseif ($st == 'exited_without_confirmation'): ?>
                                        <span class="badge bg-warning text-dark rounded-pill px-3 py-1" title="Reason: <?= htmlspecialchars($av['exit_reason'] ?? 'None') ?>">Exited Unconfirmed</span>
                                    <?php elseif ($st == 'confirmed'): ?>
                                        <span class="badge bg-info rounded-pill px-3 py-1">Confirmed</span>
                                    <?php elseif ($st == 'entered'): ?>
                                        <span class="badge bg-primary rounded-pill px-3 py-1">Inside</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary rounded-pill px-3 py-1"><?= ucfirst($st) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="../gate_pass?id=<?= $av['id'] ?>" target="_blank" class="btn btn-light btn-sm rounded-circle me-1" title="Print Gate Pass">
                                        <i class="fa-solid fa-print text-primary"></i>
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
                                        'confirmed' => $av['resident_confirmed_at'] ? date('d M Y, h:i A', strtotime($av['resident_confirmed_at'])) : 'No Confirmation',
                                        'exit' => $av['exit_time'] ? date('d M Y, h:i A', strtotime($av['exit_time'])) : 'Inside Estate',
                                        'exit_gate' => $av['exit_gate'],
                                        'exit_staff' => $av['exit_staff_name'] ?? 'Security Staff',
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

<!-- Walk-in Visitor Registration Modal -->
<div class="modal fade" id="walkinModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <form method="POST">
                <input type="hidden" name="action_type" value="walkin_entry">
                <div class="modal-header border-bottom">
                    <h5 class="modal-title font-outfit font-weight-700"><i class="fa-solid fa-user-plus text-primary me-2"></i> Register Walk-in Visitor at Gate</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label font-weight-600 small">First Name</label>
                            <input type="text" name="first_name" class="form-control" placeholder="e.g. Michael" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label font-weight-600 small">Last Name</label>
                            <input type="text" name="last_name" class="form-control" placeholder="e.g. Adebayo" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label font-weight-600 small">Phone Number</label>
                            <input type="tel" name="phone" class="form-control" placeholder="e.g. 08012345678" required>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label font-weight-600 small">Visiting Resident</label>
                            <select name="resident_id" class="form-select" required>
                                <option value="">Select Resident being visited...</option>
                                <?php if ($residents_list): ?>
                                    <?php $residents_list->data_seek(0); ?>
                                    <?php while ($r = $residents_list->fetch_assoc()): ?>
                                        <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?> (Flat <?= htmlspecialchars($r['flat_number'] ?? '-') ?>)</option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label font-weight-600 small">Purpose of Visit</label>
                            <select name="purpose" class="form-select" required>
                                <option value="Personal Visit / Family">Personal Visit / Family</option>
                                <option value="Delivery / Logistics">Delivery / Logistics</option>
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
                    </div>
                </div>
                <div class="modal-footer border-top bg-light">
                    <button type="button" class="btn btn-secondary rounded-pill font-weight-600" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill font-weight-600 px-4">
                        <i class="fa-solid fa-check me-1"></i> Register & Approve Entry
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
                        <span class="text-muted small font-weight-600">RESIDENT CONFIRMATION:</span>
                        <span class="fw-bold text-success" id="aConfirmed">-</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small font-weight-600">EXIT:</span>
                        <span class="fw-bold text-dark" id="aExit">-</span>
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
    document.getElementById('aConfirmed').innerText = data.confirmed;
    document.getElementById('aExit').innerText = data.exit + ' (' + (data.exit_gate || 'Main Gate') + ')';
    document.getElementById('aDuration').innerText = data.duration;
    document.getElementById('aStatus').innerText = data.status;
    
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
