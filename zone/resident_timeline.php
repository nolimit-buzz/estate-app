<?php
// zone/resident_timeline.php - Zonal Resident Dossier & Movement Timeline
require_once '../config.php';
require_once '../includes/auth_guard.php';

requireZoneAccess();

$zone_id = get_current_zone_id();
$estate_id = get_estate_id();
$id = intval($_GET['id'] ?? 0);

include 'header.php';
include 'sidebar.php';

if (!$id) {
    echo "<div class='p-4 text-center text-muted'>Invalid resident request. <a href='residents'>Back to Directory</a></div>";
    include 'footer.php';
    exit;
}

// Fetch Resident Details strictly within this zone
$res_query = $conn->query("
    SELECT r.*, u.name, u.email, u.phone, u.first_name, u.last_name,
           f.number as flat_number, b.name as building_name, s.name as street_name
    FROM residents r 
    JOIN users u ON r.user_id = u.id 
    JOIN flats f ON r.flat_id = f.id
    JOIN buildings b ON f.building_id = b.id
    JOIN streets s ON b.street_id = s.id
    WHERE r.id = $id AND s.zone_id = $zone_id AND r.estate_id = $estate_id 
    LIMIT 1
");

$res = ($res_query && $res_query->num_rows > 0) ? $res_query->fetch_assoc() : null;

if (!$res) {
    echo "<div class='p-5 text-center'>
            <div class='text-muted fs-1 mb-3'><i class='fa-solid fa-user-slash'></i></div>
            <h4 class='fw-bold text-dark'>Resident Not Found</h4>
            <p class='text-secondary small'>This resident record does not exist or does not belong to your assigned zone.</p>
            <a href='residents' class='btn btn-sm btn-primary' style='background: #7e22ce; border-color: #7e22ce;'>Back to Residents</a>
          </div>";
    include 'footer.php';
    exit;
}
?>

<div class="page-header-futuristic mb-4">
    <div>
        <div class="header-breadcrumbs">
            <span><?php echo htmlspecialchars($_SESSION['zone_name'] ?? 'Zone'); ?></span>
            <i class="fa-solid fa-chevron-right separator"></i>
            <a href="residents" style="color: var(--text-muted); text-decoration: none;">Residents</a>
            <i class="fa-solid fa-chevron-right separator"></i>
            <span class="active">Lifecycle Dossier</span>
        </div>
        <h1 class="page-title"><i class="fa-solid fa-user-clock me-2 text-purple" style="color: #7e22ce;"></i> Resident Dossier: <?php echo htmlspecialchars($res['name']); ?></h1>
        <p class="page-subtitle">Chronological residency audit, relocation history, and identity records within <?php echo htmlspecialchars($_SESSION['zone_name'] ?? 'Zone'); ?>.</p>
    </div>
    <div class="header-actions">
        <a href="residents" class="btn btn-outline" style="display: inline-flex; align-items: center; gap: 0.5rem; font-weight: 600; padding: 0.6rem 1.25rem; border-radius: 0.5rem; border: 1px solid var(--border-color); color: var(--text-color); background: var(--card-bg);">
            <i class="fa-solid fa-arrow-left"></i> Back to Directory
        </a>
    </div>
</div>

<div style="display: grid; grid-template-columns: 320px 1fr; gap: 1.75rem; align-items: start;">
    <!-- LEFT: Profile Summary -->
    <div>
        <div class="mature-card" style="padding: 1.75rem; text-align: center;">
            <?php if(!empty($res['image_path'])): ?>
                <img src="<?php echo htmlspecialchars($res['image_path']); ?>" style="width: 110px; height: 110px; border-radius: 50%; object-fit: cover; margin: 0 auto 1rem; border: 3px solid var(--border-color); box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);">
            <?php else: ?>
                <div style="width: 110px; height: 110px; border-radius: 50%; background: rgba(168, 85, 247, 0.12); color: #7e22ce; margin: 0 auto 1rem; display: flex; align-items: center; justify-content: center; font-size: 2.8rem; border: 3px solid var(--border-color);">
                    <i class="fa-solid fa-user"></i>
                </div>
            <?php endif; ?>
            <h3 style="font-size: 1.2rem; font-weight: 700; color: var(--text-color); margin: 0 0 0.25rem;"><?php echo htmlspecialchars($res['name']); ?></h3>
            <span class="id-chip"><?php echo htmlspecialchars($res['custom_id']); ?></span>
            
            <div style="text-align: left; margin-top: 1.5rem; font-size: 0.86rem; line-height: 2; border-top: 1px solid var(--border-color); padding-top: 1.25rem;">
                <p style="margin: 0; display: flex; align-items: center; gap: 0.6rem; color: var(--text-color);"><i class="fa-solid fa-envelope" style="width: 18px; color: var(--text-muted);"></i> <?php echo htmlspecialchars($res['email']); ?></p>
                <p style="margin: 0; display: flex; align-items: center; gap: 0.6rem; color: var(--text-color);"><i class="fa-solid fa-phone" style="width: 18px; color: var(--text-muted);"></i> <?php echo htmlspecialchars($res['phone']); ?></p>
                <p style="margin: 0; display: flex; align-items: center; gap: 0.6rem; color: var(--text-color);"><i class="fa-solid fa-building" style="width: 18px; color: var(--text-muted);"></i> Flat <?php echo htmlspecialchars($res['flat_number']); ?> (<?php echo htmlspecialchars($res['building_name']); ?>)</p>
                <p style="margin: 0; display: flex; align-items: center; gap: 0.6rem; color: var(--text-color);"><i class="fa-solid fa-circle-check" style="width: 18px; color: var(--text-muted);"></i> Status: <span class="<?php echo ($res['status'] ?? 'Active') == 'Active' ? 'mature-badge-emerald' : 'mature-badge-slate'; ?>" style="margin-left: 4px;"><?php echo ucfirst($res['status']); ?></span></p>
                <p style="margin: 0; display: flex; align-items: center; gap: 0.6rem; color: var(--text-color);"><i class="fa-solid fa-user-tag" style="width: 18px; color: var(--text-muted);"></i> Role: <span class="mature-badge-sky" style="margin-left: 4px;"><?php echo ucfirst($res['type']); ?></span></p>
                <?php if (!empty($res['registration_date'])): ?>
                    <p style="margin: 0; display: flex; align-items: center; gap: 0.6rem; color: var(--text-color);"><i class="fa-solid fa-calendar-day" style="width: 18px; color: var(--text-muted);"></i> Registered: <?php echo date('M d, Y', strtotime($res['registration_date'])); ?></p>
                <?php endif; ?>
            </div>
            
            <a href="../admin/generate_id?id=<?php echo $res['id']; ?>&type=resident" target="_blank" class="btn btn-primary" style="width: 100%; margin-top: 1.5rem; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; font-weight: 600; padding: 0.65rem 1rem; background: #7e22ce; border-color: #7e22ce;">
                <i class="fa-solid fa-id-card"></i> View Digital ID
            </a>
        </div>
    </div>
    
    <!-- RIGHT: Movement History -->
    <div>
        <div class="mature-card" style="padding: 1.75rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <div>
                    <h4 style="margin: 0; font-size: 1.15rem; font-weight: 700; color: var(--text-color); letter-spacing: -0.01em;">Relocation & Occupancy Timeline</h4>
                    <p style="margin: 0.2rem 0 0; font-size: 0.8rem; color: var(--text-muted);">Sequential log of flat assignments, transfers, and exit events in this zone.</p>
                </div>
            </div>
            
            <div class="timeline" style="position: relative; padding-left: 2rem; border-left: 2px solid var(--border-color); margin-left: 0.75rem;">
                <?php 
                $hist = $conn->query("
                    SELECT rh.*, f.number as flat_num, b.name as b_name, s.name as s_name 
                    FROM resident_history rh 
                    LEFT JOIN flats f ON rh.flat_id = f.id 
                    LEFT JOIN buildings b ON rh.building_id = b.id 
                    LEFT JOIN streets s ON rh.street_id = s.id 
                    WHERE rh.resident_id = $id 
                    ORDER BY rh.timestamp DESC
                ");
                
                if(!$hist || $hist->num_rows == 0): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 3rem 1rem;">
                        <i class="fa-solid fa-clock-rotate-left" style="font-size: 2rem; opacity: 0.4; display: block; margin-bottom: 0.5rem;"></i>
                        No movement records logged for this resident yet.
                    </p>
                <?php else: 
                    while($h = $hist->fetch_assoc()): ?>
                    <div class="timeline-item" style="position: relative; margin-bottom: 2rem;">
                        <span style="position: absolute; left: -2.7rem; top: 0.2rem; width: 1.4rem; height: 1.4rem; background: var(--card-bg); border: 2.5px solid <?php echo $h['action_type'] == 'Moved In' ? '#10b981' : '#ef4444'; ?>; border-radius: 50%; z-index: 1;"></span>
                        <div style="font-size: 0.78rem; color: var(--text-muted); margin-bottom: 0.25rem;">
                            <?php echo !empty($h['start_date']) ? date('M d, Y', strtotime($h['start_date'])) : '-'; ?> 
                            <span style="margin: 0 8px; opacity: 0.4;">&bull;</span> 
                            <?php echo date('h:i A', strtotime($h['timestamp'])); ?>
                        </div>
                        <div>
                            <span class="<?php echo $h['action_type'] == 'Moved In' ? 'mature-badge-emerald' : 'mature-badge-crimson'; ?>" style="font-size: 0.8rem; font-weight: 700;">
                                <?php echo htmlspecialchars($h['action_type']); ?>
                            </span>
                        </div>
                        <div style="margin-top: 0.5rem; font-size: 0.92rem; color: var(--text-color); font-weight: 500;">
                            <?php if(!empty($h['flat_num'])): ?>
                                <i class="fa-solid fa-location-dot" style="color: var(--text-muted); margin-right: 5px;"></i>
                                <?php echo "Flat " . htmlspecialchars($h['flat_num']) . ", " . htmlspecialchars($h['b_name'] ?? '') . " Building, " . htmlspecialchars($h['s_name'] ?? ''); ?>
                            <?php else: ?>
                                <i class="fa-solid fa-circle-question" style="color: var(--text-muted); margin-right: 5px;"></i> Property information unavailable
                            <?php endif; ?>
                        </div>
                        <?php if(!empty($h['reason_for_exit'])): ?>
                            <div style="margin-top: 0.6rem; padding: 0.75rem 1rem; background: rgba(148, 163, 184, 0.08); border-radius: 0.5rem; font-size: 0.84rem; color: var(--text-muted); border-left: 3px solid var(--border-color);">
                                <strong style="color: var(--text-color);">Notes:</strong> <?php echo htmlspecialchars($h['reason_for_exit']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; 
                endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
