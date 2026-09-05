<?php
// admin/staff.php
require_once '../config.php';
require_once '../includes/auth_guard.php';
requireAdminAccess();

$estate_id = get_estate_id();
$message = "";
$message_type = "success";
$active_tab = $_GET['tab'] ?? 'directory';

// Helper to handle uploads
function handleUpload($file) {
    global $conn;
    if (isset($file['error']) && $file['error'] == 0) {
        $target_dir = "../uploads/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $target_file = $target_dir . time() . "_" . basename($file["name"]);
        if (move_uploaded_file($file["tmp_name"], $target_file)) {
            return $conn->real_escape_string($target_file);
        }
    }
    return null;
}

if (!function_exists('generateCustomID')) {
    function generateCustomID($conn, $table, $prefix) {
        $res = $conn->query("SELECT MAX(id) as max_id FROM $table");
        $row = $res->fetch_assoc();
        $next = ($row['max_id'] ?? 0) + 1;
        return $prefix . '-' . str_pad($next, 5, '0', STR_PAD_LEFT);
    }
}

// Handle Form Submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 1. ADD / UPDATE STAFF MEMBER
    if (isset($_POST['save_staff'])) {
        $first_name = $conn->real_escape_string($_POST['first_name']);
        $last_name = $conn->real_escape_string($_POST['last_name']);
        $name = trim($first_name . ' ' . $last_name);
        $email = $conn->real_escape_string($_POST['email']);
        $phone = $conn->real_escape_string($_POST['phone']);
        $role_id = intval($_POST['role_id']);
        $reg_date = $conn->real_escape_string($_POST['registration_date']);
        $status = isset($_POST['status']) ? $conn->real_escape_string($_POST['status']) : 'active';
        
        // Fetch role info
        $role_query = $conn->query("SELECT * FROM roles WHERE id = $role_id AND estate_id = $estate_id LIMIT 1");
        if ($role_query && $role_query->num_rows > 0) {
            $role_data = $role_query->fetch_assoc();
            $role_title = $role_data['name'];
            $role_slug = $role_data['slug'];
        } else {
            $role_title = 'Staff';
            $role_slug = 'staff';
        }

        if (!empty($_POST['staff_id'])) {
            // Update Existing Staff
            $staff_id = intval($_POST['staff_id']);
            $get_uid = $conn->query("SELECT user_id FROM estate_staff WHERE id = $staff_id AND estate_id = $estate_id");
            if ($get_uid && $get_uid->num_rows > 0) {
                $uid = $get_uid->fetch_assoc()['user_id'];
                
                // Update users table
                $conn->query("UPDATE users SET first_name='$first_name', last_name='$last_name', name='$name', email='$email', phone='$phone', role='$role_slug' WHERE id=$uid");
                
                // Update estate_staff table
                $updates = "role_id=$role_id, role='$role_title', registration_date='$reg_date', status='$status', phone='$phone'";
                $image = handleUpload($_FILES['image']);
                if ($image) $updates .= ", image_path='$image'";
                
                if ($conn->query("UPDATE estate_staff SET $updates WHERE id=$staff_id AND estate_id = $estate_id")) {
                    $message = "Staff member updated successfully!";
                    logAudit($conn, "Staff Updated", "Staff Management", "Updated staff #$staff_id ($name) as $role_title");
                } else {
                    $message = "Error updating staff: " . $conn->error;
                    $message_type = "danger";
                }
            }
        } else {
            // Register New Staff
            $check = $conn->query("SELECT id FROM users WHERE email = '$email'");
            if ($check && $check->num_rows > 0) {
                $user_id = $check->fetch_assoc()['id'];
                $conn->query("UPDATE users SET role='$role_slug', first_name='$first_name', last_name='$last_name', name='$name', phone='$phone' WHERE id=$user_id");
            } else {
                $default_pass = !empty($first_name) ? trim($first_name) : 'staff123';
                $password = password_hash($default_pass, PASSWORD_DEFAULT);
                $conn->query("INSERT INTO users (estate_id, first_name, last_name, name, email, phone, password, role) VALUES ($estate_id, '$first_name', '$last_name', '$name', '$email', '$phone', '$password', '$role_slug')");
                $user_id = $conn->insert_id;
            }

            // Check if already active staff
            $checkStaff = $conn->query("SELECT id FROM estate_staff WHERE user_id = $user_id AND estate_id = $estate_id");
            if ($checkStaff->num_rows == 0) {
                $custom_id = generateCustomID($conn, 'estate_staff', 'EST');
                $image = handleUpload($_FILES['image']);
                
                $sql = "INSERT INTO estate_staff (estate_id, custom_id, user_id, role_id, role, phone, registration_date, image_path, status) 
                        VALUES ($estate_id, '$custom_id', $user_id, $role_id, '$role_title', '$phone', '$reg_date', " . ($image ? "'$image'" : "NULL") . ", '$status')";
                if ($conn->query($sql)) {
                    $message = "Staff member successfully registered!";
                    logAudit($conn, "Staff Created", "Staff Management", "Added new staff $name ($role_title)");
                } else {
                    $message = "Error creating staff: " . $conn->error;
                    $message_type = "danger";
                }
            } else {
                $message = "This user is already registered as an estate staff member.";
                $message_type = "warning";
            }
        }
        $active_tab = 'directory';
    } 
    // 2. ARCHIVE / INACTIVATE STAFF
    elseif (isset($_POST['archive_staff'])) {
        $id = intval($_POST['archive_id']);
        if ($conn->query("UPDATE estate_staff SET status='inactive' WHERE id=$id AND estate_id=$estate_id")) {
            $message = "Staff member archived (set to inactive)!";
            logAudit($conn, "Staff Archived", "Staff Management", "Archived staff ID: $id");
        } else {
            $message = "Error: " . $conn->error;
            $message_type = "danger";
        }
        $active_tab = 'directory';
    }
    // 3. ADD NEW CUSTOM ROLE
    elseif (isset($_POST['add_role'])) {
        $role_name = trim($conn->real_escape_string($_POST['role_name']));
        $role_desc = trim($conn->real_escape_string($_POST['role_desc']));
        
        if (!empty($role_name)) {
            $base_slug = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace([' ', '-'], '_', $role_name)));
            $slug = $base_slug;
            $counter = 1;
            while ($conn->query("SELECT id FROM roles WHERE estate_id = $estate_id AND slug = '$slug'")->num_rows > 0) {
                $slug = $base_slug . '_' . $counter++;
            }
            
            $sql = "INSERT INTO roles (estate_id, name, slug, description, is_system) VALUES ($estate_id, '$role_name', '$slug', '$role_desc', 0)";
            if ($conn->query($sql)) {
                $new_role_id = $conn->insert_id;
                
                // Assign selected permissions immediately if any checked
                if (isset($_POST['permissions']) && is_array($_POST['permissions'])) {
                    foreach ($_POST['permissions'] as $p_id) {
                        $p_id = intval($p_id);
                        $conn->query("INSERT INTO role_permissions (role_id, permission_id) VALUES ($new_role_id, $p_id)");
                    }
                }
                
                $message = "Custom Role '$role_name' created successfully!";
                logAudit($conn, "Role Created", "Staff Roles", "Created custom staff role '$role_name' ($slug)");
            } else {
                $message = "Error creating role: " . $conn->error;
                $message_type = "danger";
            }
        }
        $active_tab = 'roles';
    }
    // 4. UPDATE ROLE PERMISSIONS MATRIX
    elseif (isset($_POST['update_role_permissions'])) {
        $role_id = intval($_POST['role_id']);
        
        // Ensure role belongs to this estate
        $role_chk = $conn->query("SELECT name, is_system FROM roles WHERE id = $role_id AND estate_id = $estate_id");
        if ($role_chk && $role_chk->num_rows > 0) {
            $role_meta = $role_chk->fetch_assoc();
            
            $conn->query("DELETE FROM role_permissions WHERE role_id = $role_id");
            if (isset($_POST['permissions']) && is_array($_POST['permissions'])) {
                foreach ($_POST['permissions'] as $p_id) {
                    $p_id = intval($p_id);
                    $conn->query("INSERT INTO role_permissions (role_id, permission_id) VALUES ($role_id, $p_id)");
                }
            }
            $message = "Permissions for '" . htmlspecialchars($role_meta['name']) . "' updated successfully!";
            logAudit($conn, "Permissions Updated", "Staff Roles", "Updated permission matrix for role ID: $role_id");
        }
        $active_tab = 'roles';
    }
    // 5. DELETE CUSTOM ROLE
    elseif (isset($_POST['delete_role'])) {
        $role_id = intval($_POST['role_id']);
        $role_chk = $conn->query("SELECT name, is_system FROM roles WHERE id = $role_id AND estate_id = $estate_id");
        if ($role_chk && $role_chk->num_rows > 0) {
            $r_meta = $role_chk->fetch_assoc();
            if ($r_meta['is_system'] == 1) {
                $message = "System roles cannot be deleted.";
                $message_type = "danger";
            } else {
                // Check if any staff are currently assigned to this role
                $assigned_chk = $conn->query("SELECT COUNT(*) as cnt FROM estate_staff WHERE role_id = $role_id AND status = 'active'");
                $assigned_count = $assigned_chk ? $assigned_chk->fetch_assoc()['cnt'] : 0;
                
                if ($assigned_count > 0) {
                    $message = "Cannot delete role '" . htmlspecialchars($r_meta['name']) . "' because $assigned_count staff member(s) are currently assigned to it. Please reassign them first.";
                    $message_type = "danger";
                } else {
                    $conn->query("DELETE FROM role_permissions WHERE role_id = $role_id");
                    $conn->query("DELETE FROM roles WHERE id = $role_id AND estate_id = $estate_id");
                    $message = "Role '" . htmlspecialchars($r_meta['name']) . "' deleted successfully.";
                    logAudit($conn, "Role Deleted", "Staff Roles", "Deleted role ID: $role_id");
                }
            }
        }
        $active_tab = 'roles';
    }
}

