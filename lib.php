<?php
require_once __DIR__ . '/config.php';
/** Fonctions partagées par start.php / progress.php / file.php. */

require_once __DIR__ . '/vendor/autoload.php';

// Les clés de notifications sont propres à chaque installation (config.php).

/**
 * Supprime les comptes jamais activés (status='pending') datant de plus de
 * 48h — un compte demandé mais jamais validé par un admin ne doit pas
 * s'accumuler indéfiniment. Appelée à chaque chargement de la liste admin et
 * à chaque nouvelle demande de compte : pas besoin de cron dédié, l'appli n'a
 * que deux comptes actifs et ces deux points de passage suffisent à purger
 * en pratique dans un délai raisonnable après les 48h.
 */
function ytdl_purge_stale_pending_accounts(): void {
    ytdl_db()->exec(
        "DELETE FROM users WHERE status = 'pending' AND created_at < datetime('now', '-48 hours')"
    );
}

/**
 * Envoie une notification push à tous les appareils abonnés d'un compte.
 * Silencieux en cas d'échec par abonnement (endpoint expiré, appareil hors
 * ligne...) — un envoi raté ne doit jamais faire échouer l'action qui l'a
 * déclenché (ex: un partage). Les abonnements que le service de push signale
 * comme définitivement invalides (410 Gone / 404) sont nettoyés au passage.
 */
function ytdl_send_push(int $userId, string $title, string $body, array $extra = []): void {
    $stmt = ytdl_db()->prepare('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = :uid');
    $stmt->execute([':uid' => $userId]);
    $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$subs || YTDL_VAPID_PUBLIC_KEY === '' || YTDL_VAPID_PRIVATE_KEY === '') return;

    $auth = [
        'VAPID' => [
            'subject'    => YTDL_VAPID_SUBJECT,
            'publicKey'  => YTDL_VAPID_PUBLIC_KEY,
            'privateKey' => YTDL_VAPID_PRIVATE_KEY,
        ],
    ];
    $webPush = new \Minishlink\WebPush\WebPush($auth);
    $payload = json_encode(array_merge(['title' => $title, 'body' => $body], $extra));

    $deleteStale = ytdl_db()->prepare('DELETE FROM push_subscriptions WHERE id = :id');
    foreach ($subs as $sub) {
        $webPush->queueNotification(
            \Minishlink\WebPush\Subscription::create([
                'endpoint' => $sub['endpoint'],
                'publicKey' => $sub['p256dh'],
                'authToken' => $sub['auth'],
            ]),
            $payload
        );
    }

    foreach ($webPush->flush() as $report) {
        if ($report->isSubscriptionExpired()) {
            $endpoint = $report->getRequest()->getUri()->__toString();
            $match = array_filter($subs, fn($s) => $s['endpoint'] === $endpoint);
            foreach ($match as $s) {
                $deleteStale->execute([':id' => $s['id']]);
            }
        }
    }
}

function ytdl_which(string $bin): ?string {
    // Le PHP lancé par Apache n'hérite pas forcément du PATH du shell
    // interactif (Homebrew notamment) : on complète avant de chercher.
    $extraPaths = '/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/local/sbin';
    $cmd = 'PATH=' . escapeshellarg($extraPaths . ':' . getenv('PATH')) .
        ' command -v ' . escapeshellarg($bin) . ' 2>/dev/null';
    $path = trim((string)shell_exec($cmd));
    return $path !== '' ? $path : null;
}

/**
 * Durée réelle (secondes) d'un fichier média local, via ffprobe — mesurée
 * sur le fichier archivé lui-même plutôt que déduite des métadonnées
 * YouTube, pour détecter un décalage franc avec une source externe (voir
 * son usage dans ytdl_fetch_lrclib : une correspondance artiste/titre exacte
 * peut retomber sur un enregistrement différent — live, radio edit — au
 * minutage totalement décalé du fichier réellement téléchargé).
 */
function ytdl_probe_duration_seconds(string $path): ?float {
    $ffprobe = ytdl_which('ffprobe');
    if (!$ffprobe || !is_file($path)) return null;
    $cmd = sprintf(
        '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 -- %s',
        escapeshellarg($ffprobe),
        escapeshellarg($path)
    );
    $out = trim((string)shell_exec($cmd));
    return is_numeric($out) ? (float)$out : null;
}

/**
 * Niveau de référence commun à toute la bibliothèque, en LUFS (échelle de
 * sonie EBU R128 : ce que l'oreille perçoit, pas un pic électrique). -14 est
 * la valeur retenue par les plateformes de streaming ; elle laisse assez de
 * marge au-dessus du pic pour ne pas écraser la dynamique.
 */
const YTDL_LOUDNESS_TARGET_LUFS = -14.0;

// Plafond de pic après correction. Un gain positif ne doit jamais pousser le
// signal jusqu'à 0 dBFS : le rééchantillonnage en sortie de carte son peut
// dépasser le pic numérique mesuré, et l'écrêtage s'entend immédiatement.
const YTDL_LOUDNESS_PEAK_CEILING_DBFS = -1.0;

// Garde-fou sur l'amplification : au-delà, ce n'est plus un écart de
// mastering mais une source défectueuse (piste quasi muette, mesure faussée
// par un long silence), qu'il vaut mieux laisser basse que faire exploser
// avec son souffle.
const YTDL_LOUDNESS_MAX_GAIN_DB = 12.0;

/**
 * Loudness intégré (LUFS) et pic (dBFS) d'un fichier média, via le filtre
 * ebur128 de ffmpeg. Mesure le fichier entier — un morceau n'a pas un niveau
 * constant, et c'est justement la moyenne pondérée sur toute la durée qui
 * correspond à ce qu'on perçoit.
 *
 * Retourne null si ffmpeg manque, si le fichier n'a pas de piste sonore ou
 * si la sortie est illisible : l'appelant stocke alors NULL et la lecture se
 * fait sans correction, jamais avec un gain inventé.
 */
function ytdl_measure_loudness(string $path): ?array {
    $ffmpeg = ytdl_which('ffmpeg');
    if (!$ffmpeg || !is_file($path)) return null;

    $cmd = sprintf(
        // -vn : la piste image n'entre pas dans la mesure et son décodage
        // coûterait plusieurs fois le temps de l'analyse sonore sur un clip.
        // peak=true : sans cette option ebur128 ne calcule AUCUN pic, et le gain
        // se retrouve plafonné à zéro faute de marge connue (voir
        // ytdl_normalization_gain_db). La valeur renvoyée est un pic INTER-
        // ÉCHANTILLON (true peak), plus sévère que le simple maximum
        // d'échantillon : c'est lui qui écrête en sortie de convertisseur.
        '%s -nostdin -hide_banner -i %s -vn -af ebur128=framelog=quiet:peak=true -f null - 2>&1',
        escapeshellarg($ffmpeg),
        escapeshellarg($path)
    );
    $out = (string)shell_exec($cmd);

    // Le récapitulatif final ("Summary:") répète les libellés utilisés dans
    // le journal courant : on ancre sur les lignes du bloc intégré plutôt que
    // sur la première occurrence venue.
    if (!preg_match('/Integrated loudness:.*?I:\s*(-?[\d.]+)\s*LUFS/s', $out, $m)) return null;
    $lufs = (float)$m[1];
    // -70 LUFS est le plancher que ebur128 renvoie pour un silence : aucune
    // correction ne peut être déduite d'une piste muette.
    if ($lufs <= -70.0) return null;

    $peak = preg_match('/Peak:\s*(-?[\d.]+)\s*dBFS/s', $out, $mp) ? (float)$mp[1] : null;
    return ['lufs' => $lufs, 'peak' => $peak];
}

/**
 * Gain de correction (dB) à appliquer à la lecture pour amener un fichier au
 * niveau de référence commun. Même principe que ReplayGain : on corrige au
 * moment de jouer, sans jamais toucher au fichier lui-même — la mesure peut
 * être refaite, la cible changée, rien n'est perdu.
 *
 * Le gain demandé par la sonie est plafonné par la marge réellement
 * disponible au-dessus du pic : remonter un clip faible de 10 dB n'a aucun
 * intérêt si son pic est déjà à -2 dBFS, ça ne produirait que de l'écrêtage.
 * Sans pic mesuré, on reste prudent en n'amplifiant pas du tout.
 */
function ytdl_normalization_gain_db(?float $lufs, ?float $peak): float {
    if ($lufs === null) return 0.0;

    $gain = YTDL_LOUDNESS_TARGET_LUFS - $lufs;
    if ($gain > 0) {
        $headroom = $peak !== null ? YTDL_LOUDNESS_PEAK_CEILING_DBFS - $peak : 0.0;
        $gain = min($gain, max(0.0, $headroom), YTDL_LOUDNESS_MAX_GAIN_DB);
    }
    // Pas de borne basse symétrique : atténuer ne présente aucun risque, et
    // une source très forte doit pouvoir redescendre autant qu'il le faut.
    return round($gain, 2);
}

/** Formate une durée en secondes brutes (mode --flat-playlist de yt-dlp) en
 *  "M:SS" ou "H:MM:SS" — %(duration_string)s n'existe qu'après résolution
 *  complète d'une vidéo, indisponible pour une liste de résultats de recherche. */
function ytdl_format_duration(string $rawSeconds): string {
    if ($rawSeconds === '' || $rawSeconds === 'NA' || !is_numeric($rawSeconds)) return '';
    $total = (int)round((float)$rawSeconds);
    if ($total <= 0) return '';
    $h = intdiv($total, 3600);
    $m = intdiv($total % 3600, 60);
    $s = $total % 60;
    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
}

/**
 * Dossier de cache des paroles récupérées via l'API lyrics.ovh — hors du
 * dossier servi par Apache, comme ytdl-data/ pour la maintenance (voir
 * ytdl_maintenance_file()) : une même chanson revient souvent (plusieurs
 * comptes, ou simplement le même morceau déjà vérifié), autant éviter de
 * re-solliciter l'API externe à chaque affichage de la bibliothèque.
 */
function ytdl_lyrics_cache_dir(): string {
    $dir = YTDL_DATA_DIR . '/lyrics-cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

function ytdl_lyrics_cache_path(string $artist, string $title): string {
    $key = mb_strtolower(trim($artist)) . '|' . mb_strtolower(trim($title));
    return ytdl_lyrics_cache_dir() . '/' . hash('sha256', $key) . '.json';
}

/**
 * Cache des fiches artiste/groupe (bloc à droite des paroles, desktop
 * uniquement, voir capture.php) — même principe que ytdl_lyrics_cache_dir :
 * un même artiste revient très souvent d'un morceau à l'autre, autant éviter
 * de re-solliciter Wikipédia à chaque affichage.
 */
function ytdl_artist_info_cache_dir(): string {
    $dir = YTDL_DATA_DIR . '/artist-info-cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

function ytdl_artist_info_cache_path(string $artist): string {
    $key = mb_strtolower(trim($artist));
    return ytdl_artist_info_cache_dir() . '/' . hash('sha256', $key) . '.json';
}

/**
 * Longueur maximale de l'extrait renvoyé au client — l'API Wikipédia rend
 * TOUTE l'intro de l'article (voir ytdl_wikipedia_intro), qui peut atteindre
 * plusieurs milliers de caractères pour un artiste très documenté (ex. The
 * Beatles) : bien plus qu'une simple phrase, comme demandé, mais toujours
 * borné pour ne pas déséquilibrer le panneau desktop.
 */
const YTDL_ARTIST_EXTRACT_MAX_CHARS = 1800;

/**
 * Fiche artiste/groupe via l'API Wikipédia (fr, aucune clé requise) : intro
 * complète de l'article (plusieurs paragraphes, pas la seule première
 * phrase) + photo si disponibles. Une recherche par titre exact échoue
 * souvent (page de redirection, homonymie) — on retombe alors sur l'API de
 * recherche pour trouver la page la plus probable, et on relit son intro.
 *
 * Retourne ['extract'=>string|null, 'thumbnail'=>string|null,
 * 'wiki_url'=>string|null, 'wiki_title'=>string|null] — jamais d'exception,
 * l'appelant se contente alors de garder le bloc masqué.
 */
function ytdl_fetch_artist_info(string $artist, string $trackTitle = ''): array {
    $artist = trim($artist);
    $empty = ['extract' => null, 'thumbnail' => null, 'wiki_url' => null, 'wiki_title' => null, 'has_more' => false];
    if ($artist === '') return $empty;

    // Fiche archivée en base (voir ytdl_archive_artist) : c'est elle qui fait
    // foi, Wikipédia n'est plus interrogé que pour un artiste jamais vu, ou
    // introuvable depuis plus de 30 jours (une page a pu être créée depuis).
    $row = ytdl_artist_row($artist);
    $retry = $row && (int)$row['found'] === 0 && (int)($row['locked'] ?? 0) === 0
        && strtotime($row['fetched_at'] . ' UTC') < time() - 30 * 86400;
    if (!$row || $retry) $row = ytdl_archive_artist($artist, $trackTitle);
    if (!$row || (int)$row['found'] !== 1) return $empty;

    ytdl_artist_check_link($row);
    return ytdl_artist_public($row);
}

/**
 * Appel à l'API Wikipédia, en s'identifiant comme le demande la politique
 * de Wikimedia (un programme qui se fait passer pour un navigateur est
 * fortement limité : réponses 429 en rafale). En cas de 429, on attend le
 * délai demandé (Retry-After, 30 s au plus) avant de réessayer, deux fois.
 * Toute réponse ni 200 ni 404 lève le drapeau ytdl_wikipedia_failed() : un
 * échec réseau ne doit jamais être pris pour « artiste introuvable ».
 */
function ytdl_wikipedia_get(string $url, bool $json = true) {
    for ($try = 0; $try < 3; $try++) {
        $ch = curl_init($url);
        $retryAfter = 5;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => YTDL_WIKIPEDIA_USER_AGENT,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$retryAfter) {
                if (preg_match('/^retry-after:\s*(\d+)/i', $header, $m)) $retryAfter = (int)$m[1];
                return strlen($header);
            },
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code === 200 && $body !== false) {
            if (!$json) return $body;
            $data = json_decode($body, true);
            return is_array($data) ? $data : null;
        }
        if ($code === 404) return null;
        if ($code !== 429) break;
        sleep(min(30, max(1, $retryAfter)));
    }
    $GLOBALS['ytdl_wikipedia_failed'] = true;
    return null;
}
function ytdl_wikipedia_failed(): bool { return !empty($GLOBALS['ytdl_wikipedia_failed']); }
const YTDL_WIKIPEDIA_USER_AGENT = 'ytdl/1.0 (https://example.org/ytdl/; fiches artistes d\'une bibliothèque musicale personnelle) PHP-curl';

