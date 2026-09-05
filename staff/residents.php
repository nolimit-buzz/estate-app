<?php
// staff/residents.php
require_once '../config.php';
require_once '../includes/auth_guard.php';

requireLogin();
if (!isStaffRole() && !isAdminRole()) {
    header("Location: ../index");
    exit;
}

if (!hasPermission('residents.view') && !hasPermission('residents.manage') && !hasPermission('tenancies.manage')) {
    header("Location: index?error=unauthorized");
    exit;
}

$estate_id = get_estate_id();
$message = "";
$message_type = "success";

// Helper to handle uploads
function handleResidentUpload($file) {
    global $conn;
    if (isset($file['error']) && $file['error'] == 0) {
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

// Handle Registering Resident
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_resident'])) {
    if (!hasPermission('residents.manage')) {
        $message = "You do not have permission to register residents.";
        $message_type = "danger";
    } else {
        $first_name = $conn->real_escape_string($_POST['first_name']);
        $last_name = $conn->real_escape_string($_POST['last_name']);
        $name = trim($first_name . ' ' . $last_name);
        $email = $conn->real_escape_string($_POST['email']);
        $phone = $conn->real_escape_string($_POST['phone']);
        $flat_id = intval($_POST['flat_id']);
        $type = $conn->real_escape_string($_POST['type'] ?? 'head');
        $relationship = $conn->real_escape_string($_POST['relationship'] ?? 'Self');
        $reg_date = $conn->real_escape_string($_POST['registration_date']);
        $status = 'active';

        // Check if user already exists
        $user_chk = $conn->query("SELECT id FROM users WHERE email = '$email' AND estate_id = $estate_id");
        if ($user_chk && $user_chk->num_rows > 0) {
            $user_id = $user_chk->fetch_assoc()['id'];
            $conn->query("UPDATE users SET first_name='$first_name', last_name='$last_name', name='$name', phone='$phone' WHERE id=$user_id");
        } else {
            $default_pass = !empty($first_name) ? trim($first_name) : 'welcome123';
            $password = password_hash($default_pass, PASSWORD_DEFAULT);
            $conn->query("INSERT INTO users (estate_id, first_name, last_name, name, email, phone, password, role) 
                          VALUES ($estate_id, '$first_name', '$last_name', '$name', '$email', '$phone', '$password', 'resident')");
            $user_id = $conn->insert_id;
        }

        $custom_id = generateCustomID($conn, 'residents', 'RES');
        $image = handleResidentUpload($_FILES['image']);
        
        $sql = "INSERT INTO residents (estate_id, custom_id, user_id, flat_id, type, relationship, status, image_path, registration_date) 
                VALUES ($estate_id, '$custom_id', $user_id, $flat_id, '$type', '$relationship', '$status', " . ($image ? "'$image'" : "NULL") . ", '$reg_date')";
        
        if ($conn->query($sql)) {
            // Also track active tenancy
            $conn->query("INSERT INTO tenancies (estate_id, resident_id, flat_id, move_in_date, status) VALUES ($estate_id, $user_id, $flat_id, '$reg_date', 'Active')");
            logAudit($conn, "Staff Resident Registered", "Resident Registration", "Staff registered resident $name ($custom_id)");
            $message = "Resident $name registered successfully with ID $custom_id!";
        } else {
            $message = "Error registering resident: " . $conn->error;
            $message_type = "danger";
        }
    }
}

// Fetch Residents Directory
$residents = $conn->query("SELECT r.*, u.first_name, u.last_name, u.name, u.email, u.phone, 
    f.number as flat_number, b.name as building_name, s.name as street_name 
    FROM residents r 
    JOIN users u ON r.user_id = u.id 
    LEFT JOIN flats f ON r.flat_id = f.id 
    LEFT JOIN buildings b ON f.building_id = b.id 
    LEFT JOIN streets s ON b.street_id = s.id 
    WHERE r.estate_id = $estate_id 
    ORDER BY r.id DESC");

// Fetch Flats for Registration Form
$flats_res = $conn->query("SELECT f.id, f.number as flat_number, b.name as building_name, s.name as street_name 
    FROM flats f 
    JOIN buildings b ON f.building_id = b.id 
    JOIN streets s ON b.street_id = s.id 
    WHERE f.estate_id = $estate_id AND f.status = 'vacant' 
    ORDER BY b.name, f.number");

include 'header.php';
include 'sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 font-bold text-slate-800 m-0">Resident Registration & Directory</h2>
        <p class="text-secondary small mb-0">Register new occupants, maintain resident directories, and manage tenancy records.</p>
    </div>
    <div>
        <?php if (hasPermission('residents.manage')): ?>
            <button class="btn btn-primary" onclick="openResidentModal()" style="background: #0f766e; border: none;">
                <i class="fa-solid fa-user-plus me-1"></i> Register New Resident
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i> <?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm p-4 bg-white rounded-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold text-slate-800 m-0">Occupant Records</h5>
        <input type="text" id="resSearch" placeholder="Search resident name, flat or ID..." class="form-control form-control-sm" style="max-width: 320px;" onkeyup="filterResTable()">
    </div>

    <div class="table-responsive">
        <table class="table align-middle" id="resTable">
            <thead class="table-light">
                <tr style="color: #64748b; font-size: 0.85rem; text-transform: uppercase;">
                    <th>ID</th>
                    <th>Resident</th>
                    <th>Residence / Unit</th>
                    <th>Type & Relationship</th>
                    <th>Contact</th>
                    <th>Move-in Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($residents && $residents->num_rows > 0): ?>
                    <?php while($r = $residents->fetch_assoc()): ?>
                    <tr>
                        <td style="font-family: monospace; color: #64748b;"><?php echo htmlspecialchars($r['custom_id']); ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <?php if($r['image_path']): ?>
                                    <img src="<?php echo htmlspecialchars($r['image_path']); ?>" style="width: 38px; height: 38px; object-fit: cover; border-radius: 50%;">
                                <?php else: ?>
                                    <div style="width: 38px; height: 38px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #94a3b8;">
                                        <i class="fa-solid fa-user"></i>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <div class="fw-bold text-slate-800"><?php echo htmlspecialchars($r['name']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($r['email']); ?></small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="fw-semibold text-slate-800">Flat <?php echo htmlspecialchars($r['flat_number'] ?? 'N/A'); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars(($r['street_name'] ?? 'Main') . ' - ' . ($r['building_name'] ?? 'Block')); ?></small>
                        </td>
                        <td>
                            <span class="badge bg-primary-subtle text-primary text-uppercase" style="font-size: 0.72rem;">
                                <?php echo htmlspecialchars($r['type']); ?>
                            </span>
                            <span class="badge bg-light text-secondary border ms-1" style="font-size: 0.72rem;">
                                <?php echo htmlspecialchars($r['relationship'] ?: 'Self'); ?>
                            </span>
                        </td>
                        <td>
                            <div class="fw-semibold small text-slate-800"><?php echo htmlspecialchars($r['phone'] ?: 'N/A'); ?></div>
                        </td>
                        <td class="small text-muted"><?php echo date('M d, Y', strtotime($r['registration_date'])); ?></td>
                        <td>
                            <span class="badge bg-success-subtle text-success text-uppercase" style="font-size: 0.7rem; font-weight: 700;">
                                <?php echo htmlspecialchars($r['status']); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">No resident records found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Register Resident Modal -->
<div id="res-modal" class="custom-modal-backdrop" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 1050; align-items: center; justify-content: center; padding: 1.5rem 1rem; overflow-y: auto;">
    <div class="modal-content bg-white rounded-4 shadow-lg p-4" style="max-width: 580px; width: 100%; max-height: 90vh; overflow-y: auto;">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3">
            <h5 class="fw-bold text-slate-800 m-0">Register New Resident</h5>
            <button type="button" class="btn-close" onclick="closeResidentModal()"></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">First Name</label>
                    <input type="text" name="first_name" required class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Last Name</label>
                    <input type="text" name="last_name" required class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Email Address</label>
                    <input type="email" name="email" required class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Phone Number</label>
                    <input type="text" name="phone" required class="form-control">
                </div>

                <div class="col-md-12">
                    <label class="form-label fw-bold small text-slate-800 mb-1">Residence Location</label>
                    <div class="p-3 bg-light rounded-3 border">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label text-muted small fw-semibold mb-1">1. Select Street <span class="text-danger">*</span></label>
                                <select id="res_street_id" class="form-select form-select-sm" onchange="fetchStaffBuildings(this.value)" required>
                                    <option value="">-- Choose Street --</option>
                                    <?php 
                                    $all_streets = $conn->query("SELECT * FROM streets WHERE status != 'archived' AND estate_id = $estate_id ORDER BY name");
                                    if ($all_streets && $all_streets->num_rows > 0):
                                        while($st = $all_streets->fetch_assoc()): ?>
                                            <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['name']); ?></option>
                                        <?php endwhile;
                                    endif; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted small fw-semibold mb-1">2. Select Property <span class="text-danger">*</span></label>
                                <select id="res_building_id" class="form-select form-select-sm" onchange="fetchStaffFlats(this.value)" required>
                                    <option value="">Select Street First</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted small fw-semibold mb-1">3. Select Flat / Unit <span class="text-danger">*</span></label>
                                <select name="flat_id" id="res_flat_id" class="form-select form-select-sm" required>
                                    <option value="">Select Property First</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Occupant Type</label>
                    <select name="type" class="form-select">
                        <option value="head">Household Head / Primary</option>
                        <option value="dependent">Dependent / Family Member</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Relationship</label>
                    <select name="relationship" class="form-select">
                        <option value="Self">Self / Tenant</option>
                        <option value="Spouse">Spouse</option>
                        <option value="Child">Child</option>
                        <option value="Relative">Relative</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Move-In / Registration Date</label>
                    <input type="date" name="registration_date" value="<?php echo date('Y-m-d'); ?>" required class="form-control">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Profile Picture</label>
                    <input type="file" name="image" accept="image/*" class="form-control form-control-sm">
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 pt-4 mt-3 border-top">
                <button type="button" class="btn btn-light" onclick="closeResidentModal()">Cancel</button>
                <button type="submit" name="register_resident" class="btn btn-primary px-4 fw-semibold" style="background: #0f766e; border: none;">Register Resident</button>
            </div>
        </form>
    </div>
</div>

<script>
function openResidentModal() {
    document.getElementById('res-modal').style.display = 'flex';
    if (document.getElementById('res_street_id')) document.getElementById('res_street_id').value = '';
    if (document.getElementById('res_building_id')) document.getElementById('res_building_id').innerHTML = '<option value="">Select Street First</option>';
    if (document.getElementById('res_flat_id')) document.getElementById('res_flat_id').innerHTML = '<option value="">Select Property First</option>';
}

function fetchStaffBuildings(streetId) {
    const buildingSelect = document.getElementById('res_building_id');
    const flatSelect = document.getElementById('res_flat_id');
    buildingSelect.innerHTML = '<option value="">Loading...</option>';
    flatSelect.innerHTML = '<option value="">Select Property First</option>';

    if (!streetId) {
        buildingSelect.innerHTML = '<option value="">Select Street First</option>';
        return;
    }

    fetch(`../api/get_buildings?street_id=${streetId}`)
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                let html = '<option value="">Select Property / Building</option>';
                res.data.forEach(b => {
                    html += `<option value="${b.id}">${b.name} (${b.property_number})</option>`;
                });
                buildingSelect.innerHTML = html;
            } else {
                buildingSelect.innerHTML = '<option value="">No buildings found</option>';
            }
        })
        .catch(() => {
            buildingSelect.innerHTML = '<option value="">Error loading buildings</option>';
        });
}

function fetchStaffFlats(buildingId) {
    const flatSelect = document.getElementById('res_flat_id');
    flatSelect.innerHTML = '<option value="">Loading...</option>';

    if (!buildingId) {
        flatSelect.innerHTML = '<option value="">Select Property First</option>';
        return;
    }

    fetch(`../api/get_flats?building_id=${buildingId}`)
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                let html = '<option value="">Select Flat / Unit</option>';
                res.data.forEach(f => {
                    html += `<option value="${f.id}">Flat ${f.number} (Floor ${f.floor}) - ${f.status || 'Active'}</option>`;
                });
                flatSelect.innerHTML = html;
            } else {
                flatSelect.innerHTML = '<option value="">No flats found</option>';
            }
        })
        .catch(() => {
            flatSelect.innerHTML = '<option value="">Error loading flats</option>';
        });
}
function closeResidentModal() {
    document.getElementById('res-modal').style.display = 'none';
}
function filterResTable() {
    const input = document.getElementById('resSearch').value.toLowerCase();
    const rows = document.querySelectorAll('#resTable tbody tr');
    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(input) ? '' : 'none';
    });
}
</script>

<?php include 'footer.php'; ?>
