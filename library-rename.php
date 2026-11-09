<?php
/**
 * Renommage manuel d'un titre (admin uniquement) — voir startRenameTrack/
 * saveTrackRename dans capture.php, un <input> qui remplace la ligne en
 * place, comme un renommage de fichier sous Windows. Le titre est partagé
 * par tout le monde qui tombe sur ce même fichier (comme le nettoyage
 * automatique, voir ytdl_clean_title), pas un réglage par compte.
 *
 * Si le titre change et que le morceau n'a pas encore de paroles trouvées,
 * relance une recherche avec le nouveau titre — même logique que
 * migrate-clean-titles.php / admin-clean-titles.php.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
ytdl_require_admin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ytdl_json_error('Méthode non autorisée.', 405);
}

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int)($payload['id'] ?? 0);
$newTitle = trim((string)($payload['title'] ?? ''));

if ($id <= 0 || $newTitle === '') {
    ytdl_json_error('Paramètres invalides.', 400);
}
if (mb_strlen($newTitle) > 300) $newTitle = mb_substr($newTitle, 0, 300);

$pdo = ytdl_db();
$stmt = $pdo->prepare('SELECT title, uploader, duration_seconds FROM download_history WHERE id = :id');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    ytdl_json_error('Morceau introuvable.', 404);
}

$oldTitle = $row['title'];
$lyricsFoundNow = false;

if ($newTitle !== $oldTitle) {
    $pdo->prepare('UPDATE download_history SET title = :title WHERE id = :id')
        ->execute([':title' => $newTitle, ':id' => $id]);

    $duration = $row['duration_seconds'] !== null ? (float)$row['duration_seconds'] : null;
    $oldParts = ytdl_split_artist_title($oldTitle, $row['uploader']);
    if ($oldParts['artist'] !== '' && $oldParts['title'] !== '') {
        $oldResult = ytdl_fetch_lyrics_data($oldParts['artist'], $oldParts['title'], $duration);
        if ($oldResult['lyrics'] === null) {
            $newParts = ytdl_split_artist_title($newTitle, $row['uploader']);
            if ($newParts['artist'] !== '' && $newParts['title'] !== ''
                && ($newParts['artist'] !== $oldParts['artist'] || $newParts['title'] !== $oldParts['title'])) {
                $newResult = ytdl_fetch_lyrics_data($newParts['artist'], $newParts['title'], $duration);
                $lyricsFoundNow = $newResult['lyrics'] !== null;
            }
        }
    }
}

echo json_encode(['ok' => true, 'title' => $newTitle, 'lyrics_found_now' => $lyricsFoundNow]);
