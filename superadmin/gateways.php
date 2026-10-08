<?php
// superadmin/gateways.php - platform gateways: subscription Paystack status + USSD shortcode -> estate routing
$page_title = 'USSD & gateways';
require_once __DIR__ . '/includes/super_header.php';
require_once __DIR__ . '/../includes/Billing.php';
sa_require_role(['superadmin']);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $a = $_POST['action'] ?? '';
        if ($a === 'add') {
            $code = trim($_POST['service_code'] ?? ''); $eid = intval($_POST['estate_id']);
            if (!preg_match('/^\*[0-9*#]{2,38}#?$/', $code)) throw new RuntimeException('Enter a valid USSD code such as *384*2020#.');
            if (!Tenancy::find($eid)) throw new RuntimeException('Estate not found.');
            $st = $platform->prepare("INSERT INTO ussd_routes (service_code, estate_id, note) VALUES (?,?,?)"); $note = trim($_POST['note'] ?? ''); $st->bind_param('sis', $code, $eid, $note); $st->execute();
            Audit::log('ussd.route_added', ['estate_id' => $eid, 'module' => 'gateways', 'details' => "USSD $code routed to estate #$eid"]);
            redirectWithFlash('gateways', 'Route added.');
        } elseif ($a === 'delete') {
            $id = intval($_POST['id']); $r = $platform->query("SELECT * FROM ussd_routes WHERE id = $id")->fetch_assoc();
            $platform->query("DELETE FROM ussd_routes WHERE id = $id");
            Audit::log('ussd.route_removed', ['estate_id' => $r['estate_id'] ?? null, 'module' => 'gateways', 'details' => 'Removed route ' . ($r['service_code'] ?? $id)]);
            redirectWithFlash('gateways', 'Route removed.');
        }
    } catch (Throwable $ex) { redirectWithFlash('gateways', null, strpos($ex->getMessage(), 'Duplicate') !== false ? 'That USSD code is already routed to an estate.' : $ex->getMessage()); }
}
require_once __DIR__ . '/includes/super_sidebar.php';
require_once __DIR__ . '/includes/super_topbar.php';
$routes = $platform->query("SELECT r.*, e.name estate_name FROM ussd_routes r JOIN estates e ON e.id = r.estate_id ORDER BY r.service_code")->fetch_all(MYSQLI_ASSOC);
$estates = $platform->query("SELECT id, name FROM estates WHERE db_name IS NOT NULL AND status NOT IN ('deleted','archived') ORDER BY name")->fetch_all(MYSQLI_ASSOC);
?>
<div class="row g-3">
<div class="col-lg-5"><div class="card-custom p-3 h-100"><h6 class="fw-bold">Subscription payments (Paystack)</h6>
  <p class="small mb-2"><?= Billing::gatewayConfigured() ? sa_badge('active') . ' Live keys configured.' : sa_badge('pending') . ' No Paystack secret key set.' ?></p>
  <p class="small text-secondary mb-0">Keys are held in the server <code>.env</code> file (<code>PLATFORM_PAYSTACK_SECRET</code>) and are never stored in the database or shown in the browser. Webhook URL: <code><?= e(Notifier::baseUrl()) ?>/api/platform_webhook.php</code>.<?= Billing::simulationAllowed() ? '<br><strong>Local mode:</strong> payments use the built-in simulator.' : '' ?></p></div></div>
<div class="col-lg-7"><div class="card-custom p-3"><h6 class="fw-bold">USSD routing</h6>
  <p class="small text-secondary">A shared Africa's Talking callback (<code>api/ussd.php</code>) identifies the estate from the dialled service code. Each estate keeps its own Africa's Talking credentials in its own settings.</p>
  <form method="post" class="row g-2 mb-3"><input type="hidden" name="action" value="add">
   <div class="col-md-4"><input class="form-control form-control-sm" name="service_code" placeholder="*384*2020#" required></div>
   <div class="col-md-4"><select class="form-select form-select-sm" name="estate_id"><?php foreach ($estates as $x): ?><option value="<?= $x['id'] ?>"><?= e($x['name']) ?></option><?php endforeach; ?></select></div>
   <div class="col-md-3"><input class="form-control form-control-sm" name="note" placeholder="Note"></div><div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Add</button></div></form>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><tbody>
  <?php foreach ($routes as $r): ?><tr><td><code><?= e($r['service_code']) ?></code></td><td class="small"><?= e($r['estate_name']) ?></td><td class="small text-secondary"><?= e($r['note']) ?></td><td class="text-end"><form method="post"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-link text-danger p-0">Remove</button></form></td></tr><?php endforeach; if (!$routes) echo '<tr><td class="text-secondary small">No routes yet.</td></tr>'; ?></tbody></table></div></div></div>
</div>
<?php require_once __DIR__ . '/includes/super_footer.php'; ?>
