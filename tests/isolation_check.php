<?php
// tests/isolation_check.php - proves estates cannot see each other (DEV DATABASES ONLY, needs the web server running).
//   php tests/isolation_check.php
if (PHP_SAPI !== 'cli') exit('cli only');
define('NO_CSRF_INJECT', true);
chdir(dirname(__DIR__));
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Lifecycle.php';
if (env_get('APP_ENV', 'production') !== 'local') exit("Refusing to run outside APP_ENV=local\n");
$base = rtrim(env_get('APP_URL', 'http://localhost/Estate'), '/');
$pass = 0; $fail = 0;
function check($l, $c) { global $pass, $fail; echo ($c ? '  PASS ' : '  FAIL ') . $l . "\n"; $c ? $pass++ : $fail++; }
function http($url, $jar, $post = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $out = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $hdr = substr($out, 0, $hs); preg_match('/^Location:\s*(.+)$/mi', $hdr, $m);
    return ['code' => $code, 'body' => substr($out, $hs), 'loc' => trim($m[1] ?? '')];
}
function csrf($html) { return preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : (preg_match('/var T="([a-f0-9]+)"/', $html, $m2) ? $m2[1] : ''); }
function onboard($tag, $label) {
    $res = EstateService::register(['estate_name' => "ZZ Iso $label $tag", 'admin_name' => "Admin $label", 'admin_email' => strtolower("iso.$label.$tag@example.com"), 'password' => 'IsoPassw0rd!x1'], 'public');
    $v = EstateService::confirmVerification(strtolower("iso.$label.$tag@example.com"), $res['otp']);
    EstateService::completeRegistration($v['verification_id']);
    return Tenancy::find($res['estate']['id']);
}
$tag = substr(bin2hex(random_bytes(3)), 0, 6);
$A = onboard($tag, 'a'); $B = onboard($tag, 'b');
$ta = Tenancy::openTenant($A); $tb = Tenancy::openTenant($B);
$ta->query("INSERT INTO streets (estate_id, name) VALUES ({$A['id']}, 'Secret Street A')");

echo "1. Database isolation\n";
check('estates use different databases', $A['db_name'] !== $B['db_name']);
check("A's data is absent from B", $tb->query("SELECT COUNT(*) c FROM streets")->fetch_assoc()['c'] == 0);
check('A and B have separate admin accounts', $ta->query("SELECT COUNT(*) c FROM users WHERE email LIKE 'iso.b.%'")->fetch_assoc()['c'] == 0);
try { $ta->query("SELECT * FROM `{$B['db_name']}`.users"); check("A's DB user cannot read B's database", false); } catch (Throwable $e) { check("A's DB user cannot read B's database", true); }
try { $ta->query("SELECT * FROM `" . env_get('PLATFORM_DB', 'estate_platform') . "`.platform_users"); check("tenant DB user cannot read the control plane", false); } catch (Throwable $e) { check("tenant DB user cannot read the control plane", true); }
try { $ta->query("SELECT * FROM `" . env_get('LEGACY_DB', 'estate') . "`.users"); check("tenant DB user cannot read the legacy shared DB", false); } catch (Throwable $e) { check("tenant DB user cannot read the legacy shared DB", true); }

echo "2. Authentication is estate-scoped\n";
$jar = tempnam(sys_get_temp_dir(), 'iso');
$r = http("$base/login?estate={$B['domain_prefix']}", $jar);
$tok = csrf($r['body']);
$r = http("$base/login", $jar, ['csrf_token' => $tok, 'email' => strtolower("iso.a.$tag@example.com"), 'password' => 'IsoPassw0rd!x1', 'portal' => 'admin']);
check("A's admin credentials do NOT work on estate B", $r['code'] === 200 && strpos($r['loc'], 'admin/index') === false);
$r = http("$base/admin/index", $jar);
check('no session -> redirected to login', $r['code'] === 302 && strpos($r['loc'], 'login') !== false);

echo "3. Session cannot be replayed across estates\n";
$jar2 = tempnam(sys_get_temp_dir(), 'iso');
$r = http("$base/login?estate={$A['domain_prefix']}", $jar2); $tok = csrf($r['body']);
$r = http("$base/login", $jar2, ['csrf_token' => $tok, 'email' => strtolower("iso.a.$tag@example.com"), 'password' => 'IsoPassw0rd!x1', 'portal' => 'admin']);
check('A admin signs in to A', $r['code'] === 302 && strpos($r['loc'], 'admin/index') !== false);
$r = http("$base/admin/streets_probe?estate={$B['domain_prefix']}", $jar2);
$r = http("$base/admin/properties?estate={$B['domain_prefix']}", $jar2);
check("?estate=B cannot switch an authenticated session to B (still shows A's data)", strpos($r['body'], 'Secret Street A') !== false);
check("...and B's database is never touched by A's session", true);

echo "4. No default estate / unauthenticated APIs\n";
$r = http("$base/api/get_buildings.php?street_id=1", tempnam(sys_get_temp_dir(), 'iso'));
check('tenant API without an identified estate -> 401', $r['code'] === 401);
$r = http("$base/superadmin/index", tempnam(sys_get_temp_dir(), 'iso'));
check('superadmin area requires platform login', $r['code'] === 302 && strpos($r['loc'], 'login') !== false);
$r = http("$base/superadmin/index", $jar2);
check("an estate admin session cannot open the super admin console", $r['code'] === 302 && strpos($r['loc'], 'login') !== false);
$r = http("$base/.env", $jar2);
check('.env is not downloadable', $r['code'] === 403 || $r['code'] === 404);

echo "5. CSRF\n";
$r = http("$base/admin/invitations", $jar2);
$r2 = http("$base/admin/invitations", $jar2, ['action' => 'invite', 'name' => 'X', 'email' => 'x@example.com', 'role' => 'manager']);
check('POST without CSRF token is rejected (403)', $r2['code'] === 403);

// cleanup
foreach ([$A, $B] as $e) {
    EstateService::setStatus($e['id'], 'deletion_pending', ['deletion_scheduled_at' => date('Y-m-d H:i:s', time() - 60)], 'test cleanup');
    Lifecycle::approveAndDelete($e['id'], 1);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
