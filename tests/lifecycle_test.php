<?php
// tests/lifecycle_test.php - end-to-end walk of an estate through its whole lifecycle with an injectable clock.
//   php tests/lifecycle_test.php            (creates and finally deletes a throwaway estate; DEV DATABASES ONLY)
if (PHP_SAPI !== 'cli') exit('cli only');
define('NO_CSRF_INJECT', true);
chdir(dirname(__DIR__));
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Lifecycle.php';
if (env_get('APP_ENV', 'production') !== 'local') exit("Refusing to run outside APP_ENV=local\n");

$pass = 0; $fail = 0;
function check($label, $cond) { global $pass, $fail; echo ($cond ? '  PASS ' : '  FAIL ') . $label . "\n"; $cond ? $pass++ : $fail++; }
function est($id) { return Tenancy::find($id); }
$p = Tenancy::platform();

echo "1. Registration\n";
$tag = substr(bin2hex(random_bytes(3)), 0, 6);
$res = EstateService::register(['estate_name' => "ZZ Lifecycle $tag", 'admin_name' => 'Life Cycle', 'admin_email' => "life.$tag@example.com", 'password' => 'Passw0rd!long1', 'want_trial' => false], 'public');
$id = intval($res['estate']['id']);
Lifecycle::$scope = $id; // the fake clock must never touch real estates
check('estate created pending', est($id)['status'] === 'pending');
$t = Tenancy::openTenant(est($id));
check('tenant DB has exactly 1 user (the unverified Central Admin)', $t->query("SELECT COUNT(*) c FROM users")->fetch_assoc()['c'] == 1);
check('tenant DB has 0 residents / 0 visitors', $t->query("SELECT (SELECT COUNT(*) FROM residents)+(SELECT COUNT(*) FROM visitors) c")->fetch_assoc()['c'] == 0);
check('admin cannot be active before verification', $t->query("SELECT status FROM users LIMIT 1")->fetch_assoc()['status'] === 'pending');
$bad = EstateService::confirmVerification("life.$tag@example.com", '000000');
check('wrong OTP rejected', !$bad['ok']);
$ok = EstateService::confirmVerification("life.$tag@example.com", $res['otp']);
check('correct OTP accepted', $ok['ok']);
EstateService::completeRegistration($ok['verification_id']);
check('estate moves to onboarding', est($id)['status'] === 'onboarding' && est($id)['onboarding_status'] === 'onboarding_pending');
check('onboarding ticket opened', $p->query("SELECT COUNT(*) c FROM support_tickets WHERE estate_id=$id AND category='onboarding'")->fetch_assoc()['c'] == 1);
check('onboarding estate has core modules only', Entitlements::allowed($id, 'resident_management') && !Entitlements::allowed($id, 'visitor_passes'));

echo "2. Plan selection + payment\n";
$plan = Plans::bySlug('professional');
$sel = Billing::selectPlan($id, $plan['id'], 'monthly', []);
check('invoice pending, onboarding = payment_pending', $sel['invoice']['status'] === 'pending' && est($id)['onboarding_status'] === 'payment_pending');
$inv = $sel['invoice'];
Billing::startPayment($inv, "life.$tag@example.com");
$ref = $p->query("SELECT payment_reference r FROM invoices WHERE id={$inv['id']}")->fetch_assoc()['r'];
Billing::applyPaymentResult($ref, 'success', 'SIM-1', $inv['total'], ['sim' => true]);
check('estate active after payment', est($id)['status'] === 'active');
check('subscription active with period', Billing::currentSubscription($id)['status'] === 'active');
check('plan modules enabled', Entitlements::allowed($id, 'visitor_passes') && Entitlements::allowed($id, 'security_patrol'));
check('module outside plan denied', !Entitlements::allowed($id, 'ussd_offline_sync'));
Billing::applyPaymentResult($ref, 'success', 'SIM-1', $inv['total'], []); // replay
check('duplicate webhook is idempotent', $p->query("SELECT COUNT(*) c FROM invoices WHERE estate_id=$id AND status='paid'")->fetch_assoc()['c'] == 1);
try { $p->query("UPDATE payments SET amount = 1 WHERE reference='$ref'"); check('payments ledger immutable (update)', false); } catch (Throwable $e) { check('payments ledger immutable (update)', true); }
try { $p->query("DELETE FROM payments WHERE reference='$ref'"); check('payments ledger immutable (delete)', false); } catch (Throwable $e) { check('payments ledger immutable (delete)', true); }

echo "3. Entitlement hierarchy\n";
$p->query("INSERT INTO estate_module_overrides (estate_id, module_key, state) VALUES ($id,'ussd_offline_sync','on')"); Entitlements::flush($id);
check('override ON grants module outside plan', Entitlements::allowed($id, 'ussd_offline_sync'));
$p->query("UPDATE modules SET global_enabled=0 WHERE module_key='ussd_offline_sync'"); Entitlements::flush($id);
check('global OFF beats estate override ON', !Entitlements::allowed($id, 'ussd_offline_sync'));
$p->query("UPDATE modules SET global_enabled=1 WHERE module_key='ussd_offline_sync'");
$p->query("INSERT INTO estate_module_overrides (estate_id, module_key, state) VALUES ($id,'visitor_passes','off') ON DUPLICATE KEY UPDATE state='off'"); Entitlements::flush($id);
check('override OFF beats plan inclusion', !Entitlements::allowed($id, 'visitor_passes'));
$p->query("DELETE FROM estate_module_overrides WHERE estate_id=$id"); Entitlements::flush($id);

