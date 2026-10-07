<?php
// superadmin/index.php - Central SaaS Command Center & Multi-Tenant Control Panel
$page_title = "SaaS Command Center & Tenant Governance";
require_once 'includes/super_header.php';
require_once 'includes/super_sidebar.php';
require_once 'includes/super_topbar.php';

$message = getFlashMessage('success') ?? '';
$error   = getFlashMessage('error') ?? '';

if (isset($_GET['success'])) {
    $message = "Estate operation completed successfully!";
}

// Handling estate deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_estate'])) {
    $del_id = intval($_POST['estate_id'] ?? 0);
    if ($del_id > 1) { // Protect default estate ID 1 from accidental deletion
        $eName = $conn->query("SELECT name FROM estates WHERE id = $del_id")->fetch_assoc()['name'] ?? "Estate #$del_id";
        if ($conn->query("DELETE FROM estates WHERE id = $del_id")) {
            logAudit($conn, "Estate Deleted", "SaaS Management", "Super Admin deleted estate '$eName' (ID: $del_id).");
            $message = "Estate '$eName' and associated resources were removed.";
        } else {
            $error = "Failed to delete estate: " . $conn->error;
        }
    } else {
        $error = "The primary root estate (#1) cannot be deleted.";
    }
}

// Global SaaS Statistics
$total_estates = 0;
$active_estates = 0;
$suspended_estates = 0;
$trial_estates = 0;
$total_residents = 0;
$total_units = 0;
$total_passes = 0;

$e_stats = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_cnt,
    SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_cnt,
    SUM(CASE WHEN status = 'trial' THEN 1 ELSE 0 END) as trial_cnt
FROM estates")->fetch_assoc();

if ($e_stats) {
    $total_estates = intval($e_stats['total']);
    $active_estates = intval($e_stats['active_cnt']);
    $suspended_estates = intval($e_stats['suspended_cnt']);
    $trial_estates = intval($e_stats['trial_cnt']);
}

$r_stat = $conn->query("SELECT COUNT(*) as c FROM residents WHERE status = 'active'")->fetch_assoc();
if ($r_stat) $total_residents = intval($r_stat['c']);

$f_stat = $conn->query("SELECT COUNT(*) as c FROM flats")->fetch_assoc();
if ($f_stat) $total_units = intval($f_stat['c']);

$p_stat = $conn->query("SELECT COUNT(*) as c FROM visitors")->fetch_assoc();
if ($p_stat) $total_passes = intval($p_stat['c']);
?>

<!-- FLASH ALERTS -->
<?php if (!empty($message)): ?>
    <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0 d-flex align-items-center mb-4" role="alert">
        <i class="fa-solid fa-circle-check fs-5 me-2 text-success"></i>
        <div><?= htmlspecialchars($message) ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm border-0 d-flex align-items-center mb-4" role="alert">
        <i class="fa-solid fa-circle-exclamation fs-5 me-2 text-danger"></i>
        <div><?= htmlspecialchars($error) ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- SAAS PLATFORM BANNER -->
<div class="p-4 rounded-4 mb-4 text-white d-flex flex-wrap align-items-center justify-content-between gap-3 shadow-sm" style="background: linear-gradient(135deg, #0b1120 0%, #1e293b 100%); border: 1px solid rgba(255,255,255,0.08);">
    <div>
        <div class="badge bg-primary bg-opacity-25 text-white border border-primary border-opacity-50 rounded-pill px-3 py-1 mb-2 font-monospace" style="font-size: 0.72rem;">MULTI-TENANT SAAS GOVERNANCE</div>
        <h4 class="fw-bold mb-1">EstateHQ Central SaaS Command Tower</h4>
        <p class="small mb-0" style="color: #94a3b8; max-width: 650px;">
            Full operational control over tenant estates, module feature kill-switches, capacity quotas, and Africa's Talking telecom gateway.
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="create_estate" class="btn btn-primary rounded-pill px-4 py-2 fw-bold shadow-sm d-flex align-items-center gap-2">
            <i class="fa-solid fa-rocket"></i>
            <span>Provision New Estate</span>
        </a>
        <a href="modules" class="btn btn-outline-light rounded-pill px-3 py-2 fw-semibold">
            <i class="fa-solid fa-sliders me-1"></i> Module Matrix
        </a>
        <a href="gateways" class="btn btn-outline-light rounded-pill px-3 py-2 fw-semibold">
            <i class="fa-solid fa-tower-cell me-1"></i> USSD Gateways
        </a>
    </div>
