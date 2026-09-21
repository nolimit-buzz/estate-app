<?php
// zone/charges.php - Local Zonal Charges & Tariffs Catalog (Strictly Scoped)
require_once '../config.php';
require_once '../includes/auth_guard.php';

requireZoneAccess();

$zone_id = get_current_zone_id();
$estate_id = get_estate_id();
$message = $flash_message ?? "";
$error = $flash_error ?? "";

// -------------------------------------------------------------
// POST ACTIONS: CREATE / EDIT / TOGGLE / DELETE LOCAL ZONAL CHARGES (PRG Protected)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 1. ADD LOCAL ZONAL CHARGE (Simplified: Charge Name, Description, Amount, Plan)
    if (isset($_POST['add_charge'])) {
        $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $charge_type = $name;
        $desc = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $amount = floatval($_POST['amount'] ?? 0);
        $plan = $conn->real_escape_string(trim($_POST['plan'] ?? 'Monthly'));
        $allow_installments = isset($_POST['allow_installments']) ? 1 : 0;
        $due_day = 1; // Defaulted internally
        $user_id = $_SESSION['user_id'] ?? null;
        
        if (!empty($name) && $amount >= 0) {
            // Check for duplicate charge name in this zone
            $chk_dup = $conn->query("SELECT id FROM estate_charges WHERE name = '$name' AND zone_id = $zone_id AND estate_id = $estate_id LIMIT 1");
            if ($chk_dup && $chk_dup->num_rows > 0) {
                redirectWithFlash('charges', null, "A zonal charge named '$name' already exists in this zone.");
            }

            // Find or auto-add to zonal_charge_types if not exists
            $ct_res = $conn->query("SELECT id FROM zonal_charge_types WHERE name = '$name' AND (zone_id = $zone_id OR zone_id IS NULL) LIMIT 1");
            $charge_type_id = 0;
            if ($ct_res && $ct_row = $ct_res->fetch_assoc()) {
                $charge_type_id = intval($ct_row['id']);
            } else {
                $code = strtolower(str_replace(' ', '-', $name));
                $conn->query("INSERT INTO zonal_charge_types (estate_id, zone_id, name, code, icon, color, description, status) 
                              VALUES ($estate_id, $zone_id, '$name', '$code', 'fa-tag', '#7e22ce', 'Custom zonal tariff', 'Active')");
                $charge_type_id = $conn->insert_id;
            }

            $sql = "INSERT INTO estate_charges (estate_id, zone_id, name, charge_type, charge_type_id, description, amount, frequency, due_day, allow_installments, status, created_by) 
                    VALUES ($estate_id, $zone_id, '$name', '$charge_type', " . ($charge_type_id ? $charge_type_id : "NULL") . ", '$desc', $amount, '$plan', $due_day, $allow_installments, 'Active', " . ($user_id ? $user_id : "NULL") . ")";
            if ($conn->query($sql)) {
                logAudit($conn, "Zone Charge Created", "Finance", "Created zonal charge '$name' (₦$amount, Plan: $plan) for Zone #$zone_id");
                redirectWithFlash('charges', "Zonal charge '$name' created successfully!");
            } else {
                redirectWithFlash('charges', null, "Error creating charge: " . $conn->error);
            }
        } else {
            redirectWithFlash('charges', null, "Please enter a Charge Name and a valid amount.");
        }
    } 

    // 2. EDIT LOCAL ZONAL CHARGE
    elseif (isset($_POST['edit_charge'])) {
        $charge_id = intval($_POST['charge_id']);
        $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $charge_type = $name;
        $desc = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $amount = floatval($_POST['amount'] ?? 0);
        $plan = $conn->real_escape_string(trim($_POST['plan'] ?? 'Monthly'));
        $allow_installments = isset($_POST['allow_installments']) ? 1 : 0;

        // Ensure charge belongs to this zone
        $chk = $conn->query("SELECT id FROM estate_charges WHERE id = $charge_id AND zone_id = $zone_id AND estate_id = $estate_id LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            if (!empty($name) && $amount >= 0) {
                // Check name collision with another charge
                $chk_dup = $conn->query("SELECT id FROM estate_charges WHERE name = '$name' AND id != $charge_id AND zone_id = $zone_id AND estate_id = $estate_id LIMIT 1");
                if ($chk_dup && $chk_dup->num_rows > 0) {
                    redirectWithFlash('charges', null, "Another zonal charge named '$name' already exists in this zone.");
                }

                $ct_res = $conn->query("SELECT id FROM zonal_charge_types WHERE name = '$name' AND (zone_id = $zone_id OR zone_id IS NULL) LIMIT 1");
                $charge_type_id = ($ct_res && $ct_row = $ct_res->fetch_assoc()) ? intval($ct_row['id']) : 0;

                $sql = "UPDATE estate_charges 
                        SET name = '$name', charge_type = '$charge_type', charge_type_id = " . ($charge_type_id ? $charge_type_id : "NULL") . ", description = '$desc', amount = $amount, frequency = '$plan', allow_installments = $allow_installments 
                        WHERE id = $charge_id AND zone_id = $zone_id AND estate_id = $estate_id";
                if ($conn->query($sql)) {
                    logAudit($conn, "Zone Charge Updated", "Finance", "Updated zonal charge #$charge_id ('$name', Plan: $plan)");
                    redirectWithFlash('charges', "Zonal charge '$name' updated successfully!");
                } else {
                    redirectWithFlash('charges', null, "Error updating charge: " . $conn->error);
                }
            } else {
                redirectWithFlash('charges', null, "Please provide valid charge details.");
            }
        } else {
            redirectWithFlash('charges', null, "Unauthorized operation on charge.");
        }
    } 

    // 3. TOGGLE CHARGE ACTIVE/INACTIVE
    elseif (isset($_POST['toggle_status'])) {
        $id = intval($_POST['charge_id']);
        $chk = $conn->query("SELECT status, name FROM estate_charges WHERE id = $id AND zone_id = $zone_id AND estate_id = $estate_id LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            $row = $chk->fetch_assoc();
            $curr = $row['status'];
            $new_st = ($curr === 'Active') ? 'Inactive' : 'Active';
            $conn->query("UPDATE estate_charges SET status = '$new_st' WHERE id = $id AND zone_id = $zone_id AND estate_id = $estate_id");
            logAudit($conn, "Zone Charge Status Toggled", "Finance", "Toggled charge #$id to $new_st in Zone #$zone_id");
            redirectWithFlash('charges', "Charge '{$row['name']}' status updated to $new_st.");
        }
        redirectWithFlash('charges');
    } 

    // 4. DELETE CHARGE
    elseif (isset($_POST['delete_charge'])) {
        $id = intval($_POST['charge_id']);
        if ($conn->query("DELETE FROM estate_charges WHERE id = $id AND zone_id = $zone_id AND estate_id = $estate_id")) {
            logAudit($conn, "Zone Charge Deleted", "Finance", "Deleted charge #$id in Zone #$zone_id");
            redirectWithFlash('charges', "Zonal charge removed from catalog.");
        } else {
            redirectWithFlash('charges', null, "Failed to delete charge: " . $conn->error);
        }
    }
}


