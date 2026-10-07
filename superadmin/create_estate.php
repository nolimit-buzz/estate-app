<?php
// superadmin/create_estate.php - Multi-Tenant Estate Provisioning Wizard
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    redirectWithFlash('../login', null, 'Please sign in to access the system.');
}

if (($_SESSION['role'] ?? '') !== 'superadmin' && intval($_SESSION['user_id'] ?? 0) !== 1) {
    redirectWithFlash('../admin/index', null, 'Super Administrator privileges required.');
}

$error = "";
$platformModules = getAllPlatformModules();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name          = trim($conn->real_escape_string($_POST['name'] ?? ''));
    $domain_prefix = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['domain_prefix'] ?? '')));
    $plan          = in_array($_POST['plan'] ?? '', ['starter', 'growth', 'enterprise']) ? $_POST['plan'] : 'starter';
    $max_residents = intval($_POST['max_residents'] ?? 250);
    $max_guards    = intval($_POST['max_guards'] ?? 15);
    $contact_email = trim($conn->real_escape_string($_POST['contact_email'] ?? ''));
    $contact_phone = trim($conn->real_escape_string($_POST['contact_phone'] ?? ''));
    
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
            $insEstateSql = "INSERT INTO estates (name, domain_prefix, status, plan, contact_email, contact_phone, max_residents, max_guards) 
                             VALUES ('$name', '$domain_prefix', 'active', '$plan', '$contact_email', '$contact_phone', $max_residents, $max_guards)";
            if (!$conn->query($insEstateSql)) {
                throw new Exception("Estate insert failed: " . $conn->error);
            }
            $estate_id = $conn->insert_id;
            
            // 2. Create Admin User for this Estate
            $admNameEsc = $conn->real_escape_string($admin_name);
            $admEmailEsc = $conn->real_escape_string($admin_email);
            $admPhoneEsc = $conn->real_escape_string($admin_phone);

            $insUserSql = "INSERT INTO users (estate_id, first_name, last_name, name, email, phone, password, role, status) 
                           VALUES ($estate_id, '$admin_first_name', '$admin_last_name', '$admNameEsc', '$admEmailEsc', '$admPhoneEsc', '$admin_pass', 'admin', 'active')";
            if (!$conn->query($insUserSql)) {
                throw new Exception("Admin user creation failed: " . $conn->error);
            }
            
            // 3. Seed default system settings for this estate
            $default_settings = [
                ['currency', 'NGN'],
                ['currency_symbol', '₦'],
                ['estate_name', $name],
                ['estate_logo', ''],
                ['app_company_name', $name],
                ['primary_color', '#3b82f6'],
                ['theme_color', '#3b82f6'],
                ['system_email', $admin_email],
                ['grace_period_days', '7']
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provision New Estate - Super Admin</title>
    
    <!-- Google Fonts: Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 & Font Awesome -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body {
            font-family: 'Outfit', sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
        }
        .form-card {
            background: #ffffff;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05);
            padding: 2.2rem;
        }
        .section-header {
            font-size: 0.95rem;
            font-weight: 700;
            color: #0f172a;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 8px;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .module-checkbox-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px 14px;
            transition: all 0.2s;
        }
        .module-checkbox-item:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }
    </style>
</head>
<body>

    <div class="container py-5" style="max-width: 900px;">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div class="d-flex align-items-center gap-3">
                <div style="background: #0f172a; color: white; width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.25rem;">
                    <i class="fa-solid fa-plus"></i>
                </div>
                <div>
                    <h4 class="fw-bold mb-0 text-slate-900">Provision Tenant Estate</h4>
                    <small class="text-secondary">Register a new customer, set quotas, and configure active modules</small>
                </div>
            </div>
            <a href="index" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold">
                <i class="fa-solid fa-arrow-left me-1"></i> Back to Dashboard
            </a>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger rounded-4 shadow-sm border-0 d-flex align-items-center mb-4">
                <i class="fa-solid fa-circle-exclamation fs-5 me-2 text-danger"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <div class="form-card">
            <form method="POST">
                
                <!-- 1. ESTATE IDENTITY -->
                <div class="section-header">
                    <i class="fa-solid fa-building text-primary"></i> 1. Estate Information &amp; Subdomain
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-bold text-secondary">Estate Official Name *</label>
                        <input type="text" name="name" class="form-control rounded-3" placeholder="e.g. Sunrise Valley Estate" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-bold text-secondary">Subdomain Prefix (Slug) *</label>
                        <div class="input-group">
                            <input type="text" name="domain_prefix" class="form-control rounded-start-3 font-monospace" placeholder="sunrise" required value="<?= htmlspecialchars($_POST['domain_prefix'] ?? '') ?>">
                            <span class="input-group-text bg-light text-secondary rounded-end-3" style="font-size: 0.85rem;">.estateapp.com</span>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-bold text-secondary">Subscription Plan *</label>
                        <select name="plan" class="form-select rounded-3">
                            <option value="starter">Starter (Up to 150 units)</option>
                            <option value="growth" selected>Growth (Up to 500 units)</option>
                            <option value="enterprise">Enterprise (Unlimited units)</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-bold text-secondary">Max Resident Capacity</label>
                        <input type="number" name="max_residents" class="form-control rounded-3" value="250">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label small fw-bold text-secondary">Max Security Guards</label>
                        <input type="number" name="max_guards" class="form-control rounded-3" value="15">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-bold text-secondary">Contact Email</label>
                        <input type="email" name="contact_email" class="form-control rounded-3" placeholder="management@sunrise.com">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-bold text-secondary">Contact Phone</label>
                        <input type="text" name="contact_phone" class="form-control rounded-3" placeholder="+234 800 000 0000">
                    </div>
                </div>

                <!-- 2. DEFAULT ADMIN CREDENTIALS -->
                <div class="section-header">
                    <i class="fa-solid fa-user-shield text-primary"></i> 2. Primary Estate Administrator Account
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-bold text-secondary">Admin Full Name *</label>
                        <input type="text" name="admin_name" class="form-control rounded-3" placeholder="e.g. Babatunde Adeyemi" required value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-bold text-secondary">Admin Login Email *</label>
                        <input type="email" name="admin_email" class="form-control rounded-3" placeholder="admin@sunrise.com" required value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-bold text-secondary">Admin Phone Number</label>
                        <input type="text" name="admin_phone" class="form-control rounded-3" placeholder="08012345678" value="<?= htmlspecialchars($_POST['admin_phone'] ?? '') ?>">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-bold text-secondary">Initial Password *</label>
                        <input type="password" name="admin_password" class="form-control rounded-3" placeholder="Create secure password" required>
                    </div>
                </div>

                <!-- 3. INITIAL MODULE SELECTION -->
                <div class="section-header">
                    <i class="fa-solid fa-sliders text-primary"></i> 3. Enabled Feature Modules
                </div>
                <div class="row g-2 mb-4">
                    <?php foreach ($platformModules as $modKey => $meta): ?>
                        <div class="col-12 col-md-6">
                            <div class="module-checkbox-item d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="fa-solid <?= $meta['icon'] ?> text-secondary" style="width: 20px;"></i>
                                    <div>
                                        <div class="small fw-bold text-slate-800"><?= $meta['name'] ?></div>
                                    </div>
                                </div>
                                <div class="form-check m-0">
                                    <input class="form-check-input" type="checkbox" name="modules[]" value="<?= $modKey ?>" id="mod_<?= $modKey ?>" checked style="cursor: pointer;">
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
    </div>

</body>
</html>
