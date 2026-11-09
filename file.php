<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
ytdl_require_login();

ini_set('memory_limit', '-1');
set_time_limit(0);

$jobId  = $_GET['job'] ?? '';
$jobDir = ytdl_job_dir($jobId);

if (!is_dir($jobDir)) {
    ytdl_json_error('Tâche introuvable (expirée ou invalide).', 404);
}

$status = trim((string)@file_get_contents($jobDir . '/status.txt'));
if ($status !== 'done') {
    ytdl_json_error("Le fichier n'est pas encore prêt.", 409);
}

// "media.*" plutôt qu'un nom fixe : le mode audio seul produit un .mp3, le
// mode vidéo un .mp4.
$candidates = glob($jobDir . '/media.*');
$filePath = $candidates[0] ?? null;
if (!$filePath || !is_file($filePath)) {
    ytdl_json_error("Le fichier attendu est introuvable (le téléchargement a peut-être échoué).", 500);
}
$extension = pathinfo($filePath, PATHINFO_EXTENSION);

$title    = trim((string)@file_get_contents($jobDir . '/title.txt')) ?: 'media';
$fileName = ytdl_sanitize_filename($title) . '.' . $extension;
// Repli ASCII pour les vieux clients, filename* (RFC 5987) pour que les
// navigateurs actuels affichent correctement les accents/emoji du titre.
$asciiFallback = preg_replace('/[^\x20-\x7E]/', '_', $fileName);
$fileSize = filesize($filePath);

$start = 0;
$end = $fileSize - 1;
$isRange = false;

// Support des requêtes Range : Safari iOS s'appuie dessus pour son
// gestionnaire de téléchargement natif, en particulier sur les gros fichiers
// (les vidéos, bien plus que les MP3) — son absence est une cause connue et
// documentée d'échecs silencieux côté iOS. Même logique que library-play.php.
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    $isRange = true;
    if ($m[1] !== '') $start = (int)$m[1];
    if ($m[2] !== '') $end = (int)$m[2];
    if ($end >= $fileSize) $end = $fileSize - 1;
    if ($start > $end) { $start = 0; $end = $fileSize - 1; $isRange = false; }
}

$length = $end - $start + 1;

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . addslashes($asciiFallback) . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
header('Content-Transfer-Encoding: binary');
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
header('X-Accel-Buffering: no');
if ($isRange) {
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$fileSize");
}

// Diffusion par blocs de 1 Mo : le serveur de dev PHP intégré ne fait pas de
// vrai streaming sendfile() comme Apache/nginx, il faut donc éviter de tout
// charger d'un coup.
while (ob_get_level() > 0) ob_end_clean();

$handle = fopen($filePath, 'rb');
if ($handle !== false) {
    fseek($handle, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(1024 * 1024, $remaining));
        echo $chunk;
        flush();
        $remaining -= strlen($chunk);
    }
    fclose($handle);
}

// Historique par appareil : n'enregistre (et ne nettoie le dossier de job)
// que si cette réponse contenait bien le dernier octet du fichier — une
// requête Range portant sur un segment antérieur (le gestionnaire de
// téléchargement d'iOS peut découper le transfert en plusieurs requêtes)
// ne doit ni clôturer l'historique ni supprimer un fichier que le navigateur
// n'a pas fini de récupérer.
if ($end !== $fileSize - 1) {
    exit;
}

ytdl_archive_job($jobDir, $filePath, $extension, $fileSize);

// Nettoyage : le job a rempli son rôle une fois le fichier transmis. On vide
// le dossier entier plutôt qu'une liste nommée de fichiers — cette liste
// oubliait les marqueurs (retry/fallback) et le rmdir échouait alors en
// silence, laissant le job s'accumuler dans /tmp.
ytdl_remove_job_dir($jobDir);
