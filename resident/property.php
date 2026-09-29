<?php
// resident/property.php
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index");
    exit;
}

$user_id = intval($_SESSION['user_id']);
$estate_id = get_estate_id();

// Fetch Resident Record
$res_query = "SELECT r.*, f.id as flat_id, f.number as flat_number, f.floor, f.type as flat_type, f.status as flat_status,
                     b.id as building_id, b.name as building_name, b.property_number, b.type as building_type,
                     s.name as street_name 
              FROM residents r 
              LEFT JOIN flats f ON r.flat_id = f.id 
              LEFT JOIN buildings b ON f.building_id = b.id 
              LEFT JOIN streets s ON b.street_id = s.id 
              WHERE r.user_id = $user_id AND r.estate_id = $estate_id 
              ORDER BY r.id DESC LIMIT 1";
$resident = $conn->query($res_query)->fetch_assoc();
$flat_id = $resident['flat_id'] ?? 0;
$building_id = $resident['building_id'] ?? 0;

// Fetch Active Tenancy
$tenancy = $conn->query("SELECT * FROM tenancies WHERE resident_id = $user_id AND estate_id = $estate_id AND status = 'Active' ORDER BY id DESC LIMIT 1")->fetch_assoc();

// Fetch Landlord / Property Owner
$owner = null;
if ($flat_id || $building_id) {
    $owner_query = "SELECT po.* 
                    FROM owner_properties op 
                    JOIN property_owners po ON op.owner_id = po.id 
                    WHERE (op.property_id = $flat_id AND op.property_type = 'flat') 
                       OR (op.property_id = $building_id AND op.property_type = 'building') 
                    LIMIT 1";
    $owner = $conn->query($owner_query)->fetch_assoc();
}

