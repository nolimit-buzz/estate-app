<?php
// zone/reports.php - Executive Zonal Performance, Occupancy & Financial Analytics
require_once '../config.php';
require_once '../includes/auth_guard.php';

requireZoneAccess();

$zone_id = get_current_zone_id();
$estate_id = get_estate_id();
$current_user_name = $_SESSION['name'] ?? 'Zone Admin';

// Fetch Zone Details
$zone_res = $conn->query("SELECT * FROM zones WHERE id = $zone_id AND estate_id = $estate_id LIMIT 1");
$zone = ($zone_res && $zone_res->num_rows > 0) ? $zone_res->fetch_assoc() : null;
if (!$zone) {
    die("<div style='font-family:sans-serif;padding:3rem;text-align:center;'>
            <h2>Invalid Zone Assignment</h2>
            <p>Your account is not assigned to a valid zone.</p>
            <a href='index'>Return to Dashboard</a>
         </div>");
}

// System Currency Setting
$currency_symbol = '₦';
$settings_query = $conn->query("SELECT setting_value FROM system_settings WHERE estate_id = $estate_id AND setting_key = 'currency_symbol' LIMIT 1");
if ($settings_query && $row = $settings_query->fetch_assoc()) {
    if (!empty($row['setting_value'])) $currency_symbol = $row['setting_value'];
}

// 1. Street-by-Street Census & Performance in this Zone
$street_census_query = $conn->query("
    SELECT s.id as street_id, s.name as street_name, s.custom_id,
           COUNT(DISTINCT b.id) as buildings_count,
           COUNT(DISTINCT f.id) as flats_count,
           COUNT(DISTINCT CASE WHEN f.status = 'occupied' THEN f.id END) as occupied_count,
           COUNT(DISTINCT r.id) as residents_count,
           COALESCE(SUM(p.amount), 0) as street_revenue
    FROM streets s
    LEFT JOIN buildings b ON b.street_id = s.id AND b.status != 'archived'
    LEFT JOIN flats f ON f.building_id = b.id AND f.status != 'archived'
    LEFT JOIN residents r ON r.flat_id = f.id AND r.status = 'active'
    LEFT JOIN invoices i ON i.flat_id = f.id AND i.zone_id = $zone_id
    LEFT JOIN payments p ON p.invoice_id = i.id AND p.status = 'paid'
    WHERE s.zone_id = $zone_id AND s.status != 'archived' AND s.estate_id = $estate_id
    GROUP BY s.id
    ORDER BY s.name ASC
");

$street_rows = [];
$chart_street_labels = [];
$chart_street_revenues = [];
$total_zone_buildings = 0;
$total_zone_flats = 0;
$total_zone_occupied = 0;
$total_zone_residents = 0;

if ($street_census_query) {
    while ($sc = $street_census_query->fetch_assoc()) {
        $street_rows[] = $sc;
        $chart_street_labels[] = $sc['street_name'];
        $chart_street_revenues[] = floatval($sc['street_revenue']);
        $total_zone_buildings += intval($sc['buildings_count']);
        $total_zone_flats += intval($sc['flats_count']);
        $total_zone_occupied += intval($sc['occupied_count']);
        $total_zone_residents += intval($sc['residents_count']);
    }
}

// 2. Financial Metrics Breakdown
$fin_summary = $conn->query("
    SELECT 
        COUNT(*) as total_invoices,
        COALESCE(SUM(amount), 0) as total_invoiced,
        COALESCE(SUM(amount_paid), 0) as total_paid,
        COALESCE(SUM(balance), 0) as total_balance,
        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
        SUM(CASE WHEN status IN ('unpaid', 'pending') THEN 1 ELSE 0 END) as pending_count,
        COALESCE(SUM(CASE WHEN status IN ('unpaid', 'pending') THEN balance ELSE 0 END), 0) as pending_amount,
        SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_count,
        COALESCE(SUM(CASE WHEN status = 'overdue' THEN balance ELSE 0 END), 0) as overdue_amount
    FROM invoices 
    WHERE zone_id = $zone_id AND estate_id = $estate_id
")->fetch_assoc();

$total_invoices = intval($fin_summary['total_invoices'] ?? 0);
$total_invoiced = floatval($fin_summary['total_invoiced'] ?? 0);
$total_paid = floatval($fin_summary['total_paid'] ?? 0);
$total_balance = floatval($fin_summary['total_balance'] ?? 0);

$paid_count = intval($fin_summary['paid_count'] ?? 0);
$pending_count = intval($fin_summary['pending_count'] ?? 0);
$pending_amount = floatval($fin_summary['pending_amount'] ?? 0);
$overdue_count = intval($fin_summary['overdue_count'] ?? 0);
$overdue_amount = floatval($fin_summary['overdue_amount'] ?? 0);

$collection_rate = $total_invoiced > 0 ? round(($total_paid / $total_invoiced) * 100, 1) : 0;
$delinquency_rate = $total_invoiced > 0 ? round(($overdue_amount / $total_invoiced) * 100, 1) : 0;

include 'header.php';
include 'sidebar.php';
?>

<style>
@media print {
    .sidebar, .navbar, .btn, .breadcrumb, footer, .theme-toggle-btn {
        display: none !important;
    }
    .app-container, .main-content {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }
    .mature-card {
        border: 1px solid #ccc !important;
        box-shadow: none !important;
        break-inside: avoid;
    }
}
</style>

<!-- ==========================================
     EXECUTIVE HEADER & REPORT TOOLBAR
     ========================================== -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4 pb-2 border-bottom border-light-subtle">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
            <h1 class="h4 font-bold text-slate-900 m-0" style="letter-spacing: -0.02em;">
                Zonal Analytical & Performance Reports
            </h1>
            <span class="mature-badge mature-badge-purple">
                <i class="fa-solid fa-chart-pie me-1"></i> Zone <?php echo htmlspecialchars($zone['code']); ?> Audit
            </span>
            <span class="mature-badge mature-badge-slate">
                <i class="fa-solid fa-calendar me-1"></i> As of <?php echo date('M d, Y'); ?>
            </span>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2 text-secondary small">
            <span><a href="index" class="text-secondary text-decoration-none"><i class="fa-solid fa-house me-1"></i> Zone Console</a></span>
            <span>/</span>
            <span class="text-slate-800 fw-medium">Audited Reports & Census</span>
            <span>•</span>
            <span>Sector: <strong><?php echo htmlspecialchars($zone['name']); ?></strong></span>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1.5">
            <i class="fa-solid fa-print"></i>
            <span>Print Report</span>
        </button>
        <a href="finance" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-receipt me-1"></i> Zonal Finance
        </a>
        <a href="billing_config" class="btn btn-sm text-white" style="background: #7e22ce; border-color: #7e22ce;">
            <i class="fa-solid fa-sliders me-1"></i> Billing Policy
        </a>
    </div>
</div>

<!-- ==========================================
     EXECUTIVE 4-PILLAR FINANCIAL & CENSUS KPI RIBBON
     ========================================== -->
<div class="row g-3 mb-4">
    <!-- Pillar 1: Invoiced Volume -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Total Invoiced</span>
                    <div class="kpi-value"><?php echo $currency_symbol . number_format($total_invoiced, 2); ?></div>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(126, 34, 206, 0.08); color: #7e22ce; border-color: rgba(126, 34, 206, 0.2);">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Total Bills: <strong><?php echo $total_invoices; ?></strong></span>
                <span class="mature-badge mature-badge-purple">Active Portfolio</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: 100%; background: #7e22ce;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 2: Realized Collections -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Total Realized</span>
                    <div class="kpi-value" style="color: #16a34a;"><?php echo $currency_symbol . number_format($total_paid, 2); ?></div>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(22, 163, 74, 0.08); color: #16a34a; border-color: rgba(22, 163, 74, 0.2);">
                    <i class="fa-solid fa-sack-dollar"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Settled: <strong><?php echo $paid_count; ?> bills</strong></span>
                <span class="mature-badge mature-badge-emerald"><?php echo $collection_rate; ?>% Realized</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?php echo max(5, $collection_rate); ?>%; background: #16a34a;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 3: Overdue Delinquency -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Overdue Delinquency</span>
                    <div class="kpi-value" style="color: #dc2626;"><?php echo $currency_symbol . number_format($overdue_amount, 2); ?></div>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(220, 38, 38, 0.08); color: #dc2626; border-color: rgba(220, 38, 38, 0.2);">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Overdue: <strong><?php echo $overdue_count; ?> accounts</strong></span>
                <span class="mature-badge <?php echo ($overdue_count > 0) ? 'mature-badge-crimson' : 'mature-badge-emerald'; ?>">
                    <?php echo $delinquency_rate; ?>% Risk
                </span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?php echo min(100, max(5, $delinquency_rate)); ?>%; background: #dc2626;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 4: Collection Efficiency Rate -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Collection Rate</span>
                    <div class="kpi-value" style="color: #7e22ce;"><?php echo $collection_rate; ?>%</div>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(59, 130, 246, 0.08); color: #2563eb; border-color: rgba(59, 130, 246, 0.2);">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Pending: <strong><?php echo $currency_symbol . number_format($pending_amount, 0); ?></strong></span>
                <?php if ($collection_rate >= 80): ?>
                    <span class="mature-badge mature-badge-emerald">Healthy</span>
                <?php elseif ($collection_rate >= 50): ?>
                    <span class="mature-badge mature-badge-amber">Moderate</span>
                <?php else: ?>
                    <span class="mature-badge mature-badge-crimson">Low Rate</span>
                <?php endif; ?>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?php echo max(5, $collection_rate); ?>%; background: #7e22ce;"></div>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     VISUAL ANALYTICS & AUDIT CHARTS ROW
     ========================================== -->
<div class="row g-3 mb-4">
    <!-- Left: Street Revenue Performance Comparison -->
    <div class="col-12 col-lg-7">
        <div class="mature-card">
            <div class="mature-card-header">
                <div>
                    <h3 class="mature-card-title">
                        <i class="fa-solid fa-chart-column text-secondary"></i> Street Revenue Comparison
                    </h3>
                    <p class="text-secondary small mb-0">Realized collections contribution per street in <?= htmlspecialchars($zone['name']) ?></p>
                </div>
                <span class="mature-badge mature-badge-purple"><?php echo count($street_rows); ?> Streets</span>
            </div>
            <div class="mature-card-body">
                <div style="position: relative; height: 260px; width: 100%;">
                    <canvas id="streetRevenueChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: Invoice Resolution & Portfolio Health -->
    <div class="col-12 col-lg-5">
        <div class="mature-card">
            <div class="mature-card-header">
                <div>
                    <h3 class="mature-card-title">
                        <i class="fa-solid fa-chart-pie text-secondary"></i> Invoice Portfolio Health
                    </h3>
                    <p class="text-secondary small mb-0">Distribution of zonal invoices by settlement status</p>
                </div>
                <span class="mature-badge mature-badge-slate"><?php echo $total_invoices; ?> Invoices</span>
            </div>
            <div class="mature-card-body d-flex flex-column align-items-center justify-content-center">
                <div style="position: relative; height: 180px; width: 100%; max-width: 220px;">
                    <canvas id="invoiceStatusChart"></canvas>
                </div>
                <div class="d-flex justify-content-around w-100 mt-3 pt-2 border-top border-light-subtle small text-center">
                    <div>
                        <div class="fw-bold text-success"><?php echo $paid_count; ?></div>
                        <span class="text-secondary">Settled</span>
                    </div>
                    <div>
                        <div class="fw-bold text-amber" style="color: #d97706;"><?php echo $pending_count; ?></div>
                        <span class="text-secondary">Pending</span>
                    </div>
                    <div>
                        <div class="fw-bold text-danger"><?php echo $overdue_count; ?></div>
                        <span class="text-secondary">Overdue</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     STREET-BY-STREET CENSUS & REVENUE TABLE
     ========================================== -->
<div class="mature-card mb-4">
    <div class="mature-card-header">
        <div>
            <h3 class="mature-card-title">
                <i class="fa-solid fa-road text-secondary"></i> Street-by-Street Census & Realized Revenue
            </h3>
            <p class="text-secondary small mb-0">Audited building infrastructure, flat occupancy rate, and financial realization by sector street</p>
        </div>
        <span class="mature-badge mature-badge-purple">
            <?php echo count($street_rows); ?> Street(s) Analyzed
        </span>
    </div>

    <div class="mature-card-body p-0">
        <div class="table-responsive">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>Street Name</th>
                        <th>Buildings</th>
                        <th>Total Flats</th>
                        <th>Occupied Flats</th>
                        <th>Occupancy Rate</th>
                        <th>Residents</th>
                        <th class="text-end pe-4">Realized Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($street_rows)): ?>
                        <?php foreach ($street_rows as $sc): 
                            $fl_tot = intval($sc['flats_count']);
                            $fl_occ = intval($sc['occupied_count']);
                            $occ_rate = $fl_tot > 0 ? round(($fl_occ / $fl_tot) * 100, 1) : 0;
                        ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold text-slate-900"><?php echo htmlspecialchars($sc['street_name']); ?></div>
                                    <span class="mature-badge mature-badge-slate font-monospace" style="font-size: 0.7rem;"><?php echo htmlspecialchars($sc['custom_id'] ?? 'N/A'); ?></span>
                                </td>
                                <td>
                                    <span class="fw-medium text-slate-800"><?php echo $sc['buildings_count']; ?></span>
                                </td>
                                <td>
                                    <span class="fw-medium text-slate-800"><?php echo $sc['flats_count']; ?></span>
                                </td>
                                <td>
                                    <span class="mature-badge mature-badge-emerald"><?php echo $sc['occupied_count']; ?> Occupied</span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2" style="max-width: 140px;">
                                        <div class="kpi-progress-bar flex-grow-1" style="height: 5px; margin: 0;">
                                            <div class="kpi-progress-fill" style="width: <?php echo $occ_rate; ?>%; background: #16a34a;"></div>
                                        </div>
                                        <span class="small fw-bold text-slate-800"><?php echo $occ_rate; ?>%</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="mature-badge mature-badge-purple">
                                        <i class="fa-solid fa-users me-1"></i> <?php echo $sc['residents_count']; ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4">
                                    <span class="fw-bold text-slate-900">
                                        <?php echo $currency_symbol . number_format($sc['street_revenue'], 2); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-secondary">
                                <i class="fa-solid fa-road d-block mb-1 opacity-50" style="font-size: 1.5rem;"></i>
                                No streets registered in this zone yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ==========================================
     SECONDARY 2-COLUMN RADAR PANELS
     ========================================== -->
<div class="row g-3 mb-4">
    <!-- Panel 1: Invoice Status Breakdown -->
    <div class="col-12 col-lg-6">
        <div class="mature-card h-100">
            <div class="mature-card-header">
                <h3 class="mature-card-title">
                    <i class="fa-solid fa-receipt text-secondary"></i> Invoice Status Financial Summary
                </h3>
                <a href="finance" class="btn btn-sm btn-outline-secondary">
                    Ledger <i class="fa-solid fa-arrow-right ms-1 small"></i>
                </a>
            </div>
            <div class="mature-card-body p-0">
                <div class="table-responsive">
                    <table class="table dashboard-table align-middle">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Count</th>
                                <th>Total Invoiced</th>
                                <th>Balance Due</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-check me-1"></i>Settled</span>
                                </td>
                                <td><span class="fw-semibold text-slate-900"><?php echo $paid_count; ?></span></td>
                                <td><span class="fw-bold text-slate-900"><?php echo $currency_symbol . number_format($total_paid, 2); ?></span></td>
                                <td><span class="text-secondary"><?php echo $currency_symbol; ?>0.00</span></td>
                            </tr>
                            <tr>
                                <td>
                                    <span class="mature-badge mature-badge-amber"><i class="fa-solid fa-clock me-1"></i>Pending / Unpaid</span>
                                </td>
                                <td><span class="fw-semibold text-slate-900"><?php echo $pending_count; ?></span></td>
                                <td><span class="fw-bold text-slate-900"><?php echo $currency_symbol . number_format($pending_amount, 2); ?></span></td>
                                <td><span class="text-amber fw-semibold" style="color: #d97706;"><?php echo $currency_symbol . number_format($pending_amount, 2); ?></span></td>
                            </tr>
                            <tr>
                                <td>
                                    <span class="mature-badge mature-badge-crimson"><i class="fa-solid fa-triangle-exclamation me-1"></i>Overdue</span>
                                </td>
                                <td><span class="fw-semibold text-slate-900"><?php echo $overdue_count; ?></span></td>
                                <td><span class="fw-bold text-slate-900"><?php echo $currency_symbol . number_format($overdue_amount, 2); ?></span></td>
                                <td><span class="text-danger fw-bold"><?php echo $currency_symbol . number_format($overdue_amount, 2); ?></span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Panel 2: Zonal Demographic & Capacity Snapshot -->
    <div class="col-12 col-lg-6">
        <div class="mature-card h-100">
            <div class="mature-card-header">
                <h3 class="mature-card-title">
                    <i class="fa-solid fa-city text-secondary"></i> Infrastructure & Demographic Radar
                </h3>
                <a href="properties" class="btn btn-sm btn-outline-secondary">
                    Registry <i class="fa-solid fa-arrow-right ms-1 small"></i>
                </a>
            </div>
            <div class="mature-card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="p-3 rounded-3 border" style="background: #f8fafc;">
                            <span class="text-secondary small text-uppercase fw-semibold" style="font-size: 0.725rem;">Total Buildings</span>
                            <div class="h4 fw-bold text-slate-900 mb-0 mt-1"><?php echo $total_zone_buildings; ?></div>
                            <small class="text-secondary">Across <?php echo count($street_rows); ?> streets</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded-3 border" style="background: #f8fafc;">
                            <span class="text-secondary small text-uppercase fw-semibold" style="font-size: 0.725rem;">Total Flats</span>
                            <div class="h4 fw-bold text-slate-900 mb-0 mt-1"><?php echo $total_zone_flats; ?></div>
                            <small class="text-success fw-semibold"><?php echo $total_zone_occupied; ?> occupied</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded-3 border" style="background: #f8fafc;">
                            <span class="text-secondary small text-uppercase fw-semibold" style="font-size: 0.725rem;">Zonal Population</span>
                            <div class="h4 fw-bold text-purple mb-0 mt-1" style="color: #7e22ce;"><?php echo $total_zone_residents; ?></div>
                            <small class="text-secondary">Active registered residents</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded-3 border" style="background: #f8fafc;">
                            <span class="text-secondary small text-uppercase fw-semibold" style="font-size: 0.725rem;">Average Flat Density</span>
                            <?php $avg_density = $total_zone_buildings > 0 ? round($total_zone_flats / $total_zone_buildings, 1) : 0; ?>
                            <div class="h4 fw-bold text-slate-900 mb-0 mt-1"><?php echo $avg_density; ?></div>
                            <small class="text-secondary">Flats per building</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     CHART.JS INTEGRATION & CONFIGURATION
     ========================================== -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    let streetChartInstance = null;
    let invChartInstance = null;

    function initReportCharts() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark' || document.body.classList.contains('dark-mode');
        const gridColor = isDark ? 'rgba(255, 255, 255, 0.06)' : 'rgba(15, 23, 42, 0.06)';
        const textColor = isDark ? '#94a3b8' : '#64748b';

        // 1. Street Revenue Comparison Bar Chart
        const streetCtx = document.getElementById('streetRevenueChart');
        if (streetCtx) {
            if (streetChartInstance) streetChartInstance.destroy();
            const streetLabels = <?php echo json_encode($chart_street_labels); ?>;
            const streetRevenues = <?php echo json_encode($chart_street_revenues); ?>;
            const currency = <?php echo json_encode($currency_symbol); ?>;

            streetChartInstance = new Chart(streetCtx, {
                type: 'bar',
                data: {
                    labels: streetLabels.length ? streetLabels : ['No Streets'],
                    datasets: [{
                        label: 'Street Realized Revenue',
                        data: streetRevenues.length ? streetRevenues : [0],
                        backgroundColor: isDark ? 'rgba(168, 85, 247, 0.55)' : 'rgba(126, 34, 206, 0.85)',
                        borderRadius: 6,
                        maxBarThickness: 42
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 400 },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: isDark ? '#0f172a' : '#1e293b',
                            padding: 10,
                            titleFont: { family: 'Outfit', size: 12 },
                            bodyFont: { family: 'Outfit', size: 13, weight: 'bold' },
                            cornerRadius: 8,
                            callbacks: {
                                label: function(context) {
                                    return ' ' + currency + Number(context.raw).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: textColor, font: { family: 'Outfit', size: 11 } }
                        },
                        y: {
                            grid: { color: gridColor },
                            ticks: {
                                color: textColor,
                                font: { family: 'Outfit', size: 11 },
                                callback: function(val) {
                                    return currency + Number(val).toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        }

        // 2. Invoice Status Distribution Doughnut Chart
        const invCtx = document.getElementById('invoiceStatusChart');
        if (invCtx) {
            if (invChartInstance) invChartInstance.destroy();
            const statusData = [
                <?php echo $paid_count; ?>,
                <?php echo $pending_count; ?>,
                <?php echo $overdue_count; ?>
            ];
            const hasData = statusData.some(v => v > 0);
            const chartData = hasData ? statusData : [0, 1, 0];

            invChartInstance = new Chart(invCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Settled', 'Pending', 'Overdue'],
                    datasets: [{
                        data: chartData,
                        backgroundColor: [
                            '#16a34a', // Emerald
                            '#d97706', // Amber
                            '#dc2626'  // Crimson
                        ],
                        borderWidth: 2,
                        borderColor: isDark ? '#1e293b' : '#ffffff',
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '72%',
                    animation: { duration: 400 },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: isDark ? '#0f172a' : '#1e293b',
                            padding: 8,
                            cornerRadius: 6,
                            titleFont: { family: 'Outfit', size: 11 },
                            bodyFont: { family: 'Outfit', size: 12 }
                        }
                    }
                }
            });
        }
    }

    initReportCharts();

    document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            setTimeout(initReportCharts, 120);
        });
    });
});
</script>

<?php include 'footer.php'; ?>
