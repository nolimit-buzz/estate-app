<?php
// admin/emergency.php
// Central Emergency Command, Panic Broadcast, Dynamic Actions & Settings Hub

require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/emergency_roster_init.php';
require_once '../includes/Mailer.php';
require_once '../includes/NoticeManager.php';
requireAdminAccess();

$estate_id = get_estate_id();
$user_id = $_SESSION['user_id'];
$message = "";
$error = "";
$active_tab = $_GET['tab'] ?? 'monitor'; // 'monitor', 'categories', 'actions', 'settings', 'hotlines', 'history'

// -------------------------------------------------------------
// POST ACTIONS: TRIGGER CENTRAL EMERGENCY PANIC BROADCAST
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_trigger_broadcast'])) {
    $category_name = $conn->real_escape_string(trim($_POST['category_name']));
    $headline = $conn->real_escape_string(trim($_POST['headline']));
    $note = $conn->real_escape_string(trim($_POST['note']));
    $target_scope = ($_POST['target_scope'] === 'zone') ? 'zone' : 'estate_wide';
    $target_stakeholders = in_array($_POST['target_stakeholders'] ?? '', ['all_residents', 'guards_only', 'guards_and_admin', 'guards_and_medical']) ? $_POST['target_stakeholders'] : 'all_residents';
    $zone_id = (!empty($_POST['zone_id']) && $target_scope === 'zone') ? intval($_POST['zone_id']) : 'NULL';
    $sound_alarm = isset($_POST['sound_alarm']) ? 1 : 0;

    $user_info = $conn->query("SELECT name, phone FROM users WHERE id = $user_id LIMIT 1")->fetch_assoc();
    $sender_name = $conn->real_escape_string($user_info['name'] ?? 'Estate Management');
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
    if ($guards_res) {
        while ($g = $guards_res->fetch_assoc()) $guards[] = $g;
    }
    $guards_json = $conn->real_escape_string(json_encode($guards, JSON_UNESCAPED_SLASHES));

    $alert_code = "SOS-" . date('ymd') . "-" . rand(100, 999);

    $sql = "INSERT INTO estate_emergency_alerts (
        estate_id, alert_code, sender_type, sender_id, sender_name, sender_phone,
        target_scope, target_stakeholders, zone_id, category_name, headline, note, status, sound_alarm,
        security_officers_on_duty_snapshot
    ) VALUES (
        $estate_id, '$alert_code', 'central_admin', $user_id, '$sender_name', '$sender_phone',
        '$target_scope', '$target_stakeholders', $zone_id, '$category_name', '$headline', '$note', 'active', $sound_alarm,
        '$guards_json'
    )";

    if ($conn->query($sql)) {
        $alert_id = $conn->insert_id;
        logAudit($conn, "Emergency Broadcast Triggered", "Emergency", "Central Admin triggered emergency broadcast $alert_code ($category_name). Scope: $target_scope. Target: $target_stakeholders.");
        
        // Auto broadcast
        NoticeManager::publishNotice($conn, [
            'estate_id' => $estate_id,
            'zone_id' => ($target_scope === 'zone' && $zone_id !== 'NULL') ? $zone_id : null,
            'title' => "ESTATE EMERGENCY BROADCAST: $category_name ($alert_code)",
            'content' => "Advisory: $headline\nDetails: $note",
            'target_audience' => ($target_stakeholders === 'all_residents') ? 'all' : 'residents',
            'priority' => 'urgent',
            'sender_type' => 'admin',
            'sender_name' => 'Central Administration',
            'created_by' => $user_id,
            'pin_to_top' => 1,
            'dispatch_notification' => true,
            'dispatch_email' => false
        ]);

        $message = "Emergency Panic Broadcast ($alert_code) dispatched immediately! Sirens, banners and notices activated.";
        $active_tab = 'monitor';
    } else {
        $error = "Error triggering broadcast: " . $conn->error;
    }
}

// -------------------------------------------------------------
// POST ACTIONS: MANAGE CATEGORIES (Clean & Icon-Free)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_category'])) {
    $cat_name = $conn->real_escape_string(trim($_POST['category_name']));
    $color = $conn->real_escape_string(trim($_POST['color'] ?? '#dc2626'));
    $desc = $conn->real_escape_string(trim($_POST['use_case_description']));
    $priority = in_array($_POST['priority'], ['critical', 'high', 'medium']) ? $_POST['priority'] : 'critical';
    $target_stakeholders = in_array($_POST['target_stakeholders'], ['all_residents', 'guards_only', 'guards_and_admin', 'guards_and_medical']) ? $_POST['target_stakeholders'] : 'all_residents';
    $phone = $conn->real_escape_string(trim($_POST['emergency_contact_phone'] ?? ''));
    $order = intval($_POST['display_order'] ?? 0);

    if (!empty($_POST['category_id'])) {
        $cid = intval($_POST['category_id']);
        $sql = "UPDATE estate_emergency_categories SET 
                category_name = '$cat_name', color = '$color',
                use_case_description = '$desc', priority = '$priority', target_stakeholders = '$target_stakeholders',
                emergency_contact_phone = '$phone', display_order = $order
                WHERE id = $cid AND estate_id = $estate_id";
        $conn->query($sql);
        $message = "Emergency category updated successfully!";
    } else {
        $sql = "INSERT INTO estate_emergency_categories (estate_id, category_name, color, use_case_description, priority, target_stakeholders, emergency_contact_phone, display_order)
                VALUES ($estate_id, '$cat_name', '$color', '$desc', '$priority', '$target_stakeholders', '$phone', $order)";
        $conn->query($sql);
        $message = "New emergency category created successfully!";
    }
    $active_tab = 'categories';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete_category'])) {
    $cid = intval($_POST['category_id']);
    $conn->query("DELETE FROM estate_emergency_categories WHERE id = $cid AND estate_id = $estate_id");
    $message = "Emergency category removed.";
    $active_tab = 'categories';
}

// -------------------------------------------------------------
// POST ACTIONS: MANAGE RESPONSE ACTIONS (Action Taken Templates)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_response_action'])) {
    $title = $conn->real_escape_string(trim($_POST['action_title']));
    $desc = $conn->real_escape_string(trim($_POST['description'] ?? ''));
    $target_status = in_array($_POST['target_status'], ['resolved', 'dispatched', 'false_alarm']) ? $_POST['target_status'] : 'resolved';
    $order = intval($_POST['display_order'] ?? 0);

    if (!empty($_POST['action_id'])) {
        $aid = intval($_POST['action_id']);
        $sql = "UPDATE estate_emergency_actions SET 
                action_title = '$title', description = '$desc', target_status = '$target_status', display_order = $order
                WHERE id = $aid AND estate_id = $estate_id";
        $conn->query($sql);
        $message = "Response action template updated!";
    } else {
        $sql = "INSERT INTO estate_emergency_actions (estate_id, action_title, description, target_status, display_order)
                VALUES ($estate_id, '$title', '$desc', '$target_status', $order)";
        $conn->query($sql);
        $message = "New response action template registered!";
    }
    $active_tab = 'actions';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete_response_action'])) {
    $aid = intval($_POST['action_id']);
    $conn->query("DELETE FROM estate_emergency_actions WHERE id = $aid AND estate_id = $estate_id");
    $message = "Response action template removed.";
    $active_tab = 'actions';
}

