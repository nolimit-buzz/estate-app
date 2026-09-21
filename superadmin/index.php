<?php
// superadmin/index.php
require_once '../config.php';

// Check if user is superadmin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
    header("Location: ../index");
    exit;
}

$message = "";
if (isset($_GET['success'])) {
    $message = "Estate created successfully!";
}

// Handling deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_estate'])) {
    $del_id = intval($_POST['estate_id']);
    
    // In a real production system, you might want to soft-delete or prevent deletion of active estates.
    if($conn->query("DELETE FROM estates WHERE id=$del_id")) {
        // Also cascade delete users? 
        $conn->query("DELETE FROM users WHERE estate_id=$del_id");
        $message = "Estate deleted successfully.";
    } else {
        $error = "Failed to delete estate: " . $conn->error;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Super Admin - Estate Manager</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/estate_notifications.css">
    <script src="../js/estate_notifications.js"></script>
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .glass-panel { background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(10px); padding: 1.5rem; border-radius: 1rem; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { padding: 1rem; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { color: #64748b; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; }
        .btn { padding: 0.5rem 1rem; border-radius: 0.5rem; text-decoration: none; display: inline-block; text-align: center; border: none; cursor: pointer; font-weight: 500; }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-danger { background: #ef4444; color: white; padding: 0.35rem 0.75rem; font-size: 0.85rem; }
        .nav-header { display: flex; align-items: center; gap: 1rem; }
        .alert { padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    </style>
</head>
<body style="background: #f8fafc; font-family: 'Outfit', sans-serif;">
    <div class="container">
        <div class="top-bar">
            <div class="nav-header">
                <div style="background: #1e293b; color: white; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 8px;">
                    <i class="fa-solid fa-building-user"></i>
                </div>
                <h2 style="margin: 0; color: #1e293b;">Super Admin Dashboard</h2>
            </div>
            <div>
                <a href="create_estate" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add New Estate</a>
                <a href="../logout" class="btn" style="background: #e2e8f0; color: #1e293b; margin-left: 10px;"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?= $message ?></div>
        <?php endif; ?>

        <div class="glass-panel">
            <h3 style="margin-top: 0; color: #1e293b;">Registered Estates</h3>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Estate Name</th>
                        <th>Domain Prefix</th>
                        <th>Created At</th>
                        <th>Admins</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $estates = $conn->query("SELECT * FROM estates ORDER BY created_at DESC");
                    while($e = $estates->fetch_assoc()): 
                        $e_id = $e['id'];
                        $admin_count = $conn->query("SELECT COUNT(*) as c FROM users WHERE estate_id=$e_id AND role='admin'")->fetch_assoc()['c'];
                        $e_logo = '';
                        $l_res = $conn->query("SELECT setting_value FROM system_settings WHERE estate_id = $e_id AND setting_key = 'estate_logo' LIMIT 1");
                        if ($l_res && $l_row = $l_res->fetch_assoc()) {
                            $e_logo = get_media_url($l_row['setting_value']);
                        }
                    ?>
                        <tr>
                            <td>#<?= $e['id'] ?></td>
                            <td style="font-weight: 600; color: #1e293b;">
                                <div style="display: flex; align-items: center; gap: 0.65rem;">
                                    <?php if(!empty($e_logo)): ?>
                                        <img src="<?= htmlspecialchars($e_logo) ?>" alt="Logo" style="width: 30px; height: 30px; border-radius: 6px; object-fit: contain; border: 1px solid #e2e8f0; background: #fff;">
                                    <?php else: ?>
                                        <div style="width: 30px; height: 30px; border-radius: 6px; background: #e2e8f0; color: #64748b; display: flex; align-items: center; justify-content: center; font-size: 0.75rem;">
                                            <i class="fa-solid fa-tree-city"></i>
                                        </div>
                                    <?php endif; ?>
                                    <span><?= htmlspecialchars($e['name']) ?></span>
                                </div>
                            </td>
                            <td><span style="background:#f1f5f9; padding:4px 8px; border-radius:4px; font-family:monospace; color: #475569;"><?= htmlspecialchars($e['domain_prefix']) ?></span></td>
                            <td><?= date('M d, Y', strtotime($e['created_at'])) ?></td>
                            <td><span style="background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 999px; font-size: 0.85rem; font-weight: 600;"><?= $admin_count ?> Admin(s)</span></td>
                            <td>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('WARNING: Are you sure you want to delete this estate? ALL records for this estate will be lost.');">
                                    <input type="hidden" name="estate_id" value="<?= $e['id'] ?>">
                                    <button type="submit" name="delete_estate" class="btn btn-danger"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    <?php if($estates->num_rows == 0): ?>
                        <tr><td colspan="6" style="text-align:center; padding: 3rem; color: #64748b;">No estates registered yet. Click "Add New Estate" to get started.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
