<?php
// superadmin/ticket.php - ticket workspace: conversation, replies, internal notes, assignment, status/priority and estate actions
$page_title = 'Ticket';
require_once __DIR__ . '/includes/super_header.php';
require_once __DIR__ . '/../includes/Support.php';
require_once __DIR__ . '/../includes/EstateService.php';
require_once __DIR__ . '/../includes/TenantStats.php';
require_once __DIR__ . '/../includes/Billing.php';

$id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
$t = $id ? Support::find($id) : null;
if (!$t) redirectWithFlash('tickets', null, 'Ticket not found.');

$estateGone = in_array($t['estate_status'], ['deleted', 'archived'], true);
// superadmin + support work every ticket; finance only billing-related ones; nobody acts on a removed estate.
$roleOk = in_array($SA['role'], ['superadmin', 'support'], true) || ($SA['role'] === 'finance' && in_array($t['category'], ['billing', 'plan_change'], true));
$canAct = $roleOk && !$estateGone;
$self = "ticket?id=$id";
$audit = fn($action, $details, $extra = []) => Audit::log($action, ['estate_id' => $t['estate_id'], 'module' => 'support', 'entity_type' => 'ticket', 'entity_id' => $id, 'details' => $t['ticket_no'] . ' ' . $details] + $extra);
$label = fn($s) => ucwords(str_replace('_', ' ', $s));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!$canAct) throw new RuntimeException($estateGone ? 'This estate has been removed; its tickets are read-only.' : 'Your platform role cannot act on this ticket.');
        $a = $_POST['action'] ?? '';
        $msg = trim($_POST['message'] ?? '');
        switch ($a) {
            case 'reply':
                if ($msg === '') throw new RuntimeException('Please write a message.');
                Support::addMessage($id, 'platform', $SA['name'], $msg);
                $after = $_POST['after_status'] ?? 'awaiting_customer';
                if (in_array($after, Support::STATUSES, true) && $after !== $t['status']) { Support::setStatus($id, $after); $audit('ticket.status', "{$t['status']} -> $after"); }
                if (empty($t['assigned_to'])) { Support::assign($id, intval($SA['id'])); }
                $estate = Tenancy::find($t['estate_id']);
                $sent = Notifier::toCentralAdmins('ticket_update', $estate, ['ticket_no' => $t['ticket_no'], 'subject' => $t['subject'], 'message' => $msg]);
                $audit('ticket.replied', "emailed to $sent admin(s)");
                redirectWithFlash($self, $sent ? "Reply sent and emailed to $sent Central Admin(s)." : 'Reply saved. It is visible on the estate\'s Support page (no admin email could be delivered).');
            case 'note':
                if ($msg === '') throw new RuntimeException('Please write a note.');
                Support::addMessage($id, 'platform', $SA['name'], $msg, true);
                $audit('ticket.note', 'internal note added');
                redirectWithFlash($self, 'Internal note saved (never shown to the estate).');
            case 'props':
                $changes = [];
                $status = $_POST['status'] ?? $t['status'];
                if ($status !== $t['status']) { Support::setStatus($id, $status); $changes[] = "status {$t['status']} -> $status"; }
                $prio = $_POST['priority'] ?? $t['priority'];
                if ($prio !== $t['priority']) { Support::setPriority($id, $prio); $changes[] = "priority {$t['priority']} -> $prio"; }
                $cat = $_POST['category'] ?? $t['category'];
                if ($cat !== $t['category']) { Support::setCategory($id, $cat); $changes[] = "category {$t['category']} -> $cat"; }
                $asgIn = $_POST['assignee'] ?? ($t['assigned_to'] ?? '');
                $asgNew = $asgIn === '' ? null : intval($asgIn);
                if ($asgNew !== ($t['assigned_to'] === null ? null : intval($t['assigned_to']))) { $nm = Support::assign($id, $asgNew); $changes[] = 'assigned to ' . ($nm ?: 'nobody'); }
                if ($changes) { $audit('ticket.updated', implode('; ', $changes)); }
                redirectWithFlash($self, $changes ? 'Ticket updated: ' . implode(', ', $changes) . '.' : 'Nothing changed.');
            case 'assign_me':
                Support::assign($id, intval($SA['id']));
                $audit('ticket.assigned', 'assigned to ' . $SA['name']);
                redirectWithFlash($self, 'Assigned to you.');
            case 'onboarding':
                if ($t['category'] !== 'onboarding' || !in_array($_POST['step'] ?? '', ['support_review', 'onboarding_pending'], true)) throw new RuntimeException('Not an onboarding ticket.');
                EstateService::setOnboarding($t['estate_id'], $_POST['step']);
                Audit::log('onboarding.status', ['estate_id' => $t['estate_id'], 'module' => 'onboarding', 'details' => 'Onboarding moved to ' . $_POST['step'] . ' from ' . $t['ticket_no']]);
                redirectWithFlash($self, 'Onboarding stage updated.');
            case 'resend_verification':
                $em = $t['central_admin_email'] ?? Tenancy::find($t['estate_id'])['central_admin_email'] ?? '';
                if (!$em) throw new RuntimeException('This estate has no Central Admin email on file.');
                EstateService::resendVerification($em);
                $audit('ticket.action', 'verification email re-sent');
                redirectWithFlash($self, 'Verification email re-sent.');
            default:
                throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) { redirectWithFlash($self, null, $ex->getMessage()); }
}

