<?php
// admin/index.php - Executive Admin Command Center
require_once '../config.php';
require_once '../includes/auth_guard.php';
requireAdminAccess();

include '../includes/header.php';
include '../includes/sidebar.php';

$estate_id = get_estate_id();
$current_user_name = $_SESSION['name'] ?? 'Admin';

// Fetch Estate System Settings for dynamic currency and branding
$sys_settings = [];
$settings_query = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE estate_id = $estate_id");
if ($settings_query) {
    while ($row = $settings_query->fetch_assoc()) {
        $sys_settings[$row['setting_key']] = $row['setting_value'];
    }
}
$currency_symbol = !empty($sys_settings['currency_symbol']) ? $sys_settings['currency_symbol'] : '₦';
$estate_display_name = !empty($sys_settings['estate_name']) ? $sys_settings['estate_name'] : 'Estate Admin';

// Dynamic Day Greeting
$hour = intval(date('H'));
if ($hour < 12) {
    $greeting = "Good morning";
} elseif ($hour < 17) {
    $greeting = "Good afternoon";
} else {
    $greeting = "Good evening";
}

// -------------------------------------------------------------
// 1. CONSOLIDATED OPERATIONAL KPIS
// -------------------------------------------------------------
$kpi_res = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM residents WHERE estate_id = $estate_id AND status = 'active') as active_residents,
        (SELECT COUNT(*) FROM residents WHERE estate_id = $estate_id AND type = 'dependent') as total_dependents,
        (SELECT COUNT(*) FROM property_owners WHERE estate_id = $estate_id) as total_owners,
        (SELECT COUNT(*) FROM zones WHERE estate_id = $estate_id AND status = 'active') as total_zones,
        (SELECT COUNT(*) FROM buildings WHERE estate_id = $estate_id) as total_buildings,
        (SELECT COUNT(*) FROM flats WHERE estate_id = $estate_id) as total_flats,
        (SELECT COUNT(*) FROM flats WHERE estate_id = $estate_id AND status = 'occupied') as occupied_flats,
        (SELECT COUNT(*) FROM flats WHERE estate_id = $estate_id AND status = 'vacant') as vacant_flats,
        (SELECT COUNT(*) FROM flats WHERE estate_id = $estate_id AND status = 'maintenance') as maintenance_flats,
        (SELECT COUNT(*) FROM estate_staff WHERE estate_id = $estate_id AND status = 'active') as active_staff,
        (SELECT COUNT(*) FROM vehicles WHERE estate_id = $estate_id) as total_vehicles,
        (SELECT COUNT(*) FROM maintenance_requests WHERE estate_id = $estate_id AND status IN ('open','in_progress')) as pending_maint,
        (SELECT COUNT(*) FROM maintenance_requests WHERE estate_id = $estate_id AND priority = 'emergency' AND status IN ('open','in_progress')) as emergency_maint,
        (SELECT COUNT(*) FROM visitors WHERE estate_id = $estate_id AND status = 'entered') as visitors_inside,
        (SELECT COUNT(*) FROM visitors WHERE estate_id = $estate_id AND DATE(created_at) = CURDATE()) as visitors_today,
        (SELECT COUNT(*) FROM visitors WHERE estate_id = $estate_id AND status = 'pre_registered') as visitors_expected,
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE estate_id = $estate_id AND status = 'paid' AND MONTH(COALESCE(paid_at, transaction_date)) = MONTH(CURDATE()) AND YEAR(COALESCE(paid_at, transaction_date)) = YEAR(CURDATE())) as revenue_this_month,
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE estate_id = $estate_id AND status = 'paid') as revenue_total,
        (SELECT COALESCE(SUM(balance), 0) FROM invoices WHERE estate_id = $estate_id AND status IN ('pending','unpaid','partially_paid','overdue')) as outstanding_invoices,
        (SELECT COUNT(*) FROM invoices WHERE estate_id = $estate_id AND status = 'overdue') as overdue_invoice_count
");
$kpi = $kpi_res ? $kpi_res->fetch_assoc() : [];

