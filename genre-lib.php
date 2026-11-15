<?php
/**
 * Genres musicaux. Le genre d'un titre n'est JAMAIS décidé par ce code : il
 * vient d'une base musicale ouverte et mondiale, celle de l'artiste, avec le
 * vocabulaire de la base :
 *   1. MusicBrainz (base libre et communautaire, genres votés par les contributeurs) ;
 *   2. à défaut, Wikidata (propriété « genre » de l'artiste).
 * Un artiste que ni l'une ni l'autre ne connaît va dans « Inclassable » ; chacun
 * déplace ensuite le titre à la main (le choix manuel n'est jamais écrasé).
 * Seul le nom de l'artiste est envoyé à ces services.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';

const YTDL_GENRE_UNCLASSIFIED = 'Inclassable';
const YTDL_GENRE_UA = 'ytdl-genres/1.0 (https://github.com/gregorybrice-coder/ytdl-community)';

/** Nom de genre saisi à la main : texte court, sans caractère de contrôle. */
function ytdl_genre_clean(string $genre): ?string {
    $g = trim(preg_replace('/\s+/u', ' ', $genre));
    if ($g === '' || mb_strlen($g) > 40 || preg_match('/[\x00-\x1f<>]/u', $g)) return null;
    return mb_strtoupper(mb_substr($g, 0, 1)) . mb_substr($g, 1);
}

/** Artiste et titre d'une ligne d'historique (« Artiste - Titre », sinon la chaîne). */
function ytdl_genre_parse(string $title, string $uploader, ?bool &$hasArtist = null): array {
    $title = trim($title);
    $hasArtist = (bool)preg_match('/^(.+?)\s+[-–—]\s+(.+)$/u', $title);
    if (preg_match('/^(.+?)\s+[-–—]\s+(.+)$/u', $title, $m)) { $artist = trim($m[1]); $name = trim($m[2]); }
    else {
        $artist = trim(preg_replace('/\s*(-\s*Topic|VEVO|Official|Records?|Music)\s*$/iu', '', $uploader));
        $name = $title;
    }
    // Plusieurs artistes (« A, B », « A & B », « A feat. B ») : le premier.
    $artist = trim(preg_split('/\s*(?:,|&|\bfeat\.?|\bft\.?|\bx\b|\bwith\b)\s*/iu', $artist)[0]);
    return [$artist, trim(explode(' | ', $name)[0])];
}

function ytdl_genre_key(string $s): string {
    return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s)));
}

/** Les deux noms (normalisés) sont-ils identiques, à un mot d'article ou de décor près ? */
function ytdl_genre_same_name(string $a, string $b): bool {
    $a = ytdl_genre_key($a); $b = ytdl_genre_key($b);
    $strip = fn($s) => trim(preg_replace('/^(the|le|la|les|l)\s+/u', '', $s));
    return $a !== '' && $strip($a) === $strip($b);
}

/** Appel JSON d'un service ouvert ; réessaie si le service est occupé. Lève RuntimeException s'il reste indisponible. */
function ytdl_genre_http(string $url, float $minGap = 1.1): array {
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true, 'header' => 'User-Agent: ' . YTDL_GENRE_UA . "\r\nAccept: application/json\r\n"]]);
    // Politesse : MusicBrainz demande au plus une requête par seconde. L'heure de
    // la dernière requête est partagée entre TOUS les processus (classement en
    // arrière-plan, proposition à l'ajout…) par un verrou sur un fichier.
    $lock = fopen(sys_get_temp_dir() . '/ytdl-genre-http.lock', 'c+');
    foreach ([0, 5, 15, 40] as $wait) {
        if ($wait) sleep($wait);
        flock($lock, LOCK_EX);
        rewind($lock);
        $last = (float)stream_get_contents($lock);
        $gap = $minGap - (microtime(true) - $last);
        if ($gap > 0) usleep((int)($gap * 1000000));
        $raw = @file_get_contents($url, false, $ctx);
        ftruncate($lock, 0); rewind($lock); fwrite($lock, (string)microtime(true)); fflush($lock);
        flock($lock, LOCK_UN);
        $code = 0;
        foreach (($http_response_header ?? []) as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
        if ($code === 200 && $raw !== false && is_array($data = json_decode($raw, true))) return $data;
        if ($code === 404) return [];
        if ($code && $code !== 503 && $code !== 429 && $code < 500) break; // erreur définitive
    }
    throw new RuntimeException('service de genres indisponible');
}

