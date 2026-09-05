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
        $_SESSION['success_msg'] = "Request submitted successfully. Our team will look into it shortly.";
    } else {
        $_SESSION['error_msg'] = "Error: " . $conn->error;
    }
    header("Location: report_issue");
    exit;
}

// Fetch messages
$message = $_SESSION['success_msg'] ?? '';
$error = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// Fetch flats for the dropdown
$flats = $conn->query("SELECT f.id, f.number, b.name as building_name 
                      FROM flats f 
                      JOIN buildings b ON f.building_id = b.id 
                      WHERE f.estate_id = $estate_id
                      ORDER BY b.name, f.number");

// Fetch resident's submitted maintenance requests
$my_requests = $conn->query("SELECT m.*, f.number as flat_number, b.name as building_name 
                            FROM maintenance_requests m 
                            LEFT JOIN flats f ON m.flat_id = f.id 
                            LEFT JOIN buildings b ON f.building_id = b.id 
                            WHERE m.user_id = $user_id AND m.estate_id = $estate_id 
                            ORDER BY m.created_at DESC");

include 'header.php';
include 'sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 font-bold text-slate-800 m-0"><i class="fa-solid fa-screwdriver-wrench text-primary me-2"></i> Maintenance Requests</h2>
        <p class="text-secondary small mb-0">Submit and track your maintenance and repair requests.</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i> <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i> <?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Create Maintenance Request Form -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm glass">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="card-title font-bold m-0 text-slate-800"><i class="fa-solid fa-plus-circle text-primary me-2"></i> Report an Issue</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Title</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Leaking tap in bathroom" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Flat / Location</label>
                        <select name="flat_id" class="form-select" required>
                            <option value="">Select your flat...</option>
                            <?php if ($flats): while($f = $flats->fetch_assoc()): ?>
                                <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['building_name']) ?> - Flat <?= htmlspecialchars($f['number']) ?></option>
                            <?php endwhile; endif; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Priority</label>
                        <select name="priority" class="form-select" required>
                            <option value="low">Low - General query</option>
                            <option value="medium" selected>Medium - Needs attention</option>
                            <option value="high">High - Urgent</option>
                            <option value="emergency">Emergency - Critical hazard</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" class="form-control" rows="4" placeholder="Provide details about the issue..." required></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                        <i class="fa-solid fa-paper-plane me-2"></i> Submit Maintenance Request
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- History of Maintenance Requests -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm glass">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h5 class="card-title font-bold m-0 text-slate-800"><i class="fa-solid fa-clock-rotate-left text-info me-2"></i> My Request History</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle table-hover mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th>Issue</th>
                                <th>Location</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($my_requests && $my_requests->num_rows > 0): ?>
                                <?php while($req = $my_requests->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <strong class="text-slate-800"><?= htmlspecialchars($req['title']) ?></strong>
                                            <br><small class="text-secondary"><?= htmlspecialchars(substr($req['description'], 0, 45)) ?><?= strlen($req['description']) > 45 ? '...' : '' ?></small>
                                        </td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars(($req['building_name'] ?? '') . ' Flat ' . ($req['flat_number'] ?? 'N/A')) ?></span></td>
                                        <td>
                                            <?php 
                                            $p = strtolower($req['priority']);
                                            $p_class = match($p) {
                                                'emergency' => 'bg-danger',
                                                'high' => 'bg-warning text-dark',
                                                'medium' => 'bg-info text-dark',
                                                default => 'bg-secondary'
                                            };
                                            ?>
                                            <span class="badge <?= $p_class ?>"><?= ucfirst($p) ?></span>
                                        </td>
                                        <td>
                                            <?php 
                                            $st = strtolower($req['status']);
                                            $st_class = match($st) {
                                                'resolved', 'completed' => 'bg-success',
                                                'in_progress' => 'bg-primary',
                                                'open', 'pending' => 'bg-warning text-dark',
                                                'cancelled' => 'bg-secondary',
                                                default => 'bg-dark'
                                            };
                                            ?>
                                            <span class="badge <?= $st_class ?>"><?= ucfirst(str_replace('_', ' ', $st)) ?></span>
                                        </td>
                                        <td><small class="text-muted"><?= date('M j, Y H:i', strtotime($req['created_at'])) ?></small></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-secondary">No maintenance requests submitted yet.</td>
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
