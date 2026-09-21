<?php
// admin/roster.php
// Enterprise Security & Staff Calendar Duty Roster with Configurable Shifts, Posts, Forensics & Attendance System

require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/emergency_roster_init.php';
requireAdminAccess();

$estate_id = get_estate_id();
$user_id = $_SESSION['user_id'];
$message = "";
$error = "";

// -------------------------------------------------------------
// POST ACTIONS: CONFIGURE SHIFT TEMPLATE
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_shift_template'])) {
    $sh_id = intval($_POST['shift_id'] ?? 0);
    $sh_name = $conn->real_escape_string(trim($_POST['name']));
    $sh_start = trim($_POST['start_time']);
    $sh_end = trim($_POST['end_time']);
    $sh_color = $conn->real_escape_string(trim($_POST['color_code'] ?? '#2563eb'));
    $sh_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;

    if (!empty($sh_name) && !empty($sh_start) && !empty($sh_end)) {
        if ($sh_id > 0) {
            $conn->query("UPDATE security_shifts SET name='$sh_name', start_time='$sh_start', end_time='$sh_end', color_code='$sh_color', is_active=$sh_active WHERE id=$sh_id AND estate_id=$estate_id");
            $message = "Shift template '$sh_name' updated successfully!";
        } else {
            $conn->query("INSERT INTO security_shifts (estate_id, name, start_time, end_time, color_code, is_active) VALUES ($estate_id, '$sh_name', '$sh_start', '$sh_end', '$sh_color', $sh_active)");
            $message = "New shift template '$sh_name' created successfully!";
        }
    } else {
        $error = "Please provide name, start time, and end time for the shift template.";
    }
}

// -------------------------------------------------------------
// POST ACTIONS: CONFIGURE POST / WORKSTATION
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_post'])) {
    $p_id = intval($_POST['post_id'] ?? 0);
    $p_name = $conn->real_escape_string(trim($_POST['post_name']));
    $p_desc = $conn->real_escape_string(trim($_POST['location_description'] ?? ''));
    $p_ext = $conn->real_escape_string(trim($_POST['phone_extension'] ?? ''));
    $p_status = (isset($_POST['status']) && $_POST['status'] === 'inactive') ? 'inactive' : 'active';

    if (!empty($p_name)) {
        if ($p_id > 0) {
            $conn->query("UPDATE security_posts SET post_name='$p_name', location_description='$p_desc', phone_extension='$p_ext', status='$p_status' WHERE id=$p_id AND estate_id=$estate_id");
            $message = "Post '$p_name' updated successfully!";
        } else {
            $conn->query("INSERT INTO security_posts (estate_id, post_name, location_description, phone_extension, status) VALUES ($estate_id, '$p_name', '$p_desc', '$p_ext', '$p_status')");
            $message = "New post '$p_name' added successfully!";
        }
    } else {
        $error = "Please provide a post or workstation name.";
    }
}

// -------------------------------------------------------------
// FETCH SYSTEM DATA
// -------------------------------------------------------------
// Posts
$posts_res = $conn->query("SELECT * FROM security_posts WHERE estate_id = $estate_id ORDER BY status ASC, post_name ASC");
$posts = [];
while ($p = $posts_res->fetch_assoc()) $posts[] = $p;

// Shifts
$shifts_res = $conn->query("SELECT * FROM security_shifts WHERE estate_id = $estate_id ORDER BY is_active DESC, start_time ASC");
$shifts = [];
while ($s = $shifts_res->fetch_assoc()) $shifts[] = $s;

// Active staff personnel (Security, Maintenance, General Staff)
$staff_query = $conn->query("SELECT u.id, u.name, u.phone, u.role as user_role, es.custom_id as badge_id, es.role as staff_role, es.image_path 
                             FROM users u 
                             LEFT JOIN estate_staff es ON (u.id = es.user_id AND es.estate_id = $estate_id)
                             WHERE u.estate_id = $estate_id 
                               AND (u.role IN ('security', 'staff', 'manager', 'admin') OR es.id IS NOT NULL)
                             ORDER BY (CASE WHEN u.role = 'security' OR es.role LIKE '%Security%' THEN 0 ELSE 1 END), u.name ASC");
$available_staff = [];
while ($st = $staff_query->fetch_assoc()) $available_staff[] = $st;

// Live Guards on Duty Right Now
$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');
$live_guards = $conn->query("SELECT sr.*, u.name as officer_name, u.phone as officer_phone, sp.post_name, ss.name as shift_name, ss.color_code
                            FROM security_roster sr
                            JOIN users u ON sr.user_id = u.id
                            LEFT JOIN security_posts sp ON sr.post_id = sp.id
                            LEFT JOIN security_shifts ss ON sr.shift_id = ss.id
                            WHERE sr.estate_id = $estate_id 
                              AND (
                                sr.status = 'on_duty' 
                                OR (sr.start_datetime <= '$now' AND sr.end_datetime >= '$now' AND sr.status IN ('on_duty', 'scheduled'))
                              )
                            ORDER BY (CASE WHEN sr.status = 'on_duty' THEN 0 ELSE 1 END), sp.post_name ASC");

// Quick Stats
$this_month_start = date('Y-m-01');
$this_month_end = date('Y-m-t');
$total_monthly_shifts = $conn->query("SELECT COUNT(*) as c FROM security_roster WHERE estate_id = $estate_id AND duty_date BETWEEN '$this_month_start' AND '$this_month_end'")->fetch_assoc()['c'] ?? 0;
$total_on_duty_now = $live_guards ? $live_guards->num_rows : 0;
$active_posts_count = $conn->query("SELECT COUNT(*) as c FROM security_posts WHERE estate_id = $estate_id AND status = 'active'")->fetch_assoc()['c'] ?? 0;

// Active tab selector
$active_tab = $_GET['tab'] ?? 'calendar';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
/* Custom Content Wrapper override to fit whole calendar into page */
.content-wrapper {
    padding: 0.6rem 1.25rem 0.5rem !important;
}

/* Roster Calendar & Module Aesthetics */
:root {
    --cal-cell-bg: #ffffff;
    --cal-cell-border: #f1f5f9;
    --cal-header-bg: #f8fafc;
    --cal-today-bg: #eff6ff;
    --cal-today-border: #3b82f6;
}

[data-bs-theme="dark"], body.dark-mode {
    --cal-cell-bg: #1e293b;
    --cal-cell-border: #334155;
    --cal-header-bg: #0f172a;
    --cal-today-bg: rgba(59, 130, 246, 0.15);
    --cal-today-border: #60a5fa;
}

.calendar-card-viewport {
    height: calc(100vh - 165px);
    min-height: 460px;
    display: flex;
    flex-direction: column;
    background: var(--cal-cell-bg);
    border-radius: 0.75rem;
    border: 1px solid #e2e8f0;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

[data-bs-theme="dark"] .calendar-card-viewport,
body.dark-mode .calendar-card-viewport {
    border-color: #334155;
}

.calendar-container {
    background: var(--cal-cell-bg);
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    min-height: 0;
    overflow: hidden;
}

.calendar-days-header {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    flex: 0 0 auto;
    border-top: 1px solid var(--cal-cell-border);
    border-left: 1px solid var(--cal-cell-border);
}

.cal-day-header {
    background: var(--cal-header-bg);
    padding: 4px 4px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #64748b;
    text-align: center;
    border-right: 1px solid var(--cal-cell-border);
    border-bottom: 1px solid var(--cal-cell-border);
}

.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    grid-template-rows: repeat(6, 1fr);
    flex: 1 1 auto;
    height: 100%;
    min-height: 0;
    border-left: 1px solid var(--cal-cell-border);
}

.cal-cell {
    height: 100%;
    min-height: 0;
    background: var(--cal-cell-bg);
    border-right: 1px solid var(--cal-cell-border);
    border-bottom: 1px solid var(--cal-cell-border);
    padding: 2px 4px;
    position: relative;
    transition: background 0.15s ease, box-shadow 0.15s ease;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.cal-cell:hover {
    background: rgba(241, 245, 249, 0.5);
}

.cal-cell.other-month {
    background: rgba(248, 250, 252, 0.4);
    opacity: 0.55;
}

.cal-cell.is-today {
    background: var(--cal-today-bg);
}

.cal-cell.is-today .cal-date-number {
    background: var(--cal-today-border);
    color: #ffffff;
    border-radius: 50%;
    width: 18px;
    height: 18px;
    font-size: 0.68rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.cal-cell-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1px;
    line-height: 1;
}

.cal-date-number {
    font-weight: 700;
    font-size: 0.72rem;
    color: #475569;
    padding: 1px 2px;
}

.cal-add-btn {
    opacity: 0;
    transition: opacity 0.15s ease;
    width: 16px;
    height: 16px;
    padding: 0;
    font-size: 0.6rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}

.cal-cell:hover .cal-add-btn {
    opacity: 1;
}

.cal-events-list {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex-grow: 1;
    min-height: 0;
    overflow-y: auto;
}

.cal-events-list::-webkit-scrollbar {
    width: 3px;
}
.cal-events-list::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 2px;
}

.roster-chip {
    font-size: 0.65rem;
    padding: 1px 4px;
    border-radius: 3px;
    background: #f1f5f9;
    border-left: 3px solid #3b82f6;
    color: #1e293b;
    cursor: pointer;
    line-height: 1.25;
    transition: transform 0.12s ease, box-shadow 0.12s ease;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 3px;
}

.roster-chip:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
}

.status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}

