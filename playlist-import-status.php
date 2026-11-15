<?php
/**
 * État de mon dernier import de playlist (voir playlist-import-start.php).
 * GET  : { job: null | { id, status, total, done, ok, failed, seen, items? } }
 *        ?detail=1 ajoute la liste des titres avec leur état. Relance au
 *        passage le processus d'un import dont le serveur a redémarré.
 * POST : { action: cancel | ack, id } — arrêter un import / marquer son bilan vu.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/import-lib.php';
$user = ytdl_require_login();

header('Content-Type: application/json');
$db = ytdl_db();
$me = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($p['id'] ?? 0);
    if (($p['action'] ?? '') === 'cancel') {
        // Le titre en cours se termine, les suivants ne sont pas lancés.
        $db->prepare("UPDATE import_jobs SET status = 'done', finished_at = datetime('now') WHERE id = :id AND user_id = :me AND status IN ('running', 'paused')")->execute([':id' => $id, ':me' => $me]);
        $db->prepare("UPDATE import_items SET status = 'failed', error = 'arrêté' WHERE job_id = :id AND status = 'pending' AND job_id IN (SELECT id FROM import_jobs WHERE user_id = :me)")->execute([':id' => $id, ':me' => $me]);
    } elseif (($p['action'] ?? '') === 'resume') {
        $st = $db->prepare("UPDATE import_jobs SET status = 'running', heartbeat_at = datetime('now', '-1 hour') WHERE id = :id AND user_id = :me AND status = 'paused'");
        $st->execute([':id' => $id, ':me' => $me]);
        if ($st->rowCount() === 1) ytdl_import_spawn($id);
    } elseif (($p['action'] ?? '') === 'ack') {
        $db->prepare('UPDATE import_jobs SET seen = 1 WHERE id = :id AND user_id = :me')->execute([':id' => $id, ':me' => $me]);
    } else {
        ytdl_json_error('Action inconnue.', 400);
    }
    echo json_encode(['ok' => true]);
    exit;
}

$stmt = $db->prepare('SELECT * FROM import_jobs WHERE user_id = :me ORDER BY id DESC LIMIT 1');
$stmt->execute([':me' => $me]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$job) { echo json_encode(['job' => null]); exit; }

ytdl_import_revive($job);

$counts = $db->prepare(
    "SELECT COUNT(*) AS total,
            SUM(status IN ('done','duplicate')) AS ok,
            SUM(status = 'failed') AS failed,
            SUM(status = 'skipped') AS skipped,
            SUM(status IN ('done','duplicate','failed','skipped')) AS finished
     FROM import_items WHERE job_id = :id"
);
$counts->execute([':id' => $job['id']]);
$c = $counts->fetch(PDO::FETCH_ASSOC);

$out = [
    'id' => (int)$job['id'], 'status' => $job['status'], 'seen' => (int)$job['seen'],
    'total' => (int)$c['total'], 'done' => (int)$c['finished'], 'ok' => (int)$c['ok'], 'failed' => (int)$c['failed'], 'skipped' => (int)$c['skipped'],
];
if (($_GET['detail'] ?? '') === '1' || $job['status'] !== 'running') {
    $it = $db->prepare('SELECT artist, title, status, error FROM import_items WHERE job_id = :id ORDER BY position');
    $it->execute([':id' => $job['id']]);
    $out['items'] = $it->fetchAll(PDO::FETCH_ASSOC);
}
echo json_encode(['job' => $out]);
