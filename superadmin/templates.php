<?php
// superadmin/templates.php - manage platform email templates (no code changes) with live preview + test send
$page_title = 'Email templates';
require_once __DIR__ . '/includes/super_header.php';
sa_require_role(['superadmin', 'support']);

$key = preg_replace('/[^a-z_]/', '', $_GET['key'] ?? $_POST['key'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $key) {
    $t = $platform->query("SELECT * FROM email_templates WHERE template_key = '$key'")->fetch_assoc();
    if (!$t) redirectWithFlash('templates', null, 'Template not found.');
    if (($_POST['action'] ?? '') === 'test') {
        $vars = [];
        foreach (array_filter(array_map('trim', explode(',', $t['placeholders']))) as $p) $vars[$p] = '[' . $p . ']';
        $vars = array_merge($vars, ['otp' => '123456', 'link' => Notifier::baseUrl() . '/login', 'decline_link' => Notifier::baseUrl(), 'expiry_minutes' => 10]);
        Notifier::send($key, $SA['email'], $SA['name'], $vars);
        redirectWithFlash("templates?key=$key", 'Test email sent to ' . $SA['email'] . '.');
    }
    $subject = trim($_POST['subject'] ?? ''); $body = (string)($_POST['body_html'] ?? ''); $active = !empty($_POST['is_active']) ? 1 : 0;
    if ($subject === '' || trim($body) === '') redirectWithFlash("templates?key=$key", null, 'Subject and body are required.');
    // strip scripts / event handlers - templates are trusted HTML but never executable
    $body = preg_replace('#<script\b.*?</script>#is', '', $body); $body = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $body);
    $st = $platform->prepare("UPDATE email_templates SET subject=?, body_html=?, is_active=?, version=version+1, updated_by=? WHERE template_key=?");
    $st->bind_param('ssiis', $subject, $body, $active, $SA['id'], $key); $st->execute();
    Audit::log('email_template.updated', ['estate_id' => null, 'module' => 'notifications', 'entity_type' => 'email_template', 'entity_id' => $key, 'details' => "Template $key updated", 'before' => ['subject' => $t['subject'], 'body_html' => $t['body_html']], 'after' => ['subject' => $subject, 'body_html' => $body]]);
    redirectWithFlash("templates?key=$key", 'Template saved.');
}
require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';
$all = $platform->query("SELECT template_key, name, is_active, version FROM email_templates ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$cur = $key ? $platform->query("SELECT * FROM email_templates WHERE template_key = '$key'")->fetch_assoc() : null;
?>
<div class="row g-3">
 <div class="col-lg-3"><div class="card-custom list-group list-group-flush">
   <?php foreach ($all as $t): ?><a class="list-group-item list-group-item-action d-flex justify-content-between <?= $key === $t['template_key'] ? 'active' : '' ?>" href="templates?key=<?= e($t['template_key']) ?>"><span><?= e($t['name']) ?></span><?= $t['is_active'] ? '' : '<span class="badge text-bg-secondary">off</span>' ?></a><?php endforeach; ?></div></div>
 <div class="col-lg-9">
 <?php if ($cur): ?>
  <form method="post" class="card-custom p-3"><input type="hidden" name="key" value="<?= e($key) ?>">
    <h6 class="fw-bold"><?= e($cur['name']) ?> <span class="text-secondary small">v<?= intval($cur['version']) ?></span></h6>
    <div class="mb-2"><label class="form-label small">Subject</label><input class="form-control" name="subject" value="<?= e($cur['subject']) ?>"></div>
    <div class="mb-2"><label class="form-label small">Body (HTML). Placeholders: <code><?= e(implode('  ', array_map(fn($p) => '{{' . trim($p) . '}}', explode(',', $cur['placeholders'])))) ?></code></label>
      <textarea class="form-control font-monospace" rows="12" name="body_html" id="tb"><?= e($cur['body_html']) ?></textarea></div>
    <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="ia" <?= $cur['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="ia">Active</label></div>
    <button class="btn btn-primary">Save</button>
    <button class="btn btn-outline-secondary" name="action" value="test" formnovalidate>Send test to me</button>
  </form>
  <div class="card-custom p-3 mt-3"><h6 class="fw-bold">Preview</h6><iframe id="pv" style="width:100%;height:340px;border:1px solid #e2e8f0;border-radius:8px" sandbox></iframe></div>
  <script>
   var tb=document.getElementById('tb'),pv=document.getElementById('pv');
   function r(){ pv.srcdoc='<body style="font-family:Segoe UI,Arial;padding:18px;color:#0f172a">'+tb.value.replace(/\{\{\s*(\w+)\s*\}\}/g,'<b style="color:#2563eb">[$1]</b>')+'</body>'; } tb.addEventListener('input',r); r();
  </script>
 <?php else: ?><div class="card-custom p-4 text-secondary">Select a template to edit. Wording, branding line and support details are managed here and in <a href="settings">System settings</a>; no code changes required.</div><?php endif; ?>
 </div>
</div>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
