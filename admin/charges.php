<?php
// admin/charges.php - Central Charges & Tariffs Catalog
require_once '../config.php';
require_once '../includes/auth_guard.php';

requireAdminAccess();
if (!hasPermission('charges.manage') && !in_array($_SESSION['role'] ?? '', ['superadmin', 'admin'])) {
    header("Location: index?error=unauthorized");
    exit;
}

$estate_id = get_estate_id();
$message = $flash_message ?? "";
$error = $flash_error ?? "";

// Fetch Dynamic Active Frequencies
$freq_res = $conn->query("
    SELECT name, interval_days 
    FROM zonal_billing_frequencies 
    WHERE status = 'Active' AND (zone_id IS NULL OR estate_id = $estate_id) 
    ORDER BY sort_order ASC, name ASC
");
$available_frequencies = [];
if ($freq_res) {
    while ($f = $freq_res->fetch_assoc()) {
        $available_frequencies[] = $f['name'];
    }
}
if (empty($available_frequencies)) {
    $available_frequencies = ['Monthly', 'Bi-Monthly', 'Quarterly', 'Semi-Annually', 'Yearly', 'One-Time'];
}

// Fetch Zones for Scoping
$zones_res = $conn->query("SELECT id, name, code FROM zones WHERE estate_id = $estate_id AND status = 'Active' ORDER BY name ASC");
$zones_list = [];
if ($zones_res) {
    while ($z = $zones_res->fetch_assoc()) {
        $zones_list[] = $z;
    }
}

// Handle Add / Edit / Toggle / Delete Charge (PRG Protected)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 1. ADD CHARGE
    if (isset($_POST['add_charge'])) {
        $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $desc = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $amount = floatval($_POST['amount'] ?? 0);
        $frequency = $conn->real_escape_string(trim($_POST['frequency'] ?? 'Yearly'));
        $due_day = intval($_POST['due_day'] ?? 1);
        $allow_installments = isset($_POST['allow_installments']) ? 1 : 0;
        $charge_scope = $_POST['zone_id'] ?? '';
        $zone_id_val = (!empty($charge_scope) && $charge_scope !== 'all') ? intval($charge_scope) : "NULL";
        $user_id = $_SESSION['user_id'] ?? null;
        
        if (!empty($name) && $amount >= 0) {
            $sql = "INSERT INTO estate_charges (estate_id, zone_id, name, description, amount, frequency, due_day, allow_installments, status, created_by) 
                    VALUES ($estate_id, $zone_id_val, '$name', '$desc', $amount, '$frequency', $due_day, $allow_installments, 'Active', " . ($user_id ? $user_id : "NULL") . ")";
            if ($conn->query($sql)) {
                logAudit($conn, "Charge Created", "Finance", "Created central charge: '$name' (₦$amount, $frequency, Installments: " . ($allow_installments ? "Yes" : "No") . ")");
                redirectWithFlash('charges', "Estate charge '$name' created successfully!");
            } else {
                redirectWithFlash('charges', null, "Error creating charge: " . $conn->error);
            }
        } else {
            redirectWithFlash('charges', null, "Please provide a valid charge name and non-negative amount.");
        }
    }

    // 2. EDIT CHARGE
    elseif (isset($_POST['edit_charge'])) {
        $id = intval($_POST['charge_id']);
        $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $desc = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $amount = floatval($_POST['amount'] ?? 0);
        $frequency = $conn->real_escape_string(trim($_POST['frequency'] ?? 'Yearly'));
        $due_day = intval($_POST['due_day'] ?? 1);
        $allow_installments = isset($_POST['allow_installments']) ? 1 : 0;
        $charge_scope = $_POST['zone_id'] ?? '';
        $zone_id_val = (!empty($charge_scope) && $charge_scope !== 'all') ? intval($charge_scope) : "NULL";

        if (!empty($name) && $amount >= 0) {
            $sql = "UPDATE estate_charges 
                    SET name = '$name', description = '$desc', amount = $amount, frequency = '$frequency', 
                        due_day = $due_day, allow_installments = $allow_installments, zone_id = $zone_id_val 
                    WHERE id = $id AND estate_id = $estate_id";
            if ($conn->query($sql)) {
                logAudit($conn, "Charge Updated", "Finance", "Updated charge #$id: '$name' (₦$amount, $frequency)");
                redirectWithFlash('charges', "Estate charge '$name' updated successfully!");
            } else {
                redirectWithFlash('charges', null, "Error updating charge: " . $conn->error);
            }
        } else {
            redirectWithFlash('charges', null, "Please provide valid charge details.");
        }
    }

    // 3. TOGGLE CHARGE STATUS
    elseif (isset($_POST['toggle_status'])) {
        $id = intval($_POST['charge_id']);
        $curr_status = $_POST['status'] ?? 'Active';
        $new_status = ($curr_status === 'Active') ? 'Inactive' : 'Active';
        $conn->query("UPDATE estate_charges SET status = '$new_status' WHERE id = $id AND estate_id = $estate_id");
        logAudit($conn, "Charge Status Updated", "Finance", "Updated charge #$id status to $new_status");
        redirectWithFlash('charges', "Charge status updated to $new_status.");
    }

    // 4. DELETE CHARGE
    elseif (isset($_POST['delete_charge'])) {
        $id = intval($_POST['id']);
        if ($conn->query("DELETE FROM estate_charges WHERE id = $id AND estate_id = $estate_id")) {
            logAudit($conn, "Charge Deleted", "Finance", "Deleted charge #$id");
            redirectWithFlash('charges', "Estate charge removed from catalog.");
        } else {
            redirectWithFlash('charges', null, "Error deleting charge: " . $conn->error);
        }
    }
}

