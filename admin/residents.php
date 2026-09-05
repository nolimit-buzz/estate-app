<?php
// admin/residents.php
require_once '../config.php';
require_once '../includes/auth_guard.php';
requireAdminAccess();

include '../includes/header.php';
include '../includes/sidebar.php';

// Helper to handle uploads
function handleUpload($file) {
    global $conn;
    if ($file['error'] == 0) {
        $target_dir = "../uploads/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $target_file = $target_dir . time() . "_" . basename($file["name"]);
        if (move_uploaded_file($file["tmp_name"], $target_file)) {
            return $conn->real_escape_string($target_file);
        }
    }
    return null;
}

// Generate Custom ID helper (Collision-Proof)
if (!function_exists('generateCustomID')) {
    function generateCustomID($conn, $table, $prefix) {
        $res = $conn->query("SELECT custom_id FROM $table WHERE custom_id LIKE '$prefix-%'");
        $max_num = 0;
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if (preg_match('/' . preg_quote($prefix, '/') . '-0*(\d+)/', $row['custom_id'], $m)) {
                    $num = intval($m[1]);
                    if ($num > $max_num) $max_num = $num;
                }
            }
        }
        
        $id_res = $conn->query("SELECT MAX(id) as max_id FROM $table");
        if ($id_res && $row = $id_res->fetch_assoc()) {
            $max_id = intval($row['max_id'] ?? 0);
            if ($max_id > $max_num) $max_num = $max_id;
        }
        
        $next = $max_num + 1;
        
        do {
            $candidate = $prefix . '-' . str_pad($next, 5, '0', STR_PAD_LEFT);
            $check = $conn->query("SELECT id FROM $table WHERE custom_id = '$candidate' LIMIT 1");
            if ($check && $check->num_rows > 0) {
                $next++;
            } else {
                return $candidate;
            }
        } while ($next < $max_num + 1000);
        
        return $candidate;
    }
}

// Resident History Logger
function logResidentHistory($conn, $resident_id, $flat_id, $action, $notes = "") {
    $user_id = $_SESSION['user_id'] ?? null;
    
    // Get flat details for history
    $flat_res = $conn->query("SELECT f.*, b.id as b_id, s.id as s_id FROM flats f JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE f.id = $flat_id");
    $flat = ($flat_res && $flat_res->num_rows > 0) ? $flat_res->fetch_assoc() : [];
    
    $b_id = !empty($flat['b_id']) ? intval($flat['b_id']) : 'NULL';
    $s_id = !empty($flat['s_id']) ? intval($flat['s_id']) : 'NULL';
    $action = $conn->real_escape_string($action);
    $notes = $conn->real_escape_string($notes);
    $date = date('Y-m-d');
    
    $sql = "INSERT INTO resident_history (resident_id, flat_id, building_id, street_id, action_type, start_date, reason_for_exit, recorded_by) 
            VALUES ($resident_id, $flat_id, $b_id, $s_id, '$action', '$date', '$notes', " . ($user_id ? intval($user_id) : "NULL") . ")";
    return $conn->query($sql);
}

