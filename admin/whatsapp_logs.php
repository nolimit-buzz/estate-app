<?php
// admin/whatsapp_logs.php - Outgoing WhatsApp Direct Delivery & Audit Logs (Kapso)
require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/Mailer.php';
require_once '../includes/WhatsApp.php';
requireAdminAccess();

$estate_id = get_estate_id();
EstateWhatsApp::ensureDatabaseTables($conn);

$message = "";
$error = "";

// Handle Resend / Retry Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retry_log_id'])) {
    $log_id = intval($_POST['retry_log_id']);
    $chk = $conn->query("SELECT * FROM whatsapp_logs WHERE id = $log_id AND estate_id = $estate_id LIMIT 1");
    if ($chk && $chk->num_rows > 0) {
        $log_entry = $chk->fetch_assoc();
        $resend_success = false;

        if ($log_entry['template'] === 'invoice' && !empty($log_entry['reference_id'])) {
            $inv_chk = $conn->query("SELECT id FROM invoices WHERE invoice_number = '{$log_entry['reference_id']}' AND estate_id = $estate_id LIMIT 1");
            if ($inv_chk && $inv_row = $inv_chk->fetch_assoc()) {
                $resend_success = EstateWhatsApp::sendInvoiceWhatsApp($conn, $inv_row['id']);
            }
        } elseif ($log_entry['template'] === 'receipt' && !empty($log_entry['reference_id'])) {
            $resend_success = EstateWhatsApp::sendReceiptWhatsApp($conn, $log_entry['reference_id']);
        } elseif ($log_entry['template'] === 'visitor_pass' && !empty($log_entry['reference_id'])) {
            $v_chk = $conn->query("SELECT id FROM visitors WHERE visitor_code = '{$log_entry['reference_id']}' AND estate_id = $estate_id LIMIT 1");
            if ($v_chk && $v_row = $v_chk->fetch_assoc()) {
                $resend_success = EstateWhatsApp::sendVisitorPassWhatsApp($conn, $v_row['id']);
            }
        } elseif ($log_entry['template'] === 'visitor_arrival' && !empty($log_entry['reference_id'])) {
            $v_chk = $conn->query("SELECT id FROM visitors WHERE visitor_code = '{$log_entry['reference_id']}' AND estate_id = $estate_id LIMIT 1");
            if ($v_chk && $v_row = $v_chk->fetch_assoc()) {
                $resend_success = EstateWhatsApp::sendVisitorArrivalAlertWhatsApp($conn, $v_row['id']);
            }
        } else {
            // General or broadcast re-send using original body
            $res = EstateWhatsApp::sendMessage($conn, $log_entry['recipient_phone'], $log_entry['recipient_name'], $log_entry['message_body'], $log_entry['template'], $log_entry['reference_id'], $estate_id);
            $resend_success = $res['success'];
            if (!$resend_success && !empty($res['error'])) {
                $error = "Re-send failed: " . htmlspecialchars($res['error']);
            }
        }

        if ($resend_success) {
            $message = "WhatsApp message successfully re-dispatched to " . htmlspecialchars($log_entry['recipient_phone']) . " via Kapso!";
        } elseif (empty($error)) {
            $error = "Re-send failed. Please verify Kapso API credentials in Settings > WhatsApp.";
        }
    }
}

// Handle Direct WhatsApp Broadcast Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_broadcast'])) {
    $broadcast_subject = trim($_POST['broadcast_subject'] ?? '');
    $broadcast_content = trim($_POST['broadcast_content'] ?? '');
    $target_role = $_POST['target_role'] ?? 'resident';
    $target_zone = isset($_POST['target_zone']) && intval($_POST['target_zone']) > 0 ? intval($_POST['target_zone']) : null;
    
    if (empty($broadcast_subject) || empty($broadcast_content)) {
        $error = "Subject and announcement content cannot be empty.";
    } else {
        $sent_count = EstateWhatsApp::sendBroadcastWhatsApp($conn, $broadcast_subject, $broadcast_content, $target_role, $estate_id, $target_zone);
        
        // Optionally post to announcements table
        if (isset($_POST['post_to_announcements'])) {
            $subj_esc = $conn->real_escape_string($broadcast_subject);
            $content_esc = $conn->real_escape_string($broadcast_content);
            $author_id = intval($_SESSION['user_id'] ?? 1);
            $target_aud = ($target_role === 'resident') ? 'tenants' : 'all';
            $conn->query("INSERT INTO estate_announcements (estate_id, zone_id, title, content, target_audience, priority, sender_type, sender_name, status, created_by, created_at) 
                          VALUES ($estate_id, " . ($target_zone ? $target_zone : "NULL") . ", '$subj_esc', '$content_esc', '$target_aud', 'normal', 'admin', 'Central Administration', 'active', $author_id, NOW())");
        }
        
        $message = "WhatsApp Broadcast dispatched to $sent_count recipient(s) via Kapso!";
    }
}

