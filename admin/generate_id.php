<?php
// admin/generate_id.php
require_once __DIR__ . '/../config.php';

$estate_id = get_estate_id();
$type = $_GET['type'] ?? (isset($_GET['user_id']) ? 'user' : 'resident');
$id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 1);

// Fetch System Settings
$settings_res = $conn->query("SELECT * FROM system_settings WHERE estate_id = $estate_id");
$sys = [];
while ($row = $settings_res->fetch_assoc()) {
    $sys[$row['setting_key']] = $row['setting_value'];
}

$estate_name = $sys['estate_name'] ?? 'Estate Administrative Office';
$estate_motto = !empty($sys['estate_motto']) ? $sys['estate_motto'] : 'Security, Community & Excellence';
$estate_logo = $sys['estate_logo'] ?? '';
$estate_location = !empty($sys['estate_location']) ? $sys['estate_location'] : 'Central Security Gate Office';
$office_phone = !empty($sys['office_phone']) ? $sys['office_phone'] : '+234 800 000 0000';
$office_email = !empty($sys['office_email']) ? $sys['office_email'] : 'security@estate.com';
$estate_website = !empty($sys['estate_website']) ? $sys['estate_website'] : 'portal.estate.com';

// Theme Color Configuration
$default_theme = !empty($sys['theme_color']) ? $sys['theme_color'] : '#0d9488';
$active_color = isset($_GET['color']) && preg_match('/^#[a-f0-9]{6}$/i', $_GET['color']) ? $_GET['color'] : $default_theme;

$initial_orientation = isset($_GET['orientation']) ? strtolower($_GET['orientation']) : ($sys['id_card_orientation'] ?? 'portrait');
if (!in_array($initial_orientation, ['portrait', 'landscape'])) {
    $initial_orientation = 'portrait';
}

$validity_days = intval($sys['id_card_validity_days'] ?? 365);
$issue_date = date('M d, Y');
$expiry_date = date('M d, Y', strtotime("+$validity_days days"));
$issue_year = date('Y');
$expiry_year = date('Y', strtotime("+$validity_days days"));

// Fetch Person Details
$person = null;
$person_type = $type;

if ($type == 'resident') {
    $sql = "SELECT r.*, u.first_name, u.last_name, u.name as full_name, u.phone, u.email,
                   f.number as flat_number, b.name as building_name, s.name as street_name 
            FROM residents r 
            JOIN users u ON r.user_id = u.id 
            LEFT JOIN flats f ON r.flat_id = f.id 
            LEFT JOIN buildings b ON f.building_id = b.id 
            LEFT JOIN streets s ON b.street_id = s.id
            WHERE (r.id = $id OR r.user_id = $id) AND r.estate_id = $estate_id 
            LIMIT 1";
    $res = $conn->query($sql);
    if ($res && $res->num_rows > 0) {
        $person = $res->fetch_assoc();
        $first = $person['first_name'] ?? '';
        $last = $person['last_name'] ?? '';
        $person['name'] = trim("$first $last") ?: ($person['full_name'] ?? 'Estate Resident');
        $person['role'] = 'Resident';
        $person['custom_id'] = !empty($person['custom_id']) ? $person['custom_id'] : ('RES-' . str_pad($person['id'], 5, '0', STR_PAD_LEFT));
        
        $flat = $person['flat_number'] ? 'Flat ' . $person['flat_number'] : '';
        $bldg = $person['building_name'] ? $person['building_name'] . ' Block' : '';
        $street = $person['street_name'] ? $person['street_name'] . ' Street' : '';
        $addr_parts = array_filter([$flat, $bldg, $street]);
        $person['address'] = !empty($addr_parts) ? implode(', ', $addr_parts) : 'Central Estate Residence';
        
        $person['phone'] = !empty($person['phone']) ? $person['phone'] : $office_phone;
        $person['email'] = !empty($person['email']) ? $person['email'] : 'resident@estate.com';
        $person['dept'] = 'Residential Community';
        $person['join_date'] = !empty($person['registration_date']) ? date('M d, Y', strtotime($person['registration_date'])) : $issue_date;
    }

} elseif ($type == 'staff') {
    // Household domestic staff
    $sql = "SELECT s.*, f.number as flat_number, b.name as building_name, st.name as street_name,
                   r.user_id as resident_user_id, u_res.name as employer_name
            FROM household_staff s 
            LEFT JOIN flats f ON s.flat_id = f.id 
            LEFT JOIN buildings b ON f.building_id = b.id 
            LEFT JOIN streets st ON b.street_id = st.id
            LEFT JOIN residents r ON s.flat_id = r.flat_id
            LEFT JOIN users u_res ON r.user_id = u_res.id
            WHERE s.id = $id AND s.estate_id = $estate_id 
            LIMIT 1";
    $res = $conn->query($sql);
    if ($res && $res->num_rows > 0) {
        $person = $res->fetch_assoc();
        $first = $person['first_name'] ?? '';
        $last = $person['last_name'] ?? '';
        $person['name'] = trim("$first $last") ?: ($person['name'] ?? 'Staff Member');
        $person['role'] = !empty($person['role']) ? $person['role'] : 'Domestic Staff';
        $person['custom_id'] = !empty($person['custom_id']) ? $person['custom_id'] : ('HS-' . str_pad($person['id'], 5, '0', STR_PAD_LEFT));
        
        $flat = $person['flat_number'] ? 'Flat ' . $person['flat_number'] : '';
        $bldg = $person['building_name'] ? $person['building_name'] : '';
        $employer = !empty($person['employer_name']) ? 'Employer: ' . $person['employer_name'] : '';
        $person['address'] = implode(', ', array_filter([$flat, $bldg, $employer])) ?: 'Estate Residence';
        
        $person['phone'] = !empty($person['phone']) ? $person['phone'] : $office_phone;
        $person['email'] = $office_email;
        $person['dept'] = 'Household Staff';
        $person['join_date'] = !empty($person['registration_date']) ? date('M d, Y', strtotime($person['registration_date'])) : $issue_date;
    }

} elseif ($type == 'estate_staff') {
    // Official Estate Staff
    $sql = "SELECT s.*, u.first_name, u.last_name, u.name as full_name, u.phone as user_phone, u.email as user_email,
                   r.name as role_name
            FROM estate_staff s 
            JOIN users u ON s.user_id = u.id 
            LEFT JOIN roles r ON s.role_id = r.id
            WHERE (s.id = $id OR s.user_id = $id) AND s.estate_id = $estate_id 
            LIMIT 1";
    $res = $conn->query($sql);
    if ($res && $res->num_rows > 0) {
        $person = $res->fetch_assoc();
        $first = $person['first_name'] ?? '';
        $last = $person['last_name'] ?? '';
        $person['name'] = trim("$first $last") ?: ($person['full_name'] ?? 'Staff Member');
        $person['role'] = !empty($person['role_name']) ? $person['role_name'] : (!empty($person['role']) ? $person['role'] : 'Estate Staff');
        $person['custom_id'] = !empty($person['custom_id']) ? $person['custom_id'] : ('STF-' . str_pad($person['id'], 5, '0', STR_PAD_LEFT));
        $person['address'] = 'Estate Administrative Operations';
        $person['phone'] = !empty($person['phone']) ? $person['phone'] : (!empty($person['user_phone']) ? $person['user_phone'] : $office_phone);
        $person['email'] = !empty($person['user_email']) ? $person['user_email'] : $office_email;
        $person['dept'] = 'Operations & Security';
        $raw_date = $person['registration_date'] ?? $person['created_at'] ?? null;
        $person['join_date'] = !empty($raw_date) ? date('M d, Y', strtotime($raw_date)) : $issue_date;
    }

} elseif ($type == 'admin' || $type == 'user') {
    // Administrator
    $sql = "SELECT u.*, u.name as full_name, u.phone as user_phone, u.email as user_email
            FROM users u 
            WHERE u.id = $id AND u.estate_id = $estate_id 
            LIMIT 1";
    $res = $conn->query($sql);
    if ($res && $res->num_rows > 0) {
        $person = $res->fetch_assoc();
        $first = $person['first_name'] ?? '';
        $last = $person['last_name'] ?? '';
        $person['name'] = trim("$first $last") ?: ($person['full_name'] ?? 'Estate Administrator');
        $person['role'] = ($person['role'] === 'superadmin') ? 'Super Administrator' : 'Chief Administrator';
        $person['custom_id'] = 'ADM-' . str_pad($person['id'], 4, '0', STR_PAD_LEFT);
        $person['address'] = 'Executive Administrative Office';
        $person['phone'] = !empty($person['phone']) ? $person['phone'] : (!empty($person['user_phone']) ? $person['user_phone'] : $office_phone);
        $person['email'] = !empty($person['email']) ? $person['email'] : (!empty($person['user_email']) ? $person['user_email'] : $office_email);
        $person['dept'] = 'Estate Management Authority';
        $person['join_date'] = !empty($person['created_at']) ? date('M d, Y', strtotime($person['created_at'])) : $issue_date;
    }
}

