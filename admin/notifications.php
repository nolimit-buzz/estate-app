<?php
// admin/notifications.php - Central Administrator Action & Notification Center
require_once '../config.php';
require_once '../includes/auth_guard.php';

requireLogin();
if (!isAdminRole()) {
    header("Location: ../index");
    exit;
}

$estate_id = get_estate_id();
$user_id = intval($_SESSION['user_id'] ?? 0);
$message = '';
$message_type = 'success';

// Handle Action: Mark All as Read
if (isset($_POST['mark_all_read'])) {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $user_id AND estate_id = $estate_id");
    $message = "All notifications have been marked as read.";
}

// Handle Action: Toggle Single Notification Read Status
if (isset($_GET['toggle_read']) && isset($_GET['id'])) {
    $notif_id = intval($_GET['id']);
    $conn->query("UPDATE notifications SET is_read = IF(is_read = 1, 0, 1) WHERE id = $notif_id AND user_id = $user_id AND estate_id = $estate_id");
    header("Location: notifications" . (!empty($_GET['filter']) ? '?filter=' . urlencode($_GET['filter']) : ''));
    exit;
}

// Handle Action: Delete Single Notification
if (isset($_GET['delete_notif']) && isset($_GET['id'])) {
    $notif_id = intval($_GET['id']);
    $conn->query("DELETE FROM notifications WHERE id = $notif_id AND user_id = $user_id AND estate_id = $estate_id");
    $message = "Notification deleted.";
}

// Handle Inline Contact Change Approval / Rejection directly from Notifications Center
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (isset($_POST['approve_contact_change'])) {
        $req_id = intval($_POST['request_id']);
        $admin_notes = $conn->real_escape_string(trim($_POST['admin_notes'] ?? 'Approved via Action Center'));

        $req = $conn->query("SELECT * FROM contact_change_requests WHERE id = $req_id AND estate_id = $estate_id AND status = 'pending'")->fetch_assoc();
        if ($req) {
            $t_uid = intval($req['user_id']);
            $new_e = trim($req['requested_email'] ?? '');
            $new_p = trim($req['requested_phone'] ?? '');

            $u_updates = [];
            if (!empty($new_e)) {
                if (function_exists('isEmailTakenInEstate') && ($taken = isEmailTakenInEstate($conn, $new_e, $estate_id, $t_uid))) {
                    $message = "Cannot Approve: Email '$new_e' is already in use by " . htmlspecialchars($taken['name']) . ".";
                    $message_type = 'danger';
                } else {
                    $u_updates[] = "email = '" . $conn->real_escape_string($new_e) . "'";
                }
            }
            if (!empty($new_p) && $message_type !== 'danger') {
                if (function_exists('isPhoneTakenInEstate') && ($taken = isPhoneTakenInEstate($conn, $new_p, $estate_id, $t_uid))) {
                    $message = "Cannot Approve: Phone '$new_p' is already in use by " . htmlspecialchars($taken['name']) . ".";
                    $message_type = 'danger';
                } else {
                    $u_updates[] = "phone = '" . $conn->real_escape_string($new_p) . "'";
                }
            }

            if ($message_type !== 'danger' && !empty($u_updates)) {
                $conn->query("UPDATE users SET " . implode(', ', $u_updates) . " WHERE id = $t_uid AND estate_id = $estate_id");
                $conn->query("UPDATE contact_change_requests SET status = 'approved', reviewed_by = $user_id, reviewed_at = NOW(), admin_notes = '$admin_notes' WHERE id = $req_id");

                // Mark the notification as read
                $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $user_id AND type = 'resident_request'");

                // Notify Resident
                $notif_txt = $conn->real_escape_string("Your contact info update request has been APPROVED by Central Administration.");
                $conn->query("INSERT INTO notifications (estate_id, user_id, title, message, type, is_read, created_at) VALUES ($estate_id, $t_uid, 'Contact Details Updated', '$notif_txt', 'contact_approved', 0, NOW())");

                if (function_exists('logAudit')) {
                    logAudit($conn, "Contact Change Approved", "Action Center", "Approved request #$req_id for User #$t_uid");
                }
                $message = "Contact update request approved and applied successfully!";
            }
        }
    } elseif (isset($_POST['reject_contact_change'])) {
        $req_id = intval($_POST['request_id']);
        $admin_notes = $conn->real_escape_string(trim($_POST['admin_notes'] ?? 'Declined by Administrator'));

        $req = $conn->query("SELECT * FROM contact_change_requests WHERE id = $req_id AND estate_id = $estate_id AND status = 'pending'")->fetch_assoc();
        if ($req) {
            $t_uid = intval($req['user_id']);
            $conn->query("UPDATE contact_change_requests SET status = 'rejected', reviewed_by = $user_id, reviewed_at = NOW(), admin_notes = '$admin_notes' WHERE id = $req_id");
            $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $user_id AND type = 'resident_request'");

            // Notify Resident
            $notif_txt = $conn->real_escape_string("Your contact change request was declined: $admin_notes");
            $conn->query("INSERT INTO notifications (estate_id, user_id, title, message, type, is_read, created_at) VALUES ($estate_id, $t_uid, 'Contact Change Request Declined', '$notif_txt', 'contact_rejected', 0, NOW())");

            if (function_exists('logAudit')) {
                logAudit($conn, "Contact Change Rejected", "Action Center", "Rejected request #$req_id for User #$t_uid. Reason: $admin_notes");
            }
            $message = "Contact update request rejected.";
        }
    }
}

