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

// Count user's assigned permissions
$role_id = $_SESSION['role_id'] ?? 0;
$perms_count = 0;
if ($role_id) {
    $p_res = $conn->query("SELECT COUNT(*) as cnt FROM role_permissions WHERE role_id = " . intval($role_id));
    $perms_count = $p_res ? $p_res->fetch_assoc()['cnt'] : 0;
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 font-bold text-slate-800 m-0">Staff Console Dashboard</h2>
        <p class="text-secondary small mb-0">Welcome back, <strong><?php echo htmlspecialchars($user_name); ?></strong>. Role: <span class="badge bg-teal" style="background: #0f766e;"><?php echo htmlspecialchars($user_role_display); ?></span></p>
    </div>
    <div class="d-flex gap-2">
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
