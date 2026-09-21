<?php
// includes/NoticeManager.php
require_once __DIR__ . '/Mailer.php';

class NoticeManager {
    /**
     * Publish a new Estate Broadcast or Zonal Notice
     */
    public static function publishNotice($conn, $data) {
        $estate_id = intval($data['estate_id'] ?? get_estate_id());
        $zone_id = (!empty($data['zone_id']) && intval($data['zone_id']) > 0) ? intval($data['zone_id']) : null;
        $title = trim($data['title'] ?? '');
        $content = trim($data['content'] ?? '');
        $target_audience = in_array($data['target_audience'] ?? '', ['all', 'owners', 'tenants', 'residents']) ? $data['target_audience'] : 'all';
        $priority = in_array($data['priority'] ?? '', ['normal', 'important', 'urgent']) ? $data['priority'] : 'normal';
        $sender_type = in_array($data['sender_type'] ?? '', ['admin', 'zone', 'system']) ? $data['sender_type'] : 'admin';
        $sender_name = trim($data['sender_name'] ?? '');
        $created_by = intval($data['created_by'] ?? ($_SESSION['user_id'] ?? 1));
        $pin_to_top = !empty($data['pin_to_top']) ? 1 : 0;
        $dispatch_notification = !empty($data['dispatch_notification']);
        $dispatch_email = !empty($data['dispatch_email']);

        if (empty($title) || empty($content)) {
            return ['success' => false, 'message' => 'Title and content cannot be blank.'];
        }

        $title_esc = $conn->real_escape_string($title);
        $content_esc = $conn->real_escape_string($content);
        $zone_sql = $zone_id ? "$zone_id" : "NULL";
        $sender_name_esc = $sender_name ? "'" . $conn->real_escape_string($sender_name) . "'" : "NULL";

        $insert_sql = "INSERT INTO estate_announcements 
            (estate_id, zone_id, title, content, target_audience, priority, sender_type, sender_name, status, pin_to_top, created_by, created_at)
            VALUES 
            ($estate_id, $zone_sql, '$title_esc', '$content_esc', '$target_audience', '$priority', '$sender_type', $sender_name_esc, 'active', $pin_to_top, $created_by, NOW())";

        if (!$conn->query($insert_sql)) {
            return ['success' => false, 'message' => 'Database error: ' . $conn->error];
        }

        $notice_id = $conn->insert_id;

        // Fetch target users for In-App Notifications and/or Broadcast Email
        $target_users = self::getTargetUsers($conn, $estate_id, $zone_id, $target_audience);

        // 1. Dispatch in-app notifications
        $notification_count = 0;
        if ($dispatch_notification && !empty($target_users)) {
            $notif_title_esc = $conn->real_escape_string(($zone_id ? "[Zonal Notice] " : "[Estate Broadcast] ") . $title);
            $notif_msg_esc = $conn->real_escape_string(substr(strip_tags($content), 0, 200) . (strlen($content) > 200 ? '...' : ''));
            $notif_type = $zone_id ? 'zone_notice' : 'estate_broadcast';

            $values = [];
            foreach ($target_users as $u) {
                $uid = intval($u['id']);
                $values[] = "($estate_id, $uid, '$notif_title_esc', '$notif_msg_esc', '$notif_type', $notice_id, 0, NOW())";
            }

            if (!empty($values)) {
                // Chunk insert if large
                $chunks = array_chunk($values, 100);
                foreach ($chunks as $chunk) {
                    $bulk_sql = "INSERT INTO notifications (estate_id, user_id, title, message, type, reference_id, is_read, created_at) VALUES " . implode(',', $chunk);
                    $conn->query($bulk_sql);
                }
                $notification_count = count($values);
            }
        }

        // 2. Dispatch broadcast email
        $email_count = 0;
        if ($dispatch_email && !empty($target_users)) {
            $email_subject = ($zone_id ? "[Zonal Notice] " : "[Estate Broadcast] ") . $title;
            $email_count = EstateMailer::sendBroadcastEmail($conn, $email_subject, nl2br(htmlspecialchars($content)), $target_audience, $estate_id, $zone_id);
        }

        return [
            'success' => true,
            'notice_id' => $notice_id,
            'notifications_sent' => $notification_count,
            'emails_sent' => $email_count,
            'message' => 'Broadcast / Notice successfully dispatched!'
        ];
    }

    /**
     * Retrieve target users based on estate, zone and audience
     */
    public static function getTargetUsers($conn, $estate_id, $zone_id = null, $target_audience = 'all') {
        $estate_id = intval($estate_id);
        $users = [];

        if ($zone_id && $zone_id > 0) {
            $zone_id = intval($zone_id);
            // Residents in zone
            $sql = "SELECT DISTINCT u.id, u.name, u.email 
                    FROM users u
                    JOIN residents r ON r.user_id = u.id
                    JOIN flats f ON r.flat_id = f.id
                    JOIN buildings b ON f.building_id = b.id
                    JOIN streets s ON b.street_id = s.id
                    WHERE u.estate_id = $estate_id AND s.zone_id = $zone_id AND r.status = 'active'";
            
            if ($target_audience === 'tenants' || $target_audience === 'residents') {
                $sql .= " AND r.type = 'dependent' OR r.type = 'head'";
            }
            
            $res = $conn->query($sql);
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $users[$r['id']] = $r;
                }
            }

