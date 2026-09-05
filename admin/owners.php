<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// admin/owners.php
require_once '../config.php';
require_once '../includes/auth_guard.php';
requireAdminAccess();

include '../includes/header.php';
include '../includes/sidebar.php';

if (!function_exists('generateCustomID')) {
    function generateCustomID($conn, $table, $prefix) {
        $res = $conn->query("SELECT MAX(id) as max_id FROM $table");
        $row = $res->fetch_assoc();
        $next = ($row['max_id'] ?? 0) + 1;
        return $prefix . '-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }
}

$message = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['add_owner'])) {
        $first_name = $conn->real_escape_string($_POST['first_name']);
        $last_name = $conn->real_escape_string($_POST['last_name']);
        $name = $first_name . ' ' . $last_name;
        $phone = $conn->real_escape_string($_POST['phone']);
        $email = $conn->real_escape_string($_POST['email']);
        $id_type = $conn->real_escape_string($_POST['id_type']);
        $type = $conn->real_escape_string($_POST['ownership_type']);
        $owner_type = (isset($_POST['owner_type']) && $_POST['owner_type'] === 'flat') ? 'flat' : 'building';
        
        $estate_id = get_estate_id();
        $reg_date = $conn->real_escape_string($_POST['registration_date']);
        
        // Target Property or Flat ID based on scope
        $property_id = 0;
        if ($owner_type === 'building') {
            $property_id = intval($_POST['owner_building_id'] ?? 0);
        } else {
            $property_id = intval($_POST['owner_flat_id'] ?? 0);
        }
        
        if (!empty($_POST['owner_id'])) {
            $id = intval($_POST['owner_id']);
            $sql = "UPDATE property_owners SET first_name='$first_name', last_name='$last_name', full_name='$name', phone='$phone', email='$email', id_type='$id_type', ownership_type='$type', owner_type='$owner_type', registration_date='$reg_date' WHERE id=$id AND estate_id=$estate_id";
            if ($conn->query($sql)) {
                $message = "Owner updated successfully!";
                
                // Update Property Link
                if ($property_id > 0) {
                    $conn->query("DELETE FROM owner_properties WHERE owner_id=$id AND estate_id=$estate_id");
                    $conn->query("INSERT INTO owner_properties (estate_id, owner_id, property_id, property_type) VALUES ($estate_id, $id, $property_id, '$owner_type')");
                }
            }
            else $message = "Error: " . $conn->error;
        } else {
            $custom_id = generateCustomID($conn, 'property_owners', 'OWN');
            $sql = "INSERT INTO property_owners (estate_id, custom_id, first_name, last_name, full_name, phone, email, id_type, ownership_type, owner_type, registration_date) VALUES ($estate_id, '$custom_id', '$first_name', '$last_name', '$name', '$phone', '$email', '$id_type', '$type', '$owner_type', '$reg_date')";
            if ($conn->query($sql)) {
                $new_id = $conn->insert_id;
                $message = "Owner registered successfully!";
                
                // Insert Property Link
                if ($property_id > 0) {
                    $conn->query("INSERT INTO owner_properties (estate_id, owner_id, property_id, property_type) VALUES ($estate_id, $new_id, $property_id, '$owner_type')");
                }
            }
            else $message = "Error: " . $conn->error;
        }
    }
}

