<?php
require_once '../config.php';
header('Content-Type: application/json');

$estate_id = get_estate_id();
$sql = "SELECT * FROM commercial_field_definitions WHERE estate_id = $estate_id ORDER BY id ASC";
$res = $conn->query($sql);

$data = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $data[] = $row;
    }
    echo json_encode(['success' => true, 'data' => $data]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}
?>