$msgs = Support::messages($id, true);
$team = $platform->query("SELECT id, name, role FROM platform_users WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$activity = $platform->query("SELECT created_at, actor_name, action, details FROM platform_audit_logs WHERE entity_type = 'ticket' AND entity_id = '" . intval($id) . "' ORDER BY id DESC LIMIT 12")->fetch_all(MYSQLI_ASSOC);
$stats = $estateGone ? null : TenantStats::refresh($t['estate_id']);   // live read of this estate's own database
$estateRow = Tenancy::find($t['estate_id']);
$sub = $estateGone ? null : Billing::currentSubscription($t['estate_id']);
$canImpersonate = $canAct && !empty($t['db_name']) && in_array($SA['role'], ['superadmin', 'support'], true);

require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';
?>
<style>
.tkw-bubble{border-radius:16px;padding:14px 16px;margin-bottom:12px;border:1px solid #eef0f6;background:#fff}
.tkw-bubble.platform{background:#f4f6ff;border-color:#dfe5ff}
.tkw-bubble.internal{background:#fffbea;border:1px dashed #f0c14b}
.tkw-meta{font-size:.78rem;color:#6b7280;margin-bottom:6px;display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.tkw-mode .btn{border-radius:999px}
.tkw-side .card-custom{padding:16px;margin-bottom:14px}
.tkw-side h6{font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;color:#6b7280;margin-bottom:12px;font-weight:700}
.tkw-stat{display:flex;justify-content:space-between;font-size:.86rem;padding:5px 0;border-bottom:1px solid #f1f2f6}.tkw-stat:last-child{border:0}
.tkw-dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:6px}
</style>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <a href="tickets" class="small text-decoration-none text-secondary"><i class="fa-solid fa-arrow-left me-1"></i>All tickets</a>
        <h4 class="mb-1 mt-1"><?= e($t['subject']) ?></h4>
        <div class="small text-secondary"><code><?= e($t['ticket_no']) ?></code> · <a href="estate?id=<?= intval($t['estate_id']) ?>" class="text-decoration-none"><?= e($t['estate_name']) ?></a> · <?= e($label($t['category'])) ?> · opened <?= sa_dt($t['created_at']) ?> by <?= e($t['created_by_name']) ?> &lt;<?= e($t['created_by_email']) ?>&gt;</div>
    </div>
    <div class="d-flex gap-2 align-items-center"><?= sa_badge($t['status']) ?><?= sa_badge($t['priority']) ?></div>
</div>
<?php if ($estateGone): ?><div class="alert alert-secondary small"><i class="fa-solid fa-lock me-1"></i>This estate has been <strong><?= e($t['estate_status']) ?></strong>. The ticket is kept for the record and is read-only.</div>
<?php elseif (!$roleOk): ?><div class="alert alert-warning small"><i class="fa-solid fa-lock me-1"></i>Your role (<?= e($SA['role']) ?>) can read this ticket but only Support / Super admins can act on <?= e($label($t['category'])) ?> tickets.</div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card-custom p-3 mb-3" id="tkThread">
      <?php foreach ($msgs as $m): $internal = !empty($m['is_internal']); $cls = $internal ? 'internal' : ($m['author_type'] === 'platform' ? 'platform' : ''); ?>
        <div class="tkw-bubble <?= $cls ?>">
          <div class="tkw-meta"><strong class="text-dark"><?= e($m['author_name']) ?></strong>
            <?php if ($internal): ?><span class="badge text-bg-warning"><i class="fa-solid fa-eye-slash me-1"></i>Internal note</span>
            <?php else: ?><span class="badge text-bg-<?= $m['author_type'] === 'platform' ? 'primary' : 'secondary' ?>"><?= $m['author_type'] === 'platform' ? 'Platform' : 'Estate' ?></span><?php endif; ?>
            <span><?= sa_dt($m['created_at']) ?></span></div>
          <div style="white-space:pre-wrap"><?= e($m['message']) ?></div>
        </div>
      <?php endforeach; if (!$msgs) echo '<div class="text-secondary small">No messages yet.</div>'; ?>
    </div>

    <?php if ($canAct): ?>
    <form method="post" class="card-custom p-3" id="tkReplyForm">
      <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reply" id="tkAction">
      <div class="tkw-mode btn-group btn-group-sm mb-3" role="group">
        <button type="button" class="btn btn-primary" id="tkModeReply"><i class="fa-solid fa-paper-plane me-1"></i>Reply to estate</button>
        <button type="button" class="btn btn-outline-warning" id="tkModeNote"><i class="fa-solid fa-eye-slash me-1"></i>Internal note</button>
      </div>
      <div class="small text-secondary mb-2" id="tkModeHint">Visible to the estate on its Support page and emailed to its Central Admin(s).</div>
      <textarea name="message" rows="5" class="form-control mb-3" required placeholder="Write your message..."></textarea>
      <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2" id="tkAfterWrap"><label class="small text-secondary mb-0">After sending, set status to</label>
          <select name="after_status" class="form-select form-select-sm" style="width:auto"><?php foreach (['awaiting_customer' => 'Awaiting customer', 'in_progress' => 'In progress', 'resolved' => 'Resolved', $t['status'] => 'Keep (' . $label($t['status']) . ')'] as $v => $l): ?><option value="<?= e($v) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-primary" id="tkSend"><i class="fa-solid fa-paper-plane me-1"></i>Send reply</button>
      </div>
    </form>
    <script>
    (function () {
      var a = document.getElementById('tkAction'), r = document.getElementById('tkModeReply'), n = document.getElementById('tkModeNote'),
          hint = document.getElementById('tkModeHint'), after = document.getElementById('tkAfterWrap'), send = document.getElementById('tkSend');
      function mode(note) {
        a.value = note ? 'note' : 'reply';
        r.className = 'btn ' + (note ? 'btn-outline-primary' : 'btn-primary'); n.className = 'btn ' + (note ? 'btn-warning' : 'btn-outline-warning');
        hint.textContent = note ? 'Only platform staff can see this. It is never shown to the estate or emailed.' : 'Visible to the estate on its Support page and emailed to its Central Admin(s).';
        after.style.display = note ? 'none' : ''; send.innerHTML = note ? '<i class="fa-solid fa-note-sticky me-1"></i>Save note' : '<i class="fa-solid fa-paper-plane me-1"></i>Send reply';
      }
      r.onclick = function () { mode(false); }; n.onclick = function () { mode(true); };
      document.getElementById('tkReplyForm').addEventListener('submit', function () { send.disabled = true; });
    })();
    </script>
    <?php endif; ?>
  </div>

  <div class="col-lg-4 tkw-side">
    <form method="post" class="card-custom">
      <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="props">
      <h6>Ticket details</h6>
      <fieldset <?= $canAct ? '' : 'disabled' ?>>
        <label class="form-label small mb-1">Status</label>
        <select name="status" class="form-select form-select-sm mb-2"><?php foreach (Support::STATUSES as $s): ?><option value="<?= $s ?>" <?= $t['status'] === $s ? 'selected' : '' ?>><?= e($label($s)) ?></option><?php endforeach; ?></select>
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label small mb-1">Priority</label><select name="priority" class="form-select form-select-sm"><?php foreach (Support::PRIORITIES as $s): ?><option value="<?= $s ?>" <?= $t['priority'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
          <div class="col-6"><label class="form-label small mb-1">Category</label><select name="category" class="form-select form-select-sm"><?php foreach (Support::CATEGORIES as $s): ?><option value="<?= $s ?>" <?= $t['category'] === $s ? 'selected' : '' ?>><?= e($label($s)) ?></option><?php endforeach; ?></select></div>
        </div>
        <label class="form-label small mb-1">Assigned to</label>
        <select name="assignee" class="form-select form-select-sm mb-3"><option value="">Unassigned</option><?php foreach ($team as $u): ?><option value="<?= $u['id'] ?>" <?= intval($t['assigned_to']) === intval($u['id']) ? 'selected' : '' ?>><?= e($u['name']) ?> (<?= e($u['role']) ?>)</option><?php endforeach; ?></select>
        <div class="d-flex gap-2"><button class="btn btn-sm btn-primary flex-grow-1">Save changes</button>
          <?php if (intval($t['assigned_to']) !== intval($SA['id'])): ?><button class="btn btn-sm btn-outline-secondary" name="action" value="assign_me" formnovalidate>Assign to me</button><?php endif; ?></div>
      </fieldset>
      <?php if ($t['first_response_at']): ?><div class="small text-secondary mt-3"><i class="fa-regular fa-clock me-1"></i>First response <?= sa_dt($t['first_response_at']) ?></div>
      <?php else: ?><div class="small text-warning mt-3"><i class="fa-regular fa-clock me-1"></i>No reply sent yet</div><?php endif; ?>
    </form>

    <div class="card-custom">
      <h6>Estate &middot; <a href="estate?id=<?= intval($t['estate_id']) ?>" class="text-decoration-none"><?= e($t['estate_name']) ?></a></h6>
      <?php if ($stats): ?>
        <div class="tkw-stat"><span>Database</span><span><span class="tkw-dot" style="background:<?= $stats['db_ok'] ? '#16a34a' : '#dc2626' ?>"></span><?= $stats['db_ok'] ? 'Connected' : 'Unreachable' ?></span></div>
        <?php if (!$stats['db_ok']): ?><div class="small text-danger py-1"><?= e($stats['db_error']) ?></div><?php endif; ?>
        <div class="tkw-stat"><span>Status</span><span><?= sa_badge($t['estate_status']) ?></span></div>
        <div class="tkw-stat"><span>Plan</span><span><?= $sub ? e($sub['plan_name']) . ' · ' . sa_badge($sub['status']) : '<span class="text-secondary">None</span>' ?></span></div>
        <div class="tkw-stat"><span>Residents</span><strong><?= intval($stats['residents']) ?><?= !empty($sub['resident_limit']) ? ' / ' . intval($sub['resident_limit']) : '' ?></strong></div>
        <div class="tkw-stat"><span>Central admins</span><strong><?= intval($stats['admins']) ?></strong></div>
        <div class="tkw-stat"><span>Staff</span><strong><?= intval($stats['staff']) ?></strong></div>
        <div class="tkw-stat"><span>Open maintenance</span><strong><?= intval($stats['open_maintenance']) ?></strong></div>
        <div class="tkw-stat"><span>Unpaid invoices</span><strong><?= intval($stats['unpaid_invoices']) ?></strong></div>
        <div class="tkw-stat"><span>Last login</span><span><?= sa_dt($stats['last_login_at']) ?></span></div>
      <?php else: ?><div class="small text-secondary">Estate removed - no live data.</div><?php endif; ?>
    </div>

    <?php if ($canAct): ?>
    <div class="card-custom">
      <h6>Quick actions</h6>
      <div class="d-grid gap-2">
        <a class="btn btn-sm btn-outline-primary" href="estate?id=<?= intval($t['estate_id']) ?>"><i class="fa-solid fa-building me-1"></i>Open estate</a>
        <?php if ($canImpersonate): ?><button class="btn btn-sm btn-warning" type="button" data-bs-toggle="collapse" data-bs-target="#tkImp"><i class="fa-solid fa-right-to-bracket me-1"></i>Enter as Central Admin</button>
        <form method="post" action="impersonate" class="collapse" id="tkImp"><input type="hidden" name="estate_id" value="<?= intval($t['estate_id']) ?>">
          <input class="form-control form-control-sm mb-2" name="reason" required value="Support ticket <?= e($t['ticket_no']) ?>"><button class="btn btn-sm btn-warning w-100">Enter estate</button></form><?php endif; ?>
        <?php if ($t['estate_status'] === 'pending' || $t['onboarding_status'] === 'email_verification_pending'): ?>
        <form method="post"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="resend_verification"><button class="btn btn-sm btn-outline-secondary w-100"><i class="fa-solid fa-envelope me-1"></i>Resend verification email</button></form><?php endif; ?>
      </div>
      <?php if ($t['category'] === 'onboarding'): ?>
      <div class="small text-secondary mt-3 mb-1">Onboarding stage: <strong class="text-dark"><?= e(EstateService::statusLabel($t['onboarding_status'])) ?></strong></div>
      <form method="post" class="d-flex gap-2"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="onboarding">
        <button class="btn btn-sm btn-outline-primary flex-fill" name="step" value="support_review">Support review</button><button class="btn btn-sm btn-outline-secondary flex-fill" name="step" value="onboarding_pending">Back to onboarding</button></form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($activity): ?>
    <div class="card-custom"><h6>Activity</h6>
      <?php foreach ($activity as $x): ?><div class="small mb-2"><div class="text-dark"><?= e($x['actor_name']) ?> <span class="text-secondary">· <?= e($x['action']) ?></span></div><div class="text-secondary" style="font-size:.74rem"><?= sa_dt($x['created_at']) ?><?= $x['details'] ? ' — ' . e($x['details']) : '' ?></div></div><?php endforeach; ?>
    </div><?php endif; ?>
  </div>
</div>
<script>var th = document.getElementById('tkThread'); if (th && th.lastElementChild) th.lastElementChild.scrollIntoView({block:'nearest'});</script>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