$owners = $conn->query("SELECT p.*, 
                        op.property_type, 
                        op.property_id,
                        COALESCE(b.street_id, fb.street_id) as linked_street_id,
                        COALESCE(b.id, f.building_id) as linked_building_id,
                        f.id as linked_flat_id,
                        COALESCE(s_b.name, s_fb.name) as street_name,
                        b.name as building_name,
                        b.property_number as building_number,
                        fb.name as flat_building_name,
                        f.number as flat_number,
                        f.floor as flat_floor
                        FROM property_owners p 
                        LEFT JOIN owner_properties op ON p.id = op.owner_id 
                        LEFT JOIN buildings b ON op.property_type = 'building' AND op.property_id = b.id
                        LEFT JOIN streets s_b ON b.street_id = s_b.id
                        LEFT JOIN flats f ON op.property_type = 'flat' AND op.property_id = f.id
                        LEFT JOIN buildings fb ON f.building_id = fb.id
                        LEFT JOIN streets s_fb ON fb.street_id = s_fb.id
                        WHERE p.estate_id = $estate_id
                        GROUP BY p.id 
                        ORDER BY p.full_name");
$ownership_types = $conn->query("SELECT * FROM property_ownership_types ORDER BY name ASC");
?>

<div class="page-header">
    <h1>Property Owners</h1>
</div>

<?php if ($message): ?>
    <div class="alert" style="background: #dcfce7; color: #166534; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<!-- Owner Registration Modal -->
<div id="owner-modal" class="custom-modal-backdrop">
    <div class="modal-content" style="max-width: 760px;">
        <div class="modal-header">
            <h2 id="modal-title">Register Owner</h2>
            <button class="close-modal" type="button" onclick="closeOwnerModal()"><i class="fa-solid fa-times"></i></button>
        </div>
        <form method="POST" id="owner_form">
            <input type="hidden" name="owner_id" id="owner_id">
            <input type="hidden" name="add_owner" value="1">
            
            <div class="form-grid">
                <div class="form-group">
                    <label>First Name <span style="color: #ef4444;">*</span></label>
                    <input type="text" name="first_name" id="first_name" required class="form-control" placeholder="e.g. John">
                </div>
                <div class="form-group">
                    <label>Last Name <span style="color: #ef4444;">*</span></label>
                    <input type="text" name="last_name" id="last_name" required class="form-control" placeholder="e.g. Doe">
                </div>

                <!-- Ownership Scope Selector: Building Owner vs Flat Owner -->
                <div class="form-group" style="grid-column: span 2;">
                    <label style="font-weight: 600; color: #1e293b; margin-bottom: 0.5rem; display: block;">
                        Ownership Scope <span style="color: #ef4444;">*</span>
                    </label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <label id="card_scope_building" onclick="selectOwnerScope('building')" style="display: flex; align-items: center; gap: 0.85rem; padding: 1rem; border: 2px solid var(--primary-color, #3b82f6); background: #eff6ff; border-radius: 0.75rem; cursor: pointer; transition: all 0.2s;">
                            <input type="radio" name="owner_type" id="scope_building" value="building" checked style="width: 18px; height: 18px; accent-color: var(--primary-color, #3b82f6);">
                            <div>
                                <div style="font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 6px;">
                                    <i class="fa-solid fa-building" style="color: var(--primary-color, #3b82f6);"></i> Building Owner
                                </div>
                                <div style="font-size: 0.8rem; color: #64748b; margin-top: 2px;">Owns an entire building / property</div>
                            </div>
                        </label>
                        
                        <label id="card_scope_flat" onclick="selectOwnerScope('flat')" style="display: flex; align-items: center; gap: 0.85rem; padding: 1rem; border: 2px solid #e2e8f0; background: #ffffff; border-radius: 0.75rem; cursor: pointer; transition: all 0.2s;">
                            <input type="radio" name="owner_type" id="scope_flat" value="flat" style="width: 18px; height: 18px; accent-color: var(--primary-color, #3b82f6);">
                            <div>
                                <div style="font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 6px;">
                                    <i class="fa-solid fa-door-open" style="color: #d97706;"></i> Flat Owner
                                </div>
                                <div style="font-size: 0.8rem; color: #64748b; margin-top: 2px;">Owns a specific unit / flat in a building</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Assigned Property Location Hierarchy -->
                <div class="form-group" style="grid-column: span 2;">
                    <label id="location_label" style="font-weight: 600; color: #1e293b; margin-bottom: 0.5rem; display: block;">
                        Assigned Building Location
                    </label>
                    <div style="background: #f8fafc; padding: 1.25rem; border-radius: 0.75rem; border: 1px solid #e2e8f0;">
                        <div id="location_grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; transition: all 0.2s ease;">
                            <div>
                                <label style="font-size: 0.8rem; color: #64748b; font-weight: 600; display: block; margin-bottom: 0.35rem;">
                                    1. Select Street <span style="color: #ef4444;">*</span>
                                </label>
                                <select id="owner_street_id" name="owner_street_id" class="form-control" onchange="fetchOwnerBuildings(this.value)" required>
                                    <option value="">-- Choose Street --</option>
                                    <?php 
                                    $estate_id = get_estate_id();
                                    $all_streets = $conn->query("SELECT * FROM streets WHERE status != 'archived' AND estate_id = $estate_id ORDER BY name");
                                    if ($all_streets && $all_streets->num_rows > 0):
                                        while($s = $all_streets->fetch_assoc()): ?>
                                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                                        <?php endwhile; 
                                    endif; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label style="font-size: 0.8rem; color: #64748b; font-weight: 600; display: block; margin-bottom: 0.35rem;">
                                    2. Select Property / Building <span style="color: #ef4444;">*</span>
                                </label>
                                <select id="owner_building_id" name="owner_building_id" class="form-control" onchange="onBuildingChange(this.value)" required>
                                    <option value="">Select Street First</option>
                                </select>
                            </div>
                            
                            <!-- Unit / Flat selection: Shown only for Flat Owner -->
                            <div id="col_owner_flat" style="display: none;">
                                <label style="font-size: 0.8rem; color: #64748b; font-weight: 600; display: block; margin-bottom: 0.35rem;">
                                    3. Select Flat / Unit <span style="color: #ef4444;">*</span>
                                </label>
                                <select id="owner_flat_id" name="owner_flat_id" class="form-control">
                                    <option value="">Select Building First</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Ownership Category</label>
                    <select name="ownership_type" id="ownership_type" class="form-control">
                        <?php 
                        if ($ownership_types && $ownership_types->num_rows > 0):
                            while($ot = $ownership_types->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($ot['name']); ?>"><?php echo htmlspecialchars($ot['name']); ?></option>
                            <?php endwhile;
                        endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone" id="phone" class="form-control" placeholder="e.g. +234 800 000 0000">
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" id="email" class="form-control" placeholder="e.g. owner@example.com">
                </div>
                <div class="form-group">
                    <label>ID Type (NIN, BVN, RC Number)</label>
                    <input type="text" name="id_type" id="id_type" class="form-control" placeholder="e.g. NIN-123456789">
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label>Registration Date</label>
                    <input type="date" name="registration_date" id="registration_date" value="<?php echo date('Y-m-d'); ?>" class="form-control">
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem; border-top: 1px solid #e2e8f0; padding-top: 1.5rem;">
                <button type="button" class="btn" style="background: #f1f5f9; color: #475569;" onclick="closeOwnerModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="submit_btn">Save Owner</button>
            </div>
        </form>
    </div>
</div>

<div style="background: white; padding: 1.5rem; border-radius: 0.75rem; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); border: 1px solid #e2e8f0;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <div>
            <h2 style="font-size: 1.35rem; font-weight: 700; color: #1e293b; margin: 0;">Property Owners Directory</h2>
            <p style="margin: 0.25rem 0 0; font-size: 0.875rem; color: #64748b;">Manage registered building owners and individual flat owners across the estate.</p>
        </div>
        <button class="btn btn-primary" onclick="openOwnerModal()" style="display: inline-flex; align-items: center; gap: 0.5rem;">
            <i class="fa-solid fa-plus"></i> Register Owner
        </button>
    </div>

    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; color: #64748b; background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                    <th style="padding: 0.85rem; font-size: 0.85rem; font-weight: 600;">ID</th>
                    <th style="padding: 0.85rem; font-size: 0.85rem; font-weight: 600;">Owner Name</th>
                    <th style="padding: 0.85rem; font-size: 0.85rem; font-weight: 600;">Owner Scope</th>
                    <th style="padding: 0.85rem; font-size: 0.85rem; font-weight: 600;">Assigned Property</th>
                    <th style="padding: 0.85rem; font-size: 0.85rem; font-weight: 600;">Category</th>
                    <th style="padding: 0.85rem; font-size: 0.85rem; font-weight: 600;">Contact</th>
                    <th style="padding: 0.85rem; font-size: 0.85rem; font-weight: 600;">Registered</th>
                    <th style="padding: 0.85rem; font-size: 0.85rem; font-weight: 600; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($owners && $owners->num_rows > 0): ?>
                    <?php while($row = $owners->fetch_assoc()): ?>
                    <tr style="border-bottom: 1px solid #f1f5f9; transition: background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                        <td style="padding: 0.85rem; font-family: monospace; color: #64748b; font-weight: 600; font-size: 0.85rem;">
                            <?php echo htmlspecialchars($row['custom_id']); ?>
                        </td>
                        <td style="padding: 0.85rem;">
                            <div style="font-weight: 600; color: #0f172a; font-size: 0.95rem;">
                                <?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?>
                            </div>
                            <?php if (!empty($row['id_type'])): ?>
                                <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 2px;">
                                    <?php echo htmlspecialchars($row['id_type']); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 0.85rem;">
                            <?php if (($row['owner_type'] ?? '') === 'flat' || ($row['property_type'] ?? '') === 'flat'): ?>
                                <span style="background: #fef3c7; color: #b45309; border: 1px solid #fde68a; padding: 3px 9px; border-radius: 9999px; font-size: 0.78rem; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;">
                                    <i class="fa-solid fa-door-open"></i> Flat Owner
                                </span>
                            <?php else: ?>
                                <span style="background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; padding: 3px 9px; border-radius: 9999px; font-size: 0.78rem; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;">
                                    <i class="fa-solid fa-building"></i> Building Owner
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 0.85rem;">
                            <?php if (($row['owner_type'] ?? '') === 'flat' || ($row['property_type'] ?? '') === 'flat'): ?>
                                <?php if (!empty($row['flat_number'])): ?>
                                    <div style="font-weight: 600; color: #1e293b; font-size: 0.9rem;">
                                        Flat <?php echo htmlspecialchars($row['flat_number']); ?>
                                        <span style="font-weight: 400; color: #64748b; font-size: 0.8rem;">(Floor <?php echo htmlspecialchars($row['flat_floor'] ?? 'N/A'); ?>)</span>
                                    </div>
                                    <div style="font-size: 0.78rem; color: #64748b; margin-top: 2px;">
                                        <i class="fa-solid fa-building" style="color: #94a3b8; font-size: 0.75rem;"></i> <?php echo htmlspecialchars($row['flat_building_name'] ?? ''); ?>
                                        &bull; <?php echo htmlspecialchars($row['street_name'] ?? ''); ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #94a3b8; font-style: italic; font-size: 0.85rem;">Flat Unassigned</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if (!empty($row['building_name'])): ?>
                                    <div style="font-weight: 600; color: #1e293b; font-size: 0.9rem;">
                                        <?php echo htmlspecialchars($row['building_name']); ?>
                                        <?php if (!empty($row['building_number'])): ?>
                                            <span style="font-weight: 400; color: #64748b; font-size: 0.8rem;">(#<?php echo htmlspecialchars($row['building_number']); ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 0.78rem; color: #64748b; margin-top: 2px;">
                                        <i class="fa-solid fa-location-dot" style="color: #94a3b8; font-size: 0.75rem;"></i> <?php echo htmlspecialchars($row['street_name'] ?? ''); ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #94a3b8; font-style: italic; font-size: 0.85rem;">Building Unassigned</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 0.85rem;">
                            <span style="background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 4px; font-size: 0.78rem; font-weight: 500;">
                                <?php echo htmlspecialchars($row['ownership_type']); ?>
                            </span>
                        </td>
                        <td style="padding: 0.85rem;">
                            <div style="font-weight: 500; font-size: 0.875rem; color: #1e293b;"><?php echo htmlspecialchars($row['phone'] ?: '-'); ?></div>
                            <div style="font-size: 0.78rem; color: #94a3b8;"><?php echo htmlspecialchars($row['email'] ?: '-'); ?></div>
                        </td>
                        <td style="padding: 0.85rem; font-size: 0.85rem; color: #64748b;">
                            <?php echo $row['registration_date'] ? date('M d, Y', strtotime($row['registration_date'])) : '-'; ?>
                        </td>
                        <td style="padding: 0.85rem; text-align: right;">
                            <button onclick='editOwner(<?php echo json_encode($row); ?>)' class="btn" style="padding: 0.35rem 0.65rem; background: #eff6ff; color: var(--primary-color, #2563eb); border: 1px solid #dbeafe; border-radius: 0.5rem; font-size: 0.85rem; cursor: pointer;" title="Edit Owner">
                                <i class="fa-solid fa-pen-to-square"></i> Edit
                            </button>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 3.5rem 1rem; color: #94a3b8;">
                            <?php if (isset($conn->error) && $conn->error): ?>
                                <span style="color: #ef4444;">Database Error: <?php echo $conn->error; ?></span>
                            <?php else: ?>
                                <i class="fa-solid fa-user-tie" style="font-size: 2.5rem; color: #cbd5e1; display: block; margin-bottom: 0.75rem;"></i>
                                No property owners registered yet. Click <strong>Register Owner</strong> above to get started.
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
let currentOwnerScope = 'building';

function selectOwnerScope(scope) {
    currentOwnerScope = scope;
    const radioBuilding = document.getElementById('scope_building');
    const radioFlat = document.getElementById('scope_flat');
    const cardBuilding = document.getElementById('card_scope_building');
    const cardFlat = document.getElementById('card_scope_flat');
    const locationLabel = document.getElementById('location_label');
    const locationGrid = document.getElementById('location_grid');
    const colFlat = document.getElementById('col_owner_flat');
    const flatSelect = document.getElementById('owner_flat_id');
    const buildingSelect = document.getElementById('owner_building_id');

    if (scope === 'building') {
        radioBuilding.checked = true;
        cardBuilding.style.border = '2px solid var(--primary-color, #3b82f6)';
        cardBuilding.style.background = '#eff6ff';
        cardFlat.style.border = '2px solid #e2e8f0';
        cardFlat.style.background = '#ffffff';

        locationLabel.innerText = 'Assigned Building Location';
        locationGrid.style.gridTemplateColumns = '1fr 1fr';
        colFlat.style.display = 'none';
        flatSelect.required = false;
        flatSelect.value = '';
    } else {
        radioFlat.checked = true;
        cardFlat.style.border = '2px solid #d97706';
        cardFlat.style.background = '#fffbeb';
        cardBuilding.style.border = '2px solid #e2e8f0';
        cardBuilding.style.background = '#ffffff';

        locationLabel.innerText = 'Assigned Flat Location';
        locationGrid.style.gridTemplateColumns = '1fr 1fr 1fr';
        colFlat.style.display = 'block';
        flatSelect.required = true;

        // If a building is already selected, fetch flats for it
        if (buildingSelect.value) {
            fetchOwnerFlats(buildingSelect.value);
        }
    }
}

function openOwnerModal() {
    document.getElementById('owner-modal').style.display = 'flex';
    document.getElementById('owner_id').value = '';
    document.getElementById('first_name').value = '';
    document.getElementById('last_name').value = '';
    document.getElementById('phone').value = '';
    document.getElementById('email').value = '';
    document.getElementById('id_type').value = '';
    
    // Default to Building Owner
    selectOwnerScope('building');
    
    // Reset Location selectors
    document.getElementById('owner_street_id').value = '';
    document.getElementById('owner_building_id').innerHTML = '<option value="">Select Street First</option>';
    document.getElementById('owner_flat_id').innerHTML = '<option value="">Select Building First</option>';
    
    document.getElementById('registration_date').value = '<?php echo date('Y-m-d'); ?>';
    document.getElementById('modal-title').innerText = 'Register Owner';
    document.getElementById('submit_btn').innerText = 'Register Owner';
}

function fetchOwnerBuildings(streetId, selectedBuildingId = null, callback = null) {
    const buildingSelect = document.getElementById('owner_building_id');
    const flatSelect = document.getElementById('owner_flat_id');
    
    buildingSelect.innerHTML = '<option value="">Loading buildings...</option>';
    flatSelect.innerHTML = '<option value="">Select Building First</option>';

    if (!streetId) {
        buildingSelect.innerHTML = '<option value="">Select Street First</option>';
        return;
    }

    fetch(`../api/get_buildings?street_id=${streetId}`)
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                let html = '<option value="">-- Select Building --</option>';
                res.data.forEach(b => {
                    const isSel = (selectedBuildingId && selectedBuildingId == b.id) ? 'selected' : '';
                    html += `<option value="${b.id}" ${isSel}>${b.name}</option>`;
                });
                buildingSelect.innerHTML = html;
                if (callback) callback();
            } else {
                buildingSelect.innerHTML = '<option value="">No buildings found</option>';
            }
        })
        .catch(() => {
            buildingSelect.innerHTML = '<option value="">Error loading buildings</option>';
        });
}

