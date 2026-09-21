<?php
// zone/policies.php - Zonal Policy & Local Bylaws Management
require_once '../config.php';
require_once '../includes/auth_guard.php';

requireZoneAccess();

$zone_id = get_current_zone_id();
$estate_id = get_estate_id();
$message = $flash_message ?? "";
$error = $flash_error ?? "";

// Fetch Zone Details
$zone_info = $conn->query("SELECT name, code FROM zones WHERE id = $zone_id AND estate_id = $estate_id LIMIT 1")->fetch_assoc();
$zone_name = $zone_info['name'] ?? 'Zone Sector';
$zone_code = $zone_info['code'] ?? 'ZONE';

// -------------------------------------------------------------
// POST HANDLERS: CREATE / EDIT / DELETE ZONAL BYLAWS (STRICTLY SCOPED TO THIS ZONE)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_zonal_bylaw') {
        $policy_id = intval($_POST['policy_id'] ?? 0);
        $category_slug = trim($_POST['category_slug'] ?? 'sanitation');
        $code = trim($_POST['code'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $offence_definition = trim($_POST['offence_definition'] ?? '');
        $punishment_type = trim($_POST['punishment_type'] ?? 'fine');
        $punishment_details = trim($_POST['punishment_details'] ?? '');
        $fine_amount = floatval($_POST['fine_amount'] ?? 0);
        $repeat_offence_penalty = trim($_POST['repeat_offence_penalty'] ?? '');
        $severity = in_array($_POST['severity'] ?? '', ['low', 'medium', 'high', 'critical']) ? $_POST['severity'] : 'medium';
        $enforcement_entity = trim($_POST['enforcement_entity'] ?? "$zone_name Sector Marshals");
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
        $display_order = intval($_POST['display_order'] ?? 1);

        if (empty($title) || empty($description)) {
            redirectWithFlash('policies?tab=zonal', null, "Bylaw Title and Description are required.");
        } else {
            if ($policy_id > 0) {
                // Ensure policy belongs to this zone and is zonal
                $chk = $conn->query("SELECT id FROM estate_policies WHERE id = $policy_id AND zone_id = $zone_id AND estate_id = $estate_id AND scope = 'zonal' LIMIT 1");
                if (!$chk || $chk->num_rows === 0) {
                    redirectWithFlash('policies?tab=zonal', null, "Unauthorized: You can only edit local bylaws belonging to your zone.");
                }

                $stmt = $conn->prepare("UPDATE estate_policies SET 
                    category_slug = ?, code = ?, title = ?, description = ?, 
                    offence_definition = ?, punishment_type = ?, punishment_details = ?, 
                    fine_amount = ?, repeat_offence_penalty = ?, severity = ?, 
                    enforcement_entity = ?, status = ?, display_order = ?
                    WHERE id = ? AND zone_id = ? AND estate_id = ?");
                if ($stmt) {
                    $stmt->bind_param(
                        "ssssssdsssiiii",
                        $category_slug, $code, $title, $description,
                        $offence_definition, $punishment_type, $punishment_details,
                        $fine_amount, $repeat_offence_penalty, $severity,
                        $enforcement_entity, $status, $display_order,
                        $policy_id, $zone_id, $estate_id
                    );
                    $stmt->execute();
                    $stmt->close();
                    redirectWithFlash('policies?tab=zonal', "Zonal bylaw updated successfully.", null);
                }
            } else {
                // Auto-generate code if blank
                if (empty($code)) {
                    $code = strtoupper($zone_code) . '-' . strtoupper(substr($category_slug, 0, 3)) . '-' . rand(100, 999);
                }

                $stmt = $conn->prepare("INSERT INTO estate_policies (
                    estate_id, zone_id, scope, category_slug, code, title, 
                    description, offence_definition, punishment_type, punishment_details, 
                    fine_amount, repeat_offence_penalty, severity, enforcement_entity, 
                    status, display_order, created_by
                ) VALUES (?, ?, 'zonal', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $uid = $_SESSION['user_id'] ?? 1;
                    $stmt->bind_param(
                        "iisssssssdsssiii",
                        $estate_id, $zone_id, $category_slug, $code, $title,
                        $description, $offence_definition, $punishment_type, $punishment_details,
                        $fine_amount, $repeat_offence_penalty, $severity,
                        $enforcement_entity, $status, $display_order, $uid
                    );
                    $stmt->execute();
                    $stmt->close();
                    redirectWithFlash('policies?tab=zonal', "New local zonal bylaw enacted successfully.", null);
                }
            }
        }
    }

    if ($action === 'delete_zonal_bylaw') {
        $policy_id = intval($_POST['policy_id'] ?? 0);
        $del = $conn->query("DELETE FROM estate_policies WHERE id = $policy_id AND zone_id = $zone_id AND estate_id = $estate_id AND scope = 'zonal'");
        if ($del && $conn->affected_rows > 0) {
            redirectWithFlash('policies?tab=zonal', "Zonal bylaw removed.", null);
        } else {
            redirectWithFlash('policies?tab=zonal', null, "Failed to remove bylaw or permission denied.");
        }
    }

    if ($action === 'toggle_status') {
        $policy_id = intval($_POST['policy_id'] ?? 0);
        $curr = $conn->query("SELECT status FROM estate_policies WHERE id = $policy_id AND zone_id = $zone_id AND estate_id = $estate_id AND scope = 'zonal' LIMIT 1")->fetch_assoc();
        if ($curr) {
            $new_st = ($curr['status'] === 'active') ? 'inactive' : 'active';
            $conn->query("UPDATE estate_policies SET status = '$new_st' WHERE id = $policy_id AND zone_id = $zone_id AND estate_id = $estate_id");
            redirectWithFlash('policies?tab=zonal', "Bylaw status set to " . strtoupper($new_st) . ".", null);
        }
    }
}

// -------------------------------------------------------------
// DATA FETCHING & TABS
// -------------------------------------------------------------
$categories = getPolicyCategories($conn, $estate_id);

$active_tab = $_GET['tab'] ?? 'zonal'; // 'zonal' (My Zone), 'central' (Central Read-Only), 'matrix'
$filter_category = $_GET['category'] ?? '';
$search = trim($_GET['search'] ?? '');

// Metrics
$my_zonal_cnt = $conn->query("SELECT COUNT(id) as c FROM estate_policies WHERE estate_id = $estate_id AND zone_id = $zone_id AND scope = 'zonal'")->fetch_assoc()['c'] ?? 0;
$central_cnt = $conn->query("SELECT COUNT(id) as c FROM estate_policies WHERE estate_id = $estate_id AND scope = 'central'")->fetch_assoc()['c'] ?? 0;
$my_fines_cnt = $conn->query("SELECT COUNT(id) as c FROM estate_policies WHERE estate_id = $estate_id AND zone_id = $zone_id AND fine_amount > 0")->fetch_assoc()['c'] ?? 0;

// Fetch lists
$zonal_filters = ['scope' => 'zonal', 'zone_id' => $zone_id, 'category_slug' => $filter_category, 'search' => $search];
$my_zonal_policies = getEstatePoliciesList($conn, $estate_id, $zonal_filters);

$central_filters = ['scope' => 'central', 'category_slug' => $filter_category, 'search' => $search];
$central_policies = getEstatePoliciesList($conn, $estate_id, $central_filters);

include '../includes/header.php';
include 'sidebar.php';
?>

<!-- Header Section -->
<div class="page-header-futuristic mb-4">
    <div>
        <div class="header-breadcrumbs">
            <a href="index">Zone Dashboard</a>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span>Local Governance</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span class="active">Zonal &amp; Central Policies</span>
        </div>
        <h1 class="page-title d-flex align-items-center gap-2">
            <i class="fa-solid fa-scale-balanced text-purple" style="color: #9333ea;"></i>
            <span>Zonal Bylaws &amp; Central Policies</span>
        </h1>
        <p class="text-secondary small mb-0">Manage localized sector bylaws for <strong><?php echo htmlspecialchars($zone_name); ?></strong> and view governing estate-wide central regulations.</p>
    </div>
    <div class="header-actions d-flex align-items-center gap-2">
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
            <i class="fa-solid fa-print me-1.5"></i> Print Handbook
        </button>
        <button type="button" class="btn text-white shadow-sm" style="background: #9333ea;" onclick="openZonalModal()">
            <i class="fa-solid fa-plus me-1.5"></i> Enact Local Zonal Bylaw
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
    <div class="col-6 col-lg-4">
        <div class="card border-0 shadow-sm rounded-4 p-3 d-flex flex-row align-items-center justify-content-between h-100" style="background: linear-gradient(135deg, #ffffff 0%, #faf5ff 100%); border: 1px solid rgba(168, 85, 247, 0.2) !important;">
            <div>
                <span class="text-secondary small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em; color: #7e22ce !important;">My Zone Local Bylaws</span>
                <h3 class="fw-bold mb-0 mt-1" style="color: #9333ea;"><?php echo number_format($my_zonal_cnt); ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">Configurable by Zone Admin</small>
            </div>
            <div style="width: 48px; height: 48px; border-radius: 14px; background: rgba(168, 85, 247, 0.15); color: #9333ea; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                <i class="fa-solid fa-layer-group"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card border-0 shadow-sm rounded-4 p-3 d-flex flex-row align-items-center justify-content-between h-100" style="background: linear-gradient(135deg, #ffffff 0%, #eff6ff 100%); border: 1px solid rgba(59, 130, 246, 0.2) !important;">
            <div>
                <span class="text-secondary small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">Central Governing Laws</span>
                <h3 class="fw-bold mb-0 text-primary mt-1"><?php echo number_format($central_cnt); ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">Estate-Wide (Read-Only)</small>
            </div>
            <div style="width: 48px; height: 48px; border-radius: 14px; background: rgba(59, 130, 246, 0.12); color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                <i class="fa-solid fa-globe"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card border-0 shadow-sm rounded-4 p-3 d-flex flex-row align-items-center justify-content-between h-100" style="background: linear-gradient(135deg, #ffffff 0%, #fef2f2 100%); border: 1px solid rgba(239, 68, 68, 0.2) !important;">
            <div>
                <span class="text-secondary small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">Zone Sanctions &amp; Fines</span>
                <h3 class="fw-bold mb-0 text-danger mt-1"><?php echo number_format($my_fines_cnt); ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">Local Violation Penalties</small>
            </div>
            <div style="width: 48px; height: 48px; border-radius: 14px; background: rgba(239, 68, 68, 0.12); color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                <i class="fa-solid fa-money-bill-wave"></i>
            </div>
        </div>
    </div>
</div>

<!-- Tabs & Category Filter -->
<div class="d-flex flex-wrap align-items-center justify-content-between border-bottom pb-2 mb-3 gap-2">
    <ul class="nav nav-pills gap-2" id="zonalPolicyTabs">
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'zonal') ? 'active' : ''; ?> fw-semibold px-3 py-2 rounded-3" href="?tab=zonal" style="<?php echo ($active_tab === 'zonal') ? 'background: #9333ea; color: white;' : ''; ?>">
                <i class="fa-solid fa-layer-group me-1.5"></i> My Zone Bylaws (<?php echo htmlspecialchars($zone_code); ?>)
                <span class="badge ms-1.5 <?php echo ($active_tab === 'zonal') ? 'bg-white text-purple' : 'bg-light text-dark'; ?>" style="<?php echo ($active_tab === 'zonal') ? 'color: #9333ea !important;' : ''; ?>"><?php echo $my_zonal_cnt; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'central') ? 'active' : ''; ?> fw-semibold px-3 py-2 rounded-3" href="?tab=central">
                <i class="fa-solid fa-globe me-1.5"></i> Central Estate Laws (Read-Only)
                <span class="badge ms-1.5 <?php echo ($active_tab === 'central') ? 'bg-white text-primary' : 'bg-light text-dark'; ?>"><?php echo $central_cnt; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'matrix') ? 'active' : ''; ?> fw-semibold px-3 py-2 rounded-3" href="?tab=matrix">
                <i class="fa-solid fa-table-cells me-1.5"></i> Offences &amp; Penalties Matrix
            </a>
        </li>
    </ul>

    <!-- Category & Keyword Search Form -->
    <form method="GET" class="d-flex align-items-center gap-2 ms-auto">
        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">

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

        <?php if (!empty($search) || !empty($filter_category)): ?>
            <a href="?tab=<?php echo htmlspecialchars($active_tab); ?>" class="btn btn-sm btn-light border text-danger" title="Clear Filters">
                <i class="fa-solid fa-xmark"></i>
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- ============================================================= -->
<!-- TAB 1: MY ZONE BYLAWS (FULL CRUD FOR ZONE ADMIN)              -->
<!-- ============================================================= -->
<?php if ($active_tab === 'zonal'): ?>
    <div class="alert alert-purple rounded-4 p-3 mb-4 d-flex align-items-center justify-content-between" style="background: rgba(168, 85, 247, 0.08); border: 1px solid rgba(168, 85, 247, 0.25);">
        <div class="d-flex align-items-center gap-3">
            <div class="text-white rounded-3 p-2 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; background: #9333ea;">
                <i class="fa-solid fa-sliders fs-5"></i>
            </div>
            <div>
                <strong class="d-block" style="color: #7e22ce;">Local Sector Authority: <?php echo htmlspecialchars($zone_name); ?></strong>
                <span class="text-secondary small">These bylaws apply exclusively within <?php echo htmlspecialchars($zone_name); ?>. You can define localized fines, specific trash collection curbside hours, crescent parking rules, and neighborhood protocols.</span>
            </div>
        </div>
        <button type="button" class="btn btn-sm text-white px-3 py-2 rounded-pill shadow-sm" style="background: #9333ea;" onclick="openZonalModal()">
            <i class="fa-solid fa-plus me-1"></i> New Local Bylaw
        </button>
    </div>

    <?php if (empty($my_zonal_policies)): ?>
        <div class="card border-0 shadow-sm rounded-4 p-5 text-center my-4">
            <div style="width: 72px; height: 72px; border-radius: 50%; background: #faf5ff; color: #9333ea; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 1rem auto;">
                <i class="fa-solid fa-layer-group"></i>
            </div>
            <h5 class="fw-bold text-slate-800">No Local Bylaws Enacted Yet</h5>
            <p class="text-secondary small mb-3">Enact bylaws tailored to <?php echo htmlspecialchars($zone_name); ?> (e.g. inner street parking, waste schedules, sector security dues).</p>
            <div>
                <button type="button" class="btn text-white rounded-3" style="background: #9333ea;" onclick="openZonalModal()">
                    <i class="fa-solid fa-plus me-1.5"></i> Enact First Local Bylaw
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($my_zonal_policies as $pol): ?>
                <div class="col-12 col-lg-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden d-flex flex-column" style="border: 1px solid rgba(168, 85, 247, 0.25) !important;">
                        <div class="card-header bg-white p-3.5 border-bottom d-flex align-items-start justify-content-between gap-2">
                            <div>
                                <div class="d-flex align-items-center gap-2 flex-wrap mb-1.5">
                                    <span class="badge px-2 py-0.5 rounded-pill font-monospace" style="background: rgba(168, 85, 247, 0.15); color: #7e22ce; border: 1px solid rgba(168, 85, 247, 0.3); font-size: 0.72rem;">
                                        <i class="fa-solid fa-layer-group me-1"></i> <?php echo htmlspecialchars($zone_name); ?>
                                    </span>
                                    <span class="badge px-2 py-0.5 rounded-pill" style="background: <?php echo htmlspecialchars($pol['category_color'] ?: '#3b82f6'); ?>18; color: <?php echo htmlspecialchars($pol['category_color'] ?: '#3b82f6'); ?>; border: 1px solid <?php echo htmlspecialchars($pol['category_color'] ?: '#3b82f6'); ?>33; font-size: 0.72rem;">
                                        <i class="fa-solid <?php echo htmlspecialchars($pol['category_icon'] ?: 'fa-gavel'); ?> me-1"></i>
                                        <?php echo htmlspecialchars($pol['category_name'] ?: ucfirst($pol['category_slug'])); ?>
                                    </span>
                                    <?php echo formatSeverityBadge($pol['severity']); ?>
                                </div>
                                <h5 class="fw-bold text-slate-800 mb-0 d-flex align-items-center gap-2">
                                    <span class="text-secondary small font-monospace"><?php echo htmlspecialchars($pol['code'] ?: 'ZN-' . $pol['id']); ?>:</span>
                                    <?php echo htmlspecialchars($pol['title']); ?>
                                </h5>
                            </div>

                            <!-- Actions -->
                            <div class="dropdown">
                                <button class="btn btn-sm btn-light border-0 rounded-circle" type="button" data-bs-toggle="dropdown" style="width: 32px; height: 32px;">
                                    <i class="fa-solid fa-ellipsis-vertical text-secondary"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3">
                                    <li>
                                        <a class="dropdown-item d-flex align-items-center gap-2" href="javascript:void(0)" onclick='editZonalPolicy(<?php echo json_encode($pol, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                            <i class="fa-solid fa-pen-to-square text-primary"></i> Edit Bylaw
                                        </a>
                                    </li>
                                    <li>
                                        <form method="POST" onsubmit="return confirm('Toggle active status for this bylaw?');">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="policy_id" value="<?php echo $pol['id']; ?>">
                                            <button type="submit" class="dropdown-item d-flex align-items-center gap-2">
                                                <i class="fa-solid fa-power-off <?php echo ($pol['status'] === 'active') ? 'text-warning' : 'text-success'; ?>"></i>
                                                <?php echo ($pol['status'] === 'active') ? 'Deactivate' : 'Activate'; ?>
                                            </button>
                                        </form>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form method="POST" onsubmit="return confirm('Permanently delete this local bylaw?');">
                                            <input type="hidden" name="action" value="delete_zonal_bylaw">
                                            <input type="hidden" name="policy_id" value="<?php echo $pol['id']; ?>">
                                            <button type="submit" class="dropdown-item d-flex align-items-center gap-2 text-danger">
                                                <i class="fa-solid fa-trash-can"></i> Delete Bylaw
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <div class="card-body p-3.5 flex-grow-1">
                            <div class="mb-3">
                                <div class="text-secondary small fw-bold text-uppercase mb-1" style="font-size: 0.72rem; letter-spacing: 0.04em;">Bylaw Clause:</div>
                                <p class="text-slate-700 small mb-0" style="line-height: 1.55;"><?php echo nl2br(htmlspecialchars($pol['description'])); ?></p>
                            </div>

                            <div class="p-2.5 rounded-3 mb-3" style="background: #fff1f2; border-left: 3px solid #f43f5e;">
                                <div class="d-flex align-items-center gap-1.5 text-danger fw-bold small mb-1" style="font-size: 0.75rem;">
                                    <i class="fa-solid fa-circle-exclamation"></i> Breach Definition:
                                </div>
                                <div class="text-slate-700 small" style="font-size: 0.8rem; line-height: 1.45;">
                                    <?php echo nl2br(htmlspecialchars($pol['offence_definition'] ?: 'Any infraction of the above bylaw.')); ?>
                                </div>
                            </div>

                            <div class="p-2.5 rounded-3 mb-2" style="background: #faf5ff; border: 1px solid rgba(168, 85, 247, 0.2);">
                                <div class="d-flex align-items-center justify-content-between mb-1.5 flex-wrap gap-1">
                                    <div class="d-flex align-items-center gap-1.5">
                                        <span class="text-secondary small fw-bold" style="font-size: 0.75rem;">Sanction:</span>
                                        <?php echo formatPunishmentTypeBadge($pol['punishment_type']); ?>
                                    </div>
                                    <?php if ($pol['fine_amount'] > 0): ?>
                                        <div class="text-end">
                                            <span class="text-secondary small" style="font-size: 0.72rem;">Zone Surcharge:</span>
                                            <span class="fw-bold text-danger font-monospace fs-6 ms-1">₦<?php echo number_format($pol['fine_amount'], 2); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($pol['punishment_details'])): ?>
                                    <div class="text-secondary small" style="font-size: 0.78rem;">
                                        <strong>Enforcement:</strong> <?php echo htmlspecialchars($pol['punishment_details']); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($pol['repeat_offence_penalty'])): ?>
                                    <div class="text-danger small mt-1" style="font-size: 0.75rem;">
                                        <i class="fa-solid fa-arrow-trend-up me-1"></i> <strong>Repeat Offence:</strong> <?php echo htmlspecialchars($pol['repeat_offence_penalty']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card-footer bg-light bg-opacity-50 p-2.5 px-3.5 border-top d-flex align-items-center justify-content-between text-secondary small" style="font-size: 0.72rem;">
                            <div>
                                <i class="fa-solid fa-shield me-1" style="color: #9333ea;"></i>
                                Enforced by: <strong><?php echo htmlspecialchars($pol['enforcement_entity'] ?: $zone_name . ' Sector Security'); ?></strong>
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

<!-- ============================================================= -->
<!-- TAB 2: CENTRAL GOVERNING LAWS (READ-ONLY)                     -->
<!-- ============================================================= -->
<?php elseif ($active_tab === 'central'): ?>
    <div class="alert alert-primary bg-primary bg-opacity-10 border-primary border-opacity-25 rounded-4 p-3 mb-4 d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
            <div class="bg-primary text-white rounded-3 p-2 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                <i class="fa-solid fa-lock fs-5"></i>
            </div>
            <div>
                <strong class="d-block text-primary">Central Estate Governing Regulations (Read-Only)</strong>
                <span class="text-secondary small">These supreme policies are enacted centrally by Estate Management and apply uniformly across all sectors. Zonal administrations enforce these policies alongside their own local bylaws.</span>
            </div>
        </div>
        <span class="badge bg-primary px-3 py-2 rounded-pill font-monospace" style="letter-spacing: 0.05em;">ESTATE-WIDE JURISDICTION</span>
    </div>

    <div class="row g-4">
        <?php foreach ($central_policies as $pol): ?>
            <div class="col-12 col-lg-6">
                <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden d-flex flex-column" style="border: 1px solid rgba(226, 232, 240, 0.8) !important;">
                    <div class="card-header bg-white p-3.5 border-bottom d-flex align-items-start justify-content-between gap-2">
                        <div>
                            <div class="d-flex align-items-center gap-2 flex-wrap mb-1.5">
                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-0.5 rounded-pill font-monospace" style="font-size: 0.72rem;">
                                    <i class="fa-solid fa-globe me-1"></i> Central Estate
                                </span>
                                <span class="badge px-2 py-0.5 rounded-pill" style="background: <?php echo htmlspecialchars($pol['category_color'] ?: '#3b82f6'); ?>18; color: <?php echo htmlspecialchars($pol['category_color'] ?: '#3b82f6'); ?>; border: 1px solid <?php echo htmlspecialchars($pol['category_color'] ?: '#3b82f6'); ?>33; font-size: 0.72rem;">
                                    <i class="fa-solid <?php echo htmlspecialchars($pol['category_icon'] ?: 'fa-gavel'); ?> me-1"></i>
                                    <?php echo htmlspecialchars($pol['category_name'] ?: ucfirst($pol['category_slug'])); ?>
                                </span>
                                <?php echo formatSeverityBadge($pol['severity']); ?>
                            </div>
                            <h5 class="fw-bold text-slate-800 mb-0 d-flex align-items-center gap-2">
                                <span class="text-secondary small font-monospace"><?php echo htmlspecialchars($pol['code'] ?: 'POL-' . $pol['id']); ?>:</span>
                                <?php echo htmlspecialchars($pol['title']); ?>
                            </h5>
                        </div>
                        <span class="badge bg-light text-secondary border px-2 py-1" title="Managed centrally by Estate Administration">
                            <i class="fa-solid fa-lock me-1"></i> Central Law
                        </span>
                    </div>

                    <div class="card-body p-3.5 flex-grow-1">
                        <div class="mb-3">
                            <div class="text-secondary small fw-bold text-uppercase mb-1" style="font-size: 0.72rem; letter-spacing: 0.04em;">Regulation Description:</div>
                            <p class="text-slate-700 small mb-0" style="line-height: 1.55;"><?php echo nl2br(htmlspecialchars($pol['description'])); ?></p>
                        </div>

                        <div class="p-2.5 rounded-3 mb-3" style="background: #fff1f2; border-left: 3px solid #f43f5e;">
                            <div class="d-flex align-items-center gap-1.5 text-danger fw-bold small mb-1" style="font-size: 0.75rem;">
                                <i class="fa-solid fa-circle-exclamation"></i> Breach Definition:
                            </div>
                            <div class="text-slate-700 small" style="font-size: 0.8rem; line-height: 1.45;">
                                <?php echo nl2br(htmlspecialchars($pol['offence_definition'] ?: 'Any non-compliance with central guidelines.')); ?>
                            </div>
                        </div>

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
                                    <i class="fa-solid fa-arrow-trend-up me-1"></i> <strong>Repeat Penalty:</strong> <?php echo htmlspecialchars($pol['repeat_offence_penalty']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card-footer bg-light bg-opacity-50 p-2.5 px-3.5 border-top d-flex align-items-center justify-content-between text-secondary small" style="font-size: 0.72rem;">
                        <div>
                            <i class="fa-solid fa-building-shield me-1 text-primary"></i>
                            Enforcing Entity: <strong><?php echo htmlspecialchars($pol['enforcement_entity'] ?: 'Estate Security Command'); ?></strong>
                        </div>
                        <div class="text-muted">
                            Mandatory Clause
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<!-- ============================================================= -->
<!-- TAB 3: MATRIX VIEW                                            -->
<!-- ============================================================= -->
<?php elseif ($active_tab === 'matrix'): ?>
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
        <div class="card-header bg-white py-3 px-4 border-bottom d-flex align-items-center justify-content-between">
            <div>
                <h5 class="fw-bold mb-0 text-slate-800"><i class="fa-solid fa-table-cells text-purple me-2" style="color: #9333ea;"></i>Combined Offences &amp; Penalties Matrix</h5>
                <small class="text-muted">Unified schedule of Central Regulations and <?php echo htmlspecialchars($zone_name); ?> local bylaws</small>
            </div>
            <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-download me-1"></i> Print Matrix</button>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-uppercase text-secondary" style="font-size: 0.75rem; letter-spacing: 0.05em;">
                    <tr>
                        <th class="ps-4">Rule Code</th>
                        <th>Jurisdiction</th>
                        <th>Category</th>
                        <th>Offence / Violation</th>
                        <th>Sanction Type</th>
                        <th>Fine (₦)</th>
                        <th>Repeat Violation Penalty</th>
                        <th>Enforcement</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $combined = array_merge($my_zonal_policies, $central_policies);
                    if (empty($combined)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">No rules match search criteria.</td>
                        </tr>
                    <?php else: 
                        foreach ($combined as $row): ?>
                        <tr>
                            <td class="ps-4">
                                <span class="badge font-monospace bg-light text-dark border px-2 py-1"><?php echo htmlspecialchars($row['code'] ?: 'REF-' . $row['id']); ?></span>
                            </td>
                            <td>
                                <?php if ($row['scope'] === 'central'): ?>
                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1 rounded-pill">
                                        <i class="fa-solid fa-globe me-1"></i> Central Estate
                                    </span>
                                <?php else: ?>
                                    <span class="badge px-2 py-1 rounded-pill" style="background: rgba(168, 85, 247, 0.12); color: #7e22ce; border: 1px solid rgba(168, 85, 247, 0.25);">
                                        <i class="fa-solid fa-layer-group me-1"></i> <?php echo htmlspecialchars($zone_name); ?>
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
                            </td>
                            <td>
                                <?php if ($row['fine_amount'] > 0): ?>
                                    <span class="fw-bold text-danger font-monospace">₦<?php echo number_format($row['fine_amount'], 2); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border">No Fine / Warning</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-secondary" style="max-width: 200px;">
                                <?php echo htmlspecialchars($row['repeat_offence_penalty'] ?: 'Standard escalation'); ?>
                            </td>
                            <td class="small text-muted">
                                <?php echo htmlspecialchars($row['enforcement_entity'] ?: 'Security'); ?>
                            </td>
                        </tr>
                    <?php endforeach; 
                    endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- ------------------------------------------------------------- -->
<!-- MODAL: CREATE / EDIT LOCAL ZONAL BYLAW                       -->
<!-- ------------------------------------------------------------- -->
<div class="modal fade" id="zonalPolicyModal" tabindex="-1" aria-labelledby="zonalPolicyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <form method="POST">
                <input type="hidden" name="action" value="save_zonal_bylaw">
                <input type="hidden" name="policy_id" id="zmodal_policy_id" value="0">

                <div class="modal-header text-white py-3 px-4" style="background: #7e22ce;">
                    <h5 class="modal-title fw-bold text-white d-flex align-items-center gap-2" id="zonalPolicyModalLabel">
                        <i class="fa-solid fa-layer-group"></i>
                        <span>Enact Local Zonal Bylaw</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="alert alert-purple bg-opacity-10 border border-purple border-opacity-25 rounded-3 p-2.5 mb-3" style="background: rgba(168, 85, 247, 0.08); border-color: rgba(168, 85, 247, 0.25);">
                        <i class="fa-solid fa-location-dot me-1 text-purple" style="color: #9333ea;"></i>
                        Target Sector: <strong><?php echo htmlspecialchars($zone_name); ?> (<?php echo htmlspecialchars($zone_code); ?>)</strong>. This bylaw will apply strictly to residents and properties in this zone.
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-slate-700 small">Category <span class="text-danger">*</span></label>
                            <select name="category_slug" id="zmodal_category_slug" class="form-select" required>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['slug']; ?>">
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-slate-700 small">Bylaw Code</label>
                            <input type="text" name="code" id="zmodal_code" class="form-control font-monospace" placeholder="e.g. <?php echo htmlspecialchars($zone_code); ?>-TRF-001">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-slate-700 small">Severity</label>
                            <select name="severity" id="zmodal_severity" class="form-select">
                                <option value="low">Low Severity</option>
                                <option value="medium" selected>Medium Severity</option>
                                <option value="high">High Severity</option>
                                <option value="critical">Critical Violation</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-slate-700 small">Bylaw Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" id="zmodal_title" class="form-control" placeholder="e.g. Crescent Parking Protocol or Refuse Curbside Timing" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-slate-700 small">Bylaw Description &amp; Directives <span class="text-danger">*</span></label>
                        <textarea name="description" id="zmodal_description" class="form-control" rows="3" placeholder="Specify local rules, designated days, permissible hours, or restricted streets in this zone..." required></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-danger small">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i> Specific Violation / Breach Definition
                        </label>
                        <textarea name="offence_definition" id="zmodal_offence_definition" class="form-control" rows="2" placeholder="Define what constitutes a local sector violation..."></textarea>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-slate-700 small">Sanction Type</label>
                            <select name="punishment_type" id="zmodal_punishment_type" class="form-select">
                                <option value="warning">Official Warning</option>
                                <option value="fine" selected>Monetary Fine / Surcharge</option>
                                <option value="clamping_towing">Wheel Clamping / Towing</option>
                                <option value="gate_restriction">Sector Gate Barrier Barcode Revoked</option>
                                <option value="privilege_suspension">Sector Green Area Suspension</option>
                                <option value="community_service">Zone Environmental Cleanup</option>
                                <option value="other">Zonal Disciplinary Council</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-slate-700 small">Zonal Surcharge / Fine (₦)</label>
                            <div class="input-group">
                                <span class="input-group-text">₦</span>
                                <input type="number" step="0.01" name="fine_amount" id="zmodal_fine_amount" class="form-control font-monospace" placeholder="0.00" value="0.00">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-slate-700 small">Enforcement Specifics</label>
                        <input type="text" name="punishment_details" id="zmodal_punishment_details" class="form-control" placeholder="e.g. Surcharge credited to Zone Maintenance Fund; de-clamping upon receipt">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-danger small">Repeat Violation Penalty</label>
                        <input type="text" name="repeat_offence_penalty" id="zmodal_repeat_offence_penalty" class="form-control" placeholder="e.g. Fine doubles on second occurrence">
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-slate-700 small">Enforcement Body</label>
                            <input type="text" name="enforcement_entity" id="zmodal_enforcement_entity" class="form-control" value="<?php echo htmlspecialchars($zone_name); ?> Sector Marshals">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold text-slate-700 small">Status</label>
                            <select name="status" id="zmodal_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold text-slate-700 small">Display Order</label>
                            <input type="number" name="display_order" id="zmodal_display_order" class="form-control" value="1">
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light p-3 border-top d-flex justify-content-between">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn text-white px-4 shadow-sm" style="background: #9333ea;" id="zmodal_submit_btn">
                        <i class="fa-solid fa-check me-1.5"></i> Save &amp; Enact Bylaw
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openZonalModal() {
    document.getElementById('zonalPolicyModalLabel').innerHTML = '<i class="fa-solid fa-layer-group"></i> Enact Local Zonal Bylaw';
    document.getElementById('zmodal_policy_id').value = '0';
    document.getElementById('zmodal_category_slug').selectedIndex = 0;
    document.getElementById('zmodal_code').value = '';
    document.getElementById('zmodal_title').value = '';
    document.getElementById('zmodal_description').value = '';
    document.getElementById('zmodal_offence_definition').value = '';
    document.getElementById('zmodal_punishment_type').value = 'fine';
    document.getElementById('zmodal_punishment_details').value = '';
    document.getElementById('zmodal_fine_amount').value = '0.00';
    document.getElementById('zmodal_repeat_offence_penalty').value = '';
    document.getElementById('zmodal_severity').value = 'medium';
    document.getElementById('zmodal_enforcement_entity').value = '<?php echo addslashes($zone_name); ?> Sector Marshals';
    document.getElementById('zmodal_status').value = 'active';
    document.getElementById('zmodal_display_order').value = '1';
    document.getElementById('zmodal_submit_btn').innerHTML = '<i class="fa-solid fa-check me-1.5"></i> Enact Bylaw';
    new bootstrap.Modal(document.getElementById('zonalPolicyModal')).show();
}

function editZonalPolicy(pol) {
    document.getElementById('zonalPolicyModalLabel').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Edit Zonal Bylaw #' + pol.id;
    document.getElementById('zmodal_policy_id').value = pol.id;
    document.getElementById('zmodal_category_slug').value = pol.category_slug || '';
    document.getElementById('zmodal_code').value = pol.code || '';
    document.getElementById('zmodal_title').value = pol.title || '';
    document.getElementById('zmodal_description').value = pol.description || '';
    document.getElementById('zmodal_offence_definition').value = pol.offence_definition || '';
    document.getElementById('zmodal_punishment_type').value = pol.punishment_type || 'fine';
    document.getElementById('zmodal_punishment_details').value = pol.punishment_details || '';
    document.getElementById('zmodal_fine_amount').value = pol.fine_amount || '0.00';
    document.getElementById('zmodal_repeat_offence_penalty').value = pol.repeat_offence_penalty || '';
    document.getElementById('zmodal_severity').value = pol.severity || 'medium';
    document.getElementById('zmodal_enforcement_entity').value = pol.enforcement_entity || '';
    document.getElementById('zmodal_status').value = pol.status || 'active';
    document.getElementById('zmodal_display_order').value = pol.display_order || '1';
    document.getElementById('zmodal_submit_btn').innerHTML = '<i class="fa-solid fa-check me-1.5"></i> Update Bylaw';
    new bootstrap.Modal(document.getElementById('zonalPolicyModal')).show();
}
</script>

<?php include '../includes/footer.php'; ?>
