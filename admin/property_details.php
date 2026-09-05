<?php
// admin/property_details.php
require_once '../config.php';
include '../includes/header.php';
include '../includes/sidebar.php';

$type = $_GET['type'] ?? ''; // street, building, flat
$id = intval($_GET['id'] ?? 0);

if (!$type || !$id) {
    echo "Invalid property request.";
    include '../includes/footer.php';
    exit;
}

$message = "";
$estate_id = get_estate_id();
// Handle Ownership Transfer
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['transfer_ownership'])) {
    $owner_id = intval($_POST['owner_id']);
    $start_date = $_POST['transfer_date'];
    $acq_type = $_POST['acq_type'];
    $notes = $conn->real_escape_string($_POST['notes']);
    
    // 1. End current ownership for this property
    $conn->query("UPDATE property_ownership SET end_date='$start_date' WHERE property_id=$id AND property_type='$type' AND end_date IS NULL AND estate_id=$estate_id");
    
    // 2. Add new ownership
    $sql = "INSERT INTO property_ownership (estate_id, property_id, property_type, owner_id, start_date, acquisition_type, supporting_docs) 
            VALUES ($estate_id, $id, '$type', $owner_id, '$start_date', '$acq_type', '$notes')";
    
    if ($conn->query($sql)) {
        $message = "Ownership transferred successfully!";
        // Log to property history
        $owner_name = $conn->query("SELECT full_name FROM property_owners WHERE id=$owner_id AND estate_id=$estate_id")->fetch_assoc()['full_name'];
        $sql_hist = "INSERT INTO property_history (estate_id, property_id, property_type, action_type, new_value, changed_by, notes) 
                    VALUES ($estate_id, $id, '$type', 'Ownership Changed', '$owner_name', " . ($_SESSION['user_id'] ?? 'NULL') . ", 'Transferred to $owner_name via $acq_type')";
        $conn->query($sql_hist);
    } else {
        $message = "Error: " . $conn->error;
    }
}

// Fetch Property Details
$prop = null;
if ($type == 'street') $prop = $conn->query("SELECT * FROM streets WHERE id=$id AND estate_id=$estate_id")->fetch_assoc();
elseif ($type == 'building') $prop = $conn->query("SELECT b.*, s.name as street_name FROM buildings b JOIN streets s ON b.street_id = s.id WHERE b.id=$id AND b.estate_id=$estate_id")->fetch_assoc();
elseif ($type == 'flat') $prop = $conn->query("SELECT f.*, b.name as building_name, s.name as street_name FROM flats f JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE f.id=$id AND f.estate_id=$estate_id")->fetch_assoc();

if (!$prop) {
    echo "Property not found.";
    include '../includes/footer.php';
    exit;
}

$display_name = $prop['name'] ?? ('Flat ' . $prop['number']);
?>

<div class="page-header">
    <h1><i class="fa-solid fa-timeline"></i> History & Ownership: <?php echo htmlspecialchars($display_name); ?></h1>
    <a href="properties" class="btn" style="background: #f1f5f9; color: #475569;"><i class="fa-solid fa-arrow-left"></i> Back</a>
</div>

