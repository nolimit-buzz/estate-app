<?php
// superadmin/admins.php - Super Administrator Accounts & Role Security
$page_title = "Super Administrator Accounts";
require_once 'includes/super_header.php';
require_once 'includes/super_sidebar.php';
require_once 'includes/super_topbar.php';

$message = getFlashMessage('success') ?? '';
$error = getFlashMessage('error') ?? '';

// Create new Super Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_super_admin'])) {
    $name = trim($conn->real_escape_string($_POST['name'] ?? ''));
    $email = trim($conn->real_escape_string($_POST['email'] ?? ''));
    $phone = trim($conn->real_escape_string($_POST['phone'] ?? ''));
    $raw_pass = trim($_POST['password'] ?? '');

    $chk = $conn->query("SELECT id FROM users WHERE email = '$email'");
    if (empty($name) || empty($email) || empty($raw_pass)) {
        $error = "Name, email, and password are required.";
    } elseif ($chk && $chk->num_rows > 0) {
        $error = "A user with email '$email' already exists.";
    } else {
        $name_parts = explode(' ', $name);
        $fn = $conn->real_escape_string($name_parts[0] ?? 'Admin');
        $ln = $conn->real_escape_string(isset($name_parts[1]) ? implode(' ', array_slice($name_parts, 1)) : '');
        $passHash = password_hash($raw_pass, PASSWORD_DEFAULT);

        $sql = "INSERT INTO users (estate_id, name, first_name, last_name, email, phone, password, role, status)
                VALUES (1, '$name', '$fn', '$ln', '$email', '$phone', '$passHash', 'superadmin', 'active')";
        if ($conn->query($sql)) {
            logAudit($conn, "Super Admin Created", "Security & Governance", "Created new Super Admin account for '$name' ($email).");
            $message = "Super Administrator account for '$name' created successfully.";
        } else {
            $error = "Failed to create account: " . $conn->error;
        }
    }
}

// Reset Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $target_uid = intval($_POST['user_id'] ?? 0);
    $new_pass = trim($_POST['new_password'] ?? '');
    if ($target_uid > 0 && !empty($new_pass)) {
        $pHash = password_hash($new_pass, PASSWORD_DEFAULT);
        if ($conn->query("UPDATE users SET password = '$pHash' WHERE id = $target_uid AND role = 'superadmin'")) {
            logAudit($conn, "Password Reset", "Security & Governance", "Super Admin reset password for user ID $target_uid.");
            $message = "Password updated successfully.";
        } else {
            $error = "Failed updating password: " . $conn->error;
        }
    }
}

// Fetch all Super Admins
$superAdmins = [];
$res = $conn->query("SELECT * FROM users WHERE role = 'superadmin' OR id = 1 ORDER BY id ASC");
if ($res) {
    while ($r = $res->fetch_assoc()) $superAdmins[] = $r;
}
?>

<!-- FLASH ALERTS -->
<?php if (!empty($message)): ?>
    <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0 d-flex align-items-center mb-4">
        <i class="fa-solid fa-circle-check fs-5 me-2 text-success"></i>
        <div><?= htmlspecialchars($message) ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm border-0 d-flex align-items-center mb-4">
        <i class="fa-solid fa-circle-exclamation fs-5 me-2 text-danger"></i>
        <div><?= htmlspecialchars($error) ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
        <h4 class="fw-bold mb-1 text-slate-800">Super Administrator Accounts</h4>
        <p class="text-secondary small mb-0">Platform root operators with full authority across multi-tenant environments, databases, and telecom gateways.</p>
    </div>
    <button type="button" class="btn btn-primary rounded-pill px-4 py-2 fw-bold shadow-sm d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#newAdminModal">
        <i class="fa-solid fa-user-plus"></i> Add Super Admin
    </button>
</div>

