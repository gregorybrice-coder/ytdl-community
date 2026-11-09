<?php
/**
 * Dédoublonnage physique après archivage — invoqué en arrière-plan par
 * ytdl_archive_job() (voir lib.php), jamais depuis une requête HTTP et
 * jamais avant/pendant un téléchargement. Reçoit l'id download_history qui
 * vient d'être archivé, cherche d'AUTRES lignes (tous comptes confondus)
 * désignant le même contenu : même nom de fichier de base (la règle de
 * nommage déjà utilisée à l'écriture du fichier, voir
 * ytdl_duplicate_base_name) et durée proche à YTDL_DEDUPE_DURATION_TOLERANCE
 * secondes près (deux téléchargements séparés de la même vidéo n'aboutissent
 * pas toujours à un fichier strictement identique). Si trouvées, ne garde
 * que la meilleure qualité apparente parmi tous les fichiers du groupe dans
 * Shared/ (voir ytdl_consolidate_duplicate_group) et fait pointer toutes les
 * lignes concernées vers ce fichier unique.
 *
 * Idempotent : relancer sur une ligne déjà consolidée ne fait rien (le
 * SELECT exclut son propre chemin, et des lignes déjà sur le même chemin ne
 * forment jamais un groupe de plus d'un chemin distinct).
 *
 * Usage : php dedupe-check.php <history_id>
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

$historyId = (int)($argv[1] ?? 0);
if ($historyId <= 0) {
    fwrite(STDERR, "Usage: php dedupe-check.php <history_id>\n");
    exit(1);
}

$pdo = ytdl_db();

$stmt = $pdo->prepare(
    'SELECT id, audio_only, category, library_path, duration_seconds, file_size_bytes
     FROM download_history WHERE id = :id'
);
$stmt->execute([':id' => $historyId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// Rien à comparer de façon fiable sans durée connue, ou fichier déjà
// disparu (purge/suppression manuelle entre l'archivage et ce script).
if (!$row || !$row['library_path'] || !is_file($row['library_path']) || $row['duration_seconds'] === null) {
    exit(0);
}

$baseName = ytdl_duplicate_base_name($row['library_path']);

// category dans le WHERE : un contenu "Others" ne doit jamais être fusionné
// avec un contenu musical, même si nom de base/durée coïncidaient.
$candidatesStmt = $pdo->prepare(
    'SELECT id, audio_only, category, library_path, duration_seconds, file_size_bytes
     FROM download_history
     WHERE id != :id AND audio_only = :audio_only AND category = :category
       AND duration_seconds BETWEEN :duration_min AND :duration_max
       AND library_path IS NOT NULL AND library_path <> "" AND library_path != :own_path'
);
$candidatesStmt->execute([
    ':id' => $row['id'],
    ':audio_only' => $row['audio_only'],
    ':category' => $row['category'],
    ':duration_min' => $row['duration_seconds'] - YTDL_DEDUPE_DURATION_TOLERANCE,
    ':duration_max' => $row['duration_seconds'] + YTDL_DEDUPE_DURATION_TOLERANCE,
    ':own_path' => $row['library_path'],
]);

$group = [$row];
foreach ($candidatesStmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
    // Fichier purgé/effacé côté candidat : pas un vrai doublon exploitable.
    if (!is_file($candidate['library_path'])) continue;
    if (ytdl_duplicate_base_name($candidate['library_path']) !== $baseName) continue;
    $group[] = $candidate;
}

if (count($group) > 1) {
    ytdl_consolidate_duplicate_group($group);
}
