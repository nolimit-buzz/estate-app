<?php
// admin/branding.php - Tenant White-Labeling & Brand Customization Suite
require_once '../config.php';
require_once '../includes/auth_guard.php';
requireAdminAccess();

$estate_id = get_estate_id();
$message = "";
$error = "";

// Helper for image upload
function handleBrandUpload($file, $prefix = 'brand') {
    if (!empty($file['name']) && $file['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'svg', 'ico'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) return null;
        
        $target_dir = "../uploads/branding/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        
        $clean_basename = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($file["name"]));
        $target_file = $target_dir . $prefix . "_" . time() . "_" . $clean_basename;
        if (move_uploaded_file($file["tmp_name"], $target_file)) {
            return $target_file;
        }
    }
    return null;
}

// Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['save_branding'])) {
    $settings = [
        'estate_name'            => trim($_POST['estate_name'] ?? ''),
        'estate_motto'           => trim($_POST['estate_motto'] ?? ''),
        'estate_location'        => trim($_POST['estate_location'] ?? ''),
        'office_email'           => trim($_POST['office_email'] ?? ''),
        'office_phone'           => trim($_POST['office_phone'] ?? ''),
        'theme_color'            => trim($_POST['theme_color'] ?? '#3b82f6'),
        'accent_color'           => trim($_POST['accent_color'] ?? '#f59e0b'),
        'app_company_name'       => trim($_POST['app_company_name'] ?? 'EstateAdmin'),
        'sms_sender_id'          => strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $_POST['sms_sender_id'] ?? ''), 0, 11)),
        'email_from_name'        => trim($_POST['email_from_name'] ?? ''),
        'gate_pass_disclaimer'   => trim($_POST['gate_pass_disclaimer'] ?? 'Valid for single entry. Must be shown at security gate upon arrival.'),
        'receipt_footer_note'    => trim($_POST['receipt_footer_note'] ?? 'Thank you for your prompt community dues contribution.')
    ];

    // Upsert into system_settings
    foreach ($settings as $key => $val) {
        $val_esc = $conn->real_escape_string($val);
        $chk = $conn->query("SELECT 1 FROM system_settings WHERE estate_id = $estate_id AND setting_key = '$key'");
        if ($chk && $chk->num_rows > 0) {
            $conn->query("UPDATE system_settings SET setting_value = '$val_esc' WHERE estate_id = $estate_id AND setting_key = '$key'");
        } else {
            $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES ($estate_id, '$key', '$val_esc')");
        }
    }

    // Sync canonical name in estates table
    if (!empty($settings['estate_name'])) {
        $esc_name = $conn->real_escape_string($settings['estate_name']);
        $conn->query("UPDATE estates SET name = '$esc_name' WHERE id = $estate_id");
    }

    // Primary Logo Upload
    if (!empty($_FILES['estate_logo']['name'])) {
        $path = handleBrandUpload($_FILES['estate_logo'], 'logo');
        if ($path) {
            $path_esc = $conn->real_escape_string($path);
            $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES ($estate_id, 'estate_logo', '$path_esc') ON DUPLICATE KEY UPDATE setting_value = '$path_esc'");
        }
    }

    // Favicon Upload
    if (!empty($_FILES['estate_favicon']['name'])) {
        $path = handleBrandUpload($_FILES['estate_favicon'], 'favicon');
        if ($path) {
            $path_esc = $conn->real_escape_string($path);
            $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES ($estate_id, 'estate_favicon', '$path_esc') ON DUPLICATE KEY UPDATE setting_value = '$path_esc'");
        }
    }

    // Login Screen Wallpaper Upload
    if (!empty($_FILES['estate_login_bg']['name'])) {
        $path = handleBrandUpload($_FILES['estate_login_bg'], 'hero');
        if ($path) {
            $path_esc = $conn->real_escape_string($path);
            $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES ($estate_id, 'estate_login_bg', '$path_esc') ON DUPLICATE KEY UPDATE setting_value = '$path_esc'");
        }
    }

    logAudit($conn, "Branding Updated", "Settings", "Estate white-label identity and branding parameters updated.");
    $message = "Estate branding & white-label settings updated successfully!";
}

// Fetch current estate branding values
$curr = [];
$res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE estate_id = $estate_id");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $curr[$r['setting_key']] = $r['setting_value'];
    }
}

