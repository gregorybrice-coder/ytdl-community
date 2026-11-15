<?php
/**
 * Demandes d'accès à la bibliothèque d'un autre compte (lecture seule, voir
 * db.php / library-access-lib.php).
 *
 * GET  : où j'en suis avec chaque compte, les bibliothèques que je peux
 *        consulter, et les demandes que j'ai reçues.
 * POST : action = request (owner_id) | respond (viewer_id, decision =
 *        approve|deny|revoke) | leave (owner_id : annule ma demande ou quitte).
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/library-access-lib.php';
$user = ytdl_require_login();

header('Content-Type: application/json');
$db = ytdl_db();
$me = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Mes relations en tant que demandeur, avec chaque autre compte actif.
    $stmt = $db->prepare(
        "SELECT u.id, u.name, u.avatar, la.status
         FROM users u
         LEFT JOIN library_access la ON la.owner_user_id = u.id AND la.viewer_user_id = :me
         WHERE u.status = 'active' AND u.id <> :me2
         ORDER BY u.name"
    );
    $stmt->execute([':me' => $me, ':me2' => $me]);
    $users = [];
    $libraries = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $state = $row['status'] ?? 'none';
        $users[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'avatar' => $row['avatar'], 'state' => $state];
        if ($state === 'approved') {
            $libraries[] = ['owner_id' => (int)$row['id'], 'name' => $row['name'], 'avatar' => $row['avatar'],
                            'count' => ytdl_library_track_count((int)$row['id'])];
        }
    }

    // Demandes reçues : en attente d'abord, puis les accès déjà accordés
    // (pour pouvoir les retirer). Les refus ne s'affichent plus.
    $inStmt = $db->prepare(
        "SELECT la.viewer_user_id AS viewer_id, u.name, u.avatar, la.status, la.created_at
         FROM library_access la JOIN users u ON u.id = la.viewer_user_id
         WHERE la.owner_user_id = :me AND la.status IN ('pending', 'approved') AND u.status = 'active'
         ORDER BY (la.status = 'pending') DESC, u.name"
    );
    $inStmt->execute([':me' => $me]);
    $incoming = array_map(
        fn($r) => ['viewer_id' => (int)$r['viewer_id'], 'name' => $r['name'], 'avatar' => $r['avatar'],
                   'status' => $r['status'], 'created_at' => $r['created_at']],
        $inStmt->fetchAll(PDO::FETCH_ASSOC)
    );

    echo json_encode(['users' => $users, 'libraries' => $libraries, 'incoming' => $incoming,
                      'pending_count' => count(array_filter($incoming, fn($r) => $r['status'] === 'pending'))]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ytdl_json_error('Méthode non autorisée.', 405);
}

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($payload['action'] ?? '');

$activeUser = function (int $id) use ($db): ?array {
    $s = $db->prepare("SELECT id, name, email FROM users WHERE id = :id AND status = 'active'");
    $s->execute([':id' => $id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
};

if ($action === 'request') {
    $ownerId = (int)($payload['owner_id'] ?? 0);
    $owner = $ownerId !== $me ? $activeUser($ownerId) : null;
    if (!$owner) ytdl_json_error('Compte introuvable.', 404);

    $s = $db->prepare('SELECT status, decided_at FROM library_access WHERE owner_user_id = :o AND viewer_user_id = :v');
    $s->execute([':o' => $ownerId, ':v' => $me]);
    $existing = $s->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        if ($existing['status'] === 'approved') ytdl_json_error('Tu as déjà accès à cette bibliothèque.', 409);
        if ($existing['status'] === 'pending') ytdl_json_error('Ta demande est déjà en attente.', 409);
        // Refusée : pas de nouvelle demande avant 7 jours (évite d'insister).
        if ($existing['decided_at'] && strtotime($existing['decided_at'] . ' UTC') > time() - 7 * 86400) {
            ytdl_json_error('Ta demande a été refusée : tu pourras la renouveler dans quelques jours.', 409);
        }
        $db->prepare("UPDATE library_access SET status = 'pending', created_at = datetime('now'), decided_at = NULL WHERE owner_user_id = :o AND viewer_user_id = :v")
            ->execute([':o' => $ownerId, ':v' => $me]);
    } else {
        $db->prepare("INSERT INTO library_access (owner_user_id, viewer_user_id) VALUES (:o, :v)")
            ->execute([':o' => $ownerId, ':v' => $me]);
    }

    // Réponse tout de suite : les notifications (push, e-mail) partent ensuite,
    // sans faire attendre le demandeur.
    echo json_encode(['ok' => true]);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    try { ytdl_notify_library_access_request($owner, (string)$user['name']); }
    catch (Throwable $e) { error_log('ytdl library access request : ' . $e->getMessage()); }
    exit;
}

if ($action === 'respond') {
    $viewerId = (int)($payload['viewer_id'] ?? 0);
    $decision = (string)($payload['decision'] ?? '');
    if (!in_array($decision, ['approve', 'deny', 'revoke'], true)) ytdl_json_error('Décision invalide.', 400);

    $s = $db->prepare('SELECT status FROM library_access WHERE owner_user_id = :o AND viewer_user_id = :v');
    $s->execute([':o' => $me, ':v' => $viewerId]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) ytdl_json_error('Demande introuvable.', 404);

    $newStatus = $decision === 'approve' ? 'approved' : 'denied';
    $db->prepare("UPDATE library_access SET status = :s, decided_at = datetime('now') WHERE owner_user_id = :o AND viewer_user_id = :v")
        ->execute([':s' => $newStatus, ':o' => $me, ':v' => $viewerId]);

    echo json_encode(['ok' => true, 'status' => $newStatus]);
    if ($decision === 'approve' && $row['status'] !== 'approved') {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        $viewer = $activeUser($viewerId);
        if ($viewer) {
            try { ytdl_notify_library_access_granted($viewer, (string)$user['name']); }
            catch (Throwable $e) { error_log('ytdl library access granted : ' . $e->getMessage()); }
        }
    }
    exit;
}

if ($action === 'leave') {
    $ownerId = (int)($payload['owner_id'] ?? 0);
    $db->prepare('DELETE FROM library_access WHERE owner_user_id = :o AND viewer_user_id = :v')
        ->execute([':o' => $ownerId, ':v' => $me]);
    echo json_encode(['ok' => true]);
    exit;
}

ytdl_json_error('Action inconnue.', 400);
