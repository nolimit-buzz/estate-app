<?php
// resident/notices.php - Resident Notices & Broadcasts Command Center
require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/NoticeManager.php';

requireLogin();

$user_id = intval($_SESSION['user_id']);
$estate_id = get_estate_id();
$user_name = $_SESSION['name'] ?? 'Resident';

// Mark In-App Broadcast / Notice Notifications as read when visiting notices page
$conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $user_id AND estate_id = $estate_id AND (type = 'estate_broadcast' OR type = 'zone_notice')");

// Fetch Resident Property & Tenancy Link with Zone Context
$res_query = "SELECT r.*, f.number as flat_number, f.floor, b.name as building_name, b.property_number, 
                     s.name as street_name, s.zone_id, z.name as zone_name, z.code as zone_code 
              FROM residents r 
              LEFT JOIN flats f ON r.flat_id = f.id 
              LEFT JOIN buildings b ON f.building_id = b.id 
              LEFT JOIN streets s ON b.street_id = s.id 
              LEFT JOIN zones z ON s.zone_id = z.id
              WHERE r.user_id = $user_id AND r.estate_id = $estate_id 
              ORDER BY r.id DESC LIMIT 1";
$resident_info = $conn->query($res_query)->fetch_assoc();
$resident_zone_id = $resident_info['zone_id'] ?? 0;
$resident_zone_name = $resident_info['zone_name'] ?? 'Assigned Zone';
$resident_zone_code = $resident_info['zone_code'] ?? 'ZONE';

