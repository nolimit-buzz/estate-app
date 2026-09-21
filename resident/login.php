<?php
// resident/login.php - Dedicated Resident Portal Login
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/auth_helper.php';

// If already logged in, redirect
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'resident';
    if ($role === 'resident' || in_array($role, ['admin', 'manager', 'superadmin'])) {
        header("Location: index");
    } else {
        header("Location: ../staff/index");
    }
    exit;
}

// Fetch Estate Details for branding
$estate_id = get_estate_id();
$sys_res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE estate_id = $estate_id");
$sys = [];
if ($sys_res) {
    while ($row = $sys_res->fetch_assoc()) {
        $sys[$row['setting_key']] = $row['setting_value'];
    }
}
$estate_name = $sys['estate_name'] ?? 'Resident Portal';
$estate_logo = $sys['estate_logo'] ?? '';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $result = authenticatePortalUser($conn, $email, $password, 'resident');
    if ($result['success']) {
        header("Location: index");
        exit;
    } else {
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resident Sign In - <?php echo htmlspecialchars($estate_name); ?></title>
    
    <!-- Google Fonts: Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Landing & Auth Styles -->
    <link rel="stylesheet" href="../css/landing.css?v=<?php echo time(); ?>">
</head>
<body class="landing-page">
    <div class="auth-page" style="background-image: url('../images/hero_estate.jpg');">
        <div class="auth-overlay"></div>
        
        <div class="auth-card" style="border-color: rgba(56, 189, 248, 0.25); box-shadow: 0 25px 50px -12px rgba(2, 132, 199, 0.25);">
            <a href="../index" class="auth-back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to Estate Home
            </a>
            
            <div class="auth-header">
                <div class="d-flex justify-content-center mb-3">
                    <?php if ($estate_logo): ?>
                        <img src="../<?php echo htmlspecialchars($estate_logo); ?>" alt="Logo" style="height: 48px; border-radius: 8px;">
                    <?php else: ?>
                        <div style="width: 52px; height: 52px; border-radius: 12px; background: rgba(56, 189, 248, 0.15); border: 1px solid rgba(56, 189, 248, 0.3); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: #38bdf8; margin: 0 auto;">
                            <i class="fa-solid fa-house-user"></i>
                        </div>
                    <?php endif; ?>
                </div>
                
                <h2 class="auth-title">Welcome Back</h2>
                <p class="auth-subtitle">Select your portal and sign in to <?php echo htmlspecialchars($estate_name); ?></p>
            </div>

            <!-- Role Selector Pills -->
            <div class="role-pills">
                <a href="../login?role=admin" class="role-pill pill-admin">
                    <i class="fa-solid fa-shield-halved me-1"></i> Central
                </a>
                <a href="../zone/login" class="role-pill pill-zone">
                    <i class="fa-solid fa-layer-group me-1"></i> Zone
                </a>
                <a href="login" class="role-pill pill-resident active">
                    <i class="fa-solid fa-house-user me-1"></i> Resident
                </a>
                <a href="../staff/login" class="role-pill pill-staff">
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
                <div class="auth-input-group">
                    <label for="email">Resident Email Address</label>
                    <div class="auth-input-wrapper">
                        <i class="fa-solid fa-envelope"></i>
                        <input type="email" id="email" name="email" class="auth-input" placeholder="e.g. john@example.com" required autofocus>
                    </div>
                </div>

                <div class="auth-input-group">
                    <label for="password">Password</label>
                    <div class="auth-input-wrapper">
                        <i class="fa-solid fa-lock"></i>
                        <input type="password" id="password" name="password" class="auth-input" placeholder="••••••••" required>
                    </div>
                </div>

                <button type="submit" class="auth-btn auth-btn-resident">
                    <span>Sign In to Resident Portal</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>

            <div style="margin-top: 1.5rem; text-align: center; font-size: 0.8rem; color: #64748b;">
                Demo Resident: john@example.com / admin123
            </div>
        </div>
    </div>
</body>
</html>
