<?php
// superadmin/edit_estate.php - Dedicated Full Management & Governance for a Tenant Estate
$page_title = "Manage & Configure Tenant Estate";
require_once 'includes/super_header.php';
require_once 'includes/super_sidebar.php';
require_once 'includes/super_topbar.php';

$estate_id = intval($_GET['id'] ?? 0);
if ($estate_id <= 0) {
    redirectWithFlash('index', null, 'Invalid estate specified.');
}

$eRes = $conn->query("SELECT * FROM estates WHERE id = $estate_id LIMIT 1");
if (!$eRes || $eRes->num_rows === 0) {
    redirectWithFlash('index', null, 'Estate record not found.');
}
$estate = $eRes->fetch_assoc();

$message = getFlashMessage('success') ?? '';
$error = getFlashMessage('error') ?? '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_estate'])) {
    $name          = trim($conn->real_escape_string($_POST['name'] ?? $estate['name']));
    $domain_prefix = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['domain_prefix'] ?? $estate['domain_prefix'])));
    $status        = in_array($_POST['status'] ?? '', ['active', 'suspended', 'trial', 'maintenance', 'expired']) ? $_POST['status'] : $estate['status'];
    $plan          = in_array($_POST['plan'] ?? '', ['starter', 'growth', 'enterprise']) ? $_POST['plan'] : $estate['plan'];
    $billing_cycle = in_array($_POST['billing_cycle'] ?? '', ['monthly', 'annual']) ? $_POST['billing_cycle'] : ($estate['billing_cycle'] ?? 'monthly');
    $max_residents = intval($_POST['max_residents'] ?? 250);
    $max_guards    = intval($_POST['max_guards'] ?? 15);
    $contact_email = trim($conn->real_escape_string($_POST['contact_email'] ?? ''));
    $contact_phone = trim($conn->real_escape_string($_POST['contact_phone'] ?? ''));
    $custom_domain = trim($conn->real_escape_string($_POST['custom_domain'] ?? ''));

    // Check subdomain collision
    if ($domain_prefix !== $estate['domain_prefix']) {
        $colChk = $conn->query("SELECT id FROM estates WHERE domain_prefix = '$domain_prefix' AND id != $estate_id");
        if ($colChk && $colChk->num_rows > 0) {
            $error = "Subdomain prefix '$domain_prefix' is already taken by another estate.";
        }
    }

    if (empty($error)) {
        $updateSql = "UPDATE estates SET 
            name = '$name',
            domain_prefix = '$domain_prefix',
            status = '$status',
            plan = '$plan',
            billing_cycle = '$billing_cycle',
            max_residents = $max_residents,
            max_guards = $max_guards,
            contact_email = '$contact_email',
            contact_phone = '$contact_phone',
            custom_domain = '$custom_domain'
        WHERE id = $estate_id";

        if ($conn->query($updateSql)) {
            // Optional admin password reset
            if (!empty($_POST['admin_new_password'])) {
                $newPassHash = password_hash($_POST['admin_new_password'], PASSWORD_DEFAULT);
                $admTarget = intval($_POST['admin_user_id'] ?? 0);
                if ($admTarget > 0) {
                    $conn->query("UPDATE users SET password = '$newPassHash' WHERE id = $admTarget AND estate_id = $estate_id");
                }
            }

            logAudit($conn, "Estate Modified", "SaaS Management", "Super Admin updated settings for '$name' (ID: $estate_id).");
            $message = "Estate '$name' settings updated successfully!";
            
            // Refresh
            $estate = $conn->query("SELECT * FROM estates WHERE id = $estate_id LIMIT 1")->fetch_assoc();
        } else {
            $error = "Update failed: " . $conn->error;
        }
    }
}

// Fetch Estate Statistics
$resCnt = $conn->query("SELECT COUNT(*) as c FROM residents WHERE estate_id = $estate_id")->fetch_assoc()['c'] ?? 0;
$flatsCnt = $conn->query("SELECT COUNT(*) as c FROM flats WHERE estate_id = $estate_id")->fetch_assoc()['c'] ?? 0;
$guardsCnt = $conn->query("SELECT COUNT(*) as c FROM users WHERE estate_id = $estate_id AND role = 'security'")->fetch_assoc()['c'] ?? 0;
$visitsCnt = $conn->query("SELECT COUNT(*) as c FROM visitors WHERE estate_id = $estate_id")->fetch_assoc()['c'] ?? 0;

// Fetch Estate Admins
$adminsQuery = $conn->query("SELECT id, name, email, phone, created_at FROM users WHERE estate_id = $estate_id AND role = 'admin' ORDER BY id ASC");
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

