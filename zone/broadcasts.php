<?php
// zone/broadcasts.php - Zonal Notices & Resident Broadcast Center
require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/Mailer.php';
require_once '../includes/NoticeManager.php';

requireZoneAccess();

$zone_id = get_current_zone_id();
$estate_id = get_estate_id();
$current_user_name = $_SESSION['name'] ?? 'Zone Admin';

// Fetch Zone Metadata
$zone_res = $conn->query("SELECT * FROM zones WHERE id = $zone_id AND estate_id = $estate_id LIMIT 1");
$zone = ($zone_res && $zone_res->num_rows > 0) ? $zone_res->fetch_assoc() : null;

if (!$zone) {
    die("Invalid Zone Assignment");
}

$zone_name = $zone['name'];
$zone_code = $zone['code'];
$message = '';
$error = '';

// Handle Delete Notice
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $del_id = intval($_GET['id']);
    // Verify notice belongs to this zone
    $chk = $conn->query("SELECT id FROM estate_announcements WHERE id = $del_id AND zone_id = $zone_id AND estate_id = $estate_id");
    if ($chk && $chk->num_rows > 0) {
        $conn->query("DELETE FROM notifications WHERE reference_id = $del_id AND type = 'zone_notice'");
        $del_res = $conn->query("DELETE FROM estate_announcements WHERE id = $del_id");
        if ($del_res) {
            $message = "Zonal notice removed successfully.";
        } else {
            $error = "Failed to remove notice.";
        }
    } else {
        $error = "Notice not found or unauthorized.";
    }
}

// Handle Form Submission: Create / Dispatch Zonal Notice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dispatch_zone_notice'])) {
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
            'sender_type' => 'zone',
            'sender_name' => $zone_name . ' Hub',
            'created_by' => $_SESSION['user_id'] ?? 1,
            'pin_to_top' => $pin_to_top,
            'dispatch_notification' => $dispatch_notification,
            'dispatch_email' => $dispatch_email
        ]);

        if ($result['success']) {
            $msg_parts = ["Zonal notice dispatched successfully to residents of {$zone_name}!"];
            if ($result['notifications_sent'] > 0) {
                $msg_parts[] = "{$result['notifications_sent']} in-app notification(s) delivered.";
            }
            if ($result['emails_sent'] > 0) {
                $msg_parts[] = "{$result['emails_sent']} zonal email(s) sent.";
            }
            $message = implode(" ", $msg_parts);
        } else {
            $error = $result['message'];
        }
    }
}

// Filtering
$filter_priority = $_GET['priority'] ?? '';
$search = trim($_GET['search'] ?? '');

$where_clauses = ["ea.estate_id = $estate_id", "ea.zone_id = $zone_id"];

if (!empty($filter_priority)) {
    $fp_esc = $conn->real_escape_string($filter_priority);
    $where_clauses[] = "ea.priority = '$fp_esc'";
}
if (!empty($search)) {
    $s_esc = $conn->real_escape_string($search);
    $where_clauses[] = "(ea.title LIKE '%$s_esc%' OR ea.content LIKE '%$s_esc%')";
}

$where_sql = implode(' AND ', $where_clauses);

// Zone KPIs
$total_zone_notices = $conn->query("SELECT COUNT(id) as cnt FROM estate_announcements WHERE estate_id = $estate_id AND zone_id = $zone_id")->fetch_assoc()['cnt'] ?? 0;
$urgent_zone_cnt = $conn->query("SELECT COUNT(id) as cnt FROM estate_announcements WHERE estate_id = $estate_id AND zone_id = $zone_id AND priority = 'urgent'")->fetch_assoc()['cnt'] ?? 0;

