<?php
/**
 * Genre d'un titre, pour son propriétaire (le genre est celui du fichier, que
 * ses destinataires voient aussi).
 *
 * GET  ?suggest=1&id=…  : propose le genre de l'artiste d'après les bases
 *      musicales ouvertes (MusicBrainz, puis Wikidata) et l'enregistre comme
 *      genre automatique du titre. { genre, found, source, artist }
 * POST { id, genre }    : choix de l'utilisateur, jamais écrasé ensuite. Si le
 *      genre existe déjà (bibliothèque, liste de MusicBrainz), c'est le genre
 *      existant qui est utilisé : { ok, genre, existed }.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/genre-lib.php';
$user = ytdl_require_login();

header('Content-Type: application/json');

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
$id = (int)($input['id'] ?? 0);

$st = ytdl_db()->prepare('SELECT user_id, title, uploader, genre_source FROM download_history WHERE id = :id');
$st->execute([':id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
$st->closeCursor();
if (!$row) ytdl_json_error('Titre introuvable.', 404);
if ((int)$row['user_id'] !== (int)$user['id'] && (int)$user['is_admin'] !== 1) {
    ytdl_json_error('Seul le propriétaire du fichier peut changer son genre.', 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($row['genre_source'] ?? '') === 'manuel') {
        $cur = ytdl_db()->query('SELECT genre FROM download_history WHERE id = ' . $id)->fetchColumn();
        echo json_encode(['genre' => $cur, 'found' => true, 'source' => 'manuel', 'artist' => null]);
        exit;
    }
    [$artist] = ytdl_genre_parse((string)$row['title'], (string)$row['uploader']);
    try {
        [$genre, $source] = ytdl_genre_for_artist($artist);
    } catch (Throwable $e) {
        ytdl_json_error('Les bases musicales ne répondent pas pour le moment : le genre sera proposé plus tard.', 503);
    }
    ytdl_db()->prepare("UPDATE download_history SET genre = :g, genre_source = :s, genre_checked_at = datetime('now') WHERE id = :id AND COALESCE(genre_source, '') <> 'manuel'")
        ->execute([':g' => $genre, ':s' => $source, ':id' => $id]);
    echo json_encode(['genre' => $genre, 'found' => $source !== 'aucun', 'source' => $source, 'artist' => $artist]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ytdl_json_error('Méthode non autorisée.', 405);

$clean = ytdl_genre_clean((string)($input['genre'] ?? ''));
if ($clean === null) ytdl_json_error('Nom de genre invalide (40 caractères au plus).', 400);
// Un genre qui existe déjà (même écrit autrement : « hip-hop », « Hip Hop ») n'est jamais recréé.
$existing = $clean === YTDL_GENRE_UNCLASSIFIED ? YTDL_GENRE_UNCLASSIFIED : ytdl_genre_existing($clean);
$final = $existing ?? $clean;

ytdl_db()->prepare("UPDATE download_history SET genre = :g, genre_source = 'manuel', genre_checked_at = datetime('now') WHERE id = :id")
    ->execute([':g' => $final, ':id' => $id]);
echo json_encode(['ok' => true, 'genre' => $final, 'existed' => $existing !== null]);
