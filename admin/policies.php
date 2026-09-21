<?php
// admin/policies.php - Central Administration Policy, Rules & Regulations Hub
require_once '../config.php';
require_once '../includes/auth_guard.php';

requireLogin();
if (!isAdminRole()) {
    header("Location: ../index");
    exit;
}

$estate_id = get_estate_id();
$user_id = $_SESSION['user_id'] ?? 1;
$message = '';
$error = '';

// -------------------------------------------------------------
// POST HANDLERS: CREATE / EDIT / DELETE POLICIES
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_policy') {
        $policy_id = intval($_POST['policy_id'] ?? 0);
        $scope = ($_POST['scope'] === 'zonal') ? 'zonal' : 'central';
        $zone_id = ($scope === 'zonal' && !empty($_POST['zone_id'])) ? intval($_POST['zone_id']) : null;
        $category_slug = trim($_POST['category_slug'] ?? 'security');
        $code = trim($_POST['code'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $offence_definition = trim($_POST['offence_definition'] ?? '');
        $punishment_type = trim($_POST['punishment_type'] ?? 'warning');
        $punishment_details = trim($_POST['punishment_details'] ?? '');
        $fine_amount = floatval($_POST['fine_amount'] ?? 0);
        $repeat_offence_penalty = trim($_POST['repeat_offence_penalty'] ?? '');
        $severity = in_array($_POST['severity'] ?? '', ['low', 'medium', 'high', 'critical']) ? $_POST['severity'] : 'medium';
        $enforcement_entity = trim($_POST['enforcement_entity'] ?? 'Estate Security & Management');
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive', 'under_review']) ? $_POST['status'] : 'active';
        $display_order = intval($_POST['display_order'] ?? 0);

        if (empty($title) || empty($description)) {
            $error = "Policy Title and Rule Description are mandatory fields.";
        } elseif ($scope === 'zonal' && empty($zone_id)) {
            $error = "Please specify the target Zone for a zonal bylaw.";
        } else {
            if ($policy_id > 0) {
                // Update
                $stmt = $conn->prepare("UPDATE estate_policies SET 
                    scope = ?, zone_id = ?, category_slug = ?, code = ?, title = ?, 
                    description = ?, offence_definition = ?, punishment_type = ?, punishment_details = ?, 
                    fine_amount = ?, repeat_offence_penalty = ?, severity = ?, enforcement_entity = ?, 
                    status = ?, display_order = ?
                    WHERE id = ? AND estate_id = ?");
                if ($stmt) {
                    $stmt->bind_param(
                        "sisssssssdsssiiii",
                        $scope, $zone_id, $category_slug, $code, $title,
                        $description, $offence_definition, $punishment_type, $punishment_details,
                        $fine_amount, $repeat_offence_penalty, $severity, $enforcement_entity,
                        $status, $display_order, $policy_id, $estate_id
                    );
                    if ($stmt->execute()) {
                        $message = "Policy & regulation updated successfully.";
                    } else {
                        $error = "Database error updating policy: " . $conn->error;
                    }
                    $stmt->close();
                }
            } else {
                // Insert
                $stmt = $conn->prepare("INSERT INTO estate_policies (
                    estate_id, zone_id, scope, category_slug, code, title, 
                    description, offence_definition, punishment_type, punishment_details, 
                    fine_amount, repeat_offence_penalty, severity, enforcement_entity, 
                    status, display_order, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param(
                        "iissssssssdsssiii",
                        $estate_id, $zone_id, $scope, $category_slug, $code, $title,
                        $description, $offence_definition, $punishment_type, $punishment_details,
                        $fine_amount, $repeat_offence_penalty, $severity, $enforcement_entity,
                        $status, $display_order, $user_id
                    );
                    if ($stmt->execute()) {
                        $message = "New policy clause enacted successfully.";
                    } else {
                        $error = "Database error enacting policy: " . $conn->error;
                    }
                    $stmt->close();
                }
            }
        }
    }

    if ($action === 'delete_policy') {
        $policy_id = intval($_POST['policy_id'] ?? 0);
        if ($policy_id > 0) {
            $del = $conn->query("DELETE FROM estate_policies WHERE id = $policy_id AND estate_id = $estate_id");
            if ($del) {
                $message = "Policy clause deleted successfully.";
            } else {
                $error = "Failed to delete policy clause.";
            }
        }
    }

    if ($action === 'toggle_status') {
        $policy_id = intval($_POST['policy_id'] ?? 0);
        $curr = $conn->query("SELECT status FROM estate_policies WHERE id = $policy_id AND estate_id = $estate_id LIMIT 1")->fetch_assoc();
        if ($curr) {
            $new_st = ($curr['status'] === 'active') ? 'inactive' : 'active';
            $conn->query("UPDATE estate_policies SET status = '$new_st' WHERE id = $policy_id AND estate_id = $estate_id");
            $message = "Policy status updated to " . strtoupper($new_st) . ".";
        }
    }
}

// -------------------------------------------------------------
// DATA FETCHING & FILTERING
// -------------------------------------------------------------
$categories = getPolicyCategories($conn, $estate_id);

// Fetch all zones for dropdowns and filters
$zones_res = $conn->query("SELECT id, name, code FROM zones WHERE estate_id = $estate_id ORDER BY name ASC");
$zones_list = [];
if ($zones_res) {
    while ($z = $zones_res->fetch_assoc()) {
        $zones_list[] = $z;
    }
}

// Active Tab & Filters
$active_tab = $_GET['tab'] ?? 'central'; // 'central', 'zonal', 'all', 'matrix'
$filter_category = $_GET['category'] ?? '';
$filter_zone = isset($_GET['zone_id']) ? intval($_GET['zone_id']) : 0;
$search = trim($_GET['search'] ?? '');

// Metrics
$total_policies = $conn->query("SELECT COUNT(id) as c FROM estate_policies WHERE estate_id = $estate_id")->fetch_assoc()['c'] ?? 0;
$central_policies_cnt = $conn->query("SELECT COUNT(id) as c FROM estate_policies WHERE estate_id = $estate_id AND scope = 'central'")->fetch_assoc()['c'] ?? 0;
$zonal_policies_cnt = $conn->query("SELECT COUNT(id) as c FROM estate_policies WHERE estate_id = $estate_id AND scope = 'zonal'")->fetch_assoc()['c'] ?? 0;
$fines_cnt = $conn->query("SELECT COUNT(id) as c FROM estate_policies WHERE estate_id = $estate_id AND fine_amount > 0")->fetch_assoc()['c'] ?? 0;
$max_fine = $conn->query("SELECT MAX(fine_amount) as m FROM estate_policies WHERE estate_id = $estate_id")->fetch_assoc()['m'] ?? 0;

// Filter criteria array for helper
$filters = [
    'category_slug' => $filter_category,
    'search' => $search
];

if ($active_tab === 'central') {
    $filters['scope'] = 'central';
} elseif ($active_tab === 'zonal') {
    $filters['scope'] = 'zonal';
    if ($filter_zone > 0) {
        $filters['zone_id'] = $filter_zone;
    }
}

$policies_list = getEstatePoliciesList($conn, $estate_id, $filters);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Header Section -->
<div class="page-header-futuristic mb-4">
    <div>
        <div class="header-breadcrumbs">
            <a href="index">Dashboard</a>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span>Estate Governance</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span class="active">Policies &amp; Bylaws Hub</span>
        </div>
        <h1 class="page-title"><i class="fa-solid fa-scale-balanced text-primary me-2"></i>Policies, Offences &amp; Penalties Hub</h1>
        <p class="text-secondary small mb-0">Configure estate-wide governing regulations, offences, fines, and sector-specific zonal bylaws across all portals.</p>
    </div>
    <div class="header-actions d-flex align-items-center gap-2">
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
            <i class="fa-solid fa-print me-1.5"></i> Print Rulebook
        </button>
        <button type="button" class="btn btn-primary shadow-sm" onclick="openPolicyModal()">
            <i class="fa-solid fa-plus me-1.5"></i> Enact New Policy / Rule
        </button>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
        <i class="fa-solid fa-circle-check fs-5"></i>
        <div><?php echo htmlspecialchars($message); ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
        <i class="fa-solid fa-triangle-exclamation fs-5"></i>
        <div><?php echo htmlspecialchars($error); ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Metric Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 p-3 d-flex flex-row align-items-center justify-content-between h-100" style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);">
            <div>
                <span class="text-secondary small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">Total Regulations</span>
                <h3 class="fw-bold mb-0 text-slate-800 mt-1"><?php echo number_format($total_policies); ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">Across <?php echo count($categories); ?> Categories</small>
            </div>
            <div style="width: 48px; height: 48px; border-radius: 14px; background: rgba(59, 130, 246, 0.12); color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                <i class="fa-solid fa-gavel"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 p-3 d-flex flex-row align-items-center justify-content-between h-100" style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);">
            <div>
                <span class="text-secondary small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">Central Policies</span>
                <h3 class="fw-bold mb-0 text-primary mt-1"><?php echo number_format($central_policies_cnt); ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">Mandatory Estate-Wide</small>
            </div>
            <div style="width: 48px; height: 48px; border-radius: 14px; background: rgba(99, 102, 241, 0.12); color: #4f46e5; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                <i class="fa-solid fa-globe"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 p-3 d-flex flex-row align-items-center justify-content-between h-100" style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);">
            <div>
                <span class="text-secondary small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">Zonal Bylaws</span>
                <h3 class="fw-bold mb-0 text-purple mt-1" style="color: #9333ea;"><?php echo number_format($zonal_policies_cnt); ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">Zone Specific Sector Rules</small>
            </div>
            <div style="width: 48px; height: 48px; border-radius: 14px; background: rgba(168, 85, 247, 0.12); color: #9333ea; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                <i class="fa-solid fa-layer-group"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 p-3 d-flex flex-row align-items-center justify-content-between h-100" style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);">
            <div>
                <span class="text-secondary small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">Fines &amp; Penalties</span>
                <h3 class="fw-bold mb-0 text-danger mt-1"><?php echo number_format($fines_cnt); ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">Max Fine: ₦<?php echo number_format($max_fine, 2); ?></small>
            </div>
            <div style="width: 48px; height: 48px; border-radius: 14px; background: rgba(239, 68, 68, 0.12); color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                <i class="fa-solid fa-money-bill-transfer"></i>
            </div>
        </div>
    </div>
