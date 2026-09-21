<?php
// resident/emergency.php
// Resident Emergency Hub & Quick SOS Portal (Executive Dashboard Design)

require_once '../config.php';
require_once '../includes/emergency_roster_init.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit;
}

$user_id = intval($_SESSION['user_id']);
$estate_id = get_estate_id();
$user_name = $_SESSION['name'] ?? 'Resident';

// Dynamic Day Greeting
$hour = intval(date('H'));
if ($hour < 12) {
    $greeting = "Good morning";
} elseif ($hour < 17) {
    $greeting = "Good afternoon";
} else {
    $greeting = "Good evening";
}

// Fetch Resident Profile & Unit
$res_query = "SELECT r.*, f.number as flat_number, b.name as building_name, s.name as street_name 
              FROM residents r 
              LEFT JOIN flats f ON r.flat_id = f.id 
              LEFT JOIN buildings b ON f.building_id = b.id 
              LEFT JOIN streets s ON b.street_id = s.id 
              WHERE r.user_id = $user_id AND r.estate_id = $estate_id 
              ORDER BY r.id DESC LIMIT 1";
$resident_info = $conn->query($res_query)->fetch_assoc();

// Fetch Estate Hotlines
$hotlines_res = $conn->query("SELECT * FROM estate_emergency_contacts WHERE estate_id = $estate_id AND is_active = 1 ORDER BY display_order ASC");
$hotlines = [];
if ($hotlines_res) {
    while ($h = $hotlines_res->fetch_assoc()) {
        $hotlines[] = $h;
    }
}

