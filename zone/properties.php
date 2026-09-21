<?php
// zone/properties.php - Zonal Real Estate & Assets Registry (Streets, Buildings, Flats)
require_once '../config.php';
require_once '../includes/auth_guard.php';

requireZoneAccess();

$zone_id = get_current_zone_id();
$estate_id = get_estate_id();
$message = $flash_message ?? "";
$error = $flash_error ?? "";

function handleUpload($file) {
    if (isset($file) && $file['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $filename = $file['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $dest_dir = '../uploads/properties/';
            if (!is_dir($dest_dir)) mkdir($dest_dir, 0777, true);
            $new_name = uniqid('prop_') . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $dest_dir . $new_name)) {
                return 'uploads/properties/' . $new_name;
            }
        }
    }
    return null;
}

// -------------------------------------------------------------
// POST ACTIONS: SCOPED TO ZONE (PRG Protected)
// -------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 1. STREETS (LOCKED TO ZONE_ID)
    if (isset($_POST['add_street'])) {
        $reg_date = $conn->real_escape_string($_POST['registration_date'] ?? date('Y-m-d'));
        $name = trim($conn->real_escape_string($_POST['name'] ?? ''));
        $description = trim($conn->real_escape_string($_POST['description'] ?? ''));
        
        if (empty($name)) {
            redirectWithFlash('properties', null, "Street name cannot be empty.");
        }

        if (!empty($_POST['street_id'])) {
            $id = intval($_POST['street_id']);
            // Verify ownership
            $chk = $conn->query("SELECT id FROM streets WHERE id = $id AND zone_id = $zone_id AND estate_id = $estate_id LIMIT 1");
            if ($chk && $chk->num_rows > 0) {
                // Check name collision
                $chk_dup = $conn->query("SELECT id FROM streets WHERE name = '$name' AND id != $id AND zone_id = $zone_id AND estate_id = $estate_id AND status != 'archived' LIMIT 1");
                if ($chk_dup && $chk_dup->num_rows > 0) {
                    redirectWithFlash('properties', null, "Another street named '$name' already exists in your zone.");
                }

                $updates = "name='$name', description='$description', registration_date='$reg_date'";
                $image = handleUpload($_FILES['image'] ?? null);
                if ($image) $updates .= ", image_path='$image'";
                if ($conn->query("UPDATE streets SET $updates WHERE id = $id AND zone_id = $zone_id AND estate_id = $estate_id")) {
                    redirectWithFlash('properties', "Street updated successfully!");
                } else {
                    redirectWithFlash('properties', null, "Error updating street: " . $conn->error);
                }
            } else {
                redirectWithFlash('properties', null, "Unauthorized street modification.");
            }
        } else {
            // Check duplicate street name in this zone
            $chk_dup = $conn->query("SELECT id FROM streets WHERE name = '$name' AND zone_id = $zone_id AND estate_id = $estate_id AND status != 'archived' LIMIT 1");
            if ($chk_dup && $chk_dup->num_rows > 0) {
                redirectWithFlash('properties', null, "A street named '$name' already exists in this zone.");
            }

            $custom_id = generateCustomID($conn, 'streets', 'STR');
            $image = handleUpload($_FILES['image'] ?? null);
            $sql = "INSERT INTO streets (estate_id, zone_id, custom_id, name, description, image_path, registration_date) 
                    VALUES ($estate_id, $zone_id, '$custom_id', '$name', '$description', '$image', '$reg_date')";
            if ($conn->query($sql)) {
                redirectWithFlash('properties', "Street '$name' added to your zone!");
            } else {
                redirectWithFlash('properties', null, "Error adding street: " . $conn->error);
            }
        }
    } elseif (isset($_POST['archive_street'])) {
        $id = intval($_POST['archive_id']);
        if ($conn->query("UPDATE streets SET status = 'archived' WHERE id = $id AND zone_id = $zone_id AND estate_id = $estate_id")) {
            redirectWithFlash('properties', "Street archived.");
        } else {
            redirectWithFlash('properties', null, $conn->error);
        }
    }

    // 2. BUILDINGS (STREET MUST BE IN ZONE)
    elseif (isset($_POST['add_building'])) {
        $street_id = intval($_POST['street_id']);
        $reg_date = $conn->real_escape_string($_POST['registration_date'] ?? date('Y-m-d'));
        $name = trim($conn->real_escape_string($_POST['name'] ?? ''));
        $prop_num = trim($conn->real_escape_string($_POST['property_number'] ?? ''));
        $category = in_array($_POST['category'] ?? '', ['Residential', 'Commercial']) ? $_POST['category'] : 'Residential';
        $type = $conn->real_escape_string($_POST['type'] ?? 'Detached');
        $floors = $conn->real_escape_string($_POST['total_floors'] ?? '1');
        $status = in_array($_POST['status'] ?? '', ['active', 'archived']) ? $_POST['status'] : 'active';

        // Validate street belongs to zone
        $chk_st = $conn->query("SELECT id FROM streets WHERE id = $street_id AND zone_id = $zone_id AND estate_id = $estate_id LIMIT 1");
        if (!$chk_st || $chk_st->num_rows == 0) {
            redirectWithFlash('properties', null, "Selected street is not within your assigned zone.");
        } else {
            if (!empty($_POST['building_id'])) {
                $b_id = intval($_POST['building_id']);
                // Check building belongs to a street in this zone
                $chk_b = $conn->query("SELECT b.id FROM buildings b JOIN streets s ON b.street_id = s.id WHERE b.id = $b_id AND s.zone_id = $zone_id AND b.estate_id = $estate_id LIMIT 1");
                if ($chk_b && $chk_b->num_rows > 0) {
                    $updates = "street_id=$street_id, property_number='$prop_num', name='$name', category='$category', type='$type', total_floors='$floors', registration_date='$reg_date'";
                    $image = handleUpload($_FILES['image'] ?? null);
                    if ($image) $updates .= ", image_path='$image'";
                    if ($conn->query("UPDATE buildings SET $updates WHERE id = $b_id AND estate_id = $estate_id")) {
                        redirectWithFlash('properties', "Property updated successfully!");
                    } else {
                        redirectWithFlash('properties', null, "Error updating property: " . $conn->error);
                    }
                } else {
                    redirectWithFlash('properties', null, "Unauthorized property modification.");
                }
            } else {
                // Check for duplicate property number on this street
                $chk_dup = $conn->query("SELECT id FROM buildings WHERE property_number = '$prop_num' AND street_id = $street_id AND estate_id = $estate_id AND status != 'archived' LIMIT 1");
                if ($chk_dup && $chk_dup->num_rows > 0) {
                    redirectWithFlash('properties', null, "Property number '$prop_num' already exists on this street.");
                }

                $custom_id = generateCustomID($conn, 'buildings', 'BLD');
                $image = handleUpload($_FILES['image'] ?? null);
                $sql = "INSERT INTO buildings (estate_id, street_id, custom_id, property_number, name, category, type, total_floors, image_path, registration_date) 
                        VALUES ($estate_id, $street_id, '$custom_id', '$prop_num', '$name', '$category', '$type', '$floors', '$image', '$reg_date')";
                if ($conn->query($sql)) {
                    redirectWithFlash('properties', "Property added successfully!");
                } else {
                    redirectWithFlash('properties', null, "Error adding property: " . $conn->error);
                }
            }
        }
    } elseif (isset($_POST['archive_building'])) {
        $id = intval($_POST['archive_id']);
        $chk_b = $conn->query("SELECT b.id FROM buildings b JOIN streets s ON b.street_id = s.id WHERE b.id = $id AND s.zone_id = $zone_id AND b.estate_id = $estate_id LIMIT 1");
        if ($chk_b && $chk_b->num_rows > 0) {
            $conn->query("UPDATE buildings SET status = 'archived' WHERE id = $id AND estate_id = $estate_id");
            redirectWithFlash('properties', "Property archived.");
        } else {
            redirectWithFlash('properties', null, "Unauthorized.");
        }
    }

    // 3. FLATS (BUILDING MUST BE IN ZONE)
    elseif (isset($_POST['add_flat'])) {
        $building_id = intval($_POST['building_id']);
        $number = trim($conn->real_escape_string($_POST['number'] ?? ''));
        $floor = trim($conn->real_escape_string($_POST['floor'] ?? ''));
        $type = $conn->real_escape_string($_POST['type'] ?? '2 Bedroom');
        $status = in_array($_POST['status'] ?? '', ['occupied', 'vacant', 'maintenance']) ? $_POST['status'] : 'vacant';
        $reg_date = $conn->real_escape_string($_POST['registration_date'] ?? date('Y-m-d'));

        // Validate building belongs to zone
        $chk_b = $conn->query("SELECT b.id FROM buildings b JOIN streets s ON b.street_id = s.id WHERE b.id = $building_id AND s.zone_id = $zone_id AND b.estate_id = $estate_id LIMIT 1");
        if (!$chk_b || $chk_b->num_rows == 0) {
            redirectWithFlash('properties', null, "Selected building does not belong to your assigned zone.");
        } else {
            if (!empty($_POST['flat_id'])) {
                $f_id = intval($_POST['flat_id']);
                $chk_f = $conn->query("SELECT f.id FROM flats f JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE f.id = $f_id AND s.zone_id = $zone_id AND f.estate_id = $estate_id LIMIT 1");
                if ($chk_f && $chk_f->num_rows > 0) {
                    $updates = "building_id=$building_id, number='$number', floor='$floor', type='$type', status='$status', registration_date='$reg_date'";
                    $image = handleUpload($_FILES['image'] ?? null);
                    if ($image) $updates .= ", image_path='$image'";
                    if ($conn->query("UPDATE flats SET $updates WHERE id = $f_id AND estate_id = $estate_id")) {
                        redirectWithFlash('properties', "Flat updated successfully!");
                    } else {
                        redirectWithFlash('properties', null, "Error updating flat: " . $conn->error);
                    }
                } else {
                    redirectWithFlash('properties', null, "Unauthorized flat modification.");
                }
            } else {
                // Check duplicate flat number in this building
                $chk_dup = $conn->query("SELECT id FROM flats WHERE number = '$number' AND building_id = $building_id AND estate_id = $estate_id AND status != 'archived' LIMIT 1");
                if ($chk_dup && $chk_dup->num_rows > 0) {
                    redirectWithFlash('properties', null, "Flat number '$number' already exists in this building.");
                }

                $custom_id = generateCustomID($conn, 'flats', 'FLT');
                $image = handleUpload($_FILES['image'] ?? null);
                $sql = "INSERT INTO flats (estate_id, building_id, custom_id, number, floor, type, status, image_path, registration_date) 
                        VALUES ($estate_id, $building_id, '$custom_id', '$number', '$floor', '$type', '$status', '$image', '$reg_date')";
                if ($conn->query($sql)) {
                    redirectWithFlash('properties', "Flat added successfully!");
                } else {
                    redirectWithFlash('properties', null, "Error adding flat: " . $conn->error);
                }
            }
        }
    } elseif (isset($_POST['archive_flat'])) {
        $id = intval($_POST['archive_id']);
        $chk_f = $conn->query("SELECT f.id FROM flats f JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE f.id = $id AND s.zone_id = $zone_id AND f.estate_id = $estate_id LIMIT 1");
        if ($chk_f && $chk_f->num_rows > 0) {
            $conn->query("UPDATE flats SET status = 'maintenance' WHERE id = $id AND estate_id = $estate_id");
            redirectWithFlash('properties', "Flat archived.");
        }
    }
}