// Summary Statistics for KPI Ribbon (Combining Local and Active Central Charges available to Zone)
$stats_row = $conn->query("
    SELECT 
        COUNT(*) as total_charges,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_charges,
        SUM(CASE WHEN frequency IN ('Monthly', 'Yearly', 'Quarterly') AND status = 'Active' THEN 1 ELSE 0 END) as recurring_charges,
        AVG(CASE WHEN status = 'Active' THEN amount ELSE NULL END) as avg_amount
    FROM estate_charges 
    WHERE (zone_id = $zone_id OR (zone_id IS NULL AND status = 'Active')) AND estate_id = $estate_id
")->fetch_assoc();

$total_charges = (int)($stats_row['total_charges'] ?? 0);
$active_charges = (int)($stats_row['active_charges'] ?? 0);
$recurring_charges = (int)($stats_row['recurring_charges'] ?? 0);
$avg_charge_amount = (float)($stats_row['avg_amount'] ?? 0);

// Fetch Local Zonal Charges
$local_charges = $conn->query("
    SELECT * FROM estate_charges 
    WHERE zone_id = $zone_id AND estate_id = $estate_id 
    ORDER BY status ASC, name ASC
");

// Fetch Central Estate-Wide Charges (Read-Only Reference)
$central_charges = $conn->query("
    SELECT * FROM estate_charges 
    WHERE zone_id IS NULL AND estate_id = $estate_id AND status = 'Active' 
    ORDER BY name ASC
");

// Fetch Dynamic Active Frequencies (Zone-Specific or System Defaults)
$active_frequencies = [];
$f_res = $conn->query("
    SELECT * FROM zonal_billing_frequencies 
    WHERE (zone_id = $zone_id OR (zone_id IS NULL AND name NOT IN (SELECT name FROM zonal_billing_frequencies WHERE zone_id = $zone_id)))
      AND status = 'Active'
    ORDER BY sort_order ASC, name ASC
");
if ($f_res) {
    while ($r = $f_res->fetch_assoc()) $active_frequencies[] = $r;
}
if (empty($active_frequencies)) {
    $active_frequencies = [
        ['name' => 'Monthly', 'interval_days' => 30],
        ['name' => 'Bi-Monthly', 'interval_days' => 60],
        ['name' => 'Quarterly', 'interval_days' => 90],
        ['name' => 'Semi-Annually', 'interval_days' => 180],
        ['name' => 'Yearly', 'interval_days' => 365],
        ['name' => 'One-Time', 'interval_days' => 0]
    ];
}

// Fetch Dynamic Active Charge Types
$active_charge_types = [];
$ct_res = $conn->query("
    SELECT * FROM zonal_charge_types 
    WHERE (zone_id = $zone_id OR (zone_id IS NULL AND name NOT IN (SELECT name FROM zonal_charge_types WHERE zone_id = $zone_id)))
      AND status = 'Active'
    ORDER BY name ASC
");
if ($ct_res) {
    while ($r = $ct_res->fetch_assoc()) $active_charge_types[] = $r;
}

include 'header.php';
include 'sidebar.php';
?>

<div class="page-header-futuristic mb-4">
    <div>
        <div class="header-breadcrumbs">
            <span>Treasury & Billing</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <a href="finance" style="color: var(--text-muted); text-decoration: none;">Zone Finance</a>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span class="active">Charge Catalog</span>
        </div>
        <h1 class="page-title">Zonal Charges & Dues Catalog</h1>
        <p class="page-subtitle">Configure local development dues, sanitation levies, security lighting, and view central estate tariffs applicable to <?php echo htmlspecialchars($_SESSION['zone_name'] ?? 'this zone'); ?>.</p>
    </div>
    <div class="header-actions">
        <a href="billing_config" class="btn btn-outline-secondary" style="display:inline-flex; align-items:center; gap:0.5rem; font-weight:600; padding:0.6rem 1.1rem; border-radius:0.5rem; text-decoration:none;">
            <i class="fa-solid fa-sliders"></i> Installments & Policy
        </a>
        <button onclick="openCreateChargeModal()" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:0.5rem; font-weight:600; padding:0.6rem 1.25rem; border-radius:0.5rem; background:#6b21a8; border-color:#6b21a8;">
            <i class="fa-solid fa-plus"></i> Create Charge
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert mature-card p-3 mb-4" style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); color: #059669; border-radius: 0.65rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fa-solid fa-circle-check" style="font-size: 1.1rem;"></i>
        <div style="font-weight: 500; font-size: 0.9rem;"><?= htmlspecialchars($message) ?></div>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert mature-card p-3 mb-4" style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; border-radius: 0.65rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fa-solid fa-circle-exclamation" style="font-size: 1.1rem;"></i>
        <div style="font-weight: 500; font-size: 0.9rem;"><?= htmlspecialchars($error) ?></div>
    </div>
<?php endif; ?>

<!-- ==========================================
     EXECUTIVE 4-PILLAR KPI RIBBON
     ========================================== -->
<div class="row g-3 mb-4">
    <!-- Pillar 1: Total Applicable Tariffs -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Applicable Tariffs</span>
                    <div class="kpi-value"><?= number_format($total_charges) ?></div>
                </div>
                <div class="kpi-icon-wrap" style="color: #7e22ce; background: rgba(126, 34, 206, 0.1);">
                    <i class="fa-solid fa-tags"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Zonal & Central</span>
                <span class="mature-badge mature-badge-slate"><i class="fa-solid fa-layer-group me-1"></i> Catalog</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: 100%; background: #7e22ce;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 2: Active Billing Items -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Active Invoicing Tariffs</span>
                    <div class="kpi-value"><?= number_format($active_charges) ?></div>
                </div>
                <div class="kpi-icon-wrap" style="color: #059669; background: rgba(5, 150, 105, 0.1);">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span><?= $total_charges > 0 ? round(($active_charges / $total_charges) * 100) : 0 ?>% Operative Ratio</span>
                <span class="mature-badge mature-badge-emerald">Live Dues</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?= $total_charges > 0 ? round(($active_charges / $total_charges) * 100) : 0 ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 3: Recurring Assessment Tariffs -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Periodic Recurring Dues</span>
                    <div class="kpi-value"><?= number_format($recurring_charges) ?></div>
                </div>
                <div class="kpi-icon-wrap" style="color: #0284c7; background: rgba(2, 132, 199, 0.1);">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Monthly, Qtr & Annual</span>
                <span class="mature-badge mature-badge-sky">Scheduled</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?= $total_charges > 0 ? round(($recurring_charges / $total_charges) * 100) : 0 ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 4: Average Standard Tariff -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Average Active Tariff</span>
                    <div class="kpi-value">₦<?= number_format($avg_charge_amount, 2) ?></div>
                </div>
                <div class="kpi-icon-wrap" style="color: #d97706; background: rgba(217, 119, 6, 0.1);">
                    <i class="fa-solid fa-calculator"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Benchmark Per Invoicing Line</span>
                <span class="mature-badge mature-badge-amber">Tariff Avg</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: 70%; background: #d97706;"></div>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     INTERACTIVE FILTER & SEARCH TOOLBAR
     ========================================== -->
<div class="futuristic-filter-bar mb-3">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-stretch align-items-md-center gap-3">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <button class="filter-btn-pill active" onclick="setChargeFrequencyFilter('all', this)">
                <i class="fa-solid fa-list-ul me-1"></i> All Tariffs (<?= $total_charges ?>)
            </button>
            <button class="filter-btn-pill" onclick="setChargeFrequencyFilter('local', this)">
                <i class="fa-solid fa-map-pin me-1"></i> Zone Local Only
            </button>
            <button class="filter-btn-pill" onclick="setChargeFrequencyFilter('monthly', this)">
                <i class="fa-solid fa-calendar-days me-1"></i> Monthly
            </button>
            <button class="filter-btn-pill" onclick="setChargeFrequencyFilter('quarterly', this)">
                <i class="fa-solid fa-chart-pie me-1"></i> Quarterly
            </button>
            <button class="filter-btn-pill" onclick="setChargeFrequencyFilter('yearly', this)">
                <i class="fa-solid fa-calendar me-1"></i> Yearly
            </button>
            <button class="filter-btn-pill" onclick="setChargeFrequencyFilter('one-time', this)">
                <i class="fa-solid fa-bolt me-1"></i> One-Time
            </button>
            <button class="filter-btn-pill" onclick="setChargeFrequencyFilter('active', this)">
                <i class="fa-solid fa-circle-check me-1"></i> Active Only
            </button>
        </div>
        <div class="position-relative" style="min-width: 280px;">
            <i class="fa-solid fa-magnifying-glass position-absolute text-muted" style="top: 50%; left: 0.85rem; transform: translateY(-50%); font-size: 0.85rem;"></i>
            <input type="text" id="chargeSearchInput" class="form-control ps-5" placeholder="Search charge name, frequency, rate..." onkeyup="filterChargesTable()">
        </div>
    </div>
</div>

<!-- ==========================================
     CHARGES CATALOG LEDGER CARD & TABLE
     ========================================== -->
<div class="mature-card mb-4">
    <div class="mature-card-header">
        <div>
            <h2 class="mature-card-title">
                <i class="fa-solid fa-tags text-secondary"></i> Zonal Charges & Tariffs Ledger
            </h2>
            <p style="margin: 0.25rem 0 0; font-size: 0.8rem; color: var(--text-muted);">Configured baseline charges automatically populated during resident billing generation.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="tech-chip"><i class="fa-solid fa-list-check"></i> Showing: <span id="visibleChargesCount"><?= $total_charges ?></span></span>
            <button type="button" onclick="exportChargesCSV()" class="btn btn-sm btn-outline-secondary" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;">
                <i class="fa-solid fa-file-arrow-down me-1"></i> Export CSV
            </button>
        </div>
    </div>

    <div class="mature-card-body p-0">
        <div class="table-responsive">
            <table class="table dashboard-table align-middle" id="chargesTable">
                <thead>
                    <tr>
                        <th style="width: 110px;">Item Code</th>
                        <th>Charge Name</th>
                        <th>Description</th>
                        <th>Scope</th>
                        <th>Amount</th>
                        <th>Plan</th>
                        <th>Installments</th>
                        <th>Status</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody id="chargesTableBody">
                    <?php 
                    $has_charges = false;
                    
                    // Render Local Zonal Charges First
                    if ($local_charges && $local_charges->num_rows > 0): 
                        $has_charges = true;
                        while($row = $local_charges->fetch_assoc()):
                            $charge_code = 'ZN-CHG-' . sprintf("%04d", $row['id']);
                            $freq_val = strtolower($row['frequency'] ?? 'yearly');
                            $status_val = strtolower($row['status'] ?? 'active');
                            $category_name = $row['charge_type'] ?: $row['name'];
                            $allow_inst = intval($row['allow_installments'] ?? 1);
                            $search_meta = strtolower($charge_code . ' ' . $row['name'] . ' ' . $category_name . ' ' . ($row['description'] ?? '') . ' ' . $row['frequency'] . ' ' . $row['amount'] . ' local zone');
                    ?>
                        <tr class="charge-row" data-scope="local" data-frequency="<?= htmlspecialchars($freq_val) ?>" data-status="<?= htmlspecialchars($status_val) ?>" data-search="<?= htmlspecialchars($search_meta) ?>">
                            <td>
                                <span class="id-chip" style="background: rgba(126, 34, 206, 0.1); color: #7e22ce; border-color: rgba(126, 34, 206, 0.25);"><?= htmlspecialchars($charge_code) ?></span>
                            </td>
                            <td>
                                <div style="font-weight: 700; color: #1e293b; font-size: 0.93rem; display: flex; align-items: center; gap: 0.4rem;">
                                    <i class="fa-solid fa-tag text-purple" style="color: #7e22ce; font-size: 0.8rem;"></i>
                                    <?= htmlspecialchars($category_name) ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-size: 0.84rem; color: #475569; max-width: 300px; line-height: 1.35;">
                                    <?= !empty($row['description']) ? htmlspecialchars($row['description']) : '<span class="text-muted fst-italic">No description provided</span>' ?>
                                </div>
                            </td>
                            <td>
                                <span class="mature-badge" style="background: rgba(126, 34, 206, 0.12); color: #6b21a8; border: 1px solid rgba(126, 34, 206, 0.25);">
                                    <i class="fa-solid fa-map-pin me-1"></i> Zone Local
                                </span>
                            </td>
                            <td>
                                <span style="font-weight: 800; color: #0f172a; font-size: 0.98rem;">₦<?= number_format($row['amount'], 2) ?></span>
                            </td>
                            <td>
                                <span class="mature-badge mature-badge-sky"><i class="fa-solid fa-calendar-days me-1"></i> <?= htmlspecialchars($row['frequency']) ?></span>
                            </td>
                            <td>
                                <?php if ($allow_inst): ?>
                                    <span class="badge" style="background: rgba(168, 85, 247, 0.12); color: #7e22ce; font-size: 0.75rem; border: 1px solid rgba(168, 85, 247, 0.25);">
                                        <i class="fa-solid fa-chart-pie me-1"></i> Allowed
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border" style="font-size: 0.75rem;">Full Pay Only</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['status'] == 'Active'): ?>
                                    <span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-circle-check me-1"></i> Active</span>
                                <?php else: ?>
                                    <span class="mature-badge mature-badge-slate"><i class="fa-solid fa-circle-pause me-1"></i> Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <div style="display: inline-flex; align-items: center; gap: 0.35rem;">
                                    <button type="button" onclick='openEditChargeModal(<?= json_encode($row) ?>)' class="btn btn-sm btn-outline-secondary" style="padding: 0.35rem 0.6rem; font-size: 0.78rem; font-weight: 600;" title="Edit Zonal Charge">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="charge_id" value="<?= $row['id'] ?>">
                                        <button type="submit" name="toggle_status" class="btn btn-sm btn-outline-secondary" style="padding: 0.35rem 0.6rem; font-size: 0.78rem; font-weight: 600; cursor: pointer;">
                                            <?= $row['status'] == 'Active' ? '<i class="fa-solid fa-pause text-warning" title="Suspend"></i>' : '<i class="fa-solid fa-play text-success" title="Activate"></i>' ?>
                                        </button>
                                    </form>
                                    <form method="POST" onsubmit="return confirm('Delete this local charge tariff? It cannot be undone.');" style="display: inline;">
                                        <input type="hidden" name="charge_id" value="<?= $row['id'] ?>">
                                        <button type="submit" name="delete_charge" class="btn btn-sm btn-outline-danger" style="padding: 0.35rem 0.55rem; font-size: 0.78rem; cursor: pointer;" title="Delete Charge Tariff">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php 
                        endwhile; 
                    endif; 

                    // Render Central Charges as Read-Only Baseline
                    if ($central_charges && $central_charges->num_rows > 0): 
                        $has_charges = true;
                        while($row = $central_charges->fetch_assoc()):
                            $charge_code = 'CHG-' . sprintf("%04d", $row['id']);
                            $freq_val = strtolower($row['frequency'] ?? 'yearly');
                            $status_val = strtolower($row['status'] ?? 'active');
                            $search_meta = strtolower($charge_code . ' ' . $row['name'] . ' ' . ($row['description'] ?? '') . ' ' . $row['frequency'] . ' ' . $row['amount'] . ' central estate');
                    ?>
                        <tr class="charge-row" data-scope="central" data-frequency="<?= htmlspecialchars($freq_val) ?>" data-status="<?= htmlspecialchars($status_val) ?>" data-search="<?= htmlspecialchars($search_meta) ?>">
                            <td>
                                <span class="id-chip"><?= htmlspecialchars($charge_code) ?></span>
                            </td>
                            <td>
                                <div style="font-weight: 700; color: #1e293b; font-size: 0.93rem; display: flex; align-items: center; gap: 0.4rem;">
                                    <i class="fa-solid fa-building text-secondary" style="font-size: 0.8rem;"></i>
                                    <?= htmlspecialchars($row['charge_type'] ?: $row['name']) ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-size: 0.84rem; color: #64748b; max-width: 300px; line-height: 1.35;">
                                    <?= !empty($row['description']) ? htmlspecialchars($row['description']) : '<span class="text-muted fst-italic">Central estate-wide tariff</span>' ?>
                                </div>
                            </td>
                            <td>
                                <span class="mature-badge mature-badge-slate" title="Estate-wide standard set by Central Management">
                                    <i class="fa-solid fa-building-shield me-1"></i> Central Baseline
                                </span>
                            </td>
                            <td>
                                <span style="font-weight: 800; color: #0f172a; font-size: 0.98rem;">₦<?= number_format($row['amount'], 2) ?></span>
                            </td>
                            <td>
                                <span class="mature-badge mature-badge-sky"><i class="fa-solid fa-calendar-days me-1"></i> <?= htmlspecialchars($row['frequency']) ?></span>
                            </td>
                            <td>
                                <?php if (!empty($row['allow_installments'])): ?>
                                    <span class="badge" style="background: rgba(168, 85, 247, 0.12); color: #7e22ce; font-size: 0.75rem;">Allowed</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border" style="font-size: 0.75rem;">Full Pay</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-circle-check me-1"></i> Active</span>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <span class="badge bg-light text-muted border" style="font-size: 0.72rem;">Central Managed</span>
                            </td>
                        </tr>
                    <?php 
                        endwhile; 
                    endif; 

                    if (!$has_charges): 
                    ?>
                        <tr><td colspan="9" style="text-align: center; padding: 3.5rem 1rem; color: var(--text-muted);">No charges defined yet. Click "Create Charge" above to configure your first local zonal fee.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ==========================================
     CREATE ZONAL CHARGE MODAL (SIMPLIFIED & SCROLLABLE)
     Fields: Charge Name, Description, Amount, Plan, Allow Installments
     ========================================== -->
<div id="chargeModal" class="custom-modal-backdrop" style="display:none; position: fixed; inset: 0; z-index: 1050; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(6px); overflow-y: auto; align-items: flex-start; justify-content: center; padding: 2.5rem 1rem;">
    <div class="modal-content mature-card" style="max-width: 500px; width: 100%; margin: auto; max-height: calc(100vh - 4.5rem); overflow-y: auto; border-radius: 0.85rem; border: 1px solid var(--border-color); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); padding: 1.5rem; padding-bottom: 2.5rem;">
        <div class="modal-header" style="border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; margin-bottom: 1.25rem;">
            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <div style="width: 36px; height: 36px; border-radius: 0.45rem; background: rgba(126, 34, 206, 0.1); color: #7e22ce; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                    <i class="fa-solid fa-plus"></i>
                </div>
                <div>
                    <h2 style="margin: 0; font-size: 1.15rem; font-weight: 700; color: var(--text-color);">Create Charge</h2>
                    <p style="margin: 0.15rem 0 0; font-size: 0.78rem; color: var(--text-muted);">Configure billable charge for <?= htmlspecialchars($_SESSION['zone_name'] ?? 'this zone') ?></p>
                </div>
            </div>
            <button type="button" onclick="document.getElementById('chargeModal').style.display='none'" style="background: none; border: none; font-size: 1.15rem; color: var(--text-muted); cursor: pointer;"><i class="fa-solid fa-times"></i></button>
        </div>
        
        <form method="POST">
            <input type="hidden" name="add_charge" value="1">
            
            <!-- 1. Charge Name -->
            <div style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom:0.35rem; font-size: 0.8rem; font-weight: 700; color: var(--text-color); text-transform: uppercase; letter-spacing: 0.03em;">Charge Name <span class="text-danger">*</span></label>
                <input type="text" name="name" id="add_charge_name" placeholder="e.g. Security Levy, Waste Disposal, Estate Dues" required class="form-control" style="border-radius: 0.5rem; font-size: 0.95rem; font-weight: 600;">
            </div>

            <!-- 2. Description -->
            <div style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom:0.35rem; font-size: 0.8rem; font-weight: 700; color: var(--text-color); text-transform: uppercase; letter-spacing: 0.03em;">Description</label>
                <textarea name="description" rows="2" placeholder="Detail coverage (e.g. Gate security patrol, transformer maintenance, waste pickup)" class="form-control" style="border-radius: 0.5rem; font-size: 0.88rem;"></textarea>
            </div>

            <!-- 3. Amount -->
            <div style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom:0.35rem; font-size: 0.8rem; font-weight: 700; color: var(--text-color); text-transform: uppercase; letter-spacing: 0.03em;">Amount (₦) <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text" style="background: #f8fafc; border-color: #cbd5e1; font-weight: 800; color: #334155; border-radius: 0.5rem 0 0 0.5rem; font-size: 1rem;">₦</span>
                    <input type="number" step="0.01" min="0" name="amount" placeholder="0.00" required class="form-control" style="border-radius: 0 0.5rem 0.5rem 0; font-size: 1.05rem; font-weight: 700; border-color: #cbd5e1;">
                </div>
            </div>

            <!-- 4. Plan (either monthly, etc.) -->
            <div style="margin-bottom: 1.25rem;">
                <label style="display:block; margin-bottom:0.35rem; font-size: 0.8rem; font-weight: 700; color: var(--text-color); text-transform: uppercase; letter-spacing: 0.03em;">Plan (e.g. Monthly, Quarterly, Yearly) <span class="text-danger">*</span></label>
                <select name="plan" required class="form-control no-search" style="border-radius: 0.5rem; font-size: 0.92rem; font-weight: 600;">
                    <?php foreach ($active_frequencies as $af): ?>
                        <option value="<?= htmlspecialchars($af['name']) ?>"><?= htmlspecialchars($af['name']) ?><?= $af['interval_days'] > 0 ? " (Every {$af['interval_days']} Days)" : " (One-Time)" ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 5. Allow Installments -->
            <div class="p-2.5 rounded border mb-3" style="background: rgba(126, 34, 206, 0.05); border-color: rgba(126, 34, 206, 0.2) !important;">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="allow_installments" id="add_allow_installments" value="1" checked style="cursor: pointer;">
                    <label class="form-check-label fw-bold" for="add_allow_installments" style="cursor: pointer; font-size: 0.88rem;">
                        Allow Installment Payments for this Charge
                    </label>
                    <div class="text-muted small" style="font-size: 0.78rem;">Residents can pay via zonal downpayment and subsequent scheduled milestones.</div>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; border-top: 1px solid var(--border-color); padding-top: 1.25rem;">
                <button type="button" onclick="document.getElementById('chargeModal').style.display='none'" class="btn btn-outline-secondary" style="font-weight: 600; padding: 0.6rem 1.25rem;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 600; padding: 0.6rem 1.5rem; background: #6b21a8; border-color: #6b21a8;">Save Charge</button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================
     EDIT ZONAL CHARGE MODAL (SIMPLIFIED & SCROLLABLE)
     Fields: Charge Name, Description, Amount, Plan, Allow Installments
     ========================================== -->
<div id="editChargeModal" class="custom-modal-backdrop" style="display:none; position: fixed; inset: 0; z-index: 1050; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(6px); overflow-y: auto; align-items: flex-start; justify-content: center; padding: 2.5rem 1rem;">
    <div class="modal-content mature-card" style="max-width: 500px; width: 100%; margin: auto; max-height: calc(100vh - 4.5rem); overflow-y: auto; border-radius: 0.85rem; border: 1px solid var(--border-color); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); padding: 1.5rem; padding-bottom: 2.5rem;">
        <div class="modal-header" style="border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; margin-bottom: 1.25rem;">
            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <div style="width: 36px; height: 36px; border-radius: 0.45rem; background: rgba(126, 34, 206, 0.1); color: #7e22ce; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                    <i class="fa-solid fa-pen-to-square"></i>
                </div>
                <div>
                    <h2 style="margin: 0; font-size: 1.15rem; font-weight: 700; color: var(--text-color);">Edit Charge</h2>
                    <p style="margin: 0.15rem 0 0; font-size: 0.78rem; color: var(--text-muted);" id="edit_charge_code_badge">ZN-CHG-0000</p>
                </div>
            </div>
            <button type="button" onclick="document.getElementById('editChargeModal').style.display='none'" style="background: none; border: none; font-size: 1.15rem; color: var(--text-muted); cursor: pointer;"><i class="fa-solid fa-times"></i></button>
        </div>
        
        <form method="POST">
            <input type="hidden" name="edit_charge" value="1">
            <input type="hidden" name="charge_id" id="edit_charge_id">
            
            <!-- 1. Charge Name -->
            <div style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom:0.35rem; font-size: 0.8rem; font-weight: 700; color: var(--text-color); text-transform: uppercase; letter-spacing: 0.03em;">Charge Name <span class="text-danger">*</span></label>
                <input type="text" name="name" id="edit_name" placeholder="e.g. Security Levy" required class="form-control" style="border-radius: 0.5rem; font-size: 0.95rem; font-weight: 600;">
            </div>

            <!-- 2. Description -->
            <div style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom:0.35rem; font-size: 0.8rem; font-weight: 700; color: var(--text-color); text-transform: uppercase; letter-spacing: 0.03em;">Description</label>
                <textarea name="description" id="edit_description" rows="2" class="form-control" style="border-radius: 0.5rem; font-size: 0.88rem;"></textarea>
            </div>

            <!-- 3. Amount -->
            <div style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom:0.35rem; font-size: 0.8rem; font-weight: 700; color: var(--text-color); text-transform: uppercase; letter-spacing: 0.03em;">Amount (₦) <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text" style="background: #f8fafc; border-color: #cbd5e1; font-weight: 800; color: #334155; border-radius: 0.5rem 0 0 0.5rem; font-size: 1rem;">₦</span>
                    <input type="number" step="0.01" min="0" name="amount" id="edit_amount" required class="form-control" style="border-radius: 0 0.5rem 0.5rem 0; font-size: 1.05rem; font-weight: 700; border-color: #cbd5e1;">
                </div>
            </div>

            <!-- 4. Plan (either monthly, etc.) -->
            <div style="margin-bottom: 1.25rem;">
                <label style="display:block; margin-bottom:0.35rem; font-size: 0.8rem; font-weight: 700; color: var(--text-color); text-transform: uppercase; letter-spacing: 0.03em;">Plan (e.g. Monthly, Quarterly, Yearly) <span class="text-danger">*</span></label>
                <select name="plan" id="edit_plan" required class="form-control no-search" style="border-radius: 0.5rem; font-size: 0.92rem; font-weight: 600;">
                    <?php foreach ($active_frequencies as $af): ?>
                        <option value="<?= htmlspecialchars($af['name']) ?>"><?= htmlspecialchars($af['name']) ?><?= $af['interval_days'] > 0 ? " (Every {$af['interval_days']} Days)" : " (One-Time)" ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 5. Allow Installments -->
            <div class="p-2.5 rounded border mb-3" style="background: rgba(126, 34, 206, 0.05); border-color: rgba(126, 34, 206, 0.2) !important;">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="allow_installments" id="edit_allow_installments" value="1" style="cursor: pointer;">
                    <label class="form-check-label fw-bold" for="edit_allow_installments" style="cursor: pointer; font-size: 0.88rem;">
                        Allow Installment Payments for this Charge
                    </label>
                    <div class="text-muted small" style="font-size: 0.78rem;">Residents can pay downpayment and subsequent scheduled installments for invoices of this levy.</div>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; border-top: 1px solid var(--border-color); padding-top: 1.25rem;">
                <button type="button" onclick="document.getElementById('editChargeModal').style.display='none'" class="btn btn-outline-secondary" style="font-weight: 600; padding: 0.6rem 1.25rem;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 600; padding: 0.6rem 1.5rem; background: #6b21a8; border-color: #6b21a8;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
// Filter by frequency, scope, or active status
let currentChargeFilter = 'all';

function setChargeFrequencyFilter(filterVal, btn) {
    currentChargeFilter = filterVal;
    const filterBtns = btn.closest('.futuristic-filter-bar').querySelectorAll('.filter-btn-pill');
    filterBtns.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    filterChargesTable();
}

function filterChargesTable() {
    const searchInput = document.getElementById('chargeSearchInput');
    const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
    const rows = document.querySelectorAll('#chargesTableBody .charge-row');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const rowFreq = row.getAttribute('data-frequency') || '';
        const rowStatus = row.getAttribute('data-status') || '';
        const rowScope = row.getAttribute('data-scope') || '';
        const rowSearch = row.getAttribute('data-search') || '';
        
        let matchesFilter = true;
        if (currentChargeFilter === 'all') {
            matchesFilter = true;
        } else if (currentChargeFilter === 'active') {
            matchesFilter = (rowStatus === 'active');
        } else if (currentChargeFilter === 'local') {
            matchesFilter = (rowScope === 'local');
        } else {
            matchesFilter = (rowFreq === currentChargeFilter);
        }
        
        const matchesSearch = !query || rowSearch.includes(query);
        
        if (matchesFilter && matchesSearch) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
    const counterEl = document.getElementById('visibleChargesCount');
    if (counterEl) {
        counterEl.innerText = visibleCount;
    }
}

function openCreateChargeModal() {
    document.getElementById('chargeModal').style.display = 'flex';
}

function openEditChargeModal(charge) {
    document.getElementById('edit_charge_id').value = charge.id;
    document.getElementById('edit_charge_code_badge').innerText = 'ZN-CHG-' + String(charge.id).padStart(4, '0');
    
    document.getElementById('edit_name').value = charge.name || charge.charge_type || '';
    document.getElementById('edit_description').value = charge.description || '';
    document.getElementById('edit_amount').value = charge.amount || '0.00';
    document.getElementById('edit_plan').value = charge.frequency || 'Monthly';
    if (document.getElementById('edit_allow_installments')) {
        document.getElementById('edit_allow_installments').checked = (charge.allow_installments == 1 || charge.allow_installments === undefined);
    }
    
    document.getElementById('editChargeModal').style.display = 'flex';
}

// Export Charges Table to CSV
function exportChargesCSV() {
    const rows = document.querySelectorAll('#chargesTable tr');
    let csv = [];
    
    rows.forEach(row => {
        if (row.style.display === 'none') return;
        const cols = row.querySelectorAll('th, td');
        let rowData = [];
        // Export first 8 columns (exclude Action buttons)
        for (let i = 0; i < Math.min(cols.length, 8); i++) {
            let text = cols[i].innerText.replace(/(\r\n|\n|\r)/gm, ' ').replace(/\s+/g, ' ').trim();
            text = text.replace(/"/g, '""');
            rowData.push('"' + text + '"');
        }
        if (rowData.length > 0) {
            csv.push(rowData.join(','));
        }
    });
    
    const csvContent = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv.join('\n'));
    const link = document.createElement('a');
    link.setAttribute('href', csvContent);
    link.setAttribute('download', 'zone_charges_catalog_' + new Date().toISOString().slice(0, 10) + '.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php include 'footer.php'; ?>