// Household Occupants
$occupants_res = $conn->query("SELECT r.*, u.name, u.phone, u.email 
                               FROM residents r 
                               JOIN users u ON r.user_id = u.id 
                               WHERE r.flat_id = $flat_id AND r.status = 'active'");

// Vehicles
$vehicles_res = $conn->query("SELECT * FROM vehicles WHERE flat_id = $flat_id");

// Household Staff
// Building Units List for Image 1 Screen 3 Chips
$units_list = [];
if ($building_id) {
    $bu_q = $conn->query("SELECT f.number as flat_no, f.status, f.type FROM flats f WHERE f.building_id = $building_id ORDER BY f.number ASC LIMIT 8");
    if ($bu_q) while($u = $bu_q->fetch_assoc()) $units_list[] = $u;
}

// Property Financial & Ticket Metrics for Screen 3
$prop_fin = $conn->query("SELECT 
    COALESCE(SUM(balance), 0) as rent_due,
    COALESCE(SUM(amount_paid), 0) as rent_collected
    FROM invoices WHERE user_id = $user_id AND estate_id = $estate_id")->fetch_assoc();
$open_req_cnt = $conn->query("SELECT COUNT(id) as cnt FROM maintenance_requests WHERE user_id = $user_id AND status IN ('open','in_progress')")->fetch_assoc()['cnt'] ?? 0;

include 'header.php';
include 'sidebar.php';
?>

<!-- ==========================================
     NATIVE MOBILE PROPERTY VIEW (Image 1 Screen 3)
     ========================================== -->
<div class="mobile-only mb-4">
    <!-- 1. Hero Property Image Banner -->
    <div class="mobile-property-hero-wrap">
        <img src="../images/hero_estate.jpg" alt="<?= htmlspecialchars($resident['building_name'] ?? 'Residence') ?>" class="mobile-property-hero-img">
        <div class="mobile-property-hero-gradient">
            <div class="mobile-hero-badge">
                <i class="fa-solid fa-building me-1"></i> <?= htmlspecialchars($resident['building_type'] ?? 'Luxury Residential') ?>
            </div>
            <h2 class="mobile-hero-title"><?= htmlspecialchars($resident['building_name'] ?? 'Estate Residence') ?></h2>
        </div>
    </div>

    <!-- 2. Address Location Badge -->
    <div class="mobile-address-badge">
        <i class="fa-solid fa-location-dot"></i>
        <span><?= htmlspecialchars(($resident['street_name'] ?? 'Main Street') . ' &bull; Unit ' . ($resident['flat_number'] ?? '101')) ?></span>
    </div>

    <!-- 3. Summary 2x2 Grid with Monthly Dropdown (Image 1 Screen 3) -->
    <div class="mobile-section-header">
        <h3 class="mobile-section-title">Summary</h3>
        <div class="mobile-pill-dropdown">
            <span>Monthly</span>
            <i class="fa-solid fa-chevron-down" style="font-size: 0.65rem;"></i>
        </div>
    </div>
    <div class="mobile-kpi-grid">
        <div class="mobile-kpi-card kpi-tint-orange">
            <div class="mobile-kpi-val">₦<?= number_format(floatval($prop_fin['rent_collected'] ?? 0) / 1000, 1) ?>k</div>
            <span class="mobile-kpi-label mt-1">Financial History</span>
        </div>
        <div class="mobile-kpi-card kpi-tint-blue">
            <div class="mobile-kpi-val">₦<?= number_format(floatval($prop_fin['rent_due'] ?? 0) / 1000, 1) ?>k</div>
            <span class="mobile-kpi-label mt-1">Outstanding Dues</span>
        </div>
        <div class="mobile-kpi-card kpi-tint-emerald">
            <div class="mobile-kpi-val"><?= ($tenancy['status'] ?? 'Active') === 'Active' ? '100%' : '0%' ?></div>
            <span class="mobile-kpi-label mt-1">Occupied</span>
        </div>
        <div class="mobile-kpi-card kpi-tint-amber">
            <div class="mobile-kpi-val"><?= str_pad($open_req_cnt, 2, '0', STR_PAD_LEFT) ?></div>
            <span class="mobile-kpi-label mt-1">Open Requests</span>
        </div>
    </div>

    <!-- 4. List of Units / Horizontal Scrolling Status Chips -->
    <div class="mobile-section-header mt-4">
        <h3 class="mobile-section-title">List of Units</h3>
        <span class="mature-badge mature-badge-slate"><?= count($units_list) > 0 ? count($units_list) . ' Units' : '1 Unit' ?></span>
    </div>
    <div class="mobile-unit-chip-container">
        <?php if (!empty($units_list)): ?>
            <?php foreach ($units_list as $u): ?>
                <div class="mobile-unit-chip <?= ($u['flat_no'] == ($resident['flat_number'] ?? '')) ? 'border-primary' : '' ?>">
                    <span class="mobile-unit-chip-num">Unit <?= htmlspecialchars($u['flat_no']) ?></span>
                    <span class="mobile-unit-chip-status" style="color: <?= ($u['status'] === 'occupied') ? '#059669' : '#d97706' ?>;">
                        <?= htmlspecialchars(ucfirst($u['status'])) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="mobile-unit-chip border-primary">
                <span class="mobile-unit-chip-num">Unit <?= htmlspecialchars($resident['flat_number'] ?? '101') ?></span>
                <span class="mobile-unit-chip-status">Occupied</span>
            </div>
            <div class="mobile-unit-chip">
                <span class="mobile-unit-chip-num">Unit 102</span>
                <span class="mobile-unit-chip-status">Occupied</span>
            </div>
            <div class="mobile-unit-chip">
                <span class="mobile-unit-chip-num">Unit 103</span>
                <span class="mobile-unit-chip-status" style="color: #d97706;">Vacant</span>
            </div>
        <?php endif; ?>
    </div>

    <!-- 5. Registered Household Occupants -->
    <div class="mobile-section-header mt-4">
        <h3 class="mobile-section-title">Household Members</h3>
        <span class="mature-badge mature-badge-emerald"><?= $occupants_res ? $occupants_res->num_rows : 0 ?> Active</span>
    </div>
    <div class="mobile-activity-list">
        <?php 
        if ($occupants_res && $occupants_res->num_rows > 0): 
            $occupants_res->data_seek(0);
            while($occ = $occupants_res->fetch_assoc()):
        ?>
            <div class="mobile-activity-item">
                <div class="mobile-activity-left">
                    <div class="mobile-activity-icon" style="background: rgba(59, 130, 246, 0.12); color: #2563eb;">
                        <i class="fa-solid fa-user"></i>
                    </div>
                    <div class="mobile-activity-text">
                        <h5 class="mobile-activity-title"><?= htmlspecialchars($occ['name']) ?></h5>
                        <div class="mobile-activity-meta">
                            <span><?= htmlspecialchars(ucfirst($occ['relationship'] ?? 'Resident')) ?></span> &bull; 
                            <span><?= htmlspecialchars($occ['phone'] ?? 'Verified') ?></span>
                        </div>
                    </div>
                </div>
                <span class="mature-badge mature-badge-emerald py-1 px-2" style="font-size: 0.68rem;">Active</span>
            </div>
        <?php 
            endwhile; 
        endif; 
        ?>
    </div>
</div>

<div class="desktop-only">
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h1 class="h4 font-bold text-slate-900 m-0" style="letter-spacing: -0.02em;">
                <i class="fa-solid fa-building-user text-primary me-2"></i> Property & Tenancy Profile
            </h1>
            <span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-circle-check me-1"></i>Active Tenancy</span>
        </div>
        <p class="text-secondary small mb-0">Detailed flat architectural specifications, landlord contacts, registered household members, and vehicles.</p>
    </div>
</div>

<div class="d-flex flex-column gap-4">
    <!-- ==========================================
         TOP ROW: PROPERTY & TENANCY CARDS (2 COLS)
         ========================================== -->
    <div class="row g-4">
        <!-- Property Details Card -->
        <div class="col-12 col-lg-6">
            <div class="resident-glass-panel h-100">
                <div class="resident-card-header">
                    <div class="resident-card-title">
                        <i class="fa-solid fa-building text-primary"></i> Architectural Unit Details
                    </div>
                    <span class="mature-badge mature-badge-sky">
                        <?= htmlspecialchars($resident['flat_type'] ?? 'Standard Unit') ?>
                    </span>
                </div>
                <div class="resident-card-body">
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex justify-content-between align-items-center pb-2 border-bottom border-light-subtle">
                            <span class="text-secondary small fw-medium">Street / Sector Location</span>
                            <span class="fw-semibold text-slate-900"><?= htmlspecialchars($resident['street_name'] ?? 'Main Street') ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center pb-2 border-bottom border-light-subtle">
                            <span class="text-secondary small fw-medium">Building Name & Number</span>
                            <span class="fw-semibold text-slate-900"><?= htmlspecialchars(($resident['building_name'] ?? 'Building') . ' (' . ($resident['property_number'] ?? 'N/A') . ')') ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center pb-2 border-bottom border-light-subtle">
                            <span class="text-secondary small fw-medium">Flat / Unit Number</span>
                            <span class="fw-bold text-primary font-monospace fs-6">Unit <?= htmlspecialchars($resident['flat_number'] ?? 'N/A') ?> (Floor <?= htmlspecialchars($resident['floor'] ?? '1') ?>)</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center pb-2 border-bottom border-light-subtle">
                            <span class="text-secondary small fw-medium">Unit Typology</span>
                            <span class="mature-badge mature-badge-slate"><?= htmlspecialchars($resident['flat_type'] ?? '2BHK') ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-secondary small fw-medium">Occupancy Verification</span>
                            <span class="mature-badge mature-badge-emerald">
                                <i class="fa-solid fa-circle-check me-1"></i> Occupied (<?= htmlspecialchars(ucfirst($tenancy['status'] ?? 'Active')) ?>)
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tenancy & Owner Details Card -->
        <div class="col-12 col-lg-6">
            <div class="resident-glass-panel h-100">
                <div class="resident-card-header">
                    <div class="resident-card-title">
                        <i class="fa-solid fa-key text-primary"></i> Tenancy & Ownership Contract
                    </div>
                    <span class="mature-badge mature-badge-purple">
                        <?= htmlspecialchars(strtoupper($resident['relationship'] ?? 'TENANT')) ?>
                    </span>
                </div>
                <div class="resident-card-body">
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex justify-content-between align-items-center pb-2 border-bottom border-light-subtle">
                            <span class="text-secondary small fw-medium">Resident Identity Role</span>
                            <span class="fw-semibold text-primary"><?= htmlspecialchars(ucfirst($resident['relationship'] ?? 'Head / Tenant')) ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center pb-2 border-bottom border-light-subtle">
                            <span class="text-secondary small fw-medium">Lease Move-In Date</span>
                            <span class="fw-semibold text-slate-900"><?= !empty($tenancy['move_in_date']) ? date('M j, Y', strtotime($tenancy['move_in_date'])) : (!empty($resident['lease_start']) ? date('M j, Y', strtotime($resident['lease_start'])) : 'N/A') ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center pb-2 border-bottom border-light-subtle">
                            <span class="text-secondary small fw-medium">Expected Lease Expiration</span>
                            <span class="fw-semibold text-slate-900"><?= !empty($tenancy['expected_move_out_date']) ? date('M j, Y', strtotime($tenancy['expected_move_out_date'])) : (!empty($resident['lease_end']) ? date('M j, Y', strtotime($resident['lease_end'])) : 'Ongoing') ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center pb-2 border-bottom border-light-subtle">
                            <span class="text-secondary small fw-medium">Property Landlord / Owner</span>
                            <span class="fw-semibold text-slate-900"><?= htmlspecialchars($owner['full_name'] ?? 'Estate Direct / Private Owner') ?></span>
                        </div>
                        <?php if ($owner && !empty($owner['phone'])): ?>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-secondary small fw-medium">Landlord Phone</span>
                                <a href="tel:<?= htmlspecialchars($owner['phone']) ?>" class="text-decoration-none fw-semibold font-monospace small">
                                    <i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($owner['phone']) ?>
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-secondary small fw-medium">Management Handling</span>
                                <span class="mature-badge mature-badge-slate">Estate Central Office</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==========================================
         BOTTOM ROW: CO-RESIDENTS & VEHICLES (2 COLS)
         ========================================== -->
    <div class="row g-4">
        <!-- Household Members -->
        <div class="col-12 col-lg-6">
            <div class="resident-glass-panel h-100">
                <div class="resident-card-header">
                    <div class="resident-card-title">
                        <i class="fa-solid fa-users text-primary"></i> Co-Residents & Family Dependents
                    </div>
                    <span class="mature-badge mature-badge-slate"><?= ($occupants_res) ? $occupants_res->num_rows : 0 ?> Registered</span>
                </div>
                <div class="table-responsive">
                    <table class="table dashboard-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Relationship</th>
                                <th>Contact</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($occupants_res && $occupants_res->num_rows > 0): ?>
                                <?php while ($occ = $occupants_res->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-slate-900"><?= htmlspecialchars($occ['name']) ?></div>
                                        </td>
                                        <td>
                                            <span class="mature-badge mature-badge-slate">
                                                <?= htmlspecialchars(ucfirst($occ['relationship'])) ?>
                                            </span>
                                        </td>
                                        <td class="small text-secondary font-monospace"><?= htmlspecialchars($occ['phone'] ?? 'N/A') ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="3" class="text-center py-4 text-secondary small">No co-residents registered for this flat.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Vehicles & Domestic Staff -->
        <div class="col-12 col-lg-6">
            <div class="resident-glass-panel h-100">
                <div class="resident-card-header">
                    <div class="resident-card-title">
                        <i class="fa-solid fa-car text-primary"></i> Registered Vehicles & Domestic Staff
                    </div>
                </div>
                <div class="resident-card-body">
                    <div class="small fw-bold text-uppercase text-secondary mb-2" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                        <i class="fa-solid fa-car me-1 text-primary"></i> Registered Vehicles
                    </div>
                    <?php if ($vehicles_res && $vehicles_res->num_rows > 0): ?>
                        <div class="d-flex flex-column gap-2 mb-4">
                            <?php while ($v = $vehicles_res->fetch_assoc()): ?>
                                <div class="p-2 px-3 rounded-3 d-flex justify-content-between align-items-center border" style="background: rgba(59, 130, 246, 0.04); border-color: rgba(59, 130, 246, 0.15) !important;">
                                    <div>
                                        <span class="fw-bold font-monospace text-primary me-2"><?= htmlspecialchars($v['reg_number']) ?></span>
                                        <span class="small text-secondary"><?= htmlspecialchars($v['model'] ?? 'Vehicle') ?> (<?= htmlspecialchars(ucfirst($v['type'])) ?>)</span>
                                    </div>
                                    <div class="d-flex align-items-center gap-1">
                                        <?php if (!empty($v['particulars_path'])): ?>
                                            <a href="<?= htmlspecialchars($v['particulars_path']) ?>" target="_blank" class="mature-badge mature-badge-primary text-decoration-none">
                                                <i class="fa-solid fa-file-lines me-1"></i> Particulars
                                            </a>
                                        <?php endif; ?>
                                        <a href="../car_sticker.php?id=<?= $v['id'] ?>" target="_blank" class="mature-badge text-decoration-none" style="background: rgba(245, 158, 11, 0.12); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.3);">
                                            <i class="fa-solid fa-id-card me-1"></i> Car Sticker
                                        </a>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <p class="small text-secondary mb-4">No registered vehicles on record for this unit.</p>
                    <?php endif; ?>

                    <div class="small fw-bold text-uppercase text-secondary mb-2" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                        <i class="fa-solid fa-user-shield me-1 text-success"></i> Domestic Staff
                    </div>
                    <?php if ($domestic_staff_res && $domestic_staff_res->num_rows > 0): ?>
                        <div class="d-flex flex-column gap-2">
                            <?php while ($st = $domestic_staff_res->fetch_assoc()): ?>
                                <div class="p-2 px-3 rounded-3 d-flex justify-content-between align-items-center border" style="background: rgba(16, 185, 129, 0.04); border-color: rgba(16, 185, 129, 0.15) !important;">
                                    <span class="fw-semibold text-slate-900"><?= htmlspecialchars($st['name']) ?></span>
                                    <span class="small text-secondary"><?= htmlspecialchars($st['role']) ?> &bull; <span class="font-monospace"><?= htmlspecialchars($st['phone'] ?? 'N/A') ?></span></span>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <p class="small text-secondary m-0">No domestic staff registered for this unit.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</div> <!-- End .desktop-only -->

<?php include 'footer.php'; ?>
