<?php
/**
 * Écritures sur les playlists : création, renommage, suppression, et
 * appartenance d'un morceau.
 *
 * Un seul fichier pour quatre actions plutôt que quatre endpoints d'une
 * dizaine de lignes chacun : ils partagent tous le même contrôle de
 * propriété, qu'on ne veut pas voir diverger.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
$user = ytdl_require_login();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = (string)($input['action'] ?? '');
$pdo = ytdl_db();

/** Vérifie que la playlist appartient bien au compte courant. */
function ytdl_own_playlist(PDO $pdo, int $playlistId, int $userId): array {
    $stmt = $pdo->prepare('SELECT * FROM playlists WHERE id = :id AND user_id = :user_id');
    $stmt->execute([':id' => $playlistId, ':user_id' => $userId]);
    $playlist = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$playlist) {
        http_response_code(404);
        echo json_encode(['error' => 'Playlist introuvable.']);
        exit;
    }
    return $playlist;
}

/** Nom nettoyé et non vide, longueur bornée. */
function ytdl_playlist_name($raw): string {
    $name = trim(preg_replace('/\s+/u', ' ', (string)$raw));
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Donne un nom à la playlist.']);
        exit;
    }
    return mb_substr($name, 0, 80);
}

if ($action === 'create') {
    $name = ytdl_playlist_name($input['name'] ?? '');

    // Deux playlists du même nom ne servent qu'à s'y perdre.
    $exists = $pdo->prepare('SELECT id FROM playlists WHERE user_id = :user_id AND name = :name COLLATE NOCASE');
    $exists->execute([':user_id' => $user['id'], ':name' => $name]);
    if ($existing = $exists->fetchColumn()) {
        echo json_encode(['ok' => true, 'id' => (int)$existing, 'name' => $name, 'existed' => true]);
        exit;
    }

    $insert = $pdo->prepare('INSERT INTO playlists (user_id, name) VALUES (:user_id, :name)');
    $insert->execute([':user_id' => $user['id'], ':name' => $name]);

    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'name' => $name]);
    exit;
}

if ($action === 'rename') {
    $playlist = ytdl_own_playlist($pdo, (int)($input['id'] ?? 0), (int)$user['id']);
    $name = ytdl_playlist_name($input['name'] ?? '');

    $update = $pdo->prepare('UPDATE playlists SET name = :name WHERE id = :id');
    $update->execute([':name' => $name, ':id' => $playlist['id']]);

    echo json_encode(['ok' => true, 'name' => $name]);
    exit;
}

