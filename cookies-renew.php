<?php
/**
 * Renouvellement des cookies YouTube déclenché par N'IMPORTE QUEL compte, depuis
 * le bouton affiché avec l'erreur « les cookies ont probablement expiré »
 * (voir cookies-lib.php). Volontairement plus restreint que admin-cookies.php :
 *  - jamais d'envoi de fichier (un compte ne peut pas imposer SES cookies) ;
 *  - les cookies lus sont ceux d'un navigateur du serveur, et ne sont installés
 *    que si YouTube les accepte ;
 *  - une tentative à la fois, et pas plus d'une toutes les 3 minutes.
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/cookies-lib.php';
$user = ytdl_require_login();

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') ytdl_json_error('Méthode non autorisée.', 405);
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'ytdl-app') ytdl_json_error('Requête refusée.', 403);

set_time_limit(220);
$stamp = dirname(ytdl_cookies_dest()) . '/.cookies-renew.lock';
$fh = fopen($stamp, 'c+');
if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
    echo json_encode(['ok' => false, 'message' => 'Un renouvellement est déjà en cours : patiente une minute puis réessaie.']);
    exit;
}
$last = (int)trim((string)stream_get_contents($fh));

// 1) Les cookies actuels fonctionnent peut-être déjà (panne passagère, ou
//    quelqu'un les a renouvelés entre-temps) : rien à changer.
$path = ytdl_cookies_path();
$current = $path === null ? ['ok' => false] : ytdl_cookies_check($path);
if ($current['ok']) {
    $result = ['ok' => true, 'message' => 'La connexion YouTube du serveur fonctionne : réessaie ton téléchargement.'];
} else {
    // Pas plus d'une tentative de renouvellement toutes les 3 minutes.
    $wait = 180 - (time() - $last);
    if ($wait > 0) {
        echo json_encode(['ok' => false, 'message' => 'Un renouvellement vient d’être tenté : réessaie dans ' . $wait . ' s.']);
        exit;
    }
    ftruncate($fh, 0); rewind($fh); fwrite($fh, (string)time()); fflush($fh);
    // 2) Renouvellement depuis un navigateur du serveur : Chrome (le cas échéant,
    //    macOS demande alors une autorisation à l'écran du Mac), puis Firefox.
    $result = ytdl_cookies_from_browser('chrome');
    if (!$result['ok']) {
        $ff = ytdl_cookies_from_browser('firefox');
        $result = $ff['ok'] ? $ff : ['ok' => false, 'message' => $result['message'] . ' L’administrateur doit renouveler les cookies (page Administration).'];
    }
    if ($result['ok']) $result['message'] = 'Connexion YouTube renouvelée : réessaie ton téléchargement.';
    // Prévient les administrateurs (push) du résultat.
    foreach (ytdl_db()->query("SELECT id FROM users WHERE is_admin = 1 AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
        try { ytdl_send_push((int)$adminId, 'Cookies YouTube', $user['name'] . ' a demandé leur renouvellement : ' . ($result['ok'] ? 'réussi.' : 'échec, à faire dans l’administration.'), ['url' => '/ytdl/admin.php']); }
        catch (Throwable $e) {}
    }
}
echo json_encode($result);
