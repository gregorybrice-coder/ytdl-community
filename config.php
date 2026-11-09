<?php
/** Configuration publique sans secret : les valeurs privées vivent hors du site. */
$dataDir = rtrim(getenv('YTDL_DATA_DIR') ?: dirname(__DIR__) . '/ytdl-data', '/');
$siteRoot = realpath(__DIR__);
if (!str_starts_with($dataDir, '/') || str_contains($dataDir, '/..')) {
    throw new RuntimeException('YTDL_DATA_DIR doit être un chemin absolu sans segment ..');
}
if (!is_dir($dataDir) && !mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
    throw new RuntimeException('Impossible de créer le dossier privé YTDL_DATA_DIR.');
}
$resolvedData = realpath($dataDir);
if ($resolvedData === $siteRoot || str_starts_with($resolvedData . '/', $siteRoot . '/')) {
    throw new RuntimeException('YTDL_DATA_DIR doit être hors du dossier du site.');
}
define('YTDL_DATA_DIR', $resolvedData);
$libraryRoot = rtrim(getenv('YTDL_LIBRARY_ROOT') ?: YTDL_DATA_DIR . '/library', '/');
if (!str_starts_with($libraryRoot, '/') || str_contains($libraryRoot, '/..')) {
    throw new RuntimeException('YTDL_LIBRARY_ROOT doit être un chemin absolu sans segment ..');
}
$resolvedLibrary = realpath($libraryRoot) ?: $libraryRoot;
if ($resolvedLibrary === $siteRoot || str_starts_with($resolvedLibrary . '/', $siteRoot . '/')) {
    throw new RuntimeException('La bibliothèque doit être hors du dossier du site.');
}
define('YTDL_LIBRARY_ROOT', $libraryRoot);
$pushFile = YTDL_DATA_DIR . '/vapid-config.php';
$push = is_file($pushFile) ? require $pushFile : [];
define('YTDL_VAPID_PUBLIC_KEY', getenv('YTDL_VAPID_PUBLIC_KEY') ?: ($push['publicKey'] ?? ''));
define('YTDL_VAPID_PRIVATE_KEY', getenv('YTDL_VAPID_PRIVATE_KEY') ?: ($push['privateKey'] ?? ''));
define('YTDL_VAPID_SUBJECT', getenv('YTDL_VAPID_SUBJECT') ?: ($push['subject'] ?? 'mailto:admin@example.org'));
unset($dataDir, $siteRoot, $resolvedData, $pushFile, $push, $libraryRoot, $resolvedLibrary);
