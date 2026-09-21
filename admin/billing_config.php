<?php
// admin/billing_config.php - Central Billing & Dynamic Installment Configuration Engine
require_once '../config.php';
require_once '../includes/auth_guard.php';

requireAdminAccess();
if (!hasPermission('charges.manage') && !hasPermission('finance.view_invoices') && !in_array($_SESSION['role'] ?? '', ['superadmin', 'admin'])) {
    header("Location: index?error=unauthorized");
    exit;
}

$estate_id = get_estate_id();
$message = $flash_message ?? "";
$error = $flash_error ?? "";

// Fetch all zones for selector
$zones_list = [];
$zones_res = $conn->query("SELECT id, name, code, status FROM zones WHERE estate_id = $estate_id ORDER BY name ASC");
if ($zones_res) {
    while ($z = $zones_res->fetch_assoc()) {
        $zones_list[] = $z;
    }
}

// Determine selected target: 0 = Estate-Wide Universal Default, >0 = Specific Zone
$target_zone_id = isset($_GET['zone_id']) ? intval($_GET['zone_id']) : 0;
$selected_zone_info = null;
if ($target_zone_id > 0) {
    $target_chk = $conn->query("SELECT * FROM zones WHERE id = $target_zone_id AND estate_id = $estate_id LIMIT 1");
    if ($target_chk && $target_chk->num_rows > 0) {
        $selected_zone_info = $target_chk->fetch_assoc();
    } else {
        $target_zone_id = 0;
    }
}

