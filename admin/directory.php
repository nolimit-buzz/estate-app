<?php
// admin/directory.php - Central Estate Member & Contact Directory
require_once '../config.php';
require_once '../includes/auth_guard.php';

requireLogin();
if (!isAdminRole()) {
    header("Location: ../index");
    exit;
}

$estate_id = get_estate_id();

// Query all residents with household and address relations
$sql = "SELECT 
    r.id as resident_id,
    r.custom_id,
    r.flat_id,
    r.type,
    r.relationship,
    r.status,
    r.image_path,
    r.registration_date,
    u.id as user_id,
    u.first_name,
    u.last_name,
    u.name,
    u.email,
    u.phone,
    f.number as flat_number,
    f.floor,
    b.id as building_id,
    b.name as building_name,
    s.id as street_id,
    s.name as street_name,
    z.id as zone_id,
    z.name as zone_name
FROM residents r
JOIN users u ON r.user_id = u.id
LEFT JOIN flats f ON r.flat_id = f.id
LEFT JOIN buildings b ON f.building_id = b.id
LEFT JOIN streets s ON b.street_id = s.id
LEFT JOIN zones z ON s.zone_id = z.id
WHERE r.estate_id = $estate_id
ORDER BY s.name ASC, b.name ASC, f.number ASC, (CASE WHEN r.type = 'head' THEN 0 ELSE 1 END), r.id ASC";

$residents_res = $conn->query($sql);

$households = [];
$total_members = 0;
$total_heads = 0;
$total_dependents = 0;

if ($residents_res) {
    while ($row = $residents_res->fetch_assoc()) {
        $total_members++;
        $flat_key = $row['flat_id'] ? 'flat_' . $row['flat_id'] : 'solo_' . $row['resident_id'];
        
        if (!isset($households[$flat_key])) {
            $households[$flat_key] = [
                'primary' => null,
                'dependents' => [],
                'address' => [
                    'flat_number' => $row['flat_number'] ?? '',
                    'floor' => $row['floor'] ?? '',
                    'building_name' => $row['building_name'] ?? '',
                    'street_name' => $row['street_name'] ?? '',
                    'zone_name' => $row['zone_name'] ?? '',
                    'zone_id' => $row['zone_id'] ?? 0,
                    'street_id' => $row['street_id'] ?? 0
                ]
            ];
        }
        
        if ($row['type'] === 'head' || $households[$flat_key]['primary'] === null) {
            if ($households[$flat_key]['primary'] !== null) {
                if ($row['type'] === 'head' && $households[$flat_key]['primary']['type'] !== 'head') {
                    $households[$flat_key]['dependents'][] = $households[$flat_key]['primary'];
                    $households[$flat_key]['primary'] = $row;
                } else {
                    $households[$flat_key]['dependents'][] = $row;
                }
            } else {
                $households[$flat_key]['primary'] = $row;
            }
        } else {
            $households[$flat_key]['dependents'][] = $row;
        }
    }
}

foreach ($households as $h) {
    if (!empty($h['primary'])) $total_heads++;
    $total_dependents += count($h['dependents']);
}
$total_households = count($households);

// Fetch Zones & Streets for filters
$zones_list = $conn->query("SELECT id, name FROM zones WHERE estate_id = $estate_id ORDER BY name ASC");
$streets_list = $conn->query("SELECT id, name, zone_id FROM streets WHERE estate_id = $estate_id ORDER BY name ASC");

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<link rel="stylesheet" href="../css/directory.css">

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
    <div>
        <div class="header-breadcrumbs">
            <span>Community</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span>Residency</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span class="active">Estate Directory</span>
        </div>
        <h1 class="page-title m-0">Estate Member Directory</h1>
        <p class="page-subtitle mb-0">Master contact phonebook for primary residents, occupants, and affiliated family dependants.</p>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <button class="btn btn-sm btn-outline-secondary" onclick="exportDirectoryCSV()">
            <i class="fa-solid fa-file-export me-1"></i> Export Contacts
        </button>
        <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
            <i class="fa-solid fa-print me-1"></i> Print Directory
        </button>
        <button class="btn btn-sm btn-outline-primary" id="toggleAllBtn" onclick="toggleAllDependants()">
            <i class="fa-solid fa-people-roof me-1"></i> <span id="toggleAllText">Expand All Dependants</span>
        </button>
    </div>