.status-dot.scheduled { background: #3b82f6; }
.status-dot.on_duty { background: #10b981; box-shadow: 0 0 5px #10b981; animation: pulseGlow 1.8s infinite; }
.status-dot.completed { background: #06b6d4; }
.status-dot.absent { background: #ef4444; }
.status-dot.swapped { background: #f59e0b; }
.status-dot.excused { background: #8b5cf6; }

@keyframes pulseGlow {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { transform: scale(1.1); box-shadow: 0 0 0 5px rgba(16, 185, 129, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

/* Custom Tabs Styling */
.nav-pills-custom .nav-link {
    border-radius: 50rem;
    padding: 0.25rem 0.85rem;
    font-weight: 600;
    font-size: 0.78rem;
    color: #64748b;
    transition: all 0.2s ease;
}

.nav-pills-custom .nav-link.active {
    background: #0f172a;
    color: #ffffff;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.12);
}

.nav-pills-custom .nav-link:hover:not(.active) {
    background: #f1f5f9;
    color: #0f172a;
}

/* Live Guards Feed Top Bar */
.live-guards-strip {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    color: #ffffff;
    border-radius: 0.75rem;
    padding: 0.5rem 0.85rem;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.1);
}
.live-guard-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #0284c7;
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 700;
    flex-shrink: 0;
}
.live-guard-chip {
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 0.5rem;
    padding: 0.3rem 0.6rem;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.15s ease;
}
.live-guard-chip:hover {
    background: rgba(255, 255, 255, 0.15);
}
</style>

<div class="d-flex flex-column gap-1">
    <!-- ==========================================
         COMPACT EXECUTIVE HEADER & LIVE STATS
         ========================================== -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pb-1 border-bottom border-light-subtle">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <h1 class="h6 font-bold text-slate-900 m-0" style="letter-spacing: -0.02em; font-size: 0.95rem;">
                <i class="fa-solid fa-shield-halved text-primary me-1"></i> Security Duty Roster &amp; Attendance System
            </h1>
            <span class="mature-badge mature-badge-emerald py-0" style="font-size: 0.7rem;" id="badge-live-count">
                <span class="status-dot on_duty me-1" style="width: 5px; height: 5px;"></span> <strong id="live-onduty-stat"><?php echo $total_on_duty_now; ?></strong> Live On Duty
            </span>
            <span class="mature-badge mature-badge-primary py-0" style="font-size: 0.7rem;">
                <i class="fa-solid fa-calendar-check me-1"></i> <?php echo $total_monthly_shifts; ?> Monthly Duties
            </span>
            <span class="mature-badge mature-badge-slate py-0" style="font-size: 0.7rem;">
                <i class="fa-solid fa-building-shield me-1"></i> <?php echo $active_posts_count; ?> Posts Covered
            </span>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-xs btn-success px-3 py-1 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#adminQuickClockModal" style="font-size: 0.75rem;">
                <i class="fa-solid fa-clock me-1"></i> Clock In / Out Guard
            </button>
            <button type="button" class="btn btn-xs btn-primary px-3 py-1 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#scheduleShiftModal" style="font-size: 0.75rem;">
                <i class="fa-solid fa-calendar-plus me-1"></i> Distribute / Assign Roster
            </button>
            <button type="button" onclick="sendDutyAlertsPrompt()" class="btn btn-xs btn-outline-secondary px-2 py-1" style="font-size: 0.75rem;">
                <i class="fa-solid fa-paper-plane me-1"></i> Reminders
            </button>
            <a href="emergency" class="btn btn-xs btn-outline-danger px-2 py-1" style="font-size: 0.75rem;">
                <i class="fa-solid fa-triangle-exclamation me-1"></i> SOS Command
            </a>
        </div>
    </div>

    <!-- ==========================================
         REAL-TIME LIVE GUARDS ON DUTY FEED PANEL
         ========================================== -->
    <div class="live-guards-strip mb-1">
        <div class="d-flex align-items-center justify-content-between mb-1 pb-1 border-bottom border-secondary border-opacity-25">
            <div class="d-flex align-items-center gap-2">
                <span class="status-dot on_duty"></span>
                <span class="small fw-bold text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                    Live Guard Feed &bull; Stationed On Duty Right Now
                </span>
                <span class="badge bg-success bg-opacity-25 text-success rounded-pill px-2" style="font-size: 0.65rem;" id="live-feed-timer-badge">Auto-Refreshed</span>
            </div>
            <button type="button" onclick="loadLiveGuardsFeed()" class="btn btn-xs btn-outline-light rounded-pill px-2 py-0" style="font-size: 0.68rem;" title="Refresh live feed">
                <i class="fa-solid fa-rotate me-1"></i> Refresh Feed
            </button>
        </div>
        <div class="d-flex align-items-center gap-2 overflow-x-auto py-1" id="live-guards-feed-container" style="scrollbar-width: thin;">
            <div class="text-slate-400 small py-1" style="font-size: 0.75rem;">Loading live guards on duty...</div>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3 py-1 px-3 mb-1" role="alert" style="background: #dcfce7; color: #166534; font-size: 0.8rem;">
            <i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close py-1" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3 py-1 px-3 mb-1" role="alert" style="background: #fee2e2; color: #991b1b; font-size: 0.8rem;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close py-1" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <ul class="nav nav-pills-custom gap-1 mb-1" id="rosterTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo ($active_tab === 'calendar') ? 'active' : ''; ?>" id="calendar-tab" data-bs-toggle="pill" data-bs-target="#calendar-tab-pane" type="button" role="tab">
                <i class="fa-solid fa-calendar-days me-1"></i> Roster Calendar
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo ($active_tab === 'attendance') ? 'active' : ''; ?>" id="attendance-tab" data-bs-toggle="pill" data-bs-target="#attendance-tab-pane" type="button" role="tab" onclick="loadAttendanceRecords()">
                <i class="fa-solid fa-clipboard-user me-1 text-teal" style="color: #0d9488;"></i> Guard Attendance System
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo ($active_tab === 'shifts') ? 'active' : ''; ?>" id="shifts-tab" data-bs-toggle="pill" data-bs-target="#shifts-tab-pane" type="button" role="tab">
                <i class="fa-solid fa-clock me-1"></i> Shift Templates (<?php echo count($shifts); ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo ($active_tab === 'posts') ? 'active' : ''; ?>" id="posts-tab" data-bs-toggle="pill" data-bs-target="#posts-tab-pane" type="button" role="tab">
                <i class="fa-solid fa-building-shield me-1"></i> Posts &amp; Stations (<?php echo count($posts); ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo ($active_tab === 'forensics') ? 'active' : ''; ?>" id="forensics-tab" data-bs-toggle="pill" data-bs-target="#forensics-tab-pane" type="button" role="tab">
                <i class="fa-solid fa-magnifying-glass-chart me-1"></i> Incident Forensics
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo ($active_tab === 'log') ? 'active' : ''; ?>" id="log-tab" data-bs-toggle="pill" data-bs-target="#log-tab-pane" type="button" role="tab">
                <i class="fa-solid fa-list-check me-1"></i> Duty Log &amp; Export
            </button>
        </li>
    </ul>

    <!-- TAB CONTENTS -->
    <div class="tab-content" id="rosterTabsContent">
        
        <!-- ==============================================================
             TAB 1: INTERACTIVE ROSTER CALENDAR (MONTH / WEEK / DAY)
             ============================================================== -->
        <div class="tab-pane fade <?php echo ($active_tab === 'calendar') ? 'show active' : ''; ?>" id="calendar-tab-pane" role="tabpanel">
            
            <div class="calendar-card-viewport" id="calendarMainCard">
                <!-- Slim Unified Calendar Toolbar Header -->
                <div class="px-3 py-2 d-flex align-items-center justify-content-between gap-2 bg-white border-bottom border-light-subtle flex-shrink-0" style="flex-wrap: nowrap; overflow-x: auto;">
                    
                    <!-- Left: Month / Year Navigation & Quick Jump -->
                    <div class="d-flex align-items-center gap-1 flex-shrink-0" style="white-space: nowrap;">
                        <button type="button" class="btn btn-sm btn-light border rounded-circle d-flex align-items-center justify-content-center" onclick="changeMonth(-1)" title="Previous Month" style="width: 28px; height: 28px; font-size: 0.75rem;">
                            <i class="fa-solid fa-chevron-left"></i>
                        </button>
                        
                        <!-- Month & Year Selector -->
                        <div class="d-inline-flex align-items-center gap-1">
                            <select id="cal_quick_month" onchange="jumpToMonthYear()" class="form-select form-select-sm fw-bold border-0 bg-transparent text-slate-900" style="font-size: 0.92rem; width: 110px; cursor: pointer; padding-right: 18px;">
                                <option value="1">January</option>
                                <option value="2">February</option>
                                <option value="3">March</option>
                                <option value="4">April</option>
                                <option value="5">May</option>
                                <option value="6">June</option>
                                <option value="7">July</option>
                                <option value="8">August</option>
                                <option value="9">September</option>
                                <option value="10">October</option>
                                <option value="11">November</option>
                                <option value="12">December</option>
                            </select>
                            <select id="cal_quick_year" onchange="jumpToMonthYear()" class="form-select form-select-sm fw-bold border-0 bg-transparent text-slate-900" style="font-size: 0.92rem; width: 80px; cursor: pointer; padding-right: 18px;">
                                <?php for ($y = date('Y') - 1; $y <= date('Y') + 4; $y++): ?>
                                    <option value="<?php echo $y; ?>" <?php echo ($y == date('Y')) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <button type="button" class="btn btn-sm btn-light border rounded-circle d-flex align-items-center justify-content-center" onclick="changeMonth(1)" title="Next Month" style="width: 28px; height: 28px; font-size: 0.75rem;">
                            <i class="fa-solid fa-chevron-right"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-2 ms-1 fw-semibold" onclick="jumpToToday()" style="font-size: 0.74rem; height: 26px; padding-top: 1px; padding-bottom: 1px;">
                            Today
                        </button>
                    </div>

                    <!-- Middle: Strict Single Horizontal Row Filters -->
                    <div class="d-inline-flex align-items-center gap-2 flex-shrink-1" style="flex-wrap: nowrap; white-space: nowrap;">
                        <i class="fa-solid fa-filter text-primary flex-shrink-0" style="font-size: 0.75rem;"></i>
                        
                        <!-- Role -->
                        <select id="filter_role" onchange="loadCalendarEvents()" class="form-select form-select-sm" style="width: 115px !important; min-width: 100px; max-width: 120px; display: inline-block; flex: 0 0 auto; height: 28px; font-size: 0.76rem; padding: 1px 20px 1px 6px; border-radius: 5px;">
                            <option value="" selected>All Staff</option>
                            <option value="security">Security</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="facility">Facility & Admin</option>
                        </select>

                        <!-- Post -->
                        <select id="filter_post" onchange="loadCalendarEvents()" class="form-select form-select-sm" style="width: 120px !important; min-width: 105px; max-width: 125px; display: inline-block; flex: 0 0 auto; height: 28px; font-size: 0.76rem; padding: 1px 20px 1px 6px; border-radius: 5px;">
                            <option value="">All Posts</option>
                            <?php foreach ($posts as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['post_name']); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Shift -->
                        <select id="filter_shift" onchange="loadCalendarEvents()" class="form-select form-select-sm" style="width: 110px !important; min-width: 95px; max-width: 115px; display: inline-block; flex: 0 0 auto; height: 28px; font-size: 0.76rem; padding: 1px 20px 1px 6px; border-radius: 5px;">
                            <option value="">All Shifts</option>
                            <?php foreach ($shifts as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Officer -->
                        <select id="filter_user" onchange="loadCalendarEvents()" class="form-select form-select-sm" style="width: 120px !important; min-width: 105px; max-width: 125px; display: inline-block; flex: 0 0 auto; height: 28px; font-size: 0.76rem; padding: 1px 20px 1px 6px; border-radius: 5px;">
                            <option value="">All Officers</option>
                            <?php foreach ($available_staff as $st): ?>
                                <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['name']); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Reset -->
                        <button type="button" class="btn btn-sm btn-light border rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" onclick="resetRosterFilters()" title="Reset Filters" style="width: 28px; height: 28px;">
                            <i class="fa-solid fa-rotate-left text-secondary" style="font-size: 0.7rem;"></i>
                        </button>
                    </div>

                    <!-- Right: View Switcher -->
                    <div class="btn-group rounded-pill p-0 bg-light border flex-shrink-0" style="white-space: nowrap;" role="group">
                        <button type="button" id="btn-view-month" onclick="setCalendarView('month')" class="btn btn-xs btn-sm btn-primary rounded-pill px-2 py-1 fw-semibold" style="font-size: 0.74rem;">Month</button>
                        <button type="button" id="btn-view-week" onclick="setCalendarView('week')" class="btn btn-xs btn-sm btn-light rounded-pill px-2 py-1 fw-semibold text-secondary" style="font-size: 0.74rem;">Week</button>
                        <button type="button" id="btn-view-day" onclick="setCalendarView('day')" class="btn btn-xs btn-sm btn-light rounded-pill px-2 py-1 fw-semibold text-secondary" style="font-size: 0.74rem;">Day</button>
                    </div>

                </div>

                <!-- CALENDAR GRID CONTAINER -->
                <div class="calendar-container" id="calendarMainContainer">
                    <div class="calendar-days-header">
                        <div class="cal-day-header text-danger">Sun</div>
                        <div class="cal-day-header">Mon</div>
                        <div class="cal-day-header">Tue</div>
                        <div class="cal-day-header">Wed</div>
                        <div class="cal-day-header">Thu</div>
                        <div class="cal-day-header">Fri</div>
                        <div class="cal-day-header text-primary">Sat</div>
                    </div>

                    <div class="calendar-grid" id="calendarDaysGrid">
                        <!-- Populated dynamically via JS -->
                    </div>
                </div>

                <!-- WEEK VIEW CONTAINER -->
                <div id="calendarWeekContainer" style="display: none; flex: 1 1 auto; overflow-y: auto; padding: 12px;" class="bg-white">
                    <div class="row g-2" id="calendarWeekColumns">
                        <!-- Populated dynamically via JS -->
                    </div>
                </div>

                <!-- DAY LIST VIEW CONTAINER -->
                <div id="calendarDayContainer" style="display: none; flex: 1 1 auto; overflow-y: auto; padding: 12px;" class="bg-white">
                    <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom">
                        <div>
                            <h6 class="fw-bold mb-0 text-dark" id="day-view-date-title">Scheduled Staff for Today</h6>
                            <small class="text-muted" id="day-view-date-subtitle">All posts and shifts</small>
                        </div>
                        <button type="button" class="btn btn-xs btn-primary rounded-pill px-3" onclick="openScheduleModalWithDate(currentSelectedDayDate)">
                            <i class="fa-solid fa-plus me-1"></i> Add Shift For This Day
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.8rem;">
                            <thead class="table-light small text-secondary text-uppercase">
                                <tr>
                                    <th>Officer / Staff</th>
                                    <th>Post / Gate</th>
                                    <th>Shift Template</th>
                                    <th>Status</th>
                                    <th>Clock In/Out</th>
                                    <th>Supervisor</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="day-view-table-body">
                                <!-- Populated dynamically via JS -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Integrated Slim Footer Legend -->
                <div class="px-3 py-1 bg-light border-top border-light-subtle d-flex flex-wrap align-items-center justify-content-between gap-2 flex-shrink-0" style="font-size: 0.7rem;">
                    <div class="d-flex flex-wrap align-items-center gap-2 text-secondary">
                        <strong class="text-dark">Shifts:</strong>
                        <?php foreach ($shifts as $s): ?>
                            <div class="d-flex align-items-center gap-1">
                                <span style="width: 9px; height: 9px; border-radius: 2px; background: <?php echo $s['color_code'] ?? '#2563eb'; ?>; display: inline-block;"></span>
                                <span><?php echo htmlspecialchars($s['name']); ?> (<?php echo date('H:i', strtotime($s['start_time'])); ?>-<?php echo date('H:i', strtotime($s['end_time'])); ?>)</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2 text-secondary">
                        <strong class="text-dark">Status:</strong>
                        <span><span class="status-dot on_duty me-1"></span>On Duty</span>
                        <span><span class="status-dot scheduled me-1"></span>Scheduled</span>
                        <span><span class="status-dot completed me-1"></span>Completed</span>
                        <span><span class="status-dot absent me-1"></span>Absent</span>
                    </div>
                </div>
            </div>

        </div>

        <!-- ==============================================================
             TAB 2: GUARD ATTENDANCE SYSTEM & ROLL CALL LOG
             ============================================================== -->
        <div class="tab-pane fade <?php echo ($active_tab === 'attendance') ? 'show active' : ''; ?>" id="attendance-tab-pane" role="tabpanel">
            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white mb-3">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3 pb-3 border-bottom">
                    <div>
                        <h5 class="fw-bold mb-1 text-dark" style="font-family: 'Outfit', sans-serif;">
                            <i class="fa-solid fa-clipboard-user text-teal me-2" style="color: #0d9488;"></i> Guard Attendance System &amp; Shift Records
                        </h5>
                        <p class="text-secondary small mb-0">Live attendance verification, clock-in/out timestamps, hours worked, and roll call management.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" onclick="exportAttendanceCSV()" class="btn btn-sm btn-outline-success rounded-pill px-3">
                            <i class="fa-solid fa-file-csv me-1"></i> Export Attendance
                        </button>
                    </div>
                </div>

                <!-- Attendance KPI Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded-3 bg-light border border-light-subtle d-flex align-items-center gap-3">
                            <div style="width: 42px; height: 42px; border-radius: 50%; background: #dcfce7; color: #166534; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                                <i class="fa-solid fa-user-check"></i>
                            </div>
                            <div>
                                <small class="text-secondary d-block font-semibold" style="font-size: 0.72rem;">ON DUTY / PRESENT</small>
                                <h4 class="fw-bold mb-0 text-success" id="att-kpi-present">0</h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded-3 bg-light border border-light-subtle d-flex align-items-center gap-3">
                            <div style="width: 42px; height: 42px; border-radius: 50%; background: #e0f2fe; color: #0369a1; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                                <i class="fa-solid fa-calendar-days"></i>
                            </div>
                            <div>
                                <small class="text-secondary d-block font-semibold" style="font-size: 0.72rem;">TOTAL LOGGED RECORDS</small>
                                <h4 class="fw-bold mb-0 text-primary" id="att-kpi-total">0</h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded-3 bg-light border border-light-subtle d-flex align-items-center gap-3">
                            <div style="width: 42px; height: 42px; border-radius: 50%; background: #ccfbf1; color: #0f766e; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                                <i class="fa-solid fa-hourglass-half"></i>
                            </div>
                            <div>
                                <small class="text-secondary d-block font-semibold" style="font-size: 0.72rem;">TOTAL HOURS WORKED</small>
                                <h4 class="fw-bold mb-0 text-teal" id="att-kpi-hours" style="color: #0d9488;">0 hrs</h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded-3 bg-light border border-light-subtle d-flex align-items-center gap-3">
                            <div style="width: 42px; height: 42px; border-radius: 50%; background: #fee2e2; color: #991b1b; display: flex; align-items: center; justify-content: center; font-size: 1.1rem;">
                                <i class="fa-solid fa-user-xmark"></i>
                            </div>
                            <div>
                                <small class="text-secondary d-block font-semibold" style="font-size: 0.72rem;">ABSENCES / MISSED</small>
                                <h4 class="fw-bold mb-0 text-danger" id="att-kpi-absent">0</h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters & Search Bar -->
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 p-2 bg-light rounded-3 mb-3">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <input type="date" id="att_start_date" value="<?php echo date('Y-m-01'); ?>" class="form-control form-control-sm" style="width: 135px;" onchange="loadAttendanceRecords()">
                        <span class="text-muted small">to</span>
                        <input type="date" id="att_end_date" value="<?php echo date('Y-m-t'); ?>" class="form-control form-control-sm" style="width: 135px;" onchange="loadAttendanceRecords()">
                        
                        <select id="att_filter_post" class="form-select form-select-sm" style="width: 140px;" onchange="loadAttendanceRecords()">
                            <option value="">All Posts</option>
                            <?php foreach ($posts as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['post_name']); ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select id="att_filter_status" class="form-select form-select-sm" style="width: 130px;" onchange="loadAttendanceRecords()">
                            <option value="">All Statuses</option>
                            <option value="on_duty">On Duty</option>
                            <option value="completed">Completed</option>
                            <option value="scheduled">Scheduled</option>
                            <option value="absent">Absent</option>
                        </select>
                    </div>

                    <button type="button" onclick="loadAttendanceRecords()" class="btn btn-sm btn-primary rounded-pill px-3">
                        <i class="fa-solid fa-filter me-1"></i> Apply Filter
                    </button>
                </div>

                <!-- Attendance Table -->
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="attendanceTable" style="font-size: 0.85rem;">
                        <thead class="table-light small text-secondary text-uppercase">
                            <tr>
                                <th>Duty Date</th>
                                <th>Officer / Staff</th>
                                <th>Post / Gate</th>
                                <th>Shift Template</th>
                                <th>Clock In Time</th>
                                <th>Clock Out Time</th>
                                <th>Total Worked</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="attendance-tbody">
                            <tr><td colspan="9" class="text-center py-4 text-muted">Loading attendance records...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ==============================================================
             TAB 3: SHIFT TEMPLATES
             ============================================================== -->
        <div class="tab-pane fade <?php echo ($active_tab === 'shifts') ? 'show active' : ''; ?>" id="shifts-tab-pane" role="tabpanel">
            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white mb-3">
                <div class="d-flex align-items-center justify-content-between mb-3 pb-3 border-bottom">
                    <div>
                        <h5 class="fw-bold mb-1 text-dark" style="font-family: 'Outfit', sans-serif;">Shift Templates</h5>
                        <p class="text-secondary small mb-0">Define reusable shifts (Morning, Afternoon, Night, 24-hr Patrol).</p>
                    </div>
                    <button type="button" class="btn btn-sm btn-primary rounded-pill px-3" onclick="openShiftTemplateModal(0)">
                        <i class="fa-solid fa-plus me-1"></i> Add Shift Template
                    </button>
                </div>

                <div class="row g-3">
                    <?php foreach ($shifts as $s): ?>
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="p-3 border rounded-3 bg-light d-flex align-items-center justify-content-between" style="border-left: 4px solid <?php echo $s['color_code']; ?> !important;">
                                <div>
                                    <strong class="d-block text-dark"><?php echo htmlspecialchars($s['name']); ?></strong>
                                    <span class="text-secondary small">
                                        <i class="fa-solid fa-clock me-1"></i> <?php echo date('h:i A', strtotime($s['start_time'])); ?> - <?php echo date('h:i A', strtotime($s['end_time'])); ?>
                                    </span>
                                    <div>
                                        <span class="badge <?php echo $s['is_active'] ? 'bg-success' : 'bg-secondary'; ?> rounded-pill" style="font-size: 0.65rem;">
                                            <?php echo $s['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-1">
                                    <button type="button" class="btn btn-sm btn-light border rounded-circle" onclick="editShiftTemplate(<?php echo htmlspecialchars(json_encode($s)); ?>)">
                                        <i class="fa-solid fa-pen text-secondary" style="font-size: 0.75rem;"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-light border rounded-circle" onclick="deleteShiftTemplate(<?php echo $s['id']; ?>)">
                                        <i class="fa-solid fa-trash text-danger" style="font-size: 0.75rem;"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ==============================================================
             TAB 4: POSTS & STATIONS
             ============================================================== -->
        <div class="tab-pane fade <?php echo ($active_tab === 'posts') ? 'show active' : ''; ?>" id="posts-tab-pane" role="tabpanel">
            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white mb-3">
                <div class="d-flex align-items-center justify-content-between mb-3 pb-3 border-bottom">
                    <div>
                        <h5 class="fw-bold mb-1 text-dark" style="font-family: 'Outfit', sans-serif;">Security Posts &amp; Gates</h5>
                        <p class="text-secondary small mb-0">Manage security gates, patrol stations, watchtowers, and checkpoints.</p>
                    </div>
                    <button type="button" class="btn btn-sm btn-primary rounded-pill px-3" onclick="openPostModal(0)">
                        <i class="fa-solid fa-plus me-1"></i> Add Post / Gate
                    </button>
                </div>

                <div class="row g-3">
                    <?php foreach ($posts as $p): ?>
                        <div class="col-12 col-md-6 col-lg-4">
                            <div class="p-3 border rounded-3 bg-light d-flex align-items-center justify-content-between">
                                <div>
                                    <strong class="d-block text-dark"><?php echo htmlspecialchars($p['post_name']); ?></strong>
                                    <span class="text-secondary small d-block"><?php echo htmlspecialchars($p['location_description'] ?? 'Main Area'); ?></span>
                                    <?php if (!empty($p['phone_extension'])): ?>
                                        <span class="badge bg-secondary rounded-pill" style="font-size: 0.68rem;">Ext: <?php echo htmlspecialchars($p['phone_extension']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex align-items-center gap-1">
                                    <button type="button" class="btn btn-sm btn-light border rounded-circle" onclick="editPost(<?php echo htmlspecialchars(json_encode($p)); ?>)">
                                        <i class="fa-solid fa-pen text-secondary" style="font-size: 0.75rem;"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-light border rounded-circle" onclick="deletePost(<?php echo $p['id']; ?>)">
                                        <i class="fa-solid fa-trash text-danger" style="font-size: 0.75rem;"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ==============================================================
             TAB 5: INCIDENT FORENSICS
             ============================================================== -->
        <div class="tab-pane fade <?php echo ($active_tab === 'forensics') ? 'show active' : ''; ?>" id="forensics-tab-pane" role="tabpanel">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #ffffff;">
                <div class="card-body p-4 p-md-5">
                    <div class="row align-items-center g-4">
                        <div class="col-lg-12">
                            <span class="badge bg-danger bg-opacity-25 text-danger border border-danger border-opacity-50 rounded-pill px-3 py-1 mb-2" style="font-size: 0.75rem; letter-spacing: 0.05em;">
                                <i class="fa-solid fa-shield-halved me-1"></i> FORENSIC SECURITY TIMELINE AUDIT
                            </span>
                            <h3 class="fw-bold mb-2" style="font-family: 'Outfit', sans-serif;">Who was on duty when it happened?</h3>
                            <p class="text-slate-300 small mb-4">
                                Search any historical incident timestamp to instantly pull verified on-duty officers, assigned gates, clock-in records, and supervisors.
                            </p>

                            <!-- Forensics Search Input -->
                            <form onsubmit="event.preventDefault(); runForensicSearch();" class="d-flex flex-wrap align-items-center gap-2">
                                <div class="flex-grow-1" style="min-width: 240px; max-width: 380px;">
                                    <input type="datetime-local" id="investigate_dt" required class="form-control form-control-lg rounded-pill border-0 shadow-sm px-4" style="font-size: 0.95rem; background: #ffffff; color: #0f172a;">
                                </div>
                                <button type="submit" class="btn btn-danger btn-lg rounded-pill px-4 fw-bold shadow-sm" style="font-size: 0.95rem;">
                                    <i class="fa-solid fa-magnifying-glass me-1"></i> Investigate Duty
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FORENSIC INVESTIGATION RESULTS CONTAINER -->
            <div id="investigationResultsContainer" style="display: none;" class="card border-0 shadow-sm rounded-4 p-4 bg-white border-start border-danger border-4 mb-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                    <div>
                        <span class="badge bg-danger text-white rounded-pill px-3 py-1 mb-1" id="inv-count-badge">Found 0 Officers</span>
                        <h5 class="fw-bold mb-0 text-dark" id="inv-timestamp-title" style="font-family: 'Outfit', sans-serif;">Incident Time: -</h5>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                            <i class="fa-solid fa-print me-1"></i> Print Duty Report
                        </button>
                        <button type="button" onclick="document.getElementById('investigationResultsContainer').style.display='none';" class="btn btn-sm btn-light rounded-pill px-2">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-secondary text-uppercase">
                            <tr>
                                <th>Officer</th>
                                <th>Stationed Post / Gate</th>
                                <th>Shift Schedule</th>
                                <th>Duty Attendance &amp; Clock-In</th>
                                <th>Supervisor</th>
                                <th>Contact</th>
                            </tr>
                        </thead>
                        <tbody id="inv-results-tbody">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ==============================================================
             TAB 6: DUTY LOG & EXPORT
             ============================================================== -->
        <div class="tab-pane fade <?php echo ($active_tab === 'log') ? 'show active' : ''; ?>" id="log-tab-pane" role="tabpanel">
            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 pb-3 border-bottom">
                    <div>
                        <h5 class="fw-bold mb-1 text-dark" style="font-family: 'Outfit', sans-serif;">
                            <i class="fa-solid fa-list-check text-primary me-2"></i> Roster History &amp; Attendance Log
                        </h5>
                        <p class="text-secondary small mb-0">Historical records of all duty schedules, clock-in times, and notes.</p>
                    </div>
                    <button type="button" onclick="exportAttendanceCSV()" class="btn btn-outline-success rounded-pill px-3">
                        <i class="fa-solid fa-file-csv me-1"></i> Export Roster CSV
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="rosterHistoryTable">
                        <thead class="table-light small text-secondary text-uppercase">
                            <tr>
                                <th>Duty Date</th>
                                <th>Staff / Guard</th>
                                <th>Post / Gate</th>
                                <th>Shift Template</th>
                                <th>Status</th>
                                <th>Clock In / Out</th>
                                <th>Supervisor</th>
                            </tr>
                        </thead>
                        <tbody id="history-log-tbody">
                            <tr><td colspan="7" class="text-center py-4 text-muted">Loading duty log records...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ==============================================================
     MODAL: SCHEDULE / DISTRIBUTE ROSTER SHIFTS (With Repeat & Bulk)
     ============================================================== -->
<div class="modal fade" id="scheduleShiftModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" id="scheduleShiftForm" onsubmit="handleScheduleFormSubmit(event)" class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem;">
            <div class="modal-header px-4 py-3 border-0 bg-light">
                <div>
                    <h5 class="modal-title fw-bold text-dark" style="font-family: 'Outfit', sans-serif;">
                        <i class="fa-solid fa-calendar-plus text-primary me-2"></i> Distribute &amp; Assign Duty Roster
                    </h5>
                    <small class="text-secondary">Schedule recurring shifts for single or multiple security guards</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                
                <!-- Staff selection (Supports Multi-Guard Bulk Distribution) -->
                <div class="mb-3">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <label class="form-label small fw-bold mb-0">Select Guard(s) / Personnel *</label>
                        <button type="button" class="btn btn-xs btn-link text-primary p-0 text-decoration-none" onclick="selectAllGuards()" style="font-size: 0.74rem;">
                            <i class="fa-solid fa-check-double me-1"></i> Select All Security
                        </button>
                    </div>
                    <select name="guard_user_ids[]" id="modal_guard_user_ids" multiple required class="form-select rounded-3" style="min-height: 100px;">
                        <?php foreach ($available_staff as $st): ?>
                            <option value="<?php echo $st['id']; ?>" data-role="<?php echo htmlspecialchars($st['user_role'] ?? ''); ?>">
                                <?php echo htmlspecialchars($st['name']); ?> 
                                [<?php echo htmlspecialchars($st['staff_role'] ?? $st['user_role']); ?><?php echo !empty($st['badge_id']) ? ' &bull; Badge: ' . htmlspecialchars($st['badge_id']) : ''; ?>]
                                <?php echo !empty($st['phone']) ? ' - ' . htmlspecialchars($st['phone']) : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted" style="font-size: 0.72rem;">Hold Ctrl (Windows) / Cmd (Mac) to select multiple guards for bulk shift assignment.</small>
                </div>

                <!-- Post & Shift template -->
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Stationed Post / Gate *</label>
                        <select name="post_id" id="modal_post_id" required class="form-select rounded-3">
                            <option value="">-- Choose Gate / Station --</option>
                            <?php foreach ($posts as $p): ?>
                                <option value="<?php echo $p['id']; ?>" <?php echo ($p['status'] !== 'active') ? 'disabled' : ''; ?>>
                                    <?php echo htmlspecialchars($p['post_name']); ?> <?php echo !empty($p['phone_extension']) ? '(' . htmlspecialchars($p['phone_extension']) . ')' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Shift Template *</label>
                        <select name="shift_id" id="modal_shift_id" required class="form-select rounded-3">
                            <option value="">-- Choose Shift Template --</option>
                            <?php foreach ($shifts as $s): ?>
                                <option value="<?php echo $s['id']; ?>" <?php echo (!$s['is_active']) ? 'disabled' : ''; ?>>
                                    <?php echo htmlspecialchars($s['name']); ?> (<?php echo date('H:i', strtotime($s['start_time'])); ?> - <?php echo date('H:i', strtotime($s['end_time'])); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- RECURRING REPEAT & DISTRIBUTION SETTINGS -->
                <div class="card p-3 rounded-3 bg-light border-0 mb-3">
                    <h6 class="fw-bold text-dark mb-2" style="font-size: 0.85rem;">
                        <i class="fa-solid fa-repeat text-primary me-1"></i> Recurrence &amp; Distribution Rule
                    </h6>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Repeat Frequency</label>
                            <select name="repeat_mode" id="modal_repeat_mode" class="form-select form-select-sm rounded-3" onchange="handleRepeatModeChange()">
                                <option value="once">Single Day (Does Not Repeat)</option>
                                <option value="daily" selected>Daily (Every Day)</option>
                                <option value="weekdays">Weekdays Only (Mon to Fri)</option>
                                <option value="weekends">Weekends Only (Sat &amp; Sun)</option>
                                <option value="custom">Custom Days of Week</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Recurrence Duration</label>
                            <select name="repeat_duration" id="modal_repeat_duration" class="form-select form-select-sm rounded-3" onchange="handleRepeatDurationChange()">
                                <option value="1_week">Repeat for 1 Week</option>
                                <option value="2_weeks">Repeat for 2 Weeks</option>
                                <option value="1_month" selected>Repeat for 1 Month</option>
                                <option value="3_months">Repeat for 3 Months</option>
                                <option value="custom">Custom End Date</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-2">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Start Duty Date *</label>
                            <input type="date" name="start_date" id="modal_start_date" required value="<?php echo date('Y-m-d'); ?>" class="form-control form-control-sm rounded-3 bg-white" onchange="calculateRecurrenceDates()">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">End Duty Date</label>
                            <input type="date" name="end_date" id="modal_end_date" value="<?php echo date('Y-m-d', strtotime('+1 month -1 day')); ?>" class="form-control form-control-sm rounded-3 bg-white">
                        </div>
                    </div>

                    <!-- Custom Weekdays Checkboxes -->
                    <div id="custom-weekdays-container" style="display: none;" class="mt-2 pt-2 border-top">
                        <label class="form-label small fw-bold d-block text-secondary mb-1">Select Specific Days of Week:</label>
                        <div class="d-flex flex-wrap gap-2">
                            <label class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-0" style="font-size: 0.75rem;">
                                <input type="checkbox" name="repeat_days[]" value="1" class="form-check-input me-1" checked> Mon
                            </label>
                            <label class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-0" style="font-size: 0.75rem;">
                                <input type="checkbox" name="repeat_days[]" value="2" class="form-check-input me-1" checked> Tue
                            </label>
                            <label class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-0" style="font-size: 0.75rem;">
                                <input type="checkbox" name="repeat_days[]" value="3" class="form-check-input me-1" checked> Wed
                            </label>
                            <label class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-0" style="font-size: 0.75rem;">
                                <input type="checkbox" name="repeat_days[]" value="4" class="form-check-input me-1" checked> Thu
                            </label>
                            <label class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-0" style="font-size: 0.75rem;">
                                <input type="checkbox" name="repeat_days[]" value="5" class="form-check-input me-1" checked> Fri
                            </label>
                            <label class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-0" style="font-size: 0.75rem;">
                                <input type="checkbox" name="repeat_days[]" value="6" class="form-check-input me-1" checked> Sat
                            </label>
                            <label class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-0" style="font-size: 0.75rem;">
                                <input type="checkbox" name="repeat_days[]" value="0" class="form-check-input me-1" checked> Sun
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Supervisor -->
                <div class="mb-2">
                    <label class="form-label small fw-bold">Designated Supervisor (Optional)</label>
                    <select name="supervisor_id" id="modal_supervisor_id" class="form-select rounded-3">
                        <option value="">-- No Supervisor Assigned --</option>
                        <?php foreach ($available_staff as $st): ?>
                            <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['name']); ?> (<?php echo htmlspecialchars($st['staff_role'] ?? $st['user_role']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>
            <div class="modal-footer px-4 py-3 bg-light border-0 d-flex justify-content-between">
                <span class="text-secondary small" id="distribution-preview-text">Future shifts will be visible across calendar.</span>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold" id="btn-submit-schedule">
                        <i class="fa-solid fa-check me-1"></i> Save &amp; Distribute Roster
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================
     MODAL: SHIFT INSPECTION & ATTENDANCE ACTIONS
     ============================================================== -->
<div class="modal fade" id="shiftDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header px-4 py-3 border-0 text-white" id="shift-modal-header" style="background: #2563eb;">
                <div>
                    <span class="badge bg-white bg-opacity-25 rounded-pill px-2 py-1 small" id="detail-shift-pill">Shift</span>
                    <h5 class="modal-title fw-bold mt-1 text-white" id="detail-officer-name">Officer Name</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                
                <div class="d-flex align-items-center gap-3 mb-3 p-3 bg-light rounded-3">
                    <div style="width: 46px; height: 46px; border-radius: 50%; background: #0284c7; color: white; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>
                    <div>
                        <strong class="d-block text-dark" id="detail-card-officer">Officer Name</strong>
                        <span class="text-secondary small" id="detail-card-badge">Badge ID</span>
                        <div class="small" id="detail-card-phone">Phone</div>
                    </div>
                </div>

                <div class="row g-2 mb-3 small">
                    <div class="col-6">
                        <span class="text-muted d-block">Duty Date</span>
                        <strong class="text-dark" id="detail-duty-date">-</strong>
                    </div>
                    <div class="col-6">
                        <span class="text-muted d-block">Assigned Station</span>
                        <strong class="text-primary" id="detail-post-name">-</strong>
                    </div>
                    <div class="col-6">
                        <span class="text-muted d-block">Shift Hours</span>
                        <strong class="text-dark" id="detail-shift-hours">-</strong>
                    </div>
                    <div class="col-6">
                        <span class="text-muted d-block">Supervisor</span>
                        <strong class="text-dark" id="detail-supervisor">-</strong>
                    </div>
                </div>

                <div class="p-3 border rounded-3 mb-3">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="small text-muted">Attendance Status:</span>
                        <span class="badge rounded-pill text-uppercase px-3 py-1" id="detail-status-badge">SCHEDULED</span>
                    </div>
                    <div class="d-flex align-items-center justify-content-between small text-secondary">
                        <span>Clock In: <strong id="detail-clock-in" class="text-success">—</strong></span>
                        <span>Clock Out: <strong id="detail-clock-out" class="text-dark">—</strong></span>
                    </div>
                </div>

                <!-- Quick Attendance Status Updater -->
                <div class="mb-2">
                    <label class="form-label small fw-bold">Manual Attendance Override:</label>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-outline-success rounded-pill" onclick="quickUpdateStatus('on_duty')">
                            <i class="fa-solid fa-arrow-right-to-bracket me-1"></i> Mark On Duty (Clock In)
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-info rounded-pill" onclick="quickUpdateStatus('completed')">
                            <i class="fa-solid fa-flag-checkered me-1"></i> Mark Completed (Clock Out)
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger rounded-pill" onclick="quickUpdateStatus('absent')">
                            <i class="fa-solid fa-user-xmark me-1"></i> Mark Absent
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" onclick="quickUpdateStatus('scheduled')">
                            Reset to Scheduled
                        </button>
                    </div>
                </div>

            </div>
            <div class="modal-footer px-4 py-3 bg-light border-0 d-flex justify-content-between">
                <button type="button" class="btn btn-outline-danger rounded-pill px-3" onclick="deleteRosterShift()">
                    <i class="fa-solid fa-trash me-1"></i> Remove Shift
                </button>
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ==============================================================
     MODAL: ADD / EDIT SHIFT TEMPLATE
     ============================================================== -->
<div class="modal fade" id="shiftTemplateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="shiftTemplateForm" class="modal-content border-0 shadow-lg rounded-4">
            <input type="hidden" name="action_save_shift_template" value="1">
            <input type="hidden" name="shift_id" id="tpl_shift_id" value="0">
            <div class="modal-header px-4 py-3 border-0 bg-light">
                <h5 class="modal-title fw-bold text-dark" id="tpl_modal_title" style="font-family: 'Outfit', sans-serif;">Add Shift Template</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label small fw-bold">Shift Name *</label>
                    <input type="text" name="name" id="tpl_name" required placeholder="e.g. Morning Patrol Shift" class="form-control rounded-3">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-bold">Start Time *</label>
                        <input type="time" name="start_time" id="tpl_start_time" required class="form-control rounded-3">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold">End Time *</label>
                        <input type="time" name="end_time" id="tpl_end_time" required class="form-control rounded-3">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Color Theme *</label>
                    <div class="d-flex align-items-center gap-2">
                        <input type="color" name="color_code" id="tpl_color_code" value="#2563eb" class="form-control form-control-color rounded-3 p-1" style="width: 50px; height: 38px;">
                    </div>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="is_active" id="tpl_is_active" value="1" checked>
                    <label class="form-check-label small fw-semibold" for="tpl_is_active">Template Active for Scheduling</label>
                </div>
            </div>
            <div class="modal-footer px-4 py-3 bg-light border-0">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Save Shift</button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================
     MODAL: ADD / EDIT POST / WORKSTATION
     ============================================================== -->
<div class="modal fade" id="postModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="postForm" class="modal-content border-0 shadow-lg rounded-4">
            <input type="hidden" name="action_save_post" value="1">
            <input type="hidden" name="post_id" id="pst_post_id" value="0">
            <div class="modal-header px-4 py-3 border-0 bg-light">
                <h5 class="modal-title fw-bold text-dark" id="pst_modal_title" style="font-family: 'Outfit', sans-serif;">Add Gate / Post</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label small fw-bold">Post / Workstation Name *</label>
                    <input type="text" name="post_name" id="pst_name" required placeholder="e.g. West Perimeter Gate" class="form-control rounded-3">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Location Description</label>
                    <textarea name="location_description" id="pst_desc" rows="2" placeholder="e.g. Near Block C turnstile" class="form-control rounded-3"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Phone / Intercom Extension</label>
                    <input type="text" name="phone_extension" id="pst_ext" placeholder="e.g. Ext 105 or 080..." class="form-control rounded-3">
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Status</label>
                    <select name="status" id="pst_status" class="form-select rounded-3">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer px-4 py-3 bg-light border-0">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-info text-white rounded-pill px-4 fw-bold">Save Post</button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================
     MODAL: ADMIN INSTANT GUARD CLOCK IN / OUT
     ============================================================== -->
<div class="modal fade" id="adminQuickClockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form id="adminQuickClockForm" onsubmit="handleAdminQuickClockSubmit(event)" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header px-4 py-3 border-0 bg-light">
                <h5 class="modal-title fw-bold text-dark" style="font-family: 'Outfit', sans-serif;">
                    <i class="fa-solid fa-clock text-success me-2"></i> Instant Guard Clock In / Clock Out
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="small text-secondary mb-3">Record instant attendance or roll call for any security personnel on shift.</p>
                
                <div class="mb-3">
                    <label class="form-label small fw-bold">Select Security Officer *</label>
                    <select id="quick_clock_guard_id" required class="form-select rounded-3">
                        <option value="">-- Choose Officer --</option>
                        <?php foreach ($available_staff as $st): ?>
                            <option value="<?php echo $st['id']; ?>">
                                <?php echo htmlspecialchars($st['name']); ?> [<?php echo htmlspecialchars($st['staff_role'] ?? $st['user_role']); ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Action Type *</label>
                    <div class="d-flex gap-3">
                        <label class="btn btn-outline-success flex-fill py-2 rounded-3 text-start">
                            <input type="radio" name="quick_clock_type" value="clock_in" checked class="form-check-input me-2">
                            <strong><i class="fa-solid fa-arrow-right-to-bracket me-1"></i> Clock In</strong> (Mark On Duty)
                        </label>
                        <label class="btn btn-outline-danger flex-fill py-2 rounded-3 text-start">
                            <input type="radio" name="quick_clock_type" value="clock_out" class="form-check-input me-2">
                            <strong><i class="fa-solid fa-arrow-right-from-bracket me-1"></i> Clock Out</strong> (Complete Shift)
                        </label>
                    </div>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-bold">Handover Notes / Observation (Optional)</label>
                    <textarea id="quick_clock_notes" rows="2" class="form-control rounded-3" placeholder="Add any security report, incident remarks, or shift handover notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer px-4 py-3 bg-light border-0">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold" id="btn-admin-clock-submit">
                    <i class="fa-solid fa-check me-1"></i> Record Attendance
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ==============================================================
// CALENDAR & ROSTER JAVASCRIPT ENGINE
// ==============================================================

let currentCalendarYear = new Date().getFullYear();
let currentCalendarMonth = new Date().getMonth() + 1; // 1-12
let currentView = 'month'; // 'month', 'week', 'day'
let currentLoadedEvents = [];
let currentEventsByDate = {};
let selectedShiftObject = null;
let currentSelectedDayDate = '<?php echo date('Y-m-d'); ?>';

document.addEventListener('DOMContentLoaded', () => {
    syncQuickMonthYearSelectors();
    loadCalendarEvents();
    loadLiveGuardsFeed();
    loadRosterHistoryLog();
    
    // Auto-poll live guards feed every 30 seconds
    setInterval(loadLiveGuardsFeed, 30000);
});

function syncQuickMonthYearSelectors() {
    const mSelect = document.getElementById('cal_quick_month');
    const ySelect = document.getElementById('cal_quick_year');
    if (mSelect) mSelect.value = currentCalendarMonth;
    if (ySelect) ySelect.value = currentCalendarYear;
}

function jumpToMonthYear() {
    const mSelect = document.getElementById('cal_quick_month');
    const ySelect = document.getElementById('cal_quick_year');
    if (mSelect && ySelect) {
        currentCalendarMonth = parseInt(mSelect.value, 10);
        currentCalendarYear = parseInt(ySelect.value, 10);
        loadCalendarEvents();
    }
}

function changeMonth(delta) {
    currentCalendarMonth += delta;
    if (currentCalendarMonth < 1) {
        currentCalendarMonth = 12;
        currentCalendarYear -= 1;
    } else if (currentCalendarMonth > 12) {
        currentCalendarMonth = 1;
        currentCalendarYear += 1;
    }
    syncQuickMonthYearSelectors();
    loadCalendarEvents();
}

function jumpToToday() {
    const now = new Date();
    currentCalendarYear = now.getFullYear();
    currentCalendarMonth = now.getMonth() + 1;
    currentSelectedDayDate = now.toISOString().split('T')[0];
    syncQuickMonthYearSelectors();
    loadCalendarEvents();
}

function setCalendarView(viewMode) {
    currentView = viewMode;
    document.getElementById('btn-view-month').className = (viewMode === 'month') ? 'btn btn-xs btn-sm btn-primary rounded-pill px-2 py-1 fw-semibold' : 'btn btn-xs btn-sm btn-light rounded-pill px-2 py-1 fw-semibold text-secondary';
    document.getElementById('btn-view-week').className = (viewMode === 'week') ? 'btn btn-xs btn-sm btn-primary rounded-pill px-2 py-1 fw-semibold' : 'btn btn-xs btn-sm btn-light rounded-pill px-2 py-1 fw-semibold text-secondary';
    document.getElementById('btn-view-day').className = (viewMode === 'day') ? 'btn btn-xs btn-sm btn-primary rounded-pill px-2 py-1 fw-semibold' : 'btn btn-xs btn-sm btn-light rounded-pill px-2 py-1 fw-semibold text-secondary';

    document.getElementById('calendarMainContainer').style.display = (viewMode === 'month') ? 'flex' : 'none';
    document.getElementById('calendarWeekContainer').style.display = (viewMode === 'week') ? 'block' : 'none';
    document.getElementById('calendarDayContainer').style.display = (viewMode === 'day') ? 'block' : 'none';

    renderCalendar();
}

function loadCalendarEvents() {
    const role = document.getElementById('filter_role') ? document.getElementById('filter_role').value : '';
    const post = document.getElementById('filter_post') ? document.getElementById('filter_post').value : '';
    const shift = document.getElementById('filter_shift') ? document.getElementById('filter_shift').value : '';
    const user = document.getElementById('filter_user') ? document.getElementById('filter_user').value : '';

    const url = `../api/roster_query.php?action=calendar_events&year=${currentCalendarYear}&month=${currentCalendarMonth}&role_filter=${encodeURIComponent(role)}&post_id=${post}&shift_id=${shift}&user_id=${user}`;

    fetch(url)
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                currentLoadedEvents = res.events || [];
                currentEventsByDate = res.events_by_date || {};
                renderCalendar();
            } else {
                console.error('Failed to load roster events:', res.error);
            }
        })
        .catch(err => console.error('Error fetching events:', err));
}

function resetRosterFilters() {
    if (document.getElementById('filter_role')) document.getElementById('filter_role').value = '';
    if (document.getElementById('filter_post')) document.getElementById('filter_post').value = '';
    if (document.getElementById('filter_shift')) document.getElementById('filter_shift').value = '';
    if (document.getElementById('filter_user')) document.getElementById('filter_user').value = '';
    loadCalendarEvents();
}

function renderCalendar() {
    if (currentView === 'month') {
        renderMonthGrid();
    } else if (currentView === 'week') {
        renderWeekView();
    } else if (currentView === 'day') {
        renderDayView(currentSelectedDayDate);
    }
}

function renderMonthGrid() {
    const grid = document.getElementById('calendarDaysGrid');
    if (!grid) return;
    grid.innerHTML = '';

    const firstDayIndex = new Date(currentCalendarYear, currentCalendarMonth - 1, 1).getDay(); // 0 = Sun
    const totalDaysInMonth = new Date(currentCalendarYear, currentCalendarMonth, 0).getDate();
    const prevMonthDays = new Date(currentCalendarYear, currentCalendarMonth - 1, 0).getDate();

    const todayStr = new Date().toISOString().split('T')[0];
    const totalRendered = firstDayIndex + totalDaysInMonth;
    const totalSlots = (totalRendered > 35) ? 42 : 35;
    const numRows = totalSlots / 7;
    grid.style.gridTemplateRows = `repeat(${numRows}, 1fr)`;

    // Leading previous month days
    const prevMonth = (currentCalendarMonth === 1) ? 12 : currentCalendarMonth - 1;
    const prevYear = (currentCalendarMonth === 1) ? currentCalendarYear - 1 : currentCalendarYear;

    for (let i = firstDayIndex - 1; i >= 0; i--) {
        const dayNum = prevMonthDays - i;
        const dateStr = `${prevYear}-${String(prevMonth).padStart(2, '0')}-${String(dayNum).padStart(2, '0')}`;
        const events = currentEventsByDate[dateStr] || [];

        const cell = document.createElement('div');
        cell.className = 'cal-cell other-month';
        cell.setAttribute('data-date', dateStr);

        let eventsHtml = '';
        events.slice(0, 2).forEach(e => {
            eventsHtml += `
                <div class="roster-chip" style="border-left-color: ${e.color_code || '#2563eb'};" onclick="inspectRosterShift(${escapeJsonForAttr(e)})">
                    <span class="text-truncate"><strong>${escapeHtml(e.officer_name)}</strong></span>
                    <span class="status-dot ${e.status}"></span>
                </div>
            `;
        });

        cell.innerHTML = `
            <div class="cal-cell-header">
                <span class="cal-date-number text-muted">${dayNum}</span>
            </div>
            <div class="cal-events-list">${eventsHtml}</div>
        `;
        grid.appendChild(cell);
    }

    // Current month days
    for (let day = 1; day <= totalDaysInMonth; day++) {
        const dateStr = `${currentCalendarYear}-${String(currentCalendarMonth).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const isToday = (dateStr === todayStr);

        const cell = document.createElement('div');
        cell.className = `cal-cell ${isToday ? 'is-today' : ''}`;
        cell.setAttribute('data-date', dateStr);

        const events = currentEventsByDate[dateStr] || [];
        let eventsHtml = '';
        const maxDisplay = 3;
        const displayEvents = events.slice(0, maxDisplay);

        displayEvents.forEach(e => {
            const shiftColor = e.color_code || '#2563eb';
            const statusClass = e.status || 'scheduled';
            eventsHtml += `
                <div class="roster-chip" style="border-left-color: ${shiftColor};" onclick="inspectRosterShift(${escapeJsonForAttr(e)})" title="${escapeHtml(e.officer_name)} - ${escapeHtml(e.post_name)} (${e.formatted_start_time})">
                    <span class="text-truncate">
                        <i class="fa-solid fa-user-shield me-1" style="color: ${shiftColor}; font-size: 0.62rem;"></i>
                        <strong>${escapeHtml(e.officer_name)}</strong> &bull; ${escapeHtml(e.post_name || 'Gate')}
                    </span>
                    <span class="status-dot ${statusClass}" title="${statusClass}"></span>
                </div>
            `;
        });

        if (events.length > maxDisplay) {
            const rem = events.length - maxDisplay;
            eventsHtml += `
                <div class="badge bg-light text-primary border text-center py-0 mt-1" style="cursor: pointer; font-size: 0.65rem;" onclick="viewDayFromCell('${dateStr}')">
                    +${rem} more
                </div>
            `;
        }

        cell.innerHTML = `
            <div class="cal-cell-header">
                <span class="cal-date-number">${day}</span>
                <button type="button" class="btn btn-xs btn-primary cal-add-btn" onclick="openScheduleModalWithDate('${dateStr}')" title="Assign shift on this day">
                    <i class="fa-solid fa-plus"></i>
                </button>
            </div>
            <div class="cal-events-list">
                ${eventsHtml}
            </div>
        `;

        grid.appendChild(cell);
    }

    // Trailing next month days
    const nextDays = totalSlots - totalRendered;
    const nextMonth = (currentCalendarMonth === 12) ? 1 : currentCalendarMonth + 1;
    const nextYear = (currentCalendarMonth === 12) ? currentCalendarYear + 1 : currentCalendarYear;

    for (let i = 1; i <= nextDays; i++) {
        const dateStr = `${nextYear}-${String(nextMonth).padStart(2, '0')}-${String(i).padStart(2, '0')}`;
        const events = currentEventsByDate[dateStr] || [];

        const cell = document.createElement('div');
        cell.className = 'cal-cell other-month';
        cell.setAttribute('data-date', dateStr);

        let eventsHtml = '';
        events.slice(0, 2).forEach(e => {
            eventsHtml += `
                <div class="roster-chip" style="border-left-color: ${e.color_code || '#2563eb'};" onclick="inspectRosterShift(${escapeJsonForAttr(e)})">
                    <span class="text-truncate"><strong>${escapeHtml(e.officer_name)}</strong></span>
                    <span class="status-dot ${e.status}"></span>
                </div>
            `;
        });

        cell.innerHTML = `
            <div class="cal-cell-header">
                <span class="cal-date-number text-muted">${i}</span>
            </div>
            <div class="cal-events-list">${eventsHtml}</div>
        `;
        grid.appendChild(cell);
    }
}

function renderWeekView() {
    const container = document.getElementById('calendarWeekColumns');
    if (!container) return;
    container.innerHTML = '';

    const selDate = new Date(currentSelectedDayDate);
    const dayOfWeek = selDate.getDay();
    const startOfWeek = new Date(selDate);
    startOfWeek.setDate(selDate.getDate() - dayOfWeek);

    for (let i = 0; i < 7; i++) {
        const d = new Date(startOfWeek);
        d.setDate(startOfWeek.getDate() + i);
        const dateStr = d.toISOString().split('T')[0];
        const dayName = d.toLocaleDateString('en-US', { weekday: 'short' });
        const isToday = (dateStr === new Date().toISOString().split('T')[0]);

        const events = currentEventsByDate[dateStr] || [];
        let eventsHtml = '';

        if (events.length === 0) {
            eventsHtml = `<div class="text-muted small text-center py-4">No duties scheduled</div>`;
        } else {
            events.forEach(e => {
                const shiftColor = e.color_code || '#2563eb';
                eventsHtml += `
                    <div class="p-2 mb-2 rounded-3 border bg-white shadow-sm" style="border-left: 4px solid ${shiftColor} !important; cursor: pointer;" onclick="inspectRosterShift(${escapeJsonForAttr(e)})">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="badge text-white" style="background: ${shiftColor}; font-size: 0.65rem;">${escapeHtml(e.shift_name)}</span>
                            <span class="status-dot ${e.status}"></span>
                        </div>
                        <strong class="d-block text-dark" style="font-size: 0.85rem;">${escapeHtml(e.officer_name)}</strong>
                        <div class="small text-secondary" style="font-size: 0.72rem;">${escapeHtml(e.post_name || 'Gate')} &bull; ${e.formatted_start_time}-${e.formatted_end_time}</div>
                    </div>
                `;
            });
        }

        const col = document.createElement('div');
        col.className = 'col-12 col-md-4 col-lg';
        col.innerHTML = `
            <div class="card h-100 border rounded-4 ${isToday ? 'border-primary' : ''} bg-light p-2">
                <div class="d-flex align-items-center justify-content-between mb-2 p-2 border-bottom">
                    <div>
                        <strong class="d-block text-dark small">${dayName}</strong>
                        <span class="text-secondary" style="font-size: 0.75rem;">${d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}</span>
                    </div>
                    <button type="button" class="btn btn-xs btn-primary rounded-circle" onclick="openScheduleModalWithDate('${dateStr}')" style="width: 24px; height: 24px; padding: 0;">
                        <i class="fa-solid fa-plus" style="font-size: 0.7rem;"></i>
                    </button>
                </div>
                <div class="d-flex flex-column gap-1" style="max-height: 450px; overflow-y: auto;">
                    ${eventsHtml}
                </div>
            </div>
        `;
        container.appendChild(col);
    }
}

function renderDayView(dateStr) {
    currentSelectedDayDate = dateStr;
    const title = document.getElementById('day-view-date-title');
    const subtitle = document.getElementById('day-view-date-subtitle');
    const tbody = document.getElementById('day-view-table-body');
    if (!tbody) return;

    const d = new Date(dateStr);
    if (title) title.innerText = `Duty Assignments for ${d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })}`;

    const events = currentEventsByDate[dateStr] || [];
    if (subtitle) subtitle.innerText = `${events.length} personnel scheduled for active duty`;

    if (events.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-muted">No scheduled staff duties for this date.</td></tr>`;
        return;
    }

    let html = '';
    events.forEach(e => {
        let stBadge = 'bg-secondary';
        if (e.status === 'on_duty') stBadge = 'bg-success';
        else if (e.status === 'completed') stBadge = 'bg-info text-dark';
        else if (e.status === 'scheduled') stBadge = 'bg-primary';
        else if (e.status === 'absent') stBadge = 'bg-danger';

        let dayActionBtn = '';
        if (e.status === 'on_duty') {
            dayActionBtn = `<button type="button" class="btn btn-xs btn-danger rounded-pill px-2 py-1 fw-bold me-1" onclick="adminTriggerClockInOut(${e.id}, 'clock_out', '${escapeHtml(e.officer_name)}')"><i class="fa-solid fa-arrow-right-from-bracket me-1"></i> Clock Out</button>`;
        } else if (e.status === 'scheduled' || e.status === 'absent' || !e.clock_in_time) {
            dayActionBtn = `<button type="button" class="btn btn-xs btn-success rounded-pill px-2 py-1 fw-bold me-1" onclick="adminTriggerClockInOut(${e.id}, 'clock_in', '${escapeHtml(e.officer_name)}')"><i class="fa-solid fa-arrow-right-to-bracket me-1"></i> Clock In</button>`;
        }

        html += `
            <tr>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div style="width: 32px; height: 32px; border-radius: 50%; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                            <i class="fa-solid fa-user-shield"></i>
                        </div>
                        <div>
                            <strong class="d-block text-dark">${escapeHtml(e.officer_name)}</strong>
                            <small class="text-muted">${escapeHtml(e.badge_id || e.staff_role_title || 'Security')}</small>
                        </div>
                    </div>
                </td>
                <td>
                    <strong class="text-primary">${escapeHtml(e.post_name || 'Main Gate')}</strong>
                    <div class="small text-muted">${escapeHtml(e.phone_extension || '')}</div>
                </td>
                <td>
                    <span class="badge rounded-pill text-white mb-1" style="background: ${e.color_code || '#2563eb'};">
                        ${escapeHtml(e.shift_name)}
                    </span>
                    <div class="small text-secondary">${e.formatted_start_time} - ${e.formatted_end_time}</div>
                </td>
                <td>
                    <span class="badge ${stBadge} rounded-pill text-uppercase px-2 py-1" style="font-size: 0.7rem;">
                        ${escapeHtml(e.status.replace('_', ' '))}
                    </span>
                </td>
                <td>
                    ${e.clock_in_time ? `<div class="small text-success"><i class="fa-solid fa-arrow-right-to-bracket me-1"></i> ${e.formatted_clock_in || e.clock_in_time.substring(11, 16)}</div>` : '<span class="text-muted small">Not clocked in</span>'}
                    ${e.clock_out_time ? `<div class="small text-secondary"><i class="fa-solid fa-arrow-right-from-bracket me-1"></i> ${e.formatted_clock_out || e.clock_out_time.substring(11, 16)}</div>` : ''}
                </td>
                <td>
                    <span class="small text-secondary">${escapeHtml(e.supervisor_name || 'None')}</span>
                </td>
                <td class="text-end">
                    ${dayActionBtn}
                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-2 py-1" onclick="inspectRosterShift(${escapeJsonForAttr(e)})">
                        Inspect
                    </button>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

function viewDayFromCell(dateStr) {
    currentSelectedDayDate = dateStr;
    setCalendarView('day');
}

// ==============================================================
// REAL-TIME LIVE GUARDS FEED LOADER
// ==============================================================

function loadLiveGuardsFeed() {
    fetch('../api/roster_query.php?action=live_guards_feed')
        .then(r => r.json())
        .then(res => {
            const container = document.getElementById('live-guards-feed-container');
            const onDutyStat = document.getElementById('live-onduty-stat');
            if (onDutyStat) onDutyStat.innerText = res.on_duty_count || 0;

            if (!container) return;

            if (res.success && res.guards && res.guards.length > 0) {
                let html = '';
                res.guards.forEach(g => {
                    const isClockedIn = g.is_clocked_in;
                    const statusDot = isClockedIn ? 'on_duty' : 'scheduled';
                    const postName = g.post_name || 'Station';
                    const elapsed = g.elapsed_formatted || (isClockedIn ? 'On Duty' : 'Scheduled');

                    const liveClockBtn = isClockedIn ? `
                        <button type="button" class="btn btn-xs btn-danger rounded-pill px-2 py-0 ms-1 fw-bold shadow-sm" style="font-size: 0.65rem;" onclick="event.stopPropagation(); adminTriggerClockInOut(${g.id}, 'clock_out', '${escapeHtml(g.officer_name)}')" title="Clock Out ${escapeHtml(g.officer_name)}">
                            <i class="fa-solid fa-arrow-right-from-bracket me-1"></i> Clock Out
                        </button>
                    ` : `
                        <button type="button" class="btn btn-xs btn-success rounded-pill px-2 py-0 ms-1 fw-bold shadow-sm" style="font-size: 0.65rem;" onclick="event.stopPropagation(); adminTriggerClockInOut(${g.id}, 'clock_in', '${escapeHtml(g.officer_name)}')" title="Clock In ${escapeHtml(g.officer_name)}">
                            <i class="fa-solid fa-arrow-right-to-bracket me-1"></i> Clock In
                        </button>
                    `;

                    html += `
                        <div class="live-guard-chip flex-shrink-0" onclick="inspectRosterShift(${escapeJsonForAttr(g)})" style="cursor: pointer;">
                            <div class="live-guard-avatar">
                                <i class="fa-solid fa-user-shield"></i>
                            </div>
                            <div style="line-height: 1.15;">
                                <div class="d-flex align-items-center gap-1">
                                    <strong class="text-white" style="font-size: 0.8rem;">${escapeHtml(g.officer_name)}</strong>
                                    <span class="status-dot ${statusDot}"></span>
                                </div>
                                <div class="text-slate-300" style="font-size: 0.68rem;">
                                    <span class="text-info">${escapeHtml(postName)}</span> &bull; ${escapeHtml(elapsed)}
                                </div>
                            </div>
                            ${liveClockBtn}
                            ${g.officer_phone ? `
                                <a href="tel:${g.officer_phone}" onclick="event.stopPropagation();" class="btn btn-xs btn-outline-light rounded-circle ms-1 p-0 d-flex align-items-center justify-content-center" style="width: 22px; height: 22px; font-size: 0.65rem;" title="Call ${g.officer_name}">
                                    <i class="fa-solid fa-phone"></i>
                                </a>
                            ` : ''}
                        </div>
                    `;
                });
                container.innerHTML = html;
            } else {
                container.innerHTML = `
                    <div class="text-slate-400 small py-1 px-2" style="font-size: 0.74rem;">
                        <i class="fa-solid fa-shield me-1"></i> No guards currently clocked in for the active hour. Central dispatch is standby.
                    </div>
                `;
            }
        })
        .catch(err => console.error('Error fetching live guards feed:', err));
}

// ==============================================================
// ATTENDANCE SYSTEM RECORDS & ROLL CALL
// ==============================================================

function loadAttendanceRecords() {
    const startDate = document.getElementById('att_start_date') ? document.getElementById('att_start_date').value : '';
    const endDate = document.getElementById('att_end_date') ? document.getElementById('att_end_date').value : '';
    const postId = document.getElementById('att_filter_post') ? document.getElementById('att_filter_post').value : '';
    const status = document.getElementById('att_filter_status') ? document.getElementById('att_filter_status').value : '';

    const url = `../api/roster_query.php?action=attendance_records&start_date=${startDate}&end_date=${endDate}&post_id=${postId}&status_filter=${status}`;

    fetch(url)
        .then(r => r.json())
        .then(res => {
            const tbody = document.getElementById('attendance-tbody');
            if (document.getElementById('att-kpi-present')) document.getElementById('att-kpi-present').innerText = res.count_present || 0;
            if (document.getElementById('att-kpi-total')) document.getElementById('att-kpi-total').innerText = res.total_records || 0;
            if (document.getElementById('att-kpi-hours')) document.getElementById('att-kpi-hours').innerText = `${res.total_hours_worked || 0} hrs`;
            if (document.getElementById('att-kpi-absent')) document.getElementById('att-kpi-absent').innerText = res.count_absent || 0;

            if (!tbody) return;

            if (res.success && res.records && res.records.length > 0) {
                let html = '';
                res.records.forEach(r => {
                    let stBadge = 'bg-secondary';
                    if (r.status === 'on_duty') stBadge = 'bg-success';
                    else if (r.status === 'completed') stBadge = 'bg-info text-dark';
                    else if (r.status === 'scheduled') stBadge = 'bg-primary';
                    else if (r.status === 'absent') stBadge = 'bg-danger';

                    let rowActionBtns = '';
                    if (r.status === 'on_duty') {
                        rowActionBtns = `
                            <button type="button" class="btn btn-xs btn-danger rounded-pill px-3 py-1 fw-bold shadow-sm" style="font-size: 0.74rem;" onclick="adminTriggerClockInOut(${r.id}, 'clock_out', '${escapeHtml(r.officer_name)}')">
                                <i class="fa-solid fa-arrow-right-from-bracket me-1"></i> Clock Out
                            </button>
                        `;
                    } else if (r.status === 'scheduled' || r.status === 'absent' || !r.clock_in_time) {
                        rowActionBtns = `
                            <button type="button" class="btn btn-xs btn-success rounded-pill px-3 py-1 fw-bold shadow-sm" style="font-size: 0.74rem;" onclick="adminTriggerClockInOut(${r.id}, 'clock_in', '${escapeHtml(r.officer_name)}')">
                                <i class="fa-solid fa-arrow-right-to-bracket me-1"></i> Clock In
                            </button>
                        `;
                    } else {
                        rowActionBtns = `
                            <span class="badge bg-light text-secondary border px-2 py-1 me-1" style="font-size: 0.7rem;">Completed</span>
                        `;
                    }

                    html += `
                        <tr>
                            <td><strong class="text-dark">${r.formatted_duty_date}</strong></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div style="width: 30px; height: 30px; border-radius: 50%; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 0.75rem;">
                                        <i class="fa-solid fa-user-shield"></i>
                                    </div>
                                    <div>
                                        <strong class="d-block text-dark">${escapeHtml(r.officer_name)}</strong>
                                        <small class="text-muted">${escapeHtml(r.badge_id || r.staff_role_title || 'Security')}</small>
                                    </div>
                                </div>
                            </td>
                            <td><strong class="text-primary">${escapeHtml(r.post_name || 'Main Gate')}</strong></td>
                            <td>
                                <span class="badge text-white" style="background: ${r.color_code || '#2563eb'}; font-size: 0.7rem;">${escapeHtml(r.shift_name)}</span>
                            </td>
                            <td>
                                ${r.clock_in_time ? `<strong class="text-success"><i class="fa-solid fa-check me-1"></i> ${r.formatted_clock_in}</strong>` : '<span class="text-muted small">Not clocked in</span>'}
                            </td>
                            <td>
                                ${r.clock_out_time ? `<strong class="text-dark">${r.formatted_clock_out}</strong>` : (r.status === 'on_duty' ? '<span class="badge bg-success bg-opacity-25 text-success">Active Now</span>' : '—')}
                            </td>
                            <td><span class="badge bg-light text-dark border font-semibold">${r.worked_formatted}</span></td>
                            <td><span class="badge ${stBadge} rounded-pill text-uppercase px-2">${r.status.replace('_', ' ')}</span></td>
                            <td class="text-end">
                                <div class="d-flex align-items-center justify-content-end gap-1">
                                    ${rowActionBtns}
                                    <button type="button" class="btn btn-xs btn-outline-primary rounded-pill px-2 py-1" style="font-size: 0.74rem;" onclick="inspectRosterShift(${escapeJsonForAttr(r)})">
                                        Inspect
                                    </button>
                                </div>
                            </td>
                        </tr>
                    `;
                });
                tbody.innerHTML = html;
            } else {
                tbody.innerHTML = `<tr><td colspan="9" class="text-center py-4 text-muted">No attendance logs found for the selected date filters.</td></tr>`;
            }
        })
        .catch(err => console.error(err));
}

async function adminTriggerClockInOut(rosterId, type, officerName) {
    let handover = '';
    if (type === 'clock_out') {
        handover = await EstateDialog.prompt({
            title: 'Shift Handover & Clock Out',
            message: `Enter optional handover notes or shift report for ${officerName || 'this officer'}:`,
            inputType: 'textarea',
            placeholder: 'e.g. All clear at post, key transferred to incoming guard...',
            confirmText: 'Clock Out Officer'
        });
        if (handover === null) return;
    } else {
        const confirmed = await EstateDialog.confirm({
            title: 'Confirm Duty Clock In',
            message: `Confirm Clock In for ${officerName || 'this officer'} now?`,
            type: 'primary',
            confirmText: 'Clock In'
        });
        if (!confirmed) return;
    }

    const formData = new FormData();
    formData.append('action', 'clock_in_out');
    formData.append('roster_id', rosterId);
    formData.append('type', type);
    formData.append('handover_notes', handover || '');

    fetch('../api/roster_query.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                EstateDialog.toast({ type: 'success', message: res.message || 'Attendance record updated.' });
                loadLiveGuardsFeed();
                loadAttendanceRecords();
                loadCalendarEvents();
            } else {
                EstateDialog.alert({ title: 'Clock Action Error', message: res.error || 'Failed to update attendance.', type: 'danger' });
            }
        })
        .catch(err => {
            console.error(err);
            EstateDialog.toast({ type: 'error', message: 'Network error updating attendance.' });
        });
}

function handleAdminQuickClockSubmit(event) {
    event.preventDefault();
    const guardId = document.getElementById('quick_clock_guard_id').value;
    const typeRadio = document.querySelector('input[name="quick_clock_type"]:checked');
    const type = typeRadio ? typeRadio.value : 'clock_in';
    const notes = document.getElementById('quick_clock_notes').value;

    if (!guardId) {
        EstateDialog.toast({ type: 'warning', message: 'Please choose a security officer' });
        return;
    }

    const btn = document.getElementById('btn-admin-clock-submit');
    btn.disabled = true;
    btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin me-1"></i> Recording...`;

    const formData = new FormData();
    formData.append('action', 'clock_in_out');
    formData.append('roster_id', 0);
    formData.append('guard_user_id', guardId);
    formData.append('type', type);
    formData.append('handover_notes', notes);

    fetch('../api/roster_query.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-check me-1"></i> Record Attendance`;

            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('adminQuickClockModal')).hide();
                EstateDialog.toast({ type: 'success', message: res.message || 'Attendance recorded successfully!' });
                loadLiveGuardsFeed();
                loadAttendanceRecords();
                loadCalendarEvents();
            } else {
                EstateDialog.alert({ title: 'Attendance Error', message: res.error || 'Failed to record attendance.', type: 'danger' });
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-check me-1"></i> Record Attendance`;
            console.error(err);
            EstateDialog.toast({ type: 'error', message: 'Network error recording attendance.' });
        });
}

function exportAttendanceCSV() {
    const startDate = document.getElementById('att_start_date') ? document.getElementById('att_start_date').value : '';
    const endDate = document.getElementById('att_end_date') ? document.getElementById('att_end_date').value : '';
    const url = `../api/roster_query.php?action=attendance_records&start_date=${startDate}&end_date=${endDate}`;

    fetch(url)
        .then(r => r.json())
        .then(res => {
            if (!res.records || res.records.length === 0) {
                EstateDialog.toast({ type: 'warning', message: 'No records to export' });
                return;
            }
            let csv = "Duty Date,Officer Name,Role,Post,Shift,Clock In,Clock Out,Duration Worked,Status,Handover Notes\n";
            res.records.forEach(r => {
                csv += `"${r.duty_date}","${r.officer_name}","${r.staff_role_title || ''}","${r.post_name || ''}","${r.shift_name || ''}","${r.clock_in_time || ''}","${r.clock_out_time || ''}","${r.worked_formatted}","${r.status}","${(r.handover_notes || '').replace(/"/g, '""')}"\n`;
            });

            const blob = new Blob([csv], { type: 'text/csv' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `guard_attendance_${startDate}_to_${endDate}.csv`;
            link.click();
        });
}

function loadRosterHistoryLog() {
    loadAttendanceRecords();
}

// ==============================================================
// RECURRING SHIFT DISTRIBUTION MODAL HELPERS
// ==============================================================

function selectAllGuards() {
    const select = document.getElementById('modal_guard_user_ids');
    if (!select) return;
    for (let i = 0; i < select.options.length; i++) {
        select.options[i].selected = true;
    }
}

function handleRepeatModeChange() {
    const mode = document.getElementById('modal_repeat_mode').value;
    const customBox = document.getElementById('custom-weekdays-container');
    if (customBox) {
        customBox.style.display = (mode === 'custom') ? 'block' : 'none';
    }
    calculateRecurrenceDates();
}

function handleRepeatDurationChange() {
    calculateRecurrenceDates();
}

function calculateRecurrenceDates() {
    const startInput = document.getElementById('modal_start_date');
    const endInput = document.getElementById('modal_end_date');
    const mode = document.getElementById('modal_repeat_mode').value;
    const duration = document.getElementById('modal_repeat_duration').value;
    const previewText = document.getElementById('distribution-preview-text');

    if (!startInput || !endInput) return;

    if (mode === 'once') {
        endInput.value = startInput.value;
        endInput.disabled = true;
        if (previewText) previewText.innerText = `Single shift on ${startInput.value}`;
        return;
    }

    endInput.disabled = false;
    const sDate = new Date(startInput.value);

    if (duration === '1_week') {
        const eDate = new Date(sDate);
        eDate.setDate(sDate.getDate() + 6);
        endInput.value = eDate.toISOString().split('T')[0];
    } else if (duration === '2_weeks') {
        const eDate = new Date(sDate);
        eDate.setDate(sDate.getDate() + 13);
        endInput.value = eDate.toISOString().split('T')[0];
    } else if (duration === '1_month') {
        const eDate = new Date(sDate);
        eDate.setMonth(sDate.getMonth() + 1);
        eDate.setDate(eDate.getDate() - 1);
        endInput.value = eDate.toISOString().split('T')[0];
    } else if (duration === '3_months') {
        const eDate = new Date(sDate);
        eDate.setMonth(sDate.getMonth() + 3);
        eDate.setDate(eDate.getDate() - 1);
        endInput.value = eDate.toISOString().split('T')[0];
    }

    if (previewText) {
        previewText.innerText = `Will distribute recurring shifts from ${startInput.value} to ${endInput.value}`;
    }
}

function openScheduleModalWithDate(dateStr) {
    document.getElementById('modal_start_date').value = dateStr;
    calculateRecurrenceDates();
    const modal = new bootstrap.Modal(document.getElementById('scheduleShiftModal'));
    modal.show();
}

function handleScheduleSubmit(event) {
    event.preventDefault();
    const form = document.getElementById('scheduleShiftForm');
    const formData = new FormData(form);
    formData.append('action', 'schedule_shift');

    const submitBtn = document.getElementById('btn-save-shift');
    submitBtn.disabled = true;
    submitBtn.innerHTML = `<i class="fa-solid fa-spinner fa-spin me-1"></i> Distributing...`;

    fetch('../api/roster_query.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = `<i class="fa-solid fa-check me-1"></i> Save &amp; Distribute Roster`;

        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('scheduleShiftModal')).hide();
            EstateDialog.alert({
                title: 'Roster Scheduled & Distributed',
                message: res.message,
                type: 'success'
            });
            loadCalendarEvents();
            loadLiveGuardsFeed();
            loadAttendanceRecords();
        } else {
            EstateDialog.alert({
                title: 'Scheduling Error',
                message: res.error || 'Failed to save shift.',
                type: 'danger'
            });
        }
    })
    .catch(err => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = `<i class="fa-solid fa-check me-1"></i> Save &amp; Distribute Roster`;
        console.error(err);
        EstateDialog.toast({ type: 'error', message: 'Network or server error while scheduling.' });
    });
}

function inspectRosterShift(shiftObj) {
    selectedShiftObject = shiftObj;
    
    document.getElementById('shift-modal-header').style.background = shiftObj.color_code || '#2563eb';
    document.getElementById('detail-shift-pill').innerText = shiftObj.shift_name || 'Shift';
    document.getElementById('detail-officer-name').innerText = shiftObj.officer_name;
    document.getElementById('detail-card-officer').innerText = shiftObj.officer_name;
    document.getElementById('detail-card-badge').innerText = `Badge: ${shiftObj.badge_id || 'Active'} &bull; Role: ${shiftObj.staff_role_title || shiftObj.user_role || 'Staff'}`;
    document.getElementById('detail-card-phone').innerHTML = shiftObj.officer_phone ? `<a href="tel:${shiftObj.officer_phone}" class="text-primary"><i class="fa-solid fa-phone me-1"></i> ${shiftObj.officer_phone}</a>` : 'No phone';
    
    document.getElementById('detail-duty-date').innerText = shiftObj.formatted_duty_date || shiftObj.duty_date;
    document.getElementById('detail-post-name').innerText = shiftObj.post_name || 'General Post';
    document.getElementById('detail-shift-hours').innerText = `${shiftObj.formatted_start_time || shiftObj.start_time} to ${shiftObj.formatted_end_time || shiftObj.end_time}`;
    document.getElementById('detail-supervisor').innerText = shiftObj.supervisor_name || 'None Designated';

    const st = shiftObj.status || 'scheduled';
    const badge = document.getElementById('detail-status-badge');
    badge.innerText = st.replace('_', ' ').toUpperCase();
    badge.className = 'badge rounded-pill text-uppercase px-3 py-1';
    if (st === 'on_duty') badge.classList.add('bg-success');
    else if (st === 'completed') badge.classList.add('bg-info', 'text-dark');
    else if (st === 'absent') badge.classList.add('bg-danger');
    else badge.classList.add('bg-primary');

    document.getElementById('detail-clock-in').innerText = shiftObj.formatted_clock_in || shiftObj.clock_in_time || '—';
    document.getElementById('detail-clock-out').innerText = shiftObj.formatted_clock_out || shiftObj.clock_out_time || '—';

    const modal = new bootstrap.Modal(document.getElementById('shiftDetailModal'));
    modal.show();
}

function quickUpdateStatus(newStatus) {
    if (!selectedShiftObject) return;
    
    const formData = new FormData();
    formData.append('action', 'quick_update_status');
    formData.append('roster_id', selectedShiftObject.id);
    formData.append('status', newStatus);

    fetch('../api/roster_query.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            selectedShiftObject.status = newStatus;
            bootstrap.Modal.getInstance(document.getElementById('shiftDetailModal')).hide();
            EstateDialog.toast({ type: 'success', message: 'Shift status updated successfully.' });
            loadCalendarEvents();
            loadLiveGuardsFeed();
            loadAttendanceRecords();
        } else {
            EstateDialog.alert({ title: 'Update Error', message: res.error, type: 'danger' });
        }
    })
    .catch(err => console.error(err));
}

async function deleteRosterShift() {
    if (!selectedShiftObject) return;
    const confirmed = await EstateDialog.confirm({
        title: 'Delete Shift',
        message: `Are you sure you want to remove this duty shift for ${selectedShiftObject.officer_name}?`,
        type: 'danger',
        confirmText: 'Delete Shift'
    });
    if (!confirmed) return;

    const formData = new FormData();
    formData.append('action', 'delete_roster_shift');
    formData.append('roster_id', selectedShiftObject.id);

    fetch('../api/roster_query.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('shiftDetailModal')).hide();
            EstateDialog.toast({ type: 'success', message: 'Shift removed successfully.' });
            loadCalendarEvents();
            loadLiveGuardsFeed();
            loadAttendanceRecords();
        } else {
            EstateDialog.alert({ title: 'Delete Error', message: res.error, type: 'danger' });
        }
    })
    .catch(err => console.error(err));
}

async function sendDutyAlertsPrompt() {
    const today = new Date().toISOString().split('T')[0];
    const targetDate = await EstateDialog.prompt({
        title: 'Dispatch Duty Alerts & Reminders',
        message: 'Enter the duty date to dispatch automated roster notifications for (YYYY-MM-DD):',
        defaultValue: today,
        inputType: 'date',
        confirmText: 'Send Alerts'
    });
    if (!targetDate) return;

    const formData = new FormData();
    formData.append('action', 'send_duty_alert');
    formData.append('duty_date', targetDate);

    fetch('../api/roster_query.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            EstateDialog.alert({
                title: 'Duty Notifications',
                message: res.message || 'Alerts sent successfully.',
                type: 'success'
            });
        })
        .catch(err => {
            console.error(err);
            EstateDialog.toast({ type: 'error', message: 'Network error sending duty alerts.' });
        });
}