// Filters
$filter_status = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';
$filter_template = isset($_GET['template']) ? $conn->real_escape_string($_GET['template']) : '';
$search = isset($_GET['search']) ? $conn->real_escape_string(trim($_GET['search'])) : '';

$where_clauses = ["estate_id = $estate_id"];
if (!empty($filter_status)) $where_clauses[] = "status = '$filter_status'";
if (!empty($filter_template)) $where_clauses[] = "template = '$filter_template'";
if (!empty($search)) $where_clauses[] = "(recipient_phone LIKE '%$search%' OR recipient_name LIKE '%$search%' OR reference_id LIKE '%$search%' OR message_body LIKE '%$search%')";

$where_sql = implode(' AND ', $where_clauses);

// Metrics
$total_wa = $conn->query("SELECT COUNT(id) as cnt FROM whatsapp_logs WHERE estate_id = $estate_id")->fetch_assoc()['cnt'] ?? 0;
$sent_count = $conn->query("SELECT COUNT(id) as cnt FROM whatsapp_logs WHERE estate_id = $estate_id AND status IN ('sent', 'delivered', 'read')")->fetch_assoc()['cnt'] ?? 0;
$failed_count = $conn->query("SELECT COUNT(id) as cnt FROM whatsapp_logs WHERE estate_id = $estate_id AND status = 'failed'")->fetch_assoc()['cnt'] ?? 0;
$today_count = $conn->query("SELECT COUNT(id) as cnt FROM whatsapp_logs WHERE estate_id = $estate_id AND DATE(created_at) = CURRENT_DATE()")->fetch_assoc()['cnt'] ?? 0;

// Pagination
$limit = 25;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

$total_filtered = $conn->query("SELECT COUNT(id) as cnt FROM whatsapp_logs WHERE $where_sql")->fetch_assoc()['cnt'] ?? 0;
$total_pages = ceil($total_filtered / $limit);

$logs = $conn->query("SELECT * FROM whatsapp_logs WHERE $where_sql ORDER BY id DESC LIMIT $limit OFFSET $offset");

