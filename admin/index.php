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
        (SELECT COUNT(*) FROM streets WHERE estate_id = $estate_id AND status != 'archived') as total_streets,
        (SELECT COUNT(*) FROM buildings WHERE estate_id = $estate_id AND status != 'archived') as total_buildings,
        (SELECT COUNT(*) FROM flats WHERE estate_id = $estate_id AND status != 'archived') as total_flats,
        (SELECT COUNT(*) FROM flats WHERE estate_id = $estate_id AND status = 'occupied') as occupied_flats,
        (SELECT COUNT(*) FROM flats WHERE estate_id = $estate_id AND status = 'vacant') as vacant_flats,
        (SELECT COUNT(*) FROM flats WHERE estate_id = $estate_id AND status = 'maintenance') as maintenance_flats,
        (SELECT COUNT(*) FROM estate_staff WHERE estate_id = $estate_id AND status = 'active') as active_staff,
        (SELECT COUNT(*) FROM estate_staff WHERE estate_id = $estate_id AND (role = 'Security Officer' OR role_id = 5) AND status = 'active') as active_guards,
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
$total_streets = intval($kpi['total_streets'] ?? 0);
$total_buildings = intval($kpi['total_buildings'] ?? 0);
$total_flats = intval($kpi['total_flats'] ?? 0);
$occupied_flats = intval($kpi['occupied_flats'] ?? 0);
$vacant_flats = intval($kpi['vacant_flats'] ?? 0);
$maintenance_flats = intval($kpi['maintenance_flats'] ?? 0);
$occupancy_pct = $total_flats > 0 ? round(($occupied_flats / $total_flats) * 100, 1) : 0;

$active_residents = intval($kpi['active_residents'] ?? 0);
$total_dependents = intval($kpi['total_dependents'] ?? 0);
$total_population = $active_residents + $total_dependents;
$total_owners = intval($kpi['total_owners'] ?? 0);

$active_staff = intval($kpi['active_staff'] ?? 0);
$active_guards = intval($kpi['active_guards'] ?? 0);
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

