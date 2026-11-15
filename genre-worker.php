<?php
/**
 * Classement des titres par genre, en arrière-plan (voir genre-lib.php).
 * Parcourt les fichiers jamais classés, de tous les comptes, doucement
 * (services gratuits). Un seul processus à la fois ; ne touche jamais aux
 * genres choisis à la main. Usage : php genre-worker.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/genre-lib.php';

set_time_limit(0);
$lock = fopen(sys_get_temp_dir() . '/ytdl-genre-worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

$db = ytdl_db();
$db->exec('PRAGMA busy_timeout = 15000');
$select = $db->prepare(
    "SELECT id, title, uploader FROM download_history
     WHERE library_path IS NOT NULL AND library_path <> '' AND genre_checked_at IS NULL AND COALESCE(genre_source, '') <> 'manuel'
     ORDER BY id DESC LIMIT 1"
);
$update = $db->prepare(
    "UPDATE download_history SET genre = :g, genre_source = :s, genre_checked_at = datetime('now') WHERE id = :id AND COALESCE(genre_source, '') <> 'manuel'"
);

$n = 0;
while (true) {
    $select->execute();
    $row = $select->fetch(PDO::FETCH_ASSOC);
    $select->closeCursor();
    if (!$row) break;
    try {
        [$genre, $source] = ytdl_genre_classify((string)$row['title'], (string)$row['uploader']);
    } catch (Throwable $e) {
        error_log('ytdl genre-worker : ' . $e->getMessage());
        [$genre, $source] = [YTDL_GENRE_UNCLASSIFIED, 'aucun'];
    }
    for ($i = 0; ; $i++) {
        try { $update->execute([':g' => $genre, ':s' => $source, ':id' => $row['id']]); break; }
        catch (PDOException $e) { if ($i >= 6) throw $e; sleep(2); }
    }
    $n++;
    usleep(300000);
}
