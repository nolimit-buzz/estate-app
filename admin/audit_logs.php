<?php
// admin/audit_logs.php
require_once '../config.php';
require_once '../includes/auth_guard.php';
requireAdminAccess();

include '../includes/header.php';
include '../includes/sidebar.php';

$estate_id = get_estate_id();

// Filter parameters
$filter_staff = isset($_GET['staff_id']) ? intval($_GET['staff_id']) : 0;
$filter_module = isset($_GET['module_type']) ? trim($_GET['module_type']) : 'all';
$filter_date_from = isset($_GET['date_from']) ? $conn->real_escape_string($_GET['date_from']) : '';
$filter_date_to = isset($_GET['date_to']) ? $conn->real_escape_string($_GET['date_to']) : '';
$search_query = isset($_GET['q']) ? $conn->real_escape_string(trim($_GET['q'])) : '';

// Build Query
$where_clauses = ["a.estate_id = $estate_id"];

if ($filter_staff > 0) {
    $where_clauses[] = "a.user_id = $filter_staff";
}

if ($filter_module !== 'all' && !empty($filter_module)) {
    if ($filter_module === 'security') {
        $where_clauses[] = "(LOWER(a.module) LIKE '%security%' OR LOWER(a.action) LIKE '%visitor%')";
    } elseif ($filter_module === 'finance') {
        $where_clauses[] = "(LOWER(a.module) LIKE '%finance%' OR LOWER(a.action) LIKE '%payment%' OR LOWER(a.action) LIKE '%receipt%')";
    } elseif ($filter_module === 'payment_methods') {
        $where_clauses[] = "(LOWER(a.action) LIKE '%payment method%' OR LOWER(a.details) LIKE '%payment method%')";
    } elseif ($filter_module === 'maintenance') {
        $where_clauses[] = "(LOWER(a.module) LIKE '%maintenance%' OR LOWER(a.action) LIKE '%maintenance%' OR LOWER(a.action) LIKE '%need%' OR LOWER(a.action) LIKE '%work order%')";
    } else {
        $safe_mod = $conn->real_escape_string($filter_module);
        $where_clauses[] = "LOWER(a.module) = LOWER('$safe_mod')";
    }
}

if (!empty($filter_date_from)) {
    $where_clauses[] = "DATE(a.timestamp) >= '$filter_date_from'";
}

if (!empty($filter_date_to)) {
    $where_clauses[] = "DATE(a.timestamp) <= '$filter_date_to'";
}

if (!empty($search_query)) {
    $where_clauses[] = "(a.action LIKE '%$search_query%' OR a.details LIKE '%$search_query%' OR u.name LIKE '%$search_query%' OR a.ip_address LIKE '%$search_query%')";
}

$where_sql = implode(' AND ', $where_clauses);

