<?php
// staff/maintenance.php
include 'header.php';
include 'sidebar.php';

requirePermission('maintenance.view_assigned');

$estate_id = get_estate_id();
$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_ticket'])) {
    if (!verifyCSRFToken()) {
        $error = "CSRF Verification failed.";
    } else {
        $ticket_id = intval($_POST['ticket_id']);
        $new_status = $conn->real_escape_string($_POST['status']);
        $notes = $conn->real_escape_string($_POST['admin_report']);

        $staff_user = $conn->query("SELECT name, role FROM users WHERE id = $user_id LIMIT 1")->fetch_assoc();
        $staff_name = $staff_user['name'] ?? 'Staff';
        $staff_role = ucfirst($staff_user['role'] ?? 'Staff');

        $sql = "UPDATE maintenance_requests 
                SET status = '$new_status', 
                    admin_report = '$notes',
                    attended_by = $user_id,
                    attended_at = NOW() 
                WHERE id = $ticket_id AND estate_id = $estate_id";
        if ($conn->query($sql)) {
            logAudit($conn, "Resident Need Attended", "Maintenance", "Need/Request #MR-" . sprintf("%04d", $ticket_id) . " updated to '$new_status' by $staff_name ($staff_role).");
            $message = "Work order #$ticket_id status updated successfully!";
        } else {
            $error = "Error updating work order: " . $conn->error;
        }
    }
}

// Fetch Assigned Tickets for logged in technician/staff
$tickets = $conn->query("SELECT m.*, u.name as requester_name, u.phone as requester_phone, f.number as flat_number, b.name as building_name 
    FROM maintenance_requests m 
    LEFT JOIN users u ON m.user_id = u.id 
    LEFT JOIN flats f ON m.flat_id = f.id 
    LEFT JOIN buildings b ON f.building_id = b.id 
    WHERE m.estate_id = $estate_id AND (m.assigned_to = $user_id OR 'admin' = '" . ($_SESSION['role'] ?? '') . "') 
    ORDER BY FIELD(m.status, 'open', 'in_progress', 'resolved', 'closed'), m.id DESC");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 font-bold text-slate-800 m-0"><i class="fa-solid fa-wrench me-2 text-warning"></i> Assigned Field Work Orders</h2>
        <p class="text-secondary small mb-0">View and update maintenance tickets assigned to your staff profile.</p>
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

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 border-0">
        <h5 class="card-title fw-bold m-0 text-slate-800"><i class="fa-solid fa-list-check me-2 text-teal"></i> Work Orders List</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table align-middle table-hover mb-0">
                <thead class="bg-light text-secondary small text-uppercase">
                    <tr>
                        <th>Ticket ID</th>
                        <th>Issue / Priority</th>
                        <th>Location & Requester</th>
                        <th>Status</th>
                        <th>Created Date</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($tickets && $tickets->num_rows > 0): ?>
                        <?php while ($t = $tickets->fetch_assoc()): ?>
                            <tr>
                                <td><span class="badge bg-dark">#<?php echo $t['id']; ?></span></td>
                                <td>
                                    <div class="fw-bold text-slate-800"><?php echo htmlspecialchars($t['title']); ?></div>
                                    <small class="text-muted text-truncate d-block" style="max-width: 250px;"><?php echo htmlspecialchars($t['description'] ?? ''); ?></small>
                                    <span class="badge <?php 
                                        echo ($t['priority'] === 'emergency') ? 'bg-danger' : (($t['priority'] === 'high') ? 'bg-warning text-dark' : 'bg-info text-dark'); 
                                    ?> mt-1">
                                        <?php echo strtoupper($t['priority']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($t['requester_name'] ?? 'Resident'); ?></div>
                                    <small class="text-muted">Flat <?php echo htmlspecialchars($t['flat_number'] ?? 'N/A'); ?> (<?php echo htmlspecialchars($t['building_name'] ?? ''); ?>)</small>
                                    <small class="d-block text-muted"><?php echo htmlspecialchars($t['requester_phone'] ?? ''); ?></small>
                                </td>
                                <td>
                                    <?php 
                                    $st = $t['status'];
                                    if ($st === 'open') echo '<span class="badge bg-danger">Open</span>';
                                    elseif ($st === 'in_progress') echo '<span class="badge bg-warning text-dark">In Progress</span>';
                                    elseif ($st === 'resolved') echo '<span class="badge bg-success">Resolved</span>';
                                    else echo '<span class="badge bg-secondary">Closed</span>';
                                    ?>
                                </td>
                                <td><small class="text-muted"><?php echo date('d M Y, h:i A', strtotime($t['created_at'])); ?></small></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#updateModal<?php echo $t['id']; ?>">
                                        <i class="fa-solid fa-pen-to-square me-1"></i> Update Status
                                    </button>

                                    <!-- Update Ticket Modal -->
                                    <div class="modal fade text-start" id="updateModal<?php echo $t['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form action="maintenance" method="POST">
                                                    <?php echo renderCSRFField(); ?>
                                                    <input type="hidden" name="ticket_id" value="<?php echo $t['id']; ?>">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title fw-bold">Update Work Order #<?php echo $t['id']; ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold">Status</label>
                                                            <select name="status" class="form-select">
                                                                <option value="open" <?php if($t['status'] == 'open') echo 'selected'; ?>>Open</option>
                                                                <option value="in_progress" <?php if($t['status'] == 'in_progress') echo 'selected'; ?>>In Progress</option>
                                                                <option value="resolved" <?php if($t['status'] == 'resolved') echo 'selected'; ?>>Resolved</option>
                                                                <option value="closed" <?php if($t['status'] == 'closed') echo 'selected'; ?>>Closed</option>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-semibold">Technician Notes / Repair Report</label>
                                                            <textarea name="admin_report" class="form-control" rows="3" placeholder="Describe repair actions taken..."><?php echo htmlspecialchars($t['admin_report'] ?? ''); ?></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                        <button type="submit" name="update_ticket" class="btn btn-primary">Save Changes</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">No maintenance work orders assigned to you.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
