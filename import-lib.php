<?php
/**
 * Import de playlist côté serveur : travaux (import_jobs / import_items, voir
 * db.php), lancement et relance du processus d'arrière-plan
 * (import-worker.php), recherche YouTube d'un titre sans lien.
 */
require_once __DIR__ . '/lib.php';

/** Le processus de ce travail tourne-t-il encore ? */
function ytdl_import_worker_alive(?int $pid): bool {
    if (!$pid) return false;
    $out = trim((string)@shell_exec('ps -p ' . (int)$pid . ' -o command= 2>/dev/null'));
    return $out !== '' && str_contains($out, 'import-worker.php');
}

/** Lance import-worker.php en tâche de fond, indépendante de la requête. */
function ytdl_import_spawn(int $jobId): bool {
    $php = ytdl_which('php');
    if (!$php) return false;
    $log = sys_get_temp_dir() . '/ytdl-import-' . $jobId . '.log';
    $cmd = sprintf('nohup %s %s %d >> %s 2>&1 &', escapeshellarg($php), escapeshellarg(__DIR__ . '/import-worker.php'), $jobId, escapeshellarg($log));
    exec($cmd);
    return true;
}

/**
 * Relance le processus d'un travail resté « en cours » dont le processus a
 * disparu (redémarrage du serveur, plantage). Appelé à chaque lecture de
 * l'état, donc dès que l'appli est ouverte ; sans effet si le processus vit.
 */
function ytdl_import_revive(array $job): void {
    if ($job['status'] !== 'running') return;
    if (ytdl_import_worker_alive($job['worker_pid'] !== null ? (int)$job['worker_pid'] : null)) return;
    // Un processus qui vient d'être lancé s'annonce (battement) en une seconde ou deux ;
    // les doublons éventuels s'écartent d'eux-mêmes (prise en charge atomique).
    $hb = $job['heartbeat_at'] ? strtotime($job['heartbeat_at'] . ' UTC') : 0;
    if ($hb > time() - 15) return;
    ytdl_import_spawn((int)$job['id']);
}

/** Premier résultat YouTube pour « artiste titre », ou null. */
function ytdl_import_search_first(string $query): ?string {
    $ytDlp = ytdl_which('yt-dlp');
    if (!$ytDlp || trim($query) === '') return null;
    $scratch = ytdl_scratch_dir();
    $cmd = sprintf(
        '%s%s%s --flat-playlist --skip-download --print %s -- %s',
        escapeshellarg($ytDlp), ytdl_js_runtime_flag(), ytdl_cookies_flag($scratch),
        escapeshellarg('%(id)s'), escapeshellarg('ytsearch1:' . mb_substr($query, 0, 200))
    );
    [$out] = ytdl_run_capture($cmd);
    ytdl_rm_scratch($scratch);
    $id = trim(strtok($out, "\n") ?: '');
    return preg_match('/^[\w-]{11}$/', $id) ? 'https://www.youtube.com/watch?v=' . $id : null;
}
