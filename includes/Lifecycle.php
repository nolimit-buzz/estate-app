<?php
// includes/Lifecycle.php - estate lifecycle automation (run by cron/run.php) + manual lifecycle operations.
//
//   Active -> (due date passes unpaid) -> Grace Period -> Suspended -> Retention -> Deletion Pending -> Deleted
//                                           (grace_period_days)  (suspension_hold_days)  (retention_days from suspension)
//                                                                                   permanent deletion needs Super Admin approval
// Every threshold comes from platform_settings. All time arithmetic uses Lifecycle::$now so tests can time-travel.
require_once __DIR__ . '/Billing.php';
require_once __DIR__ . '/BackupService.php';
require_once __DIR__ . '/Invitations.php';

class Lifecycle {
    /** @var int|null unix timestamp override for tests */
    public static $now = null;
    public static function now() { return self::$now ?? time(); }
    /** @var int|null restrict a run to one estate (used by tests so a fake clock never touches real estates) */
    public static $scope = null;
    private static function sc($col = 'id') { return self::$scope ? " AND $col = " . intval(self::$scope) : ''; }
    private static function ts($t = null) { return date('Y-m-d H:i:s', $t ?? self::now()); }
    private static function dayStart($t) { return strtotime(date('Y-m-d', $t)); }
    private static function daysLeft($until) { return (int)ceil((strtotime($until) - self::now()) / 86400); }

    private static function once($estateId, $key) {
        $st = Tenancy::platform()->prepare("INSERT IGNORE INTO lifecycle_notices (estate_id, notice_key) VALUES (?,?)");
        $st->bind_param('is', $estateId, $key);
        $st->execute();
        return $st->affected_rows > 0;
    }

    private static function system($name) { return ['system', null, $name, 'cron']; }

    // ------------------------------------------------------------------ entry
    public static function run() {
        $log = [];
        $p = Tenancy::platform();
        $steps = [
            'trials' => 'processTrials', 'renewals' => 'processRenewals', 'grace' => 'processGrace', 'suspension' => 'processSuspended',
            'retention' => 'processRetention', 'unverified' => 'cleanupUnverified', 'invitations' => 'expireInvitations',
            'email' => 'flushEmail', 'counts' => 'refreshResidentCounts',
        ];
        foreach ($steps as $name => $fn) {
            $p->query("INSERT INTO job_runs (job_name) VALUES ('" . $p->real_escape_string("lifecycle.$name") . "')");
            $jid = $p->insert_id;
            try {
                $msg = self::$fn();
                $p->query("UPDATE job_runs SET status='success', finished_at=NOW(), message='" . $p->real_escape_string((string)$msg) . "' WHERE id=$jid");
                $log[] = "$name: $msg";
            } catch (Throwable $e) {
                $p->query("UPDATE job_runs SET status='failed', finished_at=NOW(), message='" . $p->real_escape_string($e->getMessage()) . "' WHERE id=$jid");
                Tenancy::logError('job', "Lifecycle step '$name' failed: " . $e->getMessage(), null, 'error');
                $log[] = "$name: FAILED " . $e->getMessage();
            }
        }
        return $log;
    }

    // ------------------------------------------------------------------ trials
    public static function processTrials() {
        $p = Tenancy::platform(); $n = 0;
        $r = $p->query("SELECT * FROM estates WHERE status = 'trial' AND trial_ends_at IS NOT NULL" . self::sc());
        while ($e = $r->fetch_assoc()) {
            $left = self::daysLeft($e['trial_ends_at']);
            if ($left <= 0) {
                self::suspend($e['id'], 'Trial expired without a paid subscription', self::system('trial expiry'));
                $n++;
            } elseif ($left <= 3 && self::once($e['id'], 'trial_ending:' . $left)) {
                Notifier::toCentralAdmins('trial_ending', $e, ['days_left' => $left, 'trial_ends' => date('j M Y', strtotime($e['trial_ends_at'])), 'link' => Notifier::estateUrl($e, 'admin/subscription')]);
            }
        }
        return "$n trial(s) expired";
    }