</div>

<!-- EXECUTIVE KPI METRICS -->
<div class="row g-3 mb-4">
    <!-- Card 1: Estates -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="metric-card h-100">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.05em;">Customer Estates</span>
                <div style="background: #e0f2fe; color: #0284c7; width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-building-user fs-5"></i>
                </div>
            </div>
            <h2 class="fw-bold mb-1 text-slate-900"><?= number_format($total_estates) ?></h2>
            <div class="small">
                <span class="text-success fw-bold"><i class="fa-solid fa-circle-check me-1"></i><?= $active_estates ?> Active</span>
                <?php if ($suspended_estates > 0): ?>
                    &bull; <span class="text-danger fw-bold"><?= $suspended_estates ?> Suspended</span>
                <?php endif; ?>
                <?php if ($trial_estates > 0): ?>
                    &bull; <span class="text-warning fw-bold"><?= $trial_estates ?> Trial</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Card 2: Units -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="metric-card h-100">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.05em;">Physical Units</span>
                <div style="background: #f3e8ff; color: #9333ea; width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-city fs-5"></i>
                </div>
            </div>
            <h2 class="fw-bold mb-1 text-slate-900"><?= number_format($total_units) ?></h2>
            <small class="text-secondary">Flats &amp; residential properties managed</small>
        </div>
    </div>

    <!-- Card 3: Residents -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="metric-card h-100">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.05em;">Platform Residents</span>
                <div style="background: #dcfce7; color: #16a34a; width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-users fs-5"></i>
                </div>
            </div>
            <h2 class="fw-bold mb-1 text-slate-900"><?= number_format($total_residents) ?></h2>
            <small class="text-success fw-medium"><i class="fa-solid fa-circle-check me-1"></i>Active account holders across tenants</small>
        </div>
    </div>

    <!-- Card 4: Access Transactions -->
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="metric-card h-100">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.05em;">Access Traffic</span>
                <div style="background: #fef3c7; color: #d97706; width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-ticket fs-5"></i>
                </div>
            </div>
            <h2 class="fw-bold mb-1 text-slate-900"><?= number_format($total_passes) ?></h2>
            <small class="text-secondary">Visitor gate passes &amp; offline sessions</small>
        </div>
    </div>
</div>

