<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
ytdl_require_login();

$ytDlp  = ytdl_which('yt-dlp');
$ffmpeg = ytdl_which('ffmpeg');

if (!$ytDlp)  ytdl_json_error('yt-dlp est introuvable sur le serveur. Installe-le avec : brew install yt-dlp', 500);
if (!$ffmpeg) ytdl_json_error('ffmpeg est introuvable sur le serveur. Installe-le avec : brew install ffmpeg', 500);

// Garde-fou global, avant même de savoir de quel compte il s'agit : protège
// le serveur (tous comptes confondus), distinct du quota de 5 Go par compte
// qui protège chaque compte de lui-même.
if (!ytdl_has_enough_disk_space()) {
    ytdl_json_error("Le serveur manque d'espace disque en ce moment — réessaie plus tard, ou libère de la place dans ta bibliothèque.", 503);
}

$jsRuntimeFlag = ytdl_js_runtime_flag();

$url = ytdl_clean_url($_GET['url'] ?? '');
ytdl_validate_url($url);

$audioOnly = ($_GET['audio_only'] ?? '0') === '1';
$deviceId  = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['device_id'] ?? '')), 0, 64);
// "Hors musique" (voir capture.php, bouton-poussoir dédié) — indépendant de
// $audioOnly : une vidéo "Others" peut être téléchargée en audio ou vidéo.
$category  = ($_GET['category'] ?? 'music') === 'other' ? 'other' : 'music';
// Préfixe de titre pour les résultats YouTube classés "hors musique" depuis
// le menu "…" (voir capture.php, renderYoutubeSuggestions) — liste fermée,
// jamais une valeur arbitraire du client tirée du titre final.
$allowedTitlePrefixes = ['[Docu] ', '[Tuto] '];
$titlePrefixRaw = (string)($_GET['title_prefix'] ?? '');
$titlePrefix = in_array($titlePrefixRaw, $allowedTitlePrefixes, true) ? $titlePrefixRaw : '';

// Forçage : l'utilisateur a vu l'avertissement de doublon et a explicitement
// demandé le téléchargement quand même (homonymes, version différente que la
// comparaison de titres ne sait pas distinguer, fichier abîmé à remplacer...).
// Les deux garde-fous ci-dessous sont alors sautés, rien d'autre ne change.
$force = ($_GET['force'] ?? '0') === '1';

// Historique par appareil : si ce même appareil (PC, téléphone... identifiés
// séparément côté client) a déjà téléchargé cette URL dans ce mode (vidéo ou
// audio), on ne relance pas tout le pipeline yt-dlp pour rien.
if ($deviceId !== '' && !$force) {
    $histStmt = ytdl_db()->prepare(
        'SELECT title FROM download_history WHERE device_id = :device_id AND url = :url AND audio_only = :audio_only LIMIT 1'
    );
    $histStmt->execute([':device_id' => $deviceId, ':url' => $url, ':audio_only' => $audioOnly ? 1 : 0]);
    $existing = $histStmt->fetchColumn();
    if ($existing !== false) {
        header('Content-Type: application/json');
        echo json_encode(['alreadyDownloaded' => true, 'title' => $existing, 'reason' => 'device']);
        exit;
    }
}

