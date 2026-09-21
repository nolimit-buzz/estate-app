<?php
// includes/policy_init.php
// Auto-provisions Estate Policy, Rules & Regulations, Offences & Punishments Engine

if (!function_exists('initPolicyAndOffenceTables')) {
    function initPolicyAndOffenceTables($conn) {
        if (!$conn || !($conn instanceof mysqli)) return;

        // 1. Policy Categories Table
        $conn->query("CREATE TABLE IF NOT EXISTS estate_policy_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            estate_id INT NOT NULL DEFAULT 1,
            slug VARCHAR(100) NOT NULL,
            name VARCHAR(150) NOT NULL,
            icon VARCHAR(60) DEFAULT 'fa-gavel',
            color VARCHAR(30) DEFAULT '#3b82f6',
            description VARCHAR(255) NULL,
            display_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_estate (estate_id),
            INDEX idx_slug (slug),
            UNIQUE KEY uk_estate_slug (estate_id, slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 2. Estate Policies, Rules, Offences & Punishments Table
        $conn->query("CREATE TABLE IF NOT EXISTS estate_policies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            estate_id INT NOT NULL DEFAULT 1,
            zone_id INT NULL,
            scope ENUM('central', 'zonal') NOT NULL DEFAULT 'central',
            category_slug VARCHAR(100) NOT NULL,
            code VARCHAR(50) NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            offence_definition TEXT NULL,
            punishment_type ENUM('warning', 'fine', 'clamping_towing', 'privilege_suspension', 'gate_restriction', 'community_service', 'legal_eviction', 'other') DEFAULT 'warning',
            punishment_details TEXT NULL,
            fine_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
            repeat_offence_penalty TEXT NULL,
            severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
            enforcement_entity VARCHAR(120) DEFAULT 'Estate Security & Management',
            status ENUM('active', 'inactive', 'under_review') DEFAULT 'active',
            display_order INT DEFAULT 0,
            effective_date DATE NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_estate (estate_id),
            INDEX idx_scope (scope),
            INDEX idx_zone (zone_id),
            INDEX idx_category (category_slug),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Seed default categories if empty
        $cat_check = $conn->query("SELECT COUNT(id) as cnt FROM estate_policy_categories WHERE estate_id = 1");
        $cat_cnt = ($cat_check && $row = $cat_check->fetch_assoc()) ? intval($row['cnt']) : 0;

        if ($cat_cnt == 0) {
            $categories = [
                ['security', 'Security & Gate Access Control', 'fa-shield-halved', '#ef4444', 'Rules concerning access control, visitor validation, gate passes, perimeter security, and checkpoint compliance.', 1],
                ['traffic', 'Traffic, Parking & Speed Regulations', 'fa-car-side', '#f59e0b', 'Estate speed limits, designated parking slots, visitor parking, anti-blocking, and vehicle clamping.', 2],
                ['noise', 'Noise Control & Peaceful Living', 'fa-volume-xmark', '#8b5cf6', 'Estate quiet hours, decibel levels, generator noise restrictions, party permits, and neighbor tranquility.', 3],
                ['sanitation', 'Sanitation & Environmental Waste', 'fa-recycle', '#10b981', 'Garbage collection schedules, standard bin bagging, hazardous waste, littering, and lawn maintenance.', 4],
                ['pets', 'Pets & Domestic Animals', 'fa-paw', '#ec4899', 'Leash requirements, vaccination certificates, dangerous dog breeds, waste cleanup, and barking nuisance.', 5],
                ['construction', 'Building & Structural Alterations', 'fa-trowel-bricks', '#6366f1', 'Permitted renovation hours, architectural permits, heavy truck access, scaffolding, and site cleanliness.', 6],
                ['amenities', 'Common Amenities & Recreational Facilities', 'fa-swimming-pool', '#06b6d4', 'Usage rules for clubhouse, sports facilities, swimming pool, children play areas, and facility bookings.', 7],
                ['levies', 'Levies, Fines & Financial Compliance', 'fa-money-bill-transfer', '#14b8a6', 'Estate development dues, utility payment deadlines, penalty surcharges, and default enforcement.', 8],
                ['conduct', 'Resident Conduct & Tenancy Ethics', 'fa-handshake-angle', '#3b82f6', 'Ethical neighbor relations, anti-harassment rules, domestic worker documentation, and disputes.', 9]
            ];

            $stmt = $conn->prepare("INSERT IGNORE INTO estate_policy_categories (estate_id, slug, name, icon, color, description, display_order) VALUES (1, ?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                foreach ($categories as $cat) {
                    $stmt->bind_param("sssssi", $cat[0], $cat[1], $cat[2], $cat[3], $cat[4], $cat[5]);
                    $stmt->execute();
                }
                $stmt->close();
            }
        }

        // Seed default baseline Central Policies & Zonal Bylaws if empty
        $pol_check = $conn->query("SELECT COUNT(id) as cnt FROM estate_policies WHERE estate_id = 1");
        $pol_cnt = ($pol_check && $row = $pol_check->fetch_assoc()) ? intval($row['cnt']) : 0;

        if ($pol_cnt == 0) {
            $policies = [
                // CENTRAL POLICIES (Estate-Wide)
                [
                    'scope' => 'central',
                    'zone_id' => null,
                    'category_slug' => 'traffic',
                    'code' => 'EST-TRF-001',
                    'title' => 'Estate Maximum Speed Limit (20 km/h)',
                    'description' => 'A strict maximum speed limit of 20 km/h applies uniformly across all paved estate boulevards, internal avenues, and intersections. Pedestrians and children always possess right of way.',
                    'offence_definition' => 'Driving any motor vehicle, motorcycle, or electric scooter in excess of 20 km/h or driving recklessly in residential lanes.',
                    'punishment_type' => 'fine',
                    'punishment_details' => 'First violation incurs an instant administrative fine of ₦15,000 charged to property ledger and immediate electronic warning flag.',
                    'fine_amount' => 15000.00,
                    'repeat_offence_penalty' => 'Second offence: ₦30,000 fine. Third offence: ₦50,000 fine plus mandatory temporary suspension of automated gate vehicle transponder/pass for 14 days.',
                    'severity' => 'high',
                    'enforcement_entity' => 'Estate Security Patrol & Speed Radar Camera Team'
                ],
                [
                    'scope' => 'central',
                    'zone_id' => null,
                    'category_slug' => 'traffic',
                    'code' => 'EST-TRF-002',
                    'title' => 'Illegal Parking & Obstruction of Access Ways',
                    'description' => 'All residents and visitors must park strictly in designated driveways or approved bays. Parking on sidewalks, curbs, green verges, blocking hydrants, or obstructing neighboring driveways is strictly prohibited.',
                    'offence_definition' => 'Parking a vehicle so as to block traffic flow, obstruct gates/driveways, or park on marked yellow no-parking zones.',
                    'punishment_type' => 'clamping_towing',
                    'punishment_details' => 'Immediate vehicle wheel-clamping or towing to the central estate impound depot. De-clamping fee of ₦20,000 must be cleared before release.',
                    'fine_amount' => 20000.00,
                    'repeat_offence_penalty' => 'Repeat offences incur a compounding de-clamping fee of ₦35,000 and towing costs if moved.',
                    'severity' => 'high',
                    'enforcement_entity' => 'Central Traffic Enforcement & Towing Unit'
                ],
                [
                    'scope' => 'central',
                    'zone_id' => null,
                    'category_slug' => 'security',
                    'code' => 'EST-SEC-001',
                    'title' => 'Mandatory Gate Visitor Access Clearance',
                    'description' => 'All external visitors, delivery couriers, ride-hailing cabs, and artisans must be pre-cleared via the Resident Portal visitor pass system. Security guards at main gates are prohibited from admitting unverified entrants.',
                    'offence_definition' => 'Instructing visitors to force entry, verbally abusing gate guards, tailgating behind authorized cars, or providing false gate credentials.',
                    'punishment_type' => 'fine',
                    'punishment_details' => '₦25,000 fine levied against host resident and immediate blacklisting of the vehicle.',
                    'fine_amount' => 25000.00,
                    'repeat_offence_penalty' => 'Subsequent violations incur ₦50,000 fine and requirement for in-person escort at the central security gatehouse.',
                    'severity' => 'critical',
                    'enforcement_entity' => 'Chief Security Officer & Gate Commands'
                ],
                [
                    'scope' => 'central',
                    'zone_id' => null,
                    'category_slug' => 'noise',
                    'code' => 'EST-NOI-001',
                    'title' => 'Estate Quiet Hours & Anti-Noise Nuisance Rule',
                    'description' => 'Estate quiet hours are strictly observed from 10:00 PM to 06:00 AM on weekdays, and 11:00 PM to 07:00 AM on weekends and public holidays. During quiet hours, amplified music, outdoor loudspeakers, and high-decibel disturbances are prohibited.',
                    'offence_definition' => 'Emitting unreasonable noise, playing loud musical equipment, running non-soundproofed heavy generators during quiet hours without emergency clearance.',
                    'punishment_type' => 'fine',
                    'punishment_details' => 'First incident: Verbal/SMS warning by patrol. Unheeded or repeated noise within 1 hour incurs an administrative penalty of ₦20,000.',
                    'fine_amount' => 20000.00,
                    'repeat_offence_penalty' => 'Second occurrence: ₦40,000 fine. Further occurrences will lead to sound equipment confiscation by security and report to state environmental authorities.',
                    'severity' => 'medium',
                    'enforcement_entity' => 'Estate Night Patrol & Environmental Committee'
                ],
                [
                    'scope' => 'central',
                    'zone_id' => null,
                    'category_slug' => 'sanitation',
                    'code' => 'EST-SAN-001',
                    'title' => 'Waste Disposal, Standard Bin Bagging & Anti-Littering Policy',
                    'description' => 'All household domestic waste must be securely bagged in heavy-duty bin liners and placed inside covered waste bins. Open dumping of refuse on road corridors, empty plots, or storm drains is strictly prohibited.',
                    'offence_definition' => 'Dumping loose refuse, littering streets, discharging sewage/greywater onto roads, or leaving overflowing bins unemptied.',
                    'punishment_type' => 'fine',
                    'punishment_details' => 'Administrative environmental sanitation fine of ₦25,000 plus the full cost of commercial cleanup crew dispatch.',
                    'fine_amount' => 25000.00,
                    'repeat_offence_penalty' => 'Repeat violation within 90 days attracts double fine of ₦50,000.',
                    'severity' => 'medium',
                    'enforcement_entity' => 'Environmental Health & Sanitation Unit'
                ],
                [
                    'scope' => 'central',
                    'zone_id' => null,
                    'category_slug' => 'pets',
                    'code' => 'EST-PET-001',
                    'title' => 'Pet Restraint, Leash Requirement & Waste Cleanup',
                    'description' => 'All dogs and domestic pets in public estate zones, sidewalks, and parks must be on a sturdy leash held by a capable handler. Handlers must carry waste bags and immediately clean up pet waste.',
                    'offence_definition' => 'Allowing dogs to roam unleashed in public areas, failure to scoop pet droppings, or keeping aggressive/unvaccinated animals.',
                    'punishment_type' => 'fine',
                    'punishment_details' => 'Fine of ₦15,000 per violation. Roaming unattended animals will be impounded by animal control.',
                    'fine_amount' => 15000.00,
                    'repeat_offence_penalty' => 'Repeat offence attracts ₦30,000 fine; persistent aggressive animal incidents will trigger mandatory permanent pet eviction from the estate.',
                    'severity' => 'medium',
                    'enforcement_entity' => 'Estate Security & Animal Welfare Desk'
                ],
                [
                    'scope' => 'central',
                    'zone_id' => null,
                    'category_slug' => 'construction',
                    'code' => 'EST-CON-001',
                    'title' => 'Building Modifications, Architectural Approvals & Work Timings',
                    'description' => 'No structural alterations, external facade modifications, painting, or heavy construction may proceed without prior written approval from the Estate Planning & Works Committee. Construction work is permitted strictly Monday - Saturday, 08:00 AM - 05:00 PM.',
                    'offence_definition' => 'Carrying out building works on Sundays, operating past 5:00 PM, blocking roads with sand/gravel, or building without approved architectural plans.',
                    'punishment_type' => 'fine',
                    'punishment_details' => 'Immediate Stop-Work Order sealed by security, denial of artisan gate access, and a contravention fee of ₦50,000.',
                    'fine_amount' => 50000.00,
                    'repeat_offence_penalty' => '₦100,000 penalty plus demolition/remediation of non-compliant structures at owner\'s expense.',
                    'severity' => 'critical',
                    'enforcement_entity' => 'Central Engineering & Physical Planning Bureau'
                ],
                [
                    'scope' => 'central',
                    'zone_id' => null,
                    'category_slug' => 'levies',
                    'code' => 'EST-FIN-001',
                    'title' => 'Estate Service Charge Compliance & Default Sanctions',
                    'description' => 'All residents and landlords are obligated to remit monthly or annual estate development levies and service charges within 15 calendar days of invoice generation to maintain estate utilities and security services.',
                    'offence_definition' => 'Non-payment of mandatory service charges beyond 30 days past the due date without an approved hardship installment plan.',
                    'punishment_type' => 'gate_restriction',
                    'punishment_details' => 'Late payment surcharge of 5% per month, restriction of automated vehicle gate transponder (relegated to manual visitor lane clearance), and suspension of club amenities.',
                    'fine_amount' => 10000.00,
                    'repeat_offence_penalty' => 'Continued default exceeding 60 days attracts legal recovery action and publication on the default registry.',
                    'severity' => 'high',
                    'enforcement_entity' => 'Central Revenue & Accounts Office'
                ],
                
                // ZONAL BYLAWS (Zone-Specific Local Sector Regulations)
                [
                    'scope' => 'zonal',
                    'zone_id' => 1,
                    'category_slug' => 'traffic',
                    'code' => 'ZN1-TRF-001',
                    'title' => 'Zone 1: Cul-de-Sac & Inner Crescent Parking Rule',
                    'description' => 'Due to narrow turning radii in Zone 1 crescents, parking is permitted only on the even-numbered side of the street. Overnight parking in turning circles is forbidden to ensure fire engine access.',
                    'offence_definition' => 'Parking vehicle on odd-numbered curbs or inside the cul-de-sac turning circle in Zone 1.',
                    'punishment_type' => 'clamping_towing',
                    'punishment_details' => 'Local sector wheel-clamping with a ₦15,000 release surcharge paid into the Zone 1 development purse.',
                    'fine_amount' => 15000.00,
                    'repeat_offence_penalty' => '₦25,000 fine on second violation.',
                    'severity' => 'medium',
                    'enforcement_entity' => 'Zone 1 Sector Marshal & Security'
                ],
                [
                    'scope' => 'zonal',
                    'zone_id' => 1,
                    'category_slug' => 'sanitation',
                    'code' => 'ZN1-SAN-001',
                    'title' => 'Zone 1: Designated Curbside Trash Bin Timings',
                    'description' => 'In Zone 1, communal trash compactors arrive Tuesdays and Fridays between 07:00 AM and 10:00 AM. Residents must wheel their bins to the curb only between 06:00 AM and 07:00 AM and retrieve them before 12:00 PM.',
                    'offence_definition' => 'Leaving trash bins on curbsides overnight or on non-collection days causing foul odor.',
                    'punishment_type' => 'fine',
                    'punishment_details' => '₦5,000 sanitation violation levy per occurrence.',
                    'fine_amount' => 5000.00,
                    'repeat_offence_penalty' => '₦10,000 fine for subsequent infractions.',
                    'severity' => 'low',
                    'enforcement_entity' => 'Zone 1 Sanitation Committee'
                ],
                [
                    'scope' => 'zonal',
                    'zone_id' => 2,
                    'category_slug' => 'traffic',
                    'code' => 'ZN2-TRF-001',
                    'title' => 'Zone 2: Heavy Construction Truck Tonnage Restriction',
                    'description' => 'Heavy delivery articulated trucks exceeding 15 tons are prohibited from traversing Zone 2 residential pavement after 04:00 PM or during rainy days to prevent road base collapse.',
                    'offence_definition' => 'Navigating heavy trailers or tippers into Zone 2 past the restriction curfew.',
                    'punishment_type' => 'fine',
                    'punishment_details' => 'Driver will be turned back at Zone 2 gate barrier and host resident charged ₦30,000.',
                    'fine_amount' => 30000.00,
                    'repeat_offence_penalty' => '₦60,000 fine and assessment for road asphalt surface damage.',
                    'severity' => 'high',
                    'enforcement_entity' => 'Zone 2 Gate Marshals'
                ]
            ];

            $stmt = $conn->prepare("INSERT INTO estate_policies (
                estate_id, zone_id, scope, category_slug, code, title, description, 
                offence_definition, punishment_type, punishment_details, fine_amount, 
                repeat_offence_penalty, severity, enforcement_entity, status, display_order
            ) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)");

            if ($stmt) {
                $order = 1;
                foreach ($policies as $p) {
                    $z_val = $p['zone_id'];
                    $stmt->bind_param(
                        "issssssssdsssi",
                        $z_val,
                        $p['scope'],
                        $p['category_slug'],
                        $p['code'],
                        $p['title'],
                        $p['description'],
                        $p['offence_definition'],
                        $p['punishment_type'],
                        $p['punishment_details'],
                        $p['fine_amount'],
                        $p['repeat_offence_penalty'],
                        $p['severity'],
                        $p['enforcement_entity'],
                        $order
                    );
                    $stmt->execute();
                    $order++;
                }
                $stmt->close();
            }
        }
    }
}

// -------------------------------------------------------------
// HELPER FUNCTIONS FOR POLICIES
// -------------------------------------------------------------

if (!function_exists('getPolicyCategories')) {
    function getPolicyCategories($conn, $estate_id = 1) {
        $estate_id = intval($estate_id);
        $categories = [];
        $res = $conn->query("SELECT * FROM estate_policy_categories WHERE estate_id = $estate_id ORDER BY display_order ASC, name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $categories[$row['slug']] = $row;
            }
        }
        return $categories;
    }
}

