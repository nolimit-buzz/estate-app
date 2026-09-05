<?php
// resident/index.php
require_once '../config.php';

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

// Fetch Resident Property & Tenancy Link
$res_query = "SELECT r.*, f.number as flat_number, f.floor, b.name as building_name, b.property_number, s.name as street_name 
              FROM residents r 
              LEFT JOIN flats f ON r.flat_id = f.id 
              LEFT JOIN buildings b ON f.building_id = b.id 
              LEFT JOIN streets s ON b.street_id = s.id 
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

// Announcements
$announcements = $conn->query("SELECT * FROM estate_announcements WHERE estate_id = $estate_id ORDER BY created_at DESC LIMIT 5");

// Outstanding Invoices count
$unpaid_invoices_cnt = $conn->query("SELECT COUNT(id) as cnt FROM invoices WHERE user_id = $user_id AND status != 'paid'")->fetch_assoc()['cnt'] ?? 0;
include 'header.php';
include 'sidebar.php';
?>
<style>
    /* Banner Card */
    .profile-banner {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        color: white;
        border-radius: 1.25rem;
        padding: 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1.5rem;
        box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.2);
    }
    .profile-info { display: flex; align-items: center; gap: 1.5rem; }
    .profile-img { width: 80px; height: 80px; border-radius: 50%; border: 3px solid rgba(255,255,255,0.2); object-fit: cover; background: #334155; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: white; }
    .profile-details h1 { font-family: 'Outfit', sans-serif; font-size: 1.75rem; font-weight: 700; margin-bottom: 0.25rem; }
    .tags { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.5rem; }
    .tag { font-size: 0.75rem; padding: 0.25rem 0.75rem; border-radius: 9999px; font-weight: 600; text-transform: uppercase; }
    .tag-owner { background: #dcfce7; color: #15803d; }
    .tag-tenant { background: #e0f2fe; color: #0369a1; }
    .tag-id { background: rgba(255,255,255,0.15); color: white; font-family: monospace; }

    /* KPI Grid */
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; }
    .kpi-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .kpi-title { font-size: 0.85rem; font-weight: 600; color: #64748b; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em; }
    .kpi-value { font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 700; color: #0f172a; }
    .kpi-sub { font-size: 0.8rem; color: #64748b; margin-top: 0.5rem; }

    /* Section Layout */
    .grid-2 { display: grid; grid-template-columns: 2fr 1fr; gap: 2rem; }
    @media (max-width: 900px) { .grid-2 { grid-template-columns: 1fr; } }

    .card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 1rem; }
    .card-title { font-family: 'Outfit', sans-serif; font-size: 1.15rem; font-weight: 700; }

    /* Monthly Payment Bar Chart */
    .chart-container { display: flex; align-items: flex-end; justify-content: space-between; height: 180px; padding-top: 1rem; gap: 0.5rem; }
    .bar-group { display: flex; flex-direction: column; align-items: center; flex: 1; height: 100%; justify-content: flex-end; }
    .bar { width: 100%; max-width: 28px; background: linear-gradient(180deg, #3b82f6 0%, #1d4ed8 100%); border-radius: 4px 4px 0 0; transition: height 0.5s ease; position: relative; }
    .bar-label { font-size: 0.7rem; color: #64748b; margin-top: 0.5rem; font-weight: 600; }
    .bar:hover::after { content: attr(data-val); position: absolute; top: -25px; left: 50%; transform: translateX(-50%); background: #0f172a; color: white; font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; white-space: nowrap; }

    /* Announcements List */
    .announcement-item { padding: 1rem 0; border-bottom: 1px solid #e2e8f0; }
    .announcement-item:last-child { border-bottom: none; }
    .announcement-meta { font-size: 0.75rem; color: #64748b; margin-bottom: 0.25rem; display: flex; gap: 0.5rem; }
    .badge-urgent { background: #fee2e2; color: #991b1b; padding: 2px 6px; border-radius: 4px; font-weight: 700; }

    .btn-pay { background: #10b981; color: white; padding: 0.75rem 1.5rem; border-radius: 0.5rem; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; transition: background 0.2s; }
    .btn-pay:hover { background: #059669; }
</style>

<div class="d-flex flex-column gap-4">
    <!-- Resident Profile Banner -->
    <div class="profile-banner">
        <div class="profile-info">
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
            <?php if (!empty($profile_img)): ?>
                <img src="<?= htmlspecialchars($profile_img) ?>" class="profile-img" alt="Profile">
            <?php else: ?>
                <div class="profile-img"><i class="fa-solid fa-user"></i></div>
            <?php endif; ?>
            <div class="profile-details">
                    <h1><?= htmlspecialchars($user['name']) ?></h1>
                    <p style="opacity: 0.8; font-size: 0.95rem;">
                        <i class="fa-solid fa-location-dot" style="margin-right: 4px;"></i>
                        <?= htmlspecialchars(($resident_info['street_name'] ?? 'Main Street') . ' - ' . ($resident_info['building_name'] ?? 'Block A') . ' Flat ' . ($resident_info['flat_number'] ?? 'N/A')) ?>
                    </p>
                    <div class="tags">
                        <span class="tag tag-id">ID: <?= htmlspecialchars($resident_info['custom_id'] ?? ('RES-' . str_pad($user_id, 5, '0', STR_PAD_LEFT))) ?></span>
                        <span class="tag tag-tenant"><?= htmlspecialchars(ucfirst($resident_info['relationship'] ?? 'Tenant')) ?></span>
                        <span class="tag" style="background: #dcfce7; color: #166534;"><?= htmlspecialchars(ucfirst($tenancy['status'] ?? 'Active')) ?> Occupancy</span>
                    </div>
                </div>
            </div>
            <div>
                <a href="finance" class="btn-pay"><i class="fa-solid fa-credit-card"></i> Pay Bills / Invoices</a>
            </div>
        </div>

        <!-- Financial KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card" style="border-left: 4px solid #ef4444;">
                <div class="kpi-title">Outstanding Balance</div>
                <div class="kpi-value" style="color: #dc2626;">₦<?= number_format($finance_stats['outstanding_balance'], 2) ?></div>
                <div class="kpi-sub"><?= $unpaid_invoices_cnt ?> Pending Invoices</div>
            </div>
            <div class="kpi-card" style="border-left: 4px solid #10b981;">
                <div class="kpi-title">Total Payments Made</div>
                <div class="kpi-value" style="color: #059669;">₦<?= number_format($total_paid_payments, 2) ?></div>
                <div class="kpi-sub">Verified Gateway Receipts</div>
            </div>
            <div class="kpi-card" style="border-left: 4px solid #3b82f6;">
                <div class="kpi-title">Next Payment Due</div>
                <div class="kpi-value" style="font-size: 1.5rem; color: #2563eb;">
                    <?= $finance_stats['next_due_date'] ? date('M j, Y', strtotime($finance_stats['next_due_date'])) : 'No Due Invoices' ?>
                </div>
                <div class="kpi-sub">Auto-generated Estate Charges</div>
            </div>
            <div class="kpi-card" style="border-left: 4px solid #8b5cf6;">
                <div class="kpi-title">Visitor Statistics</div>
                <div class="kpi-value" style="color: #7c3aed;"><?= number_format($visitor_stats['visitors_this_month'] ?? 0) ?></div>
                <div class="kpi-sub">Visitors This Month (<?= $visitor_stats['total_visitors'] ?? 0 ?> Total)</div>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="grid-2">
            <!-- Left: Payment History Chart & Household -->
            <div style="display: flex; flex-direction: column; gap: 2rem;">
                <!-- Payment Summary Chart -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fa-solid fa-chart-simple" style="color: var(--primary); margin-right: 8px;"></i> <?= $current_year ?> Payment Summary</div>
                        <span style="font-size: 0.85rem; color: var(--text-muted);">Monthly Collection</span>
                    </div>
                    <div class="chart-container">
                        <?php 
                        $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                        for($i=1; $i<=12; $i++): 
                            $val = $monthly_payments[$i];
                            $height_pct = ($val / $max_monthly) * 100;
                            if ($height_pct < 5 && $val > 0) $height_pct = 5;
                        ?>
                            <div class="bar-group">
                                <div class="bar" style="height: <?= $height_pct ?>%;" data-val="₦<?= number_format($val) ?>"></div>
                                <div class="bar-label"><?= $months[$i-1] ?></div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- Property Occupants & Vehicles Summary -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fa-solid fa-house-user" style="color: var(--primary); margin-right: 8px;"></i> Household Overview</div>
                        <a href="property" style="color: var(--primary); font-size: 0.85rem; font-weight: 600; text-decoration: none;">View Details</a>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div style="background: #f8fafc; padding: 1.25rem; border-radius: 0.75rem; text-align: center; border: 1px solid var(--border);">
                            <i class="fa-solid fa-users" style="font-size: 1.75rem; color: #3b82f6; margin-bottom: 0.5rem;"></i>
                            <div style="font-weight: 700; font-size: 1.5rem;"><?= $co_residents_count ?></div>
                            <div style="font-size: 0.85rem; color: var(--text-muted);">Occupants</div>
                        </div>
                        <div style="background: #f8fafc; padding: 1.25rem; border-radius: 0.75rem; text-align: center; border: 1px solid var(--border);">
                            <i class="fa-solid fa-car" style="font-size: 1.75rem; color: #10b981; margin-bottom: 0.5rem;"></i>
                            <div style="font-weight: 700; font-size: 1.5rem;"><?= $vehicles_count ?></div>
                            <div style="font-size: 0.85rem; color: var(--text-muted);">Registered Vehicles</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Estate Announcements & Quick Actions -->
            <div style="display: flex; flex-direction: column; gap: 2rem;">
                <!-- Announcements -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fa-solid fa-bullhorn" style="color: #eab308; margin-right: 8px;"></i> Estate Announcements</div>
                    </div>
                    <div>
                        <?php if ($announcements && $announcements->num_rows > 0): ?>
                            <?php while ($ann = $announcements->fetch_assoc()): ?>
                                <div class="announcement-item">
                                    <div class="announcement-meta">
                                        <span><i class="fa-regular fa-clock"></i> <?= date('M j, Y', strtotime($ann['created_at'])) ?></span>
                                        <?php if ($ann['priority'] == 'urgent'): ?>
                                            <span class="badge-urgent">URGENT</span>
                                        <?php endif; ?>
                                    </div>
                                    <h4 style="font-size: 0.95rem; margin-bottom: 0.25rem; color: #1e293b;"><?= htmlspecialchars($ann['title']) ?></h4>
                                    <p style="font-size: 0.85rem; color: #64748b;"><?= htmlspecialchars($ann['content']) ?></p>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p style="color: var(--text-muted); font-size: 0.9rem; text-align: center; padding: 1rem;">No recent estate announcements.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Action Buttons -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fa-solid fa-bolt" style="color: #ec4899; margin-right: 8px;"></i> Quick Actions</div>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <a href="visitors?action=new" style="display: flex; align-items: center; justify-content: space-between; padding: 0.85rem 1rem; background: #eff6ff; color: #1e40af; border-radius: 0.5rem; text-decoration: none; font-weight: 600;">
                            <span><i class="fa-solid fa-user-plus" style="margin-right: 8px;"></i> Pre-register Visitor Access</span>
                            <i class="fa-solid fa-chevron-right" style="font-size: 0.8rem;"></i>
                        </a>
                        <a href="report_issue" style="display: flex; align-items: center; justify-content: space-between; padding: 0.85rem 1rem; background: #fef3c7; color: #92400e; border-radius: 0.5rem; text-decoration: none; font-weight: 600;">
                            <span><i class="fa-solid fa-triangle-exclamation" style="margin-right: 8px;"></i> Log Maintenance Request</span>
                            <i class="fa-solid fa-chevron-right" style="font-size: 0.8rem;"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php include 'footer.php'; ?>