// Filter parameter
$filter_tab = $_GET['tab'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$notice_data = NoticeManager::getNoticesForResident($conn, $user_id, $estate_id, 50, $filter_tab);
$announcements = $notice_data['results'];

// Counts for Tab Pills
$count_all = 0;
$count_estate = 0;
$count_zone = 0;
$count_urgent = 0;

$c_all_data = NoticeManager::getNoticesForResident($conn, $user_id, $estate_id, 100, 'all')['results'];
if ($c_all_data) {
    while ($cr = $c_all_data->fetch_assoc()) {
        $count_all++;
        if (empty($cr['zone_id'])) {
            $count_estate++;
        } elseif ($cr['zone_id'] == $resident_zone_id) {
            $count_zone++;
        }
        if ($cr['priority'] === 'urgent') {
            $count_urgent++;
        }
    }
}

include 'header.php';
include 'sidebar.php';
?>

<div class="d-flex flex-column gap-4">
    <!-- Header Banner -->
    <div class="resident-hero-banner" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);">
        <div class="resident-hero-content">
            <div class="d-flex align-items-center gap-3">
                <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(245, 158, 11, 0.18); color: #f59e0b; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; border: 1px solid rgba(245, 158, 11, 0.3);">
                    <i class="fa-solid fa-bullhorn"></i>
                </div>
                <div>
                    <h2 class="fw-bold text-white mb-1" style="font-size: 1.4rem; letter-spacing: -0.01em;">Official Estate &amp; Zonal Broadcasts</h2>
                    <p class="text-white text-opacity-75 small m-0">
                        Stay informed with executive announcements from Central Administration and localized notices from <strong><?php echo htmlspecialchars($resident_zone_name); ?></strong>.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs & Search -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <!-- Tabs -->
        <div class="d-flex flex-wrap gap-2">
            <a href="notices?tab=all" class="btn btn-sm rounded-pill px-3 fw-semibold <?php echo ($filter_tab === 'all') ? 'btn-primary' : 'btn-light border'; ?>">
                <i class="fa-solid fa-list-ul me-1"></i> All Communications
                <span class="badge bg-white text-dark ms-1" style="font-size: 0.7rem;"><?php echo $count_all; ?></span>
            </a>
            <a href="notices?tab=estate" class="btn btn-sm rounded-pill px-3 fw-semibold <?php echo ($filter_tab === 'estate') ? 'btn-primary' : 'btn-light border'; ?>">
                <i class="fa-solid fa-globe me-1"></i> Estate-Wide
                <span class="badge bg-white text-dark ms-1" style="font-size: 0.7rem;"><?php echo $count_estate; ?></span>
            </a>
            <?php if ($resident_zone_id): ?>
                <a href="notices?tab=zone" class="btn btn-sm rounded-pill px-3 fw-semibold <?php echo ($filter_tab === 'zone') ? 'btn-primary' : 'btn-light border'; ?>">
                    <i class="fa-solid fa-layer-group me-1"></i> Notices from <?php echo htmlspecialchars($resident_zone_name); ?>
                    <span class="badge bg-white text-dark ms-1" style="font-size: 0.7rem;"><?php echo $count_zone; ?></span>
                </a>
            <?php endif; ?>
            <a href="notices?tab=urgent" class="btn btn-sm rounded-pill px-3 fw-semibold <?php echo ($filter_tab === 'urgent') ? 'btn-danger' : 'btn-light border text-danger'; ?>">
                <i class="fa-solid fa-triangle-exclamation me-1"></i> Urgent Advisories
                <span class="badge bg-white text-danger ms-1" style="font-size: 0.7rem;"><?php echo $count_urgent; ?></span>
            </a>
        </div>

        <!-- Search Bar -->
        <div style="min-width: 260px;">
            <form method="GET" action="notices" class="input-group input-group-sm">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($filter_tab); ?>">
                <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                <input type="text" name="search" class="form-control border-start-0" placeholder="Search notices..." value="<?php echo htmlspecialchars($search); ?>">
                <?php if (!empty($search)): ?>
                    <a href="notices?tab=<?php echo htmlspecialchars($filter_tab); ?>" class="btn btn-light border"><i class="fa-solid fa-xmark"></i></a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Notices Stream -->
    <div class="d-flex flex-column gap-3">
        <?php if ($announcements && $announcements->num_rows > 0): ?>
            <?php 
            $found_count = 0;
            while ($ann = $announcements->fetch_assoc()): 
                // Client side search filter if search parameter exists
                if (!empty($search)) {
                    if (stripos($ann['title'], $search) === false && stripos($ann['content'], $search) === false) {
                        continue;
                    }
                }
                $found_count++;
                $is_urgent = ($ann['priority'] === 'urgent');
                $is_important = ($ann['priority'] === 'important');
                $is_zonal = (!empty($ann['zone_id']));
            ?>
                <div class="resident-glass-panel p-4 position-relative border <?php echo $is_urgent ? 'border-danger' : ''; ?>" style="transition: all 0.2s ease; <?php echo $is_urgent ? 'background: rgba(239, 68, 68, 0.03);' : ''; ?>">
                    <!-- Top Ribbon: Scope, Priority, Date -->
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 pb-2 border-bottom">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <?php if ($is_zonal): ?>
                                <span class="mature-badge" style="background: rgba(168, 85, 247, 0.15); color: #7e22ce; font-weight: 600; font-size: 0.75rem; padding: 0.25rem 0.6rem;">
                                    <i class="fa-solid fa-layer-group me-1.5"></i> Notice from <?php echo htmlspecialchars($ann['zone_name'] ?? 'Zonal Hub'); ?>
                                </span>
                            <?php else: ?>
                                <span class="mature-badge mature-badge-amber" style="font-weight: 600; font-size: 0.75rem; padding: 0.25rem 0.6rem;">
                                    <i class="fa-solid fa-globe me-1.5"></i> Central Estate Broadcast
                                </span>
                            <?php endif; ?>

                            <?php if ($is_urgent): ?>
                                <span class="mature-badge mature-badge-crimson" style="font-size: 0.75rem; padding: 0.25rem 0.6rem;">
                                    <i class="fa-solid fa-triangle-exclamation me-1"></i> URGENT ADVISORY
                                </span>
                            <?php elseif ($is_important): ?>
                                <span class="mature-badge mature-badge-amber" style="font-size: 0.75rem; padding: 0.25rem 0.6rem;">
                                    <i class="fa-solid fa-circle-exclamation me-1"></i> Important
                                </span>
                            <?php endif; ?>

                            <?php if (!empty($ann['pin_to_top'])): ?>
                                <span class="mature-badge mature-badge-slate" style="font-size: 0.72rem; padding: 0.2rem 0.5rem;" title="Pinned Announcement">
                                    <i class="fa-solid fa-thumbtack text-primary me-1"></i> Pinned
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex align-items-center gap-2 text-secondary small" style="font-size: 0.8rem;">
                            <span><i class="fa-regular fa-clock me-1"></i><?php echo date('M j, Y • g:i A', strtotime($ann['created_at'])); ?></span>
                        </div>
                    </div>

                    <!-- Notice Title -->
                    <h4 class="fw-bold text-slate-900 mb-2" style="font-size: 1.15rem;"><?php echo htmlspecialchars($ann['title']); ?></h4>

                    <!-- Sender Info -->
                    <div class="d-flex align-items-center gap-2 text-secondary small mb-3" style="font-size: 0.8rem;">
                        <span class="text-slate-700 fw-semibold">
                            <i class="fa-solid fa-circle-user me-1 text-primary"></i>
                            <?php echo htmlspecialchars($ann['sender_name'] ?? ($ann['author_name'] ?? ($is_zonal ? ($ann['zone_name'] . ' Management') : 'Central Estate Administration'))); ?>
                        </span>
                        <span>&bull;</span>
                        <span class="text-muted">Target: <strong class="text-capitalize"><?php echo htmlspecialchars($ann['target_audience']); ?></strong></span>
                    </div>

                    <!-- Notice Content -->
                    <div class="text-slate-800 mb-3" style="line-height: 1.75; font-size: 0.92rem; white-space: pre-wrap;"><?php echo htmlspecialchars($ann['content']); ?></div>

                    <!-- Actions Bar -->
                    <div class="d-flex justify-content-end align-items-center gap-2 pt-2 border-top">
                        <button type="button" class="btn btn-sm btn-light border px-3 rounded-pill text-secondary" onclick='openNoticeModal(<?php echo json_encode($ann); ?>)'>
                            <i class="fa-solid fa-up-right-and-down-left-from-center me-1"></i> Expand Modal View
                        </button>
                    </div>
                </div>
            <?php endwhile; ?>

            <?php if ($found_count === 0): ?>
                <div class="resident-glass-panel p-5 text-center text-secondary">
                    <i class="fa-solid fa-magnifying-glass fs-2 mb-2 text-muted d-block"></i>
                    <h5 class="fw-bold text-slate-900">No matching notices found</h5>
                    <p class="small m-0">No announcements match your search query "<strong><?php echo htmlspecialchars($search); ?></strong>".</p>
                    <a href="notices?tab=<?php echo htmlspecialchars($filter_tab); ?>" class="btn btn-sm btn-outline-primary mt-3">Reset Search</a>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="resident-glass-panel p-5 text-center text-secondary">
                <i class="fa-regular fa-bell-slash fs-1 mb-3 text-muted d-block"></i>
                <h5 class="fw-bold text-slate-900">No Broadcast Notices in this Section</h5>
                <p class="small m-0">There are currently no active notices or advisories under this filter.</p>
                <?php if ($filter_tab !== 'all'): ?>
                    <a href="notices?tab=all" class="btn btn-sm btn-outline-primary mt-3">View All Communications</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal View -->
