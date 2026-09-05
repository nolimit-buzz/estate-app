<?php
// gate_pass.php - Official Printable Visitor Gate Pass
require_once __DIR__ . '/config.php';

$estate_id = get_estate_id();

// Retrieve by ID or Code
$pass_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$pass_code = isset($_GET['code']) ? $conn->real_escape_string(trim($_GET['code'])) : '';
$auto_print = isset($_GET['autoprint']) && $_GET['autoprint'] == '1';

if ($pass_id <= 0 && empty($pass_code)) {
    die("<div style='font-family:sans-serif;text-align:center;padding:4rem;'>
            <h2>Gate Pass Error</h2>
            <p>Please provide a valid Visitor Pass ID or Pass Code.</p>
            <a href='index' style='color:#2563eb;'>Return to Homepage</a>
         </div>");
}

$where_clause = ($pass_id > 0) ? "v.id = $pass_id" : "v.visitor_code = '$pass_code'";

// Fetch Full Visitor & Host Details
$query = "SELECT v.*, 
                 u_res.name AS resident_name, 
                 u_res.phone AS resident_phone,
                 u_res.email AS resident_email,
                 f.number AS flat_number, 
                 b.name AS building_name,
                 s.name AS street_name,
                 u_entry.name AS entry_staff_name
          FROM visitors v
          LEFT JOIN users u_res ON v.resident_id = u_res.id
          LEFT JOIN flats f ON v.flat_id = f.id
          LEFT JOIN buildings b ON f.building_id = b.id
          LEFT JOIN streets s ON b.street_id = s.id
          LEFT JOIN users u_entry ON v.entry_processed_by = u_entry.id
          WHERE ($where_clause) AND v.estate_id = $estate_id
          LIMIT 1";

$result = $conn->query($query);
if (!$result || $result->num_rows === 0) {
    die("<div style='font-family:sans-serif;text-align:center;padding:4rem;'>
            <h2>Visitor Pass Not Found</h2>
            <p>The requested gate pass could not be found or has been removed.</p>
            <a href='javascript:history.back()' style='color:#2563eb;'>Go Back</a>
         </div>");
}

$v = $result->fetch_assoc();

// Fetch Estate Branding Settings
$sys = [];
$settings_res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE estate_id = $estate_id");
if ($settings_res) {
    while ($row = $settings_res->fetch_assoc()) {
        $sys[$row['setting_key']] = $row['setting_value'];
    }
}
$estate_name = !empty($sys['estate_name']) ? $sys['estate_name'] : 'No Limit Buzz';
$estate_motto = !empty($sys['estate_motto']) ? $sys['estate_motto'] : 'Excellence In Living & Secure Community';
$estate_logo = !empty($sys['estate_logo']) ? $sys['estate_logo'] : '';
$office_phone = !empty($sys['office_phone']) ? $sys['office_phone'] : '0800-ESTATE-SEC';
$office_email = !empty($sys['office_email']) ? $sys['office_email'] : 'security@estate.com';
$estate_address = !empty($sys['estate_location']) ? $sys['estate_location'] : 'Central Security Gatehouse';

// Status labels and styling
$status_key = $v['status'] ?? 'pre_registered';
$status_labels = [
    'pre_registered' => ['label' => 'PRE-REGISTERED', 'bg' => '#e0f2fe', 'color' => '#0369a1', 'border' => '#7dd3fc'],
    'entered' => ['label' => 'CURRENTLY INSIDE', 'bg' => '#fef3c7', 'color' => '#b45309', 'border' => '#fcd34d'],
    'confirmed' => ['label' => 'RESIDENT CONFIRMED', 'bg' => '#dcfce7', 'color' => '#15803d', 'border' => '#86efac'],
    'checked_out' => ['label' => 'CHECKED OUT', 'bg' => '#f1f5f9', 'color' => '#475569', 'border' => '#cbd5e1'],
    'exited_without_confirmation' => ['label' => 'EXITED (UNCONFIRMED)', 'bg' => '#ffedd5', 'color' => '#c2410c', 'border' => '#fdba74'],
    'cancelled' => ['label' => 'CANCELLED', 'bg' => '#fee2e2', 'color' => '#b91c1c', 'border' => '#fca5a5']
];
$status_info = $status_labels[$status_key] ?? ['label' => strtoupper($status_key), 'bg' => '#f1f5f9', 'color' => '#334155', 'border' => '#cbd5e1'];

// Dates formatting
$arrival_time = !empty($v['expected_arrival']) ? date('D, M j, Y \a\t h:i A', strtotime($v['expected_arrival'])) : 'Immediate / Today';
$issue_time = !empty($v['created_at']) ? date('M j, Y - h:i A', strtotime($v['created_at'])) : date('M j, Y - h:i A');

// Expiry: 24h from expected arrival or created_at
$base_time = !empty($v['expected_arrival']) ? strtotime($v['expected_arrival']) : strtotime($v['created_at']);
$expiry_time = date('D, M j, Y \a\t 11:59 PM', $base_time);

// Destination full string
$destination_parts = [];
if (!empty($v['flat_number'])) $destination_parts[] = 'Flat ' . $v['flat_number'];
if (!empty($v['building_name'])) $destination_parts[] = $v['building_name'];
if (!empty($v['street_name'])) $destination_parts[] = $v['street_name'];
$destination_address = !empty($destination_parts) ? implode(', ', $destination_parts) : 'Resident Residence';

// Verification QR URL
$verify_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . 
              rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . "/gate_pass?code=" . urlencode($v['visitor_code']);
$qr_payload = $estate_name . " VISITOR PASS\n" .
              "CODE: " . $v['visitor_code'] . "\n" .
              "GUEST: " . $v['name'] . "\n" .
              "HOST: " . ($v['resident_name'] ?? 'N/A') . "\n" .
              "DEST: " . $destination_address . "\n" .
              "VERIFY: " . $verify_url;
$qr_img_url = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=4&data=" . urlencode($qr_payload);

// Pre-formatted WhatsApp Message text for visitor
$wa_text = urlencode(
    "👋 Hello *" . $v['name'] . "*,\n\n" .
    "Here is your official *Visitor Gate Pass* for *" . $estate_name . "*:\n\n" .
    "🔑 *GATE PASS CODE:* *" . $v['visitor_code'] . "*\n" .
    "📍 *Destination:* " . $destination_address . "\n" .
    "👤 *Host:* " . ($v['resident_name'] ?? 'Resident') . "\n" .
    "⏰ *Valid For:* " . $arrival_time . "\n\n" .
    "Please present this code to the security personnel at the estate entrance gate.\n" .
    "View & Print Pass: " . $verify_url
);
$wa_link = "https://api.whatsapp.com/send?text=" . $wa_text . (!empty($v['phone']) ? "&phone=" . preg_replace('/[^0-9]/', '', $v['phone']) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor Gate Pass - <?= htmlspecialchars($v['name']) ?> (<?= htmlspecialchars($v['visitor_code']) ?>)</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --slate-900: #0f172a;
            --slate-800: #1e293b;
            --slate-700: #334155;
            --slate-500: #64748b;
            --slate-200: #e2e8f0;
            --slate-100: #f1f5f9;
        }

        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Outfit', sans-serif;
            background: #0b1329;
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Top Action / Print Toolbar */
        .print-toolbar {
            position: sticky;
            top: 0;
            width: 100%;
            background: rgba(15, 23, 42, 0.96);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            padding: 0.85rem 1.5rem;
            z-index: 999;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.4);
        }

        .toolbar-group {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            flex-wrap: wrap;
        }

        .btn-toolbar {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.55rem 1.1rem;
            border-radius: 9999px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
            border: 1px solid transparent;
        }

        .btn-primary-action {
            background: #2563eb;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35);
        }
        .btn-primary-action:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
        }

        .btn-whatsapp {
            background: #16a34a;
            color: #ffffff;
        }
        .btn-whatsapp:hover {
            background: #15803d;
            transform: translateY(-1px);
        }

        .btn-secondary-action {
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.2);
        }
        .btn-secondary-action:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .btn-mode-toggle {
            padding: 0.4rem 0.85rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            background: rgba(255, 255, 255, 0.08);
            color: #cbd5e1;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .btn-mode-toggle.active {
            background: #3b82f6;
            color: #ffffff;
            border-color: #3b82f6;
        }

        /* Pass Stage Container */
        .pass-stage {
            padding: 2.5rem 1rem;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            width: 100%;
        }

        /* Standard Slip / Badge Pass Card (Default) */
        .gate-pass-card {
            width: 500px;
            max-width: 100%;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            border: 1px solid #cbd5e1;
            overflow: hidden;
            position: relative;
            display: flex;
            flex-direction: column;
        }

        /* Security Decorative Top Header */
        .pass-header-strip {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #ffffff;
            padding: 1.25rem 1.5rem;
            position: relative;
            border-bottom: 4px solid #2563eb;
        }

        .pass-header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .estate-branding {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .estate-logo-box {
            width: 44px;
            height: 44px;
            background: #ffffff;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 4px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.15);
        }

        .estate-logo-box img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .estate-info h1 {
            font-size: 1.1rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin: 0;
            color: #ffffff;
        }

        .estate-info p {
            font-size: 0.72rem;
            color: #94a3b8;
            margin: 2px 0 0;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .pass-serial-tag {
            background: rgba(37, 99, 235, 0.25);
            border: 1px solid rgba(59, 130, 246, 0.5);
            color: #93c5fd;
            font-family: 'Space Grotesk', monospace;
            font-size: 0.78rem;
            font-weight: 700;
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            letter-spacing: 0.05em;
            white-space: nowrap;
        }

        .pass-type-badge {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 10px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #60a5fa;
        }

        /* Pass Body */
        .pass-body {
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            background: #ffffff;
        }

        /* Hero Access Code Plate */
        .code-hero-box {
            background: #f8fafc;
            border: 2px dashed #94a3b8;
            border-radius: 14px;
            padding: 1.15rem 1rem;
            text-align: center;
            position: relative;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.03);
        }

        .code-hero-label {
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 4px;
        }

        .code-hero-val {
            font-family: 'Space Grotesk', monospace;
            font-size: 2.35rem;
            font-weight: 800;
            letter-spacing: 0.14em;
            color: #0f172a;
            margin: 0;
            line-height: 1.1;
            user-select: all;
        }

        .code-status-pill {
            display: inline-block;
            margin-top: 8px;
            padding: 0.25rem 0.85rem;
            border-radius: 9999px;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            background: <?= $status_info['bg'] ?>;
            color: <?= $status_info['color'] ?>;
            border: 1px solid <?= $status_info['border'] ?>;
        }

        /* Main Details Grid: Left Info, Right QR Code */
        .details-qr-flex {
            display: flex;
            gap: 1.25rem;
            align-items: stretch;
        }

        .details-col {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .detail-lbl {
            font-size: 0.7rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .detail-val {
            font-size: 0.95rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.25;
        }

        .detail-sub {
            font-size: 0.75rem;
            color: #64748b;
            font-weight: 500;
        }

        .qr-col {
            width: 140px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            background: #f8fafc;
            border-radius: 12px;
            padding: 8px;
            border: 1px solid #e2e8f0;
        }

        .qr-img-box {
            width: 116px;
            height: 116px;
            background: #ffffff;
            border-radius: 8px;
            padding: 3px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
            border: 1px solid #cbd5e1;
        }

        .qr-img-box img {
            width: 100%;
            height: 100%;
            display: block;
        }

        .qr-caption {
            font-size: 0.65rem;
            font-weight: 800;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-top: 6px;
            line-height: 1.2;
        }

        /* Schedule Bar */
        .schedule-strip {
            background: #f1f5f9;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            border: 1px solid #e2e8f0;
        }

        /* Security Instructions Box */
        .instructions-box {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 10px;
            padding: 0.85rem 1rem;
            font-size: 0.72rem;
            color: #92400e;
            line-height: 1.45;
        }

        .instructions-title {
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
            color: #b45309;
        }

        .instructions-list {
            margin: 0;
            padding-left: 1.15rem;
        }

        .instructions-list li {
            margin-bottom: 2px;
        }

        /* Card Footer */
        .pass-footer {
            border-top: 1px dashed #cbd5e1;
            padding: 1rem 1.5rem;
            background: #f8fafc;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.7rem;
            color: #64748b;
        }

        .security-stamp-line {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .stamp-title {
            font-weight: 800;
            color: #1e293b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        /* ------------------------------------------------------------------ */
        /* THERMAL POS SLIP (80mm) MODE                                       */
        /* ------------------------------------------------------------------ */
        .thermal-mode .gate-pass-card {
            width: 320px !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            border: 1px dashed #000000 !important;
            color: #000000 !important;
        }

        .thermal-mode .pass-header-strip {
            background: transparent !important;
            color: #000000 !important;
            border-bottom: 2px solid #000000 !important;
            padding: 0.75rem 0.5rem !important;
            text-align: center;
        }

        .thermal-mode .pass-header-top {
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .thermal-mode .estate-branding {
            flex-direction: column;
        }

        .thermal-mode .estate-info h1 {
            color: #000000 !important;
            font-size: 1.1rem;
        }

        .thermal-mode .estate-info p {
            color: #333333 !important;
        }

        .thermal-mode .pass-body {
            padding: 0.75rem 0.5rem !important;
            gap: 0.85rem !important;
        }

        .thermal-mode .code-hero-box {
            border: 2px solid #000000 !important;
            background: transparent !important;
            padding: 0.75rem 0.25rem !important;
        }

        .thermal-mode .code-hero-val {
            font-size: 1.9rem !important;
            color: #000000 !important;
        }

        .thermal-mode .details-qr-flex {
            flex-direction: column;
            align-items: center;
        }

        .thermal-mode .details-col {
            width: 100%;
        }

        .thermal-mode .qr-col {
            width: 100% !important;
            border: none !important;
            background: transparent !important;
            padding: 0 !important;
        }

        .thermal-mode .instructions-box {
            background: transparent !important;
            border: 1px dashed #000000 !important;
            color: #000000 !important;
        }

        /* ------------------------------------------------------------------ */
        /* PRINT STYLES                                                       */
        /* ------------------------------------------------------------------ */
        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            .print-toolbar {
                display: none !important;
            }

            .pass-stage {
                padding: 0 !important;
                margin: 0 !important;
            }

            .gate-pass-card {
                box-shadow: none !important;
                border: 1px solid #94a3b8 !important;
                margin: 0 auto !important;
                page-break-inside: avoid !important;
            }

            .thermal-mode .gate-pass-card {
                border: 1px dashed #000000 !important;
            }

            @page {
                size: auto;
                margin: 0.8cm;
            }
        }
    </style>
</head>
<body class="slip-mode" id="pageBody">

    <!-- Top Action / Print Toolbar -->
    <div class="print-toolbar">
        <div class="toolbar-group">
            <button onclick="window.close(); if(window.opener){window.opener.focus();}else{history.back();}" class="btn-toolbar btn-secondary-action">
                <i class="fa-solid fa-arrow-left"></i> Back
            </button>
            <span style="color: #94a3b8; font-size: 0.85rem; font-weight: 600; margin-left: 6px;">
                <i class="fa-solid fa-id-badge text-primary me-1"></i> Visitor Gate Pass
            </span>
        </div>

        <div class="toolbar-group">
            <!-- Format Selector -->
            <button type="button" class="btn-mode-toggle active" id="btnModeSlip" onclick="switchMode('slip')">
                <i class="fa-solid fa-ticket me-1"></i> Standard Pass
            </button>
            <button type="button" class="btn-mode-toggle" id="btnModeThermal" onclick="switchMode('thermal')">
                <i class="fa-solid fa-receipt me-1"></i> Thermal Slip (80mm)
            </button>

            <!-- Copy Code -->
            <button onclick="copyPassCode('<?= htmlspecialchars($v['visitor_code']) ?>')" class="btn-toolbar btn-secondary-action" title="Copy code to clipboard">
                <i class="fa-solid fa-copy"></i> Copy Code
            </button>

            <!-- Send via WhatsApp -->
            <a href="<?= $wa_link ?>" target="_blank" class="btn-toolbar btn-whatsapp" title="Send pass details directly to guest">
                <i class="fa-brands fa-whatsapp"></i> Send to Guest
            </a>

            <!-- Print Trigger -->
            <button onclick="window.print()" class="btn-toolbar btn-primary-action">
                <i class="fa-solid fa-print"></i> Print Gate Pass
            </button>
        </div>
    </div>

    <!-- Pass Stage Container -->
    <div class="pass-stage">
        
        <div class="gate-pass-card" id="gatePassCard">
            
            <!-- Security Header Strip -->
            <div class="pass-header-strip">
                <div class="pass-header-top">
                    <div class="estate-branding">
                        <div class="estate-logo-box">
                            <?php 
                            $logo_src = '';
                            if (!empty($estate_logo)) {
                                $clean_logo = ltrim(str_replace('../', '', $estate_logo), '/');
                                if (file_exists(__DIR__ . '/' . $clean_logo)) {
                                    $logo_src = (strpos($_SERVER['REQUEST_URI'] ?? '', '/resident/') !== false ? '../' : '') . $clean_logo;
                                } elseif (file_exists($estate_logo)) {
                                    $logo_src = $estate_logo;
                                }
                            }
                            ?>
                            <?php if (!empty($logo_src)): ?>
                                <img src="<?= htmlspecialchars($logo_src) ?>" alt="Estate Logo">
                            <?php else: ?>
                                <i class="fa-solid fa-shield-halved text-primary" style="font-size: 1.5rem;"></i>
                            <?php endif; ?>
                        </div>
                        <div class="estate-info">
                            <h1><?= htmlspecialchars($estate_name) ?></h1>
                            <p><?= htmlspecialchars($estate_motto) ?></p>
                        </div>
                    </div>

                    <div class="pass-serial-tag">
                        GP-<?= str_pad($v['id'], 5, '0', STR_PAD_LEFT) ?>
                    </div>
                </div>

                <div class="pass-type-badge">
                    <i class="fa-solid fa-shield-check"></i> Official Visitor Gate Pass &bull; Security Clearance
                </div>
            </div>

            <!-- Pass Body Content -->
            <div class="pass-body">
                
                <!-- Huge Hero Gate Pass Code -->
                <div class="code-hero-box">
                    <div class="code-hero-label">Gate Access Pass Code</div>
                    <div class="code-hero-val"><?= htmlspecialchars($v['visitor_code']) ?></div>
                    <div>
                        <span class="code-status-pill"><?= htmlspecialchars($status_info['label']) ?></span>
                    </div>
                </div>

                <!-- Main Info + QR Code Flex Grid -->
                <div class="details-qr-flex">
                    
                    <!-- Left: Guest & Host Details -->
                    <div class="details-col">
                        
                        <div class="detail-item">
                            <span class="detail-lbl"><i class="fa-solid fa-user me-1 text-primary"></i> Visitor (Guest)</span>
                            <span class="detail-val"><?= htmlspecialchars($v['name']) ?></span>
                            <?php if (!empty($v['phone'])): ?>
                                <span class="detail-sub"><i class="fa-solid fa-phone me-1"></i> <?= htmlspecialchars($v['phone']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="detail-item">
                            <span class="detail-lbl"><i class="fa-solid fa-house-user me-1 text-primary"></i> Host Resident</span>
                            <span class="detail-val"><?= htmlspecialchars($v['resident_name'] ?? 'Estate Resident') ?></span>
                            <?php if (!empty($v['resident_phone'])): ?>
                                <span class="detail-sub"><i class="fa-solid fa-phone me-1"></i> <?= htmlspecialchars($v['resident_phone']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="detail-item">
                            <span class="detail-lbl"><i class="fa-solid fa-location-dot me-1 text-primary"></i> Destination Unit</span>
                            <span class="detail-val"><?= htmlspecialchars($destination_address) ?></span>
                        </div>

                        <div class="detail-item">
                            <span class="detail-lbl"><i class="fa-solid fa-briefcase me-1 text-primary"></i> Purpose of Visit</span>
                            <span class="detail-val" style="font-size: 0.88rem; font-weight: 600; color: #334155;">
                                <?= htmlspecialchars($v['purpose'] ?? 'Personal Visit') ?>
                            </span>
                        </div>

                    </div>

                    <!-- Right: Scannable Security QR Code -->
                    <div class="qr-col">
                        <div class="qr-img-box">
                            <img src="<?= $qr_img_url ?>" alt="Verification QR">
                        </div>
                        <div class="qr-caption">
                            <i class="fa-solid fa-qrcode text-primary"></i> Scan at Gate
                        </div>
                        <div style="font-size: 0.6rem; color: #94a3b8; margin-top: 2px;">
                            Express Entry Scan
                        </div>
                    </div>

                </div>

                <!-- Schedule & Validity Strip -->
                <div class="schedule-strip">
                    <div>
                        <div class="detail-lbl"><i class="fa-regular fa-calendar-check me-1 text-primary"></i> Expected Arrival</div>
                        <div style="font-weight: 700; font-size: 0.85rem; color: #0f172a; margin-top: 2px;">
                            <?= htmlspecialchars($arrival_time) ?>
                        </div>
                    </div>
                    <div>
                        <div class="detail-lbl"><i class="fa-regular fa-clock me-1 text-danger"></i> Valid Until</div>
                        <div style="font-weight: 700; font-size: 0.85rem; color: #b91c1c; margin-top: 2px;">
                            <?= htmlspecialchars($expiry_time) ?>
                        </div>
                    </div>
                </div>

                <!-- Estate Gate Security Instructions -->
                <div class="instructions-box">
                    <div class="instructions-title">
                        <i class="fa-solid fa-triangle-exclamation"></i> Security & Gate Regulations
                    </div>
                    <ul class="instructions-list">
                        <li>Present this pass code to Security Officers at the Main Gate upon arrival and exit.</li>
                        <li>Estate speed limit is <strong>20 km/h</strong>. Park only in authorized visitor parking bays.</li>
                        <li>Valid exclusively for the guest and destination indicated on this credential.</li>
                        <li>Security Dispatch & Hotline: <strong><?= htmlspecialchars($office_phone) ?></strong>.</li>
                    </ul>
                </div>

            </div>

            <!-- Pass Security Verification Footer -->
            <div class="pass-footer">
                <div class="security-stamp-line">
                    <span class="stamp-title">Estate Access Authority</span>
                    <span>Division of Security & Access Management</span>
                </div>
                <div style="text-align: right;">
                    <div>Issued: <?= $issue_time ?></div>
                    <div style="font-weight: 700; color: #0f172a;">Gate Security Division</div>
                </div>
            </div>

        </div>

    </div>

    <script>
        function switchMode(mode) {
            const body = document.getElementById('pageBody');
            const btnSlip = document.getElementById('btnModeSlip');
            const btnThermal = document.getElementById('btnModeThermal');
            
            if (mode === 'thermal') {
                body.classList.remove('slip-mode');
                body.classList.add('thermal-mode');
                btnSlip.classList.remove('active');
                btnThermal.classList.add('active');
            } else {
                body.classList.remove('thermal-mode');
                body.classList.add('slip-mode');
                btnThermal.classList.remove('active');
                btnSlip.classList.add('active');
            }
        }

        function copyPassCode(code) {
            navigator.clipboard.writeText(code).then(() => {
                alert('Gate Pass Code (' + code + ') copied to clipboard!');
            }).catch(() => {
                prompt('Copy this Gate Pass Code:', code);
            });
        }

        <?php if ($auto_print): ?>
        window.addEventListener('load', () => {
            setTimeout(() => {
                window.print();
            }, 500);
        });
        <?php endif; ?>
    </script>
</body>
</html>
