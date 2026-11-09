<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
ytdl_require_login();

// Le premier appel pour un id donné lance yt-dlp + ffmpeg (quelques
// secondes) avant de pouvoir répondre : pas de limite de temps arbitraire,
// comme pour library-play.php.
set_time_limit(0);

$videoId = (string)($_GET['id'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Identifiant de vidéo invalide.']);
    exit;
}

$clipPath = ytdl_fetch_preview_clip($videoId);
if (!$clipPath) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => "Aperçu indisponible pour ce morceau."]);
    exit;
}

// Même logique de service que library-play.php : le support des requêtes
// Range est indispensable pour que Safari iOS accepte de lire l'audio.
$fileSize = filesize($clipPath);
$start = 0;
$end = $fileSize - 1;
$isRange = false;

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    $isRange = true;
    if ($m[1] !== '') $start = (int)$m[1];
    if ($m[2] !== '') $end = (int)$m[2];
    if ($end >= $fileSize) $end = $fileSize - 1;
    if ($start > $end) { $start = 0; $end = $fileSize - 1; $isRange = false; }
}

$length = $end - $start + 1;

header('Content-Type: audio/mpeg');
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
// Le fichier en cache ne change jamais une fois créé : autant laisser le
// navigateur le garder plutôt que de le redemander à chaque clic.
header('Cache-Control: private, max-age=86400');
if ($isRange) {
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$fileSize");
}

while (ob_get_level() > 0) ob_end_clean();

$handle = fopen($clipPath, 'rb');
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
