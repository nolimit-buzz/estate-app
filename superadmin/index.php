<?php
// superadmin/index.php - Platform Command Center & Governance Dashboard
require_once __DIR__ . '/includes/super_auth.php';

// Handle Quick Edit Estate Submission (validated, shared with estates.php / estate.php)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['update_estate_quick'])) {
    sa_require_role(['superadmin']);
    require_once __DIR__ . '/includes/estate_profile.php';
    try {
        $updated = sa_update_estate_profile($platform, intval($_POST['estate_id'] ?? 0), $_POST);
        redirectWithFlash('index', "Estate '$updated' details updated successfully!");
    } catch (Throwable $ex) {
        redirectWithFlash('index', null, $ex->getMessage());
    }
}

require_once __DIR__ . '/../includes/Support.php';
require_once __DIR__ . '/../includes/TenantStats.php';

// Handle Refresh All Tenant Stats
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['refresh_tenant_stats'])) {
    sa_require_role(['superadmin', 'support']);
    [$ok, $bad] = TenantStats::refreshAll();
    redirectWithFlash('index', "Tenant databases refreshed: $ok connected" . ($bad ? ", $bad unreachable." : "."));
}

$page_title = 'Platform Dashboard';
require_once __DIR__ . '/includes/super_header.php';
require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';

$one = fn($sql) => $platform->query($sql)->fetch_assoc();

