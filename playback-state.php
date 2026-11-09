<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
$user = ytdl_require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Titre/vignette ne sont pas stockés ici : le client les retrouve dans sa
    // bibliothèque déjà chargée via history_id. On ne renvoie que ce qui ne
    // peut pas se déduire côté client.
    $stmt = ytdl_db()->prepare('SELECT history_id, position_seconds, device_id, is_playing, playlist_id, updated_at FROM playback_state WHERE user_id = :uid');
    $stmt->execute([':uid' => $user['id']]);
    $state = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['state' => $state ?: null]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ytdl_json_error('Méthode non autorisée.', 405);
}

// navigator.sendBeacon() (utilisé à la fermeture de l'onglet/appli) envoie en
// text/plain, pas en JSON — le corps reste du JSON valide, seul le Content-Type
// diffère, donc on le décode pareil dans les deux cas.
$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$historyId = (int)($payload['history_id'] ?? 0);
$position = (float)($payload['position_seconds'] ?? -1);
$deviceId = trim((string)($payload['device_id'] ?? ''));
$isPlaying = !empty($payload['is_playing']) ? 1 : 0;
$playlistId = isset($payload['playlist_id']) && $payload['playlist_id'] !== null ? (int)$payload['playlist_id'] : null;

if ($historyId <= 0 || $position < 0 || $deviceId === '') {
    ytdl_json_error('Paramètres invalides.', 400);
}

// Même règle d'accès que pour noter un morceau : propriétaire ou destinataire
// d'un partage, jamais un fichier qu'on ne voit même pas.
$stmt = ytdl_db()->prepare('SELECT user_id FROM download_history WHERE id = :id AND library_path IS NOT NULL');
$stmt->execute([':id' => $historyId]);
$ownerId = $stmt->fetchColumn();
if ($ownerId === false) {
    ytdl_json_error('Introuvable.', 404);
}
$hasAccess = (int)$ownerId === $user['id'];
if (!$hasAccess) {
    $accessStmt = ytdl_db()->prepare('SELECT 1 FROM library_shares WHERE history_id = :id AND shared_with_user_id = :uid');
    $accessStmt->execute([':id' => $historyId, ':uid' => $user['id']]);
    $hasAccess = $accessStmt->fetchColumn() !== false;
}
if (!$hasAccess) {
    ytdl_json_error("Tu n'as pas accès à ce fichier.", 403);
}

ytdl_db()->prepare(
    'INSERT INTO playback_state (user_id, history_id, position_seconds, device_id, is_playing, playlist_id, updated_at)
     VALUES (:uid, :hid, :pos, :dev, :playing, :plid, datetime(\'now\'))
     ON CONFLICT(user_id) DO UPDATE SET history_id = :hid2, position_seconds = :pos2, device_id = :dev2, is_playing = :playing2, playlist_id = :plid2, updated_at = datetime(\'now\')'
)->execute([
    ':uid' => $user['id'], ':hid' => $historyId, ':pos' => $position, ':dev' => $deviceId, ':playing' => $isPlaying, ':plid' => $playlistId,
    ':hid2' => $historyId, ':pos2' => $position, ':dev2' => $deviceId, ':playing2' => $isPlaying, ':plid2' => $playlistId,
]);

echo json_encode(['ok' => true]);
