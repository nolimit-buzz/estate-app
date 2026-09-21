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

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 font-bold text-slate-800 m-0">Staff Console Dashboard</h2>
        <p class="text-secondary small mb-0">Welcome back, <strong><?php echo htmlspecialchars($user_name); ?></strong>. Role: <span class="badge bg-teal" style="background: #0f766e;"><?php echo htmlspecialchars($user_role_display); ?></span></p>
    </div>
    <div class="d-flex gap-2">
        <a href="roster" class="btn btn-outline-dark rounded-pill px-3 shadow-sm">
            <i class="fa-solid fa-calendar-check me-1 text-teal" style="color: #0d9488;"></i> My Duty Roster &amp; Calendar
        </a>
        <a href="community_chat" class="btn btn-outline-secondary rounded-pill px-3 shadow-sm">
            <i class="fa-solid fa-comments me-1"></i> Estate Forum
        </a>
        <?php if ($can_gate && hasPermission('visitors.check_in_out')): ?>
            <a href="security" class="btn btn-primary" style="background: #0d9488; border: none;">
                <i class="fa-solid fa-qrcode me-1"></i> Verify Visitor Code
            </a>
        <?php endif; ?>
        <?php if ($can_finance && hasPermission('finance.view_invoices')): ?>
            <a href="finance" class="btn btn-success" style="background: #10b981; border: none;">
                <i class="fa-solid fa-file-invoice-dollar me-1"></i> Billing & Receipts
            </a>
        <?php endif; ?>
        <?php if ($can_residents && hasPermission('residents.manage')): ?>
            <a href="residents" class="btn btn-primary" style="background: #0284c7; border: none;">
                <i class="fa-solid fa-user-plus me-1"></i> Resident Onboarding
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

<!-- Dynamic KPI Cards Row -->
<div class="row g-3 mb-4">
    <?php if ($can_gate): ?>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 border-start border-4 border-teal" style="border-color: #0d9488 !important;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small fw-semibold text-uppercase">Visitors Inside</span>
                        <h3 class="fw-bold mb-0 text-slate-800"><?php echo $today_visitors; ?></h3>
                    </div>
                    <div class="p-3 rounded-circle" style="background: #ccfbf1; color: #0d9488;">
                        <i class="fa-solid fa-person-walking-arrow-right fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 border-start border-4 border-primary">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small fw-semibold text-uppercase">Expected Arrivals</span>
                        <h3 class="fw-bold mb-0 text-slate-800"><?php echo $pending_checkins; ?></h3>
                    </div>
                    <div class="p-3 rounded-circle" style="background: #e0f2fe; color: #0284c7;">
                        <i class="fa-solid fa-clipboard-user fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($can_finance): ?>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 border-start border-4 border-danger">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small fw-semibold text-uppercase">Unpaid Invoices</span>
                        <h3 class="fw-bold mb-0 text-slate-800"><?php echo $unpaid_invoices_cnt; ?></h3>
                    </div>
                    <div class="p-3 rounded-circle" style="background: #fee2e2; color: #dc2626;">
                        <i class="fa-solid fa-file-invoice-dollar fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 border-start border-4 border-success">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small fw-semibold text-uppercase">Collected Today</span>
                        <h3 class="fw-bold mb-0 text-slate-800">₦<?php echo number_format($today_collected, 2); ?></h3>
                    </div>
                    <div class="p-3 rounded-circle" style="background: #dcfce7; color: #16a34a;">
                        <i class="fa-solid fa-hand-holding-dollar fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($can_residents && !$can_finance && !$can_gate): ?>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 border-start border-4 border-primary">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small fw-semibold text-uppercase">Active Residents</span>
                        <h3 class="fw-bold mb-0 text-slate-800"><?php echo $total_residents; ?></h3>
                    </div>
                    <div class="p-3 rounded-circle" style="background: #e0f2fe; color: #0284c7;">
                        <i class="fa-solid fa-users fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 border-start border-4 border-warning">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small fw-semibold text-uppercase">Vacant Flats</span>
                        <h3 class="fw-bold mb-0 text-slate-800"><?php echo $vacant_flats; ?></h3>
                    </div>
                    <div class="p-3 rounded-circle" style="background: #fef3c7; color: #d97706;">
                        <i class="fa-solid fa-door-open fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($can_maintenance): ?>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 border-start border-4 border-warning">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small fw-semibold text-uppercase">My Work Orders</span>
                        <h3 class="fw-bold mb-0 text-slate-800"><?php echo $assigned_work_orders; ?></h3>
                    </div>
                    <div class="p-3 rounded-circle" style="background: #fef3c7; color: #d97706;">
                        <i class="fa-solid fa-wrench fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Main Split Section -->
