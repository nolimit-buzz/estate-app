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
<section class="hero hero-full">
    <div class="hero-blob b1"></div><div class="hero-blob b2"></div>
    <div class="wrap hero-grid">
        <div class="hero-copy reveal">
            <span class="eyebrow">Estate management platform</span>
            <h1>Run your estate with <span class="hl">clarity</span>, security and control.</h1>
            <p class="lead"><?php echo pl_e(PlatformSettings::get('site_tagline')); ?> Residents, visitors, security, dues and communication in one place &mdash; with every estate on its own private, isolated system.</p>
            <div class="hero-cta">
                <?php if ($signupOpen): ?><a class="btn btn-primary" href="signup"><?php echo $trial ? 'Start your free trial' : 'Register your estate'; ?> &rarr;</a><?php endif; ?>
                <a class="btn btn-ghost" href="#tour">&#9654; See it in action</a>
            </div>
            <div class="hero-note"><?php echo $trial ? "$trialDays-day trial &middot; " : ''; ?>No installation &middot; Set up in minutes &middot; Already registered? <a href="login">Log in</a></div>
            <div class="hero-stats">
                <div><b data-count="100" data-suffix="%">0</b><span>Isolated data</span></div>
                <div><b data-count="24" data-suffix="/7">0</b><span>Gate &amp; alerts</span></div>
                <div><b data-count="12" data-suffix="+">0</b><span>Modules</span></div>
            </div>
        </div>

        <div class="hero-visual reveal">
            <div class="app-window">
                <div class="aw-bar"><i></i><i></i><i></i><span>app.estate &mdash; Dashboard</span></div>
                <div class="aw-body">
                    <div class="aw-rail"><b class="on"></b><b></b><b></b><b></b><b></b></div>
                    <div class="aw-main">
                        <div class="aw-kpis">
                            <div><small>Residents</small><strong data-count="1248">0</strong><em class="up">&uarr; 8.4%</em></div>
                            <div><small>Dues collected</small><strong>&#8358;<span data-count="86">0</span>%</strong><em class="up">&uarr; 12%</em></div>
                            <div><small>Open requests</small><strong data-count="17">0</strong><em class="dn">&darr; 5%</em></div>
                        </div>
                        <div class="aw-chart">
                            <div class="aw-chart-head"><span>Revenue overview</span><span class="lg"><i class="a"></i>Collected <i class="b"></i>Expected</span></div>
                            <svg viewBox="0 0 400 140" preserveAspectRatio="none" class="chart-svg">
                                <defs><linearGradient id="gA" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2563eb" stop-opacity=".35"/><stop offset="1" stop-color="#2563eb" stop-opacity="0"/></linearGradient></defs>
                                <g stroke="#e8edf4" stroke-width="1"><line x1="0" y1="25" x2="400" y2="25"/><line x1="0" y1="60" x2="400" y2="60"/><line x1="0" y1="95" x2="400" y2="95"/></g>
                                <path d="M0,110 C40,95 70,70 110,78 C150,86 180,45 220,50 C260,55 290,25 330,32 C360,36 380,20 400,16 L400,140 L0,140Z" fill="url(#gA)"/>
                                <path class="draw" d="M0,110 C40,95 70,70 110,78 C150,86 180,45 220,50 C260,55 290,25 330,32 C360,36 380,20 400,16" fill="none" stroke="#2563eb" stroke-width="3" stroke-linecap="round"/>
                                <path d="M0,120 C50,112 90,100 130,104 C170,108 210,80 250,84 C290,88 340,62 400,58" fill="none" stroke="#84cc16" stroke-width="2.5" stroke-dasharray="5 5" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div class="aw-row">
                            <div class="aw-donut"><svg viewBox="0 0 42 42"><circle cx="21" cy="21" r="15.9" fill="none" stroke="#eef2f7" stroke-width="5"/><circle class="dn-arc" cx="21" cy="21" r="15.9" fill="none" stroke="#2563eb" stroke-width="5" stroke-dasharray="72 28" stroke-dashoffset="25" stroke-linecap="round"/><circle cx="21" cy="21" r="15.9" fill="none" stroke="#84cc16" stroke-width="5" stroke-dasharray="18 82" stroke-dashoffset="-47" stroke-linecap="round"/></svg><div><b>72%</b><small>Paid</small></div></div>
                            <ul class="aw-feed">
                                <li><i class="g"></i>Visitor pass approved <span>Gate 1</span></li>
                                <li><i class="b"></i>Service charge paid <span>Block C</span></li>
                                <li><i class="o"></i>Repair assigned <span>Plumbing</span></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <div class="float-card fc1"><span class="ic">&#128737;</span><div><b>Gate secured</b><small>3 passes verified</small></div></div>
            <div class="float-card fc2"><span class="ic g">&#10003;</span><div><b>&#8358;2.4M received</b><small>Today</small></div></div>
        </div>
    </div>
</section>

