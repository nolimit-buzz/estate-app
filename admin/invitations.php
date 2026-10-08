<?php
// admin/invitations.php - invite managers / zone admins / co-admins, and track every invitation (Pending, Accepted, Expired, Declined, Cancelled)
require_once '../config.php';
require_once '../includes/auth_guard.php';
requireAdminAccess();
if (!in_array($_SESSION['role'] ?? '', ['admin', 'manager'], true)) { header('Location: index'); exit; }

$estate = Tenancy::find(Tenancy::currentId());
$eid = intval($estate['id']);
$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'invite') {
            $name = trim($_POST['name'] ?? ''); $email = strtolower(trim($_POST['email'] ?? '')); $role = $_POST['role'] ?? '';
            $allowed = ['manager', 'zone_admin', 'accountant', 'technician', 'security'] + ($_SESSION['role'] === 'admin' ? [5 => 'admin'] : []);
            if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, $allowed, true)) throw new RuntimeException('Enter a name, a valid email and a role.');
            if (function_exists('isEmailTakenInEstate') && isEmailTakenInEstate($conn, $email, $eid)) throw new RuntimeException('That email already belongs to a user of this estate.');
            if ($lim = userLimitMessage($conn)) throw new RuntimeException($lim);
            $zone = intval($_POST['zone_id'] ?? 0);
            if ($role === 'zone_admin' && !$zone) throw new RuntimeException('Choose the zone for a Zone Administrator.');
            $phone = trim($_POST['phone'] ?? '');
            $parts = preg_split('/\s+/', $name, 2);
            $fn = $parts[0];
            $ln = $parts[1] ?? '';
            $pw = Invitations::unusablePassword();

            $stmt = $conn->prepare("INSERT INTO users (estate_id, zone_id, first_name, last_name, name, email, phone, password, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'invited')");
            $zone_val = $zone ?: null;
            $stmt->bind_param('iisssssss', $eid, $zone_val, $fn, $ln, $name, $email, $phone, $pw, $role);
            $stmt->execute();
            $uid = $conn->insert_id;
            Invitations::sendForUser($estate, $uid, $email, $name, $role, $_SESSION['user_id'], $_SESSION['name'] ?? null, ['zone_id' => $zone]);
            redirectWithFlash('invitations', "Invitation sent to $email.");
        } elseif ($a === 'resend') { Invitations::resend(intval($_POST['id'])); redirectWithFlash('invitations', 'Invitation re-sent with a fresh link.'); }
        elseif ($a === 'cancel') { Invitations::cancel(intval($_POST['id']), $eid); redirectWithFlash('invitations', 'Invitation cancelled.'); }
    } catch (Throwable $e) { $err = $e->getMessage(); }
}
$list = Invitations::forEstate($eid);
$stmt = $conn->prepare("SELECT id, name FROM zones WHERE estate_id = ? AND status = 'active' ORDER BY name");
$stmt->bind_param('i', $eid);
$stmt->execute();
$zones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$counts = array_count_values(array_column($list, 'status'));
$badge = ['pending' => 'warning', 'accepted' => 'success', 'expired' => 'secondary', 'declined' => 'secondary', 'cancelled' => 'secondary'];
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="page-header-futuristic mb-4"><div><h1 class="page-title">Invitations</h1><p class="page-subtitle">Every account is activated by the person themselves through a one-time emailed link. Residents and staff added from their own pages are invited automatically and tracked here.</p></div></div>
<?php if ($err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
<div class="row g-3 mb-5">
 <div class="col-lg-4"><form method="post" class="card border-0 shadow-sm"><div class="card-body"><input type="hidden" name="action" value="invite"><h6 class="fw-bold">Invite someone</h6>
   <input class="form-control form-control-sm mb-2" name="name" placeholder="Full name" required>
   <input class="form-control form-control-sm mb-2" type="email" name="email" placeholder="Email address" required>
   <input class="form-control form-control-sm mb-2" name="phone" placeholder="Phone (optional)">
   <select name="role" class="form-select form-select-sm mb-2" id="invRole"><option value="manager">Estate Manager</option><option value="zone_admin">Zone Administrator</option><option value="accountant">Accountant</option><option value="security">Security / Gate Manager</option><option value="technician">Technician</option><?php if ($_SESSION['role'] === 'admin'): ?><option value="admin">Co-Central Admin</option><?php endif; ?></select>
   <select name="zone_id" class="form-select form-select-sm mb-2" id="invZone" style="display:none"><option value="">Select zone…</option><?php foreach ($zones as $z): ?><option value="<?php echo $z['id']; ?>"><?php echo htmlspecialchars($z['name']); ?></option><?php endforeach; ?></select>
   <button class="btn btn-primary btn-sm">Send invitation</button>
   <div class="small text-muted mt-2">Links expire after <?php echo PlatformSettings::int('invitation_expiry_days', 7); ?> days and work once. For residents and staff use <a href="residents">Residents</a> / <a href="staff">Staff</a>.</div>
 </div></form></div>
 <div class="col-lg-8"><div class="card border-0 shadow-sm"><div class="card-body">
   <div class="d-flex flex-wrap gap-2 mb-3"><?php foreach ($badge as $s => $c): ?><span class="badge bg-<?php echo $c; ?>"><?php echo ucfirst($s); ?> <?php echo intval($counts[$s] ?? 0); ?></span><?php endforeach; ?></div>
   <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Invitee</th><th>Role</th><th>Status</th><th class="d-none d-md-table-cell">Sent</th><th class="d-none d-md-table-cell">Expires</th><th></th></tr></thead><tbody>
   <?php foreach ($list as $i): ?><tr><td><?php echo htmlspecialchars($i['name'] ?: ''); ?><div class="small text-muted"><?php echo htmlspecialchars($i['email']); ?></div></td><td class="small"><?php echo htmlspecialchars(Invitations::roleLabel($i['role'])); ?></td><td><span class="badge bg-<?php echo $badge[$i['status']] ?? 'secondary'; ?>"><?php echo ucfirst($i['status']); ?></span></td><td class="small d-none d-md-table-cell"><?php echo date('j M', strtotime($i['created_at'])); ?><?php echo $i['send_count'] > 1 ? ' ×' . intval($i['send_count']) : ''; ?></td><td class="small d-none d-md-table-cell"><?php echo date('j M, H:i', strtotime($i['expires_at'])); ?></td>
     <td class="text-end text-nowrap"><?php if (in_array($i['status'], ['pending', 'expired'])): ?><form method="post" class="d-inline"><input type="hidden" name="id" value="<?php echo $i['id']; ?>"><button class="btn btn-sm btn-outline-primary" name="action" value="resend">Resend</button><?php if ($i['status'] === 'pending'): ?> <button class="btn btn-sm btn-outline-danger" name="action" value="cancel" onclick="return confirm('Cancel this invitation?')">Cancel</button><?php endif; ?></form><?php endif; ?></td></tr><?php endforeach; if (!$list) echo '<tr><td colspan="6" class="text-muted">No invitations yet.</td></tr>'; ?></tbody></table></div>
 </div></div></div>
</div>
<script>var r=document.getElementById('invRole'),z=document.getElementById('invZone');function t(){z.style.display=r.value==='zone_admin'?'':'none';}r.addEventListener('change',t);t();</script>
<?php include '../includes/footer.php'; ?>
