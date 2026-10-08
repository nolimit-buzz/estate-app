<?php
// superadmin/admins.php - platform operators (separate from every estate's users)
$page_title = 'Platform admins';
require_once __DIR__ . '/includes/super_header.php';
sa_require_role(['superadmin']);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $a = $_POST['action'] ?? '';
        if ($a === 'create') {
            $name = trim($_POST['name'] ?? ''); $email = strtolower(trim($_POST['email'] ?? '')); $role = $_POST['role'] ?? 'support';
            if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['superadmin', 'support', 'finance'], true)) throw new RuntimeException('Name, a valid email and a role are required.');
            $pw = bin2hex(random_bytes(5)) . 'Aa1!'; $h = password_hash($pw, PASSWORD_DEFAULT);
            $st = $platform->prepare("INSERT INTO platform_users (name, email, password, role, force_password_change) VALUES (?,?,?,?,1)"); $st->bind_param('ssss', $name, $email, $h, $role); $st->execute();
            Audit::log('platform_user.created', ['estate_id' => null, 'module' => 'security', 'details' => "Created platform admin $email ($role)"]);
            redirectWithFlash('admins', "Created $email. One-time password (share securely, change is forced at first sign-in): $pw");
        } elseif ($a === 'toggle') {
            $id = intval($_POST['id']);
            if ($id === intval($SA['id'])) throw new RuntimeException('You cannot disable your own account.');
            $u = $platform->query("SELECT * FROM platform_users WHERE id = $id")->fetch_assoc();
            $ns = $u['status'] === 'active' ? 'disabled' : 'active';
            if ($ns === 'disabled' && $u['role'] === 'superadmin' && $platform->query("SELECT COUNT(*) c FROM platform_users WHERE role='superadmin' AND status='active'")->fetch_assoc()['c'] <= 1) throw new RuntimeException('At least one active super admin must remain.');
            $platform->query("UPDATE platform_users SET status = '$ns' WHERE id = $id");
            Audit::log('platform_user.' . $ns, ['estate_id' => null, 'module' => 'security', 'details' => "{$u['email']} $ns"]);
            redirectWithFlash('admins', "{$u['email']} is now $ns.");
        } elseif ($a === 'reset') {
            $id = intval($_POST['id']); $u = $platform->query("SELECT * FROM platform_users WHERE id = $id")->fetch_assoc();
            $pw = bin2hex(random_bytes(5)) . 'Aa1!'; $h = password_hash($pw, PASSWORD_DEFAULT);
            $st = $platform->prepare("UPDATE platform_users SET password = ?, force_password_change = 1 WHERE id = ?"); $st->bind_param('si', $h, $id); $st->execute();
            Audit::log('platform_user.password_reset', ['estate_id' => null, 'module' => 'security', 'details' => "Password reset for {$u['email']}"]);
            redirectWithFlash('admins', "Temporary password for {$u['email']}: $pw (must be changed at next sign-in)");
        }
    } catch (Throwable $ex) { redirectWithFlash('admins', null, strpos($ex->getMessage(), 'Duplicate') !== false ? 'That email already exists.' : $ex->getMessage()); }
}
require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';
$users = $platform->query("SELECT * FROM platform_users ORDER BY id")->fetch_all(MYSQLI_ASSOC);
?>
<div class="row g-3"><div class="col-lg-8"><div class="card-custom"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Admin</th><th>Role</th><th>Status</th><th class="d-none d-md-table-cell">Last sign-in</th><th></th></tr></thead><tbody>
<?php foreach ($users as $u): ?><tr><td class="fw-semibold"><?= e($u['name']) ?><div class="small text-secondary"><?= e($u['email']) ?></div></td><td><?= e($u['role']) ?></td><td><?= sa_badge($u['status']) ?></td><td class="small d-none d-md-table-cell"><?= sa_dt($u['last_login_at']) ?><div class="text-secondary" style="font-size:.7rem"><?= e($u['last_login_ip']) ?></div></td>
 <td class="text-end text-nowrap"><?php if ($u['id'] != $SA['id']): ?><form method="post" class="d-inline"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button name="action" value="toggle" class="btn btn-sm btn-outline-secondary"><?= $u['status'] === 'active' ? 'Disable' : 'Enable' ?></button> <button name="action" value="reset" class="btn btn-sm btn-outline-secondary">Reset password</button></form><?php else: ?><span class="small text-secondary">you</span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
<div class="col-lg-4"><form method="post" class="card-custom p-3"><input type="hidden" name="action" value="create"><h6 class="fw-bold">Add platform admin</h6>
 <div class="mb-2"><input class="form-control form-control-sm" name="name" placeholder="Full name" required></div><div class="mb-2"><input class="form-control form-control-sm" type="email" name="email" placeholder="Email" required></div>
 <div class="mb-2"><select name="role" class="form-select form-select-sm"><option value="support">Support (tickets, impersonation)</option><option value="finance">Finance (billing, refunds)</option><option value="superadmin">Super admin (everything)</option></select></div><button class="btn btn-sm btn-primary">Create</button></form></div></div>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
