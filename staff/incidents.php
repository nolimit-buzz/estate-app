<?php
// staff/incidents.php
// Security Guard & Estate Staff Occurrence Logbook & Incident Reporter

require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/emergency_roster_init.php';

if (!isStaffRole() && !isAdminRole()) {
    header("Location: ../login?error=unauthorized");
    exit;
}

$estate_id = get_estate_id();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['name'] ?? 'Officer';
$user_role = $_SESSION['role'] ?? 'security';

// Fetch Active Residents for Quick Selection
$residents_res = $conn->query("
    SELECT u.id, u.name, u.phone, f.number as flat_number, b.name as building_name, s.name as street_name
    FROM users u
    LEFT JOIN residents r ON u.id = r.user_id AND r.estate_id = $estate_id
    LEFT JOIN flats f ON r.flat_id = f.id
    LEFT JOIN buildings b ON f.building_id = b.id
    LEFT JOIN streets s ON b.street_id = s.id
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

// Fetch Active Posts
$posts_res = $conn->query("SELECT * FROM security_posts WHERE estate_id = $estate_id AND status = 'active' ORDER BY post_name ASC");
$posts_list = [];
if ($posts_res) {
    while ($p = $posts_res->fetch_assoc()) {
        $posts_list[] = $p;
    }
}

// Fetch Dynamic Configurable Lookups
$incident_types = getIncidentTypes($conn);
$incident_severities = getIncidentSeverities($conn);
$incident_statuses = getIncidentStatuses($conn);
$incident_entity_types = getIncidentEntityTypes($conn);

include '../includes/header.php';
include 'sidebar.php';
?>

<style>
.staff-incident-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 0.85rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    transition: all 0.15s ease;
}
.staff-incident-card:hover {
    box-shadow: 0 3px 8px rgba(0,0,0,0.08);
}
.flag-chip {
    background: #fee2e2;
    border: 1px solid #f87171;
    color: #991b1b;
    border-radius: 50rem;
    font-size: 0.7rem;
    padding: 0.1rem 0.55rem;
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    font-weight: 700;
}
</style>