// Fetch Pending Action Requests: Contact Change Requests
$pending_contact_reqs_query = $conn->query("
    SELECT ccr.*, u.name as resident_name, u.role, r.custom_id as res_code, f.number as flat_number, b.name as building_name, z.name as zone_name
    FROM contact_change_requests ccr
    JOIN users u ON ccr.user_id = u.id
    LEFT JOIN residents r ON ccr.resident_id = r.id OR (r.user_id = u.id AND r.estate_id = ccr.estate_id)
    LEFT JOIN flats f ON r.flat_id = f.id
    LEFT JOIN buildings b ON f.building_id = b.id
    LEFT JOIN streets s ON b.street_id = s.id
    LEFT JOIN zones z ON s.zone_id = z.id OR ccr.zone_id = z.id
    WHERE ccr.estate_id = $estate_id AND ccr.status = 'pending'
    GROUP BY ccr.id
    ORDER BY ccr.id DESC
");
$pending_contact_requests = [];
if ($pending_contact_reqs_query) {
    while ($p_row = $pending_contact_reqs_query->fetch_assoc()) {
        $pending_contact_requests[] = $p_row;
    }
}
$pending_requests_count = count($pending_contact_requests);

// Filter Selection - Broadcasts remain strictly in Broadcasts Hub, NOT merged into Notifications
$filter = $_GET['filter'] ?? 'all';
$where_filter = "user_id = $user_id AND estate_id = $estate_id AND type NOT IN ('estate_broadcast', 'zone_notice')";
if ($filter === 'unread') {
    $where_filter .= " AND is_read = 0";
} elseif ($filter === 'requests') {
    $where_filter .= " AND type IN ('resident_request', 'contact_approved', 'contact_rejected')";
} elseif ($filter === 'emergencies') {
    $where_filter .= " AND (type LIKE '%emergency%' OR title LIKE '%emergency%' OR title LIKE '%SOS%')";
}

// Counts (Notifications only, excluding broadcasts)
$total_unread = $conn->query("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = $user_id AND estate_id = $estate_id AND is_read = 0 AND type NOT IN ('estate_broadcast', 'zone_notice')")->fetch_assoc()['cnt'] ?? 0;
$total_all = $conn->query("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = $user_id AND estate_id = $estate_id AND type NOT IN ('estate_broadcast', 'zone_notice')")->fetch_assoc()['cnt'] ?? 0;

// Query Notifications List
$notifications_query = $conn->query("SELECT * FROM notifications WHERE $where_filter ORDER BY is_read ASC, created_at DESC LIMIT 100");

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Header Section -->
<div class="page-header-futuristic mb-4">
    <div>
        <div class="header-breadcrumbs">
            <a href="index">Dashboard</a>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span>System</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span class="active">Notifications &amp; Action Center</span>
        </div>
        <h1 class="page-title">
            <i class="fa-solid fa-bell text-primary me-2"></i>Notifications &amp; Action Center
        </h1>
        <p class="page-subtitle">Real-time alerts, pending resident authorization requests, and administrative shortcuts.</p>
    </div>
    <div class="header-actions d-flex gap-2">
        <a href="broadcasts" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1.5 fw-semibold">
            <i class="fa-solid fa-bullhorn text-primary"></i> Broadcasts &amp; Notices
        </a>
        <a href="residents?tab=contact_requests" class="btn btn-sm btn-outline-warning d-flex align-items-center gap-1.5 fw-semibold">
            <i class="fa-solid fa-id-card-clip"></i> Contact Requests Registry
            <?php if ($pending_requests_count > 0): ?>
                <span class="badge bg-warning text-dark rounded-pill"><?php echo $pending_requests_count; ?></span>
            <?php endif; ?>
        </a>
        <?php if ($total_unread > 0): ?>
            <form method="POST" style="margin: 0;">
                <button type="submit" name="mark_all_read" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1">
                    <i class="fa-solid fa-check-double"></i> Mark All as Read
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show rounded-3 shadow-sm mb-4" role="alert">
        <div class="d-flex align-items-center gap-2">
            <i class="fa-solid <?php echo $message_type === 'danger' ? 'fa-circle-exclamation' : 'fa-circle-check'; ?> fs-5"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- =========================================================================
     ACTION REQUIRED SECTION: PENDING RESIDENT REQUESTS (HIGH PRIORITY)
     ========================================================================= -->
<?php if ($pending_requests_count > 0): ?>
    <div class="mature-card mb-4 border-warning shadow-sm" style="border-left: 5px solid #f59e0b; background: #fffcf5;">
        <div class="p-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2" style="background: rgba(245, 158, 11, 0.08);">
            <div class="d-flex align-items-center gap-2.5">
                <div style="width: 38px; height: 38px; border-radius: 10px; background: #fef3c7; display: flex; align-items: center; justify-content: center; color: #b45309; font-size: 1.1rem; box-shadow: 0 2px 4px rgba(217, 119, 6, 0.15);">
                    <i class="fa-solid fa-hourglass-half fa-spin-pulse"></i>
                </div>
                <div>
                    <h5 class="fw-bold text-slate-900 m-0" style="font-size: 1rem;">
                        Action Required: <?php echo $pending_requests_count; ?> Pending Contact Change Request(s)
                    </h5>
                    <div class="small text-muted">Residents waiting for authorized email or phone modification</div>
                </div>
            </div>
            <a href="residents?tab=contact_requests" class="btn btn-sm btn-warning text-dark fw-semibold px-3 rounded-pill shadow-sm">
                View in Residents Registry <i class="fa-solid fa-arrow-right ms-1"></i>
            </a>
        </div>

        <div class="p-3">
            <div class="row g-3">
                <?php foreach ($pending_contact_requests as $preq): ?>
                    <div class="col-12 col-lg-6">
                        <div class="p-3 rounded-3 border bg-white shadow-sm h-100 d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <span class="badge bg-warning text-dark mb-1 font-monospace">REQ-#<?php echo $preq['id']; ?></span>
                                        <h6 class="fw-bold text-slate-900 mb-0"><?php echo htmlspecialchars($preq['resident_name']); ?></h6>
                                        <div class="small text-muted">
                                            Flat <?php echo htmlspecialchars($preq['flat_number'] ?? 'N/A'); ?> &bull; 
                                            <?php echo htmlspecialchars($preq['zone_name'] ?? 'Main Estate'); ?>
                                        </div>
                                    </div>
                                    <span class="small text-muted font-monospace" style="font-size: 0.75rem;">
                                        <?php echo date('M d, H:i', strtotime($preq['created_at'])); ?>
                                    </span>
                                </div>

                                <div class="p-2.5 rounded-2 mb-2" style="background: #f8fafc; font-size: 0.83rem;">
                                    <?php if (!empty($preq['requested_email'])): ?>
                                        <div class="d-flex justify-content-between py-1 border-bottom">
                                            <span class="text-secondary">New Email:</span>
                                            <strong class="text-primary font-monospace"><?php echo htmlspecialchars($preq['requested_email']); ?></strong>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($preq['requested_phone'])): ?>
                                        <div class="d-flex justify-content-between py-1">
                                            <span class="text-secondary">New Phone:</span>
                                            <strong class="text-success font-monospace"><?php echo htmlspecialchars($preq['requested_phone']); ?></strong>
                                        </div>
                                    <?php endif; ?>
                                    <div class="pt-1.5 mt-1 border-top text-secondary small">
                                        <em>Reason: <?php echo htmlspecialchars($preq['reason'] ?: 'None specified'); ?></em>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                                <form method="POST" style="margin: 0;" onsubmit="return confirm('Approve contact update for <?php echo addslashes($preq['resident_name']); ?>?');">
                                    <input type="hidden" name="request_id" value="<?php echo $preq['id']; ?>">
                                    <button type="submit" name="approve_contact_change" class="btn btn-sm btn-success px-3 fw-semibold rounded-pill shadow-sm">
                                        <i class="fa-solid fa-check me-1"></i> Approve
                                    </button>
                                </form>
                                <button type="button" class="btn btn-sm btn-outline-danger px-3 rounded-pill" onclick="openRejectModal(<?php echo $preq['id']; ?>, '<?php echo addslashes($preq['resident_name']); ?>')">
                                    <i class="fa-solid fa-xmark me-1"></i> Reject
                                </button>
                                <a href="residents?tab=contact_requests" class="btn btn-sm btn-outline-secondary px-2.5 rounded-pill" title="View Full Details">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- =========================================================================
     NOTIFICATIONS FEED & FILTER TABS
     ========================================================================= -->
