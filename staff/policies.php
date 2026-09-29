<?php
// staff/policies.php - Security Guard & Staff Compliance & Enforcement Manual
require_once '../config.php';
require_once '../includes/auth_guard.php';

if (!isStaffRole() && !isAdminRole()) {
    header("Location: ../login?error=unauthorized");
    exit;
}

$estate_id = get_estate_id();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['name'] ?? 'Officer';
$user_role = $_SESSION['role'] ?? 'security';

$categories = getPolicyCategories($conn, $estate_id);

// Fetch zones for staff reference
$zones_res = $conn->query("SELECT id, name, code FROM zones WHERE estate_id = $estate_id ORDER BY name ASC");
$zones_list = [];
if ($zones_res) {
    while ($z = $zones_res->fetch_assoc()) {
        $zones_list[] = $z;
    }
}

// Active Tab & Filters
$active_tab = $_GET['tab'] ?? 'central'; // 'central', 'zonal', 'enforcement'
$filter_category = $_GET['category'] ?? '';
$filter_zone = isset($_GET['zone_id']) ? intval($_GET['zone_id']) : 0;
$search = trim($_GET['search'] ?? '');

// Counts
$central_cnt = $conn->query("SELECT COUNT(id) as c FROM estate_policies WHERE estate_id = $estate_id AND scope = 'central' AND status = 'active'")->fetch_assoc()['c'] ?? 0;
$zonal_cnt = $conn->query("SELECT COUNT(id) as c FROM estate_policies WHERE estate_id = $estate_id AND scope = 'zonal' AND status = 'active'")->fetch_assoc()['c'] ?? 0;
$clamping_cnt = $conn->query("SELECT COUNT(id) as c FROM estate_policies WHERE estate_id = $estate_id AND punishment_type = 'clamping_towing' AND status = 'active'")->fetch_assoc()['c'] ?? 0;
$fines_cnt = $conn->query("SELECT COUNT(id) as c FROM estate_policies WHERE estate_id = $estate_id AND fine_amount > 0 AND status = 'active'")->fetch_assoc()['c'] ?? 0;

// Fetch lists
$central_filters = ['scope' => 'central', 'status' => 'active', 'category_slug' => $filter_category, 'search' => $search];
$central_policies = getEstatePoliciesList($conn, $estate_id, $central_filters);

$zonal_filters = ['scope' => 'zonal', 'status' => 'active', 'category_slug' => $filter_category, 'search' => $search];
if ($filter_zone > 0) {
    $zonal_filters['zone_id'] = $filter_zone;
}
$zonal_policies = getEstatePoliciesList($conn, $estate_id, $zonal_filters);

include 'header.php';
include 'sidebar.php';
?>

<!-- Header Section -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
    <div>
        <div class="text-secondary small fw-semibold text-uppercase" style="letter-spacing: 0.05em;">Security &amp; Operational Compliance</div>
        <h2 class="fw-bold mb-1 text-slate-800 d-flex align-items-center gap-2">
            <i class="fa-solid fa-scale-balanced text-primary"></i>
            <span>Estate Rules, Violations &amp; Enforcement Handbook</span>
        </h2>
        <p class="text-secondary small mb-0">Official operational manual for guards and staff detailing violation criteria, clamping protocols, fine tariffs, and zonal bylaws.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="incidents" class="btn btn-danger shadow-sm">
            <i class="fa-solid fa-book-skull me-1.5"></i> Log Occurrence / Violation
        </a>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
            <i class="fa-solid fa-print me-1.5"></i> Print Field Manual
        </button>
    </div>
</div>