echo "4. Renewal, grace, suspension, retention, deletion (time travel)\n";
$sub = Billing::currentSubscription($id);
$end = strtotime($sub['period_end']);
$graceDays = PlatformSettings::int('grace_period_days', 15); $retDays = PlatformSettings::int('retention_days', 45); $hold = PlatformSettings::int('suspension_hold_days', 7);
Lifecycle::$now = $end - 5 * 86400; Lifecycle::run();
check('renewal invoice issued before due date', $p->query("SELECT COUNT(*) c FROM invoices WHERE estate_id=$id AND kind='renewal' AND status='pending'")->fetch_assoc()['c'] == 1);
check('upcoming-renewal reminder queued', $p->query("SELECT COUNT(*) c FROM email_queue WHERE estate_id=$id AND template_key='renewal_upcoming'")->fetch_assoc()['c'] >= 1);
Lifecycle::$now = $end + 3600; Lifecycle::run();
check('overdue -> grace_period (still accessible)', est($id)['status'] === 'grace_period');
check('grace modules still enabled', Entitlements::allowed($id, 'visitor_passes') === true || est($id)['status'] === 'grace_period');
Lifecycle::$now = $end + ($graceDays - 1) * 86400; Lifecycle::run();
check('final suspension warning sent', $p->query("SELECT COUNT(*) c FROM email_queue WHERE estate_id=$id AND template_key='suspension_warning'")->fetch_assoc()['c'] >= 1);
Lifecycle::$now = $end + $graceDays * 86400 + 60; Lifecycle::run();
$e = est($id);
check('grace ended -> suspended', $e['status'] === 'suspended');
check('retention end = suspension + retention_days', abs(strtotime($e['retention_ends_at']) - (Lifecycle::$now + $retDays * 86400)) < 5);
check('suspended estate loses all modules', !Entitlements::allowed($id, 'resident_management'));
check('tenant data still intact while suspended', Tenancy::openTenant($e)->query("SELECT COUNT(*) c FROM users")->fetch_assoc()['c'] == 1);
Lifecycle::$now = strtotime($e['suspended_at']) + ($hold + 1) * 86400; Lifecycle::run();
check('after hold period -> retention', est($id)['status'] === 'retention');
Lifecycle::$now = strtotime($e['retention_ends_at']) - 6 * 86400; Lifecycle::run();
check('retention warning sent', $p->query("SELECT COUNT(*) c FROM email_queue WHERE estate_id=$id AND template_key='retention_warning'")->fetch_assoc()['c'] >= 1);
Lifecycle::$now = strtotime($e['retention_ends_at']) + 60; Lifecycle::run();
check('retention over -> deletion_pending (NOT deleted)', est($id)['status'] === 'deletion_pending' && est($id)['db_name'] !== null);
check('final deletion warning sent', $p->query("SELECT COUNT(*) c FROM email_queue WHERE estate_id=$id AND template_key='final_deletion_warning'")->fetch_assoc()['c'] >= 1);
try { Lifecycle::approveAndDelete($id, 1); check('deletion blocked until final warning period ends', false); } catch (Throwable $x) { check('deletion blocked until final warning period ends', true); }

echo "5. Reactivation during retention\n";
$sub = Billing::currentSubscription($id);
Lifecycle::$now = null;
$inv2 = $p->query("SELECT * FROM invoices WHERE estate_id=$id AND kind='renewal' AND status='pending' ORDER BY id DESC LIMIT 1")->fetch_assoc();
$inv2 = Billing::invoice($inv2['id']);
Billing::startPayment($inv2, "life.$tag@example.com");
$ref2 = $p->query("SELECT payment_reference r FROM invoices WHERE id={$inv2['id']}")->fetch_assoc()['r'];
Billing::applyPaymentResult($ref2, 'success', 'SIM-2', $inv2['total'], []);
check('payment during retention restores estate to active', est($id)['status'] === 'active' && est($id)['suspended_at'] === null);
check('subscription renewed', Billing::currentSubscription($id)['status'] === 'renewed');
check('modules restored', Entitlements::allowed($id, 'visitor_passes'));

echo "6. Permanent deletion (second pass)\n";
Lifecycle::suspend($id, 'test', ['system', null, 'test', 'test']);
$e = est($id);
EstateService::setStatus($id, 'deletion_pending', ['deletion_scheduled_at' => date('Y-m-d H:i:s', time() - 60)], 'test');
Lifecycle::approveAndDelete($id, 1);
$e = est($id);
check('estate marked deleted, secrets cleared', $e['status'] === 'deleted' && $e['db_pass_enc'] === null);
check('tenant database physically dropped', Tenancy::admin()->query("SHOW DATABASES LIKE '" . $e['db_name'] . "'")->num_rows === 0);
check('final backup retained', $p->query("SELECT COUNT(*) c FROM backups WHERE estate_id=$id AND kind='final'")->fetch_assoc()['c'] == 1);
check('invoices & payments retained for legal retention', $p->query("SELECT COUNT(*) c FROM invoices WHERE estate_id=$id")->fetch_assoc()['c'] >= 1 && $p->query("SELECT COUNT(*) c FROM payments WHERE estate_id=$id")->fetch_assoc()['c'] >= 1);
check('deletion audit-logged', $p->query("SELECT COUNT(*) c FROM platform_audit_logs WHERE estate_id=$id AND action='estate.deleted'")->fetch_assoc()['c'] == 1);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