<!-- PAGE HEADER -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <a href="index" class="btn btn-sm btn-light rounded-circle border p-1" style="width: 32px; height: 32px;"><i class="fa-solid fa-arrow-left"></i></a>
            <h4 class="fw-bold mb-0 text-slate-800"><?= htmlspecialchars($estate['name']) ?></h4>
            <span class="badge bg-light text-dark border font-monospace">ID: #<?= $estate_id ?></span>
            <span class="status-badge status-<?= $estate['status'] ?>"><?= ucfirst($estate['status']) ?></span>
        </div>
        <p class="text-secondary small mb-0">Tenant Subdomain: <code><?= htmlspecialchars($estate['domain_prefix']) ?>.estateapp.com</code></p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="impersonate?estate_id=<?= $estate_id ?>" class="btn btn-warning rounded-pill px-3 py-2 fw-bold shadow-sm d-flex align-items-center gap-2">
            <i class="fa-solid fa-key"></i> Impersonate Portal
        </a>
        <a href="modules?estate_id=<?= $estate_id ?>" class="btn btn-outline-primary rounded-pill px-3 py-2 fw-semibold">
            <i class="fa-solid fa-sliders me-1"></i> Module Flags
        </a>
    </div>
</div>

<!-- STATS ROW -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="metric-card">
            <span class="text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem;">Active Residents</span>
            <h3 class="fw-bold mb-0 text-slate-900 mt-1"><?= number_format($resCnt) ?> <span class="fs-6 text-secondary font-monospace fw-normal">/ <?= $estate['max_residents'] ?> max</span></h3>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="metric-card">
            <span class="text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem;">Flats / Units</span>
            <h3 class="fw-bold mb-0 text-slate-900 mt-1"><?= number_format($flatsCnt) ?></h3>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="metric-card">
            <span class="text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem;">Security Guards</span>
            <h3 class="fw-bold mb-0 text-slate-900 mt-1"><?= number_format($guardsCnt) ?> <span class="fs-6 text-secondary font-monospace fw-normal">/ <?= $estate['max_guards'] ?> max</span></h3>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="metric-card">
            <span class="text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem;">Gate Visits</span>
            <h3 class="fw-bold mb-0 text-slate-900 mt-1"><?= number_format($visitsCnt) ?></h3>
        </div>
    </div>
</div>

