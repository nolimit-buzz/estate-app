<?php
// includes/vehicle_helper.php - Car Sticker & Security Gate Vehicle Helper
require_once __DIR__ . '/../config.php';

// Safe Schema Check & Migration
function initVehicleSchema($conn) {
    static $initialized = false;
    if ($initialized) return;

    // 1. Ensure columns exist on vehicles table
    $columns_needed = [
        'color' => "VARCHAR(50) NULL DEFAULT 'Unspecified'",
        'sticker_number' => "VARCHAR(50) NULL",
        'sticker_status' => "ENUM('active', 'expired', 'revoked', 'pending') DEFAULT 'active'",
        'sticker_expiry_date' => "DATE NULL"
    ];

    $existing_cols = [];
    $res = $conn->query("SHOW COLUMNS FROM vehicles");
    if ($res) {
        while ($col = $res->fetch_assoc()) {
            $existing_cols[$col['Field']] = true;
        }
    }

    foreach ($columns_needed as $field => $def) {
        if (!isset($existing_cols[$field])) {
            $conn->query("ALTER TABLE vehicles ADD COLUMN $field $def");
        }
    }

    // 2. Ensure all vehicles have a custom_id (Car ID)
    $null_ids = $conn->query("SELECT id FROM vehicles WHERE custom_id IS NULL OR custom_id = ''");
    if ($null_ids && $null_ids->num_rows > 0) {
        while ($row = $null_ids->fetch_assoc()) {
            $vid = intval($row['id']);
            $cid = generateCustomID($conn, 'vehicles', 'VEH');
            $conn->query("UPDATE vehicles SET custom_id = '$cid' WHERE id = $vid");
        }
    }

    // 3. Create vehicle_gate_logs table if not exists
    $sql_logs = "CREATE TABLE IF NOT EXISTS vehicle_gate_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        estate_id INT NOT NULL,
        vehicle_id INT NOT NULL,
        reg_number VARCHAR(50) NOT NULL,
        direction ENUM('entry', 'exit') NOT NULL,
        gate_name VARCHAR(100) NOT NULL,
        shift_name VARCHAR(100) NULL,
        processed_by INT NOT NULL,
        notes TEXT NULL,
        logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (estate_id),
        INDEX (vehicle_id),
        INDEX (reg_number),
        INDEX (logged_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($sql_logs);

    $initialized = true;
}

// Automatically initialize schema when helper is loaded
if (isset($conn) && $conn instanceof mysqli) {
    initVehicleSchema($conn);
}

/**
 * Find vehicle and its full dossier by query (plate, custom_id, or ID)
 */
function getVehicleDossier($conn, $estate_id, $query) {
    initVehicleSchema($conn);
    $estate_id = intval($estate_id);
    $q_clean = strtoupper(trim($query));
    $q_escaped = $conn->real_escape_string($q_clean);

    $alphanumeric_q = preg_replace('/[^A-Z0-9]/', '', $q_clean);
    $alphanumeric_escaped = $conn->real_escape_string($alphanumeric_q);

    $sql = "SELECT v.*, 
                   f.number AS flat_number, f.floor, 
                   b.name AS building_name, b.id AS building_id,
                   s.name AS street_name, s.id AS street_id,
                   z.name AS zone_name, z.id AS zone_id
            FROM vehicles v
            LEFT JOIN flats f ON v.flat_id = f.id
            LEFT JOIN buildings b ON f.building_id = b.id
            LEFT JOIN streets s ON b.street_id = s.id
            LEFT JOIN zones z ON s.zone_id = z.id
            WHERE v.estate_id = $estate_id 
              AND (
                  v.custom_id = '$q_escaped'
                  OR v.sticker_number = '$q_escaped'
                  OR v.id = " . intval($query) . "
                  OR v.reg_number = '$q_escaped'
                  " . (!empty($alphanumeric_escaped) ? "OR REPLACE(REPLACE(REPLACE(UPPER(v.reg_number), '-', ''), ' ', ''), '_', '') = '$alphanumeric_escaped'" : "") . "
              )
            LIMIT 1";

    $res = $conn->query($sql);
    if (!$res || $res->num_rows === 0) {
        // Fallback partial search
        $sql_fallback = "SELECT v.*, 
                               f.number AS flat_number, f.floor, 
                               b.name AS building_name, b.id AS building_id,
                               s.name AS street_name, s.id AS street_id,
                               z.name AS zone_name, z.id AS zone_id
                        FROM vehicles v
                        LEFT JOIN flats f ON v.flat_id = f.id
                        LEFT JOIN buildings b ON f.building_id = b.id
                        LEFT JOIN streets s ON b.street_id = s.id
                        LEFT JOIN zones z ON s.zone_id = z.id
                        WHERE v.estate_id = $estate_id 
                          AND (
                              v.reg_number LIKE '%$q_escaped%'
                              OR v.custom_id LIKE '%$q_escaped%'
                              OR v.sticker_number LIKE '%$q_escaped%'
                          )
                        ORDER BY v.id DESC LIMIT 1";
        $res = $conn->query($sql_fallback);
        if (!$res || $res->num_rows === 0) {
            return null;
        }
    }

    $vehicle = $res->fetch_assoc();
    $flat_id = intval($vehicle['flat_id']);

    // Fetch primary resident / owner
    $head_sql = "SELECT r.*, u.name, u.first_name, u.last_name, u.phone, u.email, u.role
                 FROM residents r
                 JOIN users u ON r.user_id = u.id
                 WHERE r.flat_id = $flat_id 
                   AND (r.status IS NULL OR r.status NOT IN ('archived', 'inactive'))
                 ORDER BY (CASE WHEN r.type = 'head' THEN 0 ELSE 1 END), r.id ASC LIMIT 1";
    $head_res = $conn->query($head_sql);
    $vehicle['owner'] = ($head_res && $head_res->num_rows > 0) ? $head_res->fetch_assoc() : null;

    // Fetch all co-residents in this flat
    $residents_sql = "SELECT r.*, u.name, u.phone, u.email, u.role
                      FROM residents r
                      JOIN users u ON r.user_id = u.id
                      WHERE r.flat_id = $flat_id 
                        AND (r.status IS NULL OR r.status NOT IN ('archived', 'inactive'))
                      ORDER BY (CASE WHEN r.type = 'head' THEN 0 ELSE 1 END), u.name ASC";
    $residents_res = $conn->query($residents_sql);
    $all_residents = [];
    if ($residents_res) {
        while ($r = $residents_res->fetch_assoc()) {
            $all_residents[] = $r;
        }
    }
    $vehicle['household'] = $all_residents;

    // Fetch recent gate logs for this vehicle
    $v_id = intval($vehicle['id']);
    $logs_sql = "SELECT vgl.*, u.name as officer_name
                 FROM vehicle_gate_logs vgl
                 LEFT JOIN users u ON vgl.processed_by = u.id
                 WHERE vgl.estate_id = $estate_id AND (vgl.vehicle_id = $v_id OR vgl.reg_number = '{$vehicle['reg_number']}')
                 ORDER BY vgl.logged_at DESC LIMIT 5";
    $logs_res = $conn->query($logs_sql);
    $recent_logs = [];
    if ($logs_res) {
        while ($log = $logs_res->fetch_assoc()) {
            $recent_logs[] = $log;
        }
    }
    $vehicle['recent_gate_logs'] = $recent_logs;

    return $vehicle;
}

/**
 * Log Vehicle Gate Movement (Entry or Exit)
 */
function recordVehicleGateLog($conn, $estate_id, $vehicle_id, $direction, $gate_name, $shift_name, $processed_by, $notes = '') {
    initVehicleSchema($conn);
    $estate_id = intval($estate_id);
    $vehicle_id = intval($vehicle_id);
    $direction = ($direction === 'exit') ? 'exit' : 'entry';
    $gate_name = $conn->real_escape_string(trim($gate_name ?: 'Main Gate'));
    $shift_name = $conn->real_escape_string(trim($shift_name ?: 'General Duty'));
    $processed_by = intval($processed_by);
    $notes = $conn->real_escape_string(trim($notes));

    // Get reg_number
    $v_res = $conn->query("SELECT reg_number, custom_id FROM vehicles WHERE id = $vehicle_id AND estate_id = $estate_id LIMIT 1");
    if (!$v_res || $v_res->num_rows === 0) return false;
    $v_row = $v_res->fetch_assoc();
    $reg = $conn->real_escape_string($v_row['reg_number']);

    $sql = "INSERT INTO vehicle_gate_logs (estate_id, vehicle_id, reg_number, direction, gate_name, shift_name, processed_by, notes, logged_at)
            VALUES ($estate_id, $vehicle_id, '$reg', '$direction', '$gate_name', '$shift_name', $processed_by, '$notes', NOW())";
    
    if ($conn->query($sql)) {
        if (function_exists('logAudit')) {
            $action_label = ($direction === 'entry') ? 'Resident Vehicle Entry' : 'Resident Vehicle Exit';
            logAudit($conn, $action_label, "Security", "Vehicle {$v_row['reg_number']} ({$v_row['custom_id']}) logged $direction at $gate_name during $shift_name");
        }
        return true;
    }
    return false;
}
