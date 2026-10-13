<?php
// includes/Billing.php - plans -> quotes -> invoices -> payments -> subscription activation / renewal / plan change.
// All prices, limits and cycles come from the DB (plans / modules / platform_settings). The payments table is an
// append-only ledger (DB triggers block UPDATE/DELETE after a payment leaves 'pending').
require_once __DIR__ . '/Tenancy.php';
require_once __DIR__ . '/PlatformSettings.php';
require_once __DIR__ . '/Entitlements.php';
require_once __DIR__ . '/EstateService.php';
require_once __DIR__ . '/Notifier.php';
require_once __DIR__ . '/Audit.php';

class Billing {
    public static function money($amount, $currency = null) {
        $sym = PlatformSettings::get('currency_symbol', '₦');
        return $sym . number_format((float)$amount, 2);
    }

    // ------------------------------------------------------------------ facts
    public static function residentCount($estateOrId) {
        $estate = is_array($estateOrId) ? $estateOrId : Tenancy::find($estateOrId);
        if (!$estate || empty($estate['db_name'])) return 0;
        try {
            $t = (Tenancy::currentId() == $estate['id'] && Tenancy::$conn && !Tenancy::isPlatformConn(Tenancy::$conn)) ? Tenancy::$conn : Tenancy::openTenant($estate);
            $n = intval($t->query("SELECT COUNT(*) c FROM residents WHERE status = 'active'")->fetch_assoc()['c']);
            Tenancy::platform()->query("UPDATE estates SET resident_count = $n, resident_count_at = NOW() WHERE id = " . intval($estate['id']));
            return $n;
        } catch (Throwable $e) { return intval($estate['resident_count'] ?? 0); }
    }

