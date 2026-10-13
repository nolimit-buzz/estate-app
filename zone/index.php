<?php
// zone/index.php - Executive Zonal Command Center Dashboard
require_once '../config.php';
require_once '../includes/auth_guard.php';

requireZoneAccess();

$zone_id = get_current_zone_id();
$estate_id = get_estate_id();
$current_user_name = $_SESSION['name'] ?? 'Zone Admin';

// Fetch Zone Metadata
$zone_res = $conn->query("SELECT * FROM zones WHERE id = $zone_id AND estate_id = $estate_id LIMIT 1");
$zone = ($zone_res && $zone_res->num_rows > 0) ? $zone_res->fetch_assoc() : null;

if (!$zone) {
    die("<div style='font-family:sans-serif;padding:3rem;text-align:center;'>
            <h2>Invalid Zone Assignment</h2>
            <p>Your account is not assigned to a valid zone. Please contact Central Administration.</p>
            <a href='../logout'>Sign Out</a>
         </div>");
}

$zone_name = $zone['name'];
$zone_code = $zone['code'];

// System Currency Setting
$currency_symbol = '₦';
$settings_query = $conn->query("SELECT setting_value FROM system_settings WHERE estate_id = $estate_id AND setting_key = 'currency_symbol' LIMIT 1");
if ($settings_query && $row = $settings_query->fetch_assoc()) {
    if (!empty($row['setting_value'])) $currency_symbol = $row['setting_value'];
}

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
// 1. SCOPED ZONAL KPIS
// -------------------------------------------------------------
$kpi_res = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM streets WHERE zone_id = $zone_id AND status != 'archived') as total_streets,
        (SELECT COUNT(DISTINCT b.id) FROM buildings b JOIN streets s ON b.street_id = s.id WHERE s.zone_id = $zone_id AND b.status != 'archived') as total_buildings,
        (SELECT COUNT(DISTINCT f.id) FROM flats f JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE s.zone_id = $zone_id AND f.status != 'archived') as total_flats,
        (SELECT COUNT(DISTINCT f.id) FROM flats f JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE s.zone_id = $zone_id AND f.status = 'occupied') as occupied_flats,
        (SELECT COUNT(DISTINCT f.id) FROM flats f JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE s.zone_id = $zone_id AND f.status = 'vacant') as vacant_flats,
        (SELECT COUNT(DISTINCT f.id) FROM flats f JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE s.zone_id = $zone_id AND f.status = 'maintenance') as maintenance_flats,
        (SELECT COUNT(DISTINCT r.id) FROM residents r JOIN flats f ON r.flat_id = f.id JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE s.zone_id = $zone_id AND r.status = 'active') as active_residents,
        (SELECT COUNT(DISTINCT r.id) FROM residents r JOIN flats f ON r.flat_id = f.id JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE s.zone_id = $zone_id AND r.status = 'active' AND r.type = 'dependent') as total_dependents,
        (SELECT COUNT(DISTINCT po.id) FROM property_owners po 
            JOIN owner_properties op ON po.id = op.owner_id 
            LEFT JOIN buildings b ON op.property_type = 'building' AND op.property_id = b.id 
            LEFT JOIN flats f ON op.property_type = 'flat' AND op.property_id = f.id 
            LEFT JOIN buildings fb ON f.building_id = fb.id 
            LEFT JOIN streets s ON (b.street_id = s.id OR fb.street_id = s.id) 
            WHERE s.zone_id = $zone_id) as total_owners,
        (SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN invoices i ON p.invoice_id = i.id WHERE i.zone_id = $zone_id AND p.status = 'paid' AND MONTH(COALESCE(p.paid_at, p.transaction_date)) = MONTH(CURDATE()) AND YEAR(COALESCE(p.paid_at, p.transaction_date)) = YEAR(CURDATE())) as revenue_month,
        (SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN invoices i ON p.invoice_id = i.id WHERE i.zone_id = $zone_id AND p.status = 'paid') as revenue_total,
        (SELECT COALESCE(SUM(balance), 0) FROM invoices WHERE zone_id = $zone_id AND status IN ('pending','unpaid','partially_paid','overdue')) as outstanding_invoices,
        (SELECT COUNT(*) FROM invoices WHERE zone_id = $zone_id AND status = 'overdue') as overdue_count
");
$kpi = $kpi_res ? $kpi_res->fetch_assoc() : [];

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

$revenue_month = floatval($kpi['revenue_month'] ?? 0);
$revenue_total = floatval($kpi['revenue_total'] ?? 0);
$outstanding_invoices = floatval($kpi['outstanding_invoices'] ?? 0);
$overdue_count = intval($kpi['overdue_count'] ?? 0);