// Fetch or initialize Billing Settings for selected target
$settings_res = $conn->query("SELECT * FROM zonal_billing_settings WHERE zone_id = $target_zone_id LIMIT 1");
if (!$settings_res || $settings_res->num_rows == 0) {
    $instructions_def = ($target_zone_id === 0) 
        ? "Estate-wide default installment billing policy. Downpayment is 40% with up to 3 subsequent installments every 30 days."
        : "Installment payments configured for this specific zone.";
    $conn->query("INSERT INTO zonal_billing_settings 
                  (estate_id, zone_id, allow_installments, min_first_payment_percent, max_subsequent_payments, split_mode, subsequent_percentages, installment_interval_days, allow_custom_amount, late_penalty_percent, grace_period_days, instructions) 
                  VALUES ($estate_id, $target_zone_id, 1, 40.00, 3, 'equal_remainder', '20,20,20', 30, 1, 0.00, 7, '$instructions_def')");
    $settings_res = $conn->query("SELECT * FROM zonal_billing_settings WHERE zone_id = $target_zone_id LIMIT 1");
}
$billing_settings = $settings_res->fetch_assoc();

// -------------------------------------------------------------
// POST HANDLERS (PRG Protected)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirect_url = 'billing_config' . ($target_zone_id > 0 ? ('?zone_id=' . $target_zone_id) : '');

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
                    WHERE zone_id = $target_zone_id";

        if ($conn->query($upd_sql)) {
            $target_name = $selected_zone_info ? ("Zone " . $selected_zone_info['name']) : "Estate-Wide Universal Default";
            logAudit($conn, "Billing Config Updated", "Finance", "Updated installment rules for $target_name: 1st Pay {$min_first_pct}%, {$max_subsequent} installments");
            redirectWithFlash($redirect_url, "Installment payment policy for $target_name successfully updated!");
        } else {
            redirectWithFlash($redirect_url, null, "Failed to update installment policy: " . $conn->error);
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
        $freq_scope = $_POST['freq_scope'] ?? 'estate'; // 'estate' (zone_id=NULL) or specific zone
        $freq_zone_id = ($freq_scope === 'zone' && $target_zone_id > 0) ? $target_zone_id : "NULL";

        if (!empty($name)) {
            $chk = $conn->query("SELECT id FROM zonal_billing_frequencies WHERE name = '$name' AND (zone_id = " . ($freq_zone_id === 'NULL' ? "NULL" : $freq_zone_id) . " OR zone_id IS NULL) LIMIT 1");
            if ($chk && $chk->num_rows > 0) {
                redirectWithFlash($redirect_url, null, "A billing frequency named '$name' already exists in the catalog.");
            }

            $sql = "INSERT INTO zonal_billing_frequencies (estate_id, zone_id, name, code, interval_days, description, status) 
                    VALUES ($estate_id, $freq_zone_id, '$name', '$code', $interval_days, '$desc', 'Active')";
            if ($conn->query($sql)) {
                logAudit($conn, "Frequency Added", "Finance", "Added central billing frequency '$name' ($interval_days days)");
                redirectWithFlash($redirect_url, "Billing frequency '$name' successfully added to the catalog!");
            } else {
                redirectWithFlash($redirect_url, null, "Failed to add frequency: " . $conn->error);
            }
        } else {
            redirectWithFlash($redirect_url, null, "Please provide a valid frequency name.");
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
            $conn->query("UPDATE zonal_billing_frequencies 
                          SET name = '$name', interval_days = $interval_days, description = '$desc', status = '$status' 
                          WHERE id = $freq_id");
            logAudit($conn, "Frequency Updated", "Finance", "Updated billing frequency #$freq_id ('$name')");
            redirectWithFlash($redirect_url, "Billing frequency '$name' updated successfully!");
        }
        redirectWithFlash($redirect_url, null, "Invalid frequency update request.");
    }

    // D. TOGGLE FREQUENCY STATUS
    elseif (isset($_POST['toggle_frequency'])) {
        $freq_id = intval($_POST['frequency_id']);
        $res = $conn->query("SELECT * FROM zonal_billing_frequencies WHERE id = $freq_id LIMIT 1");
        if ($res && $res->num_rows > 0) {
            $f = $res->fetch_assoc();
            $new_status = ($f['status'] === 'Active') ? 'Inactive' : 'Active';
            $conn->query("UPDATE zonal_billing_frequencies SET status = '$new_status' WHERE id = $freq_id");
            logAudit($conn, "Frequency Toggled", "Finance", "Toggled frequency '{$f['name']}' to $new_status");
            redirectWithFlash($redirect_url, "Frequency '{$f['name']}' status updated to $new_status.");
        }
        redirectWithFlash($redirect_url);
    }

    // E. DELETE FREQUENCY
    elseif (isset($_POST['delete_frequency'])) {
        $freq_id = intval($_POST['frequency_id']);
        if ($conn->query("DELETE FROM zonal_billing_frequencies WHERE id = $freq_id")) {
            logAudit($conn, "Frequency Deleted", "Finance", "Deleted billing frequency #$freq_id");
            redirectWithFlash($redirect_url, "Frequency deleted successfully.");
        } else {
            redirectWithFlash($redirect_url, null, "Failed to delete frequency: " . $conn->error);
        }
    }
}

// -------------------------------------------------------------
// LOAD BILLING FREQUENCIES CATALOG
// -------------------------------------------------------------
$freq_query = "
    SELECT f.*, 
           (CASE WHEN f.zone_id IS NULL THEN 'Universal Estate' ELSE CONCAT('Zone: ', COALESCE(z.name, 'Zonal')) END) as scope_label
    FROM zonal_billing_frequencies f
    LEFT JOIN zones z ON f.zone_id = z.id
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

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- ==========================================
     EXECUTIVE HEADER & BREADCRUMB TOOLBAR
     ========================================== -->
<div class="page-header-futuristic mb-4">
    <div>
        <div class="header-breadcrumbs">
            <span>Treasury & Billing</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <a href="finance" style="color: var(--text-muted); text-decoration: none;">Finance</a>
            <i class="fa-solid fa-chevron-right separator"></i>
            <a href="charges" style="color: var(--text-muted); text-decoration: none;">Charges</a>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span class="active">Billing & Installments Engine</span>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap mt-1">
            <h1 class="page-title m-0">Central Billing & Installment Policies</h1>
            <span class="mature-badge mature-badge-sky">
                <i class="fa-solid fa-sliders me-1"></i> Policy Engine
            </span>
            <span class="mature-badge <?= $billing_settings['allow_installments'] ? 'mature-badge-emerald' : 'mature-badge-slate' ?>">
                <i class="fa-solid <?= $billing_settings['allow_installments'] ? 'fa-circle-check' : 'fa-circle-pause' ?> me-1"></i>
                Installments <?= $billing_settings['allow_installments'] ? 'Active' : 'Disabled' ?>
            </span>
        </div>
        <p class="page-subtitle">Configure estate-wide and zonal milestone percentages, payment interval cadence, and custom billing frequencies.</p>
    </div>
    <div class="header-actions d-flex flex-wrap align-items-center gap-2">
        <!-- Target Policy Scope Switcher -->
        <div class="d-flex align-items-center gap-2 bg-white px-3 py-1 rounded-3 shadow-sm border" style="border-color: var(--border-color) !important;">
            <label class="small text-muted fw-bold text-uppercase m-0" style="font-size: 0.75rem; letter-spacing: 0.04em;">Target Scope:</label>
            <select class="form-select form-select-sm border-0 fw-semibold text-primary" style="background-color: transparent; cursor: pointer; outline: none; box-shadow: none;" onchange="location.href='billing_config?zone_id=' + this.value">
                <option value="0" <?= $target_zone_id === 0 ? 'selected' : '' ?>>🌐 Estate-Wide Universal Default</option>
                <?php foreach ($zones_list as $z): ?>
                    <option value="<?= $z['id'] ?>" <?= $target_zone_id === intval($z['id']) ? 'selected' : '' ?>>
                        📍 Zone <?= htmlspecialchars($z['code']) ?> - <?= htmlspecialchars($z['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <a href="charges" class="btn btn-outline" style="display:inline-flex; align-items:center; gap:0.5rem; font-weight:600; padding:0.6rem 1.1rem; border-radius:0.5rem; border:1px solid var(--border-color); background:var(--card-bg); color:var(--text-color); text-decoration:none;">
            <i class="fa-solid fa-tags"></i> Charge Catalog
        </a>
        <a href="finance" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:0.5rem; font-weight:600; padding:0.6rem 1.25rem; border-radius:0.5rem;">
            <i class="fa-solid fa-file-invoice-dollar"></i> Finance Hub
        </a>
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
     EXECUTIVE 3-METRIC KPI RIBBON
     ========================================== -->
<div class="row g-3 mb-4">
    <!-- Pillar 1: Installment Rules -->
    <div class="col-12 col-md-4">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Configured Downpayment</span>
                    <div class="kpi-value"><?= number_format($billing_settings['min_first_payment_percent'], 0) ?>% <span style="font-size: 0.95rem; font-weight: 500; color: #64748b;">Initial</span></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-chart-pie"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>+ <strong><?= intval($billing_settings['max_subsequent_payments']) ?></strong> subsequent milestones</span>
                <span class="mature-badge <?= $billing_settings['allow_installments'] ? 'mature-badge-emerald' : 'mature-badge-slate' ?>">
                    <?= $billing_settings['allow_installments'] ? 'Active Policy' : 'Disabled' ?>
                </span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?= floatval($billing_settings['min_first_payment_percent']) ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 2: Cadence / Interval -->
    <div class="col-12 col-md-4">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Installment Cadence</span>
                    <div class="kpi-value"><?= intval($billing_settings['installment_interval_days']) ?> <span style="font-size: 0.95rem; font-weight: 500; color: #64748b;">Days</span></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-calendar-days"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Milestone Interval Days</span>
                <span class="mature-badge mature-badge-sky">
                    <?= intval($billing_settings['installment_interval_days']) == 30 ? 'Monthly Basis' : (intval($billing_settings['installment_interval_days']) == 14 ? 'Bi-Weekly' : 'Custom Spacing') ?>
                </span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: 75%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 3: Dynamic Billing Plans -->
    <div class="col-12 col-md-4">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Billing Frequencies</span>
                    <div class="kpi-value"><?= number_format($active_freq_count) ?> <span style="font-size: 0.95rem; font-weight: 500; color: #64748b;">Active</span></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span><?= count($frequencies) ?> Plans In Catalog</span>
                <span class="mature-badge mature-badge-slate">Dynamic</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: 100%;"></div>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     DUAL CONFIGURATION TABS
     ========================================== -->
<div class="futuristic-tabs mb-4">
    <button class="futuristic-tab-btn tab-btn active" onclick="switchConfigTab(event, 'installmentTab')">
        <i class="fa-solid fa-chart-pie me-1"></i> Installment Policy & Live Simulator
    </button>
    <button class="futuristic-tab-btn tab-btn" onclick="switchConfigTab(event, 'frequenciesTab')">
        <i class="fa-solid fa-calendar-days me-1"></i> Billing Frequencies (<?= count($frequencies) ?>)
    </button>
</div>

<!-- TAB 1: INSTALLMENT POLICY & LIVE SIMULATOR -->
<div id="installmentTab" class="config-tab-content">
    <div class="row g-4">
        <!-- Configuration Form Column -->
        <div class="col-12 col-lg-7">
            <div class="mature-card h-100">
                <div class="mature-card-header">
                    <div>
                        <h2 class="mature-card-title">
                            <i class="fa-solid fa-sliders text-primary"></i> Installment Rules & Milestone Settings
                        </h2>
                        <p style="margin: 0.25rem 0 0; font-size: 0.8rem; color: var(--text-muted);">
                            <?= $target_zone_id > 0 ? ("Configuring specific policy for <strong>" . htmlspecialchars($selected_zone_info['name']) . "</strong>") : "Configuring <strong>Estate-Wide Universal Default</strong> rules applied across all zones." ?>
                        </p>
                    </div>
                </div>
                <div class="mature-card-body p-4">
                    <form method="POST" id="installmentPolicyForm">
                        <!-- Enable Installments Toggle -->
                        <div class="p-3 mb-4 rounded-3 border" style="background: rgba(59, 130, 246, 0.04); border-color: rgba(59, 130, 246, 0.2) !important;">
                            <div class="form-check form-switch d-flex align-items-center gap-3 ps-0 mb-0">
                                <input class="form-check-input ms-0 me-2" type="checkbox" role="switch" id="allow_installments" name="allow_installments" value="1" <?= $billing_settings['allow_installments'] ? 'checked' : '' ?> style="width: 2.75rem; height: 1.4rem; cursor: pointer;">
                                <div>
                                    <label class="form-check-label fw-bold text-dark d-block" for="allow_installments" style="cursor: pointer; font-size: 0.95rem;">
                                        Permit Installment & Milestone Payments
                                    </label>
                                    <small class="text-muted d-block" style="font-size: 0.78rem;">
                                        When enabled, invoices generated under charges allowing installments will offer automated milestone breakdown schedules.
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- Downpayment Percentage & Slider -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label fw-bold small text-muted text-uppercase m-0" style="letter-spacing: 0.03em;">
                                    Minimum Initial Downpayment (%)
                                </label>
                                <span class="badge bg-primary px-2.5 py-1.5 fs-6" id="firstPaymentBadge">
                                    <?= floatval($billing_settings['min_first_payment_percent']) ?>%
                                </span>
                            </div>
                            <input type="range" class="form-range" id="min_first_payment_percent_slider" min="10" max="90" step="5" value="<?= floatval($billing_settings['min_first_payment_percent']) ?>" oninput="syncFirstPayment(this.value)">
                            <div class="d-flex justify-content-between small text-muted" style="font-size: 0.72rem;">
                                <span>10% (Low Threshold)</span>
                                <span>50% (Equitable Split)</span>
                                <span>90% (Front-Loaded)</span>
                            </div>
                            <input type="hidden" name="min_first_payment_percent" id="min_first_payment_percent" value="<?= floatval($billing_settings['min_first_payment_percent']) ?>">
                        </div>

                        <!-- Subsequent Milestones & Cadence Grid -->
                        <div class="row g-3 mb-4">
                            <div class="col-12 col-sm-6">
                                <label class="form-label fw-bold small text-muted text-uppercase mb-1" style="letter-spacing: 0.03em;">
                                    Subsequent Installments Count
                                </label>
                                <select name="max_subsequent_payments" id="max_subsequent_payments" class="form-select fw-semibold" onchange="updateSimulator()">
                                    <?php for ($i = 1; $i <= 11; $i++): ?>
                                        <option value="<?= $i ?>" <?= intval($billing_settings['max_subsequent_payments']) === $i ? 'selected' : '' ?>>
                                            <?= $i ?> Subsequent Payment<?= $i > 1 ? 's' : '' ?> (Total: <?= $i + 1 ?> Milestones)
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">Excluding the initial downpayment.</small>
                            </div>

                            <div class="col-12 col-sm-6">
                                <label class="form-label fw-bold small text-muted text-uppercase mb-1" style="letter-spacing: 0.03em;">
                                    Payment Cadence Interval
                                </label>
                                <select name="installment_interval_days" id="installment_interval_days" class="form-select fw-semibold" onchange="updateSimulator()">
                                    <option value="7" <?= intval($billing_settings['installment_interval_days']) === 7 ? 'selected' : '' ?>>Every 7 Days (Weekly)</option>
                                    <option value="14" <?= intval($billing_settings['installment_interval_days']) === 14 ? 'selected' : '' ?>>Every 14 Days (Bi-Weekly)</option>
                                    <option value="30" <?= intval($billing_settings['installment_interval_days']) === 30 ? 'selected' : '' ?>>Every 30 Days (Monthly)</option>
                                    <option value="60" <?= intval($billing_settings['installment_interval_days']) === 60 ? 'selected' : '' ?>>Every 60 Days (Bi-Monthly)</option>
                                    <option value="90" <?= intval($billing_settings['installment_interval_days']) === 90 ? 'selected' : '' ?>>Every 90 Days (Quarterly)</option>
                                </select>
                                <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">Days between each milestone settlement.</small>
                            </div>
                        </div>

                        <!-- Split Mode -->
                        <div class="mb-4">
                            <label class="form-label fw-bold small text-muted text-uppercase mb-2" style="letter-spacing: 0.03em;">
                                Remainder Allocation Mode
                            </label>
                            <div class="d-flex flex-wrap gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="split_mode" id="mode_equal" value="equal_remainder" <?= ($billing_settings['split_mode'] ?? 'equal_remainder') === 'equal_remainder' ? 'checked' : '' ?> onchange="toggleCustomPercentages(false)">
                                    <label class="form-check-label small fw-semibold" for="mode_equal">
                                        Equal Remainder (Auto-Divide Remainder Evenly)
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="split_mode" id="mode_custom" value="custom_percentages" <?= ($billing_settings['split_mode'] ?? '') === 'custom_percentages' ? 'checked' : '' ?> onchange="toggleCustomPercentages(true)">
                                    <label class="form-check-label small fw-semibold" for="mode_custom">
                                        Custom Milestone Percentages
                                    </label>
                                </div>
                            </div>

                            <div id="custom_pct_wrap" class="mt-3" style="display: <?= ($billing_settings['split_mode'] ?? '') === 'custom_percentages' ? 'block' : 'none' ?>;">
                                <label class="form-label small text-muted">Subsequent Milestone Percentages (Comma-separated, e.g. <code>30, 30</code>):</label>
                                <input type="text" name="custom_subsequent_pct" id="custom_subsequent_pct" class="form-control font-monospace" value="<?= htmlspecialchars($billing_settings['subsequent_percentages'] ?? '') ?>" placeholder="e.g. 20, 20, 20" onkeyup="updateSimulator()">
                                <small class="text-muted" style="font-size: 0.72rem;">Must sum to the remaining percentage (100% minus Downpayment).</small>
                            </div>
                        </div>

                        <!-- Late Penalty & Grace Period -->
                        <div class="row g-3 mb-4">
                            <div class="col-12 col-sm-6">
                                <label class="form-label fw-bold small text-muted text-uppercase mb-1" style="letter-spacing: 0.03em;">
                                    Late Milestone Penalty (%)
                                </label>
                                <div class="input-group">
                                    <input type="number" step="0.5" min="0" max="50" name="late_penalty_percent" class="form-control" value="<?= floatval($billing_settings['late_penalty_percent'] ?? 0.00) ?>">
                                    <span class="input-group-text fw-bold">%</span>
                                </div>
                                <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">Default 0.00% (No penalty).</small>
                            </div>

                            <div class="col-12 col-sm-6">
                                <label class="form-label fw-bold small text-muted text-uppercase mb-1" style="letter-spacing: 0.03em;">
                                    Grace Period (Days)
                                </label>
                                <div class="input-group">
                                    <input type="number" min="0" max="90" name="grace_period_days" class="form-control" value="<?= intval($billing_settings['grace_period_days'] ?? 7) ?>">
                                    <span class="input-group-text fw-bold">Days</span>
                                </div>
                                <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">Buffer days before marked overdue.</small>
                            </div>
                        </div>

                        <!-- Resident Notice Instructions -->
                        <div class="mb-4">
                            <label class="form-label fw-bold small text-muted text-uppercase mb-1" style="letter-spacing: 0.03em;">
                                Resident Checkout Notice & Instructions
                            </label>
                            <textarea name="instructions" class="form-control" rows="2" placeholder="e.g. Installments must be paid on or before the respective milestone dates."><?= htmlspecialchars($billing_settings['instructions'] ?? '') ?></textarea>
                            <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">Displayed on the resident invoice checkout screen.</small>
                        </div>

                        <div class="d-flex justify-content-end gap-2 pt-3 border-top" style="border-color: var(--border-color) !important;">
                            <button type="submit" name="save_installment_settings" class="btn btn-primary px-4 fw-semibold" style="display: inline-flex; align-items: center; gap: 0.5rem;">
                                <i class="fa-solid fa-floppy-disk"></i> Save Installment Policy
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Live Interactive Simulator Column -->
        <div class="col-12 col-lg-5">
            <div class="mature-card h-100" style="border-top: 4px solid var(--primary-color);">
                <div class="mature-card-header">
                    <div>
                        <h2 class="mature-card-title">
                            <i class="fa-solid fa-calculator text-primary"></i> Live Interactive Simulator
                        </h2>
                        <p style="margin: 0.25rem 0 0; font-size: 0.8rem; color: var(--text-muted);">Real-time breakdown preview for any sample invoice amount.</p>
                    </div>
                </div>
                <div class="mature-card-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted text-uppercase">Sample Invoice Amount (₦)</label>
                        <div class="input-group">
                            <span class="input-group-text fw-bold" style="background: #f8fafc;">₦</span>
                            <input type="number" id="sim_sample_amount" class="form-control fw-bold fs-5 text-dark" value="200000" step="5000" min="1000" oninput="updateSimulator()">
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-1 mb-4">
                        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" onclick="setSimAmount(50000)">₦50,000</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" onclick="setSimAmount(100000)">₦100,000</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" onclick="setSimAmount(200000)">₦200,000</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" onclick="setSimAmount(500000)">₦500,000</button>
                    </div>

                    <!-- Milestone Breakdown Timeline Preview -->
                    <div class="p-3 rounded-3 border bg-light mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small fw-bold text-uppercase text-muted">Simulated Schedule</span>
                            <span class="badge bg-dark" id="sim_total_milestones_badge">4 Milestones</span>
                        </div>
                        <div id="sim_milestone_list" style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <!-- Populated dynamically via JS -->
                        </div>
                    </div>

                    <div class="p-3 rounded-3" style="background: rgba(16, 185, 129, 0.08); border: 1px dashed #10b981;">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-success small">Total Schedule Sum:</span>
                            <span class="fw-bolder text-success fs-6" id="sim_total_sum">₦200,000.00</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- TAB 2: BILLING FREQUENCIES CATALOG -->
<div id="frequenciesTab" class="config-tab-content" style="display: none;">
    <div class="mature-card mb-4">
        <div class="mature-card-header">
            <div>
                <h2 class="mature-card-title">
                    <i class="fa-solid fa-calendar-days text-primary"></i> Dynamic Billing Frequencies Manager
                </h2>
                <p style="margin: 0.25rem 0 0; font-size: 0.8rem; color: var(--text-muted);">
                    System and zonal frequencies available for assignment across charges and recurrent billing plans.
                </p>
            </div>
            <div>
                <button type="button" onclick="document.getElementById('addFrequencyModal').style.display='flex'" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:0.5rem; font-weight:600; padding:0.5rem 1rem; border-radius:0.5rem;">
                    <i class="fa-solid fa-plus"></i> Add New Frequency
                </button>
            </div>
        </div>

        <div class="mature-card-body p-0">
            <div class="table-responsive">
                <table class="table dashboard-table align-middle">
                    <thead>
                        <tr>
                            <th style="width: 180px;">Plan / Frequency Name</th>
                            <th>Code Identifier</th>
                            <th>Cycle Cadence (Days)</th>
                            <th>Description</th>
                            <th>Applicable Scope</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($frequencies)): ?>
                            <?php foreach ($frequencies as $f): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($f['name']) ?></div>
                                    </td>
                                    <td>
                                        <span class="id-chip"><?= htmlspecialchars($f['code']) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border px-2 py-1 fw-bold">
                                            <?= intval($f['interval_days']) ?> Days
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-muted small"><?= htmlspecialchars($f['description'] ?: 'Periodic billing cycle') ?></span>
                                    </td>
                                    <td>
                                        <span class="mature-badge <?= $f['zone_id'] === null ? 'mature-badge-sky' : 'mature-badge-purple' ?>">
                                            <?= htmlspecialchars($f['scope_label']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="mature-badge <?= $f['status'] === 'Active' ? 'mature-badge-emerald' : 'mature-badge-slate' ?>">
                                            <?= htmlspecialchars($f['status']) ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <div style="display: inline-flex; align-items: center; gap: 0.35rem;">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick='openEditFreqModal(<?= json_encode($f) ?>)' title="Edit Frequency">
                                                <i class="fa-solid fa-pen"></i>
                                            </button>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="frequency_id" value="<?= $f['id'] ?>">
                                                <button type="submit" name="toggle_frequency" class="btn btn-sm btn-outline-secondary" title="Toggle Status">
                                                    <?= $f['status'] === 'Active' ? '<i class="fa-solid fa-pause"></i>' : '<i class="fa-solid fa-play"></i>' ?>
                                                </button>
                                            </form>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete frequency \'<?= htmlspecialchars(addslashes($f['name'])) ?>\'?');">
                                                <input type="hidden" name="frequency_id" value="<?= $f['id'] ?>">
                                                <button type="submit" name="delete_frequency" class="btn btn-sm btn-outline-danger" title="Delete Frequency">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No billing frequencies found. Click "Add New Frequency" above.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     ADD FREQUENCY MODAL
     ========================================== -->
<div id="addFrequencyModal" class="custom-modal-backdrop" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15, 23, 42, 0.6); z-index:1000; align-items:center; justify-content:center; backdrop-filter: blur(4px);">
    <div class="modal-content mature-card" style="background:#fff; max-width: 480px; width: 100%; margin: auto; border-radius: 0.85rem; border: 1px solid var(--border-color); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); padding: 1.5rem;">
        <div class="d-flex justify-content-between align-items-center pb-2 mb-3 border-bottom">
            <h5 class="m-0 fw-bold"><i class="fa-solid fa-plus text-primary me-1"></i> Add Billing Frequency</h5>
            <button type="button" onclick="document.getElementById('addFrequencyModal').style.display='none'" class="btn-close"></button>
        </div>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label small fw-bold text-muted">Frequency Name</label>
                <input type="text" name="name" required class="form-control" placeholder="e.g. Bi-Weekly Cycle, Tri-Annual">
            </div>
            <div class="mb-3">
                <label class="form-label small fw-bold text-muted">Cycle Cadence (Interval in Days)</label>
                <input type="number" name="interval_days" required min="1" max="730" class="form-control" value="30" placeholder="e.g. 14, 30, 90">
            </div>
            <div class="mb-3">
                <label class="form-label small fw-bold text-muted">Description (Optional)</label>
                <input type="text" name="description" class="form-control" placeholder="e.g. Billed every 14 days">
            </div>
            <div class="mb-3">
                <label class="form-label small fw-bold text-muted">Applicable Scope</label>
                <select name="freq_scope" class="form-select">
                    <option value="estate" selected>Universal Estate (Available to All Zones & Central)</option>
                    <?php if ($target_zone_id > 0): ?>
                        <option value="zone">Scoped to Zone <?= htmlspecialchars($selected_zone_info['code'] ?? '') ?></option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                <button type="button" onclick="document.getElementById('addFrequencyModal').style.display='none'" class="btn btn-outline-secondary">Cancel</button>
                <button type="submit" name="add_frequency" class="btn btn-primary fw-semibold">Save Frequency</button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================
     EDIT FREQUENCY MODAL
     ========================================== -->
<div id="editFrequencyModal" class="custom-modal-backdrop" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15, 23, 42, 0.6); z-index:1000; align-items:center; justify-content:center; backdrop-filter: blur(4px);">
    <div class="modal-content mature-card" style="background:#fff; max-width: 480px; width: 100%; margin: auto; border-radius: 0.85rem; border: 1px solid var(--border-color); box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); padding: 1.5rem;">
        <div class="d-flex justify-content-between align-items-center pb-2 mb-3 border-bottom">
            <h5 class="m-0 fw-bold"><i class="fa-solid fa-pen text-primary me-1"></i> Edit Billing Frequency</h5>
            <button type="button" onclick="document.getElementById('editFrequencyModal').style.display='none'" class="btn-close"></button>
        </div>
        <form method="POST">
            <input type="hidden" name="frequency_id" id="edit_freq_id">
            <div class="mb-3">
                <label class="form-label small fw-bold text-muted">Frequency Name</label>
                <input type="text" name="name" id="edit_freq_name" required class="form-control">
            </div>
            <div class="mb-3">
                <label class="form-label small fw-bold text-muted">Cycle Cadence (Days)</label>
                <input type="number" name="interval_days" id="edit_freq_interval" required min="1" max="730" class="form-control">
            </div>
            <div class="mb-3">
                <label class="form-label small fw-bold text-muted">Description</label>
                <input type="text" name="description" id="edit_freq_desc" class="form-control">
            </div>
            <div class="mb-3">
                <label class="form-label small fw-bold text-muted">Status</label>
                <select name="status" id="edit_freq_status" class="form-select">
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>
            <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                <button type="button" onclick="document.getElementById('editFrequencyModal').style.display='none'" class="btn btn-outline-secondary">Cancel</button>
                <button type="submit" name="edit_frequency" class="btn btn-primary fw-semibold">Update Frequency</button>
            </div>
        </form>
    </div>