<!-- Metrics Row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 p-3 d-flex flex-row align-items-center justify-content-between h-100 bg-white">
            <div>
                <span class="text-secondary small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">Central Rules</span>
                <h3 class="fw-bold mb-0 text-primary mt-1"><?php echo number_format($central_cnt); ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">Estate-Wide Enforceable</small>
            </div>
            <div style="width: 48px; height: 48px; border-radius: 14px; background: rgba(59, 130, 246, 0.12); color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                <i class="fa-solid fa-globe"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 p-3 d-flex flex-row align-items-center justify-content-between h-100 bg-white">
            <div>
                <span class="text-secondary small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">Zonal Bylaws</span>
                <h3 class="fw-bold mb-0 text-purple mt-1" style="color: #9333ea;"><?php echo number_format($zonal_cnt); ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">Sector-Specific Rules</small>
            </div>
            <div style="width: 48px; height: 48px; border-radius: 14px; background: rgba(168, 85, 247, 0.12); color: #9333ea; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                <i class="fa-solid fa-layer-group"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 p-3 d-flex flex-row align-items-center justify-content-between h-100 bg-white">
            <div>
                <span class="text-secondary small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">Clamping Violations</span>
                <h3 class="fw-bold mb-0 text-danger mt-1"><?php echo number_format($clamping_cnt); ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">Wheel Clamp Sanctions</small>
            </div>
            <div style="width: 48px; height: 48px; border-radius: 14px; background: rgba(239, 68, 68, 0.12); color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                <i class="fa-solid fa-truck-pickup"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm rounded-4 p-3 d-flex flex-row align-items-center justify-content-between h-100 bg-white">
            <div>
                <span class="text-secondary small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">Defined Fines</span>
                <h3 class="fw-bold mb-0 text-warning mt-1" style="color: #d97706;"><?php echo number_format($fines_cnt); ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">Billable Penalty Clauses</small>
            </div>
            <div style="width: 48px; height: 48px; border-radius: 14px; background: rgba(245, 158, 11, 0.12); color: #d97706; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                <i class="fa-solid fa-money-bill-wave"></i>
            </div>
        </div>
    </div>
</div>

<!-- Tabs: Strict Split between Central Laws and Zonal Bylaws -->
<div class="d-flex flex-wrap align-items-center justify-content-between border-bottom pb-2 mb-3 gap-2">
    <ul class="nav nav-pills gap-2" id="staffPolicyTabs">
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'central') ? 'active' : ''; ?> fw-semibold px-3 py-2 rounded-3" href="?tab=central">
                <i class="fa-solid fa-globe me-1.5"></i> Central Estate Rules (Estate-Wide)
                <span class="badge ms-1.5 <?php echo ($active_tab === 'central') ? 'bg-white text-primary' : 'bg-light text-dark'; ?>"><?php echo $central_cnt; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'zonal') ? 'active' : ''; ?> fw-semibold px-3 py-2 rounded-3" href="?tab=zonal" style="<?php echo ($active_tab === 'zonal') ? 'background: #9333ea; color: white;' : ''; ?>">
                <i class="fa-solid fa-layer-group me-1.5"></i> Zonal Bylaws by Sector
                <span class="badge ms-1.5 <?php echo ($active_tab === 'zonal') ? 'bg-white text-purple' : 'bg-light text-dark'; ?>" style="<?php echo ($active_tab === 'zonal') ? 'color: #9333ea !important;' : ''; ?>"><?php echo $zonal_cnt; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'enforcement') ? 'active' : ''; ?> fw-semibold px-3 py-2 rounded-3" href="?tab=enforcement">
                <i class="fa-solid fa-shield-halved me-1.5"></i> Quick Enforcement Matrix
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

        <div class="search-integrated-wrap" style="width: 220px;">
            <i class="fa-solid fa-magnifying-glass search-icon"></i>
            <input type="text" name="search" class="form-control" placeholder="Search violation or fine..." value="<?php echo htmlspecialchars($search); ?>">
            <?php if (!empty($search)): ?>
                <a href="?tab=<?php echo htmlspecialchars($active_tab); ?>" class="search-clear-btn" title="Clear search"><i class="fa-solid fa-xmark"></i></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ============================================================= -->
