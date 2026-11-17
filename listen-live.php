<?php
/**
 * « Partager ce que j'écoute » : un compte qui l'active laisse les autres voir
 * le titre qu'il écoute en ce moment et l'écouter avec lui, à la même seconde.
 * Désactivé par défaut ; le titre partagé est lisible par les autres comptes
 * (jamais téléchargeable) tant que le partage est actif.
 *
 * GET  : { sharing, live: [{ user_id, name, avatar, is_playing, position, age, track }] }
 *        position + age = où en est l'auditeur À L'INSTANT de la réponse.
 *        ?following=ID : signale que j'écoute avec ce compte ; « listeners » = ceux qui écoutent avec moi.
 * POST : { enabled: bool } — active ou coupe MON partage ; { leave: true } — j'arrête d'écouter avec quelqu'un.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/library-access-lib.php';
$user = ytdl_require_login();

header('Content-Type: application/json');
$db = ytdl_db();
$me = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = json_decode(file_get_contents('php://input'), true) ?: [];
    // J'arrête d'écouter avec quelqu'un.
    if (!empty($p['leave'])) {
        $db->prepare('DELETE FROM listen_along WHERE listener_id = :me')->execute([':me' => $me]);
        echo json_encode(['ok' => true]);
        exit;
    }
    $on = !empty($p['enabled']) ? 1 : 0;
    $db->prepare('UPDATE users SET share_listening = :v WHERE id = :id')->execute([':v' => $on, ':id' => $me]);
    // Partage coupé : plus personne n'écoute avec moi.
    if (!$on) $db->prepare('DELETE FROM listen_along WHERE host_id = :me')->execute([':me' => $me]);
    echo json_encode(['ok' => true, 'sharing' => (bool)$on]);
    exit;
}

// ?following=ID : j'écoute avec ce compte (appelé toutes les 3 s tant que je le suis) ;
// il en est prévenu discrètement (voir « listeners » plus bas).
$following = (int)($_GET['following'] ?? 0);
if ($following > 0 && $following !== $me) {
    $chk = $db->prepare("SELECT 1 FROM users WHERE id = :id AND share_listening = 1 AND status = 'active'");
    $chk->execute([':id' => $following]);
    if ($chk->fetchColumn() !== false) {
        $db->prepare('INSERT INTO listen_along (listener_id, host_id, updated_ts) VALUES (:me, :h, :ts)
                      ON CONFLICT(listener_id) DO UPDATE SET host_id = :h2, updated_ts = :ts2')
           ->execute([':me' => $me, ':h' => $following, ':ts' => microtime(true), ':h2' => $following, ':ts2' => microtime(true)]);
    }
}

$now = microtime(true);
// Un partage « en direct » : le compte lit un titre, l'état a moins de 90 s
// (la position est enregistrée toutes les 4 s pendant le partage).
$stmt = $db->prepare(
    "SELECT u.id AS user_id, u.name, u.avatar, ps.position_seconds, ps.is_playing, ps.updated_ts,
            dh.id AS history_id, dh.title, dh.thumbnail, dh.uploader, dh.audio_only, dh.category, dh.duration_seconds,
            dh.loudness_lufs, dh.peak_dbfs, dh.genre
     FROM users u
     JOIN playback_state ps ON ps.user_id = u.id
     JOIN download_history dh ON dh.id = ps.history_id
     WHERE u.share_listening = 1 AND u.status = 'active' AND u.id <> :me
       AND ps.is_playing = 1 AND ps.updated_ts > :since
       AND dh.library_path IS NOT NULL AND dh.library_path <> '' AND dh.audio_only = 1
     ORDER BY ps.updated_ts DESC"
);
$stmt->execute([':me' => $me, ':since' => $now - 90]);

$live = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $age = max(0.0, $now - (float)$r['updated_ts']);
    $live[] = [
        'user_id' => (int)$r['user_id'], 'name' => $r['name'], 'avatar' => $r['avatar'],
        'is_playing' => 1,
        // Où il en est maintenant : dernière position enregistrée + temps écoulé depuis.
        'position' => (float)$r['position_seconds'] + $age,
        'age' => round($age, 2),
        'track' => [
            'id' => (int)$r['history_id'], 'title' => $r['title'], 'thumbnail' => $r['thumbnail'], 'uploader' => $r['uploader'],
            'audio_only' => (int)$r['audio_only'], 'category' => $r['category'], 'duration_seconds' => $r['duration_seconds'],
            'genre' => $r['genre'],
            'gain_db' => ytdl_normalization_gain_db(
                $r['loudness_lufs'] !== null ? (float)$r['loudness_lufs'] : null,
                $r['peak_dbfs'] !== null ? (float)$r['peak_dbfs'] : null
            ),
        ],
    ];
}

$sharing = (int)$db->query('SELECT share_listening FROM users WHERE id = ' . $me)->fetchColumn() === 1;

// Ceux qui écoutent avec moi en ce moment (renouvelés depuis moins de 15 s).
$listeners = [];
if ($sharing) {
    $ls = $db->prepare('SELECT u.id AS user_id, u.name, u.avatar FROM listen_along la JOIN users u ON u.id = la.listener_id
                        WHERE la.host_id = :me AND la.updated_ts > :since ORDER BY u.name');
    $ls->execute([':me' => $me, ':since' => $now - 15]);
    $listeners = array_map(fn($r) => ['user_id' => (int)$r['user_id'], 'name' => $r['name'], 'avatar' => $r['avatar']], $ls->fetchAll(PDO::FETCH_ASSOC));
}
echo json_encode(['sharing' => $sharing, 'live' => $live, 'listeners' => $listeners]);
