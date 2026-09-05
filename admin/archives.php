<?php
// admin/archives.php
require_once '../config.php';
include '../includes/header.php';
include '../includes/sidebar.php';

$message = "";

// Handle Restoration
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['restore_item'])) {
    $table = $_POST['table'];
    $item_id = intval($_POST['id']);
    $status_col = ($_POST['table'] == 'residents' || $_POST['table'] == 'estate_staff' || $_POST['table'] == 'household_staff') ? 'status' : 'status'; 
    // Actually most use 'status'. Flats use 'status' too but 'vacant' is the active-ish state for empty flats.
    // However, the user said "Archive", and we set buildings/streets to 'archived'.
    
    $new_status = 'active';
    if ($table == 'flats') $new_status = 'vacant';
    
    // Exception for domestic staff status which might be 'Daily' etc. but usually 'inactive' was the archive state.
    if ($table == 'household_staff') $new_status = 'Daily'; // Defaulting back to Daily if restored? Or should we have a previous_status?
    // For residents, 'active' is the opposite of 'inactive'.
    
    $estate_id = get_estate_id();
    $sql = "UPDATE $table SET status = '$new_status' WHERE id = $item_id AND estate_id = $estate_id";
    if ($conn->query($sql)) {
        $message = "Item restored successfully!";
        logAudit($conn, $_SESSION['user_id'] ?? 0, 'Restore', "Restored ID $item_id from table $table");
    } else {
        $message = "Error: " . $conn->error;
    }
}

// Fetch Archived Items
$estate_id = get_estate_id();
$archived_streets = $conn->query("SELECT * FROM streets WHERE status = 'archived' AND estate_id = $estate_id ORDER BY name");
$archived_buildings = $conn->query("SELECT b.*, s.name as street_name FROM buildings b JOIN streets s ON b.street_id = s.id WHERE b.status = 'archived' AND b.estate_id = $estate_id ORDER BY b.name");
$archived_flats = $conn->query("SELECT f.*, b.name as building_name, s.name as street_name FROM flats f JOIN buildings b ON f.building_id = b.id JOIN streets s ON b.street_id = s.id WHERE f.status = 'archived' AND f.estate_id = $estate_id ORDER BY f.number");
$archived_residents = $conn->query("SELECT r.*, u.name, u.email FROM residents r JOIN users u ON r.user_id = u.id WHERE r.status = 'inactive' AND r.estate_id = $estate_id ORDER BY u.name");
$archived_domestic = $conn->query("SELECT * FROM household_staff WHERE status = 'inactive' AND estate_id = $estate_id ORDER BY name");
$archived_estate = $conn->query("SELECT s.*, u.name FROM estate_staff s JOIN users u ON s.user_id = u.id WHERE s.status = 'inactive' AND s.estate_id = $estate_id ORDER BY u.name");

?>

<div class="page-header">
    <h1><i class="fa-solid fa-box-archive"></i> Centralized Archives</h1>
</div>

