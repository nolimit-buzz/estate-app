<?php
// includes/SubscriptionStatus.php - everything the Central Admin needs to see about their own estate's subscription
// (read from the control plane; never exposes other estates or platform configuration).
require_once __DIR__ . '/Billing.php';
require_once __DIR__ . '/Support.php';

class SubscriptionStatus {
    public static function summary() {
        static $cache = null;
        if ($cache !== null) return $cache;
        $estate = Tenancy::$estate;
        if (!$estate) return $cache = null;
        $id = intval($estate['id']);
        $p = Tenancy::platform();
        $estate = Tenancy::find($id); // fresh
        $sub = Billing::currentSubscription($id);
        $snap = $sub && $sub['plan_snapshot_json'] ? json_decode($sub['plan_snapshot_json'], true) : null;
        $residents = intval($estate['resident_count']);
        $limit = $sub ? intval($sub['resident_limit']) : 0;
        if (!$sub && $estate['status'] === 'trial') $limit = PlatformSettings::int('trial_resident_limit', 25);
        $open = $p->query("SELECT * FROM invoices WHERE estate_id = $id AND status IN ('pending','failed') ORDER BY id DESC LIMIT 1")->fetch_assoc();
        $tickets = intval($p->query("SELECT COUNT(*) c FROM support_tickets WHERE estate_id = $id AND status IN ('open','in_progress','awaiting_customer')")->fetch_assoc()['c']);
        $now = time();
        $s = [
            'estate' => $estate, 'status' => $estate['status'], 'onboarding' => $estate['onboarding_status'], 'sub' => $sub, 'snapshot' => $snap,
            'residents' => $residents, 'limit' => $limit, 'open_invoice' => $open, 'open_tickets' => $tickets,
            'trial_days_left' => $estate['trial_ends_at'] ? max(0, (int)ceil((strtotime($estate['trial_ends_at']) - $now) / 86400)) : null,
            'grace_days_left' => $estate['grace_ends_at'] && $estate['status'] === 'grace_period' ? max(0, (int)ceil((strtotime($estate['grace_ends_at']) - $now) / 86400)) : null,
            'retention_days_left' => $estate['retention_ends_at'] ? max(0, (int)ceil((strtotime($estate['retention_ends_at']) - $now) / 86400)) : null,
            'due_days' => $sub && $sub['period_end'] ? (int)ceil((strtotime($sub['period_end']) - $now) / 86400) : null,
        ];
        $s['payment_status'] = $open ? ($open['status'] === 'failed' ? 'failed' : 'pending') : ($sub && in_array($sub['status'], ['active', 'renewed']) ? 'paid' : '—');
        return $cache = $s;
    }

    /** Onboarding checklist shown on the Central Admin dashboard. */
    public static function checklist() {
        $s = self::summary();
        if (!$s) return [];
        $p = Tenancy::platform();
        $id = intval($s['estate']['id']);
        $onb = json_decode($s['estate']['onboarding_json'] ?? '{}', true) ?: [];
        $paid = $s['sub'] && in_array($s['sub']['status'], ['active', 'renewed', 'grace_period']);
        $invited = intval($p->query("SELECT COUNT(*) c FROM invitations WHERE estate_id = $id")->fetch_assoc()['c']);
        return [
            ['Verify your email', true, null],
            ['Submit onboarding details', !empty($onb['estimated_residents']) || !in_array($s['onboarding'], ['onboarding_pending', 'email_verification_pending']), 'onboarding'],
            ['Choose a plan', (bool)$s['sub'], 'subscription'],
            ['Complete payment', $paid, 'subscription'],
            ['Invite your team and residents', $invited > 0 || $s['residents'] > 1, 'invitations'],
        ];
    }

    /** Banner for the top of every Central Admin page (null = nothing to say). */
    public static function banner() {
        $s = self::summary();
        if (!$s || !in_array($_SESSION['role'] ?? '', ['admin', 'manager'], true)) return null;
        $link = 'subscription';
        switch ($s['status']) {
            case 'grace_period':
                return ['danger', "Payment overdue. Your estate stays available until " . date('j M Y', strtotime($s['estate']['grace_ends_at'])) . " ({$s['grace_days_left']} day(s) left). After that the estate will be suspended.", $link, 'Pay now'];
            case 'trial':
                return [$s['trial_days_left'] <= 3 ? 'warning' : 'info', "Free trial: {$s['trial_days_left']} day(s) left. Choose a plan to keep your estate running.", $link, 'Choose a plan'];
            case 'onboarding': case 'pending':
                return ['info', 'Finish setting up: submit your onboarding details, choose a plan and complete payment to unlock all modules.', 'onboarding', 'Continue setup'];
            case 'active':
                if ($s['open_invoice'] && $s['due_days'] !== null && $s['due_days'] <= max(PlatformSettings::csvInts('reminder_days_before_due', '7,3,1') ?: [7])) {
                    return ['warning', "Your subscription renews " . ($s['due_days'] <= 0 ? 'today' : "in {$s['due_days']} day(s)") . '. Pay the renewal invoice to avoid interruption.', $link, 'View invoice'];
                }
        }
        return null;
    }
}
