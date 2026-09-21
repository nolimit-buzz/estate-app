<?php
// resident/policies.php - Resident Rules, Regulations, Offences & Penalties Handbook
require_once '../config.php';
require_once '../includes/auth_guard.php';

requireLogin();

$user_id = intval($_SESSION['user_id']);
$estate_id = get_estate_id();
$user_name = $_SESSION['name'] ?? 'Resident';

// Fetch Resident Property & Zone Details
$res_query = "SELECT r.*, f.number as flat_number, f.floor, b.name as building_name, b.property_number, 
                     s.name as street_name, s.zone_id, z.name as zone_name, z.code as zone_code 
              FROM residents r 
              LEFT JOIN flats f ON r.flat_id = f.id 
              LEFT JOIN buildings b ON f.building_id = b.id 
              LEFT JOIN streets s ON b.street_id = s.id 
              LEFT JOIN zones z ON s.zone_id = z.id
              WHERE r.user_id = $user_id AND r.estate_id = $estate_id 
              ORDER BY r.id DESC LIMIT 1";
$resident_info = $conn->query($res_query)->fetch_assoc();
$resident_zone_id = intval($resident_info['zone_id'] ?? 1); // Fallback to 1 if unassigned
$resident_zone_name = $resident_info['zone_name'] ?? 'Main Sector';
$resident_zone_code = $resident_info['zone_code'] ?? 'ZONE';

$categories = getPolicyCategories($conn, $estate_id);

// Active Tab & Filters
$active_tab = $_GET['tab'] ?? 'central'; // 'central', 'zonal', 'fines'
$filter_category = $_GET['category'] ?? '';
$search = trim($_GET['search'] ?? '');

// Counts
$central_cnt = $conn->query("SELECT COUNT(id) as c FROM estate_policies WHERE estate_id = $estate_id AND scope = 'central' AND status = 'active'")->fetch_assoc()['c'] ?? 0;
$zonal_cnt = $conn->query("SELECT COUNT(id) as c FROM estate_policies WHERE estate_id = $estate_id AND scope = 'zonal' AND zone_id = $resident_zone_id AND status = 'active'")->fetch_assoc()['c'] ?? 0;
$total_fines_cnt = $conn->query("SELECT COUNT(id) as c FROM estate_policies WHERE estate_id = $estate_id AND fine_amount > 0 AND status = 'active' AND (scope = 'central' OR (scope = 'zonal' AND zone_id = $resident_zone_id))")->fetch_assoc()['c'] ?? 0;

// Fetch active policies for resident
$central_policies = getEstatePoliciesList($conn, $estate_id, [
    'scope' => 'central',
    'status' => 'active',
    'category_slug' => $filter_category,
    'search' => $search
]);

$zonal_policies = getEstatePoliciesList($conn, $estate_id, [
    'scope' => 'zonal',
    'zone_id' => $resident_zone_id,
    'status' => 'active',
    'category_slug' => $filter_category,
    'search' => $search
]);

include 'header.php';
include 'sidebar.php';
?>

<!-- Header / Welcome Banner -->
<div class="page-header-futuristic mb-4">
    <div>
        <div class="header-breadcrumbs">
            <a href="index">Dashboard</a>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span>Living Guidelines</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span class="active">Rules &amp; Penalties</span>
        </div>
        <h1 class="page-title d-flex align-items-center gap-2">
            <i class="fa-solid fa-book-bookmark text-primary"></i>
            <span>Estate Rules, Regulations &amp; Penalties</span>
        </h1>
        <p class="text-secondary small mb-0">Official resident handbook outlining estate-wide governing policies, your local sector bylaws, violation definitions, and fine schedules.</p>
    </div>
    <div class="header-actions d-flex align-items-center gap-2">
        <a href="report_issue" class="btn btn-outline-danger shadow-sm">
            <i class="fa-solid fa-triangle-exclamation me-1.5"></i> Report a Violation
        </a>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
            <i class="fa-solid fa-print me-1.5"></i> Print Rulebook
        </button>
    </div>
</div>

