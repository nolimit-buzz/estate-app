<?php
// superadmin/settings.php - platform rules (grace, retention, OTP, invitations, security, notifications, site content). No code changes needed.
$page_title = 'System settings';
require_once __DIR__ . '/includes/super_header.php';
sa_require_role(['superadmin']);

$rows = PlatformSettings::all();
$byKey = array_column($rows, null, 'setting_key');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $changes = [];
    foreach ($_POST['s'] ?? [] as $k => $v) {
        if (!isset($byKey[$k])) continue;
        $d = $byKey[$k]; $v = trim((string)$v);
        switch ($d['value_type']) {
            case 'int': if (!preg_match('/^-?\d+$/', $v)) { redirectWithFlash('settings', null, "{$d['label']} must be a whole number."); } if (intval($v) < 0) { redirectWithFlash('settings', null, "{$d['label']} cannot be negative."); } break;
            case 'decimal': if (!is_numeric($v)) redirectWithFlash('settings', null, "{$d['label']} must be a number."); break;
            case 'csv': if ($d['group_name'] !== 'site' && !preg_match('/^\s*\d+(\s*,\s*\d+)*\s*$|^\s*$/', $v)) redirectWithFlash('settings', null, "{$d['label']} must be comma-separated numbers."); break;
        }
        if ($d['setting_key'] === 'grace_period_days' && intval($v) > 90) redirectWithFlash('settings', null, 'Grace period over 90 days is not allowed.');
        if ($d['setting_key'] === 'retention_days' && intval($v) < 1) redirectWithFlash('settings', null, 'Retention must be at least 1 day.');
        if (($d['value_type'] === 'bool') ) $v = $v ? '1' : '0';
        if ((string)$d['setting_value'] !== $v) $changes[$k] = [$d['setting_value'], $v];
    }
    foreach ($byKey as $k => $d) if ($d['value_type'] === 'bool' && !isset($_POST['s'][$k]) && $d['setting_value'] === '1' && isset($_POST['__bools'][$k])) $changes[$k] = ['1', '0'];
    foreach ($changes as $k => [$old, $new]) PlatformSettings::set($k, $new, $SA['id']);
    if ($changes) Audit::log('settings.updated', ['estate_id' => null, 'module' => 'settings', 'details' => count($changes) . ' setting(s) changed: ' . implode(', ', array_keys($changes)), 'before' => array_map(fn($c) => $c[0], $changes), 'after' => array_map(fn($c) => $c[1], $changes)]);
    redirectWithFlash('settings', $changes ? count($changes) . ' setting(s) saved.' : 'Nothing changed.');
}
require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';
$groups = [];
foreach ($rows as $r) $groups[$r['group_name']][] = $r;
$gl = ['billing' => 'Billing & grace', 'lifecycle' => 'Lifecycle & retention', 'security' => 'Security', 'notifications' => 'Notifications', 'backup' => 'Backup & export', 'site' => 'Public website & company', 'general' => 'General'];
?>
<form method="post">
<ul class="nav nav-pills mb-3 flex-nowrap overflow-auto"><?php $first = true; foreach ($groups as $g => $_): ?><li class="nav-item"><button type="button" class="nav-link py-1 <?= $first ? 'active' : '' ?>" data-bs-toggle="pill" data-bs-target="#g_<?= e($g) ?>"><?= e($gl[$g] ?? ucfirst($g)) ?></button></li><?php $first = false; endforeach; ?></ul>
<div class="tab-content">
<?php $first = true; foreach ($groups as $g => $items): ?>
<div class="tab-pane fade <?= $first ? 'show active' : '' ?>" id="g_<?= e($g) ?>"><div class="card-custom p-3">
  <?php foreach ($items as $s): $k = $s['setting_key']; ?>
  <div class="row g-2 py-2 border-bottom align-items-center">
    <div class="col-md-5"><div class="fw-semibold small"><?= e($s['label']) ?></div><div class="text-secondary" style="font-size:.75rem"><?= e($s['description']) ?></div></div>
    <div class="col-md-7">
    <?php if ($s['value_type'] === 'bool'): ?><input type="hidden" name="__bools[<?= e($k) ?>]" value="1"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="s[<?= e($k) ?>]" value="1" <?= $s['setting_value'] === '1' ? 'checked' : '' ?>></div>
    <?php elseif ($s['value_type'] === 'text'): ?><textarea class="form-control form-control-sm" rows="3" name="s[<?= e($k) ?>]"><?= e($s['setting_value']) ?></textarea>
    <?php else: ?><input class="form-control form-control-sm" name="s[<?= e($k) ?>]" value="<?= e($s['setting_value']) ?>" <?= $s['value_type'] === 'int' ? 'inputmode="numeric"' : '' ?>><?php endif; ?>
    </div></div>
  <?php endforeach; ?>
</div></div>
<?php $first = false; endforeach; ?>
</div>
<div class="mt-3"><button class="btn btn-primary">Save settings</button> <span class="text-secondary small ms-2">Changes apply immediately and are written to the audit log.</span></div>
</form>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