// -------------------------------------------------------------
// POST ACTIONS: SAVE EMERGENCY SYSTEM SETTINGS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_emergency_settings'])) {
    $countdown = max(1, min(60, intval($_POST['emergency_countdown_seconds'] ?? 5)));
    $auto_b = isset($_POST['emergency_auto_broadcast']) ? '1' : '0';
    $auto_e = isset($_POST['emergency_auto_email']) ? '1' : '0';
    $phones = $conn->real_escape_string(trim($_POST['emergency_stakeholder_phones'] ?? ''));
    $emails = $conn->real_escape_string(trim($_POST['emergency_stakeholder_emails'] ?? ''));

    $settings_to_update = [
        'emergency_countdown_seconds' => strval($countdown),
        'emergency_auto_broadcast' => $auto_b,
        'emergency_auto_email' => $auto_e,
        'emergency_stakeholder_phones' => $phones,
        'emergency_stakeholder_emails' => $emails
    ];

    foreach ($settings_to_update as $k => $v) {
        $chk = $conn->query("SELECT 1 FROM system_settings WHERE estate_id = $estate_id AND setting_key = '$k' LIMIT 1");
        if ($chk && $chk->num_rows > 0) {
            $conn->query("UPDATE system_settings SET setting_value = '$v' WHERE estate_id = $estate_id AND setting_key = '$k'");
        } else {
            $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES ($estate_id, '$k', '$v')");
        }
    }

    $message = "Emergency system settings updated successfully!";
    $active_tab = 'settings';
}

// -------------------------------------------------------------
// POST ACTIONS: MANAGE HOTLINES
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_hotline'])) {
    $label = $conn->real_escape_string(trim($_POST['label']));
    $phone = $conn->real_escape_string(trim($_POST['phone_number']));
    $typ = in_array($_POST['contact_type'], ['internal_security', 'medical', 'fire', 'police', 'management', 'general']) ? $_POST['contact_type'] : 'internal_security';
    $is_prim = isset($_POST['is_primary']) ? 1 : 0;
    $order = intval($_POST['display_order'] ?? 0);

    if (!empty($_POST['hotline_id'])) {
        $hid = intval($_POST['hotline_id']);
        $sql = "UPDATE estate_emergency_contacts SET 
                label = '$label', phone_number = '$phone', contact_type = '$typ', is_primary = $is_prim, display_order = $order
                WHERE id = $hid AND estate_id = $estate_id";
        $conn->query($sql);
        $message = "Emergency hotline updated!";
    } else {
        $sql = "INSERT INTO estate_emergency_contacts (estate_id, label, phone_number, contact_type, is_primary, display_order)
                VALUES ($estate_id, '$label', '$phone', '$typ', $is_prim, $order)";
        $conn->query($sql);
        $message = "New emergency hotline registered!";
    }
    $active_tab = 'hotlines';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete_hotline'])) {
    $hid = intval($_POST['hotline_id']);
    $conn->query("DELETE FROM estate_emergency_contacts WHERE id = $hid AND estate_id = $estate_id");
    $message = "Hotline deleted from directory.";
    $active_tab = 'hotlines';
}