</div>

<!-- Navigation Tabs: Strict Split between Central and Zonal Jurisdiction -->
<div class="d-flex flex-wrap align-items-center justify-content-between border-bottom pb-2 mb-3 gap-2">
    <ul class="nav nav-pills gap-2" id="policyTabs">
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'central') ? 'active' : ''; ?> fw-semibold px-3 py-2 rounded-3" href="?tab=central">
                <i class="fa-solid fa-globe me-1.5"></i> Central Estate Policies (Estate-Wide)
                <span class="badge ms-1.5 <?php echo ($active_tab === 'central') ? 'bg-white text-primary' : 'bg-light text-dark'; ?>"><?php echo $central_policies_cnt; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'zonal') ? 'active' : ''; ?> fw-semibold px-3 py-2 rounded-3" href="?tab=zonal">
                <i class="fa-solid fa-layer-group me-1.5"></i> Zonal Bylaws &amp; Sector Rules
                <span class="badge ms-1.5 <?php echo ($active_tab === 'zonal') ? 'bg-white text-primary' : 'bg-light text-dark'; ?>"><?php echo $zonal_policies_cnt; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'all') ? 'active' : ''; ?> fw-semibold px-3 py-2 rounded-3" href="?tab=all">
                <i class="fa-solid fa-list me-1.5"></i> All Combined
                <span class="badge ms-1.5 <?php echo ($active_tab === 'all') ? 'bg-white text-primary' : 'bg-light text-dark'; ?>"><?php echo $total_policies; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'matrix') ? 'active' : ''; ?> fw-semibold px-3 py-2 rounded-3" href="?tab=matrix">
                <i class="fa-solid fa-table-cells me-1.5"></i> Offences &amp; Fines Matrix
            </a>
        </li>
    </ul>

    <!-- Quick Search & Category Filter Form -->
    <form method="GET" class="d-flex align-items-center gap-2 ms-auto">
        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
        
        <?php if ($active_tab === 'zonal'): ?>
            <select name="zone_id" class="form-select form-select-sm" onchange="this.form.submit()" style="width: 170px;">
                <option value="0">All Zones / Sectors</option>
                <?php foreach ($zones_list as $z): ?>
                    <option value="<?php echo $z['id']; ?>" <?php echo ($filter_zone == $z['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($z['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <select name="category" class="form-select form-select-sm" onchange="this.form.submit()" style="width: 190px;">
            <option value="">All Categories (<?php echo count($categories); ?>)</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?php echo $cat['slug']; ?>" <?php echo ($filter_category === $cat['slug']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cat['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div class="input-group input-group-sm" style="width: 220px;">
            <input type="text" name="search" class="form-control" placeholder="Search rules, fines..." value="<?php echo htmlspecialchars($search); ?>">
            <button class="btn btn-outline-secondary" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
        </div>

        <?php if (!empty($search) || !empty($filter_category) || $filter_zone > 0): ?>
            <a href="?tab=<?php echo htmlspecialchars($active_tab); ?>" class="btn btn-sm btn-light border text-danger" title="Clear Filters">
                <i class="fa-solid fa-xmark"></i>
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Scope Description Banner -->
<?php if ($active_tab === 'central'): ?>
    <div class="alert alert-primary bg-primary bg-opacity-10 border-primary border-opacity-25 rounded-4 p-3 mb-4 d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
            <div class="bg-primary text-white rounded-3 p-2 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                <i class="fa-solid fa-landmark fs-5"></i>
            </div>
            <div>
                <strong class="d-block text-primary">Central Estate Jurisdiction (Estate-Wide Applicability)</strong>
                <span class="text-secondary small">These policies are enacted by Central Management and apply universally to all residents, visitors, staff, and contractors across all zones. Zone bylaws cannot override these rules.</span>
            </div>
        </div>
        <span class="badge bg-primary px-3 py-2 rounded-pill font-monospace" style="letter-spacing: 0.05em;">ESTATE-WIDE JURISDICTION</span>
    </div>
<?php elseif ($active_tab === 'zonal'): ?>
    <div class="alert alert-purple bg-purple bg-opacity-10 border border-purple border-opacity-25 rounded-4 p-3 mb-4 d-flex align-items-center justify-content-between" style="background: rgba(168, 85, 247, 0.08); border-color: rgba(168, 85, 247, 0.25);">
        <div class="d-flex align-items-center gap-3">
            <div class="text-white rounded-3 p-2 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; background: #9333ea;">
                <i class="fa-solid fa-layer-group fs-5"></i>
            </div>
            <div>
                <strong class="d-block" style="color: #7e22ce;">Zonal Bylaws &amp; Local Sector Regulations</strong>
                <span class="text-secondary small">Sector-specific regulations tailored to individual zones (e.g. inner crescent parking, localized waste collection hours, green areas). Only residents and units residing in the corresponding zone are subject to these bylaws.</span>
            </div>
        </div>
        <span class="badge px-3 py-2 rounded-pill font-monospace" style="background: #9333ea; color: white; letter-spacing: 0.05em;">LOCAL SECTOR JURISDICTION</span>
    </div>
<?php endif; ?>

<!-- TAB CONTENT: MATRIX VIEW -->
<?php if ($active_tab === 'matrix'): ?>
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
        <div class="card-header bg-white py-3 px-4 border-bottom d-flex align-items-center justify-content-between">
            <div>
                <h5 class="fw-bold mb-0 text-slate-800"><i class="fa-solid fa-table-cells text-primary me-2"></i>Offences, Fines &amp; Sanctions Schedule</h5>
                <small class="text-muted">Master breakdown of violations, monetary penalties, and enforcement agencies</small>
            </div>
            <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-download me-1"></i> Export Schedule</button>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-uppercase text-secondary" style="font-size: 0.75rem; letter-spacing: 0.05em;">
                    <tr>
                        <th class="ps-4">Rule Code</th>
                        <th>Scope &amp; Sector</th>
                        <th>Category</th>
                        <th>Offence / Violation</th>
                        <th>Sanction Type</th>
                        <th>Fine Amount (₦)</th>
                        <th>Repeat Violation Penalty</th>
                        <th>Enforcement Agency</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $matrix_list = getEstatePoliciesList($conn, $estate_id, ['category_slug' => $filter_category, 'search' => $search]);
                    if (empty($matrix_list)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-file-circle-question fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                No offence records found matching the filter criteria.
                            </td>
                        </tr>
                    <?php else: 
                        foreach ($matrix_list as $row): ?>
                        <tr>
                            <td class="ps-4">
                                <span class="badge font-monospace bg-light text-dark border px-2 py-1"><?php echo htmlspecialchars($row['code'] ?: 'POL-' . $row['id']); ?></span>
                            </td>
                            <td>
                                <?php if ($row['scope'] === 'central'): ?>
                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1 rounded-pill">
                                        <i class="fa-solid fa-globe me-1"></i> Central (Estate-Wide)
                                    </span>
                                <?php else: ?>
                                    <span class="badge px-2 py-1 rounded-pill" style="background: rgba(168, 85, 247, 0.12); color: #7e22ce; border: 1px solid rgba(168, 85, 247, 0.25);">
                                        <i class="fa-solid fa-layer-group me-1"></i> <?php echo htmlspecialchars($row['zone_name'] ?: 'Zone ' . $row['zone_id']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="d-inline-flex align-items-center gap-1.5 small fw-semibold" style="color: <?php echo htmlspecialchars($row['category_color'] ?: '#475569'); ?>;">
                                    <i class="fa-solid <?php echo htmlspecialchars($row['category_icon'] ?: 'fa-gavel'); ?>"></i>
                                    <?php echo htmlspecialchars($row['category_name'] ?: ucfirst($row['category_slug'])); ?>
                                </span>
                            </td>
                            <td>
                                <div class="fw-bold text-slate-800"><?php echo htmlspecialchars($row['title']); ?></div>
                                <div class="text-secondary small" style="max-width: 320px;"><?php echo htmlspecialchars($row['offence_definition'] ?: $row['description']); ?></div>
                            </td>
                            <td>
                                <?php echo formatPunishmentTypeBadge($row['punishment_type']); ?>
                                <?php if (!empty($row['punishment_details'])): ?>
                                    <div class="text-muted small mt-1" style="font-size: 0.72rem;"><?php echo htmlspecialchars($row['punishment_details']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['fine_amount'] > 0): ?>
                                    <span class="fw-bold text-danger font-monospace" style="font-size: 0.95rem;">₦<?php echo number_format($row['fine_amount'], 2); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border">No Fine / Warning</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-secondary" style="max-width: 200px;">
                                <?php echo htmlspecialchars($row['repeat_offence_penalty'] ?: 'Standard disciplinary escalation'); ?>
                            </td>
                            <td class="small text-muted">
                                <i class="fa-solid fa-user-shield me-1"></i> <?php echo htmlspecialchars($row['enforcement_entity'] ?: 'Estate Security'); ?>
                            </td>
                        </tr>
                    <?php endforeach; 
                    endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<!-- TAB CONTENT: POLICY CARDS VIEW (CENTRAL, ZONAL, OR ALL) -->
<?php else: ?>
    <?php if (empty($policies_list)): ?>
        <div class="card border-0 shadow-sm rounded-4 p-5 text-center my-4">
            <div style="width: 72px; height: 72px; border-radius: 50%; background: #f1f5f9; color: #94a3b8; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 1rem auto;">
                <i class="fa-solid fa-gavel"></i>
            </div>
            <h5 class="fw-bold text-slate-800">No Policies Found</h5>
            <p class="text-secondary small mb-3">No policy clauses or bylaws match your active tab and filter criteria.</p>
            <div>
                <button type="button" class="btn btn-primary rounded-3" onclick="openPolicyModal()">
                    <i class="fa-solid fa-plus me-1.5"></i> Enact First Regulation
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($policies_list as $pol): ?>
                <div class="col-12 col-lg-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden d-flex flex-column" style="border: 1px solid rgba(226, 232, 240, 0.8) !important; transition: transform 0.2s ease, box-shadow 0.2s ease;">
                        <!-- Card Header -->
                        <div class="card-header bg-white p-3.5 border-bottom d-flex align-items-start justify-content-between gap-2">
                            <div>
                                <div class="d-flex align-items-center gap-2 flex-wrap mb-1.5">
                                    <!-- Scope Badge -->
                                    <?php if ($pol['scope'] === 'central'): ?>
                                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-0.5 rounded-pill font-monospace" style="font-size: 0.72rem;">
                                            <i class="fa-solid fa-globe me-1"></i> Central Estate
                                        </span>
                                    <?php else: ?>
                                        <span class="badge px-2 py-0.5 rounded-pill font-monospace" style="background: rgba(168, 85, 247, 0.12); color: #7e22ce; border: 1px solid rgba(168, 85, 247, 0.25); font-size: 0.72rem;">
                                            <i class="fa-solid fa-layer-group me-1"></i> <?php echo htmlspecialchars($pol['zone_name'] ?: 'Zone ' . $pol['zone_id']); ?>
                                        </span>
                                    <?php endif; ?>

                                    <!-- Category Badge -->
                                    <span class="badge px-2 py-0.5 rounded-pill" style="background: <?php echo htmlspecialchars($pol['category_color'] ?: '#3b82f6'); ?>18; color: <?php echo htmlspecialchars($pol['category_color'] ?: '#3b82f6'); ?>; border: 1px solid <?php echo htmlspecialchars($pol['category_color'] ?: '#3b82f6'); ?>33; font-size: 0.72rem;">
                                        <i class="fa-solid <?php echo htmlspecialchars($pol['category_icon'] ?: 'fa-gavel'); ?> me-1"></i>
                                        <?php echo htmlspecialchars($pol['category_name'] ?: ucfirst($pol['category_slug'])); ?>
                                    </span>

                                    <!-- Severity Badge -->
                                    <?php echo formatSeverityBadge($pol['severity']); ?>

                                    <?php if ($pol['status'] !== 'active'): ?>
                                        <span class="badge bg-secondary text-white px-2 py-0.5 rounded-pill" style="font-size: 0.7rem;"><?php echo strtoupper($pol['status']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <h5 class="fw-bold text-slate-800 mb-0 d-flex align-items-center gap-2">
                                    <span class="text-secondary small font-monospace"><?php echo htmlspecialchars($pol['code'] ?: 'POL-' . $pol['id']); ?>:</span>
                                    <?php echo htmlspecialchars($pol['title']); ?>
                                </h5>
                            </div>
                            
                            <!-- Action Dropdown -->
                            <div class="dropdown">
                                <button class="btn btn-sm btn-light border-0 rounded-circle" type="button" data-bs-toggle="dropdown" style="width: 32px; height: 32px;">
                                    <i class="fa-solid fa-ellipsis-vertical text-secondary"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3">
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-2" href="javascript:void(0)" onclick='editPolicy(<?php echo json_encode($pol, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                            <i class="fa-solid fa-pen-to-square text-primary"></i> Edit Regulation
                                        </a>
                                    </li>
                                    <li>
                                        <form method="POST" onsubmit="return confirm('Toggle status for this regulation?');">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="policy_id" value="<?php echo $pol['id']; ?>">
                                            <button type="submit" class="dropdown-item d-flex align-items-center gap-2">
                                                <i class="fa-solid fa-power-off <?php echo ($pol['status'] === 'active') ? 'text-warning' : 'text-success'; ?>"></i>
                                                <?php echo ($pol['status'] === 'active') ? 'Deactivate Policy' : 'Activate Policy'; ?>
                                            </button>
                                        </form>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this policy clause permanently?');">
                                            <input type="hidden" name="action" value="delete_policy">
                                            <input type="hidden" name="policy_id" value="<?php echo $pol['id']; ?>">
                                            <button type="submit" class="dropdown-item d-flex align-items-center gap-2 text-danger">
                                                <i class="fa-solid fa-trash-can"></i> Delete Clause
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- Card Body -->
                        <div class="card-body p-3.5 flex-grow-1">
                            <!-- Policy Text -->
                            <div class="mb-3">
                                <div class="text-secondary small fw-bold text-uppercase mb-1" style="font-size: 0.72rem; letter-spacing: 0.04em;">Policy Description &amp; Rule:</div>
                                <p class="text-slate-700 small mb-0" style="line-height: 1.55;"><?php echo nl2br(htmlspecialchars($pol['description'])); ?></p>
                            </div>

                            <!-- Offence Definition Box -->
                            <div class="p-2.5 rounded-3 mb-3" style="background: #fff1f2; border-left: 3px solid #f43f5e;">
                                <div class="d-flex align-items-center gap-1.5 text-danger fw-bold small mb-1" style="font-size: 0.75rem;">
                                    <i class="fa-solid fa-circle-exclamation"></i> Breach / Offence Definition:
                                </div>
                                <div class="text-slate-700 small" style="font-size: 0.8rem; line-height: 1.45;">
                                    <?php echo nl2br(htmlspecialchars($pol['offence_definition'] ?: 'Any breach of the above stated guidelines.')); ?>
                                </div>
                            </div>

                            <!-- Punishment & Penalty Details -->
                            <div class="p-2.5 rounded-3 mb-2" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                                <div class="d-flex align-items-center justify-content-between mb-1.5 flex-wrap gap-1">
                                    <div class="d-flex align-items-center gap-1.5">
                                        <span class="text-secondary small fw-bold" style="font-size: 0.75rem;">Sanction:</span>
                                        <?php echo formatPunishmentTypeBadge($pol['punishment_type']); ?>
                                    </div>
                                    <?php if ($pol['fine_amount'] > 0): ?>
                                        <div class="text-end">
                                            <span class="text-secondary small" style="font-size: 0.72rem;">Fine Amount:</span>
                                            <span class="fw-bold text-danger font-monospace fs-6 ms-1">₦<?php echo number_format($pol['fine_amount'], 2); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($pol['punishment_details'])): ?>
                                    <div class="text-secondary small" style="font-size: 0.78rem;">
                                        <strong>Details:</strong> <?php echo htmlspecialchars($pol['punishment_details']); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($pol['repeat_offence_penalty'])): ?>
                                    <div class="text-danger small mt-1" style="font-size: 0.75rem;">
                                        <i class="fa-solid fa-arrow-trend-up me-1"></i> <strong>Repeat Offence:</strong> <?php echo htmlspecialchars($pol['repeat_offence_penalty']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Card Footer -->
                        <div class="card-footer bg-light bg-opacity-50 p-2.5 px-3.5 border-top d-flex align-items-center justify-content-between text-secondary small" style="font-size: 0.72rem;">
                            <div>
                                <i class="fa-solid fa-shield me-1 text-primary"></i>
                                Enforced by: <strong><?php echo htmlspecialchars($pol['enforcement_entity'] ?: 'Estate Security'); ?></strong>
                            </div>
                            <div class="text-muted">
                                Ref: #<?php echo $pol['id']; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- ------------------------------------------------------------- -->
<!-- MODAL: ENACT / EDIT POLICY & BYLAW                           -->
<!-- ------------------------------------------------------------- -->
<div class="modal fade" id="policyModal" tabindex="-1" aria-labelledby="policyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <form method="POST">
                <input type="hidden" name="action" value="save_policy">
                <input type="hidden" name="policy_id" id="modal_policy_id" value="0">

                <div class="modal-header bg-slate-900 text-white py-3 px-4" style="background: #0f172a;">
                    <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2" id="policyModalLabel">
                        <i class="fa-solid fa-scale-balanced text-primary"></i>
                        <span>Enact Policy / Rule Clause</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">
                    <!-- Scope Selection: Central vs Zonal -->
                    <div class="p-3 rounded-3 mb-3" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                        <label class="form-label fw-bold text-slate-800 small mb-2">
                            <i class="fa-solid fa-sitemap text-primary me-1"></i> Policy Jurisdiction Scope <span class="text-danger">*</span>
                        </label>
                        <div class="row g-2">
                            <div class="col-sm-6">
                                <div class="form-check p-2.5 rounded-3 border bg-white h-100">
                                    <input class="form-check-input ms-1 me-2" type="radio" name="scope" id="scope_central" value="central" checked onchange="toggleScopeInputs()">
                                    <label class="form-check-label fw-bold text-slate-800 small" for="scope_central">
                                        <i class="fa-solid fa-globe text-primary me-1"></i> Central Estate Policy
                                        <div class="text-secondary fw-normal" style="font-size: 0.75rem;">Applies estate-wide across all zones, residents, and visitors.</div>
                                    </label>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="form-check p-2.5 rounded-3 border bg-white h-100">
                                    <input class="form-check-input ms-1 me-2" type="radio" name="scope" id="scope_zonal" value="zonal" onchange="toggleScopeInputs()">
                                    <label class="form-check-label fw-bold text-slate-800 small" for="scope_zonal">
                                        <i class="fa-solid fa-layer-group text-purple me-1" style="color: #9333ea;"></i> Zonal Local Bylaw
                                        <div class="text-secondary fw-normal" style="font-size: 0.75rem;">Applies only to a designated sector/zone.</div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Target Zone Dropdown (Conditional) -->
                        <div id="target_zone_wrapper" class="mt-3" style="display: none;">
                            <label class="form-label fw-semibold text-slate-700 small">Target Sector / Zone <span class="text-danger">*</span></label>
                            <select name="zone_id" id="modal_zone_id" class="form-select">
                                <option value="">-- Choose Target Zone --</option>
                                <?php foreach ($zones_list as $z): ?>
                                    <option value="<?php echo $z['id']; ?>"><?php echo htmlspecialchars($z['name']) . ' (' . htmlspecialchars($z['code']) . ')'; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Residents living outside this zone will not be held to this local bylaw.</small>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-slate-700 small">Category <span class="text-danger">*</span></label>
                            <select name="category_slug" id="modal_category_slug" class="form-select" required>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['slug']; ?>">
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-slate-700 small">Rule Code (e.g. EST-TRF-001)</label>
                            <input type="text" name="code" id="modal_code" class="form-control font-monospace" placeholder="AUTO-GEN">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-slate-700 small">Severity Level</label>
                            <select name="severity" id="modal_severity" class="form-select">
                                <option value="low">Low Severity</option>
                                <option value="medium" selected>Medium Severity</option>
                                <option value="high">High Severity</option>
                                <option value="critical">Critical Violation</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-slate-700 small">Policy Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="modal_title" class="form-control" placeholder="e.g. Quiet Hours & Anti-Noise Nuisance Rule" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-slate-700 small">Full Policy Clauses &amp; Description <span class="text-danger">*</span></label>
                        <textarea name="description" id="modal_description" class="form-control" rows="3" placeholder="Provide full context, permitted hours, operational restrictions, and regulatory parameters..." required></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-danger small">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i> Specific Breach / Offence Definition
                        </label>
                        <textarea name="offence_definition" id="modal_offence_definition" class="form-control" rows="2" placeholder="Explicitly define what actions or inactions constitute a violation of this regulation..."></textarea>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-slate-700 small">Sanction / Punishment Type</label>
                            <select name="punishment_type" id="modal_punishment_type" class="form-select">
                                <option value="warning">Official Warning</option>
                                <option value="fine">Monetary Fine</option>
                                <option value="clamping_towing">Wheel Clamping &amp; Towing</option>
                                <option value="gate_restriction">Gate Barcode / Transponder Revoked</option>
                                <option value="privilege_suspension">Club / Amenity Suspension</option>
                                <option value="community_service">Estate Community Service</option>
                                <option value="legal_eviction">Legal Notice &amp; Eviction</option>
                                <option value="other">Disciplinary Review Committee</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-slate-700 small">Administrative Fine (₦)</label>
                            <div class="input-group">
                                <span class="input-group-text">₦</span>
                                <input type="number" step="0.01" name="fine_amount" id="modal_fine_amount" class="form-control font-monospace" placeholder="0.00" value="0.00">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-slate-700 small">Sanction Enforcement Specifics</label>
                        <input type="text" name="punishment_details" id="modal_punishment_details" class="form-control" placeholder="e.g. Fine debited directly to property invoice; de-clamping fee required before release">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-danger small">Repeat Offence Escalation</label>
                        <input type="text" name="repeat_offence_penalty" id="modal_repeat_offence_penalty" class="form-control" placeholder="e.g. 2nd offence doubles fine; 3rd offence incurs 14-day gate restriction">
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-slate-700 small">Enforcing Authority</label>
                            <input type="text" name="enforcement_entity" id="modal_enforcement_entity" class="form-control" value="Estate Security & Management">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold text-slate-700 small">Status</label>
                            <select name="status" id="modal_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="under_review">Under Review</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold text-slate-700 small">Display Order</label>
                            <input type="number" name="display_order" id="modal_display_order" class="form-control" value="1">
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light p-3 border-top d-flex justify-content-between">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 shadow-sm" id="modal_submit_btn">
                        <i class="fa-solid fa-check me-1.5"></i> Save &amp; Enact Regulation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleScopeInputs() {
    const isZonal = document.getElementById('scope_zonal').checked;
    const targetZoneWrapper = document.getElementById('target_zone_wrapper');
    const zoneSelect = document.getElementById('modal_zone_id');
    if (isZonal) {
        targetZoneWrapper.style.display = 'block';
        zoneSelect.setAttribute('required', 'required');
    } else {
        targetZoneWrapper.style.display = 'none';
        zoneSelect.removeAttribute('required');
        zoneSelect.value = '';
    }
}

function openPolicyModal() {
    document.getElementById('policyModalLabel').innerHTML = '<i class="fa-solid fa-scale-balanced text-primary"></i> Enact Policy / Rule Clause';
    document.getElementById('modal_policy_id').value = '0';
    document.getElementById('scope_central').checked = true;
    document.getElementById('modal_zone_id').value = '';
    document.getElementById('modal_category_slug').selectedIndex = 0;
    document.getElementById('modal_code').value = '';
    document.getElementById('modal_title').value = '';
    document.getElementById('modal_description').value = '';
    document.getElementById('modal_offence_definition').value = '';
    document.getElementById('modal_punishment_type').value = 'warning';
    document.getElementById('modal_punishment_details').value = '';
    document.getElementById('modal_fine_amount').value = '0.00';
    document.getElementById('modal_repeat_offence_penalty').value = '';
    document.getElementById('modal_severity').value = 'medium';
    document.getElementById('modal_enforcement_entity').value = 'Estate Security & Management';
    document.getElementById('modal_status').value = 'active';
    document.getElementById('modal_display_order').value = '1';
    document.getElementById('modal_submit_btn').innerHTML = '<i class="fa-solid fa-check me-1.5"></i> Enact Regulation';
    toggleScopeInputs();
    new bootstrap.Modal(document.getElementById('policyModal')).show();
}

function editPolicy(pol) {
    document.getElementById('policyModalLabel').innerHTML = '<i class="fa-solid fa-pen-to-square text-primary"></i> Edit Regulation #' + pol.id;
    document.getElementById('modal_policy_id').value = pol.id;
    if (pol.scope === 'zonal') {
        document.getElementById('scope_zonal').checked = true;
        document.getElementById('modal_zone_id').value = pol.zone_id || '';
    } else {
        document.getElementById('scope_central').checked = true;
        document.getElementById('modal_zone_id').value = '';
    }
    document.getElementById('modal_category_slug').value = pol.category_slug || '';
    document.getElementById('modal_code').value = pol.code || '';
    document.getElementById('modal_title').value = pol.title || '';
    document.getElementById('modal_description').value = pol.description || '';
    document.getElementById('modal_offence_definition').value = pol.offence_definition || '';
    document.getElementById('modal_punishment_type').value = pol.punishment_type || 'warning';
    document.getElementById('modal_punishment_details').value = pol.punishment_details || '';
    document.getElementById('modal_fine_amount').value = pol.fine_amount || '0.00';
    document.getElementById('modal_repeat_offence_penalty').value = pol.repeat_offence_penalty || '';
    document.getElementById('modal_severity').value = pol.severity || 'medium';
    document.getElementById('modal_enforcement_entity').value = pol.enforcement_entity || '';
    document.getElementById('modal_status').value = pol.status || 'active';
    document.getElementById('modal_display_order').value = pol.display_order || '1';
    document.getElementById('modal_submit_btn').innerHTML = '<i class="fa-solid fa-check me-1.5"></i> Update Regulation';
    toggleScopeInputs();
    new bootstrap.Modal(document.getElementById('policyModal')).show();
}
</script>

<?php include '../includes/footer.php'; ?>
