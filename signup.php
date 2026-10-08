<?php
// signup.php - public estate registration (step 1 of the lifecycle). Creates the estate + an EMPTY isolated
// environment + the first (unverified) Central Admin, then sends the verification email/OTP.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_layout.php';

if (Tenancy::hasTenant() && !empty($_SESSION['user_id'])) { header('Location: login'); exit; }
$open = PlatformSettings::bool('allow_public_signup', true);
$trial = PlatformSettings::bool('trial_enabled', true);
$trialDays = PlatformSettings::int('trial_days', 14);
$errors = [];
$in = ['estate_name' => '', 'organisation_type' => '', 'address' => '', 'city' => '', 'state' => '', 'contact_phone' => '', 'estate_code' => '', 'admin_name' => '', 'admin_email' => '', 'admin_phone' => ''];
if (!empty($_GET['plan']) && preg_match('/^[a-z0-9-]+$/', $_GET['plan'])) $_SESSION['signup_plan'] = $_GET['plan'];

if ($open && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach ($in as $k => $_) $in[$k] = trim((string)($_POST[$k] ?? ''));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0';
    $pw = (string)($_POST['password'] ?? ''); $pw2 = (string)($_POST['password2'] ?? '');
    if (!empty($_POST['website'])) { // honeypot
        $errors[] = 'Sorry, we could not process this registration.';
    } elseif (RateLimit::lockedFor('signup', $ip) > 0) {
        $errors[] = 'Too many registrations from your network. Please try again later.';
    } elseif ($pw !== $pw2) {
        $errors[] = 'The two passwords do not match.';
    } elseif (empty($_POST['terms'])) {
        $errors[] = 'Please accept the terms to continue.';
    } else {
        RateLimit::hit('signup', $ip, max(1, PlatformSettings::int('signup_rate_limit_per_hour', 5)), 60, 60);
        try {
            $res = EstateService::register($in + ['password' => $pw, 'want_trial' => $trial && !empty($_POST['want_trial'])], 'public');
            $_SESSION['verify_email'] = strtolower($in['admin_email']);
            header('Location: verify_email');
            exit;
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('signup failed: ' . $e->getMessage());
            $errors[] = 'We could not create your estate right now. Please try again, or contact support.';
        }
    }
}

public_head('Register your estate - ' . pl_company());
public_nav('simple');
?>
<div class="auth-shell"><div></div>
<main class="auth-main">
<div class="form-card">
    <span class="eyebrow">Step 1 of 3</span>
    <h1>Register your estate</h1>
    <p class="muted">We will create a private, empty workspace for your estate, then ask you to verify your email.</p>
    <?php if (!$open): ?>
        <div class="alert info">New registrations are currently closed. Please <a href="index#support">contact us</a> to onboard your estate.</div>
    <?php else: ?>
    <?php foreach ($errors as $er): ?><div class="alert err"><?php echo pl_e($er); ?></div><?php endforeach; ?>
    <form method="post" autocomplete="off" novalidate>
        <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off">
        <h3 style="margin-top:6px">Estate details</h3>
        <div class="field"><label for="estate_name">Estate / organisation name</label><input id="estate_name" name="estate_name" required maxlength="150" value="<?php echo pl_e($in['estate_name']); ?>"></div>
        <div class="row2">
            <div class="field"><label for="organisation_type">Type</label>
                <select id="organisation_type" name="organisation_type">
                    <?php foreach (['Gated estate', 'Residential association', 'Apartment complex', 'Housing cooperative', 'Property management company', 'Other'] as $t): ?>
                        <option <?php echo $in['organisation_type'] === $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="field"><label for="contact_phone">Estate phone</label><input id="contact_phone" name="contact_phone" value="<?php echo pl_e($in['contact_phone']); ?>"></div>
        </div>
        <div class="field"><label for="address">Address</label><input id="address" name="address" value="<?php echo pl_e($in['address']); ?>"></div>
        <div class="row2">
            <div class="field"><label for="city">City</label><input id="city" name="city" value="<?php echo pl_e($in['city']); ?>"></div>
            <div class="field"><label for="state">State</label><input id="state" name="state" value="<?php echo pl_e($in['state']); ?>"></div>
        </div>
        <div class="field"><label for="estate_code">Estate code <span class="muted">(optional)</span></label><input id="estate_code" name="estate_code" maxlength="40" pattern="[a-zA-Z0-9-]*" value="<?php echo pl_e($in['estate_code']); ?>" placeholder="sunrise-gardens"><div class="hint">Used in your estate's web address and when signing in. Leave blank and we will suggest one.</div></div>

        <h3 style="margin-top:22px">Central Admin account</h3>
        <div class="field"><label for="admin_name">Full name</label><input id="admin_name" name="admin_name" required value="<?php echo pl_e($in['admin_name']); ?>"></div>
        <div class="row2">
            <div class="field"><label for="admin_email">Email</label><input id="admin_email" type="email" name="admin_email" required value="<?php echo pl_e($in['admin_email']); ?>"></div>
            <div class="field"><label for="admin_phone">Mobile</label><input id="admin_phone" name="admin_phone" value="<?php echo pl_e($in['admin_phone']); ?>"></div>
        </div>
        <div class="row2">
            <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="new-password"><div class="hint">At least <?php echo max(8, PlatformSettings::int('min_password_length', 10)); ?> characters with letters and numbers.</div></div>
            <div class="field"><label for="password2">Confirm password</label><input id="password2" type="password" name="password2" required autocomplete="new-password"></div>
        </div>
        <?php if ($trial): ?><label class="check"><input type="checkbox" name="want_trial" value="1" checked> <span>Start with a <?php echo $trialDays; ?>-day free trial. You can pick a plan any time.</span></label><?php endif; ?>
        <label class="check"><input type="checkbox" name="terms" value="1"> <span>I agree to the terms of service and privacy policy, and confirm I am authorised to register this estate.</span></label>
        <button class="btn btn-primary btn-block" type="submit">Create my estate</button>
        <p class="muted" style="text-align:center;margin:16px 0 0;font-size:.9rem">Already registered? <a href="login">Log in</a></p>
    </form>
    <?php endif; ?>
</div>
</main></div>
</body></html>
