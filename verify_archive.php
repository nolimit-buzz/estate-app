<?php
require_once 'c:/xampp/htdocs/Estate/config.php';
$estate_id = 1;
$q = $conn->query("
    SELECT r.id, r.custom_id, r.status, COALESCE(u.name, 'No User') as name, f.number as flat_number
    FROM residents r 
    LEFT JOIN users u ON r.user_id = u.id 
    LEFT JOIN flats f ON r.flat_id = f.id
    WHERE (r.status = 'archived' OR r.status = 'inactive') AND r.estate_id = $estate_id
");
echo "Archived residents count: " . $q->num_rows . "\n";
while ($row = $q->fetch_assoc()) {
    print_r($row);
}

$q_active = $conn->query("SELECT id, custom_id, status FROM residents WHERE estate_id = $estate_id AND (status IS NULL OR status NOT IN ('archived', 'inactive'))");
echo "Active residents count: " . $q_active->num_rows . "\n";