<!-- TAB 1: CENTRAL ESTATE RULES                                   -->
<!-- ============================================================= -->
<?php if ($active_tab === 'central'): ?>
    <div class="alert alert-primary bg-primary bg-opacity-10 border-primary border-opacity-25 rounded-3 p-2.5 p-sm-3 mb-3">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2.5 min-w-0" style="flex: 1 1 240px;">
                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 32px; height: 32px; font-size: 0.85rem;">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <div class="min-w-0">
                    <span class="fw-bold text-primary d-block text-truncate" style="font-size: 0.88rem;">Central Estate Enforcement Jurisdiction</span>
                    <span class="text-secondary small d-none d-md-inline" style="font-size: 0.78rem;">Estate-wide authority to enforce these regulations at all gates and common areas.</span>
                </div>
            </div>
            <span class="badge bg-primary rounded-pill font-monospace px-2.5 py-1 flex-shrink-0 ms-auto" style="font-size: 0.68rem; letter-spacing: 0.04em;">ESTATE-WIDE MANDATE</span>
        </div>
    </div>

    <div class="row g-4">
        <?php foreach ($central_policies as $pol): ?>
            <div class="col-12 col-lg-6">
                <div class="card policy-card border-0 shadow-sm rounded-4 h-100 overflow-hidden d-flex flex-column">
                    <!-- Card Header -->
                    <div class="card-header p-3 p-sm-3.5 pb-2 border-bottom d-flex align-items-start justify-content-between gap-2">
                        <div class="flex-grow-1 min-w-0">
                            <!-- Horizontal Badges Row (Spacious & Clean) -->
                            <div class="d-flex align-items-center gap-2 flex-wrap mb-2" style="row-gap: 6px; column-gap: 8px;">
                                <span class="badge bg-light text-dark border font-monospace px-2.5 py-1" style="font-size: 0.72rem; font-weight: 700;"><?php echo htmlspecialchars($pol['code'] ?: 'POL-' . $pol['id']); ?></span>
                                <span class="badge badge-policy-scope px-2.5 py-1 rounded-pill" style="font-size: 0.7rem; font-weight: 600;">
                                    <i class="fa-solid fa-globe me-1 text-primary"></i>Central
                                </span>
                                <span class="badge badge-policy-cat px-2.5 py-1 rounded-pill" style="font-size: 0.7rem; font-weight: 500;">
                                    <i class="fa-solid <?php echo htmlspecialchars($pol['category_icon'] ?: 'fa-gavel'); ?> me-1 text-secondary"></i><?php echo htmlspecialchars($pol['category_name'] ?: ucfirst($pol['category_slug'])); ?>
                                </span>
                                <?php echo formatSeverityBadge($pol['severity']); ?>
                            </div>
                            <h5 class="fw-bold text-slate-900 mb-0" style="font-size: 0.95rem; line-height: 1.35;">
                                <?php echo htmlspecialchars($pol['title']); ?>
                            </h5>
                        </div>
                        <a href="incidents" class="btn btn-sm btn-outline-danger rounded-pill px-2.5 py-1 flex-shrink-0" style="font-size: 0.75rem;" title="Log an incident based on this rule">
                            <i class="fa-solid fa-plus me-1"></i> Violation
                        </a>
                    </div>

                    <!-- Card Body (Compact & Smart) -->
                    <div class="card-body p-3 flex-grow-1 d-flex flex-column justify-content-between gap-2">
                        <p class="text-slate-600 mb-1" style="font-size: 0.83rem; line-height: 1.5;"><?php echo nl2br(htmlspecialchars($pol['description'])); ?></p>

                        <!-- Offence & Penalty Summary Strip -->
                        <div class="policy-offence-summary rounded-3 p-2.5">
                            <div class="d-flex align-items-start justify-content-between gap-2 mb-1.5">
                                <div class="min-w-0">
                                    <span class="text-muted fw-bold text-uppercase d-block" style="font-size: 0.65rem; letter-spacing: 0.04em;">
                                        <i class="fa-solid fa-circle-exclamation text-amber-500 me-1"></i> Security Trigger
                                    </span>
                                    <div class="text-slate-700" style="font-size: 0.79rem; line-height: 1.4;">
                                        <?php echo nl2br(htmlspecialchars($pol['offence_definition'] ?: 'Any breach of the above stated guidelines.')); ?>
                                    </div>
                                </div>
                                <?php if ($pol['fine_amount'] > 0): ?>
                                    <div class="text-end flex-shrink-0">
                                        <span class="text-muted fw-bold text-uppercase d-block" style="font-size: 0.65rem;">Fine</span>
                                        <span class="fw-bold text-danger font-monospace" style="font-size: 0.88rem;">₦<?php echo number_format($pol['fine_amount'], 2); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex align-items-center gap-2 flex-wrap pt-1.5 border-top" style="font-size: 0.74rem;">
                                <span class="text-muted">Action:</span>
                                <?php echo formatPunishmentTypeBadge($pol['punishment_type']); ?>
                                <?php if (!empty($pol['repeat_offence_penalty'])): ?>
                                    <span class="text-muted ms-auto"><i class="fa-solid fa-arrow-trend-up me-1 text-amber-600"></i>Escalation: <strong class="text-slate-700"><?php echo htmlspecialchars($pol['repeat_offence_penalty']); ?></strong></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Card Footer -->
                    <div class="card-footer bg-light bg-opacity-40 p-2.5 px-3 border-top d-flex align-items-center justify-content-between text-muted" style="font-size: 0.72rem;">
                        <div>
                            <i class="fa-solid fa-shield me-1 text-slate-500"></i>
                            Tasked Unit: <strong class="text-slate-700"><?php echo htmlspecialchars($pol['enforcement_entity'] ?: 'Estate Security Patrol'); ?></strong>
                        </div>
                        <div class="text-muted font-monospace">
                            Ref #<?php echo $pol['id']; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<!-- ============================================================= -->
