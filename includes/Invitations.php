<?php
// includes/Invitations.php - mandatory invitation flow for every account added to an estate.
// A user row is created in 'invited' state with an unusable password; it only becomes 'active' after the
// invitee opens the emailed single-use link, sets a password and thereby proves control of the mailbox.
// The control plane holds the token index (hashed), status history and expiry.
require_once __DIR__ . '/Tenancy.php';
require_once __DIR__ . '/PlatformSettings.php';
require_once __DIR__ . '/Notifier.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/EstateService.php';

class Invitations {
    public static function unusablePassword() { return password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT); }

    public static function roleLabel($role) {
        return ['admin' => 'Central Admin', 'manager' => 'Estate Manager', 'zone_admin' => 'Zone Administrator', 'resident' => 'Resident', 'staff' => 'Staff member',
            'security' => 'Security / Gate Manager', 'accountant' => 'Accountant', 'technician' => 'Technician'][$role] ?? ucfirst(str_replace('_', ' ', $role));
    }

    /** Invite an already-created tenant user stub (status 'invited'). Returns invitation id. */
    public static function sendForUser(array $estate, $userId, $email, $name, $role, $invitedById = null, $invitedByName = null, array $payload = []) {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Invalid email address for invitation.');
        $p = Tenancy::platform();
        // supersede earlier open invitations for the same user
        $p->query("UPDATE invitations SET status = 'cancelled' WHERE estate_id = " . intval($estate['id']) . " AND email = '" . $p->real_escape_string($email) . "' AND status = 'pending'");
        $token = Crypto::token(24);
        $th = Crypto::hashToken($token);
        $days = max(1, PlatformSettings::int('invitation_expiry_days', 7));
        $payload['user_id'] = intval($userId);
        $pj = json_encode($payload);
        $st = $p->prepare("INSERT INTO invitations (estate_id, email, name, role, payload_json, token_hash, invited_by, invited_by_name, expires_at, last_sent_at)
            VALUES (?,?,?,?,?,?,?,?, NOW() + INTERVAL ? DAY, NOW())");
        $st->bind_param('isssssisi', $estate['id'], $email, $name, $role, $pj, $th, $invitedById, $invitedByName, $days);
        $st->execute();
        $id = $p->insert_id;
        self::deliver($estate, $id, $token, $email, $name, $role, $invitedByName);
        Audit::log('invitation.sent', ['estate_id' => $estate['id'], 'module' => 'invitations', 'entity_type' => 'invitation', 'entity_id' => $id,
            'details' => "Invited $email as " . self::roleLabel($role)]);
        return $id;
    }

    private static function deliver(array $estate, $invId, $token, $email, $name, $role, $inviter) {
        $inv = Tenancy::platform()->query("SELECT expires_at FROM invitations WHERE id = " . intval($invId))->fetch_assoc();
        $ok = Notifier::send('invitation', $email, $name, [
            'inviter' => $inviter ?: 'The estate administrator', 'role' => self::roleLabel($role),
            'link' => Notifier::baseUrl() . '/accept_invite?token=' . $token,
            'decline_link' => Notifier::baseUrl() . '/accept_invite?token=' . $token . '&decline=1',
            'expires_at' => date('j M Y, g:ia', strtotime($inv['expires_at'])),
        ], $estate);
        if (!$ok) Tenancy::logError('invitation', "Invitation email to $email could not be queued", $estate['id']);
        return $ok;
    }

    public static function resend($invId) {
        $p = Tenancy::platform();
        $inv = $p->query("SELECT * FROM invitations WHERE id = " . intval($invId))->fetch_assoc();
        if (!$inv || !in_array($inv['status'], ['pending', 'expired'], true)) throw new RuntimeException('This invitation cannot be resent.');
        $estate = Tenancy::find($inv['estate_id']);
        $token = Crypto::token(24);
        $th = Crypto::hashToken($token);
        $days = max(1, PlatformSettings::int('invitation_expiry_days', 7));
        $st = $p->prepare("UPDATE invitations SET token_hash = ?, status = 'pending', expires_at = NOW() + INTERVAL ? DAY, last_sent_at = NOW(), send_count = send_count + 1 WHERE id = ?");
        $st->bind_param('sii', $th, $days, $invId);
        $st->execute();
        self::deliver($estate, $invId, $token, $inv['email'], $inv['name'], $inv['role'], $inv['invited_by_name']);
        Audit::log('invitation.resent', ['estate_id' => $inv['estate_id'], 'module' => 'invitations', 'entity_type' => 'invitation', 'entity_id' => $invId, 'details' => "Resent to {$inv['email']}"]);
    }

    public static function cancel($invId, $estateId) {
        $p = Tenancy::platform();
        $inv = $p->query("SELECT * FROM invitations WHERE id = " . intval($invId) . " AND estate_id = " . intval($estateId))->fetch_assoc();
        if (!$inv || $inv['status'] !== 'pending') throw new RuntimeException('Only pending invitations can be cancelled.');
        $p->query("UPDATE invitations SET status = 'cancelled' WHERE id = " . intval($invId));
        self::disableStubUser($inv);
        Audit::log('invitation.cancelled', ['estate_id' => $estateId, 'module' => 'invitations', 'entity_type' => 'invitation', 'entity_id' => $invId, 'details' => "Cancelled invitation for {$inv['email']}"]);
    }

    private static function disableStubUser(array $inv) {
        $payload = json_decode($inv['payload_json'] ?? '{}', true) ?: [];
        if (empty($payload['user_id'])) return;
        try {
            $estate = Tenancy::find($inv['estate_id']);
            $t = Tenancy::openTenant($estate);
            $uid = intval($payload['user_id']);
            $t->query("UPDATE users SET status = 'disabled' WHERE id = $uid AND status = 'invited'");
            $t->close();
        } catch (Throwable $e) {}
    }

    /** @return array|null ['inv'=>row,'estate'=>row,'state'=>pending|expired|accepted|...] */
    public static function lookup($token) {
        if (!$token || !preg_match('/^[a-f0-9]{20,100}$/', $token)) return null;
        $st = Tenancy::platform()->prepare("SELECT * FROM invitations WHERE token_hash = ? LIMIT 1");
        $h = Crypto::hashToken($token);
        $st->bind_param('s', $h);
        $st->execute();
        $inv = $st->get_result()->fetch_assoc();
        if (!$inv) return null;
        if ($inv['status'] === 'pending' && strtotime($inv['expires_at']) < time()) {
            Tenancy::platform()->query("UPDATE invitations SET status = 'expired' WHERE id = " . intval($inv['id']));
            $inv['status'] = 'expired';
        }
        return ['inv' => $inv, 'estate' => Tenancy::find($inv['estate_id'])];
    }

    /** Accept: set password, activate account, mark invitation accepted (single use). */
    public static function accept($token, $password) {
        $ctx = self::lookup($token);
        if (!$ctx || $ctx['inv']['status'] !== 'pending') throw new RuntimeException('This invitation is no longer valid.');
        $err = EstateService::validatePassword($password);
        if ($err) throw new InvalidArgumentException($err);
        $inv = $ctx['inv']; $estate = $ctx['estate'];
        $payload = json_decode($inv['payload_json'] ?? '{}', true) ?: [];
        $uid = intval($payload['user_id'] ?? 0);
        $t = Tenancy::openTenant($estate);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $u = $t->prepare("UPDATE users SET password = ?, status = 'active', force_password_change = 0, email_verified_at = NOW(), password_changed_at = NOW() WHERE id = ? AND status = 'invited'");
        $u->bind_param('si', $hash, $uid);
        $u->execute();
        if ($u->affected_rows < 1) { $t->close(); throw new RuntimeException('This invitation has already been used.'); }
        $user = $t->query("SELECT id, name, email, role FROM users WHERE id = $uid")->fetch_assoc();
        $t->close();
        // single use: claim atomically
        $p = Tenancy::platform();
        $p->query("UPDATE invitations SET status = 'accepted', accepted_at = NOW() WHERE id = " . intval($inv['id']) . " AND status = 'pending'");
        Audit::log('invitation.accepted', ['estate_id' => $estate['id'], 'module' => 'invitations', 'entity_type' => 'invitation', 'entity_id' => $inv['id'],
            'details' => "{$inv['email']} accepted invitation as " . self::roleLabel($inv['role']), 'actor' => ['tenant', $uid, $user['name'], $inv['role']]]);
        // let the inviter know
        if (!empty($inv['invited_by'])) {
            try {
                $tt = Tenancy::openTenant($estate);
                $by = $tt->query("SELECT name, email FROM users WHERE id = " . intval($inv['invited_by']))->fetch_assoc();
                $tt->close();
                if ($by) Notifier::send('invitation_accepted', $by['email'], $by['name'], ['invitee' => $user['name'], 'role' => self::roleLabel($inv['role'])], $estate);
            } catch (Throwable $e) {}
        }
        return ['user' => $user, 'estate' => $estate];
    }

    public static function decline($token) {
        $ctx = self::lookup($token);
        if (!$ctx || $ctx['inv']['status'] !== 'pending') return false;
        Tenancy::platform()->query("UPDATE invitations SET status = 'declined' WHERE id = " . intval($ctx['inv']['id']));
        self::disableStubUser($ctx['inv']);
        Audit::log('invitation.declined', ['estate_id' => $ctx['inv']['estate_id'], 'module' => 'invitations', 'entity_type' => 'invitation', 'entity_id' => $ctx['inv']['id'], 'details' => "{$ctx['inv']['email']} declined", 'actor' => ['system', null, $ctx['inv']['email'], 'invitee']]);
        return true;
    }

    /** Cron: mark overdue invitations expired (and disable their stub accounts). */
    public static function expireDue() {
        $p = Tenancy::platform();
        $r = $p->query("SELECT * FROM invitations WHERE status = 'pending' AND expires_at < NOW()");
        $n = 0;
        while ($r && $inv = $r->fetch_assoc()) {
            $p->query("UPDATE invitations SET status = 'expired' WHERE id = " . intval($inv['id']));
            $n++;
        }
        return $n;
    }

    public static function forEstate($estateId, $status = null) {
        $sql = "SELECT * FROM invitations WHERE estate_id = " . intval($estateId) . ($status ? " AND status = '" . Tenancy::platform()->real_escape_string($status) . "'" : '') . " ORDER BY id DESC LIMIT 500";
        $out = [];
        $r = Tenancy::platform()->query($sql);
        while ($r && $row = $r->fetch_assoc()) { if ($row['status'] === 'pending' && strtotime($row['expires_at']) < time()) $row['status'] = 'expired'; $out[] = $row; }
        return $out;
    }
}

/**
 * Helper for the legacy portals: create the invitation for a user the page has just inserted as 'invited'.
 * Returns true when the invitation email was queued.
 */
function invite_new_user($conn, $userId, $email, $name, $role, array $payload = []) {
    try {
        $estate = Tenancy::$estate;
        if (!$estate) return false;
        Invitations::sendForUser($estate, $userId, $email, $name, $role, $_SESSION['user_id'] ?? null, $_SESSION['name'] ?? null, $payload);
        return true;
    } catch (Throwable $e) {
        Tenancy::logError('invitation', 'invite_new_user failed: ' . $e->getMessage(), Tenancy::currentId());
        return false;
    }
}
