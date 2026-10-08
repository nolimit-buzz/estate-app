<?php
// suspended.php - shown to every user of a suspended estate. Central Admins get a path to renew / export.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_layout.php';

$estate = Tenancy::hasTenant() ? Tenancy::find(Tenancy::currentId()) : null;
if (!$estate) { header('Location: login'); exit; }
if (in_array($estate['status'], ['active', 'trial', 'grace_period', 'onboarding', 'pending'], true)) { header('Location: ' . (($_SESSION['role'] ?? '') === 'resident' ? 'resident/index' : 'admin/index')); exit; }
$isAdmin = in_array($_SESSION['role'] ?? '', ['admin'], true);
$left = $estate['retention_ends_at'] ? max(0, (int)ceil((strtotime($estate['retention_ends_at']) - time()) / 86400)) : null;
$deleting = $estate['status'] === 'deletion_pending';
public_head('Service suspended - ' . $estate['name']);
?>
<div class="auth-shell"><div></div><main class="auth-main"><div class="form-card narrow" style="text-align:center">
    <span class="eyebrow"><?php echo pl_e($estate['name']); ?></span>
    <h1><?php echo $deleting ? 'Estate scheduled for deletion' : 'Subscription suspended'; ?></h1>
    <?php if ($isAdmin): ?>
        <p>The subscription payment for <strong><?php echo pl_e($estate['name']); ?></strong> was not received, so access for your residents and staff is paused.</p>
        <?php if (!$deleting && $left !== null): ?><div class="alert info"><strong>Your data is safe.</strong> It is preserved for <strong><?php echo $left; ?> more day(s)</strong> (until <?php echo pl_e(date('j M Y', strtotime($estate['retention_ends_at']))); ?>). Renew at any time before then and everything is restored exactly as you left it.</div><?php endif; ?>
        <?php if ($deleting): ?><div class="alert err">The retention period has ended. Unless you renew immediately, your estate's data will be permanently deleted after the final notice period. Contact support urgently.</div><?php endif; ?>
        <a class="btn btn-primary btn-block" href="admin/subscription">Renew / reactivate now</a>
        <a class="btn btn-ghost btn-block" style="margin-top:10px" href="admin/export">Export my data</a>
        <a class="btn btn-ghost btn-block" style="margin-top:10px" href="admin/support">Contact support</a>
    <?php else: ?>
        <p>This estate's portal is temporarily unavailable. Please contact your estate's Central Administration.</p>
        <?php if ($estate['contact_phone'] || $estate['contact_email']): ?><p class="muted"><?php echo pl_e(trim($estate['contact_phone'] . ' ' . $estate['contact_email'])); ?></p><?php endif; ?>
    <?php endif; ?>
    <p style="margin-top:18px"><a href="logout">Sign out</a></p>
</div></main></div></body></html>
