<?php
/**
 * Genres musicaux : liste fermée, correspondance avec les genres des services
 * gratuits (Deezer, iTunes), classement automatique des titres et lancement du
 * processus d'arrière-plan (genre-worker.php). Un titre qu'aucun service ne
 * reconnaît va dans « Inclassable », que l'on déplace ensuite à la main.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

const YTDL_GENRE_UNCLASSIFIED = 'Inclassable';

/** Genres proposés, dans l'ordre d'affichage. */
function ytdl_genre_list(): array {
    return ['Pop', 'Rock', 'Rap / Hip-Hop', 'R&B / Soul', 'Électro / Dance', 'Jazz', 'Classique', 'Folk / Acoustique',
        'Country', 'Reggae', 'Metal', 'Blues', 'Latino', 'Musique du monde', 'Bande originale',
        'New Age / Méditation', 'Chanson française', 'Alternatif / Indé', YTDL_GENRE_UNCLASSIFIED];
}

function ytdl_genre_is_valid(string $genre): bool {
    return in_array($genre, ytdl_genre_list(), true);
}

/** Genre d'un service (Deezer, iTunes…) → genre de la liste, ou null. */
function ytdl_genre_normalize(string $raw): ?string {
    $g = mb_strtolower(trim($raw));
    if ($g === '' || in_array($g, ['inconnu', 'unknown', 'divers', 'various'], true)) return null;
    $rules = [
        '/hip.?hop|\brap\b/'                                                  => 'Rap / Hip-Hop',
        '/r&b|\brnb\b|soul|funk/'                                             => 'R&B / Soul',
        '/electro|électro|dance|house|techno|trance|edm|dubstep|drum/'        => 'Électro / Dance',
        '/metal/'                                                             => 'Metal',
        '/alternat|indie|indé/'                                               => 'Alternatif / Indé',
        '/punk|rock/'                                                         => 'Rock',
        '/jazz/'                                                              => 'Jazz',
        '/classi|opéra|opera|symph|baroque|orchestr/'                         => 'Classique',
        '/country|bluegrass|americana/'                                       => 'Country',
        '/reggae|\bska\b|dancehall/'                                          => 'Reggae',
        '/blues/'                                                             => 'Blues',
        '/latin|salsa|bachata|reggaeton|flamenco|bossa|brazil|brésil|samba/'  => 'Latino',
        '/soundtrack|bande originale|\bscore\b|films?\b|\bost\b|musical|jeux/' => 'Bande originale',
        '/new age|easy listening|ambient|méditation|meditation|relax|healing|spiritual|spirituel|zen|musique d.ambiance/' => 'New Age / Méditation',
        '/folk|singer|songwriter|acoust/'                                     => 'Folk / Acoustique',
        '/chanson|french|français|francais|variété française/'                => 'Chanson française',
        '/world|monde|afro|africa|afric|asia|asiat|j-pop|k-pop|celt|indian|indien|bollywood|arab|oriental|ethni|tribal|native|amérind/' => 'Musique du monde',
        '/\bpop\b|variété|variet/'                                            => 'Pop',
    ];
    foreach ($rules as $re => $genre) {
        if (preg_match($re . 'u', $g)) return $genre;
    }
    return null;
}

/** À défaut de service : mots-clés du titre et de la chaîne (musiques de méditation, bandes originales…). */
function ytdl_genre_from_keywords(string $title, string $uploader): ?string {
    $t = mb_strtolower($title . ' ' . $uploader);
    if (preg_match('/\b(432|528|444|963|741|852)\s?hz|medita|méditation|healing|shaman|chaman|chakra|mantra|sleep|relax|reiki|binaural|ambient|zen\b|spiritual|sacred/u', $t)) return 'New Age / Méditation';
    if (preg_match('/soundtrack|bande originale|\bost\b|original score|motion picture/u', $t)) return 'Bande originale';
    if (preg_match('/symphon|concerto|sonata|philharmon|orchestra|requiem|cantata/u', $t)) return 'Classique';
    if (preg_match('/\blofi\b|lo-fi|\bhouse\b|\btechno\b|\bremix\b|\bedm\b/u', $t)) return 'Électro / Dance';
    return null;
}

/** Artiste et titre d'une ligne d'historique (« Artiste - Titre », sinon la chaîne). */
function ytdl_genre_parse(string $title, string $uploader, ?bool &$hasArtist = null): array {
    $title = trim($title);
    $hasArtist = (bool)preg_match('/^(.+?)\s+[-–—]\s+(.+)$/u', $title);
    if (preg_match('/^(.+?)\s+[-–—]\s+(.+)$/u', $title, $m)) { $artist = trim($m[1]); $name = trim($m[2]); }
    else { $artist = trim(preg_replace('/\s*(-\s*Topic|VEVO|Official|Records?|Music)\s*$/iu', '', $uploader)); $name = $title; }
    // « Titre | Album | Label » : seul le premier morceau est le titre.
    $name = trim(explode(' | ', $name)[0]);
    $name = trim(preg_replace('/\s*[\(\[][^\)\]]*[\)\]]/u', '', $name));
    $name = trim(preg_replace('/\s+(feat\.?|ft\.?)\s+.*$/iu', '', $name));
    return [$artist, $name];
}

function ytdl_genre_key(string $s): string {
    $s = mb_strtolower($s);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    return trim($s);
}

