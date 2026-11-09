<?php
require_once __DIR__ . '/auth.php';
$user = ytdl_require_login(true); // vide $_SESSION['shared_urls'] plus bas

// Notifications "nouveauté" en attente (voir db.php) : un compte peut en
// avoir plusieurs à la fois (plusieurs fonctionnalités ajoutées avant sa
// prochaine visite). Chaque `type` pilote côté client quel effet visuel
// jouer (voir pendingNotifications dans le <script> plus bas) ; tableau vide
// si notification_ids est vide — rien à signaler. L'ordre de la liste est
// préservé (celui dans lequel les ids ont été ajoutés), pas celui du IN().
$pendingNotifications = [];
$rawNotificationIds = array_filter(array_map('intval', explode(',', (string)($user['notification_ids'] ?? ''))));
if ($rawNotificationIds) {
    $placeholders = implode(',', array_fill(0, count($rawNotificationIds), '?'));
    $notifStmt = ytdl_db()->prepare("SELECT id, type, title, message FROM notifications WHERE id IN ($placeholders)");
    $notifStmt->execute($rawNotificationIds);
    $notifById = [];
    foreach ($notifStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $notifById[(int)$row['id']] = $row;
    }
    foreach ($rawNotificationIds as $id) {
        if (isset($notifById[$id])) $pendingNotifications[] = $notifById[$id];
    }
}

$avatarFiles = array_map('basename', glob(__DIR__ . '/avatars/avatar-*.jpg'));
sort($avatarFiles);

// Liens reçus via le partage natif mobile (share.php) — tous ceux accumulés
// depuis la dernière visite de cette page, pas juste le dernier (sinon un
// partage écrasait le précédent s'il n'avait pas encore été téléchargé).
$sharedUrls = $_SESSION['shared_urls'] ?? [];
unset($_SESSION['shared_urls']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<!-- viewport-fit=cover : nécessaire pour que env(safe-area-inset-top) (voir
     .music-app .library-tabs dans music.css) reflète la vraie hauteur de la
     barre de statut iOS une fois l'app installée en PWA — sans lui, cette
     valeur reste toujours à 0 et la page ne s'étend jamais sous l'encoche. -->
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>ytdl — Votre espace musical</title>
<link rel="manifest" href="manifest.json?v=4">
<meta name="theme-color" content="#171a21">
<link rel="apple-touch-icon" href="icon-180.png?v=2">
<!-- iOS ignore le "display":"standalone" du manifest tant que ces balises
     historiques ne sont pas là — sans elles, "Ajouter à l'écran d'accueil"
     rouvre l'appli dans Safari avec sa barre d'adresse au lieu du mode
     plein écran sans chrome que donne le manifest sur Android/desktop. -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="ytdl">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<script>
  // Posé avant le CSS pour éviter un flash du skin sombre par défaut au
  // chargement — appliqué directement sur <html>, lu de façon synchrone.
  (function () {
    try {
      // L'apparence enregistrée sur le compte (users.skin, voir set-skin.php)
      // l'emporte : elle suit l'utilisateur d'un appareil à l'autre. À
      // défaut, le réglage local de cet appareil (comportement d'avant).
      var serverSkin = <?php echo json_encode($user['skin'] ?? null); ?>;
      var skin = serverSkin || localStorage.getItem('ytdl_skin');
      if (serverSkin) localStorage.setItem('ytdl_skin', serverSkin);
      // Sillon, Orbite et Platine sont des interfaces à part entière
      // (sillon.php…), pas des jeux de couleurs : on y bascule d'emblée, sauf
      // avec ?classic=1 (accès volontaire à l'interface classique). Un lien
      // reçu par le partage natif leur est transmis plutôt que traité ici.
      if (['sillon', 'orbite', 'platine', 'heros', 'galaxie', 'retro', 'arcade', 'nature', 'muse', 'aurore', 'bauhaus', 'carnet', 'laiton'].indexOf(skin) !== -1) {
        if (!/[?&]classic=1/.test(location.search)) location.replace(skin + '.php' + <?php echo json_encode($sharedUrls ? '?shared=' . rawurlencode(implode("\n", $sharedUrls)) : ''); ?>);
        skin = null;
      }
      if (skin && skin !== 'sombre') document.documentElement.setAttribute('data-skin', skin);
    } catch (err) {}
  })();
</script>
<style>
  @import url('https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Space+Grotesk:wght@500;600;700&display=swap');
  :root {
    /* Valeurs réelles de prod (reprises de l'ex-:root de music.css, qui les
       écrasait silencieusement puisqu'il se chargeait après ce fichier — les
       anciennes valeurs cyan/violet ici n'ont donc jamais été visibles). */
    --bg: #101010;
    --panel: #1b1b1b;
    --border: rgba(255, 255, 255, .078);
    --text: #f7f4ee;
    --text-dim: #a5a39f;
    --accent: #ff844b;
    --accent-2: #ffb18c;
    --error: #ff6b6b;
    --success: #b9e697;
    /* Signale ce qui vient de quelqu'un d'autre (mention « Partagé par… ») :
       assez chaud pour ressortir sur le fond sombre sans être une alerte,
       rôle déjà tenu par --error. */
    --shared: #ffa94d;
    /* --surface/--surface-2 : fonds secondaires (vignettes, champs, menus) —
       distincts de --bg pour donner du relief, mais doivent suivre le skin
       au même titre que le reste (voir data-skin plus bas). Alignées sur les
       tons "sidebar" / "carte" déjà en place dans music.css. */
    --surface: #0b0b0b;
    --surface-2: #151515;
    /* Texte/icône lisible SUR un aplat --accent (boutons pleins, coche
       active, ligne « en lecture »...) : un skin à l'accent sombre a besoin
       d'y mettre du clair, d'où une variable à part plutôt qu'un alias. */
    --on-accent: #20160f;
    /* Fond neutre d'un bouton "plein" non coloré par l'accent (lecture du
       mini-lecteur) — distinct de --chip-bg (chips/menus), plus clair pour
       rester cliquable au premier coup d'œil. */
    --btn-light-bg: #f1eee7;
    /* Fond des chips/menus/boutons de la sidebar et des barres d'action —
       un troisième ton, ni --surface ni --surface-2. */
    --chip-bg: #272624;
    /* Triplets RGB (pas de rgba() ici) réutilisés dans les halos de survol et
       fonds semi-transparents du fichier : un seul endroit à changer plutôt
       que la trentaine d'occurrences individuelles. */
    --overlay-rgb: 255,255,255;
    --accent-rgb: 255,132,75;
    --accent-2-rgb: 255,177,140;
    --success-rgb: 185,230,151;
    --error-rgb: 255,107,107;
    --surface-2-rgb: 21,21,21;
    /* Couleur du halo néon "nouveauté à découvrir" (voir .neon-pending et
       .neon-flash) — variable par skin, sinon un cyan flotte mal sur les
       fonds clairs/pastel (voir --neon-rgb du skin Kawaii). */
    --neon-rgb: 0,255,255;
  }
  /* Hero (bandeau "Playlist au hasard") et disque vinyle décoratif : palette
     volontairement FIXE, pas dépendante du skin — c'est une illustration de
     marque (comme un logo), pas un aplat d'interface. Les couleurs du disque/
     de la pochette de repli restent donc codées en dur dans music.css. */
  :root {
    --hero-bg: #d3dcb9;
    --hero-heading: #18251e;
    --hero-text: #3c5042;
    --hero-cta-bg: #1b2b22;
    --hero-cta-bg-hover: #314938;
    --hero-cta-text: #f3f4e8;
  }
  /* ============================================================
     Skins — chaque jeu de valeurs réécrit les variables ci-dessus ;
     tout le reste du CSS n'y touche jamais directement (voir le sélecteur
     de skin côté JS dans "Mon profil", qui recharge la page au choix). */
  [data-skin="clair"] {
    --bg: #f4f6fb;
    --panel: rgba(255, 255, 255, .85);
    --border: rgba(16, 19, 26, .12);
    --text: #14181f;
    --text-dim: #5a6478;
    --accent: #d1602a;
    --accent-2: #a8471a;
    --error: #c62f2f;
    --success: #1f8a4c;
    --shared: #b5661a;
    --surface: #e9ebf1;
    --surface-2: #dde1ea;
    --on-accent: #fff8f0;
    --btn-light-bg: #1c2029;
    --chip-bg: #e4e7ef;
    --overlay-rgb: 16,19,26;
    --accent-rgb: 209,96,42;
    --accent-2-rgb: 168,71,26;
    --success-rgb: 31,138,76;
    --error-rgb: 198,47,47;
    --surface-2-rgb: 221,225,234;
    --neon-rgb: 0,180,255;
  }
  [data-skin="ambre"] {
    --bg: #1a1208;
    --panel: rgba(43, 30, 12, .82);
    --border: rgba(255, 191, 102, .22);
    --text: #fff3e0;
    --text-dim: #c7a883;
    --accent: #ffb74d;
    --accent-2: #ff7043;
    --error: #ff6b6b;
    --success: #9ccc65;
    --shared: #4dd0e1;
    --surface: #0d0904;
    --surface-2: #1c130a;
    --on-accent: #1a1208;
    /* Contrairement à --chip-bg, ce fond doit rester CLAIR : son icône suit
       --on-accent, sombre dans ce skin (voir plus haut) — les deux sombres
       ensemble rendaient le bouton play du mini-lecteur quasi invisible. */
    --btn-light-bg: #fff3e0;
    --chip-bg: #2a1d10;
    --overlay-rgb: 255,255,255;
    --accent-rgb: 255,183,77;
    --accent-2-rgb: 255,112,67;
    --success-rgb: 156,204,101;
    --error-rgb: 255,107,107;
    --surface-2-rgb: 28,19,10;
  }
  [data-skin="kawaii"] {
    --bg: #fff0f5;
    --panel: rgba(255, 255, 255, .85);
    --border: rgba(255, 140, 190, .28);
    --text: #5c3a52;
    --text-dim: #b088a8;
    --accent: #ff8fab;
    --accent-2: #b39ddb;
    --error: #ff6f91;
    --success: #7bc99a;
    --shared: #ffb26b;
    --surface: #ffe4ef;
    --surface-2: #ffd9ea;
    /* PAS blanc : --btn-light-bg est déjà blanc, les deux ensemble rendaient
       le badge de compte de titres (et le bouton play du mini-lecteur)
       illisibles — même piège que rencontré sur le skin Ambre. */
    --on-accent: #5c3a52;
    --btn-light-bg: #ffffff;
    --chip-bg: #ffe0ec;
    --overlay-rgb: 92,58,82;
    --accent-rgb: 255,143,171;
    --accent-2-rgb: 179,157,219;
    --success-rgb: 123,201,154;
    --error-rgb: 255,111,145;
    --surface-2-rgb: 255,217,234;
    /* Le cyan par défaut jure sur ce fond rose pastel — violet/fuchsia vif à
       la place, qui reste dans la palette du skin (proche de --accent-2). */
    --neon-rgb: 219,50,255;
  }
  * { box-sizing: border-box; }
  /* Neutralise le « tirer pour rafraîchir » de Chrome/Android : en pleine
     écoute, un geste de défilement un peu ample rechargeait la page et coupait
     le son (le lecteur ne survit pas à une navigation). Le défilement normal
     n'est pas affecté, seul le rebond en bout de course l'est. */
  html, body { overscroll-behavior-y: contain; }

  body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    background: radial-gradient(circle at 18% 0%, rgba(var(--accent-rgb),.12), transparent 30%), radial-gradient(circle at 85% 95%, rgba(var(--accent-2-rgb),.12), transparent 34%), var(--bg);
    color: var(--text);
    font-family: "Space Grotesk", -apple-system, sans-serif;
    padding: 28px 20px;
  }
  body::before { content:""; position:fixed; inset:0; pointer-events:none; opacity:.3; background-image:linear-gradient(rgba(105,156,255,.1) 1px,transparent 1px),linear-gradient(90deg,rgba(105,156,255,.1) 1px,transparent 1px); background-size:48px 48px; mask-image:radial-gradient(circle at center,black,transparent 80%); }
  .card {
    width: 100%;
    max-width: 520px;
    position:relative; background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 24px;
    padding: 36px;
    box-shadow: 0 24px 80px rgba(0,0,0,.48), inset 0 1px 0 rgba(var(--overlay-rgb),.08); backdrop-filter:blur(20px);
  }
  .card::before { content:"YTDL // MEDIA VAULT"; position:absolute; right:25px; top:17px; color:var(--accent); opacity:.72; font:500 .62rem "DM Mono",monospace; letter-spacing:.12em; }
  .top-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
  }
  .top-bar-actions { display: flex; align-items: center; gap: 16px; }
  /* L'avatar reste toujours pleinement visible ; c'est le bandeau de stockage,
     moins prioritaire ici, qui cède la place en premier sur petit écran. */
  .top-bar-actions .user-menu { flex-shrink: 0; }
  .logo-lockup {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 0 6px;
  }
  .logo-mark {
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    filter: drop-shadow(0 4px 10px rgba(92, 200, 255, 0.35));
  }
  h1 {
    margin: 0;
    font-size: 1.9rem; letter-spacing:-.07em;
    background: linear-gradient(100deg, var(--accent), var(--accent-2));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
  }
  p.subtitle {
    margin: 0;
    color: var(--text-dim);
    font-size: 0.9rem;
  }
  .user-menu { position: relative; }
  .user-btn {
    display: flex; align-items: center; gap: 8px; background: rgba(4,10,28,.7); border: 1px solid var(--border);
    border-radius: 999px; padding: 5px 12px 5px 5px; cursor: pointer; color: var(--text); font-size: 0.82rem;
  }
  .user-btn img { width: 28px; height: 42px; object-fit: cover; border-radius: 6px; }
  .user-dropdown {
    display: none; position: absolute; right: 0; top: calc(100% + 8px); background: var(--surface-2);
    border: 1px solid var(--border); border-radius: 10px; padding: 6px; min-width: 180px; z-index: 10;
    box-shadow: 0 12px 30px rgba(0,0,0,0.4);
  }
  .user-dropdown.visible { display: block; }
  .user-dropdown a, .user-dropdown button {
    display: block; width: 100%; text-align: left; background: none; border: none; color: var(--text);
    padding: 9px 10px; border-radius: 6px; font-size: 0.82rem; cursor: pointer; text-decoration: none;
  }
  .user-dropdown a:hover, .user-dropdown button:hover { background: rgba(var(--overlay-rgb),0.06); }

  /* Signal "nouveauté à découvrir" : lueur néon en boucle sur l'avatar tant
     qu'il reste au moins une notification en attente (voir
     pendingNotifications côté JS), posée aussi sur "Mon profil" dans le
     menu déroulant pour désigner où
     cliquer — juste la LUEUR, jamais la couleur du texte lui-même (voir
     text-shadow, pas color, ci-dessous). Couleur pilotée par --neon-rgb : un
     cyan fixe tranchait bien sur les skins sombres mais jurait sur le fond
     rose pastel du skin Kawaii — chaque skin définit sa propre teinte
     (--neon-rgb), cyan par défaut. */
  @keyframes neon-pulse {
    0%, 100% { box-shadow: 0 0 4px 1px rgba(var(--neon-rgb),.5), 0 0 10px 2px rgba(var(--neon-rgb),.3); }
    50% { box-shadow: 0 0 9px 3px rgba(var(--neon-rgb),.85), 0 0 22px 6px rgba(var(--neon-rgb),.55); }
  }
  .user-btn.neon-pending { animation: neon-pulse 1.8s ease-in-out infinite; border-color: rgba(var(--neon-rgb),.7); }
  .user-dropdown button.neon-pending {
    text-shadow: 0 0 6px rgba(var(--neon-rgb),.9), 0 0 14px rgba(var(--neon-rgb),.5);
    animation: neon-pulse 1.8s ease-in-out infinite;
  }
  /* Mise en avant ponctuelle d'une section du panneau "Mon profil" une fois
     celui-ci ouvert (ex. Apparence, pour signaler les nouveaux skins) :
     quelques pulsations puis plus rien — contrairement à .neon-pending qui
     boucle indéfiniment tant que la notification n'est pas vue. */
  @keyframes neon-flash {
    0%, 100% { box-shadow: none; }
    50% { box-shadow: 0 0 0 3px rgba(var(--neon-rgb),.8), 0 0 26px 6px rgba(var(--neon-rgb),.55); }
  }
  .profile-section.neon-flash {
    border-radius: 10px; animation: neon-flash 1.1s ease-in-out 4;
  }

  label { display: block; font-size: 0.85rem; color: var(--text-dim); margin-bottom: 8px; }

  .label-row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px; }
  .label-row label { margin-bottom: 0; }

  .url-rows { display: flex; flex-direction: column; gap: 8px; }
  .input-shell { position: relative; display: block; }
  .url-row input[type="url"] {
    width: 100%; padding: 12px 34px 12px 13px; border-radius: 10px; border: 1px solid var(--border);
    background: rgba(4,10,28,.7); color: var(--text); font-size: 0.9rem; outline: none; transition: border-color .2s, box-shadow .2s;
    display: block;
  }
  .url-row input[type="url"]:focus { border-color: var(--accent); box-shadow:0 0 0 3px rgba(var(--accent-rgb),.1); }
  .url-row input[type="url"]:disabled { opacity: 0.5; }
  .row-clear {
    display: none; position: absolute; right: 6px; top: 0; bottom: 0; margin: auto 0;
    width: 24px; height: 24px; border: none; border-radius: 6px; background: none;
    color: var(--text-dim); font-size: 0.95rem; cursor: pointer; line-height: 1;
  }
  .row-clear:hover { color: var(--error); background: rgba(var(--error-rgb),0.1); }
  .input-shell.has-value .row-clear { display: block; }
  .row-error { margin-top: 4px; font-size: 0.75rem; color: var(--error); }

  .row-preview {
    display: none; gap: 10px; align-items: center; padding: 8px 10px;
    border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2);
  }
  .url-row.verified .row-preview { display: flex; }
  .url-row.verified .row-input-wrap { display: none; }
  .row-thumb { width: 72px; height: 40px; object-fit: cover; border-radius: 6px; background: var(--surface); flex-shrink: 0; }
  .row-info { flex: 1; min-width: 0; }
  .row-title {
    font-size: 0.82rem; font-weight: 600; color: var(--text); overflow: hidden; text-overflow: ellipsis;
    white-space: nowrap;
  }
  .row-meta { margin-top: 2px; font-size: 0.72rem; color: var(--text-dim); }
  .row-remove {
    background: none; border: none; color: var(--text-dim); font-size: 0.9rem; cursor: pointer;
    padding: 4px 6px; flex-shrink: 0; border-radius: 6px;
  }
  .row-remove:hover { color: var(--error); background: rgba(var(--error-rgb),0.1); }
  .row-spinner {
    display: none; width: 18px; height: 18px; border: 2px solid var(--border); border-top-color: var(--accent);
    border-radius: 50%; animation: rowspin 0.7s linear infinite; flex-shrink: 0;
  }
  .url-row.checking .row-spinner { display: block; }
  .url-row.checking .row-input-wrap { opacity: 0.6; }
  @keyframes rowspin { to { transform: rotate(360deg); } }

  .switch-row {
    display: flex; align-items: center; gap: 10px; margin-top: 14px; font-size: 0.85rem; color: var(--text); cursor: pointer;
  }
  /* Interrupteur à glissière : checkbox natif masqué, un <span> dessine la
     piste + le bouton rond, translaté en CSS selon :checked. */
  .switch-row input { position: absolute; opacity: 0; width: 0; height: 0; }
  .switch-track {
    position: relative; flex-shrink: 0; width: 42px; height: 24px; border-radius: 999px;
    background: var(--border); transition: background 0.2s ease;
  }
  .switch-track::before {
    content: ''; position: absolute; top: 2px; left: 2px; width: 20px; height: 20px; border-radius: 50%;
    background: #fff; transition: transform 0.2s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.4);
  }
  .switch-row input:checked + .switch-track { background: var(--accent); }
  .switch-row input:checked + .switch-track::before { transform: translateX(18px); }
  .switch-hint { color: var(--text-dim); font-size: 0.78rem; font-weight: 400; }

  /* Boutons playlists/ajout : regroupés avec les onglets Audio/Clips sur la
     même ligne (voir .library-tabs plus bas) plutôt que dans une rangée à
     part — margin-left:auto sur ce conteneur les colle à droite, quel que
     soit le nombre d'onglets avant eux. */
  .toolbar-actions { display: flex; align-items: center; gap: 12px; margin-left: auto; flex-shrink: 0; }
  .download-fab {
    flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    width: 52px; height: 52px; padding: 0; border: none; border-radius: 50%;
    background: linear-gradient(100deg, var(--accent), var(--accent-2)); color: var(--on-accent);
    cursor: pointer; box-shadow: 0 10px 30px rgba(0,0,0,0.45);
  }
  .download-fab:hover { transform: translateY(1px); }
  /* Volontairement sobre à côté du bouton de téléchargement : c'est lui
     l'action principale de la page, la playlist reste secondaire. */
  .playlist-fab {
    flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    width: 46px; height: 46px; padding: 0; border-radius: 50%;
    background: var(--surface-2); border: 1px solid var(--border); color: var(--text-dim);
    cursor: pointer; transition: color 0.15s, border-color 0.15s;
  }
  .playlist-fab:hover { color: var(--text); }
  /* Loupe devant les onglets Audio/Clips : même glyphe (20px) et même ligne
     que leurs pastilles — celles-ci sont abaissées par le padding-top de
     .library-tab, la loupe doit suivre pour rester alignée. Le tracé occupe
     toute la boîte 24×24 pour peser autant que la note et l'écran. */
  .search-fab { margin-top: 6px; }
  .search-fab svg { width: 20px; height: 20px; }
  /* Une playlist est en cours de lecture : le bouton le signale. */
  .playlist-fab.active { color: var(--accent); border-color: var(--accent); }
  .download-sheet-overlay {
    position: fixed; inset: 0; z-index: 70; display: flex; align-items: flex-start; justify-content: center;
    background: rgba(0,0,0,0); opacity: 0; pointer-events: none;
    transition: opacity 0.3s ease, background 0.3s ease;
  }
  .download-sheet-overlay.visible { opacity: 1; pointer-events: auto; background: rgba(0,0,0,0.6); }
  .download-sheet {
    width: 100%; max-width: 520px; max-height: 88vh; overflow-y: auto;
    background: var(--panel); border: 1px solid var(--border); border-top: none;
    border-radius: 0 0 20px 20px; padding: 24px;
    transform: translateY(-100%); transition: transform 0.4s cubic-bezier(0.22, 1, 0.36, 1);
  }
  .download-sheet-overlay.visible .download-sheet { transform: translateY(0); }
  .download-sheet-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
  .download-sheet-header h2 { margin: 0; font-size: 1.05rem; }
  .download-sheet-close {
    background: none; border: none; color: var(--text-dim); font-size: 1.2rem; cursor: pointer;
    padding: 4px 8px; border-radius: 6px; line-height: 1;
  }
  .download-sheet-close:hover { color: var(--text); background: rgba(var(--overlay-rgb),0.08); }

  /* Panneau "Mon profil" : glisse depuis le bord droit de l'écran plutôt que
     d'ouvrir une modale centrée — même mécanique que la feuille de
     téléchargement (overlay + transform), mais horizontale. */
  .profile-drawer-overlay {
    position: fixed; inset: 0; z-index: 80; display: flex; justify-content: flex-end;
    background: rgba(0,0,0,0); opacity: 0; pointer-events: none;
    transition: opacity 0.3s ease, background 0.3s ease;
  }
  .profile-drawer-overlay.visible { opacity: 1; pointer-events: auto; background: rgba(0,0,0,0.6); }
  .profile-drawer {
    width: 100%; max-width: 360px; height: 100%; overflow-y: auto;
    background: var(--panel); border-left: 1px solid var(--border); padding: 24px;
    transform: translateX(100%); transition: transform 0.4s cubic-bezier(0.22, 1, 0.36, 1);
  }
  .profile-drawer-overlay.visible .profile-drawer { transform: translateX(0); }
  .profile-drawer-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
  .profile-drawer-header h2 { margin: 0; font-size: 1.05rem; }
  .profile-section { margin-bottom: 24px; }
  .profile-section-label { display: block; font-size: 0.82rem; color: var(--text-dim); margin-bottom: 10px; }
  .skin-grid { display: flex; gap: 10px; flex-wrap: wrap; }
  .skin-choice {
    display: flex; flex-direction: column; align-items: center; gap: 6px;
    background: rgba(var(--overlay-rgb),0.04); border: 1.5px solid var(--border); border-radius: 12px;
    padding: 10px 8px; cursor: pointer; color: var(--text-dim); font-size: 0.76rem;
    flex: 1 1 calc(50% - 5px); min-width: 70px;
    transition: border-color 0.15s, color 0.15s;
  }
  .skin-choice:hover { color: var(--text); }
  .skin-choice.active { border-color: var(--accent); color: var(--text); }
  /* Aperçus : vraies captures d'écran de chaque apparence (ui/skins/previews/). */
  .skin-swatch { width: 100%; aspect-ratio: 360 / 780; height: auto; border-radius: 10px; display: block; border: 1px solid rgba(var(--overlay-rgb),0.1); background-size: cover !important; background-position: top center !important; }
  .skin-choice { flex: 1 1 calc(33% - 8px); max-width: calc(33.33% - 7px); }
  .skin-swatch-sombre { background: url(ui/skins/previews/sombre.jpg?v=3); }
  .skin-swatch-clair { background: url(ui/skins/previews/clair.jpg?v=3); }
  .skin-swatch-ambre { background: url(ui/skins/previews/ambre.jpg?v=3); }
  .skin-swatch-kawaii { background: url(ui/skins/previews/kawaii.jpg?v=3); }
  .skin-swatch-orbite { background: url(ui/skins/previews/orbite.jpg?v=10); }
  .skin-swatch-platine { background: url(ui/skins/previews/platine.jpg?v=10); }
  .skin-swatch-heros { background: url(ui/skins/previews/heros.jpg?v=10); }
  .skin-swatch-galaxie { background: url(ui/skins/previews/galaxie.jpg?v=10); }
  .skin-swatch-retro { background: url(ui/skins/previews/retro.jpg?v=10); }
  .skin-swatch-arcade { background: url(ui/skins/previews/arcade.jpg?v=10); }
  .skin-swatch-nature { background: url(ui/skins/previews/nature.jpg?v=10); }
  .skin-swatch-muse { background: url(ui/skins/previews/muse.jpg?v=10); }
  .skin-swatch-aurore { background: url(ui/skins/previews/aurore.jpg?v=10); }
  .skin-swatch-bauhaus { background: url(ui/skins/previews/bauhaus.jpg?v=10); }
  .skin-swatch-carnet { background: url(ui/skins/previews/carnet.jpg?v=10); }
  .skin-swatch-laiton { background: url(ui/skins/previews/laiton.jpg?v=10); }
  .skin-swatch-sillon { background: url(ui/skins/previews/sillon.jpg?v=10); }
  /* Petits personnages décoratifs du skin Kawaii, posés uniquement dans des
     zones qui sont déjà vides le reste du temps — jamais au-dessus d'un
     contrôle réel. Masqués pour tout autre skin (voir data-skin plus haut) :
     .music-sidebar-bottom a margin-top:auto (voir music.css), donc tout ce
     qui se met avant elle dans la colonne flex récupère automatiquement
     l'espace resté vide entre la nav et elle. */
  .kawaii-sidebar-mascot, .kawaii-end-mascot { display: none; }
  [data-skin="kawaii"] .kawaii-sidebar-mascot {
    display: flex; flex: 1; align-items: center; justify-content: center; min-height: 111px;
  }
  .kawaii-pupil { transition: transform 0.1s ease-out; }
  .kawaii-cheek { transition: transform 0.3s ease; transform-origin: center; }
  .kawaii-mouth-open .kawaii-cheek-l { transform: translateX(-6px); }
  .kawaii-mouth-open .kawaii-cheek-r { transform: translateX(6px); }
  /* Queue : petits coups de balancier de temps en temps (voir setupKawaiiMascot,
     classe .wagging posée/retirée par JS), pas une boucle permanente. */
  .kawaii-tail { transform-box: fill-box; transform-origin: 85% 60%; }
  .kawaii-tail.wagging { animation: kawaii-tail-wag 0.5s ease-in-out 3; }
  @keyframes kawaii-tail-wag {
    0%, 100% { transform: rotate(0deg); }
    50% { transform: rotate(-14deg); }
  }
  /* Sieste (perte de focus/souris hors fenêtre, voir setupKawaiiMascot) :
     fondu-bascule entre deux illustrations distinctes (assis / couché sur le
     dos) plutôt qu'une simple rotation à 180° de la pose assise, qui donnait
     une tête inversée peu naturelle. La pose assise part en s'inclinant
     légèrement (petite bascule, pas un tour complet), la pose sieste arrive
     en grossissant depuis un peu plus petit (petit "plop"). Le "Zzz" est HORS
     des deux groupes pour toujours rester lisible à l'endroit. */
  .kawaii-pose-sitting, .kawaii-pose-asleep {
    transform-box: fill-box; transform-origin: 50% 85%;
    /* Assez lent pour qu'on ait le temps de VOIR le chat se relever au
       réveil, pas juste un flash entre les deux poses. */
    transition: opacity 1.1s ease, transform 1.1s ease;
  }
  .kawaii-pose-asleep { opacity: 0; transform: scale(0.85); pointer-events: none; }
  .kawaii-zzz { opacity: 0; transition: opacity 0.4s ease 0.2s; }
  .kawaii-sidebar-mascot.asleep .kawaii-pose-sitting { opacity: 0; transform: rotate(-8deg) scale(0.92); }
  .kawaii-sidebar-mascot.asleep .kawaii-pose-asleep { opacity: 1; transform: scale(1); }
  .kawaii-sidebar-mascot.asleep .kawaii-zzz { opacity: 1; animation: kawaii-zzz-drift 3s ease-in-out infinite; }
  /* Flotte doucement de droite à gauche façon "Zzz..." de bande dessinée —
     un aller-retour d'environ 5 caractères de large (~24px à cette taille de
     police), en boucle tant que le chat dort. */
  @keyframes kawaii-zzz-drift {
    0%, 100% { transform: translateX(-12px); }
    50% { transform: translateX(12px); }
  }
  /* La sidebar verticale n'existe qu'à partir de 761px (voir music.css) — en
     dessous, elle devient une barre horizontale sans le moindre espace mort
     à combler, d'où cette même limite ici. */
  @media (max-width: 760px) {
    [data-skin="kawaii"] .kawaii-sidebar-mascot { display: none; }
  }
  [data-skin="kawaii"] .kawaii-end-mascot {
    display: flex; flex-direction: column; align-items: center; gap: 8px;
    padding: 32px 0 12px; color: var(--text-dim); font-size: 0.8rem;
  }
  .profile-save-btn { width: 100%; }
  /* Bannière d'attente affichée le temps que les liens reçus par partage natif
     (Android/PWA share_target) récupèrent leur titre — sans elle, la file
     d'attente semble vide ou figée le temps de la vérification réseau. */
  .share-import-banner {
    display: none; align-items: center; gap: 10px; margin-bottom: 16px; padding: 10px 14px;
    background: rgba(124,131,253,0.1); border: 1px solid rgba(124,131,253,0.3); border-radius: 10px;
    font-size: 0.85rem; color: var(--text-dim);
  }
  .share-import-banner.visible { display: flex; }
  .share-import-spinner {
    width: 16px; height: 16px; border: 2px solid var(--border); border-top-color: var(--accent);
    border-radius: 50%; animation: rowspin 0.7s linear infinite; flex-shrink: 0;
  }

  button.primary {
    width: 100%; margin-top: 18px; padding: 14px; border: none; border-radius: 10px;
    background: linear-gradient(100deg, var(--accent), var(--accent-2));
    color: var(--on-accent); font-weight: 700; font-size: 0.95rem; cursor: pointer;
    transition: transform 0.15s, opacity 0.15s;
  }
  button.primary:hover { transform: translateY(-1px); }
  button.primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

  .preview-card {
    display: none; margin-top: 14px; gap: 12px; align-items: center; padding: 10px;
    border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2);
  }
  .preview-card.visible { display: flex; }
  .preview-thumb { width: 96px; height: 54px; object-fit: cover; border-radius: 6px; background: var(--surface); flex-shrink: 0; }
  .preview-info { min-width: 0; }
  .preview-title {
    font-size: 0.85rem; font-weight: 600; color: var(--text); overflow: hidden; text-overflow: ellipsis;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; line-height: 1.3;
  }
  .preview-meta { margin-top: 4px; font-size: 0.75rem; color: var(--text-dim); }

  .progress-block { margin-top: 20px; display: none; }
  .progress-block.visible { display: block; }

  .retry-block {
    display: none; margin-top: 16px; padding: 14px 16px; border-radius: 10px;
    border: 1px solid var(--border); background: var(--surface-2); text-align: center;
  }
  .retry-block.visible { display: block; }
  .retry-count {
    font-size: 1.6rem; font-weight: 700; color: var(--accent); font-variant-numeric: tabular-nums;
  }
  .retry-label { margin-top: 4px; font-size: 0.78rem; color: var(--text-dim); }
  .retry-proverb {
    margin-top: 10px; font-size: 0.82rem; color: var(--text); font-style: italic; line-height: 1.4;
  }
  .progress-row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px; font-size: 0.82rem; color: var(--text-dim); }
  .progress-label { flex: 1; margin-right: 12px; }
  .progress-percent { font-variant-numeric: tabular-nums; font-weight: 600; color: var(--text); }
  .progress-track {
    position: relative; width: 100%; height: 10px; border-radius: 999px; background: var(--surface);
    border: 1px solid var(--border); overflow: hidden;
  }
  .progress-fill {
    height: 100%; width: 0%; border-radius: 999px;
    background: linear-gradient(100deg, var(--accent), var(--accent-2));
    transition: width 0.3s ease; position: relative;
  }
  .progress-fill::after {
    content: ''; position: absolute; inset: 0;
    background: linear-gradient(90deg, transparent, rgba(var(--overlay-rgb),0.35), transparent);
    width: 40%; animation: shimmer 1.3s linear infinite;
  }
  @keyframes shimmer { 0% { transform: translateX(-100%); } 100% { transform: translateX(250%); } }
  .progress-fill.done { animation: none; }
  .progress-fill.done::after { display: none; }

  .status-line { margin-top: 14px; font-size: 0.85rem; color: var(--text-dim); display: none; }
  .status-line.visible { display: block; }
  .status-line.error { color: var(--error); white-space: pre-wrap; }
  .status-line.success { color: var(--success); }

  .toast {
    position: fixed; top: 18px; left: 50%; z-index: 300;
    display: flex; align-items: center; gap: 10px; max-width: calc(100% - 32px);
    padding: 13px 20px; border-radius: 12px; background: var(--panel); border: 1px solid var(--border);
    box-shadow: 0 16px 40px rgba(0,0,0,0.45); font-size: 0.85rem; color: var(--text);
    transform: translate(-50%, -24px); opacity: 0; pointer-events: none;
    transition: transform 0.45s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.3s ease;
  }
  .toast.visible { transform: translate(-50%, 0); opacity: 1; pointer-events: auto; }
  .toast-icon { flex-shrink: 0; display: flex; color: var(--accent); }
  .toast.success .toast-icon { color: var(--success); }
  .toast.error .toast-icon { color: var(--error); }
  .toast.success { border-color: rgba(var(--success-rgb),0.35); }
  .toast.error { border-color: rgba(var(--error-rgb),0.35); }

  .hint { margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border); font-size: 0.78rem; color: var(--text-dim); line-height: 1.5; }

  /* Bouton d'import de playlist : très discret en simple lien souligné, la
     fonctionnalité passait inaperçue (retour terrain — un lien de texte se
     perd dans une feuille par ailleurs pleine de champs). Une carte avec les
     logos des services reconnus au premier coup d'œil, sans avoir à lire. */
  .playlist-import-cta {
    display: flex; align-items: center; gap: 12px; width: 100%; text-align: left;
    background: var(--surface-2); border: 1.5px solid var(--border); border-radius: 12px;
    padding: 12px 14px; margin: 0 0 18px; cursor: pointer; transition: border-color 0.15s, transform 0.15s;
  }
  .playlist-import-cta:hover { border-color: var(--accent); transform: translateY(-1px); }
  .playlist-import-cta-icons { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
  .pi-brand-icon { border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.3); }
  .pi-icon-plus { font-size: 0.75rem; color: var(--text-dim); margin: 0 -1px; }
  .pi-import-icon { margin-left: 4px; padding-left: 8px; border-left: 1px solid var(--border); }
  .playlist-import-cta-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
  .playlist-import-cta-text strong { font-size: 0.88rem; color: var(--text); }
  .playlist-import-cta-text span { font-size: 0.74rem; color: var(--text-dim); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .playlist-import-cta-arrow { flex-shrink: 0; color: var(--text-dim); font-size: 1rem; transition: color 0.15s; }
  .playlist-import-cta:hover .playlist-import-cta-arrow { color: var(--accent); }
  #playlist-import-form label, #playlist-import-sheet-overlay > .download-sheet > label {
    display: block; font-size: 0.78rem; color: var(--text-dim); margin: 14px 0 6px;
  }
  #playlist-import-url {
    width: 100%; background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px;
    padding: 9px 10px; color: var(--text); font-size: 0.85rem; outline: none; box-sizing: border-box;
  }
  #playlist-import-url:focus { border-color: var(--accent); }

  /* Séparateur "ou" entre le lien de playlist et le CSV : une ligne pleine
     avec un mot au milieu plutôt que le tiret texte d'avant, pour marquer
     plus clairement qu'il s'agit de deux façons alternatives d'importer. */
  .playlist-import-divider {
    display: flex; align-items: center; gap: 12px; margin: 18px 0 12px;
    color: var(--text-dim); font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em;
  }
  .playlist-import-divider::before, .playlist-import-divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }

  /* Zone de dépôt du CSV : un <label for="playlist-import-file"> plutôt qu'un
     <input type="file"> nu — clique n'importe où dans la zone ouvre le
     sélecteur natif (comportement natif des labels), et la même zone sert de
     cible au glisser-déposer (voir setupPlaylistImport()). L'input reste
     dans le DOM mais visuellement masqué (pas display:none : certains
     navigateurs refusent d'ouvrir le sélecteur de fichier pour un input non
     rendu). */
  .playlist-import-dropzone {
    position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 10px; min-height: 110px; padding: 18px; box-sizing: border-box;
    border: 2px dashed var(--border); border-radius: 12px; background: rgba(var(--overlay-rgb),0.02);
    cursor: pointer; text-align: center; transition: border-color 0.2s ease, background 0.2s ease;
  }
  .playlist-import-dropzone:hover { border-color: var(--text-dim); background: rgba(var(--overlay-rgb),0.04); }
  .playlist-import-dropzone.dragover { border-color: var(--accent); background: rgba(var(--accent-rgb),0.08); }
  .playlist-import-dropzone-input { position: absolute; width: 1px; height: 1px; opacity: 0; overflow: hidden; }
  .playlist-import-dropzone-empty { display: flex; flex-direction: column; align-items: center; gap: 10px; }
  .playlist-import-dropzone-icon { color: var(--text-dim); transition: color 0.2s ease; }
  .playlist-import-dropzone.dragover .playlist-import-dropzone-icon { color: var(--accent); }
  .playlist-import-dropzone-text strong { display: block; font-size: 0.84rem; color: var(--text); font-weight: 600; }
  .playlist-import-dropzone-text span { display: block; font-size: 0.72rem; color: var(--text-dim); margin-top: 3px; }
  .playlist-import-dropzone-file { display: flex; align-items: center; gap: 10px; width: 100%; cursor: default; }
  .playlist-import-dropzone-file-icon { color: var(--accent); flex-shrink: 0; }
  .playlist-import-dropzone-filename {
    flex: 1; min-width: 0; font-size: 0.82rem; text-align: left;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .playlist-import-dropzone-clear {
    flex-shrink: 0; background: rgba(var(--overlay-rgb),0.08); border: none; color: var(--text-dim); cursor: pointer;
    width: 26px; height: 26px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 0.8rem; line-height: 1;
  }
  .playlist-import-dropzone-clear:hover { color: var(--text); background: rgba(var(--overlay-rgb),0.18); }
  .playlist-import-result { margin-top: 18px; }
  .playlist-import-group-header {
    font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-dim);
    margin: 16px 0 6px; padding-top: 10px; border-top: 1px solid var(--border);
  }
  .playlist-import-group-header:first-child { margin-top: 0; padding-top: 0; border-top: none; }
  .playlist-import-result-header {
    display: flex; align-items: center; gap: 10px; margin-bottom: 14px; font-size: 0.8rem; color: var(--text-dim);
  }
  .playlist-import-result-header input {
    flex: 1; background: rgba(var(--overlay-rgb),0.04); border: 1px solid var(--border); border-radius: 8px;
    padding: 8px 10px; color: var(--text); font-size: 0.85rem;
  }
  .playlist-import-tracks { max-height: 40vh; overflow-y: auto; margin: 0 -6px; padding: 0 6px; }
  .playlist-import-track {
    display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid rgba(var(--overlay-rgb),0.05);
  }
  .playlist-import-track-info { flex: 1; min-width: 0; }
  .playlist-import-track-title { font-size: 0.82rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .playlist-import-track-artist { font-size: 0.74rem; color: var(--text-dim); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .playlist-import-track-status {
    flex-shrink: 0; font-size: 0.7rem; color: var(--text-dim); min-width: 90px; text-align: right;
  }
  .playlist-import-track.done .playlist-import-track-status { color: var(--success); }
  .playlist-import-track.failed .playlist-import-track-status { color: var(--error); }
  .playlist-import-track.active .playlist-import-track-status { color: var(--accent); }

  /* Bannière de reprise cross-device : même gabarit que .toast (identité
     visuelle cohérente) mais fixée en bas, au-dessus du mini-lecteur, et
     avec des boutons d'action plutôt qu'un simple message qui s'efface tout
     seul — elle doit rester le temps que l'utilisateur décide. */
  .resume-banner {
    position: fixed; left: 50%; bottom: 18px; z-index: 250;
    display: flex; align-items: center; gap: 12px; max-width: calc(100% - 32px);
    padding: 12px 16px; border-radius: 12px; background: var(--panel); border: 1px solid var(--border);
    box-shadow: 0 16px 40px rgba(0,0,0,0.45); font-size: 0.85rem; color: var(--text);
    transform: translate(-50%, 16px); opacity: 0; pointer-events: none;
    transition: transform 0.3s ease, opacity 0.3s ease;
  }
  .resume-banner.visible { transform: translate(-50%, 0); opacity: 1; pointer-events: auto; }
  .resume-banner.mini-player-visible { bottom: 96px; } /* laisse la place au mini-lecteur ouvert */

  /* Cercle flottant d'import de playlist : même recette que .storage-donut
     (anneau conic-gradient + centre percé), avec le pourcentage GLOBAL
     (titres terminés / total) en texte au milieu. Coin opposé au
     resume-banner (centré) et aux toasts (haut) pour ne jamais se
     superposer à eux. */
  .playlist-import-mini {
    position: fixed; right: 20px; bottom: 24px; z-index: 250;
    width: 72px; height: 72px; border-radius: 50%; border: none; cursor: pointer;
    background: var(--panel); box-shadow: 0 10px 28px rgba(0,0,0,0.45);
    display: flex; align-items: center; justify-content: center;
    transition: bottom 0.2s ease, transform 0.15s ease;
  }
  .playlist-import-mini:hover { transform: scale(1.05); }
  .playlist-import-mini.mini-player-visible { bottom: 96px; }
  .playlist-import-mini-donut {
    position: absolute; inset: 0; border-radius: 50%;
    background: conic-gradient(var(--accent) 0% 0%, var(--border) 0% 100%);
  }
  .playlist-import-mini-donut::after {
    content: ''; position: absolute; inset: 8px; border-radius: 50%; background: var(--panel);
  }
  /* Enregistrées comme <percentage> (pas juste une chaîne quelconque) :
     condition pour que le navigateur sache interpoler leur valeur et anime
     donc les transitions ci-dessous en continu, plutôt que de sauter d'un
     palier à l'autre à chaque appel JS (le cas par défaut pour une
     custom property, et pour un dégradé en général). */
  @property --track-pct {
    syntax: '<percentage>';
    inherits: false;
    initial-value: 0%;
  }
  @property --storage-pct {
    syntax: '<percentage>';
    inherits: false;
    initial-value: 0%;
  }
  @property --storage-color {
    syntax: '<color>';
    inherits: false;
    initial-value: #2f7d4f;
  }
  /* Second anneau, plus fin et plus clair, niché dans le trou du premier :
     avancement du TÉLÉCHARGEMENT DU TITRE EN COURS — sans texte, seul le
     pourcentage global (anneau extérieur) est chiffré, pour ne pas avoir
     deux nombres à lire d'un coup d'œil. */
  .playlist-import-mini-donut-inner {
    position: absolute; inset: 10px; border-radius: 50%;
    --track-pct: 0%;
    background: conic-gradient(var(--accent-2) 0% var(--track-pct), transparent var(--track-pct) 100%);
    transition: --track-pct 0.5s ease-out;
  }
  .playlist-import-mini-donut-inner::after {
    content: ''; position: absolute; inset: 6px; border-radius: 50%; background: var(--panel);
  }
  /* Troisième anneau, plus foncé, niché encore plus au centre : taux de
     remplissage de l'espace de stockage du compte — pas juste une couleur
     fixe, sa teinte glisse du vert au rouge à mesure qu'il approche de la
     saturation (voir updateMiniStorageProgress), pour avertir que l'import
     risque de s'arrêter faute de place plutôt que d'échouer sans explication. */
  .playlist-import-mini-donut-storage {
    position: absolute; inset: 18px; border-radius: 50%;
    --storage-pct: 0%; --storage-color: #2f7d4f;
    background: conic-gradient(var(--storage-color) 0% var(--storage-pct), var(--border) var(--storage-pct) 100%);
    transition: --storage-pct 0.6s ease-out, --storage-color 0.6s ease-out;
  }
  .playlist-import-mini-donut-storage::after {
    content: ''; position: absolute; inset: 6px; border-radius: 50%; background: var(--panel);
  }
  /* pointer-events:none — sans ça, ce texte (dernier élément, donc au-dessus
     de tout visuellement) intercepterait le survol pile au centre, là où se
     trouve la zone de survol de l'anneau de stockage juste en dessous,
     empêchant sa bulle de s'afficher. */
  .playlist-import-mini-pct { position: relative; font-size: 0.6rem; font-weight: 700; color: var(--text); pointer-events: none; }

  /* Bulle au survol de l'anneau de stockage : plus lisible et plus rapide
     qu'une infobulle native (title), qui de toute façon se ferait
     concurrencer par celle du bouton entier ("cliquer pour rouvrir"). */
  /* Ancrée par son bord DROIT (jamais centrée) : le cercle flotte tout près
     du bord droit de l'écran (right: 20px), une bulle centrée déborderait
     donc hors champ côté droit et son texte se ferait couper — en grandissant
     uniquement vers la gauche depuis ce bord, elle reste entièrement visible
     quelle que soit la largeur de la fenêtre. */
  .playlist-import-mini-tooltip {
    position: absolute; bottom: calc(100% + 10px); right: -6px; left: auto;
    transform: translateY(4px);
    background: var(--panel); border: 1px solid var(--border); border-radius: 8px;
    padding: 6px 11px; font-size: 0.72rem; font-weight: 400; color: var(--text);
    white-space: nowrap; box-shadow: 0 10px 24px rgba(0,0,0,0.45);
    opacity: 0; pointer-events: none;
    transition: opacity 0.15s ease, transform 0.15s ease;
  }
  .playlist-import-mini-donut-storage:hover .playlist-import-mini-tooltip {
    opacity: 1; transform: translateY(0);
  }
  /* Départ masqué (avant le premier requestAnimationFrame qui le retire) :
     évite un flash à pleine taille avant que l'apparition en douceur ne
     démarre — voir minimizeOverlay(). */
  .playlist-import-mini.appearing { opacity: 0; transform: scale(0.3); }

  /* Effondrement de la feuille "Importer une playlist" en cercle flottant,
     déclenché dès le lancement du téléchargement (voir minimizeOverlay()) :
     --collapse-x/-y/-scale sont calculées en JS à partir des positions
     réelles de la feuille et du cercle cible (technique FLIP), pour que la
     feuille rétrécisse et glisse exactement jusqu'à l'emplacement du
     cercle plutôt que vers un coin approximatif. */
  #playlist-import-sheet-overlay .download-sheet {
    transform-origin: center center;
    transition: transform 0.5s cubic-bezier(0.5, 0, 0.3, 1), border-radius 0.5s ease, opacity 0.35s ease 0.15s;
  }
  #playlist-import-sheet-overlay.collapsing .download-sheet {
    transform: translate(var(--collapse-x, 0px), var(--collapse-y, 0px)) scale(var(--collapse-scale, 0.08));
    border-radius: 50%;
    opacity: 0;
    overflow: hidden;
  }
  #playlist-import-sheet-overlay.collapsing { background: rgba(0,0,0,0); }
  /* Le titre ne tient pas toujours dans la largeur de la bannière (surtout
     sur mobile, où elle doit rester étroite) — défilement continu façon
     ticker plutôt qu'une simple troncature "...", pour que le titre entier
     reste lisible sans agrandir la bannière. Le texte est dupliqué deux fois
     dans .resume-banner-marquee et la boucle décale de -50% : les deux
     copies bord à bord donnent un défilement sans saut visible au retour au
     début. N'anime que si le texte dépasse réellement (classe .scrolling
     posée par JS après mesure) — un titre court reste simplement statique. */
  .resume-banner-text { overflow: hidden; flex: 1; min-width: 0; }
  .resume-banner-marquee { display: inline-flex; white-space: nowrap; }
  .resume-banner-marquee span { padding-right: 28px; }
  .resume-banner-text:not(.scrolling) #resume-banner-text-b { display: none; }
  .resume-banner-text.scrolling .resume-banner-marquee {
    animation: resume-banner-scroll var(--marquee-duration, 10s) linear infinite;
  }
  @keyframes resume-banner-scroll {
    from { transform: translateX(0); }
    to { transform: translateX(-50%); }
  }
  .resume-banner-actions { display: flex; gap: 6px; flex-shrink: 0; }
  .resume-banner-btn {
    border: 1px solid var(--border); background: transparent; color: var(--text);
    border-radius: 7px; padding: 5px 10px; font-size: 0.76rem; cursor: pointer;
    min-height: 30px; line-height: 1.2;
    /* "Oui !" et "Non." n'ont pas la même longueur : sans largeur commune,
       les deux boutons n'étaient pas alignés l'un sur l'autre. */
    width: 52px; text-align: center;
  }
  .resume-banner-btn.primary {
    background: var(--accent); border-color: var(--accent); color: var(--on-accent);
    /* button.primary (règle globale, boutons pleine largeur) est plus
       spécifique que .resume-banner-btn seul (classe+élément > classe) et
       écrasait sinon toute cette taille ici — d'où le bouton "Oui !" plus
       large et plus haut que "Non." malgré la largeur commune voulue plus
       haut. On réaffirme donc TOUT ce que fixe button.primary, pas juste
       la largeur. */
    width: 52px; padding: 5px 10px; border-width: 1px; margin-top: 0;
    border-radius: 7px; font-size: 0.76rem; font-weight: 400; line-height: 1.2; min-height: 30px;
  }

  /* Onglets plutôt que tout empiler verticalement : sur mobile, la liste
     entière (audio + vidéos + partagé) devait être traversée au scroll rien
     que pour atteindre le bas de la page — plus une seule catégorie visible
     à la fois, on descend directement dans la liste qui intéresse. */
  .library-tabs {
    display: flex; gap: 4px; margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border);
    /* Onglets Audio/Clips + playlists/ajout : restent joignables au scroll,
       sans avoir à remonter en haut de la page — en plus du menu déjà sticky
       (barre du haut sur mobile). Fond opaque obligatoire : sans lui, les
       lignes de la bibliothèque défileraient visiblement en transparence
       derrière. */
    position: sticky; top: 0; z-index: 30; background: var(--surface-2);
    padding-bottom: 4px; box-shadow: 0 8px 14px -10px rgba(0,0,0,0.5);
  }
  .library-tab {
    position: relative; flex: 0 0 auto; display: flex; align-items: center;
    background: none; border: none; border-radius: 999px; padding: 6px 6px 2px 2px; cursor: pointer;
    color: var(--text-dim); font-size: 0.82rem; font-weight: 600;
    transition: color 0.25s ease;
  }
  /* La pastille ronde porte l'état actif (dégradé d'accent), le compte reste
     à côté en texte — mêmes dimensions que les boutons playlists/ajout. */
  .library-tab-icon {
    flex-shrink: 0; display: flex; align-items: center; justify-content: center;
    width: 46px; height: 46px; border-radius: 50%;
    background: var(--surface-2); border: 1px solid var(--border); color: var(--text-dim);
    transition: background 0.25s ease, color 0.25s ease, border-color 0.25s ease;
  }
  .library-tab-icon svg { width: 20px; height: 20px; }
  .library-tab:hover .library-tab-icon { color: var(--text); }
  .library-tab.active { color: var(--text); }
  .library-tab.active .library-tab-icon {
    color: var(--on-accent); border-color: transparent;
    background: linear-gradient(100deg, var(--accent), var(--accent-2));
  }
  .library-tab-badge {
    display: none; position: absolute; left: -2px; bottom: 0; z-index: 1;
    background: var(--error); color: #fff; font-size: 0.62rem; font-weight: 700;
    border-radius: 999px; padding: 1px 5px; line-height: 1.4; border: 2px solid var(--surface-2);
  }
  .library-tab-badge.visible { display: inline-block; }
  /* Compte total (permanent) devant le libellé de l'onglet — distinct du
     badge rouge ci-dessus qui, lui, ne signale que les nouveautés depuis la
     dernière consultation et se remet à zéro en ouvrant l'onglet. */
  /* Nombre de titres, en badge accroché en haut à droite de la pastille
     (façon compteur de notifications), toujours visible. Le liseré de la
     couleur du fond de la barre le détache du cercle qu'il chevauche. */
  .library-tab-count {
    position: absolute; top: 0; right: 0; z-index: 1;
    min-width: 18px; padding: 1px 5px; border-radius: 999px; box-sizing: border-box;
    background: var(--btn-light-bg); color: var(--on-accent); border: 2px solid var(--surface-2);
    font-size: 0.66rem; font-weight: 700; line-height: 1.4; text-align: center;
    font-variant-numeric: tabular-nums;
  }
  .library-tab-count:empty { display: none; }

  .library-section { display: none; margin-top: 16px; }
  .library-section.active-tab { display: block; animation: tabPanelIn 0.28s ease; }
  @keyframes tabPanelIn {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
  }
  .library-empty { padding: 18px 4px; text-align: center; color: var(--text-dim); font-size: 0.82rem; }
  /* Résultats YouTube proposés quand la recherche locale ne trouve rien —
     même famille visuelle que .library-item, distincts par un liseré à
     gauche (couleur d'accent) plutôt qu'un habillage entièrement différent :
     ça reste "la bibliothèque en train de chercher", pas un module à part. */
  .yt-suggest { display: none; margin-top: 4px; }
  .yt-suggest.visible { display: block; }
  .yt-suggest-header {
    font-size: 0.74rem; color: var(--text-dim); padding: 2px 4px 8px; text-transform: uppercase; letter-spacing: .04em;
  }
  .yt-suggest-notfound {
    font-size: 0.8rem; color: var(--text-dim); padding: 6px 4px 10px;
  }
  .yt-suggest-loading, .yt-suggest-empty {
    padding: 6px 4px 14px; text-align: center; color: var(--text-dim); font-size: 0.82rem;
  }
  /* Effet néon clignotant : signale sans ambiguïté qu'une recherche YouTube
     est en train de tourner en arrière-plan (contrairement à .yt-suggest-empty,
     état final "pas de résultat", qui ne doit pas clignoter). */
  .yt-suggest-loading {
    color: var(--accent);
    animation: neon-flicker 1.4s infinite;
  }
  @keyframes neon-flicker {
    0%, 18%, 22%, 25%, 53%, 57%, 100% {
      text-shadow: 0 0 4px var(--accent), 0 0 11px var(--accent), 0 0 19px var(--accent), 0 0 40px var(--accent-2);
      opacity: 1;
    }
    20%, 24%, 55% { text-shadow: none; opacity: 0.4; }
  }
  .yt-suggest-list { display: flex; flex-direction: column; gap: 6px; }
  /* Résultats trouvés chez un autre compte du serveur (voir
     library-find-elsewhere.php) : même famille visuelle que les résultats
     YouTube, liseré vert plutôt que violet pour bien distinguer "déjà là,
     gratuit" de "à retélécharger". */
  .yt-elsewhere { display: none; margin-top: 4px; }
  .yt-elsewhere.visible { display: block; }
  .yt-elsewhere-header {
    font-size: 0.74rem; color: var(--success); padding: 2px 4px 8px; text-transform: uppercase; letter-spacing: .04em;
  }
  .yt-elsewhere-list { display: flex; flex-direction: column; gap: 6px; }
  .yt-elsewhere-item {
    display: flex; align-items: center; gap: 10px; padding: 7px 8px; border-radius: 10px;
    background: var(--surface-2); border: 1px solid var(--border); border-left: 3px solid var(--success);
  }
  .yt-elsewhere-thumb { width: 48px; height: 36px; object-fit: cover; border-radius: 6px; background: var(--surface); flex-shrink: 0; }
  .yt-elsewhere-info { flex: 1; min-width: 0; }
  .yt-elsewhere-title {
    font-size: 0.82rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .yt-elsewhere-meta { margin-top: 2px; font-size: 0.72rem; color: var(--text-dim); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .yt-elsewhere-add-btn {
    flex-shrink: 0; background: none; border: 1px solid var(--success); color: var(--success); cursor: pointer;
    padding: 6px 12px; border-radius: 999px; font-size: 0.78rem; font-weight: 600; line-height: 1;
  }
  .yt-elsewhere-add-btn:hover { background: rgba(var(--success-rgb),0.14); }
  .yt-elsewhere-add-btn:disabled { opacity: 0.6; cursor: not-allowed; }
  .yt-suggest-item {
    display: flex; align-items: center; gap: 10px; padding: 7px 8px; border-radius: 10px;
    background: var(--surface-2); border: 1px solid var(--border); border-left: 3px solid var(--accent-2);
  }
  .yt-suggest-thumb { width: 48px; height: 36px; object-fit: cover; border-radius: 6px; background: var(--surface); flex-shrink: 0; }
  .yt-suggest-info { flex: 1; min-width: 0; }
  .yt-suggest-title {
    font-size: 0.82rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .yt-suggest-meta { margin-top: 2px; font-size: 0.72rem; color: var(--text-dim); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  /* Deux ronds plutôt qu'une pilule "Télécharger" : le format (audio/clip)
     se choisit directement ici, au clic, sans passer par la feuille de
     téléchargement — elle s'ouvre quand même ensuite, pour la progression. */
  .yt-suggest-dl-btn {
    flex-shrink: 0; width: 38px; height: 38px; padding: 0; border: none; border-radius: 50%;
    background: linear-gradient(100deg, var(--accent), var(--accent-2)); color: var(--on-accent);
    font-size: 0.62rem; font-weight: 700; line-height: 1.1; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: transform 0.15s;
  }
  .yt-suggest-dl-btn:hover { transform: translateY(-1px); }
  .yt-suggest-dl-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
  /* Progression affichée directement sous le titre du résultat en cours de
     téléchargement — plus de feuille de téléchargement pour ce chemin-là :
     rien ne doit recouvrir le clavier qu'on vient de faire disparaître. */
  .yt-suggest-progress { display: none; margin-top: 6px; }
  .yt-suggest-progress.visible { display: block; }
  .yt-suggest-progress-label {
    font-size: 0.7rem; color: var(--text-dim); margin-bottom: 3px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .yt-suggest-progress-track { height: 4px; border-radius: 999px; background: rgba(var(--overlay-rgb),0.1); overflow: hidden; }
  .yt-suggest-progress-fill {
    height: 100%; width: 0%; border-radius: 999px;
    background: linear-gradient(100deg, var(--accent), var(--accent-2));
    transition: width 0.3s ease;
  }
  /* Pré-écoute (30 s) d'un résultat YouTube, avant même de le télécharger. */
  .yt-suggest-preview-btn {
    flex-shrink: 0; width: 30px; height: 30px; padding: 0; border-radius: 50%;
    border: 1px solid var(--border); background: var(--chip-bg); color: var(--text);
    font-size: 0.75rem; cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: background 0.15s, color 0.15s, border-color 0.15s;
  }
  .yt-suggest-preview-btn:hover { background: rgba(var(--overlay-rgb),0.08); }
  .yt-suggest-preview-btn.playing { color: var(--accent); border-color: var(--accent); }
  .yt-suggest-preview-spinner {
    width: 14px; height: 14px; border: 2px solid var(--border); border-top-color: var(--accent);
    border-radius: 50%; animation: rowspin 0.7s linear infinite;
  }
  /* Menu "…" : classer un résultat hors musique (podcast/documentaire/
     tutoriel) au lieu des boutons MP3/Clip habituels — voir
     renderYoutubeSuggestions/startDirectDownload. Le bouton "…" et les 3
     choix occupent le MÊME emplacement (superposés, en absolute dans un
     wrap à taille fixe) : la classe .open sur le wrap pilote les deux à la
     fois — le "…" se réduit à 0 pendant que les 3 boutons (empilés en
     colonne) zooment depuis 0 pour prendre sa place ; au retrait de .open,
     tout se joue en sens inverse via les mêmes transitions. */
  .yt-suggest-other-wrap { position: relative; flex-shrink: 0; width: 30px; height: 30px; }
  .yt-suggest-other-btn {
    position: absolute; top: 0; left: 0; width: 30px; height: 30px; padding: 0; border-radius: 50%;
    border: 1px solid var(--border); background: var(--chip-bg); color: var(--text);
    font-size: 0.9rem; line-height: 1; cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: transform 0.2s ease, opacity 0.15s ease, background 0.15s, color 0.15s, border-color 0.15s;
  }
  .yt-suggest-other-btn:hover { background: rgba(var(--overlay-rgb),0.08); }
  .yt-suggest-other-btn:disabled { opacity: 0.5; cursor: not-allowed; }
  .yt-suggest-other-wrap.open .yt-suggest-other-btn { transform: scale(0); opacity: 0; pointer-events: none; }
  /* Colonne de 3 (Podcast, Documentaire, Tutoriel, dans cet ordre du DOM) où
     le bouton du MILIEU (Documentaire) doit occuper la place exacte du "…"
     — Podcast au-dessus, Tutoriel en dessous. Un bouton fait 30px + 6px de
     gap : décaler tout le conteneur d'un cran (30+6=36px) vers le haut fait
     tomber le 2e bouton exactement sur le "…" (top:0 à l'état fermé). */
  .yt-suggest-other-menu {
    display: flex; flex-direction: column; gap: 6px; position: absolute; top: -36px; right: 0;
    z-index: 20; pointer-events: none;
  }
  .yt-suggest-other-wrap.open .yt-suggest-other-menu { pointer-events: auto; }
  /* Chaque bouton part invisible et réduit à rien (scale 0, sur son propre
     centre, à la place exacte du "…"), puis "zoome" à taille normale avec
     un léger rebond (cubic-bezier > 1), décalé du haut vers le bas de la
     colonne — et inversement (sans rebond, plus sec) à la fermeture. Couleur
     volontairement différente de celle des ronds MP3/Clip (même dégradé
     accent/accent-2) : ce sont des choix de CLASSEMENT, pas de format, ils
     doivent se distinguer au premier coup d'œil — repris du vert "Hors
     musique" déjà utilisé pour cette catégorie dans le donut de stockage. */
  .yt-suggest-other-menu button {
    width: 30px; height: 30px; border-radius: 50%; padding: 0; border: none; cursor: pointer;
    background: var(--success); color: #fff;
    font-size: 0.56rem; font-weight: 700; line-height: 1.05;
    display: flex; align-items: center; justify-content: center;
    opacity: 0; transform: scale(0); pointer-events: none;
    transition: transform 0.2s cubic-bezier(.34, 1.56, .64, 1), opacity 0.15s ease;
  }
  .yt-suggest-other-wrap.open .yt-suggest-other-menu button {
    opacity: 1; transform: scale(1); pointer-events: auto;
  }
  .yt-suggest-other-wrap.open .yt-suggest-other-menu button:nth-child(1) { transition-delay: 0s; }
  .yt-suggest-other-wrap.open .yt-suggest-other-menu button:nth-child(2) { transition-delay: .05s; }
  .yt-suggest-other-wrap.open .yt-suggest-other-menu button:nth-child(3) { transition-delay: .1s; }
  .yt-suggest-other-menu button:hover { filter: brightness(1.08); }
  .library-title { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: var(--text-dim); margin-bottom: 10px; }
  .library-search-btn {
    flex-shrink: 0; background: none; border: none; color: var(--text-dim); cursor: pointer;
    padding: 3px; display: flex; align-items: center; border-radius: 6px;
  }
  .library-search-btn:hover { color: var(--accent); background: rgba(var(--overlay-rgb),0.08); }
  .library-title-text {
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 0 1 auto;
    opacity: 1; transition: opacity 0.25s ease;
  }
  /* Boutons de tri (barre d'onglets, à côté du compteur de clips) : mêmes
     pastilles rondes que les onglets, un peu plus petites. */
  .library-sort-group { display: flex; align-items: center; gap: 4px; margin-left: 6px; flex-shrink: 0; }
  .library-sort-btn {
    flex-shrink: 0; display: flex; align-items: center; justify-content: center;
    width: 40px; height: 40px; padding: 0; border-radius: 50%; cursor: pointer;
    background: var(--surface-2); border: 1px solid var(--border); color: var(--text-dim);
    transition: background 0.25s ease, color 0.25s ease, border-color 0.25s ease;
  }
  .library-sort-btn svg { width: 20px; height: 20px; }
  .library-sort-btn:hover { color: var(--text); }
  .library-sort-btn.active {
    color: var(--on-accent); border-color: transparent;
    background: linear-gradient(100deg, var(--accent), var(--accent-2));
  }
  /* Repliée au-dessus de la liste (juste sous le titre de section, avant le
     premier morceau) plutôt que dans l'en-tête : la loupe l'ouvre et la
     ferme, mais le champ lui-même reste au niveau de ce qu'il filtre.
     Collapse vertical (max-height) plutôt qu'horizontal maintenant que le
     bloc prend toute la largeur de la section, pas juste une place dans une
     rangée d'icônes. Repli en douceur plutôt qu'un display:none instantané,
     la classe .open étant posée directement dessus. */
  .library-search-shell {
    position: relative; width: 100%; max-height: 0; opacity: 0; overflow: hidden; margin: 0;
    transition: max-height 0.35s ease, opacity 0.25s ease, margin 0.3s ease;
  }
  .library-search-shell.open { max-height: 60px; opacity: 1; margin: 0 0 10px; }
  .library-search-input {
    width: 100%; background: var(--surface-2); border: 1px solid var(--border);
    border-radius: 8px; padding: 6px 28px 6px 10px; color: var(--text); font-size: 0.85rem; outline: none;
  }
  .library-search-input:focus { border-color: var(--accent); }
  .library-search-clear {
    display: none; position: absolute; right: 3px; top: 0; bottom: 0; margin: auto 0;
    width: 22px; height: 22px; border: none; border-radius: 6px; background: none;
    color: var(--text-dim); font-size: 0.85rem; cursor: pointer; line-height: 1;
  }
  .library-search-clear:hover { color: var(--error); background: rgba(var(--error-rgb),0.1); }
  .library-search-shell.has-value .library-search-clear { display: block; }
  /* Pas de fenêtre de défilement interne (max-height + overflow-y) : ça
     donnait l'impression d'une liste "coincée dans un cadre", avec sa propre
     barre de scroll étroite et peu naturelle au doigt — désormais la liste
     s'étire librement et c'est la page entière qui défile. */
  .library-list-row { display: flex; align-items: flex-start; gap: 2px; }
  .library-list { display: flex; flex-direction: column; flex: 1; min-width: 0; }
  /* Index alphabétique façon liste de contacts : "fixed" et centré
     verticalement sur l'écran (top:50% + translateY) plutôt que "sticky" —
     reste calé au milieu de l'écran quel que soit l'endroit où on a défilé
     dans la bibliothèque ou la hauteur réellement disponible autour (barre
     du haut, mini-lecteur en bas...), au lieu de s'étirer bord à bord et de
     dépendre de la mise en page environnante. */
  .library-az-index {
    display: none; flex-direction: column; align-items: center; justify-content: center;
    position: fixed; top: 50%; right: 6px; transform: translateY(-50%);
    width: 18px; max-height: 80vh; padding: 6px 0; gap: 1px;
    flex-shrink: 0; touch-action: none; user-select: none; -webkit-user-select: none;
    z-index: 200;
  }
  .library-az-index.visible { display: flex; }
  .library-az-char {
    font-size: 9px; line-height: 1; color: var(--text-dim); cursor: pointer;
    padding: 1px 3px; border-radius: 3px;
  }
  .library-az-char.active { color: var(--on-accent); background: var(--accent); }
  /* Bulle agrandie : la lettre en cours de survol/glissé, bien plus grande,
     au centre de l'écran — indispensable sur mobile, où la colonne
     elle-même est trop étroite pour lire ce qu'on a sous le doigt pendant
     qu'on glisse dessus. */
  .library-az-bubble {
    position: fixed; top: 50%; left: 50%; z-index: 400;
    width: 92px; height: 92px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: var(--accent); color: var(--on-accent); font-size: 2.4rem; font-weight: 700;
    box-shadow: 0 18px 44px rgba(0,0,0,0.5);
    opacity: 0; pointer-events: none;
    transform: translate(-50%, -50%) scale(0.6);
    transition: opacity 0.12s ease, transform 0.12s ease;
  }
  .library-az-bubble.visible { opacity: 1; transform: translate(-50%, -50%) scale(1); }
  /* Infobulle maison (voir music.js) : remplace le title natif, trop lent à
     apparaître et impossible à styler, sur tout élément qui en porte un.
     Un seul nœud partagé, repositionné à chaque survol ; la petite pointe
     (::after) change de côté quand la bulle doit passer sous l'élément. */
  .ui-tooltip {
    position: fixed; z-index: 500; left: 0; top: 0;
    max-width: 260px; padding: 7px 11px; border-radius: 9px;
    background: var(--chip-bg); color: var(--text); border: 1px solid var(--border);
    box-shadow: 0 10px 28px rgba(0,0,0,0.5), 0 0 0 1px rgba(var(--overlay-rgb),0.03) inset;
    font-size: 12px; font-weight: 500; line-height: 1.35; letter-spacing: .01em; text-align: center;
    pointer-events: none; opacity: 0; transform: translateY(4px) scale(0.96);
    transition: opacity 0.14s ease, transform 0.14s ease;
  }
  .ui-tooltip.visible { opacity: 1; transform: translateY(0) scale(1); }
  .ui-tooltip::after {
    content: ''; position: absolute; left: var(--tip-x, 50%); width: 9px; height: 9px;
    background: var(--chip-bg); border: 1px solid var(--border); border-top: none; border-left: none;
    transform: translateX(-50%) rotate(45deg);
    bottom: -5px;
  }
  .ui-tooltip.below::after { bottom: auto; top: -5px; transform: translateX(-50%) rotate(-135deg); }
  .ui-tooltip.below { transform: translateY(-4px) scale(0.96); }
  .ui-tooltip.below.visible { transform: translateY(0) scale(1); }
  .ui-tooltip strong { color: var(--accent); font-weight: 700; }
  /* Mode PC (souris) : la colonne peut s'étirer sur toute la hauteur de la
     fenêtre et les caractères peuvent être plus grands/espacés puisqu'il n'y
     a pas de doigt qui les recouvre — inutile de rester compact comme sur
     mobile. Le zoom se fait alors directement sur le caractère survolé
     (scale en place) plutôt que via la grosse bulle centrale, réservée au
     tactile où la colonne reste minuscule. */
  @media (hover: hover) and (pointer: fine) {
    .library-az-index {
      top: 0; bottom: 0; transform: none; height: 100vh; max-height: none;
      width: 24px; padding: 16px 0; gap: 5px; justify-content: space-evenly;
    }
    .library-az-char {
      font-size: 13px; padding: 2px 5px;
      transition: transform 0.12s ease, color 0.12s ease;
    }
    .library-az-char.active { transform: scale(1.7); }
  }
  .library-item {
    display: flex; align-items: flex-start; gap: 10px; padding: 7px 8px; border-radius: 10px;
    border: 1px solid transparent; background: var(--surface-2); cursor: pointer; text-align: left;
    width: 100%; color: var(--text); font: inherit;
    opacity: 1; filter: blur(0); max-height: 200px; overflow: hidden; margin-bottom: 6px;
    transition: opacity 0.25s ease, filter 0.25s ease, max-height 0.3s ease, padding 0.3s ease, margin 0.3s ease;
  }
  /* Glisser vers la gauche (tactile) révèle un panneau de 7 actions derrière
     (paroles, téléchargement, YouTube, puis Supprimer en dernier — le geste
     le plus destructeur reste le plus loin, tout au bord) — voir
     setupSwipeToDelete(). .swipeable ajoute juste le repère de position dont
     le panneau absolu a besoin ; le contenu existant (vignette + corps) est
     réenveloppé dans .library-item-swipe-content par le JS, jamais dans le
     gabarit HTML — un seul endroit à maintenir plutôt que 4 (bibliothèque
     audio, clips, résultats croisés, vue playlist). */
  .library-item.swipeable { position: relative; touch-action: pan-y; }
  .library-item-swipe-content {
    display: flex; align-items: flex-start; gap: 10px; min-width: 0;
    /* Opaque, PAS "inherit" : .library-item lui-même est transparent (zébrage
       via music.css), donc hériter laissait le panneau rouge visible en
       permanence au lieu de rester masqué tant qu'on n'a pas glissé. */
    background: var(--bg); position: relative; z-index: 1;
    /* .library-item-swipe-action se cale en "right:0" sur le bord RÉEL de la
       ligne (avant padding), alors qu'un enfant normal comme celui-ci
       s'arrête AU padding — d'où ces marges négatives + le même padding
       repris en propre, pour venir combler exactement cet écart et ne rien
       laisser dépasser au repos. Les deux jeux de valeurs reprennent ceux de
       .library-item dans music.css (desktop, puis mobile ci-dessous). */
    margin: -12px -8px; padding: 12px 8px; width: calc(100% + 16px);
  }
  .library-item-swipe-action {
    position: absolute; top: 0; right: 0; bottom: 0; display: flex;
  }
  .library-item-swipe-action-btn {
    width: 56px; height: 100%; display: flex; align-items: center; justify-content: center;
    border: none; cursor: pointer; padding: 0; font-size: 1.15rem;
    background: var(--text); color: var(--bg);
  }
  .library-item-swipe-action-btn svg { width: 20px; height: 20px; }
  /* Suppression/retrait : seul bouton destructeur du panneau, toujours au
     bord (dernier du DOM) — couleur distincte pour rester repérable même
     après un glissement partiel. */
  .library-item-swipe-action-btn.library-item-swipe-delete { background: var(--error); color: #fff; }
  /* Note/playlist/partage rejoignent le panneau (voir setupSwipeToDelete) —
     même traitement "actif" que sur la ligne normale (étoile jaune notée,
     vert déjà dans une playlist), pour ne rien perdre de ce repère visuel
     en le déplaçant ici. */
  .library-item-swipe-action-btn.library-item-swipe-rated { color: #f5c542; }
  .library-item-swipe-action-btn.library-item-swipe-in-playlist { color: var(--success); }
  @media (max-width: 760px) {
    .library-item-swipe-content { margin: -10px -4px; padding: 10px 4px; width: calc(100% + 8px); }
    /* 7 boutons désormais (voir setupSwipeToDelete) : un peu plus étroits
       qu'en 4 pour ne pas exiger un glissement complet de l'écran. */
    .library-item-swipe-action-btn { width: 46px; }
  }
  /* Modale de note (voir openRatingModal) : plein écran, geste isolé — tout
     le reste de l'appli devient inatteignable tant qu'elle est ouverte
     (comme les autres .modal-overlay du fichier), et toucher le fond
     l'assombrissant referme sans rien changer. Couleurs volontairement
     FIXES (jamais var(--panel)/var(--border), qui suivent le skin actif) :
     avec le skin Kawaii (fond clair), un panneau clair + étoiles à peine
     plus foncées que leur fond devenait illisible — un fond sombre fixe et
     des étoiles or/blanc cassé restent lisibles quel que soit le skin. */
  .rating-modal-overlay {
    position: fixed; inset: 0; z-index: 700; display: flex; align-items: center; justify-content: center;
    background: rgba(0,0,0,0); opacity: 0; pointer-events: none;
    transition: opacity 0.2s ease, background 0.2s ease;
  }
  .rating-modal-overlay.visible { opacity: 1; pointer-events: auto; background: rgba(0,0,0,0.7); }
  .rating-modal {
    background: #14161c; border: 1px solid rgba(255,255,255,0.14); border-radius: 18px;
    padding: 28px 22px; text-align: center; max-width: 320px; width: calc(100% - 48px);
    box-shadow: 0 20px 50px rgba(0,0,0,0.5);
    transform: scale(0.9); transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
  }
  .rating-modal-overlay.visible .rating-modal { transform: scale(1); }
  .rating-modal-title {
    color: #fff; font-size: 0.92rem; font-weight: 600; margin-bottom: 20px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .rating-modal-stars { display: flex; justify-content: center; gap: 8px; }
  .rating-modal-stars button {
    background: none; border: none; padding: 4px; font-size: 2rem; line-height: 1; cursor: pointer;
    color: rgba(255,255,255,0.22); transition: color 0.15s ease, transform 0.15s ease;
  }
  .rating-modal-stars button.filled { color: #ffcc33; }
  .rating-modal-stars button:hover, .rating-modal-stars button:active { transform: scale(1.15); }
  /* Démo du geste de glissement, déclenchée au tap sur l'avatar (mobile,
     voir setupSwipeHintAvatar) tant qu'elle n'a pas déjà été vue une fois
     sur cet appareil — jamais automatique. Pilotée en JS (playSwipeHintDemo)
     plutôt qu'en @keyframes à durée fixe : elle rejoue le VRAI glissement
     (même transform que .library-item-swipe-content au doigt) 3 fois de
     suite, chaque fois plus lentement qu'un vrai geste, pour laisser le
     temps de VOIR — pas juste une glow décorative à côté du vrai panneau. */
  .library-item.swipe-hint-active { animation: swipeHintGlow 1.6s ease-in-out infinite; }
  @keyframes swipeHintGlow {
    0%, 100% { box-shadow: none; }
    50% { box-shadow: 0 0 10px 2px rgba(var(--accent-rgb),0.9), 0 0 22px 7px rgba(var(--accent-2-rgb),0.65); }
  }
  /* Pouce réaliste (silhouette + ongle, vu de dessus) plutôt qu'un simple
     rond : reconnaissable comme un doigt, pas juste un curseur générique.
     VERTICAL au repos (l'ongle en haut) — .dragging (posée/retirée en JS,
     3 fois) le fait à la fois glisser vers la gauche ET pivoter de 45° vers
     la gauche, comme un vrai pouce qui tire l'écran vers soi. Pivot proche
     de la base (transform-origin 50% 80%), pas du centre — c'est la BASE du
     pouce qui reste à peu près fixe pendant le geste, pas son milieu. */
  .swipe-hint-thumb {
    position: absolute; top: 50%; right: 28px; width: 34px; height: 46px;
    transform: translateY(-50%) rotate(0deg); transform-origin: 50% 80%;
    z-index: 30; pointer-events: none; opacity: 0;
    filter: drop-shadow(0 3px 6px rgba(0,0,0,0.35));
    transition: opacity 0.4s ease;
  }
  .swipe-hint-thumb.visible { opacity: 1; }
  .swipe-hint-thumb svg { width: 100%; height: 100%; display: block; }
  .swipe-hint-toast {
    position: fixed; left: 50%; bottom: 96px; transform: translateX(-50%) translateY(16px);
    max-width: min(92vw, 360px); background: var(--text); color: var(--bg);
    padding: 12px 16px; border-radius: 12px; font-size: 0.82rem; line-height: 1.4; text-align: center;
    box-shadow: 0 0 18px 3px rgba(var(--accent-rgb),0.55), 0 8px 20px rgba(0,0,0,0.25);
    z-index: 1000; opacity: 0; transition: opacity 0.3s ease, transform 0.3s ease; pointer-events: none;
  }
  .swipe-hint-toast.visible { opacity: 1; transform: translateX(-50%) translateY(0); }
  /* Néon sur l'avatar mobile tant que la démo n'a pas été vue — même
     animation que .neon-pending (notifications serveur) mais découplée : ce
     signal est purement local (localStorage), jamais lié à
     pendingNotifications/acknowledgeNotification. */
  .mobile-user-menu .user-btn.swipe-hint-glow { animation: neon-pulse 1.8s ease-in-out infinite; border-color: rgba(var(--neon-rgb),.7); }
  /* "En lecture" : .library-item prend normalement ce même dégradé (voir
     music.css) pour que la ligne active ressorte, et ses icônes passent en
     --on-accent pour rester lisibles PAR-DESSUS. Le fond opaque fixe de
     .library-item-swipe-content ci-dessus masquait ce dégradé — icônes
     sombres sur fond resté sombre, donc invisibles. Reproduit ici le même
     dégradé pour que le contraste tienne à nouveau. */
  .library-item.playing .library-item-swipe-content {
    background: linear-gradient(100deg, var(--accent), var(--accent-2));
  }
  .library-item:hover { border-color: var(--border); }
  /* Fond alterné (zébrage) : sans lui, les morceaux se suivaient sans repère
     visuel entre chaque ligne, difficiles à distinguer les uns des autres
     dans une longue liste. Ni un rgba(var(--overlay-rgb),X) (une
     transparence, explicitement écartée), ni --surface seule (une couleur
     solide, mais trop proche de --surface-2 dans chaque skin — l'écart
     entre les deux a été pensé pour une subtile mise en profondeur, pas
     pour trancher au premier coup d'œil) : color-mix produit UNE couleur
     opaque, réellement différente et jamais transparente, en mélangeant une
     part de --text (donc à coup sûr contrastée dans CE skin précis,
     puisque c'est déjà la couleur de référence pour la lisibilité) dans
     --surface-2. */
  /* La VRAIE couche visible du bloc vignette+titre n'est pas .library-item
     lui-même mais .library-item-swipe-content, posé PAR-DESSUS avec un fond
     opaque (voir setupSwipeToDelete/son commentaire plus bas) pour cacher
     le panneau d'actions tant qu'on n'a pas glissé — colorer .library-item
     ne changeait donc que ce que ce calque opaque laisse dépasser sur les
     bords (le fin liseré entre deux lignes), jamais le bloc lui-même. Cible
     corrigée : .library-item-swipe-content, sélectionné via le
     nth-child(even) DU PARENT. color-mix() (essayé avant) était par
     ailleurs silencieusement ignoré sur le navigateur réel testé en
     production — couleurs FIXES (calculées à la main, formule 70/30) à la
     place, un simple rgb() littéral fonctionnant partout. Sélecteur
     combinant [data-skin] (sur <html>) ET .music-app (sur <body>) : plus
     spécifique que toute règle .music-app seule dans music.css.
     :not(.playing) : sans lui, la spécificité (5 niveaux) de cette règle
     l'aurait fait gagner sur .library-item.playing .library-item-swipe-
     content (3 niveaux, le dégradé accent de la ligne en cours) chaque
     fois que CETTE ligne précise tombait sur un rang pair — perdant le
     surlignage "en cours" derrière une couleur de zébrage plate. */
  [data-skin="sombre"] .music-app .library-item:nth-child(even):not(.playing) .library-item-swipe-content,
  html:not([data-skin]) .music-app .library-item:nth-child(even):not(.playing) .library-item-swipe-content { background: #595856; }
  [data-skin="clair"] .music-app .library-item:nth-child(even):not(.playing) .library-item-swipe-content { background: #a1a5ad; }
  [data-skin="ambre"] .music-app .library-item:nth-child(even):not(.playing) .library-item-swipe-content { background: #605646; }
  [data-skin="kawaii"] .music-app .library-item:nth-child(even):not(.playing) .library-item-swipe-content { background: #cea9bc; }
  /* Filtre de recherche : flou + fondu + repli en douceur plutôt qu'un
     display:none instantané — l'item reste dans le flux (juste réduit à
     zéro), pas besoin de recalculer le DOM à chaque frappe. */
  .library-item.search-hidden {
    opacity: 0; filter: blur(6px); max-height: 0; padding-top: 0; padding-bottom: 0; margin-bottom: 0;
    border-width: 0; pointer-events: none;
  }
  /* Fond plein (même dégradé que les onglets/boutons actifs de l'appli),
     pas une simple teinte : le morceau en cours doit vraiment trancher avec
     le reste de la liste, pas juste s'en distinguer un peu au look attentif. */
  .library-item.playing {
    border-color: transparent; background: linear-gradient(100deg, var(--accent), var(--accent-2));
  }
  .library-item.playing .library-item-title,
  .library-item.playing .library-shared-by,
  .library-item.playing .library-download-btn { color: var(--on-accent); }
  .library-item.playing .library-download-btn:hover { background: rgba(0,0,0,0.12); }
  /* Colonne à droite de la vignette : titre en haut (aligné au sommet de
     l'image), puis artiste, puis les icônes d'action collées juste en
     dessous (gap réduit) plutôt que renvoyées vers le bas de la vignette —
     priorité à une rangée aussi basse que possible plutôt qu'à l'alignement
     avec le bas de l'image. */
  .library-body { display: flex; flex-direction: column; gap: 3px; flex: 1; min-width: 0; }
  .library-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .library-thumb { width: 48px; height: 48px; object-fit: cover; border-radius: 6px; background: var(--surface); flex-shrink: 0; display: block; }
  /* Bouton de lecture superposé à la vignette (plutôt qu'une colonne à part
     à sa gauche) : lecture/pause activables directement au clic sur l'image
     du morceau. Semi-transparent au repos pour ne pas masquer la pochette,
     opaque au survol pour inviter au clic — indépendant de l'état lecture/
     pause, qui ne change que le glyphe (▶ / ❚❚, voir syncLibraryPlayingState).
     Pas de largeur/hauteur fixes ici : en tant qu'enfant flex sans taille
     propre, ce conteneur se cale tout seul sur celle de .library-thumb
     (48px, 44px ou 32px selon le thème/la largeur d'écran — voir music.css
     et le media query mobile plus bas), sans avoir à dupliquer chaque
     valeur pour chaque taille d'écran. */
  .library-thumb-wrap { position: relative; flex-shrink: 0; cursor: pointer; }
  .library-info { min-width: 0; }
  /* Regroupe le titre et l'icône de renommage mobile (voir .library-mobile-
     rename-btn) — display:contents par défaut : sur desktop, .library-item-
     title reste un enfant direct de .library-info exactement comme avant ce
     regroupement (le renommage y passe par .library-rename-btn, dans
     .library-actions, inchangé). Devient une vraie ligne flex UNIQUEMENT en
     mobile (voir music.css), seul contexte où l'icône est physiquement
     affichée à côté du titre. */
  .library-title-row { display: contents; }
  /* Masqué par défaut (desktop) — voir music.css pour son affichage réel en
     mobile, à côté du titre plutôt que dans .library-actions (repli tactile
     par glissement, moins immédiat pour une action admin fréquente). */
  .library-mobile-rename-btn { display: none; }
  .library-item-title {
    font-size: 0.82rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  /* Artiste/titre en 3 <span> (voir renderLibraryItemTitle) plutôt qu'un seul
     texte "Artiste — Titre" : permet de les restyler indépendamment sur
     mobile (titre agrandi, artiste sur sa propre ligne, en plus petit — voir
     le media query dans music.css) sans toucher au rendu desktop, qui reste
     la même ligne unique qu'avant (ces span sont inline par défaut, le texte
     s'enchaîne donc exactement comme le faisait l'ancien texte combiné). */
  .library-item-title-text, .library-item-artist { display: inline; }
  .library-item-title-sep { color: var(--text-dim); }
  /* Renommage manuel (admin) : remplace .library-item-title EN PLACE, même
     taille/police qu'un titre affiché — pas un champ de formulaire qui
     détonnerait dans la ligne, un vrai "renommage sur place" façon
     Explorateur Windows. */
  .library-rename-input {
    font: inherit; font-size: 0.82rem; font-weight: 600; color: var(--text);
    background: rgba(var(--overlay-rgb),0.08); border: 1px solid var(--accent);
    border-radius: 4px; padding: 1px 4px; width: 100%; min-width: 0;
  }
  .library-rename-input:focus { outline: none; }
  /* Ligne titre des résultats "trouvés dans l'autre type" (voir
     applyCrossTypeSearch) : icône + titre, pour qu'une recherche mélangeant
     morceaux et clips reste lisible d'un coup d'œil — sans toucher à
     .library-item-title elle-même (utilisée telle quelle par les items
     natifs, sans wrapper). */
  .library-cross-type-row { display: flex; align-items: center; gap: 5px; min-width: 0; }
  .library-cross-type-row .library-item-title { flex: 1; min-width: 0; }
  .library-type-badge { flex-shrink: 0; display: inline-flex; color: var(--accent); }
  .library-play-icon {
    position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem; line-height: 1; color: #fff; text-shadow: 0 1px 4px rgba(0,0,0,0.7);
    background: rgba(0,0,0,0.25); border-radius: 6px;
    opacity: 0.25; transition: opacity 0.2s ease;
  }
  .library-thumb-wrap:hover .library-play-icon { opacity: 1; }
  .library-download-btn {
    flex-shrink: 0; background: none; border: none; color: var(--text-dim); font-size: 0.95rem;
    cursor: pointer; padding: 6px; border-radius: 6px; line-height: 1; display: flex; align-items: center;
  }
  .library-download-btn:hover { color: var(--accent); background: rgba(var(--overlay-rgb),0.08); }
  /* Bulle flottante pour data-hint (voir showIconHint/hideIconHint) : un
     seul élément réutilisé pour toutes les icônes, positionné en JS via
     getBoundingClientRect — jamais de position:absolute relative à l'icône
     elle-même (voir le commentaire JS, coupé par overflow:hidden sinon). */
  .icon-hint-float {
    position: fixed; transform: translate(-50%, -100%);
    background: var(--text); color: var(--bg); padding: 6px 10px; border-radius: 6px;
    font-size: 11px; font-weight: 600; white-space: nowrap; line-height: 1;
    box-shadow: 0 6px 16px rgba(0,0,0,0.3);
    opacity: 0; pointer-events: none; transition: opacity 0.12s ease;
    z-index: 500;
  }
  .icon-hint-float.visible { opacity: 1; }
  /* Icône de paroles coloriée : distingue d'un coup d'œil un morceau dont
     les paroles défilent en rythme (lrclib.net) d'un simple texte brut. */
  .library-lyrics-btn.lyrics-synced { color: var(--accent); }
  .library-lyrics-btn.lyrics-synced:hover { background: rgba(255,132,75,0.14); }
  /* Vert = ce titre figure déjà dans au moins une playlist. Visible sans avoir
     à ouvrir la fenêtre d'ajout, sur les deux onglets. */
  .library-playlist-btn.in-playlist { color: var(--success); }
  .library-playlist-btn.in-playlist:hover { color: var(--success); background: rgba(var(--success-rgb),0.14); }
  /* Le nombre de jours restants avant purge s'affiche directement à côté de
     l'icône qui sert à supprimer : plus besoin d'une seconde icône poubelle
     séparée (repérage) — ambiguë avec celle du bouton de suppression réel. */
  /* Largeur minimale commune : le dernier bouton de la ligne porte la même
     structure (poubelle + jours restants) qu'il s'agisse de mes fichiers ou de
     ceux qu'on m'a partagés, mais le nombre de jours varie d'un chiffre. Sans
     ce plancher, les icônes se décalent d'une ligne à l'autre. */
  .library-delete-btn { min-width: 52px; justify-content: center; }
  .library-delete-btn { gap: 3px; font-size: 0.68rem; font-variant-numeric: tabular-nums; }
  .library-delete-btn:hover { color: var(--error); background: rgba(var(--error-rgb),0.1); }
  .library-delete-btn.warning { color: var(--error); }
  /* Redondantes au doigt : glisser la ligne vers la gauche révèle déjà ces
     7 actions (paroles, téléchargement, YouTube, note, playlist, partage,
     Supprimer/Retirer — voir setupSwipeToDelete) — gain de place dans une
     rangée déjà chargée, et découverte du geste favorisée (voir
     setupSwipeHintAvatar ci-dessous). Restent visibles à la souris/trackpad
     (pointer fin), seul terrain où ce geste de glissement est désactivé. */
  @media (pointer: coarse) {
    .library-delete-btn, .library-lyrics-btn, .library-source-link, .library-download-link,
    .library-playlist-btn, .library-share-btn {
      display: none;
    }
  }
  .library-share-btn:hover { color: var(--accent-2); background: rgba(var(--accent-2-rgb),0.1); }
  .library-source-link:hover { color: var(--text); background: rgba(var(--overlay-rgb),0.08); }
  /* Tronqué comme le titre : un nom de partageur à rallonge ne doit pas non
     plus imposer sa largeur à la ligne. */
  .library-shared-by {
    margin-top: 2px; font-size: 0.68rem; color: var(--shared); font-style: italic;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }

  .share-user-list { display: flex; flex-direction: column; gap: 4px; max-height: 260px; overflow-y: auto; }
  .share-user-choice {
    display: flex; align-items: center; gap: 10px; padding: 8px; border-radius: 8px; cursor: pointer;
  }
  .share-user-choice:hover { background: rgba(var(--overlay-rgb),0.05); }
  .share-user-choice img { width: 32px; height: 32px; object-fit: cover; border-radius: 6px; flex-shrink: 0; }
  .share-user-choice span { font-size: 0.85rem; color: var(--text); flex: 1; }
  .share-user-choice input { width: 17px; height: 17px; accent-color: var(--accent-2); }
  .share-empty { color: var(--text-dim); font-size: 0.82rem; padding: 8px; }
  .share-modal-actions { display: flex; gap: 10px; margin-top: 18px; }
  .share-modal-actions .modal-close { margin-top: 0; flex: 1; }
  .share-save-btn {
    flex: 1; padding: 10px; border: none; border-radius: 8px; cursor: pointer;
    background: linear-gradient(100deg, var(--accent), var(--accent-2)); color: var(--on-accent); font-size: 0.85rem; font-weight: 700;
  }
  .share-save-btn:disabled { opacity: 0.6; cursor: not-allowed; }

  /* Panneau des playlists : monte du bas et s'arrête à mi-hauteur, plutôt que
     de couvrir l'écran comme une modale classique — la bibliothèque reste
     visible au-dessus pendant qu'on manipule ses playlists. */
  /* padding-bottom : clavier du téléphone (ui/immersive.js) — la feuille
     des playlists, renommable, remonte au-dessus. */
  .sheet-overlay {
    position: fixed; inset: 0; z-index: 80; display: flex; align-items: flex-end; justify-content: center;
    padding-bottom: var(--kb, 0px); box-sizing: border-box;
    background: rgba(0,0,0,0); opacity: 0; pointer-events: none;
    transition: opacity 0.3s ease, background 0.3s ease;
  }
  .sheet-overlay.visible { opacity: 1; pointer-events: auto; background: rgba(0,0,0,0.55); }
  .bottom-sheet {
    width: 100%; max-width: 520px; height: min(58vh, calc(100dvh - var(--kb, 0px) - 16px)); display: flex; flex-direction: column;
    background: var(--panel); border: 1px solid var(--border); border-bottom: none;
    border-radius: 20px 20px 0 0; padding: 12px 20px 20px;
    box-shadow: 0 -14px 40px rgba(0,0,0,0.5);
    transform: translateY(100%); transition: transform 0.4s cubic-bezier(0.22, 1, 0.36, 1);
  }
  .sheet-overlay.visible .bottom-sheet { transform: translateY(0); }
  .sheet-handle { width: 42px; height: 4px; border-radius: 999px; background: var(--border); margin: 0 auto 14px; flex-shrink: 0; }
  .sheet-header { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; flex-shrink: 0; }
  .playlist-focus-row {
    margin: 0 0 14px; padding: 10px 12px; border-radius: 10px; background: rgba(var(--overlay-rgb),0.04);
    flex-shrink: 0; font-size: 0.8rem;
  }
  .sheet-title {
    flex: 1; min-width: 0; font-size: 1rem; font-weight: 700;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .sheet-btn {
    background: rgba(var(--overlay-rgb),0.06); border: none; color: var(--text); cursor: pointer;
    width: 32px; height: 32px; border-radius: 8px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 0.9rem;
  }
  .sheet-btn:hover { background: rgba(var(--overlay-rgb),0.14); }
  .sheet-btn.danger:hover { background: rgba(var(--error-rgb),0.16); color: var(--error); }
  .sheet-body { flex: 1; overflow-y: auto; margin: 0 -6px; padding: 0 6px; }
  .sheet-empty { color: var(--text-dim); font-size: 0.82rem; padding: 18px 4px; text-align: center; }
  /* Bibliothèque + panneaux latéraux (desktop) : la bibliothèque (playlists
     comprises) reste alignée à gauche, plafonnée en largeur plutôt que
     d'étirer toute la page, pour laisser de la place aux paroles puis,
     s'il en reste, à la fiche artiste. Sur mobile, toute cette rangée
     redevient display:contents (voir music.css) : rien ne change par
     rapport à avant ce découpage. */
  .library-desktop-row { display: flex; gap: 28px; align-items: flex-start; }
  /* Bibliothèque volontairement resserrée (plutôt que 640px avant) : sur un
     écran de bureau courant, ça laisse largement assez de place aux deux
     colonnes de droite pour être aérées (paroles ET fiche artiste), pas
     juste au minimum vital. */
  /* min-width:420px (pas 0) : sous cette largeur, les icônes d'action d'une
     ligne (jusqu'à 7 avec renommage admin) n'ont plus la place de tenir sur
     une seule ligne et repassent à la ligne — jamais acceptable en mode PC.
     Priorité donnée à cette colonne sur les deux panneaux de droite (voir
     leurs flex-shrink respectifs juste en dessous) : elle ne cède jamais sa
     largeur, ce sont eux qui se resserrent en premier. */
  .library-main-col { flex: 1 1 0; min-width: 420px; max-width: 460px; }
  .library-side-panels {
    flex: 2 1 0; min-width: 0; display: flex; gap: 24px;
    position: sticky; top: 20px; align-self: flex-start;
  }
  /* min-width relevé (300 -> 340) : protège les paroles en priorité quand
     l'espace se resserre — voir .desktop-artist-panel.visible plus bas,
     qui cède la place bien plus vite (flex-shrink 3 contre 1 ici) et peut
     descendre bien plus bas (220px) avant que les paroles n'aient elles-
     mêmes à céder un seul pixel. */
  .desktop-lyrics-panel, .desktop-artist-panel {
    flex: 1 1 360px; min-width: 340px;
    /* En dessous de 100vh moins la marge du haut ET la hauteur RÉELLE du
       mini-lecteur (--mini-player-reserved, tenue à jour par
       syncMiniPlayerPadding — voir plus haut dans ce fichier) : sans ça, la
       fin d'une bio ou de paroles longues se retrouvait cachée derrière le
       lecteur fixé en bas de l'écran, jamais atteignable en scrollant. */
    max-height: calc(100vh - 40px - var(--mini-player-reserved, 0px));
    overflow-y: auto;
    /* Scroll qui reste utilisable (molette/trackpad), juste sans la barre
       visible — demandé explicitement pour les paroles, étendu ici à la
       fiche artiste pour un rendu cohérent entre les deux blocs. */
    scrollbar-width: none;
  }
  .desktop-lyrics-panel::-webkit-scrollbar, .desktop-artist-panel::-webkit-scrollbar { display: none; }
  /* Rien à montrer (pas de morceau en lecture) : pas de cadre vide qui
     traînerait sans raison. Pour la fiche artiste, voir .visible ci-dessous
     — :empty seul ferait apparaître/disparaître le bloc d'un coup dès que
     son contenu change, sans transition possible sur un display:none. */
  .desktop-lyrics-panel:empty { display: none; }
  .desktop-artist-panel:empty { display: none; }
  /* Repli à largeur nulle (PAS display:none) tant qu'il n'y a pas de fiche
     à montrer du tout pour l'artiste courant : ainsi, dès qu'artist-
     info.php en renvoie une (paintDesktopArtistPanel ajoute .visible), le
     flex-basis qui grandit de 0 à 360px se voit — et comme les paroles
     gardent flex:1 1 360px à côté, la répartition du flex est recalculée à
     chaque frame de la transition, donc leur largeur se réduit EN MÊME
     TEMPS, en douceur. Un léger glissement vers la droite (translateX)
     accompagne la disparition, plutôt qu'un simple repli sur place — c'est
     UNIQUEMENT ce cas (apparition/disparition franche) qui anime quoi que
     ce soit : passer d'un artiste à un autre qui a AUSSI une fiche ne
     touche jamais .visible ni ces propriétés (voir paintDesktopArtistPanel
     et son .loading), donc jamais ce repli-réouverture disgracieux à
     chaque changement de morceau. */
  .desktop-artist-panel {
    flex: 0 1 0; min-width: 0; opacity: 0; overflow-x: hidden; transform: translateX(28px);
    transition: flex-basis 0.45s cubic-bezier(0.22, 1, 0.36, 1), min-width 0.45s cubic-bezier(0.22, 1, 0.36, 1),
                opacity 0.35s ease, transform 0.4s ease;
  }
  /* flex-shrink 3 (contre 1 pour les paroles) et min-width abaissé (220 vs
     340) : la fiche artiste cède la place la première quand ça se resserre
     — jamais l'inverse. Demandé explicitement : la bibliothèque (icônes
     d'action) passe avant elle, et elle-même passe avant les paroles. */
  .desktop-artist-panel.visible { flex: 1 3 300px; min-width: 220px; opacity: 1; transform: translateX(0); }
  /* Chargement d'un nouvel artiste alors qu'une fiche est déjà affichée :
     un simple fondu (opacité), jamais un repli de largeur — le bloc garde
     sa taille, les paroles à côté ne bougent pas d'un pixel pendant que la
     nouvelle fiche se prépare. */
  .desktop-artist-panel.loading { opacity: 0.35; }
  /* La fiche artiste ne s'affiche que s'il reste vraiment de la place —
     seuil abaissé (1440px -> 1150px) maintenant que la bibliothèque est
     plus étroite : elle a désormais sa place sur un écran de bureau
     courant, pas seulement sur les très grands moniteurs. */
  @media (max-width: 1150px) { .desktop-artist-panel { display: none; } }
  /* #lyrics-bottom-sheet migre entre la feuille modale (mobile, sinon
     inchangée) et ce panneau (desktop) — voir openLyricsSheet/
     closeLyricsSheet. Cette classe neutralise le style "feuille qui glisse
     depuis le bas d'un overlay plein écran" pour un rendu de bloc normal,
     posé dans le flux de la page. */
  #lyrics-bottom-sheet.desktop-inline {
    width: 100%; max-width: none; height: auto; transform: none; transition: none;
    border-radius: 12px; box-shadow: none; border: 1px solid var(--border);
    background: var(--panel); padding: 16px 18px;
  }
  #lyrics-bottom-sheet.desktop-inline .sheet-handle { display: none; }
  .desktop-artist-panel-inner { padding: 16px 18px; border-radius: 12px; border: 1px solid var(--border); background: var(--panel); }
  .desktop-artist-thumb { width: 100%; border-radius: 10px; margin-bottom: 12px; display: block; }
  /* Desktop dispose de bien plus de place qu'un téléphone/une tablette — le
     texte de la fiche artiste n'a pas à rester à la taille "mobile" du
     reste de l'appli, quitte à défiler un peu plus (voir max-height du
     panneau) : relevé d'un cran sur chaque élément (nom, extrait, lien). */
  .desktop-artist-name { font-size: 1.3rem; font-weight: 700; margin-bottom: 10px; }
  .desktop-artist-extract { font-size: 1.02rem; line-height: 1.7; color: var(--text-dim); }
  .desktop-artist-link { display: inline-block; margin-top: 12px; font-size: 0.92rem; color: var(--accent); }
  /* Feuille d'options du mini-lecteur (aléatoire/enchaînement/fondu) : juste
     3 boutons, pas besoin des 58vh par défaut de .bottom-sheet — hauteur au
     contenu (auto) plutôt qu'une proportion d'écran arbitraire. */
  .mini-player-settings-bottom-sheet { height: auto; max-height: 70vh; }
  .mini-player-settings-body { display: flex; flex-direction: column; gap: 4px; padding: 4px 0 8px; }
  .mini-player-settings-row {
    display: flex; align-items: center; gap: 14px; width: 100%; background: none; border: none;
    color: var(--text); font-size: 0.92rem; font-family: inherit; text-align: left; cursor: pointer;
    padding: 13px 6px; border-radius: 10px;
  }
  .mini-player-settings-row:hover { background: rgba(var(--overlay-rgb),0.06); }
  .mini-player-settings-row svg { flex-shrink: 0; }
  .mini-player-settings-row.toggle-active { color: var(--accent-2); }
  /* Paroles : texte brut, les retours à la ligne de l'API sont significatifs
     (couplets/refrain) d'où pre-line plutôt que de les perdre en normal. */
  .lyrics-sheet-body { white-space: pre-line; line-height: 1.65; font-size: 0.88rem; color: var(--text); padding: 2px 4px 12px; }
  /* Paroles synchronisées (lrclib.net) : une ligne par horodatage plutôt
     qu'un bloc de texte, pour pouvoir surligner celle en cours (JS,
     updateLyricsSync) et faire défiler la vue au fil de la lecture, comme un
     karaoké. */
  .lyrics-sheet-body.synced { white-space: normal; }
  .lyrics-sheet-body.synced .lyrics-line {
    padding: 5px 4px; color: var(--text-dim); transition: color 0.2s ease, font-size 0.2s ease, margin 0.2s ease;
  }
  /* Zoom de +50% sur la ligne en cours : lisible même à bonne distance de
     l'écran (canapé, salon...), pas seulement la légère mise en avant
     précédente (scale 1.04) qui ne se voyait que de près. font-size plutôt
     que transform:scale() : scale() agrandit la boîte APRÈS sa mise en
     page (déjà repliée à la largeur du panneau), donc une ligne assez
     longue continuait à dépasser et faisait apparaître une barre de
     défilement horizontale, même à 1.5 — testé, confirmé, écarté. Une plus
     grande taille de police, elle, force un nouveau retour à la ligne DANS
     la largeur disponible, donc jamais de dépassement horizontal quelle que
     soit la longueur du texte. */
  .lyrics-sheet-body.synced .lyrics-line.active {
    color: var(--accent); font-weight: 700; font-size: 1.5em; margin: 6px 0;
  }
  /* Transport (précédent/pause/suivant) : bascule d'un morceau à l'autre
     sans quitter la feuille de paroles. Placé dans le sheet-header, juste
     avant la croix de fermeture. Volontairement discret : c'est la feuille
     de paroles qui doit capter l'attention, ce transport n'est qu'un
     raccourci pratique en plus. */
  .lyrics-transport-row {
    display: flex; align-items: center; gap: 2px; flex-shrink: 0; opacity: 0.7;
  }
  /* Curseur de volume dans l'en-tête des paroles : moins de place que dans
     le mini-lecteur, donc plus étroit. */
  .lyrics-volume-wrap { opacity: 0.7; }
  .lyrics-volume-wrap:hover { opacity: 1; }
  .lyrics-volume-wrap .mini-player-volume { width: 46px; }
  .lyrics-transport-row:hover { opacity: 1; }
  .lyrics-transport-btn {
    width: 26px; height: 26px; border-radius: 50%; border: none;
    background: none; color: var(--text-dim); cursor: pointer; font-size: 0.8rem;
    display: flex; align-items: center; justify-content: center;
  }
  .lyrics-transport-btn svg { width: 13px; height: 13px; }
  .lyrics-transport-btn:hover { background: rgba(var(--overlay-rgb),0.08); color: var(--text); }
  .lyrics-transport-play { width: 26px; height: 26px; font-size: 0.75rem; }
  /* Décalage manuel du minutage synchronisé, avec un effet visible
     immédiatement dans la feuille, pour corriger un défilement
     systématiquement en avance/en retard (bumper de chaîne, pub ajoutée en
     tête ou en fin de la vidéo YouTube...). */
  .lyrics-offset-row {
    display: flex; align-items: center; justify-content: center; gap: 10px;
    padding: 6px 4px 10px; border-bottom: 1px solid var(--border); margin-bottom: 6px;
  }
  .lyrics-offset-value {
    min-width: 52px; text-align: center; font-size: 0.8rem; font-variant-numeric: tabular-nums;
    color: var(--text-dim);
  }
  /* Un seul geste, identique sur le clip (.video-lyrics-offset-now) : pause
     sur le premier mot chanté, un clic, le décalage se déduit de la position.
     Pas de −/+ ni de champ numérique : trop facile de s'y tromper (saisir
     l'instant de départ au lieu de l'écart). */
  .lyrics-offset-now {
    padding: 3px 8px; border-radius: 8px; border: 1px solid var(--border);
    background: transparent; color: var(--text-dim); font-size: 0.7rem; cursor: pointer;
    white-space: nowrap;
  }
  .lyrics-offset-now:hover { border-color: var(--accent); color: var(--accent); }

  /* Une carte par playlist plutôt qu'une simple ligne de texte : la pochette
     colorée (dégradé propre à chaque playlist) donne un repère visuel
     immédiat, là où toutes les lignes se ressemblaient auparavant. */
  .playlist-row {
    display: flex; align-items: center; gap: 12px; padding: 10px; margin-bottom: 6px; border-radius: 12px; cursor: pointer;
    background: rgba(var(--overlay-rgb),0.03); border: 1px solid transparent;
    transition: background 0.15s, border-color 0.15s, transform 0.15s;
  }
  .playlist-row:hover { background: rgba(var(--overlay-rgb),0.06); border-color: var(--border); transform: translateY(-1px); }
  .playlist-row-cover {
    flex-shrink: 0; width: 46px; height: 46px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.15rem; color: rgba(255,255,255,0.9); box-shadow: 0 4px 10px rgba(0,0,0,0.3);
  }
  .playlist-row-info { flex: 1; min-width: 0; }
  .playlist-row-name { font-size: 0.9rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .playlist-row-count {
    display: inline-block; margin-top: 5px; font-size: 0.68rem; color: var(--text-dim);
    background: rgba(var(--overlay-rgb),0.07); border-radius: 999px; padding: 2px 8px;
  }
  .playlist-row-actions { display: flex; gap: 4px; flex-shrink: 0; }
  .playlist-row-play {
    flex-shrink: 0; width: 34px; height: 34px; padding: 0; border: none; border-radius: 50%;
    background: linear-gradient(100deg, var(--accent), var(--accent-2)); color: var(--on-accent);
    font-size: 0.75rem; cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: transform 0.15s;
  }
  .playlist-row-play:hover { transform: translateY(-1px); }
  /* 4 vignettes de morceaux piochées au hasard dans la playlist plutôt que la
     note ♪ générique (voir renderPlaylistList()) — seulement quand la
     playlist en compte au moins 4 ; en-dessous, pas assez de vignettes pour
     une mosaïque lisible, on garde ♪. */
  .playlist-row-cover-mosaic {
    display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: 1fr 1fr; gap: 1px;
    padding: 0; overflow: hidden; background: var(--surface);
  }
  .playlist-row-cover-mosaic img { width: 100%; height: 100%; object-fit: cover; display: block; }

  /* Vue "playlist en cours" (voir #playlist-view-section) : même en-tête que
     les autres sections de bibliothèque, avec un bouton de retour explicite
     plutôt que de compter uniquement sur les onglets Audio/Clips. */
  .playlist-view-header {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    margin-bottom: 14px; padding-bottom: 14px; border-bottom: 1px solid var(--border);
  }
  .playlist-view-info { min-width: 0; }
  .playlist-view-name { font-size: 1.05rem; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .playlist-view-count { margin-top: 4px; font-size: 0.78rem; color: var(--text-dim); }
  .playlist-view-exit {
    flex-shrink: 0; background: rgba(var(--overlay-rgb),0.06); border: 1px solid var(--border); color: var(--text);
    border-radius: 999px; padding: 8px 14px; font-size: 0.8rem; cursor: pointer; white-space: nowrap;
  }
  .playlist-view-exit:hover { background: rgba(var(--overlay-rgb),0.12); }

  /* Détail d'une playlist : numéro d'ordre, vignette carrée plus grande, et
     repère net (liseré + fond teinté) sur le morceau en cours de lecture —
     avant, seule une légère teinte de fond distinguait "en lecture". */
  .playlist-item {
    display: flex; align-items: center; gap: 10px; padding: 8px; border-radius: 10px; cursor: pointer;
    border-left: 3px solid transparent;
    transition: background 0.15s, border-color 0.15s;
  }
  .playlist-item:hover { background: rgba(var(--overlay-rgb),0.05); }
  .playlist-item.playing { background: rgba(92,200,255,0.10); border-left-color: var(--accent); }
  .playlist-item.playing .playlist-item-title { color: var(--accent); font-weight: 600; }
  .playlist-item-index {
    flex-shrink: 0; width: 18px; text-align: center; font-size: 0.72rem; color: var(--text-dim); font-variant-numeric: tabular-nums;
  }
  .playlist-item img { width: 48px; height: 48px; object-fit: cover; border-radius: 8px; flex-shrink: 0; background: var(--surface); }
  .playlist-item-title { flex: 1; min-width: 0; font-size: 0.83rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .playlist-item-kind {
    flex-shrink: 0; font-size: 0.68rem; color: var(--text-dim);
    background: rgba(var(--overlay-rgb),0.07); border-radius: 999px; padding: 2px 7px;
  }
  .sheet-empty-icon { font-size: 1.8rem; margin-bottom: 8px; opacity: 0.75; }

  .sheet-new-btn {
    width: 100%; margin-top: 12px; padding: 10px; border-radius: 8px; flex-shrink: 0;
    background: var(--surface-2); border: 1px dashed var(--border); color: var(--text); font-size: 0.82rem; cursor: pointer;
  }
  .sheet-new-btn:hover { border-color: var(--accent); }
  /* Saisie du nom en place, à la place du bouton (création) ou du titre
     (renommage) : pas de fenêtre native qui sorte du style de l'appli. */
  .sheet-new-form { display: flex; gap: 8px; margin-top: 12px; flex-shrink: 0; }
  .sheet-new-input, .sheet-title-input {
    flex: 1; min-width: 0; padding: 9px 11px; border-radius: 8px;
    background: var(--surface-2); border: 1px solid var(--accent); color: var(--text);
    font-size: 0.85rem; outline: none;
  }

  .playlist-pick-list { display: flex; flex-direction: column; gap: 4px; max-height: 240px; overflow-y: auto; }
  .playlist-pick {
    display: flex; align-items: center; gap: 10px; padding: 8px; border-radius: 8px; cursor: pointer;
  }
  .playlist-pick:hover { background: rgba(var(--overlay-rgb),0.05); }
  .playlist-pick span { flex: 1; min-width: 0; font-size: 0.85rem; }
  /* Cases dessinées plutôt que natives : la case système, minuscule et grise
     sur fond sombre, se repérait mal dans une liste. Le carré arrondi reprend
     le dégradé des boutons principaux, et la coche est tracée en CSS (deux
     bords d'un rectangle tourné à 45°) — aucune image à charger. */
  .playlist-pick input, .share-user-choice input {
    appearance: none; -webkit-appearance: none;
    width: 21px; height: 21px; flex-shrink: 0; margin: 0; position: relative;
    border: 1.5px solid var(--border); border-radius: 6px; background: var(--surface-2);
    cursor: pointer; transition: background 0.15s ease, border-color 0.15s ease;
  }
  .playlist-pick input:hover, .share-user-choice input:hover { border-color: var(--accent); }
  .playlist-pick input:checked, .share-user-choice input:checked {
    background: linear-gradient(100deg, var(--accent), var(--accent-2));
    border-color: transparent;
  }
  .playlist-pick input:checked::after, .share-user-choice input:checked::after {
    content: ''; position: absolute; left: 7px; top: 3px;
    width: 5px; height: 10px;
    border: solid var(--on-accent); border-width: 0 2.5px 2.5px 0;
    transform: rotate(45deg);
  }
  .playlist-pick input:focus-visible, .share-user-choice input:focus-visible {
    outline: 2px solid var(--accent); outline-offset: 2px;
  }

  /* Appartenance lisible d'un coup d'œil, jusque dans une longue liste. */
  .playlist-pick.checked { background: rgba(92,200,255,0.10); }
  .playlist-pick.checked span { color: var(--text); font-weight: 600; }

  .dup-hint { margin-top: 10px; font-size: 0.76rem; }
  .dup-actions { display: flex; gap: 10px; margin-top: 18px; }
  .dup-actions .modal-close { margin-top: 0; flex: 1; }
  .dup-force-btn {
    flex: 1.4; padding: 10px; border: none; border-radius: 8px; cursor: pointer;
    background: linear-gradient(100deg, var(--accent), var(--accent-2)); color: var(--on-accent); font-size: 0.85rem; font-weight: 700;
  }

  .email-modal-text { margin: 0 0 14px; color: var(--text-dim); font-size: 0.82rem; line-height: 1.5; }
  .email-modal-text strong { color: var(--text); }
  .email-modal-hint { margin: 10px 0 0; font-size: 0.76rem; }
  .email-modal-input {
    width: 100%; padding: 11px 13px; border-radius: 10px; border: 1px solid var(--border);
    background: var(--surface-2); color: var(--text); font-size: 0.88rem; outline: none; transition: border-color 0.15s;
  }
  .email-modal-input:focus { border-color: var(--accent); }

  .storage-section { display: none; flex: 1; min-width: 0; cursor: pointer; border-radius: 8px; transition: background 0.15s; }
  .storage-section:hover, .storage-section:focus-visible { background: rgba(var(--overlay-rgb),0.05); }
  .storage-section.visible { display: block; }
  .storage-widget { display: flex; align-items: center; gap: 16px; }
  .storage-donut {
    width: 60px; height: 60px; border-radius: 50%; flex-shrink: 0; position: relative;
    background: conic-gradient(var(--border) 0% 100%);
  }
  .storage-donut::after {
    content: ''; position: absolute; inset: 8px; border-radius: 50%; background: var(--panel);
  }
  .storage-legend { display: flex; flex-direction: column; gap: 5px; font-size: 0.76rem; color: var(--text-dim); }
  .storage-legend-item { display: flex; align-items: center; gap: 7px; }
  .storage-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
  .storage-dot.audio { background: var(--accent); }
  .storage-dot.video { background: var(--accent-2); }
  .storage-dot.other { background: var(--success); }
  .storage-dot.free { background: var(--border); }
  /* Détail complet (donut plus grand + ventilation par type) ouvert au clic
     sur le bandeau compact de l'en-tête — utile surtout sur mobile, où
     celui-ci n'affiche plus que le donut, la légende n'ayant pas la place
     d'y tenir. */
  .storage-modal-widget { display: flex; align-items: flex-start; gap: 20px; margin-top: 4px; }
  .storage-modal-donut { width: 76px; height: 76px; flex-shrink: 0; margin-top: 4px; }
  .storage-modal-donut::after { inset: 11px; }
  .storage-modal-breakdown { flex: 1; min-width: 0; }
  .storage-modal-row {
    display: grid; grid-template-columns: 1fr auto; column-gap: 10px;
    padding: 8px 0; border-bottom: 1px solid var(--border);
  }
  .storage-modal-row:last-child { border-bottom: none; padding-bottom: 0; }
  .storage-modal-row-label { display: flex; align-items: center; gap: 7px; font-size: 0.85rem; }
  .storage-modal-row-value { font-size: 0.85rem; font-weight: 600; font-variant-numeric: tabular-nums; }
  .storage-modal-row-sub {
    grid-column: 1 / -1; margin-top: 3px; font-size: 0.72rem; color: var(--text-dim);
    font-variant-numeric: tabular-nums;
  }
  .storage-modal-total { margin: 16px 0 0; font-size: 0.78rem; color: var(--text-dim); }

  .playlist-import-summary-line { margin: 0 0 8px; font-size: 0.88rem; }
  .playlist-import-summary-errors-title {
    margin: 16px 0 6px; font-size: 0.78rem; font-weight: 600; color: var(--error);
    text-transform: uppercase; letter-spacing: 0.04em;
  }
  .playlist-import-summary-errors { max-height: 30vh; overflow-y: auto; display: flex; flex-direction: column; gap: 6px; }
  .playlist-import-summary-error-item {
    padding: 7px 10px; border-radius: 8px; background: rgba(var(--error-rgb),0.08);
    border: 1px solid rgba(var(--error-rgb),0.25);
  }
  .playlist-import-summary-error-title { font-size: 0.82rem; }
  .playlist-import-summary-error-artist { font-size: 0.72rem; color: var(--text-dim); }

  .mini-player {
    display: none; position: fixed; left: 0; right: 0; bottom: 0; z-index: 50;
    background: var(--panel); border-top: 1px solid var(--border); box-shadow: 0 -8px 30px rgba(0,0,0,0.4);
    padding: 10px 16px; align-items: center; gap: 12px;
  }
  .mini-player.visible { display: flex; }
  /* En bas, le lecteur ne doit pas pour autant recouvrir la fin de la page
     (dernier élément de la bibliothèque) — :has() pousse le contenu vers le
     haut tant qu'un morceau ou une vidéo est en cours, sans code JS dédié. */
  body:has(.mini-player.visible) { padding-bottom: 90px; }
  @media (max-width: 480px) {
    body:has(.mini-player.visible) { padding-bottom: 130px; }
  }
  /* display:contents sur desktop : ces deux div ne servent qu'à regrouper le
     HTML pour le réarrangement mobile ci-dessous — sur grand écran, leurs
     enfants restent des éléments flex directs de .mini-player, comportement
     strictement identique à avant leur introduction. */
  .mini-player-row-top, .mini-player-row-bottom { display: contents; }
  /* cursor:pointer : signale que la vignette est cliquable (recentre la
     bibliothèque/playlist sur le morceau en cours, voir miniThumb plus
     bas) — pas juste une image décorative comme avant. */
  .mini-player-thumb { width: 42px; height: 42px; object-fit: cover; border-radius: 6px; flex-shrink: 0; background: var(--surface); cursor: pointer; }
  /* Dans le coin au-dessus de la durée (voir .mini-player-time-wrap), la
     vignette n'a plus besoin d'être aussi grande — elle y est décorative,
     plus un repère à viser au doigt. */
  .mini-player-time-wrap .mini-player-thumb { width: 26px; height: 26px; border-radius: 5px; }
  .mini-player-body { flex: 1; min-width: 0; }
  .mini-player-title {
    font-size: 0.82rem; font-weight: 600; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  /* Défilement uniquement si le titre déborde (classe .scrolling ajoutée en
     JS après mesure) : deux copies du texte côte à côte, translation en
     boucle de 0 à -50% pour un défilement continu sans à-coup au raccord.
     La seconde copie (aria-hidden) ne sert qu'à ce raccord de boucle : sans
     scrolling, un titre court la laissait visible juste à côté de la
     première — donnant l'impression d'un titre dupliqué par bug. */
  .mini-player-title-track { display: inline-flex; width: max-content; }
  .mini-player-title-text { white-space: nowrap; padding-right: 40px; }
  .mini-player-title-text[aria-hidden="true"] { display: none; }
  .mini-player-title.scrolling .mini-player-title-text[aria-hidden="true"] { display: block; }
  .mini-player-title.scrolling .mini-player-title-track { animation: miniTitleMarquee linear infinite; }
  @keyframes miniTitleMarquee {
    from { transform: translateX(0); }
    to { transform: translateX(-50%); }
  }
  .mini-player-seek {
    width: 100%; margin-top: 6px; accent-color: var(--accent); height: 4px; cursor: pointer;
  }
  /* Notation par étoiles : visible uniquement ici (mini-lecteur), jamais dans
     les listes de la bibliothèque ni le lecteur vidéo plein écran. Centrées
     sous la barre de progression, et volontairement grandes (padding compris
     dans la zone cliquable, pas juste le glyphe) — trop petites au tap sinon. */
  .mini-player-rating { display: flex; justify-content: center; gap: 6px; margin-top: 6px; }
  .mini-star {
    background: none; border: none; padding: 4px; cursor: pointer; line-height: 1;
    font-size: 1.5rem; color: var(--border); transition: color 0.15s ease;
  }
  .mini-star.filled { color: #f5c542; }
  .mini-player-btn {
    background: none; border: none; color: var(--text); cursor: pointer; flex-shrink: 0;
    width: 36px; height: 36px; border-radius: 50%; font-size: 1.1rem; display: flex; align-items: center; justify-content: center;
  }
  .mini-player-btn:hover { background: rgba(var(--overlay-rgb),0.08); }
  .mini-player-play { color: var(--text); font-size: 1.3rem; }
  .mini-player-prev, .mini-player-next { color: var(--text); }
  /* Déplacés dans le HTML (hors de .mini-player-row-bottom, voir son
     commentaire) pour pouvoir les intercaler autour de la vignette sur
     mobile — order les renvoie ici à leur place d'origine sur desktop
     (après tous les boutons de .mini-player-row-bottom, restés eux à
     l'ordre implicite 0), pour un rendu desktop inchangé au pixel, peu
     importe leur ordre PHYSIQUE dans le DOM (prev, vignette, next, lecture
     — voir .mini-player-center-cluster). */
  #mini-player-prev { order: 20; }
  #mini-player-play { order: 21; }
  #mini-player-next { order: 22; }
  /* Paroles/options/fermer désormais physiquement juste AVANT le volume
     dans le DOM (voir HTML, .mini-player-row-bottom supprimée) — même
     principe que ci-dessus : un order explicite (10/11/12, entre le 0
     implicite de vignette/volume et le 20+ de prev/lecture/suivant) les
     replace exactement à leur ancienne position visuelle desktop (après
     vignette+volume, avant les contrôles de piste), peu importe l'ordre
     physique dans le DOM. */
  #mini-player-lyrics-btn { order: 10; }
  #mini-player-settings-btn { order: 11; }
  #mini-player-close { order: 12; }
  .mini-player-time-mobile { display: none; }
  /* display:contents sur desktop, même principe que .mini-player-row-top/
     -row-bottom plus haut : ce groupement (prev/vignette/next/lecture) ne
     sert qu'au réarrangement mobile, sur desktop ses enfants restent des
     éléments flex directs de .mini-player. */
  .mini-player-center-cluster { display: contents; }
  .mini-player-shuffle, .mini-player-autonext, .mini-player-crossfade { color: var(--text-dim); }
  /* Même code couleur que les autres icônes paroles (ligne de bibliothèque,
     lecteur vidéo) : orange dès que synchronisées, fond plein une fois la
     feuille effectivement ouverte. */
  .mini-player-lyrics-btn.lyrics-synced { color: var(--accent); }
  .mini-player-lyrics-btn.lyrics-active { background: var(--accent); color: var(--on-accent); }
  .mini-player-lyrics-btn.lyrics-active:hover { background: var(--accent); opacity: 0.9; }
  .mini-player-btn.toggle-active { color: var(--success); }
  .mini-player-volume-wrap { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
  .mini-player-volume-icon { color: var(--text); flex-shrink: 0; display: flex; }
  .mini-player-volume {
    width: 60px; accent-color: var(--accent); height: 4px; cursor: pointer;
  }
  .mini-player-time { font-size: 0.7rem; color: var(--text-dim); flex-shrink: 0; font-variant-numeric: tabular-nums; }
  .mini-player-time-wrap { display: flex; flex-direction: column; align-items: center; gap: 4px; flex-shrink: 0; }

  /* Sous 480px, plus assez de place pour aligner vignette + tous les
     contrôles sur une seule ligne : on ne masque plus rien (volume, 🔀, 🔁,
     fondu redevenaient invisibles), on passe sur deux lignes à la place. */
  @media (max-width: 480px) {
    .mini-player { flex-wrap: wrap; padding: 8px 12px; }
    .mini-player-row-top, .mini-player-row-bottom {
      display: flex; align-items: center; width: 100%; gap: 8px;
    }
    /* Sept boutons + le volume ne tiennent plus avec l'ancien gap de 18px :
       espacement resserré, boutons légèrement réduits, et repli sur une
       ligne supplémentaire en dernier recours sur les écrans très étroits. */
    .mini-player-row-bottom { justify-content: center; flex-wrap: wrap; gap: 6px; margin-top: 2px; }
    .mini-player-btn { width: 32px; height: 32px; }
    .mini-player-volume-wrap { display: flex; }
    /* Étoiles collées aux contrôles juste en dessous : plus d'espace perdu
       entre les deux lignes sur petit écran. */
    .mini-player-rating { margin-top: 2px; }
    .mini-player-volume { width: 50px; }
    .mini-player-time { display: block; }
  }

  .video-modal-overlay {
    display: flex; position: fixed; inset: 0; background: rgba(0,0,0,0);
    align-items: center; justify-content: center; z-index: 200; padding: 16px;
    opacity: 0; pointer-events: none; transition: opacity 0.25s ease, background 0.25s ease;
  }
  .video-modal-overlay.visible { opacity: 1; pointer-events: auto; background: rgba(0,0,0,0.92); }
  .video-modal {
    width: 100%; max-width: 900px;
    transform: scale(0.94) translateY(-14px);
    transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
  }
  .video-modal-overlay.visible .video-modal { transform: scale(1) translateY(0); }
  .video-modal-header {
    display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px;
  }
  .video-modal-header-main { flex: 1; min-width: 0; }
  .video-modal-title {
    color: var(--text); font-size: 0.88rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis;
    white-space: nowrap;
  }
  /* Masquée hors plein écran : la barre native, juste en dessous dans la
     petite fenêtre modale, suffit largement — voir .video-immersive dans
     music.css pour son affichage et son positionnement réels. */
  .video-timeline { display: none; align-items: center; gap: 8px; }
  .video-timeline-seek { width: 140px; accent-color: var(--accent); height: 4px; cursor: pointer; }
  .video-timeline-time { color: var(--text); font-size: 0.72rem; white-space: nowrap; font-variant-numeric: tabular-nums; flex-shrink: 0; }
  .video-modal-actions { display: flex; gap: 6px; flex-shrink: 0; }
  .video-modal-btn {
    background: rgba(var(--overlay-rgb),0.08); border: none; color: var(--text); cursor: pointer;
    width: 34px; height: 34px; border-radius: 8px; font-size: 1rem; display: flex; align-items: center; justify-content: center;
  }
  .video-modal-btn:hover { background: rgba(var(--overlay-rgb),0.16); }
  .video-modal-btn.lyrics-synced { color: var(--accent); }
  /* Fond plein une fois les paroles affichées : la seule couleur d'icône
     (disponibles vs synchronisées) ne suffisait pas à voir si elles sont
     actuellement montrées ou non. */
  .video-modal-btn.lyrics-active { background: var(--accent); color: var(--on-accent); }
  .video-modal-btn.lyrics-active:hover { background: var(--accent); opacity: 0.9; }
  #video-player { width: 100%; max-height: 75vh; border-radius: 10px; background: #000; display: block; }
  /* Paroles sous la vidéo plutôt qu'en incrustation dessus : évite tout
     conflit avec les contrôles natifs du <video> (barre de progression,
     lecture...), dont la zone exacte n'est pas fiable en CSS d'un navigateur
     à l'autre. Masquée en plein écran immersif (voir music.css), où il n'y a
     de toute façon plus de place pour elle. */
  .video-lyrics-bar {
    display: none; margin-top: 10px; padding: 10px 14px; border-radius: 10px;
    background: rgba(var(--overlay-rgb),0.05); text-align: center; font-size: 0.84rem;
    color: var(--text-dim); line-height: 1.6; max-height: 180px; overflow-y: auto;
    white-space: pre-line;
  }
  .video-lyrics-bar.visible { display: block; }
  .video-lyrics-bar .lyrics-line { padding: 3px 0; transition: color 0.2s ease, font-size 0.2s ease, margin 0.2s ease; }
  /* Même zoom de +50% que dans la feuille de paroles (voir plus haut, même
     raison pour font-size plutôt que transform:scale()) — la ligne en cours
     doit rester lisible même en regardant le clip de loin. */
  .video-lyrics-bar .lyrics-line.active { color: var(--accent); font-weight: 700; font-size: 1.5em; margin: 6px 0; }

  /* Fiche artiste du clip — voir le commentaire HTML de #video-artist-bar.
     Même famille visuelle que .video-lyrics-bar juste au-dessus (fond/rayon/
     marge identiques), mais mise en page horizontale (miniature à gauche,
     texte à droite) : plus compacte que la colonne verticale du panneau
     desktop, pensée pour un simple bandeau sous le lecteur. */
  .video-artist-bar { display: none; margin-top: 10px; }
  .video-artist-bar.visible { display: block; }
  .video-artist-bar-inner {
    display: flex; gap: 12px; align-items: flex-start; padding: 10px 14px;
    border-radius: 10px; background: rgba(var(--overlay-rgb),0.05);
    max-height: 140px; overflow-y: auto;
  }
  .video-artist-thumb { width: 56px; height: 56px; border-radius: 8px; object-fit: cover; flex-shrink: 0; }
  .video-artist-body { min-width: 0; }
  .video-artist-name { font-size: 0.88rem; font-weight: 700; margin-bottom: 4px; }
  .video-artist-extract { font-size: 0.8rem; line-height: 1.55; color: var(--text-dim); }
  .video-artist-link { display: inline-block; margin-top: 6px; font-size: 0.76rem; color: var(--accent); }

  /* Décalage propre au CLIP : l'intro d'une vidéo (plan d'ouverture,
     dialogue, logo de label) se compte souvent en dizaines de secondes, le
     réglage de la feuille audio ne conviendrait pas. */
  .video-lyrics-offset-row {
    display: none; align-items: center; justify-content: center; gap: 8px;
    margin-top: 8px; padding: 6px 10px; border-radius: 10px;
    background: rgba(var(--overlay-rgb),0.05);
  }
  .video-lyrics-offset-row.visible { display: flex; }
  .video-lyrics-offset-value {
    min-width: 52px; text-align: center; font-size: 0.8rem; font-variant-numeric: tabular-nums;
    color: var(--text-dim);
  }
  /* Un seul geste : on met la vidéo en pause sur le premier mot chanté et on
     clique — le décalage se déduit de la position de lecture, sans avoir à
     calculer soi-même "instant dans le clip − horodatage du premier vers". */
  .video-lyrics-offset-now {
    padding: 3px 8px; border-radius: 8px; border: 1px solid var(--border);
    background: transparent; color: var(--text-dim); font-size: 0.7rem; cursor: pointer;
    white-space: nowrap;
  }
  .video-lyrics-offset-now:hover { border-color: var(--accent); color: var(--accent); }
  .video-lyrics-offset-status {
    font-size: 0.68rem; color: var(--text-dim); min-width: 62px; text-align: left;
  }

  /* Mode "synchro manuelle" (voir startManualLyricsSync) : chaque ligne de
     #video-lyrics-bar devient un gros bouton tactile — la ligne EN COURS
     nettement mise en avant (fond plein, pulsation), celles déjà marquées
     grisées avec une coche, celles pas encore atteintes neutres. */
  .video-lyrics-bar.sync-mode { text-align: left; cursor: default; }
  .video-lyrics-bar.sync-mode .lyrics-line {
    padding: 8px 10px; border-radius: 8px; cursor: pointer; transition: background 0.15s ease, color 0.15s ease;
  }
  .video-lyrics-bar.sync-mode .lyrics-line.sync-done { color: var(--text-dim); opacity: 0.55; }
  .video-lyrics-bar.sync-mode .lyrics-line.sync-current {
    background: rgba(var(--accent-rgb),0.16); color: var(--accent); font-weight: 700;
    animation: sync-current-pulse 1.4s ease-in-out infinite;
  }
  @keyframes sync-current-pulse {
    0%, 100% { background: rgba(var(--accent-rgb),0.16); }
    50% { background: rgba(var(--accent-rgb),0.32); }
  }
  .video-lyrics-sync-controls {
    display: none; align-items: center; justify-content: center; gap: 8px; flex-wrap: wrap;
    margin-top: 8px; padding: 6px 10px; border-radius: 10px;
    background: rgba(var(--overlay-rgb),0.05);
  }
  .video-lyrics-sync-controls.visible { display: flex; }
  .video-lyrics-sync-progress { font-size: 0.72rem; color: var(--text-dim); font-variant-numeric: tabular-nums; }
  .video-lyrics-sync-skip, .video-lyrics-sync-save, .video-lyrics-sync-cancel {
    padding: 3px 10px; border-radius: 8px; border: 1px solid var(--border);
    background: transparent; color: var(--text-dim); font-size: 0.72rem; cursor: pointer;
  }
  .video-lyrics-sync-skip:hover, .video-lyrics-sync-cancel:hover { border-color: var(--error); color: var(--error); }
  .video-lyrics-sync-save { border-color: var(--accent); color: var(--accent); }
  .video-lyrics-sync-save:not(:disabled):hover { background: var(--accent); color: var(--on-accent); }
  .video-lyrics-sync-save:disabled { opacity: 0.4; cursor: not-allowed; }

  /* padding-bottom : hauteur du clavier du téléphone (ui/immersive.js) — la
     modale se centre dans la place qui reste au-dessus, champ visible. */
  .modal-overlay {
    display: flex; position: fixed; inset: 0; background: rgba(0,0,0,0);
    padding-bottom: var(--kb, 0px); box-sizing: border-box; overflow-y: auto;
    align-items: center; justify-content: center; z-index: 100;
    opacity: 0; pointer-events: none; transition: opacity 0.25s ease, background 0.25s ease;
  }
  .modal-overlay.visible { opacity: 1; pointer-events: auto; background: rgba(0,0,0,0.6); }
  .modal {
    background: var(--panel); border: 1px solid var(--border); border-radius: 16px; padding: 28px;
    max-width: 380px; width: calc(100% - 40px);
    transform: scale(0.92) translateY(-14px);
    transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
  }
  .modal-overlay.visible .modal { transform: scale(1) translateY(0); }
  .modal h2 { margin: 0 0 16px; font-size: 1.05rem; }
  .avatar-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
  .avatar-choice {
    background: var(--surface-2); border: 2px solid var(--border); border-radius: 10px; padding: 8px; cursor: pointer;
  }
  .avatar-choice img { width: 100%; display: block; border-radius: 8px; }
  .avatar-choice.selected { border-color: var(--accent); }
  .modal-close {
    margin-top: 18px; width: 100%; padding: 10px; border: none; border-radius: 8px;
    background: var(--surface-2); border: 1px solid var(--border); color: var(--text); font-size: 0.85rem; cursor: pointer;
  }
  .delete-confirm-text { margin: 0; color: var(--text-dim); font-size: 0.85rem; line-height: 1.5; }
  .delete-confirm-text strong { color: var(--text); }
  /* row-reverse (pas juste un réordonnancement dans le HTML) : le bouton
     destructeur (Supprimer/Retirer) passe à gauche, Annuler à droite. Le
     déclencheur de ces modales (icône poubelle) vit tout à droite de sa
     ligne — sans cette inversion, un double-tap accidentel au même endroit
     de l'écran (l'icône, puis la modale qui s'ouvre par-dessus) retombe
     pile sur le bouton de confirmation destructeur. En l'éloignant de cette
     zone, le même geste malheureux retombe sur Annuler, sans conséquence. */
  .delete-confirm-actions { display: flex; flex-direction: row-reverse; gap: 10px; margin-top: 18px; }
  /* Titre en plus gros, centré — le petit écran d'un téléphone rend un
     champ minuscule pénible à corriger du pouce ; la modale a toute la
     place pour l'afficher confortablement. */
  .mobile-rename-input {
    display: block; width: 100%; text-align: center; font-size: 1.15rem; font-weight: 600;
    color: var(--text); background: rgba(var(--overlay-rgb),0.06); border: 1px solid var(--border);
    border-radius: 10px; padding: 12px 14px; font-family: inherit;
  }
  .mobile-rename-input:focus { outline: none; border-color: var(--accent); }
  .delete-confirm-actions .modal-close { margin-top: 0; flex: 1; }
  .delete-confirm-btn {
    flex: 1; padding: 10px; border: none; border-radius: 8px; cursor: pointer;
    background: var(--error); color: #fff; font-size: 0.85rem; font-weight: 600;
  }
  .delete-confirm-btn:hover { opacity: 0.88; }
  .delete-confirm-btn:disabled { opacity: 0.6; cursor: not-allowed; }

  /* Mobile : vignette plus petite, actions plus espacées pour le doigt. Le
     titre reste en haut (aligné à la vignette) et les icônes en dessous,
     comme sur desktop — pas besoin d'un réagencement séparé ici. */
  @media (max-width: 480px) {
    body { padding: 8px; }
    .card { padding: 16px; border-radius: 12px; }
    .library-item { padding: 5px 8px; }
    .library-thumb { width: 32px; height: 32px; }
    .library-thumb-wrap .library-play-icon { font-size: 1rem; }
    .library-shared-by { font-size: 0.64rem; margin-top: 1px; }
    /* Resserré (8px, pas 14px) : avec l'icône paroles en plus, une ligne
       compte désormais jusqu'à 5-6 boutons (poubelle comprise, plus large —
       voir .library-delete-btn) — un espacement pensé pour 4 les faisait
       déborder sur une seconde ligne, décalant la poubelle sous les autres
       icônes plutôt qu'alignée avec elles. */
    .library-actions { gap: 8px; }
    .library-download-btn { padding: 4px 6px; }
  }
</style>
<link rel="stylesheet" href="music.css?v=20260921-sync-guide1">
</head>
<body class="music-app">
  <div class="toast" id="toast">
    <span class="toast-icon" id="toast-icon"></span>
    <span class="toast-message" id="toast-message"></span>
  </div>

  <div class="resume-banner" id="resume-banner">
    <div class="resume-banner-text" id="resume-banner-text">
      <div class="resume-banner-marquee" id="resume-banner-marquee">
        <span id="resume-banner-text-a"></span>
        <span id="resume-banner-text-b" aria-hidden="true"></span>
      </div>
    </div>
    <div class="resume-banner-actions">
      <button type="button" class="resume-banner-btn primary" id="resume-banner-play">Oui !</button>
      <button type="button" class="resume-banner-btn" id="resume-banner-dismiss">Non.</button>
    </div>
  </div>

  <a class="music-skip" href="#library-tabs">Aller à la bibliothèque</a>
  <aside class="music-sidebar" aria-label="Navigation principale">
    <a class="music-brand" href="#"><span aria-hidden="true">◖</span> ytdl<span class="music-brand-dot">+</span></a>
    <span class="music-nav-label">VOTRE ESPACE</span>
    <!-- Avatar (+ nom) et bandeau de stockage désormais côte à côte sur une
         même rangée : mêmes id/classes qu'avant leur déplacement depuis le
         header (#user-btn/#user-dropdown/#open-profile-btn, #storage-section)
         — tout le JS existant (toggle du menu, ouverture du panneau,
         renderStorageUsage/openStorageModal…) continue de fonctionner sans
         changement, indifférent à la position dans le DOM. Le libellé
         "Stockage", masqué à l'affichage, redevient une infobulle au survol
         du donut (voir .sidebar-tooltip) — la modale ouverte au clic reste
         le seul endroit où le détail par catégorie s'affiche. -->
    <div class="sidebar-profile-row">
      <div class="user-menu sidebar-user-menu sidebar-tooltip" data-tooltip="Voir mon profil">
        <button class="user-btn" id="user-btn">
          <img src="avatars/<?php echo htmlspecialchars($user['avatar']); ?>" alt="">
          <span class="user-btn-name"><?php echo htmlspecialchars($user['name']); ?></span>
        </button>
        <div class="user-dropdown" id="user-dropdown">
          <button type="button" id="open-profile-btn">Mon profil</button>
          <?php if ($user['is_admin']): ?>
            <a href="admin.php">Administration</a>
          <?php endif; ?>
          <a href="logout.php">Déconnexion</a>
        </div>
      </div>
      <div class="storage-section sidebar-storage-section sidebar-tooltip" id="storage-section" data-tooltip="Stockage" role="button" tabindex="0">
        <div class="library-title">Stockage</div>
        <div class="storage-widget">
          <div class="storage-donut" id="storage-donut"></div>
          <div class="storage-legend">
            <div class="storage-legend-item"><span class="storage-dot audio"></span>MP3 : <span id="storage-audio-size"></span></div>
            <div class="storage-legend-item"><span class="storage-dot video"></span>Vidéos : <span id="storage-video-size"></span></div>
            <div class="storage-legend-item"><span class="storage-dot other"></span>Hors musique : <span id="storage-other-size"></span></div>
            <div class="storage-legend-item"><span class="storage-dot free"></span>Libre : <span id="storage-free-size"></span></div>
          </div>
        </div>
      </div>
    </div>
    <nav>
      <button class="music-nav active" data-music-target="home"><span aria-hidden="true">⌂</span> Découvrir</button>
      <button class="music-nav" data-music-target="audio"><span aria-hidden="true">♫</span> Mes morceaux</button>
      <button class="music-nav" data-music-target="video"><span aria-hidden="true">▷</span> Mes clips</button>
      <button class="music-nav" data-music-target="playlists"><span aria-hidden="true">☷</span> Mes playlists</button>
    </nav>
    <div class="kawaii-sidebar-mascot" aria-hidden="true">
      <svg viewBox="0 0 120 185" width="72" height="111">
        <!-- Deux poses distinctes, jamais la même tournée à 180° (ça donnait
             une tête inversée peu naturelle) : la sieste fait place nette à
             une vraie illustration allongée, et setupKawaiiMascot() fait un
             fondu-bascule entre les deux (voir .asleep dans le CSS). -->
        <g class="kawaii-pose-sitting">
          <!-- Assis : queue enroulée, corps, jambes — dessinés avant la tête
               pour rester derrière elle dans l'empilement SVG. -->
          <path class="kawaii-tail" d="M90 154 Q114 150 112 112 Q110 90 96 95" stroke="#ffb3c6" stroke-width="14" fill="none" stroke-linecap="round"/>
          <ellipse cx="60" cy="140" rx="40" ry="40" fill="#fff5f8"/>
          <!-- Jambes avant : un vrai segment du corps jusqu'au pied, pas
               juste une boule flottante en bas. -->
          <rect x="30" y="148" width="17" height="32" rx="8.5" fill="#fff5f8" stroke="#ffb3c6" stroke-width="2"/>
          <rect x="73" y="148" width="17" height="32" rx="8.5" fill="#fff5f8" stroke="#ffb3c6" stroke-width="2"/>
          <circle cx="38.5" cy="177" r="10.5" fill="#fff5f8" stroke="#ffb3c6" stroke-width="2.5"/>
          <circle cx="81.5" cy="177" r="10.5" fill="#fff5f8" stroke="#ffb3c6" stroke-width="2.5"/>
          <circle cx="28" cy="28" r="16" fill="#ffd3e2"/><circle cx="92" cy="28" r="16" fill="#ffd3e2"/>
          <circle cx="28" cy="28" r="8" fill="#ff9fbb"/><circle cx="92" cy="28" r="8" fill="#ff9fbb"/>
          <circle cx="60" cy="62" r="46" fill="#fff5f8"/>
          <!-- Reculent vers les oreilles pendant que la bouche est ouverte (voir
               setupKawaiiMascot()), reviennent ensuite — classe posée sur le
               <svg> parent, translateX opposé pour chaque joue. -->
          <ellipse class="kawaii-cheek kawaii-cheek-l" cx="32" cy="72" rx="9" ry="6" fill="#ffb3c6" opacity="0.7"/>
          <ellipse class="kawaii-cheek kawaii-cheek-r" cx="88" cy="72" rx="9" ry="6" fill="#ffb3c6" opacity="0.7"/>
          <!-- Le blanc de l'œil reste fixe, seule la pupille (groupe
               .kawaii-pupil) est translatée en JS vers le curseur. -->
          <circle cx="44" cy="60" r="8" fill="#fff"/><circle cx="76" cy="60" r="8" fill="#fff"/>
          <g class="kawaii-pupil"><circle cx="44" cy="60" r="5" fill="#5c3a52"/><circle cx="45.6" cy="58.4" r="1.5" fill="#fff"/></g>
          <g class="kawaii-pupil"><circle cx="76" cy="60" r="5" fill="#5c3a52"/><circle cx="77.6" cy="58.4" r="1.5" fill="#fff"/></g>
          <path class="kawaii-mascot-mouth" d="M52 76 Q60 82 68 76" stroke="#5c3a52" stroke-width="3" fill="none" stroke-linecap="round"/>
        </g>
        <!-- En boule, tête posée sur les pattes avant repliées devant — pas
             sur le dos (relisait comme des antennes) : pose classique du chat
             qui dort, illustration à part entière. -->
        <g class="kawaii-pose-asleep">
          <!-- queue enroulée autour du corps -->
          <path d="M108 138 Q128 128 120 100 Q116 86 100 90" stroke="#ffb3c6" stroke-width="13" fill="none" stroke-linecap="round"/>
          <!-- corps en boule, couché -->
          <ellipse cx="66" cy="128" rx="46" ry="30" fill="#fff5f8"/>
          <!-- pattes avant repliées devant, sous le menton -->
          <ellipse cx="36" cy="118" rx="14" ry="10" fill="#fff5f8" stroke="#ffb3c6" stroke-width="2"/>
          <ellipse cx="54" cy="122" rx="14" ry="10" fill="#fff5f8" stroke="#ffb3c6" stroke-width="2"/>
          <!-- oreilles, aplaties (chat détendu) -->
          <circle cx="24" cy="76" r="11" fill="#ffd3e2"/><circle cx="48" cy="70" r="11" fill="#ffd3e2"/>
          <circle cx="24" cy="76" r="5.5" fill="#ff9fbb"/><circle cx="48" cy="70" r="5.5" fill="#ff9fbb"/>
          <!-- tête posée sur les pattes -->
          <circle cx="42" cy="98" r="34" fill="#fff5f8"/>
          <ellipse cx="30" cy="108" rx="8" ry="5" fill="#ffb3c6" opacity="0.6"/>
          <ellipse cx="56" cy="110" rx="8" ry="5" fill="#ffb3c6" opacity="0.6"/>
          <path d="M28 96 Q34 101 40 96" stroke="#5c3a52" stroke-width="3" fill="none" stroke-linecap="round"/>
          <path d="M48 97 Q54 102 60 97" stroke="#5c3a52" stroke-width="3" fill="none" stroke-linecap="round"/>
          <path d="M38 112 Q42 115 46 112" stroke="#5c3a52" stroke-width="2.5" fill="none" stroke-linecap="round"/>
        </g>
        <text class="kawaii-zzz" x="94" y="30" font-size="15" font-weight="700" fill="#c98aa8">Zzz…</text>
      </svg>
    </div>
    <div class="music-sidebar-bottom"><div class="music-sidebar-note"><span>À votre image.</span><p>Des titres à garder.<br>Des playlists à partager.</p></div><button class="music-add" data-music-target="import">＋ Ajouter un titre</button></div>
  </aside>
  <main class="card" id="music-home">
    <!-- Logo/sous-titre ("Votre musique. Votre rythme.") et le menu
         utilisateur qui vivaient ici sont partis dans la sidebar (voir
         .music-brand et .sidebar-user-menu/.sidebar-storage-section
         ci-dessus) — masquée au-delà de 760px, ce header desktop n'a donc
         plus besoin de les reprendre. Sur mobile, la sidebar disparaît
         entièrement (voir music.css) : sans cette rangée (visible
         uniquement sous 760px, voir .mobile-top-bar-row), il n'y avait plus
         ni nom de l'app, ni espace disque, ni accès à "Mon profil"/
         Administration/Déconnexion depuis un téléphone. Mêmes comportements
         que leurs équivalents desktop (openStorageModal, openProfileDrawer)
         sur des id distincts (#mobile-*) — et l'avatar reste aussi le point
         d'ancrage de la démo du geste de glissement, voir
         setupSwipeHintAvatar plus bas. -->
    <div class="top-bar">
      <div class="mobile-top-bar-row">
        <a class="music-brand mobile-brand" href="#"><span aria-hidden="true">◖</span> ytdl<span class="music-brand-dot">+</span></a>
        <div class="storage-section mobile-storage-section sidebar-tooltip" id="mobile-storage-section" data-tooltip="Stockage" role="button" tabindex="0">
          <div class="storage-widget">
            <div class="storage-donut" id="mobile-storage-donut"></div>
          </div>
        </div>
        <div class="user-menu mobile-user-menu" id="mobile-user-menu">
          <button class="user-btn" id="mobile-user-btn">
            <img src="avatars/<?php echo htmlspecialchars($user['avatar']); ?>" alt="">
          </button>
          <div class="user-dropdown" id="mobile-user-dropdown">
            <button type="button" id="mobile-open-profile-btn">Mon profil</button>
            <?php if ($user['is_admin']): ?>
              <a href="admin.php">Administration</a>
            <?php endif; ?>
            <a href="logout.php">Déconnexion</a>
          </div>
        </div>
      </div>
    </div>

    <section class="music-recent" id="music-recent" hidden><div class="music-section-heading"><div class="music-section-heading-title"><h2 id="music-recent-heading">Playlist au hasard</h2><button type="button" class="music-shelf-reroll" id="music-shelf-reroll" title="Une autre playlist au hasard" aria-label="Une autre playlist au hasard">🎲</button></div><span id="music-recent-subtitle"></span></div><div class="music-shelf-wrap"><button type="button" class="music-shelf-arrow music-shelf-arrow-prev" id="music-shelf-prev" aria-label="Titres précédents" hidden>‹</button><div class="music-shelf" id="music-shelf"></div><button type="button" class="music-shelf-arrow music-shelf-arrow-next" id="music-shelf-next" aria-label="Titres suivants" hidden>›</button></div></section>
    <div class="music-section-heading music-collection-heading"><h2>Votre bibliothèque</h2><span>Les morceaux qui vous ressemblent.</span></div>
    <!-- Bibliothèque (playlists comprises) alignée à gauche sur desktop pour
         laisser la place, à droite, au bloc paroles (remplace la feuille
         modale sur desktop, voir openLyricsSheet) puis, si l'écran est assez
         large, à la fiche artiste (voir #desktop-artist-panel,
         artist-info.php). Sur mobile, .library-desktop-row et
         .library-main-col repassent en display:contents (voir music.css) :
         le flux redevient identique à avant ce changement, et
         .library-side-panels est carrément masquée (paroles = feuille
         modale, fiche artiste jamais affichée sur mobile). -->
    <div class="library-desktop-row" id="library-desktop-row">
    <div class="library-main-col" id="library-main-col">
    <div class="library-tabs" id="library-tabs">
      <!-- Loupe collée devant le compteur de mp3 de l'onglet Audio : la
           recherche qu'elle ouvre (voir setupLibrarySearch) apparaît juste en
           dessous, sous les onglets — la coller ici plutôt que du côté des
           boutons playlists/ajout (à droite) la rapproche visuellement du
           champ qu'elle fait apparaître. -->
      <button type="button" class="playlist-fab search-fab" id="header-search-btn" title="Rechercher" aria-label="Rechercher">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="10.5" cy="10.5" r="7.5"></circle><line x1="21.5" y1="21.5" x2="16" y2="16"></line>
        </svg>
      </button>
      <!-- Onglets sous forme de pastilles rondes (même famille que les boutons
           playlists/ajout à droite) : une note pour l'audio, une diapositive
           pour les clips, suivies du nombre de titres — toujours affiché,
           y compris sur smartphone. Le libellé texte ne survit que pour les
           lecteurs d'écran et l'infobulle. -->
      <button type="button" class="library-tab active" id="tab-audio" data-tab="audio" title="Audio" aria-label="Audio">
        <span class="library-tab-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 18V5l12-2v13"></path><circle cx="6" cy="18" r="3"></circle><circle cx="18" cy="16" r="3"></circle>
          </svg>
        </span>
        <span class="library-tab-count" id="audio-tab-count"></span><span class="library-tab-badge" id="audio-tab-badge"></span>
      </button>
      <button type="button" class="library-tab" id="tab-video" data-tab="video" title="Clips" aria-label="Clips">
        <span class="library-tab-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2" y="4" width="20" height="16" rx="2"></rect><rect x="6" y="8" width="12" height="8" rx="1"></rect>
            <path d="M2 8h4M18 8h4M2 16h4M18 16h4"></path>
          </svg>
        </span>
        <span class="library-tab-count" id="video-tab-count"></span><span class="library-tab-badge" id="video-tab-badge"></span>
      </button>
      <!-- "Others" : vidéos YouTube téléchargées hors musique (documentaire,
           tutoriel, vlog...) — mélange audio+vidéo, pas de sous-distinction
           ici (voir bouton "Hors musique" du formulaire). Trois points
           horizontaux : neutre, ne rappelle ni une note ni un clap. -->
      <button type="button" class="library-tab" id="tab-others" data-tab="others" title="Hors musique" aria-label="Hors musique">
        <span class="library-tab-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="5" cy="12" r="1.6" fill="currentColor" stroke="none"></circle>
            <circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"></circle>
            <circle cx="19" cy="12" r="1.6" fill="currentColor" stroke="none"></circle>
          </svg>
        </span>
        <span class="library-tab-count" id="others-tab-count"></span><span class="library-tab-badge" id="others-tab-badge"></span>
      </button>
      <!-- Tri de l'onglet actif : par artiste croissant (A → Z, 0 → 9) ou
           décroissant (Z → A, 9 → 0), ou par date d'ajout (les derniers
           ajoutés en premier — c'est l'ordre naturel de la liste, allumé
           par défaut). Le bouton allumé indique le tri en cours. La colonne
           d'index alphabétique suit le sens du tri par artiste et disparaît
           en tri par date. -->
      <div class="library-sort-group" role="group" aria-label="Tri par artiste">
        <button type="button" class="library-sort-btn" id="library-sort-asc" data-direction="asc" title="Trier par artiste, croissant (A → Z, 0 → 9)" aria-label="Tri croissant">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 6h5"></path><path d="M3 12h9"></path><path d="M3 18h13"></path>
            <path d="M19 5v14"></path><path d="M15.5 15.5 19 19l3.5-3.5"></path>
          </svg>
        </button>
        <button type="button" class="library-sort-btn" id="library-sort-desc" data-direction="desc" title="Trier par artiste, décroissant (Z → A, 9 → 0)" aria-label="Tri décroissant">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 6h13"></path><path d="M3 12h9"></path><path d="M3 18h5"></path>
            <path d="M19 19V5"></path><path d="M15.5 8.5 19 5l3.5 3.5"></path>
          </svg>
        </button>
        <button type="button" class="library-sort-btn" id="library-sort-recent" data-direction="recent" title="Derniers titres ajoutés en premier" aria-label="Tri par date d'ajout">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3.5 2"></path>
          </svg>
        </button>
      </div>
      <!-- Les deux boutons ronds dans un même conteneur : c'est lui qui porte
           le margin-left:auto, sinon deux marges automatiques se partageraient
           l'espace au lieu de coller les boutons l'un à l'autre. -->
      <div class="toolbar-actions">
        <button type="button" class="playlist-fab" id="playlist-fab" title="Mes playlists" aria-label="Mes playlists">
          <svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" y1="6" x2="14" y2="6"></line>
            <line x1="3" y1="12" x2="14" y2="12"></line>
            <line x1="3" y1="18" x2="10" y2="18"></line>
            <path d="M17 15.5V7l4 1.6"></path>
            <circle cx="15.4" cy="16" r="1.9"></circle>
          </svg>
        </button>
        <button type="button" class="download-fab" id="download-fab" title="Télécharger" aria-label="Ajouter un titre">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 4v12"></path><path d="M6.5 11.5 12 17l5.5-5.5"></path><path d="M5 21h14"></path>
          </svg>
        </button>
      </div>
    </div>

    <div class="library-section active-tab" id="library-section">
      <div class="library-search-shell" id="library-search-shell">
        <input type="text" class="library-search-input" id="library-search-input" placeholder="Rechercher un morceau…">
        <button type="button" class="library-search-clear" id="library-search-clear" title="Effacer">✕</button>
      </div>
      <div class="yt-elsewhere" id="library-yt-elsewhere"></div>
      <div class="yt-suggest" id="library-yt-suggest"></div>
      <div class="library-list-row">
        <div class="library-list" id="library-list"></div>
        <!-- Index alphabétique façon liste de contacts : masqué tant que le
             tri par artiste n'est pas actif (sans tri, l'ordre n'a aucun
             rapport avec les lettres) — voir les boutons .library-sort-btn et
             setupAzIndex. -->
        <div class="library-az-index" id="library-az-index"></div>
      </div>
    </div>

    <div class="library-section" id="video-library-section">
      <div class="library-search-shell" id="video-library-search-shell">
        <input type="text" class="library-search-input" id="video-library-search-input" placeholder="Rechercher une vidéo…">
        <button type="button" class="library-search-clear" id="video-library-search-clear" title="Effacer">✕</button>
      </div>
      <div class="yt-elsewhere" id="video-library-yt-elsewhere"></div>
      <div class="yt-suggest" id="video-library-yt-suggest"></div>
      <div class="library-list-row">
        <div class="library-list" id="video-library-list"></div>
        <div class="library-az-index" id="video-library-az-index"></div>
      </div>
    </div>

    <!-- "Others" : liste simple sans recherche ni tri par artiste (pas de
         setupLibrarySearch ici — cette fonction distingue audio/vidéo pour
         les suggestions croisées, ce qui ne correspond à rien pour une
         catégorie qui mélange volontairement les deux). Rendu mixte
         audio+vidéo comme la vue playlist (icône par morceau). -->
    <div class="library-section" id="others-library-section">
      <div class="library-list" id="others-library-list"></div>
    </div>

    <!-- Vue "playlist en cours" : remplace les onglets Audio/Clips au
         lancement d'une playlist (voir startPlaylist/switchTab) — seule la
         playlist jouée reste affichée, plutôt que toute la bibliothèque
         derrière elle. Un clic sur l'onglet Audio ou Clips (la "bibliothèque"
         au sens de cette vue) revient à l'affichage normal, sans arrêter la
         lecture en cours. -->
    <div class="library-section" id="playlist-view-section">
      <div class="playlist-view-header">
        <div class="playlist-view-info">
          <div class="playlist-view-name" id="playlist-view-name"></div>
          <div class="playlist-view-count" id="playlist-view-count"></div>
        </div>
        <button type="button" class="playlist-view-exit" id="playlist-view-exit">
          ← Retour à la bibliothèque
        </button>
      </div>
      <div class="library-list-row">
        <div class="library-list" id="playlist-view-list"></div>
        <!-- Index alphabétique, comme Audio/Clips : masqué tant que le tri
             par artiste n'est pas actif sur CETTE vue (voir librarySortTargets
             .playlist et renderPlaylistView). -->
        <div class="library-az-index" id="playlist-view-az-index"></div>
      </div>
    </div>
    </div>
    <!-- Panneaux latéraux desktop : paroles (toujours présent dans le DOM,
         voir #lyrics-bottom-sheet qui y est déplacé/re-déplacé selon la
         largeur d'écran) + fiche artiste (rempli/vidé par
         renderDesktopArtistPanel). :empty (voir CSS) masque chacun tant
         qu'il n'y a rien à montrer, sans JS dédié pour ça. -->
    <div class="library-side-panels" id="library-side-panels">
      <div class="desktop-lyrics-panel" id="desktop-lyrics-panel"></div>
      <div class="desktop-artist-panel" id="desktop-artist-panel"></div>
    </div>
    </div>
    <div class="kawaii-end-mascot" aria-hidden="true">
      <svg viewBox="0 0 120 120" width="56" height="56">
        <circle cx="28" cy="28" r="16" fill="#ffd3e2"/><circle cx="92" cy="28" r="16" fill="#ffd3e2"/>
        <circle cx="28" cy="28" r="8" fill="#ff9fbb"/><circle cx="92" cy="28" r="8" fill="#ff9fbb"/>
        <circle cx="60" cy="62" r="46" fill="#fff5f8"/>
        <ellipse cx="32" cy="72" rx="9" ry="6" fill="#ffb3c6" opacity="0.7"/>
        <ellipse cx="88" cy="72" rx="9" ry="6" fill="#ffb3c6" opacity="0.7"/>
        <path d="M39 60 Q44 55 49 60" stroke="#5c3a52" stroke-width="3" fill="none" stroke-linecap="round"/>
        <path d="M71 60 Q76 55 81 60" stroke="#5c3a52" stroke-width="3" fill="none" stroke-linecap="round"/>
        <path d="M50 78 Q60 86 70 78" stroke="#5c3a52" stroke-width="3" fill="none" stroke-linecap="round"/>
      </svg>
      <span>C'est tout pour l'instant !</span>
    </div>
  </main>

  <div class="download-sheet-overlay" id="download-sheet-overlay">
    <div class="download-sheet">
      <div class="download-sheet-header">
        <h2>Nouveau téléchargement</h2>
        <button type="button" class="download-sheet-close" id="download-sheet-close" title="Fermer">✕</button>
      </div>

      <button type="button" class="playlist-import-cta" id="playlist-import-open-link">
        <span class="playlist-import-cta-icons">
          <svg class="pi-brand-icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
            <circle cx="12" cy="12" r="11" fill="#1ED760"/>
            <path d="M5.5 8.8c4.5-1.6 11-1.2 14.5 1" stroke="#0b3d1f" stroke-width="1.7" fill="none" stroke-linecap="round"/>
            <path d="M6 12.2c4-1.4 9.5-1.1 12.5.9" stroke="#0b3d1f" stroke-width="1.7" fill="none" stroke-linecap="round"/>
            <path d="M6.5 15.5c3.5-1.2 8-1 11 .8" stroke="#0b3d1f" stroke-width="1.7" fill="none" stroke-linecap="round"/>
          </svg>
          <svg class="pi-brand-icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
            <rect x="1.5" y="14" width="3.4" height="6.5" rx="1" fill="#FF0092"/>
            <rect x="6.8" y="9.5" width="3.4" height="11" rx="1" fill="#FF3F86"/>
            <rect x="12.1" y="5" width="3.4" height="15.5" rx="1" fill="#A238FF"/>
            <rect x="17.4" y="9.5" width="3.4" height="11" rx="1" fill="#4451FF"/>
          </svg>
          <svg class="pi-brand-icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
            <circle cx="12" cy="12" r="11" fill="#FF0000"/>
            <path d="M9.7 8.2v7.6l6.3-3.8z" fill="#fff"/>
          </svg>
          <span class="pi-icon-plus">+</span>
          <svg class="pi-import-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <line x1="3" y1="6" x2="14" y2="6"></line>
            <line x1="3" y1="12" x2="14" y2="12"></line>
            <line x1="3" y1="18" x2="10" y2="18"></line>
            <path d="M18 8v9"></path>
            <path d="M14.5 14l3.5 3.5 3.5-3.5"></path>
          </svg>
        </span>
        <span class="playlist-import-cta-text">
          <strong>Importer une playlist</strong>
          <span>Spotify, Deezer, YouTube Music, Apple Music…</span>
        </span>
        <span class="playlist-import-cta-arrow">→</span>
      </button>

      <div class="share-import-banner" id="share-import-banner">
        <div class="share-import-spinner"></div>
        <span>Réception des liens partagés…</span>
      </div>

      <form id="dl-form">
        <label>URLs YouTube</label>
        <div class="url-rows" id="url-rows"></div>

        <label class="switch-row">
          <input type="checkbox" id="audio-only" checked>
          <span class="switch-track"></span>
          <span>Audio uniquement (MP3 haute qualité)</span>
        </label>

        <!-- Indépendant de "Audio uniquement" : une vidéo hors musique peut
             tout aussi bien être gardée en vidéo qu'extraite en audio (ex.
             un podcast) — voir la case "category" à l'archivage (lib.php). -->
        <label class="switch-row">
          <input type="checkbox" id="other-content">
          <span class="switch-track"></span>
          <span>Hors musique <span class="switch-hint">(documentaire, tutoriel, vlog...)</span></span>
        </label>

        <label class="switch-row">
          <input type="checkbox" id="download-to-device" checked>
          <span class="switch-track"></span>
          <span>Télécharger sur l'appareil <strong>ET</strong> le serveur</span>
        </label>

        <div class="preview-card" id="preview-card">
          <img class="preview-thumb" id="preview-thumb" alt="">
          <div class="preview-info">
            <div class="preview-title" id="preview-title"></div>
            <div class="preview-meta" id="preview-meta"></div>
          </div>
        </div>

        <button type="submit" class="primary" id="dl-btn">Télécharger</button>

        <div class="progress-block" id="progress-block">
          <div class="progress-row">
            <span class="progress-label" id="progress-label">Préparation…</span>
            <span class="progress-percent" id="progress-percent">0 %</span>
          </div>
          <div class="progress-track">
            <div class="progress-fill" id="progress-fill"></div>
          </div>
        </div>

        <div class="retry-block" id="retry-block">
          <div class="retry-count" id="retry-count">5</div>
          <div class="retry-label" id="retry-label">Nouvel essai dans quelques secondes…</div>
          <div class="retry-proverb" id="retry-proverb"></div>
        </div>

        <div class="status-line" id="status-line"></div>
      </form>

      <div class="hint">
        Le serveur récupère et fusionne la meilleure piste vidéo et la meilleure piste
        audio disponibles (yt-dlp + ffmpeg), puis le fichier part automatiquement vers
        le dossier de téléchargement par défaut de ton navigateur.
      </div>
    </div>
  </div>

  <!-- Import de playlist externe : colle un lien de playlist publique
       (Spotify, Deezer, YouTube Music, Apple Music), on retrouve chaque
       titre sur YouTube et on les télécharge un par un dans une playlist
       locale portant le même nom — voir setupPlaylistImport() plus bas. -->
  <div class="download-sheet-overlay" id="playlist-import-sheet-overlay">
    <div class="download-sheet">
      <div class="download-sheet-header">
        <h2>Importer une playlist</h2>
        <button type="button" class="download-sheet-close" id="playlist-import-close" title="Fermer">✕</button>
      </div>

      <!-- Masqué tant qu'un import tourne déjà (voir updateSourceVisibility
           dans setupPlaylistImport()) : lancer un second import en parallèle
           mélangerait deux process de téléchargement concurrents (progression,
           playlists créées...) — il faut d'abord laisser celui en cours
           se terminer. -->
      <div id="playlist-import-source">
        <form id="playlist-import-form">
          <label for="playlist-import-url">Lien de playlist publique</label>
          <input type="text" id="playlist-import-url" placeholder="https://open.spotify.com/playlist/…">
          <div class="hint" style="margin-top:6px;">
            Spotify, Deezer, YouTube Music ou Apple Music. La playlist doit être publique/partageable.
            Amazon Music n'est pas pris en charge (aucune page publique exploitable).
          </div>
          <button type="submit" class="primary" id="playlist-import-analyze-btn">Analyser</button>
        </form>

        <!-- Alternative au lien : un export CSV (Exportify, Soundiiz, TuneMyMusic,
             l'export "bibliothèque" de Spotify…) — colonnes reconnues au nom,
             insensible à la casse : titre/track, artiste/artist, et
             playlist/album si présente (un fichier peut contenir plusieurs
             playlists, groupées par cette colonne — une playlist locale sera
             créée par groupe). -->
        <div class="playlist-import-divider"><span>ou</span></div>
        <label class="playlist-import-dropzone" id="playlist-import-dropzone" for="playlist-import-file">
          <input type="file" id="playlist-import-file" accept=".csv,text/csv" class="playlist-import-dropzone-input">
          <div class="playlist-import-dropzone-empty" id="playlist-import-dropzone-empty">
            <svg class="playlist-import-dropzone-icon" viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 15V3"></path><path d="M7 8l5-5 5 5"></path><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"></path>
            </svg>
            <div class="playlist-import-dropzone-text">
              <strong>Glisse ton fichier CSV ici</strong>
              <span>ou clique pour parcourir — export Spotify/Deezer/autre</span>
            </div>
          </div>
          <div class="playlist-import-dropzone-file" id="playlist-import-dropzone-file" style="display:none;">
            <svg class="playlist-import-dropzone-file-icon" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path>
            </svg>
            <span class="playlist-import-dropzone-filename" id="playlist-import-dropzone-filename"></span>
            <button type="button" class="playlist-import-dropzone-clear" id="playlist-import-dropzone-clear" title="Retirer ce fichier">✕</button>
          </div>
        </label>
      </div>

      <div class="status-line" id="playlist-import-locked-status">Un import est déjà en cours — attends qu'il se termine avant d'en lancer un nouveau.</div>

      <div class="status-line" id="playlist-import-status"></div>

      <div class="playlist-import-result" id="playlist-import-result" style="display:none;">
        <div class="playlist-import-result-header" id="playlist-import-name-row">
          <span>Nom de la playlist</span>
          <input type="text" id="playlist-import-name" maxlength="80">
        </div>
        <div class="playlist-import-tracks" id="playlist-import-tracks"></div>
        <button type="button" class="primary" id="playlist-import-start-btn">Importer <span id="playlist-import-count"></span></button>
      </div>
    </div>
  </div>

  <!-- Cercle flottant : replié pendant un import de playlist en cours, pour
       laisser écouter la bibliothèque sans que la feuille plein écran ne
       bloque tout — voir setupPlaylistImport(). Même langage visuel que le
       donut de stockage (storage-donut) : anneau conic-gradient percé au
       centre plutôt qu'un composant à part. -->
  <button type="button" class="playlist-import-mini" id="playlist-import-mini" style="display:none;" title="Import de playlist en cours — cliquer pour rouvrir">
    <div class="playlist-import-mini-donut" id="playlist-import-mini-donut"></div>
    <div class="playlist-import-mini-donut-inner" id="playlist-import-mini-donut-inner"></div>
    <div class="playlist-import-mini-donut-storage" id="playlist-import-mini-donut-storage">
      <span class="playlist-import-mini-tooltip" id="playlist-import-mini-tooltip"></span>
    </div>
    <span class="playlist-import-mini-pct" id="playlist-import-mini-pct">0%</span>
  </button>

  <!-- Récapitulatif de fin d'import : pas de fermeture au clic sur le fond
       ni de disparition automatique (comme storage-modal) — l'utilisateur
       doit avoir le temps de lire les échecs éventuels avant de la fermer
       lui-même via le bouton dédié. -->
  <div class="modal-overlay" id="playlist-import-summary-modal">
    <div class="modal">
      <h2>Import terminé</h2>
      <p class="playlist-import-summary-line" id="playlist-import-summary-count"></p>
      <p class="playlist-import-summary-line" id="playlist-import-summary-storage"></p>
      <div id="playlist-import-summary-errors-block" style="display:none;">
        <p class="playlist-import-summary-errors-title">Échecs</p>
        <div class="playlist-import-summary-errors" id="playlist-import-summary-errors"></div>
      </div>
      <button type="button" class="modal-close" id="playlist-import-summary-close">Fermer</button>
    </div>
  </div>

  <!-- Bulle agrandie affichant la lettre survolée en glissant sur l'index
       alphabétique (façon carnet de contacts) — partagée entre l'onglet
       morceaux et l'onglet clips, voir setupAzIndex(). -->
  <div class="library-az-bubble" id="library-az-bubble"></div>

  <div class="mini-player" id="mini-player">
    <div class="mini-player-row-top">
      <div class="mini-player-body">
        <div class="mini-player-title" id="mini-player-title">
          <div class="mini-player-title-track" id="mini-player-title-track">
            <span class="mini-player-title-text"></span>
            <span class="mini-player-title-text" aria-hidden="true"></span>
          </div>
        </div>
        <input type="range" class="mini-player-seek" id="mini-player-seek" value="0" min="0" max="100" step="0.1">
        <!-- Doublon de #mini-player-time, MASQUÉ par défaut (voir CSS) :
             sur mobile la durée doit apparaître ici, sous la barre, alors
             que sur desktop elle reste sous la vignette (#mini-player-time
             d'origine, inchangé) — un même élément ne peut pas être à deux
             endroits du DOM en même temps selon la largeur d'écran, d'où
             cette 2ᵉ instance tenue à jour en parallèle (voir le
             timeupdate). -->
        <div class="mini-player-time-mobile" id="mini-player-time-mobile">0:00 / 0:00</div>
        <div class="mini-player-rating" id="mini-player-rating">
          <button type="button" class="mini-star" data-value="1" title="1 étoile">★</button>
          <button type="button" class="mini-star" data-value="2" title="2 étoiles">★</button>
          <button type="button" class="mini-star" data-value="3" title="3 étoiles">★</button>
          <button type="button" class="mini-star" data-value="4" title="4 étoiles">★</button>
          <button type="button" class="mini-star" data-value="5" title="5 étoiles">★</button>
        </div>
      </div>
      <!-- Prev/vignette/Next/Lecture regroupés (voir .mini-player-center-
           cluster) : sur desktop, ce conteneur passe en display:contents
           (voir CSS) — ses enfants redeviennent des éléments flex directs
           de .mini-player, EXACTEMENT comme avant ce regroupement (prev/
           lecture/next gardent leur order explicite 20/21/22, la vignette
           reste au même endroit puisque rien ne change sa position
           relative). Ce regroupement ne sert qu'au réarrangement mobile
           ci-dessous : la vignette (et son groupe prev/next/lecture) s'y
           centre dans la largeur du footer, flanquée symétriquement par
           la ligne de temps ET le volume (tous deux réduits à égalité,
           voir leur flex mobile) — le pouce n'a jamais à s'étirer loin
           du centre. -->
      <div class="mini-player-center-cluster">
        <button type="button" class="mini-player-btn mini-player-prev" id="mini-player-prev" title="Piste précédente">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="19 20 9 12 19 4 19 20"></polygon>
            <line x1="5" y1="19" x2="5" y2="5"></line>
          </svg>
        </button>
        <div class="mini-player-time-wrap">
          <img class="mini-player-thumb" id="mini-player-thumb" alt="">
          <div class="mini-player-time" id="mini-player-time">0:00 / 0:00</div>
        </div>
        <button type="button" class="mini-player-btn mini-player-next" id="mini-player-next" title="Piste suivante">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="5 4 15 12 5 20 5 4"></polygon>
            <line x1="19" y1="5" x2="19" y2="19"></line>
          </svg>
        </button>
        <button type="button" class="mini-player-btn mini-player-play" id="mini-player-play" title="Lecture/Pause">▶</button>
      </div>
      <!-- Paroles/options/fermer regroupés ici, juste avant le volume : sur
           desktop, l'ordre explicite ci-dessous (voir CSS, order:10/11/12)
           les maintient à leur place actuelle (après vignette+volume, avant
           précédent/suivant/lecture) quelle que soit leur position physique
           dans le DOM — même principe que #mini-player-prev/-play/-next
           (order:20/21/22) plus bas. Sur mobile, ce regroupement rejoint la
           ligne unique du footer (voir music.css), l'ancienne 2ᵉ ligne
           .mini-player-row-bottom n'existe donc plus : lecteur simplifié à
           l'extrême ET footer plus bas en hauteur. -->
      <button type="button" class="mini-player-btn mini-player-lyrics-btn" id="mini-player-lyrics-btn" title="Voir les paroles" style="display:none;">
        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
          <line x1="7" y1="8" x2="17" y2="8"></line>
          <line x1="7" y1="12" x2="13" y2="12"></line>
        </svg>
      </button>
      <!-- Aléatoire/enchaînement/fondu déplacés dans une feuille à part
           (voir #mini-player-settings-sheet, plus bas dans le fichier) —
           seul ce bouton roue crantée reste dans le lecteur lui-même,
           simplifié à l'extrême : fermer, paroles, durée, volume et les 3
           contrôles de piste restent seuls visibles en permanence. -->
      <button type="button" class="mini-player-btn mini-player-settings-btn" id="mini-player-settings-btn" title="Options de lecture">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="3"></circle>
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"></path>
        </svg>
      </button>
      <button type="button" class="mini-player-btn" id="mini-player-close" title="Fermer">✕</button>
      <!-- Volume déplacé ici (hors de .mini-player-row-bottom, aujourd'hui
           supprimée) : sur desktop, sa position relative (juste après la
           vignette, avant paroles/options/fermer — order:0 par défaut,
           avant leur order:10/11/12) reste inchangée ; sur mobile, dernier
           élément de la ligne unique du footer (volume vertical, tout à
           droite). -->
      <div class="mini-player-volume-wrap">
        <span class="mini-player-volume-icon" id="mini-player-volume-icon">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="3 9 8 9 13 4 13 20 8 15 3 15 3 9"></polygon>
            <path d="M16 9a4 4 0 0 1 0 6"></path>
          </svg>
        </span>
        <input type="range" class="mini-player-volume" id="mini-player-volume" value="100" min="0" max="100" step="1" title="Volume">
      </div>
    </div>
  </div>

  <!-- Options de lecture (aléatoire/enchaînement/fondu) : sorties du
       lecteur lui-même pour le simplifier à l'extrême — accessibles via la
       roue crantée ci-dessus, dans une feuille classique (glisse du bas
       vers le haut, comme .sheet-overlay/.bottom-sheet ailleurs dans ce
       fichier) plutôt que 3 icônes visibles en permanence. -->
  <div class="sheet-overlay" id="mini-player-settings-sheet">
    <div class="bottom-sheet mini-player-settings-bottom-sheet">
      <div class="sheet-handle"></div>
      <div class="sheet-header">
        <div class="sheet-title">Options de lecture</div>
        <button type="button" class="sheet-btn" id="mini-player-settings-close" title="Fermer">✕</button>
      </div>
      <div class="mini-player-settings-body">
        <button type="button" class="mini-player-settings-row mini-player-shuffle" id="mini-player-shuffle">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="16 3 21 3 21 8"></polyline>
            <line x1="4" y1="20" x2="21" y2="3"></line>
            <polyline points="21 16 21 21 16 21"></polyline>
            <line x1="15" y1="15" x2="21" y2="21"></line>
            <line x1="4" y1="4" x2="9" y2="9"></line>
          </svg>
          <span>Lecture aléatoire</span>
        </button>
        <button type="button" class="mini-player-settings-row mini-player-autonext" id="mini-player-autonext">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="17 1 21 5 17 9"></polyline>
            <path d="M3 11V9a4 4 0 0 1 4-4h14"></path>
            <polyline points="7 23 3 19 7 15"></polyline>
            <path d="M21 13v2a4 4 0 0 1-4 4H3"></path>
          </svg>
          <span>Enchaînement automatique</span>
        </button>
        <button type="button" class="mini-player-settings-row mini-player-crossfade" id="mini-player-crossfade">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="9" cy="12" r="6"></circle>
            <circle cx="15" cy="12" r="6"></circle>
          </svg>
          <span>Fondu enchaîné</span>
        </button>
        <!-- Masqué par défaut sur mobile (voir music.css) : le bouton de
             volume matériel du téléphone suffit, pas besoin d'un curseur en
             plus dans le footer — laissé en option ici pour qui préfère
             quand même un réglage fin depuis l'appli. N'a aucun effet sur
             desktop, où le volume reste toujours affiché. -->
        <button type="button" class="mini-player-settings-row mini-player-show-volume" id="mini-player-show-volume">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="3 9 8 9 13 4 13 20 8 15 3 15 3 9"></polygon>
            <path d="M16 9a4 4 0 0 1 0 6"></path>
          </svg>
          <span>Bouton de volume (mobile)</span>
        </button>
      </div>
    </div>
  </div>

  <div class="video-modal-overlay" id="video-modal">
    <div class="video-modal">
      <div class="video-modal-header">
        <div class="video-modal-header-main">
          <div class="video-modal-title" id="video-modal-title"></div>
          <!-- Ligne de temps custom, affichée uniquement en plein écran (voir
               .video-immersive dans music.css) : les contrôles natifs restent
               en bas (lecture, volume...), mais leur barre de progression y
               est peu visible/accessible une fois le clip en plein écran —
               celle-ci la duplique en haut à gauche, bien plus atteignable. -->
          <div class="video-timeline" id="video-timeline">
            <input type="range" class="video-timeline-seek" id="video-timeline-seek" value="0" min="0" max="100" step="0.1">
            <span class="video-timeline-time" id="video-timeline-time">0:00 / 0:00</span>
          </div>
        </div>
        <div class="video-modal-actions">
          <button type="button" class="video-modal-btn" id="video-prev-btn" title="Clip précédent" aria-label="Clip précédent">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polygon points="19 20 9 12 19 4 19 20"></polygon>
              <line x1="5" y1="19" x2="5" y2="5"></line>
            </svg>
          </button>
          <button type="button" class="video-modal-btn" id="video-next-btn" title="Clip suivant" aria-label="Clip suivant">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polygon points="5 4 15 12 5 20 5 4"></polygon>
              <line x1="19" y1="5" x2="19" y2="19"></line>
            </svg>
          </button>
          <button type="button" class="video-modal-btn" id="video-lyrics-btn" title="Voir les paroles" style="display:none;">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
              <line x1="7" y1="8" x2="17" y2="8"></line>
              <line x1="7" y1="12" x2="13" y2="12"></line>
            </svg>
          </button>
          <!-- Construire une synchro à la main (voir #video-lyrics-sync-
               controls plus bas) : uniquement quand des paroles BRUTES
               existent mais qu'aucun minutage exploitable n'a été trouvé —
               jamais si une synchro (même rejetée pour incohérence de durée)
               est déjà disponible, ni tant qu'aucune parole brute n'existe
               du tout (rien à synchroniser). Contribue au cache PARTAGÉ
               (voir lyrics-manual-sync.php) : profite à tous les comptes. -->
          <button type="button" class="video-modal-btn" id="video-lyrics-sync-start-btn" title="Créer une synchro" style="display:none;">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="9"></circle>
              <circle cx="12" cy="12" r="4"></circle>
              <circle cx="12" cy="12" r="0.5" fill="currentColor"></circle>
            </svg>
          </button>
          <button type="button" class="video-modal-btn" id="video-fullscreen-btn" title="Plein écran">⛶</button>
          <button type="button" class="video-modal-btn" id="video-modal-close" title="Fermer">✕</button>
        </div>
      </div>
      <video id="video-player" controls playsinline controlslist="nofullscreen"></video>
      <div class="video-lyrics-bar" id="video-lyrics-bar"></div>
      <!-- Fiche artiste du clip en cours — parité avec l'audio (voir
           #desktop-artist-panel) : ce dernier vit dans la page, invisible
           derrière le fond opaque du modal vidéo (voir .video-modal-overlay),
           d'où ce bloc dédié À L'INTÉRIEUR du modal plutôt qu'une réutilisation
           du panneau desktop. Masqué en plein écran immersif, comme les
           paroles juste au-dessus (plus de place pour ni l'un ni l'autre). -->
      <div class="video-artist-bar" id="video-artist-bar"></div>
      <!-- Décalage du clip : enregistré côté serveur (lyrics-offset.php,
           variante "video"), distinct de celui de la feuille audio. -->
      <div class="video-lyrics-offset-row" id="video-lyrics-offset-row">
        <button type="button" class="video-lyrics-offset-now" id="video-lyrics-offset-now"
                title="Mettre en pause sur le premier mot chanté, puis cliquer : le décalage se règle tout seul">Les paroles commencent ici</button>
        <span class="video-lyrics-offset-value" id="video-lyrics-offset-value"></span>
        <span class="video-lyrics-offset-status" id="video-lyrics-offset-status"></span>
      </div>
      <!-- Barre de contrôle du mode "synchro manuelle" : une ligne à la fois,
           l'utilisateur clique/tape sur la ligne EN COURS (surlignée dans
           #video-lyrics-bar, passé en .sync-mode) au moment où elle est
           chantée — voir startManualLyricsSync(). "Passer" pour une ligne
           instrumentale/sans parole exploitable (exclue du résultat final,
           pas de trou dans le minutage). -->
      <div class="video-lyrics-sync-controls" id="video-lyrics-sync-controls">
        <div class="sync-guide" role="group" aria-label="Synchroniser les paroles">
          <div id="sync-instruction">Écoute le clip. Au premier mot de la ligne affichée, clique sur le grand bouton ou appuie sur Espace. Molette sur la ligne pour réécouter une ligne déjà calée.</div>
          <span class="video-lyrics-sync-progress" id="video-lyrics-sync-progress" role="status"></span>
          <div id="sync-current-line"></div><div id="sync-next-line"></div>
          <button type="button" id="sync-mark">Démarrer le clip depuis le début</button>
          <div class="sync-guide-tools"><button type="button" id="sync-pause">Pause</button><button type="button" id="sync-undo">Corriger la dernière ligne</button></div>
        </div>
        <button type="button" class="video-lyrics-sync-skip" id="video-lyrics-sync-skip">Ligne non chantée : ignorer</button>
        <button type="button" class="video-lyrics-sync-save" id="video-lyrics-sync-save" disabled>Enregistrer</button>
        <button type="button" class="video-lyrics-sync-cancel" id="video-lyrics-sync-cancel">Quitter sans enregistrer</button>
      </div>
    </div>
  </div>

  <div class="sheet-overlay" id="playlist-sheet">
    <div class="bottom-sheet">
      <div class="sheet-handle"></div>
      <div class="sheet-header">
        <button type="button" class="sheet-btn" id="playlist-back-btn" title="Retour" style="display:none;">←</button>
        <div class="sheet-title" id="playlist-sheet-title">Mes playlists</div>
        <input type="text" class="sheet-title-input" id="playlist-rename-input" maxlength="80" style="display:none;">
        <button type="button" class="sheet-btn" id="playlist-rename-confirm" title="Valider" style="display:none;">✓</button>
        <button type="button" class="sheet-btn" id="playlist-play-btn" title="Lire la playlist" style="display:none;">▶</button>
        <button type="button" class="sheet-btn" id="playlist-rename-btn" title="Renommer" style="display:none;">✎</button>
        <button type="button" class="sheet-btn danger" id="playlist-delete-btn" title="Supprimer la playlist" style="display:none;">🗑</button>
        <button type="button" class="sheet-btn" id="playlist-close-btn" title="Fermer">✕</button>
      </div>
      <!-- Bascule persistante (voir ytdl_playlist_focus) : au lancement d'une
           playlist, remplace les onglets Audio/Clips par la playlist en
           cours plutôt que de laisser toute la bibliothèque affichée derrière
           — visible aussi bien depuis la liste que depuis le détail d'une
           playlist, pour pouvoir la couper à tout moment. -->
      <label class="switch-row playlist-focus-row">
        <input type="checkbox" id="playlist-focus-toggle" checked>
        <span class="switch-track"></span>
        <span>N'afficher que la playlist en cours de lecture</span>
      </label>
      <div class="sheet-body" id="playlist-sheet-body"></div>
      <button type="button" class="sheet-new-btn" id="playlist-new-btn">+ Nouvelle playlist</button>
      <div class="sheet-new-form" id="playlist-new-form" style="display:none;">
        <input type="text" class="sheet-new-input" id="playlist-new-input" maxlength="80" placeholder="Nom de la playlist…">
        <button type="button" class="sheet-btn" id="playlist-new-confirm" title="Créer">✓</button>
        <button type="button" class="sheet-btn" id="playlist-new-cancel" title="Annuler">✕</button>
      </div>
    </div>
  </div>

  <!-- Recherche globale : seul point d'entrée depuis "Others" ou la vue
       playlist en cours (ni l'un ni l'autre n'a de champ de recherche
       propre) — en overlay plutôt qu'en changeant d'onglet, pour ne jamais
       faire disparaître ce qu'on regardait (la playlist en cours de lecture,
       notamment) juste pour lancer une recherche. Cherche dans l'ensemble de
       la bibliothèque (audio + clips + others), pas de repli YouTube ici :
       ça reste le rôle des recherches propres à chaque onglet. -->
  <div class="sheet-overlay" id="global-search-sheet">
    <div class="bottom-sheet">
      <div class="sheet-handle"></div>
      <div class="sheet-header">
        <div class="sheet-title">Rechercher</div>
        <button type="button" class="sheet-btn" id="global-search-close" title="Fermer">✕</button>
      </div>
      <div class="library-search-shell open" id="global-search-shell">
        <input type="text" class="library-search-input" id="global-search-input" placeholder="Titre, artiste…">
        <button type="button" class="library-search-clear" id="global-search-clear" title="Effacer">✕</button>
      </div>
      <div class="sheet-body" id="global-search-results"></div>
    </div>
  </div>

  <div class="sheet-overlay" id="lyrics-sheet">
    <div class="bottom-sheet" id="lyrics-bottom-sheet">
      <div class="sheet-handle"></div>
      <div class="sheet-header">
        <div class="sheet-title" id="lyrics-sheet-title">Paroles</div>
        <!-- Volume : même curseur que le mini-lecteur, tenu synchronisé avec
             lui dans les deux sens (voir setVolume côté JS) — pratique pour
             ajuster sans quitter la feuille de paroles. -->
        <div class="mini-player-volume-wrap lyrics-volume-wrap" id="lyrics-volume-wrap">
          <span class="mini-player-volume-icon" id="lyrics-volume-icon">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polygon points="3 9 8 9 13 4 13 20 8 15 3 15 3 9"></polygon>
              <path d="M16 9a4 4 0 0 1 0 6"></path>
            </svg>
          </span>
          <input type="range" class="mini-player-volume" id="lyrics-volume" value="100" min="0" max="100" step="1" title="Volume">
        </div>
        <!-- Transport (précédent/pause/suivant) : change de morceau sans
             avoir à fermer la feuille — réutilise exactement les mêmes
             fonctions que le mini-lecteur (goToPreviousTrack,
             togglePlayPause, goToNextTrack). Juste à côté de la croix, pas
             une rangée à part : la feuille de paroles doit rester ce qui
             capte l'attention. -->
        <div class="lyrics-transport-row" id="lyrics-transport-row">
          <button type="button" class="lyrics-transport-btn" id="lyrics-prev-btn" title="Piste précédente">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polygon points="19 20 9 12 19 4 19 20"></polygon>
              <line x1="5" y1="19" x2="5" y2="5"></line>
            </svg>
          </button>
          <button type="button" class="lyrics-transport-btn lyrics-transport-play" id="lyrics-playpause-btn" title="Lecture/Pause">▶</button>
          <button type="button" class="lyrics-transport-btn" id="lyrics-next-btn" title="Piste suivante">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polygon points="5 4 15 12 5 20 5 4"></polygon>
              <line x1="19" y1="5" x2="19" y2="19"></line>
            </svg>
          </button>
        </div>
        <button type="button" class="sheet-btn" id="lyrics-close-btn" title="Fermer">✕</button>
      </div>
      <!-- Décalage manuel du minutage, uniquement pertinent si les paroles
           sont synchronisées — masqué pour un simple texte brut. Appliqué et
           mémorisé pour tout le monde sur ce morceau (voir lyrics-offset.php). -->
      <div class="lyrics-offset-row" id="lyrics-offset-row" style="display:none;">
        <button type="button" class="lyrics-offset-now" id="lyrics-offset-now"
                title="Mettre en pause sur le premier mot chanté, puis cliquer : le décalage se règle tout seul">Les paroles commencent ici</button>
        <span class="lyrics-offset-value" id="lyrics-offset-value"></span>
      </div>
      <div class="sheet-body lyrics-sheet-body" id="lyrics-sheet-body"></div>
    </div>
  </div>

  <div class="modal-overlay" id="unshare-confirm-modal">
    <div class="modal">
      <h2>Retirer ce partage ?</h2>
      <p class="delete-confirm-text">
        Tu n'auras plus accès à « <strong id="unshare-confirm-title"></strong> ».
        <strong>Le fichier n'est pas supprimé</strong> : il reste chez la personne qui te l'a partagé,
        qui pourra te le repartager.
      </p>
      <div class="delete-confirm-actions">
        <button type="button" class="modal-close" id="unshare-cancel-btn">Annuler</button>
        <button type="button" class="delete-confirm-btn" id="unshare-confirm-btn">Retirer</button>
      </div>
    </div>
  </div>

  <div class="modal-overlay" id="playlist-delete-modal">
    <div class="modal">
      <h2>Supprimer la playlist ?</h2>
      <p class="delete-confirm-text">
        « <strong id="playlist-delete-name"></strong> » sera supprimée.
        <strong>Les morceaux et les clips restent dans ta bibliothèque</strong> — seul le regroupement disparaît.
      </p>
      <div class="delete-confirm-actions">
        <button type="button" class="modal-close" id="playlist-delete-cancel">Annuler</button>
        <button type="button" class="delete-confirm-btn" id="playlist-delete-confirm">Supprimer</button>
      </div>
    </div>
  </div>

  <div class="modal-overlay" id="playlist-pick-modal">
    <div class="modal">
      <h2>Ajouter à une playlist</h2>
      <p class="delete-confirm-text">« <strong id="playlist-pick-title"></strong> »</p>
      <div class="playlist-pick-list" id="playlist-pick-list"></div>
      <div class="sheet-new-form" style="margin-top: 10px;">
        <input type="text" class="sheet-new-input" id="playlist-pick-new" maxlength="80" placeholder="Nouvelle playlist…">
        <button type="button" class="sheet-btn" id="playlist-pick-create" title="Créer et ajouter">✓</button>
      </div>
      <button type="button" class="modal-close" id="playlist-pick-close">Fermer</button>
    </div>
  </div>

  <div class="modal-overlay" id="duplicate-modal">
    <div class="modal">
      <h2>Déjà téléchargé</h2>
      <p class="delete-confirm-text" id="duplicate-modal-text"></p>
      <p class="delete-confirm-text dup-hint">
        Deux morceaux peuvent porter le même titre sans être le même
        enregistrement — à toi de voir.
      </p>
      <div class="dup-actions">
        <button type="button" class="modal-close" id="duplicate-skip-btn">Passer</button>
        <button type="button" class="dup-force-btn" id="duplicate-force-btn">Télécharger quand même</button>
      </div>
    </div>
  </div>

  <div class="modal-overlay" id="storage-modal">
    <div class="modal">
      <h2>Espace de stockage</h2>
      <div class="storage-modal-widget">
        <div class="storage-donut storage-modal-donut" id="storage-modal-donut"></div>
        <div class="storage-modal-breakdown">
          <div class="storage-modal-row">
            <div class="storage-modal-row-label"><span class="storage-dot audio"></span>MP3</div>
            <div class="storage-modal-row-value" id="storage-modal-audio-size"></div>
            <div class="storage-modal-row-sub" id="storage-modal-audio-detail"></div>
          </div>
          <div class="storage-modal-row">
            <div class="storage-modal-row-label"><span class="storage-dot video"></span>Clips</div>
            <div class="storage-modal-row-value" id="storage-modal-video-size"></div>
            <div class="storage-modal-row-sub" id="storage-modal-video-detail"></div>
          </div>
          <div class="storage-modal-row">
            <div class="storage-modal-row-label"><span class="storage-dot other"></span>Hors musique</div>
            <div class="storage-modal-row-value" id="storage-modal-other-size"></div>
            <div class="storage-modal-row-sub" id="storage-modal-other-detail"></div>
          </div>
          <div class="storage-modal-row">
            <div class="storage-modal-row-label"><span class="storage-dot free"></span>Libre</div>
            <div class="storage-modal-row-value" id="storage-modal-free-size"></div>
          </div>
        </div>
      </div>
      <p class="storage-modal-total" id="storage-modal-total"></p>
      <button type="button" class="modal-close" id="storage-modal-close">Fermer</button>
    </div>
  </div>

  <div class="modal-overlay" id="delete-confirm-modal">
    <div class="modal">
      <h2>Supprimer définitivement ?</h2>
      <p class="delete-confirm-text">
        « <strong id="delete-confirm-title"></strong> » sera effacé du serveur et disparaîtra de la
        bibliothèque. Cette action est irréversible — il faudra retélécharger le fichier pour le récupérer.
      </p>
      <p class="delete-confirm-text delete-confirm-warning" id="delete-confirm-warning" style="display:none; color: var(--error); margin-top: 10px;"></p>
      <div class="delete-confirm-actions">
        <button type="button" class="modal-close" id="delete-cancel-btn">Annuler</button>
        <button type="button" class="delete-confirm-btn" id="delete-confirm-btn">Supprimer</button>
      </div>
    </div>
  </div>

  <!-- Renommage (admin, mobile) : voir openMobileRenameModal — le petit
       écran d'un téléphone ne laisse pas la place à un renommage EN PLACE
       façon Explorateur Windows (voir .library-rename-input, desktop) sans
       recouvrir le reste de la ligne ; une modale isole l'action et affiche
       le titre en plus gros, plus confortable à corriger du pouce. -->
  <div class="modal-overlay" id="mobile-rename-modal">
    <div class="modal">
      <h2>Renommer le titre</h2>
      <input type="text" class="mobile-rename-input" id="mobile-rename-input" maxlength="300">
      <div class="delete-confirm-actions">
        <button type="button" class="modal-close" id="mobile-rename-cancel-btn">Annuler</button>
        <button type="button" class="delete-confirm-btn" id="mobile-rename-confirm-btn">Modifier</button>
      </div>
    </div>
  </div>

  <!-- Note d'un titre (voir openRatingModal) : isolée en modale plutôt qu'un
       petit sélecteur flottant près du bouton "★" du panneau de glissement —
       tout le reste de l'appli devient inatteignable tant qu'elle est
       ouverte, toucher le fond assombri la referme sans rien changer. -->
  <div class="rating-modal-overlay" id="rating-modal-overlay">
    <div class="rating-modal">
      <div class="rating-modal-title" id="rating-modal-title"></div>
      <div class="rating-modal-stars" id="rating-modal-stars">
        <button type="button" data-value="1">★</button>
        <button type="button" data-value="2">★</button>
        <button type="button" data-value="3">★</button>
        <button type="button" data-value="4">★</button>
        <button type="button" data-value="5">★</button>
      </div>
    </div>
  </div>

  <div class="modal-overlay" id="share-modal">
    <div class="modal">
      <h2>Partager « <span id="share-modal-title"></span> »</h2>
      <div class="share-user-list" id="share-user-list"></div>
      <div class="share-modal-actions">
        <button type="button" class="modal-close" id="share-cancel-btn">Annuler</button>
        <button type="button" class="share-save-btn" id="share-save-btn">Partager</button>
      </div>
    </div>
  </div>

  <div class="profile-drawer-overlay" id="profile-drawer-overlay">
    <div class="profile-drawer">
      <div class="profile-drawer-header">
        <h2>Mon profil</h2>
        <button type="button" class="download-sheet-close" id="profile-drawer-close" title="Fermer">✕</button>
      </div>

      <div class="profile-section">
        <label class="profile-section-label">Avatar</label>
        <div class="avatar-grid" id="profile-avatar-grid">
          <?php foreach ($avatarFiles as $file): ?>
            <div class="avatar-choice<?php echo $file === $user['avatar'] ? ' selected' : ''; ?>" data-avatar="<?php echo htmlspecialchars($file); ?>">
              <img src="avatars/<?php echo htmlspecialchars($file); ?>" alt="">
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="profile-section">
        <label class="profile-section-label" for="profile-email-input">Adresse email</label>
        <p class="email-modal-text">
          Actuelle : <strong><?php echo htmlspecialchars($user['email']); ?></strong>
        </p>
        <input type="email" class="email-modal-input" id="profile-email-input" placeholder="nouvelle@adresse.fr" autocomplete="email" inputmode="email">
        <p class="email-modal-text email-modal-hint">
          Laisse vide pour ne rien changer. Sinon, un lien de confirmation sera envoyé à ton adresse <strong>actuelle</strong> — rien ne change tant que tu ne l'as pas ouvert.
        </p>
      </div>

      <div class="profile-section">
        <label class="profile-section-label">Apparence</label>
        <div class="skin-grid" id="profile-skin-grid">
          <button type="button" class="skin-choice" data-skin="sombre" title="Sombre">
            <span class="skin-swatch skin-swatch-sombre"></span><span>Sombre</span>
          </button>
          <button type="button" class="skin-choice" data-skin="clair" title="Clair">
            <span class="skin-swatch skin-swatch-clair"></span><span>Clair</span>
          </button>
          <button type="button" class="skin-choice" data-skin="ambre" title="Ambre">
            <span class="skin-swatch skin-swatch-ambre"></span><span>Ambre</span>
          </button>
          <button type="button" class="skin-choice" data-skin="kawaii" title="Kawaii">
            <span class="skin-swatch skin-swatch-kawaii"></span><span>Kawaii</span>
          </button>
          <button type="button" class="skin-choice" data-skin="sillon" title="Sillon — nouvelle interface">
            <span class="skin-swatch skin-swatch-sillon"></span><span>Sillon</span>
          </button>
          <button type="button" class="skin-choice" data-skin="orbite" title="Orbite — nouvelle interface">
            <span class="skin-swatch skin-swatch-orbite"></span><span>Orbite</span>
          </button>
          <button type="button" class="skin-choice" data-skin="platine" title="Platine — nouvelle interface">
            <span class="skin-swatch skin-swatch-platine"></span><span>Platine</span>
          </button>
          <button type="button" class="skin-choice" data-skin="heros" title="Héros — manga">
            <span class="skin-swatch skin-swatch-heros"></span><span>Héros</span>
          </button>
          <button type="button" class="skin-choice" data-skin="galaxie" title="Galaxie — espace">
            <span class="skin-swatch skin-swatch-galaxie"></span><span>Galaxie</span>
          </button>
          <button type="button" class="skin-choice" data-skin="retro" title="Néo-rétro">
            <span class="skin-swatch skin-swatch-retro"></span><span>Néo-rétro</span>
          </button>
          <button type="button" class="skin-choice" data-skin="arcade" title="Arcade — jeux vidéo">
            <span class="skin-swatch skin-swatch-arcade"></span><span>Arcade</span>
          </button>
          <button type="button" class="skin-choice" data-skin="nature" title="Nature">
            <span class="skin-swatch skin-swatch-nature"></span><span>Nature</span>
          </button>
          <button type="button" class="skin-choice" data-skin="muse" title="Muse">
            <span class="skin-swatch skin-swatch-muse"></span><span>Muse</span>
          </button>
          <button type="button" class="skin-choice" data-skin="aurore" title="Aurore">
            <span class="skin-swatch skin-swatch-aurore"></span><span>Aurore</span>
          </button>
          <button type="button" class="skin-choice" data-skin="bauhaus" title="Bauhaus">
            <span class="skin-swatch skin-swatch-bauhaus"></span><span>Bauhaus</span>
          </button>
          <button type="button" class="skin-choice" data-skin="carnet" title="Carnet">
            <span class="skin-swatch skin-swatch-carnet"></span><span>Carnet</span>
          </button>
          <button type="button" class="skin-choice" data-skin="laiton" title="Laiton">
            <span class="skin-swatch skin-swatch-laiton"></span><span>Laiton</span>
          </button>
        </div>
      </div>

      <div class="profile-section">
        <label class="profile-section-label">Notifications</label>
        <label class="switch-row">
          <input type="checkbox" id="profile-push-toggle">
          <span class="switch-track"></span>
          <span>Me notifier des partages reçus</span>
        </label>
      </div>

      <button type="button" class="primary profile-save-btn" id="profile-save-btn">Enregistrer</button>
    </div>
  </div>

<script>
  // Réservé au renommage manuel des titres (voir libraryActionsHtml/
  // bindLibraryActionButtons, bouton "Renommer" — style renommage de
  // fichier Windows) : PHP est la seule source fiable du statut admin,
  // jamais une vérification côté client seule.
  const IS_ADMIN = <?php echo $user['is_admin'] ? 'true' : 'false'; ?>;
  const form = document.getElementById('dl-form');
  const btn = document.getElementById('dl-btn');
  const urlRows = document.getElementById('url-rows');
  const audioOnlyCheckbox = document.getElementById('audio-only');
  const otherContentCheckbox = document.getElementById('other-content');
  const downloadToDeviceSwitch = document.getElementById('download-to-device');
  const DOWNLOAD_TO_DEVICE_KEY = 'ytdl_download_to_device';
  // Activé par défaut (appareil ET serveur) ; le choix de l'utilisateur est
  // mémorisé d'une session à l'autre, pas juste le temps d'un lot de
  // téléchargements.
  try {
    downloadToDeviceSwitch.checked = localStorage.getItem(DOWNLOAD_TO_DEVICE_KEY) !== '0';
  } catch (err) { /* mode privé strict : reste coché */ }
  downloadToDeviceSwitch.addEventListener('change', () => {
    try {
      localStorage.setItem(DOWNLOAD_TO_DEVICE_KEY, downloadToDeviceSwitch.checked ? '1' : '0');
    } catch (err) { /* pas bloquant */ }
  });
  const progressBlock = document.getElementById('progress-block');
  const progressLabel = document.getElementById('progress-label');
  const progressPercent = document.getElementById('progress-percent');
  const progressFill = document.getElementById('progress-fill');
  const statusLine = document.getElementById('status-line');
  const previewCard = document.getElementById('preview-card');
  const previewThumb = document.getElementById('preview-thumb');
  const previewTitle = document.getElementById('preview-title');
  const previewMeta = document.getElementById('preview-meta');
  const retryBlock = document.getElementById('retry-block');
  const retryCount = document.getElementById('retry-count');
  const retryLabel = document.getElementById('retry-label');
  const retryProverb = document.getElementById('retry-proverb');


  const TIME_PROVERBS = [
    "Chaque chose en son temps.",
    "Le temps guérit toutes les blessures.",
    "Rome ne s'est pas faite en un jour.",
    "Qui trop se hâte reste en chemin.",
    "Il n'y a pas d'heure pour les braves.",
    "Le temps perdu ne se rattrape jamais.",
    "Patience et longueur de temps font plus que force ni que rage.",
    "Tout vient à point à qui sait attendre.",
    "Un tiens vaut mieux que deux tu l'auras.",
    "Le temps est un grand maître."
  ];

  function randomProverb() {
    return TIME_PROVERBS[Math.floor(Math.random() * TIME_PROVERBS.length)];
  }

  // Compte à rebours affiché entre deux tentatives de retéléchargement,
  // avec un proverbe sur le temps tiré au hasard pour faire patienter.
  function waitWithCountdown(seconds, label) {
    return new Promise(resolve => {
      retryBlock.classList.add('visible');
      retryLabel.textContent = label;
      retryProverb.textContent = '« ' + randomProverb() + ' »';
      let remaining = seconds;
      retryCount.textContent = remaining;
      const timer = setInterval(() => {
        remaining--;
        retryCount.textContent = Math.max(remaining, 0);
        if (remaining <= 0) {
          clearInterval(timer);
          retryBlock.classList.remove('visible');
          resolve();
        }
      }, 1000);
    });
  }

  // --- Menu utilisateur ---
  const userBtn = document.getElementById('user-btn');
  const userDropdown = document.getElementById('user-dropdown');
  userBtn.addEventListener('click', () => userDropdown.classList.toggle('visible'));
  document.addEventListener('click', (e) => {
    if (!userBtn.contains(e.target) && !userDropdown.contains(e.target)) {
      userDropdown.classList.remove('visible');
    }
  });

  // Même menu, doublé pour mobile (voir #mobile-user-menu et son commentaire
  // HTML) — la sidebar qui porte #user-btn est masquée en dessous de 760px,
  // ce bouton est le seul accès à "Mon profil"/Administration/Déconnexion
  // sur téléphone. Le clic ouvre soit ce menu, soit — la première fois,
  // tant que le geste de glissement n'a pas été montré — la démo animée
  // (voir setupSwipeHintAvatar plus bas, qui intercepte ce même clic).
  const mobileUserBtn = document.getElementById('mobile-user-btn');
  const mobileUserDropdown = document.getElementById('mobile-user-dropdown');
  mobileUserBtn.addEventListener('click', () => mobileUserDropdown.classList.toggle('visible'));
  document.addEventListener('click', (e) => {
    if (!mobileUserBtn.contains(e.target) && !mobileUserDropdown.contains(e.target)) {
      mobileUserDropdown.classList.remove('visible');
    }
  });
  document.getElementById('mobile-open-profile-btn').addEventListener('click', () => {
    mobileUserDropdown.classList.remove('visible');
    openProfileDrawer();
  });

  // Notifications "nouveauté" (voir db.php + pendingNotifications côté PHP) :
  // lueur néon en boucle sur l'avatar ET sur "Mon profil" tant qu'il en reste
  // au moins une non vue — un compte peut en avoir plusieurs à la fois. Un
  // seul type géré pour l'instant ('skins'), qui pointe vers la section
  // Apparence une fois le panneau ouvert — voir openProfileDrawer.
  const pendingNotifications = <?php echo json_encode($pendingNotifications); ?>;
  const openProfileBtn = document.getElementById('open-profile-btn');
  if (pendingNotifications.length) {
    userBtn.classList.add('neon-pending');
    openProfileBtn.classList.add('neon-pending');
  }
  // Retire CETTE notification précise de la liste locale et côté serveur —
  // jamais toutes d'un coup — et n'éteint le néon de l'avatar/menu qu'une
  // fois la liste complètement vide.
  function acknowledgeNotification(notificationId) {
    const idx = pendingNotifications.findIndex(n => n.id === notificationId);
    if (idx === -1) return;
    pendingNotifications.splice(idx, 1);
    if (!pendingNotifications.length) {
      userBtn.classList.remove('neon-pending');
      openProfileBtn.classList.remove('neon-pending');
    }
    fetch('notification-ack.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: notificationId }),
    }).catch(() => {});
  }

  // --- Panneau "Mon profil" ---
  // Un seul panneau regroupant avatar, email et notifications ; rien n'est
  // appliqué immédiatement (contrairement à l'ancien menu) sauf le choix
  // d'avatar dans la grille qui reste juste "sélectionné" visuellement tant
  // qu'on n'a pas cliqué sur Enregistrer, comme les deux autres champs.
  const profileDrawerOverlay = document.getElementById('profile-drawer-overlay');
  const profileDrawerClose = document.getElementById('profile-drawer-close');
  const profileAvatarGrid = document.getElementById('profile-avatar-grid');
  const profileEmailInput = document.getElementById('profile-email-input');
  const profilePushToggle = document.getElementById('profile-push-toggle');
  const profileSaveBtn = document.getElementById('profile-save-btn');
  let selectedAvatar = <?php echo json_encode($user['avatar']); ?>;

  // Skins : appliqués tout de suite au clic (pas besoin d'« Enregistrer »),
  // et déjà posés sur <html> avant même ce script (voir le petit script en
  // tête de <head>, qui évite le flash du skin sombre par défaut).
  const SKIN_KEY = 'ytdl_skin';
  const profileSkinGrid = document.getElementById('profile-skin-grid');
  function currentSkin() {
    return document.documentElement.getAttribute('data-skin') || 'sombre';
  }
  // N'applique QUE la mise en surbrillance du bon bouton — jamais l'attribut
  // lui-même : celui-ci est déjà posé sur <html> par le petit script en tête
  // de <head> (avant même ce script), pour éviter le flash au chargement.
  function syncSkinButtons(skin) {
    profileSkinGrid.querySelectorAll('.skin-choice').forEach(c => {
      c.classList.toggle('active', c.dataset.skin === skin);
    });
  }
  syncSkinButtons(currentSkin());
  profileSkinGrid.querySelectorAll('.skin-choice').forEach(choice => {
    choice.addEventListener('click', async () => {
      const skin = choice.dataset.skin;
      if (skin === currentSkin()) return; // déjà le skin actif, rien à faire
      try { localStorage.setItem(SKIN_KEY, skin); } catch (err) {}
      // Sillon vit dans sa propre page (voir le script de tête).
      // Enregistré aussi sur le compte, pour tous les appareils.
      // Attendu avant de recharger : le script de tête relit l'apparence du
      // compte, qui doit déjà être la nouvelle.
      await fetch('set-skin.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ skin: skin }) }).catch(() => {});
      if (['sillon', 'orbite', 'platine', 'heros', 'galaxie', 'retro', 'arcade', 'nature', 'muse', 'aurore', 'bauhaus', 'carnet', 'laiton'].indexOf(skin) !== -1) { location.href = skin + '.php'; return; }
      // Un simple changement de l'attribut data-skin suffit en théorie (les
      // variables CSS sont réactives), mais l'app installée en PWA sur
      // mobile ne le répercute pas de façon fiable sans un rechargement —
      // on force donc un rechargement complet, qui repart du bon skin dès le
      // script de tête (pas de flash de l'ancien skin entre-temps).
      location.reload();
    });
  });

  // Personnage de la barre latérale (skin Kawaii) : les pupilles suivent la
  // souris, la bouche change de forme au hasard de temps en temps. Coûte
  // rien tant que le skin Kawaii n'est pas actif (l'élément reste caché en
  // CSS, mais la souris étant rarement immobile, autant garder ce petit
  // écouteur global léger plutôt que de le brancher/débrancher au choix du
  // skin — deux cercles à translater, négligeable).
  (function setupKawaiiMascot() {
    const mascotEl = document.querySelector('.kawaii-sidebar-mascot');
    const pupils = document.querySelectorAll('.kawaii-pupil');
    const mouth = document.querySelector('.kawaii-mascot-mouth');
    const tail = document.querySelector('.kawaii-tail');
    if (!pupils.length && !mouth) return;
    let asleep = false; // pendant la sieste, la bouche/queue arrêtent de bouger
    const MAX_OFFSET = 2.2; // px dans le repère du viewBox (yeux de rayon 8, pupille 5)
    document.addEventListener('mousemove', e => {
      pupils.forEach(p => {
        const svg = p.ownerSVGElement;
        if (!svg) return;
        const rect = svg.getBoundingClientRect();
        const angle = Math.atan2(e.clientY - (rect.top + rect.height / 2), e.clientX - (rect.left + rect.width / 2));
        p.setAttribute('transform', 'translate(' + (Math.cos(angle) * MAX_OFFSET).toFixed(2) + ',' + (Math.sin(angle) * MAX_OFFSET).toFixed(2) + ')');
      });
    }, { passive: true });

    if (mouth) {
      const MOUTH_OPEN = 'M56 77 a4 4 0 1 0 8 0 a4 4 0 1 0 -8 0'; // bouche en "o"
      const MOUTHS = [
        'M52 76 Q60 82 68 76',   // sourire
        'M54 78 Q60 74 66 78',   // moue curieuse
        MOUTH_OPEN,
        'M52 77 L68 77',          // neutre
      ];
      const svg = mouth.ownerSVGElement;
      let lastMouth = mouth.getAttribute('d');
      (function scheduleMouth() {
        setTimeout(() => {
          if (!asleep) {
            const choices = MOUTHS.filter(m => m !== lastMouth);
            lastMouth = choices[Math.floor(Math.random() * choices.length)];
            mouth.setAttribute('d', lastMouth);
            // Les joues reculent tant que la bouche reste ouverte, et
            // reviennent d'elles-mêmes dès qu'une autre forme est tirée —
            // la transition CSS (voir .kawaii-cheek) fait le mouvement.
            if (svg) svg.classList.toggle('kawaii-mouth-open', lastMouth === MOUTH_OPEN);
          }
          scheduleMouth();
        }, 2200 + Math.random() * 2800);
      })();
    }

    // Coup de queue de temps en temps — jamais en boucle continue, juste un
    // petit mouvement ponctuel comme un vrai chat qui s'ennuie un peu.
    if (tail) {
      (function scheduleTailWag() {
        setTimeout(() => {
          if (!asleep) {
            tail.classList.remove('wagging');
            void tail.offsetWidth; // relance l'animation même si la classe était déjà là juste avant
            tail.classList.add('wagging');
          }
          scheduleTailWag();
        }, 4000 + Math.random() * 6000);
      })();
    }

    // Sieste sur le dos : au-delà de 10s sans focus fenêtre NI souris dans le
    // navigateur, le chat se retourne et ferme les yeux (voir .asleep dans le
    // CSS) ; il se rassoit dès que l'un des deux revient — pas besoin des
    // deux à la fois, la perte du focus (autre appli au premier plan) et la
    // souris qui sort de la fenêtre (autre appli visible mais pas au premier
    // plan) sont deux façons différentes de constater la même chose.
    if (mascotEl) {
      let sleepTimer = null;
      function scheduleSleep() {
        clearTimeout(sleepTimer);
        sleepTimer = setTimeout(() => {
          asleep = true;
          mascotEl.classList.add('asleep');
        }, 10000);
      }
      function wakeUp() {
        clearTimeout(sleepTimer);
        sleepTimer = null;
        asleep = false;
        mascotEl.classList.remove('asleep');
      }
      window.addEventListener('blur', scheduleSleep);
      window.addEventListener('focus', wakeUp);
      document.addEventListener('mouseleave', scheduleSleep);
      document.addEventListener('mouseenter', wakeUp);
    }
  })();

  // Section du panneau à faire briller pour chaque type de notification —
  // un futur type ajoute juste une entrée ici. Rien de mappé pour un type
  // donné : la notification est acquittée sans effet visuel plutôt que de
  // rester en attente indéfiniment.
  const NOTIFICATION_FLASH_TARGETS = {
    skins: () => profileSkinGrid.closest('.profile-section'),
  };
  function flashSection(section) {
    if (!section) return;
    section.classList.remove('neon-flash');
    void section.offsetWidth; // relance l'animation si jamais déjà présente
    section.classList.add('neon-flash');
  }
  // Ne traite les notifications en attente qu'à la toute première ouverture
  // du panneau après le chargement — pas à chaque réouverture (elles ont déjà
  // été acquittées et retirées de pendingNotifications à ce moment-là).
  let notificationsProcessed = false;
  function openProfileDrawer() {
    userDropdown.classList.remove('visible');
    profileDrawerOverlay.classList.add('visible');
    if (!notificationsProcessed) {
      notificationsProcessed = true;
      // Copie : acknowledgeNotification() mute pendingNotifications au fil
      // de la boucle, jamais toutes en une seule fois côté serveur.
      pendingNotifications.slice().forEach(notification => {
        const getTarget = NOTIFICATION_FLASH_TARGETS[notification.type];
        if (getTarget) flashSection(getTarget());
        acknowledgeNotification(notification.id);
      });
    }
    if (pushSupported()) {
      profilePushToggle.disabled = false;
      navigator.serviceWorker.ready
        .then(reg => reg.pushManager.getSubscription())
        .then(sub => { profilePushToggle.checked = !!sub; })
        .catch(() => {});
    } else {
      profilePushToggle.checked = false;
      profilePushToggle.disabled = true;
    }
  }

  function closeProfileDrawer() {
    profileDrawerOverlay.classList.remove('visible');
    profileEmailInput.value = '';
  }

  document.getElementById('open-profile-btn').addEventListener('click', openProfileDrawer);
  profileDrawerClose.addEventListener('click', closeProfileDrawer);
  // Clic sur le fond assombri (pas sur le panneau lui-même) : referme aussi.
  profileDrawerOverlay.addEventListener('click', (e) => {
    if (e.target === profileDrawerOverlay) closeProfileDrawer();
  });

  profileAvatarGrid.querySelectorAll('.avatar-choice').forEach(choice => {
    choice.addEventListener('click', () => {
      selectedAvatar = choice.dataset.avatar;
      profileAvatarGrid.querySelectorAll('.avatar-choice').forEach(c => c.classList.remove('selected'));
      choice.classList.add('selected');
    });
  });

  profileSaveBtn.addEventListener('click', async () => {
    profileSaveBtn.disabled = true;
    profileSaveBtn.textContent = 'Enregistrement…';

    const originalAvatar = <?php echo json_encode($user['avatar']); ?>;
    const newEmail = profileEmailInput.value.trim();
    const messages = [];

    try {
      if (selectedAvatar !== originalAvatar) {
        const res = await fetch('set-avatar.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ avatar: selectedAvatar }),
        });
        if (!res.ok) throw new Error("Échec du changement d'avatar.");
        document.querySelector('.user-btn img').src = 'avatars/' + selectedAvatar;
      }

      if (newEmail !== '') {
        const res = await fetch('request-email-change.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email: newEmail }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.error || "Échec de la demande de changement d'email.");
        messages.push('Lien de confirmation envoyé à ' + (data.sent_to || 'ton adresse actuelle') + '.');
      }

      if (pushSupported() && !profilePushToggle.disabled) {
        const reg = await navigator.serviceWorker.ready;
        const existingSub = await reg.pushManager.getSubscription();
        const wantsPush = profilePushToggle.checked;
        if (wantsPush && !existingSub) {
          await enablePush();
        } else if (!wantsPush && existingSub) {
          await disablePush();
        }
      }

      closeProfileDrawer();
      showToast(['Profil enregistré.', ...messages].join(' '), 'success');
    } catch (err) {
      showToast(err.message || "Échec de l'enregistrement du profil.", 'error');
    }

    profileSaveBtn.disabled = false;
    profileSaveBtn.textContent = 'Enregistrer';
  });

  // Repère toute URL YouTube (watch, youtu.be, shorts, m.youtube.com) dans un
  // bloc de texte quelconque — utile quand on colle un paragraphe entier qui
  // contient un ou plusieurs liens noyés dans du texte.
  const YOUTUBE_URL_RE = /https?:\/\/(?:www\.|m\.)?(?:youtube\.com\/(?:watch\?v=|shorts\/)[\w-]+(?:[^\s]*)?|youtu\.be\/[\w-]+(?:[^\s]*)?)/gi;
  const YOUTUBE_SINGLE_RE = /^https?:\/\/(?:www\.|m\.)?(?:youtube\.com\/(?:watch\?v=|shorts\/)|youtu\.be\/)[\w-]+/i;

  function stripUrlParams(rawUrl) {
    const ampIndex = rawUrl.indexOf('&');
    return ampIndex !== -1 ? rawUrl.slice(0, ampIndex) : rawUrl;
  }

  function getAllRowUrls() {
    return Array.from(urlRows.querySelectorAll('.row-input'))
      .map(i => i.value.trim())
      .filter(Boolean);
  }

  // Persistance locale de la liste d'attente : une simple redirection PHP
  // (comme le partage natif mobile) ne suffit pas à elle seule à survivre à
  // un rechargement de page ou à une PWA relancée par Android après avoir été
  // mise en arrière-plan — localStorage, lui, survit à tout ça sur l'appareil.
  const STORAGE_KEY = 'ytdl_pending_urls';
  const DEVICE_ID_KEY = 'ytdl_device_id';

  // Identifiant d'appareil stable, généré une seule fois et gardé en
  // localStorage : comme il ne se synchronise pas entre appareils (contrairement
  // au compte), un PC et un téléphone du même compte ont chacun le leur — donc
  // chacun télécharge sa propre copie même si l'autre l'a déjà.
  let fallbackDeviceId = ''; // secours si localStorage échoue, voir ci-dessous

  function readDeviceIdCookie() {
    const match = document.cookie.match(/(?:^|; )ytdl_device_id=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
  }

  function persistDeviceIdCookie(id) {
    document.cookie = 'ytdl_device_id=' + encodeURIComponent(id) + '; Max-Age=31536000; Path=/; SameSite=Lax';
  }

  function getDeviceId() {
    try {
      let id = localStorage.getItem(DEVICE_ID_KEY) || readDeviceIdCookie();
      if (!id) id = 'dev-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
      localStorage.setItem(DEVICE_ID_KEY, id);
      persistDeviceIdCookie(id);
      return id;
    } catch (err) {
      // Pas de localStorage (mode privé strict…) : la déduplication "déjà
      // téléchargé sur cet appareil" ne survivra pas au rechargement de page,
      // mais un identifiant généré à la volée (au moins stable pendant cette
      // session) vaut mieux qu'une chaîne vide — le serveur n'a plus besoin
      // d'un device_id pour enregistrer la bibliothèque, mais autant en
      // fournir un quand c'est possible.
      if (!fallbackDeviceId) {
        fallbackDeviceId = 'dev-session-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
      }
      const cookieId = readDeviceIdCookie();
      if (cookieId) return cookieId;
      persistDeviceIdCookie(fallbackDeviceId);
      return fallbackDeviceId;
    }
  }

  function saveRowsToStorage() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(getAllRowUrls()));
    } catch (err) { /* stockage indisponible : tant pis, pas bloquant */ }
  }

  function loadRowsFromStorage() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      return raw ? JSON.parse(raw) : [];
    } catch (err) {
      return [];
    }
  }

  function clearRowsStorage() {
    try { localStorage.removeItem(STORAGE_KEY); } catch (err) {}
  }

  function isRowEmptyAndUnverified(row) {
    return !row.classList.contains('verified') && row.querySelector('.row-input').value.trim() === '';
  }

  // Crée une nouvelle rangée de saisie. Si `prefillUrl` est fourni, la
  // vérification démarre immédiatement (cas d'un collage multi-liens) ; la
  // promesse de cette vérification initiale est renvoyée pour permettre à
  // l'appelant d'attendre sa résolution (ex : bannière de partage PWA).
  function createRow(prefillUrl) {
    const row = document.createElement('div');
    row.className = 'url-row';
    row.innerHTML =
      '<div class="row-input-wrap">' +
        '<div class="input-shell">' +
          '<input type="url" class="row-input" placeholder="https://www.youtube.com/watch?v=...">' +
          '<button type="button" class="row-clear" title="Effacer">✕</button>' +
        '</div>' +
        '<div class="row-error"></div>' +
      '</div>' +
      '<div class="row-preview">' +
        '<img class="row-thumb" alt="">' +
        '<div class="row-info">' +
          '<div class="row-title"></div>' +
          '<div class="row-meta"></div>' +
        '</div>' +
        '<div class="row-spinner"></div>' +
        '<button type="button" class="row-remove" title="Retirer">✕</button>' +
      '</div>';
    urlRows.appendChild(row);

    const input = row.querySelector('.row-input');
    const inputShell = row.querySelector('.input-shell');
    const clearBtn = row.querySelector('.row-clear');
    const errorEl = row.querySelector('.row-error');
    let debounceTimer = null;
    let requestId = 0;

    clearBtn.addEventListener('click', () => {
      input.value = '';
      errorEl.textContent = '';
      inputShell.classList.remove('has-value');
      row.classList.remove('verified', 'checking');
      input.focus();
      saveRowsToStorage();
    });

    async function verify() {
      const url = stripUrlParams(input.value.trim());
      errorEl.textContent = '';
      if (!url) return;
      if (!YOUTUBE_SINGLE_RE.test(url)) {
        errorEl.textContent = "Ça ne ressemble pas à un lien YouTube.";
        return;
      }

      const myRequestId = ++requestId;
      row.classList.add('checking');
      try {
        const res = await fetch('title.php?url=' + encodeURIComponent(url));
        const data = await res.json();
        if (myRequestId !== requestId) return;
        if (!res.ok) {
          errorEl.textContent = data.error || 'Vidéo introuvable.';
          return;
        }

        input.value = url;
        row.querySelector('.row-thumb').src = data.thumbnail || '';
        row.querySelector('.row-title').textContent = data.title || url;
        row.querySelector('.row-meta').textContent = [data.uploader, data.duration].filter(Boolean).join(' · ');
        row.classList.add('verified');
        saveRowsToStorage();

        // Si c'est la dernière rangée, on en ajoute une nouvelle vide pour la suite.
        if (row === urlRows.lastElementChild) {
          const { input: nextInput } = createRow();
          nextInput.focus();
        }
      } catch (err) {
        if (myRequestId === requestId) errorEl.textContent = 'Erreur de vérification.';
      } finally {
        if (myRequestId === requestId) row.classList.remove('checking');
      }
    }

    input.addEventListener('input', () => {
      row.classList.remove('verified');
      inputShell.classList.toggle('has-value', input.value.trim() !== '');
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(verify, 500);
    });

    input.addEventListener('paste', (e) => {
      const pasted = (e.clipboardData || window.clipboardData)?.getData('text') || '';
      const found = pasted.match(YOUTUBE_URL_RE);
      if (found && found.length >= 1) {
        // Toujours extraire le(s) lien(s) plutôt que coller le texte brut tel
        // quel (utile si le lien est noyé dans une phrase, ex: "regarde ça : <url>").
        e.preventDefault();
        input.value = found[0];
        verify();
        for (let i = 1; i < found.length; i++) {
          createRow(found[i]);
        }
      }
      // Aucun lien détecté : laisser le collage natif tel quel.
    });

    row.querySelector('.row-remove').addEventListener('click', () => {
      row.remove();
      if (!urlRows.querySelector('.url-row')) {
        createRow();
      } else if (!isRowEmptyAndUnverified(urlRows.lastElementChild)) {
        createRow();
      }
      saveRowsToStorage();
    });

    let initialVerifyPromise = null;
    if (prefillUrl) {
      input.value = prefillUrl;
      initialVerifyPromise = verify();
    }

    return { row, input, verifyPromise: initialVerifyPromise };
  }

  // Restaure d'abord la liste sauvegardée localement (survit à un rechargement
  // ou à une PWA relancée par Android), puis ajoute les nouveaux liens reçus
  // par partage natif qui ne s'y trouvent pas déjà.
  const sharedUrls = <?php echo json_encode(array_values($sharedUrls)); ?>;
  const storedUrls = loadRowsFromStorage();
  const initialUrls = storedUrls.slice();
  sharedUrls.forEach(u => { if (!initialUrls.includes(u)) initialUrls.push(u); });

  const shareImportBanner = document.getElementById('share-import-banner');
  const sharedVerifyPromises = [];

  if (initialUrls.length > 0) {
    initialUrls.forEach(u => {
      const { verifyPromise } = createRow(u);
      if (sharedUrls.includes(u)) sharedVerifyPromises.push(verifyPromise);
    });
  } else {
    createRow();
  }

  // Retour visuel le temps que les liens reçus par partage natif (Android/PWA)
  // affichent tous leur titre : sans ça, la file d'attente semble incomplète
  // ou figée pendant les quelques centaines de ms de vérification réseau.
  if (sharedVerifyPromises.length > 0 && shareImportBanner) {
    shareImportBanner.classList.add('visible');
    Promise.allSettled(sharedVerifyPromises).finally(() => {
      shareImportBanner.classList.remove('visible');
    });
  }

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('service-worker.js').catch(() => {});
  }

  // --- Notifications push (partages reçus) ---
  // Web Push n'existe que dans un contexte sécurisé avec service worker, et
  // sur iOS uniquement depuis une PWA installée via Safari (voir capacités
  // testées : PushManager est absent d'un simple onglet Safari/Chrome iOS).
  // Le bouton d'activation vit dans le panneau "Mon profil" — voir plus bas.
  const VAPID_PUBLIC_KEY = <?php echo json_encode(YTDL_VAPID_PUBLIC_KEY); ?>;

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
  }

  function pushSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  }

  // Silencieuses (pas de toast ici) : appelées depuis l'enregistrement du
  // profil, qui affiche un seul message récapitulatif à la fin.
  async function enablePush() {
    if (Notification.permission === 'denied') {
      throw new Error("Notifications bloquées : autorise-les dans les réglages iOS pour cette app.");
    }
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') throw new Error('Permission refusée.');

    const reg = await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY),
    });
    await fetch('push-subscribe.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(sub.toJSON()),
    });
  }

  async function disablePush() {
    const reg = await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.getSubscription();
    if (!sub) return;
    const endpoint = sub.endpoint;
    await sub.unsubscribe();
    await fetch('push-unsubscribe.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ endpoint }),
    });
  }

  function showProgress(percent, label) {
    progressBlock.classList.add('visible');
    progressFill.style.width = Math.max(0, Math.min(100, percent)) + '%';
    progressFill.classList.toggle('done', percent >= 100);
    progressPercent.textContent = Math.round(percent) + ' %';
    progressLabel.textContent = label;
  }

  function hideProgress() {
    progressBlock.classList.remove('visible');
    progressFill.classList.remove('done');
    progressFill.style.width = '0%';
  }

  function showStatus(text, kind) {
    statusLine.textContent = text;
    statusLine.className = 'status-line visible' + (kind ? ' ' + kind : '');
  }

  function hideStatus() {
    statusLine.className = 'status-line';
    statusLine.textContent = '';
  }

  // Notification brève en haut de l'écran (confirmation de partage, erreurs
  // ponctuelles hors du flux de téléchargement) — distincte de la ligne de
  // statut ci-dessus, qui reste réservée au résumé (potentiellement long,
  // multi-lignes) d'un lot de téléchargements.
  const toastEl = document.getElementById('toast');
  const toastIcon = document.getElementById('toast-icon');
  const toastMessage = document.getElementById('toast-message');
  let toastTimer = null;

  const TOAST_ICON_SUCCESS = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
    '<circle cx="12" cy="12" r="9"></circle><polyline points="8 12.5 11 15.5 16 9"></polyline></svg>';
  const TOAST_ICON_ERROR = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
    '<circle cx="12" cy="12" r="9"></circle><line x1="12" y1="8" x2="12" y2="13"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
  const TOAST_ICON_INFO = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
    '<circle cx="12" cy="12" r="9"></circle><line x1="12" y1="11" x2="12" y2="16"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>';

  function showToast(text, kind) {
    toastMessage.textContent = text;
    toastIcon.innerHTML = kind === 'error' ? TOAST_ICON_ERROR : (kind === 'success' ? TOAST_ICON_SUCCESS : TOAST_ICON_INFO);
    toastEl.className = 'toast visible' + (kind ? ' ' + kind : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toastEl.classList.remove('visible'); }, kind === 'error' ? 5000 : 3500);
  }

  function showCurrentItem(title, thumbnail, meta) {
    previewThumb.src = thumbnail || '';
    previewThumb.style.visibility = thumbnail ? 'visible' : 'hidden';
    previewTitle.textContent = title || '';
    previewMeta.textContent = meta || '';
    previewCard.classList.add('visible');
  }

  function hideCurrentItem() {
    previewCard.classList.remove('visible');
  }

  function setRowsDisabled(disabled) {
    urlRows.querySelectorAll('.row-input, .row-remove').forEach(el => { el.disabled = disabled; });
  }

  function resetForm() {
    // Audio par défaut à chaque nouveau lot : l'utilisateur doit décocher
    // explicitement pour repasser en vidéo, plutôt que l'inverse.
    audioOnlyCheckbox.checked = true;
    // downloadToDeviceSwitch n'est volontairement pas réinitialisé : son
    // choix est mémorisé d'un lot à l'autre (et d'une session à l'autre, via
    // localStorage — voir sa déclaration plus haut).
    urlRows.innerHTML = '';
    createRow();
    clearRowsStorage();
    hideProgress();
    hideCurrentItem();
    btn.disabled = false;
  }

  // onUpdate optionnel : par défaut, rapporte à la feuille de téléchargement
  // classique (comportement d'origine) ; le téléchargement direct MP3/Clip
  // depuis un résultat YouTube fournit son propre rapporteur pour afficher
  // la progression sous le titre du résultat au lieu de la feuille.
  async function pollProgress(jobId, indexLabel, onUpdate) {
    indexLabel = indexLabel || '';
    const reporter = onUpdate || ((percent, label) => showProgress(percent, indexLabel + label));
    return new Promise((resolve, reject) => {
      let timer = setInterval(async () => {
        try {
          const res = await fetch('progress.php?job=' + encodeURIComponent(jobId));
          const data = await res.json();

          if (!res.ok || data.phase === 'error') {
            clearInterval(timer);
            reject(new Error(data.error || data.label || 'Le serveur a renvoyé une erreur.'));
            return;
          }

          reporter(data.percent, data.label);

          if (data.phase === 'done') {
            clearInterval(timer);
            resolve(data);
          }
        } catch (err) {
          clearInterval(timer);
          reject(err);
        }
      }, 600);
    });
  }

  // Déclenche le téléchargement natif du navigateur en naviguant directement
  // vers file.php : le fichier part alors sans jamais transiter par la mémoire
  // JavaScript (contrairement à fetch()+blob), ce qui est indispensable pour
  // les vidéos longues (plusieurs centaines de Mo, voire plusieurs Go).
  function triggerBrowserDownload(jobId) {
    const a = document.createElement('a');
    a.href = 'file.php?job=' + encodeURIComponent(jobId);
    document.body.appendChild(a);
    a.click();
    a.remove();
  }

  // "Uniquement sur le serveur" : n'appelle jamais file.php (donc rien ne
  // part vers le gestionnaire de téléchargement de l'appareil), juste
  // library-save.php qui archive le résultat du job dans la bibliothèque
  // (mini-lecteur en page) — utile pour éviter les écueils propres à certains
  // navigateurs mobiles (voir le bug device_id résolu plus haut).
  function getDownloadTarget() {
    return downloadToDeviceSwitch.checked ? 'device' : 'server';
  }

  async function saveToLibraryOnly(jobId) {
    try {
      const res = await fetch('library-save.php?job=' + encodeURIComponent(jobId));
      if (!res.ok) throw new Error();
    } catch (err) {
      // Pas bloquant pour le flux global : au pire ce fichier n'apparaît pas
      // en bibliothèque, sans faire échouer les autres téléchargements du lot.
    }
  }

  // Demande à l'utilisateur quoi faire d'un doublon détecté par le serveur.
  // Résout à true pour « télécharger quand même », false pour « passer ».
  const duplicateModal = document.getElementById('duplicate-modal');
  const duplicateModalText = document.getElementById('duplicate-modal-text');
  const duplicateSkipBtn = document.getElementById('duplicate-skip-btn');
  const duplicateForceBtn = document.getElementById('duplicate-force-btn');

  function askDuplicate(title, reason) {
    const messages = {
      library: 'est déjà dans ta bibliothèque, sur le serveur.',
      // Ni un doublon local ni "déjà sur cet appareil" : trouvé chez un
      // autre compte (voir start.php) — un accès a déjà été accordé
      // automatiquement, cette modale ne propose qu'un vrai second
      // téléchargement indépendant si l'utilisateur y tient malgré tout.
      elsewhere: 'est déjà disponible via un autre compte sur ce serveur (accès ajouté à ta bibliothèque). Le télécharger quand même, en exemplaire séparé ?',
    };
    duplicateModalText.innerHTML = '« <strong></strong> » ' + (messages[reason] || 'a déjà été téléchargé depuis cet appareil.');
    duplicateModalText.querySelector('strong').textContent = title; // jamais interprété comme du HTML
    duplicateModal.classList.add('visible');

    return new Promise(resolve => {
      const close = (force) => {
        duplicateModal.classList.remove('visible');
        duplicateSkipBtn.removeEventListener('click', onSkip);
        duplicateForceBtn.removeEventListener('click', onForce);
        resolve(force);
      };
      const onSkip = () => close(false);
      const onForce = () => close(true);
      duplicateSkipBtn.addEventListener('click', onSkip);
      duplicateForceBtn.addEventListener('click', onForce);
    });
  }

  // audioOnly est un paramètre explicite, jamais relu sur la case à cocher
  // globale au moment de l'appel : la recherche dans la bibliothèque et
  // l'import de playlist tournent chacun leur propre boucle de téléchargement
  // en tâche de fond, potentiellement en même temps que ce formulaire — si
  // chacun se contentait de positionner puis relire cette même case, l'un
  // pourrait écraser le réglage de l'autre pendant qu'il est encore lu par un
  // appel concurrent (voir startDirectDownload / importOneTrack, qui passent
  // désormais leur propre valeur au lieu de la poser dans le DOM partagé).
  function startDownload(url, audioOnly, force, category, titlePrefix) {
    return fetch(
      'start.php?url=' + encodeURIComponent(url) + '&audio_only=' + (audioOnly ? '1' : '0')
        + '&device_id=' + encodeURIComponent(getDeviceId())
        + (category ? '&category=' + encodeURIComponent(category) : '')
        + (titlePrefix ? '&title_prefix=' + encodeURIComponent(titlePrefix) : '')
        + (force ? '&force=1' : '')
    );
  }

  async function downloadOne(url, indexLabel, audioOnly, category) {
    showProgress(0, indexLabel + 'Récupération des informations…');

    let startRes = await startDownload(url, audioOnly, false, category);
    let startData = await startRes.json();
    if (!startRes.ok) {
      throw new Error(startData.error || 'Impossible de démarrer le téléchargement.');
    }

    if (startData.alreadyDownloaded) {
      // Deux motifs distincts : l'URL a déjà été téléchargée depuis cet
      // appareil, ou un fichier du même titre est déjà dans la bibliothèque
      // (même s'il vient d'une autre URL ou d'un autre appareil).
      showCurrentItem(startData.title, '', url);
      showProgress(100, indexLabel + (
        startData.reason === 'library' ? 'Déjà dans ta bibliothèque'
        : startData.reason === 'elsewhere' ? 'Trouvé chez un autre compte'
        : 'Déjà téléchargé sur cet appareil'));

      const force = await askDuplicate(startData.title, startData.reason);
      if (!force) {
        // "elsewhere" a déjà accordé un accès côté serveur (start.php) même
        // sans "forcer" un second téléchargement : la bibliothèque doit
        // refléter ce nouvel accès tout de suite.
        if (startData.reason === 'elsewhere') fetchLibrary();
        return { title: startData.title, skipped: true };
      }

      // Deuxième passage, garde-fous désactivés côté serveur.
      showProgress(0, indexLabel + 'Nouveau téléchargement…');
      startRes = await startDownload(url, audioOnly, true, category);
      startData = await startRes.json();
      if (!startRes.ok) {
        throw new Error(startData.error || 'Impossible de démarrer le téléchargement.');
      }
    }

    showCurrentItem(startData.title, '', url);

    await pollProgress(startData.jobId, indexLabel);
    showProgress(100, indexLabel + 'Terminé');

    if (getDownloadTarget() === 'server') {
      await saveToLibraryOnly(startData.jobId);
    } else {
      triggerBrowserDownload(startData.jobId);
    }

    return { title: startData.title, skipped: false };
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    // Masque le clavier du smartphone : le focus reste souvent sur un champ
    // URL après la frappe, ce qui cachait la barre de progression juste
    // après le clic sur « Télécharger ».
    if (document.activeElement && document.activeElement.blur) {
      document.activeElement.blur();
    }

    const urls = getAllRowUrls().map(stripUrlParams).filter((u, i, arr) => arr.indexOf(u) === i);
    if (urls.length === 0) return;

    btn.disabled = true;
    setRowsDisabled(true);
    hideStatus();

    const succeeded = [];
    const failed = [];
    // Capturées une seule fois avant la boucle : le lot entier utilise ces
    // réglages même s'ils changent d'état en cours de route (ou si un autre
    // flux de téléchargement en arrière-plan les modifie, voir startDownload).
    const audioOnly = audioOnlyCheckbox.checked;
    const category = otherContentCheckbox.checked ? 'other' : 'music';
    const ext = audioOnly ? '.mp3' : '.mp4';

    for (let i = 0; i < urls.length; i++) {
      const indexLabel = urls.length > 1 ? 'Vidéo ' + (i + 1) + '/' + urls.length + ' — ' : '';
      try {
        const title = await downloadOne(urls[i], indexLabel, audioOnly, category);
        succeeded.push(title);
      } catch (err) {
        failed.push({ url: urls[i], message: err.message || 'Erreur inconnue' });
      }
      await new Promise(r => setTimeout(r, 400));
    }

    // Une fois la première passe terminée, on reprend automatiquement les
    // échecs : pause de 5s (avec proverbe) avant chaque nouvelle tentative,
    // jusqu'à réussite ou 5 tours sans succès (pour ne pas boucler indéfiniment
    // sur une URL réellement invalide).
    const MAX_RETRY_ROUNDS = 5;
    let round = 0;
    let retryList = failed.splice(0, failed.length);

    while (retryList.length > 0 && round < MAX_RETRY_ROUNDS) {
      round++;
      const stillFailed = [];
      for (let i = 0; i < retryList.length; i++) {
        const item = retryList[i];
        const roundLabel = 'Nouvel essai ' + round + '/' + MAX_RETRY_ROUNDS + ' — ' + (i + 1) + '/' + retryList.length;
        await waitWithCountdown(5, roundLabel);
        try {
          const title = await downloadOne(item.url, retryList.length > 1 ? roundLabel + ' — ' : '');
          succeeded.push(title);
        } catch (err) {
          stillFailed.push({ url: item.url, message: err.message || 'Erreur inconnue' });
        }
      }
      retryList = stillFailed;
      hideProgress();
    }

    failed.push(...retryList);
    hideProgress();

    const downloaded = succeeded.filter(s => !s.skipped);
    const skipped = succeeded.filter(s => s.skipped);

    if (failed.length === 0) {
      // Tout a réussi : un simple popup rapide suffit, pas besoin de garder le
      // formulaire ouvert avec un résumé détaillé — on le referme directement.
      const count = succeeded.length;
      showToast(
        count > 1 ? count + ' fichiers téléchargés avec succès.' : '1 fichier téléchargé avec succès.',
        'success'
      );
      resetForm();
      downloadSheetOverlay.classList.remove('visible');
    } else if (succeeded.length === 0) {
      showStatus('Échec sur ' + failed.length + ' vidéo(s) (après ' + MAX_RETRY_ROUNDS + ' tentatives) :\n' + failed.map(f => '• ' + f.url + ' — ' + f.message).join('\n'), 'error');
      setTimeout(resetForm, 6000);
    } else {
      showStatus(
        succeeded.length + ' réussie(s), ' + failed.length + ' échouée(s) après ' + MAX_RETRY_ROUNDS + ' tentatives :\n' +
        failed.map(f => '• ' + f.url + ' — ' + f.message).join('\n'),
        'error'
      );
      setTimeout(resetForm, 6000);
    }

    if (downloaded.length > 0) {
      await fetchLibrary();
      librarySearchCtl.close();
      videoLibrarySearchCtl.close();
      playLastDownloadedTrack(downloaded[downloaded.length - 1].title, audioOnly, category);
    }
  });

  // Retrouve, dans la bibliothèque qui vient d'être rechargée, le fichier
  // correspondant au titre tout juste téléchargé, et le lance. En cas
  // d'homonyme déjà présent avant ce téléchargement, on prend le plus
  // récent (downloaded_at) plutôt qu'un ancien fichier du même nom.
  function playLastDownloadedTrack(title, audioOnly, category) {
    if (!title) return;
    // "Others" n'est ni dans rawAudioTracks ni dans rawVideoTracks (voir
    // fetchLibrary) — sans ce cas à part, un morceau "hors musique" tout
    // juste téléchargé ne se relançait jamais tout seul, faute de le
    // retrouver dans le mauvais tableau.
    const pool = category === 'other' ? rawOtherTracks : (audioOnly ? rawAudioTracks : rawVideoTracks);
    const matches = pool.filter(t => t.title === title);
    if (matches.length === 0) return;
    const track = matches.reduce((latest, t) =>
      new Date(t.downloaded_at) > new Date(latest.downloaded_at) ? t : latest
    );
    exitPlaylistContext();
    if (audioOnly) playTrack(track); else playVideo(track);
  }

  // --- Bibliothèque audio + mini-lecteur ---
  // Un seul <audio> partagé par toute la page, piloté par les contrôles du
  // mini-lecteur : pas besoin de recharger la page pour écouter un morceau
  // déjà téléchargé, ni pour enchaîner entre plusieurs.
  const libSection = document.getElementById('library-section');
  const libList = document.getElementById('library-list');
  const miniPlayer = document.getElementById('mini-player');
  // Espace réservé en bas de page pour le mini-lecteur (position:fixed) —
  // les valeurs fixes de body:has(.mini-player.visible){padding-bottom:…}
  // (voir music.css/le <style> plus haut, une par palier de largeur) sont
  // des ESTIMATIONS de sa hauteur réelle. Après la réorganisation du footer
  // mobile (vignette centrée, volume vertical…), sa hauteur RÉELLE ne
  // correspondait plus à ces estimations — trop de marge réservée en bas
  // faisait apparaître un grand bloc vide (couleur de fond de la page, donc
  // blanc sur les skins clairs) entre la fin de la liste et le mini-lecteur
  // une fois défilé jusqu'en bas ; trop peu, et le mini-lecteur recouvrait
  // le bas de la liste. Un ResizeObserver mesure sa VRAIE hauteur en
  // continu (tout changement de largeur d'écran, orientation, retour
  // desktop→mobile...) et pose un style inline, qui l'emporte toujours sur
  // ces estimations CSS — plus jamais besoin de deviner une valeur juste.
  if (window.ResizeObserver) {
    const syncMiniPlayerPadding = () => {
      const reserved = miniPlayer.classList.contains('visible')
        ? (miniPlayer.getBoundingClientRect().height + 20) + 'px'
        : '';
      document.body.style.paddingBottom = reserved;
      // Même mesure, réutilisée par les panneaux latéraux desktop (paroles
      // + fiche artiste, voir leur max-height) pour ne jamais laisser leur
      // contenu se faire cacher par le mini-lecteur fixé en bas de l'écran.
      document.documentElement.style.setProperty('--mini-player-reserved', reserved || '0px');
    };
    new ResizeObserver(syncMiniPlayerPadding).observe(miniPlayer);
    // Le ResizeObserver seul ne voit pas l'apparition de .visible (elle ne
    // change pas les dimensions de l'élément tant qu'il est display:none
    // juste avant) — un MutationObserver sur la classe couvre ce cas.
    new MutationObserver(syncMiniPlayerPadding).observe(miniPlayer, { attributes: true, attributeFilter: ['class'] });
  }
  const miniThumb = document.getElementById('mini-player-thumb');
  // Retrouver le morceau en cours dans la bibliothèque/playlist derrière le
  // mini-lecteur, notamment utile sur mobile où le footer masque le repère
  // visuel du morceau en train de jouer. Réutilise le même recentrage que
  // scheduleAutoFocusPlaying (déjà déclenché tout seul après un temps
  // d'inactivité, voir plus bas) — ici, forcé immédiat (autoFocusImmediate),
  // sans attendre ni exiger que la lecture aléatoire soit activée (voir
  // requestImmediateFocus, qui s'y limite lui) : un tap est déjà un geste
  // explicite, il n'y a pas de raison d'imposer une autre condition.
  // currentVideoId/currentTrackId sont mutuellement exclusifs (repasser à
  // l'audio remet l'autre à null, et inversement) — déterminer le "kind" se
  // résume donc à regarder lequel des deux est renseigné.
  // autoFocusIgnorePause : le morceau en pause reste "le morceau en cours"
  // pour l'utilisateur — un tap doit y ramener dans les deux cas, contraire
  // à l'auto-focus par inactivité (lui doit rester silencieux sur une
  // lecture arrêtée, sans quoi il "suivrait" un morceau qui ne bouge plus).
  miniThumb.addEventListener('click', () => {
    autoFocusImmediate = true;
    autoFocusIgnorePause = true;
    scheduleAutoFocusPlaying(currentVideoId !== null ? 'video' : 'audio');
  });
  const miniTitle = document.getElementById('mini-player-title');
  const miniTitleTrack = document.getElementById('mini-player-title-track');
  const miniTitleTexts = miniTitleTrack.querySelectorAll('.mini-player-title-text');

  // Ne fait défiler que si le titre déborde réellement du cadre disponible ;
  // vitesse constante (px/s) plutôt que durée fixe, pour que les titres longs
  // ne filent pas plus vite que les courts.
  function measureMiniPlayerTitleOverflow() {
    const singleWidth = miniTitleTexts[0].getBoundingClientRect().width;
    const containerWidth = miniTitle.getBoundingClientRect().width;
    if (singleWidth > containerWidth) {
      const PIXELS_PER_SECOND = 45;
      miniTitleTrack.style.animationDuration = (singleWidth / PIXELS_PER_SECOND) + 's';
      miniTitle.classList.add('scrolling');
    } else {
      miniTitle.classList.remove('scrolling');
      miniTitleTrack.style.animationDuration = '';
    }
  }
  function setMiniPlayerTitle(text) {
    miniTitleTexts.forEach(el => { el.textContent = text; });
    miniTitle.classList.remove('scrolling');
    miniTitleTrack.style.animationDuration = '';
    // Deux images plutôt qu'une seule : juste après avoir rendu le
    // mini-lecteur visible (display:none -> flex), un seul rAF tombe parfois
    // avant que la mise en page ait fini de se stabiliser, ce qui fausse la
    // mesure et laisse un titre pourtant trop long ne jamais défiler.
    requestAnimationFrame(() => requestAnimationFrame(measureMiniPlayerTitleOverflow));
  }
  // Les polices web (Space Grotesk) peuvent finir de charger après cette
  // première mesure, avec des métriques différentes de la police de repli
  // utilisée entre-temps — un titre limite pouvait alors être mesuré comme
  // tenant dans le cadre, puis déborder silencieusement une fois la vraie
  // police appliquée. On remesure une fois les polices prêtes.
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(() => {
      requestAnimationFrame(() => requestAnimationFrame(measureMiniPlayerTitleOverflow));
    });
  }
  const miniPlayBtn = document.getElementById('mini-player-play');
  const miniLyricsBtn = document.getElementById('mini-player-lyrics-btn');
  const miniSeek = document.getElementById('mini-player-seek');
  const miniTime = document.getElementById('mini-player-time');
  const miniTimeMobile = document.getElementById('mini-player-time-mobile');
  const miniRatingEl = document.getElementById('mini-player-rating');
  const miniStars = Array.from(miniRatingEl.querySelectorAll('.mini-star'));
  let currentTrackRating = 0;

  function renderMiniStars(rating) {
    miniStars.forEach(s => s.classList.toggle('filled', Number(s.dataset.value) <= rating));
  }

  miniStars.forEach(star => {
    star.addEventListener('mouseenter', () => renderMiniStars(Number(star.dataset.value)));
  });
  miniRatingEl.addEventListener('mouseleave', () => renderMiniStars(currentTrackRating));

  miniRatingEl.addEventListener('click', async (e) => {
    const star = e.target.closest('.mini-star');
    if (!star || !currentTrackId) return;
    const clicked = Number(star.dataset.value);
    // Recliquer sur l'étoile qui correspond déjà à la note actuelle la
    // retire (retour à "non noté"), plutôt que de rester bloqué dessus.
    const newRating = clicked === currentTrackRating ? 0 : clicked;
    currentTrackRating = newRating;
    renderMiniStars(newRating);

    // Répercuté immédiatement sur l'objet en mémoire : la pondération de la
    // lecture aléatoire doit en tenir compte sans attendre un refetch.
    const track = currentAudioTracks.find(t => t.id === currentTrackId);
    if (track) track.rating = newRating;

    try {
      await fetch('library-rate.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: currentTrackId, rating: newRating }),
      });
    } catch (err) { /* pas bloquant : la note reste appliquée localement */ }
  });
  const miniPrevBtn = document.getElementById('mini-player-prev');
  const miniNextBtn = document.getElementById('mini-player-next');
  const miniClose = document.getElementById('mini-player-close');
  const miniShuffleBtn = document.getElementById('mini-player-shuffle');
  const miniAutoNextBtn = document.getElementById('mini-player-autonext');
  const miniCrossfadeBtn = document.getElementById('mini-player-crossfade');
  const miniShowVolumeBtn = document.getElementById('mini-player-show-volume');
  const miniSettingsBtn = document.getElementById('mini-player-settings-btn');
  const miniSettingsSheet = document.getElementById('mini-player-settings-sheet');
  const miniSettingsCloseBtn = document.getElementById('mini-player-settings-close');
  // Roue crantée du lecteur (simplification extrême du footer) : aléatoire/
  // enchaînement/fondu ne sont plus visibles en permanence, seulement dans
  // cette feuille classique — même comportement ouverture/fermeture que les
  // autres .sheet-overlay du fichier (lyrics-sheet, playlist-sheet...).
  miniSettingsBtn.addEventListener('click', () => miniSettingsSheet.classList.add('visible'));
  miniSettingsCloseBtn.addEventListener('click', () => miniSettingsSheet.classList.remove('visible'));
  miniSettingsSheet.addEventListener('click', e => { if (e.target === miniSettingsSheet) miniSettingsSheet.classList.remove('visible'); });
  const miniVolume = document.getElementById('mini-player-volume');
  const miniVolumeIcon = document.getElementById('mini-player-volume-icon');
  const lyricsVolume = document.getElementById('lyrics-volume');
  const lyricsVolumeIcon = document.getElementById('lyrics-volume-icon');

  // Deux <audio> alternés (pas un seul) : un vrai fondu enchaîné a besoin de
  // faire se chevaucher l'ancien morceau qui s'éteint et le nouveau qui monte
  // en volume, ce qu'un seul élément ne permet pas. `audioPlayer` pointe
  // toujours vers l'élément actif du moment — tout le reste du code peut
  // continuer à l'utiliser tel quel, y compris pendant un fondu.
  const audioA = new Audio();
  const audioB = new Audio();
  let audioPlayer = audioA;

  // --- Mise à niveau du son ---
  // Les sources n'ont aucun niveau commun : mesuré sur la bibliothèque, un
  // clip YouTube sort en moyenne 6 dB sous un MP3 extrait, et les clips
  // s'étalent sur près de 20 dB entre eux. Le serveur fournit donc avec
  // chaque morceau un gain de correction en dB (voir
  // ytdl_normalization_gain_db), appliqué ici à la lecture — le fichier
  // lui-même n'est jamais retouché, la mesure peut être refaite et la cible
  // changée sans rien réencoder.
  //
  // Web Audio plutôt que element.volume : remonter un clip faible demande un
  // gain SUPÉRIEUR à 1, que .volume (plafonné à 1) ne sait pas produire.
  //
  // Deux GainNode en série par élément : la correction de niveau, puis le
  // volume choisi par l'utilisateur (curseur, fondu enchaîné). Le volume ne
  // passe PLUS par element.volume une fois l'élément raccordé au graphe :
  // Safari (iOS, et macOS dans ce cas de figure) ignore .volume sur un
  // élément qui passe par createMediaElementSource — le curseur ne faisait
  // plus rien. Un gain dédié marche partout. Le lecteur vidéo, lui, garde
  // ses contrôles natifs, donc son .volume (voir setElementVolume).
  let audioContext = null;
  const gainNodes = new Map();

  function ensureGainNode(el) {
    if (gainNodes.has(el)) return gainNodes.get(el);
    let nodes = null;
    try {
      const Ctx = window.AudioContext || window.webkitAudioContext;
      if (Ctx) {
        if (!audioContext) {
          audioContext = new Ctx();
          // iOS ET Chrome Android suspendent l'AudioContext dès l'extinction de
          // l'écran — même quand l'<audio> continue lui d'avancer (currentTime
          // qui progresse) : le son sort du haut-parleur uniquement si le
          // contexte est "running". Une seule relance ponctuelle (au moment
          // précis de la suspension, ou seulement au réveil) s'est révélée peu
          // fiable sur Chrome — le contexte peut rester coincé sans jamais
          // vraiment redonner le son. On retente donc en boucle toutes les
          // secondes, écran éteint ou pas : chaque tentative ratée est
          // silencieuse (catch), et dès que le système rend la main, la
          // lecture reprend sans action de l'utilisateur.
          //
          // Verrouillage de l'écran d'un ordinateur (macOS notamment) : ni
          // 'visibilitychange' ni 'focus' ne se déclenchent forcément à ce
          // moment-là (le point de vue de la page ne change pas, seul l'OS
          // affiche l'écran de verrouillage par-dessus).
          //
          // On ne relance QUE le contexte ici, jamais l'élément <audio> lui-
          // même (pas de el.play()) : playbackIntent reste vrai même pendant
          // une pause déclenchée écran éteint (bouton de l'écran verrouillé,
          // casque Bluetooth, notification média...), puisque le code qui
          // détecte une "vraie" pause utilisateur s'appuie sur pageIsSuspending
          // pour l'ignorer À TORT dans ce cas précis (voir bindAudioEvents).
          // Un el.play() ici relançait donc la lecture une seconde après
          // chaque mise en pause faite écran éteint — bug reporté sur
          // Android, et par construction tout aussi présent sur iOS
          // (Chrome/Safari), le mécanisme étant identique. Remettre du son
          // qui ne sortait plus (contexte suspendu) n'exige que resume() :
          // l'élément, lui, continue de tourner tout seul en arrière-plan.
          setInterval(() => {
            if (playbackIntent && audioContext.state !== 'running') {
              audioContext.resume().catch(() => {});
            }
          }, 1000);
        }
        // createMediaElementSource n'est acceptée qu'UNE fois par élément :
        // un second appel lèverait, d'où la mémorisation par élément.
        const source = audioContext.createMediaElementSource(el);
        const normalization = audioContext.createGain();
        const volume = audioContext.createGain();
        source.connect(normalization).connect(volume).connect(audioContext.destination);
        nodes = { normalization, volume };
        // Reprise sans à-coup : le niveau que portait .volume passe dans le
        // gain, et .volume repasse à 1 pour ne pas atténuer deux fois là où
        // le navigateur l'honore encore (Chrome, Firefox).
        if (el === audioA || el === audioB) {
          volume.gain.value = el.volume;
          el.volume = 1;
        }
      }
    } catch (err) {
      nodes = null; // Web Audio refusé : on n'y reviendra pas (voir ci-dessous)
    }
    // L'échec est mémorisé au même titre qu'un succès : inutile de retenter à
    // chaque morceau, et la lecture continue sans correction — un son non
    // corrigé vaut mieux qu'un silence.
    gainNodes.set(el, nodes);
    return nodes;
  }

  function applyNormalizationGain(el, gainDb) {
    const nodes = ensureGainNode(el);
    if (!nodes) return;
    // Le contexte naît suspendu tant qu'aucun geste utilisateur n'a eu lieu ;
    // sans ce resume, faire passer l'élément par le graphe le rendrait muet.
    if (audioContext.state === 'suspended') audioContext.resume().catch(() => {});
    const db = Number(gainDb);
    nodes.normalization.gain.value = Number.isFinite(db) ? Math.pow(10, db / 20) : 1;
  }

  // Volume (0..1) des deux <audio> du lecteur : par le gain quand l'élément
  // est raccordé à Web Audio, par .volume tant qu'il ne l'est pas encore.
  // Tout réglage de volume ou de fondu sur audioA/audioB passe par ici.
  function setElementVolume(el, value) {
    const nodes = gainNodes.get(el);
    if (nodes) nodes.volume.gain.value = value;
    else el.volume = value;
  }
  function getElementVolume(el) {
    const nodes = gainNodes.get(el);
    return nodes ? nodes.volume.gain.value : el.volume;
  }
  let currentTrackId = null;
  // Safari peut suspendre un <audio> avant pagehide lors d'un rechargement.
  // L'intention de l'utilisateur doit rester distincte de cet état technique.
  let playbackIntent = false;
  let pageIsSuspending = false;
  let currentAudioTracks = []; // dernière liste connue, pour piocher le morceau suivant
  let resumeCheckedOnce = false; // la bannière de reprise ne se propose qu'au tout premier chargement
  let rawAudioTracks = []; // même liste, avant tri, pour retrier sans refetch
  let rawVideoTracks = []; // idem pour les clips
  let rawOtherTracks = []; // "Others" (hors musique) — mélange audio+vidéo, pas de tri par artiste dédié
  let audioSortDirection = null; // null (ordre naturel) | 'asc' | 'desc' — par artiste
  let videoSortDirection = null;
  let seeking = false;

  // Préférences de lecture persistées : pas de raison de les redemander à
  // chaque rechargement de page.
  function loadBoolPref(key, fallback) {
    try {
      const raw = localStorage.getItem(key);
      return raw === null ? fallback : raw === '1';
    } catch (err) { return fallback; }
  }
  function saveBoolPref(key, value) {
    try { localStorage.setItem(key, value ? '1' : '0'); } catch (err) {}
  }
  let shuffleEnabled = loadBoolPref('ytdl_shuffle', false);
  let autoNextEnabled = loadBoolPref('ytdl_autonext', false);
  let crossfadeEnabled = loadBoolPref('ytdl_crossfade', true);
  // Masqué par défaut sur mobile (le bouton matériel du téléphone suffit) —
  // voir .mini-player-row-top .mini-player-volume-wrap dans music.css.
  let showMobileVolume = loadBoolPref('ytdl_mobile_volume', false);
  // Par défaut activé : au lancement d'une playlist, sa vue remplace les
  // onglets Audio/Clips (voir startPlaylist/switchTab) plutôt que de laisser
  // toute la bibliothèque affichée derrière — coupable depuis la bascule de
  // la modale "Mes playlists" (#playlist-focus-toggle).
  let playlistFocusEnabled = loadBoolPref('ytdl_playlist_focus', true);
  miniShuffleBtn.classList.toggle('toggle-active', shuffleEnabled);
  miniAutoNextBtn.classList.toggle('toggle-active', autoNextEnabled);
  miniCrossfadeBtn.classList.toggle('toggle-active', crossfadeEnabled);
  miniShowVolumeBtn.classList.toggle('toggle-active', showMobileVolume);
  document.body.classList.toggle('show-mobile-volume', showMobileVolume);

  // Volume : persisté comme les deux préférences ci-dessus, pas de raison de
  // redemander le niveau souhaité à chaque rechargement de page.
  const VOLUME_ICON_HIGH = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
    '<polygon points="3 9 8 9 13 4 13 20 8 15 3 15 3 9"></polygon><path d="M16 9a4 4 0 0 1 0 6"></path><path d="M18.5 6.5a8 8 0 0 1 0 11"></path></svg>';
  const VOLUME_ICON_LOW = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
    '<polygon points="3 9 8 9 13 4 13 20 8 15 3 15 3 9"></polygon><path d="M16 9a4 4 0 0 1 0 6"></path></svg>';
  const VOLUME_ICON_MUTE = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
    '<polygon points="3 9 8 9 13 4 13 20 8 15 3 15 3 9"></polygon><line x1="17" y1="9" x2="22" y2="14"></line><line x1="22" y1="9" x2="17" y2="14"></line></svg>';

  function loadVolumePref() {
    try {
      const raw = localStorage.getItem('ytdl_volume');
      const parsed = raw === null ? 100 : parseInt(raw, 10);
      return isNaN(parsed) ? 100 : Math.min(100, Math.max(0, parsed));
    } catch (err) { return 100; }
  }
  function updateVolumeIcon(vol) {
    const icon = vol === 0 ? VOLUME_ICON_MUTE : (vol < 50 ? VOLUME_ICON_LOW : VOLUME_ICON_HIGH);
    miniVolumeIcon.innerHTML = icon;
    lyricsVolumeIcon.innerHTML = icon;
  }
  let currentVolume = loadVolumePref();
  audioA.volume = currentVolume / 100;
  audioB.volume = currentVolume / 100;
  miniVolume.value = currentVolume;
  lyricsVolume.value = currentVolume;
  updateVolumeIcon(currentVolume);

  // Deux curseurs (mini-lecteur + feuille de paroles) pour le même réglage :
  // centralisé ici pour qu'ajuster l'un mette l'autre à jour instantanément,
  // sans dupliquer la logique d'application/persistance.
  function setVolume(vol) {
    currentVolume = vol;
    // N'affecte que l'actif : si un fondu est en cours sur l'inactif, le
    // laisser terminer sa propre rampe plutôt que de lui imposer ce niveau.
    setElementVolume(audioPlayer, currentVolume / 100);
    // Un seul réglage pour tout : le clip suit le même niveau que les mp3
    // (voir aussi playVideo et l'écouteur volumechange du lecteur vidéo).
    // Passe aussi par setElementVolume, pas juste .volume : une fois le
    // lecteur vidéo raccordé au graphe Web Audio (voir applyNormalizationGain
    // dans playVideo, pour la correction de niveau), son .volume natif n'a
    // plus d'effet audible sur certains navigateurs (Safari notamment) — le
    // vrai réglage doit passer par le même GainNode que l'audio. On garde
    // .volume synchronisé en plus, pour que le curseur natif du lecteur
    // affiche la bonne position tant qu'il reste visible.
    setElementVolume(videoPlayer, currentVolume / 100);
    videoPlayer.volume = currentVolume / 100;
    miniVolume.value = currentVolume;
    lyricsVolume.value = currentVolume;
    updateVolumeIcon(currentVolume);
    try { localStorage.setItem('ytdl_volume', String(currentVolume)); } catch (err) {}
  }

  miniVolume.addEventListener('input', () => setVolume(parseInt(miniVolume.value, 10)));
  lyricsVolume.addEventListener('input', () => setVolume(parseInt(lyricsVolume.value, 10)));

  function formatTime(sec) {
    if (!isFinite(sec) || sec < 0) sec = 0;
    const m = Math.floor(sec / 60);
    const s = Math.floor(sec % 60).toString().padStart(2, '0');
    return m + ':' + s;
  }


  // --- Suppression + espace de stockage ---
  // Icône poubelle en SVG (trait, currentColor) : même raison que pour
  // 🔀/🔁 plus haut, un emoji couleur ignorerait le survol rouge en CSS.
  const TRASH_ICON_SVG =
    '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
      '<polyline points="3 6 5 6 21 6"></polyline>' +
      '<path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>' +
      '<path d="M10 11v6"></path><path d="M14 11v6"></path>' +
      '<path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path>' +
    '</svg>';

  const storageSection = document.getElementById('storage-section');
  const storageDonut = document.getElementById('storage-donut');
  // Doublon compact pour mobile (voir #mobile-storage-section) : juste le
  // donut, pas de légende (déjà masquée côté sidebar, ici jamais présente
  // dans le gabarit) — le détail par catégorie reste dans la modale.
  const mobileStorageSection = document.getElementById('mobile-storage-section');
  const mobileStorageDonut = document.getElementById('mobile-storage-donut');
  const storageAudioSize = document.getElementById('storage-audio-size');
  const storageVideoSize = document.getElementById('storage-video-size');
  const storageOtherSize = document.getElementById('storage-other-size');
  const storageFreeSize = document.getElementById('storage-free-size');

  const storageModal = document.getElementById('storage-modal');
  const storageModalDonut = document.getElementById('storage-modal-donut');
  const storageModalAudioSize = document.getElementById('storage-modal-audio-size');
  const storageModalVideoSize = document.getElementById('storage-modal-video-size');
  const storageModalOtherSize = document.getElementById('storage-modal-other-size');
  const storageModalFreeSize = document.getElementById('storage-modal-free-size');
  const storageModalAudioDetail = document.getElementById('storage-modal-audio-detail');
  const storageModalVideoDetail = document.getElementById('storage-modal-video-detail');
  const storageModalOtherDetail = document.getElementById('storage-modal-other-detail');
  const storageModalTotal = document.getElementById('storage-modal-total');
  const storageModalClose = document.getElementById('storage-modal-close');
  let lastStorageUsage = null;

  const deleteModal = document.getElementById('delete-confirm-modal');
  const deleteConfirmTitle = document.getElementById('delete-confirm-title');
  const deleteConfirmWarning = document.getElementById('delete-confirm-warning');
  const deleteConfirmBtn = document.getElementById('delete-confirm-btn');
  const deleteCancelBtn = document.getElementById('delete-cancel-btn');
  let pendingDeleteId = null;
  // Morceaux retirés optimistement dont la suppression n'est pas encore
  // confirmée par le serveur : le sondage périodique (fetchLibrary, toutes
  // les 25 s) — ou tout autre resynchronisation lancée pendant ce court
  // laps de temps — ne doit jamais les remettre dans la liste (donc dans la
  // recherche) avant que le serveur ait vraiment terminé.
  const pendingDeletedIds = new Set();

  function formatBytes(bytes) {
    if (bytes >= 1024 * 1024 * 1024) return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' Go';
    return Math.round(bytes / (1024 * 1024)) + ' Mo';
  }

  function storageDonutGradient(usage, quota) {
    const audioPct = Math.min(100, usage.audio_bytes / quota * 100);
    const videoPct = Math.min(100, (usage.audio_bytes + usage.video_bytes) / quota * 100);
    const usedPct = Math.min(100, (usage.audio_bytes + usage.video_bytes + (usage.other_bytes || 0)) / quota * 100);
    return 'conic-gradient(var(--accent) 0% ' + audioPct + '%, var(--accent-2) ' + audioPct + '% ' + videoPct + '%, var(--success) ' + videoPct + '% ' + usedPct + '%, var(--border) ' + usedPct + '% 100%)';
  }

  function renderStorageUsage(usage) {
    if (!usage) return;
    lastStorageUsage = usage;
    const quota = usage.quota_bytes || (5 * 1024 * 1024 * 1024);
    storageDonut.style.background = storageDonutGradient(usage, quota);
    mobileStorageDonut.style.background = storageDonut.style.background;
    storageAudioSize.textContent = formatBytes(usage.audio_bytes);
    storageVideoSize.textContent = formatBytes(usage.video_bytes);
    storageOtherSize.textContent = formatBytes(usage.other_bytes || 0);
    storageFreeSize.textContent = formatBytes(Math.max(0, quota - usage.total_bytes));
    storageSection.classList.toggle('visible', usage.total_bytes > 0);
    mobileStorageSection.classList.toggle('visible', usage.total_bytes > 0);
  }

  // Nombre de fichiers + taille moyenne + part du quota, pour une catégorie
  // (MP3 ou Clips) — le detail affiché sous chaque ligne de la modale.
  function storageCategoryDetail(bytes, count, quota) {
    if (count === 0) return 'Aucun fichier';
    const avg = bytes / count;
    const pct = quota > 0 ? (bytes / quota * 100) : 0;
    const noun = count > 1 ? 'fichiers' : 'fichier';
    return count + ' ' + noun + ' · ' + formatBytes(avg) + ' en moyenne · ' + pct.toFixed(1) + ' % du quota';
  }

  // Détail complet dans une modale : le bandeau de l'en-tête reste compact
  // (juste le donut sur mobile, légende masquée faute de place).
  function openStorageModal() {
    if (!lastStorageUsage) return;
    const usage = lastStorageUsage;
    const quota = usage.quota_bytes || (5 * 1024 * 1024 * 1024);
    const audioCount = usage.audio_count || 0;
    const videoCount = usage.video_count || 0;
    const otherCount = usage.other_count || 0;
    storageModalDonut.style.background = storageDonutGradient(usage, quota);
    storageModalAudioSize.textContent = formatBytes(usage.audio_bytes);
    storageModalVideoSize.textContent = formatBytes(usage.video_bytes);
    storageModalOtherSize.textContent = formatBytes(usage.other_bytes || 0);
    storageModalFreeSize.textContent = formatBytes(Math.max(0, quota - usage.total_bytes));
    storageModalAudioDetail.textContent = storageCategoryDetail(usage.audio_bytes, audioCount, quota);
    storageModalVideoDetail.textContent = storageCategoryDetail(usage.video_bytes, videoCount, quota);
    storageModalOtherDetail.textContent = storageCategoryDetail(usage.other_bytes || 0, otherCount, quota);
    storageModalTotal.textContent = formatBytes(usage.total_bytes) + ' utilisés sur ' + formatBytes(quota);
    storageModal.classList.add('visible');
  }
  storageSection.addEventListener('click', openStorageModal);
  storageSection.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openStorageModal(); }
  });
  mobileStorageSection.addEventListener('click', openStorageModal);
  mobileStorageSection.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openStorageModal(); }
  });
  storageModalClose.addEventListener('click', () => storageModal.classList.remove('visible'));

  function openDeleteConfirm(track) {
    pendingDeleteId = track.id;
    deleteConfirmTitle.textContent = track.title || 'Sans titre';
    const sharedCount = track.shared_count || 0;
    if (sharedCount > 0) {
      deleteConfirmWarning.textContent = sharedCount === 1
        ? "⚠ Ce fichier est partagé avec 1 personne, qui en perdra l'accès si tu le supprimes."
        : "⚠ Ce fichier est partagé avec " + sharedCount + " personnes, qui en perdront l'accès si tu le supprimes.";
      deleteConfirmWarning.style.display = 'block';
    } else {
      deleteConfirmWarning.style.display = 'none';
    }
    deleteConfirmBtn.disabled = false;
    deleteConfirmBtn.textContent = 'Supprimer';
    deleteModal.classList.add('visible');
  }

  // Lien vers la vidéo YouTube d'origine, gardée en base : un moyen simple
  // de retélécharger au cas où (erreur de manipulation, fichier expiré).
  // Logo YouTube plutôt qu'une icône générique : reconnaissable instantanément,
  // en couleur de marque fixe (pas currentColor, contrairement aux autres
  // icônes du fichier — un logo n'a pas vocation à changer de teinte au survol).
  const SOURCE_LINK_ICON_SVG =
    '<svg viewBox="0 0 28 20" width="17" height="12">' +
      '<rect x="0" y="0" width="28" height="20" rx="5" fill="#FF0000"></rect>' +
      '<path d="M11 5.5 L20 10 L11 14.5 Z" fill="#fff"></path>' +
    '</svg>';

  // Bulle avec deux lignes de texte : afficher les paroles du morceau,
  // quand l'API externe en a trouvé (voir queueLyricsCheck plus bas — le
  // bouton reste masqué tant que la disponibilité n'est pas confirmée).
  const LYRICS_ICON_SVG =
    '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
      '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>' +
      '<line x1="7" y1="8" x2="17" y2="8"></line>' +
      '<line x1="7" y1="12" x2="13" y2="12"></line>' +
    '</svg>';

  // Renommage manuel du titre (admin) — crayon classique, même famille que
  // les autres icônes de ligne (trait seul, currentColor).
  const RENAME_ICON_SVG =
    '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
      '<path d="M12 20h9"></path>' +
      '<path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"></path>' +
    '</svg>';

  // Liste avec un « + » : ajouter ce morceau à une playlist (à ne pas
  // confondre avec ⬇ qui, lui, télécharge le fichier sur l'appareil).
  const PLAYLIST_ADD_ICON_SVG =
    '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
      '<line x1="3" y1="6" x2="15" y2="6"></line><line x1="3" y1="12" x2="15" y2="12"></line><line x1="3" y1="18" x2="11" y2="18"></line>' +
      '<line x1="18" y1="14" x2="18" y2="22"></line><line x1="14" y1="18" x2="22" y2="18"></line>' +
    '</svg>';

  // --- Partage ---
  const SHARE_ICON_SVG =
    '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
      '<circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle>' +
      '<line x1="8.6" y1="10.5" x2="15.4" y2="6.5"></line><line x1="8.6" y1="13.5" x2="15.4" y2="17.5"></line>' +
    '</svg>';

  // Distinguent un morceau audio d'un clip vidéo quand une recherche mélange
  // les deux types (voir applyCrossTypeSearch) — mêmes tracés que les
  // pastilles d'onglets Audio/Clips (.library-tab-icon svg), en plus petit.
  const AUDIO_TYPE_ICON_SVG =
    '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' +
      '<path d="M9 18V5l12-2v13"></path><circle cx="6" cy="18" r="3"></circle><circle cx="18" cy="16" r="3"></circle>' +
    '</svg>';
  const CLIP_TYPE_ICON_SVG =
    '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' +
      '<rect x="2" y="4" width="20" height="16" rx="2"></rect><rect x="6" y="8" width="12" height="8" rx="1"></rect>' +
      '<path d="M2 8h4M18 8h4M2 16h4M18 16h4"></path>' +
    '</svg>';


  const shareModal = document.getElementById('share-modal');
  const shareModalTitle = document.getElementById('share-modal-title');
  const shareUserList = document.getElementById('share-user-list');
  const shareSaveBtn = document.getElementById('share-save-btn');
  const shareCancelBtn = document.getElementById('share-cancel-btn');
  let pendingShareId = null;

  async function openShareModal(track) {
    pendingShareId = track.id;
    shareModalTitle.textContent = track.title || 'Sans titre';
    shareUserList.innerHTML = '<div class="share-empty">Chargement…</div>';
    shareSaveBtn.disabled = false;
    shareSaveBtn.textContent = 'Partager';
    shareModal.classList.add('visible');

    try {
      // history_id exclut aussi le propriétaire d'origine côté serveur :
      // jamais de repartage vers quelqu'un qui a déjà le fichier chez lui.
      const res = await fetch('users-list.php?history_id=' + encodeURIComponent(track.id));
      const data = await res.json();
      const users = data.users || [];
      if (users.length === 0) {
        shareUserList.innerHTML = '<div class="share-empty">Aucun autre compte à qui partager.</div>';
        return;
      }
      const sharedWith = new Set(track.shared_with || []);
      shareUserList.innerHTML = '';
      users.forEach(u => {
        const label = document.createElement('label');
        label.className = 'share-user-choice';
        label.innerHTML =
          '<input type="checkbox" value="' + u.id + '"' + (sharedWith.has(u.id) ? ' checked' : '') + '>' +
          '<img src="avatars/' + u.avatar + '" alt="">' +
          '<span></span>';
        label.querySelector('span').textContent = u.name;
        shareUserList.appendChild(label);
      });
    } catch (err) {
      shareUserList.innerHTML = '<div class="share-empty">Impossible de charger la liste des comptes.</div>';
    }
  }

  shareCancelBtn.addEventListener('click', () => {
    shareModal.classList.remove('visible');
    pendingShareId = null;
  });

  shareSaveBtn.addEventListener('click', async () => {
    if (pendingShareId === null) return;
    const checkedBoxes = Array.from(shareUserList.querySelectorAll('input[type="checkbox"]:checked'));
    const checkedIds = checkedBoxes.map(cb => parseInt(cb.value, 10));
    const checkedNames = checkedBoxes.map(cb => cb.closest('.share-user-choice').querySelector('span').textContent);
    shareSaveBtn.disabled = true;
    shareSaveBtn.textContent = 'Partage…';
    try {
      const res = await fetch('library-share.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: pendingShareId, user_ids: checkedIds })
      });
      if (!res.ok) throw new Error();
      shareModal.classList.remove('visible');
      pendingShareId = null;
      fetchLibrary();

      // Confirmation nommée : les 3 premiers destinataires, puis "+N" pour
      // le reste — évite un toast interminable si le compte se partage à
      // toute la famille d'un coup.
      if (checkedNames.length === 0) {
        showToast('Partage retiré.', 'info');
      } else {
        const shown = checkedNames.slice(0, 3).join(', ');
        const rest = checkedNames.length > 3 ? ' +' + (checkedNames.length - 3) : '';
        showToast('Partagé avec ' + shown + rest + '.', 'success');
      }
    } catch (err) {
      shareSaveBtn.disabled = false;
      shareSaveBtn.textContent = 'Partager';
      showToast('Impossible de mettre à jour le partage.', 'error');
    }
  });

  deleteCancelBtn.addEventListener('click', () => {
    deleteModal.classList.remove('visible');
    pendingDeleteId = null;
  });

  // La confirmation ferme la modale et met à jour la liste tout de suite,
  // sans attendre la réponse du serveur : la requête de suppression continue
  // en tâche de fond derrière — c'était l'attente de ce round-trip réseau qui
  // donnait l'impression d'une suppression lente. En cas d'échec (rare), on
  // resynchronise avec le serveur pour remettre le morceau dans la liste
  // plutôt que de laisser l'affichage mentir.
  deleteConfirmBtn.addEventListener('click', () => {
    if (pendingDeleteId === null) return;
    const id = pendingDeleteId;
    pendingDeleteId = null;
    deleteModal.classList.remove('visible');

    // Si le morceau/la vidéo supprimé(e) est en cours de lecture, on coupe
    // proprement tout de suite plutôt que d'attendre la confirmation serveur.
    if (id === currentTrackId) {
      if (crossfadeTimer) { clearTimeout(crossfadeTimer); crossfadeTimer = null; }
      clearAudioSource(audioA);
      clearAudioSource(audioB);
      releaseCache('current');
      currentTrackId = null;
      miniPlayer.classList.remove('visible');
      // Desktop : les paroles/la fiche artiste affichées ne correspondent
      // plus à rien une fois le morceau supprimé — masquées plutôt que de
      // laisser affiché le contenu d'un morceau qui n'existe plus.
      if (isDesktopLayout()) {
        closeLyricsSheet();
        paintDesktopArtistPanel(null, null);
      }
    }
    // Un morceau supprimé ne doit pas rester pré-chargé en mémoire, ni être
    // désigné comme le suivant.
    if (id === plannedNextId) { releasePrefetch(); plannedNextId = null; }
    if (videoModal.classList.contains('visible') && videoPlayer.src.endsWith('id=' + id)) {
      videoPlayer.pause();
      videoPlayer.removeAttribute('src');
      videoModal.classList.remove('visible', 'video-immersive', 'video-controls-hidden');
      currentVideoId = null;
    }

    // Retire la ligne tout de suite plutôt que d'attendre le prochain
    // fetchLibrary() : cet appel refait aussi deux requêtes de partage et
    // recalcule l'espace disque utilisé (stat de chaque fichier), ce qui
    // peut prendre plusieurs secondes sur une grosse bibliothèque.
    //
    // pendingDeletedIds bloque toute résurrection par un fetchLibrary()
    // concurrent (le sondage périodique notamment) tant que le serveur n'a
    // pas confirmé — sinon le morceau pouvait réapparaître, y compris dans
    // une recherche, entre le clic et la fin de la requête.
    pendingDeletedIds.add(id);
    rawAudioTracks = rawAudioTracks.filter(t => t.id !== id);
    rawVideoTracks = rawVideoTracks.filter(t => t.id !== id);
    rawOtherTracks = rawOtherTracks.filter(t => t.id !== id);
    renderLibrary(sortAudioTracks(rawAudioTracks));
    renderVideoLibrary(sortVideoTracks(rawVideoTracks));
    renderOtherLibrary(rawOtherTracks);
    applyLibraryFilter(libList, audioSearch.value);
    applyLibraryFilter(videoLibList, videoSearch.value);
    // renderLibrary/renderVideoLibrary vident tout le HTML de la liste (donc
    // aussi les résultats "autre type" déjà injectés) : on les reconstruit
    // pour que la recherche en cours reste complète après la suppression.
    applyCrossTypeSearch(libList, audioSearch.value, () => rawVideoTracks.concat(rawOtherTracks));
    applyCrossTypeSearch(videoLibList, videoSearch.value, () => rawAudioTracks.concat(rawOtherTracks));
    audioTabCount.textContent = rawAudioTracks.length;
    videoTabCount.textContent = rawVideoTracks.length;
    othersTabCount.textContent = rawOtherTracks.length;

    fetch('library-delete.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'id=' + encodeURIComponent(id)
    }).then(res => {
      if (!res.ok) throw new Error();
      pendingDeletedIds.delete(id);
      // Resynchronise en arrière-plan (espace libéré, changements côté
      // partages...) : n'a plus besoin de bloquer l'affichage de la suppression.
      fetchLibrary();
    }).catch(() => {
      pendingDeletedIds.delete(id);
      showToast('Impossible de supprimer ce fichier.', 'error');
      fetchLibrary();
    });
  });

  // Nom d'artiste = tout ce qui précède le premier " - " du titre, comme pour
  // le nommage des fichiers de la bibliothèque ("Artiste - Titre") ; à défaut
  // de séparateur, le titre entier sert de clé (tri alors quasi alphabétique
  // sur le titre lui-même, ce qui reste cohérent).
  function trackArtistKey(track) {
    // Même découpage que pour l'écran verrouillé : sans ça, « HAVASI — Prayer »
    // se classait à « havasi — prayer » et « Like a Prayer » à son titre, alors
    // que la chaîne YouTube est connue.
    return splitArtistTitle(track).artist.trim().toLowerCase()
      || (track.title || '').trim().toLowerCase();
  }

  // Même logique de tri pour l'audio et les clips, juste appliquée à des
  // listes/directions différentes — factorisée une fois plutôt que dupliquée.
  function sortByArtist(tracks, direction) {
    // Ordre naturel = date d'ajout décroissante (déjà celui des listes
    // reçues de library.php, voir combineOwnAndShared) : 'recent' et null
    // sont équivalents.
    if (!direction || direction === 'recent') return tracks.slice();
    const sorted = tracks.slice().sort((a, b) => trackArtistKey(a).localeCompare(trackArtistKey(b), 'fr'));
    if (direction === 'desc') sorted.reverse();
    return sorted;
  }

  function sortAudioTracks(tracks) {
    return sortByArtist(tracks, audioSortDirection);
  }

  function sortVideoTracks(tracks) {
    return sortByArtist(tracks, videoSortDirection);
  }

  // Les trois boutons de tri de la barre d'onglets agissent sur l'onglet
  // actif ; chaque onglet garde son propre tri (audioSortDirection /
  // videoSortDirection : 'asc', 'desc', 'recent' ou null = 'recent'), et
  // les boutons se réalignent à chaque changement d'onglet (voir switchTab).
  const librarySortButtons = [...document.querySelectorAll('.library-sort-btn')];
  const librarySortTargets = {}; // onglet -> { get, set, render, azIndex }

  function activeLibraryTab() {
    // Recherche directe par id, pas la constante playlistViewSection : elle
    // n'est déclarée que plus bas dans le script (avec le reste du bloc
    // playlist), et ce point-ci s'exécute dès l'initialisation — même
    // contournement que pour videoLibList plus haut dans le fichier.
    const playlistSection = document.getElementById('playlist-view-section');
    if (playlistSection && playlistSection.classList.contains('active-tab')) return 'playlist';
    return document.getElementById('tab-video').classList.contains('active') ? 'video' : 'audio';
  }

  function syncLibrarySortButtons() {
    const target = librarySortTargets[activeLibraryTab()];
    const direction = (target && target.get()) || 'recent';
    librarySortButtons.forEach(btn => btn.classList.toggle('active', btn.dataset.direction === direction));
  }

  librarySortButtons.forEach(btn => btn.addEventListener('click', () => {
    const target = librarySortTargets[activeLibraryTab()];
    if (!target) return;
    const direction = btn.dataset.direction;
    if ((target.get() || 'recent') === direction) return;
    target.set(direction);
    target.render();
    // L'ordre de la liste n'a de rapport avec les lettres qu'en tri par
    // artiste — la colonne d'index n'a de sens que là, et elle se lit dans
    // le même sens que la liste (Z → A, 9 → 0 en décroissant). La vue
    // playlist n'a pas cette colonne (azIndex absent pour cette cible).
    if (target.azIndex) {
      target.azIndex.classList.toggle('visible', direction !== 'recent');
      target.azIndex.setReversed(direction === 'desc');
    }
    syncLibrarySortButtons();
  }));

  // Index alphabétique façon liste de contacts (0-9 puis A-Z) : glisser ou
  // taper dessus saute directement au premier morceau dont l'artiste
  // commence par cette lettre. Pointer Events (pas touch/mouse séparés) +
  // setPointerCapture : le doigt/curseur peut légèrement sortir de la
  // colonne étroite pendant qu'on glisse sans interrompre le geste, comme
  // sur un vrai téléphone.
  const LIBRARY_AZ_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');

  // Réduit à une lettre A-Z, un chiffre, ou '#' — accents neutralisés (sans
  // ça « Étienne Daho » n'aurait jamais matché la lettre E de la colonne).
  function libraryAzKey(str) {
    const c = (str || '').trim().charAt(0);
    if (!c) return '#';
    // Filtre par point de code (0x0300-0x036F = diacritiques combinants une
    // fois la lettre décomposée en NFD) plutôt qu'une regex à base de
    // caractères combinants littéraux dans le code source — bien plus sûr
    // à relire/éditer sans corruption d'encodage.
    const plain = Array.from(c.normalize('NFD'))
      .filter(ch => { const code = ch.codePointAt(0); return code < 0x0300 || code > 0x036f; })
      .join('').toUpperCase();
    return /[0-9A-Z]/.test(plain) ? plain : '#';
  }

  const libraryAzBubble = document.getElementById('library-az-bubble');

  function setupAzIndex(indexEl, listEl) {
    // Ordre courant des caractères : celui de la liste (inversé en tri
    // décroissant, voir setReversed ci-dessous).
    let chars = LIBRARY_AZ_CHARS.slice();
    function renderChars() {
      indexEl.innerHTML = chars.map(c =>
        '<span class="library-az-char" data-char="' + c + '">' + c + '</span>'
      ).join('');
    }
    renderChars();
    indexEl.setReversed = reversed => {
      const next = reversed ? LIBRARY_AZ_CHARS.slice().reverse() : LIBRARY_AZ_CHARS.slice();
      if (next[0] === chars[0]) return;
      chars = next;
      renderChars();
    };

    function charAtPoint(clientY) {
      const rect = indexEl.getBoundingClientRect();
      const ratio = Math.min(1, Math.max(0, (clientY - rect.top) / rect.height));
      const idx = Math.min(chars.length - 1, Math.floor(ratio * chars.length));
      return chars[idx];
    }

    function jumpTo(char, showBubble) {
      const target = listEl.querySelector('.library-item[data-az-key="' + char + '"]:not(.search-hidden)');
      indexEl.querySelectorAll('.library-az-char').forEach(el => {
        el.classList.toggle('active', el.dataset.char === char);
      });
      // Bulle agrandie au centre de l'écran : réservée au tactile, où un
      // doigt recouvre la colonne trop étroite pour lire quoi que ce soit
      // dessus. En mode souris (desktop), le zoom se fait directement sur le
      // caractère survolé via le CSS (.library-az-char.active { transform:
      // scale(...) }) : la bulle centrale serait redondante et déconnectée
      // visuellement de la colonne qu'on regarde déjà.
      if (showBubble) libraryAzBubble.textContent = char;
      if (target) target.scrollIntoView({ block: 'start', behavior: 'auto' });
    }

    let dragging = false;
    // Souris (mode PC) : pas besoin de cliquer-glisser comme au doigt, le
    // simple survol de la colonne doit zoomer sur la lettre survolée.
    let hovering = false;
    indexEl.addEventListener('pointerdown', e => {
      dragging = true;
      const showBubble = e.pointerType !== 'mouse';
      // Ne doit jamais empêcher le reste (bulle, saut) de s'exécuter : sans
      // ce try/catch, un refus de capture (multi-touch, navigateur strict...)
      // aurait fait échouer silencieusement tout le geste.
      try { indexEl.setPointerCapture(e.pointerId); } catch (err) {}
      if (showBubble) libraryAzBubble.classList.add('visible');
      jumpTo(charAtPoint(e.clientY), showBubble);
      e.preventDefault();
    });
    indexEl.addEventListener('pointerenter', e => {
      if (e.pointerType !== 'mouse') return;
      hovering = true;
      jumpTo(charAtPoint(e.clientY), false);
    });
    indexEl.addEventListener('pointermove', e => {
      if (dragging) {
        jumpTo(charAtPoint(e.clientY), e.pointerType !== 'mouse');
      } else if (hovering && e.pointerType === 'mouse') {
        jumpTo(charAtPoint(e.clientY), false);
      }
    });
    indexEl.addEventListener('pointerleave', e => {
      if (e.pointerType !== 'mouse' || dragging) return;
      hovering = false;
      indexEl.querySelectorAll('.library-az-char.active').forEach(el => el.classList.remove('active'));
    });
    const stopDragging = () => {
      dragging = false;
      if (hovering) return;
      libraryAzBubble.classList.remove('visible');
      indexEl.querySelectorAll('.library-az-char.active').forEach(el => el.classList.remove('active'));
    };
    indexEl.addEventListener('pointerup', stopDragging);
    indexEl.addEventListener('pointercancel', stopDragging);
  }

  const libraryAzIndex = document.getElementById('library-az-index');
  setupAzIndex(libraryAzIndex, libList);
  librarySortTargets.audio = {
    get: () => audioSortDirection,
    set: (v) => { audioSortDirection = v; },
    render: () => renderLibrary(sortAudioTracks(rawAudioTracks)),
    azIndex: libraryAzIndex,
  };

  const videoLibraryAzIndex = document.getElementById('video-library-az-index');
  // videoLibList n'est déclarée que plus bas dans le script (avec le reste
  // du player vidéo) : on va chercher l'élément directement par id plutôt
  // que de référencer cette const avant son initialisation (TDZ).
  setupAzIndex(videoLibraryAzIndex, document.getElementById('video-library-list'));
  librarySortTargets.video = {
    get: () => videoSortDirection,
    set: (v) => { videoSortDirection = v; },
    render: () => renderVideoLibrary(sortVideoTracks(rawVideoTracks)),
    azIndex: videoLibraryAzIndex,
  };
  // Les boutons de tri restent ceux de la barre principale, juste appliqués
  // à playlistContext.items ('recent' y équivaut à l'ordre de la playlist,
  // comme null : voir sortByArtist). Colonne alphabétique dédiée, comme
  // Audio/Clips : récupérée par id directement — playlistViewList n'est
  // déclarée que plus bas dans le script (même contournement que pour
  // videoLibList plus haut).
  const playlistViewAzIndex = document.getElementById('playlist-view-az-index');
  setupAzIndex(playlistViewAzIndex, document.getElementById('playlist-view-list'));
  librarySortTargets.playlist = {
    get: () => playlistViewSortDirection,
    set: (v) => { playlistViewSortDirection = v; },
    render: () => renderPlaylistView(),
    azIndex: playlistViewAzIndex,
  };
  syncLibrarySortButtons();

  // Infobulle "data-hint" : remplace le title natif du navigateur sur les
  // icônes d'action de la bibliothèque (voir libraryActionsHtml). Un seul
  // élément flottant, en position:fixed et directement enfant de <body> —
  // jamais descendant de .library-item, qui a overflow:hidden (nécessaire
  // au repli des lignes filtrées par la recherche) et couperait toute bulle
  // positionnée en absolu au-dessus de l'icône. Affiché sur mouseenter/
  // mouseleave (n'importe quel micro-mouvement DANS l'icône ne déclenche
  // aucun de ces deux événements, contrairement à "mousemove" qui referme
  // le tooltip natif au moindre pixel) — reste donc affiché tant que le
  // curseur n'a pas vraiment quitté l'icône.
  let iconHintEl = null;
  function showIconHint(target) {
    const text = target.getAttribute('data-hint');
    if (!text) return;
    if (!iconHintEl) {
      iconHintEl = document.createElement('div');
      iconHintEl.className = 'icon-hint-float';
      document.body.appendChild(iconHintEl);
    }
    iconHintEl.textContent = text;
    const r = target.getBoundingClientRect();
    iconHintEl.style.left = (r.left + r.width / 2) + 'px';
    iconHintEl.style.top = (r.top - 8) + 'px';
    iconHintEl.classList.add('visible');
  }
  function hideIconHint() {
    if (iconHintEl) iconHintEl.classList.remove('visible');
  }
  document.addEventListener('mouseover', e => {
    const target = e.target.closest('[data-hint]');
    if (target) showIconHint(target);
  });
  document.addEventListener('mouseout', e => {
    const target = e.target.closest('[data-hint]');
    if (target && !target.contains(e.relatedTarget)) hideIconHint();
  });

  // Bloc d'actions à droite de chaque morceau/vidéo : deux variantes selon
  // que le fichier m'appartient (partage + suppression) ou qu'il m'a été
  // partagé (repartage + retrait de mes partages uniquement — je ne peux pas
  // supprimer le fichier de quelqu'un d'autre). Factorisé une fois, utilisé
  // aussi bien pour l'audio que la vidéo.
  function libraryActionsHtml(track, isShared) {
    // data-hint plutôt que title : le tooltip natif du navigateur se
    // referme au moindre mousemove, même si la souris reste dans l'icône
    // (comportement natif de Chrome/Firefox, pas un bug ici) — ça donnait
    // exactement le symptôme rapporté ("trop sensible, disparaît dès qu'on
    // bouge la souris d'un pixel"). data-hint + showIconHint/hideIconHint
    // (voir plus bas, sur mouseenter/mouseleave — jamais mousemove) reste
    // affiché tant que le curseur n'a pas vraiment quitté l'icône.
    // Renommage manuel du titre — réservé à l'admin (voir IS_ADMIN), pas un
    // réglage par compte : le titre est partagé par tout le monde qui tombe
    // sur ce même fichier, au même titre que le nettoyage automatique
    // (voir ytdl_clean_title) ou le bouton de nettoyage global (admin.php).
    const renameBtn = IS_ADMIN
      ? '<button type="button" class="library-download-btn library-rename-btn" aria-label="Renommer" data-hint="Renommer">' + RENAME_ICON_SVG + '</button>'
      : '';
    const common =
      renameBtn +
      '<button type="button" class="library-download-btn library-lyrics-btn" aria-label="Voir les paroles" data-hint="Voir les paroles" style="visibility:hidden;pointer-events:none;">' + LYRICS_ICON_SVG + '</button>' +
      '<button type="button" class="library-download-btn library-playlist-btn" aria-label="Ajouter à une playlist" data-hint="Ajouter à une playlist">' + PLAYLIST_ADD_ICON_SVG + '</button>' +
      '<a class="library-download-btn library-source-link" aria-label="Voir sur YouTube (retélécharger)" data-hint="Voir sur YouTube (retélécharger)" target="_blank" rel="noopener">' + SOURCE_LINK_ICON_SVG + '</a>' +
      '<a class="library-download-btn library-download-link" aria-label="Télécharger" data-hint="Télécharger" download>⬇</a>';
    // Même bouton final dans les deux cas — poubelle — mais deux actions
    // différentes : suppression réelle du fichier pour le propriétaire,
    // simple retrait de MON accès pour un fichier qu'on m'a partagé (le
    // fichier reste chez son propriétaire). L'infobulle et la fenêtre de
    // confirmation portent la distinction.
    const lastBtn = isShared
      ? '<button type="button" class="library-download-btn library-delete-btn library-unshare-btn" aria-label="Retirer de mes partages" data-hint="Retirer de mes partages">'
      : '<button type="button" class="library-download-btn library-delete-btn" aria-label="Supprimer" data-hint="Supprimer">';

    const shareLabel = isShared ? 'Repartager' : 'Partager';
    return common +
      '<button type="button" class="library-download-btn library-share-btn" aria-label="' + shareLabel + '" data-hint="' + shareLabel + '">' + SHARE_ICON_SVG + '</button>' +
      lastBtn + TRASH_ICON_SVG + '</button>';
  }

  // Modale de note (voir son HTML, #rating-modal-overlay) : isole le vote
  // du reste de l'appli (rien d'autre n'est atteignable tant qu'elle est
  // ouverte) plutôt qu'un petit sélecteur flottant près du bouton "★" —
  // couleurs fixes dans le CSS (jamais var(--panel)/var(--border)), pour
  // rester lisible quel que soit le skin actif (Kawaii notamment, trop
  // clair pour un panneau qui suivrait ses teintes). Note posée pour CETTE
  // ligne précisément (voir library-rate.php), indépendante de ce qui joue
  // dans le mini-lecteur (voir renderMiniStars/miniRatingEl, un système à
  // part qui ne note que la piste en cours).
  const ratingModalOverlay = document.getElementById('rating-modal-overlay');
  const ratingModalTitleEl = document.getElementById('rating-modal-title');
  const ratingModalStars = Array.from(document.querySelectorAll('#rating-modal-stars button'));
  let ratingModalTrack = null;
  let ratingModalStarBtn = null; // le bouton "★" du panneau de glissement qui a ouvert la modale — pour refléter la note dessus une fois posée

  function renderRatingModalStars(rating) {
    ratingModalStars.forEach(b => b.classList.toggle('filled', Number(b.dataset.value) <= rating));
  }
  function openRatingModal(track, starBtn) {
    ratingModalTrack = track;
    ratingModalStarBtn = starBtn;
    ratingModalTitleEl.textContent = splitArtistTitle(track).title || track.title || 'Sans titre';
    renderRatingModalStars(track.rating || 0);
    ratingModalOverlay.classList.add('visible');
  }
  function closeRatingModal() {
    ratingModalOverlay.classList.remove('visible');
    ratingModalTrack = null;
    ratingModalStarBtn = null;
  }
  ratingModalStars.forEach(star => {
    star.addEventListener('click', e => {
      e.stopPropagation();
      if (!ratingModalTrack) return;
      const clicked = Number(star.dataset.value);
      const current = ratingModalTrack.rating || 0;
      const newRating = clicked === current ? 0 : clicked; // recliquer la note déjà posée la retire
      const track = ratingModalTrack, starBtn = ratingModalStarBtn;
      track.rating = newRating;
      renderRatingModalStars(newRating);
      if (starBtn) starBtn.classList.toggle('library-item-swipe-rated', newRating > 0);
      fetch('library-rate.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: track.id, rating: newRating }),
      }).catch(() => { /* pas bloquant : la note reste appliquée localement */ });
      // Laisse les étoiles pleines VISIBLES un instant avant de refermer —
      // fermer au même instant que le clic masquait le changement de
      // couleur, donnant l'impression que le vote n'avait pas été pris en
      // compte alors qu'il l'était bien.
      setTimeout(closeRatingModal, 1000);
    });
  });
  // Touche n'importe où sur le fond assombri (jamais la modale elle-même) :
  // referme sans rien changer — même motif que profileDrawerOverlay.
  ratingModalOverlay.addEventListener('click', e => {
    if (e.target === ratingModalOverlay) closeRatingModal();
  });

  function bindLibraryActionButtons(item, track, isShared) {
    // Absent du DOM si !IS_ADMIN (voir libraryActionsHtml) — jamais rendu
    // pour un compte non-admin, pas juste masqué en CSS.
    const renameBtn = item.querySelector('.library-rename-btn');
    if (renameBtn) {
      renameBtn.addEventListener('click', e => { e.stopPropagation(); startRenameTrack(item, track); });
    }
    // Icône séparée, à côté du titre (voir .library-title-row) — visible
    // UNIQUEMENT en mobile (masquée en CSS sur desktop), où .library-rename-
    // btn ci-dessus reste caché derrière le panneau de glissement plutôt
    // qu'accessible d'un tap direct.
    const mobileRenameBtn = item.querySelector('.library-mobile-rename-btn');
    if (mobileRenameBtn) {
      mobileRenameBtn.addEventListener('click', e => { e.stopPropagation(); openMobileRenameModal(item, track); });
    }
    const lyricsBtn = item.querySelector('.library-lyrics-btn');
    lyricsBtn.addEventListener('click', e => {
      e.stopPropagation();
      if (lyricsBtn.dataset.lyrics) {
        const synced = lyricsBtn.dataset.synced ? JSON.parse(lyricsBtn.dataset.synced) : null;
        openLyricsSheet(track, lyricsBtn.dataset.lyrics, synced, Number(lyricsBtn.dataset.offset || 0));
      }
    });
    const playlistBtn = item.querySelector('.library-playlist-btn');
    // L'id est posé sur le bouton lui-même, indépendamment de celui de la
    // ligne : audio et clips partagent les mêmes ids côté bibliothèque.
    playlistBtn.dataset.trackId = track.id;
    playlistBtn.classList.toggle('in-playlist', isTrackInPlaylist(track.id));
    playlistBtn.addEventListener('click', e => { e.stopPropagation(); openAddToPlaylist(track); });
    const sourceLink = item.querySelector('.library-source-link');
    sourceLink.href = track.url || '#';
    sourceLink.addEventListener('click', e => e.stopPropagation());
    const downloadBtn = item.querySelector('.library-download-link');
    downloadBtn.href = 'library-play.php?id=' + encodeURIComponent(track.id) + '&download=1';
    downloadBtn.addEventListener('click', e => e.stopPropagation());
    const shareBtn = item.querySelector('.library-share-btn');
    shareBtn.addEventListener('click', e => { e.stopPropagation(); openShareModal(track); });
    const deleteBtn = item.querySelector('.library-delete-btn');
    deleteBtn.addEventListener('click', e => {
      e.stopPropagation();
      if (isShared) openUnshareConfirm(track);   // retire mon accès, jamais le fichier
      else openDeleteConfirm(track);
    });
    const swipeLyricsBtn = setupSwipeToDelete(item, track, isShared);
    queueLyricsCheck(track, [lyricsBtn, swipeLyricsBtn]);
  }

  // Renommage manuel d'un titre (admin, MOBILE) — via une modale plutôt que
  // l'édition en place utilisée sur desktop (voir startRenameTrack juste en
  // dessous) : un petit écran laisse trop peu de place dans la ligne pour
  // corriger confortablement du pouce, la modale isole l'action et affiche
  // le titre en plus gros, centré.
  const mobileRenameModal = document.getElementById('mobile-rename-modal');
  const mobileRenameInput = document.getElementById('mobile-rename-input');
  const mobileRenameCancelBtn = document.getElementById('mobile-rename-cancel-btn');
  const mobileRenameConfirmBtn = document.getElementById('mobile-rename-confirm-btn');
  let mobileRenameTrack = null;
  let mobileRenameItem = null;
  function openMobileRenameModal(item, track) {
    mobileRenameTrack = track;
    mobileRenameItem = item;
    mobileRenameInput.value = track.title || '';
    mobileRenameModal.classList.add('visible');
    // Un léger délai laisse la modale finir son animation d'entrée avant de
    // donner le focus — sinon le clavier virtuel s'ouvre AVANT que la
    // modale n'ait fini d'apparaître, saccadant la transition sur mobile.
    setTimeout(() => { mobileRenameInput.focus(); mobileRenameInput.select(); }, 150);
  }
  function closeMobileRenameModal() {
    mobileRenameModal.classList.remove('visible');
    mobileRenameTrack = null;
    mobileRenameItem = null;
  }
  mobileRenameCancelBtn.addEventListener('click', closeMobileRenameModal);
  // Touche n'importe où sur le fond assombri (jamais la modale elle-même) :
  // referme sans rien changer — même motif que les autres modales du fichier.
  mobileRenameModal.addEventListener('click', e => { if (e.target === mobileRenameModal) closeMobileRenameModal(); });
  mobileRenameInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); mobileRenameConfirmBtn.click(); }
    else if (e.key === 'Escape') { e.preventDefault(); closeMobileRenameModal(); }
  });
  mobileRenameConfirmBtn.addEventListener('click', () => {
    if (!mobileRenameTrack) return;
    const newTitle = mobileRenameInput.value.trim();
    const track = mobileRenameTrack, item = mobileRenameItem;
    closeMobileRenameModal();
    if (newTitle !== '' && newTitle !== track.title) saveTrackRename(track, newTitle, item);
  });

  // Renommage manuel d'un titre (admin, DESKTOP) — un <input> remplace la
  // ligne affichée EN PLACE, comme un renommage de fichier sous Windows
  // (sélection du texte entier, Entrée/perte du focus valide, Échap
  // annule) plutôt qu'un champ dans une modale à part.
  function startRenameTrack(item, track) {
    const titleEl = item.querySelector('.library-item-title');
    if (!titleEl || item.querySelector('.library-rename-input')) return; // déjà en cours sur cette ligne
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'library-rename-input';
    input.value = track.title || '';
    input.maxLength = 300;
    titleEl.style.display = 'none';
    titleEl.insertAdjacentElement('afterend', input);
    input.focus();
    input.select();

    let settled = false;
    function finish(commit) {
      if (settled) return;
      settled = true;
      input.removeEventListener('blur', onBlur);
      const newTitle = input.value.trim();
      input.remove();
      titleEl.style.display = '';
      if (commit && newTitle !== '' && newTitle !== track.title) {
        saveTrackRename(track, newTitle, item);
      }
    }
    function onBlur() { finish(true); } // comme l'Explorateur Windows : perdre le focus valide
    input.addEventListener('blur', onBlur);
    input.addEventListener('keydown', e => {
      e.stopPropagation(); // jamais les raccourcis clavier de la bibliothèque pendant la saisie
      if (e.key === 'Enter') { e.preventDefault(); finish(true); }
      else if (e.key === 'Escape') { e.preventDefault(); finish(false); }
    });
    input.addEventListener('click', e => e.stopPropagation());
  }

  async function saveTrackRename(track, newTitle, item) {
    const oldTitle = track.title;
    // Optimiste : la ligne reflète le nouveau titre tout de suite, sans
    // attendre la confirmation serveur — revient en arrière seulement en
    // cas d'échec (voir catch).
    track.title = newTitle;
    renderLibraryItemTitle(item, track);
    // Si ce morceau est celui EN COURS DE LECTURE, le lecteur intégré (mini-
    // lecteur audio, modal vidéo, écran verrouillé) affichait encore
    // l'ancien titre jusqu'au morceau suivant — mis à jour ici tout de
    // suite, avec le même repli en cas d'échec que la ligne ci-dessus.
    refreshPlayingLabelsAfterRename(track);
    try {
      const res = await fetch('library-rename.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: track.id, title: newTitle }),
      });
      if (!res.ok) throw new Error();
      const data = await res.json();
      showToast(
        data.lyrics_found_now ? 'Titre renommé — paroles retrouvées avec le nouveau titre.' : 'Titre renommé.',
        'success'
      );
      // Le changement de titre invalide tout ce qui avait été déduit de
      // l'ancien (disponibilité des paroles notamment, voir dataset.lyrics/
      // synced sur ce bouton précis) — revérifié avec le nouveau titre au
      // lieu de laisser un état obsolète tant que la page n'est pas rechargée.
      const lyricsBtn = item.querySelector('.library-lyrics-btn');
      const swipeLyricsBtn = item.querySelector('.library-item-swipe-action-btn');
      if (lyricsBtn) queueLyricsCheck(track, swipeLyricsBtn ? [lyricsBtn, swipeLyricsBtn] : lyricsBtn);
    } catch (err) {
      track.title = oldTitle;
      renderLibraryItemTitle(item, track);
      refreshPlayingLabelsAfterRename(track);
      showToast('Échec du renommage.', 'error');
    }
  }

  // Reflète un titre modifié dans le(s) lecteur(s) intégré(s), SI ce
  // morceau est celui actuellement en train de jouer — jamais sinon (un
  // renommage sur une ligne qui ne joue pas ne doit rien changer à
  // l'affichage en cours). Sépare ce cas de renderLibraryItemTitle (qui ne
  // touche, lui, que la ligne de la bibliothèque) : le mini-lecteur audio,
  // le modal vidéo et l'écran verrouillé (Media Session) sont des affichages
  // à part, avec chacun leur propre état à mettre à jour.
  function refreshPlayingLabelsAfterRename(track) {
    if (track.id === currentTrackId) {
      const miniParts = splitArtistTitle(track);
      setMiniPlayerTitle(miniParts.artist ? (miniParts.artist + ' - ' + miniParts.title) : miniParts.title);
      updateMediaSessionMetadata(track);
    }
    if (track.id === currentVideoId) {
      videoModalTitle.textContent = track.title || 'Sans titre';
      updateMediaSessionMetadata(track);
    }
  }

  // Glisser une ligne vers la gauche (tactile) révèle un panneau de 7
  // actions derrière (paroles, téléchargement, YouTube, Supprimer/Retirer)
  // — même geste que Mail/Twitter. Ne fait QUE révéler le panneau : la
  // suppression réelle passe encore par la confirmation existante
  // (openDeleteConfirm/openUnshareConfirm), jamais directement au
  // relâchement, pour éviter un glissement accidentel qui supprime pour de
  // vrai sans qu'on l'ait voulu.
  let openSwipeItem = null; // une seule ligne ouverte à la fois
  // Toucher n'importe où ailleurs referme la ligne ouverte — un seul
  // écouteur global plutôt qu'un par ligne (setupSwipeToDelete est appelée
  // une fois par morceau affiché).
  document.addEventListener('pointerdown', e => {
    if (openSwipeItem && !openSwipeItem.contains(e.target)) closeSwipeItem(openSwipeItem);
  }, { passive: true });
  // Renvoie le bouton "paroles" du panneau (pas encore rattaché à
  // queueLyricsCheck ici — l'appelant, bindLibraryActionButtons, le combine
  // avec celui de la ligne normale pour ne lancer qu'une seule requête).
  function setupSwipeToDelete(item, track, isShared) {
    const REVEAL = 7 * 56; // largeur du panneau, = 7 × .library-item-swipe-action-btn
    const content = document.createElement('div');
    content.className = 'library-item-swipe-content';
    while (item.firstChild) content.appendChild(item.firstChild);

    const action = document.createElement('div');
    action.className = 'library-item-swipe-action';

    const swipeLyricsBtn = document.createElement('button');
    swipeLyricsBtn.type = 'button';
    swipeLyricsBtn.className = 'library-item-swipe-action-btn';
    swipeLyricsBtn.innerHTML = LYRICS_ICON_SVG;
    swipeLyricsBtn.title = 'Voir les paroles';
    swipeLyricsBtn.style.visibility = 'hidden';
    swipeLyricsBtn.style.pointerEvents = 'none';
    swipeLyricsBtn.addEventListener('click', e => {
      e.stopPropagation();
      close(true);
      if (swipeLyricsBtn.dataset.lyrics) {
        const synced = swipeLyricsBtn.dataset.synced ? JSON.parse(swipeLyricsBtn.dataset.synced) : null;
        openLyricsSheet(track, swipeLyricsBtn.dataset.lyrics, synced, Number(swipeLyricsBtn.dataset.offset || 0));
      }
    });

    const downloadLink = document.createElement('a');
    downloadLink.className = 'library-item-swipe-action-btn';
    downloadLink.href = 'library-play.php?id=' + encodeURIComponent(track.id) + '&download=1';
    downloadLink.setAttribute('download', '');
    downloadLink.title = 'Télécharger';
    downloadLink.textContent = '⬇';
    downloadLink.addEventListener('click', e => { e.stopPropagation(); close(true); });

    const sourceLink = document.createElement('a');
    sourceLink.className = 'library-item-swipe-action-btn';
    sourceLink.href = track.url || '#';
    sourceLink.target = '_blank';
    sourceLink.rel = 'noopener';
    sourceLink.title = 'Voir sur YouTube (retélécharger)';
    sourceLink.innerHTML = SOURCE_LINK_ICON_SVG;
    sourceLink.addEventListener('click', e => { e.stopPropagation(); close(true); });

    // Note : n'ouvre PAS directement la confirmation/l'action comme les
    // autres — un tap ouvre la modale de note (voir openRatingModal) pour
    // choisir la note, indépendamment de ce qui joue dans le mini-lecteur.
    const starBtn = document.createElement('button');
    starBtn.type = 'button';
    starBtn.className = 'library-item-swipe-action-btn' + (track.rating > 0 ? ' library-item-swipe-rated' : '');
    starBtn.textContent = '★';
    starBtn.title = 'Noter ce titre';
    starBtn.addEventListener('click', e => { e.stopPropagation(); close(true); openRatingModal(track, starBtn); });

    const swipePlaylistBtn = document.createElement('button');
    swipePlaylistBtn.type = 'button';
    swipePlaylistBtn.className = 'library-item-swipe-action-btn' + (isTrackInPlaylist(track.id) ? ' library-item-swipe-in-playlist' : '');
    swipePlaylistBtn.innerHTML = PLAYLIST_ADD_ICON_SVG;
    swipePlaylistBtn.title = 'Ajouter à une playlist';
    swipePlaylistBtn.addEventListener('click', e => { e.stopPropagation(); close(true); openAddToPlaylist(track); });

    const swipeShareBtn = document.createElement('button');
    swipeShareBtn.type = 'button';
    swipeShareBtn.className = 'library-item-swipe-action-btn';
    swipeShareBtn.innerHTML = SHARE_ICON_SVG;
    swipeShareBtn.title = isShared ? 'Repartager' : 'Partager';
    swipeShareBtn.addEventListener('click', e => { e.stopPropagation(); close(true); openShareModal(track); });

    const deleteBtn = document.createElement('button');
    deleteBtn.type = 'button';
    deleteBtn.className = 'library-item-swipe-action-btn library-item-swipe-delete';
    deleteBtn.innerHTML = TRASH_ICON_SVG;
    deleteBtn.title = isShared ? 'Retirer' : 'Supprimer';
    deleteBtn.setAttribute('aria-label', deleteBtn.title);
    deleteBtn.addEventListener('click', e => {
      e.stopPropagation();
      close(true);
      if (isShared) openUnshareConfirm(track);   // retire mon accès, jamais le fichier
      else openDeleteConfirm(track);
    });

    action.append(swipeLyricsBtn, downloadLink, sourceLink, starBtn, swipePlaylistBtn, swipeShareBtn, deleteBtn);
    item.append(content, action);
    item.classList.add('swipeable');

    let open = false, dragging = false, axis = null, startX = 0, startY = 0, base = 0, maxDrag = REVEAL;

    function setOffset(x, animate) {
      content.style.transition = animate ? 'transform 0.2s ease' : 'none';
      content.style.transform = x ? 'translateX(' + x + 'px)' : '';
    }
    function close(animate) {
      if (openSwipeItem === item) openSwipeItem = null;
      open = false;
      setOffset(0, animate);
    }

    item.addEventListener('pointerdown', e => {
      if (e.pointerType === 'mouse') return; // souris : la ligne suffit déjà (bouton poubelle visible)
      if (openSwipeItem && openSwipeItem !== item) closeSwipeItem(openSwipeItem);
      startX = e.clientX; startY = e.clientY; base = open ? -REVEAL : 0;
      // Avec 7 actions, REVEAL (largeur du panneau complet) dépasse le tiers
      // de l'écran — glisser le pouce sur une telle distance, à chaque
      // ligne, fatigue vite. Le panneau s'ouvre donc toujours EN ENTIER
      // (voir endDrag, qui vise toujours -REVEAL au relâchement), mais le
      // DOIGT n'a jamais besoin de parcourir plus du tiers de la largeur de
      // la ligne pour y arriver — au-delà, le contenu ne suit plus le doigt
      // 1:1, il se contente d'attendre le relâchement.
      maxDrag = item.getBoundingClientRect().width / 3;
      dragging = true; axis = null;
    });
    item.addEventListener('pointermove', e => {
      if (!dragging) return;
      const dx = e.clientX - startX, dy = e.clientY - startY;
      if (axis === null) {
        if (Math.abs(dx) < 6 && Math.abs(dy) < 6) return;
        axis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
        if (axis === 'y') { dragging = false; return; } // laisse le scroll vertical natif faire son travail
      }
      if (axis !== 'x') return;
      e.preventDefault();
      setOffset(Math.min(0, Math.max(-maxDrag - 10, base + dx)), false);
    });
    function endDrag(e) {
      if (!dragging) return;
      dragging = false;
      if (axis !== 'x') return;
      const dx = e.clientX - startX;
      // Le seuil de bascule (la moitié du tiers d'écran, donc un sixième de
      // la largeur) reste proportionnellement facile à atteindre — seule la
      // position de REPOS change (-REVEAL, le panneau complet), jamais le
      // seuil ni l'amplitude du geste lui-même.
      open = (base + dx) < -maxDrag / 2;
      openSwipeItem = open ? item : (openSwipeItem === item ? null : openSwipeItem);
      setOffset(open ? -REVEAL : 0, true);
      if (open) {
        // Un tap juste après l'ouverture ne doit pas être pris pour un clic
        // "lecture" sur le morceau — sauf si c'est justement un tap sur le
        // bouton révélé lui-même : sans cette exception, ce premier tap sur
        // "Supprimer" était avalé avant même d'atteindre son propre clic.
        const swallowNextClick = ce => {
          if (ce.target === action || action.contains(ce.target)) return;
          ce.stopPropagation(); ce.preventDefault();
        };
        item.addEventListener('click', swallowNextClick, { capture: true, once: true });
      }
    }
    item.addEventListener('pointerup', endDrag);
    item.addEventListener('pointercancel', () => { dragging = false; setOffset(open ? -REVEAL : 0, true); });

    item._closeSwipe = () => close(true);
    // Exposés pour playSwipeHintDemo (démo au tap sur l'avatar mobile) : le
    // vrai contenu et la vraie largeur de révélation, pour rejouer le geste
    // à l'identique (même transform que le glissement réel), pas une
    // simulation à côté qui pourrait diverger si REVEAL change un jour.
    item._swipeContent = content;
    item._swipeReveal = REVEAL;
    return swipeLyricsBtn;
  }
  function closeSwipeItem(item) { if (item && item._closeSwipe) item._closeSwipe(); }

  // Démo du geste de glissement — les icônes paroles/téléchargement/YouTube/
  // suppression disparaissent de la ligne au doigt (voir @media (pointer:
  // coarse) plus haut) au profit du panneau révélé par glissement ; sans
  // repère visuel, rien n'indique que ce geste existe. Déclenchée au tap sur
  // l'avatar mobile (voir setupSwipeHintAvatar), jamais automatiquement — un
  // titre AU HASARD dans la liste visible, pas systématiquement le premier
  // ou celui en lecture, pour ne pas laisser croire que le geste ne marche
  // que sur celui-là.
  // v2 (pas v1) : l'ancienne version de cet indice se déclenchait tout
  // seule au chargement (voir l'ancien showSwipeHintOnce) et posait déjà la
  // clé v1 sur beaucoup d'appareils. La nouvelle version (au tap sur
  // l'avatar) doit pouvoir se montrer même sur ces appareils-là — d'où une
  // clé différente plutôt que la même.
  const SWIPE_HINT_KEY = 'ytdl_swipe_hint_seen_v2';
  // Pouce VERTICAL au repos (ongle en haut, vu de dessus) — voir .swipe-hint-thumb
  // pour la rotation/le déplacement, pilotés en JS ci-dessous, pas ici.
  const SWIPE_HINT_THUMB_SVG =
    '<svg viewBox="0 0 40 56" xmlns="http://www.w3.org/2000/svg">' +
      '<path d="M20 4C10 4 4 12 4 24v10c0 12 7 18 16 18s16-6 16-18V24C36 12 30 4 20 4Z" fill="#e3a877" stroke="#b8794a" stroke-width="1.5"/>' +
      '<ellipse cx="20" cy="16" rx="10" ry="12" fill="#f6dcc4" stroke="#caa07a" stroke-width="1"/>' +
      '<ellipse cx="16" cy="10" rx="3" ry="4.5" fill="#fff" opacity="0.5"/>' +
    '</svg>';
  // Durées d'une répétition (ms) — volontairement lentes, 3 fois de suite :
  // le geste doit se voir, pas juste se deviner. OPEN fait glisser LE VRAI
  // contenu de la ligne (même transform que .library-item-swipe-content au
  // doigt — voir item._swipeContent/_swipeReveal) en même temps que le pouce
  // avance vers la gauche en pivotant de 45°, HOLD laisse le panneau (les
  // vrais boutons) visible un instant, puis CLOSE ramène les deux.
  const SWIPE_HINT_OPEN_MS = 900, SWIPE_HINT_HOLD_MS = 750, SWIPE_HINT_CLOSE_MS = 700, SWIPE_HINT_GAP_MS = 350;
  function playSwipeHintDemo() {
    const items = Array.from(document.querySelectorAll('#library-list .library-item, #video-library-list .library-item'))
      .filter(el => el.getBoundingClientRect().height > 0 && el._swipeContent);
    const target = items.length ? items[Math.floor(Math.random() * items.length)] : null;
    if (!target) return;
    const content = target._swipeContent;
    const reveal = target._swipeReveal;
    // Le doigt ne parcourt jamais plus du tiers de la largeur de la ligne
    // (voir setupSwipeToDelete/maxDrag) même si le panneau complet, lui, est
    // plus large — le pouce de la démo doit donc s'arrêter à cette même
    // distance, pas à michemin du panneau entier, sous peine d'enseigner un
    // geste plus grand que celui réellement nécessaire.
    const thumbTravel = target.getBoundingClientRect().width / 3;

    // La ligne choisie au hasard peut être plus bas que l'écran visible au
    // moment du tap sur l'avatar — inutile de jouer la démo sur un titre que
    // l'utilisateur ne voit pas. On la centre d'abord, et on laisse le
    // défilement (fluide) se terminer avant de démarrer l'animation.
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => {
      target.classList.add('swipe-hint-active');
      const thumb = document.createElement('div');
      thumb.className = 'swipe-hint-thumb';
      thumb.innerHTML = SWIPE_HINT_THUMB_SVG;
      target.appendChild(thumb);

      const toast = document.createElement('div');
      toast.className = 'swipe-hint-toast';
      toast.textContent = 'Glisse un morceau vers la gauche : paroles, téléchargement, YouTube, note, playlist, partage et suppression sont maintenant là.';
      document.body.appendChild(toast);
      requestAnimationFrame(() => {
        thumb.classList.add('visible');
        toast.classList.add('visible');
      });

      function openStep(cycle) {
        thumb.style.transition = 'right ' + SWIPE_HINT_OPEN_MS + 'ms ease, transform ' + SWIPE_HINT_OPEN_MS + 'ms ease';
        thumb.style.right = (28 + thumbTravel) + 'px';
        thumb.style.transform = 'translateY(-50%) rotate(-45deg)';
        content.style.transition = 'transform ' + SWIPE_HINT_OPEN_MS + 'ms ease';
        content.style.transform = 'translateX(-' + reveal + 'px)';
        setTimeout(() => closeStep(cycle), SWIPE_HINT_OPEN_MS + SWIPE_HINT_HOLD_MS);
      }
      function closeStep(cycle) {
        thumb.style.transition = 'right ' + SWIPE_HINT_CLOSE_MS + 'ms ease, transform ' + SWIPE_HINT_CLOSE_MS + 'ms ease';
        thumb.style.right = '28px';
        thumb.style.transform = 'translateY(-50%) rotate(0deg)';
        content.style.transition = 'transform ' + SWIPE_HINT_CLOSE_MS + 'ms ease';
        content.style.transform = '';
        if (cycle < 3) {
          setTimeout(() => openStep(cycle + 1), SWIPE_HINT_CLOSE_MS + SWIPE_HINT_GAP_MS);
        } else {
          setTimeout(finish, SWIPE_HINT_CLOSE_MS + 500);
        }
      }
      function finish() {
        target.classList.remove('swipe-hint-active');
        thumb.classList.remove('visible');
        toast.classList.remove('visible');
        setTimeout(() => { thumb.remove(); toast.remove(); }, 400);
      }
      openStep(1);
    }, 450);
  }

  // Lueur néon sur l'avatar mobile tant que la démo n'a pas encore été vue
  // sur cet appareil (localStorage, jamais synchronisé entre appareils —
  // comme le reste des préférences "par appareil" de ce fichier). Le premier
  // tap joue la démo et éteint la lueur DÉFINITIVEMENT (pas de rappel) au
  // lieu d'ouvrir le menu déroulant ; les taps suivants rouvrent le menu
  // normalement (voir le listener posé plus haut sur mobileUserBtn — celui-
  // ci passe en premier grâce à la capture, voir addEventListener ci-dessous).
  function setupSwipeHintAvatar() {
    let seen;
    try { seen = localStorage.getItem(SWIPE_HINT_KEY); } catch (err) { seen = '1'; } // mode privé strict : jamais de lueur plutôt que de la remontrer à chaque rechargement
    if (seen) return;
    mobileUserBtn.classList.add('swipe-hint-glow');
    mobileUserBtn.addEventListener('click', function onFirstTap(e) {
      e.stopPropagation();
      e.preventDefault();
      mobileUserBtn.classList.remove('swipe-hint-glow');
      mobileUserBtn.removeEventListener('click', onFirstTap, true);
      try { localStorage.setItem(SWIPE_HINT_KEY, '1'); } catch (err) {}
      playSwipeHintDemo();
    }, true); // capture : s'exécute avant le toggle du menu déroulant posé plus haut, et l'annule (preventDefault + stopPropagation).
  }

  // --- Paroles ---
  // Une même chanson peut apparaître sur plusieurs lignes (audio + clip,
  // doublon) et la bibliothèque se re-rend souvent (tri, rafraîchissement
  // après import) : le cache évite de reposer la question au serveur à
  // chaque fois, et la file d'attente limite le nombre de requêtes lancées
  // en même temps lors du tout premier rendu d'une grosse bibliothèque.
  const lyricsCache = new Map();
  const lyricsPending = new Map();
  const lyricsQueue = [];
  let lyricsActiveRequests = 0;
  const LYRICS_MAX_CONCURRENT = 3;

  function lyricsCacheKey(artist, title) {
    return artist.trim().toLowerCase() + '\u241F' + title.trim().toLowerCase();
  }

  // btns : un bouton ou un tableau de boutons — la ligne normale ET le
  // panneau de swipe mobile portent chacun leur propre bouton "paroles",
  // tous deux doivent refléter la même disponibilité sans dupliquer l'appel
  // réseau (voir queueLyricsCheck).
  function applyLyricsToButton(btns, entry) {
    const list = Array.isArray(btns) ? btns : [btns];
    // "synchro" seulement quand c'est vrai (lrclib.net a un minutage, pas
    // juste lyrics.ovh en repli) : même info que la couleur de l'icône
    // (.lyrics-synced), mais lisible au survol sans avoir à deviner ce que
    // veut dire la couleur.
    const label = (entry && entry.available && entry.synced) ? 'Voir les paroles synchro' : 'Voir les paroles';
    list.forEach(btn => {
      // title (mini-lecteur, panneau de glissement mobile, lecteur vidéo) ET
      // data-hint/aria-label (ligne de bibliothèque, tooltip maison) : chaque
      // bouton n'utilise que celui qu'il a déjà, jamais les deux à la fois
      // (un title en plus d'un data-hint donnerait une double bulle).
      if (btn.title) btn.title = label;
      if (btn.dataset.hint !== undefined) btn.dataset.hint = label;
      if (btn.hasAttribute('aria-label')) btn.setAttribute('aria-label', label);
      if (entry && entry.available) {
        btn.style.visibility = '';
        btn.style.pointerEvents = '';
        btn.dataset.lyrics = entry.lyrics;
        // Icône coloriée : distingue d'un coup d'œil les morceaux dont les
        // paroles défilent en rythme (lrclib.net) de ceux qui n'ont qu'un
        // texte brut sans minutage.
        if (entry.synced) {
          btn.dataset.synced = JSON.stringify(entry.synced);
          btn.dataset.offset = entry.offset || 0;
          btn.classList.add('lyrics-synced');
        } else {
          delete btn.dataset.synced;
          delete btn.dataset.offset;
          btn.classList.remove('lyrics-synced');
        }
      } else {
        // Invisible mais toujours en place (visibility, pas display) : sans
        // paroles disponibles, l'icône ne doit ni se voir ni réagir au clic,
        // mais garder sa place — sinon les icônes suivantes (playlist, YouTube,
        // partage...) se décalaient d'une ligne à l'autre selon la présence ou
        // non de paroles pour CE morceau précis.
        btn.style.visibility = 'hidden';
        btn.style.pointerEvents = 'none';
        delete btn.dataset.lyrics;
        delete btn.dataset.synced;
        delete btn.dataset.offset;
        btn.classList.remove('lyrics-synced');
      }
    });
  }

  function pumpLyricsQueue() {
    while (lyricsActiveRequests < LYRICS_MAX_CONCURRENT && lyricsQueue.length > 0) {
      const job = lyricsQueue.shift();
      lyricsActiveRequests++;
      const paramsObj = { artist: job.artist, title: job.title };
      // Durée réelle du fichier : permet au serveur d'écarter une édition
      // lrclib différente (live, radio edit...) qui ne correspondrait pas au
      // minutage du fichier réellement téléchargé (voir ytdl_fetch_lyrics_data).
      if (job.duration) paramsObj.duration = job.duration;
      const params = new URLSearchParams(paramsObj);
      fetch('lyrics.php?' + params.toString())
        .then(r => r.json())
        .then(data => ({
          available: !!(data && data.lyrics),
          lyrics: (data && data.lyrics) || null,
          // Paroles synchronisées (lrclib.net) : tableau [{time, text}, ...]
          // trié par horodatage croissant, ou null si non disponibles pour
          // ce morceau (lyrics.ovh en repli, paroles brutes seules).
          synced: (data && data.synced) || null,
          // Décalage manuel (secondes) réglé pour ce morceau, partagé par
          // tous les comptes — voir lyrics-offset.php. Deux réglages
          // indépendants : le fichier audio et le clip, qui commence souvent
          // par une intro absente de l'audio.
          offset: (data && data.offset) || 0,
          videoOffset: (data && data.video_offset) || 0
        }))
        .catch(() => ({ available: false, lyrics: null, synced: null, offset: 0, videoOffset: 0 }))
        .then(entry => {
          lyricsCache.set(job.key, entry);
          const waiters = lyricsPending.get(job.key);
          lyricsPending.delete(job.key);
          if (waiters) waiters.forEach(cb => cb(entry));
        })
        .finally(() => { lyricsActiveRequests--; pumpLyricsQueue(); });
    }
  }

  // Point d'entrée générique (callback plutôt que bouton) : ne relance jamais
  // de requête pour une clé déjà en cache ou déjà en vol. Utilisé à la fois
  // pour l'icône de paroles d'une ligne et pour l'ouverture automatique de la
  // feuille de paroles au changement de morceau (voir maybeAutoShowLyrics).
  function requestLyrics(artist, title, callback, duration) {
    const key = lyricsCacheKey(artist, title);
    if (lyricsCache.has(key)) { callback(lyricsCache.get(key)); return; }
    if (lyricsPending.has(key)) { lyricsPending.get(key).add(callback); return; }
    lyricsPending.set(key, new Set([callback]));
    lyricsQueue.push({ key, artist, title, duration });
    pumpLyricsQueue();
  }

  // Cherche/affiche la disponibilité des paroles pour une ligne donnée.
  // btns : un bouton ou un tableau (ligne normale + panneau de swipe).
  function queueLyricsCheck(track, btns) {
    const list = Array.isArray(btns) ? btns : [btns];
    const { artist, title } = splitArtistTitle(track);
    // Invisible (visibility) tant que la disponibilité n'est pas confirmée,
    // jamais "display:none" — l'icône garde sa place dans la ligne pour ne
    // pas décaler les suivantes (voir applyLyricsToButton).
    list.forEach(btn => { btn.style.visibility = 'hidden'; btn.style.pointerEvents = 'none'; });
    // "Others" (hors musique — documentaire, tutoriel, vlog...) n'a par
    // nature aucune parole à chercher sur lrclib.net : inutile d'y perdre un
    // appel réseau pour aboutir systématiquement à "rien trouvé".
    if (track.category === 'other') return;
    if (!artist || !title) return;
    requestLyrics(artist, title, entry => applyLyricsToButton(list, entry), track.duration_seconds);
  }

  const lyricsSheet = document.getElementById('lyrics-sheet');
  const lyricsBottomSheet = document.getElementById('lyrics-bottom-sheet');
  const desktopLyricsPanel = document.getElementById('desktop-lyrics-panel');
  const desktopArtistPanel = document.getElementById('desktop-artist-panel');
  // Desktop : "les paroles remplacent la feuille modale" (voir
  // openLyricsSheet/closeLyricsSheet) — même seuil que le reste de l'appli
  // (voir @media max-width:760px, capture.php/music.css).
  const isDesktopLayoutQuery = window.matchMedia('(min-width: 761px)');
  function isDesktopLayout() { return isDesktopLayoutQuery.matches; }
  const lyricsSheetBody = document.getElementById('lyrics-sheet-body');
  const lyricsSheetTitle = document.getElementById('lyrics-sheet-title');
  const lyricsCloseBtn = document.getElementById('lyrics-close-btn');
  const lyricsPrevBtn = document.getElementById('lyrics-prev-btn');
  const lyricsPlayPauseBtn = document.getElementById('lyrics-playpause-btn');
  const lyricsNextBtn = document.getElementById('lyrics-next-btn');
  const lyricsOffsetRow = document.getElementById('lyrics-offset-row');
  const lyricsOffsetValue = document.getElementById('lyrics-offset-value');
  const lyricsOffsetNowBtn = document.getElementById('lyrics-offset-now');

  // Paroles synchronisées (lrclib.net) actuellement affichées, s'il y en a :
  // { lines: [{time, text}, ...], trackId } — comparé à currentTrackId à
  // chaque timeupdate pour ne jamais surligner sur le mauvais morceau après
  // un enchaînement.
  let lyricsSyncData = null;
  let lyricsSyncActiveIndex = -1;

  // Display-only instrumental cues: never persist guessed end times.
  function lyricsWithMusicBreaks(lines) {
    const result = [];
    lines.forEach((line, i) => {
      const text = String(line.text || '').trim();
      if (i === 0 && line.time >= 8) result.push({time: 0, text: '♫', instrumental: true});
      result.push({...line, text: text || '♫', instrumental: !text});
      const next = lines[i + 1];
      if (text && next && next.time - line.time >= 12) {
        result.push({time: line.time + 6, text: '♫', instrumental: true});
      }
    });
    return result;
  }

  function renderSyncedLyrics(lines) {
    lyricsSheetBody.innerHTML = '';
    lines.forEach(line => {
      const div = document.createElement('div');
      div.className = 'lyrics-line';
      // Ligne "vide" (respiration entre deux couplets) : espace insécable
      // plutôt qu'une chaîne vide, pour que la ligne garde sa hauteur.
      div.textContent = line.text || '♫';
      if (line.instrumental) div.setAttribute('aria-label', 'Pause musicale (repère estimé)');
      lyricsSheetBody.appendChild(div);
    });
  }

  // Surligne la ligne correspondant au temps de lecture actuel et la fait
  // défiler en vue — appelée à chaque timeupdate de <audio>, mais ne
  // retouche le DOM que quand la ligne active change réellement.
  // Ouvert soit dans la feuille modale (mobile), soit ancré dans le panneau
  // desktop (#lyrics-bottom-sheet déplacé là, voir openLyricsSheet) — les
  // deux comptent comme "affiché" pour la synchronisation au défilement.
  function isLyricsPanelOpen() {
    return lyricsSheet.classList.contains('visible') || lyricsBottomSheet.parentElement === desktopLyricsPanel;
  }
  // scrollIntoView() sur la ligne active remontait aussi la PAGE entière (et
  // donc la liste de la bibliothèque à gauche, visible derrière) : sur
  // desktop, le panneau paroles vit dans le flux normal de la page
  // (position:sticky, pas une modale plein écran) — scrollIntoView fait
  // défiler TOUS les ancêtres scrollables nécessaires pour centrer la ligne
  // dans le VIEWPORT, pas seulement l'intérieur de la feuille de paroles
  // elle-même. Calcul manuel + scrollTo confiné au SEUL conteneur réellement
  // en overflow à la place — jamais la page.
  //
  // Ce conteneur diffère selon le contexte : sur mobile, #lyrics-bottom-
  // sheet garde sa hauteur fixe (58vh, voir .bottom-sheet) et c'est
  // .lyrics-sheet-body (flex:1;overflow-y:auto dedans) qui défile réellement.
  // Sur desktop, #lyrics-bottom-sheet.desktop-inline passe en height:auto
  // (il grandit avec son contenu, pour un rendu de bloc normal posé dans la
  // page) — plus aucun overflow sur .lyrics-sheet-body dans ce cas, c'est
  // #desktop-lyrics-panel (son max-height/overflow-y:auto, voir plus haut)
  // qui porte le VRAI overflow.
  //
  // Fonction à part (pas juste inline dans updateLyricsSync) : réutilisée
  // par le recentrage automatique après inactivité (voir
  // scheduleLyricsRecenter plus bas), qui doit pouvoir recentrer la ligne
  // active MÊME quand elle n'a pas changé (l'utilisateur a scrollé loin
  // d'elle à la main).
  function centerActiveLyricsLine(behavior) {
    if (lyricsSyncActiveIndex < 0) return;
    const lineEls = lyricsSheetBody.querySelectorAll('.lyrics-line');
    const line = lineEls[lyricsSyncActiveIndex];
    if (!line) return;
    const container = isDesktopLayout() ? desktopLyricsPanel : lyricsSheetBody;
    const lineTop = line.getBoundingClientRect().top - container.getBoundingClientRect().top + container.scrollTop;
    const targetTop = lineTop - (container.clientHeight / 2) + (line.offsetHeight / 2);
    container.scrollTo({ top: Math.max(0, targetTop), behavior: behavior || 'smooth' });
  }
  function updateLyricsSync() {
    if (!lyricsSyncData || !isLyricsPanelOpen()) return;
    // Le panneau partagé affiche indifféremment un morceau audio OU un clip
    // (voir lyricsSyncData.isVideo, posé par openLyricsSheet) : ni le bon
    // identifiant de lecture en cours, ni le bon <video>/<audio> à lire ne
    // sont les mêmes selon le cas.
    const player = lyricsSyncData.isVideo ? videoPlayer : audioPlayer;
    if (lyricsSyncData.isVideo ? lyricsSyncData.trackId !== currentVideoId : lyricsSyncData.trackId !== currentTrackId) return;
    // Décalage manuel (secondes, réglé par le bouton de la feuille) : ajouté
    // au temps de lecture réel, pas aux horodatages des lignes — équivalent,
    // mais évite de reconstruire le tableau à chaque réglage.
    const t = player.currentTime - (lyricsSyncData.offset || 0);
    const lines = lyricsSyncData.lines;
    let idx = -1;
    for (let i = 0; i < lines.length; i++) {
      if (lines[i].time <= t) idx = i; else break;
    }
    if (idx === lyricsSyncActiveIndex) return;
    lyricsSyncActiveIndex = idx;
    const lineEls = lyricsSheetBody.querySelectorAll('.lyrics-line');
    lineEls.forEach((el, i) => el.classList.toggle('active', i === idx));
    centerActiveLyricsLine();
  }
  // Recentrage automatique (desktop uniquement) : si l'utilisateur a scrollé
  // la feuille de paroles à la main (pour relire un couplet passé, par
  // exemple), elle revient toute seule sur la ligne en cours après 5s
  // d'inactivité de la souris — ou immédiatement si la fenêtre perd le
  // focus (changement d'onglet/d'appli). Un simple mousemove global suffit
  // pour la souris (l'appli n'a pas de vrai usage clavier-seul de cette
  // zone) ; wheel/scroll sur le panneau lui-même réarme aussi le délai,
  // sinon un défilement actif à la molette (qui ne déclenche pas
  // mousemove) se ferait interrompre en pleine lecture.
  const LYRICS_RECENTER_IDLE_MS = 5000;
  let lyricsRecenterIdleTimer = null;
  function scheduleLyricsRecenter() {
    if (lyricsRecenterIdleTimer) clearTimeout(lyricsRecenterIdleTimer);
    lyricsRecenterIdleTimer = setTimeout(() => {
      // 'instant', pas 'smooth' : un défilement animé posé pile au moment où
      // la souris se tait (et surtout où la fenêtre perd le focus, juste en
      // dessous) risque de rester à mi-chemin — la plupart des navigateurs
      // suspendent les animations pilotées par requestAnimationFrame (dont
      // le smooth scroll) dès qu'un onglet passe en arrière-plan. Un
      // repositionnement immédiat reste correct qu'on revienne tout de
      // suite ou bien plus tard.
      if (isDesktopLayout() && lyricsSyncData && isLyricsPanelOpen()) centerActiveLyricsLine('instant');
    }, LYRICS_RECENTER_IDLE_MS);
  }
  document.addEventListener('mousemove', scheduleLyricsRecenter);
  desktopLyricsPanel.addEventListener('wheel', scheduleLyricsRecenter, { passive: true });
  desktopLyricsPanel.addEventListener('scroll', scheduleLyricsRecenter, { passive: true });
  scheduleLyricsRecenter();
  window.addEventListener('blur', () => {
    if (isDesktopLayout() && lyricsSyncData && isLyricsPanelOpen()) centerActiveLyricsLine('instant');
  });
  // Sur les DEUX éléments <audio> physiques, pas sur la variable audioPlayer :
  // le fondu enchaîné la réaffecte à l'autre lecteur (audioA <-> audioB) en
  // cours de session, mais un addEventListener posé une fois reste attaché à
  // l'élément d'origine. Sans ça, la synchronisation se figeait dès le
  // premier morceau joué après un fondu — d'où des paroles indiquées
  // "synchronisées" (l'icône ne reflète que la disponibilité en cache) mais
  // qui ne défilaient plus une fois la lecture passée sur l'autre buffer.
  audioA.addEventListener('timeupdate', updateLyricsSync);
  audioB.addEventListener('timeupdate', updateLyricsSync);

  // Retenu (par appareil, via localStorage) pour rouvrir automatiquement la
  // feuille au bon endroit après un rechargement de page ou une reprise —
  // sans ça, une fermeture involontaire du navigateur perdait la lecture des
  // paroles en cours même si la musique, elle, reprenait normalement.
  const LYRICS_OPEN_KEY = 'ytdl_lyrics_open_track';
  function rememberLyricsSheetOpen(trackId) {
    try { localStorage.setItem(LYRICS_OPEN_KEY, String(trackId)); } catch (err) {}
  }
  function forgetLyricsSheetOpen() {
    try { localStorage.removeItem(LYRICS_OPEN_KEY); } catch (err) {}
  }

  // Premier vers réellement chanté du minutage (les LRC commencent parfois
  // par une ligne vide) : c'est lui que le bouton "Les paroles commencent
  // ici" cale sur la position de lecture, et lui dont on affiche l'instant.
  function firstSungLineTime(lines) {
    const first = (lines || []).find(l => !l.instrumental && l.text && l.text.trim() !== '' && !/^[♫♪\s]+$/.test(l.text));
    return first ? first.time : null;
  }
  // Ce qui est affiché n'est PAS le décalage interne (écart ajouté aux
  // horodatages) mais l'instant, dans le fichier, où tombe le premier vers :
  // c'est ce que l'on voit à l'écran et ce que l'on a calé en cliquant.
  function formatLyricsStart(offset, lines) {
    const first = firstSungLineTime(lines);
    if (first === null) return '';
    return 'à ' + (first + (offset || 0)).toFixed(1) + ' s';
  }
  function renderLyricsOffsetValue() {
    lyricsOffsetValue.textContent = lyricsSyncData
      ? formatLyricsStart(lyricsSyncData.offset, lyricsSyncData.lines) : '';
  }

  function openLyricsSheet(track, lyrics, synced, offset, isVideo) {
    if (synced && synced.length) synced = lyricsWithMusicBreaks(synced);
    const parts = splitArtistTitle(track);
    lyricsSheetTitle.textContent = parts.artist ? (parts.artist + ' — ' + parts.title) : parts.title;
    if (synced && synced.length) {
      lyricsSheetBody.classList.add('synced');
      renderSyncedLyrics(synced);
      lyricsSyncData = { lines: synced, trackId: track.id, artist: parts.artist, title: parts.title, offset: offset || 0, isVideo: !!isVideo };
      lyricsSyncActiveIndex = -1;
      lyricsOffsetRow.style.display = 'flex';
      renderLyricsOffsetValue();
      updateLyricsSync();
    } else {
      lyricsSheetBody.classList.remove('synced');
      lyricsSheetBody.textContent = lyrics;
      lyricsSyncData = null;
      lyricsSyncActiveIndex = -1;
      lyricsOffsetRow.style.display = 'none';
    }
    placeLyricsPanel();
    // À l'ouverture, on démarre toujours en haut — même si le morceau est
    // déjà bien avancé et que updateLyricsSync() vient de faire défiler vers
    // la ligne active (scrollIntoView plus haut) : sans ça, l'utilisateur
    // devait remonter lui-même pour relire depuis le début. Le défilement
    // automatique vers la ligne en cours reprend normalement ensuite, au fil
    // de la lecture (timeupdate).
    lyricsSheetBody.scrollTop = 0;
    rememberLyricsSheetOpen(track.id);
    // La fiche artiste n'est plus pilotée d'ici : son cycle de vie est
    // entièrement découplé de l'ouverture/fermeture des paroles (voir
    // playTrack, qui appelle renderDesktopArtistPanel à CHAQUE changement
    // de morceau, paroles affichées ou non) — elle ne doit ni disparaître
    // quand on ferme les paroles, ni attendre qu'on les ouvre pour se
    // mettre à jour.
  }
  // Bascule #lyrics-bottom-sheet entre la feuille modale (mobile) et le
  // panneau desktop, sans jamais dupliquer son contenu — mêmes nœuds, mêmes
  // écouteurs (transport, décalage...), juste déplacés selon la largeur
  // d'écran. Appelée à l'ouverture ET à chaque changement de largeur tant
  // que les paroles sont affichées (voir le listener plus bas), pour rester
  // cohérent si la fenêtre est redimensionnée en cours de lecture.
  function placeLyricsPanel() {
    if (isDesktopLayout()) {
      desktopLyricsPanel.appendChild(lyricsBottomSheet);
      lyricsBottomSheet.classList.add('desktop-inline');
      lyricsSheet.classList.remove('visible');
    } else {
      lyricsSheet.appendChild(lyricsBottomSheet);
      lyricsBottomSheet.classList.remove('desktop-inline');
      lyricsSheet.classList.add('visible');
    }
  }
  isDesktopLayoutQuery.addEventListener('change', () => { if (isLyricsPanelOpen()) placeLyricsPanel(); });

  // Alignement vertical des panneaux latéraux (paroles + fiche artiste) sur
  // le 1er morceau de la liste, PAS sur le haut de .library-tabs : sans ce
  // décalage, les paroles démarraient au niveau de la barre d'onglets/
  // boutons d'action, visuellement plus haut que le 1er titre de la
  // bibliothèque à côté de laquelle elles sont censées se lire. Recalculé
  // via ResizeObserver sur .library-main-col plutôt qu'à chaque appel de
  // rendu/switchTab : sa hauteur change de toute façon dès que la liste se
  // remplit ou qu'on change d'onglet (sections masquées en display:none),
  // un seul point d'observation suffit donc à couvrir tous les cas.
  const librarySidePanels = document.getElementById('library-side-panels');
  const libraryMainCol = document.getElementById('library-main-col');
  function alignDesktopSidePanels() {
    if (!isDesktopLayout()) { librarySidePanels.style.marginTop = ''; return; }
    const activeSection = libraryMainCol.querySelector('.library-section.active-tab') || document.getElementById('library-section');
    // Le 1er morceau lui-même (.library-item/.playlist-item), pas le
    // conteneur de la liste : ce dernier peut porter un padding/une bordure
    // propres qui décalaient l'alignement de quelques pixels. À défaut
    // (bibliothèque vide), on retombe sur le conteneur.
    const target = activeSection && (
      activeSection.querySelector('.library-item, .playlist-item') ||
      activeSection.querySelector('.library-list-row') ||
      activeSection.querySelector('.library-list')
    );
    if (!target) { librarySidePanels.style.marginTop = ''; return; }
    const offset = target.getBoundingClientRect().top - libraryMainCol.getBoundingClientRect().top;
    librarySidePanels.style.marginTop = Math.max(0, Math.round(offset)) + 'px';
  }
  if (window.ResizeObserver) {
    new ResizeObserver(alignDesktopSidePanels).observe(libraryMainCol);
  }
  window.addEventListener('resize', alignDesktopSidePanels);
  isDesktopLayoutQuery.addEventListener('change', alignDesktopSidePanels);
  alignDesktopSidePanels();
  function closeLyricsSheet() {
    lyricsSheet.classList.remove('visible');
    lyricsBottomSheet.classList.remove('desktop-inline');
    if (lyricsBottomSheet.parentElement !== lyricsSheet) lyricsSheet.appendChild(lyricsBottomSheet);
    forgetLyricsSheetOpen();
    miniLyricsBtn.classList.remove('lyrics-active');
    // La fiche artiste NE dépend PAS des paroles : fermer les paroles ne
    // doit jamais la faire disparaître (voir playTrack, qui la pilote
    // désormais seul, à chaque changement de morceau).
  }
  lyricsCloseBtn.addEventListener('click', closeLyricsSheet);
  lyricsSheet.addEventListener('click', e => { if (e.target === lyricsSheet) closeLyricsSheet(); });

  // Fiche artiste (desktop, à droite des paroles — voir #desktop-artist-
  // panel) : un cache mémoire suffit, la page n'est jamais ouverte assez
  // longtemps pour qu'il vaille la peine de le persister ; artist-info.php
  // tient déjà son propre cache disque côté serveur (Wikipédia) pour la
  // vraie économie de requêtes.
  const artistInfoMemoryCache = new Map();
  // Pilotée depuis playTrack (voir plus bas), À CHAQUE changement de
  // morceau — paroles affichées ou non, voir openLyricsSheet/
  // closeLyricsSheet qui n'y touchent plus du tout. Pour un artiste qui a
  // déjà une fiche affichée, on ne replie JAMAIS le panneau en attendant la
  // suivante (juste un fondu via .loading, voir CSS) : la largeur du
  // panneau — et donc celle des paroles à côté — ne bouge pas d'un pixel
  // pendant un simple changement de morceau, seule une vraie apparition ou
  // disparition (fiche trouvée pour la 1ère fois / plus aucune fiche pour
  // personne) anime le repli.
  function renderDesktopArtistPanel(artist, trackTitle) {
    desktopArtistPanelWantedArtist = artist;
    const isNewArtist = artist !== desktopArtistPanelShownArtist;
    if (!artist) { paintDesktopArtistPanel(artist, null); return; }
    const cached = artistInfoMemoryCache.get(artist);
    if (cached) { paintDesktopArtistPanel(artist, cached); return; }
    // Fondu discret pendant le chargement d'un artiste DIFFÉRENT de celui
    // déjà affiché — pas pour une simple ré-application (même artiste deux
    // morceaux de suite, appel redondant possible depuis playTrack).
    if (isNewArtist && desktopArtistPanel.classList.contains('visible')) {
      desktopArtistPanel.classList.add('loading');
    }
    // Le titre du morceau accompagne la requête : sert d'indice côté serveur
    // pour lever les homonymies (voir ytdl_wikipedia_page_is_musical) — un
    // nom d'artiste seul peut désigner tout autre chose qu'un musicien
    // ("The Smiths", "Oasis"...).
    fetch('artist-info.php?artist=' + encodeURIComponent(artist) + '&title=' + encodeURIComponent(trackTitle || ''))
      .then(res => res.json())
      .then(info => {
        artistInfoMemoryCache.set(artist, info);
        // Entre-temps l'utilisateur peut être passé à un autre morceau —
        // ne peint que si la demande correspond toujours à ce qui doit
        // s'afficher (comparé au dernier artiste demandé, pas à l'ancien
        // contenu déjà présent dans le panneau).
        if (desktopArtistPanelWantedArtist === artist) paintDesktopArtistPanel(artist, info);
      })
      .catch(() => {
        if (desktopArtistPanelWantedArtist === artist) desktopArtistPanel.classList.remove('loading');
      });
  }
  let desktopArtistPanelWantedArtist = null;
  // Artiste dont la fiche est ACTUELLEMENT peinte dans le panneau (pas
  // forcément celui demandé en dernier, voir desktopArtistPanelWantedArtist
  // ci-dessus).
  let desktopArtistPanelShownArtist = null;
  // Construit le panneau via le DOM (createElement/textContent), jamais via
  // de la concaténation HTML : thumbnail/URL/extrait viennent de Wikipédia,
  // donc externes et non maîtrisés — aucune raison de leur faire courir le
  // moindre risque d'injection, même minime.
  //
  // L'apparition/disparition passe par .visible (voir CSS, transition sur
  // flex-basis) plutôt que par un simple ajout/retrait de contenu : ainsi,
  // quand une fiche est trouvée, les paroles juste à côté (flex:1 1 360px)
  // se voient réduites EN DOUCEUR au même rythme, au lieu que le bloc
  // artiste surgisse d'un coup à sa largeur finale.
  function paintDesktopArtistPanel(artist, info) {
    desktopArtistPanel.classList.remove('loading');
    if (!info || !info.extract) {
      desktopArtistPanelShownArtist = null;
      desktopArtistPanel.classList.remove('visible');
      // Laisse le repli (transition CSS) jouer avant de vider le contenu —
      // sinon plus rien à voir dans un bloc qui n'a pourtant pas fini de se
      // rétracter.
      setTimeout(() => {
        if (!desktopArtistPanel.classList.contains('visible')) desktopArtistPanel.innerHTML = '';
      }, 450);
      return;
    }
    desktopArtistPanelShownArtist = artist;
    const inner = document.createElement('div');
    inner.className = 'desktop-artist-panel-inner';
    if (info.thumbnail) {
      const img = document.createElement('img');
      img.className = 'desktop-artist-thumb';
      img.src = info.thumbnail;
      img.alt = '';
      inner.appendChild(img);
    }
    const name = document.createElement('div');
    name.className = 'desktop-artist-name';
    name.textContent = info.wiki_title || artist;
    inner.appendChild(name);
    const extract = document.createElement('div');
    extract.className = 'desktop-artist-extract';
    extract.textContent = info.extract;
    inner.appendChild(extract);
    if (info.wiki_url) {
      const link = document.createElement('a');
      link.className = 'desktop-artist-link';
      link.href = info.wiki_url;
      link.target = '_blank';
      link.rel = 'noopener';
      link.textContent = 'Voir sur Wikipédia';
      inner.appendChild(link);
    } else if (info.has_more) {
      inner.appendChild(archivedBioLink('desktop-artist-link', artist, extract));
    }
    desktopArtistPanel.innerHTML = '';
    desktopArtistPanel.appendChild(inner);
    desktopArtistPanel.classList.add('visible');
  }

  // Transport : mêmes fonctions partagées que le mini-lecteur (déclarées plus
  // bas dans le script, disponibles ici grâce au hoisting des déclarations
  // de fonction) — un changement de morceau depuis la feuille se comporte
  // donc exactement comme depuis le mini-lecteur (reprise au début après 3s,
  // playlist en cours respectée, etc.).
  lyricsPrevBtn.addEventListener('click', () => { requestImmediateFocus(); goToPreviousTrack(); });
  lyricsNextBtn.addEventListener('click', () => { requestImmediateFocus(); goToNextTrack(); });
  lyricsPlayPauseBtn.addEventListener('click', () => togglePlayPause());

  // Applique un décalage : effet visible tout de suite dans la feuille (pas
  // d'attente réseau), et sauvegardé pour tous les comptes sur ce morceau
  // une fois la requête aboutie.
  function setLyricsOffset(seconds) {
    if (!lyricsSyncData || !Number.isFinite(seconds)) return;
    if (lyricsSyncData.isVideo) { setVideoLyricsOffset(seconds); return; }
    seconds = Math.max(-180, Math.min(180, Math.round(seconds * 100) / 100));
    lyricsSyncData.offset = seconds;
    lyricsSyncActiveIndex = -1; // force le recalcul même si l'index tombe pareil
    renderLyricsOffsetValue();
    updateLyricsSync();
    // Le réglage était bien pris en compte en BDD mais pas à l'écran : dès
    // qu'un affichage AUTOMATIQUE de la feuille survenait ensuite (nouveau
    // morceau, reprise après mise en veille sur mobile — bien plus fréquent
    // que sur desktop), maybeAutoShowLyrics()/openLyricsSheet() repartaient
    // de l'ancienne valeur via requestLyrics(), qui sert directement
    // lyricsCache SANS re-solliciter le serveur tant que la clé y est déjà
    // — écrasant silencieusement le nouveau décalage par l'ancien. Corrigé
    // ici même, à la source du cache, plutôt qu'en re-fetchant à chaque
    // fois (lyricsSyncData reste la valeur affichée tout de suite, ce
    // correctif ne fait que garantir que les prochaines lectures du cache,
    // elles, restent cohérentes avec elle).
    const cacheKey = lyricsCacheKey(lyricsSyncData.artist, lyricsSyncData.title);
    const cachedEntry = lyricsCache.get(cacheKey);
    if (cachedEntry) cachedEntry.offset = seconds;
    if (miniLyricsEntry && miniLyricsTrack && miniLyricsTrack.id === currentTrackId) {
      miniLyricsEntry.offset = seconds;
    }
    // Bouton "paroles" de la ligne bibliothèque du morceau en cours, s'il
    // est déjà rendu à l'écran — même souci que ci-dessus si on referme
    // puis rouvre les paroles depuis cette icône précise avant le prochain
    // rafraîchissement périodique de la bibliothèque.
    document.querySelectorAll('.library-item[data-track-id="' + currentTrackId + '"] .library-lyrics-btn')
      .forEach(btn => { btn.dataset.offset = seconds; });
    // "video" pour un clip affiché dans le panneau partagé (voir
    // lyricsSyncData.isVideo) : décalage indépendant de celui du fichier
    // audio, une intro de clip n'a souvent pas d'équivalent dans le MP3.
    fetch('lyrics-offset.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        artist: lyricsSyncData.artist, title: lyricsSyncData.title, offset: lyricsSyncData.offset,
        variant: lyricsSyncData.isVideo ? 'video' : 'audio',
      })
    }).catch(() => {});
  }
  // "Les paroles commencent ici" : décalage = position de lecture −
  // horodatage du premier vers non vide.
  lyricsOffsetNowBtn.addEventListener('click', () => {
    if (!lyricsSyncData || !lyricsSyncData.lines) return;
    const first = firstSungLineTime(lyricsSyncData.lines);
    if (first === null) return;
    // Arrondi au centième : l'instant affiché (premier vers + décalage)
    // reste alors juste au dixième près, sans dérive due à l'arrondi.
    const player = lyricsSyncData.isVideo ? videoPlayer : audioPlayer;
    setLyricsOffset((player.currentTime - first));
  });

  // Si la feuille de paroles est déjà ouverte quand un nouveau morceau se
  // lance (enchaînement automatique, changement manuel...), on la met à jour
  // toute seule dès que les paroles de ce morceau sont disponibles — plutôt
  // que de laisser affichées celles du morceau précédent.
  function maybeAutoShowLyrics(track) {
    // isLyricsPanelOpen() (pas juste lyricsSheet.classList.contains
    // ('visible')) : sur desktop, les paroles ouvertes vivent dans le
    // panneau à droite de la bibliothèque, sans jamais poser 'visible' sur
    // #lyrics-sheet (voir placeLyricsPanel) — se fier à la seule classe
    // ratait donc systématiquement la mise à jour au changement de morceau
    // en mode PC.
    if (!isLyricsPanelOpen()) return;
    const { artist, title } = splitArtistTitle(track);
    // Sur desktop, un morceau qu'on ne peut même pas interroger (pas de
    // couple artiste/titre exploitable) n'aura jamais de paroles : autant
    // fermer le panneau tout de suite plutôt que de laisser affichées
    // celles du morceau précédent. Sur mobile, comportement inchangé
    // (demande explicitement limitée au mode PC).
    if (!artist || !title) {
      if (isDesktopLayout()) closeLyricsSheet();
      return;
    }
    requestLyrics(artist, title, entry => {
      // Le morceau a pu changer entre-temps (skip rapide) : n'agit que si
      // c'est toujours celui qui joue.
      if (track.id !== currentTrackId) return;
      if (entry && entry.available) {
        openLyricsSheet(track, entry.lyrics, entry.synced, entry.offset);
      } else if (isDesktopLayout()) {
        // Pas de paroles pour ce morceau : sur desktop, le panneau doit
        // disparaître plutôt que de garder celles du morceau précédent
        // affichées comme si elles étaient toujours d'actualité.
        closeLyricsSheet();
      }
    }, track.duration_seconds);
  }

  // Icône paroles du mini-lecteur : même disponibilité/couleur que celle de
  // la ligne dans la bibliothèque, mais toujours visible pendant l'écoute —
  // pas besoin de retrouver la ligne dans la liste pour y accéder. Stocke
  // les données pour le clic (pas de re-fetch) via un état module-scope,
  // remis à zéro à chaque nouveau morceau.
  let miniLyricsEntry = null;
  let miniLyricsTrack = null;
  function setupMiniPlayerLyrics(track) {
    miniLyricsEntry = null;
    miniLyricsTrack = track;
    miniLyricsBtn.style.display = 'none';
    miniLyricsBtn.classList.remove('lyrics-synced', 'lyrics-active');
    const { artist, title } = splitArtistTitle(track);
    if (!artist || !title) return;
    requestLyrics(artist, title, entry => {
      if (track.id !== currentTrackId) return; // le morceau a pu changer entre-temps
      if (entry && entry.available) {
        miniLyricsEntry = entry;
        miniLyricsBtn.style.display = '';
        miniLyricsBtn.classList.toggle('lyrics-synced', !!entry.synced);
        miniLyricsBtn.title = entry.synced ? 'Voir les paroles synchro' : 'Voir les paroles';
      }
    }, track.duration_seconds);
  }
  miniLyricsBtn.addEventListener('click', () => {
    if (!miniLyricsEntry || !miniLyricsTrack) return;
    // isLyricsPanelOpen() (pas juste lyricsSheet.classList.contains
    // ('visible')) : sur desktop, le panneau ouvert ne pose jamais cette
    // classe (voir placeLyricsPanel) — s'y fier faisait croire que les
    // paroles étaient toujours fermées, et un second clic les rouvrait au
    // lieu de les refermer.
    const opening = !isLyricsPanelOpen();
    if (opening) {
      openLyricsSheet(miniLyricsTrack, miniLyricsEntry.lyrics, miniLyricsEntry.synced, miniLyricsEntry.offset);
      miniLyricsBtn.classList.add('lyrics-active');
    } else {
      closeLyricsSheet();
      miniLyricsBtn.classList.remove('lyrics-active');
    }
  });

  // Reprise après rechargement de page (voir checkPlaybackResume) : la
  // feuille de paroles démarre forcément fermée (nouveau DOM), contrairement
  // à un enchaînement en cours de session où elle peut déjà être ouverte. On
  // se base donc sur ce qui a été mémorisé juste avant la fermeture de
  // l'onglet pour la rouvrir toute seule, à la bonne ligne — openLyricsSheet
  // resynchronise déjà sur audioPlayer.currentTime au moment de l'appel.
  function maybeReopenLyricsSheetOnResume(track) {
    let savedId = null;
    try { savedId = localStorage.getItem(LYRICS_OPEN_KEY); } catch (err) {}
    if (savedId === null || Number(savedId) !== track.id) return;
    const { artist, title } = splitArtistTitle(track);
    if (!artist || !title) return;
    requestLyrics(artist, title, entry => {
      if (entry && entry.available && track.id === currentTrackId) {
        openLyricsSheet(track, entry.lyrics, entry.synced, entry.offset);
      }
    }, track.duration_seconds);
  }

  function renderLibrary(tracks) {
    currentAudioTracks = tracks;
    libList.innerHTML = '';
    if (tracks.length === 0) {
      libList.innerHTML = '<div class="library-empty">Aucun morceau audio pour l\'instant.</div>';
      return;
    }

    tracks.forEach(track => {
      // <div role="button">, pas <button> : un bouton de téléchargement
      // (élément interactif) doit pouvoir se nicher dedans, ce qu'un <button>
      // n'autorise pas pour un enfant interactif.
      const isShared = !!track._shared;
      const item = document.createElement('div');
      item.className = 'library-item';
      item.setAttribute('role', 'button');
      item.tabIndex = 0;
      item.dataset.trackId = track.id;
      item.dataset.azKey = libraryAzKey(trackArtistKey(track));
      if (track.id === currentTrackId) item.classList.add('playing');
      item.innerHTML =
        '<div class="library-thumb-wrap">' +
          '<img class="library-thumb" src="' + (track.thumbnail || '') + '" alt="">' +
          '<span class="library-play-icon">' + (track.id === currentTrackId && !audioPlayer.paused ? '❚❚' : '▶') + '</span>' +
        '</div>' +
        '<div class="library-body">' +
          '<div class="library-info">' +
            '<div class="library-title-row">' +
              '<div class="library-item-title"><span class="library-item-artist"></span><span class="library-item-title-sep"></span><span class="library-item-title-text"></span></div>' +
              (IS_ADMIN ? '<button type="button" class="library-mobile-rename-btn" aria-label="Renommer" data-hint="Renommer">' + RENAME_ICON_SVG + '</button>' : '') +
            '</div>' +
            (isShared ? '<div class="library-shared-by"></div>' : '') +
          '</div>' +
          '<div class="library-actions">' + libraryActionsHtml(track, isShared) + '</div>' +
        '</div>';
      renderLibraryItemTitle(item, track);
      if (isShared) {
        item.querySelector('.library-shared-by').textContent =
          'Partagé par ' + (track.shared_by_name || '?');
      }
      bindLibraryActionButtons(item, track, isShared);
      // Lancer un morceau depuis la bibliothèque quitte la playlist en cours :
      // on revient à la navigation dans la bibliothèque complète.
      item.addEventListener('click', () => { exitPlaylistContext(); playTrack(track); });
      libList.appendChild(item);
    });
  }

  // --- Recherche dans une liste ---
  // Insensible aux accents (même technique que libraryAzKey() : décomposition
  // NFD puis suppression des diacritiques combinants) — sans ça, chercher
  // "francoise hardy" sans cédille/accent ne trouvait jamais "Françoise
  // Hardy", ce qui est pourtant la façon la plus naturelle de taper,
  // surtout au clavier tactile.
  function normalizeSearchText(str) {
    return Array.from((str || '').normalize('NFD'))
      .filter(ch => { const code = ch.codePointAt(0); return code < 0x0300 || code > 0x036f; })
      .join('').toLowerCase();
  }

  // Filtre par classe (flou + fondu animés, voir .search-hidden) plutôt que
  // display:none instantané, et sans toucher au tableau de données sous-
  // jacent : currentAudioTracks (utilisé par l'enchaînement auto/aléatoire)
  // doit continuer à porter sur TOUS les morceaux, pas seulement ceux qui
  // matchent la recherche en cours.
  function applyLibraryFilter(listEl, query) {
    const q = normalizeSearchText(query.trim());
    listEl.querySelectorAll('.library-item').forEach(item => {
      const titleEl = item.querySelector('.library-item-title');
      const title = titleEl ? normalizeSearchText(titleEl.textContent) : '';
      item.classList.toggle('search-hidden', !(q === '' || title.includes(q)));
    });
  }

  // --- Recherche élargie à l'autre type (audio <-> clips) ---
  // Un morceau téléchargé seulement en clip (jamais en mp3), ou l'inverse,
  // restait invisible depuis l'onglet où il n'existe pas, alors qu'il
  // correspond bien à ce qui est tapé. Les résultats "de l'autre type" sont
  // donc ajoutés à la liste de l'onglet ouvert, avec une pastille dédiée
  // (AUDIO_TYPE_ICON_SVG / CLIP_TYPE_ICON_SVG) pour ne jamais les confondre
  // avec le contenu natif — cliquer dessus lance directement le bon lecteur
  // (playTrack / playVideo), sans changer d'onglet. Reconstruits à chaque
  // frappe (et à chaque rafraîchissement périodique, voir fetchLibrary) via
  // la classe .cross-type-item plutôt que réutilisés, plus simple qu'un vrai
  // diff pour une liste de recherche qui reste courte.
  function buildCrossTypeItem(track) {
    // Dérivé du morceau lui-même plutôt que reçu en argument : depuis que la
    // recherche croisée peut aussi remonter des morceaux "Others" (mélange
    // audio+vidéo, voir applyCrossTypeSearch), un seul indicateur pour tout
    // le lot ne conviendrait plus — chaque morceau connaît déjà son propre
    // type.
    const isVideo = !track.audio_only;
    const item = document.createElement('div');
    const isShared = !!track._shared;
    // Mêmes actions (paroles, playlist, source YouTube, téléchargement,
    // partage, suppression) qu'un item natif — sans elles, la pastille de
    // type se retrouvait seule sur la ligne, ce qui la faisait ressortir
    // comme un élément à part plutôt qu'un résultat de bibliothèque comme
    // les autres. Elles fonctionnent à l'identique ici : toutes ces actions
    // se basent sur l'id du morceau, jamais sur l'onglet depuis lequel on y
    // accède.
    item.className = 'library-item cross-type-item';
    item.setAttribute('role', 'button');
    item.tabIndex = 0;
    item.dataset.trackId = track.id;
    const isCurrent = isVideo ? track.id === currentVideoId : track.id === currentTrackId;
    if (isCurrent) item.classList.add('playing');
    const isPlayingGlyph = isVideo ? isCurrent : (isCurrent && !audioPlayer.paused);
    item.innerHTML =
      '<div class="library-thumb-wrap">' +
        '<img class="library-thumb" src="' + (track.thumbnail || '') + '" alt="">' +
        '<span class="library-play-icon">' + (isPlayingGlyph ? '❚❚' : '▶') + '</span>' +
      '</div>' +
      '<div class="library-body">' +
        '<div class="library-info">' +
          '<div class="library-cross-type-row">' +
            '<div class="library-title-row">' +
              '<div class="library-item-title"><span class="library-item-artist"></span><span class="library-item-title-sep"></span><span class="library-item-title-text"></span></div>' +
              (IS_ADMIN ? '<button type="button" class="library-mobile-rename-btn" aria-label="Renommer" data-hint="Renommer">' + RENAME_ICON_SVG + '</button>' : '') +
            '</div>' +
            '<span class="library-type-badge" title="' + (isVideo ? 'Clip vidéo' : 'Morceau audio') + '">' +
              (isVideo ? CLIP_TYPE_ICON_SVG : AUDIO_TYPE_ICON_SVG) +
            '</span>' +
          '</div>' +
          (isShared ? '<div class="library-shared-by"></div>' : '') +
        '</div>' +
        '<div class="library-actions">' + libraryActionsHtml(track, isShared) + '</div>' +
      '</div>';
    renderLibraryItemTitle(item, track);
    if (isShared) {
      item.querySelector('.library-shared-by').textContent =
        'Partagé par ' + (track.shared_by_name || '?');
    }
    bindLibraryActionButtons(item, track, isShared);
    // clearPlaylistPlaybackContext(), pas exitPlaylistContext() : ce morceau
    // vient d'un résultat de recherche (croisée ou globale), potentiellement
    // trouvé dans une autre playlist ou chez un autre compte (partage) — le
    // simple fait de le trouver/lancer ne doit pas faire disparaître la
    // playlist qu'on est en train de regarder au profit de la bibliothèque.
    item.addEventListener('click', () => {
      clearPlaylistPlaybackContext();
      if (isVideo) playVideo(track); else playTrack(track);
    });
    return item;
  }

  function applyCrossTypeSearch(listEl, query, getSourceTracks) {
    listEl.querySelectorAll('.cross-type-item').forEach(el => el.remove());
    const q = normalizeSearchText(query.trim());
    if (q === '') return;
    getSourceTracks().forEach(track => {
      const rowParts = splitArtistTitle(track);
      const title = normalizeSearchText(rowParts.artist ? (rowParts.artist + ' — ' + rowParts.title) : (track.title || ''));
      if (title.includes(q)) listEl.appendChild(buildCrossTypeItem(track));
    });
  }

  // --- Recherche YouTube de secours quand la bibliothèque locale ne trouve
  // rien pour la requête en cours. "En parallèle" de la recherche locale
  // (instantanée, côté client) : déclenchée dès qu'il ne reste plus aucune
  // correspondance visible, avec un léger anti-rebond pour ne pas relancer
  // yt-dlp à chaque frappe. Une Map plutôt qu'une seule variable globale :
  // les listes audio et vidéo cherchent chacune de leur côté, en parallèle
  // l'une de l'autre aussi.
  const youtubeSuggestState = new Map(); // élément conteneur -> { timer, controller, lastQuery }

  // --- Recherche dans les bibliothèques des AUTRES comptes, avant même de
  // proposer une recherche YouTube (voir library-find-elsewhere.php) : évite
  // un nouveau téléchargement complet quand le fichier existe déjà quelque
  // part sur le serveur.
  const elsewhereState = new Map(); // élément conteneur -> AbortController
  function clearElsewhereSuggestions(elsewhereEl) {
    const controller = elsewhereState.get(elsewhereEl);
    if (controller) controller.abort();
    elsewhereState.delete(elsewhereEl);
    elsewhereEl.innerHTML = '';
    elsewhereEl.classList.remove('visible');
  }
  async function fetchElsewhereSuggestions(elsewhereEl, query, audioOnly) {
    const prev = elsewhereState.get(elsewhereEl);
    if (prev) prev.abort();
    const controller = new AbortController();
    elsewhereState.set(elsewhereEl, controller);
    try {
      const params = new URLSearchParams({ q: query, audio_only: audioOnly ? '1' : '0' });
      const res = await fetch('library-find-elsewhere.php?' + params.toString(), { signal: controller.signal });
      const data = await res.json();
      const results = data.results || [];
      if (results.length === 0) { clearElsewhereSuggestions(elsewhereEl); return; }
      renderElsewhereSuggestions(elsewhereEl, results);
    } catch (err) { /* silencieux : la recherche YouTube reste le repli normal */ }
  }
  function renderElsewhereSuggestions(elsewhereEl, results) {
    elsewhereEl.innerHTML =
      '<div class="yt-elsewhere-header">Déjà sur le serveur :</div><div class="yt-elsewhere-list"></div>';
    elsewhereEl.classList.add('visible');
    const list = elsewhereEl.querySelector('.yt-elsewhere-list');
    results.forEach(r => {
      const item = document.createElement('div');
      item.className = 'yt-elsewhere-item';
      item.innerHTML =
        '<img class="yt-elsewhere-thumb" src="" alt="" loading="lazy">' +
        '<div class="yt-elsewhere-info">' +
          '<div class="yt-elsewhere-title"></div>' +
          '<div class="yt-elsewhere-meta"></div>' +
        '</div>' +
        '<button type="button" class="yt-elsewhere-add-btn">Ajouter</button>';
      item.querySelector('.yt-elsewhere-thumb').src = r.thumbnail || '';
      item.querySelector('.yt-elsewhere-title').textContent = r.title || '(sans titre)';
      item.querySelector('.yt-elsewhere-meta').textContent = 'Chez ' + (r.owner_name || '?');
      const addBtn = item.querySelector('.yt-elsewhere-add-btn');
      addBtn.addEventListener('click', async e => {
        e.stopPropagation();
        addBtn.disabled = true;
        addBtn.textContent = 'Ajout…';
        try {
          const res = await fetch('library-borrow.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: r.id })
          });
          const data = await res.json().catch(() => ({}));
          if (!res.ok) throw new Error(data.error || "Impossible d'ajouter ce fichier.");
          showToast('« ' + (r.title || 'Morceau') + ' » ajouté à ta bibliothèque.', 'success');
          item.remove();
          if (!list.children.length) elsewhereEl.classList.remove('visible');
          fetchLibrary();
        } catch (err) {
          addBtn.disabled = false;
          addBtn.textContent = 'Ajouter';
          showToast(err.message, 'error');
        }
      });
      list.appendChild(item);
    });
  }

  function clearYoutubeSuggestions(suggestEl) {
    const state = youtubeSuggestState.get(suggestEl);
    if (state) {
      clearTimeout(state.timer);
      if (state.controller) state.controller.abort();
      youtubeSuggestState.delete(suggestEl);
    }
    suggestEl.innerHTML = '';
    suggestEl.classList.remove('visible');
  }

  function scheduleYoutubeSearch(suggestEl, query) {
    let state = youtubeSuggestState.get(suggestEl);
    if (state) {
      clearTimeout(state.timer);
      if (state.controller) state.controller.abort();
    } else {
      state = {};
      youtubeSuggestState.set(suggestEl, state);
    }
    state.lastQuery = query;

    suggestEl.classList.add('visible');
    // Le constat d'absence s'affiche tout de suite (on le sait déjà : c'est
    // justement pourquoi cette recherche se déclenche) — pas la peine
    // d'attendre la réponse de YouTube pour l'annoncer.
    suggestEl.innerHTML = '<div class="yt-suggest-notfound"></div><div class="yt-suggest-loading">Recherche sur YouTube…</div>';
    suggestEl.querySelector('.yt-suggest-notfound').textContent =
      'Aucun résultat pour « ' + query + ' » dans ta bibliothèque.';

    state.timer = setTimeout(async () => {
      const controller = new AbortController();
      state.controller = controller;
      try {
        const res = await fetch('youtube-search.php?q=' + encodeURIComponent(query) + '&limit=6', { signal: controller.signal });
        const data = await res.json();
        // La requête la plus récente pour ce champ gagne toujours : une frappe
        // plus tardive a pu déclencher une autre recherche entre-temps.
        if (state.lastQuery !== query) return;
        const results = data.results || [];
        // Le focus reste dans le champ : le retirer ici fermait le clavier à
        // chaque nouveau résultat, y compris pendant que l'utilisateur tapait
        // encore — impossible de finir sa recherche sans se faire couper.
        renderYoutubeSuggestions(suggestEl, query, results, data.error || null);
      } catch (err) {
        if (err.name === 'AbortError') return;
        if (state.lastQuery === query) renderYoutubeSuggestions(suggestEl, query, [], 'Recherche YouTube indisponible.');
      }
    }, 450);
  }

  // --- Pré-écoute (30 s) des résultats YouTube ---
  // Un seul lecteur pour tous les aperçus : jamais deux en même temps, et un
  // nouvel aperçu coupe aussi ce qui jouait déjà ailleurs dans l'app (morceau
  // ou clip en cours) pour éviter toute superposition de sons.
  const ytPreviewAudio = new Audio();
  let ytPreviewActiveBtn = null;
  let ytPreviewStopTimer = null;

  function stopYoutubePreview() {
    clearTimeout(ytPreviewStopTimer);
    ytPreviewAudio.pause();
    ytPreviewAudio.currentTime = 0;
    if (ytPreviewActiveBtn) {
      ytPreviewActiveBtn.classList.remove('playing');
      ytPreviewActiveBtn.textContent = '▶';
    }
    ytPreviewActiveBtn = null;
  }
  ytPreviewAudio.addEventListener('ended', stopYoutubePreview);

  // Symétrique du commentaire ci-dessus : démarrer un VRAI morceau/clip
  // pendant qu'un aperçu (30s) joue encore ne le coupait pas — l'aperçu
  // continuait jusqu'à son terme par-dessus la lecture qui vient de
  // démarrer. Coupé ici en fondu si le fondu enchaîné est activé (comme une
  // vraie transition), sinon net — jamais les deux en même temps.
  // scheduleVolumeRamp() retombe automatiquement sur une rampe par palier
  // (pas de nœud Web Audio pour ytPreviewAudio, jamais inscrit dans
  // gainNodes) : pas besoin de dupliquer cette logique ici.
  function stopYoutubePreviewForPlayback() {
    if (ytPreviewAudio.paused) return;
    clearTimeout(ytPreviewStopTimer);
    if (ytPreviewActiveBtn) {
      ytPreviewActiveBtn.classList.remove('playing');
      ytPreviewActiveBtn.textContent = '▶';
    }
    ytPreviewActiveBtn = null;
    if (crossfadeEnabled) {
      const fadeMs = 500;
      scheduleVolumeRamp(ytPreviewAudio, 0, fadeMs);
      setTimeout(() => {
        ytPreviewAudio.pause();
        ytPreviewAudio.currentTime = 0;
        ytPreviewAudio.volume = 1; // remis à plein pour le prochain aperçu
      }, fadeMs);
    } else {
      ytPreviewAudio.pause();
      ytPreviewAudio.currentTime = 0;
    }
  }

  function toggleYoutubePreview(btn, videoId) {
    // Reclic sur le bouton déjà actif (en lecture ou en chargement) : on
    // arrête simplement, sans relancer une requête.
    if (ytPreviewActiveBtn === btn) {
      stopYoutubePreview();
      return;
    }

    stopYoutubePreview();
    if (!audioPlayer.paused) audioPlayer.pause();
    if (!videoPlayer.paused) videoPlayer.pause();

    ytPreviewActiveBtn = btn;
    btn.textContent = '';
    const spinner = document.createElement('span');
    spinner.className = 'yt-suggest-preview-spinner';
    btn.appendChild(spinner);

    ytPreviewAudio.src = 'preview.php?id=' + encodeURIComponent(videoId);
    ytPreviewAudio.currentTime = 0;
    ytPreviewAudio.play().then(() => {
      if (ytPreviewActiveBtn !== btn) return; // un autre aperçu a pris le relais entre-temps
      btn.textContent = '❚❚';
      btn.classList.add('playing');
      // Garde-fou : au cas où la découpe côté serveur dépasserait légèrement
      // 30 s, on coupe nous-mêmes plutôt que de laisser l'extrait filer.
      ytPreviewStopTimer = setTimeout(stopYoutubePreview, 30000);
    }).catch(() => {
      if (ytPreviewActiveBtn === btn) stopYoutubePreview();
    });
  }

  function renderYoutubeSuggestions(suggestEl, query, results, errorMsg) {
    // Le constat ("rien dans ta bibliothèque") est toujours affiché, même si
    // la recherche YouTube elle-même échoue ou ne trouve rien : c'est
    // l'information la plus importante, elle ne doit pas dépendre du réseau.
    // Texte posé via textContent (pas d'échappement HTML manuel à
    // maintenir) plutôt qu'injecté tel quel dans le innerHTML ci-dessous.
    let html = '<div class="yt-suggest-notfound"></div>';

    if (results.length === 0) {
      html += '<div class="yt-suggest-empty">' +
        (errorMsg || 'Aucun résultat YouTube pour cette recherche.') + '</div>';
      suggestEl.innerHTML = html;
      suggestEl.querySelector('.yt-suggest-notfound').textContent =
        'Aucun résultat pour « ' + query + ' » dans ta bibliothèque.';
      return;
    }
    html +=
      '<div class="yt-suggest-header">Depuis YouTube :</div>' +
      '<div class="yt-suggest-list"></div>';
    suggestEl.innerHTML = html;
    suggestEl.querySelector('.yt-suggest-notfound').textContent =
      'Aucun résultat pour « ' + query + ' » dans ta bibliothèque.';
    const list = suggestEl.querySelector('.yt-suggest-list');
    results.forEach(r => {
      const item = document.createElement('div');
      item.className = 'yt-suggest-item';
      item.innerHTML =
        '<img class="yt-suggest-thumb" src="" alt="" loading="lazy">' +
        '<div class="yt-suggest-info">' +
          '<div class="yt-suggest-title"></div>' +
          '<div class="yt-suggest-meta"></div>' +
          '<div class="yt-suggest-progress">' +
            '<div class="yt-suggest-progress-label"></div>' +
            '<div class="yt-suggest-progress-track"><div class="yt-suggest-progress-fill"></div></div>' +
          '</div>' +
        '</div>' +
        '<button type="button" class="yt-suggest-preview-btn" title="Écouter un aperçu (30 s)">▶</button>' +
        '<button type="button" class="yt-suggest-dl-btn yt-suggest-dl-mp3" title="Télécharger en MP3">MP3</button>' +
        '<button type="button" class="yt-suggest-dl-btn yt-suggest-dl-clip" title="Télécharger en clip vidéo">Clip</button>' +
        '<div class="yt-suggest-other-wrap">' +
          '<button type="button" class="yt-suggest-other-btn" title="Hors musique (podcast, documentaire, tutoriel…)">⋯</button>' +
          '<div class="yt-suggest-other-menu">' +
            '<button type="button" data-other-kind="podcast" title="Podcast (audio)">Pod</button>' +
            '<button type="button" data-other-kind="documentaire" title="Documentaire">Docu</button>' +
            '<button type="button" data-other-kind="tutoriel" title="Tutoriel">Tuto</button>' +
          '</div>' +
        '</div>';
      item.querySelector('.yt-suggest-thumb').src = r.thumbnail || '';
      item.querySelector('.yt-suggest-title').textContent = r.title || '(sans titre)';
      item.querySelector('.yt-suggest-meta').textContent = [r.uploader, r.duration].filter(Boolean).join(' · ');
      item.querySelector('.yt-suggest-preview-btn').addEventListener('click', e => {
        e.stopPropagation();
        toggleYoutubePreview(e.currentTarget, r.id);
      });
      item.querySelector('.yt-suggest-dl-mp3').addEventListener('click', e => {
        e.stopPropagation();
        startDirectDownload(item, suggestEl, r.url, true);
      });
      item.querySelector('.yt-suggest-dl-clip').addEventListener('click', e => {
        e.stopPropagation();
        startDirectDownload(item, suggestEl, r.url, false);
      });
      // Menu "…" : hors musique — 3 choix qui déterminent audio/vidéo ET le
      // préfixe de titre (voir startDownload/start.php). "podcast" reste sans
      // préfixe (juste demandé "audio non musical", pas de mention au
      // début du titre, contrairement aux deux autres).
      const otherWrap = item.querySelector('.yt-suggest-other-wrap');
      const otherBtn = item.querySelector('.yt-suggest-other-btn');
      const otherMenu = item.querySelector('.yt-suggest-other-menu');
      otherBtn.addEventListener('click', e => {
        e.stopPropagation();
        const willOpen = !otherWrap.classList.contains('open');
        closeAllYoutubeOtherMenus(suggestEl);
        if (willOpen) {
          otherWrap.classList.add('open');
          // Par sécurité, un seul menu "…" ouvert à la fois : les boutons
          // "…" des autres lignes deviennent non cliquables pendant que
          // celui-ci l'est (ré-activés à la fermeture, voir
          // closeAllYoutubeOtherMenus et le handler de clic ci-dessous).
          suggestEl.querySelectorAll('.yt-suggest-other-btn').forEach(b => {
            if (b !== otherBtn) b.disabled = true;
          });
        }
      });
      otherMenu.querySelectorAll('button[data-other-kind]').forEach(btn => {
        btn.addEventListener('click', e => {
          e.stopPropagation();
          closeAllYoutubeOtherMenus(suggestEl);
          const kind = btn.dataset.otherKind;
          const audioOnlyOther = kind === 'podcast';
          const titlePrefix = kind === 'documentaire' ? '[Docu] ' : kind === 'tutoriel' ? '[Tuto] ' : '';
          startDirectDownload(item, suggestEl, r.url, audioOnlyOther, 'other', titlePrefix);
        });
      });
      list.appendChild(item);
    });
  }

  // Ouvre la feuille de téléchargement classique et y prépare une ligne déjà
  // remplie avec l'URL choisie — le même chemin que si l'utilisateur l'avait
  // collée lui-même (aperçu vignette/titre et vérification via title.php
  // inclus, voir createRow()).
  function openDownloadSheetWithUrl(url) {
    downloadSheetOverlay.classList.add('visible');
    // Cas par défaut (une seule ligne vide non vérifiée) : on la remplace
    // plutôt que d'empiler une seconde ligne à côté — mais on ne touche pas
    // à une file d'attente déjà en cours (plusieurs lignes / déjà remplies).
    const rows = Array.from(urlRows.querySelectorAll('.url-row'));
    if (rows.length === 1 && isRowEmptyAndUnverified(rows[0])) {
      rows[0].remove();
    }
    createRow(url);
  }

  function setYoutubeItemProgress(item, percent, label) {
    const progress = item.querySelector('.yt-suggest-progress');
    item.querySelector('.yt-suggest-progress-fill').style.width = Math.max(0, Math.min(100, percent)) + '%';
    item.querySelector('.yt-suggest-progress-label').textContent = label;
    progress.classList.add('visible');
  }

  // Depuis les boutons ronds MP3/Clip d'un résultat YouTube : le format est
  // décidé par le bouton cliqué (pas par la case à cocher de la feuille), le
  // reste (téléchargement sur l'appareil et/ou le serveur) suit le réglage
  // déjà enregistré (voir downloadToDeviceSwitch/DOWNLOAD_TO_DEVICE_KEY).
  // Contrairement à un ajout depuis la feuille de téléchargement classique,
  // celle-ci ne s'ouvre jamais ici : la progression s'affiche directement
  // sous le titre du résultat, et rien ne doit venir recouvrir l'écran juste
  // après avoir fermé le clavier.
  // Masque les autres résultats de la recherche YouTube pendant qu'un
  // téléchargement est en cours sur l'un d'eux : sans ça, rien n'empêche un
  // second tap accidentel sur un autre résultat pendant que le premier tourne
  // encore, ce qui lancerait un deuxième téléchargement en parallèle.
  function setOtherYoutubeResultsHidden(suggestEl, item, hidden) {
    suggestEl.querySelectorAll('.yt-suggest-item').forEach(other => {
      if (other !== item) other.style.display = hidden ? 'none' : '';
    });
  }

  // Menu "…" (hors musique) d'un résultat YouTube : un seul ouvert à la
  // fois par bloc de suggestions, et jamais laissé ouvert après un clic
  // ailleurs sur la page (voir le listener document ci-dessous) ou pendant
  // qu'un téléchargement démarre sur cette ligne (voir startDirectDownload).
  function closeAllYoutubeOtherMenus(suggestEl) {
    suggestEl.querySelectorAll('.yt-suggest-other-wrap.open').forEach(w => w.classList.remove('open'));
    suggestEl.querySelectorAll('.yt-suggest-other-btn').forEach(b => { b.disabled = false; });
  }
  function closeYoutubeOtherMenu(item) {
    const wrap = item.querySelector('.yt-suggest-other-wrap');
    if (wrap) wrap.classList.remove('open');
  }
  document.addEventListener('click', e => {
    if (!e.target.closest('.yt-suggest-other-wrap')) {
      document.querySelectorAll('.yt-suggest-other-wrap.open').forEach(w => w.classList.remove('open'));
      document.querySelectorAll('.yt-suggest-other-btn').forEach(b => { b.disabled = false; });
    }
  });

  async function startDirectDownload(item, suggestEl, url, audioOnly, category, titlePrefix) {
    if (document.activeElement && document.activeElement.blur) {
      document.activeElement.blur();
    }

    // .yt-suggest-other-btn inclus : le menu "…" (podcast/documentaire/
    // tutoriel, voir plus bas) doit lui aussi se désactiver et se fermer
    // pendant qu'un téléchargement tourne sur cette ligne — sinon rien
    // n'empêchait un second choix pendant que le premier est en cours.
    const dlButtons = item.querySelectorAll('.yt-suggest-dl-btn, .yt-suggest-other-btn');
    dlButtons.forEach(b => { b.disabled = true; });
    closeYoutubeOtherMenu(item);
    setOtherYoutubeResultsHidden(suggestEl, item, true);
    setYoutubeItemProgress(item, 0, 'Récupération des informations…');

    try {
      let startRes = await startDownload(url, audioOnly, false, category, titlePrefix);
      let startData = await startRes.json();
      if (!startRes.ok) throw new Error(startData.error || 'Impossible de démarrer le téléchargement.');

      if (startData.alreadyDownloaded) {
        setYoutubeItemProgress(item, 100, (
          startData.reason === 'library' ? 'Déjà dans ta bibliothèque'
          : startData.reason === 'elsewhere' ? 'Trouvé chez un autre compte'
          : 'Déjà téléchargé sur cet appareil'));

        const force = await askDuplicate(startData.title, startData.reason);
        if (!force) {
          item.querySelector('.yt-suggest-progress').classList.remove('visible');
          dlButtons.forEach(b => { b.disabled = false; });
          setOtherYoutubeResultsHidden(suggestEl, item, false);
          if (startData.reason === 'elsewhere') fetchLibrary();
          return;
        }

        setYoutubeItemProgress(item, 0, 'Nouveau téléchargement…');
        startRes = await startDownload(url, audioOnly, true, category, titlePrefix);
        startData = await startRes.json();
        if (!startRes.ok) throw new Error(startData.error || 'Impossible de démarrer le téléchargement.');
      }

      await pollProgress(startData.jobId, '', (percent, label) => setYoutubeItemProgress(item, percent, label));
      setYoutubeItemProgress(item, 100, 'Terminé');

      if (getDownloadTarget() === 'server') {
        await saveToLibraryOnly(startData.jobId);
      } else {
        triggerBrowserDownload(startData.jobId);
      }

      // Même geste qu'à la fin d'un téléchargement depuis la feuille
      // classique : on revient à la bibliothèque à jour, recherche effacée,
      // et le morceau qui vient d'arriver se lance tout seul.
      await fetchLibrary();
      const ctl = suggestEl.id === 'video-library-yt-suggest' ? videoLibrarySearchCtl : librarySearchCtl;
      ctl.close();
      playLastDownloadedTrack(startData.title, audioOnly, category);
    } catch (err) {
      setYoutubeItemProgress(item, 100, err.message || 'Le téléchargement a échoué.');
      showToast('Le téléchargement a échoué : ' + (err.message || 'erreur inconnue.'), 'error');
      dlButtons.forEach(b => { b.disabled = false; });
      setOtherYoutubeResultsHidden(suggestEl, item, false);
      setTimeout(() => {
        const progress = item.querySelector('.yt-suggest-progress');
        if (progress) progress.classList.remove('visible');
      }, 4000);
    }
  }

  // Un même petit comportement pour les 3 sections (bouton loupe qui ouvre un
  // champ à la place du titre, Échap ou champ vidé qui le referme, croix qui
  // vide juste le texte pour retaper une nouvelle recherche sans tout
  // refermer) : autant le factoriser une fois plutôt que de le tripler.
  function setupLibrarySearch(btnId, inputId, listEl, queryRef, shellId, clearId, suggestId, audioOnly) {
    const btn = btnId ? document.getElementById(btnId) : null;
    const input = document.getElementById(inputId);
    const shell = document.getElementById(shellId);
    const clearBtn = document.getElementById(clearId);
    const suggestEl = document.getElementById(suggestId);
    // Placé juste avant .yt-suggest dans le HTML — voir capture.php.
    const elsewhereEl = suggestEl.previousElementSibling;
    // audioOnly indique le type NATIF de cet onglet : la recherche croisée va
    // donc chercher dans l'autre tableau ET dans "Others" (mélange
    // audio+vidéo — voir applyCrossTypeSearch/buildCrossTypeItem, qui
    // déduisent maintenant le type de chaque morceau plutôt que de recevoir
    // un indicateur unique pour tout le lot) — c'est ce qui fait de cette
    // recherche une recherche sur l'ENSEMBLE de la bibliothèque, pas
    // seulement sur l'autre onglet binaire.
    const getCrossSourceTracks = () => (audioOnly ? rawVideoTracks : rawAudioTracks).concat(rawOtherTracks);

    // Pas de correspondance locale visible pour la requête en cours : on
    // lance (ou on relance) la recherche YouTube de secours ; dès qu'il y en
    // a au moins une (ou que le champ est vide), on efface les suggestions.
    // La recherche dans les AUTRES bibliothèques part en parallèle, sans
    // anti-rebond (simple requête SQL, pas un appel yt-dlp) — avant même de
    // savoir si YouTube trouvera quelque chose.
    function refreshYoutubeSuggestions() {
      const q = queryRef.value.trim();
      const hasLocalMatch = !!listEl.querySelector('.library-item:not(.search-hidden)');
      if (q === '' || hasLocalMatch) {
        clearYoutubeSuggestions(suggestEl);
        clearElsewhereSuggestions(elsewhereEl);
        return;
      }
      fetchElsewhereSuggestions(elsewhereEl, q, audioOnly);
      scheduleYoutubeSearch(suggestEl, q);
    }

    function close() {
      shell.classList.remove('open');
      input.value = '';
      shell.classList.remove('has-value');
      queryRef.value = '';
      applyLibraryFilter(listEl, '');
      applyCrossTypeSearch(listEl, '', getCrossSourceTracks);
      clearYoutubeSuggestions(suggestEl);
      clearElsewhereSuggestions(elsewhereEl);
    }

    function open() {
      shell.classList.add('open');
      input.focus();
      // Le clavier du smartphone met un instant à pousser la mise en page :
      // on attend qu'il ait fini avant de faire défiler, sinon le calcul se
      // fait sur l'ancienne hauteur de page et retombe à côté. Sans ce
      // défilement, le champ reste juste au-dessus du clavier et les
      // résultats de recherche qui apparaissent en dessous restent cachés.
      //
      // scrollIntoView seul ne suffit plus depuis que .library-tabs est
      // sticky (en haut de la page) : aligner le haut du champ sur le haut
      // du viewport le plaçait pile derrière cette barre collante, opaque —
      // invisible malgré le scroll. scroll-margin-top calculé juste avant
      // l'appel (jamais figé à l'avance) : la hauteur exacte de la barre
      // sticky peut différer d'un onglet à l'autre (Audio/Clips) et selon ce
      // qui a fini de charger (vignettes...), donc on la mesure au moment où
      // on en a réellement besoin plutôt que de se fier à un calcul du début.
      setTimeout(() => {
        const stickyBar = document.getElementById('library-tabs');
        // .bottom (pas .height) : la barre a désormais une marge au-dessus
        // du haut de l'écran sur mobile (voir .music-app .library-tabs{top:
        // 3em} dans music.css) — .height seul ignorait cet écart et sous-
        // estimait la marge nécessaire d'autant.
        shell.style.scrollMarginTop = ((stickyBar ? stickyBar.getBoundingClientRect().bottom : 0) + 8) + 'px';
        shell.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 300);
    }

    if (btn) btn.addEventListener('click', open);
    input.addEventListener('input', () => {
      shell.classList.toggle('has-value', input.value.trim() !== '');
      queryRef.value = input.value;
      applyLibraryFilter(listEl, input.value);
      applyCrossTypeSearch(listEl, input.value, getCrossSourceTracks);
      refreshYoutubeSuggestions();
    });
    input.addEventListener('blur', () => {
      if (input.value.trim() === '') close();
    });
    input.addEventListener('keydown', e => {
      if (e.key === 'Escape') close();
    });
    // Vide le texte pour retaper vite une nouvelle recherche, sans refermer
    // la barre (contrairement à Échap / clic ailleurs) — le champ reste actif.
    clearBtn.addEventListener('click', () => {
      input.value = '';
      shell.classList.remove('has-value');
      queryRef.value = '';
      applyLibraryFilter(listEl, '');
      applyCrossTypeSearch(listEl, '', getCrossSourceTracks);
      clearYoutubeSuggestions(suggestEl);
      input.focus();
    });

    return { close, open };
  }

  const audioSearch = { value: '' };
  const videoSearch = { value: '' };

  // Pas de flux temps réel (ni websocket ni SSE côté serveur) : un sondage
  // régulier suffit largement pour une appli familiale à deux-trois comptes,
  // et reste bien plus simple à maintenir sur une stack Apache/PHP-FPM
  // classique — au prix d'un délai de quelques secondes avant la notification.
  let sharedTracksInitialized = false;
  let knownSharedIds = new Set();
  let knownAudioIds = new Set();
  let knownVideoIds = new Set();
  let knownOtherIds = new Set();

  // Fusionne mes fichiers et ceux qui me sont partagés par type (audio ou
  // vidéo), triés ensemble par date — plus d'onglet "Partagé" séparé, la
  // ligne "Partagé par XXX" sous le titre (voir renderLibrary/renderVideoLibrary)
  // suffit à distinguer les deux au sein d'une même liste.
  function combineOwnAndShared(own, shared) {
    return own.concat(shared.map(t => Object.assign({}, t, { _shared: true })))
      .sort((a, b) => new Date(b.downloaded_at) - new Date(a.downloaded_at));
  }

  async function fetchLibrary() {
    try {
      const res = await fetch('library.php');
      if (!res.ok) {
        // Disque USB de la bibliothèque débranché (503, voir library.php) :
        // le dire clairement dans les deux listes plutôt que de laisser
        // l'ancien contenu figé sans explication.
        if (res.status === 503) {
          const err = await res.json().catch(() => null);
          if (err && err.error && !err.maintenance) {
            libList.textContent = '';
            videoLibList.textContent = '';
            for (const list of [libList, videoLibList]) {
              const div = document.createElement('div');
              div.className = 'library-empty';
              div.textContent = err.error;
              list.appendChild(div);
            }
          }
        }
        return;
      }
      const data = await res.json();
      const ownTracks = data.tracks || [];
      const sharedTracks = data.shared_tracks || [];
      renderStorageUsage(data.usage);

      // category !== 'other' exclut "Others" des onglets Audio/Clips — cette
      // catégorie a sa propre liste mixte ci-dessous, indépendante de
      // audio_only (voir ytdl_library_subdir_name côté serveur).
      rawAudioTracks = combineOwnAndShared(
        ownTracks.filter(t => t.audio_only == 1 && t.category !== 'other'),
        sharedTracks.filter(t => t.audio_only == 1 && t.category !== 'other')
      );
      rawVideoTracks = combineOwnAndShared(
        ownTracks.filter(t => t.audio_only == 0 && t.category !== 'other'),
        sharedTracks.filter(t => t.audio_only == 0 && t.category !== 'other')
      );
      rawOtherTracks = combineOwnAndShared(
        ownTracks.filter(t => t.category === 'other'),
        sharedTracks.filter(t => t.category === 'other')
      );
      // Une suppression tout juste lancée mais pas encore confirmée par le
      // serveur ne doit pas être annulée par cette resynchronisation (voir
      // pendingDeletedIds).
      if (pendingDeletedIds.size > 0) {
        rawAudioTracks = rawAudioTracks.filter(t => !pendingDeletedIds.has(t.id));
        rawVideoTracks = rawVideoTracks.filter(t => !pendingDeletedIds.has(t.id));
        rawOtherTracks = rawOtherTracks.filter(t => !pendingDeletedIds.has(t.id));
      }
      renderLibrary(sortAudioTracks(rawAudioTracks));
      renderVideoLibrary(sortVideoTracks(rawVideoTracks));
      renderOtherLibrary(rawOtherTracks);
      audioTabCount.textContent = rawAudioTracks.length;
      videoTabCount.textContent = rawVideoTracks.length;
      othersTabCount.textContent = rawOtherTracks.length;

      // Une recherche en cours doit survivre au sondage périodique (sinon
      // elle se réinitialiserait toute seule 25s après avoir tapé), y
      // compris les résultats "autre type" injectés par applyCrossTypeSearch
      // (effacés comme le reste par renderLibrary/renderVideoLibrary
      // ci-dessus, donc à reconstruire ici aussi).
      applyLibraryFilter(libList, audioSearch.value);
      applyLibraryFilter(videoLibList, videoSearch.value);
      applyCrossTypeSearch(libList, audioSearch.value, () => rawVideoTracks.concat(rawOtherTracks));
      applyCrossTypeSearch(videoLibList, videoSearch.value, () => rawAudioTracks.concat(rawOtherTracks));

      // Notifications : seulement les nouveaux, jamais au tout premier
      // chargement de page (qui listerait l'existant en entier).
      if (sharedTracksInitialized) {
        const newShared = sharedTracks.filter(t => !knownSharedIds.has(t.id));
        if (newShared.length === 1) {
          showToast((newShared[0].shared_by_name || 'Quelqu’un') + ' t’a partagé « ' + (newShared[0].title || 'un fichier') + ' ».', 'info');
        } else if (newShared.length > 1) {
          showToast(newShared.length + ' nouveaux fichiers partagés avec toi.', 'info');
        }
        // Pas de badge si l'onglet concerné est déjà ouvert : on voit les
        // nouveautés (miennes ou partagées) apparaître directement dans la
        // liste, inutile de se compter à soi-même.
        const newAudio = rawAudioTracks.filter(t => !knownAudioIds.has(t.id));
        if (newAudio.length > 0 && !tabButtons.audio.classList.contains('active')) {
          unseenAudioCount += newAudio.length;
          renderAudioTabBadge();
        }
        const newVideo = rawVideoTracks.filter(t => !knownVideoIds.has(t.id));
        if (newVideo.length > 0 && !tabButtons.video.classList.contains('active')) {
          unseenVideoCount += newVideo.length;
          renderVideoTabBadge();
        }
        const newOthers = rawOtherTracks.filter(t => !knownOtherIds.has(t.id));
        if (newOthers.length > 0 && !tabButtons.others.classList.contains('active')) {
          unseenOtherCount += newOthers.length;
          renderOthersTabBadge();
        }
      }
      knownSharedIds = new Set(sharedTracks.map(t => t.id));
      knownAudioIds = new Set(rawAudioTracks.map(t => t.id));
      knownVideoIds = new Set(rawVideoTracks.map(t => t.id));
      knownOtherIds = new Set(rawOtherTracks.map(t => t.id));
      sharedTracksInitialized = true;
      // Ne peut se faire qu'une fois rawAudioTracks connu, et une seule fois
      // (pas à chaque sondage périodique de 25s) — sinon la bannière
      // réapparaîtrait après l'avoir ignorée, ou pendant une écoute en cours.
      if (!resumeCheckedOnce) {
        resumeCheckedOnce = true;
        checkPlaybackResume();
      }
    } catch (err) { /* pas bloquant */ }
  }

  // Sondage périodique : c'est ce qui fait apparaître la notification de
  // partage sur l'écran du destinataire tant que sa page reste ouverte.
  setInterval(fetchLibrary, 25000);

  const CROSSFADE_MS = 900;
  let crossfadeTimer = null;

  // Fait chevaucher l'ancien morceau (volume qui descend) et le nouveau
  // (volume qui monte) sur l'élément <audio> inactif, plutôt que de couper
  // net puis relancer — la transition la plus commune reste en fait celle
  // demandée explicitement : passer d'un morceau à l'autre sans à-coup.
  // Programme une rampe de volume sur l'AudioParam natif (gain.linearRampToValueAtTime)
  // plutôt que par petits paliers réguliers via setInterval : un setInterval, soumis
  // aux à-coups du thread JS principal (l'onglet qui fait autre chose pendant la
  // transition retarde ou regroupe des paliers), produisait des marches de volume
  // irrégulières, audibles comme de petits accrocs façon vinyle qui saute. Programmée
  // sur l'AudioParam, la rampe est calculée et suivie par le moteur audio lui-même,
  // indépendamment de ce qui se passe sur le thread JS pendant ce temps.
  // Repli par paliers uniquement si le graphe Web Audio n'a pas pu s'initialiser
  // (voir ensureGainNode) : pas d'AudioParam à programmer dans ce cas, plus rare.
  function scheduleVolumeRamp(el, toValue, durationMs) {
    const nodes = gainNodes.get(el);
    const target = Math.max(0, toValue);
    if (nodes && audioContext) {
      const now = audioContext.currentTime;
      nodes.volume.gain.cancelScheduledValues(now);
      nodes.volume.gain.setValueAtTime(nodes.volume.gain.value, now);
      nodes.volume.gain.linearRampToValueAtTime(target, now + durationMs / 1000);
      return;
    }
    const steps = 30;
    const stepMs = durationMs / steps;
    const fromValue = el.volume;
    let i = 0;
    const timer = setInterval(() => {
      i++;
      el.volume = Math.max(0, Math.min(1, fromValue + (target - fromValue) * (i / steps)));
      if (i >= steps) clearInterval(timer);
    }, stepMs);
  }

  function crossfadeToTrack(url, isObjectUrl, gainDb) {
    const outgoing = audioPlayer;
    const incoming = (audioPlayer === audioA) ? audioB : audioA;
    const targetVolume = currentVolume / 100;

    setAudioSource(incoming, url, isObjectUrl);
    // Posé sur l'élément ENTRANT, pas sur l'actif : pendant le chevauchement,
    // chacun des deux morceaux garde sa propre correction, sinon le nouveau
    // niveau s'appliquerait aussi à l'ancien en train de s'éteindre.
    applyNormalizationGain(incoming, gainDb);
    incoming.currentTime = 0;
    setElementVolume(incoming, 0);
    incoming.play().catch(() => {});

    // Bascule immédiate de la référence "active" : tous les contrôles (lecture/
    // pause, seek, affichage du temps...) pilotent déjà le nouveau morceau
    // pendant que l'ancien continue de s'éteindre en arrière-plan.
    audioPlayer = incoming;

    if (crossfadeTimer) clearTimeout(crossfadeTimer);
    scheduleVolumeRamp(incoming, targetVolume, CROSSFADE_MS);
    scheduleVolumeRamp(outgoing, 0, CROSSFADE_MS);
    crossfadeTimer = setTimeout(() => {
      crossfadeTimer = null;
      clearAudioSource(outgoing); // libère aussi son blob, la lecture y est terminée
      setElementVolume(outgoing, targetVolume);
    }, CROSSFADE_MS);
  }

  // --- Pré-chargement du morceau suivant ---------------------------------
  // Le morceau en cours est diffusé en flux : si le réseau tombe pendant la
  // transition, l'enchaînement se coupe. On télécharge donc le suivant en
  // entier, en tâche de fond, et on le lit depuis un blob local — plus aucun
  // aller-retour réseau au moment de la bascule. Le blob est libéré dès qu'il
  // n'est plus utile (morceau terminé, remplacé, ou plan invalidé).
  // Le morceau en cours est lui aussi copié localement, mais on ne l'attend
  // pas pour démarrer : la lecture part du flux réseau comme avant, et la
  // copie ne sert de relais que si ce flux se tarit (voir swapToLocalCopy).
  const PREFETCH_DELAY_MS = 4000;   // laisse la copie du morceau courant passer devant
  const CACHE_MAX_BYTES = 80 * 1024 * 1024; // garde-fou mémoire, par emplacement
  const STALL_GRACE_MS = 1200;      // au-delà, le flux est considéré comme perdu
  const trackCaches = { current: null, next: null }; // { id, controller, objectUrl, ready }
  let prefetchTimer = null;
  let plannedNextId = null;   // morceau désigné à l'avance, et donc déjà en cache

  function trackStreamUrl(id) {
    return 'library-play.php?id=' + encodeURIComponent(id);
  }

  // Un blob révoqué pendant sa lecture couperait le son : on ne libère donc
  // jamais celui d'un <audio> tant qu'il ne reçoit pas une autre source (ce
  // qui couvre aussi la fin d'un fondu enchaîné, où l'ancien élément joue
  // encore quelques centaines de millisecondes).
  const objectUrlByAudio = new Map();
  function setAudioSource(el, url, isObjectUrl) {
    const previous = objectUrlByAudio.get(el);
    if (previous && previous !== url) URL.revokeObjectURL(previous);
    objectUrlByAudio.delete(el);
    if (isObjectUrl) objectUrlByAudio.set(el, url);
    el.src = url;
  }
  function clearAudioSource(el) {
    const previous = objectUrlByAudio.get(el);
    if (previous) URL.revokeObjectURL(previous);
    objectUrlByAudio.delete(el);
    el.pause();
    el.removeAttribute('src');
  }

  function releaseCache(slot) {
    if (slot === 'next' && prefetchTimer) { clearTimeout(prefetchTimer); prefetchTimer = null; }
    const entry = trackCaches[slot];
    if (!entry) return;
    entry.controller.abort();
    if (entry.objectUrl) URL.revokeObjectURL(entry.objectUrl);
    trackCaches[slot] = null;
  }
  function releasePrefetch() { releaseCache('next'); }
  function releaseAllCaches() { releaseCache('current'); releaseCache('next'); }

  // Télécharge un morceau en entier dans l'emplacement demandé. Deux
  // emplacements seulement : au pire deux fichiers en mémoire à la fois.
  function cacheTrack(slot, track) {
    if (!track) { releaseCache(slot); return; }
    const existing = trackCaches[slot];
    if (existing && existing.id === track.id) return; // déjà en cache ou en cours
    releaseCache(slot);
    const entry = { id: track.id, controller: new AbortController(), objectUrl: null, ready: false };
    trackCaches[slot] = entry;
    fetch(trackStreamUrl(track.id), { signal: entry.controller.signal, credentials: 'same-origin' })
      .then(res => res.ok ? res.blob() : Promise.reject(new Error('HTTP ' + res.status)))
      .then(blob => {
        if (trackCaches[slot] !== entry) return; // emplacement réattribué entre-temps
        if (blob.size > CACHE_MAX_BYTES) { trackCaches[slot] = null; return; }
        entry.objectUrl = URL.createObjectURL(blob);
        entry.ready = true;
      })
      // Échec silencieux : sans copie locale on reste sur le flux réseau,
      // c'est-à-dire sur le comportement d'avant.
      .catch(() => { if (trackCaches[slot] === entry) trackCaches[slot] = null; });
  }

  // Désigne le morceau qui suivra et lance son pré-chargement. Le choix est
  // arrêté maintenant (et non à la fin du morceau) : c'est ce qui permet de
  // pré-charger le bon fichier même en lecture aléatoire. Le délai laisse la
  // copie du morceau en cours se terminer d'abord — c'est elle qui protège la
  // lecture immédiate, elle passe donc devant dans la file.
  function schedulePrefetch() {
    releaseCache('next');
    const next = pickNextTrack();
    plannedNextId = next ? next.id : null;
    if (!next) return;
    prefetchTimer = setTimeout(() => { prefetchTimer = null; cacheTrack('next', next); }, PREFETCH_DELAY_MS);
  }

  // Bascule l'élément sur la copie locale du morceau en cours, à la position
  // exacte où le flux s'est interrompu. Appelé seulement quand le son est
  // déjà arrêté : la reprise ne peut donc rien couper.
  function swapToLocalCopy(el) {
    const url = takeCachedUrl('current', currentTrackId);
    if (!url) return false;
    const position = el.currentTime;
    const wasPlaying = !el.paused;
    setAudioSource(el, url, true);
    const resume = () => {
      el.removeEventListener('loadedmetadata', resume);
      try { el.currentTime = position; } catch (err) { /* durée inconnue, on repart du début */ }
      if (wasPlaying) el.play().catch(() => {});
    };
    el.addEventListener('loadedmetadata', resume);
    return true;
  }

  // Le morceau réellement joué ensuite : celui prévu (et donc en cache) tant
  // qu'il figure toujours dans la liste, sinon un nouveau choix.
  function nextTrackToPlay() {
    if (plannedNextId !== null && plannedNextId !== currentTrackId) {
      const planned = currentAudioTracks.find(t => t.id === plannedNextId);
      if (planned) return planned;
    }
    return pickNextTrack();
  }

  // Récupère le blob d'un emplacement et l'en sort : sa libération devient la
  // responsabilité de l'élément <audio> qui le reçoit.
  function takeCachedUrl(slot, id) {
    const entry = trackCaches[slot];
    if (!entry || entry.id !== id || !entry.ready) return null;
    if (slot === 'next' && prefetchTimer) { clearTimeout(prefetchTimer); prefetchTimer = null; }
    trackCaches[slot] = null;
    return entry.objectUrl;
  }

  function playTrack(track, options) {
    selectedVideoTrack = null;
    syncVideoLibraryPlayingState();
    // autoplay: false sert uniquement à la reprise après rechargement forcé
    // de la page quand le morceau avait été laissé en pause — le charger
    // sans le relancer tout seul. Tous les autres appels (clic, enchaînement,
    // bannière cross-device) gardent le comportement par défaut : jouer.
    const autoplay = !options || options.autoplay !== false;
    if (currentTrackId === track.id) {
      if (autoplay) togglePlayPause();
      return;
    }
    if (!videoPlayer.paused) videoPlayer.pause(); // pas de musique et vidéo en même temps
    stopYoutubePreviewForPlayback(); // idem pour un aperçu (30s) resté en cours
    currentTrackId = track.id;
    // Marqué joué même hors lecture aléatoire : si tu actives le mode aléatoire
    // après avoir écouté quelques titres, il commencera par ceux que tu n'as
    // pas encore entendus.
    audioCycle.markPlayed(track.id);
    miniThumb.src = track.thumbnail || '';
    // « Artiste - Titre » quand l'artiste est connu (repli sur la chaîne
    // YouTube d'origine si le titre lui-même n'a pas de séparateur) — sinon
    // le mini-lecteur n'affichait que le titre brut, sans l'artiste.
    const miniParts = splitArtistTitle(track);
    setMiniPlayerTitle(miniParts.artist ? (miniParts.artist + ' - ' + miniParts.title) : miniParts.title);
    miniPlayer.classList.add('visible');
    currentTrackRating = track.rating || 0;
    renderMiniStars(currentTrackRating);

    const cachedUrl = takeCachedUrl('next', track.id);
    const url = cachedUrl || trackStreamUrl(track.id);
    // Rien à fondre depuis un silence : fondu seulement si activé, et
    // seulement quand un morceau était déjà en train de jouer (changement
    // volontaire ou enchaînement) — sinon coupure nette classique.
    if (crossfadeEnabled && audioPlayer.src && !audioPlayer.paused) {
      crossfadeToTrack(url, !!cachedUrl, track.gain_db);
    } else {
      setAudioSource(audioPlayer, url, !!cachedUrl);
      applyNormalizationGain(audioPlayer, track.gain_db);
      setElementVolume(audioPlayer, currentVolume / 100);
      if (autoplay) {
        playbackIntent = true;
        audioPlayer.play().catch(() => {});
      }
    }
    syncLibraryPlayingState();
    scheduleAutoFocusPlaying();
    updateMediaSessionMetadata(track);
    maybeAutoShowLyrics(track);
    setupMiniPlayerLyrics(track);
    // Fiche artiste (desktop) : mise à jour à CHAQUE morceau, indépendamment
    // des paroles (affichées ou non) — voir renderDesktopArtistPanel. Pas
    // sur mobile, où le panneau n'existe pas : ça économiserait juste un
    // appel réseau à artist-info.php pour rien.
    if (isDesktopLayout()) {
      const artistParts = splitArtistTitle(track);
      renderDesktopArtistPanel(artistParts.artist, artistParts.title);
    }

    // Le morceau joué depuis un blob est déjà entièrement local : rien à
    // copier. Sinon on télécharge sa copie de secours tout de suite, sans
    // attendre — c'est la lecture en cours qu'elle protège.
    if (cachedUrl) releaseCache('current');
    else cacheTrack('current', track);
    schedulePrefetch();

    // Publié tout de suite (pas d'attente du prochain tick périodique) :
    // changer de morceau puis quitter aussitôt ne doit pas laisser le serveur
    // sur l'ancien titre.
    savePlaybackState(false);
  }

  // --- Recentrage automatique sur le morceau en cours ---
  // En lecture (aléatoire surtout), on ne sait pas d'avance quel titre va
  // suivre : quand un morceau ou un clip démarre et que l'utilisateur n'a pas
  // touché à la page depuis 15 s, on bascule sur l'onglet concerné (Audio ou
  // Clips) et on fait défiler la bibliothèque pour amener la ligne en cours
  // au milieu de l'écran — la ligne surlignée se retrouve en face des yeux
  // sans rien avoir à faire. Pour un clip, le lecteur recouvre la page : la
  // liste est déjà positionnée quand on le referme, et reste lisible en
  // transparence derrière lui.
  // Le moindre geste (souris, touche, molette, toucher) relance le compte à
  // rebours : on ne fait jamais défiler la page sous quelqu'un qui s'en sert.
  const AUTO_FOCUS_IDLE_MS = 15000;
  let autoFocusTimer = null;
  let autoFocusPendingId = null;
  let autoFocusKind = 'audio'; // 'audio' | 'video'
  let lastUserActivity = Date.now();
  // Armé par ⏮/⏭ en lecture aléatoire : là, le prochain titre peut être
  // n'importe où dans la liste, on s'y positionne tout de suite au lieu
  // d'attendre les 15 s d'inactivité — c'est l'utilisateur qui vient de
  // demander le changement, il s'attend à voir où il est tombé.
  let autoFocusImmediate = false;
  function requestImmediateFocus() { if (shuffleEnabled) autoFocusImmediate = true; }
  // Posé par le tap sur la vignette du mini-lecteur (voir miniThumb) : y
  // retourner doit marcher qu'on soit en lecture OU en pause — seul
  // l'auto-focus par inactivité doit, lui, rester silencieux sur une
  // lecture arrêtée (voir le garde plus bas dans autoFocusPlaying).
  let autoFocusIgnorePause = false;

  function noteUserActivity() {
    lastUserActivity = Date.now();
    if (autoFocusPendingId !== null) scheduleAutoFocusPlaying(autoFocusKind);
  }
  ['pointermove', 'pointerdown', 'keydown', 'wheel', 'touchstart'].forEach(evt =>
    document.addEventListener(evt, noteUserActivity, { passive: true, capture: true }));

  function scheduleAutoFocusPlaying(kind = 'audio') {
    clearTimeout(autoFocusTimer);
    autoFocusKind = kind;
    autoFocusPendingId = kind === 'video' ? currentVideoId : currentTrackId;
    // Le cas immédiat passe quand même par setTimeout(0) : la feuille de
    // playlist re-rend sa ligne surlignée juste après playTrack(), il faut
    // la laisser finir avant de la chercher.
    const wait = autoFocusImmediate ? 0 : Math.max(0, AUTO_FOCUS_IDLE_MS - (Date.now() - lastUserActivity));
    autoFocusImmediate = false;
    autoFocusTimer = setTimeout(autoFocusPlaying, wait);
  }

  // Positionne la ligne aux 2/3 de la hauteur DISPONIBLE (pas de la fenêtre
  // entière — topObstruction/bottomObstruction retirent respectivement la
  // barre d'onglets sticky et le mini-lecteur), jamais en plein centre :
  // tout le monde n'a pas un pouce assez long pour glisser confortablement
  // sur une ligne remontée à mi-écran d'une seule main — la caser plus bas
  // (proche du pouce au repos) évite cette gymnastique. scrollIntoView ne
  // permet qu'un centrage à 50% (block:'center'), d'où ce calcul manuel.
  function scrollRowToLowerThird(row, topObstruction, bottomObstruction, reduce) {
    const rect = row.getBoundingClientRect();
    const visibleHeight = window.innerHeight - topObstruction - bottomObstruction;
    const targetCenterY = topObstruction + visibleHeight * (2 / 3);
    const currentCenterY = rect.top + rect.height / 2;
    // Filet de sécurité : jamais au-delà du bas réel de la page, quelle que
    // soit la justesse du calcul ci-dessus — sans lui, une estimation un
    // peu trop généreuse pousse le défilement DANS la marge réservée pour
    // le mini-lecteur (voir syncMiniPlayerPadding) et y révèle un bloc vide
    // (couleur de fond de la page) à la place du contenu attendu.
    const maxScroll = Math.max(0, document.documentElement.scrollHeight - window.innerHeight);
    const targetTop = Math.min(maxScroll, Math.max(0, window.scrollY + (currentCenterY - targetCenterY)));
    window.scrollTo({ top: targetTop, behavior: reduce ? 'instant' : 'smooth' });
  }
  // Même idée, mais à l'intérieur d'un conteneur défilant propre (la feuille
  // de playlist) plutôt que la fenêtre — pas d'obstruction séparée à
  // soustraire ici, le conteneur EST déjà la zone visible utile.
  function scrollRowToLowerThirdInContainer(container, row, reduce) {
    const bodyRect = container.getBoundingClientRect();
    const rowRect = row.getBoundingClientRect();
    const targetCenterY = bodyRect.top + container.clientHeight * (2 / 3);
    const currentCenterY = rowRect.top + rowRect.height / 2;
    const top = container.scrollTop + (currentCenterY - targetCenterY);
    container.scrollTo({ top: Math.max(0, top), behavior: reduce ? 'instant' : 'smooth' });
  }

  function autoFocusPlaying() {
    autoFocusTimer = null;
    const trackId = autoFocusPendingId;
    const kind = autoFocusKind;
    const ignorePause = autoFocusIgnorePause;
    autoFocusPendingId = null;
    autoFocusIgnorePause = false;
    const isVideo = kind === 'video';
    const playingId = isVideo ? currentVideoId : currentTrackId;
    const player = isVideo ? videoPlayer : audioPlayer;
    if (trackId === null || trackId !== playingId || (player.paused && !ignorePause)) return;
    const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
    // Lecture d'une playlist dont la feuille est ouverte : c'est là que
    // l'utilisateur regarde, on recentre la ligne surlignée dans la feuille
    // (son propre conteneur défilant) plutôt que la bibliothèque derrière.
    if (playlistContext && openedPlaylist && openedPlaylist.id === playlistContext.id
        && playlistSheet.classList.contains('visible')) {
      const row = playlistSheetBody.querySelector('.playlist-item.playing');
      if (!row) return;
      scrollRowToLowerThirdInContainer(playlistSheetBody, row, reduce);
      return;
    }
    // Vue "playlist en cours" (voir startPlaylist/switchTab) : recentrer la
    // ligne DANS cette vue, sans quoi le switchTab(audio/video) par défaut
    // plus bas ramènerait sur la bibliothèque à chaque changement de morceau
    // — exactement ce que cette vue existe pour éviter.
    if (isShowingPlaylistView() && playlistContext) {
      const row = playlistViewList.querySelector('.library-item[data-track-id="' + trackId + '"]');
      if (!row) return;
      const barH = libraryTabs.getBoundingClientRect().height;
      const miniH = miniPlayer.classList.contains('visible') ? miniPlayer.getBoundingClientRect().height : 0;
      scrollRowToLowerThird(row, barH, miniH, reduce);
      return;
    }
    // Une feuille ou une modale ouverte (paroles, playlists, ajout, clip) :
    // on ne change pas la vue derrière — l'utilisateur regarde autre chose.
    // Sauf le lecteur vidéo lui-même quand c'est un clip qu'on recentre.
    if (document.querySelector('.sheet-overlay.visible, .download-sheet-overlay.visible, #lyrics-sheet.visible' + (isVideo ? '' : ', #video-modal.visible'))) return;
    const item = document.querySelector((isVideo ? '#video-library-list' : '#library-list') + ' .library-item[data-track-id="' + trackId + '"]');
    if (!item) return;
    switchTab(isVideo ? 'video' : 'audio');
    // Ligne masquée par un filtre de recherche : rien à montrer.
    if (item.offsetParent === null) return;
    // La barre d'onglets sticky en haut et le mini-lecteur en bas rognent le
    // viewport ; sans en tenir compte, les 2/3 se calculeraient sur la
    // fenêtre entière et la ligne tomberait trop bas, en partie cachée.
    const barH = libraryTabs.getBoundingClientRect().height;
    const miniH = miniPlayer.classList.contains('visible') ? miniPlayer.getBoundingClientRect().height : 0;
    scrollRowToLowerThird(item, barH, miniH, reduce);
  }

  // --- Lecture aléatoire sans répétition ---
  // Un tirage indépendant à chaque morceau ramène le même titre bien trop
  // souvent : sur 20 morceaux, chacun a une chance sur 20 à CHAQUE fois, sans
  // aucune mémoire de ce qui vient de passer — d'où des reprises au bout de
  // deux ou trois titres. On procède donc par cycles : chaque morceau de la
  // bibliothèque passe une fois avant qu'aucun ne repasse. L'ordre à
  // l'intérieur d'un cycle, lui, reste bien aléatoire.
  // Un cycle par bibliothèque (audio et clips ont chacun le leur), même règle
  // pour les deux.
  const RECENT_MEMORY = 3;

  // Taille max de l'historique de navigation (voir ci-dessous) : largement
  // au-delà d'une session d'écoute, borné seulement pour ne pas grossir sans
  // fin sur une page qui reste ouverte des jours.
  const HISTORY_LIMIT = 500;

  function createShuffleCycle() {
    let playedThisCycle = new Set();
    // Derniers titres joués, tous cycles confondus : sert uniquement au
    // raccord entre deux cycles. Sans ça, le dernier d'un cycle peut ressortir
    // en tête du suivant — deux passages collés, exactement ce qu'on cherche
    // à éviter.
    let recentlyPlayed = [];
    // Historique de navigation, comme celui d'un navigateur : la suite exacte
    // des titres joués, avec un curseur sur le titre courant. C'est ce qui
    // permet à ⏮ en lecture aléatoire de revenir sur ce qu'on VIENT d'entendre
    // (et non de tirer un nouveau titre au hasard, ce qui rendait le bouton
    // inutile), puis à ⏭ de revenir en avant sur le titre qu'on avait quitté.
    // Un nouveau tirage après un retour en arrière coupe la branche
    // abandonnée, exactement comme une nouvelle page après « précédent ».
    let history = [];
    let cursor = -1;

    return {
      markPlayed(id) {
        playedThisCycle.add(id);
        recentlyPlayed.push(id);
        if (recentlyPlayed.length > RECENT_MEMORY) recentlyPlayed.shift();
        // Déplacement dans l'historique (⏮ ou ⏭ sur une branche existante) :
        // on bouge le curseur sans rien ajouter. Sinon, nouvelle entrée.
        if (history[cursor - 1] === id) { cursor--; return; }
        if (history[cursor + 1] === id) { cursor++; return; }
        if (history[cursor] === id) return; // redémarrage du même titre
        history.length = cursor + 1;
        history.push(id);
        if (history.length > HISTORY_LIMIT) history.shift();
        cursor = history.length - 1;
      },
      // Titre joué juste avant le courant, en sautant ceux qui ont disparu de
      // la liste depuis (supprimés). null si on est au tout début.
      previous(tracks) {
        for (let i = cursor - 1; i >= 0; i--) {
          const track = tracks.find(t => t.id === history[i]);
          if (track) return track;
        }
        return null;
      },
      // Titre qu'on avait quitté par ⏮, s'il y en a un : ⏭ y revient plutôt
      // que d'en tirer un nouveau. null quand on est en tête d'historique.
      forward(tracks) {
        for (let i = cursor + 1; i < history.length; i++) {
          const track = tracks.find(t => t.id === history[i]);
          if (track) return track;
        }
        return null;
      },
      pick(tracks, currentId) {
        if (tracks.length === 0) return null;

        // Le filtre porte sur la liste courante : les titres supprimés depuis
        // le début du cycle disparaissent d'eux-mêmes, ceux ajoutés entre-temps
        // rejoignent le cycle en cours (ils n'ont pas encore été joués).
        let pool = tracks.filter(t => !playedThisCycle.has(t.id));

        if (pool.length === 0) {
          // Cycle bouclé : tout le monde est passé, on repart pour un tour.
          playedThisCycle = new Set();
          pool = tracks.filter(t => !recentlyPlayed.includes(t.id));
          // Bibliothèque trop courte pour s'offrir cette marge : on retombe sur
          // la seule contrainte qui compte vraiment, ne pas s'enchaîner sur
          // soi-même.
          if (pool.length === 0) pool = tracks.filter(t => t.id !== currentId);
          if (pool.length === 0) pool = tracks.slice();
        }

        return weightedPick(pool);
      }
    };
  }

  // Tirage pondéré par la note (étoiles, mini-lecteur uniquement) : chaque
  // morceau a un poids = note + 1, donc un morceau à 5 étoiles a 6 fois plus
  // de chances d'être tiré à chaque pioche qu'un morceau non noté (poids 1).
  // La garantie "personne ne repasse avant la fin du cycle" reste intacte
  // (voir pick() ci-dessus) : la pondération influence seulement l'ORDRE à
  // l'intérieur d'un cycle, pas le fait que chacun y passe une fois. Poids
  // neutre (1) par défaut, donc sans effet sur les clips (jamais notés,
  // l'UI de notation n'existant que dans le mini-lecteur audio).
  function weightedPick(pool) {
    const weights = pool.map(t => (t.rating || 0) + 1);
    const total = weights.reduce((a, b) => a + b, 0);
    let r = Math.random() * total;
    for (let i = 0; i < pool.length; i++) {
      r -= weights[i];
      if (r <= 0) return pool[i];
    }
    return pool[pool.length - 1];
  }

  const audioCycle = createShuffleCycle();
  const videoCycle = createShuffleCycle();

  // Pioche le morceau suivant pour l'enchaînement automatique : cycle aléatoire
  // si la lecture aléatoire est activée, sinon le suivant dans l'ordre de la
  // liste (le plus récemment téléchargé en premier) — sans reboucler sur le
  // premier, pour ne pas jouer en continu indéfiniment.
  function pickNextTrack() {
    // En lecture de playlist, c'est elle qui dicte la suite. On ne renvoie
    // l'élément suivant que s'il est audio : le préchargement (seul appelant
    // concerné ici) n'a rien à faire pour un clip.
    if (playlistContext) {
      return (plannedPlaylistItem && plannedPlaylistItem.audio_only) ? plannedPlaylistItem : null;
    }
    if (currentAudioTracks.length === 0) return null;
    if (shuffleEnabled) return audioCycle.forward(currentAudioTracks) || audioCycle.pick(currentAudioTracks, currentTrackId);
    const index = currentAudioTracks.findIndex(t => t.id === currentTrackId);
    return index >= 0 && index < currentAudioTracks.length - 1 ? currentAudioTracks[index + 1] : null;
  }

  // Piste voisine pour les boutons précédent/suivant. Deux différences avec
  // pickNextTrack() : on peut reculer, et on reboucle en début comme en fin de
  // liste — un clic explicite ne doit jamais rester sans effet, alors que
  // l'enchaînement automatique, lui, doit bien s'arrêter quelque part.
  function pickAdjacentTrack(step) {
    if (currentAudioTracks.length === 0) return null;
    // Aléatoire : ⏮ remonte l'historique réel (au tout début, faute de mieux,
    // on tire un titre — le clic ne doit pas rester sans effet), ⏭ redescend
    // la branche quittée ou tire un nouveau titre.
    if (shuffleEnabled) {
      return (step < 0 ? audioCycle.previous(currentAudioTracks) : audioCycle.forward(currentAudioTracks))
        || audioCycle.pick(currentAudioTracks, currentTrackId);
    }
    const index = currentAudioTracks.findIndex(t => t.id === currentTrackId);
    if (index < 0) return currentAudioTracks[0];
    const len = currentAudioTracks.length;
    return currentAudioTracks[(index + step + len) % len];
  }

  // playTrack() bascule lecture/pause quand on lui repasse le morceau courant :
  // ce n'est pas ce qu'on veut ici (bibliothèque d'un seul morceau, ou aléatoire
  // qui retombe sur le même) — on repart du début à la place.
  function goToTrack(track) {
    if (!track) return;
    if (track.id === currentTrackId) {
      audioPlayer.currentTime = 0;
      if (audioPlayer.paused) audioPlayer.play().catch(() => {});
      return;
    }
    playTrack(track);
  }

  // Reflète l'état lecture/pause sur l'entrée active de la liste, sans tout
  // re-fetch/re-rendre (juste une classe + le contenu de l'icône).
  function syncLibraryPlayingState() {
    document.querySelectorAll('.library-item').forEach(el => {
      const isActive = Number(el.dataset.trackId) === currentTrackId;
      el.classList.toggle('playing', isActive);
      el.querySelector('.library-play-icon').textContent = (isActive && !audioPlayer.paused) ? '❚❚' : '▶';
    });
    // Le bandeau "Playlist au hasard" vit dans ses propres cartes, hors de
    // #library-list — synchronisé séparément (voir syncShelfPlayState).
    syncShelfPlayState();
    // Icône lecture/pause du transport dans la feuille de paroles.
    lyricsPlayPauseBtn.textContent = (currentTrackId !== null && !audioPlayer.paused) ? '❚❚' : '▶';
  }

  function togglePlayPause() {
    if (!audioPlayer.src) return;
    if (audioPlayer.paused) {
      playbackIntent = true;
      audioPlayer.play().catch(() => {});
    }
    else audioPlayer.pause();
  }

  miniPlayBtn.addEventListener('click', togglePlayPause);

  // Convention habituelle des lecteurs audio : passé les premières secondes,
  // « précédent » revient au début du morceau en cours plutôt que de reculer
  // dans la liste — c'est le geste attendu quand on rate un début de chanson.
  const PREV_RESTART_THRESHOLD_S = 3;
  // Fonctions de transport partagées : les boutons du mini-lecteur et les
  // commandes de l'écran verrouillé (Media Session, plus bas) doivent faire
  // exactement la même chose, d'où ce point de passage unique.
  function goToPreviousTrack() {
    if (audioPlayer.src && audioPlayer.currentTime > PREV_RESTART_THRESHOLD_S) {
      audioPlayer.currentTime = 0;
      return;
    }
    // Une playlist en cours de lecture remplace la bibliothèque comme
    // référence de navigation, pour les deux lecteurs.
    if (playlistContext) { advancePlaylist(-1, true); return; }
    goToTrack(pickAdjacentTrack(-1));
  }
  function goToNextTrack() {
    if (playlistContext) { advancePlaylist(1, true); return; }
    // Le morceau prévu d'abord — c'est celui qui est déjà en cache, donc celui
    // qui démarre instantanément ; repli sur le voisin (avec rebouclage) quand
    // il n'y a pas de plan, notamment en fin de liste.
    goToTrack(nextTrackToPlay() || pickAdjacentTrack(1));
  }
  miniPrevBtn.addEventListener('click', () => { requestImmediateFocus(); goToPreviousTrack(); });
  miniNextBtn.addEventListener('click', () => { requestImmediateFocus(); goToNextTrack(); });

  // --- Commandes système : écran verrouillé, casque, widget de notification ---
  // Media Session ne joue rien elle-même : elle publie les métadonnées du
  // morceau et déclare les actions que le système a le droit de nous demander.
  // Sans elle, Android et iOS n'affichent ni titre ni boutons piste précédente
  // / suivante quand l'écran est verrouillé — seulement lecture/pause.
  const mediaSessionSupported = 'mediaSession' in navigator;

  // Même découpage "Artiste - Titre" que le tri de la bibliothèque, pour que
  // l'écran verrouillé affiche l'artiste sur sa propre ligne.
  // Trois cas rencontrés en vrai, d'où les trois étapes :
  //   « HAEVN - We Are »      → séparateur classique
  //   « HAVASI — Prayer »     → tiret cadratin/demi-cadratin, que le seul
  //                             " - " laissait passer (artiste perdu)
  //   « Like a Prayer »       → aucun artiste dans le titre : on se rabat sur
  //                             la chaîne YouTube (colonne uploader)
  // Une moitié vide n'est jamais retenue : mieux vaut le titre entier sans
  // artiste qu'un artiste sans titre.
  function splitArtistTitle(track) {
    const full = ((track && track.title) || 'Sans titre').trim();
    const uploader = (track && track.uploader) ? String(track.uploader).trim() : '';

    const match = full.match(/^(.+?)\s+[-–—]\s+(.+)$/);
    if (match) {
      const artist = match[1].trim();
      const title = match[2].trim();
      if (artist !== '' && title !== '') return { artist, title };
    }

    // Pas de séparateur exploitable : la chaîne fait l'artiste si on la
    // connaît, sinon on n'en invente pas (un faux nom vaut moins que rien).
    return { artist: uploader, title: full };
  }

  // Remplit les 3 <span> de .library-item-title (voir son commentaire CSS) —
  // factorisé une fois, appelé par chaque endroit qui construit une ligne de
  // bibliothèque (audio, clips, vue playlist, résultats croisés, "Others").
  // Même logique exacte qu'avant (juste éclatée en 3 morceaux au lieu d'une
  // seule chaîne concaténée) : .textContent du conteneur reste la
  // concaténation de ses enfants, la recherche (applyLibraryFilter) qui lit
  // .library-item-title.textContent n'a donc rien à changer.
  function renderLibraryItemTitle(item, track) {
    const parts = splitArtistTitle(track);
    const titleEl = item.querySelector('.library-item-title');
    const artistSpan = titleEl.querySelector('.library-item-artist');
    const sepSpan = titleEl.querySelector('.library-item-title-sep');
    const textSpan = titleEl.querySelector('.library-item-title-text');
    if (parts.artist) {
      artistSpan.textContent = parts.artist;
      sepSpan.textContent = ' — ';
      textSpan.textContent = parts.title;
    } else {
      artistSpan.textContent = '';
      sepSpan.textContent = '';
      textSpan.textContent = track.title || 'Sans titre';
    }
  }

  function updateMediaSessionMetadata(track) {
    if (!mediaSessionSupported) return;
    // Reposés à chaque piste : certains navigateurs ne construisent leur
    // panneau qu'au moment où la session devient active, et ignorent des
    // gestionnaires enregistrés avant toute lecture.
    setupMediaSessionActions();
    const parts = splitArtistTitle(track);
    navigator.mediaSession.metadata = new MediaMetadata({
      title: parts.title,
      artist: parts.artist,
      album: 'ytdl',
      // Pas de `sizes` déclaré : les vignettes YouTube n'ont pas toutes le même
      // format, autant laisser le système mesurer plutôt que lui mentir.
      artwork: track.thumbnail ? [{ src: track.thumbnail }] : []
    });
  }

  function updateMediaSessionState() {
    if (!mediaSessionSupported) return;
    const el = activeMediaElement();
    navigator.mediaSession.playbackState = (el.src && !el.paused) ? 'playing' : 'paused';
  }

  // Alimente la barre de progression de l'écran verrouillé. Peut lever si la
  // durée n'est pas encore connue ou si la position sort de l'intervalle
  // pendant un changement de source — sans conséquence, on ignore.
  function updateMediaSessionPosition() {
    if (!mediaSessionSupported || !navigator.mediaSession.setPositionState) return;
    const el = activeMediaElement();
    const duration = el.duration;
    if (!isFinite(duration) || duration <= 0) return;
    try {
      navigator.mediaSession.setPositionState({
        duration: duration,
        position: Math.min(Math.max(0, el.currentTime), duration),
        playbackRate: el.playbackRate || 1
      });
    } catch (err) { /* ignoré volontairement */ }
  }

  // Les navigateurs de bureau (Edge, Chrome) n'affichent qu'une poignée de
  // boutons dans leur panneau média, et les actions de déplacement dans la
  // piste y prennent la place des boutons piste précédente / suivante — d'où
  // un panneau réduit à lecture/pause sur PC alors que le téléphone, qui a
  // plus de place, montrait bien les quatre. On ne déclare donc QUE le
  // transport, et on met explicitement les autres à null : une action déjà
  // enregistrée le reste tant qu'on ne l'efface pas, y compris après un
  // rechargement de page dans certains navigateurs.
  //
  // Le déplacement dans la piste n'est pas perdu pour autant : `seekto`
  // (utilisé par la barre de progression du panneau, qui n'occupe pas de
  // bouton) reste actif.
  // Un clip peut être à l'écran pendant que le mini-lecteur est en pause : les
  // commandes système doivent alors piloter la vidéo, pas l'audio resté en
  // arrière-plan. Les boutons ⏮/⏭ de chaque lecteur savent déjà gérer le cas
  // d'une playlist, on passe donc par eux.
  function activeMediaElement() {
    return (videoModal.classList.contains('visible') && videoPlayer.src) ? videoPlayer : audioPlayer;
  }

  const MEDIA_TRANSPORT_ACTIONS = [
    ['play', () => { const el = activeMediaElement(); if (el.src) el.play().catch(() => {}); }],
    ['pause', () => activeMediaElement().pause()],
    ['previoustrack', () => {
      if (activeMediaElement() === videoPlayer) { videoPrevBtn.click(); return; }
      goToPreviousTrack();
    }],
    ['nexttrack', () => {
      if (activeMediaElement() === videoPlayer) { videoNextBtn.click(); return; }
      goToNextTrack();
    }],
    ['seekto', (d) => { if (d.seekTime != null) activeMediaElement().currentTime = d.seekTime; }]
  ];
  const MEDIA_DISABLED_ACTIONS = ['seekbackward', 'seekforward', 'stop'];

  function setupMediaSessionActions() {
    if (!mediaSessionSupported) return;
    // Une action inconnue du navigateur lève à l'enregistrement : on saute
    // celle-là sans perdre les autres.
    MEDIA_TRANSPORT_ACTIONS.forEach(([name, handler]) => {
      try { navigator.mediaSession.setActionHandler(name, handler); } catch (err) {}
    });
    MEDIA_DISABLED_ACTIONS.forEach(name => {
      try { navigator.mediaSession.setActionHandler(name, null); } catch (err) {}
    });
  }

  setupMediaSessionActions();

  miniClose.addEventListener('click', () => {
    if (crossfadeTimer) { clearTimeout(crossfadeTimer); crossfadeTimer = null; }
    clearAudioSource(audioA);
    clearAudioSource(audioB);
    releaseAllCaches();
    plannedNextId = null;
    currentTrackId = null;
    miniPlayer.classList.remove('visible');
    fetchLibrary();
  });

  miniShuffleBtn.addEventListener('click', () => {
    shuffleEnabled = !shuffleEnabled;
    saveBoolPref('ytdl_shuffle', shuffleEnabled);
    miniShuffleBtn.classList.toggle('toggle-active', shuffleEnabled);
  });
  miniAutoNextBtn.addEventListener('click', () => {
    autoNextEnabled = !autoNextEnabled;
    saveBoolPref('ytdl_autonext', autoNextEnabled);
    miniAutoNextBtn.classList.toggle('toggle-active', autoNextEnabled);
  });
  miniCrossfadeBtn.addEventListener('click', () => {
    crossfadeEnabled = !crossfadeEnabled;
    saveBoolPref('ytdl_crossfade', crossfadeEnabled);
    miniCrossfadeBtn.classList.toggle('toggle-active', crossfadeEnabled);
  });
  miniShowVolumeBtn.addEventListener('click', () => {
    showMobileVolume = !showMobileVolume;
    saveBoolPref('ytdl_mobile_volume', showMobileVolume);
    miniShowVolumeBtn.classList.toggle('toggle-active', showMobileVolume);
    document.body.classList.toggle('show-mobile-volume', showMobileVolume);
  });

  // --- Reprise de lecture cross-device -------------------------------------
  // Une seule ligne côté serveur par compte (voir table playback_state) :
  // morceau + position + appareil, toujours écrasée par le dernier appareil
  // actif. Au chargement sur un autre support, on propose de reprendre pile
  // là où l'écoute s'était arrêtée.
  const resumeBanner = document.getElementById('resume-banner');
  const resumeBannerText = document.getElementById('resume-banner-text');
  const resumeBannerTextA = document.getElementById('resume-banner-text-a');
  const resumeBannerTextB = document.getElementById('resume-banner-text-b');
  const resumeBannerPlayBtn = document.getElementById('resume-banner-play');
  const resumeBannerDismissBtn = document.getElementById('resume-banner-dismiss');
  const PLAYBACK_SAVE_INTERVAL_MS = 10000; // pas la peine de publier à chaque timeupdate
  let lastPlaybackSaveAt = 0;
  let resumeState = null; // état proposé par la bannière, tant qu'il n'a pas été traité
  let resumeBannerAutoHideTimer = null;
  // Valide automatiquement "Oui !" plutôt que de disparaître sans rien faire
  // si l'utilisateur ne touche à rien — repose sur resumeBannerPlayBtn.click(),
  // voir plus bas pour la réserve sur ce que ça change vraiment.
  const RESUME_BANNER_AUTO_HIDE_MS = 5000;

  const LAST_MEDIA_KEY = 'ytdl_last_media_' + <?php echo json_encode((int)$user['id']); ?>;
  function savePlaybackState(useBeacon) {
    const video = selectedVideoTrack;
    if (!video && (!currentTrackId || !audioPlayer.src)) return;
    lastPlaybackSaveAt = Date.now();
    const body = JSON.stringify({
      history_id: video ? video.id : currentTrackId,
      position_seconds: video ? (videoPlayer.getAttribute('src') ? videoPlayer.currentTime || 0 : selectedVideoPosition) : audioPlayer.currentTime || 0,
      device_id: getDeviceId(),
      is_playing: video ? !!videoPlayer.getAttribute('src') && !videoPlayer.paused : playbackIntent,
      // Sans ça, reprendre (même appareil après reload, ou bannière cross-
      // appareil) perdait le contexte de playlist : le morceau repris jouait
      // bien, mais ⏭/l'enchaînement automatique retombaient ensuite sur la
      // bibliothèque entière au lieu de continuer CETTE playlist.
      playlist_id: playlistContext ? playlistContext.id : null
    });
    try { localStorage.setItem(LAST_MEDIA_KEY, body); } catch (err) {}
    if (useBeacon && navigator.sendBeacon) {
      // À la fermeture de l'onglet/appli, un fetch() classique peut être
      // interrompu avant d'aboutir : sendBeacon garantit l'envoi même là.
      navigator.sendBeacon('playback-state.php', new Blob([body], { type: 'text/plain' }));
    } else {
      fetch('playback-state.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body, keepalive: true }).catch(() => {});
    }
  }

  // 'visibilitychange' (hidden) couvre aussi bien l'onglet mis en arrière-plan
  // que l'appli quittée sur mobile — bien plus fiable que 'beforeunload', qui
  // ne se déclenche pas de façon garantie sur mobile/PWA (bouton d'accueil,
  // appli basculée). 'pagehide' reste un filet en plus pour le bureau.
  document.addEventListener('visibilitychange', () => {
    pageIsSuspending = document.hidden;
    if (document.hidden) {
      savePlaybackState(true);
    } else if (playbackIntent) {
      // Retour au premier plan (déverrouillage) : iOS suspend l'AudioContext
      // pendant l'extinction d'écran (voir ensureGainNode), d'où un retour
      // silencieux qu'un simple resume() suffit à réparer. state !== 'running'
      // plutôt que === 'suspended' : Safari utilise aussi 'interrupted'.
      //
      // PAS de el.play() ici : playbackIntent reste vrai même après une pause
      // volontaire déclenchée écran éteint (bouton de l'écran verrouillé,
      // casque Bluetooth...), puisque bindAudioEvents l'ignore À TORT dans ce
      // cas via pageIsSuspending. Un el.play() ici relançait donc la lecture
      // dès le retour au premier plan, juste après que l'utilisateur ait
      // pourtant mis sur pause — bug reporté sur Android, et tout aussi
      // présent sur iOS (Chrome/Safari) par construction.
      if (audioContext && audioContext.state !== 'running') audioContext.resume().catch(() => {});
    }
  });
  // Filet supplémentaire pour le verrouillage d'écran d'un ordinateur de
  // bureau : sur certains navigateurs/OS, verrouiller l'écran ne déclenche
  // PAS 'visibilitychange' (le point de vue de la page ne change pas), mais
  // déclenche bien 'blur'/'focus' à la sortie de veille. Même logique de
  // reprise (contexte seulement, jamais l'élément — voir plus haut) ; la
  // boucle d'auto-relance dans ensureGainNode() reste le filet ultime si ni
  // l'un ni l'autre ne se déclenche.
  window.addEventListener('focus', () => {
    if (playbackIntent && audioContext && audioContext.state !== 'running') {
      audioContext.resume().catch(() => {});
    }
  });
  window.addEventListener('pagehide', () => { pageIsSuspending = true; savePlaybackState(true); });

  function applyResumePosition(el, position, afterSeek) {
    const apply = () => {
      el.currentTime = position;
      if (afterSeek) afterSeek();
    };
    if (el.readyState >= 1 /* HAVE_METADATA */) {
      apply();
    } else {
      el.addEventListener('loadedmetadata', function once() {
        el.removeEventListener('loadedmetadata', once);
        apply();
      });
    }
  }

  function dismissResumeBanner() {
    resumeBanner.classList.remove('visible', 'mini-player-visible');
    resumeState = null;
    clearTimeout(resumeBannerAutoHideTimer);
    resumeBannerAutoHideTimer = null;
  }

  // Bannière "Reprendre « titre » à 1:23 ?" — un tap dessus compte comme un
  // vrai geste utilisateur pour le navigateur, contrairement à un appel
  // programmatique à .play(). Utilisée pour reprendre depuis un autre
  // appareil, mais aussi en repli quand la reprise automatique sur LE MÊME
  // appareil est refusée (voir plus bas).
  function offerResumeBanner(track, position, playlistId) {
    resumeState = { track, position, playlistId: playlistId || null };
    const text = 'Reprendre « ' + (track.title || 'ce morceau') + ' » à ' + formatTime(position) + ' ?';
    resumeBannerTextA.textContent = text;
    resumeBannerTextB.textContent = text;
    resumeBanner.classList.toggle('mini-player-visible', miniPlayer.classList.contains('visible'));
    resumeBanner.classList.add('visible');
    // Le défilement (classe .scrolling) n'a de sens QUE si le texte dépasse
    // vraiment la largeur disponible — mesuré après affichage (offsetWidth
    // vaut 0 tant que .visible n'a pas encore de mise en page), sinon un
    // titre court se retrouverait à défiler pour rien.
    resumeBannerText.classList.remove('scrolling');
    resumeBannerText.style.removeProperty('--marquee-duration');
    requestAnimationFrame(() => {
      const singleWidth = resumeBannerTextA.offsetWidth;
      const available = resumeBannerText.clientWidth;
      if (singleWidth > available) {
        // Vitesse constante quelle que soit la longueur du titre (~40px/s) :
        // sans ça, un titre très long défilerait à toute vitesse pour tenir
        // dans la même durée fixe qu'un titre à peine plus large que la case.
        resumeBannerText.style.setProperty('--marquee-duration', (singleWidth / 40) + 's');
        resumeBannerText.classList.add('scrolling');
      }
    });
    // Valide "Oui !" tout seul plutôt que de disparaître sans rien faire si
    // l'utilisateur l'ignore. ATTENTION : .click() ici n'est PAS un vrai
    // geste utilisateur (isTrusted vaut false) — le navigateur peut donc
    // quand même refuser audioPlayer.play() dans resumeBannerPlayBtn, pour
    // la même raison que la bannière existe : rien ne le distinguerait alors
    // d'un autoplay non sollicité. Le contexte de lecture (playlist, morceau)
    // sera restauré même si le son, lui, reste en pause dans ce cas.
    clearTimeout(resumeBannerAutoHideTimer);
    resumeBannerAutoHideTimer = setTimeout(() => resumeBannerPlayBtn.click(), RESUME_BANNER_AUTO_HIDE_MS);
  }

  // Restaure le contexte de playlist (voir playlistContext) sur une reprise —
  // sans ça, le morceau repris jouait bien mais ⏭/l'enchaînement automatique
  // retombaient ensuite sur la bibliothèque entière au lieu de continuer LA
  // playlist d'où il venait (playback-state.php ne connaît que le morceau,
  // pas la playlist, avant l'ajout de cette colonne). Silencieux si la
  // playlist a disparu depuis, ou ne contient plus ce morceau : la reprise du
  // morceau lui-même ne doit pas échouer pour autant.
  async function restorePlaylistContext(playlistId, trackId) {
    if (!playlistId) return;
    try {
      const playlist = await fetchPlaylist(playlistId);
      if (!playlist.items.some(i => i.id === trackId)) return;
      playlistContext = playlist;
      playlistCurrentId = trackId;
      plannedPlaylistItem = computePlaylistNext(1, false);
      playlistFab.classList.add('active');
      if (playlistFocusEnabled) {
        renderPlaylistView();
        switchTab('playlist');
      }
    } catch (err) { /* playlist supprimée/inaccessible depuis : on continue sans elle */ }
  }

  // Appelé une seule fois, après le tout premier chargement de la bibliothèque
  // (il faut rawAudioTracks pour retrouver le morceau à partir de son id).
  function restoreSelectedClip(state) {
    if (!isDesktopLayout() || !state) return false;
    const track = rawVideoTracks.concat(rawOtherTracks).find(t => Number(t.id) === Number(state.history_id) && !t.audio_only);
    if (!track) return false;
    selectedVideoTrack = track;
    selectedVideoPosition = Number(state.position_seconds) || 0;
    closeVideoToTrack();
    setupVideoLyrics(track);
    const parts = splitArtistTitle(track);
    renderDesktopArtistPanel(parts.artist, parts.title);
    return true;
  }

  async function checkPlaybackResume() {
    try {
      // Device-local selection wins over an older audio save still in flight.
      let localState = null;
      try { localState = JSON.parse(localStorage.getItem(LAST_MEDIA_KEY)); } catch (err) {}
      if (restoreSelectedClip(localState)) return;
      const res = await fetch('playback-state.php');
      if (!res.ok) return;
      const data = await res.json();
      if (!data.state) return;
      if (restoreSelectedClip(data.state)) return;
      const track = rawAudioTracks.find(t => t.id === data.state.history_id);
      // Morceau supprimé depuis, ou déjà en cours (rechargement de page
      // pendant l'écoute sur ce même appareil) : rien à proposer.
      if (!track || track.id === currentTrackId) return;

      if (data.state.device_id === getDeviceId()) {
        // Même appareil : un rechargement forcé (F5, plantage, PWA relancée)
        // n'a rien d'un changement d'intention, contrairement à reprendre sur
        // un autre appareil. On repart directement là où on en était, sans
        // bannière — et en lecture seulement si le morceau l'était vraiment
        // à l'instant de la sauvegarde (une pause volontaire ne doit pas se
        // relancer toute seule).
        // Safari est plus fiable lorsque la position est restaurée avant
        // play() : jouer puis seek() peut faire rejeter la reprise au reload.
        const shouldResume = !!data.state.is_playing;
        await restorePlaylistContext(data.state.playlist_id, track.id);
        playTrack(track, { autoplay: false });
        maybeReopenLyricsSheetOnResume(track);
        applyResumePosition(audioPlayer, data.state.position_seconds, () => {
          if (shouldResume) {
            playbackIntent = true;
            audioPlayer.play().catch(() => {
              // Safari (et parfois d'autres navigateurs) refuse la reprise
              // automatique après un rechargement complet de page, même
              // pour reprendre EXACTEMENT là où on en était sur le même
              // appareil — la politique anti-autoplay ne distingue pas ce
              // cas d'un vrai autoplay non sollicité. Rester silencieusement
              // en pause serait pris pour un bug plutôt qu'une limite du
              // navigateur : on retombe sur la bannière de reprise, qui ne
              // demande qu'un tap (un vrai geste utilisateur, qui lève le
              // blocage) au lieu de laisser deviner pourquoi ça ne joue pas.
              playbackIntent = false;
              offerResumeBanner(track, audioPlayer.currentTime, playlistContext ? playlistContext.id : null);
            });
          }
          // playTrack() vient d'évaluer maybeAutoShowLyrics() alors que la
          // position n'était pas encore restaurée (currentTime à 0) : si la
          // feuille de paroles s'est ouverte entre-temps, on la resynchronise
          // sur la position réellement reprise plutôt que d'attendre le
          // prochain timeupdate.
          updateLyricsSync();
        });
        return;
      }

      offerResumeBanner(track, data.state.position_seconds, data.state.playlist_id);
    } catch (err) { /* pas bloquant */ }
  }

  resumeBannerPlayBtn.addEventListener('click', async () => {
    if (!resumeState) return;
    const { track, position, playlistId } = resumeState;
    dismissResumeBanner();
    await restorePlaylistContext(playlistId, track.id);
    playTrack(track);
    applyResumePosition(audioPlayer, position, () => updateLyricsSync());
  });
  resumeBannerDismissBtn.addEventListener('click', dismissResumeBanner);

  // Écouteurs posés sur LES DEUX éléments (pas juste celui actif au moment du
  // chargement de page) : pendant un fondu, l'élément qui devient actif doit
  // déjà réagir à ses propres évènements. Chaque callback vérifie qu'il
  // s'agit bien de l'élément actif avant d'agir, pour ignorer l'ancien
  // pendant qu'il termine sa rampe de volume descendante.
  function bindAudioEvents(el) {
    el.addEventListener('play', () => {
      if (el !== audioPlayer) return;
      playbackIntent = true;
      miniPlayBtn.textContent = '❚❚';
      syncLibraryPlayingState();
      updateMediaSessionState();
      updateMediaSessionPosition();
    });
    el.addEventListener('pause', () => {
      if (el !== audioPlayer) return;
      // Une pause imposée par Safari pendant la suspension n'est pas une
      // pause choisie : garder l'intention permet une reprise au reload.
      if (!pageIsSuspending) playbackIntent = false;
      miniPlayBtn.textContent = '▶';
      syncLibraryPlayingState();
      updateMediaSessionState();
      savePlaybackState(false); // une pause volontaire doit se retrouver exactement là au retour
    });
    el.addEventListener('loadedmetadata', () => {
      if (el !== audioPlayer) return;
      updateMediaSessionPosition(); // la durée n'était pas connue avant
    });
    // Une seconde de résolution suffit pour la barre de l'écran verrouillé :
    // `timeupdate` se déclenche environ quatre fois par seconde, inutile de
    // republier l'état aussi souvent.
    let lastPublishedSecond = -1;
    el.addEventListener('timeupdate', () => {
      if (el !== audioPlayer || seeking) return;
      miniSeek.value = el.duration ? (el.currentTime / el.duration * 100) : 0;
      miniTime.textContent = miniTimeMobile.textContent = formatTime(el.currentTime) + ' / ' + formatTime(el.duration);
      const second = Math.floor(el.currentTime);
      if (second !== lastPublishedSecond) {
        lastPublishedSecond = second;
        updateMediaSessionPosition();
      }
      if (Date.now() - lastPlaybackSaveAt > PLAYBACK_SAVE_INTERVAL_MS) {
        savePlaybackState(false);
      }
    });
    el.addEventListener('ended', () => {
      if (el !== audioPlayer) return;
      miniPlayBtn.textContent = '▶';
      // Une playlist se déroule toujours jusqu'au bout, sans dépendre du
      // réglage 🔁 : c'est ce qu'on attend en la lançant.
      if (playlistContext && advancePlaylist(1, false)) return;
      if (autoNextEnabled) {
        const next = nextTrackToPlay();
        if (next) { playTrack(next); return; }
      }
      // Fin de la lecture sans enchaînement : ni la copie du morceau écoulé ni
      // le pré-chargement ne serviront plus, on rend la mémoire au lieu de la
      // garder jusqu'au prochain clic.
      releaseAllCaches();
      plannedNextId = null;
      syncLibraryPlayingState();
    });
    // Filet réseau. `waiting`/`stalled` se déclenchent aussi en usage normal
    // (démarrage, déplacement dans la piste) : on laisse donc un délai de
    // grâce et on ne bascule que si le flux n'est toujours pas reparti, pour
    // ne pas remplacer la source à chaque hoquet passager.
    let stallTimer = null;
    const disarmStallWatchdog = () => { if (stallTimer) { clearTimeout(stallTimer); stallTimer = null; } };
    const armStallWatchdog = () => {
      if (el !== audioPlayer || stallTimer) return;
      stallTimer = setTimeout(() => {
        stallTimer = null;
        if (el !== audioPlayer) return;
        if (el.readyState >= 3 /* HAVE_FUTURE_DATA : reparti tout seul */) return;
        swapToLocalCopy(el);
      }, STALL_GRACE_MS);
    };
    el.addEventListener('waiting', armStallWatchdog);
    el.addEventListener('stalled', armStallWatchdog);
    el.addEventListener('playing', disarmStallWatchdog);
    el.addEventListener('canplay', disarmStallWatchdog);

    el.addEventListener('error', () => {
      if (el !== audioPlayer || !el.src) return;
      disarmStallWatchdog();
      // Flux interrompu alors que la copie locale est prête : elle prend le
      // relais, sans message d'erreur ni intervention de l'utilisateur.
      if (swapToLocalCopy(el)) return;
      showToast("Ce morceau n'est plus disponible (probablement purgé après 30 jours).", 'error');
      miniPlayer.classList.remove('visible');
      clearAudioSource(el);
      releaseAllCaches();
      plannedNextId = null;
      currentTrackId = null;
    });
  }
  bindAudioEvents(audioA);
  bindAudioEvents(audioB);

  miniSeek.addEventListener('input', () => { seeking = true; });
  miniSeek.addEventListener('change', () => {
    if (audioPlayer.duration) audioPlayer.currentTime = (miniSeek.value / 100) * audioPlayer.duration;
    seeking = false;
    updateMediaSessionPosition(); // l'écran verrouillé doit suivre le déplacement
  });

  // --- Bibliothèque vidéo + lecteur plein écran ---
  const videoLibSection = document.getElementById('video-library-section');
  const videoLibList = document.getElementById('video-library-list');
  const othersLibList = document.getElementById('others-library-list');
  const videoModal = document.getElementById('video-modal');
  const videoModalTitle = document.getElementById('video-modal-title');
  const videoPlayer = document.getElementById('video-player');
  const videoFullscreenBtn = document.getElementById('video-fullscreen-btn');
  const videoModalClose = document.getElementById('video-modal-close');
  const videoPrevBtn = document.getElementById('video-prev-btn');
  const videoNextBtn = document.getElementById('video-next-btn');
  const videoLyricsBtn = document.getElementById('video-lyrics-btn');
  const videoLyricsBar = document.getElementById('video-lyrics-bar');
  const videoArtistBar = document.getElementById('video-artist-bar');
  // Le panneau desktop partagé (voir updateLyricsSync/lyricsSyncData.isVideo)
  // peut aussi défiler sur les paroles d'un CLIP — pas juste .audioA/.audioB.
  videoPlayer.addEventListener('timeupdate', updateLyricsSync);
  const videoLyricsOffsetRow = document.getElementById('video-lyrics-offset-row');
  const videoLyricsOffsetValue = document.getElementById('video-lyrics-offset-value');
  const videoLyricsOffsetStatus = document.getElementById('video-lyrics-offset-status');
  const videoLyricsOffsetNow = document.getElementById('video-lyrics-offset-now');
  const videoLyricsSyncStartBtn = document.getElementById('video-lyrics-sync-start-btn');
  const videoLyricsSyncControls = document.getElementById('video-lyrics-sync-controls');
  const videoLyricsSyncProgress = document.getElementById('video-lyrics-sync-progress');
  const videoLyricsSyncSkipBtn = document.getElementById('video-lyrics-sync-skip');
  const videoLyricsSyncSaveBtn = document.getElementById('video-lyrics-sync-save');
  const videoLyricsSyncCancelBtn = document.getElementById('video-lyrics-sync-cancel');
  const videoTimelineSeek = document.getElementById('video-timeline-seek');
  const videoTimelineTime = document.getElementById('video-timeline-time');

  // Reflète la progression sur la ligne de temps custom (voir .video-timeline,
  // affichée en haut à gauche uniquement en plein écran) — indépendant de
  // `seeking`, déjà pris par la barre de progression de l'audio.
  let videoSeeking = false;
  function updateVideoTimeline() {
    if (videoSeeking) return;
    videoTimelineSeek.value = videoPlayer.duration ? (videoPlayer.currentTime / videoPlayer.duration * 100) : 0;
    videoTimelineTime.textContent = formatTime(videoPlayer.currentTime) + ' / ' + formatTime(videoPlayer.duration);
  }
  videoPlayer.addEventListener('timeupdate', updateVideoTimeline);
  videoPlayer.addEventListener('loadedmetadata', updateVideoTimeline);
  videoTimelineSeek.addEventListener('input', () => { videoSeeking = true; });
  videoTimelineSeek.addEventListener('change', () => {
    if (videoPlayer.duration) videoPlayer.currentTime = (videoTimelineSeek.value / 100) * videoPlayer.duration;
    videoSeeking = false;
  });

  let currentVideoTracks = []; // dernière liste connue, pour désigner le clip suivant
  let currentVideoId = null;
  let selectedVideoTrack = null;
  let selectedVideoPosition = 0;


  // Retire uniquement ma propre visibilité sur ce partage — jamais le
  // fichier réel (qui reste chez son propriétaire), jamais l'accès des
  // autres personnes à qui il aurait été partagé par ailleurs.
  // Confirmation avant retrait : depuis que le bouton porte une poubelle,
  // identique à celle de la suppression, un clic malencontreux est vite
  // arrivé — et le geste fait disparaître le morceau de la bibliothèque.
  const unshareModal = document.getElementById('unshare-confirm-modal');
  const unshareConfirmBtn = document.getElementById('unshare-confirm-btn');
  let pendingUnshareTrack = null;

  function openUnshareConfirm(track) {
    pendingUnshareTrack = track;
    document.getElementById('unshare-confirm-title').textContent = track.title || 'Sans titre';
    unshareConfirmBtn.disabled = false;
    unshareConfirmBtn.textContent = 'Retirer';
    unshareModal.classList.add('visible');
  }

  document.getElementById('unshare-cancel-btn').addEventListener('click', () => {
    unshareModal.classList.remove('visible');
    pendingUnshareTrack = null;
  });

  unshareConfirmBtn.addEventListener('click', async () => {
    if (!pendingUnshareTrack) return;
    unshareConfirmBtn.disabled = true;
    unshareConfirmBtn.textContent = 'Retrait…';
    await unshareTrack(pendingUnshareTrack);
    unshareModal.classList.remove('visible');
    pendingUnshareTrack = null;
  });

  async function unshareTrack(track) {
    try {
      const res = await fetch('library-unshare.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + encodeURIComponent(track.id)
      });
      if (!res.ok) throw new Error();

      // Coupe proprement si c'est justement en train de jouer.
      if (track.id === currentTrackId) {
        if (crossfadeTimer) { clearTimeout(crossfadeTimer); crossfadeTimer = null; }
        clearAudioSource(audioA);
        clearAudioSource(audioB);
        releaseCache('current');
        currentTrackId = null;
        miniPlayer.classList.remove('visible');
      }
      if (track.id === plannedNextId) { releasePrefetch(); plannedNextId = null; }
      if (videoModal.classList.contains('visible') && videoPlayer.src.endsWith('id=' + track.id)) {
        videoPlayer.pause();
        videoPlayer.removeAttribute('src');
        videoModal.classList.remove('visible', 'video-immersive', 'video-controls-hidden');
        currentVideoId = null;
      }

      showToast('Retiré de tes partages.', 'info');
      fetchLibrary();
    } catch (err) {
      showToast('Impossible de retirer ce partage.', 'error');
    }
  }

  function renderVideoLibrary(tracks) {
    // Conservée comme currentAudioTracks : sans liste en mémoire, impossible
    // de désigner le clip suivant ou précédent.
    currentVideoTracks = tracks;
    videoLibList.innerHTML = '';
    if (tracks.length === 0) {
      videoLibList.innerHTML = '<div class="library-empty">Aucune vidéo pour l\'instant.</div>';
      return;
    }

    tracks.forEach(track => {
      const isShared = !!track._shared;
      const item = document.createElement('div');
      item.className = 'library-item';
      if (track.id === (currentVideoId ?? selectedVideoTrack?.id)) item.classList.add('playing');
      item.dataset.trackId = track.id;
      item.setAttribute('role', 'button');
      item.tabIndex = 0;
      item.dataset.azKey = libraryAzKey(trackArtistKey(track));
      item.innerHTML =
        '<div class="library-thumb-wrap">' +
          '<img class="library-thumb" src="' + (track.thumbnail || '') + '" alt="">' +
          '<span class="library-play-icon">' + (track.id === currentVideoId ? '❚❚' : '▶') + '</span>' +
        '</div>' +
        '<div class="library-body">' +
          '<div class="library-info">' +
            '<div class="library-title-row">' +
              '<div class="library-item-title"><span class="library-item-artist"></span><span class="library-item-title-sep"></span><span class="library-item-title-text"></span></div>' +
              (IS_ADMIN ? '<button type="button" class="library-mobile-rename-btn" aria-label="Renommer" data-hint="Renommer">' + RENAME_ICON_SVG + '</button>' : '') +
            '</div>' +
            (isShared ? '<div class="library-shared-by"></div>' : '') +
          '</div>' +
          '<div class="library-actions">' + libraryActionsHtml(track, isShared) + '</div>' +
        '</div>';
      renderLibraryItemTitle(item, track);
      if (isShared) {
        item.querySelector('.library-shared-by').textContent =
          'Partagé par ' + (track.shared_by_name || '?');
      }
      bindLibraryActionButtons(item, track, isShared);
      item.addEventListener('click', () => { exitPlaylistContext(); playVideo(track); });
      videoLibList.appendChild(item);
    });
  }

  // "Others" : liste mixte audio+vidéo (voir le bouton "Hors musique" du
  // formulaire) — même motif de rendu par ligne que renderPlaylistView (icône
  // de type par morceau), mais lecture directe (playTrack/playVideo) au lieu
  // de playPlaylistItem, cette liste n'étant pas une playlist. Pas de tri par
  // artiste ni de recherche dédiée ici (voir la section HTML correspondante) :
  // rendue dans l'ordre reçu du serveur (les plus récents en premier).
  function renderOtherLibrary(tracks) {
    othersLibList.innerHTML = '';
    if (tracks.length === 0) {
      othersLibList.innerHTML = '<div class="library-empty">Rien dans "Others" pour l\'instant.</div>';
      return;
    }

    tracks.forEach(track => {
      const isVideo = !track.audio_only;
      const isShared = !!track._shared;
      const isCurrent = isVideo ? (track.id === currentVideoId) : (track.id === currentTrackId);
      const isPlayingGlyph = isVideo ? isCurrent : (isCurrent && !audioPlayer.paused);
      const item = document.createElement('div');
      item.className = 'library-item' + (isCurrent ? ' playing' : '');
      item.dataset.trackId = track.id;
      item.setAttribute('role', 'button');
      item.tabIndex = 0;
      item.innerHTML =
        '<div class="library-thumb-wrap">' +
          '<img class="library-thumb" src="' + (track.thumbnail || '') + '" alt="">' +
          '<span class="library-play-icon">' + (isPlayingGlyph ? '❚❚' : '▶') + '</span>' +
        '</div>' +
        '<div class="library-body">' +
          '<div class="library-info">' +
            '<div class="library-cross-type-row">' +
              '<div class="library-title-row">' +
              '<div class="library-item-title"><span class="library-item-artist"></span><span class="library-item-title-sep"></span><span class="library-item-title-text"></span></div>' +
              (IS_ADMIN ? '<button type="button" class="library-mobile-rename-btn" aria-label="Renommer" data-hint="Renommer">' + RENAME_ICON_SVG + '</button>' : '') +
            '</div>' +
              '<span class="library-type-badge" title="' + (isVideo ? 'Clip vidéo' : 'Morceau audio') + '">' +
                (isVideo ? CLIP_TYPE_ICON_SVG : AUDIO_TYPE_ICON_SVG) +
              '</span>' +
            '</div>' +
            (isShared ? '<div class="library-shared-by"></div>' : '') +
          '</div>' +
          '<div class="library-actions">' + libraryActionsHtml(track, isShared) + '</div>' +
        '</div>';
      renderLibraryItemTitle(item, track);
      if (isShared) {
        item.querySelector('.library-shared-by').textContent = 'Partagé par ' + (track.shared_by_name || '?');
      }
      bindLibraryActionButtons(item, track, isShared);
      item.addEventListener('click', () => { exitPlaylistContext(); isVideo ? playVideo(track) : playTrack(track); });
      othersLibList.appendChild(item);
    });
  }

  // Ouvre le lecteur puis tente le plein écran immédiatement, dans le même
  // geste utilisateur (le clic) : au-delà de ce tour de boucle, les
  // navigateurs refusent la demande de plein écran par sécurité.
  // `goFullscreen` seulement au premier lancement (le clic sur la vignette) :
  // sur un enchaînement ou un clic sur ⏭, on est déjà en plein écran et
  // redemander ne ferait que perturber la lecture — la nouvelle source y reste
  // affichée toute seule.
  // Surligne le clip en cours dans l'onglet Clips, comme
  // syncLibraryPlayingState() pour l'audio. Rebranché sur les changements de
  // classe du modal : toutes les façons de le fermer (✕, erreur, suppression,
  // passage à un morceau audio) remettent currentVideoId à null juste après.
  function syncVideoLibraryPlayingState() {
    // #others-library-list inclus : "Others" mélange audio et vidéo, ses
    // lignes vidéo ont besoin du même surlignage que #video-library-list.
    // Les lignes audio d'Others, elles, sont déjà couvertes par le
    // sélecteur global (non scopé) de syncLibraryPlayingState().
    document.querySelectorAll('#video-library-list .library-item, #others-library-list .library-item').forEach(el => {
      const isActive = Number(el.dataset.trackId) === (currentVideoId ?? selectedVideoTrack?.id);
      el.classList.toggle('playing', isActive);
      el.querySelector('.library-play-icon').textContent = isActive && videoModal.classList.contains('visible') && !videoPlayer.paused ? '❚❚' : '▶';
    });
  }
  new MutationObserver(syncVideoLibraryPlayingState)
    .observe(videoModal, { attributes: true, attributeFilter: ['class'] });

  function playVideo(track, goFullscreen = true) {
    // Les deux éléments, pas juste l'actif : un fondu enchaîné pourrait être
    // en cours et laisser l'ancien morceau audible en arrière-plan sinon.
    if (crossfadeTimer) { clearTimeout(crossfadeTimer); crossfadeTimer = null; }
    audioA.pause();
    audioB.pause();
    stopYoutubePreviewForPlayback(); // idem pour un aperçu (30s) resté en cours
    currentVideoId = track.id;
    selectedVideoTrack = track;
    selectedVideoPosition = 0;
    // Parité avec l'audio : le clip en cours doit être surligné dans SA
    // liste (Clips), pas rester caché derrière l'onglet d'où le clic est
    // parti (Audio, Autres, résultat de recherche croisée...). Jamais si
    // une playlist est déjà affichée en pleine page : y rester est le
    // comportement attendu (la fermeture du clip doit alors revenir à
    // CETTE playlist, pas basculer sur l'onglet Clips).
    if (!isShowingPlaylistView()) switchTab('video');
    // Marqué joué même hors lecture aléatoire, comme pour l'audio.
    videoCycle.markPlayed(track.id);
    videoModalTitle.textContent = track.title || 'Sans titre';
    videoPlayer.src = 'library-play.php?id=' + encodeURIComponent(track.id);
    // Même niveau que les mp3 : le clip ne doit pas partir à 100 % alors que
    // le curseur du lecteur est réglé bien plus bas.
    videoPlayer.volume = currentVolume / 100;
    videoPlayer.muted = false;
    // Même correction que pour l'audio : c'est sur les clips qu'elle compte
    // le plus, ce sont eux qui sortaient nettement plus bas que le reste.
    applyNormalizationGain(videoPlayer, track.gain_db);
    // Une fois raccordé au graphe (ligne au-dessus, la toute première fois),
    // le gain du volume utilisateur ne se réinitialise pas tout seul comme
    // pour audioA/audioB (voir ensureGainNode) : sans cette ligne, un clip
    // repartait toujours au gain par défaut du GainNode (1 = 100 %) au lieu
    // du volume réellement choisi, quel que soit .volume ci-dessus.
    setElementVolume(videoPlayer, currentVolume / 100);
    videoModal.classList.add('visible');
    videoPlayer.play().catch(() => {});
    savePlaybackState(false);
    syncVideoLibraryPlayingState();
    scheduleAutoFocusPlaying('video');
    // Le panneau média doit annoncer le clip, pas le morceau audio précédent.
    updateMediaSessionMetadata(track);
    updateMediaSessionState();
    if (goFullscreen && !videoModal.classList.contains('video-immersive')) enterVideoFullscreen();
    setupVideoLyrics(track);
    // Fiche artiste (desktop uniquement — voir #video-artist-bar, invisible
    // sur mobile faute de place) : repli immédiat pour ne pas laisser
    // affichée celle du clip précédent le temps que la nouvelle charge.
    paintVideoArtistBar(null);
    if (isDesktopLayout()) {
      const videoParts = splitArtistTitle(track);
      renderVideoArtistBar(videoParts.artist, videoParts.title);
      // Panneau PARTAGÉ (page, invisible tant que le modal reste ouvert —
      // voir son commentaire dans setupVideoLyrics) : mis à jour à chaque
      // clip comme playTrack() le fait pour chaque morceau audio (jamais
      // gated sur "déjà ouvert", contrairement aux paroles) — sinon, à la
      // fermeture du clip, la fiche affichée restait celle du dernier
      // morceau AUDIO écouté plutôt que celle du clip qu'on vient de voir.
      renderDesktopArtistPanel(videoParts.artist, videoParts.title);
    }
  }

  // --- Paroles sous la vidéo ---
  // Même pipeline que pour l'audio (cache/requestLyrics) : l'icône
  // n'apparaît que si des paroles existent pour ce clip, synchronisées
  // (défilement en rythme) ou brutes (texte fixe) selon ce que lrclib.net /
  // lyrics.ovh ont pu fournir.
  let videoLyricsEntry = null; // { lyrics, synced } pour le clip actuellement affiché, ou null
  let videoLyricsSyncActiveIndex = -1;
  // Artiste/titre du clip ouvert : le décalage s'enregistre sur ce couple, pas
  // sur l'identifiant du fichier (même règle que la feuille audio).
  let videoLyricsTrack = null;
  let videoLyricsOffsetSaveTimer = null;

  function renderVideoLyricsBar() {
    videoLyricsBar.innerHTML = '';
    videoLyricsSyncActiveIndex = -1;
    if (!videoLyricsEntry) return;
    if (videoLyricsEntry.synced && videoLyricsEntry.synced.length) {
      lyricsWithMusicBreaks(videoLyricsEntry.synced).forEach(line => {
        const div = document.createElement('div');
        div.className = 'lyrics-line';
        div.textContent = line.text || '♫';
        if (line.instrumental) div.setAttribute('aria-label', 'Pause musicale (repère estimé)');
        videoLyricsBar.appendChild(div);
      });
      updateVideoLyricsSync();
    } else {
      videoLyricsBar.textContent = videoLyricsEntry.lyrics;
    }
    // Même règle que la feuille audio : on démarre toujours en haut, même si
    // updateVideoLyricsSync() vient de faire défiler vers la ligne active.
    videoLyricsBar.scrollTop = 0;
  }

  // Le réglage n'a de sens que sur un minutage synchronisé : sans lui, il n'y
  // a rien à décaler (paroles brutes, texte fixe).
  function syncVideoLyricsOffsetRow() {
    const applicable = !!(videoLyricsEntry && videoLyricsEntry.synced
      && videoLyricsEntry.synced.length && videoLyricsBar.classList.contains('visible'));
    videoLyricsOffsetRow.classList.toggle('visible', applicable);
    if (applicable) {
      renderVideoLyricsOffsetValue();
      videoLyricsOffsetStatus.textContent = '';
    }
  }
  function renderVideoLyricsOffsetValue() {
    videoLyricsOffsetValue.textContent = videoLyricsEntry
      ? formatLyricsStart(videoLyricsEntry.videoOffset, videoLyricsEntry.synced) : '';
  }

  // Applique un décalage : effet immédiat à l'écran, puis enregistrement
  // différé — un clic répété (on affine la pause) ne doit pas écrire au
  // serveur à chaque fois.
  function applyVideoLyricsOffsetToViews(seconds) {
    videoLyricsEntry.videoOffset = seconds;
    const cached = lyricsCache.get(lyricsCacheKey(videoLyricsTrack.artist, videoLyricsTrack.title));
    if (cached) cached.videoOffset = seconds;
    if (lyricsSyncData?.isVideo && lyricsSyncData.artist === videoLyricsTrack.artist && lyricsSyncData.title === videoLyricsTrack.title) {
      lyricsSyncData.offset = seconds;
      lyricsSyncActiveIndex = -1;
      renderLyricsOffsetValue();
      updateLyricsSync();
    }
    videoLyricsSyncActiveIndex = -1;
    updateVideoLyricsSync();
  }

  function setVideoLyricsOffset(seconds) {
    if (!videoLyricsEntry || !videoLyricsTrack) return;
    if (!Number.isFinite(seconds)) return;
    seconds = Math.max(-180, Math.min(180, Math.round(seconds * 100) / 100));
    applyVideoLyricsOffsetToViews(seconds);
    renderVideoLyricsOffsetValue();
    // Resynchronisation immédiate : sans ça, la ligne surlignée ne bougerait
    // qu'au prochain timeupdate, soit jusqu'à un quart de seconde plus tard.
    videoLyricsSyncActiveIndex = -1;
    updateVideoLyricsSync();

    videoLyricsOffsetStatus.textContent = '…';
    if (videoLyricsOffsetSaveTimer) clearTimeout(videoLyricsOffsetSaveTimer);
    const { artist, title } = videoLyricsTrack;
    videoLyricsOffsetSaveTimer = setTimeout(() => {
      fetch('lyrics-offset.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ artist, title, offset: seconds, variant: 'video' })
      })
        .then(r => r.json())
        .then(data => {
          if (!data || !data.ok) throw new Error('refusé');
          // Le serveur borne la valeur : on affiche ce qu'il a réellement
          // retenu, pas ce qui a été tapé.
          if (videoLyricsEntry && videoLyricsTrack && videoLyricsTrack.artist === artist && videoLyricsTrack.title === title && videoLyricsEntry.videoOffset === seconds) {
            applyVideoLyricsOffsetToViews(data.offset);
            renderVideoLyricsOffsetValue();
            videoLyricsOffsetStatus.textContent = 'enregistré';
          }
        })
        .catch(() => { videoLyricsOffsetStatus.textContent = 'non enregistré'; });
    }, 600);
  }

  // "Les paroles commencent ici" : le décalage cherché est l'écart entre la
  // position de lecture (le premier mot vient d'être chanté à l'écran) et
  // l'horodatage du premier vers non vide du LRC.
  videoLyricsOffsetNow.addEventListener('click', () => {
    if (!videoLyricsEntry || !videoLyricsEntry.synced) return;
    const first = firstSungLineTime(videoLyricsEntry.synced);
    if (first === null) return;
    setVideoLyricsOffset(videoPlayer.currentTime - first);
  });

  function updateVideoLyricsSync() {
    if (manualSyncActive) return;
    if (!videoLyricsEntry || !videoLyricsEntry.synced || !videoLyricsBar.classList.contains('visible')) return;
    // Décalage PROPRE AU CLIP (voir setVideoLyricsOffset) : celui de la
    // feuille audio ne conviendrait pas, une vidéo commençant souvent par une
    // intro absente du fichier sonore.
    const t = videoPlayer.currentTime - (videoLyricsEntry.videoOffset || 0);
    const lines = lyricsWithMusicBreaks(videoLyricsEntry.synced);
    let idx = -1;
    for (let i = 0; i < lines.length; i++) {
      if (lines[i].time <= t) idx = i; else break;
    }
    if (idx === videoLyricsSyncActiveIndex) return;
    videoLyricsSyncActiveIndex = idx;
    const lineEls = videoLyricsBar.querySelectorAll('.lyrics-line');
    lineEls.forEach((el, i) => el.classList.toggle('active', i === idx));
    if (idx >= 0 && lineEls[idx]) centerVideoLyricsLine(lineEls[idx]);
  }
  videoPlayer.addEventListener('timeupdate', updateVideoLyricsSync);
  videoPlayer.addEventListener('timeupdate', () => {
    if (selectedVideoTrack && Date.now() - lastPlaybackSaveAt >= PLAYBACK_SAVE_INTERVAL_MS) savePlaybackState(false);
  });
  // Le lecteur vidéo garde ses contrôles natifs, donc son propre curseur de
  // volume : un réglage fait là doit devenir LE réglage commun, sinon le
  // prochain mp3 repartirait au niveau précédent. Le mute natif est laissé
  // de côté (couper le son du clip n'est pas baisser le volume à zéro).
  videoPlayer.addEventListener('volumechange', () => {
    if (videoPlayer.muted) return;
    const vol = Math.round(videoPlayer.volume * 100);
    if (vol !== currentVolume) setVolume(vol);
  });

  // Fiche artiste du clip — parité avec l'audio (voir renderDesktopArtistPanel/
  // paintDesktopArtistPanel), mais bloc dédié DANS le modal vidéo (voir son
  // commentaire HTML) plutôt qu'une tentative de réutiliser le panneau
  // desktop, invisible derrière le fond opaque du modal. Réutilise le MÊME
  // cache mémoire (artistInfoMemoryCache) : pas de double appel réseau pour
  // un artiste déjà consulté côté audio, et vice-versa.
  function renderVideoArtistBar(artist, trackTitle) {
    videoArtistBarWantedArtist = artist;
    if (!artist) { paintVideoArtistBar(null); return; }
    const cached = artistInfoMemoryCache.get(artist);
    if (cached) { paintVideoArtistBar(cached); return; }
    fetch('artist-info.php?artist=' + encodeURIComponent(artist) + '&title=' + encodeURIComponent(trackTitle || ''))
      .then(res => res.json())
      .then(info => {
        artistInfoMemoryCache.set(artist, info);
        if (videoArtistBarWantedArtist === artist) paintVideoArtistBar(info);
      })
      .catch(() => {});
  }
  // Page Wikipédia disparue : « Lire la suite… » déroule l'article complet
  // archivé en base (artist-bio.php) à la place de l'extrait.
  function archivedBioLink(className, artist, extractEl) {
    const link = document.createElement('a');
    link.className = className;
    link.href = '#';
    link.textContent = 'Lire la suite…';
    link.addEventListener('click', (e) => {
      e.preventDefault();
      link.textContent = 'Chargement…';
      fetch('artist-bio.php?artist=' + encodeURIComponent(artist || ''))
        .then(r => r.ok ? r.json() : Promise.reject())
        .then(data => {
          extractEl.style.whiteSpace = 'pre-line';
          extractEl.textContent = String(data.full_text || '')
            .replace(/^=+\s*(.+?)\s*=+$/gm, (m, t) => '\n' + t.toUpperCase());
          link.remove();
        })
        .catch(() => { link.textContent = 'Lire la suite…'; });
    });
    return link;
  }
  let videoArtistBarWantedArtist = null;
  // Même précaution que paintDesktopArtistPanel : DOM/textContent, jamais de
  // concaténation HTML (thumbnail/extrait/URL viennent de Wikipédia, donc
  // externes et non maîtrisés).
  function paintVideoArtistBar(info) {
    videoArtistBar.innerHTML = '';
    videoArtistBar.classList.remove('visible');
    if (!info || !info.extract) return;
    const inner = document.createElement('div');
    inner.className = 'video-artist-bar-inner';
    if (info.thumbnail) {
      const img = document.createElement('img');
      img.className = 'video-artist-thumb';
      img.src = info.thumbnail;
      img.alt = '';
      inner.appendChild(img);
    }
    const body = document.createElement('div');
    body.className = 'video-artist-body';
    const name = document.createElement('div');
    name.className = 'video-artist-name';
    name.textContent = info.wiki_title || videoArtistBarWantedArtist;
    body.appendChild(name);
    const extract = document.createElement('div');
    extract.className = 'video-artist-extract';
    extract.textContent = info.extract;
    body.appendChild(extract);
    if (info.wiki_url) {
      const link = document.createElement('a');
      link.className = 'video-artist-link';
      link.href = info.wiki_url;
      link.target = '_blank';
      link.rel = 'noopener';
      link.textContent = 'Voir sur Wikipédia';
      body.appendChild(link);
    } else if (info.has_more) {
      body.appendChild(archivedBioLink('video-artist-link', videoArtistBarWantedArtist, extract));
    }
    inner.appendChild(body);
    videoArtistBar.appendChild(inner);
    videoArtistBar.classList.add('visible');
  }

  function setupVideoLyrics(track) {
    videoLyricsEntry = null;
    videoLyricsTrack = null;
    // Un enregistrement encore en attente porte sur le clip PRÉCÉDENT : ses
    // artiste/titre sont figés dans la fermeture, il aboutit donc au bon
    // endroit — seul l'affichage du statut est remis à zéro ici.
    videoLyricsOffsetRow.classList.remove('visible');
    videoLyricsOffsetStatus.textContent = '';
    videoLyricsBtn.style.display = 'none';
    videoLyricsBtn.classList.remove('lyrics-active');
    videoLyricsBar.classList.remove('visible');
    videoLyricsBar.innerHTML = '';
    // Un mode "synchro manuelle" resté ouvert sur le clip PRÉCÉDENT (quitté
    // via Échap plein écran, par exemple, qui ne repasse jamais par
    // exitManualLyricsSync) ne doit JAMAIS survivre à un nouveau clip —
    // sinon .sync-mode restait collée sur le bandeau (texte aligné à
    // gauche, lignes cliquables sans action réelle) alors que ce nouveau
    // clip n'a rien à voir avec une synchro en cours de construction.
    manualSyncActive = false;
    window.ytdlSetVideoEditMode(false);
    manualSyncLines = [];
    manualSyncTimes = [];
    manualSyncIndex = 0;
    videoLyricsBar.classList.remove('sync-mode');
    videoLyricsSyncControls.classList.remove('visible');
    videoLyricsSyncStartBtn.style.display = 'none';
    const { artist, title } = splitArtistTitle(track);
    // Prépare le panneau desktop dès la sélection, même s’il était fermé.
    if (isDesktopLayout()) openLyricsSheet(track, artist && title ? 'Recherche des paroles…' : 'Paroles indisponibles pour ce titre.', null, 0, true);
    if (!artist || !title) return;
    // track.duration_seconds : SANS elle, le serveur (voir ytdl_fetch_
    // lyrics_data) ne peut plus écarter un minutage lrclib qui correspond en
    // fait à un ENREGISTREMENT DIFFÉRENT (ici, une version studio classique
    // bien plus courte que cette session/clip étendu) — l'icône se
    // retrouvait orange (synchro) alors que le minutage ne correspond à rien
    // sur CE clip précis.
    requestLyrics(artist, title, entry => {
      // Le clip a pu changer entre-temps : n'affecte que celui toujours ouvert.
      if (track.id !== selectedVideoTrack?.id) return;
      if (entry && entry.available) {
        videoLyricsEntry = entry;
        videoLyricsTrack = { artist, title, duration: track.duration_seconds };
        videoLyricsBtn.style.display = '';
        videoLyricsBtn.classList.toggle('lyrics-synced', !!entry.synced);
        videoLyricsBtn.title = entry.synced ? 'Voir les paroles synchro' : 'Voir les paroles';
        // Parité avec l'audio (maybeAutoShowLyrics) : s'affichent toutes
        // seules dès qu'elles sont trouvées, pas seulement au clic sur
        // l'icône — sur un clip, le bandeau ne prend de la place qu'une
        // fois qu'il y a vraiment quelque chose à y lire.
        videoLyricsBar.classList.add('visible');
        videoLyricsBtn.classList.add('lyrics-active');
        renderVideoLyricsBar();
        syncVideoLyricsOffsetRow();
        if (isDesktopLayout()) {
          openLyricsSheet(track, entry.lyrics, entry.synced, entry.videoOffset, true);
        }
        // Le bouton "synchro" reste offert MÊME quand un minutage existe
        // déjà (via lrclib.net ou une synchro manuelle précédente) : à côté
        // du simple décalage global (voir #video-lyrics-offset-row, "Les
        // paroles commencent ici" — toujours dispo si entry.synced, voir
        // syncVideoLyricsOffsetRow juste au-dessus, inchangé), l'utilisateur
        // doit pouvoir tout reprendre ligne par ligne si un simple décalage
        // ne suffit pas (dérive au fil du morceau, pas juste une intro plus
        // longue). Seul son libellé change selon le cas.
        videoLyricsSyncStartBtn.style.display = '';
        videoLyricsSyncStartBtn.title = entry.synced ? 'Recréer la synchro' : 'Créer une synchro';
      } else if (isDesktopLayout()) {
        openLyricsSheet(track, 'Paroles indisponibles pour ce titre.', null, 0, true);
      }
      // Rien à synchroniser du tout (aucune parole disponible) : seul cas où
      // le bouton disparaît complètement.
      if (!entry || !entry.available) videoLyricsSyncStartBtn.style.display = 'none';
    }, track.duration_seconds);
  }
  videoLyricsBtn.addEventListener('click', () => {
    const active = videoLyricsBar.classList.toggle('visible');
    videoLyricsBtn.classList.toggle('lyrics-active', active);
    if (active) renderVideoLyricsBar();
    syncVideoLyricsOffsetRow();
  });

  // --- Synchro manuelle des paroles (clip) ---
  // Construite ligne par ligne, en tapant le rythme pendant la lecture,
  // pour un clip dont aucun minutage exploitable n'a été trouvé — voir
  // #video-lyrics-sync-start-btn et son commentaire HTML. Le tableau
  // manualSyncLines vient du texte brut déjà affiché (jamais retouché) ;
  // seuls les INSTANTS sont produits ici.
  let manualSyncActive = false;
  let manualSyncReady = false;
  let manualSyncLines = [];
  let manualSyncTimes = [];
  let manualSyncIndex = 0;
  // Ligne la plus avancée jamais atteinte cette session de synchro — la
  // molette (voir handleSyncWheel) peut reculer manualSyncIndex pour
  // réécouter une ligne déjà calée, mais jamais au-delà : les lignes plus
  // loin n'ont encore aucun instant connu, rien à prévisualiser.
  let manualSyncFrontier = 0;

  // Scroll only the lyrics viewport, never the fullscreen player or page.
  function centerVideoLyricsLine(line) {
    const top = line.offsetParent === videoLyricsBar ? line.offsetTop : line.offsetTop - videoLyricsBar.offsetTop;
    videoLyricsBar.scrollTo({
      top: Math.max(0, top - (videoLyricsBar.clientHeight - line.offsetHeight) / 2),
      behavior: manualSyncActive || matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth'
    });
  }

  function renderManualSyncLines() {
    const done = manualSyncIndex >= manualSyncLines.length;
    document.getElementById('sync-current-line').textContent = done ? 'Toutes les lignes sont traitées.' : manualSyncLines[manualSyncIndex];
    document.getElementById('sync-next-line').textContent = done ? 'Enregistre pour appliquer cette synchro. Elle sera partagée avec les autres utilisateurs.' : 'Ensuite : ' + (manualSyncLines[manualSyncIndex + 1] || 'dernière ligne');
    videoLyricsSyncProgress.textContent = done ? 'Synchro terminée' : 'Ligne ' + (manualSyncIndex + 1) + ' sur ' + manualSyncLines.length;
    const mark = document.getElementById('sync-mark');
    mark.disabled = done;
    mark.textContent = !manualSyncReady ? 'Démarrer le clip depuis le début' : videoPlayer.paused ? 'Reprendre le clip' : 'Cette ligne commence maintenant · Espace';
    document.getElementById('sync-pause').disabled = !manualSyncReady || done;
    document.getElementById('sync-pause').textContent = videoPlayer.paused ? 'Reprendre' : 'Pause';
    document.getElementById('sync-undo').disabled = manualSyncIndex === 0;
    videoLyricsSyncSkipBtn.disabled = done || !manualSyncReady;
    videoLyricsSyncSaveBtn.disabled = !done || !manualSyncTimes.some(t => t !== null);
    videoLyricsSyncSaveBtn.hidden = !done;
    if (done) videoPlayer.pause();
  }

  function markGuidedSync() {
    if (!manualSyncActive || manualSyncIndex >= manualSyncLines.length) return;
    if (!manualSyncReady || videoPlayer.paused) {
      manualSyncReady = true;
      videoPlayer.play().then(renderManualSyncLines).catch(() => {
        showToast('La lecture ne démarre pas. Réessaie avec les contrôles du clip.', 'error');
        renderManualSyncLines();
      });
      return;
    }
    manualSyncMarkLine(manualSyncIndex);
  }
  document.getElementById('sync-mark').addEventListener('click', markGuidedSync);
  document.getElementById('sync-pause').addEventListener('click', () => {
    if (videoPlayer.paused) videoPlayer.play().catch(() => {}); else videoPlayer.pause();
    renderManualSyncLines();
  });
  document.getElementById('sync-undo').addEventListener('click', () => {
    if (!manualSyncActive || manualSyncIndex === 0) return;
    manualSyncIndex--;
    const time = manualSyncTimes[manualSyncIndex];
    videoPlayer.pause();
    if (time !== null) videoPlayer.currentTime = Math.max(0, time - 3);
    manualSyncTimes.fill(null, manualSyncIndex);
    renderManualSyncLines();
  });
  document.addEventListener('keydown', e => {
    if (!manualSyncActive || e.code !== 'Space' || e.ctrlKey || e.altKey || e.metaKey || /INPUT|TEXTAREA|SELECT/.test(e.target.tagName) || e.target.isContentEditable) return;
    e.preventDefault();
    if (!e.repeat) markGuidedSync();
  }, true);
  videoPlayer.addEventListener('play', () => { if (manualSyncActive) renderManualSyncLines(); });
  videoPlayer.addEventListener('pause', () => { if (manualSyncActive) renderManualSyncLines(); });

  function manualSyncMarkLine(i) {
    if (!manualSyncActive || i < 0 || i >= manualSyncLines.length) return;
    const time = videoPlayer.currentTime;
    const previous = manualSyncTimes.slice(0, i).filter(t => t !== null).at(-1);
    if (previous !== undefined && time <= previous) {
      showToast('Avance le clip après la dernière ligne calée, ou reprends une ligne précédente.', 'info');
      return;
    }
    // Recalibrating an earlier line invalidates its following timestamps.
    manualSyncTimes.fill(null, i);
    manualSyncTimes[i] = time;
    manualSyncIndex = i + 1;
    manualSyncFrontier = Math.max(manualSyncFrontier, manualSyncIndex);
    renderManualSyncLines();
  }

  function startManualLyricsSync() {
    if (!videoLyricsEntry || !videoLyricsEntry.lyrics) return;
    manualSyncLines = videoLyricsEntry.lyrics.split('\n').map(l => l.trim()).filter(l => l !== '');
    if (manualSyncLines.length < 1) return;
    manualSyncTimes = new Array(manualSyncLines.length).fill(null);
    manualSyncIndex = 0;
    manualSyncFrontier = 0;
    manualSyncActive = true;
    manualSyncReady = false;
    window.ytdlSetVideoEditMode(true);
    videoPlayer.pause();
    videoPlayer.currentTime = 0;
    videoLyricsBar.innerHTML = '';
    videoLyricsBar.classList.add('visible', 'sync-mode');
    videoLyricsSyncControls.classList.add('visible');
    videoLyricsOffsetRow.classList.remove('visible');
    videoLyricsSyncStartBtn.style.display = 'none';
    videoLyricsSyncSaveBtn.textContent = 'Enregistrer';
    // Il faut écouter pour taper le rythme — reprend la lecture si le clip
    // était en pause au moment de démarrer.
    renderManualSyncLines();
  }
  function exitManualLyricsSync() {
    manualSyncActive = false;
    window.ytdlSetVideoEditMode(false);
    videoLyricsBar.classList.remove('sync-mode');
    videoLyricsSyncControls.classList.remove('visible');
    videoLyricsSyncStartBtn.style.display = (videoLyricsEntry && videoLyricsEntry.available) ? '' : 'none';
    if (videoLyricsEntry && videoLyricsEntry.available) {
      videoLyricsSyncStartBtn.title = videoLyricsEntry.synced ? 'Recréer la synchro' : 'Créer une synchro';
    }
    renderVideoLyricsBar();
    syncVideoLyricsOffsetRow();
  }

  videoLyricsSyncStartBtn.addEventListener('click', startManualLyricsSync);
  videoLyricsSyncCancelBtn.addEventListener('click', exitManualLyricsSync);
  // Ligne instrumentale/sans parole exploitable : exclue du résultat final
  // (son temps reste null, filtré à l'enregistrement) plutôt que de forcer
  // un horodatage arbitraire qui casserait le surlignage.
  videoLyricsSyncSkipBtn.addEventListener('click', () => {
    if (!manualSyncActive || manualSyncIndex >= manualSyncLines.length) return;
    manualSyncIndex++;
    manualSyncFrontier = Math.max(manualSyncFrontier, manualSyncIndex);
    renderManualSyncLines();
  });
  // Molette au-dessus du guide : revoir/écouter une ligne déjà atteinte sans
  // avoir à répéter "Corriger la dernière ligne" pas à pas — jamais au-delà
  // de manualSyncFrontier (lignes plus loin jamais visitées, aucun instant
  // connu à prévisualiser). Un cran de molette = une ligne, quel que soit le
  // deltaY réel (throttle) — sinon un simple geste de trackpad sauterait
  // plusieurs lignes d'un coup.
  let lastSyncWheelAt = 0;
  let syncWheelFlashTimer = null;
  function handleSyncWheel(e) {
    if (!manualSyncActive) return;
    e.preventDefault();
    const now = Date.now();
    if (now - lastSyncWheelAt < 150) return;
    const dir = e.deltaY > 0 ? 1 : -1;
    const maxIndex = Math.min(manualSyncFrontier, manualSyncLines.length - 1);
    const target = Math.max(0, Math.min(maxIndex, manualSyncIndex + dir));
    if (target === manualSyncIndex) return;
    lastSyncWheelAt = now;
    manualSyncIndex = target;
    videoPlayer.pause();
    const t = manualSyncTimes[target];
    if (t !== null && t !== undefined) videoPlayer.currentTime = t;
    renderManualSyncLines();
    const currentLineEl = document.getElementById('sync-current-line');
    currentLineEl.classList.remove('sync-line-flash');
    clearTimeout(syncWheelFlashTimer);
    // Redémarre l'animation même si elle tournait déjà (deux crans de
    // molette rapprochés) — retrait puis réapplication au frame suivant.
    requestAnimationFrame(() => currentLineEl.classList.add('sync-line-flash'));
    syncWheelFlashTimer = setTimeout(() => currentLineEl.classList.remove('sync-line-flash'), 400);
  }
  document.querySelector('.sync-guide').addEventListener('wheel', handleSyncWheel, { passive: false });
  videoLyricsSyncSaveBtn.addEventListener('click', async () => {
    if (!videoLyricsTrack || manualSyncIndex < manualSyncLines.length) return;
    const lines = manualSyncLines
      .map((text, i) => ({ time: manualSyncTimes[i], text }))
      .filter(l => l.time !== null);
    if (lines.length < 1) return;
    videoLyricsSyncSaveBtn.disabled = true;
    videoLyricsSyncSaveBtn.textContent = 'Enregistrement…';
    try {
      const res = await fetch('lyrics-manual-sync.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          artist: videoLyricsTrack.artist,
          title: videoLyricsTrack.title,
          duration: videoLyricsTrack.duration,
          lines,
        }),
      });
      if (!res.ok) throw new Error();
      const data = await res.json();
      // Cache client à jour tout de suite (même clé que requestLyrics) :
      // la synchro fraîchement créée s'affiche sans attendre un rechargement.
      const key = lyricsCacheKey(videoLyricsTrack.artist, videoLyricsTrack.title);
      const cachedEntry = lyricsCache.get(key) || { available: true, lyrics: videoLyricsEntry.lyrics, offset: 0 };
      cachedEntry.available = true;
      cachedEntry.synced = data.lines;
      lyricsCache.set(key, cachedEntry);
      videoLyricsEntry = cachedEntry;
      exitManualLyricsSync();
      videoLyricsBtn.classList.add('lyrics-synced');
      videoLyricsBtn.title = 'Voir les paroles synchro';
      showToast('Synchro enregistrée — elle profite maintenant à tout le monde.', 'success');
    } catch (err) {
      showToast("Échec de l'enregistrement de la synchro.", 'error');
      videoLyricsSyncSaveBtn.disabled = false;
      videoLyricsSyncSaveBtn.textContent = 'Enregistrer';
    }
  });

  // Mêmes règles que pour l'audio : cycle aléatoire sans répétition si 🔀 est
  // actif, sinon l'ordre de la liste. Le rebouclage distingue les deux usages —
  // un clic explicite sur ⏭ ne doit jamais rester sans effet, alors que
  // l'enchaînement automatique s'arrête au dernier clip.
  function pickNextVideo() {
    if (currentVideoTracks.length === 0) return null;
    if (shuffleEnabled) return videoCycle.forward(currentVideoTracks) || videoCycle.pick(currentVideoTracks, currentVideoId);
    const index = currentVideoTracks.findIndex(t => t.id === currentVideoId);
    return index >= 0 && index < currentVideoTracks.length - 1 ? currentVideoTracks[index + 1] : null;
  }

  function pickAdjacentVideo(step) {
    if (currentVideoTracks.length === 0) return null;
    // Même logique d'historique que pickAdjacentTrack().
    if (shuffleEnabled) {
      return (step < 0 ? videoCycle.previous(currentVideoTracks) : videoCycle.forward(currentVideoTracks))
        || videoCycle.pick(currentVideoTracks, currentVideoId);
    }
    const index = currentVideoTracks.findIndex(t => t.id === currentVideoId);
    if (index < 0) return currentVideoTracks[0];
    const len = currentVideoTracks.length;
    return currentVideoTracks[(index + step + len) % len];
  }

  function goToVideo(track) {
    if (!track) return;
    if (track.id === currentVideoId) { // liste d'un seul clip
      videoPlayer.currentTime = 0;
      videoPlayer.play().catch(() => {});
      return;
    }
    playVideo(track, false);
  }

  videoPrevBtn.addEventListener('click', () => {
    // Même convention que le mini-lecteur audio : au-delà de trois secondes,
    // « précédent » revient au début du clip en cours.
    if (videoPlayer.src && videoPlayer.currentTime > PREV_RESTART_THRESHOLD_S) {
      videoPlayer.currentTime = 0;
      return;
    }
    requestImmediateFocus();
    if (playlistContext) { advancePlaylist(-1, true); return; }
    goToVideo(pickAdjacentVideo(-1));
  });
  videoNextBtn.addEventListener('click', () => {
    requestImmediateFocus();
    if (playlistContext) { advancePlaylist(1, true); return; }
    goToVideo(pickNextVideo() || pickAdjacentVideo(1));
  });

  videoPlayer.addEventListener('ended', () => {
    if (manualSyncActive) { renderManualSyncLines(); return; }
    if (isDesktopLayout()) { closeVideoToTrack(); return; }
    if (playlistContext && advancePlaylist(1, false)) return;
    if (!autoNextEnabled) return;
    const next = pickNextVideo();
    if (next) playVideo(next, false);
  });

  // Fullscreen the complete player so clip navigation stays available.
  // iPhone browsers without element fullscreen use a viewport-filling player.
  function enterVideoFullscreen() {
    if (videoModal.classList.contains('video-immersive')) {
      videoModal.classList.remove('video-immersive');
      if (document.fullscreenElement === videoModal) document.exitFullscreen().catch(() => {});
      return;
    }
    videoModal.classList.add('video-immersive');
    if (videoModal.requestFullscreen) {
      videoModal.requestFullscreen().then(() => {
        if (screen.orientation && typeof screen.orientation.lock === 'function') {
          return screen.orientation.lock('landscape').catch(() => {});
        }
      }).catch(() => {
        // CSS rotates the complete player if fullscreen/orientation lock is unavailable.
      });
    }
  }

  videoFullscreenBtn.addEventListener('click', enterVideoFullscreen);
  // Preserve the selected clip independently of the active media source.
  // Desktop returns to its library row and the shared artist/lyrics panels.
  function closeVideoToTrack() {
    const track = selectedVideoTrack;
    if (videoPlayer.getAttribute('src')) selectedVideoPosition = videoPlayer.currentTime || 0;
    videoPlayer.pause();
    videoPlayer.removeAttribute('src');
    videoModal.classList.remove('visible', 'video-immersive', 'video-controls-hidden');
    currentVideoId = null;
    savePlaybackState(false);
    if (document.fullscreenElement === videoModal) document.exitFullscreen().catch(() => {});
    if (!isDesktopLayout() || !track) { syncVideoLibraryPlayingState(); return; }
    clearTimeout(autoFocusTimer);
    autoFocusPendingId = null;
    playlistSheet.classList.remove('visible');
    switchTab('video');
    // A previous search must not hide the clip we are returning to.
    videoSearch.value = '';
    const searchInput = document.getElementById('video-library-search-input');
    if (searchInput) searchInput.value = '';
    const tracks = sortVideoTracks(rawVideoTracks);
    if (!tracks.some(t => t.id === track.id)) tracks.unshift(track);
    renderVideoLibrary(tracks);
    syncVideoLibraryPlayingState();
    requestAnimationFrame(() => {
      if (selectedVideoTrack?.id !== track.id || videoModal.classList.contains('visible')) return;
      const row = videoLibList.querySelector('[data-track-id="' + track.id + '"]');
      if (row) {
        row.focus({ preventScroll: true });
        row.scrollIntoView({ block: 'center', behavior: 'instant' });
      }
      alignDesktopSidePanels();
    });
  }
  videoModalClose.addEventListener('click', closeVideoToTrack);
  videoPlayer.addEventListener('error', () => {
    if (!videoPlayer.getAttribute('src')) return;
    showToast("Cette vidéo n'est plus disponible (probablement purgée après 30 jours).", 'error');
    closeVideoToTrack();
  });
  // Quitter le plein écran réduit le lecteur sans interrompre le média.
  // (L'appli entière peut rester en plein écran, voir ui/immersive.js :
  // seul compte celui du lecteur.)
  document.addEventListener('fullscreenchange', () => {
    if (document.fullscreenElement !== videoModal) videoModal.classList.remove('video-immersive');
    if (document.fullscreenElement !== videoModal && screen.orientation && screen.orientation.unlock) {
      try { screen.orientation.unlock(); } catch (err) {}
    }
  });

  // --- Playlists ---
  // Une playlist n'est qu'un regroupement ordonné de fichiers déjà en
  // bibliothèque, audio et clips mélangés. La lecture route chaque élément
  // vers le lecteur qui lui correspond : mini-lecteur pour un MP3, modale
  // plein écran pour un clip.
  const playlistSheet = document.getElementById('playlist-sheet');
  const playlistSheetBody = document.getElementById('playlist-sheet-body');
  const playlistSheetTitle = document.getElementById('playlist-sheet-title');
  const playlistBackBtn = document.getElementById('playlist-back-btn');
  const playlistPlayBtn = document.getElementById('playlist-play-btn');
  const playlistRenameBtn = document.getElementById('playlist-rename-btn');
  const playlistDeleteBtn = document.getElementById('playlist-delete-btn');
  const playlistNewBtn = document.getElementById('playlist-new-btn');
  const playlistFab = document.getElementById('playlist-fab');
  const playlistPickModal = document.getElementById('playlist-pick-modal');
  const playlistPickList = document.getElementById('playlist-pick-list');
  const playlistPickNew = document.getElementById('playlist-pick-new');
  const playlistFocusToggle = document.getElementById('playlist-focus-toggle');
  playlistFocusToggle.checked = playlistFocusEnabled;
  playlistFocusToggle.addEventListener('change', () => {
    playlistFocusEnabled = playlistFocusToggle.checked;
    saveBoolPref('ytdl_playlist_focus', playlistFocusEnabled);
    if (playlistFocusEnabled && playlistContext) {
      // Activée alors qu'une playlist joue déjà : bascule tout de suite,
      // pas seulement au prochain lancement.
      renderPlaylistView();
      switchTab('playlist');
    } else if (!playlistFocusEnabled && isShowingPlaylistView()) {
      switchTab('audio');
    }
  });

  let playlists = [];
  let playlistMembership = {};
  let openedPlaylist = null;    // playlist affichée dans le panneau
  let playlistContext = null;   // playlist en cours de LECTURE (indépendante de l'affichage)
  let playlistCurrentId = null;
  let plannedPlaylistItem = null;
  let pickTargetTrack = null;
  const playlistCycle = createShuffleCycle();

  async function playlistApi(payload) {
    const res = await fetch('playlist-update.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || "L'opération a échoué.");
    return data;
  }

  async function loadPlaylists() {
    const res = await fetch('playlists.php');
    const data = await res.json();
    playlists = data.playlists || [];
    playlistMembership = data.membership || {};
    refreshPlaylistButtons();
    renderHomeShelf();
    return playlists;
  }

  // --- Bandeau d'accueil "Playlist au hasard" ---
  // Montre UNE des playlists de l'utilisateur (pas une sélection aléatoire de
  // morceaux) : tirée une fois au hasard, elle ne change plus tant qu'elle
  // existe toujours — un rafraîchissement de loadPlaylists() ailleurs dans
  // l'appli (ajout d'un morceau à une autre playlist, etc.) ne doit pas faire
  // sauter la vignette sous les yeux de l'utilisateur.
  const musicRecentSection = document.getElementById('music-recent');
  const musicRecentHeading = document.getElementById('music-recent-heading');
  const musicRecentSubtitle = document.getElementById('music-recent-subtitle');
  const musicShelf = document.getElementById('music-shelf');
  const musicShelfPrev = document.getElementById('music-shelf-prev');
  const musicShelfNext = document.getElementById('music-shelf-next');
  let homeShelfPlaylistId = null;
  let homeShelfSignature = '';

  // Flèches visibles seulement s'il y a vraiment de quoi défiler (assez de
  // titres pour déborder de la largeur visible), et chacune se cache toute
  // seule une fois arrivé à son extrémité — pas de flèche "suivant" qui ne
  // mène nulle part une fois au bout.
  function syncShelfArrows() {
    const canScroll = musicShelf.scrollWidth > musicShelf.clientWidth + 1;
    musicShelfPrev.hidden = !canScroll || musicShelf.scrollLeft <= 0;
    musicShelfNext.hidden = !canScroll || musicShelf.scrollLeft >= musicShelf.scrollWidth - musicShelf.clientWidth - 1;
  }
  musicShelf.addEventListener('scroll', syncShelfArrows, { passive: true });
  window.addEventListener('resize', syncShelfArrows);
  function scrollShelfByPage(direction) {
    musicShelf.scrollBy({ left: direction * musicShelf.clientWidth * 0.9, behavior: 'smooth' });
  }
  musicShelfPrev.addEventListener('click', () => scrollShelfByPage(-1));
  musicShelfNext.addEventListener('click', () => scrollShelfByPage(1));

  // Reflète l'état lecture/pause du morceau actif sur sa carte dans le
  // bandeau (icône ▶/❚❚), appelé depuis syncLibraryPlayingState() et après
  // chaque (re)rendu des cartes.
  function syncShelfPlayState() {
    musicShelf.querySelectorAll('.music-album').forEach(btn => {
      const active = Number(btn.dataset.trackId) === currentTrackId;
      btn.classList.toggle('playing', active);
      const icon = btn.querySelector('.music-play-icon');
      if (icon) icon.textContent = (active && !audioPlayer.paused) ? '❚❚' : '▶';
    });
  }

  function renderHomeShelfEmpty() {
    if (homeShelfSignature === 'empty') return;
    homeShelfSignature = 'empty';
    musicRecentSection.hidden = false;
    musicRecentHeading.textContent = 'Playlist au hasard';
    musicRecentSubtitle.textContent = "Tu n'as pas encore de playlist";
    musicShelf.innerHTML = '';
    const cta = document.createElement('button');
    cta.type = 'button';
    cta.className = 'music-shelf-empty-cta';
    cta.textContent = '+ Créer ma première playlist';
    cta.addEventListener('click', () => playlistFab.click());
    musicShelf.appendChild(cta);
  }

  // Durée du glissé du bandeau, selon le nombre de titres de la playlist
  // concernée : plus il y en a, plus vite ça glisse (un mouvement bref passe
  // inaperçu s'il n'y a presque rien à balayer, mais paraît juste vif quand
  // il y a beaucoup de pochettes à faire défiler) ; peu de titres → plus
  // lent, pour que le mouvement ait le temps de se voir.
  function shelfTransitionDuration(trackCount) {
    return Math.max(260, Math.min(720, 760 - trackCount * 9));
  }
  const SHELF_TRANSITION_EASE = 'cubic-bezier(.16,1,.3,1)'; // "expo out" : accélère à peine puis ralentit longuement, très fluide

  // animateIn par défaut à vrai : la playlist au hasard glisse depuis la
  // droite aussi bien au premier chargement de la page qu'à un rechargement
  // (dé) — seul le dé enchaîne en plus une sortie (playShelfExitAnimation)
  // avant, puisqu'il y a alors une playlist précédente à faire disparaître.
  function renderHomeShelfCards(playlist, animateIn = true) {
    musicRecentSection.hidden = false;
    musicRecentHeading.textContent = 'Playlist au hasard';
    musicRecentSubtitle.textContent =
      playlist.name + ' · ' + playlist.items.length + (playlist.items.length > 1 ? ' titres' : ' titre');
    musicShelf.innerHTML = '';
    const cards = [];
    playlist.items.forEach((track, index) => {
      const title = track.title || 'Sans titre';
      const button = document.createElement('button');
      button.type = 'button'; button.className = 'music-album';
      button.setAttribute('aria-label', 'Écouter ' + title);
      button.dataset.trackId = track.id;
      const cover = document.createElement('div'); cover.className = 'music-cover';
      const img = document.createElement('img'); img.src = track.thumbnail || ''; img.alt = ''; img.loading = 'lazy';
      img.addEventListener('error', () => { img.hidden = true; });
      const playIcon = document.createElement('span');
      playIcon.className = 'music-play-icon'; playIcon.textContent = '▶'; playIcon.setAttribute('aria-hidden', 'true');
      cover.append(img, playIcon);
      const text = document.createElement('span'); text.className = 'music-album-title'; text.textContent = title;
      const meta = document.createElement('span'); meta.className = 'music-album-meta'; meta.textContent = playlist.name;
      button.append(cover, text, meta);
      button.addEventListener('click', async () => {
        const playlistId = homeShelfPlaylistId;
        await startPlaylist(playlistId, index);
        if (!isDesktopLayout() || playlistContext?.id !== playlistId || playlistCurrentId !== track.id) return;
        const isVideo = !track.audio_only;
        const list = isVideo ? videoLibList : libList;
        // An old search must not hide the title selected from the shelf.
        const search = isVideo ? videoSearch : audioSearch;
        search.value = '';
        const input = document.getElementById(isVideo ? 'video-library-search-input' : 'library-search-input');
        if (input) input.value = '';
        applyLibraryFilter(list, '');
        switchTab(isVideo ? 'video' : 'audio');
        if (isVideo) syncVideoLibraryPlayingState(); else syncLibraryPlayingState();
        autoFocusImmediate = true;
        autoFocusIgnorePause = true;
        scheduleAutoFocusPlaying(isVideo ? 'video' : 'audio');
      });
      musicShelf.appendChild(button);
      cards.push(button);
    });
    syncShelfPlayState();
    requestAnimationFrame(syncShelfArrows);

    // Arrivée par la gauche : toutes les cartes démarrent décalées vers la
    // gauche (donc hors du cadre visible, coupées par le overflow-x du
    // bandeau) puis glissent ensemble vers la droite jusqu'à leur position
    // normale — un seul déplacement de groupe, pas une animation par carte.
    // Le décalage doit couvrir toute la LARGEUR DE CONTENU (scrollWidth), pas
    // seulement celle du cadre visible (clientWidth) : avec beaucoup de
    // titres, les cartes au-delà de la première vitrine sont déjà plus loin
    // à droite que le cadre — un décalage d'une seule largeur de cadre les
    // aurait fait *entrer* dans le cadre au lieu d'en sortir, laissant le
    // bandeau jamais complètement vide à l'écran. Sens choisi pour finir
    // dans le sens où le scroll-snap du bandeau se repose déjà (premier
    // titre, tout à gauche) : dans l'autre sens, la fin de la transition
    // coïncidait avec un recalage de scroll-snap sur le premier titre, donc
    // un petit sursaut.
    if (animateIn && cards.length) {
      const duration = shelfTransitionDuration(playlist.items.length);
      const shift = -(musicShelf.scrollWidth + 40);
      cards.forEach(card => {
        card.style.transition = 'none';
        card.style.transform = 'translateX(' + shift + 'px)';
      });
      void musicShelf.offsetWidth;
      cards.forEach(card => {
        card.style.transition = 'transform ' + duration + 'ms ' + SHELF_TRANSITION_EASE;
        card.style.transform = '';
      });
      setTimeout(() => {
        cards.forEach(card => { card.style.transition = ''; });
      }, duration + 40);
    }
  }

  // Fait glisser les cartes actuelles vers la gauche jusqu'à sortir du cadre
  // visible (coupées par le overflow-x du bandeau), avant qu'on les
  // remplace — pendant du symétrique "entrée" ci-dessus. `done` n'est appelé
  // qu'une fois la transition finie, pour ne remplacer le contenu qu'une
  // fois qu'il a fini de disparaître. Décalage sur scrollWidth, pas
  // clientWidth — même raison que dans renderHomeShelfCards ci-dessous.
  function playShelfExitAnimation(done) {
    const cards = Array.from(musicShelf.children).filter(el => el.classList.contains('music-album'));
    if (!cards.length) { done(); return; }
    const duration = shelfTransitionDuration(cards.length);
    const shift = -(musicShelf.scrollWidth + 40);
    cards.forEach(card => {
      card.style.transition = 'transform ' + duration + 'ms ' + SHELF_TRANSITION_EASE;
      card.style.transform = 'translateX(' + shift + 'px)';
    });
    setTimeout(done, duration);
  }

  // Molette de souris dans "Playlist au hasard" : redirige le défilement
  // vertical (celui d'une molette classique, sans axe horizontal dédié) en
  // défilement horizontal du bandeau, plutôt que de faire scroller la page
  // en dessous. Un geste déjà horizontal (trackpad, Shift+molette) n'est
  // pas touché : le navigateur s'en charge nativement.
  //
  // Inertie : chaque cran de molette ACCUMULE de la vitesse plutôt que de
  // déplacer scrollLeft d'un bloc — une seule boucle de freinage (friction
  // 0.95/frame) l'écoule ensuite. Tourner vite empile plusieurs crans avant
  // que la boucle ait eu le temps de freiner, donc le bandeau continue de
  // glisser un instant après qu'on ait lâché la molette ; un cran isolé,
  // lui, s'arrête vite — comme avant, mais jamais sec.
  //
  // .music-shelf a un scroll-snap-type (voir music.css) : le navigateur tente
  // sinon de "corriger" scrollLeft vers la carte la plus proche à CHAQUE
  // frame pendant qu'on l'anime nous-mêmes à la main, ce qui donnait un
  // mouvement saccadé au lieu d'un vrai glissé. On le coupe pendant la
  // glisse, il revient se réaccrocher tout seul (proximity, pas mandatory)
  // une fois la vitesse retombée à zéro.
  let shelfWheelVelocity = 0;
  let shelfInertiaFrame = null;
  function stepShelfInertia() {
    musicShelf.scrollLeft += shelfWheelVelocity;
    shelfWheelVelocity *= 0.95;
    if (Math.abs(shelfWheelVelocity) > 0.4) {
      shelfInertiaFrame = requestAnimationFrame(stepShelfInertia);
    } else {
      shelfWheelVelocity = 0;
      shelfInertiaFrame = null;
      musicShelf.classList.remove('no-snap');
    }
  }
  musicShelf.addEventListener('wheel', e => {
    if (musicShelf.scrollWidth <= musicShelf.clientWidth) return;
    if (Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;
    e.preventDefault();
    musicShelf.classList.add('no-snap');
    shelfWheelVelocity += e.deltaY * 0.5;
    if (!shelfInertiaFrame) shelfInertiaFrame = requestAnimationFrame(stepShelfInertia);
  }, { passive: false });

  function renderHomeShelf() {
    if (playlists.length === 0) {
      homeShelfPlaylistId = null;
      renderHomeShelfEmpty();
      return;
    }
    // Ne tire au hasard que si rien n'est encore choisi, ou si le choix
    // précédent a disparu (playlist supprimée) — jamais à chaque appel.
    if (homeShelfPlaylistId === null || !playlists.some(p => p.id === homeShelfPlaylistId)) {
      homeShelfPlaylistId = playlists[Math.floor(Math.random() * playlists.length)].id;
      homeShelfSignature = '';
    }
    const meta = playlists.find(p => p.id === homeShelfPlaylistId);
    const signature = homeShelfPlaylistId + ':' + (meta ? meta.item_count : 0);
    if (signature === homeShelfSignature) return;
    homeShelfSignature = signature;

    fetchPlaylist(homeShelfPlaylistId).then(playlist => {
      if (!playlist || playlist.items.length === 0) {
        // Playlist vide (ou disparue pendant le chargement) : on retente
        // avec ce qu'il reste plutôt que de laisser le bandeau vide.
        homeShelfPlaylistId = null;
        homeShelfSignature = '';
        renderHomeShelf();
        return;
      }
      renderHomeShelfCards(playlist);
    }).catch(() => {});
  }

  // Jet de dé physique : le dé est lancé vers la gauche jusqu'au bord gauche
  // du nom de la playlist (distance mesurée en direct, donc valable quelle
  // que soit la taille d'écran), puis un système ressort/amortisseur le
  // ramène à sa case de départ — un ressort sous-amorti dépasse naturellement
  // sa cible avant de s'y stabiliser, ce qui donne le rebond de retour demandé
  // sans la moindre clé d'animation écrite à la main.
  //
  // zeta/wn choisis pour un seul rebond net et un temps d'apaisement mesuré
  // (simulation) d'environ 2,3 s pour un jet sur toute la largeur du titre —
  // large marge sous la limite des 4 s demandée. peakFactor est le
  // déplacement maximal que produirait CE ressort pour une vitesse initiale
  // de 1 px/s (calcul exact pour un oscillateur amorti) : comme le système
  // est linéaire, la vitesse à donner pour atteindre pile la distance voulue
  // est distance/peakFactor.
  function throwDice(el) {
    const heading = document.getElementById('music-recent-heading');
    const distance = heading.getBoundingClientRect().left - el.getBoundingClientRect().left;

    const zeta = 0.55, wn = 4.4;
    const wd = wn * Math.sqrt(1 - zeta * zeta);
    const tPeak = Math.atan(wd / (zeta * wn)) / wd;
    const peakFactor = Math.exp(-zeta * wn * tPeak) * Math.sin(wd * tPeak) / wd;
    const vx0 = distance / peakFactor;

    let x = 0, vx = vx0;
    const springK = wn * wn, damping = 2 * zeta * wn;
    let angle = 0, angularVel = distance < 0 ? -2400 : 2400; // tourne dans le sens du jet
    const angularDecayRate = 3; // par seconde, décroissance exponentielle — réglé pour s'éteindre vers le même moment que le ressort
    const t0 = performance.now();
    let lastT = t0;
    let settled = false;
    function frame(now) {
      const dt = Math.min((now - lastT) / 1000, 0.032);
      lastT = now;
      const accel = -springK * x - damping * vx;
      vx += accel * dt;
      x += vx * dt;
      angularVel *= Math.exp(-angularDecayRate * dt);
      angle += angularVel * dt;
      el.style.transform = 'translateX(' + x.toFixed(2) + 'px) rotate(' + angle.toFixed(2) + 'deg)';
      const atRest = Math.abs(x) < 0.8 && Math.abs(vx) < 8 && Math.abs(angularVel) < 6;
      // Garde-fou à 3,5 s : même si la physique traînait, l'animation ne
      // dépasse jamais les 4 s demandées.
      if (!settled && !atRest && now - t0 < 3500) {
        requestAnimationFrame(frame);
      } else if (!settled) {
        // Fin de course : un dernier petit glissé CSS vers le repos exact
        // (x=0, angle au tour complet le plus proche) plutôt qu'un arrêt net.
        settled = true;
        const target = Math.round(angle / 360) * 360;
        el.style.transition = 'transform .2s ease-out';
        el.style.transform = 'translateX(0px) rotate(' + target + 'deg)';
        setTimeout(() => { el.style.transition = ''; el.style.transform = ''; }, 210);
      }
    }
    requestAnimationFrame(frame);
  }

  // Fait afficher une playlist précise dans le bandeau "Playlist au hasard",
  // avec la même transition que le dé (fermeture vers la gauche puis
  // réouverture depuis la gauche — voir playShelfExitAnimation/
  // renderHomeShelfCards) : réutilisée par le dé lui-même (qui tire un id au
  // hasard) ET par startPlaylist() (voir plus bas), pour que lancer une
  // playlist depuis N'IMPORTE OÙ (barre d'action "Votre bibliothèque",
  // feuille "Mes playlists"...) mette aussi à jour ce bandeau plutôt que de
  // le laisser figé sur une playlist qui n'est plus celle en cours.
  // Sans effet si c'est déjà la playlist affichée, ou si une transition est
  // déjà en cours (shelfRerollBusy) — jamais deux jeux de cartes mélangés.
  let shelfRerollBusy = false;
  function swapHomeShelfPlaylist(newId) {
    if (newId === null || newId === homeShelfPlaylistId || shelfRerollBusy) return;
    shelfRerollBusy = true;
    homeShelfPlaylistId = newId;
    homeShelfSignature = '';

    playShelfExitAnimation(() => {
      fetchPlaylist(homeShelfPlaylistId).then(playlist => {
        if (!playlist || playlist.items.length === 0) {
          homeShelfPlaylistId = null;
          homeShelfSignature = '';
          renderHomeShelf();
          return;
        }
        homeShelfSignature = homeShelfPlaylistId + ':' + playlist.items.length;
        renderHomeShelfCards(playlist, true);
      }).catch(() => {}).finally(() => { shelfRerollBusy = false; });
    });
  }

  // Dé à côté du titre : retire une AUTRE playlist que celle affichée (s'il y
  // en a plusieurs — sinon rien à changer). Le dé est "jeté" pendant que le
  // bandeau se referme vers le centre puis se rouvre avec la nouvelle
  // playlist.
  const musicShelfReroll = document.getElementById('music-shelf-reroll');
  musicShelfReroll.addEventListener('click', () => {
    if (playlists.length < 2 || shelfRerollBusy) return;
    musicShelfReroll.style.transition = '';
    throwDice(musicShelfReroll);
    const others = playlists.filter(p => p.id !== homeShelfPlaylistId);
    swapHomeShelfPlaylist(others[Math.floor(Math.random() * others.length)].id);
  });

  function isTrackInPlaylist(id) {
    const list = playlistMembership[String(id)];
    return Array.isArray(list) && list.length > 0;
  }

  // Repasse sur les boutons déjà rendus : la bibliothèque s'affiche souvent
  // avant que les playlists soient chargées, et l'appartenance change sans
  // qu'on re-rende toute la liste.
  function refreshPlaylistButtons() {
    document.querySelectorAll('.library-playlist-btn').forEach(btn => {
      btn.classList.toggle('in-playlist', isTrackInPlaylist(btn.dataset.trackId));
    });
  }

  // --- Panneau ---
  function openPlaylistSheet() {
    openedPlaylist = null;
    playlistSheet.classList.add('visible');
    renderPlaylistList();
    loadPlaylists().then(renderPlaylistList).catch(() => {
      playlistSheetBody.innerHTML = '<div class="sheet-empty">Impossible de charger les playlists.</div>';
    });
  }
  function closePlaylistSheet() {
    playlistSheet.classList.remove('visible');
  }

  function setSheetMode(detail) {
    // Toute bascule de vue annule une saisie en cours — les champs sont remis
    // à plat ici plutôt que par les fonctions de fermeture, qui appellent
    // elles-mêmes setSheetMode (la récursion serait sans fin).
    playlistSheetTitle.style.display = '';
    playlistRenameInput.style.display = 'none';
    playlistRenameConfirm.style.display = 'none';
    playlistNewForm.style.display = 'none';

    playlistBackBtn.style.display = detail ? 'flex' : 'none';
    playlistPlayBtn.style.display = detail ? 'flex' : 'none';
    playlistRenameBtn.style.display = detail ? 'flex' : 'none';
    playlistDeleteBtn.style.display = detail ? 'flex' : 'none';
    playlistNewBtn.style.display = detail ? 'none' : 'block';
  }

  // Dégradés inspirés de la palette déjà utilisée pour les pochettes de
  // l'accueil (music.css) : une playlist sans image reste identifiable au
  // premier coup d'œil, plutôt qu'une ligne de texte indifférenciée.
  const PLAYLIST_COVER_GRADIENTS = [
    'linear-gradient(135deg,#a95839,#453c64)',
    'linear-gradient(135deg,#2f6b4f,#1d3557)',
    'linear-gradient(135deg,#8a4b7a,#3a2e5c)',
    'linear-gradient(135deg,#c97b3d,#5a3a2e)',
  ];
  function playlistCoverStyle(id) {
    const idx = Math.abs(Number(id) || 0) % PLAYLIST_COVER_GRADIENTS.length;
    return PLAYLIST_COVER_GRADIENTS[idx];
  }

  function renderPlaylistList() {
    openedPlaylist = null;
    setSheetMode(false);
    playlistSheetTitle.textContent = 'Mes playlists';
    playlistSheetBody.innerHTML = '';

    if (playlists.length === 0) {
      playlistSheetBody.innerHTML = '<div class="sheet-empty"><div class="sheet-empty-icon">🎵</div>Aucune playlist. Crée-en une, puis ajoute des morceaux avec le bouton ☰+ de la bibliothèque.</div>';
      return;
    }

    playlists.forEach(pl => {
      const row = document.createElement('div');
      row.className = 'playlist-row';
      // Mosaïque de 4 vignettes piochées dans la playlist plutôt que la note
      // ♪ générique — seulement quand il y en a vraiment 4 à montrer, sinon
      // 2 ou 3 cases resteraient vides et la mosaïque aurait l'air cassée.
      const thumbs = pl.thumbnails || [];
      const cover = thumbs.length === 4
        ? '<div class="playlist-row-cover playlist-row-cover-mosaic">' +
            thumbs.map(t => '<img src="' + t + '" alt="" loading="lazy">').join('') +
          '</div>'
        : '<div class="playlist-row-cover" style="background:' + playlistCoverStyle(pl.id) + '">♪</div>';
      row.innerHTML =
        cover +
        '<div class="playlist-row-info">' +
          '<div class="playlist-row-name"></div>' +
          '<div class="playlist-row-count">' + pl.item_count + (pl.item_count > 1 ? ' éléments' : ' élément') + '</div>' +
        '</div>' +
        '<div class="playlist-row-actions">' +
          '<button type="button" class="playlist-row-play" title="Lire">▶</button>' +
        '</div>';
      row.querySelector('.playlist-row-name').textContent = pl.name;
      row.querySelector('.playlist-row-play').addEventListener('click', e => {
        e.stopPropagation();
        startPlaylist(pl.id);
      });
      row.addEventListener('click', () => openPlaylistDetail(pl.id));
      playlistSheetBody.appendChild(row);
    });
  }

  async function fetchPlaylist(id) {
    const res = await fetch('playlists.php?id=' + encodeURIComponent(id));
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Playlist introuvable.');
    return data.playlist;
  }

  async function openPlaylistDetail(id) {
    playlistSheetBody.innerHTML = '<div class="sheet-empty">Chargement…</div>';
    try {
      openedPlaylist = await fetchPlaylist(id);
      renderPlaylistDetail();
    } catch (err) {
      showToast(err.message, 'error');
      renderPlaylistList();
    }
  }

  function renderPlaylistDetail() {
    setSheetMode(true);
    playlistSheetTitle.textContent = openedPlaylist.name;
    playlistSheetBody.innerHTML = '';

    if (openedPlaylist.items.length === 0) {
      playlistSheetBody.innerHTML = '<div class="sheet-empty"><div class="sheet-empty-icon">🎵</div>Playlist vide.</div>';
      return;
    }

    openedPlaylist.items.forEach((item, index) => {
      const row = document.createElement('div');
      row.className = 'playlist-item' + (playlistContext && playlistContext.id === openedPlaylist.id && item.id === playlistCurrentId ? ' playing' : '');
      row.innerHTML =
        '<div class="playlist-item-index">' + (index + 1) + '</div>' +
        '<img src="' + (item.thumbnail || '') + '" alt="">' +
        '<div class="playlist-item-title"></div>' +
        '<div class="playlist-item-kind">' + (item.audio_only ? '♪' : '▶') + '</div>' +
        '<button type="button" class="sheet-btn danger playlist-item-remove" title="Retirer de la playlist">✕</button>';
      row.querySelector('.playlist-item-title').textContent = item.title || 'Sans titre';
      row.querySelector('.playlist-item-remove').addEventListener('click', async e => {
        e.stopPropagation();
        try {
          await playlistApi({ action: 'remove_item', id: openedPlaylist.id, history_id: item.id });
          openedPlaylist.items.splice(index, 1);
          if (playlistContext && playlistContext.id === openedPlaylist.id) {
            playlistContext.items = openedPlaylist.items.slice();
          }
          renderPlaylistDetail();
          loadPlaylists();
        } catch (err) { showToast(err.message, 'error'); }
      });
      row.addEventListener('click', () => startPlaylist(openedPlaylist.id, index));
      playlistSheetBody.appendChild(row);
    });
  }

  // --- Lecture ---
  async function startPlaylist(id, startIndex = null) {
    try {
      const playlist = await fetchPlaylist(id);
      if (playlist.items.length === 0) {
        showToast('Cette playlist est vide.', 'error');
        return;
      }
      playlistContext = playlist;
      playlistCurrentId = null;
      playlistFab.classList.add('active');
      // Un clic sur un élément précis démarre là (y compris le tout premier,
      // index 0 — d'où le null par défaut plutôt que 0 pour distinguer "aucun
      // choix précis" du "premier morceau cliqué") ; le bouton ▶ de la
      // playlist, lui, démarre au début, ou au hasard si l'aléatoire est actif.
      const first = (shuffleEnabled && startIndex === null)
        ? playlistCycle.pick(playlist.items, null)
        : playlist.items[Math.min(startIndex, playlist.items.length - 1)];
      playPlaylistItem(first, true);
      closePlaylistSheet();
      showToast('Lecture de « ' + playlist.name + ' »', 'info');
      // Le bandeau "Playlist au hasard" doit refléter la playlist qu'on
      // vient de lancer, quel que soit le point d'entrée (barre d'action
      // "Votre bibliothèque", feuille "Mes playlists"...) — sans effet s'il
      // s'agit déjà de la playlist affichée (clic sur une carte du bandeau
      // lui-même) ou si une autre transition du bandeau est en cours.
      swapHomeShelfPlaylist(id);
      if (playlistFocusEnabled) {
        renderPlaylistView();
        switchTab('playlist');
        // switchTab() ne rejoue PAS l'animation d'entrée (tabPanelIn, voir
        // .library-section.active-tab) si la vue était déjà active — or
        // c'est justement le cas ici en enchaînant deux playlists sans
        // repasser par Audio/Clips entre les deux : rien ne signalait alors
        // visuellement qu'une nouvelle playlist venait de se charger. On
        // force donc le rejeu (retrait puis remise de la classe après un
        // reflow), que la vue ait changé ou non — mais différé de la durée
        // de fermeture de la feuille "Mes playlists" (.bottom-sheet, 0.4s) :
        // sans ce délai, l'animation démarrait ET finissait cachée SOUS la
        // feuille encore visible, donc jamais perçue une fois celle-ci
        // effectivement disparue de l'écran.
        setTimeout(() => {
          // Garde-fou : si l'utilisateur a changé d'onglet pendant ce délai
          // de 400ms (Audio, Clips, Others...), il ne faut surtout pas le
          // ramener de force sur la vue playlist — cette classe a déjà été
          // correctement mise à false par le switchTab() qu'il a déclenché.
          if (!isShowingPlaylistView()) return;
          playlistViewSection.classList.remove('active-tab');
          void playlistViewSection.offsetWidth;
          playlistViewSection.classList.add('active-tab');
        }, 400);
      }
    } catch (err) {
      showToast(err.message, 'error');
    }
  }

  function exitPlaylistContext() {
    playlistContext = null;
    playlistCurrentId = null;
    plannedPlaylistItem = null;
    playlistFab.classList.remove('active');
    // La vue playlist n'a plus rien à montrer une fois la lecture arrêtée —
    // jamais déclenché si l'onglet actif est déjà Audio/Clips (l'utilisateur
    // y a cliqué lui-même avant de lancer un morceau normal, switchTab
    // n'aurait alors rien à faire).
    if (isShowingPlaylistView()) switchTab('audio');
  }

  // Variante de exitPlaylistContext() SANS le changement de vue : utilisée
  // quand on lance un morceau trouvé par la recherche (croisée ou globale)
  // alors qu'il appartient à une autre playlist ou à la bibliothèque
  // partagée d'un autre compte. Le contexte de lecture (suivant/précédent)
  // doit bien se détacher de l'ancienne playlist, mais la playlist qu'on
  // était en train de REGARDER ne doit pas pour autant disparaître au
  // profit de la bibliothèque — juste trouver/lancer ce morceau ailleurs
  // n'est pas une raison de la faire disparaître.
  function clearPlaylistPlaybackContext() {
    playlistContext = null;
    playlistCurrentId = null;
    plannedPlaylistItem = null;
    playlistFab.classList.remove('active');
  }

  // Élément suivant dans la playlist. `wrap` distingue les deux usages, comme
  // pour la bibliothèque : un clic sur ⏭ reboucle, l'enchaînement automatique
  // s'arrête au bout.
  function computePlaylistNext(step, wrap) {
    if (!playlistContext) return null;
    const items = playlistContext.items;
    if (items.length === 0) return null;
    // Aléatoire : même historique de navigation que la bibliothèque — ⏮
    // revient sur ce qui a réellement été joué, ⏭ redescend la branche
    // quittée avant de tirer un nouvel élément.
    if (shuffleEnabled) {
      return (step < 0 ? playlistCycle.previous(items) : playlistCycle.forward(items))
        || playlistCycle.pick(items, playlistCurrentId);
    }

    const index = items.findIndex(i => i.id === playlistCurrentId);
    if (index < 0) return items[0];
    const next = index + step;
    if (next < 0) return wrap ? items[items.length - 1] : null;
    if (next >= items.length) return wrap ? items[0] : null;
    return items[next];
  }

  // Le suivant est désigné dès que l'élément courant démarre : c'est ce qui
  // permet au préchargement audio et à l'enchaînement de viser le même
  // fichier, y compris en lecture aléatoire.
  function playlistNext(step, wrap) {
    if (step === 1 && plannedPlaylistItem
        && playlistContext.items.some(i => i.id === plannedPlaylistItem.id)) {
      return plannedPlaylistItem;
    }
    return computePlaylistNext(step, wrap);
  }

  function playPlaylistItem(item, goFullscreen) {
    if (!item) return;
    playlistCurrentId = item.id;
    playlistCycle.markPlayed(item.id);
    plannedPlaylistItem = computePlaylistNext(1, false); // avant de jouer : le préchargement le lit aussitôt

    if (item.audio_only) {
      // Un clip était peut-être à l'écran : on referme avant de passer à l'audio.
      if (videoModal.classList.contains('visible')) {
        videoPlayer.pause();
        videoPlayer.removeAttribute('src');
        videoModal.classList.remove('visible', 'video-immersive', 'video-controls-hidden');
        currentVideoId = null;
      }
      playTrack(item);
    } else {
      playVideo(item, goFullscreen);
    }

    if (openedPlaylist && playlistContext && openedPlaylist.id === playlistContext.id) {
      renderPlaylistDetail(); // met à jour la ligne en surbrillance
    }
    // Idem pour la vue en page : avance automatique, ⏮/⏭, ou clic sur un
    // autre morceau de la même playlist doivent y déplacer le surlignage.
    if (isShowingPlaylistView()) renderPlaylistView();
  }

  // Appelé par les boutons ⏮/⏭ des deux lecteurs et par les fins de lecture.
  function advancePlaylist(step, wrap) {
    const next = playlistNext(step, wrap);
    if (next) playPlaylistItem(next, false);
    return !!next;
  }

  // --- Ajout d'un morceau (bouton ☰+ des lignes de bibliothèque) ---
  function openAddToPlaylist(track) {
    pickTargetTrack = track;
    document.getElementById('playlist-pick-title').textContent = track.title || 'Sans titre';
    playlistPickNew.value = '';
    playlistPickList.innerHTML = '<div class="sheet-empty">Chargement…</div>';
    playlistPickModal.classList.add('visible');

    loadPlaylists().then(renderPickList).catch(() => {
      playlistPickList.innerHTML = '<div class="sheet-empty">Impossible de charger les playlists.</div>';
    });
  }

  function renderPickList() {
    if (!pickTargetTrack) return;
    const checked = playlistMembership[String(pickTargetTrack.id)] || [];
    playlistPickList.innerHTML = '';

    if (playlists.length === 0) {
      playlistPickList.innerHTML = '<div class="sheet-empty">Aucune playlist pour l\'instant — donne un nom ci-dessous.</div>';
      return;
    }

    playlists.forEach(pl => {
      // Un même titre peut appartenir à plusieurs playlists : chacune est
      // cochée indépendamment, rien n'est exclusif.
      const isIn = checked.includes(pl.id);
      const label = document.createElement('label');
      label.className = 'playlist-pick' + (isIn ? ' checked' : '');
      label.innerHTML =
        '<input type="checkbox" value="' + pl.id + '"' + (isIn ? ' checked' : '') + '>' +
        '<span></span>';
      label.querySelector('span').textContent = pl.name;
      // Enregistrement immédiat : cocher une playlist y range le morceau sur
      // le champ, sans validation supplémentaire.
      label.querySelector('input').addEventListener('change', e => {
        label.classList.toggle('checked', e.target.checked);
        savePickMembership(pl, e.target.checked, label);
      });
      playlistPickList.appendChild(label);
    });
  }

  // Envoie l'état courant des cases. `changed` sert uniquement au message de
  // confirmation, et à rétablir la case si le serveur refuse.
  async function savePickMembership(changed, isChecked, label) {
    if (!pickTargetTrack) return;
    const ids = Array.from(playlistPickList.querySelectorAll('input:checked')).map(i => Number(i.value));
    try {
      await playlistApi({ action: 'set_membership', history_id: pickTargetTrack.id, playlist_ids: ids });
      playlistMembership[String(pickTargetTrack.id)] = ids;
      refreshPlaylistButtons(); // le bouton de la ligne passe au vert, ou le quitte
      if (changed) {
        showToast((isChecked ? 'Ajouté à « ' : 'Retiré de « ') + changed.name + ' »', 'success');
        // Le geste est terminé dès que le morceau est rangé : on referme.
        // Un retrait, lui, laisse la fenêtre ouverte : le titre n'est alors
        // peut-être plus dans aucune playlist, et il faut pouvoir en cocher
        // une autre dans la foulée.
        if (isChecked) playlistPickModal.classList.remove('visible');
      }
      loadPlaylists();
      // La playlist en cours de lecture peut être celle qu'on vient de modifier.
      if (playlistContext && (changed ? playlistContext.id === changed.id : ids.includes(playlistContext.id))) {
        fetchPlaylist(playlistContext.id).then(p => { playlistContext.items = p.items; }).catch(() => {});
      }
    } catch (err) {
      showToast(err.message, 'error');
      // Case ET mise en évidence remises dans leur état d'avant : l'affichage
      // ne doit pas prétendre un enregistrement qui n'a pas eu lieu.
      if (changed) {
        const box = playlistPickList.querySelector('input[value="' + changed.id + '"]');
        if (box) box.checked = !isChecked;
        if (label) label.classList.toggle('checked', !isChecked);
      }
    }
  }

  document.getElementById('playlist-pick-close').addEventListener('click', () => {
    playlistPickModal.classList.remove('visible');
  });

  // Le champ ne sert plus qu'à créer : la nouvelle playlist est créée, cochée
  // et enregistrée dans la foulée, sans quitter la fenêtre.
  async function createAndAddPlaylist() {
    if (!pickTargetTrack) return;
    const name = playlistPickNew.value.trim();
    if (name === '') { playlistPickNew.focus(); return; }
    try {
      const created = await playlistApi({ action: 'create', name });
      const ids = Array.from(playlistPickList.querySelectorAll('input:checked')).map(i => Number(i.value));
      if (!ids.includes(created.id)) ids.push(created.id);
      await playlistApi({ action: 'set_membership', history_id: pickTargetTrack.id, playlist_ids: ids });
      playlistMembership[String(pickTargetTrack.id)] = ids;
      refreshPlaylistButtons();
      playlistPickNew.value = '';
      showToast('Ajouté à « ' + created.name + ' »', 'success');
      playlistPickModal.classList.remove('visible'); // morceau rangé : idem
      await loadPlaylists();
      renderPickList();   // la nouvelle playlist apparaît cochée à la réouverture
    } catch (err) {
      showToast(err.message, 'error');
    }
  }

  document.getElementById('playlist-pick-create').addEventListener('click', createAndAddPlaylist);
  playlistPickNew.addEventListener('keydown', e => {
    if (e.key === 'Enter') createAndAddPlaylist();
  });

  // --- Boutons du panneau ---
  playlistFab.addEventListener('click', openPlaylistSheet);
  document.getElementById('playlist-close-btn').addEventListener('click', closePlaylistSheet);
  playlistBackBtn.addEventListener('click', renderPlaylistList);
  playlistSheet.addEventListener('click', e => { if (e.target === playlistSheet) closePlaylistSheet(); });

  playlistPlayBtn.addEventListener('click', () => { if (openedPlaylist) startPlaylist(openedPlaylist.id); });

  // --- Création : le bouton laisse place à un champ, dans le panneau ---
  const playlistNewForm = document.getElementById('playlist-new-form');
  const playlistNewInput = document.getElementById('playlist-new-input');

  function openNewPlaylistForm() {
    playlistNewBtn.style.display = 'none';
    playlistNewForm.style.display = 'flex';
    playlistNewInput.value = '';
    playlistNewInput.focus();
  }
  function closeNewPlaylistForm() {
    playlistNewForm.style.display = 'none';
    // Rétabli seulement en vue liste : la vue détail n'affiche pas ce bouton.
    playlistNewBtn.style.display = openedPlaylist ? 'none' : 'block';
  }
  async function submitNewPlaylist() {
    const name = playlistNewInput.value.trim();
    if (name === '') { playlistNewInput.focus(); return; }
    try {
      await playlistApi({ action: 'create', name });
      await loadPlaylists();
      renderPlaylistList();      // remet le panneau en vue liste
      closeNewPlaylistForm();
    } catch (err) { showToast(err.message, 'error'); }
  }

  playlistNewBtn.addEventListener('click', openNewPlaylistForm);
  document.getElementById('playlist-new-confirm').addEventListener('click', submitNewPlaylist);
  document.getElementById('playlist-new-cancel').addEventListener('click', closeNewPlaylistForm);
  playlistNewInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') submitNewPlaylist();
    if (e.key === 'Escape') closeNewPlaylistForm();
  });

  // --- Renommage : le titre du panneau devient éditable sur place ---
  const playlistRenameInput = document.getElementById('playlist-rename-input');
  const playlistRenameConfirm = document.getElementById('playlist-rename-confirm');

  function openRenameField() {
    if (!openedPlaylist) return;
    playlistSheetTitle.style.display = 'none';
    playlistRenameInput.style.display = 'block';
    playlistRenameInput.value = openedPlaylist.name;
    playlistRenameConfirm.style.display = 'flex';
    // Les actions de la playlist n'ont pas de sens pendant la saisie.
    playlistPlayBtn.style.display = 'none';
    playlistRenameBtn.style.display = 'none';
    playlistDeleteBtn.style.display = 'none';
    playlistRenameInput.focus();
    playlistRenameInput.select();
  }
  function closeRenameField() {
    playlistSheetTitle.style.display = '';
    playlistRenameInput.style.display = 'none';
    playlistRenameConfirm.style.display = 'none';
    setSheetMode(!!openedPlaylist);
  }
  async function submitRename() {
    if (!openedPlaylist) return;
    const name = playlistRenameInput.value.trim();
    if (name === '') { playlistRenameInput.focus(); return; }
    if (name === openedPlaylist.name) { closeRenameField(); return; }
    try {
      const data = await playlistApi({ action: 'rename', id: openedPlaylist.id, name });
      openedPlaylist.name = data.name;
      if (playlistContext && playlistContext.id === openedPlaylist.id) playlistContext.name = data.name;
      playlistSheetTitle.textContent = data.name;
      closeRenameField();
      await loadPlaylists();
    } catch (err) { showToast(err.message, 'error'); }
  }

  playlistRenameBtn.addEventListener('click', openRenameField);
  playlistRenameConfirm.addEventListener('click', submitRename);
  playlistRenameInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') submitRename();
    if (e.key === 'Escape') closeRenameField();
  });

  // --- Suppression : même modale de confirmation que pour un fichier ---
  const playlistDeleteModal = document.getElementById('playlist-delete-modal');

  playlistDeleteBtn.addEventListener('click', () => {
    if (!openedPlaylist) return;
    document.getElementById('playlist-delete-name').textContent = openedPlaylist.name;
    playlistDeleteModal.classList.add('visible');
  });
  document.getElementById('playlist-delete-cancel').addEventListener('click', () => {
    playlistDeleteModal.classList.remove('visible');
  });
  document.getElementById('playlist-delete-confirm').addEventListener('click', async () => {
    if (!openedPlaylist) return;
    try {
      // Les fichiers ne sont pas concernés : seul le regroupement disparaît.
      await playlistApi({ action: 'delete', id: openedPlaylist.id });
      if (playlistContext && playlistContext.id === openedPlaylist.id) exitPlaylistContext();
      playlistDeleteModal.classList.remove('visible');
      await loadPlaylists();
      renderPlaylistList();
      showToast('Playlist supprimée.', 'success');
    } catch (err) { showToast(err.message, 'error'); }
  });

  const librarySearchCtl = setupLibrarySearch(null, 'library-search-input', libList, audioSearch, 'library-search-shell', 'library-search-clear', 'library-yt-suggest', true);
  const videoLibrarySearchCtl = setupLibrarySearch(null, 'video-library-search-input', videoLibList, videoSearch, 'video-library-search-shell', 'video-library-search-clear', 'video-library-yt-suggest', false);

  // --- Recherche globale (overlay) ---
  // Seul point d'entrée depuis "Others" ou la vue playlist en cours : ni
  // l'un ni l'autre n'a de champ de recherche propre, mais changer d'onglet
  // pour aller en ouvrir un ferait disparaître la playlist en cours de
  // lecture qu'on est justement en train de regarder. Un overlay ne touche
  // à rien en dessous — fermer la recherche laisse tout exactement comme
  // avant. Cherche dans l'ENSEMBLE de la bibliothèque (audio+clips+others) ;
  // pas de repli YouTube ici, contrairement aux recherches propres à chaque
  // onglet — celles-ci restent le bon endroit pour ça.
  const globalSearchSheet = document.getElementById('global-search-sheet');
  const globalSearchInput = document.getElementById('global-search-input');
  const globalSearchClear = document.getElementById('global-search-clear');
  const globalSearchShell = document.getElementById('global-search-shell');
  const globalSearchResults = document.getElementById('global-search-results');

  function renderGlobalSearchResults(query) {
    const q = normalizeSearchText(query.trim());
    globalSearchResults.innerHTML = '';
    globalSearchShell.classList.toggle('has-value', query.trim() !== '');
    if (q === '') return;
    const allTracks = rawAudioTracks.concat(rawVideoTracks, rawOtherTracks);
    const matches = allTracks.filter(track => {
      const rowParts = splitArtistTitle(track);
      const title = normalizeSearchText(rowParts.artist ? (rowParts.artist + ' — ' + rowParts.title) : (track.title || ''));
      return title.includes(q);
    });
    if (matches.length === 0) {
      globalSearchResults.innerHTML = '<div class="library-empty">Aucun résultat.</div>';
      return;
    }
    matches.forEach(track => globalSearchResults.appendChild(buildCrossTypeItem(track)));
  }

  function openGlobalSearch() {
    globalSearchSheet.classList.add('visible');
    globalSearchInput.value = '';
    renderGlobalSearchResults('');
    globalSearchInput.focus();
  }
  function closeGlobalSearch() {
    globalSearchSheet.classList.remove('visible');
  }
  globalSearchInput.addEventListener('input', () => renderGlobalSearchResults(globalSearchInput.value));
  globalSearchClear.addEventListener('click', () => {
    globalSearchInput.value = '';
    renderGlobalSearchResults('');
    globalSearchInput.focus();
  });
  document.getElementById('global-search-close').addEventListener('click', closeGlobalSearch);
  globalSearchSheet.addEventListener('click', e => { if (e.target === globalSearchSheet) closeGlobalSearch(); });

  // Loupe unique (barre d'onglets, avant le bouton playlists) : ouvre la
  // recherche adaptée à l'onglet actuellement affiché — celle d'Audio ou de
  // Clips (avec repli YouTube, recherche croisée...) si l'un des deux est
  // actif, sinon la recherche globale en overlay ("Others"/playlist, qui
  // n'ont pas de champ dédié) plutôt que de changer d'onglet pour y accéder.
  const headerSearchBtn = document.getElementById('header-search-btn');
  headerSearchBtn.addEventListener('click', () => {
    if (tabButtons.video.classList.contains('active')) {
      videoLibrarySearchCtl.open();
    } else if (tabButtons.audio.classList.contains('active')) {
      librarySearchCtl.open();
    } else {
      openGlobalSearch();
    }
  });

  // --- Onglets Audio / Clips ---
  // Remplace l'empilement vertical des deux listes : sur mobile, il fallait
  // traverser toute la bibliothèque au scroll rien que pour atteindre le bas
  // de la page. Une seule catégorie visible à la fois désormais. Les fichiers
  // partagés avec moi vivent directement dans ces deux listes (repérables à
  // la ligne "Partagé par XXX"), plus besoin d'un onglet dédié.
  const libraryTabs = document.getElementById('library-tabs');
  const playlistViewSection = document.getElementById('playlist-view-section');
  const othersLibSection = document.getElementById('others-library-section');
  const tabPanels = {
    audio: libSection,
    video: videoLibSection,
    others: othersLibSection,
    // Pas d'entrée dans tabButtons pour cette clé : aucune pastille dédiée
    // dans la barre d'onglets, on y arrive uniquement depuis startPlaylist()
    // (voir plus bas) — mais en sortir passe bien par switchTab('audio'/
    // 'video') comme les deux autres, cf. la garde tabButtons[key] ci-dessous.
    playlist: playlistViewSection,
  };
  const tabButtons = {
    audio: document.getElementById('tab-audio'),
    video: document.getElementById('tab-video'),
    others: document.getElementById('tab-others'),
  };

  function switchTab(name) {
    Object.keys(tabPanels).forEach(key => {
      tabPanels[key].classList.toggle('active-tab', key === name);
      if (tabButtons[key]) tabButtons[key].classList.toggle('active', key === name);
    });
    // Les deux champs de recherche partagent le même emplacement dans
    // l'en-tête : changer d'onglet referme celui qui serait resté ouvert,
    // pour ne pas se retrouver avec la recherche vidéo active en tapant sur
    // l'onglet audio (ou l'inverse).
    librarySearchCtl.close();
    videoLibrarySearchCtl.close();
    syncLibrarySortButtons();
    if (name === 'audio') markAudioSeen();
    else if (name === 'video') markVideoSeen();
    else if (name === 'others') markOthersSeen();
    // Le ResizeObserver sur .library-main-col (voir alignDesktopSidePanels)
    // finit par se déclencher tout seul après ce changement de section,
    // mais seulement à la frame suivante — appel direct ici pour éviter le
    // clignotement d'une frame mal alignée.
    alignDesktopSidePanels();
  }

  function isShowingPlaylistView() {
    return playlistViewSection.classList.contains('active-tab');
  }

  libraryTabs.addEventListener('click', e => {
    const btn = e.target.closest('.library-tab');
    if (btn) switchTab(btn.dataset.tab);
  });

  // --- Vue "playlist en cours" ---
  // Rendu à part de renderPlaylistDetail() (modale "Mes playlists") : cette
  // liste-ci vit dans la page elle-même, prend la place des onglets Audio/
  // Clips (voir startPlaylist), et clique sur un morceau appelle directement
  // playPlaylistItem (navigation au sein de LA playlist affichée), jamais
  // playTrack/playVideo qui sortiraient du contexte.
  const playlistViewList = document.getElementById('playlist-view-list');
  const playlistViewName = document.getElementById('playlist-view-name');
  const playlistViewCount = document.getElementById('playlist-view-count');
  const playlistViewExit = document.getElementById('playlist-view-exit');

  function renderPlaylistView() {
    if (!playlistContext) return;
    playlistViewName.textContent = playlistContext.name;
    const n = playlistContext.items.length;
    playlistViewCount.textContent = n + (n > 1 ? ' titres' : ' titre');
    playlistViewList.innerHTML = '';
    if (n === 0) {
      playlistViewList.innerHTML = '<div class="library-empty">Playlist vide.</div>';
      return;
    }
    sortByArtist(playlistContext.items, playlistViewSortDirection).forEach(item => {
      const isVideo = !item.audio_only;
      const isShared = !!item._shared;
      const isCurrent = item.id === playlistCurrentId;
      const isPlayingGlyph = isVideo ? isCurrent : (isCurrent && !audioPlayer.paused);
      const el = document.createElement('div');
      el.className = 'library-item' + (isCurrent ? ' playing' : '');
      el.setAttribute('role', 'button');
      el.tabIndex = 0;
      el.dataset.trackId = item.id;
      el.dataset.azKey = libraryAzKey(trackArtistKey(item));
      el.innerHTML =
        '<div class="library-thumb-wrap">' +
          '<img class="library-thumb" src="' + (item.thumbnail || '') + '" alt="">' +
          '<span class="library-play-icon">' + (isPlayingGlyph ? '❚❚' : '▶') + '</span>' +
        '</div>' +
        '<div class="library-body">' +
          '<div class="library-info">' +
            '<div class="library-cross-type-row">' +
              '<div class="library-title-row">' +
              '<div class="library-item-title"><span class="library-item-artist"></span><span class="library-item-title-sep"></span><span class="library-item-title-text"></span></div>' +
              (IS_ADMIN ? '<button type="button" class="library-mobile-rename-btn" aria-label="Renommer" data-hint="Renommer">' + RENAME_ICON_SVG + '</button>' : '') +
            '</div>' +
              '<span class="library-type-badge" title="' + (isVideo ? 'Clip vidéo' : 'Morceau audio') + '">' +
                (isVideo ? CLIP_TYPE_ICON_SVG : AUDIO_TYPE_ICON_SVG) +
              '</span>' +
            '</div>' +
            (isShared ? '<div class="library-shared-by"></div>' : '') +
          '</div>' +
          '<div class="library-actions">' + libraryActionsHtml(item, isShared) + '</div>' +
        '</div>';
      renderLibraryItemTitle(el, item);
      if (isShared) {
        el.querySelector('.library-shared-by').textContent = 'Partagé par ' + (item.shared_by_name || '?');
      }
      bindLibraryActionButtons(el, item, isShared);
      el.addEventListener('click', () => playPlaylistItem(item, true));
      playlistViewList.appendChild(el);
    });
  }

  // Tri de la vue playlist : même variable de direction ('asc'/'desc'/null)
  // et même fonction sortByArtist que les onglets Audio/Clips — les boutons
  // réutilisés sont ceux de la barre principale (voir librarySortTargets.playlist
  // et activeLibraryTab plus bas), pas une copie dédiée à cette vue.
  let playlistViewSortDirection = null;

  playlistViewExit.addEventListener('click', () => switchTab('audio'));

  // --- Badges "nouveaux fichiers" croisés entre les onglets Audio et Clips ---
  // Compte les fichiers de l'autre type ajoutés (par moi, ou reçus par
  // partage — fusionnés dans les mêmes listes) pendant qu'on est sur cet
  // onglet, et se remet à zéro dès qu'on ouvre l'onglet concerné.
  const audioTabBadge = document.getElementById('audio-tab-badge');
  const videoTabBadge = document.getElementById('video-tab-badge');
  const othersTabBadge = document.getElementById('others-tab-badge');
  // Compte total permanent (voir fetchLibrary) — distinct des badges
  // "nouveautés" ci-dessus, qui se remettent à zéro à la consultation.
  const audioTabCount = document.getElementById('audio-tab-count');
  const videoTabCount = document.getElementById('video-tab-count');
  const othersTabCount = document.getElementById('others-tab-count');
  let unseenAudioCount = 0;
  let unseenVideoCount = 0;
  let unseenOtherCount = 0;

  function renderAudioTabBadge() {
    audioTabBadge.textContent = unseenAudioCount > 9 ? '9+' : String(unseenAudioCount);
    audioTabBadge.classList.toggle('visible', unseenAudioCount > 0);
  }
  function renderVideoTabBadge() {
    videoTabBadge.textContent = unseenVideoCount > 9 ? '9+' : String(unseenVideoCount);
    videoTabBadge.classList.toggle('visible', unseenVideoCount > 0);
  }
  function renderOthersTabBadge() {
    othersTabBadge.textContent = unseenOtherCount > 9 ? '9+' : String(unseenOtherCount);
    othersTabBadge.classList.toggle('visible', unseenOtherCount > 0);
  }
  function markAudioSeen() {
    if (unseenAudioCount === 0) return;
    unseenAudioCount = 0;
    renderAudioTabBadge();
  }
  function markVideoSeen() {
    if (unseenVideoCount === 0) return;
    unseenVideoCount = 0;
    renderVideoTabBadge();
  }
  function markOthersSeen() {
    if (unseenOtherCount === 0) return;
    unseenOtherCount = 0;
    renderOthersTabBadge();
  }

  // --- Formulaire de téléchargement replié (bouton flottant + feuille) ---
  const downloadFab = document.getElementById('download-fab');
  const downloadSheetOverlay = document.getElementById('download-sheet-overlay');
  const downloadSheetClose = document.getElementById('download-sheet-close');

  downloadFab.addEventListener('click', () => {
    downloadSheetOverlay.classList.add('visible');
  });
  // Arrivée depuis le partage natif Android/PWA (share_target de manifest.json) :
  // le lien est déjà préchargé dans une ligne du formulaire (voir sharedUrls
  // plus haut), mais il resterait invisible derrière le bouton flottant sans
  // cette ouverture automatique.
  if (sharedUrls.length > 0) {
    downloadSheetOverlay.classList.add('visible');
  }
  downloadSheetClose.addEventListener('click', () => {
    downloadSheetOverlay.classList.remove('visible');
  });
  // Clic sur le fond assombri (pas sur la feuille elle-même) : referme aussi.
  downloadSheetOverlay.addEventListener('click', e => {
    if (e.target === downloadSheetOverlay) downloadSheetOverlay.classList.remove('visible');
  });

  // --- Import de playlist externe : lien public (Spotify/Deezer/YouTube
  // Music/Apple Music, résolus côté serveur par playlist-import-resolve.php)
  // ou export CSV lu directement dans le navigateur. Dans les deux cas, on
  // obtient des groupes {name, tracks: [{artist, title, youtube_url?}]} — un
  // groupe = une playlist locale créée à l'identique, un par nom de playlist
  // source (plusieurs possibles dans un même CSV). Chaque titre est ensuite
  // retrouvé sur YouTube (déjà connu pour YouTube Music) et téléchargé un par
  // un via le pipeline existant (start.php + pollProgress), en réutilisant
  // tel quel le mécanisme de startDirectDownload ci-dessus plutôt que d'en
  // écrire un second.
  (function setupPlaylistImport() {
    const openLink = document.getElementById('playlist-import-open-link');
    const overlay = document.getElementById('playlist-import-sheet-overlay');
    const closeBtn = document.getElementById('playlist-import-close');
    const form = document.getElementById('playlist-import-form');
    const urlInput = document.getElementById('playlist-import-url');
    const fileInput = document.getElementById('playlist-import-file');
    const dropzone = document.getElementById('playlist-import-dropzone');
    const dropzoneEmpty = document.getElementById('playlist-import-dropzone-empty');
    const dropzoneFile = document.getElementById('playlist-import-dropzone-file');
    const dropzoneFilename = document.getElementById('playlist-import-dropzone-filename');
    const dropzoneClear = document.getElementById('playlist-import-dropzone-clear');
    const analyzeBtn = document.getElementById('playlist-import-analyze-btn');
    const statusLine = document.getElementById('playlist-import-status');
    const resultBlock = document.getElementById('playlist-import-result');
    const nameRow = document.getElementById('playlist-import-name-row');
    const nameInput = document.getElementById('playlist-import-name');
    const tracksEl = document.getElementById('playlist-import-tracks');
    const startBtn = document.getElementById('playlist-import-start-btn');
    const countEl = document.getElementById('playlist-import-count');
    const miniEl = document.getElementById('playlist-import-mini');
    const miniDonut = document.getElementById('playlist-import-mini-donut');
    const miniDonutInner = document.getElementById('playlist-import-mini-donut-inner');
    const miniDonutStorage = document.getElementById('playlist-import-mini-donut-storage');
    const miniTooltip = document.getElementById('playlist-import-mini-tooltip');
    const miniPct = document.getElementById('playlist-import-mini-pct');
    const sourceEl = document.getElementById('playlist-import-source');
    const lockedStatusEl = document.getElementById('playlist-import-locked-status');
    const summaryModal = document.getElementById('playlist-import-summary-modal');
    const summaryCountEl = document.getElementById('playlist-import-summary-count');
    const summaryStorageEl = document.getElementById('playlist-import-summary-storage');
    const summaryErrorsBlock = document.getElementById('playlist-import-summary-errors-block');
    const summaryErrorsEl = document.getElementById('playlist-import-summary-errors');
    const summaryCloseBtn = document.getElementById('playlist-import-summary-close');
    summaryCloseBtn.addEventListener('click', () => summaryModal.classList.remove('visible'));

    let groups = []; // [{ name, tracks: [{artist, title, youtube_url?}] }]
    let importing = false;
    let overallDone = 0;
    let overallTotal = 0;

    function setStatus(text, kind) {
      statusLine.textContent = text || '';
      statusLine.className = 'status-line' + (text ? ' visible' : '') + (kind ? ' ' + kind : '');
    }

    // Masque le lien + le CSV (donc toute façon de démarrer un NOUVEAU
    // process) tant qu'un import tourne déjà — un second import en parallèle
    // mélangerait deux séries de téléchargements/playlists en cours, mieux
    // vaut laisser le premier se terminer avant d'en proposer un autre.
    function updateSourceVisibility() {
      sourceEl.style.display = importing ? 'none' : '';
      lockedStatusEl.classList.toggle('visible', importing);
    }

    function reset() {
      groups = [];
      resultBlock.style.display = 'none';
      tracksEl.innerHTML = '';
      setStatus('');
      urlInput.value = '';
      fileInput.value = '';
      dropzoneFile.style.display = 'none';
      dropzoneEmpty.style.display = 'flex';
      analyzeBtn.disabled = false;
      startBtn.disabled = false;
      updateSourceVisibility();
    }

    // Anneau extérieur (accent) + texte : progression GLOBALE, titres déjà
    // terminés sur le total — seul chiffre affiché, pour ne pas surcharger
    // le petit cercle de deux pourcentages à lire.
    function updateMiniProgress() {
      const pct = overallTotal ? Math.round((overallDone / overallTotal) * 100) : 0;
      miniDonut.style.background = 'conic-gradient(var(--accent) 0% ' + pct + '%, var(--border) ' + pct + '% 100%)';
      miniPct.textContent = pct + '%';
    }

    // Anneau intérieur (accent-2, plus clair), sans texte : avancement du
    // TÉLÉCHARGEMENT DU TITRE EN COURS uniquement — remis à zéro à chaque
    // nouveau titre (voir importOneTrack).
    function updateMiniTrackProgress(pct) {
      pct = Math.max(0, Math.min(100, pct || 0));
      // setProperty sur --track-pct (pas re-render du gradient complet) :
      // c'est ce qui permet au navigateur d'animer la transition CSS entre
      // l'ancienne et la nouvelle valeur plutôt que de sauter directement
      // dessus à chaque appel (toutes les 600ms via pollProgress).
      miniDonutInner.style.setProperty('--track-pct', pct + '%');
    }

    // Troisième anneau, plus foncé : taux de remplissage de l'espace de
    // stockage du compte (lastStorageUsage — la même donnée que le donut
    // d'en-tête, tenue à jour par renderStorageUsage()). Sa couleur glisse
    // du vert au rouge à mesure qu'on approche du quota, pour prévenir
    // avant que le prochain titre échoue faute de place plutôt qu'après.
    const STORAGE_RING_LOW_COLOR = [47, 125, 79];   // vert foncé, cuve presque vide
    const STORAGE_RING_HIGH_COLOR = [168, 50, 50];  // rouge foncé, cuve pleine
    function updateMiniStorageProgress() {
      if (!lastStorageUsage) return;
      const quota = lastStorageUsage.quota_bytes || (5 * 1024 * 1024 * 1024);
      const ratio = quota ? Math.max(0, Math.min(1, lastStorageUsage.total_bytes / quota)) : 0;
      const pct = Math.round(ratio * 100);
      const color = STORAGE_RING_LOW_COLOR.map((low, i) =>
        Math.round(low + (STORAGE_RING_HIGH_COLOR[i] - low) * ratio)
      );
      miniDonutStorage.style.setProperty('--storage-pct', pct + '%');
      miniDonutStorage.style.setProperty('--storage-color', 'rgb(' + color.join(',') + ')');
      const remainingPct = 100 - pct;
      const remainingBytes = Math.max(0, quota - lastStorageUsage.total_bytes);
      miniTooltip.textContent = remainingPct + ' % d\'espace restant (' + formatBytes(remainingBytes) + ')';
    }

    // Replie la feuille en cercle flottant plutôt que de la fermer pour de
    // bon : l'import continue en tâche de fond (rien dans la boucle
    // d'import ne dépend de la visibilité de la feuille), l'utilisateur
    // peut continuer à écouter sa bibliothèque pendant ce temps.
    // Pendant un import, la feuille ne se contente pas de se refermer : elle
    // rétrécit visuellement jusqu'à devenir le cercle flottant (technique
    // FLIP — First/Last/Invert/Play), pour que l'utilisateur comprenne où
    // suivre la suite sans qu'elle disparaisse et réapparaisse sans lien
    // visuel apparent. Sans import en cours, simple fermeture classique.
    function collapseSheetIntoCircle() {
      const sheetEl = overlay.querySelector('.download-sheet');
      const sheetRect = sheetEl.getBoundingClientRect();

      miniEl.style.display = 'flex';
      miniEl.classList.toggle('mini-player-visible', miniPlayer.classList.contains('visible'));
      miniEl.classList.add('appearing');
      const miniRect = miniEl.getBoundingClientRect();

      // Un seul facteur d'échelle (pas X/Y séparés) : la feuille doit
      // rétrécir en gardant ses proportions, pas s'aplatir en ellipse.
      const scale = Math.max(miniRect.width / sheetRect.width, miniRect.height / sheetRect.height);
      const dx = (miniRect.left + miniRect.width / 2) - (sheetRect.left + sheetRect.width / 2);
      const dy = (miniRect.top + miniRect.height / 2) - (sheetRect.top + sheetRect.height / 2);
      sheetEl.style.setProperty('--collapse-x', dx + 'px');
      sheetEl.style.setProperty('--collapse-y', dy + 'px');
      sheetEl.style.setProperty('--collapse-scale', scale);

      // Un frame d'écart avant d'ajouter les classes qui déclenchent les
      // transitions : sans lui, le navigateur peut fusionner l'ajout des
      // custom properties et celui de .collapsing dans le même style
      // recalc, et la transition ne "part" jamais (elle s'applique déjà à
      // l'état final dès le premier rendu).
      requestAnimationFrame(() => {
        overlay.classList.add('collapsing');
        miniEl.classList.remove('appearing');
      });

      const onEnd = () => {
        overlay.classList.remove('visible', 'collapsing');
        sheetEl.style.removeProperty('--collapse-x');
        sheetEl.style.removeProperty('--collapse-y');
        sheetEl.style.removeProperty('--collapse-scale');
      };
      sheetEl.addEventListener('transitionend', onEnd, { once: true });
    }

    function minimizeOverlay() {
      if (!overlay.classList.contains('visible')) return;
      if (importing) {
        collapseSheetIntoCircle();
      } else {
        overlay.classList.remove('visible');
      }
    }
    miniEl.addEventListener('click', () => {
      miniEl.style.display = 'none';
      updateSourceVisibility();
      overlay.classList.add('visible');
    });

    openLink.addEventListener('click', () => {
      downloadSheetOverlay.classList.remove('visible');
      if (importing) {
        // Un import tourne déjà en tâche de fond : on ré-ouvre son suivi
        // plutôt que d'en écraser l'état avec reset().
        miniEl.style.display = 'none';
        updateSourceVisibility();
        overlay.classList.add('visible');
        return;
      }
      reset();
      overlay.classList.add('visible');
    });
    closeBtn.addEventListener('click', minimizeOverlay);
    overlay.addEventListener('click', e => { if (e.target === overlay) minimizeOverlay(); });

    function totalTrackCount() {
      return groups.reduce((sum, g) => sum + g.tracks.length, 0);
    }

    function renderGroups() {
      const single = groups.length === 1;
      nameRow.style.display = single ? 'flex' : 'none';
      if (single) nameInput.value = groups[0].name;

      let html = '';
      groups.forEach((g, gi) => {
        if (!single) html += '<div class="playlist-import-group-header"></div>';
        g.tracks.forEach((t, ti) => {
          html += '<div class="playlist-import-track" id="pl-import-track-' + gi + '-' + ti + '">' +
            '<div class="playlist-import-track-info">' +
              '<div class="playlist-import-track-title"></div>' +
              '<div class="playlist-import-track-artist"></div>' +
            '</div>' +
            '<div class="playlist-import-track-status">en attente</div>' +
          '</div>';
        });
      });
      tracksEl.innerHTML = html;

      const headers = tracksEl.querySelectorAll('.playlist-import-group-header');
      let headerIdx = 0;
      groups.forEach((g, gi) => {
        if (!single) headers[headerIdx++].textContent = g.name + ' (' + g.tracks.length + ')';
        g.tracks.forEach((t, ti) => {
          const row = document.getElementById('pl-import-track-' + gi + '-' + ti);
          row.querySelector('.playlist-import-track-title').textContent = t.title || '(titre inconnu)';
          row.querySelector('.playlist-import-track-artist').textContent = t.artist || '';
        });
      });

      countEl.textContent = '(' + totalTrackCount() + ')';
    }

    form.addEventListener('submit', async e => {
      e.preventDefault();
      const url = urlInput.value.trim();
      if (!url) return;
      analyzeBtn.disabled = true;
      setStatus('Analyse de la playlist…');
      resultBlock.style.display = 'none';
      try {
        const res = await fetch('playlist-import-resolve.php?url=' + encodeURIComponent(url));
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Playlist introuvable.');
        const tracks = data.tracks || [];
        if (!tracks.length) throw new Error('Aucun titre trouvé dans cette playlist.');
        groups = [{ name: data.name || 'Playlist importée', tracks }];
        renderGroups();
        resultBlock.style.display = 'block';
        setStatus(totalTrackCount() + ' titre(s) trouvé(s) — vérifie puis lance l\'import.', 'success');
      } catch (err) {
        setStatus(err.message, 'error');
      } finally {
        analyzeBtn.disabled = false;
      }
    });

    // Parseur CSV minimal (RFC 4180) : champs entre guillemets, virgules et
    // retours à la ligne échappés à l'intérieur — suffisant pour les exports
    // Exportify/Soundiiz/TuneMyMusic/Spotify, pas un parseur généraliste.
    function parseCsv(text) {
      const rows = [];
      let row = [], field = '', inQuotes = false;
      for (let i = 0; i < text.length; i++) {
        const c = text[i];
        if (inQuotes) {
          if (c === '"') {
            if (text[i + 1] === '"') { field += '"'; i++; } else inQuotes = false;
          } else field += c;
        } else if (c === '"') {
          inQuotes = true;
        } else if (c === ',') {
          row.push(field); field = '';
        } else if (c === '\n' || c === '\r') {
          if (c === '\r' && text[i + 1] === '\n') i++;
          row.push(field); field = '';
          rows.push(row); row = [];
        } else {
          field += c;
        }
      }
      if (field !== '' || row.length) { row.push(field); rows.push(row); }
      return rows.filter(r => !(r.length === 1 && r[0] === ''));
    }

    function findColumn(headers, candidates) {
      const lower = headers.map(h => h.trim().toLowerCase());
      for (const c of candidates) {
        const idx = lower.indexOf(c);
        if (idx !== -1) return idx;
      }
      return -1;
    }

    // Lecture + analyse du CSV — factorisée à part de l'événement 'change' :
    // le glisser-déposer sur la zone (voir plus bas) fournit un fichier de la
    // même façon (File natif) mais sans jamais passer par fileInput.files
    // avant traitement, donc sans jamais déclencher 'change'.
    async function processFile(file) {
      setStatus('Lecture du fichier…');
      resultBlock.style.display = 'none';
      try {
        const text = (await file.text()).replace(/^﻿/, '');
        const rows = parseCsv(text);
        if (rows.length < 2) throw new Error('Fichier CSV vide ou illisible.');
        const headers = rows[0];
        const titleIdx = findColumn(headers, ['track name', 'title', 'track', 'song', 'nom du titre', 'titre']);
        const artistIdx = findColumn(headers, ['artist name', 'artist', 'artiste']);
        const playlistIdx = findColumn(headers, ['playlist name', 'playlist', 'album']);
        if (titleIdx === -1) throw new Error('Colonne de titre introuvable dans ce CSV.');

        const byPlaylist = new Map();
        for (const r of rows.slice(1)) {
          const title = (r[titleIdx] || '').trim();
          if (!title) continue;
          const artist = artistIdx !== -1 ? (r[artistIdx] || '').trim() : '';
          const plName = playlistIdx !== -1 ? (r[playlistIdx] || '').trim() : '';
          const key = plName || file.name.replace(/\.csv$/i, '');
          if (!byPlaylist.has(key)) byPlaylist.set(key, []);
          byPlaylist.get(key).push({ artist, title });
        }
        if (!byPlaylist.size) throw new Error('Aucun titre trouvé dans ce fichier.');

        groups = Array.from(byPlaylist, ([name, tracks]) => ({ name, tracks }));
        renderGroups();
        resultBlock.style.display = 'block';
        setStatus(totalTrackCount() + ' titre(s) trouvé(s) dans ' + groups.length + ' playlist(s) — vérifie puis lance l\'import.', 'success');
      } catch (err) {
        setStatus(err.message, 'error');
      }
    }

    // Bascule la zone de dépôt vers son état "fichier choisi" (nom + croix
    // pour retirer) puis lance l'analyse — commun au clic (input natif) et
    // au glisser-déposer.
    function handleFileSelected(file) {
      dropzoneEmpty.style.display = 'none';
      dropzoneFile.style.display = 'flex';
      dropzoneFilename.textContent = file.name;
      processFile(file);
    }

    fileInput.addEventListener('change', () => {
      const file = fileInput.files[0];
      if (file) handleFileSelected(file);
    });

    dropzoneClear.addEventListener('click', e => {
      // Empêche la bulle d'atteindre le <label> englobant (qui rouvrirait
      // sinon le sélecteur de fichier au clic sur la croix elle-même).
      e.preventDefault();
      e.stopPropagation();
      fileInput.value = '';
      dropzoneFile.style.display = 'none';
      dropzoneEmpty.style.display = 'flex';
      resultBlock.style.display = 'none';
      groups = [];
      setStatus('');
    });

    // Glisser-déposer : la zone entière (voir .playlist-import-dropzone) sert
    // de cible, pas seulement l'input cliquable. dragenter/dragover doivent
    // être annulés pour que 'drop' se déclenche (comportement par défaut du
    // navigateur sinon : ouvrir le fichier dans l'onglet).
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(evt => {
      dropzone.addEventListener(evt, e => { e.preventDefault(); e.stopPropagation(); });
    });
    ['dragenter', 'dragover'].forEach(evt => {
      dropzone.addEventListener(evt, () => dropzone.classList.add('dragover'));
    });
    // relatedTarget encore dans la zone : on a juste survolé un enfant
    // (l'icône, le texte...), pas vraiment quitté la zone — sans ce test le
    // survol scintille (dragover retiré puis remis à chaque enfant traversé).
    dropzone.addEventListener('dragleave', e => {
      if (e.relatedTarget && dropzone.contains(e.relatedTarget)) return;
      dropzone.classList.remove('dragover');
    });
    dropzone.addEventListener('drop', e => {
      dropzone.classList.remove('dragover');
      const file = e.dataTransfer.files && e.dataTransfer.files[0];
      if (!file) return;
      // Reflète le fichier déposé dans l'input natif : si quoi que ce soit
      // d'autre lit un jour fileInput.files, il reste cohérent avec l'écran.
      try { fileInput.files = e.dataTransfer.files; } catch (err) {}
      handleFileSelected(file);
    });

    async function findYoutubeUrl(track) {
      if (track.youtube_url) return track.youtube_url; // YouTube Music : déjà résolu
      const q = [track.artist, track.title].filter(Boolean).join(' ');
      if (!q) return null;
      const res = await fetch('youtube-search.php?limit=1&q=' + encodeURIComponent(q));
      const data = await res.json();
      const first = (data.results || [])[0];
      return first ? first.url : null;
    }

    // Nécessaire avant tout appel à set_membership (qui remplace la liste
    // complète d'appartenance d'un morceau) : sans ça, ajouter un titre déjà
    // présent ailleurs (doublon détecté par start.php) à CETTE playlist le
    // retirerait de ses autres playlists existantes.
    async function currentPlaylistIds(historyId) {
      try {
        const res = await fetch('playlists.php');
        const data = await res.json();
        return (data.membership && data.membership[historyId]) || [];
      } catch (err) { return []; }
    }

    async function addToPlaylist(historyId, playlistId) {
      const wanted = Array.from(new Set([...(await currentPlaylistIds(historyId)), playlistId]));
      await fetch('playlist-update.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'set_membership', history_id: historyId, playlist_ids: wanted }),
      });
    }

    // start.php ne renvoie pas d'id pour un doublon déjà en bibliothèque
    // (appareil ou compte) — on le retrouve par titre exact, seule info
    // disponible à ce stade, dans mes morceaux + ceux partagés avec moi
    // (un doublon "chez un autre compte" y devient accessible automatiquement).
    async function findHistoryIdByTitle(title) {
      try {
        const res = await fetch('library.php');
        const data = await res.json();
        const all = [...(data.tracks || []), ...(data.shared_tracks || [])];
        const match = all.find(t => t.title === title);
        return match ? match.id : null;
      } catch (err) { return null; }
    }

    async function importOneTrack(track, rowId, playlistId) {
      const row = document.getElementById(rowId);
      const statusEl = row.querySelector('.playlist-import-track-status');
      row.classList.add('active');
      // Garde le titre en cours au milieu de la liste : ce qui est déjà fait
      // reste visible au-dessus, ce qu'il reste à faire en dessous — sans
      // ça, la liste peut compter des dizaines de titres et l'utilisateur
      // perd la ligne en cours hors champ au fil de l'import.
      row.scrollIntoView({ behavior: 'smooth', block: 'center' });
      statusEl.textContent = 'recherche…';
      updateMiniTrackProgress(0);
      try {
        const youtubeUrl = await findYoutubeUrl(track);
        if (!youtubeUrl) throw new Error('introuvable');

        statusEl.textContent = 'téléchargement…'; // import de playlist = toujours audio
        let startRes = await startDownload(youtubeUrl, true, false);
        let startData = await startRes.json();
        if (!startRes.ok) throw new Error(startData.error || 'échec');

        let historyId = null;
        let wasAlreadyDownloaded = false;
        if (startData.alreadyDownloaded) {
          wasAlreadyDownloaded = true;
          statusEl.textContent = 'déjà en bibliothèque';
          updateMiniTrackProgress(100);
          historyId = await findHistoryIdByTitle(startData.title);
        } else {
          await pollProgress(startData.jobId, '', percent => {
            statusEl.textContent = Math.round(percent) + ' %';
            updateMiniTrackProgress(percent);
          });
          const saveRes = await fetch('library-save.php?job=' + encodeURIComponent(startData.jobId));
          const saveData = await saveRes.json();
          historyId = saveData.history_id || null;
        }

        if (!historyId) throw new Error('échec');
        await addToPlaylist(historyId, playlistId);

        // Espace utilisé re-mesuré après CHAQUE titre (pas seulement à la
        // fin de l'import) : c'est ce qui permet au troisième anneau de
        // glisser du vert au rouge en direct, avant que le quota ne soit
        // vraiment atteint et ne fasse échouer un futur titre.
        try {
          const libRes = await fetch('library.php');
          const libData = await libRes.json();
          renderStorageUsage(libData.usage);
        } catch (err) { /* pas bloquant */ }
        updateMiniStorageProgress();

        row.classList.remove('active');
        row.classList.add('done');
        statusEl.textContent = wasAlreadyDownloaded ? 'déjà présent' : 'ajouté';
      } catch (err) {
        row.classList.remove('active');
        row.classList.add('failed');
        statusEl.textContent = 'introuvable';
      }
    }

    startBtn.addEventListener('click', async () => {
      if (importing || !groups.length) return;
      importing = true;
      updateSourceVisibility();
      startBtn.disabled = true;
      overallTotal = totalTrackCount();
      overallDone = 0;
      updateMiniProgress();
      updateMiniTrackProgress(0);
      updateMiniStorageProgress();
      // Repère de l'espace utilisé avant le premier octet téléchargé, pour
      // pouvoir annoncer dans le récapitulatif final combien CET import a
      // réellement consommé (et pas le total de toute la bibliothèque).
      const importStartBytes = lastStorageUsage ? lastStorageUsage.total_bytes : null;
      // Dès le lancement, la feuille se réduit en cercle flottant — pas
      // besoin d'attendre que l'utilisateur pense à la fermer lui-même pour
      // récupérer l'écran et continuer à écouter pendant l'import.
      minimizeOverlay();

      for (const g of groups) {
        const gi = groups.indexOf(g);
        const name = groups.length === 1 ? (nameInput.value.trim() || g.name) : g.name;
        setStatus('Création de la playlist « ' + name + ' »…');

        let playlistId = null;
        try {
          const createRes = await fetch('playlist-update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'create', name }),
          });
          const createData = await createRes.json();
          if (!createRes.ok) throw new Error(createData.error || 'Échec de création.');
          playlistId = createData.id;
        } catch (err) {
          setStatus('Impossible de créer la playlist « ' + name + ' ».', 'error');
          continue;
        }

        for (let ti = 0; ti < g.tracks.length; ti++) {
          setStatus('Import ' + (overallDone + 1) + ' / ' + overallTotal + '…');
          await importOneTrack(g.tracks[ti], 'pl-import-track-' + gi + '-' + ti, playlistId);
          overallDone++;
          updateMiniProgress();
        }
      }

      importing = false;
      updateSourceVisibility();
      startBtn.disabled = false;

      // Fin d'import : on referme la feuille et le cercle flottant plutôt
      // que de laisser l'utilisateur les fermer lui-même — le récapitulatif
      // qui suit est la seule chose qui reste à l'écran, et lui seul doit
      // être fermé manuellement (voir summaryCloseBtn).
      overlay.classList.remove('visible', 'collapsing');
      miniEl.style.display = 'none';
      setStatus('Import terminé.', 'success');

      const doneRows = tracksEl.querySelectorAll('.playlist-import-track.done');
      const failedRows = tracksEl.querySelectorAll('.playlist-import-track.failed');
      summaryCountEl.textContent = doneRows.length + ' morceau' + (doneRows.length !== 1 ? 'x' : '') +
        ' téléchargé' + (doneRows.length !== 1 ? 's' : '') + ' sur ' + overallTotal +
        (failedRows.length ? ' (' + failedRows.length + ' échec' + (failedRows.length !== 1 ? 's' : '') + ')' : '');

      if (lastStorageUsage) {
        const quota = lastStorageUsage.quota_bytes || (5 * 1024 * 1024 * 1024);
        const consumed = importStartBytes !== null ? Math.max(0, lastStorageUsage.total_bytes - importStartBytes) : null;
        const remaining = Math.max(0, quota - lastStorageUsage.total_bytes);
        summaryStorageEl.textContent = (consumed !== null ? formatBytes(consumed) + ' consommés pour cet import — ' : '') +
          formatBytes(remaining) + ' restants sur votre quota.';
      } else {
        summaryStorageEl.textContent = '';
      }

      summaryErrorsEl.innerHTML = '';
      if (failedRows.length) {
        summaryErrorsBlock.style.display = '';
        // Construits via createElement/textContent (pas de concaténation
        // HTML) : titres/artistes viennent de la playlist importée par
        // l'utilisateur, jamais fiables à injecter tels quels.
        failedRows.forEach(row => {
          const item = document.createElement('div');
          item.className = 'playlist-import-summary-error-item';
          const titleEl = document.createElement('div');
          titleEl.className = 'playlist-import-summary-error-title';
          titleEl.textContent = row.querySelector('.playlist-import-track-title').textContent;
          item.appendChild(titleEl);
          const artist = row.querySelector('.playlist-import-track-artist').textContent;
          if (artist) {
            const artistEl = document.createElement('div');
            artistEl.className = 'playlist-import-summary-error-artist';
            artistEl.textContent = artist;
            item.appendChild(artistEl);
          }
          summaryErrorsEl.appendChild(item);
        });
      } else {
        summaryErrorsBlock.style.display = 'none';
      }

      summaryModal.classList.add('visible');

      loadPlaylists().catch(() => {});
      fetchLibrary();
    });
  })();

  fetchLibrary();
  setupSwipeHintAvatar();
  // Chargées dès l'ouverture, sans attendre que le panneau soit ouvert : c'est
  // ce qui permet aux boutons d'ajout de s'afficher en vert d'emblée.
  loadPlaylists().catch(() => {});
</script>
<script src="music.js?v=20260916-tooltip1"></script>
<!-- Connexion par empreinte : proposée une fois après connexion (voir ui/passkey.js). -->
<script src="ui/passkey.js?v=<?php echo (int)@filemtime(__DIR__ . '/ui/passkey.js'); ?>"></script>
<script src="ui/immersive.js?v=<?php echo (int)@filemtime(__DIR__ . '/ui/immersive.js'); ?>"></script>
<script>
  if (window.YtdlPasskey) setTimeout(() => window.YtdlPasskey.offer(<?php echo json_encode(['id' => (int)$user['id'], 'name' => (string)$user['name'], 'avatar' => (string)($user['avatar'] ?? '')], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE); ?>), 2500);
</script>
</body>
</html>
