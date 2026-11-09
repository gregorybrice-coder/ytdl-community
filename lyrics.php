<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
ytdl_require_login();

header('Content-Type: application/json');

$artist = trim((string)($_GET['artist'] ?? ''));
$title  = trim((string)($_GET['title'] ?? ''));
// Durée réelle (secondes) du fichier côté client, si connue — sert à écarter
// un minutage lrclib qui correspondrait à un enregistrement différent du
// même titre (voir ytdl_fetch_lyrics_data).
$durationParam = $_GET['duration'] ?? null;
$duration = ($durationParam !== null && is_numeric($durationParam)) ? (float)$durationParam : null;

if ($artist === '' || $title === '') {
    echo json_encode(['lyrics' => null]);
    exit;
}

// Comme pour youtube-search.php : une requête anormalement longue ne peut
// être qu'un abus ou une erreur côté client, jamais un vrai nom d'artiste ou
// de titre — on tronque plutôt que de renvoyer une erreur bloquante.
if (mb_strlen($artist) > 200) $artist = mb_substr($artist, 0, 200);
if (mb_strlen($title) > 200) $title = mb_substr($title, 0, 200);

echo json_encode(ytdl_fetch_lyrics_data($artist, $title, $duration));
