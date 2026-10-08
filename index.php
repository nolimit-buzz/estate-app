<?php
// index.php - Public SaaS marketing site. (A resolved estate host / signed-in estate user sees that estate's own home page.)
require_once __DIR__ . '/config.php';
if (Tenancy::hasTenant()) { require __DIR__ . '/estate_home.php'; exit; }
require_once __DIR__ . '/includes/Billing.php';
require_once __DIR__ . '/includes/public_layout.php';

$company = pl_company();
$sym = PlatformSettings::get('currency_symbol', '₦');
$showPrices = PlatformSettings::bool('module_pricing_public', true);
$trial = PlatformSettings::bool('trial_enabled', true);
$trialDays = PlatformSettings::int('trial_days', 14);
$signupOpen = PlatformSettings::bool('allow_public_signup', true);

// ---- dynamic catalogue
$plans = Billing::publicPlans();
$planNames = [];
foreach ($plans as $p) $planNames[$p['id']] = $p['name'];
$modules = [];
$r = Tenancy::platform()->query("SELECT * FROM modules WHERE show_public = 1 AND global_enabled = 1 ORDER BY sort_order");
while ($m = $r->fetch_assoc()) { $m['in_plans'] = []; $modules[$m['module_key']] = $m; }
foreach ($plans as $p) foreach ($p['modules'] as $pm) {
    if (isset($modules[$pm['module_key']])) $modules[$pm['module_key']]['in_plans'][] = ($pm['inclusion'] === 'optional' ? $p['name'] . ' (add-on)' : $p['name']);
}
$categories = array_values(array_unique(array_column($modules, 'category')));
$fmt = fn($n) => $sym . number_format((float)$n, 0);
$services = array_filter(array_map('trim', explode('|', PlatformSettings::get('site_services', ''))));

public_head($company . ' - Estate management software');
public_nav('full');
?>
<main>
<section class="hero">
    <div class="wrap hero-grid">
        <div>
            <span class="eyebrow">Estate management platform</span>
            <h1>Run your estate with clarity, security and control.</h1>
            <p class="lead"><?php echo pl_e(PlatformSettings::get('site_tagline')); ?> Residents, visitors, security, dues and communication in one place &mdash; with every estate on its own private, isolated system.</p>
            <div class="hero-cta">
                <?php if ($signupOpen): ?><a class="btn btn-primary" href="signup"><?php echo $trial ? 'Start your free trial' : 'Register your estate'; ?></a><?php endif; ?>
                <a class="btn btn-ghost" href="#pricing">See pricing</a>
            </div>
            <div class="hero-note"><?php echo $trial ? "$trialDays-day trial &middot; " : ''; ?>No installation &middot; Set up in minutes &middot; Already registered? <a href="login">Log in</a></div>
        </div>
        <div class="mock" aria-hidden="true">
            <div class="mock-title"><span>Estate overview</span><span class="pill ok">Active</span></div>
            <div class="mock-row"><span>Visitor pass approved</span><span class="pill">Gate 1</span></div>
            <div class="mock-row"><span>Service charge invoice paid</span><span class="pill ok">Paid</span></div>
            <div class="mock-row"><span>Maintenance request assigned</span><span class="pill warn">In progress</span></div>
            <div class="mock-row"><span>Security roster published</span><span class="pill">Night shift</span></div>
            <div class="mock-row"><span>Announcement sent to residents</span><span class="pill ok">Delivered</span></div>
        </div>
    </div>
</section>

<section id="features" class="alt">
    <div class="wrap">
        <div class="section-head"><span class="eyebrow">Why <?php echo pl_e($company); ?></span><h2>Everything an estate needs, nothing it doesn't.</h2><p>Switch on the modules you need and grow into the rest. Each estate gets its own private environment from day one.</p></div>
        <div class="grid grid-3">
            <div class="card"><h3>Safer entry and exit</h3><p>Pre-registered visitors, gate passes, vehicle checks and an auditable log of everyone who comes and goes.</p></div>
            <div class="card"><h3>Dues collected on time</h3><p>Levies, invoices, installments and online payments with automatic receipts and clear balances.</p></div>
            <div class="card"><h3>Residents stay informed</h3><p>Announcements, broadcasts, complaints and service requests keep management and residents in step.</p></div>
        </div>
    </div>
</section>

