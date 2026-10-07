<?php
// superadmin/index.php - Central SaaS Command Center & Multi-Tenant Control Panel
require_once '../config.php';

// Check if user is superadmin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'superadmin') {
    redirectWithFlash('../login', null, 'Super Administrator privileges required.');
}

$message = getFlashMessage('success') ?? '';
$error   = getFlashMessage('error') ?? '';

if (isset($_GET['success'])) {
    $message = "Estate created successfully!";
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
$total_residents = 0;
$total_units = 0;
$total_passes = 0;

$e_stats = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_cnt,
    SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_cnt
FROM estates")->fetch_assoc();

if ($e_stats) {
    $total_estates = intval($e_stats['total']);
    $active_estates = intval($e_stats['active_cnt']);
    $suspended_estates = intval($e_stats['suspended_cnt']);
}

$r_stat = $conn->query("SELECT COUNT(*) as c FROM residents WHERE status = 'active'")->fetch_assoc();
if ($r_stat) $total_residents = intval($r_stat['c']);

$f_stat = $conn->query("SELECT COUNT(*) as c FROM flats")->fetch_assoc();
if ($f_stat) $total_units = intval($f_stat['c']);

$p_stat = $conn->query("SELECT COUNT(*) as c FROM visitors")->fetch_assoc();
if ($p_stat) $total_passes = intval($p_stat['c']);

// Standard Modules
$platformModules = getAllPlatformModules();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin SaaS Command Center</title>
    
    <!-- Google Fonts: Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 & Font Awesome -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --saas-primary: #0f172a;
            --saas-accent: #3b82f6;
            --saas-bg: #f8fafc;
        }
        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--saas-bg);
            color: #1e293b;
        }
        .navbar-saas {
            background: #0f172a;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        .stat-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 1.5rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05);
        }
        .table-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02);
            overflow: hidden;
        }
        .estate-logo-box {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            flex-shrink: 0;
        }
        .estate-logo-box img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .badge-plan-starter { background: #e0f2fe; color: #0284c7; }
        .badge-plan-growth { background: #e0e7ff; color: #4338ca; }
        .badge-plan-enterprise { background: #fef3c7; color: #b45309; }
        
        .badge-status-active { background: #dcfce7; color: #15803d; }
        .badge-status-suspended { background: #fee2e2; color: #b91c1c; }
        .badge-status-trial { background: #fef9c3; color: #854d0e; }
        .badge-status-maintenance { background: #f3e8ff; color: #7e22ce; }

        .btn-impersonate {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #ffffff;
            border: none;
            transition: all 0.2s;
        }
        .btn-impersonate:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af);
            color: #ffffff;
            transform: scale(1.02);
        }
        .module-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 16px;
            transition: background 0.2s;
        }
        .module-item:hover {
            background: #f1f5f9;
        }
    </style>
</head>
<body>

    <!-- TOP NAVIGATION BAR -->
    <nav class="navbar navbar-expand-lg navbar-saas navbar-dark py-3">
        <div class="container-fluid px-4">
            <div class="d-flex align-items-center gap-3">
                <div style="background: linear-gradient(135deg, #3b82f6, #1d4ed8); width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.25rem;">
                    <i class="fa-solid fa-layer-group"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0 text-white tracking-wide">EstateHQ <span class="badge bg-primary text-white ms-2 px-2 py-1" style="font-size: 0.65rem;">SUPER ADMIN</span></h5>
                    <small class="text-slate-400" style="color: #94a3b8; font-size: 0.78rem;">Multi-Tenant SaaS Operations &amp; Enterprise Governance</small>
                </div>
            </div>

            <div class="d-flex align-items-center gap-3">
                <a href="create_estate" class="btn btn-primary rounded-pill px-3 py-2 fw-semibold shadow-sm d-flex align-items-center gap-2">
                    <i class="fa-solid fa-plus"></i>
                    <span>Provision New Estate</span>
                </a>
                <div class="vr bg-secondary opacity-50 d-none d-md-block" style="height: 30px;"></div>
                <div class="d-flex align-items-center gap-2 text-white">
                    <div style="background: #334155; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                        SA
                    </div>
                    <div class="d-none d-sm-block text-start">
                        <div class="small fw-bold lh-1"><?= htmlspecialchars($_SESSION['name'] ?? 'Super Admin') ?></div>
                        <small style="color: #94a3b8; font-size: 0.7rem;">Platform Owner</small>
                    </div>
                </div>
                <a href="../logout" class="btn btn-outline-light btn-sm rounded-pill px-3 py-1 ms-2" title="Sign Out">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </a>
            </div>
        </div>
    </nav>

    <!-- MAIN CONTAINER -->
    <div class="container-fluid px-4 py-4">

        <!-- Flash Notifications -->
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

        <!-- EXECUTIVE SAAS KPI METRICS -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-secondary small fw-semibold">Tenant Estates</span>
                        <div style="background: #e0f2fe; color: #0284c7; width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fa-solid fa-building-user fs-5"></i>
                        </div>
                    </div>
                    <h3 class="fw-bold mb-1 text-slate-900"><?= number_format($total_estates) ?></h3>
                    <div class="small text-muted">
                        <span class="text-success fw-bold"><i class="fa-solid fa-circle-check me-1"></i><?= $active_estates ?> Active</span>
                        <?php if ($suspended_estates > 0): ?>
                            &bull; <span class="text-danger fw-bold"><?= $suspended_estates ?> Suspended</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-secondary small fw-semibold">Units &amp; Flats</span>
                        <div style="background: #f3e8ff; color: #9333ea; width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fa-solid fa-city fs-5"></i>
                        </div>
                    </div>
                    <h3 class="fw-bold mb-1 text-slate-900"><?= number_format($total_units) ?></h3>
                    <small class="text-secondary">Physical units across all estates</small>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-secondary small fw-semibold">Cross-Estate Residents</span>
                        <div style="background: #dcfce7; color: #16a34a; width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fa-solid fa-users fs-5"></i>
                        </div>
                    </div>
                    <h3 class="fw-bold mb-1 text-slate-900"><?= number_format($total_residents) ?></h3>
                    <small class="text-success fw-medium"><i class="fa-solid fa-shield-check me-1"></i>Active account holders</small>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-secondary small fw-semibold">Access Traffic</span>
                        <div style="background: #fef3c7; color: #d97706; width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                            <i class="fa-solid fa-ticket fs-5"></i>
                        </div>
                    </div>
                    <h3 class="fw-bold mb-1 text-slate-900"><?= number_format($total_passes) ?></h3>
                    <small class="text-secondary">Total gate entries &amp; USSD passes</small>
                </div>
            </div>
        </div>

        <!-- ESTATES LIST TABLE CARD -->
        <div class="table-card">
            <div class="p-3 px-4 bg-white border-bottom d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h5 class="fw-bold text-slate-800 mb-0">Registered Tenant Estates</h5>
                    <small class="text-secondary">Manage customer portals, enforce module feature flags, or access tenant workspaces.</small>
                </div>
                <div class="d-flex gap-2">
                    <input type="text" id="estateSearchInput" class="form-control form-control-sm rounded-pill px-3" placeholder="Filter estates..." style="max-width: 240px;" onkeyup="filterEstatesTable()">
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="estatesTable">
                    <thead class="table-light text-secondary small fw-bold text-uppercase">
                        <tr>
                            <th class="ps-4">Estate Name &amp; Identity</th>
                            <th>Subdomain / Prefix</th>
                            <th>Plan &amp; Tier</th>
                            <th>Status</th>
                            <th>Residents / Flats</th>
                            <th>Admins</th>
                            <th class="text-end pe-4">SaaS Actions</th>
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
                        ?>
                        <tr class="estate-row" data-name="<?= strtolower(htmlspecialchars($e['name'])) ?>" data-prefix="<?= strtolower(htmlspecialchars($e['domain_prefix'] ?? '')) ?>">
                            <td class="ps-4">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="estate-logo-box">
                                        <?php if (!empty($e_logo)): ?>
                                            <img src="<?= htmlspecialchars($e_logo) ?>" alt="Logo">
                                        <?php else: ?>
                                            <i class="fa-solid fa-tree-city text-secondary"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-slate-800 fs-6"><?= htmlspecialchars($e['name']) ?></div>
                                        <small class="text-secondary font-monospace" style="font-size: 0.75rem;">ID: #<?= $eid ?> &bull; Created <?= date('M d, Y', strtotime($e['created_at'])) ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border font-monospace px-2 py-1">
                                    <?= htmlspecialchars($e['domain_prefix'] ?? 'main') ?>.estateapp.com
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-plan-<?= $plan ?> rounded-pill px-3 py-1 text-capitalize fw-bold">
                                    <?= htmlspecialchars($plan) ?>
                                </span>
                            </td>
                            <td>
                                <div class="dropdown d-inline-block">
                                    <button class="btn btn-sm badge badge-status-<?= $status ?> rounded-pill px-3 py-1 dropdown-toggle border-0" type="button" data-bs-toggle="dropdown">
                                        <i class="fa-solid fa-circle me-1" style="font-size: 0.5rem;"></i>
                                        <span id="statusText_<?= $eid ?>"><?= ucfirst(htmlspecialchars($status)) ?></span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-sm shadow border-0 rounded-3">
                                        <li><a class="dropdown-item small" href="#" onclick="updateEstateStatus(<?= $eid ?>, 'active'); return false;"><i class="fa-solid fa-check text-success me-2"></i>Active</a></li>
                                        <li><a class="dropdown-item small" href="#" onclick="updateEstateStatus(<?= $eid ?>, 'trial'); return false;"><i class="fa-solid fa-clock text-warning me-2"></i>Trial</a></li>
                                        <li><a class="dropdown-item small" href="#" onclick="updateEstateStatus(<?= $eid ?>, 'suspended'); return false;"><i class="fa-solid fa-ban text-danger me-2"></i>Suspended</a></li>
                                        <li><a class="dropdown-item small" href="#" onclick="updateEstateStatus(<?= $eid ?>, 'maintenance'); return false;"><i class="fa-solid fa-wrench text-info me-2"></i>Maintenance</a></li>
                                    </ul>
                                </div>
                            </td>
                            <td>
                                <div class="small fw-semibold"><?= number_format($e['residents_cnt']) ?> Residents</div>
                                <div class="small text-secondary"><?= number_format($e['flats_cnt']) ?> Flats</div>
                            </td>
                            <td>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-2 py-1">
                                    <?= intval($e['admin_cnt']) ?> Admin(s)
                                </span>
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-flex align-items-center justify-content-end gap-2">
                                    <!-- IMPERSONATE ACCESS BUTTON -->
                                    <a href="impersonate?estate_id=<?= $eid ?>" class="btn btn-sm btn-impersonate rounded-pill px-3 py-1 fw-bold shadow-sm" title="Impersonate & Manage Portal">
                                        <i class="fa-solid fa-key me-1"></i> Access Portal
                                    </a>

                                    <!-- FEATURE FLAGS BUTTON -->
                                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1 fw-bold" onclick="openModuleModal(<?= $eid ?>, '<?= htmlspecialchars(addslashes($e['name'])) ?>')" title="Configure Modules">
                                        <i class="fa-solid fa-sliders me-1"></i> Modules
                                    </button>

                                    <!-- DELETE (RESTRICTED FOR ROOT) -->
                                    <?php if ($eid > 1): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('WARNING: Are you sure you want to permanently delete \'<?= htmlspecialchars(addslashes($e['name'])) ?>\'? All flats, residents, and logs will be lost.');">
                                            <input type="hidden" name="estate_id" value="<?= $eid ?>">
                                            <button type="submit" name="delete_estate" class="btn btn-sm btn-outline-danger rounded-circle p-1" style="width: 30px; height: 30px;" title="Delete Estate">
                                                <i class="fa-solid fa-trash"></i>
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
    </div>

    <!-- =================================================================== -->
    <!-- MODULE FEATURE FLAGS MODAL -->
    <!-- =================================================================== -->
    <div class="modal fade" id="moduleConfigModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content rounded-4 border-0 shadow-lg overflow-hidden">
                <div class="modal-header bg-dark text-white border-0 py-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-sliders text-primary fs-5"></i>
                        <div>
                            <h6 class="modal-title fw-bold mb-0" id="modModalTitle">Estate Module Feature Flags</h6>
                            <small class="text-secondary" style="font-size: 0.75rem;" id="modModalSubtitle">Manage activated features for tenant</small>
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
                            <span class="badge bg-primary rounded-pill px-3 py-1" id="modTargetEstatePlan">Starter</span>
                        </div>
                    </div>

                    <div id="moduleListLoading" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="small text-secondary mt-2 mb-0">Loading module configuration...</p>
                    </div>

                    <div id="moduleListContainer" class="row g-2" style="display: none;">
                        <!-- Modules injected via JS -->
                    </div>
                </div>

                <div class="modal-footer bg-white border-0 py-3">
                    <small class="text-secondary me-auto" style="font-size: 0.75rem;">
                        <i class="fa-solid fa-circle-info text-primary me-1"></i> Toggling a module off hides it from the estate's navigation and intercepts access.
                    </small>
                    <button type="button" class="btn btn-secondary rounded-pill px-4 fw-semibold" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Notification Container -->
    <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 11000">
        <div id="saasToast" class="toast align-items-center text-white bg-dark border-0 rounded-4 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body d-flex align-items-center gap-2 py-3" id="saasToastMessage">
                    Action executed successfully.
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    let activeModalEstateId = 0;
    const toastEl = document.getElementById('saasToast');
    const toast = new bootstrap.Toast(toastEl, { delay: 3500 });

    function showToast(msg, isSuccess = true) {
        const body = document.getElementById('saasToastMessage');
        body.innerHTML = (isSuccess ? '<i class="fa-solid fa-circle-check text-success fs-5"></i> ' : '<i class="fa-solid fa-circle-xmark text-danger fs-5"></i> ') + msg;
        toast.show();
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
                        <div class="module-item h-100 d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-3 pe-2">
                                <div style="background: #e2e8f0; width: 38px; height: 38px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1rem; color: #475569; flex-shrink: 0;">
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
                showToast(res.message, true);
            } else {
                showToast('Error: ' + res.error, false);
                // revert toggle
                const sw = document.getElementById('switch_' + moduleKey);
                if (sw) sw.checked = !isChecked;
            }
        })
        .catch(err => {
            showToast('Network error: ' + err.message, false);
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
                showToast(res.message, true);
                const txt = document.getElementById('statusText_' + estateId);
                if (txt) {
                    txt.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
                    const btn = txt.closest('button');
                    if (btn) {
                        btn.className = `btn btn-sm badge badge-status-${newStatus} rounded-pill px-3 py-1 dropdown-toggle border-0`;
                    }
                }
            } else {
                showToast('Error: ' + res.error, false);
            }
        })
        .catch(err => {
            showToast('Network error: ' + err.message, false);
        });
    }
    </script>
</body>
</html>