// -------------------------------------------------------------
// 2. 6-MONTH REVENUE VELOCITY DATASET (Chart.js)
// -------------------------------------------------------------
$revenue_months = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("-$i months"));
    $label = date('M Y', strtotime("-$i months"));
    $revenue_months[$key] = ['label' => $label, 'amount' => 0.0];
}
$rev_chart_res = $conn->query("
    SELECT DATE_FORMAT(COALESCE(p.paid_at, p.transaction_date), '%Y-%m') as ym, SUM(p.amount) as amt 
    FROM payments p 
    JOIN invoices i ON p.invoice_id = i.id 
    WHERE i.zone_id = $zone_id AND p.status = 'paid' 
      AND COALESCE(p.paid_at, p.transaction_date) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
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
// 3. OPERATIONAL FEEDS & RECENT ACTIVITIES
// -------------------------------------------------------------
// Recent Invoices Scoped to Zone
$recent_invoices = $conn->query("
    SELECT i.*, u.name as resident_name, f.number as flat_number, b.name as building_name
    FROM invoices i
    LEFT JOIN users u ON i.user_id = u.id
    LEFT JOIN flats f ON i.flat_id = f.id
    LEFT JOIN buildings b ON f.building_id = b.id
    WHERE i.zone_id = $zone_id
    ORDER BY i.created_at DESC
    LIMIT 5
");

// Recent Residents in Zone
$recent_residents = $conn->query("
    SELECT r.*, u.name as resident_name, u.email as resident_email, u.phone as resident_phone,
           f.number as flat_number, b.name as building_name, s.name as street_name
    FROM residents r
    JOIN users u ON r.user_id = u.id
    JOIN flats f ON r.flat_id = f.id
    JOIN buildings b ON f.building_id = b.id
    JOIN streets s ON b.street_id = s.id
    WHERE s.zone_id = $zone_id AND r.status = 'active'
    ORDER BY r.created_at DESC
    LIMIT 5
");

// Recent Realized Payments in Zone
$recent_payments = $conn->query("
    SELECT p.*, u.name as resident_name, i.invoice_number 
    FROM payments p 
    JOIN invoices i ON p.invoice_id = i.id 
    LEFT JOIN users u ON p.user_id = u.id 
    WHERE i.zone_id = $zone_id AND p.status = 'paid'
    ORDER BY p.id DESC LIMIT 5
");

// Street Infrastructure Summary
$street_overview = $conn->query("
    SELECT s.id, s.name as street_name, s.custom_id,
           COUNT(DISTINCT b.id) as buildings_count,
           COUNT(DISTINCT f.id) as flats_count,
           COUNT(DISTINCT CASE WHEN f.status = 'occupied' THEN f.id END) as occupied_count
    FROM streets s
    LEFT JOIN buildings b ON b.street_id = s.id AND b.status != 'archived'
    LEFT JOIN flats f ON f.building_id = b.id AND f.status != 'archived'
    WHERE s.zone_id = $zone_id AND s.status != 'archived' AND s.estate_id = $estate_id
    GROUP BY s.id
    ORDER BY s.name ASC LIMIT 5
");

include 'header.php';
include 'sidebar.php';
?>

<style>
/* Executive Zonal Command Hero Board */
.zonal-hero-board {
    background: linear-gradient(135deg, #4a31cc 0%, #5a3fe6 50%, #6b4eff 100%) !important;
    border: 1px solid rgba(255, 255, 255, 0.25) !important;
    border-radius: 16px !important;
    box-shadow: 0 10px 30px -5px rgba(107, 78, 255, 0.4) !important;
    color: #ffffff !important;
    position: relative;
    overflow: hidden;
    padding: 1.75rem !important;
    margin-bottom: 1.5rem !important;
}
.zonal-hero-board::before {
    content: '';
    position: absolute;
    top: -60px;
    right: -60px;
    width: 340px;
    height: 340px;
    background: radial-gradient(circle, rgba(169, 155, 255, 0.25) 0%, transparent 70%);
    pointer-events: none;
    border-radius: 50%;
}
.zonal-hero-board h2 {
    color: #ffffff !important;
    font-size: 1.65rem;
    font-weight: 800;
    letter-spacing: -0.02em;
    margin-bottom: 0.35rem;
}
.zonal-hero-code-badge {
    background: #ffffff !important;
    color: #581c87 !important;
    font-size: 0.85rem !important;
    font-weight: 800 !important;
    padding: 0.3rem 0.65rem !important;
    border-radius: 8px !important;
    display: inline-block;
    vertical-align: middle;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}
.zonal-hero-motto {
    color: rgba(255, 255, 255, 0.95) !important;
    font-style: italic;
    font-size: 0.92rem;
    margin-bottom: 0.65rem;
}
.zonal-hero-chips {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
}
.zonal-hero-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    background: rgba(255, 255, 255, 0.16) !important;
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.28) !important;
    color: #ffffff !important;
    font-size: 0.8rem;
    font-weight: 500;
    padding: 0.25rem 0.75rem;
    border-radius: 999px;
}
.zonal-hero-chip i {
    color: rgba(255, 255, 255, 0.9);
}
.zonal-hero-desc {
    color: rgba(255, 255, 255, 0.92) !important;
    font-size: 0.875rem;
    line-height: 1.55;
    max-width: 680px;
    margin-bottom: 0;
}
.zonal-hero-btn-white {
    background: #ffffff !important;
    color: #581c87 !important;
    font-weight: 700 !important;
    font-size: 0.875rem;
    padding: 0.55rem 1.15rem;
    border-radius: 10px;
    border: none !important;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.18);
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s ease;
    text-decoration: none;
}
.zonal-hero-btn-white:hover {
    background: #f8fafc !important;
    color: #4a31cc !important;
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
}
.zonal-hero-btn-glass {
    background: rgba(255, 255, 255, 0.16) !important;
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    border: 1.5px solid rgba(255, 255, 255, 0.35) !important;
    color: #ffffff !important;
    font-weight: 600 !important;
    font-size: 0.875rem;
    padding: 0.52rem 1.05rem;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s ease;
    text-decoration: none;
}
.zonal-hero-btn-glass:hover {
    background: rgba(255, 255, 255, 0.28) !important;
    border-color: rgba(255, 255, 255, 0.6) !important;
    color: #ffffff !important;
    transform: translateY(-1px);
}

/* Paystack Dedicated Virtual Account Card High-Contrast Support */
.zonal-dva-account-num {
    font-family: monospace;
    font-size: 1.35rem;
    font-weight: 800;
    letter-spacing: 0.05em;
    color: #0f172a;
}
[data-theme="dark"] .zonal-dva-account-num,
body.dark-mode .zonal-dva-account-num {
    color: #f8fafc !important;
}

/* Amber alert contrast in dark mode */
[data-theme="dark"] .zonal-amber-alert-title,
body.dark-mode .zonal-amber-alert-title {
    color: #fbbf24 !important;
}
[data-theme="dark"] .zonal-amber-alert-sub,
body.dark-mode .zonal-amber-alert-sub {
    color: #fef3c7 !important;
}

/* KPI Subtitle & Unit Labels Contrast */
.kpi-unit-label {
    font-size: 0.95rem;
    font-weight: 500;
    color: #64748b;
}
[data-theme="dark"] .kpi-unit-label,
body.dark-mode .kpi-unit-label {
    color: #94a3b8 !important;
}
[data-theme="dark"] .kpi-meta span,
body.dark-mode .kpi-meta span {
    color: #cbd5e1 !important;
}
[data-theme="dark"] .kpi-title,
body.dark-mode .kpi-title {
    color: #cbd5e1 !important;
}
</style>

<!-- ==========================================
     NATIVE MOBILE APP DASHBOARD (< 992px)
     Image 1 Screen 2 & Image 2 Operational Grid
     ========================================== -->
<div class="mobile-only mb-4">
    <!-- 1. 2x2 Metric Summary Cards -->
    <div class="mobile-section-header">
        <h3 class="mobile-section-title">Zone Overview</h3>
        <a href="properties" class="mobile-section-link">View all &rarr;</a>
    </div>
    <div class="mobile-kpi-grid">
        <a href="properties" class="mobile-kpi-card">
            <span class="mobile-kpi-label">Buildings</span>
            <div class="mobile-kpi-val"><?php echo sprintf('%02d', $total_buildings); ?></div>
            <span class="mobile-kpi-sub"><?php echo $total_flats; ?> Units Total</span>
        </a>

        <a href="properties" class="mobile-kpi-card">
            <span class="mobile-kpi-label">Occupancy</span>
            <div class="mobile-kpi-val"><?php echo $occupied_flats; ?><span style="font-size: 0.85rem; font-weight: 500; color: #94a3b8;">/<?php echo $total_flats; ?></span></div>
            <span class="mobile-kpi-sub">Units Occupied</span>
        </a>

        <a href="finance" class="mobile-kpi-card">
            <span class="mobile-kpi-label">Month Revenue</span>
            <div class="mobile-kpi-val"><?php echo $currency_symbol . number_format($revenue_month / 1000, 1); ?>k</div>
            <span class="mobile-kpi-sub"><?php echo $currency_symbol . number_format($revenue_total / 1000, 0); ?>k Total</span>
        </a>

        <a href="residents" class="mobile-kpi-card">
            <span class="mobile-kpi-label">Residents</span>
            <div class="mobile-kpi-val"><?php echo sprintf('%02d', $active_residents); ?></div>
            <span class="mobile-kpi-sub"><?php echo $total_population; ?> Total Housed</span>
        </a>
    </div>

    <!-- 2. Occupancy / Revenue Trend Vertical Bar Chart (Image 1 Screen 2) -->
    <div class="mobile-chart-card">
        <div class="mobile-chart-header">
            <h4 class="mobile-chart-title">Revenue Velocity</h4>
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
                        <div class="mobile-bar-fill" style="height: <?php echo $pct; ?>%; background: linear-gradient(180deg, #6b4eff 0%, #a99bff 100%);"></div>
                    </div>
                    <span class="mobile-bar-month"><?php echo $month_short; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 3. ALL ZONE SIDEBAR ICONS DOWN BELOW (Image 2 Style Grid) -->
    <?php 
    if (function_exists('renderMobileSidebarIconsGrid')) {
        renderMobileSidebarIconsGrid('zone');
    }
    ?>

    <!-- 4. Recent Zonal Activity Feed -->
    <div class="mobile-section-header mt-4">
        <h3 class="mobile-section-title">Recent Zonal Activity</h3>
        <a href="residents" class="mobile-section-link">View all <i class="fa-solid fa-chevron-right"></i></a>
    </div>
    <div class="mobile-activity-list">
        <?php if ($recent_residents && $recent_residents->num_rows > 0): 
            $r_row = $recent_residents->fetch_assoc();
            $recent_residents->data_seek(0);
        ?>
            <a href="residents" class="mobile-activity-item">
                <div class="mobile-activity-left">
                    <div class="mobile-activity-icon" style="background: rgba(107, 78, 255, 0.12); color: #6b4eff;">
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                    <div class="mobile-activity-text">
                        <div class="mobile-activity-title"><?php echo htmlspecialchars($r_row['resident_name']); ?></div>
                        <div class="mobile-activity-meta">Unit <?php echo htmlspecialchars($r_row['flat_number']); ?> • <?php echo htmlspecialchars($r_row['building_name']); ?></div>
                    </div>
                </div>
                <i class="fa-solid fa-chevron-right mobile-activity-chevron"></i>
            </a>
        <?php endif; ?>

        <?php if ($recent_payments && $recent_payments->num_rows > 0): 
            $p_row = $recent_payments->fetch_assoc();
            $recent_payments->data_seek(0);
        ?>
            <a href="finance" class="mobile-activity-item">
                <div class="mobile-activity-left">
                    <div class="mobile-activity-icon" style="background: rgba(22, 163, 74, 0.12); color: #16a34a;">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                    <div class="mobile-activity-text">
                        <div class="mobile-activity-title">Settled: <?php echo htmlspecialchars($p_row['resident_name'] ?? 'Resident'); ?></div>
                        <div class="mobile-activity-meta"><?php echo $currency_symbol . number_format($p_row['amount']); ?> • <?php echo htmlspecialchars($p_row['invoice_number'] ?? 'INV'); ?></div>
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
<div class="desktop-only">
<div class="hero-header-enterprise">
    <div class="hero-title-group">
        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
            <h1><?php echo $greeting; ?>, <?php echo htmlspecialchars($current_user_name); ?></h1>
            <span class="mature-badge mature-badge-purple">
                <i class="fa-solid fa-shield-halved me-1"></i> Zone <?php echo htmlspecialchars($zone_code); ?> Command
            </span>
            <span class="mature-badge mature-badge-slate">
                <i class="fa-solid fa-layer-group me-1"></i> Isolated Scope
            </span>
        </div>
        <div class="hero-meta-strip">
            <span><i class="fa-regular fa-calendar me-1"></i> <?php echo date('l, F j, Y'); ?></span>
            <span>•</span>
            <span><i class="fa-solid fa-location-dot me-1"></i> <?php echo htmlspecialchars($zone_name); ?></span>
            <span>•</span>
            <span class="text-success"><i class="fa-solid fa-circle me-1" style="font-size: 0.5rem;"></i> Sector Active</span>
        </div>
    </div>
    <div class="hero-actions-group">
        <div class="pill-timeframe-dropdown" title="Reporting Period">
            <i class="fa-regular fa-calendar-days text-purple" style="color: #6b4eff;"></i>
            <span><?php echo date('M Y'); ?></span>
            <i class="fa-solid fa-chevron-down" style="font-size: 0.65rem;"></i>
        </div>
        <a href="reports" class="btn-export-ghost" title="Zonal Financial & Resident Reports">
            <i class="fa-solid fa-chart-pie text-purple" style="color: #6b4eff;"></i> Zonal Reports
        </a>
        <a href="residents" class="btn-primary-action-pill" style="background: linear-gradient(135deg, #6b4eff 0%, #5a3fe6 100%); box-shadow: 0 4px 14px -2px rgba(107, 78, 255, 0.4);">
            <i class="fa-solid fa-user-plus"></i> Onboard Resident
        </a>
        <a href="broadcasts" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-2 fw-semibold">
            <i class="fa-solid fa-bullhorn me-1"></i> Broadcast
        </a>
    </div>
</div>

<!-- Zonal Autonomy & Security Privilege Ribbon -->
<div class="card border-0 rounded-4 p-3 mb-4 shadow-sm" style="background: linear-gradient(135deg, rgba(107, 78, 255, 0.08) 0%, rgba(59, 130, 246, 0.05) 100%); border: 1px solid rgba(107, 78, 255, 0.2) !important;">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <span class="badge rounded-pill px-2.5 py-1" style="background: rgba(107, 78, 255, 0.2); color: #6b4eff; font-size: 0.72rem; font-weight: 700;">
                <i class="fa-solid fa-lock me-1"></i> ZONAL PRIVACY LOCK
            </span>
            <span class="text-secondary small fw-semibold">Strict Role-Based Scoping Active for <?= htmlspecialchars($zone_name) ?></span>
        </div>
        <div class="d-flex align-items-center gap-3 flex-wrap small text-secondary">
            <span><i class="fa-solid fa-circle-check text-success me-1"></i> Zero Cross-Zone Data Leakage</span>
            <span><i class="fa-solid fa-circle-check text-success me-1"></i> Street-Level Accounting</span>
            <span><i class="fa-solid fa-circle-check text-success me-1"></i> Dedicated Zonal Remittance</span>
        </div>
    </div>
</div>

<?php 
$z_pending_requests = $conn->query("
    SELECT COUNT(DISTINCT ccr.id) as cnt 
    FROM contact_change_requests ccr
    LEFT JOIN residents r ON ccr.resident_id = r.id OR (r.user_id = ccr.user_id AND r.estate_id = ccr.estate_id)
    LEFT JOIN flats f ON r.flat_id = f.id
    LEFT JOIN buildings b ON f.building_id = b.id
    LEFT JOIN streets s ON b.street_id = s.id
    WHERE ccr.estate_id = $estate_id 
      AND (ccr.zone_id = $zone_id OR s.zone_id = $zone_id)
      AND ccr.status = 'pending'
")->fetch_assoc()['cnt'] ?? 0;
if ($z_pending_requests > 0): 
?>
<div class="alert mature-card p-3 mb-4 shadow-sm" style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25); border-radius: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; overflow: hidden;">
    <div class="d-flex align-items-center gap-3 min-w-0" style="flex: 1;">
        <div class="glass-icon-circle hero-icon-circle glass-icon-light-amber" style="width: 44px; height: 44px; min-width: 44px; min-height: 44px; font-size: 1.25rem;">
            <i class="fa-solid fa-id-card-clip"></i>
        </div>
        <div class="min-w-0">
            <strong class="zonal-amber-alert-title" style="color: #92400e; font-size: 0.95rem;">Zonal Action Required: Pending Contact Change Requests</strong>
            <div class="zonal-amber-alert-sub" style="font-size: 0.85rem; color: #78350f;">
                You have <strong class="text-danger"><?php echo $z_pending_requests; ?></strong> resident contact change request(s) in your zone awaiting approval.
            </div>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="notifications" class="btn btn-sm btn-outline-warning text-dark px-3 py-1.5 fw-semibold rounded-pill">
            <i class="fa-solid fa-bell me-1"></i> Zonal Action Center
        </a>
        <a href="residents?tab=contact_requests" class="btn btn-sm text-white px-3 py-1.5 fw-semibold rounded-pill shadow-sm" style="background: #d97706;">
            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Review in Registry &rarr;
        </a>
    </div>
</div>
<?php endif; ?>

<!-- ==========================================
     EXECUTIVE ZONAL COMMAND HERO BOARD
     ========================================== -->
<div class="zonal-hero-board">
    <div class="position-relative" style="z-index: 2;">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h2>
                    <?php echo htmlspecialchars($zone_name); ?> 
                    <span class="zonal-hero-code-badge"><?php echo htmlspecialchars($zone_code); ?></span>
                </h2>
                <?php if (!empty($zone['motto'])): ?>
                    <div class="zonal-hero-motto">
                        <i class="fa-solid fa-quote-left opacity-75 me-1"></i><?php echo htmlspecialchars($zone['motto']); ?><i class="fa-solid fa-quote-right opacity-75 ms-1"></i>
                    </div>
                <?php endif; ?>
                <div class="zonal-hero-chips">
                    <?php if (!empty($zone['email'])): ?>
                        <span class="zonal-hero-chip"><i class="fa-solid fa-envelope"></i> <?php echo htmlspecialchars($zone['email']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($zone['phone'])): ?>
                        <span class="zonal-hero-chip"><i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($zone['phone']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($zone['office_address'])): ?>
                        <span class="zonal-hero-chip"><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($zone['office_address']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($zone['registration_date'])): ?>
                        <span class="zonal-hero-chip"><i class="fa-solid fa-calendar-check"></i> Established: <?php echo date('M d, Y', strtotime($zone['registration_date'])); ?></span>
                    <?php endif; ?>
                </div>
                <p class="zonal-hero-desc">
                    <?php echo !empty($zone['description']) ? htmlspecialchars($zone['description']) : 'Dedicated sector administration, infrastructure catalog, resident onboarding, and localized billing realization.'; ?>
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="broadcasts" class="zonal-hero-btn-white">
                    <i class="fa-solid fa-bullhorn"></i> Send Zone Notice
                </a>
                <a href="properties" class="zonal-hero-btn-glass">
                    <i class="fa-solid fa-road"></i> Streets &amp; Flats
                </a>
                <a href="residents" class="zonal-hero-btn-glass">
                    <i class="fa-solid fa-user-plus"></i> Onboard Resident
                </a>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($zone['paystack_account_number'])): ?>
<!-- Paystack Dedicated Virtual Account Card -->
<div class="mature-card mb-4 p-3" style="border-left: 4px solid #6b4eff !important;">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="kpi-icon-wrap" style="background: rgba(107, 78, 255, 0.1); color: #6b4eff; border-color: rgba(107, 78, 255, 0.25);">
                <i class="fa-solid fa-building-columns"></i>
            </div>
            <div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="text-uppercase fw-bold text-secondary" style="font-size: 0.75rem; letter-spacing: 0.05em;">Paystack Dedicated Virtual Account</span>
                    <span class="mature-badge mature-badge-sky"><?php echo htmlspecialchars($zone['paystack_bank_name'] ?? 'Wema Bank (Paystack DVA)'); ?></span>
                </div>
                <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                    <span class="zonal-dva-account-num"><?php echo htmlspecialchars($zone['paystack_account_number']); ?></span>
                    <span class="text-secondary small fw-medium">&bull; <?php echo htmlspecialchars($zone['paystack_account_name'] ?? ($zone_name . ' Account')); ?></span>
                    <?php if (!empty($zone['paystack_subaccount_code'])): ?>
                        <span class="mature-badge mature-badge-slate font-monospace"><?php echo htmlspecialchars($zone['paystack_subaccount_code']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($zone['paystack_account_number']); ?>'); this.innerHTML='<i class=\'fa-solid fa-check me-1\'></i> Copied!'; setTimeout(() => this.innerHTML='<i class=\'fa-regular fa-copy me-1\'></i> Copy NUBAN', 2000);" class="btn btn-sm btn-outline-primary fw-semibold px-3 py-1.5 rounded-pill">
                <i class="fa-regular fa-copy me-1"></i> Copy NUBAN
            </button>
            <a href="finance" class="btn btn-sm btn-outline-secondary fw-semibold px-3 py-1.5 rounded-pill">
                <i class="fa-solid fa-receipt me-1"></i> Zonal Finance
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

    <!-- ==========================================
         ROW 1: 3 METRIC CARDS (live zonal data)
         ========================================== -->
    <div class="ds-metrics-grid">
        <div class="ds-metric-card">
            <div class="ds-metric-card-head">
                <h3 class="ds-metric-title">Total property <i class="fa-regular fa-circle-question" title="Flats across your zone"></i></h3>
                <a href="properties" class="ds-see-details-btn">See Details</a>
            </div>
            <div class="ds-metric-body">
                <div class="ds-metric-info-circle">i</div>
                <div class="ds-metric-value"><?= number_format($total_flats) ?></div>
                <span class="ds-metric-trend-pill up"><i class="fa-solid fa-building"></i> <?= $occupancy_pct ?>% occupied</span>
            </div>
            <div class="ds-metric-chart-row">
                <div class="ds-metric-sparkline">
                    <svg viewBox="0 0 300 70" preserveAspectRatio="none">
                        <defs><linearGradient id="zonePurpleWaveGrad" x1="0%" y1="0%" x2="0%" y2="100%"><stop offset="0%" stop-color="#6366f1" stop-opacity="0.25"/><stop offset="100%" stop-color="#6366f1" stop-opacity="0"/></linearGradient></defs>
                        <path d="M 0 50 Q 40 50, 70 20 T 140 25 T 210 50 T 260 55 L 300 55 L 300 70 L 0 70 Z" fill="url(#zonePurpleWaveGrad)"/>
                        <path d="M 0 50 Q 40 50, 70 20 T 140 25 T 210 50 T 260 55 L 300 55" fill="none" stroke="#6366f1" stroke-width="2.5" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="ds-metric-caption">
                    <strong><?= $total_streets ?></strong> streets &bull; <strong><?= $total_buildings ?></strong> buildings<br>
                    <strong><?= $vacant_flats ?></strong> vacant &bull; <strong><?= $maintenance_flats ?></strong> in maintenance
                </div>
            </div>
        </div>

        <div class="ds-metric-card">
            <div class="ds-metric-card-head">
                <h3 class="ds-metric-title">Collected this month <i class="fa-regular fa-circle-question" title="Realized zonal collections"></i></h3>
                <a href="finance" class="ds-see-details-btn">See Details</a>
            </div>
            <div class="ds-metric-body">
                <div class="ds-metric-info-circle">i</div>
                <div class="ds-metric-value"><?= htmlspecialchars($currency_symbol) ?><?= number_format($revenue_month) ?></div>
                <span class="ds-metric-trend-pill up"><i class="fa-solid fa-wallet"></i> <?= htmlspecialchars($currency_symbol) ?><?= number_format($revenue_total) ?> total</span>
            </div>
            <div class="ds-metric-chart-row">
                <div class="ds-metric-sparkline">
                    <svg viewBox="0 0 300 70" preserveAspectRatio="none">
                        <defs><linearGradient id="zoneGreenWaveGrad" x1="0%" y1="0%" x2="0%" y2="100%"><stop offset="0%" stop-color="#10b981" stop-opacity="0.25"/><stop offset="100%" stop-color="#10b981" stop-opacity="0"/></linearGradient></defs>
                        <path d="M 0 52 Q 35 22, 60 25 T 120 48 T 170 18 T 225 52 T 265 40 L 300 55 L 300 70 L 0 70 Z" fill="url(#zoneGreenWaveGrad)"/>
                        <path d="M 0 52 Q 35 22, 60 25 T 120 48 T 170 18 T 225 52 T 265 40 L 300 55" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="ds-metric-caption">Realized zonal<br>collections</div>
            </div>
        </div>

        <div class="ds-metric-card">
            <div class="ds-metric-card-head">
                <h3 class="ds-metric-title">Outstanding <i class="fa-regular fa-circle-question" title="Unpaid zonal invoices"></i></h3>
                <a href="finance" class="ds-see-details-btn">See Details</a>
            </div>
            <div class="ds-metric-body">
                <div class="ds-metric-info-circle">i</div>
                <div class="ds-metric-value"><?= htmlspecialchars($currency_symbol) ?><?= number_format($outstanding_invoices) ?></div>
                <span class="ds-metric-trend-pill <?= $overdue_count > 0 ? 'down' : 'up' ?>">
                    <i class="fa-solid <?= $overdue_count > 0 ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"></i>
                    <?= $overdue_count > 0 ? $overdue_count . ' overdue' : 'None overdue' ?>
                </span>
            </div>
            <div class="ds-metric-chart-row">
                <div class="ds-metric-sparkline">
                    <svg viewBox="0 0 300 70" preserveAspectRatio="none">
                        <defs><linearGradient id="zoneOrangeWaveGrad" x1="0%" y1="0%" x2="0%" y2="100%"><stop offset="0%" stop-color="#f97316" stop-opacity="0.25"/><stop offset="100%" stop-color="#f97316" stop-opacity="0"/></linearGradient></defs>
                        <path d="M 0 55 Q 50 55, 95 38 T 175 20 T 240 45 L 300 52 L 300 70 L 0 70 Z" fill="url(#zoneOrangeWaveGrad)"/>
                        <path d="M 0 55 Q 50 55, 95 38 T 175 20 T 240 45 L 300 52" fill="none" stroke="#f97316" stroke-width="2.5" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="ds-metric-caption">Unpaid invoices<br>across the zone</div>
            </div>
        </div>
    </div>

    <!-- ==========================================
         ROW 2: REVENUE CHART + RECENT PAYMENTS
         ========================================== -->
    <div class="ds-overview-grid">
        <div class="ds-card-panel">
            <div class="ds-panel-header">
                <div class="ds-panel-title-group">
                    <h4>Revenue overview</h4>
                    <p class="ds-panel-subtitle">Collections &bull; last 6 months</p>
                </div>
                <div class="ds-panel-controls">
                    <div class="ds-chart-legend">
                        <span class="ds-chart-legend-item"><span class="ds-legend-dot green"></span> Collected</span>
                    </div>
                </div>
            </div>
            <div class="ds-chart-container">
                <canvas id="zoneRevenueSplineChart"></canvas>
            </div>
        </div>

        <div class="ds-card-panel">
            <div class="ds-panel-header">
                <div class="ds-panel-title-group">
                    <h4>Recent payments</h4>
                    <p class="ds-panel-subtitle">Latest settled in your zone</p>
                </div>
            </div>
            <div class="ds-messages-list">
                <?php $pi = 0; $av = ['navy', 'purple', 'orange', 'green'];
                if ($recent_payments && $recent_payments->num_rows > 0):
                    while ($p = $recent_payments->fetch_assoc()):
                        $pn = trim($p['resident_name'] ?? '') ?: 'Resident';
                        $pp = preg_split('/\s+/', $pn);
                        $pin = strtoupper(substr($pp[0], 0, 1) . (isset($pp[1]) ? substr($pp[1], 0, 1) : '')); ?>
                    <a href="finance" class="ds-message-item">
                        <div class="ds-message-avatar <?= $av[$pi++ % 4] ?>"><?= htmlspecialchars($pin) ?></div>
                        <div class="ds-message-info">
                            <div class="ds-message-name"><?= htmlspecialchars($pn) ?></div>
                            <div class="ds-message-preview"><?= htmlspecialchars($p['invoice_number'] ?? '') ?></div>
                        </div>
                        <div class="ds-message-meta">
                            <span class="ds-message-name"><?= htmlspecialchars($currency_symbol) ?><?= number_format($p['amount']) ?></span>
                            <span class="ds-message-time"><?= date('M j', strtotime($p['paid_at'] ?? $p['created_at'])) ?></span>
                        </div>
                    </a>
                <?php endwhile; else: ?>
                    <div class="text-center text-muted small py-5">
                        <i class="fa-solid fa-receipt fs-2 d-block mb-2"></i>No payments recorded yet.
                    </div>
                <?php endif; ?>
            </div>
            <a href="finance" class="ds-view-all-messages-btn">
                View all payments <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.72rem;"></i>
            </a>
        </div>
    </div>

    <!-- ==========================================
         ROW 3: STREETS DIRECTORY
         ========================================== -->
    <div class="ds-property-table-card mb-4">
        <div class="ds-panel-header mb-3">
            <div class="ds-panel-title-group">
                <h4>Streets overview</h4>
                <p class="ds-panel-subtitle">Infrastructure and occupancy in your zone</p>
            </div>
            <a href="properties" class="ds-pill-dropdown"><i class="fa-solid fa-arrow-right"></i> All properties</a>
        </div>
        <?php if ($street_overview && $street_overview->num_rows > 0):
            $grads = ['linear-gradient(135deg,#6366f1,#8b5cf6)', 'linear-gradient(135deg,#10b981,#34d399)', 'linear-gradient(135deg,#f59e0b,#fbbf24)'];
            $si = 0;
            while ($st = $street_overview->fetch_assoc()):
                $fl = intval($st['flats_count']); $oc = intval($st['occupied_count']);
                $pct = $fl > 0 ? round($oc / $fl * 100) : 0; ?>
            <div class="ds-property-row">
                <div class="ds-property-info-cell">
                    <div class="ds-property-thumb" style="background: <?= $grads[$si++ % 3] ?>; display: grid; place-items: center; color: #fff;"><i class="fa-solid fa-road"></i></div>
                    <div class="ds-property-details">
                        <h5><?= htmlspecialchars($st['street_name']) ?></h5>
                        <span><?= htmlspecialchars($st['custom_id'] ?: ('ST-' . $st['id'])) ?></span>
                    </div>
                </div>
                <div class="ds-property-col-cell"><small>Buildings</small><span><?= intval($st['buildings_count']) ?></span></div>
                <div class="ds-property-col-cell"><small>Flats</small><span><?= $fl ?></span></div>
                <div class="ds-property-col-cell"><small>Occupied</small><span><?= $oc ?></span></div>
                <div class="ds-property-col-cell"><small>Occupancy</small><span><?= $pct ?>%</span></div>
                <div></div>
                <div><a href="properties" class="ds-edit-pill-btn"><i class="fa-solid fa-eye"></i> View</a></div>
            </div>
        <?php endwhile; else: ?>
            <div class="text-center py-5 text-muted"><i class="fa-solid fa-road fs-2 d-block mb-2"></i>No streets registered in this zone yet.</div>
        <?php endif; ?>
    </div>
</div><!-- end .desktop-only -->

<!-- Chart.js Engine for Dual Area Spline Chart -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('zoneRevenueSplineChart');
    if (!ctx) return;

    const chartCtx = ctx.getContext('2d');

    // Expense Purple Gradient
    const purpleGrad = chartCtx.createLinearGradient(0, 0, 0, 240);
    purpleGrad.addColorStop(0, 'rgba(99, 102, 241, 0.28)');
    purpleGrad.addColorStop(1, 'rgba(99, 102, 241, 0.0)');

    // Income Green Gradient
    const greenGrad = chartCtx.createLinearGradient(0, 0, 0, 240);
    greenGrad.addColorStop(0, 'rgba(16, 185, 129, 0.22)');
    greenGrad.addColorStop(1, 'rgba(16, 185, 129, 0.0)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chart_revenue_labels) ?>,
            datasets: [
                {
                    label: 'Collected',
                    data: <?= json_encode($chart_revenue_values) ?>,
                    borderColor: '#10b981',
                    borderWidth: 2,
                    borderDash: [5, 4],
                    backgroundColor: greenGrad,
                    fill: true,
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
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.88)',
                    titleColor: '#ffffff',
                    bodyColor: '#e2e8f0',
                    padding: 10,
                    cornerRadius: 8,
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': $' + (context.parsed.y * 1000).toLocaleString();
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false,
                        drawBorder: false
                    },
                    ticks: {
                        color: '#94a3b8',
                        font: {
                            family: 'Outfit, sans-serif',
                            size: 11
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        maxTicksLimit: 5,
                        color: '#94a3b8',
                        font: {
                            family: 'Outfit, sans-serif',
                            size: 11
                        },
                        callback: function(value) {
                            return value === 0 ? '' : (value >= 1000 ? (value / 1000) + 'K' : value);
                        }
                    },
                    grid: {
                        color: 'rgba(226, 232, 240, 0.65)',
                        drawBorder: false
                    }
                }
            }
        }
    });
});
</script>

<?php include 'footer.php'; ?>