// -------------------------------------------------------------
// SCOPED QUERIES
// -------------------------------------------------------------
$streets = $conn->query("SELECT * FROM streets WHERE zone_id = $zone_id AND status != 'archived' AND estate_id = $estate_id ORDER BY name ASC");
$buildings = $conn->query("SELECT b.*, s.name as street_name FROM buildings b JOIN streets s ON b.street_id = s.id WHERE s.zone_id = $zone_id AND b.status != 'archived' AND b.estate_id = $estate_id ORDER BY b.name ASC");
$flats = $conn->query("SELECT f.*, b.name as building_name, s.name as street_name FROM flats f JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE s.zone_id = $zone_id AND f.estate_id = $estate_id ORDER BY s.name, b.name, f.number ASC");

$all_streets = $conn->query("SELECT * FROM streets WHERE zone_id = $zone_id AND status != 'archived' AND estate_id = $estate_id ORDER BY name ASC");
$all_buildings = $conn->query("SELECT b.id, b.name, s.name as street_name FROM buildings b JOIN streets s ON b.street_id = s.id WHERE s.zone_id = $zone_id AND b.status != 'archived' AND b.estate_id = $estate_id ORDER BY s.name, b.name ASC");

$active_tab = $_GET['tab'] ?? 'streets';

include 'header.php';
include 'sidebar.php';
?>

