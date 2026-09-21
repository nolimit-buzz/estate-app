<?php
// admin/email_logs.php - Outgoing Email Delivery & Audit Logs
require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/Mailer.php';
requireAdminAccess();

$estate_id = get_estate_id();
EstateMailer::ensureDatabaseTables($conn);

$message = "";
$error = "";

// Handle Resend / Retry Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retry_log_id'])) {
    $log_id = intval($_POST['retry_log_id']);
    $chk = $conn->query("SELECT * FROM email_logs WHERE id = $log_id AND estate_id = $estate_id LIMIT 1");
    if ($chk && $chk->num_rows > 0) {
        $log_entry = $chk->fetch_assoc();
        $resend_success = false;

        if ($log_entry['template'] === 'invoice' && !empty($log_entry['reference_id'])) {
            $inv_chk = $conn->query("SELECT id FROM invoices WHERE invoice_number = '{$log_entry['reference_id']}' AND estate_id = $estate_id LIMIT 1");
            if ($inv_chk && $inv_row = $inv_chk->fetch_assoc()) {
                $resend_success = EstateMailer::sendInvoiceEmail($conn, $inv_row['id']);
            }
        } elseif ($log_entry['template'] === 'receipt' && !empty($log_entry['reference_id'])) {
            $resend_success = EstateMailer::sendReceiptEmail($conn, $log_entry['reference_id']);
        } elseif ($log_entry['template'] === 'visitor_pass' && !empty($log_entry['reference_id'])) {
            $v_chk = $conn->query("SELECT id FROM visitors WHERE visitor_code = '{$log_entry['reference_id']}' AND estate_id = $estate_id LIMIT 1");
            if ($v_chk && $v_row = $v_chk->fetch_assoc()) {
                $resend_success = EstateMailer::sendVisitorPassEmail($conn, $v_row['id']);
            }
        } elseif ($log_entry['template'] === 'visitor_arrival' && !empty($log_entry['reference_id'])) {
            $v_chk = $conn->query("SELECT id FROM visitors WHERE visitor_code = '{$log_entry['reference_id']}' AND estate_id = $estate_id LIMIT 1");
            if ($v_chk && $v_row = $v_chk->fetch_assoc()) {
                $resend_success = EstateMailer::sendVisitorArrivalAlert($conn, $v_row['id']);
            }
        } else {
            // General test or broadcast re-send
            $resend_success = EstateMailer::sendMail($log_entry['recipient_email'], $log_entry['recipient_name'], $log_entry['subject'], "<p>Retried message content</p>", $log_entry['template'], $log_entry['reference_id'], $estate_id);
        }

        if ($resend_success) {
            $message = "Email successfully re-dispatched to " . htmlspecialchars($log_entry['recipient_email']) . "!";
        } else {
            $error = "Re-send failed. Please verify SMTP settings and check server logs.";
        }
    }
}

// Handle Broadcast Email Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_broadcast'])) {
    $broadcast_subject = trim($_POST['broadcast_subject'] ?? '');
    $broadcast_content = trim($_POST['broadcast_content'] ?? '');
    $target_role = $_POST['target_role'] ?? 'resident';
    
    if (empty($broadcast_subject) || empty($broadcast_content)) {
        $error = "Subject and message content cannot be empty.";
    } else {
        $sent_count = EstateMailer::sendBroadcastEmail($conn, $broadcast_subject, nl2br(htmlspecialchars($broadcast_content)), $target_role, $estate_id);
        
        // Optionally post to announcements table
        if (isset($_POST['post_to_announcements'])) {
            $subj_esc = $conn->real_escape_string($broadcast_subject);
            $content_esc = $conn->real_escape_string($broadcast_content);
            $author_id = intval($_SESSION['user_id'] ?? 1);
            $target_aud = ($target_role === 'resident') ? 'tenants' : 'all';
            $conn->query("INSERT INTO estate_announcements (estate_id, title, content, target_audience, priority, sender_type, sender_name, status, created_by, created_at) 
                          VALUES ($estate_id, '$subj_esc', '$content_esc', '$target_aud', 'normal', 'admin', 'Central Administration', 'active', $author_id, NOW())");
        }
        
        $message = "Broadcast successfully dispatched to $sent_count recipient(s)!";
    }
}

// Filters
$filter_status = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';
$filter_template = isset($_GET['template']) ? $conn->real_escape_string($_GET['template']) : '';
$search = isset($_GET['search']) ? $conn->real_escape_string(trim($_GET['search'])) : '';

$where_clauses = ["estate_id = $estate_id"];
if (!empty($filter_status)) $where_clauses[] = "status = '$filter_status'";
if (!empty($filter_template)) $where_clauses[] = "template = '$filter_template'";
if (!empty($search)) $where_clauses[] = "(recipient_email LIKE '%$search%' OR recipient_name LIKE '%$search%' OR subject LIKE '%$search%' OR reference_id LIKE '%$search%')";

$where_sql = implode(' AND ', $where_clauses);