if ($action === 'delete') {
    $playlist = ytdl_own_playlist($pdo, (int)($input['id'] ?? 0), (int)$user['id']);

    // Seul le regroupement disparaît : les fichiers, eux, restent en
    // bibliothèque (playlist_items part en cascade, download_history non).
    $delete = $pdo->prepare('DELETE FROM playlists WHERE id = :id');
    $delete->execute([':id' => $playlist['id']]);

    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'set_membership') {
    // Synchronise l'appartenance d'UN morceau à l'ensemble des playlists :
    // reçoit la liste complète des playlists cochées, en déduit les ajouts et
    // les retraits. Évite un aller-retour par case cochée.
    $historyId = (int)($input['history_id'] ?? 0);
    $wanted = array_values(array_unique(array_map('intval', (array)($input['playlist_ids'] ?? []))));

    // Le morceau doit être accessible au compte : le sien, ou partagé avec lui.
    $access = $pdo->prepare(
        'SELECT dh.id FROM download_history dh
         WHERE dh.id = :id AND (dh.user_id = :user_id OR EXISTS (
             SELECT 1 FROM library_shares ls WHERE ls.history_id = dh.id AND ls.shared_with_user_id = :user_id2
         ))'
    );
    $access->execute([':id' => $historyId, ':user_id' => $user['id'], ':user_id2' => $user['id']]);
    if (!$access->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'Morceau introuvable.']);
        exit;
    }

    // Les playlists visées doivent toutes m'appartenir — sinon on pourrait
    // écrire dans celles d'un autre compte en devinant un id.
    $mine = $pdo->prepare('SELECT id FROM playlists WHERE user_id = :user_id');
    $mine->execute([':user_id' => $user['id']]);
    $ownedIds = array_map('intval', $mine->fetchAll(PDO::FETCH_COLUMN));
    $wanted = array_values(array_intersect($wanted, $ownedIds));

    $pdo->beginTransaction();
    try {
        // Retraits : toutes mes playlists qui contiennent ce morceau sans
        // figurer dans la sélection. Marqueurs positionnels uniquement, PDO
        // n'acceptant pas de les mélanger avec des marqueurs nommés.
        $sql = 'DELETE FROM playlist_items WHERE history_id = ? AND playlist_id IN (
                    SELECT id FROM playlists WHERE user_id = ?
                )';
        if ($wanted) {
            $sql .= ' AND playlist_id NOT IN (' . implode(',', array_fill(0, count($wanted), '?')) . ')';
        }
        $pdo->prepare($sql)->execute(array_merge([$historyId, $user['id']], $wanted));

        // Ajouts : position = fin de la playlist concernée.
        $insert = $pdo->prepare(
            'INSERT OR IGNORE INTO playlist_items (playlist_id, history_id, position)
             VALUES (:playlist_id, :history_id,
                     COALESCE((SELECT MAX(position) + 1 FROM playlist_items WHERE playlist_id = :playlist_id2), 0))'
        );
        foreach ($wanted as $playlistId) {
            $insert->execute([
                ':playlist_id' => $playlistId,
                ':history_id' => $historyId,
                ':playlist_id2' => $playlistId,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('ytdl playlist set_membership : ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => "L'enregistrement a échoué."]);
        exit;
    }

    echo json_encode(['ok' => true, 'playlist_ids' => $wanted]);
    exit;
}

if ($action === 'add_items') {
    // Ajoute plusieurs morceaux en fin de playlist, dans l'ordre reçu (ceux
    // déjà présents sont ignorés). Sert à enregistrer une recherche comme
    // playlist, ou à la fusionner dans une playlist existante.
    $playlist = ytdl_own_playlist($pdo, (int)($input['id'] ?? 0), (int)$user['id']);
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($input['history_ids'] ?? [])))));
    if (!$ids || count($ids) > 2000) {
        http_response_code(400);
        echo json_encode(['error' => 'Aucun morceau à ajouter.']);
        exit;
    }

    // Seuls les morceaux accessibles au compte (les siens, ou partagés avec lui).
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $access = $pdo->prepare(
        "SELECT dh.id FROM download_history dh
         WHERE dh.id IN ($placeholders) AND (dh.user_id = ? OR EXISTS (
             SELECT 1 FROM library_shares ls WHERE ls.history_id = dh.id AND ls.shared_with_user_id = ?
         ))"
    );
    $access->execute(array_merge($ids, [$user['id'], $user['id']]));
    $allowed = array_flip(array_map('intval', $access->fetchAll(PDO::FETCH_COLUMN)));

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT OR IGNORE INTO playlist_items (playlist_id, history_id, position)
             VALUES (:playlist_id, :history_id,
                     COALESCE((SELECT MAX(position) + 1 FROM playlist_items WHERE playlist_id = :playlist_id2), 0))'
        );
        $added = 0;
        foreach ($ids as $historyId) {
            if (!isset($allowed[$historyId])) continue;
            $insert->execute([':playlist_id' => $playlist['id'], ':history_id' => $historyId, ':playlist_id2' => $playlist['id']]);
            $added += $insert->rowCount();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('ytdl playlist add_items : ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => "L'enregistrement a échoué."]);
        exit;
    }

    echo json_encode(['ok' => true, 'added' => $added]);
    exit;
}

if ($action === 'remove_item') {
    $playlist = ytdl_own_playlist($pdo, (int)($input['id'] ?? 0), (int)$user['id']);
    $historyId = (int)($input['history_id'] ?? 0);

    $delete = $pdo->prepare('DELETE FROM playlist_items WHERE playlist_id = :playlist_id AND history_id = :history_id');
    $delete->execute([':playlist_id' => $playlist['id'], ':history_id' => $historyId]);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Action inconnue.']);
