<?php
// admin/maintenance.php
require_once '../config.php';
require_once '../includes/auth_guard.php';
requireAdminAccess();

include '../includes/header.php';
include '../includes/sidebar.php';

// Handle Maintenance Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_maintenance'])) {
    $id = intval($_POST['request_id']);
    $status = $conn->real_escape_string($_POST['status']);
    $admin_report = $conn->real_escape_string($_POST['admin_report']);
    $user_id = intval($_SESSION['user_id']);
    
    $estate_id = get_estate_id();
    $sql = "UPDATE maintenance_requests 
            SET status = '$status', 
                admin_report = '$admin_report', 
                attended_by = $user_id, 
                attended_at = NOW() 
            WHERE id = $id AND estate_id = $estate_id";
    if ($conn->query($sql)) {
        $staff_info = $conn->query("SELECT name, role FROM users WHERE id = $user_id LIMIT 1")->fetch_assoc();
        $staff_name = $staff_info['name'] ?? 'Staff';
        $staff_role = ucfirst($staff_info['role'] ?? 'Staff');
        logAudit($conn, "Resident Need Attended", "Maintenance", "Need/Request #MR-" . sprintf("%04d", $id) . " updated to '$status' by Staff $staff_name ($staff_role)");
        $_SESSION['success_msg'] = "Maintenance record updated successfully by $staff_name.";
    } else {
        $_SESSION['error_msg'] = "Error updating record: " . $conn->error;
    }
    header("Location: maintenance");
    exit;
}