// Fallback search in users table if person not found
if (!$person) {
    $fallback_user = $conn->query("SELECT * FROM users WHERE id = $id AND estate_id = $estate_id LIMIT 1");
    if ($fallback_user && $fallback_user->num_rows > 0) {
        $person = $fallback_user->fetch_assoc();
        $person['name'] = $person['name'] ?: 'Estate Member';
        $person['role'] = ucfirst($person['role'] ?? 'Member');
        $person['custom_id'] = 'EST-' . str_pad($person['id'], 5, '0', STR_PAD_LEFT);
        $person['address'] = 'Central Estate Sector';
        $person['phone'] = $person['phone'] ?: $office_phone;
        $person['email'] = $person['email'] ?: $office_email;
        $person['dept'] = 'Estate Administration';
        $person['join_date'] = $issue_date;
    } else {
        die("<div style='font-family: sans-serif; text-align: center; padding: 4rem;'><h2>Person Record Not Found</h2><p>Unable to locate the identity profile for ID #$id ($type).</p><a href='javascript:history.back()' style='color: #2563eb;'>Return to Dashboard</a></div>");
    }
}

// Format Name Split for stylized typography
$name_parts = explode(' ', trim($person['name']));
$first_word = $name_parts[0] ?? '';
$remaining_words = count($name_parts) > 1 ? implode(' ', array_slice($name_parts, 1)) : '';