<!-- Resident Location Card -->
<div class="card border-0 shadow-sm rounded-4 p-3 mb-4" style="background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%); border-left: 4px solid #3b82f6 !important;">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <div style="width: 44px; height: 44px; border-radius: 12px; background: white; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; box-shadow: 0 2px 6px rgba(0,0,0,0.06);">
                <i class="fa-solid fa-house-chimney-user"></i>
            </div>
            <div>
                <span class="text-secondary small fw-semibold">Your Registered Residence &amp; Sector Jurisdiction:</span>
                <div class="fw-bold text-slate-800">
                    <?php if (!empty($resident_info['building_name'])): ?>
                        <?php echo htmlspecialchars($resident_info['building_name']); ?> • Unit <?php echo htmlspecialchars($resident_info['flat_number'] ?? 'N/A'); ?> (<?php echo htmlspecialchars($resident_info['street_name'] ?? ''); ?>)
                    <?php else: ?>
                        Resident Unit
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge py-2 px-3 rounded-pill" style="background: rgba(168, 85, 247, 0.15); color: #7e22ce; font-size: 0.8rem; border: 1px solid rgba(168, 85, 247, 0.3);">
                <i class="fa-solid fa-layer-group me-1.5"></i> Your Sector: <strong><?php echo htmlspecialchars($resident_zone_name); ?> (<?php echo htmlspecialchars($resident_zone_code); ?>)</strong>
            </span>
        </div>
    </div>
</div>