function onBuildingChange(buildingId, selectedFlatId = null, callback = null) {
    if (currentOwnerScope === 'flat') {
        fetchOwnerFlats(buildingId, selectedFlatId, callback);
    }
}

function fetchOwnerFlats(buildingId, selectedFlatId = null, callback = null) {
    const flatSelect = document.getElementById('owner_flat_id');
    flatSelect.innerHTML = '<option value="">Loading flats...</option>';

    if (!buildingId) {
        flatSelect.innerHTML = '<option value="">Select Building First</option>';
        return;
    }

    fetch(`../api/get_flats?building_id=${buildingId}`)
        .then(res => res.json())
        .then(res => {
            if (res.success && res.data.length > 0) {
                let html = '<option value="">-- Select Flat --</option>';
                res.data.forEach(f => {
                    const isSel = (selectedFlatId && selectedFlatId == f.id) ? 'selected' : '';
                    html += `<option value="${f.id}" ${isSel}>Flat ${f.number} (Floor ${f.floor})</option>`;
                });
                flatSelect.innerHTML = html;
                if (callback) callback();
            } else {
                flatSelect.innerHTML = '<option value="">No flats in this building</option>';
            }
        })
        .catch(() => {
            flatSelect.innerHTML = '<option value="">Error loading flats</option>';
        });
}

