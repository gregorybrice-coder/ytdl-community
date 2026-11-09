<?php
require_once __DIR__ . '/auth.php';
ytdl_maintenance_gate(null, true);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    http_response_code(405); header('Allow: GET, HEAD'); exit;
}
$key = $_GET['key'] ?? '';
$slot = is_string($key) ? ($_SESSION['welcome_audio'][$key] ?? null) : null;
if (!$slot || $slot['expires'] < time()) { http_response_code(403); exit; }
if (!ytdl_library_volume_mounted()) { http_response_code(503); exit; }
$root = realpath(YTDL_LIBRARY_ROOT);
$validPath = static function ($path) use ($root) {
    $real = $path ? realpath($path) : false;
    return $root && $real && str_starts_with($real, $root . DIRECTORY_SEPARATOR)
        && is_file($real) && is_readable($real) && filesize($real) > 0
        && in_array(strtolower(pathinfo($real, PATHINFO_EXTENSION)), ['mp3', 'm4a', 'ogg', 'wav'], true) ? $real : null;
};
$db = ytdl_db();
if (empty($slot['id'])) {
    // Dès qu'un compte est identifié pour cet appareil (ou, à défaut, celui
    // affiché par repli dans l'espace de connexion — voir ytdl_welcome_
    // account) : ambiance uniquement puisée dans SA bibliothèque, jamais
    // celle de quelqu'un d'autre. Seulement en l'absence de tout compte sur
    // l'appli (aucun compte à afficher du tout) que toutes les bibliothèques
    // actives restent mélangées comme avant.
    $welcomeAccount = ytdl_welcome_account();
    $sql = "SELECT dh.id, dh.library_path FROM download_history dh JOIN users u ON u.id = dh.user_id
        WHERE u.status = 'active' AND dh.audio_only = 1 AND dh.category = 'music' AND dh.library_path IS NOT NULL";
    $params = [];
    if ($welcomeAccount) {
        $sql .= " AND dh.user_id = ?"; $params[] = $welcomeAccount['id'];
    } else {
        // Aucun compte à afficher : ambiance mélangeant toutes les
        // bibliothèques, mais tout le monde n'aime pas le rap — les
        // morceaux de la (ou des) playlist(s) de rap en sont exclus, pour
        // ne pas tomber dessus par surprise sur un premier accueil sans
        // compte reconnu.
        $sql .= " AND dh.id NOT IN (
            SELECT pi.history_id FROM playlist_items pi
            JOIN playlists p ON p.id = pi.playlist_id
            WHERE p.name LIKE '%rap%'
        )";
    }
    $sql .= " ORDER BY random()";
    $rows = $db->prepare($sql);
    $rows->execute($params);
    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
        if ($validPath($row['library_path'])) { $slot['id'] = (int)$row['id']; break; }
    }
    $_SESSION['welcome_audio'][$key] = $slot;
}
$query = $db->prepare("SELECT dh.library_path, dh.title, dh.uploader, dh.thumbnail FROM download_history dh JOIN users u ON u.id = dh.user_id
    WHERE dh.id = ? AND u.status = 'active' AND dh.audio_only = 1 AND dh.category = 'music'");
$query->execute([$slot['id'] ?? 0]);
$track = $query->fetch(PDO::FETCH_ASSOC);
$query = $rows = null; // libère la base avant la diffusion
$path = $validPath($track['library_path'] ?? null);
session_write_close();
if (!$path) { http_response_code(404); exit; }
if (($_GET['meta'] ?? '') === '1') {
    $info = ytdl_split_artist_title((string)($track['title'] ?? ''), $track['uploader'] ?? null);
    $thumbnail = (string)($track['thumbnail'] ?? '');
    if (!filter_var($thumbnail, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($thumbnail, PHP_URL_SCHEME)), ['https', 'http'], true)) $thumbnail = '';
    // Paroles de l'ambiance d'accueil : même source que le reste de l'appli
    // (cache partagé, voir ytdl_fetch_lyrics_data) — mais accessible sans
    // connexion, comme le reste de cet endpoint. Pas de vérification de
    // durée ici (pas de piste "réelle" connue côté client à ce stade, juste
    // ce fichier précis) : un léger risque de synchro décalée sur un remix
    // rare, acceptable pour une simple ambiance de bienvenue.
    $lyrics = ytdl_fetch_lyrics_data($info['artist'], $info['title']);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'artist' => $info['artist'], 'title' => $info['title'], 'thumbnail' => $thumbnail,
        'lyrics' => $lyrics['lyrics'], 'synced' => $lyrics['synced'], 'offset' => $lyrics['offset'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$size = filesize($path);
$start = 0; $end = $size - 1; $partial = false;
$range = $_SERVER['HTTP_RANGE'] ?? null;
if ($range !== null) {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $match) || ($match[1] === '' && $match[2] === '')) {
        http_response_code(416); header("Content-Range: bytes */$size"); exit;
    }
    if ($match[1] === '') { $start = max(0, $size - (int)$match[2]); }
    else { $start = (int)$match[1]; if ($match[2] !== '') $end = min($end, (int)$match[2]); }
    if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
    $partial = true;
}
$handle = fopen($path, 'rb');
if (!$handle) { http_response_code(404); exit; }
$types = ['mp3'=>'audio/mpeg', 'm4a'=>'audio/mp4', 'ogg'=>'audio/ogg', 'wav'=>'audio/wav'];
header('Content-Type: ' . $types[strtolower(pathinfo($path, PATHINFO_EXTENSION))]);
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
if ($partial) { http_response_code(206); header("Content-Range: bytes $start-$end/$size"); }
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') { fclose($handle); exit; }
set_time_limit(0);
while (ob_get_level() > 0) ob_end_clean();
fseek($handle, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($handle) && !connection_aborted()) {
    $chunk = fread($handle, min(262144, $remaining));
    if ($chunk === false || $chunk === '') break;
    echo $chunk; flush(); $remaining -= strlen($chunk);
}
fclose($handle);
