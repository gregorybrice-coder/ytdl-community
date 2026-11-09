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
$userIds = array_values(array_unique(array_map('intval', $payload['user_ids'] ?? [])));

// Le propriétaire OU un destinataire d'un partage existant peut repartager —
// mais jamais vers le propriétaire d'origine (qui a déjà le fichier chez lui).
$stmt = ytdl_db()->prepare(
    'SELECT user_id, title, library_path, audio_only FROM download_history WHERE id = :id AND library_path IS NOT NULL'
);
$stmt->execute([':id' => $id]);
$track = $stmt->fetch(PDO::FETCH_ASSOC);
if ($track === false) {
    ytdl_json_error("Introuvable, ou ce fichier n'est plus disponible.", 404);
}
$ownerId = (int)$track['user_id'];
$trackTitle = $track['title'] ?: 'un fichier';

// Qui a déjà accès à ce fichier avant cette requête (tous partageurs
// confondus) : sert plus bas à ne notifier que les VRAIS nouveaux
// destinataires, pas quelqu'un qui avait déjà accès et le garde simplement.
$alreadySharedStmt = ytdl_db()->prepare('SELECT shared_with_user_id FROM library_shares WHERE history_id = :id');
$alreadySharedStmt->execute([':id' => $id]);
$previouslyShared = array_map('intval', array_column($alreadySharedStmt->fetchAll(), 'shared_with_user_id'));

$isOwner = $ownerId === $user['id'];
$hasAccess = $isOwner;
if (!$hasAccess) {
    $accessStmt = ytdl_db()->prepare(
        'SELECT 1 FROM library_shares WHERE history_id = :id AND shared_with_user_id = :uid'
    );
    $accessStmt->execute([':id' => $id, ':uid' => $user['id']]);
    $hasAccess = $accessStmt->fetchColumn() !== false;
}
if (!$hasAccess) {
    ytdl_json_error("Tu n'as pas accès à ce fichier.", 403);
}

$validIds = [];
$db = ytdl_db();
$db->beginTransaction();
try {
    // On fixe l'état exact plutôt que d'ajouter : correspond à une liste de
    // cases à cocher qu'on peut aussi décocher. Portée à shared_by_user_id =
    // moi : ne touche jamais aux partages faits par quelqu'un d'autre (le
    // propriétaire, ou un autre destinataire ayant lui aussi repartagé).
    $db->prepare('DELETE FROM library_shares WHERE history_id = :id AND shared_by_user_id = :actor')
        ->execute([':id' => $id, ':actor' => $user['id']]);

    if ($userIds) {
        // IN (...) avec des entiers déjà castés : aucune donnée utilisateur
        // brute n'entre dans le SQL, l'échappement standard PDO suffit ici
        // mais on reste explicite avec des placeholders nommés numérotés.
        $placeholders = [];
        $params = [':self' => $user['id'], ':owner' => $ownerId];
        foreach ($userIds as $i => $uid) {
            $key = ':u' . $i;
            $placeholders[] = $key;
            $params[$key] = $uid;
        }
        // status='active', id != self, id != owner : impossible de se
        // partager à soi-même, de repartager au propriétaire d'origine, ou de
        // cibler un compte pas encore approuvé — même en trafiquant la requête.
        $validStmt = $db->prepare(
            "SELECT id FROM users WHERE status = 'active' AND id != :self AND id != :owner AND id IN (" . implode(',', $placeholders) . ")"
        );
        $validStmt->execute($params);
        $validIds = array_column($validStmt->fetchAll(), 'id');

        // OR IGNORE : si un autre destinataire a déjà partagé à la même
        // personne (UNIQUE(history_id, shared_with_user_id)), on ignore
        // silencieusement plutôt que de faire échouer tout le lot.
        $insert = $db->prepare(
            'INSERT OR IGNORE INTO library_shares (history_id, shared_with_user_id, shared_by_user_id) VALUES (:id, :recipient, :actor)'
        );
        foreach ($validIds as $recipientId) {
            $insert->execute([':id' => $id, ':recipient' => $recipientId, ':actor' => $user['id']]);
        }
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    ytdl_json_error('Échec du partage.', 500);
}

ytdl_ensure_shared_after_grant($id);

// Notification push : uniquement les destinataires qui n'avaient PAS déjà
// accès avant cette requête — ni un simple "je réaffiche le même partage",
// ni les gens qui l'avaient déjà via quelqu'un d'autre.
$newRecipients = array_diff($validIds, $previouslyShared);
// Réponse envoyée tout de suite : les notifications partent ensuite, sans
// faire attendre la personne qui partage (chaque envoi push contacte un
// service externe).
echo json_encode(['ok' => true]);
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
foreach ($newRecipients as $recipientId) {
    ytdl_send_push(
        (int)$recipientId,
        'Nouveau partage',
        $user['name'] . ' t’a partagé « ' . $trackTitle . ' ».',
        ['url' => '/ytdl/capture.php']
    );
}
