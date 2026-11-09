<?php
/**
 * Enregistre le décalage manuel (secondes) du minutage synchronisé d'un
 * morceau — voir ytdl_set_lyrics_offset() dans lib.php. Volontairement
 * global (pas de propriétaire, pas de vérification d'accès à un fichier
 * précis) : le réglage porte sur artiste/titre, partagé par tous les
 * comptes qui tombent sur ce même morceau, pas sur un fichier en particulier.
 *
 * "variant" distingue le réglage du fichier audio ("audio", par défaut) de
 * celui du clip ("video") : un clip démarre souvent par une intro absente de
 * l'audio, les deux décalages n'ont aucune raison de coïncider.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
ytdl_require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ytdl_json_error('Méthode non autorisée.', 405);
}

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$artist = trim((string)($payload['artist'] ?? ''));
$title  = trim((string)($payload['title'] ?? ''));
$offset = $payload['offset'] ?? null;
$variant = (string)($payload['variant'] ?? 'audio');

if ($artist === '' || $title === '' || !is_numeric($offset)) {
    ytdl_json_error('Paramètres invalides.', 400);
}
if (!in_array($variant, ['audio', 'video'], true)) {
    ytdl_json_error('Variante inconnue.', 400);
}
if (mb_strlen($artist) > 200) $artist = mb_substr($artist, 0, 200);
if (mb_strlen($title) > 200) $title = mb_substr($title, 0, 200);

$applied = ytdl_set_lyrics_offset($artist, $title, (float)$offset, $variant);
echo json_encode(['ok' => true, 'offset' => $applied, 'variant' => $variant]);