// Handle Form Submissions
$message = "";
$message_type = "success";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // RESIDENTS
    if (isset($_POST['add_resident'])) {
        $first_name = $conn->real_escape_string($_POST['first_name']);
        $last_name = $conn->real_escape_string($_POST['last_name']);
        $name = $first_name . ' ' . $last_name;
        $email = $conn->real_escape_string($_POST['email']);
        $phone = $conn->real_escape_string($_POST['phone']);
        $flat_id = intval($_POST['flat_id']);
        $type = $conn->real_escape_string($_POST['type']); // head or dependent
        $relationship = $conn->real_escape_string($_POST['relationship']); 
        $estate_id = get_estate_id();
        $reg_date = $conn->real_escape_string($_POST['registration_date']);
        $status = $conn->real_escape_string($_POST['status']);
        
        if (!empty($_POST['resident_id'])) {
            // Update
            $res_id = intval($_POST['resident_id']);
            $get_res = $conn->query("SELECT * FROM residents WHERE id = $res_id AND estate_id = $estate_id");
            if ($get_res->num_rows > 0) {
                $old_res = $get_res->fetch_assoc();
                $uid = $old_res['user_id'];
                $conn->query("UPDATE users SET first_name='$first_name', last_name='$last_name', name='$name', email='$email', phone='$phone' WHERE id=$uid");
                
                $updates = "flat_id=$flat_id, type='$type', relationship='$relationship', status='$status', registration_date='$reg_date'";
                $image = handleUpload($_FILES['image']);
                if($image) $updates .= ", image_path='$image'";
                
                if ($conn->query("UPDATE residents SET $updates WHERE id=$res_id AND estate_id=$estate_id")) {
                    $message = "Resident updated successfully!";
                    if ($old_res['flat_id'] != $flat_id) {
                        logResidentHistory($conn, $res_id, $old_res['flat_id'], 'Moved Out', "Transferred to another flat");
                        logResidentHistory($conn, $res_id, $flat_id, 'Moved In', "Transferred from another flat");
                    }
                } else {
                    $message = "Error: " . $conn->error;
                }
            }
        } else {
            // Insert
            $check = $conn->query("SELECT id FROM users WHERE email = '$email' AND estate_id = $estate_id");
            if ($check->num_rows > 0) {
                $user_id = $check->fetch_assoc()['id'];
            } else {
                $default_pass = !empty($first_name) ? trim($first_name) : 'welcome123';
                $password = password_hash($default_pass, PASSWORD_DEFAULT);
                $conn->query("INSERT INTO users (estate_id, first_name, last_name, name, email, phone, password, role) VALUES ($estate_id, '$first_name', '$last_name', '$name', '$email', '$phone', '$password', 'resident')");
                $user_id = $conn->insert_id;
            }
            $custom_id = generateCustomID($conn, 'residents', 'RES');
            $image = handleUpload($_FILES['image']);
            $sql = "INSERT INTO residents (estate_id, custom_id, user_id, flat_id, type, relationship, status, image_path, registration_date) VALUES ($estate_id, '$custom_id', $user_id, $flat_id, '$type', '$relationship', '$status', '$image', '$reg_date')";
            if ($conn->query($sql)) {
                $res_id = $conn->insert_id;
                $message = "Resident added successfully!";
                logResidentHistory($conn, $res_id, $flat_id, 'Moved In', "Initial move-in");
                
                // Track in tenancies table for historical preservation
                $conn->query("INSERT INTO tenancies (estate_id, resident_id, flat_id, move_in_date, status) VALUES ($estate_id, $user_id, $flat_id, '$reg_date', 'Active')");

                // Handle Dependents & Staff from Multi-entity form
                if (isset($_POST['family_first_name'])) {
                    foreach ($_POST['family_first_name'] as $i => $fname) {
                        if (empty($fname)) continue;
                        $fname = $conn->real_escape_string($fname);
                        $lname = $conn->real_escape_string($_POST['family_last_name'][$i]);
                        $fname_full = $fname . ' ' . $lname;
                        $category = $_POST['family_category'][$i];
                        $role_rel = $conn->real_escape_string($_POST['family_role'][$i]);
                        $f_email = $conn->real_escape_string($_POST['family_email'][$i]);
                        $f_phone = $conn->real_escape_string($_POST['family_phone'][$i]);
                        
                        $image_path = null;
                        if (isset($_FILES['family_image']['name'][$i]) && $_FILES['family_image']['error'][$i] == 0) {
                            $f_file = ['name'=>$_FILES['family_image']['name'][$i], 'type'=>$_FILES['family_image']['type'][$i], 'tmp_name'=>$_FILES['family_image']['tmp_name'][$i], 'error'=>$_FILES['family_image']['error'][$i], 'size'=>$_FILES['family_image']['size'][$i]];
                            $image_path = handleUpload($f_file);
                        }

                        if ($category == 'staff') {
                            $s_custom_id = generateCustomID($conn, 'household_staff', 'STF');
                            $sql = "INSERT INTO household_staff (estate_id, custom_id, flat_id, first_name, last_name, name, role, phone, status, image_path, registration_date) 
                                    VALUES ($estate_id, '$s_custom_id', $flat_id, '$fname', '$lname', '$fname_full', '$role_rel', '$f_phone', 'Daily', " . ($image_path ? "'$image_path'" : "NULL") . ", '$reg_date')";
                            $conn->query($sql);
                        } else {
                            $d_email = !empty($f_email) ? $f_email : "dep_" . time() . "_" . $i . "@estate.com";
                            $u_check = $conn->query("SELECT id FROM users WHERE email='$d_email' AND estate_id=$estate_id");
                            if($u_check->num_rows > 0) {
                                $d_uid = $u_check->fetch_assoc()['id'];
                                $conn->query("UPDATE users SET first_name='$fname', last_name='$lname', name='$fname_full' WHERE id=$d_uid");
                            } else {
                                $d_pass = !empty($fname) ? trim($fname) : 'welcome123';
                                $d_password = password_hash($d_pass, PASSWORD_DEFAULT);
                                $conn->query("INSERT INTO users (estate_id, first_name, last_name, name, email, phone, password, role) VALUES ($estate_id, '$fname', '$lname', '$fname_full', '$d_email', '$f_phone', '$d_password', 'resident')");
                                $d_uid = $conn->insert_id;
                            }
                            $d_custom_id = generateCustomID($conn, 'residents', 'RES');
                            $sql = "INSERT INTO residents (estate_id, custom_id, user_id, flat_id, type, relationship, status, image_path, registration_date) 
                                    VALUES ($estate_id, '$d_custom_id', $d_uid, $flat_id, 'dependent', '$role_rel', '$status', " . ($image_path ? "'$image_path'" : "NULL") . ", '$reg_date')";
                            $conn->query($sql);
                        }
                    }
                }

                // Handle Vehicles from Multi-entity form
                if (isset($_POST['vehicle_reg'])) {
                    foreach ($_POST['vehicle_reg'] as $i => $reg) {
                        if (empty($reg)) continue;
                        $reg = $conn->real_escape_string($reg);
                        $v_type = $_POST['vehicle_type'][$i];
                        $v_model = $conn->real_escape_string($_POST['vehicle_model'][$i]);
                        $v_image = null;
                        if (isset($_FILES['vehicle_image']['name'][$i]) && $_FILES['vehicle_image']['error'][$i] == 0) {
                            $v_file = ['name'=>$_FILES['vehicle_image']['name'][$i], 'type'=>$_FILES['vehicle_image']['type'][$i], 'tmp_name'=>$_FILES['vehicle_image']['tmp_name'][$i], 'error'=>$_FILES['vehicle_image']['error'][$i], 'size'=>$_FILES['vehicle_image']['size'][$i]];
                            $v_image = handleUpload($v_file);
                        }
                        $v_custom_id = generateCustomID($conn, 'vehicles', 'VEH');
                        $conn->query("INSERT INTO vehicles (estate_id, custom_id, flat_id, type, reg_number, model, image_path, registration_date) VALUES ($estate_id, '$v_custom_id', $flat_id, '$v_type', '$reg', '$v_model', ".($v_image ? "'$v_image'" : "NULL").", '$reg_date')");
                    }
                }

                // Handle Pets from Multi-entity form
                if (isset($_POST['pet_name'])) {
                    foreach ($_POST['pet_name'] as $i => $p_name) {
                        if (empty($p_name)) continue;
                        $p_name = $conn->real_escape_string($p_name);
                        $p_type = $conn->real_escape_string($_POST['pet_type'][$i]);
                        $p_breed = $conn->real_escape_string($_POST['pet_breed'][$i]);
                        $p_image = null;
                        if (isset($_FILES['pet_image']['name'][$i]) && $_FILES['pet_image']['error'][$i] == 0) {
                            $p_file = ['name'=>$_FILES['pet_image']['name'][$i], 'type'=>$_FILES['pet_image']['type'][$i], 'tmp_name'=>$_FILES['pet_image']['tmp_name'][$i], 'error'=>$_FILES['pet_image']['error'][$i], 'size'=>$_FILES['pet_image']['size'][$i]];
                            $p_image = handleUpload($p_file);
                        }
                        $p_custom_id = generateCustomID($conn, 'pets', 'PET');
                        $conn->query("INSERT INTO pets (estate_id, custom_id, flat_id, name, type, breed, image_path, registration_date) VALUES ($estate_id, '$p_custom_id', $flat_id, '$p_name', '$p_type', '$p_breed', ".($p_image ? "'$p_image'" : "NULL").", '$reg_date')");
                    }
                }
                $message .= " Additional details saved successfully.";
            } else {
                $message = "Error: " . $conn->error;
            }
        }
    } elseif (isset($_POST['archive_resident'])) {
        $estate_id = get_estate_id();
        $id = intval($_POST['archive_id']);
        $res_data = $conn->query("SELECT flat_id FROM residents WHERE id=$id AND estate_id=$estate_id")->fetch_assoc();
        if ($conn->query("UPDATE residents SET status='inactive' WHERE id=$id AND estate_id=$estate_id")) {
            $message = "Resident archived (set to inactive)!";
            logResidentHistory($conn, $id, $res_data['flat_id'], 'Moved Out', "Archived by Admin");
        } else {
            $message = "Error: " . $conn->error;
        }
    } elseif (isset($_POST['add_pet'])) {
        $flat_id = intval($_POST['flat_id']);
        $name = $conn->real_escape_string($_POST['name']);
        $type = $conn->real_escape_string($_POST['type']);
        $breed = $conn->real_escape_string($_POST['breed']);
        $reg_date = $conn->real_escape_string($_POST['registration_date']);
        $estate_id = get_estate_id();
        if (!empty($_POST['pet_id'])) {
            $id = intval($_POST['pet_id']);
            $updates = "flat_id=$flat_id, name='$name', type='$type', breed='$breed', registration_date='$reg_date'";
            $image = handleUpload($_FILES['image']);
            if($image) $updates .= ", image_path='$image'";
            if ($conn->query("UPDATE pets SET $updates WHERE id=$id AND estate_id=$estate_id")) $message = "Pet updated successfully!";
            else $message = "Error: " . $conn->error;
        } else {
            $custom_id = generateCustomID($conn, 'pets', 'PET');
            $image = handleUpload($_FILES['image']);
            $sql = "INSERT INTO pets (estate_id, custom_id, flat_id, name, type, breed, image_path, registration_date) VALUES ($estate_id, '$custom_id', $flat_id, '$name', '$type', '$breed', '$image', '$reg_date')";
            if ($conn->query($sql)) $message = "Pet added successfully!";
            else $message = "Error: " . $conn->error;
        }
    } elseif (isset($_POST['archive_pet'])) {
        $id = intval($_POST['archive_id']);
        $estate_id = get_estate_id();
        if ($conn->query("DELETE FROM pets WHERE id=$id AND estate_id=$estate_id")) $message = "Pet removed!";
        else $message = "Error: " . $conn->error;
    } elseif (isset($_POST['add_vehicle'])) {
        $flat_id = intval($_POST['flat_id']);
        $reg_number = $conn->real_escape_string($_POST['reg_number']);
        $v_type = $conn->real_escape_string($_POST['type']);
        $model = $conn->real_escape_string($_POST['model']);
        $reg_date = $conn->real_escape_string($_POST['registration_date']);
        $estate_id = get_estate_id();
        if (!empty($_POST['vehicle_id'])) {
            $id = intval($_POST['vehicle_id']);
            $updates = "flat_id=$flat_id, reg_number='$reg_number', type='$v_type', model='$model', registration_date='$reg_date'";
            $image = handleUpload($_FILES['image']);
            if($image) $updates .= ", image_path='$image'";
            if ($conn->query("UPDATE vehicles SET $updates WHERE id=$id AND estate_id=$estate_id")) $message = "Vehicle updated successfully!";
            else $message = "Error: " . $conn->error;
        } else {
            $custom_id = generateCustomID($conn, 'vehicles', 'VEH');
            $image = handleUpload($_FILES['image']);
            $sql = "INSERT INTO vehicles (estate_id, custom_id, flat_id, reg_number, type, model, image_path, registration_date) VALUES ($estate_id, '$custom_id', $flat_id, '$reg_number', '$v_type', '$model', '$image', '$reg_date')";
            if ($conn->query($sql)) $message = "Vehicle added successfully!";
            else $message = "Error: " . $conn->error;
        }
    } elseif (isset($_POST['archive_vehicle'])) {
        $id = intval($_POST['archive_id']);
        $estate_id = get_estate_id();
        if ($conn->query("DELETE FROM vehicles WHERE id=$id AND estate_id=$estate_id")) $message = "Vehicle removed!";
        else $message = "Error: " . $conn->error;
    } elseif (isset($_POST['add_staff'])) {
        $flat_id = intval($_POST['flat_id']);
        $first_name = $conn->real_escape_string($_POST['first_name']);
        $last_name = $conn->real_escape_string($_POST['last_name']);
        $name = $first_name . ' ' . $last_name;
        $role = $conn->real_escape_string($_POST['role']);
        $status = $conn->real_escape_string($_POST['status']);
        $phone = $conn->real_escape_string($_POST['phone']);
        $reg_date = $conn->real_escape_string($_POST['registration_date']);
        $estate_id = get_estate_id();
        if (!empty($_POST['staff_id'])) {
            $id = intval($_POST['staff_id']);
            $updates = "flat_id=$flat_id, first_name='$first_name', last_name='$last_name', name='$name', role='$role', status='$status', phone='$phone', registration_date='$reg_date'";
            $image = handleUpload($_FILES['image']);
            if($image) $updates .= ", image_path='$image'";
            if ($conn->query("UPDATE household_staff SET $updates WHERE id=$id AND estate_id=$estate_id")) $message = "Staff updated successfully!";
            else $message = "Error: " . $conn->error;
        } else {
            $custom_id = generateCustomID($conn, 'household_staff', 'STF');
            $image = handleUpload($_FILES['image']);
            $sql = "INSERT INTO household_staff (estate_id, custom_id, flat_id, first_name, last_name, name, role, status, phone, image_path, registration_date) VALUES ($estate_id, '$custom_id', $flat_id, '$first_name', '$last_name', '$name', '$role', '$status', '$phone', '$image', '$reg_date')";
            if ($conn->query($sql)) $message = "Staff added successfully!";
            else $message = "Error: " . $conn->error;
        }
    } elseif (isset($_POST['archive_staff'])) {
        $id = intval($_POST['archive_id']);
        $estate_id = get_estate_id();
        if ($conn->query("UPDATE household_staff SET status='inactive' WHERE id=$id AND estate_id=$estate_id")) {
            $message = "Staff archived!";
        } else {
            $message = "Error: " . $conn->error;
            $message_type = "error";
        }
    }
} catch (Throwable $e) {
    $message = "Error: " . $e->getMessage();
    $message_type = "error";
}
}



