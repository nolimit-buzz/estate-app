<?php
// api/roster_query.php
// Complete Roster Scheduling API: Calendar Events, Shifts & Posts Configuration, Forensics, Live Feed & Attendance System

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/emergency_roster_init.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$estate_id = get_estate_id();
$user_id = intval($_SESSION['user_id']);
$user_role = $_SESSION['role'] ?? 'resident';
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

$isAdminOrManager = in_array($user_role, ['admin', 'superadmin', 'manager', 'zone_admin']);
$isStaffOrSecurity = in_array($user_role, ['security', 'staff', 'admin', 'superadmin', 'manager', 'zone_admin']);

// -------------------------------------------------------------
// 1. CALENDAR EVENTS: FETCH SHIFTS FOR FULL CALENDAR GRID (Including adjacent edge days)
// -------------------------------------------------------------
if ($action === 'calendar_events') {
    $year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
    $month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('n'));

    // Validate month and year
    if ($month < 1 || $month > 12) $month = intval(date('n'));
    if ($year < 2000 || $year > 2100) $year = intval(date('Y'));

    // Calculate full grid boundary (starts on Sunday of first week, ends on Saturday of 6th week)
    $month_first_ts = strtotime(sprintf('%04d-%02d-01', $year, $month));
    $lead_dow = intval(date('w', $month_first_ts)); // 0 = Sun
    $grid_start_date = date('Y-m-d', strtotime("-$lead_dow days", $month_first_ts));

    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $month_last_ts = strtotime(sprintf('%04d-%02d-%02d', $year, $month, $days_in_month));
    $trail_dow = intval(date('w', $month_last_ts));
    $trail_days = 6 - $trail_dow + 7; // add extra trailing week buffer for complete grid visibility
    $grid_end_date = date('Y-m-d', strtotime("+$trail_days days", $month_last_ts));

    $start_date = !empty($_GET['start_date']) ? $conn->real_escape_string($_GET['start_date']) : $grid_start_date;
    $end_date = !empty($_GET['end_date']) ? $conn->real_escape_string($_GET['end_date']) : $grid_end_date;

    // Filters
    $where = "sr.estate_id = $estate_id AND sr.duty_date BETWEEN '$start_date' AND '$end_date'";

    // If staff user viewing their own calendar (unless admin viewing)
    if (!$isAdminOrManager && isset($_GET['only_my_shifts']) && $_GET['only_my_shifts'] == 1) {
        $where .= " AND sr.user_id = $user_id";
    } elseif (!empty($_GET['user_id'])) {
        $filter_uid = intval($_GET['user_id']);
        $where .= " AND sr.user_id = $filter_uid";
    }

    if (!empty($_GET['post_id'])) {
        $filter_pid = intval($_GET['post_id']);
        $where .= " AND sr.post_id = $filter_pid";
    }

    if (!empty($_GET['shift_id'])) {
        $filter_sid = intval($_GET['shift_id']);
        $where .= " AND sr.shift_id = $filter_sid";
    }

    if (!empty($_GET['role_filter'])) {
        $role_filter = $conn->real_escape_string($_GET['role_filter']);
        if ($role_filter === 'security') {
            $where .= " AND (u.role = 'security' OR es.role LIKE '%Security%' OR es.role LIKE '%Guard%')";
        } elseif ($role_filter === 'maintenance') {
            $where .= " AND (es.role LIKE '%Maintenance%' OR es.role LIKE '%Technician%' OR es.role LIKE '%Plumber%' OR es.role LIKE '%Electrician%')";
        } elseif ($role_filter === 'facility') {
            $where .= " AND (es.role LIKE '%Manager%' OR es.role LIKE '%Admin%' OR es.role LIKE '%Facility%' OR es.role LIKE '%Cleaner%' OR es.role LIKE '%Janitor%')";
        }
    }

    $sql = "SELECT sr.*, 
                   u.name as officer_name, u.phone as officer_phone, u.email as officer_email, u.role as user_role,
                   es.custom_id as badge_id, es.role as staff_role_title, es.image_path as officer_photo,
                   sp.post_name, sp.phone_extension, sp.location_description,
                   ss.name as shift_name, ss.start_time, ss.end_time, ss.color_code,
                   sup.name as supervisor_name, sup.phone as supervisor_phone
            FROM security_roster sr
            JOIN users u ON sr.user_id = u.id
            LEFT JOIN estate_staff es ON (sr.staff_id = es.id OR es.user_id = u.id)
            LEFT JOIN security_posts sp ON sr.post_id = sp.id
            LEFT JOIN security_shifts ss ON sr.shift_id = ss.id
            LEFT JOIN users sup ON sr.supervisor_id = sup.id
            WHERE $where
            ORDER BY sr.duty_date ASC, ss.start_time ASC, sp.post_name ASC";

    $res = $conn->query($sql);
    $events_by_date = [];
    $all_events = [];

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $d = $row['duty_date'];
            if (!isset($events_by_date[$d])) {
                $events_by_date[$d] = [];
            }
            $row['formatted_start_time'] = date('h:i A', strtotime($row['start_time']));
            $row['formatted_end_time'] = date('h:i A', strtotime($row['end_time']));
            $row['formatted_duty_date'] = date('D, M d, Y', strtotime($row['duty_date']));
            $row['formatted_clock_in'] = !empty($row['clock_in_time']) ? date('h:i A', strtotime($row['clock_in_time'])) : null;
            $row['formatted_clock_out'] = !empty($row['clock_out_time']) ? date('h:i A', strtotime($row['clock_out_time'])) : null;
            $events_by_date[$d][] = $row;
            $all_events[] = $row;
        }
    }

    echo json_encode([
        'success' => true,
        'year' => $year,
        'month' => $month,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'total_count' => count($all_events),
        'events_by_date' => $events_by_date,
        'events' => $all_events
    ]);
    exit;
}