// Fetch Summary Statistics for KPI Ribbon
$stats_row = $conn->query("
    SELECT 
        COUNT(*) as total_charges,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_charges,
        SUM(CASE WHEN allow_installments = 1 AND status = 'Active' THEN 1 ELSE 0 END) as installment_charges,
        SUM(CASE WHEN frequency IN ('Monthly', 'Yearly', 'Quarterly') AND status = 'Active' THEN 1 ELSE 0 END) as recurring_charges,
        AVG(CASE WHEN status = 'Active' THEN amount ELSE NULL END) as avg_amount
    FROM estate_charges 
    WHERE estate_id = $estate_id
")->fetch_assoc();

$total_charges = (int)($stats_row['total_charges'] ?? 0);
$active_charges = (int)($stats_row['active_charges'] ?? 0);
$installment_charges = (int)($stats_row['installment_charges'] ?? 0);
$recurring_charges = (int)($stats_row['recurring_charges'] ?? 0);
$avg_charge_amount = (float)($stats_row['avg_amount'] ?? 0);

// Fetch Charges with Zone Names
$charges_res = $conn->query("
    SELECT c.*, z.name as zone_name, z.code as zone_code 
    FROM estate_charges c 
    LEFT JOIN zones z ON c.zone_id = z.id 
    WHERE c.estate_id = $estate_id 
    ORDER BY c.status ASC, c.name ASC
");

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="page-header-futuristic mb-4">
    <div>
        <div class="header-breadcrumbs">
            <span>Treasury & Billing</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <a href="finance" style="color: var(--text-muted); text-decoration: none;">Finance</a>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span class="active">Charge Catalog</span>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap mt-1">
            <h1 class="page-title m-0">Standardized Charges Catalog</h1>
            <span class="mature-badge mature-badge-sky"><i class="fa-solid fa-tags me-1"></i> Fee Tariffs</span>
            <span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-chart-pie me-1"></i> <?= $installment_charges ?> Installment-Ready</span>
        </div>
        <p class="page-subtitle">Standardized fee tariffs, recurring dues, installment availability controls, and automated assessment rules.</p>
    </div>
    <div class="header-actions">
        <a href="billing_config" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:0.5rem; font-weight:600; padding:0.6rem 1.1rem; border-radius:0.5rem; border:1px solid var(--border-color); background:var(--card-bg); color:var(--text-color); text-decoration:none;">
            <i class="fa-solid fa-sliders text-primary"></i> Billing & Installment Policy
        </a>
        <a href="finance" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:0.5rem; font-weight:600; padding:0.6rem 1.1rem; border-radius:0.5rem; border:1px solid var(--border-color); background:var(--card-bg); color:var(--text-color); text-decoration:none;">
            <i class="fa-solid fa-arrow-left"></i> Treasury Overview
        </a>
        <button onclick="document.getElementById('chargeModal').style.display='flex'" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:0.5rem; font-weight:600; padding:0.6rem 1.25rem; border-radius:0.5rem;">
            <i class="fa-solid fa-plus"></i> Add New Charge
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
    <!-- Pillar 1: Total Catalog Items -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Catalog Tariffs</span>
                    <div class="kpi-value"><?= number_format($total_charges) ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-tags"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Standardized Catalog</span>
                <span class="mature-badge mature-badge-slate"><i class="fa-solid fa-layer-group me-1"></i> Catalog</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: 100%;"></div>
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
                <div class="kpi-icon-wrap">
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

    <!-- Pillar 3: Installment Enabled Tariffs -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Installment-Enabled</span>
                    <div class="kpi-value"><?= number_format($installment_charges) ?></div>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(16, 185, 129, 0.1); color: #059669;">
                    <i class="fa-solid fa-chart-pie"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Flexible Milestone Ready</span>
                <span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-check me-1"></i> Enabled</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?= $total_charges > 0 ? round(($installment_charges / $total_charges) * 100) : 0 ?>%; background: #059669;"></div>
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
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-calculator"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Benchmark Per Invoicing Line</span>
                <span class="mature-badge mature-badge-amber">Tariff Avg</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: 70%;"></div>
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
                <i class="fa-solid fa-list-ul me-1"></i> All (<?= $total_charges ?>)
            </button>
            <button class="filter-btn-pill" onclick="setChargeFrequencyFilter('installment', this)">
                <i class="fa-solid fa-chart-pie me-1"></i> Installments (<?= $installment_charges ?>)
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
            <input type="text" id="chargeSearchInput" class="form-control ps-5" placeholder="Search charge name, plan, rate, scope..." onkeyup="filterChargesTable()">
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
                <i class="fa-solid fa-tags text-secondary"></i> Standardized Estate Charges & Fee Tariffs
            </h2>
            <p style="margin: 0.25rem 0 0; font-size: 0.8rem; color: var(--text-muted);">Configured baseline charges with dynamic billing frequencies and milestone installment policies.</p>
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
                        <th>Charge Title & Description</th>
                        <th>Scope</th>
                        <th>Standard Rate</th>
                        <th>Billing Cycle</th>
                        <th>Installments</th>
                        <th>Due Day</th>
                        <th>Status</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody id="chargesTableBody">
                    <?php if ($charges_res && $charges_res->num_rows > 0): ?>
                        <?php while($row = $charges_res->fetch_assoc()): ?>
                        <?php 
                            $charge_code = 'CHG-' . sprintf("%04d", $row['id']);
                            $freq_val = strtolower($row['frequency'] ?? 'yearly');
                            $status_val = strtolower($row['status'] ?? 'active');
                            $has_inst = intval($row['allow_installments'] ?? 1) === 1 ? '1' : '0';
                            $scope_display = $row['zone_id'] ? ("Zone " . ($row['zone_code'] ?? $row['zone_name'])) : "Universal Estate";
                            $search_meta = strtolower($charge_code . ' ' . $row['name'] . ' ' . ($row['description'] ?? '') . ' ' . $row['frequency'] . ' ' . $row['amount'] . ' ' . $scope_display);
                        ?>
                        <tr class="charge-row" data-frequency="<?= htmlspecialchars($freq_val) ?>" data-status="<?= htmlspecialchars($status_val) ?>" data-installment="<?= $has_inst ?>" data-search="<?= htmlspecialchars($search_meta) ?>">
                            <td>
                                <span class="id-chip"><?= htmlspecialchars($charge_code) ?></span>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: var(--text-color); font-size: 0.92rem;"><?= htmlspecialchars($row['name']) ?></div>
                                <?php if (!empty($row['description'])): ?>
                                    <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 2px; max-width: 320px; line-height: 1.35;"><?= htmlspecialchars($row['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['zone_id']): ?>
                                    <span class="mature-badge mature-badge-purple" style="font-size: 0.75rem;">
                                        <i class="fa-solid fa-layer-group me-1"></i> <?= htmlspecialchars($scope_display) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="mature-badge mature-badge-sky" style="font-size: 0.75rem;">
                                        <i class="fa-solid fa-globe me-1"></i> Universal
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-weight: 700; color: var(--text-color); font-size: 0.95rem;">₦<?= number_format($row['amount'], 2) ?></span>
                            </td>
                            <td>
                                <span class="mature-badge mature-badge-slate">
                                    <i class="fa-solid fa-calendar-days me-1"></i> <?= htmlspecialchars($row['frequency']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($has_inst === '1'): ?>
                                    <span class="mature-badge mature-badge-emerald">
                                        <i class="fa-solid fa-check me-1"></i> Allowed
                                    </span>
                                <?php else: ?>
                                    <span class="mature-badge mature-badge-slate">
                                        <i class="fa-solid fa-ban me-1"></i> Disabled
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-size: 0.82rem; color: var(--text-muted); font-weight: 500;">
                                    Day <?= intval($row['due_day'] ?? 1) ?>
                                </span>
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
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick='openEditChargeModal(<?= json_encode($row) ?>)' title="Edit Charge Tariff">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="charge_id" value="<?= $row['id'] ?>">
                                        <input type="hidden" name="status" value="<?= $row['status'] ?>">
                                        <button type="submit" name="toggle_status" class="btn btn-sm btn-outline-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.78rem; font-weight: 600; cursor: pointer;">
                                            <?= $row['status'] == 'Active' ? '<i class="fa-solid fa-pause"></i>' : '<i class="fa-solid fa-play"></i>' ?>
                                        </button>
                                    </form>
                                    <form method="POST" onsubmit="return confirm('Delete charge tariff \'<?= htmlspecialchars(addslashes($row['name'])) ?>\'?');" style="display: inline;">
                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                        <button type="submit" name="delete_charge" class="btn btn-sm btn-outline-danger" style="padding: 0.35rem 0.55rem; font-size: 0.78rem; cursor: pointer;" title="Delete Charge Tariff">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="9" style="text-align: center; padding: 3.5rem 1rem; color: var(--text-muted);">No estate charges defined yet. Click "Add New Charge" above to set up your first tariff.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ==========================================
     ADD NEW CHARGE MODAL
     ========================================== -->
<div id="chargeModal" class="custom-modal-backdrop" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15, 23, 42, 0.6); z-index:1000; align-items:flex-start; justify-content:center; backdrop-filter: blur(4px); overflow-y: auto; padding: 2.5rem 1rem;">
    <div class="modal-content mature-card" style="background:#fff; max-width: 520px; width: 100%; margin: auto; border-radius: 0.85rem; border: 1px solid var(--border-color); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); max-height: calc(100vh - 4.5rem); overflow-y: auto; padding: 1.5rem;">
        <div class="d-flex justify-content-between align-items-center pb-2 mb-3 border-bottom">
            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <div style="width: 36px; height: 36px; border-radius: 0.45rem; background: rgba(59, 130, 246, 0.1); color: var(--primary-color); display: flex; align-items: center; justify-content: center; font-size: 1rem;">
                    <i class="fa-solid fa-tags"></i>
                </div>
                <div>
                    <h2 style="margin: 0; font-size: 1.15rem; font-weight: 700; color: var(--text-color);">Create Estate Charge Tariff</h2>
                    <p style="margin: 0.15rem 0 0; font-size: 0.78rem; color: var(--text-muted);">Standardized fee baseline with installment permission control</p>
                </div>
            </div>
            <button type="button" onclick="document.getElementById('chargeModal').style.display='none'" class="btn-close"></button>
        </div>
        
        <form method="POST">
            <div class="mb-3">
                <label class="form-label small fw-bold text-muted text-uppercase">Charge Name / Title <span class="text-danger">*</span></label>
                <input type="text" name="name" placeholder="e.g. Annual Estate Service Charge, Security Levy" required class="form-control">
            </div>

            <div class="mb-3">
                <label class="form-label small fw-bold text-muted text-uppercase">Description / Coverage Scope</label>
                <textarea name="description" rows="2" placeholder="Coverage details (e.g. Street lighting, perimeter security, refuse collection)" class="form-control"></textarea>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-7">
                    <label class="form-label small fw-bold text-muted text-uppercase">Standard Rate (₦) <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text fw-bold">₦</span>
                        <input type="number" step="0.01" name="amount" placeholder="0.00" required class="form-control fw-bold" style="font-size: 1rem;">
                    </div>
                </div>
                <div class="col-5">
                    <label class="form-label small fw-bold text-muted text-uppercase">Due Cycle Day</label>
                    <input type="number" min="1" max="31" name="due_day" value="1" class="form-control">
                </div>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-7">
                    <label class="form-label small fw-bold text-muted text-uppercase">Billing Frequency / Plan <span class="text-danger">*</span></label>
                    <select name="frequency" required class="form-select">
                        <?php foreach ($available_frequencies as $freq_opt): ?>
                            <option value="<?= htmlspecialchars($freq_opt) ?>" <?= $freq_opt === 'Yearly' ? 'selected' : '' ?>>
                                <?= htmlspecialchars($freq_opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-5">
                    <label class="form-label small fw-bold text-muted text-uppercase">Applicable Scope</label>
                    <select name="zone_id" class="form-select">
                        <option value="all" selected>🌐 Universal (All Zones)</option>
                        <?php foreach ($zones_list as $z): ?>
                            <option value="<?= $z['id'] ?>">Zone <?= htmlspecialchars($z['code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Allow Installments Toggle -->
            <div class="p-3 mb-4 rounded-3 border" style="background: rgba(16, 185, 129, 0.04); border-color: rgba(16, 185, 129, 0.25) !important;">
                <div class="form-check form-switch d-flex align-items-center gap-3 ps-0 mb-0">
                    <input class="form-check-input ms-0 me-2" type="checkbox" role="switch" id="add_allow_installments" name="allow_installments" value="1" checked style="width: 2.75rem; height: 1.4rem; cursor: pointer;">
                    <div>
                        <label class="form-check-label fw-bold text-dark d-block" for="add_allow_installments" style="cursor: pointer; font-size: 0.9rem;">
                            Allow Installment Payments
                        </label>
                        <small class="text-muted d-block" style="font-size: 0.75rem;">
                            Permit residents to pay this charge across downpayment & scheduled milestones.
                        </small>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                <button type="button" onclick="document.getElementById('chargeModal').style.display='none'" class="btn btn-outline-secondary">Cancel</button>
                <button type="submit" name="add_charge" class="btn btn-primary fw-semibold px-4">Save Charge</button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================
     EDIT CHARGE MODAL
     ========================================== -->
<div id="editChargeModal" class="custom-modal-backdrop" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15, 23, 42, 0.6); z-index:1000; align-items:flex-start; justify-content:center; backdrop-filter: blur(4px); overflow-y: auto; padding: 2.5rem 1rem;">
    <div class="modal-content mature-card" style="background:#fff; max-width: 520px; width: 100%; margin: auto; border-radius: 0.85rem; border: 1px solid var(--border-color); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); max-height: calc(100vh - 4.5rem); overflow-y: auto; padding: 1.5rem;">
        <div class="d-flex justify-content-between align-items-center pb-2 mb-3 border-bottom">
            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <div style="width: 36px; height: 36px; border-radius: 0.45rem; background: rgba(59, 130, 246, 0.1); color: var(--primary-color); display: flex; align-items: center; justify-content: center; font-size: 1rem;">
                    <i class="fa-solid fa-pen"></i>
                </div>
                <div>
                    <h2 style="margin: 0; font-size: 1.15rem; font-weight: 700; color: var(--text-color);">Edit Estate Charge Tariff</h2>
                    <p style="margin: 0.15rem 0 0; font-size: 0.78rem; color: var(--text-muted);">Update charge rate, billing plan, or installment availability</p>
                </div>
            </div>
            <button type="button" onclick="document.getElementById('editChargeModal').style.display='none'" class="btn-close"></button>
        </div>
        
        <form method="POST">
            <input type="hidden" name="charge_id" id="edit_charge_id">

            <div class="mb-3">
                <label class="form-label small fw-bold text-muted text-uppercase">Charge Name / Title <span class="text-danger">*</span></label>
                <input type="text" name="name" id="edit_name" required class="form-control">
            </div>

            <div class="mb-3">
                <label class="form-label small fw-bold text-muted text-uppercase">Description / Coverage Scope</label>
                <textarea name="description" id="edit_description" rows="2" class="form-control"></textarea>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-7">
                    <label class="form-label small fw-bold text-muted text-uppercase">Standard Rate (₦) <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text fw-bold">₦</span>
                        <input type="number" step="0.01" name="amount" id="edit_amount" required class="form-control fw-bold" style="font-size: 1rem;">
                    </div>
                </div>
                <div class="col-5">
                    <label class="form-label small fw-bold text-muted text-uppercase">Due Cycle Day</label>
                    <input type="number" min="1" max="31" name="due_day" id="edit_due_day" class="form-control">
                </div>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-7">
                    <label class="form-label small fw-bold text-muted text-uppercase">Billing Frequency / Plan <span class="text-danger">*</span></label>
                    <select name="frequency" id="edit_frequency" required class="form-select">
                        <?php foreach ($available_frequencies as $freq_opt): ?>
                            <option value="<?= htmlspecialchars($freq_opt) ?>">
                                <?= htmlspecialchars($freq_opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-5">
                    <label class="form-label small fw-bold text-muted text-uppercase">Applicable Scope</label>
                    <select name="zone_id" id="edit_zone_id" class="form-select">
                        <option value="all">🌐 Universal (All Zones)</option>
                        <?php foreach ($zones_list as $z): ?>
                            <option value="<?= $z['id'] ?>">Zone <?= htmlspecialchars($z['code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Allow Installments Toggle -->
            <div class="p-3 mb-4 rounded-3 border" style="background: rgba(16, 185, 129, 0.04); border-color: rgba(16, 185, 129, 0.25) !important;">
                <div class="form-check form-switch d-flex align-items-center gap-3 ps-0 mb-0">
                    <input class="form-check-input ms-0 me-2" type="checkbox" role="switch" id="edit_allow_installments" name="allow_installments" value="1" style="width: 2.75rem; height: 1.4rem; cursor: pointer;">
                    <div>
                        <label class="form-check-label fw-bold text-dark d-block" for="edit_allow_installments" style="cursor: pointer; font-size: 0.9rem;">
                            Allow Installment Payments
                        </label>
                        <small class="text-muted d-block" style="font-size: 0.75rem;">
                            Permit residents to pay this charge across downpayment & scheduled milestones.
                        </small>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                <button type="button" onclick="document.getElementById('editChargeModal').style.display='none'" class="btn btn-outline-secondary">Cancel</button>
                <button type="submit" name="edit_charge" class="btn btn-primary fw-semibold px-4">Update Charge</button>
            </div>
        </form>
    </div>
</div>

<script>
// Open Edit Charge Modal
function openEditChargeModal(charge) {
    document.getElementById('edit_charge_id').value = charge.id;
    document.getElementById('edit_name').value = charge.name;
    document.getElementById('edit_description').value = charge.description || '';
    document.getElementById('edit_amount').value = parseFloat(charge.amount || 0).toFixed(2);
    document.getElementById('edit_due_day').value = charge.due_day || 1;
    document.getElementById('edit_frequency').value = charge.frequency || 'Yearly';
    document.getElementById('edit_zone_id').value = charge.zone_id ? charge.zone_id : 'all';
    document.getElementById('edit_allow_installments').checked = (parseInt(charge.allow_installments) === 1 || charge.allow_installments === null);
    document.getElementById('editChargeModal').style.display = 'flex';
}

// Filter by frequency or installment or active status
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
        const rowInst = row.getAttribute('data-installment') || '0';
        const rowSearch = row.getAttribute('data-search') || '';
        
        let matchesFilter = true;
        if (currentChargeFilter === 'all') {
            matchesFilter = true;
        } else if (currentChargeFilter === 'active') {
            matchesFilter = (rowStatus === 'active');
        } else if (currentChargeFilter === 'installment') {
            matchesFilter = (rowInst === '1');
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

// Export Charges Table to CSV
function exportChargesCSV() {
    const rows = document.querySelectorAll('#chargesTable tr');
    let csv = [];
    
    rows.forEach(row => {
        if (row.style.display === 'none') return;
        const cols = row.querySelectorAll('th, td');
        let rowData = [];
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
    link.setAttribute('download', 'estate_charges_catalog_' + new Date().toISOString().slice(0, 10) + '.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php include '../includes/footer.php'; ?>
