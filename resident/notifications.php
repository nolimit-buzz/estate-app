<?php
// resident/notifications.php - Resident Notifications & Action Hub
require_once '../config.php';
require_once '../includes/auth_guard.php';

requireLogin();

$user_id = intval($_SESSION['user_id'] ?? 0);
$estate_id = get_estate_id();
$user_name = $_SESSION['name'] ?? 'Resident';

// Fetch resident profile and zone context
$res_query = "SELECT r.*, f.number as flat_number, b.name as building_name, s.name as street_name, s.zone_id, z.name as zone_name 
              FROM residents r 
              LEFT JOIN flats f ON r.flat_id = f.id 
              LEFT JOIN buildings b ON f.building_id = b.id 
              LEFT JOIN streets s ON b.street_id = s.id 
              LEFT JOIN zones z ON s.zone_id = z.id 
              WHERE r.user_id = $user_id AND r.estate_id = $estate_id 
              ORDER BY r.id DESC LIMIT 1";
$resident_info = $conn->query($res_query)->fetch_assoc();
$resident_zone_id = $resident_info['zone_id'] ?? 0;
$resident_zone_name = $resident_info['zone_name'] ?? 'General Sector';

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
    $message = "Notification removed.";
}

// Handle Action: Cancel Pending Contact Change Request
if (isset($_POST['cancel_contact_request']) && isset($_POST['request_id'])) {
    $req_id = intval($_POST['request_id']);
    $conn->query("UPDATE contact_change_requests SET status = 'cancelled', updated_at = NOW() WHERE id = $req_id AND user_id = $user_id AND status = 'pending'");
    if ($conn->affected_rows > 0) {
        $message = "Your pending contact update request has been cancelled.";
    } else {
        $message = "Unable to cancel request or request already processed.";
        $message_type = 'danger';
    }
}

// Check for latest contact change request (especially pending)
$latest_contact_req = null;
$chk_req = $conn->query("SELECT * FROM contact_change_requests WHERE user_id = $user_id AND estate_id = $estate_id ORDER BY id DESC LIMIT 1");
if ($chk_req && $chk_req->num_rows > 0) {
    $latest_contact_req = $chk_req->fetch_assoc();
}

// Filter and Search logic - Broadcasts stay in notices.php, notifications stay in notifications.php
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$where = ["user_id = $user_id", "estate_id = $estate_id", "type NOT IN ('estate_broadcast', 'zone_notice')"];

if ($filter === 'unread') {
    $where[] = "is_read = 0";
} elseif ($filter === 'contact') {
    $where[] = "type IN ('contact_approved', 'contact_rejected', 'resident_request')";
} elseif ($filter === 'finance') {
    $where[] = "type IN ('invoice', 'payment', 'due_payment')";
} elseif ($filter === 'security') {
    $where[] = "type IN ('panic_alert', 'incident', 'maintenance', 'visitor')";
}

if (!empty($search)) {
    $esc_search = $conn->real_escape_string($search);
    $where[] = "(title LIKE '%$esc_search%' OR message LIKE '%$esc_search%')";
}

$where_clause = implode(' AND ', $where);

// Notification Counts for Badges (Personal Notifications Only)
$cnt_total = $conn->query("SELECT COUNT(id) as c FROM notifications WHERE user_id = $user_id AND estate_id = $estate_id AND type NOT IN ('estate_broadcast', 'zone_notice')")->fetch_assoc()['c'] ?? 0;
$cnt_unread = $conn->query("SELECT COUNT(id) as c FROM notifications WHERE user_id = $user_id AND estate_id = $estate_id AND is_read = 0 AND type NOT IN ('estate_broadcast', 'zone_notice')")->fetch_assoc()['c'] ?? 0;
$cnt_contact = $conn->query("SELECT COUNT(id) as c FROM notifications WHERE user_id = $user_id AND estate_id = $estate_id AND type IN ('contact_approved', 'contact_rejected', 'resident_request')")->fetch_assoc()['c'] ?? 0;
$cnt_finance = $conn->query("SELECT COUNT(id) as c FROM notifications WHERE user_id = $user_id AND estate_id = $estate_id AND type IN ('invoice', 'payment', 'due_payment')")->fetch_assoc()['c'] ?? 0;
$cnt_security = $conn->query("SELECT COUNT(id) as c FROM notifications WHERE user_id = $user_id AND estate_id = $estate_id AND type IN ('panic_alert', 'incident', 'maintenance', 'visitor')")->fetch_assoc()['c'] ?? 0;

