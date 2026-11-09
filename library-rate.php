<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
$user = ytdl_require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ytdl_json_error('Méthode non autorisée.', 405);
}

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int)($payload['id'] ?? 0);
$rating = (int)($payload['rating'] ?? -1);

if ($rating < 0 || $rating > 5) {
    ytdl_json_error('Note invalide (0 à 5).', 400);
}

// Il faut avoir accès au fichier (propriétaire OU destinataire d'un partage)
// pour pouvoir le noter — jamais un fichier qu'on ne voit même pas.
$stmt = ytdl_db()->prepare('SELECT user_id FROM download_history WHERE id = :id AND library_path IS NOT NULL');
$stmt->execute([':id' => $id]);
$ownerId = $stmt->fetchColumn();
if ($ownerId === false) {
    ytdl_json_error('Introuvable.', 404);
}
$hasAccess = (int)$ownerId === $user['id'];
if (!$hasAccess) {
    $accessStmt = ytdl_db()->prepare('SELECT 1 FROM library_shares WHERE history_id = :id AND shared_with_user_id = :uid');
    $accessStmt->execute([':id' => $id, ':uid' => $user['id']]);
    $hasAccess = $accessStmt->fetchColumn() !== false;
}
if (!$hasAccess) {
    ytdl_json_error("Tu n'as pas accès à ce fichier.", 403);
}

if ($rating === 0) {
    // 0 = retirer sa note plutôt que d'en stocker une "vide" : la pondération
    // de la lecture aléatoire traite déjà l'absence de ligne comme un poids
    // neutre, pas besoin de la garder.
    ytdl_db()->prepare('DELETE FROM track_ratings WHERE history_id = :id AND user_id = :uid')
        ->execute([':id' => $id, ':uid' => $user['id']]);
} else {
    ytdl_db()->prepare(
        'INSERT INTO track_ratings (history_id, user_id, rating) VALUES (:id, :uid, :rating)
         ON CONFLICT(history_id, user_id) DO UPDATE SET rating = :rating2, updated_at = datetime(\'now\')'
    )->execute([':id' => $id, ':uid' => $user['id'], ':rating' => $rating, ':rating2' => $rating]);
}

echo json_encode(['ok' => true, 'rating' => $rating]);
