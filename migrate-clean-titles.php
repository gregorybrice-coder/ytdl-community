<?php
/**
 * Migration ponctuelle, à lancer une seule fois à la main (jamais au
 * démarrage de l'app comme les migrations SQL légères de db.php) :
 *
 *   php migrate-clean-titles.php            (dry-run : n'écrit rien)
 *   php migrate-clean-titles.php --apply    (exécute réellement)
 *
 * Repasse ytdl_clean_title() (voir lib.php — étendue pour retirer "Full HD",
 * "HD", "HD Audio", "HQ Audio" en plus des mentions déjà gérées) sur le
 * titre de TOUS les morceaux déjà en bibliothèque (tous comptes confondus),
 * pas seulement les futurs téléchargements.
 *
 * Pour chaque titre effectivement modifié qui n'a PAS encore de paroles
 * trouvées, une seconde vérification est relancée avec le titre nettoyé —
 * "Titre HD" échouait parfois à trouver ses paroles là où "Titre" seul
 * réussit (lrclib.net/lyrics.ovh cherchent une correspondance texte, pas
 * floue).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

$apply = in_array('--apply', $argv, true);
$pdo = ytdl_db();

$logPath = __DIR__ . '/migrate-clean-titles-' . date('Ymd-His') . '.log';
$logLines = [];
function logLine(string $line): void {
    global $logLines;
    $logLines[] = $line;
    echo $line . "\n";
}

logLine(($apply ? '=== EXÉCUTION RÉELLE ===' : '=== DRY-RUN (rien ne sera écrit — relancer avec --apply pour exécuter) ==='));

$stats = [
    'renamed' => 0,
    'unchanged' => 0,
    'lyrics_rechecked' => 0,
    'lyrics_found_now' => 0,
];

$rows = $pdo->query(
    "SELECT id, title, uploader, duration_seconds FROM download_history WHERE title IS NOT NULL AND title <> ''"
)->fetchAll(PDO::FETCH_ASSOC);

logLine(count($rows) . ' morceau(x) à examiner.');

foreach ($rows as $row) {
    $oldTitle = $row['title'];
    $newTitle = ytdl_clean_title($oldTitle);
    if ($newTitle === $oldTitle) {
        $stats['unchanged']++;
        continue;
    }

    $stats['renamed']++;
    logLine(sprintf('RENAME  #%d  "%s"  ->  "%s"', $row['id'], $oldTitle, $newTitle));

    if (!$apply) continue; // dry-run : aucun appel réseau, aucune écriture de cache

    $pdo->prepare('UPDATE download_history SET title = :title WHERE id = :id')
        ->execute([':title' => $newTitle, ':id' => $row['id']]);

    // Vérifie l'état actuel des paroles (sous l'ANCIEN titre) — seuls les
    // morceaux qui n'en ont pas encore reçoivent une seconde chance avec le
    // titre nettoyé, comme demandé (pas la peine de re-solliciter les API
    // externes pour ceux qui ont déjà trouvé leurs paroles).
    $duration = $row['duration_seconds'] !== null ? (float)$row['duration_seconds'] : null;
    $oldParts = ytdl_split_artist_title($oldTitle, $row['uploader']);
    if ($oldParts['artist'] === '' || $oldParts['title'] === '') continue;

    $oldResult = ytdl_fetch_lyrics_data($oldParts['artist'], $oldParts['title'], $duration);
    if ($oldResult['lyrics'] !== null) continue; // déjà trouvées sous l'ancien titre : rien à refaire

    $stats['lyrics_rechecked']++;
    $newParts = ytdl_split_artist_title($newTitle, $row['uploader']);
    if ($newParts['artist'] === '' || $newParts['title'] === '') continue;
    // Inutile de rejouer une recherche identique si le nettoyage n'a
    // touché ni l'artiste ni le titre effectivement transmis aux API de
    // paroles (ex: seule une virgule finale a été retirée après "HD").
    if ($newParts['artist'] === $oldParts['artist'] && $newParts['title'] === $oldParts['title']) continue;

    $newResult = ytdl_fetch_lyrics_data($newParts['artist'], $newParts['title'], $duration);
    if ($newResult['lyrics'] !== null) {
        $stats['lyrics_found_now']++;
        logLine(sprintf('  -> paroles trouvées avec le titre nettoyé ("%s" — "%s")', $newParts['artist'], $newParts['title']));
    }
}

logLine("\n=== Résumé ===");
logLine('Titres renommés : ' . $stats['renamed']);
logLine('Titres déjà propres : ' . $stats['unchanged']);
logLine('Vérifications de paroles relancées : ' . $stats['lyrics_rechecked']);
logLine('Paroles trouvées grâce au titre nettoyé : ' . $stats['lyrics_found_now']);
if (!$apply) {
    logLine("\nDry-run terminé — relancer avec --apply pour exécuter réellement.");
}

file_put_contents($logPath, implode("\n", $logLines) . "\n");
echo "\nJournal écrit dans : $logPath\n";
