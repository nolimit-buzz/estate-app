<?php
// staff/security.php
include 'header.php';
include 'sidebar.php';

requirePermission('visitors.view_log');

$estate_id = get_estate_id();
$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Handle Form Submissions (Check-In / Check-Out)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $error = "CSRF Verification failed.";
    } else {
        // 1. Process Check-In by Passcode
        if (isset($_POST['process_check_in'])) {
            $code = strtoupper(trim($_POST['visitor_code']));
            $gate = $conn->real_escape_string($_POST['gate_name'] ?? 'Main Gate');
            
            $res = $conn->query("SELECT * FROM visitors WHERE estate_id = $estate_id AND (visitor_code = '$code' OR id = " . intval($code) . ") AND status IN ('pre_registered', 'confirmed')");
            if ($res && $res->num_rows > 0) {
                $vis = $res->fetch_assoc();
                $v_id = $vis['id'];
                
                $sql = "UPDATE visitors SET 
                        status = 'entered', 
                        entry_time = NOW(), 
                        entry_processed_by = $user_id, 
                        entry_gate = '$gate' 
                        WHERE id = $v_id AND estate_id = $estate_id";
                if ($conn->query($sql)) {
                    logAudit($conn, "Visitor Check-In", "Security", "Checked in visitor {$vis['name']} (Code: $code) at $gate by staff ID $user_id.");
                    $_SESSION['success_message'] = "Visitor '{$vis['name']}' checked in successfully at $gate!";
                    header("Location: security");
                    exit;
                } else {
                    $error = "Error updating entry: " . $conn->error;
                }
            } else {
                $error = "Invalid or expired visitor passcode '$code'. Please verify with the resident.";
            }
        }
        
        // 2. Process Check-Out
        elseif (isset($_POST['process_check_out'])) {
            $v_id = intval($_POST['visitor_id']);
            $gate = $conn->real_escape_string($_POST['gate_name'] ?? 'Main Gate');
            
            $res = $conn->query("SELECT * FROM visitors WHERE id = $v_id AND estate_id = $estate_id");
            if ($res && $res->num_rows > 0) {
                $vis = $res->fetch_assoc();
                $sql = "UPDATE visitors SET 
                        status = 'checked_out', 
                        exit_time = NOW(), 
                        exit_processed_by = $user_id, 
                        exit_gate = '$gate' 
                        WHERE id = $v_id AND estate_id = $estate_id";
                if ($conn->query($sql)) {
                    logAudit($conn, "Visitor Check-Out", "Security", "Checked out visitor {$vis['name']} at $gate by staff ID $user_id.");
                    $_SESSION['success_message'] = "Visitor '{$vis['name']}' checked out successfully!";
                    header("Location: security");
                    exit;
                } else {
                    $error = "Error processing check-out: " . $conn->error;
                }
            }
        }
    }
}

