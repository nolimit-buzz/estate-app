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

// Executive KPI Metrics
$owner_kpi_res = $conn->query("
    SELECT 
        COUNT(DISTINCT p.id) as total_owners,
        COUNT(DISTINCT CASE WHEN p.owner_type = 'building' THEN p.id END) as building_owners,
        COUNT(DISTINCT CASE WHEN p.owner_type = 'flat' THEN p.id END) as flat_owners,
        COUNT(DISTINCT CASE WHEN p.id_type IS NOT NULL AND p.id_type != '' THEN p.id END) as verified_ids
    FROM property_owners p
    WHERE p.estate_id = $estate_id
");
$owner_kpi = $owner_kpi_res ? $owner_kpi_res->fetch_assoc() : [];
$total_owners_count = intval($owner_kpi['total_owners'] ?? 0);
$building_owners_count = intval($owner_kpi['building_owners'] ?? 0);
$flat_owners_count = intval($owner_kpi['flat_owners'] ?? 0);
$verified_ids_count = intval($owner_kpi['verified_ids'] ?? 0);
?>

<div class="page-header-futuristic mb-4">
    <div>
        <div class="header-breadcrumbs">
            <span>Portfolio</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span>Ownership</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span class="active">Directory</span>
        </div>
        <h1 class="page-title">Property Owners</h1>
        <p class="page-subtitle">Centralized directory of building deed holders and individual flat proprietors.</p>
    </div>
    <div class="header-actions">
        <button class="btn btn-sm btn-outline-secondary" onclick="exportOwnersCSV()">
            <i class="fa-solid fa-file-export me-1"></i> Export List
        </button>
        <button class="btn btn-sm text-white" style="background: #0f172a;" onclick="openOwnerModal()">
            <i class="fa-solid fa-user-plus me-1"></i> Register Owner
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert mature-card p-3 mb-4" style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); color: #059669; border-radius: 0.65rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fa-solid fa-circle-check" style="font-size: 1.1rem;"></i>
        <div style="font-weight: 500; font-size: 0.9rem;"><?php echo $message; ?></div>
    </div>
<?php endif; ?>

<!-- ==========================================
     EXECUTIVE KPI METRICS RIBBON (4 PILLARS)
     ========================================== -->
<div class="row g-3 mb-4">
    <!-- Pillar 1: Total Owners -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Total Registered Owners</span>
                    <div class="kpi-value"><?php echo number_format($total_owners_count); ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-user-tie"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Estate Deed Registry</span>
                <span class="mature-badge mature-badge-slate">100% Registered</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: 100%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 2: Building Owners -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Building Deed Holders</span>
                    <div class="kpi-value"><?php echo number_format($building_owners_count); ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-building"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Whole Structure Deeds</span>
                <span class="mature-badge mature-badge-sky"><?php echo $total_owners_count > 0 ? round(($building_owners_count / $total_owners_count) * 100) : 0; ?>% Ratio</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?php echo $total_owners_count > 0 ? ($building_owners_count / $total_owners_count) * 100 : 0; ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 3: Flat Owners -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Flat Proprietors</span>
                    <div class="kpi-value"><?php echo number_format($flat_owners_count); ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-door-open"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Individual Flat Deeds</span>
                <span class="mature-badge mature-badge-amber"><?php echo $total_owners_count > 0 ? round(($flat_owners_count / $total_owners_count) * 100) : 0; ?>% Ratio</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?php echo $total_owners_count > 0 ? ($flat_owners_count / $total_owners_count) * 100 : 0; ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 4: Verified Identity -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Verified Identity Records</span>
                    <div class="kpi-value"><?php echo number_format($verified_ids_count); ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-id-card-clip"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>NIN / BVN / RC Registered</span>
                <span class="mature-badge mature-badge-emerald"><?php echo $total_owners_count > 0 ? round(($verified_ids_count / $total_owners_count) * 100) : 0; ?>% Verified</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?php echo $total_owners_count > 0 ? ($verified_ids_count / $total_owners_count) * 100 : 0; ?>%;"></div>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     INTERACTIVE SEARCH & FILTER TOOLBAR
     ========================================== -->
<div class="futuristic-filter-bar mb-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-stretch align-items-md-center gap-3">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <button class="filter-btn-pill active" onclick="setOwnerFilter('all', this)">
                <i class="fa-solid fa-list-ul me-1"></i> All Owners (<?php echo $total_owners_count; ?>)
            </button>
            <button class="filter-btn-pill" onclick="setOwnerFilter('building', this)">
                <i class="fa-solid fa-building me-1"></i> Building (<?php echo $building_owners_count; ?>)
            </button>
            <button class="filter-btn-pill" onclick="setOwnerFilter('flat', this)">
                <i class="fa-solid fa-door-open me-1"></i> Flat (<?php echo $flat_owners_count; ?>)
            </button>
        </div>
        <div class="position-relative" style="min-width: 280px;">
            <i class="fa-solid fa-magnifying-glass position-absolute text-muted" style="top: 50%; left: 0.85rem; transform: translateY(-50%); font-size: 0.85rem;"></i>
            <input type="text" id="ownerSearchInput" class="form-control ps-5" placeholder="Search by name, ID, phone, email, flat..." onkeyup="filterOwnersTable()">
        </div>
    </div>
</div>

<!-- Owner Registration Modal -->
<div id="owner-modal" class="custom-modal-backdrop">
    <div class="modal-content mature-card" style="max-width: 760px; border-radius: 0.85rem; border: 1px solid var(--border-color); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <div class="modal-header" style="border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <div style="width: 32px; height: 32px; border-radius: 0.4rem; background: rgba(59, 130, 246, 0.1); color: var(--primary-color); display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-user-tie"></i>
                </div>
                <h2 id="modal-title" style="margin: 0; font-size: 1.25rem; font-weight: 700; letter-spacing: -0.01em;">Register Owner</h2>
            </div>
            <button class="close-modal" type="button" onclick="closeOwnerModal()" style="background: none; border: none; font-size: 1.1rem; color: var(--text-muted); cursor: pointer;"><i class="fa-solid fa-times"></i></button>
        </div>
        <form method="POST" id="owner_form">
            <input type="hidden" name="owner_id" id="owner_id">
            <input type="hidden" name="add_owner" value="1">
            
            <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1.25rem;">
                <div class="form-group">
                    <label style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem; display: block; text-transform: uppercase; letter-spacing: 0.03em;">First Name <span style="color: #ef4444;">*</span></label>
                    <input type="text" name="first_name" id="first_name" required class="form-control" placeholder="e.g. John" style="border-radius: 0.5rem;">
                </div>
                <div class="form-group">
                    <label style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem; display: block; text-transform: uppercase; letter-spacing: 0.03em;">Last Name <span style="color: #ef4444;">*</span></label>
                    <input type="text" name="last_name" id="last_name" required class="form-control" placeholder="e.g. Doe" style="border-radius: 0.5rem;">
                </div>

                <!-- Ownership Scope Selector: Building Owner vs Flat Owner -->
                <div class="form-group" style="grid-column: span 2;">
                    <label style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.5rem; display: block; text-transform: uppercase; letter-spacing: 0.03em;">
                        Ownership Scope <span style="color: #ef4444;">*</span>
                    </label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <label id="card_scope_building" onclick="selectOwnerScope('building')" style="display: flex; align-items: center; gap: 0.85rem; padding: 1rem; border: 1.5px solid var(--primary-color, #3b82f6); background: rgba(59, 130, 246, 0.06); border-radius: 0.65rem; cursor: pointer; transition: all 0.2s;">
                            <input type="radio" name="owner_type" id="scope_building" value="building" checked style="width: 18px; height: 18px; accent-color: var(--primary-color, #3b82f6);">
                            <div>
                                <div style="font-weight: 700; color: var(--text-color); display: flex; align-items: center; gap: 6px;">
                                    <i class="fa-solid fa-building" style="color: var(--primary-color, #3b82f6);"></i> Building Owner
                                </div>
                                <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">Owns an entire building / structure</div>
                            </div>
                        </label>
                        
                        <label id="card_scope_flat" onclick="selectOwnerScope('flat')" style="display: flex; align-items: center; gap: 0.85rem; padding: 1rem; border: 1.5px solid var(--border-color); background: var(--card-bg); border-radius: 0.65rem; cursor: pointer; transition: all 0.2s;">
                            <input type="radio" name="owner_type" id="scope_flat" value="flat" style="width: 18px; height: 18px; accent-color: var(--primary-color, #3b82f6);">
                            <div>
                                <div style="font-weight: 700; color: var(--text-color); display: flex; align-items: center; gap: 6px;">
                                    <i class="fa-solid fa-door-open" style="color: #f59e0b;"></i> Flat Owner
                                </div>
                                <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">Owns a specific flat in a building</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Assigned Property Location Hierarchy -->
                <div class="form-group" style="grid-column: span 2;">
                    <label id="location_label" style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.4rem; display: block; text-transform: uppercase; letter-spacing: 0.03em;">
                        Assigned Building Location
                    </label>
                    <div style="background: rgba(148, 163, 184, 0.06); padding: 1.25rem; border-radius: 0.65rem; border: 1px solid var(--border-color);">
                        <div id="location_grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; transition: all 0.2s ease;">
                            <div>
                                <label style="font-size: 0.78rem; color: var(--text-muted); font-weight: 600; display: block; margin-bottom: 0.35rem;">
                                    1. Select Street <span style="color: #ef4444;">*</span>
                                </label>
                                <select id="owner_street_id" name="owner_street_id" class="form-control" onchange="fetchOwnerBuildings(this.value)" required style="border-radius: 0.5rem;">
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
                                <label style="font-size: 0.78rem; color: var(--text-muted); font-weight: 600; display: block; margin-bottom: 0.35rem;">
                                    2. Select Property / Building <span style="color: #ef4444;">*</span>
                                </label>
                                <select id="owner_building_id" name="owner_building_id" class="form-control" onchange="onBuildingChange(this.value)" required style="border-radius: 0.5rem;">
                                    <option value="">Select Street First</option>
                                </select>
                            </div>
                            
                            <!-- Unit / Flat selection: Shown only for Flat Owner -->
                            <div id="col_owner_flat" style="display: none;">
                                <label style="font-size: 0.78rem; color: var(--text-muted); font-weight: 600; display: block; margin-bottom: 0.35rem;">
                                    3. Select Flat <span style="color: #ef4444;">*</span>
                                </label>
                                <select id="owner_flat_id" name="owner_flat_id" class="form-control" style="border-radius: 0.5rem;" disabled>
                                    <option value="">Select Building First</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem; display: block; text-transform: uppercase; letter-spacing: 0.03em;">Ownership Category</label>
                    <select name="ownership_type" id="ownership_type" class="form-control" style="border-radius: 0.5rem;">
                        <?php 
                        if ($ownership_types && $ownership_types->num_rows > 0):
                            while($ot = $ownership_types->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($ot['name']); ?>"><?php echo htmlspecialchars($ot['name']); ?></option>
                            <?php endwhile;
                        endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem; display: block; text-transform: uppercase; letter-spacing: 0.03em;">Phone Number</label>
                    <input type="text" name="phone" id="phone" class="form-control" placeholder="e.g. +234 800 000 0000" style="border-radius: 0.5rem;">
                </div>
                <div class="form-group">
                    <label style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem; display: block; text-transform: uppercase; letter-spacing: 0.03em;">Email Address</label>
                    <input type="email" name="email" id="email" class="form-control" placeholder="e.g. owner@example.com" style="border-radius: 0.5rem;">
                </div>
                <div class="form-group">
                    <label style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem; display: block; text-transform: uppercase; letter-spacing: 0.03em;">ID Type (NIN, BVN, RC Number)</label>
                    <input type="text" name="id_type" id="id_type" class="form-control" placeholder="e.g. NIN-123456789" style="border-radius: 0.5rem;">
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.35rem; display: block; text-transform: uppercase; letter-spacing: 0.03em;">Registration Date</label>
                    <input type="date" name="registration_date" id="registration_date" value="<?php echo date('Y-m-d'); ?>" class="form-control" style="border-radius: 0.5rem;">
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 2rem; border-top: 1px solid var(--border-color); padding-top: 1.25rem;">
                <button type="button" class="btn" style="background: rgba(148, 163, 184, 0.15); color: var(--text-muted); font-weight: 600;" onclick="closeOwnerModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="submit_btn" style="font-weight: 600; padding: 0.6rem 1.5rem;">Save Owner</button>
            </div>
        </form>
    </div>
</div>

<div class="mature-card mb-4">
    <div class="mature-card-header">
        <div>
            <h2 class="mature-card-title">
                <i class="fa-solid fa-user-tie text-secondary"></i> Property Owners Directory
            </h2>
            <p style="margin: 0.25rem 0 0; font-size: 0.8rem; color: var(--text-muted);">Registered deeds, title designations, and linked property assets.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="tech-chip"><i class="fa-solid fa-users"></i> Total: <span id="visibleOwnerCount"><?php echo ($owners ? $owners->num_rows : 0); ?></span></span>
        </div>
    </div>

    <div class="mature-card-body p-0">
        <div class="table-responsive">
            <table class="table dashboard-table align-middle" id="ownersTable">
                <thead>
                    <tr>
                        <th style="width: 120px;">System ID</th>
                        <th>Owner Profile</th>
                        <th>Ownership Scope</th>
                        <th>Assigned Asset</th>
                        <th>Category</th>
                        <th>Contact Channels</th>
                        <th>Registration</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="ownersTableBody">
                    <?php if ($owners && $owners->num_rows > 0): ?>
                        <?php while($row = $owners->fetch_assoc()): 
                            $scope_type = (($row['owner_type'] ?? '') === 'flat' || ($row['property_type'] ?? '') === 'flat') ? 'flat' : 'building';
                            $initials = strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1));
                            $search_meta = strtolower($row['custom_id'] . ' ' . $row['first_name'] . ' ' . $row['last_name'] . ' ' . $row['email'] . ' ' . $row['phone'] . ' ' . ($row['building_name'] ?? '') . ' ' . ($row['flat_number'] ?? ''));
                        ?>
                        <tr class="owner-row" data-scope="<?php echo $scope_type; ?>" data-search="<?php echo htmlspecialchars($search_meta); ?>">
                            <td>
                                <span class="id-chip"><?php echo htmlspecialchars($row['custom_id']); ?></span>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="avatar-chip">
                                        <?php echo $initials ?: '<i class="fa-solid fa-user"></i>'; ?>
                                    </div>
                                    <div>
                                        <div style="font-weight: 600; color: var(--text-color); font-size: 0.92rem;">
                                            <?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?>
                                        </div>
                                        <?php if (!empty($row['id_type'])): ?>
                                            <div style="font-size: 0.74rem; color: var(--text-muted); margin-top: 2px;">
                                                <i class="fa-solid fa-id-card" style="font-size: 0.7rem; opacity: 0.7;"></i> <?php echo htmlspecialchars($row['id_type']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if ($scope_type === 'flat'): ?>
                                    <span class="mature-badge mature-badge-amber">
                                        <i class="fa-solid fa-door-open"></i> Flat Owner
                                    </span>
                                <?php else: ?>
                                    <span class="mature-badge mature-badge-sky">
                                        <i class="fa-solid fa-building"></i> Building Owner
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($scope_type === 'flat'): ?>
                                    <?php if (!empty($row['flat_number'])): ?>
                                        <div style="font-weight: 600; color: var(--text-color); font-size: 0.88rem;">
                                            Flat <?php echo htmlspecialchars($row['flat_number']); ?>
                                            <span style="font-weight: 400; color: var(--text-muted); font-size: 0.78rem;">(Fl. <?php echo htmlspecialchars($row['flat_floor'] ?? 'N/A'); ?>)</span>
                                        </div>
                                        <div style="font-size: 0.76rem; color: var(--text-muted); margin-top: 2px;">
                                            <i class="fa-solid fa-building" style="font-size: 0.7rem; opacity: 0.7;"></i> <?php echo htmlspecialchars($row['flat_building_name'] ?? ''); ?>
                                            &bull; <?php echo htmlspecialchars($row['street_name'] ?? ''); ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-style: italic; font-size: 0.82rem;">Flat Unassigned</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if (!empty($row['building_name'])): ?>
                                        <div style="font-weight: 600; color: var(--text-color); font-size: 0.88rem;">
                                            <?php echo htmlspecialchars($row['building_name']); ?>
                                            <?php if (!empty($row['building_number'])): ?>
                                                <span style="font-weight: 400; color: var(--text-muted); font-size: 0.78rem;">(#<?php echo htmlspecialchars($row['building_number']); ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size: 0.76rem; color: var(--text-muted); margin-top: 2px;">
                                            <i class="fa-solid fa-location-dot" style="font-size: 0.7rem; opacity: 0.7;"></i> <?php echo htmlspecialchars($row['street_name'] ?? ''); ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-style: italic; font-size: 0.82rem;">Building Unassigned</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="mature-badge mature-badge-slate">
                                    <?php echo htmlspecialchars($row['ownership_type']); ?>
                                </span>
                            </td>
                            <td>
                                <div style="font-weight: 500; font-size: 0.85rem; color: var(--text-color); font-family: monospace;">
                                    <?php if (!empty($row['phone'])): ?>
                                        <a href="tel:<?php echo htmlspecialchars($row['phone']); ?>" style="color: inherit; text-decoration: none;">
                                            <i class="fa-solid fa-phone me-1 text-muted" style="font-size: 0.75rem;"></i><?php echo htmlspecialchars($row['phone']); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 0.76rem; color: var(--text-muted); margin-top: 2px;">
                                    <?php if (!empty($row['email'])): ?>
                                        <a href="mailto:<?php echo htmlspecialchars($row['email']); ?>" style="color: var(--text-muted); text-decoration: none;">
                                            <i class="fa-solid fa-envelope me-1" style="font-size: 0.75rem;"></i><?php echo htmlspecialchars($row['email']); ?>
                                        </a>
                                    <?php else: ?>
                                        <span>-</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="font-size: 0.82rem; color: var(--text-muted);">
                                <?php echo $row['registration_date'] ? date('M d, Y', strtotime($row['registration_date'])) : '-'; ?>
                            </td>
                            <td style="text-align: right;">
                                <button onclick='editOwner(<?php echo json_encode($row); ?>)' class="btn btn-sm btn-outline-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.8rem; font-weight: 600;" title="Edit Owner">
                                    <i class="fa-solid fa-pen-to-square me-1"></i> Edit
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 3.5rem 1rem; color: var(--text-muted);">
                                <i class="fa-solid fa-user-tie" style="font-size: 2.2rem; color: var(--text-muted); opacity: 0.4; display: block; margin-bottom: 0.75rem;"></i>
                                No property owners registered yet. Click <strong>Register Owner</strong> above to get started.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
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
        flatSelect.disabled = true;
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
        flatSelect.disabled = false;

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

let activeOwnerScope = 'all';

function setOwnerFilter(scope, btn) {
    activeOwnerScope = scope;
    document.querySelectorAll('.filter-btn-pill').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    filterOwnersTable();
}

function filterOwnersTable() {
    const term = (document.getElementById('ownerSearchInput')?.value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('.owner-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const scope = row.getAttribute('data-scope');
        const searchMeta = row.getAttribute('data-search') || '';
        
        const matchesScope = (activeOwnerScope === 'all') || (scope === activeOwnerScope);
        const matchesTerm = !term || searchMeta.includes(term);

        if (matchesScope && matchesTerm) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const countElem = document.getElementById('visibleOwnerCount');
    if (countElem) countElem.innerText = visibleCount;
}

function exportOwnersCSV() {
    let csv = "System ID,Owner Name,ID Type,Scope,Asset,Category,Phone,Email,Registration Date\n";
    document.querySelectorAll('.owner-row').forEach(row => {
        if (row.style.display !== 'none') {
            const cols = row.querySelectorAll('td');
            if (cols.length >= 7) {
                const sysId = cols[0].innerText.trim();
                const name = cols[1].querySelector('div > div')?.innerText.trim() || '';
                const idType = cols[1].querySelector('div > div:nth-child(2)')?.innerText.trim() || '';
                const scope = cols[2].innerText.trim();
                const asset = cols[3].innerText.replace(/\s+/g, ' ').trim();
                const category = cols[4].innerText.trim();
                const phone = cols[5].querySelector('div:first-child')?.innerText.trim() || '';
                const email = cols[5].querySelector('div:last-child')?.innerText.trim() || '';
                const regDate = cols[6].innerText.trim();

                csv += `"${sysId}","${name}","${idType}","${scope}","${asset}","${category}","${phone}","${email}","${regDate}"\n`;
            }
        }
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.setAttribute('href', url);
    link.setAttribute('download', `Estate_Owners_${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php include '../includes/footer.php'; ?>