$e_info = $conn->query("SELECT * FROM estates WHERE id = $estate_id LIMIT 1")->fetch_assoc();
$estate_display_name = $curr['estate_name'] ?? ($e_info['name'] ?? 'Main Estate');
$estate_logo = !empty($curr['estate_logo']) ? get_media_url($curr['estate_logo']) : '';
$estate_favicon = !empty($curr['estate_favicon']) ? get_media_url($curr['estate_favicon']) : '';
$theme_color = $curr['theme_color'] ?? '#3b82f6';
$accent_color = $curr['accent_color'] ?? '#f59e0b';
$sms_sender_id = $curr['sms_sender_id'] ?? 'ESTATE-GATE';
$email_from_name = $curr['email_from_name'] ?? $estate_display_name;

$page_title = "White-Label & Branding Suite";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper p-3 p-md-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h4 class="fw-bold mb-0 text-slate-800">White-Label &amp; Brand Customization</h4>
                <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-1 fw-bold" style="font-size: 0.72rem;">
                    Tenant ID #<?= $estate_id ?>
                </span>
            </div>
            <p class="text-secondary small mb-0 mt-1">
                Personalize your estate portal's visual identity, theme palette, documents, and communication sender IDs.
            </p>
        </div>
        <div>
            <a href="settings" class="btn btn-outline-secondary rounded-pill px-3 py-2 btn-sm fw-semibold">
                <i class="fa-solid fa-gear me-1"></i> General Settings
            </a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0 d-flex align-items-center mb-4">
            <i class="fa-solid fa-circle-check fs-5 me-2 text-success"></i>
            <div><?= htmlspecialchars($message) ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="row g-4">
            
            <!-- LEFT COLUMN: SETTINGS TABS -->
            <div class="col-12 col-xl-8">
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white mb-4">
                    <div class="card-header bg-white border-bottom p-3 px-4">
                        <ul class="nav nav-pills card-header-pills gap-2" id="brandTab" role="tablist">
                            <li class="nav-item">
                                <button class="nav-link active rounded-pill fw-bold btn-sm px-3" data-bs-toggle="tab" data-bs-target="#tab-identity" type="button">
                                    <i class="fa-solid fa-id-badge me-1"></i> Identity &amp; Assets
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link rounded-pill fw-bold btn-sm px-3" data-bs-toggle="tab" data-bs-target="#tab-colors" type="button">
                                    <i class="fa-solid fa-palette me-1"></i> Colors &amp; Styling
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link rounded-pill fw-bold btn-sm px-3" data-bs-toggle="tab" data-bs-target="#tab-comms" type="button">
                                    <i class="fa-solid fa-bullhorn me-1"></i> Sender IDs &amp; Comms
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link rounded-pill fw-bold btn-sm px-3" data-bs-toggle="tab" data-bs-target="#tab-docs" type="button">
                                    <i class="fa-solid fa-print me-1"></i> Documents &amp; Slips
                                </button>
                            </li>
                        </ul>
                    </div>

                    <div class="card-body p-4">
                        <div class="tab-content" id="brandTabContent">
                            
                            <!-- TAB 1: IDENTITY & ASSETS -->
                            <div class="tab-pane fade show active" id="tab-identity" role="tabpanel">
                                <h6 class="fw-bold text-slate-800 mb-3"><i class="fa-solid fa-building text-primary me-2"></i>Estate Profile &amp; Logos</h6>
                                
                                <div class="row g-3 mb-4">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label small fw-bold text-secondary">Estate Public Name *</label>
                                        <input type="text" name="estate_name" class="form-control rounded-3" value="<?= htmlspecialchars($estate_display_name) ?>" required>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label small fw-bold text-secondary">Tagline / Motto</label>
                                        <input type="text" name="estate_motto" class="form-control rounded-3" value="<?= htmlspecialchars($curr['estate_motto'] ?? '') ?>" placeholder="e.g. Serenity, Security & Excellence">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small fw-bold text-secondary">Physical Estate Address</label>
                                        <input type="text" name="estate_location" class="form-control rounded-3" value="<?= htmlspecialchars($curr['estate_location'] ?? '') ?>" placeholder="e.g. Plot 1-10 Horizon Way, Lekki Phase 1, Lagos">
                                    </div>
                                </div>

                                <div class="border-top pt-3 mb-3">
                                    <h6 class="fw-bold text-slate-800 mb-3"><i class="fa-solid fa-image text-primary me-2"></i>Logos &amp; Visual Brand Assets</h6>
                                    
                                    <div class="row g-3">
                                        <!-- Primary Logo -->
                                        <div class="col-12 col-md-6">
                                            <label class="form-label small fw-bold text-secondary">Primary Estate Logo (Light/Dark Navbar)</label>
                                            <input type="file" name="estate_logo" class="form-control rounded-3" accept="image/*">
                                            <small class="text-secondary" style="font-size: 0.72rem;">Recommended: Transparent PNG or SVG (250x60px)</small>
                                            <?php if (!empty($estate_logo)): ?>
                                                <div class="mt-2 p-2 bg-light rounded-3 border d-inline-block">
                                                    <img src="<?= htmlspecialchars($estate_logo) ?>" alt="Current Logo" style="height: 38px; object-fit: contain;">
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Favicon -->
                                        <div class="col-12 col-md-6">
                                            <label class="form-label small fw-bold text-secondary">Browser Favicon</label>
                                            <input type="file" name="estate_favicon" class="form-control rounded-3" accept=".ico,.png,.svg">
                                            <small class="text-secondary" style="font-size: 0.72rem;">Icon displayed in browser tab (32x32px or 64x64px)</small>
                                            <?php if (!empty($estate_favicon)): ?>
                                                <div class="mt-2 p-2 bg-light rounded-3 border d-inline-block">
                                                    <img src="<?= htmlspecialchars($estate_favicon) ?>" alt="Current Favicon" style="width: 28px; height: 28px; object-fit: contain;">
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Login Screen Wallpaper -->
                                        <div class="col-12">
                                            <label class="form-label small fw-bold text-secondary">Custom Login Hero Background</label>
                                            <input type="file" name="estate_login_bg" class="form-control rounded-3" accept="image/*">
                                            <small class="text-secondary" style="font-size: 0.72rem;">Background image for public resident &amp; staff sign-in pages (1920x1080px)</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- TAB 2: COLORS & STYLING -->
                            <div class="tab-pane fade" id="tab-colors" role="tabpanel">
                                <h6 class="fw-bold text-slate-800 mb-3"><i class="fa-solid fa-palette text-primary me-2"></i>Theme Palette &amp; CSS Variables</h6>
                                
                                <div class="row g-4 mb-3">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label small fw-bold text-secondary">Primary Theme Color</label>
                                        <div class="d-flex align-items-center gap-3">
                                            <input type="color" id="primaryColorPicker" name="theme_color" class="form-control form-control-color rounded-3 p-1" value="<?= htmlspecialchars($theme_color) ?>" style="width: 50px; height: 42px; cursor: pointer;" onchange="updateLivePreview()">
                                            <input type="text" id="primaryColorText" class="form-control rounded-3 font-monospace text-uppercase" value="<?= htmlspecialchars($theme_color) ?>" onkeyup="syncColorFromText(this.value, 'primaryColorPicker')">
                                        </div>
                                        <small class="text-secondary" style="font-size: 0.75rem;">Controls buttons, navigation active states, badges &amp; icons.</small>
                                        
                                        <!-- Quick Palette Presets -->
                                        <div class="mt-3">
                                            <span class="small fw-bold text-secondary d-block mb-1">Curated Presets:</span>
                                            <div class="d-flex gap-2">
                                                <button type="button" class="btn p-0 rounded-circle border" style="width: 26px; height: 26px; background: #2563eb;" onclick="setThemePreset('#2563eb')" title="Sapphire Blue"></button>
                                                <button type="button" class="btn p-0 rounded-circle border" style="width: 26px; height: 26px; background: #059669;" onclick="setThemePreset('#059669')" title="Emerald Green"></button>
                                                <button type="button" class="btn p-0 rounded-circle border" style="width: 26px; height: 26px; background: #7c3aed;" onclick="setThemePreset('#7c3aed')" title="Royal Violet"></button>
                                                <button type="button" class="btn p-0 rounded-circle border" style="width: 26px; height: 26px; background: #d97706;" onclick="setThemePreset('#d97706')" title="Warm Amber"></button>
                                                <button type="button" class="btn p-0 rounded-circle border" style="width: 26px; height: 26px; background: #dc2626;" onclick="setThemePreset('#dc2626')" title="Crimson"></button>
                                                <button type="button" class="btn p-0 rounded-circle border" style="width: 26px; height: 26px; background: #0f172a;" onclick="setThemePreset('#0f172a')" title="Slate Dark"></button>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <label class="form-label small fw-bold text-secondary">Secondary Accent Color</label>
                                        <div class="d-flex align-items-center gap-3">
                                            <input type="color" id="accentColorPicker" name="accent_color" class="form-control form-control-color rounded-3 p-1" value="<?= htmlspecialchars($accent_color) ?>" style="width: 50px; height: 42px; cursor: pointer;">
                                            <input type="text" id="accentColorText" class="form-control rounded-3 font-monospace text-uppercase" value="<?= htmlspecialchars($accent_color) ?>" onkeyup="syncColorFromText(this.value, 'accentColorPicker')">
                                        </div>
                                        <small class="text-secondary" style="font-size: 0.75rem;">Used for highlight pills, status indicators &amp; action chips.</small>
                                    </div>
                                </div>
                            </div>

                            <!-- TAB 3: COMMS & SENDER IDS -->
                            <div class="tab-pane fade" id="tab-comms" role="tabpanel">
                                <h6 class="fw-bold text-slate-800 mb-3"><i class="fa-solid fa-bullhorn text-primary me-2"></i>Telecom &amp; Email Outbound Branding</h6>
                                
                                <div class="row g-3">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label small fw-bold text-secondary">SMS Alphanumeric Sender ID *</label>
                                        <input type="text" name="sms_sender_id" maxlength="11" class="form-control rounded-3 font-monospace text-uppercase" value="<?= htmlspecialchars($sms_sender_id) ?>" placeholder="ESTATE-GATE" required>
                                        <small class="text-secondary" style="font-size: 0.72rem;">Maximum 11 alphanumeric characters. Appears as the sender name on resident gate pass SMS.</small>
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <label class="form-label small fw-bold text-secondary">Email "From" Display Header</label>
                                        <input type="text" name="email_from_name" class="form-control rounded-3" value="<?= htmlspecialchars($email_from_name) ?>" placeholder="e.g. Sunrise Valley Admin">
                                        <small class="text-secondary" style="font-size: 0.72rem;">Name displayed in resident inboxes when automated invoices &amp; arrival notices are sent.</small>
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <label class="form-label small fw-bold text-secondary">Official Contact Email</label>
                                        <input type="email" name="office_email" class="form-control rounded-3" value="<?= htmlspecialchars($curr['office_email'] ?? '') ?>" placeholder="office@estate.com">
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <label class="form-label small fw-bold text-secondary">Gate Hotline / Emergency Phone</label>
                                        <input type="text" name="office_phone" class="form-control rounded-3" value="<?= htmlspecialchars($curr['office_phone'] ?? '') ?>" placeholder="0800-ESTATE-SEC">
                                    </div>
                                </div>
                            </div>

                            <!-- TAB 4: DOCUMENTS & SLIPS -->
                            <div class="tab-pane fade" id="tab-docs" role="tabpanel">
                                <h6 class="fw-bold text-slate-800 mb-3"><i class="fa-solid fa-print text-primary me-2"></i>Printable Gate Passes, Slips &amp; Invoices</h6>
                                
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-secondary">Gate Pass Printout Disclaimer / Instructions</label>
                                    <textarea name="gate_pass_disclaimer" class="form-control rounded-3" rows="3"><?= htmlspecialchars($curr['gate_pass_disclaimer'] ?? 'Valid for single entry. Must be shown at security gate upon arrival.') ?></textarea>
                                    <small class="text-secondary" style="font-size: 0.72rem;">Printed at the bottom of physical visitor clearance slips.</small>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-secondary">Payment Receipt &amp; Invoice Footer Note</label>
                                    <textarea name="receipt_footer_note" class="form-control rounded-3" rows="3"><?= htmlspecialchars($curr['receipt_footer_note'] ?? 'Thank you for your prompt community dues contribution.') ?></textarea>
                                    <small class="text-secondary" style="font-size: 0.72rem;">Printed on Paystack invoices and bank deposit confirmation receipts.</small>
                                </div>
                            </div>

                        </div>
                    </div>

                    <div class="card-footer bg-white border-top p-3 px-4 d-flex justify-content-end">
                        <button type="submit" name="save_branding" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Save Brand Settings
                        </button>
                    </div>
                </div>
            </div>

            <!-- RIGHT COLUMN: LIVE BRAND PREVIEW CARD -->
            <div class="col-12 col-xl-4">
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white mb-4 sticky-top" style="top: 20px;">
                    <div class="card-header bg-dark text-white p-3 px-4">
                        <h6 class="fw-bold mb-0"><i class="fa-solid fa-eye text-warning me-2"></i>Live Brand Identity Preview</h6>
                        <small class="text-secondary" style="font-size: 0.72rem;">Real-time preview of how your brand looks to residents</small>
                    </div>
                    
                    <div class="card-body p-4">
                        <!-- Mock Portal Header -->
                        <div class="p-3 rounded-3 mb-3 border d-flex align-items-center justify-content-between" style="background: #0f172a; color: #fff;">
                            <div class="d-flex align-items-center gap-2">
                                <div id="previewLogoBox" style="width: 30px; height: 30px; border-radius: 6px; background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; overflow: hidden;">
                                    <?php if (!empty($estate_logo)): ?>
                                        <img src="<?= htmlspecialchars($estate_logo) ?>" alt="Logo" style="width: 100%; height: 100%; object-fit: contain;">
                                    <?php else: ?>
                                        <i class="fa-solid fa-building text-light" style="font-size: 0.85rem;"></i>
                                    <?php endif; ?>
                                </div>
                                <span class="fw-bold small" id="previewEstateName"><?= htmlspecialchars($estate_display_name) ?></span>
                            </div>
                            <span class="badge rounded-pill" id="previewBadge" style="background: <?= htmlspecialchars($theme_color) ?>; font-size: 0.65rem;">Active</span>
                        </div>

                        <!-- Mock Button & UI Elements -->
                        <div class="mb-3">
                            <small class="text-secondary fw-semibold d-block mb-2">Button &amp; Accent Highlights:</small>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm text-white rounded-pill px-3 fw-bold" id="previewBtn" style="background: <?= htmlspecialchars($theme_color) ?>;">
                                    Primary Action
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                                    Secondary
                                </button>
                            </div>
                        </div>

                        <!-- Mock Gate Pass SMS Bubble -->
                        <div class="p-3 rounded-4 bg-light border mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="badge bg-dark rounded-pill" id="previewSmsSender" style="font-size: 0.65rem;"><?= htmlspecialchars($sms_sender_id) ?></span>
                                <small class="text-secondary" style="font-size: 0.68rem;">Just now</small>
                            </div>
                            <p class="small text-secondary mb-0" style="font-size: 0.78rem; line-height: 1.35;">
                                GATE PASS [<span id="previewSmsEstate"><?= htmlspecialchars($estate_display_name) ?></span>]: Your access code is <strong>459002</strong>. Present to security at gate.
                            </p>
                        </div>

                        <!-- Custom Domain Notice -->
                        <div class="p-2 bg-primary bg-opacity-10 text-primary rounded-3 small d-flex align-items-center gap-2" style="font-size: 0.75rem;">
                            <i class="fa-solid fa-globe fs-5"></i>
                            <div>
                                Dedicated Subdomain: <strong><?= htmlspecialchars($e_info['domain_prefix'] ?? 'main') ?>.estateapp.com</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </form>
</div>

<script>
function updateLivePreview() {
    const col = document.getElementById('primaryColorPicker').value;
    document.getElementById('primaryColorText').value = col;
    document.getElementById('previewBadge').style.background = col;
    document.getElementById('previewBtn').style.background = col;
}

function syncColorFromText(val, pickerId) {
    if (/^#[0-9A-F]{6}$/i.test(val)) {
        document.getElementById(pickerId).value = val;
        updateLivePreview();
    }
}

function setThemePreset(hex) {
    document.getElementById('primaryColorPicker').value = hex;
    document.getElementById('primaryColorText').value = hex;
    updateLivePreview();
}
</script>

<?php include '../includes/footer.php'; ?>
