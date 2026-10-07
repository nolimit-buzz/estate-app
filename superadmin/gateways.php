<?php
// superadmin/gateways.php - Central Africa's Talking USSD & Telecom Infrastructure Console
$page_title = "USSD & Africa's Talking Telecom Console";
require_once 'includes/super_header.php';
require_once 'includes/super_sidebar.php';
require_once 'includes/super_topbar.php';

$message = getFlashMessage('success') ?? '';
$error = getFlashMessage('error') ?? '';

// Save Central Gateway Credentials
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_gateway'])) {
    $at_username = trim($_POST['at_username'] ?? '');
    $at_api_key  = trim($_POST['at_api_key'] ?? '');
    $at_shortcode = trim($_POST['at_shortcode'] ?? '*384*2020#');
    $at_env      = ($_POST['at_environment'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';

    // Store in global system_settings (estate_id = 1 or null)
    $settings = [
        ['at_username', $at_username],
        ['at_api_key', $at_api_key],
        ['at_shortcode', $at_shortcode],
        ['at_environment', $at_env]
    ];

    foreach ($settings as $s) {
        $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) 
                      VALUES (1, '{$s[0]}', '{$conn->real_escape_string($s[1])}')
                      ON DUPLICATE KEY UPDATE setting_value = '{$conn->real_escape_string($s[1])}'");
    }

    logAudit($conn, "Gateway Updated", "Telecom Infrastructure", "Super Admin updated Africa's Talking telecom gateway configuration.");
    $message = "Africa's Talking gateway credentials updated successfully.";
}

// Fetch current telecom settings
$at_username = '';
$at_api_key = '';
$at_shortcode = '*384*2020#';
$at_env = 'sandbox';

$gwRes = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE estate_id = 1 AND setting_key IN ('at_username', 'at_api_key', 'at_shortcode', 'at_environment')");
if ($gwRes) {
    while ($gw = $gwRes->fetch_assoc()) {
        if ($gw['setting_key'] === 'at_username') $at_username = $gw['setting_value'];
        if ($gw['setting_key'] === 'at_api_key') $at_api_key = $gw['setting_value'];
        if ($gw['setting_key'] === 'at_shortcode') $at_shortcode = $gw['setting_value'];
        if ($gw['setting_key'] === 'at_environment') $at_env = $gw['setting_value'];
    }
}

require_once __DIR__ . '/../includes/AfricasTalking.php';
EstateAfricasTalking::ensureDatabaseTables($conn);

// Fetch USSD & Offline Pass Telemetry
$totalUssdLogs = 0;
$uRes = $conn->query("SELECT COUNT(*) as c FROM ussd_logs");
if ($uRes) $totalUssdLogs = intval($uRes->fetch_assoc()['c'] ?? 0);

