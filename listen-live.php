<?php
/**
 * « Partager ce que j'écoute » : un compte qui l'active laisse les autres voir
 * le titre qu'il écoute en ce moment et l'écouter avec lui, à la même seconde.
 * Désactivé par défaut ; le titre partagé est lisible par les autres comptes
 * (jamais téléchargeable) tant que le partage est actif.
 *
 * GET  : { sharing, live: [{ user_id, name, avatar, is_playing, position, age, track }] }
 *        position + age = où en est l'auditeur À L'INSTANT de la réponse.
 * POST : { enabled: bool } — active ou coupe MON partage.
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
    $db->prepare('UPDATE users SET share_listening = :v WHERE id = :id')->execute([':v' => !empty($p['enabled']) ? 1 : 0, ':id' => $me]);
    echo json_encode(['ok' => true, 'sharing' => !empty($p['enabled'])]);
    exit;
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
echo json_encode(['sharing' => $sharing, 'live' => $live]);