</div>

<!-- ==========================================
     KPI SUMMARY STRIP
     ========================================== -->
<div class="directory-hero d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div class="d-flex align-items-center gap-3">
        <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(37, 99, 235, 0.12); color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 1.4rem;">
            <i class="fa-solid fa-address-book"></i>
        </div>
        <div>
            <h4 class="fw-bold text-slate-900 m-0" style="font-size: 1.15rem;">Central Occupant Directory</h4>
            <span class="text-secondary small">Estate-wide resident phonebook with nested family members.</span>
        </div>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <div class="directory-stat-badge">
            <i class="fa-solid fa-users text-primary"></i>
            <span><?php echo number_format($total_members); ?> Total Members</span>
        </div>
        <div class="directory-stat-badge">
            <i class="fa-solid fa-user-tie text-success"></i>
            <span><?php echo number_format($total_heads); ?> Primary Residents</span>
        </div>
        <div class="directory-stat-badge">
            <i class="fa-solid fa-people-roof text-info"></i>
            <span><?php echo number_format($total_dependents); ?> Dependants</span>
        </div>
        <div class="directory-stat-badge">
            <i class="fa-solid fa-building-user text-purple"></i>
            <span><?php echo number_format($total_households); ?> Units / Households</span>
        </div>
    </div>
</div>

<!-- ==========================================
     SEARCH & FILTER TOOLBAR
     ========================================== -->
<div class="directory-toolbar">
    <div class="d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center justify-content-between gap-3">
        <!-- Live Search Input -->
        <div class="search-input-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="dirSearch" placeholder="Search member name, phone, email, flat number, street..." onkeyup="filterDirectory()">
        </div>

        <!-- Filter Selects -->
        <div class="d-flex flex-wrap align-items-center gap-2">
            <!-- Zone Filter -->
            <select id="zoneFilter" class="filter-select" onchange="filterDirectory()">
                <option value="">All Zones / Sectors</option>
                <?php if ($zones_list): ?>
                    <?php while ($z = $zones_list->fetch_assoc()): ?>
                        <option value="<?php echo htmlspecialchars($z['name']); ?>"><?php echo htmlspecialchars($z['name']); ?></option>
                    <?php endwhile; ?>
                <?php endif; ?>
            </select>

            <!-- Street Filter -->
            <select id="streetFilter" class="filter-select" onchange="filterDirectory()">
                <option value="">All Streets</option>
                <?php if ($streets_list): ?>
                    <?php while ($st = $streets_list->fetch_assoc()): ?>
                        <option value="<?php echo htmlspecialchars($st['name']); ?>"><?php echo htmlspecialchars($st['name']); ?></option>
                    <?php endwhile; ?>
                <?php endif; ?>
            </select>

            <!-- Dependants Filter -->
            <select id="depFilter" class="filter-select" onchange="filterDirectory()">
                <option value="all">All Households</option>
                <option value="has_dep">With Dependants Only</option>
                <option value="solo">Single Occupant (No Dependants)</option>
            </select>

            <!-- View Mode Switcher -->
            <div class="view-mode-toggle">
                <button type="button" class="view-btn active" id="btnGridView" onclick="switchViewMode('grid')">
                    <i class="fa-solid fa-grip"></i> <span class="d-none d-sm-inline">Cards</span>
                </button>
                <button type="button" class="view-btn" id="btnTableView" onclick="switchViewMode('table')">
                    <i class="fa-solid fa-table-list"></i> <span class="d-none d-sm-inline">Table</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Results Indicator -->
<div class="d-flex justify-content-between align-items-center mb-3 text-secondary small px-1">
    <span>Showing <strong id="visibleCount" class="text-slate-900"><?php echo count($households); ?></strong> households</span>
</div>

<!-- ==========================================
     VIEW 1: CONTACT CARD GRID VIEW
     ========================================== -->
