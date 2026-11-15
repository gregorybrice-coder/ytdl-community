<?php
/**
 * Processus d'arrière-plan d'un import de playlist (voir import-lib.php) :
 * lancé par playlist-import-start.php, relancé par playlist-import-status.php
 * si le serveur a redémarré. Enchaîne les titres un par un — recherche
 * YouTube si besoin, téléchargement MP3, archivage en bibliothèque, ajout à
 * la playlist — sans qu'aucun navigateur n'ait besoin d'être ouvert.
 * Usage : php import-worker.php <id du travail>
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/download-start.php';
require_once __DIR__ . '/import-lib.php';

set_time_limit(0);
ignore_user_abort(true);

$jobId = (int)($argv[1] ?? 0);
$db = ytdl_db();
$db->exec('PRAGMA busy_timeout = 15000');

$jobStmt = $db->prepare('SELECT * FROM import_jobs WHERE id = :id');
$jobStmt->execute([':id' => $jobId]);
$job = $jobStmt->fetch(PDO::FETCH_ASSOC);
if (!$job || $job['status'] !== 'running') exit(0);
$oldPid = $job['worker_pid'] !== null ? (int)$job['worker_pid'] : null;
if (ytdl_import_worker_alive($oldPid)) exit(0); // déjà pris en charge
// Prise en charge atomique : si deux processus démarrent ensemble (relances
// rapprochées), un seul remplace l'ancien numéro de processus.
$claim = $db->prepare("UPDATE import_jobs SET worker_pid = :me, heartbeat_at = datetime('now') WHERE id = :id AND status = 'running' AND worker_pid IS :old");
$claim->execute([':me' => getmypid(), ':id' => $jobId, ':old' => $oldPid]);
if ($claim->rowCount() !== 1) exit(0);

$userId = (int)$job['user_id'];
// La base peut être brièvement verrouillée par une autre requête : on réessaie.
$retry = function (callable $fn) {
    for ($i = 0; ; $i++) {
        try { return $fn(); }
        catch (PDOException $e) { if ($i >= 8) throw $e; sleep(2); }
    }
};
$beat = function () use ($db, $jobId, $retry): void {
    $retry(fn() => $db->prepare("UPDATE import_jobs SET heartbeat_at = datetime('now') WHERE id = :id")->execute([':id' => $jobId]));
};
// Titres interrompus par un arrêt brutal : à refaire.
$retry(fn() => $db->prepare("UPDATE import_items SET status = 'pending' WHERE job_id = :id AND status = 'running'")->execute([':id' => $jobId]));

$setItem = function (int $itemId, string $status, ?string $error = null, ?int $historyId = null) use ($db, $retry): void {
    $retry(fn() => $db->prepare('UPDATE import_items SET status = :s, error = :e, history_id = :h WHERE id = :id')
       ->execute([':s' => $status, ':e' => $error, ':h' => $historyId, ':id' => $itemId]));
};
// Lecture complète PUIS fermeture du curseur : une requête laissée ouverte
// pendant un long téléchargement garde un instantané de la base, et la
// première écriture qui suit échoue alors aussitôt (« database is locked »).
$one = function (string $sql, array $params = []) use ($db, $retry): ?array {
    return $retry(function () use ($db, $sql, $params) {
        $st = $db->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $st->closeCursor();
        return $row ?: null;
    });
};
$isCancelled = function () use ($one, $jobId): bool {
    $r = $one('SELECT status FROM import_jobs WHERE id = :id', [':id' => $jobId]);
    return !$r || $r['status'] !== 'running';
};

while (!$retry($isCancelled)) {
    $next = $one("SELECT * FROM import_items WHERE job_id = :id AND status = 'pending' ORDER BY position LIMIT 1", [':id' => $jobId]);
    if (!$next) break;
    $itemId = (int)$next['id'];
    $setItem($itemId, 'running');
    $beat();

    try {
        $url = $next['youtube_url'] ?: ytdl_import_search_first(trim($next['artist'] . ' ' . $next['title']));
        if (!$url) throw new RuntimeException('introuvable');
        $url = ytdl_clean_url($url);
        if (!preg_match('#^https?://(www\.)?(youtube\.com|youtu\.be|m\.youtube\.com)/#i', $url)) throw new RuntimeException('lien invalide');

        // Déjà dans la bibliothèque sous ce même lien (reprise d'un import
        // interrompu, titre déjà téléchargé) : rien à refaire, juste le ranger.
        $known = $one("SELECT id FROM download_history WHERE user_id = :u AND url = :url AND audio_only = 1 AND library_path IS NOT NULL AND library_path <> '' ORDER BY id DESC LIMIT 1", [':u' => $userId, ':url' => $url]);
        $res = $known
            ? ['status' => 'duplicate', 'history_id' => (int)$known['id']]
            : ytdl_start_download($userId, $url, ['audioOnly' => true]);
        $historyId = null;
        $state = 'done';
        if ($res['status'] === 'duplicate') {
            $historyId = $res['history_id'];
            $state = 'duplicate';
            if (!$historyId) throw new RuntimeException('doublon introuvable');
        } else {
            // Attente de la fin du téléchargement (poursuivi par le shell lancé par ytdl_start_download).
            $jobDir = ytdl_jobs_root() . '/' . $res['jobId'];
            $deadline = time() + 45 * 60;
            while (trim((string)@file_get_contents($jobDir . '/status.txt')) !== 'done') {
                if (time() > $deadline) throw new RuntimeException('délai dépassé');
                sleep(2);
                $beat();
            }
            if (trim((string)@file_get_contents($jobDir . '/exitcode.txt')) !== '0') {
                ytdl_remove_job_dir($jobDir);
                throw new RuntimeException('échec du téléchargement');
            }
            $files = glob($jobDir . '/media.*');
            $file = $files[0] ?? null;
            if (!$file || !is_file($file)) { ytdl_remove_job_dir($jobDir); throw new RuntimeException('fichier introuvable'); }
            $historyId = ytdl_archive_job($jobDir, $file, pathinfo($file, PATHINFO_EXTENSION), (int)filesize($file));
            ytdl_remove_job_dir($jobDir);
            if (!$historyId) throw new RuntimeException('enregistrement impossible');
            $kept = $one('SELECT library_path FROM download_history WHERE id = :id', [':id' => (int)$historyId]);
            if (!$kept || !$kept['library_path']) throw new RuntimeException('quota atteint');
        }

        if ($next['playlist_id']) {
            $retry(fn() => $db->prepare(
                'INSERT OR IGNORE INTO playlist_items (playlist_id, history_id, position)
                 VALUES (:p, :h, COALESCE((SELECT MAX(position) + 1 FROM playlist_items WHERE playlist_id = :p2), 0))'
            )->execute([':p' => $next['playlist_id'], ':h' => $historyId, ':p2' => $next['playlist_id']]));
        }
        $setItem($itemId, $state, null, (int)$historyId);
    } catch (Throwable $e) {
        $setItem($itemId, 'failed', mb_substr($e->getMessage(), 0, 200));
    }
}

$final = $retry($isCancelled) ? null : 'done';
if ($final) {
    $retry(fn() => $db->prepare("UPDATE import_jobs SET status = 'done', finished_at = datetime('now'), worker_pid = NULL WHERE id = :id AND status = 'running'")->execute([':id' => $jobId]));
    $c = $one("SELECT SUM(status IN ('done','duplicate')) ok, COUNT(*) n FROM import_items WHERE job_id = :id", [':id' => $jobId]);
    ytdl_send_push($userId, 'Import terminé', (int)$c['ok'] . ' titre' . ((int)$c['ok'] > 1 ? 's' : '') . ' sur ' . (int)$c['n'] . ' ajouté' . ((int)$c['ok'] > 1 ? 's' : '') . ' à ta bibliothèque.', ['url' => '/ytdl/capture.php']);
}
ytdl_purge_stale_jobs();
