<?php
// admin/africastalking.php - Africa's Talking USSD Gateway & Offline Mode Management Console
require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/AfricasTalking.php';

requireAdminAccess();

$estate_id = get_estate_id();
EstateAfricasTalking::ensureDatabaseTables($conn);

$message = "";
$error = "";

// -----------------------------------------------------------------------------
// POST ACTIONS: UPDATE AFRICA'S TALKING CONFIGURATION
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_at_config'])) {
    if (!verifyCSRFToken()) {
        $error = "CSRF verification failed.";
    } else {
        $enabled = isset($_POST['at_ussd_enabled']) ? '1' : '0';
        $env = ($_POST['at_environment'] ?? '') === 'live' ? 'live' : 'sandbox';
        $username = trim($_POST['at_username'] ?? 'sandbox');
        $api_key = trim($_POST['at_api_key'] ?? '');
        $ussd_code = trim($_POST['at_ussd_code'] ?? '*384*777#');
        $sms_sender_id = trim($_POST['at_sms_sender_id'] ?? '');
        $sms_enabled = isset($_POST['at_sms_notifications_enabled']) ? '1' : '0';
        $notify_pass = isset($_POST['at_notify_pass_on_ussd']) ? '1' : '0';
        $notify_sos = isset($_POST['at_notify_emergency_on_ussd']) ? '1' : '0';

        $settings_to_save = [
            'at_ussd_enabled' => $enabled,
            'at_environment' => $env,
            'at_username' => $username,
            'at_ussd_code' => $ussd_code,
            'at_sms_sender_id' => $sms_sender_id,
            'at_sms_notifications_enabled' => $sms_enabled,
            'at_notify_pass_on_ussd' => $notify_pass,
            'at_notify_emergency_on_ussd' => $notify_sos
        ];

        // Only update API key if provided (prevents wiping on blank save)
        if (!empty($api_key)) {
            $settings_to_save['at_api_key'] = $api_key;
        }

        foreach ($settings_to_save as $key => $val) {
            $k_esc = $conn->real_escape_string($key);
            $v_esc = $conn->real_escape_string($val);
            $chk = $conn->query("SELECT 1 FROM system_settings WHERE estate_id = $estate_id AND setting_key = '$k_esc'");
            if ($chk && $chk->num_rows > 0) {
                $conn->query("UPDATE system_settings SET setting_value = '$v_esc' WHERE estate_id = $estate_id AND setting_key = '$k_esc'");
            } else {
                $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES ($estate_id, '$k_esc', '$v_esc')");
            }
        }

        logAudit($conn, "Updated Africa's Talking Settings", "System", "USSD and Offline sync settings updated by admin.");
        $message = "Africa's Talking USSD & Offline settings updated successfully!";
    }
}

// Fetch current config
$cfg = EstateAfricasTalking::getConfig($conn, $estate_id);

// Stats
$total_sessions_cnt = 0;
$total_passes_ussd_cnt = 0;
$total_offline_synced_cnt = 0;

$s_res = $conn->query("SELECT COUNT(*) as cnt FROM ussd_logs WHERE estate_id = $estate_id");
if ($s_res) $total_sessions_cnt = intval($s_res->fetch_assoc()['cnt'] ?? 0);

$p_res = $conn->query("SELECT COUNT(*) as cnt FROM visitors WHERE estate_id = $estate_id AND purpose LIKE '%USSD%'");
if ($p_res) $total_passes_ussd_cnt = intval($p_res->fetch_assoc()['cnt'] ?? 0);

$o_res = $conn->query("SELECT COUNT(*) as cnt FROM offline_sync_logs WHERE estate_id = $estate_id AND sync_status = 'synced'");
if ($o_res) $total_offline_synced_cnt = intval($o_res->fetch_assoc()['cnt'] ?? 0);

// Fetch sample resident and guard for the simulator
$sample_resident = $conn->query("SELECT u.name, u.phone FROM users u JOIN residents r ON u.id = r.user_id WHERE u.estate_id = $estate_id AND u.phone IS NOT NULL AND u.phone != '' AND u.role != 'security' LIMIT 1")->fetch_assoc();
$sample_guard = $conn->query("SELECT u.name, u.phone FROM users u WHERE u.estate_id = $estate_id AND u.role IN ('security', 'staff') AND u.phone IS NOT NULL AND u.phone != '' LIMIT 1")->fetch_assoc();

