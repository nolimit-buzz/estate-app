<?php
// resident/settings.php
include 'header.php';
include 'sidebar.php';

$user_id = intval($_SESSION['user_id']);
$estate_id = get_estate_id();
$message = "";
$error = "";

// Handle Profile Updates & Password Changes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $first_name = $conn->real_escape_string($_POST['first_name']);
        $last_name = $conn->real_escape_string($_POST['last_name']);
        $name = trim($first_name . ' ' . $last_name);
        $email = $conn->real_escape_string($_POST['email']);
        $phone = $conn->real_escape_string($_POST['phone']);

        $sql = "UPDATE users SET first_name='$first_name', last_name='$last_name', name='$name', email='$email', phone='$phone' WHERE id=$user_id AND estate_id=$estate_id";
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

            $message = "Profile information updated successfully!";
            logAudit($conn, "Resident Profile Updated", "Settings", "Updated profile info for $name");
        } else {
            $error = "Error updating profile: " . $conn->error;
        }
    } elseif (isset($_POST['change_password'])) {
        $old_pass = $_POST['old_password'];
        $new_pass = $_POST['new_password'];
        $confirm_pass = $_POST['confirm_password'];

        $user_res = $conn->query("SELECT password FROM users WHERE id=$user_id AND estate_id=$estate_id");
        $user_data = $user_res->fetch_assoc();

        if (password_verify($old_pass, $user_data['password'])) {
            if ($new_pass === $confirm_pass && strlen($new_pass) >= 6) {
                $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
                $conn->query("UPDATE users SET password='$hashed' WHERE id=$user_id AND estate_id=$estate_id");
                $message = "Password changed successfully!";
                logAudit($conn, "Resident Password Changed", "Security", "Resident changed account password.");
            } else {
                $error = "New passwords do not match or are shorter than 6 characters.";
            }
        } else {
            $error = "Current password is incorrect.";
        }
    } elseif (isset($_POST['update_theme_preference'])) {
        $theme_color = $conn->real_escape_string($_POST['theme_color']);
        // Save preference in system_settings or user preference
        $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES ($estate_id, 'theme_color', '$theme_color') ON DUPLICATE KEY UPDATE setting_value='$theme_color'");
        $message = "Theme color preference saved!";
        logAudit($conn, "Theme Preference Updated", "Settings", "Set accent theme color to $theme_color");
    }
}

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
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h4 font-bold text-slate-800 m-0"><i class="fa-solid fa-sliders text-primary me-2"></i> Portal Settings & Preferences</h2>
        <p class="text-secondary small mb-0">Manage your profile, account security, and portal appearance.</p>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Profile Information Card -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm glass">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="card-title font-bold m-0 text-slate-800"><i class="fa-solid fa-user-pen text-info me-2"></i> Personal Information</h5>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="d-flex align-items-center gap-3 mb-4 pb-3 border-bottom">
                        <?php if (!empty($res_pic)): ?>
                            <img src="<?php echo htmlspecialchars($res_pic); ?>" id="avatar_preview" alt="Profile Photo" style="width: 70px; height: 70px; border-radius: 50%; object-fit: cover; border: 3px solid #e2e8f0;">
                        <?php else: ?>
                            <div id="avatar_preview_placeholder" style="width: 70px; height: 70px; border-radius: 50%; background: #e2e8f0; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; color: #64748b;">
                                <i class="fa-solid fa-user"></i>
                            </div>
                            <img id="avatar_preview" src="" alt="Profile Photo" style="display:none; width: 70px; height: 70px; border-radius: 50%; object-fit: cover; border: 3px solid #e2e8f0;">
                        <?php endif; ?>
                        <div class="flex-grow-1">
                            <label class="form-label fw-semibold mb-1">Profile Photo</label>
                            <input type="file" name="image" class="form-control form-control-sm" accept="image/*" onchange="previewAvatar(this)">
                            <small class="text-muted">JPG, PNG, WebP up to 5MB</small>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">First Name</label>
                            <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($user_info['first_name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Last Name</label>
                            <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($user_info['last_name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email Address</label>
                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user_info['email'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Phone Number</label>
                            <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($user_info['phone'] ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="mt-4 text-end">
                        <button type="submit" name="update_profile" class="btn btn-primary px-4 fw-semibold">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Save Profile
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Theme & Display Customization Card -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm glass">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="card-title font-bold m-0 text-slate-800"><i class="fa-solid fa-palette text-warning me-2"></i> Display & Theme Customization</h5>
            </div>
            <div class="card-body">
                <div class="mb-4">
                    <label class="form-label fw-semibold d-block">Day & Night Mode</label>
                    <p class="text-secondary small">Toggle between Light and Dark mode for easy viewing day or night.</p>
                    <button type="button" class="theme-toggle-btn px-4 py-2" onclick="toggleAppTheme()">
                        <i class="fa-solid fa-moon me-2"></i> Toggle Day / Night Mode
                    </button>
                </div>

                <hr class="my-4 text-secondary">

                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Primary Accent Theme Color</label>
                        <p class="text-secondary small">Choose your preferred accent color for buttons and highlights.</p>
                        <div class="d-flex align-items-center gap-3">
                            <input type="color" name="theme_color" class="form-control form-control-color" value="<?php echo htmlspecialchars($theme_color); ?>" title="Choose your color">
                            <span class="font-monospace text-secondary"><?php echo htmlspecialchars($theme_color); ?></span>
                        </div>
                    </div>
                    <div class="mt-4 text-end">
                        <button type="submit" name="update_theme_preference" class="btn btn-outline-primary px-4 fw-semibold">
                            <i class="fa-solid fa-wand-magic-sparkles me-1"></i> Save Theme Accent
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Password Card -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm glass">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="card-title font-bold m-0 text-slate-800"><i class="fa-solid fa-lock text-danger me-2"></i> Account Security</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Current Password</label>
                        <input type="password" name="old_password" class="form-control" required>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">New Password</label>
                            <input type="password" name="new_password" class="form-control" required minlength="6">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" required minlength="6">
                        </div>
                    </div>
                    <div class="mt-4 text-end">
                        <button type="submit" name="change_password" class="btn btn-danger px-4 fw-semibold">
                            <i class="fa-solid fa-key me-1"></i> Update Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

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
</script>

<?php include 'footer.php'; ?>