// Fetch active zones for modal
$zones_res = $conn->query("SELECT id, name, code FROM zones WHERE estate_id = $estate_id ORDER BY name ASC");
$zones = [];
if ($zones_res) {
    while ($zr = $zones_res->fetch_assoc()) $zones[] = $zr;
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="page-header-futuristic mb-4">
    <div>
        <div class="header-breadcrumbs">
            <a href="index">Dashboard</a>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span>Communications</span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span class="active">WhatsApp Delivery Logs</span>
        </div>
        <h1 class="page-title">
            <i class="fa-brands fa-whatsapp text-success me-2"></i>WhatsApp Direct Dispatch Logs
        </h1>
        <p class="page-subtitle">Real-time telemetry for automated WhatsApp messages delivered via Kapso (invoices, receipts, digital passes, and alerts).</p>
    </div>
    <div class="header-actions">
        <a href="settings?tab=whatsapp_config" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-gear me-1"></i> Kapso Settings
        </a>
        <button type="button" onclick="document.getElementById('waBroadcastModal').style.display='flex'" class="btn btn-sm text-white" style="background: #059669; box-shadow: 0 4px 6px -1px rgba(5,150,105,0.25);">
            <i class="fa-brands fa-whatsapp me-1"></i> Send WhatsApp Broadcast
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert mature-card p-3 mb-4" style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); color: #059669; border-radius: 0.65rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fa-solid fa-circle-check" style="font-size: 1.1rem;"></i>
        <div style="font-weight: 500; font-size: 0.9rem;"><?php echo $message; ?></div>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert mature-card p-3 mb-4" style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444; border-radius: 0.65rem; display: flex; align-items: center; gap: 0.75rem;">
        <i class="fa-solid fa-circle-exclamation" style="font-size: 1.1rem;"></i>
        <div style="font-weight: 500; font-size: 0.9rem;"><?php echo $error; ?></div>
    </div>
<?php endif; ?>

<!-- ==========================================
     EXECUTIVE KPI METRICS RIBBON (4 PILLARS)
     ========================================== -->
<div class="row g-3 mb-4">
    <!-- Pillar 1: Total WhatsApp Dispatched -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Total Dispatched</span>
                    <div class="kpi-value"><?php echo number_format($total_wa); ?></div>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(37,211,102,0.12); color: #25D366;">
                    <i class="fa-brands fa-whatsapp"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>All-Time Kapso Outbound</span>
                <span class="mature-badge mature-badge-slate">Logged</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: 100%; background: #25D366;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 2: Successfully Delivered -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Successfully Sent</span>
                    <div class="kpi-value"><?php echo number_format($sent_count); ?></div>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(16,185,129,0.12); color: #10b981;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Kapso Acknowledged</span>
                <?php $rate = ($total_wa > 0) ? round(($sent_count / $total_wa) * 100) : 100; ?>
                <span class="mature-badge mature-badge-emerald"><?php echo $rate; ?>% Success</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?php echo $rate; ?>%;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 3: Delivery Failures -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Failed Dispatches</span>
                    <div class="kpi-value"><?php echo number_format($failed_count); ?></div>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(239,68,68,0.12); color: #ef4444;">
                    <i class="fa-solid fa-circle-xmark"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Delivery Exceptions</span>
                <span class="mature-badge <?php echo ($failed_count > 0) ? 'mature-badge-crimson' : 'mature-badge-emerald'; ?>">
                    <?php echo ($failed_count > 0) ? $failed_count . ' Failed' : 'Zero Failures'; ?>
                </span>
            </div>
            <div class="kpi-progress-bar">
                <?php $fail_pct = ($total_wa > 0) ? min(100, round(($failed_count / $total_wa) * 100)) : 0; ?>
                <div class="kpi-progress-fill" style="width: <?php echo max(8, $fail_pct); ?>%; background: #ef4444;"></div>
            </div>
        </div>
    </div>

    <!-- Pillar 4: Today's Activity -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Today's Activity</span>
                    <div class="kpi-value"><?php echo number_format($today_count); ?></div>
                </div>
                <div class="kpi-icon-wrap" style="background: rgba(59,130,246,0.12); color: #3b82f6;">
                    <i class="fa-solid fa-paper-plane"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Dispatched Today</span>
                <span class="mature-badge mature-badge-primary">Live Activity</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: <?php echo min(100, max(15, $today_count * 10)); ?>%;"></div>
            </div>
        </div>
    </div>
</div>

<!-- ==========================================
     FILTERS & SEARCH BAR
     ========================================== -->
<div class="futuristic-filter-bar mb-4">
    <form method="GET" class="row g-3 align-items-end">
        <div class="col-12 col-md-5">
            <label class="form-label small fw-semibold text-secondary mb-1">Search Phone / Recipient / Reference</label>
            <input type="text" name="search" class="form-control form-control-sm" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by recipient phone, name, or code...">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small fw-semibold text-secondary mb-1">Message Template</label>
            <select name="template" class="form-select form-select-sm">
                <option value="">All Templates</option>
                <option value="invoice" <?php echo $filter_template === 'invoice' ? 'selected' : ''; ?>>Invoice</option>
                <option value="receipt" <?php echo $filter_template === 'receipt' ? 'selected' : ''; ?>>Payment Receipt</option>
                <option value="visitor_pass" <?php echo $filter_template === 'visitor_pass' ? 'selected' : ''; ?>>Visitor Pass</option>
                <option value="visitor_arrival" <?php echo $filter_template === 'visitor_arrival' ? 'selected' : ''; ?>>Gate Arrival</option>
                <option value="welcome" <?php echo $filter_template === 'welcome' ? 'selected' : ''; ?>>Welcome Credentials</option>
                <option value="broadcast" <?php echo $filter_template === 'broadcast' ? 'selected' : ''; ?>>Broadcast Notice</option>
                <option value="emergency" <?php echo $filter_template === 'emergency' ? 'selected' : ''; ?>>Emergency Alert</option>
                <option value="password_reset_otp" <?php echo $filter_template === 'password_reset_otp' ? 'selected' : ''; ?>>Password Reset OTP</option>
                <option value="test_diagnostic" <?php echo $filter_template === 'test_diagnostic' ? 'selected' : ''; ?>>Test Diagnostic</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small fw-semibold text-secondary mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <option value="sent" <?php echo $filter_status === 'sent' ? 'selected' : ''; ?>>Sent</option>
                <option value="delivered" <?php echo $filter_status === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                <option value="read" <?php echo $filter_status === 'read' ? 'selected' : ''; ?>>Read</option>
                <option value="failed" <?php echo $filter_status === 'failed' ? 'selected' : ''; ?>>Failed</option>
            </select>
        </div>
        <div class="col-12 col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-primary w-100 fw-semibold" title="Apply Filter">
                <i class="fa-solid fa-filter me-1"></i> Filter
            </button>
            <?php if (!empty($search) || !empty($filter_status) || !empty($filter_template)): ?>
                <a href="whatsapp_logs.php" class="btn btn-sm btn-outline-secondary" title="Reset Filters"><i class="fa-solid fa-rotate-left"></i></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ==========================================
     LOGS DATA TABLE PANEL
     ========================================== -->
<div class="mature-card mb-4">
    <div class="mature-card-header">
        <div>
            <h3 class="mature-card-title">
                <i class="fa-brands fa-whatsapp text-success me-1"></i> WhatsApp Outbound Registry
            </h3>
            <p class="text-secondary small mb-0">Full dispatch audit trail with Kapso response tracking and WAMID message identifiers</p>
        </div>
        <span class="mature-badge mature-badge-slate">
            <i class="fa-solid fa-server me-1"></i><?php echo number_format($total_filtered); ?> Messages Found
        </span>
    </div>

    <div class="mature-card-body p-0">
        <div class="table-responsive">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th class="ps-4">ID</th>
                        <th>Recipient</th>
                        <th>Template</th>
                        <th>Reference</th>
                        <th>Message Preview</th>
                        <th>Status</th>
                        <th>Dispatched At</th>
                        <th class="text-end pe-4">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs && $logs->num_rows > 0): ?>
                        <?php while ($row = $logs->fetch_assoc()): ?>
                            <?php 
                            $clean_p = EstateWhatsApp::formatPhoneNumber($row['recipient_phone']); 
                            $wa_link = !empty($clean_p) ? "https://wa.me/{$clean_p}" : "#";
                            ?>
                            <tr>
                                <td class="ps-4">
                                    <span class="tech-chip">#<?php echo $row['id']; ?></span>
                                </td>
                                <td>
                                    <div class="fw-semibold text-slate-900"><?php echo htmlspecialchars($row['recipient_name'] ?: 'Member'); ?></div>
                                    <div class="small">
                                        <a href="<?php echo $wa_link; ?>" target="_blank" class="text-success font-monospace text-decoration-none" title="Open chat on WhatsApp">
                                            <i class="fa-brands fa-whatsapp me-1"></i>+<?php echo htmlspecialchars($clean_p ?: $row['recipient_phone']); ?>
                                        </a>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $tpl = $row['template'];
                                    $tpl_badge = 'mature-badge-slate';
                                    if ($tpl === 'invoice') $tpl_badge = 'mature-badge-primary';
                                    elseif ($tpl === 'receipt') $tpl_badge = 'mature-badge-emerald';
                                    elseif ($tpl === 'visitor_pass') $tpl_badge = 'mature-badge-sky';
                                    elseif ($tpl === 'visitor_arrival') $tpl_badge = 'mature-badge-amber';
                                    elseif ($tpl === 'broadcast') $tpl_badge = 'mature-badge-slate';
                                    elseif ($tpl === 'emergency') $tpl_badge = 'mature-badge-crimson';
                                    elseif ($tpl === 'welcome') $tpl_badge = 'mature-badge-emerald';
                                    ?>
                                    <span class="mature-badge <?php echo $tpl_badge; ?>">
                                        <?php echo str_replace('_', ' ', htmlspecialchars($tpl)); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="id-chip"><?php echo htmlspecialchars($row['reference_id'] ?: 'N/A'); ?></span>
                                </td>
                                <td style="max-width: 280px;">
                                    <div class="fw-medium text-slate-900 text-truncate" style="font-size: 0.85rem;" title="Click to view full message">
                                        <?php echo htmlspecialchars(substr($row['message_body'], 0, 75)) . (strlen($row['message_body']) > 75 ? '...' : ''); ?>
                                    </div>
                                    <button type="button" onclick="viewMessage(<?php echo htmlspecialchars(json_encode($row['message_body'])); ?>, '<?php echo htmlspecialchars($row['recipient_name']); ?>', '<?php echo htmlspecialchars($row['recipient_phone']); ?>')" class="btn btn-link p-0 text-primary small text-decoration-none" style="font-size: 0.75rem;">
                                        <i class="fa-solid fa-eye me-1"></i>View Message
                                    </button>
                                    <?php if (!empty($row['error_message'])): ?>
                                        <div class="small text-danger text-truncate mt-0.5" style="font-size: 0.72rem;" title="<?php echo htmlspecialchars($row['error_message']); ?>">
                                            <i class="fa-solid fa-circle-exclamation me-1"></i><?php echo htmlspecialchars($row['error_message']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['status'] === 'read'): ?>
                                        <span class="mature-badge mature-badge-sky" title="Read by recipient">
                                            <i class="fa-solid fa-check-double text-info me-1"></i> READ
                                        </span>
                                    <?php elseif ($row['status'] === 'delivered'): ?>
                                        <span class="mature-badge mature-badge-emerald" title="Delivered to device">
                                            <i class="fa-solid fa-check-double me-1"></i> DELIVERED
                                        </span>
                                    <?php elseif ($row['status'] === 'sent'): ?>
                                        <span class="mature-badge mature-badge-emerald" title="Dispatched to WhatsApp servers">
                                            <i class="fa-solid fa-check me-1"></i> SENT
                                        </span>
                                    <?php else: ?>
                                        <span class="mature-badge mature-badge-crimson" title="<?php echo htmlspecialchars($row['error_message'] ?? 'Dispatch failed'); ?>">
                                            <i class="fa-solid fa-xmark me-1"></i> FAILED
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small text-slate-700">
                                        <i class="fa-regular fa-clock me-1 text-secondary"></i><?php echo date('M d, Y h:i A', strtotime($row['created_at'])); ?>
                                    </div>
                                    <?php if (!empty($row['response_id'])): ?>
                                        <div class="text-secondary font-monospace" style="font-size: 0.65rem;" title="Kapso WAMID: <?php echo htmlspecialchars($row['response_id']); ?>">
                                            <?php echo htmlspecialchars(substr($row['response_id'], 0, 18)) . '...'; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-4">
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Re-dispatch this WhatsApp message now?');">
                                        <input type="hidden" name="retry_log_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" class="btn btn-outline-secondary btn-sm" title="Retry Delivery">
                                            <i class="fa-solid fa-rotate-right me-1"></i> Resend
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-secondary">
                                <i class="fa-brands fa-whatsapp fs-1 text-muted mb-2 d-block opacity-50"></i>
                                No WhatsApp activity logs recorded yet. Outgoing messages will appear here in real-time.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="d-flex justify-content-between align-items-center p-3 border-top border-light-subtle small text-secondary">
            <div>Showing page <strong><?php echo $page; ?></strong> of <strong><?php echo $total_pages; ?></strong> (<?php echo $total_filtered; ?> total entries)</div>
            <div class="d-flex gap-1">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo ($page - 1); ?>&status=<?php echo urlencode($filter_status); ?>&template=<?php echo urlencode($filter_template); ?>&search=<?php echo urlencode($search); ?>" class="btn btn-sm btn-outline-secondary">&laquo; Prev</a>
                <?php endif; ?>
                <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                    <a href="?page=<?php echo $p; ?>&status=<?php echo urlencode($filter_status); ?>&template=<?php echo urlencode($filter_template); ?>&search=<?php echo urlencode($search); ?>" class="btn btn-sm <?php echo $p == $page ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                        <?php echo $p; ?>
                    </a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo ($page + 1); ?>&status=<?php echo urlencode($filter_status); ?>&template=<?php echo urlencode($filter_template); ?>&search=<?php echo urlencode($search); ?>" class="btn btn-sm btn-outline-secondary">Next &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- View WhatsApp Message Modal -->