// Fetch Staff Directory Data
$staff_list = $conn->query("SELECT s.*, u.first_name, u.last_name, u.name, u.email, r.name as role_name, r.slug as role_slug 
    FROM estate_staff s 
    JOIN users u ON s.user_id = u.id 
    LEFT JOIN roles r ON s.role_id = r.id 
    WHERE s.estate_id = $estate_id 
    ORDER BY s.status ASC, u.first_name ASC");

// Fetch Roles available for this estate (excluding resident and superadmin)
$roles_res = $conn->query("SELECT r.*, 
    (SELECT COUNT(*) FROM estate_staff es WHERE es.role_id = r.id AND es.status = 'active') as active_staff_count 
    FROM roles r 
    WHERE r.estate_id = $estate_id AND r.slug NOT IN ('resident', 'superadmin') 
    ORDER BY r.is_system DESC, r.name ASC");

$available_roles = [];
while ($r = $roles_res->fetch_assoc()) {
    $available_roles[] = $r;
}

// Fetch Grouped Permissions
$perms_res = $conn->query("SELECT * FROM permissions ORDER BY module ASC, name ASC");
$all_perms = [];
while ($p = $perms_res->fetch_assoc()) {
    $all_perms[$p['module']][] = $p;
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    /* Modern Tab Styles */
    .tab-nav {
        display: flex;
        gap: 0.5rem;
        border-bottom: 2px solid #e2e8f0;
        margin-bottom: 2rem;
    }
    .tab-btn {
        padding: 0.75rem 1.5rem;
        border: none;
        background: transparent;
        font-weight: 600;
        color: #64748b;
        cursor: pointer;
        border-bottom: 3px solid transparent;
        margin-bottom: -2px;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
    }
    .tab-btn:hover {
        color: var(--primary-color);
    }
    .tab-btn.active {
        color: var(--primary-color);
        border-bottom-color: var(--primary-color);
        background: #f8fafc;
        border-top-left-radius: 0.5rem;
        border-top-right-radius: 0.5rem;
    }
    .badge-count {
        background: #e2e8f0;
        color: #334155;
        padding: 2px 8px;
        border-radius: 9999px;
        font-size: 0.75rem;
    }
    .tab-btn.active .badge-count {
        background: var(--primary-color);
        color: #ffffff;
    }
    .role-matrix-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    }
</style>

<div class="page-header d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 font-bold text-slate-800 m-0">Estate Staff & Permissions</h1>
        <p class="text-secondary small mb-0">Manage estate team members, define custom staff roles, and configure granular permissions.</p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($active_tab == 'roles'): ?>
            <button class="btn btn-primary" onclick="openNewRoleModal()"><i class="fa-solid fa-plus me-1"></i> Create Custom Role</button>
        <?php else: ?>
            <button class="btn btn-primary" onclick="openStaffModal()"><i class="fa-solid fa-user-plus me-1"></i> Add Staff Member</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i> <?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Navigation Tabs -->
<div class="tab-nav">
    <a href="?tab=directory" class="tab-btn <?php echo ($active_tab == 'directory') ? 'active' : ''; ?>">
        <i class="fa-solid fa-users-gear"></i> Staff Directory 
        <span class="badge-count"><?php echo $staff_list ? $staff_list->num_rows : 0; ?></span>
    </a>
    <a href="?tab=roles" class="tab-btn <?php echo ($active_tab == 'roles') ? 'active' : ''; ?>">
        <i class="fa-solid fa-shield-halved"></i> Roles & Permission Matrix 
        <span class="badge-count"><?php echo count($available_roles); ?></span>
    </a>
</div>

<?php if ($active_tab == 'directory'): ?>
    <!-- TAB 1: STAFF DIRECTORY -->
    <div class="glass p-4 rounded-3 shadow-sm bg-white border">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold text-slate-800 m-0">Active Team Directory</h5>
            <input type="text" id="staffSearch" placeholder="Search staff by name, email or role..." class="form-control form-control-sm" style="max-width: 320px;" onkeyup="filterStaffTable()">
        </div>
        
        <div class="table-responsive">
            <table class="table align-middle" id="staffTable">
                <thead class="table-light">
                    <tr style="color: #64748b; font-size: 0.85rem; text-transform: uppercase;">
                        <th>ID</th>
                        <th>Staff Member</th>
                        <th>Assigned Role</th>
                        <th>Contact</th>
                        <th>Registration</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($staff_list && $staff_list->num_rows > 0): ?>
                        <?php while($row = $staff_list->fetch_assoc()): ?>
                        <tr style="<?php echo $row['status'] == 'inactive' ? 'opacity: 0.6;' : ''; ?>">
                            <td style="font-family: monospace; color: #64748b;"><?php echo htmlspecialchars($row['custom_id']); ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <?php if($row['image_path']): ?>
                                        <img src="<?php echo htmlspecialchars($row['image_path']); ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 50%; border: 2px solid #e2e8f0;">
                                    <?php else: ?>
                                        <div style="width: 40px; height: 40px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #94a3b8;">
                                            <i class="fa-solid fa-user"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="fw-bold text-slate-800"><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($row['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge" style="background: #e0f2fe; color: #0284c7; font-size: 0.8rem; font-weight: 600; padding: 0.35rem 0.65rem;">
                                    <i class="fa-solid fa-id-badge me-1"></i> <?php echo htmlspecialchars($row['role_name'] ?? $row['role']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="fw-semibold text-slate-800 small"><?php echo htmlspecialchars($row['phone'] ?: 'N/A'); ?></div>
                                <a href="generate_id?id=<?php echo $row['id']; ?>&type=estate_staff" target="_blank" class="small text-primary text-decoration-none fw-bold" style="font-size: 0.72rem;">
                                    <i class="fa-solid fa-id-card"></i> Print ID Card
                                </a>
                            </td>
                            <td class="small text-muted"><?php echo date('M d, Y', strtotime($row['registration_date'])); ?></td>
                            <td>
                                <span class="badge <?php echo $row['status'] == 'active' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'; ?> text-uppercase" style="font-size: 0.7rem; font-weight: 700;">
                                    <?php echo htmlspecialchars($row['status']); ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <button onclick='editStaff(<?php echo json_encode($row); ?>)' class="btn btn-sm btn-outline-primary me-1" title="Edit Staff Member">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                                <?php if($row['status'] != 'inactive'): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this staff member?');">
                                    <input type="hidden" name="archive_id" value="<?php echo $row['id']; ?>">
                                    <button type="submit" name="archive_staff" class="btn btn-sm btn-outline-danger" title="Archive / Deactivate">
                                        <i class="fa-solid fa-box-archive"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">No estate staff registered yet. Click "Add Staff Member" to get started.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>
    <!-- TAB 2: ROLES & PERMISSIONS MATRIX -->
    <div class="row g-4">
        <div class="col-12">
            <div class="alert alert-info py-2 px-3 mb-4 d-flex align-items-center gap-2 small">
                <i class="fa-solid fa-circle-info fs-5"></i>
                <div>
                    <strong>Custom Role Flexibility:</strong> Different estates have different operational models. Create roles tailored to your estate (e.g. <em>Gate Security</em>, <em>Finance Officer</em>, <em>Resident Registration</em>), check the modules you want them to control, and their Staff Portal will dynamically reflect only what you allow.
                </div>
            </div>

            <!-- Role Permissions Cards -->
            <?php foreach ($available_roles as $r): ?>
                <?php
                $r_id = $r['id'];
                $assigned_perms = [];
                $rp_res = $conn->query("SELECT permission_id FROM role_permissions WHERE role_id = $r_id");
                while ($rp = $rp_res->fetch_assoc()) {
                    $assigned_perms[] = $rp['permission_id'];
                }
                ?>
                <div class="role-matrix-card">
                    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
                        <div>
                            <div class="d-flex align-items-center gap-2">
                                <h4 class="h5 fw-bold text-slate-800 m-0"><?php echo htmlspecialchars($r['name']); ?></h4>
                                <span class="badge bg-light text-secondary border font-monospace" style="font-size: 0.75rem;">slug: <?php echo htmlspecialchars($r['slug']); ?></span>
                                <?php if ($r['is_system']): ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis" style="font-size: 0.7rem;">System Role</span>
                                <?php else: ?>
                                    <span class="badge bg-primary-subtle text-primary" style="font-size: 0.7rem;">Custom Role</span>
                                <?php endif; ?>
                                <span class="badge bg-secondary-subtle text-dark" style="font-size: 0.75rem;">
                                    <i class="fa-solid fa-users me-1"></i> <?php echo intval($r['active_staff_count']); ?> Active Staff
                                </span>
                            </div>
                            <p class="text-secondary small mb-0 mt-1"><?php echo htmlspecialchars($r['description'] ?: 'No description specified.'); ?></p>
                        </div>
                        
                        <?php if (!$r['is_system']): ?>
                            <form method="POST" onsubmit="return confirm('Delete this role? This cannot be undone.');">
                                <input type="hidden" name="role_id" value="<?php echo $r['id']; ?>">
                                <button type="submit" name="delete_role" class="btn btn-outline-danger btn-sm" title="Delete Role">
                                    <i class="fa-solid fa-trash-can me-1"></i> Delete Role
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="role_id" value="<?php echo $r['id']; ?>">
                        
                        <!-- Grouped Permission Checkboxes -->
                        <div class="row g-3 mb-3">
                            <?php 
                            $module_icons = [
                                'Visitors' => 'fa-shield-halved text-teal',
                                'Finance' => 'fa-coins text-success',
                                'Residents' => 'fa-users text-primary',
                                'Properties' => 'fa-building text-info',
                                'Maintenance' => 'fa-hammer text-warning',
                                'Staff' => 'fa-user-tie text-secondary',
                                'Settings' => 'fa-sliders text-dark'
                            ];
                            ?>
                            <?php foreach ($all_perms as $module_name => $module_perms): ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="p-3 rounded bg-light border h-100">
                                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                                            <span class="fw-bold small text-uppercase text-slate-700">
                                                <i class="fa-solid <?php echo $module_icons[$module_name] ?? 'fa-cube'; ?> me-1"></i>
                                                <?php echo htmlspecialchars($module_name); ?>
                                            </span>
                                            <?php if ($r['slug'] != 'admin'): ?>
                                                <button type="button" class="btn btn-link p-0 text-decoration-none small" style="font-size: 0.72rem;" onclick="toggleAllCheckboxes(this)">Toggle All</button>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex flex-column gap-2">
                                            <?php foreach ($module_perms as $p): ?>
                                                <label class="d-flex align-items-start gap-2 small cursor-pointer mb-0">
                                                    <input type="checkbox" name="permissions[]" value="<?php echo $p['id']; ?>" 
                                                        <?php echo in_array($p['id'], $assigned_perms) ? 'checked' : ''; ?> 
                                                        <?php echo ($r['slug'] == 'admin') ? 'checked disabled' : ''; ?> 
                                                        class="form-check-input mt-1 perm-box">
                                                    <div>
                                                        <div class="fw-semibold text-slate-800"><?php echo htmlspecialchars($p['name']); ?></div>
                                                        <div class="text-muted" style="font-size: 0.72rem;"><?php echo htmlspecialchars($p['description']); ?></div>
                                                    </div>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($r['slug'] != 'admin'): ?>
                            <div class="text-end">
                                <button type="submit" name="update_role_permissions" class="btn btn-success px-4 fw-semibold">
                                    <i class="fa-solid fa-floppy-disk me-1"></i> Save Permissions for <?php echo htmlspecialchars($r['name']); ?>
                                </button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Add / Edit Staff Modal -->
<div id="staff-modal" class="custom-modal-backdrop" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 1050; align-items: center; justify-content: center; padding: 1.5rem 1rem; overflow-y: auto;">
    <div class="modal-content bg-white rounded-4 shadow-lg p-4" style="max-width: 600px; width: 100%;">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3">
            <h4 class="h5 fw-bold text-slate-800 m-0" id="modal-title">Add Staff Member</h4>
            <button type="button" class="btn-close" onclick="closeStaffModal()"></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="staff_id" id="est_staff_id">
            
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">First Name</label>
                    <input type="text" name="first_name" id="est_first_name" required class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Last Name</label>
                    <input type="text" name="last_name" id="est_last_name" required class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Email Address</label>
                    <input type="email" name="email" id="est_email" required class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Phone Number</label>
                    <input type="text" name="phone" id="est_phone" class="form-control">
                </div>
                
                <div class="col-md-12">
                    <label class="form-label fw-semibold small">Assigned Role & Permissions</label>
                    <select name="role_id" id="est_role_id" required class="form-select">
                        <option value="">-- Select Role --</option>
                        <?php foreach ($available_roles as $r): ?>
                            <option value="<?php echo $r['id']; ?>">
                                <?php echo htmlspecialchars($r['name']); ?> (<?php echo htmlspecialchars($r['description'] ?: $r['slug']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted" style="font-size: 0.75rem;">Staff access to the portal is controlled strictly by this role's permissions.</small>
                </div>
                
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Registration Date</label>
                    <input type="date" name="registration_date" id="est_reg_date" value="<?php echo date('Y-m-d'); ?>" required class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Account Status</label>
                    <select name="status" id="est_status" class="form-select">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                
                <div class="col-md-12">
                    <label class="form-label fw-semibold small">Profile Picture</label>
                    <input type="file" name="image" accept="image/*" class="form-control" onchange="previewImage(this, 'staff_preview')">
                    <div class="mt-2 text-center">
                        <img id="staff_preview" src="" style="display: none; width: 70px; height: 70px; object-fit: cover; border-radius: 50%; border: 2px solid var(--primary-color);">
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                <button type="button" class="btn btn-light" onclick="closeStaffModal()">Cancel</button>
                <button type="submit" name="save_staff" class="btn btn-primary px-4 fw-semibold" id="est_btn">Save Staff</button>
            </div>
        </form>
    </div>
</div>

<!-- Create Custom Role Modal -->
<div id="role-modal" class="custom-modal-backdrop" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 1050; align-items: center; justify-content: center; padding: 1.5rem 1rem; overflow-y: auto;">
    <div class="modal-content bg-white rounded-4 shadow-lg p-4" style="max-width: 650px; width: 100%; max-height: 90vh; overflow-y: auto;">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3">
            <h4 class="h5 fw-bold text-slate-800 m-0">Create Custom Role</h4>
            <button type="button" class="btn-close" onclick="closeNewRoleModal()"></button>
        </div>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label fw-semibold small">Role Title</label>
                <input type="text" name="role_name" required placeholder="e.g. Gate Security, Finance Clerk, Resident Registrar" class="form-control">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold small">Role Description</label>
                <input type="text" name="role_desc" placeholder="Briefly describe what this staff role is responsible for" class="form-control">
            </div>

            <label class="form-label fw-semibold small mb-2">Initial Permissions for this Role</label>
            <div class="row g-2 mb-3">
                <?php foreach ($all_perms as $module_name => $module_perms): ?>
                    <div class="col-md-6">
                        <div class="p-2 rounded bg-light border">
                            <span class="fw-bold small text-uppercase text-secondary d-block mb-1"><?php echo htmlspecialchars($module_name); ?></span>
                            <?php foreach ($module_perms as $p): ?>
                                <label class="d-flex align-items-center gap-2 small cursor-pointer mb-1">
                                    <input type="checkbox" name="permissions[]" value="<?php echo $p['id']; ?>" class="form-check-input">
                                    <span><?php echo htmlspecialchars($p['name']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="d-flex justify-content-end gap-2 pt-3 border-top">
                <button type="button" class="btn btn-light" onclick="closeNewRoleModal()">Cancel</button>
                <button type="submit" name="add_role" class="btn btn-primary px-4 fw-semibold">Create Role</button>
            </div>
        </form>
    </div>
</div>

<script>
function openStaffModal() {
    const modal = document.getElementById('staff-modal');
    modal.style.display = 'flex';
    document.getElementById('est_staff_id').value = '';
    document.getElementById('est_first_name').value = '';
    document.getElementById('est_last_name').value = '';
    document.getElementById('est_email').value = '';
    document.getElementById('est_phone').value = '';
    document.getElementById('est_role_id').value = '';
    document.getElementById('est_reg_date').value = '<?php echo date('Y-m-d'); ?>';
    document.getElementById('est_status').value = 'active';
    document.getElementById('staff_preview').style.display = 'none';
    document.getElementById('modal-title').innerText = 'Add Staff Member';
    document.getElementById('est_btn').innerText = 'Register Staff';
}

function closeStaffModal() {
    document.getElementById('staff-modal').style.display = 'none';
}

function openNewRoleModal() {
    document.getElementById('role-modal').style.display = 'flex';
}

function closeNewRoleModal() {
    document.getElementById('role-modal').style.display = 'none';
}

function previewImage(input, previewId) {
    const preview = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'inline-block';
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function editStaff(data) {
    openStaffModal();
    document.getElementById('modal-title').innerText = 'Update Staff Member';
    document.getElementById('est_staff_id').value = data.id;
    document.getElementById('est_first_name').value = data.first_name;
    document.getElementById('est_last_name').value = data.last_name;
    document.getElementById('est_email').value = data.email;
    document.getElementById('est_phone').value = data.phone;
    if (data.role_id) {
        document.getElementById('est_role_id').value = data.role_id;
    }
    document.getElementById('est_reg_date').value = data.registration_date;
    document.getElementById('est_status').value = data.status || 'active';
    if(data.image_path) {
        document.getElementById('staff_preview').src = data.image_path;
        document.getElementById('staff_preview').style.display = 'inline-block';
    }
    document.getElementById('est_btn').innerText = 'Update Staff';
}

function toggleAllCheckboxes(btn) {
    const container = btn.closest('.p-3');
    const boxes = container.querySelectorAll('.perm-box:not(:disabled)');
    if (boxes.length === 0) return;
    const allChecked = Array.from(boxes).every(b => b.checked);
    boxes.forEach(b => b.checked = !allChecked);
}

function filterStaffTable() {
    const input = document.getElementById('staffSearch').value.toLowerCase();
    const rows = document.querySelectorAll('#staffTable tbody tr');
    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(input) ? '' : 'none';
    });
}
</script>

<?php include '../includes/footer.php'; ?>
