<?php
// resident/index.php
require_once '../config.php';

require_once '../includes/NoticeManager.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index");
    exit;
}

$user_id = intval($_SESSION['user_id']);
$estate_id = get_estate_id();

// Fetch Resident User Info
$user_res = $conn->query("SELECT * FROM users WHERE id = $user_id AND estate_id = $estate_id");
if ($user_res->num_rows == 0) {
    die("Resident account not found.");
}
$user = $user_res->fetch_assoc();

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

// Fetch Active Tenancy (for move-in / occupancy status)
$tenancy_query = "SELECT * FROM tenancies WHERE resident_id = $user_id AND estate_id = $estate_id AND status = 'Active' ORDER BY id DESC LIMIT 1";
$tenancy = $conn->query($tenancy_query)->fetch_assoc();

// Financial KPIs calculation
$finance_stats = $conn->query("SELECT 
    COALESCE(SUM(CASE WHEN status != 'paid' THEN balance ELSE 0 END), 0) as outstanding_balance,
    COALESCE(SUM(amount_paid), 0) as total_paid,
    MIN(CASE WHEN status != 'paid' THEN due_date ELSE NULL END) as next_due_date
    FROM invoices WHERE user_id = $user_id AND estate_id = $estate_id")->fetch_assoc();

$total_payments_res = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE user_id = $user_id AND status = 'paid'");
$total_paid_payments = $total_payments_res->fetch_assoc()['total'] ?? 0;

// Monthly Payment Aggregates (Current Year)
$current_year = date('Y');
$monthly_payments = array_fill(1, 12, 0);
$m_query = "SELECT MONTH(paid_at) as m, SUM(amount) as total 
            FROM payments 
            WHERE user_id = $user_id AND status = 'paid' AND YEAR(paid_at) = $current_year 
            GROUP BY MONTH(paid_at)";
$m_res = $conn->query($m_query);
if ($m_res) {
    while ($m_row = $m_res->fetch_assoc()) {
        $monthly_payments[intval($m_row['m'])] = floatval($m_row['total']);
    }
}
$max_monthly = max(array_values($monthly_payments));
if ($max_monthly == 0) $max_monthly = 1;

// Visitor Stats
$visitor_stats = $conn->query("SELECT 
    COUNT(id) as total_visitors,
    SUM(CASE WHEN MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE()) THEN 1 ELSE 0 END) as visitors_this_month
    FROM visitors WHERE resident_id = $user_id")->fetch_assoc();

// Household Stats
$flat_id = $resident_info['flat_id'] ?? 0;
$vehicles_count = 0;
$co_residents_count = 0;
if ($flat_id) {
    $vehicles_count = $conn->query("SELECT COUNT(id) as cnt FROM vehicles WHERE flat_id = $flat_id")->fetch_assoc()['cnt'] ?? 0;
    $co_residents_count = $conn->query("SELECT COUNT(id) as cnt FROM residents WHERE flat_id = $flat_id AND status = 'active'")->fetch_assoc()['cnt'] ?? 0;
}

// Announcements & Zonal Notices
$notice_data = NoticeManager::getNoticesForResident($conn, $user_id, $estate_id, 5);
$announcements = $notice_data['results'];
$resident_zone_name = $notice_data['resident_zone_name'] ?? ($resident_info['zone_name'] ?? null);

// Outstanding Invoices count
$unpaid_invoices_cnt = $conn->query("SELECT COUNT(id) as cnt FROM invoices WHERE user_id = $user_id AND status != 'paid'")->fetch_assoc()['cnt'] ?? 0;
include 'header.php';
include 'sidebar.php';
?>

<div class="d-flex flex-column gap-4">
    <!-- ==========================================
         FUTURISTIC RESIDENT HERO IDENTITY BANNER
         ========================================== -->
    <div class="resident-hero-banner">
        <div class="resident-hero-content">
            <?php 
            $profile_img = '';
            $raw_img = $resident_info['image_path'] ?? '';
            if (!empty($raw_img)) {
                if (file_exists($raw_img)) {
                    $profile_img = $raw_img;
                } elseif (file_exists('../' . ltrim($raw_img, './'))) {
                    $profile_img = '../' . ltrim($raw_img, './');
                }
            }
            ?>
            <div class="resident-avatar-glow">
                <?php if (!empty($profile_img)): ?>
                    <img src="<?= htmlspecialchars($profile_img) ?>" alt="Resident Avatar">
                <?php else: ?>
                    <div class="resident-avatar-placeholder"><i class="fa-solid fa-user"></i></div>
                <?php endif; ?>
            </div>
            <div>
                <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                    <h1 class="h3 font-bold m-0 text-white" style="letter-spacing: -0.02em;">
                        <?= htmlspecialchars($user['name']) ?>
                    </h1>
                    <span class="mature-badge mature-badge-sky" style="font-size: 0.7rem;">
                        <i class="fa-solid fa-shield-halved me-1"></i> Verified Resident
                    </span>
                </div>
                <div class="d-flex align-items-center gap-2 text-slate-300 small mb-2" style="font-size: 0.9rem;">
                    <i class="fa-solid fa-location-dot text-info"></i>
                    <span><?= htmlspecialchars(($resident_info['street_name'] ?? 'Main Street') . ' &bull; ' . ($resident_info['building_name'] ?? 'Block A') . ' &bull; Flat ' . ($resident_info['flat_number'] ?? 'N/A')) ?></span>
                </div>
                <div class="hero-tags">
                    <span class="hero-tag hero-tag-id">
                        <i class="fa-solid fa-fingerprint me-1"></i> <?= htmlspecialchars($resident_info['custom_id'] ?? ('RES-' . str_pad($user_id, 5, '0', STR_PAD_LEFT))) ?>
                    </span>
                    <span class="hero-tag hero-tag-role">
                        <i class="fa-solid fa-user-tag me-1"></i> <?= htmlspecialchars(ucfirst($resident_info['relationship'] ?? 'Tenant')) ?>
                    </span>
                    <span class="hero-tag hero-tag-status">
                        <i class="fa-solid fa-circle-check me-1"></i> <?= htmlspecialchars(ucfirst($tenancy['status'] ?? 'Active')) ?> Occupancy
                    </span>
                </div>
            </div>
        </div>
        <div>
            <a href="finance" class="hero-btn-action">
                <i class="fa-solid fa-credit-card"></i>
                <span>Pay Bills & Invoices</span>
                <i class="fa-solid fa-arrow-right small ms-1"></i>
            </a>
        </div>
    </div>

    <!-- ==========================================
         FUTURISTIC KPI CARDS (4 PILLARS)
         ========================================== -->
    <div class="row g-3">
        <!-- Pillar 1: Outstanding Balance -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="resident-kpi-card kpi-accent-danger h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="kpi-title">Outstanding Balance</span>
                    <div class="kpi-icon-wrap" style="background: rgba(239, 68, 68, 0.1); color: #ef4444; border-color: rgba(239, 68, 68, 0.25);">
                        <i class="fa-solid fa-file-invoice-dollar"></i>
                    </div>
                </div>
                <div class="kpi-value text-danger" style="font-size: 1.85rem; font-weight: 800; letter-spacing: -0.02em;">
                    ₦<?= number_format($finance_stats['outstanding_balance'], 2) ?>
                </div>
                <div class="kpi-meta justify-content-between mt-2 pt-2 border-top border-light-subtle">
                    <span>Pending: <strong><?= $unpaid_invoices_cnt ?> Invoices</strong></span>
                    <a href="finance" class="text-decoration-none text-danger small fw-semibold">Pay Now &rarr;</a>
                </div>
            </div>
        </div>

        <!-- Pillar 2: Total Settled -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="resident-kpi-card kpi-accent-success h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="kpi-title">Total Payments Settled</span>
                    <div class="kpi-icon-wrap" style="background: rgba(16, 185, 129, 0.1); color: #10b981; border-color: rgba(16, 185, 129, 0.25);">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                </div>
                <div class="kpi-value text-success" style="font-size: 1.85rem; font-weight: 800; letter-spacing: -0.02em;">
                    ₦<?= number_format($total_paid_payments, 2) ?>
                </div>
                <div class="kpi-meta justify-content-between mt-2 pt-2 border-top border-light-subtle">
                    <span>Verified Receipts</span>
                    <a href="receipts" class="text-decoration-none text-success small fw-semibold">View Proof &rarr;</a>
                </div>
            </div>
        </div>

        <!-- Pillar 3: Next Schedule -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="resident-kpi-card kpi-accent-primary h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="kpi-title">Next Payment Schedule</span>
                    <div class="kpi-icon-wrap" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6; border-color: rgba(59, 130, 246, 0.25);">
                        <i class="fa-regular fa-calendar-check"></i>
                    </div>
                </div>
                <div class="kpi-value text-primary" style="font-size: 1.45rem; font-weight: 700; margin: 0.4rem 0;">
                    <?= $finance_stats['next_due_date'] ? date('M j, Y', strtotime($finance_stats['next_due_date'])) : 'All Clear' ?>
                </div>
                <div class="kpi-meta justify-content-between mt-2 pt-2 border-top border-light-subtle">
                    <span>Auto Estate Assessment</span>
                    <span class="mature-badge mature-badge-sky"><i class="fa-solid fa-clock me-1"></i>Scheduled</span>
                </div>
            </div>
        </div>

        <!-- Pillar 4: Visitor Flow Radar -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="resident-kpi-card kpi-accent-purple h-100">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="kpi-title">Visitor Passes Radar</span>
                    <div class="kpi-icon-wrap" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6; border-color: rgba(139, 92, 246, 0.25);">
                        <i class="fa-solid fa-id-badge"></i>
                    </div>
                </div>
                <div class="kpi-value" style="color: #7c3aed; font-size: 1.85rem; font-weight: 800; letter-spacing: -0.02em;">
                    <?= number_format($visitor_stats['visitors_this_month'] ?? 0) ?> <span style="font-size: 0.95rem; font-weight: 500; color: #64748b;">This Month</span>
                </div>
                <div class="kpi-meta justify-content-between mt-2 pt-2 border-top border-light-subtle">
                    <span>Total: <strong><?= number_format($visitor_stats['total_visitors'] ?? 0) ?> Passes</strong></span>
                    <a href="visitors" class="text-decoration-none small fw-semibold" style="color: #7c3aed;">Manage &rarr;</a>
                </div>
            </div>
        </div>
    </div>

    <!-- ==========================================
         MAIN CONTENT & ANALYTICS SECTION (2 COLUMNS)
         ========================================== -->
    <div class="row g-4">
        <!-- Left Column: Visual Payment Velocity & Household -->
        <div class="col-12 col-lg-8 d-flex flex-column gap-4">
            <!-- Payment Velocity Chart -->
            <div class="resident-glass-panel">
                <div class="resident-card-header">
                    <div class="resident-card-title">
                        <i class="fa-solid fa-chart-column text-primary"></i> <?= $current_year ?> Payment Collection Velocity
                    </div>
                    <span class="mature-badge mature-badge-slate"><i class="fa-regular fa-calendar me-1"></i> Monthly Realized</span>
                </div>
                <div class="resident-card-body">
                    <div class="resident-chart-container">
                        <?php 
                        $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                        for($i=1; $i<=12; $i++): 
                            $val = $monthly_payments[$i];
                            $height_pct = ($val / $max_monthly) * 100;
                            if ($height_pct < 6 && $val > 0) $height_pct = 6;
                        ?>
                            <div class="resident-bar-group">
                                <div class="resident-bar" style="height: <?= max(4, $height_pct) ?>%;" data-val="₦<?= number_format($val) ?>"></div>
                                <div class="resident-bar-label"><?= $months[$i-1] ?></div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <!-- Household Overview -->
            <div class="resident-glass-panel">
                <div class="resident-card-header">
                    <div class="resident-card-title">
                        <i class="fa-solid fa-house-user text-primary"></i> Household & Unit Occupancy Overview
                    </div>
                    <a href="property" class="btn btn-sm btn-outline-primary rounded-pill px-3" style="font-size: 0.8rem;">
                        View Property File <i class="fa-solid fa-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="resident-card-body">
                    <div class="row g-3">
                        <div class="col-12 col-sm-6">
                            <div class="p-3 rounded-3 d-flex align-items-center gap-3 border" style="background: rgba(59, 130, 246, 0.05); border-color: rgba(59, 130, 246, 0.2) !important;">
                                <div class="kpi-icon-wrap" style="background: rgba(59, 130, 246, 0.15); color: #2563eb; width: 50px; height: 50px; font-size: 1.35rem;">
                                    <i class="fa-solid fa-users"></i>
                                </div>
                                <div>
                                    <div class="fw-bold fs-4 m-0 text-slate-900"><?= $co_residents_count ?></div>
                                    <div class="small text-secondary fw-semibold">Active Unit Occupants</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <div class="p-3 rounded-3 d-flex align-items-center gap-3 border" style="background: rgba(16, 185, 129, 0.05); border-color: rgba(16, 185, 129, 0.2) !important;">
                                <div class="kpi-icon-wrap" style="background: rgba(16, 185, 129, 0.15); color: #059669; width: 50px; height: 50px; font-size: 1.35rem;">
                                    <i class="fa-solid fa-car"></i>
                                </div>
                                <div>
                                    <div class="fw-bold fs-4 m-0 text-slate-900"><?= $vehicles_count ?></div>
                                    <div class="small text-secondary fw-semibold">Registered Vehicles</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Announcements & Quick Actions -->
        <div class="col-12 col-lg-4 d-flex flex-column gap            <!-- Estate Announcements & Zonal Notices Feed -->
            <div class="resident-glass-panel">
                <div class="resident-card-header d-flex justify-content-between align-items-center">
                    <div class="resident-card-title">
                        <i class="fa-solid fa-bullhorn text-warning"></i> Estate Notices &amp; Broadcasts
                    </div>
                    <a href="notices" class="mature-badge mature-badge-amber text-decoration-none" style="cursor: pointer;">
                        View All <i class="fa-solid fa-arrow-right ms-1 small"></i>
                    </a>
                </div>
                <div class="resident-card-body p-3">
                    <?php if ($announcements && $announcements->num_rows > 0): ?>
                        <div class="d-flex flex-column gap-3">
                            <?php while ($ann = $announcements->fetch_assoc()): ?>
                                <div class="p-3 rounded-3 border bg-white-subtle notice-card-item position-relative" style="border-color: rgba(226, 232, 240, 0.8) !important; cursor: pointer; transition: all 0.2s ease;" onclick='openNoticeModal(<?php echo json_encode($ann); ?>)'>
                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-1.5">
                                        <div class="d-flex align-items-center gap-1">
                                            <?php if (!empty($ann['zone_id'])): ?>
                                                <span class="mature-badge" style="background: rgba(168, 85, 247, 0.12); color: #7e22ce; font-size: 0.68rem; padding: 0.15rem 0.45rem;">
                                                    <i class="fa-solid fa-layer-group me-1"></i><?= htmlspecialchars($ann['zone_name'] ?? 'Zonal Notice') ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="mature-badge mature-badge-amber" style="font-size: 0.68rem; padding: 0.15rem 0.45rem;">
                                                    <i class="fa-solid fa-globe me-1"></i>Estate Broadcast
                                                </span>
                                            <?php endif; ?>

                                            <?php if ($ann['priority'] == 'urgent'): ?>
                                                <span class="mature-badge mature-badge-crimson" style="font-size: 0.68rem; padding: 0.15rem 0.45rem;">
                                                    <i class="fa-solid fa-triangle-exclamation me-1"></i>URGENT
                                                </span>
                                            <?php endif; ?>

                                            <?php if (!empty($ann['pin_to_top'])): ?>
                                                <i class="fa-solid fa-thumbtack text-primary ms-1" style="font-size: 0.72rem;" title="Pinned Notice"></i>
                                            <?php endif; ?>
                                        </div>
                                        <span class="small text-secondary" style="font-size: 0.72rem;">
                                            <i class="fa-regular fa-clock me-1"></i> <?= date('M j, Y', strtotime($ann['created_at'])) ?>
                                        </span>
                                    </div>
                                    <h6 class="fw-bold mb-1 text-slate-900" style="font-size: 0.92rem;"><?= htmlspecialchars($ann['title']) ?></h6>
                                    <p class="small text-secondary m-0 text-truncate" style="line-height: 1.4;"><?= htmlspecialchars($ann['content']) ?></p>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-secondary small">
                            <i class="fa-regular fa-bell-slash fs-4 d-block mb-2 text-muted"></i>
                            No recent estate notices or broadcast alerts.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Futuristic Quick Actions Dock -->
            <div class="resident-glass-panel">
                <div class="resident-card-header">
                    <div class="resident-card-title">
                        <i class="fa-solid fa-bolt text-danger"></i> Quick Operations Dock
                    </div>
                </div>
                <div class="resident-card-body">
                    <div class="resident-quick-dock">
                        <a href="notices" class="resident-dock-item dock-item-purple">
                            <span class="d-flex align-items-center gap-2">
                                <i class="fa-solid fa-bullhorn"></i> View All Notices &amp; Broadcasts
                            </span>
                            <i class="fa-solid fa-chevron-right small"></i>
                        </a>
                        <a href="visitors?action=new" class="resident-dock-item dock-item-primary">
                            <span class="d-flex align-items-center gap-2">
                                <i class="fa-solid fa-user-plus"></i> Pre-register Visitor Access
                            </span>
                            <i class="fa-solid fa-chevron-right small"></i>
                        </a>
                        <a href="report_issue" class="resident-dock-item dock-item-amber">
                            <span class="d-flex align-items-center gap-2">
                                <i class="fa-solid fa-screwdriver-wrench"></i> Log Maintenance Ticket
                            </span>
                            <i class="fa-solid fa-chevron-right small"></i>
                        </a>
                        <a href="community_chat" class="resident-dock-item dock-item-primary">
                            <span class="d-flex align-items-center gap-2">
                                <i class="fa-solid fa-comments"></i> Resident Community Forum
                            </span>
                            <i class="fa-solid fa-chevron-right small"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Notice Details Modal -->
<div id="resNoticeModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.75); z-index: 1050; align-items: center; justify-content: center; backdrop-filter: blur(8px);">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 600px; width: 95%;">
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
            <div class="modal-footer px-4 py-3 border-top d-flex justify-content-between" style="background: #f8fafc;">
                <a href="notices" class="btn btn-sm btn-outline-primary">Open Notices Hub</a>
                <button type="button" onclick="closeNoticeModal()" class="btn btn-sm btn-secondary">Close</button>
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
