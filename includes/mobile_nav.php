<?php
// includes/mobile_nav.php - Enterprise Mobile UI Component Renderer

if (!function_exists('renderMobileAppShell')) {
    function renderMobileAppShell($portal = 'resident', $current_page = 'index', $page_title = '') {
        global $conn, $user_name, $resident_info, $branding;

        $user_id = intval($_SESSION['user_id'] ?? 0);
        $name = $_SESSION['name'] ?? 'User';
        $role = $_SESSION['role'] ?? 'user';
        $is_subpage = ($current_page !== 'index' && $current_page !== '');

        if (!isset($branding) || empty($branding)) {
            if (function_exists('get_estate_branding') && isset($conn)) {
                $branding = get_estate_branding($conn);
            } else {
                $branding = [];
            }
        }
        $estate_name = $branding['estate_name'] ?? 'Main Estate';
        $estate_logo_url = $branding['estate_logo_url'] ?? '';
        $app_company_name = $branding['app_company_name'] ?? 'NoLimitBuzz';
        $e_id = function_exists('get_estate_id') ? get_estate_id() : 1;

        // Avatar & role subtitle calculation
        $avatar_url = '';
        $role_subtitle = 'Member';
        $res_unit_label = '';
        
        // Calculate initials for fallback avatar
        $name_parts = explode(' ', trim($name));
        $initials = strtoupper(substr($name_parts[0] ?? 'U', 0, 1) . (isset($name_parts[1]) ? substr($name_parts[1], 0, 1) : ''));
        if (empty($initials)) { $initials = 'U'; }
        
        if ($portal === 'resident') {
            $role_subtitle = 'Verified Resident';
            if ($user_id && isset($conn)) {
                $av_res = $conn->query("SELECT r.image_path, f.number as flat_no, b.name as building_name 
                                       FROM residents r 
                                       LEFT JOIN flats f ON r.flat_id = f.id 
                                       LEFT JOIN buildings b ON f.building_id = b.id 
                                       WHERE r.user_id = $user_id AND r.estate_id = $e_id 
                                       ORDER BY r.id DESC LIMIT 1");
                if ($av_res && $av_row = $av_res->fetch_assoc()) {
                    $raw = $av_row['image_path'] ?? '';
                    if (!empty($raw)) {
                        $avatar_url = file_exists($raw) ? $raw : (file_exists('../' . ltrim($raw, './')) ? '../' . ltrim($raw, './') : '');
                    }
                    if (!empty($av_row['flat_no'])) {
                        $res_unit_label = (!empty($av_row['building_name']) ? $av_row['building_name'] . ' • ' : '') . 'Unit ' . $av_row['flat_no'];
                    }
                }
            }
            if (!empty($res_unit_label)) {
                $role_subtitle = $res_unit_label;
            }
        } elseif ($portal === 'admin') {
            $role_subtitle = 'Estate Admin';
        } elseif ($portal === 'staff') {
            $role_subtitle = !empty($_SESSION['staff_role_title']) ? $_SESSION['staff_role_title'] : 'Security Officer';
        } elseif ($portal === 'zone') {
            $zone_code_display = $_SESSION['zone_code'] ?? 'ZONE';
            $role_subtitle = 'Zone Admin (' . $zone_code_display . ')';
        }

        // Subpage readable title
        if (empty($page_title)) {
            $titles = [
                'property' => 'Property & Unit Profile',
                'properties' => 'Properties & Units',
                'property_details' => 'Property Details',
                'visitors' => 'Visitor Access Passes',
                'security' => 'Gate Security & Passes',
                'finance' => 'Finance & Billing Hub',
                'receipts' => 'Verified Receipts',
                'receipt' => 'Receipt Proof',
                'report_issue' => 'Maintenance Requests',
                'maintenance' => 'Maintenance Dispatch',
                'artisans' => 'Verified Artisan Directory',
                'community_chat' => 'Estate Community Forum',
                'notices' => 'Notices & Broadcasts',
                'broadcasts' => 'Broadcasts & Alerts',
                'directory' => 'Estate Directory',
                'policies' => 'Rules & Bylaws',
                'emergency' => 'Emergency & SOS Alarm',
                'settings' => 'Account & Preferences',
                'residents' => 'Resident Registry',
                'zones' => 'Zones & Sectors',
                'owners' => 'Property Owners',
                'charges' => 'Charge Catalog',
                'billing_config' => 'Billing & Installments',
                'staff' => 'Estate Staff',
                'archives' => 'Archives Vault',
                'roster' => 'Duty Roster',
                'incidents' => 'Incident Reports',
                'email_logs' => 'Email Logs',
                'whatsapp_logs' => 'WhatsApp Logs',
                'users' => 'Users & Security',
                'audit_logs' => 'System Audit',
                'reports' => 'Zonal Reports'
            ];
            $page_title = $titles[$current_page] ?? ucwords(str_replace('_', ' ', $current_page));
        }

        // Back button parent link
        $parent_url = 'index';
        if ($portal === 'admin') {
            if (in_array($current_page, ['property_details'])) {
                $parent_url = 'properties';
            } elseif (in_array($current_page, ['charges', 'billing_config'])) {
                $parent_url = 'finance';
            } else {
                $parent_url = '../admin/index';
            }
        } elseif ($portal === 'zone') {
            if (in_array($current_page, ['property_details'])) {
                $parent_url = 'properties';
            } elseif (in_array($current_page, ['charges', 'billing_config'])) {
                $parent_url = 'finance';
            }
        } elseif ($portal === 'staff') {
            $parent_url = '../staff/index';
        }

        // Badges calculation
        $unread_count = 0;
        $unread_notices_cnt = 0;
        $pending_req_cnt = 0;
        $pending_artisan_cnt = 0;
        
        if (isset($conn) && $user_id > 0) {
            // General unread notifications
            $notif_res = $conn->query("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = $user_id AND is_read = 0 AND type NOT IN ('estate_broadcast', 'zone_notice')");
            if ($notif_res) {
                $unread_count = intval($notif_res->fetch_assoc()['cnt'] ?? 0);
            }

            if ($portal === 'resident') {
                $un_res = $conn->query("SELECT COUNT(id) as cnt FROM estate_announcements WHERE estate_id = $e_id AND status = 'active'");
                if ($un_res) { $unread_notices_cnt = intval($un_res->fetch_assoc()['cnt'] ?? 0); }
                
                $p_res = $conn->query("SELECT COUNT(id) as cnt FROM contact_change_requests WHERE user_id = $user_id AND estate_id = $e_id AND status = 'pending'");
                if ($p_res) { $pending_req_cnt = intval($p_res->fetch_assoc()['cnt'] ?? 0); }
            } elseif ($portal === 'admin') {
                $pen_res = $conn->query("SELECT COUNT(*) as cnt FROM contact_change_requests WHERE estate_id = $e_id AND status = 'pending'");
                if ($pen_res) { $pending_req_cnt = intval($pen_res->fetch_assoc()['cnt'] ?? 0); }

                $art_res = $conn->query("SELECT COUNT(*) as cnt FROM artisans WHERE estate_id = $e_id AND verification_status = 'pending'");
                if ($art_res) { $pending_artisan_cnt = intval($art_res->fetch_assoc()['cnt'] ?? 0); }
            } elseif ($portal === 'zone') {
                $zb_zid = intval($_SESSION['zone_id'] ?? 0);
                $pen_res = $conn->query("
                    SELECT COUNT(DISTINCT ccr.id) as cnt 
                    FROM contact_change_requests ccr
                    LEFT JOIN residents r ON ccr.resident_id = r.id OR (r.user_id = ccr.user_id AND r.estate_id = ccr.estate_id)
                    LEFT JOIN flats f ON r.flat_id = f.id
                    LEFT JOIN buildings b ON f.building_id = b.id
                    LEFT JOIN streets s ON b.street_id = s.id
                    WHERE ccr.estate_id = $e_id 
                      AND (ccr.zone_id = $zb_zid OR s.zone_id = $zb_zid)
                      AND ccr.status = 'pending'
                ");
                if ($pen_res) { $pending_req_cnt = intval($pen_res->fetch_assoc()['cnt'] ?? 0); }
            }
        }

        // Emergency URL per portal
        $emergency_url = 'emergency';
        if ($portal === 'admin') {
            $emergency_url = '../admin/emergency';
        } elseif ($portal === 'staff') {
            $emergency_url = '../staff/incidents';
        } elseif ($portal === 'zone') {
            $emergency_url = 'emergency';
        }

        // Notifications URL per portal
        $notif_url = 'notifications';
        if ($portal === 'admin') {
            $notif_url = '../admin/notifications';
        } elseif ($portal === 'staff') {
            $notif_url = '../staff/index';
        }
        ?>        <!-- ==========================================
             NATIVE MOBILE TOP APP BAR (Phones & Tablets)
             With Emergency SOS, Direct Hotlines & Right-Aligned Profile
             ========================================== -->
        <div class="mobile-only">
            <?php if ($is_subpage): ?>
                <!-- Sub-page Header with Back Arrow & Actions -->
                <header class="mobile-subpage-header">
                    <a href="<?php echo htmlspecialchars($parent_url); ?>" class="mobile-subpage-back" title="Go Back">
                        <i class="fa-solid fa-arrow-left"></i>
                        <span><?php echo htmlspecialchars($page_title); ?></span>
                    </a>
                    <div class="mobile-header-actions">
                        <!-- Direct Hotline Button (Emergency Call without Alarm) -->
                        <button type="button" class="mobile-icon-btn estate-hotline-btn" title="Direct Emergency Hotlines" aria-label="Hotlines">
                            <i class="fa-solid fa-phone-volume text-danger hotline-pulse-icon"></i>
                        </button>
                        <!-- Direct SOS Emergency Action -->
                        <a href="<?php echo htmlspecialchars($emergency_url); ?>" class="mobile-header-sos-btn <?php echo ($portal === 'staff' ? 'estate-hotline-btn' : ''); ?>" title="Emergency &amp; Panic SOS">
                            <i class="fa-solid fa-truck-medical"></i>
                            <span>SOS</span>
                        </a>
                        <button type="button" class="mobile-icon-btn theme-toggle-btn" title="Toggle Day/Night Mode" aria-label="Toggle Theme">
                            <i class="fa-solid fa-moon"></i>
                        </button>
                        <a href="<?php echo htmlspecialchars($notif_url); ?>" class="mobile-icon-btn position-relative" title="Notifications">
                            <i class="fa-regular fa-bell"></i>
                            <?php if ($unread_count > 0): ?>
                                <span class="mobile-badge-dot"></span>
                            <?php endif; ?>
                        </a>
                    </div>
                </header>
            <?php else: ?>
                <!-- Main Dashboard Header (Estate Logo Left, Profile & Actions Right) -->
                <header class="mobile-app-header">
                    <!-- TOP LEFT: Estate Logo & Title -->
                    <a href="<?php echo ($portal === 'admin' ? '../admin/index' : ($portal === 'staff' ? '../staff/index' : 'index')); ?>" class="mobile-brand-link" title="<?php echo htmlspecialchars($estate_name); ?>">
                        <?php if (!empty($estate_logo_url)): ?>
                            <img src="<?php echo htmlspecialchars($estate_logo_url); ?>" alt="<?php echo htmlspecialchars($estate_name); ?>" class="mobile-brand-logo">
                        <?php else: ?>
                            <div class="mobile-brand-icon">
                                <i class="fa-solid fa-city"></i>
                            </div>
                        <?php endif; ?>
                        <div class="mobile-brand-info">
                            <span class="mobile-brand-title"><?php echo htmlspecialchars($estate_name); ?></span>
                        </div>
                    </a>

                    <!-- TOP RIGHT: Actions & User Info (Menu box removed) -->
                    <div class="mobile-header-actions">
                        <!-- Direct Hotline Button (Call Security/Medical directly) -->
                        <button type="button" class="mobile-icon-btn estate-hotline-btn" title="Direct Emergency Hotlines" aria-label="Hotlines">
                            <i class="fa-solid fa-phone-volume text-danger hotline-pulse-icon"></i>
                        </button>

                        <a href="<?php echo htmlspecialchars($notif_url); ?>" class="mobile-icon-btn position-relative" title="Notifications">
                            <i class="fa-regular fa-bell"></i>
                            <?php if ($unread_count > 0): ?>
                                <span class="mobile-badge-dot"></span>
                            <?php endif; ?>
                        </a>

                        <!-- Direct SOS & Theme Toggle visible on wider screens -->
                        <a href="<?php echo htmlspecialchars($emergency_url); ?>" class="mobile-header-sos-btn d-none d-sm-inline-flex <?php echo ($portal === 'staff' ? 'estate-hotline-btn' : ''); ?>" title="Emergency &amp; Panic SOS">
                            <i class="fa-solid fa-truck-medical"></i>
                            <span>SOS</span>
                        </a>

                        <button type="button" class="mobile-icon-btn theme-toggle-btn d-none d-sm-inline-flex" title="Toggle Day/Night Mode" aria-label="Toggle Theme">
                            <i class="fa-solid fa-moon"></i>
                        </button>

                        <!-- User Profile on the Right (Always Visible on Mobile) -->
                        <a href="<?php echo ($portal === 'resident' ? 'settings' : ($portal === 'admin' ? '../admin/settings' : ($portal === 'zone' ? 'settings' : '../staff/index'))); ?>" class="mobile-user-profile-right" title="Account &amp; Settings">
                            <div class="mobile-user-meta text-end d-flex flex-column justify-content-center">
                                <span class="mobile-user-name"><?php echo htmlspecialchars($name); ?></span>
                                <span class="mobile-user-role-badge"><?php echo htmlspecialchars($role_subtitle); ?></span>
                            </div>
                            <div class="mobile-avatar-wrap">
                                <?php if (!empty($avatar_url)): ?>
                                    <img src="<?php echo htmlspecialchars($avatar_url); ?>" alt="Avatar" class="mobile-avatar-img">
                                <?php else: ?>
                                    <div class="mobile-avatar-fallback">
                                        <?php echo htmlspecialchars($initials); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </a>
                    </div>
                </header>
            <?php endif; ?>
        </div>

        <!-- ==========================================
             DOCKED BOTTOM NAVIGATION BAR
             Direct Access to Primary Workflows + All Menu
             ========================================== -->
        <nav class="mobile-bottom-nav mobile-only" aria-label="Mobile Navigation">
            <?php if ($portal === 'resident'): ?>
                <a href="index" class="mobile-tab-item <?php echo ($current_page === 'index') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-house-chimney"></i>
                    <span class="tab-label">Home</span>
                </a>
                <a href="visitors" class="mobile-tab-item <?php echo ($current_page === 'visitors') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-id-card-clip"></i>
                    <span class="tab-label">Passes</span>
                </a>
                <a href="property" class="mobile-tab-item <?php echo ($current_page === 'property') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-building-user"></i>
                    <span class="tab-label">Property</span>
                </a>
                <a href="finance" class="mobile-tab-item <?php echo ($current_page === 'finance' || $current_page === 'receipts' || $current_page === 'receipt') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-wallet"></i>
                    <span class="tab-label">Finance</span>
                </a>
                <a href="javascript:void(0)" class="mobile-tab-item" onclick="openMobileActionSheet()">
                    <i class="fa-solid fa-bars-staggered"></i>
                    <span class="tab-label">All Menu</span>
                </a>

            <?php elseif ($portal === 'admin'): ?>
                <a href="../admin/index" class="mobile-tab-item <?php echo ($current_page === 'index') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-chart-pie"></i>
                    <span class="tab-label">Home</span>
                </a>
                <a href="../admin/properties" class="mobile-tab-item <?php echo ($current_page === 'properties' || $current_page === 'property_details' || $current_page === 'zones') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-city"></i>
                    <span class="tab-label">Properties</span>
                </a>
                <a href="../admin/residents" class="mobile-tab-item <?php echo ($current_page === 'residents' || $current_page === 'owners' || $current_page === 'resident_timeline') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-users"></i>
                    <span class="tab-label">Residents</span>
                </a>
                <a href="../admin/finance" class="mobile-tab-item <?php echo ($current_page === 'finance' || $current_page === 'receipt' || $current_page === 'charges') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-wallet"></i>
                    <span class="tab-label">Finance</span>
                </a>
                <a href="javascript:void(0)" class="mobile-tab-item" onclick="openMobileActionSheet()">
                    <i class="fa-solid fa-bars-staggered"></i>
                    <span class="tab-label">All Menu</span>
                </a>

            <?php elseif ($portal === 'staff'): ?>
                <a href="../staff/index" class="mobile-tab-item <?php echo ($current_page === 'index') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-shield-halved"></i>
                    <span class="tab-label">Home</span>
                </a>
                <a href="../staff/security" class="mobile-tab-item <?php echo ($current_page === 'security') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-qrcode"></i>
                    <span class="tab-label">Passes</span>
                </a>
                <a href="../staff/maintenance" class="mobile-tab-item <?php echo ($current_page === 'maintenance') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-screwdriver-wrench"></i>
                    <span class="tab-label">Work</span>
                </a>
                <a href="../staff/artisans" class="mobile-tab-item <?php echo ($current_page === 'artisans') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-wrench"></i>
                    <span class="tab-label">Artisans</span>
                </a>
                <a href="javascript:void(0)" class="mobile-tab-item" onclick="openMobileActionSheet()">
                    <i class="fa-solid fa-bars-staggered"></i>
                    <span class="tab-label">All Menu</span>
                </a>

            <?php else: /* zone */ ?>
                <a href="index" class="mobile-tab-item <?php echo ($current_page === 'index') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-layer-group"></i>
                    <span class="tab-label">Home</span>
                </a>
                <a href="properties" class="mobile-tab-item <?php echo ($current_page === 'properties') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-city"></i>
                    <span class="tab-label">Properties</span>
                </a>
                <a href="residents" class="mobile-tab-item <?php echo ($current_page === 'residents') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-users"></i>
                    <span class="tab-label">Residents</span>
                </a>
                <a href="finance" class="mobile-tab-item <?php echo ($current_page === 'finance') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-wallet"></i>
                    <span class="tab-label">Finance</span>
                </a>
                <a href="javascript:void(0)" class="mobile-tab-item" onclick="openMobileActionSheet()">
                    <i class="fa-solid fa-bars-staggered"></i>
                    <span class="tab-label">All Menu</span>
                </a>
            <?php endif; ?>
        </nav>

        <!-- ==========================================
             COMPREHENSIVE MOBILE NAVIGATION DRAWER
             Contains 100% of Web Sidebar Properties & Features
             ========================================== -->
        <div class="mobile-sheet-backdrop mobile-only" id="mobileActionSheetBackdrop">
            <div class="mobile-action-sheet" id="mobileActionSheet">
                <div class="mobile-sheet-handle"></div>
                <div class="mobile-sheet-header">
                    <div class="d-flex align-items-center gap-2">
                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(37, 99, 235, 0.12); color: var(--mob-primary); display: flex; align-items: center; justify-content: center; font-size: 0.95rem;">
                            <i class="fa-solid fa-house-user"></i>
                        </div>
                        <div>
                            <h3 class="mobile-sheet-title" style="font-size: 0.95rem; line-height: 1.2;"><?php echo htmlspecialchars($estate_name); ?></h3>
                            <span style="font-size: 0.68rem; color: var(--mob-text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;">
                                <?php echo ($portal === 'resident') ? 'Resident Portal' : (($portal === 'admin') ? 'Executive Admin Hub' : (($portal === 'staff') ? 'Staff Operations' : 'Zonal Portal')); ?>
                            </span>
                        </div>
                    </div>
                    <button type="button" class="mobile-sheet-close" id="mobileSheetCloseBtn" aria-label="Close">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <!-- Emergency SOS Distress Card (Always on Top) -->
                <div class="mobile-drawer-emergency-banner mb-3">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2" style="min-width: 0; flex: 1 1 auto;">
                            <div class="drawer-sos-badge flex-shrink-0">
                                <i class="fa-solid fa-truck-medical text-white fs-6"></i>
                            </div>
                            <div style="min-width: 0;">
                                <div class="fw-bold text-white text-truncate" style="font-size: 0.88rem; line-height: 1.2;">Emergency &amp; Panic SOS</div>
                                <small class="text-truncate d-block" style="color: rgba(255,255,255,0.85); font-size: 0.7rem;">24/7 Security &amp; Distress Hotlines</small>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-1.5 flex-shrink-0">
                            <button type="button" class="btn btn-sm rounded-pill px-2.5 py-1 estate-hotline-btn drawer-hotline-btn fw-bold" style="font-size: 0.75rem;" title="Call Emergency Hotlines">
                                <i class="fa-solid fa-phone-volume me-1"></i> Hotlines
                            </button>
                            <a href="<?php echo htmlspecialchars($emergency_url); ?>" class="btn btn-sm rounded-pill px-2.5 py-1 drawer-hub-btn fw-bold" style="font-size: 0.75rem;" title="Open Emergency Hub">
                                Hub &rarr;
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Theme Mode Appearance Switcher -->
                <div class="mobile-drawer-theme-card mb-3">
                    <div class="d-flex align-items-center gap-2.5" style="min-width: 0;">
                        <div class="mobile-drawer-theme-icon flex-shrink-0">
                            <i class="fa-solid fa-circle-half-stroke"></i>
                        </div>
                        <div style="min-width: 0;">
                            <div class="mobile-drawer-theme-title text-truncate">Interface Mode</div>
                            <div class="mobile-drawer-theme-desc text-truncate">Switch day or night theme</div>
                        </div>
                    </div>
                    <button type="button" class="theme-toggle-btn mobile-theme-switch-pill" title="Toggle Day/Night Mode">
                        <i class="fa-solid fa-moon"></i>
                        <span class="theme-text">Dark Mode</span>
                    </button>
                </div>

                <!-- ================= RESIDENT PORTAL ALL PROPERTIES ================= -->
                <?php if ($portal === 'resident'): ?>
                    <div class="mobile-drawer-section">
                        <div class="mobile-drawer-section-title">Resident Navigation</div>
                        <div class="mobile-sheet-grid">
                            <a href="index" class="mobile-action-tile <?php echo ($current_page === 'index') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-chart-pie text-primary"></i></span>
                                <span class="mobile-tile-label">Overview</span>
                            </a>
                            <a href="notifications" class="mobile-action-tile <?php echo ($current_page === 'notifications') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-bell text-warning"></i></span>
                                <span class="mobile-tile-label">Notifications</span>
                                <?php if ($unread_count > 0 || $pending_req_cnt > 0): ?>
                                    <span class="badge bg-danger rounded-pill mobile-tile-badge"><?php echo ($unread_count > 0) ? $unread_count : '1 req'; ?></span>
                                <?php endif; ?>
                            </a>
                            <a href="notices" class="mobile-action-tile <?php echo ($current_page === 'notices') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-bullhorn text-danger"></i></span>
                                <span class="mobile-tile-label">Notices &amp; Broadcasts</span>
                                <?php if ($unread_notices_cnt > 0): ?>
                                    <span class="badge bg-danger rounded-pill mobile-tile-badge"><?php echo $unread_notices_cnt; ?></span>
                                <?php endif; ?>
                            </a>
                            <a href="policies" class="mobile-action-tile <?php echo ($current_page === 'policies') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-book-bookmark text-primary"></i></span>
                                <span class="mobile-tile-label">Rules &amp; Bylaws</span>
                            </a>
                            <a href="finance" class="mobile-action-tile <?php echo ($current_page === 'finance') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-file-invoice-dollar text-success"></i></span>
                                <span class="mobile-tile-label">Bills &amp; Invoices</span>
                            </a>
                            <a href="receipts" class="mobile-action-tile <?php echo ($current_page === 'receipts' || $current_page === 'receipt') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-receipt text-emerald"></i></span>
                                <span class="mobile-tile-label">My Receipts</span>
                            </a>
                            <a href="emergency" class="mobile-action-tile tile-emergency <?php echo ($current_page === 'emergency') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon" style="background: rgba(239, 68, 68, 0.15);"><i class="fa-solid fa-truck-medical text-danger"></i></span>
                                <span class="mobile-tile-label text-danger fw-bold">SOS &amp; Panic Hub</span>
                            </a>
                            <a href="visitors" class="mobile-action-tile <?php echo ($current_page === 'visitors') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-id-card-clip text-primary"></i></span>
                                <span class="mobile-tile-label">Visitor Passes</span>
                            </a>
                            <a href="property" class="mobile-action-tile <?php echo ($current_page === 'property') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-building-user text-indigo"></i></span>
                                <span class="mobile-tile-label">My Property Details</span>
                            </a>
                            <a href="report_issue" class="mobile-action-tile <?php echo ($current_page === 'report_issue') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-screwdriver-wrench text-warning"></i></span>
                                <span class="mobile-tile-label">Maintenance Requests</span>
                            </a>
                            <a href="artisans" class="mobile-action-tile <?php echo ($current_page === 'artisans') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-user-gear text-info"></i></span>
                                <span class="mobile-tile-label">Verified Artisans</span>
                            </a>
                            <a href="community_chat" class="mobile-action-tile <?php echo ($current_page === 'community_chat') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-comments text-primary"></i></span>
                                <span class="mobile-tile-label">Estate Forum</span>
                            </a>
                            <a href="directory" class="mobile-action-tile <?php echo ($current_page === 'directory') ? 'active' : ''; ?>" style="grid-column: span 2;">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-address-book text-secondary"></i></span>
                                <span class="mobile-tile-label">Estate Directory</span>
                            </a>
                        </div>
                    </div>

                    <div class="mobile-drawer-section">
                        <div class="mobile-drawer-section-title">Preferences &amp; Account</div>
                        <div class="mobile-sheet-grid">
                            <a href="settings" class="mobile-action-tile <?php echo ($current_page === 'settings') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-sliders text-secondary"></i></span>
                                <span class="mobile-tile-label">Portal Settings</span>
                            </a>
                            <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'superadmin', 'manager'])): ?>
                                <a href="../admin/index" class="mobile-action-tile">
                                    <span class="mobile-tile-icon"><i class="fa-solid fa-arrow-left text-primary"></i></span>
                                    <span class="mobile-tile-label text-primary">Return to Admin</span>
                                </a>
                                <a href="../logout" class="mobile-action-tile" style="grid-column: span 2;">
                                    <span class="mobile-tile-icon" style="background: rgba(239, 68, 68, 0.1);"><i class="fa-solid fa-arrow-right-from-bracket text-danger"></i></span>
                                    <span class="mobile-tile-label text-danger">Sign Out</span>
                                </a>
                            <?php else: ?>
                                <a href="../logout" class="mobile-action-tile">
                                    <span class="mobile-tile-icon" style="background: rgba(239, 68, 68, 0.1);"><i class="fa-solid fa-arrow-right-from-bracket text-danger"></i></span>
                                    <span class="mobile-tile-label text-danger">Sign Out</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                <!-- ================= ADMIN PORTAL ALL PROPERTIES ================= -->
                <?php elseif ($portal === 'admin'): ?>
                    <div class="mobile-drawer-section">
                        <div class="mobile-drawer-section-title">Core Operations</div>
                        <div class="mobile-sheet-grid">
                            <a href="../admin/index" class="mobile-action-tile <?php echo ($current_page === 'index') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-chart-pie text-primary"></i></span>
                                <span class="mobile-tile-label">Dashboard</span>
                            </a>
                            <a href="../admin/notifications" class="mobile-action-tile <?php echo ($current_page === 'notifications') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-bell text-secondary"></i></span>
                                <span class="mobile-tile-label">Notifications</span>
                                <?php if (($unread_count + $pending_req_cnt) > 0): ?>
                                    <span class="badge bg-danger rounded-pill mobile-tile-badge"><?php echo ($unread_count + $pending_req_cnt); ?></span>
                                <?php endif; ?>
                            </a>
                        </div>
                    </div>

                    <div class="mobile-drawer-section">
                        <div class="mobile-drawer-section-title">Real Estate &amp; Assets</div>
                        <div class="mobile-sheet-grid">
                            <a href="../admin/zones" class="mobile-action-tile <?php echo ($current_page === 'zones') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-layer-group text-purple"></i></span>
                                <span class="mobile-tile-label">Zones &amp; Sectors</span>
                            </a>
                            <a href="../admin/properties" class="mobile-action-tile <?php echo ($current_page === 'properties' || $current_page === 'property_details') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-city text-primary"></i></span>
                                <span class="mobile-tile-label">Properties &amp; Units</span>
                            </a>
                            <a href="../admin/owners" class="mobile-action-tile <?php echo ($current_page === 'owners') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-id-card-clip text-teal"></i></span>
                                <span class="mobile-tile-label">Property Owners</span>
                            </a>
                            <a href="../admin/residents" class="mobile-action-tile <?php echo ($current_page === 'residents' || $current_page === 'resident_timeline') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-users text-sky"></i></span>
                                <span class="mobile-tile-label">Residents Registry</span>
                                <?php if ($pending_req_cnt > 0): ?>
                                    <span class="badge bg-warning text-dark rounded-pill mobile-tile-badge"><?php echo $pending_req_cnt; ?> req</span>
                                <?php endif; ?>
                            </a>
                            <a href="../admin/directory" class="mobile-action-tile <?php echo ($current_page === 'directory') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-address-book text-secondary"></i></span>
                                <span class="mobile-tile-label">Member Directory</span>
                            </a>
                            <a href="../admin/broadcasts" class="mobile-action-tile <?php echo ($current_page === 'broadcasts') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-bullhorn text-amber"></i></span>
                                <span class="mobile-tile-label">Broadcasts &amp; Notices</span>
                            </a>
                            <a href="../admin/community_chat" class="mobile-action-tile <?php echo ($current_page === 'community_chat') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-comments text-primary"></i></span>
                                <span class="mobile-tile-label">Estate Forum</span>
                            </a>
                            <a href="../admin/staff" class="mobile-action-tile <?php echo ($current_page === 'staff') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-user-gear text-indigo"></i></span>
                                <span class="mobile-tile-label">Estate Staff</span>
                            </a>
                            <a href="../admin/archives" class="mobile-action-tile <?php echo ($current_page === 'archives') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-box-archive text-slate"></i></span>
                                <span class="mobile-tile-label">Archives Vault</span>
                            </a>
                            <a href="../admin/policies" class="mobile-action-tile <?php echo ($current_page === 'policies') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-gavel text-secondary"></i></span>
                                <span class="mobile-tile-label">Policies &amp; Bylaws</span>
                            </a>
                        </div>
                    </div>

                    <div class="mobile-drawer-section">
                        <div class="mobile-drawer-section-title">Finance &amp; Facilities</div>
                        <div class="mobile-sheet-grid">
                            <a href="../admin/finance" class="mobile-action-tile <?php echo ($current_page === 'finance' || $current_page === 'receipt') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-wallet text-success"></i></span>
                                <span class="mobile-tile-label">Finance Hub</span>
                            </a>
                            <a href="../admin/charges" class="mobile-action-tile <?php echo ($current_page === 'charges') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-tags text-teal"></i></span>
                                <span class="mobile-tile-label">Charge Catalog</span>
                            </a>
                            <a href="../admin/billing_config" class="mobile-action-tile <?php echo ($current_page === 'billing_config') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-sliders text-primary"></i></span>
                                <span class="mobile-tile-label">Billing Config</span>
                            </a>
                            <a href="../admin/maintenance" class="mobile-action-tile <?php echo ($current_page === 'maintenance') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-screwdriver-wrench text-warning"></i></span>
                                <span class="mobile-tile-label">Maintenance</span>
                            </a>
                            <a href="../admin/artisans" class="mobile-action-tile <?php echo ($current_page === 'artisans') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-user-check text-info"></i></span>
                                <span class="mobile-tile-label">Artisan Registry</span>
                                <?php if ($pending_artisan_cnt > 0): ?>
                                    <span class="badge bg-warning text-dark rounded-pill mobile-tile-badge"><?php echo $pending_artisan_cnt; ?> new</span>
                                <?php endif; ?>
                            </a>
                        </div>
                    </div>

                    <div class="mobile-drawer-section">
                        <div class="mobile-drawer-section-title">Security &amp; System</div>
                        <div class="mobile-sheet-grid">
                            <a href="../admin/emergency" class="mobile-action-tile tile-emergency <?php echo ($current_page === 'emergency') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon" style="background: rgba(239, 68, 68, 0.15);"><i class="fa-solid fa-truck-medical text-danger"></i></span>
                                <span class="mobile-tile-label text-danger fw-bold">Emergency &amp; Panic</span>
                            </a>
                            <a href="../admin/incidents" class="mobile-action-tile <?php echo ($current_page === 'incidents') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-book-skull text-danger"></i></span>
                                <span class="mobile-tile-label">Incident Book</span>
                            </a>
                            <a href="../admin/roster" class="mobile-action-tile <?php echo ($current_page === 'roster') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-calendar-check text-teal"></i></span>
                                <span class="mobile-tile-label">Duty Roster</span>
                            </a>
                            <a href="../admin/security" class="mobile-action-tile <?php echo ($current_page === 'security') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-shield-halved text-primary"></i></span>
                                <span class="mobile-tile-label">Gate Passes</span>
                            </a>
                            <a href="../admin/email_logs" class="mobile-action-tile <?php echo ($current_page === 'email_logs') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-envelope-open-text text-secondary"></i></span>
                                <span class="mobile-tile-label">Email Logs</span>
                            </a>
                            <a href="../admin/whatsapp_logs" class="mobile-action-tile <?php echo ($current_page === 'whatsapp_logs') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-brands fa-whatsapp text-success"></i></span>
                                <span class="mobile-tile-label">WhatsApp Logs</span>
                            </a>
                            <a href="../admin/users" class="mobile-action-tile <?php echo ($current_page === 'users') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-user-shield text-secondary"></i></span>
                                <span class="mobile-tile-label">User Accounts</span>
                            </a>
                            <a href="../admin/audit_logs" class="mobile-action-tile <?php echo ($current_page === 'audit_logs') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-clock-rotate-left text-secondary"></i></span>
                                <span class="mobile-tile-label">System Audit</span>
                            </a>
                            <a href="../admin/settings" class="mobile-action-tile <?php echo ($current_page === 'settings') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-sliders text-secondary"></i></span>
                                <span class="mobile-tile-label">Admin Settings</span>
                            </a>
                            <a href="../logout" class="mobile-action-tile">
                                <span class="mobile-tile-icon" style="background: rgba(239, 68, 68, 0.1);"><i class="fa-solid fa-arrow-right-from-bracket text-danger"></i></span>
                                <span class="mobile-tile-label text-danger">Sign Out</span>
                            </a>
                        </div>
                    </div>

                    <div class="mobile-drawer-section">
                        <div class="mobile-drawer-section-title">Portals</div>
                        <div class="mobile-sheet-grid">
                            <a href="../zone/index" target="_blank" class="mobile-action-tile">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-network-wired text-purple"></i></span>
                                <span class="mobile-tile-label">Zone Portal</span>
                            </a>
                            <a href="../resident/index" target="_blank" class="mobile-action-tile">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-house-chimney-user text-primary"></i></span>
                                <span class="mobile-tile-label">Resident Portal</span>
                            </a>
                        </div>
                    </div>

                <!-- ================= STAFF PORTAL ALL PROPERTIES ================= -->
                <?php elseif ($portal === 'staff'): ?>
                    <div class="mobile-drawer-section">
                        <div class="mobile-drawer-section-title">Staff Console</div>
                        <div class="mobile-sheet-grid">
                            <a href="../staff/index" class="mobile-action-tile <?php echo ($current_page === 'index') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-gauge text-primary"></i></span>
                                <span class="mobile-tile-label">Dashboard</span>
                            </a>
                            <a href="../staff/roster" class="mobile-action-tile <?php echo ($current_page === 'roster') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-calendar-check text-teal"></i></span>
                                <span class="mobile-tile-label">Duty Roster</span>
                            </a>
                            <a href="../staff/community_chat" class="mobile-action-tile <?php echo ($current_page === 'community_chat') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-comments text-primary"></i></span>
                                <span class="mobile-tile-label">Estate Forum</span>
                            </a>
                        </div>
                    </div>

                    <div class="mobile-drawer-section">
                        <div class="mobile-drawer-section-title">Security &amp; Gate</div>
                        <div class="mobile-sheet-grid">
                            <a href="../staff/security" class="mobile-action-tile <?php echo ($current_page === 'security') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-shield-halved text-primary"></i></span>
                                <span class="mobile-tile-label">Gate Passes</span>
                            </a>
                            <a href="../staff/incidents" class="mobile-action-tile <?php echo ($current_page === 'incidents') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-book-skull text-danger"></i></span>
                                <span class="mobile-tile-label">Incident Book</span>
                            </a>
                            <a href="../staff/policies" class="mobile-action-tile <?php echo ($current_page === 'policies') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-scale-balanced text-warning"></i></span>
                                <span class="mobile-tile-label">Rules &amp; Fines</span>
                            </a>
                        </div>
                    </div>

                    <div class="mobile-drawer-section">
                        <div class="mobile-drawer-section-title">Finance &amp; Residents</div>
                        <div class="mobile-sheet-grid">
                            <a href="../staff/finance" class="mobile-action-tile <?php echo ($current_page === 'finance') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-file-invoice-dollar text-success"></i></span>
                                <span class="mobile-tile-label">Bills &amp; Receipts</span>
                            </a>
                            <a href="../staff/directory" class="mobile-action-tile <?php echo ($current_page === 'directory') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-address-book text-secondary"></i></span>
                                <span class="mobile-tile-label">Directory</span>
                            </a>
                            <a href="../staff/residents" class="mobile-action-tile <?php echo ($current_page === 'residents') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-users text-sky"></i></span>
                                <span class="mobile-tile-label">Registration</span>
                            </a>
                            <a href="../staff/maintenance" class="mobile-action-tile <?php echo ($current_page === 'maintenance') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-hammer text-warning"></i></span>
                                <span class="mobile-tile-label">Work Orders</span>
                            </a>
                            <a href="../staff/artisans" class="mobile-action-tile <?php echo ($current_page === 'artisans') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-wrench text-info"></i></span>
                                <span class="mobile-tile-label">Verified Artisans</span>
                            </a>
                            <a href="../logout" class="mobile-action-tile">
                                <span class="mobile-tile-icon" style="background: rgba(239, 68, 68, 0.1);"><i class="fa-solid fa-power-off text-danger"></i></span>
                                <span class="mobile-tile-label text-danger">Sign Out</span>
                            </a>
                        </div>
                    </div>

                <!-- ================= ZONE PORTAL ALL PROPERTIES ================= -->
                <?php else: /* zone */ ?>
                    <div class="mobile-drawer-section">
                        <div class="mobile-drawer-section-title">Core Operations</div>
                        <div class="mobile-sheet-grid">
                            <a href="index" class="mobile-action-tile <?php echo ($current_page === 'index') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-chart-pie text-primary"></i></span>
                                <span class="mobile-tile-label">Zone Dashboard</span>
                            </a>
                            <a href="notifications" class="mobile-action-tile <?php echo ($current_page === 'notifications') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-bell text-secondary"></i></span>
                                <span class="mobile-tile-label">Notifications</span>
                                <?php if (($unread_count + $pending_req_cnt) > 0): ?>
                                    <span class="badge bg-danger rounded-pill mobile-tile-badge"><?php echo ($unread_count + $pending_req_cnt); ?></span>
                                <?php endif; ?>
                            </a>
                        </div>
                    </div>

                    <div class="mobile-drawer-section">
                        <div class="mobile-drawer-section-title">Zonal Assets &amp; Directory</div>
                        <div class="mobile-sheet-grid">
                            <a href="properties" class="mobile-action-tile <?php echo ($current_page === 'properties' || $current_page === 'property_details') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-road text-primary"></i></span>
                                <span class="mobile-tile-label">Streets &amp; Properties</span>
                            </a>
                            <a href="residents" class="mobile-action-tile <?php echo ($current_page === 'residents' || $current_page === 'resident_timeline') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-users text-sky"></i></span>
                                <span class="mobile-tile-label">Zone Residents</span>
                                <?php if ($pending_req_cnt > 0): ?>
                                    <span class="badge bg-warning text-dark rounded-pill mobile-tile-badge"><?php echo $pending_req_cnt; ?> req</span>
                                <?php endif; ?>
                            </a>
                            <a href="archives" class="mobile-action-tile <?php echo ($current_page === 'archives') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-box-archive text-slate"></i></span>
                                <span class="mobile-tile-label">Zonal Archives</span>
                            </a>
                            <a href="policies" class="mobile-action-tile <?php echo ($current_page === 'policies') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-gavel text-secondary"></i></span>
                                <span class="mobile-tile-label">Zonal Policies</span>
                            </a>
                            <a href="broadcasts" class="mobile-action-tile <?php echo ($current_page === 'broadcasts') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-bullhorn text-amber"></i></span>
                                <span class="mobile-tile-label">Zone Broadcasts</span>
                            </a>
                            <a href="community_chat" class="mobile-action-tile <?php echo ($current_page === 'community_chat') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-comments text-primary"></i></span>
                                <span class="mobile-tile-label">Estate Forum</span>
                            </a>
                            <a href="emergency" class="mobile-action-tile tile-emergency <?php echo ($current_page === 'emergency') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon" style="background: rgba(239, 68, 68, 0.15);"><i class="fa-solid fa-truck-medical text-danger"></i></span>
                                <span class="mobile-tile-label text-danger fw-bold">Emergency SOS Hub</span>
                            </a>
                            <a href="directory" class="mobile-action-tile <?php echo ($current_page === 'directory') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-address-book text-secondary"></i></span>
                                <span class="mobile-tile-label">Directory</span>
                            </a>
                            <a href="artisans" class="mobile-action-tile <?php echo ($current_page === 'artisans') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-wrench text-info"></i></span>
                                <span class="mobile-tile-label">Artisans</span>
                            </a>
                            <a href="owners" class="mobile-action-tile <?php echo ($current_page === 'owners') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-user-tie text-purple"></i></span>
                                <span class="mobile-tile-label">Property Owners</span>
                            </a>
                        </div>
                    </div>

                    <div class="mobile-drawer-section">
                        <div class="mobile-drawer-section-title">Local Billing &amp; Reports</div>
                        <div class="mobile-sheet-grid">
                            <a href="charges" class="mobile-action-tile <?php echo ($current_page === 'charges') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-list-check text-teal"></i></span>
                                <span class="mobile-tile-label">Local Levies</span>
                            </a>
                            <a href="billing_config" class="mobile-action-tile <?php echo ($current_page === 'billing_config') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-sliders text-primary"></i></span>
                                <span class="mobile-tile-label">Billing Setup</span>
                            </a>
                            <a href="finance" class="mobile-action-tile <?php echo ($current_page === 'finance' || $current_page === 'receipt') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-file-invoice-dollar text-success"></i></span>
                                <span class="mobile-tile-label">Invoices &amp; Billing</span>
                            </a>
                            <a href="reports" class="mobile-action-tile <?php echo ($current_page === 'reports') ? 'active' : ''; ?>">
                                <span class="mobile-tile-icon"><i class="fa-solid fa-chart-simple text-indigo"></i></span>
                                <span class="mobile-tile-label">Zonal Reports</span>
                            </a>
                            <a href="../logout" class="mobile-action-tile" style="grid-column: span 2;">
                                <span class="mobile-tile-icon" style="background: rgba(239, 68, 68, 0.1);"><i class="fa-solid fa-right-from-bracket text-danger"></i></span>
                                <span class="mobile-tile-label text-danger">Sign Out</span>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

/**
 * Clean & Understated Quick Actions with Direct SOS & Property Shortcuts
 */
if (!function_exists('renderMobileSidebarIconsGrid')) {
    function renderMobileSidebarIconsGrid($portal = 'resident') {
        ?>
        <div class="mobile-only mt-3 mb-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--mob-text-muted);">Quick Access</span>
                <a href="javascript:void(0)" onclick="openMobileActionSheet()" style="font-size: 0.78rem; font-weight: 600; color: var(--mob-primary); text-decoration: none;">
                    All Navigation &rarr;
                </a>
            </div>
            <div class="d-flex gap-2 overflow-x-auto pb-1" style="scrollbar-width: none; -webkit-overflow-scrolling: touch;">
                <?php if ($portal === 'resident'): ?>
                    <a href="emergency" class="btn btn-sm btn-outline-danger mobile-quick-btn fw-bold">
                        <i class="fa-solid fa-truck-medical text-danger"></i> SOS Alarm
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-danger mobile-quick-btn fw-bold estate-hotline-btn" title="Call Emergency Hotlines">
                        <i class="fa-solid fa-phone-volume text-danger hotline-pulse-icon"></i> Hotlines
                    </button>
                    <a href="visitors" class="btn btn-sm btn-outline-secondary mobile-quick-btn">
                        <i class="fa-solid fa-id-card-clip text-info"></i> Issue Pass
                    </a>
                    <a href="property" class="btn btn-sm btn-outline-secondary mobile-quick-btn">
                        <i class="fa-solid fa-building-user text-primary"></i> My Property
                    </a>
                    <a href="finance" class="btn btn-sm btn-outline-secondary mobile-quick-btn">
                        <i class="fa-solid fa-wallet text-success"></i> Pay Bills
                    </a>
                <?php elseif ($portal === 'admin'): ?>
                    <a href="../admin/emergency" class="btn btn-sm btn-outline-danger mobile-quick-btn fw-bold">
                        <i class="fa-solid fa-truck-medical text-danger"></i> SOS Alarm
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-danger mobile-quick-btn fw-bold estate-hotline-btn" title="Call Emergency Hotlines">
                        <i class="fa-solid fa-phone-volume text-danger hotline-pulse-icon"></i> Hotlines
                    </button>
                    <a href="../admin/properties" class="btn btn-sm btn-outline-secondary mobile-quick-btn">
                        <i class="fa-solid fa-city text-primary"></i> Properties
                    </a>
                    <a href="../admin/residents" class="btn btn-sm btn-outline-secondary mobile-quick-btn">
                        <i class="fa-solid fa-users text-info"></i> Residents
                    </a>
                    <a href="../admin/security" class="btn btn-sm btn-outline-secondary mobile-quick-btn">
                        <i class="fa-solid fa-shield-halved text-success"></i> Gate Pass
                    </a>
                <?php elseif ($portal === 'staff'): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger mobile-quick-btn fw-bold estate-hotline-btn" title="Call Emergency Hotlines">
                        <i class="fa-solid fa-phone-volume text-danger hotline-pulse-icon"></i> Hotlines
                    </button>
                    <a href="../staff/incidents" class="btn btn-sm btn-outline-danger mobile-quick-btn fw-bold">
                        <i class="fa-solid fa-truck-medical text-danger"></i> Incidents
                    </a>
                    <a href="../staff/security" class="btn btn-sm btn-outline-secondary mobile-quick-btn">
                        <i class="fa-solid fa-qrcode text-primary"></i> Scan Pass
                    </a>
                    <a href="../staff/maintenance" class="btn btn-sm btn-outline-secondary mobile-quick-btn">
                        <i class="fa-solid fa-screwdriver-wrench text-warning"></i> Work Orders
                    </a>
                    <a href="../staff/roster" class="btn btn-sm btn-outline-secondary mobile-quick-btn">
                        <i class="fa-solid fa-calendar-check text-success"></i> Duty Roster
                    </a>
                <?php else: /* zone */ ?>
                    <a href="emergency" class="btn btn-sm btn-outline-danger mobile-quick-btn fw-bold">
                        <i class="fa-solid fa-truck-medical text-danger"></i> SOS Alarm
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-danger mobile-quick-btn fw-bold estate-hotline-btn" title="Call Emergency Hotlines">
                        <i class="fa-solid fa-phone-volume text-danger hotline-pulse-icon"></i> Hotlines
                    </button>
                    <a href="properties" class="btn btn-sm btn-outline-secondary mobile-quick-btn">
                        <i class="fa-solid fa-city text-primary"></i> Properties
                    </a>
                    <a href="residents" class="btn btn-sm btn-outline-secondary mobile-quick-btn">
                        <i class="fa-solid fa-users text-info"></i> Residents
                    </a>
                    <a href="broadcasts" class="btn btn-sm btn-outline-secondary mobile-quick-btn">
                        <i class="fa-solid fa-bullhorn text-warning"></i> Broadcast
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
?>
