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

// Même filtre substring que la recherche locale côté client, sur une clé
// normalisée (accents, casse, ponctuation) : un LIKE SQL ne reconnaît pas
// "Don't" dans "Don’t". Filtré en PHP, à cette échelle (bibliothèque
// familiale) c'est sans coût notable.
$needle = ytdl_search_key($query);
if ($needle === '') {
    echo json_encode(['results' => []]);
    exit;
}

$stmt = ytdl_db()->prepare(
    "SELECT dh.id, dh.title, dh.thumbnail, dh.uploader, u.name AS owner_name
     FROM download_history dh
     JOIN users u ON u.id = dh.user_id
     WHERE dh.user_id != :me
       AND dh.audio_only = :audio_only
       AND dh.library_path IS NOT NULL
       AND NOT EXISTS (
           SELECT 1 FROM library_shares ls
           WHERE ls.history_id = dh.id AND ls.shared_with_user_id = :me2
       )
     ORDER BY dh.downloaded_at DESC"
);
$stmt->execute([
    ':me' => $user['id'],
    ':me2' => $user['id'],
    ':audio_only' => $audioOnly,
]);

$results = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (mb_strpos(ytdl_search_key((string)$row['title']), $needle) === false) continue;
    $results[] = $row;
    if (count($results) >= 6) break;
}

echo json_encode(['results' => $results]);
