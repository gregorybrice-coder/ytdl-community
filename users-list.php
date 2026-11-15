<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
$user = ytdl_require_login();

header('Content-Type: application/json');

// Comptes actifs hors soi-même : c'est la liste à cocher pour le partage —
// pas de sens à se partager un fichier à soi. Le propriétaire d'origine d'un
// fichier y figure aussi : le lui partager le lui recommande (« partagé par
// … » sur son propre titre, voir library-share.php).
$stmt = ytdl_db()->prepare(
    "SELECT id, name, avatar FROM users WHERE status = 'active' AND id <> ? ORDER BY name"
);
$stmt->execute([$user['id']]);

echo json_encode(['users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
