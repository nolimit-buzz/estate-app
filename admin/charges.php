<?php
// admin/charges.php
require_once '../config.php';
include '../includes/header.php';
include '../includes/sidebar.php';

requirePermission('charges.manage');

$estate_id = get_estate_id();
$message = "";

// Handle Add / Edit / Delete Charge
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_charge'])) {
        $name = $conn->real_escape_string($_POST['name']);
        $desc = $conn->real_escape_string($_POST['description']);
        $amount = floatval($_POST['amount']);
        $frequency = $conn->real_escape_string($_POST['frequency']);
        $due_day = intval($_POST['due_day'] ?? 1);
        $user_id = $_SESSION['user_id'] ?? null;
        
        $sql = "INSERT INTO estate_charges (estate_id, name, description, amount, frequency, due_day, created_by) 
                VALUES ($estate_id, '$name', '$desc', $amount, '$frequency', $due_day, " . ($user_id ? $user_id : "NULL") . ")";
        if ($conn->query($sql)) {
            $message = "Estate charge '$name' created successfully!";
            logAudit($conn, "Charge Created", "Finance", "Created estate charge: $name (₦$amount)");
        } else {
            $message = "Error creating charge: " . $conn->error;
        }
    } elseif (isset($_POST['toggle_status'])) {
        $id = intval($_POST['charge_id']);
        $status = $_POST['status'] == 'Active' ? 'Inactive' : 'Active';
        $conn->query("UPDATE estate_charges SET status = '$status' WHERE id = $id AND estate_id = $estate_id");
        $message = "Charge status updated to $status.";
    } elseif (isset($_POST['delete_charge'])) {
        $id = intval($_POST['id']);
        $conn->query("DELETE FROM estate_charges WHERE id = $id AND estate_id = $estate_id");
        $message = "Estate charge removed.";
    }
}

// Fetch Charges
$charges_res = $conn->query("SELECT * FROM estate_charges WHERE estate_id = $estate_id ORDER BY status ASC, name ASC");
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h1>Estate Charge Catalog</h1>
        <p style="color: #64748b; margin: 0;">Configure standardized recurring fees (Service Charges, Security Levies, Waste Fees, Utilities, etc.)</p>
    </div>
    <button onclick="document.getElementById('chargeModal').style.display='block'" class="btn btn-primary" style="background:#3b82f6;color:#fff;padding:10px 15px;border-radius:5px;border:none;cursor:pointer; font-weight:600;">+ Add New Charge</button>
</div>

<?php if ($message): ?>
    <div style="background-color: #dcfce7; color: #166534; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; border: 1px solid #bbf7d0;">
        <?= $message ?>
    </div>
<?php endif; ?>

<div class="glass" style="padding: 2rem; border-radius: 1rem; background: rgba(255,255,255,0.95); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="border-bottom: 2px solid #e2e8f0; color: #64748b;">
                    <th style="padding: 12px;">Charge Name</th>
                    <th style="padding: 12px;">Default Amount</th>
                    <th style="padding: 12px;">Frequency</th>
                    <th style="padding: 12px;">Status</th>
                    <th style="padding: 12px; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($charges_res && $charges_res->num_rows > 0): ?>
                    <?php while($row = $charges_res->fetch_assoc()): ?>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 12px;">
                            <div style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($row['name']) ?></div>
                            <div style="font-size: 0.8rem; color: #64748b;"><?= htmlspecialchars($row['description'] ?? '') ?></div>
                        </td>
                        <td style="padding: 12px; font-weight: 700; color: #059669;">₦<?= number_format($row['amount'], 2) ?></td>
                        <td style="padding: 12px;"><span style="background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 600;"><?= htmlspecialchars($row['frequency']) ?></span></td>
                        <td style="padding: 12px;">
                            <span style="padding: 4px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; background: <?= $row['status'] == 'Active' ? '#dcfce7; color: #166534;' : '#fee2e2; color: #991b1b;' ?>">
                                <?= $row['status'] ?>
                            </span>
                        </td>
                        <td style="padding: 12px; text-align: right;">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="charge_id" value="<?= $row['id'] ?>">
                                <input type="hidden" name="status" value="<?= $row['status'] ?>">
                                <button type="submit" name="toggle_status" style="background: none; border: none; color: #3b82f6; cursor: pointer; font-weight: 600; margin-right: 10px;">Toggle</button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Delete this charge?');" style="display: inline;">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <button type="submit" name="delete_charge" style="background: none; border: none; color: #ef4444; cursor: pointer;"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="5" style="text-align: center; padding: 2rem; color: #94a3b8;">No estate charges defined yet. Click Add New Charge to set up.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div id="chargeModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15, 23, 42, 0.6); z-index:1000; align-items:center; justify-content:center; backdrop-filter: blur(4px);">
    <div style="background:#fff; padding:2.5rem; border-radius:1rem; width:100%; max-width: 480px; margin: 5vh auto; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);">
        <h2 style="margin-top:0; margin-bottom:1.5rem; color: #1e293b;">Add New Estate Charge</h2>
        <form method="POST">
            <div style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom:0.5rem; font-weight: 500; color: #475569;">Charge Name</label>
                <input type="text" name="name" placeholder="e.g. Annual Service Charge" required style="width:100%; padding:0.75rem; border-radius:0.5rem; border:1px solid #cbd5e1; outline: none;">
            </div>
            <div style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom:0.5rem; font-weight: 500; color: #475569;">Description</label>
                <input type="text" name="description" placeholder="Brief detail about what this fee covers" style="width:100%; padding:0.75rem; border-radius:0.5rem; border:1px solid #cbd5e1; outline: none;">
            </div>
            <div style="margin-bottom: 1rem;">
                <label style="display:block; margin-bottom:0.5rem; font-weight: 500; color: #475569;">Default Amount (₦)</label>
                <input type="number" step="0.01" name="amount" placeholder="250000.00" required style="width:100%; padding:0.75rem; border-radius:0.5rem; border:1px solid #cbd5e1; outline: none;">
            </div>
            <div style="margin-bottom: 1.5rem;">
                <label style="display:block; margin-bottom:0.5rem; font-weight: 500; color: #475569;">Frequency</label>
                <select name="frequency" required style="width:100%; padding:0.75rem; border-radius:0.5rem; border:1px solid #cbd5e1; outline: none;">
                    <option value="Monthly">Monthly</option>
                    <option value="Quarterly">Quarterly</option>
                    <option value="Yearly" selected>Yearly</option>
                    <option value="One-Time">One-Time</option>
                </select>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" onclick="document.getElementById('chargeModal').style.display='none'" style="padding:0.75rem 1.5rem; border:none; background:#f1f5f9; color: #475569; border-radius:0.5rem; cursor:pointer; font-weight:600;">Cancel</button>
                <button type="submit" name="add_charge" style="padding:0.75rem 1.5rem; border:none; background:#3b82f6; color:#fff; border-radius:0.5rem; cursor:pointer; font-weight:600;">Save Charge</button>
            </div>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
