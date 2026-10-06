<?php
// index.php - Public Estate Landing Page & Community Website
require_once 'config.php';
require_once 'includes/auth_guard.php';

// If user is already logged in, provide quick dashboard redirection or let them proceed
$logged_in = isset($_SESSION['user_id']);
$user_role = $_SESSION['role'] ?? 'resident';
$user_name = $_SESSION['name'] ?? '';

// Fetch Estate Brand & System Details
$estate_id = get_estate_id();
$sys_res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE estate_id = $estate_id");
$sys = [];
if ($sys_res) {
    while ($row = $sys_res->fetch_assoc()) {
        $sys[$row['setting_key']] = $row['setting_value'];
    }
}

$estate_name = $sys['estate_name'] ?? 'EstateAdmin';
$estate_motto = $sys['estate_motto'] ?? 'Excellence in Community Living';
$estate_location = $sys['estate_location'] ?? 'Prime Residential District';
$estate_logo = $sys['estate_logo'] ?? '';
$estate_rules = $sys['estate_rules'] ?? '';
$estate_conduct = $sys['estate_code_of_conduct'] ?? '';
$office_phone = $sys['office_phone'] ?? 'Contact Office';
$office_email = $sys['office_email'] ?? 'admin@estate.com';
$theme_color = $sys['theme_color'] ?? '#3b82f6';

