<?php
// login.php - Universal & Admin Portal Login
require_once 'config.php';
require_once 'includes/auth_guard.php';
require_once 'includes/auth_helper.php';
require_once 'includes/login_gate.php'; // asks for the estate code when the estate is not identified

// If already logged in, redirect to respective dashboard
if (isset($_SESSION['user_id']) && intval($_SESSION['estate_id'] ?? 0) === Tenancy::currentId()) {
    if (isset($conn)) {
        $uid = intval($_SESSION['user_id']);
        $r_chk = $conn->query("SELECT role FROM users WHERE id = $uid LIMIT 1");
        if ($r_chk && $r_row = $r_chk->fetch_assoc()) {
            $_SESSION['role'] = $r_row['role'];
        }
    }

    $role = $_SESSION['role'] ?? 'resident';
    if (in_array($role, ['admin', 'manager'])) {
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
if (isset($_GET['error']) && $_GET['error'] === 'account_disabled') {
    $error = "Your account has been deactivated. Please contact Estate Central Administration.";
}

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
    <link rel="stylesheet" href="css/landing.css?v=<?php echo ASSET_VER; ?>">
    <link rel="stylesheet" href="css/estate_notifications.css?v=<?php echo ASSET_VER; ?>">
    <script src="js/estate_notifications.js?v=<?php echo ASSET_VER; ?>"></script>
    <link rel="stylesheet" href="css/dashboard-theme-public.css?v=<?php echo defined('ASSET_VER') ? ASSET_VER : time(); ?>">
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
                        <div class="glass-icon-circle hero-icon-circle glass-icon-blue" style="width: 54px; height: 54px; min-width: 54px; min-height: 54px; font-size: 1.45rem; margin: 0 auto;">
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

            <?php if ($flash_message): ?>
                <div class="auth-error" style="background: rgba(16, 185, 129, 0.15); border-color: rgba(16, 185, 129, 0.3); color: #34d399;">
                    <i class="fa-solid fa-circle-check"></i>
                    <span><?php echo htmlspecialchars($flash_message); ?></span>
                </div>
            <?php endif; ?>

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
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                        <label for="password" style="margin-bottom: 0;">Password</label>
                        <a href="forgot_password" style="font-size: 0.78rem; color: #60a5fa; text-decoration: none; font-weight: 500;">Forgot Password?</a>
                    </div>
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

            <div style="margin-top: 1.25rem; text-align: center; font-size: 0.8rem; color: #94a3b8;">
                Signing in to <strong><?php echo htmlspecialchars($estate_name); ?></strong> &middot; <a href="login?switch_estate=1" style="color:#60a5fa;text-decoration:none;">not your estate?</a>
            </div>

            <!-- Platform Security & Selling Points Banner -->
            <div class="auth-usp-banner">
                <div class="auth-usp-item">
                    <i class="fa-solid fa-lock text-success"></i>
                    <span><strong>Strict RBAC Isolation:</strong> Central &amp; Zonal data boundary guard.</span>
                </div>
                <div class="auth-usp-item">
                    <i class="fa-solid fa-bolt text-warning"></i>
                    <span><strong>Instant Gate Clearance:</strong> Sub-5s QR scan at security gatehouse.</span>
                </div>
                <div class="auth-usp-item">
                    <i class="fa-solid fa-scale-balanced text-info"></i>
                    <span><strong>Audited Ledgers:</strong> 100% verified accounting &amp; instant receipts.</span>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
