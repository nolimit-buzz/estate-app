<?php
// superadmin/backups.php - backups across estates; restore is locked to the backup's own estate
$page_title = 'Backups';
require_once __DIR__ . '/includes/super_header.php';
sa_require_role(['superadmin']);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $a = $_POST['action'] ?? '';
        if ($a === 'restore') {
            $e = Tenancy::find(intval($_POST['estate_id']));
            if (!$e || !sa_confirmed('RESTORE ' . $e['domain_prefix'])) throw new RuntimeException('Confirmation phrase did not match.');
            BackupService::restore(intval($_POST['backup_id']), $e['id'], $SA['id']);
            redirectWithFlash('backups', 'Estate restored from backup. A safety backup of the previous state was taken first.');
        } elseif ($a === 'backup') { $b = BackupService::backup(intval($_POST['estate_id']), 'manual', $SA['id']); redirectWithFlash('backups', 'Backup created.'); }
        elseif ($a === 'prune') { $n = BackupService::prune(); redirectWithFlash('backups', "$n old backup(s) removed."); }
    } catch (Throwable $ex) { redirectWithFlash('backups', null, $ex->getMessage()); }
}
[$off, $lim, $pg] = sa_page(40);
$total = intval($platform->query("SELECT COUNT(*) c FROM backups")->fetch_assoc()['c']);
$res = $platform->query("SELECT b.*, e.name estate_name, e.domain_prefix, e.status estate_status FROM backups b JOIN estates e ON e.id = b.estate_id ORDER BY b.id DESC LIMIT $lim OFFSET $off");
$estates = $platform->query("SELECT id, name FROM estates WHERE db_name IS NOT NULL AND status NOT IN ('deleted','archived') ORDER BY name")->fetch_all(MYSQLI_ASSOC);
require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';
?>
<div class="card-custom p-3 mb-3 d-flex flex-wrap gap-2 align-items-end">
 <form method="post" class="d-flex gap-2"><input type="hidden" name="action" value="backup"><select name="estate_id" class="form-select form-select-sm"><?php foreach ($estates as $x): ?><option value="<?= $x['id'] ?>"><?= e($x['name']) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-primary text-nowrap">Back up now</button></form>
 <form method="post" class="ms-auto"><button class="btn btn-sm btn-outline-secondary" name="action" value="prune">Prune expired (<?= PlatformSettings::int('backup_retention_days', 30) ?> d)</button></form></div>
<div class="alert alert-info small">Each backup is bound to one estate (checksum + embedded estate id). The restore tool refuses to load a backup into any other estate. Nightly backups run from <code>cron/run.php</code>.</div>
<div class="card-custom"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Created</th><th>Estate</th><th>Kind</th><th>Size</th><th>Status</th><th></th></tr></thead><tbody>
<?php while ($b = $res->fetch_assoc()): ?><tr><td class="small text-nowrap"><?= sa_dt($b['created_at']) ?></td><td class="small"><a href="estate?id=<?= $b['estate_id'] ?>"><?= e($b['estate_name']) ?></a></td><td><?= e($b['kind']) ?></td><td class="small"><?= round($b['size_bytes'] / 1024) ?> KB</td><td><?= sa_badge($b['status']) ?></td>
 <td class="text-end"><?php if ($b['status'] === 'ok' && !in_array($b['estate_status'], ['deleted', 'archived'])): ?><button class="btn btn-sm btn-outline-danger" onclick="saConfirm({action:'backups',title:'Restore backup #<?= $b['id'] ?> into <?= e($b['estate_name']) ?>',message:'Replaces the estate database with this backup. A safety backup is taken first.',phrase:<?= json_encode('RESTORE ' . $b['domain_prefix']) ?>,button:'Restore',fields:{action:'restore',backup_id:'<?= $b['id'] ?>',estate_id:'<?= $b['estate_id'] ?>'}})">Restore</button><?php endif; ?></td></tr><?php endwhile; ?></tbody></table></div></div>
<?= sa_pager($total, $lim, $pg) ?>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
