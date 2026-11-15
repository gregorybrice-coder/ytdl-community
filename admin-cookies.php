<?php
/**
 * Cookies YouTube du serveur, pour l'administrateur (voir cookies-lib.php).
 * GET  : état du fichier.
 * POST : action = test | renew (browser = chrome|firefox) | upload (champ « file »).
 * L'en-tête X-Requested-With: ytdl-admin est exigé (un autre site ne peut pas l'ajouter).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/cookies-lib.php';
$admin = ytdl_require_admin();

header('Content-Type: application/json');
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'ytdl-admin') {
    ytdl_json_error('Requête refusée.', 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(ytdl_cookies_status());
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') ytdl_json_error('Méthode non autorisée.', 405);

set_time_limit(200);
$action = (string)($_POST['action'] ?? '');

if ($action === 'test') {
    $path = ytdl_cookies_path();
    $result = $path === null ? ['ok' => false, 'message' => 'Aucun fichier de cookies sur le serveur.'] : ytdl_cookies_check($path);
} elseif ($action === 'renew') {
    $result = ytdl_cookies_from_browser((string)($_POST['browser'] ?? ''));
} elseif ($action === 'upload') {
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 4 * 1024 * 1024) {
        $result = ['ok' => false, 'message' => 'Fichier absent ou trop gros (4 Mo au plus).'];
    } else {
        $result = ytdl_cookies_install((string)file_get_contents($f['tmp_name']));
    }
} else {
    ytdl_json_error('Action inconnue.', 400);
}

echo json_encode($result + ['status' => ytdl_cookies_status()]);