// -------------------------------------------------------------
// 2. SAVE & DISTRIBUTE ROSTER SHIFTS (Single, Bulk Officers & Recurring Presets)
// -------------------------------------------------------------
if ($action === 'save_roster_shift' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdminOrManager) {
        echo json_encode(['success' => false, 'error' => 'Permission denied. Admin or Manager access required.']);
        exit;
    }

    // Support single officer or multiple officer IDs
    $officer_ids = [];
    if (!empty($_POST['guard_user_ids']) && is_array($_POST['guard_user_ids'])) {
        foreach ($_POST['guard_user_ids'] as $uid) {
            $uid = intval($uid);
            if ($uid > 0) $officer_ids[] = $uid;
        }
    } elseif (!empty($_POST['guard_user_id'])) {
        $uid = intval($_POST['guard_user_id']);
        if ($uid > 0) $officer_ids[] = $uid;
    }

    $post_id = intval($_POST['post_id'] ?? 0);
    $shift_id = intval($_POST['shift_id'] ?? 0);
    $start_date = trim($_POST['start_date'] ?? '');
    $end_date = trim($_POST['end_date'] ?? '');
    $supervisor_id = !empty($_POST['supervisor_id']) ? intval($_POST['supervisor_id']) : 'NULL';
    
    // Repeat preset mode: 'once', 'daily', 'weekdays', 'weekends', 'custom'
    $repeat_mode = trim($_POST['repeat_mode'] ?? 'custom');
    $repeat_duration = trim($_POST['repeat_duration'] ?? 'custom');
    $repeat_days = isset($_POST['repeat_days']) ? (array)$_POST['repeat_days'] : []; // Array of day indices 0-6 (Sun-Sat)

    if (empty($officer_ids) || !$post_id || !$shift_id || empty($start_date)) {
        echo json_encode(['success' => false, 'error' => 'Please select at least one Personnel Officer, Stationed Post, Shift Template, and Start Date.']);
        exit;
    }

    // Calculate end date based on repeat duration preset if specified
    $start_ts = strtotime($start_date);
    if (empty($end_date) || $repeat_mode === 'once') {
        $end_date = $start_date;
    } elseif ($repeat_duration === '1_week') {
        $end_date = date('Y-m-d', strtotime('+6 days', $start_ts));
    } elseif ($repeat_duration === '2_weeks') {
        $end_date = date('Y-m-d', strtotime('+13 days', $start_ts));
    } elseif ($repeat_duration === '1_month') {
        $end_date = date('Y-m-d', strtotime('+1 month -1 day', $start_ts));
    } elseif ($repeat_duration === '3_months') {
        $end_date = date('Y-m-d', strtotime('+3 months -1 day', $start_ts));
    }

    // Apply repeat preset days of week
    if ($repeat_mode === 'daily') {
        $repeat_days = ['0', '1', '2', '3', '4', '5', '6'];
    } elseif ($repeat_mode === 'weekdays') {
        $repeat_days = ['1', '2', '3', '4', '5']; // Mon-Fri
    } elseif ($repeat_mode === 'weekends') {
        $repeat_days = ['0', '6']; // Sun, Sat
    } elseif ($repeat_mode === 'once') {
        $repeat_days = [(string)date('w', $start_ts)];
        $end_date = $start_date;
    }

    // Fetch shift template info
    $sh_res = $conn->query("SELECT * FROM security_shifts WHERE id = $shift_id AND estate_id = $estate_id LIMIT 1");
    if (!$sh_res || $sh_res->num_rows == 0) {
        echo json_encode(['success' => false, 'error' => 'Selected shift template does not exist.']);
        exit;
    }
    $shift = $sh_res->fetch_assoc();
    $start_time = $shift['start_time'];
    $end_time = $shift['end_time'];

    $cur_ts_base = strtotime($start_date);
    $end_ts_base = strtotime($end_date);
    if ($cur_ts_base > $end_ts_base) {
        $end_ts_base = $cur_ts_base;
    }

    $total_added = 0;
    $total_skipped = 0;
    $officer_names = [];

    foreach ($officer_ids as $guard_user_id) {
        // Get staff_id if linked
        $st_res = $conn->query("SELECT id FROM estate_staff WHERE user_id = $guard_user_id AND estate_id = $estate_id LIMIT 1");
        $staff_id = ($st_res && $st_row = $st_res->fetch_assoc()) ? intval($st_row['id']) : 'NULL';

        $u_res = $conn->query("SELECT name FROM users WHERE id = $guard_user_id LIMIT 1")->fetch_assoc();
        if ($u_res) $officer_names[] = $u_res['name'];

        $cur_ts = $cur_ts_base;
        while ($cur_ts <= $end_ts_base) {
            $w_day = intval(date('w', $cur_ts)); // 0 (Sun) to 6 (Sat)
            
            // If specific repeat days chosen, check if current day of week matches
            if (empty($repeat_days) || in_array((string)$w_day, $repeat_days)) {
                $duty_date = date('Y-m-d', $cur_ts);
                $start_dt = "$duty_date $start_time";

                // If shift crosses midnight
                if ($end_time < $start_time) {
                    $next_day = date('Y-m-d', strtotime('+1 day', $cur_ts));
                    $end_dt = "$next_day $end_time";
                } else {
                    $end_dt = "$duty_date $end_time";
                }

                // Check if already scheduled for this shift slot
                $chk = $conn->query("SELECT id FROM security_roster 
                                     WHERE estate_id = $estate_id 
                                       AND user_id = $guard_user_id 
                                       AND duty_date = '$duty_date' 
                                       AND shift_id = $shift_id LIMIT 1");

                if (!$chk || $chk->num_rows == 0) {
                    $conn->query("INSERT INTO security_roster (estate_id, user_id, staff_id, post_id, shift_id, duty_date, start_datetime, end_datetime, supervisor_id, created_by)
                                 VALUES ($estate_id, $guard_user_id, $staff_id, $post_id, $shift_id, '$duty_date', '$start_dt', '$end_dt', $supervisor_id, $user_id)");
                    $total_added++;
                } else {
                    $total_skipped++;
                }
            }
            $cur_ts = strtotime('+1 day', $cur_ts);
        }
    }

    $names_str = implode(', ', array_slice($officer_names, 0, 3)) . (count($officer_names) > 3 ? ' +' . (count($officer_names) - 3) . ' more' : '');
    logAudit($conn, "Roster Schedule Distributed", "Security", "Distributed $total_added shift(s) across " . count($officer_ids) . " personnel ($names_str) from $start_date to $end_date.");

    echo json_encode([
        'success' => true,
        'message' => "Successfully scheduled and distributed $total_added duty shift(s) across " . count($officer_ids) . " officer(s) from " . date('M d, Y', $cur_ts_base) . " to " . date('M d, Y', $end_ts_base) . "!" . ($total_skipped > 0 ? " ($total_skipped duplicate slots skipped)" : ""),
        'added_count' => $total_added,
        'skipped_count' => $total_skipped,
        'start_date' => $start_date,
        'end_date' => $end_date
    ]);
    exit;
}

// -------------------------------------------------------------
// 3. LIVE GUARDS ON DUTY FEED (Real-time live status of guards on shift)
// -------------------------------------------------------------
if ($action === 'live_guards_feed') {
    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');
    $cur_time = date('H:i:s');

    $sql = "SELECT sr.*, 
                   u.name as officer_name, u.phone as officer_phone, u.email as officer_email, u.role as user_role,
                   es.custom_id as badge_id, es.role as staff_role_title, es.image_path as officer_photo,
                   sp.post_name, sp.phone_extension, sp.location_description,
                   ss.name as shift_name, ss.start_time as shift_start_time, ss.end_time as shift_end_time, ss.color_code,
                   sup.name as supervisor_name, sup.phone as supervisor_phone
            FROM security_roster sr
            JOIN users u ON sr.user_id = u.id
            LEFT JOIN estate_staff es ON (sr.staff_id = es.id OR es.user_id = u.id)
            LEFT JOIN security_posts sp ON sr.post_id = sp.id
            LEFT JOIN security_shifts ss ON sr.shift_id = ss.id
            LEFT JOIN users sup ON sr.supervisor_id = sup.id
            WHERE sr.estate_id = $estate_id
              AND (
                sr.status = 'on_duty'
                OR (
                    sr.duty_date = '$today' 
                    AND sr.status IN ('scheduled', 'on_duty') 
                    AND (
                        (sr.start_datetime <= '$now' AND sr.end_datetime >= '$now')
                        OR (sr.duty_date = '$today' AND ss.start_time <= '$cur_time' AND ss.end_time >= '$cur_time')
                    )
                )
              )
            ORDER BY (CASE WHEN sr.status = 'on_duty' THEN 0 ELSE 1 END), sp.post_name ASC, u.name ASC";

    $res = $conn->query($sql);
    $guards = [];
    $on_duty_count = 0;
    $scheduled_count = 0;

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $is_clocked_in = !empty($row['clock_in_time']);
            $elapsed_minutes = 0;
            $elapsed_formatted = "Not Clocked In";

            if ($is_clocked_in) {
                $clock_in_ts = strtotime($row['clock_in_time']);
                $diff_sec = max(0, time() - $clock_in_ts);
                $hrs = floor($diff_sec / 3600);
                $mins = floor(($diff_sec % 3600) / 60);
                $elapsed_minutes = floor($diff_sec / 60);
                $elapsed_formatted = "{$hrs}h {$mins}m on duty";
                $on_duty_count++;
            } else {
                $scheduled_count++;
            }

            $row['is_clocked_in'] = $is_clocked_in;
            $row['elapsed_minutes'] = $elapsed_minutes;
            $row['elapsed_formatted'] = $elapsed_formatted;
            $row['formatted_start_time'] = date('h:i A', strtotime($row['shift_start_time'] ?? '00:00:00'));
            $row['formatted_end_time'] = date('h:i A', strtotime($row['shift_end_time'] ?? '00:00:00'));
            $row['formatted_clock_in'] = $is_clocked_in ? date('h:i A', strtotime($row['clock_in_time'])) : null;

            $guards[] = $row;
        }
    }

    echo json_encode([
        'success' => true,
        'timestamp' => $now,
        'formatted_now' => date('l, M d, Y - h:i A'),
        'total_active' => count($guards),
        'on_duty_count' => $on_duty_count,
        'scheduled_count' => $scheduled_count,
        'guards' => $guards
    ]);
    exit;
}

