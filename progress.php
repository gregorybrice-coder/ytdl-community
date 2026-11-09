<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
ytdl_require_login();

header('Content-Type: application/json');

$jobId  = $_GET['job'] ?? '';
$jobDir = ytdl_job_dir($jobId);

if (!is_dir($jobDir)) {
    ytdl_json_error('Tâche introuvable (expirée ou invalide).', 404);
}

$title      = trim((string)@file_get_contents($jobDir . '/title.txt'));
$statusFile = @file_get_contents($jobDir . '/status.txt') ?: 'running';
$log        = @file_get_contents($jobDir . '/log.txt') ?: '';

// --- Erreur : le process est terminé mais avec un code de sortie non nul ---
if (trim($statusFile) === 'done' && is_file($jobDir . '/exitcode.txt')) {
    $exitCode = trim((string)file_get_contents($jobDir . '/exitcode.txt'));
    if ($exitCode !== '0') {
        $lines = array_filter(explode("\n", $log));
        $lastLines = implode("\n", array_slice($lines, -6));
        echo json_encode([
            'phase' => 'error', 'percent' => 0, 'title' => $title,
            'label' => 'Échec du téléchargement',
            'error' => $lastLines ?: 'yt-dlp a échoué.',
        ]);
        exit;
    }
}

// --- Terminé avec succès ---
if (trim($statusFile) === 'done') {
    echo json_encode(['phase' => 'done', 'percent' => 100, 'title' => $title, 'label' => 'Terminé']);
    exit;
}

// --- En cours : on parse le log pour estimer une progression globale ---
// Compte le nombre de flux distincts déjà entamés (vidéo, puis audio) pour
// savoir dans quelle tranche de la barre on se trouve.
$destinationCount = preg_match_all('/\[download\] Destination:/', $log);
$alreadyDownloaded = preg_match_all('/has already been downloaded/', $log);
$streamIndex = max($destinationCount, $alreadyDownloaded > 0 ? 1 : 0);

$currentPercent = 0.0;
if (preg_match_all('/\[download\]\s+([\d.]+)%/', $log, $matches)) {
    $currentPercent = (float)end($matches[1]);
}

$merging = (bool)preg_match('/\[Merger\]|\[VideoConvertor\]|\[ffmpeg\]/', $log);
$extractingAudio = (bool)preg_match('/\[ExtractAudio\]/', $log);
$isFallback = is_file($jobDir . '/fallback.txt');
// retry.txt seul = on refait un essai dans la qualité demandée ; fallback.txt
// = on a renoncé à cette qualité et on télécharge le flux combiné de secours.
$isRetry = !$isFallback && is_file($jobDir . '/retry.txt');
$prefix = $isFallback ? 'Nouvelle tentative en qualité standard — ' : '';
$retryPrefix = $isRetry ? 'Nouvelle tentative — ' : '';

if ($extractingAudio) {
    $percent = 95;
    $label = 'Conversion en MP3…';
    $phase = 'converting';
} elseif ($isFallback) {
    // Le repli télécharge un flux déjà combiné (vidéo+son) : une seule étape,
    // pas de phase audio séparée ni de fusion.
    $percent = ($currentPercent / 100) * 95;
    $label = $prefix . round($currentPercent) . ' %';
    $phase = 'video';
} elseif ($merging) {
    $percent = 95;
    $label = 'Fusion audio/vidéo…';
    $phase = 'merging';
} elseif ($streamIndex <= 1) {
    // Phase 1 : piste vidéo, 0 → 55 %
    $percent = ($currentPercent / 100) * 55;
    $label = $retryPrefix . 'Téléchargement de la vidéo… ' . round($currentPercent) . ' %';
    $phase = 'video';
} else {
    // Phase 2 : piste audio, 55 → 90 %
    $percent = 55 + ($currentPercent / 100) * 35;
    $label = $retryPrefix . "Téléchargement de l'audio… " . round($currentPercent) . ' %';
    $phase = 'audio';
}

echo json_encode([
    'phase' => $phase,
    'percent' => round($percent, 1),
    'title' => $title,
    'label' => $label,
]);
