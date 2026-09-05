<?php
// admin/settings.php
require_once '../config.php';
require_once '../includes/auth_guard.php';
requireAdminAccess();

include '../includes/header.php';
include '../includes/sidebar.php';

// Helper to handle uploads
if (!function_exists('handleUpload')) {
    function handleUpload($file) {
        if ($file['error'] == 0) {
            $target_dir = "../uploads/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            $target_file = $target_dir . time() . "_" . basename($file["name"]);
            if (move_uploaded_file($file["tmp_name"], $target_file)) {
                return $target_file; 
            }
        }
        return null;
    }
}

// Handle Actions
$message = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Update General Settings
    if (isset($_POST['update_general'])) {
        $settings = [
            'estate_name' => $_POST['estate_name'] ?? '',
            'estate_location' => $_POST['estate_location'] ?? '',
            'estate_motto' => $_POST['estate_motto'] ?? '',
            'estate_rules' => $_POST['estate_rules'] ?? '',
            'estate_code_of_conduct' => $_POST['estate_code_of_conduct'] ?? '',
            'currency_symbol' => $_POST['currency_symbol'] ?? '',
            'office_email' => $_POST['office_email'] ?? '',
            'office_phone' => $_POST['office_phone'] ?? '',
            'theme_color' => $_POST['theme_color'] ?? ''
        ];
        
        $estate_id = get_estate_id();
        foreach ($settings as $key => $val) {
            $val = $conn->real_escape_string($val);
            $conn->query("UPDATE system_settings SET setting_value = '$val' WHERE setting_key = '$key' AND estate_id = $estate_id");
        }

        if (!empty($_FILES['estate_logo']['name'])) {
            $path = handleUpload($_FILES['estate_logo']);
            if ($path) {
                $path = $conn->real_escape_string($path);
                $conn->query("UPDATE system_settings SET setting_value = '$path' WHERE setting_key = 'estate_logo' AND estate_id = $estate_id");
            }
        }
        if (!empty($_FILES['estate_hero_image']['name'])) {
            $path = handleUpload($_FILES['estate_hero_image']);
            if ($path) {
                $path = $conn->real_escape_string($path);
                $conn->query("UPDATE system_settings SET setting_value = '$path' WHERE setting_key = 'estate_hero_image' AND estate_id = $estate_id");
            }
        }
        $message = "General settings updated successfully!";
        
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
        
    } elseif (isset($_POST['add_type'])) {
        $table = $_POST['table'];
        $name = $conn->real_escape_string($_POST['name']);
        
        $allowed_tables = ['building_types', 'flat_types', 'resident_relationships', 'domestic_staff_roles', 'domestic_staff_status', 'estate_staff_roles', 'building_floor_types', 'building_statuses', 'flat_statuses', 'resident_statuses', 'property_ownership_types', 'property_acquisition_types'];
        if (in_array($table, $allowed_tables)) {
            $sql = "INSERT INTO $table (name) VALUES ('$name')";
            if ($conn->query($sql)) $message = "Item added successfully!";
            else $message = "Error: " . $conn->error;
        }
    } elseif (isset($_POST['delete_type'])) {
        $table = $_POST['table'];
        $id = intval($_POST['id']);
        
        $allowed_tables = ['building_types', 'flat_types', 'resident_relationships', 'domestic_staff_roles', 'domestic_staff_status', 'estate_staff_roles', 'building_floor_types', 'building_statuses', 'flat_statuses', 'resident_statuses', 'property_ownership_types', 'property_acquisition_types'];
        if (in_array($table, $allowed_tables)) {
            $sql = "DELETE FROM $table WHERE id = $id";
            if ($conn->query($sql)) $message = "Item deleted successfully!";
            else $message = "Error: " . $conn->error;
        }
    } elseif (isset($_POST['edit_type'])) {
        $table = $_POST['table'];
        $id = intval($_POST['id']);
        $name = $conn->real_escape_string($_POST['name']);
        
        $allowed_tables = ['building_types', 'flat_types', 'resident_relationships', 'domestic_staff_roles', 'domestic_staff_status', 'estate_staff_roles', 'building_floor_types', 'building_statuses', 'flat_statuses', 'resident_statuses'];
        if (in_array($table, $allowed_tables)) {
            $sql = "UPDATE $table SET name = '$name' WHERE id = $id";
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
$building_types = $conn->query("SELECT * FROM building_types ORDER BY name");
$flat_types = $conn->query("SELECT * FROM flat_types ORDER BY name");
$resident_relationships = $conn->query("SELECT * FROM resident_relationships ORDER BY name");
$ds_roles = $conn->query("SELECT * FROM domestic_staff_roles ORDER BY name");
$ds_status = $conn->query("SELECT * FROM domestic_staff_status ORDER BY name");
$estate_staff_roles = $conn->query("SELECT * FROM estate_staff_roles ORDER BY name ASC");
$building_floor_types = $conn->query("SELECT * FROM building_floor_types ORDER BY name ASC");
$building_statuses = $conn->query("SELECT * FROM building_statuses ORDER BY name ASC");
$flat_statuses = $conn->query("SELECT * FROM flat_statuses ORDER BY name ASC");
$resident_statuses = $conn->query("SELECT * FROM resident_statuses ORDER BY name ASC");
$ownership_types = $conn->query("SELECT * FROM property_ownership_types ORDER BY name ASC");
$acquisition_types = $conn->query("SELECT * FROM property_acquisition_types ORDER BY name ASC");
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
        
        <div class="settings-group-title">Estate Staff</div>
        <button class="settings-nav-btn" onclick="openTab(event, 'estate_staff_roles')"><i class="fa-solid fa-user-shield"></i> Staff Roles</button>

        <div class="settings-group-title">Finance</div>
        <button class="settings-nav-btn" onclick="openTab(event, 'paystack_api')"><i class="fa-solid fa-credit-card"></i> Paystack API</button>

        <div class="settings-group-title">Ownership & Transfers</div>
        <button class="settings-nav-btn" onclick="openTab(event, 'property_ownership_types')"><i class="fa-solid fa-key"></i> Ownership Types</button>
        <button class="settings-nav-btn" onclick="openTab(event, 'property_acquisition_types')"><i class="fa-solid fa-file-contract"></i> Acquisition Types</button>
    </div>

    <!-- Content Area -->
    <div style="min-width: 0;">
        <!-- General Settings Tab -->
        <div id="general" class="tab-content">
            <div class="glass" style="padding: 2rem; border-radius: 0.5rem;">
                <form method="POST" enctype="multipart/form-data">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                        <!-- Identity Section -->
                        <div>
                            <h3 style="margin-bottom: 1rem; color: var(--primary-color);">Identity & Branding</h3> <!-- UPDATED -->
                            <div style="margin-bottom: 1rem;">
                                <label>Estate Name</label>
                                <input type="text" name="estate_name" value="<?php echo htmlspecialchars($sys['estate_name'] ?? ''); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; margin-top: 0.25rem;">
                            </div>
                            <div style="margin-bottom: 1rem;">
                                <label>Location</label>
                                <input type="text" name="estate_location" value="<?php echo htmlspecialchars($sys['estate_location'] ?? ''); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; margin-top: 0.25rem;">
                            </div>
                            <div style="margin-bottom: 1rem;">
                                <label>Motto</label>
                                <input type="text" name="estate_motto" value="<?php echo htmlspecialchars($sys['estate_motto'] ?? ''); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; margin-top: 0.25rem;">
                            </div>
                            <div style="margin-bottom: 1rem;">
                                <label>Estate Logo</label>
                                <?php if(!empty($sys['estate_logo'])): ?>
                                    <div style="margin: 0.5rem 0;"><img src="<?php echo htmlspecialchars($sys['estate_logo']); ?>" style="height: 50px; border-radius: 4px; border: 1px solid #e2e8f0;"></div>
                                <?php endif; ?>
                                <input type="file" name="estate_logo" accept="image/*" onchange="previewImage(this, 'logo_preview')" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; margin-top: 0.25rem; background: #fff;">
                                <img id="logo_preview" src="" style="display: none; width: 100%; height: auto; max-height: 150px; object-fit: contain; margin-top: 0.5rem; border-radius: 4px; border: 1px solid #e2e8f0;">
                            </div>
                            <div style="margin-bottom: 1rem;">
                                <label>Hero Image</label>
                                <?php if(!empty($sys['estate_hero_image'])): ?>
                                    <div style="margin: 0.5rem 0;"><img src="<?php echo htmlspecialchars($sys['estate_hero_image']); ?>" style="height: 50px; border-radius: 4px; border: 1px solid #e2e8f0;"></div>
                                <?php endif; ?>
                                <input type="file" name="estate_hero_image" accept="image/*" onchange="previewImage(this, 'hero_preview')" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; margin-top: 0.25rem; background: #fff;">
                                <img id="hero_preview" src="" style="display: none; width: 100%; height: auto; max-height: 200px; object-fit: contain; margin-top: 0.5rem; border-radius: 4px; border: 1px solid #e2e8f0;">
                            </div>
                        </div>

                        <!-- Governance & Contact -->
                        <div>
                            <h3 style="margin-bottom: 1rem; color: var(--primary-color);">Governance & Info</h3> <!-- UPDATED -->
                            <div style="margin-bottom: 1rem;">
                                <label>Estate Rules</label>
                                <textarea name="estate_rules" rows="5" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; margin-top: 0.25rem;"><?php echo htmlspecialchars($sys['estate_rules'] ?? ''); ?></textarea>
                            </div>
                            <div style="margin-bottom: 1rem;">
                                <label>Code of Conduct</label>
                                <textarea name="estate_code_of_conduct" rows="3" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; margin-top: 0.25rem;"><?php echo htmlspecialchars($sys['estate_code_of_conduct'] ?? ''); ?></textarea>
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                <div style="margin-bottom: 1rem;">
                                    <label>Office Phone</label>
                                    <input type="text" name="office_phone" value="<?php echo htmlspecialchars($sys['office_phone'] ?? ''); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; margin-top: 0.25rem;">
                                </div>
                                <div style="margin-bottom: 1rem;">
                                    <label>Office Email</label>
                                    <input type="email" name="office_email" value="<?php echo htmlspecialchars($sys['office_email'] ?? ''); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; margin-top: 0.25rem;">
                                </div>
                            </div>
                            <div style="margin-bottom: 1rem;">
                                <label>Currency Symbol</label>
                                <input type="text" name="currency_symbol" value="<?php echo htmlspecialchars($sys['currency_symbol'] ?? ''); ?>" style="width: 80px; padding: 0.5rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; margin-top: 0.25rem;">
                            </div>
                            <div style="margin-bottom: 1rem;">
                                <label>Theme Color</label>
                                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.25rem;">
                                    <?php 
                                    $current_color = $sys['theme_color'] ?? '#3b82f6';
                                    $presets = ['#3b82f6', '#10b981', '#a855f7', '#f43f5e', '#f59e0b', '#14b8a6', '#0f172a'];
                                    foreach($presets as $color): 
                                    ?>
                                        <div onclick="document.querySelector('input[name=theme_color]').value='<?php echo $color; ?>'" style="width: 32px; height: 32px; background: <?php echo $color; ?>; border-radius: 50%; cursor: pointer; border: 2px solid <?php echo $current_color == $color ? '#1e293b' : 'transparent'; ?>; box-shadow: 0 1px 2px rgba(0,0,0,0.1);"></div>
                                    <?php endforeach; ?>
                                    <input type="color" name="theme_color" value="<?php echo htmlspecialchars($current_color); ?>" style="height: 32px; width: 60px; padding: 0; border: none; background: none; cursor: pointer;">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div style="margin-top: 1.5rem; text-align: right;">
                        <button type="submit" name="update_general" class="btn btn-primary" style="padding: 0.75rem 2rem; font-size: 1rem;">Save Changes</button>
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
        echo renderConfigTable('Staff Role', 'estate_staff_roles', $estate_staff_roles);
        echo renderConfigTable('Ownership Type', 'property_ownership_types', $ownership_types);
        echo renderConfigTable('Acquisition Type', 'property_acquisition_types', $acquisition_types);
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
    document.getElementById(tabName).style.display = "block";
    evt.currentTarget.className += " active";
}

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

function editConfig(table, id, currentName) {
    let newName = prompt("Edit Name:", currentName);
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