<div id="dirGridView" class="directory-grid">
    <?php foreach ($households as $f_key => $item): 
        $p = $item['primary'];
        if (!$p) continue;
        $deps = $item['dependents'];
        $dep_count = count($deps);
        $addr = $item['address'];

        $unit_str = !empty($addr['flat_number']) ? 'Unit ' . $addr['flat_number'] : 'Private Residence';
        $bldg_str = !empty($addr['building_name']) ? $addr['building_name'] : '';
        $street_str = !empty($addr['street_name']) ? $addr['street_name'] : '';
        $zone_str = !empty($addr['zone_name']) ? $addr['zone_name'] : '';
        $full_addr = implode(' • ', array_filter([$unit_str, $bldg_str, $street_str, $zone_str]));

        $initials = strtoupper(substr($p['first_name'] ?? $p['name'] ?? 'U', 0, 1) . substr($p['last_name'] ?? '', 0, 1));
        if (empty($initials)) $initials = 'PR';

        // Search haystack for client-side search
        $search_haystack = strtolower($p['name'] . ' ' . $p['phone'] . ' ' . $p['email'] . ' ' . $p['custom_id'] . ' ' . $full_addr);
        foreach ($deps as $d) {
            $search_haystack .= ' ' . strtolower($d['name'] . ' ' . ($d['phone'] ?? '') . ' ' . ($d['email'] ?? '') . ' ' . ($d['relationship'] ?? ''));
        }
    ?>
    <div class="dir-card" 
         data-search="<?php echo htmlspecialchars($search_haystack); ?>"
         data-zone="<?php echo htmlspecialchars($addr['zone_name'] ?? ''); ?>"
         data-street="<?php echo htmlspecialchars($addr['street_name'] ?? ''); ?>"
         data-has-dep="<?php echo $dep_count > 0 ? 'yes' : 'no'; ?>"
         id="card-<?php echo $f_key; ?>">
        
        <!-- Primary Resident Header -->
        <div class="dir-card-header">
            <?php if (!empty($p['image_path']) && (file_exists($p['image_path']) || file_exists('../' . ltrim($p['image_path'], './')))): 
                $img = file_exists($p['image_path']) ? $p['image_path'] : '../' . ltrim($p['image_path'], './');
            ?>
                <img src="<?php echo htmlspecialchars($img); ?>" alt="<?php echo htmlspecialchars($p['name']); ?>" class="dir-avatar">
            <?php else: ?>
                <div class="dir-avatar"><?php echo $initials; ?></div>
            <?php endif; ?>

            <div class="dir-info">
                <h3 class="dir-name" title="<?php echo htmlspecialchars($p['name']); ?>">
                    <?php echo htmlspecialchars($p['name']); ?>
                </h3>
                <div class="d-flex flex-wrap align-items-center gap-1">
                    <span class="dir-designation">
                        <i class="fa-solid fa-user-check"></i> <?php echo ucfirst($p['relationship'] ?? 'Primary Resident'); ?>
                    </span>
                    <?php if (!empty($p['custom_id'])): ?>
                        <span class="badge bg-slate-100 text-secondary" style="font-size: 0.72rem; font-family: monospace;"><?php echo htmlspecialchars($p['custom_id']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="dir-address-pill mt-1" title="<?php echo htmlspecialchars($full_addr); ?>">
                    <i class="fa-solid fa-location-dot"></i>
                    <span class="text-truncate"><?php echo htmlspecialchars($full_addr); ?></span>
                </div>
            </div>
        </div>

        <!-- Primary Resident Contact Rows -->
        <div class="dir-contact-body">
            <!-- Phone -->
            <div class="contact-row">
                <div class="contact-value-wrap">
                    <i class="fa-solid fa-phone"></i>
                    <?php if (!empty($p['phone'])): ?>
                        <a href="tel:<?php echo htmlspecialchars($p['phone']); ?>" class="contact-text"><?php echo htmlspecialchars($p['phone']); ?></a>
                    <?php else: ?>
                        <span class="text-muted small">No phone on record</span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($p['phone'])): 
                    $clean_phone = preg_replace('/[^0-9]/', '', $p['phone']);
                ?>
                    <div class="contact-actions">
                        <a href="tel:<?php echo htmlspecialchars($p['phone']); ?>" class="quick-action-btn call-btn" title="Call Resident">
                            <i class="fa-solid fa-phone"></i>
                        </a>
                        <a href="https://wa.me/<?php echo $clean_phone; ?>" target="_blank" class="quick-action-btn wa-btn" title="Message on WhatsApp">
                            <i class="fa-brands fa-whatsapp"></i>
                        </a>
                        <button type="button" class="quick-action-btn copy-btn" onclick="copyContact('<?php echo htmlspecialchars($p['phone']); ?>', 'Phone')" title="Copy Phone">
                            <i class="fa-regular fa-copy"></i>
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Email -->
            <div class="contact-row">
                <div class="contact-value-wrap">
                    <i class="fa-solid fa-envelope"></i>
                    <?php if (!empty($p['email'])): ?>
                        <a href="mailto:<?php echo htmlspecialchars($p['email']); ?>" class="contact-text"><?php echo htmlspecialchars($p['email']); ?></a>
                    <?php else: ?>
                        <span class="text-muted small">No email on record</span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($p['email'])): ?>
                    <div class="contact-actions">
                        <a href="mailto:<?php echo htmlspecialchars($p['email']); ?>" class="quick-action-btn mail-btn" title="Send Email">
                            <i class="fa-regular fa-envelope"></i>
                        </a>
                        <button type="button" class="quick-action-btn copy-btn" onclick="copyContact('<?php echo htmlspecialchars($p['email']); ?>', 'Email')" title="Copy Email">
                            <i class="fa-regular fa-copy"></i>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Expandable Dependants Section -->
        <div class="dir-dependants-wrapper">
            <?php if ($dep_count > 0): ?>
                <button type="button" class="dependants-toggle-bar" onclick="toggleDependants('<?php echo $f_key; ?>', this)">
                    <div class="d-flex align-items-center gap-2">
                        <span class="dep-count-badge">
                            <i class="fa-solid fa-people-roof"></i> Family Dependants (<?php echo $dep_count; ?>)
                        </span>
                        <span class="text-muted small" style="font-size: 0.75rem;">Click to view</span>
                    </div>
                    <i class="fa-solid fa-chevron-down dep-chevron"></i>
                </button>

                <div class="dependants-drawer" id="drawer-<?php echo $f_key; ?>">
                    <?php foreach ($deps as $d): 
                        $d_initials = strtoupper(substr($d['first_name'] ?? $d['name'] ?? 'D', 0, 1) . substr($d['last_name'] ?? '', 0, 1));
                        if (empty($d_initials)) $d_initials = 'DP';
                    ?>
                        <div class="dependant-item-card">
                            <div class="dependant-header">
                                <?php if (!empty($d['image_path']) && (file_exists($d['image_path']) || file_exists('../' . ltrim($d['image_path'], './')))): 
                                    $d_img = file_exists($d['image_path']) ? $d['image_path'] : '../' . ltrim($d['image_path'], './');
                                ?>
                                    <img src="<?php echo htmlspecialchars($d_img); ?>" alt="<?php echo htmlspecialchars($d['name']); ?>" class="dir-avatar-dependent">
                                <?php else: ?>
                                    <div class="dir-avatar-dependent"><?php echo $d_initials; ?></div>
                                <?php endif; ?>

                                <div class="dep-meta-details">
                                    <div class="dep-name"><?php echo htmlspecialchars($d['name']); ?></div>
                                    <span class="dep-rel-tag">
                                        <?php echo htmlspecialchars(ucfirst($d['relationship'] ?? 'Family Member')); ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Dependant Contacts -->
                            <?php if (!empty($d['phone'])): 
                                $d_clean_phone = preg_replace('/[^0-9]/', '', $d['phone']);
                            ?>
                                <div class="dep-contact-pill">
                                    <span class="text-truncate"><i class="fa-solid fa-phone text-secondary me-1"></i> <?php echo htmlspecialchars($d['phone']); ?></span>
                                    <div class="d-flex align-items-center gap-1">
                                        <a href="tel:<?php echo htmlspecialchars($d['phone']); ?>" class="quick-action-btn call-btn" style="width: 22px; height: 22px; font-size: 0.65rem;" title="Call">
                                            <i class="fa-solid fa-phone"></i>
                                        </a>
                                        <a href="https://wa.me/<?php echo $d_clean_phone; ?>" target="_blank" class="quick-action-btn wa-btn" style="width: 22px; height: 22px; font-size: 0.65rem;" title="WhatsApp">
                                            <i class="fa-brands fa-whatsapp"></i>
                                        </a>
                                        <button type="button" class="quick-action-btn copy-btn" style="width: 22px; height: 22px; font-size: 0.65rem;" onclick="copyContact('<?php echo htmlspecialchars($d['phone']); ?>', 'Phone')" title="Copy">
                                            <i class="fa-regular fa-copy"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($d['email']) && !str_contains($d['email'], '@estate.com')): ?>
                                <div class="dep-contact-pill">
                                    <span class="text-truncate"><i class="fa-solid fa-envelope text-secondary me-1"></i> <?php echo htmlspecialchars($d['email']); ?></span>
                                    <div class="d-flex align-items-center gap-1">
                                        <a href="mailto:<?php echo htmlspecialchars($d['email']); ?>" class="quick-action-btn mail-btn" style="width: 22px; height: 22px; font-size: 0.65rem;" title="Email">
                                            <i class="fa-regular fa-envelope"></i>
                                        </a>
                                        <button type="button" class="quick-action-btn copy-btn" style="width: 22px; height: 22px; font-size: 0.65rem;" onclick="copyContact('<?php echo htmlspecialchars($d['email']); ?>', 'Email')" title="Copy">
                                            <i class="fa-regular fa-copy"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="dependants-toggle-bar no-dependants">
                    <span class="text-muted small"><i class="fa-regular fa-circle-check me-1"></i> Single Occupant (No Dependants)</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ==========================================
     VIEW 2: DETAILED TABLE VIEW (WITH ACCORDION ROWS)
     ========================================== -->