/** MusicBrainz : le genre le plus voté de l'artiste, ou null s'il est inconnu. */
function ytdl_genre_musicbrainz(string $artist): ?array {
    $q = 'artist:"' . addcslashes($artist, '"\\') . '"';
    $res = ytdl_genre_http('https://musicbrainz.org/ws/2/artist/?fmt=json&limit=3&query=' . rawurlencode($q));
    foreach (($res['artists'] ?? []) as $a) {
        if ((int)($a['score'] ?? 0) < 90 || !ytdl_genre_same_name($artist, (string)($a['name'] ?? ''))) continue;
        $detail = ytdl_genre_http('https://musicbrainz.org/ws/2/artist/' . rawurlencode((string)$a['id']) . '?fmt=json&inc=genres');
        $genres = $detail['genres'] ?? [];
        usort($genres, fn($x, $y) => ($y['count'] ?? 0) <=> ($x['count'] ?? 0));
        if ($genres) return ['genre' => (string)$genres[0]['name'], 'mbid' => (string)$a['id']];
        // Aucun genre voté : le tag le plus utilisé de la recherche, s'il y en a un.
        $tags = $a['tags'] ?? [];
        usort($tags, fn($x, $y) => ($y['count'] ?? 0) <=> ($x['count'] ?? 0));
        if ($tags) return ['genre' => (string)$tags[0]['name'], 'mbid' => (string)$a['id']];
    }
    return null;
}

/** Wikidata : premier genre (propriété P136) d'un artiste dont la description est celle d'un musicien. */
function ytdl_genre_wikidata(string $artist): ?array {
    $res = ytdl_genre_http('https://www.wikidata.org/w/api.php?action=wbsearchentities&format=json&language=en&type=item&limit=5&search=' . rawurlencode($artist), 0.4);
    foreach (($res['search'] ?? []) as $e) {
        if (!ytdl_genre_same_name($artist, (string)($e['label'] ?? ''))) continue;
        if (!preg_match('/singer|rapper|band|group|musician|duo|composer|songwriter|artist|disc jockey|\bDJ\b|producer|vocalist/i', (string)($e['description'] ?? ''))) continue;
        $ent = ytdl_genre_http('https://www.wikidata.org/w/api.php?action=wbgetentities&format=json&props=claims&ids=' . rawurlencode($e['id']), 0.4);
        $claims = $ent['entities'][$e['id']]['claims']['P136'] ?? [];
        usort($claims, fn($x, $y) => (($y['rank'] ?? '') === 'preferred') <=> (($x['rank'] ?? '') === 'preferred'));
        $ids = [];
        foreach ($claims as $c) if (isset($c['mainsnak']['datavalue']['value']['id'])) $ids[] = $c['mainsnak']['datavalue']['value']['id'];
        if (!$ids) continue;
        $labels = ytdl_genre_http('https://www.wikidata.org/w/api.php?action=wbgetentities&format=json&props=labels&languages=en&ids=' . rawurlencode(implode('|', array_slice($ids, 0, 5))), 0.4);
        foreach ($ids as $id) {
            $label = $labels['entities'][$id]['labels']['en']['value'] ?? null;
            if ($label) return ['genre' => $label, 'mbid' => null];
        }
    }
    return null;
}

/**
 * Genre d'un artiste, avec cache (table artist_genres) : [genre, source].
 * Lève RuntimeException si les bases sont momentanément indisponibles (le titre
 * sera réessayé plus tard, jamais rangé à tort en « Inclassable »).
 */
function ytdl_genre_for_artist(string $artist): array {
    $key = ytdl_genre_key($artist);
    if ($key === '') return [YTDL_GENRE_UNCLASSIFIED, 'aucun'];
    $db = ytdl_db();
    $st = $db->prepare('SELECT genre, source FROM artist_genres WHERE artist_key = :k');
    $st->execute([':k' => $key]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $st->closeCursor();
    if ($row) return [$row['genre'], $row['source']];

    $found = ytdl_genre_musicbrainz($artist);
    $source = 'musicbrainz';
    if (!$found) { $found = ytdl_genre_wikidata($artist); $source = 'wikidata'; }
    if (!$found) { $genre = YTDL_GENRE_UNCLASSIFIED; $source = 'aucun'; $mbid = null; }
    else { $genre = ytdl_genre_clean($found['genre']) ?? YTDL_GENRE_UNCLASSIFIED; $mbid = $found['mbid']; }
    $db->prepare('INSERT OR REPLACE INTO artist_genres (artist_key, artist, genre, source, mbid) VALUES (:k, :a, :g, :s, :m)')
       ->execute([':k' => $key, ':a' => $artist, ':g' => $genre, ':s' => $source, ':m' => $mbid]);
    return [$genre, $source];
}

/** Classe un titre d'après son artiste : [genre, source]. */
function ytdl_genre_classify(string $title, string $uploader): array {
    [$artist] = ytdl_genre_parse($title, $uploader);
    return ytdl_genre_for_artist($artist);
}

/** Il reste des titres à classer ? */
function ytdl_genre_pending_count(): int {
    return (int)ytdl_db()->query("SELECT COUNT(*) FROM download_history WHERE library_path IS NOT NULL AND library_path <> '' AND genre_checked_at IS NULL AND COALESCE(genre_source, '') <> 'manuel'")->fetchColumn();
}

/** Lance le processus de classement s'il reste des titres (un seul tourne à la fois : verrou dans le worker). */
function ytdl_genre_spawn(bool $force = false): void {
    $stamp = sys_get_temp_dir() . '/ytdl-genre-spawn.stamp';
    if (is_file($stamp) && time() - filemtime($stamp) < 60) return; // pas plus d'un lancement par minute
    if (!$force && ytdl_genre_pending_count() === 0) return;
    @touch($stamp);
    $php = ytdl_which('php');
    if (!$php) return;
    $log = sys_get_temp_dir() . '/ytdl-genre.log';
    exec(sprintf('nohup %s %s >> %s 2>&1 &', escapeshellarg($php), escapeshellarg(__DIR__ . '/genre-worker.php'), escapeshellarg($log)));
}


/** Clé de comparaison de deux noms de genre : sans casse, accents, espaces ni ponctuation (« Hip-hop » = « hip hop »). */
function ytdl_genre_match_key(string $s): string {
    $t = function_exists('transliterator_transliterate') ? (string)transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s) : mb_strtolower($s);
    return preg_replace('/[^a-z0-9]+/', '', $t);
}

