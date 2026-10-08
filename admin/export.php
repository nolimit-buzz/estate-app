<?php
// admin/export.php - Central Admin downloads their own estate's data (also available while suspended / in retention)
require_once '../config.php';
require_once '../includes/auth_guard.php';
requireAdminAccess();
require_once '../includes/BackupService.php';

$eid = Tenancy::currentId();
$p = Tenancy::platform();
if (!in_array($_SESSION['role'] ?? '', ['admin'], true) && !is_impersonating_estate()) { header('Location: index'); exit; }
$err = '';
if (isset($_GET['download'])) {
    $row = $p->query("SELECT * FROM data_exports WHERE id = " . intval($_GET['download']) . " AND estate_id = $eid AND status = 'ready'")->fetch_assoc();   // own exports only
    $path = $row ? dirname(__DIR__) . '/' . $row['file_path'] : '';
    if (!$row || !is_file($path)) { http_response_code(404); exit('Export not found or expired.'); }
    logAudit($conn, 'Data export downloaded', 'Export', 'Export #' . $row['id'] . ' downloaded');
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/zip'); header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="estate-export-' . date('Ymd', strtotime($row['created_at'])) . '.zip"');
    readfile($path); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!PlatformSettings::bool('export_enabled', true)) throw new RuntimeException('Data export is currently disabled by the platform.');
        require_once '../includes/RateLimit.php';
        if (RateLimit::lockedFor('export', (string)$eid) > 0) throw new RuntimeException('Please wait a few minutes before requesting another export.');
        RateLimit::hit('export', (string)$eid, 3, 30, 60);
        $u = $conn->query("SELECT name FROM users WHERE id = " . intval($_SESSION['user_id']))->fetch_assoc();
        BackupService::exportEstate($eid, $_SESSION['user_id'], $u['name'] ?? null);
        redirectWithFlash('export', 'Your export is ready to download below.');
    } catch (Throwable $e) { $err = $e->getMessage(); }
}
$list = $p->query("SELECT * FROM data_exports WHERE estate_id = $eid AND status = 'ready' ORDER BY id DESC LIMIT 20")->fetch_all(MYSQLI_ASSOC);
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="page-header-futuristic mb-4"><div><h1 class="page-title">Data export</h1><p class="page-subtitle">Download a copy of all your estate's records (one CSV per table) before cancelling or if you ever need your own backup.</p></div></div>
<?php if ($err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body"><form method="post" class="d-flex flex-wrap align-items-center gap-3"><button class="btn btn-primary">Generate new export</button><span class="small text-muted">Passwords, security tokens and payment-gateway secrets are never included. Exports are for your estate only.</span></form></div></div>
<div class="card border-0 shadow-sm mb-5"><div class="card-body"><h6 class="fw-bold">Your exports</h6><div class="table-responsive"><table class="table table-sm align-middle mb-0"><tbody>
<?php foreach ($list as $x): ?><tr><td><?php echo date('j M Y H:i', strtotime($x['created_at'])); ?></td><td class="small text-muted"><?php echo htmlspecialchars($x['requested_by_name'] ?? ''); ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="export?download=<?php echo $x['id']; ?>">Download</a></td></tr><?php endforeach; if (!$list) echo '<tr><td class="text-muted">No exports yet.</td></tr>'; ?></tbody></table></div></div></div>
<?php include '../includes/footer.php'; ?>