<div class="content-header d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="index">Zone Dashboard</a></li>
                <li class="breadcrumb-item active">Streets & Properties</li>
            </ol>
        </nav>
        <h2 class="mb-0 fw-bold" style="letter-spacing: -0.02em;">Zonal Streets & Property Registry</h2>
        <p class="text-secondary small mb-0">Manage streets, residential buildings, and apartments strictly belonging to <?php echo htmlspecialchars($_SESSION['zone_name'] ?? 'your zone'); ?>.</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 shadow-sm" role="alert">
        <i class="fa-solid fa-circle-check fs-5"></i>
        <div><?php echo htmlspecialchars($message); ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 shadow-sm" role="alert">
        <i class="fa-solid fa-circle-exclamation fs-5"></i>
        <div><?php echo htmlspecialchars($error); ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Tab Switcher -->
<div class="futuristic-tabs mb-4">
    <button class="futuristic-tab-btn tab-btn <?php echo $active_tab == 'streets' ? 'active' : ''; ?>" onclick="openTab(event, 'streets')">
        <i class="fa-solid fa-road"></i> Streets (<?php echo $streets ? $streets->num_rows : 0; ?>)
    </button>
    <button class="futuristic-tab-btn tab-btn <?php echo $active_tab == 'buildings' ? 'active' : ''; ?>" onclick="openTab(event, 'buildings')">
        <i class="fa-solid fa-building"></i> Buildings & Properties (<?php echo $buildings ? $buildings->num_rows : 0; ?>)
    </button>
    <button class="futuristic-tab-btn tab-btn <?php echo $active_tab == 'flats' ? 'active' : ''; ?>" onclick="openTab(event, 'flats')">
        <i class="fa-solid fa-door-open"></i> Flats (<?php echo $flats ? $flats->num_rows : 0; ?>)
    </button>
