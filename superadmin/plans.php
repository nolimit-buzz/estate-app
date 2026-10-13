<?php
// superadmin/plans.php - Plan Builder: plans, prices, limits, included/optional modules, versions & impact on existing estates
$page_title = 'Plans & pricing';
require_once __DIR__ . '/includes/super_header.php';
require_once dirname(__DIR__) . '/includes/FeatureGrid.php';
sa_require_role(['superadmin', 'finance']);

$mods = $platform->query("SELECT module_key, name, category, default_price, global_enabled FROM modules ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC);
$editId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$isNew = isset($_GET['new']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $a = $_POST['action'] ?? 'save';
    try {
        if ($a === 'status') {
            $pid = intval($_POST['plan_id']); $ns = $_POST['status'];
            if (!in_array($ns, ['active', 'inactive', 'archived'], true)) throw new RuntimeException('Bad status');
            $plan = Plans::get($pid);
            if ($ns === 'archived' && !sa_confirmed($plan['name'])) throw new RuntimeException('Type the plan name to archive it.');
            $platform->query("UPDATE plans SET status = '$ns' WHERE id = $pid");
            Audit::log('plan.status_changed', ['estate_id' => null, 'module' => 'plans', 'entity_type' => 'plan', 'entity_id' => $pid, 'details' => "{$plan['name']}: {$plan['status']} -> $ns. Existing subscriptions keep their terms.", 'before' => ['status' => $plan['status']], 'after' => ['status' => $ns]]);
            redirectWithFlash('plans', "Plan {$plan['name']} is now $ns. Existing subscribers are not affected.");
        }
        // ---- save (create / update)
        $pid = intval($_POST['plan_id'] ?? 0);
        $f = [
            'name' => trim($_POST['name'] ?? ''), 'description' => trim($_POST['description'] ?? ''), 'monthly' => round(floatval($_POST['monthly_price'] ?? 0), 2), 'annual' => round(floatval($_POST['annual_price'] ?? 0), 2),
            'res' => max(0, intval($_POST['resident_limit'] ?? 0)), 'users' => max(0, intval($_POST['user_limit'] ?? 0)), 'storage' => max(0, intval($_POST['storage_limit_mb'] ?? 0)),
            'support' => trim($_POST['support_level'] ?? 'email'), 'features' => trim($_POST['features'] ?? ''), 'featured' => !empty($_POST['is_featured']) ? 1 : 0, 'public' => !empty($_POST['show_public']) ? 1 : 0,
            'status' => in_array($_POST['status'] ?? 'active', ['active', 'inactive', 'archived'], true) ? $_POST['status'] : 'active', 'sort' => intval($_POST['sort_order'] ?? 100),
        ];
        if ($f['name'] === '') throw new RuntimeException('Plan name is required.');
        if ($f['monthly'] < 0 || $f['annual'] < 0) throw new RuntimeException('Prices cannot be negative.');
        $valid = array_column($mods, 'module_key');
        // Selected features (permission slugs); modules are derived from them so route/sidebar gating stays consistent.
        $catalog = FeatureCatalog::all();
        $feats = array_values(array_filter(array_keys($_POST['feat'] ?? []), fn($s) => isset($catalog[$s])));
        $incl = [];
        foreach (FeatureCatalog::modulesFor($feats) as $k) $incl[$k] = 'included';
        foreach (array_keys($_POST['mod'] ?? []) as $k) $incl[$k] = 'included'; // modules without a feature group
        $before = $pid ? Plans::snapshot($pid) : null;

        if ($pid) {
            $cur = Plans::get($pid);
            $changed = $cur['monthly_price'] != $f['monthly'] || $cur['annual_price'] != $f['annual'] || $cur['resident_limit'] != $f['res'] || $cur['user_limit'] != $f['users'];
            $st = $platform->prepare("UPDATE plans SET name=?, description=?, monthly_price=?, annual_price=?, resident_limit=?, user_limit=?, storage_limit_mb=?, support_level=?, features=?, is_featured=?, show_public=?, status=?, sort_order=?, version = version + 1 WHERE id=?");
            $st->bind_param('ssddiiissiisii', $f['name'], $f['description'], $f['monthly'], $f['annual'], $f['res'], $f['users'], $f['storage'], $f['support'], $f['features'], $f['featured'], $f['public'], $f['status'], $f['sort'], $pid);
            $st->execute();
            $platform->query("DELETE FROM plan_modules WHERE plan_id = $pid");
        } else {
            $slug = EstateService::slugify($f['name']); $base = $slug; $i = 2;
            while ($platform->query("SELECT 1 FROM plans WHERE slug = '" . $platform->real_escape_string($slug) . "'")->num_rows) $slug = $base . '-' . $i++;
            $st = $platform->prepare("INSERT INTO plans (name, slug, description, monthly_price, annual_price, resident_limit, user_limit, storage_limit_mb, support_level, features, is_featured, show_public, status, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->bind_param('sssddiiissiisi', $f['name'], $slug, $f['description'], $f['monthly'], $f['annual'], $f['res'], $f['users'], $f['storage'], $f['support'], $f['features'], $f['featured'], $f['public'], $f['status'], $f['sort']);
            $st->execute();
            $pid = $platform->insert_id;
        }
        $pm = $platform->prepare("INSERT INTO plan_modules (plan_id, module_key, inclusion, module_price) VALUES (?,?,'included',NULL)");
        foreach (array_keys($incl) as $k) {
            if (!in_array($k, $valid, true)) continue;
            $pm->bind_param('is', $pid, $k);
            $pm->execute();
        }
        $platform->query("DELETE FROM plan_features WHERE plan_id = $pid");
        $pf = $platform->prepare("INSERT IGNORE INTO plan_features (plan_id, feature_slug) VALUES (?,?)");
        foreach ($feats as $slug) { $pf->bind_param('is', $pid, $slug); $pf->execute(); }
        $after = Plans::snapshot($pid);
        $vrow = $platform->query("SELECT version FROM plans WHERE id = $pid")->fetch_assoc();
        $note = trim($_POST['change_note'] ?? '');
        $sj = json_encode($after);
        $pv = $platform->prepare("INSERT INTO plan_versions (plan_id, version, snapshot_json, changed_by, change_note) VALUES (?,?,?,?,?)");
        $ver = intval($vrow['version']); $pv->bind_param('iisis', $pid, $ver, $sj, $SA['id'], $note); $pv->execute();
        Audit::log($before ? 'plan.updated' : 'plan.created', ['estate_id' => null, 'module' => 'plans', 'entity_type' => 'plan', 'entity_id' => $pid, 'details' => "Plan {$f['name']} v$ver. $note", 'before' => $before, 'after' => $after]);

        // optionally roll the new terms out to existing subscribers (otherwise they stay grandfathered)
        $applied = 0;
        if ($before && !empty($_POST['apply_existing'])) {
            $r = $platform->query("SELECT * FROM subscriptions WHERE plan_id = $pid AND status IN ('active','renewed','grace_period','past_due','pending_payment')");
            while ($s = $r->fetch_assoc()) {
                $old = json_decode($s['plan_snapshot_json'] ?? '', true); $addons = $old['addons'] ?? [];
                $snap = json_encode($after + ['addons' => $addons]);
                $u = $platform->prepare("UPDATE subscriptions SET plan_snapshot_json = ?, plan_version = ?, resident_limit = ? WHERE id = ?");
                $lim = intval($f['res']); $u->bind_param('siii', $snap, $ver, $lim, $s['id']); $u->execute();
                Entitlements::flush($s['estate_id']); Tenancy::mirror($s['estate_id']);
                Billing::event($s['estate_id'], $s['id'], 'plan_change', "Plan {$f['name']} terms updated by platform (v$ver)" . ($note ? ": $note" : ''));
                Audit::log('subscription.plan_terms_applied', ['estate_id' => $s['estate_id'], 'module' => 'billing', 'entity_type' => 'subscription', 'entity_id' => $s['id'], 'details' => "Plan {$f['name']} v$ver applied to existing subscription"]);
                $applied++;
            }
        }
        redirectWithFlash('plans', "Plan saved (v$ver)." . ($applied ? " New terms applied to $applied existing subscription(s)." : ' Existing subscribers keep their current terms.'));
    } catch (Throwable $ex) { redirectWithFlash('plans' . ($pid ?? 0 ? '?id=' . $pid : '?new=1'), null, $ex->getMessage()); }
}

require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';

if ($isNew || $editId):
    $plan = $editId ? Plans::get($editId) : ['id' => 0, 'name' => '', 'description' => '', 'monthly_price' => 0, 'annual_price' => 0, 'resident_limit' => 100, 'user_limit' => 10, 'storage_limit_mb' => 1024, 'support_level' => 'email', 'features' => '', 'is_featured' => 0, 'show_public' => 1, 'status' => 'active', 'sort_order' => 100, 'version' => 0];
    if (!$plan) { echo '<div class="alert alert-danger">Plan not found.</div>'; require __DIR__ . '/includes/super_footer.php'; exit; }
    $cur = []; $curFeat = [];
    if ($editId) foreach (Plans::modules($editId) as $m) { $cur[$m['module_key']] = $m['inclusion']; }
    if ($editId) $curFeat = array_flip(FeatureCatalog::planFeatures($editId));
    $grouped = FeatureCatalog::grouped();
    $groupedMods = [];
    foreach (FeatureCatalog::all() as $f) if ($f['module_key']) $groupedMods[$f['module_key']] = true;
    $subs = $editId ? intval($platform->query("SELECT COUNT(*) c FROM subscriptions WHERE plan_id = $editId AND status IN ('active','renewed','grace_period','past_due')")->fetch_assoc()['c']) : 0;
?>
<form method="post" class="row g-3">
 <input type="hidden" name="plan_id" value="<?= intval($plan['id']) ?>">
 <div class="col-lg-5"><div class="card-custom p-3">
   <h6 class="fw-bold"><?= $editId ? 'Edit plan (v' . intval($plan['version']) . ')' : 'New plan' ?></h6>
   <div class="mb-2"><label class="form-label small">Name</label><input class="form-control" name="name" required value="<?= e($plan['name']) ?>"></div>
   <div class="mb-2"><label class="form-label small">Description</label><input class="form-control" name="description" value="<?= e($plan['description']) ?>"></div>
   <div class="row g-2 mb-2"><div class="col-6"><label class="form-label small">Monthly price</label><input class="form-control" type="number" step="0.01" min="0" name="monthly_price" value="<?= e($plan['monthly_price']) ?>"></div>
     <div class="col-6"><label class="form-label small">Annual price</label><input class="form-control" type="number" step="0.01" min="0" name="annual_price" value="<?= e($plan['annual_price']) ?>"></div></div>
   <div class="row g-2 mb-2"><div class="col-4"><label class="form-label small">Resident limit</label><input class="form-control" type="number" min="0" name="resident_limit" value="<?= e($plan['resident_limit']) ?>"><div class="form-text">0 = unlimited</div></div>
     <div class="col-4"><label class="form-label small">User limit</label><input class="form-control" type="number" min="0" name="user_limit" value="<?= e($plan['user_limit']) ?>"></div>
     <div class="col-4"><label class="form-label small">Storage MB</label><input class="form-control" type="number" min="0" name="storage_limit_mb" value="<?= e($plan['storage_limit_mb']) ?>"></div></div>
   <div class="row g-2 mb-2"><div class="col-6"><label class="form-label small">Support level</label><select class="form-select" name="support_level"><?php foreach (['email', 'priority', 'dedicated', 'phone'] as $s): ?><option <?= $plan['support_level'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
     <div class="col-3"><label class="form-label small">Status</label><select class="form-select" name="status"><?php foreach (['active', 'inactive', 'archived'] as $s): ?><option <?= $plan['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
     <div class="col-3"><label class="form-label small">Order</label><input class="form-control" type="number" name="sort_order" value="<?= e($plan['sort_order']) ?>"></div></div>
   <div class="form-check"><input class="form-check-input" type="checkbox" name="show_public" id="sp" value="1" <?= $plan['show_public'] ? 'checked' : '' ?>><label class="form-check-label" for="sp">Show on public pricing page</label></div>
   <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_featured" id="ft" value="1" <?= $plan['is_featured'] ? 'checked' : '' ?>><label class="form-check-label" for="ft">Highlight as most popular</label></div>
   <div class="mb-2"><label class="form-label small">Change note</label><input class="form-control" name="change_note" placeholder="Recorded in plan history & audit log"></div>
   <?php if ($editId): ?><div class="alert alert-warning small mb-2"><strong><?= $subs ?></strong> active subscription(s) use this plan. By default they keep their current (grandfathered) terms.
     <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="apply_existing" id="ap" value="1"><label class="form-check-label" for="ap">Apply the new features, limits and price to existing subscribers now</label></div></div><?php endif; ?>
   <button class="btn btn-primary">Save plan</button> <a class="btn btn-light" href="plans">Cancel</a>
 </div></div>
 <div class="col-lg-7"><div class="card-custom p-3">
   <h6 class="fw-bold">Features in this plan</h6><p class="small text-secondary">Tick what this plan unlocks. The plan has one fixed price (left); features are not priced individually. Estates can only assign these features to their roles; everything else appears faint and disabled.</p>
   <?= render_feature_grid($grouped, ['name' => 'feat', 'checked' => $curFeat]) ?>
   <?php $others = array_filter($mods, fn($m) => empty($groupedMods[$m['module_key']])); if ($others): ?>
   <h6 class="fw-bold mt-4">Other platform modules</h6>
   <div class="row g-2"><?php foreach ($others as $m): ?><div class="col-md-6"><label class="d-flex gap-2 small"><input type="checkbox" class="form-check-input" name="mod[<?= e($m['module_key']) ?>]" value="1" <?= isset($cur[$m['module_key']]) ? 'checked' : '' ?>><span><?= e($m['name']) ?><span class="text-secondary"> · <?= e($m['category']) ?><?= $m['global_enabled'] ? '' : ' · globally disabled' ?></span></span></label></div><?php endforeach; ?></div>
   <?php endif; ?>
 </div></div>
</form>
<?php else: $plans = $platform->query("SELECT p.*, (SELECT COUNT(*) FROM subscriptions s WHERE s.plan_id = p.id AND s.status IN ('active','renewed','grace_period','past_due')) subs, (SELECT COUNT(*) FROM plan_features pf WHERE pf.plan_id = p.id) inc FROM plans p ORDER BY sort_order")->fetch_all(MYSQLI_ASSOC); ?>
<div class="d-flex justify-content-between mb-3"><p class="text-secondary mb-0 small">All prices, limits and module inclusion are configured here (one fixed price per plan, features chosen per plan) and feed the public pricing page and billing engine.</p><a href="plans?new=1" class="btn btn-primary btn-sm">New plan</a></div>
<div class="card-custom"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Plan</th><th>Monthly</th><th class="d-none d-md-table-cell">Annual</th><th>Residents</th><th class="d-none d-md-table-cell">Features</th><th>Estates</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($plans as $p): ?><tr><td class="fw-semibold"><?= e($p['name']) ?> <span class="text-secondary small">v<?= intval($p['version']) ?></span><div class="small text-secondary"><?= e($p['description']) ?></div></td>
 <td><?= sa_money($p['monthly_price']) ?></td><td class="d-none d-md-table-cell"><?= sa_money($p['annual_price']) ?></td><td><?= $p['resident_limit'] ? number_format($p['resident_limit']) : '∞' ?></td><td class="d-none d-md-table-cell"><?= intval($p['inc']) ?></td><td><?= intval($p['subs']) ?></td><td><?= sa_badge($p['status']) ?></td>
 <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="plans?id=<?= $p['id'] ?>">Edit</a>
   <?php if ($p['status'] === 'active'): ?><form method="post" class="d-inline"><input type="hidden" name="action" value="status"><input type="hidden" name="plan_id" value="<?= $p['id'] ?>"><input type="hidden" name="status" value="inactive"><button class="btn btn-sm btn-outline-secondary">Deactivate</button></form>
   <?php elseif ($p['status'] === 'inactive'): ?><form method="post" class="d-inline"><input type="hidden" name="action" value="status"><input type="hidden" name="plan_id" value="<?= $p['id'] ?>"><input type="hidden" name="status" value="active"><button class="btn btn-sm btn-outline-success">Activate</button></form><?php endif; ?>
   <?php if ($p['status'] !== 'archived'): ?><button class="btn btn-sm btn-outline-danger" onclick="saConfirm({action:'plans',title:'Archive plan',message:'Archived plans cannot be selected by new estates. Existing subscribers keep their terms.',phrase:<?= json_encode($p['name']) ?>,button:'Archive',fields:{action:'status',plan_id:'<?= $p['id'] ?>',status:'archived'}})">Archive</button><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