<section id="modules">
    <div class="wrap">
        <div class="section-head"><span class="eyebrow">Modules</span><h2>Pick the capabilities that fit your estate.</h2><p>Modules are included in plans or added individually. <?php echo $showPrices ? 'Prices shown are indicative starting prices per month.' : ''; ?></p></div>
        <div class="tabs" role="tablist">
            <button class="tab active" data-cat="all">All</button>
            <?php foreach ($categories as $c): ?><button class="tab" data-cat="<?php echo pl_e($c); ?>"><?php echo pl_e($c); ?></button><?php endforeach; ?>
        </div>
        <div class="grid grid-3" id="moduleGrid">
            <?php foreach ($modules as $m): $caps = array_filter(explode('|', (string)$m['capabilities'])); ?>
            <article class="card module" data-cat="<?php echo pl_e($m['category']); ?>">
                <div class="cat"><?php echo pl_e($m['category']); ?></div>
                <h3><?php echo pl_e($m['name']); ?></h3>
                <p><?php echo pl_e($m['description']); ?></p>
                <p class="purpose"><?php echo pl_e($m['purpose']); ?></p>
                <ul class="checks"><?php foreach ($caps as $cap): ?><li><?php echo pl_e($cap); ?></li><?php endforeach; ?></ul>
                <div class="foot">
                    <span class="muted" style="font-size:.82rem"><?php echo $m['in_plans'] ? 'In: ' . pl_e(implode(', ', $m['in_plans'])) : 'Add-on'; ?></span>
                    <?php if ($showPrices): ?><span class="price"><?php echo floatval($m['default_price']) > 0 ? 'from ' . $fmt($m['default_price']) . '<small> /mo</small>' : 'Included'; ?></span><?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section id="pricing" class="alt">
    <div class="wrap" id="pricingWrap">
        <div class="section-head"><span class="eyebrow">Pricing</span><h2>Simple plans that scale with your estate.</h2><p>Choose by number of residents. Upgrade or downgrade any time; your modules follow your plan.</p></div>
        <div class="toggle" role="group" aria-label="Billing cycle"><button class="active" data-cycle="monthly">Monthly</button><button data-cycle="annual">Annual</button></div>
        <div class="grid grid-3">
            <?php foreach ($plans as $p):
                $inc = array_filter($p['modules'], fn($m) => $m['inclusion'] === 'included');
                $opt = array_filter($p['modules'], fn($m) => $m['inclusion'] === 'optional');
                $saving = ($p['monthly_price'] > 0 && $p['annual_price'] > 0) ? round((1 - $p['annual_price'] / ($p['monthly_price'] * 12)) * 100) : 0;
            ?>
            <div class="card plan <?php echo $p['is_featured'] ? 'featured' : ''; ?>">
                <?php if ($p['is_featured']): ?><span class="badge">Most popular</span><?php endif; ?>
                <h3><?php echo pl_e($p['name']); ?></h3>
                <p class="muted" style="font-size:.93rem"><?php echo pl_e($p['description']); ?></p>
                <div class="amount"><span class="monthly-only"><?php echo $fmt($p['monthly_price']); ?><small> /month</small></span><span class="annual-only"><?php echo $fmt($p['annual_price']); ?><small> /year</small></span></div>
                <div class="cap"><span class="annual-only"><?php echo $saving > 0 ? "Save $saving% vs monthly &middot; " : ''; ?></span><?php echo intval($p['resident_limit']) ? 'Up to ' . number_format($p['resident_limit']) . ' residents' : 'Unlimited residents'; ?></div>
                <ul class="checks">
                    <li><?php echo count($inc); ?> modules included</li>
                    <?php if (intval($p['user_limit'])): ?><li>Up to <?php echo intval($p['user_limit']); ?> staff accounts</li><?php else: ?><li>Unlimited staff accounts</li><?php endif; ?>
                    <li><?php echo pl_e(ucfirst($p['support_level'])); ?> support</li>
                    <?php if ($opt): ?><li><?php echo count($opt); ?> optional add-on modules</li><?php endif; ?>
                </ul>
                <a class="btn <?php echo $p['is_featured'] ? 'btn-primary' : 'btn-ghost'; ?> btn-block" href="signup?plan=<?php echo urlencode($p['slug']); ?>">Choose <?php echo pl_e($p['name']); ?></a>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="muted" style="margin-top:22px;font-size:.9rem">Prices in <?php echo pl_e(PlatformSettings::get('currency', 'NGN')); ?>. Monthly plans include a payment grace period of <?php echo PlatformSettings::int('grace_period_days', 15); ?> days after the due date. <?php echo $trial ? "Every new estate can start with a $trialDays-day trial." : ''; ?></p>
    </div>
</section>

<section>
    <div class="wrap">
        <div class="section-head"><span class="eyebrow">Getting started</span><h2>From sign-up to live in four steps.</h2></div>
        <div class="grid grid-4 steps">
            <div class="step"><h3>Register</h3><p>Tell us about your estate and create the Central Admin account.</p></div>
            <div class="step"><h3>Verify</h3><p>Confirm your email with a one-time code. Your private system is created, empty and ready.</p></div>
            <div class="step"><h3>Choose a plan</h3><p>Our team reviews your onboarding request while you pick a plan and pay securely online.</p></div>
            <div class="step"><h3>Invite your people</h3><p>Invite managers, guards and residents by email. They activate their own accounts.</p></div>
        </div>
    </div>
</section>

