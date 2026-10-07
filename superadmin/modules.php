<?php
// superadmin/modules.php - Global Tenant Module Feature Matrix
$page_title = "Global Module Feature Matrix";
require_once 'includes/super_header.php';
require_once 'includes/super_sidebar.php';
require_once 'includes/super_topbar.php';

$platformModules = getAllPlatformModules();

// Handle bulk enable/disable
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $target_eid = intval($_POST['estate_id'] ?? 0);
    $action = $_POST['bulk_action']; // 'enable_all' or 'disable_all'
    $newVal = ($action === 'enable_all') ? 1 : 0;
    
    if ($target_eid > 0) {
        foreach ($platformModules as $mKey => $mMeta) {
            $conn->query("INSERT INTO estate_modules (estate_id, module_key, is_enabled) 
                          VALUES ($target_eid, '$mKey', $newVal) 
                          ON DUPLICATE KEY UPDATE is_enabled = $newVal");
        }
        $eName = $conn->query("SELECT name FROM estates WHERE id = $target_eid")->fetch_assoc()['name'] ?? "Estate #$target_eid";
        logAudit($conn, "Modules Bulk Update", "SaaS Feature Flags", "Super Admin set all modules to $action for '$eName'.");
        setFlashMessage('success', "All modules have been " . ($newVal ? "activated" : "deactivated") . " for '$eName'.");
        header("Location: modules");
        exit;
    }
}

// Fetch all estates
$estates = [];
$eRes = $conn->query("SELECT id, name, domain_prefix, plan, status FROM estates ORDER BY id ASC");
if ($eRes) {
    while ($r = $eRes->fetch_assoc()) $estates[] = $r;
}

// Fetch all module states into an indexed lookup map [estate_id][module_key] => bool
$moduleMap = [];
$mRes = $conn->query("SELECT estate_id, module_key, is_enabled FROM estate_modules");
if ($mRes) {
    while ($mr = $mRes->fetch_assoc()) {
        $moduleMap[$mr['estate_id']][$mr['module_key']] = (intval($mr['is_enabled']) === 1);
    }
}
?>

<!-- BANNER -->
<div class="p-4 rounded-4 mb-4 text-white d-flex flex-wrap align-items-center justify-content-between gap-3 shadow-sm" style="background: linear-gradient(135deg, #0b1120 0%, #1e293b 100%); border: 1px solid rgba(255,255,255,0.08);">
    <div>
        <div class="badge bg-primary bg-opacity-25 text-white border border-primary border-opacity-50 rounded-pill px-3 py-1 mb-2 font-monospace" style="font-size: 0.72rem;">PER-TENANT FEATURE GATES</div>
        <h4 class="fw-bold mb-1">Global Module Feature Matrix</h4>
        <p class="small mb-0" style="color: #94a3b8; max-width: 650px;">
            Deactivating a module immediately hides it from the tenant's sidebar navigation and intercepts direct route attempts with an informative restricted access banner.
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="index" class="btn btn-outline-light rounded-pill px-3 py-2 fw-semibold">
            <i class="fa-solid fa-arrow-left me-1"></i> Command Center
        </a>
    </div>
</div>

<!-- MODULE LEGEND CARDS -->
<div class="card-custom p-4 mb-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h6 class="fw-bold text-slate-800 mb-0">Platform Module Catalog (<?= count($platformModules) ?> Modules)</h6>
        <small class="text-secondary">Changes take effect immediately across all active tenant sessions.</small>
    </div>
    <div class="row g-2">
        <?php foreach ($platformModules as $k => $m): ?>
            <div class="col-6 col-md-4 col-xl-2.4" style="flex: 0 0 20%; max-width: 20%;">
                <div class="p-2.5 bg-light rounded-3 border h-100 d-flex align-items-center gap-2">
                    <div style="width: 28px; height: 28px; border-radius: 8px; background: #e2e8f0; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; color: #475569; flex-shrink: 0;">
                        <i class="fa-solid <?= $m['icon'] ?>"></i>
                    </div>
                    <div class="text-truncate">
                        <div class="small fw-bold text-slate-800 text-truncate" style="font-size: 0.75rem;"><?= $m['name'] ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- GLOBAL MATRIX TABLE -->
