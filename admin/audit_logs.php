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

<div class="page-header" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem;">
    <div>
        <h1 style="display: flex; align-items: center; gap: 10px; margin: 0; font-size: 1.6rem; color: #0f172a;">
            <i class="fa-solid fa-user-shield" style="color: #2563eb;"></i> Staff Action & Security Audit Console
        </h1>
        <p style="color: #64748b; margin: 0.35rem 0 0; font-size: 0.92rem;">
            Real-time tracking of staff actions: visitor admittance, receipt generation, payment method creation, and resident needs resolution.
        </p>
    </div>
    <div>
        <a href="audit_logs" class="btn btn-secondary" style="background: #f1f5f9; color: #475569; padding: 0.6rem 1.1rem; border-radius: 0.5rem; text-decoration: none; font-weight: 600; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 6px;">
            <i class="fa-solid fa-rotate-left"></i> Reset Filters
        </a>
    </div>
</div>

<!-- KPI Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: space-between;">
        <div>
            <div style="font-size: 0.78rem; text-transform: uppercase; font-weight: 700; color: #64748b; letter-spacing: 0.04em;">Today's Staff Actions</div>
            <div style="font-size: 1.75rem; font-weight: 800; color: #0f172a; margin-top: 0.2rem;"><?= number_format($kpi_today_actions) ?></div>
            <div style="font-size: 0.75rem; color: #10b981; font-weight: 600; margin-top: 0.2rem;"><i class="fa-solid fa-bolt"></i> Total audit events recorded</div>
        </div>
        <div style="width: 46px; height: 46px; border-radius: 50%; background: #eff6ff; display: flex; align-items: center; justify-content: center; color: #2563eb; font-size: 1.25rem;">
            <i class="fa-solid fa-list-check"></i>
        </div>
    </div>

    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: space-between;">
        <div>
            <div style="font-size: 0.78rem; text-transform: uppercase; font-weight: 700; color: #64748b; letter-spacing: 0.04em;">Gate Passes Handled</div>
            <div style="font-size: 1.75rem; font-weight: 800; color: #0284c7; margin-top: 0.2rem;"><?= number_format($kpi_visitors) ?></div>
            <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.2rem;">Admitted & checked out today</div>
        </div>
        <div style="width: 46px; height: 46px; border-radius: 50%; background: #e0f2fe; display: flex; align-items: center; justify-content: center; color: #0284c7; font-size: 1.25rem;">
            <i class="fa-solid fa-shield-halved"></i>
        </div>
    </div>

    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: space-between;">
        <div>
            <div style="font-size: 0.78rem; text-transform: uppercase; font-weight: 700; color: #64748b; letter-spacing: 0.04em;">Receipts & Collections</div>
            <div style="font-size: 1.75rem; font-weight: 800; color: #16a34a; margin-top: 0.2rem;"><?= number_format($kpi_receipts) ?></div>
            <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.2rem;">Receipts generated today</div>
        </div>
        <div style="width: 46px; height: 46px; border-radius: 50%; background: #dcfce7; display: flex; align-items: center; justify-content: center; color: #16a34a; font-size: 1.25rem;">
            <i class="fa-solid fa-receipt"></i>
        </div>
    </div>

    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: space-between;">
        <div>
            <div style="font-size: 0.78rem; text-transform: uppercase; font-weight: 700; color: #64748b; letter-spacing: 0.04em;">Needs & Issues Attended</div>
            <div style="font-size: 1.75rem; font-weight: 800; color: #d97706; margin-top: 0.2rem;"><?= number_format($kpi_maintenance) ?></div>
            <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.2rem;">Maintenance actions today</div>
        </div>
        <div style="width: 46px; height: 46px; border-radius: 50%; background: #fef3c7; display: flex; align-items: center; justify-content: center; color: #d97706; font-size: 1.25rem;">
            <i class="fa-solid fa-wrench"></i>
        </div>
    </div>
</div>

