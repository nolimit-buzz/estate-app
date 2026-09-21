<?php
// admin/broadcasts.php - Central Administration Broadcast & Notice Dispatch Center
require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/Mailer.php';
require_once '../includes/NoticeManager.php';

requireLogin();
if (!isAdminRole()) {
    header("Location: ../index");
    exit;
}

$estate_id = get_estate_id();
$message = '';
$error = '';

// Handle Delete / Archive Notice
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $del_id = intval($_GET['id']);
    $conn->query("DELETE FROM notifications WHERE reference_id = $del_id AND (type = 'estate_broadcast' OR type = 'zone_notice')");
    $del_res = $conn->query("DELETE FROM estate_announcements WHERE id = $del_id AND estate_id = $estate_id");
    if ($del_res) {
        $message = "Broadcast notice deleted successfully.";
    } else {
        $error = "Failed to delete notice.";
    }
}

// Handle Form Submission: Create / Dispatch Broadcast
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dispatch_broadcast'])) {
    $scope = $_POST['scope'] ?? 'estate';
    $zone_id = ($scope === 'zone' && !empty($_POST['target_zone_id'])) ? intval($_POST['target_zone_id']) : null;
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $target_audience = $_POST['target_audience'] ?? 'all';
    $priority = $_POST['priority'] ?? 'normal';
    $pin_to_top = isset($_POST['pin_to_top']) ? 1 : 0;
    $dispatch_notification = isset($_POST['channel_in_app']);
    $dispatch_email = isset($_POST['channel_email']);

    if (empty($title) || empty($content)) {
        $error = "Subject / Title and Notice content cannot be blank.";
    } else {
        $result = NoticeManager::publishNotice($conn, [
            'estate_id' => $estate_id,
            'zone_id' => $zone_id,
            'title' => $title,
            'content' => $content,
            'target_audience' => $target_audience,
            'priority' => $priority,
            'sender_type' => 'admin',
            'sender_name' => 'Central Administration',
            'created_by' => $_SESSION['user_id'] ?? 1,
            'pin_to_top' => $pin_to_top,
            'dispatch_notification' => $dispatch_notification,
            'dispatch_email' => $dispatch_email
        ]);

        if ($result['success']) {
            $msg_parts = ["Broadcast dispatched successfully!"];
            if ($result['notifications_sent'] > 0) {
                $msg_parts[] = "{$result['notifications_sent']} in-app alert(s) delivered.";
            }
            if ($result['emails_sent'] > 0) {
                $msg_parts[] = "{$result['emails_sent']} broadcast email(s) sent.";
            }
            $message = implode(" ", $msg_parts);
        } else {
            $error = $result['message'];
        }
    }
}

// Fetch Active Zones for dropdown & filter
$zones_res = $conn->query("SELECT id, name, code FROM zones WHERE estate_id = $estate_id ORDER BY name ASC");
$zones_list = [];
if ($zones_res) {
    while ($zr = $zones_res->fetch_assoc()) {
        $zones_list[] = $zr;
    }
}

// Filtering
$filter_scope = $_GET['scope'] ?? '';
$filter_zone = isset($_GET['zone_id']) ? intval($_GET['zone_id']) : 0;
$filter_priority = $_GET['priority'] ?? '';
$search = trim($_GET['search'] ?? '');

$where_clauses = ["ea.estate_id = $estate_id"];
if ($filter_scope === 'estate') {
    $where_clauses[] = "(ea.zone_id IS NULL OR ea.zone_id = 0)";
} elseif ($filter_scope === 'zone') {
    $where_clauses[] = "ea.zone_id IS NOT NULL AND ea.zone_id > 0";
}

