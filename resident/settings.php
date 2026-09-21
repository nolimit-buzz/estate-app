<?php
// resident/settings.php
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index");
    exit;
}

$user_id = intval($_SESSION['user_id']);
$estate_id = get_estate_id();
$message = "";
$error = "";

// Fetch resident's resident record and zone info
$res_info = $conn->query("
    SELECT r.id as resident_id, r.flat_id, s.zone_id 
    FROM residents r 
    LEFT JOIN flats f ON r.flat_id = f.id 
    LEFT JOIN buildings b ON f.building_id = b.id 
    LEFT JOIN streets s ON b.street_id = s.id 
    WHERE r.user_id = $user_id AND r.estate_id = $estate_id 
    ORDER BY r.id DESC LIMIT 1
")->fetch_assoc();

$resident_rec_id = $res_info['resident_id'] ?? null;
$resident_zone_id = $res_info['zone_id'] ?? null;

// Handle Profile Updates, Contact Change Requests & Password Changes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $first_name = $conn->real_escape_string(trim($_POST['first_name']));
        $last_name = $conn->real_escape_string(trim($_POST['last_name']));
        $name = trim($first_name . ' ' . $last_name);

        // Name and avatar only can be updated directly; Email & Phone are security locked!
        $sql = "UPDATE users SET first_name='$first_name', last_name='$last_name', name='$name' WHERE id=$user_id AND estate_id=$estate_id";
        if ($conn->query($sql)) {
            $_SESSION['name'] = $name;

            // Handle Profile Photo Upload if provided
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $target_dir = "../uploads/";
                if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
                $target_file = $target_dir . time() . "_" . basename($_FILES["image"]["name"]);
                if (move_uploaded_file($_FILES["image"]["tmp_name"], $target_file)) {
                    $escaped_img = $conn->real_escape_string($target_file);
                    $conn->query("UPDATE residents SET image_path='$escaped_img' WHERE user_id=$user_id AND estate_id=$estate_id");
                }
            }

            $message = "Personal profile names and photo updated successfully!";
            if (function_exists('logAudit')) {
                logAudit($conn, "Resident Profile Updated", "Settings", "Updated profile name/photo for $name");
            }
        } else {
            $error = "Error updating profile: " . $conn->error;
        }
    } elseif (isset($_POST['request_contact_change'])) {
        $req_email = trim($_POST['requested_email'] ?? '');
        $req_phone = trim($_POST['requested_phone'] ?? '');
        $reason = trim($conn->real_escape_string($_POST['reason'] ?? ''));
        
        $u_cur = $conn->query("SELECT email, phone, name FROM users WHERE id=$user_id AND estate_id=$estate_id")->fetch_assoc();
        $curr_email = $u_cur['email'] ?? '';
        $curr_phone = $u_cur['phone'] ?? '';
        
        $validation_failed = false;
        
        if (empty($req_email) && empty($req_phone)) {
            $error = "Please provide at least a new email address or new phone number to request.";
            $validation_failed = true;
        }
        
        if (!$validation_failed && !empty($req_email)) {
            if (!filter_var($req_email, FILTER_VALIDATE_EMAIL)) {
                $error = "The requested email address format is invalid.";
                $validation_failed = true;
            } elseif (strtolower($req_email) === strtolower($curr_email)) {
                $error = "The requested email is identical to your current email.";
                $validation_failed = true;
            } elseif (function_exists('isEmailTakenInEstate') && ($taken = isEmailTakenInEstate($conn, $req_email, $estate_id, $user_id))) {
                $error = "The requested email address is already in use by another resident or staff in this estate.";
                $validation_failed = true;
            }
        }
        
        if (!$validation_failed && !empty($req_phone)) {
            $clean_phone = preg_replace('/[^0-9]/', '', $req_phone);
            if (strlen($clean_phone) < 7) {
                $error = "The requested phone number must contain at least 7 digits.";
                $validation_failed = true;
            } elseif ($clean_phone === preg_replace('/[^0-9]/', '', $curr_phone)) {
                $error = "The requested phone number is identical to your current phone number.";
                $validation_failed = true;
            } elseif (function_exists('isPhoneTakenInEstate') && ($taken = isPhoneTakenInEstate($conn, $req_phone, $estate_id, $user_id))) {
                $error = "The requested phone number is already registered to another resident or staff in this estate.";
                $validation_failed = true;
            }
        }
        
        if (!$validation_failed) {
            $esc_req_email = $conn->real_escape_string($req_email);
            $esc_req_phone = $conn->real_escape_string($req_phone);
            $esc_curr_email = $conn->real_escape_string($curr_email);
            $esc_curr_phone = $conn->real_escape_string($curr_phone);
            $z_id_sql = $resident_zone_id ? intval($resident_zone_id) : "NULL";
            $r_id_sql = $resident_rec_id ? intval($resident_rec_id) : "NULL";
            
            // Check for existing pending request
            $chk_p = $conn->query("SELECT id FROM contact_change_requests WHERE user_id=$user_id AND estate_id=$estate_id AND status='pending' LIMIT 1");
            if ($chk_p && $chk_p->num_rows > 0) {
                $p_id = $chk_p->fetch_assoc()['id'];
                $conn->query("UPDATE contact_change_requests SET requested_email='$esc_req_email', requested_phone='$esc_req_phone', reason='$reason', updated_at=NOW() WHERE id=$p_id");
            } else {
                $conn->query("INSERT INTO contact_change_requests (estate_id, zone_id, user_id, resident_id, current_email, requested_email, current_phone, requested_phone, reason, status) 
                              VALUES ($estate_id, $z_id_sql, $user_id, $r_id_sql, '$esc_curr_email', '$esc_req_email', '$esc_curr_phone', '$esc_req_phone', '$reason', 'pending')");
            }
            
            // Dispatch in-app notification to Central and Zonal Admins
            $admin_q = $conn->query("SELECT id FROM users WHERE estate_id=$estate_id AND (role='admin' OR role='superadmin')");
            $resident_name = $u_cur['name'] ?? 'Resident';
            if ($admin_q) {
                while ($adm = $admin_q->fetch_assoc()) {
                    $adm_id = $adm['id'];
                    $notif_msg = $conn->real_escape_string("Resident $resident_name has requested an official contact info change. Click to review in Residents.");
                    $conn->query("INSERT INTO notifications (estate_id, user_id, title, message, type, is_read, created_at) 
                                  VALUES ($estate_id, $adm_id, 'Contact Change Request', '$notif_msg', 'resident_request', 0, NOW())");
                }
            }
            
            if ($resident_zone_id) {
                $zone_admin_q = $conn->query("SELECT id FROM users WHERE estate_id=$estate_id AND role='zone_admin' AND (zone_id=$resident_zone_id OR id IN (SELECT user_id FROM zones WHERE id=$resident_zone_id))");
                if ($zone_admin_q) {
                    while ($z_adm = $zone_admin_q->fetch_assoc()) {
                        $z_adm_id = $z_adm['id'];
                        $notif_msg = $conn->real_escape_string("Resident $resident_name in your zone has requested an official contact info change.");
                        $conn->query("INSERT INTO notifications (estate_id, user_id, title, message, type, is_read, created_at) 
                                      VALUES ($estate_id, $z_adm_id, 'Zonal Contact Change Request', '$notif_msg', 'resident_request', 0, NOW())");
                    }
                }
            }
            
            $message = "Your contact information change request has been submitted successfully! Central and Zonal Administrators have been alerted for approval.";
            if (function_exists('logAudit')) {
                logAudit($conn, "Contact Change Requested", "Settings", "Resident $resident_name requested new email: '$req_email', new phone: '$req_phone'");
            }
        }
    } elseif (isset($_POST['change_password'])) {
        $old_pass = $_POST['old_password'];
        $new_pass = $_POST['new_password'];
        $confirm_pass = $_POST['confirm_password'];

        $user_res = $conn->query("SELECT password FROM users WHERE id=$user_id AND estate_id=$estate_id");
        $user_data = $user_res ? $user_res->fetch_assoc() : null;

        if ($user_data && password_verify($old_pass, $user_data['password'])) {
            if ($new_pass === $confirm_pass && strlen($new_pass) >= 6) {
                $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
                $conn->query("UPDATE users SET password='$hashed' WHERE id=$user_id AND estate_id=$estate_id");
                $message = "Security credentials updated successfully! Please remember your new password.";
                if (function_exists('logAudit')) {
                    logAudit($conn, "Resident Password Changed", "Security", "Resident updated login password.");
                }
            } else {
                $error = "New passwords do not match or are shorter than 6 characters.";
            }
        } else {
            $error = "Current password verification failed. Please check your current password.";
        }
    } elseif (isset($_POST['update_theme_preference'])) {
        $theme_color = $conn->real_escape_string($_POST['theme_color']);
        $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES ($estate_id, 'theme_color', '$theme_color') ON DUPLICATE KEY UPDATE setting_value='$theme_color'");
        $message = "Portal theme accent updated to $theme_color!";
        if (function_exists('logAudit')) {
            logAudit($conn, "Theme Preference Updated", "Settings", "Set accent theme color to $theme_color");
        }
    }
}

// Fetch active pending or recent contact change request
$latest_contact_req = $conn->query("
    SELECT * FROM contact_change_requests 
    WHERE user_id = $user_id AND estate_id = $estate_id 
    ORDER BY id DESC LIMIT 1
")->fetch_assoc();

// Fetch current user details
$user_info = $conn->query("SELECT * FROM users WHERE id=$user_id AND estate_id=$estate_id")->fetch_assoc();

// Fetch resident profile image
$res_pic = '';
$r_res = $conn->query("SELECT image_path FROM residents WHERE user_id=$user_id AND estate_id=$estate_id ORDER BY id DESC LIMIT 1");
if ($r_res && $r_row = $r_res->fetch_assoc()) {
    $raw_pic = $r_row['image_path'] ?? '';
    if (!empty($raw_pic)) {
        if (file_exists($raw_pic)) {
            $res_pic = $raw_pic;
        } elseif (file_exists('../' . ltrim($raw_pic, './'))) {
            $res_pic = '../' . ltrim($raw_pic, './');
        }
    }
}

include 'header.php';
include 'sidebar.php';
?>

<!-- Header Banner -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="mature-badge mature-badge-primary">
                <i class="fa-solid fa-sliders"></i> Account & Preferences
            </span>
            <span class="text-secondary small">• Security Hub</span>
        </div>
        <h2 class="h4 font-bold text-slate-800 m-0">Portal Settings & Customization</h2>
        <p class="text-secondary small mb-0">Configure your personal profile, night/day interface modes, and account credentials.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold d-inline-flex align-items-center gap-2" onclick="toggleAppTheme()">
            <i class="fa-solid fa-circle-half-stroke"></i> Switch Day / Night Mode
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" role="alert" style="border-radius: 12px; background: rgba(16, 185, 129, 0.12); color: #065f46; border-left: 4px solid #10b981 !important;">
        <div class="d-flex align-items-center gap-2">
            <i class="fa-solid fa-circle-check fs-5"></i>
            <div><?= htmlspecialchars($message) ?></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-4" role="alert" style="border-radius: 12px; background: rgba(239, 68, 68, 0.12); color: #991b1b; border-left: 4px solid #ef4444 !important;">
        <div class="d-flex align-items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation fs-5"></i>
            <div><?= htmlspecialchars($error) ?></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($latest_contact_req && $latest_contact_req['status'] === 'pending'): ?>
    <div class="alert border-0 shadow-sm mb-4" style="border-radius: 12px; background: rgba(245, 158, 11, 0.12); color: #92400e; border-left: 4px solid #f59e0b !important;">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
            <div class="d-flex align-items-center gap-2">
                <i class="fa-solid fa-clock-rotate-left fs-5 text-warning"></i>
                <div>
                    <strong>Pending Contact Information Change Request</strong>
                    <div class="small text-secondary">
                        Submitted on <?= date('M d, Y h:i A', strtotime($latest_contact_req['created_at'])) ?>. 
                        <?php if (!empty($latest_contact_req['requested_email'])): ?>
                            Requested Email: <span class="font-monospace fw-semibold text-slate-800"><?= htmlspecialchars($latest_contact_req['requested_email']) ?></span> &bull;
                        <?php endif; ?>
                        <?php if (!empty($latest_contact_req['requested_phone'])): ?>
                            Requested Phone: <span class="font-monospace fw-semibold text-slate-800"><?= htmlspecialchars($latest_contact_req['requested_phone']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <span class="badge bg-warning text-dark px-3 py-1.5 rounded-pill font-monospace"><i class="fa-solid fa-hourglass-half me-1"></i> Awaiting Admin Review</span>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Profile Information Card -->
    <div class="col-lg-7">
        <div class="resident-glass-panel h-100">
            <div class="resident-card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="resident-card-title mb-1">
                        <i class="fa-solid fa-id-card text-primary me-2"></i> Resident Identity Profile
                    </h5>
                    <p class="text-secondary small mb-0">Personal records linked to security gates and billing.</p>
                </div>
                <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-1 rounded-pill small font-monospace">Verified</span>
            </div>
            <div class="p-4">
                <form method="POST" enctype="multipart/form-data">
                    <div class="d-flex flex-column flex-sm-row align-items-center gap-4 mb-4 pb-4 border-bottom">
                        <div class="position-relative">
                            <?php if (!empty($res_pic)): ?>
                                <img src="<?= htmlspecialchars($res_pic) ?>" id="avatar_preview" alt="Profile" style="width: 86px; height: 86px; border-radius: 50%; object-fit: cover; border: 3px solid rgba(59, 130, 246, 0.5); box-shadow: 0 4px 14px rgba(37, 99, 235, 0.2);">
                            <?php else: ?>
                                <div id="avatar_preview_placeholder" style="width: 86px; height: 86px; border-radius: 50%; background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(14, 165, 233, 0.25)); display: flex; align-items: center; justify-content: center; font-size: 2.2rem; color: var(--primary-color, #2563eb); border: 2px dashed rgba(59, 130, 246, 0.4);">
                                    <i class="fa-solid fa-user"></i>
                                </div>
                                <img id="avatar_preview" src="" alt="Profile" style="display:none; width: 86px; height: 86px; border-radius: 50%; object-fit: cover; border: 3px solid rgba(59, 130, 246, 0.5);">
                            <?php endif; ?>
                            <div class="position-absolute bottom-0 end-0 bg-primary text-white rounded-circle d-flex align-items-center justify-content-center shadow" style="width: 26px; height: 26px; font-size: 0.75rem;">
                                <i class="fa-solid fa-camera"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 text-center text-sm-start">
                            <label class="form-label fw-bold mb-1 text-slate-800">Change Profile Photo</label>
                            <div class="text-secondary small mb-2">Upload a high-resolution portrait for instant gate optical scanning and digital ID cards.</div>
                            <input type="file" name="image" class="form-control form-control-sm" accept="image/*" onchange="previewAvatar(this)" style="max-width: 320px;">
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-secondary">FIRST NAME</label>
                            <input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($user_info['first_name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small text-secondary">LAST NAME</label>
                            <input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($user_info['last_name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label fw-semibold small text-secondary mb-0">EMAIL ADDRESS</label>
                                <span class="badge bg-slate-100 text-slate-600 font-monospace" style="font-size: 0.68rem; background: rgba(100, 116, 139, 0.1); color: #475569;">
                                    <i class="fa-solid fa-lock text-warning me-1"></i> Admin Managed
                                </span>
                            </div>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-secondary"><i class="fa-regular fa-envelope"></i></span>
                                <input type="email" class="form-control border-start-0 ps-0 bg-light text-muted" value="<?= htmlspecialchars($user_info['email'] ?? '') ?>" readonly title="Email cannot be edited directly for estate security and access audit trails.">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label fw-semibold small text-secondary mb-0">PHONE NUMBER</label>
                                <span class="badge bg-slate-100 text-slate-600 font-monospace" style="font-size: 0.68rem; background: rgba(100, 116, 139, 0.1); color: #475569;">
                                    <i class="fa-solid fa-lock text-warning me-1"></i> Admin Managed
                                </span>
                            </div>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 text-secondary"><i class="fa-solid fa-phone"></i></span>
                                <input type="text" class="form-control border-start-0 ps-0 bg-light text-muted" value="<?= htmlspecialchars($user_info['phone'] ?? '') ?>" readonly title="Phone cannot be edited directly for gate pass verification.">
                            </div>
                        </div>
                    </div>

                    <!-- Contact Security Notice & Request Change Trigger -->
                    <div class="mt-3 p-3 rounded-3" style="background: rgba(59, 130, 246, 0.05); border: 1px dashed rgba(59, 130, 246, 0.25);">
                        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa-solid fa-shield-halved text-primary fs-5"></i>
                                <div class="small text-secondary">
                                    <strong>Contact info locked for security:</strong> To change your email or phone, submit a request for Admin review.
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm rounded-pill fw-semibold text-nowrap" data-bs-toggle="modal" data-bs-target="#requestContactChangeModal">
                                <i class="fa-solid fa-pen-to-square me-1"></i> Request Contact Change
                            </button>
                        </div>
                    </div>

                    <div class="mt-4 pt-2 text-end">
                        <button type="submit" name="update_profile" class="btn btn-primary px-4 py-2 fw-semibold rounded-pill shadow-sm d-inline-flex align-items-center gap-2">
                            <i class="fa-solid fa-floppy-disk"></i> Save Profile Details
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Contact Change Request Modal -->
    <div class="modal fade" id="requestContactChangeModal" tabindex="-1" aria-labelledby="requestContactChangeLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header border-0 pb-0 pt-4 px-4">
                    <div class="d-flex align-items-center gap-2">
                        <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(59, 130, 246, 0.15); color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                            <i class="fa-solid fa-id-card-clip"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold text-slate-800" id="requestContactChangeLabel">Request Contact Information Update</h5>
                            <p class="text-secondary small mb-0">Changes require Central or Zonal Admin approval.</p>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body p-4">
                        <div class="p-3 mb-3 rounded-3" style="background: #f8fafc; border: 1px solid #e2e8f0; font-size: 0.85rem;">
                            <div class="text-secondary mb-1"><strong>Current Registered Details:</strong></div>
                            <div>Email: <span class="font-monospace text-slate-800"><?= htmlspecialchars($user_info['email'] ?? 'N/A') ?></span></div>
                            <div>Phone: <span class="font-monospace text-slate-800"><?= htmlspecialchars($user_info['phone'] ?? 'N/A') ?></span></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-secondary">NEW DESIRED EMAIL ADDRESS (OPTIONAL)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="fa-regular fa-envelope text-secondary"></i></span>
                                <input type="email" name="requested_email" class="form-control" placeholder="new.email@example.com">
                            </div>
                            <div class="form-text small text-secondary">Leave blank if you are not changing your email.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-secondary">NEW DESIRED PHONE NUMBER (OPTIONAL)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="fa-solid fa-phone text-secondary"></i></span>
                                <input type="text" name="requested_phone" class="form-control" placeholder="+234 800 000 0000">
                            </div>
                            <div class="form-text small text-secondary">Leave blank if you are not changing your phone number.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-secondary">REASON / JUSTIFICATION <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control" rows="3" placeholder="Briefly state why you need to change your registered contact details (e.g. lost phone line, changed corporate email, etc.)" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0 px-4 pb-4">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="request_contact_change" class="btn btn-primary rounded-pill px-4 fw-semibold d-inline-flex align-items-center gap-2">
                            <i class="fa-solid fa-paper-plane"></i> Submit Request to Admin
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Theme & Display Customization Card -->
    <div class="col-lg-5">
        <div class="resident-glass-panel h-100">
            <div class="resident-card-header">
                <h5 class="resident-card-title mb-1">
                    <i class="fa-solid fa-wand-magic-sparkles text-warning me-2"></i> Theme & Hologram Style
                </h5>
                <p class="text-secondary small mb-0">Personalize your portal accent lighting and visual aura.</p>
            </div>
            <div class="p-4">
                <div class="mb-4">
                    <label class="form-label fw-semibold text-slate-800 d-block mb-1">Interface Viewing Mode</label>
                    <p class="text-secondary small mb-3">High-contrast Dark Glassmorphism or Crisp Sunlight Light mode.</p>
                    <button type="button" class="btn btn-outline-secondary w-100 py-2 rounded-pill d-flex align-items-center justify-content-center gap-2 fw-semibold" onclick="toggleAppTheme()">
                        <i class="fa-solid fa-circle-half-stroke text-primary"></i> Toggle Dark / Light Theme
                    </button>
                </div>

                <div class="border-top pt-4">
                    <label class="form-label fw-semibold text-slate-800 d-block mb-1">Primary Color Accent Palette</label>
                    <p class="text-secondary small mb-3">Select a preset or fine-tune with the custom hex wheel.</p>

                    <!-- Preset Color Swatches -->
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <button type="button" class="color-preset-btn" data-color="#2563eb" style="background:#2563eb;" title="Sapphire Blue"></button>
                        <button type="button" class="color-preset-btn" data-color="#0284c7" style="background:#0284c7;" title="Sky Cyan"></button>
                        <button type="button" class="color-preset-btn" data-color="#059669" style="background:#059669;" title="Emerald Green"></button>
                        <button type="button" class="color-preset-btn" data-color="#7c3aed" style="background:#7c3aed;" title="Electric Purple"></button>
                        <button type="button" class="color-preset-btn" data-color="#d97706" style="background:#d97706;" title="Warm Amber"></button>
                        <button type="button" class="color-preset-btn" data-color="#e11d48" style="background:#e11d48;" title="Neon Crimson"></button>
                        <button type="button" class="color-preset-btn" data-color="#0f172a" style="background:#0f172a;" title="Midnight Dark"></button>
                    </div>

                    <form method="POST">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <input type="color" id="theme_color_picker" name="theme_color" class="form-control form-control-color border-0 rounded-circle cursor-pointer" style="width: 48px; height: 48px; padding: 3px;" value="<?= htmlspecialchars($theme_color) ?>" title="Choose custom shade">
                            <div class="flex-grow-1">
                                <div class="small fw-semibold text-secondary">Active Hex Value</div>
                                <div class="font-monospace fw-bold text-slate-800 fs-6" id="theme_color_label"><?= htmlspecialchars($theme_color) ?></div>
                            </div>
                        </div>
                        <button type="submit" name="update_theme_preference" class="btn btn-outline-primary w-100 py-2 rounded-pill fw-semibold">
                            <i class="fa-solid fa-palette me-1"></i> Apply Theme Color
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Account Security Card -->
    <div class="col-lg-12">
        <div class="resident-glass-panel">
            <div class="resident-card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="resident-card-title mb-1">
                        <i class="fa-solid fa-shield-halved text-danger me-2"></i> Account Security & Credentials
                    </h5>
                    <p class="text-secondary small mb-0">Update your residential portal authentication password.</p>
                </div>
                <span class="badge bg-danger bg-opacity-10 text-danger px-3 py-1 rounded-pill small font-monospace">Encrypted</span>
            </div>
            <div class="p-4">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small text-secondary">CURRENT PASSWORD</label>
                            <div class="input-group">
                                <span class="input-group-text bg-transparent border-end-0 text-secondary"><i class="fa-solid fa-key"></i></span>
                                <input type="password" name="old_password" class="form-control border-start-0 ps-0" placeholder="••••••••" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small text-secondary">NEW PASSWORD</label>
                            <div class="input-group">
                                <span class="input-group-text bg-transparent border-end-0 text-secondary"><i class="fa-solid fa-lock"></i></span>
                                <input type="password" name="new_password" class="form-control border-start-0 ps-0" placeholder="Min. 6 characters" required minlength="6">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small text-secondary">CONFIRM NEW PASSWORD</label>
                            <div class="input-group">
                                <span class="input-group-text bg-transparent border-end-0 text-secondary"><i class="fa-solid fa-check-double"></i></span>
                                <input type="password" name="confirm_password" class="form-control border-start-0 ps-0" placeholder="Repeat new password" required minlength="6">
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 text-end">
                        <button type="submit" name="change_password" class="btn btn-danger px-4 py-2 rounded-pill fw-semibold shadow-sm d-inline-flex align-items-center gap-2">
                            <i class="fa-solid fa-shield"></i> Update Security Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.color-preset-btn {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: 2px solid #ffffff;
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
    cursor: pointer;
    transition: transform 0.2s ease;
}
.color-preset-btn:hover {
    transform: scale(1.15);
}
</style>

<script>
function previewAvatar(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('avatar_preview');
            const placeholder = document.getElementById('avatar_preview_placeholder');
            preview.src = e.target.result;
            preview.style.display = 'block';
            if (placeholder) placeholder.style.display = 'none';
        }
        reader.readAsDataURL(input.files[0]);
    }
}

const colorPicker = document.getElementById('theme_color_picker');
const colorLabel = document.getElementById('theme_color_label');

if (colorPicker && colorLabel) {
    colorPicker.addEventListener('input', (e) => {
        colorLabel.textContent = e.target.value.toUpperCase();
    });
}

document.querySelectorAll('.color-preset-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const c = this.getAttribute('data-color');
        if (colorPicker) {
            colorPicker.value = c;
            if (colorLabel) colorLabel.textContent = c.toUpperCase();
        }
    });
});
</script>

<?php include 'footer.php'; ?>
