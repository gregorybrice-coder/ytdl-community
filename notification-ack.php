<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
$user = ytdl_require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ytdl_json_error('Méthode non autorisée.', 405);
}

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$notificationId = (int)($payload['id'] ?? 0);

if ($notificationId > 0) {
    $pdo = ytdl_db();
    // Retire UNIQUEMENT l'id acquitté de la liste — un compte peut avoir
    // plusieurs notifications en attente, vues (et donc acquittées) une par
    // une au fil des effets néon déclenchés côté client, jamais toutes d'un
    // coup.
    $stmt = $pdo->prepare('SELECT notification_ids FROM users WHERE id = :id');
    $stmt->execute([':id' => $user['id']]);
    $current = (string)$stmt->fetchColumn();
    $ids = array_filter(
        explode(',', $current),
        fn($v) => $v !== '' && (int)$v !== $notificationId
    );
    $pdo->prepare('UPDATE users SET notification_ids = :ids WHERE id = :id')
        ->execute([':ids' => implode(',', $ids), ':id' => $user['id']]);
}

echo json_encode(['ok' => true]);