// -------------------------------------------------------------
// 4. ATTENDANCE SYSTEM RECORDS & LOGS (Exact Clock-In/Out & Work Duration)
// -------------------------------------------------------------
if ($action === 'attendance_records') {
    if (!$isStaffOrSecurity) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }

    $start_date = !empty($_GET['start_date']) ? $conn->real_escape_string($_GET['start_date']) : date('Y-m-01');
    $end_date = !empty($_GET['end_date']) ? $conn->real_escape_string($_GET['end_date']) : date('Y-m-t');
    
    $where = "sr.estate_id = $estate_id AND sr.duty_date BETWEEN '$start_date' AND '$end_date'";

    if (!$isAdminOrManager) {
        $where .= " AND sr.user_id = $user_id";
    } elseif (!empty($_GET['user_id'])) {
        $where .= " AND sr.user_id = " . intval($_GET['user_id']);
    }

    if (!empty($_GET['post_id'])) {
        $where .= " AND sr.post_id = " . intval($_GET['post_id']);
    }

    if (!empty($_GET['status_filter'])) {
        $st = $conn->real_escape_string($_GET['status_filter']);
        $where .= " AND sr.status = '$st'";
    }

    $sql = "SELECT sr.*, 
                   u.name as officer_name, u.phone as officer_phone, u.email as officer_email,
                   es.custom_id as badge_id, es.role as staff_role_title,
                   sp.post_name, sp.phone_extension,
                   ss.name as shift_name, ss.start_time as shift_start_time, ss.end_time as shift_end_time, ss.color_code,
                   sup.name as supervisor_name,
                   TIMESTAMPDIFF(MINUTE, sr.clock_in_time, IFNULL(sr.clock_out_time, NOW())) as duration_minutes
            FROM security_roster sr
            JOIN users u ON sr.user_id = u.id
            LEFT JOIN estate_staff es ON (sr.staff_id = es.id OR es.user_id = u.id)
            LEFT JOIN security_posts sp ON sr.post_id = sp.id
            LEFT JOIN security_shifts ss ON sr.shift_id = ss.id
            LEFT JOIN users sup ON sr.supervisor_id = sup.id
            WHERE $where
            ORDER BY sr.duty_date DESC, sr.clock_in_time DESC, sr.start_datetime DESC";

    $res = $conn->query($sql);
    $records = [];
    $total_hours = 0;
    $count_present = 0;
    $count_completed = 0;
    $count_absent = 0;

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $mins = intval($row['duration_minutes'] ?? 0);
            if (!empty($row['clock_in_time'])) {
                $total_hours += ($mins / 60);
                $count_present++;
            }
            if ($row['status'] === 'completed') $count_completed++;
            if ($row['status'] === 'absent') $count_absent++;

            $hrs = floor($mins / 60);
            $rem_mins = $mins % 60;
            $row['worked_formatted'] = !empty($row['clock_in_time']) ? "{$hrs}h {$rem_mins}m" : "0h 0m";
            $row['formatted_duty_date'] = date('M d, Y', strtotime($row['duty_date']));
            $row['formatted_clock_in'] = !empty($row['clock_in_time']) ? date('h:i A (M d)', strtotime($row['clock_in_time'])) : '—';
            $row['formatted_clock_out'] = !empty($row['clock_out_time']) ? date('h:i A (M d)', strtotime($row['clock_out_time'])) : '—';
            $row['formatted_shift'] = $row['shift_name'] . ' (' . date('h:i A', strtotime($row['shift_start_time'])) . ' - ' . date('h:i A', strtotime($row['shift_end_time'])) . ')';

            $records[] = $row;
        }
    }

    echo json_encode([
        'success' => true,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'total_records' => count($records),
        'total_hours_worked' => round($total_hours, 1),
        'count_present' => $count_present,
        'count_completed' => $count_completed,
        'count_absent' => $count_absent,
        'records' => $records
    ]);
    exit;
}

