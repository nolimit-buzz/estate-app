<?php
// superadmin/includes/super_topbar.php - Super Admin Modern Sticky Topbar
$allEstatesList = [];
if (isset($conn)) {
    $eq = $conn->query("SELECT id, name, status, plan FROM estates ORDER BY id ASC");
    if ($eq) {
        while ($er = $eq->fetch_assoc()) $allEstatesList[] = $er;
    }
}

$is_impersonating = is_impersonating_estate();
$impersonated_name = get_impersonated_estate_name($conn);
?>
<!-- MAIN WRAPPER START -->
<main id="super-main">
    <!-- SUPER ADMIN TOPBAR -->
    <header id="super-topbar">
        <div class="d-flex align-items-center gap-3">
            <!-- Sidebar Toggle (Mobile) -->
            <button type="button" class="btn btn-sm btn-light rounded-circle border d-lg-none" onclick="toggleSuperSidebar()" style="width: 38px; height: 38px;">
                <i class="fa-solid fa-bars"></i>
            </button>

            <!-- Context Breadcrumb & Health Pulse -->
            <div>
                <div class="d-flex align-items-center gap-2">
                    <h6 class="fw-bold mb-0 text-slate-800 fs-6"><?= htmlspecialchars($page_title ?? 'SaaS Command Center') ?></h6>
                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-2 py-0.5" style="font-size: 0.68rem;">v2.5 SaaS Enterprise</span>
                </div>
                <div class="d-flex align-items-center gap-2 small text-secondary mt-0.5" style="font-size: 0.72rem;">
                    <span class="text-primary fw-semibold"><i class="fa-solid fa-server me-1"></i>Central Cluster</span>
                    <span>&bull;</span>
                    <span class="text-success fw-medium d-inline-flex align-items-center gap-1">
                        <i class="fa-solid fa-circle text-success" style="font-size: 0.45rem;"></i> Gateways Active
                    </span>
                    <span>&bull;</span>
                    <span class="text-secondary"><?= count($allEstatesList) ?> Estates Provisioned</span>
                </div>
            </div>
        </div>

        <!-- Actions & Quick Impersonate -->
        <div class="d-flex align-items-center gap-2 gap-sm-3">
            
            <!-- QUICK IMPERSONATE DROPDOWN -->
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-warning rounded-pill px-3 py-1.5 fw-bold dropdown-toggle d-flex align-items-center gap-2 shadow-sm" type="button" data-bs-toggle="dropdown" style="border-width: 1.5px;">
                    <i class="fa-solid fa-key text-warning"></i>
                    <span class="d-none d-md-inline">Jump to Tenant</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4 p-2" style="width: 280px; max-height: 420px; overflow-y: auto;">
                    <li class="dropdown-header small fw-bold text-uppercase text-secondary" style="font-size: 0.7rem;">
                        <i class="fa-solid fa-building me-1"></i> Select Estate to Impersonate:
                    </li>
                    <?php foreach ($allEstatesList as $es): 
                        $badgeColor = ($es['status'] === 'active') ? 'bg-success' : (($es['status'] === 'suspended') ? 'bg-danger' : 'bg-warning text-dark');
                    ?>
                        <li>
                            <a class="dropdown-item rounded-3 py-2 small d-flex align-items-center justify-content-between" href="impersonate?estate_id=<?= $es['id'] ?>">
                                <div class="d-flex align-items-center gap-2 text-truncate me-2">
                                    <div style="width: 26px; height: 26px; border-radius: 6px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 700; color: #475569; flex-shrink: 0;">
                                        #<?= $es['id'] ?>
                                    </div>
                                    <span class="fw-semibold text-slate-800 text-truncate"><?= htmlspecialchars($es['name']) ?></span>
                                </div>
                                <span class="badge <?= $badgeColor ?> rounded-pill" style="font-size: 0.62rem;"><?= ucfirst($es['status']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- PROVISION BUTTON -->
            <a href="create_estate" class="btn btn-sm btn-primary rounded-pill px-3 py-1.5 fw-bold shadow-sm d-none d-sm-inline-flex align-items-center gap-1.5">
                <i class="fa-solid fa-plus"></i>
                <span>Provision Estate</span>
            </a>

            <div class="vr bg-secondary opacity-25 d-none d-sm-block" style="height: 28px;"></div>

            <!-- USER MENU -->
            <div class="dropdown">
                <button class="btn btn-sm btn-light border rounded-pill px-2.5 py-1 d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                    <div style="width: 28px; height: 28px; border-radius: 50%; background: #0b1120; color: #38bdf8; display: flex; align-items: center; justify-content: center; font-size: 0.72rem; font-weight: 800; border: 1.5px solid #38bdf8;">
                        SA
                    </div>
                    <span class="small fw-bold d-none d-md-inline"><?= htmlspecialchars($_SESSION['name'] ?? 'Super Admin') ?></span>
                    <i class="fa-solid fa-chevron-down text-secondary" style="font-size: 0.65rem;"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4 p-2" style="width: 230px;">
                    <li class="p-2 border-bottom mb-1">
                        <div class="fw-bold small text-slate-800"><?= htmlspecialchars($_SESSION['name'] ?? 'Super Admin') ?></div>
                        <small class="text-secondary" style="font-size: 0.72rem;"><?= htmlspecialchars($_SESSION['email'] ?? 'superadmin@admin.com') ?></small>
                        <div class="mt-1"><span class="badge bg-dark rounded-pill" style="font-size: 0.65rem;">Super Admin Authority</span></div>
                    </li>
                    <li><a class="dropdown-item rounded-3 small py-2" href="index"><i class="fa-solid fa-chart-pie me-2 text-primary"></i>Command Center</a></li>
                    <li><a class="dropdown-item rounded-3 small py-2" href="modules"><i class="fa-solid fa-sliders me-2 text-info"></i>Module Matrix</a></li>
                    <li><a class="dropdown-item rounded-3 small py-2" href="gateways"><i class="fa-solid fa-tower-cell me-2 text-warning"></i>USSD Gateway</a></li>
                    <li><a class="dropdown-item rounded-3 small py-2" href="audit"><i class="fa-solid fa-clock-rotate-left me-2 text-secondary"></i>Audit Trail</a></li>
                    <li><a class="dropdown-item rounded-3 small py-2" href="admins"><i class="fa-solid fa-user-shield me-2 text-success"></i>Admin Security</a></li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li><a class="dropdown-item rounded-3 small py-2 text-danger fw-semibold" href="../logout"><i class="fa-solid fa-power-off me-2"></i>Sign Out</a></li>
                </ul>
            </div>

        </div>
    </header>

    <?php if ($is_impersonating): ?>
        <!-- IMPERSONATION ACTIVE BANNER -->
        <div class="px-4 py-2.5 text-white d-flex flex-wrap align-items-center justify-content-between gap-2 shadow-sm" style="background: linear-gradient(90deg, #d97706 0%, #b45309 100%); font-size: 0.85rem;">
            <div class="d-flex align-items-center gap-2">
                <i class="fa-solid fa-triangle-exclamation fs-6"></i>
                <span><strong>TENANT IMPERSONATION ACTIVE:</strong> You are currently operating inside <strong><?= htmlspecialchars($impersonated_name) ?></strong></span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="../admin/index" class="btn btn-sm btn-light fw-bold text-dark rounded-pill px-3 py-1 shadow-sm" style="font-size: 0.75rem;">
                    <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Open Portal
                </a>
                <a href="exit_impersonation" class="btn btn-sm btn-outline-light rounded-pill px-3 py-1" style="font-size: 0.75rem;">
                    <i class="fa-solid fa-xmark me-1"></i> Exit Impersonation
                </a>
            </div>
        </div>
    <?php endif; ?>

    <div class="super-content-body">
