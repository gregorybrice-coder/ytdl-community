<?php
/**
 * Renouvelle les cookies YouTube du serveur, en ligne de commande, depuis un
 * navigateur du Mac ou un fichier cookies.txt. Même logique que le bouton de
 * l'administration (cookies-lib.php) : seuls les cookies de YouTube/Google sont
 * gardés, et rien n'est installé sans avoir été accepté par YouTube.
 *
 *   php install-cookies.php               # depuis Chrome
 *   php install-cookies.php firefox       # depuis Firefox
 *   php install-cookies.php /chemin/cookies.txt
 *
 * À lancer depuis Terminal.app : Chrome chiffre ses cookies avec une clé du
 * trousseau macOS, dont l'accès exige une autorisation affichée à l'écran, que
 * seul un programme lancé depuis une session ouverte peut obtenir (pas le
 * serveur web, pas une connexion à distance). Firefox n'a pas cette contrainte.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/cookies-lib.php';

$arg = $argv[1] ?? 'chrome';
if (is_file($arg)) {
    echo "Lecture du fichier $arg\n";
    $result = ytdl_cookies_install((string)file_get_contents($arg));
} else {
    echo "Lecture des cookies de $arg (si macOS demande l'accès au trousseau, autorise-le)\n";
    $result = ytdl_cookies_from_browser($arg);
}
echo ($result['ok'] ? 'OK : ' : 'ÉCHEC : ') . $result['message'] . "\n";
exit($result['ok'] ? 0 : 1);