</div>

<!-- 1. STREETS TAB -->
<div id="streets" class="tab-content" style="display: <?php echo $active_tab == 'streets' ? 'block' : 'none'; ?>;">
    <div class="futuristic-table-card">
        <div class="futuristic-table-card-header">
            <h3 class="m-0 fw-bold fs-6 text-slate-900"><i class="fa-solid fa-road text-purple me-2" style="color: #9333ea;"></i> Zone Streets</h3>
            <button class="btn btn-sm text-white" style="background: #6b21a8;" onclick="openModal('street-modal')"><i class="fa-solid fa-plus me-1"></i> Register Street</button>
        </div>
        <div class="table-responsive">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>Custom ID</th>
                        <th>Street Name</th>
                        <th>Description</th>
                        <th>Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($streets && $streets->num_rows > 0): ?>
                        <?php while($s = $streets->fetch_assoc()): ?>
                            <tr>
                                <td><span class="id-chip"><?php echo htmlspecialchars($s['custom_id']); ?></span></td>
                                <td><span class="fw-bold text-dark"><?php echo htmlspecialchars($s['name']); ?></span></td>
                                <td><small class="text-secondary"><?php echo htmlspecialchars($s['description'] ?: 'No description'); ?></small></td>
                                <td><small class="text-muted"><?php echo date('M d, Y', strtotime($s['registration_date'] ?: $s['created_at'])); ?></small></td>
                                <td class="text-end">
                                    <button onclick='editStreet(<?php echo json_encode($s); ?>)' class="btn btn-sm btn-outline-primary py-1 px-2 me-1" title="Edit"><i class="fa-solid fa-pen-to-square"></i></button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this street?');">
                                        <input type="hidden" name="archive_id" value="<?php echo $s['id']; ?>">
                                        <button type="submit" name="archive_street" class="btn btn-sm btn-outline-danger py-1 px-2"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">No streets registered in this zone yet. Click "Register Street" to add one.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 2. BUILDINGS TAB -->
