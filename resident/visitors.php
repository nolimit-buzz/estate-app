<?php
// resident/visitors.php
require_once '../config.php';
require_once '../includes/Mailer.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index");
    exit;
}

$user_id = intval($_SESSION['user_id']);
$estate_id = get_estate_id();

// Fetch Resident Flat Link
$res_info = $conn->query("SELECT flat_id FROM residents WHERE user_id = $user_id AND estate_id = $estate_id ORDER BY id DESC LIMIT 1")->fetch_assoc();
$flat_id = $res_info['flat_id'] ?? null;

// Fetch Estate Confirmation Policy
$sys_gate_res = $conn->query("SELECT setting_value FROM system_settings WHERE estate_id = $estate_id AND setting_key = 'require_resident_visitor_confirmation' LIMIT 1");
$req_confirm = ($sys_gate_res && $sys_gate_res->num_rows > 0) ? ($sys_gate_res->fetch_assoc()['setting_value'] == '1') : true;

$message = "";
$error = "";
$new_pass = null;

// Pre-Register Visitor
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register_visitor'])) {
    $first_name = $conn->real_escape_string($_POST['first_name']);
    $last_name = $conn->real_escape_string($_POST['last_name']);
    $full_name = trim($first_name . ' ' . $last_name);
    $phone = $conn->real_escape_string($_POST['phone']);
    $email = isset($_POST['email']) ? $conn->real_escape_string(trim($_POST['email'])) : '';
    $purpose = $conn->real_escape_string($_POST['purpose']);
    $expected_arrival = $conn->real_escape_string($_POST['expected_arrival']);
    
    // Generate unique EST- Access Code (e.g. EST-7K42P9)
    $rand_str = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
    $visitor_code = 'EST-' . $rand_str;
    
    $sql = "INSERT INTO visitors (estate_id, resident_id, flat_id, first_name, last_name, name, phone, email, purpose, visitor_code, expected_arrival, status) 
            VALUES ($estate_id, $user_id, " . ($flat_id ? $flat_id : "NULL") . ", '$first_name', '$last_name', '$full_name', '$phone', " . (!empty($email) ? "'$email'" : "NULL") . ", '$purpose', '$visitor_code', '$expected_arrival', 'pre_registered')";
    
    if ($conn->query($sql)) {
        $inserted_pass_id = $conn->insert_id;
        
        // Dispatch Digital Visitor Pass Email to Resident & Visitor
        EstateMailer::sendVisitorPassEmail($conn, $inserted_pass_id);

        logAudit($conn, "Visitor Pre-registered", "Visitors", "Visitor $full_name pre-registered with code: $visitor_code");
        $message = "Visitor access pass created and delivered to email successfully!";
        $new_pass = [
            'id' => $inserted_pass_id,
            'name' => $full_name,
            'code' => $visitor_code,
            'arrival' => $expected_arrival,
            'purpose' => $purpose
        ];
    } else {
        $error = "Error creating visitor pass: " . $conn->error;
    }
}

// Confirm Visitor by Resident
if (isset($_GET['confirm_id']) || (isset($_POST['confirm_by_code']) && !empty($_POST['confirm_code']))) {
    if (!$req_confirm) {
        $error = "Resident visitor confirmation has been disabled by estate administration.";
    } else {
        $v_id = isset($_GET['confirm_id']) ? intval($_GET['confirm_id']) : 0;
        $v_code = isset($_POST['confirm_code']) ? $conn->real_escape_string(trim($_POST['confirm_code'])) : '';
        
        $where_clause = $v_id > 0 ? "id = $v_id" : "visitor_code = '$v_code'";
        
        $check_v = $conn->query("SELECT * FROM visitors WHERE $where_clause AND resident_id = $user_id AND estate_id = $estate_id");
        if ($check_v && $check_v->num_rows > 0) {
            $v_row = $check_v->fetch_assoc();
            if (in_array($v_row['status'], ['pre_registered', 'entered'])) {
                $conn->query("UPDATE visitors SET status = 'confirmed', resident_confirmed_at = NOW() WHERE id = {$v_row['id']}");
                logAudit($conn, "Resident Confirmed Visitor", "Visitors", "Resident confirmed visitor {$v_row['name']} (Code: {$v_row['visitor_code']})");
                $message = "Visitor '{$v_row['name']}' has been confirmed successfully!";
            } else {
                $error = "Visitor status is already '{$v_row['status']}'. Cannot confirm.";
            }
        } else {
            $error = "Visitor pass not found or not assigned to your property.";
        }
    }
}