// Fetch Active Alerts
$active_alerts = $conn->query("SELECT ea.*, TIMESTAMPDIFF(SECOND, ea.created_at, NOW()) as seconds_ago 
                               FROM estate_emergency_alerts ea 
                               WHERE ea.estate_id = $estate_id AND ea.status IN ('active', 'acknowledged', 'dispatched') 
                               ORDER BY ea.created_at DESC");

// Fetch Alert History
$history_alerts = $conn->query("SELECT * FROM estate_emergency_alerts WHERE estate_id = $estate_id ORDER BY created_at DESC LIMIT 50");

// Fetch Categories, Hotlines, Actions, Zones & Settings
$categories = $conn->query("SELECT * FROM estate_emergency_categories WHERE estate_id = $estate_id ORDER BY display_order ASC");
$hotlines = $conn->query("SELECT * FROM estate_emergency_contacts WHERE estate_id = $estate_id ORDER BY display_order ASC");
$actions = $conn->query("SELECT * FROM estate_emergency_actions WHERE estate_id = $estate_id ORDER BY display_order ASC");
$zones = $conn->query("SELECT * FROM zones WHERE estate_id = $estate_id ORDER BY name ASC");

// Fetch System Settings
$cfg_countdown = 5;
$cfg_auto_b = '1';
$cfg_auto_e = '1';
$cfg_phones = '';
$cfg_emails = '';

$set_res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE estate_id = $estate_id AND setting_key LIKE 'emergency_%'");
if ($set_res) {
    while ($srow = $set_res->fetch_assoc()) {
        if ($srow['setting_key'] === 'emergency_countdown_seconds') $cfg_countdown = intval($srow['setting_value']);
        elseif ($srow['setting_key'] === 'emergency_auto_broadcast') $cfg_auto_b = $srow['setting_value'];
        elseif ($srow['setting_key'] === 'emergency_auto_email') $cfg_auto_e = $srow['setting_value'];
        elseif ($srow['setting_key'] === 'emergency_stakeholder_phones') $cfg_phones = $srow['setting_value'];
        elseif ($srow['setting_key'] === 'emergency_stakeholder_emails') $cfg_emails = $srow['setting_value'];
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="d-flex flex-column gap-4">
    <!-- ==========================================
         EXECUTIVE HEADER & COMMAND TOOLBAR
         ========================================== -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-2 pb-2 border-bottom border-light-subtle">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <h1 class="h4 font-bold text-slate-900 m-0" style="letter-spacing: -0.02em;">
                    Estate Emergency &amp; Panic Operations
                </h1>
                <span class="mature-badge mature-badge-crimson">
                    <i class="fa-solid fa-tower-broadcast me-1"></i> SOS Command
                </span>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2 text-secondary small">
                <span><i class="fa-regular fa-calendar me-1"></i> <?php echo date('l, F j, Y'); ?></span>
                <span>•</span>
                <span><i class="fa-solid fa-shield-halved me-1"></i> Multi-Alarm Dispatch Deck</span>
                <span>•</span>
                <span class="text-success"><i class="fa-solid fa-circle me-1" style="font-size: 0.55rem;"></i> System Live</span>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-sm btn-danger px-3 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#panicBroadcastModal" style="background: #e11d48; border-color: #e11d48;">
                <i class="fa-solid fa-bullhorn me-1"></i> Trigger Estate Broadcast
            </button>
            <button type="button" onclick="toggleEstateSirenMute()" id="estate-alarm-mute-btn" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-volume-high me-1"></i> Toggle Siren
            </button>
            <a href="roster" class="btn btn-sm btn-outline-primary">
                <i class="fa-solid fa-calendar-days me-1"></i> Duty Roster
            </a>
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
        <!-- Pillar 1: Active Incidents -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="kpi-title">Active Incidents</span>
                        <div class="kpi-value text-danger" style="font-size: 1.85rem; font-weight: 800;">
                            <?php echo ($active_alerts ? $active_alerts->num_rows : 0); ?>
                        </div>
                    </div>
                    <div class="kpi-icon-wrap" style="background: #fff1f2; color: #e11d48; border-color: #fecdd3;">
                        <i class="fa-solid fa-radiation"></i>
                    </div>
                </div>
                <div class="kpi-meta justify-content-between mt-2">
                    <span>Live Emergency Feed</span>
                    <?php if ($active_alerts && $active_alerts->num_rows > 0): ?>
                        <span class="mature-badge mature-badge-crimson"><i class="fa-solid fa-bell me-1"></i>Active</span>
                    <?php else: ?>
                        <span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-check me-1"></i>Clear</span>
                    <?php endif; ?>
                </div>
                <div class="kpi-progress-bar">
                    <div class="kpi-progress-fill" style="width: <?php echo ($active_alerts && $active_alerts->num_rows > 0) ? '100%' : '10%'; ?>; background: <?php echo ($active_alerts && $active_alerts->num_rows > 0) ? '#e11d48' : '#16a34a'; ?>;"></div>
                </div>
            </div>
        </div>

        <!-- Pillar 2: Pre-Alarm Countdown -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="kpi-title">Pre-Alarm Delay</span>
                        <div class="kpi-value"><?php echo $cfg_countdown; ?>s <span style="font-size: 0.95rem; font-weight: 500; color: #64748b;">Delay</span></div>
                    </div>
                    <div class="kpi-icon-wrap" style="background: #fefce8; color: #ca8a04; border-color: #fef08a;">
                        <i class="fa-solid fa-stopwatch"></i>
                    </div>
                </div>
                <div class="kpi-meta justify-content-between mt-2">
                    <span>False-Alarm Protection</span>
                    <span class="mature-badge mature-badge-amber">Configured</span>
                </div>
                <div class="kpi-progress-bar">
                    <div class="kpi-progress-fill" style="width: 100%; background: #ca8a04;"></div>
                </div>
            </div>
        </div>

        <!-- Pillar 3: Emergency Categories -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="kpi-title">Incident Categories</span>
                        <div class="kpi-value"><?php echo ($categories ? $categories->num_rows : 0); ?> <span style="font-size: 0.95rem; font-weight: 500; color: #64748b;">Routing</span></div>
                    </div>
                    <div class="kpi-icon-wrap" style="background: #eff6ff; color: #2563eb; border-color: #dbeafe;">
                        <i class="fa-solid fa-shapes"></i>
                    </div>
                </div>
                <div class="kpi-meta justify-content-between mt-2">
                    <span>Stakeholder Targets</span>
                    <span class="mature-badge mature-badge-primary">Active</span>
                </div>
                <div class="kpi-progress-bar">
                    <div class="kpi-progress-fill" style="width: 100%;"></div>
                </div>
            </div>
        </div>

        <!-- Pillar 4: Response Actions -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="kpi-title">Response Action Presets</span>
                        <div class="kpi-value"><?php echo ($actions ? $actions->num_rows : 0); ?> <span style="font-size: 0.95rem; font-weight: 500; color: #64748b;">Presets</span></div>
                    </div>
                    <div class="kpi-icon-wrap" style="background: #f0fdf4; color: #16a34a; border-color: #dcfce7;">
                        <i class="fa-solid fa-list-check"></i>
                    </div>
                </div>
                <div class="kpi-meta justify-content-between mt-2">
                    <span>Closing Workflow</span>
                    <span class="mature-badge mature-badge-emerald">Standardized</span>
                </div>
                <div class="kpi-progress-bar">
                    <div class="kpi-progress-fill" style="width: 100%; background: #16a34a;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-pills-custom gap-2 mb-1">
        <li class="nav-item">
            <a href="?tab=monitor" class="nav-link <?php echo ($active_tab === 'monitor') ? 'active' : ''; ?>">
                <i class="fa-solid fa-tower-broadcast me-1"></i> Live Incidents
                <?php if ($active_alerts && $active_alerts->num_rows > 0): ?>
                    <span class="badge bg-danger text-white rounded-pill ms-1"><?php echo $active_alerts->num_rows; ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li class="nav-item">
            <a href="?tab=categories" class="nav-link <?php echo ($active_tab === 'categories') ? 'active' : ''; ?>">
                <i class="fa-solid fa-shapes me-1"></i> Categories &amp; Stakeholders
            </a>
        </li>
        <li class="nav-item">
            <a href="?tab=actions" class="nav-link <?php echo ($active_tab === 'actions') ? 'active' : ''; ?>">
                <i class="fa-solid fa-list-check me-1"></i> Response Actions
            </a>
        </li>
        <li class="nav-item">
            <a href="?tab=settings" class="nav-link <?php echo ($active_tab === 'settings') ? 'active' : ''; ?>">
                <i class="fa-solid fa-gear me-1"></i> Timing &amp; Broadcast Settings
            </a>
        </li>
        <li class="nav-item">
            <a href="?tab=hotlines" class="nav-link <?php echo ($active_tab === 'hotlines') ? 'active' : ''; ?>">
                <i class="fa-solid fa-phone-volume me-1"></i> Direct Hotlines
            </a>
        </li>
        <li class="nav-item">
            <a href="?tab=history" class="nav-link <?php echo ($active_tab === 'history') ? 'active' : ''; ?>">
                <i class="fa-solid fa-clock-rotate-left me-1"></i> Incident Archives
            </a>
        </li>
    </ul>

    <!-- ========================================================
         TAB 1: LIVE INCIDENTS MONITOR
         ======================================================== -->
    <?php if ($active_tab === 'monitor'): ?>
        <div class="mature-card p-4">
            <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold mb-0 text-dark" style="font-family: 'Outfit', sans-serif;">
                        <i class="fa-solid fa-radiation text-danger me-2"></i> Active Emergency Incidents
                    </h5>
                    <small class="text-secondary">Real-time alerts triggered by residents, zone administrators, or central command</small>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" onclick="location.reload()" class="btn btn-sm btn-light border rounded-pill px-3">
                        <i class="fa-solid fa-arrows-rotate me-1"></i> Refresh Feed
                    </button>
                </div>
            </div>

            <?php if ($active_alerts && $active_alerts->num_rows > 0): ?>
                <div class="row g-3">
                    <?php while ($al = $active_alerts->fetch_assoc()): ?>
                        <?php 
                        $sec = intval($al['seconds_ago']);
                        $time_label = ($sec < 60) ? "$sec sec ago" : floor($sec / 60) . " min ago";
                        $guards_snapshot = json_decode($al['security_officers_on_duty_snapshot'] ?? '[]', true);
                        
                        $wa_share = "🚨 *ESTATE EMERGENCY ALERT* 🚨\nCode: " . $al['alert_code'] . "\nCategory: " . $al['category_name'] . "\nLocation: " . ($al['building_name'] ? $al['building_name'] . ' Unit ' . $al['flat_number'] : 'Estate Grounds') . "\nResident: " . $al['sender_name'] . " (" . $al['sender_phone'] . ")\nTime: " . date('h:i A', strtotime($al['created_at']));
                        $wa_url = "https://api.whatsapp.com/send?text=" . urlencode($wa_share);
                        ?>
                        <div class="col-12">
                            <div class="border border-danger border-2 rounded-4 p-4 shadow-sm" style="background: #fffafa;">
                                <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
                                    <div class="d-flex align-items-center gap-3">
                                        <div style="width: 50px; height: 50px; border-radius: 14px; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; font-weight: 800;">
                                            SOS
                                        </div>
                                        <div>
                                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                                <h5 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($al['category_name']); ?></h5>
                                                <span class="badge bg-danger rounded-pill"><?php echo htmlspecialchars($al['alert_code']); ?></span>
                                                <span class="badge bg-warning text-dark rounded-pill"><?php echo strtoupper($al['status']); ?></span>
                                                <span class="badge bg-light text-secondary border rounded-pill text-uppercase" style="font-size: 0.68rem;">
                                                    Audience: <?php echo str_replace('_', ' ', htmlspecialchars($al['target_stakeholders'] ?? 'all_residents')); ?>
                                                </span>
                                            </div>
                                            <div class="text-secondary small mt-1">
                                                <i class="fa-solid fa-clock me-1 text-danger"></i> Triggered <strong><?php echo $time_label; ?></strong> (<?php echo date('h:i A', strtotime($al['created_at'])); ?>)
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <!-- Early Silence Siren Button -->
                                        <?php if ($al['sound_alarm'] == 1): ?>
                                            <button type="button" onclick="silenceEmergencySiren(<?php echo $al['id']; ?>)" class="btn btn-sm btn-outline-danger fw-bold rounded-pill px-3">
                                                <i class="fa-solid fa-bell-slash me-1"></i> Silence Siren
                                            </button>
                                        <?php else: ?>
                                            <span class="badge bg-secondary text-white rounded-pill px-2 py-1 small"><i class="fa-solid fa-volume-xmark me-1"></i> Siren Silenced</span>
                                        <?php endif; ?>

                                        <!-- WhatsApp Share -->
                                        <a href="<?php echo $wa_url; ?>" target="_blank" class="btn btn-sm btn-success rounded-pill px-3 fw-bold">
                                            <i class="fa-brands fa-whatsapp me-1"></i> WhatsApp
                                        </a>

                                        <!-- Dispatch / Acknowledge -->
                                        <?php if ($al['status'] === 'active'): ?>
                                            <button type="button" onclick="quickAcknowledgeEmergency(<?php echo $al['id']; ?>)" class="btn btn-sm btn-warning text-dark fw-bold rounded-pill px-3">
                                                <i class="fa-solid fa-person-running me-1"></i> Dispatch Security
                                            </button>
                                        <?php endif; ?>

                                        <!-- Close / Resolve Modal Trigger -->
                                        <button type="button" onclick="openResolveModal(<?php echo $al['id']; ?>, '<?php echo htmlspecialchars($al['alert_code']); ?>', '<?php echo htmlspecialchars($al['category_name']); ?>')" class="btn btn-sm btn-success text-white fw-bold rounded-pill px-3">
                                            <i class="fa-solid fa-check-double me-1"></i> Take Action / Resolve
                                        </button>
                                    </div>
                                </div>

                                <!-- Incident Context -->
                                <div class="row g-3 pt-3 border-top">
                                    <div class="col-12 col-md-4">
                                        <label class="small text-secondary text-uppercase fw-bold d-block">Source / Initiator</label>
                                        <strong class="text-dark"><?php echo htmlspecialchars($al['sender_name']); ?></strong>
                                        <span class="badge bg-light text-dark border ms-1"><?php echo ucfirst($al['sender_type']); ?></span>
                                        <?php if (!empty($al['sender_phone'])): ?>
                                            <div class="mt-1">
                                                <a href="tel:<?php echo htmlspecialchars($al['sender_phone']); ?>" class="btn btn-xs btn-outline-primary rounded-pill px-2 py-1" style="font-size: 0.75rem;">
                                                    <i class="fa-solid fa-phone me-1"></i> <?php echo htmlspecialchars($al['sender_phone']); ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label class="small text-secondary text-uppercase fw-bold d-block">Location / Residence</label>
                                        <?php if (!empty($al['building_name'])): ?>
                                            <strong class="text-dark"><?php echo htmlspecialchars($al['building_name']); ?> &bull; Unit <?php echo htmlspecialchars($al['flat_number']); ?></strong>
                                        <?php else: ?>
                                            <strong class="text-dark"><?php echo ($al['target_scope'] === 'zone') ? 'Zonal Scoped Broadcast' : 'Estate-Wide Incident'; ?></strong>
                                        <?php endif; ?>
                                        <?php if (!empty($al['headline'])): ?>
                                            <div class="text-danger small mt-1 fw-bold"><?php echo htmlspecialchars($al['headline']); ?></div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label class="small text-secondary text-uppercase fw-bold d-block">On-Duty Security Snapshot</label>
                                        <?php if (!empty($guards_snapshot)): ?>
                                            <div class="d-flex flex-wrap gap-1 mt-1">
                                                <?php foreach ($guards_snapshot as $gs): ?>
                                                    <span class="badge bg-light text-secondary border" style="font-size: 0.72rem;">
                                                        <i class="fa-solid fa-shield-halved text-success me-1"></i> <?php echo htmlspecialchars($gs['officer_name']); ?> (<?php echo htmlspecialchars($gs['post_name'] ?? 'Gate'); ?>)
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">No guards were on duty snapshot</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <div style="width: 70px; height: 70px; border-radius: 50%; background: #ecfdf5; color: #10b981; display: inline-flex; align-items: center; justify-content: center; font-size: 2rem;" class="mb-3">
                        <i class="fa-solid fa-shield-check"></i>
                    </div>
                    <h5 class="fw-bold text-dark">All Clear — No Active Emergencies</h5>
                    <p class="text-secondary small mb-0">The estate is safe. Active alarms and emergency triggers will appear here in real time with audible sirens and multi-channel notifications.</p>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ========================================================
         TAB 2: EMERGENCY CATEGORIES & STAKEHOLDER ROUTING (Icon-Free)
         ======================================================== -->
    <?php if ($active_tab === 'categories'): ?>
        <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
            <div class="d-flex align-items-center justify-content-between mb-4 pb-2 border-bottom flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold mb-0 text-dark" style="font-family: 'Outfit', sans-serif;">
                        <i class="fa-solid fa-shapes text-primary me-2"></i> Estate Emergency Categories &amp; Stakeholder Routing
                    </h5>
                    <small class="text-secondary">Configure emergency types and designate which stakeholders (Guards only, Medical, or All Residents) receive the alert.</small>
                </div>
                <button type="button" class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                    <i class="fa-solid fa-plus me-1"></i> Add Emergency Category
                </button>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small text-secondary text-uppercase">
                        <tr>
                            <th>Category Title</th>
                            <th>Target Stakeholders</th>
                            <th>Description</th>
                            <th>Priority</th>
                            <th>Direct Phone</th>
                            <th>Order</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($categories && $categories->num_rows > 0): ?>
                            <?php while ($c = $categories->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span style="display:inline-block; width: 12px; height: 12px; border-radius: 3px; background: <?php echo $c['color']; ?>;"></span>
                                            <strong class="text-dark"><?php echo htmlspecialchars($c['category_name']); ?></strong>
                                        </div>
                                    </td>
                                    <td>
                                        <?php 
                                        $stk = $c['target_stakeholders'] ?? 'all_residents';
                                        $badge_col = 'bg-primary';
                                        if ($stk === 'guards_only') $badge_col = 'bg-dark';
                                        elseif ($stk === 'guards_and_admin') $badge_col = 'bg-info text-dark';
                                        elseif ($stk === 'guards_and_medical') $badge_col = 'bg-danger';
                                        ?>
                                        <span class="badge <?php echo $badge_col; ?> rounded-pill text-uppercase" style="font-size: 0.68rem;">
                                            <?php echo str_replace('_', ' ', htmlspecialchars($stk)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-secondary small"><?php echo htmlspecialchars($c['use_case_description']); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo ($c['priority'] === 'critical') ? 'bg-danger' : (($c['priority'] === 'high') ? 'bg-warning text-dark' : 'bg-info text-dark'); ?> rounded-pill text-uppercase" style="font-size: 0.68rem;">
                                            <?php echo htmlspecialchars($c['priority']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="font-monospace text-secondary small"><?php echo htmlspecialchars($c['emergency_contact_phone'] ?? '-'); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?php echo intval($c['display_order']); ?></span>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" onsubmit="return confirm('Delete this category?');" class="d-inline">
                                            <input type="hidden" name="action_delete_category" value="1">
                                            <input type="hidden" name="category_id" value="<?php echo $c['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No categories configured.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- ========================================================
         TAB 3: RESPONSE ACTIONS (Action Taken Templates)
         ======================================================== -->
    <?php if ($active_tab === 'actions'): ?>
        <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
            <div class="d-flex align-items-center justify-content-between mb-4 pb-2 border-bottom flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold mb-0 text-dark" style="font-family: 'Outfit', sans-serif;">
                        <i class="fa-solid fa-list-check text-success me-2"></i> Configurable Response Actions ("Action Taken" Templates)
                    </h5>
                    <small class="text-secondary">Preset response choices available when closing or updating emergency incidents (e.g. Patrol Dispatched, Paramedics Assisted, Verified False Alarm).</small>
                </div>
                <button type="button" class="btn btn-success rounded-pill px-4 text-white" data-bs-toggle="modal" data-bs-target="#addActionModal">
                    <i class="fa-solid fa-plus me-1"></i> Add Action Preset
                </button>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small text-secondary text-uppercase">
                        <tr>
                            <th>Action Title</th>
                            <th>Description</th>
                            <th>Default Incident Status</th>
                            <th>Order</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($actions && $actions->num_rows > 0): ?>
                            <?php while ($act = $actions->fetch_assoc()): ?>
                                <tr>
                                    <td><strong class="text-dark"><?php echo htmlspecialchars($act['action_title']); ?></strong></td>
                                    <td><span class="text-secondary small"><?php echo htmlspecialchars($act['description']); ?></span></td>
                                    <td>
                                        <span class="badge <?php echo ($act['target_status'] === 'resolved') ? 'bg-success' : (($act['target_status'] === 'dispatched') ? 'bg-warning text-dark' : 'bg-secondary'); ?> rounded-pill text-uppercase" style="font-size: 0.68rem;">
                                            <?php echo htmlspecialchars($act['target_status']); ?>
                                        </span>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?php echo intval($act['display_order']); ?></span></td>
                                    <td class="text-end">
                                        <form method="POST" onsubmit="return confirm('Delete this response action?');" class="d-inline">
                                            <input type="hidden" name="action_delete_response_action" value="1">
                                            <input type="hidden" name="action_id" value="<?php echo $act['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">No response action presets registered yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- ========================================================
         TAB 4: TIMING, COUNTDOWN & BROADCAST SETTINGS
         ======================================================== -->
    <?php if ($active_tab === 'settings'): ?>
        <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
            <h5 class="fw-bold mb-1 text-dark" style="font-family: 'Outfit', sans-serif;">
                <i class="fa-solid fa-gear text-primary me-2"></i> Emergency Timing, Countdown &amp; Broadcast Automations
            </h5>
            <p class="text-secondary small mb-4 pb-2 border-bottom">Control the safety countdown delay before siren sounds, automatic email dispatches, and stakeholder contact preferences.</p>

            <form method="POST" class="row g-4">
                <input type="hidden" name="action_save_emergency_settings" value="1">
                
                <div class="col-12 col-md-6">
                    <label class="form-label fw-bold text-dark">Safety Countdown Timing (Seconds) *</label>
                    <div class="input-group">
                        <input type="number" name="emergency_countdown_seconds" min="1" max="60" value="<?php echo $cfg_countdown; ?>" class="form-control rounded-start-3" required>
                        <span class="input-group-text bg-light">Seconds delay</span>
                    </div>
                    <small class="text-muted">Number of seconds residents have on the confirmation screen before the SOS alarm and sirens blare out.</small>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label fw-bold text-dark">Major Stakeholder Notification Emails</label>
                    <input type="text" name="emergency_stakeholder_emails" value="<?php echo htmlspecialchars($cfg_emails); ?>" placeholder="e.g. cso@estate.com, chairman@estate.com" class="form-control rounded-3">
                    <small class="text-muted">Comma-separated email addresses to receive instant detailed incident reports upon trigger.</small>
                </div>

                <div class="col-12 col-md-6">
                    <div class="form-check form-switch p-3 bg-light rounded-3">
                        <input class="form-check-input ms-0 me-3" type="checkbox" name="emergency_auto_broadcast" value="1" id="auto_b_switch" <?php echo ($cfg_auto_b == '1') ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold text-dark" for="auto_b_switch">
                            Automatically Publish In-App Broadcast Notice
                        </label>
                        <div class="small text-secondary">Instantly creates an in-app notice and alert notification for connected estate residents when an SOS is dispatched.</div>
                    </div>
                </div>

                <div class="col-12 col-md-6">
                    <div class="form-check form-switch p-3 bg-light rounded-3">
                        <input class="form-check-input ms-0 me-3" type="checkbox" name="emergency_auto_email" value="1" id="auto_e_switch" <?php echo ($cfg_auto_e == '1') ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold text-dark" for="auto_e_switch">
                            Automatically Send Detailed Email with Logs
                        </label>
                        <div class="small text-secondary">Transmits comprehensive emergency email logs with residence unit, initiator phone, time, and on-duty guards snapshot.</div>
                    </div>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm">
                        <i class="fa-solid fa-save me-1"></i> Save Emergency Settings
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- ========================================================
         TAB 5: DIRECT DIAL HOTLINES DIRECTORY
         ======================================================== -->
    <?php if ($active_tab === 'hotlines'): ?>
        <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
            <div class="d-flex align-items-center justify-content-between mb-4 pb-2 border-bottom flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold mb-0 text-dark" style="font-family: 'Outfit', sans-serif;">
                        <i class="fa-solid fa-phone-volume text-success me-2"></i> Estate Direct-Dial Emergency Directory
                    </h5>
                    <small class="text-secondary">Configured telephone hotlines presented as instant 1-tap call buttons to residents during emergencies.</small>
                </div>
                <button type="button" class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#addHotlineModal">
                    <i class="fa-solid fa-plus me-1"></i> Add Emergency Hotline
                </button>
            </div>

            <div class="row g-3">
                <?php if ($hotlines && $hotlines->num_rows > 0): ?>
                    <?php while ($h = $hotlines->fetch_assoc()): ?>
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="border rounded-4 p-3 bg-light d-flex flex-column justify-content-between h-100">
                                <div>
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill" style="font-size: 0.68rem; text-transform: uppercase;">
                                            <?php echo str_replace('_', ' ', htmlspecialchars($h['contact_type'])); ?>
                                        </span>
                                        <?php if ($h['is_primary'] == 1): ?>
                                            <span class="badge bg-warning text-dark rounded-pill" style="font-size: 0.65rem;">PRIMARY</span>
                                        <?php endif; ?>
                                    </div>
                                    <h6 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($h['label']); ?></h6>
                                    <h5 class="fw-bold text-danger font-monospace mb-3"><?php echo htmlspecialchars($h['phone_number']); ?></h5>
                                </div>
                                <div class="d-flex align-items-center justify-content-between pt-2 border-top">
                                    <a href="tel:<?php echo htmlspecialchars($h['phone_number']); ?>" class="btn btn-sm btn-outline-danger rounded-pill px-3">
                                        <i class="fa-solid fa-phone me-1"></i> Test Dial
                                    </a>
                                    <form method="POST" onsubmit="return confirm('Remove this hotline?');" class="m-0">
                                        <input type="hidden" name="action_delete_hotline" value="1">
                                        <input type="hidden" name="hotline_id" value="<?php echo $h['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-light text-danger rounded-pill px-2">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="col-12 text-center py-4 text-muted">No emergency telephone hotlines registered yet.</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- ========================================================
         TAB 6: INCIDENT ARCHIVES & HISTORICAL TIMELINE
         ======================================================== -->
    <?php if ($active_tab === 'history'): ?>
        <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
            <h5 class="fw-bold mb-3 text-dark" style="font-family: 'Outfit', sans-serif;">
                <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i> Historical Emergency Archives &amp; Actions
            </h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small text-secondary text-uppercase">
                        <tr>
                            <th>Alert Code</th>
                            <th>Category</th>
                            <th>Initiator</th>
                            <th>Location / Unit</th>
                            <th>Status</th>
                            <th>Action Taken</th>
                            <th>Resolution Notes</th>
                            <th>Incident Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($history_alerts && $history_alerts->num_rows > 0): ?>
                            <?php while ($ha = $history_alerts->fetch_assoc()): ?>
                                <tr>
                                    <td><span class="fw-bold font-monospace text-primary"><?php echo htmlspecialchars($ha['alert_code']); ?></span></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($ha['category_name']); ?></span></td>
                                    <td>
                                        <strong class="text-dark d-block small"><?php echo htmlspecialchars($ha['sender_name']); ?></strong>
                                        <span class="badge bg-secondary rounded-pill" style="font-size: 0.65rem;"><?php echo htmlspecialchars($ha['sender_type']); ?></span>
                                    </td>
                                    <td class="small">
                                        <?php if (!empty($ha['building_name'])): ?>
                                            <?php echo htmlspecialchars($ha['building_name']); ?> &bull; Unit <?php echo htmlspecialchars($ha['flat_number']); ?>
                                        <?php else: ?>
                                            Estate Wide
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $st = $ha['status'];
                                        $bclass = 'bg-secondary';
                                        if ($st === 'active') $bclass = 'bg-danger';
                                        elseif ($st === 'resolved') $bclass = 'bg-success';
                                        elseif ($st === 'false_alarm') $bclass = 'bg-dark';
                                        ?>
                                        <span class="badge <?php echo $bclass; ?> rounded-pill text-uppercase" style="font-size: 0.68rem;"><?php echo htmlspecialchars($st); ?></span>
                                    </td>
                                    <td class="small font-semibold text-dark"><?php echo htmlspecialchars($ha['resolution_action'] ?? '-'); ?></td>
                                    <td class="small text-secondary"><?php echo htmlspecialchars($ha['resolution_notes'] ?? '-'); ?></td>
                                    <td class="small text-secondary"><?php echo date('M d, Y - h:i A', strtotime($ha['created_at'])); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="text-center py-4 text-muted">No historical emergency incidents recorded.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ========================================================
     MODAL: TRIGGER ESTATE PANIC BROADCAST
     ======================================================== -->
<div class="modal fade" id="panicBroadcastModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" id="panicBroadcastForm" class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem;">
            <input type="hidden" name="action_trigger_broadcast" value="1">
            <div class="modal-header bg-danger text-white px-4 py-3 border-0">
                <div class="d-flex align-items-center gap-2">
                    <div style="width: 36px; height: 36px; border-radius: 8px; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-weight: 800;">SOS</div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0">Trigger Emergency Panic Broadcast</h5>
                        <small class="text-white-50">Broadcast sirens and high-urgency advisory to residents and security</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-bold">Emergency Category *</label>
                        <select name="category_name" required class="form-select rounded-3">
                            <option value="Security Threat">Security Threat (Intruder, breach, lockdown)</option>
                            <option value="Fire / Hazard Evacuation">Fire / Hazard Evacuation</option>
                            <option value="Medical Lockdown">Medical Crisis / Lockdown</option>
                            <option value="Severe Weather Hazard">Severe Weather Hazard</option>
                            <option value="General Emergency Broadcast">General Emergency Broadcast</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-bold">Target Stakeholders *</label>
                        <select name="target_stakeholders" class="form-select rounded-3">
                            <option value="all_residents">All Estate Residents (Estate-wide sirens &amp; notice)</option>
                            <option value="guards_only">Security Gates &amp; Guards Only</option>
                            <option value="guards_and_admin">Security Guards &amp; Estate Admins</option>
                            <option value="guards_and_medical">Security Guards &amp; Medical Team</option>
                        </select>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12">
                        <label class="form-label small fw-bold">Target Geographical Scope *</label>
                        <select name="target_scope" id="broadcast_target_scope" onchange="toggleZoneSelect(this.value)" class="form-select rounded-3">
                            <option value="estate_wide">Estate-Wide (All Zones)</option>
                            <option value="zone">Specific Zone Only</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3" id="zone_select_group" style="display: none;">
                    <label class="form-label small fw-bold">Select Target Zone</label>
                    <select name="zone_id" class="form-select rounded-3">
                        <?php if ($zones && $zones->num_rows > 0): ?>
                            <?php while ($z = $zones->fetch_assoc()): ?>
                                <option value="<?php echo $z['id']; ?>"><?php echo htmlspecialchars($z['name']); ?> (<?php echo htmlspecialchars($z['code']); ?>)</option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Urgent Headline / Advisory Action *</label>
                    <input type="text" name="headline" required placeholder="e.g. Armed intruder alert at Gate 2 - All residents stay indoors and secure doors!" class="form-control rounded-3">
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Detailed Instructions / Notes</label>
                    <textarea name="note" rows="3" placeholder="Provide clear instructions for residents and security personnel..." class="form-control rounded-3"></textarea>
                </div>

                <div class="form-check form-switch p-3 bg-light rounded-3">
                    <input class="form-check-input ms-0 me-3" type="checkbox" name="sound_alarm" value="1" id="sound_alarm_switch" checked>
                    <label class="form-check-label fw-bold" for="sound_alarm_switch">
                        <i class="fa-solid fa-bullhorn text-danger me-1"></i> Sound Audio Siren Alarm in All Active Portals
                    </label>
                    <div class="small text-secondary">Produces audible klaxon siren tone on all connected resident, guard, and admin screens.</div>
                </div>
            </div>
            <div class="modal-footer px-4 py-3 bg-light border-0">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold">
                    <i class="fa-solid fa-radiation me-1"></i> DISPATCH EMERGENCY BROADCAST
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================
     MODAL: ADD CUSTOM CATEGORY (Icon-Free Clean Form)
     ======================================================== -->
<div class="modal fade" id="addCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem;">
            <input type="hidden" name="action_save_category" value="1">
            <div class="modal-header px-4 py-3 border-0 bg-light">
                <h5 class="modal-title fw-bold text-dark">Add Emergency Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label small fw-bold">Category Title *</label>
                    <input type="text" name="category_name" required placeholder="e.g. Elevator Entrapment, Perimeter Breach" class="form-control rounded-3">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-bold">Color Theme</label>
                        <input type="color" name="color" value="#dc2626" class="form-control form-control-color w-100 rounded-3">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold">Priority</label>
                        <select name="priority" class="form-select rounded-3">
                            <option value="critical">Critical</option>
                            <option value="high">High</option>
                            <option value="medium">Medium</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Default Target Stakeholders *</label>
                    <select name="target_stakeholders" class="form-select rounded-3">
                        <option value="all_residents">All Estate Residents (Entire estate alerted)</option>
                        <option value="guards_only">Security Gate &amp; Guards Only</option>
                        <option value="guards_and_admin">Security Guards &amp; Estate Admins</option>
                        <option value="guards_and_medical">Security Guards &amp; Medical Team</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Use Case Guidance / Description *</label>
                    <textarea name="use_case_description" required rows="2" placeholder="Brief guidance shown to residents explaining when to use this category..." class="form-control rounded-3"></textarea>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-8">
                        <label class="form-label small fw-bold">Direct Phone Hotline (Optional)</label>
                        <input type="text" name="emergency_contact_phone" placeholder="e.g. 08012345678" class="form-control rounded-3">
                    </div>
                    <div class="col-4">
                        <label class="form-label small fw-bold">Display Order</label>
                        <input type="number" name="display_order" value="10" class="form-control rounded-3">
                    </div>
                </div>
            </div>
            <div class="modal-footer px-4 py-3 bg-light border-0">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Save Category</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================
     MODAL: ADD RESPONSE ACTION TEMPLATE
     ======================================================== -->
<div class="modal fade" id="addActionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem;">
            <input type="hidden" name="action_save_response_action" value="1">
            <div class="modal-header px-4 py-3 border-0 bg-light">
                <h5 class="modal-title fw-bold text-dark">Add Response Action Preset</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label small fw-bold">Action Title *</label>
                    <input type="text" name="action_title" required placeholder="e.g. Security Patrol Dispatched to Unit" class="form-control rounded-3">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Description / Standard Procedure</label>
                    <textarea name="description" rows="2" placeholder="Brief summary of procedure carried out..." class="form-control rounded-3"></textarea>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-8">
                        <label class="form-label small fw-bold">Target Status</label>
                        <select name="target_status" class="form-select rounded-3">
                            <option value="resolved">Resolved / Closed</option>
                            <option value="dispatched">Dispatched / Under Investigation</option>
                            <option value="false_alarm">False Alarm</option>
                        </select>
                    </div>
                    <div class="col-4">
                        <label class="form-label small fw-bold">Display Order</label>
                        <input type="number" name="display_order" value="1" class="form-control rounded-3">
                    </div>
                </div>
            </div>
            <div class="modal-footer px-4 py-3 bg-light border-0">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold">Save Response Action</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================
     MODAL: ADD EMERGENCY HOTLINE
     ======================================================== -->
<div class="modal fade" id="addHotlineModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem;">
            <input type="hidden" name="action_save_hotline" value="1">
            <div class="modal-header px-4 py-3 border-0 bg-light">
                <h5 class="modal-title fw-bold text-dark">Add Direct Emergency Hotline</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label small fw-bold">Hotline Name / Label *</label>
                    <input type="text" name="label" required placeholder="e.g. CSO Direct Cell, Gate 2 Guard Desk" class="form-control rounded-3">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Phone Number *</label>
                    <input type="text" name="phone_number" required placeholder="e.g. 08012345678" class="form-control rounded-3 font-monospace">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-8">
                        <label class="form-label small fw-bold">Classification *</label>
                        <select name="contact_type" class="form-select rounded-3">
                            <option value="internal_security">Internal Estate Security</option>
                            <option value="medical">Medical / Ambulance</option>
                            <option value="fire">Fire &amp; Rescue</option>
                            <option value="police">Police Division</option>
                            <option value="management">Estate Management</option>
                            <option value="general">General Support</option>
                        </select>
                    </div>
                    <div class="col-4">
                        <label class="form-label small fw-bold">Order</label>
                        <input type="number" name="display_order" value="1" class="form-control rounded-3">
                    </div>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_primary" value="1" id="prim_check">
                    <label class="form-check-label small fw-semibold" for="prim_check">Mark as Primary Emergency Number</label>
                </div>
            </div>
            <div class="modal-footer px-4 py-3 bg-light border-0">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Register Hotline</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================
     MODAL: RESOLVE EMERGENCY INCIDENT (With Configurable Action Taken)
     ======================================================== -->
<div class="modal fade" id="resolveIncidentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem;">
            <div class="modal-header px-4 py-3 border-0 bg-light">
                <h5 class="modal-title fw-bold text-dark">Record Response Action &amp; Close</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="resolve_alert_id">
                <p class="small text-secondary mb-3">Incident: <strong id="resolve_alert_code" class="text-primary"></strong> &bull; <span id="resolve_alert_category" class="fw-bold"></span></p>
                
                <div class="mb-3">
                    <label class="form-label small fw-bold">Action Taken (Choose Preset or Custom) *</label>
                    <select id="resolve_action_preset" class="form-select rounded-3 mb-2" onchange="onSelectActionPreset(this.value)">
                        <option value="">-- Choose Preset Action Taken --</option>
                        <?php 
                        // Fetch actions again for modal
                        $modal_actions = $conn->query("SELECT action_title, description, target_status FROM estate_emergency_actions WHERE estate_id = $estate_id ORDER BY display_order ASC");
                        if ($modal_actions) {
                            while ($ma = $modal_actions->fetch_assoc()) {
                                echo '<option value="' . htmlspecialchars($ma['action_title']) . '" data-desc="' . htmlspecialchars($ma['description']) . '" data-status="' . htmlspecialchars($ma['target_status']) . '">' . htmlspecialchars($ma['action_title']) . '</option>';
                            }
                        }
                        ?>
                        <option value="Other Action Taken">Other Custom Action Taken...</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Resolution Notes / Details *</label>
                    <textarea id="resolve_notes" rows="3" required placeholder="Describe the action taken and current situation..." class="form-control rounded-3"></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Incident Classification</label>
                    <select id="resolve_status" class="form-select rounded-3">
                        <option value="resolved">Successfully Resolved</option>
                        <option value="false_alarm">False Alarm</option>
                        <option value="dispatched">Dispatched / Under Active Patrol</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer px-4 py-3 bg-light border-0">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="button" onclick="submitResolveIncident()" class="btn btn-success rounded-pill px-4 fw-bold">Submit Action &amp; Update</button>
            </div>
        </div>
    </div>
</div>

<script>
function toggleZoneSelect(val) {
    const zg = document.getElementById('zone_select_group');
    if (zg) zg.style.display = (val === 'zone') ? 'block' : 'none';
}

function openResolveModal(alertId, alertCode, catName) {
    document.getElementById('resolve_alert_id').value = alertId;
    document.getElementById('resolve_alert_code').innerText = alertCode;
    document.getElementById('resolve_alert_category').innerText = catName || '';
    document.getElementById('resolve_action_preset').value = '';
    document.getElementById('resolve_notes').value = '';
    const m = new bootstrap.Modal(document.getElementById('resolveIncidentModal'));
    m.show();
}

function onSelectActionPreset(presetVal) {
    const sel = document.getElementById('resolve_action_preset');
    const opt = sel.options[sel.selectedIndex];
    const desc = opt.getAttribute('data-desc');
    const stat = opt.getAttribute('data-status');
    
    if (desc) {
        document.getElementById('resolve_notes').value = desc;
    }
    if (stat) {
        document.getElementById('resolve_status').value = stat;
    }
}

function submitResolveIncident() {
    const alertId = document.getElementById('resolve_alert_id').value;
    const preset = document.getElementById('resolve_action_preset').value;
    const notes = document.getElementById('resolve_notes').value;
    const status = document.getElementById('resolve_status').value;

    if (!notes && !preset) {
        if (window.EstateDialog) {
            EstateDialog.toast({ type: 'warning', title: 'Input Required', message: 'Please provide notes or select an action taken.' });
        }
        return;
    }

    fetch('../api/emergency.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=resolve_alert&alert_id=${encodeURIComponent(alertId)}&status=${encodeURIComponent(status)}&resolution_action=${encodeURIComponent(preset)}&resolution_notes=${encodeURIComponent(notes)}`
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            if (window.EstateDialog) {
                EstateDialog.toast({ type: 'success', title: 'Incident Resolved', message: 'Emergency alert updated successfully.' });
            }
            setTimeout(() => location.reload(), 600);
        } else {
            if (window.EstateDialog) {
                EstateDialog.toast({ type: 'error', title: 'Error', message: res.error || 'Failed to resolve incident' });
            }
        }
    })
    .catch(err => {
        if (window.EstateDialog) {
            EstateDialog.toast({ type: 'error', title: 'Network Error', message: 'Failed to submit resolution.' });
        }
    });
}

// Ask confirmation before triggering central emergency panic broadcast
document.addEventListener('DOMContentLoaded', function() {
    const broadcastForm = document.getElementById('panicBroadcastForm');
    if (broadcastForm) {
        broadcastForm.addEventListener('submit', async function(e) {
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
                title: 'Trigger Emergency Broadcast',
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
include '../includes/footer.php'; 
?>