<!-- Quick Module Tabs -->
<div style="display: flex; gap: 0.5rem; margin-bottom: 1.25rem; overflow-x: auto; padding-bottom: 4px;">
    <?php
    $nav_tabs = [
        'all' => ['label' => 'All Staff Operations', 'icon' => 'fa-border-all'],
        'security' => ['label' => 'Gate & Visitors In/Out', 'icon' => 'fa-door-open'],
        'finance' => ['label' => 'Receipts & Payments', 'icon' => 'fa-receipt'],
        'payment_methods' => ['label' => 'Payment Modes Created', 'icon' => 'fa-credit-card'],
        'maintenance' => ['label' => 'Resident Needs Attended', 'icon' => 'fa-wrench'],
    ];
    foreach ($nav_tabs as $tab_key => $tab_val):
        $is_curr = ($filter_module === $tab_key);
        $tab_url = "audit_logs?module_type=" . urlencode($tab_key) . ($filter_staff > 0 ? "&staff_id=$filter_staff" : "");
    ?>
        <a href="<?= $tab_url ?>" style="text-decoration: none; padding: 0.55rem 1rem; border-radius: 9999px; font-size: 0.85rem; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; <?= $is_curr ? 'background: #2563eb; color: #ffffff;' : 'background: #ffffff; color: #64748b; border: 1px solid #e2e8f0;' ?>">
            <i class="fa-solid <?= $tab_val['icon'] ?>"></i> <?= $tab_val['label'] ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Filter Bar -->
<div style="background: white; border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 1.25rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
    <form method="GET" action="audit_logs" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; align-items: flex-end;">
        <input type="hidden" name="module_type" value="<?= htmlspecialchars($filter_module) ?>">

        <div>
            <label style="display: block; font-size: 0.8rem; font-weight: 700; color: #475569; margin-bottom: 0.35rem;">Staff Member</label>
            <select name="staff_id" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; font-size: 0.88rem; outline: none;">
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

        <div>
            <label style="display: block; font-size: 0.8rem; font-weight: 700; color: #475569; margin-bottom: 0.35rem;">Search Details / Code</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search_query) ?>" placeholder="Receipt #, Visitor Code, keyword..." style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; font-size: 0.88rem; outline: none;">
        </div>

        <div>
            <label style="display: block; font-size: 0.8rem; font-weight: 700; color: #475569; margin-bottom: 0.35rem;">Date From</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($filter_date_from) ?>" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; font-size: 0.88rem; outline: none;">
        </div>

        <div>
            <label style="display: block; font-size: 0.8rem; font-weight: 700; color: #475569; margin-bottom: 0.35rem;">Date To</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($filter_date_to) ?>" style="width: 100%; padding: 0.65rem 0.85rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; font-size: 0.88rem; outline: none;">
        </div>

        <div style="display: flex; gap: 0.5rem;">
            <button type="submit" style="flex: 1; padding: 0.65rem 1rem; background: #2563eb; color: white; border: none; border-radius: 0.5rem; font-weight: 700; font-size: 0.88rem; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 5px;">
                <i class="fa-solid fa-filter"></i> Apply Filter
            </button>
            <a href="audit_logs" style="padding: 0.65rem 0.9rem; background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; border-radius: 0.5rem; text-decoration: none; display: inline-flex; align-items: center; justify-content: center;">
                <i class="fa-solid fa-xmark"></i>
            </a>
        </div>
    </form>
</div>