<?php if ($message): ?>
    <div class="alert" style="background: #dcfce7; color: #166534; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 2fr; gap: 2rem;">
    <!-- LEFT: Details & Transfer -->
    <div>
        <div class="glass" style="padding: 1.5rem; border-radius: 0.5rem; margin-bottom: 2rem;">
            <h4 style="margin-top:0;">Property Details</h4>
            <div style="font-size: 0.9rem; line-height: 1.6;">
                <p><strong>ID:</strong> <?php echo $prop['custom_id']; ?></p>
                <?php if ($type == 'flat'): ?>
                    <p><strong>Building:</strong> <?php echo $prop['building_name']; ?></p>
                    <p><strong>Floor:</strong> <?php echo $prop['floor']; ?></p>
                <?php endif; ?>
                <?php if ($type != 'street'): ?>
                    <p><strong>Street:</strong> <?php echo $prop['street_name']; ?></p>
                <?php endif; ?>
                <p><strong>Status:</strong> <?php echo $prop['status']; ?></p>
                <?php if ($prop['registration_date']): ?>
                    <p><strong>Registration Date:</strong> <?php echo date('M d, Y', strtotime($prop['registration_date'])); ?></p>
                <?php endif; ?>
            </div>
            
            <?php if ($type != 'street'): ?>
            <hr style="margin: 1.5rem 0; border: none; border-top: 1px solid #e2e8f0;">
            <h4 style="margin-top:0;">Transfer Ownership</h4>
            <form method="POST">
                <div style="margin-bottom: 1rem;">
                    <label>New Owner</label>
                    <select name="owner_id" required style="width:100%; padding:0.5rem; border:1px solid #cbd5e1; border-radius:0.375rem;">
                        <?php 
                        $owners = $conn->query("SELECT id, full_name, custom_id, owner_type FROM property_owners WHERE estate_id=$estate_id ORDER BY full_name");
                        while($o = $owners->fetch_assoc()): 
                            $scope_label = (($o['owner_type'] ?? '') === 'flat') ? 'Flat Owner' : 'Building Owner';
                        ?>
                            <option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['full_name'] . ' [' . $scope_label . '] (' . $o['custom_id'] . ')'); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div style="margin-bottom: 1rem;">
                    <label>Transfer Date</label>
                    <input type="date" name="transfer_date" required value="<?php echo date('Y-m-d'); ?>" style="width:100%; padding:0.5rem; border:1px solid #cbd5e1; border-radius:0.375rem;">
                </div>
                <div style="margin-bottom: 1rem;">
                    <label>Transfer Type</label>
                    <select name="acq_type" style="width:100%; padding:0.5rem; border:1px solid #cbd5e1; border-radius:0.375rem;">
                        <?php 
                        $acq_types = $conn->query("SELECT * FROM property_acquisition_types ORDER BY name ASC");
                        while($at = $acq_types->fetch_assoc()): ?>
                            <option value="<?php echo htmlspecialchars($at['name']); ?>"><?php echo htmlspecialchars($at['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div style="margin-bottom: 1rem;">
                    <label>Supporting Documents / Notes</label>
                    <textarea name="notes" placeholder="e.g. Sale agreement reference..." style="width:100%; padding:0.5rem; border:1px solid #cbd5e1; border-radius:0.375rem;"></textarea>
                </div>
                <button type="submit" name="transfer_ownership" class="btn btn-primary" style="width:100%;" onclick="return confirm('Ensure you have verified legal documents. Proceed?')">Execute Transfer</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- RIGHT: Timeline & History -->
    <div>
        <div class="tabs" style="display: flex; gap: 1rem; border-bottom: 2px solid #e2e8f0; margin-bottom: 1.5rem;">
            <button class="tab-btn active" onclick="openPropTab(event, 'ownership')">Ownership History</button>
            <?php if($type == 'flat'): ?>
                <button class="tab-btn" onclick="openPropTab(event, 'residents')">Resident Timeline</button>
            <?php endif; ?>
            <button class="tab-btn" onclick="openPropTab(event, 'updates')">Property Changes</button>
        </div>

        <div id="ownership" class="prop-tab-content">
            <div class="glass" style="padding: 1.5rem; border-radius: 0.5rem;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="text-align: left; color: #64748b; font-size: 0.85rem;">
                            <th style="padding: 0.75rem;">Owner</th>
                            <th style="padding: 0.75rem;">Period</th>
                            <th style="padding: 0.75rem;">Type</th>
                            <th style="padding: 0.75rem;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $own_hist = $conn->query("SELECT po.*, o.full_name, o.custom_id as o_cid FROM property_ownership po JOIN property_owners o ON po.owner_id = o.id WHERE po.property_id=$id AND po.property_type='$type' AND po.estate_id=$estate_id ORDER BY po.start_date DESC");
                        if($own_hist->num_rows == 0): ?>
                            <tr><td colspan="4" style="text-align:center; padding: 2rem; color: #94a3b8;">No ownership records found.</td></tr>
                        <?php else: 
                            while($o = $own_hist->fetch_assoc()): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 0.75rem;">
                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($o['full_name']); ?></div>
                                    <div style="font-size: 0.75rem; color: #94a3b8;"><?php echo $o['o_cid']; ?></div>
                                </td>
                                <td style="padding: 0.75rem;">
                                    <span style="font-size: 0.85rem;"><?php echo date('M d, Y', strtotime($o['start_date'])); ?></span>
                                    <i class="fa-solid fa-arrow-right" style="font-size: 0.7rem; margin: 0 5px; color: #cbd5e1;"></i>
                                    <span style="font-size: 0.85rem;"><?php echo $o['end_date'] ? date('M d, Y', strtotime($o['end_date'])) : 'Present'; ?></span>
                                </td>
                                <td style="padding: 0.75rem; font-size: 0.85rem;"><?php echo $o['acquisition_type']; ?></td>
                                <td style="padding: 0.75rem;">
                                    <?php if(!$o['end_date']): ?>
                                        <span style="background: #dcfce7; color: #166534; padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: 600;">CURRENT</span>
                                    <?php else: ?>
                                        <span style="background: #f1f5f9; color: #64748b; padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: 600;">PAST</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; 
                        endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="residents" class="prop-tab-content" style="display: none;">
            <div class="glass" style="padding: 1.5rem; border-radius: 0.5rem;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="text-align: left; color: #64748b; font-size: 0.85rem;">
                            <th style="padding: 0.75rem;">Resident</th>
                            <th style="padding: 0.75rem;">Action</th>
                            <th style="padding: 0.75rem;">Date</th>
                            <th style="padding: 0.75rem;">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $res_hist = $conn->query("SELECT rh.*, u.name FROM resident_history rh JOIN residents r ON rh.resident_id = r.id JOIN users u ON r.user_id = u.id WHERE rh.flat_id=$id AND rh.estate_id=$estate_id ORDER BY rh.timestamp DESC");
                        if($res_hist->num_rows == 0): ?>
                            <tr><td colspan="4" style="text-align:center; padding: 2rem; color: #94a3b8;">No movement history found.</td></tr>
                        <?php else: 
                            while($r = $res_hist->fetch_assoc()): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 0.75rem; font-weight: 500; font-size: 0.85rem;"><?php echo htmlspecialchars($r['name']); ?></td>
                                <td style="padding: 0.75rem;">
                                    <span style="background: <?php echo $r['action_type'] == 'Moved In' ? '#dcfce7' : '#fee2e2'; ?>; color: <?php echo $r['action_type'] == 'Moved In' ? '#166534' : '#991b1b'; ?>; padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: 600; text-transform: uppercase;">
                                        <?php echo $r['action_type']; ?>
                                    </span>
                                </td>
                                <td style="padding: 0.75rem; font-size: 0.85rem;"><?php echo date('M d, Y', strtotime($r['start_date'])); ?></td>
                                <td style="padding: 0.75rem; font-size: 0.8rem; color: #64748b;"><?php echo htmlspecialchars($r['reason_for_exit']); ?></td>
                            </tr>
                            <?php endwhile; 
                        endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="updates" class="prop-tab-content" style="display: none;">
            <div class="glass" style="padding: 1.5rem; border-radius: 0.5rem;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="text-align: left; color: #64748b; font-size: 0.85rem;">
                            <th style="padding: 0.75rem;">Action</th>
                            <th style="padding: 0.75rem;">Changes</th>
                            <th style="padding: 0.75rem;">By</th>
                            <th style="padding: 0.75rem;">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $prop_hist = $conn->query("SELECT ph.*, u.name as admin_name FROM property_history ph LEFT JOIN users u ON ph.changed_by = u.id WHERE ph.property_id=$id AND ph.property_type='$type' AND ph.estate_id=$estate_id ORDER BY ph.change_date DESC");
                        if($prop_hist->num_rows == 0): ?>
                            <tr><td colspan="4" style="text-align:center; padding: 2rem; color: #94a3b8;">No update history found.</td></tr>
                        <?php else: 
                            while($p = $prop_hist->fetch_assoc()): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 0.75rem; font-weight: 500; font-size: 0.85rem;"><?php echo $p['action_type']; ?></td>
                                <td style="padding: 0.75rem; font-size: 0.8rem;">
                                    <?php if($p['old_value']): ?>
                                        <del style="color: #94a3b8;"><?php echo htmlspecialchars($p['old_value']); ?></del>
                                        <i class="fa-solid fa-arrow-right" style="margin: 0 5px; font-size: 0.7rem;"></i>
                                    <?php endif; ?>
                                    <strong><?php echo htmlspecialchars($p['new_value']); ?></strong>
                                    <?php if($p['notes']): ?><div style="font-size: 0.7rem; color: #64748b; margin-top:2px;"><?php echo htmlspecialchars($p['notes']); ?></div><?php endif; ?>
                                </td>
                                <td style="padding: 0.75rem; font-size: 0.8rem;"><?php echo htmlspecialchars($p['admin_name'] ?? 'System'); ?></td>
                                <td style="padding: 0.75rem; font-size: 0.8rem;"><?php echo date('M d, h:i A', strtotime($p['change_date'])); ?></td>
                            </tr>
                            <?php endwhile; 
                        endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
.tab-btn {
    padding: 0.75rem 1rem;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    color: #64748b;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.tab-btn.active {
    color: var(--primary-color);
    border-bottom-color: var(--primary-color);
}
.tab-btn:hover:not(.active) {
    color: #334155;
    background: #f8fafc;
}
</style>

<script>
function openPropTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName("prop-tab-content");
    for (i = 0; i < tabcontent.length; i++) {
        tabcontent[i].style.display = "none";
    }
    tablinks = document.getElementsByClassName("tab-btn");
    for (i = 0; i < tablinks.length; i++) {
        tablinks[i].className = tablinks[i].className.replace(" active", "");
    }
    document.getElementById(tabName).style.display = "block";
    evt.currentTarget.className += " active";
}
</script>

<?php include '../includes/footer.php'; ?>
