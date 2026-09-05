<!-- includes/sidebar.php -->
<?php
// Fetch Estate Details if not already available
if (!isset($sys)) {
    $sys_res = $conn->query("SELECT * FROM system_settings");
    $sys = [];
    if($sys_res) {
        while ($row = $sys_res->fetch_assoc()) {
            $sys[$row['setting_key']] = $row['setting_value'];
        }
    }
}
$estate_name = $sys['estate_name'] ?? 'EstateAdmin';
$estate_logo = $sys['estate_logo'] ?? '';
$current_page = str_replace('.php', '', basename($_SERVER['PHP_SELF']));
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <?php if($estate_logo): ?>
                <img src="<?php echo htmlspecialchars($estate_logo); ?>" alt="Logo" style="height: 32px; border-radius: 4px;">
                <span style="font-size: 1.1rem; margin-left: 0.5rem;"><?php echo htmlspecialchars($estate_name); ?></span>
            <?php else: ?>
                <i class="fa-solid fa-building-user"></i>
                <span><?php echo htmlspecialchars($estate_name); ?></span>
            <?php endif; ?>
        </div>
    </div>
    
    <nav class="sidebar-nav">
        <ul>
            <li class="nav-label">Main</li>
            <li><a href="../admin/index" class="<?php echo ($current_page == 'index') ? 'active' : ''; ?>"><i class="fa-solid fa-chart-line"></i> Dashboard</a></li>
            
            <li class="nav-label">Management</li>
            <li class="has-dropdown <?php echo ($current_page == 'properties') ? 'active' : ''; ?>">
                <a href="javascript:void(0)" class="submenu-toggle">
                    <i class="fa-solid fa-building"></i> 
                    Properties 
                    <i class="fa-solid fa-chevron-down ms-auto" style="font-size: 0.7rem;"></i>
                </a>
                <ul class="sidebar-submenu" <?php echo ($current_page == 'properties') ? 'style="display: block;"' : ''; ?>>
                    <li><a href="../admin/properties?tab=streets" class="<?php echo ($current_page == 'properties' && ($_GET['tab'] ?? 'streets') == 'streets') ? 'active' : ''; ?>">Streets</a></li>
                    <li><a href="../admin/properties?tab=buildings" class="<?php echo ($current_page == 'properties' && ($_GET['tab'] ?? '') == 'buildings') ? 'active' : ''; ?>">Properties</a></li>
                </ul>
            </li>
            <li><a href="../admin/owners" class="<?php echo ($current_page == 'owners') ? 'active' : ''; ?>"><i class="fa-solid fa-user-shield"></i> Property Owners</a></li>
            <li><a href="../admin/residents" class="<?php echo ($current_page == 'residents') ? 'active' : ''; ?>"><i class="fa-solid fa-users"></i> Residents</a></li>
            <li><a href="../admin/staff" class="<?php echo ($current_page == 'staff') ? 'active' : ''; ?>"><i class="fa-solid fa-user-tie"></i> Estate Staff</a></li>
            <li><a href="../admin/archives" class="<?php echo ($current_page == 'archives') ? 'active' : ''; ?>"><i class="fa-solid fa-box-archive"></i> Archives</a></li>
            
            <li class="nav-label">Finance & Ops</li>
            <li><a href="../admin/finance" class="<?php echo ($current_page == 'finance') ? 'active' : ''; ?>"><i class="fa-solid fa-coins"></i> Finance Hub</a></li>
            <li><a href="../admin/charges" class="<?php echo ($current_page == 'charges') ? 'active' : ''; ?>"><i class="fa-solid fa-list-check"></i> Charge Catalog</a></li>
            <li><a href="../admin/maintenance" class="<?php echo ($current_page == 'maintenance') ? 'active' : ''; ?>"><i class="fa-solid fa-hammer"></i> Maintenance</a></li>
            
            <li class="nav-label">Portals</li>
            <li><a href="../resident/index" target="_blank"><i class="fa-solid fa-house-user"></i> Resident Portal View</a></li>

            <li class="nav-label">System</li>
            <li><a href="../admin/security" class="<?php echo ($current_page == 'security') ? 'active' : ''; ?>"><i class="fa-solid fa-shield-halved"></i> Security</a></li>
            <li><a href="../admin/settings" class="<?php echo ($current_page == 'settings') ? 'active' : ''; ?>"><i class="fa-solid fa-cog"></i> Settings</a></li>
            <li><a href="../logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a></li>
        </ul>
    </nav>
</aside>
<main class="main-content">
    <header class="top-bar">
        <div class="toggle-sidebar">
            <i class="fa-solid fa-bars"></i>
        </div>
        <div class="d-flex align-items-center gap-3">
            <button type="button" class="theme-toggle-btn" title="Toggle Day/Night Mode">
                <i class="fa-solid fa-moon"></i>
                <span class="theme-text d-none d-sm-inline">Dark Mode</span>
            </button>
            <div class="user-menu" style="display: flex; align-items: center; gap: 0.5rem;">
                <span class="user-name"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin User'); ?></span>
                <div class="user-avatar" style="width: 36px; height: 36px; border-radius: 50%; background: var(--primary-color); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem;">AD</div>
            </div>
        </div>
    </header>
    <div class="content-wrapper">
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Submenu toggle logic
    const submenuToggles = document.querySelectorAll('.submenu-toggle');
    submenuToggles.forEach(toggle => {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            const parent = this.closest('.has-dropdown');
            parent.classList.toggle('active');
            
            // Handle display style for smooth transition if needed, 
            // but CSS display: block/none on .active is usually enough.
            const submenu = parent.querySelector('.sidebar-submenu');
            if (parent.classList.contains('active')) {
                submenu.style.display = 'block';
            } else {
                submenu.style.display = 'none';
            }
        });
    });
});
</script>
