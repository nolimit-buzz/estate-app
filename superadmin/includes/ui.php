<?php
// superadmin/includes/ui.php - small helpers shared by all super admin screens.
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

function sa_money($n) { return PlatformSettings::get('currency_symbol', '₦') . number_format((float)$n, 2); }
function sa_dt($d) { return $d ? date('j M Y, H:i', strtotime($d)) : '—'; }
function sa_d($d) { return $d ? date('j M Y', strtotime($d)) : '—'; }

function sa_badge($status) {
    $map = [
        'active' => 'success', 'renewed' => 'success', 'paid' => 'success', 'success' => 'success', 'accepted' => 'success', 'ok' => 'success', 'resolved' => 'success', 'sent' => 'success', 'ready' => 'success', 'on' => 'success',
        'trial' => 'info', 'onboarding' => 'info', 'pending' => 'warning', 'pending_payment' => 'warning', 'grace_period' => 'warning', 'past_due' => 'warning', 'queued' => 'warning', 'open' => 'warning', 'in_progress' => 'info', 'awaiting_customer' => 'secondary',
        'suspended' => 'danger', 'retention' => 'danger', 'deletion_pending' => 'danger', 'failed' => 'danger', 'expired' => 'secondary', 'cancelled' => 'secondary', 'declined' => 'secondary',
        'deleted' => 'dark', 'archived' => 'dark', 'refunded' => 'secondary', 'inactive' => 'secondary', 'closed' => 'secondary', 'off' => 'danger', 'low' => 'secondary', 'medium' => 'warning', 'high' => 'danger', 'critical' => 'danger',
    ];
    $c = $map[strtolower($status)] ?? 'secondary';
    return '<span class="badge text-bg-' . $c . ' rounded-pill fw-semibold">' . e(ucwords(str_replace('_', ' ', $status))) . '</span>';
}

/** Require typing a phrase for destructive actions. Returns true when POST carries the matching phrase. */
function sa_confirmed($expected) {
    return isset($_POST['confirm_text']) && hash_equals(trim($expected), trim((string)$_POST['confirm_text']));
}

/** Modal that asks the operator to type a phrase. Call once per page; buttons use data-confirm attributes. */
function sa_confirm_modal() {
    ?>
<div class="modal fade" id="saConfirm" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content" id="saConfirmForm">
  <div class="modal-header"><h5 class="modal-title" id="saConfirmTitle">Confirm action</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <p id="saConfirmMsg" class="mb-3"></p>
    <div id="saConfirmPhraseWrap"><label class="form-label small fw-semibold">Type <code id="saConfirmPhrase"></code> to confirm</label><input class="form-control" name="confirm_text" id="saConfirmInput" autocomplete="off"></div>
    <div class="mt-3" id="saReasonWrap"><label class="form-label small fw-semibold">Reason (recorded in the audit log)</label><input class="form-control" name="reason" maxlength="200"></div>
    <div id="saConfirmFields"></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger" id="saConfirmGo" disabled>Confirm</button></div>
</form></div></div>
<script>
function saConfirm(opts) {
  var f = document.getElementById('saConfirmForm');
  f.action = opts.action || '';
  document.getElementById('saConfirmTitle').textContent = opts.title || 'Confirm action';
  document.getElementById('saConfirmMsg').textContent = opts.message || '';
  document.getElementById('saConfirmPhrase').textContent = opts.phrase || '';
  document.getElementById('saConfirmPhraseWrap').style.display = opts.phrase ? '' : 'none';
  var inp = document.getElementById('saConfirmInput'); inp.value = '';
  var go = document.getElementById('saConfirmGo'); go.disabled = !!opts.phrase; go.textContent = opts.button || 'Confirm';
  inp.oninput = function () { go.disabled = inp.value.trim() !== (opts.phrase || ''); };
  var h = document.getElementById('saConfirmFields'); h.innerHTML = '';
  Object.keys(opts.fields || {}).forEach(function (k) { var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = opts.fields[k]; h.appendChild(i); });
  new bootstrap.Modal(document.getElementById('saConfirm')).show();
}
</script>
<?php
}

/** Stream a CSV download and exit. */
function sa_csv($filename, array $headers, array $rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9_.-]/i', '_', $filename) . '"');
    while (ob_get_level()) ob_end_clean();
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, $headers);
    foreach ($rows as $r) {
        // neutralise spreadsheet formula injection
        fputcsv($o, array_map(fn($v) => (is_string($v) && $v !== '' && strpos('=+-@', $v[0]) !== false) ? "'" . $v : $v, $r));
    }
    fclose($o);
    exit;
}

/** Build WHERE from simple filters. $defs: [param => ['col'=>..., 'op'=>'=' | 'like' | 'from' | 'to']]. */
function sa_where(array $defs, &$types, array &$vals) {
    global $platform;
    $w = [];
    foreach ($defs as $param => $d) {
        $v = trim((string)($_GET[$param] ?? ''));
        if ($v === '') continue;
        switch ($d['op']) {
            case 'like': $w[] = $d['col'] . ' LIKE ?'; $types .= 's'; $vals[] = '%' . $v . '%'; break;
            case 'from': $w[] = $d['col'] . ' >= ?'; $types .= 's'; $vals[] = $v . ' 00:00:00'; break;
            case 'to':   $w[] = $d['col'] . ' <= ?'; $types .= 's'; $vals[] = $v . ' 23:59:59'; break;
            default:     $w[] = $d['col'] . ' = ?'; $types .= 's'; $vals[] = $v;
        }
    }
    return $w ? ' WHERE ' . implode(' AND ', $w) : '';
}

function sa_flash() {
    global $flash_message, $flash_error;
    $o = '';
    if (!empty($flash_message)) $o .= '<div class="alert alert-success alert-dismissible fade show">' . e($flash_message) . '<button class="btn-close" data-bs-dismiss="alert"></button></div>';
    if (!empty($flash_error)) $o .= '<div class="alert alert-danger alert-dismissible fade show">' . e($flash_error) . '<button class="btn-close" data-bs-dismiss="alert"></button></div>';
    return $o;
}

function sa_redirect($url, $ok = null, $err = null) { redirectWithFlash($url, $ok, $err); }

/** Pagination helper: returns [offset, limit, page]. */
function sa_page($perPage = 50) {
    $page = max(1, intval($_GET['page'] ?? 1));
    return [($page - 1) * $perPage, $perPage, $page];
}
function sa_pager($total, $perPage, $page) {
    $pages = max(1, (int)ceil($total / $perPage));
    if ($pages <= 1) return '';
    $q = $_GET; $o = '<nav class="mt-3"><ul class="pagination pagination-sm flex-wrap">';
    for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++) { $q['page'] = $i; $o .= '<li class="page-item ' . ($i == $page ? 'active' : '') . '"><a class="page-link" href="?' . e(http_build_query($q)) . '">' . $i . '</a></li>'; }
    return $o . '</ul></nav>';
}
