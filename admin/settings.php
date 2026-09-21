<?php
// admin/settings.php
require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/Mailer.php';
require_once '../includes/emergency_roster_init.php';
requireAdminAccess();

include '../includes/header.php';
include '../includes/sidebar.php';

// Helper to handle uploads
if (!function_exists('handleUpload')) {
    function handleUpload($file) {
        if (!empty($file['name']) && $file['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'svg', 'ico'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) {
                return null;
            }
            $target_dir = "../uploads/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            $clean_basename = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($file["name"]));
            $target_file = $target_dir . time() . "_" . $clean_basename;
            if (move_uploaded_file($file["tmp_name"], $target_file)) {
                return $target_file; 
            }
        }
        return null;
    }
}

// Handle Actions
$message = "";
$error = "";
$active_tab = $_GET['tab'] ?? 'general';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Update General Settings & Estate Branding
    if (isset($_POST['update_general'])) {
        $estate_id = get_estate_id();
        
        $settings = [
            'estate_name' => trim($_POST['estate_name'] ?? ''),
            'estate_location' => trim($_POST['estate_location'] ?? ''),
            'estate_motto' => trim($_POST['estate_motto'] ?? ''),
            'estate_rules' => trim($_POST['estate_rules'] ?? ''),
            'estate_code_of_conduct' => trim($_POST['estate_code_of_conduct'] ?? ''),
            'currency_symbol' => trim($_POST['currency_symbol'] ?? ''),
            'office_email' => trim($_POST['office_email'] ?? ''),
            'office_phone' => trim($_POST['office_phone'] ?? ''),
            'theme_color' => trim($_POST['theme_color'] ?? '#3b82f6'),
            'app_company_name' => trim($_POST['app_company_name'] ?? 'NoLimitBuzz')
        ];
        
        // Upsert settings into system_settings
        foreach ($settings as $key => $val) {
            $val = $conn->real_escape_string($val);
            $check = $conn->query("SELECT 1 FROM system_settings WHERE setting_key = '$key' AND estate_id = $estate_id");
            if ($check && $check->num_rows > 0) {
                $conn->query("UPDATE system_settings SET setting_value = '$val' WHERE setting_key = '$key' AND estate_id = $estate_id");
            } else {
                $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES ($estate_id, '$key', '$val')");
            }
        }
        
        // Synchronize canonical estate name in the estates table
        if (!empty($settings['estate_name'])) {
            $esc_name = $conn->real_escape_string($settings['estate_name']);
            $conn->query("UPDATE estates SET name = '$esc_name' WHERE id = $estate_id");
        }

        // Check if estate logo removal was requested
        if (!empty($_POST['remove_estate_logo'])) {
            $conn->query("UPDATE system_settings SET setting_value = '' WHERE setting_key = 'estate_logo' AND estate_id = $estate_id");
        }

        // Estate Logo Upload
        if (!empty($_FILES['estate_logo']['name'])) {
            $path = handleUpload($_FILES['estate_logo']);
            if ($path) {
                $path = $conn->real_escape_string($path);
                $check = $conn->query("SELECT 1 FROM system_settings WHERE setting_key = 'estate_logo' AND estate_id = $estate_id");
                if ($check && $check->num_rows > 0) {
                    $conn->query("UPDATE system_settings SET setting_value = '$path' WHERE setting_key = 'estate_logo' AND estate_id = $estate_id");
                } else {
                    $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES ($estate_id, 'estate_logo', '$path')");
                }
            }
        }
        
        // App Producer Company Logo Upload
        if (!empty($_FILES['app_company_logo']['name'])) {
            $path = handleUpload($_FILES['app_company_logo']);
            if ($path) {
                $path = $conn->real_escape_string($path);
                $check = $conn->query("SELECT 1 FROM system_settings WHERE setting_key = 'app_company_logo' AND estate_id = $estate_id");
                if ($check && $check->num_rows > 0) {
                    $conn->query("UPDATE system_settings SET setting_value = '$path' WHERE setting_key = 'app_company_logo' AND estate_id = $estate_id");
                } else {
                    $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES ($estate_id, 'app_company_logo', '$path')");
                }
            }
        }

        // Hero Image Upload
        if (!empty($_FILES['estate_hero_image']['name'])) {
            $path = handleUpload($_FILES['estate_hero_image']);
            if ($path) {
                $path = $conn->real_escape_string($path);
                $check = $conn->query("SELECT 1 FROM system_settings WHERE setting_key = 'estate_hero_image' AND estate_id = $estate_id");
                if ($check && $check->num_rows > 0) {
                    $conn->query("UPDATE system_settings SET setting_value = '$path' WHERE setting_key = 'estate_hero_image' AND estate_id = $estate_id");
                } else {
                    $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES ($estate_id, 'estate_hero_image', '$path')");
                }
            }
        }
        
        logAudit($conn, "Estate Branding Updated", "Settings", "Estate name, logo and general branding updated.");
        $message = "Estate settings & logo updated successfully! Changes are live across all headers.";
    } elseif (isset($_POST['update_paystack'])) {
        $estate_id = get_estate_id();
        $pub_key = $conn->real_escape_string($_POST['paystack_public_key'] ?? '');
        $sec_key = $conn->real_escape_string($_POST['paystack_secret_key'] ?? '');
        
        foreach (['paystack_public_key' => $pub_key, 'paystack_secret_key' => $sec_key] as $key => $val) {
            $check = $conn->query("SELECT 1 FROM system_settings WHERE setting_key = '$key' AND estate_id = $estate_id");
            if ($check && $check->num_rows > 0) {
                $conn->query("UPDATE system_settings SET setting_value = '$val' WHERE setting_key = '$key' AND estate_id = $estate_id");
            } else {
                $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES ($estate_id, '$key', '$val')");
            }
        }
        $message = "Paystack API keys updated successfully!";
        
    } elseif (isset($_POST['update_smtp'])) {
        $estate_id = get_estate_id();
        $smtp_fields = [
            'smtp_enabled' => isset($_POST['smtp_enabled']) ? '1' : '0',
            'smtp_host' => trim($_POST['smtp_host'] ?? ''),
            'smtp_port' => trim($_POST['smtp_port'] ?? '587'),
            'smtp_encryption' => trim($_POST['smtp_encryption'] ?? 'tls'),
            'smtp_user' => trim($_POST['smtp_user'] ?? ''),
            'smtp_from_name' => trim($_POST['smtp_from_name'] ?? ''),
            'smtp_from_email' => trim($_POST['smtp_from_email'] ?? ''),
            'smtp_reply_to' => trim($_POST['smtp_reply_to'] ?? ''),
            'notify_on_invoice' => isset($_POST['notify_on_invoice']) ? '1' : '0',
            'notify_on_receipt' => isset($_POST['notify_on_receipt']) ? '1' : '0',
            'notify_on_visitor_pass' => isset($_POST['notify_on_visitor_pass']) ? '1' : '0',
            'notify_on_visitor_arrival' => isset($_POST['notify_on_visitor_arrival']) ? '1' : '0',
            'notify_on_welcome' => isset($_POST['notify_on_welcome']) ? '1' : '0'
        ];

        if (!empty($_POST['smtp_pass'])) {
            $smtp_fields['smtp_pass'] = $_POST['smtp_pass'];
        }

        foreach ($smtp_fields as $key => $val) {
            $val = $conn->real_escape_string($val);
            $check = $conn->query("SELECT 1 FROM system_settings WHERE setting_key = '$key' AND estate_id = $estate_id");
            if ($check && $check->num_rows > 0) {
                $conn->query("UPDATE system_settings SET setting_value = '$val' WHERE setting_key = '$key' AND estate_id = $estate_id");
            } else {
                $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES ($estate_id, '$key', '$val')");
            }
        }
        $active_tab = 'smtp_config';
        $message = "Email & SMTP configuration updated successfully!";
        
    } elseif (isset($_POST['send_test_email'])) {
        $active_tab = 'smtp_config';
        $estate_id = get_estate_id();
        $recipient = trim($_POST['test_email'] ?? '');
        if (empty($recipient) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $error = "Please provide a valid test recipient email address.";
        } else {
            if (EstateMailer::testSmtpConnection($conn, $estate_id, $recipient)) {
                $message = "Test email sent successfully to <strong>" . htmlspecialchars($recipient) . "</strong>! Please check the inbox.";
            } else {
                $error = "Failed to deliver test email. Please check your SMTP host, port, username, password, or check the Email Logs for error details.";
            }
        }
        
    } elseif (isset($_POST['update_id_settings'])) {
        $estate_id = get_estate_id();
        $template = $conn->real_escape_string($_POST['id_card_template']);
        $orientation = $conn->real_escape_string($_POST['id_card_orientation']);
        
        $conn->query("UPDATE system_settings SET setting_value = '$template' WHERE setting_key = 'id_card_template' AND estate_id = $estate_id");
        $conn->query("UPDATE system_settings SET setting_value = '$orientation' WHERE setting_key = 'id_card_orientation' AND estate_id = $estate_id");
        
        $validity = intval($_POST['id_card_validity_days']);
        // Check if key exists, if not insert (just in case)
        $check = $conn->query("SELECT * FROM system_settings WHERE setting_key = 'id_card_validity_days' AND estate_id = $estate_id");
        if ($check->num_rows > 0) {
            $conn->query("UPDATE system_settings SET setting_value = '$validity' WHERE setting_key = 'id_card_validity_days' AND estate_id = $estate_id");
        } else {
            $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES ($estate_id, 'id_card_validity_days', '$validity')");
        }
        
        $message = "ID Card configuration updated!";
        
    } elseif (isset($_POST['update_visitor_settings'])) {
        $estate_id = get_estate_id();
        $req_confirm = isset($_POST['require_resident_visitor_confirmation']) ? '1' : '0';
        
        $check = $conn->query("SELECT 1 FROM system_settings WHERE setting_key = 'require_resident_visitor_confirmation' AND estate_id = $estate_id");
        if ($check && $check->num_rows > 0) {
            $conn->query("UPDATE system_settings SET setting_value = '$req_confirm' WHERE setting_key = 'require_resident_visitor_confirmation' AND estate_id = $estate_id");
        } else {
            $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES ($estate_id, 'require_resident_visitor_confirmation', '$req_confirm')");
        }
        
        logAudit($conn, "Visitor Policy Updated", "Settings", "Resident visitor confirmation requirement set to " . ($req_confirm == '1' ? 'Enabled' : 'Disabled'));
        $active_tab = 'visitor_settings';
        $message = "Gate & Visitor Pass configuration updated successfully!";
        
    } elseif (isset($_POST['add_type'])) {
        $table = $_POST['table'];
        $name = $conn->real_escape_string($_POST['name']);
        
        $allowed_tables = [
            'building_types', 'flat_types', 'resident_relationships', 'domestic_staff_roles', 
            'domestic_staff_status', 'building_floor_types', 'building_statuses', 
            'flat_statuses', 'resident_statuses', 'property_ownership_types', 'property_acquisition_types',
            'incident_types', 'incident_severities', 'incident_statuses', 'incident_entity_types'
        ];
        if (in_array($table, $allowed_tables)) {
            $chk_slug_col = $conn->query("SHOW COLUMNS FROM $table LIKE 'slug'");
            if ($chk_slug_col && $chk_slug_col->num_rows > 0) {
                $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $name)));
                $slug = trim($slug, '_');
                $sql = "INSERT INTO $table (estate_id, name, slug) VALUES ($estate_id, '$name', '$slug')";
            } else {
                $sql = "INSERT INTO $table (name) VALUES ('$name')";
            }
            if ($conn->query($sql)) $message = "Item added successfully!";
            else $message = "Error: " . $conn->error;
        }
    } elseif (isset($_POST['delete_type'])) {
        $table = $_POST['table'];
        $id = intval($_POST['id']);
        
        $allowed_tables = [
            'building_types', 'flat_types', 'resident_relationships', 'domestic_staff_roles', 
            'domestic_staff_status', 'building_floor_types', 'building_statuses', 
            'flat_statuses', 'resident_statuses', 'property_ownership_types', 'property_acquisition_types',
            'incident_types', 'incident_severities', 'incident_statuses', 'incident_entity_types'
        ];
        if (in_array($table, $allowed_tables)) {
            $sql = "DELETE FROM $table WHERE id = $id";
            if ($conn->query($sql)) $message = "Item deleted successfully!";
            else $message = "Error: " . $conn->error;
        }
    } elseif (isset($_POST['edit_type'])) {
        $table = $_POST['table'];
        $id = intval($_POST['id']);
        $name = $conn->real_escape_string($_POST['name']);
        
        $allowed_tables = [
            'building_types', 'flat_types', 'resident_relationships', 'domestic_staff_roles', 
            'domestic_staff_status', 'building_floor_types', 'building_statuses', 
            'flat_statuses', 'resident_statuses', 'property_ownership_types', 'property_acquisition_types',
            'incident_types', 'incident_severities', 'incident_statuses', 'incident_entity_types'
        ];
        if (in_array($table, $allowed_tables)) {
            $chk_slug_col = $conn->query("SHOW COLUMNS FROM $table LIKE 'slug'");
            if ($chk_slug_col && $chk_slug_col->num_rows > 0) {
                $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $name)));
                $slug = trim($slug, '_');
                $sql = "UPDATE $table SET name = '$name', slug = '$slug' WHERE id = $id";
            } else {
                $sql = "UPDATE $table SET name = '$name' WHERE id = $id";
            }
            if ($conn->query($sql)) $message = "Item updated successfully!";
            else $message = "Error: " . $conn->error;
        }
    } elseif (isset($_POST['add_commercial_field'])) {
        $estate_id = get_estate_id();
        $label = $conn->real_escape_string($_POST['field_label']);
        $type = $conn->real_escape_string($_POST['field_type']);
        $options = $conn->real_escape_string($_POST['field_options'] ?? '');
        $required = isset($_POST['is_required']) ? 1 : 0;
        
        $sql = "INSERT INTO commercial_field_definitions (estate_id, field_label, field_type, field_options, is_required) 
                VALUES ($estate_id, '$label', '$type', '$options', $required)";
        if ($conn->query($sql)) $message = "Commercial field added!";
        else $message = "Error: " . $conn->error;
        
    } elseif (isset($_POST['delete_commercial_field'])) {
        $id = intval($_POST['id']);
        $estate_id = get_estate_id();
        if ($conn->query("DELETE FROM commercial_field_definitions WHERE id = $id AND estate_id = $estate_id")) {
            $message = "Commercial field removed!";
        } else {
            $message = "Error: " . $conn->error;
        }
    }
}