if (!function_exists('getEstatePoliciesList')) {
    function getEstatePoliciesList($conn, $estate_id = 1, $filters = []) {
        $estate_id = intval($estate_id);
        $where = ["p.estate_id = $estate_id"];

        if (!empty($filters['scope'])) {
            $scope = $conn->real_escape_string($filters['scope']);
            $where[] = "p.scope = '$scope'";
        }

        if (isset($filters['zone_id']) && $filters['zone_id'] !== '' && $filters['zone_id'] !== null) {
            $zone_id = intval($filters['zone_id']);
            $where[] = "p.zone_id = $zone_id";
        }

        if (!empty($filters['category_slug'])) {
            $cat = $conn->real_escape_string($filters['category_slug']);
            $where[] = "p.category_slug = '$cat'";
        }

        if (!empty($filters['status'])) {
            $st = $conn->real_escape_string($filters['status']);
            $where[] = "p.status = '$st'";
        }

        if (!empty($filters['search'])) {
            $s = $conn->real_escape_string($filters['search']);
            $where[] = "(p.title LIKE '%$s%' OR p.code LIKE '%$s%' OR p.description LIKE '%$s%' OR p.offence_definition LIKE '%$s%' OR p.punishment_details LIKE '%$s%')";
        }

        $where_sql = implode(' AND ', $where);

        $sql = "SELECT p.*, c.name as category_name, c.icon as category_icon, c.color as category_color,
                       z.name as zone_name, z.code as zone_code
                FROM estate_policies p
                LEFT JOIN estate_policy_categories c ON p.category_slug = c.slug AND c.estate_id = p.estate_id
                LEFT JOIN zones z ON p.zone_id = z.id
                WHERE $where_sql
                ORDER BY p.scope ASC, p.display_order ASC, p.id DESC";

        $res = $conn->query($sql);
        $list = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $list[] = $row;
            }
        }
        return $list;
    }
}