// Fetch messages from session
$success = $_SESSION['success_msg'] ?? '';
$error = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// Fetch Report Log (All with resident and attending staff info)
$estate_id = get_estate_id();
$report_log = $conn->query("SELECT m.*, u.name as resident_name, f.number as flat_number, b.name as building_name,
                                   u_att.name as attended_staff_name, u_att.role as attended_staff_role
                            FROM maintenance_requests m 
                            JOIN users u ON m.user_id = u.id 
                            LEFT JOIN flats f ON m.flat_id = f.id 
                            LEFT JOIN buildings b ON f.building_id = b.id
                            LEFT JOIN users u_att ON m.attended_by = u_att.id
                            WHERE m.estate_id = $estate_id
                            ORDER BY m.created_at DESC");

// Fetch Fixes Log (Specifically resolved/closed with reports and attending staff info)
$fixes_log = $conn->query("SELECT m.*, u.name as resident_name, f.number as flat_number, b.name as building_name,
                                  u_att.name as attended_staff_name, u_att.role as attended_staff_role
                          FROM maintenance_requests m 
                          JOIN users u ON m.user_id = u.id 
                          LEFT JOIN flats f ON m.flat_id = f.id 
                          LEFT JOIN buildings b ON f.building_id = b.id
                          LEFT JOIN users u_att ON m.attended_by = u_att.id
                          WHERE m.estate_id = $estate_id AND m.status IN ('resolved', 'closed')
                          ORDER BY m.created_at DESC");
?>

<style>
    .tab-btn {
        padding: 0.75rem 1.5rem;
        cursor: pointer;
        border: none;
        background: none;
        font-weight: 600;
        color: #64748b;
        border-bottom: 3px solid transparent;
        transition: all 0.2s;
    }
    .tab-btn.active {
        color: #3b82f6;
        border-bottom-color: #3b82f6;
    }
    .status-badge {
        padding: 4px 10px;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    .priority-dot {
        height: 10px;
        width: 10px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 5px;
    }
</style>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <h1>Maintenance Management</h1>
    <a href="../resident/report_issue" target="_blank" class="btn btn-primary" style="background:#3b82f6;color:#fff;padding:10px 15px;border-radius:5px;text-decoration:none;font-weight:600;box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.4);">+ New Request</a>
</div>

<?php if ($success): ?>
    <div style="background: #dcfce7; color: #166534; padding: 1rem; border-radius: 0.5rem; border: 1px solid #bbf7d0; margin-bottom: 1.5rem;"><?= $success ?></div>
<?php endif; ?>

<div class="glass" style="padding: 0; border-radius: 1rem; background: rgba(255,255,255,0.9); overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
    <!-- Tab Controls -->
    <div style="display: flex; border-bottom: 1px solid #e2e8f0; background: #f8fafc; padding: 0 1rem;">
        <button class="tab-btn active" onclick="switchTab(event, 'reportLog')">Reporting Log (Residents)</button>
        <button class="tab-btn" onclick="switchTab(event, 'fixesLog')">Fixes Log (Admin Reports)</button>
    </div>

    <!-- Reporting Log Content -->
    <div id="reportLog" class="tab-content" style="padding: 1.5rem;">
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="text-align: left; color: #64748b; border-bottom: 2px solid #f1f5f9;">
                        <th style="padding: 12px;">Ref</th>
                        <th style="padding: 12px;">Resident</th>
                        <th style="padding: 12px;">Title</th>
                        <th style="padding: 12px;">Priority</th>
                        <th style="padding: 12px;">Status</th>
                        <th style="padding: 12px;">Attended By</th>
                        <th style="padding: 12px;">Date & Time</th>
                        <th style="padding: 12px; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $report_log->fetch_assoc()): ?>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 12px; font-weight: 600;">#MR-<?= sprintf("%04d", $row['id']) ?></td>
                        <td style="padding: 12px;">
                            <div style="font-weight: 500; font-size: 0.95rem;"><?= htmlspecialchars($row['resident_name']) ?></div>
                            <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($row['building_name']) ?> (Flat <?= htmlspecialchars($row['flat_number']) ?>)</div>
                        </td>
                        <td style="padding: 12px; font-size: 0.9rem; color: #334155;"><?= htmlspecialchars($row['title']) ?></td>
                        <td style="padding: 12px;">
                            <?php
                            $p_color = ['low'=>'#10b981', 'medium'=>'#f59e0b', 'high'=>'#ef4444', 'emergency'=>'#7f1d1d'];
                            ?>
                            <span class="priority-dot" style="background: <?= $p_color[$row['priority']] ?>"></span>
                            <span style="font-size: 0.85rem; text-transform: capitalize;"><?= $row['priority'] ?></span>
                        </td>
                        <td style="padding: 12px;">
                            <span class="status-badge" style="background: <?= $row['status'] == 'open' ? '#fee2e2; color: #991b1b;' : ($row['status'] == 'resolved' ? '#dcfce7; color: #166534;' : '#fef3c7; color: #92400e;') ?>">
                                <?= $row['status'] ?>
                            </span>
                        </td>
                        <td style="padding: 12px;">
                            <?php if (!empty($row['attended_staff_name'])): ?>
                                <div style="font-weight: 600; font-size: 0.85rem; color: #1e293b;">
                                    <i class="fa-solid fa-user-check text-primary me-1"></i><?= htmlspecialchars($row['attended_staff_name']) ?>
                                </div>
                                <div style="font-size: 0.72rem; color: #64748b;">
                                    <?= ucfirst($row['attended_staff_role'] ?? 'Staff') ?>
                                    <?= $row['attended_at'] ? '&bull; ' . date('M j, g:i A', strtotime($row['attended_at'])) : '' ?>
                                </div>
                            <?php else: ?>
                                <span style="color: #94a3b8; font-size: 0.78rem; font-style: italic;">Pending / Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px;">
                            <div style="font-size: 0.85rem; color: #1e293b; font-weight: 500;"><?= date('M j, Y', strtotime($row['created_at'])) ?></div>
                            <div style="font-size: 0.75rem; color: #64748b;"><?= date('g:i A', strtotime($row['created_at'])) ?></div>
                        </td>
                        <td style="padding: 12px; text-align: right;">
                            <button onclick="openUpdateModal(<?= htmlspecialchars(json_encode($row)) ?>)" style="background:none; border:none; color:#3b82f6; cursor:pointer; font-weight:600;">Update</button>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Fixes Log Content -->
    <div id="fixesLog" class="tab-content" style="display: none; padding: 1.5rem;">
        <div style="display: grid; grid-template-columns: 1fr; gap: 1.5rem;">
            <?php if($fixes_log->num_rows > 0): ?>
                <?php while($row = $fixes_log->fetch_assoc()): ?>
                <div style="background: #f8fafc; border-radius: 0.75rem; border: 1px solid #e2e8f0; padding: 1.5rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 1rem; align-items: flex-start;">
                        <div>
                            <h3 style="margin: 0; font-size: 1.1rem;"><?= htmlspecialchars($row['title']) ?></h3>
                            <div style="font-size: 0.85rem; color: #64748b;">Resolved for <?= htmlspecialchars($row['resident_name']) ?> in <?= htmlspecialchars($row['building_name']) ?> - <?= htmlspecialchars($row['flat_number']) ?></div>
                        </div>
                        <span class="status-badge" style="background: #dcfce7; color: #166534; font-size: 0.7rem;">COMPLETED</span>
                    </div>
                    <div style="background: white; border-radius: 0.5rem; padding: 1rem; border: 1px solid #f1f5f9;">
                        <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; margin-bottom: 0.5rem; font-weight: 700;">Admin Fix Report</div>
                        <p style="margin: 0; color: #1e293b; font-size: 0.95rem; line-height: 1.5;"><?= nl2br(htmlspecialchars($row['admin_report'] ?? 'No formal report written.')) ?></p>
                    </div>
                    <div style="margin-top: 1rem; font-size: 0.8rem; color: #64748b; display: flex; justify-content: space-between; border-top: 1px solid #f1f5f9; padding-top: 1rem;">
                        <span>Ref: #MR-<?= sprintf("%04d", $row['id']) ?></span>
                        <span>
                            <?php if (!empty($row['attended_staff_name'])): ?>
                                <span style="color:#0f172a; font-weight:600;"><i class="fa-solid fa-user-shield text-success me-1"></i> Attended by:</span> <?= htmlspecialchars($row['attended_staff_name']) ?> (<?= ucfirst($row['attended_staff_role'] ?? 'Staff') ?>) on <?= date('M j, Y g:i A', strtotime($row['attended_at'] ?? $row['created_at'])) ?>
                            <?php else: ?>
                                Reported On: <?= date('M j, Y', strtotime($row['created_at'])) ?> at <?= date('g:i A', strtotime($row['created_at'])) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="text-align: center; color: #94a3b8; padding: 3rem;">No resolved requests in the fixes log.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal -->
<div id="updateModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15, 23, 42, 0.6); z-index:1000; align-items:center; justify-content:center; backdrop-filter: blur(4px);">
    <div style="background:#fff; padding:2.5rem; border-radius:1rem; width:100%; max-width: 500px; margin: 5vh auto; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);">
        <h2 style="margin-top:0; margin-bottom:0.5rem; color: #1e293b;">Update Maintenance Request</h2>
        <p id="modalRef" style="color:#64748b; margin-bottom: 1.5rem; font-weight: 500;"></p>
        
        <form method="POST">
            <input type="hidden" name="request_id" id="modalId">
            <div style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom:0.5rem; font-weight: 600;">Status</label>
                <select name="status" id="modalStatus" style="width:100%; padding:0.75rem; border-radius:0.5rem; border:1px solid #cbd5e1; font-family:inherit;">
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="resolved">Resolved (Completed)</option>
                    <option value="closed">Closed</option>
                </select>
            </div>
            <div style="margin-bottom: 2rem;">
                <label style="display:block; margin-bottom:0.5rem; font-weight: 600;">Admin Fix Report</label>
                <textarea name="admin_report" id="modalReport" rows="6" placeholder="Write details about the fix performed..." style="width:100%; padding:0.75rem; border-radius:0.5rem; border:1px solid #cbd5e1; font-family:inherit; outline: none;"></textarea>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" onclick="document.getElementById('updateModal').style.display='none'" style="padding:0.75rem 1.5rem; border:none; background:#f1f5f9; color: #475569; border-radius:0.5rem; cursor:pointer; font-weight:600;">Cancel</button>
                <button type="submit" name="update_maintenance" style="padding:0.75rem 1.5rem; border:none; background:#3b82f6; color:#fff; border-radius:0.5rem; cursor:pointer; font-weight:600; box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.4);">Save Update</button>
            </div>
        </form>
    </div>
</div>

<script>
function switchTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName("tab-content");
    for (i = 0; i < tabcontent.length; i++) {
        tabcontent[i].style.display = "none";
    }
    tablinks = document.getElementsByClassName("tab-btn");
    for (i = 0; i < tablinks.length; i++) {
        tablinks[i].className = tablinks[i].className.replace(" active", "");
    }
    document.getElementById(tabName).style.display = "block";
    evt.currentTarget.className += " active";
}

function openUpdateModal(data) {
    document.getElementById('modalId').value = data.id;
    document.getElementById('modalRef').innerText = "#MR-" + data.id.toString().padStart(4, '0') + " : " + data.title;
    document.getElementById('modalStatus').value = data.status;
    document.getElementById('modalReport').value = data.admin_report || "";
    document.getElementById('updateModal').style.display = 'flex';
}
</script>

<?php include '../includes/footer.php'; ?>
