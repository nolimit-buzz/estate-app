<?php
// superadmin/module_usage.php - View which estates are using a specific module
require_once '../config.php';
require_once 'includes/super_auth.php';
sa_require_role('superadmin');

$module_id = intval($_GET['module_id'] ?? 0);
$module = null;
$estates = [];
$error = '';

if ($module_id) {
    $stmt = platform_db()->prepare("SELECT * FROM modules WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $module_id);
    $stmt->execute();
    $module = $stmt->get_result()->fetch_assoc();

    if (!$module) {
        $error = 'Module not found.';
    } else {
        // Get estates using this module
        $query = platform_db()->prepare("
            SELECT DISTINCT e.id, e.name, e.domain_prefix, e.status, s.billing_cycle, s.period_end,
                   CASE
                       WHEN eao.id IS NOT NULL THEN 'add-on'
                       WHEN pm.id IS NOT NULL THEN 'plan-included'
                       WHEN eeo.id IS NOT NULL AND eeo.enabled = 1 THEN 'override-enabled'
                       WHEN eeo.id IS NOT NULL AND eeo.enabled = 0 THEN 'override-disabled'
                       ELSE 'unknown'
                   END as module_source
            FROM estates e
            LEFT JOIN subscriptions s ON e.id = s.estate_id AND s.status IN ('active','renewed','grace_period','past_due','suspended')
            LEFT JOIN estate_addon_modules eao ON e.id = eao.estate_id AND eao.module_id = ?
            LEFT JOIN plan_modules pm ON s.plan_id = pm.plan_id AND pm.module_id = ?
            LEFT JOIN estate_module_overrides eeo ON e.id = eeo.estate_id AND eeo.module_id = ?
            WHERE e.status NOT IN ('deleted','archived')
              AND (eao.id IS NOT NULL OR pm.id IS NOT NULL OR eeo.id IS NOT NULL)
            ORDER BY e.status DESC, e.name ASC
        ");
        $stmt->bind_param('iii', $module_id, $module_id, $module_id);
        $stmt->execute();
        $estates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

include 'includes/super_header.php';
?>

<div class="page-header mb-5">
    <h1 class="page-title">Module Usage: <?php echo htmlspecialchars($module['name'] ?? ''); ?></h1>
    <p class="page-subtitle">Estates using this module across all subscription tiers.</p>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?> <a href="modules.php" class="alert-link">← Back to Modules</a></div>
<?php elseif (!$module_id): ?>
    <div class="alert alert-info">Select a module to view usage statistics. <a href="modules.php" class="alert-link">Go to Modules →</a></div>
<?php else: ?>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="text-muted mb-1">Total Estates Using</h6>
                <h2 class="mb-0"><?php echo count($estates); ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="text-muted mb-1">Active Estates</h6>
                <h2 class="mb-0"><?php echo count(array_filter($estates, fn($e) => $e['status'] === 'active')); ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="text-muted mb-1">Module Global Status</h6>
                <h4 class="mb-0">
                    <?php if ($module['global_enabled']): ?>
                        <span class="badge bg-success">Enabled</span>
                    <?php else: ?>
                        <span class="badge bg-danger">Disabled</span>
                    <?php endif; ?>
                </h4>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-light">
        <h5 class="mb-0">Estates Using This Module</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Estate</th>
                        <th>Status</th>
                        <th>Module Source</th>
                        <th>Subscription</th>
                        <th>Expires</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($estates as $e): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($e['name']); ?></strong>
                            <div class="small text-muted"><?php echo htmlspecialchars($e['domain_prefix']); ?></div>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $e['status'] === 'active' ? 'success' : 'warning'; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $e['status'])); ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            $source = $e['module_source'];
                            $badge_class = match($source) {
                                'add-on' => 'info',
                                'plan-included' => 'success',
                                'override-enabled' => 'warning',
                                'override-disabled' => 'secondary',
                                default => 'dark'
                            };
                            $label = match($source) {
                                'add-on' => 'Add-on (Purchased)',
                                'plan-included' => 'Plan (Included)',
                                'override-enabled' => 'Override (Enabled)',
                                'override-disabled' => 'Override (Disabled)',
                                default => 'Unknown'
                            };
                            ?>
                            <span class="badge bg-<?php echo $badge_class; ?>"><?php echo $label; ?></span>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($e['billing_cycle'] ?? 'No subscription'); ?>
                        </td>
                        <td class="small text-muted">
                            <?php if ($e['period_end']): ?>
                                <?php echo date('j M Y', strtotime($e['period_end'])); ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a href="estate.php?id=<?php echo $e['id']; ?>" class="btn btn-sm btn-outline-primary">
                                View Estate
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($estates)): ?>
                    <tr>
                        <td colspan="6" class="text-muted text-center py-4">
                            No estates are using this module.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<div class="mt-4">
    <a href="modules.php" class="btn btn-outline-secondary">← Back to Modules</a>
</div>

<?php include 'includes/super_footer.php'; ?>
