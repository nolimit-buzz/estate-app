<?php
// zone/archives.php - Zonal Archives Vault (Strictly Scoped to Zone)
require_once '../config.php';
require_once '../includes/auth_guard.php';

requireZoneAccess();

$zone_id = get_current_zone_id();
$estate_id = get_estate_id();

$message = "";
$message_type = "success";

// Handle Quick Restoration
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['restore_item'])) {
    $table = $_POST['table'];
    $item_id = intval($_POST['id']);
    $allowed_tables = ['streets', 'buildings', 'flats', 'residents', 'household_staff'];
    
    if (in_array($table, $allowed_tables)) {
        $new_status = 'active';
        if ($table == 'flats') $new_status = 'vacant';
        if ($table == 'household_staff') $new_status = 'Daily';
        
        // Ensure zonal ownership
        $is_valid = false;
        if ($table == 'streets') {
            $chk = $conn->query("SELECT id FROM streets WHERE id = $item_id AND zone_id = $zone_id AND estate_id = $estate_id");
            $is_valid = ($chk && $chk->num_rows > 0);
        } elseif ($table == 'buildings') {
            $chk = $conn->query("SELECT b.id FROM buildings b JOIN streets s ON b.street_id = s.id WHERE b.id = $item_id AND s.zone_id = $zone_id AND b.estate_id = $estate_id");
            $is_valid = ($chk && $chk->num_rows > 0);
        } elseif ($table == 'flats') {
            $chk = $conn->query("SELECT f.id FROM flats f JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE f.id = $item_id AND s.zone_id = $zone_id AND f.estate_id = $estate_id");
            $is_valid = ($chk && $chk->num_rows > 0);
        } elseif ($table == 'residents') {
            $chk = $conn->query("SELECT r.id FROM residents r LEFT JOIN flats f ON r.flat_id = f.id LEFT JOIN buildings b ON f.building_id = b.id LEFT JOIN streets s ON b.street_id = s.id WHERE r.id = $item_id AND (s.zone_id = $zone_id OR r.flat_id IS NULL) AND r.estate_id = $estate_id");
            $is_valid = ($chk && $chk->num_rows > 0);
        } elseif ($table == 'household_staff') {
            $chk = $conn->query("SELECT hs.id FROM household_staff hs JOIN flats f ON hs.flat_id = f.id JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE hs.id = $item_id AND s.zone_id = $zone_id AND hs.estate_id = $estate_id");
            $is_valid = ($chk && $chk->num_rows > 0);
        }
        
        if ($is_valid) {
            $sql = "UPDATE $table SET status = '$new_status' WHERE id = $item_id AND estate_id = $estate_id";
            if ($conn->query($sql)) {
                $message = "Record restored successfully to active registry!";
                logAudit($conn, "Zonal Item Restored", "Zonal Archives", "Restored ID $item_id in table $table (Zone #$zone_id)");
            } else {
                $message = "Error: " . $conn->error;
                $message_type = "danger";
            }
        } else {
            $message = "Unauthorized: Item does not belong to your assigned zone.";
            $message_type = "danger";
        }
    }
}

