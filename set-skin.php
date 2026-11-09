<?php
/**
 * Enregistre l'apparence choisie pour le compte (users.skin), afin qu'elle
 * suive l'utilisateur sur tous ses appareils. Appelé par « Mon profil ›
 * Apparence », dans capture.php comme dans les nouvelles interfaces.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
$user = ytdl_require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ytdl_json_error('Méthode non autorisée.', 405);
}

const YTDL_SKINS = ['sombre', 'clair', 'ambre', 'kawaii', 'sillon', 'orbite', 'platine', 'heros', 'galaxie', 'retro', 'arcade', 'nature', 'muse', 'aurore', 'bauhaus', 'carnet', 'laiton'];

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$skin = (string)($payload['skin'] ?? '');
if (!in_array($skin, YTDL_SKINS, true)) {
    ytdl_json_error('Apparence inconnue.', 400);
}

ytdl_db()->prepare('UPDATE users SET skin = :skin WHERE id = :id')
    ->execute([':skin' => $skin, ':id' => $user['id']]);

echo json_encode(['ok' => true, 'skin' => $skin]);
