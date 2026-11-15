<?php
/**
 * Importe dans MA bibliothèque des titres de quelqu'un dont j'ai l'accès
 * (library-access.php) : un partage library_shares comme pour l'« emprunt »
 * (library-borrow.php), donc AUCUNE copie de fichier — le titre apparaît chez
 * moi, le fichier reste celui du propriétaire (et rejoint Shared/, voir
 * ytdl_ensure_shared_after_grant). ids = liste de titres, ou all = tout.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/library-access-lib.php';
$user = ytdl_require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ytdl_json_error('Méthode non autorisée.', 405);
}
set_time_limit(300);

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$ownerId = (int)($payload['owner_id'] ?? 0);
$me = (int)$user['id'];

if (!ytdl_viewer_has_library_access($me, $ownerId)) {
    ytdl_json_error("Tu n'as pas accès à cette bibliothèque.", 403);
}

$db = ytdl_db();
if (!empty($payload['all'])) {
    $stmt = $db->prepare(
        "SELECT id FROM download_history WHERE user_id = :owner AND library_path IS NOT NULL AND library_path <> ''"
    );
    $stmt->execute([':owner' => $ownerId]);
    $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
} else {
    $ids = array_values(array_unique(array_map('intval', $payload['ids'] ?? [])));
}
if (!$ids) ytdl_json_error('Aucun titre à importer.', 400);

$check = $db->prepare(
    "SELECT 1 FROM download_history WHERE id = :id AND user_id = :owner AND library_path IS NOT NULL AND library_path <> ''"
);
$insert = $db->prepare(
    'INSERT OR IGNORE INTO library_shares (history_id, shared_with_user_id, shared_by_user_id) VALUES (:id, :me, :owner)'
);
$imported = 0;
foreach ($ids as $id) {
    // Titres du propriétaire uniquement : jamais un id deviné ailleurs.
    $check->execute([':id' => $id, ':owner' => $ownerId]);
    if ($check->fetchColumn() === false) continue;
    $insert->execute([':id' => $id, ':me' => $me, ':owner' => $ownerId]);
    if ($insert->rowCount() > 0) {
        $imported++;
        ytdl_ensure_shared_after_grant($id);
    }
}

echo json_encode(['ok' => true, 'imported' => $imported, 'skipped' => count($ids) - $imported]);
