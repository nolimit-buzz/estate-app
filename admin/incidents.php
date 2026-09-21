<?php
// admin/incidents.php
// Enterprise Security Occurrence Logbook, Incident Forensics & Bad Behavior Flagging System

require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/emergency_roster_init.php';
requireAdminAccess();

$estate_id = get_estate_id();
$user_id = $_SESSION['user_id'];

// Fetch Active Residents for Quick Selection
$residents_res = $conn->query("
    SELECT u.id, u.name, u.phone, f.number as flat_number, b.name as building_name, s.name as street_name, z.name as zone_name
    FROM users u
    LEFT JOIN residents r ON u.id = r.user_id AND r.estate_id = $estate_id
    LEFT JOIN flats f ON r.flat_id = f.id
    LEFT JOIN buildings b ON f.building_id = b.id
    LEFT JOIN streets s ON b.street_id = s.id
    LEFT JOIN zones z ON (s.zone_id = z.id OR u.zone_id = z.id)
    WHERE u.estate_id = $estate_id AND u.role = 'resident'
    GROUP BY u.id
    ORDER BY u.name ASC
");
$residents_list = [];
if ($residents_res) {
    while ($r = $residents_res->fetch_assoc()) {
        $residents_list[] = $r;
    }
}

// Fetch Active Security Posts / Gates
$posts_res = $conn->query("SELECT * FROM security_posts WHERE estate_id = $estate_id AND status = 'active' ORDER BY post_name ASC");
$posts_list = [];
if ($posts_res) {
    while ($p = $posts_res->fetch_assoc()) {
        $posts_list[] = $p;
    }
}

// Fetch Staff & Investigators
$staff_res = $conn->query("SELECT id, name, role FROM users WHERE estate_id = $estate_id AND role IN ('admin', 'manager', 'security', 'staff') ORDER BY name ASC");
$investigators_list = [];
if ($staff_res) {
    while ($st = $staff_res->fetch_assoc()) {
        $investigators_list[] = $st;
    }
}

// Fetch Dynamic Configurable Lookups
$incident_types = getIncidentTypes($conn);
$incident_severities = getIncidentSeverities($conn);
$incident_statuses = getIncidentStatuses($conn);
$incident_entity_types = getIncidentEntityTypes($conn);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
/* Incident Console Aesthetic */
.incident-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 0.85rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    transition: all 0.2s ease;
}
.incident-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
.mature-badge-critical {
    background: rgba(220, 38, 38, 0.12);
    color: #dc2626;
    border: 1px solid rgba(220, 38, 38, 0.25);
}
.mature-badge-high {
    background: rgba(234, 88, 12, 0.12);
    color: #ea580c;
    border: 1px solid rgba(234, 88, 12, 0.25);
}
.mature-badge-medium {
    background: rgba(217, 119, 6, 0.12);
    color: #d97706;
    border: 1px solid rgba(217, 119, 6, 0.25);
}
.mature-badge-low {
    background: rgba(14, 165, 233, 0.12);
    color: #0284c7;
    border: 1px solid rgba(14, 165, 233, 0.25);
}
.flag-chip {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    border: 1px solid #f87171;
    color: #991b1b;
    border-radius: 50rem;
    font-size: 0.72rem;
    padding: 0.15rem 0.65rem;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-weight: 700;
}
</style>

