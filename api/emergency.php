<?php
// api/emergency.php
// Handles panic triggers, real-time multi-panic alert polling, stakeholder routing, broadcast notices, email & WhatsApp dispatch

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/emergency_roster_init.php';
require_once __DIR__ . '/../includes/Mailer.php';
require_once __DIR__ . '/../includes/NoticeManager.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$estate_id = get_estate_id();
$user_id = intval($_SESSION['user_id']);
$user_role = $_SESSION['role'] ?? 'resident';
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// Helper to snapshot active guards on duty
function snapshotGuardsOnDuty($conn, $estate_id) {
    $now = date('Y-m-d H:i:s');
    $sql = "SELECT sr.id, sr.duty_date, sr.status, sr.clock_in_time, 
                   u.id as user_id, u.name as officer_name, u.phone as officer_phone,
                   sp.post_name, sp.phone_extension,
                   ss.name as shift_name
            FROM security_roster sr
            JOIN users u ON sr.user_id = u.id
            LEFT JOIN security_posts sp ON sr.post_id = sp.id
            LEFT JOIN security_shifts ss ON sr.shift_id = ss.id
            WHERE sr.estate_id = $estate_id 
              AND sr.start_datetime <= '$now' 
              AND sr.end_datetime >= '$now'
              AND sr.status IN ('on_duty', 'scheduled')
            ORDER BY sp.post_name ASC";
    $res = $conn->query($sql);
    $guards = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $guards[] = $row;
        }
    }
    return $guards;
}

// Helper to get system setting
function getEmergencySetting($conn, $estate_id, $key, $default = '') {
    $res = $conn->query("SELECT setting_value FROM system_settings WHERE estate_id = $estate_id AND setting_key = '$key' LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        return $row['setting_value'];
    }
    return $default;
}

