<?php
// admin/zones.php - Central Management: Estate Zones & Sector Administration
require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/Paystack.php';

requireAdminAccess();
if (!hasPermission('zones.manage') && !in_array($_SESSION['role'] ?? '', ['superadmin', 'admin'])) {
    header("Location: index?error=unauthorized");
    exit;
}

$estate_id = get_estate_id();
$message = $flash_message ?? "";
$error = $flash_error ?? "";

// Helper function to auto-sequence the next Zone Code (e.g. ZN-01, ZN-02, ZN-03...)
if (!function_exists('generateNextZoneCode')) {
    function generateNextZoneCode($conn, $estate_id) {
        $res = $conn->query("SELECT code FROM zones WHERE estate_id = $estate_id");
        $max_num = 0;
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if (preg_match('/ZN[-_]?0*(\d+)/i', $row['code'], $m)) {
                    $num = intval($m[1]);
                    if ($num > $max_num) $max_num = $num;
                }
            }
        }
        $id_res = $conn->query("SELECT MAX(id) as max_id FROM zones WHERE estate_id = $estate_id");
        if ($id_res && $row = $id_res->fetch_assoc()) {
            $max_id = intval($row['max_id'] ?? 0);
            if ($max_id > $max_num) $max_num = $max_id;
        }
        $next = $max_num + 1;
        do {
            $candidate = 'ZN-' . str_pad($next, 2, '0', STR_PAD_LEFT);
            $chk = $conn->query("SELECT id FROM zones WHERE code = '$candidate' AND estate_id = $estate_id LIMIT 1");
            if ($chk && $chk->num_rows > 0) {
                $next++;
            } else {
                return $candidate;
            }
        } while (true);
    }
}

