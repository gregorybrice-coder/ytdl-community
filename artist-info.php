<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
ytdl_require_login();

header('Content-Type: application/json');

$artist = trim((string)($_GET['artist'] ?? ''));
// Indice supplémentaire pour lever les homonymies (voir
// ytdl_wikipedia_page_is_musical) — le titre du morceau en cours, jamais
// utilisé seul, juste pour biaiser la recherche vers le bon sujet musical.
$trackTitle = trim((string)($_GET['title'] ?? ''));

if ($artist === '') {
    echo json_encode(['extract' => null, 'thumbnail' => null, 'wiki_url' => null, 'wiki_title' => null]);
    exit;
}

// Comme pour lyrics.php : une requête anormalement longue ne peut être qu'un
// abus ou une erreur côté client, jamais un vrai nom d'artiste — on tronque
// plutôt que de renvoyer une erreur bloquante.
if (mb_strlen($artist) > 200) $artist = mb_substr($artist, 0, 200);
if (mb_strlen($trackTitle) > 200) $trackTitle = mb_substr($trackTitle, 0, 200);

echo json_encode(ytdl_fetch_artist_info($artist, $trackTitle));