<div id="dirTableView" class="directory-table-container" style="display: none;">
    <div class="table-responsive">
        <table class="dir-table">
            <thead>
                <tr>
                    <th style="width: 50px;"></th>
                    <th>Primary Resident</th>
                    <th>Residence Location</th>
                    <th>Primary Phone</th>
                    <th>Primary Email</th>
                    <th>Dependants</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($households as $f_key => $item): 
                    $p = $item['primary'];
                    if (!$p) continue;
                    $deps = $item['dependents'];
                    $dep_count = count($deps);
                    $addr = $item['address'];

                    $unit_str = !empty($addr['flat_number']) ? 'Unit ' . $addr['flat_number'] : 'N/A';
                    $loc_str = implode(', ', array_filter([$addr['building_name'] ?? '', $addr['street_name'] ?? '', $addr['zone_name'] ?? '']));

                    $initials = strtoupper(substr($p['first_name'] ?? $p['name'] ?? 'U', 0, 1) . substr($p['last_name'] ?? '', 0, 1));
                    if (empty($initials)) $initials = 'PR';

                    $search_haystack = strtolower($p['name'] . ' ' . $p['phone'] . ' ' . $p['email'] . ' ' . $unit_str . ' ' . $loc_str);
                    foreach ($deps as $d) {
                        $search_haystack .= ' ' . strtolower($d['name'] . ' ' . ($d['phone'] ?? '') . ' ' . ($d['email'] ?? ''));
                    }
                ?>
                <tr class="primary-row" 
                    data-search="<?php echo htmlspecialchars($search_haystack); ?>"
                    data-zone="<?php echo htmlspecialchars($addr['zone_name'] ?? ''); ?>"
                    data-street="<?php echo htmlspecialchars($addr['street_name'] ?? ''); ?>"
                    data-has-dep="<?php echo $dep_count > 0 ? 'yes' : 'no'; ?>"
                    id="trow-<?php echo $f_key; ?>">
                    
                    <td class="text-center">
                        <?php if ($dep_count > 0): ?>
                            <button type="button" class="btn btn-sm btn-light p-1" style="width: 28px; height: 28px; border-radius: 6px;" onclick="toggleTableNestedRow('<?php echo $f_key; ?>', this)">
                                <i class="fa-solid fa-chevron-down dep-chevron"></i>
                            </button>
                        <?php else: ?>
                            <span class="text-muted opacity-25">•</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="dir-avatar" style="width: 38px; height: 38px; font-size: 0.85rem; border-radius: 8px;">
                                <?php echo $initials; ?>
                            </div>
                            <div>
                                <div class="fw-bold text-slate-900"><?php echo htmlspecialchars($p['name']); ?></div>
                                <span class="badge bg-slate-100 text-secondary" style="font-size: 0.7rem;"><?php echo htmlspecialchars($p['custom_id'] ?? 'RES'); ?></span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="fw-semibold text-slate-800"><?php echo htmlspecialchars($unit_str); ?></div>
                        <small class="text-secondary"><?php echo htmlspecialchars($loc_str); ?></small>
                    </td>
                    <td>
                        <?php if (!empty($p['phone'])): ?>
                            <a href="tel:<?php echo htmlspecialchars($p['phone']); ?>" class="fw-semibold text-decoration-none text-slate-900">
                                <?php echo htmlspecialchars($p['phone']); ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted small">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($p['email'])): ?>
                            <a href="mailto:<?php echo htmlspecialchars($p['email']); ?>" class="text-decoration-none text-primary small">
                                <?php echo htmlspecialchars($p['email']); ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted small">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($dep_count > 0): ?>
                            <span class="dep-count-badge" style="cursor: pointer;" onclick="toggleTableNestedRow('<?php echo $f_key; ?>')">
                                <i class="fa-solid fa-people-roof"></i> <?php echo $dep_count; ?> Family Members
                            </span>
                        <?php else: ?>
                            <span class="badge bg-slate-100 text-muted">None</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-1">
                            <?php if (!empty($p['phone'])): 
                                $clean_p = preg_replace('/[^0-9]/', '', $p['phone']);
                            ?>
                                <a href="tel:<?php echo htmlspecialchars($p['phone']); ?>" class="quick-action-btn call-btn" title="Call">
                                    <i class="fa-solid fa-phone"></i>
                                </a>
                                <a href="https://wa.me/<?php echo $clean_p; ?>" target="_blank" class="quick-action-btn wa-btn" title="WhatsApp">
                                    <i class="fa-brands fa-whatsapp"></i>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($p['email'])): ?>
                                <a href="mailto:<?php echo htmlspecialchars($p['email']); ?>" class="quick-action-btn mail-btn" title="Email">
                                    <i class="fa-regular fa-envelope"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>

                <!-- Nested Table Row for Dependants -->
                <?php if ($dep_count > 0): ?>
                    <tr class="dependants-nested-row" id="tnested-<?php echo $f_key; ?>">
                        <td colspan="7" class="p-0">
                            <div class="nested-dependants-inner">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="fw-bold text-slate-800 small text-uppercase" style="letter-spacing: 0.04em;">
                                        <i class="fa-solid fa-people-roof text-primary me-1"></i> Family Dependants Registered to <?php echo htmlspecialchars($unit_str); ?> (<?php echo $dep_count; ?>)
                                    </span>
                                </div>
                                <table class="dep-subtable">
                                    <thead>
                                        <tr>
                                            <th>Dependant Name</th>
                                            <th>Relationship</th>
                                            <th>Phone Number</th>
                                            <th>Email Address</th>
                                            <th>Contact Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($deps as $d): ?>
                                            <tr>
                                                <td class="fw-semibold text-slate-900"><?php echo htmlspecialchars($d['name']); ?></td>
                                                <td><span class="dep-rel-tag"><?php echo htmlspecialchars(ucfirst($d['relationship'] ?? 'Dependent')); ?></span></td>
                                                <td><?php echo !empty($d['phone']) ? htmlspecialchars($d['phone']) : '<span class="text-muted">N/A</span>'; ?></td>
                                                <td><?php echo (!empty($d['email']) && !str_contains($d['email'], '@estate.com')) ? htmlspecialchars($d['email']) : '<span class="text-muted">N/A</span>'; ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-1">
                                                        <?php if (!empty($d['phone'])): 
                                                            $clean_dp = preg_replace('/[^0-9]/', '', $d['phone']);
                                                        ?>
                                                            <a href="tel:<?php echo htmlspecialchars($d['phone']); ?>" class="quick-action-btn call-btn" style="width:24px;height:24px;font-size:0.7rem;" title="Call">
                                                                <i class="fa-solid fa-phone"></i>
                                                            </a>
                                                            <a href="https://wa.me/<?php echo $clean_dp; ?>" target="_blank" class="quick-action-btn wa-btn" style="width:24px;height:24px;font-size:0.7rem;" title="WhatsApp">
                                                                <i class="fa-brands fa-whatsapp"></i>
                                                            </a>
                                                            <button type="button" class="quick-action-btn copy-btn" style="width:24px;height:24px;font-size:0.7rem;" onclick="copyContact('<?php echo htmlspecialchars($d['phone']); ?>', 'Phone')" title="Copy Phone">
                                                                <i class="fa-regular fa-copy"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Empty State -->
