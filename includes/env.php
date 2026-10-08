<?php
// includes/env.php - minimal .env loader (no composer). Values are read via env_get().
if (!function_exists('env_get')) {
    function env_load($file) {
        static $loaded = [];
        if (isset($loaded[$file]) || !is_readable($file)) return;
        $loaded[$file] = true;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k); $v = trim($v);
            if (strlen($v) > 1 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) $v = substr($v, 1, -1);
            $GLOBALS['__ENV'][$k] = $v;
        }
    }
    function env_get($key, $default = null) {
        if (isset($GLOBALS['__ENV'][$key])) return $GLOBALS['__ENV'][$key];
        $e = getenv($key);
        return ($e !== false && $e !== '') ? $e : $default;
    }
    env_load(dirname(__DIR__) . '/.env');
}
