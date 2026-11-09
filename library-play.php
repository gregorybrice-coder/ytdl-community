<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
$user = ytdl_require_login(); // referme la session : un clip se lit pendant des minutes

ini_set('memory_limit', '-1');
set_time_limit(0);

$id = (int)($_GET['id'] ?? 0);
$asDownload = ($_GET['download'] ?? '0') === '1';

// Propriétaire OU destinataire d'un partage — jamais vérifié après coup :
// empêche un compte d'accéder au fichier d'un autre en devinant/énumérant
// les id, y compris pour un fichier jamais partagé avec lui.
$stmt = ytdl_db()->prepare(
    'SELECT library_path, title FROM download_history dh
     WHERE dh.id = :id AND (
        dh.user_id = :user_id
        OR EXISTS (SELECT 1 FROM library_shares ls WHERE ls.history_id = dh.id AND ls.shared_with_user_id = :user_id2)
     )'
);
$stmt->execute([':id' => $id, ':user_id' => $user['id'], ':user_id2' => $user['id']]);
$row = $stmt->fetch();
$stmt = null; // libère la base avant une diffusion qui peut durer des minutes
$libraryPath = $row['library_path'] ?? null;

if (!$libraryPath || !is_file($libraryPath)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => "Ce fichier n'est plus disponible (probablement purgé après 30 jours) — retélécharge-le."]);
    exit;
}

$mimeTypes = ['mp3' => 'audio/mpeg', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'm4a' => 'audio/mp4'];
$extension = strtolower(pathinfo($libraryPath, PATHINFO_EXTENSION));
$mimeType  = $mimeTypes[$extension] ?? 'application/octet-stream';

if ($asDownload) {
    // Même logique de nommage que file.php : repli ASCII pour les vieux
    // clients, filename* (RFC 5987) pour que les navigateurs actuels
    // affichent correctement les accents/emoji du titre.
    $title    = trim((string)($row['title'] ?? '')) ?: 'media';
    $fileName = ytdl_sanitize_filename($title) . '.' . $extension;
    $asciiFallback = preg_replace('/[^\x20-\x7E]/', '_', $fileName);
    header('Content-Disposition: attachment; filename="' . addslashes($asciiFallback) . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
}

$fileSize = filesize($libraryPath);
$start = 0;
$end = $fileSize - 1;
$isRange = false;

// Support des requêtes Range : indispensable pour que les lecteurs <audio>/
// <video> du navigateur puissent avancer/reculer sans tout retélécharger —
// et pour que Safari iOS accepte de lire la vidéo tout court.
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    $isRange = true;
    if ($m[1] !== '') $start = (int)$m[1];
    if ($m[2] !== '') $end = (int)$m[2];
    if ($end >= $fileSize) $end = $fileSize - 1;
    if ($start > $end) { $start = 0; $end = $fileSize - 1; $isRange = false; }
}

$length = $end - $start + 1;

header('Content-Type: ' . $mimeType);
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
if ($isRange) {
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$fileSize");
}

while (ob_get_level() > 0) ob_end_clean();

$handle = fopen($libraryPath, 'rb');
if ($handle !== false) {
    fseek($handle, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(1024 * 1024, $remaining));
        echo $chunk;
        flush();
        $remaining -= strlen($chunk);
    }
    fclose($handle);
}