// -------------------------------------------------------------
// 5. QUICK UPDATE DUTY STATUS (Scheduled, On Duty, Completed, Absent, etc.)
// -------------------------------------------------------------
if ($action === 'quick_update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdminOrManager && !in_array($user_role, ['security', 'staff'])) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }

    $roster_id = intval($_POST['roster_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $valid_statuses = ['scheduled', 'on_duty', 'completed', 'absent', 'swapped', 'excused'];

    if (!$roster_id || !in_array($status, $valid_statuses)) {
        echo json_encode(['success' => false, 'error' => 'Invalid status update parameters']);
        exit;
    }

    $clock_sql = "";
    if ($status === 'on_duty') {
        $clock_sql = ", clock_in_time = IFNULL(clock_in_time, NOW())";
    } elseif ($status === 'completed') {
        $clock_sql = ", clock_out_time = IFNULL(clock_out_time, NOW())";
    }

    $upd = $conn->query("UPDATE security_roster SET status = '$status' $clock_sql WHERE id = $roster_id AND estate_id = $estate_id");
    if ($upd) {
        logAudit($conn, "Roster Status Update", "Security", "Roster #$roster_id status updated to $status.");
        echo json_encode([
            'success' => true,
            'message' => "Duty status updated to " . strtoupper(str_replace('_', ' ', $status)),
            'status' => $status
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update roster status: ' . $conn->error]);
    }
    exit;
}

// -------------------------------------------------------------
// 6. CLOCK IN / CLOCK OUT (Guard & Admin Attendance Engine)
// -------------------------------------------------------------
if ($action === 'clock_in_out' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $roster_id = intval($_POST['roster_id'] ?? 0);
    $type = trim($_POST['type'] ?? 'clock_in');
    $handover_notes = $conn->real_escape_string(trim($_POST['handover_notes'] ?? ''));

    // If no specific roster_id given, check if current user has an active shift scheduled for today
    if ($roster_id === 0) {
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        $shift_chk = $conn->query("SELECT id FROM security_roster 
                                   WHERE estate_id = $estate_id 
                                     AND user_id = $user_id 
                                     AND (duty_date = '$today' OR (start_datetime <= '$now' AND end_datetime >= '$now'))
                                     AND status IN ('scheduled', 'on_duty')
                                   ORDER BY start_datetime ASC LIMIT 1");
        if ($shift_chk && $s_row = $shift_chk->fetch_assoc()) {
            $roster_id = intval($s_row['id']);
        }
    }

    $check = $conn->query("SELECT sr.*, u.name as officer_name, sp.post_name, ss.name as shift_name
                           FROM security_roster sr
                           JOIN users u ON sr.user_id = u.id
                           LEFT JOIN security_posts sp ON sr.post_id = sp.id
                           LEFT JOIN security_shifts ss ON sr.shift_id = ss.id
                           WHERE sr.id = $roster_id AND sr.estate_id = $estate_id LIMIT 1");

    if (!$check || $check->num_rows == 0) {
        echo json_encode(['success' => false, 'error' => 'Duty roster shift record not found. Please ensure you have an assigned shift for today.']);
        exit;
    }
    $entry = $check->fetch_assoc();

    if (!$isAdminOrManager && $entry['user_id'] != $user_id) {
        echo json_encode(['success' => false, 'error' => 'You can only record attendance for your own assigned shifts.']);
        exit;
    }

    if ($type === 'clock_in') {
        $sql = "UPDATE security_roster SET 
                status = 'on_duty', 
                clock_in_time = IFNULL(clock_in_time, NOW()) 
                WHERE id = $roster_id";
        $conn->query($sql);
        
        $clock_time_formatted = date('h:i A');
        logAudit($conn, "Security Clock-In", "Security", "Officer {$entry['officer_name']} clocked in at {$entry['post_name']} ({$entry['shift_name']}).");
        
        echo json_encode([
            'success' => true,
            'message' => "Clock-in verified at $clock_time_formatted! You are now ON DUTY at {$entry['post_name']}.",
            'status' => 'on_duty',
            'clock_in_time' => date('Y-m-d H:i:s'),
            'clock_in_formatted' => $clock_time_formatted,
            'post_name' => $entry['post_name'],
            'shift_name' => $entry['shift_name']
        ]);
    } else {
        $sql = "UPDATE security_roster SET 
                status = 'completed', 
                clock_out_time = NOW(),
                handover_notes = IF('$handover_notes' != '', '$handover_notes', handover_notes)
                WHERE id = $roster_id";
        $conn->query($sql);

        $clock_out_formatted = date('h:i A');
        logAudit($conn, "Security Clock-Out", "Security", "Officer {$entry['officer_name']} clocked out of {$entry['post_name']} ({$entry['shift_name']}).");

        echo json_encode([
            'success' => true,
            'message' => "Clock-out confirmed at $clock_out_formatted. Duty completed successfully!",
            'status' => 'completed',
            'clock_out_time' => date('Y-m-d H:i:s'),
            'clock_out_formatted' => $clock_out_formatted
        ]);
    }
    exit;
}

// -------------------------------------------------------------
// 7. DELETE ROSTER SHIFT ASSIGNMENT
// -------------------------------------------------------------
if ($action === 'delete_roster_shift' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdminOrManager) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }

    $roster_id = intval($_POST['roster_id'] ?? 0);
    if (!$roster_id) {
        echo json_encode(['success' => false, 'error' => 'Invalid roster ID']);
        exit;
    }

    $del = $conn->query("DELETE FROM security_roster WHERE id = $roster_id AND estate_id = $estate_id");
    if ($del) {
        logAudit($conn, "Roster Shift Removed", "Security", "Deleted duty roster entry #$roster_id.");
        echo json_encode(['success' => true, 'message' => 'Shift assignment removed successfully.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database deletion error: ' . $conn->error]);
    }
    exit;
}

// -------------------------------------------------------------
// 8. CONFIGURE SHIFT TEMPLATES (Add, Edit, Delete, Toggle)
// -------------------------------------------------------------
if ($action === 'manage_shift_template' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdminOrManager) {
        echo json_encode(['success' => false, 'error' => 'Admin permission required']);
        exit;
    }

    $sub_action = trim($_POST['sub_action'] ?? 'save');
    $shift_id = intval($_POST['shift_id'] ?? 0);

    if ($sub_action === 'delete') {
        if (!$shift_id) {
            echo json_encode(['success' => false, 'error' => 'Invalid shift ID']);
            exit;
        }
        // Check if any roster references this shift
        $ref_chk = $conn->query("SELECT id FROM security_roster WHERE shift_id = $shift_id AND estate_id = $estate_id LIMIT 1");
        if ($ref_chk && $ref_chk->num_rows > 0) {
            // Soft deactivate instead of hard delete to keep historical integrity
            $conn->query("UPDATE security_shifts SET is_active = 0 WHERE id = $shift_id AND estate_id = $estate_id");
            echo json_encode(['success' => true, 'message' => 'Shift has historical assignments, so it was set to Inactive.']);
            exit;
        }

        $conn->query("DELETE FROM security_shifts WHERE id = $shift_id AND estate_id = $estate_id");
        logAudit($conn, "Shift Template Deleted", "Security Settings", "Deleted shift template #$shift_id");
        echo json_encode(['success' => true, 'message' => 'Shift template deleted successfully.']);
        exit;
    }

    if ($sub_action === 'toggle_active') {
        $conn->query("UPDATE security_shifts SET is_active = IF(is_active = 1, 0, 1) WHERE id = $shift_id AND estate_id = $estate_id");
        echo json_encode(['success' => true, 'message' => 'Shift active status toggled.']);
        exit;
    }

    // Save (Create or Update)
    $name = $conn->real_escape_string(trim($_POST['name'] ?? ''));
    $start_time = trim($_POST['start_time'] ?? '');
    $end_time = trim($_POST['end_time'] ?? '');
    $color_code = $conn->real_escape_string(trim($_POST['color_code'] ?? '#2563eb'));
    $is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;

    if (empty($name) || empty($start_time) || empty($end_time)) {
        echo json_encode(['success' => false, 'error' => 'Please provide shift name, start time, and end time.']);
        exit;
    }

    if ($shift_id > 0) {
        $sql = "UPDATE security_shifts SET name = '$name', start_time = '$start_time', end_time = '$end_time', color_code = '$color_code', is_active = $is_active WHERE id = $shift_id AND estate_id = $estate_id";
        $conn->query($sql);
        logAudit($conn, "Shift Template Updated", "Security Settings", "Updated shift template '$name'");
        echo json_encode(['success' => true, 'message' => "Shift template '$name' updated successfully!"]);
    } else {
        $sql = "INSERT INTO security_shifts (estate_id, name, start_time, end_time, color_code, is_active) VALUES ($estate_id, '$name', '$start_time', '$end_time', '$color_code', $is_active)";
        $conn->query($sql);
        $new_id = $conn->insert_id;
        logAudit($conn, "Shift Template Created", "Security Settings", "Created shift template '$name'");
        echo json_encode(['success' => true, 'message' => "Shift template '$name' created successfully!", 'shift_id' => $new_id]);
    }
    exit;
}

// -------------------------------------------------------------
// 9. CONFIGURE POSTS & STATIONS (Add, Edit, Delete, Toggle)
// -------------------------------------------------------------
if ($action === 'manage_post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdminOrManager) {
        echo json_encode(['success' => false, 'error' => 'Admin permission required']);
        exit;
    }

    $sub_action = trim($_POST['sub_action'] ?? 'save');
    $post_id = intval($_POST['post_id'] ?? 0);

    if ($sub_action === 'delete') {
        if (!$post_id) {
            echo json_encode(['success' => false, 'error' => 'Invalid post ID']);
            exit;
        }
        $ref_chk = $conn->query("SELECT id FROM security_roster WHERE post_id = $post_id AND estate_id = $estate_id LIMIT 1");
        if ($ref_chk && $ref_chk->num_rows > 0) {
            $conn->query("UPDATE security_posts SET status = 'inactive' WHERE id = $post_id AND estate_id = $estate_id");
            echo json_encode(['success' => true, 'message' => 'Post has past duty records, so it was deactivated to preserve audit integrity.']);
            exit;
        }

        $conn->query("DELETE FROM security_posts WHERE id = $post_id AND estate_id = $estate_id");
        logAudit($conn, "Security Post Deleted", "Security Settings", "Deleted post #$post_id");
        echo json_encode(['success' => true, 'message' => 'Post/Station removed successfully.']);
        exit;
    }

    if ($sub_action === 'toggle_active') {
        $conn->query("UPDATE security_posts SET status = IF(status = 'active', 'inactive', 'active') WHERE id = $post_id AND estate_id = $estate_id");
        echo json_encode(['success' => true, 'message' => 'Post status toggled.']);
        exit;
    }

    // Save
    $post_name = $conn->real_escape_string(trim($_POST['post_name'] ?? ''));
    $location_desc = $conn->real_escape_string(trim($_POST['location_description'] ?? ''));
    $phone_ext = $conn->real_escape_string(trim($_POST['phone_extension'] ?? ''));
    $status = isset($_POST['status']) && in_array($_POST['status'], ['active', 'inactive']) ? $_POST['status'] : 'active';

    if (empty($post_name)) {
        echo json_encode(['success' => false, 'error' => 'Please provide a post / station name.']);
        exit;
    }

    if ($post_id > 0) {
        $sql = "UPDATE security_posts SET post_name = '$post_name', location_description = '$location_desc', phone_extension = '$phone_ext', status = '$status' WHERE id = $post_id AND estate_id = $estate_id";
        $conn->query($sql);
        logAudit($conn, "Security Post Updated", "Security Settings", "Updated post '$post_name'");
        echo json_encode(['success' => true, 'message' => "Post '$post_name' updated successfully!"]);
    } else {
        $sql = "INSERT INTO security_posts (estate_id, post_name, location_description, phone_extension, status) VALUES ($estate_id, '$post_name', '$location_desc', '$phone_ext', '$status')";
        $conn->query($sql);
        $new_id = $conn->insert_id;
        logAudit($conn, "Security Post Created", "Security Settings", "Created post '$post_name'");
        echo json_encode(['success' => true, 'message' => "Post '$post_name' created successfully!", 'post_id' => $new_id]);
    }
    exit;
}

// -------------------------------------------------------------
// 10. FORENSIC AUDIT: "WHO WAS ON DUTY WHEN IT HAPPENED?"
// -------------------------------------------------------------
if ($action === 'who_was_on_duty') {
    if (!$isStaffOrSecurity) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $raw_dt = trim($_GET['incident_datetime'] ?? ($_POST['incident_datetime'] ?? ''));
    if (empty($raw_dt)) {
        echo json_encode(['success' => false, 'error' => 'Please provide an incident date & time']);
        exit;
    }

    $timestamp = strtotime($raw_dt);
    if (!$timestamp) {
        echo json_encode(['success' => false, 'error' => 'Invalid datetime format. Example: 2026-09-15 02:45:00']);
        exit;
    }
    $target_dt = date('Y-m-d H:i:s', $timestamp);
    $target_date = date('Y-m-d', $timestamp);
    $target_time = date('H:i:s', $timestamp);

    $post_filter = !empty($_GET['post_id']) ? intval($_GET['post_id']) : 0;
    $post_sql = $post_filter > 0 ? " AND sr.post_id = $post_filter" : "";

    $sql = "SELECT sr.*, 
                   u.name as officer_name, u.phone as officer_phone, u.email as officer_email,
                   es.custom_id as staff_badge_id, es.image_path as officer_photo,
                   sp.post_name, sp.location_description, sp.phone_extension,
                   ss.name as shift_name, ss.start_time as shift_start_time, ss.end_time as shift_end_time, ss.color_code,
                   sup.name as supervisor_name, sup.phone as supervisor_phone
            FROM security_roster sr
            JOIN users u ON sr.user_id = u.id
            LEFT JOIN estate_staff es ON (sr.staff_id = es.id OR es.user_id = u.id)
            LEFT JOIN security_posts sp ON sr.post_id = sp.id
            LEFT JOIN security_shifts ss ON sr.shift_id = ss.id
            LEFT JOIN users sup ON sr.supervisor_id = sup.id
            WHERE sr.estate_id = $estate_id
              AND (
                (sr.start_datetime <= '$target_dt' AND sr.end_datetime >= '$target_dt')
                OR
                (sr.duty_date = '$target_date' AND (
                    (ss.start_time <= ss.end_time AND '$target_time' BETWEEN ss.start_time AND ss.end_time)
                    OR
                    (ss.start_time > ss.end_time AND ('$target_time' >= ss.start_time OR '$target_time' <= ss.end_time))
                ))
              )
              $post_sql
            ORDER BY sp.post_name ASC, u.name ASC";

    $res = $conn->query($sql);
    $officers = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $is_clocked_in = !empty($row['clock_in_time']);
            $was_active_at_time = false;
            
            if ($is_clocked_in) {
                $clock_in_ts = strtotime($row['clock_in_time']);
                $clock_out_ts = !empty($row['clock_out_time']) ? strtotime($row['clock_out_time']) : ($timestamp + 3600);
                if ($clock_in_ts <= $timestamp && $clock_out_ts >= $timestamp) {
                    $was_active_at_time = true;
                }
            }

            $row['verified_active_during_incident'] = $was_active_at_time;
            $row['formatted_duty_date'] = date('M d, Y', strtotime($row['duty_date']));
            $row['formatted_shift'] = $row['shift_name'] . ' (' . date('h:i A', strtotime($row['shift_start_time'])) . ' - ' . date('h:i A', strtotime($row['shift_end_time'])) . ')';
            $officers[] = $row;
        }
    }

    logAudit($conn, "Duty Incident Forensics", "Security", "Investigated who was on duty at $target_dt. Found " . count($officers) . " officer(s).");

    echo json_encode([
        'success' => true,
        'incident_datetime' => $target_dt,
        'formatted_datetime' => date('l, F j, Y - h:i A', $timestamp),
        'count' => count($officers),
        'officers' => $officers
    ]);
    exit;
}

