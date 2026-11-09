<?php
/** Routeur du serveur PHP de développement, monté uniquement sous /ytdl/. */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if ($path === '/') { header('Location: /ytdl/'); return; }
if (!str_starts_with($path, '/ytdl/')) { http_response_code(404); exit; }
$relative = substr($path, strlen('/ytdl/'));
if ($relative === '') { $relative = 'index.php'; }
if (preg_match('#(^|/)(\.|vendor(?:/|$)|scripts(?:/|$)|tests(?:/|$)|config-examples(?:/|$))#', $relative)
    || in_array($relative, ['config.php', 'db.php', 'lib.php', 'auth.php', 'mailer.php', 'composer.json', 'composer.lock'], true)) {
    http_response_code(404); exit;
}
$root = realpath(dirname(__DIR__));
$file = realpath($root . '/' . $relative);
if (!$file || !str_starts_with($file, $root . '/') || !is_file($file)) { http_response_code(404); exit; }
$_SERVER['SCRIPT_NAME'] = '/ytdl/' . $relative;
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
if (str_ends_with($file, '.php')) { require $file; return; }
$type = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
    'css' => 'text/css', 'js' => 'application/javascript', 'json' => 'application/json',
    'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', default => 'application/octet-stream',
};
header('Content-Type: ' . $type); readfile($file);