<section class="logo-strip">
    <div class="wrap">
        <p>One platform for every part of estate life</p>
        <div class="marquee"><div class="track">
            <span>Visitor management</span><span>Levies &amp; invoices</span><span>Security patrols</span><span>Maintenance</span><span>Community chat</span><span>Artisan marketplace</span><span>Emergency alerts</span><span>Reports &amp; audit</span>
            <span>Visitor management</span><span>Levies &amp; invoices</span><span>Security patrols</span><span>Maintenance</span><span>Community chat</span><span>Artisan marketplace</span><span>Emergency alerts</span><span>Reports &amp; audit</span>
        </div></div>
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

<section id="tour" class="tour">
    <div class="wrap">
        <div class="section-head reveal"><span class="eyebrow">Product tour</span><h2>See every corner of your estate at a glance.</h2><p>Click through the real workflows managers, guards and residents use every day.</p></div>
        <div class="tour-tabs reveal">
            <button class="tt active" data-pane="p1">Dashboard</button>
            <button class="tt" data-pane="p2">Finance</button>
            <button class="tt" data-pane="p3">Security</button>
            <button class="tt" data-pane="p4">Community</button>
        </div>
        <div class="tour-stage reveal">
            <div class="app-window big">
                <div class="aw-bar"><i></i><i></i><i></i><span id="tourTitle">app.estate &mdash; Dashboard</span></div>
                <div class="tour-panes">
                    <div class="pane show" id="p1">
                        <div class="pane-grid">
                            <div class="pcard"><small>Total residents</small><strong>1,248</strong><svg viewBox="0 0 120 36" class="spark"><path d="M0,28 C15,24 25,30 40,18 C55,6 70,20 85,12 C100,4 110,10 120,3" fill="none" stroke="#2563eb" stroke-width="2.5" stroke-linecap="round"/></svg></div>
                            <div class="pcard"><small>Occupancy</small><strong>94%</strong><svg viewBox="0 0 120 36" class="spark"><path d="M0,20 C20,22 30,10 50,14 C70,18 85,6 120,8" fill="none" stroke="#84cc16" stroke-width="2.5" stroke-linecap="round"/></svg></div>
                            <div class="pcard"><small>Open tickets</small><strong>17</strong><svg viewBox="0 0 120 36" class="spark"><path d="M0,8 C20,12 35,6 55,16 C75,26 95,18 120,28" fill="none" stroke="#f59e0b" stroke-width="2.5" stroke-linecap="round"/></svg></div>
                            <div class="pcard wide"><small>Resident activity (7 days)</small>
                                <div class="bars"><i style="--h:45%"></i><i style="--h:70%"></i><i style="--h:55%"></i><i style="--h:90%"></i><i style="--h:65%"></i><i style="--h:80%"></i><i style="--h:100%"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="pane" id="p2">
                        <div class="pane-grid">
                            <div class="pcard wide"><small>Monthly collections</small><svg viewBox="0 0 400 130" preserveAspectRatio="none" class="chart-svg tall"><path d="M0,100 C40,90 70,60 110,66 C150,72 180,34 220,40 C260,46 300,20 340,26 C370,30 385,14 400,10 L400,130 L0,130Z" fill="#2563eb" fill-opacity=".12"/><path class="draw" d="M0,100 C40,90 70,60 110,66 C150,72 180,34 220,40 C260,46 300,20 340,26 C370,30 385,14 400,10" fill="none" stroke="#2563eb" stroke-width="3" stroke-linecap="round"/></svg></div>
                            <div class="pcard"><small>Outstanding</small><strong>&#8358;1.2M</strong></div>
                            <div class="pcard"><small>Paid this month</small><strong>&#8358;8.6M</strong></div>
                            <div class="pcard wide"><small>Recent invoices</small><ul class="rows"><li>Service charge &mdash; Block A <span class="pill ok">Paid</span></li><li>Security levy &mdash; Block C <span class="pill warn">Due</span></li><li>Water &mdash; Block B <span class="pill ok">Paid</span></li></ul></div>
                        </div>
                    </div>
                    <div class="pane" id="p3">
                        <div class="pane-grid">
                            <div class="pcard"><small>Visitors today</small><strong>86</strong></div>
                            <div class="pcard"><small>Incidents</small><strong>2</strong></div>
                            <div class="pcard"><small>Guards on duty</small><strong>9</strong></div>
                            <div class="pcard wide"><small>Gate log</small><ul class="rows"><li>Chidi O. &mdash; Visitor pass <span class="pill ok">Entered</span></li><li>Delivery &mdash; Courier <span class="pill">Pending</span></li><li>Mrs. Bello &mdash; Guest <span class="pill ok">Exited</span></li></ul></div>
                        </div>
                    </div>
                    <div class="pane" id="p4">
                        <div class="pane-grid">
                            <div class="pcard wide"><small>Community chat</small>
                                <div class="chat"><p class="in">Is the generator on tonight?</p><p class="out">Yes, power returns at 6pm.</p><p class="in">Thanks, manager!</p></div>
                            </div>
                            <div class="pcard"><small>Announcements</small><strong>5</strong></div>
                            <div class="pcard"><small>Artisans online</small><strong>23</strong></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="photo-band">
    <div class="photo-overlay"></div>
    <div class="wrap photo-inner">
        <div class="reveal"><span class="eyebrow light">Built for real estates</span><h2>From the main gate to the last invoice &mdash; one calm system.</h2></div>
        <div class="photo-stats reveal">
            <div><b data-count="500" data-suffix="+">0</b><span>Gate passes a week</span></div>
            <div><b data-count="98" data-suffix="%">0</b><span>On-time dues</span></div>
            <div><b data-count="3" data-suffix="x">0</b><span>Faster response</span></div>
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
        <div class="grid grid-3 collapsed" id="moduleGrid">
            <?php foreach ($modules as $m): $caps = array_filter(explode('|', (string)$m['capabilities'])); ?>
            <article class="card module" data-cat="<?php echo pl_e($m['category']); ?>">
                <div class="cat"><?php echo pl_e($m['category']); ?></div>
                <h3><?php echo pl_e($m['name']); ?></h3>
                <p><?php echo pl_e($m['description']); ?></p>
                <p class="purpose"><?php echo pl_e($m['purpose']); ?></p>
                <ul class="checks"><?php foreach ($caps as $cap): ?><li><?php echo pl_e($cap); ?></li><?php endforeach; ?></ul>
                <div class="foot">
                    <span class="muted" style="font-size:.82rem"><?php echo $m['in_plans'] ? 'In: ' . pl_e(implode(', ', $m['in_plans'])) : 'Add-on'; ?></span>
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
<script>
(function () {
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  document.querySelectorAll('.section-head, .grid > *, .faq details, #about .wrap > *').forEach(function (el) { el.classList.add('reveal'); });
  // scroll reveal
  var io = ('IntersectionObserver' in window) ? new IntersectionObserver(function (es) {
    es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
  }, { threshold: .12 }) : null;
  document.querySelectorAll('.reveal').forEach(function (el) { if (io) io.observe(el); else el.classList.add('in'); });
  // counters
  function count(el) {
    var end = parseFloat(el.getAttribute('data-count')), suf = el.getAttribute('data-suffix') || '';
    if (reduce) { el.textContent = end.toLocaleString() + suf; return; }
    var t0 = null, dur = 1400;
    function step(t) { if (!t0) t0 = t; var p = Math.min((t - t0) / dur, 1), v = Math.round(end * (1 - Math.pow(1 - p, 3)));
      el.textContent = v.toLocaleString() + suf; if (p < 1) requestAnimationFrame(step); }
    requestAnimationFrame(step);
  }
  var cio = ('IntersectionObserver' in window) ? new IntersectionObserver(function (es) {
    es.forEach(function (e) { if (e.isIntersecting) { count(e.target); cio.unobserve(e.target); } });
  }, { threshold: .4 }) : null;
  document.querySelectorAll('[data-count]').forEach(function (el) { if (cio) cio.observe(el); else count(el); });
  // modules: show 4, expand on demand
  (function () {
    var g = document.getElementById('moduleGrid'); if (!g) return;
    var total = g.querySelectorAll('.module').length; if (total <= 4) { g.classList.remove('collapsed'); return; }
    var more = document.createElement('div'); more.className = 'modules-more';
    var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-ghost';
    var open = false;
    function sync(cat) { var filtered = cat && cat !== 'all'; g.classList.toggle('collapsed', !open && !filtered); more.style.display = filtered ? 'none' : ''; b.textContent = open ? 'Show fewer modules' : 'View all ' + total + ' modules'; }
    b.addEventListener('click', function () { open = !open; sync('all'); });
    more.appendChild(b); g.parentNode.insertBefore(more, g.nextSibling); sync('all');
    document.querySelectorAll('.tab').forEach(function (t) { t.addEventListener('click', function () { sync(t.getAttribute('data-cat')); }); });
  })();
  // product tour tabs
  var titles = { p1: 'Dashboard', p2: 'Finance', p3: 'Security', p4: 'Community' };
  var btns = document.querySelectorAll('.tt'), panes = document.querySelectorAll('.pane'), ttl = document.getElementById('tourTitle');
  var order = ['p1', 'p2', 'p3', 'p4'], idx = 0, timer = null;
  function show(id) {
    btns.forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-pane') === id); });
    panes.forEach(function (p) { p.classList.toggle('show', p.id === id); });
    if (ttl) ttl.textContent = 'app.estate — ' + titles[id];
    idx = order.indexOf(id);
  }
  btns.forEach(function (b) { b.addEventListener('click', function () { show(b.getAttribute('data-pane')); clearInterval(timer); }); });
  if (!reduce && btns.length) timer = setInterval(function () { show(order[(idx + 1) % order.length]); }, 5000);
})();
</script>
<?php public_footer();