// Metrics
$total_emails = $conn->query("SELECT COUNT(id) as cnt FROM email_logs WHERE estate_id = $estate_id")->fetch_assoc()['cnt'] ?? 0;
$sent_count = $conn->query("SELECT COUNT(id) as cnt FROM email_logs WHERE estate_id = $estate_id AND status = 'sent'")->fetch_assoc()['cnt'] ?? 0;
$failed_count = $conn->query("SELECT COUNT(id) as cnt FROM email_logs WHERE estate_id = $estate_id AND status = 'failed'")->fetch_assoc()['cnt'] ?? 0;
$today_count = $conn->query("SELECT COUNT(id) as cnt FROM email_logs WHERE estate_id = $estate_id AND DATE(created_at) = CURRENT_DATE()")->fetch_assoc()['cnt'] ?? 0;

// Pagination
$limit = 25;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

$total_filtered = $conn->query("SELECT COUNT(id) as cnt FROM email_logs WHERE $where_sql")->fetch_assoc()['cnt'] ?? 0;
$total_pages = ceil($total_filtered / $limit);

$logs = $conn->query("SELECT * FROM email_logs WHERE $where_sql ORDER BY id DESC LIMIT $limit OFFSET $offset");

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
            <span class="active">Email Delivery Logs</span>
        </div>
        <h1 class="page-title">Email Delivery &amp; Audit Logs</h1>
        <p class="page-subtitle">Real-time telemetry for automated invoices, receipts, access passes, and outbound estate broadcasts.</p>
    </div>
    <div class="header-actions">
        <a href="settings?tab=smtp_config" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-gear me-1"></i> SMTP Settings
        </a>
        <button type="button" onclick="document.getElementById('broadcastModal').style.display='flex'" class="btn btn-sm text-white" style="background: #0f172a;">
            <i class="fa-solid fa-bullhorn me-1"></i> Send Broadcast Email
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
    <!-- Pillar 1: Total Emails Dispatched -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card h-100">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="kpi-title">Total Dispatched</span>
                    <div class="kpi-value"><?php echo number_format($total_emails); ?></div>
                </div>
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-envelope-open-text"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>All-Time Outbound</span>
                <span class="mature-badge mature-badge-slate">Logged</span>
            </div>
            <div class="kpi-progress-bar">
                <div class="kpi-progress-fill" style="width: 100%;"></div>
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
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Mail Server Acknowledged</span>
                <?php $rate = ($total_emails > 0) ? round(($sent_count / $total_emails) * 100) : 100; ?>
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
                <div class="kpi-icon-wrap">
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
                <?php $fail_pct = ($total_emails > 0) ? min(100, round(($failed_count / $total_emails) * 100)) : 0; ?>
                <div class="kpi-progress-fill" style="width: <?php echo max(8, $fail_pct); ?>%;"></div>
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
                <div class="kpi-icon-wrap">
                    <i class="fa-solid fa-paper-plane"></i>
                </div>
            </div>
            <div class="kpi-meta justify-content-between mt-2">
                <span>Dispatched Today</span>
                <span class="mature-badge mature-badge-primary">Live Queue</span>
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
            <label class="form-label small fw-semibold text-secondary mb-1">Search Recipient / Subject</label>
            <input type="text" name="search" class="form-control form-control-sm" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by recipient email, subject, or code...">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small fw-semibold text-secondary mb-1">Notification Template</label>
            <select name="template" class="form-select form-select-sm">
                <option value="">All Templates</option>
                <option value="invoice" <?php echo $filter_template === 'invoice' ? 'selected' : ''; ?>>Invoice</option>
                <option value="receipt" <?php echo $filter_template === 'receipt' ? 'selected' : ''; ?>>Payment Receipt</option>
                <option value="visitor_pass" <?php echo $filter_template === 'visitor_pass' ? 'selected' : ''; ?>>Visitor Pass</option>
                <option value="visitor_arrival" <?php echo $filter_template === 'visitor_arrival' ? 'selected' : ''; ?>>Gate Arrival</option>
                <option value="welcome" <?php echo $filter_template === 'welcome' ? 'selected' : ''; ?>>Welcome Credentials</option>
                <option value="broadcast" <?php echo $filter_template === 'broadcast' ? 'selected' : ''; ?>>Broadcast</option>
                <option value="test_diagnostic" <?php echo $filter_template === 'test_diagnostic' ? 'selected' : ''; ?>>Test Diagnostic</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small fw-semibold text-secondary mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <option value="sent" <?php echo $filter_status === 'sent' ? 'selected' : ''; ?>>Sent</option>
                <option value="failed" <?php echo $filter_status === 'failed' ? 'selected' : ''; ?>>Failed</option>
            </select>
        </div>
        <div class="col-12 col-md-2 d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-primary w-100 fw-semibold" title="Apply Filter">
                <i class="fa-solid fa-filter me-1"></i> Filter
            </button>
            <?php if (!empty($search) || !empty($filter_status) || !empty($filter_template)): ?>
                <a href="email_logs.php" class="btn btn-sm btn-outline-secondary" title="Reset Filters"><i class="fa-solid fa-rotate-left"></i></a>
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
                <i class="fa-solid fa-envelopes-bulk text-secondary"></i> Outbound Notification Registry
            </h3>
            <p class="text-secondary small mb-0">Full dispatch audit trail with payload reference tracking and SMTP response codes</p>
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
                        <th>Subject</th>
                        <th>Template</th>
                        <th>Reference</th>
                        <th>Status</th>
                        <th>Dispatched At</th>
                        <th class="text-end pe-4">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs && $logs->num_rows > 0): ?>
                        <?php while ($row = $logs->fetch_assoc()): ?>
                            <tr>
                                <td class="ps-4">
                                    <span class="tech-chip">#<?php echo $row['id']; ?></span>
                                </td>
                                <td>
                                    <div class="fw-semibold text-slate-900"><?php echo htmlspecialchars($row['recipient_name'] ?: 'Recipient'); ?></div>
                                    <div class="small text-secondary font-monospace"><?php echo htmlspecialchars($row['recipient_email']); ?></div>
                                </td>
                                <td style="max-width: 280px;">
                                    <div class="fw-medium text-slate-900 text-truncate" title="<?php echo htmlspecialchars($row['subject']); ?>">
                                        <?php echo htmlspecialchars($row['subject']); ?>
                                    </div>
                                    <?php if (!empty($row['error_message'])): ?>
                                        <div class="small text-danger text-truncate mt-0.5" style="font-size: 0.72rem;" title="<?php echo htmlspecialchars($row['error_message']); ?>">
                                            <i class="fa-solid fa-circle-exclamation me-1"></i><?php echo htmlspecialchars($row['error_message']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $tpl = $row['template'];
                                    $tpl_badge = 'mature-badge-slate';
                                    if ($tpl === 'invoice') $tpl_badge = 'mature-badge-primary';
                                    elseif ($tpl === 'receipt') $tpl_badge = 'mature-badge-emerald';
                                    elseif ($tpl === 'visitor_pass') $tpl_badge = 'mature-badge-sky';
                                    elseif ($tpl === 'broadcast') $tpl_badge = 'mature-badge-amber';
                                    ?>
                                    <span class="mature-badge <?php echo $tpl_badge; ?>">
                                        <?php echo str_replace('_', ' ', htmlspecialchars($tpl)); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="id-chip"><?php echo htmlspecialchars($row['reference_id'] ?: 'N/A'); ?></span>
                                </td>
                                <td>
                                    <?php if ($row['status'] === 'sent'): ?>
                                        <span class="mature-badge mature-badge-emerald">
                                            <i class="fa-solid fa-check me-1"></i> SENT
                                        </span>
                                    <?php else: ?>
                                        <span class="mature-badge mature-badge-crimson">
                                            <i class="fa-solid fa-xmark me-1"></i> FAILED
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small text-slate-700">
                                        <i class="fa-regular fa-clock me-1 text-secondary"></i><?php echo date('M d, Y h:i A', strtotime($row['created_at'])); ?>
                                    </div>
                                </td>
                                <td class="text-end pe-4">
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Re-dispatch this email now?');">
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
                                <i class="fa-regular fa-envelope-open fs-1 text-muted mb-2 d-block"></i>
                                No email activity logs recorded yet. Outgoing notifications will appear here automatically.
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

<!-- Broadcast Email Modal -->
<div id="broadcastModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.7); z-index: 1050; align-items: center; justify-content: center; backdrop-filter: blur(8px);">
    <div class="mature-card p-4 rounded-4" style="width: 100%; max-width: 560px; margin: auto; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h3 class="mature-card-title">
                <i class="fa-solid fa-bullhorn text-primary"></i> Send Estate Broadcast Email
            </h3>
            <button type="button" onclick="document.getElementById('broadcastModal').style.display='none'" class="btn-close"></button>
        </div>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label small fw-semibold text-secondary">Target Audience</label>
                <select name="target_role" class="form-select">
                    <option value="resident">All Active Residents</option>
                    <option value="staff">All Estate Staff</option>
                    <option value="all">Everyone in Estate (Residents &amp; Staff)</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold text-secondary">Email Subject</label>
                <input type="text" name="broadcast_subject" class="form-control" required placeholder="e.g. Scheduled Power Maintenance Notice">
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold text-secondary">Message Content</label>
                <textarea name="broadcast_content" class="form-control" rows="5" required placeholder="Type your announcement or alert message here..."></textarea>
            </div>
            <div class="mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="post_to_announcements" value="1" id="postAnnounceCheck" checked>
                    <label class="form-check-label small text-secondary" for="postAnnounceCheck">
                        Also pin this announcement on the Resident Portal noticeboard
                    </label>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" onclick="document.getElementById('broadcastModal').style.display='none'" class="btn btn-sm btn-light">Cancel</button>
                <button type="submit" name="send_broadcast" class="btn btn-sm btn-primary px-4 fw-semibold">
                    <i class="fa-solid fa-paper-plane me-1"></i> Dispatch Broadcast
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
