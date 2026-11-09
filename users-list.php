<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
$user = ytdl_require_login();

header('Content-Type: application/json');

// Comptes actifs hors soi-même : c'est la liste à cocher pour le partage —
// pas de sens à se partager un fichier à soi.
$excludeIds = [$user['id']];

// Si on partage/repartage un fichier précis, son propriétaire d'origine est
// aussi exclu : jamais de repartage vers quelqu'un qui a déjà le fichier
// chez lui.
$historyId = (int)($_GET['history_id'] ?? 0);
if ($historyId) {
    $ownerStmt = ytdl_db()->prepare('SELECT user_id FROM download_history WHERE id = :id');
    $ownerStmt->execute([':id' => $historyId]);
    $ownerId = $ownerStmt->fetchColumn();
    if ($ownerId !== false && $ownerId !== null) {
        $excludeIds[] = (int)$ownerId;
    }
}
$excludeIds = array_unique($excludeIds);

$placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
$stmt = ytdl_db()->prepare(
    "SELECT id, name, avatar FROM users WHERE status = 'active' AND id NOT IN ($placeholders) ORDER BY name"
);
$stmt->execute(array_values($excludeIds));

echo json_encode(['users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