<!-- TAB 2: ZONAL BYLAWS                                           -->
<!-- ============================================================= -->
<?php elseif ($active_tab === 'zonal'): ?>
    <div class="alert alert-purple bg-opacity-10 border border-purple border-opacity-25 rounded-3 p-2.5 p-sm-3 mb-3" style="background: rgba(168, 85, 247, 0.08); border-color: rgba(168, 85, 247, 0.25);">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2.5 min-w-0" style="flex: 1 1 240px;">
                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 text-white" style="width: 32px; height: 32px; font-size: 0.85rem; background: #9333ea;">
                    <i class="fa-solid fa-layer-group"></i>
                </div>
                <div class="min-w-0">
                    <span class="fw-bold d-block text-truncate" style="font-size: 0.88rem; color: #7e22ce;">Sector-Specific Zonal Bylaws</span>
                    <span class="text-secondary small d-none d-md-inline" style="font-size: 0.78rem;">Sector marshals stationed in specific zones enforce these local bylaws.</span>
                </div>
            </div>
            <span class="badge px-2.5 py-1 rounded-pill font-monospace flex-shrink-0 ms-auto" style="font-size: 0.68rem; background: #9333ea; color: white; letter-spacing: 0.04em;">SECTOR SPECIFIC</span>
        </div>
    </div>

    <?php if (empty($zonal_policies)): ?>
        <div class="card border-0 shadow-sm rounded-4 p-5 text-center my-4 bg-white">
            <i class="fa-solid fa-layer-group fs-1 text-muted mb-2"></i>
            <h5 class="fw-bold text-slate-800">No Zonal Bylaws Found</h5>
            <p class="text-secondary small mb-0">No sector-specific bylaws match the current sector filter.</p>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($zonal_policies as $pol): ?>
                <div class="col-12 col-lg-6">
                    <div class="card policy-card border-0 shadow-sm rounded-4 h-100 overflow-hidden d-flex flex-column" style="border: 1px solid rgba(168, 85, 247, 0.25) !important;">
                        <!-- Card Header -->
                        <div class="card-header p-3 p-sm-3.5 pb-2 border-bottom d-flex align-items-start justify-content-between gap-2">
                            <div class="flex-grow-1 min-w-0">
                                <!-- Horizontal Badges Row (Spacious & Clean) -->
                                <div class="d-flex align-items-center gap-2 flex-wrap mb-2" style="row-gap: 6px; column-gap: 8px;">
                                    <span class="badge bg-light text-dark border font-monospace px-2.5 py-1" style="font-size: 0.72rem; font-weight: 700;"><?php echo htmlspecialchars($pol['code'] ?: 'ZN-' . $pol['id']); ?></span>
                                    <span class="badge badge-policy-scope px-2.5 py-1 rounded-pill" style="font-size: 0.7rem; font-weight: 600; color: #7e22ce; background: rgba(168, 85, 247, 0.1);">
                                        <i class="fa-solid fa-layer-group me-1"></i><?php echo htmlspecialchars($pol['zone_name'] ?: 'Zone ' . $pol['zone_id']); ?>
                                    </span>
                                    <span class="badge badge-policy-cat px-2.5 py-1 rounded-pill" style="font-size: 0.7rem; font-weight: 500;">
                                        <i class="fa-solid <?php echo htmlspecialchars($pol['category_icon'] ?: 'fa-gavel'); ?> me-1 text-secondary"></i><?php echo htmlspecialchars($pol['category_name'] ?: ucfirst($pol['category_slug'])); ?>
                                    </span>
                                    <?php echo formatSeverityBadge($pol['severity']); ?>
                                </div>
                                <h5 class="fw-bold text-slate-900 mb-0" style="font-size: 0.95rem; line-height: 1.35;">
                                    <?php echo htmlspecialchars($pol['title']); ?>
                                </h5>
                            </div>
                            <a href="incidents" class="btn btn-sm btn-outline-danger rounded-pill px-2.5 py-1 flex-shrink-0" style="font-size: 0.75rem;" title="Log an incident based on this bylaw">
                                <i class="fa-solid fa-plus me-1"></i> Violation
                            </a>
                        </div>

                        <!-- Card Body (Compact & Smart) -->
                        <div class="card-body p-3 flex-grow-1 d-flex flex-column justify-content-between gap-2">
                            <p class="text-slate-600 mb-1" style="font-size: 0.83rem; line-height: 1.5;"><?php echo nl2br(htmlspecialchars($pol['description'])); ?></p>

                            <!-- Offence & Penalty Summary Strip -->
                            <div class="policy-offence-summary rounded-3 p-2.5" style="border-left: 3px solid #7e22ce !important;">
                                <div class="d-flex align-items-start justify-content-between gap-2 mb-1.5">
                                    <div class="min-w-0">
                                        <span class="fw-bold text-uppercase d-block" style="font-size: 0.65rem; letter-spacing: 0.04em; color: #7e22ce;">
                                            <i class="fa-solid fa-triangle-exclamation text-amber-500 me-1"></i> Sector Breach
                                        </span>
                                        <div class="text-slate-700" style="font-size: 0.79rem; line-height: 1.4;">
                                            <?php echo nl2br(htmlspecialchars($pol['offence_definition'] ?: 'Any infraction of the above sector bylaw.')); ?>
                                        </div>
                                    </div>
                                    <?php if ($pol['fine_amount'] > 0): ?>
                                        <div class="text-end flex-shrink-0">
                                            <span class="text-muted fw-bold text-uppercase d-block" style="font-size: 0.65rem;">Surcharge</span>
                                            <span class="fw-bold text-slate-900 font-monospace" style="font-size: 0.88rem;">₦<?php echo number_format($pol['fine_amount'], 2); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex align-items-center gap-2 flex-wrap pt-1.5 border-top" style="font-size: 0.74rem;">
                                    <span class="text-muted">Sanction:</span>
                                    <?php echo formatPunishmentTypeBadge($pol['punishment_type']); ?>
                                    <?php if (!empty($pol['repeat_offence_penalty'])): ?>
                                        <span class="text-muted ms-auto"><i class="fa-solid fa-arrow-trend-up me-1 text-amber-600"></i>Escalation: <strong class="text-slate-700"><?php echo htmlspecialchars($pol['repeat_offence_penalty']); ?></strong></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Card Footer -->
                        <div class="card-footer bg-light bg-opacity-40 p-2.5 px-3 border-top d-flex align-items-center justify-content-between text-muted" style="font-size: 0.72rem;">
                            <div>
                                <i class="fa-solid fa-shield me-1" style="color: #9333ea;"></i>
                                Sector Unit: <strong class="text-slate-700"><?php echo htmlspecialchars($pol['enforcement_entity'] ?: 'Zone Sector Security'); ?></strong>
                            </div>
                            <div class="text-muted font-monospace">
                                Ref #<?php echo $pol['id']; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<!-- ============================================================= -->
