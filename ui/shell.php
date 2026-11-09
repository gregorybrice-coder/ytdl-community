<?php
/*
 * Modèle commun des nouvelles interfaces : sillon.php, orbite.php et
 * platine.php ne font que choisir l'apparence ($skin) puis incluent ce
 * fichier. Elles réutilisent tels quels les endpoints de capture.php ;
 * seules les données d'amorçage passent par ici.
 */
if (!isset($skin)) { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/auth.php';
$user = ytdl_require_login(true); // vide $_SESSION['shared_urls'] plus bas

$skins = [
    'sillon' => [
        'label' => 'Sillon', 'theme' => '#0c0b0a',
        'fonts' => 'family=Big+Shoulders+Display:wght@700;900&family=IBM+Plex+Mono:wght@400;500&family=Instrument+Sans:wght@400;500;600',
    ],
    'orbite' => [
        'label' => 'Orbite', 'theme' => '#04060f',
        'fonts' => 'family=Unbounded:wght@400;600;800&family=JetBrains+Mono:wght@400;600&family=Manrope:wght@400;500;600;700',
    ],
    'platine' => [
        'label' => 'Platine', 'theme' => '#eceef4',
        'fonts' => 'family=Syne:wght@600;800&family=Manrope:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500',
    ],
    'heros' => [
        'label' => 'Héros', 'theme' => '#fff8e7',
        'fonts' => 'family=Bangers&family=M+PLUS+Rounded+1c:wght@500;700;800&family=Space+Mono:wght@400;700',
    ],
    'galaxie' => [
        'label' => 'Galaxie', 'theme' => '#05030f',
        'fonts' => 'family=Orbitron:wght@600;800&family=Exo+2:wght@400;500;600;700&family=Space+Mono:wght@400;700',
    ],
    'retro' => [
        'label' => 'Néo-rétro', 'theme' => '#fff1dc',
        'fonts' => 'family=Shrikhand&family=DM+Sans:wght@400;500;700&family=DM+Mono:wght@400;500',
    ],
    'arcade' => [
        'label' => 'Arcade', 'theme' => '#0b0b16',
        'fonts' => 'family=Press+Start+2P&family=VT323&family=Silkscreen:wght@400;700',
    ],
    'nature' => [
        'label' => 'Nature', 'theme' => '#f3f1e7',
        'fonts' => 'family=Fraunces:opsz,wght@9..144,600;9..144,800&family=Nunito:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500',
    ],
    'aurore' => [
        'label' => 'Aurore', 'theme' => '#030a16',
        'fonts' => 'family=Cormorant+Garamond:ital,wght@0,600;0,700;1,600;1,700&family=Outfit:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500',
    ],
    'bauhaus' => [
        'label' => 'Bauhaus', 'theme' => '#f1e9d8',
        'fonts' => 'family=League+Spartan:wght@700;800;900&family=Work+Sans:wght@400;500;600;700&family=Space+Mono:wght@400;700',
    ],
    'carnet' => [
        'label' => 'Carnet', 'theme' => '#fdfbf3',
        'fonts' => 'family=Permanent+Marker&family=Patrick+Hand&family=Courier+Prime:wght@400;700',
    ],
    'laiton' => [
        'label' => 'Laiton', 'theme' => '#1b120c',
        'fonts' => 'family=Cinzel:wght@600;700;900&family=Spectral:ital,wght@0,400;0,500;0,700;1,700&family=Courier+Prime:wght@400;700',
    ],
    'muse' => [
        'label' => 'Muse', 'theme' => '#f8e7e7',
        'fonts' => 'family=Playfair+Display:ital,wght@0,600;1,500;1,700&family=Jost:wght@400;500;600&family=DM+Mono:wght@400;500',
    ],
];
$skinInfo = $skins[$skin];

// Le compte a choisi une autre des nouvelles interfaces (sur un autre
// appareil, par exemple) : on l'y emmène, en gardant d'éventuels liens
// partagés dans l'adresse.
$accountSkin = (string)($user['skin'] ?? '');
if ($accountSkin !== $skin && isset($skins[$accountSkin])) {
    header('Location: ' . $accountSkin . '.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

// Nouveautés en attente (voir db.php et capture.php) : même requête, même
// ordre que la liste notification_ids.
$pendingNotifications = [];
$notificationIds = array_filter(array_map('intval', explode(',', (string)($user['notification_ids'] ?? ''))));
if ($notificationIds) {
    $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
    $stmt = ytdl_db()->prepare("SELECT id, type, title, message FROM notifications WHERE id IN ($placeholders)");
    $stmt->execute($notificationIds);
    $byId = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $byId[(int)$row['id']] = $row + ['id' => (int)$row['id']];
    foreach ($notificationIds as $id) if (isset($byId[$id])) $pendingNotifications[] = $byId[$id];
}

// Liens reçus par le partage natif du téléphone (share.php) : consommés ici,
// exactement comme capture.php le faisait.
// capture.php, quand il redirige ici, les transmet dans ?shared= ; on ne
// garde que de vrais liens YouTube (même motif que share.php).
$sharedUrls = array_values($_SESSION['shared_urls'] ?? []);
unset($_SESSION['shared_urls']);
foreach (explode("\n", (string)($_GET['shared'] ?? '')) as $candidate) {
    if (preg_match('#^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?v=|shorts/)|youtu\.be/)[\w-]+\S*$#i', trim($candidate))) {
        $sharedUrls[] = trim($candidate);
    }
}
$sharedUrls = array_values(array_unique($sharedUrls));

$avatarFiles = array_map('basename', glob(dirname(__DIR__) . '/avatars/avatar-*.jpg') ?: []);
sort($avatarFiles);

$boot = [
    'id' => (int)$user['id'],
    'name' => (string)$user['name'],
    'email' => (string)$user['email'],
    'avatar' => (string)($user['avatar'] ?? ''),
    'avatars' => $avatarFiles,
    'isAdmin' => (int)$user['is_admin'] === 1,
    'notifications' => $pendingNotifications,
    'sharedUrls' => $sharedUrls,
    'vapidKey' => YTDL_VAPID_PUBLIC_KEY,
    'skin' => $skin,
    'serverSkin' => $user['skin'] ?? null,
];
$avatar = $boot['avatar'] !== '' ? 'avatars/' . rawurlencode($boot['avatar']) : '';
// Version = date de modification : un fichier modifié n'est jamais servi
// depuis le cache du navigateur.
$v = fn(string $f): int => (int)@filemtime(dirname(__DIR__) . '/' . $f);

// Icônes : un seul jeu de tracés, réutilisé par toute la page.
function icon(string $name): string {
    $paths = [
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'music' => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
        'clip' => '<rect x="2" y="5" width="15" height="14" rx="2"/><path d="m17 10 5-3v10l-5-3"/>',
        'lists' => '<path d="M3 6h13M3 12h13M3 18h8"/><path d="m15 15 6 3-6 3z"/>',
        // Hors musique (podcasts, documentaires, tutoriels) : un micro, la parole plutôt que le son.
        'other' => '<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v4M8 22h8"/>',
        'playlist-add' => '<path d="M3 6h13M3 12h13M3 18h8"/><path d="M18 14v8M14 18h8"/>',
        'share' => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/>',
        'download' => '<path d="M12 3v12M7 10l5 5 5-5M4 20h16"/>',
        'more' => '<circle cx="5" cy="12" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="19" cy="12" r="1.2"/>',
        'shuffle' => '<path d="M16 3h5v5M4 20 21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/>',
        'sliders' => '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
        'back' => '<path d="M15 6l-6 6 6 6"/>',
        'sort' => '<path d="M7 4v16M3 16l4 4 4-4M17 20V4M13 8l4-4 4 4"/>',
        'listadd' => '<path d="M3 6h13M3 12h13M3 18h8"/><path d="M18 14v8M14 18h8"/>',
        'dice' => '<rect x="3" y="3" width="18" height="18" rx="4"/><circle cx="8.5" cy="8.5" r="1.2" fill="currentColor"/><circle cx="15.5" cy="15.5" r="1.2" fill="currentColor"/><circle cx="12" cy="12" r="1.2" fill="currentColor"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}
?><!doctype html>
<html lang="fr" data-skin="<?php echo $skin; ?>">
<head>
<meta charset="utf-8">
<!-- Pas de viewport-fit=cover : sur Android, l'appli installée se dessinait
     alors sous la barre de navigation (retour / accueil / applis récentes)
     sans que le navigateur en signale la hauteur, et les commandes de
     lecture passaient dessous. Sans lui, la page s'arrête au-dessus. -->
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="<?php echo $skinInfo['theme']; ?>">
<title>ytdl+ · <?php echo $skinInfo['label']; ?></title>
<link rel="manifest" href="manifest.json?v=4">
<link rel="icon" href="icon-192.png?v=2">
<link rel="apple-touch-icon" href="icon-180.png?v=2">
<!-- Ajout à l'écran d'accueil sur iPhone (Safari comme Chrome) : appli
     autonome, sans barre du navigateur. -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="ytdl">
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?<?php echo $skinInfo['fonts']; ?>&display=swap">
<link rel="stylesheet" href="ui/base.css?v=<?php echo $v('ui/base.css'); ?>">
<link rel="stylesheet" href="ui/<?php echo $skin; ?>.css?v=<?php echo $v('ui/' . $skin . '.css'); ?>">
<?php
// Images personnelles de l'apparence, déposées dans ui/skins/<apparence>/ :
// fond.(jpg|png|webp), perso.(png|webp) — un personnage détouré —, et
// etiquette.(jpg|png|webp) pour le centre du disque. Chacune devient une
// variable CSS (--skin-fond, --skin-perso, --skin-etiquette) que la feuille
// de l'apparence utilise si elle est présente ; sans image, rien ne change.
$skinVars = [];
foreach (['fond', 'perso', 'etiquette'] as $slot) {
    foreach (['webp', 'png', 'jpg', 'jpeg'] as $ext) {
        $rel = 'ui/skins/' . $skin . '/' . $slot . '.' . $ext;
        if (is_file(dirname(__DIR__) . '/' . $rel)) {
            // Adresse absolue : une url() relative serait résolue depuis la
            // feuille qui utilise la variable (ui/…), pas depuis la page.
            $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
            $skinVars[] = '--skin-' . $slot . ': url("' . $base . '/' . $rel . '?v=' . $v($rel) . '")';
            break;
        }
    }
}
if ($skinVars): ?>
<style>:root { <?php echo implode('; ', $skinVars); ?>; }</style>
<?php endif; ?>
</head>
<body>
<div class="app" id="app">

  <header class="top">
    <a class="brand" href="<?php echo $skin; ?>.php">ytdl<span>+</span></a>
    <label class="search" id="search" aria-label="Rechercher dans ma bibliothèque">
      <button type="button" class="search-go" id="search-go" aria-label="Valider la recherche"><?php echo icon('search'); ?></button>
      <input type="search" id="search-input" placeholder="Rechercher dans ma bibliothèque" autocomplete="off" enterkeyhint="search">
      <button type="button" class="search-clear" id="search-clear" aria-label="Effacer la recherche" hidden><?php echo icon('close'); ?></button>
    </label>
    <button type="button" class="ib search-toggle" id="search-toggle" aria-label="Rechercher" aria-expanded="false"><?php echo icon('search'); ?></button>
    <button type="button" class="ib" id="add-btn" aria-label="Ajouter un titre"><?php echo icon('plus'); ?></button>
    <button type="button" class="avatar-btn" id="profile-btn" aria-label="Mon profil">
      <span class="avatar-ring" id="usage-ring"><?php if ($avatar): ?><img src="<?php echo htmlspecialchars($avatar); ?>" alt=""><?php else: ?><span><?php echo htmlspecialchars(mb_substr($boot['name'], 0, 1)); ?></span><?php endif; ?></span>
    </button>
  </header>

  <section class="hero" aria-live="polite">
    <p class="now-artist" id="now-artist"></p>
    <h1 class="now-title" id="now-title">Chargement…</h1>
    <p class="now-meta" id="now-meta"></p>
    <div class="now-rating" id="now-rating" role="radiogroup" aria-label="Ma note">
      <?php for ($i = 1; $i <= 5; $i++): ?><button type="button" data-rating="<?php echo $i; ?>" role="radio" aria-checked="false" aria-label="<?php echo $i . ' étoile' . ($i > 1 ? 's' : ''); ?>">★</button><?php endfor; ?>
    </div>
    <div class="now-actions">
      <button type="button" class="ib" id="act-playlist" aria-label="Ajouter à une playlist" title="Ajouter à une playlist"><?php echo icon('playlist-add'); ?></button>
      <button type="button" class="ib" id="act-share" aria-label="Partager" title="Partager"><?php echo icon('share'); ?></button>
      <a class="ib" id="act-download" aria-label="Télécharger le fichier" title="Télécharger le fichier" download><?php echo icon('download'); ?></a>
      <button type="button" class="ib" id="act-more" aria-label="Plus d'actions" title="Plus d'actions"><?php echo icon('more'); ?></button>
    </div>
  </section>

  <section class="deck" aria-label="Bibliothèque">
    <div class="wheel-zone" id="wheel-zone" tabindex="0" aria-label="Faites tourner le disque pour parcourir les titres">
      <div class="disc" id="disc" aria-hidden="true">
        <div class="disc-rot" id="disc-rot">
          <div class="disc-spin" id="disc-spin">
            <div class="disc-label"><span>YTDL+</span><i></i></div>
          </div>
        </div>
        <div class="disc-sheen"></div>
      </div>
    </div>
    <div class="needle" id="needle" aria-hidden="true"></div>
    <nav class="hub" id="hub" aria-label="Catégories">
      <button type="button" data-cat="audio" aria-label="Morceaux" title="Morceaux"><?php echo icon('music'); ?><span class="badge" hidden></span></button>
      <button type="button" data-cat="video" aria-label="Clips" title="Clips"><?php echo icon('clip'); ?><span class="badge" hidden></span></button>
      <button type="button" data-cat="list" aria-label="Playlists" title="Playlists"><?php echo icon('lists'); ?><span class="badge" hidden></span></button>
      <button type="button" data-cat="other" aria-label="Hors musique" title="Hors musique"><?php echo icon('other'); ?><span class="badge" hidden></span></button>
    </nav>
    <div class="deck-info">
      <button type="button" class="deck-back" id="deck-back" hidden><?php echo icon('back'); ?><span>Playlists</span></button>
      <button type="button" class="deck-sort" id="deck-plmenu" aria-label="Actions de la playlist" hidden><?php echo icon('more'); ?></button>
      <button type="button" class="deck-sort" id="deck-dice" aria-label="Découvrir : une playlist au hasard" title="Découvrir : une playlist au hasard"><?php echo icon('dice'); ?></button>
      <button type="button" class="deck-sort" id="deck-save-search" aria-label="Enregistrer ces résultats en playlist" title="Enregistrer ces résultats en playlist" hidden><?php echo icon('listadd'); ?></button>
      <span class="deck-count" id="deck-count"></span>
    </div>
    <div class="deck-sorts" id="deck-sorts" role="group" aria-label="Ordre du disque">
      <button type="button" data-sort="recent" aria-pressed="false" title="Derniers ajoutés en premier">Récents</button>
      <button type="button" data-sort="asc" aria-pressed="false" title="Par titre, de A à Z">A→Z</button>
      <button type="button" data-sort="desc" aria-pressed="false" title="Par titre, de Z à A">Z→A</button>
    </div>
    <div class="deck-side">
      <button type="button" class="ib" id="deck-shuffle" aria-label="Lecture aléatoire" aria-pressed="false"><?php echo icon('shuffle'); ?></button>
      <button type="button" class="ib" id="deck-options" aria-label="Options de lecture"><?php echo icon('sliders'); ?></button>
    </div>
    <div class="deck-letter" id="deck-letter" aria-hidden="true"></div>
    <div class="deck-empty" id="deck-empty" hidden></div>
  </section>

  <section class="panel">
    <div class="tabs" role="tablist">
      <button type="button" role="tab" data-view="lyrics" aria-selected="true">Paroles</button>
      <button type="button" role="tab" data-view="bio" aria-selected="false">Bio</button>
      <button type="button" role="tab" data-view="queue" aria-selected="false">À suivre</button>
    </div>
    <div class="views">
      <div class="view is-active" id="view-lyrics" data-view="lyrics">
        <h2 class="view-title">Paroles</h2>
        <div class="lyrics" id="lyrics"></div>
        <div class="lyrics-tools" id="lyrics-tools" hidden>
          <button type="button" id="lyr-toggle" aria-expanded="false" aria-controls="lyrics-tools">Synchro ±</button>
          <button type="button" id="lyr-sync-now" title="Appuie quand la première ligne commence">Caler ici</button>
          <button type="button" id="lyr-minus" aria-label="Paroles plus tôt de 0,5 seconde">−0,5 s</button>
          <span id="lyr-offset"></span>
          <button type="button" id="lyr-plus" aria-label="Paroles plus tard de 0,5 seconde">+0,5 s</button>
        </div>
      </div>
      <div class="view" id="view-bio" data-view="bio">
        <h2 class="view-title">Artiste</h2>
        <div class="bio" id="bio"></div>
      </div>
      <div class="view" id="view-queue" data-view="queue">
        <h2 class="view-title">À suivre</h2>
        <ol class="queue" id="queue"></ol>
      </div>
    </div>
  </section>

  <footer class="transport">
    <div class="progress">
      <span class="t" id="t-elapsed">0:00</span>
      <div class="bar" id="bar" role="slider" tabindex="0" aria-label="Position de lecture" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="fill" id="fill"></div></div>
      <span class="t" id="t-remaining">0:00</span>
    </div>
    <div class="controls">
      <button type="button" class="ib" id="ctl-shuffle" aria-label="Lecture aléatoire" aria-pressed="false"><?php echo icon('shuffle'); ?></button>
      <button type="button" class="ib" id="ctl-prev" aria-label="Piste précédente"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 5h2v14H6zM20 5v14L9 12z"/></svg></button>
      <button type="button" class="play" id="ctl-play" aria-label="Lecture">
        <svg class="i-play" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4v16l13-8z"/></svg>
        <svg class="i-pause" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 4h4v16H6zM14 4h4v16h-4z"/></svg>
      </button>
      <button type="button" class="ib" id="ctl-next" aria-label="Piste suivante"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16 5h2v14h-2zM4 5v14l11-7z"/></svg></button>
      <button type="button" class="ib" id="ctl-options" aria-label="Options de lecture"><?php echo icon('sliders'); ?></button>
    </div>
  </footer>
</div>

<!-- Feuille modale générique : son contenu est construit par ui/sillon.js. -->
<dialog class="sheet" id="sheet" aria-labelledby="sheet-title">
  <div class="sheet-head">
    <h2 id="sheet-title"></h2>
    <button type="button" class="ib" id="sheet-close" aria-label="Fermer"><?php echo icon('close'); ?></button>
  </div>
  <div class="sheet-body" id="sheet-body"></div>
</dialog>

<div class="video-layer" id="video-layer" hidden>
  <div class="video-head">
    <div><p class="now-artist" id="video-artist"></p><h2 id="video-title"></h2></div>
    <a class="ib" id="video-download" aria-label="Télécharger ce clip sur cet appareil" title="Télécharger ce clip sur cet appareil" download><?php echo icon('download'); ?></a>
    <button type="button" class="ib" id="video-share" aria-label="Partager ce clip" title="Partager ce clip"><?php echo icon('share'); ?></button>
    <button type="button" class="ib" id="video-close" aria-label="Fermer le clip"><?php echo icon('close'); ?></button>
  </div>
  <video id="video" controls playsinline preload="metadata"></video>
  <div class="video-lyrics" id="video-lyrics" aria-live="off"></div>
  <div class="video-tools" id="video-tools">
    <button type="button" id="video-prev" aria-label="Clip précédent">◀◀</button>
    <span class="video-offset" id="video-offset-box" hidden>
      <button type="button" id="video-lyr-now" title="Appuie quand la première ligne commence">Caler ici</button>
      <button type="button" id="video-lyr-minus" aria-label="Paroles plus tôt de 0,5 seconde">−0,5 s</button>
      <span id="video-lyr-offset"></span>
      <button type="button" id="video-lyr-plus" aria-label="Paroles plus tard de 0,5 seconde">+0,5 s</button>
    </span>
    <button type="button" id="video-sync" hidden>Synchroniser les paroles</button>
    <button type="button" id="video-next" aria-label="Clip suivant">▶▶</button>
  </div>
  <div class="video-syncbox" id="video-syncbox" hidden></div>
</div>

<div class="toast" id="toast" role="status" hidden></div>

<script>window.YTDL_BOOT = <?php echo json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;</script>
<script src="ui/core.js?v=<?php echo $v('ui/core.js'); ?>"></script>
<script src="ui/wheel.js?v=<?php echo $v('ui/wheel.js'); ?>"></script>
<script src="ui/passkey.js?v=<?php echo $v('ui/passkey.js'); ?>"></script>
<script src="ui/immersive.js?v=<?php echo $v('ui/immersive.js'); ?>"></script>
<script src="ui/app.js?v=<?php echo $v('ui/app.js'); ?>"></script>
</body>
</html>
