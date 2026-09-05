<?php
// admin/index.php
require_once '../config.php';
require_once '../includes/auth_guard.php';
requireAdminAccess();

include '../includes/header.php';
include '../includes/sidebar.php';

$estate_id = get_estate_id();

// Dynamic Database Metrics
$res_count = $conn->query("SELECT COUNT(*) as cnt FROM residents WHERE estate_id = $estate_id AND status = 'active'");
$total_residents = $res_count ? $res_count->fetch_assoc()['cnt'] : 0;

$bld_count = $conn->query("SELECT COUNT(*) as cnt FROM buildings WHERE estate_id = $estate_id");
$total_buildings = $bld_count ? $bld_count->fetch_assoc()['cnt'] : 0;

$flat_occupied = $conn->query("SELECT COUNT(*) as cnt FROM flats WHERE estate_id = $estate_id AND status = 'occupied'");
$total_occupied_flats = $flat_occupied ? $flat_occupied->fetch_assoc()['cnt'] : 0;

$maint_count = $conn->query("SELECT COUNT(*) as cnt FROM maintenance_requests WHERE estate_id = $estate_id AND status IN ('open', 'in_progress')");
$pending_maintenance = $maint_count ? $maint_count->fetch_assoc()['cnt'] : 0;

$sec_count = $conn->query("SELECT COUNT(*) as cnt FROM visitors WHERE estate_id = $estate_id AND status = 'entered'");
$active_visitors = $sec_count ? $sec_count->fetch_assoc()['cnt'] : 0;

