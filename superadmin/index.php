<?php
// superadmin/index.php - platform dashboard
$page_title = 'Dashboard';
require_once __DIR__ . '/includes/super_header.php';
require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';

$one = fn($sql) => $platform->query($sql)->fetch_assoc();
$st = $one("SELECT COUNT(*) total,
    SUM(status='active') active_c, SUM(status='trial') trial_c, SUM(status='grace_period') grace_c, SUM(status='suspended') susp_c,
    SUM(status='retention') ret_c, SUM(status='deletion_pending') del_c, SUM(status IN ('pending','onboarding')) onb_c, COALESCE(SUM(resident_count),0) residents
    FROM estates WHERE status NOT IN ('deleted','archived')");
$rev = $one("SELECT COALESCE(SUM(total),0) all_time, COALESCE(SUM(CASE WHEN paid_at >= DATE_FORMAT(NOW(),'%Y-%m-01') THEN total END),0) this_month FROM invoices WHERE status = 'paid'");
$mrr = $one("SELECT COALESCE(SUM(i.subtotal / IF(s.billing_cycle='annual',12,1)),0) mrr, COALESCE(SUM(s.billing_cycle='annual'),0) annual_c
    FROM subscriptions s JOIN invoices i ON i.id = (SELECT MAX(id) FROM invoices WHERE subscription_id = s.id AND status = 'paid' AND kind IN ('subscription','renewal'))
    WHERE s.status IN ('active','renewed','grace_period','past_due')");
$pay = $one("SELECT SUM(status='failed' AND created_at > NOW() - INTERVAL 30 DAY) failed_c, SUM(status='pending') pending_c FROM payments WHERE entry_type='payment'");
$tix = $one("SELECT COUNT(*) c FROM support_tickets WHERE status IN ('open','in_progress','awaiting_customer')");
$alerts = $one("SELECT COUNT(*) c FROM security_alerts WHERE status = 'open'");
$errs = $one("SELECT COUNT(*) c FROM system_errors WHERE resolved = 0 AND severity IN ('error','critical')");
$stat = fn($label, $value, $sub = '', $href = null) => '<div class="col-6 col-lg-3"><a ' . ($href ? 'href="' . $href . '"' : '') . ' class="metric-card d-block text-decoration-none h-100"><div class="small text-secondary">' . e($label) . '</div><div class="fs-3 fw-bold text-dark">' . $value . '</div><div class="small text-secondary">' . $sub . '</div></a></div>';
?>
<div class="row g-3 mb-4">
    <?= $stat('Total estates', intval($st['total']), intval($st['onb_c']) . ' onboarding', 'estates') ?>
    <?= $stat('Active', intval($st['active_c']), intval($st['trial_c']) . ' on trial', 'estates?status=active') ?>
    <?= $stat('Grace period', intval($st['grace_c']), 'payment overdue', 'estates?status=grace_period') ?>
    <?= $stat('Suspended', intval($st['susp_c']) + intval($st['ret_c']), intval($st['del_c']) . ' pending deletion', 'estates?status=suspended') ?>
    <?= $stat('Residents', number_format($st['residents']), 'across all estates') ?>
    <?= $stat('Monthly recurring revenue', sa_money($mrr['mrr']), intval($mrr['annual_c']) . ' annual subscriptions', 'billing') ?>
    <?= $stat('Revenue this month', sa_money($rev['this_month']), 'all time ' . sa_money($rev['all_time']), 'billing') ?>
    <?= $stat('Payments', intval($pay['failed_c']) . ' failed', intval($pay['pending_c']) . ' pending', 'billing') ?>
    <?= $stat('Open tickets', intval($tix['c']), 'support queue', 'tickets') ?>
    <?= $stat('Security alerts', intval($alerts['c']), 'open', 'alerts') ?>
    <?= $stat('System errors', intval($errs['c']), 'unresolved', 'monitoring') ?>
    <?= $stat('Trial estates', intval($st['trial_c']), 'convert or expire', 'estates?status=trial') ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6"><div class="card-custom p-3 h-100">
        <h6 class="fw-bold mb-3">Estate Status Distribution</h6>
        <canvas id="estateStatusChart" height="250"></canvas>
    </div></div>
    <div class="col-lg-6"><div class="card-custom p-3 h-100">
        <h6 class="fw-bold mb-3">Subscription Status Breakdown</h6>
        <canvas id="subscriptionStatusChart" height="250"></canvas>
    </div></div>
    <div class="col-lg-6"><div class="card-custom p-3 h-100">
        <h6 class="fw-bold mb-3">Monthly Revenue Trend (Last 12 Months)</h6>
        <canvas id="revenueChart" height="250"></canvas>
    </div></div>
    <div class="col-lg-6"><div class="card-custom p-3 h-100">
        <h6 class="fw-bold mb-3">Payment Status (Last 30 Days)</h6>
        <canvas id="paymentStatusChart" height="250"></canvas>
    </div></div>
</div>

<div class="row g-3">
    <div class="col-lg-6"><div class="card-custom p-3 h-100">
        <h6 class="fw-bold">Recent registrations</h6>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Estate</th><th>Status</th><th>Registered</th></tr></thead><tbody>
        <?php $r = $platform->query("SELECT id,name,status,created_at FROM estates ORDER BY id DESC LIMIT 6"); while ($x = $r->fetch_assoc()): ?>
            <tr><td><a href="estate?id=<?= $x['id'] ?>"><?= e($x['name']) ?></a></td><td><?= sa_badge($x['status']) ?></td><td class="small text-secondary"><?= sa_d($x['created_at']) ?></td></tr>
        <?php endwhile; ?></tbody></table></div>
    </div></div>
    <div class="col-lg-6"><div class="card-custom p-3 h-100">
        <h6 class="fw-bold">Security alerts <a class="small fw-normal" href="alerts">view all</a></h6>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0"><tbody>
        <?php $r = $platform->query("SELECT * FROM security_alerts WHERE status='open' ORDER BY id DESC LIMIT 6"); $n = 0; while ($x = $r->fetch_assoc()): $n++; ?>
            <tr><td><?= sa_badge($x['severity']) ?></td><td class="small"><?= e($x['message']) ?></td><td class="small text-secondary text-nowrap"><?= sa_dt($x['created_at']) ?></td></tr>
        <?php endwhile; if (!$n) echo '<tr><td class="text-secondary small">No open alerts.</td></tr>'; ?></tbody></table></div>
    </div></div>
    <div class="col-lg-6"><div class="card-custom p-3 h-100">
        <h6 class="fw-bold">Recent sign-ins <a class="small fw-normal" href="logins">view all</a></h6>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>User</th><th>Result</th><th>When</th></tr></thead><tbody>
        <?php $r = $platform->query("SELECT * FROM platform_login_logs WHERE event_type IN ('login_success','login_failed','login_blocked') ORDER BY id DESC LIMIT 8"); while ($x = $r->fetch_assoc()): ?>
            <tr><td class="small"><?= e($x['email'] ?: $x['user_name']) ?><div class="text-secondary" style="font-size:.7rem"><?= e($x['ip_address']) ?></div></td><td><?= $x['success'] ? sa_badge('success') : sa_badge('failed') ?></td><td class="small text-secondary"><?= sa_dt($x['logged_in_at']) ?></td></tr>
        <?php endwhile; ?></tbody></table></div>
    </div></div>
    <div class="col-lg-6"><div class="card-custom p-3 h-100">
        <h6 class="fw-bold">Needs attention</h6>
        <ul class="list-unstyled small mb-0">
        <?php
        $items = [];
        $r = $platform->query("SELECT id,name,retention_ends_at FROM estates WHERE status IN ('retention','suspended') ORDER BY retention_ends_at LIMIT 5");
        while ($x = $r->fetch_assoc()) $items[] = ['Suspended: <a href="estate?id=' . $x['id'] . '">' . e($x['name']) . '</a>' . ($x['retention_ends_at'] ? ' - data kept until ' . sa_d($x['retention_ends_at']) . ' (' . max(0, (int)ceil((strtotime($x['retention_ends_at']) - time()) / 86400)) . ' days left)' : '')];
        $r = $platform->query("SELECT id,name FROM estates WHERE status = 'deletion_pending'");
        while ($x = $r->fetch_assoc()) $items[] = ['Awaiting deletion approval: <a href="estate?id=' . $x['id'] . '">' . e($x['name']) . '</a>'];
        $r = $platform->query("SELECT id,name,trial_ends_at FROM estates WHERE status='trial' AND trial_ends_at < NOW() + INTERVAL 3 DAY");
        while ($x = $r->fetch_assoc()) $items[] = ['Trial ending ' . sa_d($x['trial_ends_at']) . ': <a href="estate?id=' . $x['id'] . '">' . e($x['name']) . '</a>'];
        $r = $platform->query("SELECT category, COUNT(*) c FROM system_errors WHERE resolved = 0 GROUP BY category");
        while ($x = $r->fetch_assoc()) $items[] = ['<a href="monitoring">' . intval($x['c']) . ' unresolved ' . e($x['category']) . ' issue(s)</a>'];
        foreach ($items as $i) echo '<li class="py-1 border-bottom">' . $i[0] . '</li>';
        if (!$items) echo '<li class="text-secondary">Nothing needs attention.</li>';
        ?></ul>
    </div></div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
<script>
// Chart.js configuration
const chartColors = {
    success: '#10b981',
    warning: '#f59e0b',
    danger: '#ef4444',
    info: '#3b82f6',
    secondary: '#6b7280'
};

// 1. Estate Status Distribution (Doughnut)
const estateStatusCtx = document.getElementById('estateStatusChart').getContext('2d');
new Chart(estateStatusCtx, {
    type: 'doughnut',
    data: {
        labels: ['Active', 'Trial', 'Grace Period', 'Suspended', 'Retention', 'Onboarding'],
        datasets: [{
            data: [<?= intval($st['active_c']) ?>, <?= intval($st['trial_c']) ?>, <?= intval($st['grace_c']) ?>, <?= intval($st['susp_c']) ?>, <?= intval($st['ret_c']) ?>, <?= intval($st['onb_c']) ?>],
            backgroundColor: [
                chartColors.success,
                chartColors.info,
                chartColors.warning,
                chartColors.danger,
                '#d97706',
                chartColors.secondary
            ],
            borderColor: '#fff',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});

// 2. Subscription Status (Pie)
<?php
$sub_stats = $platform->query("SELECT status, COUNT(*) c FROM subscriptions WHERE status IN ('active','renewed','grace_period','past_due','suspended','cancelled') GROUP BY status")->fetch_all(MYSQLI_ASSOC);
$sub_labels = array_map(fn($s) => ucfirst(str_replace('_', ' ', $s['status'])), $sub_stats);
$sub_data = array_map(fn($s) => intval($s['c']), $sub_stats);
$sub_colors = array_map(fn($s) => match($s['status']) {
    'active', 'renewed' => chartColors.success,
    'grace_period' => chartColors.warning,
    'past_due' => '#d97706',
    'suspended' => chartColors.danger,
    'cancelled' => chartColors.secondary,
    default => chartColors.info
}, $sub_stats);
?>
const subscriptionStatusCtx = document.getElementById('subscriptionStatusChart').getContext('2d');
new Chart(subscriptionStatusCtx, {
    type: 'pie',
    data: {
        labels: <?= json_encode($sub_labels) ?>,
        datasets: [{
            data: <?= json_encode($sub_data) ?>,
            backgroundColor: <?= json_encode($sub_colors) ?>,
            borderColor: '#fff',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});

// 3. Monthly Revenue Trend (Line)
<?php
$revenue_data = [];
for ($i = 11; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $result = $platform->query("SELECT COALESCE(SUM(total),0) as revenue FROM invoices WHERE status='paid' AND DATE_FORMAT(paid_at,'%Y-%m') = '$month'")->fetch_assoc();
    $revenue_data[] = floatval($result['revenue']);
}
$revenue_months = [];
for ($i = 11; $i >= 0; $i--) {
    $revenue_months[] = date('M', strtotime("-$i months"));
}
?>
const revenueCtx = document.getElementById('revenueChart').getContext('2d');
new Chart(revenueCtx, {
    type: 'line',
    data: {
        labels: <?= json_encode($revenue_months) ?>,
        datasets: [{
            label: 'Revenue',
            data: <?= json_encode($revenue_data) ?>,
            borderColor: chartColors.success,
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: { beginAtZero: true }
        }
    }
});

// 4. Payment Status (Bar)
<?php
$payment_stats = $platform->query("SELECT status, COUNT(*) c FROM payments WHERE entry_type='payment' AND created_at > NOW() - INTERVAL 30 DAY GROUP BY status")->fetch_all(MYSQLI_ASSOC);
$payment_labels = array_map(fn($p) => ucfirst($p['status']), $payment_stats);
$payment_data = array_map(fn($p) => intval($p['c']), $payment_stats);
$payment_colors = array_map(fn($p) => match($p['status']) {
    'paid' => chartColors.success,
    'pending' => chartColors.warning,
    'failed' => chartColors.danger,
    default => chartColors.info
}, $payment_stats);
?>
const paymentStatusCtx = document.getElementById('paymentStatusChart').getContext('2d');
new Chart(paymentStatusCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($payment_labels) ?>,
        datasets: [{
            label: 'Payments',
            data: <?= json_encode($payment_data) ?>,
            backgroundColor: <?= json_encode($payment_colors) ?>,
            borderRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: { beginAtZero: true }
        }
    }
});
</script>

<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