/**
 * Noms à essayer sur Wikipédia pour un artiste tel qu'il apparaît dans la
 * bibliothèque : le nom exact d'abord (« Simon & Garfunkel » doit rester
 * entier), puis sans les ajouts des chaînes YouTube (« - Topic », « VEVO »,
 * « Official »…), puis le premier artiste d'un featuring ou d'un duo
 * (« 2Pac ft. Dr. Dre » → « 2Pac », « Busta Rhymes, Mariah Carey » →
 * « Busta Rhymes »). Sans doublons, dans cet ordre.
 */
function ytdl_artist_search_names(string $artist): array {
    $names = [trim($artist)];
    $clean = trim(preg_replace('/\s*(?:-\s*Topic|VEVO|Official(?:\s+(?:Channel|Music))?|Music)\s*$/iu', '', $artist));
    $clean = ltrim($clean, '@');
    // Featuring entre crochets ou parenthèses : « Diddy [feat. Keyshia Cole] ».
    $clean = trim(preg_replace('/\s*[\[(]\s*(?:feat\.?|ft\.?|featuring|with|avec)\b[^\])]*[\])]/iu', '', $clean));
    $names[] = $clean;
    $first = preg_split('/\s+(?:feat\.?|ft\.?|featuring|avec|with|x|vs\.?)\s+|\s*[,&+\/]\s*|\s+(?:et|and)\s+/iu', $clean)[0] ?? '';
    $names[] = trim($first);
    return array_values(array_unique(array_filter($names, fn($n) => mb_strlen($n) >= 2)));
}

/** Clé d'un artiste dans la table artists : nom en minuscules, espaces normalisés. */
function ytdl_artist_key(string $artist): string {
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $artist)));
}