// Fetch Data
$estate_id = get_estate_id();
$settings_res = $conn->query("SELECT * FROM system_settings WHERE estate_id = $estate_id");
$sys = [];
while ($row = $settings_res->fetch_assoc()) {
    $sys[$row['setting_key']] = $row['setting_value'];
}
$est_chk = $conn->query("SELECT name FROM estates WHERE id = $estate_id LIMIT 1");
if ($est_chk && $est_row = $est_chk->fetch_assoc()) {
    if (empty($sys['estate_name'])) {
        $sys['estate_name'] = $est_row['name'];
    }
}
$estate_logo_display = !empty($sys['estate_logo']) ? get_media_url($sys['estate_logo']) : '';
$app_company_logo_display = !empty($sys['app_company_logo']) ? get_media_url($sys['app_company_logo']) : '';
$estate_hero_display = !empty($sys['estate_hero_image']) ? get_media_url($sys['estate_hero_image']) : '';
$building_types = $conn->query("SELECT * FROM building_types ORDER BY name");
$flat_types = $conn->query("SELECT * FROM flat_types ORDER BY name");
$resident_relationships = $conn->query("SELECT * FROM resident_relationships ORDER BY name");
$ds_roles = $conn->query("SELECT * FROM domestic_staff_roles ORDER BY name");
$ds_status = $conn->query("SELECT * FROM domestic_staff_status ORDER BY name");
$building_floor_types = $conn->query("SELECT * FROM building_floor_types ORDER BY name ASC");
$building_statuses = $conn->query("SELECT * FROM building_statuses ORDER BY name ASC");
$flat_statuses = $conn->query("SELECT * FROM flat_statuses ORDER BY name ASC");
$resident_statuses = $conn->query("SELECT * FROM resident_statuses ORDER BY name ASC");
$ownership_types = $conn->query("SELECT * FROM property_ownership_types ORDER BY name ASC");
$acquisition_types = $conn->query("SELECT * FROM property_acquisition_types ORDER BY name ASC");
$incident_types = $conn->query("SELECT * FROM incident_types ORDER BY name ASC");
$incident_severities = $conn->query("SELECT * FROM incident_severities ORDER BY id ASC");
$incident_statuses = $conn->query("SELECT * FROM incident_statuses ORDER BY id ASC");
$incident_entity_types = $conn->query("SELECT * FROM incident_entity_types ORDER BY id ASC");
$commercial_fields = $conn->query("SELECT * FROM commercial_field_definitions WHERE estate_id = $estate_id ORDER BY id ASC");

