<?php
// artisan_register.php - Multi-Portal Artisan Onboarding & Accreditation Registration
require_once 'config.php';
require_once 'includes/ArtisanHelper.php';

$estate_id = function_exists('get_estate_id') ? get_estate_id() : 1;
$branding = function_exists('get_estate_branding') ? get_estate_branding($conn) : [];
$estate_name = $branding['estate_name'] ?? 'Main Estate';
$estate_logo = $branding['estate_logo_url'] ?? '';

// Determine referral source
$ref_source = htmlspecialchars($_GET['ref'] ?? ($_POST['ref_source'] ?? 'public'));
$referrer_id = isset($_GET['referrer_id']) ? intval($_GET['referrer_id']) : (isset($_POST['referrer_id']) ? intval($_POST['referrer_id']) : null);

// If resident is logged in and opening this form, pre-tag referrer
if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'resident') {
    $ref_source = 'resident';
    $referrer_id = intval($_SESSION['user_id']);
} elseif (isset($_SESSION['user_id']) && in_array(($_SESSION['role'] ?? ''), ['admin', 'superadmin'])) {
    $ref_source = 'admin';
    $referrer_id = intval($_SESSION['user_id']);
}

$success_data = null;
$error_msg = '';

$trades = ArtisanHelper::getTradeCategories();

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register_artisan') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $alt_phone = trim($_POST['alt_phone'] ?? '');
    $trade_category = trim($_POST['trade_category'] ?? 'handyman');
    $specialties = trim($_POST['specialties'] ?? '');
    $years_experience = intval($_POST['years_experience'] ?? 1);
    $business_name = trim($_POST['business_name'] ?? '');
    $residential_address = trim($_POST['residential_address'] ?? '');
    $id_type = trim($_POST['id_type'] ?? 'nin');
    $id_number = trim($_POST['id_number'] ?? '');
    $base_inspection_fee = floatval($_POST['base_inspection_fee'] ?? 0.00);
    $is_emergency_ready = isset($_POST['is_emergency_ready']) ? 1 : 0;
    
    $guarantor_name = trim($_POST['guarantor_name'] ?? '');
    $guarantor_phone = trim($_POST['guarantor_phone'] ?? '');
    $guarantor_address = trim($_POST['guarantor_address'] ?? '');

    // Validation
    if (empty($full_name)) {
        $error_msg = "Please enter your full name.";
    } elseif (empty($phone)) {
        $error_msg = "Please provide an active phone number.";
    } elseif (empty($residential_address)) {
        $error_msg = "Residential or business address is required.";
    } elseif (empty($id_number)) {
        $error_msg = "Government ID / NIN number is required for estate security verification.";
    } else {
        // Check if phone number already registered for this trade in estate
        $check_phone = $conn->real_escape_string($phone);
        $chk_q = $conn->query("SELECT id, verification_status, artisan_code FROM artisans WHERE estate_id = $estate_id AND phone = '$check_phone' LIMIT 1");
        if ($chk_q && $chk_q->num_rows > 0) {
            $existing = $chk_q->fetch_assoc();
            $st = $existing['verification_status'];
            if ($st === 'verified') {
                $error_msg = "An artisan with phone number $phone is already registered and verified (Badge: {$existing['artisan_code']}). Please contact Central Admin for profile updates.";
            } elseif ($st === 'pending') {
                $error_msg = "An application with phone number $phone has already been submitted and is currently pending Central Admin verification.";
            }
        }
    }

    // Process File Uploads if no errors
    $id_document_path = null;
    $profile_photo_path = null;

    if (empty($error_msg)) {
        $target_dir = __DIR__ . '/uploads/artisans/';
        if (!is_dir($target_dir)) {
            @mkdir($target_dir, 0755, true);
        }

        // 1. Profile Photo
        if (!empty($_FILES['profile_photo']['name']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $photo_ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            if (in_array($photo_ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $new_photo_name = 'photo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $photo_ext;
                $dest = $target_dir . $new_photo_name;
                if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $dest)) {
                    $profile_photo_path = 'uploads/artisans/' . $new_photo_name;
                }
            } else {
                $error_msg = "Invalid profile photo format. Allowed: JPG, PNG, WEBP.";
            }
        }

        // 2. ID Document Scan
        if (empty($error_msg) && !empty($_FILES['id_document']['name']) && $_FILES['id_document']['error'] === UPLOAD_ERR_OK) {
            $doc_ext = strtolower(pathinfo($_FILES['id_document']['name'], PATHINFO_EXTENSION));
            if (in_array($doc_ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                $new_doc_name = 'id_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $doc_ext;
                $dest = $target_dir . $new_doc_name;
                if (move_uploaded_file($_FILES['id_document']['tmp_name'], $dest)) {
                    $id_document_path = 'uploads/artisans/' . $new_doc_name;
                }
            } else {
                $error_msg = "Invalid ID document format. Allowed: JPG, PNG, PDF.";
            }
        }
    }

    // Insert into Database
    if (empty($error_msg)) {
        $stmt = $conn->prepare("INSERT INTO artisans 
            (estate_id, full_name, email, phone, alt_phone, trade_category, specialties, years_experience, 
             business_name, residential_address, id_type, id_number, id_document_path, profile_photo, 
             guarantor_name, guarantor_phone, guarantor_address, base_inspection_fee, is_emergency_ready, 
             referred_by_type, referred_by_id, verification_status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
        
        $stmt->bind_param("issssssisssssssssisii", 
            $estate_id, $full_name, $email, $phone, $alt_phone, $trade_category, $specialties, $years_experience,
            $business_name, $residential_address, $id_type, $id_number, $id_document_path, $profile_photo_path,
            $guarantor_name, $guarantor_phone, $guarantor_address, $base_inspection_fee, $is_emergency_ready,
            $ref_source, $referrer_id
        );

        if ($stmt->execute()) {
            $new_artisan_id = $stmt->insert_id;
            
            // Build notification payload
            $notify_payload = [
                'estate_id'        => $estate_id,
                'full_name'        => $full_name,
                'trade_category'   => $trade_category,
                'phone'            => $phone,
                'email'            => $email,
                'years_experience' => $years_experience,
                'referred_by_type' => $ref_source
            ];

            // Trigger Instant Multi-Channel Alerts to Central Admin
            ArtisanHelper::notifyAdminNewRegistration($conn, $new_artisan_id, $notify_payload);

            // Audit Log if available
            if (function_exists('logAudit')) {
                logAudit($conn, "Artisan Registration Submitted", "Maintenance", "New application #$new_artisan_id ($full_name - $trade_category) submitted from $ref_source portal.");
            }

            $success_data = [
                'id' => $new_artisan_id,
                'ref_code' => 'APP-ART-' . sprintf("%04d", $new_artisan_id),
                'name' => $full_name,
                'trade' => ArtisanHelper::getTradeInfo($trade_category)['label'],
                'phone' => $phone
            ];
        } else {
            $error_msg = "Database error saving application: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Artisan Accreditation & Onboarding - <?php echo htmlspecialchars($estate_name); ?></title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 & FontAwesome -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-soft: #eff6ff;
            --slate-900: #0f172a;
            --slate-800: #1e293b;
            --slate-700: #334155;
            --slate-600: #475569;
            --slate-100: #f1f5f9;
            --slate-50: #f8fafc;
        }
        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            color: var(--slate-800);
            min-height: 100vh;
            padding-bottom: 3rem;
        }
        .app-navbar {
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        .portal-card {
            background: #ffffff;
            border-radius: 1.25rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }
        .portal-card-header {
            background: linear-gradient(135deg, var(--slate-900) 0%, var(--slate-800) 100%);
            color: #ffffff;
            padding: 2.25rem 2rem;
            position: relative;
        }
        .form-section-title {
            font-size: 0.825rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #64748b;
            font-weight: 700;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .form-section-title::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }
        .form-label {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--slate-700);
            margin-bottom: 0.35rem;
        }
        .form-control, .form-select {
            border-radius: 0.65rem;
            border: 1px solid #cbd5e1;
            padding: 0.7rem 0.95rem;
            font-size: 0.95rem;
            font-family: inherit;
            transition: all 0.15s ease-in-out;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        .quarantine-notice {
            background: #fef3c7;
            border: 1px solid #fde68a;
            border-left: 5px solid #f59e0b;
            border-radius: 0.75rem;
            padding: 1.25rem;
        }
        .submit-btn {
            background: var(--primary);
            color: #ffffff;
            font-weight: 700;
            padding: 0.9rem 2rem;
            border-radius: 0.75rem;
            border: none;
            font-size: 1.05rem;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
            transition: all 0.2s;
        }
        .submit-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.4);
            color: #ffffff;
        }
        .step-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: rgba(255,255,255,0.15);
            padding: 0.35rem 0.85rem;
            border-radius: 9999px;
            font-size: 0.78rem;
            font-weight: 600;
        }
    </style>
</head>
<body>

    <!-- Header Navigation -->
    <header class="app-navbar">
        <div class="container d-flex justify-content-between align-items-center">
            <a href="index" class="text-decoration-none d-flex align-items-center gap-2">
                <?php if (!empty($estate_logo)): ?>
                    <img src="<?php echo htmlspecialchars($estate_logo); ?>" alt="Logo" style="height: 38px; border-radius: 6px;">
                <?php else: ?>
                    <div style="width: 38px; height: 38px; border-radius: 8px; background: rgba(37,99,235,0.1); color: var(--primary); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.1rem;">
                        <i class="fa-solid fa-screwdriver-wrench"></i>
                    </div>
                <?php endif; ?>
                <div>
                    <div style="font-weight: 800; color: var(--slate-900); font-size: 1.1rem; line-height: 1.1;"><?php echo htmlspecialchars($estate_name); ?></div>
                    <div style="font-size: 0.75rem; color: #64748b; font-weight: 600; text-transform: uppercase;">Facility &amp; Artisan Network</div>
                </div>
            </a>
            
            <div class="d-flex align-items-center gap-2">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <?php if (($_SESSION['role'] ?? '') === 'resident'): ?>
                        <a href="resident/index" class="btn btn-sm btn-outline-secondary rounded-pill px-3"><i class="fa-solid fa-arrow-left me-1"></i> Resident Portal</a>
                    <?php elseif (in_array($_SESSION['role'] ?? '', ['admin', 'superadmin'])): ?>
                        <a href="admin/artisans" class="btn btn-sm btn-outline-secondary rounded-pill px-3"><i class="fa-solid fa-shield-halved me-1"></i> Admin Console</a>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="index" class="btn btn-sm btn-outline-secondary rounded-pill px-3"><i class="fa-solid fa-house me-1"></i> Home</a>
                    <a href="login" class="btn btn-sm btn-primary rounded-pill px-3">Login</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-9 col-xl-8">

                <?php if ($success_data): ?>
                    <!-- Success Confirmation Card -->
                    <div class="portal-card p-4 p-md-5 text-center mt-3">
                        <div style="width: 80px; height: 80px; border-radius: 50%; background: #dcfce7; color: #16a34a; font-size: 2.2rem; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 1.5rem;">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <h2 class="h3 font-bold text-slate-900 mb-2">Application Submitted Successfully!</h2>
                        <p class="text-secondary mb-4" style="max-width: 500px; margin-left: auto; margin-right: auto;">
                            Your artisan onboarding request has been registered and safely routed to the <strong>Central Estate Administration</strong>.
                        </p>

                        <div class="card bg-light border-0 rounded-4 p-4 text-start mb-4" style="max-width: 520px; margin: 0 auto;">
                            <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-2">
                                <span class="text-muted small">Tracking Reference:</span>
                                <span class="fw-bold text-primary font-monospace fs-6"><?php echo htmlspecialchars($success_data['ref_code']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-2">
                                <span class="text-muted small">Applicant Name:</span>
                                <span class="fw-bold text-dark"><?php echo htmlspecialchars($success_data['name']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-2">
                                <span class="text-muted small">Accredited Trade:</span>
                                <span class="badge bg-primary text-white rounded-pill px-3 py-1"><?php echo htmlspecialchars($success_data['trade']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small">Current Gate Status:</span>
                                <span class="badge bg-warning text-dark rounded-pill px-3 py-1">
                                    <i class="fa-solid fa-hourglass-half me-1"></i> Pending Central Admin Verification
                                </span>
                            </div>
                        </div>

                        <!-- What happens next box -->
                        <div class="quarantine-notice text-start mb-4" style="max-width: 520px; margin: 0 auto;">
                            <h6 class="fw-bold text-warning-emphasis mb-2"><i class="fa-solid fa-shield-halved me-1"></i> What Happens Next?</h6>
                            <ol class="small text-secondary ps-3 m-0" style="line-height: 1.6;">
                                <li>The Central Admin team has received immediate notification via Email, WhatsApp, and the Admin Console.</li>
                                <li>Your uploaded identification and background details will be vetted by the facility desk.</li>
                                <li>Upon confirmation, you will receive an official Estate Artisan Badge code and your profile will be activated in the Resident Maintenance Directory!</li>
                            </ol>
                        </div>

                        <div class="d-flex justify-content-center gap-3">
                            <a href="index" class="btn btn-outline-secondary rounded-pill px-4">Back to Home</a>
                            <?php if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'resident'): ?>
                                <a href="resident/artisans" class="btn btn-primary rounded-pill px-4">View Resident Directory</a>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else: ?>

                    <!-- Registration Form -->
                    <div class="portal-card">
                        <div class="portal-card-header">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="step-pill"><i class="fa-solid fa-shield-halved text-warning"></i> Estate Vetted Artisan Program</span>
                                <?php if ($ref_source === 'resident'): ?>
                                    <span class="step-pill" style="background: rgba(59,130,246,0.3);"><i class="fa-solid fa-house-user"></i> Resident Referral</span>
                                <?php elseif ($ref_source === 'admin'): ?>
                                    <span class="step-pill" style="background: rgba(16,185,129,0.3);"><i class="fa-solid fa-user-gear"></i> Admin Walk-in</span>
                                <?php endif; ?>
                            </div>
                            <h1 class="h3 font-bold mb-1 text-white">Artisan Onboarding &amp; Accreditation</h1>
                            <p class="text-white-50 mb-0 small">
                                Register your trade skills to receive maintenance requests from estate residents and authorized facility work orders.
                            </p>
                        </div>

                        <div class="p-4 p-md-5">

                            <!-- Quarantine & Pre-Confirmation Banner -->
                            <div class="quarantine-notice mb-4">
                                <div class="d-flex gap-3 align-items-start">
                                    <div style="font-size: 1.5rem; color: #d97706; flex-shrink: 0;">
                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                    </div>
                                    <div>
                                        <h6 class="fw-bold text-dark mb-1">Central Admin Confirmation Required Before Onboarding</h6>
                                        <p class="small text-secondary mb-0">
                                            To ensure the safety and security of all estate households, every artisan application is quarantined. 
                                            <strong>No artisan will appear in the resident directory or be eligible for gate clearance until confirmed and vetted by Central Administration.</strong>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($error_msg)): ?>
                                <div class="alert alert-danger d-flex align-items-center gap-2 rounded-3 mb-4">
                                    <i class="fa-solid fa-circle-exclamation flex-shrink-0"></i>
                                    <div><?php echo htmlspecialchars($error_msg); ?></div>
                                </div>
                            <?php endif; ?>

                            <form method="POST" action="artisan_register.php" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="register_artisan">
                                <input type="hidden" name="ref_source" value="<?php echo htmlspecialchars($ref_source); ?>">
                                <input type="hidden" name="referrer_id" value="<?php echo htmlspecialchars($referrer_id ?? ''); ?>">

                                <!-- SECTION 1: Personal & Contact Information -->
                                <div class="form-section-title">
                                    <i class="fa-solid fa-user text-primary"></i> 1. Personal &amp; Contact Details
                                </div>

                                <div class="row g-3 mb-4">
                                    <div class="col-md-7">
                                        <label class="form-label">Full Legal Name <span class="text-danger">*</span></label>
                                        <input type="text" name="full_name" class="form-control" placeholder="e.g. Samuel Adeleke" required value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label">Business / Workshop Name</label>
                                        <input type="text" name="business_name" class="form-control" placeholder="e.g. Sam Fix Solutions" value="<?php echo htmlspecialchars($_POST['business_name'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Primary Phone (WhatsApp Ready) <span class="text-danger">*</span></label>
                                        <input type="tel" name="phone" class="form-control" placeholder="e.g. 08012345678" required value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                                        <div class="form-text small">Used for instant job dispatch alerts and admin vetting.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Alternative / Emergency Phone</label>
                                        <input type="tel" name="alt_phone" class="form-control" placeholder="e.g. 08198765432" value="<?php echo htmlspecialchars($_POST['alt_phone'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email Address (Optional)</label>
                                        <input type="email" name="email" class="form-control" placeholder="artisan@gmail.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Passport / Headshot Photo</label>
                                        <input type="file" name="profile_photo" class="form-control" accept="image/jpeg,image/png,image/webp">
                                        <div class="form-text small">Clear face photo for your official estate ID profile.</div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Residential or Workshop Address <span class="text-danger">*</span></label>
                                        <textarea name="residential_address" class="form-control" rows="2" placeholder="Full physical street address..." required><?php echo htmlspecialchars($_POST['residential_address'] ?? ''); ?></textarea>
                                    </div>
                                </div>

                                <!-- SECTION 2: Trade & Skills Specialization -->
                                <div class="form-section-title">
                                    <i class="fa-solid fa-wrench text-primary"></i> 2. Trade &amp; Service Expertise
                                </div>

                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label">Primary Trade Skill <span class="text-danger">*</span></label>
                                        <select name="trade_category" class="form-select" required>
                                            <option value="">-- Select Your Primary Trade --</option>
                                            <?php foreach ($trades as $key => $trade): ?>
                                                <option value="<?php echo $key; ?>" <?php echo (isset($_POST['trade_category']) && $_POST['trade_category'] === $key) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($trade['label']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Years of Experience <span class="text-danger">*</span></label>
                                        <input type="number" name="years_experience" class="form-control" min="1" max="50" value="<?php echo htmlspecialchars($_POST['years_experience'] ?? '3'); ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Base Inspection Fee (₦)</label>
                                        <input type="number" name="base_inspection_fee" class="form-control" step="100" min="0" placeholder="e.g. 2000" value="<?php echo htmlspecialchars($_POST['base_inspection_fee'] ?? ''); ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Specific Specialties &amp; Highlights</label>
                                        <input type="text" name="specialties" class="form-control" placeholder="e.g. Inverter wiring, pressure pump repair, POP repair, Italian tile laying" value="<?php echo htmlspecialchars($_POST['specialties'] ?? ''); ?>">
                                    </div>
                                    <div class="col-12">
                                        <div class="form-check form-switch p-3 bg-light rounded-3 border">
                                            <input class="form-check-input ms-0 me-3" type="checkbox" name="is_emergency_ready" id="emergencyToggle" value="1" <?php echo isset($_POST['is_emergency_ready']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label fw-bold text-dark" for="emergencyToggle">
                                                <i class="fa-solid fa-bolt text-warning me-1"></i> Available for 24/7 Emergency Repairs
                                                <span class="d-block fw-normal text-secondary small">Check this if you can respond to late-night or urgent pipe bursts, electrical blackouts, or leaks.</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- SECTION 3: Identity & Security Verification -->
                                <div class="form-section-title">
                                    <i class="fa-solid fa-id-card text-primary"></i> 3. Identification &amp; Security Vetting
                                </div>

                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label">Government ID Type <span class="text-danger">*</span></label>
                                        <select name="id_type" class="form-select" required>
                                            <option value="nin" <?php echo (isset($_POST['id_type']) && $_POST['id_type'] === 'nin') ? 'selected' : ''; ?>>National Identification Number (NIN)</option>
                                            <option value="drivers_license" <?php echo (isset($_POST['id_type']) && $_POST['id_type'] === 'drivers_license') ? 'selected' : ''; ?>>Driver's License</option>
                                            <option value="voters_card" <?php echo (isset($_POST['id_type']) && $_POST['id_type'] === 'voters_card') ? 'selected' : ''; ?>>Voter's Card</option>
                                            <option value="national_id" <?php echo (isset($_POST['id_type']) && $_POST['id_type'] === 'national_id') ? 'selected' : ''; ?>>National ID Card</option>
                                            <option value="passport" <?php echo (isset($_POST['id_type']) && $_POST['id_type'] === 'passport') ? 'selected' : ''; ?>>International Passport</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">ID / NIN Number <span class="text-danger">*</span></label>
                                        <input type="text" name="id_number" class="form-control" placeholder="Enter government identification number" required value="<?php echo htmlspecialchars($_POST['id_number'] ?? ''); ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Upload Scanned ID Card / Document (JPG, PNG, PDF)</label>
                                        <input type="file" name="id_document" class="form-control" accept="image/jpeg,image/png,application/pdf">
                                        <div class="form-text small">Required for Central Admin verification. Kept strictly confidential under privacy policy.</div>
                                    </div>
                                </div>

                                <!-- SECTION 4: Referee / Guarantor Details -->
                                <div class="form-section-title">
                                    <i class="fa-solid fa-handshake text-primary"></i> 4. Referee / Guarantor Information
                                </div>

                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label">Guarantor Full Name</label>
                                        <input type="text" name="guarantor_name" class="form-control" placeholder="e.g. Chief O. Johnson" value="<?php echo htmlspecialchars($_POST['guarantor_name'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Guarantor Phone Number</label>
                                        <input type="tel" name="guarantor_phone" class="form-control" placeholder="e.g. 08033334444" value="<?php echo htmlspecialchars($_POST['guarantor_phone'] ?? ''); ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Guarantor Physical Address</label>
                                        <input type="text" name="guarantor_address" class="form-control" placeholder="Street address or workplace of guarantor" value="<?php echo htmlspecialchars($_POST['guarantor_address'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="border-top pt-4 text-center">
                                    <button type="submit" class="submit-btn w-100 py-3">
                                        <i class="fa-solid fa-paper-plane me-2"></i> Submit Application for Central Admin Verification
                                    </button>
                                    <div class="mt-2 text-secondary small">
                                        By submitting, you agree to background security checks in accordance with the estate bylaws.
                                    </div>
                                </div>

                            </form>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

</body>
</html>