// Fetch Notifications List
$notifs_res = $conn->query("SELECT * FROM notifications WHERE $where_clause ORDER BY id DESC LIMIT 100");
$notifications = [];
if ($notifs_res) {
    while ($row = $notifs_res->fetch_assoc()) {
        $notifications[] = $row;
    }
}

// Relative time helper
function timeAgo($datetime) {
    $ts = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60) return "Just now";
    if ($diff < 3600) return floor($diff / 60) . "m ago";
    if ($diff < 86400) return floor($diff / 3600) . "h ago";
    if ($diff < 604800) return floor($diff / 86400) . "d ago";
    return date("M j, Y", $ts);
}

include 'header.php';
include 'sidebar.php';
?>

<div class="d-flex flex-column gap-4">
    <!-- Header / Hero Banner -->
    <div class="resident-hero-banner" style="background: linear-gradient(135deg, #091e3a 0%, #1e293b 50%, #1e1b4b 100%);">
        <div class="resident-hero-content flex-grow-1 min-w-0">
            <div class="d-flex align-items-start gap-3 w-100">
                <div class="glass-icon-circle hero-icon-circle glass-icon-blue" style="width: 48px; height: 48px; min-width: 48px; min-height: 48px; border-radius: 50% !important; aspect-ratio: 1 / 1 !important; flex-shrink: 0 !important; font-size: 1.35rem;">
                    <i class="fa-solid fa-bell"></i>
                </div>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                        <h2 class="fw-bold text-white mb-0" style="font-size: 1.35rem; letter-spacing: -0.01em;">Resident Notification Hub</h2>
                        <span class="badge rounded-pill" style="font-size: 0.72rem; padding: 0.35rem 0.65rem; background: rgba(59, 130, 246, 0.25); border: 1px solid rgba(147, 197, 253, 0.35); color: #93c5fd; white-space: nowrap;">
                            <i class="fa-solid fa-satellite-dish me-1"></i> Unified Activity Center
                        </span>
                    </div>
                    <p class="text-white text-opacity-75 small m-0" style="overflow-wrap: break-word; line-height: 1.45;">
                        Track contact update requests, approvals from Central &amp; Zonal Administration, official estate notices, and system alerts.
                    </p>
                </div>
            </div>
        </div>
        <div class="resident-hero-actions d-flex align-items-center gap-2 flex-wrap">
            <?php if ($cnt_unread > 0): ?>
                <form method="POST" class="m-0">
                    <button type="submit" name="mark_all_read" class="btn btn-sm btn-light rounded-pill px-3 fw-semibold shadow-sm d-inline-flex align-items-center gap-2">
                        <i class="fa-solid fa-envelope-open-text text-primary"></i> Mark All as Read
                    </button>
                </form>
            <?php endif; ?>
            <a href="settings" class="btn btn-sm btn-outline-light rounded-pill px-3 fw-semibold d-inline-flex align-items-center gap-1">
                <i class="fa-solid fa-user-gear"></i> Contact Settings
            </a>
        </div>
    </div>

    <!-- Feedback Message Alert -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show rounded-4 border-0 shadow-sm d-flex align-items-center gap-2" role="alert">
            <i class="fa-solid fa-circle-check fs-5"></i>
            <div><?php echo htmlspecialchars($message); ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- ========================================================
         PENDING CONTACT CHANGE REQUEST ACTIVE CARD (IF ANY)
         ======================================================== -->
    <?php if ($latest_contact_req && $latest_contact_req['status'] === 'pending'): ?>
        <div class="card border-0 rounded-4 shadow-sm overflow-hidden" style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border-left: 5px solid #d97706 !important;">
            <div class="card-body p-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div class="d-flex align-items-start gap-3">
                        <div class="glass-icon-circle hero-icon-circle glass-icon-amber" style="width: 46px; height: 46px; min-width: 46px; min-height: 46px; font-size: 1.25rem;">
                            <i class="fa-solid fa-hourglass-half fa-spin"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                <h5 class="fw-bold text-dark m-0" style="font-size: 1.05rem;">
                                    Official Contact Information Update Pending Review
                                </h5>
                                <span class="badge rounded-pill bg-warning text-dark px-2.5 py-1 fw-bold" style="font-size: 0.72rem;">
                                    <i class="fa-solid fa-clock me-1"></i> Awaiting Admin Action
                                </span>
                            </div>
                            <p class="text-secondary small mb-2">
                                Your update request was submitted on <strong><?php echo date('M j, Y \a\t g:i A', strtotime($latest_contact_req['created_at'])); ?></strong>. Both <strong>Central Estate Management</strong> and <strong><?php echo htmlspecialchars($resident_zone_name); ?></strong> have been notified to review and authorize your request.
                            </p>
                            
                            <div class="d-flex flex-wrap gap-3 small">
                                <?php if (!empty($latest_contact_req['requested_phone'])): ?>
                                    <div class="bg-white px-3 py-1.5 rounded-3 border border-warning-subtle">
                                        <span class="text-muted">Requested Phone:</span> 
                                        <strong class="text-dark font-monospace"><?php echo htmlspecialchars($latest_contact_req['requested_phone']); ?></strong>
                                        <span class="text-muted small ms-1">(Current: <?php echo htmlspecialchars($latest_contact_req['current_phone'] ?? 'None'); ?>)</span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($latest_contact_req['requested_email'])): ?>
                                    <div class="bg-white px-3 py-1.5 rounded-3 border border-warning-subtle">
                                        <span class="text-muted">Requested Email:</span> 
                                        <strong class="text-dark font-monospace"><?php echo htmlspecialchars($latest_contact_req['requested_email']); ?></strong>
                                        <span class="text-muted small ms-1">(Current: <?php echo htmlspecialchars($latest_contact_req['current_email'] ?? 'None'); ?>)</span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($latest_contact_req['reason'])): ?>
                                    <div class="bg-white px-3 py-1.5 rounded-3 border border-warning-subtle text-muted">
                                        Reason: <em class="text-dark">"<?php echo htmlspecialchars($latest_contact_req['reason']); ?>"</em>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-shrink-0">
                        <a href="settings" class="btn btn-sm btn-outline-dark rounded-pill px-3 fw-semibold">
                            <i class="fa-solid fa-sliders me-1"></i> Settings
                        </a>
                        <form method="POST" onsubmit="return confirm('Are you sure you want to cancel this pending contact change request?');" class="m-0">
                            <input type="hidden" name="request_id" value="<?php echo $latest_contact_req['id']; ?>">
                            <button type="submit" name="cancel_contact_request" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold">
                                <i class="fa-solid fa-xmark me-1"></i> Cancel Request
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Stat Pillars -->
    <div class="row g-3">
        <div class="col-6 col-md-3">
            <div class="card border-0 rounded-4 shadow-sm p-3 bg-white h-100 d-flex flex-row align-items-center gap-3" style="overflow: hidden;">
                <div class="glass-icon-circle glass-icon-light-blue" style="width: 44px; height: 44px; min-width: 44px; min-height: 44px; font-size: 1.25rem;">
                    <i class="fa-solid fa-bell"></i>
                </div>
                <div style="flex: 1; min-width: 0;">
                    <div class="text-secondary small fw-medium text-truncate">Total Alerts</div>
                    <div class="fw-bold fs-4 text-dark"><?php echo $cnt_total; ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 rounded-4 shadow-sm p-3 bg-white h-100 d-flex flex-row align-items-center gap-3" style="overflow: hidden;">
                <div class="glass-icon-circle glass-icon-rose" style="width: 44px; height: 44px; min-width: 44px; min-height: 44px; font-size: 1.25rem;">
                    <i class="fa-solid fa-envelope"></i>
                </div>
                <div style="flex: 1; min-width: 0;">
                    <div class="text-secondary small fw-medium text-truncate">Unread Items</div>
                    <div class="fw-bold fs-4 <?php echo ($cnt_unread > 0) ? 'text-danger' : 'text-dark'; ?>">
                        <?php echo $cnt_unread; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 rounded-4 shadow-sm p-3 bg-white h-100 d-flex flex-row align-items-center gap-3" style="overflow: hidden;">
                <div class="glass-icon-circle glass-icon-light-emerald" style="width: 44px; height: 44px; min-width: 44px; min-height: 44px; font-size: 1.25rem;">
                    <i class="fa-solid fa-id-card-clip"></i>
                </div>
                <div style="flex: 1; min-width: 0;">
                    <div class="text-secondary small fw-medium text-truncate">Contact &amp; Profile</div>
                    <div class="fw-bold fs-4 text-dark"><?php echo $cnt_contact; ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <a href="notices" class="text-decoration-none">
                <div class="card border-0 rounded-4 shadow-sm p-3 bg-white h-100 d-flex flex-row align-items-center gap-3" style="overflow: hidden;">
                    <div class="glass-icon-circle glass-icon-light-amber" style="width: 44px; height: 44px; min-width: 44px; min-height: 44px; font-size: 1.25rem;">
                        <i class="fa-solid fa-bullhorn"></i>
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <div class="text-secondary small fw-medium text-truncate">Estate Broadcasts</div>
                        <div class="fw-bold fs-6 text-dark text-truncate">Noticeboard &rarr;</div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- Filter Navigation Tabs & Search Bar -->
    <div class="card border-0 rounded-4 shadow-sm p-3 bg-white">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
            <!-- Filter Pills -->
            <div class="d-flex flex-wrap gap-2">
                <a href="notifications?filter=all" class="btn btn-sm rounded-pill px-3 fw-semibold <?php echo ($filter === 'all') ? 'btn-primary' : 'btn-light border'; ?>">
                    All <span class="badge bg-white text-dark ms-1"><?php echo $cnt_total; ?></span>
                </a>
                <a href="notifications?filter=unread" class="btn btn-sm rounded-pill px-3 fw-semibold <?php echo ($filter === 'unread') ? 'btn-danger' : 'btn-light border'; ?>">
                    Unread <span class="badge bg-white text-dark ms-1"><?php echo $cnt_unread; ?></span>
                </a>
                <a href="notifications?filter=contact" class="btn btn-sm rounded-pill px-3 fw-semibold <?php echo ($filter === 'contact') ? 'btn-primary' : 'btn-light border'; ?>">
                    <i class="fa-solid fa-id-card-clip me-1"></i> Contact Updates <span class="badge bg-white text-dark ms-1"><?php echo $cnt_contact; ?></span>
                </a>
                <a href="notifications?filter=finance" class="btn btn-sm rounded-pill px-3 fw-semibold <?php echo ($filter === 'finance') ? 'btn-primary' : 'btn-light border'; ?>">
                    <i class="fa-solid fa-file-invoice-dollar me-1"></i> Invoices <span class="badge bg-white text-dark ms-1"><?php echo $cnt_finance; ?></span>
                </a>
                <a href="notifications?filter=security" class="btn btn-sm rounded-pill px-3 fw-semibold <?php echo ($filter === 'security') ? 'btn-primary' : 'btn-light border'; ?>">
                    <i class="fa-solid fa-shield-halved me-1"></i> Security &amp; Maintenance <span class="badge bg-white text-dark ms-1"><?php echo $cnt_security; ?></span>
                </a>
            </div>

            <!-- Search Form -->
            <form method="GET" class="d-flex align-items-center gap-2 m-0" style="min-width: 240px; flex: 1 1 240px; max-width: 360px;">
                <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                <div class="search-integrated-wrap">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" name="search" class="form-control" placeholder="Search notices..." value="<?php echo htmlspecialchars($search); ?>">
                    <?php if (!empty($search)): ?>
                        <a href="notifications?filter=<?php echo urlencode($filter); ?>" class="search-clear-btn" title="Clear search">
                            <i class="fa-solid fa-xmark"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Notification Feed Stream -->
    <div class="d-flex flex-column gap-3">
        <?php if (empty($notifications)): ?>
            <div class="card border-0 rounded-4 shadow-sm p-5 text-center bg-white">
                <div style="width: 68px; height: 68px; border-radius: 50%; background: #f8fafc; color: #94a3b8; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; margin: 0 auto 1rem;">
                    <i class="fa-regular fa-bell-slash"></i>
                </div>
                <h5 class="fw-bold text-dark mb-1">No Notifications Found</h5>
                <p class="text-secondary small mb-3">
                    <?php if (!empty($search)): ?>
                        No activity found matching "<?php echo htmlspecialchars($search); ?>". Try a different keyword or reset filters.
                    <?php else: ?>
                        You're all caught up! When official notices, approval decisions, or invoices are dispatched, they will appear here.
                    <?php endif; ?>
                </p>
                <div>
                    <a href="notifications" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                        <i class="fa-solid fa-rotate-right me-1"></i> Refresh Feed
                    </a>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $n): 
                $is_unr = ($n['is_read'] == 0);
                $type = $n['type'] ?? 'general';

                // Determine badge and visual tokens based on notification type
                $icon = 'fa-solid fa-bell';
                $icon_bg = '#eff6ff';
                $icon_color = '#2563eb';
                $badge_class = 'bg-primary';
                $badge_text = 'System Alert';
                $action_url = 'index';
                $action_label = 'View Details';

                if ($type === 'contact_approved') {
                    $icon = 'fa-solid fa-circle-check';
                    $icon_bg = '#ecfdf5';
                    $icon_color = '#059669';
                    $badge_class = 'bg-success';
                    $badge_text = 'Update Approved';
                    $action_url = 'settings';
                    $action_label = 'View Updated Profile';
                } elseif ($type === 'contact_rejected') {
                    $icon = 'fa-solid fa-circle-xmark';
                    $icon_bg = '#fef2f2';
                    $icon_color = '#dc2626';
                    $badge_class = 'bg-danger';
                    $badge_text = 'Update Declined';
                    $action_url = 'settings';
                    $action_label = 'Check Settings';
                } elseif ($type === 'resident_request') {
                    $icon = 'fa-solid fa-id-card-clip';
                    $icon_bg = '#fffbeb';
                    $icon_color = '#d97706';
                    $badge_class = 'bg-warning text-dark';
                    $badge_text = 'Request Under Review';
                    $action_url = 'settings';
                    $action_label = 'View Request';
                } elseif ($type === 'estate_broadcast') {
                    $icon = 'fa-solid fa-bullhorn';
                    $icon_bg = '#fef3c7';
                    $icon_color = '#b45309';
                    $badge_class = 'bg-warning text-dark';
                    $badge_text = 'Estate Broadcast';
                    $action_url = 'notices?tab=estate';
                    $action_label = 'Read Official Notice';
                } elseif ($type === 'zone_notice') {
                    $icon = 'fa-solid fa-layer-group';
                    $icon_bg = '#f3e8ff';
                    $icon_color = '#7e22ce';
                    $badge_class = 'bg-purple text-white';
                    $badge_text = 'Zonal Notice';
                    $action_url = 'notices?tab=zone';
                    $action_label = 'Read Zonal Notice';
                } elseif ($type === 'invoice' || $type === 'due_payment') {
                    $icon = 'fa-solid fa-file-invoice-dollar';
                    $icon_bg = '#f0fdf4';
                    $icon_color = '#16a34a';
                    $badge_class = 'bg-success';
                    $badge_text = 'Billing & Invoice';
                    $action_url = 'finance';
                    $action_label = 'Pay / View Invoice';
                } elseif ($type === 'maintenance') {
                    $icon = 'fa-solid fa-screwdriver-wrench';
                    $icon_bg = '#fef3c7';
                    $icon_color = '#d97706';
                    $badge_class = 'bg-warning text-dark';
                    $badge_text = 'Maintenance';
                    $action_url = 'report_issue';
                    $action_label = 'Check Issue Status';
                } elseif ($type === 'visitor') {
                    $icon = 'fa-solid fa-id-card-clip';
                    $icon_bg = '#eff6ff';
                    $icon_color = '#2563eb';
                    $badge_class = 'bg-info text-white';
                    $badge_text = 'Visitor Pass';
                    $action_url = 'visitors';
                    $action_label = 'View Passes';
                } elseif ($type === 'panic_alert' || $type === 'incident') {
                    $icon = 'fa-solid fa-triangle-exclamation';
                    $icon_bg = '#fef2f2';
                    $icon_color = '#dc2626';
                    $badge_class = 'bg-danger';
                    $badge_text = 'Emergency Alert';
                    $action_url = 'emergency';
                    $action_label = 'Emergency Hub';
                }
            ?>
                <div class="card border-0 rounded-4 shadow-sm p-3 p-md-4 bg-white position-relative transition-all" style="border-left: 5px solid <?php echo $is_unr ? '#2563eb' : '#e2e8f0'; ?> !important; background: <?php echo $is_unr ? '#fbfcfe' : '#ffffff'; ?>;">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
                        <div class="d-flex align-items-start gap-3" style="flex: 1; min-width: 0;">
                            <!-- Category Icon -->
                            <div class="glass-icon-circle hero-icon-circle" style="width: 44px; height: 44px; min-width: 44px; min-height: 44px; border-radius: 50% !important; aspect-ratio: 1 / 1 !important; flex-shrink: 0 !important; background: <?php echo $icon_bg; ?>; color: <?php echo $icon_color; ?>; display: inline-flex; align-items: center; justify-content: center; font-size: 1.2rem; border: 1px solid rgba(0,0,0,0.06);">
                                <i class="<?php echo $icon; ?>"></i>
                            </div>

                            <div style="flex: 1; min-width: 0;">
                                <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                    <span class="badge rounded-pill <?php echo $badge_class; ?>" style="font-size: 0.7rem; padding: 0.25rem 0.55rem;">
                                        <?php echo $badge_text; ?>
                                    </span>
                                    <?php if ($is_unr): ?>
                                        <span class="badge rounded-pill bg-danger-subtle text-danger border border-danger-subtle" style="font-size: 0.65rem; padding: 0.2rem 0.45rem;">
                                            <i class="fa-solid fa-circle" style="font-size: 0.35rem;"></i> New
                                        </span>
                                    <?php endif; ?>
                                    <span class="text-secondary small font-monospace">
                                        <i class="fa-regular fa-clock me-1"></i><?php echo timeAgo($n['created_at']); ?>
                                    </span>
                                    <span class="text-muted small d-none d-sm-inline">
                                        (<?php echo date('M j, Y • g:i A', strtotime($n['created_at'])); ?>)
                                    </span>
                                </div>

                                <h5 class="fw-bold text-dark mb-1" style="font-size: 1rem; line-height: 1.3;">
                                    <?php echo htmlspecialchars($n['title']); ?>
                                </h5>

                                <p class="text-secondary small m-0 mb-3" style="line-height: 1.5; white-space: pre-line;">
                                    <?php echo htmlspecialchars($n['message']); ?>
                                </p>

                                <!-- Direct Action Shortcut Button -->
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <a href="<?php echo htmlspecialchars($action_url); ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 fw-semibold d-inline-flex align-items-center gap-1.5" style="font-size: 0.78rem;">
                                        <span><?php echo $action_label; ?></span>
                                        <i class="fa-solid fa-arrow-right small"></i>
                                    </a>

                                    <a href="notifications?toggle_read=1&id=<?php echo $n['id']; ?>&filter=<?php echo urlencode($filter); ?>" class="btn btn-sm btn-light rounded-pill px-2.5 py-1 text-secondary" style="font-size: 0.75rem;" title="<?php echo $is_unr ? 'Mark as Read' : 'Mark as Unread'; ?>">
                                        <i class="fa-solid <?php echo $is_unr ? 'fa-check' : 'fa-envelope'; ?> me-1"></i>
                                        <?php echo $is_unr ? 'Mark Read' : 'Mark Unread'; ?>
                                    </a>

                                    <a href="notifications?delete_notif=1&id=<?php echo $n['id']; ?>&filter=<?php echo urlencode($filter); ?>" onclick="return confirm('Remove this notification?');" class="btn btn-sm btn-light text-danger rounded-pill px-2 py-1" style="font-size: 0.75rem;" title="Delete">
                                        <i class="fa-regular fa-trash-can"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