<div class="d-flex flex-column gap-3">

    <!-- ==========================================
         PAGE HEADER & QUICK ACTIONS
         ========================================== -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 pb-2 border-bottom border-light-subtle">
        <div>
            <h1 class="h5 font-bold text-slate-900 m-0" style="letter-spacing: -0.02em;">
                <i class="fa-solid fa-book-skull text-danger me-2"></i> Security Occurrence Logbook &amp; Incident Forensics
            </h1>
            <p class="text-secondary small mb-0">Occurrence book (OB), resident/visitor bad behavior tracking, strange event reporting, and investigation forensics.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-sm btn-danger px-3 py-1 fw-bold shadow-sm rounded-pill" onclick="openLogIncidentModal()">
                <i class="fa-solid fa-triangle-exclamation me-1"></i> Log Security Incident
            </button>
            <button type="button" class="btn btn-sm btn-outline-success px-3 py-1 rounded-pill" onclick="exportIncidentsCSV()">
                <i class="fa-solid fa-file-csv me-1"></i> Export Logbook CSV
            </button>
            <a href="emergency" class="btn btn-sm btn-outline-secondary px-3 py-1 rounded-pill">
                <i class="fa-solid fa-truck-medical me-1"></i> Emergency Panic Hub
            </a>
            <a href="roster" class="btn btn-sm btn-outline-primary px-3 py-1 rounded-pill">
                <i class="fa-solid fa-calendar-check me-1"></i> Duty Roster
            </a>
        </div>
    </div>

    <!-- ==========================================
         KPI EXECUTIVE SUMMARY CARDS
         ========================================== -->
    <div class="row g-3">
        <div class="col-6 col-md-4 col-lg-2">
            <div class="p-3 rounded-4 bg-white border border-light-subtle shadow-sm d-flex align-items-center gap-3">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: #f1f5f9; color: #475569; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                    <i class="fa-solid fa-list-check"></i>
                </div>
                <div>
                    <small class="text-secondary d-block font-semibold" style="font-size: 0.7rem;">TOTAL LOGGED</small>
                    <h5 class="fw-bold mb-0 text-slate-900" id="kpi-total">0</h5>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="p-3 rounded-4 bg-white border border-light-subtle shadow-sm d-flex align-items-center gap-3">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                    <i class="fa-solid fa-flag"></i>
                </div>
                <div>
                    <small class="text-secondary d-block font-semibold" style="font-size: 0.7rem;">FLAGGED BEHAVIOR</small>
                    <h5 class="fw-bold mb-0 text-danger" id="kpi-flagged">0</h5>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="p-3 rounded-4 bg-white border border-light-subtle shadow-sm d-flex align-items-center gap-3">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: #ffedd5; color: #ea580c; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                    <i class="fa-solid fa-folder-open"></i>
                </div>
                <div>
                    <small class="text-secondary d-block font-semibold" style="font-size: 0.7rem;">OPEN CASES</small>
                    <h5 class="fw-bold mb-0 text-warning" id="kpi-open">0</h5>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="p-3 rounded-4 bg-white border border-light-subtle shadow-sm d-flex align-items-center gap-3">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                    <i class="fa-solid fa-magnifying-glass-chart"></i>
                </div>
                <div>
                    <small class="text-secondary d-block font-semibold" style="font-size: 0.7rem;">INVESTIGATING</small>
                    <h5 class="fw-bold mb-0 text-info" id="kpi-investigating">0</h5>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-8 col-lg-3">
            <div class="p-3 rounded-4 bg-white border border-light-subtle shadow-sm d-flex align-items-center gap-3">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: #dcfce7; color: #166534; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <small class="text-secondary d-block font-semibold" style="font-size: 0.7rem;">RESOLVED / CLOSED</small>
                    <h5 class="fw-bold mb-0 text-success" id="kpi-resolved">0</h5>
                </div>
            </div>
        </div>
    </div>

    <!-- ==========================================
         NAVIGATION TABS
         ========================================== -->
    <ul class="nav nav-pills gap-2" id="incidentTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active rounded-pill px-4 py-1 fw-bold" id="logbook-tab" data-bs-toggle="pill" data-bs-target="#logbook-tab-pane" type="button" role="tab" style="font-size: 0.82rem;">
                <i class="fa-solid fa-book-open me-1"></i> Occurrence Logbook (OB) Feed
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill px-4 py-1 fw-bold" id="watchlist-tab" data-bs-toggle="pill" data-bs-target="#watchlist-tab-pane" type="button" role="tab" onclick="loadFlaggedWatchlist()" style="font-size: 0.82rem;">
                <i class="fa-solid fa-user-shield me-1 text-danger"></i> Flagged Bad Behavior &amp; Blacklist Watchlist
            </button>
        </li>
    </ul>

    <!-- ==========================================
         TAB CONTENT
         ========================================== -->
    <div class="tab-content" id="incidentTabsContent">
        
        <!-- TAB 1: OCCURRENCE LOGBOOK -->
        <div class="tab-pane fade show active" id="logbook-tab-pane" role="tabpanel">
            
            <!-- Filters Toolbar -->
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white mb-3">
                <div class="row g-2 align-items-center">
                    <div class="col-12 col-md-3">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-magnifying-glass text-secondary"></i></span>
                            <input type="text" id="filter_search" placeholder="Search ref, resident, visitor, plate..." class="form-control border-start-0" onkeyup="debounceLoadIncidents()">
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <select id="filter_type" class="form-select form-select-sm" onchange="loadIncidents()">
                            <option value="">All Incident Types</option>
                            <?php foreach ($incident_types as $it): ?>
                                <option value="<?php echo htmlspecialchars($it['slug']); ?>"><?php echo htmlspecialchars($it['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select id="filter_severity" class="form-select form-select-sm" onchange="loadIncidents()">
                            <option value="">All Severities</option>
                            <?php foreach ($incident_severities as $is): ?>
                                <option value="<?php echo htmlspecialchars($is['slug']); ?>"><?php echo htmlspecialchars($is['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select id="filter_status" class="form-select form-select-sm" onchange="loadIncidents()">
                            <option value="">All Statuses</option>
                            <?php foreach ($incident_statuses as $ist): ?>
                                <option value="<?php echo htmlspecialchars($ist['slug']); ?>"><?php echo htmlspecialchars($ist['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="form-check form-switch pt-1">
                            <input class="form-check-input" type="checkbox" id="filter_flagged_only" onchange="loadIncidents()">
                            <label class="form-check-label small fw-semibold text-danger" for="filter_flagged_only">Flagged Only</label>
                        </div>
                    </div>
                    <div class="col-12 col-md-1 text-end">
                        <button type="button" class="btn btn-sm btn-light border rounded-circle" onclick="resetIncidentFilters()" title="Reset Filters" style="width: 32px; height: 32px;">
                            <i class="fa-solid fa-rotate-left text-secondary"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Incidents Table -->
            <div class="card border-0 shadow-sm rounded-4 bg-white overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.83rem;">
                        <thead class="table-light small text-secondary text-uppercase" style="letter-spacing: 0.04em;">
                            <tr>
                                <th>Incident Ref &bull; Date</th>
                                <th>Severity &bull; Type</th>
                                <th>Target / Entity Involved</th>
                                <th>Location / Post</th>
                                <th>Reported By</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="incidents-tbody">
                            <tr><td colspan="7" class="text-center py-4 text-muted"><i class="fa-solid fa-spinner fa-spin me-2"></i> Loading security occurrences...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- TAB 2: FLAGGED WATCHLIST & BLACKLIST -->
        <div class="tab-pane fade" id="watchlist-tab-pane" role="tabpanel">
            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
                <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                    <div>
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="fa-solid fa-user-xmark text-danger me-2"></i> Flagged Misconduct &amp; Blacklist Watchlist
                        </h6>
                        <small class="text-secondary">Summary of residents, visitors, and vehicles flagged for recurring security violations, noise, damage, or unruly conduct.</small>
                    </div>
                    <button type="button" onclick="loadFlaggedWatchlist()" class="btn btn-xs btn-outline-secondary rounded-pill px-3">
                        <i class="fa-solid fa-rotate me-1"></i> Refresh Watchlist
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                        <thead class="table-light small text-secondary text-uppercase">
                            <tr>
                                <th>Entity / Name</th>
                                <th>Type</th>
                                <th>Unit / Address / Plate</th>
                                <th>Violation Count</th>
                                <th>Last Occurrence</th>
                                <th>Summary Reasons</th>
                                <th>Blacklist Recommended</th>
                            </tr>
                        </thead>
                        <tbody id="watchlist-tbody">
                            <tr><td colspan="7" class="text-center py-4 text-muted">Loading flagged watchlist...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

</div>

<!-- ==============================================================
     MODAL: LOG / REGISTER SECURITY INCIDENT
     ============================================================== -->
<div class="modal fade" id="logIncidentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form id="logIncidentForm" onsubmit="handleSaveIncident(event)" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden" enctype="multipart/form-data">
            <input type="hidden" name="incident_id" id="inc_form_id" value="0">
            <div class="modal-header px-4 py-3 bg-danger text-white border-0">
                <h5 class="modal-title fw-bold" id="inc_modal_title" style="font-family: 'Outfit', sans-serif;">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i> Log Security Incident &amp; Occurrence
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" style="max-height: 75vh; overflow-y: auto;">
                
                <div class="row g-3 mb-3">
                    <div class="col-md-8">
                        <label class="form-label small fw-bold">Incident Title / Summary *</label>
                        <input type="text" name="title" id="inc_title" required placeholder="e.g. Unruly resident confrontation with gate guard" class="form-control rounded-3">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Incident Date &amp; Time *</label>
                        <input type="datetime-local" name="incident_datetime" id="inc_datetime" required value="<?php echo date('Y-m-d\TH:i'); ?>" class="form-control rounded-3">
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Incident Category *</label>
                        <select name="incident_type" id="inc_type" required class="form-select rounded-3">
                            <?php foreach ($incident_types as $it): ?>
                                <option value="<?php echo htmlspecialchars($it['slug']); ?>"><?php echo htmlspecialchars($it['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Severity Level *</label>
                        <select name="severity" id="inc_severity" required class="form-select rounded-3">
                            <?php foreach ($incident_severities as $is): ?>
                                <option value="<?php echo htmlspecialchars($is['slug']); ?>" <?php echo ($is['slug'] === 'medium') ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($is['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Post / Gate / Location *</label>
                        <input type="text" name="location_or_post" id="inc_location" list="posts_datalist" required placeholder="e.g. Main Entrance Gate" class="form-control rounded-3">
                        <datalist id="posts_datalist">
                            <?php foreach ($posts_list as $p): ?>
                                <option value="<?php echo htmlspecialchars($p['post_name']); ?>"></option>
                            <?php endforeach; ?>
                            <option value="Clubhouse &amp; Pool Area"></option>
                            <option value="Block A Perimeter Fence"></option>
                            <option value="North Pedestrian Gate"></option>
                            <option value="Visitor Parking Deck"></option>
                        </datalist>
                    </div>
                </div>

                <!-- Entity Involved Section -->
                <div class="p-3 rounded-3 bg-light border mb-3">
                    <h6 class="fw-bold mb-2 text-dark small text-uppercase" style="letter-spacing: 0.05em;">
                        <i class="fa-solid fa-users me-1 text-primary"></i> Entity / Person Involved
                    </h6>
                    <div class="row g-2 mb-2">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Entity Type</label>
                            <select name="entity_type" id="inc_entity_type" class="form-select rounded-3" onchange="handleEntityTypeChange()">
                                <?php foreach ($incident_entity_types as $iet): ?>
                                    <option value="<?php echo htmlspecialchars($iet['slug']); ?>"><?php echo htmlspecialchars($iet['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8" id="resident-picker-box">
                            <label class="form-label small fw-bold">Select Resident</label>
                            <select name="target_resident_id" id="inc_resident_id" class="form-select rounded-3" onchange="handleResidentSelect(this)">
                                <option value="">-- Choose Resident (Or type details below) --</option>
                                <?php foreach ($residents_list as $res): ?>
                                    <?php 
                                    $unit_parts = [];
                                    if (!empty($res['building_name'])) $unit_parts[] = $res['building_name'];
                                    if (!empty($res['flat_number'])) $unit_parts[] = 'Flat ' . $res['flat_number'];
                                    if (!empty($res['street_name'])) $unit_parts[] = $res['street_name'];
                                    if (!empty($res['zone_name'])) $unit_parts[] = $res['zone_name'];
                                    $unit_display = !empty($unit_parts) ? implode(' - ', $unit_parts) : 'Resident';
                                    ?>
                                    <option value="<?php echo $res['id']; ?>" data-name="<?php echo htmlspecialchars($res['name']); ?>" data-unit="<?php echo htmlspecialchars($unit_display); ?>">
                                        <?php echo htmlspecialchars($res['name']); ?> (<?php echo htmlspecialchars($unit_display); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Target Person / Visitor Name</label>
                            <input type="text" name="target_visitor_name" id="inc_target_name" placeholder="Name if visitor or unknown" class="form-control rounded-3">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Unit / Address</label>
                            <input type="text" name="target_unit_or_address" id="inc_target_unit" placeholder="e.g. Villa 14 / Block B Apt 3" class="form-control rounded-3">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Vehicle Plate / Registration</label>
                            <input type="text" name="vehicle_reg_plate" id="inc_vehicle_plate" placeholder="e.g. ABC-123-XY" class="form-control text-uppercase rounded-3">
                        </div>
                    </div>
                </div>

                <!-- Flag Bad Behavior Checkbox Card -->
                <div class="p-3 rounded-3 mb-3" style="background: #fff1f2; border: 1px solid #fecdd3;">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="is_flagged_bad_behavior" id="inc_is_flagged" value="1" onchange="toggleFlagReason(this.checked)">
                        <label class="form-check-label fw-bold text-danger" for="inc_is_flagged">
                            <i class="fa-solid fa-flag me-1"></i> Flag as Bad Behavior / Security Violation
                        </label>
                    </div>
                    <div id="flag-reason-container" style="display: none;">
                        <label class="form-label small fw-bold text-danger">Reason for Flagging / Violation Summary *</label>
                        <input type="text" name="flag_reason" id="inc_flag_reason" placeholder="e.g. Repeated verbal assault on security officers at main gate" class="form-control rounded-3 mb-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="blacklist_recommended" id="inc_blacklist_rec" value="1">
                            <label class="form-check-label small fw-semibold text-dark" for="inc_blacklist_rec">
                                Recommend for Gate Pass Blacklist / Restricted Access
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Description & Action Taken -->
                <div class="mb-3">
                    <label class="form-label small fw-bold">Detailed Incident Description &amp; Observation *</label>
                    <textarea name="description" id="inc_description" rows="3" required placeholder="Describe what occurred, eyewitness statements, actions observed..." class="form-control rounded-3"></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Immediate Action Taken</label>
                    <textarea name="immediate_action_taken" id="inc_action_taken" rows="2" placeholder="e.g. Officer issued warning, vehicle turned back, reported to supervisor..." class="form-control rounded-3"></textarea>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold">Upload Photographic Evidence / Document (Optional)</label>
                    <input type="file" name="evidence_image" id="inc_evidence_image" accept="image/*,.pdf" class="form-control rounded-3">
                </div>

            </div>
            <div class="modal-footer px-4 py-3 bg-light border-0">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold" id="btn-save-incident">
                    <i class="fa-solid fa-check me-1"></i> Register Occurrence
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================
     MODAL: INVESTIGATE & RESOLVE INCIDENT
     ============================================================== -->
<div class="modal fade" id="investigateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form id="investigateForm" onsubmit="handleInvestigateSubmit(event)" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <input type="hidden" id="inv_modal_incident_id" value="0">
            <div class="modal-header px-4 py-3 bg-light border-0">
                <h5 class="modal-title fw-bold text-dark" style="font-family: 'Outfit', sans-serif;">
                    <i class="fa-solid fa-magnifying-glass-chart text-info me-2"></i> Update Investigation &amp; Case Status
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="small text-secondary mb-3">Incident: <strong id="inv_modal_ref" class="text-primary"></strong> &bull; <span id="inv_modal_title_txt" class="fw-semibold"></span></p>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Case Status *</label>
                    <select id="inv_status" class="form-select rounded-3">
                        <?php foreach ($incident_statuses as $ist): ?>
                            <option value="<?php echo htmlspecialchars($ist['slug']); ?>"><?php echo htmlspecialchars($ist['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Assign Investigator / Supervisor</label>
                    <select id="inv_investigator_id" class="form-select rounded-3">
                        <?php foreach ($investigators_list as $inv): ?>
                            <option value="<?php echo $inv['id']; ?>" <?php echo ($inv['id'] == $user_id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($inv['name']); ?> (<?php echo htmlspecialchars($inv['role']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold">Investigation &amp; Resolution Remarks</label>
                    <textarea id="inv_resolution_notes" rows="3" class="form-control rounded-3" placeholder="Document investigation findings, actions taken, penalty issued, or resolution details..."></textarea>
                </div>
            </div>
            <div class="modal-footer px-4 py-3 bg-light border-0">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold" id="btn-submit-investigation">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Save Status
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ==============================================================
// INCIDENT LOGBOOK JAVASCRIPT ENGINE
// ==============================================================

let currentLoadedIncidents = [];
let debounceTimer = null;

document.addEventListener('DOMContentLoaded', () => {
    loadIncidents();
});

function debounceLoadIncidents() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(loadIncidents, 300);
}

function resetIncidentFilters() {
    document.getElementById('filter_search').value = '';
    document.getElementById('filter_type').value = '';
    document.getElementById('filter_severity').value = '';
    document.getElementById('filter_status').value = '';
    document.getElementById('filter_flagged_only').checked = false;
    loadIncidents();
}

function loadIncidents() {
    const search = document.getElementById('filter_search') ? document.getElementById('filter_search').value : '';
    const type = document.getElementById('filter_type') ? document.getElementById('filter_type').value : '';
    const sev = document.getElementById('filter_severity') ? document.getElementById('filter_severity').value : '';
    const status = document.getElementById('filter_status') ? document.getElementById('filter_status').value : '';
    const flagged = (document.getElementById('filter_flagged_only') && document.getElementById('filter_flagged_only').checked) ? '1' : '';

    const url = `../api/incident_query.php?action=fetch_incidents&search=${encodeURIComponent(search)}&incident_type=${type}&severity=${sev}&status=${status}&flagged_only=${flagged}`;

    fetch(url)
        .then(r => r.json())
        .then(res => {
            const tbody = document.getElementById('incidents-tbody');
            if (res.kpis) {
                document.getElementById('kpi-total').innerText = res.kpis.total || 0;
                document.getElementById('kpi-flagged').innerText = res.kpis.flagged || 0;
                document.getElementById('kpi-open').innerText = res.kpis.open || 0;
                document.getElementById('kpi-investigating').innerText = res.kpis.investigating || 0;
                document.getElementById('kpi-resolved').innerText = res.kpis.resolved || 0;
            }

            if (!tbody) return;

            if (res.success && res.incidents && res.incidents.length > 0) {
                currentLoadedIncidents = res.incidents;
                let html = '';
                res.incidents.forEach(inc => {
                    let sevBadge = 'mature-badge-medium';
                    if (inc.severity === 'critical') sevBadge = 'mature-badge-critical';
                    else if (inc.severity === 'high') sevBadge = 'mature-badge-high';
                    else if (inc.severity === 'low') sevBadge = 'mature-badge-low';

                    let stBadge = 'bg-secondary';
                    if (inc.status === 'open') stBadge = 'bg-warning text-dark';
                    else if (inc.status === 'investigating') stBadge = 'bg-info text-dark';
                    else if (inc.status === 'resolved') stBadge = 'bg-success';
                    else if (inc.status === 'escalated_police') stBadge = 'bg-danger';

                    const flagPill = (inc.is_flagged_bad_behavior == 1) ? `
                        <div class="mt-1">
                            <span class="flag-chip"><i class="fa-solid fa-flag"></i> FLAGGED: ${escapeHtml(inc.flag_reason || 'Misconduct')}</span>
                        </div>
                    ` : '';

                    const evidenceBtn = inc.evidence_image_path ? `
                        <a href="../${inc.evidence_image_path}" target="_blank" class="badge bg-light text-primary border text-decoration-none py-1 px-2 me-1" title="View photographic evidence">
                            <i class="fa-solid fa-paperclip me-1"></i> Evidence
                        </a>
                    ` : '';

                    const targetName = inc.target_resident_name || inc.target_visitor_name || 'Unspecified';
                    const targetSub = inc.target_unit_or_address || (inc.vehicle_reg_plate ? `Plate: ${inc.vehicle_reg_plate}` : inc.entity_type);

                    html += `
                        <tr>
                            <td>
                                <strong class="d-block text-primary">${escapeHtml(inc.incident_ref)}</strong>
                                <small class="text-secondary">${inc.formatted_datetime}</small>
                            </td>
                            <td>
                                <span class="badge ${sevBadge} rounded-pill text-uppercase px-2 mb-1" style="font-size: 0.68rem;">${inc.severity}</span>
                                <strong class="d-block text-dark" style="font-size: 0.85rem;">${escapeHtml(inc.title)}</strong>
                                <small class="text-muted text-capitalize">${inc.incident_type.replace(/_/g, ' ')}</small>
                                ${flagPill}
                            </td>
                            <td>
                                <strong class="d-block text-dark">${escapeHtml(targetName)}</strong>
                                <small class="text-secondary">${escapeHtml(targetSub)}</small>
                            </td>
                            <td>
                                <strong class="text-dark">${escapeHtml(inc.location_or_post)}</strong>
                            </td>
                            <td>
                                <strong class="d-block text-dark">${escapeHtml(inc.reporter_name || 'Security')}</strong>
                                <small class="text-muted">${escapeHtml(inc.reporter_role || 'Staff')}</small>
                            </td>
                            <td>
                                <span class="badge ${stBadge} rounded-pill text-uppercase px-2" style="font-size: 0.7rem;">
                                    ${inc.status.replace(/_/g, ' ')}
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="d-flex align-items-center justify-content-end gap-1">
                                    ${evidenceBtn}
                                    <button type="button" class="btn btn-xs btn-outline-info rounded-pill px-2 py-1" onclick="openInvestigateModal(${escapeJsonForAttr(inc)})">
                                        <i class="fa-solid fa-magnifying-glass me-1"></i> Status
                                    </button>
                                    <button type="button" class="btn btn-xs btn-outline-danger rounded-circle p-0 d-flex align-items-center justify-content-center" onclick="deleteIncident(${inc.id}, '${escapeHtml(inc.incident_ref)}')" style="width: 24px; height: 24px;" title="Delete">
                                        <i class="fa-solid fa-trash" style="font-size: 0.65rem;"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    `;
                });
                tbody.innerHTML = html;
            } else {
                tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-muted">No security incident records match the filter criteria.</td></tr>`;
            }
        })
        .catch(err => console.error(err));
}

function loadFlaggedWatchlist() {
    fetch('../api/incident_query.php?action=flagged_entities')
        .then(r => r.json())
        .then(res => {
            const tbody = document.getElementById('watchlist-tbody');
            if (!tbody) return;

            if (res.success && res.entities && res.entities.length > 0) {
                let html = '';
                res.entities.forEach(e => {
                    const isBlRec = (e.is_blacklist_rec == 1) ? '<span class="badge bg-danger rounded-pill px-2">YES - BLACKLIST</span>' : '<span class="badge bg-light text-secondary border">Monitoring</span>';
                    html += `
                        <tr>
                            <td><strong class="text-danger">${escapeHtml(e.entity_name)}</strong></td>
                            <td><span class="badge bg-light text-dark border text-capitalize">${e.entity_type.replace(/_/g, ' ')}</span></td>
                            <td>${escapeHtml(e.target_unit_or_address || e.vehicle_reg_plate || '—')}</td>
                            <td><span class="badge bg-danger bg-opacity-10 text-danger fw-bold fs-6 px-3 py-1">${e.incident_count} Violation(s)</span></td>
                            <td><small class="text-secondary">${e.formatted_last_date}</small></td>
                            <td><small class="text-dark">${escapeHtml(e.all_reasons || 'Misconduct reported')}</small></td>
                            <td>${isBlRec}</td>
                        </tr>
                    `;
                });
                tbody.innerHTML = html;
            } else {
                tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-muted">No residents or visitors currently on the flagged watchlist.</td></tr>`;
            }
        });
}

function openLogIncidentModal() {
    document.getElementById('logIncidentForm').reset();
    document.getElementById('inc_form_id').value = 0;
    document.getElementById('inc_modal_title').innerHTML = `<i class="fa-solid fa-triangle-exclamation me-2"></i> Log Security Incident &amp; Occurrence`;
    toggleFlagReason(false);
    const modal = new bootstrap.Modal(document.getElementById('logIncidentModal'));
    modal.show();
}

function handleEntityTypeChange() {
    const val = document.getElementById('inc_entity_type').value;
    const resBox = document.getElementById('resident-picker-box');
    if (resBox) {
        resBox.style.display = (val === 'resident') ? 'block' : 'none';
    }
}

function handleResidentSelect(selectElem) {
    const selectedOption = selectElem.options[selectElem.selectedIndex];
    if (selectedOption && selectedOption.value) {
        document.getElementById('inc_target_name').value = selectedOption.getAttribute('data-name') || '';
        document.getElementById('inc_target_unit').value = selectedOption.getAttribute('data-unit') || '';
    }
}

function toggleFlagReason(isChecked) {
    const box = document.getElementById('flag-reason-container');
    if (box) box.style.display = isChecked ? 'block' : 'none';
}

function handleSaveIncident(event) {
    event.preventDefault();
    const form = document.getElementById('logIncidentForm');
    const formData = new FormData(form);
    formData.append('action', 'save_incident');

    const btn = document.getElementById('btn-save-incident');
    btn.disabled = true;
    btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin me-1"></i> Recording...`;

    fetch('../api/incident_query.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-check me-1"></i> Register Occurrence`;

            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('logIncidentModal')).hide();
                EstateDialog.toast({ type: 'success', message: res.message || 'Incident logged successfully.' });
                loadIncidents();
            } else {
                EstateDialog.alert({ title: 'Logging Error', message: res.error || 'Failed to save incident', type: 'danger' });
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-check me-1"></i> Register Occurrence`;
            console.error(err);
            EstateDialog.toast({ type: 'error', message: 'Network error saving incident.' });
        });
}

function openInvestigateModal(inc) {
    document.getElementById('inv_modal_incident_id').value = inc.id;
    document.getElementById('inv_modal_ref').innerText = inc.incident_ref;
    document.getElementById('inv_modal_title_txt').innerText = inc.title;
    document.getElementById('inv_status').value = inc.status;
    document.getElementById('inv_resolution_notes').value = inc.resolution_notes || '';
    if (inc.investigated_by) {
        document.getElementById('inv_investigator_id').value = inc.investigated_by;
    }

    const modal = new bootstrap.Modal(document.getElementById('investigateModal'));
    modal.show();
}

function handleInvestigateSubmit(event) {
    event.preventDefault();
    const incId = document.getElementById('inv_modal_incident_id').value;
    const status = document.getElementById('inv_status').value;
    const notes = document.getElementById('inv_resolution_notes').value;
    const invId = document.getElementById('inv_investigator_id').value;

    const formData = new FormData();
    formData.append('action', 'update_incident_status');
    formData.append('incident_id', incId);
    formData.append('status', status);
    formData.append('resolution_notes', notes);
    formData.append('investigator_id', invId);

    fetch('../api/incident_query.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('investigateModal')).hide();
                EstateDialog.toast({ type: 'success', message: res.message || 'Incident updated.' });
                loadIncidents();
            } else {
                EstateDialog.alert({ title: 'Update Error', message: res.error, type: 'danger' });
            }
        });
}

async function deleteIncident(id, ref) {
    const confirmed = await EstateDialog.confirm({
        title: 'Delete Incident Record',
        message: `Are you sure you want to permanently delete incident record ${ref}?`,
        type: 'danger',
        confirmText: 'Delete'
    });
    if (!confirmed) return;

    const formData = new FormData();
    formData.append('action', 'delete_incident');
    formData.append('incident_id', id);

    fetch('../api/incident_query.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                EstateDialog.toast({ type: 'success', message: res.message || 'Incident deleted.' });
                loadIncidents();
            } else {
                EstateDialog.alert({ title: 'Delete Error', message: res.error, type: 'danger' });
            }
        });
}

function exportIncidentsCSV() {
    if (!currentLoadedIncidents || currentLoadedIncidents.length === 0) {
        EstateDialog.toast({ type: 'warning', message: 'No incident records to export' });
        return;
    }
    let csv = "Incident Ref,Date & Time,Category,Severity,Title,Location,Target Entity,Target Name,Unit/Address,Vehicle Plate,Flagged Bad Behavior,Flag Reason,Blacklist Rec,Status,Reporter,Resolution Notes\n";
    currentLoadedIncidents.forEach(i => {
        csv += `"${i.incident_ref}","${i.incident_datetime}","${i.incident_type}","${i.severity}","${(i.title||'').replace(/"/g, '""')}","${(i.location_or_post||'').replace(/"/g, '""')}","${i.entity_type}","${(i.target_resident_name||i.target_visitor_name||'').replace(/"/g, '""')}","${(i.target_unit_or_address||'').replace(/"/g, '""')}","${i.vehicle_reg_plate||''}","${i.is_flagged_bad_behavior}","${(i.flag_reason||'').replace(/"/g, '""')}","${i.blacklist_recommended}","${i.status}","${(i.reporter_name||'').replace(/"/g, '""')}","${(i.resolution_notes||'').replace(/"/g, '""')}"\n`;
    });

    const blob = new Blob([csv], { type: 'text/csv' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `security_incidents_ob_${new Date().toISOString().split('T')[0]}.csv`;
    link.click();
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function escapeJsonForAttr(obj) {
    return JSON.stringify(obj).replace(/'/g, "&apos;").replace(/"/g, "&quot;");
}
</script>

<?php include '../includes/footer.php'; ?>