<div class="mature-card">
    <div class="mature-card-header d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
        <div>
            <h3 class="mature-card-title">
                <i class="fa-solid fa-clock-rotate-left text-secondary"></i> System Alerts &amp; Activity Stream
            </h3>
            <p style="margin: 0.2rem 0 0; font-size: 0.8rem; color: var(--text-muted);">
                Showing <?php echo $notifications_query ? $notifications_query->num_rows : 0; ?> recent notification(s) for your administrator profile.
            </p>
        </div>

        <!-- Filter Pills -->
        <div class="d-flex flex-wrap gap-2">
            <a href="notifications?filter=all" class="btn btn-sm <?php echo $filter === 'all' ? 'btn-primary text-white' : 'btn-outline-secondary'; ?> rounded-pill px-3 py-1">
                All (<?php echo $total_all; ?>)
            </a>
            <a href="notifications?filter=unread" class="btn btn-sm <?php echo $filter === 'unread' ? 'btn-primary text-white' : 'btn-outline-secondary'; ?> rounded-pill px-3 py-1">
                Unread <?php if ($total_unread > 0): ?><span class="badge bg-danger rounded-pill ms-1"><?php echo $total_unread; ?></span><?php endif; ?>
            </a>
            <a href="notifications?filter=requests" class="btn btn-sm <?php echo $filter === 'requests' ? 'btn-primary text-white' : 'btn-outline-secondary'; ?> rounded-pill px-3 py-1">
                <i class="fa-solid fa-id-card-clip me-1"></i> Requests
            </a>
            <a href="notifications?filter=emergencies" class="btn btn-sm <?php echo $filter === 'emergencies' ? 'btn-danger text-white' : 'btn-outline-secondary'; ?> rounded-pill px-3 py-1">
                <i class="fa-solid fa-triangle-exclamation me-1"></i> Emergencies
            </a>
        </div>
    </div>

    <div class="mature-card-body p-0">
        <?php if ($notifications_query && $notifications_query->num_rows > 0): ?>
            <div class="list-group list-group-flush">
                <?php while ($notif = $notifications_query->fetch_assoc()): 
                    $is_unread = ($notif['is_read'] == 0);
                    $ntype = $notif['type'] ?? 'system';
                    $title = $notif['title'] ?? 'Notification';
                    
                    // Determine Icon, Colors and Shortcut Link
                    $icon = 'fa-bell';
                    $icon_bg = 'rgba(59, 130, 246, 0.12)';
                    $icon_color = '#2563eb';
                    $shortcut_url = '';
                    $shortcut_text = '';

                    if ($ntype === 'resident_request' || stripos($title, 'Contact Change') !== false) {
                        $icon = 'fa-id-card-clip';
                        $icon_bg = 'rgba(245, 158, 11, 0.15)';
                        $icon_color = '#d97706';
                        $shortcut_url = 'residents?tab=contact_requests';
                        $shortcut_text = 'Review Contact Request';
                    } elseif (stripos($ntype, 'emergency') !== false || stripos($title, 'emergency') !== false || stripos($title, 'SOS') !== false) {
                        $icon = 'fa-triangle-exclamation';
                        $icon_bg = 'rgba(239, 68, 68, 0.15)';
                        $icon_color = '#dc2626';
                        $shortcut_url = 'emergency';
                        $shortcut_text = 'Open Emergency Hub';
                    } elseif ($ntype === 'estate_broadcast' || $ntype === 'zone_notice') {
                        $icon = 'fa-bullhorn';
                        $icon_bg = 'rgba(168, 85, 247, 0.15)';
                        $icon_color = '#7e22ce';
                        $shortcut_url = 'broadcasts';
                        $shortcut_text = 'View Broadcasts';
                    } elseif ($ntype === 'artisan_registration' || stripos($title, 'Artisan') !== false) {
                        $icon = 'fa-user-check';
                        $icon_bg = 'rgba(245, 158, 11, 0.15)';
                        $icon_color = '#d97706';
                        $shortcut_url = 'artisans?tab=pending';
                        $shortcut_text = 'Verify Artisan Onboarding';
                    } elseif (stripos($title, 'Maintenance') !== false) {
                        $icon = 'fa-screwdriver-wrench';
                        $icon_bg = 'rgba(14, 165, 233, 0.15)';
                        $icon_color = '#0284c7';
                        $shortcut_url = 'maintenance';
                        $shortcut_text = 'Maintenance Dispatch';
                    } elseif (stripos($title, 'Visitor') !== false) {
                        $icon = 'fa-shield-halved';
                        $icon_bg = 'rgba(16, 185, 129, 0.15)';
                        $icon_color = '#059669';
                        $shortcut_url = 'security';
                        $shortcut_text = 'Visitor Security';
                    }
                ?>
                    <div class="list-group-item p-3.5 d-flex align-items-start gap-3 transition-all" style="overflow: hidden; <?php echo $is_unread ? 'background: rgba(248, 250, 252, 0.9); border-left: 4px solid var(--primary-color, #2563eb);' : 'background: #fff; opacity: 0.85;'; ?>">
                        <!-- Icon Avatar -->
                        <div class="glass-icon-circle hero-icon-circle" style="width: 44px; height: 44px; min-width: 44px; min-height: 44px; border-radius: 50% !important; aspect-ratio: 1 / 1 !important; flex-shrink: 0 !important; background: <?php echo $icon_bg; ?>; color: <?php echo $icon_color; ?>; display: inline-flex; align-items: center; justify-content: center; font-size: 1.15rem; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border: 1px solid rgba(0,0,0,0.05);">
                            <i class="fa-solid <?php echo $icon; ?>"></i>
                        </div>

                        <!-- Content & Message -->
                        <div class="flex-grow-1" style="min-width: 0; overflow: hidden; word-break: break-word; overflow-wrap: anywhere;">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
                                <div class="d-flex align-items-center gap-2">
                                    <h6 class="mb-0 fw-bold <?php echo $is_unread ? 'text-slate-900' : 'text-slate-700'; ?>" style="font-size: 0.95rem;">
                                        <?php echo htmlspecialchars($title); ?>
                                    </h6>
                                    <?php if ($is_unread): ?>
                                        <span class="badge bg-primary rounded-pill px-2 py-0.5" style="font-size: 0.68rem;">New</span>
                                    <?php endif; ?>
                                </div>
                                <span class="small text-muted font-monospace" style="font-size: 0.75rem;">
                                    <i class="fa-regular fa-clock me-1"></i><?php echo date('M d, Y h:i A', strtotime($notif['created_at'])); ?>
                                </span>
                            </div>

                            <p class="mb-2 text-slate-700" style="font-size: 0.88rem; line-height: 1.55;">
                                <?php echo nl2br(htmlspecialchars($notif['message'])); ?>
                            </p>

                            <!-- Action Shortcuts & Controls -->
                            <div class="d-flex align-items-center gap-2 flex-wrap pt-1">
                                <?php if (!empty($shortcut_url)): ?>
                                    <a href="<?php echo $shortcut_url; ?>" class="btn btn-sm btn-primary px-3 py-1 fw-semibold rounded-pill shadow-sm" style="font-size: 0.78rem;">
                                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> <?php echo $shortcut_text; ?>
                                    </a>
                                <?php endif; ?>

                                <a href="notifications?toggle_read=1&id=<?php echo $notif['id']; ?>&filter=<?php echo urlencode($filter); ?>" class="btn btn-sm btn-outline-secondary px-2.5 py-1 rounded-pill" style="font-size: 0.75rem;" title="<?php echo $is_unread ? 'Mark as Read' : 'Mark as Unread'; ?>">
                                    <i class="fa-solid <?php echo $is_unread ? 'fa-check' : 'fa-envelope'; ?> me-1"></i>
                                    <?php echo $is_unread ? 'Mark Read' : 'Mark Unread'; ?>
                                </a>

                                <a href="notifications?delete_notif=1&id=<?php echo $notif['id']; ?>&filter=<?php echo urlencode($filter); ?>" onclick="return confirm('Delete this notification?');" class="btn btn-sm btn-outline-danger px-2 py-1 rounded-pill" style="font-size: 0.75rem;" title="Delete Notification">
                                    <i class="fa-solid fa-trash-can"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-5">
                <div style="width: 60px; height: 60px; border-radius: 50%; background: #f1f5f9; color: #94a3b8; display: inline-flex; align-items: center; justify-content: center; font-size: 1.6rem; margin-bottom: 1rem;">
                    <i class="fa-regular fa-bell-slash"></i>
                </div>
                <h5 class="fw-bold text-slate-800 mb-1">No Notifications Found</h5>
                <p class="small text-muted mb-0">
                    <?php echo $filter === 'unread' ? 'You are all caught up! There are no unread notifications.' : 'No alerts matching this filter category.'; ?>
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Reject Modal for inline action -->
<div id="inlineRejectModal" class="modal-backdrop" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.7); z-index: 1060; align-items: center; justify-content: center; backdrop-filter: blur(8px);">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 480px; width: 95%;">
        <div class="modal-content bg-white rounded-4 shadow-lg border-0 overflow-hidden">
            <form method="POST">
                <input type="hidden" name="request_id" id="inline_reject_req_id">
                <div class="modal-header bg-light px-4 py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="modal-title fw-bold text-slate-900 m-0" style="font-size: 1.05rem;">
                        <i class="fa-solid fa-triangle-exclamation text-danger me-2"></i>Decline Contact Change
                    </h5>
                    <button type="button" onclick="closeRejectModal()" class="btn-close" style="font-size: 0.8rem;"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="small text-secondary mb-3">
                        Provide a reason for declining the contact update request for <strong id="inline_reject_res_name" class="text-dark"></strong>:
                    </p>
                    <textarea name="admin_notes" class="form-control" rows="3" required placeholder="e.g. Phone number already belongs to another household; please provide valid utility document."></textarea>
                </div>
                <div class="modal-footer bg-light px-4 py-2.5 border-top d-flex justify-content-between">
                    <button type="button" onclick="closeRejectModal()" class="btn btn-sm btn-outline-secondary px-3">Cancel</button>
                    <button type="submit" name="reject_contact_change" class="btn btn-sm btn-danger px-4 fw-semibold">
                        Confirm Decline
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openRejectModal(id, name) {
    document.getElementById('inline_reject_req_id').value = id;
    document.getElementById('inline_reject_res_name').innerText = name;
    document.getElementById('inlineRejectModal').style.display = 'flex';
}
function closeRejectModal() {
    document.getElementById('inlineRejectModal').style.display = 'none';
}
</script>

<?php include '../includes/footer.php'; ?>