// -------------------------------------------------------------
// 11. SEND DUTY SHIFT ALERTS TO GUARDS
// -------------------------------------------------------------
if ($action === 'send_duty_alert' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdminOrManager) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $duty_date = $conn->real_escape_string($_POST['duty_date'] ?? date('Y-m-d'));
    
    $query = "SELECT sr.id, sr.duty_date, sr.start_datetime, sr.end_datetime,
                     u.id as user_id, u.name, u.email, u.phone,
                     sp.post_name, ss.name as shift_name
              FROM security_roster sr
              JOIN users u ON sr.user_id = u.id
              LEFT JOIN security_posts sp ON sr.post_id = sp.id
              LEFT JOIN security_shifts ss ON sr.shift_id = ss.id
              WHERE sr.estate_id = $estate_id AND sr.duty_date = '$duty_date'";
    
    $res = $conn->query($query);
    $notified_count = 0;
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $target_uid = intval($r['user_id']);
            $post = $r['post_name'] ?? 'General Gate';
            $shift = $r['shift_name'] ?? 'Assigned Shift';
            $msg = "Reminder: You are scheduled for duty on " . date('D, M d, Y', strtotime($r['duty_date'])) . " for $shift at $post. Please report on time and clock in.";
            
            $msg_esc = $conn->real_escape_string($msg);
            $conn->query("INSERT INTO notifications (estate_id, user_id, title, message, type) 
                         VALUES ($estate_id, $target_uid, 'Duty Roster Alert: $shift', '$msg_esc', 'security_duty')");
            
            $conn->query("UPDATE security_roster SET alerted_at = NOW() WHERE id = " . intval($r['id']));
            $notified_count++;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "Duty alerts dispatched to $notified_count staff personnel for date $duty_date"
    ]);
    exit;
}

