<?php
require_once __DIR__ . '/config.php';
/** Envoi du lien de connexion par email — SMTP authentifié (Gmail) si configuré, sinon mail() natif en repli. */

function ytdl_base_url(): string {
    // Les liens envoyés par email sont ouverts depuis l'extérieur (téléphone,
    // autre réseau) : on utilise toujours l'URL publique configurée plutôt que
    // le Host de la requête qui a déclenché l'envoi (qui peut être "localhost"
    // si le lien est demandé depuis le Mac lui-même).
    $configPath = YTDL_DATA_DIR . '/app-config.php';
    if (is_file($configPath)) {
        $config = require $configPath;
        if (!empty($config['public_base_url'])) {
            return rtrim($config['public_base_url'], '/');
        }
    }

    // Repli si la config publique n'existe pas encore.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/ytdl';
}

function ytdl_smtp_config(): ?array {
    $path = YTDL_DATA_DIR . '/smtp-config.php';
    if (!is_file($path)) return null;
    $config = require $path;
    if (empty($config['password']) || str_contains($config['password'], 'COLLE_ICI')) {
        return null; // pas encore rempli
    }
    return $config;
}

function ytdl_send_login_email(array $user, string $token, ?string $code = null): bool {
    $link = ytdl_base_url() . '/verify.php?token=' . urlencode($token);

    $subject = 'ytdl — lien de connexion';
    $body = "Bonjour {$user['name']},\n\n"
        . "Voici ton lien de connexion à ytdl :\n\n"
        . "{$link}\n\n"
        . ($code !== null
            ? "Dans l'appli installée sur l'écran d'accueil (iPhone notamment), tape plutôt ce code :\n\n"
              . "    " . substr($code, 0, 3) . ' ' . substr($code, 3) . "\n\n(valable 15 minutes)\n\n"
            : '')
        . "Si tu n'es pas à l'origine de cette demande, ignore simplement cet email.\n";

    $smtp = ytdl_smtp_config();
    if ($smtp) {
        return ytdl_send_via_smtp($smtp, $user['email'], $subject, $body);
    }

    // Repli : mail() natif (non fiable vers Gmail depuis une IP résidentielle,
    // voir ytdl-data/smtp-config.php pour activer l'envoi authentifié).
    $fromDomain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $fromDomain = preg_replace('/:\d+$/', '', $fromDomain);
    $headers = "From: ytdl <no-reply@{$fromDomain}>\r\n" . "Content-Type: text/plain; charset=UTF-8\r\n";
    $sent = mail($user['email'], $subject, $body, $headers);
    if ($sent) {
        $postfix = ytdl_which_bin('postfix');
        if ($postfix) shell_exec(escapeshellarg($postfix) . ' flush 2>&1');
    }
    return $sent;
}

/** Prévient les admins actifs qu'une nouvelle demande de compte attend leur validation. */
function ytdl_send_admin_new_request_email(array $admin, string $requesterName, string $requesterEmail): bool {
    $adminUrl = ytdl_base_url() . '/admin.php';

    $subject = 'ytdl — nouvelle demande de compte';
    $body = "Bonjour {$admin['name']},\n\n"
        . "{$requesterName} ({$requesterEmail}) vient de demander un compte sur ytdl.\n\n"
        . "Pour l'approuver ou la refuser :\n{$adminUrl}\n";

    $smtp = ytdl_smtp_config();
    if ($smtp) {
        return ytdl_send_via_smtp($smtp, $admin['email'], $subject, $body);
    }

    $fromDomain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $fromDomain = preg_replace('/:\d+$/', '', $fromDomain);
    $headers = "From: ytdl <no-reply@{$fromDomain}>\r\n" . "Content-Type: text/plain; charset=UTF-8\r\n";
    $sent = mail($admin['email'], $subject, $body, $headers);
    if ($sent) {
        $postfix = ytdl_which_bin('postfix');
        if ($postfix) shell_exec(escapeshellarg($postfix) . ' flush 2>&1');
    }
    return $sent;
}

/** Annonce à l'utilisateur que son compte vient d'être validé. */
function ytdl_send_account_approved_email(array $user): bool {
    $loginUrl = ytdl_base_url() . '/';

    $subject = '🎉 Ton compte ytdl est activé !';
    $body = "Bonne nouvelle {$user['name']} !\n\n"
        . "Ta demande de compte sur ytdl vient d'être validée. Tu peux dès maintenant "
        . "te connecter et télécharger tes vidéos (ou pistes audio) YouTube préférées "
        . "dans la meilleure qualité disponible.\n\n"
        . "Direction la page de connexion : {$loginUrl}\n"
        . "Choisis ton compte, tu recevras un lien de connexion par email (valable 24h).\n\n"
        . "À bientôt !\n";

    $smtp = ytdl_smtp_config();
    if ($smtp) {
        return ytdl_send_via_smtp($smtp, $user['email'], $subject, $body);
    }

    $fromDomain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $fromDomain = preg_replace('/:\d+$/', '', $fromDomain);
    $headers = "From: ytdl <no-reply@{$fromDomain}>\r\n" . "Content-Type: text/plain; charset=UTF-8\r\n";
    $sent = mail($user['email'], $subject, $body, $headers);
    if ($sent) {
        $postfix = ytdl_which_bin('postfix');
        if ($postfix) shell_exec(escapeshellarg($postfix) . ' flush 2>&1');
    }
    return $sent;
}

/**
 * Demande de confirmation d'un changement d'adresse — envoyée à l'ANCIENNE
 * boîte, seule preuve qu'on contrôle bien le compte au moment de la demande.
 * Elle sert aussi d'alerte : si le titulaire n'est pas à l'origine du
 * changement, c'est ce message qui le lui apprend, avant que l'adresse ne
 * bouge.
 */