<div id="buildings" class="tab-content" style="display: <?php echo $active_tab == 'buildings' ? 'block' : 'none'; ?>;">
    <div class="futuristic-table-card">
        <div class="futuristic-table-card-header">
            <h3 class="m-0 fw-bold fs-6 text-slate-900"><i class="fa-solid fa-building text-primary me-2"></i> Zone Buildings & Properties</h3>
            <button class="btn btn-sm text-white" style="background: #6b21a8;" onclick="openModal('building-modal')"><i class="fa-solid fa-plus me-1"></i> Register Property</button>
        </div>
        <div class="table-responsive">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>Custom ID</th>
                        <th>Property Name</th>
                        <th>Street</th>
                        <th>Type / Category</th>
                        <th>Floors</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($buildings && $buildings->num_rows > 0): ?>
                        <?php while($b = $buildings->fetch_assoc()): ?>
                            <tr>
                                <td><span class="id-chip"><?php echo htmlspecialchars($b['custom_id']); ?></span></td>
                                <td>
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($b['name']); ?></div>
                                    <small class="text-muted">No: <?php echo htmlspecialchars($b['property_number'] ?: 'N/A'); ?></small>
                                </td>
                                <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($b['street_name']); ?></span></td>
                                <td><?php echo htmlspecialchars($b['type'] . ' (' . $b['category'] . ')'); ?></td>
                                <td><?php echo htmlspecialchars($b['total_floors']); ?> Floor(s)</td>
                                <td class="text-end">
                                    <button onclick='editBuilding(<?php echo json_encode($b); ?>)' class="btn btn-sm btn-outline-primary py-1 px-2 me-1" title="Edit"><i class="fa-solid fa-pen-to-square"></i></button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this property?');">
                                        <input type="hidden" name="archive_id" value="<?php echo $b['id']; ?>">
                                        <button type="submit" name="archive_building" class="btn btn-sm btn-outline-danger py-1 px-2"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">No buildings registered yet in this zone.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 3. FLATS TAB -->