// Verification QR Payload
$qr_payload = $estate_name . " OFFICIAL SECURITY VERIFICATION\n" .
              "ID NO: " . $person['custom_id'] . "\n" .
              "NAME: " . $person['name'] . "\n" .
              "ROLE: " . $person['role'] . "\n" .
              "STATUS: ACTIVE\n" .
              "JOINED: " . $person['join_date'] . "\n" .
              "EXPIRES: " . $expiry_date . "\n" .
              "UNIT: " . $person['address'];
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=240x240&margin=4&data=" . urlencode($qr_payload);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Official Identity Card - <?= htmlspecialchars($person['name']) ?> (<?= htmlspecialchars($person['custom_id']) ?>)</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Outfit:wght@300;400;500;600;700;800;900&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --theme-color: <?= $active_color ?>;
            --theme-dark: color-mix(in srgb, var(--theme-color) 78%, black);
            --theme-light: color-mix(in srgb, var(--theme-color) 12%, white);
            --theme-ultra-light: color-mix(in srgb, var(--theme-color) 6%, white);
            --accent-gold: #d97706;
            --accent-gold-light: #fef3c7;
            --slate-dark: #0f172a;
            --slate-text: #334155;
            --slate-muted: #64748b;
        }

        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        body {
            margin: 0;
            padding: 0;
            background: #1e293b;
            font-family: 'Outfit', sans-serif;
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Top Toolbar */
        .preview-toolbar {
            position: sticky;
            top: 0;
            width: 100%;
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding: 0.85rem 1.5rem;
            z-index: 999;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
        }

        .toolbar-group {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .toolbar-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #94a3b8;
            font-weight: 700;
        }

        .toggle-btn {
            background: #334155;
            color: #cbd5e1;
            border: 1px solid rgba(255, 255, 255, 0.15);
            padding: 0.45rem 0.95rem;
            border-radius: 9999px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            text-decoration: none;
        }

        .toggle-btn:hover {
            background: #475569;
            color: #ffffff;
        }

        .toggle-btn.active {
            background: var(--theme-color);
            color: #ffffff;
            border-color: var(--theme-color);
            box-shadow: 0 0 15px color-mix(in srgb, var(--theme-color) 60%, transparent);
        }

        .color-chip {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border: 2px solid #ffffff;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .color-chip:hover, .color-chip.active {
            transform: scale(1.25);
            box-shadow: 0 0 10px rgba(255,255,255,0.8);
        }

        .btn-action {
            padding: 0.55rem 1.25rem;
            border-radius: 0.5rem;
            font-weight: 700;
            font-size: 0.88rem;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .btn-print {
            background: #10b981;
            color: white;
            box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3);
        }
        .btn-print:hover { background: #059669; }

        .btn-close {
            background: #334155;
            color: #e2e8f0;
        }
        .btn-close:hover { background: #475569; color: white; }

        /* Stage Canvas Area */
        .id-card-stage {
            padding: 3rem 1.5rem 5rem;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 3.5rem;
            max-width: 1400px;
            width: 100%;
        }

        .card-perspective-label {
            text-align: center;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: #94a3b8;
            font-weight: 700;
            margin-bottom: 0.75rem;
        }

        /* ------------------------------------------------------------------ */
        /* PORTRAIT BADGE CARD HOLDER                                         */
        /* ------------------------------------------------------------------ */
        .portrait-holder {
            width: 380px;
            background: #ffffff;
            border-radius: 28px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45);
            padding: 16px;
            position: relative;
            display: flex;
            flex-direction: column;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .holder-slot {
            width: 65px;
            height: 12px;
            background: #e2e8f0;
            border-radius: 9999px;
            margin: 0 auto 14px;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.15);
        }

        /* Portrait Front Face */
        .card-face-portrait-front {
            width: 100%;
            min-height: 610px;
            background: #ffffff;
            border-radius: 18px;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            border: 1px solid #e2e8f0;
            box-shadow: inset 0 0 0 1px rgba(0,0,0,0.03);
            padding-bottom: 75px;
        }

        /* Subtle Wavy Contour SVG Background */
        .wavy-accent-svg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }

        .plus-cross {
            position: absolute;
            font-size: 1.2rem;
            color: var(--theme-color);
            font-weight: 900;
            opacity: 0.85;
            z-index: 2;
        }

        .portrait-brand-header {
            position: relative;
            z-index: 3;
            margin-top: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .portrait-logo-icon {
            font-size: 1.3rem;
            color: var(--theme-dark);
        }

        .portrait-estate-name {
            font-size: 0.95rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--theme-dark);
            line-height: 1.1;
            text-align: left;
        }

        /* Photo Frame with Gold / Metallic Border */
        .portrait-photo-box {
            position: relative;
            z-index: 3;
            width: 130px;
            height: 130px;
            margin-top: 14px;
            border: 4px solid var(--accent-gold);
            outline: 2px solid var(--accent-gold-light);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 10px 20px -5px rgba(0, 0, 0, 0.25);
            background: #f1f5f9;
        }

        .portrait-photo-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .portrait-photo-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e2e8f0;
            color: #94a3b8;
            font-size: 3.5rem;
        }

        /* Name & Role Designation */
        .portrait-name-block {
            position: relative;
            z-index: 3;
            margin-top: 12px;
            padding: 0 1rem;
        }

        .portrait-person-name {
            font-size: 1.3rem;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin: 0;
            line-height: 1.2;
        }

        .portrait-person-role {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--theme-color);
            margin-top: 3px;
            letter-spacing: 0.02em;
        }

        /* Exact Front Identification Info Section (User Screenshot) */
        .front-identity-section {
            position: relative;
            z-index: 3;
            width: 100%;
            padding: 0 1.25rem;
            margin-top: 16px;
            text-align: left;
        }

        .front-meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 12px;
            font-size: 0.82rem;
            line-height: 1.35;
        }

        .front-meta-col {
            display: flex;
            align-items: baseline;
            gap: 6px;
        }

        .front-meta-lbl {
            font-weight: 800;
            color: #475569;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.04em;
        }

        .front-meta-val {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            color: #0f172a;
            font-size: 0.85rem;
        }

        .front-divider {
            border-top: 1px dashed #cbd5e1;
            margin: 10px 0 12px;
        }

        .front-contact-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            font-size: 0.8rem;
            color: #1e293b;
        }

        .front-contact-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            line-height: 1.35;
        }

        .front-contact-icon {
            color: #2563eb;
            font-size: 0.9rem;
            width: 16px;
            display: flex;
            justify-content: center;
            margin-top: 2px;
            flex-shrink: 0;
        }

        /* Deep Chevron Bottom in Theme Color Gradient */
        .portrait-chevron-base {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 95px;
            background: linear-gradient(180deg, var(--theme-color) 0%, var(--theme-dark) 100%);
            clip-path: polygon(0 40%, 50% 0, 100% 40%, 100% 100%, 0 100%);
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
            padding-bottom: 12px;
        }

        .portrait-authority-badge {
            color: #ffffff;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            text-shadow: 0 1px 3px rgba(0,0,0,0.3);
        }

        .portrait-sub-badge {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.65rem;
            font-weight: 600;
            margin-top: 2px;
            letter-spacing: 0.04em;
        }

        /* Portrait Back Face */
        .card-face-portrait-back {
            width: 100%;
            min-height: 610px;
            background: #ffffff;
            border-radius: 18px;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            border: 3px solid var(--theme-dark);
            padding: 1.25rem 1.4rem;
            box-shadow: inset 0 0 0 1px rgba(0,0,0,0.05);
        }

        .portrait-back-content {
            position: relative;
            z-index: 3;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .dot-matrix-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.5rem;
        }

        .dot-grid {
            display: grid;
            grid-template-columns: repeat(7, 4px);
            gap: 5px;
        }

        .dot-grid span {
            width: 4px;
            height: 4px;
            background: #94a3b8;
            border-radius: 50%;
            display: block;
        }

        .chevron-accent-lines {
            display: flex;
            flex-direction: column;
            gap: 2px;
            color: var(--accent-gold);
            font-size: 0.75rem;
            font-weight: 800;
            line-height: 0.85;
        }

        .back-brand-header {
            text-align: center;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .back-estate-name {
            font-weight: 800;
            font-size: 0.92rem;
            color: var(--theme-dark);
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .back-estate-sub {
            font-size: 0.65rem;
            color: #64748b;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-top: 2px;
        }

        .back-rules-title {
            font-size: 0.74rem;
            font-weight: 800;
            text-transform: uppercase;
            color: #0f172a;
            margin-bottom: 3px;
            letter-spacing: 0.04em;
        }

        .back-rules-text {
            font-size: 0.68rem;
            color: #475569;
            line-height: 1.42;
            margin: 0 0 0.65rem;
            text-align: justify;
        }

        .back-footer-row {
            margin-top: auto;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
        }

        .back-signature-box {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .signature-line {
            width: 140px;
            border-bottom: 1px solid #0f172a;
            margin-bottom: 4px;
            font-family: 'Caveat', cursive, sans-serif;
            font-size: 1.35rem;
            color: #1e3a8a;
            line-height: 1.1;
            padding-bottom: 2px;
        }

        .signature-caption {
            font-size: 0.64rem;
            color: #64748b;
            font-weight: 700;
            text-transform: uppercase;
        }

        .back-qr-frame {
            width: 78px;
            height: 78px;
            border: 2px solid #0f172a;
            border-radius: 6px;
            padding: 2px;
            background: #ffffff;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .back-qr-frame img {
            width: 100%;
            height: 100%;
            display: block;
        }

        /* ------------------------------------------------------------------ */
        /* LANDSCAPE ID CARD                                                  */
        /* ------------------------------------------------------------------ */
        .landscape-card {
            width: 580px;
            height: 360px;
            background: #ffffff;
            border-radius: 18px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45);
            border: 1px solid rgba(255, 255, 255, 0.5);
            display: flex;
            flex-direction: column;
        }

        .geom-stripe {
            position: absolute;
            background: var(--theme-color);
            pointer-events: none;
            z-index: 2;
        }

        .geom-stripe-dark {
            position: absolute;
            background: var(--theme-dark);
            pointer-events: none;
            z-index: 1;
        }

        .ls-top-left-1 {
            top: -25px;
            left: -35px;
            width: 160px;
            height: 75px;
            transform: rotate(-35deg);
            background: var(--theme-color);
        }

        .ls-top-left-2 {
            top: -35px;
            left: -45px;
            width: 180px;
            height: 40px;
            transform: rotate(-35deg);
            background: var(--theme-dark);
        }

        .ls-bot-right-1 {
            bottom: -55px;
            right: -45px;
            width: 250px;
            height: 110px;
            transform: rotate(-35deg);
            background: var(--theme-color);
        }

        .ls-bot-right-2 {
            bottom: -75px;
            right: 40px;
            width: 230px;
            height: 45px;
            transform: rotate(-35deg);
            background: var(--theme-dark);
        }

        /* Landscape Front Side */
        .landscape-front-grid {
            position: relative;
            z-index: 5;
            display: grid;
            grid-template-columns: 210px 1fr;
            height: 100%;
            padding: 1.5rem;
            gap: 1.5rem;
            background: #ffffff;
        }

        .ls-front-left {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            border-right: 1px dashed #cbd5e1;
            padding-right: 1rem;
        }

        .ls-photo-circle {
            width: 115px;
            height: 115px;
            border-radius: 50%;
            border: 4px solid #ffffff;
            outline: 2px solid var(--accent-gold);
            overflow: hidden;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.18);
            background: #e2e8f0;
            margin-bottom: 12px;
        }

        .ls-photo-circle img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .ls-photo-circle-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #334155;
            color: #ffffff;
            font-size: 3.5rem;
        }

        .ls-person-name {
            font-size: 1.15rem;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            line-height: 1.2;
            margin: 0;
        }

        .ls-person-name span {
            color: var(--theme-color);
        }

        .ls-person-role {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--theme-color);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-top: 3px;
        }

        /* Landscape Front Right: Metadata + Contact */
        .ls-front-right {
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .ls-estate-banner {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            border-bottom: 2px solid var(--theme-light);
            padding-bottom: 6px;
        }

        .ls-estate-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--theme-dark);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin: 0;
            line-height: 1.1;
        }

        /* Landscape Back Layout: Rules & Terms & Return Policy */
        .landscape-back-grid {
            position: relative;
            z-index: 5;
            display: grid;
            grid-template-columns: 180px 1fr;
            height: 100%;
            padding: 1.5rem;
            gap: 1.25rem;
            background: #ffffff;
        }

        .ls-back-left {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            text-align: center;
            border-right: 1px solid #e2e8f0;
            padding-right: 1rem;
        }

        .ls-back-right {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .cards-wrapper {
            display: flex;
            flex-wrap: wrap;
            gap: 2.5rem;
            justify-content: center;
            align-items: center;
        }

        /* Print Settings */
        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            .preview-toolbar {
                display: none !important;
            }

            .id-card-stage {
                padding: 0 !important;
                margin: 0 !important;
                gap: 1.5cm !important;
            }

            .card-perspective-label {
                display: none !important;
            }

            .portrait-holder, .landscape-card {
                box-shadow: none !important;
                border: 1px solid #cbd5e1 !important;
                page-break-inside: avoid !important;
            }

            @page {
                size: auto;
                margin: 1cm;
            }
        }
    </style>
