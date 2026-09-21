<?php
// staff/roster.php
// Security & Staff Personnel Duty Calendar, Clock-In / Attendance & Team Duty Portal

require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/emergency_roster_init.php';

requireLogin();
if (!isStaffRole() && !isAdminRole()) {
    header("Location: ../index");
    exit;
}

$estate_id = get_estate_id();
$user_id = $_SESSION['user_id'];
$message = "";
$error = "";

// Handle Attendance Clock In / Out
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_clock'])) {
    $roster_id = intval($_POST['roster_id']);
    $clock_type = $_POST['clock_type']; // 'in' or 'out'
    $handover = $conn->real_escape_string(trim($_POST['handover_notes'] ?? ''));

    $chk = $conn->query("SELECT * FROM security_roster WHERE id = $roster_id AND estate_id = $estate_id AND user_id = $user_id LIMIT 1");
    if ($chk && $chk->num_rows > 0) {
        if ($clock_type === 'in') {
            $conn->query("UPDATE security_roster SET status = 'on_duty', clock_in_time = IFNULL(clock_in_time, NOW()) WHERE id = $roster_id");
            logAudit($conn, "Security Clock-In", "Security", "Officer clocked in for shift #$roster_id.");
            $message = "You have successfully clocked in! You are now ON DUTY.";
        } else {
            $conn->query("UPDATE security_roster SET status = 'completed', clock_out_time = NOW(), handover_notes = '$handover' WHERE id = $roster_id");
            logAudit($conn, "Security Clock-Out", "Security", "Officer clocked out of shift #$roster_id.");
            $message = "You have successfully clocked out. Shift completed!";
        }
    } else {
        $error = "Duty shift record not found.";
    }
}

$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