/** Les deux textes (normalisés) se contiennent-ils, ou partagent-ils l'essentiel de leurs mots ? */
function ytdl_genre_similar(string $a, string $b): bool {
    $a = ytdl_genre_key($a); $b = ytdl_genre_key($b);
    if ($a === '' || $b === '') return false;
    if ($a === $b || str_contains($a, $b) || str_contains($b, $a)) return true;
    $wa = array_filter(explode(' ', $a), fn($w) => mb_strlen($w) > 1); $wb = array_filter(explode(' ', $b), fn($w) => mb_strlen($w) > 1);
    if (!$wa || !$wb) return false;
    $common = count(array_intersect($wa, $wb));
    return $common / min(count($wa), count($wb)) >= 0.7;
}

/** Appel JSON d'un service public (HTTPS, délai court), ou null. */
function ytdl_genre_http(string $url): ?array {
    $ctx = stream_context_create(['http' => ['timeout' => 12, 'header' => "User-Agent: ytdl-genres/1.0\r\nAccept: application/json\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    $data = $raw !== false ? json_decode($raw, true) : null;
    return is_array($data) ? $data : null;
}

/** Plusieurs genres pour un même album : le plus précis l'emporte (« Pop » est le plus générique). */
function ytdl_genre_most_specific(array $genres): string {
    $order = ['Rap / Hip-Hop', 'Metal', 'Jazz', 'Classique', 'Reggae', 'Country', 'Blues', 'Latino', 'Bande originale',
        'Musique du monde', 'New Age / Méditation', 'Électro / Dance', 'R&B / Soul', 'Rock', 'Alternatif / Indé',
        'Folk / Acoustique', 'Chanson française', 'Pop'];
    foreach ($order as $g) if (in_array($g, $genres, true)) return $g;
    return $genres[0];
}

/** Deezer (sans clé) : meilleur résultat de recherche, puis genre de son album. */
function ytdl_genre_deezer(string $artist, string $title): ?string {
    $res = ytdl_genre_http('https://api.deezer.com/search?limit=5&q=' . rawurlencode(trim($artist . ' ' . $title)));
    foreach (($res['data'] ?? []) as $t) {
        if (!ytdl_genre_similar($title, (string)($t['title'] ?? ''))) continue;
        if ($artist !== '' && !ytdl_genre_similar($artist, (string)($t['artist']['name'] ?? ''))) continue;
        $albumId = (int)($t['album']['id'] ?? 0);
        if (!$albumId) continue;
        usleep(150000);
        $album = ytdl_genre_http('https://api.deezer.com/album/' . $albumId);
        $found = [];
        foreach (($album['genres']['data'] ?? []) as $g) {
            $norm = ytdl_genre_normalize((string)($g['name'] ?? ''));
            if ($norm) $found[] = $norm;
        }
        if ($found) return ytdl_genre_most_specific($found);
    }
    return null;
}

/** iTunes (sans clé, environ 20 appels par minute) : le genre est dans la réponse. */
function ytdl_genre_itunes(string $artist, string $title): ?string {
    $res = ytdl_genre_http('https://itunes.apple.com/search?media=music&entity=song&limit=5&country=FR&term=' . rawurlencode(trim($artist . ' ' . $title)));
    foreach (($res['results'] ?? []) as $t) {
        if (!ytdl_genre_similar($title, (string)($t['trackName'] ?? ''))) continue;
        if ($artist !== '' && !ytdl_genre_similar($artist, (string)($t['artistName'] ?? ''))) continue;
        $norm = ytdl_genre_normalize((string)($t['primaryGenreName'] ?? ''));
        if ($norm) return $norm;
    }
    return null;
}

/** Classe un titre : [genre, source]. Toujours un résultat (« Inclassable » à défaut). */
function ytdl_genre_classify(string $title, string $uploader): array {
    [$artist, $name] = ytdl_genre_parse($title, $uploader, $hasArtist);
    // Titre sans « Artiste - » (musiques de méditation, chaînes thématiques) :
    // les services ne le reconnaissent presque jamais, les mots-clés d'abord.
    if (!$hasArtist && ($g = ytdl_genre_from_keywords($title, $uploader))) return [$g, 'mots-clés'];
    if ($name !== '') {
        $g = ytdl_genre_deezer($artist, $name);
        if ($g) return [$g, 'deezer'];
        // iTunes limite à ~20 appels par minute : on n'attend que le temps qui manque.
        static $lastItunes = 0.0;
        $wait = 3.2 - (microtime(true) - $lastItunes);
        if ($wait > 0) usleep((int)($wait * 1000000));
        $g = ytdl_genre_itunes($artist, $name);
        $lastItunes = microtime(true);
        if ($g) return [$g, 'itunes'];
    }
    $g = ytdl_genre_from_keywords($title, $uploader);
    if ($g) return [$g, 'mots-clés'];
    return [YTDL_GENRE_UNCLASSIFIED, 'aucun'];
}

/** Il reste des titres à classer ? */
function ytdl_genre_pending_count(): int {
    return (int)ytdl_db()->query("SELECT COUNT(*) FROM download_history WHERE library_path IS NOT NULL AND library_path <> '' AND genre_checked_at IS NULL AND COALESCE(genre_source, '') <> 'manuel'")->fetchColumn();
}

/** Lance le processus de classement s'il reste des titres (un seul tourne à la fois : verrou dans le worker). */
function ytdl_genre_spawn(): void {
    $stamp = sys_get_temp_dir() . '/ytdl-genre-spawn.stamp';
    // Pas plus d'un lancement par minute.
    if (is_file($stamp) && time() - filemtime($stamp) < 60) return;
    if (ytdl_genre_pending_count() === 0) return;
    @touch($stamp);
    $php = ytdl_which('php');
    if (!$php) return;
    $log = sys_get_temp_dir() . '/ytdl-genre.log';
    exec(sprintf('nohup %s %s >> %s 2>&1 &', escapeshellarg($php), escapeshellarg(__DIR__ . '/genre-worker.php'), escapeshellarg($log)));
}
