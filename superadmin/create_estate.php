<?php
// superadmin/create_estate.php - Multi-Tenant Estate Provisioning Wizard
$page_title = "Provision New Tenant Estate";
require_once 'includes/super_header.php';
require_once 'includes/super_sidebar.php';
require_once 'includes/super_topbar.php';

$error = "";
$platformModules = getAllPlatformModules();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name          = trim($conn->real_escape_string($_POST['name'] ?? ''));
    $domain_prefix = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['domain_prefix'] ?? '')));
    $plan          = in_array($_POST['plan'] ?? '', ['starter', 'growth', 'enterprise']) ? $_POST['plan'] : 'starter';
    $billing_cycle = in_array($_POST['billing_cycle'] ?? '', ['monthly', 'annual']) ? $_POST['billing_cycle'] : 'monthly';
    $max_residents = intval($_POST['max_residents'] ?? 250);
    $max_guards    = intval($_POST['max_guards'] ?? 15);
    $contact_email = trim($conn->real_escape_string($_POST['contact_email'] ?? ''));
    $contact_phone = trim($conn->real_escape_string($_POST['contact_phone'] ?? ''));
    $custom_domain = trim($conn->real_escape_string($_POST['custom_domain'] ?? ''));
    
    $admin_name  = trim($_POST['admin_name'] ?? '');
    $admin_email = trim($_POST['admin_email'] ?? '');
    $admin_phone = trim($_POST['admin_phone'] ?? '');
    $admin_raw_pass = trim($_POST['admin_password'] ?? '');

    $name_parts = explode(' ', $admin_name);
    $admin_first_name = $conn->real_escape_string($name_parts[0] ?? 'Admin');
    $admin_last_name = $conn->real_escape_string(isset($name_parts[1]) ? implode(' ', array_slice($name_parts, 1)) : '');
    $admin_pass = password_hash($admin_raw_pass ?: 'AdminPass123!', PASSWORD_DEFAULT);
    
    // Check if domain prefix already exists
    $check_domain = $conn->query("SELECT id FROM estates WHERE domain_prefix = '$domain_prefix'");
    
    // Check if admin email already exists
    $check_email = $conn->query("SELECT id FROM users WHERE email = '" . $conn->real_escape_string($admin_email) . "'");

    if (empty($name) || empty($domain_prefix) || empty($admin_email)) {
        $error = "Estate Name, Subdomain Prefix, and Admin Email are required.";
    } elseif ($check_domain && $check_domain->num_rows > 0) {
        $error = "Domain prefix '$domain_prefix' is already taken. Please choose another prefix.";
    } elseif ($check_email && $check_email->num_rows > 0) {
        $error = "Admin email '$admin_email' is already registered on the system.";
    } else {
        $conn->begin_transaction();
        try {
            // 1. Create Estate
            $insEstateSql = "INSERT INTO estates (name, domain_prefix, status, plan, billing_cycle, contact_email, contact_phone, max_residents, max_guards, custom_domain) 
                             VALUES ('$name', '$domain_prefix', 'active', '$plan', '$billing_cycle', '$contact_email', '$contact_phone', $max_residents, $max_guards, '$custom_domain')";
            if (!$conn->query($insEstateSql)) {
                throw new Exception("Estate insert failed: " . $conn->error);
            }
            $estate_id = $conn->insert_id;
            
            // 2. Create Admin User for this Estate
            $admNameEsc = $conn->real_escape_string($admin_name);
            $admEmailEsc = $conn->real_escape_string($admin_email);
            $admPhoneEsc = $conn->real_escape_string($admin_phone);
            
            $insUserSql = "INSERT INTO users (estate_id, name, first_name, last_name, email, phone, password, role, status) 
                           VALUES ($estate_id, '$admNameEsc', '$admin_first_name', '$admin_last_name', '$admEmailEsc', '$admPhoneEsc', '$admin_pass', 'admin', 'active')";
            if (!$conn->query($insUserSql)) {
                throw new Exception("Admin user insert failed: " . $conn->error);
            }
            $admin_id = $conn->insert_id;
            
            // 3. Initialize default system settings for this estate
            $default_settings = [
                ['estate_name', $name],
                ['contact_email', $contact_email ?: $admin_email],
                ['contact_phone', $contact_phone ?: $admin_phone],
                ['emergency_phone', $contact_phone ?: '+234 800 000 0000'],
                ['currency_symbol', '₦'],
                ['ussd_enabled', '1'],
                ['primary_color', '#3b82f6']
            ];
            
            $stmt = $conn->prepare("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES (?, ?, ?)");
            foreach ($default_settings as $setting) {
                $stmt->bind_param("iss", $estate_id, $setting[0], $setting[1]);
                $stmt->execute();
            }
            $stmt->close();

            // 4. Seed Module Feature Flags
            $selectedModules = $_POST['modules'] ?? array_keys($platformModules);
            foreach ($platformModules as $modKey => $meta) {
                $isEnabled = in_array($modKey, $selectedModules) ? 1 : 0;
                $conn->query("INSERT INTO estate_modules (estate_id, module_key, is_enabled) VALUES ($estate_id, '$modKey', $isEnabled)");
            }
            
            $conn->commit();
            logAudit($conn, "Estate Provisioned", "SaaS Provisioning", "Super Admin provisioned new estate '$name' (ID: $estate_id, Plan: $plan, Prefix: $domain_prefix).");

            redirectWithFlash('index', "Estate '$name' provisioned successfully with Admin account ($admin_email)!");
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Provisioning error: " . $e->getMessage();
        }
    }
}
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div class="d-flex align-items-center gap-3">
        <a href="index" class="btn btn-sm btn-light rounded-circle border p-1" style="width: 34px; height: 34px;"><i class="fa-solid fa-arrow-left"></i></a>
        <div>
            <h4 class="fw-bold mb-0 text-slate-900">Provision Tenant Estate</h4>
            <small class="text-secondary">Onboard a new customer estate, allocate quotas, and configure active modules</small>
        </div>
    </div>
    <a href="index" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold">
        <i class="fa-solid fa-list me-1"></i> View All Estates
    </a>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger rounded-4 shadow-sm border-0 d-flex align-items-center mb-4">
        <i class="fa-solid fa-circle-exclamation fs-5 me-2 text-danger"></i>
        <div><?= htmlspecialchars($error) ?></div>
    </div>
