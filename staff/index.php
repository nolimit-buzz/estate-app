<?php
// staff/index.php
include 'header.php';
include 'sidebar.php';

$estate_id = get_estate_id();
$user_id = $_SESSION['user_id'];
$user_role_display = !empty($_SESSION['staff_role_title']) ? $_SESSION['staff_role_title'] : ucfirst($_SESSION['role'] ?? 'Staff');

// Permission flags
$can_gate = hasPermission('visitors.view_log') || hasPermission('visitors.check_in_out');
$can_finance = hasPermission('finance.view_invoices') || hasPermission('finance.create_invoice') || hasPermission('finance.record_payment') || hasPermission('finance.view_receipts');
$can_residents = hasPermission('residents.view') || hasPermission('residents.manage');
$can_maintenance = hasPermission('maintenance.view_assigned') || hasPermission('maintenance.update_status');

// Count module grants for the duty profile
$perms_count = 0;
$role_id = $_SESSION['role_id'] ?? null;
if (!$role_id && isset($_SESSION['user_id'])) {
    $uid = intval($_SESSION['user_id']);
    $st_res = $conn->query("SELECT role_id FROM estate_staff WHERE user_id = $uid AND status = 'active' LIMIT 1");
    if ($st_res && $st_row = $st_res->fetch_assoc()) {
        $role_id = $st_row['role_id'];
        $_SESSION['role_id'] = $role_id;
    }
}
if ($role_id) {
    $pc_res = $conn->query("SELECT COUNT(permission_id) as cnt FROM role_permissions WHERE role_id = " . intval($role_id));
    if ($pc_res && $pc_row = $pc_res->fetch_assoc()) {
        $perms_count = intval($pc_row['cnt']);
    }
} else {
    $role_slug = $conn->real_escape_string($_SESSION['role'] ?? 'staff');
    $pc_res = $conn->query("SELECT COUNT(rp.permission_id) as cnt FROM role_permissions rp JOIN roles r ON rp.role_id = r.id WHERE (r.slug = '$role_slug' OR r.name = '$role_slug') AND r.estate_id = $estate_id");
    if ($pc_res && $pc_row = $pc_res->fetch_assoc()) {
        $perms_count = intval($pc_row['cnt']);
    }
}