<!-- Logs Table Container -->
<div style="background: white; border: 1px solid #e2e8f0; border-radius: 0.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden;">
    <div style="padding: 1rem 1.5rem; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
        <div style="font-weight: 700; color: #1e293b; font-size: 0.95rem;">
            <i class="fa-solid fa-list-ul text-primary me-1"></i> Audit Activity Records 
            <span style="font-weight: 500; font-size: 0.82rem; color: #64748b; margin-left: 6px;">(Showing up to <?= $logs ? $logs->num_rows : 0 ?> results)</span>
        </div>
    </div>

    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; background: #f8fafc; color: #64748b; border-bottom: 1px solid #e2e8f0; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em;">
                    <th style="padding: 0.9rem 1.25rem;">Timestamp</th>
                    <th style="padding: 0.9rem 1.25rem;">Attending Staff</th>
                    <th style="padding: 0.9rem 1.25rem;">Module / Area</th>
                    <th style="padding: 0.9rem 1.25rem;">Action Executed</th>
                    <th style="padding: 0.9rem 1.25rem;">Activity Details & Context</th>
                    <th style="padding: 0.9rem 1.25rem; text-align: right;">Terminal IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$logs || $logs->num_rows == 0): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 4rem 1rem; color: #94a3b8;">
                            <i class="fa-solid fa-shield-halved" style="font-size: 2.5rem; margin-bottom: 0.75rem; opacity: 0.5; display: block;"></i>
                            <div style="font-weight: 600; font-size: 1rem; color: #475569;">No staff activity records found</div>
                            <div style="font-size: 0.85rem; margin-top: 0.25rem;">Try adjusting the staff member filter, module tab, or date range.</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php while ($l = $logs->fetch_assoc()): ?>
                        <?php
                        $role = strtolower($l['staff_role'] ?? 'system');
                        $role_bg = '#f1f5f9';
                        $role_color = '#475569';
                        if ($role === 'admin') { $role_bg = '#ede9fe'; $role_color = '#6d28d9'; }
                        elseif ($role === 'manager') { $role_bg = '#e0e7ff'; $role_color = '#4338ca'; }
                        elseif ($role === 'security') { $role_bg = '#e0f2fe'; $role_color = '#0369a1'; }
                        elseif ($role === 'staff') { $role_bg = '#ccfbf1'; $role_color = '#0f766e'; }

                        $mod = strtolower($l['module'] ?? '');
                        $mod_badge_bg = '#f1f5f9';
                        $mod_badge_color = '#475569';
                        if (strpos($mod, 'security') !== false) { $mod_badge_bg = '#e0f2fe'; $mod_badge_color = '#0284c7'; }
                        elseif (strpos($mod, 'finance') !== false) { $mod_badge_bg = '#dcfce7'; $mod_badge_color = '#15803d'; }
                        elseif (strpos($mod, 'maintenance') !== false) { $mod_badge_bg = '#fef3c7'; $mod_badge_color = '#b45309'; }
                        ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 1rem 1.25rem; font-size: 0.85rem; white-space: nowrap;">
                                <div style="font-weight: 600; color: #1e293b;"><?= date('M d, Y', strtotime($l['timestamp'])) ?></div>
                                <div style="color: #64748b; font-size: 0.78rem;"><?= date('h:i:s A', strtotime($l['timestamp'])) ?></div>
                            </td>
                            <td style="padding: 1rem 1.25rem;">
                                <div style="font-weight: 600; color: #0f172a; font-size: 0.92rem;">
                                    <?php if (!empty($l['staff_name'])): ?>
                                        <i class="fa-solid fa-user-check text-primary me-1"></i><?= htmlspecialchars($l['staff_name']) ?>
                                    <?php else: ?>
                                        <span style="color: #64748b;"><i class="fa-solid fa-robot me-1"></i>Automated / System</span>
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top: 3px;">
                                    <span style="display: inline-block; padding: 2px 7px; border-radius: 4px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; background: <?= $role_bg ?>; color: <?= $role_color ?>;">
                                        <?= htmlspecialchars(ucfirst($l['staff_role'] ?? 'System')) ?>
                                    </span>
                                </div>
                            </td>
                            <td style="padding: 1rem 1.25rem;">
                                <span style="display: inline-block; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; background: <?= $mod_badge_bg ?>; color: <?= $mod_badge_color ?>;">
                                    <?= htmlspecialchars($l['module'] ?: 'General') ?>
                                </span>
                            </td>
                            <td style="padding: 1rem 1.25rem; font-weight: 600; color: #1e293b; font-size: 0.88rem;">
                                <?= htmlspecialchars($l['action']) ?>
                            </td>
                            <td style="padding: 1rem 1.25rem; font-size: 0.85rem; color: #334155; line-height: 1.45; max-width: 380px;">
                                <?= htmlspecialchars($l['details']) ?>
                            </td>
                            <td style="padding: 1rem 1.25rem; text-align: right; font-family: monospace; font-size: 0.78rem; color: #64748b;">
                                <?= htmlspecialchars($l['ip_address'] ?? '127.0.0.1') ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
