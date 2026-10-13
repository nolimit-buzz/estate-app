<?php
// superadmin/modules.php - Global module management: catalogue, default price, global on/off, plan membership, overrides
$page_title = 'Modules';
require_once __DIR__ . '/includes/super_header.php';
require_once dirname(__DIR__) . '/includes/FeatureGrid.php';
sa_require_role(['superadmin']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'plan_features') {
    $pid = intval($_POST['plan_id'] ?? 0);
    try {
        $plan = Plans::get($pid);
        if (!$plan) throw new RuntimeException('Choose a plan first.');
        $catalog = FeatureCatalog::all();
        $feats = array_values(array_filter(array_keys($_POST['feat'] ?? []), fn($x) => isset($catalog[$x])));
        $platform->query("DELETE FROM plan_features WHERE plan_id = $pid");
        $ins = $platform->prepare("INSERT IGNORE INTO plan_features (plan_id, feature_slug) VALUES (?,?)");
        foreach ($feats as $x) { $ins->bind_param('is', $pid, $x); $ins->execute(); }
        // keep module gating consistent: derived modules + modules that have no feature group
        $grouped = [];
        foreach ($catalog as $c) if ($c['module_key']) $grouped[$c['module_key']] = true;
        $keep = [];
        $r = $platform->query("SELECT module_key FROM plan_modules WHERE plan_id = $pid");
        while ($r && $row = $r->fetch_assoc()) if (empty($grouped[$row['module_key']])) $keep[] = $row['module_key'];
        $mods = array_unique(array_merge($keep, FeatureCatalog::modulesFor($feats)));
        $platform->query("DELETE FROM plan_modules WHERE plan_id = $pid");
        $pm = $platform->prepare("INSERT INTO plan_modules (plan_id, module_key, inclusion, module_price) VALUES (?,?,'included',NULL)");
        foreach ($mods as $mk) { $pm->bind_param('is', $pid, $mk); $pm->execute(); }
        $platform->query("UPDATE plans SET version = version + 1 WHERE id = $pid");
        $after = Plans::snapshot($pid);
        $r = $platform->query("SELECT * FROM subscriptions WHERE plan_id = $pid AND status IN ('active','renewed','grace_period','past_due','pending_payment')");
        $n = 0;
        if (!empty($_POST['apply_existing'])) while ($r && $sub = $r->fetch_assoc()) {
            $old = json_decode($sub['plan_snapshot_json'] ?? '', true);
            $snap = json_encode($after + ['addons' => $old['addons'] ?? []]);
            $u = $platform->prepare("UPDATE subscriptions SET plan_snapshot_json = ? WHERE id = ?");
            $u->bind_param('si', $snap, $sub['id']); $u->execute();
            Entitlements::flush($sub['estate_id']); Tenancy::mirror($sub['estate_id']); $n++;
        }
        Audit::log('plan.features_updated', ['estate_id' => null, 'module' => 'plans', 'entity_type' => 'plan', 'entity_id' => $pid, 'details' => "{$plan['name']}: " . count($feats) . " features selected" . ($n ? ", applied to $n subscription(s)" : ''), 'after' => $after]);
        redirectWithFlash('modules?plan=' . $pid, "{$plan['name']} now has " . count($feats) . " features." . ($n ? " Applied to $n existing estate(s)." : ' Existing estates keep their current terms.'));
    } catch (Throwable $ex) { redirectWithFlash('modules?plan=' . $pid, null, $ex->getMessage()); }
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $k = $_POST['module_key'] ?? '';
    $m = $platform->query("SELECT * FROM modules WHERE module_key = '" . $platform->real_escape_string($k) . "'")->fetch_assoc();
    try {
        if (!$m) throw new RuntimeException('Unknown module.');
        if (($_POST['action'] ?? '') === 'toggle') {
            if (!sa_confirmed($k)) throw new RuntimeException('Type the module key to confirm a global change.');
            $new = $m['global_enabled'] ? 0 : 1;
            $platform->query("UPDATE modules SET global_enabled = $new WHERE module_key = '" . $platform->real_escape_string($k) . "'");
            $affected = intval($platform->query("SELECT COUNT(DISTINCT s.estate_id) c FROM subscriptions s JOIN plan_modules pm ON pm.plan_id = s.plan_id WHERE pm.module_key = '" . $platform->real_escape_string($k) . "' AND s.status IN ('active','renewed','grace_period')")->fetch_assoc()['c']);
            Entitlements::flush();
            Audit::log('module.global_toggled', ['estate_id' => null, 'module' => 'modules', 'entity_type' => 'module', 'entity_id' => $k, 'details' => "{$m['name']} globally " . ($new ? 'ENABLED' : 'DISABLED') . ". About $affected subscribed estate(s) affected. " . trim($_POST['reason'] ?? ''), 'before' => ['global_enabled' => (int)$m['global_enabled']], 'after' => ['global_enabled' => $new]]);
            redirectWithFlash('modules', "{$m['name']} is now " . ($new ? 'enabled' : 'disabled') . " platform-wide.");
        }
        $nm = trim($_POST['name']); $cat = trim($_POST['category']); $de = trim($_POST['description']); $pu = trim($_POST['purpose']);
        $caps = implode('|', array_filter(array_map('trim', preg_split('/\R/', (string)$_POST['capabilities']))));
        $sp = !empty($_POST['show_public']) ? 1 : 0; $so = intval($_POST['sort_order']);
    } catch (Throwable $ex) { redirectWithFlash('modules', null, $ex->getMessage()); }
    try {
        $st = $platform->prepare("UPDATE modules SET name=?, category=?, description=?, purpose=?, capabilities=?, show_public=?, sort_order=? WHERE module_key=?");
        $st->bind_param('sssssiis', $nm, $cat, $de, $pu, $caps, $sp, $so, $k);
        $st->execute();
        Audit::log('module.updated', ['estate_id' => null, 'module' => 'modules', 'entity_type' => 'module', 'entity_id' => $k, 'details' => "Module $k details updated", 'before' => $m, 'after' => ['name' => $nm, 'show_public' => $sp]]);
        redirectWithFlash('modules', "$nm updated.");
    } catch (Throwable $ex) { redirectWithFlash('modules', null, $ex->getMessage()); }
}

