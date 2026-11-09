<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
$user = ytdl_require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ytdl_json_error('Méthode non autorisée.', 405);
}

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$endpoint = trim((string)($payload['endpoint'] ?? ''));

if ($endpoint !== '') {
    // Scopé à user_id : n'efface jamais l'abonnement d'un autre compte, même
    // si l'endpoint venait à être deviné/rejoué.
    ytdl_db()->prepare('DELETE FROM push_subscriptions WHERE endpoint = :endpoint AND user_id = :uid')
        ->execute([':endpoint' => $endpoint, ':uid' => $user['id']]);
}

echo json_encode(['ok' => true]);
