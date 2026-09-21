<?php
// resident/report_issue.php
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index");
    exit;
}

$user_id = intval($_SESSION['user_id']);
$estate_id = get_estate_id();
$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $title = $conn->real_escape_string($_POST['title']);
    $description = $conn->real_escape_string($_POST['description']);
    $priority = $conn->real_escape_string($_POST['priority']);
    $flat_id = isset($_POST['flat_id']) && !empty($_POST['flat_id']) ? intval($_POST['flat_id']) : null;
    
    $sql = "INSERT INTO maintenance_requests (estate_id, user_id, flat_id, title, description, priority, status) 
            VALUES ($estate_id, '$user_id', " . ($flat_id ? "'$flat_id'" : "NULL") . ", '$title', '$description', '$priority', 'open')";
    
    if ($conn->query($sql) === TRUE) {
        $_SESSION['success_msg'] = "Maintenance ticket created successfully. Our facility dispatch team has been notified.";
        if (function_exists('logAudit')) {
            logAudit($conn, "Maintenance Request Created", "Maintenance", "Resident submitted ticket: $title");
        }
    } else {
        $_SESSION['error_msg'] = "Unable to process request: " . $conn->error;
    }
    header("Location: report_issue");
    exit;
}

// Fetch messages
$message = $_SESSION['success_msg'] ?? '';
$error = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// Fetch resident's assigned flat first, then fallback to estate flats
$user_flat = $conn->query("SELECT f.id, f.number, b.name as building_name 
                           FROM residents r
                           JOIN flats f ON r.flat_id = f.id
                           JOIN buildings b ON f.building_id = b.id
                           WHERE r.user_id = $user_id AND r.estate_id = $estate_id 
                           LIMIT 1")->fetch_assoc();

$flats = $conn->query("SELECT f.id, f.number, b.name as building_name 
                      FROM flats f 
                      JOIN buildings b ON f.building_id = b.id 
                      WHERE f.estate_id = $estate_id
                      ORDER BY b.name, f.number");

// Fetch resident's submitted maintenance requests
$my_requests_res = $conn->query("SELECT m.*, f.number as flat_number, b.name as building_name 
                                  FROM maintenance_requests m 
                                  LEFT JOIN flats f ON m.flat_id = f.id 
                                  LEFT JOIN buildings b ON f.building_id = b.id 
                                  WHERE m.user_id = $user_id AND m.estate_id = $estate_id 
                                  ORDER BY m.created_at DESC");

$requests_list = [];
$total_tickets = 0;
$pending_count = 0;
$inprogress_count = 0;
$resolved_count = 0;

if ($my_requests_res) {
    while ($row = $my_requests_res->fetch_assoc()) {
        $requests_list[] = $row;
        $total_tickets++;
        $st = strtolower($row['status'] ?? 'open');
        if (in_array($st, ['resolved', 'completed', 'closed'])) {
            $resolved_count++;
        } elseif ($st === 'in_progress') {
            $inprogress_count++;
        } else {
            $pending_count++;
        }
    }
}

include 'header.php';
include 'sidebar.php';
?>

<!-- Header Banner -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="mature-badge mature-badge-primary">
                <i class="fa-solid fa-screwdriver-wrench"></i> Facility Services
            </span>
            <span class="text-secondary small">• Direct Dispatch</span>
        </div>
        <h2 class="h4 font-bold text-slate-800 m-0">Maintenance & Facility Requests</h2>
        <p class="text-secondary small mb-0">Submit repair tickets, track estate maintenance resolution, and view dispatch logs.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="#newTicketForm" class="btn btn-primary rounded-pill px-4 py-2 fw-semibold d-inline-flex align-items-center gap-2 shadow-sm">
            <i class="fa-solid fa-plus-circle"></i> New Ticket
        </a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" role="alert" style="border-radius: 12px; background: rgba(16, 185, 129, 0.12); color: #065f46; border-left: 4px solid #10b981 !important;">
        <div class="d-flex align-items-center gap-2">
            <i class="fa-solid fa-circle-check fs-5"></i>
            <div><?= htmlspecialchars($message) ?></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-4" role="alert" style="border-radius: 12px; background: rgba(239, 68, 68, 0.12); color: #991b1b; border-left: 4px solid #ef4444 !important;">
        <div class="d-flex align-items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation fs-5"></i>
            <div><?= htmlspecialchars($error) ?></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Futuristic Glass Metric Ribbon -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="resident-kpi-card accent-primary h-100">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-secondary small fw-semibold text-uppercase tracking-wider">Total Tickets</span>
                <div class="p-2 rounded-3 bg-primary bg-opacity-10 text-primary">
                    <i class="fa-solid fa-ticket-simple"></i>
                </div>
            </div>
            <div class="h3 font-bold text-slate-800 mb-1"><?= number_format($total_tickets) ?></div>
            <div class="small text-secondary"><i class="fa-regular fa-clock me-1"></i> Lifetime logged</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="resident-kpi-card accent-amber h-100">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-secondary small fw-semibold text-uppercase tracking-wider">Awaiting Review</span>
                <div class="p-2 rounded-3 bg-warning bg-opacity-10 text-warning">
                    <i class="fa-solid fa-hourglass-half"></i>
                </div>
            </div>
            <div class="h3 font-bold text-slate-800 mb-1"><?= number_format($pending_count) ?></div>
            <div class="small text-secondary"><i class="fa-solid fa-inbox me-1"></i> Pending dispatch</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="resident-kpi-card accent-purple h-100">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-secondary small fw-semibold text-uppercase tracking-wider">In Progress</span>
                <div class="p-2 rounded-3 bg-info bg-opacity-10 text-info">
                    <i class="fa-solid fa-wrench"></i>
                </div>
            </div>
            <div class="h3 font-bold text-slate-800 mb-1"><?= number_format($inprogress_count) ?></div>
            <div class="small text-secondary"><i class="fa-solid fa-person-digging me-1"></i> Technicians active</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="resident-kpi-card accent-emerald h-100">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-secondary small fw-semibold text-uppercase tracking-wider">Resolved</span>
                <div class="p-2 rounded-3 bg-success bg-opacity-10 text-success">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
            </div>
            <div class="h3 font-bold text-slate-800 mb-1"><?= number_format($resolved_count) ?></div>
            <div class="small text-secondary"><i class="fa-solid fa-shield-halved me-1"></i> Closed tickets</div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Create Maintenance Request Form -->
    <div class="col-lg-5" id="newTicketForm">
        <div class="resident-glass-panel h-100">
            <div class="resident-card-header">
                <div>
                    <h5 class="resident-card-title mb-1">
                        <i class="fa-solid fa-file-circle-plus text-primary me-2"></i> Submit Issue
                    </h5>
                    <p class="text-secondary small mb-0">Describe the issue and our technical squad will respond.</p>
                </div>
            </div>
            <div class="p-4">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-secondary">ISSUE TITLE / SUMMARY</label>
                        <div class="input-group">
                            <span class="input-group-text bg-transparent border-end-0 text-secondary">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </span>
                            <input type="text" name="title" class="form-control border-start-0 ps-0" placeholder="e.g. Water leak under kitchen sink" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-secondary">FLAT / RESIDENTIAL LOCATION</label>
                        <div class="input-group">
                            <span class="input-group-text bg-transparent border-end-0 text-secondary">
                                <i class="fa-solid fa-location-dot"></i>
                            </span>
                            <select name="flat_id" class="form-select border-start-0 ps-0" required>
                                <?php if ($user_flat): ?>
                                    <option value="<?= $user_flat['id'] ?>" selected>
                                        <?= htmlspecialchars($user_flat['building_name']) ?> - Flat <?= htmlspecialchars($user_flat['number']) ?> (My Assigned Unit)
                                    </option>
                                <?php else: ?>
                                    <option value="">Select affected unit / location...</option>
                                <?php endif; ?>
                                
                                <?php if ($flats): while($f = $flats->fetch_assoc()): ?>
                                    <?php if ($user_flat && $user_flat['id'] == $f['id']) continue; ?>
                                    <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['building_name']) ?> - Flat <?= htmlspecialchars($f['number']) ?></option>
                                <?php endwhile; endif; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small text-secondary">SEVERITY / PRIORITY</label>
                        <div class="row g-2">
                            <div class="col-6 col-sm-3">
                                <label class="priority-choice w-100 text-center p-2 rounded-3 border d-block cursor-pointer">
                                    <input type="radio" name="priority" value="low" class="d-none">
                                    <div class="small fw-bold text-slate-700">Low</div>
                                    <div class="badge bg-secondary bg-opacity-10 text-secondary" style="font-size:0.65rem;">Routine</div>
                                </label>
                            </div>
                            <div class="col-6 col-sm-3">
                                <label class="priority-choice w-100 text-center p-2 rounded-3 border d-block cursor-pointer active">
                                    <input type="radio" name="priority" value="medium" class="d-none" checked>
                                    <div class="small fw-bold text-primary">Medium</div>
                                    <div class="badge bg-primary bg-opacity-10 text-primary" style="font-size:0.65rem;">Standard</div>
                                </label>
                            </div>
                            <div class="col-6 col-sm-3">
                                <label class="priority-choice w-100 text-center p-2 rounded-3 border d-block cursor-pointer">
                                    <input type="radio" name="priority" value="high" class="d-none">
                                    <div class="small fw-bold text-warning">High</div>
                                    <div class="badge bg-warning bg-opacity-10 text-warning" style="font-size:0.65rem;">Urgent</div>
                                </label>
                            </div>
                            <div class="col-6 col-sm-3">
                                <label class="priority-choice w-100 text-center p-2 rounded-3 border d-block cursor-pointer">
                                    <input type="radio" name="priority" value="emergency" class="d-none">
                                    <div class="small fw-bold text-danger">Critical</div>
                                    <div class="badge bg-danger bg-opacity-10 text-danger" style="font-size:0.65rem;">Hazard</div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold small text-secondary">ISSUE DESCRIPTION & ACCESS INSTRUCTIONS</label>
                        <textarea name="description" class="form-control" rows="4" placeholder="Provide details, convenient inspection hours, or special gate clearance notes..." required style="border-radius: 12px;"></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold rounded-pill d-flex align-items-center justify-content-center gap-2 shadow-sm">
                        <i class="fa-solid fa-paper-plane"></i> Dispatch Work Order
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- History of Maintenance Requests -->
    <div class="col-lg-7">
        <div class="resident-glass-panel h-100">
            <div class="resident-card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="resident-card-title mb-1">
                        <i class="fa-solid fa-clock-rotate-left text-info me-2"></i> Request Dispatch Log
                    </h5>
                    <p class="text-secondary small mb-0">Track technician assignment and resolution logs.</p>
                </div>
                <span class="badge bg-light text-dark border px-3 py-2 rounded-pill font-monospace small">
                    <?= count($requests_list) ?> Total
                </span>
            </div>
            <div class="p-0">
                <div class="table-responsive">
                    <table class="dashboard-table w-100 align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="padding-left: 1.5rem;">Issue Details</th>
                                <th>Unit</th>
                                <th>Severity</th>
                                <th>Status</th>
                                <th style="padding-right: 1.5rem;">Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($requests_list)): ?>
                                <?php foreach($requests_list as $req): ?>
                                    <tr>
                                        <td style="padding-left: 1.5rem;">
                                            <div class="fw-bold text-slate-800"><?= htmlspecialchars($req['title']) ?></div>
                                            <div class="small text-secondary text-truncate" style="max-width: 230px;" title="<?= htmlspecialchars($req['description']) ?>">
                                                <?= htmlspecialchars($req['description']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border font-monospace">
                                                <?= htmlspecialchars(($req['building_name'] ?? '') . ' #' . ($req['flat_number'] ?? 'N/A')) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $p = strtolower($req['priority'] ?? 'medium');
                                            $p_badge = match($p) {
                                                'emergency', 'critical' => '<span class="mature-badge mature-badge-danger"><i class="fa-solid fa-radiation"></i> Emergency</span>',
                                                'high' => '<span class="mature-badge mature-badge-warning"><i class="fa-solid fa-triangle-exclamation"></i> High</span>',
                                                'medium' => '<span class="mature-badge mature-badge-info"><i class="fa-solid fa-circle-dot"></i> Medium</span>',
                                                default => '<span class="mature-badge mature-badge-secondary"><i class="fa-solid fa-minus"></i> Low</span>'
                                            };
                                            echo $p_badge;
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $st = strtolower($req['status'] ?? 'open');
                                            $st_badge = match($st) {
                                                'resolved', 'completed', 'closed' => '<span class="mature-badge mature-badge-success"><i class="fa-solid fa-circle-check"></i> Resolved</span>',
                                                'in_progress' => '<span class="mature-badge mature-badge-primary"><i class="fa-solid fa-wrench fa-spin"></i> In Progress</span>',
                                                'cancelled' => '<span class="mature-badge mature-badge-secondary">Cancelled</span>',
                                                default => '<span class="mature-badge mature-badge-warning"><i class="fa-regular fa-clock"></i> Pending</span>'
                                            };
                                            echo $st_badge;
                                            ?>
                                        </td>
                                        <td style="padding-right: 1.5rem;">
                                            <div class="small font-monospace text-secondary">
                                                <?= date('M j, Y', strtotime($req['created_at'])) ?>
                                            </div>
                                            <div class="small text-muted" style="font-size:0.75rem;">
                                                <?= date('h:i A', strtotime($req['created_at'])) ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5">
                                        <div class="p-4 text-center">
                                            <div class="w-12 h-12 rounded-circle bg-light d-inline-flex align-items-center justify-content-center text-muted mb-3" style="width:54px; height:54px; font-size:1.5rem;">
                                                <i class="fa-solid fa-clipboard-check"></i>
                                            </div>
                                            <h6 class="font-bold text-slate-700 mb-1">No Maintenance Requests</h6>
                                            <p class="text-secondary small mb-0">All facilities are operational. Submit a ticket if you notice any issues.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.priority-choice {
    cursor: pointer;
    transition: all 0.2s ease;
    background: rgba(248, 250, 252, 0.6);
}
.priority-choice:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0,0,0,0.05);
}
.priority-choice.active {
    border-color: var(--primary-color, #3b82f6) !important;
    background: rgba(59, 130, 246, 0.08);
}
[data-theme="dark"] .priority-choice,
body.dark-mode .priority-choice {
    background: rgba(15, 23, 42, 0.5);
    border-color: rgba(255, 255, 255, 0.1) !important;
}
[data-theme="dark"] .priority-choice.active,
body.dark-mode .priority-choice.active {
    background: rgba(56, 189, 248, 0.15) !important;
    border-color: #38bdf8 !important;
}
</style>

<script>
document.querySelectorAll('.priority-choice').forEach(label => {
    label.addEventListener('click', function() {
        document.querySelectorAll('.priority-choice').forEach(l => l.classList.remove('active'));
        this.classList.add('active');
        const radio = this.querySelector('input[type="radio"]');
        if (radio) radio.checked = true;
    });
});
</script>

<?php include 'footer.php'; ?>
