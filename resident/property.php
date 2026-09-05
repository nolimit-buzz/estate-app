<?php
// resident/property.php
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index");
    exit;
}

$user_id = intval($_SESSION['user_id']);
$estate_id = get_estate_id();

// Fetch Resident Record
$res_query = "SELECT r.*, f.id as flat_id, f.number as flat_number, f.floor, f.type as flat_type, f.status as flat_status,
                     b.id as building_id, b.name as building_name, b.property_number, b.type as building_type,
                     s.name as street_name 
              FROM residents r 
              LEFT JOIN flats f ON r.flat_id = f.id 
              LEFT JOIN buildings b ON f.building_id = b.id 
              LEFT JOIN streets s ON b.street_id = s.id 
              WHERE r.user_id = $user_id AND r.estate_id = $estate_id 
              ORDER BY r.id DESC LIMIT 1";
$resident = $conn->query($res_query)->fetch_assoc();
$flat_id = $resident['flat_id'] ?? 0;
$building_id = $resident['building_id'] ?? 0;

// Fetch Active Tenancy
$tenancy = $conn->query("SELECT * FROM tenancies WHERE resident_id = $user_id AND estate_id = $estate_id AND status = 'Active' ORDER BY id DESC LIMIT 1")->fetch_assoc();

// Fetch Landlord / Property Owner
$owner = null;
if ($flat_id || $building_id) {
    $owner_query = "SELECT po.* 
                    FROM owner_properties op 
                    JOIN property_owners po ON op.owner_id = po.id 
                    WHERE (op.property_id = $flat_id AND op.property_type = 'flat') 
                       OR (op.property_id = $building_id AND op.property_type = 'building') 
                    LIMIT 1";
    $owner = $conn->query($owner_query)->fetch_assoc();
}

