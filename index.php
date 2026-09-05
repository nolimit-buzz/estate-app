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
    
    <!-- Landing CSS -->
    <link rel="stylesheet" href="css/landing.css?v=<?php echo time(); ?>">
    
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
                <li><a href="#portals">Portals</a></li>
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
        <div class="hero-content">
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

    <!-- Role Portals Selector Section -->
    <section class="portals-section" id="portals">
        <div class="section-header">
            <div class="section-badge">
                <i class="fa-solid fa-network-wired"></i> Unified Gateway
            </div>
            <h2 class="section-title">Select Your Estate Portal</h2>
            <p class="section-subtitle">
                Access dedicated dashboards tailored specifically for homeowners, security personnel, and estate administration.
            </p>
        </div>

        <div class="portals-grid">
            <!-- 1. Resident Portal -->
            <div class="portal-card resident-card">
                <div class="portal-icon-wrapper">
                    <i class="fa-solid fa-house-user"></i>
                </div>
                <span class="portal-tag">Residents & Owners</span>
                <h3 class="portal-title">Resident Portal</h3>
                <p class="portal-desc">
                    Homeowners and tenants can manage utility bills, book visitor access passes, and report facility repair issues in seconds.
                </p>
                <ul class="portal-features">
                    <li><i class="fa-solid fa-circle-check"></i> Visitor Access Passes & QR Codes</li>
                    <li><i class="fa-solid fa-circle-check"></i> Online Bill Payments & Receipts</li>
                    <li><i class="fa-solid fa-circle-check"></i> Maintenance Work Order Tracking</li>
                    <li><i class="fa-solid fa-circle-check"></i> Resident Forum & Community Chat</li>
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
                <span class="portal-tag">Operations & Security</span>
                <h3 class="portal-title">Staff Console</h3>
                <p class="portal-desc">
                    For gatehouse security officers, maintenance engineers, and field supervisors to verify visitors and resolve tickets.
                </p>
                <ul class="portal-features">
                    <li><i class="fa-solid fa-circle-check"></i> Real-time Gate Pass Verification</li>
                    <li><i class="fa-solid fa-circle-check"></i> Visitor Check-In / Check-Out Log</li>
                    <li><i class="fa-solid fa-circle-check"></i> Assigned Field Work Order Queue</li>
                    <li><i class="fa-solid fa-circle-check"></i> Gatehouse Shift Reporting</li>
                </ul>
                <a href="staff/login" class="portal-btn">
                    <span>Staff Sign In</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <!-- 3. Estate Administration Hub -->
            <div class="portal-card admin-card">
                <div class="portal-icon-wrapper">
                    <i class="fa-solid fa-building-columns"></i>
                </div>
                <span class="portal-tag">Executive & Finance</span>
                <h3 class="portal-title">Administration Hub</h3>
                <p class="portal-desc">
                    Executive management command center for property registers, automated levy invoicing, staff roles, and audit governance.
                </p>
                <ul class="portal-features">
                    <li><i class="fa-solid fa-circle-check"></i> Property, Street & Flat Registry</li>
                    <li><i class="fa-solid fa-circle-check"></i> Finance Hub & Automated Invoicing</li>
                    <li><i class="fa-solid fa-circle-check"></i> Granular Roles & Access Controls</li>
                    <li><i class="fa-solid fa-circle-check"></i> Real-time System Audit Logging</li>
                </ul>
                <a href="login" class="portal-btn">
                    <span>Admin Sign In</span>
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

    <!-- Governance, Rules & Conduct (If defined) -->
    <?php if ($estate_rules || $estate_conduct): ?>
    <section class="rules-section" id="governance">
        <div class="section-header" style="margin-bottom: 2.5rem;">
            <div class="section-badge">
                <i class="fa-solid fa-scale-balanced"></i> Governance
            </div>
            <h2 class="section-title">Community Standards & Rules</h2>
            <p class="section-subtitle">
                Guidelines that keep <?php echo htmlspecialchars($estate_name); ?> safe, clean, and peaceful for everyone.
            </p>
        </div>

        <div class="rules-card">
            <?php if ($estate_rules): ?>
            <div class="rules-col">
                <h4><i class="fa-solid fa-book-bookmark text-primary"></i> Estate Bylaws & Rules</h4>
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
