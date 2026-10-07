<?php
// superadmin/audit.php - Global Audit Trail & SaaS Event Log
$page_title = "Global Audit Trail & Governance Logs";
require_once 'includes/super_header.php';
require_once 'includes/super_sidebar.php';
require_once 'includes/super_topbar.php';

// Filtering parameters
$filter_module = trim($_GET['module'] ?? '');
$filter_search = trim($_GET['search'] ?? '');

$whereClauses = ["1=1"];
if (!empty($filter_module)) {
    $whereClauses[] = "a.module = '" . $conn->real_escape_string($filter_module) . "'";
}
if (!empty($filter_search)) {
    $s = $conn->real_escape_string($filter_search);
    $whereClauses[] = "(a.action LIKE '%$s%' OR a.details LIKE '%$s%' OR u.name LIKE '%$s%')";
}

$whereSql = implode(' AND ', $whereClauses);

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

$totRes = $conn->query("SELECT COUNT(*) as c FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id WHERE $whereSql");
$totalLogs = $totRes ? intval($totRes->fetch_assoc()['c'] ?? 0) : 0;
$totalPages = ceil($totalLogs / $limit);

$logsQuery = $conn->query("SELECT a.*, u.name as user_name, u.email as user_email, e.name as estate_name 
                           FROM audit_logs a 
                           LEFT JOIN users u ON a.user_id = u.id 
                           LEFT JOIN estates e ON a.estate_id = e.id 
                           WHERE $whereSql 
                           ORDER BY a.timestamp DESC 
                           LIMIT $offset, $limit");

$modulesList = [];
$mRes = $conn->query("SELECT DISTINCT module FROM audit_logs ORDER BY module ASC");
if ($mRes) {
    while($mr = $mRes->fetch_assoc()) if (!empty($mr['module'])) $modulesList[] = $mr['module'];
}
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
        <h4 class="fw-bold mb-1 text-slate-800">Global Audit Trail &amp; Activity Log</h4>
        <p class="text-secondary small mb-0">Complete historical security ledger tracking administrative operations across all tenant portals.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="index" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold">
            <i class="fa-solid fa-arrow-left me-1"></i> Dashboard
        </a>
    </div>
</div>

<!-- FILTERS CARD -->
<div class="card-custom p-3 px-4 mb-4">
    <form method="GET" class="row g-2 align-items-center">
        <div class="col-12 col-md-4">
            <div class="position-relative">
                <i class="fa-solid fa-magnifying-glass position-absolute text-secondary" style="top: 11px; left: 14px; font-size: 0.8rem;"></i>
                <input type="text" name="search" class="form-control form-control-sm rounded-pill ps-4" placeholder="Search actions, details, user..." value="<?= htmlspecialchars($filter_search) ?>">
            </div>
        </div>
        <div class="col-12 col-md-3">
            <select name="module" class="form-select form-select-sm rounded-pill">
                <option value="">All Categories / Modules</option>
                <?php foreach ($modulesList as $mod): ?>
                    <option value="<?= htmlspecialchars($mod) ?>" <?= $filter_module === $mod ? 'selected' : '' ?>><?= htmlspecialchars($mod) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold w-100">Filter</button>
            <?php if (!empty($filter_search) || !empty($filter_module)): ?>
                <a href="audit" class="btn btn-sm btn-light border rounded-pill px-2.5" title="Reset Filters"><i class="fa-solid fa-rotate-right"></i></a>
            <?php endif; ?>
        </div>
        <div class="col-12 col-md-3 text-md-end text-secondary small">
            Total recorded events: <strong><?= number_format($totalLogs) ?></strong>
        </div>
    </form>
</div>

<!-- AUDIT LOGS TABLE -->
<div class="card-custom mb-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem;">
                <tr>
                    <th class="ps-4">Timestamp</th>
                    <th>User / Operator</th>
                    <th>Action</th>
                    <th>Category</th>
                    <th>Tenant Estate</th>
                    <th>Details &amp; Payload</th>
                    <th class="text-end pe-4">IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($logsQuery && $logsQuery->num_rows > 0): ?>
                    <?php while($l = $logsQuery->fetch_assoc()): ?>
                        <tr>
                            <td class="ps-4 text-secondary font-monospace" style="font-size: 0.75rem;">
                                <?= date('M d, Y H:i:s', strtotime($l['timestamp'])) ?>
                            </td>
                            <td>
                                <div class="fw-bold text-slate-800"><?= htmlspecialchars($l['user_name'] ?? 'System') ?></div>
                                <small class="text-secondary" style="font-size: 0.7rem;"><?= htmlspecialchars($l['user_email'] ?? '') ?></small>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border fw-bold"><?= htmlspecialchars($l['action']) ?></span>
                            </td>
                            <td>
                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-2 py-0.5" style="font-size: 0.68rem;">
                                    <?= htmlspecialchars($l['module'] ?? 'Core') ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($l['estate_name'])): ?>
                                    <span class="badge bg-light text-secondary border font-monospace"><?= htmlspecialchars($l['estate_name']) ?></span>
                                <?php else: ?>
                                    <span class="text-secondary small">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="max-width: 320px;">
                                <div class="text-truncate text-slate-700" title="<?= htmlspecialchars($l['details']) ?>">
                                    <?= htmlspecialchars($l['details']) ?>
                                </div>
                            </td>
                            <td class="text-end pe-4 font-monospace text-secondary" style="font-size: 0.72rem;">
                                <?= htmlspecialchars($l['ip_address'] ?? '127.0.0.1') ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-secondary">
                            <i class="fa-solid fa-clock-rotate-left fs-3 mb-2 d-block"></i>
                            No audit log records found matching query.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="p-3 bg-light border-top d-flex justify-content-between align-items-center">
            <small class="text-secondary">Page <?= $page ?> of <?= $totalPages ?></small>
            <nav>
                <ul class="pagination pagination-sm m-0">
                    <?php if ($page > 1): ?>
                        <li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($filter_search) ?>&module=<?= urlencode($filter_module) ?>">Previous</a></li>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <li class="page-item"><a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($filter_search) ?>&module=<?= urlencode($filter_module) ?>">Next</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'includes/super_footer.php'; ?>
