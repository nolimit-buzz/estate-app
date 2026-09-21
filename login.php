<?php
// login.php - Universal & Admin Portal Login
require_once 'config.php';
require_once 'includes/auth_guard.php';
require_once 'includes/auth_helper.php';

// If already logged in, redirect to respective dashboard
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'resident';
    if ($role === 'superadmin') {
        header("Location: superadmin/index");
    } elseif (in_array($role, ['admin', 'manager'])) {
        header("Location: admin/index");
    } elseif ($role === 'zone_admin') {
        header("Location: zone/index");
    } elseif ($role === 'resident') {
        header("Location: resident/index");
    } else {
        header("Location: staff/index");
    }
    exit;
}

// Fetch Estate Details for branding
$sys_res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE estate_id = " . get_estate_id());
$sys = [];
if ($sys_res) {
    while ($row = $sys_res->fetch_assoc()) {
        $sys[$row['setting_key']] = $row['setting_value'];
    }
}
$estate_name = $sys['estate_name'] ?? 'EstateAdmin';
$estate_logo = $sys['estate_logo'] ?? '';

$active_role = $_GET['role'] ?? 'admin';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $portal = $_POST['portal'] ?? null;
    
    $result = authenticatePortalUser($conn, $email, $password, $portal);
    if ($result['success']) {
        header("Location: " . $result['redirect']);
        exit;
    } else {
        $error = $result['error'];
        $active_role = $portal ?? $active_role;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - <?php echo htmlspecialchars($estate_name); ?></title>
    
    <!-- Google Fonts: Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Landing & Auth Styles -->
    <link rel="stylesheet" href="css/landing.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/estate_notifications.css?v=<?php echo time(); ?>">
    <script src="js/estate_notifications.js?v=<?php echo time(); ?>"></script>
</head>
<body class="landing-page">
    <div class="auth-page" style="background-image: url('images/hero_estate.jpg');">
        <div class="auth-overlay"></div>
        
        <div class="auth-card">
            <a href="index" class="auth-back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to Estate Home
            </a>
            
            <div class="auth-header">
                <div class="d-flex justify-content-center mb-3">
                    <?php if ($estate_logo): ?>
                        <img src="<?php echo htmlspecialchars($estate_logo); ?>" alt="Logo" style="height: 48px; border-radius: 8px;">
                    <?php else: ?>
                        <div style="width: 52px; height: 52px; border-radius: 12px; background: rgba(59, 130, 246, 0.15); border: 1px solid rgba(59, 130, 246, 0.3); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: #3b82f6; margin: 0 auto;">
                            <i class="fa-solid fa-building-user"></i>
                        </div>
                    <?php endif; ?>
                </div>
                
                <h2 class="auth-title">Welcome Back</h2>
                <p class="auth-subtitle">Select your portal and sign in to <?php echo htmlspecialchars($estate_name); ?></p>
            </div>

            <!-- Role Selector Pills -->
            <div class="role-pills">
                <a href="?role=admin" class="role-pill pill-admin <?php echo ($active_role === 'admin') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-shield-halved me-1"></i> Central
                </a>
                <a href="zone/login" class="role-pill pill-zone <?php echo ($active_role === 'zone') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-layer-group me-1"></i> Zone
                </a>
                <a href="resident/login" class="role-pill pill-resident <?php echo ($active_role === 'resident') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-house-user me-1"></i> Resident
                </a>
                <a href="staff/login" class="role-pill pill-staff <?php echo ($active_role === 'staff') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-user-shield me-1"></i> Staff
                </a>
            </div>

            <?php if ($error): ?>
                <div class="auth-error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="portal" value="<?php echo htmlspecialchars($active_role); ?>">
                
                <div class="auth-input-group">
                    <label for="email">Email Address</label>
                    <div class="auth-input-wrapper">
                        <i class="fa-solid fa-envelope"></i>
                        <input type="email" id="email" name="email" class="auth-input" placeholder="name@domain.com" required autofocus>
                    </div>
                </div>

                <div class="auth-input-group">
                    <label for="password">Password</label>
                    <div class="auth-input-wrapper">
                        <i class="fa-solid fa-lock"></i>
                        <input type="password" id="password" name="password" class="auth-input" placeholder="••••••••" required>
                    </div>
                </div>

                <button type="submit" class="auth-btn auth-btn-admin">
                    <span>Sign In to Admin Hub</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>

            <div style="margin-top: 1.5rem; text-align: center;">
                <span style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.76rem; color: #64748b; background: #f8fafc; border: 1px solid #e2e8f0; padding: 0.35rem 0.75rem; border-radius: 9999px; cursor: pointer; transition: all 0.2s;" onclick="document.getElementById('email').value='admin@admin.com'; document.getElementById('password').value='admin123';" title="Click to auto-fill demo credentials">
                    <i class="fa-solid fa-key" style="color: #6366f1; font-size: 0.7rem;"></i> Demo Admin: <strong style="color: #334155;">admin@admin.com</strong>
                </span>
            </div>
        </div>
    </div>
</body>
</html>