require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';
$rows = $platform->query("SELECT m.* FROM modules m ORDER BY m.sort_order")->fetch_all(MYSQLI_ASSOC);
$plansAll = $platform->query("SELECT p.id, p.name, (SELECT COUNT(*) FROM plan_features pf WHERE pf.plan_id = p.id) n FROM plans p WHERE p.status = 'active' ORDER BY p.sort_order")->fetch_all(MYSQLI_ASSOC);
$grouped = FeatureCatalog::grouped();
$allFeat = [];
foreach ($grouped as $g) foreach ($g['features'] as $f) $allFeat[$f['slug']] = true;
?>
<?php $selPlan = intval($_GET['plan'] ?? ($plansAll[0]['id'] ?? 0)); $curF = array_flip(FeatureCatalog::planFeatures($selPlan)); ?>
<div class="mb-4">
  <h5 class="fw-bold mb-1">What each module contains</h5>
  <p class="text-secondary small mb-3">Pick a plan, tick the features it should include, then save. Modules have no price of their own, each plan has one fixed price (set on the <a href="plans">Plans</a> page).</p>
  <form method="get" class="d-flex gap-2 align-items-center mb-3">
    <label class="small fw-semibold mb-0">Plan</label>
    <select name="plan" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
      <?php foreach ($plansAll as $pl): ?><option value="<?= intval($pl['id']) ?>" <?= $selPlan === intval($pl['id']) ? 'selected' : '' ?>><?= e($pl['name']) ?> (<?= intval($pl['n']) ?> features)</option><?php endforeach; ?>
    </select>
  </form>
  <form method="post">
    <input type="hidden" name="action" value="plan_features"><input type="hidden" name="plan_id" value="<?= $selPlan ?>">
    <?= render_feature_grid($grouped, ['name' => 'feat', 'checked' => $curF]) ?>
    <div class="d-flex flex-wrap gap-3 align-items-center mt-3">
      <button class="btn btn-primary">Save features for this plan</button>
      <div class="form-check"><input class="form-check-input" type="checkbox" name="apply_existing" id="ap" value="1"><label class="form-check-label small" for="ap">Also apply to estates already on this plan</label></div>
    </div>
  </form>
