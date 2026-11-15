<?php
/**
 * Déplace un titre dans un genre, à la main (il n'est alors plus reclassé
 * automatiquement). Réservé au propriétaire du fichier : le genre est celui
 * du fichier, que ses destinataires voient aussi. Corps : { id, genre }.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/genre-lib.php';
$user = ytdl_require_login();

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') ytdl_json_error('Méthode non autorisée.', 405);

$p = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int)($p['id'] ?? 0);
$genre = ytdl_genre_clean((string)($p['genre'] ?? ''));
if ($genre === null) ytdl_json_error('Nom de genre invalide (40 caractères au plus).', 400);

$st = ytdl_db()->prepare('SELECT user_id FROM download_history WHERE id = :id');
$st->execute([':id' => $id]);
$owner = $st->fetchColumn();
if ($owner === false) ytdl_json_error('Titre introuvable.', 404);
if ((int)$owner !== (int)$user['id'] && (int)$user['is_admin'] !== 1) {
    ytdl_json_error('Seul le propriétaire du fichier peut changer son genre.', 403);
}

ytdl_db()->prepare("UPDATE download_history SET genre = :g, genre_source = 'manuel', genre_checked_at = datetime('now') WHERE id = :id")
    ->execute([':g' => $genre, ':id' => $id]);
echo json_encode(['ok' => true, 'genre' => $genre]);