<!-- TAB 3: QUICK ENFORCEMENT MATRIX                               -->
<!-- ============================================================= -->
<?php elseif ($active_tab === 'enforcement'): ?>
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4 bg-white">
        <div class="card-header bg-white py-3 px-4 border-bottom d-flex align-items-center justify-content-between">
            <div>
                <h5 class="fw-bold mb-0 text-slate-800"><i class="fa-solid fa-shield-halved text-primary me-2"></i>Security Occurrence Enforcement Matrix</h5>
                <small class="text-muted">Cross-reference table linking violations, fine tariffs, and reporting pathways</small>
            </div>
            <a href="incidents" class="btn btn-sm btn-danger"><i class="fa-solid fa-plus me-1"></i> New Incident Report</a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-uppercase text-secondary" style="font-size: 0.75rem; letter-spacing: 0.05em;">
                    <tr>
                        <th class="ps-4">Rule Code</th>
                        <th>Scope</th>
                        <th>Category</th>
                        <th>Violation Description</th>
                        <th>Sanction</th>
                        <th>Fine Amount (₦)</th>
                        <th>Officer Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $combined = array_merge($central_policies, $zonal_policies);
                    if (empty($combined)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">No rules match the current filters.</td>
                        </tr>
                    <?php else: 
                        foreach ($combined as $row): ?>
                        <tr>
                            <td class="ps-4">
                                <span class="badge font-monospace bg-light text-dark border px-2 py-1"><?php echo htmlspecialchars($row['code'] ?: 'REF-' . $row['id']); ?></span>
                            </td>
                            <td>
                                <?php if ($row['scope'] === 'central'): ?>
                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1 rounded-pill">Central</span>
                                <?php else: ?>
                                    <span class="badge px-2 py-1 rounded-pill" style="background: rgba(168, 85, 247, 0.12); color: #7e22ce; border: 1px solid rgba(168, 85, 247, 0.25);">
                                        <?php echo htmlspecialchars($row['zone_name'] ?: 'Zone ' . $row['zone_id']); ?>
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
                                    <span class="badge bg-light text-muted border">Warning</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="incidents" class="btn btn-xs btn-outline-danger rounded-pill px-2.5 py-1 text-xs" style="font-size: 0.75rem;">
                                    <i class="fa-solid fa-triangle-exclamation me-1"></i> Log in OB
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; 
                    endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php include 'footer.php'; ?>