</head>
<body>

<!-- Interactive Toolbar -->
<div class="preview-toolbar">
    <div class="toolbar-group">
        <span class="toolbar-label"><i class="fa-solid fa-id-card-clip me-1"></i> ID Format:</span>
        <button type="button" class="toggle-btn <?= $initial_orientation === 'portrait' ? 'active' : '' ?>" id="btnOrientationPortrait" onclick="switchOrientation('portrait')">
            <i class="fa-solid fa-mobile-screen-button"></i> Portrait (Badge)
        </button>
        <button type="button" class="toggle-btn <?= $initial_orientation === 'landscape' ? 'active' : '' ?>" id="btnOrientationLandscape" onclick="switchOrientation('landscape')">
            <i class="fa-solid fa-credit-card"></i> Landscape (Card)
        </button>
    </div>

    <div class="toolbar-group">
        <span class="toolbar-label"><i class="fa-solid fa-layer-group me-1"></i> Display:</span>
        <button type="button" class="toggle-btn active" id="btnSideBoth" onclick="switchSideView('both')">Front & Back</button>
        <button type="button" class="toggle-btn" id="btnSideFront" onclick="switchSideView('front')">Front Only</button>
        <button type="button" class="toggle-btn" id="btnSideBack" onclick="switchSideView('back')">Back Only</button>
    </div>

    <div class="toolbar-group">
        <span class="toolbar-label"><i class="fa-solid fa-palette me-1"></i> Theme Accent:</span>
        <div class="color-chip <?= $active_color === '#0d9488' ? 'active' : '' ?>" style="background: #0d9488;" title="Teal (Default)" onclick="setThemeColor('#0d9488')"></div>
        <div class="color-chip <?= $active_color === '#2563eb' ? 'active' : '' ?>" style="background: #2563eb;" title="Royal Blue" onclick="setThemeColor('#2563eb')"></div>
        <div class="color-chip <?= $active_color === '#f59e0b' ? 'active' : '' ?>" style="background: #f59e0b;" title="Golden Amber" onclick="setThemeColor('#f59e0b')"></div>
        <div class="color-chip <?= $active_color === '#10b981' ? 'active' : '' ?>" style="background: #10b981;" title="Emerald Green" onclick="setThemeColor('#10b981')"></div>
        <div class="color-chip <?= $active_color === '#7c3aed' ? 'active' : '' ?>" style="background: #7c3aed;" title="Imperial Purple" onclick="setThemeColor('#7c3aed')"></div>
        <div class="color-chip <?= $active_color === '#dc2626' ? 'active' : '' ?>" style="background: #dc2626;" title="Crimson" onclick="setThemeColor('#dc2626')"></div>
        <div class="color-chip <?= $active_color === '#1e293b' ? 'active' : '' ?>" style="background: #1e293b;" title="Midnight Dark" onclick="setThemeColor('#1e293b')"></div>
        <input type="color" id="customColorPicker" value="<?= $active_color ?>" style="width: 24px; height: 24px; padding: 0; border: none; border-radius: 50%; cursor: pointer;" onchange="setThemeColor(this.value)" title="Custom Color">
    </div>

    <div class="toolbar-group">
        <button type="button" class="btn-action btn-print" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Print ID Card
        </button>
        <button type="button" class="btn-action btn-close" onclick="window.close()">
            <i class="fa-solid fa-xmark"></i> Close
        </button>
    </div>
