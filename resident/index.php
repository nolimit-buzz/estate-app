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

$outstanding_balance = floatval($finance_stats['outstanding_balance'] ?? 0);
$total_paid = floatval($finance_stats['total_paid'] ?? 0);
$next_due_date = $finance_stats['next_due_date'] ?? null;

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

// Pending Contact Change Request & Unread Contact Decision Notifications
$pending_contact_req = $conn->query("SELECT * FROM contact_change_requests WHERE user_id = $user_id AND estate_id = $estate_id AND status = 'pending' LIMIT 1")->fetch_assoc();
$recent_contact_update_notif = $conn->query("SELECT * FROM notifications WHERE user_id = $user_id AND estate_id = $estate_id AND is_read = 0 AND type IN ('contact_approved', 'contact_rejected') ORDER BY id DESC LIMIT 1")->fetch_assoc();

// Fetch Recent Activities for Native Mobile Feed (Image 1 Screen 2)
$mobile_activities = [];
$act_q1 = $conn->query("SELECT 'payment' as type, amount, COALESCE(paid_at, created_at) as created_at, 'Payment Settled' as title, 'Verified via Paystack/Bank' as subtitle FROM payments WHERE user_id = $user_id AND estate_id = $estate_id AND status = 'paid' ORDER BY COALESCE(paid_at, created_at) DESC LIMIT 2");
if ($act_q1) while($r = $act_q1->fetch_assoc()) $mobile_activities[] = $r;

$act_q2 = $conn->query("SELECT 'visitor' as type, 0 as amount, name as title, CONCAT('Pass: ', status) as subtitle, created_at FROM visitors WHERE resident_id = $user_id AND estate_id = $estate_id ORDER BY id DESC LIMIT 2");
if ($act_q2) while($r = $act_q2->fetch_assoc()) $mobile_activities[] = $r;

$act_q3 = $conn->query("SELECT 'maintenance' as type, 0 as amount, title as title, CONCAT('Ticket: ', status) as subtitle, created_at FROM maintenance_requests WHERE user_id = $user_id AND estate_id = $estate_id ORDER BY id DESC LIMIT 2");
if ($act_q3) while($r = $act_q3->fetch_assoc()) $mobile_activities[] = $r;

