<?php
// api/incident_query.php
// Enterprise Security Incident & Occurrence Book (OB Logbook) API Engine

header('Content-Type: application/json');

require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/emergency_roster_init.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthenticated session']);
    exit;
}

$estate_id = get_estate_id();
$user_id = intval($_SESSION['user_id']);
$user_role = $_SESSION['role'] ?? 'staff';
$user_name = $_SESSION['name'] ?? 'Staff User';
$isAdminOrManager = in_array($user_role, ['superadmin', 'admin', 'manager']);

$action = $_GET['action'] ?? ($_POST['action'] ?? 'fetch_incidents');

// ==============================================================
// 1. FETCH INCIDENTS & OCCURRENCE BOOK FEED
// ==============================================================
if ($action === 'fetch_incidents') {
    $where = "si.estate_id = $estate_id";

    // Date range filter
    if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) {
        $st = $conn->real_escape_string($_GET['start_date']) . ' 00:00:00';
        $et = $conn->real_escape_string($_GET['end_date']) . ' 23:59:59';
        $where .= " AND si.incident_datetime BETWEEN '$st' AND '$et'";
    }

    // Type filter
    if (!empty($_GET['incident_type'])) {
        $type = $conn->real_escape_string($_GET['incident_type']);
        $where .= " AND si.incident_type = '$type'";
    }

    // Severity filter
    if (!empty($_GET['severity'])) {
        $sev = $conn->real_escape_string($_GET['severity']);
        $where .= " AND si.severity = '$sev'";
    }

    // Status filter
    if (!empty($_GET['status'])) {
        $stt = $conn->real_escape_string($_GET['status']);
        $where .= " AND si.status = '$stt'";
    }

    // Entity type filter
    if (!empty($_GET['entity_type'])) {
        $ent = $conn->real_escape_string($_GET['entity_type']);
        $where .= " AND si.entity_type = '$ent'";
    }

    // Flagged only
    if (isset($_GET['flagged_only']) && $_GET['flagged_only'] == '1') {
        $where .= " AND si.is_flagged_bad_behavior = 1";
    }

    // Search query
    if (!empty($_GET['search'])) {
        $q = $conn->real_escape_string(trim($_GET['search']));
        $where .= " AND (
            si.incident_ref LIKE '%$q%' 
            OR si.title LIKE '%$q%' 
            OR si.description LIKE '%$q%' 
            OR si.location_or_post LIKE '%$q%' 
            OR si.target_resident_name LIKE '%$q%' 
            OR si.target_visitor_name LIKE '%$q%' 
            OR si.vehicle_reg_plate LIKE '%$q%' 
            OR si.reporter_name LIKE '%$q%'
        )";
    }

    // KPIs Query
    $kpi_res = $conn->query("
        SELECT 
            COUNT(*) as total_count,
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count,
            SUM(CASE WHEN status = 'investigating' THEN 1 ELSE 0 END) as investigating_count,
            SUM(CASE WHEN status IN ('resolved', 'dismissed') THEN 1 ELSE 0 END) as resolved_count,
            SUM(CASE WHEN is_flagged_bad_behavior = 1 THEN 1 ELSE 0 END) as flagged_count,
            SUM(CASE WHEN severity IN ('high', 'critical') THEN 1 ELSE 0 END) as critical_count
        FROM security_incidents
        WHERE estate_id = $estate_id
    ");
    $kpis = $kpi_res ? $kpi_res->fetch_assoc() : [];

    // Main records query
    $sql = "SELECT si.*, 
                   u.name as investigator_name,
                   rep.phone as reporter_phone
            FROM security_incidents si
            LEFT JOIN users u ON si.investigated_by = u.id
            LEFT JOIN users rep ON si.reporter_id = rep.id
            WHERE $where
            ORDER BY si.incident_datetime DESC, si.id DESC";

    $res = $conn->query($sql);
    $incidents = [];

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['formatted_datetime'] = date('M d, Y &bull; h:i A', strtotime($row['incident_datetime']));
            $row['formatted_date_short'] = date('M d, Y', strtotime($row['incident_datetime']));
            $row['formatted_time_short'] = date('h:i A', strtotime($row['incident_datetime']));
            $incidents[] = $row;
        }
    }

    echo json_encode([
        'success' => true,
        'kpis' => [
            'total' => intval($kpis['total_count'] ?? 0),
            'open' => intval($kpis['open_count'] ?? 0),
            'investigating' => intval($kpis['investigating_count'] ?? 0),
            'resolved' => intval($kpis['resolved_count'] ?? 0),
            'flagged' => intval($kpis['flagged_count'] ?? 0),
            'critical' => intval($kpis['critical_count'] ?? 0)
        ],
        'count' => count($incidents),
        'incidents' => $incidents
    ]);
    exit;
}