</div>

<!-- Main Canvas Container -->
<div class="id-card-stage">

    <!-- ================================================================= -->
    <!-- 1. PORTRAIT VIEW                                                  -->
    <!-- ================================================================= -->
    <div id="portraitStage" class="cards-wrapper" style="<?= $initial_orientation !== 'portrait' ? 'display: none;' : '' ?>">
        
        <!-- FRONT SIDE (Contains Photo, Name, Role, Metadata, & Contacts - NO BARCODE) -->
        <div class="card-side-box" id="portraitFrontSide">
            <div class="card-perspective-label"><i class="fa-solid fa-id-card"></i> Front Side (Obverse)</div>
            <div class="portrait-holder">
                <div class="holder-slot"></div>
                
                <div class="card-face-portrait-front">
                    <!-- Subtle Wavy Contour SVG Background -->
                    <svg class="wavy-accent-svg" viewBox="0 0 380 610" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M-20 60 C80 30, 260 120, 380 40" stroke="var(--accent-gold)" stroke-width="1.5" opacity="0.35"/>
                        <path d="M-40 85 C60 55, 240 145, 400 65" stroke="var(--accent-gold)" stroke-width="1.2" opacity="0.25"/>
                        <path d="M-10 110 C90 80, 270 170, 390 90" stroke="var(--accent-gold)" stroke-width="1" opacity="0.2"/>
                    </svg>

                    <!-- Cross Accents -->
                    <div class="plus-cross" style="top: 100px; left: 24px;">+</div>
                    <div class="plus-cross" style="top: 160px; right: 28px;">+</div>

                    <!-- Estate Logo & Title Header -->
                    <div class="portrait-brand-header">
                        <?php if (!empty($estate_logo)): ?>
                            <img src="<?= htmlspecialchars($estate_logo) ?>" style="height: 32px; max-width: 50px; object-fit: contain;">
                        <?php else: ?>
                            <i class="fa-solid fa-hotel portrait-logo-icon"></i>
                        <?php endif; ?>
                        <div class="portrait-estate-name">
                            <div><?= htmlspecialchars($estate_name) ?></div>
                            <div style="font-size: 0.65rem; color: #64748b; font-weight: 600; text-transform: capitalize;"><?= htmlspecialchars($estate_motto) ?></div>
                        </div>
                    </div>

                    <!-- Member Photo Frame -->
                    <div class="portrait-photo-box">
                        <?php if (!empty($person['image_path'])): ?>
                            <img src="<?= htmlspecialchars($person['image_path']) ?>" alt="<?= htmlspecialchars($person['name']) ?>">
                        <?php else: ?>
                            <div class="portrait-photo-placeholder">
                                <i class="fa-solid fa-user"></i>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Member Name & Role -->
                    <div class="portrait-name-block">
                        <h2 class="portrait-person-name"><?= htmlspecialchars($person['name']) ?></h2>
                        <div class="portrait-person-role"><?= htmlspecialchars($person['role']) ?></div>
                    </div>

                    <!-- Front Identification Info Section (Matching User Image) -->
                    <div class="front-identity-section">
                        <!-- Metadata Grid -->
                        <div class="front-meta-grid">
                            <div class="front-meta-col">
                                <span class="front-meta-lbl">ID NO:</span>
                                <span class="front-meta-val"><?= htmlspecialchars($person['custom_id']) ?></span>
                            </div>
                            <div class="front-meta-col">
                                <span class="front-meta-lbl">JOIN:</span>
                                <span class="front-meta-val"><?= htmlspecialchars($person['join_date']) ?></span>
                            </div>
                            <div class="front-meta-col">
                                <span class="front-meta-lbl">STATUS:</span>
                                <span class="front-meta-val" style="color: #15803d;">ACTIVE</span>
                            </div>
                            <div class="front-meta-col">
                                <span class="front-meta-lbl">EXPIRED:</span>
                                <span class="front-meta-val" style="color: #dc2626;"><?= htmlspecialchars($expiry_date) ?></span>
                            </div>
                        </div>

                        <!-- Dashed Divider Line -->
                        <div class="front-divider"></div>

                        <!-- Contact Details List -->
                        <div class="front-contact-list">
                            <div class="front-contact-row">
                                <span class="front-contact-icon"><i class="fa-solid fa-envelope"></i></span>
                                <span><?= htmlspecialchars($person['email']) ?></span>
                            </div>
                            <div class="front-contact-row">
                                <span class="front-contact-icon"><i class="fa-solid fa-phone"></i></span>
                                <span><?= htmlspecialchars($person['phone']) ?></span>
                            </div>
                            <div class="front-contact-row">
                                <span class="front-contact-icon"><i class="fa-solid fa-location-dot"></i></span>
                                <span><?= htmlspecialchars($person['address']) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Deep Chevron Base (NO BARCODE) -->
                    <div class="portrait-chevron-base">
                        <div class="portrait-authority-badge">
                            <i class="fa-solid fa-shield-halved me-1"></i> Verified Estate Credential
                        </div>
                        <div class="portrait-sub-badge">
                            <?= htmlspecialchars($estate_name) ?> Access Authority
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- BACK SIDE (Rules, Terms and Conditions, Return Policy & Verification QR) -->
        <div class="card-side-box" id="portraitBackSide">
            <div class="card-perspective-label"><i class="fa-solid fa-shield-halved"></i> Back Side (Reverse)</div>
            <div class="portrait-holder">
                <div class="holder-slot"></div>
                
                <div class="card-face-portrait-back">
                    <div class="portrait-back-content">
                        
                        <!-- Top Dot Matrix & Decorative Chevron Accent -->
                        <div class="dot-matrix-top">
                            <div class="dot-grid">
                                <?php for($i=0; $i<28; $i++): ?><span></span><?php endfor; ?>
                            </div>
                            <div class="chevron-accent-lines">
                                <i class="fa-solid fa-angles-down"></i>
                                <i class="fa-solid fa-angles-down" style="opacity: 0.7;"></i>
                                <i class="fa-solid fa-angles-down" style="opacity: 0.4;"></i>
                            </div>
                        </div>

                        <!-- Back Estate Authority Branding -->
                        <div class="back-brand-header">
                            <div class="back-estate-name"><?= htmlspecialchars($estate_name) ?></div>
                            <div class="back-estate-sub">Security, Verification & Access Control Division</div>
                        </div>

                        <!-- Rules & Regulations -->
                        <div class="back-rules-title"><i class="fa-solid fa-list-check me-1 text-primary"></i> Rules & Regulations</div>
                        <p class="back-rules-text">
                            1. This credential must be exhibited upon accessing estate entry/exit perimeter gates.<br>
                            2. Non-transferable; valid exclusively for the individual registered and pictured on the front.<br>
                            3. Any alteration, forgery, or transfer is a serious breach of estate security regulations.
                        </p>

                        <!-- Terms & Conditions of Issue -->
                        <div class="back-rules-title"><i class="fa-solid fa-file-contract me-1 text-primary"></i> Terms & Conditions</div>
                        <p class="back-rules-text">
                            This card remains the official property of <strong><?= htmlspecialchars($estate_name) ?></strong> and is issued solely for authorized estate residency and operations. It must be surrendered on demand to estate security personnel.
                        </p>

                        <!-- Return Policy Notice -->
                        <div class="back-rules-title" style="color: #b45309;"><i class="fa-solid fa-arrow-rotate-left me-1"></i> Return Policy</div>
                        <p class="back-rules-text" style="margin-bottom: 0.5rem;">
                            <strong>IF FOUND:</strong> Please return immediately to the <strong>Central Security Gate Office</strong> (<?= htmlspecialchars($estate_location) ?>) or contact Estate Command at <strong><?= htmlspecialchars($office_phone) ?></strong> / <strong><?= htmlspecialchars($office_email) ?></strong>.
                        </p>

                        <!-- Footer Stamp, Signature & Scannable QR -->
                        <div class="back-footer-row">
                            <div class="back-signature-box">
                                <div class="signature-line">Estate Authority</div>
                                <div class="signature-caption">Authorized Security Director</div>
                                <div style="font-size: 0.65rem; color: #64748b; font-weight: 600; margin-top: 2px;">
                                    <i class="fa-solid fa-lock text-success me-1"></i> Certified Official Pass
                                </div>
                            </div>

                            <div class="back-qr-frame">
                                <img src="<?= $qr_url ?>" alt="Verification QR Code">
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- ================================================================= -->
    <!-- 2. LANDSCAPE VIEW                                                 -->
    <!-- ================================================================= -->
    <div id="landscapeStage" class="cards-wrapper" style="<?= $initial_orientation !== 'landscape' ? 'display: none;' : '' ?>">
        
        <!-- FRONT SIDE (Member Photo, Name, Role, Metadata, & Contacts - NO BARCODE) -->
        <div class="card-side-box" id="landscapeFrontSide">
            <div class="card-perspective-label"><i class="fa-solid fa-id-card"></i> Front Side (Obverse)</div>
            <div class="landscape-card">
                <!-- Angular Theme Colored Corner Bands -->
                <div class="geom-stripe ls-top-left-1"></div>
                <div class="geom-stripe-dark ls-top-left-2"></div>
                <div class="geom-stripe ls-bot-right-1"></div>
                <div class="geom-stripe-dark ls-bot-right-2"></div>

                <div class="landscape-front-grid">
                    <!-- Left: Avatar & Name & Role -->
                    <div class="ls-front-left">
                        <div class="ls-photo-circle">
                            <?php if (!empty($person['image_path'])): ?>
                                <img src="<?= htmlspecialchars($person['image_path']) ?>" alt="<?= htmlspecialchars($person['name']) ?>">
                            <?php else: ?>
                                <div class="ls-photo-circle-placeholder">
                                    <i class="fa-solid fa-user"></i>
                                </div>
                            <?php endif; ?>
                        </div>

                        <h2 class="ls-person-name">
                            <?= htmlspecialchars($first_word) ?> <span><?= htmlspecialchars($remaining_words) ?></span>
                        </h2>
                        <div class="ls-person-role"><?= htmlspecialchars($person['role']) ?></div>
                    </div>

                    <!-- Right: Estate Header, Metadata Grid, and Contact Info -->
                    <div class="ls-front-right">
                        <div class="ls-estate-banner">
                            <?php if (!empty($estate_logo)): ?>
                                <img src="<?= htmlspecialchars($estate_logo) ?>" style="height: 22px; max-width: 32px; object-fit: contain;">
                            <?php else: ?>
                                <i class="fa-solid fa-hotel text-primary" style="font-size: 1.1rem;"></i>
                            <?php endif; ?>
                            <div>
                                <h3 class="ls-estate-title"><?= htmlspecialchars($estate_name) ?></h3>
                                <div style="font-size: 0.65rem; color: #64748b; font-weight: 600; text-transform: uppercase;"><?= htmlspecialchars($estate_motto) ?></div>
                            </div>
                        </div>

                        <!-- Metadata Grid -->
                        <div class="front-meta-grid" style="margin-bottom: 8px;">
                            <div class="front-meta-col">
                                <span class="front-meta-lbl">ID NO:</span>
                                <span class="front-meta-val"><?= htmlspecialchars($person['custom_id']) ?></span>
                            </div>
                            <div class="front-meta-col">
                                <span class="front-meta-lbl">JOIN:</span>
                                <span class="front-meta-val"><?= htmlspecialchars($person['join_date']) ?></span>
                            </div>
                            <div class="front-meta-col">
                                <span class="front-meta-lbl">STATUS:</span>
                                <span class="front-meta-val" style="color: #15803d;">ACTIVE</span>
                            </div>
                            <div class="front-meta-col">
                                <span class="front-meta-lbl">EXPIRED:</span>
                                <span class="front-meta-val" style="color: #dc2626;"><?= htmlspecialchars($expiry_date) ?></span>
                            </div>
                        </div>

                        <!-- Dashed Divider -->
                        <div class="front-divider" style="margin: 6px 0 8px;"></div>

                        <!-- Contact List with Icons -->
                        <div class="front-contact-list">
                            <div class="front-contact-row">
                                <span class="front-contact-icon"><i class="fa-solid fa-envelope"></i></span>
                                <span><?= htmlspecialchars($person['email']) ?></span>
                            </div>
                            <div class="front-contact-row">
                                <span class="front-contact-icon"><i class="fa-solid fa-phone"></i></span>
                                <span><?= htmlspecialchars($person['phone']) ?></span>
                            </div>
                            <div class="front-contact-row">
                                <span class="front-contact-icon"><i class="fa-solid fa-location-dot"></i></span>
                                <span><?= htmlspecialchars($person['address']) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- BACK SIDE (Rules, Terms and Conditions, Return Policy & Verification QR) -->
        <div class="card-side-box" id="landscapeBackSide">
            <div class="card-perspective-label"><i class="fa-solid fa-shield-halved"></i> Back Side (Reverse)</div>
            <div class="landscape-card">
                
                <!-- Angular Colored Corner Bands -->
                <div class="geom-stripe ls-top-left-1" style="background: var(--theme-dark);"></div>
                <div class="geom-stripe ls-bot-right-1"></div>

                <div class="landscape-back-grid">
                    <!-- Left: Estate Branding, QR Code, and Authority Signature -->
                    <div class="ls-back-left">
                        <div>
                            <?php if (!empty($estate_logo)): ?>
                                <img src="<?= htmlspecialchars($estate_logo) ?>" style="height: 38px; max-width: 60px; object-fit: contain; margin-bottom: 4px;">
                            <?php else: ?>
                                <i class="fa-solid fa-shield-halved" style="font-size: 2rem; color: var(--theme-color); margin-bottom: 4px;"></i>
                            <?php endif; ?>
                            <div style="font-weight: 800; font-size: 0.88rem; color: var(--theme-dark); text-transform: uppercase;">
                                <?= htmlspecialchars($estate_name) ?>
                            </div>
                            <div style="font-size: 0.65rem; color: #64748b; font-weight: 600;">
                                Access Control Division
                            </div>
                        </div>

                        <div class="back-qr-frame" style="width: 72px; height: 72px;">
                            <img src="<?= $qr_url ?>" alt="Verification QR">
                        </div>

                        <div style="font-size: 0.62rem; color: #64748b; font-weight: 700; text-transform: uppercase;">
                            <i class="fa-solid fa-qrcode text-primary"></i> Scan to Verify Pass
                        </div>
                    </div>

                    <!-- Right: Rules, Terms and Conditions, and Return Policy -->
                    <div class="ls-back-right">
                        <div>
                            <div class="back-rules-title"><i class="fa-solid fa-list-check me-1 text-primary"></i> Rules & Regulations</div>
                            <p class="back-rules-text" style="font-size: 0.64rem; line-height: 1.35; margin-bottom: 6px;">
                                Must be presented upon entry/exit at estate security checkpoints. Non-transferable; valid only for the named individual. Fraudulent use is subject to confiscation.
                            </p>

                            <div class="back-rules-title"><i class="fa-solid fa-file-contract me-1 text-primary"></i> Terms & Conditions</div>
                            <p class="back-rules-text" style="font-size: 0.64rem; line-height: 1.35; margin-bottom: 6px;">
                                Official property of <strong><?= htmlspecialchars($estate_name) ?></strong>. Issued for authorized estate operations and perimeter access. Subject to revocation.
                            </p>

                            <div class="back-rules-title" style="color: #b45309;"><i class="fa-solid fa-arrow-rotate-left me-1"></i> Return Policy</div>
                            <p class="back-rules-text" style="font-size: 0.64rem; line-height: 1.35; margin-bottom: 4px;">
                                <strong>IF FOUND:</strong> Please return immediately to the <strong>Main Security Gatehouse</strong> or call Hotline: <strong><?= htmlspecialchars($office_phone) ?></strong> | <strong><?= htmlspecialchars($office_email) ?></strong>.
                            </p>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: flex-end; border-top: 1px solid #e2e8f0; padding-top: 4px;">
                            <div style="font-size: 0.62rem; color: #64748b;">
                                Certified Official Record &bull; Issue Year: <?= $issue_year ?>
                            </div>
                            <div style="font-size: 0.62rem; font-weight: 800; color: #1e3a8a; font-style: italic;">
                                Authorized Security Signature
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

