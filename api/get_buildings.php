<?php
header('Content-Type: application/json');
require_once '../config.php';

if (!isset($_GET['street_id'])) {
    echo json_encode(['success' => false, 'message' => 'Street ID missing']);
    exit;
}

$street_id = intval($_GET['street_id']);
$estate_id = get_estate_id();
$buildings = [];
$res = $conn->query("SELECT * FROM buildings WHERE street_id = $street_id AND estate_id = $estate_id AND status != 'archived' ORDER BY name");
while($row = $res->fetch_assoc()) $buildings[] = $row;

echo json_encode(['success' => true, 'data' => $buildings]);
$conn->close();
?>
