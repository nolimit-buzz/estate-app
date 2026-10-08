<?php
// admin/onboarding.php - Central Admin onboarding request (feeds the platform support team) + setup checklist
require_once '../config.php';
require_once '../includes/auth_guard.php';
requireAdminAccess();
require_once '../includes/SubscriptionStatus.php';

$estate = Tenancy::find(Tenancy::currentId());
$admin = $conn->query("SELECT id, name, email FROM users WHERE id = " . intval($_SESSION['user_id']))->fetch_assoc();
$onb = json_decode($estate['onboarding_json'] ?? '{}', true) ?: [];
$ok = ''; $err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $d = [
        'estimated_residents' => intval($_POST['estimated_residents'] ?? 0), 'zones' => intval($_POST['zones'] ?? 0),
        'modules_needed' => array_slice(array_map('strval', (array)($_POST['modules_needed'] ?? [])), 0, 30), 'go_live' => trim($_POST['go_live'] ?? ''), 'notes' => trim($_POST['notes'] ?? ''),
    ];
    if ($d['estimated_residents'] < 1) $err = 'Please tell us roughly how many residents you expect.';
    else {
        Support::submitOnboardingRequest($estate, $admin, $d + ['want_trial' => $onb['want_trial'] ?? false]);
        logAudit($conn, 'Onboarding submitted', 'Onboarding', 'Central Admin submitted the onboarding request');
        redirectWithFlash('onboarding', 'Thank you - our team has your onboarding request and will review it shortly.');
    }
}
$estate = Tenancy::find($estate['id']);
$onb = json_decode($estate['onboarding_json'] ?? '{}', true) ?: [];
$checklist = SubscriptionStatus::checklist();
$modules = getAllPlatformModules();
$tickets = Support::forEstate($estate['id']);
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="page-header-futuristic mb-4"><div><h1 class="page-title">Onboarding</h1><p class="page-subtitle">Tell us about your estate so our team can help you get live quickly.</p></div></div>
<?php if ($err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
<div class="row g-3 mb-5">
  <div class="col-lg-4"><div class="card border-0 shadow-sm"><div class="card-body"><h6 class="fw-bold">Setup checklist</h6>
    <ul class="list-unstyled mb-0"><?php foreach ($checklist as [$label, $done, $link]): ?><li class="py-2 border-bottom d-flex justify-content-between align-items-center"><span class="<?php echo $done ? 'text-muted text-decoration-line-through' : 'fw-semibold'; ?>"><?php echo htmlspecialchars($label); ?></span><?php if ($done): ?><span class="text-success">Done</span><?php elseif ($link): ?><a class="small" href="<?php echo $link; ?>">Open</a><?php endif; ?></li><?php endforeach; ?></ul>
    <div class="small text-muted mt-3">Stage: <strong><?php echo ucwords(str_replace('_', ' ', $estate['onboarding_status'])); ?></strong></div></div></div></div>
  <div class="col-lg-8"><form method="post" class="card border-0 shadow-sm"><div class="card-body">
    <h6 class="fw-bold">Onboarding details</h6>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label small">Estimated number of residents</label><input type="number" min="1" class="form-control" name="estimated_residents" required value="<?php echo htmlspecialchars($onb['estimated_residents'] ?? ''); ?>"></div>
      <div class="col-md-6"><label class="form-label small">Number of zones / blocks</label><input type="number" min="0" class="form-control" name="zones" value="<?php echo htmlspecialchars($onb['zones'] ?? ''); ?>"></div>
      <div class="col-12"><label class="form-label small">Modules you are most interested in</label><div class="row g-1">
        <?php $sel = (array)($onb['modules_needed'] ?? []); foreach ($modules as $k => $m): ?><div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="modules_needed[]" value="<?php echo htmlspecialchars($k); ?>" id="mn_<?php echo $k; ?>" <?php echo in_array($k, $sel) ? 'checked' : ''; ?>><label class="form-check-label small" for="mn_<?php echo $k; ?>"><?php echo htmlspecialchars($m['name']); ?></label></div></div><?php endforeach; ?></div></div>
      <div class="col-md-6"><label class="form-label small">Target go-live date</label><input type="date" class="form-control" name="go_live" value="<?php echo htmlspecialchars($onb['go_live'] ?? ''); ?>"></div>
      <div class="col-12"><label class="form-label small">Anything else we should know?</label><textarea class="form-control" rows="3" name="notes"><?php echo htmlspecialchars($onb['notes'] ?? ''); ?></textarea></div>
    </div>
    <button class="btn btn-primary mt-3"><?php echo !empty($onb['estimated_residents']) ? 'Update request' : 'Submit to support team'; ?></button>
    <a href="subscription" class="btn btn-light mt-3">Skip to plans</a>
  </div></form>
  <?php if ($tickets): ?><div class="small text-muted mt-2">Your onboarding request is tracked as ticket <a href="support?id=<?php echo $tickets[count($tickets) - 1]['id']; ?>"><?php echo htmlspecialchars($tickets[count($tickets) - 1]['ticket_no']); ?></a>.</div><?php endif; ?></div>
</div>
<?php include '../includes/footer.php'; ?>