if ($filter_zone > 0) {
    $where_clauses[] = "ea.zone_id = $filter_zone";
}
if (!empty($filter_priority)) {
    $fp_esc = $conn->real_escape_string($filter_priority);
    $where_clauses[] = "ea.priority = '$fp_esc'";
}
if (!empty($search)) {
    $s_esc = $conn->real_escape_string($search);
    $where_clauses[] = "(ea.title LIKE '%$s_esc%' OR ea.content LIKE '%$s_esc%')";
}

$where_sql = implode(' AND ', $where_clauses);

// Metrics
$total_broadcasts = $conn->query("SELECT COUNT(id) as cnt FROM estate_announcements WHERE estate_id = $estate_id")->fetch_assoc()['cnt'] ?? 0;
$estate_wide_cnt = $conn->query("SELECT COUNT(id) as cnt FROM estate_announcements WHERE estate_id = $estate_id AND (zone_id IS NULL OR zone_id = 0)")->fetch_assoc()['cnt'] ?? 0;
$zonal_notices_cnt = $conn->query("SELECT COUNT(id) as cnt FROM estate_announcements WHERE estate_id = $estate_id AND zone_id > 0")->fetch_assoc()['cnt'] ?? 0;
$urgent_cnt = $conn->query("SELECT COUNT(id) as cnt FROM estate_announcements WHERE estate_id = $estate_id AND priority = 'urgent'")->fetch_assoc()['cnt'] ?? 0;

// Query Broadcasts
$query = "SELECT ea.*, z.name as zone_name, z.code as zone_code, u.name as author_name 
          FROM estate_announcements ea
          LEFT JOIN zones z ON ea.zone_id = z.id
          LEFT JOIN users u ON ea.created_by = u.id
          WHERE $where_sql
          ORDER BY ea.pin_to_top DESC, ea.created_at DESC";
$announcements = $conn->query($query);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Header Section -->
<div class="page-header-futuristic mb-4">
    <div>
        <div class="header-breadcrumbs">
            <a href="index">Dashboard</a>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span>Communications</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span class="active">Broadcasts &amp; Notices</span>
        </div>
        <h1 class="page-title"><i class="fa-solid fa-bullhorn text-primary me-2"></i>Estate Broadcasts &amp; Notices Hub</h1>
        <p class="page-subtitle">Publish official announcements, estate-wide advisories, and targeted zonal notices directly to residents.</p>
    </div>
    <div class="header-actions d-flex gap-2">
        <a href="email_logs" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1">
            <i class="fa-solid fa-envelope-open-text"></i> Email Logs
        </a>
        <button type="button" onclick="openBroadcastModal()" class="btn btn-sm btn-primary d-flex align-items-center gap-2 fw-semibold shadow-sm">
            <i class="fa-solid fa-paper-plane"></i> Dispatch New Broadcast
        </button>
    </div>