<?php if ($message): ?>
    <div class="alert" style="background: #dcfce7; color: #166534; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<div class="glass" style="padding: 2rem; border-radius: 0.5rem;">
    <div class="tabs" style="display: flex; gap: 1rem; border-bottom: 2px solid #e2e8f0; margin-bottom: 1.5rem;">
        <button class="tab-btn active" onclick="openArchTab(event, 'prop-archives')">Property Archives</button>
        <button class="tab-btn" onclick="openArchTab(event, 'res-archives')">Resident Archives</button>
        <button class="tab-btn" onclick="openArchTab(event, 'staff-archives')">Staff Archives</button>
    </div>

    <!-- Property Archives -->
    <div id="prop-archives" class="tab-content">
        <h4 style="margin-top:0;">Archived Streets</h4>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 2rem;">
            <thead>
                <tr style="text-align: left; color: #64748b; border-bottom: 1px solid #e2e8f0;">
                    <th style="padding: 0.75rem;">Name</th>
                    <th style="padding: 0.75rem;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $archived_streets->fetch_assoc()): ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 0.75rem;"><?php echo htmlspecialchars($row['name']); ?></td>
                    <td style="padding: 0.75rem;">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="table" value="streets">
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="restore_item" class="btn" style="background: #eff6ff; color: #2563eb; padding: 0.25rem 0.75rem;"><i class="fa-solid fa-trash-arrow-up"></i> Restore</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if($archived_streets->num_rows == 0) echo "<tr><td colspan='2' style='padding:1rem; text-align:center; color:#94a3b8;'>No archived streets found.</td></tr>"; ?>
            </tbody>
        </table>

        <h4>Archived Buildings</h4>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 2rem;">
            <thead>
                <tr style="text-align: left; color: #64748b; border-bottom: 1px solid #e2e8f0;">
                    <th style="padding: 0.75rem;">Name</th>
                    <th style="padding: 0.75rem;">Street</th>
                    <th style="padding: 0.75rem;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $archived_buildings->fetch_assoc()): ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 0.75rem;"><?php echo htmlspecialchars($row['name']); ?></td>
                    <td style="padding: 0.75rem;"><?php echo htmlspecialchars($row['street_name']); ?></td>
                    <td style="padding: 0.75rem;">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="table" value="buildings">
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="restore_item" class="btn" style="background: #eff6ff; color: #2563eb; padding: 0.25rem 0.75rem;"><i class="fa-solid fa-trash-arrow-up"></i> Restore</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if($archived_buildings->num_rows == 0) echo "<tr><td colspan='3' style='padding:1rem; text-align:center; color:#94a3b8;'>No archived buildings found.</td></tr>"; ?>
            </tbody>
        </table>

        <h4>Archived Flats</h4>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; color: #64748b; border-bottom: 1px solid #e2e8f0;">
                    <th style="padding: 0.75rem;">Number</th>
                    <th style="padding: 0.75rem;">Building</th>
                    <th style="padding: 0.75rem;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $archived_flats->fetch_assoc()): ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 0.75rem;">Flat <?php echo htmlspecialchars($row['number']); ?></td>
                    <td style="padding: 0.75rem;"><?php echo htmlspecialchars($row['building_name']); ?></td>
                    <td style="padding: 0.75rem;">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="table" value="flats">
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="restore_item" class="btn" style="background: #eff6ff; color: #2563eb; padding: 0.25rem 0.75rem;"><i class="fa-solid fa-trash-arrow-up"></i> Restore</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if($archived_flats->num_rows == 0) echo "<tr><td colspan='3' style='padding:1rem; text-align:center; color:#94a3b8;'>No archived flats found.</td></tr>"; ?>
            </tbody>
        </table>
    </div>

    <!-- Resident Archives -->
    <div id="res-archives" class="tab-content" style="display: none;">
        <h4 style="margin-top:0;">Archived Residents</h4>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; color: #64748b; border-bottom: 1px solid #e2e8f0;">
                    <th style="padding: 0.75rem;">Name</th>
                    <th style="padding: 0.75rem;">Email</th>
                    <th style="padding: 0.75rem;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $archived_residents->fetch_assoc()): ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 0.75rem;"><?php echo htmlspecialchars($row['name']); ?></td>
                    <td style="padding: 0.75rem;"><?php echo htmlspecialchars($row['email']); ?></td>
                    <td style="padding: 0.75rem;">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="table" value="residents">
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="restore_item" class="btn" style="background: #eff6ff; color: #2563eb; padding: 0.25rem 0.75rem;"><i class="fa-solid fa-trash-arrow-up"></i> Restore</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if($archived_residents->num_rows == 0) echo "<tr><td colspan='3' style='padding:1rem; text-align:center; color:#94a3b8;'>No archived residents found.</td></tr>"; ?>
            </tbody>
        </table>
    </div>

    <!-- Staff Archives -->
    <div id="staff-archives" class="tab-content" style="display: none;">
        <h4 style="margin-top:0;">Archived Domestic Staff</h4>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 2rem;">
            <thead>
                <tr style="text-align: left; color: #64748b; border-bottom: 1px solid #e2e8f0;">
                    <th style="padding: 0.75rem;">Name</th>
                    <th style="padding: 0.75rem;">Role</th>
                    <th style="padding: 0.75rem;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $archived_domestic->fetch_assoc()): ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 0.75rem;"><?php echo htmlspecialchars($row['name']); ?></td>
                    <td style="padding: 0.75rem;"><?php echo htmlspecialchars($row['role']); ?></td>
                    <td style="padding: 0.75rem;">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="table" value="household_staff">
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="restore_item" class="btn" style="background: #eff6ff; color: #2563eb; padding: 0.25rem 0.75rem;"><i class="fa-solid fa-trash-arrow-up"></i> Restore</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if($archived_domestic->num_rows == 0) echo "<tr><td colspan='3' style='padding:1rem; text-align:center; color:#94a3b8;'>No archived domestic staff found.</td></tr>"; ?>
            </tbody>
        </table>

        <h4>Archived Estate Staff</h4>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; color: #64748b; border-bottom: 1px solid #e2e8f0;">
                    <th style="padding: 0.75rem;">Name</th>
                    <th style="padding: 0.75rem;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $archived_estate->fetch_assoc()): ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 0.75rem;"><?php echo htmlspecialchars($row['name']); ?></td>
                    <td style="padding: 0.75rem;">
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="table" value="estate_staff">
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="restore_item" class="btn" style="background: #eff6ff; color: #2563eb; padding: 0.25rem 0.75rem;"><i class="fa-solid fa-trash-arrow-up"></i> Restore</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if($archived_estate->num_rows == 0) echo "<tr><td colspan='2' style='padding:1rem; text-align:center; color:#94a3b8;'>No archived estate staff found.</td></tr>"; ?>
            </tbody>
        </table>
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
}
.tab-btn.active {
    color: var(--primary-color);
    border-bottom-color: var(--primary-color);
}
.tab-content { padding-top: 1rem; }
h4 { color: #1e293b; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px dashed #e2e8f0; }
</style>

<script>
function openArchTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName("tab-content");
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
