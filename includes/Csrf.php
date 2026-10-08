<?php
// includes/Csrf.php - application-wide CSRF protection.
// Every state-changing request (POST/PUT/PATCH/DELETE) must carry the session token, either as the
// `csrf_token` form field or the `X-CSRF-Token` header. An output filter transparently injects the field into
// every POST <form> and a tiny script that adds the header to fetch/XHR calls, so existing pages stay protected.
class Csrf {
    /** Endpoints authenticated by something other than the browser session (signature / shortcode). */
    private static $exempt = [
        '/api/paystack_webhook.php', '/api/kapso_webhook.php', '/api/ussd.php', '/api/platform_webhook.php',
    ];

    public static function token() {
        if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }
    public static function field() { return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token()) . '">'; }

    public static function isExempt() {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        foreach (self::$exempt as $e) if (substr($script, -strlen($e)) === $e) return true;
        return false;
    }

    public static function submitted() {
        if (!empty($_POST['csrf_token'])) return (string)$_POST['csrf_token'];
        return (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    }

    public static function verify() {
        $t = self::submitted();
        return $t !== '' && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $t);
    }

    /** Reject unsafe requests that lack a valid token. Called once from config.php. */
    public static function enforce() {
        $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($m, ['GET', 'HEAD', 'OPTIONS'], true) || PHP_SAPI === 'cli' || self::isExempt()) return;
        if (self::verify()) return;
        http_response_code(403);
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $wantsJson = strpos($script, '/api/') !== false || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'status' => 'error', 'error' => 'Security token expired. Please refresh the page and try again.']);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Session expired</title><body style="font-family:system-ui;max-width:420px;margin:15vh auto;padding:0 20px;color:#0f172a"><h2>Your session expired</h2><p>For your security the form could not be submitted. Please go back, refresh the page and try again.</p><p><a href="javascript:history.back()">Go back</a></p></body>';
        }
        exit;
    }

    /** Output filter: add the token to POST forms and a header shim for AJAX. */
    public static function inject($html) {
        if ($html === '' || stripos($html, '<form') === false && stripos($html, '</head>') === false && stripos($html, '<body') === false) return $html;
        $ctype = '';
        foreach (headers_list() as $h) if (stripos($h, 'content-type:') === 0) $ctype = strtolower($h);
        if ($ctype !== '' && strpos($ctype, 'text/html') === false) return $html;
        if ($ctype === '' && ltrim($html)[0] !== '<') return $html;

        $field = self::field();
        $html = preg_replace_callback('/<form\b[^>]*>/i', function ($m) use ($field) {
            return preg_match('/method\s*=\s*["\']?post/i', $m[0]) ? $m[0] . $field : $m[0];
        }, $html);

        $tok = json_encode(self::token());
        $js = '<script>(function(){var T=' . $tok . ';try{var f=window.fetch;if(f){window.fetch=function(u,o){o=o||{};var m=String(o.method||(u&&u.method)||"GET").toUpperCase();if(m!=="GET"&&m!=="HEAD"){var h=new Headers(o.headers||(u&&u.headers)||{});if(!h.has("X-CSRF-Token"))h.set("X-CSRF-Token",T);o.headers=h;}return f.call(this,u,o);};}'
            . 'var oo=XMLHttpRequest.prototype.open,os=XMLHttpRequest.prototype.send;XMLHttpRequest.prototype.open=function(m){this.__m=String(m).toUpperCase();return oo.apply(this,arguments);};'
            . 'XMLHttpRequest.prototype.send=function(){if(this.__m&&this.__m!=="GET"&&this.__m!=="HEAD"){try{this.setRequestHeader("X-CSRF-Token",T);}catch(e){}}return os.apply(this,arguments);};'
            . 'function add(fm){if(fm&&String(fm.method).toLowerCase()==="post"&&!fm.querySelector("input[name=csrf_token]")){var i=document.createElement("input");i.type="hidden";i.name="csrf_token";i.value=T;fm.appendChild(i);}}'
            . 'document.addEventListener("submit",function(e){add(e.target);},true);var sub=HTMLFormElement.prototype.submit;HTMLFormElement.prototype.submit=function(){add(this);return sub.call(this);};}catch(e){}})();</script>';
        if (stripos($html, '</head>') !== false) return preg_replace('/<\/head>/i', $js . '</head>', $html, 1);
        if (stripos($html, '</body>') !== false) return preg_replace('/<\/body>/i', $js . '</body>', $html, 1);
        return $html;
    }
}