// 1. Core Platform Estate Metrics
$st = $one("SELECT COUNT(*) total,
    SUM(status='active') active_c, SUM(status='trial') trial_c, SUM(status='grace_period') grace_c, SUM(status='suspended') susp_c,
    SUM(status='retention') ret_c, SUM(status='deletion_pending') del_c, SUM(status IN ('pending','onboarding')) onb_c, COALESCE(SUM(resident_count),0) residents
    FROM estates WHERE status NOT IN ('deleted','archived')");

// 2. Revenue & Financial Volume
$rev = $one("SELECT COALESCE(SUM(total),0) all_time, COALESCE(SUM(CASE WHEN paid_at >= DATE_FORMAT(NOW(),'%Y-%m-01') THEN total END),0) this_month FROM invoices WHERE status = 'paid'");

// 3. Monthly Recurring Revenue (MRR)
$mrr = $one("SELECT COALESCE(SUM(i.subtotal / IF(s.billing_cycle='annual',12,1)),0) mrr, COALESCE(SUM(s.billing_cycle='annual'),0) annual_c
    FROM subscriptions s JOIN invoices i ON i.id = (SELECT MAX(id) FROM invoices WHERE subscription_id = s.id AND status = 'paid' AND kind IN ('subscription','renewal'))
    WHERE s.status IN ('active','renewed','grace_period','past_due')");

// 4. Payments, Tickets, Alerts, and Errors
$pay = $one("SELECT SUM(status='failed' AND created_at > NOW() - INTERVAL 30 DAY) failed_c, SUM(status='pending') pending_c FROM payments WHERE entry_type='payment'");
$tix = ['c' => Support::queueCount()];
$alerts = $one("SELECT COUNT(*) c FROM security_alerts WHERE status = 'open'");
$errs = $one("SELECT COUNT(*) c FROM system_errors WHERE resolved = 0 AND severity IN ('error','critical')");

// 5. 12-Month Revenue Data for Historical Spline
$revenue_data = [];
$revenue_months = [];
for ($i = 11; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $res = $platform->query("SELECT COALESCE(SUM(total),0) as revenue FROM invoices WHERE status='paid' AND DATE_FORMAT(paid_at,'%Y-%m') = '$month'")->fetch_assoc();
    $revenue_data[] = round(floatval($res['revenue'] ?? 0), 2);
    $revenue_months[] = date('M', strtotime("-$i months"));
}

// 6. Subscription Breakdown
$sub_stats = $platform->query("SELECT status, COUNT(*) c FROM subscriptions WHERE status IN ('active','renewed','grace_period','past_due','suspended','cancelled') GROUP BY status")->fetch_all(MYSQLI_ASSOC);
$sub_labels = array_map(fn($s) => ucfirst(str_replace('_', ' ', $s['status'])), $sub_stats);
$sub_data = array_map(fn($s) => intval($s['c']), $sub_stats);

// 7. Payment Status Breakdown (Last 30 Days)
$payment_stats = $platform->query("SELECT status, COUNT(*) c FROM payments WHERE entry_type='payment' AND created_at > NOW() - INTERVAL 30 DAY GROUP BY status")->fetch_all(MYSQLI_ASSOC);
$payment_labels = array_map(fn($p) => ucfirst($p['status']), $payment_stats);
$payment_data = array_map(fn($p) => intval($p['c']), $payment_stats);

// 8. Live Support Tickets (excluding deleted estates)
$open_tickets = $platform->query("SELECT t.id, t.ticket_no, t.subject, t.priority, t.status, t.created_by_name, t.created_at, e.name AS estate_name 
    FROM support_tickets t 
    JOIN estates e ON e.id = t.estate_id 
    WHERE t.status IN ('open','in_progress','awaiting_customer') 
      AND e.status NOT IN ('deleted','archived')
    ORDER BY FIELD(t.priority,'urgent','high','normal','low'), t.updated_at DESC LIMIT 5");

// Keep tenant data fresh without blocking page load
TenantStats::refreshStale(300, 4);

// 9. Live Registered Estates Fleet (With complete profile details and live tenant database status)
$live_estates = $platform->query("SELECT e.id, e.name, e.domain_prefix, e.contact_email, e.contact_phone, e.address, e.city, e.state, e.notes, e.resident_count, e.created_at, e.status, e.db_name,
    s.db_ok, s.db_error, s.residents AS live_residents, s.staff AS live_staff, s.admins AS live_admins, s.open_maintenance, s.unpaid_invoices, s.checked_at
    FROM estates e 
    LEFT JOIN estate_stats s ON s.estate_id = e.id
    WHERE e.status NOT IN ('deleted','archived') 
    ORDER BY e.id DESC LIMIT 10");
$tenant_last_checked = TenantStats::lastChecked();

// 10. Needs Attention Hub
$needs_attention = [];
$r_att = $platform->query("SELECT id, name, retention_ends_at FROM estates WHERE status IN ('retention','suspended') ORDER BY retention_ends_at LIMIT 5");
if ($r_att) {
    while ($x = $r_att->fetch_assoc()) {
        $days = $x['retention_ends_at'] ? max(0, (int)ceil((strtotime($x['retention_ends_at']) - time()) / 86400)) : null;
        $needs_attention[] = [
            'type' => 'danger',
            'icon' => 'fa-triangle-exclamation',
            'text' => 'Suspended: <a href="estate?id=' . $x['id'] . '" class="fw-bold text-dark text-decoration-none">' . e($x['name']) . '</a>' . ($x['retention_ends_at'] ? ' &middot; retention ends in <strong>' . $days . ' days</strong> (' . sa_d($x['retention_ends_at']) . ')' : '')
        ];
    }
}
$r_att = $platform->query("SELECT id, name FROM estates WHERE status = 'deletion_pending'");
if ($r_att) {
    while ($x = $r_att->fetch_assoc()) {
        $needs_attention[] = [
            'type' => 'danger',
            'icon' => 'fa-trash',
            'text' => 'Awaiting deletion approval: <a href="estate?id=' . $x['id'] . '" class="fw-bold text-dark text-decoration-none">' . e($x['name']) . '</a>'
        ];
    }
}
$r_att = $platform->query("SELECT id, name, trial_ends_at FROM estates WHERE status='trial' AND trial_ends_at < NOW() + INTERVAL 3 DAY");
if ($r_att) {
    while ($x = $r_att->fetch_assoc()) {
        $needs_attention[] = [
            'type' => 'warning',
            'icon' => 'fa-clock',
            'text' => 'Trial expiring soon (' . sa_d($x['trial_ends_at']) . '): <a href="estate?id=' . $x['id'] . '" class="fw-bold text-dark text-decoration-none">' . e($x['name']) . '</a>'
        ];
    }
}
$r_att = $platform->query("SELECT category, COUNT(*) c FROM system_errors WHERE resolved = 0 GROUP BY category");
if ($r_att) {
    while ($x = $r_att->fetch_assoc()) {
        $needs_attention[] = [
            'type' => 'warning',
            'icon' => 'fa-circle-exclamation',
            'text' => '<a href="monitoring" class="fw-bold text-dark text-decoration-none">' . intval($x['c']) . ' unresolved ' . e($x['category']) . ' issue(s)</a> in error monitoring'
        ];
    }
}
$down_estates = TenantStats::down();
foreach ($down_estates as $d) {
    $needs_attention[] = [
        'type' => 'danger',
        'icon' => 'fa-database',
        'text' => 'DB unreachable: <a href="estate?id=' . $d['id'] . '" class="fw-bold text-dark text-decoration-none">' . e($d['name']) . '</a> &middot; <span class="text-danger small">' . e($d['db_error'] ?: 'Connection failed') . '</span>'
    ];
}
?>

<!-- ==========================================================================
     SECTION 1: THE 3 HERO METRIC CARDS (Total Estates, Monthly Revenue, Gross Volume)
     ========================================================================== -->
<div class="ds-metrics-grid mb-4">
    <!-- Card 1: Total Estates -->
    <div class="ds-metric-card">
        <div class="ds-metric-card-head">
            <h3 class="ds-metric-title">
                Total Estates <i class="fa-regular fa-circle-question" title="Registered tenant estates on this SaaS instance"></i>
            </h3>
            <a href="estates" class="ds-see-details-btn">See Details</a>
        </div>
        <div class="ds-metric-body">
            <div class="ds-metric-info-circle"><i class="fa-solid fa-building text-primary" style="font-size: 11px;"></i></div>
            <div class="ds-metric-value"><?= intval($st['total']) ?></div>
            <span class="ds-metric-trend-pill up">
                <i class="fa-solid fa-arrow-up"></i> <?= intval($st['onb_c']) ?> onboarding
            </span>
        </div>
        <div class="ds-metric-chart-row">
            <div class="ds-metric-sparkline">
                <svg viewBox="0 0 240 60" preserveAspectRatio="none">
                    <defs>
                        <linearGradient id="purpleSparkGradSA" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#6259e8" stop-opacity="0.32" />
                            <stop offset="100%" stop-color="#6259e8" stop-opacity="0.0" />
                        </linearGradient>
                    </defs>
                    <path d="M 0 45 C 30 45, 45 15, 75 18 C 105 20, 120 36, 150 24 C 180 14, 205 38, 240 36 L 240 60 L 0 60 Z" fill="url(#purpleSparkGradSA)" />
                    <path d="M 0 45 C 30 45, 45 15, 75 18 C 105 20, 120 36, 150 24 C 180 14, 205 38, 240 36" fill="none" stroke="#6259e8" stroke-width="2.6" stroke-linecap="round" />
                </svg>
            </div>
            <div class="ds-metric-caption">
                <strong><?= intval($st['active_c']) ?></strong> active &middot; <strong><?= intval($st['trial_c']) ?></strong> trial tenancy
            </div>
        </div>
    </div>

    <!-- Card 2: Monthly Recurring Revenue (MRR) -->
    <div class="ds-metric-card">
        <div class="ds-metric-card-head">
            <h3 class="ds-metric-title">
                Monthly Recurring Revenue <i class="fa-regular fa-circle-question" title="Normalized monthly subscription run rate"></i>
            </h3>
            <a href="billing" class="ds-see-details-btn">See Details</a>
        </div>
        <div class="ds-metric-body">
            <div class="ds-metric-info-circle"><i class="fa-solid fa-chart-line text-success" style="font-size: 11px;"></i></div>
            <div class="ds-metric-value"><?= sa_money($mrr['mrr']) ?></div>
            <span class="ds-metric-trend-pill up">
                <i class="fa-solid fa-arrow-up"></i> <?= intval($mrr['annual_c']) ?> annual
            </span>
        </div>
        <div class="ds-metric-chart-row">
            <div class="ds-metric-sparkline">
                <svg viewBox="0 0 240 60" preserveAspectRatio="none">
                    <defs>
                        <linearGradient id="greenSparkGradSA" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#10b981" stop-opacity="0.32" />
                            <stop offset="100%" stop-color="#10b981" stop-opacity="0.0" />
                        </linearGradient>
                    </defs>
                    <path d="M 0 38 C 25 24, 45 10, 70 22 C 95 32, 115 8, 140 14 C 165 20, 195 38, 240 26 L 240 60 L 0 60 Z" fill="url(#greenSparkGradSA)" />
                    <path d="M 0 38 C 25 24, 45 10, 70 22 C 95 32, 115 8, 140 14 C 165 20, 195 38, 240 26" fill="none" stroke="#10b981" stroke-width="2.6" stroke-linecap="round" />
                </svg>
            </div>
            <div class="ds-metric-caption">
                Platform recurring revenue from active client subscriptions
            </div>
        </div>
    </div>

    <!-- Card 3: All-Time Collections / Volume -->
    <div class="ds-metric-card">
        <div class="ds-metric-card-head">
            <h3 class="ds-metric-title">
                Platform Collections <i class="fa-regular fa-circle-question" title="Cumulative settled invoice payments"></i>
            </h3>
            <a href="billing" class="ds-see-details-btn">See Details</a>
        </div>
        <div class="ds-metric-body">
            <div class="ds-metric-info-circle"><i class="fa-solid fa-vault text-warning" style="font-size: 11px;"></i></div>
            <div class="ds-metric-value"><?= sa_money($rev['all_time']) ?></div>
            <span class="ds-metric-trend-pill up">
                <i class="fa-solid fa-arrow-up"></i> Settled
            </span>
        </div>
        <div class="ds-metric-chart-row">
            <div class="ds-metric-sparkline">
                <svg viewBox="0 0 240 60" preserveAspectRatio="none">
                    <defs>
                        <linearGradient id="orangeSparkGradSA" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#f97316" stop-opacity="0.32" />
                            <stop offset="100%" stop-color="#f97316" stop-opacity="0.0" />
                        </linearGradient>
                    </defs>
                    <path d="M 0 42 C 30 42, 55 18, 85 20 C 115 22, 140 34, 170 28 C 200 22, 220 38, 240 40 L 240 60 L 0 60 Z" fill="url(#orangeSparkGradSA)" />
                    <path d="M 0 42 C 30 42, 55 18, 85 20 C 115 22, 140 34, 170 28 C 200 22, 220 38, 240 40" fill="none" stroke="#f97316" stroke-width="2.6" stroke-linecap="round" />
                </svg>
            </div>
            <div class="ds-metric-caption">
                This month inflow: <strong><?= sa_money($rev['this_month']) ?></strong>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================================================
     SECTION 2: OPERATIONAL METRIC RIBBON (8 Fast Diagnostic Controls)
     ========================================================================== -->
<div class="row g-3 mb-4">
    <!-- 1. Active Estates -->
    <div class="col-6 col-md-3 col-xl-3">
        <a href="estates?status=active" class="d-flex align-items-center gap-3 p-3 rounded-4 bg-white border text-decoration-none shadow-sm h-100 transition-all hover-translate">
            <div class="rounded-3 d-grid place-items-center text-success bg-success-subtle" style="width: 44px; height: 44px; min-width: 44px;">
                <i class="fa-solid fa-circle-check fs-5"></i>
            </div>
            <div style="min-width: 0;">
                <div class="text-muted small fw-semibold text-truncate">Active Tenancy</div>
                <div class="fs-5 fw-bold text-dark line-height-1"><?= intval($st['active_c']) ?> <span class="badge bg-success-subtle text-success fs-xs fw-bold rounded-pill"><?= intval($st['trial_c']) ?> trial</span></div>
                <div class="text-muted" style="font-size: 0.72rem;">Online & Operational</div>
            </div>
        </a>
    </div>

    <!-- 2. Grace Period -->
    <div class="col-6 col-md-3 col-xl-3">
        <a href="estates?status=grace_period" class="d-flex align-items-center gap-3 p-3 rounded-4 bg-white border text-decoration-none shadow-sm h-100 transition-all hover-translate">
            <div class="rounded-3 d-grid place-items-center text-warning bg-warning-subtle" style="width: 44px; height: 44px; min-width: 44px;">
                <i class="fa-solid fa-hourglass-half fs-5"></i>
            </div>
            <div style="min-width: 0;">
                <div class="text-muted small fw-semibold text-truncate">Grace Period</div>
                <div class="fs-5 fw-bold text-dark line-height-1"><?= intval($st['grace_c']) ?></div>
                <div class="text-warning-emphasis" style="font-size: 0.72rem;">Subscription overdue</div>
            </div>
        </a>
    </div>

    <!-- 3. Suspended / Retention -->
    <div class="col-6 col-md-3 col-xl-3">
        <a href="estates?status=suspended" class="d-flex align-items-center gap-3 p-3 rounded-4 bg-white border text-decoration-none shadow-sm h-100 transition-all hover-translate">
            <div class="rounded-3 d-grid place-items-center text-danger bg-danger-subtle" style="width: 44px; height: 44px; min-width: 44px;">
                <i class="fa-solid fa-ban fs-5"></i>
            </div>
            <div style="min-width: 0;">
                <div class="text-muted small fw-semibold text-truncate">Suspended</div>
                <div class="fs-5 fw-bold text-dark line-height-1"><?= intval($st['susp_c']) + intval($st['ret_c']) ?> <span class="badge bg-danger-subtle text-danger fs-xs fw-bold rounded-pill"><?= intval($st['del_c']) ?> del</span></div>
                <div class="text-muted" style="font-size: 0.72rem;">Retention isolation</div>
            </div>
        </a>
    </div>

    <!-- 4. Total Residents -->
    <div class="col-6 col-md-3 col-xl-3">
        <a href="estates" class="d-flex align-items-center gap-3 p-3 rounded-4 bg-white border text-decoration-none shadow-sm h-100 transition-all hover-translate">
            <div class="rounded-3 d-grid place-items-center text-primary bg-primary-subtle" style="width: 44px; height: 44px; min-width: 44px;">
                <i class="fa-solid fa-users fs-5"></i>
            </div>
            <div style="min-width: 0;">
                <div class="text-muted small fw-semibold text-truncate">Total Residents</div>
                <div class="fs-5 fw-bold text-dark line-height-1"><?= number_format($st['residents']) ?></div>
                <div class="text-muted" style="font-size: 0.72rem;">Across all estates</div>
            </div>
        </a>
    </div>

    <!-- 5. Open Tickets -->
    <div class="col-6 col-md-3 col-xl-3">
        <a href="tickets" class="d-flex align-items-center gap-3 p-3 rounded-4 bg-white border text-decoration-none shadow-sm h-100 transition-all hover-translate">
            <div class="rounded-3 d-grid place-items-center text-info bg-info-subtle" style="width: 44px; height: 44px; min-width: 44px;">
                <i class="fa-solid fa-ticket fs-5"></i>
            </div>
            <div style="min-width: 0;">
                <div class="text-muted small fw-semibold text-truncate">Support Queue</div>
                <div class="fs-5 fw-bold text-dark line-height-1"><?= intval($tix['c']) ?> <span class="badge bg-info-subtle text-info fs-xs fw-bold rounded-pill">open</span></div>
                <div class="text-muted" style="font-size: 0.72rem;">Operator assistance</div>
            </div>
        </a>
    </div>

    <!-- 6. Security Alerts -->
    <div class="col-6 col-md-3 col-xl-3">
        <a href="alerts" class="d-flex align-items-center gap-3 p-3 rounded-4 bg-white border text-decoration-none shadow-sm h-100 transition-all hover-translate">
            <div class="rounded-3 d-grid place-items-center text-danger bg-danger-subtle" style="width: 44px; height: 44px; min-width: 44px;">
                <i class="fa-solid fa-shield-halved fs-5"></i>
            </div>
            <div style="min-width: 0;">
                <div class="text-muted small fw-semibold text-truncate">Security Alerts</div>
                <div class="fs-5 fw-bold text-dark line-height-1"><?= intval($alerts['c']) ?> <span class="badge bg-<?= $alerts['c'] > 0 ? 'danger' : 'success' ?>-subtle text-<?= $alerts['c'] > 0 ? 'danger' : 'success' ?> fs-xs fw-bold rounded-pill"><?= $alerts['c'] > 0 ? 'active' : 'clear' ?></span></div>
                <div class="text-muted" style="font-size: 0.72rem;">Anomaly protection</div>
            </div>
        </a>
    </div>

    <!-- 7. System Errors -->
    <div class="col-6 col-md-3 col-xl-3">
        <a href="monitoring" class="d-flex align-items-center gap-3 p-3 rounded-4 bg-white border text-decoration-none shadow-sm h-100 transition-all hover-translate">
            <div class="rounded-3 d-grid place-items-center text-secondary bg-secondary-subtle" style="width: 44px; height: 44px; min-width: 44px;">
                <i class="fa-solid fa-chart-line fs-5"></i>
            </div>
            <div style="min-width: 0;">
                <div class="text-muted small fw-semibold text-truncate">System Errors</div>
                <div class="fs-5 fw-bold text-dark line-height-1"><?= intval($errs['c']) ?> <span class="badge bg-<?= $errs['c'] > 0 ? 'danger' : 'success' ?>-subtle text-<?= $errs['c'] > 0 ? 'danger' : 'success' ?> fs-xs fw-bold rounded-pill"><?= $errs['c'] > 0 ? 'issues' : 'healthy' ?></span></div>
                <div class="text-muted" style="font-size: 0.72rem;">Platform stability</div>
            </div>
        </a>
    </div>

    <!-- 8. Failed Payments -->
    <div class="col-6 col-md-3 col-xl-3">
        <a href="billing" class="d-flex align-items-center gap-3 p-3 rounded-4 bg-white border text-decoration-none shadow-sm h-100 transition-all hover-translate">
            <div class="rounded-3 d-grid place-items-center text-warning bg-warning-subtle" style="width: 44px; height: 44px; min-width: 44px;">
                <i class="fa-solid fa-credit-card fs-5"></i>
            </div>
            <div style="min-width: 0;">
                <div class="text-muted small fw-semibold text-truncate">Payments Log</div>
                <div class="fs-5 fw-bold text-dark line-height-1"><?= intval($pay['failed_c']) ?> <span class="badge bg-warning-subtle text-warning-emphasis fs-xs fw-bold rounded-pill"><?= intval($pay['pending_c']) ?> pending</span></div>
                <div class="text-muted" style="font-size: 0.72rem;">Last 30 days</div>
            </div>
        </a>
    </div>
</div>

<!-- ==========================================================================
     SECTION 3: PRIMARY INTELLIGENCE DUAL PANEL (12-Mo Revenue Spline + Live Support Queue)
     ========================================================================== -->
<div class="ds-overview-grid mb-4">
    <!-- Left: 12-Month Revenue Spline Area Chart (Chart.js) -->
    <div class="ds-card-panel">
        <div class="ds-panel-header">
            <div class="ds-panel-title-group">
                <h4>Platform Revenue Trend</h4>
                <p class="ds-panel-subtitle">Monthly invoice settlements in Naira (₦) for past 12 months</p>
            </div>
            <div class="ds-panel-controls">
                <a href="billing" class="ds-see-details-btn">
                    <span>Manage Invoices</span>
                    <i class="fa-solid fa-arrow-right ms-1" style="font-size: 10px;"></i>
                </a>
            </div>
        </div>
        <div class="ds-chart-container" style="height: 310px; position: relative;">
            <canvas id="superRevenueSplineChart"></canvas>
        </div>
    </div>

    <!-- Right: Real Support Ticket Queue & Platform Communications -->
    <div class="ds-card-panel">
        <div class="ds-panel-header">
            <div class="ds-panel-title-group">
                <h4>Support Queue</h4>
                <p class="ds-panel-subtitle">Active requests awaiting operator resolution</p>
            </div>
            <div class="ds-panel-controls">
                <a href="tickets" class="ds-see-details-btn">
                    <span>All Tickets (<?= intval($tix['c']) ?>)</span>
                </a>
            </div>
        </div>

        <div class="ds-messages-list">
            <?php if ($open_tickets && $open_tickets->num_rows > 0): ?>
                <?php while ($tk = $open_tickets->fetch_assoc()): 
                    $prio_color = match($tk['priority']) {
                        'urgent', 'high' => 'bg-danger text-white',
                        'normal' => 'bg-primary text-white',
                        default => 'bg-secondary text-white'
                    };
                    $inits = strtoupper(substr($tk['created_by_name'] ?: 'Op', 0, 2));
                ?>
                <a href="ticket?id=<?= intval($tk['id']) ?>" class="ds-message-item" title="Open ticket <?= e($tk['ticket_no']) ?>">
                    <div class="ds-message-avatar purple"><?= e($inits) ?></div>
                    <div class="ds-message-info">
                        <div class="d-flex align-items-center gap-2">
                            <h5 class="ds-message-name mb-0 text-truncate" style="max-width: 170px;"><?= e($tk['subject']) ?></h5>
                            <span class="badge <?= $prio_color ?> rounded-pill" style="font-size: 0.65rem; padding: 2px 6px;"><?= e(ucfirst($tk['priority'])) ?></span>
                        </div>
                        <p class="ds-message-preview">
                            <span class="text-primary fw-semibold"><?= e($tk['estate_name'] ?? 'Platform') ?></span> &middot; By: <?= e($tk['created_by_name'] ?: 'Estate Staff') ?>
                        </p>
                    </div>
                    <div class="ds-message-meta">
                        <span class="ds-message-time text-nowrap"><?= sa_d($tk['created_at']) ?></span>
                        <span class="badge bg-light text-secondary border rounded-pill" style="font-size: 0.65rem;"><?= e($tk['ticket_no']) ?></span>
                    </div>
                </a>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="text-center py-5 text-muted">
                    <i class="fa-solid fa-circle-check text-success fs-1 mb-2 d-block"></i>
                    <div class="fw-bold text-dark">Queue is Clear!</div>
                    <small>No pending support tickets need immediate platform operator action.</small>
                </div>
            <?php endif; ?>
        </div>

        <a href="tickets" class="ds-view-all-messages-btn">
            <span>Open Support Dispatch Center</span>
            <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 11px;"></i>
        </a>
    </div>
</div>

<!-- ==========================================================================
     SECTION 4: REGISTERED ESTATES FLEET DIRECTORY (Live DB Records)
     ========================================================================== -->
<div class="ds-property-table-card mb-4">
    <div class="ds-panel-header mb-3">
        <div class="ds-panel-title-group">
            <h4>Registered Estates Fleet</h4>
            <p class="ds-panel-subtitle">Live multi-tenant instances actively governed on this SaaS core</p>
        </div>
        <div class="ds-panel-controls d-flex align-items-center gap-2 flex-wrap">
            <form method="post" class="d-inline m-0">
                <button type="submit" name="refresh_tenant_stats" value="1" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-semibold d-inline-flex align-items-center gap-1.5" title="<?= $tenant_last_checked ? 'Last probed ' . sa_dt($tenant_last_checked) : 'Probe all tenant DBs' ?>">
                    <i class="fa-solid fa-arrows-rotate"></i> Refresh DBs
                </button>
            </form>
            <a href="create_estate" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold d-inline-flex align-items-center gap-2">
                <i class="fa-solid fa-circle-plus"></i> Register Estate
            </a>
            <a href="estates" class="ds-see-details-btn" style="padding: 6px 16px; gap: 6px;">
                <i class="fa-solid fa-sliders"></i> View All (<?= intval($st['total']) ?>)
            </a>
        </div>
    </div>

    <?php 
    if ($live_estates && $live_estates->num_rows > 0):
        $grads = [
            'linear-gradient(135deg, #6259e8 0%, #4338ca 100%)',
            'linear-gradient(135deg, #0d9488 0%, #0f766e 100%)',
            'linear-gradient(135deg, #d97706 0%, #b45309 100%)',
            'linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%)',
            'linear-gradient(135deg, #ec4899 0%, #be185d 100%)'
        ];
        $gi = 0;
        while ($le = $live_estates->fetch_assoc()):
            $gradBg = $grads[$gi % count($grads)];
            $gi++;
            $rc = intval($le['resident_count'] ?? 0);
            $estate_initials = strtoupper(substr($le['name'], 0, 2));
    ?>
    <div class="ds-property-row" onclick="window.location.href='estate?id=<?= $le['id'] ?>'" style="cursor: pointer; transition: all 0.2s;" title="Click to view and manage <?= e($le['name']) ?>">
        <div class="ds-property-info-cell">
            <div class="ds-property-thumb d-grid place-items-center text-white fw-bold fs-6" style="background: <?= $gradBg ?>;">
                <?= e($estate_initials) ?>
            </div>
            <div class="ds-property-details">
                <h5 class="mb-0"><a href="estate?id=<?= $le['id'] ?>" class="text-decoration-none text-dark" onclick="event.stopPropagation();"><?= e($le['name']) ?></a></h5>
                <span>Code: <strong class="text-primary"><?= e($le['domain_prefix'] ?? 'EST') ?></strong> &middot; <?= $rc ?> Residents<?= isset($le['open_maintenance']) && intval($le['open_maintenance']) > 0 ? ' &middot; <span class="text-warning-emphasis fw-semibold">' . intval($le['open_maintenance']) . ' Maint.</span>' : '' ?></span>
            </div>
        </div>
        <div class="ds-property-col-cell">
            <small>Database Health</small>
            <?php if (empty($le['db_name'])): ?>
                <span class="badge bg-secondary-subtle text-secondary rounded-pill fw-semibold">No DB</span>
            <?php elseif (isset($le['db_ok']) && $le['db_ok'] == 0): ?>
                <span class="badge bg-danger-subtle text-danger rounded-pill fw-semibold" title="<?= e($le['db_error'] ?? '') ?>"><i class="fa-solid fa-triangle-exclamation me-1"></i>Unreachable</span>
            <?php else: ?>
                <span class="badge bg-success-subtle text-success rounded-pill fw-semibold"><i class="fa-solid fa-circle text-success me-1" style="font-size: 7px;"></i>Connected</span>
            <?php endif; ?>
        </div>
        <div class="ds-property-col-cell">
            <small>City / Region</small>
            <span class="fw-semibold text-dark"><?= e($le['city'] ?: 'Nigeria') ?></span>
        </div>
        <div class="ds-property-col-cell">
            <small>Contact Channel</small>
            <span><?= e($le['contact_phone'] ?: ($le['contact_email'] ?: 'N/A')) ?></span>
        </div>
        <div class="ds-property-col-cell">
            <small>Registered</small>
            <span><?= date('j M Y', strtotime($le['created_at'])) ?></span>
        </div>
        <div class="ds-property-col-cell">
            <small>Status</small>
            <span><?= sa_badge($le['status']) ?></span>
        </div>
        <div class="text-end d-flex align-items-center gap-1 justify-content-end" onclick="event.stopPropagation();">
            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-2.5 py-1 fw-semibold shadow-xs" data-bs-toggle="modal" data-bs-target="#quickEditEstateModal" onclick="openEditEstateModal(<?= htmlspecialchars(json_encode($le), ENT_QUOTES) ?>)" title="Quick Edit Estate">
                <i class="fa-solid fa-pen-to-square"></i> Edit
            </button>
            <a href="estate?id=<?= $le['id'] ?>" class="ds-edit-pill-btn" title="Inspect & Configure Estate">
                <i class="fa-solid fa-gear"></i> Manage
            </a>
        </div>
    </div>
    <?php endwhile; else: ?>
    <div class="text-center py-5 text-muted">
        <i class="fa-solid fa-building-circle-exclamation fs-1 mb-2 d-block"></i>
        <div class="fw-bold text-dark">No Estates Registered</div>
        <small>Get started by clicking "Register Estate" above.</small>
    </div>
    <?php endif; ?>
</div>

<!-- ==========================================================================
     SECTION 5: 4-CARD DIAGNOSTIC VISUAL BREAKDOWN & ATTENTION HUB
     ========================================================================== -->
<div class="row g-4 mb-4">
    <!-- Hub 1: Estate Status Distribution (Doughnut) -->
    <div class="col-lg-6 col-xl-3">
        <div class="ds-card-panel h-100">
            <div class="ds-panel-header mb-2">
                <div class="ds-panel-title-group">
                    <h4>Tenancy Status</h4>
                    <p class="ds-panel-subtitle">Estate breakdown</p>
                </div>
            </div>
            <div style="height: 220px; position: relative;">
                <canvas id="estateStatusChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Hub 2: Subscription Status Breakdown (Pie) -->
    <div class="col-lg-6 col-xl-3">
        <div class="ds-card-panel h-100">
            <div class="ds-panel-header mb-2">
                <div class="ds-panel-title-group">
                    <h4>Subscriptions</h4>
                    <p class="ds-panel-subtitle">Billing health</p>
                </div>
            </div>
            <div style="height: 220px; position: relative;">
                <canvas id="subscriptionStatusChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Hub 3: Payment Status Breakdown (Bar) -->
    <div class="col-lg-6 col-xl-3">
        <div class="ds-card-panel h-100">
            <div class="ds-panel-header mb-2">
                <div class="ds-panel-title-group">
                    <h4>Payment Volume</h4>
                    <p class="ds-panel-subtitle">30-day settlement</p>
                </div>
            </div>
            <div style="height: 220px; position: relative;">
                <canvas id="paymentStatusChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Hub 4: Immediate "Needs Attention" Action Center -->
    <div class="col-lg-6 col-xl-3">
        <div class="ds-card-panel h-100">
            <div class="ds-panel-header mb-2">
                <div class="ds-panel-title-group">
                    <h4>Needs Attention</h4>
                    <p class="ds-panel-subtitle">Critical items</p>
                </div>
            </div>
            <div class="d-flex flex-column gap-2" style="max-height: 220px; overflow-y: auto;">
                <?php if (!empty($needs_attention)): ?>
                    <?php foreach ($needs_attention as $item): ?>
                        <div class="d-flex align-items-start gap-2 p-2 rounded-3 bg-light border small">
                            <i class="fa-solid <?= $item['icon'] ?> text-<?= $item['type'] ?> mt-1"></i>
                            <div class="flex-grow-1" style="font-size: 0.78rem; line-height: 1.35;">
                                <?= $item['text'] ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-4 text-muted my-auto">
                        <i class="fa-solid fa-circle-check text-success fs-3 mb-2 d-block"></i>
                        <span class="small fw-semibold">Platform fully healthy. No retention or expiring items.</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================================================
     SECTION 6: SECURITY ALERTS & RECENT PLATFORM SIGN-IN AUDIT
     ========================================================================== -->
<div class="row g-4 mb-4">
    <!-- Security Alerts -->
    <div class="col-lg-6">
        <div class="ds-card-panel h-100">
            <div class="ds-panel-header">
                <div class="ds-panel-title-group">
                    <h4>Security Alerts</h4>
                    <p class="ds-panel-subtitle">Threat and access anomaly monitoring</p>
                </div>
                <a href="alerts" class="ds-see-details-btn">View All Alerts</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Severity</th>
                            <th>Incident</th>
                            <th class="text-end">Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
                    $alerts_feed = $platform->query("SELECT * FROM security_alerts WHERE status='open' ORDER BY id DESC LIMIT 5");
                    $na = 0;
                    if ($alerts_feed):
                        while ($al = $alerts_feed->fetch_assoc()):
                            $na++;
                    ?>
                        <tr>
                            <td style="width: 90px;"><?= sa_badge($al['severity']) ?></td>
                            <td class="small fw-semibold"><?= e($al['message']) ?></td>
                            <td class="small text-muted text-end text-nowrap"><?= sa_dt($al['created_at']) ?></td>
                        </tr>
                    <?php 
                        endwhile; 
                    endif;
                    if (!$na): 
                    ?>
                        <tr>
                            <td colspan="3" class="text-muted small py-4 text-center">
                                <i class="fa-solid fa-circle-check text-success me-1"></i> No active security warnings detected. Shield status nominal.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Sign-In Audit Logs -->
    <div class="col-lg-6">
        <div class="ds-card-panel h-100">
            <div class="ds-panel-header">
                <div class="ds-panel-title-group">
                    <h4>Recent Sign-Ins</h4>
                    <p class="ds-panel-subtitle">Platform control plane authentication audits</p>
                </div>
                <a href="logins" class="ds-see-details-btn">View All Logs</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>User / Account</th>
                            <th>IP Address</th>
                            <th>Result</th>
                            <th class="text-end">Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
                    $login_logs = $platform->query("SELECT * FROM platform_login_logs WHERE event_type IN ('login_success','login_failed','login_blocked') ORDER BY id DESC LIMIT 5");
                    $nl = 0;
                    if ($login_logs):
                        while ($ll = $login_logs->fetch_assoc()):
                            $nl++;
                    ?>
                        <tr>
                            <td class="small fw-semibold text-dark"><?= e($ll['email'] ?: $ll['user_name']) ?></td>
                            <td class="small text-muted"><code><?= e($ll['ip_address']) ?></code></td>
                            <td><?= $ll['success'] ? sa_badge('success') : sa_badge('failed') ?></td>
                            <td class="small text-muted text-end text-nowrap"><?= sa_dt($ll['logged_in_at']) ?></td>
                        </tr>
                    <?php 
                        endwhile; 
                    endif;
                    if (!$nl): 
                    ?>
                        <tr>
                            <td colspan="4" class="text-muted small py-4 text-center">
                                No recent login activity recorded.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================================================
     CHART.JS SCRIPTS & CONFIGURATIONS
     ========================================================================== -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const chartColors = {
        primary: '#6259e8',
        success: '#10b981',
        warning: '#f59e0b',
        danger: '#ef4444',
        info: '#3b82f6',
        secondary: '#8c9bb0'
    };

    // 1. Dribbble Spline Area Chart: 12-Month Revenue Inflow in Naira (₦)
    const superRevCtx = document.getElementById('superRevenueSplineChart');
    if (superRevCtx) {
        const ctx = superRevCtx.getContext('2d');
        const purpleAreaGrad = ctx.createLinearGradient(0, 0, 0, 300);
        purpleAreaGrad.addColorStop(0, 'rgba(98, 89, 232, 0.14)');
        purpleAreaGrad.addColorStop(1, 'rgba(98, 89, 232, 0.01)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($revenue_months) ?>,
                datasets: [{
                    label: 'Settled Revenue (₦)',
                    data: <?= json_encode($revenue_data) ?>,
                    borderColor: '#6259e8',
                    backgroundColor: purpleAreaGrad,
                    borderWidth: 1.5,
                    fill: true,
                    tension: 0.35,
                    pointRadius: 0,
                    pointBackgroundColor: '#6259e8',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 1,
                    pointHoverRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleFont: { family: 'Plus Jakarta Sans', size: 12, weight: 'bold' },
                        bodyFont: { family: 'Plus Jakarta Sans', size: 12 },
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                return ' Revenue: ₦' + Number(context.parsed.y).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#8c9bb0', font: { family: 'Plus Jakarta Sans', size: 10 }, maxTicksLimit: window.innerWidth < 768 ? 6 : 12, maxRotation: 0 }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(232, 237, 245, 0.8)', drawBorder: false },
                        ticks: {
                            color: '#8c9bb0',
                            font: { family: 'Plus Jakarta Sans', size: 10 },
                            maxTicksLimit: 5,
                            callback: function(val) {
                                const n = Number(val);
                                if (n >= 1e6) return '₦' + (n / 1e6).toFixed(n % 1e6 ? 1 : 0) + 'M';
                                if (n >= 1e3) return '₦' + (n / 1e3).toFixed(n % 1e3 ? 1 : 0) + 'k';
                                return '₦' + n;
                            }
                        }
                    }
                }
            }
        });
    }

    // 2. Estate Status Distribution (Doughnut)
    const estateStatusCtx = document.getElementById('estateStatusChart');
    if (estateStatusCtx) {
        new Chart(estateStatusCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Active', 'Trial', 'Grace Period', 'Suspended', 'Retention', 'Onboarding'],
                datasets: [{
                    data: [
                        <?= intval($st['active_c']) ?>, 
                        <?= intval($st['trial_c']) ?>, 
                        <?= intval($st['grace_c']) ?>, 
                        <?= intval($st['susp_c']) ?>, 
                        <?= intval($st['ret_c']) ?>, 
                        <?= intval($st['onb_c']) ?>
                    ],
                    backgroundColor: [
                        chartColors.success,
                        chartColors.primary,
                        chartColors.warning,
                        chartColors.danger,
                        '#d97706',
                        chartColors.secondary
                    ],
                    borderColor: '#fff',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 8, font: { size: 10 } }
                    }
                }
            }
        });
    }

    // 3. Subscription Status Breakdown (Pie)
    const subscriptionStatusCtx = document.getElementById('subscriptionStatusChart');
    if (subscriptionStatusCtx) {
        new Chart(subscriptionStatusCtx.getContext('2d'), {
            type: 'pie',
            data: {
                labels: <?= json_encode($sub_labels ?: ['No Subscriptions']) ?>,
                datasets: [{
                    data: <?= json_encode($sub_data ?: [1]) ?>,
                    backgroundColor: [
                        chartColors.success,
                        chartColors.primary,
                        chartColors.warning,
                        '#d97706',
                        chartColors.danger,
                        chartColors.secondary
                    ],
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 10, font: { size: 10 } }
                    }
                }
            }
        });
    }

    // 4. Payment Status (Last 30 Days) (Bar)
    const paymentStatusCtx = document.getElementById('paymentStatusChart');
    if (paymentStatusCtx) {
        new Chart(paymentStatusCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($payment_labels ?: ['No Payments']) ?>,
                datasets: [{
                    label: 'Transactions',
                    data: <?= json_encode($payment_data ?: [0]) ?>,
                    backgroundColor: [
                        chartColors.success,
                        chartColors.warning,
                        chartColors.danger
                    ],
                    borderRadius: 4,
                    maxBarThickness: 28
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 10 } }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, font: { size: 10 } }
                    }
                }
            }
        });
    }
});
</script>