// -------------------------------------------------------------
// 1. TRIGGER PANIC / EMERGENCY ALERT
// -------------------------------------------------------------
if ($action === 'trigger_panic' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $sender_type = 'resident';
    if (in_array($user_role, ['admin', 'superadmin', 'manager'])) {
        $sender_type = 'central_admin';
    } elseif ($user_role === 'zone_admin') {
        $sender_type = 'zone_admin';
    }

    $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : null;
    $category_name = trim($_POST['category_name'] ?? 'Emergency Alert');
    $headline = trim($_POST['headline'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $lat = !empty($_POST['latitude']) ? floatval($_POST['latitude']) : 'NULL';
    $lng = !empty($_POST['longitude']) ? floatval($_POST['longitude']) : 'NULL';
    $target_scope = isset($_POST['target_scope']) && $_POST['target_scope'] === 'zone' ? 'zone' : 'estate_wide';
    $target_zone_id = !empty($_POST['target_zone_id']) ? intval($_POST['target_zone_id']) : 'NULL';
    $sound_alarm = isset($_POST['sound_alarm']) ? intval($_POST['sound_alarm']) : 1;
    $target_stakeholders = $_POST['target_stakeholders'] ?? 'all_residents';

    // Fetch category details if category_id given
    if ($category_id) {
        $c_res = $conn->query("SELECT * FROM estate_emergency_categories WHERE id = $category_id AND estate_id = $estate_id LIMIT 1");
        if ($c_res && $crow = $c_res->fetch_assoc()) {
            $category_name = $crow['category_name'];
            if (empty($_POST['target_stakeholders']) && !empty($crow['target_stakeholders'])) {
                $target_stakeholders = $crow['target_stakeholders'];
            }
        }
    }

    if (!in_array($target_stakeholders, ['all_residents', 'guards_only', 'guards_and_admin', 'guards_and_medical'])) {
        $target_stakeholders = 'all_residents';
    }

    // Fetch Sender profile & Property details
    $user_info = $conn->query("SELECT name, phone, email FROM users WHERE id = $user_id LIMIT 1")->fetch_assoc();
    $sender_name = $conn->real_escape_string($user_info['name'] ?? 'Estate Resident');
    $sender_phone = $conn->real_escape_string($user_info['phone'] ?? '');

    $flat_id = 'NULL';
    $building_name = 'NULL';
    $flat_number = 'NULL';
    $res_zone_id = 'NULL';
    $unit_display = 'Estate Grounds / General';

    if ($sender_type === 'resident') {
        $res_prop = $conn->query("SELECT r.flat_id, f.number as flat_number, b.name as building_name, s.zone_id 
                                  FROM residents r 
                                  LEFT JOIN flats f ON r.flat_id = f.id 
                                  LEFT JOIN buildings b ON f.building_id = b.id 
                                  LEFT JOIN streets s ON b.street_id = s.id 
                                  WHERE r.user_id = $user_id AND r.estate_id = $estate_id 
                                  ORDER BY r.id DESC LIMIT 1");
        if ($res_prop && $rp = $res_prop->fetch_assoc()) {
            if (!empty($rp['flat_id'])) $flat_id = intval($rp['flat_id']);
            if (!empty($rp['flat_number'])) $flat_number = "'" . $conn->real_escape_string($rp['flat_number']) . "'";
            if (!empty($rp['building_name'])) $building_name = "'" . $conn->real_escape_string($rp['building_name']) . "'";
            if (!empty($rp['zone_id'])) $res_zone_id = intval($rp['zone_id']);
            $unit_display = ($rp['building_name'] ?? '') . ' • Unit ' . ($rp['flat_number'] ?? '');
        }
    } elseif ($sender_type === 'zone_admin') {
        $z_id = $_SESSION['zone_id'] ?? null;
        if ($z_id) {
            $res_zone_id = intval($z_id);
            $target_scope = 'zone';
            $target_zone_id = intval($z_id);
            $unit_display = 'Zonal Command Broadcast';
        }
    } else {
        $unit_display = 'Central Administration Command';
    }

    // Capture security guards on duty snapshot
    $guards_snapshot = snapshotGuardsOnDuty($conn, $estate_id);
    $guards_json = $conn->real_escape_string(json_encode($guards_snapshot, JSON_UNESCAPED_SLASHES));

    // Generate Alert Code (SOS-YYMMDD-XXX)
    $datePart = date('ymd');
    $randPart = str_pad(mt_rand(10, 999), 3, '0', STR_PAD_LEFT);
    $alert_code = "SOS-$datePart-$randPart";

    $category_name_esc = $conn->real_escape_string($category_name);
    $headline_esc = "'" . $conn->real_escape_string($headline) . "'";
    $note_esc = "'" . $conn->real_escape_string($note) . "'";

    $insert_sql = "INSERT INTO estate_emergency_alerts (
        estate_id, alert_code, sender_type, sender_id, sender_name, sender_phone,
        target_scope, target_stakeholders, zone_id, flat_id, building_name, flat_number,
        category_id, category_name, headline, note, latitude, longitude,
        status, sound_alarm, security_officers_on_duty_snapshot
    ) VALUES (
        $estate_id, '$alert_code', '$sender_type', $user_id, '$sender_name', '$sender_phone',
        '$target_scope', '$target_stakeholders', " . ($target_scope === 'zone' ? $target_zone_id : $res_zone_id) . ", $flat_id, $building_name, $flat_number,
        " . ($category_id ? $category_id : "NULL") . ", '$category_name_esc', $headline_esc, $note_esc, $lat, $lng,
        'active', $sound_alarm, '$guards_json'
    )";

    if ($conn->query($insert_sql)) {
        $alert_id = $conn->insert_id;

        // Log audit
        logAudit($conn, "Emergency Panic Triggered", "Emergency", "Alert $alert_code ($category_name) triggered by $sender_name ($sender_type). Stakeholders: $target_stakeholders.");

        // -------------------------------------------------------------
        // AUTOMATED MULTI-CHANNEL BROADCAST NOTICE
        // -------------------------------------------------------------
        $auto_broadcast = getEmergencySetting($conn, $estate_id, 'emergency_auto_broadcast', '1');
        if ($auto_broadcast == '1') {
            $b_title = "EMERGENCY ALERT: $category_name ($alert_code)";
            $b_content = "Emergency situation reported at $unit_display by $sender_name.\n" . 
                         (!empty($headline) ? "Advisory: $headline\n" : "") . 
                         (!empty($note) ? "Details: $note\n" : "") . 
                         "Security response teams have been dispatched. Stand by for instructions.";
            
            $b_audience = ($target_stakeholders === 'all_residents') ? 'all' : 'residents';
            NoticeManager::publishNotice($conn, [
                'estate_id' => $estate_id,
                'zone_id' => ($target_scope === 'zone' && $target_zone_id !== 'NULL') ? $target_zone_id : null,
                'title' => $b_title,
                'content' => $b_content,
                'target_audience' => $b_audience,
                'priority' => 'urgent',
                'sender_type' => 'system',
                'sender_name' => 'Emergency Response Center',
                'created_by' => $user_id,
                'pin_to_top' => 1,
                'dispatch_notification' => true,
                'dispatch_email' => false
            ]);
            $conn->query("UPDATE estate_emergency_alerts SET broadcast_sent = 1 WHERE id = $alert_id");
        }

        // -------------------------------------------------------------
        // AUTOMATED DETAILED EMAIL DISPATCH
        // -------------------------------------------------------------
        $auto_email = getEmergencySetting($conn, $estate_id, 'emergency_auto_email', '1');
        if ($auto_email == '1') {
            $email_subject = "🚨 [URGENT SOS] $category_name - $alert_code ($unit_display)";
            
            $guard_list_html = "";
            if (!empty($guards_snapshot)) {
                foreach ($guards_snapshot as $g) {
                    $guard_list_html .= "<li><strong>" . htmlspecialchars($g['officer_name']) . "</strong> (" . htmlspecialchars($g['post_name'] ?? 'Gate') . ") - Tel: " . htmlspecialchars($g['officer_phone'] ?? 'N/A') . "</li>";
                }
            } else {
                $guard_list_html = "<li>No scheduled gate guards snapshot recorded</li>";
            }

            $gps_link = ($lat !== 'NULL' && $lng !== 'NULL') 
                ? "<p><strong>GPS Coordinates:</strong> <a href='https://maps.google.com/?q=$lat,$lng' target='_blank' style='color: #dc2626; font-weight: bold;'>View Incident Location on Google Maps</a></p>" 
                : "";

            $email_body = "
                <div style='font-family: Arial, sans-serif; color: #1e293b; max-width: 600px; margin: 0 auto; border: 2px solid #dc2626; border-radius: 12px; overflow: hidden;'>
                    <div style='background: #dc2626; color: #ffffff; padding: 18px 24px; text-align: center;'>
                        <h2 style='margin: 0; font-size: 20px; letter-spacing: 0.05em;'>EMERGENCY PANIC ALERT DISPATCHED</h2>
                        <div style='font-size: 13px; opacity: 0.9; margin-top: 4px;'>Estate Rapid Security Response Network</div>
                    </div>
                    <div style='padding: 24px; background: #ffffff;'>
                        <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'>
                            <tr style='border-bottom: 1px solid #e2e8f0;'><td style='padding: 8px 0; color: #64748b; font-size: 13px;'>Alert Code:</td><td style='padding: 8px 0; font-weight: bold; color: #dc2626; font-family: monospace;'>$alert_code</td></tr>
                            <tr style='border-bottom: 1px solid #e2e8f0;'><td style='padding: 8px 0; color: #64748b; font-size: 13px;'>Emergency Category:</td><td style='padding: 8px 0; font-weight: bold; color: #0f172a;'>$category_name</td></tr>
                            <tr style='border-bottom: 1px solid #e2e8f0;'><td style='padding: 8px 0; color: #64748b; font-size: 13px;'>Initiator / Resident:</td><td style='padding: 8px 0; font-weight: bold; color: #0f172a;'>$sender_name (" . ($user_info['phone'] ?? 'No Phone') . ")</td></tr>
                            <tr style='border-bottom: 1px solid #e2e8f0;'><td style='padding: 8px 0; color: #64748b; font-size: 13px;'>Location / Unit:</td><td style='padding: 8px 0; font-weight: bold; color: #0f172a;'>$unit_display</td></tr>
                            <tr style='border-bottom: 1px solid #e2e8f0;'><td style='padding: 8px 0; color: #64748b; font-size: 13px;'>Target Stakeholders:</td><td style='padding: 8px 0; font-weight: bold; color: #2563eb; text-transform: uppercase;'>$target_stakeholders</td></tr>
                            <tr style='border-bottom: 1px solid #e2e8f0;'><td style='padding: 8px 0; color: #64748b; font-size: 13px;'>Time Dispatched:</td><td style='padding: 8px 0; font-weight: bold; color: #0f172a;'>" . date('M d, Y - h:i:s A') . "</td></tr>
                        </table>
                        " . (!empty($headline) ? "<div style='background: #fee2e2; border-left: 4px solid #dc2626; padding: 12px; margin-bottom: 16px; font-weight: bold; color: #991b1b;'>Advisory: $headline</div>" : "") . "
                        " . (!empty($note) ? "<p style='color: #475569; font-size: 14px;'><strong>Notes:</strong> $note</p>" : "") . "
                        $gps_link
                        <div style='background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-top: 20px;'>
                            <strong style='font-size: 13px; color: #334155; text-transform: uppercase;'>On-Duty Security Personnel:</strong>
                            <ul style='margin: 8px 0 0 0; padding-left: 20px; font-size: 13px; color: #475569;'>
                                $guard_list_html
                            </ul>
                        </div>
                    </div>
                </div>
            ";

            // Determine email recipients based on stakeholders
            $stakeholder_emails = [];
            
            // Central Admins & Superadmins always receive alert
            $admin_res = $conn->query("SELECT email FROM users WHERE role IN ('admin', 'superadmin', 'manager') AND estate_id = $estate_id AND email != ''");
            if ($admin_res) {
                while ($arow = $admin_res->fetch_assoc()) {
                    if (!empty($arow['email'])) $stakeholder_emails[] = $arow['email'];
                }
            }

            // If stakeholder emails configured in settings
            $extra_emails = getEmergencySetting($conn, $estate_id, 'emergency_stakeholder_emails', '');
            if (!empty($extra_emails)) {
                $exploded = explode(',', $extra_emails);
                foreach ($exploded as $em) {
                    $em = trim($em);
                    if (filter_var($em, FILTER_VALIDATE_EMAIL)) $stakeholder_emails[] = $em;
                }
            }

            // If scope is all_residents, send broadcast email
            if ($target_stakeholders === 'all_residents') {
                EstateMailer::sendBroadcastEmail($conn, $email_subject, $email_body, 'all', $estate_id);
            } else {
                // Send to stakeholder emails
                foreach (array_unique($stakeholder_emails) as $rec_email) {
                    EstateMailer::sendMail($conn, $rec_email, 'Estate Command', $email_subject, $email_body, 'emergency_alert', $alert_code, $estate_id);
                }
            }
            $conn->query("UPDATE estate_emergency_alerts SET email_sent = 1 WHERE id = $alert_id");
        }

        // -------------------------------------------------------------
        // PREPARE WHATSAPP BROADCAST PAYLOAD
        // -------------------------------------------------------------
        $wa_msg = "🚨 *ESTATE EMERGENCY ALERT* 🚨\n" .
                  "--------------------------------\n" .
                  "📌 *Code:* $alert_code\n" .
                  "⚠️ *Category:* $category_name\n" .
                  "📍 *Location:* $unit_display\n" .
                  "👤 *Resident:* $sender_name (" . ($user_info['phone'] ?? '') . ")\n" .
                  "🕒 *Time:* " . date('h:i A, M d') . "\n" .
                  (!empty($headline) ? "📢 *Advisory:* $headline\n" : "") .
                  (!empty($note) ? "📝 *Details:* $note\n" : "") .
                  "--------------------------------\n" .
                  "Security team mobilized. Direct hotlines & guard gates have been dispatched.";
        
        $wa_url = "https://api.whatsapp.com/send?text=" . urlencode($wa_msg);

        // Fetch estate emergency hotlines to return for immediate click-to-dial
        $hotlines_res = $conn->query("SELECT label, phone_number, contact_type, is_primary FROM estate_emergency_contacts WHERE estate_id = $estate_id AND is_active = 1 ORDER BY display_order ASC");
        $hotlines = [];
        if ($hotlines_res) {
            while ($h = $hotlines_res->fetch_assoc()) {
                $hotlines[] = $h;
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Emergency Alert Dispatched Successfully',
            'alert_id' => $alert_id,
            'alert_code' => $alert_code,
            'category_name' => $category_name,
            'target_stakeholders' => $target_stakeholders,
            'timestamp' => date('Y-m-d H:i:s'),
            'hotlines' => $hotlines,
            'guards_on_duty' => $guards_snapshot,
            'whatsapp_text' => $wa_msg,
            'whatsapp_share_url' => $wa_url
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
    }
    exit;
}

// -------------------------------------------------------------
// 2. CHECK ACTIVE ALERTS (Real-Time Polling Engine with Stakeholder Scoping)
// -------------------------------------------------------------
if ($action === 'check_active_alerts') {
    $now = date('Y-m-d H:i:s');
    $user_zone_id = $_SESSION['zone_id'] ?? null;

    $where = "estate_id = $estate_id AND status IN ('active', 'acknowledged', 'dispatched')";
    
    // Stakeholder Audience Filtering
    if ($user_role === 'resident') {
        // Resident sees alerts triggered by themselves, OR broadcasts intended for all residents
        $flat_zone_query = $conn->query("SELECT s.zone_id FROM residents r LEFT JOIN flats f ON r.flat_id = f.id LEFT JOIN buildings b ON f.building_id = b.id LEFT JOIN streets s ON b.street_id = s.id WHERE r.user_id = $user_id LIMIT 1");
        $rz_id = ($flat_zone_query && $row = $flat_zone_query->fetch_assoc()) ? $row['zone_id'] : null;
        
        $where .= " AND (sender_id = $user_id OR target_stakeholders = 'all_residents')";
        if ($rz_id) {
            $where .= " AND (target_scope = 'estate_wide' OR (target_scope = 'zone' AND zone_id = " . intval($rz_id) . ") OR sender_id = $user_id)";
        } else {
            $where .= " AND (target_scope = 'estate_wide' OR sender_id = $user_id)";
        }
    } elseif ($user_role === 'zone_admin') {
        $where .= " AND (target_stakeholders IN ('guards_and_admin', 'all_residents') OR sender_id = $user_id)";
        if ($user_zone_id) {
            $where .= " AND (target_scope = 'estate_wide' OR zone_id = " . intval($user_zone_id) . ")";
        }
    } elseif (in_array($user_role, ['security', 'staff'])) {
        // Security guards see all alerts except strictly admin-only if any
        $where .= " AND (target_stakeholders IN ('guards_only', 'guards_and_admin', 'guards_and_medical', 'all_residents') OR sender_id = $user_id)";
    }
    // Admins & Managers see all alerts

    $res = $conn->query("SELECT id, alert_code, sender_type, sender_id, sender_name, sender_phone, 
                                building_name, flat_number, category_name, target_stakeholders, headline, note, status, sound_alarm,
                                acknowledged_by, acknowledged_at, created_at,
                                TIMESTAMPDIFF(SECOND, created_at, NOW()) as seconds_ago
                         FROM estate_emergency_alerts 
                         WHERE $where 
                         ORDER BY created_at DESC LIMIT 15");
    
    $alerts = [];
    $has_active = false;
    $should_sound_alarm = false;

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if ($row['status'] === 'active' || $row['status'] === 'dispatched') {
                $has_active = true;
                if ($row['sound_alarm'] == 1) {
                    $should_sound_alarm = true;
                }
            }
            $row['is_own_alert'] = ($row['sender_id'] == $user_id);
            $alerts[] = $row;
        }
    }

    echo json_encode([
        'success' => true,
        'count' => count($alerts),
        'has_active' => $has_active,
        'should_sound_alarm' => $should_sound_alarm,
        'alerts' => $alerts,
        'server_time' => $now
    ]);
    exit;
}

// -------------------------------------------------------------
// 3. SILENCE ALARM SIREN (Early Siren Mute without closing incident)
// -------------------------------------------------------------
if ($action === 'silence_alarm' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $alert_id = intval($_POST['alert_id'] ?? 0);
    
    // Check if user is authorized (admin/staff/security OR the sender)
    $alert_chk = $conn->query("SELECT sender_id FROM estate_emergency_alerts WHERE id = $alert_id AND estate_id = $estate_id LIMIT 1")->fetch_assoc();
    if (!$alert_chk) {
        echo json_encode(['success' => false, 'error' => 'Alert not found']);
        exit;
    }

    $is_allowed = in_array($user_role, ['admin', 'superadmin', 'manager', 'security', 'staff', 'zone_admin']) || ($alert_chk['sender_id'] == $user_id);
    if (!$is_allowed) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized action']);
        exit;
    }

    $sql = "UPDATE estate_emergency_alerts SET sound_alarm = 0 WHERE id = $alert_id AND estate_id = $estate_id";
    if ($conn->query($sql)) {
        logAudit($conn, "Emergency Siren Silenced", "Emergency", "Siren for alert #$alert_id silenced by user ID $user_id ($user_role).");
        echo json_encode(['success' => true, 'message' => 'Alarm siren silenced successfully.']);
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
    exit;
}

// -------------------------------------------------------------
// 4. ACKNOWLEDGE / DISPATCH ALERT
// -------------------------------------------------------------
if ($action === 'acknowledge_alert' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array($user_role, ['admin', 'superadmin', 'manager', 'security', 'staff', 'zone_admin'])) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized action']);
        exit;
    }

    $alert_id = intval($_POST['alert_id'] ?? 0);
    $status = trim($_POST['status'] ?? 'dispatched');
    if (!in_array($status, ['acknowledged', 'dispatched'])) {
        $status = 'dispatched';
    }

    $sql = "UPDATE estate_emergency_alerts SET 
            status = '$status', 
            acknowledged_by = $user_id, 
            acknowledged_at = NOW() 
            WHERE id = $alert_id AND estate_id = $estate_id";
    
    if ($conn->query($sql)) {
        logAudit($conn, "Emergency Alert $status", "Emergency", "Alert #$alert_id marked $status by user ID $user_id ($user_role).");
        echo json_encode(['success' => true, 'message' => "Alert status updated to $status"]);
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
    exit;
}

// -------------------------------------------------------------
// 5. RESOLVE ALERT / CLOSE INCIDENT / CANCEL FALSE ALARM
// -------------------------------------------------------------
if ($action === 'resolve_alert' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $alert_id = intval($_POST['alert_id'] ?? 0);
    $alert_chk = $conn->query("SELECT sender_id FROM estate_emergency_alerts WHERE id = $alert_id AND estate_id = $estate_id LIMIT 1")->fetch_assoc();
    
    if (!$alert_chk) {
        echo json_encode(['success' => false, 'error' => 'Alert not found']);
        exit;
    }

    $is_allowed = in_array($user_role, ['admin', 'superadmin', 'manager', 'security', 'staff', 'zone_admin']) || ($alert_chk['sender_id'] == $user_id);
    if (!$is_allowed) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized action']);
        exit;
    }

    $resolution_action = $conn->real_escape_string(trim($_POST['resolution_action'] ?? ''));
    $resolution_notes = $conn->real_escape_string(trim($_POST['resolution_notes'] ?? 'Resolved by on-duty response team'));
    $status = trim($_POST['status'] ?? 'resolved');
    if (!in_array($status, ['resolved', 'false_alarm'])) {
        $status = 'resolved';
    }

    $sql = "UPDATE estate_emergency_alerts SET 
            status = '$status', 
            resolved_by = $user_id, 
            resolved_at = NOW(), 
            resolution_action = '$resolution_action',
            resolution_notes = '$resolution_notes',
            sound_alarm = 0
            WHERE id = $alert_id AND estate_id = $estate_id";
    
    if ($conn->query($sql)) {
        logAudit($conn, "Emergency Alert Closed", "Emergency", "Alert #$alert_id marked $status by user ID $user_id. Action: $resolution_action. Notes: $resolution_notes");
        echo json_encode(['success' => true, 'message' => "Emergency incident closed ($status)."]);
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
    exit;
}

// -------------------------------------------------------------
// 6. GET CONFIG, CATEGORIES, CONTACTS & RESPONSE ACTIONS
// -------------------------------------------------------------
if ($action === 'get_config') {
    // Categories without icon, clean and stakeholder-aware
    $cat_res = $conn->query("SELECT id, category_name, color, use_case_description, priority, target_stakeholders, emergency_contact_phone 
                             FROM estate_emergency_categories 
                             WHERE estate_id = $estate_id AND is_active = 1 
                             ORDER BY display_order ASC");
    $categories = [];
    if ($cat_res) {
        while ($c = $cat_res->fetch_assoc()) {
            $categories[] = $c;
        }
    }

    $con_res = $conn->query("SELECT id, label, phone_number, contact_type, is_primary 
                             FROM estate_emergency_contacts 
                             WHERE estate_id = $estate_id AND is_active = 1 
                             ORDER BY display_order ASC");
    $contacts = [];
    if ($con_res) {
        while ($ct = $con_res->fetch_assoc()) {
            $contacts[] = $ct;
        }
    }

    $act_res = $conn->query("SELECT id, action_title, description, target_status 
                             FROM estate_emergency_actions 
                             WHERE estate_id = $estate_id AND is_active = 1 
                             ORDER BY display_order ASC");
    $actions = [];
    if ($act_res) {
        while ($a = $act_res->fetch_assoc()) {
            $actions[] = $a;
        }
    }

    $active_guards = snapshotGuardsOnDuty($conn, $estate_id);
    $countdown_seconds = intval(getEmergencySetting($conn, $estate_id, 'emergency_countdown_seconds', '5'));
    if ($countdown_seconds < 1) $countdown_seconds = 5;

    echo json_encode([
        'success' => true,
        'countdown_seconds' => $countdown_seconds,
        'categories' => $categories,
        'contacts' => $contacts,
        'actions' => $actions,
        'guards_on_duty' => $active_guards
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
exit;
