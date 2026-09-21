<?php
// zone/residents.php - Zonal Resident Management & Multi-Entity Registry (Strictly Scoped to Zone)
require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/Mailer.php';

requireZoneAccess();

$zone_id = get_current_zone_id();
$estate_id = get_estate_id();
$message = $flash_message ?? ($flash_error ?? "");
$message_type = !empty($flash_error) ? "error" : "success";

// Helper to handle uploads
function handleUpload($file) {
    global $conn;
    if (isset($file['error']) && $file['error'] == 0) {
        $target_dir = "../uploads/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $target_file = $target_dir . time() . "_" . preg_replace("/[^a-zA-Z0-9._-]/", "", basename($file["name"]));
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

// -------------------------------------------------------------
// POST ACTIONS: MULTI-ENTITY RESIDENT, VEHICLE, PET, STAFF
// -------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // 1. ADD / UPDATE RESIDENT
        if (isset($_POST['add_resident'])) {
            $first_name = trim($conn->real_escape_string($_POST['first_name'] ?? ''));
            $last_name = trim($conn->real_escape_string($_POST['last_name'] ?? ''));
            $name = trim($first_name . ' ' . $last_name);
            $email = trim($conn->real_escape_string($_POST['email'] ?? ''));
            $phone = trim($conn->real_escape_string($_POST['phone'] ?? ''));
            $flat_id = intval($_POST['flat_id'] ?? 0);
            $type = in_array($_POST['type'] ?? '', ['head', 'dependent']) ? $_POST['type'] : 'head';
            $relationship = $conn->real_escape_string($_POST['relationship'] ?? 'Self');
            $reg_date = $conn->real_escape_string($_POST['registration_date'] ?? date('Y-m-d'));
            $status = in_array($_POST['status'] ?? '', ['active', 'inactive', 'Active']) ? $_POST['status'] : 'active';
            
            // Validate flat belongs strictly to this zone
            $chk_flat = $conn->query("
                SELECT f.id, b.id as b_id, s.id as s_id 
                FROM flats f 
                JOIN buildings b ON f.building_id = b.id 
                JOIN streets s ON b.street_id = s.id 
                WHERE f.id = $flat_id AND s.zone_id = $zone_id AND f.estate_id = $estate_id 
                LIMIT 1
            ");

            if (!$chk_flat || $chk_flat->num_rows == 0) {
                throw new Exception("Selected property unit does not belong to your assigned zone.");
            } else {
                if (!empty($_POST['resident_id'])) {
                    // Update Resident
                    $res_id = intval($_POST['resident_id']);
                    $get_res = $conn->query("
                        SELECT r.*, s.zone_id 
                        FROM residents r 
                        JOIN flats f ON r.flat_id = f.id 
                        JOIN buildings b ON f.building_id = b.id 
                        JOIN streets s ON b.street_id = s.id 
                        WHERE r.id = $res_id AND s.zone_id = $zone_id AND r.estate_id = $estate_id 
                        LIMIT 1
                    ");
                    if ($get_res && $get_res->num_rows > 0) {
                        $old_res = $get_res->fetch_assoc();
                        $uid = intval($old_res['user_id']);

                        // DUPLICATE BLOCKER FOR UPDATES: Check email & phone uniqueness
                        if (!empty($email) && function_exists('isEmailTakenInEstate') && ($taken = isEmailTakenInEstate($conn, $email, $estate_id, $uid))) {
                            throw new Exception("Duplicate Blocker: Email '$email' is already registered in the estate to {$taken['name']}. Duplicate emails are strictly prohibited.");
                        }
                        if (!empty($phone) && function_exists('isPhoneTakenInEstate') && ($taken = isPhoneTakenInEstate($conn, $phone, $estate_id, $uid))) {
                            throw new Exception("Duplicate Blocker: Phone number '$phone' is already registered in the estate to {$taken['name']}. Duplicate phone numbers are strictly prohibited.");
                        }

                        $conn->query("UPDATE users SET first_name='$first_name', last_name='$last_name', name='$name', email='$email', phone='$phone' WHERE id=$uid");
                        
                        $updates = "flat_id=$flat_id, type='$type', relationship='$relationship', status='$status', registration_date='$reg_date'";
                        if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
                            $image = handleUpload($_FILES['image']);
                            if ($image) $updates .= ", image_path='$image'";
                        }
                        
                        if ($conn->query("UPDATE residents SET $updates WHERE id=$res_id AND estate_id=$estate_id")) {
                            $message = "Resident profile updated successfully!";
                            if ($old_res['flat_id'] != $flat_id) {
                                logResidentHistory($conn, $res_id, $old_res['flat_id'], 'Moved Out', "Transferred to another unit in zone");
                                logResidentHistory($conn, $res_id, $flat_id, 'Moved In', "Transferred from another unit in zone");
                            }
                        } else {
                            throw new Exception("Error updating resident: " . $conn->error);
                        }
                    } else {
                        throw new Exception("Unauthorized resident record.");
                    }
                } else {
                    // Register New Resident
                    // DUPLICATE BLOCKER: Ensure neither email nor phone is already registered in the estate
                    if (!empty($email) && function_exists('isEmailTakenInEstate') && ($taken = isEmailTakenInEstate($conn, $email, $estate_id))) {
                        throw new Exception("Duplicate Blocker: Email '$email' is already registered in this estate to {$taken['name']} (ID: " . ($taken['res_id'] ?? 'User #' . $taken['id']) . "). Two residents cannot share the same email.");
                    }
                    if (!empty($phone) && function_exists('isPhoneTakenInEstate') && ($taken = isPhoneTakenInEstate($conn, $phone, $estate_id))) {
                        throw new Exception("Duplicate Blocker: Phone number '$phone' is already registered in this estate to {$taken['name']} (ID: " . ($taken['res_id'] ?? 'User #' . $taken['id']) . "). Two residents cannot share the same phone number.");
                    }

                    $default_pass = !empty($first_name) ? trim($first_name) : 'welcome123';
                    $password = password_hash($default_pass, PASSWORD_DEFAULT);
                    $conn->query("INSERT INTO users (estate_id, first_name, last_name, name, email, phone, password, role) 
                                  VALUES ($estate_id, '$first_name', '$last_name', '$name', '$email', '$phone', '$password', 'resident')");
                    $user_id = $conn->insert_id;
                    
                    // Dispatch Welcome Email with Credentials to New Resident
                    if (class_exists('EstateMailer')) {
                        EstateMailer::sendWelcomeCredentialsEmail($conn, $user_id, $default_pass);
                    }
                    
                    $custom_id = generateCustomID($conn, 'residents', 'RES');
                    $image = isset($_FILES['image']) ? handleUpload($_FILES['image']) : null;
                    $sql = "INSERT INTO residents (estate_id, custom_id, user_id, flat_id, type, relationship, status, image_path, registration_date) 
                            VALUES ($estate_id, '$custom_id', $user_id, $flat_id, '$type', '$relationship', '$status', " . ($image ? "'$image'" : "NULL") . ", '$reg_date')";
                    
                    if ($conn->query($sql)) {
                        $res_id = $conn->insert_id;
                        $conn->query("UPDATE flats SET status='occupied' WHERE id=$flat_id");
                        $message = "Resident '$name' onboarded to your zone successfully!";
                        logResidentHistory($conn, $res_id, $flat_id, 'Moved In', "Initial zonal onboarding");
                        
                        // Tenancies table tracking
                        $conn->query("INSERT INTO tenancies (estate_id, resident_id, flat_id, move_in_date, status) 
                                      VALUES ($estate_id, $user_id, $flat_id, '$reg_date', 'Active')");

                        // Handle Multi-entity Family Members & Staff
                        $batch_emails = [$email];
                        $batch_phones = [preg_replace('/[^0-9]/', '', $phone)];

                        if (isset($_POST['family_first_name']) && is_array($_POST['family_first_name'])) {
                            foreach ($_POST['family_first_name'] as $i => $fname) {
                                if (empty($fname)) continue;
                                $fname = $conn->real_escape_string(trim($fname));
                                $lname = $conn->real_escape_string(trim($_POST['family_last_name'][$i] ?? ''));
                                $fname_full = trim($fname . ' ' . $lname);
                                $category = $_POST['family_category'][$i] ?? 'dependent';
                                $role_rel = $conn->real_escape_string($_POST['family_role'][$i] ?? 'Dependent');
                                $f_email = trim($_POST['family_email'][$i] ?? '');
                                $f_phone = trim($_POST['family_phone'][$i] ?? '');
                                
                                $image_path = null;
                                if (isset($_FILES['family_image']['name'][$i]) && $_FILES['family_image']['error'][$i] == 0) {
                                    $f_file = ['name'=>$_FILES['family_image']['name'][$i], 'type'=>$_FILES['family_image']['type'][$i], 'tmp_name'=>$_FILES['family_image']['tmp_name'][$i], 'error'=>$_FILES['family_image']['error'][$i], 'size'=>$_FILES['family_image']['size'][$i]];
                                    $image_path = handleUpload($f_file);
                                }

                                if ($category === 'staff') {
                                    if (!empty($f_phone)) {
                                        $cl_f_phone = preg_replace('/[^0-9]/', '', $f_phone);
                                        if (in_array($cl_f_phone, $batch_phones)) {
                                            throw new Exception("Duplicate Blocker: Phone '$f_phone' is repeated in this registration batch.");
                                        }
                                        if (function_exists('isPhoneTakenInEstate') && ($taken = isPhoneTakenInEstate($conn, $f_phone, $estate_id))) {
                                            throw new Exception("Duplicate Blocker: Domestic staff phone '$f_phone' is already registered to {$taken['name']}.");
                                        }
                                        $batch_phones[] = $cl_f_phone;
                                    }
                                    $s_custom_id = generateCustomID($conn, 'household_staff', 'STF');
                                    $conn->query("INSERT INTO household_staff (estate_id, custom_id, flat_id, first_name, last_name, name, role, phone, status, image_path, registration_date) 
                                                  VALUES ($estate_id, '$s_custom_id', $flat_id, '$fname', '$lname', '$fname_full', '$role_rel', '$f_phone', 'Daily', " . ($image_path ? "'$image_path'" : "NULL") . ", '$reg_date')");
                                } else {
                                    if (!empty($f_email)) {
                                        $cl_f_email = strtolower($f_email);
                                        if (in_array($cl_f_email, $batch_emails)) {
                                            throw new Exception("Duplicate Blocker: Email '$f_email' is repeated in this registration batch.");
                                        }
                                        if (function_exists('isEmailTakenInEstate') && ($taken = isEmailTakenInEstate($conn, $f_email, $estate_id))) {
                                            throw new Exception("Duplicate Blocker: Dependent email '$f_email' is already registered to {$taken['name']}.");
                                        }
                                        $batch_emails[] = $cl_f_email;
                                        $d_email = $conn->real_escape_string($f_email);
                                    } else {
                                        $d_email = "dep_" . time() . "_" . $i . "@estate.com";
                                    }

                                    if (!empty($f_phone)) {
                                        $cl_f_phone = preg_replace('/[^0-9]/', '', $f_phone);
                                        if (in_array($cl_f_phone, $batch_phones)) {
                                            throw new Exception("Duplicate Blocker: Dependent phone '$f_phone' is repeated in this registration batch.");
                                        }
                                        if (function_exists('isPhoneTakenInEstate') && ($taken = isPhoneTakenInEstate($conn, $f_phone, $estate_id))) {
                                            throw new Exception("Duplicate Blocker: Dependent phone '$f_phone' is already registered to {$taken['name']}.");
                                        }
                                        $batch_phones[] = $cl_f_phone;
                                    }

                                    $d_pass = !empty($fname) ? trim($fname) : 'welcome123';
                                    $d_password = password_hash($d_pass, PASSWORD_DEFAULT);
                                    $conn->query("INSERT INTO users (estate_id, first_name, last_name, name, email, phone, password, role) 
                                                  VALUES ($estate_id, '$fname', '$lname', '$fname_full', '$d_email', '$f_phone', '$d_password', 'resident')");
                                    $d_uid = $conn->insert_id;

                                    $d_custom_id = generateCustomID($conn, 'residents', 'RES');
                                    $conn->query("INSERT INTO residents (estate_id, custom_id, user_id, flat_id, type, relationship, status, image_path, registration_date) 
                                                  VALUES ($estate_id, '$d_custom_id', $d_uid, $flat_id, 'dependent', '$role_rel', '$status', " . ($image_path ? "'$image_path'" : "NULL") . ", '$reg_date')");
                                }
                            }
                        }

                        // Handle Multi-entity Vehicles
                        if (isset($_POST['vehicle_reg']) && is_array($_POST['vehicle_reg'])) {
                            foreach ($_POST['vehicle_reg'] as $i => $reg) {
                                if (empty($reg)) continue;
                                $reg = strtoupper($conn->real_escape_string(trim($reg)));
                                $v_type = $conn->real_escape_string($_POST['vehicle_type'][$i] ?? 'car');
                                $v_model = $conn->real_escape_string(trim($_POST['vehicle_model'][$i] ?? ''));
                                $v_image = null;
                                if (isset($_FILES['vehicle_image']['name'][$i]) && $_FILES['vehicle_image']['error'][$i] == 0) {
                                    $v_file = ['name'=>$_FILES['vehicle_image']['name'][$i], 'type'=>$_FILES['vehicle_image']['type'][$i], 'tmp_name'=>$_FILES['vehicle_image']['tmp_name'][$i], 'error'=>$_FILES['vehicle_image']['error'][$i], 'size'=>$_FILES['vehicle_image']['size'][$i]];
                                    $v_image = handleUpload($v_file);
                                }
                                $v_particulars = null;
                                if (isset($_FILES['vehicle_particulars']['name'][$i]) && $_FILES['vehicle_particulars']['error'][$i] == 0) {
                                    $v_p_file = ['name'=>$_FILES['vehicle_particulars']['name'][$i], 'type'=>$_FILES['vehicle_particulars']['type'][$i], 'tmp_name'=>$_FILES['vehicle_particulars']['tmp_name'][$i], 'error'=>$_FILES['vehicle_particulars']['error'][$i], 'size'=>$_FILES['vehicle_particulars']['size'][$i]];
                                    $v_particulars = handleUpload($v_p_file);
                                }
                                $v_custom_id = generateCustomID($conn, 'vehicles', 'VEH');
                                $conn->query("INSERT INTO vehicles (estate_id, custom_id, flat_id, type, reg_number, model, image_path, particulars_path, registration_date) 
                                              VALUES ($estate_id, '$v_custom_id', $flat_id, '$type', '$reg', '$model', " . ($v_image ? "'$v_image'" : "NULL") . ", " . ($v_particulars ? "'$v_particulars'" : "NULL") . ", '$reg_date')");
                            }
                        }

                        // Handle Multi-entity Pets
                        if (isset($_POST['pet_name']) && is_array($_POST['pet_name'])) {
                            foreach ($_POST['pet_name'] as $i => $p_name) {
                                if (empty($p_name)) continue;
                                $p_name = $conn->real_escape_string(trim($p_name));
                                $p_type = $conn->real_escape_string(trim($_POST['pet_type'][$i] ?? 'Dog'));
                                $p_breed = $conn->real_escape_string(trim($_POST['pet_breed'][$i] ?? ''));
                                $p_image = null;
                                if (isset($_FILES['pet_image']['name'][$i]) && $_FILES['pet_image']['error'][$i] == 0) {
                                    $p_file = ['name'=>$_FILES['pet_image']['name'][$i], 'type'=>$_FILES['pet_image']['type'][$i], 'tmp_name'=>$_FILES['pet_image']['tmp_name'][$i], 'error'=>$_FILES['pet_image']['error'][$i], 'size'=>$_FILES['pet_image']['size'][$i]];
                                    $p_image = handleUpload($p_file);
                                }
                                $p_custom_id = generateCustomID($conn, 'pets', 'PET');
                                $conn->query("INSERT INTO pets (estate_id, custom_id, flat_id, name, type, breed, image_path, registration_date) 
                                              VALUES ($estate_id, '$p_custom_id', $flat_id, '$p_name', '$p_type', '$p_breed', " . ($p_image ? "'$p_image'" : "NULL") . ", '$reg_date')");
                            }
                        }
                    } else {
                        throw new Exception("Database Error: " . $conn->error);
                    }
                }
            }
        } 
        
        // 2. ARCHIVE RESIDENT
        elseif (isset($_POST['archive_resident'])) {
            $id = intval($_POST['archive_id']);
            $reason = !empty($_POST['archive_reason']) ? $conn->real_escape_string(trim($_POST['archive_reason'])) : 'Departed / Moved out of zone';
            $vacate_flat = isset($_POST['vacate_flat']) ? intval($_POST['vacate_flat']) : 1;

            $chk = $conn->query("
                SELECT r.id, r.user_id, r.flat_id 
                FROM residents r 
                JOIN flats f ON r.flat_id = f.id 
                JOIN buildings b ON f.building_id = b.id 
                JOIN streets s ON b.street_id = s.id 
                WHERE r.id = $id AND s.zone_id = $zone_id AND r.estate_id = $estate_id 
                LIMIT 1
            ");
            if ($chk && $chk->num_rows > 0) {
                $r_data = $chk->fetch_assoc();
                $flat_id = intval($r_data['flat_id']);
                $u_id = intval($r_data['user_id']);

                $conn->query("UPDATE residents SET status='archived' WHERE id=$id");
                if ($vacate_flat && $flat_id > 0) {
                    $remain = $conn->query("SELECT id FROM residents WHERE flat_id=$flat_id AND status='active' AND id != $id AND estate_id=$estate_id");
                    if (!$remain || $remain->num_rows == 0) {
                        $conn->query("UPDATE flats SET status='vacant' WHERE id=$flat_id AND estate_id=$estate_id");
                    }
                }
                $conn->query("UPDATE tenancies SET status='Terminated', move_out_date=CURDATE() WHERE flat_id=$flat_id AND resident_id=$u_id AND (status='Active' OR status='active')");
                logResidentHistory($conn, $id, $flat_id, 'Moved Out', $reason);
                $message = "Resident archived successfully and moved to Zonal Archives Vault!";
            } else {
                throw new Exception("Unauthorized archive request.");
            }
        }
        elseif (isset($_POST['approve_contact_change'])) {
            $req_id = intval($_POST['request_id']);
            $admin_uid = intval($_SESSION['user_id'] ?? 0);
            $admin_notes = $conn->real_escape_string(trim($_POST['admin_notes'] ?? 'Approved by Zone Administrator'));
            
            $req = $conn->query("SELECT * FROM contact_change_requests WHERE id=$req_id AND estate_id=$estate_id AND (zone_id=$zone_id OR zone_id IS NULL) AND status='pending'")->fetch_assoc();
            if ($req) {
                $t_uid = intval($req['user_id']);
                $new_e = trim($req['requested_email'] ?? '');
                $new_p = trim($req['requested_phone'] ?? '');
                
                $u_updates = [];
                if (!empty($new_e)) {
                    if (function_exists('isEmailTakenInEstate') && ($taken = isEmailTakenInEstate($conn, $new_e, $estate_id, $t_uid))) {
                        throw new Exception("Cannot Approve: Email '$new_e' is already registered in the estate.");
                    }
                    $esc_e = $conn->real_escape_string($new_e);
                    $u_updates[] = "email='$esc_e'";
                }
                if (!empty($new_p)) {
                    if (function_exists('isPhoneTakenInEstate') && ($taken = isPhoneTakenInEstate($conn, $new_p, $estate_id, $t_uid))) {
                        throw new Exception("Cannot Approve: Phone number '$new_p' is already registered in the estate.");
                    }
                    $esc_p = $conn->real_escape_string($new_p);
                    $u_updates[] = "phone='$esc_p'";
                }
                
                if (!empty($u_updates)) {
                    $conn->query("UPDATE users SET " . implode(', ', $u_updates) . " WHERE id=$t_uid AND estate_id=$estate_id");
                }
                
                $conn->query("UPDATE contact_change_requests SET status='approved', reviewed_by=$admin_uid, reviewed_at=NOW(), admin_notes='$admin_notes' WHERE id=$req_id");
                
                // Notify Resident
                $notif_txt = $conn->real_escape_string("Your contact info update request has been APPROVED by your Zone Administration.");
                $conn->query("INSERT INTO notifications (estate_id, user_id, title, message, type, is_read, created_at) VALUES ($estate_id, $t_uid, 'Contact Details Updated', '$notif_txt', 'contact_approved', 0, NOW())");
                
                $message = "Resident contact details updated and request approved!";
            } else {
                throw new Exception("Pending request not found.");
            }
        }
        elseif (isset($_POST['reject_contact_change'])) {
            $req_id = intval($_POST['request_id']);
            $admin_uid = intval($_SESSION['user_id'] ?? 0);
            $admin_notes = $conn->real_escape_string(trim($_POST['admin_notes'] ?? 'Declined by Zone Admin'));
            
            $req = $conn->query("SELECT * FROM contact_change_requests WHERE id=$req_id AND estate_id=$estate_id AND (zone_id=$zone_id OR zone_id IS NULL) AND status='pending'")->fetch_assoc();
            if ($req) {
                $t_uid = intval($req['user_id']);
                $conn->query("UPDATE contact_change_requests SET status='rejected', reviewed_by=$admin_uid, reviewed_at=NOW(), admin_notes='$admin_notes' WHERE id=$req_id");
                
                // Notify Resident
                $notif_txt = $conn->real_escape_string("Your contact change request was declined by Zone Admin: $admin_notes");
                $conn->query("INSERT INTO notifications (estate_id, user_id, title, message, type, is_read, created_at) VALUES ($estate_id, $t_uid, 'Contact Change Request Declined', '$notif_txt', 'contact_rejected', 0, NOW())");
                
                $message = "Contact change request declined.";
            } else {
                throw new Exception("Pending request not found.");
            }
        }
        elseif (isset($_POST['delete_resident'])) {
            $id = intval($_POST['archive_id']);
            $chk = $conn->query("
                SELECT r.id, r.user_id, r.flat_id 
                FROM residents r 
                JOIN flats f ON r.flat_id = f.id 
                JOIN buildings b ON f.building_id = b.id 
                JOIN streets s ON b.street_id = s.id 
                WHERE r.id = $id AND s.zone_id = $zone_id AND r.estate_id = $estate_id 
                LIMIT 1
            ");
            if ($chk && $chk->num_rows > 0) {
                $r_data = $chk->fetch_assoc();
                $conn->query("DELETE FROM resident_history WHERE resident_id=$id");
                if (!empty($r_data['user_id'])) {
                    $conn->query("DELETE FROM tenancies WHERE resident_id=" . intval($r_data['user_id']) . " AND flat_id=" . intval($r_data['flat_id']));
                }
                if ($conn->query("DELETE FROM residents WHERE id=$id")) {
                    $message = "Resident deleted successfully!";
                } else {
                    throw new Exception("Error deleting resident: " . $conn->error);
                }
            }
        }

        // 3. VEHICLE CRUD
        elseif (isset($_POST['add_vehicle'])) {
            $flat_id = intval($_POST['flat_id']);
            $reg_number = strtoupper(trim($conn->real_escape_string($_POST['reg_number'] ?? '')));
            $type = $conn->real_escape_string($_POST['type'] ?? 'car');
            $model = trim($conn->real_escape_string($_POST['model'] ?? ''));
            $reg_date = $conn->real_escape_string($_POST['registration_date'] ?? date('Y-m-d'));
            
            // Validate flat belongs to zone
            $chk_flat = $conn->query("SELECT f.id FROM flats f JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE f.id = $flat_id AND s.zone_id = $zone_id LIMIT 1");
            if ($chk_flat && $chk_flat->num_rows > 0) {
                if (!empty($_POST['vehicle_id'])) {
                    $vid = intval($_POST['vehicle_id']);
                    $color = $conn->real_escape_string(trim($_POST['color'] ?? 'Unspecified'));
                    $u_sql = "UPDATE vehicles SET flat_id=$flat_id, type='$type', reg_number='$reg_number', model='$model', color='$color', registration_date='$reg_date'";
                    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
                        $img = handleUpload($_FILES['image']);
                        if ($img) $u_sql .= ", image_path='$img'";
                    }
                    if (isset($_FILES['particulars']) && $_FILES['particulars']['error'] == 0) {
                        $part = handleUpload($_FILES['particulars']);
                        if ($part) $u_sql .= ", particulars_path='$part'";
                    }
                    $u_sql .= " WHERE id=$vid AND estate_id=$estate_id";
                    if ($conn->query($u_sql)) $message = "Vehicle details updated successfully!";
                } else {
                    $v_custom_id = generateCustomID($conn, 'vehicles', 'VEH');
                    $color = $conn->real_escape_string(trim($_POST['color'] ?? 'Unspecified'));
                    $img = (isset($_FILES['image']) && $_FILES['image']['error'] == 0) ? handleUpload($_FILES['image']) : null;
                    $part = (isset($_FILES['particulars']) && $_FILES['particulars']['error'] == 0) ? handleUpload($_FILES['particulars']) : null;
                    $i_sql = "INSERT INTO vehicles (estate_id, custom_id, flat_id, type, reg_number, model, color, image_path, particulars_path, registration_date, sticker_status) 
                              VALUES ($estate_id, '$v_custom_id', $flat_id, '$type', '$reg_number', '$model', '$color', " . ($img ? "'$img'" : "NULL") . ", " . ($part ? "'$part'" : "NULL") . ", '$reg_date', 'active')";
                    if ($conn->query($i_sql)) $message = "Vehicle registered successfully!";
                }
            } else {
                $message = "Error: Invalid property unit for this zone.";
                $message_type = "error";
            }
        } elseif (isset($_POST['archive_vehicle'])) {
            $vid = intval($_POST['archive_id']);
            $chk = $conn->query("SELECT v.id FROM vehicles v JOIN flats f ON v.flat_id = f.id JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE v.id = $vid AND s.zone_id = $zone_id LIMIT 1");
            if ($chk && $chk->num_rows > 0) {
                $conn->query("DELETE FROM vehicles WHERE id=$vid AND estate_id=$estate_id");
                $message = "Vehicle record removed successfully.";
            }
        }

        // 4. PET CRUD
        elseif (isset($_POST['add_pet'])) {
            $flat_id = intval($_POST['flat_id']);
            $name = trim($conn->real_escape_string($_POST['name'] ?? ''));
            $type = trim($conn->real_escape_string($_POST['type'] ?? 'Dog'));
            $breed = trim($conn->real_escape_string($_POST['breed'] ?? ''));
            $reg_date = $conn->real_escape_string($_POST['registration_date'] ?? date('Y-m-d'));

            $chk_flat = $conn->query("SELECT f.id FROM flats f JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE f.id = $flat_id AND s.zone_id = $zone_id LIMIT 1");
            if ($chk_flat && $chk_flat->num_rows > 0) {
                if (!empty($_POST['pet_id'])) {
                    $pid = intval($_POST['pet_id']);
                    $u_sql = "UPDATE pets SET flat_id=$flat_id, name='$name', type='$type', breed='$breed', registration_date='$reg_date'";
                    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
                        $img = handleUpload($_FILES['image']);
                        if ($img) $u_sql .= ", image_path='$img'";
                    }
                    $u_sql .= " WHERE id=$pid AND estate_id=$estate_id";
                    if ($conn->query($u_sql)) $message = "Pet record updated successfully!";
                } else {
                    $p_custom_id = generateCustomID($conn, 'pets', 'PET');
                    $img = isset($_FILES['image']) ? handleUpload($_FILES['image']) : null;
                    $i_sql = "INSERT INTO pets (estate_id, custom_id, flat_id, name, type, breed, image_path, registration_date) 
                              VALUES ($estate_id, '$p_custom_id', $flat_id, '$name', '$type', '$breed', " . ($img ? "'$img'" : "NULL") . ", '$reg_date')";
                    if ($conn->query($i_sql)) $message = "Pet registered successfully!";
                }
            } else {
                $message = "Error: Invalid property unit for this zone.";
                $message_type = "error";
            }
        } elseif (isset($_POST['archive_pet'])) {
            $pid = intval($_POST['archive_id']);
            $chk = $conn->query("SELECT p.id FROM pets p JOIN flats f ON p.flat_id = f.id JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE p.id = $pid AND s.zone_id = $zone_id LIMIT 1");
            if ($chk && $chk->num_rows > 0) {
                $conn->query("DELETE FROM pets WHERE id=$pid AND estate_id=$estate_id");
                $message = "Pet record removed successfully.";
            }
        }

        // 5. DOMESTIC STAFF CRUD
        elseif (isset($_POST['add_staff'])) {
            $flat_id = intval($_POST['flat_id']);
            $first_name = trim($conn->real_escape_string($_POST['first_name'] ?? ''));
            $last_name = trim($conn->real_escape_string($_POST['last_name'] ?? ''));
            $name = trim($first_name . ' ' . $last_name);
            $role = trim($conn->real_escape_string($_POST['role'] ?? ''));
            $status = trim($conn->real_escape_string($_POST['status'] ?? 'Daily'));
            $phone = trim($conn->real_escape_string($_POST['phone'] ?? ''));
            $reg_date = $conn->real_escape_string($_POST['registration_date'] ?? date('Y-m-d'));

            $chk_flat = $conn->query("SELECT f.id FROM flats f JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE f.id = $flat_id AND s.zone_id = $zone_id LIMIT 1");
            if ($chk_flat && $chk_flat->num_rows > 0) {
                if (!empty($_POST['staff_id'])) {
                    $sid = intval($_POST['staff_id']);
                    $u_sql = "UPDATE household_staff SET flat_id=$flat_id, first_name='$first_name', last_name='$last_name', name='$name', role='$role', status='$status', phone='$phone', registration_date='$reg_date'";
                    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
                        $img = handleUpload($_FILES['image']);
                        if ($img) $u_sql .= ", image_path='$img'";
                    }
                    $u_sql .= " WHERE id=$sid AND estate_id=$estate_id";
                    if ($conn->query($u_sql)) $message = "Domestic staff record updated successfully!";
                } else {
                    $s_custom_id = generateCustomID($conn, 'household_staff', 'STF');
                    $img = isset($_FILES['image']) ? handleUpload($_FILES['image']) : null;
                    $i_sql = "INSERT INTO household_staff (estate_id, custom_id, flat_id, first_name, last_name, name, role, phone, status, image_path, registration_date) 
                              VALUES ($estate_id, '$s_custom_id', $flat_id, '$first_name', '$last_name', '$name', '$role', '$phone', '$status', " . ($img ? "'$img'" : "NULL") . ", '$reg_date')";
                    if ($conn->query($i_sql)) $message = "Domestic staff registered successfully!";
                }
            } else {
                $message = "Error: Invalid property unit for this zone.";
                $message_type = "error";
            }
        } elseif (isset($_POST['archive_staff'])) {
            $sid = intval($_POST['archive_id']);
            $chk = $conn->query("SELECT hs.id FROM household_staff hs JOIN flats f ON hs.flat_id = f.id JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE hs.id = $sid AND s.zone_id = $zone_id LIMIT 1");
            if ($chk && $chk->num_rows > 0) {
                $conn->query("UPDATE household_staff SET status='inactive' WHERE id=$sid AND estate_id=$estate_id");
                $message = "Domestic staff archived successfully.";
            }
        }
    } catch (Exception $e) {
        $message = "Exception: " . $e->getMessage();
        $message_type = "error";
    }

    // PRG Pattern: Redirect after any POST request with flash messages
    if (!empty($message)) {
        if ($message_type === 'error') {
            redirectWithFlash('residents', null, $message);
        } else {
            redirectWithFlash('residents', $message);
        }
    } else {
        redirectWithFlash('residents');
    }
}

// -------------------------------------------------------------
// SCOPED DATA RETRIEVAL (STRICTLY FOR CURRENT ZONE)
// -------------------------------------------------------------
$residents = $conn->query("
    SELECT r.*, u.first_name, u.last_name, u.name, u.email, u.phone, 
           f.number as flat_number, f.floor, b.name as building_name, b.id as building_id, 
           s.name as street_name, s.id as street_id 
    FROM residents r 
    LEFT JOIN users u ON r.user_id = u.id 
    JOIN flats f ON r.flat_id = f.id 
    JOIN buildings b ON f.building_id = b.id 
    JOIN streets s ON b.street_id = s.id 
    WHERE s.zone_id = $zone_id AND r.estate_id = $estate_id AND (r.status IS NULL OR r.status NOT IN ('archived', 'inactive'))
    ORDER BY r.id DESC
");

$vehicles = $conn->query("
    SELECT v.*, f.number as flat_number, f.floor, b.name as building_name, s.name as street_name 
    FROM vehicles v 
    JOIN flats f ON v.flat_id = f.id 
    JOIN buildings b ON f.building_id = b.id 
    JOIN streets s ON b.street_id = s.id 
    WHERE s.zone_id = $zone_id AND v.estate_id = $estate_id 
    ORDER BY v.id DESC
");

$pets = $conn->query("
    SELECT p.*, f.number as flat_number, f.floor, b.name as building_name, s.name as street_name 
    FROM pets p 
    JOIN flats f ON p.flat_id = f.id 
    JOIN buildings b ON f.building_id = b.id 
    JOIN streets s ON b.street_id = s.id 
    WHERE s.zone_id = $zone_id AND p.estate_id = $estate_id 
    ORDER BY p.id DESC
");

$staff = $conn->query("
    SELECT hs.*, f.number as flat_number, f.floor, b.name as building_name, s.name as street_name 
    FROM household_staff hs 
    JOIN flats f ON hs.flat_id = f.id 
    JOIN buildings b ON f.building_id = b.id 
    JOIN streets s ON b.street_id = s.id 
    WHERE s.zone_id = $zone_id AND hs.estate_id = $estate_id AND (hs.status IS NULL OR hs.status != 'inactive')
    ORDER BY hs.id DESC
");

// Streets strictly in this zone
$streets_list = $conn->query("SELECT * FROM streets WHERE zone_id = $zone_id AND status != 'archived' AND estate_id = $estate_id ORDER BY name ASC");

// Flats strictly in this zone for direct select
$flats_list = $conn->query("
    SELECT f.id, f.number, f.floor, b.name as building_name, s.name as street_name 
    FROM flats f 
    JOIN buildings b ON f.building_id = b.id 
    JOIN streets s ON b.street_id = s.id 
    WHERE s.zone_id = $zone_id AND f.estate_id = $estate_id 
    ORDER BY s.name, b.name, f.number ASC
");
$all_flats = [];
if ($flats_list) {
    while ($f = $flats_list->fetch_assoc()) { $all_flats[] = $f; }
}

// Zonal Contact Change Requests
$contact_requests = $conn->query("
    SELECT ccr.*, u.name as resident_name, u.role, r.custom_id as res_code, f.number as flat_number, b.name as building_name, s.name as street_name 
    FROM contact_change_requests ccr
    JOIN users u ON ccr.user_id = u.id
    LEFT JOIN residents r ON ccr.resident_id = r.id OR (r.user_id = u.id AND r.estate_id = ccr.estate_id)
    LEFT JOIN flats f ON r.flat_id = f.id
    LEFT JOIN buildings b ON f.building_id = b.id
    LEFT JOIN streets s ON b.street_id = s.id
    WHERE (ccr.zone_id = $zone_id OR s.zone_id = $zone_id) AND ccr.estate_id = $estate_id
    GROUP BY ccr.id
    ORDER BY CASE WHEN ccr.status = 'pending' THEN 1 ELSE 2 END, ccr.id DESC
");
$pending_contact_reqs_count = 0;
if ($contact_requests) {
    while ($cr_row = $contact_requests->fetch_assoc()) {
        if ($cr_row['status'] === 'pending') $pending_contact_reqs_count++;
    }
    $contact_requests->data_seek(0);
}

// Catalogs
$relationships = $conn->query("SELECT * FROM resident_relationships ORDER BY name");
$resident_statuses = $conn->query("SELECT * FROM resident_statuses ORDER BY name");
$ds_roles = $conn->query("SELECT * FROM domestic_staff_roles ORDER BY name");
$ds_status = $conn->query("SELECT * FROM domestic_staff_status ORDER BY name");

// Executive Zonal Resident Population KPIs
$res_kpi_res = $conn->query("
    SELECT 
        COUNT(CASE WHEN r.status = 'active' THEN 1 END) as active_residents,
        COUNT(CASE WHEN r.type = 'head' AND (r.status IS NULL OR r.status NOT IN ('archived', 'inactive')) THEN 1 END) as total_heads,
        COUNT(CASE WHEN r.type = 'dependent' AND (r.status IS NULL OR r.status NOT IN ('archived', 'inactive')) THEN 1 END) as total_dependents,
        (SELECT COUNT(*) FROM vehicles v JOIN flats f ON v.flat_id = f.id JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE s.zone_id = $zone_id AND v.estate_id = $estate_id) as total_vehicles,
        (SELECT COUNT(*) FROM pets p JOIN flats f ON p.flat_id = f.id JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE s.zone_id = $zone_id AND p.estate_id = $estate_id) as total_pets,
        (SELECT COUNT(*) FROM household_staff hs JOIN flats f ON hs.flat_id = f.id JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE s.zone_id = $zone_id AND hs.estate_id = $estate_id AND (hs.status IS NULL OR hs.status != 'inactive')) as total_staff
    FROM residents r
    JOIN flats f ON r.flat_id = f.id
    JOIN buildings b ON f.building_id = b.id
    JOIN streets s ON b.street_id = s.id
    WHERE s.zone_id = $zone_id AND r.estate_id = $estate_id
");

$res_kpi = $res_kpi_res ? $res_kpi_res->fetch_assoc() : [];
$active_res_count = intval($res_kpi['active_residents'] ?? 0);
$heads_count = intval($res_kpi['total_heads'] ?? 0);
$dependents_count = intval($res_kpi['total_dependents'] ?? 0);
$total_pop_count = $heads_count + $dependents_count;
$vehicles_count = intval($res_kpi['total_vehicles'] ?? 0);
$pets_count = intval($res_kpi['total_pets'] ?? 0);
$dom_staff_count = intval($res_kpi['total_staff'] ?? 0);
$ancillaries_count = $vehicles_count + $pets_count + $dom_staff_count;

include 'header.php';
include 'sidebar.php';
?>

<div class="page-header-futuristic mb-4">
    <div>
        <div class="header-breadcrumbs">
            <span><?php echo htmlspecialchars($_SESSION['zone_name'] ?? 'Zone'); ?></span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span>Community</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span class="active">Resident Registry</span>
        </div>
        <h1 class="header-title">Zonal Residents & Multi-Entity Registry</h1>
        <p class="header-subtitle">Strictly isolated to your assigned sector. Register and manage households, family dependents, domestic staff, and vehicle units.</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="archives?tab=residents" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold d-inline-flex align-items-center gap-2 shadow-sm" style="background: white;">
            <i class="fa-solid fa-box-archive text-warning"></i> Zonal Archives Vault
        </a>
        <button type="button" class="btn btn-primary rounded-pill px-3 py-2 fw-semibold d-inline-flex align-items-center gap-2 shadow-sm" onclick="openResidentModal()">
            <i class="fa-solid fa-user-plus"></i> Onboard Resident
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type == 'error' ? 'danger' : 'success'; ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert" style="border-radius: 12px;">
        <div class="d-flex align-items-center gap-2">
            <i class="fa-solid <?php echo $message_type == 'error' ? 'fa-triangle-exclamation' : 'fa-circle-check'; ?> fs-5"></i>
            <div><?php echo htmlspecialchars($message); ?></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- ==========================================
     EXECUTIVE KPI METRICS RIBBON (4 PILLARS)
     ========================================== -->
<div class="row g-3 mb-4">
    <!-- Pillar 1: Active Resident Population -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Active Resident Population</span>
                    <div class="kpi-value"><?php echo number_format($active_res_count); ?></div>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(126, 34, 206, 0.12); color: #7e22ce;">
                    <i class="fa-solid fa-users"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Total Roster: <strong><?php echo $total_pop_count; ?></strong></span>
                <span class="mature-badge mature-badge-emerald">Active In Zone</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?php echo $total_pop_count > 0 ? round(($active_res_count / $total_pop_count) * 100) : 100; ?>%; background: #7e22ce;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 2: Heads of Household -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Heads of Household</span>
                    <div class="kpi-value"><?php echo number_format($heads_count); ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-user-tie"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Primary Leaseholders</span>
                <span class="mature-badge mature-badge-sky"><?php echo $total_pop_count > 0 ? round(($heads_count / $total_pop_count) * 100) : 0; ?>% Heads</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?php echo $total_pop_count > 0 ? ($heads_count / $total_pop_count) * 100 : 0; ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 3: Dependents & Families -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Family Dependents</span>
                    <div class="kpi-value"><?php echo number_format($dependents_count); ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-people-roof"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Affiliated Occupants</span>
                <span class="mature-badge mature-badge-slate"><?php echo $total_pop_count > 0 ? round(($dependents_count / $total_pop_count) * 100) : 0; ?>% Ratio</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?php echo $total_pop_count > 0 ? ($dependents_count / $total_pop_count) * 100 : 0; ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 4: Registered Ancillaries -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Registered Ancillaries</span>
                    <div class="kpi-value"><?php echo number_format($ancillaries_count); ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-shield-cat"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span><?php echo $vehicles_count; ?> Cars &bull; <?php echo $dom_staff_count; ?> Staff &bull; <?php echo $pets_count; ?> Pets</span>
                <span class="mature-badge mature-badge-primary">Permits</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: 100%;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Futuristic Tabs -->
<div class="futuristic-tabs">
    <button class="futuristic-tab-btn tab-btn active" onclick="openTab(event, 'residents')">
        <i class="fa-solid fa-users"></i> Residents 
        <span class="tech-chip" style="padding: 1px 6px;"><?php echo $total_pop_count; ?></span>
    </button>
    <button class="futuristic-tab-btn tab-btn" onclick="openTab(event, 'vehicles')">
        <i class="fa-solid fa-car"></i> Vehicles 
        <span class="tech-chip" style="padding: 1px 6px;"><?php echo $vehicles_count; ?></span>
    </button>
    <button class="futuristic-tab-btn tab-btn" onclick="openTab(event, 'pets')">
        <i class="fa-solid fa-paw"></i> Pets 
        <span class="tech-chip" style="padding: 1px 6px;"><?php echo $pets_count; ?></span>
    </button>
    <button class="futuristic-tab-btn tab-btn" onclick="openTab(event, 'staff')">
        <i class="fa-solid fa-user-shield"></i> Domestic Staff 
        <span class="tech-chip" style="padding: 1px 6px;"><?php echo $dom_staff_count; ?></span>
    </button>
</div>

<!-- ==========================================
     TAB 1: RESIDENTS DIRECTORY
     ========================================== -->
<div id="residents" class="tab-content">
    <!-- Filter Toolbar -->
    <div class="futuristic-filter-bar mb-3">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-stretch align-items-md-center gap-3">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <button class="filter-btn-pill active" onclick="setResidentTypeFilter('all', this)">
                    <i class="fa-solid fa-list-ul me-1"></i> All (<?php echo $total_pop_count; ?>)
                </button>
                <button class="filter-btn-pill" onclick="setResidentTypeFilter('head', this)">
                    <i class="fa-solid fa-user-tie me-1"></i> Heads (<?php echo $heads_count; ?>)
                </button>
                <button class="filter-btn-pill" onclick="setResidentTypeFilter('dependent', this)">
                    <i class="fa-solid fa-people-roof me-1"></i> Dependents (<?php echo $dependents_count; ?>)
                </button>
                <button class="filter-btn-pill" onclick="setResidentTypeFilter('active', this)">
                    <i class="fa-solid fa-circle-check me-1"></i> Active (<?php echo $active_res_count; ?>)
                </button>
            </div>
            <div class="position-relative" style="min-width: 280px;">
                <i class="fa-solid fa-magnifying-glass position-absolute text-muted" style="top: 50%; left: 0.85rem; transform: translateY(-50%); font-size: 0.85rem;"></i>
                <input type="text" id="residentSearchInput" class="form-control ps-5" placeholder="Search by name, flat, phone, email, ID..." onkeyup="filterResidentsTable()">
            </div>
        </div>
    </div>

    <div class="mature-card mb-4">
        <div class="mature-card-header">
            <div>
                <h3 class="mature-card-title">
                    <i class="fa-solid fa-users text-secondary"></i> All Registered Residents in <?php echo htmlspecialchars($_SESSION['zone_name'] ?? 'Zone'); ?>
                </h3>
                <p style="margin: 0.25rem 0 0; font-size: 0.8rem; color: var(--text-muted);">Occupants, primary heads of household, and affiliated family members.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="tech-chip"><i class="fa-solid fa-user-check"></i> Showing: <span id="visibleResidentCount"><?php echo $residents ? $residents->num_rows : 0; ?></span></span>
                <button class="btn btn-sm text-white" style="background: #7e22ce; border-color: #7e22ce;" onclick="openResidentModal()">
                    <i class="fa-solid fa-user-plus me-1"></i> Onboard Resident
                </button>
            </div>
        </div>

        <div class="mature-card-body p-0">
            <div class="table-responsive">
                <table class="table dashboard-table align-middle" id="residentsTable">
                    <thead>
                        <tr>
                            <th style="width: 120px;">System ID</th>
                            <th>Resident Profile</th>
                            <th>Address Location</th>
                            <th>Designation</th>
                            <th>Status</th>
                            <th>Contact / Digital ID</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="residentsTableBody">
                        <?php if ($residents && $residents->num_rows > 0): ?>
                            <?php while($row = $residents->fetch_assoc()): 
                                $res_type = strtolower($row['type'] ?? 'head');
                                $res_status = strtolower($row['status'] ?? 'active');
                                $initials = strtoupper(substr($row['first_name'] ?? '', 0, 1) . substr($row['last_name'] ?? '', 0, 1));
                                $search_meta = strtolower($row['custom_id'] . ' ' . $row['first_name'] . ' ' . $row['last_name'] . ' ' . $row['email'] . ' ' . $row['phone'] . ' ' . $row['flat_number'] . ' ' . $row['building_name'] . ' ' . $row['street_name']);
                            ?>
                            <tr class="resident-row" data-type="<?php echo $res_type; ?>" data-status="<?php echo $res_status; ?>" data-search="<?php echo htmlspecialchars($search_meta); ?>" style="<?php echo $res_status == 'inactive' ? 'opacity: 0.6;' : ''; ?>">
                                <td>
                                    <span class="id-chip"><?php echo htmlspecialchars($row['custom_id']); ?></span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if(!empty($row['image_path'])): ?>
                                            <img src="<?php echo htmlspecialchars($row['image_path']); ?>" style="width: 36px; height: 36px; object-fit: cover; border-radius: 50%; border: 1px solid var(--border-color);" alt="">
                                        <?php else: ?>
                                            <div class="avatar-chip">
                                                <?php echo $initials ?: '<i class="fa-solid fa-user"></i>'; ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div style="font-weight: 600; color: var(--text-color); font-size: 0.92rem;">
                                                <?php echo htmlspecialchars(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')); ?>
                                            </div>
                                            <div style="font-size: 0.75rem; color: var(--text-muted);">
                                                <?php echo ucfirst($row['relationship'] ?? 'Resident'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--text-color); font-size: 0.88rem;">
                                        Flat <?php echo htmlspecialchars($row['flat_number']); ?>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 1px;">
                                        <i class="fa-solid fa-building" style="font-size: 0.7rem; opacity: 0.7;"></i> <?php echo htmlspecialchars($row['building_name']); ?> &bull; <?php echo htmlspecialchars($row['street_name']); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="mature-badge <?php echo $res_type == 'head' ? 'mature-badge-sky' : 'mature-badge-slate'; ?>">
                                        <i class="fa-solid <?php echo $res_type == 'head' ? 'fa-user-tie' : 'fa-user-group'; ?> me-1"></i>
                                        <?php echo ucfirst($row['type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="mature-badge <?php echo $res_status == 'active' ? 'mature-badge-emerald' : 'mature-badge-slate'; ?>">
                                        <i class="fa-solid fa-circle me-1" style="font-size: 0.45rem;"></i>
                                        <?php echo ucfirst($row['status'] ?? 'Active'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-weight: 500; font-size: 0.84rem; color: var(--text-color);"><?php echo htmlspecialchars($row['email']); ?></div>
                                    <div style="font-size: 0.76rem; color: var(--text-muted); font-family: monospace;"><?php echo htmlspecialchars($row['phone']); ?></div>
                                    <a href="../admin/generate_id?id=<?php echo $row['id']; ?>&type=resident" target="_blank" style="display: inline-flex; align-items: center; gap: 0.3rem; margin-top: 0.25rem; font-size: 0.74rem; color: #7e22ce; text-decoration: none; font-weight: 600;">
                                        <i class="fa-solid fa-id-card"></i> Digital ID
                                    </a>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <a href="resident_timeline?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-secondary" style="padding: 0.3rem 0.55rem; font-size: 0.8rem;" title="View Timeline">
                                            <i class="fa-solid fa-clock-rotate-left"></i>
                                        </a>
                                        <button onclick='editResident(<?php echo json_encode($row); ?>)' class="btn btn-sm btn-outline-secondary" style="padding: 0.3rem 0.55rem; font-size: 0.8rem;" title="Edit Resident">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <?php if(($row['status'] ?? 'active') != 'inactive' && ($row['status'] ?? 'active') != 'archived'): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this resident? They will be moved to archives.');">
                                            <input type="hidden" name="archive_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="archive_resident" class="btn btn-sm btn-outline-warning" style="padding: 0.3rem 0.55rem; font-size: 0.8rem;" title="Archive">
                                                <i class="fa-solid fa-box-archive"></i>
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete this duplicate resident entry? This cannot be undone.');">
                                            <input type="hidden" name="archive_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="delete_resident" class="btn btn-sm btn-outline-danger" style="padding: 0.3rem 0.55rem; font-size: 0.8rem;" title="Delete Duplicate">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 3.5rem 1rem; color: var(--text-muted);">
                                    <i class="fa-solid fa-users" style="font-size: 2.2rem; color: var(--text-muted); opacity: 0.4; display: block; margin-bottom: 0.75rem;"></i>
                                    No residents registered in this zone yet. Click <strong>Onboard Resident</strong> to register.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     TAB 2: VEHICLES REGISTRY
     ========================================== -->
<div id="vehicles" class="tab-content" style="display: none;">
    <div class="mature-card mb-4">
        <div class="mature-card-header">
            <div>
                <h3 class="mature-card-title">
                    <i class="fa-solid fa-car text-secondary"></i> Registered Vehicles (<?php echo htmlspecialchars($_SESSION['zone_name'] ?? 'Zone'); ?>)
                </h3>
                <p style="margin: 0.25rem 0 0; font-size: 0.8rem; color: var(--text-muted);">Automobiles, motorcycles, and authorized resident transport units.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="tech-chip"><i class="fa-solid fa-car-side"></i> Total: <?php echo $vehicles ? $vehicles->num_rows : 0; ?></span>
                <button class="btn btn-sm text-white" style="background: #7e22ce; border-color: #7e22ce;" onclick="openModal('vehicle-modal')">
                    <i class="fa-solid fa-car me-1"></i> Add Vehicle
                </button>
            </div>
        </div>

        <div class="mature-card-body p-0">
            <div class="table-responsive">
                <table class="table dashboard-table align-middle">
                    <thead>
                        <tr>
                            <th style="width: 120px;">System ID</th>
                            <th>License Plate</th>
                            <th>Vehicle Make & Model</th>
                            <th>Type</th>
                            <th>Assigned Flat Location</th>
                            <th>Particulars</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($vehicles && $vehicles->num_rows > 0): ?>
                            <?php while($row = $vehicles->fetch_assoc()): ?>
                            <tr>
                                <td><span class="id-chip"><?php echo htmlspecialchars($row['custom_id']); ?></span></td>
                                <td>
                                    <span class="id-chip" style="font-size: 0.85rem; font-weight: 700; letter-spacing: 0.05em; background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe;">
                                        <i class="fa-solid fa-car me-1"></i> <?php echo htmlspecialchars($row['reg_number']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--text-color); font-size: 0.9rem;"><?php echo htmlspecialchars($row['model'] ?? 'Standard Vehicle'); ?></div>
                                </td>
                                <td>
                                    <span class="mature-badge mature-badge-slate"><?php echo ucfirst(htmlspecialchars($row['type'])); ?></span>
                                </td>
                                <td>
                                    <div style="font-weight: 500; color: var(--text-color); font-size: 0.88rem;">Flat <?php echo htmlspecialchars($row['flat_number']); ?></div>
                                    <div style="font-size: 0.74rem; color: var(--text-muted);"><?php echo htmlspecialchars($row['street_name'] . ' &bull; ' . $row['building_name']); ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($row['particulars_path'])): ?>
                                        <a href="<?php echo htmlspecialchars($row['particulars_path']); ?>" target="_blank" class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 text-decoration-none py-1 px-2" title="View Particulars Document">
                                            <i class="fa-solid fa-file-lines me-1"></i> View Doc
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small" style="font-size: 0.78rem;">None</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <a href="../car_sticker.php?id=<?php echo $row['id']; ?>" target="_blank" class="btn btn-sm btn-outline-warning text-dark fw-semibold" style="padding: 0.3rem 0.6rem; font-size: 0.8rem;" title="View &amp; Print Official Car Sticker">
                                            <i class="fa-solid fa-id-card me-1"></i> Sticker
                                        </a>
                                        <button onclick='editVehicle(<?php echo json_encode($row); ?>)' class="btn btn-sm btn-outline-secondary" style="padding: 0.3rem 0.55rem; font-size: 0.8rem;" title="Edit Vehicle"><i class="fa-solid fa-pen-to-square"></i></button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this vehicle?');">
                                            <input type="hidden" name="archive_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="archive_vehicle" class="btn btn-sm btn-outline-danger" style="padding: 0.3rem 0.55rem; font-size: 0.8rem;" title="Remove Vehicle"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align: center; padding: 3rem; color: var(--text-muted);">No vehicles registered in this zone yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     TAB 3: PETS REGISTRY
     ========================================== -->
<div id="pets" class="tab-content" style="display: none;">
    <div class="mature-card mb-4">
        <div class="mature-card-header">
            <div>
                <h3 class="mature-card-title">
                    <i class="fa-solid fa-paw text-secondary"></i> Resident Pets (<?php echo htmlspecialchars($_SESSION['zone_name'] ?? 'Zone'); ?>)
                </h3>
                <p style="margin: 0.25rem 0 0; font-size: 0.8rem; color: var(--text-muted);">Registered pets, veterinary tags, and flat locations.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="tech-chip"><i class="fa-solid fa-dog"></i> Total: <?php echo $pets ? $pets->num_rows : 0; ?></span>
                <button class="btn btn-sm text-white" style="background: #7e22ce; border-color: #7e22ce;" onclick="openModal('pet-modal')">
                    <i class="fa-solid fa-paw me-1"></i> Add Pet
                </button>
            </div>
        </div>

        <div class="mature-card-body p-0">
            <div class="table-responsive">
                <table class="table dashboard-table align-middle">
                    <thead>
                        <tr>
                            <th style="width: 120px;">System ID</th>
                            <th style="width: 50px;">Photo</th>
                            <th>Pet Name</th>
                            <th>Classification / Breed</th>
                            <th>Residence Address</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($pets && $pets->num_rows > 0): ?>
                            <?php while($row = $pets->fetch_assoc()): ?>
                            <tr>
                                <td><span class="id-chip"><?php echo htmlspecialchars($row['custom_id']); ?></span></td>
                                <td>
                                    <?php if(!empty($row['image_path'])): ?>
                                        <img src="<?php echo htmlspecialchars($row['image_path']); ?>" style="width: 36px; height: 36px; object-fit: cover; border-radius: 50%; border: 1px solid var(--border-color);" alt="">
                                    <?php else: ?>
                                        <div class="avatar-chip"><i class="fa-solid fa-paw"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td><div style="font-weight: 600; color: var(--text-color); font-size: 0.9rem;"><?php echo htmlspecialchars($row['name']); ?></div></td>
                                <td>
                                    <span class="mature-badge mature-badge-amber"><?php echo htmlspecialchars($row['type']); ?></span>
                                    <?php if (!empty($row['breed'])): ?>
                                        <span style="color: var(--text-muted); font-size: 0.78rem; margin-left: 4px;">(<?php echo htmlspecialchars($row['breed']); ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-weight: 500; color: var(--text-color); font-size: 0.88rem;">Flat <?php echo htmlspecialchars($row['flat_number']); ?></div>
                                    <div style="font-size: 0.74rem; color: var(--text-muted);"><?php echo htmlspecialchars($row['street_name'] . ' &bull; ' . $row['building_name']); ?></div>
                                </td>
                                <td style="text-align: right;">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <button onclick='editPet(<?php echo json_encode($row); ?>)' class="btn btn-sm btn-outline-secondary" style="padding: 0.3rem 0.55rem; font-size: 0.8rem;" title="Edit Pet"><i class="fa-solid fa-pen-to-square"></i></button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this pet?');">
                                            <input type="hidden" name="archive_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="archive_pet" class="btn btn-sm btn-outline-danger" style="padding: 0.3rem 0.55rem; font-size: 0.8rem;" title="Remove Pet"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align: center; padding: 3rem; color: var(--text-muted);">No pets registered in this zone yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     TAB 4: DOMESTIC STAFF REGISTRY
     ========================================== -->
<div id="staff" class="tab-content" style="display: none;">
    <div class="mature-card mb-4">
        <div class="mature-card-header">
            <div>
                <h3 class="mature-card-title">
                    <i class="fa-solid fa-user-shield text-secondary"></i> Domestic Staff Registry (<?php echo htmlspecialchars($_SESSION['zone_name'] ?? 'Zone'); ?>)
                </h3>
                <p style="margin: 0.25rem 0 0; font-size: 0.8rem; color: var(--text-muted);">Household personnel, drivers, housekeepers, cooks, and zonal security passes.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="tech-chip"><i class="fa-solid fa-id-badge"></i> Total: <?php echo $staff ? $staff->num_rows : 0; ?></span>
                <button class="btn btn-sm text-white" style="background: #7e22ce; border-color: #7e22ce;" onclick="openModal('staff-modal')">
                    <i class="fa-solid fa-user-plus me-1"></i> Add Staff
                </button>
            </div>
        </div>

        <div class="mature-card-body p-0">
            <div class="table-responsive">
                <table class="table dashboard-table align-middle">
                    <thead>
                        <tr>
                            <th style="width: 120px;">System ID</th>
                            <th style="width: 50px;">Photo</th>
                            <th>Staff Name</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Phone</th>
                            <th>Assigned Flat</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($staff && $staff->num_rows > 0): ?>
                            <?php while($row = $staff->fetch_assoc()): ?>
                            <tr>
                                <td><span class="id-chip"><?php echo htmlspecialchars($row['custom_id']); ?></span></td>
                                <td>
                                    <?php if(!empty($row['image_path'])): ?>
                                        <img src="<?php echo htmlspecialchars($row['image_path']); ?>" style="width: 36px; height: 36px; object-fit: cover; border-radius: 50%; border: 1px solid var(--border-color);" alt="">
                                    <?php else: ?>
                                        <div class="avatar-chip"><i class="fa-solid fa-user-tie"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td><div style="font-weight: 600; color: var(--text-color); font-size: 0.9rem;"><?php echo htmlspecialchars($row['name']); ?></div></td>
                                <td><span class="mature-badge mature-badge-sky"><?php echo htmlspecialchars($row['role']); ?></span></td>
                                <td>
                                    <span class="mature-badge <?php echo ($row['status'] ?? 'active') != 'inactive' ? 'mature-badge-emerald' : 'mature-badge-slate'; ?>">
                                        <?php echo htmlspecialchars($row['status'] ?? 'Daily'); ?>
                                    </span>
                                </td>
                                <td><span style="font-family: monospace; font-size: 0.85rem;"><?php echo htmlspecialchars($row['phone'] ?? '-'); ?></span></td>
                                <td>
                                    <div style="font-weight: 500; color: var(--text-color); font-size: 0.88rem;">Flat <?php echo htmlspecialchars($row['flat_number']); ?></div>
                                    <div style="font-size: 0.74rem; color: var(--text-muted);"><?php echo htmlspecialchars($row['street_name'] . ' &bull; ' . $row['building_name']); ?></div>
                                </td>
                                <td style="text-align: right;">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <button onclick='editStaff(<?php echo json_encode($row); ?>)' class="btn btn-sm btn-outline-secondary" style="padding: 0.3rem 0.55rem; font-size: 0.8rem;" title="Edit Staff"><i class="fa-solid fa-pen-to-square"></i></button>
                                        <?php if(($row['status'] ?? 'active') != 'inactive'): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this staff member?');">
                                            <input type="hidden" name="archive_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="archive_staff" class="btn btn-sm btn-outline-danger" style="padding: 0.3rem 0.55rem; font-size: 0.8rem;" title="Archive Staff"><i class="fa-solid fa-box-archive"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align: center; padding: 3rem; color: var(--text-muted);">No domestic staff registered in this zone yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Contact Requests Tab -->
<div id="contact_requests" class="tab-content" style="display: none;">
    <div class="mature-card mb-4">
        <div class="mature-card-header">
            <div>
                <h3 class="mature-card-title">
                    <i class="fa-solid fa-id-card-clip text-secondary"></i> Zonal Resident Contact Details Change Requests
                </h3>
                <p style="margin: 0.25rem 0 0; font-size: 0.8rem; color: var(--text-muted);">Review and authorize email and phone number modifications requested by residents in your zone.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="tech-chip"><i class="fa-solid fa-clock-rotate-left"></i> Pending: <?php echo $pending_contact_reqs_count; ?></span>
            </div>
        </div>

        <div class="mature-card-body p-0">
            <div class="table-responsive">
                <table class="table dashboard-table align-middle">
                    <thead>
                        <tr>
                            <th style="width: 100px;">Req ID</th>
                            <th>Resident</th>
                            <th>Unit Location</th>
                            <th>Current Contact</th>
                            <th>Requested Update</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th style="text-align: right; min-width: 160px;">Admin Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($contact_requests && $contact_requests->num_rows > 0): ?>
                            <?php while($req = $contact_requests->fetch_assoc()): 
                                $r_status = $req['status'] ?? 'pending';
                            ?>
                            <tr style="<?php echo $r_status === 'pending' ? 'background: #fffbeb;' : ''; ?>">
                                <td><span class="id-chip font-monospace">REQ-#<?php echo $req['id']; ?></span></td>
                                <td>
                                    <div class="fw-bold text-slate-800"><?php echo htmlspecialchars($req['resident_name']); ?></div>
                                    <div class="small text-muted font-monospace"><?php echo htmlspecialchars($req['res_code'] ?? 'User #' . $req['user_id']); ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-slate-800">Flat <?php echo htmlspecialchars($req['flat_number'] ?? 'N/A'); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($req['street_name'] ?? 'Zone Unit'); ?></div>
                                </td>
                                <td>
                                    <div class="small text-muted">Email: <span class="font-monospace text-slate-700"><?php echo htmlspecialchars($req['current_email'] ?: 'None'); ?></span></div>
                                    <div class="small text-muted">Phone: <span class="font-monospace text-slate-700"><?php echo htmlspecialchars($req['current_phone'] ?: 'None'); ?></span></div>
                                </td>
                                <td>
                                    <?php if (!empty($req['requested_email'])): ?>
                                        <div class="small"><i class="fa-regular fa-envelope text-primary me-1"></i> <strong class="text-primary font-monospace"><?php echo htmlspecialchars($req['requested_email']); ?></strong></div>
                                    <?php endif; ?>
                                    <?php if (!empty($req['requested_phone'])): ?>
                                        <div class="small"><i class="fa-solid fa-phone text-success me-1"></i> <strong class="text-success font-monospace"><?php echo htmlspecialchars($req['requested_phone']); ?></strong></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small text-slate-700" style="max-width: 200px;"><?php echo htmlspecialchars($req['reason'] ?: 'No justification specified.'); ?></div>
                                    <div class="small text-muted mt-1"><?php echo date('M d, Y h:i A', strtotime($req['created_at'])); ?></div>
                                </td>
                                <td>
                                    <?php if ($r_status === 'pending'): ?>
                                        <span class="badge bg-warning text-dark px-2.5 py-1 rounded-pill"><i class="fa-solid fa-hourglass-half me-1"></i> Pending</span>
                                    <?php elseif ($r_status === 'approved'): ?>
                                        <span class="badge bg-success px-2.5 py-1 rounded-pill"><i class="fa-solid fa-check me-1"></i> Approved</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger px-2.5 py-1 rounded-pill"><i class="fa-solid fa-xmark me-1"></i> Rejected</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <?php if ($r_status === 'pending'): ?>
                                        <div class="d-inline-flex align-items-center gap-1">
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Approve this contact information update for <?php echo addslashes($req['resident_name']); ?>?');">
                                                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                                <button type="submit" name="approve_contact_change" class="btn btn-sm btn-success px-2.5 py-1 rounded-pill shadow-sm" title="Approve & Apply Update">
                                                    <i class="fa-solid fa-check"></i> Approve
                                                </button>
                                            </form>
                                            <button type="button" class="btn btn-sm btn-outline-danger px-2.5 py-1 rounded-pill" onclick="openRejectContactModal(<?php echo $req['id']; ?>, '<?php echo addslashes($req['resident_name']); ?>')">
                                                <i class="fa-solid fa-xmark"></i> Reject
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="small text-muted"><?php echo htmlspecialchars($req['admin_notes'] ?: 'Completed'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align: center; padding: 3rem; color: var(--text-muted);">No contact update requests recorded in this zone.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal to Reject Contact Change Request -->
<div id="reject-contact-modal" class="custom-modal-backdrop">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-header">
            <h3 style="margin:0; font-size: 1.1rem; color: #dc2626;"><i class="fa-solid fa-triangle-exclamation me-1"></i> Decline Contact Update</h3>
            <button class="close-modal" type="button" onclick="closeModal('reject-contact-modal')"><i class="fa-solid fa-times"></i></button>
        </div>
        <form method="POST">
            <div style="padding: 1.5rem;">
                <input type="hidden" name="request_id" id="reject_req_id">
                <p class="small text-secondary mb-3">Please provide a reason for declining the contact update requested by <strong id="reject_res_name"></strong>:</p>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary">DECLINE REASON</label>
                    <textarea name="admin_notes" class="form-control" rows="3" placeholder="e.g. Unverified identification, duplicate record mismatch..." required></textarea>
                </div>
            </div>
            <div class="modal-footer" style="padding: 1rem 1.5rem; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 0.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('reject-contact-modal')">Cancel</button>
                <button type="submit" name="reject_contact_change" class="btn btn-danger">Confirm Decline</button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================
     MODAL 1: RESIDENT ONBOARDING (MULTI-ENTITY)
     ========================================== -->
<div id="resident-modal" class="custom-modal-backdrop">
    <div class="modal-content" style="max-width: 800px; max-height: 92vh; overflow-y: auto;">
        <div class="modal-header">
            <h2 id="resident-modal-title" style="font-size: 1.25rem; font-weight: 700; color: #1e293b;">Register Resident</h2>
            <button class="close-modal" type="button" onclick="closeModal('resident-modal')"><i class="fa-solid fa-times"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="resident_form">
            <input type="hidden" name="resident_id" id="res_resident_id">
            <input type="hidden" name="add_resident" value="1">
            
            <div class="form-grid">
                <div class="form-group">
                    <label>First Name <span class="text-danger">*</span></label>
                    <input type="text" name="first_name" id="res_first_name" required class="form-control" placeholder="e.g. Samuel">
                </div>
                <div class="form-group">
                    <label>Last Name <span class="text-danger">*</span></label>
                    <input type="text" name="last_name" id="res_last_name" required class="form-control" placeholder="e.g. Adeleke">
                </div>
                <div class="form-group">
                    <label>Email Address <span class="text-danger">*</span></label>
                    <input type="email" name="email" id="res_email" required class="form-control" placeholder="samuel.adeleke@example.com">
                </div>
                <div class="form-group">
                    <label>Phone Number <span class="text-danger">*</span></label>
                    <input type="text" name="phone" id="res_phone" required class="form-control" placeholder="e.g. 08012345678">
                </div>
                
                <!-- Assigned Residence Location Hierarchy (Zone-Filtered) -->
                <div class="form-group" style="grid-column: span 2;">
                    <label style="font-weight: 600; color: #1e293b; margin-bottom: 0.5rem; display: block;">
                        <i class="fa-solid fa-location-dot text-purple me-1" style="color: #7e22ce;"></i> Assigned Residence Location (Zone Scoped)
                    </label>
                    <div style="background: #f8fafc; padding: 1.25rem; border-radius: 0.5rem; border: 1px solid #e2e8f0;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                            <div>
                                <label style="font-size: 0.8rem; color: #64748b; font-weight: 600; display: block; margin-bottom: 0.35rem;">
                                    1. Zonal Street <span style="color: #ef4444;">*</span>
                                </label>
                                <select id="res_street_id" class="form-control" onchange="fetchResidentBuildings(this.value)" required>
                                    <option value="">-- Choose Street --</option>
                                    <?php 
                                    if ($streets_list && $streets_list->num_rows > 0):
                                        $streets_list->data_seek(0);
                                        while($st = $streets_list->fetch_assoc()): ?>
                                            <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['name']); ?></option>
                                        <?php endwhile;
                                    endif; ?>
                                </select>
                            </div>
                            <div>
                                <label style="font-size: 0.8rem; color: #64748b; font-weight: 600; display: block; margin-bottom: 0.35rem;">
                                    2. Building / Property <span style="color: #ef4444;">*</span>
                                </label>
                                <select id="res_building_id" class="form-control" onchange="fetchResidentFlats(this.value)" required>
                                    <option value="">Select Street First</option>
                                </select>
                            </div>
                            <div>
                                <label style="font-size: 0.8rem; color: #64748b; font-weight: 600; display: block; margin-bottom: 0.35rem;">
                                    3. Select Flat <span style="color: #ef4444;">*</span>
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
                        <option value="active">Active</option>
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
                <img id="res_preview" src="" style="display: none; width: 80px; height: 80px; object-fit: cover; border-radius: 50%; margin-top: 1rem; border: 2px solid #7e22ce;">
            </div>

            <!-- DYNAMIC MULTI-ENTITY ACCORDIONS (Onboarding Extras) -->
            <div id="multiEntitySections" style="margin-top: 1.5rem; border-top: 1px solid #e2e8f0; padding-top: 1rem;">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-dark mb-0">
                        <i class="fa-solid fa-users-viewfinder text-purple me-1" style="color: #7e22ce;"></i> Additional Household Entities (Optional)
                    </h6>
                    <div class="dropdown">
                        <button class="btn btn-xs btn-outline-primary dropdown-toggle rounded-pill px-3 py-1" type="button" data-bs-toggle="dropdown" style="font-size: 0.78rem;">
                            <i class="fa-solid fa-plus me-1"></i> Attach More
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border">
                            <li><a class="dropdown-item small" href="javascript:void(0)" onclick="addFamilyRow()"><i class="fa-solid fa-people-roof me-2 text-primary"></i> Family Member</a></li>
                            <li><a class="dropdown-item small" href="javascript:void(0)" onclick="addStaffRow()"><i class="fa-solid fa-user-shield me-2 text-success"></i> Domestic Staff</a></li>
                            <li><a class="dropdown-item small" href="javascript:void(0)" onclick="addVehicleRow()"><i class="fa-solid fa-car me-2 text-warning"></i> Vehicle</a></li>
                            <li><a class="dropdown-item small" href="javascript:void(0)" onclick="addPetRow()"><i class="fa-solid fa-paw me-2 text-info"></i> Pet</a></li>
                        </ul>
                    </div>
                </div>

                <div id="dynamicContainer" class="d-flex flex-column gap-3"></div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem; border-top: 1px solid #e2e8f0; padding-top: 1.5rem;">
                <button type="button" class="btn" style="background: #f1f5f9; color: #475569;" onclick="closeModal('resident-modal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="res_submit_btn" style="background: #7e22ce; border-color: #7e22ce;">Save Resident</button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================
     MODAL 2: VEHICLE MODAL
     ========================================== -->
<div id="vehicle-modal" class="custom-modal-backdrop">
    <div class="modal-content" style="max-width: 650px;">
        <div class="modal-header">
            <h2 id="vehicle-modal-title">Add Vehicle</h2>
            <button class="close-modal" onclick="closeModal('vehicle-modal')"><i class="fa-solid fa-times"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="vehicle_id" id="vehicle_id">
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
                <div class="form-grid">
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Zonal Flat Address</label>
                        <select name="flat_id" id="veh_flat_id" required class="form-control">
                            <option value="">-- Choose Flat in Zone --</option>
                            <?php foreach($all_flats as $f): ?>
                                <option value="<?php echo $f['id']; ?>"><?php echo htmlspecialchars('Flat ' . $f['number'] . ' &bull; ' . $f['building_name'] . ' (' . $f['street_name'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Registration / Plate Number</label>
                        <input type="text" name="reg_number" id="veh_reg" required class="form-control text-uppercase font-monospace" placeholder="e.g. ABC-123XY">
                    </div>
                    <div class="form-group">
                        <label>Vehicle Type</label>
                        <select name="type" id="veh_type" class="form-control">
                            <option value="car">Car / Sedan</option>
                            <option value="suv">SUV / Jeep</option>
                            <option value="motorcycle">Motorcycle</option>
                            <option value="truck">Van / Truck</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Make & Model</label>
                        <input type="text" name="model" id="veh_model" placeholder="e.g. Toyota Camry" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Color</label>
                        <input type="text" name="color" id="veh_color" placeholder="e.g. Silver Metallic" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Registration Date</label>
                        <input type="date" name="registration_date" id="veh_reg_date" value="<?php echo date('Y-m-d'); ?>" class="form-control">
                    </div>
                </div>
                <div class="d-flex flex-column gap-3">
                    <div class="form-group">
                         <label>Vehicle Photo</label>
                         <input type="file" name="image" accept="image/*" onchange="previewImage(this, 'vehicle_preview')" class="form-control">
                         <img id="vehicle_preview" src="" style="display: none; width: 100%; height: auto; max-height: 120px; object-fit: contain; margin-top: 0.5rem; border-radius: 0.5rem;">
                    </div>
                    <div class="form-group">
                         <label>Vehicle Particulars (Doc / License)</label>
                         <input type="file" name="particulars" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="form-control">
                         <div id="veh_particulars_wrap" style="display: none; margin-top: 0.4rem;">
                             <a href="" id="veh_particulars_link" target="_blank" class="btn btn-sm btn-outline-primary py-1 px-2" style="font-size: 0.78rem;">
                                 <i class="fa-solid fa-file-lines me-1"></i> View Existing Document
                             </a>
                         </div>
                    </div>
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem;">
                <button type="button" class="btn" style="background: #f1f5f9;" onclick="closeModal('vehicle-modal')">Cancel</button>
                <button type="submit" name="add_vehicle" class="btn btn-primary" id="veh_btn" style="background: #7e22ce; border-color: #7e22ce;">Save Vehicle</button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================
     MODAL 3: PET MODAL
     ========================================== -->
<div id="pet-modal" class="custom-modal-backdrop">
    <div class="modal-content" style="max-width: 650px;">
        <div class="modal-header">
            <h2 id="pet-modal-title">Add Pet</h2>
            <button class="close-modal" onclick="closeModal('pet-modal')"><i class="fa-solid fa-times"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="pet_id" id="pet_id">
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
                <div class="form-grid">
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Zonal Flat Address</label>
                        <select name="flat_id" id="pet_flat_id" required class="form-control">
                            <option value="">-- Choose Flat in Zone --</option>
                            <?php foreach($all_flats as $f): ?>
                                <option value="<?php echo $f['id']; ?>"><?php echo htmlspecialchars('Flat ' . $f['number'] . ' &bull; ' . $f['building_name'] . ' (' . $f['street_name'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Pet Name</label>
                        <input type="text" name="name" id="pet_name" required class="form-control" placeholder="e.g. Bruno">
                    </div>
                    <div class="form-group">
                        <label>Classification</label>
                        <input type="text" name="type" id="pet_type" placeholder="Dog, Cat, etc." required class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Breed</label>
                        <input type="text" name="breed" id="pet_breed" placeholder="e.g. German Shepherd" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Registration Date</label>
                        <input type="date" name="registration_date" id="pet_reg_date" value="<?php echo date('Y-m-d'); ?>" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                     <label>Pet Photo</label>
                     <input type="file" name="image" accept="image/*" onchange="previewImage(this, 'pet_preview')" class="form-control">
                     <img id="pet_preview" src="" style="display: none; width: 100%; height: auto; max-height: 160px; object-fit: contain; margin-top: 1rem; border-radius: 0.5rem;">
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem;">
                <button type="button" class="btn" style="background: #f1f5f9;" onclick="closeModal('pet-modal')">Cancel</button>
                <button type="submit" name="add_pet" class="btn btn-primary" id="pet_btn" style="background: #7e22ce; border-color: #7e22ce;">Save Pet</button>
            </div>
        </form>
    </div>
</div>

<!-- ==========================================
     MODAL 4: DOMESTIC STAFF MODAL
     ========================================== -->
<div id="staff-modal" class="custom-modal-backdrop">
    <div class="modal-content" style="max-width: 650px;">
        <div class="modal-header">
            <h2 id="staff-modal-title">Add Domestic Staff</h2>
            <button class="close-modal" onclick="closeModal('staff-modal')"><i class="fa-solid fa-times"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="staff_id" id="staff_id">
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
                <div class="form-grid">
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Zonal Flat Address</label>
                        <select name="flat_id" id="stf_flat_id" required class="form-control">
                            <option value="">-- Choose Flat in Zone --</option>
                            <?php foreach($all_flats as $f): ?>
                                <option value="<?php echo $f['id']; ?>"><?php echo htmlspecialchars('Flat ' . $f['number'] . ' &bull; ' . $f['building_name'] . ' (' . $f['street_name'] . ')'); ?></option>
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
                            <?php if ($ds_roles && $ds_roles->num_rows > 0): 
                                $ds_roles->data_seek(0); while($r = $ds_roles->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($r['name']); ?>"><?php echo htmlspecialchars($r['name']); ?></option>
                            <?php endwhile; else: ?>
                                <option value="Cook">Cook</option>
                                <option value="Driver">Driver</option>
                                <option value="Housekeeper">Housekeeper</option>
                                <option value="Gardener">Gardener</option>
                                <option value="Security">Private Security</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status / Access Mode</label>
                        <select name="status" id="stf_status" required class="form-control">
                            <?php if ($ds_status && $ds_status->num_rows > 0): 
                                $ds_status->data_seek(0); while($s = $ds_status->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($s['name']); ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                            <?php endwhile; else: ?>
                                <option value="Daily">Daily Commuter</option>
                                <option value="Live-in">Live-in</option>
                                <option value="Temporary">Temporary</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone" id="stf_phone" class="form-control" placeholder="08012345678">
                    </div>
                    <div class="form-group">
                        <label>Registration Date</label>
                        <input type="date" name="registration_date" id="stf_reg_date" value="<?php echo date('Y-m-d'); ?>" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                     <label>Staff Photo</label>
                     <input type="file" name="image" accept="image/*" onchange="previewImage(this, 'staff_preview')" class="form-control">
                     <img id="staff_preview" src="" style="display: none; width: 100%; height: auto; max-height: 160px; object-fit: contain; margin-top: 1rem; border-radius: 0.5rem;">
                </div>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem;">
                <button type="button" class="btn" style="background: #f1f5f9;" onclick="closeModal('staff-modal')">Cancel</button>
                <button type="submit" name="add_staff" class="btn btn-primary" id="stf_btn" style="background: #7e22ce; border-color: #7e22ce;">Save Staff</button>
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
        tablinks[i].style.color = "";
        tablinks[i].style.borderBottom = "";
    }
    var target = document.getElementById(tabName);
    if (target) {
        target.style.display = "block";
    }
    if (evt && evt.currentTarget) {
        evt.currentTarget.classList.add("active");
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
        const partWrap = document.getElementById('veh_particulars_wrap');
        if (partWrap) partWrap.style.display = 'none';
        const partLink = document.getElementById('veh_particulars_link');
        if (partLink) partLink.href = '';
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

// Resident Modal Handler
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
    document.getElementById('res_status').value = 'active';
    document.getElementById('res_reg_date').value = '<?php echo date('Y-m-d'); ?>';
    const preview = document.getElementById('res_preview');
    if (preview) {
        preview.style.display = 'none';
        preview.src = '';
    }
    const btn = document.getElementById('res_submit_btn');
    if (btn) btn.innerText = 'Save Resident';
    document.getElementById('dynamicContainer').innerHTML = '';
}

// Dynamic Cascading Dropdowns
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
                let html = '<option value="">Select Flat</option>';
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
    document.getElementById('res_status').value = data.status || 'active';
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
    document.getElementById('dynamicContainer').innerHTML = '';
}

// Vehicle Modal Edit
function editVehicle(data) {
    openModal('vehicle-modal');
    document.getElementById('vehicle-modal-title').innerText = 'Update Vehicle';
    document.getElementById('vehicle_id').value = data.id;
    document.getElementById('veh_flat_id').value = data.flat_id;
    document.getElementById('veh_reg').value = data.reg_number;
    document.getElementById('veh_type').value = data.type;
    document.getElementById('veh_model').value = data.model;
    document.getElementById('veh_color').value = data.color || '';
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
    const partWrap = document.getElementById('veh_particulars_wrap');
    const partLink = document.getElementById('veh_particulars_link');
    if (partWrap && partLink) {
        if (data.particulars_path) {
            partLink.href = data.particulars_path;
            partWrap.style.display = 'block';
        } else {
            partWrap.style.display = 'none';
            partLink.href = '';
        }
    }
}

// Pet Modal Edit
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

// Staff Modal Edit
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

// Dynamic Household Extras
let dynamicRowCount = 0;

function addFamilyRow() {
    dynamicRowCount++;
    const id = dynamicRowCount;
    const card = document.createElement('div');
    card.id = `dyn_family_${id}`;
    card.className = 'p-3 rounded-3 border bg-white shadow-sm position-relative';
    card.innerHTML = `
        <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
            <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold"><i class="fa-solid fa-people-roof me-1"></i> Family Member / Dependent</span>
            <button type="button" class="btn btn-sm btn-link text-danger p-0 text-decoration-none" onclick="document.getElementById('dyn_family_${id}').remove()">
                <i class="fa-solid fa-trash-can"></i> Remove
            </button>
        </div>
        <input type="hidden" name="family_category[]" value="dependent">
        <div class="row g-2">
            <div class="col-md-6">
                <input type="text" name="family_first_name[]" class="form-control form-control-sm" placeholder="First Name *" required>
            </div>
            <div class="col-md-6">
                <input type="text" name="family_last_name[]" class="form-control form-control-sm" placeholder="Last Name">
            </div>
            <div class="col-md-4">
                <select name="family_role[]" class="form-select form-select-sm">
                    <option value="Spouse">Spouse</option>
                    <option value="Child">Child / Dependent</option>
                    <option value="Parent">Parent</option>
                    <option value="Sibling">Sibling</option>
                    <option value="Other">Other Relative</option>
                </select>
            </div>
            <div class="col-md-4">
                <input type="email" name="family_email[]" class="form-control form-control-sm" placeholder="Email (Optional)">
            </div>
            <div class="col-md-4">
                <input type="text" name="family_phone[]" class="form-control form-control-sm" placeholder="Phone Number">
            </div>
            <div class="col-12">
                <input type="file" name="family_image[]" class="form-control form-control-sm" accept="image/*">
            </div>
        </div>
    `;
    document.getElementById('dynamicContainer').appendChild(card);
}

function addStaffRow() {
    dynamicRowCount++;
    const id = dynamicRowCount;
    const card = document.createElement('div');
    card.id = `dyn_staff_${id}`;
    card.className = 'p-3 rounded-3 border bg-white shadow-sm position-relative';
    card.innerHTML = `
        <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
            <span class="badge bg-success bg-opacity-10 text-success fw-semibold"><i class="fa-solid fa-user-shield me-1"></i> Domestic Staff</span>
            <button type="button" class="btn btn-sm btn-link text-danger p-0 text-decoration-none" onclick="document.getElementById('dyn_staff_${id}').remove()">
                <i class="fa-solid fa-trash-can"></i> Remove
            </button>
        </div>
        <input type="hidden" name="family_category[]" value="staff">
        <div class="row g-2">
            <div class="col-md-6">
                <input type="text" name="family_first_name[]" class="form-control form-control-sm" placeholder="Staff First Name *" required>
            </div>
            <div class="col-md-6">
                <input type="text" name="family_last_name[]" class="form-control form-control-sm" placeholder="Last Name">
            </div>
            <div class="col-md-6">
                <select name="family_role[]" class="form-select form-select-sm">
                    <option value="Housekeeper">Housekeeper</option>
                    <option value="Driver">Driver</option>
                    <option value="Cook">Cook / Chef</option>
                    <option value="Nanny">Nanny</option>
                    <option value="Gardener">Gardener</option>
                    <option value="Private Security">Private Security</option>
                </select>
            </div>
            <div class="col-md-6">
                <input type="text" name="family_phone[]" class="form-control form-control-sm" placeholder="Phone Number">
            </div>
            <div class="col-12">
                <input type="file" name="family_image[]" class="form-control form-control-sm" accept="image/*">
            </div>
        </div>
    `;
    document.getElementById('dynamicContainer').appendChild(card);
}

function addVehicleRow() {
    dynamicRowCount++;
    const id = dynamicRowCount;
    const card = document.createElement('div');
    card.id = `dyn_veh_${id}`;
    card.className = 'p-3 rounded-3 border bg-white shadow-sm position-relative';
    card.innerHTML = `
        <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
            <span class="badge bg-warning bg-opacity-10 text-dark fw-semibold"><i class="fa-solid fa-car me-1"></i> Registered Vehicle</span>
            <button type="button" class="btn btn-sm btn-link text-danger p-0 text-decoration-none" onclick="document.getElementById('dyn_veh_${id}').remove()">
                <i class="fa-solid fa-trash-can"></i> Remove
            </button>
        </div>
        <div class="row g-2">
            <div class="col-md-4">
                <select name="vehicle_type[]" class="form-select form-select-sm">
                    <option value="car">Car / Sedan</option>
                    <option value="suv">SUV / Jeep</option>
                    <option value="motorcycle">Motorcycle</option>
                    <option value="truck">Van / Truck</option>
                </select>
            </div>
            <div class="col-md-4">
                <input type="text" name="vehicle_reg[]" class="form-control form-control-sm text-uppercase font-monospace" placeholder="Plate Number *" required>
            </div>
            <div class="col-md-4">
                <input type="text" name="vehicle_model[]" class="form-control form-control-sm" placeholder="Make / Model">
            </div>
            <div class="col-md-6">
                <label class="form-label small text-muted mb-1">Vehicle Photo</label>
                <input type="file" name="vehicle_image[]" class="form-control form-control-sm" accept="image/*">
            </div>
            <div class="col-md-6">
                <label class="form-label small text-muted mb-1">Vehicle Particulars</label>
                <input type="file" name="vehicle_particulars[]" class="form-control form-control-sm" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
            </div>
        </div>
    `;
    document.getElementById('dynamicContainer').appendChild(card);
}

function addPetRow() {
    dynamicRowCount++;
    const id = dynamicRowCount;
    const card = document.createElement('div');
    card.id = `dyn_pet_${id}`;
    card.className = 'p-3 rounded-3 border bg-white shadow-sm position-relative';
    card.innerHTML = `
        <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
            <span class="badge bg-info bg-opacity-10 text-info fw-semibold"><i class="fa-solid fa-paw me-1"></i> Pet</span>
            <button type="button" class="btn btn-sm btn-link text-danger p-0 text-decoration-none" onclick="document.getElementById('dyn_pet_${id}').remove()">
                <i class="fa-solid fa-trash-can"></i> Remove
            </button>
        </div>
        <div class="row g-2">
            <div class="col-md-4">
                <input type="text" name="pet_name[]" class="form-control form-control-sm" placeholder="Pet Name *" required>
            </div>
            <div class="col-md-4">
                <input type="text" name="pet_type[]" class="form-control form-control-sm" placeholder="Type (e.g. Dog, Cat)" value="Dog">
            </div>
            <div class="col-md-4">
                <input type="text" name="pet_breed[]" class="form-control form-control-sm" placeholder="Breed (e.g. Alsatian)">
            </div>
            <div class="col-12">
                <input type="file" name="pet_image[]" class="form-control form-control-sm" accept="image/*">
            </div>
        </div>
    `;
    document.getElementById('dynamicContainer').appendChild(card);
}

// Close modal when clicking backdrop
window.addEventListener('click', function(e) {
    if (e.target.classList && e.target.classList.contains('custom-modal-backdrop')) {
        e.target.style.display = 'none';
    }
});

// Close modal on Escape
window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.custom-modal-backdrop').forEach(m => m.style.display = 'none');
    }
});

let activeResidentType = 'all';

function setResidentTypeFilter(type, btn) {
    activeResidentType = type;
    document.querySelectorAll('.filter-btn-pill').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    filterResidentsTable();
}

function filterResidentsTable() {
    const term = (document.getElementById('residentSearchInput')?.value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('.resident-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const type = row.getAttribute('data-type');
        const status = row.getAttribute('data-status');
        const searchMeta = row.getAttribute('data-search') || '';

        let matchesType = true;
        if (activeResidentType === 'head') matchesType = (type === 'head');
        else if (activeResidentType === 'dependent') matchesType = (type === 'dependent');
        else if (activeResidentType === 'active') matchesType = (status === 'active');

        const matchesTerm = !term || searchMeta.includes(term);

        if (matchesType && matchesTerm) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const countElem = document.getElementById('visibleResidentCount');
    if (countElem) countElem.innerText = visibleCount;
}

function exportResidentsCSV() {
    let csv = "System ID,Full Name,Address,Type,Status,Email,Phone\n";
    document.querySelectorAll('.resident-row').forEach(row => {
        if (row.style.display !== 'none') {
            const cols = row.querySelectorAll('td');
            if (cols.length >= 6) {
                const sysId = cols[0].innerText.trim();
                const name = cols[1].querySelector('div > div > div:first-child')?.innerText.trim() || '';
                const addr = cols[2].innerText.replace(/\s+/g, ' ').trim();
                const type = cols[3].innerText.trim();
                const status = cols[4].innerText.trim();
                const email = cols[5].querySelector('div:first-child')?.innerText.trim() || '';
                const phone = cols[5].querySelector('div:nth-child(2)')?.innerText.trim() || '';
                csv += `"${sysId}","${name}","${addr}","${type}","${status}","${email}","${phone}"\n`;
            }
        }
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.setAttribute('href', url);
    link.setAttribute('download', `Zone_Residents_${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
function openRejectContactModal(reqId, resName) {
    document.getElementById('reject_req_id').value = reqId;
    document.getElementById('reject_res_name').innerText = resName;
    openModal('reject-contact-modal');
}
</script>

<?php include 'footer.php'; ?>