// Fetch Filtered Logs
$logs = $conn->query("SELECT a.*, u.name as staff_name, u.role as staff_role 
                      FROM audit_logs a 
                      LEFT JOIN users u ON a.user_id = u.id 
                      WHERE $where_sql 
                      ORDER BY a.timestamp DESC 
                      LIMIT 250");

// Fetch All Staff Members for Dropdown Filter
$staff_members = $conn->query("SELECT id, name, role FROM users WHERE estate_id = $estate_id AND role IN ('admin', 'manager', 'staff', 'security') ORDER BY name ASC");

// Fetch Aggregate KPIs for Header
$kpi_today_actions = $conn->query("SELECT COUNT(*) as total FROM audit_logs WHERE estate_id = $estate_id AND DATE(timestamp) = CURRENT_DATE()")->fetch_assoc()['total'] ?? 0;

$kpi_visitors = $conn->query("SELECT COUNT(*) as total FROM audit_logs WHERE estate_id = $estate_id AND (LOWER(module) LIKE '%security%' OR LOWER(action) LIKE '%visitor%') AND DATE(timestamp) = CURRENT_DATE()")->fetch_assoc()['total'] ?? 0;

$kpi_receipts = $conn->query("SELECT COUNT(*) as total FROM audit_logs WHERE estate_id = $estate_id AND (LOWER(action) LIKE '%payment%' OR LOWER(action) LIKE '%receipt%') AND DATE(timestamp) = CURRENT_DATE()")->fetch_assoc()['total'] ?? 0;

$kpi_maintenance = $conn->query("SELECT COUNT(*) as total FROM audit_logs WHERE estate_id = $estate_id AND (LOWER(module) LIKE '%maintenance%' OR LOWER(action) LIKE '%maintenance%' OR LOWER(action) LIKE '%need%') AND DATE(timestamp) = CURRENT_DATE()")->fetch_assoc()['total'] ?? 0;
?>

<div class="page-header-futuristic mb-4">
    <div>
        <div class="header-breadcrumbs">
            <a href="index">Dashboard</a>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span>Governance</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span class="active">System Audit Logs</span>
        </div>
        <h1 class="page-title">Staff Action &amp; Security Audit Console</h1>
        <p class="page-subtitle">Real-time immutable audit trail: gate access decisions, receipt generation, privilege changes, and work orders.</p>
    </div>
    <div class="header-actions">
        <a href="audit_logs" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-rotate-left me-1"></i> Reset Filters
        </a>
        <button class="btn btn-sm text-white" style="background: #0f172a;" onclick="window.print()">
            <i class="fa-solid fa-print me-1"></i> Print Audit Trail
        </button>
    </div>
</div>

<!-- ==========================================
     EXECUTIVE KPI METRICS RIBBON (4 PILLARS)
     ========================================== -->
<div class="row g-3 mb-4">
    <!-- Pillar 1: Today's Total Events -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Today's Staff Actions</span>
                    <div class="kpi-value"><?= number_format($kpi_today_actions) ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-bolt"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Recorded Today</span>
                <span class="mature-badge mature-badge-emerald">Live Telemetry</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?= min(100, max(20, $kpi_today_actions * 10)) ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 2: Gate Passes Handled -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Gate Passes Handled</span>
                    <div class="kpi-value"><?= number_format($kpi_visitors) ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Access Clearances</span>
                <span class="mature-badge mature-badge-sky">Perimeter</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?= min(100, max(15, $kpi_visitors * 12)) ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 3: Receipts & Collections -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Receipts Generated</span>
                    <div class="kpi-value"><?= number_format($kpi_receipts) ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-receipt"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Financial Ledger</span>
                <span class="mature-badge mature-badge-primary">Receipts</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?= min(100, max(15, $kpi_receipts * 15)) ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 4: Maintenance Actions -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Needs Attended</span>
                    <div class="kpi-value"><?= number_format($kpi_maintenance) ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-wrench"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Work Orders</span>
                <span class="mature-badge mature-badge-amber">Facilities</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?= min(100, max(15, $kpi_maintenance * 20)) ?>%;"></div>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     QUICK MODULE FILTER PILLS
     ========================================== -->
<div class="d-flex gap-2 mb-3 overflow-x-auto pb-1">
    <?php
    $nav_tabs = [
        'all' => ['label' => 'All Operations', 'icon' => 'fa-border-all'],
        'security' => ['label' => 'Gate & Perimeter', 'icon' => 'fa-door-open'],
        'finance' => ['label' => 'Receipts & Payments', 'icon' => 'fa-receipt'],
        'payment_methods' => ['label' => 'Payment Modes', 'icon' => 'fa-credit-card'],
        'maintenance' => ['label' => 'Maintenance & Needs', 'icon' => 'fa-wrench'],
    ];
    foreach ($nav_tabs as $tab_key => $tab_val):
        $is_curr = ($filter_module === $tab_key);
        $tab_url = "audit_logs?module_type=" . urlencode($tab_key) . ($filter_staff > 0 ? "&staff_id=$filter_staff" : "");
    ?>
        <a href="<?= $tab_url ?>" class="filter-btn-pill <?= $is_curr ? 'active' : '' ?>">
            <i class="fa-solid <?= $tab_val['icon'] ?>"></i> <?= $tab_val['label'] ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- ==========================================
     MULTI-CRITERIA FILTER BAR
     ========================================== -->
<div class="futuristic-filter-bar mb-4">
    <form method="GET" action="audit_logs" class="row g-3 align-items-end">
        <input type="hidden" name="module_type" value="<?= htmlspecialchars($filter_module) ?>">

        <div class="col-12 col-md-3">
            <label class="form-label small fw-semibold text-secondary mb-1">Attending Staff</label>
            <select name="staff_id" class="form-select form-select-sm">
                <option value="0">All Staff Members</option>
                <?php if ($staff_members): ?>
                    <?php while ($sm = $staff_members->fetch_assoc()): ?>
                        <option value="<?= $sm['id'] ?>" <?= ($filter_staff == $sm['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sm['name']) ?> (<?= ucfirst($sm['role']) ?>)
                        </option>
                    <?php endwhile; ?>
                <?php endif; ?>
            </select>
        </div>

        <div class="col-12 col-md-3">
            <label class="form-label small fw-semibold text-secondary mb-1">Search Details / Keyword</label>
            <input type="text" name="q" class="form-control form-control-sm" value="<?= htmlspecialchars($search_query) ?>" placeholder="Receipt #, visitor code, action...">
        </div>

        <div class="col-6 col-md-2">
            <label class="form-label small fw-semibold text-secondary mb-1">Date From</label>
            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date_from) ?>">
        </div>

        <div class="col-6 col-md-2">
            <label class="form-label small fw-semibold text-secondary mb-1">Date To</label>
            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date_to) ?>">
        </div>

        <div class="col-12 col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-primary w-100 fw-semibold" title="Apply Filter">
                <i class="fa-solid fa-filter me-1"></i> Filter
            </button>
            <a href="audit_logs" class="btn btn-sm btn-outline-secondary" title="Reset Filters"><i class="fa-solid fa-rotate-left"></i></a>
        </div>
    </form>
</div>

<!-- ==========================================
     AUDIT ACTIVITY DATA TABLE PANEL
     ========================================== -->
<div class="mature-card mb-4">
    <div class="mature-card-header">
        <div>
            <h3 class="mature-card-title">
                <i class="fa-solid fa-clipboard-check text-secondary"></i> Staff Audit Activity Records
            </h3>
            <p class="text-secondary small mb-0">Immutable tracking of operator dispatches, clearances, and administrative events</p>
        </div>
        <span class="mature-badge mature-badge-slate">
            <i class="fa-solid fa-database me-1"></i><?= $logs ? $logs->num_rows : 0 ?> Events Loaded
        </span>
    </div>

    <div class="mature-card-body p-0">
        <div class="table-responsive">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th class="ps-4">Timestamp</th>
                        <th>Attending Staff</th>
                        <th>Module / Area</th>
                        <th>Action Executed</th>
                        <th>Activity Details &amp; Context</th>
                        <th class="text-end pe-4">Terminal IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$logs || $logs->num_rows == 0): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-secondary">
                                <i class="fa-solid fa-shield-halved fs-1 text-muted mb-2 d-block"></i>
                                <div class="fw-semibold text-slate-800">No staff activity records found</div>
                                <div class="small text-muted mt-1">Try adjusting the staff member filter, module tab, or date range.</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php while ($l = $logs->fetch_assoc()): ?>
                            <?php
                            $role = strtolower($l['staff_role'] ?? 'system');
                            $role_badge = 'mature-badge-slate';
                            if ($role === 'admin') $role_badge = 'mature-badge-primary';
                            elseif ($role === 'manager') $role_badge = 'mature-badge-sky';
                            elseif ($role === 'security') $role_badge = 'mature-badge-emerald';
                            elseif ($role === 'staff') $role_badge = 'mature-badge-amber';

                            $mod = strtolower($l['module'] ?? '');
                            $mod_badge = 'mature-badge-slate';
                            if (strpos($mod, 'security') !== false) $mod_badge = 'mature-badge-sky';
                            elseif (strpos($mod, 'finance') !== false || strpos($mod, 'payment') !== false) $mod_badge = 'mature-badge-emerald';
                            elseif (strpos($mod, 'maintenance') !== false) $mod_badge = 'mature-badge-amber';
                            elseif (strpos($mod, 'zones') !== false) $mod_badge = 'mature-badge-primary';
                            ?>
                            <tr>
                                <td class="ps-4 text-nowrap">
                                    <div class="fw-bold text-slate-900"><?= date('M d, Y', strtotime($l['timestamp'])) ?></div>
                                    <div class="small text-secondary"><i class="fa-regular fa-clock me-1"></i><?= date('h:i:s A', strtotime($l['timestamp'])) ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-slate-900">
                                        <?php if (!empty($l['staff_name'])): ?>
                                            <i class="fa-solid fa-user-check text-primary me-1"></i><?= htmlspecialchars($l['staff_name']) ?>
                                        <?php else: ?>
                                            <span class="text-secondary"><i class="fa-solid fa-robot me-1"></i>Automated / System</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-0.5">
                                        <span class="mature-badge <?= $role_badge ?>">
                                            <?= htmlspecialchars(ucfirst($l['staff_role'] ?? 'System')) ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <span class="mature-badge <?= $mod_badge ?>">
                                        <?= htmlspecialchars($l['module'] ?: 'General') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-semibold text-slate-900"><?= htmlspecialchars($l['action']) ?></span>
                                </td>
                                <td style="max-width: 380px;">
                                    <div class="text-secondary small" style="line-height: 1.45;">
                                        <?= htmlspecialchars($l['details']) ?>
                                    </div>
                                </td>
                                <td class="text-end pe-4">
                                    <span class="tech-chip"><?= htmlspecialchars($l['ip_address'] ?? '127.0.0.1') ?></span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