<div class="d-flex flex-column gap-3">

    <!-- Header Banner -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 pb-2 border-bottom border-light-subtle">
        <div>
            <h1 class="h5 font-bold text-slate-900 m-0" style="letter-spacing: -0.02em;">
                <i class="fa-solid fa-book-skull text-danger me-2"></i> Security Occurrence Logbook (OB Book)
            </h1>
            <p class="text-secondary small mb-0">Report gate occurrences, flag bad behavior of residents or visitors, and log strange events.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-sm btn-danger px-3 py-1 fw-bold rounded-pill shadow-sm" onclick="openStaffLogModal()">
                <i class="fa-solid fa-triangle-exclamation me-1"></i> Report Security Incident
            </button>
            <a href="security" class="btn btn-sm btn-outline-primary px-3 py-1 rounded-pill">
                <i class="fa-solid fa-shield-halved me-1"></i> Visitor Scanner Gate
            </a>
            <a href="roster" class="btn btn-sm btn-outline-secondary px-3 py-1 rounded-pill">
                <i class="fa-solid fa-calendar-check me-1"></i> My Duty Roster
            </a>
        </div>
    </div>

    <!-- Quick Filter Bar -->
    <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
        <div class="row g-2 align-items-center">
            <div class="col-12 col-md-4">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-magnifying-glass text-secondary"></i></span>
                    <input type="text" id="staff_filter_search" placeholder="Search ref, resident, visitor, vehicle plate..." class="form-control border-start-0" onkeyup="loadStaffIncidents()">
                </div>
            </div>
            <div class="col-6 col-md-3">
                <select id="staff_filter_type" class="form-select form-select-sm" onchange="loadStaffIncidents()">
                    <option value="">All Incident Types</option>
                    <?php foreach ($incident_types as $it): ?>
                        <option value="<?php echo htmlspecialchars($it['slug']); ?>"><?php echo htmlspecialchars($it['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <select id="staff_filter_severity" class="form-select form-select-sm" onchange="loadStaffIncidents()">
                    <option value="">All Severities</option>
                    <?php foreach ($incident_severities as $is): ?>
                        <option value="<?php echo htmlspecialchars($is['slug']); ?>"><?php echo htmlspecialchars($is['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2 text-end">
                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 w-100" onclick="loadStaffIncidents()">
                    <i class="fa-solid fa-rotate me-1"></i> Refresh Feed
                </button>
            </div>
        </div>
    </div>

    <!-- Feed Container -->
    <div class="d-flex flex-column gap-2" id="staff-incidents-feed">
        <div class="text-center py-5 text-muted bg-white rounded-4 border">
            <i class="fa-solid fa-spinner fa-spin me-2"></i> Loading security occurrences...
        </div>
    </div>

</div>

<!-- ==============================================================
     MODAL: STAFF LOG SECURITY INCIDENT
     ============================================================== -->
<div class="modal fade" id="staffLogModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form id="staffLogForm" onsubmit="handleStaffSaveIncident(event)" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden" enctype="multipart/form-data">
            <input type="hidden" name="incident_id" value="0">
            <div class="modal-header px-4 py-3 bg-danger text-white border-0">
                <h5 class="modal-title fw-bold" style="font-family: 'Outfit', sans-serif;">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i> Report Security Incident / Occurrence
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" style="max-height: 75vh; overflow-y: auto;">
                
                <div class="row g-3 mb-3">
                    <div class="col-md-8">
                        <label class="form-label small fw-bold">Incident Title / Summary *</label>
                        <input type="text" name="title" required placeholder="e.g. Visitor refused security check &amp; drove past barrier" class="form-control rounded-3">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Incident Date &amp; Time *</label>
                        <input type="datetime-local" name="incident_datetime" required value="<?php echo date('Y-m-d\TH:i'); ?>" class="form-control rounded-3">
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Category *</label>
                        <select name="incident_type" required class="form-select rounded-3">
                            <?php foreach ($incident_types as $it): ?>
                                <option value="<?php echo htmlspecialchars($it['slug']); ?>"><?php echo htmlspecialchars($it['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Severity Level *</label>
                        <select name="severity" required class="form-select rounded-3">
                            <?php foreach ($incident_severities as $is): ?>
                                <option value="<?php echo htmlspecialchars($is['slug']); ?>" <?php echo ($is['slug'] === 'medium') ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($is['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Location / Gate Post *</label>
                        <input type="text" name="location_or_post" list="staff_posts_list" required placeholder="e.g. Main Entrance Gate" class="form-control rounded-3">
                        <datalist id="staff_posts_list">
                            <?php foreach ($posts_list as $p): ?>
                                <option value="<?php echo htmlspecialchars($p['post_name']); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>

                <!-- Entity Involved -->
                <div class="p-3 rounded-3 bg-light border mb-3">
                    <h6 class="fw-bold mb-2 text-dark small text-uppercase">
                        <i class="fa-solid fa-user-tag me-1 text-primary"></i> Person or Vehicle Involved
                    </h6>
                    <div class="row g-2 mb-2">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Entity Type</label>
                            <select name="entity_type" id="staff_inc_entity_type" class="form-select rounded-3" onchange="handleStaffEntityTypeChange()">
                                <?php foreach ($incident_entity_types as $iet): ?>
                                    <option value="<?php echo htmlspecialchars($iet['slug']); ?>"><?php echo htmlspecialchars($iet['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8" id="staff-resident-picker-box" style="display: none;">
                            <label class="form-label small fw-bold">Select Resident</label>
                            <select name="target_resident_id" id="staff_inc_resident_id" class="form-select rounded-3" onchange="handleStaffResidentSelect(this)">
                                <option value="">-- Choose Resident --</option>
                                <?php foreach ($residents_list as $res): ?>
                                    <?php 
                                    $unit_parts = [];
                                    if (!empty($res['building_name'])) $unit_parts[] = $res['building_name'];
                                    if (!empty($res['flat_number'])) $unit_parts[] = 'Flat ' . $res['flat_number'];
                                    if (!empty($res['street_name'])) $unit_parts[] = $res['street_name'];
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
                            <label class="form-label small fw-semibold">Name / Alias</label>
                            <input type="text" name="target_visitor_name" id="staff_inc_target_name" placeholder="Name if visitor or driver" class="form-control rounded-3">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Unit / Destination Address</label>
                            <input type="text" name="target_unit_or_address" id="staff_inc_target_unit" placeholder="e.g. Block B Apt 4" class="form-control rounded-3">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Vehicle Plate Number</label>
                            <input type="text" name="vehicle_reg_plate" placeholder="e.g. KJA-482-AA" class="form-control text-uppercase rounded-3">
                        </div>
                    </div>
                </div>

                <!-- Flag Bad Behavior -->
                <div class="p-3 rounded-3 mb-3" style="background: #fff1f2; border: 1px solid #fecdd3;">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="is_flagged_bad_behavior" id="staff_is_flagged" value="1" onchange="toggleStaffFlagReason(this.checked)">
                        <label class="form-check-label fw-bold text-danger" for="staff_is_flagged">
                            <i class="fa-solid fa-flag me-1"></i> Flag Misconduct / Bad Behavior
                        </label>
                    </div>
                    <div id="staff-flag-reason-box" style="display: none;">
                        <label class="form-label small fw-bold text-danger">Violation Summary *</label>
                        <input type="text" name="flag_reason" placeholder="e.g. Threatened gate officer and forced entry through boom barrier" class="form-control rounded-3 mb-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="blacklist_recommended" value="1" id="staff_blacklist_rec">
                            <label class="form-check-label small fw-semibold text-dark" for="staff_blacklist_rec">
                                Recommend for Gate Blacklist
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Description & Action -->
                <div class="mb-3">
                    <label class="form-label small fw-bold">Incident Description &amp; Eyewitness Details *</label>
                    <textarea name="description" rows="3" required placeholder="What happened, statements made, actions observed..." class="form-control rounded-3"></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Immediate Action Taken</label>
                    <textarea name="immediate_action_taken" rows="2" placeholder="e.g. Refused entry, escorted subject to gate, dispatched patrol unit..." class="form-control rounded-3"></textarea>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold">Attach Photo / Snapshot Evidence (Optional)</label>
                    <input type="file" name="evidence_image" accept="image/*" capture="environment" class="form-control rounded-3">
                </div>

            </div>
            <div class="modal-footer px-4 py-3 bg-light border-0">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold" id="btn-staff-save">
                    <i class="fa-solid fa-paper-plane me-1"></i> Submit Occurrence Log
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    loadStaffIncidents();
});

function openStaffLogModal() {
    document.getElementById('staffLogForm').reset();
    toggleStaffFlagReason(false);
    const modal = new bootstrap.Modal(document.getElementById('staffLogModal'));
    modal.show();
}

function handleStaffEntityTypeChange() {
    const val = document.getElementById('staff_inc_entity_type').value;
    const box = document.getElementById('staff-resident-picker-box');
    if (box) box.style.display = (val === 'resident') ? 'block' : 'none';
}

function handleStaffResidentSelect(elem) {
    const opt = elem.options[elem.selectedIndex];
    if (opt && opt.value) {
        document.getElementById('staff_inc_target_name').value = opt.getAttribute('data-name') || '';
        document.getElementById('staff_inc_target_unit').value = opt.getAttribute('data-unit') || '';
    }
}

function toggleStaffFlagReason(isChecked) {
    const box = document.getElementById('staff-flag-reason-box');
    if (box) box.style.display = isChecked ? 'block' : 'none';
}

function loadStaffIncidents() {
    const search = document.getElementById('staff_filter_search') ? document.getElementById('staff_filter_search').value : '';
    const type = document.getElementById('staff_filter_type') ? document.getElementById('staff_filter_type').value : '';
    const sev = document.getElementById('staff_filter_severity') ? document.getElementById('staff_filter_severity').value : '';

    const url = `../api/incident_query.php?action=fetch_incidents&search=${encodeURIComponent(search)}&incident_type=${type}&severity=${sev}`;

    fetch(url)
        .then(r => r.json())
        .then(res => {
            const container = document.getElementById('staff-incidents-feed');
            if (!container) return;

            if (res.success && res.incidents && res.incidents.length > 0) {
                let html = '';
                res.incidents.forEach(inc => {
                    let sevBadge = 'bg-secondary';
                    if (inc.severity === 'critical') sevBadge = 'bg-danger text-white';
                    else if (inc.severity === 'high') sevBadge = 'bg-warning text-dark';
                    else if (inc.severity === 'medium') sevBadge = 'bg-info text-dark';
                    else sevBadge = 'bg-light text-dark border';

                    const flagPill = (inc.is_flagged_bad_behavior == 1) ? `
                        <span class="flag-chip"><i class="fa-solid fa-flag"></i> FLAGGED MISCONDUCT: ${escapeHtml(inc.flag_reason || '')}</span>
                    ` : '';

                    const evidenceLink = inc.evidence_image_path ? `
                        <a href="../${inc.evidence_image_path}" target="_blank" class="badge bg-light text-primary border text-decoration-none py-1 px-2">
                            <i class="fa-solid fa-image me-1"></i> View Photo Evidence
                        </a>
                    ` : '';

                    const targetName = inc.target_resident_name || inc.target_visitor_name || 'Unspecified';
                    const targetUnit = inc.target_unit_or_address || '';
                    const plate = inc.vehicle_reg_plate ? `Plate: ${inc.vehicle_reg_plate}` : '';

                    html += `
                        <div class="staff-incident-card p-3">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2 pb-2 border-bottom">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="badge ${sevBadge} rounded-pill text-uppercase px-2" style="font-size: 0.7rem;">${inc.severity}</span>
                                    <strong class="text-primary">${escapeHtml(inc.incident_ref)}</strong>
                                    <span class="text-secondary small">&bull; ${inc.formatted_datetime}</span>
                                    <span class="badge bg-light text-dark border">${escapeHtml(inc.location_or_post)}</span>
                                </div>
                                <div>
                                    <span class="badge bg-light text-secondary border text-uppercase" style="font-size: 0.7rem;">Status: ${inc.status.replace(/_/g, ' ')}</span>
                                </div>
                            </div>
                            
                            <h6 class="fw-bold mb-1 text-dark">${escapeHtml(inc.title)}</h6>
                            <p class="text-secondary small mb-2" style="font-size: 0.82rem;">${escapeHtml(inc.description)}</p>

                            ${inc.immediate_action_taken ? `
                                <div class="small p-2 rounded bg-light border-start border-3 border-info mb-2">
                                    <strong>Action Taken:</strong> ${escapeHtml(inc.immediate_action_taken)}
                                </div>
                            ` : ''}

                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pt-1">
                                <div class="d-flex align-items-center gap-2 flex-wrap small">
                                    <span class="text-dark"><strong>Target:</strong> ${escapeHtml(targetName)} ${targetUnit ? `(${escapeHtml(targetUnit)})` : ''} ${plate ? `&bull; <strong class="text-danger">${plate}</strong>` : ''}</span>
                                    ${flagPill}
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    ${evidenceLink}
                                    <span class="text-muted small">Logged by: <strong>${escapeHtml(inc.reporter_name || 'Staff')}</strong></span>
                                </div>
                            </div>
                        </div>
                    `;
                });
                container.innerHTML = html;
            } else {
                container.innerHTML = `<div class="text-center py-5 text-muted bg-white rounded-4 border">No occurrence logs found.</div>`;
            }
        });
}

function handleStaffSaveIncident(event) {
    event.preventDefault();
    const form = document.getElementById('staffLogForm');
    const formData = new FormData(form);
    formData.append('action', 'save_incident');

    const btn = document.getElementById('btn-staff-save');
    btn.disabled = true;
    btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin me-1"></i> Saving...`;

    fetch('../api/incident_query.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-paper-plane me-1"></i> Submit Occurrence Log`;

            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('staffLogModal')).hide();
                EstateDialog.toast({ type: 'success', message: res.message || 'Occurrence reported successfully.' });
                loadStaffIncidents();
            } else {
                EstateDialog.alert({ title: 'Submission Error', message: res.error || 'Failed to submit occurrence.', type: 'danger' });
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-paper-plane me-1"></i> Submit Occurrence Log`;
            console.error(err);
            EstateDialog.toast({ type: 'error', message: 'Network error submitting occurrence.' });
        });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}
</script>

<?php include '../includes/footer.php'; ?>