// Fetch recent USSD logs across all estates
$recentUssdLogs = [];
$upQuery = $conn->query("SELECT l.*, e.name as estate_name 
                         FROM ussd_logs l 
                         LEFT JOIN estates e ON l.estate_id = e.id 
                         ORDER BY l.created_at DESC LIMIT 10");
if ($upQuery) {
    while ($up = $upQuery->fetch_assoc()) $recentUssdLogs[] = $up;
}
?>

<!-- FLASH ALERTS -->
<?php if (!empty($message)): ?>
    <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0 d-flex align-items-center mb-4">
        <i class="fa-solid fa-circle-check fs-5 me-2 text-success"></i>
        <div><?= htmlspecialchars($message) ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- PAGE HEADER BANNER -->
<div class="p-4 rounded-4 mb-4 text-white d-flex flex-wrap align-items-center justify-content-between gap-3 shadow-sm" style="background: linear-gradient(135deg, #0b1120 0%, #1e293b 100%); border: 1px solid rgba(255,255,255,0.08);">
    <div>
        <div class="badge bg-warning text-dark rounded-pill px-3 py-1 mb-2 font-monospace fw-bold" style="font-size: 0.72rem;">TELECOM INFRASTRUCTURE</div>
        <h4 class="fw-bold mb-1">Africa's Talking USSD &amp; Offline Gateway</h4>
        <p class="small mb-0" style="color: #94a3b8; max-width: 650px;">
            Enables low-bandwidth GSM session processing, short-code menus (e.g. <code>*384*2020#</code>), and offline terminal emergency sync when internet access fails.
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="../admin/africastalking" class="btn btn-warning rounded-pill px-3 py-2 fw-bold text-dark shadow-sm">
            <i class="fa-solid fa-mobile-screen-button me-1"></i> Interactive USSD Simulator
        </a>
    </div>
</div>

<!-- TELEMETRY KPIS -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="metric-card">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem;">USSD Short Code</span>
                <div style="background: #e0f2fe; color: #0284c7; width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-hashtag fs-5"></i>
                </div>
            </div>
            <h3 class="fw-bold mb-1 font-monospace text-primary"><?= htmlspecialchars($at_shortcode) ?></h3>
            <small class="text-success fw-semibold"><i class="fa-solid fa-signal me-1"></i>Session Gateway Routing Active</small>
        </div>
    </div>

    <div class="col-12 col-md-4">
        <div class="metric-card">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem;">USSD Sessions &amp; Logs</span>
                <div style="background: #fef3c7; color: #d97706; width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-mobile-screen-button fs-5"></i>
                </div>
            </div>
            <h3 class="fw-bold mb-1 text-slate-900"><?= number_format($totalUssdLogs) ?></h3>
            <small class="text-secondary">Total sessions processed via GSM</small>
        </div>
    </div>

    <div class="col-12 col-md-4">
        <div class="metric-card">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem;">Environment Status</span>
                <div style="background: #dcfce7; color: #16a34a; width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-server fs-5"></i>
                </div>
            </div>
            <h3 class="fw-bold mb-1 text-capitalize text-slate-900"><?= htmlspecialchars($at_env) ?></h3>
            <small class="text-secondary">Africa's Talking API Cluster</small>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- LEFT: Gateway Configuration -->
    <div class="col-12 col-lg-6">
        <div class="card-custom p-4 h-100">
            <h5 class="fw-bold text-slate-800 mb-3 border-bottom pb-2">
                <i class="fa-solid fa-tower-cell text-primary me-2"></i>Global Africa's Talking API Settings
            </h5>
            
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">Username (Sandbox or Production Username)</label>
                    <input type="text" name="at_username" class="form-control rounded-3" placeholder="e.g. sandbox or myproductionapp" value="<?= htmlspecialchars($at_username) ?>">
                    <small class="text-secondary" style="font-size: 0.72rem;">Default sandbox user is usually <code>sandbox</code>.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">API Key</label>
                    <input type="password" name="at_api_key" class="form-control rounded-3 font-monospace" placeholder="atsk_..." value="<?= htmlspecialchars($at_api_key) ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary">Assigned USSD Service Code</label>
                    <input type="text" name="at_shortcode" class="form-control rounded-3 font-monospace" value="<?= htmlspecialchars($at_shortcode) ?>" required>
                    <small class="text-secondary" style="font-size: 0.72rem;">Channels configured with Africa's Talking telecom routing.</small>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-secondary">Operating Environment</label>
                    <select name="at_environment" class="form-select rounded-3">
                        <option value="sandbox" <?= $at_env === 'sandbox' ? 'selected' : '' ?>>Sandbox (Simulator Testing)</option>
                        <option value="production" <?= $at_env === 'production' ? 'selected' : '' ?>>Production (Live GSM Telephony)</option>
                    </select>
                </div>

                <button type="submit" name="save_gateway" class="btn btn-primary rounded-pill px-4 py-2 fw-bold shadow-sm">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Save Gateway Configuration
                </button>
            </form>
        </div>
    </div>

    <!-- RIGHT: Recent USSD Sessions & Logs -->
    <div class="col-12 col-lg-6">
        <div class="card-custom p-4 h-100">
            <h5 class="fw-bold text-slate-800 mb-3 border-bottom pb-2">
                <i class="fa-solid fa-clock-rotate-left text-warning me-2"></i>Recent GSM USSD Telemetry Logs
            </h5>

            <?php if (empty($recentUssdLogs)): ?>
                <div class="text-center py-5">
                    <i class="fa-solid fa-tower-broadcast text-secondary mb-2" style="font-size: 2rem;"></i>
                    <p class="text-secondary small mb-0">No USSD sessions logged yet.</p>
                    <a href="../admin/africastalking" class="btn btn-sm btn-outline-primary rounded-pill mt-3 px-3">Test in USSD Simulator</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle small mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Phone</th>
                                <th>Input</th>
                                <th>Status</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentUssdLogs as $up): ?>
                                <tr>
                                    <td class="font-monospace fw-bold"><?= htmlspecialchars($up['phone_number']) ?></td>
                                    <td>
                                        <div class="text-truncate" style="max-width: 140px;" title="<?= htmlspecialchars($up['user_input']) ?>">
                                            <?= htmlspecialchars($up['user_input'] ?: '(initial menu)') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?= $up['status'] === 'success' ? 'bg-success' : 'bg-danger' ?> rounded-pill">
                                            <?= htmlspecialchars($up['status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-secondary"><?= date('M d, H:i', strtotime($up['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once 'includes/super_footer.php'; ?>
