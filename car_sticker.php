<?php
// car_sticker.php - Official Resident Vehicle Sticker Generator
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/vehicle_helper.php';

requireLogin();

$estate_id = get_estate_id();
$user_id = intval($_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['role'] ?? 'resident';

// Fetch Vehicle ID or search query
$vehicle_id = intval($_GET['id'] ?? 0);
$query = trim($_GET['search'] ?? ($_GET['plate'] ?? ($_GET['code'] ?? '')));

$dossier = null;
if ($vehicle_id > 0) {
    $dossier = getVehicleDossier($conn, $estate_id, (string)$vehicle_id);
} elseif (!empty($query)) {
    $dossier = getVehicleDossier($conn, $estate_id, $query);
} else {
    // If resident logged in, get their first vehicle by default
    if ($user_role === 'resident') {
        $my_res = $conn->query("SELECT r.flat_id FROM residents r WHERE r.user_id = $user_id AND r.estate_id = $estate_id LIMIT 1");
        if ($my_res && $mrow = $my_res->fetch_assoc()) {
            $my_flat_id = intval($mrow['flat_id']);
            $v_res = $conn->query("SELECT id FROM vehicles WHERE flat_id = $my_flat_id AND estate_id = $estate_id ORDER BY id DESC LIMIT 1");
            if ($v_res && $vrow = $v_res->fetch_assoc()) {
                $dossier = getVehicleDossier($conn, $estate_id, (string)$vrow['id']);
            }
        }
    }
}

if (!$dossier) {
    die("
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <title>Vehicle Sticker Not Found</title>
        <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'>
        <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>
    </head>
    <body class='bg-light d-flex align-items-center justify-content-center min-vh-100'>
        <div class='card shadow-sm p-4 text-center' style='max-width: 480px; border-radius: 1rem;'>
            <div class='text-muted mb-3'><i class='fa-solid fa-car-tunnel fa-3x text-secondary'></i></div>
            <h4 class='fw-bold text-slate-800 mb-2'>Vehicle Sticker Not Found</h4>
            <p class='text-secondary small mb-4'>No registered vehicle record was found matching your request. Please ensure the vehicle has been registered under a flat unit.</p>
            <div>
                <a href='javascript:history.back()' class='btn btn-outline-secondary rounded-pill px-4 me-2'>Go Back</a>
                <a href='staff/security' class='btn btn-primary rounded-pill px-4'>Gate Control</a>
            </div>
        </div>
    </body>
    </html>");
}

// Fetch Estate System Settings
$settings_res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE estate_id = $estate_id");
$sys = [];
if ($settings_res) {
    while ($s = $settings_res->fetch_assoc()) {
        $sys[$s['setting_key']] = $s['setting_value'];
    }
}

$estate_name = !empty($sys['estate_name']) ? $sys['estate_name'] : 'Estate Administrative Office';
$estate_motto = !empty($sys['estate_motto']) ? $sys['estate_motto'] : 'Excellence in Living';
$estate_logo = !empty($sys['estate_logo']) ? $sys['estate_logo'] : '';

// Active Template Style: 'emerald', 'amber', or 'crimson'
$template_style = $_GET['style'] ?? 'emerald';
if (!in_array($template_style, ['emerald', 'amber', 'crimson'])) {
    $template_style = 'emerald';
}

// Sticker details
$car_id = htmlspecialchars($dossier['custom_id'] ?? ('VEH-' . str_pad($dossier['id'], 5, '0', STR_PAD_LEFT)));
$plate_no = htmlspecialchars($dossier['reg_number']);
$model_name = htmlspecialchars($dossier['model'] ?? 'Standard Vehicle');
$color_name = htmlspecialchars($dossier['color'] ?? 'Vehicle');
$flat_num = htmlspecialchars($dossier['flat_number'] ?? 'N/A');
$bldg_name = htmlspecialchars($dossier['building_name'] ?? 'Main Block');
$street_name = htmlspecialchars($dossier['street_name'] ?? 'Central Ave');
$zone_name = htmlspecialchars($dossier['zone_name'] ?? 'Zone 1');
$owner_name = htmlspecialchars($dossier['owner']['name'] ?? 'Registered Resident');
$owner_phone = htmlspecialchars($dossier['owner']['phone'] ?? 'N/A');
$owner_id = htmlspecialchars($dossier['owner']['custom_id'] ?? 'RES-00000');
$status = htmlspecialchars($dossier['sticker_status'] ?? 'active');

$issue_year = date('Y');
$valid_year = date('Y', strtotime('+1 year'));
$validity_text = "VALID $issue_year - $valid_year";

// Verification URL for QR code
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$verify_url = $protocol . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . "/staff/security?search_vehicle=" . urlencode($dossier['reg_number']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Access Sticker - <?= $car_id ?> (<?= $plate_no ?>)</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@600;700;800&family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@700;800&family=Montserrat:wght@700;800;900&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --sticker-width: 900px;
            --sticker-height: 290px;
        }

        body {
            background-color: #0f172a;
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: #f8fafc;
            min-height: 100vh;
            margin: 0;
            padding: 2rem 1rem;
        }

        .action-toolbar {
            max-width: var(--sticker-width);
            margin: 0 auto 1.5rem auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .style-selector-btn {
            padding: 0.45rem 1rem;
            border-radius: 9999px;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            transition: all 0.2s ease;
            border: 2px solid transparent;
        }

        .style-selector-btn.style-emerald {
            background: #0f766e;
            color: white;
        }
        .style-selector-btn.style-amber {
            background: #d97706;
            color: white;
        }
        .style-selector-btn.style-crimson {
            background: #e11d48;
            color: white;
        }
        .style-selector-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            color: white;
        }
        .style-selector-btn.active {
            border-color: #ffffff;
            box-shadow: 0 0 0 3px rgba(255,255,255,0.3);
        }

        /* STICKER CONTAINER */
        .sticker-stage-wrap {
            max-width: var(--sticker-width);
            margin: 0 auto;
            position: relative;
        }

        .car-sticker-card {
            width: 100%;
            height: var(--sticker-height);
            border-radius: 12px;
            position: relative;
            overflow: hidden;
            display: flex;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.65), 0 0 0 1px rgba(255, 255, 255, 0.1);
            user-select: none;
        }

        /* ==========================================================
           TEMPLATE 1: EMERALD METALLIC
           Metallic brushed silver background + Halftone dot matrix + 
           Teal/Emerald grunge brush splatter + Fine precision lines
           ========================================================== */
        .sticker-theme-emerald {
            background: linear-gradient(135deg, #cbd5e1 0%, #e2e8f0 25%, #94a3b8 50%, #e2e8f0 75%, #cbd5e1 100%);
            color: #0f172a;
        }
        .sticker-theme-emerald::before {
            content: '';
            position: absolute;
            top: 0; left: 0; width: 60%; height: 100%;
            background-image: radial-gradient(circle, rgba(15, 23, 42, 0.22) 1.5px, transparent 1.5px);
            background-size: 14px 14px;
            pointer-events: none;
            opacity: 0.75;
            mask-image: linear-gradient(to right, rgba(0,0,0,1) 40%, rgba(0,0,0,0) 100%);
            -webkit-mask-image: linear-gradient(to right, rgba(0,0,0,1) 40%, rgba(0,0,0,0) 100%);
        }
        .sticker-theme-emerald .grunge-art {
            position: absolute;
            right: -20px;
            top: -20px;
            bottom: -20px;
            width: 46%;
            background: radial-gradient(circle at 65% 50%, #0d9488 0%, #042f2e 100%);
            clip-path: polygon(25% 0%, 100% 0%, 100% 100%, 8% 100%, 35% 72%, 18% 50%, 38% 28%);
            opacity: 0.96;
            filter: drop-shadow(-8px 0 15px rgba(13, 148, 136, 0.4));
        }
        .sticker-theme-emerald .grunge-art-splatter {
            position: absolute;
            right: 32%;
            top: 15%;
            width: 90px;
            height: 90px;
            background: #0f766e;
            clip-path: polygon(50% 0%, 65% 35%, 100% 50%, 70% 70%, 80% 100%, 40% 75%, 0% 85%, 25% 45%);
            opacity: 0.85;
            transform: rotate(25deg);
        }
        .sticker-theme-emerald .grunge-art-lines {
            position: absolute;
            right: 42%;
            top: 0;
            bottom: 0;
            width: 28px;
            display: flex;
            gap: 5px;
        }
        .sticker-theme-emerald .grunge-art-lines span {
            width: 3px;
            height: 100%;
            background: rgba(15, 23, 42, 0.35);
        }
        .sticker-theme-emerald .grunge-art-lines span:nth-child(2) {
            background: rgba(185, 28, 28, 0.7);
            width: 4px;
        }

        /* ==========================================================
           TEMPLATE 2: AMBER CARBON GEOMETRIC
           City architectural silhouette + Bold diagonal amber cuts +
           Dot halftone matrix + Modern industrial technical aesthetic
           ========================================================== */
        .sticker-theme-amber {
            background: #f1f5f9;
            color: #0f172a;
        }
        .sticker-theme-amber .cityscape-backdrop {
            position: absolute;
            left: 0;
            top: 0;
            width: 48%;
            height: 100%;
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.85), rgba(30, 41, 59, 0.75)), 
                        radial-gradient(ellipse at center, #475569 0%, #0f172a 100%);
            clip-path: polygon(0 0, 85% 0, 60% 100%, 0 100%);
            overflow: hidden;
        }
        .sticker-theme-amber .cityscape-backdrop::after {
            content: '';
            position: absolute;
            inset: 0;
            background-image: linear-gradient(0deg, rgba(245, 158, 11, 0.25) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(245, 158, 11, 0.25) 1px, transparent 1px);
            background-size: 20px 20px;
            opacity: 0.6;
        }
        .sticker-theme-amber .amber-bars {
            position: absolute;
            left: 0;
            top: 0;
            width: 52%;
            height: 100%;
            pointer-events: none;
        }
        .sticker-theme-amber .amber-bars .bar-1 {
            position: absolute;
            left: 0; top: 0;
            width: 140px; height: 110px;
            background: #f59e0b;
            clip-path: polygon(0 0, 100% 0, 40% 100%, 0 100%);
        }
        .sticker-theme-amber .amber-bars .bar-2 {
            position: absolute;
            left: 20px; top: 30px;
            width: 260px; height: 16px;
            background: #d97706;
            transform: rotate(-25deg);
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
        }
        .sticker-theme-amber .amber-bars .bar-3 {
            position: absolute;
            left: 70px; top: 120px;
            width: 290px; height: 22px;
            background: #fbbf24;
            transform: rotate(-25deg);
        }
        .sticker-theme-amber .amber-bars .bar-4 {
            position: absolute;
            left: -20px; bottom: -20px;
            width: 180px; height: 80px;
            background: #b45309;
            transform: rotate(-25deg);
        }
        .sticker-theme-amber::before {
            content: '';
            position: absolute;
            top: 0; right: 25%; width: 45%; height: 100%;
            background-image: radial-gradient(circle, rgba(15, 23, 42, 0.2) 1.5px, transparent 1.5px);
            background-size: 14px 14px;
            pointer-events: none;
            opacity: 0.6;
            mask-image: radial-gradient(circle, rgba(0,0,0,1) 30%, rgba(0,0,0,0) 80%);
            -webkit-mask-image: radial-gradient(circle, rgba(0,0,0,1) 30%, rgba(0,0,0,0) 80%);
        }

        /* ==========================================================
           TEMPLATE 3: CRIMSON MINIMALIST
           Pure white background + Fiery vermillion brush stroke on left +
           Charcoal vertical precision lines + Dot halftone on right
           ========================================================== */
        .sticker-theme-crimson {
            background: #ffffff;
            color: #0f172a;
        }
        .sticker-theme-crimson .crimson-brush {
            position: absolute;
            left: -30px;
            top: -20px;
            bottom: -20px;
            width: 44%;
            background: radial-gradient(circle at 40% 50%, #ef4444 0%, #b91c1c 100%);
            clip-path: polygon(0 0, 75% 0, 95% 42%, 70% 68%, 88% 100%, 0 100%);
            filter: drop-shadow(8px 0 15px rgba(225, 29, 72, 0.35));
        }
        .sticker-theme-crimson .crimson-lines {
            position: absolute;
            left: 36%;
            top: 0;
            bottom: 0;
            width: 32px;
            display: flex;
            gap: 6px;
        }
        .sticker-theme-crimson .crimson-lines span {
            width: 3px;
            height: 100%;
            background: rgba(15, 23, 42, 0.3);
        }
        .sticker-theme-crimson .crimson-lines span:first-child {
            width: 4px;
            background: #991b1b;
        }
        .sticker-theme-crimson::before {
            content: '';
            position: absolute;
            top: 0; right: 0; width: 55%; height: 100%;
            background-image: radial-gradient(circle, rgba(15, 23, 42, 0.18) 1.5px, transparent 1.5px);
            background-size: 14px 14px;
            pointer-events: none;
            opacity: 0.65;
            mask-image: linear-gradient(to left, rgba(0,0,0,1) 40%, rgba(0,0,0,0) 100%);
            -webkit-mask-image: linear-gradient(to left, rgba(0,0,0,1) 40%, rgba(0,0,0,0) 100%);
        }

        /* CONTENT INNER LAYOUT */
        .sticker-content-inner {
            position: relative;
            z-index: 10;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 1.4rem 2rem;
            box-sizing: border-box;
        }

        .sticker-theme-emerald .sticker-content-inner {
            padding-right: 32%;
        }
        .sticker-theme-amber .sticker-content-inner {
            padding-left: 38%;
        }
        .sticker-theme-crimson .sticker-content-inner {
            padding-left: 36%;
        }

        .sticker-top-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }

        .estate-brand-wrap {
            display: flex;
            align-items: center;
            gap: 0.85rem;
        }

        .estate-logo-img {
            width: 52px;
            height: 52px;
            object-fit: cover;
            border-radius: 8px;
            background: white;
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
            padding: 2px;
            border: 1px solid rgba(0,0,0,0.1);
        }

        .estate-logo-fallback {
            width: 52px;
            height: 52px;
            border-radius: 8px;
            background: #0f172a;
            color: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }

        .estate-text-info {
            line-height: 1.15;
        }
        .estate-title {
            font-family: 'Montserrat', sans-serif;
            font-size: 1.18rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: -0.01em;
            color: #0f172a;
            margin: 0;
        }
        .estate-motto-tag {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 700;
            color: #475569;
            margin-top: 3px;
        }

        .security-stamp-badge {
            background: #0f172a;
            color: white;
            padding: 0.35rem 0.85rem;
            border-radius: 6px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.82rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
            white-space: nowrap;
        }

        /* MAIN PLATE & HEADLINE SECTION */
        .sticker-center-body {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 0.4rem 0;
            gap: 1.5rem;
        }

        .plate-container {
            display: flex;
            flex-direction: column;
        }

        .plate-badge-styled {
            display: inline-flex;
            align-items: center;
            font-family: 'Chakra Petch', sans-serif;
            font-size: 2.25rem;
            font-weight: 800;
            letter-spacing: 0.06em;
            color: #0f172a;
            line-height: 1;
            text-transform: uppercase;
            text-shadow: 0 1px 2px rgba(255,255,255,0.8);
        }

        .vehicle-specs-line {
            font-size: 0.88rem;
            font-weight: 700;
            color: #334155;
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .vehicle-residence-line {
            font-size: 0.82rem;
            font-weight: 600;
            color: #475569;
            margin-top: 0.15rem;
        }

        /* QR CODE WRAPPER */
        .qr-seal-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: white;
            padding: 8px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border: 1px solid rgba(0,0,0,0.1);
            text-align: center;
        }

        .qr-seal-wrap img {
            width: 76px;
            height: 76px;
            display: block;
        }
        .qr-scan-label {
            font-size: 0.58rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin-top: 3px;
        }

        /* FOOTER STRIP */
        .sticker-bottom-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid rgba(15, 23, 42, 0.15);
            padding-top: 0.4rem;
            font-size: 0.74rem;
            font-weight: 700;
            color: #334155;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .hologram-strip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: linear-gradient(90deg, #ec4899, #8b5cf6, #3b82f6, #10b981, #f59e0b);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 900;
            font-size: 0.78rem;
        }

        /* DOSSIER SUMMARY CARD BELOW STICKER */
        .dossier-card {
            background: #1e293b;
            border-radius: 12px;
            border: 1px solid #334155;
            padding: 1.5rem;
            margin-top: 1.5rem;
        }

        /* PRINT STYLES */
        @media print {
            body {
                background: white !important;
                color: black !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .action-toolbar, .dossier-card, .btn, .no-print {
                display: none !important;
            }
            .sticker-stage-wrap {
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .car-sticker-card {
                box-shadow: none !important;
                border: 1px solid #ccc !important;
                page-break-inside: avoid;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                width: 100% !important;
                max-width: 900px !important;
                margin: 1cm auto !important;
            }
        }
    </style>
</head>
<body>

    <!-- ACTION TOOLBAR -->
    <div class="action-toolbar">
        <div>
            <a href="staff/security?search_vehicle=<?= urlencode($plate_no) ?>" class="btn btn-sm btn-outline-light rounded-pill px-3 me-2">
                <i class="fa-solid fa-arrow-left me-1"></i> Gate Security
            </a>
            <span class="text-slate-400 small me-2">Template Style:</span>
            <div class="btn-group" role="group">
                <a href="?id=<?= $dossier['id'] ?>&style=emerald" class="style-selector-btn style-emerald <?= $template_style === 'emerald' ? 'active' : '' ?>">
                    <i class="fa-solid fa-gem"></i> Emerald Chrome
                </a>
                <a href="?id=<?= $dossier['id'] ?>&style=amber" class="style-selector-btn style-amber <?= $template_style === 'amber' ? 'active' : '' ?>">
                    <i class="fa-solid fa-city"></i> Amber Carbon
                </a>
                <a href="?id=<?= $dossier['id'] ?>&style=crimson" class="style-selector-btn style-crimson <?= $template_style === 'crimson' ? 'active' : '' ?>">
                    <i class="fa-solid fa-paintbrush"></i> Crimson Crisp
                </a>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2">
            <button onclick="window.print()" class="btn btn-sm btn-success rounded-pill px-4 fw-bold shadow-sm">
                <i class="fa-solid fa-print me-1"></i> Print Sticker
            </button>
            <button onclick="downloadStickerAsImage()" class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-download me-1"></i> Download PNG
            </button>
        </div>
    </div>

    <!-- STICKER STAGE -->
    <div class="sticker-stage-wrap">
        <div id="captureSticker" class="car-sticker-card sticker-theme-<?= $template_style ?>">

            <!-- TEMPLATE BACKGROUND EMBELLISHMENTS -->
            <?php if ($template_style === 'emerald'): ?>
                <div class="grunge-art"></div>
                <div class="grunge-art-splatter"></div>
                <div class="grunge-art-lines">
                    <span></span><span></span><span></span>
                </div>
            <?php elseif ($template_style === 'amber'): ?>
                <div class="cityscape-backdrop"></div>
                <div class="amber-bars">
                    <div class="bar-1"></div>
                    <div class="bar-2"></div>
                    <div class="bar-3"></div>
                    <div class="bar-4"></div>
                </div>
            <?php elseif ($template_style === 'crimson'): ?>
                <div class="crimson-brush"></div>
                <div class="crimson-lines">
                    <span></span><span></span><span></span>
                </div>
            <?php endif; ?>

            <!-- INNER STICKER CONTENT -->
            <div class="sticker-content-inner">
                
                <!-- TOP ROW: ESTATE LOGO, NAME, CAR ID BADGE -->
                <div class="sticker-top-row">
                    <div class="estate-brand-wrap">
                        <?php if (!empty($estate_logo)): ?>
                            <img src="<?= htmlspecialchars($estate_logo) ?>" class="estate-logo-img" alt="Estate Logo" onerror="this.style.display='none'; document.getElementById('fallback_logo').style.display='flex';">
                            <div id="fallback_logo" class="estate-logo-fallback" style="display: none;">
                                <i class="fa-solid fa-shield-halved"></i>
                            </div>
                        <?php else: ?>
                            <div class="estate-logo-fallback">
                                <i class="fa-solid fa-shield-halved"></i>
                            </div>
                        <?php endif; ?>

                        <div class="estate-text-info">
                            <h2 class="estate-title"><?= htmlspecialchars($estate_name) ?></h2>
                            <div class="estate-motto-tag"><i class="fa-solid fa-quote-left me-1 opacity-50"></i><?= htmlspecialchars($estate_motto) ?></div>
                        </div>
                    </div>

                    <div class="security-stamp-badge">
                        <i class="fa-solid fa-fingerprint text-warning"></i>
                        <span>CAR ID: <?= $car_id ?></span>
                    </div>
                </div>

                <!-- CENTER BODY: REG NUMBER, VEHICLE SPECS & SCANNABLE QR CODE -->
                <div class="sticker-center-body">
                    <div class="plate-container">
                        <div class="plate-badge-styled">
                            <i class="fa-solid fa-car me-2" style="font-size: 1.5rem; opacity: 0.7;"></i>
                            <?= $plate_no ?>
                        </div>
                        <div class="vehicle-specs-line">
                            <span><?= $model_name ?></span>
                            <span>&bull;</span>
                            <span><?= $color_name ?></span>
                            <span>&bull;</span>
                            <span class="text-uppercase"><?= htmlspecialchars($dossier['type'] ?? 'Car') ?></span>
                        </div>
                        <div class="vehicle-residence-line">
                            <i class="fa-solid fa-location-dot me-1 text-danger"></i>
                            Flat <?= $flat_num ?> &bull; <?= $bldg_name ?> &bull; <?= $street_name ?> (<?= $zone_name ?>)
                        </div>
                    </div>

                    <div class="qr-seal-wrap">
                        <?php 
                        // Google Charts QR Code API for crisp instant QR code rendering
                        $qr_api_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&margin=0&data=" . urlencode($verify_url);
                        ?>
                        <img src="<?= $qr_api_url ?>" alt="Verification QR Code">
                        <div class="qr-scan-label">GATE SCAN</div>
                    </div>
                </div>

                <!-- BOTTOM STRIP: VALIDITY & SECURITY HOLOGRAM -->
                <div class="sticker-bottom-footer">
                    <div>
                        <span class="badge bg-dark text-white me-2 px-2 py-1"><?= $validity_text ?></span>
                        <span>RESIDENT: <strong><?= $owner_name ?></strong> (<?= $owner_id ?>)</span>
                    </div>
                    <div class="hologram-strip">
                        <i class="fa-solid fa-certificate"></i>
                        <span>OFFICIAL ESTATE VEHICLE CLEARANCE PASS</span>
                    </div>
                </div>

            </div>

        </div>
    </div>

    <!-- DOSSIER SPECIFICATIONS DETAILS BELOW -->
    <div class="sticker-stage-wrap">
        <div class="dossier-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-white m-0">
                    <i class="fa-solid fa-shield-check text-success me-2"></i> Security Clearance Dossier: <?= $plate_no ?>
                </h5>
                <span class="badge bg-success rounded-pill px-3 py-1 font-monospace">STATUS: ACTIVE CLEARANCE</span>
            </div>

            <div class="row g-3">
                <div class="col-md-3">
                    <div class="text-slate-400 small text-uppercase fw-semibold">Car &amp; Sticker ID</div>
                    <div class="text-white fw-bold fs-5 font-monospace"><?= $car_id ?></div>
                    <small class="text-slate-400">Database Ref: #<?= $dossier['id'] ?></small>
                </div>
                <div class="col-md-3">
                    <div class="text-slate-400 small text-uppercase fw-semibold">Vehicle Specs</div>
                    <div class="text-white fw-semibold"><?= $model_name ?></div>
                    <small class="text-slate-400">Color: <?= $color_name ?> | Type: <?= ucfirst($dossier['type']) ?></small>
                </div>
                <div class="col-md-3">
                    <div class="text-slate-400 small text-uppercase fw-semibold">Registered Host &amp; Contact</div>
                    <div class="text-white fw-semibold"><?= $owner_name ?></div>
                    <div class="small text-slate-300"><i class="fa-solid fa-phone text-success me-1"></i><a href="tel:<?= $owner_phone ?>" class="text-info text-decoration-none"><?= $owner_phone ?></a></div>
                </div>
                <div class="col-md-3">
                    <div class="text-slate-400 small text-uppercase fw-semibold">Allocated Residence</div>
                    <div class="text-white fw-semibold">Flat <?= $flat_num ?>, <?= $bldg_name ?></div>
                    <small class="text-slate-400"><?= $street_name ?> &bull; <?= $zone_name ?></small>
                </div>
            </div>

            <?php if (!empty($dossier['recent_gate_logs'])): ?>
                <div class="mt-4 pt-3 border-top border-secondary border-opacity-25">
                    <h6 class="text-slate-300 small fw-bold text-uppercase mb-2"><i class="fa-solid fa-clock-rotate-left me-1"></i> Recent Gate Movements</h6>
                    <div class="table-responsive">
                        <table class="table table-dark table-sm table-borderless small mb-0">
                            <thead>
                                <tr class="text-slate-400">
                                    <th>Direction</th>
                                    <th>Gate Post</th>
                                    <th>Shift / Officer</th>
                                    <th>Date &amp; Time</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dossier['recent_gate_logs'] as $log): ?>
                                    <tr>
                                        <td>
                                            <?php if ($log['direction'] === 'entry'): ?>
                                                <span class="badge bg-success bg-opacity-25 text-success">ENTRY</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger bg-opacity-25 text-danger">EXIT</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($log['gate_name']) ?></td>
                                        <td><?= htmlspecialchars($log['shift_name'] ?? 'Duty') ?> (<?= htmlspecialchars($log['officer_name'] ?? 'Guard') ?>)</td>
                                        <td><?= date('M d, Y h:i A', strtotime($log['logged_at'])) ?></td>
                                        <td><?= htmlspecialchars($log['notes'] ?: '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- HTML2CANVAS SCRIPT FOR DOWNLOAD -->
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <script>
        function downloadStickerAsImage() {
            const card = document.getElementById('captureSticker');
            html2canvas(card, {
                scale: 3, // High DPI export
                useCORS: true,
                backgroundColor: null
            }).then(canvas => {
                const link = document.createElement('a');
                link.download = 'car_sticker_<?= $car_id ?>_<?= $plate_no ?>.png';
                link.href = canvas.toDataURL('image/png');
                link.click();
            });
        }
    </script>
</body>
</html>