<form method="POST" action="">
    <div class="row g-4">
        <!-- LEFT: Primary Configuration -->
        <div class="col-12 col-lg-8">
            <div class="card-custom p-4 mb-4">
                <h5 class="fw-bold text-slate-800 mb-3 border-bottom pb-2">Tenant Identity &amp; Routing</h5>
                
                <div class="row g-3 mb-3">
                    <div class="col-md-7">
                        <label class="form-label small fw-bold text-secondary">Estate Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control rounded-3" value="<?= htmlspecialchars($estate['name']) ?>" required>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-bold text-secondary">Subdomain Routing <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" name="domain_prefix" class="form-control rounded-start-3 font-monospace" value="<?= htmlspecialchars($estate['domain_prefix']) ?>" required>
                            <span class="input-group-text bg-light text-secondary font-monospace small">.estateapp.com</span>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-secondary">Custom Domain (CNAME / White-label)</label>
                        <input type="text" name="custom_domain" class="form-control rounded-3" placeholder="portal.myestate.com" value="<?= htmlspecialchars($estate['custom_domain'] ?? '') ?>">
                        <small class="text-secondary" style="font-size: 0.72rem;">Optional custom white-label hostname.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-secondary">Billing Cycle</label>
                        <select name="billing_cycle" class="form-select rounded-3">
                            <option value="monthly" <?= ($estate['billing_cycle'] ?? '') === 'monthly' ? 'selected' : '' ?>>Monthly Billing</option>
                            <option value="annual" <?= ($estate['billing_cycle'] ?? '') === 'annual' ? 'selected' : '' ?>>Annual Billing (Discounted)</option>
                        </select>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-secondary">Official Contact Email</label>
                        <input type="email" name="contact_email" class="form-control rounded-3" value="<?= htmlspecialchars($estate['contact_email'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-secondary">Official Contact Phone</label>
                        <input type="text" name="contact_phone" class="form-control rounded-3" value="<?= htmlspecialchars($estate['contact_phone'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <!-- Capacity Limits -->
            <div class="card-custom p-4 mb-4">
                <h5 class="fw-bold text-slate-800 mb-3 border-bottom pb-2">Capacity Quotas &amp; Enforcement</h5>
                
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-secondary">Maximum Residents Quota</label>
                        <input type="number" name="max_residents" class="form-control rounded-3" value="<?= intval($estate['max_residents'] ?: 250) ?>" min="10" max="10000">
                        <small class="text-secondary" style="font-size: 0.72rem;">Estate cannot register active residents beyond this limit.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-secondary">Maximum Security Guards Quota</label>
                        <input type="number" name="max_guards" class="form-control rounded-3" value="<?= intval($estate['max_guards'] ?: 15) ?>" min="1" max="500">
                        <small class="text-secondary" style="font-size: 0.72rem;">Active security roster limit for gate terminals.</small>
                    </div>
                </div>
            </div>

            <!-- Administrators Management -->
            <div class="card-custom p-4 mb-4">
                <h5 class="fw-bold text-slate-800 mb-3 border-bottom pb-2">Tenant Administrators &amp; Password Reset</h5>
                <p class="text-secondary small mb-3">Administrators configured for this specific estate portal.</p>
                
                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle">
                        <thead class="table-light small">
                            <tr>
                                <th>Admin Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($adm = $adminsQuery->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($adm['name']) ?></td>
                                    <td><?= htmlspecialchars($adm['email']) ?></td>
                                    <td><?= htmlspecialchars($adm['phone'] ?: '—') ?></td>
                                    <td>
                                        <button type="button" class="btn btn-xs btn-outline-secondary rounded-pill px-2.5 py-0.5" onclick="setResetAdmin(<?= $adm['id'] ?>, '<?= htmlspecialchars(addslashes($adm['name'])) ?>')">
                                            Reset Pass
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>

                <div id="resetPassBox" style="display: none;" class="p-3 bg-light rounded-3 border">
                    <input type="hidden" name="admin_user_id" id="resetAdminUserId" value="">
                    <label class="form-label small fw-bold text-danger">Reset Password for <span id="resetAdminName">Admin</span></label>
                    <input type="password" name="admin_new_password" class="form-control rounded-3" placeholder="Enter new password to override...">
                    <small class="text-secondary" style="font-size: 0.72rem;">Leave blank to keep existing password.</small>
                </div>
            </div>
        </div>

        <!-- RIGHT: Subscription & Control Actions -->
        <div class="col-12 col-lg-4">
            <!-- Plan & Status Card -->
            <div class="card-custom p-4 mb-4">
                <h5 class="fw-bold text-slate-800 mb-3 border-bottom pb-2">Subscription &amp; Status</h5>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">Plan Tier</label>
                    <select name="plan" class="form-select rounded-3">
                        <option value="starter" <?= $estate['plan'] === 'starter' ? 'selected' : '' ?>>Starter Tier (Basic Access)</option>
                        <option value="growth" <?= $estate['plan'] === 'growth' ? 'selected' : '' ?>>Growth Tier (Pro Features)</option>
                        <option value="enterprise" <?= $estate['plan'] === 'enterprise' ? 'selected' : '' ?>>Enterprise Tier (Uncapped + USSD)</option>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-secondary">Tenant Access Status</label>
                    <select name="status" class="form-select rounded-3">
                        <option value="active" <?= $estate['status'] === 'active' ? 'selected' : '' ?>>Active (Full Access)</option>
                        <option value="trial" <?= $estate['status'] === 'trial' ? 'selected' : '' ?>>Trial Period</option>
                        <option value="suspended" <?= $estate['status'] === 'suspended' ? 'selected' : '' ?>>Suspended (Lockout Portal)</option>
                        <option value="maintenance" <?= $estate['status'] === 'maintenance' ? 'selected' : '' ?>>Maintenance Mode</option>
                    </select>
                    <small class="text-secondary" style="font-size: 0.72rem;">Suspended status immediately intercepts logins and shows the suspension screen.</small>
                </div>

                <button type="submit" name="update_estate" class="btn btn-primary w-100 rounded-pill py-2.5 fw-bold shadow-sm">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Save Estate Changes
                </button>
            </div>

            <!-- Danger Zone -->
            <?php if ($estate_id > 1): ?>
                <div class="card-custom p-4 border-danger border-opacity-25 bg-danger bg-opacity-10">
                    <h6 class="fw-bold text-danger mb-2"><i class="fa-solid fa-triangle-exclamation me-1"></i> Danger Zone</h6>
                    <p class="small text-secondary mb-3">Deleting this estate will permanently remove its flats, residents, and visitor records.</p>
                    <form method="POST" action="index" onsubmit="return confirm('DANGER: Permanent deletion of <?= htmlspecialchars(addslashes($estate['name'])) ?>! Are you absolutely sure?');">
                        <input type="hidden" name="estate_id" value="<?= $estate_id ?>">
                        <button type="submit" name="delete_estate" class="btn btn-outline-danger w-100 rounded-pill py-2 fw-bold">
                            <i class="fa-solid fa-trash me-1"></i> Delete Estate
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<script>
function setResetAdmin(id, name) {
    document.getElementById('resetPassBox').style.display = 'block';
    document.getElementById('resetAdminUserId').value = id;
    document.getElementById('resetAdminName').textContent = name;
}
</script>

<?php require_once 'includes/super_footer.php'; ?>