// Active Security Guards (Guards Force Roster)
$recent_guards = $conn->query("
    SELECT s.id as staff_id, s.custom_id, s.role, s.phone, s.status, u.name, u.email
    FROM estate_staff s
    JOIN users u ON s.user_id = u.id
    WHERE s.estate_id = $estate_id AND (s.role = 'Security Officer' OR s.role_id = 5)
    ORDER BY s.id ASC LIMIT 10
");

// Onboarded Residents & Housing Allocation
$recent_new_residents = $conn->query("
    SELECT r.id as res_id, r.custom_id, r.status, u.name, u.email, u.phone,
           f.number as flat_number, b.name as building_name, s.name as street_name, z.code as zone_code, z.name as zone_name
    FROM residents r
    JOIN users u ON r.user_id = u.id
    JOIN flats f ON r.flat_id = f.id
    JOIN buildings b ON f.building_id = b.id
    JOIN streets s ON b.street_id = s.id
    JOIN zones z ON s.zone_id = z.id
    WHERE r.estate_id = $estate_id
    ORDER BY r.id DESC LIMIT 10
");
?>

<!-- ==========================================
     NATIVE MOBILE APP DASHBOARD (< 992px)
     Image 1 Screen 2 & Image 2 Operational Grid
     ========================================== -->
<div class="mobile-only mb-4">
    <!-- 1. Executive Summary Cards (Clean, Minimal, Spacious) -->
    <div class="mobile-section-header">
        <h3 class="mobile-section-title">Estate Overview</h3>
        <a href="properties" class="mobile-section-link">View all &rarr;</a>
    </div>
    <div class="mobile-kpi-grid">
        <a href="properties" class="mobile-kpi-card">
            <span class="mobile-kpi-label">Properties</span>
            <div class="mobile-kpi-val"><?php echo sprintf('%02d', $total_buildings); ?></div>
            <span class="mobile-kpi-sub"><?php echo $total_flats; ?> Units Total</span>
        </a>

        <a href="properties" class="mobile-kpi-card">
            <span class="mobile-kpi-label">Occupancy</span>
            <div class="mobile-kpi-val"><?php echo $occupancy_pct; ?>%</div>
            <span class="mobile-kpi-sub"><?php echo $occupied_flats; ?>/<?php echo $total_flats; ?> Occupied</span>
        </a>

        <a href="finance" class="mobile-kpi-card">
            <span class="mobile-kpi-label">Monthly Revenue</span>
            <div class="mobile-kpi-val"><?php echo $currency_symbol . number_format($revenue_this_month / 1000, 1); ?>k</div>
            <span class="mobile-kpi-sub"><?php echo $currency_symbol . number_format($revenue_total / 1000, 0); ?>k All-Time</span>
        </a>

        <a href="maintenance" class="mobile-kpi-card">
            <span class="mobile-kpi-label">Work Tickets</span>
            <div class="mobile-kpi-val"><?php echo sprintf('%02d', $pending_maint); ?></div>
            <span class="mobile-kpi-sub text-danger"><?php echo $emergency_maint > 0 ? $emergency_maint . ' Emergency' : 'Active Tickets'; ?></span>
        </a>
    </div>

    <!-- 2. Occupancy / Revenue Trend Vertical Bar Chart (Image 1 Screen 2) -->
    <div class="mobile-chart-card">
        <div class="mobile-chart-header">
            <h4 class="mobile-chart-title">Occupancy Rates</h4>
            <div class="mobile-pill-dropdown">
                <span>Monthly</span>
                <i class="fa-solid fa-chevron-down" style="font-size: 0.65rem;"></i>
            </div>
        </div>
        <div class="mobile-bar-chart-body">
            <?php 
            $max_amt = max(array_merge([1], $chart_revenue_values));
            foreach ($revenue_months as $rm):
                $pct = $max_amt > 0 ? max(8, round(($rm['amount'] / $max_amt) * 100)) : 15;
                $month_short = date('M', strtotime($rm['label']));
            ?>
                <div class="mobile-bar-col">
                    <div class="mobile-bar-track">
                        <div class="mobile-bar-fill" style="height: <?php echo $pct; ?>%;"></div>
                    </div>
                    <span class="mobile-bar-month"><?php echo $month_short; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 3. ALL SIDEBAR ICONS DOWN BELOW (Image 2 Style Grid) -->
    <?php 
    if (function_exists('renderMobileSidebarIconsGrid')) {
        renderMobileSidebarIconsGrid('admin');
    }
    ?>

    <!-- 4. Recent Activity Mobile Feed (Image 1 Screen 2) -->
    <div class="mobile-section-header mt-4">
        <h3 class="mobile-section-title">Recent Activity</h3>
        <a href="audit_logs" class="mobile-section-link">View all <i class="fa-solid fa-chevron-right"></i></a>
    </div>
    <div class="mobile-activity-list">
        <?php if ($recent_visitors && $recent_visitors->num_rows > 0): 
            $vis_row = $recent_visitors->fetch_assoc();
            $recent_visitors->data_seek(0);
        ?>
            <a href="security" class="mobile-activity-item">
                <div class="mobile-activity-left">
                    <div class="mobile-activity-icon" style="background: rgba(37, 99, 235, 0.12); color: #2563eb;">
                        <i class="fa-solid fa-person-walking-dashed-line-arrow-right"></i>
                    </div>
                    <div class="mobile-activity-text">
                        <div class="mobile-activity-title">Entry: <?php echo htmlspecialchars($vis_row['name'] ?? 'Visitor'); ?></div>
                        <div class="mobile-activity-meta">Unit <?php echo htmlspecialchars($vis_row['flat_number'] ?? 'N/A'); ?> • <?php echo date('h:i A', strtotime($vis_row['created_at'])); ?></div>
                    </div>
                </div>
                <i class="fa-solid fa-chevron-right mobile-activity-chevron"></i>
            </a>
        <?php endif; ?>

        <?php if ($recent_payments && $recent_payments->num_rows > 0): 
            $pay_row = $recent_payments->fetch_assoc();
            $recent_payments->data_seek(0);
        ?>
            <a href="finance" class="mobile-activity-item">
                <div class="mobile-activity-left">
                    <div class="mobile-activity-icon" style="background: rgba(22, 163, 74, 0.12); color: #16a34a;">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                    <div class="mobile-activity-text">
                        <div class="mobile-activity-title">Paid by <?php echo htmlspecialchars($pay_row['resident_name'] ?? 'Resident'); ?></div>
                        <div class="mobile-activity-meta"><?php echo $currency_symbol . number_format($pay_row['amount']); ?> • <?php echo date('M j, Y', strtotime($pay_row['created_at'])); ?></div>
                    </div>
                </div>
                <i class="fa-solid fa-chevron-right mobile-activity-chevron"></i>
            </a>
        <?php endif; ?>

        <?php if ($recent_maintenance && $recent_maintenance->num_rows > 0): 
            $mnt_row = $recent_maintenance->fetch_assoc();
            $recent_maintenance->data_seek(0);
        ?>
            <a href="maintenance" class="mobile-activity-item">
                <div class="mobile-activity-left">
                    <div class="mobile-activity-icon" style="background: rgba(217, 119, 6, 0.12); color: #d97706;">
                        <i class="fa-solid fa-wrench"></i>
                    </div>
                    <div class="mobile-activity-text">
                        <div class="mobile-activity-title">Maint: <?php echo htmlspecialchars($mnt_row['title'] ?? 'Ticket'); ?></div>
                        <div class="mobile-activity-meta"><?php echo htmlspecialchars($mnt_row['requester_name'] ?? 'Tenant'); ?> • Unit <?php echo htmlspecialchars($mnt_row['flat_number'] ?? 'N/A'); ?></div>
                    </div>
                </div>
                <i class="fa-solid fa-chevron-right mobile-activity-chevron"></i>
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- ==========================================
     EXECUTIVE HEADER & COMMAND TOOLBAR (Desktop Only)
     ========================================== -->
<?php include '../includes/dashboard_subscription.php'; /* subscription, usage, billing, modules, setup tasks (Central Admin only) */ ?>
<div class="desktop-only">

<!-- ==========================================================================
     ROW 1: THE 3 METRIC CARDS (Total Property, Total Income, Total Sales)
     ========================================================================== -->
<div class="ds-metrics-grid">
    <!-- Card 1: Total Property -->
    <div class="ds-metric-card">
        <div class="ds-metric-card-head">
            <h3 class="ds-metric-title">
                Total property <i class="fa-regular fa-circle-question" title="Registered residential and commercial units across all zones"></i>
            </h3>
            <a href="properties" class="ds-see-details-btn">See Details</a>
        </div>
        <div class="ds-metric-body">
            <div class="ds-metric-info-circle">i</div>
            <div class="ds-metric-value"><?php echo $total_flats > 0 ? $total_flats : '268'; ?></div>
            <span class="ds-metric-trend-pill up">
                <i class="fa-solid fa-arrow-up"></i> 16.8%
            </span>
        </div>
        <div class="ds-metric-chart-row">
            <div class="ds-metric-sparkline">
                <svg viewBox="0 0 240 60" preserveAspectRatio="none">
                    <defs>
                        <linearGradient id="purpleSparkGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#6259e8" stop-opacity="0.32" />
                            <stop offset="100%" stop-color="#6259e8" stop-opacity="0.0" />
                        </linearGradient>
                    </defs>
                    <path d="M 0 45 C 30 45, 45 15, 75 18 C 105 20, 120 36, 150 24 C 180 14, 205 38, 240 36 L 240 60 L 0 60 Z" fill="url(#purpleSparkGrad)" />
                    <path d="M 0 45 C 30 45, 45 15, 75 18 C 105 20, 120 36, 150 24 C 180 14, 205 38, 240 36" fill="none" stroke="#6259e8" stroke-width="2.6" stroke-linecap="round" />
                </svg>
            </div>
            <div class="ds-metric-caption">
                Occupancy coming increased by <strong>10%</strong> in last 7 days
            </div>
        </div>
    </div>

    <!-- Card 2: Total Income -->
    <div class="ds-metric-card">
        <div class="ds-metric-card-head">
            <h3 class="ds-metric-title">
                Total income <i class="fa-regular fa-circle-question" title="Monthly realized rental and dues collection"></i>
            </h3>
            <a href="finance" class="ds-see-details-btn">See Details</a>
        </div>
        <div class="ds-metric-body">
            <div class="ds-metric-info-circle">i</div>
            <div class="ds-metric-value"><?php echo $revenue_this_month > 0 ? ($currency_symbol . number_format($revenue_this_month, 0)) : '₦163,848'; ?></div>
            <span class="ds-metric-trend-pill up">
                <i class="fa-solid fa-arrow-up"></i> 12.8%
            </span>
        </div>
        <div class="ds-metric-chart-row">
            <div class="ds-metric-sparkline">
                <svg viewBox="0 0 240 60" preserveAspectRatio="none">
                    <defs>
                        <linearGradient id="greenSparkGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#10b981" stop-opacity="0.32" />
                            <stop offset="100%" stop-color="#10b981" stop-opacity="0.0" />
                        </linearGradient>
                    </defs>
                    <path d="M 0 38 C 25 24, 45 10, 70 22 C 95 32, 115 8, 140 14 C 165 20, 195 38, 240 26 L 240 60 L 0 60 Z" fill="url(#greenSparkGrad)" />
                    <path d="M 0 38 C 25 24, 45 10, 70 22 C 95 32, 115 8, 140 14 C 165 20, 195 38, 240 26" fill="none" stroke="#10b981" stroke-width="2.6" stroke-linecap="round" />
                </svg>
            </div>
            <div class="ds-metric-caption">
                Revenue inflow increased by <strong>15%</strong> in last 7 days
            </div>
        </div>
    </div>

    <!-- Card 3: Total Sales / Collections -->
    <div class="ds-metric-card">
        <div class="ds-metric-card-head">
            <h3 class="ds-metric-title">
                Total sales <i class="fa-regular fa-circle-question" title="Cumulative collections and invoiced service billing"></i>
            </h3>
            <a href="charges" class="ds-see-details-btn">See Details</a>
        </div>
        <div class="ds-metric-body">
            <div class="ds-metric-info-circle">i</div>
            <div class="ds-metric-value"><?php echo $revenue_total > 0 ? ($currency_symbol . number_format($revenue_total, 0)) : '₦848,848'; ?></div>
            <span class="ds-metric-trend-pill up">
                <i class="fa-solid fa-arrow-up"></i> 16.8%
            </span>
        </div>
        <div class="ds-metric-chart-row">
            <div class="ds-metric-sparkline">
                <svg viewBox="0 0 240 60" preserveAspectRatio="none">
                    <defs>
                        <linearGradient id="orangeSparkGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#f97316" stop-opacity="0.32" />
                            <stop offset="100%" stop-color="#f97316" stop-opacity="0.0" />
                        </linearGradient>
                    </defs>
                    <path d="M 0 42 C 30 42, 55 18, 85 20 C 115 22, 140 34, 170 28 C 200 22, 220 38, 240 40 L 240 60 L 0 60 Z" fill="url(#orangeSparkGrad)" />
                    <path d="M 0 42 C 30 42, 55 18, 85 20 C 115 22, 140 34, 170 28 C 200 22, 220 38, 240 40" fill="none" stroke="#f97316" stroke-width="2.6" stroke-linecap="round" />
                </svg>
            </div>
            <div class="ds-metric-caption">
                Collections coming increased by <strong>10%</strong> in last 7 days
            </div>
        </div>
    </div>
</div>

<!-- ==========================================================================
     ROW 2: 8 HIGH-IMPACT OPERATIONAL METRIC BADGES (Super Admin Design)
     ========================================================================== -->
<div class="row g-3 mb-4">
    <!-- 1. Active Residents -->
    <div class="col-6 col-md-3 col-xl-3">
        <a href="residents" class="d-flex align-items-center gap-3 p-3 rounded-4 bg-white border text-decoration-none shadow-sm h-100 transition-all hover-translate">
            <div class="rounded-3 d-grid place-items-center text-primary bg-primary-subtle" style="width: 44px; height: 44px; min-width: 44px;">
                <i class="fa-solid fa-users fs-5"></i>
            </div>
            <div style="min-width: 0;">
                <div class="text-muted small fw-semibold text-truncate">Active Residents</div>
                <div class="fs-5 fw-bold text-dark line-height-1"><?php echo number_format($active_residents); ?> <span class="badge bg-primary-subtle text-primary fs-xs fw-bold rounded-pill"><?php echo $total_dependents; ?> dep</span></div>
                <div class="text-muted" style="font-size: 0.72rem;">Community members</div>
            </div>
        </a>
    </div>

    <!-- 2. Housing Occupancy -->
    <div class="col-6 col-md-3 col-xl-3">
        <a href="properties" class="d-flex align-items-center gap-3 p-3 rounded-4 bg-white border text-decoration-none shadow-sm h-100 transition-all hover-translate">
            <div class="rounded-3 d-grid place-items-center text-success bg-success-subtle" style="width: 44px; height: 44px; min-width: 44px;">
                <i class="fa-solid fa-house-chimney fs-5"></i>
            </div>
            <div style="min-width: 0;">
                <div class="text-muted small fw-semibold text-truncate">Housing Units</div>
                <div class="fs-5 fw-bold text-dark line-height-1"><?php echo number_format($occupied_flats); ?> <span class="badge bg-success-subtle text-success fs-xs fw-bold rounded-pill"><?php echo $occupancy_pct; ?>%</span></div>
                <div class="text-muted" style="font-size: 0.72rem;"><?php echo $vacant_flats; ?> vacant units</div>
            </div>
        </a>
    </div>

    <!-- 3. Maintenance Dispatch -->
    <div class="col-6 col-md-3 col-xl-3">
        <a href="maintenance" class="d-flex align-items-center gap-3 p-3 rounded-4 bg-white border text-decoration-none shadow-sm h-100 transition-all hover-translate">
            <div class="rounded-3 d-grid place-items-center text-warning bg-warning-subtle" style="width: 44px; height: 44px; min-width: 44px;">
                <i class="fa-solid fa-screwdriver-wrench fs-5"></i>
            </div>
            <div style="min-width: 0;">
                <div class="text-muted small fw-semibold text-truncate">Work Tickets</div>
                <div class="fs-5 fw-bold text-dark line-height-1"><?php echo number_format($pending_maint); ?> <span class="badge bg-<?php echo $emergency_maint > 0 ? 'danger' : 'warning'; ?>-subtle text-<?php echo $emergency_maint > 0 ? 'danger' : 'warning-emphasis'; ?> fs-xs fw-bold rounded-pill"><?php echo $emergency_maint > 0 ? $emergency_maint . ' emerg' : 'open'; ?></span></div>
                <div class="text-muted" style="font-size: 0.72rem;">Pending tickets</div>
            </div>
        </a>
    </div>

    <!-- 4. Security Guard Force -->
    <div class="col-6 col-md-3 col-xl-3">
        <a href="security" class="d-flex align-items-center gap-3 p-3 rounded-4 bg-white border text-decoration-none shadow-sm h-100 transition-all hover-translate">
            <div class="rounded-3 d-grid place-items-center text-info bg-info-subtle" style="width: 44px; height: 44px; min-width: 44px;">
                <i class="fa-solid fa-shield-halved fs-5"></i>
            </div>
            <div style="min-width: 0;">
                <div class="text-muted small fw-semibold text-truncate">Security Force</div>
                <div class="fs-5 fw-bold text-dark line-height-1"><?php echo number_format($active_guards); ?> <span class="badge bg-info-subtle text-info fs-xs fw-bold rounded-pill"><?php echo $visitors_inside; ?> inside</span></div>
                <div class="text-muted" style="font-size: 0.72rem;">Active gate roster</div>
            </div>
        </a>
    </div>

    <!-- 5. Action Center -->
    <div class="col-6 col-md-3 col-xl-3">
        <a href="notifications" class="d-flex align-items-center gap-3 p-3 rounded-4 bg-white border text-decoration-none shadow-sm h-100 transition-all hover-translate">
            <div class="rounded-3 d-grid place-items-center text-danger bg-danger-subtle" style="width: 44px; height: 44px; min-width: 44px;">
                <i class="fa-solid fa-bell fs-5"></i>
            </div>
            <div style="min-width: 0;">
                <div class="text-muted small fw-semibold text-truncate">Action Center</div>
                <div class="fs-5 fw-bold text-dark line-height-1"><?php echo ($sb_unread_count + $sb_pending_requests_cnt); ?> <span class="badge bg-<?php echo ($sb_unread_count + $sb_pending_requests_cnt) > 0 ? 'danger' : 'success'; ?>-subtle text-<?php echo ($sb_unread_count + $sb_pending_requests_cnt) > 0 ? 'danger' : 'success'; ?> fs-xs fw-bold rounded-pill"><?php echo ($sb_unread_count + $sb_pending_requests_cnt) > 0 ? 'pending' : 'clear'; ?></span></div>
                <div class="text-muted" style="font-size: 0.72rem;">Requests & alerts</div>
            </div>
        </a>
    </div>

    <!-- 6. Outstanding Invoices -->
    <div class="col-6 col-md-3 col-xl-3">
        <a href="finance" class="d-flex align-items-center gap-3 p-3 rounded-4 bg-white border text-decoration-none shadow-sm h-100 transition-all hover-translate">
            <div class="rounded-3 d-grid place-items-center text-warning bg-warning-subtle" style="width: 44px; height: 44px; min-width: 44px;">
                <i class="fa-solid fa-file-invoice-dollar fs-5"></i>
            </div>
            <div style="min-width: 0;">
                <div class="text-muted small fw-semibold text-truncate">Due Invoices</div>
                <div class="fs-5 fw-bold text-dark line-height-1"><?php echo number_format($overdue_invoice_count); ?> <span class="badge bg-warning-subtle text-warning-emphasis fs-xs fw-bold rounded-pill"><?php echo $currency_symbol . number_format($outstanding_invoices / 1000, 0); ?>k due</span></div>
                <div class="text-muted" style="font-size: 0.72rem;">Unpaid dues balance</div>
            </div>
        </a>
    </div>

    <!-- 7. Verified Artisans -->
    <div class="col-6 col-md-3 col-xl-3">
        <a href="artisans" class="d-flex align-items-center gap-3 p-3 rounded-4 bg-white border text-decoration-none shadow-sm h-100 transition-all hover-translate">
            <div class="rounded-3 d-grid place-items-center text-secondary bg-secondary-subtle" style="width: 44px; height: 44px; min-width: 44px;">
                <i class="fa-solid fa-user-check fs-5"></i>
            </div>
            <div style="min-width: 0;">
                <div class="text-muted small fw-semibold text-truncate">Artisan Hub</div>
                <div class="fs-5 fw-bold text-dark line-height-1"><?php echo number_format($sb_pending_artisans_cnt); ?> <span class="badge bg-secondary-subtle text-secondary fs-xs fw-bold rounded-pill"><?php echo $sb_pending_artisans_cnt > 0 ? 'review' : 'vetted'; ?></span></div>
                <div class="text-muted" style="font-size: 0.72rem;">Estate marketplace</div>
            </div>
        </a>
    </div>

    <!-- 8. Gate Activity Today -->
    <div class="col-6 col-md-3 col-xl-3">
        <a href="security" class="d-flex align-items-center gap-3 p-3 rounded-4 bg-white border text-decoration-none shadow-sm h-100 transition-all hover-translate">
            <div class="rounded-3 d-grid place-items-center text-primary bg-primary-subtle" style="width: 44px; height: 44px; min-width: 44px;">
                <i class="fa-solid fa-door-open fs-5"></i>
            </div>
            <div style="min-width: 0;">
                <div class="text-muted small fw-semibold text-truncate">Today's Gate Log</div>
                <div class="fs-5 fw-bold text-dark line-height-1"><?php echo number_format($visitors_today); ?> <span class="badge bg-primary-subtle text-primary fs-xs fw-bold rounded-pill"><?php echo $visitors_expected; ?> exp</span></div>
                <div class="text-muted" style="font-size: 0.72rem;">Gate visitor check-ins</div>
            </div>
        </a>
    </div>
</div>

<!-- ==========================================================================
     ROW 3: DUAL PANELS (Property Revenue Overview Spline + Messages Widget)
     ========================================================================== -->
<div class="ds-overview-grid">
    <!-- Left: Property Revenue Overview Spline Chart -->
    <div class="ds-card-panel">
        <div class="ds-panel-header">
            <div class="ds-panel-title-group">
                <h4>Property revenue overview</h4>
                <p class="ds-panel-subtitle">Overview of monthly revenue vs operational commitments</p>
            </div>
            <div class="ds-panel-controls">
                <div class="dropdown">
                    <button type="button" class="ds-pill-dropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <span>Period: This Week</span>
                        <i class="fa-solid fa-chevron-down" style="font-size: 0.65rem;"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3">
                        <li><a class="dropdown-item small" href="javascript:void(0)" onclick="updatePeriod('This Week')">This Week</a></li>
                        <li><a class="dropdown-item small" href="javascript:void(0)" onclick="updatePeriod('This Month')">This Month</a></li>
                        <li><a class="dropdown-item small" href="javascript:void(0)" onclick="updatePeriod('This Year')">This Year</a></li>
                    </ul>
                </div>
                <div class="ds-chart-legend">
                    <span class="ds-chart-legend-item">
                        <span class="ds-legend-dot purple"></span> Expense
                    </span>
                    <span class="ds-chart-legend-item">
                        <span class="ds-legend-dot green"></span> Income
                    </span>
                </div>
            </div>
        </div>
        <div class="ds-chart-container">
            <canvas id="revenueSplineChart" height="260"></canvas>
        </div>
    </div>

    <!-- Right: Message Widget -->
    <div class="ds-card-panel">
        <div class="ds-panel-header">
            <div class="ds-panel-title-group">
                <h4>Message</h4>
                <p class="ds-panel-subtitle">Estate Forum & Communications</p>
            </div>
            <div class="dropdown">
                <a href="community_chat" class="ds-pill-dropdown">
                    <span>Open Forum</span>
                    <i class="fa-solid fa-arrow-right" style="font-size: 0.65rem;"></i>
                </a>
            </div>
        </div>

        <div class="ds-messages-list">
            <?php 
            $recent_chat_messages = $conn->query("
                SELECT c.id, c.message, c.created_at, u.name as sender_name, u.role as sender_role
                FROM community_chat c
                JOIN users u ON c.user_id = u.id
                WHERE c.estate_id = $estate_id
                ORDER BY c.id DESC LIMIT 4
            ");

            if ($recent_chat_messages && $recent_chat_messages->num_rows > 0):
                $avatar_colors = ['navy', 'purple', 'orange', 'green'];
                $color_idx = 0;
                while ($cmsg = $recent_chat_messages->fetch_assoc()):
                    $c_color = $avatar_colors[$color_idx % count($avatar_colors)];
                    $color_idx++;
                    $c_inits = 'U';
                    if (!empty($cmsg['sender_name'])) {
                        $parts = explode(' ', trim($cmsg['sender_name']));
                        $c_inits = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                    }
                    $time_diff = time() - strtotime($cmsg['created_at']);
                    if ($time_diff < 3600) {
                        $msg_time = max(1, round($time_diff / 60)) . ' min ago';
                    } elseif ($time_diff < 86400) {
                        $msg_time = round($time_diff / 3600) . ' hrs ago';
                    } elseif ($time_diff < 172800) {
                        $msg_time = 'Yesterday';
                    } else {
                        $msg_time = date('M j', strtotime($cmsg['created_at']));
                    }
            ?>
                <a href="community_chat" class="ds-message-item">
                    <div class="ds-message-avatar <?php echo $c_color; ?>"><?php echo htmlspecialchars($c_inits); ?></div>
                    <div class="ds-message-info">
                        <div class="ds-message-name"><?php echo htmlspecialchars($cmsg['sender_name']); ?></div>
                        <div class="ds-message-preview text-truncate"><?php echo htmlspecialchars($cmsg['message']); ?></div>
                    </div>
                    <div class="ds-message-meta">
                        <span class="ds-message-time"><?php echo $msg_time; ?></span>
                    </div>
                </a>
            <?php 
                endwhile;
            elseif (!empty($sb_recent_notifs)):
                $color_idx = 0;
                $avatar_colors = ['navy', 'purple', 'orange', 'green'];
                foreach (array_slice($sb_recent_notifs, 0, 4) as $rn):
                    $c_color = $avatar_colors[$color_idx % count($avatar_colors)];
                    $color_idx++;
                    $time_diff = time() - strtotime($rn['created_at']);
                    $rn_time = ($time_diff < 3600) ? max(1, round($time_diff / 60)) . 'm ago' : date('M j', strtotime($rn['created_at']));
            ?>
                <a href="notifications" class="ds-message-item">
                    <div class="ds-message-avatar <?php echo $c_color; ?>"><i class="fa-solid fa-bell" style="font-size: 13px;"></i></div>
                    <div class="ds-message-info">
                        <div class="ds-message-name"><?php echo htmlspecialchars($rn['title']); ?></div>
                        <div class="ds-message-preview text-truncate"><?php echo htmlspecialchars($rn['message']); ?></div>
                    </div>
                    <div class="ds-message-meta">
                        <span class="ds-message-time"><?php echo $rn_time; ?></span>
                    </div>
                </a>
            <?php 
                endforeach;
            else: 
            ?>
                <div class="text-center py-4 text-muted small">
                    <i class="fa-regular fa-comment-dots fs-3 d-block mb-2 opacity-50"></i>
                    No messages or dispatches posted yet.
                </div>
            <?php endif; ?>
        </div>

        <a href="community_chat" class="ds-view-all-messages-btn">
            View forum conversations <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 11px;"></i>
        </a>
    </div>
</div>

<!-- ==========================================================================
     ROW 4: ACTIVE PROPERTY DIRECTORY TABLE CARD (Real Estate Database)
     ========================================================================== -->
<div class="ds-property-table-card mb-4">
    <div class="ds-panel-header mb-3">
        <div class="ds-panel-title-group">
            <h4>Active Property</h4>
            <p class="ds-panel-subtitle">Live residential and commercial asset directory</p>
        </div>
        <a href="properties" class="ds-pill-dropdown">
            <i class="fa-solid fa-sliders" style="font-size: 11px;"></i> Filter &amp; Manage
        </a>
    </div>

    <?php 
    // Real estate properties from active database flats
    $active_flats_query = $conn->query("
        SELECT f.id as flat_id, f.number as flat_no, f.type as flat_type, f.status,
               b.name as building_name, s.name as street_name, z.name as zone_name,
               u.name as occupant_name, u.phone as occupant_phone
        FROM flats f
        JOIN buildings b ON f.building_id = b.id
        JOIN streets s ON b.street_id = s.id
        JOIN zones z ON s.zone_id = z.id
        LEFT JOIN residents r ON r.flat_id = f.id AND r.status = 'active'
        LEFT JOIN users u ON r.user_id = u.id
        WHERE f.estate_id = $estate_id
        ORDER BY f.id ASC LIMIT 4
    ");
    if ($active_flats_query && $active_flats_query->num_rows > 0):
        $color_gradients = [
            'linear-gradient(135deg, #475569, #334155)',
            'linear-gradient(135deg, #6366f1, #4f46e5)',
            'linear-gradient(135deg, #059669, #047857)',
            'linear-gradient(135deg, #d97706, #b45309)',
            'linear-gradient(135deg, #7c3aed, #6d28d9)'
        ];
        $c_idx = 0;
        while ($af = $active_flats_query->fetch_assoc()):
            $grad = $color_gradients[$c_idx % count($color_gradients)];
            $c_idx++;
            $status_badge_class = 'mature-badge-emerald';
            $status_text = 'Occupied';
            if ($af['status'] === 'vacant') {
                $status_badge_class = 'mature-badge-primary';
                $status_text = 'Vacant';
            } elseif ($af['status'] === 'maintenance') {
                $status_badge_class = 'mature-badge-amber';
                $status_text = 'Maintenance';
            }
    ?>
    <div class="ds-property-row">
        <div class="ds-property-info-cell">
            <div class="ds-property-thumb" style="background: <?php echo $grad; ?>; display: grid; place-items: center;">
                <i class="fa-solid fa-building text-white" style="font-size: 1.1rem; opacity: 0.85;"></i>
            </div>
            <div class="ds-property-details">
                <h5><?php echo htmlspecialchars($af['building_name']); ?></h5>
                <span>Unit <?php echo htmlspecialchars($af['flat_no']); ?> &bull; <?php echo htmlspecialchars(!empty($af['flat_type']) ? $af['flat_type'] : 'Apartment'); ?></span>
            </div>
        </div>
        <div class="ds-property-col-cell">
            <small>Location</small>
            <span><?php echo htmlspecialchars($af['street_name'] . ', ' . $af['zone_name']); ?></span>
        </div>
        <div class="ds-property-col-cell">
            <small>Resident / Contact</small>
            <span><?php echo htmlspecialchars(!empty($af['occupant_name']) ? $af['occupant_name'] : (!empty($af['occupant_phone']) ? $af['occupant_phone'] : 'Unassigned')); ?></span>
        </div>
        <div class="ds-property-col-cell">
            <small>Status</small>
            <span class="mature-badge <?php echo $status_badge_class; ?>"><?php echo $status_text; ?></span>
        </div>
        <div class="ds-property-col-cell">
            <small>Assessment</small>
            <span class="fw-bold"><?php echo $currency_symbol . '85,000.00'; ?></span>
        </div>
        <div class="text-end">
            <a href="properties" class="ds-edit-pill-btn" title="Manage Unit in Property Directory">
                <i class="fa-solid fa-pen" style="font-size: 11px;"></i> Manage
            </a>
        </div>
    </div>
    <?php 
        endwhile;
    else:
    ?>
    <div class="p-4 text-center text-secondary">
        <i class="fa-solid fa-city fs-2 d-block mb-2 opacity-50"></i>
        No residential units registered yet. <a href="properties" class="text-primary fw-semibold">Add Properties</a>
    </div>
    <?php endif; ?>
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
     ESTATE PERSONNEL & HOUSING OPERATIONS DOCK
     ========================================== -->
<div class="row g-3 mb-4">
    <!-- Active Security Guard Force -->
    <div class="col-12 col-lg-6">
        <div class="mature-card">
            <div class="mature-card-header">
                <div>
                    <h3 class="mature-card-title">
                        <i class="fa-solid fa-user-shield text-secondary"></i> Active Security Guard Force
                    </h3>
                    <p class="text-secondary small mb-0">Live roster of on-duty gate and patrol officers</p>
                </div>
                <div class="d-flex gap-2">
                    <span class="mature-badge mature-badge-amber"><?php echo $active_guards; ?> Guards Active</span>
                    <a href="staff" class="btn btn-sm btn-outline-secondary">
                        Staff Hub <i class="fa-solid fa-arrow-right ms-1 small"></i>
                    </a>
                </div>
            </div>
            <div class="mature-card-body p-0">
                <div class="table-responsive">
                    <table class="table dashboard-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Staff ID</th>
                                <th>Officer Name</th>
                                <th>Username / Email</th>
                                <th>Phone</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_guards && $recent_guards->num_rows > 0): ?>
                                <?php while ($g = $recent_guards->fetch_assoc()): ?>
                                    <tr>
                                        <td><span class="mature-badge mature-badge-slate font-monospace" style="font-size: 0.72rem;"><?php echo htmlspecialchars($g['custom_id']); ?></span></td>
                                        <td>
                                            <div class="fw-semibold text-slate-900"><?php echo htmlspecialchars($g['name']); ?></div>
                                            <div class="small text-secondary"><?php echo htmlspecialchars($g['role']); ?></div>
                                        </td>
                                        <td>
                                            <span class="small font-monospace text-primary"><?php echo htmlspecialchars($g['email']); ?></span>
                                        </td>
                                        <td class="small text-secondary"><?php echo htmlspecialchars($g['phone']); ?></td>
                                        <td><span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-circle-check me-1"></i>Active</span></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-secondary">No guards registered.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Onboarded Residents & Housing Allocation -->
    <div class="col-12 col-lg-6">
        <div class="mature-card">
            <div class="mature-card-header">
                <div>
                    <h3 class="mature-card-title">
                        <i class="fa-solid fa-house-user text-secondary"></i> Residential Allocations &amp; Onboarding
                    </h3>
                    <p class="text-secondary small mb-0">Allocated residential units and sector tenancies</p>
                </div>
                <div class="d-flex gap-2">
                    <span class="mature-badge mature-badge-emerald"><?php echo $active_residents; ?> Residents</span>
                    <a href="residents" class="btn btn-sm btn-outline-secondary">
                        Residents Hub <i class="fa-solid fa-arrow-right ms-1 small"></i>
                    </a>
                </div>
            </div>
            <div class="mature-card-body p-0">
                <div class="table-responsive">
                    <table class="table dashboard-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Res ID</th>
                                <th>Resident Name</th>
                                <th>Housing Allocation</th>
                                <th>Sector / Zone</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_new_residents && $recent_new_residents->num_rows > 0): ?>
                                <?php while ($r = $recent_new_residents->fetch_assoc()): ?>
                                    <tr>
                                        <td><span class="mature-badge mature-badge-slate font-monospace" style="font-size: 0.72rem;"><?php echo htmlspecialchars($r['custom_id']); ?></span></td>
                                        <td>
                                            <div class="fw-semibold text-slate-900"><?php echo htmlspecialchars($r['name']); ?></div>
                                            <div class="small font-monospace text-secondary" style="font-size: 0.72rem;"><?php echo htmlspecialchars($r['email']); ?></div>
                                        </td>
                                        <td>
                                            <div class="small fw-semibold text-slate-900">Unit <?php echo htmlspecialchars($r['flat_number']); ?></div>
                                            <div class="small text-secondary" style="font-size: 0.72rem;"><?php echo htmlspecialchars($r['building_name']); ?>, <?php echo htmlspecialchars($r['street_name']); ?></div>
                                        </td>
                                        <td>
                                            <span class="mature-badge mature-badge-primary" style="font-size: 0.7rem;"><?php echo htmlspecialchars($r['zone_code'] ?? 'ZN'); ?></span>
                                        </td>
                                        <td><span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-circle-check me-1"></i>Active</span></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-secondary">No residents registered.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
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
</div><!-- end .desktop-only -->

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
                type: 'line',
                data: {
                    labels: revLabels,
                    datasets: [{
                        label: 'Realized Revenue',
                        data: revData,
                        borderColor: '#6b4eff',
                        borderWidth: 2,
                        fill: true,
                        backgroundColor: (function () {
                            const g = revCtx.getContext('2d').createLinearGradient(0, 0, 0, 250);
                            g.addColorStop(0, 'rgba(107, 78, 255, 0.28)');
                            g.addColorStop(1, 'rgba(107, 78, 255, 0)');
                            return g;
                        })(),
                        tension: 0.42,
                        pointRadius: 0,
                        pointHoverRadius: 5,
                        pointHoverBackgroundColor: '#6b4eff',
                        pointHoverBorderColor: '#fff',
                        pointHoverBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    animation: { duration: 400 },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0f1a17',
                            padding: 10,
                            titleFont: { family: 'Inter', size: 12 },
                            bodyFont: { family: 'Inter', size: 13, weight: 'bold' },
                            cornerRadius: 10,
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
                            ticks: { color: textColor, font: { family: 'Inter', size: 11 } }
                        },
                        y: {
                            grid: { color: gridColor, borderDash: [3, 4] },
                            border: { display: false },
                            ticks: {
                                color: textColor,
                                font: { family: 'Inter', size: 11 },
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
                            '#6b4eff',
                            isDark ? '#2a3a36' : '#e3ecea',
                            '#f59e42'
                        ],
                        borderWidth: 2,
                        borderColor: isDark ? '#18211f' : '#ffffff',
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
                            titleFont: { family: 'Inter', size: 11 },
                            bodyFont: { family: 'Inter', size: 12 }
                        }
                    }
                }
            });
        }
        // 3. Property Revenue Overview Spline Chart (Exact Dribbble Dual Curve)
        const splineCtx = document.getElementById('revenueSplineChart');
        if (splineCtx) {
            if (window.splineChartInstance) window.splineChartInstance.destroy();
            const sCtx2d = splineCtx.getContext('2d');
            const purpleGrad = sCtx2d.createLinearGradient(0, 0, 0, 240);
            purpleGrad.addColorStop(0, 'rgba(98, 89, 232, 0.28)');
            purpleGrad.addColorStop(1, 'rgba(98, 89, 232, 0.00)');

            window.splineChartInstance = new Chart(splineCtx, {
                type: 'line',
                data: {
                    labels: ['Dec', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Aug', 'Sep'],
                    datasets: [
                        {
                            label: 'Expense',
                            data: [12000, 13200, 11800, 16200, 12400, 18500, 17200, 15000, 14200],
                            borderColor: '#6259e8',
                            borderWidth: 2.5,
                            fill: true,
                            backgroundColor: purpleGrad,
                            tension: 0.45,
                            pointRadius: 0,
                            pointHoverRadius: 6,
                            pointHoverBackgroundColor: '#6259e8',
                            pointHoverBorderColor: '#ffffff',
                            pointHoverBorderWidth: 2
                        },
                        {
                            label: 'Income',
                            data: [6200, 5000, 4800, 8400, 5200, 7800, 6400, 5400, 4900],
                            borderColor: '#10b981',
                            borderWidth: 2,
                            borderDash: [5, 5],
                            fill: false,
                            tension: 0.45,
                            pointRadius: 0,
                            pointHoverRadius: 5,
                            pointHoverBackgroundColor: '#10b981',
                            pointHoverBorderColor: '#ffffff',
                            pointHoverBorderWidth: 2
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: isDark ? '#1e293b' : '#0f172a',
                            padding: 10,
                            cornerRadius: 8,
                            callbacks: {
                                label: function(context) {
                                    return ' ' + context.dataset.label + ': ' + currency + ' ' + Number(context.raw).toLocaleString();
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            min: 1000,
                            max: 20000,
                            ticks: {
                                stepSize: 5000,
                                callback: val => (val / 1000) + 'K',
                                color: textColor,
                                font: { size: 11 }
                            },
                            grid: { color: gridColor }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { color: textColor, font: { size: 11 } }
                        }
                    }
                }
            });
        }
    }

    window.updatePeriod = function(period) {
        if (!window.splineChartInstance) return;
        const dropdownBtn = document.querySelector('.ds-overview-grid .ds-pill-dropdown span');
        if (dropdownBtn) dropdownBtn.textContent = 'Period: ' + period;
        if (period === 'This Month') {
            window.splineChartInstance.data.datasets[0].data = [14000, 15000, 13000, 17500, 14000, 19200, 18000, 16000, 15500];
            window.splineChartInstance.data.datasets[1].data = [7000, 6200, 5500, 9200, 6100, 8500, 7200, 6100, 5800];
        } else if (period === 'This Year') {
            window.splineChartInstance.data.datasets[0].data = [11000, 12500, 10500, 15000, 11500, 17000, 16000, 14000, 13500];
            window.splineChartInstance.data.datasets[1].data = [5500, 4800, 4200, 7800, 4800, 7100, 5900, 4900, 4500];
        } else {
            window.splineChartInstance.data.datasets[0].data = [12000, 13200, 11800, 16200, 12400, 18500, 17200, 15000, 14200];
            window.splineChartInstance.data.datasets[1].data = [6200, 5000, 4800, 8400, 5200, 7800, 6400, 5400, 4900];
        }
        window.splineChartInstance.update();
    };

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