// -------------------------------------------------------------
// 12. GET MONTHLY STATS SUMMARY
// -------------------------------------------------------------
if ($action === 'get_roster_stats') {
    $month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('n'));
    $year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
    $start_date = sprintf('%04d-%02d-01', $year, $month);
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $end_date = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);

    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');

    // Total shifts this month
    $total_res = $conn->query("SELECT COUNT(*) as c, COUNT(DISTINCT user_id) as unique_officers, COUNT(DISTINCT post_id) as posts_covered 
                               FROM security_roster 
                               WHERE estate_id = $estate_id AND duty_date BETWEEN '$start_date' AND '$end_date'")->fetch_assoc();

    // Active on duty right now
    $active_now = $conn->query("SELECT COUNT(*) as c FROM security_roster WHERE estate_id = $estate_id AND start_datetime <= '$now' AND end_datetime >= '$now' AND status IN ('on_duty', 'scheduled')")->fetch_assoc();

    // Completed shifts this month
    $completed_res = $conn->query("SELECT COUNT(*) as c FROM security_roster WHERE estate_id = $estate_id AND duty_date BETWEEN '$start_date' AND '$end_date' AND status = 'completed'")->fetch_assoc();

    // Today's scheduled shifts
    $today_res = $conn->query("SELECT COUNT(*) as c FROM security_roster WHERE estate_id = $estate_id AND duty_date = '$today'")->fetch_assoc();

    echo json_encode([
        'success' => true,
        'total_shifts' => intval($total_res['c'] ?? 0),
        'unique_officers' => intval($total_res['unique_officers'] ?? 0),
        'posts_covered' => intval($total_res['posts_covered'] ?? 0),
        'active_on_duty_now' => intval($active_now['c'] ?? 0),
        'completed_shifts' => intval($completed_res['c'] ?? 0),
        'today_shifts' => intval($today_res['c'] ?? 0)
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid roster action']);
exit;
