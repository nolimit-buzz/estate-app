<?php
// includes/login_gate.php - included at the top of every portal login page.
// Sign-in must always happen inside ONE estate. If the estate is not identified by the host name
// (e.g. sunrise.example.com) or an earlier step, ask for the estate code first.
if (Tenancy::hasTenant()) return;

$gate_error = '';
$depth = preg_match('#/(zone|staff|resident|admin)/[^/]+$#', str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '')) ? '../' : '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['estate_code'])) {
    require_once __DIR__ . '/RateLimit.php';
    $code = strtolower(trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['estate_code'])));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0';
    if (RateLimit::lockedFor('estate_code', $ip) > 0) {
        $gate_error = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        $e = $code !== '' ? Tenancy::findByPrefix($code) : null;
        if ($e && !in_array($e['status'], ['deleted', 'archived'], true) && !empty($e['db_name'])) {
            $_SESSION['pending_estate_id'] = intval($e['id']);
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
        RateLimit::hit('estate_code', $ip, 15, 10, 10);
        $gate_error = 'We could not find an estate with that code. Check with your estate administrator.';
    }
}
$brand = PlatformSettings::get('site_company_name', 'Estate Platform');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find your estate - <?php echo htmlspecialchars($brand); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#eef6f5; --card:#fff; --ink:#111827; --muted:#8a94a6; --line:#e6ebf0; --brand:#6b4eff; }
        @media (prefers-color-scheme: dark) { :root { --bg:#0f1716; --card:#18211f; --ink:#eef3f2; --muted:#8fa09c; --line:#26322f; } }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:grid; place-items:center; background:var(--bg); color:var(--ink); font-family:'Inter',system-ui,sans-serif; padding:16px; }
        .box { width:100%; max-width:420px; background:var(--card); border:0; border-radius:24px; padding:32px 28px; box-shadow:0 1px 2px rgba(16,24,40,.04),0 12px 40px rgba(16,24,40,.06); }
        h1 { font-size:1.4rem; margin:0 0 6px; } p { color:var(--muted); margin:0 0 20px; line-height:1.5; }
        label { display:block; font-size:.85rem; font-weight:600; margin-bottom:6px; }
        input { width:100%; padding:12px 14px; border:1px solid var(--line); border-radius:12px; font:inherit; background:transparent; color:inherit; }
        input:focus { outline:2px solid var(--brand); outline-offset:1px; }
        button { width:100%; margin-top:14px; padding:12px; border:0; border-radius:999px; background:var(--brand); color:#fff; font:inherit; font-weight:600; cursor:pointer; box-shadow:0 8px 18px rgba(107,78,255,.3); }
        .err { background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; padding:10px 12px; border-radius:10px; margin-bottom:14px; font-size:.9rem; }
        .links { margin-top:18px; font-size:.9rem; color:var(--muted); text-align:center; } a { color:var(--brand); text-decoration:none; }
    </style>
</head>
<body>
<div class="box">
    <h1>Find your estate</h1>
    <p>Enter the estate code you were given (the part before the dot in your estate's web address).</p>
    <?php if ($gate_error): ?><div class="err"><?php echo htmlspecialchars($gate_error); ?></div><?php endif; ?>
    <form method="POST">
        <label for="estate_code">Estate code</label>
        <input id="estate_code" name="estate_code" required autofocus autocomplete="off" placeholder="e.g. sunrise-gardens">
        <button type="submit">Continue</button>
    </form>
    <div class="links">New here? <a href="<?php echo $depth; ?>signup">Register your estate</a> &middot; <a href="<?php echo $depth; ?>index">Home</a></div>
</div>
</body>
</html>
<?php exit;
