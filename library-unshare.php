<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
$user = ytdl_require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ytdl_json_error('Méthode non autorisée.', 405);
}

$id = (int)($_POST['id'] ?? 0);

// Ne retire QUE mon propre accès (shared_with_user_id = moi) : jamais le
// fichier réel, jamais l'accès des autres personnes à qui ce même fichier
// aurait été partagé par ailleurs (par le propriétaire ou un autre
// destinataire) — une simple sortie de ma propre liste, rien de plus.
ytdl_db()->prepare('DELETE FROM library_shares WHERE history_id = :id AND shared_with_user_id = :self')
    ->execute([':id' => $id, ':self' => $user['id']]);

echo json_encode(['ok' => true]);
