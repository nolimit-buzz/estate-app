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
    background: linear-gradient(135deg, #3b0764 0%, #6b21a8 50%, #7e22ce 100%) !important;
    border: 1px solid rgba(255, 255, 255, 0.25) !important;
    border-radius: 16px !important;
    box-shadow: 0 10px 30px -5px rgba(107, 33, 168, 0.4) !important;
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
    background: radial-gradient(circle, rgba(192, 132, 252, 0.25) 0%, transparent 70%);
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
    color: #3b0764 !important;
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
                        <div class="mobile-bar-fill" style="height: <?php echo $pct; ?>%; background: linear-gradient(180deg, #9333ea 0%, #c084fc 100%);"></div>
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
                    <div class="mobile-activity-icon" style="background: rgba(147, 51, 234, 0.12); color: #9333ea;">
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
            <i class="fa-regular fa-calendar-days text-purple" style="color: #9333ea;"></i>
            <span><?php echo date('M Y'); ?></span>
            <i class="fa-solid fa-chevron-down" style="font-size: 0.65rem;"></i>
        </div>
        <a href="reports" class="btn-export-ghost" title="Zonal Financial & Resident Reports">
            <i class="fa-solid fa-chart-pie text-purple" style="color: #7e22ce;"></i> Zonal Reports
        </a>
        <a href="residents" class="btn-primary-action-pill" style="background: linear-gradient(135deg, #7e22ce 0%, #6b21a8 100%); box-shadow: 0 4px 14px -2px rgba(126, 34, 206, 0.4);">
            <i class="fa-solid fa-user-plus"></i> Onboard Resident
        </a>
        <a href="broadcasts" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-2 fw-semibold">
            <i class="fa-solid fa-bullhorn me-1"></i> Broadcast
        </a>
    </div>
</div>

<!-- Zonal Autonomy & Security Privilege Ribbon -->
<div class="card border-0 rounded-4 p-3 mb-4 shadow-sm" style="background: linear-gradient(135deg, rgba(168, 85, 247, 0.08) 0%, rgba(59, 130, 246, 0.05) 100%); border: 1px solid rgba(168, 85, 247, 0.2) !important;">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <span class="badge rounded-pill px-2.5 py-1" style="background: rgba(168, 85, 247, 0.2); color: #7e22ce; font-size: 0.72rem; font-weight: 700;">
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
<div class="mature-card mb-4 p-3" style="border-left: 4px solid #0284c7 !important;">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="kpi-icon-wrap" style="background: rgba(2, 132, 199, 0.1); color: #0284c7; border-color: rgba(2, 132, 199, 0.25);">
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
     EXECUTIVE KPI METRICS RIBBON (4 PILLARS)
     ========================================== -->
<div class="row g-3 mb-4">
    <!-- Pillar 1: Financial Collections -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Revenue (This Month)</span>
                    <div class="kpi-value"><?php echo $currency_symbol . number_format($revenue_month, 2); ?></div>
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
                $fin_pct = ($revenue_total > 0) ? min(100, round(($revenue_month / $revenue_total) * 100)) : 0;
                ?>
                <div class="kpi-progress-fill" style="width: <?php echo max(8, $fin_pct); ?>%; background: #7e22ce;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 2: Real Estate & Occupancy -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Capacity & Occupancy</span>
                    <div class="kpi-value"><?php echo $occupancy_pct; ?>% <span class="kpi-unit-label">Occupied</span></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-city"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span><strong><?php echo $occupied_flats; ?></strong> Occupied / <strong><?php echo $total_flats; ?></strong> Flats</span>
                <span class="mature-badge mature-badge-purple"><?php echo $total_buildings; ?> Buildings</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?php echo $occupancy_pct; ?>%; background: #10b981;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 3: Zone Population & Community -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Zonal Population</span>
                    <div class="kpi-value"><?php echo number_format($active_residents); ?> <span class="kpi-unit-label">Residents</span></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-users"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Total People: <strong><?php echo number_format($total_population); ?></strong></span>
                <span class="mature-badge mature-badge-slate"><?php echo $total_owners; ?> Owners</span>
            </div>
            <div class="kpi-progress-bar">
                <?php 
                $pop_pct = ($total_population > 0) ? min(100, round(($active_residents / $total_population) * 100)) : 100;
                ?>
                <div class="kpi-progress-fill" style="width: <?php echo $pop_pct; ?>%; background: #3b82f6;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 4: Sector Infrastructure -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Sector Infrastructure</span>
                    <div class="kpi-value"><?php echo $total_streets; ?> <span class="kpi-unit-label">Streets</span></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-road"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Across <strong><?php echo $total_buildings; ?></strong> Buildings</span>
                <span class="mature-badge mature-badge-slate"><?php echo $total_flats; ?> Flats</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?php echo min(100, max(15, $total_streets * 20)); ?>%; background: #d97706;"></div>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     VISUAL ANALYTICS & TREND CHARTS
     ========================================== -->
<div class="row g-3 mb-4">
    <!-- Left: 6-Month Zonal Collection Trend -->
    <div class="col-12 col-lg-8">
        <div class="mature-card">
            <div class="mature-card-header">
                <div>
                    <h3 class="mature-card-title">
                        <i class="fa-solid fa-chart-column text-secondary"></i> Zonal Collection Velocity
                    </h3>
                    <p class="text-secondary small mb-0">Monthly realized payments trend for <?= htmlspecialchars($zone_name) ?> over the last 6 months</p>
                </div>
                <a href="finance" class="btn btn-sm btn-outline-secondary">
                    View Invoices <i class="fa-solid fa-arrow-right ms-1 small"></i>
                </a>
            </div>
            <div class="mature-card-body">
                <div style="position: relative; height: 250px; width: 100%;">
                    <canvas id="zoneRevenueTrendChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: Flat Capacity Breakdown -->
    <div class="col-12 col-lg-4">
        <div class="mature-card">
            <div class="mature-card-header">
                <div>
                    <h3 class="mature-card-title">
                        <i class="fa-solid fa-chart-pie text-secondary"></i> Flat Occupancy
                    </h3>
                    <p class="text-secondary small mb-0">Flat status distribution</p>
                </div>
                <span class="mature-badge mature-badge-purple"><?php echo $total_flats; ?> Total Flats</span>
            </div>
            <div class="mature-card-body d-flex flex-column align-items-center justify-content-center">
                <div style="position: relative; height: 180px; width: 100%; max-width: 220px;">
                    <canvas id="zoneOccupancyChart"></canvas>
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
    <!-- Panel 1: Recent Invoices in Zone -->
    <div class="col-12 col-lg-6">
        <div class="mature-card">
            <div class="mature-card-header">
                <h3 class="mature-card-title">
                    <i class="fa-solid fa-file-invoice-dollar text-secondary"></i> Recent Invoices & Levies
                </h3>
                <a href="finance" class="btn btn-sm btn-outline-secondary">
                    View Invoices <i class="fa-solid fa-arrow-right ms-1 small"></i>
                </a>
            </div>
            <div class="mature-card-body p-0">
                <div class="table-responsive">
                    <table class="table dashboard-table align-middle">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Resident & Flat</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_invoices && $recent_invoices->num_rows > 0): ?>
                                <?php while ($inv = $recent_invoices->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <span class="mature-badge mature-badge-slate font-monospace" style="font-size: 0.725rem;">
                                                <?php echo htmlspecialchars($inv['invoice_number'] ?: ('INV-' . $inv['id'])); ?>
                                            </span>
                                            <div class="small text-secondary text-truncate" style="max-width: 140px;">
                                                <?php echo htmlspecialchars($inv['title']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-slate-900"><?php echo htmlspecialchars($inv['resident_name'] ?: 'Resident'); ?></div>
                                            <div class="small text-secondary">
                                                <?php echo htmlspecialchars(($inv['building_name'] ? $inv['building_name'] . ' - ' : '') . 'Flat ' . $inv['flat_number']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="fw-bold text-slate-900">
                                                <?php echo $currency_symbol . number_format($inv['amount'], 2); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $st = $inv['status'];
                                            if ($st === 'paid') {
                                                echo '<span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-check me-1"></i>Paid</span>';
                                            } elseif ($st === 'partially_paid') {
                                                echo '<span class="mature-badge mature-badge-amber">Partial</span>';
                                            } elseif ($st === 'overdue') {
                                                echo '<span class="mature-badge mature-badge-crimson">Overdue</span>';
                                            } else {
                                                echo '<span class="mature-badge mature-badge-slate">' . ucfirst($st) . '</span>';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-secondary">
                                        <i class="fa-solid fa-receipt d-block mb-1 opacity-50" style="font-size: 1.5rem;"></i>
                                        No invoices generated in this zone yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Panel 2: Recent Resident Onboarding -->
    <div class="col-12 col-lg-6">
        <div class="mature-card">
            <div class="mature-card-header">
                <h3 class="mature-card-title">
                    <i class="fa-solid fa-users text-secondary"></i> Recent Resident Onboarding
                </h3>
                <a href="residents" class="btn btn-sm btn-outline-secondary">
                    Directory <i class="fa-solid fa-arrow-right ms-1 small"></i>
                </a>
            </div>
            <div class="mature-card-body p-0">
                <div class="table-responsive">
                    <table class="table dashboard-table align-middle">
                        <thead>
                            <tr>
                                <th>Resident</th>
                                <th>Flat Location</th>
                                <th>Type</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_residents && $recent_residents->num_rows > 0): ?>
                                <?php while ($res = $recent_residents->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2.5">
                                                <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(126, 34, 206, 0.12); color: #7e22ce; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem;">
                                                    <?php echo strtoupper(substr($res['resident_name'], 0, 2)); ?>
                                                </div>
                                                <div>
                                                    <div class="fw-semibold text-slate-900"><?php echo htmlspecialchars($res['resident_name']); ?></div>
                                                    <div class="small text-secondary"><?php echo htmlspecialchars($res['resident_phone'] ?? 'No phone'); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="small fw-medium text-slate-800"><?php echo htmlspecialchars($res['building_name']); ?></div>
                                            <div class="small text-secondary"><?php echo htmlspecialchars($res['street_name'] . ' • Flat ' . $res['flat_number']); ?></div>
                                        </td>
                                        <td>
                                            <span class="mature-badge mature-badge-slate">
                                                <?php echo ucfirst($res['type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="text-secondary small">
                                                <?php echo date('M d', strtotime($res['created_at'])); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-secondary">
                                        <i class="fa-solid fa-user-group d-block mb-1 opacity-50" style="font-size: 1.5rem;"></i>
                                        No residents registered in this zone yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Panel 3: Recent Local Payments -->
    <div class="col-12 col-lg-6">
        <div class="mature-card">
            <div class="mature-card-header">
                <h3 class="mature-card-title">
                    <i class="fa-solid fa-receipt text-secondary"></i> Recent Realized Payments
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
                                <th>Ref</th>
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
                                            <span class="mature-badge mature-badge-slate font-monospace" style="font-size: 0.725rem;">
                                                <?php echo htmlspecialchars($p['transaction_ref'] ?? ('TRX-' . $p['id'])); ?>
                                            </span>
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
                                        <i class="fa-solid fa-coins d-block mb-1 opacity-50" style="font-size: 1.5rem;"></i>
                                        No recent payments realized in this zone yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Panel 4: Sector Street Network -->
    <div class="col-12 col-lg-6">
        <div class="mature-card">
            <div class="mature-card-header">
                <h3 class="mature-card-title">
                    <i class="fa-solid fa-road text-secondary"></i> Street Infrastructure Overview
                </h3>
                <a href="properties" class="btn btn-sm btn-outline-secondary">
                    All Streets <i class="fa-solid fa-arrow-right ms-1 small"></i>
                </a>
            </div>
            <div class="mature-card-body p-0">
                <div class="table-responsive">
                    <table class="table dashboard-table align-middle">
                        <thead>
                            <tr>
                                <th>Street</th>
                                <th>Buildings</th>
                                <th>Total Flats</th>
                                <th>Occupancy</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($street_overview && $street_overview->num_rows > 0): ?>
                                <?php while ($st = $street_overview->fetch_assoc()): 
                                    $st_tot = intval($st['flats_count']);
                                    $st_occ = intval($st['occupied_count']);
                                    $st_pct = $st_tot > 0 ? round(($st_occ / $st_tot) * 100, 1) : 0;
                                ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-slate-900"><?php echo htmlspecialchars($st['street_name']); ?></div>
                                            <div class="small text-secondary font-monospace"><?php echo htmlspecialchars($st['custom_id'] ?? 'N/A'); ?></div>
                                        </td>
                                        <td>
                                            <span class="fw-medium text-slate-800"><?php echo $st['buildings_count']; ?></span>
                                        </td>
                                        <td>
                                            <span class="fw-medium text-slate-800"><?php echo $st['flats_count']; ?></span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="kpi-progress-bar flex-grow-1" style="height: 5px; margin: 0;">
                                                    <div class="kpi-progress-fill" style="width: <?php echo $st_pct; ?>%; background: #10b981;"></div>
                                                </div>
                                                <span class="small fw-bold text-slate-800"><?php echo $st_pct; ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-secondary">
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
    </div>
</div>

<!-- ==========================================
     OPERATIONAL QUICK-JUMP NAVIGATION DOCK
     ========================================== -->
<div class="mb-4">
    <div class="d-flex align-items-center justify-content-between mb-2">
        <h6 class="text-secondary fw-semibold text-uppercase small m-0" style="letter-spacing: 0.05em;">
            Zonal Administrative Portals & Tools
        </h6>
    </div>
    <div class="row g-2">
        <div class="col-6 col-md-4 col-xl-2">
            <a href="properties" class="quick-dock-btn">
                <div class="quick-dock-icon"><i class="fa-solid fa-road"></i></div>
                <span>Streets & Flats</span>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="residents" class="quick-dock-btn">
                <div class="quick-dock-icon"><i class="fa-solid fa-users"></i></div>
                <span>Residents</span>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="owners" class="quick-dock-btn">
                <div class="quick-dock-icon"><i class="fa-solid fa-user-shield"></i></div>
                <span>Property Owners</span>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="charges" class="quick-dock-btn">
                <div class="quick-dock-icon"><i class="fa-solid fa-list-check"></i></div>
                <span>Local Levies</span>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="billing_config" class="quick-dock-btn">
                <div class="quick-dock-icon"><i class="fa-solid fa-sliders"></i></div>
                <span>Billing Config</span>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="reports" class="quick-dock-btn">
                <div class="quick-dock-icon"><i class="fa-solid fa-chart-pie"></i></div>
                <span>Zonal Reports</span>
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

        // 1. Revenue Velocity Trend Chart (Zone Scoped)
        const revCtx = document.getElementById('zoneRevenueTrendChart');
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
                        backgroundColor: isDark ? 'rgba(168, 85, 247, 0.55)' : 'rgba(126, 34, 206, 0.85)',
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
        const occCtx = document.getElementById('zoneOccupancyChart');
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
                            isDark ? '#a855f7' : '#7e22ce', // Primary purple
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

<?php include 'footer.php'; ?>
