<?php
// resident/visitors.php
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index");
    exit;
}

$user_id = intval($_SESSION['user_id']);
$estate_id = get_estate_id();

// Fetch Resident Flat Link
$res_info = $conn->query("SELECT flat_id FROM residents WHERE user_id = $user_id AND estate_id = $estate_id ORDER BY id DESC LIMIT 1")->fetch_assoc();
$flat_id = $res_info['flat_id'] ?? null;

$message = "";
$error = "";
$new_pass = null;

// Pre-Register Visitor
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register_visitor'])) {
    $first_name = $conn->real_escape_string($_POST['first_name']);
    $last_name = $conn->real_escape_string($_POST['last_name']);
    $full_name = trim($first_name . ' ' . $last_name);
    $phone = $conn->real_escape_string($_POST['phone']);
    $purpose = $conn->real_escape_string($_POST['purpose']);
    $expected_arrival = $conn->real_escape_string($_POST['expected_arrival']);
    
    // Generate unique EST- Access Code (e.g. EST-7K42P9)
    $rand_str = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
    $visitor_code = 'EST-' . $rand_str;
    
    $sql = "INSERT INTO visitors (estate_id, resident_id, flat_id, first_name, last_name, name, phone, purpose, visitor_code, expected_arrival, status) 
            VALUES ($estate_id, $user_id, " . ($flat_id ? $flat_id : "NULL") . ", '$first_name', '$last_name', '$full_name', '$phone', '$purpose', '$visitor_code', '$expected_arrival', 'pre_registered')";
    
    if ($conn->query($sql)) {
        $inserted_pass_id = $conn->insert_id;
        logAudit($conn, "Visitor Pre-registered", "Visitors", "Visitor $full_name pre-registered with code: $visitor_code");
        $message = "Visitor access pass created successfully!";
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
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 font-bold text-slate-800 m-0"><i class="fa-solid fa-id-card-clip text-primary me-2"></i> Visitor Access Passes</h2>
        <p class="text-secondary small mb-0">Pre-register visitors and manage gate access codes.</p>
    </div>
</div>

<style>
    .grid-2 { display: grid; grid-template-columns: 1fr 2fr; gap: 2rem; }
    @media (max-width: 850px) { .grid-2 { grid-template-columns: 1fr; } }

    .card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1.5rem; }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 1rem; }
    .card-title { font-family: 'Outfit', sans-serif; font-size: 1.15rem; font-weight: 700; }

    label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; color: #475569; }
    input, select { width: 100%; padding: 0.65rem; border: 1px solid #e2e8f0; border-radius: 0.5rem; margin-bottom: 1rem; font-family: inherit; font-size: 0.95rem; }

    .btn-submit { width: 100%; padding: 0.75rem; background: #3b82f6; color: white; border: none; border-radius: 0.5rem; font-weight: 600; cursor: pointer; transition: background 0.2s; }
    .btn-submit:hover { background: #2563eb; }

    .btn-confirm { padding: 0.4rem 0.75rem; background: #10b981; color: white; border: none; border-radius: 0.375rem; font-weight: 600; text-decoration: none; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.25rem; transition: background 0.2s; }
    .btn-confirm:hover { background: #059669; }

    .badge-pre_registered { background: #e0f2fe; color: #0369a1; }
    .badge-entered { background: #fef3c7; color: #d97706; }
    .badge-confirmed { background: #dcfce7; color: #15803d; }
    .badge-checked_out { background: #f1f5f9; color: #475569; }
    .badge-exited_without_confirmation { background: #ffedd5; color: #c2410c; }
    .badge-cancelled { background: #fee2e2; color: #b91c1c; }

    .pass-card { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: white; border-radius: 1rem; padding: 1.5rem; text-align: center; margin-bottom: 1.5rem; border: 2px solid #3b82f6; }
    .pass-code { font-family: monospace; font-size: 2.5rem; font-weight: 700; letter-spacing: 0.1em; color: #38bdf8; margin: 0.5rem 0; }

    .confirm-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 0.75rem; padding: 1rem; margin-bottom: 1.5rem; display: flex; gap: 1rem; align-items: flex-end; }
    .confirm-box input { margin-bottom: 0; background: white; }
    .confirm-box button { margin-bottom: 0; white-space: nowrap; width: auto; padding: 0.65rem 1.25rem; }

    /* Modal styling */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; }
    .modal-card { background: white; width: 100%; max-width: 500px; border-radius: 1rem; padding: 1.5rem; position: relative; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
    .modal-close { position: absolute; top: 1rem; right: 1rem; background: none; border: none; font-size: 1.25rem; color: #94a3b8; cursor: pointer; }
    
    .history-timeline { display: flex; flex-direction: column; gap: 1rem; margin-top: 1rem; font-size: 0.9rem; }
    .timeline-item { border-left: 2px solid #cbd5e1; padding-left: 1rem; position: relative; }
    .timeline-item::before { content: ''; position: absolute; left: -5px; top: 4px; width: 8px; height: 8px; border-radius: 50%; background: #3b82f6; }
    .timeline-title { font-weight: 600; color: #64748b; font-size: 0.8rem; text-transform: uppercase; }
    .timeline-val { font-weight: 700; color: #0f172a; font-size: 1rem; }
</style>

<div class="d-flex flex-column gap-4">


        <?php if ($message): ?>
            <div style="background: #dcfce7; color: #15803d; padding: 1rem; border-radius: 0.5rem; font-weight: 600;">
                <i class="fa-solid fa-circle-check" style="margin-right: 6px;"></i> <?= $message ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div style="background: #fee2e2; color: #b91c1c; padding: 1rem; border-radius: 0.5rem; font-weight: 600;">
                <i class="fa-solid fa-circle-exclamation" style="margin-right: 6px;"></i> <?= $error ?>
            </div>
        <?php endif; ?>

        <?php if ($new_pass): ?>
            <div class="pass-card">
                <div style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.1em; color: #94a3b8;">Visitor Access Pass Code</div>
                <div class="pass-code"><?= $new_pass['code'] ?></div>
                <div style="font-weight: 600; font-size: 1.15rem; margin-bottom: 0.25rem;"><?= htmlspecialchars($new_pass['name']) ?></div>
                <div style="font-size: 0.85rem; color: #cbd5e1;">Expected Arrival: <?= date('M j, Y h:i A', strtotime($new_pass['arrival'])) ?></div>
                
                <div style="display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap; margin-top: 1.25rem;">
                    <a href="gate_pass?id=<?= $new_pass['id'] ?>" target="_blank" class="btn-submit" style="width: auto; padding: 0.6rem 1.25rem; background: #2563eb; display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                        <i class="fa-solid fa-print"></i> Print Gate Pass Slip
                    </a>
                    <a href="gate_pass?id=<?= $new_pass['id'] ?>&autoprint=1" target="_blank" class="btn-submit" style="width: auto; padding: 0.6rem 1.25rem; background: #0284c7; display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                        <i class="fa-solid fa-file-invoice"></i> Fast Print
                    </a>
                    <button type="button" onclick="navigator.clipboard.writeText('<?= $new_pass['code'] ?>'); alert('Pass Code <?= $new_pass['code'] ?> copied!');" class="btn-submit" style="width: auto; padding: 0.6rem 1.25rem; background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-copy"></i> Copy Code
                    </button>
                </div>
                <div style="font-size: 0.8rem; color: #94a3b8; margin-top: 0.85rem;">You can print this official pass slip to hand to your guest, or share the pass code for gate entry.</div>
            </div>
        <?php endif; ?>

        <!-- Quick Code Confirmation Box -->
        <div class="card" style="background: #f8fafc; border: 1px dashed var(--primary);">
            <div style="font-weight: 700; font-size: 1rem; margin-bottom: 0.5rem; color: #1e293b; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-user-check" style="color: #10b981;"></i> Confirm Visitor Code Directly
            </div>
            <form method="POST" class="confirm-box">
                <div style="flex: 1;">
                    <label>Visitor Code</label>
                    <input type="text" name="confirm_code" placeholder="e.g. EST-7K42P9" style="text-transform: uppercase;" required>
                </div>
                <button type="submit" name="confirm_by_code" class="btn-submit" style="background: #10b981;">
                    <i class="fa-solid fa-check-double"></i> Confirm Visitor
                </button>
            </form>
        </div>

        <div class="grid-2">
            <!-- Form Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-user-plus" style="color: var(--primary); margin-right: 8px;"></i> Pre-Register Visitor</div>
                </div>
                <form method="POST">
                    <div>
                        <label>Visitor First Name</label>
                        <input type="text" name="first_name" placeholder="e.g. David" required>
                    </div>
                    <div>
                        <label>Visitor Last Name</label>
                        <input type="text" name="last_name" placeholder="e.g. Johnson" required>
                    </div>
                    <div>
                        <label>Phone Number</label>
                        <input type="tel" name="phone" placeholder="e.g. 08012345678" required>
                    </div>
                    <div>
                        <label>Purpose of Visit</label>
                        <select name="purpose" required>
                            <option value="Personal Visit / Family">Personal Visit / Family</option>
                            <option value="Delivery / Logistics">Delivery / Logistics</option>
                            <option value="Maintenance / Repairs">Maintenance / Repairs</option>
                            <option value="Business / Meeting">Business / Meeting</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label>Expected Arrival Date & Time</label>
                        <input type="datetime-local" name="expected_arrival" required value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                    <button type="submit" name="register_visitor" class="btn-submit"><i class="fa-solid fa-qrcode"></i> Generate Access Pass Code</button>
                </form>
            </div>

            <!-- History Table -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-list-check" style="color: #10b981; margin-right: 8px;"></i> Visitor Log & History</div>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Visitor</th>
                                <th>Entry</th>
                                <th>Confirmed</th>
                                <th>Exit</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($visitors_res && $visitors_res->num_rows > 0): ?>
                                <?php while ($v = $visitors_res->fetch_assoc()): ?>
                                    <?php 
                                        $entry_fmt = $v['entry_time'] ? date('h:i A', strtotime($v['entry_time'])) : '-';
                                        $conf_fmt = $v['resident_confirmed_at'] ? date('h:i A', strtotime($v['resident_confirmed_at'])) : '-';
                                        $exit_fmt = $v['exit_time'] ? date('h:i A', strtotime($v['exit_time'])) : ($v['entry_time'] ? '<span style="color:#eab308; font-weight:600;">Inside</span>' : '-');
                                        $duration_fmt = formatDuration($v['duration_minutes']);
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 700; color: #1e293b;"><?= htmlspecialchars($v['name']) ?></div>
                                            <div style="font-family: monospace; font-weight: 700; color: #0284c7; font-size: 0.85rem;"><?= htmlspecialchars($v['visitor_code'] ?? 'N/A') ?></div>
                                        </td>
                                        <td><?= $entry_fmt ?></td>
                                        <td><?= $conf_fmt ?></td>
                                        <td><?= $exit_fmt ?></td>
                                        <td style="font-weight: 700; color: #0f172a;"><?= $duration_fmt ?></td>
                                        <td>
                                            <?php 
                                                $st = $v['status'];
                                                $st_label = str_replace('_', ' ', $st);
                                                if ($st == 'checked_out') $st_label = 'Checked Out';
                                                elseif ($st == 'exited_without_confirmation') $st_label = 'Exited Without Confirmation';
                                                elseif ($st == 'confirmed') $st_label = 'Confirmed';
                                                elseif ($st == 'entered') $st_label = 'Inside Estate';
                                            ?>
                                            <span class="badge badge-<?= $st ?>"><?= htmlspecialchars($st_label) ?></span>
                                        </td>
                                        <td style="text-align: right; white-space: nowrap;">
                                            <a href="gate_pass?id=<?= $v['id'] ?>" target="_blank" class="btn-confirm" style="background: #2563eb; margin-right: 4px;" title="Print Gate Pass for Guest">
                                                <i class="fa-solid fa-print"></i> Pass
                                            </a>
                                            <?php if ($st == 'entered' || $st == 'pre_registered'): ?>
                                                <?php if (!$v['resident_confirmed_at']): ?>
                                                    <a href="visitors?confirm_id=<?= $v['id'] ?>" class="btn-confirm" title="Confirm Visitor">
                                                        <i class="fa-solid fa-check"></i> Confirm
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ($st == 'pre_registered'): ?>
                                                    <a href="visitors?cancel_id=<?= $v['id'] ?>" onclick="return confirm('Cancel this visitor pass?');" style="color: #ef4444; font-weight: 600; text-decoration: none; font-size: 0.8rem; margin-left: 6px;">Cancel</a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <button onclick="showDetails(<?= htmlspecialchars(json_encode([
                                                'id' => $v['id'],
                                                'code' => $v['visitor_code'],
                                                'name' => $v['name'],
                                                'entry' => $v['entry_time'] ? date('d M Y, h:i A', strtotime($v['entry_time'])) : 'Not entered',
                                                'confirmed' => $v['resident_confirmed_at'] ? date('d M Y, h:i A', strtotime($v['resident_confirmed_at'])) : 'No',
                                                'exit' => $v['exit_time'] ? date('d M Y, h:i A', strtotime($v['exit_time'])) : ($v['entry_time'] ? 'Currently Inside' : 'Not exited'),
                                                'duration' => formatDuration($v['duration_minutes']),
                                                'reason' => $v['exit_reason'] ?? 'N/A',
                                                'status' => strtoupper(str_replace('_', ' ', $v['status']))
                                            ])) ?>)" style="background: none; border: none; color: #64748b; cursor: pointer; font-size: 0.9rem; margin-left: 6px;" title="View Details">
                                                <i class="fa-solid fa-circle-info"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2rem;">No visitor passes generated yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Details Modal -->
    <div id="detailsModal" class="modal-overlay">
        <div class="modal-card">
            <button class="modal-close" onclick="closeDetails()">&times;</button>
            <div style="font-family: 'Outfit', sans-serif; font-size: 1.2rem; font-weight: 700; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem; margin-bottom: 1rem;">
                <i class="fa-solid fa-id-card" style="color: var(--primary);"></i> Visitor History Audit
            </div>
            
            <div style="font-weight: 700; font-size: 1.2rem; color: #1e293b;" id="mName">Visitor Name</div>
            <div style="font-family: monospace; font-size: 1.1rem; color: #0284c7; font-weight: 700;" id="mCode">EST-XXXXXX</div>
            
            <div class="history-timeline">
                <div class="timeline-item">
                    <div class="timeline-title">Entry Time</div>
                    <div class="timeline-val" id="mEntry">-</div>
                </div>
                <div class="timeline-item">
                    <div class="timeline-title">Resident Confirmation</div>
                    <div class="timeline-val" id="mConfirmed">-</div>
                </div>
                <div class="timeline-item">
                    <div class="timeline-title">Exit Time</div>
                    <div class="timeline-val" id="mExit">-</div>
                </div>
                <div class="timeline-item">
                    <div class="timeline-title">Visit Duration</div>
                    <div class="timeline-val" id="mDuration" style="color: #3b82f6;">-</div>
                </div>
                <div class="timeline-item" id="mReasonRow">
                    <div class="timeline-title">Exit Reason (Unconfirmed Exit)</div>
                    <div class="timeline-val" id="mReason" style="color: #c2410c;">-</div>
                </div>
                <div class="timeline-item">
                    <div class="timeline-title">Status</div>
                    <div class="timeline-val" id="mStatus">-</div>
                </div>
            </div>

            <div style="margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid #e2e8f0; display: flex; gap: 0.5rem; justify-content: flex-end;">
                <a id="modalPrintBtn" href="#" target="_blank" class="btn-confirm" style="background: #2563eb; padding: 0.55rem 1.1rem; font-size: 0.85rem;">
                    <i class="fa-solid fa-print"></i> Print Gate Pass Slip
                </a>
            </div>
        </div>
    </div>

    <script>
        function showDetails(data) {
            document.getElementById('mName').innerText = data.name;
            document.getElementById('mCode').innerText = 'Code: ' + (data.code || 'N/A');
            document.getElementById('mEntry').innerText = data.entry;
            document.getElementById('mConfirmed').innerText = data.confirmed;
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