if (!function_exists('renderConfigTable')) {
    function renderConfigTable($title, $table_name, $result, $icon = 'fa-list') {
        $html = '
        <div id="'.$table_name.'" class="tab-content" style="display: none;">
            <div class="glass" style="padding: 2rem; border-radius: 0.5rem;">
                <div style="margin-bottom: 2rem; padding: 1.5rem; background: #f8fafc; border-radius: 0.5rem;">
                    <form method="POST" style="display: flex; gap: 1rem; align-items: flex-end;">
                        <input type="hidden" name="table" value="'.$table_name.'">
                        <div style="flex-grow: 1;">
                            <label>Add New '.htmlspecialchars($title).'</label>
                            <input type="text" name="name" required style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; margin-top: 0.25rem;">
                        </div>
                        <button type="submit" name="add_type" class="btn btn-primary">Add</button>
                    </form>
                </div>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead><tr style="text-align: left; color: #64748b;"><th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Name</th><th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Action</th></tr></thead>
                    <tbody>';
        
        if ($result) {
            $result->data_seek(0);
            while($row = $result->fetch_assoc()) {
                $html .= '
                    <tr>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;">'.htmlspecialchars($row['name']).'</td>
                        <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;">
                            <button onclick="editConfig(\''.$table_name.'\', '.$row['id'].', \''.addslashes($row['name']).'\')" style="background: none; border: none; color: var(--primary-color); cursor: pointer; margin-right: 0.5rem;"><i class="fa-solid fa-pen-to-square"></i></button>
                            <form method="POST" onsubmit="return confirm(\'Are you sure?\');" style="display:inline;">
                                <input type="hidden" name="table" value="'.$table_name.'">
                                <input type="hidden" name="id" value="'.$row['id'].'">
                                <button type="submit" name="delete_type" style="background: none; border: none; color: #ef4444; cursor: pointer;"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>';
            }
        }
        
        $html .= '</tbody></table></div></div>';
        return $html;
    }
}
?>