$recent_maintenance = $conn->query("SELECT m.*, u.name as requester_name, f.number as flat_number 
    FROM maintenance_requests m 
    LEFT JOIN users u ON m.user_id = u.id 
    LEFT JOIN flats f ON m.flat_id = f.id 
    WHERE m.estate_id = $estate_id 
    ORDER BY m.id DESC LIMIT 5");

$recent_visitors = $conn->query("SELECT v.*, u.name as resident_name, f.number as flat_number 
    FROM visitors v 
    LEFT JOIN users u ON v.resident_id = u.id 
    LEFT JOIN flats f ON v.flat_id = f.id 
    WHERE v.estate_id = $estate_id 
    ORDER BY v.id DESC LIMIT 5");
?>

<div class="page-header d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 font-bold text-slate-800 m-0">Admin Command Center</h1>
        <p class="text-secondary small mb-0">Overview of Estate Operations, Resident Management, and Staff Activities</p>
    </div>
    <div class="d-flex gap-2">
        <a href="residents" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-user-plus me-1"></i> Add Resident</a>
        <a href="staff" class="btn btn-outline-success btn-sm"><i class="fa-solid fa-user-tie me-1"></i> Manage Staff</a>
        <a href="../staff/security" target="_blank" class="btn btn-teal btn-sm text-white" style="background: #0d9488;"><i class="fa-solid fa-shield-halved me-1"></i> Gate Security Portal</a>
    </div>
</div>

<div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; margin-top: 1rem;">
    <!-- Stat Card 1: Active Residents -->
    <div class="stat-card" style="background: white; padding: 1.5rem; border-radius: 0.5rem; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <div class="stat-icon" style="flex-shrink: 0; width: 48px; height: 48px; background: var(--primary-light); color: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
            <i class="fa-solid fa-users" style="font-size: 1.25rem;"></i>
        </div>
        <h3 style="margin: 0; font-size: 0.875rem; color: #64748b; font-weight: 500;">Active Residents</h3>
        <p style="margin: 0.5rem 0 0; font-size: 1.75rem; font-weight: 700; color: #0f172a;"><?php echo number_format($total_residents); ?></p>
    </div>
    
    <!-- Stat Card 2: Properties & Occupancy -->
    <div class="stat-card" style="background: white; padding: 1.5rem; border-radius: 0.5rem; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <div class="stat-icon" style="flex-shrink: 0; width: 48px; height: 48px; background: #f0fdf4; color: #22c55e; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
            <i class="fa-solid fa-building" style="font-size: 1.25rem;"></i>
        </div>
        <h3 style="margin: 0; font-size: 0.875rem; color: #64748b; font-weight: 500;">Buildings & Occupancy</h3>
        <p style="margin: 0.5rem 0 0; font-size: 1.75rem; font-weight: 700; color: #0f172a;"><?php echo number_format($total_buildings); ?> <span style="font-size: 0.875rem; color: #64748b; font-weight: 400;">(<?php echo $total_occupied_flats; ?> Occupied)</span></p>
    </div>
    
    <!-- Stat Card 3: Pending Maintenance -->
    <div class="stat-card" style="background: white; padding: 1.5rem; border-radius: 0.5rem; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <div class="stat-icon" style="flex-shrink: 0; width: 48px; height: 48px; background: #fff7ed; color: #f97316; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
            <i class="fa-solid fa-hammer" style="font-size: 1.25rem;"></i>
        </div>
        <h3 style="margin: 0; font-size: 0.875rem; color: #64748b; font-weight: 500;">Pending Maintenance</h3>
        <p style="margin: 0.5rem 0 0; font-size: 1.75rem; font-weight: 700; color: #0f172a;"><?php echo number_format($pending_maintenance); ?></p>
    </div>
    
    <!-- Stat Card 4: Active Visitors -->
    <div class="stat-card" style="background: white; padding: 1.5rem; border-radius: 0.5rem; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <div class="stat-icon" style="flex-shrink: 0; width: 48px; height: 48px; background: #ccfbf1; color: #0d9488; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 1rem;">
            <i class="fa-solid fa-shield-cat" style="font-size: 1.25rem;"></i>
        </div>
        <h3 style="margin: 0; font-size: 0.875rem; color: #64748b; font-weight: 500;">Visitors Inside Estate</h3>
        <p style="margin: 0.5rem 0 0; font-size: 1.75rem; font-weight: 700; color: #0f172a;"><?php echo number_format($active_visitors); ?></p>
    </div>
</div>

<div class="row g-4 mt-3">
    <!-- Maintenance Work Orders Overview -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-0">
                <h5 class="card-title fw-bold m-0 text-slate-800"><i class="fa-solid fa-wrench me-2 text-warning"></i> Recent Maintenance Work Orders</h5>
                <a href="maintenance" class="btn btn-sm btn-outline-secondary">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle table-hover mb-0">
                        <thead class="bg-light text-secondary small text-uppercase">
                            <tr>
                                <th>Ticket</th>
                                <th>Requester</th>
                                <th>Priority</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_maintenance && $recent_maintenance->num_rows > 0): ?>
                                <?php while ($m = $recent_maintenance->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-slate-800">#<?php echo $m['id']; ?> - <?php echo htmlspecialchars($m['title']); ?></div>
                                            <small class="text-muted">Flat <?php echo htmlspecialchars($m['flat_number'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td><small><?php echo htmlspecialchars($m['requester_name'] ?? 'Resident'); ?></small></td>
                                        <td>
                                            <span class="badge <?php echo ($m['priority'] === 'emergency') ? 'bg-danger' : 'bg-info text-dark'; ?>">
                                                <?php echo strtoupper($m['priority']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $st = $m['status'];
                                            if ($st === 'open') echo '<span class="badge bg-danger">Open</span>';
                                            elseif ($st === 'in_progress') echo '<span class="badge bg-warning text-dark">In Progress</span>';
                                            elseif ($st === 'resolved') echo '<span class="badge bg-success">Resolved</span>';
                                            else echo '<span class="badge bg-secondary">Closed</span>';
                                            ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-3 text-muted">No maintenance tickets found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Security Gate Log Feed -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-0">
                <h5 class="card-title fw-bold m-0 text-slate-800"><i class="fa-solid fa-shield-halved me-2 text-teal" style="color: #0d9488;"></i> Live Gate Visitor Log</h5>
                <a href="security" class="btn btn-sm btn-outline-secondary">View Security</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle table-hover mb-0">
                        <thead class="bg-light text-secondary small text-uppercase">
                            <tr>
                                <th>Passcode</th>
                                <th>Visitor Name</th>
                                <th>Host Resident</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_visitors && $recent_visitors->num_rows > 0): ?>
                                <?php while ($v = $recent_visitors->fetch_assoc()): ?>
                                    <tr>
                                        <td><span class="badge bg-dark text-monospace"><?php echo htmlspecialchars($v['visitor_code'] ?? 'N/A'); ?></span></td>
                                        <td>
                                            <div class="fw-semibold text-slate-800"><?php echo htmlspecialchars($v['name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($v['phone'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($v['resident_name'] ?? 'N/A'); ?></div>
                                            <small class="text-muted">Flat <?php echo htmlspecialchars($v['flat_number'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td>
                                            <?php 
                                            $st = $v['status'];
                                            if ($st === 'entered') echo '<span class="badge bg-success">Entered</span>';
                                            elseif ($st === 'pre_registered') echo '<span class="badge bg-primary">Expected</span>';
                                            elseif ($st === 'checked_out') echo '<span class="badge bg-secondary">Checked Out</span>';
                                            else echo '<span class="badge bg-warning text-dark">' . htmlspecialchars($st) . '</span>';
                                            ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-3 text-muted">No recent visitor activity recorded.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