$total_zones = intval($kpi['total_zones'] ?? 0);
$active_residents = intval($kpi['active_residents'] ?? 0);
$total_dependents = intval($kpi['total_dependents'] ?? 0);
$total_population = $active_residents + $total_dependents;
$total_owners = intval($kpi['total_owners'] ?? 0);
$total_buildings = intval($kpi['total_buildings'] ?? 0);
$total_flats = intval($kpi['total_flats'] ?? 0);
$occupied_flats = intval($kpi['occupied_flats'] ?? 0);
$vacant_flats = intval($kpi['vacant_flats'] ?? 0);
$maintenance_flats = intval($kpi['maintenance_flats'] ?? 0);
$occupancy_pct = $total_flats > 0 ? round(($occupied_flats / $total_flats) * 100, 1) : 0;

$active_staff = intval($kpi['active_staff'] ?? 0);
$total_vehicles = intval($kpi['total_vehicles'] ?? 0);
$pending_maint = intval($kpi['pending_maint'] ?? 0);
$emergency_maint = intval($kpi['emergency_maint'] ?? 0);
$visitors_inside = intval($kpi['visitors_inside'] ?? 0);
$visitors_today = intval($kpi['visitors_today'] ?? 0);
$visitors_expected = intval($kpi['visitors_expected'] ?? 0);

$revenue_this_month = floatval($kpi['revenue_this_month'] ?? 0);
$revenue_total = floatval($kpi['revenue_total'] ?? 0);
$outstanding_invoices = floatval($kpi['outstanding_invoices'] ?? 0);
$overdue_invoice_count = intval($kpi['overdue_invoice_count'] ?? 0);

