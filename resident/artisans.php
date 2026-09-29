<?php
// resident/artisans.php - Verified Estate Artisan Directory & Service Booking Hub
require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/ArtisanHelper.php';
require_once '../includes/Mailer.php';
require_once '../includes/WhatsApp.php';

requireLogin();

$user_id = intval($_SESSION['user_id']);
$estate_id = get_estate_id();
$user_name = $_SESSION['name'] ?? 'Resident';

// Handle Direct Artisan Booking / Maintenance Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_artisan') {
    $artisan_id = intval($_POST['artisan_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = trim($_POST['priority'] ?? 'medium');
    $scheduled_date = !empty($_POST['scheduled_date']) ? $_POST['scheduled_date'] : null;
    $flat_id = !empty($_POST['flat_id']) ? intval($_POST['flat_id']) : null;

    if ($artisan_id > 0 && !empty($title)) {
        // Fetch artisan info
        $art_chk = $conn->query("SELECT * FROM artisans WHERE id = $artisan_id AND estate_id = $estate_id AND verification_status = 'verified' LIMIT 1");
        if ($art_chk && $art_chk->num_rows > 0) {
            $artisan = $art_chk->fetch_assoc();
            $artisan_name = $artisan['full_name'];
            $service_type = $artisan['trade_category'];

            $esc_title = $conn->real_escape_string($title);
            $esc_desc = $conn->real_escape_string($description);
            $esc_prio = in_array($priority, ['low', 'medium', 'high', 'emergency']) ? $priority : 'medium';
            $esc_sched = $scheduled_date ? "'" . $conn->real_escape_string($scheduled_date) . "'" : "NULL";
            $esc_flat = $flat_id ? "'$flat_id'" : "NULL";

            $sql = "INSERT INTO maintenance_requests 
                    (estate_id, user_id, flat_id, title, description, priority, status, artisan_id, service_type, scheduled_date, created_at) 
                    VALUES ($estate_id, $user_id, $esc_flat, '$esc_title', '$esc_desc', '$esc_prio', 'open', $artisan_id, '$service_type', $esc_sched, NOW())";
            
            if ($conn->query($sql)) {
                $ticket_id = $conn->insert_id;
                
                // 1. Notify Central Admin
                $n_title = $conn->real_escape_string("🛠️ New Maintenance Booking: #MR-" . sprintf("%04d", $ticket_id));
                $n_msg = $conn->real_escape_string("Resident $user_name requested verified artisan $artisan_name for '$esc_title'.");
                $admin_users = $conn->query("SELECT id FROM users WHERE role IN ('admin', 'superadmin') AND (estate_id = $estate_id OR role = 'superadmin')");
                if ($admin_users) {
                    while ($adm = $admin_users->fetch_assoc()) {
                        $aid = intval($adm['id']);
                        $conn->query("INSERT INTO notifications (estate_id, user_id, title, message, type, reference_id, is_read, created_at)
                                     VALUES ($estate_id, $aid, '$n_title', '$n_msg', 'maintenance', $ticket_id, 0, NOW())");
                    }
                }

                // 2. Generate Official Artisan Gate Clearance Pass
                $rand_code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
                $artisan_pass_code = 'ART-' . $rand_code;
                $name_parts = explode(' ', trim($artisan_name));
                $art_first = $conn->real_escape_string($artisan['first_name'] ?? ($name_parts[0] ?? 'Artisan'));
                $art_last = $conn->real_escape_string($artisan['last_name'] ?? ($name_parts[1] ?? ''));
                $art_phone = $conn->real_escape_string($artisan['phone'] ?? '');
                $art_email = !empty($artisan['email']) ? "'" . $conn->real_escape_string($artisan['email']) . "'" : "NULL";
                $art_purpose = $conn->real_escape_string("Artisan Service: $title ($service_type)");
                $expected_arr = $scheduled_date ? "'" . $conn->real_escape_string($scheduled_date) . "'" : "NOW()";

                $pass_sql = "INSERT INTO visitors (estate_id, resident_id, flat_id, first_name, last_name, name, phone, email, purpose, visitor_code, expected_arrival, status)
                             VALUES ($estate_id, $user_id, $esc_flat, '$art_first', '$art_last', '" . $conn->real_escape_string($artisan_name) . "', '$art_phone', $art_email, '$art_purpose', '$artisan_pass_code', $expected_arr, 'pre_registered')";
                
                if ($conn->query($pass_sql)) {
                    $pass_id = $conn->insert_id;
                    // Dispatch Official Gate Pass to Artisan & Host via WhatsApp and Email
                    EstateMailer::sendArtisanPassEmail($conn, $pass_id);
                    EstateWhatsApp::sendArtisanPassWhatsApp($conn, $pass_id);
                }

                if (function_exists('logAudit')) {
                    logAudit($conn, "Artisan Booked", "Maintenance", "Resident $user_name booked artisan $artisan_name (Pass Code: $artisan_pass_code) for Ticket #$ticket_id ($title)");
                }

                $_SESSION['success_msg'] = "Service booking confirmed! Gate Clearance Pass ($artisan_pass_code) has been dispatched directly to $artisan_name via WhatsApp and Email.";
            } else {
                $_SESSION['error_msg'] = "Database error creating booking: " . $conn->error;
            }
        } else {
            $_SESSION['error_msg'] = "Selected artisan is not currently verified or available.";
        }
    } else {
        $_SESSION['error_msg'] = "Please provide an issue title and description.";
    }
    header("Location: artisans.php" . (!empty($_GET['trade']) ? '?trade=' . urlencode($_GET['trade']) : ''));
    exit;
}

// Fetch Resident's Flat Details
$user_flat = $conn->query("SELECT f.id, f.number, b.name as building_name 
                           FROM residents r
                           JOIN flats f ON r.flat_id = f.id
                           JOIN buildings b ON f.building_id = b.id
                           WHERE r.user_id = $user_id AND r.estate_id = $estate_id 
                           LIMIT 1")->fetch_assoc();

// Messages
$success = $_SESSION['success_msg'] ?? '';
$error = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// Filters
$active_trade = trim($_GET['trade'] ?? 'all');
$search_q = trim($_GET['q'] ?? '');
$emergency_only = isset($_GET['emergency']) && $_GET['emergency'] === '1';

// Build Query - STRICT SECURITY: Only verified status is displayed!
$where_clauses = [
    "a.estate_id = $estate_id",
    "a.verification_status = 'verified'"
];

if ($active_trade !== 'all' && !empty($active_trade)) {
    $esc_t = $conn->real_escape_string($active_trade);
    $where_clauses[] = "a.trade_category = '$esc_t'";
}

if ($emergency_only) {
    $where_clauses[] = "a.is_emergency_ready = 1";
}

if (!empty($search_q)) {
    $esc_q = $conn->real_escape_string($search_q);
    $where_clauses[] = "(a.full_name LIKE '%$esc_q%' OR a.business_name LIKE '%$esc_q%' OR a.specialties LIKE '%$esc_q%' OR a.trade_category LIKE '%$esc_q%')";
}

$where_sql = implode(' AND ', $where_clauses);
$artisans_res = $conn->query("SELECT a.* FROM artisans a WHERE $where_sql ORDER BY a.is_emergency_ready DESC, a.average_rating DESC, a.total_jobs_completed DESC");

$trades = ArtisanHelper::getTradeCategories();
$public_reg_url = EstateMailer::getBaseUrl() . 'artisan_register.php?ref=resident&referrer_id=' . $user_id;

include 'header.php';
include 'sidebar.php';
?>

<style>
    .trade-pill-scroll {
        display: flex;
        gap: 0.5rem;
        overflow-x: auto;
        padding-bottom: 0.5rem;
        margin-bottom: 1.5rem;
        scrollbar-width: thin;
    }
    .trade-pill-btn {
        padding: 0.55rem 1.15rem;
        border-radius: 9999px;
        font-weight: 600;
        font-size: 0.85rem;
        border: 1px solid #e2e8f0;
        background: #ffffff;
        color: #475569;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        white-space: nowrap;
        transition: all 0.15s ease-in-out;
        box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    }
    .trade-pill-btn:hover {
        background: #f8fafc;
        border-color: #cbd5e1;
        color: #1e293b;
        transform: translateY(-1px);
    }
    .trade-pill-btn.active {
        background: #2563eb;
        border-color: #2563eb;
        color: #ffffff;
        box-shadow: 0 4px 10px rgba(37, 99, 235, 0.25);
    }
    .artisan-card {
        background: #ffffff;
        border-radius: 1rem;
        border: 1px solid #e2e8f0;
        padding: 1.5rem;
        transition: all 0.2s ease-in-out;
        box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    .artisan-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 24px -6px rgba(15, 23, 42, 0.08);
        border-color: #cbd5e1;
    }
    .artisan-card-avatar {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        object-fit: cover;
        background: #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.75rem;
        flex-shrink: 0;
        box-shadow: 0 2px 4px rgba(0,0,0,0.06);
    }
    .verified-estate-badge {
        background: #dcfce7;
        color: #166534;
        font-weight: 700;
        font-size: 0.72rem;
        padding: 3px 8px;
        border-radius: 9999px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .emergency-badge {
        background: #fee2e2;
        color: #b91c1c;
        font-weight: 700;
        font-size: 0.72rem;
        padding: 3px 8px;
        border-radius: 9999px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .callout-fee-badge {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        color: #334155;
        font-size: 0.78rem;
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 6px;
    }
    .action-btn-call {
        background: #f1f5f9;
        color: #334155;
        border: 1px solid #e2e8f0;
        padding: 0.55rem 0.85rem;
        border-radius: 0.5rem;
        font-weight: 600;
        font-size: 0.85rem;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        transition: all 0.15s;
    }
    .action-btn-call:hover {
        background: #e2e8f0;
        color: #0f172a;
    }
    .action-btn-wa {
        background: #25d366;
        color: #ffffff;
        padding: 0.55rem 0.85rem;
        border-radius: 0.5rem;
        font-weight: 600;
        font-size: 0.85rem;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        transition: all 0.15s;
    }
    .action-btn-wa:hover {
        background: #1eb855;
        color: #ffffff;
    }
    .action-btn-book {
        background: #2563eb;
        color: #ffffff;
        padding: 0.55rem 1rem;
        border-radius: 0.5rem;
        font-weight: 700;
        font-size: 0.85rem;
        border: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        box-shadow: 0 2px 6px rgba(37,99,235,0.3);
        transition: all 0.15s;
    }
    .action-btn-book:hover {
        background: #1d4ed8;
        color: #ffffff;
        transform: translateY(-1px);
    }
</style>

<div class="container-fluid px-3 px-md-4 py-3">

    <!-- Header Section -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                    <i class="fa-solid fa-screwdriver-wrench"></i> Facility &amp; Handyman Directory
                </span>
                <span class="text-secondary small">• Central Admin Accredited</span>
            </div>
            <h1 class="h4 font-bold text-slate-800 m-0">Verified Estate Artisans &amp; Services</h1>
            <p class="text-secondary small mb-0">Browse background-vetted handymen, inspect customer ratings, contact via WhatsApp, or book a service ticket.</p>
        </div>

        <div class="d-flex align-items-center gap-2">
            <!-- Share Onboarding Link / Recommend Handyman -->
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="copyResidentInviteLink()" title="Recommend or Invite an Artisan">
                <i class="fa-solid fa-user-plus text-primary me-1"></i> Recommend Handyman
            </button>
            <a href="report_issue" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                <i class="fa-solid fa-ticket me-1"></i> My Maintenance Tickets
            </a>
        </div>
    </div>

    <!-- Flash Alerts -->
    <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm d-flex align-items-center gap-2 mb-4" role="alert">
            <i class="fa-solid fa-circle-check fs-5 text-success"></i>
            <div><?php echo htmlspecialchars($success); ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm d-flex align-items-center gap-2 mb-4" role="alert">
            <i class="fa-solid fa-circle-exclamation fs-5 text-danger"></i>
            <div><?php echo htmlspecialchars($error); ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Search & Filter Bar (Matching Reference Image 1 & 2) -->
    <form method="GET" action="artisans.php" class="artisan-search-hero-bar">
        <div class="artisan-search-inner-input">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="q" placeholder="Search by name, skill (e.g. inverter, leaking pipe, POP, tiles)..." value="<?php echo htmlspecialchars($search_q); ?>">
        </div>
        <div class="artisan-search-toggle-wrap">
            <div class="form-check form-switch m-0 p-0 d-flex align-items-center gap-2">
                <input class="form-check-input ms-0" type="checkbox" name="emergency" id="emergencyFilter" value="1" <?php echo $emergency_only ? 'checked' : ''; ?> onchange="this.form.submit()" style="cursor: pointer; width: 2.25em; height: 1.15em;">
                <label class="artisan-search-toggle-label" for="emergencyFilter">
                    <i class="fa-solid fa-bolt"></i> 24/7 Emergency Ready Only
                </label>
            </div>
        </div>
        <button type="submit" class="artisan-search-submit-btn">Search</button>
        <?php if (!empty($search_q) || $emergency_only || $active_trade !== 'all'): ?>
            <a href="artisans.php" class="btn btn-outline-secondary rounded-pill px-3 py-2" title="Reset Filters"><i class="fa-solid fa-rotate-left"></i></a>
        <?php endif; ?>
        <input type="hidden" name="trade" value="<?php echo htmlspecialchars($active_trade); ?>">
    </form>

    <!-- Category Pills -->
    <div class="trade-pill-scroll">
        <a href="artisans.php?trade=all<?php echo $emergency_only ? '&emergency=1' : ''; ?>" class="trade-pill-btn <?php echo ($active_trade === 'all') ? 'active' : ''; ?>">
            <i class="fa-solid fa-border-all"></i> All Categories
        </a>
        <?php foreach ($trades as $key => $trade): ?>
            <a href="artisans.php?trade=<?php echo $key; ?><?php echo $emergency_only ? '&emergency=1' : ''; ?>" class="trade-pill-btn <?php echo ($active_trade === $key) ? 'active' : ''; ?>">
                <i class="<?php echo $trade['icon']; ?>"></i> <?php echo htmlspecialchars($trade['short']); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Artisans Grid -->
    <div class="row g-3">
        <?php if ($artisans_res && $artisans_res->num_rows > 0): ?>
            <?php while ($row = $artisans_res->fetch_assoc()): 
                $tradeInfo = ArtisanHelper::getTradeInfo($row['trade_category']);
                $avatar_url = !empty($row['profile_photo']) ? '../' . ltrim($row['profile_photo'], '/') : '';
                $clean_phone = EstateWhatsApp::formatPhoneNumber($row['phone']);
            ?>
                <div class="col-md-6 col-xl-4">
                    <div class="artisan-card">
                        
                        <!-- Top Header with Avatar & Details -->
                        <div class="d-flex align-items-start gap-3 mb-3">
                            <?php if (!empty($avatar_url) && file_exists(__DIR__ . '/' . $avatar_url)): ?>
                                <img src="<?php echo htmlspecialchars($avatar_url); ?>" class="artisan-card-avatar" alt="Photo">
                            <?php else: ?>
                                <div class="artisan-card-avatar" style="background: <?php echo $tradeInfo['bg']; ?>; color: <?php echo $tradeInfo['color']; ?>;">
                                    <i class="<?php echo $tradeInfo['icon']; ?>"></i>
                                </div>
                            <?php endif; ?>

                            <div class="flex-grow-1 overflow-hidden">
                                <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
                                    <span class="verified-estate-badge">
                                        <i class="fa-solid fa-certificate"></i> Verified
                                    </span>
                                    <?php if ($row['is_emergency_ready']): ?>
                                        <span class="emergency-badge" title="Available for 24/7 Emergency Service">
                                            <i class="fa-solid fa-bolt"></i> 24/7 Callout
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <h5 class="fw-bold text-dark mb-0 text-truncate" title="<?php echo htmlspecialchars($row['full_name']); ?>">
                                    <?php echo htmlspecialchars($row['full_name']); ?>
                                </h5>
                                <div class="small text-secondary text-truncate">
                                    <?php echo htmlspecialchars($row['business_name'] ?: $tradeInfo['label']); ?>
                                </div>
                            </div>
                        </div>

                        <!-- Trade & Rating Row -->
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                            <div>
                                <span class="badge rounded-pill px-2.5 py-1" style="background: <?php echo $tradeInfo['bg']; ?>; color: <?php echo $tradeInfo['color']; ?>; font-weight: 700; font-size: 0.75rem;">
                                    <i class="<?php echo $tradeInfo['icon']; ?> me-1"></i> <?php echo htmlspecialchars($tradeInfo['short']); ?>
                                </span>
                                <span class="text-secondary small ms-1"><?php echo $row['years_experience']; ?> yrs exp</span>
                            </div>
                            <div class="d-flex align-items-center gap-1 font-bold text-warning small">
                                <i class="fa-solid fa-star"></i> <?php echo number_format($row['average_rating'], 1); ?>
                                <span class="text-secondary fw-normal">(<?php echo $row['total_jobs_completed']; ?> jobs)</span>
                            </div>
                        </div>

                        <!-- Specialties / Bio -->
                        <div class="small text-secondary mb-3 flex-grow-1" style="line-height: 1.5;">
                            <?php if (!empty($row['specialties'])): ?>
                                <span class="fw-semibold text-dark">Specialties:</span> <?php echo htmlspecialchars($row['specialties']); ?>
                            <?php else: ?>
                                General <?php echo strtolower($tradeInfo['label']); ?> and estate property maintenance.
                            <?php endif; ?>
                        </div>

                        <!-- Callout Fee & Badge Code -->
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="callout-fee-badge">
                                <i class="fa-solid fa-tag text-secondary me-1"></i>
                                Base Callout: <strong>₦<?php echo number_format($row['base_inspection_fee'], 0); ?></strong>
                            </span>
                            <span class="font-monospace small text-muted">
                                <?php echo htmlspecialchars($row['artisan_code']); ?>
                            </span>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex gap-2 pt-2 border-top">
                            <a href="tel:<?php echo htmlspecialchars($row['phone']); ?>" class="action-btn-call flex-fill" title="Direct Phone Call">
                                <i class="fa-solid fa-phone"></i> Call
                            </a>
                            <a href="https://wa.me/<?php echo $clean_phone; ?>?text=<?php echo urlencode("Hello " . $row['full_name'] . ", I found your profile on the Main Estate Resident Portal and would like to inquire about a service."); ?>" target="_blank" class="action-btn-wa flex-fill" title="Chat on WhatsApp">
                                <i class="fa-brands fa-whatsapp fs-6"></i> WhatsApp
                            </a>
                            <button type="button" class="action-btn-book flex-fill" onclick='openBookModal(<?php echo json_encode($row); ?>)' title="Submit Maintenance Ticket">
                                <i class="fa-solid fa-calendar-check"></i> Book
                            </button>
                        </div>

                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="col-12 text-center py-5">
                <div class="card border-0 shadow-sm rounded-4 p-5">
                    <div class="text-secondary mb-3" style="font-size: 3rem;"><i class="fa-solid fa-screwdriver-wrench opacity-25"></i></div>
                    <h5 class="fw-bold text-dark">No Verified Artisans in this Category Yet</h5>
                    <p class="text-secondary small mb-4" style="max-width: 480px; margin: 0 auto;">
                        Do you know a reliable plumber, electrician, or handyman? Send them our accreditation link so they can register and get vetted by Central Admin!
                    </p>
                    <div>
                        <button type="button" class="btn btn-primary rounded-pill px-4" onclick="copyResidentInviteLink()">
                            <i class="fa-solid fa-share-nodes me-2"></i> Share Artisan Accreditation Link
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- Booking / Service Request Modal -->
<div class="modal fade" id="bookModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <form method="POST" action="artisans.php">
                <input type="hidden" name="action" value="book_artisan">
                <input type="hidden" name="artisan_id" id="book_artisan_id" value="">
                
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-bold"><i class="fa-solid fa-screwdriver-wrench me-2"></i> Book Maintenance Service</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body p-4">
                    <!-- Artisan Pill Header -->
                    <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3 border mb-3">
                        <div id="book_avatar_ph" class="rounded-circle d-flex align-items-center justify-content-center bg-primary text-white fw-bold" style="width: 44px; height: 44px; font-size: 1.1rem;">
                            <i class="fa-solid fa-user"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark" id="book_artisan_name">Artisan Name</div>
                            <div class="small text-secondary" id="book_trade_label">Plumbing Services</div>
                        </div>
                        <span class="badge bg-success text-white rounded-pill ms-auto">✓ Verified</span>
                    </div>

                    <!-- Flat / Property Unit -->
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark">Serviced Residence / Flat Unit <span class="text-danger">*</span></label>
                        <?php if ($user_flat): ?>
                            <input type="hidden" name="flat_id" value="<?php echo $user_flat['id']; ?>">
                            <input type="text" class="form-control bg-light" readonly value="<?php echo htmlspecialchars(($user_flat['building_name'] ? $user_flat['building_name'] . ' • ' : '') . 'Unit ' . $user_flat['number']); ?>">
                        <?php else: ?>
                            <input type="text" class="form-control" name="custom_location" placeholder="e.g. Block C, Flat 12" required>
                        <?php endif; ?>
                    </div>

                    <!-- Issue Title -->
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark">Issue Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Leaking kitchen tap, Power tripping" required>
                    </div>

                    <!-- Issue Description -->
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark">Issue Description &amp; Details <span class="text-danger">*</span></label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Describe the fault or service needed..." required></textarea>
                    </div>

                    <!-- Urgency & Scheduled Date -->
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-dark">Urgency Level</label>
                            <select name="priority" class="form-select">
                                <option value="low">Low (Flexible)</option>
                                <option value="medium" selected>Medium (Standard)</option>
                                <option value="high">High (Prompt)</option>
                                <option value="emergency">Emergency (Immediate)</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-dark">Preferred Date &amp; Time</label>
                            <input type="datetime-local" name="scheduled_date" class="form-control">
                        </div>
                    </div>

                    <div class="alert alert-light border small text-secondary mb-0">
                        <i class="fa-solid fa-shield-halved text-primary me-1"></i>
                        Central Administration and Estate Gate Security are automatically synchronized when you book a verified artisan.
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-bold">
                        <i class="fa-solid fa-paper-plane me-1"></i> Submit Service Ticket
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function copyResidentInviteLink() {
    const link = "<?php echo $public_reg_url; ?>";
    navigator.clipboard.writeText(link).then(() => {
        alert("Artisan Accreditation link copied!\n\nSend this link to handymen or artisans so they can register and get verified by Central Admin:\n" + link);
    }).catch(err => {
        prompt("Copy this accreditation registration link to share:", link);
    });
}

function openBookModal(row) {
    document.getElementById('book_artisan_id').value = row.id;
    document.getElementById('book_artisan_name').textContent = row.full_name;
    document.getElementById('book_trade_label').textContent = (row.business_name ? row.business_name + ' • ' : '') + row.trade_category.toUpperCase();
    new bootstrap.Modal(document.getElementById('bookModal')).show();
}
</script>

<?php include 'footer.php'; ?>
