<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
ytdl_require_login();

header('Content-Type: application/json');

// Pendant vraiment du mode "bibliothèque uniquement" : archive le résultat du
// job comme file.php le fait après avoir streamé le fichier à l'appareil,
// mais sans jamais le streamer — rien ne part vers le navigateur, seule la
// copie serveur (mini-lecteur / lecteur vidéo en page) est créée. Utile pour
// éviter tout downstream vers un gestionnaire de téléchargement d'appareil
// capricieux (voir file.php et le bug résolu avec device_id).

$jobId  = $_GET['job'] ?? '';
$jobDir = ytdl_job_dir($jobId);

if (!is_dir($jobDir)) {
    ytdl_json_error('Tâche introuvable (expirée ou invalide).', 404);
}

$status = trim((string)@file_get_contents($jobDir . '/status.txt'));
if ($status !== 'done') {
    ytdl_json_error("Le fichier n'est pas encore prêt.", 409);
}

$candidates = glob($jobDir . '/media.*');
$filePath = $candidates[0] ?? null;
if (!$filePath || !is_file($filePath)) {
    ytdl_json_error("Le fichier attendu est introuvable (le téléchargement a peut-être échoué).", 500);
}
$extension = pathinfo($filePath, PATHINFO_EXTENSION);
$fileSize  = filesize($filePath);
$title     = trim((string)@file_get_contents($jobDir . '/title.txt')) ?: 'media';

$historyId = ytdl_archive_job($jobDir, $filePath, $extension, $fileSize);
ytdl_remove_job_dir($jobDir);

echo json_encode(['ok' => true, 'title' => $title, 'history_id' => $historyId]);
