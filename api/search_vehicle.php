<?php
// api/search_vehicle.php - Instant Security Gate Vehicle Clearance & Search Endpoint
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/vehicle_helper.php';

header('Content-Type: application/json; charset=utf-8');

// Ensure logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$estate_id = get_estate_id();
$user_id = intval($_SESSION['user_id']);

// Handle Log Movement POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'log_movement') {
    $vehicle_id = intval($_POST['vehicle_id'] ?? 0);
    $direction = ($_POST['direction'] ?? '') === 'exit' ? 'exit' : 'entry';
    $gate_name = trim($_POST['gate_name'] ?? 'Main Gate');
    $shift_name = trim($_POST['shift_name'] ?? 'General Duty');
    $notes = trim($_POST['notes'] ?? '');

    if ($vehicle_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid vehicle ID']);
        exit;
    }

    $ok = recordVehicleGateLog($conn, $estate_id, $vehicle_id, $direction, $gate_name, $shift_name, $user_id, $notes);
    if ($ok) {
        echo json_encode([
            'success' => true, 
            'message' => "Vehicle " . strtoupper($direction) . " recorded at $gate_name under $shift_name successfully."
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not record vehicle gate movement.']);
    }
    exit;
}

// Handle Query GET / POST
$q = trim($_GET['q'] ?? ($_POST['q'] ?? ''));

if (empty($q)) {
    echo json_encode(['success' => false, 'error' => 'Query string is required', 'data' => null]);
    exit;
}

// Check if autocomplete requested
$mode = $_GET['mode'] ?? 'dossier'; // 'dossier' or 'suggestions'

if ($mode === 'suggestions') {
    $q_escaped = $conn->real_escape_string(strtoupper($q));
    $sql = "SELECT v.id, v.reg_number, v.custom_id, v.model, v.type, v.color,
                   f.number as flat_number, b.name as building_name,
                   u.name as owner_name, u.phone as owner_phone
            FROM vehicles v
            LEFT JOIN flats f ON v.flat_id = f.id
            LEFT JOIN buildings b ON f.building_id = b.id
            LEFT JOIN residents r ON r.flat_id = f.id AND (r.type = 'head' OR r.type IS NULL)
            LEFT JOIN users u ON r.user_id = u.id
            WHERE v.estate_id = $estate_id 
              AND (
                  v.reg_number LIKE '%$q_escaped%' 
                  OR v.custom_id LIKE '%$q_escaped%'
                  OR v.model LIKE '%$q_escaped%'
                  OR u.name LIKE '%$q_escaped%'
              )
            GROUP BY v.id
            ORDER BY (CASE WHEN v.reg_number = '$q_escaped' THEN 0 ELSE 1 END), v.id DESC 
            LIMIT 8";

    $res = $conn->query($sql);
    $suggestions = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $suggestions[] = $row;
        }
    }
    echo json_encode(['success' => true, 'suggestions' => $suggestions]);
    exit;
}

// Full Dossier
$dossier = getVehicleDossier($conn, $estate_id, $q);

if ($dossier) {
    // Fetch Estate Branding info
    $settings_res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE estate_id = $estate_id");
    $sys = [];
    if ($settings_res) {
        while ($s = $settings_res->fetch_assoc()) {
            $sys[$s['setting_key']] = $s['setting_value'];
        }
    }
    $dossier['estate_name'] = $sys['estate_name'] ?? 'Estate Administrative Office';
    $dossier['estate_motto'] = !empty($sys['estate_motto']) ? $sys['estate_motto'] : 'Excellence in Living';
    $dossier['estate_logo'] = $sys['estate_logo'] ?? '';

    echo json_encode(['success' => true, 'data' => $dossier]);
} else {
    echo json_encode(['success' => false, 'error' => "No registered vehicle found matching '$q'", 'data' => null]);
}