/** Télécharge la liste officielle des genres de MusicBrainz (une page de 100 par requête). */
function ytdl_genre_vocab_load(): int {
    $db = ytdl_db();
    $insert = $db->prepare('INSERT OR REPLACE INTO genre_vocab (match_key, name) VALUES (:k, :n)');
    $offset = 0; $n = 0;
    do {
        $page = ytdl_genre_http('https://musicbrainz.org/ws/2/genre/all?fmt=json&limit=100&offset=' . $offset);
        $genres = $page['genres'] ?? [];
        foreach ($genres as $g) {
            $name = ytdl_genre_clean((string)($g['name'] ?? ''));
            $key = $name !== null ? ytdl_genre_match_key($name) : '';
            if ($key !== '') { $insert->execute([':k' => $key, ':n' => $name]); $n++; }
        }
        $offset += 100;
    } while ($genres && $offset < (int)($page['genre-count'] ?? 0));
    return $n;
}

function ytdl_genre_vocab_count(): int {
    return (int)ytdl_db()->query('SELECT COUNT(*) FROM genre_vocab')->fetchColumn();
}

/** Genres déjà utilisés dans la bibliothèque (tous comptes), du plus au moins fréquent. */
function ytdl_genre_used(): array {
    return ytdl_db()->query("SELECT genre FROM download_history WHERE genre IS NOT NULL AND library_path IS NOT NULL GROUP BY genre ORDER BY COUNT(*) DESC")->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Ce genre existe-t-il déjà ? Renvoie son nom exact (celui de la bibliothèque,
 * sinon celui de MusicBrainz), ou null s'il est nouveau.
 */
function ytdl_genre_existing(string $genre): ?string {
    $key = ytdl_genre_match_key($genre);
    if ($key === '') return null;
    foreach (ytdl_genre_used() as $g) if (ytdl_genre_match_key($g) === $key) return $g;
    $st = ytdl_db()->prepare('SELECT name FROM genre_vocab WHERE match_key = :k');
    $st->execute([':k' => $key]);
    $name = $st->fetchColumn();
    return $name !== false ? $name : null;
}

/** Genres commençant par / contenant la saisie : bibliothèque d'abord, puis liste de MusicBrainz. */
function ytdl_genre_search(string $q, int $limit = 8): array {
    $key = ytdl_genre_match_key($q);
    if ($key === '') return [];
    $out = [];
    foreach (ytdl_genre_used() as $g) {
        if (str_contains(ytdl_genre_match_key($g), $key)) $out[$g] = 'bibliothèque';
    }
    $st = ytdl_db()->prepare("SELECT name FROM genre_vocab WHERE match_key LIKE :like ORDER BY (match_key LIKE :pre) DESC, LENGTH(match_key) LIMIT 30");
    $st->execute([':like' => '%' . $key . '%', ':pre' => $key . '%']);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $known = false;
        foreach (array_keys($out) as $g) if (ytdl_genre_match_key($g) === ytdl_genre_match_key($name)) { $known = true; break; }
        if (!$known) $out[$name] = 'musicbrainz';
    }
    $res = [];
    foreach ($out as $name => $src) { $res[] = ['name' => $name, 'source' => $src]; if (count($res) >= $limit) break; }
    return $res;
}
