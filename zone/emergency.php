<?php
// zone/emergency.php
// Zonal Emergency Panic Broadcast & Incident Monitor

require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/emergency_roster_init.php';

if (!isZoneAdminRole()) {
    header("Location: login?error=unauthorized");
    exit;
}

$estate_id = get_estate_id();
$user_id = $_SESSION['user_id'];
$zone_id = intval($_SESSION['zone_id'] ?? 0);
$zone_name = $_SESSION['zone_name'] ?? 'Zone';

$message = "";
$error = "";

// -------------------------------------------------------------
// POST ACTIONS: TRIGGER ZONAL EMERGENCY PANIC BROADCAST
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_trigger_zonal_broadcast'])) {
    $category_name = $conn->real_escape_string(trim($_POST['category_name']));
    $headline = $conn->real_escape_string(trim($_POST['headline']));
    $note = $conn->real_escape_string(trim($_POST['note']));
    $sound_alarm = isset($_POST['sound_alarm']) ? 1 : 0;

    $user_info = $conn->query("SELECT name, phone FROM users WHERE id = $user_id LIMIT 1")->fetch_assoc();
    $sender_name = $conn->real_escape_string($user_info['name'] ?? ($zone_name . ' Admin'));
    $sender_phone = $conn->real_escape_string($user_info['phone'] ?? '');

    // Snapshot on-duty guards
    $now = date('Y-m-d H:i:s');
    $guards_res = $conn->query("SELECT sr.id, u.name as officer_name, u.phone as officer_phone, sp.post_name, ss.name as shift_name
                                FROM security_roster sr
                                JOIN users u ON sr.user_id = u.id
                                LEFT JOIN security_posts sp ON sr.post_id = sp.id
                                LEFT JOIN security_shifts ss ON sr.shift_id = ss.id
                                WHERE sr.estate_id = $estate_id AND sr.start_datetime <= '$now' AND sr.end_datetime >= '$now'");
    $guards = [];
    while ($g = $guards_res->fetch_assoc()) $guards[] = $g;
    $guards_json = $conn->real_escape_string(json_encode($guards, JSON_UNESCAPED_SLASHES));

    $alert_code = "SOS-Z" . $zone_id . "-" . date('ymd') . "-" . rand(10, 99);

    $sql = "INSERT INTO estate_emergency_alerts (
        estate_id, alert_code, sender_type, sender_id, sender_name, sender_phone,
        target_scope, zone_id, category_name, headline, note, status, sound_alarm,
        security_officers_on_duty_snapshot
    ) VALUES (
        $estate_id, '$alert_code', 'zone_admin', $user_id, '$sender_name', '$sender_phone',
        'zone', $zone_id, '$category_name', '$headline', '$note', 'active', $sound_alarm,
        '$guards_json'
    )";

    if ($conn->query($sql)) {
        logAudit($conn, "Zonal Panic Broadcast", "Emergency", "Zonal Admin triggered emergency broadcast $alert_code ($category_name) for $zone_name.");
        $message = "Zonal Emergency Broadcast ($alert_code) dispatched to all residents in $zone_name and security personnel!";
    } else {
        $error = "Failed to dispatch broadcast: " . $conn->error;
    }
}