<!-- ADMINS LIST CARD -->
<div class="card-custom mb-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem;">
                <tr>
                    <th class="ps-4">Super Administrator</th>
                    <th>Email Address</th>
                    <th>Phone</th>
                    <th>Authority Role</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th class="text-end pe-4">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($superAdmins as $sa): 
                    $isRoot = (intval($sa['id']) === 1);
                ?>
                    <tr>
                        <td class="ps-4">
                            <div class="d-flex align-items-center gap-3">
                                <div style="width: 40px; height: 40px; border-radius: 12px; background: #0b1120; color: #38bdf8; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.85rem; border: 1.5px solid #38bdf8;">
                                    <?= strtoupper(substr($sa['name'], 0, 2)) ?>
                                </div>
                                <div>
                                    <div class="fw-bold text-slate-800 fs-6"><?= htmlspecialchars($sa['name']) ?></div>
                                    <small class="text-secondary font-monospace" style="font-size: 0.72rem;">User ID: #<?= $sa['id'] ?> <?= $isRoot ? '&bull; Primary Root' : '' ?></small>
                                </div>
                            </div>
                        </td>
                        <td class="font-monospace small"><?= htmlspecialchars($sa['email']) ?></td>
                        <td class="small text-secondary"><?= htmlspecialchars($sa['phone'] ?: '—') ?></td>
                        <td>
                            <span class="badge bg-dark rounded-pill px-3 py-1 font-monospace" style="font-size: 0.7rem;">
                                <i class="fa-solid fa-shield-halved text-warning me-1"></i>SUPERADMIN
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2.5 py-1 fw-bold">Active</span>
                        </td>
                        <td class="text-secondary small"><?= date('M d, Y', strtotime($sa['created_at'])) ?></td>
                        <td class="text-end pe-4">
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1" onclick="openResetModal(<?= $sa['id'] ?>, '<?= htmlspecialchars(addslashes($sa['name'])) ?>')">
                                <i class="fa-solid fa-key me-1"></i> Reset Pass
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL: ADD SUPER ADMIN -->
<div class="modal fade" id="newAdminModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header bg-dark text-white border-0 py-3">
                <h6 class="modal-title fw-bold mb-0"><i class="fa-solid fa-user-plus text-primary me-2"></i>Create Super Administrator</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4 bg-light">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Full Name *</label>
                        <input type="text" name="name" class="form-control rounded-3" placeholder="Jane Doe" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Email Address *</label>
                        <input type="email" name="email" class="form-control rounded-3" placeholder="jane@platform.com" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Phone Number</label>
                        <input type="text" name="phone" class="form-control rounded-3" placeholder="+234 800 000 0000">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Password *</label>
                        <input type="password" name="password" class="form-control rounded-3" placeholder="Set secure password" required>
                    </div>
                </div>
                <div class="modal-footer bg-white border-0 py-3">
                    <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_super_admin" class="btn btn-primary rounded-pill px-4 fw-bold">Create Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: RESET PASSWORD -->
<div class="modal fade" id="resetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header bg-dark text-white border-0 py-3">
                <h6 class="modal-title fw-bold mb-0"><i class="fa-solid fa-key text-warning me-2"></i>Reset Super Admin Password</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="user_id" id="resetUserId" value="">
                <div class="modal-body p-4 bg-light">
                    <p class="small text-secondary mb-3">Updating credentials for <strong id="resetTargetName">User</strong>.</p>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">New Password *</label>
                        <input type="password" name="new_password" class="form-control rounded-3" placeholder="Enter new strong password" required>
                    </div>
                </div>
                <div class="modal-footer bg-white border-0 py-3">
                    <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="reset_password" class="btn btn-warning rounded-pill px-4 fw-bold text-dark">Save New Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openResetModal(userId, userName) {
    document.getElementById('resetUserId').value = userId;
    document.getElementById('resetTargetName').textContent = userName;
    new bootstrap.Modal(document.getElementById('resetModal')).show();
}
</script>

<?php require_once 'includes/super_footer.php'; ?>
