<?php
// suspended.php - Tenant Account Suspension / Renewal Notice Screen
require_once 'config.php';

$estate_id = get_estate_id();
$e_info = $conn->query("SELECT name, status, contact_email, contact_phone FROM estates WHERE id = $estate_id LIMIT 1")->fetch_assoc();
$estate_name = $e_info['name'] ?? 'Your Estate Portal';
$status = $e_info['status'] ?? 'suspended';

// If estate is active or user is superadmin, send back to dashboard
if ($status === 'active' || ($_SESSION['role'] ?? '') === 'superadmin') {
    header("Location: admin/index");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Suspended - <?= htmlspecialchars($estate_name) ?></title>
    
    <!-- Google Fonts: Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 & Font Awesome -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            color: #f8fafc;
        }
        .suspension-card {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            max-width: 540px;
            width: 100%;
            padding: 2.5rem;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        .warning-glow {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1.5rem auto;
            border: 2px solid rgba(239, 68, 68, 0.3);
        }
    </style>
</head>
<body>

    <div class="suspension-card">
        <div class="warning-glow">
            <i class="fa-solid fa-lock"></i>
        </div>

        <h3 class="fw-bold mb-2 text-white">Portal Inactive</h3>
        <p class="text-secondary small mb-4" style="color: #94a3b8 !important;">
            Access to <strong><?= htmlspecialchars($estate_name) ?></strong> is temporarily unavailable.
        </p>

        <div class="p-3 bg-dark bg-opacity-50 rounded-4 border border-secondary border-opacity-25 text-start mb-4">
            <div class="d-flex align-items-center gap-2 mb-2 text-warning small fw-bold">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>Subscription Renewal Required</span>
            </div>
            <p class="small text-slate-300 mb-0" style="color: #cbd5e1; font-size: 0.82rem; line-height: 1.4;">
                This estate's software package subscription has lapsed or requires administrative maintenance. Estate executive committees and management representatives can reactivate full access immediately.
            </p>
        </div>

        <?php if (!empty($e_info['contact_email']) || !empty($e_info['contact_phone'])): ?>
            <div class="small text-secondary mb-4" style="color: #94a3b8 !important;">
                Estate Contact: <?= htmlspecialchars($e_info['contact_email'] ?? '') ?> 
                <?= !empty($e_info['contact_phone']) ? ' &bull; ' . htmlspecialchars($e_info['contact_phone']) : '' ?>
            </div>
        <?php endif; ?>

        <div class="d-flex gap-2 justify-content-center">
            <a href="logout" class="btn btn-outline-light rounded-pill px-4 fw-semibold btn-sm">
                <i class="fa-solid fa-right-from-bracket me-1"></i> Sign Out
            </a>
            <a href="mailto:support@estatehq.com?subject=Reactivate%20Estate%20<?= urlencode($estate_name) ?>" class="btn btn-primary rounded-pill px-4 fw-bold btn-sm shadow-sm">
                <i class="fa-solid fa-headset me-1"></i> Contact Platform Billing
            </a>
        </div>
    </div>

</body>
</html>
