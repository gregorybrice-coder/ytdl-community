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
$keys = $payload['keys'] ?? [];
$p256dh = trim((string)($keys['p256dh'] ?? ''));
$auth   = trim((string)($keys['auth'] ?? ''));

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    ytdl_json_error('Abonnement incomplet.', 400);
}

// UNIQUE(endpoint) : si ce même endpoint existait déjà (réabonnement après
// changement de clé, ou compte différent sur le même appareil), on le
// réattribue au compte courant plutôt que d'échouer.
ytdl_db()->prepare(
    'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth)
     VALUES (:user_id, :endpoint, :p256dh, :auth)
     ON CONFLICT(endpoint) DO UPDATE SET user_id = :user_id2, p256dh = :p256dh2, auth = :auth2'
)->execute([
    ':user_id' => $user['id'], ':endpoint' => $endpoint, ':p256dh' => $p256dh, ':auth' => $auth,
    ':user_id2' => $user['id'], ':p256dh2' => $p256dh, ':auth2' => $auth,
]);

echo json_encode(['ok' => true]);