<!-- Tabs: Strict Split between Central Estate Rules and My Zone Bylaws -->
<div class="d-flex flex-wrap align-items-center justify-content-between border-bottom pb-2 mb-3 gap-2">
    <ul class="nav nav-pills gap-2" id="residentPolicyTabs">
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'central') ? 'active' : ''; ?> fw-semibold px-3 py-2 rounded-3" href="?tab=central">
                <i class="fa-solid fa-globe me-1.5"></i> Central Estate Rules (Estate-Wide)
                <span class="badge ms-1.5 <?php echo ($active_tab === 'central') ? 'bg-white text-primary' : 'bg-light text-dark'; ?>"><?php echo $central_cnt; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'zonal') ? 'active' : ''; ?> fw-semibold px-3 py-2 rounded-3" href="?tab=zonal" style="<?php echo ($active_tab === 'zonal') ? 'background: #9333ea; color: white;' : ''; ?>">
                <i class="fa-solid fa-layer-group me-1.5"></i> My Zone Bylaws (<?php echo htmlspecialchars($resident_zone_code); ?>)
                <span class="badge ms-1.5 <?php echo ($active_tab === 'zonal') ? 'bg-white text-purple' : 'bg-light text-dark'; ?>" style="<?php echo ($active_tab === 'zonal') ? 'color: #9333ea !important;' : ''; ?>"><?php echo $zonal_cnt; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo ($active_tab === 'fines') ? 'active' : ''; ?> fw-semibold px-3 py-2 rounded-3" href="?tab=fines">
                <i class="fa-solid fa-scale-balanced me-1.5"></i> Offences &amp; Fines Schedule
                <span class="badge ms-1.5 <?php echo ($active_tab === 'fines') ? 'bg-white text-primary' : 'bg-light text-dark'; ?>"><?php echo $total_fines_cnt; ?></span>
            </a>
        </li>
    </ul>

    <!-- Quick Search & Category Filter Form -->
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
            <input type="text" name="search" class="form-control" placeholder="Search offences, fines..." value="<?php echo htmlspecialchars($search); ?>">
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
<!-- TAB 1: CENTRAL ESTATE RULES (ESTATE-WIDE)                     -->
<!-- ============================================================= -->
<?php if ($active_tab === 'central'): ?>
    <div class="alert alert-primary bg-primary bg-opacity-10 border-primary border-opacity-25 rounded-4 p-3 mb-4 d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
            <div class="bg-primary text-white rounded-3 p-2 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                <i class="fa-solid fa-landmark fs-5"></i>
            </div>
            <div>
                <strong class="d-block text-primary">Central Estate Governing Regulations</strong>
                <span class="text-secondary small">These general rules apply universally to all residents, property owners, visitors, and commercial vendors residing in or visiting the estate.</span>
            </div>
        </div>
        <span class="badge bg-primary px-3 py-2 rounded-pill font-monospace" style="letter-spacing: 0.05em;">ESTATE-WIDE LAW</span>
    </div>

    <?php if (empty($central_policies)): ?>
        <div class="card border-0 shadow-sm rounded-4 p-5 text-center my-4">
            <i class="fa-solid fa-book-open fs-1 text-muted mb-2"></i>
            <h5 class="fw-bold text-slate-800">No Regulations Found</h5>
            <p class="text-secondary small mb-0">No central estate policies match the selected filter criteria.</p>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($central_policies as $pol): ?>
                <div class="col-12 col-lg-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden d-flex flex-column" style="border: 1px solid rgba(226, 232, 240, 0.8) !important;">
                        <div class="card-header bg-white p-3.5 border-bottom">
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
                                <span class="text-secondary small font-monospace"><?php echo htmlspecialchars($pol['code'] ?: 'EST-' . $pol['id']); ?>:</span>
                                <?php echo htmlspecialchars($pol['title']); ?>
                            </h5>
                        </div>

                        <div class="card-body p-3.5 flex-grow-1">
                            <div class="mb-3">
                                <div class="text-secondary small fw-bold text-uppercase mb-1" style="font-size: 0.72rem; letter-spacing: 0.04em;">Policy Description:</div>
                                <p class="text-slate-700 small mb-0" style="line-height: 1.55;"><?php echo nl2br(htmlspecialchars($pol['description'])); ?></p>
                            </div>

                            <div class="p-2.5 rounded-3 mb-3" style="background: #fff1f2; border-left: 3px solid #f43f5e;">
                                <div class="d-flex align-items-center gap-1.5 text-danger fw-bold small mb-1" style="font-size: 0.75rem;">
                                    <i class="fa-solid fa-triangle-exclamation"></i> What Constitutes an Offence:
                                </div>
                                <div class="text-slate-700 small" style="font-size: 0.8rem; line-height: 1.45;">
                                    <?php echo nl2br(htmlspecialchars($pol['offence_definition'] ?: 'Any infraction of the above rule.')); ?>
                                </div>
                            </div>

                            <div class="p-2.5 rounded-3 mb-2" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                                <div class="d-flex align-items-center justify-content-between mb-1.5 flex-wrap gap-1">
                                    <div class="d-flex align-items-center gap-1.5">
                                        <span class="text-secondary small fw-bold" style="font-size: 0.75rem;">Penalty:</span>
                                        <?php echo formatPunishmentTypeBadge($pol['punishment_type']); ?>
                                    </div>
                                    <?php if ($pol['fine_amount'] > 0): ?>
                                        <div class="text-end">
                                            <span class="text-secondary small" style="font-size: 0.72rem;">Administrative Fine:</span>
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
                                        <i class="fa-solid fa-arrow-trend-up me-1"></i> <strong>Repeat Infraction:</strong> <?php echo htmlspecialchars($pol['repeat_offence_penalty']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card-footer bg-light bg-opacity-50 p-2.5 px-3.5 border-top d-flex align-items-center justify-content-between text-secondary small" style="font-size: 0.72rem;">
                            <div>
                                <i class="fa-solid fa-shield me-1 text-primary"></i>
                                Enforcing Body: <strong><?php echo htmlspecialchars($pol['enforcement_entity'] ?: 'Estate Security Patrol'); ?></strong>
                            </div>
                            <div class="text-muted">
                                Clause Ref: #<?php echo $pol['id']; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<!-- ============================================================= -->
<!-- TAB 2: MY ZONE BYLAWS (SECTOR-SPECIFIC)                       -->
<!-- ============================================================= -->
<?php elseif ($active_tab === 'zonal'): ?>
    <div class="alert alert-purple bg-opacity-10 border border-purple border-opacity-25 rounded-4 p-3 mb-4 d-flex align-items-center justify-content-between" style="background: rgba(168, 85, 247, 0.08); border-color: rgba(168, 85, 247, 0.25);">
        <div class="d-flex align-items-center gap-3">
            <div class="text-white rounded-3 p-2 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; background: #9333ea;">
                <i class="fa-solid fa-layer-group fs-5"></i>
            </div>
            <div>
                <strong class="d-block" style="color: #7e22ce;">Sector Bylaws: Specific to <?php echo htmlspecialchars($resident_zone_name); ?></strong>
                <span class="text-secondary small">These local bylaws are enacted specifically for residents of your sector to govern inner roads, localized refuse schedules, and sector green areas.</span>
            </div>
        </div>
        <span class="badge px-3 py-2 rounded-pill font-monospace" style="background: #9333ea; color: white; letter-spacing: 0.05em;">YOUR SECTOR</span>
    </div>

    <?php if (empty($zonal_policies)): ?>
        <div class="card border-0 shadow-sm rounded-4 p-5 text-center my-4">
            <div style="width: 72px; height: 72px; border-radius: 50%; background: #faf5ff; color: #9333ea; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 1rem auto;">
                <i class="fa-solid fa-check-double"></i>
            </div>
            <h5 class="fw-bold text-slate-800">No Specific Zonal Bylaws Active</h5>
            <p class="text-secondary small mb-0">No sector-specific bylaws are currently registered for <strong><?php echo htmlspecialchars($resident_zone_name); ?></strong>. The general Central Estate Rules govern this area.</p>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($zonal_policies as $pol): ?>
                <div class="col-12 col-lg-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden d-flex flex-column" style="border: 1px solid rgba(168, 85, 247, 0.25) !important;">
                        <div class="card-header bg-white p-3.5 border-bottom">
                            <div class="d-flex align-items-center gap-2 flex-wrap mb-1.5">
                                <span class="badge px-2 py-0.5 rounded-pill font-monospace" style="background: rgba(168, 85, 247, 0.15); color: #7e22ce; border: 1px solid rgba(168, 85, 247, 0.3); font-size: 0.72rem;">
                                    <i class="fa-solid fa-layer-group me-1"></i> <?php echo htmlspecialchars($resident_zone_name); ?>
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

                        <div class="card-body p-3.5 flex-grow-1">
                            <div class="mb-3">
                                <div class="text-secondary small fw-bold text-uppercase mb-1" style="font-size: 0.72rem; letter-spacing: 0.04em;">Bylaw Clause:</div>
                                <p class="text-slate-700 small mb-0" style="line-height: 1.55;"><?php echo nl2br(htmlspecialchars($pol['description'])); ?></p>
                            </div>

                            <div class="p-2.5 rounded-3 mb-3" style="background: #fff1f2; border-left: 3px solid #f43f5e;">
                                <div class="d-flex align-items-center gap-1.5 text-danger fw-bold small mb-1" style="font-size: 0.75rem;">
                                    <i class="fa-solid fa-triangle-exclamation"></i> What Constitutes an Offence:
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
                                        <strong>Details:</strong> <?php echo htmlspecialchars($pol['punishment_details']); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($pol['repeat_offence_penalty'])): ?>
                                    <div class="text-danger small mt-1" style="font-size: 0.75rem;">
                                        <i class="fa-solid fa-arrow-trend-up me-1"></i> <strong>Repeat Infraction:</strong> <?php echo htmlspecialchars($pol['repeat_offence_penalty']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card-footer bg-light bg-opacity-50 p-2.5 px-3.5 border-top d-flex align-items-center justify-content-between text-secondary small" style="font-size: 0.72rem;">
                            <div>
                                <i class="fa-solid fa-shield me-1" style="color: #9333ea;"></i>
                                Enforcing Body: <strong><?php echo htmlspecialchars($pol['enforcement_entity'] ?: $resident_zone_name . ' Sector Security'); ?></strong>
                            </div>
                            <div class="text-muted">
                                Clause Ref: #<?php echo $pol['id']; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<!-- ============================================================= -->
<!-- TAB 3: OFFENCES & FINES SCHEDULE (QUICK MATRIX)               -->
<!-- ============================================================= -->
<?php elseif ($active_tab === 'fines'): ?>
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
        <div class="card-header bg-white py-3 px-4 border-bottom d-flex align-items-center justify-content-between">
            <div>
                <h5 class="fw-bold mb-0 text-slate-800"><i class="fa-solid fa-scale-balanced text-primary me-2"></i>Resident Offences &amp; Penalty Tariff</h5>
                <small class="text-muted">Quick reference guide of violations, enforcement consequences, and monetary fines</small>
            </div>
            <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print Schedule</button>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light text-uppercase text-secondary" style="font-size: 0.75rem; letter-spacing: 0.05em;">
                    <tr>
                        <th class="ps-4">Rule Code</th>
                        <th>Applicability</th>
                        <th>Category</th>
                        <th>Offence / Violation</th>
                        <th>Sanction</th>
                        <th>Fine Amount (₦)</th>
                        <th>Repeat Violation Surcharge</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $combined_resident = array_merge($central_policies, $zonal_policies);
                    if (empty($combined_resident)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">No rules match search criteria.</td>
                        </tr>
                    <?php else: 
                        foreach ($combined_resident as $row): ?>
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
                                        <i class="fa-solid fa-layer-group me-1"></i> My Sector (<?php echo htmlspecialchars($resident_zone_code); ?>)
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
                                <div class="text-secondary small" style="max-width: 300px;"><?php echo htmlspecialchars($row['offence_definition'] ?: $row['description']); ?></div>
                            </td>
                            <td>
                                <?php echo formatPunishmentTypeBadge($row['punishment_type']); ?>
                            </td>
                            <td>
                                <?php if ($row['fine_amount'] > 0): ?>
                                    <span class="fw-bold text-danger font-monospace fs-6">₦<?php echo number_format($row['fine_amount'], 2); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border">No Fine / Warning</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-secondary" style="max-width: 200px;">
                                <?php echo htmlspecialchars($row['repeat_offence_penalty'] ?: 'Compounding warning'); ?>
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
