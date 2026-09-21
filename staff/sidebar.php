<?php
// staff/sidebar.php
$current_page = str_replace('.php', '', basename($_SERVER['PHP_SELF']));
?>
<aside class="sidebar" style="min-height: calc(100vh - 60px);">
    <nav class="sidebar-nav mt-3">
        <ul>
            <li class="nav-label">Staff Console</li>
            <li>
                <a href="../staff/index" class="<?php echo ($current_page == 'index') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-gauge"></i> Overview Dashboard
                </a>
            </li>
            <li>
                <a href="../staff/roster" class="<?php echo ($current_page == 'roster') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-calendar-check text-teal" style="color: #0d9488;"></i> My Duty Roster &amp; Clock In
                </a>
            </li>
            <li>
                <a href="../staff/community_chat" class="<?php echo ($current_page == 'community_chat') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-comments"></i> Estate Forum
                </a>
            </li>

            <!-- Security & Gate Operations -->
            <?php if (hasPermission('visitors.view_log') || hasPermission('visitors.check_in_out') || hasPermission('incidents.create') || hasPermission('incidents.view') || isStaffRole()): ?>
            <li class="nav-label text-slate-400 px-3 py-2 mt-3 text-uppercase fw-semibold" style="font-size: 0.75rem; letter-spacing: 0.05em;">Security & Gate</li>
            <li>
                <a href="../staff/security" class="<?php echo ($current_page == 'security') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-shield-halved"></i> Gate Visitor Passes
                </a>
            </li>
            <li>
                <a href="../staff/incidents" class="<?php echo ($current_page == 'incidents') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-book-skull text-danger"></i> Incident &amp; Occurrence Book
                </a>
            </li>
            <li>
                <a href="../staff/policies" class="<?php echo ($current_page == 'policies') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-scale-balanced text-warning"></i> Estate Rules &amp; Fines
                </a>
            </li>
            <?php endif; ?>

            <!-- Finance, Billing & Receipts -->
            <?php if (hasPermission('finance.view_invoices') || hasPermission('finance.create_invoice') || hasPermission('finance.record_payment') || hasPermission('finance.view_receipts') || hasPermission('charges.manage')): ?>
            <li class="nav-label text-slate-400 px-3 py-2 mt-3 text-uppercase fw-semibold" style="font-size: 0.75rem; letter-spacing: 0.05em;">Finance & Invoices</li>
            <li>
                <a href="../staff/finance" class="<?php echo ($current_page == 'finance') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-file-invoice-dollar"></i> Bills & Receipts
                </a>
            </li>
            <?php endif; ?>

            <!-- Resident Registration & Onboarding -->
            <?php if (hasPermission('residents.view') || hasPermission('residents.manage') || hasPermission('tenancies.manage')): ?>
            <li class="nav-label text-slate-400 px-3 py-2 mt-3 text-uppercase fw-semibold" style="font-size: 0.75rem; letter-spacing: 0.05em;">Resident Management</li>
            <li>
                <a href="../staff/directory" class="<?php echo ($current_page == 'directory') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-address-book"></i> Resident Directory
                </a>
            </li>
            <li>
                <a href="../staff/residents" class="<?php echo ($current_page == 'residents') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-users"></i> Resident Registration
                </a>
            </li>
            <?php endif; ?>

            <!-- Field Work Orders & Maintenance -->
            <?php if (hasPermission('maintenance.view_assigned') || hasPermission('maintenance.update_status')): ?>
            <li class="nav-label text-slate-400 px-3 py-2 mt-3 text-uppercase fw-semibold" style="font-size: 0.75rem; letter-spacing: 0.05em;">Field Work Orders</li>
            <li>
                <a href="../staff/maintenance" class="<?php echo ($current_page == 'maintenance') ? 'active' : ''; ?>">
                    <i class="fa-solid fa-hammer"></i> My Work Orders
                </a>
            </li>
            <?php endif; ?>

            <li class="nav-label text-slate-400 px-3 py-2 mt-3 text-uppercase fw-semibold" style="font-size: 0.75rem; letter-spacing: 0.05em;">Account</li>
            <li>
                <a href="../logout" class="text-danger">
                    <i class="fa-solid fa-power-off"></i> Sign Out
                </a>
            </li>
        </ul>
    </nav>
</aside>
<main class="main-content p-4" style="flex: 1; background: #f8fafc;">
