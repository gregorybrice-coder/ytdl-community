<?php
/**
 * Article Wikipédia complet d'un artiste, tel qu'archivé en base (voir
 * ytdl_archive_artist) : déroulé par « Lire la suite… » quand la page
 * Wikipédia ne répond plus. Le texte brut garde ses titres de section au
 * format « == Titre == ».
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
ytdl_require_login();

header('Content-Type: application/json');

$artist = trim((string)($_GET['artist'] ?? ''));
$row = $artist !== '' ? ytdl_artist_row(mb_substr($artist, 0, 200)) : null;
if (!$row || (int)$row['found'] !== 1) {
    ytdl_json_error('Aucune fiche archivée pour cet artiste.', 404);
}

echo json_encode([
    'name' => $row['wiki_title'] ?: $row['name'],
    'full_text' => $row['full_text'] ?? $row['intro'],
    'archived_at' => $row['fetched_at'],
]);
