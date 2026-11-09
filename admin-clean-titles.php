<?php
/**
 * Version web (admin uniquement) de migrate-clean-titles.php : repasse
 * ytdl_clean_title() sur TOUS les titres de la bibliothèque (tous comptes),
 * et relance une recherche de paroles avec le titre nettoyé pour les
 * morceaux renommés qui n'en avaient pas encore. Toujours appliqué
 * directement (pas de mode dry-run ici — le bouton admin lance l'action
 * réelle, contrairement au script CLI pensé pour être relu avant --apply).
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
ytdl_require_admin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

$pdo = ytdl_db();
$renamed = [];
$lyricsFoundNow = 0;
$lyricsRechecked = 0;

$rows = $pdo->query(
    "SELECT id, title, uploader, duration_seconds FROM download_history WHERE title IS NOT NULL AND title <> ''"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    $oldTitle = $row['title'];
    $newTitle = ytdl_clean_title($oldTitle);
    if ($newTitle === $oldTitle) continue;

    $pdo->prepare('UPDATE download_history SET title = :title WHERE id = :id')
        ->execute([':title' => $newTitle, ':id' => $row['id']]);
    $renamed[] = ['id' => (int)$row['id'], 'before' => $oldTitle, 'after' => $newTitle];

    $duration = $row['duration_seconds'] !== null ? (float)$row['duration_seconds'] : null;
    $oldParts = ytdl_split_artist_title($oldTitle, $row['uploader']);
    if ($oldParts['artist'] === '' || $oldParts['title'] === '') continue;

    $oldResult = ytdl_fetch_lyrics_data($oldParts['artist'], $oldParts['title'], $duration);
    if ($oldResult['lyrics'] !== null) continue; // déjà trouvées sous l'ancien titre

    $lyricsRechecked++;
    $newParts = ytdl_split_artist_title($newTitle, $row['uploader']);
    if ($newParts['artist'] === '' || $newParts['title'] === '') continue;
    if ($newParts['artist'] === $oldParts['artist'] && $newParts['title'] === $oldParts['title']) continue;

    $newResult = ytdl_fetch_lyrics_data($newParts['artist'], $newParts['title'], $duration);
    if ($newResult['lyrics'] !== null) $lyricsFoundNow++;
}

echo json_encode([
    'renamed' => $renamed,
    'renamed_count' => count($renamed),
    'lyrics_rechecked' => $lyricsRechecked,
    'lyrics_found_now' => $lyricsFoundNow,
]);
