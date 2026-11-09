<?php
/**
 * Archive en base la fiche Wikipédia COMPLÈTE de chaque artiste présent dans
 * les bibliothèques de tous les comptes (voir ytdl_archive_artist), pour
 * qu'elle survive à la disparition de Wikipédia.
 *
 *   php archive-artists.php            artistes pas encore archivés
 *   php archive-artists.php --tous     tout réarchiver (rafraîchit les textes)
 *   php archive-artists.php --introuvables   retenter les artistes introuvables
 *   php archive-artists.php --bloquer "Nom"  écarter une fiche fausse (homonyme)
 *                                            pour de bon : plus jamais recherchée
 *
 * Relançable sans risque. Les artistes vus pour la première fois après coup
 * sont de toute façon archivés à leur première consultation (artist-info.php).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

$block = array_search('--bloquer', $argv, true);
if ($block !== false) {
    $name = trim((string)($argv[$block + 1] ?? ''));
    if ($name === '') exit("Usage : php archive-artists.php --bloquer \"Nom de l'artiste\"\n");
    ytdl_db()->prepare("INSERT INTO artists (name_key, name, found, locked) VALUES (?, ?, 0, 1)
        ON CONFLICT(name_key) DO UPDATE SET found = 0, locked = 1, wiki_title = NULL, wiki_url = NULL,
        intro = NULL, full_text = NULL, thumbnail_url = NULL, photo_path = NULL")
        ->execute([ytdl_artist_key($name), $name]);
    exit("« $name » : fiche écartée, plus jamais recherchée.\n");
}

$all = in_array('--tous', $argv, true);
$retryMissing = in_array('--introuvables', $argv, true);
$db = ytdl_db();

// Artistes de toutes les bibliothèques, avec un titre de morceau pour aider
// la recherche à lever les homonymies.
$artists = [];
// Hors musique (podcasts, vidéastes…) exclu : la « chaîne » n'y est pas un artiste.
foreach ($db->query("SELECT title, uploader FROM download_history WHERE library_path IS NOT NULL AND category <> 'other'") as $r) {
    $parts = ytdl_split_artist_title((string)$r['title'], $r['uploader']);
    $artist = trim($parts['artist']);
    if ($artist === '') continue;
    $artists[ytdl_artist_key($artist)] ??= ['name' => $artist, 'track' => $parts['title']];
}
ksort($artists);
echo count($artists) . " artistes distincts dans les bibliothèques.\n";

$done = $found = $missing = $skipped = 0;
foreach ($artists as $key => $a) {
    $row = ytdl_artist_row($a['name']);
    if ($row && (int)($row['locked'] ?? 0) === 1) { $skipped++; continue; }
    $retry = $retryMissing && $row && (int)$row['found'] === 0;
    if (!$all && !$retry && $row && ((int)$row['found'] === 0 || !empty($row['full_text']))) { $skipped++; continue; }

    // Page déjà identifiée par l'ancien cache de fiches : pas de recherche.
    $hint = null;
    $cached = json_decode((string)@file_get_contents(ytdl_artist_info_cache_path($a['name'])), true);
    if (is_array($cached) && !empty($cached['wiki_url']) && !empty($cached['wiki_title'])
        && preg_match('#^https://([a-z]+)\.wikipedia\.org/#', $cached['wiki_url'], $m)) {
        $hint = ['title' => $cached['wiki_title'], 'lang' => $m[1]];
    }

    $row = ytdl_archive_artist($a['name'], $a['track'], $hint);
    $done++;
    if ($row && (int)$row['found'] === 1) {
        $found++;
        printf("  ✓ %-40s %s (%d car.%s)\n", mb_substr($a['name'], 0, 40), $row['wiki_title'],
            mb_strlen((string)$row['full_text']), $row['photo_path'] ? ', photo' : '');
    } else {
        $missing++;
        printf("  · %-40s introuvable\n", mb_substr($a['name'], 0, 40));
    }
    usleep(300000); // courtoisie envers Wikipédia
}
echo "\nTerminé : $done traités ($found archivés, $missing introuvables), $skipped déjà archivés.\n";
