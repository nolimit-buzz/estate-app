<?php
// admin/support.php - the estate's own support tickets (platform team replies appear here and by email)
require_once '../config.php';
require_once '../includes/auth_guard.php';
requireAdminAccess();
require_once '../includes/Support.php';

$eid = Tenancy::currentId();
$me = $conn->query("SELECT name, email FROM users WHERE id = " . intval($_SESSION['user_id']))->fetch_assoc();
$p = Tenancy::platform();
$id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'new') {
            $subj = trim($_POST['subject'] ?? ''); $msg = trim($_POST['message'] ?? '');
            $cat = in_array($_POST['category'] ?? '', ['billing', 'technical', 'module_request', 'plan_change', 'other'], true) ? $_POST['category'] : 'other';
            if ($subj === '' || $msg === '') throw new RuntimeException('Subject and message are required.');
            $tid = Support::create($eid, $cat, substr($subj, 0, 200), $msg, $me['name'], $me['email']);
            redirectWithFlash("support?id=$tid", 'Your request has been sent to our support team.');
        } elseif ($a === 'reply' && $id) {
            $t = $p->query("SELECT * FROM support_tickets WHERE id = $id AND estate_id = $eid")->fetch_assoc();   // tenant isolation: own tickets only
            if (!$t) throw new RuntimeException('Ticket not found.');
            if (trim($_POST['message'] ?? '') === '') throw new RuntimeException('Please write a message.');
            Support::addMessage($id, 'estate', $me['name'], trim($_POST['message']));
            $p->query("UPDATE support_tickets SET status = IF(status IN ('resolved','closed','awaiting_customer'),'open',status) WHERE id = $id");
            redirectWithFlash("support?id=$id", 'Reply sent.');
        }
    } catch (Throwable $e) { $err = $e->getMessage(); }
}
$tickets = Support::forEstate($eid);
$cur = $id ? $p->query("SELECT * FROM support_tickets WHERE id = $id AND estate_id = $eid")->fetch_assoc() : null;
$msgs = $cur ? $p->query("SELECT * FROM ticket_messages WHERE ticket_id = $id AND is_internal = 0 ORDER BY id")->fetch_all(MYSQLI_ASSOC) : [];
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="page-header-futuristic mb-4"><div><h1 class="page-title">Support</h1><p class="page-subtitle">Questions about onboarding, billing or modules? Our team replies here and by email.</p></div></div>
<?php if ($err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
<div class="row g-3 mb-5">
 <div class="col-lg-5">
  <div class="card border-0 shadow-sm mb-3"><div class="card-body"><h6 class="fw-bold">New request</h6>
   <form method="post"><input type="hidden" name="action" value="new">
    <select name="category" class="form-select form-select-sm mb-2"><option value="technical">Technical help</option><option value="billing">Billing</option><option value="module_request">Module request</option><option value="plan_change">Plan change</option><option value="other">Other</option></select>
    <input class="form-control form-control-sm mb-2" name="subject" placeholder="Subject" required maxlength="200">
    <textarea class="form-control form-control-sm mb-2" name="message" rows="3" placeholder="How can we help?" required></textarea>
    <button class="btn btn-primary btn-sm">Send</button></form></div></div>
  <div class="card border-0 shadow-sm"><div class="list-group list-group-flush">
   <?php foreach ($tickets as $t): ?><a class="list-group-item list-group-item-action <?php echo $t['id'] == $id ? 'active' : ''; ?>" href="support?id=<?php echo $t['id']; ?>"><div class="d-flex justify-content-between"><strong><?php echo htmlspecialchars($t['ticket_no']); ?></strong><span class="small"><?php echo ucwords(str_replace('_', ' ', $t['status'])); ?></span></div><div class="small"><?php echo htmlspecialchars($t['subject']); ?></div></a><?php endforeach; if (!$tickets) echo '<div class="list-group-item text-muted small">No tickets yet.</div>'; ?></div></div>
 </div>
 <div class="col-lg-7"><?php if ($cur): ?>
  <div class="card border-0 shadow-sm"><div class="card-body"><h6 class="fw-bold"><?php echo htmlspecialchars($cur['ticket_no'] . ' · ' . $cur['subject']); ?></h6>
   <?php foreach ($msgs as $m): ?><div class="mb-3 pb-3 border-bottom"><div class="small"><strong><?php echo htmlspecialchars($m['author_name']); ?></strong> <span class="badge bg-<?php echo $m['author_type'] === 'platform' ? 'primary' : 'secondary'; ?>"><?php echo $m['author_type'] === 'platform' ? 'Support team' : 'You'; ?></span> <span class="text-muted"><?php echo date('j M Y H:i', strtotime($m['created_at'])); ?></span></div><div style="white-space:pre-wrap"><?php echo htmlspecialchars($m['message']); ?></div></div><?php endforeach; ?>
   <form method="post"><input type="hidden" name="action" value="reply"><input type="hidden" name="id" value="<?php echo $id; ?>"><textarea class="form-control mb-2" name="message" rows="3" placeholder="Write a reply" required></textarea><button class="btn btn-primary btn-sm">Reply</button></form></div></div>
 <?php else: ?><div class="card border-0 shadow-sm"><div class="card-body text-muted">Select a ticket to read the conversation.</div></div><?php endif; ?></div>
</div>
<?php include '../includes/footer.php'; ?>
