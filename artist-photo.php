<?php
/** Photo d'artiste archivée sur le disque (voir ytdl_archive_artist). */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
ytdl_require_login();

$stmt = ytdl_db()->prepare('SELECT photo_path FROM artists WHERE id = ?');
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$path = $stmt->fetchColumn();
$stmt = null;
if (!$path || !is_file($path) || !str_starts_with(realpath($path), realpath(ytdl_artist_photo_dir()) . '/')) {
    http_response_code(404);
    exit;
}

$types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
header('Content-Type: ' . ($types[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
header('Cache-Control: private, max-age=2592000');
header('Content-Length: ' . filesize($path));
readfile($path);
