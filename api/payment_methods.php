<?php
// api/payment_methods.php
header('Content-Type: application/json');
require_once '../config.php';

$estate_id = get_estate_id();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
    
    if ($action === 'generate_receipt_number') {
        $receipt_no = generateReceiptNumber($conn);
        echo json_encode(['success' => true, 'receipt_number' => $receipt_no]);
        exit;
    }

    // Default: list active methods
    $res = $conn->query("SELECT pm.id, pm.name, pm.code, pm.is_system, pm.created_by, pm.status, pm.created_at,
                                u.name as creator_name, u.role as creator_role
                         FROM payment_methods pm
                         LEFT JOIN users u ON pm.created_by = u.id
                         WHERE pm.estate_id = $estate_id AND pm.status = 'active' 
                         ORDER BY pm.is_system DESC, pm.name ASC");
    $methods = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $methods[] = $row;
        }
    }
    echo json_encode(['success' => true, 'methods' => $methods]);
    exit;
}

if ($method === 'POST') {
    // Check session login
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data) {
        $data = $_POST;
    }

    $name = trim($data['name'] ?? '');
    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Payment method name is required.']);
        exit;
    }

    // Generate slug/code
    $code = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($name)));
    $code = trim($code, '_');
    if (empty($code)) {
        $code = 'custom_' . time();
    }

    $safe_name = $conn->real_escape_string($name);
    $safe_code = $conn->real_escape_string($code);
    $creator_id = intval($_SESSION['user_id']);

    // Check creator info
    $u_res = $conn->query("SELECT name, role FROM users WHERE id = $creator_id LIMIT 1");
    $u_data = ($u_res && $u_res->num_rows > 0) ? $u_res->fetch_assoc() : ['name' => 'Staff', 'role' => 'staff'];
    $creator_name = $u_data['name'];
    $creator_role = ucfirst($u_data['role']);

    // Check if duplicate exists for estate
    $check = $conn->query("SELECT pm.id, pm.name, pm.code, pm.is_system, pm.created_by, u.name as creator_name, u.role as creator_role
                           FROM payment_methods pm 
                           LEFT JOIN users u ON pm.created_by = u.id
                           WHERE pm.estate_id = $estate_id AND (pm.code = '$safe_code' OR LOWER(pm.name) = LOWER('$safe_name')) LIMIT 1");
    if ($check && $check->num_rows > 0) {
        $existing = $check->fetch_assoc();
        echo json_encode([
            'success' => true, 
            'message' => 'Payment method already exists.', 
            'method' => $existing
        ]);
        exit;
    }

    $insert = $conn->query("INSERT INTO payment_methods (estate_id, name, code, is_system, created_by, status) VALUES ($estate_id, '$safe_name', '$safe_code', 0, $creator_id, 'active')");
    if ($insert) {
        $new_id = $conn->insert_id;
        logAudit($conn, "Payment Method Created", "Finance", "Staff member '$creator_name' ($creator_role) created new payment method: $name (Code: $code)");
        echo json_encode([
            'success' => true, 
            'message' => 'Payment method added successfully.',
            'method' => [
                'id' => $new_id,
                'name' => $name,
                'code' => $code,
                'is_system' => 0,
                'created_by' => $creator_id,
                'creator_name' => $creator_name,
                'creator_role' => $creator_role,
                'created_at' => date('Y-m-d H:i:s'),
                'status' => 'active'
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
?>
