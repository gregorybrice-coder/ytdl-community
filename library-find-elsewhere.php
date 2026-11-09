<?php
/**
 * Recherche un morceau dans les bibliothèques des AUTRES comptes, proposée
 * avant de passer à une recherche YouTube (voir capture.php,
 * refreshYoutubeSuggestions) — évite un nouveau téléchargement complet quand
 * le fichier existe déjà quelque part sur le serveur. Exclut ce que
 * l'utilisateur a déjà via un partage existant : inutile de le "découvrir",
 * il l'a déjà dans sa propre liste.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
$user = ytdl_require_login();

header('Content-Type: application/json');

$query = trim((string)($_GET['q'] ?? ''));
$audioOnly = ($_GET['audio_only'] ?? '1') === '1' ? 1 : 0;

if ($query === '') {
    echo json_encode(['results' => []]);
    exit;
}
if (mb_strlen($query) > 200) {
    $query = mb_substr($query, 0, 200);
}

// Même filtre substring insensible à la casse que la recherche locale côté
// client — un simple LIKE suffit à cette échelle (bibliothèque familiale).
$like = '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%';

$stmt = ytdl_db()->prepare(
    "SELECT dh.id, dh.title, dh.thumbnail, dh.uploader, u.name AS owner_name
     FROM download_history dh
     JOIN users u ON u.id = dh.user_id
     WHERE dh.user_id != :me
       AND dh.audio_only = :audio_only
       AND dh.library_path IS NOT NULL
       AND dh.title LIKE :q ESCAPE '\\'
       AND NOT EXISTS (
           SELECT 1 FROM library_shares ls
           WHERE ls.history_id = dh.id AND ls.shared_with_user_id = :me2
       )
     ORDER BY dh.downloaded_at DESC
     LIMIT 6"
);
$stmt->execute([
    ':me' => $user['id'],
    ':me2' => $user['id'],
    ':audio_only' => $audioOnly,
    ':q' => $like,
]);

echo json_encode(['results' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