// Fetch Active Security Guards on duty right now
$now = date('Y-m-d H:i:s');
$guards_res = $conn->query("SELECT sr.*, u.name as officer_name, u.phone as officer_phone, sp.post_name, ss.name as shift_name
                            FROM security_roster sr
                            JOIN users u ON sr.user_id = u.id
                            LEFT JOIN security_posts sp ON sr.post_id = sp.id
                            LEFT JOIN security_shifts ss ON sr.shift_id = ss.id
                            WHERE sr.estate_id = $estate_id 
                              AND sr.start_datetime <= '$now' 
                              AND sr.end_datetime >= '$now'
                              AND sr.status IN ('on_duty', 'scheduled')
                            ORDER BY sp.post_name ASC");
$guards_on_duty = [];
if ($guards_res) {
    while ($g = $guards_res->fetch_assoc()) {
        $guards_on_duty[] = $g;
    }
}

// Fetch Past Alerts
$my_alerts = $conn->query("SELECT * FROM estate_emergency_alerts 
                           WHERE estate_id = $estate_id AND (sender_id = $user_id OR sender_type IN ('central_admin', 'zone_admin')) 
                           ORDER BY created_at DESC LIMIT 10");

include 'header.php';
include 'sidebar.php';
?>

<div class="d-flex flex-column gap-4">
    <!-- ==========================================
         EXECUTIVE HEADER & RESIDENT COMMAND BAR
         ========================================== -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-2 pb-2 border-bottom border-light-subtle">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <h1 class="h4 font-bold text-slate-900 m-0" style="letter-spacing: -0.02em;">
                    <?php echo $greeting; ?>, <?php echo htmlspecialchars($user_name); ?>
                </h1>
                <span class="mature-badge mature-badge-crimson">
                    <i class="fa-solid fa-truck-medical me-1"></i> Emergency Hub
                </span>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2 text-secondary small">
                <span><i class="fa-regular fa-calendar me-1"></i> <?php echo date('l, F j, Y'); ?></span>
                <span>•</span>
                <span><i class="fa-solid fa-location-dot me-1 text-primary"></i> <?php echo htmlspecialchars(($resident_info['building_name'] ?? 'Residence') . ' &bull; Unit ' . ($resident_info['flat_number'] ?? 'N/A')); ?></span>
                <span>•</span>
                <span class="text-success"><i class="fa-solid fa-circle me-1" style="font-size: 0.55rem;"></i> Control Room Active</span>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="#estate-hotlines" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-phone-volume me-1"></i> Hotlines
            </a>
            <a href="#on-duty-guards" class="btn btn-sm btn-outline-primary">
                <i class="fa-solid fa-shield-halved me-1"></i> On-Duty Guards
            </a>
            <button type="button" class="btn btn-sm btn-danger px-3 fw-bold shadow-sm btn-estate-panic-trigger" style="background: #e11d48; border-color: #e11d48;">
                <i class="fa-solid fa-triangle-exclamation me-1"></i> Trigger SOS
            </button>
        </div>
    </div>

    <!-- ==========================================
         EXECUTIVE KPI METRICS RIBBON (4 PILLARS)
         ========================================== -->
    <div class="row g-3">
        <!-- Pillar 1: Rapid Dispatch Status -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="kpi-title">Rapid Dispatch Status</span>
                        <div class="kpi-value text-slate-900" style="font-size: 1.45rem;">
                            Standby <span style="font-size: 0.95rem; font-weight: 500; color: #16a34a;">Ready</span>
                        </div>
                    </div>
                    <div class="kpi-icon-wrap" style="background: #fff1f2; color: #e11d48; border-color: #fecdd3;">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                </div>
                <div class="kpi-meta justify-content-between mt-2">
                    <span>Central Gate Link</span>
                    <span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-circle me-1" style="font-size: 0.45rem;"></i>Online 24/7</span>
                </div>
                <div class="kpi-progress-bar">
                    <div class="kpi-progress-fill" style="width: 100%; background: #16a34a;"></div>
                </div>
            </div>
        </div>

        <!-- Pillar 2: Active Security Officers on Post -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="kpi-title">On-Duty Security</span>
                        <div class="kpi-value"><?php echo count($guards_on_duty); ?> <span style="font-size: 0.95rem; font-weight: 500; color: #64748b;">Officers</span></div>
                    </div>
                    <div class="kpi-icon-wrap" style="background: #f0fdf4; color: #16a34a; border-color: #dcfce7;">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>
                </div>
                <div class="kpi-meta justify-content-between mt-2">
                    <span>Gate &amp; Patrol Posts</span>
                    <span class="mature-badge mature-badge-primary">Live Roster</span>
                </div>
                <div class="kpi-progress-bar">
                    <div class="kpi-progress-fill" style="width: <?php echo min(100, max(12, count($guards_on_duty) * 25)); ?>%;"></div>
                </div>
            </div>
        </div>

        <!-- Pillar 3: Emergency Hotlines -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="kpi-title">Emergency Directory</span>
                        <div class="kpi-value"><?php echo count($hotlines); ?> <span style="font-size: 0.95rem; font-weight: 500; color: #64748b;">Hotlines</span></div>
                    </div>
                    <div class="kpi-icon-wrap" style="background: #f8fafc; color: #334155; border-color: #e2e8f0;">
                        <i class="fa-solid fa-phone-volume"></i>
                    </div>
                </div>
                <div class="kpi-meta justify-content-between mt-2">
                    <span>Direct-Dial Verified</span>
                    <span class="mature-badge mature-badge-slate">Monitored</span>
                </div>
                <div class="kpi-progress-bar">
                    <div class="kpi-progress-fill" style="width: 100%;"></div>
                </div>
            </div>
        </div>

        <!-- Pillar 4: Registered Residence Link -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="kpi-title">My Residence Link</span>
                        <div class="kpi-value" style="font-size: 1.45rem;">
                            Unit <?php echo htmlspecialchars($resident_info['flat_number'] ?? '-'); ?>
                        </div>
                    </div>
                    <div class="kpi-icon-wrap" style="background: #eff6ff; color: #2563eb; border-color: #dbeafe;">
                        <i class="fa-solid fa-location-dot"></i>
                    </div>
                </div>
                <div class="kpi-meta justify-content-between mt-2">
                    <span class="text-truncate" style="max-width: 130px;"><?php echo htmlspecialchars($resident_info['building_name'] ?? 'Residence'); ?></span>
                    <span class="mature-badge mature-badge-emerald">GPS Linked</span>
                </div>
                <div class="kpi-progress-bar">
                    <div class="kpi-progress-fill" style="width: 100%; background: #2563eb;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==========================================
         INSTANT SOS PANIC ACTION CARD
         ========================================== -->
    <div class="mature-card p-4" style="border-left: 4px solid #e11d48 !important;">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="mature-badge mature-badge-crimson text-uppercase">
                        <i class="fa-solid fa-bolt me-1"></i> Rapid SOS Dispatcher
                    </span>
                    <span class="small text-secondary">• Direct Command Telemetry</span>
                </div>
                <h2 class="h4 font-bold text-slate-900 mb-2" style="letter-spacing: -0.01em;">
                    Need Urgent Security or Medical Assistance?
                </h2>
                <p class="text-secondary small mb-3" style="line-height: 1.6; max-width: 680px;">
                    Triggering the SOS button alerts all active gate security officers, sounds the estate control room alarm, and automatically transmits your exact verified unit location: 
                    <strong class="text-dark"><?php echo htmlspecialchars(($resident_info['building_name'] ?? 'Residence') . ' &bull; Unit ' . ($resident_info['flat_number'] ?? 'N/A')); ?></strong>.
                </p>
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <span class="small text-secondary"><i class="fa-solid fa-circle-check text-success me-1"></i> Pre-alarm confirmation countdown</span>
                    <span class="small text-secondary"><i class="fa-solid fa-volume-xmark text-secondary me-1"></i> Manual silence option</span>
                    <span class="small text-secondary"><i class="fa-solid fa-shield text-primary me-1"></i> Guard GPS dispatch</span>
                </div>
            </div>
            <div class="col-lg-4 text-lg-end text-center">
                <button type="button" class="btn btn-danger btn-lg rounded-pill px-4 py-3 fw-bold shadow btn-estate-panic-trigger" style="font-size: 1.05rem; background: #e11d48; border-color: #e11d48;">
                    <i class="fa-solid fa-radiation me-2"></i> ACTIVATE PANIC ALERT
                </button>
                <div class="small text-muted mt-2">Protected against accidental false alarms</div>
            </div>
        </div>
    </div>

    <!-- ==========================================
         ESTATE DIRECT-DIAL EMERGENCY HOTLINES
         ========================================== -->
    <div id="estate-hotlines" class="mature-card">
        <div class="mature-card-header">
            <div>
                <h5 class="mature-card-title">
                    <i class="fa-solid fa-phone-volume text-danger"></i> Estate Direct-Dial Emergency Directory
                </h5>
                <small class="text-secondary">Official hotlines configured by estate management for rapid response</small>
            </div>
            <span class="mature-badge mature-badge-crimson">
                <i class="fa-solid fa-circle me-1" style="font-size: 0.45rem;"></i> 24/7 Monitored
            </span>
        </div>

        <div class="mature-card-body">
            <div class="row g-3">
                <?php if (!empty($hotlines)): ?>
                    <?php foreach ($hotlines as $h): ?>
                        <?php 
                        $badge_class = 'mature-badge-primary';
                        if ($h['contact_type'] === 'internal_security') $badge_class = 'mature-badge-crimson';
                        elseif ($h['contact_type'] === 'medical') $badge_class = 'mature-badge-crimson';
                        elseif ($h['contact_type'] === 'fire') $badge_class = 'mature-badge-amber';
                        elseif ($h['contact_type'] === 'police') $badge_class = 'mature-badge-primary';
                        elseif ($h['contact_type'] === 'management') $badge_class = 'mature-badge-emerald';
                        ?>
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="p-3 border rounded-3 bg-white h-100 d-flex flex-column justify-content-between shadow-sm">
                                <div class="d-flex align-items-start justify-content-between gap-2 mb-3">
                                    <div>
                                        <span class="mature-badge <?php echo $badge_class; ?> mb-2 text-uppercase" style="font-size: 0.68rem;">
                                            <?php echo str_replace('_', ' ', htmlspecialchars($h['contact_type'])); ?>
                                        </span>
                                        <h6 class="fw-bold text-slate-900 mb-0"><?php echo htmlspecialchars($h['label']); ?></h6>
                                    </div>
                                    <div class="kpi-icon-wrap" style="width: 36px; height: 36px; font-size: 0.95rem;">
                                        <i class="fa-solid fa-phone text-secondary"></i>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center justify-content-between pt-2 border-top border-light-subtle">
                                    <span class="fw-bold text-slate-800 font-monospace" style="font-size: 0.95rem;"><?php echo htmlspecialchars($h['phone_number']); ?></span>
                                    <a href="tel:<?php echo htmlspecialchars($h['phone_number']); ?>" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold">
                                        <i class="fa-solid fa-phone me-1"></i> Call Now
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12 text-center py-4 text-muted">
                        No emergency hotlines configured yet. Central security is standing by.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ==========================================
         ON DUTY SECURITY PERSONNEL RIGHT NOW
         ========================================== -->
    <div id="on-duty-guards" class="mature-card">
        <div class="mature-card-header">
            <div>
                <h5 class="mature-card-title">
                    <i class="fa-solid fa-user-shield text-success"></i> Security Personnel On Duty Right Now
                </h5>
                <small class="text-secondary">Officers currently stationed at the gates, main checkpoints, and patrol beats</small>
            </div>
            <span class="mature-badge mature-badge-emerald">
                <i class="fa-solid fa-circle me-1" style="font-size: 0.45rem;"></i> <?php echo count($guards_on_duty); ?> Active On Post
            </span>
        </div>

        <div class="mature-card-body">
            <div class="row g-3">
                <?php if (!empty($guards_on_duty)): ?>
                    <?php foreach ($guards_on_duty as $g): ?>
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="p-3 border rounded-3 bg-white d-flex align-items-center justify-content-between shadow-sm">
                                <div class="d-flex align-items-center gap-3">
                                    <div style="width: 42px; height: 42px; border-radius: 50%; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; font-weight: 700; flex-shrink: 0;">
                                        <i class="fa-solid fa-user-shield"></i>
                                    </div>
                                    <div>
                                        <strong class="text-slate-900 d-block" style="font-size: 0.92rem;"><?php echo htmlspecialchars($g['officer_name']); ?></strong>
                                        <span class="mature-badge mature-badge-primary mb-1" style="font-size: 0.68rem;">
                                            <?php echo htmlspecialchars($g['post_name'] ?? 'Main Gate'); ?>
                                        </span>
                                        <div class="text-secondary small" style="font-size: 0.75rem;"><?php echo htmlspecialchars($g['shift_name'] ?? 'Current Shift'); ?></div>
                                    </div>
                                </div>
                                <?php if (!empty($g['officer_phone'])): ?>
                                    <a href="tel:<?php echo htmlspecialchars($g['officer_phone']); ?>" class="btn btn-sm btn-outline-success rounded-pill px-3" style="font-size: 0.78rem;">
                                        <i class="fa-solid fa-phone me-1"></i> Call
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12 text-center py-4 text-muted">
                        No active roster shifts assigned for the current hour. Central control room is on standby.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ==========================================
         RECENT EMERGENCY LOGS & ADVISORIES
         ========================================== -->
    <div class="mature-card">
        <div class="mature-card-header">
            <h5 class="mature-card-title">
                <i class="fa-solid fa-clock-rotate-left text-primary"></i> Recent Emergency Logs &amp; Advisories
            </h5>
            <span class="mature-badge mature-badge-slate">Incident History</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small text-secondary text-uppercase">
                    <tr>
                        <th>Alert Code</th>
                        <th>Type / Category</th>
                        <th>Scope / Sender</th>
                        <th>Status</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($my_alerts && $my_alerts->num_rows > 0): ?>
                        <?php while ($al = $my_alerts->fetch_assoc()): ?>
                            <tr>
                                <td><span class="fw-bold font-monospace text-primary"><?php echo htmlspecialchars($al['alert_code']); ?></span></td>
                                <td>
                                    <span class="mature-badge mature-badge-crimson">
                                        <?php echo htmlspecialchars($al['category_name']); ?>
                                    </span>
                                    <?php if (!empty($al['headline'])): ?>
                                        <div class="small text-secondary mt-1"><?php echo htmlspecialchars($al['headline']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($al['sender_type'] === 'resident'): ?>
                                        <span class="mature-badge mature-badge-slate">My SOS Alert</span>
                                    <?php else: ?>
                                        <span class="mature-badge mature-badge-amber">Management Broadcast</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $st = $al['status'];
                                    $badge = 'mature-badge-amber';
                                    if ($st === 'resolved') $badge = 'mature-badge-emerald';
                                    elseif ($st === 'false_alarm') $badge = 'mature-badge-slate';
                                    elseif ($st === 'dispatched') $badge = 'mature-badge-primary';
                                    ?>
                                    <span class="mature-badge <?php echo $badge; ?> text-uppercase" style="font-size: 0.7rem;">
                                        <?php echo htmlspecialchars($st); ?>
                                    </span>
                                </td>
                                <td class="text-secondary small">
                                    <?php echo date('M d, Y - h:i A', strtotime($al['created_at'])); ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">No emergency records logged yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php 
include 'footer.php'; 
?>
