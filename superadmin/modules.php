<?php
// superadmin/modules.php - Global module management: catalogue, default price, global on/off, plan membership, overrides
$page_title = 'Modules';
require_once __DIR__ . '/includes/super_header.php';
sa_require_role(['superadmin']);

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
        $price = max(0, round(floatval($_POST['default_price'] ?? 0), 2));
        $nm = trim($_POST['name']); $cat = trim($_POST['category']); $de = trim($_POST['description']); $pu = trim($_POST['purpose']);
        $caps = implode('|', array_filter(array_map('trim', preg_split('/\R/', (string)$_POST['capabilities']))));
        $sp = !empty($_POST['show_public']) ? 1 : 0; $so = intval($_POST['sort_order']);
    } catch (Throwable $ex) { redirectWithFlash('modules', null, $ex->getMessage()); }
    try {
        $st = $platform->prepare("UPDATE modules SET name=?, category=?, description=?, purpose=?, capabilities=?, default_price=?, show_public=?, sort_order=? WHERE module_key=?");
        $st->bind_param('sssssdiis', $nm, $cat, $de, $pu, $caps, $price, $sp, $so, $k);
        $st->execute();
        Audit::log('module.updated', ['estate_id' => null, 'module' => 'modules', 'entity_type' => 'module', 'entity_id' => $k, 'details' => "Module $k details/price updated" . ($m['default_price'] != $price ? " (price {$m['default_price']} -> $price)" : ''), 'before' => $m, 'after' => ['name' => $nm, 'default_price' => $price, 'show_public' => $sp]]);
        redirectWithFlash('modules', "$nm updated.");
    } catch (Throwable $ex) { redirectWithFlash('modules', null, $ex->getMessage()); }
}

require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';
$rows = $platform->query("SELECT m.*, (SELECT GROUP_CONCAT(p.name ORDER BY p.sort_order SEPARATOR ', ') FROM plan_modules pm JOIN plans p ON p.id = pm.plan_id WHERE pm.module_key = m.module_key AND pm.inclusion='included') plans_in,
  (SELECT COUNT(*) FROM estate_module_overrides o WHERE o.module_key = m.module_key) overrides FROM modules m ORDER BY m.sort_order")->fetch_all(MYSQLI_ASSOC);
?>
<p class="text-secondary small">Access order: <strong>global status</strong> → <strong>estate override</strong> → <strong>plan</strong> (+add-ons). A module switched off here is unavailable to every estate regardless of plan or override.</p>
<div class="card-custom"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Module</th><th class="d-none d-md-table-cell">Included in plans</th><th>Price / mo</th><th class="d-none d-md-table-cell">Overrides</th><th>Global</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $m): ?>
<tr><td class="fw-semibold"><?= e($m['name']) ?><div class="small text-secondary"><code><?= e($m['module_key']) ?></code> · <?= e($m['category']) ?><?= $m['is_core'] ? ' · core' : '' ?></div></td>
 <td class="d-none d-md-table-cell small"><?= e($m['plans_in'] ?: '—') ?></td><td><?= sa_money($m['default_price']) ?></td><td class="d-none d-md-table-cell"><?= intval($m['overrides']) ?></td>
 <td><?= $m['global_enabled'] ? sa_badge('on') : sa_badge('off') ?></td>
 <td class="text-end text-nowrap"><button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#m_<?= e($m['module_key']) ?>">Edit</button>
   <button class="btn btn-sm btn-outline-<?= $m['global_enabled'] ? 'danger' : 'success' ?>" onclick="saConfirm({action:'modules',title:'<?= $m['global_enabled'] ? 'Disable' : 'Enable' ?> module globally',message:'<?= $m['global_enabled'] ? 'Every estate loses access immediately, even if its plan includes it.' : 'The module becomes available again according to plans and overrides.' ?>',phrase:<?= json_encode($m['module_key']) ?>,button:'<?= $m['global_enabled'] ? 'Disable' : 'Enable' ?> for all',fields:{action:'toggle',module_key:<?= json_encode($m['module_key']) ?>}})"><?= $m['global_enabled'] ? 'Turn off' : 'Turn on' ?></button></td></tr>
<tr class="collapse" id="m_<?= e($m['module_key']) ?>"><td colspan="6" class="bg-light"><form method="post" class="row g-2 p-2"><input type="hidden" name="action" value="save"><input type="hidden" name="module_key" value="<?= e($m['module_key']) ?>">
  <div class="col-md-5"><label class="form-label small mb-0">Name</label><input class="form-control form-control-sm" name="name" value="<?= e($m['name']) ?>" required></div>
  <div class="col-md-3"><label class="form-label small mb-0">Category</label><input class="form-control form-control-sm" name="category" value="<?= e($m['category']) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0">Indicative price / mo</label><input class="form-control form-control-sm" type="number" step="0.01" min="0" name="default_price" value="<?= e($m['default_price']) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0">Order</label><input class="form-control form-control-sm" type="number" name="sort_order" value="<?= e($m['sort_order']) ?>"></div>
  <div class="col-12"><label class="form-label small mb-0">Short description</label><input class="form-control form-control-sm" name="description" value="<?= e($m['description']) ?>"></div>
  <div class="col-12"><label class="form-label small mb-0">Purpose</label><input class="form-control form-control-sm" name="purpose" value="<?= e($m['purpose']) ?>"></div>
  <div class="col-12"><label class="form-label small mb-0">Key capabilities (one per line)</label><textarea class="form-control form-control-sm" rows="3" name="capabilities"><?= e(str_replace('|', "\n", $m['capabilities'])) ?></textarea></div>
  <div class="col-12 d-flex align-items-center gap-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="show_public" value="1" id="sp_<?= e($m['module_key']) ?>" <?= $m['show_public'] ? 'checked' : '' ?>><label class="form-check-label small" for="sp_<?= e($m['module_key']) ?>">Show on landing page</label></div><button class="btn btn-sm btn-primary">Save</button></div>
</form></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