<div class="row g-4">
    <div class="col-lg-8">
        <?php if ($can_gate && $recent_visitors): ?>
            <!-- Gate Activity Table -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-0">
                    <h5 class="card-title fw-bold m-0 text-slate-800"><i class="fa-solid fa-shield-halved me-2 text-teal" style="color: #0d9488;"></i> Gate Activity Feed</h5>
                    <a href="security" class="btn btn-sm btn-outline-secondary">View All Passes</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle table-hover mb-0">
                            <thead class="bg-light text-secondary small text-uppercase">
                                <tr>
                                    <th>Passcode</th>
                                    <th>Visitor Name</th>
                                    <th>Resident Host</th>
                                    <th>Entry / Scheduled</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($recent_visitors->num_rows > 0): ?>
                                    <?php while ($v = $recent_visitors->fetch_assoc()): ?>
                                        <tr>
                                            <td><span class="badge bg-dark font-monospace px-2 py-1"><?php echo htmlspecialchars($v['visitor_code'] ?? 'N/A'); ?></span></td>
                                            <td>
                                                <div class="fw-semibold text-slate-800"><?php echo htmlspecialchars($v['name']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($v['phone'] ?? ''); ?></small>
                                            </td>
                                            <td>
                                                <div><?php echo htmlspecialchars($v['resident_name'] ?? 'General Visitor'); ?></div>
                                                <small class="text-muted">Flat <?php echo htmlspecialchars($v['flat_number'] ?? 'N/A'); ?></small>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?php echo $v['entry_time'] ? date('d M, h:i A', strtotime($v['entry_time'])) : ($v['expected_arrival'] ? date('d M, h:i A', strtotime($v['expected_arrival'])) : '-'); ?></small>
                                            </td>
                                            <td>
                                                <?php 
                                                $st = $v['status'];
                                                if ($st === 'entered') echo '<span class="badge bg-success">Inside</span>';
                                                elseif ($st === 'pre_registered') echo '<span class="badge bg-primary">Expected</span>';
                                                elseif ($st === 'checked_out') echo '<span class="badge bg-secondary">Checked Out</span>';
                                                else echo '<span class="badge bg-warning text-dark">' . htmlspecialchars($st) . '</span>';
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">No gate entries logged recently.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($can_finance && $recent_invoices): ?>
            <!-- Recent Invoices Table -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-0">
                    <h5 class="card-title fw-bold m-0 text-slate-800"><i class="fa-solid fa-file-invoice-dollar me-2 text-success"></i> Recent Estate Invoices</h5>
                    <a href="finance" class="btn btn-sm btn-outline-secondary">View All Bills</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle table-hover mb-0">
                            <thead class="bg-light text-secondary small text-uppercase">
                                <tr>
                                    <th>ID</th>
                                    <th>Resident</th>
                                    <th>Title</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($recent_invoices->num_rows > 0): ?>
                                    <?php while ($inv = $recent_invoices->fetch_assoc()): ?>
                                        <tr>
                                            <td class="font-monospace text-muted">#<?php echo str_pad($inv['id'], 5, '0', STR_PAD_LEFT); ?></td>
                                            <td class="fw-semibold text-slate-800"><?php echo htmlspecialchars($inv['resident_name']); ?></td>
                                            <td><?php echo htmlspecialchars($inv['title']); ?></td>
                                            <td class="fw-bold">₦<?php echo number_format($inv['amount'], 2); ?></td>
                                            <td>
                                                <span class="badge <?php echo $inv['status'] === 'paid' ? 'bg-success' : 'bg-danger'; ?>">
                                                    <?php echo ucfirst($inv['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">No invoices generated recently.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Right Column: Duty Card -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="card-title fw-bold m-0 text-slate-800"><i class="fa-solid fa-id-card-clip me-2 text-teal" style="color: #0d9488;"></i> My Duty Profile</h5>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-3 p-3 bg-light rounded-3">
                    <div class="rounded-circle bg-white p-3 shadow-sm" style="color: #0d9488;">
                        <i class="fa-solid fa-user-shield fs-3"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-slate-800"><?php echo htmlspecialchars($user_name); ?></div>
                        <div class="small text-muted">Assigned Role: <strong><?php echo htmlspecialchars($user_role_display); ?></strong></div>
                    </div>
                </div>
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Granted Permissions:</span>
                        <span class="badge bg-primary-subtle text-primary"><?php echo $perms_count ?: 'Role Assigned'; ?> Module Grants</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Gate Control:</span>
                        <span class="fw-semibold <?php echo $can_gate ? 'text-success' : 'text-muted'; ?>">
                            <?php echo $can_gate ? 'Authorized' : 'Restricted'; ?>
                        </span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Finance & Billing:</span>
                        <span class="fw-semibold <?php echo $can_finance ? 'text-success' : 'text-muted'; ?>">
                            <?php echo $can_finance ? 'Authorized' : 'Restricted'; ?>
                        </span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Resident Registration:</span>
                        <span class="fw-semibold <?php echo $can_residents ? 'text-success' : 'text-muted'; ?>">
                            <?php echo $can_residents ? 'Authorized' : 'Restricted'; ?>
                        </span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Field Maintenance:</span>
                        <span class="fw-semibold <?php echo $can_maintenance ? 'text-success' : 'text-muted'; ?>">
                            <?php echo $can_maintenance ? 'Authorized' : 'Restricted'; ?>
                        </span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Status:</span>
                        <span class="badge bg-success">Active on Duty</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