// Household Occupants
$occupants_res = $conn->query("SELECT r.*, u.name, u.phone, u.email 
                               FROM residents r 
                               JOIN users u ON r.user_id = u.id 
                               WHERE r.flat_id = $flat_id AND r.status = 'active'");

// Vehicles
$vehicles_res = $conn->query("SELECT * FROM vehicles WHERE flat_id = $flat_id");

// Household Staff
$domestic_staff_res = $conn->query("SELECT * FROM household_staff WHERE flat_id = $flat_id");
include 'header.php';
include 'sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 font-bold text-slate-800 m-0"><i class="fa-solid fa-building-user text-primary me-2"></i> My Property & Tenancy</h2>
        <p class="text-secondary small mb-0">View flat details, landlord information, household members, and registered vehicles.</p>
    </div>
</div>

<style>
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; }
    @media (max-width: 850px) { .grid-2 { grid-template-columns: 1fr; } }

    .card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1.5rem; }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 1rem; }
    .card-title { font-family: 'Outfit', sans-serif; font-size: 1.15rem; font-weight: 700; }

    .prop-detail-row { display: flex; justify-content: space-between; padding: 0.75rem 0; border-bottom: 1px solid #e2e8f0; font-size: 0.95rem; }
    .prop-detail-row:last-child { border-bottom: none; }
    .prop-label { color: #64748b; font-weight: 500; }
    .prop-value { font-weight: 600; color: #0f172a; }

    table { width: 100%; border-collapse: collapse; text-align: left; margin-top: 0.5rem; }
    th { padding: 0.75rem 1rem; border-bottom: 2px solid #e2e8f0; font-size: 0.85rem; text-transform: uppercase; color: #64748b; }
    td { padding: 0.85rem 1rem; border-bottom: 1px solid #e2e8f0; font-size: 0.9rem; }
</style>

<div class="d-flex flex-column gap-4">


        <div class="grid-2">
            <!-- Property Details Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-building" style="color: var(--primary); margin-right: 8px;"></i> Property Information</div>
                </div>
                <div>
                    <div class="prop-detail-row">
                        <span class="prop-label">Street / Block</span>
                        <span class="prop-value"><?= htmlspecialchars($resident['street_name'] ?? 'Main Street') ?></span>
                    </div>
                    <div class="prop-detail-row">
                        <span class="prop-label">Building Name / #</span>
                        <span class="prop-value"><?= htmlspecialchars(($resident['building_name'] ?? 'Building') . ' (' . ($resident['property_number'] ?? 'N/A') . ')') ?></span>
                    </div>
                    <div class="prop-detail-row">
                        <span class="prop-label">Flat / House Number</span>
                        <span class="prop-value">Flat <?= htmlspecialchars($resident['flat_number'] ?? 'N/A') ?> (Floor <?= htmlspecialchars($resident['floor'] ?? '1') ?>)</span>
                    </div>
                    <div class="prop-detail-row">
                        <span class="prop-label">Flat Type</span>
                        <span class="prop-value"><?= htmlspecialchars($resident['flat_type'] ?? '2BHK') ?></span>
                    </div>
                    <div class="prop-detail-row">
                        <span class="prop-label">Occupancy Status</span>
                        <span class="prop-value" style="color: #10b981;"><i class="fa-solid fa-circle-check"></i> Occupied (<?= htmlspecialchars(ucfirst($tenancy['status'] ?? 'Active')) ?>)</span>
                    </div>
                </div>
            </div>

            <!-- Tenancy & Owner Details Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-key" style="color: #8b5cf6; margin-right: 8px;"></i> Tenancy & Ownership</div>
                </div>
                <div>
                    <div class="prop-detail-row">
                        <span class="prop-label">Resident Role</span>
                        <span class="prop-value" style="text-transform: uppercase; color: #2563eb;"><?= htmlspecialchars($resident['relationship'] ?? 'Head / Tenant') ?></span>
                    </div>
                    <div class="prop-detail-row">
                        <span class="prop-label">Move-In Date</span>
                        <span class="prop-value"><?= !empty($tenancy['move_in_date']) ? date('M j, Y', strtotime($tenancy['move_in_date'])) : (!empty($resident['lease_start']) ? date('M j, Y', strtotime($resident['lease_start'])) : 'N/A') ?></span>
                    </div>
                    <div class="prop-detail-row">
                        <span class="prop-label">Expected Lease End</span>
                        <span class="prop-value"><?= !empty($tenancy['expected_move_out_date']) ? date('M j, Y', strtotime($tenancy['expected_move_out_date'])) : (!empty($resident['lease_end']) ? date('M j, Y', strtotime($resident['lease_end'])) : 'Ongoing') ?></span>
                    </div>
                    <div class="prop-detail-row">
                        <span class="prop-label">Property Landlord / Owner</span>
                        <span class="prop-value"><?= htmlspecialchars($owner['full_name'] ?? 'Estate Direct / Private Owner') ?></span>
                    </div>
                    <?php if ($owner && !empty($owner['phone'])): ?>
                        <div class="prop-detail-row">
                            <span class="prop-label">Landlord Phone</span>
                            <span class="prop-value"><?= htmlspecialchars($owner['phone']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Household Members & Vehicles -->
        <div class="grid-2">
            <!-- Household Members -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-users" style="color: #3b82f6; margin-right: 8px;"></i> Co-Residents / Family</div>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Relationship</th>
                                <th>Phone</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($occupants_res && $occupants_res->num_rows > 0): ?>
                                <?php while ($occ = $occupants_res->fetch_assoc()): ?>
                                    <tr>
                                        <td style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($occ['name']) ?></td>
                                        <td><span style="background: #f1f5f9; padding: 2px 8px; border-radius: 4px; font-weight: 600; font-size: 0.8rem;"><?= htmlspecialchars(ucfirst($occ['relationship'])) ?></span></td>
                                        <td><?= htmlspecialchars($occ['phone'] ?? 'N/A') ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="3" style="text-align: center; color: var(--text-muted); padding: 1.5rem;">No co-residents registered for this flat.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Vehicles & Household Staff -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-car" style="color: #10b981; margin-right: 8px;"></i> Registered Vehicles & Staff</div>
                </div>
                <div>
                    <h4 style="font-size: 0.9rem; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em;">Vehicles</h4>
                    <?php if ($vehicles_res && $vehicles_res->num_rows > 0): ?>
                        <ul style="list-style: none; padding: 0; margin-bottom: 1.5rem;">
                            <?php while ($v = $vehicles_res->fetch_assoc()): ?>
                                <li style="padding: 0.5rem 0; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; font-size: 0.9rem;">
                                    <span style="font-weight: 600; font-family: monospace; color: #2563eb;"><?= htmlspecialchars($v['reg_number']) ?></span>
                                    <span style="color: var(--text-muted);"><?= htmlspecialchars($v['model'] ?? 'Vehicle') ?> (<?= htmlspecialchars(ucfirst($v['type'])) ?>)</span>
                                </li>
                            <?php endwhile; ?>
                        </ul>
                    <?php else: ?>
                        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.5rem;">No registered vehicles for this flat.</p>
                    <?php endif; ?>

                    <h4 style="font-size: 0.9rem; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em;">Domestic Staff</h4>
                    <?php if ($domestic_staff_res && $domestic_staff_res->num_rows > 0): ?>
                        <ul style="list-style: none; padding: 0;">
                            <?php while ($st = $domestic_staff_res->fetch_assoc()): ?>
                                <li style="padding: 0.5rem 0; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; font-size: 0.9rem;">
                                    <span style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($st['name']) ?></span>
                                    <span style="color: var(--text-muted);"><?= htmlspecialchars($st['role']) ?> (<?= htmlspecialchars($st['phone'] ?? 'N/A') ?>)</span>
                                </li>
                            <?php endwhile; ?>
                        </ul>
                    <?php else: ?>
                        <p style="color: var(--text-muted); font-size: 0.85rem;">No domestic staff registered for this flat.</p>
                    <?php endif; ?>
                </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