// Fetch Active Central Policies for Public Showcase
$public_policies = [];
$pol_q = $conn->query("
    SELECT p.*, c.name as category_name, c.icon as category_icon, c.color as category_color 
    FROM estate_policies p
    LEFT JOIN estate_policy_categories c ON p.category_slug = c.slug AND c.estate_id = p.estate_id
    WHERE p.estate_id = $estate_id AND p.scope = 'central' AND p.status = 'active'
    ORDER BY p.display_order ASC, p.id ASC LIMIT 6
");
if ($pol_q) {
    while ($pr = $pol_q->fetch_assoc()) {
        $public_policies[] = $pr;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($estate_name); ?> - Modern Community Living & Estate Portal</title>
    
    <!-- Google Fonts: Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Core Design & Mobile CSS -->
    <link rel="stylesheet" href="css/estate_notifications.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/mobile_app.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/landing.css?v=<?php echo time(); ?>">
    <script src="js/estate_notifications.js?v=<?php echo time(); ?>"></script>
    <script src="js/mobile_app.js?v=<?php echo time(); ?>" defer></script>
    
    <style>
        :root {
            --brand-primary: <?php echo $theme_color; ?>;
        }
    </style>
</head>
<body class="landing-page">

    <!-- Navigation Bar -->
    <header class="landing-nav">
        <div class="nav-container">
            <a href="index" class="nav-brand">
                <?php if ($estate_logo): ?>
                    <img src="<?php echo htmlspecialchars($estate_logo); ?>" alt="Logo" style="height: 36px; border-radius: 6px;">
                <?php else: ?>
                    <i class="fa-solid fa-building-user"></i>
                <?php endif; ?>
                <span><?php echo htmlspecialchars($estate_name); ?></span>
            </a>

            <ul class="nav-links">
                <li><a href="#about">About</a></li>
                <li><a href="#selling-points"><i class="fa-solid fa-gem text-info me-1"></i> Advantages</a></li>
                <li><a href="#comparison">Compare</a></li>
                <li><a href="#portals">Portals</a></li>
                <li><a href="artisan_register.php"><i class="fa-solid fa-wrench me-1 text-primary"></i> Artisan Network</a></li>
                <li><a href="#features">Amenities</a></li>
                <?php if ($estate_rules || $estate_conduct): ?>
                    <li><a href="#governance">Governance</a></li>
                <?php endif; ?>
                <li><a href="#contact">Contact</a></li>
            </ul>

            <div class="d-flex align-items-center gap-3">
                <?php if ($logged_in): ?>
                    <a href="<?php 
                        if ($user_role === 'superadmin') echo 'superadmin/index';
                        elseif (in_array($user_role, ['admin', 'manager'])) echo 'admin/index';
                        elseif ($user_role === 'zone_admin') echo 'zone/index';
                        elseif ($user_role === 'resident') echo 'resident/index';
                        else echo 'staff/index';
                    ?>" class="nav-cta">
                        <i class="fa-solid fa-gauge"></i> My Dashboard
                    </a>
                <?php else: ?>
                    <a href="#portals" class="nav-cta">
                        <i class="fa-solid fa-arrow-right-to-bracket"></i> Sign In
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Hero Section with Luxury Estate Image Blend -->
    <section class="hero-section" id="about">
        <!-- Mature Executive Mobile Hero Showcase -->
        <div class="mobile-only w-100 mobile-hero-wrapper">
            <div class="mobile-hero-showcase">
                <!-- Status & Brand Indicator -->
                <div class="mobile-hero-badge">
                    <span class="live-pulse-dot"></span>
                    <i class="fa-solid fa-shield-halved"></i>
                    <span>Official Estate Portal &bull; Gated Community</span>
                </div>

                <!-- Mature Main Title -->
                <h1 class="mobile-hero-title">
                    Smart, Secure &amp; Connected Community Living
                </h1>

                <!-- Mature Subtitle (No mention of rent!) -->
                <p class="mobile-hero-subtitle">
                    Welcome to <strong><?php echo htmlspecialchars($estate_name); ?></strong>. <?php echo htmlspecialchars($estate_motto); ?>. Enjoy seamless digital gate access, automated service assessments, instant maintenance dispatch, and unified neighborhood governance.
                </p>

                <!-- Primary & Secondary Action CTAs -->
                <div class="mobile-hero-actions">
                    <a href="#portals" class="mobile-btn-primary">
                        <span>Access Portals</span>
                        <i class="fa-solid fa-arrow-right"></i>
                    </a>
                    <a href="#features" class="mobile-btn-glass">
                        <i class="fa-solid fa-compass"></i>
                        <span>Explore Amenities</span>
                    </a>
                </div>

                <!-- Executive Mobile Micro-Stats Strip (2x2 Grid) -->
                <div class="mobile-hero-stats">
                    <div class="mobile-stat-chip">
                        <div class="stat-chip-icon stat-icon-emerald"><i class="fa-solid fa-shield-halved"></i></div>
                        <div class="stat-chip-text">
                            <strong>24/7</strong>
                            <span>Gate Security</span>
                        </div>
                    </div>
                    <div class="mobile-stat-chip">
                        <div class="stat-chip-icon stat-icon-sky"><i class="fa-solid fa-qrcode"></i></div>
                        <div class="stat-chip-text">
                            <strong>100%</strong>
                            <span>Digital Passes</span>
                        </div>
                    </div>
                    <div class="mobile-stat-chip">
                        <div class="stat-chip-icon stat-icon-indigo"><i class="fa-solid fa-receipt"></i></div>
                        <div class="stat-chip-text">
                            <strong>Instant</strong>
                            <span>Assessments</span>
                        </div>
                    </div>
                    <div class="mobile-stat-chip">
                        <div class="stat-chip-icon stat-icon-amber"><i class="fa-solid fa-bolt"></i></div>
                        <div class="stat-chip-text">
                            <strong>Rapid</strong>
                            <span>Maintenance</span>
                        </div>
                    </div>
                </div>

                <!-- Instant Direct Portal Launcher for Mobile Users -->
                <div class="mobile-portal-launcher">
                    <div class="launcher-header">
                        <i class="fa-solid fa-arrow-right-to-bracket text-primary"></i>
                        <span>Direct Portal Access</span>
                    </div>
                    <div class="launcher-grid">
                        <a href="resident/login" class="launcher-item launcher-resident">
                            <div class="launcher-icon"><i class="fa-solid fa-house-user"></i></div>
                            <div class="launcher-info">
                                <span class="launcher-name">Resident Portal</span>
                                <span class="launcher-sub">Passes, Dues &amp; Community</span>
                            </div>
                            <i class="fa-solid fa-chevron-right launcher-arrow"></i>
                        </a>
                        <a href="staff/login" class="launcher-item launcher-staff">
                            <div class="launcher-icon"><i class="fa-solid fa-shield-halved"></i></div>
                            <div class="launcher-info">
                                <span class="launcher-name">Staff &amp; Security</span>
                                <span class="launcher-sub">Gatehouse Scanner &amp; Shifts</span>
                            </div>
                            <i class="fa-solid fa-chevron-right launcher-arrow"></i>
                        </a>
                        <a href="zone/login" class="launcher-item launcher-zone">
                            <div class="launcher-icon"><i class="fa-solid fa-diagram-project"></i></div>
                            <div class="launcher-info">
                                <span class="launcher-name">Zonal Admin</span>
                                <span class="launcher-sub">Streets, Levies &amp; Residents</span>
                            </div>
                            <i class="fa-solid fa-chevron-right launcher-arrow"></i>
                        </a>
                        <a href="login" class="launcher-item launcher-admin">
                            <div class="launcher-icon"><i class="fa-solid fa-building-columns"></i></div>
                            <div class="launcher-info">
                                <span class="launcher-name">Central Hub</span>
                                <span class="launcher-sub">Estate-Wide Oversight</span>
                            </div>
                            <i class="fa-solid fa-chevron-right launcher-arrow"></i>
                        </a>
                        <a href="artisan_register.php?ref=public" class="launcher-item" style="border-left-color: #0284c7;">
                            <div class="launcher-icon" style="background: rgba(2, 132, 199, 0.1); color: #0284c7;"><i class="fa-solid fa-screwdriver-wrench"></i></div>
                            <div class="launcher-info">
                                <span class="launcher-name">Artisan Network</span>
                                <span class="launcher-sub">Accreditation &amp; Onboarding</span>
                            </div>
                            <i class="fa-solid fa-chevron-right launcher-arrow"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="hero-content desktop-only">
            <div class="hero-badge">
                <i class="fa-solid fa-shield-halved"></i>
                <span>Gated & Secure Smart Community</span>
            </div>

            <h1 class="hero-title">
                Smart, Secure & Connected Community Living
            </h1>

            <p class="hero-subtitle">
                Welcome to <strong><?php echo htmlspecialchars($estate_name); ?></strong>. <?php echo htmlspecialchars($estate_motto); ?>. Enjoy seamless digital gate access, online bill settlement, rapid maintenance, and vibrant neighborhood communication.
            </p>

            <div class="hero-actions">
                <a href="#portals" class="btn-hero-primary">
                    <span>Access Your Portal</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
                <a href="#features" class="btn-hero-secondary">
                    <i class="fa-solid fa-compass"></i>
                    <span>Explore Amenities</span>
                </a>
            </div>

            <div class="estate-stats-strip">
                <div class="stat-item">
                    <div class="stat-number">24/7</div>
                    <div class="stat-label">Security & Surveillance</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">100%</div>
                    <div class="stat-label">Digital Visitor Passes</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">Instant</div>
                    <div class="stat-label">Online Invoicing</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">Fast</div>
                    <div class="stat-label">Facility Maintenance</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Core Platform Selling Points (USPs) Section -->
    <section class="selling-points-section" id="selling-points">
        <div class="usp-container">
            <div class="section-header">
                <div class="section-badge">
                    <i class="fa-solid fa-gem text-primary"></i> Value Propositions &amp; Selling Points
                </div>
                <h2 class="section-title">6 Core Pillars That Set Our Estate Apart</h2>
                <p class="section-subtitle">
                    Engineered from the ground up to solve real estate bottlenecks: from ironclad gatehouse security and strict zonal data isolation to transparent accounting and verified artisan networks.
                </p>
            </div>

            <div class="selling-points-grid">
                <!-- 1. Ironclad Gatehouse Clearance -->
                <div class="usp-card" style="--usp-accent: #10b981; --usp-icon-bg: rgba(16, 185, 129, 0.15); --usp-icon-border: rgba(16, 185, 129, 0.3); --usp-glow: rgba(16, 185, 129, 0.25);">
                    <div class="usp-header">
                        <div class="usp-icon-wrap">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <span class="usp-metric-badge">
                            <i class="fa-solid fa-bolt text-warning"></i> &lt; 5s Verification
                        </span>
                    </div>
                    <h3 class="usp-title">Ironclad Gatehouse Security &amp; Passes</h3>
                    <p class="usp-desc">
                        Eliminates manual paper logbooks and gate congestion. Encrypted digital QR passes allow security officers to verify visitors in under 5 seconds with instant host arrival notifications.
                    </p>
                    <ul class="usp-perks-list">
                        <li class="usp-perk-item">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Zero manual paper logs or stolen visitor records</span>
                        </li>
                        <li class="usp-perk-item">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Real-time gatehouse scanner with entry &amp; exit timestamping</span>
                        </li>
                        <li class="usp-perk-item">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Single-use, auto-expiring codes stop unauthorized re-entry</span>
                        </li>
                    </ul>
                </div>

                <!-- 2. Hierarchical Zonal Autonomy -->
                <div class="usp-card" style="--usp-accent: #a855f7; --usp-icon-bg: rgba(168, 85, 247, 0.15); --usp-icon-border: rgba(168, 85, 247, 0.3); --usp-glow: rgba(168, 85, 247, 0.25);">
                    <div class="usp-header">
                        <div class="usp-icon-wrap">
                            <i class="fa-solid fa-layer-group"></i>
                        </div>
                        <span class="usp-metric-badge">
                            <i class="fa-solid fa-lock text-purple"></i> Strict Data Boundary
                        </span>
                    </div>
                    <h3 class="usp-title">Hierarchical Zonal RBAC Autonomy</h3>
                    <p class="usp-desc">
                        Tailored for modern multi-sector communities. Zone Admins autonomously manage their streets, units, and local levies with zero visibility into central secrets or other sectors.
                    </p>
                    <ul class="usp-perks-list">
                        <li class="usp-perk-item">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Zero cross-zone data leakage or unauthorized sector access</span>
                        </li>
                        <li class="usp-perk-item">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Street-level precision for local billing &amp; resident census</span>
                        </li>
                        <li class="usp-perk-item">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Consolidated executive visibility for Central Management</span>
                        </li>
                    </ul>
                </div>

                <!-- 3. Transparent Accounting & Instant Settlement -->
                <div class="usp-card" style="--usp-accent: #3b82f6; --usp-icon-bg: rgba(59, 130, 246, 0.15); --usp-icon-border: rgba(59, 130, 246, 0.3); --usp-glow: rgba(59, 130, 246, 0.25);">
                    <div class="usp-header">
                        <div class="usp-icon-wrap">
                            <i class="fa-solid fa-file-invoice-dollar"></i>
                        </div>
                        <span class="usp-metric-badge">
                            <i class="fa-solid fa-chart-line text-info"></i> 100% Reconciled
                        </span>
                    </div>
                    <h3 class="usp-title">Transparent Accounting &amp; Direct Billing</h3>
                    <p class="usp-desc">
                        Eliminates financial leakage and unrecorded dues. Automated recurring billing with Paystack card, USSD, and dedicated zonal bank accounts with instant verifiable receipts.
                    </p>
                    <ul class="usp-perks-list">
                        <li class="usp-perk-item">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Zero cash handling &amp; complete protection against revenue diversion</span>
                        </li>
                        <li class="usp-perk-item">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Flexible installment milestones configured per zone</span>
                        </li>
                        <li class="usp-perk-item">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Tamper-proof digital receipts with automated PDF export</span>
                        </li>
                    </ul>
                </div>

                <!-- 4. Accredited Artisan Network -->
                <div class="usp-card" style="--usp-accent: #0284c7; --usp-icon-bg: rgba(2, 132, 199, 0.15); --usp-icon-border: rgba(2, 132, 199, 0.3); --usp-glow: rgba(2, 132, 199, 0.25);">
                    <div class="usp-header">
                        <div class="usp-icon-wrap">
                            <i class="fa-solid fa-screwdriver-wrench"></i>
                        </div>
                        <span class="usp-metric-badge">
                            <i class="fa-solid fa-certificate text-primary"></i> Vetted &amp; Quarantined
                        </span>
                    </div>
                    <h3 class="usp-title">Accredited Estate Artisan Network</h3>
                    <p class="usp-desc">
                        Protect households from unverified roadside workers. Central Administration vets artisans with NIN verification, guarantor audits, and background screening before issuing credentials.
                    </p>
                    <ul class="usp-perks-list">
                        <li class="usp-perk-item">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Strict admin quarantine before listing in the resident directory</span>
                        </li>
                        <li class="usp-perk-item">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Express gatehouse clearance code eliminates entry delays</span>
                        </li>
                        <li class="usp-perk-item">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>5-star resident ratings, review tracking &amp; fair inspection pricing</span>
                        </li>
                    </ul>
                </div>

                <!-- 5. 360° Resident Dossiers & Vehicle Stickers -->
                <div class="usp-card" style="--usp-accent: #f59e0b; --usp-icon-bg: rgba(245, 158, 11, 0.15); --usp-icon-border: rgba(245, 158, 11, 0.3); --usp-glow: rgba(245, 158, 11, 0.25);">
                    <div class="usp-header">
                        <div class="usp-icon-wrap">
                            <i class="fa-solid fa-id-card"></i>
                        </div>
                        <span class="usp-metric-badge">
                            <i class="fa-solid fa-car text-amber"></i> 360° Household Dossier
                        </span>
                    </div>
                    <h3 class="usp-title">360° Household &amp; Vehicle Profiling</h3>
                    <p class="usp-desc">
                        Centralize multi-entity household management in one profile: authorized domestic staff, co-residents, dependents, registered pets, and vehicles with holographic security stickers.
                    </p>
                    <ul class="usp-perks-list">
                        <li class="usp-perk-item">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Anti-counterfeit vehicle stickers with QR license plate verification</span>
                        </li>
                        <li class="usp-perk-item">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Domestic staff gate clearance passes &amp; verified background files</span>
                        </li>
                        <li class="usp-perk-item">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Comprehensive resident timeline history and occupancy tracking</span>
                        </li>
                    </ul>
                </div>

                <!-- 6. Emergency SOS & Multi-Channel Broadcast -->
                <div class="usp-card" style="--usp-accent: #f43f5e; --usp-icon-bg: rgba(244, 63, 94, 0.15); --usp-icon-border: rgba(244, 63, 94, 0.3); --usp-glow: rgba(244, 63, 94, 0.25);">
                    <div class="usp-header">
                        <div class="usp-icon-wrap">
                            <i class="fa-solid fa-tower-broadcast"></i>
                        </div>
                        <span class="usp-metric-badge">
                            <i class="fa-solid fa-phone-volume text-danger"></i> Rapid Response
                        </span>
                    </div>
                    <h3 class="usp-title">Emergency SOS &amp; Omnichannel Notices</h3>
                    <p class="usp-desc">
                        Speed saves lives. Emergency hotlines connect residents directly to guardhouse officers without alarm delays, supported by automated WhatsApp, Kapso webhook, and Email broadcasts.
                    </p>
                    <ul class="usp-perks-list">
                        <li class="usp-perk-item">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>One-tap panic hotline dialer to gatehouse &amp; patrol beats</span>
                        </li>
                        <li class="usp-perk-item">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Automated multi-channel notices delivered to Email &amp; WhatsApp</span>
                        </li>
                        <li class="usp-perk-item">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Interactive resident community forum &amp; digital policy handbook</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Trust & Assurance Strip -->
            <div class="trust-strip-bar">
                <div class="trust-strip-item">
                    <i class="fa-solid fa-lock text-success"></i>
                    <span>256-Bit Bank Grade Data Encryption</span>
                </div>
                <div class="trust-strip-item">
                    <i class="fa-solid fa-qrcode text-info"></i>
                    <span>Sub-5s Gate Access Verification</span>
                </div>
                <div class="trust-strip-item">
                    <i class="fa-solid fa-shield-check text-warning"></i>
                    <span>100% Vetted Artisan Guarantee</span>
                </div>
                <div class="trust-strip-item">
                    <i class="fa-solid fa-scale-balanced text-primary"></i>
                    <span>Zero Revenue Diversion &amp; Audited Ledgers</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Legacy Estates vs Smart Digital Estate Comparison Matrix -->
    <section class="comparison-section" id="comparison">
        <div class="usp-container">
            <div class="section-header">
                <div class="section-badge">
                    <i class="fa-solid fa-code-compare text-info"></i> Comparative Operational Impact
                </div>
                <h2 class="section-title">Smart Digital Estate vs. Legacy Estate Management</h2>
                <p class="section-subtitle">
                    Why traditional manual estates struggle with security breaches, financial discrepancies, and resident dissatisfaction — and how our platform transforms community living.
                </p>
            </div>

            <div class="comparison-wrapper">
                <table class="comparison-table">
                    <thead>
                        <tr>
                            <th class="col-feature"><i class="fa-solid fa-sliders me-1.5"></i> Operational Domain</th>
                            <th class="col-legacy"><i class="fa-solid fa-triangle-exclamation me-1.5"></i> Legacy Estates (Paper &amp; Manual)</th>
                            <th class="col-smart"><i class="fa-solid fa-circle-check me-1.5"></i> Our Smart Estate Platform</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="col-feature">Visitor Access Clearance</td>
                            <td class="col-legacy"><i class="fa-solid fa-xmark"></i> Paper notebooks, queues at gate, unverified phone calls, prone to theft and lost records.</td>
                            <td class="col-smart"><i class="fa-solid fa-check"></i> Encrypted QR passes generated on mobile, sub-5s gate scanner, automated resident check-in alert.</td>
                        </tr>
                        <tr>
                            <td class="col-feature">Levies &amp; Dues Collection</td>
                            <td class="col-legacy"><i class="fa-solid fa-xmark"></i> Cash handling, lost paper receipts, delayed bank reconciliations, frequent dispute over paid dues.</td>
                            <td class="col-smart"><i class="fa-solid fa-check"></i> Automated digital invoices, instant Paystack/bank transfer, tamper-proof receipts, 100% reconciled ledger.</td>
                        </tr>
                        <tr>
                            <td class="col-feature">Artisans &amp; Tradespeople</td>
                            <td class="col-legacy"><i class="fa-solid fa-xmark"></i> Unvetted roadside workers roaming estate, unverified identities, security and theft risks.</td>
                            <td class="col-smart"><i class="fa-solid fa-check"></i> Central Admin accredited network, NIN &amp; guarantor verified, gate pre-clearance badge code, resident ratings.</td>
                        </tr>
                        <tr>
                            <td class="col-feature">Multi-Zone Administration</td>
                            <td class="col-legacy"><i class="fa-solid fa-xmark"></i> Central executive bottleneck, chaotic WhatsApp groups, cross-zone privacy leaks, uncoordinated billing.</td>
                            <td class="col-smart"><i class="fa-solid fa-check"></i> Strict Zonal RBAC isolation, autonomous sector secretariats, localized levies, executive oversight hub.</td>
                        </tr>
                        <tr>
                            <td class="col-feature">Resident Household Tracking</td>
                            <td class="col-legacy"><i class="fa-solid fa-xmark"></i> Scattered paper files, untracked domestic staff, no record of resident vehicles or pets.</td>
                            <td class="col-smart"><i class="fa-solid fa-check"></i> 360° Household dossiers: domestic staff security passes, holographic vehicle stickers, pet registry.</td>
                        </tr>
                        <tr>
                            <td class="col-feature">Emergency &amp; Security Alerts</td>
                            <td class="col-legacy"><i class="fa-solid fa-xmark"></i> Chaotic phone calls, delayed security guardhouse response, unconfirmed rumors.</td>
                            <td class="col-smart"><i class="fa-solid fa-check"></i> Direct gatehouse emergency hotlines, automated multi-channel WhatsApp, Kapso webhook, &amp; Email broadcasts.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- Role Portals Selector Section -->
    <section class="portals-section" id="portals">
        <div class="section-header">
            <div class="section-badge">
                <i class="fa-solid fa-network-wired"></i> Unified Gateway
            </div>
            <h2 class="section-title">Select Your Estate Portal</h2>
            <p class="section-subtitle">
                Access dedicated dashboards tailored specifically for homeowners, security personnel, zonal supervisors, and central estate administration.
            </p>
        </div>

        <div class="portals-grid">
            <!-- 1. Resident Portal -->
            <div class="portal-card resident-card">
                <div class="portal-icon-wrapper">
                    <i class="fa-solid fa-house-user"></i>
                </div>
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                    <span class="portal-tag mb-0">Residents &amp; Owners</span>
                    <span class="badge bg-info bg-opacity-25 text-info rounded-pill px-2 py-0.5" style="font-size: 0.7rem;"><i class="fa-solid fa-bolt me-1"></i> Instant QR Pass</span>
                </div>
                <h3 class="portal-title">Resident Portal</h3>
                <p class="portal-desc">
                    Homeowners and tenants can manage utility bills, book visitor access passes, and report facility repair issues in seconds.
                </p>
                <ul class="portal-features">
                    <li><i class="fa-solid fa-circle-check"></i> Visitor Access Passes &amp; QR Codes (&lt; 5s Entry)</li>
                    <li><i class="fa-solid fa-circle-check"></i> Online Bill Payments &amp; Instant Proof of Settlement</li>
                    <li><i class="fa-solid fa-circle-check"></i> Vetted Artisan Directory &amp; Repair Work Orders</li>
                    <li><i class="fa-solid fa-circle-check"></i> Resident Forum &amp; Direct Gatehouse Panic Hotline</li>
                </ul>
                <a href="resident/login" class="portal-btn">
                    <span>Resident Sign In</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <!-- 2. Staff & Security Console -->
            <div class="portal-card staff-card">
                <div class="portal-icon-wrapper">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                    <span class="portal-tag mb-0">Operations &amp; Security</span>
                    <span class="badge bg-success bg-opacity-25 text-success rounded-pill px-2 py-0.5" style="font-size: 0.7rem;"><i class="fa-solid fa-qrcode me-1"></i> Sub-5s Scanner</span>
                </div>
                <h3 class="portal-title">Staff Console</h3>
                <p class="portal-desc">
                    For gatehouse security officers, maintenance engineers, and field supervisors to verify visitors and resolve tickets.
                </p>
                <ul class="portal-features">
                    <li><i class="fa-solid fa-circle-check"></i> Real-time Gate Pass Verification &amp; Inspection</li>
                    <li><i class="fa-solid fa-circle-check"></i> Scannable Vehicle Hologram Plate Lookup</li>
                    <li><i class="fa-solid fa-circle-check"></i> Automated Check-In / Check-Out Audit Logs</li>
                    <li><i class="fa-solid fa-circle-check"></i> Gatehouse Shift Reporting &amp; Incident Dispatch</li>
                </ul>
                <a href="staff/login" class="portal-btn">
                    <span>Staff Sign In</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <!-- 3. Zonal Admin Portal -->
            <div class="portal-card zone-card">
                <div class="portal-icon-wrapper">
                    <i class="fa-solid fa-layer-group"></i>
                </div>
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                    <span class="portal-tag mb-0">Zonal Management</span>
                    <span class="badge rounded-pill px-2 py-0.5" style="font-size: 0.7rem; background: rgba(168, 85, 247, 0.2); color: #c084fc;"><i class="fa-solid fa-lock me-1"></i> Strict RBAC</span>
                </div>
                <h3 class="portal-title">Zonal Admin Portal</h3>
                <p class="portal-desc">
                    Dedicated command center for Zone Administrators to manage streets, onboard local residents, configure zonal levies, and generate bills.
                </p>
                <ul class="portal-features">
                    <li><i class="fa-solid fa-circle-check"></i> Scoped Street &amp; Property Registry (Zero Cross-Zone Leak)</li>
                    <li><i class="fa-solid fa-circle-check"></i> Multi-Entity Resident &amp; Domestic Staff Directory</li>
                    <li><i class="fa-solid fa-circle-check"></i> Dedicated Zonal Accounts &amp; Automated Billing</li>
                    <li><i class="fa-solid fa-circle-check"></i> Real-time Zone Financial &amp; Occupancy Analytics</li>
                </ul>
                <a href="zone/login" class="portal-btn">
                    <span>Zone Admin Sign In</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <!-- 4. Central Administration Hub -->
            <div class="portal-card admin-card">
                <div class="portal-icon-wrapper">
                    <i class="fa-solid fa-building-columns"></i>
                </div>
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                    <span class="portal-tag mb-0">Central &amp; Executive</span>
                    <span class="badge bg-warning bg-opacity-25 text-warning rounded-pill px-2 py-0.5" style="font-size: 0.7rem;"><i class="fa-solid fa-chart-pie me-1"></i> Audited Ledger</span>
                </div>
                <h3 class="portal-title">Central Admin Hub</h3>
                <p class="portal-desc">
                    Executive management command center for estate-wide zone creation, guardhouse security oversight, automated finance, and global governance.
                </p>
                <ul class="portal-features">
                    <li><i class="fa-solid fa-circle-check"></i> Multi-Zone Sector Creation &amp; Admin Scoping</li>
                    <li><i class="fa-solid fa-circle-check"></i> Exclusive Gate &amp; Guardhouse Security Command</li>
                    <li><i class="fa-solid fa-circle-check"></i> Estate-Wide Consolidated Financial Auditing</li>
                    <li><i class="fa-solid fa-circle-check"></i> Global Roles, Staff Shift Logs &amp; Activity Audit</li>
                </ul>
                <a href="login" class="portal-btn">
                    <span>Central Sign In</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <!-- 5. Artisan & Facility Contractors -->
            <div class="portal-card" style="border-top: 4px solid #0284c7;">
                <div class="portal-icon-wrapper" style="background: rgba(2, 132, 199, 0.1); color: #0284c7;">
                    <i class="fa-solid fa-screwdriver-wrench"></i>
                </div>
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                    <span class="portal-tag mb-0" style="background: rgba(2, 132, 199, 0.1); color: #0284c7;">Artisans &amp; Trades</span>
                    <span class="badge rounded-pill px-2 py-0.5" style="font-size: 0.7rem; background: rgba(2, 132, 199, 0.2); color: #38bdf8;"><i class="fa-solid fa-certificate me-1"></i> Vetted Pro</span>
                </div>
                <h3 class="portal-title">Artisan Network</h3>
                <p class="portal-desc">
                    Plumbers, electricians, HVAC technicians, carpenters, and facility contractors can apply for official estate accreditation and receive service requests.
                </p>
                <ul class="portal-features">
                    <li><i class="fa-solid fa-circle-check"></i> Official Vetted Badge &amp; Express Gatehouse Passcode</li>
                    <li><i class="fa-solid fa-circle-check"></i> Exclusive Direct Work Orders from 500+ Verified Residents</li>
                    <li><i class="fa-solid fa-circle-check"></i> Direct 100% Client Settlement (Zero Middleman Deductions)</li>
                    <li><i class="fa-solid fa-circle-check"></i> Verified Ratings, 5-Star Reviews &amp; Facility Contracts</li>
                </ul>
                <a href="artisan_register.php?ref=public" class="portal-btn" style="background: #0284c7; color: #fff;">
                    <span>Apply for Accreditation</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </section>

    <!-- Amenities & Features Showcase -->
    <section class="features-section" id="features">
        <div class="section-header">
            <div class="section-badge">
                <i class="fa-solid fa-star"></i> Estate Amenities
            </div>
            <h2 class="section-title">Designed for Modern Comfort & Safety</h2>
            <p class="section-subtitle">
                Everything required to make estate living effortless, orderly, and enjoyable for all residents.
            </p>
        </div>

        <div class="features-grid">
            <div class="feature-box">
                <div class="feature-icon">
                    <i class="fa-solid fa-qrcode"></i>
                </div>
                <h4 class="feature-title">Smart Gate Passcodes</h4>
                <p class="feature-text">
                    Residents generate instant visitor access codes directly from their mobile devices, eliminating queue times at the security gate.
                </p>
            </div>

            <div class="feature-box">
                <div class="feature-icon">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                </div>
                <h4 class="feature-title">Transparent Accounting</h4>
                <p class="feature-text">
                    Automated monthly bills, maintenance levies, and digital payment receipts generated instantly with Paystack card and transfer options.
                </p>
            </div>

            <div class="feature-box">
                <div class="feature-icon">
                    <i class="fa-solid fa-screwdriver-wrench"></i>
                </div>
                <h4 class="feature-title">Rapid Maintenance</h4>
                <p class="feature-text">
                    Submit maintenance requests with priority levels and track technician dispatch progress in real-time until work is fully resolved.
                </p>
            </div>

            <div class="feature-box">
                <div class="feature-icon">
                    <i class="fa-solid fa-comments"></i>
                </div>
                <h4 class="feature-title">Community Discussions</h4>
                <p class="feature-text">
                    Stay connected with neighbors and management through official announcements, security advisories, and the interactive estate forum.
                </p>
            </div>
        </div>
    </section>

    <!-- Governance, Rules & Conduct -->
    <?php if (!empty($public_policies) || $estate_rules || $estate_conduct): ?>
    <section class="rules-section" id="governance">
        <div class="section-header" style="margin-bottom: 2.5rem;">
            <div class="section-badge">
                <i class="fa-solid fa-scale-balanced"></i> Estate Governance
            </div>
            <h2 class="section-title">Community Standards &amp; Policies</h2>
            <p class="section-subtitle">
                Central governing guidelines that ensure <?php echo htmlspecialchars($estate_name); ?> remains safe, harmonious, and orderly.
            </p>
        </div>

        <?php if (!empty($public_policies)): ?>
        <div class="row g-4 mb-4">
            <?php foreach ($public_policies as $pp): ?>
                <div class="col-12 col-md-6 col-lg-4 text-start">
                    <div class="p-4 rounded-4 h-100 shadow-sm bg-white" style="border: 1px solid rgba(226, 232, 240, 0.8);">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="badge px-2.5 py-1 rounded-pill" style="background: <?php echo htmlspecialchars($pp['category_color'] ?: '#3b82f6'); ?>18; color: <?php echo htmlspecialchars($pp['category_color'] ?: '#3b82f6'); ?>; font-size: 0.75rem;">
                                <i class="fa-solid <?php echo htmlspecialchars($pp['category_icon'] ?: 'fa-gavel'); ?> me-1"></i>
                                <?php echo htmlspecialchars($pp['category_name'] ?: ucfirst($pp['category_slug'])); ?>
                            </span>
                            <?php if ($pp['fine_amount'] > 0): ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger font-monospace" style="font-size: 0.75rem;">Fine: ₦<?php echo number_format($pp['fine_amount']); ?></span>
                            <?php endif; ?>
                        </div>
                        <h5 class="fw-bold text-slate-800 mb-2" style="font-size: 1.05rem;"><?php echo htmlspecialchars($pp['title']); ?></h5>
                        <p class="text-secondary small mb-0" style="line-height: 1.5;"><?php echo htmlspecialchars(substr($pp['description'], 0, 160)) . (strlen($pp['description']) > 160 ? '...' : ''); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="text-center mt-3">
            <a href="resident/policies" class="btn btn-outline-primary px-4 py-2 rounded-pill fw-semibold">
                <i class="fa-solid fa-book-bookmark me-1.5"></i> View Full Handbook in Resident Portal
            </a>
        </div>
        <?php elseif ($estate_rules || $estate_conduct): ?>
        <div class="rules-card">
            <?php if ($estate_rules): ?>
            <div class="rules-col">
                <h4><i class="fa-solid fa-book-bookmark text-primary"></i> Estate Bylaws &amp; Rules</h4>
                <div class="rules-content"><?php echo htmlspecialchars($estate_rules); ?></div>
            </div>
            <?php endif; ?>

            <?php if ($estate_conduct): ?>
            <div class="rules-col">
                <h4><i class="fa-solid fa-handshake-angle text-info"></i> Code of Conduct</h4>
                <div class="rules-content"><?php echo htmlspecialchars($estate_conduct); ?></div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- Public Footer -->
    <footer class="landing-footer" id="contact">
        <div class="footer-container">
            <div class="footer-brand">
                <h3><?php echo htmlspecialchars($estate_name); ?></h3>
                <p><?php echo htmlspecialchars($estate_motto); ?></p>
                <div style="margin-top: 1.5rem; display: flex; gap: 0.75rem;">
                    <span style="color: #64748b; font-size: 0.85rem;"><i class="fa-solid fa-location-dot text-primary me-1"></i> <?php echo htmlspecialchars($estate_location); ?></span>
                </div>
            </div>

            <div class="footer-col">
                <h5>Portals</h5>
                <ul>
                    <li><a href="resident/login"><i class="fa-solid fa-house-user me-1 text-info"></i> Resident Login</a></li>
                    <li><a href="staff/login"><i class="fa-solid fa-shield-halved me-1 text-success"></i> Staff Console</a></li>
                    <li><a href="login"><i class="fa-solid fa-building-columns me-1 text-warning"></i> Admin Hub</a></li>
                </ul>
            </div>

            <div class="footer-col">
                <h5>Community</h5>
                <ul>
                    <li><a href="#about">About Estate</a></li>
                    <li><a href="#features">Amenities</a></li>
                    <li><a href="#governance">Governance</a></li>
                    <li><a href="#portals">Sign In Options</a></li>
                </ul>
            </div>

            <div class="footer-col">
                <h5>Estate Office</h5>
                <div class="footer-contact-item">
                    <i class="fa-solid fa-phone"></i>
                    <span><?php echo htmlspecialchars($office_phone); ?></span>
                </div>
                <div class="footer-contact-item">
                    <i class="fa-solid fa-envelope"></i>
                    <span><?php echo htmlspecialchars($office_email); ?></span>
                </div>
                <div class="footer-contact-item">
                    <i class="fa-solid fa-clock"></i>
                    <span>Gatehouse: 24/7 &bull; Office: 8am - 5pm</span>
                </div>
            </div>
        </div>

        <div class="footer-bottom">
            <div>
                &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($estate_name); ?>. All rights reserved.
            </div>
            <div>
                Powered by Estate Management System
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
