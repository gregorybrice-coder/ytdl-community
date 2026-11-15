<?php
/**
 * Genres existants correspondant à une saisie (bibliothèque, puis liste
 * officielle de MusicBrainz) : proposés pendant la saisie d'un genre pour
 * éviter les doublons. GET ?q=…  →  { genres: [{ name, source }] }
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/genre-lib.php';
ytdl_require_login();

header('Content-Type: application/json');
// Première utilisation : la liste officielle est chargée en arrière-plan.
if (ytdl_genre_vocab_count() === 0) ytdl_genre_spawn(true);
echo json_encode(['genres' => ytdl_genre_search((string)($_GET['q'] ?? ''), 8)]);
