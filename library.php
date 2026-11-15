<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/genre-lib.php';
$user = ytdl_require_login();

header('Content-Type: application/json');

// Disque USB de la bibliothèque absent : mieux vaut le dire tout de suite que
// renvoyer une liste dont aucun fichier n'est lisible (voir YTDL_LIBRARY_ROOT).
if (!ytdl_library_volume_mounted()) {
    ytdl_json_error('Disque de bibliothèque non monté : branchez le disque USB « ytdl ».', 503);
}

// Mes propres fichiers. "url" est renvoyée pour permettre un retéléchargement
// facile depuis la page (lien direct vers la vidéo YouTube d'origine), par
// exemple si le fichier a expiré ou a été supprimé par erreur. La note par
// étoiles est PERSONNELLE (voir track_ratings) : jointe sur mon user_id, pas
// sur le propriétaire du fichier — même si je suis moi-même le propriétaire
// ici, la requête reste identique à celle des fichiers partagés plus bas.
$stmt = ytdl_db()->prepare(
    'SELECT dh.id, dh.title, dh.thumbnail, dh.downloaded_at, dh.audio_only, dh.category, dh.url, dh.uploader, dh.duration_seconds,
            dh.loudness_lufs, dh.peak_dbfs, dh.genre,
            COALESCE(tr.rating, 0) AS rating
     FROM download_history dh
     LEFT JOIN track_ratings tr ON tr.history_id = dh.id AND tr.user_id = :rating_user_id
     WHERE dh.user_id = :user_id AND dh.library_path IS NOT NULL
     ORDER BY dh.downloaded_at DESC'
);
$stmt->execute([':user_id' => $user['id'], ':rating_user_id' => $user['id']]);
$tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Avec qui chacun de mes fichiers est partagé — nécessaire pour pré-cocher
// la bonne liste en rouvrant le sélecteur de partage, et pour avertir avant
// suppression si quelqu'un en perdrait l'accès.
$sharesByTrack = [];
if ($tracks) {
    $ids = array_column($tracks, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $shareStmt = ytdl_db()->prepare("SELECT history_id, shared_with_user_id FROM library_shares WHERE history_id IN ($placeholders)");
    $shareStmt->execute($ids);
    foreach ($shareStmt->fetchAll() as $row) {
        $sharesByTrack[$row['history_id']][] = (int)$row['shared_with_user_id'];
    }
}

// Mes fichiers que d'autres m'ont recommandés (partagés alors que je les
// avais déjà, voir library-share.php) : « partagé par … » sur le titre.
$recommendedBy = [];
if ($tracks) {
    $recoStmt = ytdl_db()->prepare(
        "SELECT r.history_id, GROUP_CONCAT(u.name, ', ') AS names
         FROM track_recommendations r JOIN users u ON u.id = r.sender_user_id
         WHERE r.recipient_user_id = ? AND r.history_id IN ($placeholders)
         GROUP BY r.history_id"
    );
    $recoStmt->execute(array_merge([$user['id']], $ids));
    foreach ($recoStmt->fetchAll() as $row) {
        $recommendedBy[$row['history_id']] = $row['names'];
    }
}

foreach ($tracks as &$track) {
    $track['shared_with'] = $sharesByTrack[$track['id']] ?? [];
    $track['shared_count'] = count($track['shared_with']);
    if (isset($recommendedBy[$track['id']])) $track['shared_by_name'] = $recommendedBy[$track['id']];
}
unset($track);

// Fichiers que d'autres comptes ont partagés avec moi — jamais de copie,
// juste l'accès (voir library-play.php) ; disparaît tout seul si le
// propriétaire supprime son fichier (library_path repasse à NULL, donc
// exclu par ce filtre).
$sharedStmt = ytdl_db()->prepare(
    'SELECT dh.id, dh.title, dh.thumbnail, dh.downloaded_at, dh.audio_only, dh.category, dh.url, dh.uploader, dh.duration_seconds,
            dh.loudness_lufs, dh.peak_dbfs, dh.genre, u.name AS shared_by_name,
            COALESCE(tr.rating, 0) AS rating
     FROM library_shares ls
     JOIN download_history dh ON dh.id = ls.history_id
     JOIN users u ON u.id = ls.shared_by_user_id
     LEFT JOIN track_ratings tr ON tr.history_id = dh.id AND tr.user_id = :rating_user_id
     WHERE ls.shared_with_user_id = :user_id AND dh.library_path IS NOT NULL
     ORDER BY ls.created_at DESC'
);
$sharedStmt->execute([':user_id' => $user['id'], ':rating_user_id' => $user['id']]);
$sharedTracks = $sharedStmt->fetchAll(PDO::FETCH_ASSOC);

// Pour chaque fichier partagé avec moi, à qui MOI je l'ai éventuellement
// repartagé (jamais les partages faits par quelqu'un d'autre) — nécessaire
// pour pré-cocher le sélecteur si je rouvre le partage sur ce fichier.
$mySharesByTrack = [];
if ($sharedTracks) {
    $sharedIds = array_column($sharedTracks, 'id');
    $sharedPlaceholders = implode(',', array_fill(0, count($sharedIds), '?'));
    $myShareStmt = ytdl_db()->prepare(
        "SELECT history_id, shared_with_user_id FROM library_shares WHERE shared_by_user_id = ? AND history_id IN ($sharedPlaceholders)"
    );
    $myShareStmt->execute(array_merge([$user['id']], $sharedIds));
    foreach ($myShareStmt->fetchAll() as $row) {
        $mySharesByTrack[$row['history_id']][] = (int)$row['shared_with_user_id'];
    }
    // Y compris la recommandation faite au propriétaire d'origine.
    $myRecoStmt = ytdl_db()->prepare(
        "SELECT history_id, recipient_user_id FROM track_recommendations WHERE sender_user_id = ? AND history_id IN ($sharedPlaceholders)"
    );
    $myRecoStmt->execute(array_merge([$user['id']], $sharedIds));
    foreach ($myRecoStmt->fetchAll() as $row) {
        $mySharesByTrack[$row['history_id']][] = (int)$row['recipient_user_id'];
    }
}

foreach ($sharedTracks as &$track) {
    $track['shared_with'] = $mySharesByTrack[$track['id']] ?? [];
}
unset($track);

// Le client reçoit le gain DÉJÀ CALCULÉ, pas la mesure brute : la cible de
// référence et le plafond anti-écrêtage sont des règles de niveau serveur
// (voir ytdl_normalization_gain_db), et les changer ne doit rien demander au
// code du lecteur. Les deux colonnes brutes n'ont plus d'utilité ensuite.
$attachGain = function (array &$list): void {
    foreach ($list as &$track) {
        $track['gain_db'] = ytdl_normalization_gain_db(
            $track['loudness_lufs'] !== null ? (float)$track['loudness_lufs'] : null,
            $track['peak_dbfs'] !== null ? (float)$track['peak_dbfs'] : null
        );
        unset($track['loudness_lufs'], $track['peak_dbfs']);
    }
};
$attachGain($tracks);
$attachGain($sharedTracks);

// Titres pas encore classés par genre (nouveaux téléchargements, première mise en service) :
// le classement se fait en arrière-plan, sans retarder cette réponse.
ytdl_genre_spawn();

echo json_encode([
    'genres' => ytdl_genre_list(),
    'tracks' => $tracks,
    'shared_tracks' => $sharedTracks,
    'usage' => ytdl_library_usage($user['id']),
]);
