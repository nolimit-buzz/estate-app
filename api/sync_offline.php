<?php
// api/sync_offline.php - Guard Terminal Offline Bundle & Synchronization Endpoint
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/AfricasTalking.php';
require_once __DIR__ . '/../includes/Mailer.php';

header('Content-Type: application/json; charset=UTF-8');

// Ensure database tables
EstateAfricasTalking::ensureDatabaseTables($conn);

$estate_id = function_exists('get_estate_id') ? get_estate_id() : 1;

// Allow session authentication or guard token
$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? '';

// -----------------------------------------------------------------------------
// 1. GET METHOD: Download Offline Gate Bundle (Pre-caching for Zero Internet)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 1. Fetch Active Gate Passes (Pre-registered or currently inside)
    $visSql = "SELECT v.id, v.visitor_code, v.name, v.phone, v.status, v.purpose,
                      v.expected_arrival, v.entry_time, v.vehicle_plate,
                      f.number as flat_number, b.name as building_name, u.name as resident_name, u.phone as res_phone
               FROM visitors v
               LEFT JOIN flats f ON v.flat_id = f.id
               LEFT JOIN buildings b ON f.building_id = b.id
               LEFT JOIN residents r ON v.resident_id = r.id
               LEFT JOIN users u ON r.user_id = u.id
               WHERE v.estate_id = $estate_id 
                 AND (
                     v.status IN ('pre_registered', 'confirmed', 'entered')
                     OR v.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 )
               ORDER BY v.id DESC LIMIT 500";
    $visRes = $conn->query($visSql);
    $visitors = [];
    if ($visRes) {
        while ($vr = $visRes->fetch_assoc()) {
            $visitors[] = $vr;
        }
    }

    // 2. Fetch Registered Resident Vehicles
    $vehSql = "SELECT v.id, v.reg_number as plate_number, v.model, v.color, v.type, v.sticker_number,
                      f.number as flat_number, b.name as building_name
               FROM vehicles v
               LEFT JOIN flats f ON v.flat_id = f.id
               LEFT JOIN buildings b ON f.building_id = b.id
               WHERE v.estate_id = $estate_id LIMIT 1000";
    $vehRes = $conn->query($vehSql);
    $vehicles = [];
    if ($vehRes) {
        while ($ve = $vehRes->fetch_assoc()) {
            $vehicles[] = $ve;
        }
    }

    // 3. Security Posts / Gates
    $postRes = $conn->query("SELECT post_name FROM security_posts WHERE estate_id = $estate_id AND status = 'active'");
    $gates = [];
    if ($postRes) {
        while ($gp = $postRes->fetch_assoc()) $gates[] = $gp['post_name'];
    }
    if (empty($gates)) $gates = ['Main Gate', 'North Gate', 'South Gate'];

    // 4. Resident Lookup Directory (for offline pass generation)
    $resSql = "SELECT r.id as resident_id, r.user_id, u.name as resident_name, u.phone as resident_phone,
                      f.id as flat_id, f.number as flat_number, b.name as building_name
               FROM residents r
               JOIN users u ON r.user_id = u.id
               LEFT JOIN flats f ON r.flat_id = f.id
               LEFT JOIN buildings b ON f.building_id = b.id
               WHERE r.estate_id = $estate_id AND r.status = 'active'
               ORDER BY u.name ASC LIMIT 1000";
    $resLookup = $conn->query($resSql);
    $residents = [];
    if ($resLookup) {
        while ($rr = $resLookup->fetch_assoc()) {
            $residents[] = $rr;
        }
    }

    echo json_encode([
        'status' => 'success',
        'server_time' => date('Y-m-d H:i:s'),
        'estate_id' => $estate_id,
        'visitors_count' => count($visitors),
        'vehicles_count' => count($vehicles),
        'residents_count' => count($residents),
        'bundle' => [
            'visitors' => $visitors,
            'vehicles' => $vehicles,
            'residents' => $residents,
            'gates' => $gates
        ]
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// 2. POST METHOD: Upload Queued Offline Actions to Central Database
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    if (!$payload || !isset($payload['events']) || !is_array($payload['events'])) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid JSON payload. Expected array of offline events.'
        ]);
        exit;
    }

    $terminal_id = $conn->real_escape_string($payload['terminal_id'] ?? 'gate_terminal_1');
    $guard_user_id = intval($payload['guard_user_id'] ?? $user_id);
    $events = $payload['events'];

    $synced_count = 0;
    $conflict_count = 0;
    $results = [];

    foreach ($events as $evt) {
        $action = strtolower(trim($evt['action'] ?? 'check_in'));
        $code = strtoupper(trim($evt['visitor_code'] ?? ''));
        $offlineTime = !empty($evt['offline_timestamp']) ? $conn->real_escape_string($evt['offline_timestamp']) : date('Y-m-d H:i:s');
        $gate = $conn->real_escape_string($evt['entry_gate'] ?? ($evt['gate_name'] ?? 'Main Gate'));
        $plate = $conn->real_escape_string(strtoupper(trim($evt['vehicle_plate'] ?? '')));
        $notes = $conn->real_escape_string(trim($evt['guard_notes'] ?? 'Synced from offline terminal'));

        if (empty($code)) {
            $conflict_count++;
            $results[] = ['code' => $code, 'status' => 'skipped', 'reason' => 'Empty visitor code'];
            continue;
        }

        // Handle Offline Pass Generation (create_pass / offline_walkin)
        if ($action === 'create_pass' || $action === 'offline_walkin') {
            $codeEsc = $conn->real_escape_string($code);
            $vRes = $conn->query("SELECT id FROM visitors WHERE estate_id = $estate_id AND (visitor_code = '$codeEsc' OR id = " . intval($code) . ") LIMIT 1");
            if ($vRes && $vRes->num_rows > 0) {
                $synced_count++;
                $results[] = ['code' => $code, 'status' => 'synced', 'note' => 'Already inserted in central database'];
                continue;
            }

            $visName = $conn->real_escape_string(trim($evt['name'] ?? 'Walk-in Visitor'));
            $visPhone = $conn->real_escape_string(trim($evt['phone'] ?? ''));
            $resId = intval($evt['resident_id'] ?? 0);
            $flatId = intval($evt['flat_id'] ?? 0);
            $purpose = $conn->real_escape_string(trim($evt['purpose'] ?? 'Offline Gate Entry'));
            $visStatus = !empty($evt['status']) ? $conn->real_escape_string($evt['status']) : 'entered';

            if ($resId > 0 && empty($flatId)) {
                $rf_res = $conn->query("SELECT flat_id FROM residents WHERE user_id = $resId OR id = $resId LIMIT 1");
                if ($rf_res && $rf_row = $rf_res->fetch_assoc()) {
                    $flatId = intval($rf_row['flat_id'] ?? 0);
                }
            }

            $insSql = "INSERT INTO visitors (estate_id, resident_id, flat_id, name, phone, purpose, visitor_code, status, entry_time, entry_gate, vehicle_plate, guard_notes, entry_processed_by, created_at)
                       VALUES ($estate_id, " . ($resId ?: "NULL") . ", " . ($flatId ?: "NULL") . ", '$visName', '$visPhone', '$purpose', '$codeEsc', '$visStatus', '$offlineTime', '$gate', " . (!empty($plate) ? "'$plate'" : "NULL") . ", '$notes', " . ($guard_user_id ?: "NULL") . ", '$offlineTime')";

            if ($conn->query($insSql)) {
                $newVId = $conn->insert_id;
                $synced_count++;
                $conn->query("INSERT INTO offline_sync_logs (estate_id, terminal_id, guard_user_id, action_type, visitor_id, visitor_code, offline_timestamp, sync_status, details)
                              VALUES ($estate_id, '$terminal_id', " . ($guard_user_id ?: "NULL") . ", '$action', $newVId, '$codeEsc', '$offlineTime', 'synced', 'Offline generated pass successfully synced')");

                if ($newVId > 0) {
                    EstateMailer::sendVisitorArrivalAlert($conn, $newVId, $gate);
                }
                logAudit($conn, "Offline Pass Synced", "Security", "Offline pass $code for visitor '$visName' synced from terminal $terminal_id.");
                $results[] = ['code' => $code, 'status' => 'synced', 'visitor_name' => $visName];
            } else {
                $conflict_count++;
                $results[] = ['code' => $code, 'status' => 'error', 'reason' => $conn->error];
            }
            continue;
        }

        $codeEsc = $conn->real_escape_string($code);
        $vRes = $conn->query("SELECT * FROM visitors WHERE estate_id = $estate_id AND (visitor_code = '$codeEsc' OR id = " . intval($code) . ") LIMIT 1");

        if (!$vRes || $vRes->num_rows === 0) {
            $conflict_count++;
            // Log as sync conflict
            $conn->query("INSERT INTO offline_sync_logs (estate_id, terminal_id, guard_user_id, action_type, visitor_code, offline_timestamp, sync_status, details)
                          VALUES ($estate_id, '$terminal_id', " . ($guard_user_id ?: "NULL") . ", '$action', '$codeEsc', '$offlineTime', 'conflict', 'Passcode does not exist in central DB')");
            $results[] = ['code' => $code, 'status' => 'conflict', 'reason' => 'Passcode not found in central database'];
            continue;
        }

        $vis = $vRes->fetch_assoc();
        $vId = intval($vis['id']);

        if ($action === 'check_in') {
            // If already entered via USSD or online, acknowledge as already entered
            if ($vis['status'] === 'entered') {
                $conn->query("INSERT INTO offline_sync_logs (estate_id, terminal_id, guard_user_id, action_type, visitor_id, visitor_code, offline_timestamp, sync_status, details)
                              VALUES ($estate_id, '$terminal_id', " . ($guard_user_id ?: "NULL") . ", '$action', $vId, '$codeEsc', '$offlineTime', 'synced', 'Visitor was already entered via USSD; timestamp unified.')");
                $synced_count++;
                $results[] = ['code' => $code, 'status' => 'synced', 'note' => 'Already entered in cloud; state reconciled'];
                continue;
            }

            $updateSql = "UPDATE visitors SET 
                          status = 'entered', 
                          entry_time = '$offlineTime', 
                          entry_processed_by = " . ($guard_user_id ?: "NULL") . ", 
                          entry_gate = '$gate',
                          vehicle_plate = " . (!empty($plate) ? "'$plate'" : "vehicle_plate") . ",
                          guard_notes = CONCAT(COALESCE(guard_notes, ''), ' [Offline Synced: $notes]')
                          WHERE id = $vId AND estate_id = $estate_id";

            if ($conn->query($updateSql)) {
                $synced_count++;
                $conn->query("INSERT INTO offline_sync_logs (estate_id, terminal_id, guard_user_id, action_type, visitor_id, visitor_code, offline_timestamp, sync_status, details)
                              VALUES ($estate_id, '$terminal_id', " . ($guard_user_id ?: "NULL") . ", '$action', $vId, '$codeEsc', '$offlineTime', 'synced', 'Check-in processed from offline queue')");

                // Send email alert to resident if not already notified
                EstateMailer::sendVisitorArrivalAlert($conn, $vId, $gate);
                logAudit($conn, "Offline Check-In Synced", "Security", "Offline check-in synced for {$vis['name']} (Code: $code). Entry occurred at $offlineTime.");
                $results[] = ['code' => $code, 'status' => 'synced', 'visitor_name' => $vis['name']];
            } else {
                $conflict_count++;
                $results[] = ['code' => $code, 'status' => 'error', 'reason' => $conn->error];
            }
        } 
        elseif ($action === 'check_out') {
            $updateSql = "UPDATE visitors SET 
                          status = 'checked_out', 
                          exit_time = '$offlineTime', 
                          exit_processed_by = " . ($guard_user_id ?: "NULL") . ", 
                          exit_gate = '$gate',
                          exit_reason = 'Offline Exit Record'
                          WHERE id = $vId AND estate_id = $estate_id";

            if ($conn->query($updateSql)) {
                $synced_count++;
                $conn->query("INSERT INTO offline_sync_logs (estate_id, terminal_id, guard_user_id, action_type, visitor_id, visitor_code, offline_timestamp, sync_status, details)
                              VALUES ($estate_id, '$terminal_id', " . ($guard_user_id ?: "NULL") . ", '$action', $vId, '$codeEsc', '$offlineTime', 'synced', 'Check-out processed from offline queue')");
                logAudit($conn, "Offline Check-Out Synced", "Security", "Offline check-out synced for {$vis['name']} (Code: $code). Exit occurred at $offlineTime.");
                $results[] = ['code' => $code, 'status' => 'synced', 'visitor_name' => $vis['name']];
            } else {
                $conflict_count++;
                $results[] = ['code' => $code, 'status' => 'error', 'reason' => $conn->error];
            }
        }
    }

    // Return sync confirmation and fresh visitor dataset (which includes passes generated via USSD during outage)
    $freshRes = $conn->query("SELECT v.id, v.visitor_code, v.name, v.phone, v.status, v.entry_time, v.exit_time,
                                     f.number as flat_number, b.name as building_name, u.name as resident_name
                              FROM visitors v
                              LEFT JOIN flats f ON v.flat_id = f.id
                              LEFT JOIN buildings b ON f.building_id = b.id
                              LEFT JOIN residents r ON v.resident_id = r.id
                              LEFT JOIN users u ON r.user_id = u.id
                              WHERE v.estate_id = $estate_id 
                                AND (v.status IN ('pre_registered', 'confirmed', 'entered') OR v.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR))
                              ORDER BY v.id DESC LIMIT 500");
    $freshVisitors = [];
    if ($freshRes) {
        while ($fr = $freshRes->fetch_assoc()) $freshVisitors[] = $fr;
    }

    echo json_encode([
        'status' => 'success',
        'message' => "Synchronized $synced_count offline events successfully.",
        'synced_count' => $synced_count,
        'conflict_count' => $conflict_count,
        'processed_events' => $results,
        'latest_visitors' => $freshVisitors
    ]);
    exit;
}