// Handle Recreating / Re-onboarding Resident from Zonal Archive
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['recreate_resident'])) {
    $res_id = intval($_POST['resident_id']);
    $flat_id = intval($_POST['flat_id']);
    $move_in_date = !empty($_POST['move_in_date']) ? $conn->real_escape_string($_POST['move_in_date']) : date('Y-m-d');
    $type = in_array($_POST['type'] ?? '', ['head', 'dependent']) ? $_POST['type'] : 'head';
    $relationship = $conn->real_escape_string($_POST['relationship'] ?? 'Self');
    
    // Verify target flat is in this zone
    $chk_flat = $conn->query("
        SELECT f.id, f.number, b.name as building_name 
        FROM flats f 
        JOIN buildings b ON f.building_id = b.id 
        JOIN streets s ON b.street_id = s.id 
        WHERE f.id = $flat_id AND s.zone_id = $zone_id AND f.estate_id = $estate_id
    ")->fetch_assoc();
    
    if (!$chk_flat) {
        $message = "Error: Target flat does not belong to this zone.";
        $message_type = "danger";
    } else {
        $r_data = $conn->query("SELECT user_id, custom_id FROM residents WHERE id = $res_id AND estate_id = $estate_id")->fetch_assoc();
        if ($r_data) {
            $u_id = intval($r_data['user_id']);
            
            // Update resident record to active and new flat location
            $conn->query("UPDATE residents SET status = 'active', flat_id = $flat_id, type = '$type', relationship = '$relationship', registration_date = '$move_in_date' WHERE id = $res_id AND estate_id = $estate_id");
            $conn->query("UPDATE flats SET status = 'occupied' WHERE id = $flat_id AND estate_id = $estate_id");
            
            // Record new active tenancy
            if ($u_id > 0) {
                $conn->query("INSERT INTO tenancies (estate_id, resident_id, flat_id, move_in_date, status) VALUES ($estate_id, $u_id, $flat_id, '$move_in_date', 'Active')");
            }
            
            // Log history
            if (function_exists('logResidentHistory')) {
                logResidentHistory($conn, $res_id, $flat_id, 'Moved In', 'Re-onboarded / Recreated from Zonal Archives Vault');
            }
            
            logAudit($conn, "Zonal Resident Re-onboarded", "Zonal Archives", "Reactivated resident {$r_data['custom_id']} to Flat ID #$flat_id (Zone #$zone_id)");
            $message = "Resident successfully re-onboarded and assigned to Flat " . htmlspecialchars($chk_flat['number']) . "!";
        } else {
            $message = "Resident record not found.";
            $message_type = "danger";
        }
    }
}

// Handle Permanent Deletion in Zone
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_item'])) {
    $table = $_POST['table'];
    $item_id = intval($_POST['id']);
    $allowed_tables = ['streets', 'buildings', 'flats', 'residents', 'household_staff'];
    
    if (in_array($table, $allowed_tables)) {
        $is_valid = false;
        if ($table == 'streets') {
            $chk = $conn->query("SELECT id FROM streets WHERE id = $item_id AND zone_id = $zone_id AND estate_id = $estate_id");
            $is_valid = ($chk && $chk->num_rows > 0);
        } elseif ($table == 'buildings') {
            $chk = $conn->query("SELECT b.id FROM buildings b JOIN streets s ON b.street_id = s.id WHERE b.id = $item_id AND s.zone_id = $zone_id AND b.estate_id = $estate_id");
            $is_valid = ($chk && $chk->num_rows > 0);
        } elseif ($table == 'flats') {
            $chk = $conn->query("SELECT f.id FROM flats f JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE f.id = $item_id AND s.zone_id = $zone_id AND f.estate_id = $estate_id");
            $is_valid = ($chk && $chk->num_rows > 0);
        } elseif ($table == 'residents') {
            $chk = $conn->query("SELECT r.id, r.user_id, r.flat_id FROM residents r LEFT JOIN flats f ON r.flat_id = f.id LEFT JOIN buildings b ON f.building_id = b.id LEFT JOIN streets s ON b.street_id = s.id WHERE r.id = $item_id AND (s.zone_id = $zone_id OR r.flat_id IS NULL) AND r.estate_id = $estate_id");
            if ($chk && $r_row = $chk->fetch_assoc()) {
                $is_valid = true;
                $conn->query("DELETE FROM resident_history WHERE resident_id = $item_id");
                if (!empty($r_row['user_id']) && !empty($r_row['flat_id'])) {
                    $conn->query("DELETE FROM tenancies WHERE resident_id = " . intval($r_row['user_id']) . " AND flat_id = " . intval($r_row['flat_id']));
                }
            }
        } elseif ($table == 'household_staff') {
            $chk = $conn->query("SELECT hs.id FROM household_staff hs JOIN flats f ON hs.flat_id = f.id JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE hs.id = $item_id AND s.zone_id = $zone_id AND hs.estate_id = $estate_id");
            $is_valid = ($chk && $chk->num_rows > 0);
        }
        
        if ($is_valid) {
            $sql = "DELETE FROM $table WHERE id = $item_id AND estate_id = $estate_id";
            if ($conn->query($sql)) {
                $message = "Record permanently deleted from database.";
                logAudit($conn, "Zonal Item Deleted", "Zonal Archives", "Permanently deleted ID $item_id from table $table");
            } else {
                $message = "Error: " . $conn->error;
                $message_type = "danger";
            }
        } else {
            $message = "Unauthorized: Item does not belong to your assigned zone.";
            $message_type = "danger";
        }
    }
}