<?php endif; ?>

<div class="card-custom p-4 p-md-5 mb-5" style="max-width: 1000px; margin: 0 auto;">
    <form method="POST">
        
        <!-- 1. ESTATE IDENTITY -->
        <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
            <div style="width: 32px; height: 32px; border-radius: 8px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 0.9rem;">
                <i class="fa-solid fa-building"></i>
            </div>
            <h6 class="fw-bold text-slate-800 mb-0">1. Estate Information &amp; Subdomain Routing</h6>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12 col-md-6">
                <label class="form-label small fw-bold text-secondary">Estate Official Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control rounded-3" placeholder="e.g. Imperial Heritage Estate" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label small fw-bold text-secondary">Subdomain Prefix (Slug) <span class="text-danger">*</span></label>
                <div class="input-group">
                    <input type="text" name="domain_prefix" class="form-control rounded-start-3 font-monospace" placeholder="imperial" required value="<?= htmlspecialchars($_POST['domain_prefix'] ?? '') ?>">
                    <span class="input-group-text bg-light text-secondary font-monospace small">.estateapp.com</span>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label small fw-bold text-secondary">Subscription Plan <span class="text-danger">*</span></label>
                <select name="plan" class="form-select rounded-3">
                    <option value="starter">Starter (Up to 150 units)</option>
                    <option value="growth" selected>Growth (Up to 500 units)</option>
                    <option value="enterprise">Enterprise (Unlimited + USSD)</option>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label small fw-bold text-secondary">Max Resident Quota</label>
                <input type="number" name="max_residents" class="form-control rounded-3" value="250">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label small fw-bold text-secondary">Max Security Guards</label>
                <input type="number" name="max_guards" class="form-control rounded-3" value="15">
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label small fw-bold text-secondary">Contact Email</label>
                <input type="email" name="contact_email" class="form-control rounded-3" placeholder="management@imperial.com">
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label small fw-bold text-secondary">Contact Phone</label>
                <input type="text" name="contact_phone" class="form-control rounded-3" placeholder="+234 800 000 0000">
            </div>
        </div>

        <!-- 2. DEFAULT ADMIN CREDENTIALS -->
        <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
            <div style="width: 32px; height: 32px; border-radius: 8px; background: #f3e8ff; color: #9333ea; display: flex; align-items: center; justify-content: center; font-size: 0.9rem;">
                <i class="fa-solid fa-user-shield"></i>
            </div>
            <h6 class="fw-bold text-slate-800 mb-0">2. Primary Tenant Administrator Account</h6>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12 col-md-6">
                <label class="form-label small fw-bold text-secondary">Admin Full Name <span class="text-danger">*</span></label>
                <input type="text" name="admin_name" class="form-control rounded-3" placeholder="e.g. Babatunde Adeyemi" required value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label small fw-bold text-secondary">Admin Login Email <span class="text-danger">*</span></label>
                <input type="email" name="admin_email" class="form-control rounded-3" placeholder="admin@imperial.com" required value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label small fw-bold text-secondary">Admin Phone Number</label>
                <input type="text" name="admin_phone" class="form-control rounded-3" placeholder="08012345678" value="<?= htmlspecialchars($_POST['admin_phone'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label small fw-bold text-secondary">Initial Password <span class="text-danger">*</span></label>
                <input type="password" name="admin_password" class="form-control rounded-3" placeholder="Create secure password" required>
            </div>
        </div>

        <!-- 3. INITIAL MODULE SELECTION -->
        <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
            <div style="width: 32px; height: 32px; border-radius: 8px; background: #dcfce7; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 0.9rem;">
                <i class="fa-solid fa-sliders"></i>
            </div>
            <h6 class="fw-bold text-slate-800 mb-0">3. Active Feature Modules (Can be toggled later)</h6>
        </div>

        <div class="row g-2 mb-4">
            <?php foreach ($platformModules as $modKey => $meta): ?>
                <div class="col-12 col-md-6">
                    <div class="p-3 bg-light rounded-3 border d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-2.5">
                            <i class="fa-solid <?= $meta['icon'] ?> text-secondary" style="width: 20px;"></i>
                            <div>
                                <div class="small fw-bold text-slate-800"><?= $meta['name'] ?></div>
                                <small class="text-secondary" style="font-size: 0.72rem;"><?= $meta['desc'] ?></small>
                            </div>
                        </div>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" name="modules[]" value="<?= $modKey ?>" id="mod_<?= $modKey ?>" checked style="cursor: pointer; width: 2.2rem; height: 1.2rem;">
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
            <a href="index" class="btn btn-light rounded-pill px-4 fw-semibold">Cancel</a>
            <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm">
                <i class="fa-solid fa-rocket me-1"></i> Provision &amp; Launch Estate
            </button>
        </div>
    </form>
</div>

<?php require_once 'includes/super_footer.php'; ?>