<div id="dirEmptyState" class="text-center py-5" style="display: none;">
    <div style="width: 64px; height: 64px; border-radius: 50%; background: rgba(0,0,0,0.04); display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; font-size: 1.5rem; color: #94a3b8;">
        <i class="fa-solid fa-user-slash"></i>
    </div>
    <h5 class="fw-bold text-slate-800 mb-1">No Matching Directory Contacts</h5>
    <p class="text-secondary small mb-3">No residents or dependants matched your current search filters.</p>
    <button class="btn btn-sm btn-outline-secondary" onclick="resetDirectoryFilters()">
        <i class="fa-solid fa-rotate-left me-1"></i> Reset Filters
    </button>
</div>

<!-- Copy Toast Notification -->
<div id="dirToast" class="dir-toast">
    <i class="fa-solid fa-circle-check text-success"></i>
    <span id="dirToastText">Copied to clipboard!</span>
</div>

<script>
// Filter Directory Logic
function filterDirectory() {
    const query = document.getElementById('dirSearch').value.toLowerCase().trim();
    const selectedZone = document.getElementById('zoneFilter').value;
    const selectedStreet = document.getElementById('streetFilter').value;
    const depFilter = document.getElementById('depFilter').value;

    const cards = document.querySelectorAll('.dir-card');
    const tableRows = document.querySelectorAll('.primary-row');

    let visibleCount = 0;

    // Filter Cards
    cards.forEach(card => {
        const searchData = card.dataset.search || '';
        const zone = card.dataset.zone || '';
        const street = card.dataset.street || '';
        const hasDep = card.dataset.hasDep || 'no';

        const matchesQuery = !query || searchData.includes(query);
        const matchesZone = !selectedZone || zone === selectedZone;
        const matchesStreet = !selectedStreet || street === selectedStreet;
        let matchesDep = true;
        if (depFilter === 'has_dep') matchesDep = (hasDep === 'yes');
        if (depFilter === 'solo') matchesDep = (hasDep === 'no');

        if (matchesQuery && matchesZone && matchesStreet && matchesDep) {
            card.style.display = 'flex';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });

    // Filter Table Rows
    tableRows.forEach(row => {
        const searchData = row.dataset.search || '';
        const zone = row.dataset.zone || '';
        const street = row.dataset.street || '';
        const hasDep = row.dataset.hasDep || 'no';
        const fKey = row.id.replace('trow-', '');
        const nestedRow = document.getElementById('tnested-' + fKey);

        const matchesQuery = !query || searchData.includes(query);
        const matchesZone = !selectedZone || zone === selectedZone;
        const matchesStreet = !selectedStreet || street === selectedStreet;
        let matchesDep = true;
        if (depFilter === 'has_dep') matchesDep = (hasDep === 'yes');
        if (depFilter === 'solo') matchesDep = (hasDep === 'no');

        if (matchesQuery && matchesZone && matchesStreet && matchesDep) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
            if (nestedRow) nestedRow.style.display = 'none';
        }
    });

    document.getElementById('visibleCount').textContent = visibleCount;
    document.getElementById('dirEmptyState').style.display = visibleCount === 0 ? 'block' : 'none';
}