<div id="viewMessageModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.7); z-index: 1055; align-items: center; justify-content: center; backdrop-filter: blur(8px);">
    <div class="mature-card p-4 rounded-4" style="width: 100%; max-width: 520px; margin: auto; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mature-card-title mb-0" style="font-size: 1.1rem;">
                <i class="fa-brands fa-whatsapp text-success me-1"></i> WhatsApp Message Payload
            </h3>
            <button type="button" onclick="document.getElementById('viewMessageModal').style.display='none'" class="btn-close"></button>
        </div>
        <div class="mb-2 text-secondary small">
            To: <strong id="modalMsgRecipient" class="text-dark"></strong> (<span id="modalMsgPhone" class="font-monospace"></span>)
        </div>
        <!-- WhatsApp Chat Bubble Simulation -->
        <div style="background: #efeae2; padding: 1.25rem; border-radius: 0.75rem; max-height: 400px; overflow-y: auto;">
            <div style="background: #ffffff; border-radius: 0.5rem 0.5rem 0.5rem 0; padding: 1rem; box-shadow: 0 1px 2px rgba(0,0,0,0.1); font-size: 0.88rem; color: #111b21; white-space: pre-wrap; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.5;" id="modalMsgBody"></div>
        </div>
        <div class="text-end mt-3">
            <button type="button" onclick="document.getElementById('viewMessageModal').style.display='none'" class="btn btn-sm btn-secondary px-3">Close</button>
        </div>
    </div>
