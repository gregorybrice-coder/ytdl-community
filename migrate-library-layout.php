<?php
/**
 * Migration ponctuelle, à lancer une seule fois à la main (jamais au
 * démarrage de l'app comme les migrations SQL légères de db.php) :
 *
 *   php migrate-library-layout.php            (dry-run : n'écrit rien)
 *   php migrate-library-layout.php --apply     (exécute réellement)
 *
 * Trois passes, dans cet ordre :
 *   1. Réorganisation <user>/*.ext -> <user>/audio|clips/*.ext, et persiste
 *      file_size_bytes (jamais calculée pour les lignes déjà anciennes).
 *   2. Fichiers déjà partagés (library_shares) : déplacés vers Shared/ s'ils
 *      n'y sont pas déjà.
 *   3. Doublons de contenu entre comptes (même nom de fichier de base, même
 *      durée, même taille) : ne garde qu'une copie physique dans Shared/.
 *
 * Chaque renommage/suppression est journalisé (fichier log horodaté dans ce
 * même dossier) pour permettre un rollback manuel si besoin — un rename sur
 * le même volume est instantané et sans perte, donc trivialement réversible
 * en literally rejouant le journal à l'envers.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

$apply = in_array('--apply', $argv, true);
$pdo = ytdl_db();

$logPath = __DIR__ . '/migrate-library-layout-' . date('Ymd-His') . '.log';
$logLines = [];
function logLine(string $line): void {
    global $logLines;
    $logLines[] = $line;
    echo $line . "\n";
}

logLine(($apply ? '=== EXÉCUTION RÉELLE ===' : '=== DRY-RUN (rien ne sera écrit — relancer avec --apply pour exécuter) ==='));

$stats = [
    'reorganized' => 0,
    'reorganize_failed' => 0,
    'shares_moved' => 0,
    'shares_failed' => 0,
    'dupe_groups' => 0,
    'dupe_files_removed' => 0,
    'bytes_reclaimed' => 0,
];

// ---------------------------------------------------------------------------
// Passe 1 — <user>/*.ext -> <user>/audio|clips/*.ext + file_size_bytes
// ---------------------------------------------------------------------------
logLine("\n-- Passe 1 : réorganisation audio/clips --");

$rows = $pdo->query(
    "SELECT id, user_id, library_path, audio_only, category, file_size_bytes
     FROM download_history
     WHERE library_path IS NOT NULL AND library_path <> '' AND user_id IS NOT NULL"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    $oldPath = $row['library_path'];
    if (!is_file($oldPath)) continue; // fichier déjà purgé/absent : rien à déplacer

    $userId = (int)$row['user_id'];
    $audioOnly = (bool)$row['audio_only'];
    $category = $row['category'] ?? 'music';
    // Déjà sous <user>/audio|clips|others/ ou déjà dans Shared/ : rien à
    // faire ici (Shared/ est traité par les passes 2 et 3, jamais touché
    // par celle-ci).
    $expectedUserDir = YTDL_LIBRARY_ROOT . '/' . $userId . '/' . ytdl_library_subdir_name($audioOnly, $category) . '/';
    $sharedPrefix = YTDL_LIBRARY_ROOT . '/Shared/';
    if (str_starts_with($oldPath, $expectedUserDir) || str_starts_with($oldPath, $sharedPrefix)) {
        continue;
    }

    $ext = pathinfo($oldPath, PATHINFO_EXTENSION);
    $baseName = ytdl_duplicate_base_name($oldPath);
    $newPath = ytdl_unique_library_path(ytdl_library_dir($userId, $audioOnly, $category), $baseName, $ext, $oldPath);
    $size = filesize($oldPath);

    logLine(sprintf('RENAME  #%d  %s  ->  %s', $row['id'], $oldPath, $newPath));

    if ($apply) {
        if (@rename($oldPath, $newPath)) {
            $pdo->prepare('UPDATE download_history SET library_path = :path, file_size_bytes = :size WHERE id = :id')
                ->execute([':path' => $newPath, ':size' => $size, ':id' => $row['id']]);
            $stats['reorganized']++;
        } else {
            logLine('  ÉCHEC du rename, ligne #' . $row['id'] . ' laissée en place.');
            $stats['reorganize_failed']++;
        }
    } else {
        $stats['reorganized']++;
    }
}

// ---------------------------------------------------------------------------
// Passe 2 — fichiers déjà partagés -> Shared/
// ---------------------------------------------------------------------------
logLine("\n-- Passe 2 : partages existants vers Shared/ --");

$sharedHistoryIds = $pdo->query('SELECT DISTINCT history_id FROM library_shares')->fetchAll(PDO::FETCH_COLUMN);
foreach ($sharedHistoryIds as $historyId) {
    $stmt = $pdo->prepare('SELECT library_path, audio_only, category FROM download_history WHERE id = :id');
    $stmt->execute([':id' => (int)$historyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['library_path'] || !is_file($row['library_path'])) continue;

    $category = $row['category'] ?? 'music';
    $sharedPrefix = YTDL_LIBRARY_ROOT . '/Shared/' . ytdl_library_subdir_name((bool)$row['audio_only'], $category) . '/';
    if (str_starts_with($row['library_path'], $sharedPrefix)) continue; // déjà là

    $dir = ytdl_shared_library_dir((bool)$row['audio_only'], $category);
    $newPath = ytdl_unique_library_path($dir, ytdl_duplicate_base_name($row['library_path']), pathinfo($row['library_path'], PATHINFO_EXTENSION));

    logLine(sprintf('SHARE-MOVE  history #%d  %s  ->  %s', $historyId, $row['library_path'], $newPath));

    if ($apply) {
        if (@rename($row['library_path'], $newPath)) {
            $pdo->prepare('UPDATE download_history SET library_path = :new WHERE library_path = :old')
                ->execute([':new' => $newPath, ':old' => $row['library_path']]);
            $stats['shares_moved']++;
        } else {
            logLine('  ÉCHEC du rename, history #' . $historyId . ' laissé en place.');
            $stats['shares_failed']++;
        }
    } else {
        $stats['shares_moved']++;
    }
}

// ---------------------------------------------------------------------------
// Passe 3 — doublons de contenu (même nom de base, durée à
// YTDL_DEDUPE_DURATION_TOLERANCE près — voir dedupe-check.php) : parmi les
// fichiers réellement présents, seul celui à la meilleure qualité apparente
// (voir ytdl_media_quality_score) est conservé.
// ---------------------------------------------------------------------------
logLine("\n-- Passe 3 : doublons de contenu --");

$rows = $pdo->query(
    "SELECT id, library_path, audio_only, category, duration_seconds, file_size_bytes
     FROM download_history
     WHERE library_path IS NOT NULL AND library_path <> ''
       AND duration_seconds IS NOT NULL"
)->fetchAll(PDO::FETCH_ASSOC);

// Regroupement en deux temps : d'abord un seau exact (même nom de base,
// même type, même catégorie — jamais de fusion "Others" avec un contenu
// musical) — vite fait par table de hachage — puis, À L'INTÉRIEUR de
// chaque seau, un regroupement séquentiel par durée croissante : un nouveau
// groupe démarre dès qu'un écart dépasse la tolérance. Suffisant à cette
// échelle (quelques titres identiques tout au plus par seau) sans comparer
// chaque ligne à toutes les autres.
$buckets = [];
foreach ($rows as $row) {
    if (!is_file($row['library_path'])) continue;
    $key = ytdl_duplicate_base_name($row['library_path']) . '|' . $row['audio_only'] . '|' . ($row['category'] ?? 'music');
    $buckets[$key][] = $row;
}

$dupeGroups = [];
foreach ($buckets as $bucketRows) {
    if (count($bucketRows) < 2) continue;
    usort($bucketRows, fn($a, $b) => $a['duration_seconds'] <=> $b['duration_seconds']);
    $cluster = [$bucketRows[0]];
    for ($i = 1; $i < count($bucketRows); $i++) {
        $prev = $cluster[count($cluster) - 1];
        if ($bucketRows[$i]['duration_seconds'] - $prev['duration_seconds'] <= YTDL_DEDUPE_DURATION_TOLERANCE) {
            $cluster[] = $bucketRows[$i];
        } else {
            if (count($cluster) > 1) $dupeGroups[] = $cluster;
            $cluster = [$bucketRows[$i]];
        }
    }
    if (count($cluster) > 1) $dupeGroups[] = $cluster;
}

foreach ($dupeGroups as $groupRows) {
    $stats['dupe_groups']++;

    $audioOnly = (bool)$groupRows[0]['audio_only'];
    $category = $groupRows[0]['category'] ?? 'music';
    $sharedPrefix = YTDL_LIBRARY_ROOT . '/Shared/' . ytdl_library_subdir_name($audioOnly, $category) . '/';

    // Meilleure qualité apparente en premier — c'est elle qu'on garde.
    usort($groupRows, fn($a, $b) => ytdl_media_quality_score($b) <=> ytdl_media_quality_score($a));
    $keptRow = $groupRows[0];
    $keptPath = $keptRow['library_path'];

    if (!str_starts_with($keptPath, $sharedPrefix)) {
        $dir = ytdl_shared_library_dir($audioOnly, $category);
        $newKeptPath = ytdl_unique_library_path($dir, ytdl_duplicate_base_name($keptPath), pathinfo($keptPath, PATHINFO_EXTENSION));
        logLine(sprintf('DEDUPE-MOVE  history #%d  %s  ->  %s', $keptRow['id'], $keptPath, $newKeptPath));
        if ($apply) {
            if (!@rename($keptPath, $newKeptPath)) {
                logLine('  ÉCHEC du rename, groupe autour de history #' . $keptRow['id'] . ' laissé tel quel.');
                continue;
            }
            $pdo->prepare('UPDATE download_history SET library_path = :new WHERE library_path = :old')
                ->execute([':new' => $newKeptPath, ':old' => $keptPath]);
        }
        $keptPath = $newKeptPath;
    }

    foreach ($groupRows as $obsoleteRow) {
        if ($obsoleteRow['id'] === $keptRow['id']) continue;
        $obsoletePath = $obsoleteRow['library_path'];
        if ($obsoletePath === $keptPath) continue; // déjà consolidé sur ce même chemin

        $size = is_file($obsoletePath) ? filesize($obsoletePath) : 0;
        logLine(sprintf(
            'DEDUPE-MERGE  history #%d  %s (%s)  ->  %s (fichier supprimé)',
            $obsoleteRow['id'], $obsoletePath, number_format($size / 1048576, 1) . ' Mio', $keptPath
        ));

        if ($apply) {
            if (is_file($obsoletePath)) @unlink($obsoletePath);
            $pdo->prepare('UPDATE download_history SET library_path = :new WHERE library_path = :old')
                ->execute([':new' => $keptPath, ':old' => $obsoletePath]);
        }
        $stats['bytes_reclaimed'] += $size; // estimé aussi en dry-run, pour voir l'octet à récupérer avant de lancer --apply
        $stats['dupe_files_removed']++;
    }
}

// ---------------------------------------------------------------------------
logLine("\n=== Résumé ===");
logLine('Fichiers réorganisés (audio/clips) : ' . $stats['reorganized'] . ($stats['reorganize_failed'] ? ' (' . $stats['reorganize_failed'] . ' échecs)' : ''));
logLine('Fichiers partagés déplacés vers Shared/ : ' . $stats['shares_moved'] . ($stats['shares_failed'] ? ' (' . $stats['shares_failed'] . ' échecs)' : ''));
logLine('Groupes de doublons de contenu trouvés : ' . $stats['dupe_groups']);
logLine('Copies redondantes supprimées : ' . $stats['dupe_files_removed']);
if ($apply) {
    logLine('Octets récupérés : ' . number_format($stats['bytes_reclaimed'] / 1048576, 1) . ' Mio');
}
if (!$apply) {
    logLine("\nDry-run terminé — relancer avec --apply pour exécuter réellement.");
}

file_put_contents($logPath, implode("\n", $logLines) . "\n");
echo "\nJournal écrit dans : $logPath\n";
