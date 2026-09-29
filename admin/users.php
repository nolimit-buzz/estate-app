<?php
// admin/users.php - Central User Accounts & Access Control Hub
require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/Mailer.php';
requireAdminAccess();

// Auto-ensure required tables and columns exist
EstateMailer::ensureDatabaseTables($conn);

$estate_id = get_estate_id();
$current_admin_id = intval($_SESSION['user_id'] ?? 0);

$message = '';
$message_type = 'success';
$reset_result = null; // Store details of freshly reset account for multi-channel dispatch modal

// -------------------------------------------------------------------------
// POST HANDLERS
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. TOGGLE ACCOUNT STATUS (Enable / Disable)
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
        $target_user_id = intval($_POST['user_id'] ?? 0);
        $new_status = ($_POST['new_status'] === 'disabled') ? 'disabled' : 'active';

        // Security check: cannot disable self
        if ($target_user_id === $current_admin_id) {
            $message = "Security restriction: You cannot deactivate your own administrative account.";
            $message_type = "danger";
        } else {
            // Check user exists
            $u_chk = $conn->query("SELECT id, name, email, role, status FROM users WHERE id = $target_user_id AND estate_id = $estate_id LIMIT 1");
            if ($u_chk && $u_row = $u_chk->fetch_assoc()) {
                // Prevent lower admins from deactivating superadmins
                if ($u_row['role'] === 'superadmin' && ($_SESSION['role'] ?? '') !== 'superadmin') {
                    $message = "Permission Denied: Only Super Administrators can deactivate another Superadmin.";
                    $message_type = "danger";
                } else {
                    $conn->query("UPDATE users SET status = '$new_status' WHERE id = $target_user_id AND estate_id = $estate_id");
                    
                    // Also update linked resident or estate_staff status if applicable
                    if ($u_row['role'] === 'resident') {
                        $res_status = ($new_status === 'disabled') ? 'inactive' : 'active';
                        $conn->query("UPDATE residents SET status = '$res_status' WHERE user_id = $target_user_id AND estate_id = $estate_id");
                    } elseif (in_array($u_row['role'], ['staff', 'security', 'accountant', 'technician'])) {
                        $conn->query("UPDATE estate_staff SET status = '$new_status' WHERE user_id = $target_user_id AND estate_id = $estate_id");
                    }

                    $action_label = ($new_status === 'disabled') ? 'Deactivated' : 'Reactivated';
                    logAudit($conn, "Account Status Changed", "User Security", "$action_label account for {$u_row['name']} (#$target_user_id, {$u_row['email']})");

                    $message = "Account for <strong>" . htmlspecialchars($u_row['name']) . "</strong> was successfully " . strtolower($action_label) . ".";
                }
            } else {
                $message = "Target user account was not found.";
                $message_type = "danger";
            }
        }
    }

    // 2. INSTANT ADMIN PASSWORD RESET
    if (isset($_POST['action']) && $_POST['action'] === 'admin_reset_password') {
        $target_user_id = intval($_POST['user_id'] ?? 0);
        $force_change = isset($_POST['force_change']) ? 1 : 0;
        $send_email = isset($_POST['send_email']) ? 1 : 0;
        $temp_password = trim($_POST['custom_password'] ?? '');

        // If no custom password supplied, auto-generate a secure temporary password
        if (empty($temp_password)) {
            $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%';
            $temp_password = 'Est#' . substr(str_shuffle($chars), 0, 6);
        }

        $u_chk = $conn->query("SELECT id, name, first_name, email, phone, role FROM users WHERE id = $target_user_id AND estate_id = $estate_id LIMIT 1");
        if ($u_chk && $u_row = $u_chk->fetch_assoc()) {
            $hash = password_hash($temp_password, PASSWORD_BCRYPT);
            
            $conn->query("UPDATE users SET password = '$hash', force_password_change = $force_change, password_changed_at = NOW() WHERE id = $target_user_id AND estate_id = $estate_id");

            // Format login destination
            $base_url = EstateMailer::getBaseUrl();
            $login_url = $base_url . "login";
            if ($u_row['role'] === 'resident') {
                $login_url = $base_url . "resident/login";
            } elseif ($u_row['role'] === 'zone_admin') {
                $login_url = $base_url . "zone/login";
            } elseif (in_array($u_row['role'], ['staff', 'security', 'accountant', 'technician'])) {
                $login_url = $base_url . "staff/login";
            }

            // Dispatch Email if requested
            $email_sent = false;
            if ($send_email && !empty($u_row['email'])) {
                $email_sent = EstateMailer::sendAdminPasswordResetNotification($conn, $u_row['email'], $u_row['name'], $temp_password, $login_url, $estate_id);
            }

            // Prepare pre-formatted WhatsApp & SMS Messages
            $clean_phone = preg_replace('/[^0-9]/', '', $u_row['phone'] ?? '');
            if (!empty($clean_phone) && strlen($clean_phone) === 11 && strpos($clean_phone, '0') === 0) {
                // Convert Nigerian local format 080... to 23480...
                $clean_phone = '234' . substr($clean_phone, 1);
            }

            $wa_message = "Hello {$u_row['name']},\nYour Estate Portal access password has been reset by Administration.\n\n"
                        . "📧 Login Email: {$u_row['email']}\n"
                        . "🔑 Temporary Password: {$temp_password}\n"
                        . "🌐 Login URL: {$login_url}\n\n"
                        . ($force_change ? "⚠️ Note: You will be required to change this password on your first sign-in." : "Please change your password after signing in.");

            $wa_link = "https://api.whatsapp.com/send?text=" . urlencode($wa_message) . (!empty($clean_phone) ? "&phone=" . $clean_phone : "");
            $sms_link = "sms:" . (!empty($clean_phone) ? $clean_phone : "") . "?body=" . urlencode($wa_message);

            logAudit($conn, "Password Reset by Admin", "User Security", "Admin reset password for {$u_row['name']} (#$target_user_id, {$u_row['email']})");

            $message = "Password for <strong>" . htmlspecialchars($u_row['name']) . "</strong> has been reset successfully!";
            
            $reset_result = [
                'user_id' => $u_row['id'],
                'name' => $u_row['name'],
                'email' => $u_row['email'],
                'phone' => $u_row['phone'],
                'clean_phone' => $clean_phone,
                'temp_password' => $temp_password,
                'force_change' => $force_change,
                'email_sent' => $email_sent,
                'wa_link' => $wa_link,
                'sms_link' => $sms_link,
                'message_text' => $wa_message
            ];
        } else {
            $message = "User not found or does not belong to this estate.";
            $message_type = "danger";
        }
    }
}

