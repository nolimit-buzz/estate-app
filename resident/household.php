<?php
// resident/household.php - Resident self-service: register / remove vehicles, pets and domestic staff for the resident's own unit.
require_once '../config.php';
require_once '../includes/auth_guard.php';

requireLogin();

$user_id = intval($_SESSION['user_id']);
$estate_id = get_estate_id();

// The resident's own unit - every read and write below is scoped to it.
$stmt = $conn->prepare("SELECT r.flat_id, f.number AS flat_number, b.name AS building_name, s.name AS street_name
                        FROM residents r
                        LEFT JOIN flats f ON r.flat_id = f.id
                        LEFT JOIN buildings b ON f.building_id = b.id
                        LEFT JOIN streets s ON b.street_id = s.id
                        WHERE r.user_id = ? AND r.estate_id = ? AND r.status = 'active'
                        ORDER BY r.id DESC LIMIT 1");
$stmt->bind_param('ii', $user_id, $estate_id);
$stmt->execute();
$unit = $stmt->get_result()->fetch_assoc();
$flat_id = intval($unit['flat_id'] ?? 0);

$message = '';
$error = '';

function household_upload($field) {
    if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? 1) !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) throw new Exception('Photos must be JPG, PNG or WEBP.');
    if ($_FILES[$field]['size'] > 5 * 1024 * 1024) throw new Exception('Photos must be under 5 MB.');
    $dir = '../uploads/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $path = $dir . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    return move_uploaded_file($_FILES[$field]['tmp_name'], $path) ? $path : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($flat_id <= 0) throw new Exception('No active unit is linked to your account yet. Please contact the estate office.');
        $action = $_POST['action'] ?? '';
        $today = date('Y-m-d');

        if ($action === 'add_vehicle') {
            $reg = strtoupper(trim($_POST['reg_number'] ?? ''));
            $model = trim($_POST['model'] ?? '');
            $color = trim($_POST['color'] ?? '') ?: 'Unspecified';
            $type = in_array($_POST['type'] ?? '', ['car', 'motorcycle', 'truck', 'other'], true) ? $_POST['type'] : 'car';
            if ($reg === '') throw new Exception('Plate / registration number is required.');
            $dup = $conn->prepare("SELECT id FROM vehicles WHERE reg_number = ? AND estate_id = ? LIMIT 1");
            $dup->bind_param('si', $reg, $estate_id);
            $dup->execute();
            if ($dup->get_result()->num_rows > 0) throw new Exception("A vehicle with plate $reg is already registered in this estate.");
            $img = household_upload('image');
            $cid = generateCustomID($conn, 'vehicles', 'VEH');
            $ins = $conn->prepare("INSERT INTO vehicles (estate_id, custom_id, flat_id, type, reg_number, model, color, image_path, registration_date) VALUES (?,?,?,?,?,?,?,?,?)");
            $ins->bind_param('isissssss', $estate_id, $cid, $flat_id, $type, $reg, $model, $color, $img, $today);
            $ins->execute();
            logAudit($conn, 'Vehicle Registered', 'Household', "Resident registered vehicle $reg for unit $flat_id");
            $message = "Vehicle $reg registered.";
        } elseif ($action === 'add_pet') {
            $name = trim($_POST['name'] ?? '');
            $type = trim($_POST['type'] ?? '');
            $breed = trim($_POST['breed'] ?? '');
            if ($name === '' || $type === '') throw new Exception('Pet name and type are required.');
            $img = household_upload('image');
            $cid = generateCustomID($conn, 'pets', 'PET');
            $ins = $conn->prepare("INSERT INTO pets (estate_id, custom_id, flat_id, name, type, breed, image_path, registration_date) VALUES (?,?,?,?,?,?,?,?)");
            $ins->bind_param('isisssss', $estate_id, $cid, $flat_id, $name, $type, $breed, $img, $today);
            $ins->execute();
            logAudit($conn, 'Pet Registered', 'Household', "Resident registered pet $name for unit $flat_id");
            $message = "$name registered.";
        } elseif ($action === 'add_staff') {
            $first = trim($_POST['first_name'] ?? '');
            $last = trim($_POST['last_name'] ?? '');
            $role = trim($_POST['role'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $status = in_array($_POST['status'] ?? '', ['Daily', 'Live-in', 'Part-time'], true) ? $_POST['status'] : 'Daily';
            if ($first === '' || $role === '') throw new Exception('First name and role are required.');
            if ($phone !== '' && function_exists('isPhoneTakenInEstate') && ($taken = isPhoneTakenInEstate($conn, $phone, $estate_id))) {
                throw new Exception("Phone '$phone' is already registered to {$taken['name']}.");
            }
            $full = trim("$first $last");
            $img = household_upload('image');
            $cid = generateCustomID($conn, 'household_staff', 'STF');
            $ins = $conn->prepare("INSERT INTO household_staff (estate_id, custom_id, flat_id, first_name, last_name, name, role, phone, status, image_path, registration_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $ins->bind_param('isissssssss', $estate_id, $cid, $flat_id, $first, $last, $full, $role, $phone, $status, $img, $today);
            $ins->execute();
            logAudit($conn, 'Domestic Staff Registered', 'Household', "Resident registered domestic staff $full for unit $flat_id");
            $message = "$full registered.";
        } elseif ($action === 'remove') {
            $tables = ['vehicle' => 'vehicles', 'pet' => 'pets', 'staff' => 'household_staff'];
            $kind = $_POST['kind'] ?? '';
            if (!isset($tables[$kind])) throw new Exception('Unknown record type.');
            $rid = intval($_POST['id'] ?? 0);
            $tbl = $tables[$kind];
            $del = $conn->prepare("DELETE FROM $tbl WHERE id = ? AND flat_id = ? AND estate_id = ?");
            $del->bind_param('iii', $rid, $flat_id, $estate_id);
            $del->execute();
            if ($del->affected_rows < 1) throw new Exception('Record not found for your unit.');
            logAudit($conn, 'Household Record Removed', 'Household', "Resident removed $kind #$rid from unit $flat_id");
            $message = 'Record removed.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$vehicles = $pets = $staff = [];
if ($flat_id > 0) {
    foreach (['vehicles' => &$vehicles, 'pets' => &$pets, 'household_staff' => &$staff] as $tbl => &$bucket) {
        $q = $conn->prepare("SELECT * FROM $tbl WHERE flat_id = ? AND estate_id = ? ORDER BY id DESC");
        $q->bind_param('ii', $flat_id, $estate_id);
        $q->execute();
        $bucket = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    unset($bucket);
}
$tab = in_array($_GET['tab'] ?? '', ['vehicles', 'pets', 'staff'], true) ? $_GET['tab'] : 'vehicles';

include 'header.php';
include 'sidebar.php';

function hh_remove_form($kind, $id) { ?>
    <form method="post" class="m-0" onsubmit="return confirm('Remove this record?');">
        <input type="hidden" name="action" value="remove">
        <input type="hidden" name="kind" value="<?php echo $kind; ?>">
        <input type="hidden" name="id" value="<?php echo intval($id); ?>">
        <button class="btn btn-sm btn-outline-secondary" title="Remove"><i class="fa-solid fa-trash"></i></button>
    </form>
<?php } ?>

<div class="page-header">
    <div>
        <h1>My Household</h1>
        <p class="text-secondary small mb-0">
            Register vehicles, pets and domestic staff for
            <?php echo $unit ? htmlspecialchars(trim(($unit['street_name'] ?? '') . ' ' . ($unit['building_name'] ?? '') . ' - Flat ' . ($unit['flat_number'] ?? ''))) : 'your unit'; ?>.
        </p>
    </div>
</div>

<?php if ($message): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check me-1"></i> <?php echo htmlspecialchars($message); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation me-1"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($flat_id <= 0): ?><div class="alert alert-warning">No active unit is linked to your account yet, so nothing can be registered. Please contact the estate office.</div><?php endif; ?>

<ul class="nav nav-pills mb-3 gap-2">
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'vehicles' ? 'active' : ''; ?>" href="?tab=vehicles"><i class="fa-solid fa-car me-1"></i> Vehicles (<?php echo count($vehicles); ?>)</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'pets' ? 'active' : ''; ?>" href="?tab=pets"><i class="fa-solid fa-paw me-1"></i> Pets (<?php echo count($pets); ?>)</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'staff' ? 'active' : ''; ?>" href="?tab=staff"><i class="fa-solid fa-user-shield me-1"></i> Domestic Staff (<?php echo count($staff); ?>)</a></li>
</ul>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="mature-card h-100">
            <div class="mature-card-header"><h3 class="mature-card-title">Registered <?php echo $tab === 'staff' ? 'domestic staff' : $tab; ?></h3></div>
            <div class="mature-card-body">
                <?php if ($tab === 'vehicles'): ?>
                    <?php if (!$vehicles): ?><p class="text-secondary small mb-0">No vehicles registered yet.</p><?php endif; ?>
                    <?php foreach ($vehicles as $v): ?>
                        <div class="d-flex align-items-center justify-content-between gap-2 py-2 border-bottom">
                            <div>
                                <span class="fw-bold font-monospace"><?php echo htmlspecialchars($v['reg_number']); ?></span>
                                <span class="small text-secondary ms-2"><?php echo htmlspecialchars(trim(($v['model'] ?? '') . ' ' . ($v['color'] ?? ''))); ?> (<?php echo htmlspecialchars(ucfirst($v['type'])); ?>)</span>
                            </div>
                            <div class="d-flex gap-2 align-items-center">
                                <a href="../car_sticker.php?id=<?php echo intval($v['id']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary">Car sticker</a>
                                <?php hh_remove_form('vehicle', $v['id']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php elseif ($tab === 'pets'): ?>
                    <?php if (!$pets): ?><p class="text-secondary small mb-0">No pets registered yet.</p><?php endif; ?>
                    <?php foreach ($pets as $p): ?>
                        <div class="d-flex align-items-center justify-content-between gap-2 py-2 border-bottom">
                            <div><span class="fw-semibold"><?php echo htmlspecialchars($p['name']); ?></span>
                                <span class="small text-secondary ms-2"><?php echo htmlspecialchars(trim($p['type'] . ' ' . ($p['breed'] ? '- ' . $p['breed'] : ''))); ?></span></div>
                            <?php hh_remove_form('pet', $p['id']); ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php if (!$staff): ?><p class="text-secondary small mb-0">No domestic staff registered yet.</p><?php endif; ?>
                    <?php foreach ($staff as $s): ?>
                        <div class="d-flex align-items-center justify-content-between gap-2 py-2 border-bottom">
                            <div><span class="fw-semibold"><?php echo htmlspecialchars($s['name']); ?></span>
                                <span class="small text-secondary ms-2"><?php echo htmlspecialchars($s['role']); ?> &bull; <?php echo htmlspecialchars($s['status'] ?? ''); ?> &bull; <span class="font-monospace"><?php echo htmlspecialchars($s['phone'] ?: 'N/A'); ?></span></span></div>
                            <?php hh_remove_form('staff', $s['id']); ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="mature-card h-100">
            <div class="mature-card-header"><h3 class="mature-card-title">
                <?php echo ['vehicles' => 'Register a vehicle', 'pets' => 'Register a pet', 'staff' => 'Register domestic staff'][$tab]; ?>
            </h3></div>
            <div class="mature-card-body">
                <form method="post" enctype="multipart/form-data" class="row g-3">
                    <?php if ($tab === 'vehicles'): ?>
                        <input type="hidden" name="action" value="add_vehicle">
                        <div class="col-12"><label class="form-label">Plate number *</label><input class="form-control" name="reg_number" required maxlength="50" placeholder="e.g. ABC-123-XY"></div>
                        <div class="col-6"><label class="form-label">Type</label>
                            <select class="form-select" name="type"><option value="car">Car</option><option value="motorcycle">Motorcycle</option><option value="truck">Truck</option><option value="other">Other</option></select></div>
                        <div class="col-6"><label class="form-label">Colour</label><input class="form-control" name="color" maxlength="50"></div>
                        <div class="col-12"><label class="form-label">Make / model</label><input class="form-control" name="model" maxlength="100" placeholder="e.g. Toyota Camry"></div>
                    <?php elseif ($tab === 'pets'): ?>
                        <input type="hidden" name="action" value="add_pet">
                        <div class="col-12"><label class="form-label">Pet name *</label><input class="form-control" name="name" required maxlength="100"></div>
                        <div class="col-6"><label class="form-label">Type *</label><input class="form-control" name="type" required maxlength="50" placeholder="Dog, Cat..."></div>
                        <div class="col-6"><label class="form-label">Breed</label><input class="form-control" name="breed" maxlength="100"></div>
                    <?php else: ?>
                        <input type="hidden" name="action" value="add_staff">
                        <div class="col-6"><label class="form-label">First name *</label><input class="form-control" name="first_name" required maxlength="100"></div>
                        <div class="col-6"><label class="form-label">Last name</label><input class="form-control" name="last_name" maxlength="100"></div>
                        <div class="col-6"><label class="form-label">Role *</label><input class="form-control" name="role" required maxlength="100" placeholder="Driver, Nanny, Cook..."></div>
                        <div class="col-6"><label class="form-label">Arrangement</label>
                            <select class="form-select" name="status"><option>Daily</option><option>Live-in</option><option>Part-time</option></select></div>
                        <div class="col-12"><label class="form-label">Phone</label><input class="form-control" name="phone" maxlength="20"></div>
                    <?php endif; ?>
                    <div class="col-12"><label class="form-label">Photo (optional)</label><input class="form-control" type="file" name="image" accept="image/jpeg,image/png,image/webp"></div>
                    <div class="col-12"><button class="btn btn-primary w-100" <?php echo $flat_id <= 0 ? 'disabled' : ''; ?>><i class="fa-solid fa-plus me-1"></i> Register</button></div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
