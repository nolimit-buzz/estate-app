<?php
// staff/login.php - Dedicated Staff & Security Portal Login
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/auth_helper.php';

// If already logged in, redirect
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'staff';
    if (in_array($role, ['staff', 'security', 'accountant', 'technician'])) {
        header("Location: index");
    } elseif ($role === 'resident') {
        header("Location: ../resident/index");
    } else {
        header("Location: ../admin/index");
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
$estate_name = $sys['estate_name'] ?? 'Estate Operations';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $result = authenticatePortalUser($conn, $email, $password, 'staff');
    if ($result['success']) {
        header("Location: " . (in_array($result['role'], ['staff', 'security', 'accountant', 'technician']) ? 'index' : '../' . $result['redirect']));
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
    <title>Staff & Security Sign In - <?php echo htmlspecialchars($estate_name); ?></title>
    
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
        
        <div class="auth-card" style="border-color: rgba(16, 185, 129, 0.25); box-shadow: 0 25px 50px -12px rgba(5, 150, 105, 0.25);">
            <a href="../index" class="auth-back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to Estate Home
            </a>
            
            <div class="auth-header">
                <div class="auth-badge badge-staff">
                    <i class="fa-solid fa-shield-halved"></i> Staff & Security Console
                </div>
                
                <h2 class="auth-title">Staff Sign In</h2>
                <p class="auth-subtitle">Gate security pass verification and maintenance operations</p>
            </div>

            <?php if ($error): ?>
                <div class="auth-error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="auth-input-group">
                    <label for="email">Staff / Officer Email</label>
                    <div class="auth-input-wrapper">
                        <i class="fa-solid fa-user-shield"></i>
                        <input type="email" id="email" name="email" class="auth-input" placeholder="e.g. paul@estate.com" required autofocus>
                    </div>
                </div>

                <div class="auth-input-group">
                    <label for="password">Password</label>
                    <div class="auth-input-wrapper">
                        <i class="fa-solid fa-lock"></i>
                        <input type="password" id="password" name="password" class="auth-input" placeholder="••••••••" required>
                    </div>
                </div>

                <button type="submit" class="auth-btn auth-btn-staff">
                    <span>Access Security Console</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>

            <div class="auth-switcher">
                Estate Resident? <a href="../resident/login" style="color: #38bdf8;">Resident Portal</a><br>
                Estate Administrator? <a href="../login" style="color: #f59e0b;">Admin Login</a>
            </div>

            <div style="margin-top: 1.25rem; text-align: center; font-size: 0.8rem; color: #64748b;">
                Demo Staff: paul@estate.com / admin123
            </div>
        </div>
    </div>
</body>
</html>