function resetDirectoryFilters() {
    document.getElementById('dirSearch').value = '';
    document.getElementById('zoneFilter').value = '';
    document.getElementById('streetFilter').value = '';
    document.getElementById('depFilter').value = 'all';
    filterDirectory();
}

// Toggle Dependants in Card
function toggleDependants(fKey, btn) {
    const drawer = document.getElementById('drawer-' + fKey);
    if (!drawer) return;
    const chevron = btn ? btn.querySelector('.dep-chevron') : null;

    if (drawer.classList.contains('open')) {
        drawer.classList.remove('open');
        if (chevron) chevron.classList.remove('rotated');
    } else {
        drawer.classList.add('open');
        if (chevron) chevron.classList.add('rotated');
    }
}

// Toggle Dependants in Table
function toggleTableNestedRow(fKey, btn) {
    const nestedRow = document.getElementById('tnested-' + fKey);
    if (!nestedRow) return;
    const chevron = btn ? btn.querySelector('.dep-chevron') : document.querySelector('#trow-' + fKey + ' .dep-chevron');

    if (nestedRow.classList.contains('open')) {
        nestedRow.classList.remove('open');
        if (chevron) chevron.classList.remove('rotated');
    } else {
        nestedRow.classList.add('open');
        if (chevron) chevron.classList.add('rotated');
    }
}