// -------------------------------------------------------------
// 2. TREND DATASETS FOR CHARTS
// -------------------------------------------------------------
// 6-Month Revenue Trend
$revenue_months = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("-$i months"));
    $label = date('M Y', strtotime("-$i months"));
    $revenue_months[$key] = ['label' => $label, 'amount' => 0.0];
}
$rev_chart_res = $conn->query("
    SELECT DATE_FORMAT(COALESCE(paid_at, transaction_date), '%Y-%m') as ym, SUM(amount) as amt 
    FROM payments 
    WHERE estate_id = $estate_id AND status = 'paid' 
      AND COALESCE(paid_at, transaction_date) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym
");
if ($rev_chart_res) {
    while ($r = $rev_chart_res->fetch_assoc()) {
        if (isset($revenue_months[$r['ym']])) {
            $revenue_months[$r['ym']]['amount'] = floatval($r['amt']);
        }
    }
}
$chart_revenue_labels = array_map(function($item) { return $item['label']; }, array_values($revenue_months));
$chart_revenue_values = array_map(function($item) { return $item['amount']; }, array_values($revenue_months));

// -------------------------------------------------------------
// 3. OPERATIONAL FEEDS & TABLES
// -------------------------------------------------------------
// Recent Gate Entries
$recent_visitors = $conn->query("
    SELECT v.*, u.name as resident_name, f.number as flat_number, b.name as building_name 
    FROM visitors v 
    LEFT JOIN users u ON v.resident_id = u.id 
    LEFT JOIN flats f ON v.flat_id = f.id 
    LEFT JOIN buildings b ON f.building_id = b.id 
    WHERE v.estate_id = $estate_id 
    ORDER BY v.id DESC LIMIT 5
");

// Recent Maintenance Tickets
$recent_maintenance = $conn->query("
    SELECT m.*, u.name as requester_name, f.number as flat_number, b.name as building_name,
           u_att.name as attended_staff_name
    FROM maintenance_requests m 
    LEFT JOIN users u ON m.user_id = u.id 
    LEFT JOIN flats f ON m.flat_id = f.id 
    LEFT JOIN buildings b ON f.building_id = b.id 
    LEFT JOIN users u_att ON m.attended_by = u_att.id 
    WHERE m.estate_id = $estate_id 
    ORDER BY m.id DESC LIMIT 5
");

// Recent Financial Transactions
$recent_payments = $conn->query("
    SELECT p.*, u.name as resident_name, i.invoice_number, r.receipt_number 
    FROM payments p 
    LEFT JOIN users u ON p.user_id = u.id 
    LEFT JOIN invoices i ON p.invoice_id = i.id 
    LEFT JOIN receipts r ON r.payment_id = p.id 
    WHERE p.estate_id = $estate_id 
    ORDER BY p.id DESC LIMIT 5
");

// Recent System Audit Activity
$recent_audits = $conn->query("
    SELECT a.*, u.name as user_name, u.role as user_role 
    FROM audit_logs a 
    LEFT JOIN users u ON a.user_id = u.id 
    WHERE a.estate_id = $estate_id 
    ORDER BY a.id DESC LIMIT 5
");
?>

<!-- ==========================================
     EXECUTIVE HEADER & COMMAND TOOLBAR
     ========================================== -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4 pb-2 border-bottom border-light-subtle">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h1 class="h4 font-bold text-slate-900 m-0" style="letter-spacing: -0.02em;">
                <?php echo $greeting; ?>, <?php echo htmlspecialchars($current_user_name); ?>
            </h1>
            <span class="mature-badge mature-badge-slate">
                <i class="fa-solid fa-shield-check me-1"></i> Admin Command
            </span>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2 text-secondary small">
            <span><i class="fa-regular fa-calendar me-1"></i> <?php echo date('l, F j, Y'); ?></span>
            <span>•</span>
            <span><i class="fa-solid fa-building-circle-check me-1"></i> <?php echo htmlspecialchars($estate_display_name); ?></span>
            <span>•</span>
            <span class="text-success"><i class="fa-solid fa-circle me-1" style="font-size: 0.55rem;"></i> System Live</span>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="zones" class="btn btn-sm btn-outline-primary">
            <i class="fa-solid fa-layer-group me-1"></i> Zones & Sectors
        </a>
        <a href="residents" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-user-plus me-1"></i> Resident
        </a>
        <a href="security" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-id-badge me-1"></i> Issue Pass
        </a>
        <a href="finance" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-file-invoice-dollar me-1"></i> Finance Hub
        </a>
        <a href="community_chat" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-comments me-1"></i> Estate Forum
        </a>
        <a href="maintenance" class="btn btn-sm text-white" style="background: #0f172a;">
            <i class="fa-solid fa-screwdriver-wrench me-1"></i> Work Order
        </a>
    </div>
</div>

<!-- ==========================================
     CONDITIONAL OPERATIONAL ATTENTION BANNER
     ========================================== -->
<?php if ($emergency_maint > 0 || $overdue_invoice_count > 0): ?>
<div class="alert mature-card p-3 mb-4 border-0" style="background: #fff1f2; border-left: 4px solid #e11d48 !important;">
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2">
        <div class="d-flex align-items-center gap-3">
            <div class="kpi-icon-wrap" style="background: #ffe4e6; color: #e11d48; border-color: #fecdd3;">
                <i class="fa-solid fa-bell"></i>
            </div>
            <div>
                <h6 class="fw-bold mb-0 text-slate-900" style="font-size: 0.9rem;">Operational Attention Required</h6>
                <div class="small text-secondary">
                    <?php 
                    $alerts = [];
                    if ($emergency_maint > 0) $alerts[] = "<strong>$emergency_maint</strong> emergency maintenance ticket(s) awaiting dispatch";
                    if ($overdue_invoice_count > 0) $alerts[] = "<strong>$overdue_invoice_count</strong> overdue invoice(s) pending collection";
                    echo implode(' &bull; ', $alerts);
                    ?>
                </div>
            </div>
        </div>
        <div class="d-flex gap-2">
            <?php if ($emergency_maint > 0): ?>
                <a href="maintenance" class="btn btn-xs btn-outline-danger btn-sm">Review Emergency</a>
            <?php endif; ?>
            <?php if ($overdue_invoice_count > 0): ?>
                <a href="finance" class="btn btn-xs btn-outline-dark btn-sm">Review Overdue</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ==========================================
     EXECUTIVE KPI METRICS RIBBON (4 PILLARS)
     ========================================== -->
<div class="row g-3 mb-4">
    <!-- Pillar 1: Financial Performance -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Revenue (This Month)</span>
                    <div class="kpi-value"><?php echo $currency_symbol . number_format($revenue_this_month, 2); ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-coins"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>All-Time: <strong><?php echo $currency_symbol . number_format($revenue_total, 0); ?></strong></span>
                <span class="mature-badge <?php echo ($outstanding_invoices > 0) ? 'mature-badge-amber' : 'mature-badge-emerald'; ?>">
                    <?php echo $currency_symbol . number_format($outstanding_invoices, 0); ?> due
                </span>
            </div>
            <div class="kpi-progress-bar">
                <?php 
                $fin_pct = ($revenue_total > 0) ? min(100, round(($revenue_this_month / $revenue_total) * 100)) : 0;
                ?>
                <div class="kpi-progress-fill" style="width: <?php echo max(8, $fin_pct); ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 2: Real Estate Capacity -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Capacity & Occupancy</span>
                    <div class="kpi-value"><?php echo $occupancy_pct; ?>% <span style="font-size: 0.95rem; font-weight: 500; color: #64748b;">Occupied</span></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-city"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span><strong><?php echo $occupied_flats; ?></strong> Occupied / <strong><?php echo $total_flats; ?></strong> Flats</span>
                <span class="mature-badge mature-badge-slate"><a href="zones" class="text-decoration-none text-reset"><i class="fa-solid fa-layer-group me-1"></i><?php echo $total_zones; ?> Zones</a></span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?php echo $occupancy_pct; ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 3: Security & Gate Access -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Gate & Security</span>
                    <div class="kpi-value"><?php echo number_format($visitors_inside); ?> <span style="font-size: 0.95rem; font-weight: 500; color: #64748b;">Inside</span></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Today's Flow: <strong><?php echo $visitors_today; ?></strong></span>
                <span class="mature-badge mature-badge-primary"><?php echo $visitors_expected; ?> Expected</span>
            </div>
            <div class="kpi-progress-bar">
                <?php 
                $sec_bar = ($visitors_today > 0) ? min(100, round(($visitors_inside / max(1, $visitors_today)) * 100)) : 0;
                ?>
                <div class="kpi-progress-fill" style="width: <?php echo max(6, $sec_bar); ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 4: Facility & Maintenance -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Maintenance Radar</span>
                    <div class="kpi-value"><?php echo number_format($pending_maint); ?> <span style="font-size: 0.95rem; font-weight: 500; color: #64748b;">Pending</span></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-hammer"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Population: <strong><?php echo number_format($total_population); ?></strong></span>
                <?php if ($emergency_maint > 0): ?>
                    <span class="mature-badge mature-badge-crimson"><i class="fa-solid fa-triangle-exclamation me-1"></i><?php echo $emergency_maint; ?> Critical</span>
                <?php else: ?>
                    <span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-check me-1"></i>Normal</span>
                <?php endif; ?>
            </div>
            <div class="kpi-progress-bar">
                <?php 
                $maint_fill = ($pending_maint > 0) ? min(100, $pending_maint * 15) : 5;
                ?>
                <div class="kpi-progress-fill" style="width: <?php echo $maint_fill; ?>%;"></div>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     VISUAL ANALYTICS & TREND CHARTS
     ========================================== -->
<div class="row g-3 mb-4">
    <!-- Left: 6-Month Financial Collection Trend -->
    <div class="col-12 col-lg-8">
        <div class="mature-card">
            <div class="mature-card-header">
                <div>
                    <h3 class="mature-card-title">
                        <i class="fa-solid fa-chart-column text-secondary"></i> Financial Collection Velocity
                    </h3>
                    <p class="text-secondary small mb-0">Monthly realized payments trend over the last 6 months</p>
                </div>
                <a href="finance" class="btn btn-sm btn-outline-secondary">
                    View Ledger <i class="fa-solid fa-arrow-right ms-1 small"></i>
                </a>
            </div>
            <div class="mature-card-body">
                <div style="position: relative; height: 250px; width: 100%;">
                    <canvas id="revenueTrendChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: Property Capacity Breakdown -->
    <div class="col-12 col-lg-4">
        <div class="mature-card">
            <div class="mature-card-header">
                <div>
                    <h3 class="mature-card-title">
                        <i class="fa-solid fa-chart-pie text-secondary"></i> Unit Capacity Breakdown
                    </h3>
                    <p class="text-secondary small mb-0">Flat status distribution</p>
                </div>
                <span class="mature-badge mature-badge-slate"><?php echo $total_flats; ?> Total Units</span>
            </div>
            <div class="mature-card-body d-flex flex-column align-items-center justify-content-center">
                <div style="position: relative; height: 180px; width: 100%; max-width: 220px;">
                    <canvas id="occupancyDoughnutChart"></canvas>
                </div>
                <div class="d-flex justify-content-around w-100 mt-3 pt-2 border-top border-light-subtle small text-center">
                    <div>
                        <div class="fw-bold text-slate-800"><?php echo $occupied_flats; ?></div>
                        <span class="text-secondary">Occupied</span>
                    </div>
                    <div>
                        <div class="fw-bold text-slate-800"><?php echo $vacant_flats; ?></div>
                        <span class="text-secondary">Vacant</span>
                    </div>
                    <div>
                        <div class="fw-bold text-slate-800"><?php echo $maintenance_flats; ?></div>
                        <span class="text-secondary">Maintenance</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     OPERATIONAL COMMAND TABLES & FEEDS (2x2)
     ========================================== -->
<div class="row g-3 mb-4">
    <!-- Panel 1: Live Gate Security Feed -->
    <div class="col-12 col-lg-6">
        <div class="mature-card">
            <div class="mature-card-header">
                <h3 class="mature-card-title">
                    <i class="fa-solid fa-shield-halved text-secondary"></i> Live Gate & Access Log
                </h3>
                <a href="security" class="btn btn-sm btn-outline-secondary">
                    Security Center <i class="fa-solid fa-arrow-right ms-1 small"></i>
                </a>
            </div>
            <div class="mature-card-body p-0">
                <div class="table-responsive">
                    <table class="table dashboard-table align-middle">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Visitor</th>
                                <th>Host / Destination</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_visitors && $recent_visitors->num_rows > 0): ?>
                                <?php while ($v = $recent_visitors->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <span class="mature-badge mature-badge-slate font-monospace" style="font-size: 0.725rem;">
                                                <?php echo htmlspecialchars($v['visitor_code'] ?? 'N/A'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-slate-900"><?php echo htmlspecialchars($v['name']); ?></div>
                                            <div class="small text-secondary"><?php echo htmlspecialchars($v['phone'] ?? 'No phone'); ?></div>
                                        </td>
                                        <td>
                                            <div class="small fw-medium"><?php echo htmlspecialchars($v['resident_name'] ?? 'General Visitor'); ?></div>
                                            <div class="small text-secondary">
                                                <?php 
                                                $dest = [];
                                                if (!empty($v['building_name'])) $dest[] = $v['building_name'];
                                                if (!empty($v['flat_number'])) $dest[] = 'Unit ' . $v['flat_number'];
                                                echo htmlspecialchars(!empty($dest) ? implode(' - ', $dest) : 'Main Gate');
                                                ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php 
                                            $st = $v['status'];
                                            if ($st === 'entered') {
                                                echo '<span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-arrow-right-to-bracket me-1"></i>Inside</span>';
                                            } elseif ($st === 'pre_registered') {
                                                echo '<span class="mature-badge mature-badge-primary"><i class="fa-solid fa-clock me-1"></i>Expected</span>';
                                            } elseif ($st === 'checked_out') {
                                                echo '<span class="mature-badge mature-badge-slate"><i class="fa-solid fa-arrow-right-from-bracket me-1"></i>Exited</span>';
                                            } else {
                                                echo '<span class="mature-badge mature-badge-amber">' . htmlspecialchars(ucfirst($st)) . '</span>';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-secondary">
                                        <i class="fa-solid fa-user-shield d-block mb-1 opacity-50" style="font-size: 1.5rem;"></i>
                                        No recent visitor activity recorded today.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Panel 2: Maintenance Work Orders Radar -->
    <div class="col-12 col-lg-6">
        <div class="mature-card">
            <div class="mature-card-header">
                <h3 class="mature-card-title">
                    <i class="fa-solid fa-screwdriver-wrench text-secondary"></i> Maintenance Work Orders
                </h3>
                <a href="maintenance" class="btn btn-sm btn-outline-secondary">
                    Dispatch Board <i class="fa-solid fa-arrow-right ms-1 small"></i>
                </a>
            </div>
            <div class="mature-card-body p-0">
                <div class="table-responsive">
                    <table class="table dashboard-table align-middle">
                        <thead>
                            <tr>
                                <th>Ticket</th>
                                <th>Location</th>
                                <th>Priority</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_maintenance && $recent_maintenance->num_rows > 0): ?>
                                <?php while ($m = $recent_maintenance->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-slate-900">#MR-<?php echo sprintf('%04d', $m['id']); ?></div>
                                            <div class="small text-secondary text-truncate" style="max-width: 170px;">
                                                <?php echo htmlspecialchars($m['title']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="small fw-medium">
                                                <?php echo htmlspecialchars($m['building_name'] ?? 'Estate'); ?>
                                            </div>
                                            <div class="small text-secondary">Unit <?php echo htmlspecialchars($m['flat_number'] ?? 'N/A'); ?></div>
                                        </td>
                                        <td>
                                            <?php 
                                            $p = strtolower($m['priority'] ?? 'low');
                                            if ($p === 'emergency') {
                                                echo '<span class="mature-badge mature-badge-crimson">Emergency</span>';
                                            } elseif ($p === 'high') {
                                                echo '<span class="mature-badge mature-badge-amber">High</span>';
                                            } else {
                                                echo '<span class="mature-badge mature-badge-slate">' . ucfirst($p) . '</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $mst = $m['status'];
                                            if ($mst === 'open') {
                                                echo '<span class="mature-badge mature-badge-crimson">Open</span>';
                                            } elseif ($mst === 'in_progress') {
                                                echo '<span class="mature-badge mature-badge-amber">In Progress</span>';
                                            } elseif ($mst === 'resolved') {
                                                echo '<span class="mature-badge mature-badge-emerald">Resolved</span>';
                                            } else {
                                                echo '<span class="mature-badge mature-badge-slate">Closed</span>';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-secondary">
                                        <i class="fa-solid fa-circle-check d-block mb-1 text-success opacity-75" style="font-size: 1.5rem;"></i>
                                        No active maintenance work orders pending.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Panel 3: Recent Financial Collections -->
    <div class="col-12 col-lg-6">
        <div class="mature-card">
            <div class="mature-card-header">
                <h3 class="mature-card-title">
                    <i class="fa-solid fa-receipt text-secondary"></i> Recent Financial Realizations
                </h3>
                <a href="finance" class="btn btn-sm btn-outline-secondary">
                    Finance Hub <i class="fa-solid fa-arrow-right ms-1 small"></i>
                </a>
            </div>
            <div class="mature-card-body p-0">
                <div class="table-responsive">
                    <table class="table dashboard-table align-middle">
                        <thead>
                            <tr>
                                <th>Transaction</th>
                                <th>Resident</th>
                                <th>Method</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_payments && $recent_payments->num_rows > 0): ?>
                                <?php while ($p = $recent_payments->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-slate-900 font-monospace" style="font-size: 0.8rem;">
                                                <?php echo htmlspecialchars($p['transaction_ref'] ?? ('TRX-' . $p['id'])); ?>
                                            </div>
                                            <div class="small text-secondary">
                                                <?php echo date('M d, Y', strtotime($p['paid_at'] ?? $p['created_at'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="small fw-medium"><?php echo htmlspecialchars($p['resident_name'] ?? 'Resident'); ?></div>
                                            <?php if (!empty($p['invoice_number'])): ?>
                                                <div class="small text-secondary"><?php echo htmlspecialchars($p['invoice_number']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="mature-badge mature-badge-slate">
                                                <?php echo htmlspecialchars(ucfirst($p['payment_method'] ?? 'Transfer')); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="fw-bold text-slate-900">
                                                <?php echo $currency_symbol . number_format($p['amount'], 2); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-secondary">
                                        <i class="fa-solid fa-file-invoice d-block mb-1 opacity-50" style="font-size: 1.5rem;"></i>
                                        No recent financial payments recorded yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Panel 4: Administrative Activity & Audit Feed -->
    <div class="col-12 col-lg-6">
        <div class="mature-card">
            <div class="mature-card-header">
                <h3 class="mature-card-title">
                    <i class="fa-solid fa-clock-rotate-left text-secondary"></i> System & Staff Activity
                </h3>
                <a href="audit_logs" class="btn btn-sm btn-outline-secondary">
                    Audit Log <i class="fa-solid fa-arrow-right ms-1 small"></i>
                </a>
            </div>
            <div class="mature-card-body">
                <?php if ($recent_audits && $recent_audits->num_rows > 0): ?>
                    <div class="activity-feed">
                        <?php while ($a = $recent_audits->fetch_assoc()): ?>
                            <div class="activity-feed-item">
                                <div class="activity-feed-dot"></div>
                                <div class="d-flex justify-content-between align-items-baseline">
                                    <span class="fw-semibold text-slate-900" style="font-size: 0.85rem;">
                                        <?php echo htmlspecialchars($a['action']); ?>
                                    </span>
                                    <span class="text-secondary" style="font-size: 0.75rem;">
                                        <?php echo date('M d, H:i', strtotime($a['timestamp'])); ?>
                                    </span>
                                </div>
                                <p class="text-secondary small mb-0 mt-0 text-truncate" style="max-width: 380px;">
                                    <?php echo htmlspecialchars($a['details'] ?? 'Action performed'); ?>
                                </p>
                                <div class="small text-secondary" style="font-size: 0.725rem;">
                                    By: <strong><?php echo htmlspecialchars($a['user_name'] ?? 'System'); ?></strong> 
                                    (<?php echo htmlspecialchars(ucfirst($a['user_role'] ?? 'Staff')); ?>) • <?php echo htmlspecialchars($a['module']); ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4 text-secondary">
                        <i class="fa-solid fa-shield-check d-block mb-1 opacity-50" style="font-size: 1.5rem;"></i>
                        No audit events recorded yet.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     OPERATIONAL QUICK-JUMP NAVIGATION DOCK
     ========================================== -->
<div class="mb-4">
    <div class="d-flex align-items-center justify-content-between mb-2">
        <h6 class="text-secondary fw-semibold text-uppercase small m-0" style="letter-spacing: 0.05em;">
            Administrative Portals & Tools
        </h6>
    </div>
    <div class="row g-2">
        <div class="col-6 col-md-4 col-xl-2">
            <a href="properties" class="quick-dock-btn">
                <div class="quick-dock-icon"><i class="fa-solid fa-building"></i></div>
                <span>Properties</span>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="owners" class="quick-dock-btn">
                <div class="quick-dock-icon"><i class="fa-solid fa-user-shield"></i></div>
                <span>Owners</span>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="staff" class="quick-dock-btn">
                <div class="quick-dock-icon"><i class="fa-solid fa-user-tie"></i></div>
                <span>Staff Roster</span>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="charges" class="quick-dock-btn">
                <div class="quick-dock-icon"><i class="fa-solid fa-list-check"></i></div>
                <span>Charges</span>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="email_logs" class="quick-dock-btn">
                <div class="quick-dock-icon"><i class="fa-solid fa-envelope-open-text"></i></div>
                <span>Email Logs</span>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="settings" class="quick-dock-btn">
                <div class="quick-dock-icon"><i class="fa-solid fa-gear"></i></div>
                <span>Settings</span>
            </a>
        </div>
    </div>
</div>

<!-- ==========================================
     CHART.JS INTEGRATION & CONFIGURATION
     ========================================== -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    let revChartInstance = null;
    let occChartInstance = null;

    function initDashboardCharts() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark' || document.body.classList.contains('dark-mode');
        const gridColor = isDark ? 'rgba(255, 255, 255, 0.06)' : 'rgba(15, 23, 42, 0.06)';
        const textColor = isDark ? '#94a3b8' : '#64748b';

        // 1. Revenue Velocity Trend Chart
        const revCtx = document.getElementById('revenueTrendChart');
        if (revCtx) {
            if (revChartInstance) revChartInstance.destroy();
            const revLabels = <?php echo json_encode($chart_revenue_labels); ?>;
            const revData = <?php echo json_encode($chart_revenue_values); ?>;
            const currency = <?php echo json_encode($currency_symbol); ?>;

            revChartInstance = new Chart(revCtx, {
                type: 'bar',
                data: {
                    labels: revLabels,
                    datasets: [{
                        label: 'Realized Revenue',
                        data: revData,
                        backgroundColor: isDark ? 'rgba(148, 163, 184, 0.45)' : 'rgba(51, 65, 85, 0.85)',
                        borderRadius: 6,
                        maxBarThickness: 38
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

        // 2. Unit Occupancy Capacity Breakdown Doughnut Chart
        const occCtx = document.getElementById('occupancyDoughnutChart');
        if (occCtx) {
            if (occChartInstance) occChartInstance.destroy();
            const occData = [
                <?php echo $occupied_flats; ?>, 
                <?php echo $vacant_flats; ?>, 
                <?php echo $maintenance_flats; ?>
            ];
            
            const hasData = occData.some(v => v > 0);
            const chartData = hasData ? occData : [0, 1, 0];

            occChartInstance = new Chart(occCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Occupied', 'Vacant', 'Maintenance'],
                    datasets: [{
                        data: chartData,
                        backgroundColor: [
                            isDark ? '#38bdf8' : '#0f172a', // Primary slate dark
                            isDark ? '#334155' : '#cbd5e1', // Slate light
                            isDark ? '#f59e0b' : '#d97706'  // Amber maintenance
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

    initDashboardCharts();

    // Re-render charts smoothly when theme changes
    document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            setTimeout(initDashboardCharts, 120);
        });
    });
});
</script>

<?php include '../includes/footer.php'; ?>
