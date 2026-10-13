<?php
// includes/Support.php - support & onboarding tickets (control plane; estates only ever see their own).
require_once __DIR__ . '/Tenancy.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/Notifier.php';

class Support {
    public static function newNumber() {
        return 'TK-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    }

    public static function create($estateId, $category, $subject, $message, $name, $email, $priority = 'normal') {
        $p = Tenancy::platform();
        $no = self::newNumber();
        $st = $p->prepare("INSERT INTO support_tickets (ticket_no, estate_id, category, subject, priority, created_by_name, created_by_email) VALUES (?,?,?,?,?,?,?)");
        $st->bind_param('sisssss', $no, $estateId, $category, $subject, $priority, $name, $email);
        $st->execute();
        $id = $p->insert_id;
        self::addMessage($id, 'estate', $name, $message);
        Audit::log('ticket.created', ['estate_id' => $estateId, 'module' => 'support', 'entity_type' => 'ticket', 'entity_id' => $id, 'details' => "[$no] $subject"]);
        return $id;
    }

    public static function addMessage($ticketId, $authorType, $authorName, $message, $internal = false) {
        $int = $internal ? 1 : 0;
        $st = Tenancy::platform()->prepare("INSERT INTO ticket_messages (ticket_id, author_type, author_name, message, is_internal) VALUES (?,?,?,?,?)");
        $st->bind_param('isssi', $ticketId, $authorType, $authorName, $message, $int);
        $st->execute();
        $id = intval($ticketId);
        $extra = ($authorType === 'platform' && !$internal) ? ", first_response_at = COALESCE(first_response_at, NOW())" : '';
        Tenancy::platform()->query("UPDATE support_tickets SET updated_at = NOW() $extra WHERE id = $id");
    }

    // ------------------------------------------------------------------ workflow (platform side)
    const STATUSES = ['open', 'in_progress', 'awaiting_customer', 'resolved', 'closed'];
    const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    const CATEGORIES = ['onboarding', 'billing', 'technical', 'module_request', 'plan_change', 'other'];

    /** Ticket joined with its estate (including the estate lifecycle status), or null. */
    public static function find($id) {
        $st = Tenancy::platform()->prepare("SELECT t.*, e.name estate_name, e.status estate_status, e.onboarding_status, e.db_name, au.name assignee_name
            FROM support_tickets t JOIN estates e ON e.id = t.estate_id LEFT JOIN platform_users au ON au.id = t.assigned_to WHERE t.id = ? LIMIT 1");
        $st->bind_param('i', $id);
        $st->execute();
        return $st->get_result()->fetch_assoc() ?: null;
    }

    /** Messages of a ticket. Estates only ever receive the public ones. */
    public static function messages($ticketId, $includeInternal = false) {
        $st = Tenancy::platform()->prepare("SELECT * FROM ticket_messages WHERE ticket_id = ?" . ($includeInternal ? '' : ' AND is_internal = 0') . " ORDER BY id");
        $st->bind_param('i', $ticketId);
        $st->execute();
        return $st->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** Tickets that need an operator (open + in progress) from estates that still exist. Single definition used by every badge. */
    public static function queueCount() {
        $r = Tenancy::platform()->query("SELECT COUNT(*) c FROM support_tickets t JOIN estates e ON e.id = t.estate_id WHERE t.status IN ('open','in_progress') AND e.status NOT IN ('deleted','archived')");
        return $r ? intval($r->fetch_assoc()['c']) : 0;
    }

    public static function setStatus($ticketId, $status) {
        if (!in_array($status, self::STATUSES, true)) throw new InvalidArgumentException('Invalid status.');
        $closed = in_array($status, ['resolved', 'closed'], true);
        $st = Tenancy::platform()->prepare("UPDATE support_tickets SET status = ?, closed_at = " . ($closed ? 'COALESCE(closed_at, NOW())' : 'NULL') . ", updated_at = NOW() WHERE id = ?");
        $st->bind_param('si', $status, $ticketId);
        $st->execute();
    }

    public static function setPriority($ticketId, $priority) {
        if (!in_array($priority, self::PRIORITIES, true)) throw new InvalidArgumentException('Invalid priority.');
        $st = Tenancy::platform()->prepare("UPDATE support_tickets SET priority = ?, updated_at = NOW() WHERE id = ?");
        $st->bind_param('si', $priority, $ticketId);
        $st->execute();
    }

    public static function setCategory($ticketId, $category) {
        if (!in_array($category, self::CATEGORIES, true)) throw new InvalidArgumentException('Invalid category.');
        $st = Tenancy::platform()->prepare("UPDATE support_tickets SET category = ?, updated_at = NOW() WHERE id = ?");
        $st->bind_param('si', $category, $ticketId);
        $st->execute();
    }

    /** Assign to an active platform user, or null to unassign. Returns the assignee's name (or null). */
    public static function assign($ticketId, $platformUserId) {
        $p = Tenancy::platform();
        $name = null;
        if ($platformUserId) {
            $st = $p->prepare("SELECT name FROM platform_users WHERE id = ? AND status = 'active'");
            $st->bind_param('i', $platformUserId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            if (!$row) throw new InvalidArgumentException('Assignee must be an active platform user.');
            $name = $row['name'];
            $st = $p->prepare("UPDATE support_tickets SET assigned_to = ?, updated_at = NOW() WHERE id = ?");
            $st->bind_param('ii', $platformUserId, $ticketId);
        } else {
            $st = $p->prepare("UPDATE support_tickets SET assigned_to = NULL, updated_at = NOW() WHERE id = ?");
            $st->bind_param('i', $ticketId);
        }
        $st->execute();
        return $name;
    }

    /** Opened automatically once the first Central Admin has verified their email. */
    public static function createOnboardingTicket(array $estate, array $admin) {
        $p = Tenancy::platform();
        $ex = $p->query("SELECT id FROM support_tickets WHERE estate_id = " . intval($estate['id']) . " AND category = 'onboarding' LIMIT 1");
        if ($ex && $ex->num_rows) return 0;
        $msg = "Estate \"{$estate['name']}\" has completed registration. Central Admin: {$admin['name']} <{$admin['email']}>.\n\nNext steps: submit the onboarding details form, choose a subscription plan and complete payment.";
        return self::create($estate['id'], 'onboarding', 'Onboarding request - ' . $estate['name'], $msg, $admin['name'], $admin['email'], 'normal');
    }

    /** Central Admin submits the onboarding form -> Support Review. */
    public static function submitOnboardingRequest(array $estate, array $admin, array $details) {
        $lines = [];
        foreach (['estimated_residents' => 'Estimated residents', 'zones' => 'Number of zones/blocks', 'modules_needed' => 'Modules of interest', 'go_live' => 'Target go-live date', 'notes' => 'Notes'] as $k => $label) {
            if (!empty($details[$k])) $lines[] = "$label: " . (is_array($details[$k]) ? implode(', ', $details[$k]) : $details[$k]);
        }
        $p = Tenancy::platform();
        $tk = $p->query("SELECT id FROM support_tickets WHERE estate_id = " . intval($estate['id']) . " AND category = 'onboarding' ORDER BY id LIMIT 1")->fetch_assoc();
        $tid = $tk['id'] ?? self::createOnboardingTicket($estate, $admin);
        self::addMessage($tid, 'estate', $admin['name'], "Onboarding details submitted:\n" . implode("\n", $lines));
        $p->query("UPDATE support_tickets SET status = 'in_progress' WHERE id = " . intval($tid));
        $json = json_encode($details);
        $st = $p->prepare("UPDATE estates SET onboarding_json = ? WHERE id = ?");
        $st->bind_param('si', $json, $estate['id']);
        $st->execute();
        require_once __DIR__ . '/EstateService.php';
        if (in_array($estate['onboarding_status'], ['onboarding_pending', 'email_verification_pending', 'central_admin_verification_pending'], true)) {
            EstateService::setOnboarding($estate['id'], 'support_review');
        }
        Audit::log('onboarding.submitted', ['estate_id' => $estate['id'], 'module' => 'onboarding', 'details' => 'Central Admin submitted onboarding request.']);
        return $tid;
    }

    public static function forEstate($estateId) {
        $out = [];
        $r = Tenancy::platform()->query("SELECT t.*, au.name assignee_name FROM support_tickets t LEFT JOIN platform_users au ON au.id = t.assigned_to WHERE t.estate_id = " . intval($estateId) . " ORDER BY t.updated_at DESC");
        while ($r && $row = $r->fetch_assoc()) $out[] = $row;
        return $out;
    }
}