// Toggle All Dependants
let allExpanded = false;
function toggleAllDependants() {
    allExpanded = !allExpanded;
    const drawers = document.querySelectorAll('.dependants-drawer');
    const chevrons = document.querySelectorAll('.dep-chevron');
    const nestedRows = document.querySelectorAll('.dependants-nested-row');

    drawers.forEach(d => {
        if (allExpanded) d.classList.add('open');
        else d.classList.remove('open');
    });

    nestedRows.forEach(nr => {
        if (allExpanded) nr.classList.add('open');
        else nr.classList.remove('open');
    });

    chevrons.forEach(ch => {
        if (allExpanded) ch.classList.add('rotated');
        else ch.classList.remove('rotated');
    });

    document.getElementById('toggleAllText').textContent = allExpanded ? 'Collapse All Dependants' : 'Expand All Dependants';
}

// Switch View Modes (Grid vs Table)
function switchViewMode(mode) {
    const grid = document.getElementById('dirGridView');
    const table = document.getElementById('dirTableView');
    const btnGrid = document.getElementById('btnGridView');
    const btnTable = document.getElementById('btnTableView');

    if (mode === 'grid') {
        grid.style.display = 'grid';
        table.style.display = 'none';
        btnGrid.classList.add('active');
        btnTable.classList.remove('active');
    } else {
        grid.style.display = 'none';
        table.style.display = 'block';
        btnTable.classList.add('active');
        btnGrid.classList.remove('active');
    }
}

