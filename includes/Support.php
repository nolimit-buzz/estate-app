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

    public static function addMessage($ticketId, $authorType, $authorName, $message) {
        $st = Tenancy::platform()->prepare("INSERT INTO ticket_messages (ticket_id, author_type, author_name, message) VALUES (?,?,?,?)");
        $st->bind_param('isss', $ticketId, $authorType, $authorName, $message);
        $st->execute();
        Tenancy::platform()->query("UPDATE support_tickets SET updated_at = NOW() WHERE id = " . intval($ticketId));
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
        $r = Tenancy::platform()->query("SELECT * FROM support_tickets WHERE estate_id = " . intval($estateId) . " ORDER BY updated_at DESC");
        while ($r && $row = $r->fetch_assoc()) $out[] = $row;
        return $out;
    }
}