function openShiftTemplateModal(id) {
    document.getElementById('tpl_shift_id').value = 0;
    document.getElementById('tpl_name').value = '';
    document.getElementById('tpl_start_time').value = '08:00';
    document.getElementById('tpl_end_time').value = '16:00';
    document.getElementById('tpl_color_code').value = '#2563eb';
    document.getElementById('tpl_is_active').checked = true;
    document.getElementById('tpl_modal_title').innerText = 'Add New Shift Template';

    const modal = new bootstrap.Modal(document.getElementById('shiftTemplateModal'));
    modal.show();
}

function editShiftTemplate(s) {
    document.getElementById('tpl_shift_id').value = s.id;
    document.getElementById('tpl_name').value = s.name;
    document.getElementById('tpl_start_time').value = s.start_time;
    document.getElementById('tpl_end_time').value = s.end_time;
    document.getElementById('tpl_color_code').value = s.color_code || '#2563eb';
    document.getElementById('tpl_is_active').checked = (s.is_active == 1);
    document.getElementById('tpl_modal_title').innerText = `Edit Shift: ${s.name}`;

    const modal = new bootstrap.Modal(document.getElementById('shiftTemplateModal'));
    modal.show();
}

async function deleteShiftTemplate(shiftId) {
    const confirmed = await EstateDialog.confirm({
        title: 'Delete Shift Template',
        message: 'Are you sure you want to delete this shift template?',
        type: 'danger',
        confirmText: 'Delete'
    });
    if (!confirmed) return;

    const formData = new FormData();
    formData.append('action', 'manage_shift_template');
    formData.append('sub_action', 'delete');
    formData.append('shift_id', shiftId);

    fetch('../api/roster_query.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.success) location.reload();
            else EstateDialog.alert({ title: 'Error', message: res.error, type: 'danger' });
        });
}

