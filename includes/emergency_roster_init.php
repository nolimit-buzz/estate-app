<?php
// includes/emergency_roster_init.php
// Automatically provisions security roster and dynamic emergency tables

if (!function_exists('getEmergencySetting')) {
    function getEmergencySetting($conn, $estate_id, $key, $default = '') {
        if (!$conn || !($conn instanceof mysqli)) return $default;
        $res = $conn->query("SELECT setting_value FROM system_settings WHERE estate_id = $estate_id AND setting_key = '$key' LIMIT 1");
        if ($res && $row = $res->fetch_assoc()) {
            return $row['setting_value'];
        }
        return $default;
    }
}

if (!function_exists('initEmergencyAndRosterTables')) {
function initEmergencyAndRosterTables($conn) {
    if (!$conn || !($conn instanceof mysqli)) return;

    // 1. Security Posts (Gates, Patrol Units, Watchtowers, Command Centers)
    $conn->query("CREATE TABLE IF NOT EXISTS security_posts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        estate_id INT DEFAULT 1,
        post_name VARCHAR(100) NOT NULL,
        location_description VARCHAR(255) NULL,
        phone_extension VARCHAR(50) NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(estate_id),
        INDEX(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 2. Security Shift Templates (Morning, Afternoon, Night, 24-hr)
    $conn->query("CREATE TABLE IF NOT EXISTS security_shifts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        estate_id INT DEFAULT 1,
        name VARCHAR(100) NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        color_code VARCHAR(30) DEFAULT '#2563eb',
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(estate_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 3. Security Duty Roster (Records who is on duty, when, and where)
    $conn->query("CREATE TABLE IF NOT EXISTS security_roster (
        id INT AUTO_INCREMENT PRIMARY KEY,
        estate_id INT DEFAULT 1,
        user_id INT NOT NULL,
        staff_id INT NULL,
        post_id INT NULL,
        shift_id INT NULL,
        duty_date DATE NOT NULL,
        start_datetime DATETIME NOT NULL,
        end_datetime DATETIME NOT NULL,
        status ENUM('scheduled', 'on_duty', 'completed', 'absent', 'swapped', 'excused') DEFAULT 'scheduled',
        clock_in_time DATETIME NULL,
        clock_out_time DATETIME NULL,
        alerted_at DATETIME NULL,
        handover_notes TEXT NULL,
        supervisor_id INT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(estate_id, duty_date),
        INDEX(estate_id, start_datetime, end_datetime),
        INDEX(user_id),
        INDEX(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 4. Estate Emergency Categories (100% Dynamic, Estate-Configurable)
    $conn->query("CREATE TABLE IF NOT EXISTS estate_emergency_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        estate_id INT DEFAULT 1,
        category_name VARCHAR(100) NOT NULL,
        color VARCHAR(30) DEFAULT '#dc2626',
        use_case_description TEXT NOT NULL,
        priority ENUM('critical', 'high', 'medium') DEFAULT 'critical',
        target_stakeholders ENUM('all_residents', 'guards_only', 'guards_and_admin', 'guards_and_medical') DEFAULT 'all_residents',
        emergency_contact_phone VARCHAR(50) NULL,
        display_order INT DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(estate_id),
        INDEX(is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add target_stakeholders column to estate_emergency_categories if missing
    $c_target_chk = $conn->query("SHOW COLUMNS FROM estate_emergency_categories LIKE 'target_stakeholders'");
    if ($c_target_chk && $c_target_chk->num_rows == 0) {
        $conn->query("ALTER TABLE estate_emergency_categories ADD COLUMN target_stakeholders ENUM('all_residents', 'guards_only', 'guards_and_admin', 'guards_and_medical') DEFAULT 'all_residents' AFTER priority");
    }

    // 5. Estate Emergency Contacts / Hotlines (Configured Direct Dial Numbers)
    $conn->query("CREATE TABLE IF NOT EXISTS estate_emergency_contacts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        estate_id INT DEFAULT 1,
        label VARCHAR(100) NOT NULL,
        phone_number VARCHAR(50) NOT NULL,
        contact_type ENUM('internal_security', 'medical', 'fire', 'police', 'management', 'general') DEFAULT 'internal_security',
        is_primary TINYINT(1) DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        display_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(estate_id),
        INDEX(is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 6. Estate Emergency Response Actions (Configurable Action Taken Templates)
    $conn->query("CREATE TABLE IF NOT EXISTS estate_emergency_actions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        estate_id INT DEFAULT 1,
        action_title VARCHAR(150) NOT NULL,
        description VARCHAR(255) NULL,
        target_status ENUM('resolved', 'dispatched', 'acknowledged', 'false_alarm') DEFAULT 'resolved',
        display_order INT DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(estate_id),
        INDEX(is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 7. Estate Emergency & Panic Alerts (Incidents & Broadcasts)
    $conn->query("CREATE TABLE IF NOT EXISTS estate_emergency_alerts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        estate_id INT DEFAULT 1,
        alert_code VARCHAR(50) NOT NULL,
        sender_type ENUM('resident', 'zone_admin', 'central_admin') NOT NULL DEFAULT 'resident',
        sender_id INT NOT NULL,
        sender_name VARCHAR(150) NULL,
        sender_phone VARCHAR(50) NULL,
        target_scope ENUM('estate_wide', 'zone') DEFAULT 'estate_wide',
        target_stakeholders ENUM('all_residents', 'guards_only', 'guards_and_admin', 'guards_and_medical') DEFAULT 'all_residents',
        zone_id INT NULL,
        flat_id INT NULL,
        building_name VARCHAR(150) NULL,
        flat_number VARCHAR(50) NULL,
        category_id INT NULL,
        category_name VARCHAR(100) NOT NULL,
        headline VARCHAR(255) NULL,
        note TEXT NULL,
        latitude DECIMAL(10, 8) NULL,
        longitude DECIMAL(11, 8) NULL,
        status ENUM('active', 'acknowledged', 'dispatched', 'resolved', 'false_alarm') DEFAULT 'active',
        sound_alarm TINYINT(1) DEFAULT 1,
        acknowledged_by INT NULL,
        acknowledged_at DATETIME NULL,
        resolved_by INT NULL,
        resolved_at DATETIME NULL,
        resolution_action VARCHAR(150) NULL,
        resolution_notes TEXT NULL,
        broadcast_sent TINYINT(1) DEFAULT 0,
        email_sent TINYINT(1) DEFAULT 0,
        security_officers_on_duty_snapshot LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(estate_id, status),
        INDEX(sender_type, sender_id),
        INDEX(target_scope, zone_id),
        INDEX(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 8. Security Occurrence Book & Incident Log (OB Book & Bad Behavior Tracking)
    $conn->query("CREATE TABLE IF NOT EXISTS security_incidents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        estate_id INT DEFAULT 1,
        incident_ref VARCHAR(50) NOT NULL,
        reporter_id INT NOT NULL,
        reporter_name VARCHAR(150) NULL,
        reporter_role VARCHAR(100) NULL,
        incident_type ENUM(
            'bad_behavior', 
            'noise_disturbance', 
            'speeding_traffic', 
            'unauthorized_entry', 
            'suspicious_activity', 
            'property_damage', 
            'theft_burglary', 
            'altercation_fight', 
            'access_denied', 
            'lost_found', 
            'general_occurrence'
        ) DEFAULT 'general_occurrence',
        severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
        location_or_post VARCHAR(150) NOT NULL,
        incident_datetime DATETIME NOT NULL,
        entity_type ENUM('resident', 'visitor', 'staff_contractor', 'unknown_third_party') DEFAULT 'resident',
        target_resident_id INT NULL,
        target_resident_name VARCHAR(150) NULL,
        target_unit_or_address VARCHAR(150) NULL,
        target_visitor_name VARCHAR(150) NULL,
        target_visitor_phone VARCHAR(50) NULL,
        target_pass_code VARCHAR(50) NULL,
        vehicle_reg_plate VARCHAR(50) NULL,
        is_flagged_bad_behavior TINYINT(1) DEFAULT 0,
        flag_reason VARCHAR(255) NULL,
        blacklist_recommended TINYINT(1) DEFAULT 0,
        title VARCHAR(200) NOT NULL,
        description LONGTEXT NOT NULL,
        immediate_action_taken TEXT NULL,
        evidence_image_path VARCHAR(255) NULL,
        status ENUM('open', 'investigating', 'resolved', 'escalated_police', 'escalated_mgmt', 'dismissed') DEFAULT 'open',
        investigated_by INT NULL,
        resolution_notes TEXT NULL,
        resolved_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(estate_id, incident_datetime),
        INDEX(estate_id, status),
        INDEX(estate_id, incident_type),
        INDEX(is_flagged_bad_behavior),
        INDEX(target_resident_id),
        INDEX(reporter_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 9. Configurable Security Incident Types (OB Book)
    $conn->query("CREATE TABLE IF NOT EXISTS incident_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        estate_id INT DEFAULT 1,
        name VARCHAR(100) NOT NULL,
        slug VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(estate_id),
        INDEX(slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 10. Configurable Security Incident Severities
    $conn->query("CREATE TABLE IF NOT EXISTS incident_severities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        estate_id INT DEFAULT 1,
        name VARCHAR(100) NOT NULL,
        slug VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(estate_id),
        INDEX(slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 11. Configurable Security Incident Statuses
    $conn->query("CREATE TABLE IF NOT EXISTS incident_statuses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        estate_id INT DEFAULT 1,
        name VARCHAR(100) NOT NULL,
        slug VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(estate_id),
        INDEX(slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 12. Configurable Incident Entity Types
    $conn->query("CREATE TABLE IF NOT EXISTS incident_entity_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        estate_id INT DEFAULT 1,
        name VARCHAR(100) NOT NULL,
        slug VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(estate_id),
        INDEX(slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ensure security_incidents columns support custom configurable values (convert from strict enum if needed)
    @$conn->query("ALTER TABLE security_incidents MODIFY COLUMN incident_type VARCHAR(100) DEFAULT 'general_occurrence'");
    @$conn->query("ALTER TABLE security_incidents MODIFY COLUMN severity VARCHAR(50) DEFAULT 'medium'");
    @$conn->query("ALTER TABLE security_incidents MODIFY COLUMN status VARCHAR(50) DEFAULT 'open'");
    @$conn->query("ALTER TABLE security_incidents MODIFY COLUMN entity_type VARCHAR(50) DEFAULT 'resident'");

    // Add missing columns to estate_emergency_alerts if needed
    $col_checks = [
        'target_stakeholders' => "ALTER TABLE estate_emergency_alerts ADD COLUMN target_stakeholders ENUM('all_residents', 'guards_only', 'guards_and_admin', 'guards_and_medical') DEFAULT 'all_residents' AFTER target_scope",
        'resolution_action' => "ALTER TABLE estate_emergency_alerts ADD COLUMN resolution_action VARCHAR(150) NULL AFTER resolved_at",
        'broadcast_sent' => "ALTER TABLE estate_emergency_alerts ADD COLUMN broadcast_sent TINYINT(1) DEFAULT 0 AFTER resolution_notes",
        'email_sent' => "ALTER TABLE estate_emergency_alerts ADD COLUMN email_sent TINYINT(1) DEFAULT 0 AFTER broadcast_sent"
    ];
    foreach ($col_checks as $col => $alter_sql) {
        $chk = $conn->query("SHOW COLUMNS FROM estate_emergency_alerts LIKE '$col'");
        if ($chk && $chk->num_rows == 0) {
            $conn->query($alter_sql);
        }
    }

    // -------------------------------------------------------------
    // GATE PASS & VISITOR LOGS: DUTY SHIFT & VEHICLE COLUMNS
    // -------------------------------------------------------------
    $vis_cols_needed = [
        'entry_shift_name' => "VARCHAR(100) NULL AFTER entry_processed_by",
        'entry_roster_id'  => "INT NULL AFTER entry_shift_name",
        'exit_shift_name'  => "VARCHAR(100) NULL AFTER exit_processed_by",
        'exit_roster_id'   => "INT NULL AFTER exit_shift_name",
        'vehicle_plate'    => "VARCHAR(50) NULL AFTER exit_gate",
        'guard_notes'      => "TEXT NULL AFTER vehicle_plate"
    ];
    foreach ($vis_cols_needed as $col => $col_def) {
        $chk = $conn->query("SHOW COLUMNS FROM visitors LIKE '$col'");
        if ($chk && $chk->num_rows == 0) {
            $conn->query("ALTER TABLE visitors ADD COLUMN $col $col_def");
        }
    }

    // -------------------------------------------------------------
    // SEED EXPANDED GRANULAR PERMISSIONS
    // -------------------------------------------------------------
    $perm_items = [
        // Security & Incident Logging
        ['Security & Incidents', 'View Incident & Occurrence Log', 'incidents.view', 'Allows viewing estate incident history, occurrence logbook and forensics'],
        ['Security & Incidents', 'Log Incident & Strange Events', 'incidents.create', 'Allows guards & staff to register new occurrences and security incidents'],
        ['Security & Incidents', 'Flag Bad Behavior / Blacklist', 'incidents.flag_behavior', 'Allows flagging unruly residents or visitors for security review & blacklisting'],
        ['Security & Incidents', 'Manage & Resolve Incidents', 'incidents.manage', 'Allows updating investigation status, resolving incidents and escalating to police'],
        ['Security & Incidents', 'Export Occurrence Logbook', 'incidents.export', 'Allows exporting occurrence books, incident logs and analytics to CSV'],
        
        // Roster & Shift Operations
        ['Roster & Attendance', 'View Duty Rosters', 'roster.view', 'Allows viewing staff duty rosters, calendar shifts and station allocations'],
        ['Roster & Attendance', 'Manage & Distribute Rosters', 'roster.manage', 'Allows creating shifts, configuring stations/posts and distributing recurring rosters'],
        ['Roster & Attendance', 'Clock In & Out (Personal)', 'attendance.clock_in_out', 'Allows staff to record clock in and clock out timestamps for assigned shifts'],
        ['Roster & Attendance', 'Attendance Override & Roll Call', 'attendance.admin_override', 'Allows manual clock in/out overrides, attendance verification and roll call management'],
        
        // Visitor Security & Gate
        ['Gate & Visitor Passes', 'Flag & Blacklist Visitors', 'visitors.blacklist_flag', 'Allows flagging unruly visitors or blacklisting suspicious vehicles at the gate'],
        ['Gate & Visitor Passes', 'Bypass Resident Confirmation', 'visitors.bypass_confirmation', 'Allows staff to approve visitor gate exit without requiring resident confirmation code']
    ];

    foreach ($perm_items as $pi) {
        $mod = $conn->real_escape_string($pi[0]);
        $name = $conn->real_escape_string($pi[1]);
        $slug = $conn->real_escape_string($pi[2]);
        $desc = $conn->real_escape_string($pi[3]);

        $chk_p = $conn->query("SELECT id FROM permissions WHERE slug = '$slug' LIMIT 1");
        if (!$chk_p || $chk_p->num_rows == 0) {
            $conn->query("INSERT INTO permissions (module, name, slug, description) VALUES ('$mod', '$name', '$slug', '$desc')");
            $new_p_id = $conn->insert_id;

            // Auto-grant to Admin, Manager and Security roles where appropriate
            if ($new_p_id) {
                // Admin & Manager get all
                $admin_roles = $conn->query("SELECT id FROM roles WHERE slug IN ('admin', 'manager', 'superadmin') OR name LIKE '%Admin%' OR name LIKE '%Manager%'");
                if ($admin_roles) {
                    while ($ar = $admin_roles->fetch_assoc()) {
                        $rid = intval($ar['id']);
                        $conn->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES ($rid, $new_p_id)");
                    }
                }

                // Security roles get incidents.view, incidents.create, incidents.flag_behavior, roster.view, attendance.clock_in_out, visitors.blacklist_flag
                if (in_array($slug, ['incidents.view', 'incidents.create', 'incidents.flag_behavior', 'roster.view', 'attendance.clock_in_out', 'visitors.blacklist_flag'])) {
                    $sec_roles = $conn->query("SELECT id FROM roles WHERE slug = 'security' OR name LIKE '%Security%' OR name LIKE '%Guard%'");
                    if ($sec_roles) {
                        while ($sr = $sec_roles->fetch_assoc()) {
                            $rid = intval($sr['id']);
                            $conn->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES ($rid, $new_p_id)");
                        }
                    }
                }

                // General Staff roles get roster.view, attendance.clock_in_out, incidents.create
                if (in_array($slug, ['roster.view', 'attendance.clock_in_out', 'incidents.create'])) {
                    $staff_roles = $conn->query("SELECT id FROM roles WHERE slug = 'staff' OR name LIKE '%Staff%' OR name LIKE '%Maintenance%'");
                    if ($staff_roles) {
                        while ($str = $staff_roles->fetch_assoc()) {
                            $rid = intval($str['id']);
                            $conn->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES ($rid, $new_p_id)");
                        }
                    }
                }
            }
        }
    }

    // -------------------------------------------------------------
    // SEED DEFAULT DATA IF TABLES ARE EMPTY (Per Estate)
    // -------------------------------------------------------------
    $estate_id = function_exists('get_estate_id') ? get_estate_id() : 1;

    // Seed Security Posts
    $post_chk = $conn->query("SELECT id FROM security_posts WHERE estate_id = $estate_id LIMIT 1");
    if ($post_chk && $post_chk->num_rows == 0) {
        $default_posts = [
            ['Main Entrance Gate', 'Primary vehicle & visitor access gate', 'Ext 101'],
            ['North Pedestrian Gate', 'Resident turnstiles and North exit', 'Ext 102'],
            ['South Perimeter Gate', 'Heavy vehicles & service entrance', 'Ext 103'],
            ['Patrol Unit Alpha', 'Mobile patrol vehicle & perimeter security', 'Ext 104'],
            ['CCTV Security Command Hub', 'Central surveillance monitoring room', 'Ext 100']
        ];
        foreach ($default_posts as $p) {
            $name = $conn->real_escape_string($p[0]);
            $desc = $conn->real_escape_string($p[1]);
            $ext = $conn->real_escape_string($p[2]);
            $conn->query("INSERT INTO security_posts (estate_id, post_name, location_description, phone_extension) VALUES ($estate_id, '$name', '$desc', '$ext')");
        }
    }

    // Seed Standard Security Shifts
    $shift_chk = $conn->query("SELECT id FROM security_shifts WHERE estate_id = $estate_id LIMIT 1");
    if ($shift_chk && $shift_chk->num_rows == 0) {
        $default_shifts = [
            ['Morning Shift', '06:00:00', '14:00:00', '#0ea5e9'],
            ['Afternoon Shift', '14:00:00', '22:00:00', '#f59e0b'],
            ['Night Shift', '22:00:00', '06:00:00', '#6366f1'],
            ['24-Hour Command Duty', '08:00:00', '08:00:00', '#10b981']
        ];
        foreach ($default_shifts as $s) {
            $name = $conn->real_escape_string($s[0]);
            $st = $s[1];
            $et = $s[2];
            $col = $s[3];
            $conn->query("INSERT INTO security_shifts (estate_id, name, start_time, end_time, color_code) VALUES ($estate_id, '$name', '$st', '$et', '$col')");
        }
    }

    // Seed Dynamic Emergency Categories (Initial Starter Presets)
    $cat_chk = $conn->query("SELECT id FROM estate_emergency_categories WHERE estate_id = $estate_id LIMIT 1");
    if ($cat_chk && $cat_chk->num_rows == 0) {
        $starter_categories = [
            ['Medical Emergency', '#ef4444', 'Someone is injured, unconscious, having a medical crisis, etc.', 'critical', 'guards_and_medical', '112', 1],
            ['Security Threat', '#b91c1c', 'Intruder, assault, suspicious person, confrontation, etc.', 'critical', 'guards_only', '08000000001', 2],
            ['Fire / Hazard Evacuation', '#ea580c', 'Fire, smoke, explosion or suspected fire hazard requiring immediate response', 'critical', 'all_residents', '08000000002', 3],
            ['Break-In / Burglary', '#dc2626', 'Active or suspected break-in or burglary in progress', 'critical', 'guards_and_admin', '08000000001', 4],
            ['Personal Safety', '#f97316', 'Resident feels threatened, followed, or unsafe', 'high', 'guards_only', '08000000001', 5],
            ['Vehicle Emergency', '#d97706', 'Accident, vehicle-related threat, hit-and-run, or blockage', 'high', 'guards_only', '08000000003', 6],
            ['Child Emergency', '#e11d48', 'Missing child or child requiring urgent immediate assistance', 'critical', 'guards_and_admin', '08000000001', 7],
            ['Electrical / Hazard', '#eab308', 'Electrical hazard, exposed high-voltage wire, sparking transformer', 'high', 'guards_and_admin', '08000000004', 8],
            ['Flood / Water Emergency', '#0284c7', 'Major flooding, burst water main, dangerous water leak', 'medium', 'guards_and_admin', '08000000004', 9],
            ['General Estate Emergency', '#64748b', 'Urgent matter requiring immediate dispatch that does not fit above', 'high', 'guards_and_admin', '08000000001', 10]
        ];
        foreach ($starter_categories as $c) {
            $name = $conn->real_escape_string($c[0]);
            $col = $conn->real_escape_string($c[1]);
            $desc = $conn->real_escape_string($c[2]);
            $prio = $c[3];
            $stakeholders = $c[4];
            $phone = $conn->real_escape_string($c[5]);
            $order = intval($c[6]);
            $conn->query("INSERT INTO estate_emergency_categories (estate_id, category_name, color, use_case_description, priority, target_stakeholders, emergency_contact_phone, display_order) 
                         VALUES ($estate_id, '$name', '$col', '$desc', '$prio', '$stakeholders', '$phone', $order)");
        }
    }

    // Seed Configurable Response Actions (Action Taken Templates)
    $action_chk = $conn->query("SELECT id FROM estate_emergency_actions WHERE estate_id = $estate_id LIMIT 1");
    if ($action_chk && $action_chk->num_rows == 0) {
        $starter_actions = [
            ['Security Patrol Dispatched to Unit', 'Gate patrol dispatched immediately to inspect location and secure premises', 'dispatched', 1],
            ['Medical Team Responded & Assisted', 'First-aid desk or external medical ambulance assisted resident on-site', 'resolved', 2],
            ['Fire Neutralized / Extinguished', 'Estate fire extinguishers utilized, threat neutralized', 'resolved', 3],
            ['Intruder Apprehended / Perimeter Secured', 'Security personnel intercepted threat and restored order', 'resolved', 4],
            ['Verified False Alarm - Resident Safe', 'Direct contact established with resident; confirmed accidental trigger or safe', 'false_alarm', 5],
            ['Direct Phone Contact Established', 'CSO / Admin called resident directly and resolved query', 'resolved', 6],
            ['Police Escalation & Escort', 'State police emergency division notified and escorted on-site', 'resolved', 7],
            ['Facility Hazard Repaired & Made Safe', 'Maintenance/technical staff isolated electrical or water hazard', 'resolved', 8]
        ];
        foreach ($starter_actions as $act) {
            $atitle = $conn->real_escape_string($act[0]);
            $adesc = $conn->real_escape_string($act[1]);
            $astat = $act[2];
            $aord = intval($act[3]);
            $conn->query("INSERT INTO estate_emergency_actions (estate_id, action_title, description, target_status, display_order) 
                         VALUES ($estate_id, '$atitle', '$adesc', '$astat', $aord)");
        }
    }

    // Seed Emergency System Settings defaults
    $emergency_settings = [
        'emergency_countdown_seconds' => '5',
        'emergency_auto_broadcast' => '1',
        'emergency_auto_email' => '1',
        'emergency_stakeholder_phones' => '',
        'emergency_stakeholder_emails' => '',
        'require_resident_visitor_confirmation' => '1'
    ];
    foreach ($emergency_settings as $sk => $sv) {
        $chk_set = $conn->query("SELECT 1 FROM system_settings WHERE estate_id = $estate_id AND setting_key = '$sk' LIMIT 1");
        if (!$chk_set || $chk_set->num_rows == 0) {
            $conn->query("INSERT INTO system_settings (estate_id, setting_key, setting_value) VALUES ($estate_id, '$sk', '$sv')");
        }
    }

    // Seed Configurable Estate Emergency Direct-Dial Contacts
    $contact_chk = $conn->query("SELECT id FROM estate_emergency_contacts WHERE estate_id = $estate_id LIMIT 1");
    if ($contact_chk && $contact_chk->num_rows == 0) {
        $starter_contacts = [
            ['Main Gate Security Desk', '08012345678', 'internal_security', 1, 1],
            ['Rapid Response Patrol Mobile', '08087654321', 'internal_security', 0, 2],
            ['Chief Security Officer (CSO)', '09066832352', 'management', 0, 3],
            ['Estate First-Aid / Medical Desk', '08033334444', 'medical', 0, 4],
            ['State Police Emergency Division', '112', 'police', 0, 5],
            ['State Fire & Rescue Service', '119', 'fire', 0, 6]
        ];
        foreach ($starter_contacts as $ct) {
            $lbl = $conn->real_escape_string($ct[0]);
            $num = $conn->real_escape_string($ct[1]);
            $typ = $ct[2];
            $is_prim = intval($ct[3]);
            $ord = intval($ct[4]);
            $conn->query("INSERT INTO estate_emergency_contacts (estate_id, label, phone_number, contact_type, is_primary, display_order) 
                         VALUES ($estate_id, '$lbl', '$num', '$typ', $is_prim, $ord)");
        }
    }

    // Seed Initial Duty Shifts for Active Security Guard (Femi Oke - user_id 12) if empty
    $roster_chk = $conn->query("SELECT id FROM security_roster WHERE estate_id = $estate_id LIMIT 1");
    if ($roster_chk && $roster_chk->num_rows == 0) {
        // Find security user
        $sec_user = $conn->query("SELECT u.id as user_id, es.id as staff_id FROM users u LEFT JOIN estate_staff es ON u.id = es.user_id WHERE u.role = 'security' LIMIT 1")->fetch_assoc();
        $p_res = $conn->query("SELECT id FROM security_posts WHERE estate_id = $estate_id LIMIT 1")->fetch_assoc();
        $s_res = $conn->query("SELECT id FROM security_shifts WHERE estate_id = $estate_id LIMIT 1")->fetch_assoc();
        
        if ($sec_user && $p_res && $s_res) {
            $u_id = intval($sec_user['user_id']);
            $st_id = !empty($sec_user['staff_id']) ? intval($sec_user['staff_id']) : 'NULL';
            $p_id = intval($p_res['id']);
            $sh_id = intval($s_res['id']);
            $today = date('Y-m-d');
            $st_dt = $today . ' 06:00:00';
            $end_dt = $today . ' 14:00:00';
            
            $conn->query("INSERT INTO security_roster (estate_id, user_id, staff_id, post_id, shift_id, duty_date, start_datetime, end_datetime, status, clock_in_time) 
                         VALUES ($estate_id, $u_id, $st_id, $p_id, $sh_id, '$today', '$st_dt', '$end_dt', 'on_duty', NOW())");
        }
    }

    // Seed Configurable Incident Types if empty
    $it_chk = $conn->query("SELECT id FROM incident_types LIMIT 1");
    if ($it_chk && $it_chk->num_rows == 0) {
        $default_types = [
            ['General Occurrence / Routine Log', 'general_occurrence'],
            ['Bad Behavior / Misconduct', 'bad_behavior'],
            ['Noise Disturbance', 'noise_disturbance'],
            ['Speeding & Traffic Violation', 'speeding_traffic'],
            ['Unauthorized Entry / Gate Breach', 'unauthorized_entry'],
            ['Suspicious Person / Vehicle', 'suspicious_activity'],
            ['Property Damage / Vandalism', 'property_damage'],
            ['Theft / Attempted Burglary', 'theft_burglary'],
            ['Altercation / Physical Fight', 'altercation_fight'],
            ['Access Denied at Gate', 'access_denied'],
            ['Lost & Found', 'lost_found']
        ];
        foreach ($default_types as $dt) {
            $name = $conn->real_escape_string($dt[0]);
            $slug = $conn->real_escape_string($dt[1]);
            $conn->query("INSERT INTO incident_types (estate_id, name, slug) VALUES ($estate_id, '$name', '$slug')");
        }
    }

    // Seed Configurable Incident Severities if empty
    $is_chk = $conn->query("SELECT id FROM incident_severities LIMIT 1");
    if ($is_chk && $is_chk->num_rows == 0) {
        $default_severities = [
            ['Low / Routine Observation', 'low'],
            ['Medium / Disruption', 'medium'],
            ['High / Serious Security Matter', 'high'],
            ['Critical / Life & Safety Threat', 'critical']
        ];
        foreach ($default_severities as $ds) {
            $name = $conn->real_escape_string($ds[0]);
            $slug = $conn->real_escape_string($ds[1]);
            $conn->query("INSERT INTO incident_severities (estate_id, name, slug) VALUES ($estate_id, '$name', '$slug')");
        }
    }

    // Seed Configurable Incident Statuses if empty
    $ist_chk = $conn->query("SELECT id FROM incident_statuses LIMIT 1");
    if ($ist_chk && $ist_chk->num_rows == 0) {
        $default_statuses = [
            ['Open / Unresolved', 'open'],
            ['Under Investigation', 'investigating'],
            ['Resolved', 'resolved'],
            ['Escalated to Police / Law Enforcement', 'escalated_police'],
            ['Escalated to Management', 'escalated_mgmt'],
            ['Dismissed / False Alarm', 'dismissed']
        ];
        foreach ($default_statuses as $dst) {
            $name = $conn->real_escape_string($dst[0]);
            $slug = $conn->real_escape_string($dst[1]);
            $conn->query("INSERT INTO incident_statuses (estate_id, name, slug) VALUES ($estate_id, '$name', '$slug')");
        }
    }

    // Seed Configurable Incident Entity Types if empty
    $iet_chk = $conn->query("SELECT id FROM incident_entity_types LIMIT 1");
    if ($iet_chk && $iet_chk->num_rows == 0) {
        $default_entities = [
            ['Estate Resident', 'resident'],
            ['Visitor / Guest', 'visitor'],
            ['Staff / Contractor', 'staff_contractor'],
            ['Unknown / Third Party', 'unknown_third_party']
        ];
        foreach ($default_entities as $de) {
            $name = $conn->real_escape_string($de[0]);
            $slug = $conn->real_escape_string($de[1]);
            $conn->query("INSERT INTO incident_entity_types (estate_id, name, slug) VALUES ($estate_id, '$name', '$slug')");
        }
    }
}
}

// -------------------------------------------------------------
// DYNAMIC LOOKUP FETCH HELPERS (100% Configurable)
// -------------------------------------------------------------
if (!function_exists('getIncidentTypes')) {
    function getIncidentTypes($conn = null) {
        if (!$conn) { global $conn; }
        if (!$conn || !($conn instanceof mysqli)) return [];
        $res = $conn->query("SELECT * FROM incident_types ORDER BY name ASC");
        $items = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $items[] = $row;
            }
        }
        return $items;
    }
}

if (!function_exists('getIncidentSeverities')) {
    function getIncidentSeverities($conn = null) {
        if (!$conn) { global $conn; }
        if (!$conn || !($conn instanceof mysqli)) return [];
        $res = $conn->query("SELECT * FROM incident_severities ORDER BY id ASC");
        $items = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $items[] = $row;
            }
        }
        return $items;
    }
}

if (!function_exists('getIncidentStatuses')) {
    function getIncidentStatuses($conn = null) {
        if (!$conn) { global $conn; }
        if (!$conn || !($conn instanceof mysqli)) return [];
        $res = $conn->query("SELECT * FROM incident_statuses ORDER BY id ASC");
        $items = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $items[] = $row;
            }
        }
        return $items;
    }
}

if (!function_exists('getIncidentEntityTypes')) {
    function getIncidentEntityTypes($conn = null) {
        if (!$conn) { global $conn; }
        if (!$conn || !($conn instanceof mysqli)) return [];
        $res = $conn->query("SELECT * FROM incident_entity_types ORDER BY id ASC");
        $items = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $items[] = $row;
            }
        }
        return $items;
    }
}

if (isset($conn) && $conn instanceof mysqli) {
    initEmergencyAndRosterTables($conn);
}