<section id="security" class="alt">
    <div class="wrap">
        <div class="section-head"><span class="eyebrow">Security</span><h2>Built so one estate can never see another.</h2></div>
        <div class="grid grid-3">
            <div class="card"><h3>Isolated by design</h3><p>Every estate runs on its own database. There is no shared table of residents, payments or visitors.</p></div>
            <div class="card"><h3>Verified accounts only</h3><p>Email and one-time-code verification for the first administrator, and mandatory invitations for everyone else.</p></div>
            <div class="card"><h3>Everything is audited</h3><p>Sign-ins, role changes, payments and administrative actions are recorded with who, what and when.</p></div>
            <div class="card"><h3>Protected sessions</h3><p>Hashed passwords, rate-limited sign-in, expiring sessions and request forgery protection.</p></div>
            <div class="card"><h3>Backups and recovery</h3><p>Regular per-estate backups that can only be restored into the estate they belong to.</p></div>
            <div class="card"><h3>Your data, your exit</h3><p>Export your estate's data any time, and keep it safe for <?php echo PlatformSettings::int('retention_days', 45); ?> days after a lapse so you can come back.</p></div>
        </div>
    </div>
</section>

<section id="about">
    <div class="wrap grid grid-2" style="align-items:start">
        <div>
            <span class="eyebrow">About <?php echo pl_e($company); ?></span>
            <h2>Software built with estates in mind.</h2>
            <p><?php echo nl2br(pl_e(PlatformSettings::get('site_about'))); ?></p>
            <?php if ($services): ?><ul class="checks"><?php foreach ($services as $s): ?><li><?php echo pl_e($s); ?></li><?php endforeach; ?></ul><?php endif; ?>
        </div>
        <div class="card" id="support">
            <h3>Support &amp; contact</h3>
            <p class="muted"><?php echo pl_e(PlatformSettings::get('site_support_hours')); ?></p>
            <ul class="checks">
                <?php if ($v = PlatformSettings::get('site_support_email')): ?><li><a href="mailto:<?php echo pl_e($v); ?>"><?php echo pl_e($v); ?></a></li><?php endif; ?>
                <?php if ($v = PlatformSettings::get('site_support_phone')): ?><li><?php echo pl_e($v); ?></li><?php endif; ?>
                <?php if ($v = PlatformSettings::get('site_address')): ?><li><?php echo pl_e($v); ?></li><?php endif; ?>
                <?php if ($v = PlatformSettings::get('site_website')): ?><li><a href="<?php echo pl_e($v); ?>" target="_blank" rel="noopener"><?php echo pl_e($v); ?></a></li><?php endif; ?>
            </ul>
            <p style="margin-bottom:0">Every new estate gets a guided onboarding request handled by our team.</p>
        </div>
    </div>
</section>

<section class="alt">
    <div class="wrap faq" style="max-width:760px">
        <div class="section-head"><span class="eyebrow">FAQ</span><h2>Common questions</h2></div>
        <details><summary>Do we need to install anything?</summary><p>No. The platform runs in the browser on desktop and mobile.</p></details>
        <details><summary>What happens if a payment is late?</summary><p>Your estate stays fully available during a <?php echo PlatformSettings::int('grace_period_days', 15); ?>-day grace period with reminders. If payment is still not received the estate is suspended, but your data is preserved for <?php echo PlatformSettings::int('retention_days', 45); ?> days so you can reactivate at any time.</p></details>
        <details><summary>Can we change plans later?</summary><p>Yes. Upgrades apply immediately with only the price difference charged; downgrades take effect at the end of your billing period.</p></details>
        <details><summary>Who can see our data?</summary><p>Only people you invite to your estate. Each estate is isolated from every other estate.</p></details>
    </div>
</section>

<section>
    <div class="wrap" style="text-align:center">
        <h2>Ready to bring order to your estate?</h2>
        <p class="muted">Create your Central Admin account and your private estate workspace in a few minutes.</p>
        <div class="hero-cta" style="justify-content:center">
            <?php if ($signupOpen): ?><a class="btn btn-primary" href="signup"><?php echo $trial ? 'Start your free trial' : 'Register your estate'; ?></a><?php endif; ?>
            <a class="btn btn-ghost" href="login">Log in</a>
        </div>
    </div>
</section>
</main>
<script>
(function () {
  var tabs = document.querySelectorAll('.tab'), cards = document.querySelectorAll('#moduleGrid .module');
  tabs.forEach(function (t) { t.addEventListener('click', function () {
    tabs.forEach(function (x) { x.classList.remove('active'); }); t.classList.add('active');
    var c = t.getAttribute('data-cat'); cards.forEach(function (k) { k.style.display = (c === 'all' || k.getAttribute('data-cat') === c) ? '' : 'none'; });
  }); });
  var wrap = document.getElementById('pricingWrap');
  document.querySelectorAll('.toggle button').forEach(function (b) { b.addEventListener('click', function () {
    document.querySelectorAll('.toggle button').forEach(function (x) { x.classList.remove('active'); }); b.classList.add('active');
    wrap.classList.toggle('show-annual', b.getAttribute('data-cycle') === 'annual');
  }); });
  document.querySelectorAll('#navLinks a').forEach(function (a) { a.addEventListener('click', function () { document.getElementById('navLinks').classList.remove('open'); }); });
})();
</script>
<?php public_footer();
