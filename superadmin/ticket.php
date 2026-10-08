<?php
// superadmin/ticket.php - one support / onboarding ticket
$page_title = 'Ticket';
require_once __DIR__ . '/includes/super_header.php';
require_once __DIR__ . '/../includes/Support.php';
$id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
$t = $platform->query("SELECT t.*, e.name estate_name, e.onboarding_status FROM support_tickets t JOIN estates e ON e.id = t.estate_id WHERE t.id = $id")->fetch_assoc();
if (!$t) redirectWithFlash('tickets', null, 'Ticket not found.');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'reply' && trim($_POST['message'] ?? '') !== '') {
        Support::addMessage($id, 'platform', $SA['name'], trim($_POST['message']));
        $platform->query("UPDATE support_tickets SET status = IF(status IN ('open','resolved','closed'), 'awaiting_customer', status), assigned_to = COALESCE(assigned_to, {$SA['id']}) WHERE id = $id");
        $estate = Tenancy::find($t['estate_id']);
        Notifier::toCentralAdmins('ticket_update', $estate, ['ticket_no' => $t['ticket_no'], 'subject' => $t['subject'], 'message' => trim($_POST['message'])]);
        Audit::log('ticket.replied', ['estate_id' => $t['estate_id'], 'module' => 'support', 'entity_type' => 'ticket', 'entity_id' => $id, 'details' => $t['ticket_no']]);
    } elseif ($a === 'status' && in_array($_POST['status'], ['open', 'in_progress', 'awaiting_customer', 'resolved', 'closed'], true)) {
        $s = $_POST['status']; $platform->query("UPDATE support_tickets SET status = '$s', closed_at = " . (in_array($s, ['resolved', 'closed']) ? 'NOW()' : 'NULL') . " WHERE id = $id");
        Audit::log('ticket.status', ['estate_id' => $t['estate_id'], 'module' => 'support', 'entity_type' => 'ticket', 'entity_id' => $id, 'details' => "{$t['ticket_no']} -> $s"]);
    } elseif ($a === 'onboarding' && $t['category'] === 'onboarding' && in_array($_POST['step'] ?? '', ['support_review', 'onboarding_pending'], true)) {
        EstateService::setOnboarding($t['estate_id'], $_POST['step']);
        Audit::log('onboarding.status', ['estate_id' => $t['estate_id'], 'module' => 'onboarding', 'details' => 'Onboarding moved to ' . $_POST['step']]);
    }
    redirectWithFlash("ticket?id=$id", 'Saved.');
}
$msgs = $platform->query("SELECT * FROM ticket_messages WHERE ticket_id = $id ORDER BY id")->fetch_all(MYSQLI_ASSOC);
require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3"><div><h5 class="mb-0"><?= e($t['ticket_no']) ?> · <?= e($t['subject']) ?></h5><div class="small text-secondary"><a href="estate?id=<?= $t['estate_id'] ?>"><?= e($t['estate_name']) ?></a> · <?= e($t['category']) ?> · <?= e($t['created_by_name']) ?> &lt;<?= e($t['created_by_email']) ?>&gt;</div></div>
<form method="post" class="d-flex gap-2"><input type="hidden" name="action" value="status"><select name="status" class="form-select form-select-sm"><?php foreach (['open', 'in_progress', 'awaiting_customer', 'resolved', 'closed'] as $s): ?><option value="<?= $s ?>" <?= $t['status'] === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-primary">Update</button></form></div>
<?php if ($t['category'] === 'onboarding'): ?><div class="alert alert-info small d-flex flex-wrap align-items-center gap-2">Onboarding stage: <strong><?= e(EstateService::statusLabel($t['onboarding_status'])) ?></strong>
 <form method="post" class="ms-auto"><input type="hidden" name="action" value="onboarding"><button class="btn btn-sm btn-outline-primary" name="step" value="support_review">Mark: Support review</button> <button class="btn btn-sm btn-outline-secondary" name="step" value="onboarding_pending">Back to onboarding</button></form></div><?php endif; ?>
<div class="card-custom p-3 mb-3"><?php foreach ($msgs as $m): ?><div class="mb-3 pb-3 border-bottom"><div class="small"><strong><?= e($m['author_name']) ?></strong> <span class="badge text-bg-<?= $m['author_type'] === 'platform' ? 'primary' : 'secondary' ?>"><?= e($m['author_type']) ?></span> <span class="text-secondary"><?= sa_dt($m['created_at']) ?></span></div><div style="white-space:pre-wrap"><?= e($m['message']) ?></div></div><?php endforeach; ?></div>
<form method="post" class="card-custom p-3"><input type="hidden" name="action" value="reply"><label class="form-label small">Reply (emailed to the estate's Central Admin)</label><textarea name="message" rows="4" class="form-control mb-2" required></textarea><button class="btn btn-primary btn-sm">Send reply</button></form>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