function ytdl_artist_row(string $artist): ?array {
    $stmt = ytdl_db()->prepare('SELECT * FROM artists WHERE name_key = ?');
    $stmt->execute([ytdl_artist_key($artist)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Photos d'artistes archivées (servies par artist-photo.php). */
function ytdl_artist_photo_dir(): string {
    $dir = YTDL_DATA_DIR . '/artist-photos';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

/**
 * Article Wikipédia COMPLET en texte brut, sections comprises (titres au
 * format « == Titre == »), pour qu'il survive à la disparition de la page.
 */
function ytdl_wikipedia_full_text(string $title, string $lang): ?string {
    $url = 'https://' . $lang . '.wikipedia.org/w/api.php?action=query&prop=extracts'
        . '&explaintext=1&exsectionformat=wiki&redirects=1'
        . '&titles=' . rawurlencode($title) . '&format=json&formatversion=2';
    $data = ytdl_wikipedia_get($url);
    $text = $data['query']['pages'][0]['extract'] ?? null;
    return is_string($text) && trim($text) !== '' ? trim($text) : null;
}

/**
 * Cherche l'artiste sur Wikipédia et archive sa fiche complète en base :
 * intro, article entier, lien, et photo copiée sur le disque. $hint
 * (['title' => …, 'lang' => …]) évite la recherche quand la page est déjà
 * connue (ancien cache). Un artiste introuvable est aussi enregistré
 * (found = 0) pour ne pas réinterroger Wikipédia à chaque affichage.
 * Renvoie la ligne de la table artists.
 */
function ytdl_archive_artist(string $artist, string $trackTitle = '', ?array $hint = null): ?array {
    $artist = trim($artist);
    if ($artist === '') return null;
    $GLOBALS['ytdl_wikipedia_failed'] = false;
    $page = null;
    $lang = 'fr';
    if ($hint && !empty($hint['title'])) {
        $lang = $hint['lang'] ?? 'fr';
        $page = ytdl_wikipedia_intro($hint['title'], $lang);
    }
    // Le nom tel quel d'abord, puis ses variantes (chaîne « - Topic »,
    // premier artiste d'un duo ou d'un featuring…), chacune en français
    // puis en anglais. La fiche reste rangée sous le nom affiché.
    // Pour une variante, l'intro elle-même doit parler de musique : un nom
    // tronqué (« Roses » pour « Roses & Revolutions ») tombe sinon sur une
    // page sans rapport dont seules les catégories évoquent la musique.
    foreach ($page === null ? ytdl_artist_search_names($artist) : [] as $k => $name) {
        foreach (['fr' => 'musique', 'en' => 'singer'] as $tryLang => $bias) {
            $found = ytdl_fetch_artist_wikipedia_page($name, $trackTitle, $tryLang, $bias);
            if ($found !== null && ($k === 0 || ytdl_wikipedia_page_is_musical($found, true))) { $page = $found; $lang = $tryLang; break 2; }
        }
    }

    // Wikipédia injoignable ou saturé : on n'enregistre rien (une fiche déjà
    // archivée reste telle quelle), on réessaiera plus tard.
    if ($page === null && ytdl_wikipedia_failed()) return ytdl_artist_row($artist);

    $key = ytdl_artist_key($artist);
    $fields = ['name_key' => $key, 'name' => $artist, 'found' => 0, 'lang' => null, 'wiki_title' => null,
        'wiki_url' => null, 'thumbnail_url' => null, 'photo_path' => null, 'intro' => null, 'full_text' => null];
    if ($page !== null) {
        $intro = trim((string)($page['extract'] ?? ''));
        $full = ytdl_wikipedia_full_text((string)$page['title'], $lang);
        $thumb = $page['original']['source'] ?? null;
        $fields = array_merge($fields, [
            'found' => 1, 'lang' => $lang, 'wiki_title' => $page['title'] ?? null, 'wiki_url' => $page['fullurl'] ?? null,
            'thumbnail_url' => $thumb, 'intro' => $intro !== '' ? $intro : null, 'full_text' => $full ?? ($intro !== '' ? $intro : null),
        ]);
        if ($thumb) {
            $img = ytdl_wikipedia_get($thumb, false);
            $ext = strtolower(pathinfo(parse_url($thumb, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) $ext = 'jpg';
            if ($img !== null && strlen($img) > 0) {
                $path = ytdl_artist_photo_dir() . '/' . hash('sha256', $key) . '.' . $ext;
                if (@file_put_contents($path, $img) !== false) {
                    // L'original fait souvent plus d'un mégaoctet : 600 px suffisent.
                    if (is_executable('/usr/bin/sips')) {
                        @exec('/usr/bin/sips -Z 600 ' . escapeshellarg($path) . ' >/dev/null 2>&1');
                    }
                    $fields['photo_path'] = $path;
                }
            }
        }
    }

    $cols = array_keys($fields);
    $sql = 'INSERT INTO artists (' . implode(', ', $cols) . ", fetched_at, link_checked_at, link_ok)
            VALUES (:" . implode(', :', $cols) . ", datetime('now'), datetime('now'), 1)
            ON CONFLICT(name_key) DO UPDATE SET "
        . implode(', ', array_map(fn($c) => "$c = excluded.$c", array_diff($cols, ['name_key'])))
        . ", fetched_at = excluded.fetched_at, link_checked_at = excluded.link_checked_at, link_ok = 1";
    ytdl_db()->prepare($sql)->execute(array_combine(array_map(fn($c) => ':' . $c, $cols), array_values($fields)));
    return ytdl_artist_row($artist);
}

/**
 * Le lien Wikipédia répond-il encore ? Vérifié au plus une fois par semaine
 * (chaque jour s'il était mort). Une page supprimée, ou Wikipédia
 * injoignable, fait basculer l'affichage sur la fiche archivée.
 */
function ytdl_artist_check_link(array &$row): void {
    if (empty($row['wiki_url'])) return;
    $wait = (int)$row['link_ok'] === 1 ? 7 * 86400 : 86400;
    if (strtotime((string)$row['link_checked_at'] . ' UTC') > time() - $wait) return;
    $ch = curl_init($row['wiki_url']);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 6, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => YTDL_WIKIPEDIA_USER_AGENT,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code === 429) return; // simple saturation : on garde l'état connu
    $row['link_ok'] = ($code >= 200 && $code < 400) ? 1 : 0;
    ytdl_db()->prepare("UPDATE artists SET link_ok = ?, link_checked_at = datetime('now') WHERE id = ?")
        ->execute([$row['link_ok'], $row['id']]);
}

/**
 * Ce que le client reçoit : l'intro (bornée, voir
 * YTDL_ARTIST_EXTRACT_MAX_CHARS), la photo archivée, le lien Wikipédia
 * seulement s'il répond encore, et has_more quand la base garde plus que
 * l'extrait affiché (à dérouler via artist-bio.php).
 */
function ytdl_artist_public(array $row): array {
    $intro = (string)($row['intro'] ?? '');
    $extract = $intro;
    if (mb_strlen($extract) > YTDL_ARTIST_EXTRACT_MAX_CHARS) {
        // Coupe à la dernière fin de phrase avant la limite plutôt qu'en
        // plein mot — un extrait tronqué proprement, pas juste raccourci.
        $cut = mb_substr($extract, 0, YTDL_ARTIST_EXTRACT_MAX_CHARS);
        $lastDot = mb_strrpos($cut, '. ');
        $extract = ($lastDot !== false ? mb_substr($cut, 0, $lastDot + 1) : rtrim($cut)) . '…';
    }
    $photo = !empty($row['photo_path']) && is_file($row['photo_path'])
        ? 'artist-photo.php?id=' . (int)$row['id'] . '&v=' . substr(hash('sha256', (string)$row['fetched_at']), 0, 8)
        : ($row['thumbnail_url'] ?? null);
    return [
        'extract' => $extract !== '' ? $extract : null,
        'thumbnail' => $photo,
        'wiki_url' => (int)$row['link_ok'] === 1 ? ($row['wiki_url'] ?? null) : null,
        'wiki_title' => $row['wiki_title'] ?? null,
        'has_more' => mb_strlen((string)($row['full_text'] ?? '')) > mb_strlen($extract) + 40,
    ];
}

/**
 * Cherche la page Wikipédia d'un artiste sur UNE édition linguistique
 * donnée : d'abord un accès direct par le nom exact (écarté s'il ne parle
 * pas de musique — voir ytdl_wikipedia_page_is_musical), sinon une
 * recherche biaisée vers le sujet musical ($musicBiasKeyword, propre à
 * chaque langue — "musique" en français, "singer musician band" en
 * anglais) parmi laquelle seul un résultat dont le TITRE ressemble
 * vraiment à l'artiste cherché est retenu (voir
 * ytdl_wikipedia_title_matches_artist). Retourne null si rien
 * d'exploitable n'existe dans cette langue précise — à l'appelant de
 * retomber sur une autre langue.
 */
function ytdl_fetch_artist_wikipedia_page(string $artist, string $trackTitle, string $lang, string $musicBiasKeyword): ?array {
    $page = ytdl_wikipedia_intro($artist, $lang);
    if ($page !== null && !ytdl_wikipedia_page_is_musical($page)) {
        $page = null;
    }
    if ($page !== null) return $page;

    // Homonymes : Wikipédia range souvent l'artiste sous « Nom (groupe) »,
    // « Nom (chanteuse) »… (« Ghost » est une page d'homonymie, le groupe
    // suédois est « Ghost (groupe) »). Essayé avant la recherche plein texte.
    $suffixes = $lang === 'fr' ? ['groupe', 'chanteur', 'chanteuse', 'rappeur', 'musicien'] : ['band', 'singer', 'musician', 'rapper'];
    foreach ($suffixes as $suffix) {
        $candidate = ytdl_wikipedia_intro($artist . ' (' . $suffix . ')', $lang);
        if ($candidate !== null && ytdl_wikipedia_page_is_musical($candidate)) return $candidate;
    }

    $query = trim($artist . ' ' . $trackTitle . ' ' . $musicBiasKeyword);
    $searchUrl = 'https://' . $lang . '.wikipedia.org/w/rest.php/v1/search/page?q=' . rawurlencode($query) . '&limit=5';
    $search = ytdl_wikipedia_get($searchUrl);
    foreach (($search['pages'] ?? []) as $candidate) {
        $candidateTitle = $candidate['title'] ?? null;
        if ($candidateTitle === null) continue;
        if (!ytdl_wikipedia_title_matches_artist($candidateTitle, $artist)) continue;
        $candidatePage = ytdl_wikipedia_intro($candidateTitle, $lang);
        if ($candidatePage !== null && ytdl_wikipedia_page_is_musical($candidatePage)) {
            return $candidatePage;
        }
    }
    return null;
}

/**
 * Le titre d'une page Wikipédia trouvée par la recherche ressemble-t-il
 * VRAIMENT au nom d'artiste cherché ? Le moteur de recherche peut renvoyer
 * un résultat sans aucun rapport quand il ne trouve rien de pertinent (un
 * artiste connu dont la page contient par hasard un mot-clé musical) —
 * "parler de musique" (voir ytdl_wikipedia_page_is_musical) ne suffit donc
 * pas, il faut aussi que ce soit le bon nom. Comparaison volontairement
 * tolérante (accents/casse/ponctuation ignorés, désambiguateur entre
 * parenthèses retiré, article en tête ignoré — "The Weeknd" doit matcher
 * "Weeknd", "Drake (rappeur)" doit matcher "Drake") mais jamais un simple
 * nom contenu dans un autre ("Dylan" n'est pas "Bob Dylan").
 */
function ytdl_wikipedia_title_matches_artist(string $pageTitle, string $artist): bool {
    $normalize = function (string $s): string {
        $s = preg_replace('/\s*\([^()]*\)\s*$/u', '', $s); // "Drake (rappeur)" -> "Drake"
        $s = mb_strtolower(trim($s));
        $translit = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($translit !== false) $s = $translit;
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim($s);
    };
    // Une discographie, une liste, un album ou une chanson n'est pas une
    // fiche d'artiste (« Nirvana (album de Nirvana) »).
    if (preg_match('/^(discographie|discography|liste|list of)\b/iu', trim($pageTitle))) return false;
    if (preg_match('/\((?:[^()]*\b)?(album|chanson|single|song|film|ep|bande originale|soundtrack|tournée|tour)\b[^()]*\)\s*$/iu', $pageTitle)) return false;
    // Noms identiques, à un article près en tête (« The Weeknd » = « Weeknd »)
    // : un simple nom contenu dans le titre ne suffit pas (« Dylan », la
    // chanteuse, n'est pas « Bob Dylan »).
    $noArticle = fn(string $s): string => preg_replace('/^(the|le|la|les|l) /', '', $s);
    $normTitle = $noArticle($normalize($pageTitle));
    $normArtist = $noArticle($normalize($artist));
    if ($normTitle === '' || $normArtist === '') return false;
    return $normTitle === $normArtist;
}

/**
 * Intro complète (toutes les phrases avant le premier sous-titre, pas juste
 * la première comme l'API REST /page/summary) + photo + URL canonique, en un
 * seul appel à l'API classique de MediaWiki. null si la page n'existe pas ou
 * est une page d'homonymie (pageprops.disambiguation) — l'appelant retombe
 * alors sur la recherche. $lang choisit l'édition linguistique interrogée
 * (voir ytdl_fetch_artist_info, qui essaie plusieurs langues à la suite).
 */
function ytdl_wikipedia_intro(string $title, string $lang = 'fr'): ?array {
    $url = 'https://' . $lang . '.wikipedia.org/w/api.php?action=query'
        . '&prop=extracts|pageimages|pageprops|info|categories'
        . '&exintro=1&explaintext=1&piprop=original&ppprop=disambiguation&inprop=url&redirects=1&cllimit=50'
        . '&titles=' . rawurlencode($title) . '&format=json&formatversion=2';
    $data = ytdl_wikipedia_get($url);
    $page = $data['query']['pages'][0] ?? null;
    if (!is_array($page) || !empty($page['missing']) || isset($page['pageprops']['disambiguation'])) {
        return null;
    }
    return $page;
}

/**
 * La page trouvée parle-t-elle bien de musique ? Deux signaux combinés (l'un
 * ou l'autre suffit) : des mots-clés musicaux dans l'intro elle-même, ou
 * dans les catégories de l'article (souvent plus fiables — "Catégorie:
 * Groupe de rock américain" même quand l'intro, elle, ne dit jamais
 * explicitement "groupe"). Sert à écarter les homonymes qui n'ont rien à
 * voir avec un artiste/groupe (ex. "The Smiths" un patronyme, "Oasis" un
 * plan d'eau ou une marque) avant de proposer la page en consultation.
 */
function ytdl_wikipedia_page_is_musical(array $page, bool $introOnly = false): bool {
    $keywords = [
        'groupe', 'musicien', 'musicienne', 'chanteur', 'chanteuse', 'chanson',
        'album', 'rock', 'pop', 'rap', 'r&b', 'reggae', 'musique', 'compositeur',
        'compositrice', 'rappeur', 'rappeuse', 'auteur-compositeur', 'interprète',
        'band', 'singer', 'musician', 'discographie', 'tournée',
    ];
    // \b (mot entier, pas juste une sous-chaîne) : sans ça, "rap" matchait
    // à l'intérieur de "géographie" — un simple mb_strpos avait fait
    // accepter à tort la page "Oasis" (le point d'eau) via sa catégorie
    // "Portail:Géographie/Articles liés".
    $pattern = '/\b(' . implode('|', array_map(fn($k) => preg_quote($k, '/'), $keywords)) . ')\b/ui';
    $extract = (string)($page['extract'] ?? '');
    if (preg_match($pattern, $extract)) return true;
    if ($introOnly) return false;
    foreach (($page['categories'] ?? []) as $cat) {
        if (preg_match($pattern, (string)($cat['title'] ?? ''))) return true;
    }
    return false;
}

/**
 * Décalage manuel (secondes) du minutage synchronisé pour un morceau donné —
 * réglable depuis la feuille de paroles (boutons +/-, par pas d'une demi-
 * seconde) quand le minutage lrclib.net est systématiquement en avance ou en
 * retard sur le fichier réellement téléchargé (bumper de chaîne, pub ajoutée
 * en tête/fin de la vidéo YouTube...). Stocké séparément du cache de paroles
 * lui-même (fichier dédié) : un réglage réalisé par un compte doit survivre à
 * un rafraîchissement du cache (nouvelle recherche lrclib, expiration d'un
 * résultat négatif...) et rester valable pour tous les comptes sur ce même
 * morceau, jamais réinitialisé par erreur.
 *
 * Deux réglages INDÉPENDANTS par morceau, d'où la "variante" ($variant) :
 * "audio" pour le fichier sonore, "video" pour le clip. Un clip a très
 * souvent une intro (plan d'ouverture, dialogue, logo de label) absente de
 * l'audio : le même décalage ne peut pas convenir aux deux, et corriger l'un
 * dérèglerait l'autre. La variante "audio" garde la clé historique, pour ne
 * perdre aucun réglage déjà enregistré.
 */
function ytdl_lyrics_offset_dir(): string {
    $dir = YTDL_DATA_DIR . '/lyrics-offsets';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

function ytdl_lyrics_offset_path(string $artist, string $title, string $variant = 'audio'): string {
    $artist = ytdl_lyrics_query_name($artist);
    $title = ytdl_lyrics_query_name($title);
    $key = mb_strtolower(trim($artist)) . '|' . mb_strtolower(trim($title));
    // La variante "audio" n'ajoute rien à la clé : c'est celle d'avant
    // l'existence des variantes, les réglages déjà enregistrés restent lus.
    if ($variant !== 'audio') $key .= '|' . $variant;
    return ytdl_lyrics_offset_dir() . '/' . hash('sha256', $key) . '.json';
}

/**
 * Emplacement utilisé AVANT le retrait des mentions d'artistes invités
 * (ytdl_strip_featuring) : un décalage réglé à la main ne doit pas être perdu
 * parce que la clé de recherche a changé — seulement relu, jamais réécrit
 * ici (toute nouvelle écriture part sur la clé courante).
 */
function ytdl_lyrics_offset_path_legacy(string $artist, string $title): string {
    $artist = ytdl_strip_trailing_annotations($artist);
    $title = ytdl_strip_trailing_annotations($title);
    $key = mb_strtolower(trim($artist)) . '|' . mb_strtolower(trim($title));
    return ytdl_lyrics_offset_dir() . '/' . hash('sha256', $key) . '.json';
}

function ytdl_get_lyrics_offset(string $artist, string $title, string $variant = 'audio'): float {
    $path = ytdl_lyrics_offset_path($artist, $title, $variant);
    // Repli sur l'ancienne clé pour l'audio seul : la variante "video"
    // n'existait pas avant, il n'y a rien à y récupérer.
    if (!is_file($path) && $variant === 'audio') $path = ytdl_lyrics_offset_path_legacy($artist, $title);
    if (!is_file($path)) return 0.0;
    $data = json_decode((string)@file_get_contents($path), true);
    return (is_array($data) && isset($data['offset'])) ? (float)$data['offset'] : 0.0;
}

// Borne du décalage manuel, par variante. Plus large pour un clip : une
// intro de vidéo (plan d'ouverture, dialogue, logo de label) dépasse
// couramment la minute, là où un décalage audio de cet ordre ne serait plus
// un réglage fin mais le signe d'un minutage qui ne convient pas du tout.
const YTDL_LYRICS_OFFSET_LIMIT_S = ['audio' => 60.0, 'video' => 180.0];

function ytdl_set_lyrics_offset(string $artist, string $title, float $offset, string $variant = 'audio'): float {
    $limit = YTDL_LYRICS_OFFSET_LIMIT_S[$variant] ?? 60.0;
    $offset = max(-$limit, min($limit, $offset));
    @file_put_contents(
        ytdl_lyrics_offset_path($artist, $title, $variant),
        json_encode(['offset' => $offset, 'updated_at' => time()])
    );
    return $offset;
}

/**
 * Découpe un texte au format LRC ("[mm:ss.xx] paroles", une ligne par
 * horodatage) en tableau exploitable côté client pour le surlignage
 * synchronisé. Les lignes sans horodatage exploitable sont ignorées plutôt
 * que de casser l'affichage.
 */
function ytdl_parse_lrc(string $lrc): array {
    $lines = [];
    foreach (preg_split('/\r\n|\r|\n/', $lrc) as $line) {
        if (preg_match('/^\[(\d+):(\d+(?:\.\d+)?)\](.*)$/', $line, $m)) {
            $lines[] = ['time' => ((int)$m[1]) * 60 + (float)$m[2], 'text' => trim($m[3])];
        }
    }
    return $lines;
}

/**
 * lrclib.net référence souvent PLUSIEURS enregistrements sous le même
 * artiste/titre (album, radio edit, live, réédition remasterisée...), avec
 * des durées différentes. /api/get (correspondance exacte) n'en renvoie
 * qu'un seul, choisi arbitrairement par leur système — pas forcément celui
 * qui correspond au fichier réellement téléchargé. Quand on connaît la durée
 * réelle du fichier, /api/search + sélection par durée la plus proche (dans
 * la tolérance) est bien plus fiable : parmi les éditions dans la tolérance,
 * on préfère celle qui a un minutage synchronisé, puis la plus proche en durée.
 * Retourne null si aucune édition n'est assez proche.
 */
function ytdl_search_lrclib_best_match(string $artist, string $title, float $knownDurationSeconds): ?array {
    $url = 'https://lrclib.net/api/search?artist_name=' . rawurlencode($artist) . '&track_name=' . rawurlencode($title);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: ytdl-personal-app/1.0'],
    ]);
    $response = curl_exec($ch);
    if ($response === false) { return null; }
    $codeHTTP = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($codeHTTP !== 200) return null;

    $results = json_decode((string)$response, true);
    if (!is_array($results)) return null;

    $best = null;
    $bestHasSynced = false;
    $bestDiff = null;
    foreach ($results as $r) {
        if (empty($r['plainLyrics']) || !isset($r['duration'])) continue;

        $hasSynced = !empty($r['syncedLyrics']);
        $synced = $hasSynced ? ytdl_parse_lrc($r['syncedLyrics']) : null;
        $hasSynced = $synced && count($synced) > 0;

        // Le champ "duration" de l'API peut être incohérent avec son PROPRE
        // fichier de paroles synchronisées (déjà rencontré : duration=270s
        // pour un LRC dont la dernière ligne est à 344s) — on retient donc la
        // plus grande des deux valeurs comme durée de référence, jamais la
        // seule métadonnée déclarée, sans quoi une entrée mal renseignée
        // passerait le contrôle alors que son minutage réel ne correspond à
        // rien une fois posé sur le fichier téléchargé.
        $apiDuration = (float)$r['duration'];
        $lastLineTime = $hasSynced ? end($synced)['time'] : null;
        $effectiveDuration = $lastLineTime !== null ? max($apiDuration, $lastLineTime) : $apiDuration;

        $diff = abs($effectiveDuration - $knownDurationSeconds);
        if ($diff > YTDL_LYRICS_DURATION_TOLERANCE_S) continue;

        // Priorité à une édition synchronisée sur une édition plus proche en
        // durée mais sans minutage ; à égalité (les deux synchronisées, ou
        // les deux non), on garde la plus proche en durée.
        $better = $best === null
            || ($hasSynced && !$bestHasSynced)
            || ($hasSynced === $bestHasSynced && $diff < $bestDiff);
        if (!$better) continue;

        $best = [
            'lyrics' => trim($r['plainLyrics']),
            'synced' => $hasSynced ? $synced : null,
            'reference_duration' => $effectiveDuration,
        ];
        $bestHasSynced = $hasSynced;
        $bestDiff = $diff;
    }
    return $best;
}

/**
 * Paroles synchronisées + brutes via l'API publique lrclib.net (gratuite,
 * sans clé). Contrairement à lyrics.ovh (repli ci-dessous), elle fournit
 * aussi un minutage ligne par ligne quand il existe, exploité pour le
 * surlignage en cours de lecture (voir maybeAutoShowLyrics côté client).
 *
 * Quand $knownDurationSeconds est fourni, une recherche large est tentée
 * d'abord pour choisir la bonne édition parmi celles disponibles (voir
 * ytdl_search_lrclib_best_match) — sinon (ou si rien d'assez proche), repli
 * sur la correspondance exacte artiste/titre habituelle (/api/get), pas de
 * recherche floue sur le texte lui-même.
 */
function ytdl_fetch_lrclib(string $artist, string $title, ?float $knownDurationSeconds = null): ?array {
    if ($knownDurationSeconds !== null) {
        $bestMatch = ytdl_search_lrclib_best_match($artist, $title, $knownDurationSeconds);
        if ($bestMatch) return $bestMatch;
    }

    $url = 'https://lrclib.net/api/get?artist_name=' . rawurlencode($artist) . '&track_name=' . rawurlencode($title);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: ytdl-personal-app/1.0'],
    ]);
    $response = curl_exec($ch);
    if ($response === false) { return null; }
    $codeHTTP = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($codeHTTP !== 200) return null;

    $data = json_decode((string)$response, true);
    if (!is_array($data) || empty($data['plainLyrics'])) return null;

    $synced = !empty($data['syncedLyrics']) ? ytdl_parse_lrc($data['syncedLyrics']) : null;
    $hasSynced = $synced && count($synced) > 0;
    $apiDuration = isset($data['duration']) ? (float)$data['duration'] : null;
    // Même garde-fou que ytdl_search_lrclib_best_match : le "duration" de
    // l'API peut être incohérent avec son propre fichier LRC.
    $lastLineTime = $hasSynced ? end($synced)['time'] : null;
    $effectiveDuration = ($lastLineTime !== null && $apiDuration !== null)
        ? max($apiDuration, $lastLineTime)
        : ($lastLineTime ?? $apiDuration);
    return [
        'lyrics' => trim($data['plainLyrics']),
        'synced' => $hasSynced ? $synced : null,
        // Durée de L'ENREGISTREMENT référencé par lrclib — jamais celle du
        // fichier de l'appelant. Comparée à ytdl_fetch_lyrics_data() pour
        // écarter un minutage qui ne correspond pas au même enregistrement
        // (une correspondance artiste/titre exacte peut très bien retomber
        // sur une version live, un radio edit, etc.).
        'reference_duration' => $effectiveDuration,
    ];
}

/**
 * Repli lyrics.ovh : paroles brutes seules, sans minutage — utilisé quand
 * lrclib.net n'a rien pour ce morceau.
 */
function ytdl_fetch_lyrics_ovh(string $artist, string $title): ?string {
    $url = 'https://api.lyrics.ovh/v1/' . rawurlencode($artist) . '/' . rawurlencode($title);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    if ($response === false) { return null; }
    $codeHTTP = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $data = json_decode((string)$response, true);
    return ($codeHTTP === 200 && !empty($data['lyrics'])) ? trim($data['lyrics']) : null;
}

// =========================================================================
// Import de playlist externe (Spotify, Deezer, YouTube Music, Apple Music)
// =========================================================================
// Objectif : coller un lien de playlist publique et retrouver chaque titre
// sur YouTube pour l'ajouter à la bibliothèque, dans une playlist locale du
// même nom. Amazon Music n'a aucune API publique ni page exploitable — pas
// de résolveur pour cette plateforme.

/**
 * GET avec un User-Agent de navigateur (certaines pages publiques renvoient
 * une version dégradée ou bloquent purement et simplement un client sans
 * User-Agent reconnaissable) et un timeout court — ces requêtes bloquent la
 * réponse à l'utilisateur, pas question de le faire attendre 30 secondes
 * pour une plateforme en panne.
 */
function ytdl_http_get(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/json'],
        CURLOPT_MAXREDIRS      => 5,
    ]);
    // HTTPS uniquement, redirections comprises (pas de file://, gopher://, http:// interne…).
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https');
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS_STR, 'https');
    } else {
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
    }
    $response = curl_exec($ch);
    $codeHTTP = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return ($response !== false && $codeHTTP === 200) ? $response : null;
}