// Cancel Pass
if (isset($_GET['cancel_id'])) {
    $cancel_id = intval($_GET['cancel_id']);
    $conn->query("UPDATE visitors SET status = 'cancelled' WHERE id = $cancel_id AND resident_id = $user_id AND estate_id = $estate_id");
    header("Location: visitors");
    exit;
}

// Fetch Visitors Log with calculated duration from entry_time to exit_time
$query = "SELECT v.*, 
                 TIMESTAMPDIFF(MINUTE, v.entry_time, v.exit_time) AS duration_minutes
          FROM visitors v 
          WHERE v.resident_id = $user_id AND v.estate_id = $estate_id 
          ORDER BY v.created_at DESC";
$visitors_res = $conn->query($query);
include 'header.php';
include 'sidebar.php';
?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h1 class="h4 font-bold text-slate-900 m-0" style="letter-spacing: -0.02em;">
                <i class="fa-solid fa-id-card-clip text-primary me-2"></i> Visitor Access Passes
            </h1>
            <span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-shield-halved me-1"></i>Gate Security</span>
        </div>
        <p class="text-secondary small mb-0">Pre-register visitors, issue cryptographic gate pass codes, and manage arrival clearances in real time.</p>
    </div>
</div>

<div class="d-flex flex-column gap-4">
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert" style="background: rgba(16, 185, 129, 0.12); color: #065f46; border-left: 4px solid #10b981 !important;">
            <i class="fa-solid fa-circle-check me-2"></i> <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert" style="background: rgba(239, 68, 68, 0.12); color: #991b1b; border-left: 4px solid #ef4444 !important;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i> <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($new_pass): ?>
        <!-- ==========================================
             FUTURISTIC NEW PASS PRESENTATION CARD
             ========================================== -->
        <div class="futuristic-pass-card">
            <div class="small text-uppercase fw-bold text-slate-400" style="letter-spacing: 0.1em; font-size: 0.75rem;">
                <i class="fa-solid fa-shield-check text-info me-1"></i> Generated Visitor Gate Access Pass
            </div>
            <div class="futuristic-pass-code"><?= htmlspecialchars($new_pass['code']) ?></div>
            <h4 class="fw-bold mb-1 text-white"><?= htmlspecialchars($new_pass['name']) ?></h4>
            <div class="small text-slate-300">
                <i class="fa-regular fa-clock me-1"></i> Expected Arrival: <?= date('M j, Y h:i A', strtotime($new_pass['arrival'])) ?>
            </div>
            
            <div class="d-flex gap-2 justify-content-center flex-wrap mt-3">
                <a href="gate_pass?id=<?= $new_pass['id'] ?>" target="_blank" class="btn btn-primary rounded-pill px-4 fw-semibold shadow-sm">
                    <i class="fa-solid fa-print me-1"></i> Print Pass Slip
                </a>
                <a href="gate_pass?id=<?= $new_pass['id'] ?>&autoprint=1" target="_blank" class="btn btn-outline-light rounded-pill px-3 fw-semibold">
                    <i class="fa-solid fa-file-invoice me-1"></i> Fast Print
                </a>
                <button type="button" onclick="EstateDialog.copy('<?= $new_pass['code'] ?>', 'Pass Code <?= $new_pass['code'] ?> copied to clipboard!');" class="btn btn-light rounded-pill px-3 fw-semibold">
                    <i class="fa-solid fa-copy me-1"></i> Copy Code
                </button>
            </div>
            <div class="small text-slate-400 mt-2" style="font-size: 0.75rem;">
                Share this pass code with your guest or security guard at the estate entrance gate for instantaneous verification.
            </div>
        </div>
    <?php endif; ?>

    <?php if ($req_confirm): ?>
        <!-- ==========================================
             DIRECT CODE CONFIRMATION DOCK (ONLY SHOWN IF ENABLED BY ESTATE)
             ========================================== -->
        <div class="resident-glass-panel p-3">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-user-check me-1"></i>Gate Arrival</span>
                <span class="fw-bold text-slate-900 small">Confirm Visitor Code Directly</span>
            </div>
            <form method="POST" class="d-flex flex-column flex-sm-row gap-2 align-items-sm-center">
                <div class="flex-grow-1">
                    <input type="text" name="confirm_code" placeholder="Enter guest pass code (e.g. EST-7K42P9)..." class="form-control text-uppercase font-monospace fw-bold" required>
                </div>
                <button type="submit" name="confirm_by_code" class="btn btn-success fw-semibold px-4 rounded-3 d-flex align-items-center justify-content-center gap-2">
                    <i class="fa-solid fa-check-double"></i> Confirm Arrival
                </button>
            </form>
        </div>
    <?php endif; ?>

    <!-- ==========================================
         REGISTRATION FORM & VISITOR LOG (2 COLS)
         ========================================== -->
    <div class="row g-4">
        <!-- Left: Pre-Registration Form -->
        <div class="col-12 col-lg-5">
            <div class="resident-glass-panel">
                <div class="resident-card-header">
                    <div class="resident-card-title">
                        <i class="fa-solid fa-user-plus text-primary"></i> Pre-Register New Visitor
                    </div>
                </div>
                <div class="resident-card-body">
                    <form method="POST" class="d-flex flex-column gap-3">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small fw-semibold">First Name</label>
                                <input type="text" name="first_name" class="form-control" placeholder="David" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Last Name</label>
                                <input type="text" name="last_name" class="form-control" placeholder="Johnson" required>
                            </div>
                        </div>
                        <div>
                            <label class="form-label small fw-semibold">Phone Number</label>
                            <input type="tel" name="phone" class="form-control" placeholder="08012345678" required>
                        </div>
                        <div>
                            <label class="form-label small fw-semibold">Visitor Email <small class="text-secondary">(Optional for digital slip)</small></label>
                            <input type="email" name="email" class="form-control" placeholder="visitor@example.com">
                        </div>
                        <div>
                            <label class="form-label small fw-semibold">Purpose of Visit</label>
                            <select name="purpose" class="form-select" required>
                                <option value="Personal Visit / Family">Personal Visit / Family</option>
                                <option value="Delivery / Logistics">Delivery / Logistics</option>
                                <option value="Maintenance / Repairs">Maintenance / Repairs</option>
                                <option value="Business / Meeting">Business / Meeting</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label small fw-semibold">Expected Arrival Date & Time</label>
                            <input type="datetime-local" name="expected_arrival" class="form-control" required value="<?= date('Y-m-d\TH:i') ?>">
                        </div>
                        <button type="submit" name="register_visitor" class="btn btn-primary w-100 py-2 mt-2 fw-semibold rounded-3 d-flex align-items-center justify-content-center gap-2">
                            <i class="fa-solid fa-qrcode"></i> Generate Gate Access Pass
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Right: Visitor Log History -->
        <div class="col-12 col-lg-7">
            <div class="resident-glass-panel">
                <div class="resident-card-header">
                    <div class="resident-card-title">
                        <i class="fa-solid fa-list-check text-primary"></i> Visitor Access Log & Clearance
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table dashboard-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Visitor / Pass</th>
                                <th>Entry</th>
                                <th>Exit</th>
                                <th>Status</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($visitors_res && $visitors_res->num_rows > 0): ?>
                                <?php while ($v = $visitors_res->fetch_assoc()): ?>
                                    <?php 
                                        $entry_fmt = $v['entry_time'] ? date('h:i A', strtotime($v['entry_time'])) : '-';
                                        $exit_fmt = $v['exit_time'] ? date('h:i A', strtotime($v['exit_time'])) : ($v['entry_time'] ? '<span class="mature-badge mature-badge-amber">Inside</span>' : '-');
                                        
                                        $st = $v['status'];
                                        $badge_class = 'mature-badge-slate';
                                        $st_label = str_replace('_', ' ', $st);
                                        if ($st == 'checked_out') { $badge_class = 'mature-badge-slate'; $st_label = 'Checked Out'; }
                                        elseif ($st == 'confirmed') { $badge_class = 'mature-badge-emerald'; $st_label = $req_confirm ? 'Confirmed' : 'Inside Estate'; }
                                        elseif ($st == 'entered') { $badge_class = 'mature-badge-amber'; $st_label = 'Inside Estate'; }
                                        elseif ($st == 'pre_registered') { $badge_class = 'mature-badge-sky'; $st_label = 'Pre-Registered'; }
                                        elseif ($st == 'cancelled') { $badge_class = 'mature-badge-crimson'; $st_label = 'Cancelled'; }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-slate-900"><?= htmlspecialchars($v['name']) ?></div>
                                            <span class="mature-badge mature-badge-sky font-monospace" style="font-size: 0.72rem;">
                                                <?= htmlspecialchars($v['visitor_code'] ?? 'N/A') ?>
                                            </span>
                                        </td>
                                        <td class="small text-secondary"><?= $entry_fmt ?></td>
                                        <td class="small text-secondary"><?= $exit_fmt ?></td>
                                        <td>
                                            <span class="mature-badge <?= $badge_class ?>">
                                                <?= htmlspecialchars($st_label) ?>
                                            </span>
                                        </td>
                                        <td style="text-align: right; white-space: nowrap;">
                                            <a href="gate_pass?id=<?= $v['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-2 py-1" title="Print Gate Pass">
                                                <i class="fa-solid fa-print"></i>
                                            </a>
                                            <?php if ($st == 'entered' || $st == 'pre_registered'): ?>
                                                <?php if ($req_confirm && !$v['resident_confirmed_at']): ?>
                                                    <a href="visitors?confirm_id=<?= $v['id'] ?>" class="btn btn-sm btn-success rounded-pill px-2 py-1" title="Confirm Visitor">
                                                        <i class="fa-solid fa-check"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ($st == 'pre_registered'): ?>
                                                    <a href="visitors?cancel_id=<?= $v['id'] ?>" data-confirm="Cancel this visitor pass?" data-confirm-title="Cancel Visitor Pass" class="btn btn-sm btn-outline-danger rounded-pill px-2 py-1" title="Cancel Pass">
                                                        <i class="fa-solid fa-xmark"></i>
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <button type="button" onclick="showDetails(<?= htmlspecialchars(json_encode([
                                                'id' => $v['id'],
                                                'code' => $v['visitor_code'],
                                                'name' => $v['name'],
                                                'entry' => $v['entry_time'] ? date('d M Y, h:i A', strtotime($v['entry_time'])) : 'Not entered',
                                                'confirmed' => $v['resident_confirmed_at'] ? date('d M Y, h:i A', strtotime($v['resident_confirmed_at'])) : (!$req_confirm ? 'Not Required' : 'No'),
                                                'exit' => $v['exit_time'] ? date('d M Y, h:i A', strtotime($v['exit_time'])) : ($v['entry_time'] ? 'Currently Inside' : 'Not exited'),
                                                'duration' => formatDuration($v['duration_minutes']),
                                                'reason' => $v['exit_reason'] ?? 'N/A',
                                                'status' => strtoupper(str_replace('_', ' ', $v['status']))
                                            ])) ?>)" class="btn btn-sm btn-light border rounded-circle" style="width: 28px; height: 28px; padding: 0;" title="View Details">
                                                <i class="fa-solid fa-circle-info text-secondary"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center py-4 text-secondary small">No visitor passes generated yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     FROSTED GLASS DETAILS AUDIT MODAL
     ========================================== -->