// Fetch Data
$estate_id = get_estate_id();
$residents = $conn->query("SELECT r.*, u.first_name, u.last_name, u.name, u.email, u.phone, f.number as flat_number, f.floor, b.id as building_id, b.name as building_name, s.id as street_id, s.name as street_name 
                           FROM residents r 
                           JOIN users u ON r.user_id = u.id 
                          JOIN flats f ON r.flat_id = f.id 
                          JOIN buildings b ON f.building_id = b.id 
                          JOIN streets s ON b.street_id = s.id
                          WHERE r.estate_id = $estate_id
                          ORDER BY s.name, b.name, f.number");

$pets = $conn->query("SELECT p.*, f.number as flat_number, f.floor, b.name as building_name, s.name as street_name 
                     FROM pets p 
                     JOIN flats f ON p.flat_id = f.id 
                     JOIN buildings b ON f.building_id = b.id 
                     JOIN streets s ON b.street_id = s.id
                     WHERE p.estate_id = $estate_id");

$vehicles = $conn->query("SELECT v.*, f.number as flat_number, f.floor, b.name as building_name, s.name as street_name 
                         FROM vehicles v 
                         JOIN flats f ON v.flat_id = f.id 
                         JOIN buildings b ON f.building_id = b.id 
                         JOIN streets s ON b.street_id = s.id
                         WHERE v.estate_id = $estate_id");

$staff = $conn->query("SELECT s.*, f.number as flat_number, f.floor, b.name as building_name, s_t.name as street_name 
                      FROM household_staff s 
                      JOIN flats f ON s.flat_id = f.id 
                      JOIN buildings b ON f.building_id = b.id 
                      JOIN streets s_t ON b.street_id = s_t.id
                      WHERE s.estate_id = $estate_id");

// Helper to get streets
$streets_list = $conn->query("SELECT * FROM streets WHERE status = 'active' AND estate_id = $estate_id ORDER BY name");

// Helper to get flats for dropdowns
$flats_list = $conn->query("SELECT f.id, f.number, f.floor, b.name as building_name, s.name as street_name FROM flats f JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE f.estate_id = $estate_id ORDER BY s.name, b.name, f.number");
$all_flats = [];
while($f = $flats_list->fetch_assoc()) { $all_flats[] = $f; }

// Helper for relationships
$relationships = $conn->query("SELECT * FROM resident_relationships ORDER BY name");
$resident_statuses = $conn->query("SELECT * FROM resident_statuses ORDER BY name");
$ds_roles = $conn->query("SELECT * FROM domestic_staff_roles ORDER BY name");
$ds_status = $conn->query("SELECT * FROM domestic_staff_status ORDER BY name");
?>

<div class="page-header">
    <h1>Resident Management</h1>
</div>

<?php if ($message): ?>
    <div class="alert" style="background: <?php echo ($message_type ?? 'success') === 'error' ? '#fee2e2' : '#dcfce7'; ?>; color: <?php echo ($message_type ?? 'success') === 'error' ? '#991b1b' : '#166534'; ?>; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem; border: 1px solid <?php echo ($message_type ?? 'success') === 'error' ? '#fca5a5' : '#86efac'; ?>;">
        <i class="fa-solid <?php echo ($message_type ?? 'success') === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check'; ?> me-2"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<!-- Tabs -->
<div class="tabs" style="display: flex; gap: 1rem; border-bottom: 2px solid #e2e8f0; margin-bottom: 2rem;">
    <button class="tab-btn active" onclick="openTab(event, 'residents')" style="padding: 0.5rem 1rem; background: none; border: none; border-bottom: 2px solid var(--primary-color); color: var(--primary-color); font-weight: 600; cursor: pointer;">Residents</button>
    <button class="tab-btn" onclick="openTab(event, 'vehicles')" style="padding: 0.5rem 1rem; background: none; border: none; border-bottom: 2px solid transparent; color: #64748b; font-weight: 600; cursor: pointer;">Vehicles</button>
    <button class="tab-btn" onclick="openTab(event, 'pets')" style="padding: 0.5rem 1rem; background: none; border: none; border-bottom: 2px solid transparent; color: #64748b; font-weight: 600; cursor: pointer;">Pets</button>
    <button class="tab-btn" onclick="openTab(event, 'staff')" style="padding: 0.5rem 1rem; background: none; border: none; border-bottom: 2px solid transparent; color: #64748b; font-weight: 600; cursor: pointer;">Domestic Staff</button>
</div>

<!-- Residents Tab -->
<div id="residents" class="tab-content">
    <div class="glass" style="padding: 2rem; border-radius: 0.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>All Residents</h3>
            <button class="btn btn-primary" onclick="openResidentModal()"><i class="fa-solid fa-user-plus"></i> Add Resident</button>
        </div>
        

        <table class="premium-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Image</th>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Address</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Contact</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $residents->fetch_assoc()): ?>
                <tr style="<?php echo $row['status'] == 'inactive' ? 'opacity: 0.6;' : ''; ?>">
                    <td style="font-family: monospace; color: #64748b;"><?php echo htmlspecialchars($row['custom_id']); ?></td>
                    <td>
                        <?php if($row['image_path']): ?>
                            <img src="<?php echo htmlspecialchars($row['image_path']); ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 50%;">
                        <?php else: ?>
                            <div style="width: 40px; height: 40px; background: #e2e8f0; border-radius: 50%;"></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-weight: 600;"><?php echo htmlspecialchars($row['first_name']); ?></div>
                    </td>
                    <td>
                        <div style="font-weight: 600;"><?php echo htmlspecialchars($row['last_name']); ?></div>
                    </td>
                    <td>
                        <?php echo htmlspecialchars('Flat ' . $row['flat_number'] . ' ' . $row['street_name'] . ' street ' . $row['building_name'] . ' building'); ?>
                    </td>
                    <td>
                        <span style="padding: 0.25rem 0.75rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; background: <?php echo $row['type'] == 'head' ? '#eff6ff' : '#f1f5f9'; ?>; color: <?php echo $row['type'] == 'head' ? '#1d4ed8' : '#64748b'; ?>;">
                            <?php echo ucfirst($row['type']); ?>
                        </span>
                        <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.25rem;"><?php echo ucfirst($row['relationship']); ?></div>
                    </td>
                    <td>
                        <span style="background: <?php echo ($row['status'] ?? 'Active') == 'Active' ? '#dcfce7' : '#f1f5f9'; ?>; color: <?php echo ($row['status'] ?? 'Active') == 'Active' ? '#166534' : '#64748b'; ?>; padding: 0.25rem 0.6rem; border-radius: 99px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;">
                            <?php echo htmlspecialchars($row['status'] ?? 'Active'); ?>
                        </span>
                    </td>
                    <td>
                        <div><?php echo htmlspecialchars($row['email']); ?></div>
                        <div style="font-size: 0.75rem; color: #94a3b8;"><?php echo htmlspecialchars($row['phone']); ?></div>
                        <a href="generate_id?id=<?php echo $row['id']; ?>&type=resident" target="_blank" style="display: inline-flex; align-items: center; gap: 0.3rem; margin-top: 0.25rem; font-size: 0.75rem; color: var(--primary-color); text-decoration: none; font-weight: 600;"><i class="fa-solid fa-id-card"></i> ID Card</a>
                    </td>
                    <td>
                         <a href="resident_timeline?id=<?php echo $row['id']; ?>" class="btn" style="padding: 0.4rem; background: #f8fafc; color: #64748b; margin-right: 0.25rem;" title="View Timeline"><i class="fa-solid fa-clock-rotate-left"></i></a>
                         <button onclick='editResident(<?php echo json_encode($row); ?>)' class="btn" style="padding: 0.4rem; background: #eff6ff; color: #2563eb; margin-right: 0.25rem;"><i class="fa-solid fa-edit"></i></button>
                         <?php if($row['status'] != 'inactive'): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to archive this resident?');">
                            <input type="hidden" name="archive_id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="archive_resident" class="btn" style="padding: 0.4rem; background: #fef2f2; color: #ef4444;"><i class="fa-solid fa-archive"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Vehicles Tab -->
<div id="vehicles" class="tab-content" style="display: none;">
    <div class="glass" style="padding: 2rem; border-radius: 0.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Registered Vehicles</h3>
            <button class="btn btn-primary" onclick="openModal('vehicle-modal')"><i class="fa-solid fa-car"></i> Add Vehicle</button>
        </div>


        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; color: #64748b;">
                    <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">ID</th>
                    <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Image</th>
                    <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Details</th>
                    <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Address</th>
                    <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Reg Number</th>
                    <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $vehicles->fetch_assoc()): ?>
                <tr>
                    <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9; font-family: monospace;"><?php echo htmlspecialchars($row['custom_id']); ?></td>
                    <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;">
                        <?php if($row['image_path']): ?>
                            <img src="<?php echo htmlspecialchars($row['image_path']); ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 0.25rem;">
                        <?php else: ?>
                            <div style="width: 40px; height: 40px; background: #e2e8f0; border-radius: 0.25rem;"></div>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;">
                         <span style="text-transform: capitalize; font-weight: 500;"><?php echo htmlspecialchars($row['type']); ?></span>
                         <div style="font-size: 0.75rem; color: #94a3b8;"><?php echo htmlspecialchars($row['model']); ?></div>
                    </td>
                    <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;"><?php echo htmlspecialchars('Flat ' . $row['flat_number'] . ' ' . $row['street_name'] . ' street ' . $row['building_name'] . ' building'); ?></td>
                    <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;"><span style="font-family: monospace; background: #e2e8f0; padding: 0.2rem 0.4rem; border-radius: 0.25rem;"><?php echo htmlspecialchars($row['reg_number']); ?></span></td>
                    <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;">
                         <button onclick='editVehicle(<?php echo json_encode($row); ?>)' style="background: none; border: none; cursor: pointer; color: var(--primary-color); margin-right: 0.5rem;"><i class="fa-solid fa-pen-to-square"></i></button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure?');">
                            <input type="hidden" name="archive_id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="archive_vehicle" style="background: none; border: none; cursor: pointer; color: #ef4444;"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pets Tab -->
<div id="pets" class="tab-content" style="display: none;">
    <div class="glass" style="padding: 2rem; border-radius: 0.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Resident Pets</h3>
            <button class="btn btn-primary" onclick="openModal('pet-modal')"><i class="fa-solid fa-paw"></i> Add Pet</button>
        </div>


        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; color: #64748b;">
                    <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">ID</th>
                    <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Image</th>
                    <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Name</th>
                    <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Type/Breed</th>
                    <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Address</th>
                    <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $pets->fetch_assoc()): ?>
                <tr>
                    <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9; font-family: monospace;"><?php echo htmlspecialchars($row['custom_id']); ?></td>
                    <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;">
                        <?php if($row['image_path']): ?>
                            <img src="<?php echo htmlspecialchars($row['image_path']); ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 50%;">
                        <?php else: ?>
                            <div style="width: 40px; height: 40px; background: #e2e8f0; border-radius: 50%;"></div>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9; font-weight: 500;"><?php echo htmlspecialchars($row['name']); ?></td>
                    <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;">
                         <?php echo htmlspecialchars($row['type']); ?>
                         <span style="color: #94a3b8; font-size: 0.875rem;">(<?php echo htmlspecialchars($row['breed']); ?>)</span>
                    </td>
                    <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;"><?php echo htmlspecialchars('Flat ' . $row['flat_number'] . ' ' . $row['street_name'] . ' street ' . $row['building_name'] . ' building'); ?></td>
                    <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;">
                         <button onclick='editPet(<?php echo json_encode($row); ?>)' style="background: none; border: none; cursor: pointer; color: var(--primary-color); margin-right: 0.5rem;"><i class="fa-solid fa-pen-to-square"></i></button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure?');">
                            <input type="hidden" name="archive_id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="archive_pet" style="background: none; border: none; cursor: pointer; color: #ef4444;"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Staff Tab -->
<div id="staff" class="tab-content" style="display: none;">
    <div class="glass" style="padding: 2rem; border-radius: 0.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3>Domestic Staff</h3>
            <button class="btn btn-primary" onclick="openModal('staff-modal')"><i class="fa-solid fa-user-check"></i> Add Staff</button>
        </div>


        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; color: #64748b;">
                    <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">ID</th>
                    <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Image</th>
                    <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Name/Role</th>
                    <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Address</th>
                    <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Contact</th>
                    <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Registered</th>
                    <th style="padding: 0.75rem; border-bottom: 1px solid #e2e8f0;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $staff->fetch_assoc()): ?>
                <tr style="<?php echo ($row['status'] ?? 'active') == 'inactive' ? 'opacity: 0.6; background: #f8fafc;' : ''; ?>">
                    <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9; font-family: monospace;"><?php echo htmlspecialchars($row['custom_id']); ?></td>
                    <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;">
                        <?php if($row['image_path']): ?>
                            <img src="<?php echo htmlspecialchars($row['image_path']); ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 50%;">
                        <?php else: ?>
                            <div style="width: 40px; height: 40px; background: #e2e8f0; border-radius: 50%;"></div>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;">
                         <div style="font-weight: 500;"><?php echo htmlspecialchars($row['name']); ?></div>
                         <div style="font-size: 0.75rem; color: #94a3b8;">
                             <?php echo htmlspecialchars($row['role']); ?> 
                             <span style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px; border: 1px solid #cbd5e1;"><?php echo htmlspecialchars($row['status'] ?? 'Daily'); ?></span>
                         </div>
                    </td>
                    <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;"><?php echo htmlspecialchars('Flat ' . $row['flat_number'] . ' ' . $row['street_name'] . ' street ' . $row['building_name'] . ' building'); ?></td>
                    <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;">
                        <?php echo htmlspecialchars($row['phone']); ?>
                        <div style="margin-top: 5px;">
                             <a href="generate_id?id=<?php echo $row['id']; ?>&type=staff" target="_blank" style="font-size: 0.75rem; color: var(--primary-color); text-decoration: none;"><i class="fa-solid fa-id-card"></i> ID Card</a>
                        </div>
                    </td>
                    <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9; font-size: 0.85rem;">
                        <?php echo $row['registration_date'] ? date('M d, Y', strtotime($row['registration_date'])) : '-'; ?>
                    </td>
                    <td style="padding: 0.75rem; border-bottom: 1px solid #f1f5f9;">
                         <button onclick='editStaff(<?php echo json_encode($row); ?>)' style="background: none; border: none; cursor: pointer; color: var(--primary-color); margin-right: 0.5rem;"><i class="fa-solid fa-pen-to-square"></i></button>
                        <?php if(($row['status'] ?? 'active') != 'inactive'): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure?');">
                            <input type="hidden" name="archive_id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="archive_staff" style="background: none; border: none; cursor: pointer; color: #ef4444;"><i class="fa-solid fa-box-archive"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>


<!-- Modal: resident-modal -->
        <!-- Resident Registration Modal -->
        <div id="resident-modal" class="custom-modal-backdrop">
            <div class="modal-content" style="max-width: 750px;">
                <div class="modal-header">
                    <h2 id="resident-modal-title">Register Resident</h2>
                    <button class="close-modal" type="button" onclick="closeModal('resident-modal')"><i class="fa-solid fa-times"></i></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="resident_form">
                    <input type="hidden" name="resident_id" id="res_resident_id">
                    <input type="hidden" name="add_resident" value="1">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>First Name</label>
                            <input type="text" name="first_name" id="res_first_name" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Last Name</label>
                            <input type="text" name="last_name" id="res_last_name" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" id="res_email" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Phone Number</label>
                            <input type="text" name="phone" id="res_phone" required class="form-control">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label style="font-weight: 600; color: #1e293b; margin-bottom: 0.5rem; display: block;">Assigned Residence Location</label>
                            <div style="background: #f8fafc; padding: 1.25rem; border-radius: 0.5rem; border: 1px solid #e2e8f0;">
                                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                                    <div>
                                        <label style="font-size: 0.8rem; color: #64748b; font-weight: 600; display: block; margin-bottom: 0.35rem;">
                                            1. Select Street <span style="color: #ef4444;">*</span>
                                        </label>
                                        <select id="res_street_id" class="form-control" onchange="fetchResidentBuildings(this.value)" required>
                                            <option value="">-- Choose Street --</option>
                                            <?php 
                                            $all_streets = $conn->query("SELECT * FROM streets WHERE status != 'archived' AND estate_id = $estate_id ORDER BY name");
                                            if ($all_streets && $all_streets->num_rows > 0):
                                                while($st = $all_streets->fetch_assoc()): ?>
                                                    <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['name']); ?></option>
                                                <?php endwhile;
                                            endif; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="font-size: 0.8rem; color: #64748b; font-weight: 600; display: block; margin-bottom: 0.35rem;">
                                            2. Select Property / Building <span style="color: #ef4444;">*</span>
                                        </label>
                                        <select id="res_building_id" class="form-control" onchange="fetchResidentFlats(this.value)" required>
                                            <option value="">Select Street First</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label style="font-size: 0.8rem; color: #64748b; font-weight: 600; display: block; margin-bottom: 0.35rem;">
                                            3. Select Flat / Unit <span style="color: #ef4444;">*</span>
                                        </label>
                                        <select name="flat_id" id="res_flat_id" required class="form-control">
                                            <option value="">Select Property First</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Resident Type</label>
                            <select name="type" id="res_type" class="form-control">
                                <option value="head">Head of Household</option>
                                <option value="dependent">Dependent</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Relationship</label>
                            <select name="relationship" id="res_relationship" class="form-control">
                                <option value="Self">Self (Primary)</option>
                                <?php 
                                if($relationships && $relationships->num_rows > 0):
                                    $relationships->data_seek(0);
                                    while($rel = $relationships->fetch_assoc()): ?>
                                        <option value="<?php echo htmlspecialchars($rel['name']); ?>"><?php echo htmlspecialchars($rel['name']); ?></option>
                                    <?php endwhile;
                                endif; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" id="res_status" class="form-control">
                                <option value="Active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Registration Date</label>
                            <input type="date" name="registration_date" id="res_reg_date" value="<?php echo date('Y-m-d'); ?>" class="form-control">
                        </div>
                    </div>
                    <div class="form-group" style="margin-top: 1rem;">
                        <label>Profile Image</label>
                        <input type="file" name="image" accept="image/*" class="form-control" onchange="previewImage(this, 'res_preview')">
                        <img id="res_preview" src="" style="display: none; width: 80px; height: 80px; object-fit: cover; border-radius: 50%; margin-top: 1rem; border: 2px solid var(--primary-color);">
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem; border-top: 1px solid #e2e8f0; padding-top: 1.5rem;">
                        <button type="button" class="btn" style="background: #f1f5f9; color: #475569;" onclick="closeModal('resident-modal')">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="res_submit_btn">Save Resident</button>
                    </div>
                </form>
            </div>
        </div>


<!-- Modal: vehicle-modal -->
        <!-- Add Vehicle Form -->
        <!-- Vehicle Modal -->
        <div id="vehicle-modal" class="custom-modal-backdrop">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="vehicle-modal-title">Add Vehicle</h2>
                    <button class="close-modal" onclick="closeModal('vehicle-modal')"><i class="fa-solid fa-times"></i></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="vehicle_id" id="vehicle_id">
                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
                        <div class="form-grid">
                            <div class="form-group" style="grid-column: span 2;">
                                <label>Address</label>
                                <select name="flat_id" id="veh_flat_id" required class="form-control">
                                    <?php foreach($all_flats as $f): ?>
                                        <option value="<?php echo $f['id']; ?>"><?php echo htmlspecialchars('Flat ' . $f['number'] . ' ' . $f['street_name'] . ' street ' . $f['building_name'] . ' building (Floor ' . $f['floor'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Reg Number</label>
                                <input type="text" name="reg_number" id="veh_reg" required class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Type</label>
                                <select name="type" id="veh_type" class="form-control">
                                    <option value="car">Car</option>
                                    <option value="motorcycle">Motorcycle</option>
                                    <option value="truck">Truck</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Model</label>
                                <input type="text" name="model" id="veh_model" placeholder="e.g. Toyota Camry" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Registration Date</label>
                                <input type="date" name="registration_date" id="veh_reg_date" value="<?php echo date('Y-m-d'); ?>" class="form-control">
                            </div>
                        </div>
                        <div class="form-group">
                             <label>Vehicle Image</label>
                             <input type="file" name="image" accept="image/*" onchange="previewImage(this, 'vehicle_preview')" class="form-control">
                             <img id="vehicle_preview" src="" style="display: none; width: 100%; height: auto; max-height: 200px; object-fit: contain; margin-top: 1rem; border-radius: 0.75rem;">
                        </div>
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem;">
                        <button type="button" class="btn" style="background: #f1f5f9;" onclick="closeModal('vehicle-modal')">Cancel</button>
                        <button type="submit" name="add_vehicle" class="btn btn-primary" id="veh_btn">Save Vehicle</button>
                    </div>
                </form>
            </div>
        </div>


<!-- Modal: pet-modal -->
        <div id="pet-modal" class="custom-modal-backdrop">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="pet-modal-title">Add Pet</h2>
                    <button class="close-modal" onclick="closeModal('pet-modal')"><i class="fa-solid fa-times"></i></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="pet_id" id="pet_id">
                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
                        <div class="form-grid">
                            <div class="form-group" style="grid-column: span 2;">
                                <label>Address</label>
                                <select name="flat_id" id="pet_flat_id" required class="form-control">
                                    <?php foreach($all_flats as $f): ?>
                                        <option value="<?php echo $f['id']; ?>"><?php echo htmlspecialchars('Flat ' . $f['number'] . ' ' . $f['street_name'] . ' street ' . $f['building_name'] . ' building (Floor ' . $f['floor'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Name</label>
                                <input type="text" name="name" id="pet_name" required class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Type</label>
                                <input type="text" name="type" id="pet_type" placeholder="Dog, Cat, etc." required class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Breed</label>
                                <input type="text" name="breed" id="pet_breed" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Registration Date</label>
                                <input type="date" name="registration_date" id="pet_reg_date" value="<?php echo date('Y-m-d'); ?>" class="form-control">
                            </div>
                        </div>
                        <div class="form-group">
                             <label>Pet Image</label>
                             <input type="file" name="image" accept="image/*" onchange="previewImage(this, 'pet_preview')" class="form-control">
                             <img id="pet_preview" src="" style="display: none; width: 100%; height: auto; max-height: 200px; object-fit: contain; margin-top: 1rem; border-radius: 0.75rem;">
                        </div>
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem;">
                        <button type="button" class="btn" style="background: #f1f5f9;" onclick="closeModal('pet-modal')">Cancel</button>
                        <button type="submit" name="add_pet" class="btn btn-primary" id="pet_btn">Save Pet</button>
                    </div>
                </form>
            </div>
        </div>


<!-- Modal: staff-modal -->
        <div id="staff-modal" class="custom-modal-backdrop">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="staff-modal-title">Add Domestic Staff</h2>
                    <button class="close-modal" onclick="closeModal('staff-modal')"><i class="fa-solid fa-times"></i></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="staff_id" id="staff_id">
                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
                        <div class="form-grid">
                            <div class="form-group" style="grid-column: span 2;">
                                <label>Address</label>
                                <select name="flat_id" id="stf_flat_id" required class="form-control">
                                    <?php foreach($all_flats as $f): ?>
                                        <option value="<?php echo $f['id']; ?>"><?php echo htmlspecialchars('Flat ' . $f['number'] . ' ' . $f['street_name'] . ' street ' . $f['building_name'] . ' building (Floor ' . $f['floor'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>First Name</label>
                                <input type="text" name="first_name" id="stf_first_name" required class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Last Name</label>
                                <input type="text" name="last_name" id="stf_last_name" required class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Role</label>
                                <select name="role" id="stf_role" required class="form-control">
                                    <?php $ds_roles->data_seek(0); while($r = $ds_roles->fetch_assoc()): ?>
                                        <option value="<?php echo htmlspecialchars($r['name']); ?>"><?php echo htmlspecialchars($r['name']); ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" id="stf_status" required class="form-control">
                                    <?php $ds_status->data_seek(0); while($s = $ds_status->fetch_assoc()): ?>
                                        <option value="<?php echo htmlspecialchars($s['name']); ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Phone</label>
                                <input type="text" name="phone" id="stf_phone" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Registration Date</label>
                                <input type="date" name="registration_date" id="stf_reg_date" value="<?php echo date('Y-m-d'); ?>" class="form-control">
                            </div>
                        </div>
                        <div class="form-group">
                             <label>Staff Image</label>
                             <input type="file" name="image" accept="image/*" onchange="previewImage(this, 'staff_preview')" class="form-control">
                             <img id="staff_preview" src="" style="display: none; width: 100%; height: auto; max-height: 200px; object-fit: contain; margin-top: 1rem; border-radius: 0.75rem;">
                        </div>
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem;">
                        <button type="button" class="btn" style="background: #f1f5f9;" onclick="closeModal('staff-modal')">Cancel</button>
                        <button type="submit" name="add_staff" class="btn btn-primary" id="stf_btn">Save Staff</button>
                    </div>
                </form>
            </div>
        </div>

<script>
// Tab Switching
function openTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName("tab-content");
    for (i = 0; i < tabcontent.length; i++) {
        tabcontent[i].style.display = "none";
    }
    tablinks = document.getElementsByClassName("tab-btn");
    for (i = 0; i < tablinks.length; i++) {
        tablinks[i].classList.remove("active");
        tablinks[i].style.color = "#64748b";
        tablinks[i].style.borderBottom = "2px solid transparent";
    }
    var target = document.getElementById(tabName);
    if (target) {
        target.style.display = "block";
    }
    if (evt && evt.currentTarget) {
        evt.currentTarget.classList.add("active");
        evt.currentTarget.style.color = "var(--primary-color)";
        evt.currentTarget.style.borderBottom = "2px solid var(--primary-color)";
    }
}

// Image Preview Helper
function previewImage(input, previewId) {
    var preview = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Generic Modal Helpers
function openModal(modalId) {
    const element = document.getElementById(modalId);
    if (!element) return;
    element.style.display = 'flex';
    
    const today = new Date().toISOString().split('T')[0];
    
    if (modalId === 'vehicle-modal' && !document.getElementById('vehicle_id').value) {
        document.getElementById('vehicle-modal-title').innerText = 'Add Vehicle';
        const btn = document.getElementById('veh_btn');
        if (btn) btn.innerText = 'Save Vehicle';
        const dateInput = document.getElementById('veh_reg_date');
        if (dateInput) dateInput.value = today;
    } else if (modalId === 'pet-modal' && !document.getElementById('pet_id').value) {
        document.getElementById('pet-modal-title').innerText = 'Add Pet';
        const btn = document.getElementById('pet_btn');
        if (btn) btn.innerText = 'Save Pet';
        const dateInput = document.getElementById('pet_reg_date');
        if (dateInput) dateInput.value = today;
    } else if (modalId === 'staff-modal' && !document.getElementById('staff_id').value) {
        document.getElementById('staff-modal-title').innerText = 'Add Domestic Staff';
        const btn = document.getElementById('stf_btn');
        if (btn) btn.innerText = 'Save Staff';
        const dateInput = document.getElementById('stf_reg_date');
        if (dateInput) dateInput.value = today;
    }
}

function closeModal(modalId) {
    const element = document.getElementById(modalId);
    if (element) {
        element.style.display = 'none';
    }
}

// Resident Modal
function openResidentModal() {
    openModal('resident-modal');
    document.getElementById('resident-modal-title').innerText = 'Register Resident';
    document.getElementById('res_resident_id').value = '';
    document.getElementById('res_first_name').value = '';
    document.getElementById('res_last_name').value = '';
    document.getElementById('res_email').value = '';
    document.getElementById('res_phone').value = '';
    document.getElementById('res_street_id').value = '';
    document.getElementById('res_building_id').innerHTML = '<option value="">Select Street First</option>';
    document.getElementById('res_flat_id').innerHTML = '<option value="">Select Property First</option>';
    document.getElementById('res_type').value = 'head';
    document.getElementById('res_relationship').value = 'Self';
    document.getElementById('res_status').value = 'Active';
    document.getElementById('res_reg_date').value = '<?php echo date('Y-m-d'); ?>';
    const preview = document.getElementById('res_preview');
    if (preview) {
        preview.style.display = 'none';
        preview.src = '';
    }
    const btn = document.getElementById('res_submit_btn');
    if (btn) btn.innerText = 'Save Resident';
}

function fetchResidentBuildings(streetId, selectedBuildingId = null, callback = null) {
    const buildingSelect = document.getElementById('res_building_id');
    const flatSelect = document.getElementById('res_flat_id');
    
    buildingSelect.innerHTML = '<option value="">Loading...</option>';
    flatSelect.innerHTML = '<option value="">Select Property First</option>';

    if (!streetId) {
        buildingSelect.innerHTML = '<option value="">Select Street First</option>';
        return;
    }

    fetch(`../api/get_buildings?street_id=${streetId}`)
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                let html = '<option value="">Select Property / Building</option>';
                res.data.forEach(b => {
                    html += `<option value="${b.id}" ${selectedBuildingId == b.id ? 'selected' : ''}>${b.name} (${b.property_number})</option>`;
                });
                buildingSelect.innerHTML = html;
                if (callback) callback();
            } else {
                buildingSelect.innerHTML = '<option value="">No buildings found</option>';
            }
        })
        .catch(err => {
            buildingSelect.innerHTML = '<option value="">Error loading buildings</option>';
        });
}

function fetchResidentFlats(buildingId, selectedFlatId = null) {
    const flatSelect = document.getElementById('res_flat_id');
    flatSelect.innerHTML = '<option value="">Loading...</option>';

    if (!buildingId) {
        flatSelect.innerHTML = '<option value="">Select Property First</option>';
        return;
    }

    fetch(`../api/get_flats?building_id=${buildingId}`)
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                let html = '<option value="">Select Flat / Unit</option>';
                res.data.forEach(f => {
                    html += `<option value="${f.id}" ${selectedFlatId == f.id ? 'selected' : ''}>Flat ${f.number} (Floor ${f.floor}) - ${f.status || 'Active'}</option>`;
                });
                flatSelect.innerHTML = html;
            } else {
                flatSelect.innerHTML = '<option value="">No flats found</option>';
            }
        })
        .catch(err => {
            flatSelect.innerHTML = '<option value="">Error loading flats</option>';
        });
}

function editResident(data) {
    openModal('resident-modal');
    document.getElementById('resident-modal-title').innerText = 'Update Resident';
    document.getElementById('res_resident_id').value = data.id;
    document.getElementById('res_first_name').value = data.first_name || '';
    document.getElementById('res_last_name').value = data.last_name || '';
    document.getElementById('res_email').value = data.email || '';
    document.getElementById('res_phone').value = data.phone || '';
    document.getElementById('res_type').value = data.type || 'head';
    document.getElementById('res_relationship').value = data.relationship || 'Self';
    document.getElementById('res_status').value = data.status || 'Active';
    document.getElementById('res_reg_date').value = data.registration_date || '<?php echo date('Y-m-d'); ?>';
    
    // Pre-fill Street -> Building -> Flat hierarchy
    const streetSelect = document.getElementById('res_street_id');
    if (data.street_id) {
        streetSelect.value = data.street_id;
        fetchResidentBuildings(data.street_id, data.building_id, function() {
            if (data.building_id) {
                fetchResidentFlats(data.building_id, data.flat_id);
            }
        });
    } else {
        streetSelect.value = '';
        document.getElementById('res_building_id').innerHTML = '<option value="">Select Street First</option>';
        document.getElementById('res_flat_id').innerHTML = '<option value="">Select Property First</option>';
    }

    const btn = document.getElementById('res_submit_btn');
    if (btn) btn.innerText = 'Update Resident';
    const preview = document.getElementById('res_preview');
    if (preview) {
        if (data.image_path) {
            preview.src = data.image_path;
            preview.style.display = 'block';
        } else {
            preview.style.display = 'none';
            preview.src = '';
        }
    }
}

// Vehicle Modal
function editVehicle(data) {
    openModal('vehicle-modal');
    document.getElementById('vehicle-modal-title').innerText = 'Update Vehicle';
    document.getElementById('vehicle_id').value = data.id;
    document.getElementById('veh_flat_id').value = data.flat_id;
    document.getElementById('veh_reg').value = data.reg_number;
    document.getElementById('veh_type').value = data.type;
    document.getElementById('veh_model').value = data.model;
    document.getElementById('veh_reg_date').value = data.registration_date || '<?php echo date('Y-m-d'); ?>';
    document.getElementById('veh_btn').innerText = 'Update Vehicle';
    const preview = document.getElementById('vehicle_preview');
    if (preview) {
        if (data.image_path) {
            preview.src = data.image_path;
            preview.style.display = 'block';
        } else {
            preview.style.display = 'none';
            preview.src = '';
        }
    }
}

// Pet Modal
function editPet(data) {
    openModal('pet-modal');
    document.getElementById('pet-modal-title').innerText = 'Update Pet';
    document.getElementById('pet_id').value = data.id;
    document.getElementById('pet_flat_id').value = data.flat_id;
    document.getElementById('pet_name').value = data.name;
    document.getElementById('pet_type').value = data.type;
    document.getElementById('pet_breed').value = data.breed;
    document.getElementById('pet_reg_date').value = data.registration_date || '<?php echo date('Y-m-d'); ?>';
    document.getElementById('pet_btn').innerText = 'Update Pet';
    const preview = document.getElementById('pet_preview');
    if (preview) {
        if (data.image_path) {
            preview.src = data.image_path;
            preview.style.display = 'block';
        } else {
            preview.style.display = 'none';
            preview.src = '';
        }
    }
}

// Staff Modal
function editStaff(data) {
    openModal('staff-modal');
    document.getElementById('staff-modal-title').innerText = 'Update Domestic Staff';
    document.getElementById('staff_id').value = data.id;
    document.getElementById('stf_flat_id').value = data.flat_id;
    document.getElementById('stf_first_name').value = data.first_name;
    document.getElementById('stf_last_name').value = data.last_name;
    document.getElementById('stf_role').value = data.role;
    document.getElementById('stf_status').value = data.status;
    document.getElementById('stf_phone').value = data.phone;
    document.getElementById('stf_reg_date').value = data.registration_date || '<?php echo date('Y-m-d'); ?>';
    document.getElementById('stf_btn').innerText = 'Update Staff';
    const preview = document.getElementById('staff_preview');
    if (preview) {
        if (data.image_path) {
            preview.src = data.image_path;
            preview.style.display = 'block';
        } else {
            preview.style.display = 'none';
            preview.src = '';
        }
    }
}

// Close modal when clicking on backdrop
window.addEventListener('click', function(e) {
    if (e.target.classList && e.target.classList.contains('custom-modal-backdrop')) {
        e.target.style.display = 'none';
    }
});

// Close modal on Escape key
window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.custom-modal-backdrop').forEach(m => m.style.display = 'none');
    }
});
</script>

<?php include '../includes/footer.php'; ?>