<!-- TENANT ESTATES TABLE CARD -->
<div class="card-custom mb-4" id="estates-table-card">
    <div class="p-3 px-4 bg-white border-bottom d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <h5 class="fw-bold text-slate-800 mb-0">Tenant Estates Registry</h5>
            <small class="text-secondary">Control customer portals, enforce module feature flags, or access tenant workspaces.</small>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <!-- Filter Pills -->
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-secondary active filter-btn" onclick="filterByStatus('all', this)">All (<?= $total_estates ?>)</button>
                <button type="button" class="btn btn-outline-secondary filter-btn" onclick="filterByStatus('active', this)">Active (<?= $active_estates ?>)</button>
                <button type="button" class="btn btn-outline-secondary filter-btn" onclick="filterByStatus('suspended', this)">Suspended (<?= $suspended_estates ?>)</button>
            </div>

            <div class="position-relative">
                <i class="fa-solid fa-magnifying-glass position-absolute text-secondary" style="top: 10px; left: 12px; font-size: 0.8rem;"></i>
                <input type="text" id="estateSearchInput" class="form-control form-control-sm rounded-pill ps-4 pe-3" placeholder="Search estates or subdomains..." style="width: 230px;" onkeyup="filterEstatesTable()">
            </div>
            <a href="create_estate" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold d-flex align-items-center gap-1 shadow-sm">
                <i class="fa-solid fa-plus"></i> New Estate
            </a>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="estatesTable">
            <thead class="table-light text-secondary small fw-bold text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.05em;">
                <tr>
                    <th class="ps-4">Estate &amp; Customer</th>
                    <th>Subdomain Routing</th>
                    <th>Plan Tier</th>
                    <th>Tenant Status</th>
                    <th>Residents / Quota</th>
                    <th>Units</th>
                    <th class="text-end pe-4">Super Admin Control Hub</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $estatesQuery = $conn->query("SELECT e.*, 
                    (SELECT COUNT(*) FROM flats f WHERE f.estate_id = e.id) as flats_cnt,
                    (SELECT COUNT(*) FROM residents r WHERE r.estate_id = e.id AND r.status = 'active') as residents_cnt,
                    (SELECT COUNT(*) FROM users u WHERE u.estate_id = e.id AND u.role = 'admin') as admin_cnt
                    FROM estates e 
                    ORDER BY e.created_at DESC");
                
                while($e = $estatesQuery->fetch_assoc()):
                    $eid = intval($e['id']);
                    $e_logo = '';
                    $l_res = $conn->query("SELECT setting_value FROM system_settings WHERE estate_id = $eid AND setting_key = 'estate_logo' LIMIT 1");
                    if ($l_res && $l_row = $l_res->fetch_assoc()) {
                        $e_logo = get_media_url($l_row['setting_value']);
                    }
                    $plan = strtolower($e['plan'] ?? 'starter');
                    $status = strtolower($e['status'] ?? 'active');
                    $prefix = $e['domain_prefix'] ?? 'main';
                    $max_res = intval($e['max_residents'] ?: 250);
                    $cur_res = intval($e['residents_cnt']);
                    $res_pct = ($max_res > 0) ? min(100, round(($cur_res / $max_res) * 100)) : 0;
                ?>
                <tr class="estate-row" data-name="<?= strtolower(htmlspecialchars($e['name'])) ?>" data-prefix="<?= strtolower(htmlspecialchars($prefix)) ?>" data-status="<?= $status ?>">
                    <td class="ps-4">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width: 44px; height: 44px; border-radius: 12px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; overflow: hidden; border: 1px solid #e2e8f0; flex-shrink: 0;">
                                <?php if (!empty($e_logo)): ?>
                                    <img src="<?= htmlspecialchars($e_logo) ?>" alt="Logo" style="width: 100%; height: 100%; object-fit: contain;">
                                <?php else: ?>
                                    <i class="fa-solid fa-tree-city text-secondary fs-5"></i>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="fw-bold text-slate-800 fs-6"><?= htmlspecialchars($e['name']) ?></div>
                                <div class="small text-secondary font-monospace" style="font-size: 0.72rem;">
                                    ID: #<?= $eid ?> &bull; <?= intval($e['admin_cnt']) ?> Admin(s) &bull; <?= date('M d, Y', strtotime($e['created_at'])) ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge bg-light text-dark border font-monospace px-2.5 py-1.5" style="font-size: 0.76rem;">
                            <i class="fa-solid fa-globe text-secondary me-1"></i><?= htmlspecialchars($prefix) ?>.estateapp.com
                        </span>
                        <?php if (!empty($e['custom_domain'])): ?>
                            <div class="small text-secondary font-monospace mt-1" style="font-size: 0.68rem;">
                                <i class="fa-solid fa-link me-1"></i><?= htmlspecialchars($e['custom_domain']) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge rounded-pill px-3 py-1 text-capitalize fw-bold" style="background: <?= $plan === 'enterprise' ? '#fef3c7; color: #b45309;' : ($plan === 'growth' ? '#e0e7ff; color: #4338ca;' : '#e0f2fe; color: #0284c7;') ?>">
                            <i class="fa-solid fa-cube me-1"></i><?= htmlspecialchars($plan) ?>
                        </span>
                    </td>
                    <td>
                        <!-- FAST STATUS CHANGER DROPDOWN -->
                        <div class="dropdown d-inline-block">
                            <button class="btn btn-sm rounded-pill px-3 py-1 dropdown-toggle border-0 fw-bold" style="background: <?= $status === 'active' ? '#dcfce7; color: #15803d;' : ($status === 'suspended' ? '#fee2e2; color: #b91c1c;' : '#fef9c3; color: #854d0e;') ?> font-size: 0.75rem;" type="button" data-bs-toggle="dropdown">
                                <i class="fa-solid fa-circle me-1" style="font-size: 0.45rem;"></i>
                                <span id="statusText_<?= $eid ?>"><?= ucfirst(htmlspecialchars($status)) ?></span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-sm shadow border-0 rounded-3">
                                <li><a class="dropdown-item small py-2" href="#" onclick="updateEstateStatus(<?= $eid ?>, 'active'); return false;"><i class="fa-solid fa-check text-success me-2"></i>Active (Normal Access)</a></li>
                                <li><a class="dropdown-item small py-2" href="#" onclick="updateEstateStatus(<?= $eid ?>, 'trial'); return false;"><i class="fa-solid fa-clock text-warning me-2"></i>Trial Period</a></li>
                                <li><a class="dropdown-item small py-2 text-danger fw-bold" href="#" onclick="updateEstateStatus(<?= $eid ?>, 'suspended'); return false;"><i class="fa-solid fa-ban text-danger me-2"></i>Suspended (Lockout)</a></li>
                                <li><a class="dropdown-item small py-2" href="#" onclick="updateEstateStatus(<?= $eid ?>, 'maintenance'); return false;"><i class="fa-solid fa-wrench text-info me-2"></i>Maintenance Mode</a></li>
                            </ul>
                        </div>
                    </td>
                    <td>
                        <div class="d-flex align-items-center justify-content-between small fw-bold text-slate-800" style="font-size: 0.78rem;">
                            <span><?= number_format($cur_res) ?> / <?= number_format($max_res) ?></span>
                            <span class="text-secondary"><?= $res_pct ?>%</span>
                        </div>
                        <div class="progress mt-1" style="height: 5px; width: 110px;">
                            <div class="progress-bar <?= $res_pct > 90 ? 'bg-danger' : ($res_pct > 75 ? 'bg-warning' : 'bg-success') ?>" role="progressbar" style="width: <?= $res_pct ?>%;"></div>
                        </div>
                    </td>
                    <td>
                        <span class="badge bg-light text-dark border rounded-pill px-2.5 py-1 fw-bold">
                            <?= number_format($e['flats_cnt']) ?> Units
                        </span>
                    </td>
                    <td class="text-end pe-4">
                        <div class="d-flex align-items-center justify-content-end gap-1.5">
                            
                            <!-- 1-CLICK IMPERSONATION ACCESS -->
                            <a href="impersonate?estate_id=<?= $eid ?>" class="btn btn-sm btn-primary rounded-pill px-3 py-1 fw-bold shadow-sm d-flex align-items-center gap-1.5" title="Access & Manage this Estate Portal">
                                <i class="fa-solid fa-key" style="font-size: 0.75rem;"></i>
                                <span>Access</span>
                            </a>

                            <!-- MODULE FEATURE FLAGS MODAL -->
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2.5 py-1 fw-semibold d-flex align-items-center gap-1" onclick="openModuleModal(<?= $eid ?>, '<?= htmlspecialchars(addslashes($e['name'])) ?>')" title="Toggle Feature Modules">
                                <i class="fa-solid fa-sliders" style="font-size: 0.75rem;"></i>
                                <span class="d-none d-md-inline">Modules</span>
                            </button>

                            <!-- FULL EDIT & LIMITS -->
                            <a href="edit_estate?id=<?= $eid ?>" class="btn btn-sm btn-outline-dark rounded-pill px-2.5 py-1 fw-semibold d-flex align-items-center gap-1" title="Configure Estate Details & Limits">
                                <i class="fa-solid fa-gear" style="font-size: 0.75rem;"></i>
                                <span class="d-none d-md-inline">Settings</span>
                            </a>

                            <!-- DELETE (RESTRICTED FOR ROOT) -->
                            <?php if ($eid > 1): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('WARNING: Are you sure you want to permanently delete \'<?= htmlspecialchars(addslashes($e['name'])) ?>\'? All flats, residents, and logs will be lost.');">
                                    <input type="hidden" name="estate_id" value="<?= $eid ?>">
                                    <button type="submit" name="delete_estate" class="btn btn-sm btn-outline-danger rounded-circle p-1" style="width: 28px; height: 28px;" title="Delete Estate">
                                        <i class="fa-solid fa-trash" style="font-size: 0.75rem;"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- =================================================================== -->
<!-- MODULE FEATURE FLAGS MODAL -->
<!-- =================================================================== -->
<div class="modal fade" id="moduleConfigModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 border-0 shadow-lg overflow-hidden">
            <div class="modal-header bg-dark text-white border-0 py-3" style="background: linear-gradient(135deg, #0b1120 0%, #1e293b 100%) !important;">
                <div class="d-flex align-items-center gap-2">
                    <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(59, 130, 246, 0.2); display: flex; align-items: center; justify-content: center; color: #38bdf8;">
                        <i class="fa-solid fa-sliders fs-6"></i>
                    </div>
                    <div>
                        <h6 class="modal-title fw-bold mb-0" id="modModalTitle">Estate Module Feature Flags</h6>
                        <small class="text-secondary" style="font-size: 0.75rem;">Instant kill-switch to turn features on or off for this tenant</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body p-4 bg-light">
                <div class="p-3 bg-white rounded-3 border mb-3 d-flex justify-content-between align-items-center">
                    <div>
                        <span class="small text-secondary fw-semibold">Target Tenant Estate:</span>
                        <div class="fw-bold fs-6 text-slate-800" id="modTargetEstateName">Estate Name</div>
                    </div>
                    <div>
                        <span class="badge bg-primary rounded-pill px-3 py-1 font-monospace" id="modTargetEstatePlan">STARTER</span>
                    </div>
                </div>

                <div id="moduleListLoading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="small text-secondary mt-2 mb-0">Loading module status...</p>
                </div>

                <div id="moduleListContainer" class="row g-2" style="display: none;">
                    <!-- Modules injected via JS -->
                </div>
            </div>

            <div class="modal-footer bg-white border-0 py-3">
                <small class="text-secondary me-auto" style="font-size: 0.75rem;">
                    <i class="fa-solid fa-circle-info text-primary me-1"></i> Toggling a module off instantly hides it from the tenant sidebar and blocks direct route requests.
                </small>
                <button type="button" class="btn btn-secondary rounded-pill px-4 fw-semibold" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
let activeModalEstateId = 0;

function filterByStatus(status, btn) {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    
    const rows = document.querySelectorAll('.estate-row');
    rows.forEach(r => {
        const rowStatus = r.getAttribute('data-status');
        if (status === 'all' || rowStatus === status) {
            r.style.display = '';
        } else {
            r.style.display = 'none';
        }
    });
}

function filterEstatesTable() {
    const term = document.getElementById('estateSearchInput').value.toLowerCase().trim();
    const rows = document.querySelectorAll('.estate-row');
    rows.forEach(r => {
        const name = r.getAttribute('data-name');
        const prefix = r.getAttribute('data-prefix');
        if (name.includes(term) || prefix.includes(term)) {
            r.style.display = '';
        } else {
            r.style.display = 'none';
        }
    });
}

function openModuleModal(estateId, estateName) {
    activeModalEstateId = estateId;
    document.getElementById('modTargetEstateName').textContent = estateName;
    document.getElementById('moduleListLoading').style.display = 'block';
    document.getElementById('moduleListContainer').style.display = 'none';

    const modal = new bootstrap.Modal(document.getElementById('moduleConfigModal'));
    modal.show();

    fetch(`ajax_get_estate_modules?estate_id=${estateId}`)
        .then(r => r.json())
        .then(data => {
            document.getElementById('moduleListLoading').style.display = 'none';
            if (!data.success) {
                alert('Error: ' + data.error);
                return;
            }

            document.getElementById('modTargetEstatePlan').textContent = (data.estate.plan || 'Starter').toUpperCase();
            
            const container = document.getElementById('moduleListContainer');
            container.innerHTML = '';

            data.modules.forEach(mod => {
                const col = document.createElement('div');
                col.className = 'col-12 col-md-6';
                col.innerHTML = `
                    <div class="p-3 bg-white rounded-3 border h-100 d-flex align-items-center justify-content-between shadow-sm">
                        <div class="d-flex align-items-center gap-3 pe-2">
                            <div style="background: #f1f5f9; width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1rem; color: #475569; flex-shrink: 0;">
                                <i class="fa-solid ${mod.icon}"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-0 text-slate-800" style="font-size: 0.88rem;">${mod.name}</h6>
                                <small class="text-secondary" style="font-size: 0.72rem; line-height: 1.2; display: block;">${mod.desc}</small>
                            </div>
                        </div>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" role="switch" id="switch_${mod.key}" ${mod.is_enabled ? 'checked' : ''} onchange="toggleModule('${mod.key}', this.checked)" style="cursor: pointer; width: 2.2rem; height: 1.2rem;">
                        </div>
                    </div>
                `;
                container.appendChild(col);
            });

            document.getElementById('moduleListContainer').style.display = 'flex';
        })
        .catch(err => {
            document.getElementById('moduleListLoading').style.display = 'none';
            alert('Failed loading modules: ' + err.message);
        });
}

function toggleModule(moduleKey, isChecked) {
    const formData = new FormData();
    formData.append('estate_id', activeModalEstateId);
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
            const sw = document.getElementById('switch_' + moduleKey);
            if (sw) sw.checked = !isChecked;
        }
    })
    .catch(err => {
        showSuperToast('Network error: ' + err.message, false);
        const sw = document.getElementById('switch_' + moduleKey);
        if (sw) sw.checked = !isChecked;
    });
}

function updateEstateStatus(estateId, newStatus) {
    const formData = new FormData();
    formData.append('estate_id', estateId);
    formData.append('status', newStatus);

    fetch('ajax_toggle_estate_status', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            showSuperToast(res.message, true);
            const txt = document.getElementById('statusText_' + estateId);
            if (txt) {
                txt.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
                const btn = txt.closest('button');
                if (btn) {
                    const colors = {
                        'active': '#dcfce7; color: #15803d;',
                        'suspended': '#fee2e2; color: #b91c1c;',
                        'trial': '#fef9c3; color: #854d0e;',
                        'maintenance': '#f3e8ff; color: #7e22ce;'
                    };
                    btn.setAttribute('style', `background: ${colors[newStatus] || '#f1f5f9; color: #475569;'} font-size: 0.75rem;`);
                }
                const row = txt.closest('tr');
                if (row) {
                    row.setAttribute('data-status', newStatus);
                }
            }
        } else {
            showSuperToast('Error: ' + res.error, false);
        }
    })
    .catch(err => {
        showSuperToast('Network error: ' + err.message, false);
    });
}
</script>

<?php require_once 'includes/super_footer.php'; ?>
