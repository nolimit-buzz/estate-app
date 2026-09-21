<?php
// zone/billing_config.php - Zonal Billing & Dynamic Installment Configuration Engine
require_once '../config.php';
require_once '../includes/auth_guard.php';

requireZoneAccess();

$zone_id = get_current_zone_id();
$estate_id = get_estate_id();
$message = $flash_message ?? "";
$error = $flash_error ?? "";

// 1. Fetch Zone Details
$zone_res = $conn->query("SELECT * FROM zones WHERE id = $zone_id AND estate_id = $estate_id LIMIT 1");
$zone = ($zone_res && $zone_res->num_rows > 0) ? $zone_res->fetch_assoc() : null;
if (!$zone) {
    die("<div style='font-family:sans-serif;padding:3rem;text-align:center;'>
            <h2>Invalid Zone Assignment</h2>
            <p>Your account is not assigned to a valid zone.</p>
            <a href='index'>Return to Dashboard</a>
         </div>");
}

// 2. Fetch or initialize Zonal Billing Settings
$settings_res = $conn->query("SELECT * FROM zonal_billing_settings WHERE zone_id = $zone_id LIMIT 1");
if (!$settings_res || $settings_res->num_rows == 0) {
    $conn->query("INSERT INTO zonal_billing_settings 
                  (estate_id, zone_id, allow_installments, min_first_payment_percent, max_subsequent_payments, split_mode, subsequent_percentages, installment_interval_days, allow_custom_amount, instructions) 
                  VALUES ($estate_id, $zone_id, 1, 40.00, 3, 'equal_remainder', '20,20,20', 30, 1, 'Installment payments are enabled for this zone. Initial downpayment is 40% with up to 3 subsequent installments every 30 days.')");
    $settings_res = $conn->query("SELECT * FROM zonal_billing_settings WHERE zone_id = $zone_id LIMIT 1");
}
$billing_settings = $settings_res->fetch_assoc();

// -------------------------------------------------------------
// POST HANDLERS (PRG Protected)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // A. UPDATE INSTALLMENT POLICY
    if (isset($_POST['save_installment_settings'])) {
        $allow_installments = isset($_POST['allow_installments']) ? 1 : 0;
        $min_first_pct = floatval($_POST['min_first_payment_percent'] ?? 40.00);
        $min_first_pct = max(5.00, min(95.00, $min_first_pct));
        $max_subsequent = intval($_POST['max_subsequent_payments'] ?? 3);
        $max_subsequent = max(1, min(12, $max_subsequent));
        $split_mode = in_array($_POST['split_mode'] ?? '', ['equal_remainder', 'custom_percentages', 'flexible']) ? $_POST['split_mode'] : 'equal_remainder';
        $interval_days = intval($_POST['installment_interval_days'] ?? 30);
        $interval_days = max(1, min(365, $interval_days));
        $allow_custom_amount = isset($_POST['allow_custom_amount']) ? 1 : 0;
        $late_penalty = floatval($_POST['late_penalty_percent'] ?? 0.00);
        $grace_period = intval($_POST['grace_period_days'] ?? 7);
        $instructions = $conn->real_escape_string(trim($_POST['instructions'] ?? ''));

        // Handle subsequent percentage schedule
        $subsequent_percentages = '';
        if ($split_mode === 'custom_percentages' && !empty($_POST['custom_subsequent_pct'])) {
            $raw_parts = explode(',', $_POST['custom_subsequent_pct']);
            $clean_parts = [];
            foreach ($raw_parts as $p) {
                $val = floatval(trim($p));
                if ($val > 0) $clean_parts[] = $val;
            }
            $subsequent_percentages = implode(',', $clean_parts);
        } else {
            // Calculate equal remainder automatically
            $remainder = 100.00 - $min_first_pct;
            $each_pct = round($remainder / $max_subsequent, 2);
            $parts = [];
            for ($i = 0; $i < $max_subsequent; $i++) {
                $parts[] = $each_pct;
            }
            $subsequent_percentages = implode(',', $parts);
        }
        $subsequent_percentages_sql = $conn->real_escape_string($subsequent_percentages);

        $upd_sql = "UPDATE zonal_billing_settings SET 
                    allow_installments = $allow_installments,
                    min_first_payment_percent = $min_first_pct,
                    max_subsequent_payments = $max_subsequent,
                    split_mode = '$split_mode',
                    subsequent_percentages = '$subsequent_percentages_sql',
                    installment_interval_days = $interval_days,
                    allow_custom_amount = $allow_custom_amount,
                    late_penalty_percent = $late_penalty,
                    grace_period_days = $grace_period,
                    instructions = '$instructions'
                    WHERE zone_id = $zone_id";

        if ($conn->query($upd_sql)) {
            logAudit($conn, "Zonal Billing Config Updated", "Finance", "Updated installment payment rules for Zone #$zone_id: 1st Pay {$min_first_pct}%, {$max_subsequent} subsequent times");
            redirectWithFlash('billing_config', "Zonal installment payment policy successfully updated!");
        } else {
            redirectWithFlash('billing_config', null, "Failed to update installment policy: " . $conn->error);
        }
    }

    // B. ADD NEW BILLING FREQUENCY
    elseif (isset($_POST['add_frequency'])) {
        $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $code = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_POST['code'] ?? '')));
        if (empty($code)) $code = strtolower(str_replace(' ', '-', $name));
        $code = $conn->real_escape_string($code);
        $interval_days = intval($_POST['interval_days'] ?? 30);
        $desc = $conn->real_escape_string(trim($_POST['description'] ?? ''));

        if (!empty($name)) {
            // Check for duplicate frequency name in this zone or globally
            $chk = $conn->query("SELECT id FROM zonal_billing_frequencies WHERE name = '$name' AND (zone_id = $zone_id OR zone_id IS NULL) LIMIT 1");
            if ($chk && $chk->num_rows > 0) {
                redirectWithFlash('billing_config', null, "A billing frequency named '$name' already exists.");
            }

            $sql = "INSERT INTO zonal_billing_frequencies (estate_id, zone_id, name, code, interval_days, description, status) 
                    VALUES ($estate_id, $zone_id, '$name', '$code', $interval_days, '$desc', 'Active')";
            if ($conn->query($sql)) {
                logAudit($conn, "Frequency Added", "Finance", "Added custom billing frequency '$name' ($interval_days days) in Zone #$zone_id");
                redirectWithFlash('billing_config', "Billing frequency '$name' successfully added to your zone catalog!");
            } else {
                redirectWithFlash('billing_config', null, "Failed to add frequency: " . $conn->error);
            }
        } else {
            redirectWithFlash('billing_config', null, "Please provide a valid frequency name.");
        }
    }

    // C. EDIT BILLING FREQUENCY
    elseif (isset($_POST['edit_frequency'])) {
        $freq_id = intval($_POST['frequency_id']);
        $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
        $interval_days = intval($_POST['interval_days'] ?? 30);
        $desc = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $status = in_array($_POST['status'] ?? '', ['Active', 'Inactive']) ? $_POST['status'] : 'Active';

        if (!empty($name)) {
            $chk = $conn->query("SELECT id, zone_id FROM zonal_billing_frequencies WHERE id = $freq_id LIMIT 1");
            if ($chk && $chk->num_rows > 0) {
                $row = $chk->fetch_assoc();
                if ($row['zone_id'] == $zone_id) {
                    $conn->query("UPDATE zonal_billing_frequencies 
                                  SET name = '$name', interval_days = $interval_days, description = '$desc', status = '$status' 
                                  WHERE id = $freq_id AND zone_id = $zone_id");
                } else {
                    $code = strtolower(str_replace(' ', '-', $name));
                    $conn->query("INSERT INTO zonal_billing_frequencies (estate_id, zone_id, name, code, interval_days, description, status) 
                                  VALUES ($estate_id, $zone_id, '$name', '$code', $interval_days, '$desc', '$status')");
                }
                logAudit($conn, "Frequency Updated", "Finance", "Updated billing frequency #$freq_id ('$name') in Zone #$zone_id");
                redirectWithFlash('billing_config', "Billing frequency '$name' updated successfully!");
            }
        }
        redirectWithFlash('billing_config', null, "Invalid frequency update request.");
    }

    // D. TOGGLE FREQUENCY STATUS
    elseif (isset($_POST['toggle_frequency'])) {
        $freq_id = intval($_POST['frequency_id']);
        $res = $conn->query("SELECT * FROM zonal_billing_frequencies WHERE id = $freq_id LIMIT 1");
        if ($res && $res->num_rows > 0) {
            $f = $res->fetch_assoc();
            $new_status = ($f['status'] === 'Active') ? 'Inactive' : 'Active';
            if ($f['zone_id'] == $zone_id) {
                $conn->query("UPDATE zonal_billing_frequencies SET status = '$new_status' WHERE id = $freq_id AND zone_id = $zone_id");
            } else {
                $conn->query("INSERT INTO zonal_billing_frequencies (estate_id, zone_id, name, code, interval_days, description, status) 
                              VALUES ($estate_id, $zone_id, '{$f['name']}', '{$f['code']}', {$f['interval_days']}, '{$f['description']}', '$new_status')");
            }
            logAudit($conn, "Frequency Toggled", "Finance", "Toggled frequency '{$f['name']}' to $new_status in Zone #$zone_id");
            redirectWithFlash('billing_config', "Frequency '{$f['name']}' is now $new_status.");
        }
        redirectWithFlash('billing_config');
    }

    // E. DELETE FREQUENCY
    elseif (isset($_POST['delete_frequency'])) {
        $freq_id = intval($_POST['frequency_id']);
        if ($conn->query("DELETE FROM zonal_billing_frequencies WHERE id = $freq_id AND zone_id = $zone_id")) {
            logAudit($conn, "Frequency Deleted", "Finance", "Deleted custom frequency #$freq_id in Zone #$zone_id");
            redirectWithFlash('billing_config', "Zonal frequency deleted successfully.");
        } else {
            redirectWithFlash('billing_config', null, "Cannot delete standard frequency or system frequency.");
        }
    }
}

// -------------------------------------------------------------
// LOAD BILLING FREQUENCIES CATALOG
// -------------------------------------------------------------
$freq_query = "
    SELECT f.*, (CASE WHEN f.zone_id = $zone_id THEN 'Local Zone' ELSE 'System Standard' END) as scope_label
    FROM zonal_billing_frequencies f
    WHERE (f.zone_id = $zone_id OR (f.zone_id IS NULL AND f.name NOT IN (SELECT name FROM zonal_billing_frequencies WHERE zone_id = $zone_id)))
    ORDER BY f.sort_order ASC, f.name ASC
";
$frequencies = [];
$freq_res = $conn->query($freq_query);
if ($freq_res) {
    while ($r = $freq_res->fetch_assoc()) {
        $frequencies[] = $r;
    }
}

$active_freq_count = count(array_filter($frequencies, fn($f) => $f['status'] === 'Active'));

include 'header.php';
include 'sidebar.php';
?>

<!-- ==========================================
     EXECUTIVE HEADER & BREADCRUMB TOOLBAR
     ========================================== -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4 pb-2 border-bottom border-light-subtle">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
            <h1 class="h4 font-bold text-slate-900 m-0" style="letter-spacing: -0.02em;">
                Zonal Billing & Installment Policy
            </h1>
            <span class="mature-badge mature-badge-purple">
                <i class="fa-solid fa-sliders me-1"></i> Zone <?php echo htmlspecialchars($zone['code']); ?> Config
            </span>
            <span class="mature-badge <?php echo $billing_settings['allow_installments'] ? 'mature-badge-emerald' : 'mature-badge-slate'; ?>">
                <i class="fa-solid <?php echo $billing_settings['allow_installments'] ? 'fa-check' : 'fa-ban'; ?> me-1"></i>
                Installments <?php echo $billing_settings['allow_installments'] ? 'Enabled' : 'Disabled'; ?>
            </span>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2 text-secondary small">
            <span><a href="index" class="text-secondary text-decoration-none"><i class="fa-solid fa-house me-1"></i> Zone Console</a></span>
            <span>/</span>
            <span><a href="charges" class="text-secondary text-decoration-none">Local Levies</a></span>
            <span>/</span>
            <span class="text-slate-800 fw-medium">Billing Configuration</span>
            <span>•</span>
            <span>Sector: <strong><?php echo htmlspecialchars($zone['name']); ?></strong></span>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="charges" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i> Local Levies
        </a>
        <a href="reports" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-chart-pie me-1"></i> Zonal Reports
        </a>
        <a href="finance" class="btn btn-sm text-white" style="background: #7e22ce; border-color: #7e22ce;">
            <i class="fa-solid fa-file-invoice-dollar me-1"></i> Invoices
        </a>
    </div>
</div>

<!-- ==========================================
     ALERT NOTIFICATIONS
     ========================================== -->
<?php if ($message): ?>
    <div class="alert mature-card p-3 mb-4 border-0" style="background: #f0fdf4; border-left: 4px solid #16a34a !important;">
        <div class="d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2 text-success">
                <i class="fa-solid fa-circle-check fs-5"></i>
                <span class="fw-semibold small text-slate-800"><?= $message ?></span>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert mature-card p-3 mb-4 border-0" style="background: #fef2f2; border-left: 4px solid #dc2626 !important;">
        <div class="d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2 text-danger">
                <i class="fa-solid fa-triangle-exclamation fs-5"></i>
                <span class="fw-semibold small text-slate-800"><?= $error ?></span>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
<?php endif; ?>

<!-- ==========================================
     EXECUTIVE 3-METRIC KPI RIBBON
     ========================================== -->
<div class="row g-3 mb-4">
    <!-- Pillar 1: Installment Rules -->
    <div class="col-12 col-md-4">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Installment Engine</span>
                    <div class="kpi-value"><?= number_format($billing_settings['min_first_payment_percent'], 0) ?>% <span style="font-size: 0.95rem; font-weight: 500; color: #64748b;">Initial</span></div>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(126, 34, 206, 0.08); color: #7e22ce; border-color: rgba(126, 34, 206, 0.2);">
                    <i class="fa-solid fa-chart-pie"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>+ <strong><?= intval($billing_settings['max_subsequent_payments']) ?></strong> subsequent installments</span>
                <span class="mature-badge <?= $billing_settings['allow_installments'] ? 'mature-badge-emerald' : 'mature-badge-slate' ?>">
                    <?= $billing_settings['allow_installments'] ? 'Active' : 'Disabled' ?>
                </span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?= floatval($billing_settings['min_first_payment_percent']) ?>%; background: #7e22ce;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 2: Frequency Catalog -->
    <div class="col-12 col-md-4">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Billing Frequencies</span>
                    <div class="kpi-value"><?= count($frequencies) ?> <span style="font-size: 0.95rem; font-weight: 500; color: #64748b;">Cycles</span></div>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(59, 130, 246, 0.08); color: #2563eb; border-color: rgba(59, 130, 246, 0.2);">
                    <i class="fa-solid fa-calendar-days"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Active in Catalog: <strong><?= $active_freq_count ?></strong></span>
                <span class="mature-badge mature-badge-primary">Custom Cadence</span>
            </div>
            <div class="kpi-progress-bar">
                <?php $freq_pct = count($frequencies) > 0 ? round(($active_freq_count / count($frequencies)) * 100) : 0; ?>
                <div class="kpi-progress-fill" style="width: <?= $freq_pct ?>%; background: #3b82f6;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 3: Spacing & Cadence -->
    <div class="col-12 col-md-4">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Payment Spacing</span>
                    <div class="kpi-value"><?= intval($billing_settings['installment_interval_days']) ?> <span style="font-size: 0.95rem; font-weight: 500; color: #64748b;">Days</span></div>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(245, 158, 11, 0.08); color: #d97706; border-color: rgba(245, 158, 11, 0.2);">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Grace Period: <strong><?= intval($billing_settings['grace_period_days']) ?> days</strong></span>
                <span class="mature-badge <?= $billing_settings['allow_custom_amount'] ? 'mature-badge-emerald' : 'mature-badge-slate' ?>">
                    <?= $billing_settings['allow_custom_amount'] ? 'Overpay OK' : 'Strict' ?>
                </span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?= min(100, intval($billing_settings['installment_interval_days']) * 2) ?>%; background: #d97706;"></div>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     MODERN NAVIGATION PILL TABS
     ========================================== -->
<div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
    <ul class="nav nav-pills gap-2" id="configTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-semibold d-flex align-items-center gap-2 rounded-3 px-3 py-2" id="installments-tab" data-bs-toggle="pill" data-bs-target="#tab-installments" type="button" role="tab">
                <i class="fa-solid fa-sliders"></i>
                <span>Installment Policy & Live Simulator</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-semibold d-flex align-items-center gap-2 rounded-3 px-3 py-2" id="frequencies-tab" data-bs-toggle="pill" data-bs-target="#tab-frequencies" type="button" role="tab">
                <i class="fa-solid fa-calendar-days"></i>
                <span>Billing Frequencies Catalog (<?= count($frequencies) ?>)</span>
            </button>
        </li>
    </ul>
</div>

<!-- ==========================================
     TAB CONTENTS
     ========================================== -->
<div class="tab-content" id="configTabsContent">

    <!-- =================================================================== -->
    <!-- TAB 1: INSTALLMENT BREAKDOWN POLICY & SIMULATOR                     -->
    <!-- =================================================================== -->
    <div class="tab-pane fade show active" id="tab-installments" role="tabpanel">
        <div class="row g-4">
            
            <!-- Left Column: Installment Rules Form -->
            <div class="col-12 col-lg-7">
                <div class="mature-card">
                    <div class="mature-card-header">
                        <div>
                            <h3 class="mature-card-title">
                                <i class="fa-solid fa-sliders text-secondary"></i> Installment Breakdown Policy
                            </h3>
                            <p class="text-secondary small mb-0">Configure rules for splitting zonal levy invoices into manageable installments</p>
                        </div>
                        <span class="mature-badge mature-badge-purple">
                            Sector Scope: <?= htmlspecialchars($zone['code']) ?>
                        </span>
                    </div>
                    <div class="mature-card-body">
                        <form method="POST" id="installmentForm">
                            <input type="hidden" name="save_installment_settings" value="1">
                            
                            <!-- Toggle Allow Installments -->
                            <div class="form-check form-switch p-3 mb-4 rounded-3 border" style="background: rgba(126, 34, 206, 0.04); border-color: rgba(126, 34, 206, 0.18) !important;">
                                <input class="form-check-input ms-0 me-3" type="checkbox" role="switch" id="allow_installments" name="allow_installments" value="1" <?= $billing_settings['allow_installments'] ? 'checked' : '' ?> style="width: 2.4em; height: 1.25em; cursor: pointer;">
                                <label class="form-check-label fw-bold text-slate-900" for="allow_installments" style="cursor: pointer;">
                                    Enable Installment Payments for Residents
                                    <div class="text-secondary small fw-normal mt-0.5">When enabled, eligible residents in this zone can split invoices into an initial downpayment and subsequent scheduled milestones.</div>
                                </label>
                            </div>

                            <!-- Downpayment Percentage Slider & Input -->
                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label fw-bold small text-uppercase text-secondary mb-0">
                                        Initial Downpayment Percentage (%)
                                    </label>
                                    <span class="mature-badge mature-badge-purple" id="downpaymentBadge">
                                        <?= number_format($billing_settings['min_first_payment_percent'], 0) ?>% Initial
                                    </span>
                                </div>
                                <p class="text-secondary small mb-2">The minimum required percentage a resident must pay as their first installment upon bill generation.</p>
                                <div class="d-flex align-items-center gap-3">
                                    <input type="range" class="form-range flex-grow-1" id="min_first_slider" min="10" max="90" step="5" value="<?= intval($billing_settings['min_first_payment_percent']) ?>" oninput="syncDownpayment(this.value)">
                                    <div class="input-group" style="width: 120px;">
                                        <input type="number" class="form-control fw-bold text-center" id="min_first_input" name="min_first_payment_percent" min="10" max="90" step="1" value="<?= floatval($billing_settings['min_first_payment_percent']) ?>" oninput="syncDownpayment(this.value)" required>
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Number of Subsequent Payment Times & Interval -->
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-uppercase text-secondary">
                                        Subsequent Payment Milestones
                                    </label>
                                    <select class="form-select fw-semibold" id="max_subsequent" name="max_subsequent_payments" onchange="updateSimulators()" required>
                                        <option value="1" <?= $billing_settings['max_subsequent_payments'] == 1 ? 'selected' : '' ?>>1 Subsequent Milestone (2 Payments Total)</option>
                                        <option value="2" <?= $billing_settings['max_subsequent_payments'] == 2 ? 'selected' : '' ?>>2 Subsequent Milestones (3 Payments Total)</option>
                                        <option value="3" <?= $billing_settings['max_subsequent_payments'] == 3 ? 'selected' : '' ?>>3 Subsequent Milestones (4 Payments Total)</option>
                                        <option value="4" <?= $billing_settings['max_subsequent_payments'] == 4 ? 'selected' : '' ?>>4 Subsequent Milestones (5 Payments Total)</option>
                                        <option value="5" <?= $billing_settings['max_subsequent_payments'] == 5 ? 'selected' : '' ?>>5 Subsequent Milestones (6 Payments Total)</option>
                                        <option value="6" <?= $billing_settings['max_subsequent_payments'] == 6 ? 'selected' : '' ?>>6 Subsequent Milestones (7 Payments Total)</option>
                                        <option value="11" <?= $billing_settings['max_subsequent_payments'] == 11 ? 'selected' : '' ?>>11 Subsequent Milestones (12 Monthly Payments)</option>
                                    </select>
                                    <div class="form-text small">How many installments follow the initial downpayment.</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-uppercase text-secondary">
                                        Cadence Interval (Days)
                                    </label>
                                    <select class="form-select fw-semibold" id="interval_days" name="installment_interval_days" onchange="updateSimulators()" required>
                                        <option value="7" <?= $billing_settings['installment_interval_days'] == 7 ? 'selected' : '' ?>>Every 7 Days (Weekly)</option>
                                        <option value="14" <?= $billing_settings['installment_interval_days'] == 14 ? 'selected' : '' ?>>Every 14 Days (Bi-Weekly)</option>
                                        <option value="30" <?= $billing_settings['installment_interval_days'] == 30 ? 'selected' : '' ?>>Every 30 Days (Monthly - Recommended)</option>
                                        <option value="60" <?= $billing_settings['installment_interval_days'] == 60 ? 'selected' : '' ?>>Every 60 Days (Bi-Monthly)</option>
                                        <option value="90" <?= $billing_settings['installment_interval_days'] == 90 ? 'selected' : '' ?>>Every 90 Days (Quarterly)</option>
                                    </select>
                                    <div class="form-text small">Spacing duration between each subsequent installment.</div>
                                </div>
                            </div>

                            <!-- Breakdown Split Mode -->
                            <div class="mb-4">
                                <label class="form-label fw-bold small text-uppercase text-secondary">
                                    Subsequent Breakdown Mode
                                </label>
                                <div class="d-flex flex-column gap-2">
                                    <div class="form-check p-3 rounded-3 border" style="background: #ffffff;">
                                        <input class="form-check-input ms-0 me-2" type="radio" name="split_mode" id="mode_equal" value="equal_remainder" <?= ($billing_settings['split_mode'] === 'equal_remainder') ? 'checked' : '' ?> onchange="toggleCustomSchedule(false)">
                                        <label class="form-check-label fw-semibold text-slate-900" for="mode_equal">
                                            Equal Remainder Split (Recommended)
                                            <div class="text-secondary small fw-normal mt-0.5">Remaining balance (100% - Initial %) is automatically split into equal shares across each subsequent milestone.</div>
                                        </label>
                                    </div>
                                    <div class="form-check p-3 rounded-3 border" style="background: #ffffff;">
                                        <input class="form-check-input ms-0 me-2" type="radio" name="split_mode" id="mode_custom" value="custom_percentages" <?= ($billing_settings['split_mode'] === 'custom_percentages') ? 'checked' : '' ?> onchange="toggleCustomSchedule(true)">
                                        <label class="form-check-label fw-semibold text-slate-900" for="mode_custom">
                                            Custom Percentage Schedule
                                            <div class="text-secondary small fw-normal mt-0.5">Define exact comma-separated percentages for each subsequent installment.</div>
                                        </label>
                                    </div>
                                </div>

                                <!-- Custom Percentage Input Row -->
                                <div id="customScheduleBox" class="mt-3 p-3 rounded-3 border" style="display: <?= ($billing_settings['split_mode'] === 'custom_percentages') ? 'block' : 'none' ?>; background: rgba(59, 130, 246, 0.05); border-color: rgba(59, 130, 246, 0.25) !important;">
                                    <label class="form-label fw-bold small text-primary mb-1">
                                        Comma-Separated Subsequent Percentages:
                                    </label>
                                    <input type="text" class="form-control font-monospace" name="custom_subsequent_pct" id="custom_subsequent_pct" value="<?= htmlspecialchars($billing_settings['subsequent_percentages']) ?>" placeholder="e.g. 20,20,20" oninput="updateSimulators()">
                                    <div class="small text-secondary mt-1">
                                        Example: For 40% initial and 3 subsequent times, enter: <code>20, 20, 20</code> (Total remaining = 60%).
                                    </div>
                                </div>
                            </div>

                            <!-- Flexible Amount & Grace Period Row -->
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <div class="form-check p-3 rounded-3 border h-100" style="background: #ffffff;">
                                        <input class="form-check-input ms-0 me-2" type="checkbox" name="allow_custom_amount" id="allow_custom_amount" value="1" <?= $billing_settings['allow_custom_amount'] ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-semibold text-slate-900 small" for="allow_custom_amount">
                                            Allow Flexible Overpayments
                                            <div class="text-secondary fw-normal mt-0.5">Residents may pay greater than the designated milestone amount to accelerate clearance.</div>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-uppercase text-secondary">
                                        Grace Period (Days)
                                    </label>
                                    <input type="number" class="form-control fw-semibold" name="grace_period_days" value="<?= intval($billing_settings['grace_period_days']) ?>" min="0" max="60">
                                    <div class="form-text small">Days allowed after milestone due date before flagging invoice overdue.</div>
                                </div>
                            </div>

                            <!-- Zonal Resident Notice -->
                            <div class="mb-4">
                                <label class="form-label fw-bold small text-uppercase text-secondary">
                                    Resident Notice / Terms (Displayed at Resident Portal)
                                </label>
                                <textarea class="form-control" name="instructions" rows="2" placeholder="e.g. Installment payments must be completed within 90 days. For queries, contact Zone Office."><?= htmlspecialchars($billing_settings['instructions'] ?? '') ?></textarea>
                            </div>

                            <button type="submit" class="btn text-white w-100 py-2.5 fw-bold" style="background: #7e22ce; border-color: #7e22ce; border-radius: 8px; box-shadow: 0 4px 14px rgba(126, 34, 206, 0.25);">
                                <i class="fa-solid fa-floppy-disk me-1"></i> Save Zonal Billing & Installment Policy
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Right Column: Live Interactive Simulator Card -->
            <div class="col-12 col-lg-5">
                <div class="mature-card sticky-top" style="top: 1.5rem;">
                    <div class="mature-card-header">
                        <div>
                            <h3 class="mature-card-title">
                                <i class="fa-solid fa-calculator text-secondary"></i> Live Installment Simulator
                            </h3>
                            <p class="text-secondary small mb-0">Real-time simulation based on active settings</p>
                        </div>
                        <span class="mature-badge mature-badge-emerald">
                            Interactive Preview
                        </span>
                    </div>
                    <div class="mature-card-body">
                        <!-- Test Bill Amount -->
                        <div class="mb-3 p-3 rounded-3 border" style="background: #f8fafc;">
                            <label class="form-label fw-bold small text-secondary text-uppercase mb-1">
                                Test Sample Bill Amount:
                            </label>
                            <div class="input-group">
                                <span class="input-group-text fw-bold">₦</span>
                                <input type="number" id="sim_total_amount" class="form-control fw-bold fs-5 text-slate-900" value="100000" step="5000" oninput="updateSimulators()">
                            </div>
                        </div>

                        <!-- Milestone Summary -->
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold small text-uppercase text-secondary">Scheduled Milestones</span>
                            <span class="mature-badge mature-badge-purple" id="sim_milestone_summary">4 Payments</span>
                        </div>

                        <!-- Schedule Cards Container -->
                        <div id="sim_schedule_cards" class="d-flex flex-column gap-2 mb-3">
                            <!-- Dynamically generated via JavaScript -->
                        </div>

                        <!-- Total Validation Banner -->
                        <div id="sim_total_banner" class="p-3 rounded-3 d-flex justify-content-between align-items-center text-white" style="background: linear-gradient(135deg, #7e22ce 0%, #581c87 100%);">
                            <div>
                                <div class="small text-white-50 text-uppercase fw-bold" style="font-size: 0.7rem;">Total Repayment</div>
                                <div class="fw-bold fs-5 text-white" id="sim_grand_total">₦100,000.00</div>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-white text-purple fw-bold px-2.5 py-1.5" id="sim_percentage_total" style="color: #7e22ce;">100% Scheduled</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- =================================================================== -->
    <!-- TAB 2: BILLING FREQUENCIES MANAGEMENT                              -->
    <!-- =================================================================== -->
    <div class="tab-pane fade" id="tab-frequencies" role="tabpanel">
        <div class="mature-card">
            <div class="mature-card-header">
                <div>
                    <h3 class="mature-card-title">
                        <i class="fa-solid fa-calendar-days text-secondary"></i> Zonal Billing Frequencies Catalog
                    </h3>
                    <p class="text-secondary small mb-0">Define recurring levy cycles (e.g. Monthly, Quarterly, Seasonal, Ad-Hoc). These are selectable when creating zone charges.</p>
                </div>
                <button class="btn btn-sm text-white px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#addFrequencyModal" style="background: #7e22ce; border-color: #7e22ce; border-radius: 8px;">
                    <i class="fa-solid fa-plus me-1"></i> Add Frequency
                </button>
            </div>
            <div class="mature-card-body p-0">
                <div class="table-responsive">
                    <table class="table dashboard-table align-middle">
                        <thead>
                            <tr>
                                <th>Frequency Title</th>
                                <th>Identifier Code</th>
                                <th>Cadence Interval</th>
                                <th>Catalog Scope</th>
                                <th>Status</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($frequencies as $f): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-slate-900"><?= htmlspecialchars($f['name']) ?></div>
                                        <div class="text-secondary small"><?= htmlspecialchars($f['description'] ?? 'No description') ?></div>
                                    </td>
                                    <td>
                                        <code class="px-2 py-1 bg-light text-slate-800 rounded small fw-bold font-monospace"><?= htmlspecialchars($f['code']) ?></code>
                                    </td>
                                    <td>
                                        <span class="mature-badge mature-badge-primary">
                                            <i class="fa-solid fa-clock me-1"></i>
                                            <?= $f['interval_days'] > 0 ? "Every {$f['interval_days']} Days" : "One-Time / Ad-Hoc" ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($f['scope_label'] === 'Local Zone'): ?>
                                            <span class="mature-badge mature-badge-purple">Local Zone</span>
                                        <?php else: ?>
                                            <span class="mature-badge mature-badge-slate">System Standard</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="mature-badge <?= $f['status'] === 'Active' ? 'mature-badge-emerald' : 'mature-badge-slate' ?>">
                                            <?= htmlspecialchars($f['status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="d-inline-flex gap-1">
                                            <!-- Edit Button -->
                                            <button type="button" class="btn btn-xs btn-sm btn-outline-secondary" onclick='openEditFrequencyModal(<?= json_encode($f) ?>)' title="Edit Frequency">
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </button>
                                            
                                            <!-- Toggle Button -->
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Toggle status for <?= addslashes($f['name']) ?>?');">
                                                <input type="hidden" name="toggle_frequency" value="1">
                                                <input type="hidden" name="frequency_id" value="<?= $f['id'] ?>">
                                                <button type="submit" class="btn btn-xs btn-sm <?= $f['status'] === 'Active' ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $f['status'] === 'Active' ? 'Deactivate' : 'Activate' ?>">
                                                    <i class="fa-solid <?= $f['status'] === 'Active' ? 'fa-ban' : 'fa-check' ?>"></i>
                                                </button>
                                            </form>

                                            <!-- Delete Button (Only local zone custom frequencies) -->
                                            <?php if (!empty($f['zone_id']) && $f['zone_id'] == $zone_id): ?>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to remove <?= addslashes($f['name']) ?>?');">
                                                    <input type="hidden" name="delete_frequency" value="1">
                                                    <input type="hidden" name="frequency_id" value="<?= $f['id'] ?>">
                                                    <button type="submit" class="btn btn-xs btn-sm btn-outline-danger" title="Delete">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- =================================================================== -->
<!-- MODALS: ADD & EDIT FREQUENCIES                                      -->
<!-- =================================================================== -->
<!-- Add Frequency Modal -->
<div class="modal fade" id="addFrequencyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 12px; border: 1px solid #e2e8f0;">
            <div class="modal-header border-bottom py-3">
                <h5 class="modal-title fw-bold text-slate-900"><i class="fa-solid fa-plus text-purple me-2" style="color: #7e22ce;"></i> Add Zonal Billing Frequency</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="add_frequency" value="1">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-secondary">Frequency Title *</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Bi-Weekly, Seasonal Levy, Ad-Hoc" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-secondary">Slug Identifier Code (Optional)</label>
                        <input type="text" name="code" class="form-control font-monospace" placeholder="e.g. bi-weekly">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-secondary">Cadence Interval in Days *</label>
                        <input type="number" name="interval_days" class="form-control fw-bold" value="30" min="0" max="365" required>
                        <div class="form-text small">Enter <code>0</code> for one-time / ad-hoc levies, <code>30</code> for monthly, <code>90</code> for quarterly, etc.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-secondary">Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Brief note about when this frequency applies..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top py-3">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn text-white btn-sm px-4 fw-bold" style="background: #7e22ce; border-color: #7e22ce;">Add Frequency</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Frequency Modal -->
<div class="modal fade" id="editFrequencyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 12px; border: 1px solid #e2e8f0;">
            <div class="modal-header border-bottom py-3">
                <h5 class="modal-title fw-bold text-slate-900"><i class="fa-solid fa-pen-to-square text-purple me-2" style="color: #7e22ce;"></i> Edit Billing Frequency</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="edit_frequency" value="1">
                <input type="hidden" name="frequency_id" id="edit_freq_id">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-secondary">Frequency Title *</label>
                        <input type="text" name="name" id="edit_freq_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-secondary">Cadence Interval in Days *</label>
                        <input type="number" name="interval_days" id="edit_freq_interval" class="form-control fw-bold" min="0" max="365" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-secondary">Status</label>
                        <select name="status" id="edit_freq_status" class="form-select">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-secondary">Description</label>
                        <textarea name="description" id="edit_freq_desc" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top py-3">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn text-white btn-sm px-4 fw-bold" style="background: #7e22ce; border-color: #7e22ce;">Update Frequency</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =================================================================== -->
<!-- JAVASCRIPT: DYNAMIC INTERACTIVE INSTALLMENT SIMULATOR ENGINE       -->
<!-- =================================================================== -->
<script>
function syncDownpayment(val) {
    val = parseFloat(val) || 40;
    val = Math.max(10, Math.min(90, val));
    document.getElementById('min_first_slider').value = val;
    document.getElementById('min_first_input').value = val;
    document.getElementById('downpaymentBadge').innerText = val + '% Initial';
    updateSimulators();
}

function toggleCustomSchedule(isCustom) {
    const box = document.getElementById('customScheduleBox');
    if (box) {
        box.style.display = isCustom ? 'block' : 'none';
    }
    updateSimulators();
}

function updateSimulators() {
    const totalAmount = parseFloat(document.getElementById('sim_total_amount').value) || 100000;
    const firstPct = parseFloat(document.getElementById('min_first_input').value) || 40;
    const subsequentTimes = parseInt(document.getElementById('max_subsequent').value) || 3;
    const intervalDays = parseInt(document.getElementById('interval_days').value) || 30;
    const isCustom = document.getElementById('mode_custom').checked;

    let schedule = [];
    
    // Milestone 1: Initial Downpayment
    const firstAmount = (totalAmount * (firstPct / 100));
    schedule.push({
        num: 1,
        title: "1st Installment (Initial Downpayment)",
        pct: firstPct,
        amount: firstAmount,
        timing: "Due upon invoicing (Immediate)"
    });

    const remainderPct = 100 - firstPct;
    let scheduledRemainderPct = 0;

    if (isCustom) {
        const customInput = document.getElementById('custom_subsequent_pct').value || '';
        const parts = customInput.split(',').map(s => parseFloat(s.trim())).filter(n => !isNaN(n) && n > 0);
        
        for (let i = 0; i < subsequentTimes; i++) {
            const p = (i < parts.length) ? parts[i] : (remainderPct / subsequentTimes);
            scheduledRemainderPct += p;
            schedule.push({
                num: i + 2,
                title: `${i + 2}${getOrdinalSuffix(i + 2)} Installment`,
                pct: p,
                amount: (totalAmount * (p / 100)),
                timing: `Due in ${(i + 1) * intervalDays} days`
            });
        }
    } else {
        const equalPct = remainderPct / subsequentTimes;
        for (let i = 0; i < subsequentTimes; i++) {
            scheduledRemainderPct += equalPct;
            schedule.push({
                num: i + 2,
                title: `${i + 2}${getOrdinalSuffix(i + 2)} Installment`,
                pct: equalPct,
                amount: (totalAmount * (equalPct / 100)),
                timing: `Due in ${(i + 1) * intervalDays} days`
            });
        }
    }

    // Render schedule cards
    const container = document.getElementById('sim_schedule_cards');
    if (container) {
        container.innerHTML = '';

        schedule.forEach((item, idx) => {
            const isFirst = (idx === 0);
            const card = document.createElement('div');
            card.className = 'p-2.5 rounded-3 border d-flex justify-content-between align-items-center';
            card.style.background = isFirst ? 'rgba(126, 34, 206, 0.05)' : '#ffffff';
            card.style.borderColor = isFirst ? 'rgba(126, 34, 206, 0.25)' : '#e2e8f0';

            card.innerHTML = `
                <div class="d-flex align-items-center gap-2.5">
                    <div style="width: 30px; height: 30px; border-radius: 8px; background: ${isFirst ? '#7e22ce' : '#3b82f6'}; color: white; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 700;">
                        ${item.num}
                    </div>
                    <div>
                        <div class="fw-bold text-slate-900" style="font-size: 0.85rem;">${item.title}</div>
                        <div class="text-secondary small" style="font-size: 0.725rem;">
                            <i class="fa-regular fa-clock me-1"></i> ${item.timing}
                        </div>
                    </div>
                </div>
                <div class="text-end">
                    <div class="fw-bold text-slate-900" style="font-size: 0.95rem;">
                        ₦${item.amount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                    </div>
                    <span class="mature-badge ${isFirst ? 'mature-badge-purple' : 'mature-badge-slate'}" style="font-size: 0.7rem;">
                        ${item.pct.toFixed(1)}%
                    </span>
                </div>
            `;
            container.appendChild(card);
        });

        // Summary banner update
        const totalScheduledPct = firstPct + (isCustom ? scheduledRemainderPct : (100 - firstPct));
        document.getElementById('sim_milestone_summary').innerText = `${schedule.length} Payments Total`;
        document.getElementById('sim_grand_total').innerText = `₦${totalAmount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
        
        const pctBadge = document.getElementById('sim_percentage_total');
        pctBadge.innerText = `${totalScheduledPct.toFixed(1)}% Scheduled`;
        if (Math.abs(totalScheduledPct - 100) < 0.1) {
            pctBadge.className = 'badge bg-white text-purple fw-bold px-2.5 py-1.5';
            pctBadge.style.color = '#7e22ce';
        } else {
            pctBadge.className = 'badge bg-warning text-dark fw-bold px-2.5 py-1.5';
            pctBadge.innerText = `Warning: ${totalScheduledPct.toFixed(1)}% (Not 100%)`;
        }
    }
}

function getOrdinalSuffix(i) {
    let j = i % 10, k = i % 100;
    if (j == 1 && k != 11) return "st";
    if (j == 2 && k != 12) return "nd";
    if (j == 3 && k != 13) return "rd";
    return "th";
}

function openEditFrequencyModal(freq) {
    document.getElementById('edit_freq_id').value = freq.id;
    document.getElementById('edit_freq_name').value = freq.name;
    document.getElementById('edit_freq_interval').value = freq.interval_days;
    document.getElementById('edit_freq_status').value = freq.status;
    document.getElementById('edit_freq_desc').value = freq.description || '';
    new bootstrap.Modal(document.getElementById('editFrequencyModal')).show();
}

document.addEventListener('DOMContentLoaded', function() {
    updateSimulators();
});
</script>

<?php include 'footer.php'; ?>
