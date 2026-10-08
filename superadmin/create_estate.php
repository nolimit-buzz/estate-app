<?php
// superadmin/create_estate.php - register an estate on behalf of a customer.
// Same pipeline as public sign-up: empty isolated environment + unverified Central Admin who must verify and set their own password.
$page_title = 'Register an estate';
require_once __DIR__ . '/includes/super_header.php';
sa_require_role(['superadmin', 'support']);
$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $in = array_map('trim', array_intersect_key($_POST, array_flip(['estate_name', 'organisation_type', 'address', 'city', 'state', 'contact_phone', 'estate_code', 'admin_name', 'admin_email', 'admin_phone'])));
        $in['password'] = ''; // the Central Admin chooses their own after verifying their email
        $in['want_trial'] = !empty($_POST['want_trial']);
        $res = EstateService::register($in, 'superadmin');
        redirectWithFlash('estate?id=' . $res['estate']['id'], 'Estate created. A verification email has been sent to ' . $in['admin_email'] . '.');
    } catch (Throwable $e) { $err = $e->getMessage(); }
}
require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';
$v = fn($k) => e($_POST[$k] ?? '');
?>
<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>
<form method="post" class="card-custom p-3 p-md-4" style="max-width:760px">
    <p class="text-secondary small">Creates an empty, isolated estate environment. The Central Admin receives a verification email (code + link), sets their own password, and then continues through onboarding, plan selection and payment. No sample data is created.</p>
    <h6 class="fw-bold">Estate</h6>
    <div class="row g-3 mb-3">
        <div class="col-md-8"><label class="form-label small">Name</label><input class="form-control" name="estate_name" required value="<?= $v('estate_name') ?>"></div>
        <div class="col-md-4"><label class="form-label small">Code (optional)</label><input class="form-control" name="estate_code" pattern="[a-zA-Z0-9-]*" value="<?= $v('estate_code') ?>"></div>
        <div class="col-md-6"><label class="form-label small">Type</label><input class="form-control" name="organisation_type" value="<?= $v('organisation_type') ?>"></div>
        <div class="col-md-6"><label class="form-label small">Phone</label><input class="form-control" name="contact_phone" value="<?= $v('contact_phone') ?>"></div>
        <div class="col-12"><label class="form-label small">Address</label><input class="form-control" name="address" value="<?= $v('address') ?>"></div>
        <div class="col-md-6"><label class="form-label small">City</label><input class="form-control" name="city" value="<?= $v('city') ?>"></div>
        <div class="col-md-6"><label class="form-label small">State</label><input class="form-control" name="state" value="<?= $v('state') ?>"></div>
    </div>
    <h6 class="fw-bold">Central Admin</h6>
    <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label small">Full name</label><input class="form-control" name="admin_name" required value="<?= $v('admin_name') ?>"></div>
        <div class="col-md-6"><label class="form-label small">Email</label><input class="form-control" type="email" name="admin_email" required value="<?= $v('admin_email') ?>"></div>
        <div class="col-md-6"><label class="form-label small">Mobile</label><input class="form-control" name="admin_phone" value="<?= $v('admin_phone') ?>"></div>
    </div>
    <?php if (PlatformSettings::bool('trial_enabled', true)): ?><div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="want_trial" id="wt" value="1" checked><label class="form-check-label" for="wt">Start with a <?= PlatformSettings::int('trial_days', 14) ?>-day trial</label></div><?php endif; ?>
    <button class="btn btn-primary">Create estate &amp; send verification</button>
</form>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
