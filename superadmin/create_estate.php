<?php
// superadmin/create_estate.php
require_once '../config.php';

// Check if user is superadmin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
    header("Location: ../index");
    exit;
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = $conn->real_escape_string($_POST['name']);
    $domain_prefix = $conn->real_escape_string($_POST['domain_prefix']);
    
    $admin_name = $conn->real_escape_string($_POST['admin_name']);
    $admin_email = $conn->real_escape_string($_POST['admin_email']);
    $admin_phone = $conn->real_escape_string($_POST['admin_phone']);
    $name_parts = explode(' ', trim($_POST['admin_name']));
    $admin_first_name = $conn->real_escape_string($name_parts[0] ?? 'Admin');
    $admin_last_name = $conn->real_escape_string(isset($name_parts[1]) ? implode(' ', array_slice($name_parts, 1)) : '');
    $default_admin_pass = !empty($_POST['admin_password']) ? $_POST['admin_password'] : $admin_first_name;
    $admin_pass = password_hash($default_admin_pass, PASSWORD_DEFAULT);
    
    // Check if domain prefix already exists
    $check_domain = $conn->query("SELECT id FROM estates WHERE domain_prefix = '$domain_prefix'");
    
    // Check if admin email already exists (emails must be unique globally in the DB schema for login)
    $check_email = $conn->query("SELECT id FROM users WHERE email = '$admin_email'");

    if ($check_domain->num_rows > 0) {
        $error = "Domain prefix already exists. Please choose another one.";
    } elseif ($check_email->num_rows > 0) {
        $error = "Admin email is already in use by another account.";
    } else {
        $conn->begin_transaction();
        try {
            // 1. Create Estate
            $conn->query("INSERT INTO estates (name, domain_prefix) VALUES ('$name', '$domain_prefix')");
            $estate_id = $conn->insert_id;
            
            // 2. Create Admin User for this Estate
            $conn->query("INSERT INTO users (estate_id, first_name, last_name, name, email, phone, password, role) 
                          VALUES ($estate_id, '$admin_first_name', '$admin_last_name', '$admin_name', '$admin_email', '$admin_phone', '$admin_pass', 'admin')");
            
            // 3. Seed default system settings for this estate
            $default_settings = [
                ['currency', 'NGN'],
                ['currency_symbol', '₦'],
                ['estate_name', $name],
                ['estate_logo', ''],
                ['app_company_name', 'NoLimitBuzz'],
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
            
            $conn->commit();
            
            header("Location: index?success=1");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to create estate: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Estate - Super Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/estate_notifications.css">
    <script src="../js/estate_notifications.js"></script>
    <style>
        .container { max-width: 800px; margin: 0 auto; padding: 2rem; }
        .glass-panel { background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(10px); padding: 2rem; border-radius: 1rem; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
        .form-group { margin-bottom: 1.5rem; }
        .form-group.full { grid-column: span 2; }
        label { display: block; margin-bottom: 0.5rem; font-weight: 500; color: #475569; font-size: 0.9rem; }
        input { width: 100%; padding: 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; font-family: inherit; transition: border-color 0.2s; box-sizing: border-box; }
        input:focus { border-color: #3b82f6; outline: none; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        .btn { padding: 0.75rem 1.5rem; border-radius: 0.5rem; text-decoration: none; display: inline-block; text-align: center; border: none; cursor: pointer; font-weight: 600; font-size: 1rem; width: 100%; transition: background 0.2s; }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-primary:hover { background: #2563eb; }
        .alert { background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #fecaca; }
        h3 { color: #1e293b; margin-bottom: 1.5rem; padding-bottom: 0.5rem; border-bottom: 1px solid #e2e8f0; }
    </style>
</head>
<body style="background: #f8fafc; font-family: 'Outfit', sans-serif;">
    <div class="container">
        <div style="margin-bottom: 2rem; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <div style="background: #3b82f6; color: white; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 8px;">
                    <i class="fa-solid fa-plus"></i>
                </div>
                <h2 style="margin: 0; color: #1e293b;">Register New Estate</h2>
            </div>
            <a href="index" style="color: #64748b; text-decoration: none; font-weight: 500;"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
        </div>

        <?php if ($error): ?>
            <div class="alert"><?= $error ?></div>
        <?php endif; ?>

        <div class="glass-panel">
            <form method="POST">
                <h3>1. Estate Details</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Estate Name</label>
                        <input type="text" name="name" placeholder="e.g. Sunrise Valley" required>
                    </div>
                    <div class="form-group">
                        <label>Domain Prefix</label>
                        <input type="text" name="domain_prefix" placeholder="e.g. sunrise (for sunrise.app.com)" required>
                    </div>
                </div>

                <h3 style="margin-top: 1rem;">2. Default Admin Account</h3>
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Admin Full Name</label>
                        <input type="text" name="admin_name" placeholder="e.g. Administrator" required>
                    </div>
                    <div class="form-group">
                        <label>Admin Email</label>
                        <input type="email" name="admin_email" placeholder="admin@sunrise.com" required>
                    </div>
                    <div class="form-group">
                        <label>Admin Phone</label>
                        <input type="text" name="admin_phone" placeholder="Contact number" required>
                    </div>
                    <div class="form-group full">
                        <label>Admin Password</label>
                        <input type="password" name="admin_password" placeholder="Create a strong password" required>
                    </div>
                </div>

                <div style="margin-top: 2rem;">
                    <button type="submit" class="btn btn-primary">Create Estate & Admin Account</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