function ytdl_http_get_json(string $url): ?array {
    $body = ytdl_http_get($url);
    if ($body === null) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/**
 * Parcourt récursivement un tableau décodé et renvoie la première valeur
 * trouvée sous cette clé, à n'importe quelle profondeur — utilisé pour lire
 * le JSON embarqué des pages Spotify/Apple Music, dont la structure exacte
 * n'est pas documentée officiellement et peut changer sans préavis : mieux
 * vaut chercher la clé utile où qu'elle soit que viser un chemin fixe qui
 * casserait au moindre remaniement de page.
 */
function ytdl_json_find_key(array $data, string $key) {
    if (array_key_exists($key, $data) && $data[$key] !== null) return $data[$key];
    foreach ($data as $value) {
        if (is_array($value)) {
            $found = ytdl_json_find_key($value, $key);
            if ($found !== null) return $found;
        }
    }
    return null;
}

/**
 * Vrai si $url est un lien http(s) dont l'hôte est exactement $host (ou www.$host).
 * Les plateformes ne se reconnaissent plus par sous-chaîne : sinon
 * « http://127.0.0.1/…#music.apple.com/… » passait et le serveur allait
 * chercher une adresse interne (SSRF).
 */
function ytdl_url_host_is(string $url, string $host): bool {
    $parts = parse_url(trim($url));
    if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) return false;
    $scheme = strtolower($parts['scheme'] ?? '');
    $actual = strtolower($parts['host'] ?? '');
    return in_array($scheme, ['http', 'https'], true) && ($actual === $host || $actual === 'www.' . $host);
}

/**
 * Lien canonique reconstruit pour les plateformes dont on télécharge l'URL
 * elle-même (Apple Music, YouTube Music) : jamais l'URL brute de l'utilisateur.
 */
function ytdl_canonical_playlist_url(string $platform, string $url): ?string {
    if ($platform === 'ytmusic' && ytdl_url_host_is($url, 'music.youtube.com')) {
        parse_str((string)parse_url(trim($url), PHP_URL_QUERY), $query);
        $list = is_string($query['list'] ?? null) ? $query['list'] : '';
        if (parse_url(trim($url), PHP_URL_PATH) === '/playlist' && preg_match('#^[A-Za-z0-9_-]+$#', $list)) {
            return 'https://music.youtube.com/playlist?list=' . $list;
        }
    }
    if ($platform === 'apple' && ytdl_url_host_is($url, 'music.apple.com')) {
        $path = (string)parse_url(trim($url), PHP_URL_PATH);
        if (preg_match('#^/([a-z]{2})/playlist/([^/?\#]+)/(pl\.[a-z0-9-]+)/?$#i', $path, $m)) {
            return 'https://music.apple.com/' . strtolower($m[1]) . '/playlist/' . rawurlencode(rawurldecode($m[2])) . '/' . $m[3];
        }
    }
    return null;
}

/** Détecte la plateforme d'un lien de playlist externe, ou null si inconnu. */
function ytdl_detect_playlist_platform(string $url): ?string {
    // Spotify et Deezer : on n'en extrait qu'un identifiant numérique /
    // alphanumérique, l'URL appelée est construite côté serveur.
    if (preg_match('#open\.spotify\.com/(?:[a-zA-Z-]+/)?playlist/([A-Za-z0-9]+)#i', $url)) return 'spotify';
    if (preg_match('#deezer\.(com|page\.link)/.*?playlist[/:](\d+)#i', $url) || preg_match('#deezer\.com/playlist/(\d+)#i', $url)) return 'deezer';
    if (ytdl_canonical_playlist_url('ytmusic', $url) !== null) return 'ytmusic';
    if (ytdl_canonical_playlist_url('apple', $url) !== null) return 'apple';
    return null;
}

/** Point d'entrée unique : détecte la plateforme puis délègue au résolveur. */
function ytdl_resolve_external_playlist(string $url): array {
    $platform = ytdl_detect_playlist_platform($url);
    switch ($platform) {
        case 'deezer':  return ytdl_resolve_deezer_playlist($url);
        case 'ytmusic': return ytdl_resolve_ytmusic_playlist($url);
        case 'spotify': return ytdl_resolve_spotify_playlist($url);
        case 'apple':   return ytdl_resolve_apple_playlist($url);
        default:
            return ['error' => "Lien non reconnu — colle un lien de playlist publique Spotify, Deezer, YouTube Music ou Apple Music."];
    }
}

/** Deezer : API publique officielle, sans authentification. Le plus fiable des quatre. */
function ytdl_resolve_deezer_playlist(string $url): array {
    if (!preg_match('#playlist[/:](\d+)#i', $url, $m)) {
        return ['error' => "Lien Deezer non reconnu."];
    }
    $data = ytdl_http_get_json('https://api.deezer.com/playlist/' . $m[1] . '?limit=1000');
    if ($data === null || isset($data['error'])) {
        return ['error' => "Impossible de lire cette playlist Deezer — vérifie qu'elle est publique."];
    }
    $tracks = [];
    foreach (($data['tracks']['data'] ?? []) as $t) {
        $artist = trim((string)($t['artist']['name'] ?? ''));
        $title  = trim((string)($t['title'] ?? ''));
        if ($artist !== '' || $title !== '') $tracks[] = ['artist' => $artist, 'title' => $title];
    }
    if (!$tracks) return ['error' => "Playlist Deezer vide ou introuvable."];
    return ['platform' => 'deezer', 'name' => trim((string)($data['title'] ?? '')) ?: 'Playlist Deezer', 'tracks' => $tracks];
}

/**
 * YouTube Music : c'est déjà YouTube. Pas de recherche à faire ensuite —
 * chaque entrée porte directement sa vraie URL YouTube (youtube_url),
 * contrairement aux autres plateformes qui ne fournissent qu'artiste+titre.
 */
function ytdl_resolve_ytmusic_playlist(string $url): array {
    $url = ytdl_canonical_playlist_url('ytmusic', $url);
    if ($url === null) return ['error' => "Lien YouTube Music non reconnu."];
    $ytDlp = ytdl_which('yt-dlp');
    if (!$ytDlp) return ['error' => 'yt-dlp est introuvable sur le serveur.'];

    $scratch = ytdl_scratch_dir();
    // playlist_title répété sur chaque ligne (pas de moyen plus simple de le
    // récupérer une seule fois en --flat-playlist) : on ne garde que celui de
    // la première.
    $printFormat = "%(playlist_title)s\x1f%(id)s\x1f%(title)s\x1f%(uploader)s";
    $cmd = sprintf(
        '%s%s%s --flat-playlist --skip-download --playlist-end 300 --print %s -- %s',
        escapeshellarg($ytDlp),
        ytdl_js_runtime_flag(),
        ytdl_cookies_flag($scratch),
        escapeshellarg($printFormat),
        escapeshellarg($url)
    );
    [$out, $err] = ytdl_run_capture($cmd);
    ytdl_rm_scratch($scratch);

    if ($out === '') {
        return ['error' => $err !== '' ? ytdl_explain_error($err) : "Playlist YouTube Music introuvable ou privée."];
    }

    $tracks = [];
    $playlistName = null;
    foreach (explode("\n", $out) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = explode("\x1f", $line);
        $id = $parts[1] ?? '';
        if ($id === '' || $id === 'NA') continue;
        if ($playlistName === null) {
            $rawName = $parts[0] ?? '';
            $playlistName = ($rawName !== '' && $rawName !== 'NA') ? $rawName : 'Playlist YouTube Music';
        }
        $title    = ($parts[2] ?? '') !== 'NA' ? ($parts[2] ?? '') : '';
        $uploader = ($parts[3] ?? '') !== 'NA' ? ($parts[3] ?? '') : '';
        $tracks[] = [
            'artist'      => $uploader,
            'title'       => $title,
            'youtube_url' => 'https://www.youtube.com/watch?v=' . $id,
        ];
    }
    if (!$tracks) return ['error' => "Playlist YouTube Music vide ou introuvable."];
    return ['platform' => 'ytmusic', 'name' => $playlistName ?? 'Playlist YouTube Music', 'tracks' => $tracks];
}

/**
 * Spotify : pas d'appel officiel possible sans compte développeur + OAuth.
 * Best-effort via la page d'embed publique (open.spotify.com/embed/playlist/…),
 * qui expose son état dans un <script id="__NEXT_DATA__"> — structure non
 * documentée par Spotify, susceptible de changer sans préavis. D'où la
 * recherche récursive plutôt qu'un chemin fixe, et une erreur explicite
 * plutôt qu'un résultat silencieusement incomplet si la structure attendue
 * n'est plus là.
 */
function ytdl_resolve_spotify_playlist(string $url): array {
    if (!preg_match('#playlist/([A-Za-z0-9]+)#', $url, $m)) {
        return ['error' => "Lien Spotify non reconnu."];
    }
    $html = ytdl_http_get('https://open.spotify.com/embed/playlist/' . $m[1]);
    if ($html === null) {
        return ['error' => "Impossible de joindre Spotify — la playlist est peut-être privée."];
    }
    if (!preg_match('#<script id="__NEXT_DATA__"[^>]*>(.*?)</script>#s', $html, $m2)) {
        return ['error' => "Page Spotify non reconnue (structure modifiée par Spotify, ou playlist privée)."];
    }
    $data = json_decode($m2[1], true);
    if (!is_array($data)) {
        return ['error' => "Réponse Spotify illisible."];
    }

    $entity = ytdl_json_find_key($data, 'entity');
    $trackList = is_array($entity) ? ($entity['trackList'] ?? null) : null;
    if (!is_array($trackList)) $trackList = ytdl_json_find_key($data, 'trackList');
    if (!is_array($trackList)) {
        return ['error' => "Impossible de retrouver la liste des titres sur la page Spotify (structure inattendue)."];
    }

    $tracks = [];
    foreach ($trackList as $t) {
        if (!is_array($t)) continue;
        $title  = trim((string)($t['title'] ?? ''));
        $artist = trim((string)($t['subtitle'] ?? ''));
        if ($title !== '') $tracks[] = ['artist' => $artist, 'title' => $title];
    }
    if (!$tracks) return ['error' => "Playlist Spotify vide, ou introuvable."];

    $name = is_array($entity) ? trim((string)($entity['title'] ?? '')) : '';
    return ['platform' => 'spotify', 'name' => $name !== '' ? $name : 'Playlist Spotify', 'tracks' => $tracks];
}

/**
 * Apple Music : même limitation que Spotify (MusicKit JS nécessite un compte
 * développeur payant). Best-effort via le JSON-LD "MusicPlaylist" que la page
 * publique embarque pour le référencement — lui aussi non garanti dans le
 * temps, d'où la même tolérance aux erreurs qu'au-dessus.
 */
function ytdl_resolve_apple_playlist(string $url): array {
    $url = ytdl_canonical_playlist_url('apple', $url);
    if ($url === null) return ['error' => "Lien Apple Music non reconnu."];
    $html = ytdl_http_get($url);
    if ($html === null) {
        return ['error' => "Impossible de joindre Apple Music — la playlist est peut-être privée."];
    }
    if (!preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches)) {
        return ['error' => "Page Apple Music non reconnue (structure modifiée par Apple, ou playlist privée)."];
    }

    $tracks = [];
    $name = '';
    foreach ($matches[1] as $block) {
        $data = json_decode($block, true);
        if (!is_array($data) || ($data['@type'] ?? '') !== 'MusicPlaylist') continue;
        $name = trim((string)($data['name'] ?? ''));
        foreach (($data['track'] ?? []) as $t) {
            if (!is_array($t)) continue;
            $title  = trim((string)($t['name'] ?? ''));
            $byArtist = $t['byArtist'] ?? null;
            $artist = trim((string)(is_array($byArtist) ? ($byArtist['name'] ?? '') : ($byArtist ?? '')));
            if ($title !== '') $tracks[] = ['artist' => $artist, 'title' => $title];
        }
        break;
    }
    if (!$tracks) {
        return ['error' => "Impossible de retrouver la liste des titres sur la page Apple Music (structure inattendue, ou playlist privée)."];
    }
    return ['platform' => 'apple', 'name' => $name !== '' ? $name : 'Playlist Apple Music', 'tracks' => $tracks];
}

/**
 * Retire, en boucle, tout groupe entre parenthèses/crochets collé en fin de
 * chaîne — quel que soit son contenu. Contrairement à ytdl_clean_title() ci-
 * dessus (volontairement conservatrice : ne retire que des mentions connues,
 * pour ne jamais amputer le titre affiché dans la bibliothèque d'une info
 * légitime), ici n'importe quelle annotation finale — même un nom de film
 * ("[The Greatest Showman Soundtrack]"), une précision de version, etc. —
 * fait échouer la recherche par correspondance exacte des API de paroles.
 * Utilisée uniquement pour cette recherche, jamais pour le titre stocké.
 */
function ytdl_strip_trailing_annotations(string $text): string {
    $clean = trim($text);
    while (preg_match('/^(.*?)\s*[\(\[][^\(\)\[\]]*[\)\]]\s*$/u', $clean, $m)) {
        $next = trim($m[1]);
        if ($next === '' || $next === $clean) break; // ne jamais tout effacer
        $clean = $next;
    }
    return $clean;
}

/**
 * Retire la mention d'artistes invités ("feat.", "ft.", "featuring", "w/"...)
 * d'un artiste ou d'un titre, pour la seule recherche de paroles.
 *
 * lrclib.net (comme lyrics.ovh) fonctionne par correspondance EXACTE sur le
 * couple artiste/titre : « Titre feat. Untel » ne ramène rien alors que le
 * morceau y est référencé sous « Titre ». C'est de loin la première cause
 * d'absence de minutage synchronisé sur les titres de la bibliothèque, d'où
 * ce nettoyage — jamais appliqué au titre stocké ni affiché.
 *
 * Deux passes : les groupes entre parenthèses/crochets introduits par une de
 * ces mentions, où qu'ils soient dans la chaîne ("Titre (feat. X) [Live]"),
 * puis la mention en clair qui court jusqu'à la fin ("Titre feat. X & Y",
 * "Artiste ft. Untel"). Seule la mention SUIVIE d'un nom est retirée, et
 * jamais au point de vider la chaîne.
 *
 * "with" est volontairement absent de la liste : trop de titres légitimes
 * l'emploient ("Dancing With Myself") pour le traiter comme une mention
 * d'invité.
 */
