<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
ytdl_require_login();

header('Content-Type: application/json');

$ytDlp = ytdl_which('yt-dlp');
if (!$ytDlp) ytdl_json_error('yt-dlp est introuvable sur le serveur.', 500);

$query = trim((string)($_GET['q'] ?? ''));
if ($query === '') {
    echo json_encode(['results' => []]);
    exit;
}
// Une requête déraisonnablement longue ne peut être qu'un abus ou une erreur
// côté client (jamais un vrai titre de morceau) : on la tronque plutôt que
// de renvoyer une erreur bloquante pour un simple champ de recherche.
if (mb_strlen($query) > 200) {
    $query = mb_substr($query, 0, 200);
}

$limit = (int)($_GET['limit'] ?? 6);
if ($limit < 1) $limit = 1;
if ($limit > 10) $limit = 10;

// \x1f (Unit Separator) comme délimiteur, une ligne par résultat : ne peut
// pas apparaître dans un titre, contrairement à un caractère plus courant.
// --flat-playlist évite de résoudre chaque vidéo individuellement (ce que
// title.php fait, lui, pour UNE vidéo déjà choisie) : ici on liste juste des
// candidats parmi lesquels choisir, la résolution complète n'arrive qu'au
// clic (voir title.php, appelé par createRow() côté client une fois l'URL
// retenue) — nettement plus rapide, ce qui compte pour une recherche qui se
// déclenche pendant que l'utilisateur tape encore.
$printFormat = "%(id)s\x1f%(title)s\x1f%(uploader)s\x1f%(duration)s";

$scratch = ytdl_scratch_dir();
$cmd = sprintf(
    '%s%s%s --flat-playlist --skip-download --print %s -- %s',
    escapeshellarg($ytDlp),
    ytdl_js_runtime_flag(),
    ytdl_cookies_flag($scratch),
    escapeshellarg($printFormat),
    escapeshellarg('ytsearch' . $limit . ':' . $query)
);

[$out, $err] = ytdl_run_capture($cmd);
ytdl_rm_scratch($scratch);

if ($out === '') {
    // Contrairement à title.php (une seule URL invalide = erreur bloquante),
    // une recherche sans résultat n'est pas une erreur pour l'appelant : la
    // recherche bibliothèque sait déjà afficher "aucun résultat".
    echo json_encode(['results' => [], 'error' => $err !== '' ? ytdl_explain_error($err) : null]);
    exit;
}

$results = [];
foreach (explode("\n", $out) as $line) {
    $line = trim($line);
    if ($line === '') continue;
    $parts = explode("\x1f", $line);
    $id = $parts[0] ?? '';
    if ($id === '' || $id === 'NA') continue;
    $title    = $parts[1] ?? '';
    $uploader = $parts[2] ?? '';
    $results[] = [
        'id'        => $id,
        'url'       => 'https://www.youtube.com/watch?v=' . $id,
        'title'     => $title !== 'NA' ? $title : '',
        'uploader'  => $uploader !== 'NA' ? $uploader : '',
        'duration'  => ytdl_format_duration($parts[3] ?? ''),
        // Vignette reconstruite directement depuis l'ID plutôt que demandée à
        // yt-dlp : en --flat-playlist, %(thumbnail)s n'est pas toujours résolu,
        // alors que cette URL YouTube standard fonctionne pour toute vidéo
        // publique, sans requête supplémentaire.
        'thumbnail' => 'https://i.ytimg.com/vi/' . $id . '/mqdefault.jpg',
    ];
}

echo json_encode(['results' => $results]);