$active_residents_cnt = $conn->query("
    SELECT COUNT(DISTINCT r.id) as cnt 
    FROM residents r 
    JOIN flats f ON r.flat_id = f.id 
    JOIN buildings b ON f.building_id = b.id 
    JOIN streets s ON b.street_id = s.id 
    WHERE s.zone_id = $zone_id AND r.status = 'active'
")->fetch_assoc()['cnt'] ?? 0;

$active_streets_cnt = $conn->query("SELECT COUNT(id) as cnt FROM streets WHERE zone_id = $zone_id AND status != 'archived'")->fetch_assoc()['cnt'] ?? 0;

// Query Zonal Notices
$query = "SELECT ea.*, u.name as author_name 
          FROM estate_announcements ea
          LEFT JOIN users u ON ea.created_by = u.id
          WHERE $where_sql
          ORDER BY ea.pin_to_top DESC, ea.created_at DESC";
$announcements = $conn->query($query);

include 'header.php';
include 'sidebar.php';
?>

<div class="content-wrapper p-3 p-md-4">
    <!-- Header Section -->
    <div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <div class="header-breadcrumbs d-flex align-items-center gap-2 small text-secondary mb-1">
                <a href="index" class="text-decoration-none text-secondary">Zone Dashboard</a>
                <i class="fa-solid fa-chevron-right separator" style="font-size: 0.7rem;"></i>
                <span>Communications</span>
                <i class="fa-solid fa-chevron-right separator" style="font-size: 0.7rem;"></i>
                <span class="text-primary fw-semibold">Zonal Notices &amp; Broadcasts</span>
            </div>
            <h2 class="page-title fw-bold text-slate-900 m-0 d-flex align-items-center gap-2">
                <i class="fa-solid fa-bullhorn text-purple" style="color: #9333ea;"></i> Notices From <?php echo htmlspecialchars($zone_name); ?>
            </h2>
            <p class="text-secondary small m-0 mt-1">Compose and send official zonal notices, dues advisories, and security alerts to residents in <?php echo htmlspecialchars($zone_name); ?>.</p>
        </div>
        <div class="header-actions">
            <button type="button" onclick="openZoneNoticeModal()" class="btn btn-sm text-white px-3 py-2 fw-semibold shadow-sm d-flex align-items-center gap-2" style="background: #9333ea; border-radius: 8px;">
                <i class="fa-solid fa-paper-plane"></i> Send Notice to Zone Residents
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

    <!-- Zone KPI Ribbon -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100 p-3 bg-white rounded-3 border shadow-sm" style="border-color: #e2e8f0;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-secondary small fw-semibold text-uppercase">Total Zonal Notices</span>
                        <div class="fs-3 fw-bold text-slate-900 mt-1"><?php echo number_format($total_zone_notices); ?></div>
                    </div>
                    <div class="p-2 rounded-2" style="background: rgba(168, 85, 247, 0.12); color: #9333ea;">
                        <i class="fa-solid fa-bullhorn fs-5"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100 p-3 bg-white rounded-3 border shadow-sm" style="border-color: #e2e8f0;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-secondary small fw-semibold text-uppercase">Target Zone Reach</span>
                        <div class="fs-3 fw-bold text-slate-900 mt-1"><?php echo number_format($active_residents_cnt); ?> <span class="fs-6 fw-normal text-muted">Residents</span></div>
                    </div>
                    <div class="p-2 rounded-2" style="background: rgba(59, 130, 246, 0.1); color: #2563eb;">
                        <i class="fa-solid fa-users fs-5"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100 p-3 bg-white rounded-3 border shadow-sm" style="border-color: #e2e8f0;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-secondary small fw-semibold text-uppercase">Covered Streets</span>
                        <div class="fs-3 fw-bold text-slate-900 mt-1"><?php echo number_format($active_streets_cnt); ?> <span class="fs-6 fw-normal text-muted">Streets</span></div>
                    </div>
                    <div class="p-2 rounded-2" style="background: rgba(16, 185, 129, 0.1); color: #059669;">
                        <i class="fa-solid fa-road fs-5"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100 p-3 bg-white rounded-3 border shadow-sm" style="border-color: #e2e8f0;">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-secondary small fw-semibold text-uppercase">Urgent Advisories</span>
                        <div class="fs-3 fw-bold text-danger mt-1"><?php echo number_format($urgent_zone_cnt); ?></div>
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
                <div class="col-12 col-md-6">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-start-0" placeholder="Search zonal notices by title or keywords..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <select name="priority" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Priorities</option>
                        <option value="normal" <?php echo ($filter_priority === 'normal') ? 'selected' : ''; ?>>Normal</option>
                        <option value="important" <?php echo ($filter_priority === 'important') ? 'selected' : ''; ?>>Important</option>
                        <option value="urgent" <?php echo ($filter_priority === 'urgent') ? 'selected' : ''; ?>>Urgent</option>
                    </select>
                </div>
                <div class="col-6 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-sm text-white w-100" style="background: #9333ea;">Filter</button>
                    <a href="broadcasts" class="btn btn-sm btn-light border" title="Reset Filters"><i class="fa-solid fa-rotate-right"></i></a>
                </div>
            </form>
        </div>
    </div>

    <!-- Zonal Notices Registry Table -->
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="m-0 fw-bold text-slate-900"><i class="fa-solid fa-list-check me-2" style="color: #9333ea;"></i><?php echo htmlspecialchars($zone_name); ?> Notices Registry</h6>
            <span class="badge bg-light text-secondary border"><?php echo ($announcements) ? $announcements->num_rows : 0; ?> Notices Found</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                <thead class="table-light text-secondary text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.03em;">
                    <tr>
                        <th style="width: 5%;">Pin</th>
                        <th style="width: 38%;">Notice Subject &amp; Preview</th>
                        <th style="width: 14%;">Audience</th>
                        <th style="width: 12%;">Priority</th>
                        <th style="width: 16%;">Date Posted</th>
                        <th style="width: 15%; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($announcements && $announcements->num_rows > 0): ?>
                        <?php while ($ann = $announcements->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <?php if ($ann['pin_to_top']): ?>
                                        <i class="fa-solid fa-thumbtack text-purple" style="color: #9333ea;" title="Pinned to top"></i>
                                    <?php else: ?>
                                        <i class="fa-solid fa-minus text-muted opacity-25"></i>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-slate-900"><?php echo htmlspecialchars($ann['title']); ?></div>
                                    <div class="text-secondary small text-truncate" style="max-width: 400px;">
                                        <?php echo htmlspecialchars(substr($ann['content'], 0, 120)) . (strlen($ann['content']) > 120 ? '...' : ''); ?>
                                    </div>
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
                                    <div class="small fw-semibold"><?php echo date('M j, Y', strtotime($ann['created_at'])); ?></div>
                                    <div class="small text-muted" style="font-size: 0.75rem;">
                                        <?php echo date('g:i A', strtotime($ann['created_at'])); ?>
                                    </div>
                                </td>
                                <td style="text-align: right;">
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-secondary" onclick='viewZoneNotice(<?php echo json_encode($ann); ?>)' title="View Full Notice">
                                            <i class="fa-regular fa-eye"></i>
                                        </button>
                                        <a href="broadcasts?action=delete&id=<?php echo $ann['id']; ?>" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to remove this notice?');" title="Delete Notice">
                                            <i class="fa-regular fa-trash-can"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-bullhorn fs-2 mb-2 opacity-50 d-block" style="color: #9333ea;"></i>
                                <div class="fw-semibold">No Zonal Notices Published Yet</div>
                                <div class="small">Click "Send Notice to Zone Residents" to dispatch your first announcement to residents in <?php echo htmlspecialchars($zone_name); ?>.</div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Compose Zonal Notice Modal -->
<div id="zoneNoticeModal" class="modal-backdrop" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.7); z-index: 1050; align-items: center; justify-content: center; backdrop-filter: blur(8px);">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 650px; width: 95%;">
        <div class="modal-content bg-white rounded-3 shadow-lg border-0 overflow-hidden">
            <form method="POST" action="broadcasts">
                <div class="modal-header px-4 py-3 border-bottom d-flex justify-content-between align-items-center" style="background: rgba(168, 85, 247, 0.08);">
                    <h5 class="modal-title fw-bold text-slate-900 m-0" style="font-size: 1.1rem;">
                        <i class="fa-solid fa-bullhorn me-2" style="color: #9333ea;"></i>Dispatch Notice to <?php echo htmlspecialchars($zone_name); ?>
                    </h5>
                    <button type="button" onclick="closeZoneNoticeModal()" class="btn-close" style="font-size: 0.8rem;"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <!-- Target Audience -->
                        <div class="col-12 col-sm-6">
                            <label class="form-label small fw-bold text-secondary">Target Audience in Zone</label>
                            <select name="target_audience" class="form-select">
                                <option value="all">All Zone Residents &amp; Owners</option>
                                <option value="tenants">Tenants / Residents Only</option>
                                <option value="owners">Property Owners Only</option>
                            </select>
                        </div>

                        <!-- Priority Level -->
                        <div class="col-12 col-sm-6">
                            <label class="form-label small fw-bold text-secondary">Priority Level</label>
                            <select name="priority" class="form-select">
                                <option value="normal">Normal (General Notice)</option>
                                <option value="important">Important (Zonal Dues / Meeting)</option>
                                <option value="urgent">Urgent / Emergency Alert</option>
                            </select>
                        </div>

                        <!-- Subject / Title -->
                        <div class="col-12">
                            <label class="form-label small fw-bold text-secondary">Notice Subject / Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" required placeholder="e.g. Zone A Security Meeting &amp; Waste Collection Schedule">
                        </div>

                        <!-- Content Body -->
                        <div class="col-12">
                            <label class="form-label small fw-bold text-secondary">Notice Message Content <span class="text-danger">*</span></label>
                            <textarea name="content" class="form-control" rows="5" required placeholder="Enter the complete zonal advisory, meeting agenda, or payment instructions..."></textarea>
                        </div>

                        <!-- Dispatch Channels -->
                        <div class="col-12">
                            <label class="form-label small fw-bold text-secondary mb-2">Delivery Channels</label>
                            <div class="d-flex flex-column gap-2 p-3 bg-light rounded-3 border">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="channel_feed" id="zChFeed" checked disabled>
                                    <label class="form-check-label small fw-semibold" for="zChFeed">
                                        <i class="fa-solid fa-bullhorn me-1" style="color: #9333ea;"></i> Display under "Notices From the Zone" in Resident Portal (Default)
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="channel_in_app" id="zChInApp" value="1" checked>
                                    <label class="form-check-label small" for="zChInApp">
                                        <i class="fa-solid fa-bell text-warning me-1"></i> Send In-App Alerts to Zone Residents
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="channel_email" id="zChEmail" value="1">
                                    <label class="form-check-label small" for="zChEmail">
                                        <i class="fa-solid fa-envelope text-success me-1"></i> Send Outbound Broadcast Email to Zone Residents
                                    </label>
                                </div>
                                <div class="form-check mt-1 pt-1 border-top">
                                    <input class="form-check-input" type="checkbox" name="pin_to_top" id="zChPin" value="1">
                                    <label class="form-check-label small fw-semibold" style="color: #7e22ce;" for="zChPin">
                                        <i class="fa-solid fa-thumbtack me-1"></i> Pin this Notice to top of Zonal Feed
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light px-4 py-3 border-top d-flex justify-content-between">
                    <button type="button" onclick="closeZoneNoticeModal()" class="btn btn-sm btn-outline-secondary">Cancel</button>
                    <button type="submit" name="dispatch_zone_notice" class="btn btn-sm text-white px-4 fw-semibold" style="background: #9333ea;">
                        <i class="fa-solid fa-paper-plane me-1"></i> Dispatch Zonal Notice
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Notice Modal -->
<div id="viewZoneNoticeModal" class="modal-backdrop" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.7); z-index: 1050; align-items: center; justify-content: center; backdrop-filter: blur(8px);">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 600px; width: 95%;">
        <div class="modal-content bg-white rounded-3 shadow-lg border-0 overflow-hidden">
            <div class="modal-header bg-light px-4 py-3 border-bottom d-flex justify-content-between align-items-center">
                <span id="viewZPriorityBadge"></span>
                <button type="button" onclick="closeViewZoneNoticeModal()" class="btn-close" style="font-size: 0.8rem;"></button>
            </div>
            <div class="modal-body p-4">
                <h4 id="viewZNoticeTitle" class="fw-bold text-slate-900 mb-2"></h4>
                <div class="d-flex align-items-center gap-3 text-secondary small pb-3 mb-3 border-bottom">
                    <span><i class="fa-regular fa-clock me-1"></i><span id="viewZNoticeDate"></span></span>
                    <span><i class="fa-solid fa-users me-1"></i><span id="viewZNoticeAudience" class="text-capitalize"></span></span>
                </div>
                <div id="viewZNoticeContent" class="text-slate-800" style="line-height: 1.7; white-space: pre-wrap;"></div>
            </div>
            <div class="modal-footer bg-light px-4 py-2 border-top">
                <button type="button" onclick="closeViewZoneNoticeModal()" class="btn btn-sm btn-secondary">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function openZoneNoticeModal() {
    document.getElementById('zoneNoticeModal').style.display = 'flex';
}
function closeZoneNoticeModal() {
    document.getElementById('zoneNoticeModal').style.display = 'none';
}
function viewZoneNotice(ann) {
    document.getElementById('viewZNoticeTitle').innerText = ann.title;
    document.getElementById('viewZNoticeContent').innerText = ann.content;
    document.getElementById('viewZNoticeDate').innerText = new Date(ann.created_at).toLocaleDateString(undefined, {
        month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit'
    });
    document.getElementById('viewZNoticeAudience').innerText = 'Audience: ' + ann.target_audience;

    const prioEl = document.getElementById('viewZPriorityBadge');
    if (ann.priority === 'urgent') {
        prioEl.innerHTML = `<span class="badge bg-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>Urgent</span>`;
    } else if (ann.priority === 'important') {
        prioEl.innerHTML = `<span class="badge bg-warning text-dark"><i class="fa-solid fa-circle-exclamation me-1"></i>Important</span>`;
    } else {
        prioEl.innerHTML = `<span class="badge bg-secondary">Normal</span>`;
    }

    document.getElementById('viewZoneNoticeModal').style.display = 'flex';
}
function closeViewZoneNoticeModal() {
    document.getElementById('viewZoneNoticeModal').style.display = 'none';
}
</script>

<?php include 'footer.php'; ?>
