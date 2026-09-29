<?php
// admin/artisans.php - Central Admin Artisan Onboarding Verification & Directory Management
require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/ArtisanHelper.php';
requireAdminAccess();

$estate_id = get_estate_id();
$user_id = intval($_SESSION['user_id']);

// Handle Verification Decisions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $artisan_id = intval($_POST['artisan_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($artisan_id > 0) {
        $art_q = $conn->query("SELECT * FROM artisans WHERE id = $artisan_id AND estate_id = $estate_id LIMIT 1");
        if ($art_q && $art_q->num_rows > 0) {
            $artisan = $art_q->fetch_assoc();
            $artisan_name = $artisan['full_name'];

            if ($action === 'approve_artisan') {
                // Generate next sequential badge code if not set
                $badge_code = $artisan['artisan_code'];
                if (empty($badge_code)) {
                    $badge_code = ArtisanHelper::generateArtisanCode($conn, $estate_id);
                }
                
                $sql = "UPDATE artisans 
                        SET verification_status = 'verified', 
                            artisan_code = '$badge_code', 
                            verified_by = $user_id, 
                            verified_at = NOW(), 
                            verification_notes = '" . $conn->real_escape_string($notes) . "' 
                        WHERE id = $artisan_id AND estate_id = $estate_id";
                
                if ($conn->query($sql)) {
                    // Send notifications to artisan via Email & WhatsApp
                    ArtisanHelper::notifyArtisanVerificationOutcome($conn, $artisan_id, 'verified', $notes);
                    
                    if (function_exists('logAudit')) {
                        logAudit($conn, "Artisan Approved", "Maintenance", "Artisan $artisan_name (#$artisan_id) verified with Badge $badge_code by Admin.");
                    }
                    $_SESSION['success_msg'] = "Artisan $artisan_name has been verified and assigned badge $badge_code. They are now live in the Resident Directory!";
                } else {
                    $_SESSION['error_msg'] = "Error approving artisan: " . $conn->error;
                }
            } elseif ($action === 'reject_artisan') {
                $rejection_reason = trim($_POST['rejection_reason'] ?? $notes);
                $sql = "UPDATE artisans 
                        SET verification_status = 'rejected', 
                            rejection_reason = '" . $conn->real_escape_string($rejection_reason) . "', 
                            verified_by = $user_id, 
                            verified_at = NOW() 
                        WHERE id = $artisan_id AND estate_id = $estate_id";
                
                if ($conn->query($sql)) {
                    ArtisanHelper::notifyArtisanVerificationOutcome($conn, $artisan_id, 'rejected', $rejection_reason);
                    if (function_exists('logAudit')) {
                        logAudit($conn, "Artisan Rejected", "Maintenance", "Artisan $artisan_name (#$artisan_id) application rejected. Reason: $rejection_reason");
                    }
                    $_SESSION['success_msg'] = "Application for $artisan_name was rejected and applicant has been notified.";
                } else {
                    $_SESSION['error_msg'] = "Error updating status: " . $conn->error;
                }
            } elseif ($action === 'suspend_artisan') {
                $sql = "UPDATE artisans 
                        SET verification_status = 'suspended', 
                            verification_notes = CONCAT(IFNULL(verification_notes,''), '\nSuspended on " . date('Y-m-d H:i') . ": ', '" . $conn->real_escape_string($notes) . "') 
                        WHERE id = $artisan_id AND estate_id = $estate_id";
                if ($conn->query($sql)) {
                    if (function_exists('logAudit')) {
                        logAudit($conn, "Artisan Suspended", "Maintenance", "Artisan $artisan_name (#$artisan_id) suspended from resident directory.");
                    }
                    $_SESSION['success_msg'] = "Artisan $artisan_name has been suspended and removed from active resident directory.";
                }
            } elseif ($action === 'reactivate_artisan') {
                $sql = "UPDATE artisans 
                        SET verification_status = 'verified' 
                        WHERE id = $artisan_id AND estate_id = $estate_id";
                if ($conn->query($sql)) {
                    if (function_exists('logAudit')) {
                        logAudit($conn, "Artisan Reactivated", "Maintenance", "Artisan $artisan_name (#$artisan_id) reactivated by Admin.");
                    }
                    $_SESSION['success_msg'] = "Artisan $artisan_name has been reactivated.";
                }
            }
        }
    }
    header("Location: artisans.php" . (!empty($_GET['tab']) ? '?tab=' . urlencode($_GET['tab']) : ''));
    exit;
}

