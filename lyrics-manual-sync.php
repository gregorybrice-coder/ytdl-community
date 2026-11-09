<?php
/**
 * Enregistre un minutage de paroles construit à la main par un utilisateur
 * (voir ytdl_save_manual_synced_lyrics dans lib.php) — accessible à tout
 * compte connecté, pas réservé à l'administrateur : n'importe qui peut
 * contribuer une synchro, elle profite ensuite à tous les comptes qui
 * tombent sur ce même morceau (même cache que les paroles trouvées
 * automatiquement).
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
$duration = $payload['duration'] ?? null;
$lines = $payload['lines'] ?? null;

if ($artist === '' || $title === '' || !is_array($lines) || count($lines) < 1) {
    ytdl_json_error('Paramètres invalides.', 400);
}
if (mb_strlen($artist) > 200) $artist = mb_substr($artist, 0, 200);
if (mb_strlen($title) > 200) $title = mb_substr($title, 0, 200);

// Chaque ligne validée/normalisée individuellement — jamais fait confiance
// à la forme envoyée par le client. Une ligne invalide est ignorée plutôt
// que de faire échouer tout l'enregistrement.
$cleanLines = [];
foreach ($lines as $line) {
    if (!is_array($line)) continue;
    $time = $line['time'] ?? null;
    $text = $line['text'] ?? '';
    if (!is_numeric($time) || (float)$time < 0) continue;
    $text = (string)$text;
    if (mb_strlen($text) > 500) $text = mb_substr($text, 0, 500);
    $cleanLines[] = ['time' => round((float)$time, 2), 'text' => $text];
}
if (count($cleanLines) < 1) {
    ytdl_json_error('Aucune ligne valide.', 400);
}
// Toujours trié par temps croissant : le surlignage synchronisé (côté
// client) suppose cet ordre, une synchro construite en cliquant vite ne
// garantit rien sur l'ordre d'arrivée des événements réseau.
usort($cleanLines, fn($a, $b) => $a['time'] <=> $b['time']);

$refDuration = (is_numeric($duration) && (float)$duration > 0) ? (float)$duration : null;

ytdl_save_manual_synced_lyrics($artist, $title, $cleanLines, $refDuration);

echo json_encode(['ok' => true, 'lines' => $cleanLines]);
