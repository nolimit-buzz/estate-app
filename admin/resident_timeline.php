<?php
// admin/resident_timeline.php
require_once '../config.php';
include '../includes/header.php';
include '../includes/sidebar.php';

$id = intval($_GET['id'] ?? 0);

if (!$id) {
    echo "Invalid resident request.";
    include '../includes/footer.php';
    exit;
}

// Fetch Resident Details
$res = $conn->query("SELECT r.*, u.name, u.email, u.phone FROM residents r JOIN users u ON r.user_id = u.id WHERE r.id=$id")->fetch_assoc();

if (!$res) {
    echo "Resident not found.";
    include '../includes/footer.php';
    exit;
}
?>

<div class="page-header">
    <h1><i class="fa-solid fa-user-clock"></i> Resident Timeline: <?php echo htmlspecialchars($res['name']); ?></h1>
    <a href="residents" class="btn" style="background: #f1f5f9; color: #475569;"><i class="fa-solid fa-arrow-left"></i> Back</a>
</div>

<div style="display: grid; grid-template-columns: 1fr 2fr; gap: 2rem;">
    <!-- LEFT: Profile Summary -->
    <div>
        <div class="glass" style="padding: 1.5rem; border-radius: 0.5rem; text-align: center;">
            <?php if($res['image_path']): ?>
                <img src="<?php echo htmlspecialchars($res['image_path']); ?>" style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; margin-bottom: 1rem; border: 4px solid #fff; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);">
            <?php else: ?>
                <div style="width: 120px; height: 120px; border-radius: 50%; background: #e2e8f0; margin: 0 auto 1rem; display: flex; align-items: center; justify-content: center; font-size: 3rem; color: #94a3b8;">
                    <i class="fa-solid fa-user"></i>
                </div>
            <?php endif; ?>
            <h3><?php echo htmlspecialchars($res['name']); ?></h3>
            <p style="color: #64748b; font-size: 0.9rem; margin-top: -0.5rem;"><?php echo $res['custom_id']; ?></p>
            
            <div style="text-align: left; margin-top: 1.5rem; font-size: 0.9rem; line-height: 1.8;">
                <p><i class="fa-solid fa-envelope" style="width: 20px; color: #94a3b8;"></i> <?php echo htmlspecialchars($res['email']); ?></p>
                <p><i class="fa-solid fa-phone" style="width: 20px; color: #94a3b8;"></i> <?php echo htmlspecialchars($res['phone']); ?></p>
                <p><i class="fa-solid fa-circle-check" style="width: 20px; color: #94a3b8;"></i> Status: <strong><?php echo ucfirst($res['status']); ?></strong></p>
                <p><i class="fa-solid fa-user-tag" style="width: 20px; color: #94a3b8;"></i> Current Role: <?php echo ucfirst($res['type']); ?></p>
                <?php if ($res['registration_date']): ?>
                    <p><i class="fa-solid fa-calendar-day" style="width: 20px; color: #94a3b8;"></i> Registered: <?php echo date('M d, Y', strtotime($res['registration_date'])); ?></p>
                <?php endif; ?>
            </div>
            
            <a href="generate_id?id=<?php echo $res['id']; ?>&type=resident" target="_blank" class="btn btn-primary" style="width: 100%; margin-top: 1.5rem;"><i class="fa-solid fa-id-card"></i> View ID Card</a>
        </div>
    </div>
    
    <!-- RIGHT: Movement History -->
    <div>
        <div class="glass" style="padding: 1.5rem; border-radius: 0.5rem;">
            <h4 style="margin-top:0; margin-bottom: 1.5rem;">Movement History</h4>
            
            <div class="timeline" style="position: relative; padding-left: 2rem; border-left: 2px solid #e2e8f0; margin-left: 1rem;">
                <?php 
                $hist = $conn->query("SELECT rh.*, f.number as flat_num, b.name as b_name, s.name as s_name 
                                    FROM resident_history rh 
                                    LEFT JOIN flats f ON rh.flat_id = f.id 
                                    LEFT JOIN buildings b ON rh.building_id = b.id 
                                    LEFT JOIN streets s ON rh.street_id = s.id 
                                    WHERE rh.resident_id = $id 
                                    ORDER BY rh.timestamp DESC");
                
                if($hist->num_rows == 0): ?>
                    <p style="color: #94a3b8; text-align: center; padding: 2rem;">No movement records found.</p>
                <?php else: 
                    while($h = $hist->fetch_assoc()): ?>
                    <div class="timeline-item" style="position: relative; margin-bottom: 2rem;">
                        <span style="position: absolute; left: -2.75rem; top: 0.25rem; width: 1.5rem; height: 1.5rem; background: #fff; border: 2px solid <?php echo $h['action_type'] == 'Moved In' ? '#22c55e' : '#ef4444'; ?>; border-radius: 50%; z-index: 1;"></span>
                        <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 0.25rem;">
                            <?php echo date('M d, Y', strtotime($h['start_date'])); ?> 
                            <span style="margin: 0 10px; opacity: 0.3;">|</span> 
                            <?php echo date('h:i A', strtotime($h['timestamp'])); ?>
                        </div>
                        <div style="font-weight: 600; font-size: 1.1rem; color: <?php echo $h['action_type'] == 'Moved In' ? '#166534' : '#991b1b'; ?>;">
                            <?php echo $h['action_type']; ?>
                        </div>
                        <div style="margin-top: 0.25rem; font-size: 0.95rem;">
                            <?php if($h['flat_num']): ?>
                                <i class="fa-solid fa-location-dot" style="color: #94a3b8; margin-right: 5px;"></i>
                                <?php echo "Flat " . $h['flat_num'] . ", " . $h['b_name'] . " Building, " . $h['s_name']; ?>
                            <?php else: ?>
                                <i class="fa-solid fa-circle-question" style="color: #94a3b8; margin-right: 5px;"></i> Property information unavailable
                            <?php endif; ?>
                        </div>
                        <?php if($h['reason_for_exit']): ?>
                            <div style="margin-top: 0.5rem; padding: 0.75rem; background: #f8fafc; border-radius: 0.375rem; font-size: 0.85rem; color: #475569; border-left: 3px solid #cbd5e1;">
                                <strong>Notes:</strong> <?php echo htmlspecialchars($h['reason_for_exit']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; 
                endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
