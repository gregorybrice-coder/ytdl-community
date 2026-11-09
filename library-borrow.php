<?php
/**
 * "Emprunte" un morceau trouvé dans la bibliothèque d'un autre compte (voir
 * library-find-elsewhere.php) : accorde un accès (même table library_shares
 * que le partage classique lancé par le propriétaire), sans copier le
 * fichier. Volontairement initié par le DESTINATAIRE plutôt que par le
 * propriétaire — c'est bien lui qui a fait la démarche de chercher.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
$user = ytdl_require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ytdl_json_error('Méthode non autorisée.', 405);
}

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int)($payload['id'] ?? 0);

$stmt = ytdl_db()->prepare(
    'SELECT id, user_id, title, thumbnail, uploader, audio_only, url
     FROM download_history WHERE id = :id AND library_path IS NOT NULL'
);
$stmt->execute([':id' => $id]);
$track = $stmt->fetch(PDO::FETCH_ASSOC);
if ($track === false) {
    ytdl_json_error("Introuvable, ou ce fichier n'est plus disponible.", 404);
}

$ownerId = (int)$track['user_id'];
if ($ownerId === $user['id']) {
    ytdl_json_error('Ce fichier est déjà dans ta bibliothèque.', 400);
}

ytdl_db()->prepare(
    'INSERT OR IGNORE INTO library_shares (history_id, shared_with_user_id, shared_by_user_id) VALUES (:id, :me, :owner)'
)->execute([':id' => $id, ':me' => $user['id'], ':owner' => $ownerId]);
ytdl_ensure_shared_after_grant($id);

echo json_encode([
    'ok' => true,
    'track' => [
        'id' => (int)$track['id'],
        'title' => $track['title'],
        'thumbnail' => $track['thumbnail'],
        'uploader' => $track['uploader'],
        'audio_only' => (int)$track['audio_only'],
        'url' => $track['url'],
    ],
]);