<!-- Quick Edit Estate Modal -->
<div class="modal fade" id="quickEditEstateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header border-bottom p-3.5">
                <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2">
                    <i class="fa-solid fa-pen-to-square text-primary"></i>
                    <span>Edit Estate: <span id="quickEditEstateTitle" class="text-primary"></span></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="index">
                <input type="hidden" name="update_estate_quick" value="1">
                <input type="hidden" name="estate_id" id="quickEditEstateId">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label small fw-semibold">Estate Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="quickEditEstateName" class="form-control" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold">Estate Code / Prefix <span class="text-danger">*</span></label>
                            <input type="text" name="domain_prefix" id="quickEditEstatePrefix" class="form-control font-monospace" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Contact Email</label>
                            <input type="email" name="contact_email" id="quickEditEstateEmail" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Contact Phone</label>
                            <input type="text" name="contact_phone" id="quickEditEstatePhone" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">City</label>
                            <input type="text" name="city" id="quickEditEstateCity" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Status</label>
                            <select name="status" id="quickEditEstateStatus" class="form-select">
                                <option value="active">Active</option>
                                <option value="trial">Trial</option>
                                <option value="grace_period">Grace Period</option>
                                <option value="suspended">Suspended</option>
                                <option value="retention">Retention</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Address</label>
                            <input type="text" name="address" id="quickEditEstateAddress" class="form-control" placeholder="Physical street address">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Internal Notes</label>
                            <textarea name="notes" id="quickEditEstateNotes" class="form-control" rows="2" placeholder="Administrative remarks, account history..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top p-3 d-flex justify-content-between">
                    <a href="#" id="quickEditEstateManageLink" class="btn btn-sm btn-outline-secondary">
                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Open Full Estate Manager
                    </a>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary px-3 fw-semibold">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditEstateModal(estate) {
    if (typeof estate === 'string') {
        try { estate = JSON.parse(estate); } catch(e) {}
    }
    const el = document.getElementById('quickEditEstateModal');
    if (!el) return;
    document.getElementById('quickEditEstateId').value = estate.id || '';
    document.getElementById('quickEditEstateTitle').textContent = estate.name || '';
    document.getElementById('quickEditEstateName').value = estate.name || '';
    document.getElementById('quickEditEstatePrefix').value = estate.domain_prefix || '';
    document.getElementById('quickEditEstateEmail').value = estate.contact_email || '';
    document.getElementById('quickEditEstatePhone').value = estate.contact_phone || '';
    document.getElementById('quickEditEstateCity').value = estate.city || '';
    document.getElementById('quickEditEstateAddress').value = estate.address || '';
    document.getElementById('quickEditEstateStatus').value = estate.status || 'active';
    document.getElementById('quickEditEstateNotes').value = estate.notes || '';
    document.getElementById('quickEditEstateManageLink').href = 'estate?id=' + (estate.id || '');

    if (window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(el).show();
    } else if (window.openModal) {
        window.openModal('quickEditEstateModal');
    }
}
</script>

<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