</div>

    <?php if ($message): ?>
        <div class="alert mature-card p-3 mb-4" style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); color: #059669; border-radius: 0.65rem; display: flex; align-items: center; gap: 0.75rem;">
            <i class="fa-solid fa-circle-check" style="font-size: 1.2rem;"></i>
            <div style="font-weight: 500; font-size: 0.92rem;"><?php echo htmlspecialchars($message); ?></div>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert mature-card p-3 mb-4" style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; border-radius: 0.65rem; display: flex; align-items: center; gap: 0.75rem;">
            <i class="fa-solid fa-circle-exclamation" style="font-size: 1.2rem;"></i>
            <div style="font-weight: 500; font-size: 0.92rem;"><?php echo htmlspecialchars($error); ?></div>
        </div>
    <?php endif; ?>

    <!-- KPI Metric Cards -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100 p-3 bg-white rounded-3 border" style="border-color: #e2e8f0;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-secondary small fw-semibold text-uppercase">Total Dispatched</span>
                        <div class="fs-3 fw-bold text-slate-900 mt-1"><?php echo number_format($total_broadcasts); ?></div>
                    </div>
                    <div class="p-2 rounded-2" style="background: rgba(59, 130, 246, 0.1); color: #2563eb;">
                        <i class="fa-solid fa-bullhorn fs-5"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100 p-3 bg-white rounded-3 border" style="border-color: #e2e8f0;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-secondary small fw-semibold text-uppercase">Estate-Wide Broadcasts</span>
                        <div class="fs-3 fw-bold text-slate-900 mt-1"><?php echo number_format($estate_wide_cnt); ?></div>
                    </div>
                    <div class="p-2 rounded-2" style="background: rgba(16, 185, 129, 0.1); color: #059669;">
                        <i class="fa-solid fa-globe fs-5"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100 p-3 bg-white rounded-3 border" style="border-color: #e2e8f0;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-secondary small fw-semibold text-uppercase">Zonal Notices</span>
                        <div class="fs-3 fw-bold text-slate-900 mt-1"><?php echo number_format($zonal_notices_cnt); ?></div>
                    </div>
                    <div class="p-2 rounded-2" style="background: rgba(168, 85, 247, 0.1); color: #9333ea;">
                        <i class="fa-solid fa-layer-group fs-5"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100 p-3 bg-white rounded-3 border" style="border-color: #e2e8f0;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-secondary small fw-semibold text-uppercase">Urgent Advisories</span>
                        <div class="fs-3 fw-bold text-danger mt-1"><?php echo number_format($urgent_cnt); ?></div>
                    </div>
                    <div class="p-2 rounded-2" style="background: rgba(239, 68, 68, 0.1); color: #dc2626;">
                        <i class="fa-solid fa-triangle-exclamation fs-5"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter & Search Bar -->
    <div class="card mb-4 border-0 shadow-sm rounded-3">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-12 col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-start-0" placeholder="Search notices by title or keywords..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <select name="scope" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Scopes</option>
                        <option value="estate" <?php echo ($filter_scope === 'estate') ? 'selected' : ''; ?>>Estate-Wide Only</option>
                        <option value="zone" <?php echo ($filter_scope === 'zone') ? 'selected' : ''; ?>>Zonal Notices Only</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select name="zone_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="0">All Zones</option>
                        <?php foreach ($zones_list as $z): ?>
                            <option value="<?php echo $z['id']; ?>" <?php echo ($filter_zone == $z['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($z['name']); ?> (<?php echo htmlspecialchars($z['code']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select name="priority" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Priorities</option>
                        <option value="normal" <?php echo ($filter_priority === 'normal') ? 'selected' : ''; ?>>Normal</option>
                        <option value="important" <?php echo ($filter_priority === 'important') ? 'selected' : ''; ?>>Important</option>
                        <option value="urgent" <?php echo ($filter_priority === 'urgent') ? 'selected' : ''; ?>>Urgent</option>
                    </select>
                </div>
                <div class="col-6 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
                    <a href="broadcasts" class="btn btn-sm btn-light border" title="Reset Filters"><i class="fa-solid fa-rotate-right"></i></a>
                </div>
            </form>
        </div>
    </div>

    <!-- Broadcasts Feed Table -->
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="m-0 fw-bold text-slate-900"><i class="fa-solid fa-list-check me-2 text-primary"></i>Dispatched Broadcasts Registry</h6>
            <span class="badge bg-light text-secondary border"><?php echo ($announcements) ? $announcements->num_rows : 0; ?> Records Found</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                <thead class="table-light text-secondary text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.03em;">
                    <tr>
                        <th style="width: 5%;">Pin</th>
                        <th style="width: 32%;">Notice / Subject</th>
                        <th style="width: 16%;">Scope / Zone</th>
                        <th style="width: 12%;">Audience</th>
                        <th style="width: 10%;">Priority</th>
                        <th style="width: 13%;">Sender &amp; Date</th>
                        <th style="width: 12%; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($announcements && $announcements->num_rows > 0): ?>
                        <?php while ($ann = $announcements->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <?php if ($ann['pin_to_top']): ?>
                                        <i class="fa-solid fa-thumbtack text-primary" title="Pinned to top"></i>
                                    <?php else: ?>
                                        <i class="fa-solid fa-minus text-muted opacity-25"></i>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-slate-900"><?php echo htmlspecialchars($ann['title']); ?></div>
                                    <div class="text-secondary small text-truncate" style="max-width: 380px;">
                                        <?php echo htmlspecialchars(substr($ann['content'], 0, 120)) . (strlen($ann['content']) > 120 ? '...' : ''); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($ann['zone_id'])): ?>
                                        <span class="badge rounded-pill" style="background: rgba(168, 85, 247, 0.12); color: #7e22ce; border: 1px solid rgba(168, 85, 247, 0.25);">
                                            <i class="fa-solid fa-layer-group me-1"></i><?php echo htmlspecialchars($ann['zone_name'] ?? ('Zone #' . $ann['zone_id'])); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge rounded-pill" style="background: rgba(16, 185, 129, 0.12); color: #047857; border: 1px solid rgba(16, 185, 129, 0.25);">
                                            <i class="fa-solid fa-globe me-1"></i>Estate-Wide
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-capitalize badge bg-light text-dark border">
                                        <?php echo htmlspecialchars($ann['target_audience']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($ann['priority'] === 'urgent'): ?>
                                        <span class="badge bg-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>Urgent</span>
                                    <?php elseif ($ann['priority'] === 'important'): ?>
                                        <span class="badge bg-warning text-dark"><i class="fa-solid fa-circle-exclamation me-1"></i>Important</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Normal</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small fw-semibold"><?php echo htmlspecialchars($ann['sender_name'] ?? ($ann['author_name'] ?? 'Admin')); ?></div>
                                    <div class="small text-muted" style="font-size: 0.75rem;">
                                        <?php echo date('M j, Y • g:i A', strtotime($ann['created_at'])); ?>
                                    </div>
                                </td>
                                <td style="text-align: right;">
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary" onclick='viewNotice(<?php echo json_encode($ann); ?>)' title="View Full Notice">
                                            <i class="fa-regular fa-eye"></i>
                                        </button>
                                        <a href="broadcasts?action=delete&id=<?php echo $ann['id']; ?>" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to delete this broadcast notice?');" title="Delete Notice">
                                            <i class="fa-regular fa-trash-can"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-bullhorn fs-2 mb-2 text-secondary opacity-50 d-block"></i>
                                <div class="fw-semibold">No Broadcasts or Notices Found</div>
                                <div class="small">Click "Dispatch New Broadcast" to publish an announcement across the estate or specific zones.</div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<!-- Compose Broadcast Modal -->
<div id="broadcastModal" class="modal-backdrop" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.7); z-index: 1050; align-items: center; justify-content: center; backdrop-filter: blur(8px);">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 650px; width: 95%;">
        <div class="modal-content bg-white rounded-3 shadow-lg border-0 overflow-hidden">
            <form method="POST" action="broadcasts">
                <div class="modal-header bg-light px-4 py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="modal-title fw-bold text-slate-900 m-0" style="font-size: 1.1rem;">
                        <i class="fa-solid fa-bullhorn text-primary me-2"></i>Dispatch Estate Broadcast / Notice
                    </h5>
                    <button type="button" onclick="closeBroadcastModal()" class="btn-close" style="font-size: 0.8rem;"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <!-- Target Scope -->
                        <div class="col-12 col-sm-6">
                            <label class="form-label small fw-bold text-secondary">Broadcast Scope <span class="text-danger">*</span></label>
                            <select name="scope" id="scopeSelector" class="form-select" onchange="toggleZoneDropdown(this.value)" required>
                                <option value="estate">🌐 Entire Estate (All Zones)</option>
                                <option value="zone">🏢 Specific Zone Only</option>
                            </select>
                        </div>

                        <!-- Target Zone (Hidden if estate-wide) -->
                        <div class="col-12 col-sm-6" id="zoneSelectWrapper" style="display: none;">
                            <label class="form-label small fw-bold text-secondary">Target Zone <span class="text-danger">*</span></label>
                            <select name="target_zone_id" id="targetZoneSelect" class="form-select">
                                <option value="">Select Target Zone...</option>
                                <?php foreach ($zones_list as $z): ?>
                                    <option value="<?php echo $z['id']; ?>">
                                        <?php echo htmlspecialchars($z['name']); ?> (<?php echo htmlspecialchars($z['code']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Target Audience -->
                        <div class="col-12 col-sm-6" id="audienceWrapper">
                            <label class="form-label small fw-bold text-secondary">Target Audience</label>
                            <select name="target_audience" class="form-select">
                                <option value="all">Everyone (Owners &amp; Residents)</option>
                                <option value="tenants">Tenants / Residents Only</option>
                                <option value="owners">Property Owners Only</option>
                            </select>
                        </div>

                        <!-- Priority Level -->
                        <div class="col-12 col-sm-6">
                            <label class="form-label small fw-bold text-secondary">Priority Level</label>
                            <select name="priority" class="form-select">
                                <option value="normal">Normal (General Announcement)</option>
                                <option value="important">Important (Security / Billing / Policy)</option>
                                <option value="urgent">Urgent / Emergency Advisory</option>
                            </select>
                        </div>

                        <!-- Subject / Title -->
                        <div class="col-12">
                            <label class="form-label small fw-bold text-secondary">Broadcast Title / Subject <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" required placeholder="e.g. Scheduled Water Infrastructure Maintenance">
                        </div>

                        <!-- Content Body -->
                        <div class="col-12">
                            <label class="form-label small fw-bold text-secondary">Announcement Message Content <span class="text-danger">*</span></label>
                            <textarea name="content" class="form-control" rows="5" required placeholder="Enter the complete advisory, guidelines, or notice details here..."></textarea>
                        </div>

                        <!-- Dispatch Channels -->
                        <div class="col-12">
                            <label class="form-label small fw-bold text-secondary mb-2">Delivery Channels</label>
                            <div class="d-flex flex-column gap-2 p-3 bg-light rounded-3 border">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="channel_feed" id="chFeed" checked disabled>
                                    <label class="form-check-label small fw-semibold" for="chFeed">
                                        <i class="fa-solid fa-bullhorn text-primary me-1"></i> Resident Portal Noticeboard &amp; Live Feed (Default)
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="channel_in_app" id="chInApp" value="1" checked>
                                    <label class="form-check-label small" for="chInApp">
                                        <i class="fa-solid fa-bell text-warning me-1"></i> Send Direct In-App Notification Alerts to Target Users
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="channel_email" id="chEmail" value="1">
                                    <label class="form-check-label small" for="chEmail">
                                        <i class="fa-solid fa-envelope text-success me-1"></i> Dispatch Outbound Broadcast Email (via SMTP)
                                    </label>
                                </div>
                                <div class="form-check mt-1 pt-1 border-top">
                                    <input class="form-check-input" type="checkbox" name="pin_to_top" id="chPin" value="1">
                                    <label class="form-check-label small text-primary fw-semibold" for="chPin">
                                        <i class="fa-solid fa-thumbtack me-1"></i> Pin this Announcement to the top of Resident Portals
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light px-4 py-3 border-top d-flex justify-content-between">
                    <button type="button" onclick="closeBroadcastModal()" class="btn btn-sm btn-outline-secondary">Cancel</button>
                    <button type="submit" name="dispatch_broadcast" class="btn btn-sm btn-primary px-4 fw-semibold">
                        <i class="fa-solid fa-paper-plane me-1"></i> Confirm &amp; Dispatch
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Notice Modal -->
<div id="viewNoticeModal" class="modal-backdrop" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.7); z-index: 1050; align-items: center; justify-content: center; backdrop-filter: blur(8px);">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 600px; width: 95%;">
        <div class="modal-content bg-white rounded-3 shadow-lg border-0 overflow-hidden">
            <div class="modal-header bg-light px-4 py-3 border-bottom d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <span id="viewPriorityBadge"></span>
                    <span id="viewScopeBadge"></span>
                </div>
                <button type="button" onclick="closeViewNoticeModal()" class="btn-close" style="font-size: 0.8rem;"></button>
            </div>
            <div class="modal-body p-4">
                <h4 id="viewNoticeTitle" class="fw-bold text-slate-900 mb-2"></h4>
                <div class="d-flex align-items-center gap-3 text-secondary small pb-3 mb-3 border-bottom">
                    <span><i class="fa-regular fa-user me-1"></i><span id="viewNoticeAuthor"></span></span>
                    <span><i class="fa-regular fa-clock me-1"></i><span id="viewNoticeDate"></span></span>
                    <span><i class="fa-solid fa-users me-1"></i><span id="viewNoticeAudience" class="text-capitalize"></span></span>
                </div>
                <div id="viewNoticeContent" class="text-slate-800" style="line-height: 1.7; white-space: pre-wrap;"></div>
            </div>
            <div class="modal-footer bg-light px-4 py-2 border-top">
                <button type="button" onclick="closeViewNoticeModal()" class="btn btn-sm btn-secondary">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function openBroadcastModal() {
    document.getElementById('broadcastModal').style.display = 'flex';
}
function closeBroadcastModal() {
    document.getElementById('broadcastModal').style.display = 'none';
}
function toggleZoneDropdown(scope) {
    const zoneWrapper = document.getElementById('zoneSelectWrapper');
    const zoneSelect = document.getElementById('targetZoneSelect');
    if (scope === 'zone') {
        zoneWrapper.style.display = 'block';
        zoneSelect.required = true;
    } else {
        zoneWrapper.style.display = 'none';
        zoneSelect.required = false;
    }
}
function viewNotice(ann) {
    document.getElementById('viewNoticeTitle').innerText = ann.title;
    document.getElementById('viewNoticeContent').innerText = ann.content;
    document.getElementById('viewNoticeAuthor').innerText = ann.sender_name || ann.author_name || 'Central Administration';
    document.getElementById('viewNoticeDate').innerText = new Date(ann.created_at).toLocaleDateString(undefined, {
        month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit'
    });
    document.getElementById('viewNoticeAudience').innerText = 'Audience: ' + ann.target_audience;

    // Scope Badge
    const scopeEl = document.getElementById('viewScopeBadge');
    if (ann.zone_id && ann.zone_name) {
        scopeEl.innerHTML = `<span class="badge rounded-pill" style="background: rgba(168, 85, 247, 0.15); color: #7e22ce;"><i class="fa-solid fa-layer-group me-1"></i>${ann.zone_name}</span>`;
    } else {
        scopeEl.innerHTML = `<span class="badge rounded-pill" style="background: rgba(16, 185, 129, 0.15); color: #047857;"><i class="fa-solid fa-globe me-1"></i>Estate-Wide</span>`;
    }

    // Priority Badge
    const prioEl = document.getElementById('viewPriorityBadge');
    if (ann.priority === 'urgent') {
        prioEl.innerHTML = `<span class="badge bg-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>Urgent</span>`;
    } else if (ann.priority === 'important') {
        prioEl.innerHTML = `<span class="badge bg-warning text-dark"><i class="fa-solid fa-circle-exclamation me-1"></i>Important</span>`;
    } else {
        prioEl.innerHTML = `<span class="badge bg-secondary">Normal</span>`;
    }

    document.getElementById('viewNoticeModal').style.display = 'flex';
}
function closeViewNoticeModal() {
    document.getElementById('viewNoticeModal').style.display = 'none';
}
</script>

<?php include '../includes/footer.php'; ?>