// Même URL déjà présente chez un AUTRE compte (bibliothèque encore sur
// disque) : inutile de retélécharger un second exemplaire, un accès partagé
// (même mécanisme que le bouton "Partager", voir library_shares) suffit —
// économise bande passante et espace disque. Avant même l'appel yt-dlp
// ci-dessous, pour ne pas le payer pour rien.
$myUserId = $_SESSION['user_id'] ?? null;
if (!$force && $myUserId !== null) {
    $elsewhereStmt = ytdl_db()->prepare(
        'SELECT id, user_id, title, library_path FROM download_history
         WHERE url = :url AND audio_only = :audio_only AND library_path IS NOT NULL AND user_id != :me
         LIMIT 1'
    );
    $elsewhereStmt->execute([':url' => $url, ':audio_only' => $audioOnly ? 1 : 0, ':me' => $myUserId]);
    $elsewhere = $elsewhereStmt->fetch();
    if ($elsewhere && is_file($elsewhere['library_path'])) {
        ytdl_db()->prepare(
            'INSERT OR IGNORE INTO library_shares (history_id, shared_with_user_id, shared_by_user_id) VALUES (:id, :me, :owner)'
        )->execute([':id' => $elsewhere['id'], ':me' => $myUserId, ':owner' => $elsewhere['user_id']]);
        ytdl_ensure_shared_after_grant((int)$elsewhere['id']);
        header('Content-Type: application/json');
        echo json_encode(['alreadyDownloaded' => true, 'title' => $elsewhere['title'], 'reason' => 'elsewhere']);
        exit;
    }
}

// Récupération rapide du titre + vignette (juste les métadonnées, pas le
// média) pour nommer le fichier final, l'afficher pendant la progression, et
// alimenter la bibliothèque en cas de succès (voir file.php).
$scratch = ytdl_scratch_dir();
$titleCmd = sprintf(
    '%s%s%s --no-playlist --skip-download --print %s -- %s',
    escapeshellarg($ytDlp),
    $jsRuntimeFlag,
    ytdl_cookies_flag($scratch),
    escapeshellarg("%(title)s\x1f%(thumbnail)s\x1f%(uploader)s"),
    escapeshellarg($url)
);
[$titleOut, $titleErr] = ytdl_run_capture($titleCmd);
ytdl_rm_scratch($scratch);
if ($titleOut === '') {
    ytdl_json_error(ytdl_explain_error($titleErr));
}
// L'uploader sert d'artiste de repli quand le titre ne contient pas de
// séparateur « Artiste - Titre » (voir capture.php, écran verrouillé).
[$title, $thumbnail, $uploader] = array_pad(explode("\x1f", $titleOut, 3), 3, '');
if ($titlePrefix !== '') {
    $title = $titlePrefix . $title;
}

// Second garde-fou anti-doublon, sur le TITRE cette fois. Le contrôle par URL
// plus haut ne voit rien quand la même vidéo est demandée depuis un autre
// appareil, sous une autre forme d'URL (youtu.be, /shorts, paramètres de
// suivi), ou via une republication au même titre. On compare donc au contenu
// réellement présent sur le serveur pour ce compte, avant de lancer yt-dlp.
$duplicate = $force ? null : ytdl_find_library_duplicate($_SESSION['user_id'] ?? null, $title, $audioOnly);
if ($duplicate) {
    header('Content-Type: application/json');
    echo json_encode([
        'alreadyDownloaded' => true,
        'title' => $duplicate['title'],
        'reason' => 'library',
    ]);
    exit;
}

// Ramasse-miettes des jobs abandonnés, au moment de créer le suivant : c'est
// le seul point de passage à la fois peu fréquent et systématique (progress.php
// est appelé en boucle, ce serait du gâchis). Le nouveau job n'existe pas
// encore, il ne risque donc pas d'être emporté.
ytdl_purge_stale_jobs();

$jobId  = bin2hex(random_bytes(16));
$jobDir = ytdl_jobs_root() . '/' . $jobId;
mkdir($jobDir, 0700, true);

file_put_contents($jobDir . '/title.txt', $title);
file_put_contents($jobDir . '/thumbnail.txt', $thumbnail);
file_put_contents($jobDir . '/uploader.txt', $uploader);
file_put_contents($jobDir . '/status.txt', 'running');
file_put_contents($jobDir . '/url.txt', $url);
file_put_contents($jobDir . '/device_id.txt', $deviceId);
file_put_contents($jobDir . '/audio_only.txt', $audioOnly ? '1' : '0');
file_put_contents($jobDir . '/category.txt', $category);
file_put_contents($jobDir . '/user_id.txt', (string)($_SESSION['user_id'] ?? ''));