// -------------------------------------------------------------
// POST ACTIONS: CREATE / EDIT / TOGGLE ZONE (Post-Redirect-Get Protected)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. CREATE NEW ZONE
    if (isset($_POST['create_zone'])) {
        $name = trim($conn->real_escape_string($_POST['name'] ?? ''));
        $code = strtoupper(trim($conn->real_escape_string($_POST['code'] ?? '')));
        
        // Auto-generate code if empty or unprovided
        if (empty($code)) {
            $code = generateNextZoneCode($conn, $estate_id);
        }
        
        $email = trim($conn->real_escape_string($_POST['email'] ?? ''));
        $phone = trim($conn->real_escape_string($_POST['phone'] ?? ''));
        $motto = trim($conn->real_escape_string($_POST['motto'] ?? ''));
        $reg_date = $conn->real_escape_string($_POST['registration_date'] ?? date('Y-m-d'));
        $office_address = trim($conn->real_escape_string($_POST['office_address'] ?? ''));
        $description = trim($conn->real_escape_string($_POST['description'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
        
        // Zone Admin Login Credentials
        $admin_name = trim($conn->real_escape_string($_POST['admin_name'] ?? ''));
        if (empty($admin_name)) {
            $admin_name = $name . ' Admin';
        }
        $admin_email = trim($conn->real_escape_string($_POST['admin_email'] ?? ''));
        if (empty($admin_email)) {
            $admin_email = $email;
        }
        $zone_password = $_POST['zone_password'] ?? 'zonepass123';
        if (empty($zone_password)) {
            $zone_password = 'zonepass123';
        }
        
        $assign_existing = isset($_POST['assign_existing']) && $_POST['assign_existing'] == '1';
        $existing_user_id = intval($_POST['existing_user_id'] ?? 0);

        if (empty($name)) {
            redirectWithFlash('zones', null, "Zone name is required.");
        } else {
            // Check name uniqueness
            $chk_name = $conn->query("SELECT id FROM zones WHERE name = '$name' AND estate_id = $estate_id LIMIT 1");
            if ($chk_name && $chk_name->num_rows > 0) {
                redirectWithFlash('zones', null, "A zone with name '$name' already exists.");
            } else {
                // Ensure code uniqueness: if collision occurs, auto-advance to next unique code
                $chk_code = $conn->query("SELECT id FROM zones WHERE code = '$code' AND estate_id = $estate_id LIMIT 1");
                if ($chk_code && $chk_code->num_rows > 0) {
                    $code = generateNextZoneCode($conn, $estate_id);
                }
                
                $custom_id = generateCustomID($conn, 'zones', 'ZN');
                $creator_id = $_SESSION['user_id'] ?? "NULL";
                
                $sql = "INSERT INTO zones (estate_id, custom_id, name, code, email, phone, motto, registration_date, office_address, description, status, created_by) 
                        VALUES ($estate_id, '$custom_id', '$name', '$code', '$email', '$phone', '$motto', '$reg_date', '$office_address', '$description', '$status', $creator_id)";
                
                if ($conn->query($sql)) {
                    $new_zone_id = $conn->insert_id;
                    logAudit($conn, "Zone Created", "Zones", "Created zone '$name' ($code) with ID: $new_zone_id");
                    $message = "Zone '$name' ($code) created successfully!";

                    // Settlement Bank Details for Paystack Subaccount
                    $settlement_bank = trim($_POST['settlement_bank'] ?? '');
                    $settlement_bank_name = trim($_POST['settlement_bank_name'] ?? '');
                    $settlement_account = trim($_POST['settlement_account_number'] ?? '');

                    // AUTO-PROVISION PAYSTACK DEDICATED VIRTUAL ACCOUNT / SUBACCOUNT
                    $dva = Paystack::provisionZoneVirtualAccount($conn, $new_zone_id, $estate_id, $name, $email, $phone, $code, $settlement_bank, $settlement_account, $settlement_bank_name);
                    if ($dva && !empty($dva['account_number'])) {
                        if (!empty($dva['is_live'])) {
                            $message .= " Paystack Subaccount registered: {$dva['subaccount_code']} ({$dva['bank_name']} - {$dva['account_number']}).";
                        } else {
                            $message .= " Assigned Virtual Account: {$dva['bank_name']} - {$dva['account_number']} (Code: {$dva['subaccount_code']}).";
                        }
                        logAudit($conn, "Zone DVA Assigned", "Finance", "Assigned Virtual Account {$dva['account_number']} ({$dva['bank_name']}) to Zone #$new_zone_id");
                    }

                    // Handle Zone Admin assignment
                    if ($assign_existing && $existing_user_id > 0) {
                        $conn->query("UPDATE users SET zone_id = $new_zone_id, role = 'zone_admin' WHERE id = $existing_user_id AND estate_id = $estate_id");
                        logAudit($conn, "Zone Admin Assigned", "Zones", "Assigned existing user #$existing_user_id as Zone Admin for Zone #$new_zone_id");
                        $message .= " Assigned selected user as Zone Admin.";
                    } elseif (!empty($admin_email)) {
                        $hash = password_hash($zone_password, PASSWORD_DEFAULT);
                        // Check if user with this email already exists
                        $chk_u = $conn->query("SELECT id FROM users WHERE email = '$admin_email' LIMIT 1");
                        if ($chk_u && $chk_u->num_rows > 0) {
                            $existing_u = $chk_u->fetch_assoc();
                            $existing_id = $existing_u['id'];
                            $conn->query("UPDATE users SET zone_id = $new_zone_id, role = 'zone_admin', password = '$hash', name = '$admin_name' WHERE id = $existing_id");
                            logAudit($conn, "Zone Admin Linked", "Zones", "Linked user #$existing_id ($admin_email) as Zone Admin for Zone #$new_zone_id");
                            $message .= " Configured Zone Admin login for '$admin_email'.";
                        } else {
                            $u_sql = "INSERT INTO users (estate_id, zone_id, name, email, password, phone, role) 
                                      VALUES ($estate_id, $new_zone_id, '$admin_name', '$admin_email', '$hash', '$phone', 'zone_admin')";
                            if ($conn->query($u_sql)) {
                                logAudit($conn, "Zone Admin Created", "Zones", "Created Zone Admin '$admin_name' ($admin_email) for Zone #$new_zone_id");
                                $message .= " Created Zone Admin login: $admin_email with Password: $zone_password.";
                            }
                        }
                    }
                    redirectWithFlash('zones', $message);
                } else {
                    redirectWithFlash('zones', null, "Failed to create zone: " . $conn->error);
                }
            }
        }
    }

    // REGENERATE / REFRESH / LINK PAYSTACK SUBACCOUNT
    if (isset($_POST['regenerate_dva'])) {
        $r_zone_id = intval($_POST['zone_id']);
        $r_bank = trim($_POST['settlement_bank'] ?? '');
        $r_bank_name = trim($_POST['settlement_bank_name'] ?? '');
        $r_acc = trim($_POST['settlement_account_number'] ?? '');

        $z_chk = $conn->query("SELECT * FROM zones WHERE id = $r_zone_id AND estate_id = $estate_id LIMIT 1");
        if ($z_chk && $z_chk->num_rows > 0) {
            $z_row = $z_chk->fetch_assoc();
            $dva = Paystack::provisionZoneVirtualAccount($conn, $r_zone_id, $estate_id, $z_row['name'], $z_row['email'], $z_row['phone'], $z_row['code'], $r_bank, $r_acc, $r_bank_name);
            if ($dva && !empty($dva['account_number'])) {
                if (!empty($dva['is_live'])) {
                    $msg = "Paystack Live Subaccount for {$z_row['name']} synced: {$dva['bank_name']} - {$dva['account_number']} (Code: {$dva['subaccount_code']}).";
                } else {
                    $msg = "Paystack Dedicated Virtual Account for {$z_row['name']} synced: {$dva['bank_name']} - {$dva['account_number']}.";
                }
                logAudit($conn, "Zone DVA Refreshed", "Finance", "Refreshed DVA {$dva['account_number']} ({$dva['bank_name']}) for Zone #$r_zone_id");
                redirectWithFlash('zones', $msg);
            }
        }
        redirectWithFlash('zones', "Paystack details updated.");
    }

    // 2. UPDATE ZONE
    if (isset($_POST['update_zone'])) {
        $zone_id = intval($_POST['zone_id']);
        $name = trim($conn->real_escape_string($_POST['name'] ?? ''));
        $code = strtoupper(trim($conn->real_escape_string($_POST['code'] ?? '')));
        $email = trim($conn->real_escape_string($_POST['email'] ?? ''));
        $phone = trim($conn->real_escape_string($_POST['phone'] ?? ''));
        $motto = trim($conn->real_escape_string($_POST['motto'] ?? ''));
        $reg_date = $conn->real_escape_string($_POST['registration_date'] ?? date('Y-m-d'));
        $office_address = trim($conn->real_escape_string($_POST['office_address'] ?? ''));
        $description = trim($conn->real_escape_string($_POST['description'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
        $assigned_user_id = intval($_POST['assigned_user_id'] ?? 0);

        $settlement_bank = trim($_POST['settlement_bank'] ?? '');
        $settlement_bank_name = trim($_POST['settlement_bank_name'] ?? '');
        $settlement_account = trim($_POST['settlement_account_number'] ?? '');

        if (empty($name) || empty($code)) {
            redirectWithFlash('zones', null, "Zone name and code cannot be empty.");
        } else {
            // Check code uniqueness
            $chk = $conn->query("SELECT id FROM zones WHERE (code = '$code' OR name = '$name') AND id != $zone_id AND estate_id = $estate_id LIMIT 1");
            if ($chk && $chk->num_rows > 0) {
                redirectWithFlash('zones', null, "Another zone is already using code '$code' or name '$name'.");
            } else {
                $u_sql = "UPDATE zones 
                          SET name = '$name', code = '$code', email = '$email', phone = '$phone', motto = '$motto', 
                              registration_date = '$reg_date', office_address = '$office_address', description = '$description', status = '$status' 
                          WHERE id = $zone_id AND estate_id = $estate_id";
                if ($conn->query($u_sql)) {
                    // Update assigned admin
                    if ($assigned_user_id > 0) {
                        $conn->query("UPDATE users SET zone_id = $zone_id, role = 'zone_admin' WHERE id = $assigned_user_id AND estate_id = $estate_id");
                    }
                    
                    // Reset or set admin password if provided
                    $reset_password = $_POST['reset_password'] ?? '';
                    if (!empty($reset_password)) {
                        $hash = password_hash($reset_password, PASSWORD_DEFAULT);
                        if ($assigned_user_id > 0) {
                            $conn->query("UPDATE users SET password = '$hash' WHERE id = $assigned_user_id AND estate_id = $estate_id");
                        } else {
                            $chk_adm = $conn->query("SELECT id FROM users WHERE zone_id = $zone_id AND role = 'zone_admin' LIMIT 1");
                            if ($chk_adm && $chk_adm->num_rows > 0) {
                                $adm_id = $chk_adm->fetch_assoc()['id'];
                                $conn->query("UPDATE users SET password = '$hash' WHERE id = $adm_id");
                            } elseif (!empty($email)) {
                                $adm_name = $name . ' Admin';
                                $conn->query("INSERT INTO users (estate_id, zone_id, name, email, password, phone, role) 
                                              VALUES ($estate_id, $zone_id, '$adm_name', '$email', '$hash', '$phone', 'zone_admin')");
                            }
                        }
                    }
                    logAudit($conn, "Zone Updated", "Zones", "Updated zone #$zone_id ($name)");
                    $msg = "Zone '$name' updated successfully!";

                    // Sync Settlement Bank Details to Paystack Subaccount if provided
                    if (!empty($settlement_bank) && !empty($settlement_account)) {
                        Paystack::provisionZoneVirtualAccount($conn, $zone_id, $estate_id, $name, $email, $phone, $code, $settlement_bank, $settlement_account, $settlement_bank_name);
                    }
                    redirectWithFlash('zones', $msg);
                } else {
                    redirectWithFlash('zones', null, "Failed to update zone: " . $conn->error);
                }
            }
        }
    }

    // 3. TOGGLE STATUS
    if (isset($_POST['toggle_status'])) {
        $zone_id = intval($_POST['zone_id']);
        $new_status = ($_POST['current_status'] === 'active') ? 'inactive' : 'active';
        $conn->query("UPDATE zones SET status = '$new_status' WHERE id = $zone_id AND estate_id = $estate_id");
        logAudit($conn, "Zone Status Changed", "Zones", "Zone #$zone_id status toggled to $new_status");
        redirectWithFlash('zones', "Zone status updated to " . ucfirst($new_status) . ".");
    }
}


// Auto-provision virtual accounts for legacy zones missing an account
$unassigned_zones = $conn->query("SELECT id, name, email, phone, code FROM zones WHERE estate_id = $estate_id AND (paystack_account_number IS NULL OR paystack_account_number = '')");
if ($unassigned_zones && $unassigned_zones->num_rows > 0) {
    while ($uz = $unassigned_zones->fetch_assoc()) {
        Paystack::provisionZoneVirtualAccount($conn, $uz['id'], $estate_id, $uz['name'], $uz['email'], $uz['phone'], $uz['code']);
    }
}

// Paystack client & Bank List for subaccount setup
$paystack_client = new Paystack($conn);
$bank_list = $paystack_client->getBanks();

// -------------------------------------------------------------
// FETCH METRICS & DATA
// -------------------------------------------------------------
$zones_res = $conn->query("
    SELECT z.*, 
           (SELECT COUNT(*) FROM streets s WHERE s.zone_id = z.id AND s.status != 'archived') as streets_count,
           (SELECT COUNT(DISTINCT b.id) FROM buildings b JOIN streets s ON b.street_id = s.id WHERE s.zone_id = z.id AND b.status != 'archived') as buildings_count,
           (SELECT COUNT(DISTINCT f.id) FROM flats f JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE s.zone_id = z.id AND f.status != 'archived') as flats_count,
           (SELECT COUNT(DISTINCT r.id) FROM residents r JOIN flats f ON r.flat_id = f.id JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE s.zone_id = z.id AND r.status = 'active') as residents_count,
           (SELECT COUNT(*) FROM users u WHERE u.zone_id = z.id AND u.role = 'zone_admin') as admins_count,
           (SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN invoices i ON p.invoice_id = i.id WHERE i.zone_id = z.id AND p.status = 'paid') as revenue_collected
    FROM zones z 
    WHERE z.estate_id = $estate_id 
    ORDER BY z.id ASC
");

$zones_list = [];
$total_zones = 0;
$total_streets_in_zones = 0;
$total_residents_in_zones = 0;
$total_revenue_in_zones = 0.0;

if ($zones_res) {
    while ($row = $zones_res->fetch_assoc()) {
        $zones_list[] = $row;
        $total_zones++;
        $total_streets_in_zones += intval($row['streets_count']);
        $total_residents_in_zones += intval($row['residents_count']);
        $total_revenue_in_zones += floatval($row['revenue_collected']);
    }
}

// Fetch all available users for admin assignment
$eligible_users = [];
$eu_res = $conn->query("SELECT id, name, email, role, zone_id FROM users WHERE estate_id = $estate_id ORDER BY name ASC");
if ($eu_res) {
    while ($u = $eu_res->fetch_assoc()) {
        $eligible_users[] = $u;
    }
}

// Pre-compute next auto-generated zone code
$next_zone_code = generateNextZoneCode($conn, $estate_id);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="page-header-futuristic mb-4">
    <div>
        <div class="header-breadcrumbs">
            <a href="index">Dashboard</a>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span>Infrastructure</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span class="active">Zones & Sectors</span>
        </div>
        <h1 class="page-title">Estate Zones & Sectors</h1>
        <p class="page-subtitle">Configure administrative zones, automated Paystack remittance accounts, sector boundaries, and assigned Zone Admins.</p>
    </div>
    <div class="header-actions">
        <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
            <i class="fa-solid fa-print me-1"></i> Print Register
        </button>
        <button class="btn btn-sm text-white" style="background: #0f172a;" data-bs-toggle="modal" data-bs-target="#createZoneModal">
            <i class="fa-solid fa-plus me-1"></i> Create New Zone
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert mature-card p-3 mb-4" style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); color: #059669; border-radius: 0.65rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fa-solid fa-circle-check" style="font-size: 1.1rem;"></i>
        <div style="font-weight: 500; font-size: 0.9rem;"><?php echo $message; ?></div>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert mature-card p-3 mb-4" style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; border-radius: 0.65rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fa-solid fa-circle-exclamation" style="font-size: 1.1rem;"></i>
        <div style="font-weight: 500; font-size: 0.9rem;"><?php echo htmlspecialchars($error); ?></div>
    </div>
<?php endif; ?>

<!-- ==========================================
     EXECUTIVE KPI METRICS RIBBON (4 PILLARS)
     ========================================== -->
<div class="row g-3 mb-4">
    <!-- Pillar 1: Active Administrative Zones -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Active Zones</span>
                    <div class="kpi-value"><?php echo number_format($total_zones); ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-layer-group"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Configured Sectors</span>
                <span class="mature-badge mature-badge-primary">100% Online</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: 100%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 2: Zonal Streets Partition -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Zonal Streets</span>
                    <div class="kpi-value"><?php echo number_format($total_streets_in_zones); ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-road"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Partitioned Network</span>
                <span class="mature-badge mature-badge-emerald">Active Segments</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?php echo min(100, max(20, $total_streets_in_zones * 10)); ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 3: Resident Population -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Zonal Residents</span>
                    <div class="kpi-value"><?php echo number_format($total_residents_in_zones); ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-users"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Zoned Occupants</span>
                <span class="mature-badge mature-badge-sky">Registered</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?php echo min(100, max(25, $total_residents_in_zones * 5)); ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 4: Realized Revenue -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Zonal Revenue</span>
                    <div class="kpi-value">₦<?php echo number_format($total_revenue_in_zones, 2); ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-coins"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Realized Dues</span>
                <span class="mature-badge mature-badge-amber">Settled</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?php echo ($total_revenue_in_zones > 0) ? '100' : '15'; ?>%;"></div>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     ZONES REGISTER TABLE PANEL
     ========================================== -->
<div class="mature-card mb-4">
    <div class="mature-card-header">
        <div>
            <h3 class="mature-card-title">
                <i class="fa-solid fa-layer-group text-secondary"></i> Configured Estate Zones & Sectors
            </h3>
            <p class="text-secondary small mb-0">Autonomous administrative sectors with dedicated virtual subaccounts</p>
        </div>
        <span class="mature-badge mature-badge-slate">
            <i class="fa-solid fa-shield-check me-1"></i><?php echo count($zones_list); ?> Zone(s) Registered
        </span>
    </div>

    <div class="mature-card-body p-0">
        <div class="table-responsive">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th class="ps-4">Zone Code / ID</th>
                        <th>Zone Name, Motto & Address</th>
                        <th>Official Contacts</th>
                        <th>Paystack Dedicated Account</th>
                        <th>Streets</th>
                        <th>Properties & Units</th>
                        <th>Residents</th>
                        <th>Assigned Admin</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($zones_list)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5 text-secondary">
                                <i class="fa-solid fa-layer-group fs-1 text-muted mb-2 d-block"></i>
                                No zones created yet. Click "Create New Zone" to add your first zone.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($zones_list as $z): 
                            $zid = $z['id'];
                            $admins_q = $conn->query("SELECT id, name, email, phone FROM users WHERE zone_id = $zid AND role = 'zone_admin'");
                            $admins = [];
                            if ($admins_q) {
                                while ($a = $admins_q->fetch_assoc()) $admins[] = $a;
                            }
                        ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="mature-badge mature-badge-primary font-monospace fw-bold mb-1">
                                        <?php echo htmlspecialchars($z['code']); ?>
                                    </div>
                                    <div>
                                        <span class="id-chip"><?php echo htmlspecialchars($z['custom_id'] ?? ('ZN-' . $z['id'])); ?></span>
                                    </div>
                                    <?php if (!empty($z['registration_date'])): ?>
                                        <div class="text-muted small mt-1" style="font-size: 0.7rem;">
                                            <i class="fa-regular fa-calendar me-1"></i><?php echo date('M d, Y', strtotime($z['registration_date'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-slate-900" style="font-size: 0.92rem;"><?php echo htmlspecialchars($z['name']); ?></div>
                                    <?php if (!empty($z['motto'])): ?>
                                        <div class="small fst-italic text-purple mt-0.5" style="color: #7e22ce; font-size: 0.78rem;">
                                            <i class="fa-solid fa-quote-left opacity-50 me-1" style="font-size: 0.65rem;"></i><?php echo htmlspecialchars($z['motto']); ?><i class="fa-solid fa-quote-right opacity-50 ms-1" style="font-size: 0.65rem;"></i>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($z['office_address'])): ?>
                                        <div class="text-secondary small mt-1" style="font-size: 0.75rem;">
                                            <i class="fa-solid fa-location-dot me-1 text-danger"></i><?php echo htmlspecialchars($z['office_address']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($z['description'])): ?>
                                        <div class="text-muted small text-truncate mt-0.5" style="max-width: 220px; font-size: 0.75rem;">
                                            <?php echo htmlspecialchars($z['description']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($z['email'])): ?>
                                        <div class="small">
                                            <i class="fa-solid fa-envelope me-1 text-primary"></i>
                                            <a href="mailto:<?php echo htmlspecialchars($z['email']); ?>" class="text-decoration-none text-slate-700"><?php echo htmlspecialchars($z['email']); ?></a>
                                        </div>
                                    <?php else: ?>
                                        <small class="text-muted fst-italic">No email set</small>
                                    <?php endif; ?>
                                    <?php if (!empty($z['phone'])): ?>
                                        <div class="small text-secondary mt-0.5">
                                            <i class="fa-solid fa-phone me-1 text-success"></i> <?php echo htmlspecialchars($z['phone']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($z['paystack_account_number'])): ?>
                                        <div class="d-flex align-items-center gap-1 flex-wrap mb-1">
                                            <span class="mature-badge mature-badge-sky" style="font-weight: 700;">
                                                <i class="fa-solid fa-building-columns me-1"></i> <?php echo htmlspecialchars($z['paystack_bank_name'] ?: 'Settlement Bank'); ?>
                                            </span>
                                            <?php if (!empty($z['paystack_subaccount_code']) && str_starts_with($z['paystack_subaccount_code'], 'SUB_') && strlen($z['paystack_subaccount_code']) > 15): ?>
                                                <span class="mature-badge mature-badge-emerald" title="Live Subaccount active in Paystack Dashboard">
                                                    <i class="fa-solid fa-cloud-check me-1"></i> Live
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex align-items-center gap-1">
                                            <span class="font-monospace fw-bold text-slate-900" style="font-size: 0.85rem; letter-spacing: 0.05em;"><?php echo htmlspecialchars($z['paystack_account_number']); ?></span>
                                            <button type="button" class="btn btn-link p-0 text-muted" title="Copy Account Number" onclick="EstateDialog.copy('<?php echo htmlspecialchars($z['paystack_account_number']); ?>', 'Copied account number to clipboard');">
                                                <i class="fa-regular fa-copy" style="font-size: 0.75rem;"></i>
                                            </button>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-between mt-1 gap-1">
                                            <span class="tech-chip text-truncate" style="max-width: 120px;" title="<?php echo htmlspecialchars($z['paystack_subaccount_code'] ?? ''); ?>">
                                                <?php echo htmlspecialchars($z['paystack_subaccount_code'] ?: 'No subaccount'); ?>
                                            </span>
                                            <button type="button" class="btn btn-link p-0 text-primary small text-decoration-none fw-semibold" style="font-size: 0.72rem;" onclick='openEditModal(<?php echo json_encode($z); ?>, <?php echo json_encode($admins); ?>)'>
                                                <i class="fa-solid fa-gear"></i> Link
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary" style="font-size: 0.72rem; border-radius: 6px;" onclick='openEditModal(<?php echo json_encode($z); ?>, <?php echo json_encode($admins); ?>)'>
                                            <i class="fa-solid fa-plus me-1"></i> Link Bank
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="mature-badge mature-badge-primary">
                                        <i class="fa-solid fa-road me-1"></i> <?php echo $z['streets_count']; ?> Street(s)
                                    </span>
                                </td>
                                <td>
                                    <div class="small fw-semibold text-slate-900"><?php echo $z['buildings_count']; ?> Buildings</div>
                                    <div class="small text-secondary"><?php echo $z['flats_count']; ?> Flats/Units</div>
                                </td>
                                <td>
                                    <span class="mature-badge mature-badge-emerald">
                                        <i class="fa-solid fa-users me-1"></i> <?php echo $z['residents_count']; ?> Occupants
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($admins)): ?>
                                        <?php foreach ($admins as $adm): ?>
                                            <div class="mb-1">
                                                <span class="mature-badge mature-badge-slate">
                                                    <i class="fa-solid fa-user-shield me-1 text-primary"></i> <?php echo htmlspecialchars($adm['name']); ?>
                                                </span>
                                            </div>
                                            <div class="small text-muted font-monospace" style="font-size: 0.72rem;"><?php echo htmlspecialchars($adm['email']); ?></div>
                                            <button class="btn btn-link p-0 text-decoration-none text-primary mt-0.5 d-inline-flex align-items-center gap-1" style="font-size: 0.72rem;" onclick='openEditModal(<?php echo json_encode($z); ?>, <?php echo json_encode($admins); ?>)'>
                                                <i class="fa-solid fa-key"></i> Reset Access
                                            </button>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="mature-badge mature-badge-slate mb-1">Unassigned</span>
                                        <div>
                                            <button class="btn btn-link p-0 text-primary small text-decoration-none fw-semibold" style="font-size: 0.72rem;" onclick='openEditModal(<?php echo json_encode($z); ?>, <?php echo json_encode($admins); ?>)'>
                                                <i class="fa-solid fa-user-plus me-1"></i>Set Password
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($z['status'] === 'active'): ?>
                                        <span class="mature-badge mature-badge-emerald">
                                            <i class="fa-solid fa-circle-check me-1"></i>Active
                                        </span>
                                    <?php else: ?>
                                        <span class="mature-badge mature-badge-crimson">
                                            <i class="fa-solid fa-circle-xmark me-1"></i>Inactive
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="btn-group btn-group-sm">
                                        <a href="../zone/billing_config?switch_zone_id=<?php echo $z['id']; ?>" 
                                           class="btn btn-outline-primary btn-sm" 
                                           title="Configure Billing & Installments"
                                           target="_blank">
                                            <i class="fa-solid fa-sliders text-primary"></i>
                                        </a>
                                        <button class="btn btn-outline-secondary btn-sm" 
                                                onclick='openEditModal(<?php echo json_encode($z); ?>, <?php echo json_encode($admins); ?>)' 
                                                title="Edit Zone">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Change status for this zone?');">
                                            <input type="hidden" name="zone_id" value="<?php echo $z['id']; ?>">
                                            <input type="hidden" name="current_status" value="<?php echo $z['status']; ?>">
                                            <button type="submit" name="toggle_status" class="btn btn-outline-secondary btn-sm" title="Toggle Active/Inactive">
                                                <i class="fa-solid fa-power-off <?php echo ($z['status'] === 'active') ? 'text-danger' : 'text-success'; ?>"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- CREATE ZONE MODAL -->
<div class="modal fade" id="createZoneModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-bottom py-3 px-4">
                <h5 class="modal-title fw-bold d-flex align-items-center gap-2">
                    <i class="fa-solid fa-layer-group text-primary"></i>
                    <span>Create New Estate Zone</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="createZoneForm">
                <input type="hidden" name="create_zone" value="1">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <!-- Name & Code -->
                        <div class="col-md-7">
                            <label class="form-label fw-semibold small text-secondary">Zone Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="new_zone_name" class="form-control rounded-3" placeholder="e.g. Zone 3 - Hilltop Enclave" required oninput="autoSuggestZoneCode(this.value)">
                        </div>
                        <div class="col-md-5">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label fw-semibold small text-secondary mb-0">Zone Code</label>
                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 fw-semibold" style="font-size: 0.7rem;">
                                    <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Auto-Generated
                                </span>
                            </div>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted font-monospace"><i class="fa-solid fa-hashtag"></i></span>
                                <input type="text" name="code" id="new_zone_code" class="form-control text-uppercase font-monospace fw-bold text-primary bg-light" value="<?php echo $next_zone_code; ?>" readonly required>
                                <button type="button" class="btn btn-outline-secondary" id="unlockCodeBtn" onclick="toggleZoneCodeEdit()" title="Unlock to customize code">
                                    <i class="fa-solid fa-lock" id="unlockCodeIcon"></i>
                                </button>
                            </div>
                            <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">Auto-sequenced as <code><?php echo $next_zone_code; ?></code>. Click lock to edit.</small>
                        </div>

                        <!-- Email & Phone -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-secondary">Official Contact Email <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fa-solid fa-envelope text-muted"></i></span>
                                <input type="email" name="email" id="new_zone_email" class="form-control rounded-end-3" placeholder="zone@estate.com" required oninput="syncContactEmailToLogin(this.value)">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-secondary">Official Phone / Helpline</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fa-solid fa-phone text-muted"></i></span>
                                <input type="text" name="phone" class="form-control rounded-end-3" placeholder="e.g. 08012345678">
                            </div>
                        </div>

                        <!-- Motto / Slogan -->
                        <div class="col-12">
                            <label class="form-label fw-semibold small text-secondary">Zone Motto / Slogan</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fa-solid fa-quote-left text-muted"></i></span>
                                <input type="text" name="motto" class="form-control rounded-end-3" placeholder="e.g. Unity, Peace & Neighborhood Progress">
                            </div>
                        </div>

                        <!-- Registration Date & Secretariat Address -->
                        <div class="col-md-5">
                            <label class="form-label fw-semibold small text-secondary">Registration Date</label>
                            <input type="date" name="registration_date" value="<?php echo date('Y-m-d'); ?>" class="form-control rounded-3">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label fw-semibold small text-secondary">Office / Secretariat Address</label>
                            <input type="text" name="office_address" class="form-control rounded-3" placeholder="e.g. Zone Secretariat, Block 5">
                        </div>

                        <!-- Boundaries & Status -->
                        <div class="col-md-8">
                            <label class="form-label fw-semibold small text-secondary">Sector Boundaries & Description</label>
                            <textarea name="description" class="form-control rounded-3" rows="2" placeholder="Sector boundaries, landmark reference, or notes..."></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small text-secondary">Status</label>
                            <select name="status" class="form-select rounded-3">
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                        <!-- Zone Portal Login Credentials Section (Always Visible & Intuitive) -->
                        <div class="col-12 mt-4 pt-3 border-top">
                            <div class="d-flex align-items-center justify-content-between mb-1">
                                <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                                    <i class="fa-solid fa-key text-purple" style="color: #9333ea;"></i>
                                    <span>Zone Admin Portal Login Credentials</span>
                                </h6>
                                <span class="badge bg-purple-100 text-purple border" style="background: rgba(168, 85, 247, 0.12); color: #7e22ce; font-size: 0.72rem;">
                                    <i class="fa-solid fa-lock me-1"></i>Required For Zone Login
                                </span>
                            </div>
                            <p class="text-secondary small mb-3">Set the credentials the Zone Admin will use to sign into the <a href="../zone/login" target="_blank" class="fw-semibold text-purple">Zonal Admin Portal</a>.</p>
                        </div>

                        <!-- Login Email & Password -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-secondary">Zone Login Email <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fa-solid fa-at text-muted"></i></span>
                                <input type="email" name="admin_email" id="new_admin_email" class="form-control" placeholder="zone@estate.com" required oninput="this.dataset.manual='1'">
                            </div>
                            <small class="text-muted" style="font-size: 0.72rem;">Synchronizes with Zone Contact Email</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-secondary">Zone Login Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fa-solid fa-lock text-muted"></i></span>
                                <input type="password" name="zone_password" id="new_zone_password" class="form-control" placeholder="Enter password for Zone" value="zonepass123" required>
                                <button type="button" class="btn btn-outline-secondary" onclick="togglePasswordVisibility('new_zone_password', this)" title="Show/Hide Password">
                                    <i class="fa-solid fa-eye" id="new_zone_password_eye"></i>
                                </button>
                                <button type="button" class="btn btn-outline-primary" onclick="generateRandomZonePassword()" title="Generate Random Password">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                                </button>
                            </div>
                            <small class="text-muted" style="font-size: 0.72rem;">Default: <code>zonepass123</code> (click eye to show, wand to randomize)</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-secondary">Admin Display Name</label>
                            <input type="text" name="admin_name" id="new_admin_name" class="form-control rounded-3" placeholder="e.g. Zone Administrator">
                            <small class="text-muted" style="font-size: 0.72rem;">Defaults to "[Zone Name] Admin" if blank</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-secondary">Advanced Option</label>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" id="assignExistingToggle" name="assign_existing" value="1" onchange="toggleExistingAdminDropdown()">
                                <label class="form-check-label small fw-semibold text-secondary" for="assignExistingToggle">
                                    Assign an existing user account instead
                                </label>
                            </div>
                        </div>

                        <div class="col-12" id="existingUserContainer" style="display: none;">
                            <div class="p-3 bg-light border rounded-3">
                                <label class="form-label small fw-semibold">Choose Existing Registered User</label>
                                <select name="existing_user_id" class="form-select form-select-sm">
                                    <option value="0">-- Select User --</option>
                                    <?php foreach ($eligible_users as $eu): ?>
                                        <option value="<?php echo $eu['id']; ?>">
                                            <?php echo htmlspecialchars($eu['name'] . ' (' . $eu['email'] . ') - Role: ' . $eu['role']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Paystack Settlement Bank & Subaccount Section -->
                        <div class="col-12 mt-4 pt-3 border-top">
                            <div class="d-flex align-items-center justify-content-between mb-1">
                                <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                                    <i class="fa-solid fa-building-columns text-primary"></i>
                                    <span>Paystack Settlement Bank & Subaccount</span>
                                </h6>
                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25" style="font-size: 0.72rem;">
                                    <i class="fa-solid fa-cloud-arrow-up me-1"></i>Syncs to Paystack Dashboard
                                </span>
                            </div>
                            <p class="text-secondary small mb-3">
                                Link a Nigerian bank account where this zone's collections should settle. Entering this creates an official <strong>Subaccount</strong> directly in your Paystack dashboard under <em>Settings &gt; Subaccounts</em>.
                            </p>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-secondary">Settlement Bank</label>
                            <select name="settlement_bank" id="new_settlement_bank" class="form-select rounded-3" onchange="resolveAccount('new')">
                                <option value="">-- Select Settlement Bank (Optional) --</option>
                                <?php foreach ($bank_list as $bk): ?>
                                    <option value="<?php echo htmlspecialchars($bk['code']); ?>" data-name="<?php echo htmlspecialchars($bk['name']); ?>">
                                        <?php echo htmlspecialchars($bk['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="settlement_bank_name" id="new_settlement_bank_name">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-secondary">10-Digit Account Number (NUBAN)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fa-solid fa-hashtag text-muted"></i></span>
                                <input type="text" name="settlement_account_number" id="new_settlement_account_number" class="form-control font-monospace fw-semibold" placeholder="e.g. 0123456789" maxlength="10" oninput="resolveAccount('new')">
                            </div>
                            <div id="new_account_resolve_status" class="mt-1 small" style="display:none;"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top py-3 px-4">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold">Save Zone</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- EDIT ZONE MODAL -->
<div class="modal fade" id="editZoneModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-bottom py-3 px-4">
                <h5 class="modal-title fw-bold d-flex align-items-center gap-2">
                    <i class="fa-solid fa-pen-to-square text-primary"></i>
                    <span>Edit Zone Details</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editZoneForm">
                <input type="hidden" name="update_zone" value="1">
                <input type="hidden" name="zone_id" id="edit_zone_id">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label fw-semibold small text-secondary">Zone Name</label>
                            <input type="text" name="name" id="edit_name" class="form-control rounded-3" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold small text-secondary">Zone Code</label>
                            <input type="text" name="code" id="edit_code" class="form-control rounded-3 text-uppercase font-monospace" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-secondary">Official Contact Email</label>
                            <input type="email" name="email" id="edit_email" class="form-control rounded-3" placeholder="zone@estate.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-secondary">Official Phone</label>
                            <input type="text" name="phone" id="edit_phone" class="form-control rounded-3" placeholder="08012345678">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold small text-secondary">Zone Motto / Slogan</label>
                            <input type="text" name="motto" id="edit_motto" class="form-control rounded-3" placeholder="e.g. Unity, Peace & Neighborhood Progress">
                        </div>

                        <div class="col-md-5">
                            <label class="form-label fw-semibold small text-secondary">Registration Date</label>
                            <input type="date" name="registration_date" id="edit_reg_date" class="form-control rounded-3">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label fw-semibold small text-secondary">Office Address</label>
                            <input type="text" name="office_address" id="edit_office_address" class="form-control rounded-3">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold small text-secondary">Zone Description</label>
                            <textarea name="description" id="edit_description" class="form-control rounded-3" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-secondary">Status</label>
                            <select name="status" id="edit_status" class="form-select rounded-3">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-secondary">Assign Zone Admin User</label>
                            <select name="assigned_user_id" id="edit_assigned_user_id" class="form-select rounded-3">
                                <option value="0">-- Keep Current or Unassigned --</option>
                                <?php foreach ($eligible_users as $eu): ?>
                                    <option value="<?php echo $eu['id']; ?>">
                                        <?php echo htmlspecialchars($eu['name'] . ' (' . $eu['email'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Reset / Change Password in Edit Modal -->
                        <div class="col-12 mt-3 pt-3 border-top">
                            <div class="d-flex align-items-center justify-content-between mb-1">
                                <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                                    <i class="fa-solid fa-key text-purple" style="color: #9333ea;"></i>
                                    <span>Reset / Set Zone Admin Password</span>
                                </h6>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary border px-2 py-0.5" style="font-size: 0.7rem;">Optional</span>
                            </div>
                            <p class="text-secondary small mb-2">Enter a new password below to reset login access for this zone. Leave blank to keep existing password unchanged.</p>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label fw-semibold small text-secondary">New Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fa-solid fa-lock text-muted"></i></span>
                                <input type="password" name="reset_password" id="edit_reset_password" class="form-control" placeholder="Enter new password or leave blank">
                                <button type="button" class="btn btn-outline-secondary" onclick="togglePasswordVisibility('edit_reset_password', this)">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold small text-secondary">Quick Generator</label>
                            <button type="button" class="btn btn-outline-primary w-100 rounded-3" onclick="document.getElementById('edit_reset_password').value = 'zonepass' + Math.floor(100 + Math.random() * 900); document.getElementById('edit_reset_password').type = 'text';">
                                <i class="fa-solid fa-wand-magic-sparkles me-1"></i> Randomize
                            </button>
                        </div>

                        <!-- Paystack Settlement Bank & Subaccount Card in Edit Modal -->
                        <div class="col-12 mt-3 pt-3 border-top">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                                    <i class="fa-solid fa-building-columns text-primary"></i>
                                    <span>Paystack Settlement Bank & Subaccount</span>
                                </h6>
                                <span class="badge" id="edit_subaccount_badge" style="background: rgba(2, 132, 199, 0.12); color: #0284c7; font-size: 0.72rem;">Assigned Virtual NUBAN</span>
                            </div>

                            <div class="p-3 bg-light border rounded-3 mb-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
                                <div>
                                    <div class="small text-secondary text-uppercase fw-semibold" style="font-size: 0.7rem;">Active Remittance Account</div>
                                    <div class="d-flex align-items-center gap-2 mt-1">
                                        <span class="badge bg-primary px-2 py-1" id="edit_dva_bank">Wema Bank</span>
                                        <span class="font-monospace fw-bold fs-6 text-dark" id="edit_dva_number">0000000000</span>
                                        <button type="button" class="btn btn-sm btn-link p-0 text-muted" onclick="EstateDialog.copy(document.getElementById('edit_dva_number').innerText, 'Copied account number to clipboard');" title="Copy">
                                            <i class="fa-regular fa-copy"></i>
                                        </button>
                                    </div>
                                    <div class="small text-muted mt-1" style="font-size: 0.75rem;">
                                        Beneficiary: <strong class="text-dark" id="edit_dva_name">-</strong> &bull; Subaccount Code: <code id="edit_dva_subcode" class="text-primary">-</code>
                                    </div>
                                </div>
                                <div>
                                    <button type="button" onclick="triggerRegenerateDva()" class="btn btn-sm btn-outline-primary rounded-3" style="font-size: 0.8rem;">
                                        <i class="fa-solid fa-arrows-rotate me-1"></i> Sync With Paystack
                                    </button>
                                </div>
                            </div>

                            <div class="p-3 bg-white border rounded-3">
                                <label class="form-label small fw-bold text-dark mb-1 d-flex align-items-center gap-1">
                                    <i class="fa-solid fa-link text-primary"></i> Link Settlement Bank (Creates Subaccount in Paystack Dashboard)
                                </label>
                                <p class="text-muted small mb-3" style="font-size: 0.75rem;">
                                    Link a Nigerian commercial bank account for this zone. Saving or syncing will register an official <strong>Subaccount</strong> directly in your Paystack dashboard under <em>Settings &gt; Subaccounts</em>.
                                </p>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-secondary">Bank</label>
                                        <select name="settlement_bank" id="edit_settlement_bank" class="form-select form-select-sm rounded-3" onchange="resolveAccount('edit')">
                                            <option value="">-- Choose Settlement Bank --</option>
                                            <?php foreach ($bank_list as $bk): ?>
                                                <option value="<?php echo htmlspecialchars($bk['code']); ?>" data-name="<?php echo htmlspecialchars($bk['name']); ?>">
                                                    <?php echo htmlspecialchars($bk['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="settlement_bank_name" id="edit_settlement_bank_name">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold text-secondary">10-Digit Account Number (NUBAN)</label>
                                        <input type="text" name="settlement_account_number" id="edit_settlement_account_number" class="form-control form-control-sm font-monospace fw-semibold rounded-3" placeholder="e.g. 0123456789" maxlength="10" oninput="resolveAccount('edit')">
                                        <div id="edit_account_resolve_status" class="mt-1 small" style="display:none;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top py-3 px-4">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold">Update Zone & Sync</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let defaultGeneratedCode = <?php echo json_encode($next_zone_code); ?>;
let isCodeCustomized = false;

function toggleZoneCodeEdit() {
    const input = document.getElementById('new_zone_code');
    const icon = document.getElementById('unlockCodeIcon');
    if (input.hasAttribute('readonly')) {
        input.removeAttribute('readonly');
        input.classList.remove('bg-light');
        input.focus();
        icon.className = 'fa-solid fa-lock-open text-warning';
        isCodeCustomized = true;
    } else {
        input.setAttribute('readonly', 'readonly');
        input.classList.add('bg-light');
        icon.className = 'fa-solid fa-lock text-secondary';
        input.value = defaultGeneratedCode;
        isCodeCustomized = false;
    }
}

function autoSuggestZoneCode(name) {
    if (isCodeCustomized) return;
    const match = name.match(/zone\s*(\d+)/i) || name.match(/sector\s*(\d+)/i);
    if (match) {
        const num = String(match[1]).padStart(2, '0');
        document.getElementById('new_zone_code').value = 'ZN-' + num;
    } else {
        document.getElementById('new_zone_code').value = defaultGeneratedCode;
    }
}

function togglePasswordVisibility(fieldId, btn) {
    const field = document.getElementById(fieldId);
    const icon = btn.querySelector('i');
    if (field.type === 'password') {
        field.type = 'text';
        icon.className = 'fa-solid fa-eye-slash text-warning';
    } else {
        field.type = 'password';
        icon.className = 'fa-solid fa-eye';
    }
}

function generateRandomZonePassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    let pass = 'zn';
    for (let i = 0; i < 6; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    const passInput = document.getElementById('new_zone_password');
    passInput.value = pass;
    passInput.type = 'text';
    const eye = document.getElementById('new_zone_password_eye');
    if (eye) eye.className = 'fa-solid fa-eye-slash text-warning';
}

function syncContactEmailToLogin(val) {
    const adminEmail = document.getElementById('new_admin_email');
    if (adminEmail && (!adminEmail.dataset.manual || adminEmail.dataset.manual === '0')) {
        adminEmail.value = val;
    }
}

function toggleExistingAdminDropdown() {
    const chk = document.getElementById('assignExistingToggle');
    const container = document.getElementById('existingUserContainer');
    if (chk.checked) {
        container.style.display = 'block';
    } else {
        container.style.display = 'none';
    }
}

let resolveTimeout = null;
function resolveAccount(prefix) {
    const bankSelect = document.getElementById(prefix + '_settlement_bank');
    const accInput = document.getElementById(prefix + '_settlement_account_number');
    const statusDiv = document.getElementById(prefix + '_account_resolve_status');
    const nameInput = document.getElementById(prefix + '_settlement_bank_name');

    if (nameInput && bankSelect && bankSelect.selectedOptions[0]) {
        nameInput.value = bankSelect.selectedOptions[0].dataset.name || bankSelect.selectedOptions[0].text;
    }

    const bankCode = bankSelect ? bankSelect.value : '';
    const accNum = accInput ? accInput.value.trim() : '';

    if (accNum.length !== 10 || !bankCode) {
        statusDiv.style.display = 'none';
        return;
    }

    clearTimeout(resolveTimeout);
    statusDiv.style.display = 'block';
    statusDiv.className = 'mt-1 small text-muted';
    statusDiv.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Verifying account with Paystack & NIBSS...';

    resolveTimeout = setTimeout(() => {
        fetch('../api/resolve_bank.php?account_number=' + encodeURIComponent(accNum) + '&bank_code=' + encodeURIComponent(bankCode))
            .then(res => res.json())
            .then(data => {
                if (data.status && data.account_name) {
                    statusDiv.className = 'mt-1 small text-success fw-bold';
                    statusDiv.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i> Verified: ' + data.account_name;
                } else {
                    statusDiv.className = 'mt-1 small text-secondary';
                    statusDiv.innerHTML = '<i class="fa-solid fa-circle-info me-1"></i> ' + (data.message || 'Account details unverified');
                }
            })
            .catch(() => {
                statusDiv.style.display = 'none';
            });
    }, 600);
}

function openEditModal(zone, admins) {
    document.getElementById('edit_zone_id').value = zone.id;
    document.getElementById('edit_name').value = zone.name;
    document.getElementById('edit_code').value = zone.code;
    document.getElementById('edit_email').value = zone.email || '';
    document.getElementById('edit_phone').value = zone.phone || '';
    document.getElementById('edit_motto').value = zone.motto || '';
    document.getElementById('edit_reg_date').value = zone.registration_date || '';
    document.getElementById('edit_office_address').value = zone.office_address || '';
    document.getElementById('edit_description').value = zone.description || '';
    document.getElementById('edit_status').value = zone.status;
    document.getElementById('edit_reset_password').value = '';

    // Populate DVA & Subaccount details
    const dvaBank = document.getElementById('edit_dva_bank');
    const dvaNum = document.getElementById('edit_dva_number');
    const dvaName = document.getElementById('edit_dva_name');
    const dvaSub = document.getElementById('edit_dva_subcode');
    const subBadge = document.getElementById('edit_subaccount_badge');

    if (dvaBank) dvaBank.innerText = zone.paystack_bank_name || 'Wema Bank';
    if (dvaNum) dvaNum.innerText = zone.paystack_account_number || 'Unassigned';
    if (dvaName) dvaName.innerText = zone.paystack_account_name || (zone.name + ' / Estate');
    if (dvaSub) dvaSub.innerText = zone.paystack_subaccount_code || 'None';

    if (zone.paystack_subaccount_code && zone.paystack_subaccount_code.startsWith('SUB_') && zone.paystack_subaccount_code.length > 15) {
        if (subBadge) {
            subBadge.className = 'badge bg-success';
            subBadge.innerHTML = '<i class="fa-solid fa-cloud-check me-1"></i> Live Paystack Subaccount';
        }
    } else {
        if (subBadge) {
            subBadge.className = 'badge bg-info bg-opacity-10 text-primary border border-primary border-opacity-25';
            subBadge.innerHTML = '<i class="fa-solid fa-building-columns me-1"></i> Virtual Account / Local';
        }
    }

    // Populate settlement inputs if available
    const editBankSelect = document.getElementById('edit_settlement_bank');
    const editAccInput = document.getElementById('edit_settlement_account_number');
    const editBankNameInput = document.getElementById('edit_settlement_bank_name');

    if (editBankSelect) editBankSelect.value = zone.paystack_bank_code || '';
    if (editBankNameInput) editBankNameInput.value = zone.paystack_bank_name || '';
    if (editAccInput) {
        // If it's a mock 99 number, leave input blank for user to enter their real bank account
        editAccInput.value = (zone.paystack_account_number && !zone.paystack_account_number.startsWith('99')) ? zone.paystack_account_number : '';
    }
    const statusDiv = document.getElementById('edit_account_resolve_status');
    if (statusDiv) statusDiv.style.display = 'none';
    
    const userSelect = document.getElementById('edit_assigned_user_id');
    if (admins && admins.length > 0) {
        userSelect.value = admins[0].id;
    } else {
        userSelect.value = "0";
    }

    const modal = new bootstrap.Modal(document.getElementById('editZoneModal'));
    modal.show();
}

function triggerRegenerateDva() {
    const zid = document.getElementById('edit_zone_id').value;
    if (!zid) return;

    const bankCode = document.getElementById('edit_settlement_bank').value;
    const bankName = document.getElementById('edit_settlement_bank_name').value;
    const accNum = document.getElementById('edit_settlement_account_number').value.trim();

    let confirmMsg = 'Re-sync / create Paystack Subaccount for this zone?';
    if (!bankCode || !accNum) {
        confirmMsg = 'You have not entered a settlement bank and account number. If you have a Nigerian bank account for this zone, enter it now to create an official Subaccount visible in your Paystack dashboard. Proceed with sync anyway?';
    }

    const doSubmit = () => {
        const f = document.createElement('form');
        f.method = 'POST';
        f.innerHTML = '<input type="hidden" name="regenerate_dva" value="1">' +
                      '<input type="hidden" name="zone_id" value="' + zid + '">' +
                      '<input type="hidden" name="settlement_bank" value="' + encodeURIComponent(bankCode) + '">' +
                      '<input type="hidden" name="settlement_bank_name" value="' + encodeURIComponent(bankName) + '">' +
                      '<input type="hidden" name="settlement_account_number" value="' + encodeURIComponent(accNum) + '">';
        document.body.appendChild(f);
        f.submit();
    };

    if (window.EstateDialog) {
        EstateDialog.confirm({
            title: 'Sync Paystack Subaccount?',
            message: confirmMsg,
            type: 'info',
            confirmText: 'Yes, Sync Account'
        }).then(confirmed => {
            if (confirmed) doSubmit();
        });
    } else {
        doSubmit();
    }
}
</script>

<?php include '../includes/footer.php'; ?>