// Fetch Archived Items Scoped to Zone
$archived_streets = $conn->query("SELECT * FROM streets WHERE status = 'archived' AND zone_id = $zone_id AND estate_id = $estate_id ORDER BY name");
$archived_buildings = $conn->query("
    SELECT b.*, s.name as street_name 
    FROM buildings b 
    JOIN streets s ON b.street_id = s.id 
    WHERE b.status = 'archived' AND s.zone_id = $zone_id AND b.estate_id = $estate_id 
    ORDER BY b.name
");
$archived_flats = $conn->query("
    SELECT f.*, b.name as building_name, s.name as street_name 
    FROM flats f 
    JOIN buildings b ON f.building_id = b.id 
    JOIN streets s ON b.street_id = s.id 
    WHERE f.status = 'archived' AND s.zone_id = $zone_id AND f.estate_id = $estate_id 
    ORDER BY f.number
");
$archived_residents = $conn->query("
    SELECT r.*, 
           COALESCE(u.name, 'No User Profile') as name, 
           COALESCE(u.email, 'N/A') as email, 
           COALESCE(u.phone, 'N/A') as phone,
           f.number as flat_number, 
           b.name as building_name, 
           s.name as street_name 
    FROM residents r 
    LEFT JOIN users u ON r.user_id = u.id 
    LEFT JOIN flats f ON r.flat_id = f.id 
    LEFT JOIN buildings b ON f.building_id = b.id 
    LEFT JOIN streets s ON b.street_id = s.id 
    WHERE (r.status = 'archived' OR r.status = 'inactive') 
      AND (s.zone_id = $zone_id OR (r.flat_id IS NULL AND u.zone_id = $zone_id))
      AND r.estate_id = $estate_id 
    ORDER BY r.id DESC
");
$archived_domestic = $conn->query("
    SELECT hs.*, f.number as flat_number, b.name as building_name, s.name as street_name 
    FROM household_staff hs 
    JOIN flats f ON hs.flat_id = f.id 
    JOIN buildings b ON f.building_id = b.id 
    JOIN streets s ON b.street_id = s.id 
    WHERE hs.status = 'inactive' AND s.zone_id = $zone_id AND hs.estate_id = $estate_id 
    ORDER BY hs.name
");

// Flats list in this zone for re-onboarding
$all_available_flats = $conn->query("
    SELECT f.id, f.number, b.name as building_name, s.name as street_name 
    FROM flats f 
    JOIN buildings b ON f.building_id = b.id 
    JOIN streets s ON b.street_id = s.id 
    WHERE s.zone_id = $zone_id AND f.estate_id = $estate_id 
    ORDER BY s.name, b.name, f.number
");
$flats_opts = [];
if ($all_available_flats) {
    while($fl = $all_available_flats->fetch_assoc()) {
        $flats_opts[] = $fl;
    }
}

include 'header.php';
include 'sidebar.php';
?>

<div class="page-header-futuristic mb-4">
    <div>
        <div class="header-breadcrumbs">
            <span><?php echo htmlspecialchars($_SESSION['zone_name'] ?? 'Zone'); ?></span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span>Data Vault</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span class="active">Zonal Archives</span>
        </div>
        <h1 class="header-title">Zonal Archives & Re-onboarding Vault</h1>
        <p class="header-subtitle">Record keeping repository for departed zonal residents, domestic staff, and archived sector units. Recreate returning residents seamlessly.</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type == 'danger' ? 'danger' : 'success'; ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert" style="border-radius: 12px;">
        <div class="d-flex align-items-center gap-2">
            <i class="fa-solid <?php echo $message_type == 'danger' ? 'fa-triangle-exclamation' : 'fa-circle-check'; ?> fs-5"></i>
            <div><?php echo htmlspecialchars($message); ?></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="glass" style="padding: 2rem; border-radius: 0.75rem; background: white; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
    <div class="tabs" style="display: flex; gap: 1rem; border-bottom: 2px solid #e2e8f0; margin-bottom: 1.5rem;">
        <button id="tab-res-btn" class="tab-btn active" onclick="openArchTab(event, 'res-archives')">
            <i class="fa-solid fa-users me-1"></i> Archived Residents (<?php echo $archived_residents ? $archived_residents->num_rows : 0; ?>)
        </button>
        <button id="tab-staff-btn" class="tab-btn" onclick="openArchTab(event, 'staff-archives')">
            <i class="fa-solid fa-user-gear me-1"></i> Domestic Staff Archives (<?php echo $archived_domestic ? $archived_domestic->num_rows : 0; ?>)
        </button>
        <button id="tab-prop-btn" class="tab-btn" onclick="openArchTab(event, 'prop-archives')">
            <i class="fa-solid fa-building me-1"></i> Zonal Property Archives
        </button>
    </div>

    <!-- Resident Archives -->
    <div id="res-archives" class="tab-content">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 style="margin:0;"><i class="fa-solid fa-user-clock text-secondary me-2"></i> Archived / Departed Residents</h4>
            <span class="small text-muted">Use <strong>Re-onboard</strong> to assign returning residents into active flats in your zone.</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr style="text-align: left; color: #64748b;">
                        <th style="padding: 0.75rem;">System ID</th>
                        <th style="padding: 0.75rem;">Resident Name</th>
                        <th style="padding: 0.75rem;">Last Zonal Address</th>
                        <th style="padding: 0.75rem;">Role / Type</th>
                        <th style="padding: 0.75rem;">Contact Details</th>
                        <th style="padding: 0.75rem; text-align: right;">Action Provision</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($archived_residents && $archived_residents->num_rows > 0): ?>
                        <?php while($row = $archived_residents->fetch_assoc()): ?>
                        <tr>
                            <td style="padding: 0.75rem;">
                                <span style="font-family: monospace; font-size: 0.8rem; background: #f3e8ff; color: #7e22ce; padding: 2px 6px; border-radius: 4px; font-weight: 600;">
                                    <?php echo htmlspecialchars($row['custom_id'] ?? 'RES-' . $row['id']); ?>
                                </span>
                            </td>
                            <td style="padding: 0.75rem; font-weight: 600;">
                                <?php echo htmlspecialchars($row['name']); ?>
                            </td>
                            <td style="padding: 0.75rem; font-size: 0.85rem; color: #475569;">
                                Flat <?php echo htmlspecialchars($row['flat_number'] ?? 'N/A'); ?>
                                <?php if(!empty($row['building_name'])): ?>
                                    &bull; <?php echo htmlspecialchars($row['building_name']); ?>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 0.75rem;">
                                <span style="font-size: 0.78rem; text-transform: capitalize; padding: 2px 8px; border-radius: 9999px; background: #f1f5f9; color: #475569;">
                                    <?php echo htmlspecialchars($row['type'] ?? 'head'); ?>
                                </span>
                            </td>
                            <td style="padding: 0.75rem; font-size: 0.82rem; color: #64748b;">
                                <?php echo htmlspecialchars($row['email']); ?><br>
                                <span style="font-family: monospace; font-size: 0.78rem;"><?php echo htmlspecialchars($row['phone']); ?></span>
                            </td>
                            <td style="padding: 0.75rem; text-align: right; white-space: nowrap;">
                                <button type="button" class="btn btn-sm text-white" style="background: #9333ea; padding: 0.28rem 0.65rem; font-size: 0.8rem; margin-right: 0.25rem;" onclick="openReonboardModal(<?php echo htmlspecialchars(json_encode($row)); ?>)" title="Re-onboard / Recreate Resident into Zone Flat">
                                    <i class="fa-solid fa-arrows-rotate me-1"></i> Re-onboard
                                </button>
                                <form method="POST" style="display:inline; margin-right: 0.25rem;" onsubmit="return confirm('Restore this resident to active registry?');">
                                    <input type="hidden" name="table" value="residents">
                                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                    <button type="submit" name="restore_item" class="btn btn-sm btn-outline-secondary" style="padding: 0.28rem 0.65rem; font-size: 0.8rem;" title="Quick Restore">
                                        <i class="fa-solid fa-trash-arrow-up"></i>
                                    </button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete this archived resident record? This cannot be undone.');">
                                    <input type="hidden" name="table" value="residents">
                                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                    <button type="submit" name="delete_item" class="btn btn-sm btn-outline-danger" style="padding: 0.28rem 0.65rem; font-size: 0.8rem;" title="Permanently Delete">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" style="padding: 2rem; text-align: center; color: #94a3b8;">No archived residents found in your zone.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Domestic Staff Archives -->
    <div id="staff-archives" class="tab-content" style="display: none;">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 style="margin:0;"><i class="fa-solid fa-user-shield text-secondary me-2"></i> Inactive Domestic Staff</h4>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr style="text-align: left; color: #64748b;">
                        <th style="padding: 0.75rem;">System ID</th>
                        <th style="padding: 0.75rem;">Staff Name</th>
                        <th style="padding: 0.75rem;">Assigned Flat</th>
                        <th style="padding: 0.75rem;">Role</th>
                        <th style="padding: 0.75rem;">Phone</th>
                        <th style="padding: 0.75rem; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($archived_domestic && $archived_domestic->num_rows > 0): ?>
                        <?php while($row = $archived_domestic->fetch_assoc()): ?>
                        <tr>
                            <td style="padding: 0.75rem;"><span style="font-family: monospace; font-size: 0.8rem; background: #f1f5f9; padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($row['custom_id'] ?? 'STF-' . $row['id']); ?></span></td>
                            <td style="padding: 0.75rem; font-weight: 600;"><?php echo htmlspecialchars($row['name']); ?></td>
                            <td style="padding: 0.75rem; font-size: 0.85rem;">Flat <?php echo htmlspecialchars($row['flat_number'] ?? 'N/A'); ?> (<?php echo htmlspecialchars($row['building_name'] ?? ''); ?>)</td>
                            <td style="padding: 0.75rem;"><span class="badge bg-secondary"><?php echo htmlspecialchars($row['role']); ?></span></td>
                            <td style="padding: 0.75rem; font-family: monospace; font-size: 0.82rem;"><?php echo htmlspecialchars($row['phone']); ?></td>
                            <td style="padding: 0.75rem; text-align: right; white-space: nowrap;">
                                <form method="POST" style="display:inline; margin-right: 0.25rem;">
                                    <input type="hidden" name="table" value="household_staff">
                                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                    <button type="submit" name="restore_item" class="btn btn-sm btn-outline-success" style="padding: 0.28rem 0.65rem; font-size: 0.8rem;"><i class="fa-solid fa-trash-arrow-up me-1"></i> Restore</button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete this staff record?');">
                                    <input type="hidden" name="table" value="household_staff">
                                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                    <button type="submit" name="delete_item" class="btn btn-sm btn-outline-danger" style="padding: 0.28rem 0.65rem; font-size: 0.8rem;"><i class="fa-solid fa-trash-can"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" style="padding: 2rem; text-align: center; color: #94a3b8;">No archived domestic staff in your zone.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Zonal Properties Archives -->
    <div id="prop-archives" class="tab-content" style="display: none;">
        <h4 style="margin-top: 0.5rem;"><i class="fa-solid fa-road text-secondary me-2"></i> Archived Streets</h4>
        <table class="table table-hover align-middle mb-4">
            <thead class="table-light">
                <tr style="text-align: left; color: #64748b;">
                    <th style="padding: 0.75rem;">Street Name</th>
                    <th style="padding: 0.75rem; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $archived_streets->fetch_assoc()): ?>
                <tr>
                    <td style="padding: 0.75rem;"><?php echo htmlspecialchars($row['name']); ?></td>
                    <td style="padding: 0.75rem; text-align: right;">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="table" value="streets">
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="restore_item" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-trash-arrow-up"></i> Restore</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if($archived_streets->num_rows == 0) echo "<tr><td colspan='2' style='padding:1rem; text-align:center; color:#94a3b8;'>No archived streets found.</td></tr>"; ?>
            </tbody>
        </table>

        <h4 style="margin-top: 1.5rem;"><i class="fa-solid fa-city text-secondary me-2"></i> Archived Buildings</h4>
        <table class="table table-hover align-middle mb-4">
            <thead class="table-light">
                <tr style="text-align: left; color: #64748b;">
                    <th style="padding: 0.75rem;">Building Name</th>
                    <th style="padding: 0.75rem;">Street</th>
                    <th style="padding: 0.75rem; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $archived_buildings->fetch_assoc()): ?>
                <tr>
                    <td style="padding: 0.75rem;"><?php echo htmlspecialchars($row['name']); ?></td>
                    <td style="padding: 0.75rem;"><?php echo htmlspecialchars($row['street_name']); ?></td>
                    <td style="padding: 0.75rem; text-align: right;">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="table" value="buildings">
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="restore_item" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-trash-arrow-up"></i> Restore</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if($archived_buildings->num_rows == 0) echo "<tr><td colspan='3' style='padding:1rem; text-align:center; color:#94a3b8;'>No archived buildings found.</td></tr>"; ?>
            </tbody>
        </table>

        <h4 style="margin-top: 1.5rem;"><i class="fa-solid fa-door-closed text-secondary me-2"></i> Archived Flats / Units</h4>
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr style="text-align: left; color: #64748b;">
                    <th style="padding: 0.75rem;">Flat Number</th>
                    <th style="padding: 0.75rem;">Building</th>
                    <th style="padding: 0.75rem;">Street</th>
                    <th style="padding: 0.75rem; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $archived_flats->fetch_assoc()): ?>
                <tr>
                    <td style="padding: 0.75rem;">Flat <?php echo htmlspecialchars($row['number']); ?></td>
                    <td style="padding: 0.75rem;"><?php echo htmlspecialchars($row['building_name']); ?></td>
                    <td style="padding: 0.75rem;"><?php echo htmlspecialchars($row['street_name']); ?></td>
                    <td style="padding: 0.75rem; text-align: right;">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="table" value="flats">
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="restore_item" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-trash-arrow-up"></i> Restore</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if($archived_flats->num_rows == 0) echo "<tr><td colspan='4' style='padding:1rem; text-align:center; color:#94a3b8;'>No archived flats found.</td></tr>"; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Re-onboard Returning Resident to Zone Flat -->
<div class="modal fade" id="reonboardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-0 pt-4 px-4 pb-0">
                <div>
                    <h5 class="modal-title fw-bold text-slate-800"><i class="fa-solid fa-arrows-rotate text-purple me-2" style="color: #9333ea;"></i> Re-onboard Returning Resident</h5>
                    <p class="text-secondary small mb-0">Assign returning resident <strong id="reonboard_name" class="text-purple" style="color: #9333ea;"></strong> to a zonal flat unit.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="resident_id" id="reonboard_res_id">
                <input type="hidden" name="recreate_resident" value="1">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">SELECT TARGET ZONAL FLAT / UNIT <span class="text-danger">*</span></label>
                        <select name="flat_id" class="form-control" required>
                            <option value="">-- Choose Zonal Residence Flat --</option>
                            <?php foreach ($flats_opts as $fl): ?>
                                <option value="<?php echo $fl['id']; ?>">Flat <?php echo htmlspecialchars($fl['number']); ?> &bull; <?php echo htmlspecialchars($fl['building_name']); ?> (<?php echo htmlspecialchars($fl['street_name']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">RESIDENT TYPE</label>
                            <select name="type" id="reonboard_type" class="form-control">
                                <option value="head">Head of Household</option>
                                <option value="dependent">Dependent</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold text-secondary">RELATIONSHIP</label>
                            <input type="text" name="relationship" id="reonboard_rel" class="form-control" value="Self">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">MOVE-IN DATE</label>
                        <input type="date" name="move_in_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn text-white rounded-pill px-4 fw-semibold" style="background: #9333ea;">
                        <i class="fa-solid fa-check me-1"></i> Confirm Zonal Re-onboarding
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.tab-btn {
    padding: 0.75rem 1rem;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    color: #64748b;
    font-weight: 600;
    cursor: pointer;
}
.tab-btn.active {
    color: #9333ea;
    border-bottom-color: #9333ea;
}
.tab-content { padding-top: 1rem; }
h4 { color: #1e293b; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px dashed #e2e8f0; }
</style>

<script>
function openArchTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName("tab-content");
    for (i = 0; i < tabcontent.length; i++) {
        tabcontent[i].style.display = "none";
    }
    tablinks = document.getElementsByClassName("tab-btn");
    for (i = 0; i < tablinks.length; i++) {
        tablinks[i].className = tablinks[i].className.replace(" active", "");
    }
    document.getElementById(tabName).style.display = "block";
    if (evt && evt.currentTarget) {
        evt.currentTarget.className += " active";
    }
}

function openReonboardModal(data) {
    document.getElementById('reonboard_res_id').value = data.id;
    document.getElementById('reonboard_name').innerText = data.name;
    document.getElementById('reonboard_type').value = data.type || 'head';
    document.getElementById('reonboard_rel').value = data.relationship || 'Self';
    const modal = new bootstrap.Modal(document.getElementById('reonboardModal'));
    modal.show();
}

window.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const tab = urlParams.get('tab');
    if (tab === 'residents' || tab === 'res-archives') {
        const btn = document.getElementById('tab-res-btn');
        if (btn) btn.click();
    } else if (tab === 'staff' || tab === 'staff-archives') {
        const btn = document.getElementById('tab-staff-btn');
        if (btn) btn.click();
    } else if (tab === 'prop' || tab === 'prop-archives') {
        const btn = document.getElementById('tab-prop-btn');
        if (btn) btn.click();
    }
});
</script>

<?php include 'footer.php'; ?>