</div>
<details class="card-custom p-3">
  <summary class="fw-bold" style="cursor:pointer">Platform switches &amp; module details</summary>
  <p class="text-secondary small mt-2 mb-3">Turning a module off here removes it for every estate, whatever their plan. Use this only for maintenance or emergencies.</p>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Module</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $m): ?>
  <tr><td class="fw-semibold"><?= e($m['name']) ?><div class="small text-secondary"><?= e($m['category']) ?><?= $m['is_core'] ? ' · core' : '' ?></div></td>
   <td><?= $m['global_enabled'] ? sa_badge('on') : sa_badge('off') ?></td>
   <td class="text-end text-nowrap"><button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#m_<?= e($m['module_key']) ?>">Edit text</button>
     <button class="btn btn-sm btn-outline-<?= $m['global_enabled'] ? 'danger' : 'success' ?>" onclick="saConfirm({action:'modules',title:'<?= $m['global_enabled'] ? 'Disable' : 'Enable' ?> module globally',message:'<?= $m['global_enabled'] ? 'Every estate loses access immediately, even if its plan includes it.' : 'The module becomes available again according to plans and overrides.' ?>',phrase:<?= json_encode($m['module_key']) ?>,button:'<?= $m['global_enabled'] ? 'Disable' : 'Enable' ?> for all',fields:{action:'toggle',module_key:<?= json_encode($m['module_key']) ?>}})"><?= $m['global_enabled'] ? 'Turn off' : 'Turn on' ?></button></td></tr>
  <tr class="collapse" id="m_<?= e($m['module_key']) ?>"><td colspan="3" class="bg-light"><form method="post" class="row g-2 p-2"><input type="hidden" name="action" value="save"><input type="hidden" name="module_key" value="<?= e($m['module_key']) ?>">
    <div class="col-md-5"><label class="form-label small mb-0">Name</label><input class="form-control form-control-sm" name="name" value="<?= e($m['name']) ?>" required></div>
    <div class="col-md-5"><label class="form-label small mb-0">Category</label><input class="form-control form-control-sm" name="category" value="<?= e($m['category']) ?>"></div>
    <div class="col-md-2"><label class="form-label small mb-0">Order</label><input class="form-control form-control-sm" type="number" name="sort_order" value="<?= e($m['sort_order']) ?>"></div>
    <div class="col-12"><label class="form-label small mb-0">Short description</label><input class="form-control form-control-sm" name="description" value="<?= e($m['description']) ?>"></div>
    <div class="col-12"><label class="form-label small mb-0">Purpose</label><input class="form-control form-control-sm" name="purpose" value="<?= e($m['purpose']) ?>"></div>
    <div class="col-12"><label class="form-label small mb-0">Key capabilities (one per line)</label><textarea class="form-control form-control-sm" rows="3" name="capabilities"><?= e(str_replace('|', "\n", $m['capabilities'])) ?></textarea></div>
    <div class="col-12 d-flex align-items-center gap-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="show_public" value="1" id="sp_<?= e($m['module_key']) ?>" <?= $m['show_public'] ? 'checked' : '' ?>><label class="form-check-label small" for="sp_<?= e($m['module_key']) ?>">Show on landing page</label></div><button class="btn btn-sm btn-primary">Save</button></div>
  </form></td></tr>
  <?php endforeach; ?></tbody></table></div>
</details>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