// Fetch Active Alerts in this Zone (or estate-wide broadcasts)
$zone_alerts = $conn->query("SELECT ea.*, TIMESTAMPDIFF(SECOND, ea.created_at, NOW()) as seconds_ago 
                             FROM estate_emergency_alerts ea 
                             WHERE ea.estate_id = $estate_id 
                               AND (ea.zone_id = $zone_id OR ea.target_scope = 'estate_wide') 
                               AND ea.status IN ('active', 'acknowledged', 'dispatched')
                             ORDER BY ea.created_at DESC");

// Fetch Estate Hotlines
$hotlines = $conn->query("SELECT * FROM estate_emergency_contacts WHERE estate_id = $estate_id AND is_active = 1 ORDER BY display_order ASC");

// Fetch on duty guards
$now = date('Y-m-d H:i:s');
$guards_on_duty = $conn->query("SELECT sr.*, u.name as officer_name, u.phone as officer_phone, sp.post_name, ss.name as shift_name
                                FROM security_roster sr
                                JOIN users u ON sr.user_id = u.id
                                LEFT JOIN security_posts sp ON sr.post_id = sp.id
                                LEFT JOIN security_shifts ss ON sr.shift_id = ss.id
                                WHERE sr.estate_id = $estate_id 
                                  AND sr.start_datetime <= '$now' 
                                  AND sr.end_datetime >= '$now'
                                  AND sr.status IN ('on_duty', 'scheduled')");

include 'header.php';
include 'sidebar.php';
?>

<div class="d-flex flex-column gap-4">
    <!-- ==========================================
         EXECUTIVE HEADER & ZONAL COMMAND BAR
         ========================================== -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-2 pb-2 border-bottom border-light-subtle">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <h1 class="h4 font-bold text-slate-900 m-0" style="letter-spacing: -0.02em;">
                    Sector Emergency &amp; Security Hub
                </h1>
                <span class="mature-badge mature-badge-primary">
                    <i class="fa-solid fa-layer-group me-1"></i> <?php echo htmlspecialchars($zone_name); ?>
                </span>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2 text-secondary small">
                <span><i class="fa-regular fa-calendar me-1"></i> <?php echo date('l, F j, Y'); ?></span>
                <span>•</span>
                <span><i class="fa-solid fa-shield-halved me-1"></i> Sector Command Deck</span>
                <span>•</span>
                <span class="text-success"><i class="fa-solid fa-circle me-1" style="font-size: 0.55rem;"></i> Active Monitoring</span>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-sm btn-danger px-3 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#zonalPanicModal" style="background: #e11d48; border-color: #e11d48;">
                <i class="fa-solid fa-bullhorn me-1"></i> Dispatch Zonal Panic
            </button>
            <button type="button" onclick="toggleEstateSirenMute()" id="estate-alarm-mute-btn" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-volume-high me-1"></i> Toggle Siren
            </button>
        </div>
    </div>

    <!-- Feedback Alerts -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert" style="background: #dcfce7; color: #166534;">
            <i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert" style="background: #fee2e2; color: #991b1b;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- ==========================================
         EXECUTIVE KPI METRICS RIBBON (4 PILLARS)
         ========================================== -->
    <div class="row g-3">
        <!-- Pillar 1: Sector Incidents -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="kpi-title">Active Sector Alarms</span>
                        <div class="kpi-value <?php echo ($zone_alerts && $zone_alerts->num_rows > 0) ? 'text-danger' : 'text-slate-900'; ?>">
                            <?php echo ($zone_alerts ? $zone_alerts->num_rows : 0); ?>
                        </div>
                    </div>
                    <div class="kpi-icon-wrap" style="background: #fff1f2; color: #e11d48; border-color: #fecdd3;">
                        <i class="fa-solid fa-radiation"></i>
                    </div>
                </div>
                <div class="kpi-meta justify-content-between mt-2">
                    <span><?php echo htmlspecialchars($zone_name); ?></span>
                    <?php if ($zone_alerts && $zone_alerts->num_rows > 0): ?>
                        <span class="mature-badge mature-badge-crimson">Active</span>
                    <?php else: ?>
                        <span class="mature-badge mature-badge-emerald">Zone Secure</span>
                    <?php endif; ?>
                </div>
                <div class="kpi-progress-bar">
                    <div class="kpi-progress-fill" style="width: <?php echo ($zone_alerts && $zone_alerts->num_rows > 0) ? '100%' : '10%'; ?>; background: <?php echo ($zone_alerts && $zone_alerts->num_rows > 0) ? '#e11d48' : '#16a34a'; ?>;"></div>
                </div>
            </div>
        </div>

        <!-- Pillar 2: Assigned Sector Officers -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="kpi-title">Security Forces</span>
                        <div class="kpi-value"><?php echo ($guards_on_duty ? $guards_on_duty->num_rows : 0); ?> <span style="font-size: 0.95rem; font-weight: 500; color: #64748b;">Officers</span></div>
                    </div>
                    <div class="kpi-icon-wrap" style="background: #f0fdf4; color: #16a34a; border-color: #dcfce7;">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>
                </div>
                <div class="kpi-meta justify-content-between mt-2">
                    <span>Sector Patrols</span>
                    <span class="mature-badge mature-badge-primary">On Post</span>
                </div>
                <div class="kpi-progress-bar">
                    <div class="kpi-progress-fill" style="width: <?php echo min(100, max(12, ($guards_on_duty ? $guards_on_duty->num_rows : 0) * 25)); ?>%;"></div>
                </div>
            </div>
        </div>

        <!-- Pillar 3: Sector Hotlines -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="kpi-title">Direct Hotlines</span>
                        <div class="kpi-value"><?php echo ($hotlines ? $hotlines->num_rows : 0); ?> <span style="font-size: 0.95rem; font-weight: 500; color: #64748b;">Lines</span></div>
                    </div>
                    <div class="kpi-icon-wrap">
                        <i class="fa-solid fa-phone-volume"></i>
                    </div>
                </div>
                <div class="kpi-meta justify-content-between mt-2">
                    <span>Rapid Reach</span>
                    <span class="mature-badge mature-badge-slate">Verified</span>
                </div>
                <div class="kpi-progress-bar">
                    <div class="kpi-progress-fill" style="width: 100%;"></div>
                </div>
            </div>
        </div>

        <!-- Pillar 4: Zonal Sector Identity -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="kpi-title">Sector Identity</span>
                        <div class="kpi-value" style="font-size: 1.45rem;"><?php echo htmlspecialchars($zone_name); ?></div>
                    </div>
                    <div class="kpi-icon-wrap" style="background: #eff6ff; color: #2563eb; border-color: #dbeafe;">
                        <i class="fa-solid fa-city"></i>
                    </div>
                </div>
                <div class="kpi-meta justify-content-between mt-2">
                    <span>Zonal Sector Admin</span>
                    <span class="mature-badge mature-badge-emerald">Active Hub</span>
                </div>
                <div class="kpi-progress-bar">
                    <div class="kpi-progress-fill" style="width: 100%; background: #2563eb;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==========================================
         ACTIVE ZONAL INCIDENTS
         ========================================== -->
    <div class="mature-card">
        <div class="mature-card-header">
            <div>
                <h5 class="mature-card-title">
                    <i class="fa-solid fa-radiation text-danger"></i> Active Incidents in <?php echo htmlspecialchars($zone_name); ?>
                </h5>
                <small class="text-secondary">Real-time alerts and panic dispatches affecting this zone</small>
            </div>
            <span class="mature-badge <?php echo ($zone_alerts && $zone_alerts->num_rows > 0) ? 'mature-badge-crimson' : 'mature-badge-emerald'; ?>">
                <i class="fa-solid fa-circle me-1" style="font-size: 0.45rem;"></i>
                <?php echo ($zone_alerts ? $zone_alerts->num_rows : 0); ?> Active Alarms
            </span>
        </div>

        <div class="mature-card-body">
            <?php if ($zone_alerts && $zone_alerts->num_rows > 0): ?>
                <div class="row g-3">
                    <?php while ($za = $zone_alerts->fetch_assoc()): ?>
                        <div class="col-12">
                            <div class="p-3 border rounded-3 bg-white" style="border-left: 4px solid #e11d48 !important;">
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-2">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="kpi-icon-wrap" style="background: #fff1f2; color: #e11d48; border-color: #fecdd3; width: 40px; height: 40px; font-weight: 800; font-size: 0.85rem;">
                                            SOS
                                        </div>
                                        <div>
                                            <h6 class="fw-bold mb-0 text-slate-900"><?php echo htmlspecialchars($za['category_name']); ?></h6>
                                            <span class="mature-badge mature-badge-crimson"><?php echo htmlspecialchars($za['alert_code']); ?></span>
                                            <span class="mature-badge mature-badge-amber text-uppercase"><?php echo htmlspecialchars($za['status']); ?></span>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <button type="button" onclick="quickAcknowledgeEmergency(<?php echo $za['id']; ?>)" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold">
                                            <i class="fa-solid fa-check me-1"></i> Acknowledge
                                        </button>
                                    </div>
                                </div>
                                <div class="pt-2 border-top border-light-subtle small text-secondary">
                                    <strong>Source:</strong> <?php echo htmlspecialchars($za['sender_name']); ?> (<?php echo ucfirst($za['sender_type']); ?>)
                                    <?php if (!empty($za['building_name'])): ?>
                                        &bull; <strong>Unit:</strong> <?php echo htmlspecialchars($za['building_name']); ?> #<?php echo htmlspecialchars($za['flat_number']); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($za['headline'])): ?>
                                        &bull; <strong class="text-danger">Advisory:</strong> <?php echo htmlspecialchars($za['headline']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-4">
                    <div style="width: 50px; height: 50px; border-radius: 50%; background: #ecfdf5; color: #10b981; display: inline-flex; align-items: center; justify-content: center; font-size: 1.5rem;" class="mb-2">
                        <i class="fa-solid fa-shield-check"></i>
                    </div>
                    <h6 class="fw-bold text-slate-900">Sector Secure</h6>
                    <p class="text-secondary small mb-0">No active emergency alarms in <?php echo htmlspecialchars($zone_name); ?> right now.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ==========================================
         ON DUTY SECURITY FORCES
         ========================================== -->
    <div class="mature-card">
        <div class="mature-card-header">
            <div>
                <h5 class="mature-card-title">
                    <i class="fa-solid fa-user-shield text-primary"></i> On-Duty Security Force
                </h5>
                <small class="text-secondary">Security officers stationed in this sector</small>
            </div>
            <span class="mature-badge mature-badge-emerald">
                <i class="fa-solid fa-circle me-1" style="font-size: 0.45rem;"></i> <?php echo ($guards_on_duty ? $guards_on_duty->num_rows : 0); ?> Active
            </span>
        </div>
        <div class="mature-card-body">
            <div class="row g-3">
                <?php if ($guards_on_duty && $guards_on_duty->num_rows > 0): ?>
                    <?php while ($gd = $guards_on_duty->fetch_assoc()): ?>
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="p-3 border rounded-3 bg-white d-flex align-items-center justify-content-between shadow-sm">
                                <div>
                                    <strong class="d-block text-slate-900" style="font-size: 0.92rem;"><?php echo htmlspecialchars($gd['officer_name']); ?></strong>
                                    <span class="mature-badge mature-badge-primary" style="font-size: 0.7rem;">
                                        <?php echo htmlspecialchars($gd['post_name'] ?? 'Gate'); ?>
                                    </span>
                                </div>
                                <?php if (!empty($gd['officer_phone'])): ?>
                                    <a href="tel:<?php echo htmlspecialchars($gd['officer_phone']); ?>" class="btn btn-sm btn-outline-success rounded-pill px-3">
                                        <i class="fa-solid fa-phone me-1"></i> Call
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="col-12 text-muted small py-3 text-center">No guards currently on duty record in this sector.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>

<!-- ========================================================
     MODAL: TRIGGER ZONAL PANIC BROADCAST
     ======================================================== -->
<div class="modal fade" id="zonalPanicModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="zonalPanicForm" class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem;">
            <input type="hidden" name="action_trigger_zonal_broadcast" value="1">
            <div class="modal-header bg-danger text-white px-4 py-3 border-0">
                <h5 class="modal-title fw-bold">Trigger Zonal Emergency Broadcast</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="small text-secondary mb-3">Dispatches high-urgency audio siren and alert banner to all residents and security personnel in <strong><?php echo htmlspecialchars($zone_name); ?></strong>.</p>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Emergency Category *</label>
                    <select name="category_name" required class="form-select rounded-3">
                        <option value="Security Threat">Security Threat (Intruder, breach, lockdown)</option>
                        <option value="Fire / Smoke Hazard">Fire / Smoke Evacuation Hazard</option>
                        <option value="Electrical / Infrastructure Hazard">Electrical / Transformer Hazard</option>
                        <option value="Flood / Water Disaster">Flash Flood / Water Burst</option>
                        <option value="Urgent Zonal Advisory">Urgent Zonal Advisory</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Urgent Headline / Advisory *</label>
                    <input type="text" name="headline" required placeholder="e.g. Transformer explosion on Street 4 - Evacuate area immediately!" class="form-control rounded-3">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Safety Notes</label>
                    <textarea name="note" rows="2" placeholder="Actionable safety guidelines for residents..." class="form-control rounded-3"></textarea>
                </div>
                <div class="form-check form-switch p-3 bg-light rounded-3">
                    <input class="form-check-input ms-0 me-3" type="checkbox" name="sound_alarm" value="1" id="z_sound_alarm" checked>
                    <label class="form-check-label fw-bold" for="z_sound_alarm">
                        <i class="fa-solid fa-bullhorn text-danger me-1"></i> Sound Audio Siren Alarm
                    </label>
                </div>
            </div>
            <div class="modal-footer px-4 py-3 bg-light border-0">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold">Dispatch Zonal Panic</button>
            </div>
        </form>
    </div>
</div>

<script>
// Ask confirmation before triggering zonal emergency panic broadcast
document.addEventListener('DOMContentLoaded', function() {
    const zonalForm = document.getElementById('zonalPanicForm');
    if (zonalForm) {
        zonalForm.addEventListener('submit', async function(e) {
            if (this.dataset.confirmed === 'true') {
                return;
            }
            e.preventDefault();
            e.stopImmediatePropagation();

            if (typeof this.checkValidity === 'function' && !this.checkValidity()) {
                this.reportValidity();
                return;
            }

            const confirmed = window.EstateDialog ? await EstateDialog.confirm({
                title: 'Trigger Zonal Panic Broadcast',
                message: 'Are you sure you want to trigger the alarm?',
                type: 'danger',
                confirmText: 'Yes, Trigger Alarm',
                cancelText: 'Cancel'
            }) : confirm('Are you sure you want to trigger the alarm?');

            if (confirmed) {
                this.dataset.confirmed = 'true';
                if (typeof this.requestSubmit === 'function') {
                    this.requestSubmit();
                } else {
                    this.submit();
                }
            }
        });
    }
});
</script>

<?php 
include 'footer.php'; 
?>