$logFile = $jobDir . '/log.txt';
// Nom générique (pas "video") : le mode audio seul produit un .mp3, pas un .mp4.
$outputTemplate = $jobDir . '/media.%(ext)s';

// Flags communs aux quatre variantes de commande ci-dessous. La copie des
// cookies vit dans le dossier du job et est effacée dès la fin du pipeline.
$commonFlags = $jsRuntimeFlag . ytdl_cookies_flag($jobDir);

if ($audioOnly) {
    // bestaudio : la meilleure piste audio disponible (opus/m4a selon la vidéo),
    // convertie en MP3 avec --audio-quality 0 (VBR maximal, quasi transparent).
    $downloadCmd = sprintf(
        '%s --newline -f %s -x --audio-format mp3 --audio-quality 0 --ffmpeg-location %s%s --no-playlist --restrict-filenames -o %s -- %s',
        escapeshellarg($ytDlp),
        escapeshellarg('bestaudio/best'),
        escapeshellarg($ffmpeg),
        $commonFlags,
        escapeshellarg($outputTemplate),
        escapeshellarg($url)
    );
    // Repli si le flux audio seul (DASH) est bloqué : certaines vidéos protègent
    // ces flux plus agressivement que les flux combinés vidéo+audio classiques
    // (progressive). On télécharge alors un flux combiné et on en extrait
    // l'audio localement avec ffmpeg — plus lourd à transférer, mais fiable là
    // où bestaudio/worstaudio se font systématiquement bloquer.
    //
    // 22 AVANT 18, à l'inverse du repli vidéo plus bas : ces deux formats se
    // distinguent aussi par leur piste audio (AAC ~192 kbps pour le 22, ~96
    // seulement pour le 18). Ici c'est le son qu'on garde, la résolution ne
    // sert à rien — prendre le 18 en premier revenait à ré-encoder du 96 kbps
    // en MP3 V0 : un gros fichier au son médiocre.
    $fallbackCmd = sprintf(
        '%s --newline -f %s -x --audio-format mp3 --audio-quality 0 --ffmpeg-location %s%s --no-playlist --restrict-filenames -o %s -- %s',
        escapeshellarg($ytDlp),
        escapeshellarg('22/18/best'),
        escapeshellarg($ffmpeg),
        $commonFlags,
        escapeshellarg($outputTemplate),
        escapeshellarg($url)
    );
} else {
    // Sélecteur de format, du plus au moins prioritaire. Le filtre de codec
    // seul (sans condition de hauteur) piégeait la qualité : si l'avc1 le
    // plus fiable ne montait qu'à 480p sur une vidéo donnée alors que du
    // VP9/AV1 existait en 1080p+, l'ancien sélecteur prenait quand même le
    // 480p avc1 en premier — d'où des rendus visiblement dégradés. Priorité
    // désormais à la résolution (1080p minimum si dispo), le codec ne
    // départage qu'à résolution égale :
    // 1) avc1 en 1080p+ : le combo idéal (fiable + bonne qualité).
    // 2) N'importe quel codec sauf AV1 en 1080p+ (AV1 le plus sujet au 403).
    // 3) N'importe quel codec en 1080p+, même AV1, si c'est la seule option.
    // 4) Si 1080p+ n'existe pas du tout (vidéo source de moindre qualité) :
    //    on retombe sur le meilleur avc1 disponible, quelle que soit sa
    //    résolution, puis non-AV1, puis best en tout dernier recours.
    $format = 'bestvideo[height>=1080][vcodec^=avc1]+bestaudio'
        . '/bestvideo[height>=1080][vcodec!*=av01]+bestaudio'
        . '/bestvideo[height>=1080]+bestaudio'
        . '/bestvideo[vcodec^=avc1]+bestaudio'
        . '/bestvideo[vcodec!*=av01]+bestaudio'
        . '/best';

    // --newline force une ligne complète par mise à jour de progression (au lieu
    // d'un retour chariot \r qui écraserait la ligne), ce qui rend le fichier de
    // log exploitable par progress.php pendant que le téléchargement tourne.
    // --format-sort acodec:aac : departage bestaudio vers une piste AAC plutôt
    // qu'Opus quand les deux existent, SANS changer quoi que ce soit d'autre
    // au sélecteur ci-dessus (résolution/codec vidéo inchangés). Sans ça,
    // bestaudio prend souvent la piste Opus (débit perçu comme meilleur par
    // yt-dlp) une fois muxée dans le .mp4 — un conteneur où Safari (macOS/
    // iOS) ne sait pas décoder l'audio Opus : la vidéo se lisait, mais restait
    // totalement silencieuse sur ce navigateur précis.
    $downloadCmd = sprintf(
        '%s --newline -f %s --format-sort %s --merge-output-format mp4 --ffmpeg-location %s%s --no-playlist --restrict-filenames -o %s -- %s',
        escapeshellarg($ytDlp),
        escapeshellarg($format),
        escapeshellarg('acodec:aac'),
        escapeshellarg($ffmpeg),
        $commonFlags,
        escapeshellarg($outputTemplate),
        escapeshellarg($url)
    );

    // Repli si le format prioritaire échoue en cours de téléchargement (pas juste
    // indisponible, mais bloqué en plein transfert — YouTube protège parfois plus
    // agressivement les flux séparés haute résolution de certaines vidéos avec un
    // 403 après quelques pourcents). 22/18 sont des flux déjà combinés (vidéo+son
    // dans un seul fichier, jusqu'à 720p), historiquement les plus fiables — déjà
    // en AAC, --format-sort n'y change donc rien mais reste inoffensif.
    $fallbackCmd = sprintf(
        '%s --newline -f %s --format-sort %s --merge-output-format mp4 --ffmpeg-location %s%s --no-playlist --restrict-filenames -o %s -- %s',
        escapeshellarg($ytDlp),
        escapeshellarg('22/18/best'),
        escapeshellarg('acodec:aac'),
        escapeshellarg($ffmpeg),
        $commonFlags,
        escapeshellarg($outputTemplate),
        escapeshellarg($url)
    );
}