<div id="resNoticeModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.75); z-index: 1050; align-items: center; justify-content: center; backdrop-filter: blur(8px);">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 620px; width: 95%;">
        <div class="modal-content bg-white rounded-4 shadow-lg border-0 overflow-hidden">
            <div class="modal-header px-4 py-3 border-bottom d-flex justify-content-between align-items-center" style="background: #f8fafc;">
                <div class="d-flex align-items-center gap-2">
                    <span id="resModalScopeBadge"></span>
                    <span id="resModalPrioBadge"></span>
                </div>
                <button type="button" onclick="closeNoticeModal()" class="btn-close" style="font-size: 0.8rem;"></button>
            </div>
            <div class="modal-body p-4">
                <h4 id="resModalTitle" class="fw-bold text-slate-900 mb-2" style="font-size: 1.25rem;"></h4>
                <div class="d-flex align-items-center gap-3 text-secondary small pb-3 mb-3 border-bottom">
                    <span><i class="fa-regular fa-user me-1"></i><span id="resModalSender"></span></span>
                    <span><i class="fa-regular fa-clock me-1"></i><span id="resModalDate"></span></span>
                </div>
                <div id="resModalContent" class="text-slate-800" style="line-height: 1.75; font-size: 0.95rem; white-space: pre-wrap;"></div>
            </div>
            <div class="modal-footer px-4 py-2.5 border-top d-flex justify-content-end" style="background: #f8fafc;">
                <button type="button" onclick="closeNoticeModal()" class="btn btn-sm btn-secondary" style="border-radius: 8px;">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function openNoticeModal(ann) {
    document.getElementById('resModalTitle').innerText = ann.title;
    document.getElementById('resModalContent').innerText = ann.content;
    document.getElementById('resModalSender').innerText = ann.sender_name || ann.author_name || (ann.zone_id ? (ann.zone_name + ' Administration') : 'Central Administration');
    document.getElementById('resModalDate').innerText = new Date(ann.created_at).toLocaleDateString(undefined, {
        month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit'
    });

    const scopeEl = document.getElementById('resModalScopeBadge');
    if (ann.zone_id && ann.zone_name) {
        scopeEl.innerHTML = `<span class="mature-badge" style="background: rgba(168, 85, 247, 0.15); color: #7e22ce;"><i class="fa-solid fa-layer-group me-1"></i>Notice from ${ann.zone_name}</span>`;
    } else {
        scopeEl.innerHTML = `<span class="mature-badge mature-badge-amber"><i class="fa-solid fa-globe me-1"></i>Estate Broadcast</span>`;
    }

    const prioEl = document.getElementById('resModalPrioBadge');
    if (ann.priority === 'urgent') {
        prioEl.innerHTML = `<span class="mature-badge mature-badge-crimson"><i class="fa-solid fa-triangle-exclamation me-1"></i>URGENT</span>`;
    } else if (ann.priority === 'important') {
        prioEl.innerHTML = `<span class="mature-badge mature-badge-amber"><i class="fa-solid fa-circle-exclamation me-1"></i>Important</span>`;
    } else {
        prioEl.innerHTML = ``;
    }

    document.getElementById('resNoticeModal').style.display = 'flex';
}
function closeNoticeModal() {
    document.getElementById('resNoticeModal').style.display = 'none';
}
</script>

<?php include 'footer.php'; ?>
