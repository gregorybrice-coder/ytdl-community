<?php
/**
 * Résout un lien de playlist externe (Spotify, Deezer, YouTube Music, Apple
 * Music) en une liste de titres à retrouver sur YouTube — voir
 * ytdl_resolve_external_playlist() dans lib.php pour le détail par
 * plateforme. Ne télécharge rien ici : juste la lecture de la playlist
 * source, le matching YouTube et le téléchargement se font ensuite côté
 * client, titre par titre (réutilise youtube-search.php et start.php).
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
ytdl_require_login();

header('Content-Type: application/json');

$url = trim((string)($_GET['url'] ?? ''));
if ($url === '') {
    ytdl_json_error("Colle un lien de playlist.");
}
if (mb_strlen($url) > 500) {
    $url = mb_substr($url, 0, 500);
}

$result = ytdl_resolve_external_playlist($url);
if (isset($result['error'])) {
    ytdl_json_error($result['error']);
}

echo json_encode($result);