</div>

<!-- Broadcast WhatsApp Modal -->
<div id="waBroadcastModal" onclick="if(event.target === this) this.style.display='none'" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.7); z-index: 1060; align-items: center; justify-content: center; backdrop-filter: blur(8px); overflow-y: auto; padding: 1.5rem 1rem;">
    <div class="mature-card p-4 rounded-4" style="width: 100%; max-width: 560px; margin: auto; max-height: calc(100vh - 3rem); overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mature-card-title">
                <i class="fa-brands fa-whatsapp text-success me-1"></i> Send Direct WhatsApp Broadcast
            </h3>
            <button type="button" onclick="document.getElementById('waBroadcastModal').style.display='none'" class="btn-close"></button>
        </div>
        <form method="POST">
            <div class="row g-3 mb-3">
                <div class="col-sm-6">
                    <label class="form-label small fw-semibold text-secondary">Target Audience</label>
                    <select name="target_role" class="form-select form-select-sm">
                        <option value="resident">All Active Residents</option>
                        <option value="staff">All Estate Staff</option>
                        <option value="all">Everyone (Residents &amp; Staff)</option>
                    </select>
                </div>
                <div class="col-sm-6">
                    <label class="form-label small fw-semibold text-secondary">Target Zone (Optional)</label>
                    <select name="target_zone" class="form-select form-select-sm">
                        <option value="">Estate-Wide (All Zones)</option>
                        <?php foreach ($zones as $z): ?>
                            <option value="<?php echo $z['id']; ?>"><?php echo htmlspecialchars($z['name']); ?> (<?php echo htmlspecialchars($z['code']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold text-secondary">Broadcast Heading / Subject</label>
                <input type="text" name="broadcast_subject" class="form-control" required placeholder="e.g. Scheduled Infrastructure Maintenance">
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold text-secondary">Message Content Body</label>
                <textarea name="broadcast_content" id="waContentTextarea" class="form-control" rows="5" required placeholder="Type your advisory here... WhatsApp supports *bold*, _italics_, and emojis." oninput="updateCharCount(this)"></textarea>
                <div class="d-flex justify-content-between mt-1 text-muted" style="font-size: 0.75rem;">
                    <span>Tip: Use <code>*bold*</code> or <code>_italics_</code> to highlight text</span>
                    <span id="waCharCount">0 characters</span>
                </div>
            </div>
            <div class="mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="post_to_announcements" value="1" id="waPostAnnounceCheck" checked>
                    <label class="form-check-label small text-secondary" for="waPostAnnounceCheck">
                        Also pin this notice on the Resident Portal noticeboard
                    </label>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" onclick="document.getElementById('waBroadcastModal').style.display='none'" class="btn btn-sm btn-light">Cancel</button>
                <button type="submit" name="send_broadcast" class="btn btn-sm text-white px-4 fw-semibold" style="background: #059669;">
                    <i class="fa-brands fa-whatsapp me-1"></i> Dispatch via Kapso
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function viewMessage(body, name, phone) {
    document.getElementById('modalMsgRecipient').textContent = name || 'Recipient';
    document.getElementById('modalMsgPhone').textContent = phone || '';
    document.getElementById('modalMsgBody').textContent = body || '';
    document.getElementById('viewMessageModal').style.display = 'flex';
}

function updateCharCount(textarea) {
    document.getElementById('waCharCount').textContent = textarea.value.length + ' characters';
}
</script>

<?php include '../includes/footer.php'; ?>
