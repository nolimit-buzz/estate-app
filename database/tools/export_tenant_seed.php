<?php
// One-off developer tool: regenerates database/tenant_seed.json from the legacy single-tenant DB.
// Only lookup / system-default rows are exported - never users, residents, settings or any tenant data.
require_once __DIR__ . '/../../includes/env.php';
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli(env_get('DB_HOST', 'localhost'), env_get('DB_USER', 'root'), env_get('DB_PASS', ''), env_get('LEGACY_DB', 'estate'));
if ($db->connect_errno) die("Cannot connect: " . $db->connect_error . "\n");

// table => [estate_scoped, where]
$tables = [
    'building_floor_types' => [false, ''], 'building_statuses' => [false, ''], 'building_types' => [false, ''],
    'domestic_staff_roles' => [false, ''], 'domestic_staff_status' => [false, ''], 'flat_statuses' => [false, ''],
    'flat_types' => [false, ''], 'property_acquisition_types' => [false, ''], 'property_ownership_types' => [false, ''],
    'resident_relationships' => [false, ''], 'resident_statuses' => [false, ''], 'permissions' => [false, ''],
    'incident_entity_types' => [true, ''], 'incident_severities' => [true, ''], 'incident_statuses' => [true, ''],
    'incident_types' => [true, ''], 'zonal_billing_frequencies' => [true, ''], 'zonal_charge_types' => [true, ''],
    'roles' => [true, "slug <> 'superadmin'"], 'role_permissions' => [false, 'role_id IN (SELECT id FROM roles WHERE slug <> \'superadmin\')'],
    'estate_emergency_categories' => [true, ''], 'estate_emergency_actions' => [true, ''],
    'estate_policy_categories' => [true, ''], 'payment_methods' => [true, 'is_system = 1'],
];
$out = [];
foreach ($tables as $t => [$scoped, $where]) {
    $sql = "SELECT * FROM `$t`" . ($where ? " WHERE $where" : '') . ($scoped && $t !== 'roles' ? " WHERE estate_id = 1" . '' : '');
    if ($scoped) {
        $sql = "SELECT * FROM `$t` WHERE estate_id = 1" . ($where ? " AND $where" : '');
    }
    $r = $db->query($sql);
    if (!$r) { fwrite(STDERR, "skip $t: {$db->error}\n"); continue; }
    $cols = array_map(fn($f) => $f->name, $r->fetch_fields());
    $rows = [];
    while ($row = $r->fetch_row()) $rows[] = $row;
    $out[$t] = ['estate_scoped' => $scoped, 'columns' => $cols, 'rows' => $rows];
    echo str_pad($t, 34) . count($rows) . " rows\n";
}
file_put_contents(__DIR__ . '/../tenant_seed.json', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "written database/tenant_seed.json\n";