// Fetch Active Visitors inside the estate
$inside_visitors = $conn->query("SELECT v.*, u.name as resident_name, u.phone as resident_phone, f.number as flat_number, 
    TIMESTAMPDIFF(MINUTE, v.entry_time, NOW()) as duration_minutes 
    FROM visitors v 
    LEFT JOIN users u ON v.resident_id = u.id 
    LEFT JOIN flats f ON v.flat_id = f.id 
    WHERE v.estate_id = $estate_id AND v.status = 'entered' 
    ORDER BY v.entry_time DESC");

// Fetch Expected Pre-Registered Visitors
$expected_visitors = $conn->query("SELECT v.*, u.name as resident_name, f.number as flat_number 
    FROM visitors v 
    LEFT JOIN users u ON v.resident_id = u.id 
    LEFT JOIN flats f ON v.flat_id = f.id 
    WHERE v.estate_id = $estate_id AND v.status = 'pre_registered' 
    ORDER BY v.id DESC LIMIT 15");

// Fetch Recent Entry/Exit History Log
$history_visitors = $conn->query("SELECT v.*, u.name as resident_name, f.number as flat_number,
    e_staff.name as entry_staff_name, x_staff.name as exit_staff_name
    FROM visitors v 
    LEFT JOIN users u ON v.resident_id = u.id 
    LEFT JOIN flats f ON v.flat_id = f.id 
    LEFT JOIN users e_staff ON v.entry_processed_by = e_staff.id 
    LEFT JOIN users x_staff ON v.exit_processed_by = x_staff.id 
    WHERE v.estate_id = $estate_id AND v.status IN ('checked_out', 'exited_without_confirmation', 'cancelled') 
    ORDER BY v.id DESC LIMIT 20");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 font-bold text-slate-800 m-0"><i class="fa-solid fa-shield-halved me-2 text-teal" style="color: #0d9488;"></i> Security & Gate Pass Control</h2>
        <p class="text-secondary small mb-0">Verify visitor codes, process entry/exit passes, and monitor real-time gate activity.</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <!-- Verification Card -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm" style="border-top: 4px solid #0d9488 !important;">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="card-title fw-bold m-0 text-slate-800"><i class="fa-solid fa-qrcode me-2 text-teal" style="color: #0d9488;"></i> Gate Verification Scanner</h5>
            </div>
            <div class="card-body">
                <form action="security" method="POST">
                    <?php echo renderCSRFField(); ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-slate-700">Enter Visitor Passcode Code</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-light"><i class="fa-solid fa-key text-muted"></i></span>
                            <input type="text" name="visitor_code" class="form-control text-uppercase fw-bold letter-spacing-1" placeholder="e.g. VIS-12345 or 12345" required style="letter-spacing: 2px;">
                        </div>
                        <div class="form-text">Enter the 6-digit access passcode generated by the resident.</div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold text-slate-700">Select Gate Location</label>
                        <select name="gate_name" class="form-select">
                            <option value="Main Gate">Main Gate</option>
                            <option value="North Gate">North Gate</option>
                            <option value="South Gate">South Gate</option>
                            <option value="Pedestrian Gate">Pedestrian Gate</option>
                        </select>
                    </div>

                    <?php if (hasPermission('visitors.check_in_out')): ?>
                        <button type="submit" name="process_check_in" class="btn btn-primary btn-lg w-100 fw-bold" style="background: #0d9488; border: none;">
                            <i class="fa-solid fa-right-to-bracket me-2"></i> Process Gate Entry
                        </button>
                    <?php else: ?>
                        <div class="alert alert-warning small mb-0"><i class="fa-solid fa-lock me-1"></i> Your staff role does not have gate check-in permissions.</div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- Active Visitors Currently Inside -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h5 class="card-title fw-bold m-0 text-slate-800">
                    <i class="fa-solid fa-street-view me-2 text-teal" style="color: #0d9488;"></i> Currently Inside Estate 
                    <span class="badge bg-teal rounded-pill ms-1" style="background: #0d9488;"><?php echo $inside_visitors ? $inside_visitors->num_rows : 0; ?></span>
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
                    <table class="table align-middle table-hover mb-0">
                        <thead class="bg-light text-secondary small text-uppercase sticky-top">
                            <tr>
                                <th>Visitor / Code</th>
                                <th>Host / Flat</th>
                                <th>Entered At</th>
                                <th>Duration</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($inside_visitors && $inside_visitors->num_rows > 0): ?>
                                <?php while ($vis = $inside_visitors->fetch_assoc()): ?>
                                    <?php 
                                    $duration_mins = intval($vis['duration_minutes']);
                                    $is_overstay = ($duration_mins > 720); // > 12 hours
                                    ?>
                                    <tr class="<?php echo $is_overstay ? 'table-danger' : ''; ?>">
                                        <td>
                                            <div class="fw-bold text-slate-800"><?php echo htmlspecialchars($vis['name']); ?></div>
                                            <span class="badge bg-dark text-monospace"><?php echo htmlspecialchars($vis['visitor_code'] ?? 'N/A'); ?></span>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars($vis['phone'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($vis['resident_name'] ?? 'General Visitor'); ?></div>
                                            <small class="text-muted">Flat <?php echo htmlspecialchars($vis['flat_number'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td>
                                            <small class="fw-semibold"><?php echo date('h:i A', strtotime($vis['entry_time'])); ?></small>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars($vis['entry_gate'] ?? 'Main Gate'); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $is_overstay ? 'bg-danger' : 'bg-info text-dark'; ?>">
                                                <?php echo formatDuration($duration_mins); ?>
                                            </span>
                                            <?php if ($is_overstay): ?>
                                                <small class="text-danger fw-bold d-block"><i class="fa-solid fa-triangle-exclamation"></i> Overstay Warning</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="../gate_pass?id=<?php echo $vis['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary me-1" title="Print Gate Pass">
                                                <i class="fa-solid fa-print"></i>
                                            </a>
                                            <?php if (hasPermission('visitors.check_in_out')): ?>
                                                <form action="security" method="POST" class="d-inline" onsubmit="return confirm('Process check-out for <?php echo htmlspecialchars(addslashes($vis['name'])); ?>?');">
                                                    <?php echo renderCSRFField(); ?>
                                                    <input type="hidden" name="visitor_id" value="<?php echo $vis['id']; ?>">
                                                    <input type="hidden" name="gate_name" value="Main Gate">
                                                    <button type="submit" name="process_check_out" class="btn btn-sm btn-outline-danger">
                                                        <i class="fa-solid fa-right-from-bracket me-1"></i> Check Out
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">View Only</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">No visitors currently inside the estate.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs for Expected Arrivals & History Log -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 border-0">
        <ul class="nav nav-tabs card-header-tabs" id="securityTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active fw-bold" id="expected-tab" data-bs-toggle="tab" data-bs-target="#expected" type="button">
                    <i class="fa-solid fa-clipboard-check me-1 text-primary"></i> Pre-Registered Arrivals
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-bold" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button">
                    <i class="fa-solid fa-history me-1 text-secondary"></i> Gate Log History
                </button>
            </li>
        </ul>
    </div>
    <div class="card-body p-0">
        <div class="tab-content" id="securityTabsContent">
            <!-- Expected Arrivals Tab -->
            <div class="tab-pane fade show active" id="expected" role="tabpanel">
                <div class="table-responsive">
                    <table class="table align-middle table-hover mb-0">
                        <thead class="bg-light text-secondary small text-uppercase">
                            <tr>
                                <th>Passcode</th>
                                <th>Visitor Name</th>
                                <th>Host Resident</th>
                                <th>Purpose</th>
                                <th>Expected Date / Time</th>
                                <th class="text-end">Quick Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($expected_visitors && $expected_visitors->num_rows > 0): ?>
                                <?php while ($exp = $expected_visitors->fetch_assoc()): ?>
                                    <tr>
                                        <td><span class="badge bg-dark text-monospace px-2 py-1 fs-6"><?php echo htmlspecialchars($exp['visitor_code'] ?? 'N/A'); ?></span></td>
                                        <td>
                                            <div class="fw-bold text-slate-800"><?php echo htmlspecialchars($exp['name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($exp['phone'] ?? 'No Phone'); ?></small>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($exp['resident_name'] ?? 'N/A'); ?></div>
                                            <small class="text-muted">Flat <?php echo htmlspecialchars($exp['flat_number'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($exp['purpose'] ?? 'Visit'); ?></span></td>
                                        <td>
                                            <small class="fw-semibold text-slate-700">
                                                <?php echo $exp['expected_arrival'] ? date('d M, h:i A', strtotime($exp['expected_arrival'])) : 'Today'; ?>
                                            </small>
                                        </td>
                                        <td class="text-end">
                                            <a href="../gate_pass?id=<?php echo $exp['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary me-1" title="Print Gate Pass">
                                                <i class="fa-solid fa-print"></i>
                                            </a>
                                            <?php if (hasPermission('visitors.check_in_out')): ?>
                                                <form action="security" method="POST" class="d-inline">
                                                    <?php echo renderCSRFField(); ?>
                                                    <input type="hidden" name="visitor_code" value="<?php echo htmlspecialchars($exp['visitor_code']); ?>">
                                                    <button type="submit" name="process_check_in" class="btn btn-sm btn-success">
                                                        <i class="fa-solid fa-check me-1"></i> Quick Check-In
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No pre-registered visitors pending check-in.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- History Log Tab -->
            <div class="tab-pane fade" id="history" role="tabpanel">
                <div class="table-responsive">
                    <table class="table align-middle table-hover mb-0">
                        <thead class="bg-light text-secondary small text-uppercase">
                            <tr>
                                <th>Visitor / Code</th>
                                <th>Host / Flat</th>
                                <th>Entry Time & Officer</th>
                                <th>Exit Time & Officer</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($history_visitors && $history_visitors->num_rows > 0): ?>
                                <?php while ($h = $history_visitors->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($h['name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($h['visitor_code'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($h['resident_name'] ?? 'N/A'); ?></div>
                                            <small class="text-muted">Flat <?php echo htmlspecialchars($h['flat_number'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td>
                                            <small class="d-block fw-semibold"><?php echo $h['entry_time'] ? date('d M, h:i A', strtotime($h['entry_time'])) : '-'; ?></small>
                                            <small class="text-muted"><i class="fa-solid fa-user-shield me-1"></i> <?php echo htmlspecialchars($h['entry_staff_name'] ?? 'Gate Officer'); ?></small>
                                        </td>
                                        <td>
                                            <small class="d-block fw-semibold"><?php echo $h['exit_time'] ? date('d M, h:i A', strtotime($h['exit_time'])) : '-'; ?></small>
                                            <small class="text-muted"><i class="fa-solid fa-user-shield me-1"></i> <?php echo htmlspecialchars($h['exit_staff_name'] ?? 'Gate Officer'); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst($h['status'])); ?></span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">No gate history records found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