// -------------------------------------------------------------------------
// QUERY FILTERS & DATA FETCHING
// -------------------------------------------------------------------------
$filter_role = trim($_GET['role'] ?? 'all');
$filter_status = trim($_GET['status'] ?? 'all');
$search = trim($_GET['q'] ?? '');

$where = ["u.estate_id = $estate_id"];

if ($filter_role !== 'all' && !empty($filter_role)) {
    $esc_role = $conn->real_escape_string($filter_role);
    if ($esc_role === 'staff_group') {
        $where[] = "u.role IN ('staff', 'security', 'accountant', 'technician')";
    } else {
        $where[] = "u.role = '$esc_role'";
    }
}

if ($filter_status !== 'all' && !empty($filter_status)) {
    $esc_stat = $conn->real_escape_string($filter_status);
    $where[] = "u.status = '$esc_stat'";
}

if (!empty($search)) {
    $esc_q = $conn->real_escape_string($search);
    $where[] = "(u.name LIKE '%$esc_q%' OR u.email LIKE '%$esc_q%' OR u.phone LIKE '%$esc_q%')";
}

$where_sql = implode(' AND ', $where);

// Metrics
$kpi_res = $conn->query("SELECT 
    COUNT(*) as total_users,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users,
    SUM(CASE WHEN status = 'disabled' THEN 1 ELSE 0 END) as disabled_users,
    SUM(CASE WHEN force_password_change = 1 THEN 1 ELSE 0 END) as pending_force_reset
    FROM users WHERE estate_id = $estate_id");
$kpi = $kpi_res ? $kpi_res->fetch_assoc() : [];

// User accounts list
$users_query = "SELECT u.id, u.name, u.first_name, u.last_name, u.email, u.phone, u.role, u.status, 
                       u.force_password_change, u.password_changed_at, u.created_at, u.zone_id,
                       z.name as zone_name
                FROM users u
                LEFT JOIN zones z ON u.zone_id = z.id
                WHERE $where_sql
                ORDER BY u.id DESC";
$users_res = $conn->query($users_query);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
    <div>
        <div class="breadcrumb text-secondary small mb-1">
            <span>Security &amp; System</span>
            <i class="fa-solid fa-chevron-right separator mx-1" style="font-size: 0.65rem;"></i>
            <span class="active text-primary fw-bold">User Accounts &amp; Access Control</span>
        </div>
        <h1 class="page-title mb-0" style="font-size: 1.6rem; font-weight: 800;">User Accounts &amp; Security</h1>
        <p class="page-subtitle text-secondary mb-0" style="font-size: 0.85rem;">Central identity management, account deactivation, instant password reset, and multi-channel dispatch.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="residents" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-users me-1"></i> Residents
        </a>
        <a href="staff" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-user-gear me-1"></i> Estate Staff
        </a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert mature-card p-3 mb-4" style="background: <?php echo ($message_type === 'danger') ? 'rgba(239, 68, 68, 0.08)' : 'rgba(16, 185, 129, 0.08)'; ?>; border: 1px solid <?php echo ($message_type === 'danger') ? 'rgba(239, 68, 68, 0.3)' : 'rgba(16, 185, 129, 0.3)'; ?>; color: <?php echo ($message_type === 'danger') ? '#ef4444' : '#059669'; ?>; border-radius: 0.65rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fa-solid <?php echo ($message_type === 'danger') ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>" style="font-size: 1.1rem;"></i>
        <div style="font-weight: 500; font-size: 0.9rem;"><?php echo $message; ?></div>
    </div>
<?php endif; ?>

<!-- ==========================================
     EXECUTIVE KPI METRICS (4 PILLARS)
     ========================================== -->
<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Total System Accounts</span>
                    <div class="kpi-value"><?php echo number_format($kpi['total_users'] ?? 0); ?></div>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6;">
                    <i class="fa-solid fa-users"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>All Roles &amp; Portals</span>
                <span class="mature-badge mature-badge-sky">Registered</span>
            </div>
        </div>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Active Accounts</span>
                    <div class="kpi-value" style="color: #10b981;"><?php echo number_format($kpi['active_users'] ?? 0); ?></div>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Authorized Sign-in</span>
                <span class="mature-badge mature-badge-emerald">Live Access</span>
            </div>
        </div>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Deactivated Accounts</span>
                    <div class="kpi-value" style="color: #ef4444;"><?php echo number_format($kpi['disabled_users'] ?? 0); ?></div>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;">
                    <i class="fa-solid fa-user-slash"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Blocked from Sign-in</span>
                <span class="mature-badge mature-badge-rose">Disabled</span>
            </div>
        </div>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Forced Reset Pending</span>
                    <div class="kpi-value" style="color: #f59e0b;"><?php echo number_format($kpi['pending_force_reset'] ?? 0); ?></div>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b;">
                    <i class="fa-solid fa-key"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Must Change on Login</span>
                <span class="mature-badge mature-badge-amber">Action Req.</span>
            </div>
        </div>
    </div>
</div>

<!-- Filter Toolbar -->
<div class="mature-card p-3 mb-4">
    <form method="GET" class="row g-2 align-items-center">
        <div class="col-12 col-md-4">
            <div class="position-relative">
                <i class="fa-solid fa-magnifying-glass position-absolute text-muted" style="top: 50%; left: 0.85rem; transform: translateY(-50%); font-size: 0.85rem;"></i>
                <input type="text" name="q" class="form-control form-control-sm ps-5" placeholder="Search by name, email, or phone..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
        </div>

        <div class="col-6 col-md-3">
            <select name="role" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all" <?php echo ($filter_role === 'all') ? 'selected' : ''; ?>>All Roles</option>
                <option value="resident" <?php echo ($filter_role === 'resident') ? 'selected' : ''; ?>>Residents</option>
                <option value="staff_group" <?php echo ($filter_role === 'staff_group') ? 'selected' : ''; ?>>Staff &amp; Security</option>
                <option value="zone_admin" <?php echo ($filter_role === 'zone_admin') ? 'selected' : ''; ?>>Zone Administrators</option>
                <option value="admin" <?php echo ($filter_role === 'admin') ? 'selected' : ''; ?>>Central Administrators</option>
            </select>
        </div>

        <div class="col-6 col-md-3">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all" <?php echo ($filter_status === 'all') ? 'selected' : ''; ?>>All Statuses</option>
                <option value="active" <?php echo ($filter_status === 'active') ? 'selected' : ''; ?>>Active Only</option>
                <option value="disabled" <?php echo ($filter_status === 'disabled') ? 'selected' : ''; ?>>Deactivated Only</option>
            </select>
        </div>

        <div class="col-12 col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-primary w-100">
                <i class="fa-solid fa-filter me-1"></i> Filter
            </button>
            <?php if (!empty($search) || $filter_role !== 'all' || $filter_status !== 'all'): ?>
                <a href="users" class="btn btn-sm btn-outline-secondary" title="Reset Filters"><i class="fa-solid fa-rotate-left"></i></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Accounts Table Card -->
<div class="mature-card mb-4">
    <div class="mature-card-header d-flex justify-content-between align-items-center">
        <div>
            <h3 class="mature-card-title mb-0" style="font-size: 1.05rem; font-weight: 700;">
                <i class="fa-solid fa-shield-halved text-primary me-2"></i> All System Accounts
            </h3>
        </div>
        <span class="tech-chip">
            <i class="fa-solid fa-list-check me-1"></i> Showing: <?php echo $users_res ? $users_res->num_rows : 0; ?> Accounts
        </span>
    </div>

    <div class="mature-card-body p-0">
        <div class="table-responsive">
            <table class="table dashboard-table align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>User Profile</th>
                        <th>Role &amp; Jurisdiction</th>
                        <th>Account Status</th>
                        <th>Password Status</th>
                        <th>Registered Date</th>
                        <th style="text-align: right; min-width: 170px;">Security Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($users_res && $users_res->num_rows > 0): ?>
                        <?php while ($u = $users_res->fetch_assoc()): 
                            $is_active = ($u['status'] ?? 'active') === 'active';
                            $force_active = intval($u['force_password_change'] ?? 0) === 1;
                            $role = $u['role'] ?? 'resident';
                            $initials = strtoupper(substr($u['first_name'] ?: $u['name'], 0, 1) . substr($u['last_name'] ?: '', 0, 1));
                        ?>
                            <tr style="<?php echo !$is_active ? 'background: #fff1f2; opacity: 0.85;' : ''; ?>">
                                <td class="text-muted small">#<?php echo $u['id']; ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-chip" style="width: 36px; height: 36px; font-size: 0.85rem; background: <?php echo $is_active ? 'rgba(59, 130, 246, 0.12)' : 'rgba(239, 68, 68, 0.12)'; ?>; color: <?php echo $is_active ? '#2563eb' : '#ef4444'; ?>;">
                                            <?php echo $initials ?: '<i class="fa-solid fa-user"></i>'; ?>
                                        </div>
                                        <div>
                                            <div style="font-weight: 700; color: var(--text-color); font-size: 0.9rem;">
                                                <?php echo htmlspecialchars($u['name']); ?>
                                                <?php if ($u['id'] == $current_admin_id): ?>
                                                    <span class="badge bg-primary ms-1" style="font-size: 0.65rem;">You</span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size: 0.78rem; color: var(--text-muted);">
                                                <i class="fa-regular fa-envelope me-1"></i> <?php echo htmlspecialchars($u['email']); ?>
                                                <?php if (!empty($u['phone'])): ?>
                                                    &bull; <i class="fa-solid fa-phone me-1"></i> <?php echo htmlspecialchars($u['phone']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if (in_array($role, ['superadmin', 'admin', 'manager'])): ?>
                                        <span class="mature-badge mature-badge-amber"><i class="fa-solid fa-shield-halved me-1"></i> <?php echo ucfirst($role); ?></span>
                                    <?php elseif ($role === 'zone_admin'): ?>
                                        <span class="mature-badge mature-badge-sky"><i class="fa-solid fa-layer-group me-1"></i> Zone Admin</span>
                                        <?php if (!empty($u['zone_name'])): ?>
                                            <div class="text-secondary small mt-1" style="font-size: 0.72rem;"><?php echo htmlspecialchars($u['zone_name']); ?></div>
                                        <?php endif; ?>
                                    <?php elseif ($role === 'resident'): ?>
                                        <span class="mature-badge mature-badge-primary"><i class="fa-solid fa-house-user me-1"></i> Resident</span>
                                    <?php else: ?>
                                        <span class="mature-badge mature-badge-emerald"><i class="fa-solid fa-user-shield me-1"></i> <?php echo ucfirst($role); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($is_active): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" style="border-radius: 9999px; font-size: 0.75rem; font-weight: 600;">
                                            <i class="fa-solid fa-circle me-1" style="font-size: 0.45rem;"></i> Active
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1" style="border-radius: 9999px; font-size: 0.75rem; font-weight: 600;">
                                            <i class="fa-solid fa-circle-xmark me-1"></i> Deactivated
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($force_active): ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning px-2 py-1" style="border-radius: 9999px; font-size: 0.72rem; font-weight: 600;" title="User must choose a new password upon their next login">
                                            <i class="fa-solid fa-key me-1"></i> Must Change on Login
                                        </span>
                                    <?php else: ?>
                                        <span class="text-secondary small" style="font-size: 0.78rem;">
                                            <i class="fa-solid fa-check-double text-success me-1"></i> Standard Active
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-secondary small" style="font-size: 0.8rem;">
                                        <?php echo !empty($u['created_at']) ? date('M d, Y', strtotime($u['created_at'])) : '-'; ?>
                                    </span>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <div class="d-inline-flex align-items-center gap-1">
                                        <!-- Instant Password Reset Button -->
                                        <button type="button" class="btn btn-sm btn-outline-primary" style="padding: 0.28rem 0.6rem; font-size: 0.78rem;" onclick="openResetModal(<?php echo htmlspecialchars(json_encode($u)); ?>)" title="Reset Password Directly">
                                            <i class="fa-solid fa-key me-1"></i> Reset Pass
                                        </button>

                                        <!-- Toggle Status Button -->
                                        <?php if ($u['id'] != $current_admin_id): ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('<?php echo $is_active ? 'Deactivate this user account? They will be immediately blocked from signing in.' : 'Reactivate this user account?'; ?>');">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                <input type="hidden" name="new_status" value="<?php echo $is_active ? 'disabled' : 'active'; ?>">
                                                <?php if ($is_active): ?>
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" style="padding: 0.28rem 0.55rem; font-size: 0.78rem;" title="Deactivate Account">
                                                        <i class="fa-solid fa-user-slash"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" class="btn btn-sm btn-outline-success" style="padding: 0.28rem 0.55rem; font-size: 0.78rem;" title="Reactivate Account">
                                                        <i class="fa-solid fa-user-check"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-user-shield fs-1 d-block mb-2 opacity-30"></i>
                                No user accounts found matching your filter criteria.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ==========================================
     MODAL 1: ADMIN INSTANT PASSWORD RESET
     ========================================== -->
<div class="modal fade" id="adminResetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 480px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden;">
            <div class="modal-header bg-dark text-white border-0 py-3">
                <h5 class="modal-title fs-6 fw-bold">
                    <i class="fa-solid fa-key text-warning me-2"></i> Instant Admin Password Reset
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="admin_reset_password">
                    <input type="hidden" name="user_id" id="resetUserId" value="">

                    <div class="p-3 mb-3 rounded-3" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                        <div style="font-weight: 700; color: #0f172a; font-size: 0.95rem;" id="resetUserName">User Name</div>
                        <div style="font-size: 0.8rem; color: #64748b;" id="resetUserEmail">email@domain.com</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Temporary Password</label>
                        <div class="input-group">
                            <input type="text" name="custom_password" id="resetCustomPassword" class="form-control font-monospace" placeholder="Auto-generated if left empty">
                            <button class="btn btn-outline-secondary" type="button" onclick="generateRandomPassword()" title="Generate Random Password">
                                <i class="fa-solid fa-dice me-1"></i> Generate
                            </button>
                        </div>
                        <small class="text-muted" style="font-size: 0.74rem;">Leave blank to automatically generate a secure temporary password.</small>
                    </div>

                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" name="force_change" id="resetForceChange" value="1" checked>
                        <label class="form-check-label small fw-bold" for="resetForceChange">
                            Force Password Change on Next Login
                        </label>
                        <div class="text-muted small" style="font-size: 0.72rem;">User will be blocked from accessing their dashboard until they establish a new private password.</div>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" name="send_email" id="resetSendEmail" value="1" checked>
                        <label class="form-check-label small fw-bold" for="resetSendEmail">
                            Dispatch Automated Email Notification
                        </label>
                        <div class="text-muted small" style="font-size: 0.72rem;">Sends the temporary credentials and login link via SMTP.</div>
                    </div>

                    <div class="alert alert-info py-2 px-3 small mb-0" style="font-size: 0.78rem;">
                        <i class="fa-solid fa-info-circle me-1"></i> You will also be given instant <strong>WhatsApp</strong> and <strong>SMS</strong> 1-click delivery links after confirming.
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary px-4 fw-bold">
                        <i class="fa-solid fa-check me-1"></i> Confirm &amp; Reset
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==========================================
     MODAL 2: FRESHLY RESET MULTI-CHANNEL DISPATCH
     ========================================== -->
<?php if ($reset_result): ?>
<div class="modal fade show" id="dispatchResultModal" tabindex="-1" style="display: block; background: rgba(0,0,0,0.6);" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 500px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden;">
            <div class="modal-header bg-success text-white border-0 py-3">
                <h5 class="modal-title fs-6 fw-bold">
                    <i class="fa-solid fa-circle-check me-2"></i> Password Successfully Reset!
                </h5>
                <button type="button" class="btn-close btn-close-white" onclick="closeDispatchModal()" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p style="font-size: 0.88rem; color: #334155; margin-bottom: 1rem;">
                    Temporary credentials have been set for <strong><?php echo htmlspecialchars($reset_result['name']); ?></strong>:
                </p>

                <!-- Monospace Credential Box -->
                <div class="p-3 mb-3 rounded-3" style="background: #f8fafc; border: 1px dashed #94a3b8; text-align: center;">
                    <span class="text-muted small d-block mb-1" style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px;">Temporary Password</span>
                    <div style="font-size: 1.4rem; font-weight: 800; font-family: monospace; color: #dc2626; letter-spacing: 1px;" id="dispTempPass">
                        <?php echo htmlspecialchars($reset_result['temp_password']); ?>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2 py-1 px-3" onclick="copyTemporaryPassword('<?php echo htmlspecialchars($reset_result['temp_password']); ?>', this)">
                        <i class="fa-solid fa-copy me-1"></i> Copy Password
                    </button>
                </div>

                <?php if ($reset_result['email_sent']): ?>
                    <div class="d-flex align-items-center gap-2 p-2 mb-3 rounded-2 text-success small" style="background: #ecfdf5; border: 1px solid #a7f3d0;">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>Email dispatched via SMTP to <strong><?php echo htmlspecialchars($reset_result['email']); ?></strong></span>
                    </div>
                <?php endif; ?>

                <div style="font-size: 0.82rem; font-weight: 700; color: #0f172a; margin-bottom: 0.5rem;">
                    Instant Multi-Channel Dispatch:
                </div>

                <div class="d-grid gap-2">
                    <!-- 1-Click WhatsApp Button -->
                    <a href="<?php echo htmlspecialchars($reset_result['wa_link']); ?>" target="_blank" class="btn btn-success fw-bold py-2">
                        <i class="fa-brands fa-whatsapp fs-5 me-1"></i> Send via WhatsApp
                    </a>

                    <!-- Direct SMS Button -->
                    <a href="<?php echo htmlspecialchars($reset_result['sms_link']); ?>" class="btn btn-outline-dark fw-bold py-2">
                        <i class="fa-solid fa-comment-sms fs-5 me-1"></i> Open SMS Messenger
                    </a>

                    <!-- Copy Full SMS Text Button -->
                    <button type="button" class="btn btn-outline-secondary py-2" onclick="copyFullMessageText(this)">
                        <i class="fa-solid fa-clipboard me-1"></i> Copy Full Text to Clipboard
                    </button>
                </div>

                <div class="mt-3 text-center text-muted small" style="font-size: 0.74rem;">
                    <?php if ($reset_result['force_change']): ?>
                        <span class="text-warning-emphasis fw-bold"><i class="fa-solid fa-triangle-exclamation"></i> Forced Reset:</span> User will be required to change this password immediately upon their first sign-in.
                    <?php endif; ?>
                </div>

                <textarea id="hiddenFullMsg" style="position: absolute; left: -9999px;"><?php echo htmlspecialchars($reset_result['message_text']); ?></textarea>
            </div>
            <div class="modal-footer bg-light border-0 py-2">
                <button type="button" class="btn btn-sm btn-primary w-100" onclick="closeDispatchModal()">Done &amp; Close</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    function openResetModal(userData) {
        document.getElementById('resetUserId').value = userData.id;
        document.getElementById('resetUserName').textContent = userData.name + ' (' + userData.role + ')';
        document.getElementById('resetUserEmail').textContent = userData.email + (userData.phone ? ' • ' + userData.phone : '');
        document.getElementById('resetCustomPassword').value = '';
        
        const modal = new bootstrap.Modal(document.getElementById('adminResetModal'));
        modal.show();
    }

    function generateRandomPassword() {
        const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%';
        let pass = 'Est#';
        for (let i = 0; i < 6; i++) {
            pass += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        document.getElementById('resetCustomPassword').value = pass;
    }

    function closeDispatchModal() {
        const modalEl = document.getElementById('dispatchResultModal');
        if (modalEl) modalEl.remove();
    }

    function copyTemporaryPassword(text, btn) {
        navigator.clipboard.writeText(text).then(() => {
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-check text-success me-1"></i> Copied!';
            setTimeout(() => { btn.innerHTML = orig; }, 2000);
        });
    }

    function copyFullMessageText(btn) {
        const text = document.getElementById('hiddenFullMsg').value;
        navigator.clipboard.writeText(text).then(() => {
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-check text-success me-1"></i> Copied Full Message!';
            setTimeout(() => { btn.innerHTML = orig; }, 2000);
        });
    }
</script>

<?php include '../includes/footer.php'; ?>