</div>

<script>
// Toggle Orientation: Portrait vs Landscape
function switchOrientation(orientation) {
    const portraitStage = document.getElementById('portraitStage');
    const landscapeStage = document.getElementById('landscapeStage');
    const btnP = document.getElementById('btnOrientationPortrait');
    const btnL = document.getElementById('btnOrientationLandscape');

    if (orientation === 'portrait') {
        portraitStage.style.display = 'flex';
        landscapeStage.style.display = 'none';
        btnP.classList.add('active');
        btnL.classList.remove('active');
    } else {
        portraitStage.style.display = 'none';
        landscapeStage.style.display = 'flex';
        btnL.classList.add('active');
        btnP.classList.remove('active');
    }
}

// Toggle Sides: Both, Front Only, Back Only
function switchSideView(side) {
    const pFront = document.getElementById('portraitFrontSide');
    const pBack = document.getElementById('portraitBackSide');
    const lFront = document.getElementById('landscapeFrontSide');
    const lBack = document.getElementById('landscapeBackSide');

    const btnBoth = document.getElementById('btnSideBoth');
    const btnFront = document.getElementById('btnSideFront');
    const btnBack = document.getElementById('btnSideBack');

    [btnBoth, btnFront, btnBack].forEach(b => b.classList.remove('active'));

    if (side === 'both') {
        pFront.style.display = 'block';
        pBack.style.display = 'block';
        lFront.style.display = 'block';
        lBack.style.display = 'block';
        btnBoth.classList.add('active');
    } else if (side === 'front') {
        pFront.style.display = 'block';
        pBack.style.display = 'none';
        lFront.style.display = 'block';
        lBack.style.display = 'none';
        btnFront.classList.add('active');
    } else if (side === 'back') {
        pFront.style.display = 'none';
        pBack.style.display = 'block';
        lFront.style.display = 'none';
        lBack.style.display = 'block';
        btnBack.classList.add('active');
    }
}

// Interactive Live Theme Color Engine
function setThemeColor(hexColor) {
    document.documentElement.style.setProperty('--theme-color', hexColor);
    
    // Highlight matching chip or custom picker
    document.querySelectorAll('.color-chip').forEach(chip => {
        chip.classList.remove('active');
        if (chip.style.backgroundColor.toLowerCase() === hexColor.toLowerCase()) {
            chip.classList.add('active');
        }
    });

    const picker = document.getElementById('customColorPicker');
    if (picker) {
        picker.value = hexColor;
    }
}
</script>

</body>
</html>