// Fetch messages
$success = $_SESSION['success_msg'] ?? '';
$error = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// Active Tab
$current_tab = $_GET['tab'] ?? 'pending';
if (!in_array($current_tab, ['pending', 'verified', 'suspended', 'all'])) {
    $current_tab = 'pending';
}

// Trade filter & search
$filter_trade = trim($_GET['trade'] ?? '');
$search_query = trim($_GET['q'] ?? '');

// Counts for KPI pills
$pending_count = $conn->query("SELECT COUNT(*) as cnt FROM artisans WHERE estate_id = $estate_id AND verification_status = 'pending'")->fetch_assoc()['cnt'] ?? 0;
$verified_count = $conn->query("SELECT COUNT(*) as cnt FROM artisans WHERE estate_id = $estate_id AND verification_status = 'verified'")->fetch_assoc()['cnt'] ?? 0;
$suspended_count = $conn->query("SELECT COUNT(*) as cnt FROM artisans WHERE estate_id = $estate_id AND verification_status = 'suspended'")->fetch_assoc()['cnt'] ?? 0;
$emergency_count = $conn->query("SELECT COUNT(*) as cnt FROM artisans WHERE estate_id = $estate_id AND verification_status = 'verified' AND is_emergency_ready = 1")->fetch_assoc()['cnt'] ?? 0;

// Build Query
$where_clauses = ["a.estate_id = $estate_id"];

if ($current_tab === 'pending') {
    $where_clauses[] = "a.verification_status = 'pending'";
} elseif ($current_tab === 'verified') {
    $where_clauses[] = "a.verification_status = 'verified'";
} elseif ($current_tab === 'suspended') {
    $where_clauses[] = "a.verification_status = 'suspended'";
}

if (!empty($filter_trade)) {
    $esc_trade = $conn->real_escape_string($filter_trade);
    $where_clauses[] = "a.trade_category = '$esc_trade'";
}

if (!empty($search_query)) {
    $esc_q = $conn->real_escape_string($search_query);
    $where_clauses[] = "(a.full_name LIKE '%$esc_q%' OR a.phone LIKE '%$esc_q%' OR a.artisan_code LIKE '%$esc_q%' OR a.specialties LIKE '%$esc_q%')";
}

$where_sql = implode(' AND ', $where_clauses);