    // ------------------------------------------------------------------ renewals & due dates
    public static function processRenewals() {
        $p = Tenancy::platform(); $inv = 0; $rem = 0; $grace = 0; $pend = 0;
        $reminders = PlatformSettings::csvInts('reminder_days_before_due', '7,3,1'); // sorted desc
        $invoiceDays = max(PlatformSettings::int('renewal_invoice_days', 10), $reminders ? max($reminders) : 0);
        $r = $p->query("SELECT s.*, e.status estate_status FROM subscriptions s JOIN estates e ON e.id = s.estate_id
            WHERE s.status IN ('active','renewed') AND e.status IN ('active') AND s.period_end IS NOT NULL" . self::sc('s.estate_id'));
        while ($s = $r->fetch_assoc()) {
            $estate = Tenancy::find($s['estate_id']);
            $left = self::daysLeft($s['period_end']);

            // scheduled downgrade takes effect at period end
            if ($s['pending_plan_id'] && $s['pending_effective_at'] && strtotime($s['pending_effective_at']) <= self::now()) { self::applyPendingPlan($s); $pend++; $s = $p->query("SELECT * FROM subscriptions WHERE id = " . intval($s['id']))->fetch_assoc(); }

            if ($left <= $invoiceDays && $left > -1000) {
                $before = $p->query("SELECT COUNT(*) c FROM invoices WHERE subscription_id = " . intval($s['id']) . " AND kind='renewal' AND status IN ('pending','failed')")->fetch_assoc()['c'];
                if (!$before) { Billing::createRenewalInvoice($s); $inv++; }
            }
            $open = $p->query("SELECT * FROM invoices WHERE subscription_id = " . intval($s['id']) . " AND kind='renewal' AND status IN ('pending','failed') ORDER BY id DESC LIMIT 1")->fetch_assoc();
            $vars = fn($extra = []) => array_merge(['estate_name' => $estate['name'], 'due_date' => date('j M Y', strtotime($s['period_end'])), 'amount' => $open ? Billing::money($open['total']) : '', 'link' => Notifier::estateUrl($estate, 'admin/subscription')], $extra);

            if ($left > 0) {
                // send only the smallest threshold crossed, mark larger ones as sent
                $crossed = array_filter($reminders, fn($t) => $left <= $t);
                if ($crossed) {
                    $t = min($crossed); $key = "renew:{$s['id']}:" . date('Ymd', strtotime($s['period_end'])) . ":$t";
                    if (self::once($estate['id'], $key)) {
                        foreach ($crossed as $c) self::once($estate['id'], "renew:{$s['id']}:" . date('Ymd', strtotime($s['period_end'])) . ":$c");
                        Notifier::toCentralAdmins('renewal_upcoming', $estate, $vars(['days' => $left])); $rem++;
                    }
                }
            } else {
                if ($left === 0 || $left > -1) {
                    if (self::once($estate['id'], "due:{$s['id']}:" . date('Ymd', strtotime($s['period_end'])))) { Notifier::toCentralAdmins('payment_due', $estate, $vars()); $rem++; }
                }
                // overdue: enter grace (monthly) or suspend directly
                if (strtotime($s['period_end']) <= self::now()) { self::enterGrace($s, $estate); $grace++; }
            }
        }
        return "$inv invoice(s), $rem reminder(s), $grace to grace, $pend plan change(s)";
    }

    private static function applyPendingPlan(array $s) {
        $p = Tenancy::platform();
        $np = Plans::get($s['pending_plan_id']);
        if (!$np) return;
        $snap = json_encode(Plans::snapshot($np['id']) + ['addons' => []]);
        $cy = $s['pending_cycle'] ?: $s['billing_cycle'];
        $st = $p->prepare("UPDATE subscriptions SET plan_id=?, plan_version=?, plan_snapshot_json=?, resident_limit=?, billing_cycle=?, pending_plan_id=NULL, pending_cycle=NULL, pending_effective_at=NULL WHERE id=?");
        $v = intval($np['version']); $lim = intval($np['resident_limit']); $pid = intval($np['id']);
        $st->bind_param('iisisi', $pid, $v, $snap, $lim, $cy, $s['id']);
        $st->execute();
        $p->query("UPDATE invoices SET status='cancelled' WHERE subscription_id=" . intval($s['id']) . " AND kind='renewal' AND status IN ('pending','failed')");
        Entitlements::flush($s['estate_id']);
        Tenancy::mirror($s['estate_id']);
        Billing::event($s['estate_id'], $s['id'], 'plan_change', "Scheduled plan change applied: {$np['name']}");
        Audit::log('subscription.plan_change_applied', ['estate_id' => $s['estate_id'], 'module' => 'billing', 'entity_type' => 'subscription', 'entity_id' => $s['id'], 'details' => "Switched to {$np['name']}", 'actor' => self::system('scheduler')]);
    }

    private static function enterGrace(array $s, array $estate) {
        $p = Tenancy::platform();
        $graceDays = max(0, PlatformSettings::int('grace_period_days', 15));
        $applies = $s['billing_cycle'] === 'monthly' || PlatformSettings::bool('grace_applies_annual', false);
        if (!$applies || $graceDays === 0) { self::suspend($estate['id'], 'Payment overdue (no grace period applies)', self::system('billing')); return; }
        $graceEnds = self::ts(strtotime($s['period_end']) + $graceDays * 86400);
        $p->query("UPDATE subscriptions SET status='grace_period', grace_ends_at='$graceEnds' WHERE id=" . intval($s['id']));
        EstateService::setStatus($estate['id'], 'grace_period', ['grace_ends_at' => $graceEnds], 'Payment overdue');
        Billing::event($estate['id'], $s['id'], 'status_change', 'Payment overdue - grace period started until ' . date('j M Y', strtotime($graceEnds)));
        Audit::log('subscription.grace_started', ['estate_id' => $estate['id'], 'module' => 'billing', 'entity_type' => 'subscription', 'entity_id' => $s['id'], 'details' => "Grace until $graceEnds", 'actor' => self::system('billing')]);
        $e = Tenancy::find($estate['id']);
        if (self::once($estate['id'], "grace_start:{$s['id']}:" . date('Ymd', strtotime($s['period_end'])))) {
            Notifier::toCentralAdmins('grace_started', $e, ['grace_ends' => date('j M Y', strtotime($graceEnds)), 'link' => Notifier::estateUrl($e, 'admin/subscription')]);
        }
    }

    // ------------------------------------------------------------------ grace
    public static function processGrace() {
        $p = Tenancy::platform(); $rem = 0; $susp = 0;
        $every = max(1, PlatformSettings::int('grace_reminder_every_days', 5));
        $warn = max(0, PlatformSettings::int('suspension_warning_days', 2));
        $r = $p->query("SELECT * FROM estates WHERE status = 'grace_period' AND grace_ends_at IS NOT NULL" . self::sc());
        while ($e = $r->fetch_assoc()) {
            $left = self::daysLeft($e['grace_ends_at']);
            if (strtotime($e['grace_ends_at']) <= self::now()) {
                $p->query("UPDATE subscriptions SET status='suspended' WHERE estate_id = " . intval($e['id']) . " AND status IN ('grace_period','past_due')");
                self::suspend($e['id'], 'Grace period ended without payment', self::system('billing'));
                $susp++; continue;
            }
            $vars = ['grace_ends' => date('j M Y', strtotime($e['grace_ends_at'])), 'days_left' => $left, 'link' => Notifier::estateUrl($e, 'admin/subscription')];
            if ($left <= $warn) { if (self::once($e['id'], 'suspension_warning:' . date('Ymd', strtotime($e['grace_ends_at'])))) { Notifier::toCentralAdmins('suspension_warning', $e, $vars); $rem++; } }
            else {
                $elapsed = (int)floor((self::now() - strtotime($e['grace_ends_at']) + max(0, PlatformSettings::int('grace_period_days', 15)) * 86400) / 86400);
                $bucket = intdiv(max(0, $elapsed), $every);
                if ($elapsed > 0 && self::once($e['id'], 'grace_reminder:' . date('Ymd', strtotime($e['grace_ends_at'])) . ":$bucket")) { Notifier::toCentralAdmins('grace_reminder', $e, $vars); $rem++; }
            }
        }
        return "$rem reminder(s), $susp suspended";
    }

    // ------------------------------------------------------------------ suspension / reactivation
    public static function suspend($estateId, $reason, array $actor = null) {
        $e = Tenancy::find($estateId);
        if (!$e || in_array($e['status'], ['suspended', 'retention', 'deletion_pending', 'deleted', 'archived'], true)) return false;
        $days = max(1, PlatformSettings::int('retention_days', 45));
        $now = self::now();
        $retEnds = self::ts($now + $days * 86400);
        EstateService::setStatus($estateId, 'suspended', ['suspended_at' => self::ts($now), 'retention_ends_at' => $retEnds], $reason);
        Tenancy::platform()->query("UPDATE subscriptions SET status='suspended' WHERE estate_id = " . intval($estateId) . " AND status IN ('active','renewed','grace_period','past_due')");
        Entitlements::flush($estateId);
        Audit::log('estate.suspended', ['estate_id' => $estateId, 'module' => 'estates', 'entity_type' => 'estate', 'entity_id' => $estateId, 'details' => "$reason. Data preserved until " . $retEnds, 'actor' => $actor ?: Audit::actor()]);
        $e = Tenancy::find($estateId);
        Notifier::toCentralAdmins('account_suspended', $e, ['retention_ends' => date('j M Y', strtotime($retEnds)), 'retention_days' => $days, 'link' => Notifier::estateUrl($e, 'admin/subscription')]);
        return true;
    }

    /** Super Admin manual reactivation with a complimentary period. Normal reactivation happens by paying (Billing::markPaid). */
    public static function reactivate($estateId, $reason, $complimentaryDays = 30, array $actor = null) {
        $p = Tenancy::platform();
        $e = Tenancy::find($estateId);
        if (!$e || in_array($e['status'], ['deleted', 'archived'], true)) throw new RuntimeException('This estate cannot be reactivated.');
        $until = self::ts(self::now() + max(1, $complimentaryDays) * 86400);
        $sub = Billing::currentSubscription($estateId);
        if ($sub) {
            $p->query("UPDATE subscriptions SET status='active', period_end='$until', next_billing_date='$until', grace_ends_at=NULL WHERE id=" . intval($sub['id']));
            EstateService::setStatus($estateId, 'active', [], "Manually reactivated: $reason");
        } else {
            EstateService::setStatus($estateId, 'trial', ['trial_ends_at' => $until, 'suspended_at' => null, 'retention_ends_at' => null, 'grace_ends_at' => null, 'deletion_scheduled_at' => null], "Manually reactivated as trial: $reason");
            EstateService::setOnboarding($estateId, 'onboarding_pending');
        }
        Entitlements::flush($estateId);
        $p->query("DELETE FROM lifecycle_notices WHERE estate_id = " . intval($estateId));
        Billing::event($estateId, $sub['id'] ?? null, 'status_change', "Reactivated by platform admin until " . date('j M Y', strtotime($until)) . " - $reason");
        Audit::log('estate.reactivated', ['estate_id' => $estateId, 'module' => 'estates', 'entity_type' => 'estate', 'entity_id' => $estateId, 'details' => "$reason (until $until)", 'actor' => $actor ?: Audit::actor()]);
        Notifier::toCentralAdmins('account_reactivated', Tenancy::find($estateId), []);
        return true;
    }

    // ------------------------------------------------------------------ suspended -> retention -> deletion pending
    public static function processSuspended() {
        $p = Tenancy::platform(); $n = 0;
        $hold = max(0, PlatformSettings::int('suspension_hold_days', 7));
        $r = $p->query("SELECT * FROM estates WHERE status = 'suspended' AND suspended_at IS NOT NULL" . self::sc());
        while ($e = $r->fetch_assoc()) {
            if (strtotime($e['suspended_at']) + $hold * 86400 <= self::now()) {
                EstateService::setStatus($e['id'], 'retention', [], 'Suspension hold period ended - data retention period');
                $n++;
            }
        }
        return "$n moved to retention";
    }

    public static function processRetention() {
        $p = Tenancy::platform(); $warn = 0; $moved = 0;
        $marks = PlatformSettings::csvInts('retention_warning_days', '30,14,7');
        $r = $p->query("SELECT * FROM estates WHERE status IN ('suspended','retention') AND retention_ends_at IS NOT NULL" . self::sc());
        while ($e = $r->fetch_assoc()) {
            $left = self::daysLeft($e['retention_ends_at']);
            if ($left <= 0) {
                $finalDays = max(1, PlatformSettings::int('final_deletion_warning_days', 3));
                $deleteAfter = self::ts(self::now() + $finalDays * 86400);
                EstateService::setStatus($e['id'], 'deletion_pending', ['deletion_scheduled_at' => $deleteAfter], 'Retention period ended');
                $p->query("INSERT INTO deletion_requests (estate_id, status, note) VALUES (" . intval($e['id']) . ", 'awaiting_approval', 'Auto-created: retention period ended')");
                $e2 = Tenancy::find($e['id']);
                Notifier::toCentralAdmins('final_deletion_warning', $e2, ['deletion_date' => date('j M Y', strtotime($deleteAfter)), 'link' => Notifier::estateUrl($e2, 'admin/subscription')]);
                Notifier::notifySuperAdmin('Deletion approval needed', "Estate '{$e['name']}' (#{$e['id']}) finished its retention period and awaits deletion approval.");
                Audit::log('estate.deletion_pending', ['estate_id' => $e['id'], 'module' => 'estates', 'details' => "Retention ended; permanent deletion may be approved after $deleteAfter", 'actor' => self::system('retention')]);
                $moved++; continue;
            }
            $crossed = array_filter($marks, fn($t) => $left <= $t);
            if ($crossed) {
                $t = min($crossed);
                if (self::once($e['id'], 'retention_warn:' . date('Ymd', strtotime($e['retention_ends_at'])) . ":$t")) {
                    foreach ($crossed as $c) self::once($e['id'], 'retention_warn:' . date('Ymd', strtotime($e['retention_ends_at'])) . ":$c");
                    Notifier::toCentralAdmins('retention_warning', $e, ['days_left' => $left, 'retention_ends' => date('j M Y', strtotime($e['retention_ends_at'])), 'link' => Notifier::estateUrl($e, 'admin/subscription'), 'export_link' => Notifier::estateUrl($e, 'admin/export')]);
                    $warn++;
                }
            }
        }
        return "$warn warning(s), $moved awaiting deletion approval";
    }

    // ------------------------------------------------------------------ permanent deletion (never automatic)
    public static function requestDeletion($estateId, $byId, $note = '') {
        $e = Tenancy::find($estateId);
        if (!$e || !in_array($e['status'], ['suspended', 'retention', 'deletion_pending'], true)) throw new RuntimeException('Only suspended / retained estates can be queued for deletion.');
        $p = Tenancy::platform();
        $open = $p->query("SELECT id FROM deletion_requests WHERE estate_id = " . intval($estateId) . " AND status IN ('awaiting_approval','approved')")->fetch_assoc();
        if (!$open) { $st = $p->prepare("INSERT INTO deletion_requests (estate_id, requested_by, status, note) VALUES (?,?, 'awaiting_approval', ?)"); $st->bind_param('iis', $estateId, $byId, $note); $st->execute(); }
        if ($e['status'] !== 'deletion_pending') {
            $finalDays = max(1, PlatformSettings::int('final_deletion_warning_days', 3));
            EstateService::setStatus($estateId, 'deletion_pending', ['deletion_scheduled_at' => self::ts(self::now() + $finalDays * 86400)], 'Deletion requested' . ($note ? ": $note" : ''));
            Notifier::toCentralAdmins('final_deletion_warning', Tenancy::find($estateId), ['deletion_date' => date('j M Y', self::now() + $finalDays * 86400), 'link' => Notifier::estateUrl($e, 'admin/subscription')]);
        }
        Audit::log('estate.deletion_requested', ['estate_id' => $estateId, 'module' => 'estates', 'details' => $note ?: 'Deletion requested']);
    }

    /** Approve + execute: final backup -> drop tenant DB -> keep financial records. Returns true on success. */
    public static function approveAndDelete($estateId, $approverId, $force = false) {
        $p = Tenancy::platform();
        $e = Tenancy::find($estateId);
        if (!$e || $e['status'] !== 'deletion_pending') throw new RuntimeException('Estate is not awaiting deletion.');
        if (!$force && $e['deletion_scheduled_at'] && strtotime($e['deletion_scheduled_at']) > self::now()) {
            throw new RuntimeException('The final warning period has not ended yet (earliest deletion ' . date('j M Y H:i', strtotime($e['deletion_scheduled_at'])) . ').');
        }
        $req = $p->query("SELECT * FROM deletion_requests WHERE estate_id = " . intval($estateId) . " AND status IN ('awaiting_approval','approved') ORDER BY id DESC LIMIT 1")->fetch_assoc();
        if ($req && PlatformSettings::bool('deletion_requires_second_admin', false) && intval($req['requested_by']) === intval($approverId) && $req['requested_by']) {
            throw new RuntimeException('A different super admin must approve this deletion.');
        }
        // 1. final backup is mandatory
        $b = BackupService::backup($estateId, 'final', $approverId);
        // 2. drop database + user
        Tenancy::dropTenant($estateId);
        // 3. mark deleted; financial records stay in the control plane for the legal retention period
        $p->query("UPDATE estates SET status='deleted', onboarding_status='deletion_pending', deleted_at=" . "'" . self::ts() . "'" . ", db_pass_enc=NULL, deletion_approved_by=" . intval($approverId) . ", central_admin_email=NULL, contact_phone=NULL, current_subscription_id = current_subscription_id WHERE id = " . intval($estateId));
        $p->query("UPDATE subscriptions SET status='expired' WHERE estate_id = " . intval($estateId) . " AND status <> 'cancelled'");
        $p->query("UPDATE invitations SET status='cancelled' WHERE estate_id = " . intval($estateId) . " AND status='pending'");
        if ($req) $p->query("UPDATE deletion_requests SET status='executed', approved_by=" . intval($approverId) . ", final_backup_id=" . intval($b['id']) . ", executed_at=NOW() WHERE id=" . intval($req['id']));
        Entitlements::flush($estateId);
        $years = PlatformSettings::int('financial_retention_years', 7);
        Audit::log('estate.deleted', ['estate_id' => $estateId, 'module' => 'estates', 'entity_type' => 'estate', 'entity_id' => $estateId,
            'details' => "Tenant database permanently deleted after approval. Final backup #{$b['id']} kept; invoices/payments/audit retained for $years years."]);
        return true;
    }

    // ------------------------------------------------------------------ housekeeping
    public static function cleanupUnverified() {
        $days = max(1, PlatformSettings::int('unverified_cleanup_days', 7));
        $cut = self::ts(self::now() - $days * 86400);
        $p = Tenancy::platform(); $n = 0;
        $r = $p->query("SELECT id FROM estates WHERE status = 'pending' AND onboarding_status IN ('registration_started','email_verification_pending') AND created_at < '$cut'" . self::sc());
        while ($e = $r->fetch_assoc()) {
            try { Tenancy::dropTenant($e['id']); } catch (Throwable $x) {}
            $p->query("UPDATE estates SET status='deleted', deleted_at='" . self::ts() . "', db_pass_enc=NULL, central_admin_email=NULL, notes='Unverified sign-up removed automatically' WHERE id=" . intval($e['id']));
            Audit::log('estate.unverified_removed', ['estate_id' => $e['id'], 'module' => 'estates', 'details' => "Registration never verified within $days days", 'actor' => self::system('cleanup')]);
            $n++;
        }
        return "$n unverified registration(s) removed";
    }

    public static function expireInvitations() { return Invitations::expireDue() . ' invitation(s) expired'; }
    public static function flushEmail() { return Notifier::flushQueue(100) . ' queued email(s) delivered'; }

    public static function refreshResidentCounts() {
        $p = Tenancy::platform(); $n = 0;
        $r = $p->query("SELECT * FROM estates WHERE db_name IS NOT NULL AND status NOT IN ('deleted','archived','pending') AND (resident_count_at IS NULL OR resident_count_at < NOW() - INTERVAL 1 HOUR)");
        while ($e = $r->fetch_assoc()) { Billing::residentCount($e); $n++; }
        return "$n estate(s) counted";
    }

    public static function retentionDaysLeft(array $estate) {
        return $estate['retention_ends_at'] ? max(0, self::daysLeft($estate['retention_ends_at'])) : null;
    }
}