function ytdl_strip_featuring(string $text): string {
    $keywords = 'feat|feats|featuring|ft|f|w';
    $clean = trim($text);

    // Groupe parenthésé ou crocheté introduit par la mention, n'importe où.
    $clean = preg_replace(
        '/\s*[\(\[]\s*(?:' . $keywords . ')\b[.\/]?\s+[^()\[\]]*[\)\]]/iu',
        '',
        $clean
    );

    // Mention en clair, jusqu'à la fin de la chaîne — éventuellement précédée
    // d'un séparateur ("Titre - feat. X", "Titre, ft. X").
    $stripped = preg_replace(
        '/\s*(?:[-–—|,\/]\s*)?\b(?:' . $keywords . ')\b[.\/]?\s+\S.*$/iu',
        '',
        $clean
    );
    if (is_string($stripped) && trim($stripped) !== '') {
        $clean = $stripped;
    }

    $clean = trim(preg_replace('/\s{2,}/', ' ', $clean));
    // Ne jamais tout effacer : un artiste ou un titre vide ne chercherait
    // plus rien du tout.
    return $clean !== '' ? $clean : trim($text);
}

/**
 * Forme d'un artiste ou d'un titre utilisée pour INTERROGER les API de
 * paroles (et pour les clés de cache/décalage qui en dépendent) : annotations
 * traînantes et mentions d'artistes invités retirées, en boucle jusqu'à
 * stabilisation — les deux se démasquent l'une l'autre ("Titre (feat. X)
 * [Official Video]", "Titre feat. X (Live)"). Jamais utilisée pour le titre
 * stocké ou affiché.
 */
function ytdl_lyrics_query_name(string $text): string {
    $clean = trim($text);
    for ($i = 0; $i < 4; $i++) {
        $next = ytdl_strip_featuring(ytdl_strip_trailing_annotations($clean));
        if ($next === $clean || $next === '') break;
        $clean = $next;
    }
    return $clean;
}

/**
 * Équivalent PHP de splitArtistTitle() côté client (capture.php) — même
 * règle, pour les besoins server-side (migration, ré-interrogation des
 * paroles...) qui n'ont pas accès au JS : "Artiste - Titre" (tiret simple,
 * en/em dash) donne les deux ; sinon repli sur la chaîne YouTube d'origine
 * comme artiste (seule source fiable quand le titre n'en contient pas), le
 * titre entier restant tel quel.
 */
function ytdl_split_artist_title(string $title, ?string $uploader = null): array {
    $full = trim($title) !== '' ? trim($title) : 'Sans titre';
    if (preg_match('/^(.+?)\s+[-–—]\s+(.+)$/u', $full, $m)) {
        $artist = trim($m[1]);
        $songTitle = trim($m[2]);
        if ($artist !== '' && $songTitle !== '') {
            return ['artist' => $artist, 'title' => $songTitle];
        }
    }
    return ['artist' => trim((string)$uploader), 'title' => $full];
}

// Écart maximal (secondes) toléré entre la durée du fichier réellement
// téléchargé et celle de l'enregistrement référencé par lrclib.net avant de
// considérer que ce n'est plus le même enregistrement — au-delà, le
// minutage synchronisé est écarté (les paroles brutes restent affichées).
// Volontairement généreux (au-delà d'un simple écart d'encodage) pour
// couvrir un bumper de chaîne ou une pub ajoutée en tête/fin de la vidéo
// YouTube, sans pour autant accepter un montage clairement différent
// (version live, radio edit...).
const YTDL_LYRICS_DURATION_TOLERANCE_S = 12.0;

/**
 * Paroles (brutes, + synchronisées quand disponibles) pour un morceau,
 * mises en cache sur disque : un résultat positif ne change jamais, on le
 * garde indéfiniment ; un résultat négatif est réessayé après 30 jours
 * (l'API peut avoir été enrichie depuis). Les entrées écrites par l'ancienne
 * version (paroles brutes seules, sans la clé "synced") sont retentées une
 * fois plutôt que de rester bloquées sans minutage indéfiniment.
 *
 * $knownDurationSeconds, quand fourni (durée réelle du fichier archivé, voir
 * ytdl_probe_duration_seconds), est comparée à celle de l'enregistrement
 * renvoyé par lrclib.net : au-delà de YTDL_LYRICS_DURATION_TOLERANCE_S
 * d'écart, "synced" est écarté du résultat (les paroles brutes restent
 * proposées) — une correspondance artiste/titre exacte peut très bien
 * retomber sur un enregistrement différent (live, radio edit...) dont le
 * minutage ne correspondrait à rien une fois appliqué au fichier téléchargé.
 * Cette validation ne modifie jamais l'entrée mise en cache elle-même (deux
 * fichiers différents peuvent partager la même clé artiste/titre).
 *
 * Retourne ['lyrics' => string|null, 'synced' => array|null] — jamais
 * d'exception, l'appelant se contente alors de garder l'icône masquée.
 */
function ytdl_fetch_lyrics_data(string $artist, string $title, ?float $knownDurationSeconds = null): array {
    // Les annotations traînantes ("(Lyric Video) [Movie Soundtrack]"...) et
    // les mentions d'artistes invités ("feat. X", "ft. Y") ne doivent jamais
    // atteindre les API de paroles : recherche par correspondance exacte, la
    // moindre mention en trop fait échouer le match alors que le morceau y
    // figure bel et bien.
    $artist = ytdl_lyrics_query_name($artist);
    $title = ytdl_lyrics_query_name($title);
    $cachePath = ytdl_lyrics_cache_path($artist, $title);

    // Les deux décalages manuels sont renvoyés ensemble : le lecteur audio et
    // la vue clip lisent le même morceau mais chacun avec son propre réglage
    // (voir ytdl_lyrics_offset_dir), et une seule requête les sert tous deux.
    $offset = ytdl_get_lyrics_offset($artist, $title, 'audio');
    $videoOffset = ytdl_get_lyrics_offset($artist, $title, 'video');
    $applyDurationCheck = function (array $result) use ($knownDurationSeconds, $offset, $videoOffset): array {
        $refDuration = $result['reference_duration'] ?? null;
        if ($knownDurationSeconds !== null && $refDuration !== null
            && abs($knownDurationSeconds - $refDuration) > YTDL_LYRICS_DURATION_TOLERANCE_S) {
            $result['synced'] = null;
        }
        return [
            'lyrics' => $result['lyrics'],
            'synced' => $result['synced'],
            'offset' => $offset,
            'video_offset' => $videoOffset,
        ];
    };

    if (is_file($cachePath)) {
        $cached = json_decode((string)@file_get_contents($cachePath), true);
        if (is_array($cached) && array_key_exists('lyrics', $cached) && array_key_exists('synced', $cached)) {
            $checkedAt = (int)($cached['checked_at'] ?? 0);
            $expired = $cached['lyrics'] === null && (time() - $checkedAt) > 30 * 86400;
            if (!$expired) {
                return $applyDurationCheck($cached);
            }
        }
    }

    $lrclib = ytdl_fetch_lrclib($artist, $title, $knownDurationSeconds);
    $result = $lrclib ?: ['lyrics' => ytdl_fetch_lyrics_ovh($artist, $title), 'synced' => null, 'reference_duration' => null];

    // La mise en cache garde reference_duration (utile aux futurs appelants
    // pour leur propre vérification) ; seule la valeur RETOURNÉE ici est
    // filtrée pour cet appelant précis.
    @file_put_contents($cachePath, json_encode($result + ['checked_at' => time()]));

    return $applyDurationCheck($result);
}

/**
 * Enregistre un minutage construit À LA MAIN par un utilisateur (voir
 * lyrics-manual-sync.php) — ligne par ligne, en tapant le rythme pendant la
 * lecture — pour un morceau dont aucune synchro exploitable n'existait
 * (lrclib.net muet, ou une seule entrée trouvée mais rejetée pour cause de
 * durée trop différente). Écrit dans le MÊME cache que ytdl_fetch_lyrics_data
 * (même clé artiste/titre), pour que toute la bibliothèque — tous les
 * comptes — en profite immédiatement à la prochaine lecture, exactement
 * comme un résultat trouvé automatiquement.
 *
 * $lines : tableau de ['time'=>float, 'text'=>string], déjà trié par temps
 * croissant côté appelant. $referenceDuration : durée du fichier qui a servi
 * à construire ce minutage — sert de référence pour les futures vérifications
 * de cohérence (voir YTDL_LYRICS_DURATION_TOLERANCE_S), exactement comme la
 * durée renvoyée par lrclib.net pour un résultat trouvé automatiquement.
 *
 * Le texte brut existant est conservé tel quel (jamais réécrit à partir des
 * lignes) : il vient de la même source déjà affichée à l'utilisateur avant
 * qu'il ne lance la synchronisation manuelle.
 */
function ytdl_save_manual_synced_lyrics(string $artist, string $title, array $lines, ?float $referenceDuration): void {
    $artist = ytdl_lyrics_query_name($artist);
    $title = ytdl_lyrics_query_name($title);
    $cachePath = ytdl_lyrics_cache_path($artist, $title);

    $existing = is_file($cachePath) ? json_decode((string)@file_get_contents($cachePath), true) : null;
    $plainLyrics = (is_array($existing) && !empty($existing['lyrics']))
        ? $existing['lyrics']
        : implode("\n", array_map(fn($l) => (string)($l['text'] ?? ''), $lines));

    $result = [
        'lyrics' => $plainLyrics,
        'synced' => $lines,
        'reference_duration' => $referenceDuration,
        'manual' => true,
    ];
    @file_put_contents($cachePath, json_encode($result + ['checked_at' => time()]));
}

/**
 * Dossier de cache des extraits de 30 secondes utilisés pour la pré-écoute
 * des résultats YouTube — même emplacement hors dossier servi qu'un cache
 * classique (voir ytdl_lyrics_cache_dir()) : un extrait ne change jamais,
 * inutile de relancer yt-dlp à chaque clic sur le même résultat.
 */
function ytdl_preview_cache_dir(): string {
    $dir = YTDL_DATA_DIR . '/preview-cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

function ytdl_preview_cache_path(string $videoId): string {
    return ytdl_preview_cache_dir() . '/' . $videoId . '.mp3';
}

/**
 * Télécharge (ou sert depuis le cache) les 30 premières secondes de la piste
 * audio d'une vidéo YouTube, pour la pré-écoute des résultats de recherche.
 * --download-sections s'appuie sur des requêtes HTTP par plage : pas besoin
 * de récupérer toute la piste pour un simple aperçu. Qualité modeste (5, pas
 * 0 comme pour un vrai téléchargement) : un aperçu n'a pas besoin d'être
 * transparent, et l'encodage est nettement plus rapide. Retourne null en cas
 * d'échec (jamais d'exception) : l'appelant se contente alors de répondre
 * une erreur, sans planter la recherche elle-même.
 */
function ytdl_fetch_preview_clip(string $videoId): ?string {
    if (!preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId)) {
        return null;
    }

    $cachePath = ytdl_preview_cache_path($videoId);
    if (is_file($cachePath) && filesize($cachePath) > 0) {
        return $cachePath;
    }

    $ytDlp  = ytdl_which('yt-dlp');
    $ffmpeg = ytdl_which('ffmpeg');
    if (!$ytDlp || !$ffmpeg) {
        return null;
    }

    $scratch = ytdl_scratch_dir();
    $outputTemplate = $scratch . '/preview.%(ext)s';
    $url = 'https://www.youtube.com/watch?v=' . $videoId;

    $cmd = sprintf(
        '%s --newline --download-sections %s -f %s -x --audio-format mp3 --audio-quality 5 --ffmpeg-location %s%s%s --no-playlist --restrict-filenames -o %s -- %s',
        escapeshellarg($ytDlp),
        escapeshellarg('*0-30'),
        escapeshellarg('bestaudio/best'),
        escapeshellarg($ffmpeg),
        ytdl_js_runtime_flag(),
        ytdl_cookies_flag($scratch),
        escapeshellarg($outputTemplate),
        escapeshellarg($url)
    );

    ytdl_run_capture($cmd);

    $mp3 = null;
    foreach (glob($scratch . '/preview.*') ?: [] as $f) {
        if (strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'mp3') { $mp3 = $f; break; }
    }
    if ($mp3 && filesize($mp3) > 0) {
        @rename($mp3, $cachePath);
    }
    ytdl_rm_scratch($scratch);

    return (is_file($cachePath) && filesize($cachePath) > 0) ? $cachePath : null;
}