<div id="flats" class="tab-content" style="display: <?php echo $active_tab == 'flats' ? 'block' : 'none'; ?>;">
    <div class="futuristic-table-card">
        <div class="futuristic-table-card-header">
            <h3 class="m-0 fw-bold fs-6 text-slate-900"><i class="fa-solid fa-door-open text-success me-2"></i> Zone Flats</h3>
            <button class="btn btn-sm text-white" style="background: #6b21a8;" onclick="openModal('flat-modal')"><i class="fa-solid fa-plus me-1"></i> Add Flat</button>
        </div>
        <div class="table-responsive">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>Custom ID</th>
                        <th>Flat Number</th>
                        <th>Building / Street</th>
                        <th>Type / Floor</th>
                        <th>Occupancy Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($flats && $flats->num_rows > 0): ?>
                        <?php while($f = $flats->fetch_assoc()): ?>
                            <tr>
                                <td><span class="id-chip"><?php echo htmlspecialchars($f['custom_id']); ?></span></td>
                                <td><div class="fw-bold text-dark">Flat <?php echo htmlspecialchars($f['number']); ?></div></td>
                                <td>
                                    <div class="small fw-semibold text-dark"><?php echo htmlspecialchars($f['building_name']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($f['street_name']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($f['type'] . ' • Floor ' . $f['floor']); ?></td>
                                <td>
                                    <?php 
                                    $fst = $f['status'];
                                    $bclass = ($fst == 'occupied') ? 'bg-success' : (($fst == 'vacant') ? 'bg-warning text-dark' : 'bg-secondary');
                                    ?>
                                    <span class="badge <?php echo $bclass; ?> rounded-pill px-2.5 py-1 text-uppercase" style="font-size: 0.7rem;"><?php echo $fst; ?></span>
                                </td>
                                <td class="text-end">
                                    <button onclick='editFlat(<?php echo json_encode($f); ?>)' class="btn btn-sm btn-outline-primary py-1 px-2 me-1" title="Edit"><i class="fa-solid fa-pen-to-square"></i></button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this flat?');">
                                        <input type="hidden" name="archive_id" value="<?php echo $f['id']; ?>">
                                        <button type="submit" name="archive_flat" class="btn btn-sm btn-outline-danger py-1 px-2"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">No flats registered in this zone yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- STREET MODAL -->
<div id="street-modal" class="custom-modal-backdrop">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="street-modal-title">Add Street</h2>
            <button class="close-modal" onclick="closeModal('street-modal')"><i class="fa-solid fa-times"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="street_id" id="street_id">
            <div class="form-group mb-3">
                <label class="form-label small fw-semibold">Street Name <span class="text-danger">*</span></label>
                <input type="text" name="name" id="street_name" required class="form-control" placeholder="e.g. Hilltop Crescent">
            </div>
            <div class="form-group mb-3">
                <label class="form-label small fw-semibold">Registration Date</label>
                <input type="date" name="registration_date" id="street_reg_date" value="<?php echo date('Y-m-d'); ?>" class="form-control">
            </div>
            <div class="form-group mb-3">
                <label class="form-label small fw-semibold">Description / Landmark</label>
                <textarea name="description" id="street_desc" class="form-control" rows="2"></textarea>
            </div>
            <div class="form-group mb-4">
                <label class="form-label small fw-semibold">Street Image (Optional)</label>
                <input type="file" name="image" accept="image/*" class="form-control">
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light" onclick="closeModal('street-modal')">Cancel</button>
                <button type="submit" name="add_street" class="btn btn-primary" style="background: #6b21a8; border-color: #6b21a8;">Save Street</button>
            </div>
        </form>
    </div>
</div>

<!-- BUILDING MODAL -->
<div id="building-modal" class="custom-modal-backdrop">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="building-modal-title">Register Property</h2>
            <button class="close-modal" onclick="closeModal('building-modal')"><i class="fa-solid fa-times"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="building_id" id="building_id">
            <div class="form-group mb-3">
                <label class="form-label small fw-semibold">Street (Zone Scoped) <span class="text-danger">*</span></label>
                <select name="street_id" id="building_street_id" class="form-control" required>
                    <option value="">-- Select Street --</option>
                    <?php if ($all_streets): $all_streets->data_seek(0); while($s = $all_streets->fetch_assoc()): ?>
                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-md-7">
                    <label class="form-label small fw-semibold">Building / House Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="building_name" required class="form-control" placeholder="e.g. Hilltop Heights">
                </div>
                <div class="col-md-5">
                    <label class="form-label small fw-semibold">House / Property #</label>
                    <input type="text" name="property_number" id="building_property_number" class="form-control" placeholder="e.g. 14B">
                </div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Category</label>
                    <select name="category" id="building_category" class="form-control">
                        <option value="Residential">Residential</option>
                        <option value="Commercial">Commercial</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Building Type</label>
                    <select name="type" id="building_type" class="form-control">
                        <option value="Duplex">Duplex</option>
                        <option value="Bungalow">Bungalow</option>
                        <option value="Block of Flats">Block of Flats</option>
                        <option value="Terrace">Terrace</option>
                        <option value="Mansion">Mansion</option>
                    </select>
                </div>
            </div>
            <div class="form-group mb-4">
                <label class="form-label small fw-semibold">Total Floors</label>
                <input type="number" name="total_floors" id="building_floors" value="1" min="1" class="form-control">
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light" onclick="closeModal('building-modal')">Cancel</button>
                <button type="submit" name="add_building" class="btn btn-primary" style="background: #6b21a8; border-color: #6b21a8;">Save Property</button>
            </div>
        </form>
    </div>
</div>

<!-- FLAT MODAL -->
<div id="flat-modal" class="custom-modal-backdrop">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="flat-modal-title">Register Flat</h2>
            <button class="close-modal" onclick="closeModal('flat-modal')"><i class="fa-solid fa-times"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="flat_id" id="flat_id">
            <div class="form-group mb-3">
                <label class="form-label small fw-semibold">Building (In this Zone) <span class="text-danger">*</span></label>
                <select name="building_id" id="flat_building_id" class="form-control" required>
                    <option value="">-- Select Building --</option>
                    <?php if ($all_buildings): $all_buildings->data_seek(0); while($b = $all_buildings->fetch_assoc()): ?>
                        <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name'] . ' (' . $b['street_name'] . ')'); ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Flat Number <span class="text-danger">*</span></label>
                    <input type="text" name="number" id="flat_number" required class="form-control" placeholder="e.g. Flat 1, Suite A">
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Floor</label>
                    <input type="text" name="floor" id="flat_floor" class="form-control" placeholder="Ground, 1st, 2nd">
                </div>
            </div>
            <div class="row g-2 mb-4">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Flat Type</label>
                    <select name="type" id="flat_type" class="form-control">
                        <option value="1 Bedroom">1 Bedroom</option>
                        <option value="2 Bedroom">2 Bedroom</option>
                        <option value="3 Bedroom">3 Bedroom</option>
                        <option value="4 Bedroom">4 Bedroom</option>
                        <option value="Studio">Studio</option>
                        <option value="Penthouse">Penthouse</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Status</label>
                    <select name="status" id="flat_status" class="form-control">
                        <option value="vacant">Vacant</option>
                        <option value="occupied">Occupied</option>
                        <option value="maintenance">Maintenance</option>
                    </select>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light" onclick="closeModal('flat-modal')">Cancel</button>
                <button type="submit" name="add_flat" class="btn btn-primary" style="background: #6b21a8; border-color: #6b21a8;">Save Flat</button>
            </div>
        </form>
    </div>
</div>

<script>
function openTab(evt, tabName) {
    var tabcontent = document.getElementsByClassName("tab-content");
    for (var i = 0; i < tabcontent.length; i++) {
        tabcontent[i].style.display = "none";
    }
    var tablinks = document.getElementsByClassName("tab-btn");
    for (var i = 0; i < tablinks.length; i++) {
        tablinks[i].classList.remove("active");
    }
    document.getElementById(tabName).style.display = "block";
    evt.currentTarget.classList.add("active");
}

function openModal(id) {
    document.getElementById(id).style.display = 'flex';
}
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

function editStreet(data) {
    openModal('street-modal');
    document.getElementById('street-modal-title').innerText = 'Update Street';
    document.getElementById('street_id').value = data.id;
    document.getElementById('street_name').value = data.name;
    document.getElementById('street_desc').value = data.description || '';
}

function editBuilding(data) {
    openModal('building-modal');
    document.getElementById('building-modal-title').innerText = 'Update Property';
    document.getElementById('building_id').value = data.id;
    document.getElementById('building_street_id').value = data.street_id;
    document.getElementById('building_name').value = data.name;
    document.getElementById('building_property_number').value = data.property_number || '';
    document.getElementById('building_category').value = data.category || 'Residential';
    document.getElementById('building_type').value = data.type || 'Duplex';
    document.getElementById('building_floors').value = data.total_floors || '1';
}

function editFlat(data) {
    openModal('flat-modal');
    document.getElementById('flat-modal-title').innerText = 'Update Flat';
    document.getElementById('flat_id').value = data.id;
    document.getElementById('flat_building_id').value = data.building_id;
    document.getElementById('flat_number').value = data.number;
    document.getElementById('flat_floor').value = data.floor;
    document.getElementById('flat_type').value = data.type;
    document.getElementById('flat_status').value = data.status;
}
</script>

<?php include 'footer.php'; ?>
