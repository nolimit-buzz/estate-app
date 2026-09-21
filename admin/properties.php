<?php
// admin/properties.php
require_once '../config.php';
require_once '../includes/auth_guard.php';
requireAdminAccess();

include '../includes/header.php';
include '../includes/sidebar.php';

// Helper to handle uploads
function handleUpload($file) {
    global $conn;
    if ($file['error'] == 0) {
        $target_dir = "../uploads/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $target_file = $target_dir . time() . "_" . basename($file["name"]);
        if (move_uploaded_file($file["tmp_name"], $target_file)) {
            return $conn->real_escape_string($target_file);
        }
    }
    return null;
}

if (!function_exists('generateCustomID')) {
    function generateCustomID($conn, $table, $prefix) {
        $res = $conn->query("SELECT MAX(id) as max_id FROM $table");
        $row = $res->fetch_assoc();
        $next = ($row['max_id'] ?? 0) + 1;
        return $prefix . '-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }
}

// History Logger
function logPropertyHistory($conn, $property_id, $property_type, $action, $old_val = null, $new_val = null, $notes = null) {
    $user_id = $_SESSION['user_id'] ?? null;
    $property_id = intval($property_id);
    $property_type = $conn->real_escape_string($property_type);
    $action = $conn->real_escape_string($action);
    $old_val = $conn->real_escape_string($old_val);
    $new_val = $conn->real_escape_string($new_val);
    $notes = $conn->real_escape_string($notes);
    
    $sql = "INSERT INTO property_history (property_id, property_type, action_type, old_value, new_value, changed_by, notes) 
            VALUES ($property_id, '$property_type', '$action', '$old_val', '$new_val', " . ($user_id ? $user_id : "NULL") . ", '$notes')";
    return $conn->query($sql);
}

// Handle Form Submissions
// Handle Form Submissions
$message = "";
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $message = "Error: " . $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $redirect = false;
    $estate_id = get_estate_id();
    // STREETS
    if (isset($_POST['add_street'])) {
        $reg_date = $conn->real_escape_string($_POST['registration_date']);
        $name = $conn->real_escape_string($_POST['name']);
        $description = $conn->real_escape_string($_POST['description']);
        $zone_id = !empty($_POST['zone_id']) ? intval($_POST['zone_id']) : "NULL";
        
        if (!empty($_POST['street_id'])) {
            // Update
            $id = intval($_POST['street_id']);
            
            // Get old data for history
            $old = $conn->query("SELECT * FROM streets WHERE id=$id")->fetch_assoc();
            
            $updates = "name='$name', zone_id=$zone_id, description='$description', registration_date='$reg_date'";
            $image = handleUpload($_FILES['image']);
            if($image) $updates .= ", image_path='$image'";
            
            if ($conn->query("UPDATE streets SET $updates WHERE id=$id AND estate_id=$estate_id")) {
                $_SESSION['success_message'] = "Street updated successfully!";
                if ($old['name'] != $name) {
                    logPropertyHistory($conn, $id, 'street', 'Renamed', $old['name'], $name);
                }
                $redirect = true;
            }
            else $_SESSION['error_message'] = $conn->error;
        } else {
            // Insert
            $custom_id = generateCustomID($conn, 'streets', 'STR');
            $image = handleUpload($_FILES['image']);
            $sql = "INSERT INTO streets (estate_id, zone_id, custom_id, name, description, image_path, registration_date) VALUES ($estate_id, $zone_id, '$custom_id', '$name', '$description', '$image', '$reg_date')";
            if ($conn->query($sql)) {
                $new_id = $conn->insert_id;
                $_SESSION['success_message'] = "Street added successfully!";
                logPropertyHistory($conn, $new_id, 'street', 'Registered', null, $name);
                $redirect = true;
            }
            else $_SESSION['error_message'] = $conn->error;
        }

    } elseif (isset($_POST['archive_street'])) {
        $id = intval($_POST['archive_id']);
        if ($conn->query("UPDATE streets SET status='archived' WHERE id=$id AND estate_id=$estate_id")) {
            $_SESSION['success_message'] = "Street archived successfully!";
            logPropertyHistory($conn, $id, 'street', 'Archived');
            $redirect = true;
        }
        else $_SESSION['error_message'] = $conn->error;

    } elseif (isset($_POST['add_building'])) {
        $street_id = intval($_POST['street_id']);
        $reg_date = $conn->real_escape_string($_POST['registration_date']);
        $name = $conn->real_escape_string($_POST['name']);
        $property_number = $conn->real_escape_string($_POST['property_number']); // NEW
        $category = $conn->real_escape_string($_POST['category']); // NEW
        $type = $conn->real_escape_string($_POST['type']);
        $floors = $conn->real_escape_string($_POST['total_floors']);
        
        // Handle dynamic commercial data
        $commercial_data = null;
        if ($category == 'Commercial' && isset($_POST['commercial_meta'])) {
            $commercial_data = json_encode($_POST['commercial_meta']);
        }
        $comm_data_sql = $commercial_data ? "'".$conn->real_escape_string($commercial_data)."'" : "NULL";

        if (!empty($_POST['building_id'])) {
             // Update
            $id = intval($_POST['building_id']);
            
            // Get old data
            $old = $conn->query("SELECT * FROM buildings WHERE id=$id")->fetch_assoc();
            
            $status = $conn->real_escape_string($_POST['status']);
            $updates = "street_id=$street_id, name='$name', property_number='$property_number', category='$category', type='$type', total_floors='$floors', status='$status', registration_date='$reg_date', commercial_data=$comm_data_sql";
            $image = handleUpload($_FILES['image']);
            if($image) $updates .= ", image_path='$image'";
            
            if ($conn->query("UPDATE buildings SET $updates WHERE id=$id AND estate_id=$estate_id")) {
                $_SESSION['success_message'] = "Property updated successfully!";
                if ($old['property_number'] != $property_number) logPropertyHistory($conn, $id, 'building', 'Renamed', $old['property_number'], $property_number, "Property number changed");
                if ($old['category'] != $category) logPropertyHistory($conn, $id, 'building', 'Converted', $old['category'], $category, "Property category changed");
                $redirect = true;
            }
            else $_SESSION['error_message'] = $conn->error;
        } else {
            $status = $conn->real_escape_string($_POST['status']);
            $image = handleUpload($_FILES['image']);
            $custom_id = generateCustomID($conn, 'buildings', 'BLD');
            $sql = "INSERT INTO buildings (estate_id, custom_id, street_id, name, property_number, category, type, total_floors, status, image_path, registration_date, commercial_data) 
                    VALUES ($estate_id, '$custom_id', $street_id, '$name', '$property_number', '$category', '$type', '$floors', '$status', '$image', '$reg_date', $comm_data_sql)";
            if ($conn->query($sql)) {
                $new_id = $conn->insert_id;
                $_SESSION['success_message'] = "Property added successfully!";
                logPropertyHistory($conn, $new_id, 'building', 'Registered', null, $property_number);
                $redirect = true;
            }
            else $_SESSION['error_message'] = $conn->error;
        }

    } elseif (isset($_POST['archive_building'])) {
        $id = intval($_POST['archive_id']);
        if ($conn->query("UPDATE buildings SET status='archived' WHERE id=$id AND estate_id=$estate_id")) {
            $_SESSION['success_message'] = "Building archived!";
            logPropertyHistory($conn, $id, 'building', 'Archived');
            $redirect = true;
        }
        else $_SESSION['error_message'] = $conn->error;

    // FLATS
    } elseif (isset($_POST['add_flat'])) {
        $building_id = intval($_POST['building_id']);
        $number = $conn->real_escape_string($_POST['number']);
        $floor = $conn->real_escape_string($_POST['floor']);
        $type = $conn->real_escape_string($_POST['type']);
        
        $reg_date = $conn->real_escape_string($_POST['registration_date']);
        
        if (!empty($_POST['flat_id'])) {
             // Update
            $id = intval($_POST['flat_id']);
            
            // Get old data
            $old = $conn->query("SELECT * FROM flats WHERE id=$id")->fetch_assoc();
            
            $status = $conn->real_escape_string($_POST['status']);
            $updates = "building_id=$building_id, number='$number', floor='$floor', type='$type', status='$status', registration_date='$reg_date'";
            $image = handleUpload($_FILES['image']);
            if($image) $updates .= ", image_path='$image'";
            
            if ($conn->query("UPDATE flats SET $updates WHERE id=$id AND estate_id=$estate_id")) {
                $_SESSION['success_message'] = "Flat updated successfully!";
                if ($old['number'] != $number) logPropertyHistory($conn, $id, 'flat', 'Renamed', $old['number'], $number);
                if ($old['type'] != $type) logPropertyHistory($conn, $id, 'flat', 'Converted', $old['type'], $type);
                if ($old['status'] != $status) logPropertyHistory($conn, $id, 'flat', 'Ownership Changed', $old['status'], $status, "Status changed to $status");
                $redirect = true;
            }
            else $_SESSION['error_message'] = $conn->error;
        } else {
            $status = $conn->real_escape_string($_POST['status']);
            $image = handleUpload($_FILES['image']);
            $custom_id = generateCustomID($conn, 'flats', 'FLT');
            $sql = "INSERT INTO flats (estate_id, custom_id, building_id, number, floor, type, status, image_path, registration_date) VALUES ($estate_id, '$custom_id', $building_id, '$number', '$floor', '$type', '$status', '$image', '$reg_date')";
            if ($conn->query($sql)) {
                $new_id = $conn->insert_id;
                $_SESSION['success_message'] = "Flat added successfully!";
                logPropertyHistory($conn, $new_id, 'flat', 'Registered', null, $number);
                $redirect = true;
            }
            else $_SESSION['error_message'] = $conn->error;
        }
    } elseif (isset($_POST['archive_flat'])) {
        $id = intval($_POST['archive_id']);
        if ($conn->query("UPDATE flats SET status='archived' WHERE id=$id AND estate_id=$estate_id")) {
            $_SESSION['success_message'] = "Flat archived!";
            logPropertyHistory($conn, $id, 'flat', 'Archived');
            $redirect = true;
        }
        else $_SESSION['error_message'] = $conn->error;
    }
    
    if ($redirect) {
        $redirect_tab = 'streets';
        if (isset($_POST['add_street']) || isset($_POST['archive_street'])) {
            $redirect_tab = 'streets';
        } elseif (isset($_POST['add_building']) || isset($_POST['archive_building'])) {
            $redirect_tab = 'buildings';
        } elseif (isset($_POST['add_flat']) || isset($_POST['archive_flat'])) {
            $redirect_tab = 'flats';
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?tab=" . $redirect_tab);
        exit;
    }
}

// Fetch Data
$estate_id = get_estate_id();
$streets = $conn->query("SELECT s.*, z.name as zone_name, z.code as zone_code FROM streets s LEFT JOIN zones z ON s.zone_id = z.id WHERE s.status != 'archived' AND s.estate_id = $estate_id ORDER BY s.name");
$buildings = $conn->query("SELECT b.*, s.name as street_name FROM buildings b JOIN streets s ON b.street_id = s.id WHERE b.status != 'archived' AND b.estate_id = $estate_id ORDER BY b.name");
$flats = $conn->query("SELECT f.*, b.name as building_name, b.street_id, s.name as street_name FROM flats f JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE f.status != 'archived' AND f.estate_id = $estate_id ORDER BY s.name, b.name, f.number");

$all_streets = $conn->query("SELECT * FROM streets WHERE status != 'archived' AND estate_id = $estate_id ORDER BY name");
$building_types = $conn->query("SELECT * FROM building_types ORDER BY name");
$building_floor_types = $conn->query("SELECT * FROM building_floor_types ORDER BY name");
$building_statuses = $conn->query("SELECT * FROM building_statuses ORDER BY name");
$flat_types = $conn->query("SELECT * FROM flat_types ORDER BY name");
$flat_statuses = $conn->query("SELECT * FROM flat_statuses ORDER BY name");
?>

<div class="page-header-futuristic">
    <div>
        <h1 class="h4 font-bold text-slate-900 m-0"><i class="fa-solid fa-building me-2 text-secondary"></i> Property Portfolio & Assets</h1>
        <p class="text-secondary small">Comprehensive directory of estate streets, buildings, and residential/commercial flats</p>
    </div>
    <div class="d-flex gap-2">
        <span class="id-chip"><i class="fa-solid fa-database me-1"></i> Inventory Directory</span>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert mature-card p-3 mb-4 border-0" style="background: rgba(34, 197, 94, 0.1); border-left: 4px solid #16a34a !important; color: #15803d;">
        <i class="fa-solid fa-circle-check me-2"></i> <?php echo $message; ?>
    </div>
<?php endif; ?>

<!-- Futuristic Tabs -->
<?php $active_tab = $_GET['tab'] ?? 'streets'; ?>
<div class="futuristic-tabs">
    <button class="futuristic-tab-btn tab-btn <?php echo $active_tab == 'streets' ? 'active' : ''; ?>" onclick="openTab(event, 'streets')">
        <i class="fa-solid fa-road"></i> Streets
    </button>
    <button class="futuristic-tab-btn tab-btn <?php echo $active_tab == 'buildings' ? 'active' : ''; ?>" onclick="openTab(event, 'buildings')">
        <i class="fa-solid fa-building"></i> Buildings & Properties
    </button>
    <button class="futuristic-tab-btn tab-btn <?php echo $active_tab == 'flats' ? 'active' : ''; ?>" onclick="openTab(event, 'flats')">
        <i class="fa-solid fa-door-open"></i> Flats
    </button>
</div>

<!-- Streets Tab -->
<div id="streets" class="tab-content" style="display: <?php echo $active_tab == 'streets' ? 'block' : 'none'; ?>;">
    <div class="futuristic-table-card">
        <div class="futuristic-table-card-header">
            <h3 class="m-0 fw-bold fs-6 text-slate-900"><i class="fa-solid fa-road text-secondary me-2"></i> Registered Streets</h3>
            <button class="btn btn-sm text-white" style="background: #0f172a;" onclick="openModal('street-modal')"><i class="fa-solid fa-plus me-1"></i> Add Street</button>
        </div>
        
        <!-- Street Modal -->
        <div id="street-modal" class="custom-modal-backdrop">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="street-modal-title">Add Street</h2>
                    <button class="close-modal" onclick="closeModal('street-modal')"><i class="fa-solid fa-times"></i></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="street_id" id="street_id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Street Name</label>
                            <input type="text" name="name" id="street_name" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Assigned Zone <span class="text-danger">*</span></label>
                            <select name="zone_id" id="street_zone_id" class="form-control" required>
                                <option value="">-- Select Zone --</option>
                                <?php 
                                $all_z = $conn->query("SELECT id, name, code FROM zones WHERE estate_id = $estate_id AND status = 'active' ORDER BY name ASC");
                                if ($all_z) {
                                    while($zr = $all_z->fetch_assoc()) {
                                        echo '<option value="' . $zr['id'] . '">' . htmlspecialchars($zr['name'] . ' (' . $zr['code'] . ')') . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Registration Date</label>
                            <input type="date" name="registration_date" id="street_reg_date" value="<?php echo date('Y-m-d'); ?>" class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" id="street_desc" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                         <label>Street Image</label>
                         <input type="file" name="image" accept="image/*" onchange="previewImage(this, 'street_preview')" class="form-control">
                        <img id="street_preview" src="" style="display: none; width: 100%; height: auto; max-height: 200px; object-fit: contain; margin-top: 1rem; border-radius: 0.75rem;">
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem;">
                        <button type="button" class="btn" style="background: #f1f5f9;" onclick="closeModal('street-modal')">Cancel</button>
                        <button type="submit" name="add_street" class="btn btn-primary" id="street_btn">Save Street</button>
                    </div>
                </form>
            </div>
        </div>


        <div class="table-responsive">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>Custom ID</th>
                        <th>Image</th>
                        <th>Name</th>
                        <th>Zone</th>
                        <th>Description</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $streets->fetch_assoc()): ?>
                    <tr>
                        <td><span class="id-chip"><?php echo htmlspecialchars($row['custom_id']); ?></span></td>
                        <td>
                            <?php if($row['image_path']): ?>
                                <img src="<?php echo htmlspecialchars($row['image_path']); ?>" style="width: 38px; height: 38px; object-fit: cover; border-radius: 8px; border: 1px solid #e2e8f0;">
                            <?php else: ?>
                                <div style="width: 38px; height: 38px; background: #f1f5f9; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #94a3b8;"><i class="fa-solid fa-road"></i></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="fw-semibold text-slate-900"><?php echo htmlspecialchars($row['name']); ?></span></td>
                        <td>
                            <?php if(!empty($row['zone_name'])): ?>
                                <span class="badge" style="background: rgba(168, 85, 247, 0.12); color: #7e22ce; border: 1px solid rgba(168, 85, 247, 0.2);">
                                    <i class="fa-solid fa-layer-group me-1"></i> <?php echo htmlspecialchars($row['zone_name']); ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-light text-muted border">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td><small class="text-secondary"><?php echo htmlspecialchars($row['description'] ?: 'No description'); ?></small></td>
                        <td class="text-end">
                            <a href="property_details?type=street&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-secondary py-1 px-2 me-1" title="View History"><i class="fa-solid fa-clock-rotate-left"></i></a>
                            <button onclick='editStreet(<?php echo json_encode($row); ?>)' class="btn btn-sm btn-outline-primary py-1 px-2 me-1" title="Edit"><i class="fa-solid fa-pen-to-square"></i></button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to archive this street?');">
                                <input type="hidden" name="archive_id" value="<?php echo $row['id']; ?>">
                                <button type="submit" name="archive_street" class="btn btn-sm btn-outline-danger py-1 px-2" title="Archive"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Buildings Tab -->
<div id="buildings" class="tab-content" style="display: <?php echo $active_tab == 'buildings' ? 'block' : 'none'; ?>;">
    <div class="futuristic-table-card">
        <div class="futuristic-table-card-header">
            <h3 class="m-0 fw-bold fs-6 text-slate-900"><i class="fa-solid fa-building text-secondary me-2"></i> Properties & Buildings</h3>
            <button class="btn btn-sm text-white" style="background: #0f172a;" onclick="openModal('building-modal')"><i class="fa-solid fa-plus me-1"></i> Add Property</button>
        </div>

        <!-- Building Modal -->
        <div id="building-modal" class="custom-modal-backdrop">
            <div class="modal-content" style="max-width: 700px;">
                <div class="modal-header">
                    <h2 id="building-modal-title">Add Property</h2>
                    <button class="close-modal" onclick="closeModal('building-modal')"><i class="fa-solid fa-times"></i></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="building_id" id="building_id">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Select Street</label>
                            <select name="street_id" id="building_street_id" required class="form-control">
                                <?php 
                                $all_streets->data_seek(0);
                                while($s = $all_streets->fetch_assoc()): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Property Number</label>
                            <input type="text" name="property_number" id="building_property_number" required class="form-control" placeholder="e.g. 10A">
                        </div>
                        <div class="form-group">
                            <label>Building Name (Optional)</label>
                            <input type="text" name="name" id="building_name" class="form-control" placeholder="Display Name">
                        </div>
                        <div class="form-group">
                            <label>Property Category</label>
                            <select name="category" id="building_category" class="form-control" onchange="toggleCommercialFields(this.value)">
                                <option value="Residential">Residential</option>
                                <option value="Commercial">Commercial</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Building Type</label>
                            <select name="type" id="building_type" class="form-control">
                                <?php 
                                $building_types->data_seek(0);
                                while($bt = $building_types->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($bt['name']); ?>"><?php echo htmlspecialchars($bt['name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" id="building_status" class="form-control">
                                <?php 
                                $building_statuses->data_seek(0);
                                while($bs = $building_statuses->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($bs['name']); ?>"><?php echo htmlspecialchars($bs['name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Dynamic Commercial Fields -->
                    <div id="commercial_fields_container" style="display: none; border-top: 1px solid #e2e8f0; margin-top: 1.5rem; padding-top: 1.5rem;">
                        <h4 style="margin-bottom: 1rem; font-size: 0.9rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Commercial Property Details</h4>
                        <div id="dynamic_fields" class="form-grid">
                            <!-- Populated via JS -->
                        </div>
                    </div>

                    <div class="form-grid" style="margin-top: 1.5rem;">
                        <div class="form-group">
                            <label>Floors Description</label>
                            <select name="total_floors" id="building_floors" class="form-control">
                                <?php 
                                $building_floor_types->data_seek(0);
                                while($bft = $building_floor_types->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($bft['name']); ?>"><?php echo htmlspecialchars($bft['name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Registration Date</label>
                            <input type="date" name="registration_date" id="building_reg_date" value="<?php echo date('Y-m-d'); ?>" class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                         <label>Property Image</label>
                         <input type="file" name="image" accept="image/*" onchange="previewImage(this, 'building_preview')" class="form-control">
                         <img id="building_preview" src="" style="display: none; width: 100%; height: auto; max-height: 200px; object-fit: contain; margin-top: 1rem; border-radius: 0.75rem;">
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem;">
                        <button type="button" class="btn" style="background: #f1f5f9;" onclick="closeModal('building-modal')">Cancel</button>
                        <button type="submit" name="add_building" class="btn btn-primary" id="building_btn">Save Property</button>
                    </div>
                </form>
            </div>
        </div>


        <div class="table-responsive">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>Custom ID</th>
                        <th>Image</th>
                        <th>Property Details</th>
                        <th>Street</th>
                        <th>Category</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $buildings->fetch_assoc()): ?>
                    <tr>
                        <td><span class="id-chip"><?php echo htmlspecialchars($row['custom_id']); ?></span></td>
                        <td>
                            <?php if($row['image_path']): ?>
                                <img src="<?php echo htmlspecialchars($row['image_path']); ?>" style="width: 38px; height: 38px; object-fit: cover; border-radius: 8px; border: 1px solid #e2e8f0;">
                            <?php else: ?>
                                <div style="width: 38px; height: 38px; background: #f1f5f9; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #94a3b8;"><i class="fa-solid fa-building"></i></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-semibold text-slate-900"><?php echo htmlspecialchars($row['property_number'] ?: 'No #'); ?></div>
                            <small class="text-secondary"><?php echo htmlspecialchars($row['name'] ?: ''); ?></small>
                        </td>
                        <td><span class="small fw-medium"><?php echo htmlspecialchars($row['street_name']); ?></span></td>
                        <td>
                            <span class="mature-badge <?php echo $row['category'] == 'Commercial' ? 'mature-badge-primary' : 'mature-badge-slate'; ?>">
                                <?php echo htmlspecialchars($row['category']); ?>
                            </span>
                        </td>
                        <td><small class="text-secondary"><?php echo htmlspecialchars($row['type']); ?></small></td>
                        <td>
                            <?php 
                            $bst = strtolower($row['status'] ?? 'vacant');
                            $b_badge = ($bst === 'active' || $bst === 'occupied') ? 'mature-badge-emerald' : 'mature-badge-slate';
                            ?>
                            <span class="mature-badge <?php echo $b_badge; ?>">
                                <?php echo htmlspecialchars($row['status'] ?? 'Vacant'); ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <a href="property_details?type=building&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-secondary py-1 px-2 me-1" title="View History"><i class="fa-solid fa-clock-rotate-left"></i></a>
                            <button onclick='editBuilding(<?php echo json_encode($row); ?>)' class="btn btn-sm btn-outline-primary py-1 px-2 me-1" title="Edit"><i class="fa-solid fa-pen-to-square"></i></button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to archive this building?');">
                                <input type="hidden" name="archive_id" value="<?php echo $row['id']; ?>">
                                <button type="submit" name="archive_building" class="btn btn-sm btn-outline-danger py-1 px-2" title="Archive"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Flats Tab -->
<div id="flats" class="tab-content" style="display: <?php echo $active_tab == 'flats' ? 'block' : 'none'; ?>;">
    <div class="futuristic-table-card">
        <div class="futuristic-table-card-header">
            <h3 class="m-0 fw-bold fs-6 text-slate-900"><i class="fa-solid fa-door-open text-secondary me-2"></i> Flats</h3>
            <button class="btn btn-sm text-white" style="background: #0f172a;" onclick="openModal('flat-modal')"><i class="fa-solid fa-plus me-1"></i> Add Flat</button>
        </div>

        <!-- Flat Modal -->
        <div id="flat-modal" class="custom-modal-backdrop">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="flat-modal-title">Add Flat</h2>
                    <button class="close-modal" onclick="closeModal('flat-modal')"><i class="fa-solid fa-times"></i></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="flat_id" id="flat_id">
                    <div class="form-group">
                        <label>Select Street</label>
                        <select id="flat_street_id" class="form-control" onchange="fetchBuildingsForFlat(this.value)">
                            <option value="">Select Street</option>
                            <?php 
                            $all_streets->data_seek(0); 
                            while($s = $all_streets->fetch_assoc()): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Select Building</label>
                        <select name="building_id" id="flat_building_id" required class="form-control">
                            <option value="">Select Street First</option>
                        </select>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Flat Number</label>
                            <input type="text" name="number" id="flat_number" placeholder="e.g. 101" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Floor</label>
                            <input type="text" name="floor" id="flat_floor" placeholder="e.g. 1" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Type</label>
                            <select name="type" id="flat_type" class="form-control">
                                <?php 
                                $flat_types->data_seek(0);
                                while($ft = $flat_types->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($ft['name']); ?>"><?php echo htmlspecialchars($ft['name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" id="flat_status" class="form-control">
                                <?php 
                                $flat_statuses->data_seek(0);
                                while($fs = $flat_statuses->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($fs['name']); ?>"><?php echo htmlspecialchars($fs['name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Registration Date</label>
                            <input type="date" name="registration_date" id="flat_reg_date" value="<?php echo date('Y-m-d'); ?>" class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                         <label>Flat Image</label>
                         <input type="file" name="image" accept="image/*" onchange="previewImage(this, 'flat_preview')" class="form-control">
                         <img id="flat_preview" src="" style="display: none; width: 100%; height: auto; max-height: 200px; object-fit: contain; margin-top: 1rem; border-radius: 0.75rem;">
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem;">
                        <button type="button" class="btn" style="background: #f1f5f9;" onclick="closeModal('flat-modal')">Cancel</button>
                        <button type="submit" name="add_flat" class="btn btn-primary" id="flat_btn">Save Flat</button>
                    </div>
                </form>
            </div>
        </div>


        <div class="table-responsive">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>Custom ID</th>
                        <th>Image</th>
                        <th>Flat Location</th>
                        <th>Floor</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $flats->fetch_assoc()): ?>
                    <tr>
                        <td><span class="id-chip"><?php echo htmlspecialchars($row['custom_id']); ?></span></td>
                        <td>
                            <?php if($row['image_path']): ?>
                                <img src="<?php echo htmlspecialchars($row['image_path']); ?>" style="width: 38px; height: 38px; object-fit: cover; border-radius: 8px; border: 1px solid #e2e8f0;">
                            <?php else: ?>
                                <div style="width: 38px; height: 38px; background: #f1f5f9; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #94a3b8;"><i class="fa-solid fa-door-open"></i></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-semibold text-slate-900">Flat <?php echo htmlspecialchars($row['number']); ?></div>
                            <small class="text-secondary"><?php echo htmlspecialchars($row['building_name'] . ' • ' . $row['street_name']); ?></small>
                        </td>
                        <td><small class="text-secondary"><?php echo htmlspecialchars($row['floor']); ?></small></td>
                        <td><small class="text-secondary"><?php echo htmlspecialchars($row['type']); ?></small></td>
                        <td>
                            <?php 
                            $fst = strtolower($row['status'] ?? 'vacant');
                            $f_badge = ($fst === 'occupied') ? 'mature-badge-emerald' : (($fst === 'maintenance') ? 'mature-badge-amber' : 'mature-badge-slate');
                            ?>
                            <span class="mature-badge <?php echo $f_badge; ?>">
                                <?php echo htmlspecialchars(ucfirst($row['status'] ?? 'Vacant')); ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <a href="property_details?type=flat&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-secondary py-1 px-2 me-1" title="View History"><i class="fa-solid fa-clock-rotate-left"></i></a>
                            <button onclick='editFlat(<?php echo json_encode($row); ?>)' class="btn btn-sm btn-outline-primary py-1 px-2 me-1" title="Edit"><i class="fa-solid fa-pen-to-square"></i></button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to archive this flat?');">
                                <input type="hidden" name="archive_id" value="<?php echo $row['id']; ?>">
                                <button type="submit" name="archive_flat" class="btn btn-sm btn-outline-danger py-1 px-2" title="Archive"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<script>
function openTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName("tab-content");
    for (i = 0; i < tabcontent.length; i++) {
        tabcontent[i].style.display = "none";
    }
    tablinks = document.getElementsByClassName("tab-btn");
    for (i = 0; i < tablinks.length; i++) {
        tablinks[i].classList.remove("active");
        tablinks[i].style.color = "";
        tablinks[i].style.borderBottom = "";
    }
    document.getElementById(tabName).style.display = "block";
    evt.currentTarget.classList.add("active");
    evt.currentTarget.style.color = "";
    evt.currentTarget.style.borderBottom = "";
    
    if (window.history.replaceState) {
        const url = new URL(window.location);
        url.searchParams.set('tab', tabName);
        window.history.replaceState({}, '', url);
    }
}

function previewImage(input, previewId) {
    var preview = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
        }
        reader.readAsDataURL(input.files[0]);
    }
}

// Modal Management
function openModal(id) {
    document.getElementById(id).style.display = 'flex';
    if (id === 'street-modal') {
        document.getElementById('street-modal-title').innerText = 'Add Street';
        document.getElementById('street_id').value = '';
        document.getElementById('street_name').value = '';
        document.getElementById('street_desc').value = '';
        if (document.getElementById('street_zone_id')) {
            document.getElementById('street_zone_id').value = '';
        }
        document.getElementById('street_reg_date').value = '<?php echo date('Y-m-d'); ?>';
        document.getElementById('street_preview').style.display = 'none';
    }
    if (id === 'building-modal') {
        document.getElementById('building-modal-title').innerText = 'Add Property';
        document.getElementById('building_id').value = '';
        document.getElementById('building_property_number').value = '';
        document.getElementById('building_name').value = '';
        document.getElementById('building_category').value = 'Residential';
        document.getElementById('building_reg_date').value = '<?php echo date('Y-m-d'); ?>';
        document.getElementById('building_preview').style.display = 'none';
        toggleCommercialFields('Residential'); // Hide dynamic fields
    }
    if (id === 'flat-modal') {
        document.getElementById('flat-modal-title').innerText = 'Add Flat';
        document.getElementById('flat_id').value = '';
        document.getElementById('flat_number').value = '';
        document.getElementById('flat_floor').value = '';
        document.getElementById('flat_reg_date').value = '<?php echo date('Y-m-d'); ?>';
        document.getElementById('flat_preview').style.display = 'none';
    }
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

// Dynamic Fields Logic
let commercialFieldsDef = [];

function toggleCommercialFields(category) {
    const container = document.getElementById('commercial_fields_container');
    const dynamicFieldsDiv = document.getElementById('dynamic_fields');
    
    if (category === 'Commercial') {
        container.style.display = 'block';
        if (commercialFieldsDef.length === 0) {
            fetch('../api/get_commercial_fields')
                .then(res => res.json())
                .then(res => {
                    if (res.success) {
                        commercialFieldsDef = res.data;
                        renderDynamicFields(dynamicFieldsDiv);
                    }
                });
        } else {
            renderDynamicFields(dynamicFieldsDiv);
        }
    } else {
        container.style.display = 'none';
    }
}

function renderDynamicFields(target, values = {}) {
    target.innerHTML = '';
    commercialFieldsDef.forEach(field => {
        const div = document.createElement('div');
        div.className = 'form-group';
        
        let inputHtml = '';
        const val = values[field.id] || '';
        const required = field.is_required ? 'required' : '';

        if (field.field_type === 'dropdown') {
            const options = field.field_options.split(',').map(o => o.trim());
            inputHtml = `<select name="commercial_meta[${field.id}]" class="form-control" ${required}>
                            <option value="">Select ${field.field_label}</option>
                            ${options.map(o => `<option value="${o}" ${val == o ? 'selected' : ''}>${o}</option>`).join('')}
                         </select>`;
        } else {
            inputHtml = `<input type="${field.field_type}" name="commercial_meta[${field.id}]" value="${val}" class="form-control" ${required} placeholder="${field.field_label}">`;
        }

        div.innerHTML = `<label>${field.field_label}</label>${inputHtml}`;
        target.appendChild(div);
    });
    
    if (commercialFieldsDef.length === 0) {
        target.innerHTML = '<div style="grid-column: span 2; color: #94a3b8; font-size: 0.875rem;">No custom fields configured for commercial properties. Edit them in Settings.</div>';
    }
}

function editStreet(data) {
    openModal('street-modal');
    document.getElementById('street-modal-title').innerText = 'Update Street';
    document.getElementById('street_id').value = data.id;
    document.getElementById('street_name').value = data.name;
    document.getElementById('street_desc').value = data.description || '';
    document.getElementById('street_reg_date').value = data.registration_date || '<?php echo date('Y-m-d'); ?>';
    if (document.getElementById('street_zone_id')) {
        document.getElementById('street_zone_id').value = data.zone_id || '';
    }
    if (data.image_path) {
        document.getElementById('street_preview').src = data.image_path;
        document.getElementById('street_preview').style.display = 'block';
    } else {
        document.getElementById('street_preview').style.display = 'none';
    }
}

function editBuilding(data) {
    openModal('building-modal');
    document.getElementById('building-modal-title').innerText = 'Update Property';
    document.getElementById('building_id').value = data.id;
    document.getElementById('building_street_id').value = data.street_id;
    document.getElementById('building_property_number').value = data.property_number || '';
    document.getElementById('building_name').value = data.name || '';
    document.getElementById('building_category').value = data.category || 'Residential';
    document.getElementById('building_type').value = data.type;
    document.getElementById('building_floors').value = data.total_floors;
    document.getElementById('building_status').value = data.status || 'Vacant';
    document.getElementById('building_reg_date').value = data.registration_date || '<?php echo date('Y-m-d'); ?>';
    
    if (data.category === 'Commercial') {
        toggleCommercialFields('Commercial');
        // Wait for fields to load if necessary, or just wait for render
        setTimeout(() => {
            let commData = {};
            try { commData = JSON.parse(data.commercial_data || '{}'); } catch(e) {}
            renderDynamicFields(document.getElementById('dynamic_fields'), commData);
        }, 500);
    }

    if(data.image_path) {
        document.getElementById('building_preview').src = data.image_path;
        document.getElementById('building_preview').style.display = 'block';
    } else {
        document.getElementById('building_preview').style.display = 'none';
    }
}

function editFlat(data) {
    openModal('flat-modal');
    document.getElementById('flat-modal-title').innerText = 'Update Flat';
    document.getElementById('flat_id').value = data.id;
    
    // Pre-select street and fetch buildings
    const streetSelect = document.getElementById('flat_street_id');
    if(streetSelect && data.street_id) {
        streetSelect.value = data.street_id;
        fetchBuildingsForFlat(data.street_id, data.building_id);
    } else {
        // Fallback if no street selected (shouldn't happen with correct query)
        document.getElementById('flat_building_id').innerHTML = `<option value="${data.building_id}" selected>${data.building_name}</option>`;
    }

    document.getElementById('flat_number').value = data.number;
    document.getElementById('flat_floor').value = data.floor;
    document.getElementById('flat_type').value = data.type;
    document.getElementById('flat_status').value = data.status || 'Vacant';
    document.getElementById('flat_reg_date').value = data.registration_date || '<?php echo date('Y-m-d'); ?>';
    
    if(data.image_path) {
        document.getElementById('flat_preview').src = data.image_path;
        document.getElementById('flat_preview').style.display = 'block';
    } else {
        document.getElementById('flat_preview').style.display = 'none';
    }
}

function fetchBuildingsForFlat(streetId, selectedBuildingId = null) {
    const buildingSelect = document.getElementById('flat_building_id');
    buildingSelect.innerHTML = '<option value="">Loading...</option>';

    if (!streetId) {
        buildingSelect.innerHTML = '<option value="">Select Street First</option>';
        return;
    }

    fetch(`../api/get_buildings?street_id=${streetId}`)
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                let html = '<option value="">Select Building</option>';
                res.data.forEach(b => {
                    html += `<option value="${b.id}" ${selectedBuildingId == b.id ? 'selected' : ''}>${b.name}</option>`;
                });
                buildingSelect.innerHTML = html;
            }
        });
}
</script>

<?php include '../includes/footer.php'; ?>