function ytdl_send_email_change_email(array $user, string $newEmail, string $token): bool {
    $link = ytdl_base_url() . '/confirm-email-change.php?token=' . urlencode($token);

    $subject = 'ytdl — confirme ton changement d\'adresse email';
    $body = "Bonjour {$user['name']},\n\n"
        . "Une demande de changement d'adresse email a été faite sur ton compte ytdl :\n\n"
        . "  actuelle : {$user['email']}\n"
        . "  nouvelle : {$newEmail}\n\n"
        . "Pour confirmer ce changement, ouvre ce lien (valable 24h) :\n\n"
        . "{$link}\n\n"
        . "Tant que ce lien n'a pas été ouvert, rien ne change : tes liens de "
        . "connexion continuent d'arriver sur l'adresse actuelle.\n\n"
        . "Si tu n'es pas à l'origine de cette demande, ignore cet email — et "
        . "surtout n'ouvre pas le lien.\n";

    return ytdl_send_mail($user['email'], $subject, $body);
}

/** Informe la nouvelle adresse que le changement a bien été appliqué — c'est aussi la preuve pour l'utilisateur que sa nouvelle boîte reçoit bien les messages de ytdl. */
function ytdl_send_email_changed_notice(array $user, string $newEmail, string $oldEmail): bool {
    $loginUrl = ytdl_base_url() . '/';

    $subject = 'ytdl — ton adresse email a été changée';
    $body = "Bonjour {$user['name']},\n\n"
        . "C'est confirmé : l'adresse email de ton compte ytdl est passée de "
        . "{$oldEmail} à {$newEmail}.\n\n"
        . "Tes prochains liens de connexion arriveront donc dans cette boîte.\n"
        . "Page de connexion : {$loginUrl}\n";

    return ytdl_send_mail($newEmail, $subject, $body);
}

/**
 * Envoi générique : SMTP authentifié si configuré, mail() natif en repli.
 * Les fonctions ci-dessus répétaient toutes ce même bloc de repli.
 */
function ytdl_send_mail(string $to, string $subject, string $body): bool {
    $smtp = ytdl_smtp_config();
    if ($smtp) {
        return ytdl_send_via_smtp($smtp, $to, $subject, $body);
    }

    $fromDomain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $fromDomain = preg_replace('/:\d+$/', '', $fromDomain);
    $headers = "From: ytdl <no-reply@{$fromDomain}>\r\n" . "Content-Type: text/plain; charset=UTF-8\r\n";
    $sent = mail($to, $subject, $body, $headers);
    if ($sent) {
        $postfix = ytdl_which_bin('postfix');
        if ($postfix) shell_exec(escapeshellarg($postfix) . ' flush 2>&1');
    }
    return $sent;
}

function ytdl_which_bin(string $bin): ?string {
    $path = trim((string)shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
    return $path !== '' ? $path : null;
}

/**
 * Client SMTP minimal (EHLO/STARTTLS/AUTH LOGIN/DATA) sans dépendance externe.
 * Suffisant pour un envoi ponctuel via smtp.gmail.com:587.
 */
function ytdl_send_via_smtp(array $cfg, string $to, string $subject, string $body): bool {
    $errno = 0; $errstr = '';
    $sock = @stream_socket_client(
        "tcp://{$cfg['host']}:{$cfg['port']}",
        $errno, $errstr, 15
    );
    if (!$sock) {
        error_log("ytdl SMTP: connexion échouée — {$errstr}");
        return false;
    }

    $read = function () use ($sock): string {
        $data = '';
        while (($line = fgets($sock, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break; // dernière ligne d'une réponse multi-lignes
        }
        return $data;
    };
    $write = function (string $cmd) use ($sock) {
        fwrite($sock, $cmd . "\r\n");
    };
    $expect = function (string $expectedCode) use ($read, $sock): bool {
        $resp = $read();
        if (!str_starts_with($resp, $expectedCode)) {
            error_log("ytdl SMTP: réponse inattendue — {$resp}");
            fclose($sock);
            return false;
        }
        return true;
    };

    if (!$expect('220')) return false;

    $write('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if (!$expect('250')) return false;

    $write('STARTTLS');
    if (!$expect('220')) return false;

    if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        error_log('ytdl SMTP: échec de la négociation TLS');
        fclose($sock);
        return false;
    }

    $write('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if (!$expect('250')) return false;

    $write('AUTH LOGIN');
    if (!$expect('334')) return false;

    $write(base64_encode($cfg['username']));
    if (!$expect('334')) return false;

    $write(base64_encode($cfg['password']));
    if (!$expect('235')) return false;

    $write('MAIL FROM:<' . $cfg['from'] . '>');
    if (!$expect('250')) return false;

    $write('RCPT TO:<' . $to . '>');
    if (!$expect('250')) return false;

    $write('DATA');
    if (!$expect('354')) return false;

    $headers = "From: ytdl <{$cfg['from']}>\r\n"
        . "To: <{$to}>\r\n"
        . "Subject: {$subject}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "MIME-Version: 1.0\r\n";

    // Un point seul en début de ligne termine le message en SMTP : on double
    // tout point de début de ligne présent dans le corps ("dot-stuffing").
    $escapedBody = preg_replace('/^\./m', '..', $body);

    $write($headers . "\r\n" . $escapedBody . "\r\n.");
    if (!$expect('250')) return false;

    $write('QUIT');
    fclose($sock);

    return true;
}