if (!function_exists('formatPunishmentTypeBadge')) {
    function formatPunishmentTypeBadge($type) {
        $map = [
            'warning' => ['bg' => '#fef3c7', 'text' => '#b45309', 'icon' => 'fa-triangle-exclamation', 'label' => 'Official Warning'],
            'fine' => ['bg' => '#fee2e2', 'text' => '#b91c1c', 'icon' => 'fa-money-bill-wave', 'label' => 'Monetary Fine'],
            'clamping_towing' => ['bg' => '#ffedd5', 'text' => '#c2410c', 'icon' => 'fa-truck-pickup', 'label' => 'Clamping & Towing'],
            'privilege_suspension' => ['bg' => '#f3e8ff', 'text' => '#7e22ce', 'icon' => 'fa-ban', 'label' => 'Privilege Suspension'],
            'gate_restriction' => ['bg' => '#e0e7ff', 'text' => '#4338ca', 'icon' => 'fa-torii-gate', 'label' => 'Gate Barcode Revoked'],
            'community_service' => ['bg' => '#dcfce7', 'text' => '#15803d', 'icon' => 'fa-hands-holding-child', 'label' => 'Community Service'],
            'legal_eviction' => ['bg' => '#fecdd3', 'text' => '#9f1239', 'icon' => 'fa-gavel', 'label' => 'Legal Eviction'],
            'other' => ['bg' => '#f1f5f9', 'text' => '#475569', 'icon' => 'fa-scale-balanced', 'label' => 'Disciplinary Action']
        ];
        $cfg = $map[$type] ?? $map['other'];
        return '<span class="badge d-inline-flex align-items-center gap-1.5 px-2.5 py-1 text-xs font-semibold rounded-pill" style="background: ' . $cfg['bg'] . '; color: ' . $cfg['text'] . '; border: 1px solid ' . $cfg['text'] . '33;"><i class="fa-solid ' . $cfg['icon'] . '"></i> ' . $cfg['label'] . '</span>';
    }
}

if (!function_exists('formatSeverityBadge')) {
    function formatSeverityBadge($severity) {
        $map = [
            'low' => ['class' => 'bg-info bg-opacity-10 text-info border border-info border-opacity-25', 'icon' => 'fa-circle-info', 'label' => 'Low Severity'],
            'medium' => ['class' => 'bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25', 'icon' => 'fa-triangle-exclamation', 'label' => 'Medium Severity'],
            'high' => ['class' => 'bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25', 'icon' => 'fa-circle-exclamation', 'label' => 'High Severity'],
            'critical' => ['class' => 'bg-danger text-white border border-danger', 'icon' => 'fa-skull-crossbones', 'label' => 'CRITICAL VIOLATION']
        ];
        $cfg = $map[$severity] ?? $map['medium'];
        return '<span class="badge d-inline-flex align-items-center gap-1.5 px-2.5 py-1 text-xs font-semibold rounded-pill ' . $cfg['class'] . '"><i class="fa-solid ' . $cfg['icon'] . '"></i> ' . $cfg['label'] . '</span>';
    }
}

// Auto-run initialization when loaded with active DB connection
if (isset($conn) && $conn instanceof mysqli) {
    initPolicyAndOffenceTables($conn);
}
