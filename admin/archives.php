<?php
// admin/archives.php
require_once '../config.php';
require_once '../includes/auth_guard.php';
requireAdminAccess();

$message = "";
$message_type = "success";
$estate_id = get_estate_id();

// Handle Quick Restoration
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['restore_item'])) {
    $table = $_POST['table'];
    $item_id = intval($_POST['id']);
    $allowed_tables = ['streets', 'buildings', 'flats', 'residents', 'household_staff', 'estate_staff'];
    
    if (in_array($table, $allowed_tables)) {
        $new_status = 'active';
        if ($table == 'flats') $new_status = 'vacant';
        if ($table == 'household_staff') $new_status = 'Daily';
        
        $sql = "UPDATE $table SET status = '$new_status' WHERE id = $item_id AND estate_id = $estate_id";
        if ($conn->query($sql)) {
            $message = "Item restored successfully!";
            logAudit($conn, "Item Restored", "Archives Vault", "Restored ID $item_id in table $table");
        } else {
            $message = "Error: " . $conn->error;
            $message_type = "danger";
        }
    }
}

// Handle Recreating / Re-onboarding Resident from Archive
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['recreate_resident'])) {
    $res_id = intval($_POST['resident_id']);
    $flat_id = intval($_POST['flat_id']);
    $move_in_date = !empty($_POST['move_in_date']) ? $conn->real_escape_string($_POST['move_in_date']) : date('Y-m-d');
    $type = in_array($_POST['type'] ?? '', ['head', 'dependent']) ? $_POST['type'] : 'head';
    $relationship = $conn->real_escape_string($_POST['relationship'] ?? 'Self');
    
    $r_data = $conn->query("SELECT user_id, custom_id FROM residents WHERE id = $res_id AND estate_id = $estate_id")->fetch_assoc();
    if ($r_data) {
        $u_id = intval($r_data['user_id']);
        
        // Update resident record to active and new flat location
        $conn->query("UPDATE residents SET status = 'active', flat_id = $flat_id, type = '$type', relationship = '$relationship', registration_date = '$move_in_date' WHERE id = $res_id AND estate_id = $estate_id");
        $conn->query("UPDATE flats SET status = 'occupied' WHERE id = $flat_id AND estate_id = $estate_id");
        
        // Record new active tenancy
        $conn->query("INSERT INTO tenancies (estate_id, resident_id, flat_id, move_in_date, status) VALUES ($estate_id, $u_id, $flat_id, '$move_in_date', 'Active')");
        
        // Log history
        if (function_exists('logResidentHistory')) {
            logResidentHistory($conn, $res_id, $flat_id, 'Moved In', 'Re-onboarded / Recreated from Central Archives Vault');
        }
        
        logAudit($conn, "Resident Re-onboarded", "Archives Vault", "Reactivated resident {$r_data['custom_id']} to Flat ID #$flat_id");
        $message = "Resident successfully re-onboarded to active status!";
    } else {
        $message = "Resident record not found.";
        $message_type = "danger";
    }
}

// Handle Recreating / Re-hiring Staff from Archive
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['recreate_staff'])) {
    $staff_id = intval($_POST['staff_id']);
    $role_id = intval($_POST['role_id']);
    $role_title = 'Staff';
    $r_q = $conn->query("SELECT name FROM roles WHERE id = $role_id AND estate_id = $estate_id");
    if ($r_q && $r_row = $r_q->fetch_assoc()) {
        $role_title = $conn->real_escape_string($r_row['name']);
    }
    
    if ($conn->query("UPDATE estate_staff SET status = 'active', role_id = $role_id, role = '$role_title' WHERE id = $staff_id AND estate_id = $estate_id")) {
        logAudit($conn, "Staff Reactivated", "Archives Vault", "Reactivated staff ID $staff_id with role $role_title");
        $message = "Staff member successfully reactivated and assigned as $role_title!";
    } else {
        $message = "Error: " . $conn->error;
        $message_type = "danger";
    }
}

// Handle Permanent Deletion
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_item'])) {
    $table = $_POST['table'];
    $item_id = intval($_POST['id']);
    $allowed_tables = ['streets', 'buildings', 'flats', 'residents', 'household_staff', 'estate_staff'];
    
    if (in_array($table, $allowed_tables)) {
        if ($table == 'residents') {
            $conn->query("DELETE FROM resident_history WHERE resident_id = $item_id");
            $r_data = $conn->query("SELECT user_id, flat_id FROM residents WHERE id = $item_id AND estate_id = $estate_id")->fetch_assoc();
            if ($r_data && !empty($r_data['user_id'])) {
                $conn->query("DELETE FROM tenancies WHERE resident_id = " . intval($r_data['user_id']) . " AND flat_id = " . intval($r_data['flat_id']));
            }
        }
        $sql = "DELETE FROM $table WHERE id = $item_id AND estate_id = $estate_id";
        if ($conn->query($sql)) {
            $message = "Record permanently deleted!";
            logAudit($conn, "Item Deleted", "Archives Vault", "Permanently deleted ID $item_id from table $table");
        } else {
            $message = "Error: " . $conn->error;
            $message_type = "danger";
        }
    }
}