            // Also check property owners with properties in this zone if target_audience is 'all' or 'owners'
            if ($target_audience === 'all' || $target_audience === 'owners') {
                $owner_sql = "SELECT DISTINCT u.id, u.name, u.email
                              FROM users u
                              JOIN property_owners po ON (po.email = u.email AND po.estate_id = $estate_id)
                              JOIN owner_properties op ON po.id = op.owner_id
                              LEFT JOIN buildings b ON op.property_type = 'building' AND op.property_id = b.id
                              LEFT JOIN flats f ON op.property_type = 'flat' AND op.property_id = f.id
                              LEFT JOIN buildings fb ON f.building_id = fb.id
                              LEFT JOIN streets s ON (b.street_id = s.id OR fb.street_id = s.id)
                              WHERE u.estate_id = $estate_id AND s.zone_id = $zone_id";
                $ores = $conn->query($owner_sql);
                if ($ores) {
                    while ($r = $ores->fetch_assoc()) {
                        $users[$r['id']] = $r;
                    }
                }
            }
        } else {
            // Estate wide
            $where = "estate_id = $estate_id";
            if ($target_audience === 'owners') {
                $where .= " AND role = 'owner'";
            } elseif ($target_audience === 'tenants' || $target_audience === 'residents') {
                $where .= " AND role = 'resident'";
            }
            $res = $conn->query("SELECT id, name, email FROM users WHERE $where");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $users[$r['id']] = $r;
                }
            }
        }

        return array_values($users);
    }

    /**
     * Get notices visible to a specific resident
     */
    public static function getNoticesForResident($conn, $user_id, $estate_id, $limit = 20, $filter_type = 'all') {
        $user_id = intval($user_id);
        $estate_id = intval($estate_id);

        // Find resident's zone
        $res_q = "SELECT s.zone_id, z.name as zone_name, z.code as zone_code, r.type as resident_type 
                  FROM residents r 
                  LEFT JOIN flats f ON r.flat_id = f.id 
                  LEFT JOIN buildings b ON f.building_id = b.id 
                  LEFT JOIN streets s ON b.street_id = s.id 
                  LEFT JOIN zones z ON s.zone_id = z.id
                  WHERE r.user_id = $user_id AND r.estate_id = $estate_id 
                  ORDER BY r.id DESC LIMIT 1";
        $res_meta = $conn->query($res_q)->fetch_assoc();
        $zone_id = $res_meta['zone_id'] ?? 0;

        $conditions = ["ea.estate_id = $estate_id", "ea.status = 'active'"];

        if ($filter_type === 'estate') {
            $conditions[] = "(ea.zone_id IS NULL OR ea.zone_id = 0)";
        } elseif ($filter_type === 'zone') {
            if ($zone_id) {
                $conditions[] = "ea.zone_id = " . intval($zone_id);
            } else {
                $conditions[] = "1=0"; // No zone
            }
        } elseif ($filter_type === 'urgent') {
            $conditions[] = "ea.priority = 'urgent'";
            if ($zone_id) {
                $conditions[] = "(ea.zone_id IS NULL OR ea.zone_id = 0 OR ea.zone_id = " . intval($zone_id) . ")";
            } else {
                $conditions[] = "(ea.zone_id IS NULL OR ea.zone_id = 0)";
            }
        } else {
            // All applicable notices (Estate broadcast OR resident's zone)
            if ($zone_id) {
                $conditions[] = "(ea.zone_id IS NULL OR ea.zone_id = 0 OR ea.zone_id = " . intval($zone_id) . ")";
            } else {
                $conditions[] = "(ea.zone_id IS NULL OR ea.zone_id = 0)";
            }
        }

        $where_sql = implode(' AND ', $conditions);

        $query = "SELECT ea.*, z.name as zone_name, z.code as zone_code, u.name as author_name 
                  FROM estate_announcements ea
                  LEFT JOIN zones z ON ea.zone_id = z.id
                  LEFT JOIN users u ON ea.created_by = u.id
                  WHERE $where_sql
                  ORDER BY ea.pin_to_top DESC, ea.created_at DESC 
                  LIMIT " . intval($limit);

        return [
            'results' => $conn->query($query),
            'resident_zone_id' => $zone_id,
            'resident_zone_name' => $res_meta['zone_name'] ?? null,
            'resident_zone_code' => $res_meta['zone_code'] ?? null
        ];
    }
}
