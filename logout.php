<?php
require_once __DIR__ . '/auth.php';

// Mémorise aussi les navigateurs connectés avant l'ajout de cette fonctionnalité.
$user = ytdl_current_user();
if ($user) ytdl_remember_account($user);

// Déconnexion volontaire : le token (48h ou "indéfiniment") doit être détruit
// pour de bon, sinon auth.php reconnaîtrait automatiquement l'appareil via le
// cookie dès la page suivante — ce qui contredirait le clic sur "Déconnexion".
$cookieToken = $_COOKIE['ytdl_token'] ?? '';
if ($cookieToken !== '') {
    ytdl_db()->prepare('DELETE FROM login_tokens WHERE token = :token')->execute([':token' => $cookieToken]);
    $options = ytdl_cookie_options(time() - 3600);
    setcookie('ytdl_token', '', $options);
    // Efface également l'ancien chemin fixe si l'application a changé de chemin.
    if ($options['path'] !== '/ytdl/') {
        $options['path'] = '/ytdl/';
        setcookie('ytdl_token', '', $options);
    }
}

$_SESSION = [];
session_destroy();
header('Location: index.php');