function ytdl_json_error(string $message, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

function ytdl_clean_url(string $url): string {
    $url = trim($url);
    // Retire les paramètres superflus (ex : &list=..., &index=... quand le lien
    // vient d'une playlist) pour ne garder que l'URL de la vidéo elle-même.
    $ampPos = strpos($url, '&');
    if ($ampPos !== false) {
        $url = substr($url, 0, $ampPos);
    }
    return $url;
}

function ytdl_validate_url(string $url): void {
    if ($url === '' || !preg_match('#^https?://(www\.)?(youtube\.com|youtu\.be|m\.youtube\.com)/#i', $url)) {
        ytdl_json_error("Cette URL ne ressemble pas à une URL YouTube valide.");
    }
}

/**
 * Fichier de cookies YouTube (format Netscape), ou null s'il n'est pas configuré.
 * Il vit hors du dossier servi par Apache, comme la base : ces cookies donnent
 * accès au compte Google associé, ils ne doivent jamais être téléchargeables.
 */
function ytdl_cookies_path(): ?string {
    $path = YTDL_DATA_DIR . '/cookies.txt';
    return (is_file($path) && is_readable($path)) ? $path : null;
}

/**
 * Prépare une copie jetable des cookies dans $workDir et renvoie le flag
 * --cookies correspondant (chaîne vide si aucun fichier n'est configuré).
 *
 * yt-dlp réécrit le fichier qu'on lui passe pour y enregistrer les jetons
 * rafraîchis : deux téléchargements simultanés corrompraient le fichier
 * maître. On ne lui confie donc qu'une copie, jamais l'original.
 */
function ytdl_cookies_flag(string $workDir): string {
    $src = ytdl_cookies_path();
    if ($src === null) return '';
    $copy = $workDir . '/cookies.txt';
    if (!@copy($src, $copy)) return '';
    @chmod($copy, 0600);
    return ' --cookies ' . escapeshellarg($copy);
}

/**
 * Flag --js-runtimes pour deno, ou chaîne vide si deno est introuvable.
 *
 * Sans le chemin explicite, l'auto-détection de yt-dlp échoue silencieusement
 * quand le binaire est lancé par Apache/PHP-FPM (contexte de process distinct
 * de celui d'un Terminal interactif) : le déchiffrement de signature JS ("n
 * challenge") échoue, YouTube ne renvoie alors que des formats inutilisables
 * (miniatures) et yt-dlp échoue avec "Requested format is not available" —
 * y compris sur un simple appel --skip-download pour récupérer le titre.
 * --js-runtimes est un flag séparé, pas une simple résolution de PATH pour un
 * binaire déjà appelé par son chemin complet.
 */
function ytdl_js_runtime_flag(): string {
    $deno = ytdl_which('deno');
    return $deno ? sprintf(' --js-runtimes %s', escapeshellarg('deno:' . $deno)) : '';
}

/** Dossier de travail temporaire à usage unique (appels synchrones : à supprimer après). */
function ytdl_scratch_dir(): string {
    $dir = sys_get_temp_dir() . '/ytdl-tmp-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700, true);
    return $dir;
}

function ytdl_rm_scratch(string $dir): void {
    foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($dir);
}

/**
 * Lance yt-dlp en capturant stderr séparément et renvoie [sortie, message d'erreur].
 * Sans ça, toute panne (vidéo bloquée, réseau, extracteur obsolète) était rapportée
 * à l'utilisateur comme une URL invalide, ce qui l'envoyait sur une fausse piste.
 */
function ytdl_run_capture(string $cmd): array {
    $errFile = tempnam(sys_get_temp_dir(), 'ytdl-err');
    $out = trim((string)shell_exec($cmd . ' 2>' . escapeshellarg($errFile)));
    $err = trim((string)@file_get_contents($errFile));
    @unlink($errFile);
    return [$out, $err];
}

/** Traduit la sortie d'erreur de yt-dlp en message affichable. */
function ytdl_explain_error(string $stderr): string {
    // YouTube exige de plus en plus souvent une session authentifiée sur
    // certaines vidéos : sans cookies, l'extraction échoue avant tout
    // téléchargement, quelle que soit la qualité demandée.
    if (stripos($stderr, 'not a bot') !== false || stripos($stderr, 'Sign in to confirm') !== false) {
        return ytdl_cookies_path() === null
            ? "YouTube exige une connexion pour cette vidéo (vérification anti-robot). "
              . "Le serveur doit être configuré avec des cookies YouTube pour la télécharger."
            : "YouTube refuse la session enregistrée sur le serveur : les cookies ont "
              . "probablement expiré et doivent être réexportés.";
    }
    if (stripos($stderr, 'Private video') !== false) {
        return 'Cette vidéo est privée.';
    }
    if (stripos($stderr, 'Video unavailable') !== false || stripos($stderr, 'has been removed') !== false) {
        return "Cette vidéo n'est plus disponible.";
    }
    if (stripos($stderr, 'members-only') !== false || stripos($stderr, 'This live event') !== false) {
        return 'Cette vidéo est réservée aux membres ou pas encore diffusée.';
    }
    if (stripos($stderr, 'age') !== false && stripos($stderr, 'confirm') !== false) {
        return "Cette vidéo est soumise à une restriction d'âge.";
    }

    $line = '';
    foreach (array_reverse(explode("\n", $stderr)) as $candidate) {
        if (stripos($candidate, 'ERROR') !== false) { $line = trim($candidate); break; }
    }
    return $line !== ''
        ? "Impossible de récupérer cette vidéo. Détail : " . $line
        : "Impossible de récupérer les informations de cette vidéo. Vérifie l'URL.";
}

// Espace disque minimum à garder libre sur le serveur, tous comptes confondus
// — vérifié avant CHAQUE téléchargement (voir ytdl_ensure_disk_space() dans
// start.php), indépendamment du quota de 5 Go par compte : le quota protège
// un compte de lui-même, ce seuil protège le serveur de la somme de tous les
// comptes (et de tout ce qui n'est pas la bibliothèque : jobs en cours, base,
// logs...).
const YTDL_MIN_FREE_DISK_BYTES = 3 * 1024 * 1024 * 1024; // 3 Go

/**
 * Espace libre restant sur le volume qui héberge la bibliothèque (jobs +
 * fichiers conservés). true si un téléchargement peut démarrer sans risquer
 * de saturer le serveur.
 */
function ytdl_has_enough_disk_space(): bool {
    if (!ytdl_library_volume_mounted()) return false;
    $free = @disk_free_space(YTDL_LIBRARY_ROOT);
    // Mesure impossible (chemin inaccessible, etc.) : on laisse passer plutôt
    // que de bloquer tout le monde sur un faux positif — un vrai manque
    // d'espace échouera de toute façon plus loin, de façon plus explicite.
    if ($free === false) return true;
    return $free >= YTDL_MIN_FREE_DISK_BYTES;
}

/** Le marqueur empêche une écriture sur un volume de bibliothèque absent. */
const YTDL_LIBRARY_VOLUME_MARKER = YTDL_LIBRARY_ROOT . '/.ytdl-library-volume';

// Tolérance de durée pour considérer deux téléchargements séparés comme "le
// même contenu" (voir dedupe-check.php et migrate-library-layout.php) : deux
// téléchargements de la même vidéo n'aboutissent pas toujours à un fichier
// de durée strictement identique (précision de mesure, ré-encodage...).
const YTDL_DEDUPE_DURATION_TOLERANCE = 2; // secondes

function ytdl_library_volume_mounted(): bool {
    return is_file(YTDL_LIBRARY_VOLUME_MARKER);
}

/**
 * Nom du sous-dossier de bibliothèque pour ce type de contenu : 'others' pour
 * tout ce qui est catégorisé hors musique (mélange audio+vidéo, pas de
 * distinction à ce niveau — voir capture.php, bouton "Hors musique"),
 * 'audio'/'clips' sinon comme avant. Centralisé ici : utilisé aussi bien
 * pour le dossier personnel d'un compte que pour Shared/.
 */
function ytdl_library_subdir_name(bool $audioOnly, string $category = 'music'): string {
    if ($category === 'other') return 'others';
    return $audioOnly ? 'audio' : 'clips';
}

/**
 * Dossier de la bibliothèque personnelle d'un compte (copies MP3/MP4
 * conservées pour relecture en page, hors des dossiers de job éphémères qui
 * sont effacés dès le fichier transmis au navigateur) — scindé en
 * sous-dossiers audio/clips/others, sinon les types se mélangeaient à plat.
 * Refuse de travailler si le disque de bibliothèque n'est pas monté (voir
 * YTDL_LIBRARY_ROOT).
 */
function ytdl_library_dir(int $userId, bool $audioOnly, string $category = 'music'): string {
    if (!ytdl_library_volume_mounted()) {
        throw new RuntimeException('Disque de bibliothèque non monté (' . YTDL_LIBRARY_ROOT . ').');
    }
    $dir = YTDL_LIBRARY_ROOT . '/' . $userId . '/' . ytdl_library_subdir_name($audioOnly, $category);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

/**
 * Dossier partagé : un fichier y vit dès qu'il est référencé par plus d'une
 * chose (plusieurs lignes download_history sur le même chemin, et/ou au
 * moins un partage library_shares) — voir ytdl_consolidate_duplicate_group()
 * et library-share.php. Pas de compteur de référence en base : à la
 * suppression, on recompte à la demande (COUNT(*) ... WHERE library_path =
 * ce chemin) plutôt que de risquer un compteur désynchronisé.
 */
function ytdl_shared_library_dir(bool $audioOnly, string $category = 'music'): string {
    if (!ytdl_library_volume_mounted()) {
        throw new RuntimeException('Disque de bibliothèque non monté (' . YTDL_LIBRARY_ROOT . ').');
    }
    $dir = YTDL_LIBRARY_ROOT . '/Shared/' . ytdl_library_subdir_name($audioOnly, $category);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

/**
 * Nom de base d'un fichier de bibliothèque, débarrassé d'un éventuel suffixe
 * de désambiguïsation " (2)", " (3)"... posé par ytdl_unique_library_path()
 * en cas de collision de NOM (pas de contenu). Sert à comparer deux fichiers
 * selon "la même règle de nommage" (le nom réellement écrit sur disque),
 * plutôt que de recomparer les titres bruts en base — voir dedupe-check.php.
 */
function ytdl_duplicate_base_name(string $path): string {
    $base = pathinfo($path, PATHINFO_FILENAME);
    return preg_replace('/ \(\d+\)$/', '', $base);
}

/**
 * À appeler juste après avoir accordé un accès (INSERT dans library_shares),
 * quel que soit le point d'entrée (library-share.php, library-borrow.php,
 * l'"emprunt" automatique de start.php) : si ce fichier a maintenant au
 * moins un partage actif et n'est pas encore dans Shared/, l'y déplace — la
 * règle "pas de suppression tant que quelqu'un en a besoin" (voir
 * library-delete.php) ne s'applique proprement qu'à partir de là.
 */
function ytdl_ensure_shared_after_grant(int $historyId): void {
    $stmt = ytdl_db()->prepare('SELECT library_path, audio_only, category FROM download_history WHERE id = :id');
    $stmt->execute([':id' => $historyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['library_path']) return;

    $countStmt = ytdl_db()->prepare('SELECT COUNT(*) FROM library_shares WHERE history_id = :id');
    $countStmt->execute([':id' => $historyId]);
    if ((int)$countStmt->fetchColumn() > 0) {
        ytdl_move_to_shared($row['library_path'], (bool)$row['audio_only'], $row['category']);
    }
}

/**
 * Déplace un fichier de bibliothèque vers Shared/ s'il n'y est pas déjà, et
 * répercute le changement sur TOUTES les lignes download_history qui
 * pointaient vers son ancien chemin (normalement une seule, mais rien ne
 * l'impose). Renvoie le chemin final (nouveau si déplacé, inchangé si déjà
 * dans Shared/), ou null si le déplacement a échoué (fichier disparu,
 * droits, disque...) — jamais d'exception, la dédoublonnage est une
 * optimisation d'espace, jamais une condition dont dépendrait la lecture.
 */
function ytdl_move_to_shared(string $path, bool $audioOnly, string $category = 'music'): ?string {
    $sharedPrefix = YTDL_LIBRARY_ROOT . '/Shared/' . ytdl_library_subdir_name($audioOnly, $category) . '/';
    if (str_starts_with($path, $sharedPrefix)) return $path;
    if (!is_file($path)) return null;

    $newPath = ytdl_unique_library_path(
        ytdl_shared_library_dir($audioOnly, $category),
        ytdl_duplicate_base_name($path),
        pathinfo($path, PATHINFO_EXTENSION)
    );
    if (!@rename($path, $newPath)) return null;

    ytdl_db()->prepare('UPDATE download_history SET library_path = :new WHERE library_path = :old')
        ->execute([':new' => $newPath, ':old' => $path]);
    return $newPath;
}

/**
 * Estime la qualité d'un fichier média par son débit apparent (octets par
 * seconde) — pas un vrai bitrate de codec (pas de nouvel appel ffprobe),
 * mais un proxy fiable pour comparer deux fichiers de la MÊME durée : à
 * durée égale, plus gros veut dire plus de débit, donc une meilleure
 * qualité audio/vidéo. Utilise file_size_bytes si connue, sinon retombe sur
 * filesize() du fichier réel.
 */
function ytdl_media_quality_score(array $row): float {
    $duration = (float)($row['duration_seconds'] ?? 0);
    if ($duration <= 0) return 0.0;
    $size = $row['file_size_bytes'] ?? null;
    if ($size === null && !empty($row['library_path']) && is_file($row['library_path'])) {
        $size = filesize($row['library_path']);
    }
    return $size ? ((float)$size / $duration) : 0.0;
}

/**
 * Ne garde qu'UNE copie physique pour un groupe de lignes download_history
 * désignant le même contenu (même nom de fichier de base, durée proche à
 * quelques secondes — voir dedupe-check.php) : parmi les fichiers réellement
 * présents sur disque, celui à la meilleure qualité apparente (voir
 * ytdl_media_quality_score) est conservé — déplacé vers Shared/ s'il n'y
 * est pas déjà, y compris pour en REMPLACER un moins bon qui y aurait été
 * mis avant l'introduction de ce critère de qualité. Tous les autres
 * fichiers du groupe sont supprimés, et TOUTES les lignes qui pointaient
 * vers l'un de leurs chemins (le groupe passé en argument ou d'autres déjà
 * regroupées dessus) sont reportées sur le fichier conservé.
 *
 * Idempotent et sans effet s'il ne reste qu'un seul chemin distinct dans le
 * groupe. N'échoue jamais bruyamment (fichier disque manquant, droits...).
 */
function ytdl_consolidate_duplicate_group(array $rows): void {
    $existing = array_values(array_filter(
        $rows,
        fn($r) => !empty($r['library_path']) && is_file($r['library_path'])
    ));
    if (count($existing) < 2) return;

    // Meilleur score en premier — c'est lui qu'on conserve, même s'il ne
    // s'agit pas de la ligne qui a déclenché cette consolidation.
    usort($existing, fn($a, $b) => ytdl_media_quality_score($b) <=> ytdl_media_quality_score($a));
    $best = $existing[0];
    $audioOnly = (bool)$best['audio_only'];
    $category = $best['category'] ?? 'music';
    $bestPath = $best['library_path'];

    $sharedPrefix = YTDL_LIBRARY_ROOT . '/Shared/' . ytdl_library_subdir_name($audioOnly, $category) . '/';
    if (!str_starts_with($bestPath, $sharedPrefix)) {
        $moved = ytdl_move_to_shared($bestPath, $audioOnly, $category);
        if ($moved === null) return; // échec : on abandonne plutôt que de risquer un état incohérent
        $bestPath = $moved;
    }

    $update = ytdl_db()->prepare('UPDATE download_history SET library_path = :new WHERE library_path = :old');
    foreach (array_unique(array_column($existing, 'library_path')) as $oldPath) {
        if ($oldPath === $bestPath) continue; // c'est justement le fichier qu'on garde (ou son ancien chemin, déjà mis à jour par ytdl_move_to_shared)
        if (is_file($oldPath)) @unlink($oldPath);
        $update->execute([':new' => $bestPath, ':old' => $oldPath]);
    }
}

/**
 * Nettoie un titre pour en faire un nom de fichier lisible (repris du titre
 * YouTube, "Artiste - Titre" la plupart du temps). Centralisé ici : utilisé
 * à la fois pour le nom du fichier réellement écrit sur disque et pour le
 * nom affiché au téléchargement (Content-Disposition), qui doivent rester
 * cohérents entre eux.
 */
function ytdl_sanitize_filename(string $title): string {
    $name = trim(preg_replace('/[\/\\\\:"*?<>|]+/', '_', trim($title)));
    if ($name === '') $name = 'media';
    // Marge large sous la limite usuelle de 255 octets par composant de
    // chemin, même avec des caractères multi-octets (emoji, accents).
    if (mb_strlen($name) > 150) {
        $name = mb_substr($name, 0, 150);
    }
    return $name;
}

/**
 * Retire du titre les mentions promotionnelles/techniques les plus courantes
 * ("(Official Video)", "Official 4K Video", "Remastered"/"Remaster",
 * "(Lyrics)"...) avant archivage — le titre nettoyé est celui conservé dans
 * la bibliothèque, le nom de fichier, etc.
 *
 * Distinct de ytdl_title_key() ci-dessous : celle-ci sert uniquement à
 * comparer deux titres pour la détection de doublons et conserve volontairement
 * "remastered" (une vraie version différente) — ici, à l'inverse, on l'enlève
 * du titre affiché puisque l'utilisateur ne veut pas le voir.
 */
function ytdl_clean_title(string $title): string {
    // Trois familles de mentions à retirer. Volontairement des "phrases"
    // complètes plutôt que des mots isolés : appliquées telles quelles à
    // l'intérieur d'un groupe entre parenthèses/crochets ci-dessous, jamais en
    // ne grignotant qu'un mot au milieu — un retrait partiel laisserait une
    // parenthèse ou un mot orphelin ("(Official Lyric Video)" tronqué en
    // "(Official" par exemple).
    $phrases = [
        // "Official Video", "Official Music/Lyric Video", "Official 4K Video",
        // "Official Audio"...
        '/\bofficial\b(?:\s+\d{1,2}k)?(?:\s+(?:music|lyrics?))?\s+\b(?:video|audio)\b/i',
        // "Remastered", "Remaster", avec année et/ou résolution optionnelles
        // collées ("2011 Remaster", "4K Remaster").
        '/(?:\d{4}\s*)?(?:\d{1,2}k\s*)?\bremaster(?:ed)?\b(?:\s*\d{4})?(?:\s*\d{1,2}k)?/i',
        // "Lyrics", "Lyric Video"
        '/\blyrics?\b(?:\s+video)?/i',
        // "Audio" seul (ex: "(Audio)") — uniquement utile dans la passe par
        // groupe ci-dessous, jamais en bout de titre sans parenthèses (trop
        // de vrais titres finissent légitimement par ce mot).
        '/\baudio\b/i',
        // Résolution seule entre parenthèses ("(4K)", "(8K)") — le cas déjà
        // collé à "remaster"/"official ... video" est couvert plus haut.
        '/\b\d{1,2}k\b/i',
        // "HD Audio", "HQ Audio", "Full HD Audio" — la combinaison D'ABORD,
        // sinon "HD"/"Full HD" retiré isolément (règle suivante) laisserait
        // un "Audio" orphelin non couvert par la règle générique ci-dessus
        // (celle-ci exige un tiret explicite juste avant, absent ici).
        '/\b(?:full\s*hd|hd|hq)\s+audio\b/i',
        // "Full HD" puis "HD" seul ("Full HD", "(HD)"...) — même esprit que
        // la résolution "4K"/"8K" juste au-dessus.
        '/\bfull\s*hd\b/i',
        '/\bhd\b/i',
        // "DVD Exclusive Version"
        '/\bdvd\s+exclusive\s+version\b/i',
    ];

    // 0) Parenthèses : retirées EN ENTIER, quel que soit leur contenu — sur
    // demande explicite, contrairement aux crochets ci-dessous qui restent
    // logés à l'enseigne "bruit connu uniquement" (voir étape 1).
    $clean = preg_replace('/\s*\([^()]*\)/u', '', $title);
    // Sert à savoir si CE titre précis contenait vraiment du bruit reconnu
    // (voir la boucle plus bas) : la virgule/le point ne sont retirés en
    // bout de chaîne QUE si quelque chose a effectivement été ôté par
    // ytdl_clean_title — jamais à l'aveugle sur un titre déjà propre
    // ("HUMBLE." de Kendrick Lamar doit garder son point).
    $anyPhraseRemoved = ($clean !== $title);

    // 1) Crochets : retirés EN ENTIER, mais seulement si, une fois les
    // phrases ci-dessus ôtées, il ne reste plus rien dedans. Sinon le groupe
    // est laissé intact tel quel — un mélange imprévu ("[Remastered HD]")
    // reste donc affiché plutôt que d'être charcuté.
    $clean = preg_replace_callback('/\[([^\[\]]*)\]/', function ($m) use ($phrases, &$anyPhraseRemoved) {
        $inner = preg_replace($phrases, '', $m[1]);
        // Un simple séparateur laissé entre deux mentions retirées ne compte
        // pas comme du contenu réel : sans ça, le groupe resterait affiché
        // pour un tiret orphelin.
        $inner = trim(preg_replace('/[\s\-–—|,:]+/', ' ', $inner));
        if ($inner === '') $anyPhraseRemoved = true;
        return $inner === '' ? '' : $m[0];
    }, $clean);

    // 2) Mêmes mentions sans parenthèses/crochets, uniquement en toute fin de
    // titre ("... Official Music Video", "... Remaster") — jamais au milieu,
    // où un mot pourrait faire partie du vrai titre. Chaque motif avale aussi
    // toute ponctuation/espace qui le suivrait immédiatement jusqu'à la vraie
    // fin de chaîne ([\s,.]*$ plutôt que \s*$) : "Titre HD." retire "HD."
    // d'un coup (le point appartient à la mention retirée, pas au titre),
    // sans qu'il faille une règle séparée et inconditionnelle qui risquerait
    // de couper le point final d'un titre qui n'a AUCUN bruit à nettoyer
    // ("HUMBLE." de Kendrick Lamar, par exemple).
    $trailingPhrases = [
        '/\s+\bofficial\b(?:\s+\d{1,2}k)?(?:\s+(?:music|lyrics?))?\s+\b(?:video|audio)\b[\s,.]*$/i',
        '/\s+(?:\d{4}\s*)?(?:\d{1,2}k\s*)?\bremaster(?:ed)?\b(?:\s*\d{4})?(?:\s*\d{1,2}k)?[\s,.]*$/i',
        '/\s+\blyrics?\b(?:\s+video)?[\s,.]*$/i',
        // "HD Audio", "HQ Audio", "Full HD Audio" en bout de titre — la
        // combinaison D'ABORD, même raison que dans $phrases ci-dessus (sinon
        // un "Audio" orphelin resterait, non couvert par la règle du tiret
        // juste en dessous).
        '/\s+\b(?:full\s*hd|hd|hq)\s+audio\b[\s,.]*$/i',
        // "Full HD" puis "HD" seuls en bout de titre ("... Full HD", "... HD").
        '/\s+\bfull\s*hd\b[\s,.]*$/i',
        '/\s+\bhd\b[\s,.]*$/i',
        // Résolution seule en bout de titre ("... 4K", "... 8K") — jamais
        // retirée hors combinaison jusqu'ici (seulement via "remaster"/
        // "official ... video" ci-dessus, ou entre crochets).
        '/\s+\b\d{1,2}k\b[\s,.]*$/i',
        // "Audio" seul en bout de titre — uniquement précédé d'un séparateur
        // explicite (tiret) pour rester sûr : "... - Official Audio",
        // "... - Audio". Sans le tiret, trop de vrais titres se terminent
        // légitimement par ce mot pour le retirer à l'aveugle.
        '/\s*[-–—|]\s*\baudio\b[\s,.]*$/i',
    ];

    // Deux passes qui s'alimentent l'une l'autre (mention en bout de titre,
    // puis séparateur/ponctuation laissé pendant) tournent jusqu'à ce que
    // plus rien ne bouge — pas juste une fois chacune — pour avaler les cas
    // à plusieurs niveaux : "Titre - HD -" (la 1ère passe ne voit pas "HD"
    // en bout tant que le tiret final n'est pas retiré ; la 2ᵉ ne le retire
    // qu'après), "Titre HD." (le point empêche "HD" de matcher "en bout de
    // titre" tant qu'il est là), etc. Plafonné à 5 tours par sécurité (une
    // vraie chaîne de titre n'a jamais besoin d'autant de passes).
    for ($i = 0; $i < 5; $i++) {
        $before = $clean;
        $afterPhrases = preg_replace($trailingPhrases, '', $clean);
        if ($afterPhrases !== $clean) $anyPhraseRemoved = true;
        $clean = $afterPhrases;
        // Tiret/barre pendant en bout (ou en tête) de chaîne après retrait
        // ("Titre -", "- Titre") : toujours réappliqué, comme avant ce
        // correctif — un tiret orphelin n'est jamais un choix de style
        // légitime en fin de titre.
        $clean = preg_replace('/\s*[-–—|]\s*$/u', '', $clean);
        $clean = preg_replace('/^\s*[-–—|]\s*/u', '', $clean);
        // Virgule/point pendants ("Titre, 4K" -> "Titre," une fois "4K"
        // retiré) : UNIQUEMENT si quelque chose a réellement été retiré à un
        // moment de ce nettoyage (voir $anyPhraseRemoved plus haut) — sinon
        // un vrai titre qui se termine légitimement par un point ou une
        // virgule ("HUMBLE.", "Ladies, Ladies, Ladies") le perdrait pour
        // rien, sans qu'aucune mention promotionnelle n'ait été touchée.
        if ($anyPhraseRemoved) {
            $clean = preg_replace('/(?:\s*[-–—|,.]\s*)+$/u', '', $clean);
            $clean = preg_replace('/^(?:\s*[-–—|,.]\s*)+/u', '', $clean);
        }
        $clean = trim(preg_replace('/\s{2,}/', ' ', $clean));
        if ($clean === $before) break;
    }

    // Un titre entièrement composé de bruit ne doit pas disparaître : on garde
    // alors l'original tel quel plutôt que de stocker une chaîne vide.
    return $clean !== '' ? $clean : $title;
}

/**
 * Clé de comparaison de deux titres YouTube. Le même morceau revient sous des
 * habillages différents ("Artiste - Titre (Official Video)", "ARTISTE – TITRE
 * [Clip Officiel] HD"...) : on ramène tout à une forme comparable — minuscules,
 * sans accents, sans ponctuation, mentions promotionnelles retirées.
 *
 * Volontairement conservateur sur ce qu'il retire : seules les mentions
 * purement promotionnelles sont supprimées. Tout ce qui désigne une VERSION
 * (live, remix, acoustic, remastered, cover, edit, instrumental...) est
 * conservé — ce sont de vrais morceaux distincts, les confondre empêcherait
 * de télécharger le live d'un titre déjà présent en studio.
 */
function ytdl_title_key(string $title): string {
    $key = mb_strtolower(trim($title));

    // Décomposition Unicode puis suppression des diacritiques : "Éà" -> "ea".
    // (iconv //TRANSLIT donne des résultats inexploitables sur macOS.)
    if (class_exists('Normalizer')) {
        $key = Normalizer::normalize($key, Normalizer::FORM_D) ?: $key;
        $key = preg_replace('/\p{Mn}+/u', '', $key);
    }

    // Mentions promotionnelles, avec ou sans parenthèses/crochets autour.
    $noise = '(?:official\s+(?:music\s+)?(?:video|audio|visualizer)|official|clip\s+officiel|audio\s+officiel|'
        . 'lyrics?\s+video|lyrics?|paroles|music\s+video|video\s+officielle?|hd|hq|full\s+hd|4k|8k|mv)';
    $key = preg_replace('/[\(\[\{]\s*' . $noise . '\s*[\)\]\}]/iu', ' ', $key);
    $key = preg_replace('/\s[-–—|]\s*' . $noise . '\s*$/iu', ' ', $key);

    // Toute ponctuation (y compris les tirets typographiques et les guillemets
    // exotiques) devient un séparateur : "artiste – titre" == "artiste - titre".
    $key = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $key);
    $key = trim(preg_replace('/\s+/u', ' ', $key));

    // Mentions restées en fin de titre sans parenthèses ni séparateur
    // ("... Yellow Official Music Video HD"), retirées une par une puisqu'elles
    // s'enchaînent souvent, et uniquement en fin de chaîne.
    //
    // Les mots isolés trop courants dans de vrais titres (video, audio, music,
    // clip, lyrics) ne sont PAS retirés seuls : "Radio Video" deviendrait
    // "Radio" et se confondrait avec un autre morceau. Ils ne comptent comme
    // bruit qu'au sein d'une locution complète.
    $trailing = '/\s(?:official\s+(?:music\s+)?(?:video|audio|visualizer)|music\s+video|lyrics?\s+video|'
        . 'video\s+officielle?|clip\s+officiel|audio\s+officiel|official|officielle?|hd|hq|4k|8k|mv)$/u';
    while (preg_match($trailing, $key)) {
        $key = preg_replace($trailing, '', $key);
    }

    return $key;
}

/**
 * Cherche, parmi les fichiers de ce compte réellement présents sur le serveur,
 * un morceau portant le même titre — dans le même mode (un MP3 déjà là ne doit
 * pas bloquer le téléchargement du clip, et inversement).
 *
 * Le contrôle porte sur les fichiers existants, pas sur l'historique : une
 * ligne dont la copie a été purgée (30 jours) ou supprimée à la main doit
 * pouvoir être retéléchargée.
 *
 * @return array|null La ligne d'historique correspondante, ou null.
 */
function ytdl_find_library_duplicate(?int $userId, string $title, bool $audioOnly): ?array {
    if (!$userId) return null;

    // ytdl_clean_title() D'ABORD, puis ytdl_title_key() : le titre BRUT de
    // deux vidéos peut différer ("Hungry Eyes" vs "Hungry Eyes (2011
    // Remaster)") sans que ytdl_title_key seul les distingue comme doublons
    // (volontairement conservateur — voir sa docblock). Mais ytdl_clean_title
    // retire ensuite TOUT contenu entre parenthèses avant stockage : les deux
    // finissaient donc affichés sous le même titre en bibliothèque sans avoir
    // jamais été reconnus comme doublons au moment du téléchargement. On
    // compare donc ici sur le titre tel qu'il sera réellement stocké/affiché,
    // pas sur le brut.
    $key = ytdl_title_key(ytdl_clean_title($title));
    if ($key === '') return null;

    $stmt = ytdl_db()->prepare(
        'SELECT id, title, library_path, downloaded_at FROM download_history
         WHERE user_id = :user_id AND audio_only = :audio_only
           AND library_path IS NOT NULL AND library_path <> ""
         ORDER BY id DESC'
    );
    $stmt->execute([':user_id' => $userId, ':audio_only' => $audioOnly ? 1 : 0]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (ytdl_title_key(ytdl_clean_title((string)$row['title'])) !== $key) continue;
        if (!is_file($row['library_path'])) continue; // fichier purgé ou effacé
        return $row;
    }

    return null;
}

/**
 * Chemin de bibliothèque unique pour ce titre dans le dossier $dir donné
 * (dossier personnel d'un compte OU dossier Shared/ — voir ytdl_library_dir
 * et ytdl_shared_library_dir) : "Titre.mp4", puis "Titre (2).mp4", etc. en
 * cas de doublon — deux morceaux au même nom exact ne doivent jamais
 * s'écraser l'un l'autre sur disque.
 */
function ytdl_unique_library_path(string $dir, string $baseName, string $extension, ?string $ignorePath = null): string {
    $candidate = $dir . '/' . $baseName . '.' . $extension;
    $i = 2;
    while (is_file($candidate) && $candidate !== $ignorePath) {
        $candidate = $dir . '/' . $baseName . ' (' . $i . ').' . $extension;
        $i++;
    }
    return $candidate;
}

// Quota de stockage bibliothèque par compte — valeur PAR DÉFAUT, utilisée
// quand la colonne users.quota_bytes est NULL (voir ytdl_user_quota_bytes).
// Non bloquant pour le téléchargement lui-même (qui part toujours vers le
// navigateur) : seule la copie serveur conservée pour la relecture en page
// est concernée — voir file.php, qui saute la copie plutôt que d'échouer le
// téléchargement.
const YTDL_LIBRARY_QUOTA_BYTES = 5 * 1024 * 1024 * 1024; // 5 Go

/**
 * Quota effectif d'un compte : sa propre valeur (users.quota_bytes) si elle a
 * été réglée individuellement (voir admin.php), sinon la valeur par défaut
 * commune à tous. NULL en base, donc, ne veut jamais dire "illimité" — juste
 * "pas de réglage particulier pour ce compte".
 */
function ytdl_user_quota_bytes(int $userId): int {
    $stmt = ytdl_db()->prepare('SELECT quota_bytes FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $value = $stmt->fetchColumn();
    return ($value !== false && $value !== null) ? (int)$value : YTDL_LIBRARY_QUOTA_BYTES;
}

/**
 * Espace disque utilisé par la bibliothèque d'un compte, ventilé
 * audio/vidéo/others. Chaque ligne compte pour son quota même si le fichier
 * vit dans Shared/ et sert aussi à un autre compte (le quota reflète
 * "combien j'ai dans ma bibliothèque", pas l'octet réellement consommé sur
 * le disque partagé). Un enregistrement category='other' compte dans
 * other_bytes qu'il soit audio_only ou pas — "Others" ne se subdivise pas
 * en audio/vidéo (voir ytdl_library_subdir_name). Utilise file_size_bytes
 * quand elle est connue (persistée à l'archivage, voir ytdl_archive_job)
 * plutôt que de rappeler filesize() sur chaque fichier à chaque affichage ;
 * repli sur filesize() pour les lignes antérieures à cette colonne, jamais
 * sur une taille supposée sans vérifier que le fichier existe encore.
 */
function ytdl_library_usage(int $userId): array {
    $stmt = ytdl_db()->prepare(
        'SELECT library_path, audio_only, category, file_size_bytes FROM download_history WHERE user_id = :user_id AND library_path IS NOT NULL'
    );
    $stmt->execute([':user_id' => $userId]);

    $audioBytes = 0;
    $videoBytes = 0;
    $otherBytes = 0;
    $audioCount = 0;
    $videoCount = 0;
    $otherCount = 0;
    foreach ($stmt->fetchAll() as $row) {
        if (!is_file($row['library_path'])) continue;
        $size = $row['file_size_bytes'] !== null ? (int)$row['file_size_bytes'] : filesize($row['library_path']);
        if (($row['category'] ?? 'music') === 'other') {
            $otherBytes += $size;
            $otherCount++;
        } elseif ((int)$row['audio_only'] === 1) {
            $audioBytes += $size;
            $audioCount++;
        } else {
            $videoBytes += $size;
            $videoCount++;
        }
    }

    return [
        'audio_bytes' => $audioBytes,
        'video_bytes' => $videoBytes,
        'other_bytes' => $otherBytes,
        'total_bytes' => $audioBytes + $videoBytes + $otherBytes,
        'quota_bytes' => ytdl_user_quota_bytes($userId),
        // Décompte par type : sert au détail affiché dans la modale de
        // l'espace de stockage (nombre de fichiers + taille moyenne).
        'audio_count' => $audioCount,
        'video_count' => $videoCount,
        'other_count' => $otherCount,
    ];
}

/**
 * Copie le média d'un job terminé dans la bibliothèque du compte et enregistre
 * la ligne d'historique correspondante. Extrait de file.php pour être appelé
 * aussi bien après un streaming vers l'appareil (téléchargement classique)
 * que sans streaming du tout (mode "bibliothèque uniquement", voir
 * library-save.php) — les deux chemins doivent produire exactement le même
 * résultat côté serveur.
 *
 * deviceId n'est PAS requis pour archiver : il ne sert qu'à la déduplication
 * "déjà téléchargé sur cet appareil" (voir start.php), une pure optimisation.
 */
/**
 * Renvoie l'id de la ligne download_history créée, ou null si l'archivage a
 * été sauté (pas d'URL de job) — utilisé par l'import de playlist externe
 * pour rattacher immédiatement le morceau à la playlist de destination sans
 * avoir à le retrouver par une recherche a posteriori.
 */
function ytdl_archive_job(string $jobDir, string $filePath, string $extension, int $fileSize): ?int {
    $rawTitle   = trim((string)@file_get_contents($jobDir . '/title.txt'));
    $title      = $rawTitle !== '' ? ytdl_clean_title($rawTitle) : 'media';
    $historyUrl = trim((string)@file_get_contents($jobDir . '/url.txt'));
    if ($historyUrl === '') return null;

    $deviceId      = trim((string)@file_get_contents($jobDir . '/device_id.txt'));
    $audioOnlyFlag = trim((string)@file_get_contents($jobDir . '/audio_only.txt')) === '1' ? 1 : 0;
    $userIdRaw     = trim((string)@file_get_contents($jobDir . '/user_id.txt'));
    $userId        = $userIdRaw !== '' ? (int)$userIdRaw : null;
    $thumbnail     = trim((string)@file_get_contents($jobDir . '/thumbnail.txt'));
    $uploader      = trim((string)@file_get_contents($jobDir . '/uploader.txt'));
    // Catégorie "hors musique" (voir capture.php, bouton "Hors musique") :
    // écrite par start.php, absente pour tout téléchargement classique — la
    // seule valeur reconnue autre que le défaut est 'other'.
    $category      = trim((string)@file_get_contents($jobDir . '/category.txt')) === 'other' ? 'other' : 'music';

    // Bibliothèque de lecture (mini-lecteur audio / lecteur vidéo en page) :
    // uniquement pour les comptes identifiés et sous LEUR quota (par défaut,
    // ou individuel — voir ytdl_user_quota_bytes) — une copie du fichier
    // (mp3 ou mp4 selon $extension) est conservée hors du dossier de job
    // (effacé par l'appelant juste après), sans purge automatique — seule
    // une suppression manuelle (voir library-delete.php) ou l'atteinte du
    // quota du compte y met fin. Le quota ne bloque jamais le téléchargement
    // lui-même : au pire, ce fichier-ci ne sera simplement pas rejouable
    // depuis le serveur.
    $libraryPath = null;
    if ($userId) {
        $usage = ytdl_library_usage($userId);
        if ($usage['total_bytes'] + $fileSize <= $usage['quota_bytes']) {
            // Nom lisible ("Artiste - Titre.mp4") plutôt que l'id de job :
            // impossible à identifier à l'œil sur le disque sinon.
            $libraryFile = ytdl_unique_library_path(
                ytdl_library_dir($userId, (bool)$audioOnlyFlag, $category),
                ytdl_sanitize_filename($title),
                $extension
            );
            if (@copy($filePath, $libraryFile)) {
                $libraryPath = $libraryFile;
            }
        }
    }

    // Mesurée sur le fichier de bibliothèque s'il existe (copie durable),
    // sinon sur le fichier de job éphémère — dans les deux cas le fichier
    // réellement obtenu, pas une estimation tirée des métadonnées YouTube.
    $durationSeconds = ytdl_probe_duration_seconds($libraryPath ?? $filePath);
    // Même remarque pour le niveau sonore : mesuré sur le fichier obtenu, une
    // fois pour toutes (voir ytdl_measure_loudness). Un échec n'empêche jamais
    // l'enregistrement — la lecture se fera simplement sans correction.
    $loudness = ytdl_measure_loudness($libraryPath ?? $filePath);

    ytdl_db()->prepare(
        'INSERT INTO download_history (device_id, user_id, url, title, audio_only, category, thumbnail, library_path, uploader, duration_seconds, loudness_lufs, peak_dbfs, file_size_bytes)
         VALUES (:device_id, :user_id, :url, :title, :audio_only, :category, :thumbnail, :library_path, :uploader, :duration_seconds, :loudness_lufs, :peak_dbfs, :file_size_bytes)'
    )->execute([
        ':device_id' => $deviceId,
        ':user_id' => $userId,
        ':url' => $historyUrl,
        ':title' => $title,
        ':audio_only' => $audioOnlyFlag,
        ':category' => $category,
        ':thumbnail' => $thumbnail !== '' ? $thumbnail : null,
        ':library_path' => $libraryPath,
        // "NA" est ce que yt-dlp imprime quand le champ est absent.
        ':uploader' => ($uploader !== '' && $uploader !== 'NA') ? $uploader : null,
        ':duration_seconds' => $durationSeconds,
        ':loudness_lufs' => $loudness['lufs'] ?? null,
        ':peak_dbfs' => $loudness['peak'] ?? null,
        // Connue dès l'appel (taille du fichier de job, identique à la copie
        // de bibliothèque puisque c'est un copy() strict) — persistée pour
        // servir de clé de dédoublonnage (voir dedupe-check.php) sans avoir
        // à rappeler filesize() sur chaque fichier à chaque comparaison.
        ':file_size_bytes' => $fileSize,
    ]);

    $newHistoryId = (int)ytdl_db()->lastInsertId();

    // Dédoublonnage physique : comparaison avec TOUS les comptes, jamais
    // avant/pendant le téléchargement — seulement une fois ce fichier-ci
    // vraiment archivé. Lancé en arrière-plan (même détachement shell que le
    // téléchargement yt-dlp lui-même) pour ne jamais retarder la réponse ni
    // le téléchargement suivant.
    if ($libraryPath !== null) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/dedupe-check.php') . ' ' . $newHistoryId;
        exec($cmd . ' > /dev/null 2>&1 &');
    }

    return $newHistoryId;
}

function ytdl_jobs_root(): string {
    $dir = YTDL_DATA_DIR . '/jobs';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

function ytdl_job_dir(string $jobId): string {
    if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
        ytdl_json_error('Identifiant de tâche invalide.');
    }
    return ytdl_jobs_root() . '/' . $jobId;
}

/**
 * Mode maintenance : piloté par la simple présence d'un fichier, hors du
 * dossier servi par Apache. Son contenu, s'il n'est pas vide, remplace le
 * message affiché. Bascule : maintenance.sh on|off.
 */
function ytdl_maintenance_file(): string {
    return YTDL_DATA_DIR . '/maintenance.txt';
}

function ytdl_maintenance_active(): bool {
    return is_file(ytdl_maintenance_file());
}

function ytdl_maintenance_message(): string {
    $message = trim((string)@file_get_contents(ytdl_maintenance_file()));
    return $message !== ''
        ? $message
        : 'Le site est en maintenance, il sera de retour très vite.';
}

/**
 * Coupe la requête si la maintenance est active. L'admin n'est jamais bloqué :
 * il doit pouvoir tester le site pendant l'interruption.
 */
function ytdl_maintenance_gate(?array $user = null, bool $json = false): void {
    if (!ytdl_maintenance_active()) return;
    if ($user !== null && (int)($user['is_admin'] ?? 0) === 1) return;

    // 503 + Retry-After : le code correct pour une indisponibilité temporaire,
    // qui évite aussi que les moteurs désindexent le site.
    http_response_code(503);
    header('Retry-After: 3600');

    if ($json) {
        header('Content-Type: application/json');
        echo json_encode(['error' => ytdl_maintenance_message(), 'maintenance' => true]);
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    $message = htmlspecialchars(ytdl_maintenance_message(), ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ytdl — Maintenance</title>
<meta name="theme-color" content="#171a21">
<style>
  body {
    margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
    background: radial-gradient(circle at 30% 20%, #1a1e28 0%, #0f1115 60%);
    color: #e6e6e6; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Inter, sans-serif;
    padding: 20px;
  }
  .card {
    width: 100%; max-width: 460px; background: #171a21; border: 1px solid #2a2f3a;
    border-radius: 16px; padding: 36px; box-shadow: 0 20px 60px rgba(0,0,0,0.4); text-align: center;
  }
  .icon { font-size: 2.6rem; line-height: 1; margin-bottom: 14px; }
  h1 {
    margin: 0 0 10px; font-size: 1.5rem;
    background: linear-gradient(100deg, #5cc8ff, #8b7bff);
    -webkit-background-clip: text; background-clip: text; color: transparent;
  }
  p { margin: 0; color: #8b93a3; font-size: 0.92rem; line-height: 1.5; }
</style>
</head>
<body>
  <div class="card">
    <div class="icon">🔧</div>
    <h1>ytdl</h1>
    <p>{$message}</p>
  </div>
</body>
</html>
HTML;
    exit;
}

/** Efface le contenu d'un dossier de job puis le dossier lui-même. */
function ytdl_remove_job_dir(string $dir): void {
    // GLOB_BRACE pour inclure les fichiers cachés ; "." et ".." remontent aussi
    // dans la liste mais sont écartés par is_file().
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $entry) {
        if (is_file($entry)) @unlink($entry);
    }
    @rmdir($dir);
}

/**
 * Supprime les jobs abandonnés (onglet fermé, téléchargement jamais récupéré,
 * yt-dlp tué en cours de route). file.php nettoie les jobs menés à leur terme,
 * mais rien ne ramassait les autres : ils s'accumulaient indéfiniment dans
 * /tmp, à plusieurs Go pièce.
 */
function ytdl_purge_stale_jobs(int $maxAgeHours = 6): void {
    $cutoff = time() - $maxAgeHours * 3600;

    foreach (glob(ytdl_jobs_root() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (!preg_match('#/[a-f0-9]{32}$#', $dir)) continue;

        // On date le job sur son fichier le plus récent, jamais sur le dossier :
        // un téléchargement en cours réécrit log.txt en continu, alors que le
        // mtime du dossier ne bouge plus une fois les fichiers créés. Sans ça,
        // un gros téléchargement de plus de $maxAgeHours se ferait purger
        // sous ses propres pieds.
        $latest = (int)@filemtime($dir);
        foreach (glob($dir . '/*') ?: [] as $file) {
            $mtime = (int)@filemtime($file);
            if ($mtime > $latest) $latest = $mtime;
        }

        if ($latest < $cutoff) {
            ytdl_remove_job_dir($dir);
        }
    }
}
