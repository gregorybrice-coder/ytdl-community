<?php
/**
 * Bibliothèque d'un autre compte, en lecture seule, pour quelqu'un qui en a
 * l'autorisation (voir library-access.php). Ne renvoie ni chemin de fichier
 * ni lien source : juste de quoi afficher et écouter (library-play.php).
 * "imported" = déjà dans ma bibliothèque par un partage.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/library-access-lib.php';
$user = ytdl_require_login();

header('Content-Type: application/json');

if (!ytdl_library_volume_mounted()) {
    ytdl_json_error('Disque de bibliothèque non monté : branchez le disque USB « ytdl ».', 503);
}

$ownerId = (int)($_GET['owner'] ?? 0);
if (!ytdl_viewer_has_library_access((int)$user['id'], $ownerId)) {
    ytdl_json_error("Tu n'as pas accès à cette bibliothèque.", 403);
}

$ownerStmt = ytdl_db()->prepare("SELECT id, name FROM users WHERE id = :id AND status = 'active'");
$ownerStmt->execute([':id' => $ownerId]);
$owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
if (!$owner) ytdl_json_error('Compte introuvable.', 404);

$stmt = ytdl_db()->prepare(
    "SELECT dh.id, dh.title, dh.thumbnail, dh.downloaded_at, dh.audio_only, dh.category, dh.uploader, dh.duration_seconds,
            dh.loudness_lufs, dh.peak_dbfs,
            COALESCE(tr.rating, 0) AS rating,
            EXISTS (SELECT 1 FROM library_shares ls WHERE ls.history_id = dh.id AND ls.shared_with_user_id = :me2) AS imported
     FROM download_history dh
     LEFT JOIN track_ratings tr ON tr.history_id = dh.id AND tr.user_id = :me
     WHERE dh.user_id = :owner AND dh.library_path IS NOT NULL AND dh.library_path <> ''
     ORDER BY dh.downloaded_at DESC"
);
$stmt->execute([':me' => $user['id'], ':me2' => $user['id'], ':owner' => $ownerId]);
$tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($tracks as &$track) {
    $track['gain_db'] = ytdl_normalization_gain_db(
        $track['loudness_lufs'] !== null ? (float)$track['loudness_lufs'] : null,
        $track['peak_dbfs'] !== null ? (float)$track['peak_dbfs'] : null
    );
    unset($track['loudness_lufs'], $track['peak_dbfs']);
    $track['imported'] = (int)$track['imported'];
}
unset($track);

echo json_encode(['owner' => $owner, 'tracks' => $tracks]);