    public static function currentSubscription($estateId) {
        $r = Tenancy::platform()->query("SELECT s.*, p.name AS plan_name FROM subscriptions s JOIN plans p ON p.id = s.plan_id
            WHERE s.estate_id = " . intval($estateId) . " AND s.status <> 'cancelled' ORDER BY s.id DESC LIMIT 1");
        return $r ? ($r->fetch_assoc() ?: null) : null;
    }

    public static function publicPlans() {
        $out = [];
        $r = Tenancy::platform()->query("SELECT * FROM plans WHERE status = 'active' AND show_public = 1 ORDER BY sort_order, monthly_price");
        while ($r && $row = $r->fetch_assoc()) { $row['modules'] = Plans::modules($row['id']); $out[] = $row; }
        return $out;
    }

    /** Smallest active plan whose resident limit covers $count. */
    public static function recommendPlan($count) {
        $r = Tenancy::platform()->query("SELECT * FROM plans WHERE status = 'active' ORDER BY (resident_limit = 0), resident_limit ASC, monthly_price ASC");
        while ($r && $p = $r->fetch_assoc()) if (intval($p['resident_limit']) === 0 || intval($p['resident_limit']) >= $count) return $p;
        return null;
    }

    // ------------------------------------------------------------------ quote
    /** @return array ['lines'=>[...], 'subtotal'=>float, 'plan'=>row, 'cycle'=>string] */
    public static function quote($planId, $cycle, array $addonKeys = []) {
        $plan = Plans::get($planId);
        if (!$plan || $plan['status'] !== 'active') throw new InvalidArgumentException('That plan is not available.');
        $cycle = $cycle === 'annual' ? 'annual' : 'monthly';
        $price = $cycle === 'annual' ? floatval($plan['annual_price']) : floatval($plan['monthly_price']);
        $label = $plan['name'] . ' plan - ' . ($cycle === 'annual' ? '12 months' : '1 month');
        $lines = [['description' => $label, 'line_type' => 'plan', 'module_key' => null, 'quantity' => 1, 'unit_price' => $price, 'amount' => $price]];
        // Fixed plan price only: modules/features are selected per plan, never priced individually.
        $sub = array_sum(array_column($lines, 'amount'));
        return ['plan' => $plan, 'cycle' => $cycle, 'lines' => $lines, 'subtotal' => $sub];
    }

    // ------------------------------------------------------------------ invoices
    private static function nextInvoiceNo() {
        $p = Tenancy::platform();
        $prefix = 'INV-' . date('Ym') . '-';
        $n = intval($p->query("SELECT COUNT(*) c FROM invoices WHERE invoice_no LIKE '$prefix%'")->fetch_assoc()['c']) + 1;
        do { $no = $prefix . str_pad($n++, 5, '0', STR_PAD_LEFT); } while ($p->query("SELECT 1 FROM invoices WHERE invoice_no = '$no'")->num_rows);
        return $no;
    }

    public static function createInvoice($estateId, $subId, $kind, array $lines, $periodStart = null, $periodEnd = null, $dueDate = null, array $meta = []) {
        $p = Tenancy::platform();
        $subtotal = array_sum(array_column($lines, 'amount'));
        // apply available credit
        $credit = 0.0; $used = [];
        $cr = $p->query("SELECT id, remaining FROM credits WHERE estate_id = " . intval($estateId) . " AND remaining > 0 ORDER BY id");
        while ($cr && $c = $cr->fetch_assoc()) {
            if ($credit >= $subtotal) break;
            $take = min(floatval($c['remaining']), $subtotal - $credit);
            $credit += $take; $used[] = [$c['id'], $take];
        }
        $total = max(0, $subtotal - $credit);
        $no = self::nextInvoiceNo();
        $ref = 'SUB-' . intval($estateId) . '-' . strtoupper(bin2hex(random_bytes(5)));
        $due = $dueDate ?: date('Y-m-d H:i:s', time() + 86400 * max(1, PlatformSettings::int('invoice_due_days', 7)));
        $currency = PlatformSettings::get('currency', 'NGN');
        $metaJson = json_encode($meta);
        $status = $total <= 0 ? 'paid' : 'pending';
        $st = $p->prepare("INSERT INTO invoices (invoice_no, estate_id, subscription_id, kind, status, currency, subtotal, credit_applied, total, period_start, period_end, due_date, payment_reference, meta_json, paid_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, IF(?='paid', NOW(), NULL))");
        $st->bind_param('siisssdddsssss' . 's', $no, $estateId, $subId, $kind, $status, $currency, $subtotal, $credit, $total, $periodStart, $periodEnd, $due, $ref, $metaJson, $status);
        $st->execute();
        $invId = $p->insert_id;
        $ls = $p->prepare("INSERT INTO invoice_lines (invoice_id, description, line_type, module_key, quantity, unit_price, amount) VALUES (?,?,?,?,?,?,?)");
        foreach ($lines as $l) { $ls->bind_param('isssidd', $invId, $l['description'], $l['line_type'], $l['module_key'], $l['quantity'], $l['unit_price'], $l['amount']); $ls->execute(); }
        foreach ($used as [$cid, $take]) $p->query("UPDATE credits SET remaining = remaining - " . floatval($take) . " WHERE id = " . intval($cid));
        self::event($estateId, $subId, 'invoice', "Invoice $no issued (" . self::money($total) . ")", $total);
        Audit::log('invoice.created', ['estate_id' => $estateId, 'module' => 'billing', 'entity_type' => 'invoice', 'entity_id' => $invId, 'details' => "$no total " . self::money($total) . " ($kind)"]);
        return self::invoice($invId);
    }

    public static function invoice($id) {
        $r = Tenancy::platform()->query("SELECT * FROM invoices WHERE id = " . intval($id));
        $inv = $r ? $r->fetch_assoc() : null;
        if ($inv) { $inv['lines'] = []; $l = Tenancy::platform()->query("SELECT * FROM invoice_lines WHERE invoice_id = " . intval($id)); while ($row = $l->fetch_assoc()) $inv['lines'][] = $row; }
        return $inv;
    }

    public static function event($estateId, $subId, $type, $title, $amount = null, $meta = null) {
        $st = Tenancy::platform()->prepare("INSERT INTO billing_events (estate_id, subscription_id, event_type, title, amount, meta_json) VALUES (?,?,?,?,?,?)");
        $mj = $meta ? json_encode($meta) : null;
        $st->bind_param('iissds', $estateId, $subId, $type, $title, $amount, $mj);
        $st->execute();
    }

    // ------------------------------------------------------------------ plan selection / checkout
    /**
     * Estate picks a plan: creates (or replaces the unpaid) subscription + a pending invoice. Does NOT activate anything.
     */
    public static function selectPlan($estateId, $planId, $cycle, array $addons = []) {
        $estate = Tenancy::find($estateId);
        if (!$estate) throw new RuntimeException('Estate not found');
        $q = self::quote($planId, $cycle, $addons);
        $residents = self::residentCount($estate);
        $limit = intval($q['plan']['resident_limit']);
        if ($limit > 0 && $residents > $limit) throw new InvalidArgumentException("Your estate has $residents active residents; the {$q['plan']['name']} plan supports up to $limit. Please choose a larger plan.");
        $p = Tenancy::platform();
        // cancel any previous unpaid attempt
        $p->query("UPDATE invoices SET status = 'cancelled' WHERE estate_id = " . intval($estateId) . " AND status IN ('pending','failed') AND kind IN ('subscription')");
        $p->query("UPDATE subscriptions SET status = 'cancelled', cancelled_at = NOW() WHERE estate_id = " . intval($estateId) . " AND status = 'pending_payment'");
        $snap = json_encode(Plans::snapshot($planId) + ['addons' => array_values(array_filter(array_column($q['lines'], 'module_key')))]);
        $ver = intval($q['plan']['version']); $cyc = $q['cycle'];
        $st = $p->prepare("INSERT INTO subscriptions (estate_id, plan_id, plan_version, plan_snapshot_json, billing_cycle, status, resident_limit) VALUES (?,?,?,?,?,'pending_payment',?)");
        $st->bind_param('iiissi', $estateId, $planId, $ver, $snap, $cyc, $limit);
        $st->execute();
        $subId = $p->insert_id;
        EstateService::setOnboarding($estateId, 'plan_selected');
        $inv = self::createInvoice($estateId, $subId, 'subscription', $q['lines'], null, null, null, ['addons' => array_column(array_filter($q['lines'], fn($l) => $l['module_key']), 'module_key')]);
        EstateService::setOnboarding($estateId, 'payment_pending');
        self::event($estateId, $subId, 'subscription_change', "Plan selected: {$q['plan']['name']} ({$cyc})");
        Audit::log('subscription.plan_selected', ['estate_id' => $estateId, 'module' => 'billing', 'entity_type' => 'subscription', 'entity_id' => $subId, 'details' => "Selected {$q['plan']['name']} ($cyc), invoice {$inv['invoice_no']}"]);
        if ($inv['status'] === 'paid') self::markPaid($inv['id'], 'CREDIT-' . $inv['id'], 'credit', []);
        return ['subscription_id' => $subId, 'invoice' => self::invoice($inv['id'])];
    }

    // ------------------------------------------------------------------ gateway
    public static function gatewayConfigured() { return env_get('PLATFORM_PAYSTACK_SECRET', '') !== ''; }
    public static function simulationAllowed() { return !self::gatewayConfigured() && env_get('APP_ENV', 'production') === 'local'; }

    /** Returns URL to send the payer to. Creates the pending ledger row. */
    public static function startPayment(array $invoice, $payerEmail) {
        if (!in_array($invoice['status'], ['pending', 'failed'], true)) throw new RuntimeException('This invoice cannot be paid.');
        $p = Tenancy::platform();
        // fresh reference per attempt keeps the ledger clean (old pending attempts expire)
        $ref = 'SUB-' . intval($invoice['estate_id']) . '-' . strtoupper(bin2hex(random_bytes(6)));
        $p->query("UPDATE payments SET status = 'cancelled' WHERE invoice_id = " . intval($invoice['id']) . " AND status = 'pending'");
        $st = $p->prepare("UPDATE invoices SET payment_reference = ?, status = 'pending' WHERE id = ?");
        $st->bind_param('si', $ref, $invoice['id']);
        $st->execute();
        $gw = self::gatewayConfigured() ? 'paystack' : 'simulator';
        $amount = floatval($invoice['total']); $cur = $invoice['currency'];
        $ins = $p->prepare("INSERT INTO payments (estate_id, invoice_id, entry_type, status, gateway, reference, amount, currency, recorded_by) VALUES (?,?, 'payment','pending',?,?,?,?, 'estate')");
        $ins->bind_param('iissds', $invoice['estate_id'], $invoice['id'], $gw, $ref, $amount, $cur);
        $ins->execute();
        self::event($invoice['estate_id'], $invoice['subscription_id'], 'payment', "Payment initiated for {$invoice['invoice_no']} via $gw", $amount);

        if (!self::gatewayConfigured()) {
            if (!self::simulationAllowed()) throw new RuntimeException('Online payment is not configured yet. Please contact support.');
            return Notifier::baseUrl() . '/pay_simulate?ref=' . urlencode($ref);
        }
        $callback = Notifier::baseUrl() . '/platform_payment_return';
        $payload = json_encode(['email' => $payerEmail, 'amount' => intval(round($amount * 100)), 'currency' => $cur, 'reference' => $ref, 'callback_url' => $callback,
            'metadata' => ['estate_id' => $invoice['estate_id'], 'invoice_no' => $invoice['invoice_no']]]);
        $res = self::paystack('POST', '/transaction/initialize', $payload);
        if (empty($res['status']) || empty($res['data']['authorization_url'])) {
            Tenancy::logError('payment', 'Paystack initialize failed: ' . ($res['message'] ?? 'no response'), $invoice['estate_id']);
            throw new RuntimeException('We could not start the payment. Please try again.');
        }
        return $res['data']['authorization_url'];
    }

    public static function paystack($method, $path, $body = null) {
        $ch = curl_init('https://api.paystack.co' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . env_get('PLATFORM_PAYSTACK_SECRET', ''), 'Content-Type: application/json']]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $out = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) { Tenancy::logError('payment', "Paystack request failed: $err"); return null; }
        return json_decode($out, true);
    }

    /** Verify a reference directly with Paystack and apply the result (used by return URL + webhook). */
    public static function verifyAndApply($reference) {
        if (!self::gatewayConfigured()) return false;
        $res = self::paystack('GET', '/transaction/verify/' . rawurlencode($reference));
        if (!$res || empty($res['data'])) return false;
        $d = $res['data'];
        $status = ($d['status'] ?? '') === 'success' ? 'success' : (in_array($d['status'] ?? '', ['abandoned'], true) ? 'cancelled' : 'failed');
        return self::applyPaymentResult($reference, $status, $d['id'] ?? null, isset($d['amount']) ? $d['amount'] / 100 : null, $d, $d['gateway_response'] ?? null);
    }

    /**
     * Idempotently record a payment outcome. Success activates / renews the subscription.
     * Amounts are cross-checked against the invoice; mismatches are flagged instead of activated.
     */
    public static function applyPaymentResult($reference, $status, $gatewayRef, $paidAmount, $raw = [], $failureReason = null) {
        $p = Tenancy::platform();
        $p->begin_transaction();
        try {
            $st = $p->prepare("SELECT * FROM payments WHERE reference = ? AND entry_type = 'payment' ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $st->bind_param('s', $reference);
            $st->execute();
            $pay = $st->get_result()->fetch_assoc();
            if (!$pay) { $p->rollback(); Tenancy::logError('payment', "Payment result for unknown reference $reference", null, 'warning'); return false; }
            if ($pay['status'] !== 'pending') { $p->commit(); return true; } // already processed - idempotent
            $inv = self::invoice($pay['invoice_id']);
            if ($status === 'success' && $paidAmount !== null && abs(floatval($paidAmount) - floatval($pay['amount'])) > 0.009) {
                $status = 'failed'; $failureReason = 'Amount mismatch (paid ' . $paidAmount . ', expected ' . $pay['amount'] . ')';
                Tenancy::logError('payment', "Amount mismatch on $reference: paid $paidAmount expected {$pay['amount']}", $pay['estate_id'], 'critical');
            }
            $rawJson = json_encode($raw);
            $u = $p->prepare("UPDATE payments SET status = ?, gateway_reference = ?, failure_reason = ?, raw_payload = ? WHERE id = ?");
            $gr = $gatewayRef !== null ? (string)$gatewayRef : null;
            $u->bind_param('ssssi', $status, $gr, $failureReason, $rawJson, $pay['id']);
            $u->execute();
            $p->commit();
        } catch (Throwable $e) {
            $p->rollback();
            Tenancy::logError('payment', 'applyPaymentResult failed: ' . $e->getMessage(), null, 'critical');
            return false;
        }

        $estate = Tenancy::find($pay['estate_id']);
        if ($status === 'success') {
            self::markPaid($pay['invoice_id'], $reference, $pay['gateway'], $raw);
        } else {
            $ns = in_array($status, ['expired', 'cancelled'], true) ? $status : 'failed';
            $p->query("UPDATE invoices SET status = '$ns' WHERE id = " . intval($pay['invoice_id']) . " AND status = 'pending'");
            self::event($pay['estate_id'], $inv['subscription_id'] ?? null, 'failed_payment', "Payment $status for {$inv['invoice_no']}" . ($failureReason ? " - $failureReason" : ''), $pay['amount']);
            Notifier::toCentralAdmins('payment_failed', $estate, ['amount' => self::money($pay['amount']), 'invoice_no' => $inv['invoice_no'], 'reason' => $failureReason ? " ($failureReason)" : '', 'link' => Notifier::estateUrl($estate, 'admin/subscription')]);
            Audit::log('payment.' . $status, ['estate_id' => $pay['estate_id'], 'module' => 'billing', 'entity_type' => 'payment', 'entity_id' => $pay['id'], 'details' => "{$inv['invoice_no']} $status", 'actor' => ['system', null, 'payment gateway', null]]);
            Tenancy::logError('payment', "Payment $status for {$inv['invoice_no']} ($reference)" . ($failureReason ? ": $failureReason" : ''), $pay['estate_id'], 'warning');
        }
        return true;
    }

    // ------------------------------------------------------------------ activation
    /** Invoice settled (by gateway or credit): activate / renew subscription, enable modules, restore estate. */
    public static function markPaid($invoiceId, $reference, $gateway, $raw = []) {
        $p = Tenancy::platform();
        $inv = self::invoice($invoiceId);
        if (!$inv || $inv['status'] === 'paid') return;
        $p->query("UPDATE invoices SET status = 'paid', paid_at = NOW() WHERE id = " . intval($invoiceId));
        $estate = Tenancy::find($inv['estate_id']);
        $sub = $inv['subscription_id'] ? $p->query("SELECT * FROM subscriptions WHERE id = " . intval($inv['subscription_id']))->fetch_assoc() : null;
        $meta = json_decode($inv['meta_json'] ?? '{}', true) ?: [];
        $wasStatus = $estate['status'];

        if ($sub) {
            $cycle = $sub['billing_cycle'];
            $base = ($sub['period_end'] && strtotime($sub['period_end']) > time() && in_array($inv['kind'], ['renewal'], true)) ? strtotime($sub['period_end']) : time();
            $interval = $cycle === 'annual' ? '+1 year' : '+1 month';
            $start = date('Y-m-d H:i:s', $base); $end = date('Y-m-d H:i:s', strtotime($interval, $base));
            if ($inv['kind'] === 'plan_change') { // upgrade keeps the existing period
                $start = $sub['period_start']; $end = $sub['period_end'];
                if (!empty($meta['new_plan_id'])) {
                    $snap = json_encode(Plans::snapshot($meta['new_plan_id']) + ['addons' => $meta['addons'] ?? []]);
                    $np = Plans::get($meta['new_plan_id']);
                    $stm = $p->prepare("UPDATE subscriptions SET plan_id = ?, plan_version = ?, plan_snapshot_json = ?, resident_limit = ?, billing_cycle = ? WHERE id = ?");
                    $v = intval($np['version']); $lim = intval($np['resident_limit']); $cy = $meta['new_cycle'] ?? $cycle; $pid = intval($meta['new_plan_id']);
                    $stm->bind_param('iisisi', $pid, $v, $snap, $lim, $cy, $sub['id']);
                    $stm->execute();
                }
            }
            $newStatus = in_array($sub['status'], ['past_due', 'grace_period', 'suspended', 'expired'], true) || $inv['kind'] === 'renewal' ? 'renewed' : 'active';
            if ($sub['status'] === 'pending_payment') $newStatus = 'active';
            $stm = $p->prepare("UPDATE subscriptions SET status = ?, period_start = ?, period_end = ?, next_billing_date = ?, grace_ends_at = NULL WHERE id = ?");
            $stm->bind_param('ssssi', $newStatus, $start, $end, $end, $sub['id']);
            $stm->execute();
            // add-ons purchased on this invoice
            foreach ($meta['addons'] ?? [] as $k) {
                $price = 0; foreach ($inv['lines'] as $l) if ($l['module_key'] === $k) $price = $l['amount'];
                $a = $p->prepare("INSERT INTO estate_addon_modules (estate_id, module_key, price) VALUES (?,?,?) ON DUPLICATE KEY UPDATE price = VALUES(price)");
                $a->bind_param('isd', $inv['estate_id'], $k, $price);
                $a->execute();
            }
            $p->query("UPDATE estates SET current_subscription_id = " . intval($sub['id']) . " WHERE id = " . intval($inv['estate_id']));
        }

        EstateService::setStatus($inv['estate_id'], 'active', ['trial_ends_at' => null], 'Subscription payment confirmed');
        EstateService::setOnboarding($inv['estate_id'], 'payment_confirmed');
        EstateService::setOnboarding($inv['estate_id'], 'active');
        Entitlements::flush($inv['estate_id']);
        Tenancy::mirror($inv['estate_id']);

        self::event($inv['estate_id'], $sub['id'] ?? null, in_array($inv['kind'], ['renewal']) ? 'renewal' : 'payment', "Payment received for {$inv['invoice_no']}", $inv['total']);
        Audit::log('payment.success', ['estate_id' => $inv['estate_id'], 'module' => 'billing', 'entity_type' => 'invoice', 'entity_id' => $invoiceId,
            'details' => "{$inv['invoice_no']} paid (" . self::money($inv['total']) . ") ref $reference", 'actor' => ['system', null, 'payment gateway', null]]);

        $estate = Tenancy::find($inv['estate_id']);
        $subNow = self::currentSubscription($inv['estate_id']);
        Notifier::toCentralAdmins('payment_confirmed', $estate, ['amount' => self::money($inv['total']), 'invoice_no' => $inv['invoice_no'], 'reference' => $reference]);
        if ($wasStatus !== 'active') {
            Notifier::toCentralAdmins($wasStatus === 'suspended' || $wasStatus === 'retention' ? 'account_reactivated' : 'subscription_activated', $estate, [
                'plan' => $subNow['plan_name'] ?? '', 'cycle' => $subNow['billing_cycle'] ?? '', 'next_billing' => $subNow ? date('j M Y', strtotime($subNow['next_billing_date'])) : '',
            ]);
        }
        // clear lifecycle notice markers so a future cycle can warn again
        $p->query("DELETE FROM lifecycle_notices WHERE estate_id = " . intval($inv['estate_id']));
    }

    // ------------------------------------------------------------------ renewals
    /** Create the next renewal invoice for a subscription if none is open. */
    public static function createRenewalInvoice(array $sub) {
        $p = Tenancy::platform();
        $open = $p->query("SELECT id FROM invoices WHERE subscription_id = " . intval($sub['id']) . " AND kind = 'renewal' AND status IN ('pending','failed') LIMIT 1");
        if ($open && $open->num_rows) return self::invoice($open->fetch_assoc()['id']);
        $snap = json_decode($sub['plan_snapshot_json'] ?? '', true);
        $planId = $sub['plan_id'];
        $addons = $snap['addons'] ?? [];
        $q = self::quote($planId, $sub['billing_cycle'], $addons);
        // renewal honours the grandfathered price if the plan snapshot is kept
        if (!empty($snap['plan'])) {
            $price = $sub['billing_cycle'] === 'annual' ? floatval($snap['plan']['annual_price']) : floatval($snap['plan']['monthly_price']);
            $q['lines'][0]['unit_price'] = $price; $q['lines'][0]['amount'] = $price;
        }
        $interval = $sub['billing_cycle'] === 'annual' ? '+1 year' : '+1 month';
        $ps = $sub['period_end']; $pe = date('Y-m-d H:i:s', strtotime($interval, strtotime($ps)));
        return self::createInvoice($sub['estate_id'], $sub['id'], 'renewal', $q['lines'], $ps, $pe, $ps, ['addons' => $addons]);
    }

    // ------------------------------------------------------------------ plan change
    /** Preview: effective date, payment difference, limit & module consequences. */
    public static function planChangePreview($estateId, $newPlanId, $cycle, array $addons = []) {
        $sub = self::currentSubscription($estateId);
        if (!$sub || !in_array($sub['status'], ['active', 'renewed', 'grace_period', 'past_due'], true)) throw new RuntimeException('Only active subscriptions can change plan.');
        $new = self::quote($newPlanId, $cycle, $addons);
        $old = json_decode($sub['plan_snapshot_json'] ?? '', true);
        $oldPlan = $old['plan'] ?? Plans::get($sub['plan_id']);
        $oldPrice = $sub['billing_cycle'] === 'annual' ? floatval($oldPlan['annual_price']) : floatval($oldPlan['monthly_price']);
        $newPrice = $new['lines'][0]['amount'];
        $residents = self::residentCount($estateId);
        $newLimit = intval($new['plan']['resident_limit']);
        $problems = [];
        if ($newLimit > 0 && $residents > $newLimit) $problems[] = "Your estate has $residents active residents; the {$new['plan']['name']} plan allows $newLimit.";
        $oldMods = array_column(array_filter($old['modules'] ?? Plans::modules($sub['plan_id']), fn($m) => $m['inclusion'] === 'included'), 'module_key');
        $newMods = array_column(array_filter(Plans::modules($newPlanId), fn($m) => $m['inclusion'] === 'included'), 'module_key');
        $lost = array_values(array_diff($oldMods, $newMods)); $gained = array_values(array_diff($newMods, $oldMods));
        $isUpgrade = ($newPrice > $oldPrice) || ($new['cycle'] === 'annual' && $sub['billing_cycle'] === 'monthly');
        $sameCycle = $new['cycle'] === $sub['billing_cycle'];
        $remainFrac = 1.0;
        if ($sub['period_end'] && $sub['period_start']) {
            $total = max(1, strtotime($sub['period_end']) - strtotime($sub['period_start']));
            $remainFrac = max(0, min(1, (strtotime($sub['period_end']) - time()) / $total));
        }
        $charge = 0.0; $effective = $sub['period_end']; $mode = 'scheduled';
        if ($isUpgrade && $sameCycle) {
            $mode = 'immediate'; $effective = date('Y-m-d H:i:s');
            $charge = PlatformSettings::bool('proration_enabled', true) ? max(0, ($newPrice - $oldPrice) * $remainFrac) : max(0, $newPrice - $oldPrice);
        } elseif ($isUpgrade) { // cycle change upgrade: new period starts now at full price
            $mode = 'immediate'; $effective = date('Y-m-d H:i:s'); $charge = $new['subtotal'];
        }
        return ['mode' => $mode, 'effective' => $effective, 'charge' => round($charge, 2), 'problems' => $problems, 'lost_modules' => $lost, 'gained_modules' => $gained,
            'new' => $new, 'old_price' => $oldPrice, 'new_price' => $newPrice, 'residents' => $residents, 'subscription' => $sub];
    }

    public static function requestPlanChange($estateId, $newPlanId, $cycle, array $addons = []) {
        $pv = self::planChangePreview($estateId, $newPlanId, $cycle, $addons);
        if ($pv['problems']) throw new InvalidArgumentException(implode(' ', $pv['problems']));
        $p = Tenancy::platform(); $sub = $pv['subscription'];
        if ($pv['mode'] === 'scheduled') {
            $st = $p->prepare("UPDATE subscriptions SET pending_plan_id = ?, pending_cycle = ?, pending_effective_at = ? WHERE id = ?");
            $cy = $pv['new']['cycle'];
            $st->bind_param('issi', $newPlanId, $cy, $pv['effective'], $sub['id']);
            $st->execute();
            self::event($estateId, $sub['id'], 'plan_change', "Downgrade to {$pv['new']['plan']['name']} scheduled for " . date('j M Y', strtotime($pv['effective'])));
            Audit::log('subscription.plan_change_scheduled', ['estate_id' => $estateId, 'module' => 'billing', 'entity_type' => 'subscription', 'entity_id' => $sub['id'], 'details' => "To plan #$newPlanId effective {$pv['effective']}"]);
            $e = Tenancy::find($estateId);
            Notifier::toCentralAdmins('plan_changed', $e, ['plan' => $pv['new']['plan']['name'], 'effective' => date('j M Y', strtotime($pv['effective']))]);
            return ['scheduled' => true, 'invoice' => null];
        }
        $lines = [['description' => 'Upgrade to ' . $pv['new']['plan']['name'] . ' (' . $pv['new']['cycle'] . ')', 'line_type' => 'proration', 'module_key' => null, 'quantity' => 1, 'unit_price' => $pv['charge'], 'amount' => $pv['charge']]];
        $inv = self::createInvoice($estateId, $sub['id'], 'plan_change', $lines, null, null, null, ['new_plan_id' => $newPlanId, 'new_cycle' => $pv['new']['cycle'], 'addons' => array_column(array_filter($pv['new']['lines'], fn($l) => $l['module_key']), 'module_key')]);
        self::event($estateId, $sub['id'], 'plan_change', "Upgrade to {$pv['new']['plan']['name']} - invoice {$inv['invoice_no']}", $inv['total']);
        Audit::log('subscription.plan_change_requested', ['estate_id' => $estateId, 'module' => 'billing', 'entity_type' => 'subscription', 'entity_id' => $sub['id'], 'details' => "Upgrade to plan #$newPlanId, invoice {$inv['invoice_no']}"]);
        if ($inv['status'] === 'paid') self::markPaid($inv['id'], 'CREDIT-' . $inv['id'], 'credit', []);
        return ['scheduled' => false, 'invoice' => self::invoice($inv['id'])];
    }

    public static function history($estateId) {
        $out = ['events' => [], 'invoices' => [], 'payments' => []];
        $p = Tenancy::platform(); $id = intval($estateId);
        $r = $p->query("SELECT * FROM billing_events WHERE estate_id = $id ORDER BY id DESC LIMIT 200"); while ($row = $r->fetch_assoc()) $out['events'][] = $row;
        $r = $p->query("SELECT * FROM invoices WHERE estate_id = $id ORDER BY id DESC"); while ($row = $r->fetch_assoc()) $out['invoices'][] = $row;
        $r = $p->query("SELECT pm.*, i.invoice_no FROM payments pm LEFT JOIN invoices i ON i.id = pm.invoice_id WHERE pm.estate_id = $id ORDER BY pm.id DESC"); while ($row = $r->fetch_assoc()) $out['payments'][] = $row;
        return $out;
    }

    /** Admin-issued refund / adjustment: appended to the ledger, never edits history. */
    public static function recordRefund($estateId, $invoiceId, $amount, $reason, $actor = 'superadmin') {
        $p = Tenancy::platform();
        $ref = 'RF-' . intval($estateId) . '-' . strtoupper(bin2hex(random_bytes(5)));
        $neg = -abs(floatval($amount)); $cur = PlatformSettings::get('currency', 'NGN');
        $st = $p->prepare("INSERT INTO payments (estate_id, invoice_id, entry_type, status, gateway, reference, amount, currency, failure_reason, recorded_by) VALUES (?,?, 'refund','refunded','manual',?,?,?,?,?)");
        $st->bind_param('iisdsss', $estateId, $invoiceId, $ref, $neg, $cur, $reason, $actor);
        $st->execute();
        if ($invoiceId) $p->query("UPDATE invoices SET status = 'refunded' WHERE id = " . intval($invoiceId));
        self::event($estateId, null, 'refund', "Refund recorded: $reason", $neg);
        Audit::log('payment.refund_recorded', ['estate_id' => $estateId, 'module' => 'billing', 'entity_type' => 'invoice', 'entity_id' => $invoiceId, 'details' => self::money(abs($amount)) . " - $reason"]);
    }

    public static function addCredit($estateId, $amount, $reason, $by = null) {
        $st = Tenancy::platform()->prepare("INSERT INTO credits (estate_id, amount, remaining, reason, created_by) VALUES (?,?,?,?,?)");
        $st->bind_param('iddsi', $estateId, $amount, $amount, $reason, $by);
        $st->execute();
        self::event($estateId, null, 'credit', "Credit added: $reason", $amount);
        Audit::log('billing.credit_added', ['estate_id' => $estateId, 'module' => 'billing', 'details' => self::money($amount) . " - $reason"]);
    }
}
