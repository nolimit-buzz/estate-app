<?php
// staff/artisans.php - Staff & Security Verified Artisan Directory & Gate Clearance Hub
require_once '../config.php';
require_once '../includes/auth_guard.php';
require_once '../includes/ArtisanHelper.php';

requireLogin();

$user_id = intval($_SESSION['user_id']);
$estate_id = get_estate_id();
$user_name = $_SESSION['name'] ?? 'Staff';

// Filters
$active_trade = trim($_GET['trade'] ?? 'all');
$search_q = trim($_GET['q'] ?? '');
$emergency_only = isset($_GET['emergency']) && $_GET['emergency'] === '1';

// Build Query
$where_clauses = [
    "estate_id = $estate_id",
    "verification_status = 'verified'"
];

if (!empty($search_q)) {
    $esc_q = $conn->real_escape_string($search_q);
    $where_clauses[] = "(full_name LIKE '%$esc_q%' OR phone LIKE '%$esc_q%' OR specialties LIKE '%$esc_q%' OR artisan_code LIKE '%$esc_q%' OR business_name LIKE '%$esc_q%')";
}

if ($active_trade !== 'all') {
    $esc_trade = $conn->real_escape_string($active_trade);
    $where_clauses[] = "trade_category = '$esc_trade'";
}

if ($emergency_only) {
    $where_clauses[] = "is_emergency_ready = 1";
}

$where_sql = implode(' AND ', $where_clauses);
$artisans_res = $conn->query("SELECT * FROM artisans WHERE $where_sql ORDER BY is_emergency_ready DESC, full_name ASC");

$trades = ArtisanHelper::getTradeCategories();

$branding = get_estate_branding($conn);
$estate_name = $branding['estate_name'] ?? 'Main Estate';

include 'header.php';
include 'sidebar.php';
?>

<div class="content-wrapper">
    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
        <div>
            <h1 class="h3 font-bold text-slate-900 mb-1" style="font-family: 'Outfit', sans-serif;">Verified Artisan Directory</h1>
            <p class="text-secondary small mb-0">Accredited estate handymen, emergency technicians, and vetted service providers.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="security" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-qrcode me-1 text-primary"></i> Gate Passes
            </a>
            <a href="maintenance" class="btn btn-sm btn-primary">
                <i class="fa-solid fa-hammer me-1"></i> Work Orders
            </a>
        </div>
    </div>

    <!-- Search & Filter Bar (Matching Reference Image 1 & 2) -->
    <form method="GET" action="artisans.php" class="artisan-search-hero-bar">
        <div class="artisan-search-inner-input">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="q" placeholder="Search by name, skill (e.g. inverter, pipe, POP, tiles) or code..." value="<?php echo htmlspecialchars($search_q); ?>">
        </div>
        <div class="artisan-search-toggle-wrap">
            <div class="form-check form-switch m-0 p-0 d-flex align-items-center gap-2">
                <input class="form-check-input ms-0" type="checkbox" name="emergency" id="staffEmergencyFilter" value="1" <?php echo $emergency_only ? 'checked' : ''; ?> onchange="this.form.submit()" style="cursor: pointer; width: 2.25em; height: 1.15em;">
                <label class="artisan-search-toggle-label" for="staffEmergencyFilter">
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
    <div class="d-flex gap-2 overflow-x-auto pb-3 mb-4" style="scrollbar-width: thin;">
        <a href="artisans.php?trade=all<?php echo $emergency_only ? '&emergency=1' : ''; ?>" class="btn btn-sm <?php echo ($active_trade === 'all') ? 'btn-primary' : 'btn-outline-secondary'; ?> text-nowrap rounded-pill px-3">
            All Categories
        </a>
        <?php foreach ($trades as $key => $trade): ?>
            <a href="artisans.php?trade=<?php echo $key; ?><?php echo $emergency_only ? '&emergency=1' : ''; ?>" class="btn btn-sm <?php echo ($active_trade === $key) ? 'btn-primary' : 'btn-outline-secondary'; ?> text-nowrap rounded-pill px-3">
                <i class="<?php echo $trade['icon']; ?> me-1"></i> <?php echo htmlspecialchars($trade['short']); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Artisan Grid -->
    <?php if ($artisans_res && $artisans_res->num_rows > 0): ?>
        <div class="row g-3">
            <?php while ($art = $artisans_res->fetch_assoc()): 
                $tradeInfo = ArtisanHelper::getTradeInfo($art['trade_category']);
                $photo = $art['profile_photo'] ? ('../' . ltrim($art['profile_photo'], './')) : '';
            ?>
                <div class="col-md-6 col-xl-4">
                    <div class="glass-card p-4 h-100 d-flex flex-direction-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="mature-badge mature-badge-primary">
                                    <i class="<?php echo $tradeInfo['icon']; ?> me-1"></i> <?php echo htmlspecialchars($tradeInfo['label']); ?>
                                </span>
                                <?php if ($art['is_emergency_ready']): ?>
                                    <span class="mature-badge mature-badge-rose">
                                        <i class="fa-solid fa-bolt me-1"></i> 24/7 Emergency
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="d-flex align-items-center gap-3 mb-3">
                                <?php if (!empty($photo) && file_exists(__DIR__ . '/' . $photo)): ?>
                                    <img src="<?php echo htmlspecialchars($photo); ?>" alt="Artisan" style="width: 52px; height: 52px; border-radius: 50%; object-fit: cover; border: 1.5px solid #e2e8f0;">
                                <?php else: ?>
                                    <div style="width: 52px; height: 52px; border-radius: 50%; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; font-weight: 700;">
                                        <?php echo strtoupper(substr($art['full_name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="min-w-0">
                                    <h3 class="h6 font-bold text-slate-900 mb-0 text-truncate"><?php echo htmlspecialchars($art['full_name']); ?></h3>
                                    <div class="small text-muted font-monospace"><?php echo htmlspecialchars($art['artisan_code'] ?: 'VERIFIED'); ?></div>
                                    <div class="small text-secondary"><?php echo intval($art['years_experience']); ?> Yrs Exp</div>
                                </div>
                            </div>

                            <?php if (!empty($art['specialties'])): ?>
                                <p class="small text-secondary mb-3 text-truncate-neat">
                                    <strong>Skills:</strong> <?php echo htmlspecialchars($art['specialties']); ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex gap-2 pt-3 border-top" style="border-color: rgba(226, 232, 240, 0.7) !important;">
                            <a href="tel:<?php echo htmlspecialchars($art['phone']); ?>" class="btn btn-sm btn-outline-secondary flex-fill text-center">
                                <i class="fa-solid fa-phone me-1 text-primary"></i> Call
                            </a>
                            <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $art['phone']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary flex-fill text-center">
                                <i class="fa-brands fa-whatsapp me-1 text-success"></i> Chat
                            </a>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="glass-panel text-center p-5">
            <div style="width: 60px; height: 60px; border-radius: 50%; background: rgba(148, 163, 184, 0.12); color: #64748b; display: inline-flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 1rem;">
                <i class="fa-solid fa-wrench"></i>
            </div>
            <h3 class="h5 font-bold text-slate-900 mb-1">No Verified Artisans Found</h3>
            <p class="text-secondary small mb-3">No accredited artisans matched your search criteria.</p>
            <a href="artisans.php" class="btn btn-sm btn-outline-secondary">Reset Filters</a>
        </div>
    <?php endif; ?>
</div>

</main>
</div>
</body>
</html>
