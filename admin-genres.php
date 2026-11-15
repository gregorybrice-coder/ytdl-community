<?php
/**
 * Classement des titres par genre, pour l'administrateur (voir genre-lib.php).
 * GET  : { total, pending, genres: [{ genre, count }] }
 * POST : action = start (lance le classement) | recheck_unclassified (reclasse
 *        les « Inclassable ») | recheck_all (reclasse tout sauf les choix manuels).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/genre-lib.php';
$admin = ytdl_require_admin();

header('Content-Type: application/json');
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'ytdl-admin') ytdl_json_error('Requête refusée.', 403);
$db = ytdl_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'recheck_unclassified') {
        $db->exec("UPDATE download_history SET genre_checked_at = NULL WHERE genre = '" . YTDL_GENRE_UNCLASSIFIED . "' AND COALESCE(genre_source, '') <> 'manuel'");
    } elseif ($action === 'recheck_all') {
        $db->exec("UPDATE download_history SET genre_checked_at = NULL WHERE COALESCE(genre_source, '') <> 'manuel'");
    } elseif ($action !== 'start') {
        ytdl_json_error('Action inconnue.', 400);
    }
    @unlink(sys_get_temp_dir() . '/ytdl-genre-spawn.stamp'); // lancement immédiat
    ytdl_genre_spawn();
}

$rows = $db->query(
    "SELECT COALESCE(genre, '(pas encore classé)') AS genre, COUNT(*) AS n FROM download_history
     WHERE library_path IS NOT NULL AND library_path <> '' GROUP BY genre ORDER BY n DESC"
)->fetchAll(PDO::FETCH_ASSOC);
$total = array_sum(array_column($rows, 'n'));
echo json_encode([
    'total' => (int)$total,
    'pending' => ytdl_genre_pending_count(),
    'genres' => array_map(fn($r) => ['genre' => $r['genre'], 'count' => (int)$r['n']], $rows),
]);