// Copy Contact Helper
function copyContact(text, label) {
    EstateDialog.copy(text, label + ' copied to clipboard: ' + text);
}

// Export CSV
function exportDirectoryCSV() {
    const rows = [
        ["Household ID", "Unit Number", "Building", "Street", "Zone", "Primary Resident", "Primary Phone", "Primary Email", "Dependant Name", "Relationship", "Dependant Phone", "Dependant Email"]
    ];

    <?php foreach ($households as $f_key => $item): 
        $p = $item['primary'];
        if (!$p) continue;
        $addr = $item['address'];
        $deps = $item['dependents'];
        
        $flat_no = addslashes($addr['flat_number'] ?? '');
        $bldg = addslashes($addr['building_name'] ?? '');
        $street = addslashes($addr['street_name'] ?? '');
        $zone = addslashes($addr['zone_name'] ?? '');
        $p_name = addslashes($p['name'] ?? '');
        $p_phone = addslashes($p['phone'] ?? '');
        $p_email = addslashes($p['email'] ?? '');
        $f_id = addslashes($f_key);

        if (empty($deps)) {
            echo "rows.push(['$f_id', '$flat_no', '$bldg', '$street', '$zone', '$p_name', '$p_phone', '$p_email', 'None', '', '', '']);\n";
        } else {
            foreach ($deps as $d) {
                $d_name = addslashes($d['name'] ?? '');
                $d_rel = addslashes($d['relationship'] ?? '');
                $d_phone = addslashes($d['phone'] ?? '');
                $d_email = addslashes($d['email'] ?? '');
                echo "rows.push(['$f_id', '$flat_no', '$bldg', '$street', '$zone', '$p_name', '$p_phone', '$p_email', '$d_name', '$d_rel', '$d_phone', '$d_email']);\n";
            }
        }
    endforeach; ?>

    let csvContent = "data:text/csv;charset=utf-8," + rows.map(e => e.map(val => '"' + ('' + val).replace(/"/g, '""') + '"').join(",")).join("\n");
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "Estate_Directory_" + new Date().toISOString().slice(0, 10) + ".csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php include '../includes/footer.php'; ?>