if (!empty($mobile_activities)) {
    usort($mobile_activities, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    $mobile_activities = array_slice($mobile_activities, 0, 4);
}

// Paid payment stats (count + last payment date)
$pay_meta = $conn->query("SELECT COUNT(id) as cnt, MAX(COALESCE(paid_at, created_at)) as last_paid FROM payments WHERE user_id = $user_id AND estate_id = $estate_id AND status = 'paid'")->fetch_assoc();
$paid_count = intval($pay_meta['cnt'] ?? 0);
$last_payment_date = $pay_meta['last_paid'] ?? null;

// Last 6 months billed vs paid series (for the dashboard chart)
$series = [];
for ($i = 5; $i >= 0; $i--) {
    $series[date('Y-m', strtotime("first day of -$i month"))] = ['label' => date('M', strtotime("first day of -$i month")), 'billed' => 0, 'paid' => 0];
}
$bill_q = $conn->query("SELECT DATE_FORMAT(COALESCE(issue_date, created_at), '%Y-%m') ym, SUM(amount) total FROM invoices WHERE user_id = $user_id AND estate_id = $estate_id AND COALESCE(issue_date, created_at) >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH) GROUP BY ym");
if ($bill_q) while ($b = $bill_q->fetch_assoc()) if (isset($series[$b['ym']])) $series[$b['ym']]['billed'] = floatval($b['total']);
$paid_q = $conn->query("SELECT DATE_FORMAT(COALESCE(paid_at, created_at), '%Y-%m') ym, SUM(amount) total FROM payments WHERE user_id = $user_id AND estate_id = $estate_id AND status = 'paid' AND COALESCE(paid_at, created_at) >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH) GROUP BY ym");
if ($paid_q) while ($p = $paid_q->fetch_assoc()) if (isset($series[$p['ym']])) $series[$p['ym']]['paid'] = floatval($p['total']);
$chart_labels = array_column($series, 'label');
$chart_billed = array_column($series, 'billed');
$chart_paid = array_column($series, 'paid');
$chart_paid_mobile = array_values($chart_paid);
$max_monthly = max(1, max($chart_paid_mobile));

// Latest community chat messages
$recent_messages = [];
$msg_q = $conn->query("SELECT c.id, c.user_id, c.message, c.created_at, u.name FROM community_chat c LEFT JOIN users u ON c.user_id = u.id WHERE c.estate_id = $estate_id ORDER BY c.id DESC LIMIT 4");
if ($msg_q) while ($m = $msg_q->fetch_assoc()) $recent_messages[] = $m;

// Latest invoices
$recent_invoices = [];
$inv_q = $conn->query("SELECT id, invoice_number, title, amount, balance, status, issue_date, due_date, created_at FROM invoices WHERE user_id = $user_id AND estate_id = $estate_id ORDER BY id DESC LIMIT 5");
if ($inv_q) while ($iv = $inv_q->fetch_assoc()) $recent_invoices[] = $iv;

include 'header.php';
include 'sidebar.php';
?>

<!-- ==========================================
     NATIVE MOBILE APP DASHBOARD (< 992px)
     Matching User Provided UI Reference Designs
     ========================================== -->
<div class="mobile-only mb-4">
    <!-- Contact Change Request Mobile Banner -->
    <?php if ($pending_contact_req): ?>
        <a href="notifications" class="px-3 py-2 mb-2 glass-notice-banner d-flex align-items-center gap-2.5 text-decoration-none" style="border-left: 4px solid #d97706 !important;">
            <div class="glass-icon-circle hero-icon-circle glass-icon-light-amber" style="width: 32px; height: 32px; min-width: 32px; min-height: 32px; font-size: 0.85rem;">
                <i class="fa-solid fa-hourglass-half"></i>
            </div>
            <div style="flex: 1; min-width: 0; overflow: hidden;">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-0.5">
                    <span class="text-uppercase fw-bold text-warning-emphasis" style="font-size: 0.65rem; letter-spacing: 0.05em;">Contact Update</span>
                    <span class="badge bg-warning text-dark" style="font-size: 0.65rem;">Pending</span>
                </div>
                <div class="fw-bold text-slate-900 small text-truncate">Awaiting Admin Approval</div>
                <div class="text-muted small text-truncate" style="font-size: 0.75rem;">Under review by Central &amp; Zonal Admins &rarr;</div>
            </div>
        </a>
    <?php elseif ($recent_contact_update_notif): ?>
        <a href="notifications" class="px-3 py-2 mb-2 glass-notice-banner d-flex align-items-center gap-2.5 text-decoration-none" style="border-left: 4px solid <?php echo ($recent_contact_update_notif['type'] === 'contact_approved') ? '#059669' : '#dc2626'; ?> !important;">
            <div class="glass-icon-circle hero-icon-circle <?php echo ($recent_contact_update_notif['type'] === 'contact_approved') ? 'glass-icon-light-emerald' : 'glass-icon-rose'; ?>" style="width: 32px; height: 32px; min-width: 32px; min-height: 32px; font-size: 0.85rem;">
                <i class="fa-solid <?php echo ($recent_contact_update_notif['type'] === 'contact_approved') ? 'fa-check' : 'fa-xmark'; ?>"></i>
            </div>
            <div style="flex: 1; min-width: 0; overflow: hidden;">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-0.5">
                    <span class="text-uppercase fw-bold text-muted" style="font-size: 0.65rem; letter-spacing: 0.05em;">Contact Update</span>
                    <span class="badge <?php echo ($recent_contact_update_notif['type'] === 'contact_approved') ? 'bg-success' : 'bg-danger'; ?>" style="font-size: 0.65rem;">
                        <?php echo ($recent_contact_update_notif['type'] === 'contact_approved') ? 'Approved' : 'Declined'; ?>
                    </span>
                </div>
                <div class="fw-bold text-slate-900 small text-truncate"><?= htmlspecialchars($recent_contact_update_notif['title']) ?></div>
                <div class="text-muted small text-truncate" style="font-size: 0.75rem;"><?= htmlspecialchars($recent_contact_update_notif['message']) ?></div>
            </div>
        </a>
    <?php endif; ?>

    <!-- 1. Notification Pill Banner (Image 1 Fixed & Modernized) -->
    <?php if ($announcements && $announcements->num_rows > 0): 
        $latest_ann = $announcements->fetch_assoc();
        $announcements->data_seek(0);
    ?>
        <div class="px-3 py-2 mb-2 glass-notice-banner d-flex align-items-center gap-2.5" style="border-left: 4px solid var(--mob-primary) !important; cursor: pointer;" onclick='openNoticeModal(<?php echo json_encode($latest_ann); ?>)'>
            <div class="glass-icon-circle hero-icon-circle glass-icon-light-orange" style="width: 32px; height: 32px; min-width: 32px; min-height: 32px; font-size: 0.85rem;">
                <i class="fa-solid fa-bullhorn"></i>
            </div>
            <div style="flex: 1; min-width: 0; max-width: calc(100% - 44px); overflow: hidden;">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-0.5">
                    <span class="text-uppercase fw-bold text-muted" style="font-size: 0.64rem; letter-spacing: 0.05em;">Estate Notice</span>
                    <span class="text-muted small flex-shrink-0" style="font-size: 0.68rem;"><?= date('M j', strtotime($latest_ann['created_at'])) ?></span>
                </div>
                <div class="fw-bold text-slate-900 small text-truncate" style="font-size: 0.82rem;"><?= htmlspecialchars($latest_ann['title']) ?></div>
                <div class="text-muted small text-truncate" style="font-size: 0.72rem;"><?= htmlspecialchars($latest_ann['content']) ?></div>
            </div>
        </div>
    <?php endif; ?>

    <!-- 2. Property & Account Summary (Clean, Minimal, Spacious) -->
    <div class="mobile-section-header">
        <h3 class="mobile-section-title">My Residence</h3>
        <a href="property" class="mobile-section-link">Details &rarr;</a>
    </div>
    <div class="mobile-kpi-grid">
        <a href="property" class="mobile-kpi-card">
            <span class="mobile-kpi-label">Unit Number</span>
            <div class="mobile-kpi-val"><?= htmlspecialchars($resident_info['flat_number'] ?? '101') ?></div>
            <span class="mobile-kpi-sub"><?= htmlspecialchars($resident_info['building_name'] ?? 'Flat Unit') ?></span>
        </a>

        <a href="property" class="mobile-kpi-card">
            <span class="mobile-kpi-label">Tenancy Status</span>
            <div class="mobile-kpi-val text-success" style="font-size: 1.25rem;"><?= htmlspecialchars(ucfirst($tenancy['status'] ?? 'Active')) ?></div>
            <span class="mobile-kpi-sub">Verified Resident</span>
        </a>

        <a href="finance" class="mobile-kpi-card">
            <span class="mobile-kpi-label">Payments Made</span>
            <div class="mobile-kpi-val">₦<?= number_format($total_paid_payments / 1000, 1) ?>k</div>
            <span class="mobile-kpi-sub"><?= ($outstanding_balance > 0) ? '₦' . number_format($outstanding_balance) . ' Due' : 'All Settled' ?></span>
        </a>

        <a href="report_issue" class="mobile-kpi-card">
            <span class="mobile-kpi-label">Active Requests</span>
            <div class="mobile-kpi-val"><?= $conn->query("SELECT COUNT(id) as cnt FROM maintenance_requests WHERE user_id = $user_id AND status IN ('open','in_progress')")->fetch_assoc()['cnt'] ?? 0 ?></div>
            <span class="mobile-kpi-sub">Maintenance Tickets</span>
        </a>
    </div>

    <!-- 3. Occupancy Rates / Collection Velocity Bar Chart (Image 1 Screen 2) -->
    <div class="mobile-chart-card">
        <div class="mobile-chart-header">
            <h4 class="mobile-chart-title">Payment Velocity</h4>
            <div class="mobile-pill-dropdown">
                <span>Monthly</span>
                <i class="fa-solid fa-chevron-down" style="font-size: 0.65rem;"></i>
            </div>
        </div>
        <div class="mobile-bar-chart-body">
            <?php 
            foreach ($chart_labels as $m_idx => $m_label):
                $val = $chart_paid_mobile[$m_idx] ?? 0;
                $pct = ($val / $max_monthly) * 100;
                if ($pct < 12 && $val > 0) $pct = 12;
                if ($val == 0) $pct = 10;
            ?>
                <div class="mobile-bar-col">
                    <div class="mobile-bar-track">
                        <div class="mobile-bar-fill" style="height: <?= max(8, min(100, $pct)) ?>%;"></div>
                    </div>
                    <span class="mobile-bar-month"><?= htmlspecialchars($m_label) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 4. ALL RESIDENT SIDEBAR ICONS DOWN BELOW (Image 2 Style Grid) -->
    <?php 
    if (function_exists('renderMobileSidebarIconsGrid')) {
        renderMobileSidebarIconsGrid('resident');
    }
    ?>

    <!-- 5. Recent Activity Feed (Image 1 Screen 2) -->
    <div class="mobile-section-header">
        <h3 class="mobile-section-title">Recent Activity</h3>
        <a href="finance" class="mobile-section-link">View all <i class="fa-solid fa-chevron-right" style="font-size: 0.7rem;"></i></a>
    </div>
    <div class="mobile-activity-list">
        <?php if (!empty($mobile_activities)): ?>
            <?php foreach ($mobile_activities as $act): ?>
                <?php 
                $icon = 'fa-solid fa-receipt';
                $icon_bg = 'rgba(16, 185, 129, 0.12)';
                $icon_c = '#059669';
                $link = 'finance';
                if ($act['type'] === 'visitor') {
                    $icon = 'fa-solid fa-id-badge';
                    $icon_bg = 'rgba(107, 78, 255, 0.12)';
                    $icon_c = '#6b4eff';
                    $link = 'visitors';
                } elseif ($act['type'] === 'maintenance') {
                    $icon = 'fa-solid fa-screwdriver-wrench';
                    $icon_bg = 'rgba(217, 119, 6, 0.12)';
                    $icon_c = '#d97706';
                    $link = 'report_issue';
                }
                ?>
                <a href="<?= $link ?>" class="mobile-activity-item">
                    <div class="mobile-activity-left">
                        <div class="mobile-activity-icon" style="background: <?= $icon_bg ?>; color: <?= $icon_c ?>;">
                            <i class="<?= $icon ?>"></i>
                        </div>
                        <div class="mobile-activity-text">
                            <h5 class="mobile-activity-title"><?= htmlspecialchars($act['title']) ?></h5>
                            <div class="mobile-activity-meta">
                                <span><?= htmlspecialchars(ucfirst($act['subtitle'])) ?></span> &bull; 
                                <span><?= date('M j, g:i a', strtotime($act['created_at'])) ?></span>
                            </div>
                        </div>
                    </div>
                    <i class="fa-solid fa-chevron-right mobile-activity-chevron"></i>
                </a>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="p-3 text-center text-muted small mobile-activity-empty rounded-3 border">
                No recent activity recorded yet.
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="d-flex flex-column gap-4 desktop-only">
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
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <div class="pill-timeframe-dropdown d-none d-lg-inline-flex" style="background: rgba(255,255,255,0.15); border-color: rgba(255,255,255,0.25); color: #ffffff;" title="Billing Month">
                <i class="fa-regular fa-calendar-days text-white"></i>
                <span><?= date('M Y') ?></span>
            </div>
            <a href="receipts" class="btn btn-outline-light rounded-pill px-3 py-2 fw-semibold d-inline-flex align-items-center gap-1 shadow-sm" style="backdrop-filter: blur(6px); font-size: 0.85rem;">
                <i class="fa-solid fa-receipt me-1"></i> Receipts
            </a>
            <a href="visitors" class="btn btn-outline-light rounded-pill px-3 py-2 fw-semibold d-inline-flex align-items-center gap-1 shadow-sm" style="backdrop-filter: blur(6px); font-size: 0.85rem;">
                <i class="fa-solid fa-id-card-clip me-1"></i> + Guest Pass
            </a>
            <a href="finance" class="hero-btn-action">
                <i class="fa-solid fa-credit-card"></i>
                <span>Pay Bills & Invoices</span>
                <i class="fa-solid fa-arrow-right small ms-1"></i>
            </a>
        </div>
    </div>

    <!-- Active Contact Request or Recent Decision Notification Alert -->
    <?php if ($pending_contact_req): ?>
        <div class="alert alert-warning border-0 rounded-4 shadow-sm p-3 mb-4 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 overflow-hidden" style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border-left: 5px solid #d97706 !important;">
            <div class="d-flex align-items-center gap-3" style="flex: 1; min-width: 0;">
                <div class="glass-icon-circle hero-icon-circle glass-icon-light-amber" style="width: 44px; height: 44px; min-width: 44px; min-height: 44px; font-size: 1.25rem;">
                    <i class="fa-solid fa-hourglass-half fa-spin"></i>
                </div>
                <div style="flex: 1; min-width: 0;">
                    <div class="fw-bold text-dark" style="font-size: 0.98rem;">Contact Information Change Request Under Review</div>
                    <div class="text-secondary small">Your request to update phone/email is awaiting approval by Central Estate Management or your Zonal Administration.</div>
                </div>
            </div>
            <a href="notifications" class="btn btn-sm btn-outline-dark rounded-pill px-3 py-1.5 fw-semibold flex-shrink-0">
                Track in Notification Hub &rarr;
            </a>
        </div>
    <?php elseif ($recent_contact_update_notif): ?>
        <div class="alert <?php echo ($recent_contact_update_notif['type'] === 'contact_approved') ? 'alert-success' : 'alert-danger'; ?> border-0 rounded-4 shadow-sm p-3 mb-4 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 overflow-hidden" style="border-left: 5px solid <?php echo ($recent_contact_update_notif['type'] === 'contact_approved') ? '#059669' : '#dc2626'; ?> !important;">
            <div class="d-flex align-items-center gap-3" style="flex: 1; min-width: 0;">
                <div class="glass-icon-circle hero-icon-circle <?php echo ($recent_contact_update_notif['type'] === 'contact_approved') ? 'glass-icon-light-emerald' : 'glass-icon-rose'; ?>" style="width: 44px; height: 44px; min-width: 44px; min-height: 44px; font-size: 1.25rem;">
                    <i class="fa-solid <?php echo ($recent_contact_update_notif['type'] === 'contact_approved') ? 'fa-circle-check text-success' : 'fa-circle-xmark text-danger'; ?>"></i>
                </div>
                <div style="flex: 1; min-width: 0;">
                    <div class="fw-bold text-dark" style="font-size: 0.98rem;"><?php echo htmlspecialchars($recent_contact_update_notif['title']); ?></div>
                    <div class="text-secondary small"><?php echo htmlspecialchars($recent_contact_update_notif['message']); ?></div>
                </div>
            </div>
            <a href="notifications" class="btn btn-sm btn-primary rounded-pill px-3 py-1.5 fw-semibold flex-shrink-0">
                View in Notification Hub &rarr;
            </a>
        </div>
    <?php endif; ?>

    <!-- ==========================================
         ROW 1: 3 METRIC CARDS (live resident data)
         ========================================== -->
    <div class="ds-metrics-grid">
        <!-- Card 1: My Residence -->
        <div class="ds-metric-card">
            <div class="ds-metric-card-head">
                <h3 class="ds-metric-title">My residence <i class="fa-regular fa-circle-question" title="Assigned residence and tenancy"></i></h3>
                <a href="property" class="ds-see-details-btn">See Details</a>
            </div>
            <div class="ds-metric-body">
                <div class="ds-metric-info-circle">i</div>
                <div class="ds-metric-value"><?= !empty($resident_info['flat_number']) ? 'Flat ' . htmlspecialchars($resident_info['flat_number']) : 'Not assigned' ?></div>
                <span class="ds-metric-trend-pill up">
                    <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars(ucfirst($tenancy['status'] ?? (!empty($resident_info['flat_id']) ? 'Active' : 'Pending'))) ?>
                </span>
            </div>
            <div class="ds-metric-chart-row">
                <div class="ds-metric-sparkline">
                    <svg viewBox="0 0 300 70" preserveAspectRatio="none">
                        <defs>
                            <linearGradient id="resPurpleWaveGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" stop-color="#6366f1" stop-opacity="0.25" />
                                <stop offset="100%" stop-color="#6366f1" stop-opacity="0.0" />
                            </linearGradient>
                        </defs>
                        <path d="M 0 50 Q 40 50, 70 20 T 140 25 T 210 50 T 260 55 L 300 55 L 300 70 L 0 70 Z" fill="url(#resPurpleWaveGrad)" />
                        <path d="M 0 50 Q 40 50, 70 20 T 140 25 T 210 50 T 260 55 L 300 55" fill="none" stroke="#6366f1" stroke-width="2.5" stroke-linecap="round" />
                    </svg>
                </div>
                <div class="ds-metric-caption">
                    <?= htmlspecialchars(trim(($resident_info['building_name'] ?? '') . (!empty($resident_info['street_name']) ? ', ' . $resident_info['street_name'] : ''), ', ') ?: 'No property linked yet') ?><br>
                    <strong><?= intval($co_residents_count) ?></strong> member(s) &bull; <strong><?= intval($vehicles_count) ?></strong> vehicle(s)
                </div>
            </div>
        </div>

        <!-- Card 2: Total Paid -->
        <div class="ds-metric-card">
            <div class="ds-metric-card-head">
                <h3 class="ds-metric-title">Total paid <i class="fa-regular fa-circle-question" title="Settled payments and receipts"></i></h3>
                <a href="receipts" class="ds-see-details-btn">See Details</a>
            </div>
            <div class="ds-metric-body">
                <div class="ds-metric-info-circle">i</div>
                <div class="ds-metric-value">₦<?= number_format($total_paid_payments) ?></div>
                <span class="ds-metric-trend-pill up">
                    <i class="fa-solid fa-receipt"></i> <?= intval($paid_count) ?> payment<?= $paid_count == 1 ? '' : 's' ?>
                </span>
            </div>
            <div class="ds-metric-chart-row">
                <div class="ds-metric-sparkline">
                    <svg viewBox="0 0 300 70" preserveAspectRatio="none">
                        <defs>
                            <linearGradient id="resGreenWaveGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" stop-color="#10b981" stop-opacity="0.25" />
                                <stop offset="100%" stop-color="#10b981" stop-opacity="0.0" />
                            </linearGradient>
                        </defs>
                        <path d="M 0 52 Q 35 22, 60 25 T 120 48 T 170 18 T 225 52 T 265 40 L 300 55 L 300 70 L 0 70 Z" fill="url(#resGreenWaveGrad)" />
                        <path d="M 0 52 Q 35 22, 60 25 T 120 48 T 170 18 T 225 52 T 265 40 L 300 55" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" />
                    </svg>
                </div>
                <div class="ds-metric-caption">
                    Verified cleared payments<br>
                    <?php if ($last_payment_date): ?>last on <strong><?= date('M j, Y', strtotime($last_payment_date)) ?></strong><?php else: ?><strong>No payments yet</strong><?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Card 3: Outstanding Balance -->
        <div class="ds-metric-card">
            <div class="ds-metric-card-head">
                <h3 class="ds-metric-title">Outstanding balance <i class="fa-regular fa-circle-question" title="Unpaid estate dues and invoices"></i></h3>
                <a href="finance" class="ds-see-details-btn">See Details</a>
            </div>
            <div class="ds-metric-body">
                <div class="ds-metric-info-circle">i</div>
                <div class="ds-metric-value">₦<?= number_format($outstanding_balance) ?></div>
                <span class="ds-metric-trend-pill <?= $outstanding_balance > 0 ? 'down' : 'up' ?>">
                    <i class="fa-solid <?= $outstanding_balance > 0 ? 'fa-hourglass-half' : 'fa-circle-check' ?>"></i>
                    <?= $outstanding_balance > 0 ? intval($unpaid_invoices_cnt) . ' unpaid' : 'Cleared' ?>
                </span>
            </div>
            <div class="ds-metric-chart-row">
                <div class="ds-metric-sparkline">
                    <svg viewBox="0 0 300 70" preserveAspectRatio="none">
                        <defs>
                            <linearGradient id="resOrangeWaveGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                                <stop offset="0%" stop-color="#f97316" stop-opacity="0.25" />
                                <stop offset="100%" stop-color="#f97316" stop-opacity="0.0" />
                            </linearGradient>
                        </defs>
                        <path d="M 0 55 Q 50 55, 95 38 T 175 20 T 240 45 L 300 52 L 300 70 L 0 70 Z" fill="url(#resOrangeWaveGrad)" />
                        <path d="M 0 55 Q 50 55, 95 38 T 175 20 T 240 45 L 300 52" fill="none" stroke="#f97316" stroke-width="2.5" stroke-linecap="round" />
                    </svg>
                </div>
                <div class="ds-metric-caption">
                    Pending estate dues<br>
                    <?php if ($outstanding_balance > 0 && $next_due_date): ?>next due <strong><?= date('M j, Y', strtotime($next_due_date)) ?></strong><?php else: ?><strong>All clear</strong><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ==========================================
         ROW 2: DUAL PANELS (Payment chart + Community messages)
         ========================================== -->
    <div class="ds-overview-grid">
        <div class="ds-card-panel">
            <div class="ds-panel-header">
                <div class="ds-panel-title-group">
                    <h4>Payment overview</h4>
                    <p class="ds-panel-subtitle">Billed vs paid &bull; last 6 months</p>
                </div>
                <div class="ds-panel-controls">
                    <div class="ds-chart-legend">
                        <span class="ds-chart-legend-item"><span class="ds-legend-dot purple"></span> Billed</span>
                        <span class="ds-chart-legend-item"><span class="ds-legend-dot green"></span> Paid</span>
                    </div>
                </div>
            </div>
            <div class="ds-chart-container">
                <canvas id="residentRevenueSplineChart"></canvas>
            </div>
        </div>

        <div class="ds-card-panel">
            <div class="ds-panel-header">
                <div class="ds-panel-title-group">
                    <h4>Community</h4>
                    <p class="ds-panel-subtitle">Latest estate conversations</p>
                </div>
            </div>

            <div class="ds-messages-list">
                <?php if (!empty($recent_messages)): ?>
                    <?php
                    $avatar_classes = ['navy', 'purple', 'orange', 'green'];
                    foreach ($recent_messages as $mi => $msg):
                        $m_name = trim($msg['name'] ?? '') ?: 'Resident';
                        $parts = preg_split('/\s+/', $m_name);
                        $m_init = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                        $secs = max(0, time() - strtotime($msg['created_at']));
                        if ($secs < 60) $ago = 'just now';
                        elseif ($secs < 3600) $ago = floor($secs / 60) . ' min ago';
                        elseif ($secs < 86400) $ago = floor($secs / 3600) . ' hr' . (floor($secs / 3600) > 1 ? 's' : '') . ' ago';
                        else $ago = floor($secs / 86400) . ' day' . (floor($secs / 86400) > 1 ? 's' : '') . ' ago';
                    ?>
                    <a href="community_chat" class="ds-message-item">
                        <div class="ds-message-avatar <?= $avatar_classes[$mi % 4] ?>"><?= htmlspecialchars($m_init) ?></div>
                        <div class="ds-message-info">
                            <div class="ds-message-name"><?= htmlspecialchars($m_name) ?><?= intval($msg['user_id']) === $user_id ? ' (You)' : '' ?></div>
                            <div class="ds-message-preview"><?= htmlspecialchars(mb_strimwidth($msg['message'], 0, 60, '…')) ?></div>
                        </div>
                        <div class="ds-message-meta">
                            <span class="ds-message-time"><?= $ago ?></span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted small py-5">
                        <i class="fa-regular fa-comments fs-2 d-block mb-2"></i>
                        No community messages yet.<br>Start the conversation!
                    </div>
                <?php endif; ?>
            </div>

            <a href="community_chat" class="ds-view-all-messages-btn">
                Open community chat <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.72rem;"></i>
            </a>
        </div>
    </div>

    <!-- ==========================================
         ROW 3: RECENT INVOICES (live)
         ========================================== -->
    <div class="ds-property-table-card">
        <div class="ds-panel-header mb-3">
            <div class="ds-panel-title-group">
                <h4>Recent invoices</h4>
                <p class="ds-panel-subtitle">Your latest estate dues &amp; levies</p>
            </div>
            <a href="finance" class="ds-pill-dropdown">
                <i class="fa-solid fa-arrow-right"></i> All bills
            </a>
        </div>

        <?php if (!empty($recent_invoices)): ?>
            <?php foreach ($recent_invoices as $inv):
                $inv_status = strtolower($inv['status']);
                $inv_badge = $inv_status === 'paid' ? 'mature-badge-emerald' : (($inv_status === 'overdue') ? 'mature-badge-crimson' : 'mature-badge-amber');
                $inv_grad = $inv_status === 'paid' ? 'linear-gradient(135deg,#10b981,#34d399)' : 'linear-gradient(135deg,#f59e0b,#fbbf24)';
            ?>
            <div class="ds-property-row">
                <div class="ds-property-info-cell">
                    <div class="ds-property-thumb" style="background: <?= $inv_grad ?>; display: grid; place-items: center; color: #fff;">
                        <i class="fa-solid fa-file-invoice"></i>
                    </div>
                    <div class="ds-property-details">
                        <h5><?= htmlspecialchars($inv['title'] ?: 'Estate charge') ?></h5>
                        <span><?= htmlspecialchars($inv['invoice_number'] ?: ('INV-' . $inv['id'])) ?></span>
                    </div>
                </div>
                <div class="ds-property-col-cell">
                    <small>Issued</small>
                    <span><?= date('j M Y', strtotime($inv['issue_date'] ?: $inv['created_at'])) ?></span>
                </div>
                <div class="ds-property-col-cell">
                    <small>Due</small>
                    <span><?= $inv['due_date'] ? date('j M Y', strtotime($inv['due_date'])) : '—' ?></span>
                </div>
                <div class="ds-property-col-cell">
                    <small>Amount</small>
                    <span>₦<?= number_format($inv['amount'], 2) ?></span>
                </div>
                <div class="ds-property-col-cell">
                    <small>Balance</small>
                    <span>₦<?= number_format($inv['balance'], 2) ?></span>
                </div>
                <div class="ds-property-col-cell">
                    <span class="mature-badge <?= $inv_badge ?>"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $inv_status))) ?></span>
                </div>
                <div>
                    <a href="finance" class="ds-edit-pill-btn">
                        <i class="fa-solid <?= $inv_status === 'paid' ? 'fa-eye' : 'fa-credit-card' ?>"></i> <?= $inv_status === 'paid' ? 'View' : 'Pay' ?>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="text-center py-5 text-muted">
                <i class="fa-regular fa-file-lines fs-2 d-block mb-2"></i>
                No invoices have been issued to you yet.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Chart.js Engine for Dual Area Spline Chart -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('residentRevenueSplineChart');
    if (!ctx) return;

    const chartCtx = ctx.getContext('2d');

    // Expense Purple Gradient
    const purpleGrad = chartCtx.createLinearGradient(0, 0, 0, 240);
    purpleGrad.addColorStop(0, 'rgba(99, 102, 241, 0.28)');
    purpleGrad.addColorStop(1, 'rgba(99, 102, 241, 0.0)');

    // Income Green Gradient
    const greenGrad = chartCtx.createLinearGradient(0, 0, 0, 240);
    greenGrad.addColorStop(0, 'rgba(16, 185, 129, 0.22)');
    greenGrad.addColorStop(1, 'rgba(16, 185, 129, 0.0)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chart_labels) ?>,
            datasets: [
                {
                    label: 'Billed',
                    data: <?= json_encode($chart_billed) ?>,
                    borderColor: '#6366f1',
                    borderWidth: 2.5,
                    backgroundColor: purpleGrad,
                    fill: true,
                    tension: 0.45,
                    pointRadius: 0,
                    pointHoverRadius: 6,
                    pointHoverBackgroundColor: '#6366f1',
                    pointHoverBorderColor: '#ffffff',
                    pointHoverBorderWidth: 2
                },
                {
                    label: 'Paid',
                    data: <?= json_encode($chart_paid) ?>,
                    borderColor: '#10b981',
                    borderWidth: 2,
                    borderDash: [5, 4],
                    backgroundColor: greenGrad,
                    fill: true,
                    tension: 0.45,
                    pointRadius: 0,
                    pointHoverRadius: 5,
                    pointHoverBackgroundColor: '#10b981',
                    pointHoverBorderColor: '#ffffff',
                    pointHoverBorderWidth: 2
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.88)',
                    titleColor: '#ffffff',
                    bodyColor: '#e2e8f0',
                    padding: 10,
                    cornerRadius: 8,
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ₦' + Number(context.parsed.y).toLocaleString();
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false,
                        drawBorder: false
                    },
                    ticks: {
                        color: '#94a3b8',
                        font: {
                            family: 'Outfit, sans-serif',
                            size: 11
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        maxTicksLimit: 5,
                        color: '#94a3b8',
                        font: {
                            family: 'Outfit, sans-serif',
                            size: 11
                        },
                        callback: function(value) {
                            if (value === 0) return '';
                            return '₦' + (value >= 1000 ? (value / 1000) + 'K' : value);
                        }
                    },
                    grid: {
                        color: 'rgba(226, 232, 240, 0.65)',
                        drawBorder: false
                    }
                }
            }
        }
    });
});
</script>

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
                <div id="resModalContent" class="text-slate-800" style="line-height: 1.75; font-size: 0.95rem; white-space: pre-wrap; overflow-wrap: break-word; word-break: break-word; max-width: 100%;"></div>
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
        scopeEl.innerHTML = `<span class="mature-badge" style="background: rgba(107, 78, 255, 0.15); color: #6b4eff;"><i class="fa-solid fa-layer-group me-1"></i>Notice from ${ann.zone_name}</span>`;
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