<div id="detailsModal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); z-index: 1050; align-items: center; justify-content: center; padding: 1.5rem 1rem;">
    <div class="resident-glass-panel" style="max-width: 480px; width: 100%; border-radius: 20px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); overflow: hidden;">
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center" style="background: rgba(255, 255, 255, 0.4);">
            <div class="d-flex align-items-center gap-2">
                <i class="fa-solid fa-id-card text-primary"></i>
                <span class="fw-bold text-slate-900">Visitor Clearance Audit</span>
            </div>
            <button type="button" onclick="closeDetails()" class="btn-close" aria-label="Close"></button>
        </div>
        <div class="p-4">
            <h5 class="fw-bold mb-1 text-slate-900" id="mName">Visitor Name</h5>
            <div class="font-monospace text-primary fw-bold mb-3" id="mCode">EST-XXXXXX</div>
            
            <div class="activity-feed">
                <div class="activity-feed-item">
                    <div class="activity-feed-dot" style="background: #3b82f6;"></div>
                    <div class="small text-secondary text-uppercase fw-semibold" style="font-size: 0.7rem;">Entry Timestamp</div>
                    <div class="fw-semibold text-slate-900" id="mEntry">-</div>
                </div>
                <?php if ($req_confirm): ?>
                <div class="activity-feed-item">
                    <div class="activity-feed-dot" style="background: #10b981;"></div>
                    <div class="small text-secondary text-uppercase fw-semibold" style="font-size: 0.7rem;">Host Resident Confirmation</div>
                    <div class="fw-semibold text-slate-900" id="mConfirmed">-</div>
                </div>
                <?php endif; ?>
                <div class="activity-feed-item">
                    <div class="activity-feed-dot" style="background: #f59e0b;"></div>
                    <div class="small text-secondary text-uppercase fw-semibold" style="font-size: 0.7rem;">Exit Timestamp</div>
                    <div class="fw-semibold text-slate-900" id="mExit">-</div>
                </div>
                <div class="activity-feed-item">
                    <div class="activity-feed-dot" style="background: #8b5cf6;"></div>
                    <div class="small text-secondary text-uppercase fw-semibold" style="font-size: 0.7rem;">Calculated Duration</div>
                    <div class="fw-semibold text-slate-900" id="mDuration">-</div>
                </div>
                <div class="activity-feed-item" id="mReasonRow" style="display:none;">
                    <div class="activity-feed-dot" style="background: #ef4444;"></div>
                    <div class="small text-secondary text-uppercase fw-semibold" style="font-size: 0.7rem;">Exit Reason</div>
                    <div class="fw-semibold text-danger" id="mReason">-</div>
                </div>
                <div class="activity-feed-item">
                    <div class="activity-feed-dot" style="background: #64748b;"></div>
                    <div class="small text-secondary text-uppercase fw-semibold" style="font-size: 0.7rem;">Clearance Status</div>
                    <div class="fw-semibold text-slate-900" id="mStatus">-</div>
                </div>
            </div>

            <div class="mt-4 pt-3 border-top d-flex gap-2 justify-content-end">
                <a id="modalPrintBtn" href="#" target="_blank" class="btn btn-sm btn-primary rounded-pill px-3">
                    <i class="fa-solid fa-print me-1"></i> Print Pass Slip
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function showDetails(data) {
    document.getElementById('mName').innerText = data.name;
    document.getElementById('mCode').innerText = 'Code: ' + (data.code || 'N/A');
    document.getElementById('mEntry').innerText = data.entry;
    if (document.getElementById('mConfirmed')) {
        document.getElementById('mConfirmed').innerText = data.confirmed;
    }
    document.getElementById('mExit').innerText = data.exit;
    document.getElementById('mDuration').innerText = data.duration;
    document.getElementById('mStatus').innerText = data.status;
    
    if (data.id) {
        document.getElementById('modalPrintBtn').href = 'gate_pass?id=' + data.id;
    } else if (data.code) {
        document.getElementById('modalPrintBtn').href = 'gate_pass?code=' + encodeURIComponent(data.code);
    }
    
    if (data.reason && data.reason !== 'N/A') {
        document.getElementById('mReasonRow').style.display = 'block';
        document.getElementById('mReason').innerText = data.reason;
    } else {
        document.getElementById('mReasonRow').style.display = 'none';
    }
    
    document.getElementById('detailsModal').style.display = 'flex';
}

function closeDetails() {
    document.getElementById('detailsModal').style.display = 'none';
}
</script>

<?php include 'footer.php'; ?>