function openPostModal(id) {
    document.getElementById('pst_post_id').value = 0;
    document.getElementById('pst_name').value = '';
    document.getElementById('pst_desc').value = '';
    document.getElementById('pst_ext').value = '';
    document.getElementById('pst_status').value = 'active';
    document.getElementById('pst_modal_title').innerText = 'Add Post / Station';

    const modal = new bootstrap.Modal(document.getElementById('postModal'));
    modal.show();
}

function editPost(p) {
    document.getElementById('pst_post_id').value = p.id;
    document.getElementById('pst_name').value = p.post_name;
    document.getElementById('pst_desc').value = p.location_description || '';
    document.getElementById('pst_ext').value = p.phone_extension || '';
    document.getElementById('pst_status').value = p.status || 'active';
    document.getElementById('pst_modal_title').innerText = `Edit Post: ${p.post_name}`;

    const modal = new bootstrap.Modal(document.getElementById('postModal'));
    modal.show();
}

async function deletePost(postId) {
    const confirmed = await EstateDialog.confirm({
        title: 'Delete Post/Station',
        message: 'Are you sure you want to remove this post/station?',
        type: 'danger',
        confirmText: 'Remove'
    });
    if (!confirmed) return;

    const formData = new FormData();
    formData.append('action', 'manage_post');
    formData.append('sub_action', 'delete');
    formData.append('post_id', postId);

    fetch('../api/roster_query.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.success) location.reload();
            else EstateDialog.alert({ title: 'Error', message: res.error, type: 'danger' });
        });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function escapeJsonForAttr(obj) {
    return JSON.stringify(obj).replace(/'/g, '&#39;').replace(/"/g, '&quot;');
}
</script>

<?php 
include '../includes/footer.php'; 
?>
