<?php
/**
 * Démarre un import de playlist EXÉCUTÉ PAR LE SERVEUR : les playlists sont
 * créées, les titres mis en file, et import-worker.php les télécharge un à un
 * même si l'appli est fermée ou le téléphone éteint (voir import-lib.php).
 * Corps : { groups: [{ name, tracks: [{ artist, title, youtube_url? }] }] }
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/import-lib.php';
$user = ytdl_require_login();

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') ytdl_json_error('Méthode non autorisée.', 405);
ytdl_maintenance_gate($user, true);

$db = ytdl_db();
$me = (int)$user['id'];

$active = $db->prepare("SELECT id FROM import_jobs WHERE user_id = :me AND status IN ('running', 'paused') LIMIT 1");
$active->execute([':me' => $me]);
if ($active->fetchColumn() !== false) ytdl_json_error('Un import est déjà en cours : attends sa fin ou arrête-le.', 409);

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$groups = is_array($payload['groups'] ?? null) ? $payload['groups'] : [];
$total = 0;
foreach ($groups as $g) $total += count($g['tracks'] ?? []);
if (!$groups || $total === 0) ytdl_json_error('Aucun titre à importer.', 400);
if ($total > 2000) ytdl_json_error('Trop de titres d’un coup (2000 au plus).', 400);

$db->beginTransaction();
try {
    $db->prepare('INSERT INTO import_jobs (user_id, heartbeat_at) VALUES (:me, datetime(\'now\'))')->execute([':me' => $me]);
    $jobId = (int)$db->lastInsertId();
    $find = $db->prepare('SELECT id FROM playlists WHERE user_id = :me AND name = :n COLLATE NOCASE');
    $makePl = $db->prepare('INSERT INTO playlists (user_id, name) VALUES (:me, :n)');
    $item = $db->prepare(
        'INSERT INTO import_items (job_id, playlist_id, position, artist, title, youtube_url) VALUES (:j, :p, :pos, :a, :t, :u)'
    );
    $pos = 0;
    foreach ($groups as $g) {
        $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($g['name'] ?? ''))), 0, 80) ?: 'Playlist importée';
        $find->execute([':me' => $me, ':n' => $name]);
        $playlistId = $find->fetchColumn();
        if ($playlistId === false) {
            $makePl->execute([':me' => $me, ':n' => $name]);
            $playlistId = $db->lastInsertId();
        }
        foreach (($g['tracks'] ?? []) as $t) {
            $url = trim((string)($t['youtube_url'] ?? ''));
            $item->execute([
                ':j' => $jobId, ':p' => (int)$playlistId, ':pos' => $pos++,
                ':a' => mb_substr(trim((string)($t['artist'] ?? '')), 0, 200),
                ':t' => mb_substr(trim((string)($t['title'] ?? '')), 0, 300),
                ':u' => preg_match('#^https://www\.youtube\.com/watch\?v=[\w-]{11}$#', $url) ? $url : null,
            ]);
        }
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('ytdl playlist-import-start : ' . $e->getMessage());
    ytdl_json_error('Impossible de préparer l’import.', 500);
}

if (!ytdl_import_spawn($jobId)) {
    $db->prepare("UPDATE import_jobs SET status = 'done', finished_at = datetime('now') WHERE id = :id")->execute([':id' => $jobId]);
    ytdl_json_error('Le serveur ne peut pas lancer l’import (php introuvable).', 500);
}
echo json_encode(['ok' => true, 'job_id' => $jobId, 'total' => $total]);