// Determine Server Webhook URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script_path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$root_path = preg_replace('/\/admin$/', '', $script_path);
$callback_url = $protocol . $host . $root_path . '/api/ussd.php';

// Fetch recent USSD logs
$ussd_logs = [];
$ul_res = $conn->query("SELECT * FROM ussd_logs WHERE estate_id = $estate_id ORDER BY id DESC LIMIT 25");
if ($ul_res) {
    while ($row = $ul_res->fetch_assoc()) $ussd_logs[] = $row;
}

// Fetch recent Offline Sync logs
$sync_logs = [];
$sl_res = $conn->query("SELECT * FROM offline_sync_logs WHERE estate_id = $estate_id ORDER BY id DESC LIMIT 25");
if ($sl_res) {
    while ($row = $sl_res->fetch_assoc()) $sync_logs[] = $row;
}

$page_title = "Africa's Talking USSD & Offline Sync";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container-fluid px-3 px-md-4 py-4">
    <!-- Top Header -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h1 class="h3 fw-bold mb-0 text-slate-800">
                    <i class="fa-solid fa-signal text-primary me-2"></i>USSD Gateway &amp; Offline Sync
                </h1>
                <span class="badge <?php echo $cfg['enabled'] ? 'bg-success' : 'bg-secondary'; ?> rounded-pill px-3 py-1">
                    <?php echo $cfg['enabled'] ? 'USSD Active' : 'USSD Disabled'; ?>
                </span>
                <span class="badge <?php echo ($cfg['environment'] === 'live') ? 'bg-danger' : 'bg-warning text-dark'; ?> rounded-pill px-2 py-1">
                    <?php echo strtoupper($cfg['environment']); ?>
                </span>
            </div>
            <p class="text-secondary small mb-0 mt-1">
                Zero-internet GSM USSD accessibility via Africa's Talking API &amp; automatic local gate cache synchronization.
            </p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary shadow-sm rounded-pill px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#ussdSimulatorModal">
                <i class="fa-solid fa-mobile-screen-button me-1"></i> Launch USSD Phone Simulator
            </button>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm d-flex align-items-center" role="alert">
            <i class="fa-solid fa-circle-check fs-5 me-2"></i>
            <div><?php echo htmlspecialchars($message); ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm d-flex align-items-center" role="alert">
            <i class="fa-solid fa-circle-exclamation fs-5 me-2"></i>
            <div><?php echo htmlspecialchars($error); ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Summary KPI Cards -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-secondary small fw-semibold">USSD Channel Status</span>
                    <div class="p-2 bg-primary bg-opacity-10 text-primary rounded-3">
                        <i class="fa-solid fa-tower-cell fs-5"></i>
                    </div>
                </div>
                <h4 class="fw-bold mb-0 text-slate-800"><?php echo htmlspecialchars($cfg['ussd_code']); ?></h4>
                <small class="text-success fw-medium mt-1 d-block"><i class="fa-solid fa-check me-1"></i>Telecom Shortcode Ready</small>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-secondary small fw-semibold">Total USSD Sessions</span>
                    <div class="p-2 bg-success bg-opacity-10 text-success rounded-3">
                        <i class="fa-solid fa-phone-volume fs-5"></i>
                    </div>
                </div>
                <h4 class="fw-bold mb-0 text-slate-800"><?php echo number_format($total_sessions_cnt); ?></h4>
                <small class="text-secondary mt-1 d-block">Handset dials logged</small>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-secondary small fw-semibold">Passes via USSD</span>
                    <div class="p-2 bg-info bg-opacity-10 text-info rounded-3">
                        <i class="fa-solid fa-ticket fs-5"></i>
                    </div>
                </div>
                <h4 class="fw-bold mb-0 text-slate-800"><?php echo number_format($total_passes_ussd_cnt); ?></h4>
                <small class="text-secondary mt-1 d-block">Created without internet</small>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-secondary small fw-semibold">Offline Gate Syncs</span>
                    <div class="p-2 bg-warning bg-opacity-10 text-warning rounded-3">
                        <i class="fa-solid fa-arrows-rotate fs-5"></i>
                    </div>
                </div>
                <h4 class="fw-bold mb-0 text-slate-800"><?php echo number_format($total_offline_synced_cnt); ?></h4>
                <small class="text-secondary mt-1 d-block">Check-ins auto-synced</small>
            </div>
        </div>
    </div>

    <!-- Main Navigation Tabs -->
    <ul class="nav nav-pills mb-4 gap-2" id="atTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active rounded-pill px-4 fw-semibold" id="settings-tab" data-bs-toggle="tab" data-bs-target="#tab-settings" type="button">
                <i class="fa-solid fa-sliders me-1"></i> Gateway Configuration
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link rounded-pill px-4 fw-semibold" id="logs-tab" data-bs-toggle="tab" data-bs-target="#tab-logs" type="button">
                <i class="fa-solid fa-list-check me-1"></i> USSD Logs (<?php echo count($ussd_logs); ?>)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link rounded-pill px-4 fw-semibold" id="sync-tab" data-bs-toggle="tab" data-bs-target="#tab-sync" type="button">
                <i class="fa-solid fa-clock-rotate-left me-1"></i> Offline Sync Logs (<?php echo count($sync_logs); ?>)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link rounded-pill px-4 fw-semibold" id="how-tab" data-bs-toggle="tab" data-bs-target="#tab-how" type="button">
                <i class="fa-solid fa-circle-question me-1"></i> Architecture &amp; How It Works
            </button>
        </li>
    </ul>

    <div class="tab-content" id="atTabsContent">
        <!-- TAB 1: CONFIGURATION -->
        <div class="tab-pane fade show active" id="tab-settings" role="tabpanel">
            <div class="row g-4">
                <div class="col-12 col-lg-8">
                    <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
                        <div class="d-flex align-items-center justify-content-between pb-3 border-bottom mb-4">
                            <div>
                                <h5 class="fw-bold text-slate-800 mb-1">Africa's Talking Credentials &amp; USSD Setup</h5>
                                <p class="text-secondary small mb-0">Configure your Africa's Talking API account keys, USSD service code, and SMS sender info.</p>
                            </div>
                            <img src="https://africastalking.com/img/favicons/favicon-32x32.png" alt="Africa's Talking" style="height: 32px; border-radius: 6px;">
                        </div>

                        <form method="POST" action="">
                            <?php echo renderCSRFField(); ?>
                            <input type="hidden" name="save_at_config" value="1">

                            <div class="row g-3 mb-4">
                                <div class="col-12 col-md-6">
                                    <div class="form-check form-switch p-3 bg-light rounded-3 d-flex justify-content-between align-items-center">
                                        <div>
                                            <label class="form-check-label fw-bold text-slate-800 d-block" for="at_ussd_enabled">Enable USSD Gateway</label>
                                            <small class="text-secondary">Allow inbound telecom USSD sessions</small>
                                        </div>
                                        <input class="form-check-input ms-0 fs-5" type="checkbox" role="switch" id="at_ussd_enabled" name="at_ussd_enabled" value="1" <?php echo $cfg['enabled'] ? 'checked' : ''; ?>>
                                    </div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-bold small text-secondary">Environment Mode</label>
                                    <select class="form-select rounded-3 py-2" name="at_environment">
                                        <option value="sandbox" <?php echo ($cfg['environment'] === 'sandbox') ? 'selected' : ''; ?>>Sandbox (Free Test Simulator &amp; Mock Numbers)</option>
                                        <option value="live" <?php echo ($cfg['environment'] === 'live') ? 'selected' : ''; ?>>Production Live (Real Telco Telecom Networks)</option>
                                    </select>
                                </div>

                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-bold small text-secondary">Africa's Talking Username</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0 text-secondary"><i class="fa-solid fa-user"></i></span>
                                        <input type="text" class="form-control rounded-end-3 py-2 border-start-0" name="at_username" value="<?php echo htmlspecialchars($cfg['username']); ?>" placeholder="sandbox or your AT username" required>
                                    </div>
                                    <small class="text-muted">Use <code>sandbox</code> for development testing.</small>
                                </div>

                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-bold small text-secondary">Africa's Talking API Key</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0 text-secondary"><i class="fa-solid fa-key"></i></span>
                                        <input type="password" class="form-control rounded-end-3 py-2 border-start-0" name="at_api_key" placeholder="<?php echo !empty($cfg['api_key']) ? '••••••••••••••••••••••••••••' : 'Enter AT API Key'; ?>">
                                    </div>
                                    <small class="text-muted">Generated from your Africa's Talking Developer Settings.</small>
                                </div>

                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-bold small text-secondary">USSD Short Code</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0 text-secondary"><i class="fa-solid fa-hashtag"></i></span>
                                        <input type="text" class="form-control rounded-end-3 py-2 border-start-0" name="at_ussd_code" value="<?php echo htmlspecialchars($cfg['ussd_code']); ?>" placeholder="*384*777#">
                                    </div>
                                    <small class="text-muted">Assigned telecom service code channel (e.g. *384*777#).</small>
                                </div>

                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-bold small text-secondary">Alphanumeric SMS Sender ID (Optional)</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0 text-secondary"><i class="fa-solid fa-comment-sms"></i></span>
                                        <input type="text" class="form-control rounded-end-3 py-2 border-start-0" name="at_sms_sender_id" value="<?php echo htmlspecialchars($cfg['sms_sender_id']); ?>" placeholder="ESTATE_SEC" maxlength="11">
                                    </div>
                                    <small class="text-muted">Registered alphanumeric sender ID (max 11 chars).</small>
                                </div>
                            </div>

                            <div class="border-top pt-3 mb-4">
                                <h6 class="fw-bold text-slate-800 mb-2">Automated Notifications &amp; Relays</h6>
                                <div class="row g-2">
                                    <div class="col-12 col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="at_sms_notifications_enabled" value="1" id="at_sms_notifications_enabled" <?php echo $cfg['sms_enabled'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label small" for="at_sms_notifications_enabled">
                                                Enable Outbound Africa's Talking SMS Delivery
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="at_notify_pass_on_ussd" value="1" id="at_notify_pass_on_ussd" <?php echo $cfg['notify_pass_on_ussd'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label small" for="at_notify_pass_on_ussd">
                                                Send SMS Passcode to Visitor &amp; Resident upon USSD generation
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="at_notify_emergency_on_ussd" value="1" id="at_notify_emergency_on_ussd" <?php echo $cfg['notify_emergency_on_ussd'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label small" for="at_notify_emergency_on_ussd">
                                                Broadcast SMS SOS to Duty Security Officers when USSD panic is triggered
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary px-4 py-2 rounded-pill fw-semibold shadow-sm">
                                    <i class="fa-solid fa-floppy-disk me-1"></i> Save Configuration
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Webhook URL Card -->
                <div class="col-12 col-lg-4">
                    <div class="card border-0 shadow-sm rounded-4 p-4 bg-white mb-4">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <span class="p-2 bg-success bg-opacity-10 text-success rounded-3">
                                <i class="fa-solid fa-link fs-5"></i>
                            </span>
                            <div>
                                <h6 class="fw-bold mb-0 text-slate-800">Your USSD Callback Webhook</h6>
                                <small class="text-secondary">Paste this into Africa's Talking</small>
                            </div>
                        </div>

                        <p class="small text-secondary mb-2">
                            When users dial your shortcode, Africa's Talking telecom servers make an HTTP POST request to this endpoint:
                        </p>

                        <div class="p-3 bg-light rounded-3 border mb-3">
                            <code class="d-block text-break small font-monospace text-primary" id="callbackUrlCode"><?php echo htmlspecialchars($callback_url); ?></code>
                        </div>

                        <button type="button" class="btn btn-outline-primary btn-sm rounded-pill w-100 fw-semibold mb-3" onclick="copyCallbackUrl()">
                            <i class="fa-solid fa-copy me-1"></i> Copy Callback URL
                        </button>

                        <div class="alert alert-info py-2 px-3 small rounded-3 mb-0">
                            <i class="fa-solid fa-lightbulb me-1"></i>
                            <strong>Localhost / XAMPP Note:</strong> If running locally, use <code>ngrok http 80</code> to create a public URL (e.g. <code>https://xyz.ngrok-free.app/Estate/api/ussd.php</code>), OR test immediately using our built-in <strong>Virtual USSD Phone Simulator</strong>!
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
                        <h6 class="fw-bold mb-2 text-slate-800"><i class="fa-solid fa-shield-halved text-primary me-2"></i>Gate Offline Resilience</h6>
                        <p class="small text-secondary mb-3">
                            When the estate gate loses fiber or Wi-Fi internet:
                        </p>
                        <ul class="small text-secondary ps-3 mb-3">
                            <li class="mb-1">Guards dial <strong><?php echo htmlspecialchars($cfg['ussd_code']); ?></strong> on any GSM phone to verify passes with zero data.</li>
                            <li class="mb-1">Web Gate Terminal continues verifying passes against local storage cache.</li>
                            <li>Queued check-ins automatically sync to Central Server the second connection returns.</li>
                        </ul>
                        <button type="button" class="btn btn-outline-dark btn-sm rounded-pill w-100 fw-semibold" data-bs-toggle="modal" data-bs-target="#ussdSimulatorModal">
                            <i class="fa-solid fa-play me-1"></i> Test in Virtual Simulator
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 2: USSD LOGS -->
        <div class="tab-pane fade" id="tab-logs" role="tabpanel">
            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="fw-bold text-slate-800 mb-0">USSD Session &amp; Dial Activity Logs</h5>
                    <span class="badge bg-light text-secondary border px-3 py-2">Latest 25 transactions</span>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-secondary">
                            <tr>
                                <th>Timestamp</th>
                                <th>Caller Phone</th>
                                <th>Session ID</th>
                                <th>Input Sequence</th>
                                <th>Response Type</th>
                                <th>Output Summary</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($ussd_logs)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-secondary">
                                        <i class="fa-solid fa-phone-slash fs-3 opacity-25 d-block mb-2"></i>
                                        No USSD calls recorded yet. Use the Simulator to initiate test calls!
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($ussd_logs as $log): ?>
                                    <tr>
                                        <td class="small text-nowrap"><?php echo date('d M Y, H:i:s', strtotime($log['created_at'])); ?></td>
                                        <td class="fw-bold font-monospace small"><?php echo htmlspecialchars($log['phone_number']); ?></td>
                                        <td class="small font-monospace text-secondary" style="max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($log['session_id']); ?>">
                                            <?php echo htmlspecialchars($log['session_id']); ?>
                                        </td>
                                        <td class="small font-monospace text-primary"><?php echo htmlspecialchars($log['user_input'] ?: '(initial dial)'); ?></td>
                                        <td>
                                            <span class="badge <?php echo ($log['response_type'] === 'END') ? 'bg-secondary' : 'bg-success'; ?> rounded-pill">
                                                <?php echo htmlspecialchars($log['response_type']); ?>
                                            </span>
                                        </td>
                                        <td class="small" style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($log['response_text']); ?>">
                                            <?php echo htmlspecialchars($log['response_text']); ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2">
                                                <i class="fa-solid fa-check me-1"></i><?php echo htmlspecialchars($log['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 3: OFFLINE SYNC LOGS -->
        <div class="tab-pane fade" id="tab-sync" role="tabpanel">
            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="fw-bold text-slate-800 mb-0">Offline Terminal Sync Audit History</h5>
                    <span class="badge bg-light text-secondary border px-3 py-2">Latest 25 synchronizations</span>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-secondary">
                            <tr>
                                <th>Synced At</th>
                                <th>Terminal ID</th>
                                <th>Action</th>
                                <th>Visitor Passcode</th>
                                <th>Offline Entry Time</th>
                                <th>Sync Status</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sync_logs)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-secondary">
                                        <i class="fa-solid fa-arrows-rotate fs-3 opacity-25 d-block mb-2"></i>
                                        No offline sync transactions yet. When the guard terminal goes offline and comes back online, events appear here.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sync_logs as $sl): ?>
                                    <tr>
                                        <td class="small text-nowrap"><?php echo date('d M Y, H:i:s', strtotime($sl['synced_at'])); ?></td>
                                        <td class="small font-monospace"><?php echo htmlspecialchars($sl['terminal_id']); ?></td>
                                        <td>
                                            <span class="badge <?php echo ($sl['action_type'] === 'check_in') ? 'bg-primary' : 'bg-dark'; ?> rounded-pill">
                                                <?php echo strtoupper(str_replace('_', ' ', $sl['action_type'])); ?>
                                            </span>
                                        </td>
                                        <td class="fw-bold font-monospace text-primary"><?php echo htmlspecialchars($sl['visitor_code']); ?></td>
                                        <td class="small text-secondary"><?php echo date('d M, H:i:s', strtotime($sl['offline_timestamp'])); ?></td>
                                        <td>
                                            <span class="badge <?php echo ($sl['sync_status'] === 'synced') ? 'bg-success' : 'bg-warning text-dark'; ?> rounded-pill">
                                                <?php echo htmlspecialchars($sl['sync_status']); ?>
                                            </span>
                                        </td>
                                        <td class="small text-secondary"><?php echo htmlspecialchars($sl['details'] ?: 'Synced from gate'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 4: ARCHITECTURE WALKTHROUGH -->
        <div class="tab-pane fade" id="tab-how" role="tabpanel">
            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
                <h5 class="fw-bold text-slate-800 mb-3"><i class="fa-solid fa-network-wired text-primary me-2"></i>Complete USSD &amp; Offline Sync Architecture</h5>
                
                <div class="row g-4">
                    <div class="col-12 col-md-4">
                        <div class="p-3 bg-light rounded-4 h-100 border">
                            <div class="badge bg-primary rounded-pill mb-2">Phase 1: Zero Data Handset</div>
                            <h6 class="fw-bold text-slate-800">1. Resident / Visitor Offline Phone</h6>
                            <p class="small text-secondary mb-0">
                                Residents and visitors don't need mobile data or smartphones. USSD operates over GSM signaling (SS7 voice channel). Dialing <code>*384*...#</code> reaches the telecom carrier and routes to Africa's Talking gateway.
                            </p>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="p-3 bg-light rounded-4 h-100 border">
                            <div class="badge bg-warning text-dark rounded-pill mb-2">Phase 2: Gate Terminal Outage</div>
                            <h6 class="fw-bold text-slate-800">2. Estate Gate House Loses Internet</h6>
                            <p class="small text-secondary mb-0">
                                If fiber or Wi-Fi drops at the gate booth, guards immediately use their phone via USSD to verify codes, OR use the web terminal which validates against local browser cache and queues entries offline.
                            </p>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="p-3 bg-light rounded-4 h-100 border">
                            <div class="badge bg-success rounded-pill mb-2">Phase 3: Restored Connectivity</div>
                            <h6 class="fw-bold text-slate-800">3. Automatic Re-Sync Engine</h6>
                            <p class="small text-secondary mb-0">
                                The second internet is restored, the terminal's <code>EstateOfflineSync</code> background worker POSTs the queue to <code>api/sync_offline.php</code>, reconciles timestamps, sends arrival alerts, and pulls fresh USSD passes.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="mt-4 p-3 bg-primary bg-opacity-10 rounded-4 text-primary d-flex align-items-center gap-3">
                    <i class="fa-solid fa-circle-info fs-3 flex-shrink-0"></i>
                    <div class="small">
                        <strong>Africa's Talking Setup in Production:</strong> Create an account at <a href="https://africastalking.com" target="_blank" class="fw-bold text-decoration-underline text-primary">africastalking.com</a>, request a dedicated or shared USSD code, set the Callback URL to <code><?php echo htmlspecialchars($callback_url); ?></code>, and paste your Username &amp; API Key above!
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================================= -->
<!-- INTERACTIVE VISUAL USSD PHONE SIMULATOR MODAL -->
<!-- ======================================================================= -->
<div class="modal fade" id="ussdSimulatorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
        <div class="modal-content rounded-4 border-0 shadow-lg overflow-hidden">
            <div class="modal-header bg-dark text-white border-0 py-3">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-mobile-screen-button text-warning"></i>
                    <h6 class="modal-title fw-bold mb-0">USSD Virtual Phone Simulator</h6>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body p-4 bg-light">
                <!-- Phone Profile Switcher -->
                <div class="mb-3">
                    <label class="form-label small fw-bold text-secondary d-flex justify-content-between align-items-center">
                        <span>Simulate Caller Profile:</span>
                        <span id="simActiveRoleBadge" class="badge bg-primary text-white" style="font-size: 0.7rem;">Gate Security</span>
                    </label>

                    <!-- Quick Role Switcher Buttons -->
                    <div class="btn-group w-100 mb-2 shadow-sm" role="group">
                        <button type="button" class="btn btn-sm btn-outline-primary active fw-bold" id="btnRoleGuard" onclick="selectSimRole('guard')">
                            👮 Gate Officer
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary fw-bold" id="btnRoleGuest" onclick="selectSimRole('guest')">
                            🚶 Walk-In Guest
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary fw-bold" id="btnRoleResident" onclick="selectSimRole('resident')">
                            🏠 Resident
                        </button>
                    </div>

                    <select class="form-select form-select-sm rounded-3" id="simPhonePreset" onchange="applySimPreset()">
                        <option value="<?php echo htmlspecialchars($sample_guard['phone'] ?? '09066832352'); ?>" data-role="guard">
                            Security Officer: <?php echo htmlspecialchars($sample_guard['name'] ?? 'Officer Femi'); ?> (<?php echo htmlspecialchars($sample_guard['phone'] ?? '09066832352'); ?>)
                        </option>
                        <option value="+2348001112222" data-role="guest">
                            Unregistered Walk-In Guest (+2348001112222)
                        </option>
                        <option value="<?php echo htmlspecialchars($sample_resident['phone'] ?? '+2348034000010'); ?>" data-role="resident">
                            Resident: <?php echo htmlspecialchars($sample_resident['name'] ?? 'Amina Mohammed'); ?> (<?php echo htmlspecialchars($sample_resident['phone'] ?? '+2348034000010'); ?>)
                        </option>
                    </select>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-7">
                        <input type="text" class="form-control form-control-sm rounded-3 font-monospace" id="simPhoneNumber" value="<?php echo htmlspecialchars($sample_guard['phone'] ?? '09066832352'); ?>" placeholder="Caller Phone Number">
                    </div>
                    <div class="col-5">
                        <input type="text" class="form-control form-control-sm rounded-3 font-monospace" id="simServiceCode" value="<?php echo htmlspecialchars($cfg['ussd_code']); ?>" placeholder="*384*777#">
                    </div>
                </div>

                <!-- Smartphone Screen Mockup -->
                <div style="background: #0f172a; border-radius: 20px; padding: 20px 16px; border: 4px solid #334155; box-shadow: inset 0 2px 10px rgba(0,0,0,0.5);">
                    <div class="d-flex justify-content-between align-items-center text-secondary small mb-3 pb-1 border-bottom border-secondary border-opacity-25" style="font-size: 0.72rem;">
                        <span><i class="fa-solid fa-signal me-1"></i>GSM Full</span>
                        <span id="simClock">12:00</span>
                        <span><i class="fa-solid fa-battery-full"></i> 100%</span>
                    </div>

                    <!-- USSD Dialog Box on Phone -->
                    <div id="simScreenDialog" style="min-height: 200px; display: flex; flex-direction: column; justify-content: space-between;">
                        <div id="simIdleScreen" class="text-center py-4">
                            <i class="fa-solid fa-tower-cell text-primary fs-1 mb-2 opacity-50"></i>
                            <p class="text-light small mb-0">Phone Idle</p>
                            <small class="text-secondary" style="font-size: 0.75rem;">Click "Dial USSD Code" below to test session</small>
                        </div>

                        <div id="simActiveScreen" style="display: none;">
                            <div class="p-3 rounded-3 mb-3 font-monospace text-light" id="simResponseText" style="background: #1e293b; font-size: 0.84rem; white-space: pre-wrap; line-height: 1.4; border-left: 3px solid #10b981;">
                                Welcome to Estate...
                            </div>
                            
                            <div id="simInputRow" class="d-flex gap-2">
                                <input type="text" class="form-control form-control-sm rounded-3 font-monospace bg-dark text-light border-secondary" id="simUserInput" placeholder="Type option..." autocomplete="off">
                                <button type="button" class="btn btn-primary btn-sm rounded-3 px-3 fw-bold" id="simSendBtn" onclick="sendSimInput()">Send</button>
                            </div>

                            <div id="simEndedRow" class="text-center mt-2" style="display: none;">
                                <span class="badge bg-secondary rounded-pill px-3 py-1 text-light small">Session Closed</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Dial Controls -->
                <div class="mt-3 d-flex gap-2">
                    <button type="button" class="btn btn-success rounded-pill w-100 fw-bold py-2 shadow-sm" id="simDialBtn" onclick="startSimSession()">
                        <i class="fa-solid fa-phone me-1"></i> Dial USSD Code
                    </button>
                    <button type="button" class="btn btn-outline-danger rounded-pill px-3 fw-bold py-2" onclick="cancelSimSession()" title="Hang Up">
                        <i class="fa-solid fa-phone-slash"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let simSessionId = '';
let simFullText = '';
const ussdEndpoint = '../api/ussd.php';

function copyCallbackUrl() {
    const code = document.getElementById('callbackUrlCode').textContent;
    navigator.clipboard.writeText(code).then(() => {
        alert('Callback URL copied to clipboard: ' + code);
    }).catch(err => {
        prompt('Copy callback URL manually:', code);
    });
}

function selectSimRole(role) {
    const select = document.getElementById('simPhonePreset');
    for (let i = 0; i < select.options.length; i++) {
        if (select.options[i].getAttribute('data-role') === role) {
            select.selectedIndex = i;
            break;
        }
    }
    applySimPreset();
}

function applySimPreset() {
    const select = document.getElementById('simPhonePreset');
    const phoneInput = document.getElementById('simPhoneNumber');
    phoneInput.value = select.value;

    const opt = select.options[select.selectedIndex];
    const role = opt ? opt.getAttribute('data-role') : 'guard';

    // Update active button state
    ['btnRoleGuard', 'btnRoleGuest', 'btnRoleResident'].forEach(id => {
        const btn = document.getElementById(id);
        if (btn) btn.classList.remove('active');
    });

    const badge = document.getElementById('simActiveRoleBadge');
    if (role === 'guard') {
        document.getElementById('btnRoleGuard')?.classList.add('active');
        if (badge) { badge.textContent = 'Gate Security'; badge.className = 'badge bg-primary text-white'; }
    } else if (role === 'guest') {
        document.getElementById('btnRoleGuest')?.classList.add('active');
        if (badge) { badge.textContent = 'Walk-In Guest'; badge.className = 'badge bg-warning text-dark'; }
    } else {
        document.getElementById('btnRoleResident')?.classList.add('active');
        if (badge) { badge.textContent = 'Host Resident'; badge.className = 'badge bg-success text-white'; }
    }

    // Auto-dial session immediately with fresh caller profile
    startSimSession();
}

function updateSimClock() {
    const now = new Date();
    const clock = document.getElementById('simClock');
    if (clock) {
        clock.textContent = now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0');
    }
}
setInterval(updateSimClock, 1000);
updateSimClock();

function startSimSession() {
    simSessionId = 'SIM_' + Math.random().toString(36).substring(2, 10) + '_' + Date.now();
    simFullText = '';

    document.getElementById('simIdleScreen').style.display = 'none';
    document.getElementById('simActiveScreen').style.display = 'block';
    document.getElementById('simResponseText').textContent = 'Connecting via GSM telecom network...';
    document.getElementById('simInputRow').style.display = 'none';
    document.getElementById('simEndedRow').style.display = 'none';

    makeUssdRequest('');
}

function sendSimInput() {
    const inp = document.getElementById('simUserInput');
    const val = inp.value.trim();
    if (!val) return;

    inp.value = '';
    const newText = (simFullText === '') ? val : (simFullText + '*' + val);
    simFullText = newText;

    document.getElementById('simResponseText').textContent = 'Processing request...';
    makeUssdRequest(simFullText);
}

function makeUssdRequest(textSequence) {
    const phone = document.getElementById('simPhoneNumber').value.trim();
    const serviceCode = document.getElementById('simServiceCode').value.trim();

    const formData = new FormData();
    formData.append('sessionId', simSessionId);
    formData.append('phoneNumber', phone);
    formData.append('serviceCode', serviceCode);
    formData.append('text', textSequence);
    formData.append('networkCode', '62120');

    fetch(ussdEndpoint, {
        method: 'POST',
        body: formData
    })
    .then(r => r.text())
    .then(respText => {
        const clean = respText.trim();
        const isEnd = clean.startsWith('END');
        const displayText = clean.replace(/^(CON|END)\s*/, '');

        document.getElementById('simResponseText').textContent = displayText;

        if (isEnd) {
            document.getElementById('simInputRow').style.display = 'none';
            document.getElementById('simEndedRow').style.display = 'block';
        } else {
            document.getElementById('simInputRow').style.display = 'flex';
            document.getElementById('simEndedRow').style.display = 'none';
            document.getElementById('simUserInput').focus();
        }
    })
    .catch(err => {
        document.getElementById('simResponseText').textContent = 'USSD Connection Failed: ' + err.message;
        document.getElementById('simInputRow').style.display = 'none';
        document.getElementById('simEndedRow').style.display = 'block';
    });
}

function cancelSimSession() {
    simSessionId = '';
    simFullText = '';
    document.getElementById('simIdleScreen').style.display = 'block';
    document.getElementById('simActiveScreen').style.display = 'none';
}

// Enter key submit in simulator
document.getElementById('simUserInput').addEventListener('keyup', function(e) {
    if (e.key === 'Enter') {
        sendSimInput();
    }
});

// Auto-dial when simulator modal is opened
const simModal = document.getElementById('ussdSimulatorModal');
if (simModal) {
    simModal.addEventListener('shown.bs.modal', function() {
        if (!simSessionId) {
            applySimPreset();
        }
    });
}
</script>

<?php include '../includes/footer.php'; ?>