// Escalade en deux temps. Les 403 de YouTube en plein transfert sont
// intermittents (mesuré : ~1 échec sur 3 sur la même URL et le même format,
// à quelques secondes d'intervalle) : relancer la commande d'origine suffit
// le plus souvent. On retente donc d'abord le format demandé — sinon un seul
// 403 fortuit dégradait définitivement la vidéo en 360p — et on ne bascule
// sur le flux combiné de secours qu'après trois échecs d'affilée.
$retryLoop = function (string $cmd, int $times) use ($logFile): string {
    return sprintf(
        'for i in $(seq 1 %d); do sleep 4; : > %s; %s >> %s 2>&1; ec=$?; if [ "$ec" = "0" ]; then break; fi; done;',
        $times,
        escapeshellarg($logFile),
        $cmd,
        escapeshellarg($logFile)
    );
};

// La copie des cookies est effacée dès la dernière tentative, avant même que
// le fichier soit servi : le dossier du job survit au téléchargement, pas les
// identifiants du compte.
$fullCmd = sprintf(
    '(%s > %s 2>&1; ec=$?; if [ "$ec" != "0" ]; then touch %s; %s fi; if [ "$ec" != "0" ]; then touch %s; %s fi; rm -f %s; echo $ec > %s; echo done > %s) > /dev/null 2>&1 &',
    $downloadCmd,
    escapeshellarg($logFile),
    escapeshellarg($jobDir . '/retry.txt'),
    $retryLoop($downloadCmd, 2),
    escapeshellarg($jobDir . '/fallback.txt'),
    $retryLoop($fallbackCmd, 3),
    escapeshellarg($jobDir . '/cookies.txt'),
    escapeshellarg($jobDir . '/exitcode.txt'),
    escapeshellarg($jobDir . '/status.txt')
);

exec($fullCmd);

header('Content-Type: application/json');
echo json_encode(['jobId' => $jobId, 'title' => $title]);
