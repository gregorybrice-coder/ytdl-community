<?php
/**
 * Lecture des playlists du compte.
 *
 * Sans paramètre : la liste, avec le nombre d'éléments de chacune.
 * Avec ?id=N : le contenu détaillé de cette playlist, dans l'ordre.
 *
 * Un élément n'est renvoyé que si son fichier est toujours accessible AU
 * COMPTE COURANT — le sien, ou un fichier partagé avec lui. Sans ce filtre,
 * une playlist pourrait exposer le titre d'un fichier dont le partage a été
 * retiré depuis.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
$user = ytdl_require_login();

header('Content-Type: application/json');

$pdo = ytdl_db();
$playlistId = (int)($_GET['id'] ?? 0);

// Clause d'accès commune : propriétaire du fichier, ou destinataire d'un
// partage. Identique à celle de library-play.php, qui garde le fichier lui-même.
const YTDL_PLAYLIST_ACCESS_SQL = '(dh.user_id = :user_id OR EXISTS (
        SELECT 1 FROM library_shares ls
        WHERE ls.history_id = dh.id AND ls.shared_with_user_id = :user_id2
    ))';

if ($playlistId > 0) {
    $check = $pdo->prepare('SELECT id, name FROM playlists WHERE id = :id AND user_id = :user_id');
    $check->execute([':id' => $playlistId, ':user_id' => $user['id']]);
    $playlist = $check->fetch(PDO::FETCH_ASSOC);

    if (!$playlist) {
        http_response_code(404);
        echo json_encode(['error' => 'Playlist introuvable.']);
        exit;
    }

    // url + user_id + infos de partage : mêmes champs que library.php, pour
    // que les boutons d'action (source YouTube, suppression/retrait de
    // partage avec avertissement) fonctionnent identiquement dans la vue
    // playlist — ils s'appuient dessus sans savoir d'où vient la ligne.
    $stmt = $pdo->prepare(
        'SELECT dh.id, dh.title, dh.thumbnail, dh.audio_only, dh.uploader, dh.loudness_lufs, dh.peak_dbfs,
                dh.url, dh.user_id, pi.position,
                u.name AS shared_by_name,
                (SELECT COUNT(*) FROM library_shares ls2 WHERE ls2.history_id = dh.id) AS shared_count
         FROM playlist_items pi
         JOIN download_history dh ON dh.id = pi.history_id
         LEFT JOIN library_shares ls ON ls.history_id = dh.id AND ls.shared_with_user_id = :user_id3
         LEFT JOIN users u ON u.id = ls.shared_by_user_id
         WHERE pi.playlist_id = :playlist_id
           AND dh.library_path IS NOT NULL AND dh.library_path <> ""
           AND ' . YTDL_PLAYLIST_ACCESS_SQL . '
         ORDER BY pi.position ASC, pi.id ASC'
    );
    $stmt->execute([
        ':playlist_id' => $playlistId,
        ':user_id' => $user['id'],
        ':user_id2' => $user['id'],
        ':user_id3' => $user['id'],
    ]);

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // À qui chacun de MES fichiers de cette playlist est déjà partagé — pour
    // pré-cocher le sélecteur si on rouvre le partage depuis cette vue (même
    // logique que library.php, ici limitée aux fichiers de cette playlist).
    $sharesByTrack = [];
    $myIds = array_values(array_filter(array_map(
        fn($it) => ((int) $it['user_id']) === $user['id'] ? (int) $it['id'] : null,
        $items
    )));
    if ($myIds) {
        $placeholders = implode(',', array_fill(0, count($myIds), '?'));
        $shareStmt = $pdo->prepare("SELECT history_id, shared_with_user_id FROM library_shares WHERE history_id IN ($placeholders)");
        $shareStmt->execute($myIds);
        foreach ($shareStmt->fetchAll() as $row) {
            $sharesByTrack[$row['history_id']][] = (int) $row['shared_with_user_id'];
        }
    }

    foreach ($items as &$item) {
        $item['_shared'] = ((int) $item['user_id']) !== $user['id'];
        // shared_count/shared_with n'ont de sens que pour le propriétaire
        // (pour un fichier partagé avec moi, ça compterait/listerait les
        // partages de quelqu'un d'autre).
        if ($item['_shared']) {
            $item['shared_count'] = 0;
        } else {
            $item['shared_with'] = $sharesByTrack[$item['id']] ?? [];
        }
        unset($item['user_id']);
    }
    unset($item);
    // Le fichier peut avoir été purgé du disque sans que library_path soit
    // encore repassé à NULL (la purge tourne au chargement de page) : on
    // vérifie donc aussi sa présence réelle, comme l'anti-doublon.
    $playlist['items'] = array_values(array_map(
        fn($it) => [
            'id' => (int)$it['id'],
            'title' => $it['title'],
            'thumbnail' => $it['thumbnail'],
            'audio_only' => (int)$it['audio_only'],
            'uploader' => $it['uploader'],
            // Correction de niveau, comme dans library.php : sans elle, un
            // morceau lu depuis une playlist repasserait au niveau brut du
            // fichier alors que le même morceau est corrigé en bibliothèque.
            'gain_db' => ytdl_normalization_gain_db(
                $it['loudness_lufs'] !== null ? (float)$it['loudness_lufs'] : null,
                $it['peak_dbfs'] !== null ? (float)$it['peak_dbfs'] : null
            ),
        ],
        $items
    ));

    echo json_encode(['playlist' => $playlist]);
    exit;
}

// Liste : le compte d'éléments suit les mêmes règles d'accès, sinon une
// playlist afficherait « 5 morceaux » pour n'en jouer que 3.
$stmt = $pdo->prepare(
    'SELECT p.id, p.name, p.created_at,
            (SELECT COUNT(*) FROM playlist_items pi
             JOIN download_history dh ON dh.id = pi.history_id
             WHERE pi.playlist_id = p.id
               AND dh.library_path IS NOT NULL AND dh.library_path <> ""
               AND ' . YTDL_PLAYLIST_ACCESS_SQL . ') AS item_count
     FROM playlists p
     WHERE p.user_id = :owner_id
     ORDER BY p.name COLLATE NOCASE ASC'
);
$stmt->execute([
    ':owner_id' => $user['id'],
    ':user_id' => $user['id'],
    ':user_id2' => $user['id'],
]);

// Jusqu'à 4 vignettes piochées au hasard par playlist, pour la mosaïque
// affichée à la place de la note ♪ générique (voir renderPlaylistList() côté
// client) — une requête par playlist plutôt qu'une jointure globale : le
// nombre de playlists reste faible (usage personnel/familial), largement
// plus simple à lire qu'un ORDER BY RANDOM() partitionné en SQL pur.
$thumbStmt = $pdo->prepare(
    'SELECT dh.thumbnail FROM playlist_items pi
     JOIN download_history dh ON dh.id = pi.history_id
     WHERE pi.playlist_id = :playlist_id
       AND dh.library_path IS NOT NULL AND dh.library_path <> ""
       AND dh.thumbnail IS NOT NULL AND dh.thumbnail <> ""
       AND ' . YTDL_PLAYLIST_ACCESS_SQL . '
     ORDER BY RANDOM() LIMIT 4'
);

$playlists = array_map(
    function ($p) use ($thumbStmt, $user) {
        $thumbStmt->execute([
            ':playlist_id' => $p['id'],
            ':user_id' => $user['id'],
            ':user_id2' => $user['id'],
        ]);
        return [
            'id' => (int)$p['id'],
            'name' => $p['name'],
            'item_count' => (int)$p['item_count'],
            'thumbnails' => array_column($thumbStmt->fetchAll(PDO::FETCH_ASSOC), 'thumbnail'),
        ];
    },
    $stmt->fetchAll(PDO::FETCH_ASSOC)
);

// Appartenance de chaque morceau : permet de pré-cocher les bonnes cases en
// ouvrant le sélecteur « ajouter à une playlist » sans un appel par morceau.
$membership = [];
$memberStmt = $pdo->prepare(
    'SELECT pi.history_id, pi.playlist_id FROM playlist_items pi
     JOIN playlists p ON p.id = pi.playlist_id
     WHERE p.user_id = :user_id'
);
$memberStmt->execute([':user_id' => $user['id']]);
foreach ($memberStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $membership[(int)$row['history_id']][] = (int)$row['playlist_id'];
}

echo json_encode(['playlists' => $playlists, 'membership' => (object)$membership]);
