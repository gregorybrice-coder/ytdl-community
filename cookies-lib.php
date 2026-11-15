<?php
/**
 * Cookies YouTube du serveur (voir ytdl_cookies_path dans lib.php) : état,
 * test, renouvellement depuis un navigateur du Mac (équivalent de
 * install-cookies.php) ou depuis un fichier cookies.txt envoyé. Utilisé par
 * admin-cookies.php, donc réservé à l'administrateur.
 *
 * Rien n'est installé sans avoir été testé : un fichier qui ne passe pas la
 * vérification de YouTube ne remplace jamais celui qui fonctionne.
 */
require_once __DIR__ . '/lib.php';

const YTDL_COOKIES_TEST_URL = 'https://www.youtube.com/watch?v=jNQXAC9IVRw';

/**
 * Exécute une commande avec délai maximal. Renvoie [sortie, erreurs, code, délai dépassé].
 * « exec » : le shell est remplacé par la commande, qu'on peut donc vraiment arrêter.
 */
function ytdl_run_timeout(string $cmd, int $seconds): array {
    $proc = proc_open('exec ' . $cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) return ['', 'lancement impossible', -1, false];
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = $err = '';
    $deadline = time() + $seconds;
    $timedOut = false;
    while (true) {
        $out .= (string)stream_get_contents($pipes[1]);
        $err .= (string)stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (!$status['running']) break;
        if (time() > $deadline) { $timedOut = true; proc_terminate($proc, 15); usleep(500000); proc_terminate($proc, 9); break; }
        usleep(200000);
    }
    $out .= (string)stream_get_contents($pipes[1]);
    $err .= (string)stream_get_contents($pipes[2]);
    foreach ($pipes as $p) fclose($p);
    $code = proc_close($proc);
    return [trim($out), trim($err), $code, $timedOut];
}

/** Domaines utiles à YouTube : tout le reste (autres sites) n'a pas à être gardé. */
function ytdl_cookies_domain_ok(string $domain): bool {
    $d = ltrim(strtolower($domain), '.');
    return (bool)preg_match('/(^|\.)(youtube\.com|youtube-nocookie\.com|googlevideo\.com|google\.[a-z]{2,3}(\.[a-z]{2})?)$/', $d);
}

/** Ne garde que l'en-tête et les cookies de YouTube / Google (format Netscape). */
function ytdl_cookies_filter(string $text): string {
    $keep = ["# Netscape HTTP Cookie File"];
    foreach (preg_split('/\r?\n/', $text) as $line) {
        $raw = $line;
        // yt-dlp écrit les cookies « HttpOnly » avec le préfixe #HttpOnly_
        if (str_starts_with($line, '#HttpOnly_')) $line = substr($line, 10);
        elseif ($line === '' || $line[0] === '#') continue;
        $f = explode("\t", $line);
        if (count($f) >= 7 && ytdl_cookies_domain_ok($f[0])) $keep[] = $raw;
    }
    return implode("\n", $keep) . "\n";
}

/** Présence d'un cookie d'authentification (un export « anonyme » ne débloque rien). */
function ytdl_cookies_has_auth(string $text): bool {
    return (bool)preg_match('/\t(SAPISID|__Secure-1PSID|__Secure-3PSID|LOGIN_INFO)\t/', $text);
}

function ytdl_cookies_count(string $text): int {
    $n = 0;
    foreach (preg_split('/\r?\n/', $text) as $l) {
        if ($l === '') continue;
        if ($l[0] === '#' && !str_starts_with($l, '#HttpOnly_')) continue;
        $n++;
    }
    return $n;
}

/** Fait vérifier ces cookies par YouTube (lecture du titre d'une vidéo, sans téléchargement). */
function ytdl_cookies_check(string $file): array {
    $ytDlp = ytdl_which('yt-dlp');
    if (!$ytDlp) return ['ok' => false, 'message' => 'yt-dlp est introuvable sur le serveur.'];
    $scratch = ytdl_scratch_dir();
    $copy = $scratch . '/cookies.txt'; // yt-dlp réécrit le fichier qu'on lui donne
    copy($file, $copy);
    chmod($copy, 0600);
    $cmd = sprintf('%s%s --cookies %s --no-playlist --skip-download --print %s -- %s',
        escapeshellarg($ytDlp), ytdl_js_runtime_flag(), escapeshellarg($copy), escapeshellarg('%(title)s'), escapeshellarg(YTDL_COOKIES_TEST_URL));
    [$out, $err, $code, $timedOut] = ytdl_run_timeout($cmd, 60);
    ytdl_rm_scratch($scratch);
    if ($timedOut) return ['ok' => false, 'message' => 'YouTube n’a pas répondu à temps.'];
    if ($out !== '' && $code === 0) return ['ok' => true, 'message' => 'YouTube accepte ces cookies.'];
    if (stripos($err, 'not a bot') !== false || stripos($err, 'Sign in to confirm') !== false) {
        return ['ok' => false, 'message' => 'YouTube demande encore la vérification anti-robot : ces cookies ne suffisent pas (expirés, ou adresse du serveur temporairement bloquée).'];
    }
    return ['ok' => false, 'message' => 'Test refusé : ' . mb_substr(preg_replace('/\s+/', ' ', $err), 0, 200)];
}