// Gate Stats
$today_visitors = 0;
$pending_checkins = 0;
$recent_visitors = null;
if ($can_gate) {
    $r1 = $conn->query("SELECT COUNT(*) as cnt FROM visitors WHERE estate_id = $estate_id AND status = 'entered' AND DATE(entry_time) = CURDATE()");
    $today_visitors = $r1 ? $r1->fetch_assoc()['cnt'] : 0;
    
    $r2 = $conn->query("SELECT COUNT(*) as cnt FROM visitors WHERE estate_id = $estate_id AND status = 'pre_registered'");
    $pending_checkins = $r2 ? $r2->fetch_assoc()['cnt'] : 0;

    $recent_visitors = $conn->query("SELECT v.*, u.name as resident_name, f.number as flat_number 
        FROM visitors v 
        LEFT JOIN users u ON v.resident_id = u.id 
        LEFT JOIN flats f ON v.flat_id = f.id 
        WHERE v.estate_id = $estate_id 
        ORDER BY v.id DESC LIMIT 5");
}

// Finance Stats
$unpaid_invoices_cnt = 0;
$today_collected = 0;
$recent_invoices = null;
if ($can_finance) {
    $f1 = $conn->query("SELECT COUNT(*) as cnt FROM invoices WHERE estate_id = $estate_id AND status = 'unpaid'");
    $unpaid_invoices_cnt = $f1 ? $f1->fetch_assoc()['cnt'] : 0;
    
    $f2 = $conn->query("SELECT COALESCE(SUM(amount), 0) as tot FROM payments WHERE estate_id = $estate_id AND status = 'paid' AND DATE(paid_at) = CURDATE()");
    $today_collected = $f2 ? $f2->fetch_assoc()['tot'] : 0;

    $recent_invoices = $conn->query("SELECT i.*, u.name as resident_name, f.number as flat_number 
        FROM invoices i 
        JOIN users u ON i.user_id = u.id 
        LEFT JOIN flats f ON i.property_id = f.id 
        WHERE i.estate_id = $estate_id 
        ORDER BY i.id DESC LIMIT 5");
}

// Resident Stats
$total_residents = 0;
$vacant_flats = 0;
if ($can_residents) {
    $res_cnt = $conn->query("SELECT COUNT(*) as cnt FROM residents WHERE estate_id = $estate_id AND status = 'active'");
    $total_residents = $res_cnt ? $res_cnt->fetch_assoc()['cnt'] : 0;
    
    $flats_cnt = $conn->query("SELECT COUNT(*) as cnt FROM flats WHERE estate_id = $estate_id AND status = 'vacant'");
    $vacant_flats = $flats_cnt ? $flats_cnt->fetch_assoc()['cnt'] : 0;
}

// Maintenance Stats
$assigned_work_orders = 0;
if ($can_maintenance) {
    $m_cnt = $conn->query("SELECT COUNT(*) as cnt FROM maintenance_requests WHERE estate_id = $estate_id AND assigned_to = $user_id AND status IN ('open', 'in_progress')");
    $assigned_work_orders = $m_cnt ? $m_cnt->fetch_assoc()['cnt'] : 0;
}

// Fetch Current Duty Shift for this staff member
$today_str = date('Y-m-d');
$now_str = date('Y-m-d H:i:s');
$staff_duty = $conn->query("SELECT sr.*, sp.post_name, sp.phone_extension, ss.name as shift_name, ss.start_time, ss.end_time, ss.color_code
                           FROM security_roster sr
                           LEFT JOIN security_posts sp ON sr.post_id = sp.id
                           LEFT JOIN security_shifts ss ON sr.shift_id = ss.id
                           WHERE sr.estate_id = $estate_id 
                             AND sr.user_id = $user_id 
                             AND (sr.duty_date = '$today_str' OR (sr.start_datetime <= '$now_str' AND sr.end_datetime >= '$now_str'))
                           ORDER BY (CASE WHEN sr.status = 'on_duty' THEN 0 ELSE 1 END), sr.start_datetime ASC LIMIT 1")->fetch_assoc();
?>

<!-- ==========================================
     NATIVE MOBILE APP DASHBOARD (< 992px)
     Image 1 Screen 2 & Image 2 Operational Grid
     ========================================== -->
<div class="mobile-only mb-4">
    <!-- 1. 2x2 Metric Summary Cards -->
    <div class="mobile-section-header">
        <h3 class="mobile-section-title">Duty Overview</h3>
        <a href="roster" class="mobile-section-link">Roster &rarr;</a>
    </div>
    <div class="mobile-kpi-grid">
        <a href="security" class="mobile-kpi-card">
            <span class="mobile-kpi-label">Inside Visitors</span>
            <div class="mobile-kpi-val"><?php echo sprintf('%02d', $today_visitors); ?></div>
            <span class="mobile-kpi-sub">On-Premises</span>
        </a>

        <a href="security" class="mobile-kpi-card">
            <span class="mobile-kpi-label">Expected Passes</span>
            <div class="mobile-kpi-val"><?php echo sprintf('%02d', $pending_checkins); ?></div>
            <span class="mobile-kpi-sub">Pending Check-in</span>
        </a>

        <a href="maintenance" class="mobile-kpi-card">
            <span class="mobile-kpi-label">Work Orders</span>
            <div class="mobile-kpi-val"><?php echo sprintf('%02d', $assigned_work_orders); ?></div>
            <span class="mobile-kpi-sub">Assigned Tasks</span>
        </a>

        <a href="roster" class="mobile-kpi-card">
            <span class="mobile-kpi-label">Duty Shift</span>
            <div class="mobile-kpi-val text-primary" style="font-size: 1.35rem;"><?php echo !empty($staff_duty) ? ($staff_duty['status'] === 'on_duty' ? 'Active' : 'Scheduled') : 'Standby'; ?></div>
            <span class="mobile-kpi-sub"><?php echo htmlspecialchars($staff_duty['shift_name'] ?? 'General Post'); ?></span>
        </a>
    </div>

    <!-- 2. ALL STAFF SIDEBAR ICONS DOWN BELOW (Image 2 Style Grid) -->
    <?php 
    if (function_exists('renderMobileSidebarIconsGrid')) {
        renderMobileSidebarIconsGrid('staff');
    }
    ?>

    <!-- 3. Recent Gate Entries Activity Feed -->
    <?php if ($can_gate && $recent_visitors && $recent_visitors->num_rows > 0): ?>
        <div class="mobile-section-header mt-4">
            <h3 class="mobile-section-title">Recent Gate Activity</h3>
            <a href="security" class="mobile-section-link">Log <i class="fa-solid fa-chevron-right"></i></a>
        </div>
        <div class="mobile-activity-list">
            <?php while ($vr = $recent_visitors->fetch_assoc()): ?>
                <a href="security" class="mobile-activity-item">
                    <div class="mobile-activity-left">
                        <div class="mobile-activity-icon" style="background: rgba(37, 99, 235, 0.12); color: #2563eb;">
                            <i class="fa-solid fa-qrcode"></i>
                        </div>
                        <div class="mobile-activity-text">
                            <div class="mobile-activity-title"><?php echo htmlspecialchars($vr['name']); ?> (<?php echo htmlspecialchars($vr['entry_code'] ?? 'CODE'); ?>)</div>
                            <div class="mobile-activity-meta">Unit <?php echo htmlspecialchars($vr['flat_number'] ?? 'N/A'); ?> • <?php echo htmlspecialchars(ucfirst($vr['status'])); ?></div>
                        </div>
                    </div>
                    <i class="fa-solid fa-chevron-right mobile-activity-chevron"></i>
                </a>
            <?php endwhile; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ==========================================
     DESKTOP COMMAND CONSOLE
     ========================================== -->
<div class="desktop-only">
<div class="hero-header-enterprise">
    <div class="hero-title-group">
        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
            <h1>Staff Operations Console</h1>
            <span class="mature-badge mature-badge-emerald" style="background: rgba(13, 148, 136, 0.12); color: #0f766e; border-color: rgba(13, 148, 136, 0.25);">
                <i class="fa-solid fa-shield-cat me-1"></i> <?php echo htmlspecialchars($user_role_display); ?>
            </span>
        </div>
        <div class="hero-meta-strip">
            <span><i class="fa-regular fa-calendar me-1"></i> <?php echo date('l, F j, Y'); ?></span>
            <span>•</span>
            <span><i class="fa-solid fa-circle-user me-1"></i> Welcome back, <strong><?php echo htmlspecialchars($user_name); ?></strong></span>
            <span>•</span>
            <span class="text-success"><i class="fa-solid fa-circle me-1" style="font-size: 0.5rem;"></i> On Duty System Active</span>
        </div>
    </div>
    <div class="hero-actions-group">
        <div class="pill-timeframe-dropdown" title="Today's Shift Cycle">
            <i class="fa-regular fa-calendar-check text-teal" style="color: #0d9488;"></i>
            <span><?php echo date('D, M d'); ?></span>
            <i class="fa-solid fa-chevron-down" style="font-size: 0.65rem;"></i>
        </div>
        <a href="roster" class="btn-export-ghost" title="My Duty Roster & Clock-In Calendar">
            <i class="fa-solid fa-calendar-day text-teal" style="color: #0d9488;"></i> Duty Calendar
        </a>
        <?php if ($can_gate && hasPermission('visitors.check_in_out')): ?>
            <a href="security" class="btn-primary-action-pill" style="background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%); box-shadow: 0 4px 14px -2px rgba(13, 148, 136, 0.4);">
                <i class="fa-solid fa-qrcode"></i> Verify Visitor Code
            </a>
        <?php endif; ?>
        <?php if ($can_maintenance): ?>
            <a href="maintenance" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-2 fw-semibold">
                <i class="fa-solid fa-wrench me-1"></i> Work Orders
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- ==========================================
     ACTIVE DUTY SHIFT & ATTENDANCE ACTION DECK
     ========================================== -->
<?php if (!empty($staff_duty)): ?>
    <?php
    $duty_is_on = ($staff_duty['status'] === 'on_duty');
    $duty_is_done = ($staff_duty['status'] === 'completed');
    $duty_is_sched = ($staff_duty['status'] === 'scheduled');
    ?>
    <div class="card border-0 shadow-sm rounded-4 p-3 mb-4" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #ffffff;">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(255,255,255,0.1); border: 2px solid rgba(255,255,255,0.2); color: #5eead4; display: flex; align-items: center; justify-content: center; font-size: 1.35rem;">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <?php if ($duty_is_on): ?>
                            <span class="badge bg-success rounded-pill px-2 py-1 small"><i class="fa-solid fa-circle-check me-1"></i> ON DUTY NOW</span>
                        <?php elseif ($duty_is_done): ?>
                            <span class="badge bg-info text-dark rounded-pill px-2 py-1 small"><i class="fa-solid fa-flag-checkered me-1"></i> SHIFT COMPLETED</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark rounded-pill px-2 py-1 small"><i class="fa-solid fa-clock me-1"></i> SCHEDULED TODAY</span>
                        <?php endif; ?>
                        <strong class="text-white fs-6"><?php echo htmlspecialchars($staff_duty['post_name'] ?? 'Main Post'); ?></strong>
                        <?php if (!empty($staff_duty['phone_extension'])): ?>
                            <span class="badge bg-black bg-opacity-25 text-slate-300">Ext: <?php echo htmlspecialchars($staff_duty['phone_extension']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="text-slate-300 small">
                        <strong><?php echo htmlspecialchars($staff_duty['shift_name']); ?></strong> (<?php echo date('h:i A', strtotime($staff_duty['start_time'])); ?> - <?php echo date('h:i A', strtotime($staff_duty['end_time'])); ?>)
                        <?php if ($duty_is_on && !empty($staff_duty['clock_in_time'])): ?>
                            &bull; Clocked in at <strong><?php echo date('h:i A', strtotime($staff_duty['clock_in_time'])); ?></strong>
                        <?php elseif ($duty_is_done && !empty($staff_duty['clock_out_time'])): ?>
                            &bull; Concluded at <strong><?php echo date('h:i A', strtotime($staff_duty['clock_out_time'])); ?></strong>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2">
                <?php if ($duty_is_sched): ?>
                    <button type="button" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm" onclick="quickClockInOutIndex(<?php echo $staff_duty['id']; ?>, 'clock_in')">
                        <i class="fa-solid fa-arrow-right-to-bracket me-1"></i> Clock In For Duty
                    </button>
                <?php elseif ($duty_is_on): ?>
                    <button type="button" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm" onclick="quickClockInOutIndex(<?php echo $staff_duty['id']; ?>, 'clock_out')">
                        <i class="fa-solid fa-arrow-right-from-bracket me-1"></i> Clock Out Shift
                    </button>
                <?php endif; ?>
                <a href="roster" class="btn btn-outline-light rounded-pill px-3">
                    <i class="fa-solid fa-calendar me-1"></i> My Duty Schedule
                </a>
            </div>
        </div>
    </div>

    <script>
    async function quickClockInOutIndex(rosterId, type) {
        let handover = '';
        if (type === 'clock_out') {
            handover = await EstateDialog.prompt({
                title: 'Shift Handover & Clock Out',
                message: "Optional shift handover notes for the incoming officer:",
                inputType: 'textarea',
                placeholder: 'e.g. Everything orderly, keys handed over...',
                confirmText: 'Clock Out'
            });
            if (handover === null) return;
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
                    EstateDialog.toast({ type: 'success', message: res.message || 'Attendance updated.' });
                    setTimeout(() => location.reload(), 1000);
                } else {
                    EstateDialog.alert({ title: 'Clock Error', message: res.error || 'Failed to update attendance.', type: 'danger' });
                }
            })
            .catch(err => {
                console.error(err);
                EstateDialog.toast({ type: 'error', message: 'Network error updating attendance.' });
            });
    }
    </script>
<?php endif; ?>

    <!-- ==========================================
         ROW 1: 3 METRIC CARDS (Exact Reference UI)
         ========================================== -->
    <div class="row g-3 g-xl-4 mb-4">
        <!-- Card 1: Total Property -->
        <div class="col-12 col-md-4">
            <div class="metric-card metric-card-purple h-100">
                <div class="metric-card-header">
                    <span class="metric-card-title">
                        Total property <i class="fa-regular fa-circle-question ms-1 metric-info-icon" title="Estate properties under patrol and security coverage"></i>
                    </span>
                    <a href="../staff/directory" class="metric-see-details-btn">See Details</a>
                </div>
                <div class="metric-value-row">
                    <span class="metric-info-circle-badge">i</span>
                    <span class="metric-value">268</span>
                    <span class="metric-trend-pill">
                        <i class="fa-solid fa-arrow-up"></i> 16.8%
                    </span>
                </div>
                <div class="metric-sparkline-wrap">
                    <svg viewBox="0 0 300 70" preserveAspectRatio="none" class="metric-sparkline-svg">
                        <defs>
                            <linearGradient id="stfPurpleWaveGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" stop-color="#6366f1" stop-opacity="0.25" />
                                <stop offset="100%" stop-color="#6366f1" stop-opacity="0.0" />
                            </linearGradient>
                        </defs>
                        <path d="M 0 50 Q 40 50, 70 20 T 140 25 T 210 50 T 260 55 L 300 55 L 300 70 L 0 70 Z" fill="url(#stfPurpleWaveGrad)" />
                        <path d="M 0 50 Q 40 50, 70 20 T 140 25 T 210 50 T 260 55 L 300 55" fill="none" stroke="#6366f1" stroke-width="2.5" stroke-linecap="round" />
                    </svg>
                </div>
                <div class="metric-footer-note">
                    Gate passes and cleared units<br>increased by <strong>10%</strong> in last 7 days
                </div>
            </div>
        </div>

        <!-- Card 2: Total Income -->
        <div class="col-12 col-md-4">
            <div class="metric-card metric-card-green h-100">
                <div class="metric-card-header">
                    <span class="metric-card-title">
                        Total income <i class="fa-regular fa-circle-question ms-1 metric-info-icon" title="Daily collections & financial verifications"></i>
                    </span>
                    <a href="../staff/finance" class="metric-see-details-btn">See Details</a>
                </div>
                <div class="metric-value-row">
                    <span class="metric-info-circle-badge">i</span>
                    <span class="metric-value">$<?= number_format(max(163848, $today_collected > 0 ? $today_collected : 163848)) ?></span>
                    <span class="metric-trend-pill">
                        <i class="fa-solid fa-arrow-up"></i> 12.8%
                    </span>
                </div>
                <div class="metric-sparkline-wrap">
                    <svg viewBox="0 0 300 70" preserveAspectRatio="none" class="metric-sparkline-svg">
                        <defs>
                            <linearGradient id="stfGreenWaveGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" stop-color="#10b981" stop-opacity="0.25" />
                                <stop offset="100%" stop-color="#10b981" stop-opacity="0.0" />
                            </linearGradient>
                        </defs>
                        <path d="M 0 52 Q 35 22, 60 25 T 120 48 T 170 18 T 225 52 T 265 40 L 300 55 L 300 70 L 0 70 Z" fill="url(#stfGreenWaveGrad)" />
                        <path d="M 0 52 Q 35 22, 60 25 T 120 48 T 170 18 T 225 52 T 265 40 L 300 55" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" />
                    </svg>
                </div>
                <div class="metric-footer-note">
                    Operations clearing flow<br>increased by <strong>15%</strong> in last 7 days
                </div>
            </div>
        </div>

        <!-- Card 3: Total Sales -->
        <div class="col-12 col-md-4">
            <div class="metric-card metric-card-orange h-100">
                <div class="metric-card-header">
                    <span class="metric-card-title">
                        Total sales <i class="fa-regular fa-circle-question ms-1 metric-info-icon" title="Assessment throughput and facility tickets"></i>
                    </span>
                    <a href="../staff/security" class="metric-see-details-btn">See Details</a>
                </div>
                <div class="metric-value-row">
                    <span class="metric-info-circle-badge">i</span>
                    <span class="metric-value">₦848,848</span>
                    <span class="metric-trend-pill">
                        <i class="fa-solid fa-arrow-up"></i> 16.8%
                    </span>
                </div>
                <div class="metric-sparkline-wrap">
                    <svg viewBox="0 0 300 70" preserveAspectRatio="none" class="metric-sparkline-svg">
                        <defs>
                            <linearGradient id="stfOrangeWaveGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" stop-color="#f97316" stop-opacity="0.25" />
                                <stop offset="100%" stop-color="#f97316" stop-opacity="0.0" />
                            </linearGradient>
                        </defs>
                        <path d="M 0 55 Q 50 55, 95 38 T 175 20 T 240 45 L 300 52 L 300 70 L 0 70 Z" fill="url(#stfOrangeWaveGrad)" />
                        <path d="M 0 55 Q 50 55, 95 38 T 175 20 T 240 45 L 300 52" fill="none" stroke="#f97316" stroke-width="2.5" stroke-linecap="round" />
                    </svg>
                </div>
                <div class="metric-footer-note">
                    Operational throughput<br>increased by <strong>10%</strong> in last 7 days
                </div>
            </div>
        </div>
    </div>

    <!-- ==========================================
         ROW 2: DUAL PANELS (Spline Chart + Message)
         ========================================== -->
    <div class="row g-3 g-xl-4 mb-4">
        <!-- Left Panel: Property Revenue Overview Spline Chart -->
        <div class="col-12 col-xl-8">
            <div class="chart-panel-card h-100">
                <div class="chart-panel-header">
                    <div>
                        <h4 class="chart-panel-title">Property revenue overview</h4>
                        <div class="chart-panel-subtitle">Overview of July 15–July 22</div>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <div class="chart-legend-wrap">
                            <span class="chart-legend-item">
                                <span class="chart-legend-dot chart-legend-dot-purple"></span> Expense
                            </span>
                            <span class="chart-legend-item">
                                <span class="chart-legend-dot chart-legend-dot-green"></span> Income
                            </span>
                        </div>
                        <div class="dropdown">
                            <button class="chart-period-dropdown-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                Period: This Week
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3">
                                <li><a class="dropdown-item small" href="#">Period: This Week</a></li>
                                <li><a class="dropdown-item small" href="#">Period: This Month</a></li>
                                <li><a class="dropdown-item small" href="#">Period: This Year</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="chart-canvas-container" style="position: relative; height: 260px; width: 100%;">
                    <canvas id="staffRevenueSplineChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Right Panel: Message Widget -->
        <div class="col-12 col-xl-4">
            <div class="message-widget-card h-100">
                <div class="message-widget-header">
                    <div>
                        <h4 class="message-widget-title">Message</h4>
                        <div class="message-widget-subtitle">All conversations</div>
                    </div>
                    <div class="dropdown">
                        <button class="message-period-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Today
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3">
                            <li><a class="dropdown-item small" href="#">Today</a></li>
                            <li><a class="dropdown-item small" href="#">Yesterday</a></li>
                            <li><a class="dropdown-item small" href="#">This Week</a></li>
                        </ul>
                    </div>
                </div>

                <div class="message-list">
                    <!-- Message Item 1 -->
                    <div class="message-item">
                        <div class="message-avatar message-avatar-dark">JC</div>
                        <div class="message-content">
                            <div class="message-sender-name">Jane Cooper</div>
                            <div class="message-snippet">I need a property</div>
                        </div>
                        <div class="message-meta">
                            <span class="message-time">12 min ago</span>
                            <span class="message-unread-badge">12</span>
                        </div>
                    </div>

                    <!-- Message Item 2 -->
                    <div class="message-item">
                        <div class="message-avatar message-avatar-purple">RG</div>
                        <div class="message-content">
                            <div class="message-sender-name">Brooklyn Simmons</div>
                            <div class="message-snippet">My budget is ₦500,000</div>
                        </div>
                        <div class="message-meta">
                            <span class="message-time">1 day ago</span>
                        </div>
                    </div>

                    <!-- Message Item 3 -->
                    <div class="message-item">
                        <div class="message-avatar message-avatar-orange">EH</div>
                        <div class="message-content">
                            <div class="message-sender-name">Esther Howard</div>
                            <div class="message-snippet">Thank you so much 😄</div>
                        </div>
                        <div class="message-meta">
                            <span class="message-time">5 hrs ago</span>
                            <span class="message-unread-badge">8</span>
                        </div>
                    </div>

                    <!-- Message Item 4 -->
                    <div class="message-item">
                        <div class="message-avatar message-avatar-green">GH</div>
                        <div class="message-content">
                            <div class="message-sender-name">Guy Hawkins</div>
                            <div class="message-snippet">How much is...</div>
                        </div>
                        <div class="message-meta">
                            <span class="message-time">3 day ago</span>
                        </div>
                    </div>
                </div>

                <div class="message-widget-footer">
                    <a href="../staff/community_chat" class="message-view-all-btn">
                        View all messages <i class="fa-solid fa-arrow-up-right-from-square ms-1" style="font-size: 0.72rem;"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- ==========================================
         ROW 3: ACTIVE PROPERTY DIRECTORY TABLE CARD
         ========================================== -->
    <div class="active-property-card mb-4">
        <div class="active-property-header">
            <div>
                <h4 class="active-property-title">Active Property</h4>
                <div class="active-property-subtitle">Overview of July 15–July 31</div>
            </div>
            <button type="button" class="property-filter-pill-btn">
                <i class="fa-solid fa-sliders"></i> Filter
            </button>
        </div>

        <div class="active-property-table-responsive">
            <table class="active-property-table">
                <thead>
                    <tr>
                        <th style="min-width: 220px;">Property</th>
                        <th style="min-width: 140px;">Location</th>
                        <th style="min-width: 130px;">Contact</th>
                        <th style="min-width: 110px;">Date</th>
                        <th style="min-width: 120px;">Engagement</th>
                        <th style="min-width: 110px;">Price</th>
                        <th style="text-align: right; min-width: 90px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Row 1: Opulence Oasis -->
                    <tr>
                        <td>
                            <div class="prop-cell-flex">
                                <div class="prop-thumbnail-box prop-thumb-teal"></div>
                                <div>
                                    <div class="prop-name">Opulence Oasis</div>
                                    <div class="prop-specs">3 beds · 2 bath · 1200sqft</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="prop-label-dim">Location</div>
                            <div class="prop-val-text">Paris, France</div>
                        </td>
                        <td>
                            <div class="prop-label-dim">Contact</div>
                            <div class="prop-val-text">(406) 555-0120</div>
                        </td>
                        <td>
                            <div class="prop-label-dim">Date</div>
                            <div class="prop-val-text">6 Jul 2024</div>
                        </td>
                        <td>
                            <div class="prop-label-dim">Engagement</div>
                            <div class="prop-val-views">
                                <i class="fa-regular fa-eye"></i> 1,747 Views
                            </div>
                        </td>
                        <td>
                            <div class="prop-label-dim">Price</div>
                            <div class="prop-val-price">₦85,858.59</div>
                        </td>
                        <td style="text-align: right;">
                            <a href="../staff/directory" class="prop-edit-pill-btn">
                                <i class="fa-solid fa-pencil"></i> Edit
                            </a>
                        </td>
                    </tr>

                    <!-- Row 2: Regal Retreat -->
                    <tr>
                        <td>
                            <div class="prop-cell-flex">
                                <div class="prop-thumbnail-box prop-thumb-gold"></div>
                                <div>
                                    <div class="prop-name">Regal Retreat</div>
                                    <div class="prop-specs">3 beds · 2 beds · 1400sqft</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="prop-label-dim">Location</div>
                            <div class="prop-val-text">Paris, France</div>
                        </td>
                        <td>
                            <div class="prop-label-dim">Contact</div>
                            <div class="prop-val-text">(480) 555-0103</div>
                        </td>
                        <td>
                            <div class="prop-label-dim">Date</div>
                            <div class="prop-val-text">7 Jul 2024</div>
                        </td>
                        <td>
                            <div class="prop-label-dim">Engagement</div>
                            <div class="prop-val-views">
                                <i class="fa-regular fa-eye"></i> 1,656 Views
                            </div>
                        </td>
                        <td>
                            <div class="prop-label-dim">Price</div>
                            <div class="prop-val-price">₦94,346.40</div>
                        </td>
                        <td style="text-align: right;">
                            <a href="../staff/directory" class="prop-edit-pill-btn">
                                <i class="fa-solid fa-pencil"></i> Edit
                            </a>
                        </td>
                    </tr>

                    <?php if (!empty($recent_visitors) && $recent_visitors->num_rows > 0): 
                        $first_v = $recent_visitors->fetch_assoc();
                        $recent_visitors->data_seek(0);
                    ?>
                    <!-- Row 3: Live Gate Unit / Pass Entry -->
                    <tr>
                        <td>
                            <div class="prop-cell-flex">
                                <div class="prop-thumbnail-box prop-thumb-purple"></div>
                                <div>
                                    <div class="prop-name">Flat <?= htmlspecialchars($first_v['flat_number'] ?? '101') ?> · <?= htmlspecialchars($first_v['name'] ?? 'Guest Pass') ?></div>
                                    <div class="prop-specs"><?= htmlspecialchars($first_v['resident_name'] ?? 'Resident Host') ?> · Verified Pass</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="prop-label-dim">Location</div>
                            <div class="prop-val-text">Paris, France</div>
                        </td>
                        <td>
                            <div class="prop-label-dim">Contact</div>
                            <div class="prop-val-text"><?= htmlspecialchars($first_v['phone'] ?? '(406) 555-0120') ?></div>
                        </td>
                        <td>
                            <div class="prop-label-dim">Date</div>
                            <div class="prop-val-text"><?= date('j M Y') ?></div>
                        </td>
                        <td>
                            <div class="prop-label-dim">Engagement</div>
                            <div class="prop-val-views">
                                <i class="fa-regular fa-eye"></i> 1,980 Views
                            </div>
                        </td>
                        <td>
                            <div class="prop-label-dim">Price</div>
                            <div class="prop-val-price">$78,250.00</div>
                        </td>
                        <td style="text-align: right;">
                            <a href="../staff/security" class="prop-edit-pill-btn">
                                <i class="fa-solid fa-pencil"></i> Edit
                            </a>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div><!-- end .desktop-only -->

<!-- Chart.js Engine for Dual Area Spline Chart -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('staffRevenueSplineChart');
    if (!ctx) return;

    const chartCtx = ctx.getContext('2d');

    // Expense Purple Gradient
    const purpleGrad = chartCtx.createLinearGradient(0, 0, 0, 240);
    purpleGrad.addColorStop(0, 'rgba(99, 102, 241, 0.28)');
    purpleGrad.addColorStop(1, 'rgba(99, 102, 241, 0.0)');

    // Income Green Gradient
    const greenGrad = chartCtx.createLinearGradient(0, 0, 0, 240);
    greenGrad.addColorStop(0, 'rgba(16, 185, 129, 0.22)');
    greenGrad.addColorStop(1, 'rgba(16, 185, 129, 0.0)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: ['Dec', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Aug', 'Sep'],
            datasets: [
                {
                    label: 'Expense',
                    data: [10.5, 9.2, 10.1, 7.8, 12.8, 10.2, 16.5, 17.1, 14.2],
                    borderColor: '#6366f1',
                    borderWidth: 2.5,
                    backgroundColor: purpleGrad,
                    fill: true,
                    tension: 0.45,
                    pointRadius: 0,
                    pointHoverRadius: 6,
                    pointHoverBackgroundColor: '#6366f1',
                    pointHoverBorderColor: '#ffffff',
                    pointHoverBorderWidth: 2
                },
                {
                    label: 'Income',
                    data: [7.2, 5.8, 4.3, 7.1, 9.8, 5.2, 8.4, 7.9, 6.1],
                    borderColor: '#10b981',
                    borderWidth: 2,
                    borderDash: [5, 4],
                    backgroundColor: greenGrad,
                    fill: true,
                    tension: 0.45,
                    pointRadius: 0,
                    pointHoverRadius: 5,
                    pointHoverBackgroundColor: '#10b981',
                    pointHoverBorderColor: '#ffffff',
                    pointHoverBorderWidth: 2
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.88)',
                    titleColor: '#ffffff',
                    bodyColor: '#e2e8f0',
                    padding: 10,
                    cornerRadius: 8,
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': $' + (context.parsed.y * 1000).toLocaleString();
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false,
                        drawBorder: false
                    },
                    ticks: {
                        color: '#94a3b8',
                        font: {
                            family: 'Outfit, sans-serif',
                            size: 11
                        }
                    }
                },
                y: {
                    min: 0,
                    max: 20,
                    ticks: {
                        stepSize: 5,
                        color: '#94a3b8',
                        font: {
                            family: 'Outfit, sans-serif',
                            size: 11
                        },
                        callback: function(value) {
                            return value === 0 ? '' : value + 'K';
                        }
                    },
                    grid: {
                        color: 'rgba(226, 232, 240, 0.65)',
                        drawBorder: false
                    }
                }
            }
        }
    });
});
</script>

<?php include 'footer.php'; ?>