// ==============================================================
// 2. SAVE / REGISTER SECURITY INCIDENT (Staff & Admin)
// ==============================================================
if ($action === 'save_incident' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $inc_id = intval($_POST['incident_id'] ?? 0);
    $title = $conn->real_escape_string(trim($_POST['title'] ?? ''));
    $incident_type = $conn->real_escape_string(trim($_POST['incident_type'] ?? 'general_occurrence'));
    $severity = $conn->real_escape_string(trim($_POST['severity'] ?? 'medium'));
    $location = $conn->real_escape_string(trim($_POST['location_or_post'] ?? 'Main Entrance Gate'));
    $inc_dt = trim($_POST['incident_datetime'] ?? date('Y-m-d H:i:s'));
    $entity_type = $conn->real_escape_string(trim($_POST['entity_type'] ?? 'resident'));
    
    $resident_id = !empty($_POST['target_resident_id']) ? intval($_POST['target_resident_id']) : 'NULL';
    $resident_name = $conn->real_escape_string(trim($_POST['target_resident_name'] ?? ''));
    $unit_address = $conn->real_escape_string(trim($_POST['target_unit_or_address'] ?? ''));
    
    $visitor_name = $conn->real_escape_string(trim($_POST['target_visitor_name'] ?? ''));
    $visitor_phone = $conn->real_escape_string(trim($_POST['target_visitor_phone'] ?? ''));
    $pass_code = $conn->real_escape_string(trim($_POST['target_pass_code'] ?? ''));
    $vehicle_plate = strtoupper($conn->real_escape_string(trim($_POST['vehicle_reg_plate'] ?? '')));

    $is_flagged = isset($_POST['is_flagged_bad_behavior']) && ($_POST['is_flagged_bad_behavior'] == '1' || $_POST['is_flagged_bad_behavior'] === 'true') ? 1 : 0;
    $flag_reason = $conn->real_escape_string(trim($_POST['flag_reason'] ?? ''));
    $blacklist_rec = isset($_POST['blacklist_recommended']) && ($_POST['blacklist_recommended'] == '1' || $_POST['blacklist_recommended'] === 'true') ? 1 : 0;

    $description = $conn->real_escape_string(trim($_POST['description'] ?? ''));
    $action_taken = $conn->real_escape_string(trim($_POST['immediate_action_taken'] ?? ''));

    if (empty($title) || empty($description)) {
        echo json_encode(['success' => false, 'error' => 'Please provide an incident title and detailed description.']);
        exit;
    }

    // Auto-fill resident name if resident_id provided
    if ($resident_id !== 'NULL' && empty($resident_name)) {
        $u_chk = $conn->query("SELECT name FROM users WHERE id = $resident_id LIMIT 1")->fetch_assoc();
        if ($u_chk) $resident_name = $conn->real_escape_string($u_chk['name']);
    }

    // Handle evidence photo upload
    $evidence_path = null;
    if (isset($_FILES['evidence_image']) && $_FILES['evidence_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['evidence_image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];

        if (in_array($ext, $allowed) && $file['size'] <= 10 * 1024 * 1024) {
            $upload_dir = '../uploads/incidents/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $filename = 'evidence_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            $dest = $upload_dir . $filename;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $evidence_path = 'uploads/incidents/' . $filename;
            }
        }
    }

    // Generate reference code if new
    if ($inc_id === 0) {
        $year = date('Y');
        $seq_chk = $conn->query("SELECT COUNT(*) as c FROM security_incidents WHERE estate_id = $estate_id AND YEAR(created_at) = $year")->fetch_assoc()['c'] ?? 0;
        $inc_ref = 'INC-' . $year . '-' . str_pad($seq_chk + 1, 4, '0', STR_PAD_LEFT);

        $evidence_sql_val = $evidence_path ? "'$evidence_path'" : "NULL";
        $rep_name_safe = $conn->real_escape_string($user_name);
        $rep_role_safe = $conn->real_escape_string($user_role);

        $sql = "INSERT INTO security_incidents (
                    estate_id, incident_ref, reporter_id, reporter_name, reporter_role,
                    incident_type, severity, location_or_post, incident_datetime,
                    entity_type, target_resident_id, target_resident_name, target_unit_or_address,
                    target_visitor_name, target_visitor_phone, target_pass_code, vehicle_reg_plate,
                    is_flagged_bad_behavior, flag_reason, blacklist_recommended,
                    title, description, immediate_action_taken, evidence_image_path, status
                ) VALUES (
                    $estate_id, '$inc_ref', $user_id, '$rep_name_safe', '$rep_role_safe',
                    '$incident_type', '$severity', '$location', '$inc_dt',
                    '$entity_type', $resident_id, '$resident_name', '$unit_address',
                    '$visitor_name', '$visitor_phone', '$pass_code', '$vehicle_plate',
                    $is_flagged, '$flag_reason', $blacklist_rec,
                    '$title', '$description', '$action_taken', $evidence_sql_val, 'open'
                )";

        if ($conn->query($sql)) {
            $inserted_id = $conn->insert_id;
            logAudit($conn, "Security Incident Registered", "Security Logbook", "Created incident $inc_ref: '$title' ($severity severity, flagged: " . ($is_flagged ? 'YES' : 'NO') . ")");
            echo json_encode([
                'success' => true,
                'message' => "Incident $inc_ref registered successfully in the Security Occurrence Book!",
                'incident_id' => $inserted_id,
                'incident_ref' => $inc_ref
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
        }
    } else {
        // Update existing incident
        $img_update = $evidence_path ? ", evidence_image_path = '$evidence_path'" : "";
        $sql = "UPDATE security_incidents SET 
                    title = '$title',
                    incident_type = '$incident_type',
                    severity = '$severity',
                    location_or_post = '$location',
                    incident_datetime = '$inc_dt',
                    entity_type = '$entity_type',
                    target_resident_id = $resident_id,
                    target_resident_name = '$resident_name',
                    target_unit_or_address = '$unit_address',
                    target_visitor_name = '$visitor_name',
                    target_visitor_phone = '$visitor_phone',
                    target_pass_code = '$pass_code',
                    vehicle_reg_plate = '$vehicle_plate',
                    is_flagged_bad_behavior = $is_flagged,
                    flag_reason = '$flag_reason',
                    blacklist_recommended = $blacklist_rec,
                    description = '$description',
                    immediate_action_taken = '$action_taken'
                    $img_update
                WHERE id = $inc_id AND estate_id = $estate_id";

        if ($conn->query($sql)) {
            logAudit($conn, "Security Incident Updated", "Security Logbook", "Updated occurrence entry #$inc_id: '$title'");
            echo json_encode(['success' => true, 'message' => "Incident #$inc_id updated successfully."]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Update failed: ' . $conn->error]);
        }
    }
    exit;
}

// ==============================================================
// 3. UPDATE INVESTIGATION STATUS & RESOLUTION
// ==============================================================
if ($action === 'update_incident_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $inc_id = intval($_POST['incident_id'] ?? 0);
    $status = $conn->real_escape_string(trim($_POST['status'] ?? ''));
    $resolution_notes = $conn->real_escape_string(trim($_POST['resolution_notes'] ?? ''));
    $investigator_id = !empty($_POST['investigator_id']) ? intval($_POST['investigator_id']) : $user_id;

    $valid_statuses = ['open', 'investigating', 'resolved', 'escalated_police', 'escalated_mgmt', 'dismissed'];

    if (!$inc_id || !in_array($status, $valid_statuses)) {
        echo json_encode(['success' => false, 'error' => 'Invalid status update parameters']);
        exit;
    }

    $resolved_at_sql = in_array($status, ['resolved', 'dismissed']) ? "NOW()" : "NULL";

    $sql = "UPDATE security_incidents SET 
                status = '$status',
                investigated_by = $investigator_id,
                resolution_notes = IF('$resolution_notes' != '', '$resolution_notes', resolution_notes),
                resolved_at = $resolved_at_sql
            WHERE id = $inc_id AND estate_id = $estate_id";

    if ($conn->query($sql)) {
        logAudit($conn, "Incident Status Changed", "Security Forensics", "Updated incident #$inc_id status to '$status'");
        echo json_encode([
            'success' => true,
            'message' => "Incident status updated to '" . strtoupper(str_replace('_', ' ', $status)) . "' successfully!"
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
    }
    exit;
}

// ==============================================================
// 4. FLAGGED BAD BEHAVIOR & BLACKLIST WATCHLIST
// ==============================================================
if ($action === 'flagged_entities') {
    $sql = "SELECT 
                si.entity_type,
                COALESCE(si.target_resident_name, si.target_visitor_name, si.vehicle_reg_plate, 'Unknown Entity') as entity_name,
                si.target_unit_or_address,
                si.vehicle_reg_plate,
                si.target_visitor_phone,
                COUNT(*) as incident_count,
                MAX(si.incident_datetime) as last_incident_date,
                GROUP_CONCAT(DISTINCT si.flag_reason SEPARATOR ' | ') as all_reasons,
                MAX(si.blacklist_recommended) as is_blacklist_rec
            FROM security_incidents si
            WHERE si.estate_id = $estate_id AND si.is_flagged_bad_behavior = 1
            GROUP BY si.entity_type, entity_name, si.target_unit_or_address, si.vehicle_reg_plate, si.target_visitor_phone
            ORDER BY incident_count DESC, last_incident_date DESC";

    $res = $conn->query($sql);
    $flagged = [];

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['formatted_last_date'] = date('M d, Y', strtotime($row['last_incident_date']));
            $flagged[] = $row;
        }
    }

    echo json_encode([
        'success' => true,
        'total_flagged' => count($flagged),
        'entities' => $flagged
    ]);
    exit;
}

// ==============================================================
// 5. DELETE INCIDENT (Admin Only)
// ==============================================================
if ($action === 'delete_incident' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdminOrManager) {
        echo json_encode(['success' => false, 'error' => 'Admin permission required to remove occurrence entries']);
        exit;
    }

    $inc_id = intval($_POST['incident_id'] ?? 0);
    if (!$inc_id) {
        echo json_encode(['success' => false, 'error' => 'Invalid incident ID']);
        exit;
    }

    $del = $conn->query("DELETE FROM security_incidents WHERE id = $inc_id AND estate_id = $estate_id");
    if ($del) {
        logAudit($conn, "Security Incident Deleted", "Security Logbook", "Permanently removed incident #$inc_id");
        echo json_encode(['success' => true, 'message' => "Incident #$inc_id deleted successfully."]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Delete failed: ' . $conn->error]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid API action']);
