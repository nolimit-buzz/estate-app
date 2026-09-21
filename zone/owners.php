<?php
// zone/owners.php - Zonal Property Owners Management (Scoped to Zone)
require_once '../config.php';
require_once '../includes/auth_guard.php';

requireZoneAccess();

$zone_id = get_current_zone_id();
$estate_id = get_estate_id();
$message = $flash_message ?? "";
$error = $flash_error ?? "";

// -------------------------------------------------------------
// POST ACTIONS: REGISTER / LINK PROPERTY OWNER (PRG Protected)
// -------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['add_owner'])) {
        $first_name = trim($conn->real_escape_string($_POST['first_name'] ?? ''));
        $last_name = trim($conn->real_escape_string($_POST['last_name'] ?? ''));
        $full_name = trim($first_name . ' ' . $last_name);
        $email = trim($conn->real_escape_string($_POST['email'] ?? ''));
        $phone = trim($conn->real_escape_string($_POST['phone'] ?? ''));
        $ownership_type = $conn->real_escape_string($_POST['ownership_type'] ?? 'Resident Owner');
        $id_type = $conn->real_escape_string($_POST['id_type'] ?? 'National ID');
        $owner_type = in_array($_POST['owner_type'] ?? '', ['building', 'flat']) ? $_POST['owner_type'] : 'flat';
        $property_id = intval($_POST['property_id'] ?? 0);
        $reg_date = $conn->real_escape_string($_POST['registration_date'] ?? date('Y-m-d'));

        // Validate that the property strictly belongs to this zone!
        $is_valid_prop = false;
        if ($owner_type === 'flat') {
            $chk = $conn->query("
                SELECT f.id 
                FROM flats f 
                JOIN buildings b ON f.building_id = b.id 
                JOIN streets s ON b.street_id = s.id 
                WHERE f.id = $property_id AND s.zone_id = $zone_id AND f.estate_id = $estate_id 
                LIMIT 1
            ");
            if ($chk && $chk->num_rows > 0) $is_valid_prop = true;
        } else {
            $chk = $conn->query("
                SELECT b.id 
                FROM buildings b 
                JOIN streets s ON b.street_id = s.id 
                WHERE b.id = $property_id AND s.zone_id = $zone_id AND b.estate_id = $estate_id 
                LIMIT 1
            ");
            if ($chk && $chk->num_rows > 0) $is_valid_prop = true;
        }

        if (!$is_valid_prop) {
            redirectWithFlash('owners', null, "Selected property unit does not belong to your assigned zone.");
        } else {
            // Check if this owner is already linked to this property
            $chk_dup_own = $conn->query("
                SELECT o.id FROM property_owners o 
                JOIN owner_properties op ON o.id = op.owner_id 
                WHERE (o.email = '$email' OR o.phone = '$phone') AND op.property_id = $property_id AND op.property_type = '$owner_type' AND o.estate_id = $estate_id 
                LIMIT 1
            ");
            if ($chk_dup_own && $chk_dup_own->num_rows > 0) {
                redirectWithFlash('owners', null, "This owner is already linked to this property unit.");
            }

            $custom_id = generateCustomID($conn, 'property_owners', 'OWN');
            $sql = "INSERT INTO property_owners (estate_id, custom_id, full_name, first_name, last_name, phone, email, id_type, ownership_type, owner_type, registration_date) 
                    VALUES ($estate_id, '$custom_id', '$full_name', '$first_name', '$last_name', '$phone', '$email', '$id_type', '$ownership_type', '$owner_type', '$reg_date')";
            if ($conn->query($sql)) {
                $owner_id = $conn->insert_id;
                $conn->query("INSERT INTO owner_properties (estate_id, owner_id, property_id, property_type) VALUES ($estate_id, $owner_id, $property_id, '$owner_type')");
                redirectWithFlash('owners', "Property owner '$full_name' registered to zone successfully!");
            } else {
                redirectWithFlash('owners', null, "Error saving property owner: " . $conn->error);
            }
        }
    }
}


// -------------------------------------------------------------
// FETCH ZONE OWNERS
// -------------------------------------------------------------
$owners = $conn->query("
    SELECT DISTINCT po.*, op.property_id, op.property_type,
           CASE 
               WHEN op.property_type = 'flat' THEN CONCAT('Flat ', f.number, ' (', fb.name, ', ', s_f.name, ')')
               ELSE CONCAT(b.name, ' (', s_b.name, ')')
           END as property_label
    FROM property_owners po 
    JOIN owner_properties op ON po.id = op.owner_id 
    LEFT JOIN buildings b ON op.property_type = 'building' AND op.property_id = b.id 
    LEFT JOIN streets s_b ON b.street_id = s_b.id
    LEFT JOIN flats f ON op.property_type = 'flat' AND op.property_id = f.id 
    LEFT JOIN buildings fb ON f.building_id = fb.id 
    LEFT JOIN streets s_f ON fb.street_id = s_f.id
    WHERE (s_b.zone_id = $zone_id OR s_f.zone_id = $zone_id) AND po.estate_id = $estate_id
    ORDER BY po.id DESC
");

// Available properties in this zone for dropdown
$available_flats = $conn->query("
    SELECT f.id, f.number, b.name as building_name, s.name as street_name 
    FROM flats f 
    JOIN buildings b ON f.building_id = b.id 
    JOIN streets s ON b.street_id = s.id 
    WHERE s.zone_id = $zone_id AND f.estate_id = $estate_id 
    ORDER BY s.name, b.name, f.number ASC
");

$available_buildings = $conn->query("
    SELECT b.id, b.name, s.name as street_name 
    FROM buildings b 
    JOIN streets s ON b.street_id = s.id 
    WHERE s.zone_id = $zone_id AND b.estate_id = $estate_id 
    ORDER BY s.name, b.name ASC
");

include 'header.php';
include 'sidebar.php';
?>

<div class="content-header d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="index">Zone Dashboard</a></li>
                <li class="breadcrumb-item active">Property Owners</li>
            </ol>
        </nav>
        <h2 class="mb-0 fw-bold" style="letter-spacing: -0.02em;">Zone Property Owners</h2>
        <p class="text-secondary small mb-0">Manage landlords, titleholders, and resident property owners in <?php echo htmlspecialchars($_SESSION['zone_name'] ?? 'your zone'); ?>.</p>
    </div>
    <div>
        <button class="btn btn-primary d-inline-flex align-items-center gap-2 shadow-sm" style="background: #6b21a8; border-color: #6b21a8;" data-bs-toggle="modal" data-bs-target="#ownerModal">
            <i class="fa-solid fa-user-shield"></i>
            <span>Register Property Owner</span>
        </button>
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

<div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
    <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold d-flex align-items-center gap-2 text-dark">
            <i class="fa-solid fa-user-shield text-purple" style="color: #9333ea;"></i>
            <span>Property Owners in this Zone</span>
        </h5>
        <span class="badge bg-light text-secondary border px-3 py-2 rounded-pill"><?php echo $owners ? $owners->num_rows : 0; ?> Owner(s)</span>
    </div>

    <div class="table-responsive">
        <table class="table align-middle mb-0 table-hover">
            <thead class="table-light small">
                <tr>
                    <th class="ps-4">Custom ID</th>
                    <th>Owner Name</th>
                    <th>Contact Details</th>
                    <th>Ownership Type</th>
                    <th>Linked Property</th>
                    <th>Registered</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($owners && $owners->num_rows > 0): ?>
                    <?php while ($o = $owners->fetch_assoc()): ?>
                        <tr>
                            <td class="ps-4">
                                <span class="id-chip"><?php echo htmlspecialchars($o['custom_id'] ?? ('OWN-' . $o['id'])); ?></span>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($o['full_name']); ?></div>
                                <small class="text-secondary"><?php echo htmlspecialchars($o['id_type'] ?: 'National ID'); ?></small>
                            </td>
                            <td>
                                <div><i class="fa-solid fa-envelope me-1 text-secondary small"></i> <?php echo htmlspecialchars($o['email']); ?></div>
                                <small class="text-secondary"><i class="fa-solid fa-phone me-1 text-secondary small"></i> <?php echo htmlspecialchars($o['phone']); ?></small>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($o['ownership_type']); ?></span>
                            </td>
                            <td>
                                <div class="small fw-semibold text-dark"><?php echo htmlspecialchars($o['property_label'] ?: 'Unlinked'); ?></div>
                                <small class="text-muted text-uppercase" style="font-size: 0.65rem;"><?php echo htmlspecialchars($o['property_type']); ?></small>
                            </td>
                            <td>
                                <small class="text-muted"><?php echo date('M d, Y', strtotime($o['registration_date'] ?: $o['created_at'])); ?></small>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-secondary">
                            <i class="fa-solid fa-user-shield fs-1 text-muted mb-2 d-block"></i>
                            No property owners registered for this zone yet.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- REGISTER OWNER MODAL -->
<div class="modal fade" id="ownerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-bottom py-3 px-4">
                <h5 class="modal-title fw-bold d-flex align-items-center gap-2">
                    <i class="fa-solid fa-user-shield text-purple" style="color: #9333ea;"></i>
                    <span>Register Property Owner (Zone Scoped)</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="add_owner" value="1">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" required class="form-control" placeholder="e.g. Samuel">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" required class="form-control" placeholder="e.g. Ibrahim">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" required class="form-control" placeholder="samuel@example.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Phone Number <span class="text-danger">*</span></label>
                            <input type="text" name="phone" required class="form-control" placeholder="08098765432">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Ownership Classification</label>
                            <select name="ownership_type" class="form-select">
                                <option value="Resident Landlord">Resident Landlord</option>
                                <option value="Non-Resident Landlord">Non-Resident Landlord</option>
                                <option value="Corporate Owner">Corporate Owner</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">ID Type</label>
                            <select name="id_type" class="form-select">
                                <option value="National ID">National ID (NIN)</option>
                                <option value="International Passport">International Passport</option>
                                <option value="Voters Card">Voter's Card</option>
                                <option value="Driver License">Driver's License</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Property Category</label>
                            <select name="owner_type" id="owner_type_select" class="form-select" onchange="toggleOwnerPropList()">
                                <option value="flat" selected>Flat</option>
                                <option value="building">Whole Building</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold">Select Owned Property (In this Zone) <span class="text-danger">*</span></label>
                            <!-- Flat selector container -->
                            <div id="owner_flat_container">
                                <select name="property_id" id="owner_flat_select" class="form-select" required>
                                    <option value="">-- Choose Flat --</option>
                                    <?php if ($available_flats): $available_flats->data_seek(0); while($af = $available_flats->fetch_assoc()): ?>
                                        <option value="<?php echo $af['id']; ?>">
                                            <?php echo htmlspecialchars($af['street_name'] . ' • ' . $af['building_name'] . ' • Flat ' . $af['number']); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                            </div>
                            <!-- Building selector container (hidden by default) -->
                            <div id="owner_building_container" style="display: none;">
                                <select name="property_id" id="owner_building_select" class="form-select" style="display: none;" disabled>
                                    <option value="">-- Choose Building --</option>
                                    <?php if ($available_buildings): $available_buildings->data_seek(0); while($ab = $available_buildings->fetch_assoc()): ?>
                                        <option value="<?php echo $ab['id']; ?>">
                                            <?php echo htmlspecialchars($ab['name'] . ' (' . $ab['street_name'] . ')'); ?>
                                        </option>
                                    <?php endwhile; endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Registration Date</label>
                            <input type="date" name="registration_date" value="<?php echo date('Y-m-d'); ?>" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top py-3 px-4">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold" style="background: #6b21a8; border-color: #6b21a8;">Save Owner</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleOwnerPropList() {
    const type = document.getElementById('owner_type_select').value;
    const flatCont = document.getElementById('owner_flat_container');
    const bldCont = document.getElementById('owner_building_container');
    const flatSel = document.getElementById('owner_flat_select');
    const bldSel = document.getElementById('owner_building_select');
    if (type === 'building') {
        flatCont.style.display = 'none';
        flatSel.style.display = 'none';
        flatSel.disabled = true;
        flatSel.required = false;
        bldCont.style.display = 'block';
        bldSel.style.display = '';
        bldSel.disabled = false;
        bldSel.required = true;
    } else {
        flatCont.style.display = 'block';
        flatSel.style.display = '';
        flatSel.disabled = false;
        flatSel.required = true;
        bldCont.style.display = 'none';
        bldSel.style.display = 'none';
        bldSel.disabled = true;
        bldSel.required = false;
    }
}
</script>

<?php include 'footer.php'; ?>