function closeOwnerModal() {
    document.getElementById('owner-modal').style.display = 'none';
}

function editOwner(data) {
    openOwnerModal();
    document.getElementById('modal-title').innerText = 'Update Owner';
    document.getElementById('owner_id').value = data.id;
    document.getElementById('first_name').value = data.first_name;
    document.getElementById('last_name').value = data.last_name;
    document.getElementById('phone').value = data.phone || '';
    document.getElementById('email').value = data.email || '';
    document.getElementById('id_type').value = data.id_type || '';
    
    // Determine scope
    const scope = (data.owner_type === 'flat' || data.property_type === 'flat') ? 'flat' : 'building';
    selectOwnerScope(scope);

    // Pre-fill location hierarchy
    const streetSelect = document.getElementById('owner_street_id');
    if (data.linked_street_id) {
        streetSelect.value = data.linked_street_id;
        fetchOwnerBuildings(data.linked_street_id, data.linked_building_id, function() {
            if (scope === 'flat' && data.linked_building_id) {
                fetchOwnerFlats(data.linked_building_id, data.linked_flat_id);
            }
        });
    }

    document.getElementById('ownership_type').value = data.ownership_type;
    document.getElementById('registration_date').value = data.registration_date || '<?php echo date('Y-m-d'); ?>';
    document.getElementById('submit_btn').innerText = 'Update Owner';
}
</script>

<?php include '../includes/footer.php'; ?>