// Fetch Archived Items
$archived_streets = $conn->query("SELECT * FROM streets WHERE status = 'archived' AND estate_id = $estate_id ORDER BY name");
$archived_buildings = $conn->query("SELECT b.*, s.name as street_name FROM buildings b JOIN streets s ON b.street_id = s.id WHERE b.status = 'archived' AND b.estate_id = $estate_id ORDER BY b.name");
$archived_flats = $conn->query("SELECT f.*, b.name as building_name, s.name as street_name FROM flats f JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE f.status = 'archived' AND f.estate_id = $estate_id ORDER BY f.number");
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
    WHERE (r.status = 'archived' OR r.status = 'inactive') AND r.estate_id = $estate_id 
    ORDER BY r.id DESC
");
$archived_domestic = $conn->query("SELECT * FROM household_staff WHERE status = 'inactive' AND estate_id = $estate_id ORDER BY name");
$archived_estate = $conn->query("SELECT s.*, u.name, u.email, u.phone as u_phone FROM estate_staff s JOIN users u ON s.user_id = u.id WHERE s.status = 'inactive' AND s.estate_id = $estate_id ORDER BY u.name");

// Flats list for re-onboarding
$all_available_flats = $conn->query("
    SELECT f.id, f.number, b.name as building_name, s.name as street_name 
    FROM flats f 
    JOIN buildings b ON f.building_id = b.id 
    JOIN streets s ON b.street_id = s.id 
    WHERE f.estate_id = $estate_id 
    ORDER BY s.name, b.name, f.number
");
$flats_opts = [];
if ($all_available_flats) {
    while($fl = $all_available_flats->fetch_assoc()) {
        $flats_opts[] = $fl;
    }
}

$all_roles = $conn->query("SELECT * FROM roles WHERE estate_id = $estate_id ORDER BY name");

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="mature-badge mature-badge-primary">
                <i class="fa-solid fa-box-archive"></i> Data Preservation
            </span>
            <span class="text-secondary small">&bull; Central Archives</span>
        </div>
        <h2 class="h4 font-bold text-slate-800 m-0">Centralized Archives Vault</h2>
        <p class="text-secondary small mb-0">Record keeping repository for departed residents, inactive staff, and archived estate infrastructure.</p>
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
            <i class="fa-solid fa-users me-1"></i> Resident Archives (<?php echo $archived_residents ? $archived_residents->num_rows : 0; ?>)
        </button>
        <button id="tab-staff-btn" class="tab-btn" onclick="openArchTab(event, 'staff-archives')">
            <i class="fa-solid fa-user-gear me-1"></i> Staff Archives
        </button>
        <button id="tab-prop-btn" class="tab-btn" onclick="openArchTab(event, 'prop-archives')">
            <i class="fa-solid fa-building me-1"></i> Property Archives
        </button>
    </div>

    <!-- Resident Archives -->
    <div id="res-archives" class="tab-content">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 style="margin:0;"><i class="fa-solid fa-user-clock text-secondary me-2"></i> Archived / Departed Residents</h4>
            <span class="small text-muted">Use <strong>Re-onboard</strong> to re-activate returning residents into new units without losing history.</span>
        </div>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; color: #64748b; border-bottom: 1px solid #e2e8f0;">
                    <th style="padding: 0.75rem;">System ID</th>
                    <th style="padding: 0.75rem;">Resident Name</th>
                    <th style="padding: 0.75rem;">Last Address</th>
                    <th style="padding: 0.75rem;">Role / Designation</th>
                    <th style="padding: 0.75rem;">Contact Details</th>
                    <th style="padding: 0.75rem; text-align: right;">Action Provision</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $archived_residents->fetch_assoc()): ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 0.75rem;">
                        <span style="font-family: monospace; font-size: 0.8rem; background: #f1f5f9; padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($row['custom_id'] ?? 'RES-' . $row['id']); ?></span>
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
                        <button type="button" class="btn btn-sm btn-primary" style="padding: 0.28rem 0.65rem; font-size: 0.8rem; margin-right: 0.25rem;" onclick="openReonboardModal(<?php echo htmlspecialchars(json_encode($row)); ?>)" title="Re-onboard / Recreate Resident to Flat">
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
                <?php if($archived_residents->num_rows == 0) echo "<tr><td colspan='6' style='padding:2.5rem; text-align:center; color:#94a3b8;'>No archived residents in the vault.</td></tr>"; ?>
            </tbody>
        </table>
    </div>

    <!-- Staff Archives -->
    <div id="staff-archives" class="tab-content" style="display: none;">
        <h4 style="margin-top:0;">Archived Estate Staff</h4>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 2rem;">
            <thead>
                <tr style="text-align: left; color: #64748b; border-bottom: 1px solid #e2e8f0;">
                    <th style="padding: 0.75rem;">System ID</th>
                    <th style="padding: 0.75rem;">Name</th>
                    <th style="padding: 0.75rem;">Last Role</th>
                    <th style="padding: 0.75rem;">Contact</th>
                    <th style="padding: 0.75rem; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $archived_estate->fetch_assoc()): ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 0.75rem;"><span class="font-monospace small"><?php echo htmlspecialchars($row['custom_id'] ?? 'EST-' . $row['id']); ?></span></td>
                    <td style="padding: 0.75rem; font-weight: 600;"><?php echo htmlspecialchars($row['name']); ?></td>
                    <td style="padding: 0.75rem;"><span class="badge bg-secondary"><?php echo htmlspecialchars($row['role']); ?></span></td>
                    <td style="padding: 0.75rem; font-size: 0.82rem; color: #64748b;"><?php echo htmlspecialchars($row['email']); ?><br><?php echo htmlspecialchars($row['phone'] ?? $row['u_phone']); ?></td>
                    <td style="padding: 0.75rem; text-align: right;">
                        <button type="button" class="btn btn-sm btn-primary" style="padding: 0.25rem 0.65rem; font-size: 0.8rem;" onclick="openRehireStaffModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['name']); ?>', <?php echo intval($row['role_id'] ?? 0); ?>)">
                            <i class="fa-solid fa-user-check me-1"></i> Re-hire / Reactivate
                        </button>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if($archived_estate->num_rows == 0) echo "<tr><td colspan='5' style='padding:1.5rem; text-align:center; color:#94a3b8;'>No archived estate staff found.</td></tr>"; ?>
            </tbody>
        </table>

        <h4>Archived Domestic / Household Staff</h4>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; color: #64748b; border-bottom: 1px solid #e2e8f0;">
                    <th style="padding: 0.75rem;">Name</th>
                    <th style="padding: 0.75rem;">Role</th>
                    <th style="padding: 0.75rem;">Phone</th>
                    <th style="padding: 0.75rem; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $archived_domestic->fetch_assoc()): ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 0.75rem; font-weight: 600;"><?php echo htmlspecialchars($row['name']); ?></td>
                    <td style="padding: 0.75rem;"><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($row['role']); ?></span></td>
                    <td style="padding: 0.75rem; font-family: monospace; font-size: 0.85rem;"><?php echo htmlspecialchars($row['phone'] ?: '-'); ?></td>
                    <td style="padding: 0.75rem; text-align: right;">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="table" value="household_staff">
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="restore_item" class="btn btn-sm btn-outline-primary" style="padding: 0.25rem 0.75rem;"><i class="fa-solid fa-trash-arrow-up"></i> Restore</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if($archived_domestic->num_rows == 0) echo "<tr><td colspan='4' style='padding:1.5rem; text-align:center; color:#94a3b8;'>No archived domestic staff found.</td></tr>"; ?>
            </tbody>
        </table>
    </div>

    <!-- Property Archives -->
    <div id="prop-archives" class="tab-content" style="display: none;">
        <h4 style="margin-top:0;">Archived Streets</h4>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 2rem;">
            <thead>
                <tr style="text-align: left; color: #64748b; border-bottom: 1px solid #e2e8f0;">
                    <th style="padding: 0.75rem;">Name</th>
                    <th style="padding: 0.75rem; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $archived_streets->fetch_assoc()): ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 0.75rem;"><?php echo htmlspecialchars($row['name']); ?></td>
                    <td style="padding: 0.75rem; text-align: right;">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="table" value="streets">
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="restore_item" class="btn" style="background: #eff6ff; color: #2563eb; padding: 0.25rem 0.75rem;"><i class="fa-solid fa-trash-arrow-up"></i> Restore</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if($archived_streets->num_rows == 0) echo "<tr><td colspan='2' style='padding:1rem; text-align:center; color:#94a3b8;'>No archived streets found.</td></tr>"; ?>
            </tbody>
        </table>

        <h4>Archived Buildings</h4>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 2rem;">
            <thead>
                <tr style="text-align: left; color: #64748b; border-bottom: 1px solid #e2e8f0;">
                    <th style="padding: 0.75rem;">Name</th>
                    <th style="padding: 0.75rem;">Street</th>
                    <th style="padding: 0.75rem; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $archived_buildings->fetch_assoc()): ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 0.75rem;"><?php echo htmlspecialchars($row['name']); ?></td>
                    <td style="padding: 0.75rem;"><?php echo htmlspecialchars($row['street_name']); ?></td>
                    <td style="padding: 0.75rem; text-align: right;">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="table" value="buildings">
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="restore_item" class="btn" style="background: #eff6ff; color: #2563eb; padding: 0.25rem 0.75rem;"><i class="fa-solid fa-trash-arrow-up"></i> Restore</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if($archived_buildings->num_rows == 0) echo "<tr><td colspan='3' style='padding:1rem; text-align:center; color:#94a3b8;'>No archived buildings found.</td></tr>"; ?>
            </tbody>
        </table>

        <h4>Archived Flats</h4>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; color: #64748b; border-bottom: 1px solid #e2e8f0;">
                    <th style="padding: 0.75rem;">Number</th>
                    <th style="padding: 0.75rem;">Building</th>
                    <th style="padding: 0.75rem; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $archived_flats->fetch_assoc()): ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 0.75rem;">Flat <?php echo htmlspecialchars($row['number']); ?></td>
                    <td style="padding: 0.75rem;"><?php echo htmlspecialchars($row['building_name']); ?></td>
                    <td style="padding: 0.75rem; text-align: right;">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="table" value="flats">
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="restore_item" class="btn" style="background: #eff6ff; color: #2563eb; padding: 0.25rem 0.75rem;"><i class="fa-solid fa-trash-arrow-up"></i> Restore</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if($archived_flats->num_rows == 0) echo "<tr><td colspan='3' style='padding:1rem; text-align:center; color:#94a3b8;'>No archived flats found.</td></tr>"; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Re-onboard Returning Resident -->
<div class="modal fade" id="reonboardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-0 pt-4 px-4 pb-0">
                <div>
                    <h5 class="modal-title fw-bold text-slate-800"><i class="fa-solid fa-arrows-rotate text-primary me-2"></i> Re-onboard Returning Resident</h5>
                    <p class="text-secondary small mb-0">Assign returning resident <strong id="reonboard_name" class="text-primary"></strong> to an estate flat unit.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="resident_id" id="reonboard_res_id">
                <input type="hidden" name="recreate_resident" value="1">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">SELECT TARGET FLAT / UNIT <span class="text-danger">*</span></label>
                        <select name="flat_id" class="form-control" required>
                            <option value="">-- Choose Residence Flat --</option>
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
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold">
                        <i class="fa-solid fa-check me-1"></i> Confirm Re-onboarding
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Re-hire Staff -->
<div class="modal fade" id="rehireStaffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-0 pt-4 px-4 pb-0">
                <div>
                    <h5 class="modal-title fw-bold text-slate-800"><i class="fa-solid fa-user-check text-success me-2"></i> Re-hire & Reactivate Staff</h5>
                    <p class="text-secondary small mb-0">Re-activate employment for <strong id="rehire_name" class="text-primary"></strong>.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="staff_id" id="rehire_staff_id">
                <input type="hidden" name="recreate_staff" value="1">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">ASSIGNED ROLE / DEPARTMENT <span class="text-danger">*</span></label>
                        <select name="role_id" id="rehire_role_id" class="form-control" required>
                            <?php 
                            if ($all_roles && $all_roles->num_rows > 0):
                                $all_roles->data_seek(0);
                                while($ro = $all_roles->fetch_assoc()): ?>
                                    <option value="<?php echo $ro['id']; ?>"><?php echo htmlspecialchars($ro['name']); ?></option>
                                <?php endwhile;
                            endif; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success rounded-pill px-4 fw-semibold">
                        <i class="fa-solid fa-check me-1"></i> Reactivate Staff Member
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
    color: var(--primary-color);
    border-bottom-color: var(--primary-color);
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

function openRehireStaffModal(staffId, staffName, roleId) {
    document.getElementById('rehire_staff_id').value = staffId;
    document.getElementById('rehire_name').innerText = staffName;
    if (roleId) document.getElementById('rehire_role_id').value = roleId;
    const modal = new bootstrap.Modal(document.getElementById('rehireStaffModal'));
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

<?php include '../includes/footer.php'; ?>
