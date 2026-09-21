<?php
// admin/property_details.php
require_once '../config.php';
require_once '../includes/auth_guard.php';
requireAdminAccess();

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

<div class="page-header-futuristic">
    <div>
        <h1 class="h4 font-bold text-slate-900 m-0"><i class="fa-solid fa-timeline me-2 text-secondary"></i> History & Ownership: <?php echo htmlspecialchars($display_name); ?></h1>
        <p class="text-secondary small">Comprehensive asset dossier, ownership transfer log, and tenancy activity records</p>
    </div>
    <div class="d-flex gap-2">
        <a href="properties" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Back to Properties</a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert mature-card p-3 mb-4 border-0" style="background: rgba(34, 197, 94, 0.1); border-left: 4px solid #16a34a !important; color: #15803d;">
        <i class="fa-solid fa-circle-check me-2"></i> <?php echo $message; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- LEFT: Property Dossier & Transfer Action -->
    <div class="col-12 col-lg-4">
        <div class="mature-card mb-4">
            <div class="mature-card-header">
                <h3 class="mature-card-title"><i class="fa-solid fa-circle-info text-secondary"></i> Asset Dossier</h3>
                <span class="id-chip"><?php echo htmlspecialchars($prop['custom_id'] ?? ''); ?></span>
            </div>
            <div class="mature-card-body">
                <div class="d-flex flex-column gap-2 small">
                    <div class="d-flex justify-content-between py-1 border-bottom border-light-subtle">
                        <span class="text-secondary">Custom ID</span>
                        <span class="font-monospace fw-semibold"><?php echo htmlspecialchars($prop['custom_id'] ?? '-'); ?></span>
                    </div>
                    <?php if ($type == 'flat'): ?>
                        <div class="d-flex justify-content-between py-1 border-bottom border-light-subtle">
                            <span class="text-secondary">Building</span>
                            <span class="fw-semibold text-slate-900"><?php echo htmlspecialchars($prop['building_name'] ?? '-'); ?></span>
                        </div>
                        <div class="d-flex justify-content-between py-1 border-bottom border-light-subtle">
                            <span class="text-secondary">Floor</span>
                            <span class="fw-semibold"><?php echo htmlspecialchars($prop['floor'] ?? '-'); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($type != 'street'): ?>
                        <div class="d-flex justify-content-between py-1 border-bottom border-light-subtle">
                            <span class="text-secondary">Street</span>
                            <span class="fw-semibold"><?php echo htmlspecialchars($prop['street_name'] ?? '-'); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between py-1 border-bottom border-light-subtle">
                        <span class="text-secondary">Operational Status</span>
                        <?php 
                        $pst = strtolower($prop['status'] ?? 'active');
                        $p_badge = ($pst === 'active' || $pst === 'occupied') ? 'mature-badge-emerald' : (($pst === 'maintenance') ? 'mature-badge-amber' : 'mature-badge-slate');
                        ?>
                        <span class="mature-badge <?php echo $p_badge; ?>">
                            <?php echo htmlspecialchars(ucfirst($prop['status'] ?? 'Active')); ?>
                        </span>
                    </div>
                    <?php if (!empty($prop['registration_date'])): ?>
                        <div class="d-flex justify-content-between py-1">
                            <span class="text-secondary">Registration Date</span>
                            <span class="text-slate-900"><?php echo date('M d, Y', strtotime($prop['registration_date'])); ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($type != 'street'): ?>
                <hr class="my-4">
                <h6 class="fw-bold text-slate-900 mb-3" style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.04em;">
                    <i class="fa-solid fa-file-signature text-secondary me-1"></i> Transfer Ownership
                </h6>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Designated Owner</label>
                        <select name="owner_id" required class="form-select">
                            <?php 
                            $owners = $conn->query("SELECT id, full_name, custom_id, owner_type FROM property_owners WHERE estate_id=$estate_id ORDER BY full_name");
                            while($o = $owners->fetch_assoc()): 
                                $scope_label = (($o['owner_type'] ?? '') === 'flat') ? 'Flat Owner' : 'Building Owner';
                            ?>
                                <option value="<?php echo $o['id']; ?>"><?php echo htmlspecialchars($o['full_name'] . ' [' . $scope_label . '] (' . $o['custom_id'] . ')'); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Effective Date</label>
                        <input type="date" name="transfer_date" required value="<?php echo date('Y-m-d'); ?>" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Acquisition Type</label>
                        <select name="acq_type" class="form-select">
                            <?php 
                            $acq_types = $conn->query("SELECT * FROM property_acquisition_types ORDER BY name ASC");
                            while($at = $acq_types->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($at['name']); ?>"><?php echo htmlspecialchars($at['name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Supporting Legal Reference / Notes</label>
                        <textarea name="notes" placeholder="e.g. Title deed reference, Deed of Assignment..." class="form-control" rows="3"></textarea>
                    </div>
                    <button type="submit" name="transfer_ownership" class="btn btn-sm text-white w-100" style="background: #0f172a;" onclick="return confirm('Ensure legal supporting documents are verified. Proceed with transfer?')">
                        <i class="fa-solid fa-arrow-right-arrow-left me-1"></i> Execute Transfer
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- RIGHT: Timeline & Records -->
    <div class="col-12 col-lg-8">
        <div class="futuristic-tabs mb-3">
            <button class="futuristic-tab-btn tab-btn active" onclick="openPropTab(event, 'ownership')">
                <i class="fa-solid fa-user-shield"></i> Ownership History
            </button>
            <?php if($type == 'flat'): ?>
                <button class="futuristic-tab-btn tab-btn" onclick="openPropTab(event, 'residents')">
                    <i class="fa-solid fa-users"></i> Resident Movement
                </button>
            <?php endif; ?>
            <button class="futuristic-tab-btn tab-btn" onclick="openPropTab(event, 'updates')">
                <i class="fa-solid fa-clock-rotate-left"></i> System Modifications
            </button>
        </div>

        <!-- Ownership Tab -->
        <div id="ownership" class="prop-tab-content">
            <div class="futuristic-table-card">
                <div class="futuristic-table-card-header">
                    <h3 class="m-0 fw-bold fs-6 text-slate-900"><i class="fa-solid fa-user-shield text-secondary me-2"></i> Ownership Records</h3>
                </div>
                <div class="table-responsive">
                    <table class="table dashboard-table align-middle">
                        <thead>
                            <tr>
                                <th>Owner</th>
                                <th>Tenancy Period</th>
                                <th>Acquisition</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $own_hist = $conn->query("SELECT po.*, o.full_name, o.custom_id as o_cid FROM property_ownership po JOIN property_owners o ON po.owner_id = o.id WHERE po.property_id=$id AND po.property_type='$type' AND po.estate_id=$estate_id ORDER BY po.start_date DESC");
                            if(!$own_hist || $own_hist->num_rows == 0): ?>
                                <tr><td colspan="4" class="text-center py-4 text-secondary">No ownership records registered yet.</td></tr>
                            <?php else: 
                                while($o = $own_hist->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold text-slate-900"><?php echo htmlspecialchars($o['full_name']); ?></div>
                                        <span class="id-chip"><?php echo htmlspecialchars($o['o_cid']); ?></span>
                                    </td>
                                    <td>
                                        <span class="small"><?php echo date('M d, Y', strtotime($o['start_date'])); ?></span>
                                        <i class="fa-solid fa-arrow-right mx-1 text-secondary" style="font-size: 0.65rem;"></i>
                                        <span class="small"><?php echo $o['end_date'] ? date('M d, Y', strtotime($o['end_date'])) : 'Present'; ?></span>
                                    </td>
                                    <td><small class="text-secondary"><?php echo htmlspecialchars($o['acquisition_type'] ?: 'Direct Purchase'); ?></small></td>
                                    <td>
                                        <?php if(!$o['end_date']): ?>
                                            <span class="mature-badge mature-badge-emerald">Current</span>
                                        <?php else: ?>
                                            <span class="mature-badge mature-badge-slate">Past</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; 
                            endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Residents Tab -->
        <?php if($type == 'flat'): ?>
        <div id="residents" class="prop-tab-content" style="display: none;">
            <div class="futuristic-table-card">
                <div class="futuristic-table-card-header">
                    <h3 class="m-0 fw-bold fs-6 text-slate-900"><i class="fa-solid fa-users text-secondary me-2"></i> Resident Movement Timeline</h3>
                </div>
                <div class="table-responsive">
                    <table class="table dashboard-table align-middle">
                        <thead>
                            <tr>
                                <th>Resident</th>
                                <th>Action</th>
                                <th>Date</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $res_hist = $conn->query("SELECT rh.*, u.name FROM resident_history rh JOIN residents r ON rh.resident_id = r.id JOIN users u ON r.user_id = u.id WHERE rh.flat_id=$id AND rh.estate_id=$estate_id ORDER BY rh.timestamp DESC");
                            if(!$res_hist || $res_hist->num_rows == 0): ?>
                                <tr><td colspan="4" class="text-center py-4 text-secondary">No resident movement history recorded.</td></tr>
                            <?php else: 
                                while($r = $res_hist->fetch_assoc()): ?>
                                <tr>
                                    <td><span class="fw-semibold text-slate-900"><?php echo htmlspecialchars($r['name']); ?></span></td>
                                    <td>
                                        <?php 
                                        $act = $r['action_type'];
                                        $act_badge = ($act == 'Moved In') ? 'mature-badge-emerald' : 'mature-badge-crimson';
                                        ?>
                                        <span class="mature-badge <?php echo $act_badge; ?>">
                                            <?php echo htmlspecialchars($act); ?>
                                        </span>
                                    </td>
                                    <td><span class="small text-secondary"><?php echo date('M d, Y', strtotime($r['start_date'])); ?></span></td>
                                    <td><small class="text-secondary"><?php echo htmlspecialchars($r['reason_for_exit'] ?: '-'); ?></small></td>
                                </tr>
                                <?php endwhile; 
                            endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Updates Tab -->
        <div id="updates" class="prop-tab-content" style="display: none;">
            <div class="futuristic-table-card">
                <div class="futuristic-table-card-header">
                    <h3 class="m-0 fw-bold fs-6 text-slate-900"><i class="fa-solid fa-clock-rotate-left text-secondary me-2"></i> Modification Trail</h3>
                </div>
                <div class="table-responsive">
                    <table class="table dashboard-table align-middle">
                        <thead>
                            <tr>
                                <th>Action</th>
                                <th>Values</th>
                                <th>Admin</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $prop_hist = $conn->query("SELECT ph.*, u.name as admin_name FROM property_history ph LEFT JOIN users u ON ph.changed_by = u.id WHERE ph.property_id=$id AND ph.property_type='$type' AND ph.estate_id=$estate_id ORDER BY ph.change_date DESC");
                            if(!$prop_hist || $prop_hist->num_rows == 0): ?>
                                <tr><td colspan="4" class="text-center py-4 text-secondary">No property update events registered.</td></tr>
                            <?php else: 
                                while($p = $prop_hist->fetch_assoc()): ?>
                                <tr>
                                    <td><span class="fw-semibold text-slate-900"><?php echo htmlspecialchars($p['action_type']); ?></span></td>
                                    <td>
                                        <?php if($p['old_value']): ?>
                                            <del class="text-secondary small"><?php echo htmlspecialchars($p['old_value']); ?></del>
                                            <i class="fa-solid fa-arrow-right mx-1 text-secondary" style="font-size: 0.65rem;"></i>
                                        <?php endif; ?>
                                        <span class="fw-semibold"><?php echo htmlspecialchars($p['new_value']); ?></span>
                                        <?php if($p['notes']): ?><div class="small text-secondary"><?php echo htmlspecialchars($p['notes']); ?></div><?php endif; ?>
                                    </td>
                                    <td><span class="id-chip"><?php echo htmlspecialchars($p['admin_name'] ?? 'System'); ?></span></td>
                                    <td><span class="small text-secondary"><?php echo date('M d, H:i', strtotime($p['change_date'])); ?></span></td>
                                </tr>
                                <?php endwhile; 
                            endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function openPropTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName("prop-tab-content");
    for (i = 0; i < tabcontent.length; i++) {
        tabcontent[i].style.display = "none";
    }
    tablinks = document.querySelectorAll(".futuristic-tab-btn");
    for (i = 0; i < tablinks.length; i++) {
        tablinks[i].classList.remove("active");
    }
    const target = document.getElementById(tabName);
    if (target) target.style.display = "block";
    evt.currentTarget.classList.add("active");
}
</script>

<?php include '../includes/footer.php'; ?>
