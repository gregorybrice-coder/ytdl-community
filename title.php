<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
ytdl_require_login();

header('Content-Type: application/json');

$ytDlp = ytdl_which('yt-dlp');
if (!$ytDlp) ytdl_json_error('yt-dlp est introuvable sur le serveur.', 500);

$url = ytdl_clean_url($_GET['url'] ?? '');
ytdl_validate_url($url);

// \x1f (Unit Separator) comme délimiteur : ne peut pas apparaître dans un
// titre, contrairement à un caractère plus courant comme "|".
$printFormat = "%(title)s\x1f%(thumbnail)s\x1f%(uploader)s\x1f%(duration_string)s";

$scratch = ytdl_scratch_dir();
$cmd = sprintf(
    '%s%s%s --no-playlist --skip-download --print %s -- %s',
    escapeshellarg($ytDlp),
    ytdl_js_runtime_flag(),
    ytdl_cookies_flag($scratch),
    escapeshellarg($printFormat),
    escapeshellarg($url)
);

[$out, $err] = ytdl_run_capture($cmd);
ytdl_rm_scratch($scratch);
if ($out === '') {
    ytdl_json_error(ytdl_explain_error($err));
}

$parts = explode("\x1f", $out);

echo json_encode([
    'title'     => $parts[0] ?? '',
    'thumbnail' => $parts[1] ?? '',
    'uploader'  => $parts[2] ?? '',
    'duration'  => $parts[3] ?? '',
]);