<style>
.settings-container { display: grid; grid-template-columns: 240px 1fr; gap: 2rem; align-items: start; }
.settings-sidebar { background: white; padding: 1.5rem; border-radius: 0.5rem; border: 1px solid #e2e8f0; position: sticky; top: 2rem; }
.settings-group-title { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; margin-bottom: 0.5rem; font-weight: 700; margin-top: 1.5rem; }
.settings-group-title:first-child { margin-top: 0; }
.settings-nav-btn { display: block; width: 100%; text-align: left; padding: 0.75rem 1rem; border-radius: 0.375rem; margin-bottom: 0.25rem; background: transparent; border: none; cursor: pointer; color: #475569; font-weight: 500; transition: all 0.2s; }
.settings-nav-btn:hover { background: #f1f5f9; color: #0f172a; }
.settings-nav-btn.active { background: var(--primary-light); color: var(--primary-color); font-weight: 600; }
.settings-nav-btn i { width: 20px; text-align: center; margin-right: 0.5rem; }
</style>

<div class="page-header">
    <h1>System Settings</h1>
</div>

<?php if ($message): ?>
    <div class="alert" style="background: #dcfce7; color: #166534; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert" style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
        <?php echo $error; ?>
    </div>
<?php endif; ?>

<div class="settings-container">
    <!-- Sidebar -->
    <div class="settings-sidebar">
        <div class="settings-group-title">General</div>
        <button class="settings-nav-btn active" onclick="openTab(event, 'general')"><i class="fa-solid fa-sliders"></i> General Info</button>
        
        <div class="settings-group-title">ID Cards</div>
        <button class="settings-nav-btn" onclick="openTab(event, 'id_cards')"><i class="fa-solid fa-id-card"></i> Configuration</button>
        
        <div class="settings-group-title">Properties</div>
        <button class="settings-nav-btn" onclick="openTab(event, 'building_types')"><i class="fa-solid fa-building"></i> Building Types</button>
        <button class="settings-nav-btn" onclick="openTab(event, 'building_statuses')"><i class="fa-solid fa-circle-info"></i> Building Statuses</button>
        <button class="settings-nav-btn" onclick="openTab(event, 'building_floor_types')"><i class="fa-solid fa-layer-group"></i> Floor Configs</button>
        <button class="settings-nav-btn" onclick="openTab(event, 'flat_types')"><i class="fa-solid fa-door-open"></i> Flat Types</button>
        <button class="settings-nav-btn" onclick="openTab(event, 'flat_statuses')"><i class="fa-solid fa-tag"></i> Flat Statuses</button>
        <button class="settings-nav-btn" onclick="openTab(event, 'commercial_config')"><i class="fa-solid fa-gear"></i> Commercial Config</button>
        
        <div class="settings-group-title">Residents</div>
        <button class="settings-nav-btn" onclick="openTab(event, 'resident_relationships')"><i class="fa-solid fa-people-arrows"></i> Relationships</button>
        <button class="settings-nav-btn" onclick="openTab(event, 'resident_statuses')"><i class="fa-solid fa-user-check"></i> Resident Statuses</button>
        <button class="settings-nav-btn" onclick="openTab(event, 'domestic_staff_roles')"><i class="fa-solid fa-user-tag"></i> Domestic Roles</button>
        <button class="settings-nav-btn" onclick="openTab(event, 'domestic_staff_status')"><i class="fa-solid fa-clock"></i> Domestic Status</button>

        <div class="settings-group-title">Finance</div>
        <button class="settings-nav-btn" onclick="openTab(event, 'paystack_api')"><i class="fa-solid fa-credit-card"></i> Paystack API</button>

        <div class="settings-group-title">Communications</div>
        <button class="settings-nav-btn" onclick="openTab(event, 'smtp_config')"><i class="fa-solid fa-envelope"></i> Email &amp; SMTP</button>
        <a href="email_logs.php" class="settings-nav-btn" style="text-decoration:none;"><i class="fa-solid fa-paper-plane"></i> Email Logs</a>

        <div class="settings-group-title">Ownership & Transfers</div>
        <button class="settings-nav-btn" onclick="openTab(event, 'property_ownership_types')"><i class="fa-solid fa-key"></i> Ownership Types</button>
        <button class="settings-nav-btn" onclick="openTab(event, 'property_acquisition_types')"><i class="fa-solid fa-file-contract"></i> Acquisition Types</button>

        <div class="settings-group-title">Gate &amp; Security</div>
        <button class="settings-nav-btn" onclick="openTab(event, 'visitor_settings')"><i class="fa-solid fa-shield-halved"></i> Gate &amp; Visitor Passes</button>
        <button class="settings-nav-btn" onclick="openTab(event, 'incident_types')"><i class="fa-solid fa-triangle-exclamation"></i> Incident Types</button>
        <button class="settings-nav-btn" onclick="openTab(event, 'incident_severities')"><i class="fa-solid fa-gauge-high"></i> Severities</button>
        <button class="settings-nav-btn" onclick="openTab(event, 'incident_statuses')"><i class="fa-solid fa-list-check"></i> Incident Statuses</button>
        <button class="settings-nav-btn" onclick="openTab(event, 'incident_entity_types')"><i class="fa-solid fa-user-tag"></i> Entity Types</button>
    </div>

    <!-- Content Area -->
    <div style="min-width: 0;">
        <!-- General Settings Tab -->
        <div id="general" class="tab-content">
            <div class="glass" style="padding: 2rem; border-radius: 0.75rem;">
                <form method="POST" enctype="multipart/form-data">
                    
                    <!-- Multi-Tenant Callout Banner -->
                    <div style="background: linear-gradient(135deg, rgba(59, 130, 246, 0.08) 0%, rgba(147, 51, 234, 0.06) 100%); border: 1px solid rgba(59, 130, 246, 0.2); border-radius: 0.75rem; padding: 1.25rem 1.5rem; margin-bottom: 2rem; display: flex; align-items: flex-start; gap: 1rem;">
                        <div style="width: 40px; height: 40px; border-radius: 50%; background: #3b82f6; color: white; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 1.15rem; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3);">
                            <i class="fa-solid fa-tree-city"></i>
                        </div>
                        <div>
                            <div style="font-weight: 700; color: #1e293b; font-size: 0.98rem; margin-bottom: 0.25rem;">Multi-Estate Identity &amp; Top-Right Branding</div>
                            <div style="font-size: 0.85rem; color: #64748b; line-height: 1.5;">
                                Because multiple residential estates use this platform, customize this estate's identity below. The <strong>Estate Name</strong> and <strong>Estate Logo</strong> configured here appear dynamically on the <strong>Top-Right Corner</strong> of all navigation bars, invoices, passes, and receipts. The software producer company information (e.g. platform developer) remains distinct.
                            </div>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; align-items: start;">
                        <!-- Left Column: Estate Branding & Identity -->
                        <div>
                            <!-- Estate Identity Card -->
                            <div style="background: white; border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem; padding-bottom: 0.75rem; border-bottom: 1px solid #f1f5f9;">
                                    <h4 style="margin: 0; font-size: 1.05rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 0.5rem;">
                                        <i class="fa-solid fa-building-circle-check text-primary"></i> Estate Identity &amp; Top-Right Logo
                                    </h4>
                                    <span class="badge bg-primary-subtle text-primary fw-semibold" style="font-size: 0.72rem; padding: 0.35rem 0.65rem; border-radius: 999px;">
                                        Estate #<?php echo $estate_id; ?>
                                    </span>
                                </div>

                                <div style="margin-bottom: 1.25rem;">
                                    <label style="font-weight: 600; color: #334155; font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">
                                        Estate Name <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" name="estate_name" value="<?php echo htmlspecialchars($sys['estate_name'] ?? ''); ?>" required placeholder="e.g. Palm Gardens Estate, Lekki Sunrise Estate" style="width: 100%; padding: 0.65rem 0.85rem; border: 1.5px solid #cbd5e1; border-radius: 0.5rem; font-size: 0.95rem;">
                                    <small class="text-secondary" style="font-size: 0.78rem;">Displayed prominently on the top-right corner of all portal headers.</small>
                                </div>

                                <!-- Estate Logo Upload Component -->
                                <div style="margin-bottom: 1.25rem;">
                                    <label style="font-weight: 600; color: #334155; font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">
                                        Estate Logo
                                    </label>
                                    
                                    <div style="display: grid; grid-template-columns: auto 1fr; gap: 1.25rem; align-items: center; padding: 1rem; border: 1.5px dashed #cbd5e1; border-radius: 0.65rem; background: #f8fafc;">
                                        <!-- Current or Fallback Logo Preview -->
                                        <div style="text-align: center;">
                                            <div id="estate_logo_container" style="width: 80px; height: 80px; border-radius: 12px; background: white; border: 2px solid #e2e8f0; display: flex; align-items: center; justify-content: center; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.06); position: relative;">
                                                <?php if(!empty($estate_logo_display)): ?>
                                                    <img id="estate_logo_preview" src="<?php echo htmlspecialchars($estate_logo_display); ?>" alt="Estate Logo" style="width: 100%; height: 100%; object-fit: contain;">
                                                <?php else: ?>
                                                    <img id="estate_logo_preview" src="" alt="Estate Logo Preview" style="width: 100%; height: 100%; object-fit: contain; display: none;">
                                                    <div id="estate_logo_fallback" style="font-size: 2rem; color: #94a3b8;"><i class="fa-solid fa-tree-city"></i></div>
                                                <?php endif; ?>
                                            </div>
                                            <span class="badge bg-slate text-secondary mt-1" style="font-size: 0.68rem; display: block;">Active Logo</span>
                                        </div>

                                        <!-- Upload Controls -->
                                        <div>
                                            <div style="display: flex; gap: 0.75rem; align-items: center; margin-bottom: 0.5rem; flex-wrap: wrap;">
                                                <input type="file" id="estate_logo_input" name="estate_logo" accept="image/png, image/jpeg, image/webp, image/svg+xml" onchange="previewEstateLogo(this)" style="display: none;">
                                                <button type="button" onclick="document.getElementById('estate_logo_input').click()" class="btn btn-sm btn-outline-primary" style="padding: 0.45rem 1rem; font-weight: 600; border-radius: 0.5rem;">
                                                    <i class="fa-solid fa-cloud-arrow-up me-1"></i> Upload Estate Logo
                                                </button>
                                                <span id="estate_logo_filename" class="text-secondary small" style="font-style: italic;">No file selected</span>
                                            </div>
                                            <small class="text-secondary d-block" style="font-size: 0.75rem; line-height: 1.4;">
                                                Accepted: PNG, JPG, WEBP, SVG. Transparent background recommended.
                                            </small>
                                            <?php if(!empty($estate_logo_display)): ?>
                                                <label style="display: inline-flex; align-items: center; gap: 0.4rem; margin-top: 0.5rem; font-size: 0.78rem; color: #dc2626; cursor: pointer;">
                                                    <input type="checkbox" name="remove_estate_logo" value="1">
                                                    <span><i class="fa-solid fa-trash-can me-1"></i> Remove logo &amp; use default badge icon</span>
                                                </label>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Live Top-Right Header Badge Simulation Preview -->
                                    <div style="margin-top: 0.75rem; padding: 0.75rem 1rem; background: #f1f5f9; border-radius: 0.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
                                        <span class="text-secondary small fw-semibold" style="font-size: 0.75rem;">
                                            <i class="fa-solid fa-eye me-1"></i> Live Header Preview (Extreme Right Corner):
                                        </span>
                                        <div id="header_preview_badge" class="estate-header-brand" style="pointer-events: none;">
                                            <img id="header_preview_img" src="<?php echo htmlspecialchars($estate_logo_display); ?>" alt="Preview" class="estate-header-logo" style="<?php echo empty($estate_logo_display) ? 'display: none;' : ''; ?>">
                                            <div id="header_preview_fallback" class="estate-header-fallback" style="<?php echo !empty($estate_logo_display) ? 'display: none;' : ''; ?>">
                                                <i class="fa-solid fa-tree-city"></i>
                                            </div>
                                            <div class="estate-header-info">
                                                <span class="estate-header-tag">Estate</span>
                                                <span id="header_preview_name" class="estate-header-name"><?php echo htmlspecialchars($sys['estate_name'] ?? 'Estate Name'); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Location & Motto -->
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.25rem;">
                                    <div>
                                        <label style="font-weight: 600; color: #334155; font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">Location</label>
                                        <input type="text" name="estate_location" value="<?php echo htmlspecialchars($sys['estate_location'] ?? ''); ?>" placeholder="e.g. Lagos, Nigeria" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e1; border-radius: 0.375rem;">
                                    </div>
                                    <div>
                                        <label style="font-weight: 600; color: #334155; font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">Motto / Slogan</label>
                                        <input type="text" name="estate_motto" value="<?php echo htmlspecialchars($sys['estate_motto'] ?? ''); ?>" placeholder="e.g. Excellence in Living" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e1; border-radius: 0.375rem;">
                                    </div>
                                </div>

                                <!-- Hero Image -->
                                <div>
                                    <label style="font-weight: 600; color: #334155; font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">Estate Hero / Banner Image</label>
                                    <?php if(!empty($estate_hero_display)): ?>
                                        <div style="margin: 0.35rem 0;"><img src="<?php echo htmlspecialchars($estate_hero_display); ?>" style="height: 60px; border-radius: 6px; border: 1px solid #e2e8f0; object-fit: cover;"></div>
                                    <?php endif; ?>
                                    <input type="file" name="estate_hero_image" accept="image/*" onchange="previewImage(this, 'hero_preview')" style="width: 100%; padding: 0.45rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; background: #fff; font-size: 0.85rem;">
                                    <img id="hero_preview" src="" style="display: none; width: 100%; height: auto; max-height: 160px; object-fit: cover; margin-top: 0.5rem; border-radius: 6px; border: 1px solid #e2e8f0;">
                                </div>
                            </div>

                            <!-- Software Producer Company Card -->
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 1.5rem;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; padding-bottom: 0.5rem; border-bottom: 1px solid #e2e8f0;">
                                    <h4 style="margin: 0; font-size: 0.95rem; font-weight: 700; color: #334155; display: flex; align-items: center; gap: 0.5rem;">
                                        <i class="fa-solid fa-laptop-code text-secondary"></i> Software Producer Company
                                    </h4>
                                    <span class="badge bg-slate text-secondary fw-normal" style="font-size: 0.7rem;">Platform Developer</span>
                                </div>
                                <p style="font-size: 0.8rem; color: #64748b; margin-bottom: 1rem; line-height: 1.4;">
                                    The company producing the software application (e.g. <strong>NoLimitBuzz</strong>). Displays in the sidebar platform header and system credits.
                                </p>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; align-items: start;">
                                    <div>
                                        <label style="font-weight: 500; color: #475569; font-size: 0.85rem; display: block; margin-bottom: 0.35rem;">Company / Software Name</label>
                                        <input type="text" name="app_company_name" value="<?php echo htmlspecialchars($sys['app_company_name'] ?? 'NoLimitBuzz'); ?>" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; font-size: 0.9rem;">
                                    </div>
                                    <div>
                                        <label style="font-weight: 500; color: #475569; font-size: 0.85rem; display: block; margin-bottom: 0.35rem;">Company Logo</label>
                                        <?php if(!empty($app_company_logo_display)): ?>
                                            <div style="margin-bottom: 0.35rem;"><img src="<?php echo htmlspecialchars($app_company_logo_display); ?>" style="height: 36px; border-radius: 4px; border: 1px solid #e2e8f0; object-fit: contain; background: #fff; padding: 2px;"></div>
                                        <?php endif; ?>
                                        <input type="file" name="app_company_logo" accept="image/*" style="width: 100%; padding: 0.45rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; background: #fff; font-size: 0.85rem;">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column: Governance, Contact & System Theme -->
                        <div>
                            <div style="background: white; border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.02);">
                                <h4 style="margin: 0 0 1.25rem 0; font-size: 1.05rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.75rem; border-bottom: 1px solid #f1f5f9;">
                                    <i class="fa-solid fa-scale-balanced text-primary"></i> Governance &amp; Contact Info
                                </h4>
                                
                                <div style="margin-bottom: 1rem;">
                                    <label style="font-weight: 600; color: #334155; font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">Estate Rules</label>
                                    <textarea name="estate_rules" rows="4" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; font-size: 0.88rem;"><?php echo htmlspecialchars($sys['estate_rules'] ?? ''); ?></textarea>
                                </div>
                                <div style="margin-bottom: 1rem;">
                                    <label style="font-weight: 600; color: #334155; font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">Code of Conduct</label>
                                    <textarea name="estate_code_of_conduct" rows="3" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; font-size: 0.88rem;"><?php echo htmlspecialchars($sys['estate_code_of_conduct'] ?? ''); ?></textarea>
                                </div>
                                
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                                    <div>
                                        <label style="font-weight: 600; color: #334155; font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">Office Phone</label>
                                        <input type="text" name="office_phone" value="<?php echo htmlspecialchars($sys['office_phone'] ?? ''); ?>" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e1; border-radius: 0.375rem;">
                                    </div>
                                    <div>
                                        <label style="font-weight: 600; color: #334155; font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">Office Email</label>
                                        <input type="email" name="office_email" value="<?php echo htmlspecialchars($sys['office_email'] ?? ''); ?>" style="width: 100%; padding: 0.55rem; border: 1px solid #cbd5e1; border-radius: 0.375rem;">
                                    </div>
                                </div>
                                
                                <div style="margin-bottom: 1rem;">
                                    <label style="font-weight: 600; color: #334155; font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">Currency Symbol</label>
                                    <input type="text" name="currency_symbol" value="<?php echo htmlspecialchars($sys['currency_symbol'] ?? '₦'); ?>" style="width: 80px; padding: 0.55rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; font-size: 1.1rem; text-align: center;">
                                </div>
                                
                                <div>
                                    <label style="font-weight: 600; color: #334155; font-size: 0.88rem; display: block; margin-bottom: 0.35rem;">System Accent Theme Color</label>
                                    <div style="display: flex; gap: 0.65rem; flex-wrap: wrap; margin-top: 0.35rem; align-items: center;">
                                        <?php 
                                        $current_color = $sys['theme_color'] ?? '#3b82f6';
                                        $presets = ['#3b82f6', '#10b981', '#a855f7', '#f43f5e', '#f59e0b', '#14b8a6', '#0f172a'];
                                        foreach($presets as $color): 
                                        ?>
                                            <div onclick="document.querySelector('input[name=theme_color]').value='<?php echo $color; ?>'" style="width: 32px; height: 32px; background: <?php echo $color; ?>; border-radius: 50%; cursor: pointer; border: 2.5px solid <?php echo $current_color == $color ? '#0f172a' : 'transparent'; ?>; box-shadow: 0 1px 3px rgba(0,0,0,0.15); transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'"></div>
                                        <?php endforeach; ?>
                                        <input type="color" name="theme_color" value="<?php echo htmlspecialchars($current_color); ?>" style="height: 34px; width: 64px; padding: 0; border: none; background: none; cursor: pointer;">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div style="margin-top: 1.5rem; text-align: right; padding-top: 1rem; border-top: 1px solid #e2e8f0;">
                        <button type="submit" name="update_general" class="btn btn-primary" style="padding: 0.75rem 2.5rem; font-size: 1rem; font-weight: 600; border-radius: 0.5rem; box-shadow: 0 4px 14px rgba(59, 130, 246, 0.4);">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Save Changes &amp; Update Branding
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ID Card Settings Tab -->
        <div id="id_cards" class="tab-content" style="display: none;">
             <div class="glass" style="padding: 2rem; border-radius: 0.5rem;">
                <h3 style="margin-bottom: 1.5rem; color: var(--primary-color);">ID Card Configuration</h3>
                <form method="POST">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                        <div>
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Select Template</label>
                            <div style="display: grid; gap: 1rem;">
                                <!-- Modern Option -->
                                <label style="display: flex; align-items: flex-start; padding: 1rem; border: 1px solid #e2e8f0; border-radius: 0.5rem; cursor: pointer; transition: all 0.2s; background: white;">
                                    <div style="padding-top: 0.25rem;"><input type="radio" name="id_card_template" value="modern" <?php echo ($sys['id_card_template'] ?? '') == 'modern' ? 'checked' : ''; ?> style="margin-right: 1rem;"></div>
                                    <div style="flex-grow: 1;">
                                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                            <div style="font-weight: 600; color: #0f172a;">Modern</div>
                                            <!-- Illustration -->
                                            <div style="width: 60px; height: 40px; border-radius: 4px; background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%); position: relative; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                                <div style="width: 15px; height: 15px; background: rgba(255,255,255,0.8); border-radius: 50%; position: absolute; top: 12px; left: 5px;"></div>
                                                <div style="width: 30px; height: 4px; background: rgba(255,255,255,0.6); border-radius: 2px; position: absolute; top: 12px; left: 25px;"></div>
                                                <div style="width: 20px; height: 4px; background: rgba(255,255,255,0.4); border-radius: 2px; position: absolute; top: 20px; left: 25px;"></div>
                                            </div>
                                        </div>
                                        <div style="font-size: 0.85rem; color: #64748b;">Colorful gradient, glassmorphism effects.</div>
                                    </div>
                                </label>

                                <!-- Corporate Option -->
                                <label style="display: flex; align-items: flex-start; padding: 1rem; border: 1px solid #e2e8f0; border-radius: 0.5rem; cursor: pointer; transition: all 0.2s; background: white;">
                                    <div style="padding-top: 0.25rem;"><input type="radio" name="id_card_template" value="corporate" <?php echo ($sys['id_card_template'] ?? '') == 'corporate' ? 'checked' : ''; ?> style="margin-right: 1rem;"></div>
                                    <div style="flex-grow: 1;">
                                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                            <div style="font-weight: 600; color: #0f172a;">Corporate</div>
                                            <!-- Illustration -->
                                            <div style="width: 60px; height: 40px; border-radius: 4px; background: #fff; border: 1px solid #e2e8f0; position: relative; overflow: hidden; border-top: 6px solid #0f172a;">
                                                 <div style="width: 15px; height: 15px; background: #e2e8f0; border-radius: 2px; position: absolute; top: 10px; left: 5px;"></div>
                                                 <div style="width: 30px; height: 4px; background: #0f172a; border-radius: 2px; position: absolute; top: 10px; left: 25px;"></div>
                                                 <div style="width: 25px; height: 3px; background: #94a3b8; border-radius: 2px; position: absolute; top: 18px; left: 25px;"></div>
                                            </div>
                                        </div>
                                        <div style="font-size: 0.85rem; color: #64748b;">Clean, formal white and blue design.</div>
                                    </div>
                                </label>

                                <!-- Minimalist Option -->
                                <label style="display: flex; align-items: flex-start; padding: 1rem; border: 1px solid #e2e8f0; border-radius: 0.5rem; cursor: pointer; transition: all 0.2s; background: white;">
                                    <div style="padding-top: 0.25rem;"><input type="radio" name="id_card_template" value="minimalist" <?php echo ($sys['id_card_template'] ?? '') == 'minimalist' ? 'checked' : ''; ?> style="margin-right: 1rem;"></div>
                                    <div style="flex-grow: 1;">
                                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                            <div style="font-weight: 600; color: #0f172a;">Minimalist</div>
                                            <!-- Illustration -->
                                            <div style="width: 60px; height: 40px; border-radius: 4px; background: #fff; border: 3px solid #000; position: relative; display: flex; align-items: center; justify-content: center;">
                                                <div style="font-weight: 900; font-size: 8px; letter-spacing: -1px;">ID</div>
                                            </div>
                                        </div>
                                        <div style="font-size: 0.85rem; color: #64748b;">High contrast, simple black and white.</div>
                                    </div>
                                </label>
                            </div>
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Orientation</label>
                            <div style="display: flex; gap: 1rem;">
                                <label style="flex: 1; text-align: center; padding: 1rem; border: 1px solid #e2e8f0; border-radius: 0.5rem; cursor: pointer;">
                                    <input type="radio" name="id_card_orientation" value="portrait" <?php echo ($sys['id_card_orientation'] ?? '') == 'portrait' ? 'checked' : ''; ?> style="margin-bottom: 0.5rem;">
                                    <div style="font-weight: 600; color: #0f172a;"><i class="fa-regular fa-file" style="font-size: 1.5rem; display: block; margin-bottom: 0.5rem;"></i> Portrait</div>
                                </label>
                                <label style="flex: 1; text-align: center; padding: 1rem; border: 1px solid #e2e8f0; border-radius: 0.5rem; cursor: pointer;">
                                    <input type="radio" name="id_card_orientation" value="landscape" <?php echo ($sys['id_card_orientation'] ?? '') == 'landscape' ? 'checked' : ''; ?> style="margin-bottom: 0.5rem;">
                                    <div style="font-weight: 600; color: #0f172a;"><i class="fa-regular fa-file" style="font-size: 1.5rem; display: block; margin-bottom: 0.5rem; transform: rotate(90deg);"></i> Landscape</div>
                                </label>
                            </div>
                        </div>
                        <div style="margin-top: 1rem;">
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Validity Period (Days)</label>
                            <input type="number" name="id_card_validity_days" value="<?php echo htmlspecialchars($sys['id_card_validity_days'] ?? '365'); ?>" style="width: 100%; padding: 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.375rem;">
                            <p style="font-size: 0.85rem; color: #64748b; margin-top: 0.25rem;">Used to calculate the "Valid Until" date from today.</p>
                        </div>
                    </div>
                    <div style="margin-top: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                        <div style="display: flex; gap: 0.5rem;">
                            <a href="generate_id?id=<?php echo $_SESSION['user_id'] ?? 1; ?>&type=admin" target="_blank" class="btn btn-secondary" style="background: #e0f2fe; color: #0284c7; padding: 0.65rem 1.2rem; border-radius: 0.375rem; text-decoration: none; font-weight: 600; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-user-shield"></i> Preview My Admin ID Card
                            </a>
                        </div>
                        <button type="submit" name="update_id_settings" class="btn btn-primary" style="padding: 0.75rem 2rem;">Save Configurations</button>
                    </div>
                </form>
             </div>
        </div>

        <!-- Gate & Visitor Pass Policies Tab -->
        <div id="visitor_settings" class="tab-content" style="display: none;">
            <div class="glass" style="padding: 2rem; border-radius: 0.5rem;">
                <div class="d-flex justify-content-between align-items-start mb-4 pb-3 border-bottom flex-wrap gap-2">
                    <div>
                        <h3 style="margin: 0; color: var(--primary-color);">
                            <i class="fa-solid fa-shield-halved me-2"></i> Gate &amp; Visitor Pass Clearance Policy
                        </h3>
                        <p class="text-muted small mt-1 mb-0">
                            Configure whether visitors require resident confirmation codes or enjoy direct gate pass clearance.
                        </p>
                    </div>
                    <?php 
                    $is_confirm_required = ($sys['require_resident_visitor_confirmation'] ?? '1') == '1';
                    ?>
                    <div>
                        <?php if ($is_confirm_required): ?>
                            <span class="mature-badge mature-badge-emerald px-3 py-1.5 font-semibold">
                                <i class="fa-solid fa-lock me-1"></i> Strict Verification Mode
                            </span>
                        <?php else: ?>
                            <span class="mature-badge mature-badge-sky px-3 py-1.5 font-semibold">
                                <i class="fa-solid fa-unlock-keyhole me-1"></i> Direct Access Pass Mode
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <form method="POST">
                    <!-- Main Toggle Switch Card -->
                    <div class="p-4 rounded-3 border mb-4" style="background: #f8fafc; border-color: #e2e8f0;">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <div style="max-width: 650px;">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <h5 class="fw-bold text-slate-900 m-0">Overall Resident Confirmation Control</h5>
                                    <span class="badge rounded-pill <?= $is_confirm_required ? 'bg-success' : 'bg-secondary' ?>" style="font-size: 0.72rem;">
                                        <?= $is_confirm_required ? 'Confirmation Enabled' : 'Confirmation Disabled' ?>
                                    </span>
                                </div>
                                <p class="text-secondary small mb-0">
                                    Master toggle: When disabled, the entire function of residents confirming visitors is disabled and does not show on the resident portal. Gate security checks out visitors directly.
                                </p>
                            </div>
                            
                            <!-- Premium Toggle Switch -->
                            <div class="form-check form-switch m-0" style="font-size: 1.5rem;">
                                <input class="form-check-input" type="checkbox" role="switch" id="toggleConfirmationPolicy" name="require_resident_visitor_confirmation" value="1" <?= $is_confirm_required ? 'checked' : '' ?> style="cursor: pointer;">
                            </div>
                        </div>
                    </div>

                    <!-- Policy Comparison / Information Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="p-3 rounded-3 border h-100 <?= $is_confirm_required ? 'bg-white shadow-sm border-primary' : 'bg-light text-muted' ?>" style="border-width: <?= $is_confirm_required ? '2px' : '1px' ?>;">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <i class="fa-solid fa-circle-check <?= $is_confirm_required ? 'text-primary' : 'text-secondary' ?>"></i>
                                    <strong class="text-slate-900">When Enabled (Strict Verification)</strong>
                                </div>
                                <ul class="small text-secondary ps-3 mb-0" style="line-height: 1.6;">
                                    <li>Resident confirmation code dock and confirm buttons are <strong>visible and active</strong> on the resident portal.</li>
                                    <li>Residents must confirm incoming visitors by pass code in the resident portal or code dock.</li>
                                    <li>Gate checkout without resident confirmation is treated as an <strong>Unconfirmed Exit</strong> requiring security to record an exception reason (e.g. <em>"Resident not at home"</em>).</li>
                                    <li>Ideal for high-security residential communities requiring strict host verification.</li>
                                </ul>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="p-3 rounded-3 border h-100 <?= !$is_confirm_required ? 'bg-white shadow-sm border-info' : 'bg-light text-muted' ?>" style="border-width: <?= !$is_confirm_required ? '2px' : '1px' ?>;">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <i class="fa-solid fa-eye-slash <?= !$is_confirm_required ? 'text-info' : 'text-secondary' ?>"></i>
                                    <strong class="text-slate-900">When Disabled (Completely Hidden from Resident Portal)</strong>
                                </div>
                                <ul class="small text-secondary ps-3 mb-0" style="line-height: 1.6;">
                                    <li>The resident confirmation function is <strong>completely disabled and does not show on the resident portal</strong>.</li>
                                    <li>Residents will <strong>not</strong> see any confirmation code box, dock, or confirm buttons.</li>
                                    <li>Security guards can checkout visitors directly with zero friction—no exception reason prompt or unconfirmed alerts.</li>
                                    <li>Ideal for estates that do not require resident confirmation codes for visitor passes.</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Action Bar -->
                    <div class="d-flex justify-content-between align-items-center pt-3 border-top flex-wrap gap-2">
                        <div class="small text-muted">
                            <i class="fa-solid fa-circle-info me-1"></i> Changes take effect immediately across all gate checkpoints and resident portals.
                        </div>
                        <button type="submit" name="update_visitor_settings" class="btn btn-primary px-4 py-2 fw-semibold rounded-3 shadow-sm">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Save Gate Pass Policy
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php 
        echo renderConfigTable('Building Type', 'building_types', $building_types);
        echo renderConfigTable('Building Status', 'building_statuses', $building_statuses);
        echo renderConfigTable('Floor Config', 'building_floor_types', $building_floor_types);
        echo renderConfigTable('Flat Type', 'flat_types', $flat_types);
        echo renderConfigTable('Flat Status', 'flat_statuses', $flat_statuses);
        echo renderConfigTable('Relationship', 'resident_relationships', $resident_relationships);
        echo renderConfigTable('Resident Status', 'resident_statuses', $resident_statuses);
        echo renderConfigTable('Domestic Role', 'domestic_staff_roles', $ds_roles);
        echo renderConfigTable('Domestic Status', 'domestic_staff_status', $ds_status);
        echo renderConfigTable('Ownership Type', 'property_ownership_types', $ownership_types);
        echo renderConfigTable('Acquisition Type', 'property_acquisition_types', $acquisition_types);
        echo renderConfigTable('Incident Type', 'incident_types', $incident_types);
        echo renderConfigTable('Incident Severity', 'incident_severities', $incident_severities);
        echo renderConfigTable('Incident Status', 'incident_statuses', $incident_statuses);
        echo renderConfigTable('Entity Type', 'incident_entity_types', $incident_entity_types);
        ?>

        <!-- Commercial Property Configuration -->
        <div id="commercial_config" class="tab-content" style="display: none;">
            <div class="glass" style="padding: 2rem; border-radius: 0.5rem;">
                <h3 style="margin-bottom: 1.5rem; color: var(--primary-color);">Commercial Property Fields</h3>
                <div style="background: #f8fafc; padding: 1.5rem; border-radius: 0.5rem; margin-bottom: 2rem;">
                    <form method="POST">
                        <div style="display: grid; grid-template-columns: 2fr 1fr 2fr auto; gap: 1rem; align-items: flex-end;">
                            <div class="form-group">
                                <label>Field Label</label>
                                <input type="text" name="field_label" required class="form-control" placeholder="e.g. License Number" style="margin-top: 5px;">
                            </div>
                            <div class="form-group">
                                <label>Field Type</label>
                                <select name="field_type" class="form-control" style="margin-top: 5px;">
                                    <option value="text">Text</option>
                                    <option value="number">Number</option>
                                    <option value="date">Date</option>
                                    <option value="dropdown">Dropdown</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Options (for Dropdown)</label>
                                <input type="text" name="field_options" class="form-control" placeholder="Opt1, Opt2, Opt3" style="margin-top: 5px;">
                            </div>
                            <div style="padding-bottom: 5px;">
                                <label style="display: flex; align-items: center; gap: 5px; cursor: pointer; font-size: 0.875rem;">
                                    <input type="checkbox" name="is_required"> Required
                                </label>
                            </div>
                            <div style="padding-bottom: 2px;">
                                <button type="submit" name="add_commercial_field" class="btn btn-primary">Add Field</button>
                            </div>
                        </div>
                    </form>
                </div>

                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="text-align: left; color: #64748b; font-size: 0.875rem;">
                            <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Label</th>
                            <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Type</th>
                            <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Options</th>
                            <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Required</th>
                            <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($cf = $commercial_fields->fetch_assoc()): ?>
                        <tr>
                            <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9; font-weight: 500;"><?php echo htmlspecialchars($cf['field_label']); ?></td>
                            <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9; text-transform: capitalize;"><?php echo htmlspecialchars($cf['field_type']); ?></td>
                            <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 0.85rem;"><?php echo htmlspecialchars($cf['field_options'] ?: 'N/A'); ?></td>
                            <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;"><?php echo $cf['is_required'] ? '<span style="color: #10b981;"><i class="fa-solid fa-check"></i></span>' : '<span style="color: #94a3b8;"><i class="fa-solid fa-minus"></i></span>'; ?></td>
                            <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;">
                                <form method="POST" onsubmit="return confirm('Remove this field?');" style="display:inline;">
                                    <input type="hidden" name="id" value="<?php echo $cf['id']; ?>">
                                    <button type="submit" name="delete_commercial_field" style="background: none; border: none; color: #ef4444; cursor: pointer;"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if($commercial_fields->num_rows == 0): ?>
                        <tr><td colspan="5" style="padding: 2rem; text-align: center; color: #94a3b8;">No custom fields defined for commercial properties.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Paystack API Settings Tab -->
        <div id="paystack_api" class="tab-content" style="display: none;">
             <div class="glass" style="padding: 2rem; border-radius: 0.5rem;">
                <h3 style="margin-bottom: 1.5rem; color: #000; display: flex; align-items: center;">
                    <img src="https://paystack.com/assets/img/v3/logo/paystack-logo-vector.svg" style="height: 25px; margin-right: 10px;">
                    API Configuration
                </h3>
                <div style="background: #eff6ff; border-left: 4px solid #3b82f6; padding: 1rem; border-radius: 0.25rem; margin-bottom: 2rem; font-size: 0.9rem; color: #1e3a8a;">
                    <i class="fa-solid fa-circle-info" style="margin-right: 5px;"></i>
                    Enter your Paystack Test or Live keys here. You can find these in your <a href="https://dashboard.paystack.com/#/settings/developer" target="_blank" style="color: #2563eb; font-weight: 600;">Paystack Dashboard Settings</a>.
                </div>
                <form method="POST">
                    <div style="margin-bottom: 1.5rem;">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Public Key</label>
                        <input type="text" name="paystack_public_key" placeholder="pk_test_..." value="<?php echo htmlspecialchars($sys['paystack_public_key'] ?? ''); ?>" style="width: 100%; padding: 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; font-family: monospace;">
                    </div>
                    <div style="margin-bottom: 2rem;">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Secret Key</label>
                        <input type="password" name="paystack_secret_key" placeholder="sk_test_..." value="<?php echo htmlspecialchars($sys['paystack_secret_key'] ?? ''); ?>" style="width: 100%; padding: 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; font-family: monospace;">
                    </div>
                    <div style="text-align: right;">
                        <button type="submit" name="update_paystack" class="btn btn-primary" style="padding: 0.75rem 2rem;">Save Paystack Keys</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Email & SMTP Settings Tab -->
        <div id="smtp_config" class="tab-content" style="display: none;">
            <div class="glass" style="padding: 2rem; border-radius: 0.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <h3 style="margin: 0; color: #0f172a; font-size: 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fa-solid fa-envelope-circle-check" style="color: var(--primary-color);"></i>
                            Email &amp; SMTP Configuration
                        </h3>
                        <p style="margin: 0.25rem 0 0 0; font-size: 0.875rem; color: #64748b;">
                            Configure outgoing mail delivery for invoices, receipts, gate passes, and security alerts.
                        </p>
                    </div>
                    <a href="email_logs.php" class="btn" style="background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; text-decoration: none; padding: 0.5rem 1rem; border-radius: 0.375rem; font-size: 0.875rem;">
                        <i class="fa-solid fa-clock-rotate-left" style="margin-right: 4px;"></i> View Email Logs
                    </a>
                </div>

                <form method="POST">
                    <!-- Delivery Driver Mode -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 0.5rem; padding: 1.25rem; margin-bottom: 1.5rem;">
                        <label style="display: flex; align-items: center; gap: 0.75rem; cursor: pointer; font-weight: 600; color: #0f172a;">
                            <input type="checkbox" name="smtp_enabled" value="1" <?php echo (!empty($sys['smtp_enabled']) && $sys['smtp_enabled'] == '1') ? 'checked' : ''; ?> style="width: 18px; height: 18px;">
                            <span>Enable Custom SMTP Delivery</span>
                        </label>
                        <div style="font-size: 0.825rem; color: #64748b; margin-top: 0.25rem; margin-left: 28px;">
                            When checked, emails are dispatched directly through your configured SMTP server (cPanel Webmail, Google Workspace, SendGrid, etc.). When unchecked, standard PHP mail() is used as fallback.
                        </div>
                    </div>

                    <!-- Server Settings Grid -->
                    <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                        <div>
                            <label style="display: block; margin-bottom: 0.25rem; font-size: 0.875rem; font-weight: 500;">SMTP Host</label>
                            <input type="text" name="smtp_host" placeholder="e.g. mail.yourdomain.com or smtp.gmail.com" value="<?php echo htmlspecialchars($sys['smtp_host'] ?? ''); ?>" style="width: 100%; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; font-family: monospace;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 0.25rem; font-size: 0.875rem; font-weight: 500;">Port</label>
                            <input type="number" name="smtp_port" placeholder="587" value="<?php echo htmlspecialchars($sys['smtp_port'] ?? '587'); ?>" style="width: 100%; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; font-family: monospace;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 0.25rem; font-size: 0.875rem; font-weight: 500;">Encryption</label>
                            <select name="smtp_encryption" style="width: 100%; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; background: #fff;">
                                <option value="tls" <?php echo (($sys['smtp_encryption'] ?? '') === 'tls') ? 'selected' : ''; ?>>TLS (Port 587)</option>
                                <option value="ssl" <?php echo (($sys['smtp_encryption'] ?? '') === 'ssl') ? 'selected' : ''; ?>>SSL (Port 465)</option>
                                <option value="none" <?php echo (($sys['smtp_encryption'] ?? '') === 'none') ? 'selected' : ''; ?>>None (Port 25)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Authentication Credentials -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                        <div>
                            <label style="display: block; margin-bottom: 0.25rem; font-size: 0.875rem; font-weight: 500;">SMTP Username / Email</label>
                            <input type="text" name="smtp_user" placeholder="e.g. notifications@yourdomain.com" value="<?php echo htmlspecialchars($sys['smtp_user'] ?? ''); ?>" style="width: 100%; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 0.375rem;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 0.25rem; font-size: 0.875rem; font-weight: 500;">SMTP Password</label>
                            <input type="password" name="smtp_pass" placeholder="<?php echo !empty($sys['smtp_pass']) ? '•••••••• (Leave blank to keep existing)' : 'Enter SMTP password'; ?>" value="" style="width: 100%; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 0.375rem;">
                        </div>
                    </div>

                    <!-- Sender Profile -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                        <div>
                            <label style="display: block; margin-bottom: 0.25rem; font-size: 0.875rem; font-weight: 500;">Sender Display Name</label>
                            <input type="text" name="smtp_from_name" placeholder="e.g. <?php echo htmlspecialchars($sys['estate_name'] ?? 'Estate Management'); ?>" value="<?php echo htmlspecialchars($sys['smtp_from_name'] ?? ''); ?>" style="width: 100%; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 0.375rem;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 0.25rem; font-size: 0.875rem; font-weight: 500;">Sender Email Address</label>
                            <input type="email" name="smtp_from_email" placeholder="e.g. noreply@yourdomain.com" value="<?php echo htmlspecialchars($sys['smtp_from_email'] ?? ''); ?>" style="width: 100%; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 0.375rem;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 0.25rem; font-size: 0.875rem; font-weight: 500;">Reply-To Email</label>
                            <input type="email" name="smtp_reply_to" placeholder="e.g. info@yourdomain.com" value="<?php echo htmlspecialchars($sys['smtp_reply_to'] ?? ''); ?>" style="width: 100%; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 0.375rem;">
                        </div>
                    </div>

                    <!-- Notification Triggers Toggles -->
                    <h4 style="margin: 1.5rem 0 0.75rem 0; color: #1e293b; font-size: 1rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.5rem;">
                        Automatic Email Dispatch Triggers
                    </h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 2rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; color: #334155; cursor: pointer;">
                            <input type="checkbox" name="notify_on_invoice" value="1" <?php echo (($sys['notify_on_invoice'] ?? '1') == '1') ? 'checked' : ''; ?>>
                            <span><strong>Invoices:</strong> Send email when bill/invoice is generated</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; color: #334155; cursor: pointer;">
                            <input type="checkbox" name="notify_on_receipt" value="1" <?php echo (($sys['notify_on_receipt'] ?? '1') == '1') ? 'checked' : ''; ?>>
                            <span><strong>Receipts:</strong> Send email receipt on successful payment</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; color: #334155; cursor: pointer;">
                            <input type="checkbox" name="notify_on_visitor_pass" value="1" <?php echo (($sys['notify_on_visitor_pass'] ?? '1') == '1') ? 'checked' : ''; ?>>
                            <span><strong>Visitor Passes:</strong> Deliver digital passes to resident &amp; visitor</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; color: #334155; cursor: pointer;">
                            <input type="checkbox" name="notify_on_visitor_arrival" value="1" <?php echo (($sys['notify_on_visitor_arrival'] ?? '1') == '1') ? 'checked' : ''; ?>>
                            <span><strong>Gate Arrivals:</strong> Alert resident when visitor arrives at gate</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; color: #334155; cursor: pointer;">
                            <input type="checkbox" name="notify_on_welcome" value="1" <?php echo (($sys['notify_on_welcome'] ?? '1') == '1') ? 'checked' : ''; ?>>
                            <span><strong>Welcome Onboarding:</strong> Email login credentials to new users</span>
                        </label>
                    </div>

                    <div style="text-align: right; padding-top: 1rem; border-top: 1px solid #e2e8f0;">
                        <button type="submit" name="update_smtp" class="btn btn-primary" style="padding: 0.75rem 2rem;">Save Email Settings</button>
                    </div>
                </form>

                <!-- Diagnostic Test Tool -->
                <div style="margin-top: 2.5rem; padding: 1.5rem; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 0.5rem;">
                    <h4 style="margin: 0 0 0.5rem 0; color: #0f172a; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fa-solid fa-stethoscope" style="color: #2563eb;"></i> Live SMTP Test Diagnostic
                    </h4>
                    <p style="margin: 0 0 1rem 0; font-size: 0.85rem; color: #64748b;">
                        Verify that your SMTP host and authentication credentials work by sending an instant test verification email.
                    </p>
                    <form method="POST" style="display: flex; gap: 0.75rem; max-width: 600px;">
                        <input type="email" name="test_email" required placeholder="Enter your email to receive test message" style="flex-grow: 1; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 0.375rem;">
                        <button type="submit" name="send_test_email" class="btn" style="background: #2563eb; color: #fff; padding: 0.65rem 1.5rem; border-radius: 0.375rem; border: none; font-weight: 500; cursor: pointer;">
                            <i class="fa-solid fa-paper-plane" style="margin-right: 4px;"></i> Send Test
                        </button>
                    </form>
                </div>
            </div>
        </div>
     </div>
</div>

<script>
function openTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName("tab-content");
    for (i = 0; i < tabcontent.length; i++) {
        tabcontent[i].style.display = "none";
    }
    tablinks = document.getElementsByClassName("settings-nav-btn");
    for (i = 0; i < tablinks.length; i++) {
        tablinks[i].className = tablinks[i].className.replace(" active", "");
    }
    var target = document.getElementById(tabName);
    if (target) target.style.display = "block";
    if (evt && evt.currentTarget) {
        evt.currentTarget.className += " active";
    }
    if (window.history.replaceState) {
        const url = new URL(window.location);
        url.searchParams.set('tab', tabName);
        window.history.replaceState({}, '', url);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get('tab') || '<?php echo htmlspecialchars($active_tab); ?>';
    if (activeTab && document.getElementById(activeTab)) {
        var tabcontent = document.getElementsByClassName("tab-content");
        for (var i = 0; i < tabcontent.length; i++) {
            tabcontent[i].style.display = "none";
        }
        var tablinks = document.getElementsByClassName("settings-nav-btn");
        for (var i = 0; i < tablinks.length; i++) {
            tablinks[i].classList.remove("active");
            var onclickAttr = tablinks[i].getAttribute('onclick') || '';
            if (onclickAttr.indexOf("'" + activeTab + "'") !== -1) {
                tablinks[i].classList.add("active");
            }
        }
        document.getElementById(activeTab).style.display = "block";
    }
});

function previewImage(input, previewId) {
    var preview = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function previewEstateLogo(input) {
    var preview = document.getElementById('estate_logo_preview');
    var fallback = document.getElementById('estate_logo_fallback');
    var headerImg = document.getElementById('header_preview_img');
    var headerFallback = document.getElementById('header_preview_fallback');
    var filenameLabel = document.getElementById('estate_logo_filename');
    
    if (input.files && input.files[0]) {
        var file = input.files[0];
        if (filenameLabel) {
            filenameLabel.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
        }
        var reader = new FileReader();
        reader.onload = function(e) {
            if (preview) {
                preview.src = e.target.result;
                preview.style.display = 'block';
            }
            if (fallback) fallback.style.display = 'none';
            
            if (headerImg) {
                headerImg.src = e.target.result;
                headerImg.style.display = 'block';
            }
            if (headerFallback) {
                headerFallback.style.display = 'none';
            }
        };
        reader.readAsDataURL(file);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var nameInput = document.querySelector('input[name="estate_name"]');
    var previewName = document.getElementById('header_preview_name');
    if (nameInput && previewName) {
        nameInput.addEventListener('input', function() {
            previewName.textContent = this.value.trim() || 'Estate Name';
        });
    }
});

async function editConfig(table, id, currentName) {
    let newName = await EstateDialog.prompt({
        title: 'Edit Configuration',
        message: 'Enter updated name for this configuration item:',
        defaultValue: currentName,
        confirmText: 'Save Changes'
    });
    if (newName !== null && newName !== currentName && newName.trim() !== "") {
        let form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        let inputTable = document.createElement('input');
        inputTable.type = 'hidden';
        inputTable.name = 'table';
        inputTable.value = table;
        
        let inputId = document.createElement('input');
        inputId.type = 'hidden';
        inputId.name = 'id';
        inputId.value = id;

        let inputName = document.createElement('input');
        inputName.type = 'hidden';
        inputName.name = 'name';
        inputName.value = newName;
        
        let inputAction = document.createElement('input');
        inputAction.type = 'hidden';
        inputAction.name = 'edit_type';
        inputAction.value = '1';

        form.appendChild(inputTable);
        form.appendChild(inputId);
        form.appendChild(inputName);
        form.appendChild(inputAction);
        
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php include '../includes/footer.php'; ?>