// Fetch Today's Shift for this guard
$today_shift = $conn->query("SELECT sr.*, sp.post_name, sp.location_description, sp.phone_extension,
                                    ss.name as shift_name, ss.start_time, ss.end_time, ss.color_code,
                                    sup.name as supervisor_name, sup.phone as supervisor_phone
                             FROM security_roster sr
                             LEFT JOIN security_posts sp ON sr.post_id = sp.id
                             LEFT JOIN security_shifts ss ON sr.shift_id = ss.id
                             LEFT JOIN users sup ON sr.supervisor_id = sup.id
                             WHERE sr.estate_id = $estate_id 
                               AND sr.user_id = $user_id 
                               AND (sr.duty_date = '$today' OR (sr.start_datetime <= '$now' AND sr.end_datetime >= '$now'))
                             ORDER BY (CASE WHEN sr.status = 'on_duty' THEN 0 ELSE 1 END), sr.start_datetime ASC LIMIT 1")->fetch_assoc();

// Fetch Upcoming Scheduled Duties (Future dates)
$upcoming_shifts = $conn->query("SELECT sr.*, sp.post_name, ss.name as shift_name, ss.start_time, ss.end_time, ss.color_code,
                                        sup.name as supervisor_name
                                 FROM security_roster sr
                                 LEFT JOIN security_posts sp ON sr.post_id = sp.id
                                 LEFT JOIN security_shifts ss ON sr.shift_id = ss.id
                                 LEFT JOIN users sup ON sr.supervisor_id = sup.id
                                 WHERE sr.estate_id = $estate_id 
                                   AND sr.user_id = $user_id 
                                   AND sr.duty_date > '$today'
                                 ORDER BY sr.duty_date ASC LIMIT 20");

// Fetch Colleague Officers on Duty Right Now
$fellow_guards = $conn->query("SELECT sr.*, u.name as officer_name, u.phone as officer_phone, sp.post_name, ss.name as shift_name
                               FROM security_roster sr
                               JOIN users u ON sr.user_id = u.id
                               LEFT JOIN security_posts sp ON sr.post_id = sp.id
                               LEFT JOIN security_shifts ss ON sr.shift_id = ss.id
                               WHERE sr.estate_id = $estate_id 
                                 AND sr.user_id != $user_id
                                 AND (
                                    sr.status = 'on_duty' 
                                    OR (sr.start_datetime <= '$now' AND sr.end_datetime >= '$now' AND sr.status IN ('on_duty', 'scheduled'))
                                 )
                               ORDER BY (CASE WHEN sr.status = 'on_duty' THEN 0 ELSE 1 END), sp.post_name ASC");

include 'header.php';
include 'sidebar.php';
?>

<style>
/* Personal Staff Calendar Grid Styles */
.staff-cal-container {
    background: #ffffff;
    border-radius: 1.25rem;
    overflow: hidden;
    box-shadow: 0 4px 15px -2px rgba(0, 0, 0, 0.04);
}
.staff-cal-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    border-top: 1px solid #f1f5f9;
    border-left: 1px solid #f1f5f9;
}
.staff-cal-header {
    background: #f8fafc;
    padding: 10px 4px;
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    color: #64748b;
    text-align: center;
    border-right: 1px solid #f1f5f9;
    border-bottom: 1px solid #f1f5f9;
}
.staff-cal-cell {
    min-height: 95px;
    background: #ffffff;
    border-right: 1px solid #f1f5f9;
    border-bottom: 1px solid #f1f5f9;
    padding: 6px;
    position: relative;
    display: flex;
    flex-direction: column;
}
.staff-cal-cell.is-today {
    background: #f0fdfa;
    border: 2px solid #0d9488;
}
.staff-cal-cell.other-month {
    background: #fcfcfc;
    opacity: 0.45;
}
.staff-chip {
    font-size: 0.7rem;
    padding: 3px 5px;
    border-radius: 4px;
    color: white;
    font-weight: 600;
    margin-top: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.status-dot-pulse {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
    background: #10b981;
    box-shadow: 0 0 6px #10b981;
    animation: pulseDot 1.6s infinite;
}
@keyframes pulseDot {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { transform: scale(1.15); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}
</style>

<div class="d-flex flex-column gap-4">
    <!-- TODAY'S ACTIVE DUTY SHIFT CARD (Glassmorphic Deck) -->
    <div class="glass-panel-dark glass-panel-sheen p-4 p-md-5">
        <?php if (!empty($today_shift)): ?>
            <?php
            $is_on_duty = ($today_shift['status'] === 'on_duty');
            $is_completed = ($today_shift['status'] === 'completed');
            $is_scheduled = ($today_shift['status'] === 'scheduled');
            ?>
            <div class="row align-items-center g-4">
                <div class="col-lg-8">
                    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                        <?php if ($is_on_duty): ?>
                            <span class="glass-badge glass-badge-success text-uppercase">
                                <span class="status-dot-pulse me-1"></span> ON DUTY NOW
                            </span>
                        <?php elseif ($is_completed): ?>
                            <span class="glass-badge glass-badge-primary text-uppercase">
                                <i class="fa-solid fa-check-double me-1"></i> SHIFT COMPLETED
                            </span>
                        <?php else: ?>
                            <span class="glass-badge glass-badge-warning text-uppercase">
                                <i class="fa-solid fa-clock me-1"></i> SCHEDULED TODAY
                            </span>
                        <?php endif; ?>
                        <span class="text-teal small" style="color: #5eead4;">&bull; Date: <?php echo date('l, M d, Y', strtotime($today_shift['duty_date'])); ?></span>
                    </div>

                    <h2 class="display-6 fw-bold mb-1 text-white" style="font-family: 'Outfit', sans-serif;">
                        <?php echo htmlspecialchars($today_shift['post_name'] ?? 'Main Entrance Gate'); ?>
                    </h2>
                    <p class="text-slate-200 lead mb-3" style="font-size: 1.05rem;">
                        <strong><?php echo htmlspecialchars($today_shift['shift_name']); ?></strong> &bull; 
                        <?php echo date('h:i A', strtotime($today_shift['start_time'])); ?> to <?php echo date('h:i A', strtotime($today_shift['end_time'])); ?>
                        <?php if (!empty($today_shift['phone_extension'])): ?>
                            &bull; <span class="badge bg-black bg-opacity-25"><?php echo htmlspecialchars($today_shift['phone_extension']); ?></span>
                        <?php endif; ?>
                    </p>

                    <?php if ($today_shift['supervisor_name']): ?>
                        <div class="small text-slate-300 mb-3">
                            <i class="fa-solid fa-user-shield me-1"></i> Designated Supervisor: <strong><?php echo htmlspecialchars($today_shift['supervisor_name']); ?></strong>
                            <?php if (!empty($today_shift['supervisor_phone'])): ?>
                                <a href="tel:<?php echo htmlspecialchars($today_shift['supervisor_phone']); ?>" class="text-teal ms-2"><i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($today_shift['supervisor_phone']); ?></a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Live On-Duty Timer -->
                    <?php if ($is_on_duty && !empty($today_shift['clock_in_time'])): ?>
                        <div class="d-flex align-items-center gap-2 mb-3 p-2 px-3 rounded-3 bg-white bg-opacity-10 border border-white border-opacity-15" style="max-width: fit-content;">
                            <i class="fa-solid fa-stopwatch text-teal fs-5" style="color: #5eead4;"></i>
                            <span class="small text-slate-200">
                                Clocked in at <strong><?php echo date('h:i A', strtotime($today_shift['clock_in_time'])); ?></strong> &bull; Elapsed: <strong id="duty-timer-display" class="text-white">00h 00m 00s</strong>
                            </span>
                        </div>
                    <?php endif; ?>

                    <!-- Clock In / Out Action Buttons -->
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <?php if ($is_scheduled): ?>
                            <form method="POST" class="m-0">
                                <input type="hidden" name="action_clock" value="1">
                                <input type="hidden" name="roster_id" value="<?php echo $today_shift['id']; ?>">
                                <input type="hidden" name="clock_type" value="in">
                                <button type="submit" class="btn btn-success btn-lg rounded-pill px-4 fw-bold shadow">
                                    <i class="fa-solid fa-arrow-right-to-bracket me-2"></i> Clock In For Duty
                                </button>
                            </form>
                        <?php elseif ($is_on_duty): ?>
                            <button type="button" class="btn btn-danger btn-lg rounded-pill px-4 fw-bold shadow" data-bs-toggle="modal" data-bs-target="#clockOutModal">
                                <i class="fa-solid fa-arrow-right-from-bracket me-2"></i> Clock Out &amp; Handover
                            </button>
                        <?php elseif ($is_completed): ?>
                            <div class="p-3 rounded-4 bg-white bg-opacity-10 border border-white border-opacity-15">
                                <i class="fa-solid fa-shield-check text-success fs-5 me-2"></i>
                                <span>Duty concluded at <?php echo date('h:i A', strtotime($today_shift['clock_out_time'])); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-4 text-center d-none d-lg-block">
                    <div style="width: 140px; height: 140px; border-radius: 50%; background: rgba(255,255,255,0.08); display: inline-flex; align-items: center; justify-content: center; font-size: 3.5rem; border: 3px solid rgba(255,255,255,0.15);">
                        <i class="fa-solid fa-fingerprint text-teal" style="color: #5eead4;"></i>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="text-center py-4">
                <i class="fa-solid fa-mug-hot text-teal fs-1 mb-3 d-block" style="color: #5eead4;"></i>
                <h4 class="fw-bold text-white">No Scheduled Shift Today</h4>
                <p class="text-slate-300 small mb-0">You are not currently rostered for a shift today. Check your upcoming schedule below.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-4" role="alert" style="background: #dcfce7; color: #166534;">
            <i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-4" role="alert" style="background: #fee2e2; color: #991b1b;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- PERSONAL MONTHLY CALENDAR VIEW -->
    <div class="glass-panel p-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3 pb-3 border-bottom border-light border-opacity-50">
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-light rounded-circle shadow-sm" onclick="staffChangeMonth(-1)" style="width: 34px; height: 34px;">
                    <i class="fa-solid fa-chevron-left"></i>
                </button>
                <h5 class="fw-bold mb-0 text-dark" id="staff-cal-title" style="font-family: 'Outfit', sans-serif;">
                    My Duty Schedule
                </h5>
                <button type="button" class="btn btn-sm btn-light rounded-circle shadow-sm" onclick="staffChangeMonth(1)" style="width: 34px; height: 34px;">
                    <i class="fa-solid fa-chevron-right"></i>
                </button>
            </div>
            <span class="glass-badge glass-badge-primary">
                <i class="fa-solid fa-calendar-check me-1"></i> Personal Future Shifts Calendar
            </span>
        </div>

        <div class="staff-cal-container">
            <div class="staff-cal-grid" style="border-bottom: none;">
                <div class="staff-cal-header text-danger">Sun</div>
                <div class="staff-cal-header">Mon</div>
                <div class="staff-cal-header">Tue</div>
                <div class="staff-cal-header">Wed</div>
                <div class="staff-cal-header">Thu</div>
                <div class="staff-cal-header">Fri</div>
                <div class="staff-cal-header text-primary">Sat</div>
            </div>
            <div class="staff-cal-grid" id="staffCalendarGrid">
                <!-- Dynamically rendered -->
            </div>
        </div>
    </div>

    <!-- FELLOW GUARDS ON DUTY RIGHT NOW -->
    <div class="glass-panel p-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="fw-bold mb-0 text-dark" style="font-family: 'Outfit', sans-serif;">
                <i class="fa-solid fa-users-viewfinder text-primary me-2"></i> Live Feed &bull; Fellow Guards On Duty Right Now
            </h5>
            <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-1">
                <span class="status-dot-pulse me-1"></span> Live
            </span>
        </div>
        <div class="row g-3">
            <?php if ($fellow_guards && $fellow_guards->num_rows > 0): ?>
                <?php while ($fg = $fellow_guards->fetch_assoc()): ?>
                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="p-3 border rounded-3 glass-panel d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-3">
                                <div style="width: 40px; height: 40px; border-radius: 50%; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; font-weight: 700;">
                                    <i class="fa-solid fa-user-shield"></i>
                                </div>
                                <div>
                                    <strong class="d-block text-dark" style="font-size: 0.9rem;"><?php echo htmlspecialchars($fg['officer_name']); ?></strong>
                                    <span class="glass-badge glass-badge-primary mb-1" style="font-size: 0.68rem;">
                                        <?php echo htmlspecialchars($fg['post_name'] ?? 'Gate'); ?>
                                    </span>
                                </div>
                            </div>
                            <?php if (!empty($fg['officer_phone'])): ?>
                                <a href="tel:<?php echo htmlspecialchars($fg['officer_phone']); ?>" class="btn btn-sm btn-outline-success rounded-pill px-3" style="font-size: 0.75rem;">
                                    <i class="fa-solid fa-phone"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="col-12 text-muted small py-2">No other officers currently on recorded shifts at this hour.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- UPCOMING SCHEDULED FUTURE SHIFTS -->
    <div class="glass-panel p-4">
        <h5 class="fw-bold mb-3 text-dark" style="font-family: 'Outfit', sans-serif;">
            <i class="fa-solid fa-calendar-days text-primary me-2"></i> Upcoming Scheduled Duties (Future Calendar)
        </h5>
        <div class="table-responsive glass-table-container">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small text-secondary text-uppercase">
                    <tr>
                        <th>Date</th>
                        <th>Assigned Post / Gate</th>
                        <th>Shift Hours</th>
                        <th>Status</th>
                        <th>Supervisor</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($upcoming_shifts && $upcoming_shifts->num_rows > 0): ?>
                        <?php while ($us = $upcoming_shifts->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <strong class="text-dark"><?php echo date('l, M d, Y', strtotime($us['duty_date'])); ?></strong>
                                </td>
                                <td>
                                    <strong class="text-primary"><?php echo htmlspecialchars($us['post_name'] ?? 'General Gate'); ?></strong>
                                </td>
                                <td>
                                    <span class="badge rounded-pill text-white" style="background: <?php echo $us['color_code'] ?? '#2563eb'; ?>;">
                                        <?php echo htmlspecialchars($us['shift_name']); ?>
                                    </span>
                                    <span class="text-secondary small ms-2">
                                        <?php echo date('h:i A', strtotime($us['start_time'])); ?> - <?php echo date('h:i A', strtotime($us['end_time'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="glass-badge glass-badge-neutral text-uppercase" style="font-size: 0.7rem;">
                                        <?php echo htmlspecialchars($us['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="small text-secondary"><?php echo htmlspecialchars($us['supervisor_name'] ?? 'None Assigned'); ?></span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">No upcoming shifts scheduled. Management will assign your new roster shortly.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ==========================================
     MODAL: CLOCK OUT WITH HANDOVER NOTES
     ========================================== -->
<?php if (!empty($today_shift)): ?>
<div class="modal fade" id="clockOutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 1.25rem;">
            <input type="hidden" name="action_clock" value="1">
            <input type="hidden" name="roster_id" value="<?php echo $today_shift['id']; ?>">
            <input type="hidden" name="clock_type" value="out">
            <div class="modal-header px-4 py-3 border-0 bg-light">
                <h5 class="modal-title fw-bold text-dark">Clock Out &amp; Complete Shift</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="small text-secondary mb-3">You are completing duty at <strong><?php echo htmlspecialchars($today_shift['post_name']); ?></strong>.</p>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Shift Handover Notes / Incidents (Optional)</label>
                    <textarea name="handover_notes" rows="3" placeholder="Note any security observations, pending visitors, or handovers for the next shift officer..." class="form-control rounded-3"></textarea>
                </div>
            </div>
            <div class="modal-footer px-4 py-3 bg-light border-0">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold">Confirm Clock Out</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
let sCalYear = new Date().getFullYear();
let sCalMonth = new Date().getMonth() + 1;
let sEventsByDate = {};
let clockInTimestamp = <?php echo (!empty($today_shift['clock_in_time']) && $today_shift['status'] === 'on_duty') ? (strtotime($today_shift['clock_in_time']) * 1000) : 0; ?>;

document.addEventListener('DOMContentLoaded', () => {
    loadStaffCalendar();
    if (clockInTimestamp > 0) {
        startDutyStopwatch();
    }
});

function startDutyStopwatch() {
    function updateTimer() {
        const now = new Date().getTime();
        const diff = Math.max(0, now - clockInTimestamp);
        const hrs = Math.floor(diff / (1000 * 60 * 60));
        const mins = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        const secs = Math.floor((diff % (1000 * 60)) / 1000);

        const el = document.getElementById('duty-timer-display');
        if (el) {
            el.innerText = `${String(hrs).padStart(2, '0')}h ${String(mins).padStart(2, '0')}m ${String(secs).padStart(2, '0')}s`;
        }
    }
    updateTimer();
    setInterval(updateTimer, 1000);
}

function staffChangeMonth(delta) {
    sCalMonth += delta;
    if (sCalMonth < 1) { sCalMonth = 12; sCalYear -= 1; }
    else if (sCalMonth > 12) { sCalMonth = 1; sCalYear += 1; }
    loadStaffCalendar();
}

function loadStaffCalendar() {
    const url = `../api/roster_query.php?action=calendar_events&year=${sCalYear}&month=${sCalMonth}&only_my_shifts=1`;
    fetch(url)
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                sEventsByDate = res.events_by_date || {};
                renderStaffCalendarGrid();
                const mNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
                document.getElementById('staff-cal-title').innerText = `My Schedule: ${mNames[sCalMonth - 1]} ${sCalYear}`;
            }
        })
        .catch(err => console.error(err));
}

function renderStaffCalendarGrid() {
    const grid = document.getElementById('staffCalendarGrid');
    if (!grid) return;
    grid.innerHTML = '';

    const firstDay = new Date(sCalYear, sCalMonth - 1, 1).getDay();
    const totalDays = new Date(sCalYear, sCalMonth, 0).getDate();
    const prevDays = new Date(sCalYear, sCalMonth - 1, 0).getDate();
    const todayStr = new Date().toISOString().split('T')[0];

    // Leading days
    for (let i = firstDay - 1; i >= 0; i--) {
        const cell = document.createElement('div');
        cell.className = 'staff-cal-cell other-month';
        cell.innerHTML = `<span class="small text-muted font-bold">${prevDays - i}</span>`;
        grid.appendChild(cell);
    }

    // Month days
    for (let d = 1; d <= totalDays; d++) {
        const dateStr = `${sCalYear}-${String(sCalMonth).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const isToday = (dateStr === todayStr);

        const cell = document.createElement('div');
        cell.className = `staff-cal-cell ${isToday ? 'is-today' : ''}`;

        const events = sEventsByDate[dateStr] || [];
        let chipsHtml = '';

        events.forEach(e => {
            chipsHtml += `
                <div class="staff-chip" style="background: ${e.color_code || '#0d9488'};" title="${e.shift_name} @ ${e.post_name} (${e.formatted_start_time}-${e.formatted_end_time})">
                    ${e.shift_name} (${e.post_name || 'Gate'})
                </div>
            `;
        });

        cell.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <span class="small fw-bold ${isToday ? 'text-teal' : 'text-dark'}" style="${isToday ? 'color: #0d9488;' : ''}">${d}</span>
                ${events.length > 0 ? '<i class="fa-solid fa-shield-halved text-teal" style="font-size: 0.65rem; color: #0d9488;"></i>' : ''}
            </div>
            <div class="d-flex flex-column gap-1 mt-1">
                ${chipsHtml}
            </div>
        `;

        grid.appendChild(cell);
    }

    // Trailing days
    const totalRendered = firstDay + totalDays;
    const totalSlots = (totalRendered > 35) ? 42 : 35;
    for (let i = 1; i <= (totalSlots - totalRendered); i++) {
        const cell = document.createElement('div');
        cell.className = 'staff-cal-cell other-month';
        cell.innerHTML = `<span class="small text-muted font-bold">${i}</span>`;
        grid.appendChild(cell);
    }
}
</script>

<?php 
include 'footer.php'; 
?>