<div class="card-custom mb-5">
    <div class="p-3 px-4 bg-white border-bottom d-flex align-items-center justify-content-between">
        <div>
            <h5 class="fw-bold text-slate-800 mb-0">Tenant Module Switchboard</h5>
            <small class="text-secondary">Toggle features for specific estates in real-time.</small>
        </div>
        <div class="small text-secondary font-monospace">
            <i class="fa-solid fa-bolt text-warning me-1"></i> Real-time AJAX persistence active
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 text-center" style="font-size: 0.82rem;">
            <thead class="table-light small fw-bold text-uppercase text-secondary" style="font-size: 0.7rem;">
                <tr>
                    <th class="text-start ps-4" style="min-width: 220px;">Tenant Estate</th>
                    <?php foreach ($platformModules as $k => $m): ?>
                        <th title="<?= htmlspecialchars($m['desc']) ?>" style="min-width: 110px;">
                            <i class="fa-solid <?= $m['icon'] ?> text-secondary d-block mb-1" style="font-size: 0.95rem;"></i>
                            <span><?= htmlspecialchars(explode(' ', $m['name'])[0]) ?></span>
                        </th>
                    <?php endforeach; ?>
                    <th class="text-end pe-4" style="min-width: 140px;">Bulk Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($estates as $es): 
                    $eid = intval($es['id']);
                ?>
                <tr>
                    <td class="text-start ps-4">
                        <div class="fw-bold text-slate-800"><?= htmlspecialchars($es['name']) ?></div>
                        <div class="small text-secondary font-monospace" style="font-size: 0.7rem;">
                            #<?= $eid ?> &bull; <?= htmlspecialchars($es['domain_prefix']) ?>.estateapp.com &bull; 
                            <span class="badge bg-light text-dark border"><?= strtoupper($es['plan']) ?></span>
                        </div>
                    </td>

                    <?php foreach ($platformModules as $mKey => $mMeta): 
                        // Default is true if not explicitly disabled
                        $isEnabled = isset($moduleMap[$eid][$mKey]) ? $moduleMap[$eid][$mKey] : true;
                    ?>
                        <td>
                            <div class="form-check form-switch d-inline-block m-0">
                                <input class="form-check-input matrix-switch" 
                                       type="checkbox" 
                                       role="switch" 
                                       id="sw_<?= $eid ?>_<?= $mKey ?>"
                                       <?= $isEnabled ? 'checked' : '' ?>
                                       onchange="toggleMatrixModule(<?= $eid ?>, '<?= $mKey ?>', this.checked)"
                                       style="cursor: pointer; width: 2.1rem; height: 1.15rem;"
                                       title="Toggle <?= htmlspecialchars($mMeta['name']) ?> for <?= htmlspecialchars($es['name']) ?>">
                            </div>
                        </td>
                    <?php endforeach; ?>

                    <td class="text-end pe-4">
                        <form method="POST" class="d-inline-flex gap-1">
                            <input type="hidden" name="estate_id" value="<?= $eid ?>">
                            <button type="submit" name="bulk_action" value="enable_all" class="btn btn-xs btn-outline-success rounded-pill px-2 py-0.5" style="font-size: 0.68rem;" title="Enable All Modules">
                                All On
                            </button>
                            <button type="submit" name="bulk_action" value="disable_all" class="btn btn-xs btn-outline-danger rounded-pill px-2 py-0.5" style="font-size: 0.68rem;" title="Disable All Modules">
                                All Off
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function toggleMatrixModule(estateId, moduleKey, isChecked) {
    const formData = new FormData();
    formData.append('estate_id', estateId);
    formData.append('module_key', moduleKey);
    formData.append('is_enabled', isChecked ? '1' : '0');

    fetch('ajax_toggle_module', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            showSuperToast(res.message, true);
        } else {
            showSuperToast('Error: ' + res.error, false);
            const sw = document.getElementById(`sw_${estateId}_${moduleKey}`);
            if (sw) sw.checked = !isChecked;
        }
    })
    .catch(err => {
        showSuperToast('Network error: ' + err.message, false);
        const sw = document.getElementById(`sw_${estateId}_${moduleKey}`);
        if (sw) sw.checked = !isChecked;
    });
}
</script>

<?php require_once 'includes/super_footer.php'; ?>