/** État du fichier actuel (sans en afficher le contenu). */
function ytdl_cookies_status(): array {
    $path = ytdl_cookies_path();
    if ($path === null) return ['exists' => false];
    $text = (string)file_get_contents($path);
    $mtime = filemtime($path);
    // Fuseau du système (PHP est souvent en UTC par défaut).
    $zone = 'UTC';
    if (preg_match('#zoneinfo/(.+)$#', (string)@readlink('/etc/localtime'), $m)) $zone = $m[1];
    $local = (new DateTime('@' . $mtime))->setTimezone(new DateTimeZone($zone));
    return [
        'exists' => true,
        'modified' => $local->format('Y-m-d H:i'),
        'age_days' => (int)floor((time() - $mtime) / 86400),
        'count' => ytdl_cookies_count($text),
        'has_auth' => ytdl_cookies_has_auth($text),
    ];
}

/**
 * Filtre, contrôle (cookie d'authentification, vérification par YouTube) puis
 * installe de nouveaux cookies. L'ancien fichier est conservé en .bak.
 */
function ytdl_cookies_install(string $text): array {
    $text = ytdl_cookies_filter($text);
    if (!ytdl_cookies_has_auth($text)) {
        return ['ok' => false, 'message' => 'Aucun cookie de connexion YouTube dans ce fichier : le compte n’était sans doute pas connecté à YouTube.'];
    }
    $dest = ytdl_cookies_dest();
    $dir = dirname($dest);
    if (!is_dir($dir) || !is_writable($dir)) return ['ok' => false, 'message' => 'Dossier de stockage inaccessible en écriture.'];
    $tmp = $dest . '.new.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, $text);
    chmod($tmp, 0600);
    $check = ytdl_cookies_check($tmp);
    if (!$check['ok']) { @unlink($tmp); return ['ok' => false, 'message' => $check['message'] . ' L’ancien fichier est conservé.']; }
    if (is_file($dest) && @copy($dest, $dest . '.bak')) @chmod($dest . '.bak', 0600);
    rename($tmp, $dest);
    return ['ok' => true, 'message' => 'Cookies renouvelés et vérifiés par YouTube (' . ytdl_cookies_count($text) . ' cookies). Les téléchargements les utilisent tout de suite.'];
}

/** Exporte les cookies d'un navigateur du Mac (équivalent de install-cookies.php), puis les installe. */
function ytdl_cookies_from_browser(string $browser): array {
    if (!in_array($browser, ['chrome', 'firefox'], true)) return ['ok' => false, 'message' => 'Navigateur non pris en charge.'];
    $ytDlp = ytdl_which('yt-dlp');
    if (!$ytDlp) return ['ok' => false, 'message' => 'yt-dlp est introuvable sur le serveur.'];
    $scratch = ytdl_scratch_dir();
    $export = $scratch . '/export.txt';
    $cmd = sprintf('%s --cookies-from-browser %s --cookies %s --skip-download --print %s -- %s',
        escapeshellarg($ytDlp), escapeshellarg($browser), escapeshellarg($export), escapeshellarg('%(title)s'), escapeshellarg(YTDL_COOKIES_TEST_URL));
    // Chrome : macOS peut demander, à l'écran du Mac, d'autoriser l'accès au trousseau.
    [, $err, , $timedOut] = ytdl_run_timeout($cmd, 120);
    $text = is_file($export) ? (string)file_get_contents($export) : '';
    ytdl_rm_scratch($scratch);
    if ($timedOut) return ['ok' => false, 'message' => 'Délai dépassé : l’autorisation du trousseau macOS n’a pas été donnée à temps.'];
    if ($text === '') {
        $hint = stripos($err, 'find-generic-password') !== false || stripos($err, 'keychain') !== false
            ? ' macOS n’a pas autorisé l’accès au trousseau depuis le serveur : lance plutôt « php install-cookies.php » dans Terminal.app, ou envoie un fichier cookies.txt ci-dessous.'
            : '';
        return ['ok' => false, 'message' => 'Aucun cookie n’a pu être lu depuis ' . ($browser === 'chrome' ? 'Chrome' : 'Firefox') . '.' . $hint];
    }
    return ytdl_cookies_install($text);
}