</div>

<script>
function switchConfigTab(evt, tabId) {
    document.querySelectorAll('.config-tab-content').forEach(c => c.style.display = 'none');
    document.querySelectorAll('.futuristic-tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById(tabId).style.display = 'block';
    evt.currentTarget.classList.add('active');
}

function syncFirstPayment(val) {
    document.getElementById('firstPaymentBadge').innerText = val + '%';
    document.getElementById('min_first_payment_percent').value = val;
    updateSimulator();
}

function toggleCustomPercentages(show) {
    document.getElementById('custom_pct_wrap').style.display = show ? 'block' : 'none';
    updateSimulator();
}

function setSimAmount(amt) {
    document.getElementById('sim_sample_amount').value = amt;
    updateSimulator();
}

function updateSimulator() {
    const totalAmount = parseFloat(document.getElementById('sim_sample_amount').value) || 0;
    const firstPct = parseFloat(document.getElementById('min_first_payment_percent').value) || 40;
    const subsequentTimes = parseInt(document.getElementById('max_subsequent_payments').value) || 3;
    const intervalDays = parseInt(document.getElementById('installment_interval_days').value) || 30;
    const isCustom = document.getElementById('mode_custom').checked;
    const customStr = document.getElementById('custom_subsequent_pct') ? document.getElementById('custom_subsequent_pct').value : '';

    const listContainer = document.getElementById('sim_milestone_list');
    listContainer.innerHTML = '';

    // Downpayment
    const firstAmt = Math.round(totalAmount * (firstPct / 100));
    let schedule = [];
    schedule.push({
        num: 1,
        title: '1st Installment (Downpayment)',
        pct: firstPct,
        amt: firstAmt,
        days: 0
    });

    // Subsequent milestones
    const remPct = 100 - firstPct;
    const remAmt = totalAmount - firstAmt;
    let parts = [];

    if (isCustom && customStr) {
        const raw = customStr.split(',');
        for (let r of raw) {
            let v = parseFloat(r.trim());
            if (v > 0) parts.push(v);
        }
    }

    if (parts.length === 0) {
        const eqPct = (remPct / subsequentTimes).toFixed(2);
        for (let s = 0; s < subsequentTimes; s++) {
            parts.push(parseFloat(eqPct));
        }
    }

    let allocatedAmt = 0;
    parts.forEach((p, idx) => {
        const sNum = idx + 2;
        const isLast = (idx === parts.length - 1);
        const sAmt = isLast ? (remAmt - allocatedAmt) : Math.round(totalAmount * (p / 100));
        allocatedAmt += sAmt;
        const daysOffset = (idx + 1) * intervalDays;
        const suffix = (sNum === 2) ? 'nd' : (sNum === 3 ? 'rd' : 'th');
        schedule.push({
            num: sNum,
            title: `${sNum}${suffix} Installment`,
            pct: p,
            amt: sAmt,
            days: daysOffset
        });
    });

    document.getElementById('sim_total_milestones_badge').innerText = schedule.length + ' Milestones';

    let totalSum = 0;
    schedule.forEach(item => {
        totalSum += item.amt;
        const div = document.createElement('div');
        div.className = 'd-flex justify-content-between align-items-center p-2 rounded-2 bg-white border';
        div.style.fontSize = '0.85rem';
        div.innerHTML = `
            <div>
                <div class="fw-bold text-dark">${item.title} <span class="badge bg-light text-muted border ms-1" style="font-size:0.7rem;">${item.pct}%</span></div>
                <div class="text-muted" style="font-size:0.72rem;"><i class="fa-solid fa-clock me-1"></i> Due in ${item.days === 0 ? 'Day 0 (Immediately)' : item.days + ' Days'}</div>
            </div>
            <div class="text-end">
                <div class="fw-bold text-dark">₦${item.amt.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
            </div>
        `;
        listContainer.appendChild(div);
    });

    document.getElementById('sim_total_sum').innerText = '₦' + totalSum.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function openEditFreqModal(freq) {
    document.getElementById('edit_freq_id').value = freq.id;
    document.getElementById('edit_freq_name').value = freq.name;
    document.getElementById('edit_freq_interval').value = freq.interval_days;
    document.getElementById('edit_freq_desc').value = freq.description || '';
    document.getElementById('edit_freq_status').value = freq.status;
    document.getElementById('editFrequencyModal').style.display = 'flex';
}

document.addEventListener('DOMContentLoaded', function() {
    updateSimulator();
});
</script>

<?php include '../includes/footer.php'; ?>
