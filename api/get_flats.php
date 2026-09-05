<?php
header('Content-Type: application/json');
require_once '../config.php';

if (!isset($_GET['building_id'])) {
    echo json_encode(['success' => false, 'message' => 'Building ID missing']);
    exit;
}

$building_id = intval($_GET['building_id']);
$estate_id = get_estate_id();
$flats = [];
$res = $conn->query("SELECT * FROM flats WHERE building_id = $building_id AND estate_id = $estate_id AND status != 'archived' ORDER BY number");
while($row = $res->fetch_assoc()) $flats[] = $row;

echo json_encode(['success' => true, 'data' => $flats]);
$conn->close();
?>