$artisans_res = $conn->query("SELECT a.*, u.name as verifier_name, 
                                     r_user.name as referrer_name 
                              FROM artisans a 
                              LEFT JOIN users u ON a.verified_by = u.id 
                              LEFT JOIN users r_user ON a.referred_by_id = r_user.id 
                              WHERE $where_sql 
                              ORDER BY (CASE WHEN a.verification_status = 'pending' THEN 0 ELSE 1 END), a.id DESC");

$tradeList = ArtisanHelper::getTradeCategories();
$public_reg_url = EstateMailer::getBaseUrl() . 'artisan_register.php?ref=admin';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
    .kpi-card-artisan {
        background: #ffffff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        padding: 1.25rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    .kpi-icon-artisan {
        width: 48px;
        height: 48px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
    }
    .tab-nav-artisan {
        display: flex;
        gap: 0.5rem;
        border-bottom: 2px solid #e2e8f0;
        margin-bottom: 1.5rem;
        overflow-x: auto;
    }
    .tab-link-artisan {
        padding: 0.75rem 1.25rem;
        font-weight: 600;
        color: #64748b;
        text-decoration: none;
        border-bottom: 3px solid transparent;
        margin-bottom: -2px;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        white-space: nowrap;
        transition: all 0.2s;
    }
    .tab-link-artisan:hover {
        color: #2563eb;
    }
    .tab-link-artisan.active {
        color: #2563eb;
        border-bottom-color: #2563eb;
    }
    .artisan-avatar-sm {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        object-fit: cover;
        background: #e2e8f0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        color: #475569;
    }
    .status-badge-pending {
        background: #fef3c7;
        color: #b45309;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 9999px;
        font-size: 0.75rem;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .status-badge-verified {
        background: #d1fae5;
        color: #047857;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 9999px;
        font-size: 0.75rem;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .status-badge-suspended {
        background: #fee2e2;
        color: #b91c1c;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 9999px;
        font-size: 0.75rem;
    }
    .id-thumbnail {
        width: 60px;
        height: 42px;
        object-fit: cover;
        border-radius: 4px;
        border: 1px solid #cbd5e1;
        cursor: pointer;
        transition: transform 0.2s;
    }
    .id-thumbnail:hover {
        transform: scale(1.08);
    }
</style>

<div class="container-fluid px-3 px-md-4 py-3">

    <!-- Page Header & Action Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                    <i class="fa-solid fa-screwdriver-wrench"></i> Facility &amp; Contractors
                </span>
                <span class="text-secondary small">• Central Admin Verification Gate</span>
            </div>
            <h1 class="h3 font-bold text-slate-800 m-0">Artisan Directory &amp; Accreditation</h1>
            <p class="text-secondary small mb-0">Review pending registrations, verify identity documents, and onboard artisans to the resident directory.</p>
        </div>
        
        <div class="d-flex flex-wrap align-items-center gap-2">
            <button type="button" class="btn btn-outline-secondary d-flex align-items-center gap-2" onclick="copyRegLink()" title="Copy Public Onboarding Link">
                <i class="fa-solid fa-link text-primary"></i> <span class="d-none d-sm-inline">Share Link</span>
            </button>
            <a href="https://wa.me/?text=<?php echo urlencode("Apply to become an accredited artisan for $estate_name: " . $public_reg_url); ?>" target="_blank" class="btn btn-outline-success d-flex align-items-center gap-2" title="Share on WhatsApp">
                <i class="fa-brands fa-whatsapp"></i> <span class="d-none d-sm-inline">WhatsApp</span>
            </a>
            <a href="../artisan_register.php?ref=admin" target="_blank" class="btn btn-primary d-flex align-items-center gap-2">
                <i class="fa-solid fa-user-plus"></i> + Onboard Artisan
            </a>
        </div>
    </div>

    <!-- Flash Notifications -->
    <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm d-flex align-items-center gap-2 mb-4" role="alert">
            <i class="fa-solid fa-circle-check fs-5 text-success"></i>
            <div><?php echo htmlspecialchars($success); ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm d-flex align-items-center gap-2 mb-4" role="alert">
            <i class="fa-solid fa-circle-exclamation fs-5 text-danger"></i>
            <div><?php echo htmlspecialchars($error); ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- KPI Metric Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="kpi-card-artisan">
                <div class="kpi-icon-artisan bg-warning-subtle text-warning-emphasis">
                    <i class="fa-solid fa-hourglass-half"></i>
                </div>
                <div>
                    <div class="text-secondary small fw-semibold">Pending Vetting</div>
                    <div class="fs-4 font-bold text-dark"><?php echo $pending_count; ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-card-artisan">
                <div class="kpi-icon-artisan bg-success-subtle text-success">
                    <i class="fa-solid fa-certificate"></i>
                </div>
                <div>
                    <div class="text-secondary small fw-semibold">Verified Active</div>
                    <div class="fs-4 font-bold text-dark"><?php echo $verified_count; ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-card-artisan">
                <div class="kpi-icon-artisan bg-danger-subtle text-danger">
                    <i class="fa-solid fa-bolt"></i>
                </div>
                <div>
                    <div class="text-secondary small fw-semibold">24/7 Emergency Ready</div>
                    <div class="fs-4 font-bold text-dark"><?php echo $emergency_count; ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="kpi-card-artisan">
                <div class="kpi-icon-artisan bg-secondary-subtle text-secondary">
                    <i class="fa-solid fa-ban"></i>
                </div>
                <div>
                    <div class="text-secondary small fw-semibold">Suspended</div>
                    <div class="fs-4 font-bold text-dark"><?php echo $suspended_count; ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs & Search/Filter Controls -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <div class="tab-nav-artisan">
                <a href="artisans.php?tab=pending" class="tab-link-artisan <?php echo ($current_tab === 'pending') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-user-clock text-warning"></i> Pending Verification
                    <?php if ($pending_count > 0): ?>
                        <span class="badge bg-warning text-dark rounded-pill"><?php echo $pending_count; ?></span>
                    <?php endif; ?>
                </a>
                <a href="artisans.php?tab=verified" class="tab-link-artisan <?php echo ($current_tab === 'verified') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-shield-check text-success"></i> Verified &amp; Onboarded (<?php echo $verified_count; ?>)
                </a>
                <a href="artisans.php?tab=suspended" class="tab-link-artisan <?php echo ($current_tab === 'suspended') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-ban text-danger"></i> Suspended (<?php echo $suspended_count; ?>)
                </a>
                <a href="artisans.php?tab=all" class="tab-link-artisan <?php echo ($current_tab === 'all') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-list"></i> All Records
                </a>
            </div>

            <!-- Filters (Image 1 & 2 Style) -->
            <form method="GET" action="artisans.php" class="artisan-search-hero-bar mt-3 mb-0">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($current_tab); ?>">
                <div class="artisan-search-inner-input">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" name="q" placeholder="Search by name, skill (e.g. inverter, leaking pipe, POP, tiles), phone..." value="<?php echo htmlspecialchars($search_query); ?>">
                </div>
                <div style="min-width: 200px;">
                    <select name="trade" class="form-select border-0 shadow-none py-1" style="background-color: transparent !important; font-size: 0.85rem; font-weight: 600;">
                        <option value="">All Trade Categories</option>
                        <?php foreach ($tradeList as $k => $t): ?>
                            <option value="<?php echo $k; ?>" <?php echo ($filter_trade === $k) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="artisan-search-submit-btn">Search</button>
                <?php if (!empty($search_query) || !empty($filter_trade)): ?>
                    <a href="artisans.php?tab=<?php echo htmlspecialchars($current_tab); ?>" class="btn btn-outline-secondary rounded-pill px-3 py-2" title="Reset Filters"><i class="fa-solid fa-rotate-left"></i></a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Artisan Table Card -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Artisan / Profile</th>
                        <th>Trade &amp; Skills</th>
                        <th>Contact / Phone</th>
                        <th>ID &amp; Documents</th>
                        <th>Gate Status</th>
                        <th>Performance</th>
                        <th class="text-end" style="min-width: 140px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($artisans_res && $artisans_res->num_rows > 0): ?>
                        <?php $idx = 1; while ($row = $artisans_res->fetch_assoc()): 
                            $tradeInfo = ArtisanHelper::getTradeInfo($row['trade_category']);
                            $avatar_url = !empty($row['profile_photo']) ? '../' . ltrim($row['profile_photo'], '/') : '';
                            $id_doc_url = !empty($row['id_document_path']) ? '../' . ltrim($row['id_document_path'], '/') : '';
                            $is_pdf = strtolower(pathinfo($id_doc_url, PATHINFO_EXTENSION)) === 'pdf';
                        ?>
                            <tr>
                                <td class="text-secondary small"><?php echo $idx++; ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <?php if (!empty($avatar_url) && file_exists(__DIR__ . '/' . $avatar_url)): ?>
                                            <img src="<?php echo htmlspecialchars($avatar_url); ?>" class="artisan-avatar-sm" alt="Photo">
                                        <?php else: ?>
                                            <div class="artisan-avatar-sm" style="background: <?php echo $tradeInfo['bg']; ?>; color: <?php echo $tradeInfo['color']; ?>;">
                                                <i class="<?php echo $tradeInfo['icon']; ?>"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-bold text-dark d-flex align-items-center gap-2">
                                                <?php echo htmlspecialchars($row['full_name']); ?>
                                                <?php if ($row['is_emergency_ready']): ?>
                                                    <span class="badge bg-danger-subtle text-danger" title="Available for 24/7 Emergency Calls" style="font-size: 0.65rem;">
                                                        <i class="fa-solid fa-bolt"></i> 24/7
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="small text-secondary">
                                                <?php if (!empty($row['artisan_code'])): ?>
                                                    <span class="font-monospace fw-semibold text-primary"><?php echo htmlspecialchars($row['artisan_code']); ?></span> • 
                                                <?php endif; ?>
                                                <?php echo htmlspecialchars($row['business_name'] ?: 'Independent'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge rounded-pill px-3 py-1 mb-1" style="background: <?php echo $tradeInfo['bg']; ?>; color: <?php echo $tradeInfo['color']; ?>; font-weight: 700;">
                                        <i class="<?php echo $tradeInfo['icon']; ?> me-1"></i> <?php echo htmlspecialchars($tradeInfo['short']); ?>
                                    </span>
                                    <div class="small text-secondary" style="max-width: 220px; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;" title="<?php echo htmlspecialchars($row['specialties']); ?>">
                                        <?php echo htmlspecialchars($row['years_experience'] . ' yrs exp' . (!empty($row['specialties']) ? ' • ' . $row['specialties'] : '')); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark">
                                        <a href="tel:<?php echo htmlspecialchars($row['phone']); ?>" class="text-decoration-none text-dark">
                                            <i class="fa-solid fa-phone small text-secondary me-1"></i><?php echo htmlspecialchars($row['phone']); ?>
                                        </a>
                                    </div>
                                    <div class="small">
                                        <a href="https://wa.me/<?php echo EstateWhatsApp::formatPhoneNumber($row['phone']); ?>" target="_blank" class="text-success text-decoration-none">
                                            <i class="fa-brands fa-whatsapp"></i> Chat on WhatsApp
                                        </a>
                                    </div>
                                </td>
                                <td>
                                    <div class="small fw-semibold text-uppercase text-secondary"><?php echo htmlspecialchars($row['id_type']); ?></div>
                                    <div class="font-monospace small text-dark mb-1"><?php echo htmlspecialchars($row['id_number']); ?></div>
                                    <?php if (!empty($id_doc_url) && file_exists(__DIR__ . '/' . $id_doc_url)): ?>
                                        <?php if ($is_pdf): ?>
                                            <a href="<?php echo htmlspecialchars($id_doc_url); ?>" target="_blank" class="badge bg-secondary-subtle text-secondary text-decoration-none">
                                                <i class="fa-solid fa-file-pdf text-danger me-1"></i> View PDF ID
                                            </a>
                                        <?php else: ?>
                                            <img src="<?php echo htmlspecialchars($id_doc_url); ?>" class="id-thumbnail" alt="ID Document" onclick="viewIdModal('<?php echo htmlspecialchars($id_doc_url); ?>', '<?php echo htmlspecialchars(addslashes($row['full_name'])); ?>')">
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small fst-italic">No ID scan</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['verification_status'] === 'pending'): ?>
                                        <span class="status-badge-pending">
                                            <i class="fa-solid fa-hourglass-half"></i> Pending Vetting
                                        </span>
                                        <div class="text-muted" style="font-size: 0.7rem;">Confirmation Required</div>
                                    <?php elseif ($row['verification_status'] === 'verified'): ?>
                                        <span class="status-badge-verified">
                                            <i class="fa-solid fa-circle-check"></i> Onboarded &amp; Live
                                        </span>
                                        <div class="text-success" style="font-size: 0.7rem;">In Resident Directory</div>
                                    <?php elseif ($row['verification_status'] === 'suspended'): ?>
                                        <span class="status-badge-suspended">
                                            <i class="fa-solid fa-ban"></i> Suspended
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger">Rejected</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-1 text-warning font-bold small">
                                        <i class="fa-solid fa-star"></i> <?php echo number_format($row['average_rating'], 1); ?>
                                    </div>
                                    <div class="small text-secondary"><?php echo $row['total_jobs_completed']; ?> jobs done</div>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-secondary" onclick='openDossierModal(<?php echo json_encode($row); ?>)' title="View Full Vetting Dossier">
                                            <i class="fa-solid fa-eye text-primary"></i> Dossier
                                        </button>
                                        
                                        <?php if ($row['verification_status'] === 'pending'): ?>
                                            <button type="button" class="btn btn-success" onclick="openApproveModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['full_name'])); ?>')" title="Confirm &amp; Onboard to Directory">
                                                <i class="fa-solid fa-check"></i> Verify
                                            </button>
                                            <button type="button" class="btn btn-danger" onclick="openRejectModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['full_name'])); ?>')" title="Reject Application">
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        <?php elseif ($row['verification_status'] === 'verified'): ?>
                                            <form method="POST" action="artisans.php?tab=<?php echo urlencode($current_tab); ?>" style="display:inline;" onsubmit="return confirm('Suspend this artisan? They will be removed from the resident directory.');">
                                                <input type="hidden" name="action" value="suspend_artisan">
                                                <input type="hidden" name="artisan_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" class="btn btn-outline-danger" title="Suspend from Directory">
                                                    <i class="fa-solid fa-ban"></i>
                                                </button>
                                            </form>
                                        <?php elseif ($row['verification_status'] === 'suspended'): ?>
                                            <form method="POST" action="artisans.php?tab=<?php echo urlencode($current_tab); ?>" style="display:inline;" onsubmit="return confirm('Reactivate this artisan into the resident directory?');">
                                                <input type="hidden" name="action" value="reactivate_artisan">
                                                <input type="hidden" name="artisan_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" class="btn btn-outline-success" title="Reactivate Directory Access">
                                                    <i class="fa-solid fa-rotate-left"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <div class="text-secondary mb-2" style="font-size: 2.5rem;"><i class="fa-solid fa-screwdriver-wrench opacity-25"></i></div>
                                <h6 class="fw-bold text-dark">No Artisans Found in this Category</h6>
                                <p class="text-secondary small mb-3">Share the onboarding link with contractors or register a walk-in artisan directly.</p>
                                <a href="../artisan_register.php?ref=admin" target="_blank" class="btn btn-sm btn-primary rounded-pill px-4">+ Register Artisan Now</a>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal 1: Full Vetting Dossier Modal -->
<div class="modal fade" id="dossierModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header bg-light border-bottom">
                <h5 class="modal-title font-bold text-dark"><i class="fa-solid fa-id-card text-primary me-2"></i> Artisan Accreditation Dossier</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="dossierModalBody">
                <!-- Dynamically populated via JS -->
            </div>
            <div class="modal-footer bg-light" id="dossierModalFooter">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal 2: Approve & Verify Artisan Modal -->
<div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <form method="POST" action="artisans.php?tab=<?php echo urlencode($current_tab); ?>">
                <input type="hidden" name="action" value="approve_artisan">
                <input type="hidden" name="artisan_id" id="approve_artisan_id" value="">
                
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title font-bold"><i class="fa-solid fa-circle-check me-2"></i> Confirm &amp; Onboard Artisan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="mb-3">
                        Are you sure you want to verify and onboard <strong id="approve_artisan_name"></strong>?
                    </p>
                    <div class="alert alert-info py-2 px-3 small rounded-3 mb-3">
                        <i class="fa-solid fa-circle-info me-1"></i> <strong>What happens upon verification:</strong>
                        <ul class="mb-0 ps-3 mt-1">
                            <li>An official Estate Artisan Badge code (e.g. <code>ART-2026-XXXX</code>) is generated.</li>
                            <li>The artisan's profile is immediately published in the <strong>Resident Directory</strong>.</li>
                            <li>An automated confirmation email and WhatsApp welcome message are dispatched.</li>
                        </ul>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Verification / Inspection Notes (Optional)</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Identity and NIN verified. Referee phone check passed."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success font-bold"><i class="fa-solid fa-check me-1"></i> Approve &amp; Publish</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 3: Reject Application Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <form method="POST" action="artisans.php?tab=<?php echo urlencode($current_tab); ?>">
                <input type="hidden" name="action" value="reject_artisan">
                <input type="hidden" name="artisan_id" id="reject_artisan_id" value="">
                
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title font-bold"><i class="fa-solid fa-xmark me-2"></i> Reject Artisan Application</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="mb-3">
                        Rejecting application for <strong id="reject_artisan_name"></strong>. They will not be listed in the resident directory.
                    </p>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark">Reason for Rejection <span class="text-danger">*</span></label>
                        <textarea name="rejection_reason" class="form-control" rows="3" required placeholder="e.g. ID card scan is unreadable, please re-upload clear NIN document."></textarea>
                        <div class="form-text small">This reason will be included in the email and WhatsApp notification sent to the applicant.</div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger font-bold">Reject Application</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 4: ID Card Lightbox Preview -->
<div class="modal fade" id="idPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg bg-dark text-white">
            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title text-white-50" id="idPreviewTitle">Government Identification Document</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-3">
                <img src="" id="idPreviewImg" class="img-fluid rounded-3" style="max-height: 80vh; object-fit: contain;" alt="ID Preview">
            </div>
        </div>
    </div>
</div>

<script>
function copyRegLink() {
    const link = "<?php echo $public_reg_url; ?>";
    navigator.clipboard.writeText(link).then(() => {
        alert("Public Artisan Registration link copied to clipboard!\n\n" + link);
    }).catch(err => {
        prompt("Copy this registration link:", link);
    });
}

function openApproveModal(id, name) {
    document.getElementById('approve_artisan_id').value = id;
    document.getElementById('approve_artisan_name').textContent = name;
    new bootstrap.Modal(document.getElementById('approveModal')).show();
}

function openRejectModal(id, name) {
    document.getElementById('reject_artisan_id').value = id;
    document.getElementById('reject_artisan_name').textContent = name;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

function viewIdModal(imgUrl, name) {
    document.getElementById('idPreviewImg').src = imgUrl;
    document.getElementById('idPreviewTitle').textContent = "Government ID Scan: " + name;
    new bootstrap.Modal(document.getElementById('idPreviewModal')).show();
}

function openDossierModal(row) {
    let html = `
        <div class="row g-4">
            <div class="col-md-4 text-center border-end">
                <div style="width: 100px; height: 100px; border-radius: 50%; overflow: hidden; margin: 0 auto 1rem; background: #e2e8f0; display: flex; align-items: center; justify-content: center; font-size: 2rem;">
                    ${row.profile_photo ? `<img src="../${row.profile_photo}" style="width:100%;height:100%;object-fit:cover;">` : `<i class="fa-solid fa-user text-secondary"></i>`}
                </div>
                <h5 class="fw-bold text-dark mb-1">${row.full_name}</h5>
                <div class="badge bg-primary text-white rounded-pill px-3 py-1 mb-2">${row.trade_category.toUpperCase()}</div>
                ${row.artisan_code ? `<div class="font-monospace fw-bold text-secondary mb-2">${row.artisan_code}</div>` : ''}
                <div class="small text-secondary mb-2">${row.business_name || 'Independent Artisan'}</div>
                <div class="border-top pt-2 mt-2">
                    <span class="badge ${row.verification_status === 'verified' ? 'bg-success' : (row.verification_status === 'pending' ? 'bg-warning text-dark' : 'bg-danger')}">
                        ${row.verification_status.toUpperCase()}
                    </span>
                </div>
            </div>
            <div class="col-md-8">
                <h6 class="fw-bold text-dark mb-3 border-bottom pb-2">Verification &amp; Background Information</h6>
                <div class="row g-2 small mb-3">
                    <div class="col-sm-4 text-secondary">Phone Number:</div>
                    <div class="col-sm-8 fw-semibold text-dark"><a href="tel:${row.phone}">${row.phone}</a> ${row.alt_phone ? `(Alt: ${row.alt_phone})` : ''}</div>
                    
                    <div class="col-sm-4 text-secondary">Email Address:</div>
                    <div class="col-sm-8 text-dark">${row.email || 'None provided'}</div>
                    
                    <div class="col-sm-4 text-secondary">Address:</div>
                    <div class="col-sm-8 text-dark">${row.residential_address}</div>
                    
                    <div class="col-sm-4 text-secondary">Experience:</div>
                    <div class="col-sm-8 text-dark">${row.years_experience} Years hands-on</div>

                    <div class="col-sm-4 text-secondary">Specialties:</div>
                    <div class="col-sm-8 text-dark">${row.specialties || 'General trade repair'}</div>

                    <div class="col-sm-4 text-secondary">Base Callout Fee:</div>
                    <div class="col-sm-8 text-dark">₦${parseFloat(row.base_inspection_fee || 0).toLocaleString()}</div>

                    <div class="col-sm-4 text-secondary">Emergency 24/7:</div>
                    <div class="col-sm-8 text-dark">${row.is_emergency_ready == 1 ? '<span class="text-danger fw-bold"><i class="fa-solid fa-bolt"></i> Yes (Available for emergency night calls)</span>' : 'Standard hours only'}</div>

                    <div class="col-sm-4 text-secondary">Govt ID Type:</div>
                    <div class="col-sm-8 text-dark text-uppercase font-monospace">${row.id_type}: ${row.id_number}</div>

                    <div class="col-sm-4 text-secondary">Guarantor:</div>
                    <div class="col-sm-8 text-dark"><strong>${row.guarantor_name || 'N/A'}</strong> ${row.guarantor_phone ? `(${row.guarantor_phone})` : ''}<br><span class="text-muted">${row.guarantor_address || ''}</span></div>
                    
                    <div class="col-sm-4 text-secondary">Referred From:</div>
                    <div class="col-sm-8 text-dark text-capitalize">${row.referred_by_type} Portal ${row.referrer_name ? `(By: ${row.referrer_name})` : ''}</div>
                </div>

                ${row.id_document_path ? `
                    <div class="p-3 bg-light rounded-3 border">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small fw-bold text-dark"><i class="fa-solid fa-paperclip me-1"></i> Attached ID Card Document:</span>
                            <a href="../${row.id_document_path}" target="_blank" class="btn btn-xs btn-outline-primary small">Open in New Tab &rarr;</a>
                        </div>
                        ${row.id_document_path.endsWith('.pdf') ? `
                            <a href="../${row.id_document_path}" target="_blank" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-file-pdf"></i> View PDF Document</a>
                        ` : `
                            <img src="../${row.id_document_path}" class="img-fluid rounded border" style="max-height: 200px; cursor: pointer;" onclick="viewIdModal('../${row.id_document_path}', '${row.full_name}')">
                        `}
                    </div>
                ` : ''}

                ${row.verification_notes ? `
                    <div class="mt-3 p-2 bg-light rounded small text-secondary">
                        <strong>Admin Notes:</strong> ${row.verification_notes}
                    </div>
                ` : ''}
            </div>
        </div>
    `;

    document.getElementById('dossierModalBody').innerHTML = html;
    
    let footerHtml = `<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>`;
    if (row.verification_status === 'pending') {
        footerHtml += `
            <button type="button" class="btn btn-danger" onclick="bootstrap.Modal.getInstance(document.getElementById('dossierModal')).hide(); openRejectModal(${row.id}, '${row.full_name}')">Reject</button>
            <button type="button" class="btn btn-success font-bold" onclick="bootstrap.Modal.getInstance(document.getElementById('dossierModal')).hide(); openApproveModal(${row.id}, '${row.full_name}')"><i class="fa-solid fa-check me-1"></i> Verify &amp; Onboard</button>
        `;
    }
    document.getElementById('dossierModalFooter').innerHTML = footerHtml;

    new bootstrap.Modal(document.getElementById('dossierModal')).show();
}
</script>

<?php include '../includes/footer.php'; ?>
