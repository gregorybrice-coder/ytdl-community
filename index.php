<?php
require_once __DIR__ . '/auth.php';

ytdl_maintenance_gate(ytdl_current_user());

if (ytdl_current_user()) {
    header('Location: capture.php');
    exit;
}

$welcomeAccount = ytdl_welcome_account();
$welcomeAudioKey = bin2hex(random_bytes(24));
$_SESSION['welcome_audio'] = array_filter($_SESSION['welcome_audio'] ?? [], static fn($slot) => $slot['expires'] >= time());
$_SESSION['welcome_audio'] = array_slice($_SESSION['welcome_audio'], -3, null, true);
$_SESSION['welcome_audio'][$welcomeAudioKey] = ['expires' => time() + 7200, 'id' => null];
header('Cache-Control: no-store, private');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, interactive-widget=resizes-content">
<title>ytdl — Connexion</title>
<link rel="manifest" href="manifest.json?v=4">
<meta name="theme-color" content="#171a21">
<link rel="apple-touch-icon" href="icon-180.png?v=2">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="ytdl">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<style>
  @import url('https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Space+Grotesk:wght@500;600;700&display=swap');
  :root {
    --bg: #050816; --panel: rgba(12, 20, 43, .78); --border: rgba(132, 173, 255, .22);
    --text: #edf5ff; --text-dim: #94a7c7;
    --accent: #49e8ff; --accent-2: #9e72ff;
    --error: #ff6b6b; --success: #4ade80;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
    background: radial-gradient(circle at 20% 15%, rgba(73,232,255,.18), transparent 27%), radial-gradient(circle at 80% 80%, rgba(158,114,255,.16), transparent 31%), var(--bg);
    color: var(--text); font-family: "Space Grotesk", -apple-system, sans-serif; overflow: hidden;
  }
  body::before { content:""; position: fixed; inset:0; pointer-events:none; opacity:.32; background-image:linear-gradient(rgba(105,156,255,.12) 1px,transparent 1px),linear-gradient(90deg,rgba(105,156,255,.12) 1px,transparent 1px); background-size:48px 48px; mask-image:radial-gradient(circle at center,black,transparent 78%); }
  .card {
    position:relative; width: 100%; max-width: 480px; background: var(--panel); border: 1px solid var(--border);
    border-radius: 24px; padding: 40px; box-shadow: 0 24px 80px rgba(0,0,0,.48), inset 0 1px 0 rgba(255,255,255,.08); backdrop-filter: blur(20px);
  }
  .card::before { content:"SYSTEM / ACCESS"; position:absolute; top:14px; right:20px; color:var(--accent); font:500 .62rem "DM Mono",monospace; letter-spacing:.12em; opacity:.75; }
  h1 {
    margin: 0 0 6px; font-size: 2rem; letter-spacing:-.07em;
    background: linear-gradient(100deg, var(--accent), var(--accent-2));
    -webkit-background-clip: text; background-clip: text; color: transparent;
  }
  p.subtitle { margin: 0 0 28px; color: var(--text-dim); font-size: .9rem; line-height:1.55; }

  .accounts { display: flex; gap: 16px; flex-wrap: wrap; justify-content: center; }
  .account-btn {
    position: relative;
    display: flex; flex-direction: column; align-items: center; gap: 8px;
    background: rgba(4,10,28,.65); border: 1px solid var(--border); border-radius: 16px;
    padding: 16px 18px; cursor: pointer; width: 110px; transition: border-color 0.15s, transform 0.15s;
    color: var(--text);
  }
  .account-btn:hover { border-color: var(--accent); transform: translateY(-3px); box-shadow:0 0 0 3px rgba(73,232,255,.10),0 14px 24px rgba(0,0,0,.25); }
  .account-btn:disabled { cursor: not-allowed; }
  .account-btn:disabled:not(.loading) { opacity: 0.45; }
  .account-btn img { width: 56px; height: 84px; object-fit: cover; border-radius: 10px; transition: opacity 0.15s; }
  .account-btn span { font-size: 0.85rem; font-weight: 600; color: var(--text); }
  .account-btn.loading img { opacity: 0.25; }
  .account-btn .spinner {
    display: none; position: absolute; top: 40px; left: 50%; margin-left: -13px;
    width: 26px; height: 26px; border: 3px solid var(--border); border-top-color: var(--accent);
    border-radius: 50%; animation: spin 0.7s linear infinite;
  }
  .account-btn.loading .spinner { display: block; }
  @keyframes spin { to { transform: rotate(360deg); } }

  .add-btn {
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;
    background: rgba(4,10,28,.48); border: 1px dashed var(--border); border-radius: 16px;
    padding: 16px 18px; cursor: pointer; width: 110px; color: var(--text-dim);
    transition: border-color 0.15s, color 0.15s;
  }
  .add-btn:hover { border-color: var(--accent); color: var(--accent); }
  .add-btn .plus { font-size: 1.8rem; line-height: 1; }
  .add-btn span:last-child { font-size: 0.78rem; text-align: center; }

  .request-form {
    display: none; margin-top: 24px; padding-top: 24px; border-top: 1px solid var(--border);
  }
  .request-form.visible { display: block; }
  label { display: block; font-size: 0.85rem; color: var(--text-dim); margin-bottom: 6px; }
  input[type="text"], input[type="email"] {
    width: 100%; padding: 11px 12px; border-radius: 8px; border: 1px solid var(--border);
    background: rgba(4,10,28,.65); color: var(--text); font-size: 0.9rem; outline: none; margin-bottom: 14px;
  }
  input:focus { border-color: var(--accent); }
  button.submit {
    width: 100%; padding: 12px; border: none; border-radius: 8px;
    background: linear-gradient(100deg, var(--accent), var(--accent-2)); box-shadow:0 8px 26px rgba(73,232,255,.18);
    color: #0a0c10; font-weight: 700; font-size: 0.9rem; cursor: pointer;
  }
  button.submit:disabled { opacity: 0.6; cursor: not-allowed; }

  .status-line { margin-top: 18px; font-size: 0.85rem; color: var(--text-dim); display: none; text-align: center; }
  .status-line.visible { display: block; }
  .status-line.error { color: var(--error); }
  .status-line.success { color: var(--success); }

  .recovery-link { background: none; border: 0; color: var(--accent); cursor: pointer; margin-top: 20px; font: inherit; text-decoration: underline; }
  .remember-row {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    margin-bottom: 20px; font-size: 0.85rem; color: var(--text-dim);
  }
  .remember-row input { width: 16px; height: 16px; accent-color: var(--accent); cursor: pointer; }
  .remember-row label { margin: 0; color: var(--text); cursor: pointer; }
</style>
<link rel="stylesheet" href="music.css?v=20260913">

<style>
  @media (min-width: 761px) {
    .music-login-record .music-disc-label {
      padding: 0;
      background: radial-gradient(circle, transparent 0 18px, #b86a3d 19px 20px, #e78c56 21px);
      box-shadow: inset 0 0 0 1px #f5b48780;
    }
  }
  .music-login-record .login-vinyl-label { display: block; width: 100%; height: 100%; fill: #25221b; }
  .login-vinyl-label .vinyl-artist { font-size: 20px; font-weight: 700; letter-spacing: -.6px; }
  .login-vinyl-label .vinyl-mix { font-size: 11px; font-weight: 600; letter-spacing: 2px; }
  .music-login-record { height: 365px; }
  .music-login-record .music-disc { border: 0; padding: 0; cursor: pointer; transform: none; isolation: isolate; }
  /* Sillons du vinyle et reflets sur la surface noire. */
  .music-login-record .music-disc {
    background:
      radial-gradient(circle, transparent 0 47%, #08090c 47.4% 48.2%, transparent 48.6% 57%, #08090c 57.4% 58.2%, transparent 58.6% 67%, #363941 67.3% 67.6%, #090a0d 68%),
      repeating-radial-gradient(circle, #08090c 0 1.5px, #30333b 2px 2.7px, #15171c 3.2px 4.5px);
    box-shadow: inset 0 0 0 1px #50535d, inset 0 0 0 5px #0b0c10, 10px 16px 32px #0004;
  }
  .music-login-record .music-disc::after {
    background: conic-gradient(from 25deg, transparent 0deg, #ffffff12 35deg, transparent 70deg, #0005 125deg, transparent 160deg, #ffffff1c 210deg, transparent 245deg, #0004 300deg, transparent 360deg);
    pointer-events: none;
  }
  /* Centre opaque au-dessus de la pochette, y compris sans rendu du masque. */
  .music-login-record .music-disc::before {
    content: ''; position: absolute; left: 50%; top: 50%;
    width: 10.1818%; height: 10.1818%; transform: translate(-50%, -50%);
    border-radius: 50%; background: #050711; z-index: 2; pointer-events: none;
  }
  /* Le disque tourne autour d’un axe fixe ; ombre et lumière restent immobiles. */
  .welcome-disc-rotor {
    position: absolute; inset: 0; border-radius: 50%; display: grid; place-items: center;
    background: inherit; transform-origin: 50% 50%;
    animation: welcome-vinyl-spin 1.8s linear infinite; animation-play-state: paused;
  }
  .music-login-record .music-disc.is-playing .welcome-disc-rotor { animation-play-state: running; }
  .music-login-record .music-disc:focus-visible { outline: none; box-shadow: 0 0 0 3px var(--accent); }
  #welcome-audio-status { font-size: 11px; color: var(--text-dim); margin: 14px 0 0 40px; }
  @keyframes welcome-vinyl-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
  @media (prefers-reduced-motion: reduce) { .welcome-disc-rotor { animation: none; } }
  .welcome-disc-wrap { position: relative; width: 330px; margin-left: 40px; }
  .music-login-record .welcome-disc-wrap .music-disc { width: 100%; margin: 0; }
  .disc-tap-hint { position: absolute; z-index: 3; left: 70%; top: 58%; width: 46px; height: 55px; pointer-events: none; opacity: 1; transition: opacity .25s; filter: drop-shadow(0 3px 8px #0008); }
  .disc-tap-hint svg { position: relative; display: block; width: 100%; height: 100%; transform-origin: 45% 10%; animation: disc-finger-tap 2.8s ease-in-out infinite; }
  .disc-tap-wave { position: absolute; width: 24px; height: 24px; left: 8px; top: -3px; border: 2px solid var(--accent); border-radius: 50%; animation: disc-tap-ripple 2.8s ease-out infinite; }
  .welcome-disc-wrap.hint-dismissed .disc-tap-hint { opacity: 0; }
  .welcome-disc-wrap.hint-dismissed .disc-tap-hint svg, .welcome-disc-wrap.hint-dismissed .disc-tap-wave { animation: none; }
  .music-login-record #welcome-audio-status { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
  .music-login-record #welcome-audio-status.audio-error { position: static; width: auto; height: auto; margin: 10px 0; clip-path: none; white-space: normal; }
  @keyframes disc-finger-tap { 0%, 18%, 60%, 100% { transform: translate(4px, 7px) rotate(-12deg); } 30%, 44% { transform: translate(0, 0) rotate(-6deg) scale(.92); } }
  @keyframes disc-tap-ripple { 0%, 26% { opacity: 0; transform: scale(.4); } 32% { opacity: .9; } 58%, 100% { opacity: 0; transform: scale(2.2); } }
  @media (max-width: 760px) { .welcome-disc-wrap { width: min(100%, 250px); margin: 0; } .disc-tap-hint { width: 34px; height: 41px; } .disc-tap-wave { left: 3px; top: -5px; } }
  @media (prefers-reduced-motion: reduce) { .disc-tap-hint svg, .disc-tap-wave { animation: none; } .disc-tap-wave { display: none; } }
  /* Étiquette centrale réduite de 20 % par rapport au diamètre initial de 62 %. */
  .music-login-record .music-disc .music-disc-label { width: 49.6%; padding: 0; background: transparent; box-shadow: none; }
  .login-vinyl-label .track-label-text { fill: #fff; font-size: 16px; font-weight: 600; letter-spacing: .5px; }
  /* Icône paroles, à côté de la signature — masquée tant qu'on ne sait pas
     si le morceau tiré au hasard en a (voir loadWelcomeLabel). En accent
     dès que la synchro est disponible, comme partout ailleurs dans l'appli. */
  .music-login-foot { display: inline-flex; align-items: center; gap: 6px; }
  .welcome-lyrics-btn {
    display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
    width: 22px; height: 22px; border-radius: 50%; border: 1px solid var(--border);
    background: rgba(237,245,255,.06); color: var(--text-dim); cursor: pointer; padding: 0;
  }
  .welcome-lyrics-btn:hover { border-color: var(--accent); color: var(--accent); }
  .welcome-lyrics-btn.lyrics-synced { color: var(--accent); border-color: var(--accent); }
  /* Même doigt animé que sur le disque (.disc-tap-hint) — mêmes animations
     (disc-finger-tap/disc-tap-ripple), juste redimensionné pour une icône
     bien plus petite. Affiché une fois la lecture lancée (voir showWelcome
     LyricsHint, déclenché par l'événement 'playing') SEULEMENT si la
     synchro existe : pas la peine d'attirer l'œil vers des paroles à lire
     seulement en brut. */
  .welcome-lyrics-btn-wrap { position: relative; display: inline-flex; }
  .lyrics-tap-hint {
    position: absolute; z-index: 3; left: 55%; top: 55%; width: 22px; height: 26px;
    pointer-events: none; opacity: 0; transition: opacity .25s; filter: drop-shadow(0 2px 5px #0008);
  }
  .lyrics-tap-hint.visible { opacity: 1; }
  .lyrics-tap-hint svg { position: relative; display: block; width: 100%; height: 100%; transform-origin: 45% 10%; animation: disc-finger-tap 2.8s ease-in-out infinite; }
  .lyrics-tap-hint .disc-tap-wave { position: absolute; width: 12px; height: 12px; left: 3px; top: -2px; border: 1.5px solid var(--accent); border-radius: 50%; animation: disc-tap-ripple 2.8s ease-out infinite; }
  @media (prefers-reduced-motion: reduce) { .lyrics-tap-hint svg, .lyrics-tap-hint .disc-tap-wave { animation: none; } .lyrics-tap-hint .disc-tap-wave { display: none; } }
  /* Fond de la dialog volontairement TRÈS transparent, sans flou : le but
     explicite est de continuer à voir le disque tourner derrière pendant
     que les paroles défilent, pas de l'assombrir comme les autres modales
     de cette page (.login-dialog::backdrop, bien plus opaque). */
  .welcome-lyrics-dialog::backdrop { background: rgba(5,8,22,.32); }
  /* position:fixed + left/width posés en JS (voir positionWelcomeLyricsDialog) :
     la dialog doit rester au-dessus de la colonne du disque (.music-login-
     story), jamais sur le cadre de connexion à côté — un simple centrage
     automatique (margin:auto) centre sur TOUT le viewport, pas sur une
     colonne précise. */
  .welcome-lyrics-dialog {
    /* max-width:none — sans ça, le style par défaut du navigateur pour
       dialog:modal (calc(100% - 38px) environ) plafonne la largeur malgré
       un width explicite posé en JS (positionWelcomeLyricsDialog), laissant
       un filet vide sur le bord droit. */
    position: fixed; margin: 0; max-width: none; max-height: calc(100dvh - 32px);
    padding: 20px 20px 20px 5px; border: 1px solid var(--border); border-radius: 20px;
    background: rgba(12,20,43,.55); backdrop-filter: blur(10px); color: var(--text);
    box-shadow: 0 24px 80px #0006; overflow-y: auto; overscroll-behavior: contain;
    scrollbar-width: none;
  }
  .welcome-lyrics-dialog::-webkit-scrollbar { display: none; }
  /* Arrivée bien plus lente que les autres dialogs (.22s) — celle-ci
     n'apparaît qu'après le glissement du contenu (animateContentShift,
     ~0.5s), doit se sentir comme la suite fluide du même geste, pas un
     pop-in sec. */
  .welcome-lyrics-dialog[open] { animation: welcome-lyrics-in .6s cubic-bezier(.22,1,.36,1); }
  @keyframes welcome-lyrics-in { from { opacity: 0; transform: translateY(28px); } to { opacity: 1; transform: translateY(0); } }
  /* Sortie jouée AVANT le close() natif (voir requestCloseWelcomeLyrics) —
     forwards : reste à opacity:0 jusqu'à la fermeture réelle, pas de
     flash-back au visible entre-temps. */
  .welcome-lyrics-dialog.closing { animation: welcome-lyrics-out .48s cubic-bezier(.4,0,.2,1) forwards; }
  @keyframes welcome-lyrics-out { from { opacity: 1; transform: translateY(0); } to { opacity: 0; transform: translateY(28px); } }
  /* "Paroles" en titre retiré (inutile, on le sait déjà) — remplacé par
     l'artiste/le titre du morceau en cours (voir setWelcomeLyrics), qui
     réutilise cet élément à la fois comme label visuel ET comme
     aria-labelledby de la dialog. Croix TOUJOURS visible même en scrollant
     un long texte : la marge négative ramène ce bandeau pile au bord haut
     (annule le padding de la dialog, désormais asymétrique — 5px à gauche,
     20px ailleurs), position:sticky l'y accroche pendant le scroll, et un
     fond propre évite que les lignes de paroles ne se voient au travers.
     Ligne basse : peu de padding vertical, juste assez pour la petite croix. */
  .welcome-lyrics-header {
    position: sticky; top: -20px; margin: -20px -20px 8px -5px; padding: 4px 12px 4px 5px;
    background: rgba(12,20,43,.85); backdrop-filter: blur(6px); z-index: 2;
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    border-radius: 20px 20px 0 0;
  }
  /* Petite croix discrète — pas besoin d'une grosse cible tactile ici,
     jamais le seul moyen de fermer (clic à l'extérieur/Échap marchent déjà,
     comme les autres dialogs de cette page). */
  #welcome-lyrics-close { width: 26px; height: 26px; font-size: 15px; flex-shrink: 0; }
  /* Le focus ring global (:focus-visible, outline-offset:3px) déborde du
     bandeau — trop peu de marge autour d'un si petit bouton dans une si
     fine ligne. Anneau resserré et vers l'intérieur (offset négatif) pour
     cette croix précise, sans toucher le style de focus des autres boutons
     de la page. */
  #welcome-lyrics-close:focus, #welcome-lyrics-close:focus-visible { outline-width: 2px; outline-offset: -2px; }
  .welcome-lyrics-title {
    margin: 0; font-size: .82rem; font-weight: 600; color: var(--text);
    overflow: hidden; white-space: nowrap; min-width: 0; flex: 1;
  }
  /* Défilement seulement si le texte dépasse (classe .scrolling posée en
     JS après mesure, voir setWelcomeLyrics) — même principe que le titre du
     mini-lecteur de l'appli principale : deux copies du texte côte à côte,
     translation en boucle de 0 à -50% pour un raccord de boucle invisible.
     La seconde copie (aria-hidden) ne sert qu'à ce raccord — repliée tant
     que le texte tient déjà dans la largeur disponible. */
  .welcome-lyrics-title-track { display: inline-flex; width: max-content; }
  .welcome-lyrics-title-text { white-space: nowrap; padding-right: 32px; }
  .welcome-lyrics-title-text[aria-hidden="true"] { display: none; }
  .welcome-lyrics-title.scrolling .welcome-lyrics-title-text[aria-hidden="true"] { display: inline; }
  .welcome-lyrics-title.scrolling .welcome-lyrics-title-track { animation: welcomeLyricsTitleMarquee linear infinite; }
  @keyframes welcomeLyricsTitleMarquee { from { transform: translateX(0); } to { transform: translateX(-50%); } }
  @media (prefers-reduced-motion: reduce) { .welcome-lyrics-title.scrolling .welcome-lyrics-title-track { animation: none; } }
  .welcome-lyrics-body { font-size: .92rem; line-height: 1.7; text-align: center; white-space: pre-line; }
  .welcome-lyrics-line { padding: 4px 0; color: var(--text-dim); transition: color .2s ease, font-size .2s ease; }
  .welcome-lyrics-line.active { color: var(--accent); font-weight: 700; font-size: 1.15em; }
  body.login-modal-open { overflow: hidden; }
  .login-dialog { width: min(440px, calc(100% - 32px)); max-height: calc(100dvh - 32px); margin: auto; padding: 24px; border: 1px solid var(--border); border-radius: 20px; background: var(--panel); color: var(--text); box-shadow: 0 24px 80px #0006; overflow-y: auto; overscroll-behavior: contain; }
  .login-dialog::backdrop { background: #050816b3; backdrop-filter: blur(5px); }
  .login-dialog[open] { animation: dialog-in .22s ease-out; }
  .login-dialog-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
  .login-dialog h2 { font-size: 21px; margin: 0; letter-spacing: -.5px; }
  .dialog-close { flex-shrink: 0; width: 44px; height: 44px; border: 1px solid var(--border); border-radius: 50%; background: var(--chip-bg); color: var(--text); font-size: 26px; cursor: pointer; }
  .login-dialog .request-form { display: block; margin: 0; padding: 0; border: 0; }
  .login-dialog input[type=text], .login-dialog input[type=email] { font-size: 16px; min-height: 44px; }
  .login-dialog input.code-input { font-size: 28px; letter-spacing: .35em; text-align: center; font-variant-numeric: tabular-nums; }
  .code-help { margin: 0 0 16px; font-size: .88rem; line-height: 1.5; color: var(--text-dim); }
  .login-dialog button.submit { min-height: 46px; }
  .login-dialog .status-line { line-height: 1.5; }
  .recovery-link { min-height: 44px; }
  :focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }
  @keyframes dialog-in { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
  @keyframes sheet-in { from { transform: translateY(100%); } to { transform: translateY(0); } }
  @media (max-width: 760px) {
    body.music-login { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); align-items: center; gap: 12px; min-height: 100svh; padding: max(16px, env(safe-area-inset-top)) max(10px, env(safe-area-inset-right)) max(16px, env(safe-area-inset-bottom)) max(10px, env(safe-area-inset-left)); }
    .music-login-story { display: flex; min-width: 0; min-height: 480px; margin: 0; gap: 20px; }
    .music-login-story .music-brand { margin: 0; font-size: 24px; }
    .music-login-story > div:not(.music-login-record) { display: block; }
    .music-login-story .music-eyebrow { font-size: 7px; letter-spacing: 1.1px; margin: 0 0 12px; }
    .music-login-story h2 { font-size: clamp(21px, 5vw, 36px); line-height: 1.08; letter-spacing: -1px; margin: 0 0 16px; }
    .music-login-story > div > p:not(.music-eyebrow) { font-size: 10px; line-height: 1.65; margin: 0; }
    .music-login-story > div > p:not(.music-eyebrow) br { display: none; }
    .music-login-story .music-login-record { display: block; height: auto; overflow: visible; }
    .music-login-record .music-disc { width: min(100%, 250px); margin: 0; }
    .music-login-record .music-disc-label { width: 29%; padding: 0; background: #e78c56; }
    #welcome-audio-status { font-size: 9px; line-height: 1.4; margin: 10px 0 0; }
    .music-login-story .music-login-foot { display: block; font-size: 6px; line-height: 1.5; letter-spacing: 1px; }
    .music-login > .card { min-width: 0; width: 100%; max-width: none; margin: 0; padding: 22px 12px 14px; border-radius: 16px; }
    .music-login .card > .music-eyebrow { font-size: 6px; letter-spacing: 1px; margin: 0 0 16px; }
    .music-login h1 { font-size: clamp(24px, 5vw, 34px); line-height: 1.15; letter-spacing: -1px; margin-bottom: 16px; }
    .music-login .subtitle { font-size: 10px; line-height: 1.65; margin-bottom: 18px; }
    .music-login .remember-row { align-items: flex-start; margin-bottom: 18px; gap: 6px; }
    .music-login .remember-row label { font-size: 9px; line-height: 1.4; }
    .music-login .remember-row input { flex-shrink: 0; width: 16px; height: 16px; margin: 0; }
    .music-login .accounts { flex-direction: column; align-items: center; gap: 10px; }
    .music-login .account-btn, .music-login .add-btn { width: 100%; max-width: 112px; min-height: 86px; padding: 12px 8px; }
    .music-login .account-btn span { overflow-wrap: anywhere; font-size: 12px; }
    .music-login .account-btn img { width: 48px; height: 48px; }
    .music-login .add-btn span:last-child { font-size: 10px; }
    .music-login .recovery-link { display: block; margin: 14px auto 0; padding: 0; font-size: 11px; }
    .music-login .card > .status-line { margin-top: 8px; font-size: 10px; line-height: 1.4; }
    .login-dialog { width: 100%; max-width: 100%; max-height: calc(100dvh - 12px); margin: auto 0 0; padding: 12px 20px max(20px, env(safe-area-inset-bottom)); border-radius: 22px 22px 0 0; }
    .login-dialog::before { content: ''; display: block; width: 36px; height: 4px; border-radius: 4px; background: var(--border); margin: 0 auto 8px; }
    .login-dialog[open] { animation: sheet-in .25s ease-out; }
  }
  @media (max-width: 380px) {
    body.music-login { gap: 10px; }
    .music-login > .card { padding: 16px 10px 10px; }
    .music-login .card > .music-eyebrow { margin-bottom: 10px; }
    .music-login .subtitle, .music-login .remember-row { margin-bottom: 12px; }
    .music-login .account-btn, .music-login .add-btn { padding: 8px 6px; min-height: 76px; }
    .music-login .account-btn img { width: 40px; height: 40px; }
    .music-login-story { min-height: 460px; gap: 16px; }
  }
  @media (prefers-reduced-motion: reduce) { .login-dialog[open], .welcome-lyrics-dialog[open] { animation: none; } }
  .passkey-btn {
    display: flex; align-items: center; justify-content: center; margin: 18px auto 0;
    width: 64px; height: 64px; padding: 0; border-radius: 50%; cursor: pointer;
    border: 1px solid var(--accent); background: rgba(73, 232, 255, .1); color: var(--accent);
    box-shadow: 0 0 22px rgba(73, 232, 255, .18);
  }
  .passkey-btn svg { width: 34px; height: 34px; }
  .passkey-btn:hover { background: rgba(73, 232, 255, .18); }
  .passkey-btn[hidden] { display: none; }
  /* Centré verticalement par défaut (voir body.music-login dans music.css,
     inchangé) — seulement remonté en haut d'écran quand les paroles
     s'ouvrent (voir animateContentShift), pour laisser la place à leur
     fenêtre sans jamais recouvrir le contenu. align-items n'étant pas
     animable, le glissement visuel se fait via un transform (technique
     FLIP) posé en JS ; cette classe ne fait que fixer la position finale. */
  body.music-login.lyrics-layout-top { align-items: start; }
  @media (max-width: 760px) { body.music-login.lyrics-layout-top { align-items: start; } }
</style>
</head>
<body class="music-login">
  <section class="music-login-story"><a class="music-brand" href="index.php"><span aria-hidden="true">◖</span> ytdl<span class="music-brand-dot">+</span></a><div><p class="music-eyebrow">VOTRE ESPACE MUSICAL</p><h2>Tous vos titres.<br>Toutes vos vibes.</h2><p>Votre bibliothèque, vos playlists et vos découvertes. <br>Au même endroit, à votre rythme.</p></div><div class="music-login-record"><div class="welcome-disc-wrap"><button type="button" id="welcome-disc" class="music-disc" aria-label="Écouter un morceau au hasard" aria-pressed="false"><div class="welcome-disc-rotor"><div class="music-disc-label"><svg class="login-vinyl-label" viewBox="0 0 200 200" aria-hidden="true"><defs><clipPath id="track-art-clip"><circle cx="100" cy="100" r="99"/></clipPath><path id="track-artist-arc" d="M 24 100 A 76 76 0 0 1 176 100"/><path id="track-title-arc" d="M 16 100 A 84 84 0 0 0 184 100"/></defs><circle cx="100" cy="100" r="100" fill="#e78c56"/><image id="track-artwork" x="0" y="0" width="200" height="200" preserveAspectRatio="xMidYMid slice" clip-path="url(#track-art-clip)"/><path d="M 15 88 A 86 86 0 0 1 185 88 M 15 112 A 86 86 0 0 0 185 112" stroke="#07101c" stroke-opacity=".86" stroke-width="25" stroke-linecap="round" fill="none"/><text class="track-label-text"><textPath id="track-artist" href="#track-artist-arc" startOffset="50%" text-anchor="middle"></textPath></text><text class="track-label-text"><textPath id="track-title" href="#track-title-arc" startOffset="50%" text-anchor="middle">Découverte musicale</textPath></text></svg></div></div></button><span class="disc-tap-hint" aria-hidden="true"><span class="disc-tap-wave"></span><svg viewBox="0 0 64 76" fill="none"><path d="M24 37V12a5 5 0 0 1 10 0v21-5a5 5 0 0 1 10 0v7-3a5 5 0 0 1 10 0v6a5 5 0 0 1 9 3v12c0 12-8 20-20 20h-5c-7 0-11-4-15-9L9 47a5 5 0 0 1 8-6l7 7V37Z" fill="#edf5ff" stroke="#0b1527" stroke-width="2.5" stroke-linejoin="round"/></svg></span></div><p id="welcome-audio-status" role="status" aria-live="polite">Clique sur le disque pour écouter</p><audio id="welcome-audio" preload="none" src="welcome-audio.php?key=<?php echo htmlspecialchars($welcomeAudioKey, ENT_QUOTES); ?>"></audio></div><span class="music-login-foot">LA MUSIQUE VOUS APPARTIENT.<span class="welcome-lyrics-btn-wrap"><button type="button" id="welcome-lyrics-btn" class="welcome-lyrics-btn" style="display:none;" aria-label="Voir les paroles" title="Voir les paroles"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path><line x1="7" y1="8" x2="17" y2="8"></line><line x1="7" y1="12" x2="13" y2="12"></line></svg></button><span class="lyrics-tap-hint" id="welcome-lyrics-hint" aria-hidden="true"><span class="disc-tap-wave"></span><svg viewBox="0 0 64 76" fill="none"><path d="M24 37V12a5 5 0 0 1 10 0v21-5a5 5 0 0 1 10 0v7-3a5 5 0 0 1 10 0v6a5 5 0 0 1 9 3v12c0 12-8 20-20 20h-5c-7 0-11-4-15-9L9 47a5 5 0 0 1 8-6l7 7V37Z" fill="#edf5ff" stroke="#0b1527" stroke-width="2.5" stroke-linejoin="round"/></svg></span></span></span></section>
  <dialog class="welcome-lyrics-dialog" id="welcome-lyrics-dialog" aria-labelledby="welcome-lyrics-title">
    <div class="welcome-lyrics-header"><h2 class="welcome-lyrics-title" id="welcome-lyrics-title"><span class="welcome-lyrics-title-track"><span class="welcome-lyrics-title-text" id="welcome-lyrics-title-text">Paroles</span><span class="welcome-lyrics-title-text" aria-hidden="true"></span></span></h2><button type="button" class="dialog-close" id="welcome-lyrics-close" aria-label="Fermer">×</button></div>
    <div class="welcome-lyrics-body" id="welcome-lyrics-body"></div>
  </dialog>
  <div class="card">
    <p class="music-eyebrow">BIENVENUE CHEZ VOUS</p><h1>On écoute quoi ?</h1>
    <p class="subtitle">Retrouve ton espace musical ou demande un compte.</p>

    <?php if ($welcomeAccount): ?>
    <div class="remember-row">
      <input type="checkbox" id="remember-me">
      <label for="remember-me">Rester connecté sur ce navigateur</label>
    </div>
    <?php endif; ?>

    <div class="accounts">
      <?php if ($welcomeAccount): $u = $welcomeAccount; ?>
        <button class="account-btn" data-user-id="<?php echo (int)$u['id']; ?>">
          <img src="avatars/<?php echo htmlspecialchars($u['avatar']); ?>" alt="">
          <div class="spinner"></div>
          <span><?php echo htmlspecialchars($u['name']); ?></span>
        </button>
      <?php endif; ?>
      <button class="add-btn" id="request-btn" aria-haspopup="dialog" aria-controls="request-dialog" aria-expanded="false">
        <span class="plus">+</span>
        <span>Demander un compte</span>
      </button>
    </div>

    <!-- Connexion par empreinte (voir ui/passkey.js) : simple logo, affiché
         seulement si cet appareil a déjà activé l'empreinte. L'avatar, lui,
         reste réservé au lien par e-mail. -->
    <button type="button" class="passkey-btn" id="passkey-btn" aria-label="Se connecter avec l’empreinte" title="Se connecter avec l’empreinte" hidden>
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M12 11v3M8.5 8.5a5 5 0 0 1 8.5 3.5v1M7 12c0-1 .2-2 .7-2.8M17 16.5c-.3 1.5-.9 2.8-1.7 3.9M12 7a5 5 0 0 0-5 5v2c0 2 .5 3.8 1.5 5.3M15 14v.5c0 2-.4 3.8-1.2 5.3M10 14v1c0 1.6-.3 3.1-.9 4.4"/><path d="M4.6 9.2A8 8 0 0 1 19.4 9M3.5 13.5V12M20.5 12.5v.8"/></svg>
    </button>
    <button type="button" class="recovery-link" id="recovery-btn" aria-expanded="false" aria-controls="recovery-dialog" aria-haspopup="dialog">Compte perdu ?</button>
    <div class="status-line" id="status-line" role="status" aria-live="polite"></div>
  </div>

  <dialog class="login-dialog" id="recovery-dialog" aria-labelledby="recovery-title">
    <div class="login-dialog-header"><h2 id="recovery-title" tabindex="-1" autofocus>Compte perdu ?</h2><button type="button" class="dialog-close" aria-label="Fermer">×</button></div>
    <form class="request-form" id="recovery-form">
      <label for="recovery-email">L’email de ton compte</label>
      <input type="email" id="recovery-email" autocomplete="email" required>
      <button type="submit" class="submit">Recevoir mon lien de connexion</button>
    </form>
    <div class="status-line" role="status" aria-live="polite"></div>
  </dialog>
  <dialog class="login-dialog" id="request-dialog" aria-labelledby="request-title">
    <div class="login-dialog-header"><h2 id="request-title" tabindex="-1" autofocus>Demander un compte</h2><button type="button" class="dialog-close" aria-label="Fermer">×</button></div>
    <form class="request-form" id="request-form">
      <label for="req-name">Prénom</label>
      <input type="text" id="req-name" autocomplete="given-name" required>
      <label for="req-email">Email</label>
      <input type="email" id="req-email" autocomplete="email" required>
      <button type="submit" class="submit">Envoyer la demande</button>
    </form>
    <div class="status-line" role="status" aria-live="polite"></div>
  </dialog>
  <!-- Code à 6 chiffres de l'email (login-code.php) : indispensable dans
       l'appli ajoutée à l'écran d'accueil d'un iPhone, où le lien s'ouvre
       dans le navigateur et ne connecte pas l'appli. -->
  <dialog class="login-dialog" id="code-dialog" aria-labelledby="code-title">
    <div class="login-dialog-header"><h2 id="code-title" tabindex="-1">Lien envoyé !</h2><button type="button" class="dialog-close" aria-label="Fermer">×</button></div>
    <p class="code-help">Ouvre le lien reçu par email, ou tape ici le code à 6 chiffres qu’il contient (indispensable dans l’appli installée sur iPhone). Pense à regarder les indésirables.</p>
    <form class="request-form" id="code-form">
      <label for="code-input">Code reçu par email</label>
      <input type="text" id="code-input" class="code-input" inputmode="numeric" autocomplete="one-time-code" maxlength="7" pattern="[0-9 ]*" required>
      <button type="submit" class="submit">Me connecter</button>
    </form>
    <div class="status-line" role="status" aria-live="polite"></div>
  </dialog>

<script src="ui/passkey.js?v=<?php echo (int)@filemtime(__DIR__ . '/ui/passkey.js'); ?>"></script>
<script src="ui/immersive.js?v=<?php echo (int)@filemtime(__DIR__ . '/ui/immersive.js'); ?>"></script>
<script>
  const statusLine = document.getElementById('status-line');
  function showStatus(text, kind, target = statusLine) {
    target.textContent = text;
    target.className = 'status-line visible' + (kind ? ' ' + kind : '');
  }
  function hideStatus() {
    statusLine.className = 'status-line';
    statusLine.textContent = '';
  }

  let sendingLogin = false;

  // --- Connexion par empreinte -------------------------------------------
  // Uniquement par le bouton « empreinte » : toucher l'avatar envoie
  // toujours le lien par e-mail.
  const passkeyBtn = document.getElementById('passkey-btn');
  let passkeyLocal = null;
  let passkeyReady = false;
  (async () => {
    if (!window.YtdlPasskey) return;
    passkeyLocal = window.YtdlPasskey.local();
    if (!passkeyLocal || !(await window.YtdlPasskey.available())) return;
    passkeyReady = true;
    if (passkeyLocal.name) passkeyBtn.setAttribute('aria-label', 'Se connecter avec l’empreinte (' + passkeyLocal.name + ')');
    passkeyBtn.hidden = false;
  })();
  async function loginWithPasskey(btn) {
    hideStatus();
    if (btn) btn.classList.add('loading');
    passkeyBtn.disabled = true;
    try {
      const res = await window.YtdlPasskey.login();
      showStatus('Empreinte reconnue, connexion…', 'success');
      window.location.href = res.redirect || 'capture.php';
      return true;
    } catch (err) {
      showStatus(window.YtdlPasskey.explain(err), 'error');
      if (!window.YtdlPasskey.local()) passkeyBtn.hidden = true;
      return false;
    } finally {
      if (btn) btn.classList.remove('loading');
      passkeyBtn.disabled = false;
    }
  }
  passkeyBtn.addEventListener('click', () => loginWithPasskey(null));

  document.querySelectorAll('.account-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (sendingLogin) return; // clic ignoré tant qu'un envoi est en cours
      sendingLogin = true;

      document.querySelectorAll('.account-btn').forEach(b => b.disabled = true);
      document.getElementById('request-btn').disabled = true;
      btn.classList.add('loading');
      hideStatus();

      try {
        const remember = document.getElementById('remember-me').checked ? '1' : '0';
        const res = await fetch('send-login.php?remember=' + remember);
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Erreur lors de l\'envoi.');
        if (data.direct) {
          showStatus('Déjà reconnu sur cet appareil, connexion…', 'success');
          window.location.href = 'capture.php';
          return;
        }
        showStatus('Lien envoyé ! Vérifie ta boîte email (valable 48h).', 'success');
        openCodeDialog(data.code_request);
      } catch (err) {
        showStatus(err.message, 'error');
        document.querySelectorAll('.account-btn').forEach(b => b.disabled = false);
        document.getElementById('request-btn').disabled = false;
      } finally {
        btn.classList.remove('loading');
        sendingLogin = false;
      }
    });
  });

  const requestBtn = document.getElementById('request-btn');
  const requestForm = document.getElementById('request-form');
  const recoveryBtn = document.getElementById('recovery-btn');
  const recoveryForm = document.getElementById('recovery-form');
  const requestDialog = document.getElementById('request-dialog');
  const recoveryDialog = document.getElementById('recovery-dialog');
  const requestStatus = requestDialog.querySelector('.status-line');
  const recoveryStatus = recoveryDialog.querySelector('.status-line');
  function wireDialog(button, dialog) {
    button.addEventListener('click', () => {
      hideStatus();
      dialog.querySelector('.status-line').className = 'status-line';
      document.body.classList.add('login-modal-open');
      dialog.showModal();
      button.setAttribute('aria-expanded', 'true');
    });
    dialog.querySelector('.dialog-close').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => {
      const rect = dialog.getBoundingClientRect();
      if (event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) dialog.close();
    });
    dialog.addEventListener('close', () => {
      document.body.classList.remove('login-modal-open');
      button.setAttribute('aria-expanded', 'false');
      button.focus({preventScroll: true});
    });
  }
  wireDialog(requestBtn, requestDialog);
  wireDialog(recoveryBtn, recoveryDialog);
  recoveryForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submit = recoveryForm.querySelector('button');
    submit.disabled = true;
    showStatus('Envoi du lien…', '', recoveryStatus);
    try {
      const response = await fetch('recover-account.php', {
        method: 'POST',
        body: new URLSearchParams({email: document.getElementById('recovery-email').value.trim()})
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Envoi impossible. Réessaie plus tard.');
      showStatus('Si un compte actif correspond à cet email, un lien de connexion a été envoyé. Vérifie aussi les indésirables.', 'success', recoveryStatus);
      recoveryForm.reset();
      recoveryDialog.close();
      openCodeDialog(data.code_request);

    } catch (error) {
      showStatus(error.message, 'error', recoveryStatus);
    } finally {
      submit.disabled = false;
    }
  });

  // --- Code à 6 chiffres -----------------------------------------------
  const codeDialog = document.getElementById('code-dialog');
  const codeForm = document.getElementById('code-form');
  const codeInput = document.getElementById('code-input');
  const codeStatus = codeDialog.querySelector('.status-line');
  let codeRequest = null;
  function openCodeDialog(request) {
    if (!request) return;
    codeRequest = request;
    codeForm.reset();
    codeStatus.className = 'status-line';
    document.body.classList.add('login-modal-open');
    if (!codeDialog.open) codeDialog.showModal();
    setTimeout(() => codeInput.focus(), 60);
  }
  codeDialog.querySelector('.dialog-close').addEventListener('click', () => codeDialog.close());
  codeDialog.addEventListener('close', () => document.body.classList.remove('login-modal-open'));
  codeForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submit = codeForm.querySelector('button');
    submit.disabled = true;
    showStatus('Vérification…', '', codeStatus);
    try {
      const response = await fetch('login-code.php', {
        method: 'POST',
        body: new URLSearchParams({ request: codeRequest || '', code: codeInput.value }),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Code refusé.');
      showStatus('Connexion…', 'success', codeStatus);
      window.location.href = 'capture.php';
    } catch (error) {
      showStatus(error.message, 'error', codeStatus);
      submit.disabled = false;
    }
  });

  requestForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = document.getElementById('req-name').value.trim();
    const email = document.getElementById('req-email').value.trim();
    if (!name || !email) return;

    const submitBtn = requestForm.querySelector('button');
    submitBtn.disabled = true;
    showStatus('Envoi de la demande…', '', requestStatus);

    try {
      const res = await fetch('request-account.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name, email })
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Erreur lors de la demande.');
      showStatus('Demande envoyée ! Elle sera validée par un administrateur.', 'success', requestStatus);
      requestForm.reset();

    } catch (err) {
      showStatus(err.message, 'error', requestStatus);
    } finally {
      submitBtn.disabled = false;
    }
  });

  const welcomeDisc = document.getElementById('welcome-disc');
  const welcomeAudio = document.getElementById('welcome-audio');
  const welcomeAudioStatus = document.getElementById('welcome-audio-status');
  async function loadWelcomeLabel() {
    try {
      const response = await fetch(welcomeAudio.getAttribute('src') + '&meta=1');
      if (!response.ok) return;
      const track = await response.json();
      const artist = track.artist || 'Artiste inconnu';
      const title = track.title || 'Sans titre';
      for (const [id, value] of [['track-artist', artist], ['track-title', title]]) {
        const path = document.getElementById(id);
        const letters = Array.from(value);
        path.textContent = letters.length > 48 ? letters.slice(0, 47).join('') + '…' : value;
        const text = path.parentElement;
        const width = text.getComputedTextLength();
        if (width > 210) text.style.fontSize = Math.max(10, 16 * 210 / width) + 'px';
      }
      welcomeDisc.title = artist + ' — ' + title;
      const image = document.getElementById('track-artwork');
      if (track.thumbnail) {
        image.addEventListener('error', () => image.removeAttribute('href'), {once: true});
        image.setAttribute('href', track.thumbnail);
      }
      setWelcomeLyrics(track.lyrics || null, Array.isArray(track.synced) ? track.synced : null, track.offset || 0, artist, title);
    } catch (_) { /* L'étiquette de repli reste utilisable sans métadonnées. */ }
  }

  // Paroles de l'ambiance d'accueil : icône à côté de "LA MUSIQUE VOUS
  // APPARTIENT.", masquée tant qu'on ne sait pas s'il y en a (voir
  // loadWelcomeLabel). Modale au fond volontairement très transparent
  // (voir CSS, .welcome-lyrics-dialog) : le disque continue de tourner et
  // de se voir derrière pendant que les paroles défilent.
  const welcomeLyricsBtn = document.getElementById('welcome-lyrics-btn');
  const welcomeLyricsDialog = document.getElementById('welcome-lyrics-dialog');
  const welcomeLyricsBody = document.getElementById('welcome-lyrics-body');
  const welcomeLyricsClose = document.getElementById('welcome-lyrics-close');
  const welcomeLyricsHint = document.getElementById('welcome-lyrics-hint');
  const welcomeLyricsTitle = document.getElementById('welcome-lyrics-title');
  const welcomeLyricsTitleTexts = welcomeLyricsTitle.querySelectorAll('.welcome-lyrics-title-text');
  const welcomeLyricsTitleTrack = welcomeLyricsTitle.querySelector('.welcome-lyrics-title-track');
  // Défilement si trop long — même principe que le titre du mini-lecteur
  // de l'appli principale (measureMiniPlayerTitleOverflow) : mesuré après
  // deux rAF (la mise en page doit s'être stabilisée) et remesuré une fois
  // les polices web prêtes, sinon un titre limite mesuré avec la police de
  // repli peut déborder silencieusement une fois "Space Grotesk" chargée.
  function measureWelcomeLyricsTitleOverflow() {
    const singleWidth = welcomeLyricsTitleTexts[0].getBoundingClientRect().width;
    const containerWidth = welcomeLyricsTitle.getBoundingClientRect().width;
    if (singleWidth > containerWidth) {
      const PIXELS_PER_SECOND = 45;
      welcomeLyricsTitleTrack.style.animationDuration = (singleWidth / PIXELS_PER_SECOND) + 's';
      welcomeLyricsTitle.classList.add('scrolling');
    } else {
      welcomeLyricsTitle.classList.remove('scrolling');
      welcomeLyricsTitleTrack.style.animationDuration = '';
    }
  }
  function setWelcomeLyricsTitleText(text) {
    welcomeLyricsTitleTexts.forEach(el => { el.textContent = text; });
    welcomeLyricsTitle.classList.remove('scrolling');
    welcomeLyricsTitleTrack.style.animationDuration = '';
    requestAnimationFrame(() => requestAnimationFrame(measureWelcomeLyricsTitleOverflow));
  }
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(() => {
      requestAnimationFrame(() => requestAnimationFrame(measureWelcomeLyricsTitleOverflow));
    });
  }
  let lyricsHintShown = false;
  let welcomeLyricsSynced = null;
  let welcomeLyricsOffset = 0;
  let welcomeLyricsActiveIndex = -1;
  // Même principe que le doigt sur le disque (hint-dismissed) : montré une
  // fois la lecture lancée, seulement s'il y a une synchro à découvrir —
  // jamais pour de simples paroles brutes. Appelé à la fois depuis
  // l'événement 'playing' ET depuis setWelcomeLyrics (l'un peut arriver
  // après l'autre : la lecture démarre parfois avant que les paroles ne
  // soient revenues du serveur, ou l'inverse).
  function maybeShowLyricsHint() {
    if (lyricsHintShown || !welcomeLyricsSynced || welcomeAudio.paused) return;
    lyricsHintShown = true;
    welcomeLyricsHint.classList.add('visible');
  }
  function setWelcomeLyrics(lyrics, synced, offset, artist, title) {
    welcomeLyricsSynced = synced;
    welcomeLyricsOffset = offset || 0;
    welcomeLyricsActiveIndex = -1;
    setWelcomeLyricsTitleText((artist ? artist + ' — ' : '') + (title || ''));
    welcomeLyricsBtn.style.display = lyrics ? '' : 'none';
    welcomeLyricsBtn.classList.toggle('lyrics-synced', !!synced);
    welcomeLyricsBtn.title = synced ? 'Voir les paroles synchro' : 'Voir les paroles';
    if (synced) {
      // textContent, jamais innerHTML : les paroles synchro sont saisies par
      // les utilisateurs (lyrics-manual-sync.php) et affichées ici aux
      // visiteurs non connectés — du HTML injecté s'y exécuterait.
      welcomeLyricsBody.replaceChildren(...synced.map(l => {
        const div = document.createElement('div');
        div.className = 'welcome-lyrics-line';
        div.textContent = l.text || ' ';
        return div;
      }));
    } else {
      welcomeLyricsBody.textContent = lyrics || '';
    }
    maybeShowLyricsHint();
  }
  function updateWelcomeLyricsSync() {
    if (!welcomeLyricsSynced || welcomeLyricsDialog.open === false) return;
    const t = welcomeAudio.currentTime - welcomeLyricsOffset;
    let idx = -1;
    for (let i = 0; i < welcomeLyricsSynced.length; i++) {
      if (welcomeLyricsSynced[i].time <= t) idx = i; else break;
    }
    if (idx === welcomeLyricsActiveIndex) return;
    welcomeLyricsActiveIndex = idx;
    const lines = welcomeLyricsBody.querySelectorAll('.welcome-lyrics-line');
    lines.forEach((el, i) => el.classList.toggle('active', i === idx));
    if (idx >= 0) lines[idx].scrollIntoView({ block: 'center', behavior: 'smooth' });
  }
  welcomeAudio.addEventListener('timeupdate', updateWelcomeLyricsSync);
  // Le contenu est remonté en haut d'écran (voir body.music-login,
  // align-items:start) : l'espace resté vide en bas devient celui de la
  // modale — plus rien en dessous ni de la colonne du disque NI de la carte
  // de connexion à ce niveau, donc pleine largeur d'écran désormais (plus de
  // raison de la cantonner à une colonne). top/max-height posés en px
  // (mesurés APRÈS showModal(), le contenu — liste de paroles — n'existe
  // pas avant). Recalculé à l'ouverture ET au redimensionnement (rotation
  // d'écran, fenêtre redimensionnée pendant que c'est ouvert).
  const welcomeLyricsCard = document.querySelector('.card');
  const welcomeLyricsStory = document.querySelector('.music-login-story');
  function positionWelcomeLyricsDialog() {
    // Bord gauche collé à l'écran, bord droit ALIGNÉ sur celui du cadre de
    // connexion (plutôt que sur le bord de l'écran/navigateur, demandé
    // explicitement) — width en px directement jusqu'à ce bord, pas de
    // right:0 ici.
    const cardRect = welcomeLyricsCard.getBoundingClientRect();
    welcomeLyricsDialog.style.left = '0px';
    welcomeLyricsDialog.style.right = '';
    welcomeLyricsDialog.style.width = cardRect.right + 'px';
    const contentBottom = Math.max(welcomeLyricsStory.getBoundingClientRect().bottom, cardRect.bottom);
    const top = contentBottom + 16;
    welcomeLyricsDialog.style.top = top + 'px';
    welcomeLyricsDialog.style.maxHeight = Math.max(120, window.innerHeight - top - 16) + 'px';
  }
  window.addEventListener('resize', () => { if (welcomeLyricsDialog.open) positionWelcomeLyricsDialog(); });
  // Centré par défaut, remonté seulement à l'ouverture des paroles (voir
  // .lyrics-layout-top plus haut) — align-items n'étant pas animable, on
  // simule le glissement via un transform (technique FLIP) : on mesure la
  // position AVANT de basculer la classe, on rebascule, on mesure APRÈS, et
  // on anime la différence à rebours jusqu'à 0. Marche dans les deux sens
  // (ouverture ET fermeture). `done` n'est appelé qu'une fois le glissement
  // terminé — la modale ne doit apparaître/se positionner qu'à ce moment,
  // jamais pendant, sous peine de se caler sur une position intermédiaire.
  function animateContentShift(toTop, done) {
    const els = [welcomeLyricsStory, welcomeLyricsCard];
    const first = els.map(el => el.getBoundingClientRect().top);
    document.body.classList.toggle('lyrics-layout-top', toTop);
    const last = els.map(el => el.getBoundingClientRect().top);
    const deltas = first.map((v, i) => v - last[i]);
    els.forEach((el, i) => {
      el.style.transition = 'none';
      el.style.transform = deltas[i] ? `translateY(${deltas[i]}px)` : '';
    });
    // Reflow forcé (lecture d'une propriété de layout) plutôt qu'un rAF :
    // un rAF peut être retardé (onglet en arrière-plan, notamment) et
    // laisserait alors l'état initial figé jusqu'au nettoyage de sécurité
    // plus bas — donnant l'impression d'un saut brutal sans transition du
    // tout. Lire offsetHeight force le navigateur à calculer/peindre la
    // position de départ AVANT qu'on active la transition juste après —
    // déterministe, ne dépend d'aucune frame de rendu à venir.
    void els[0].offsetHeight;
    els.forEach((el, i) => {
      el.style.transition = 'transform .5s cubic-bezier(.22,1,.36,1)';
      el.style.transform = 'translateY(0)';
    });
    let remaining = deltas.filter(d => d).length;
    let finished = false;
    const cleanup = () => els.forEach(el => { el.style.transition = ''; el.style.transform = ''; el.removeEventListener('transitionend', onEnd); });
    const finish = () => {
      if (finished) return;
      finished = true;
      clearTimeout(safety);
      cleanup();
      if (done) done();
    };
    function onEnd(e) {
      if (e.propertyName !== 'transform') return;
      remaining--;
      if (remaining <= 0) finish();
    }
    if (remaining === 0) { finish(); return; }
    els.forEach(el => el.addEventListener('transitionend', onEnd));
    // Filet de sécurité seulement : transitionend suffit dans l'immense
    // majorité des cas, ce timeout ne sert qu'à ne jamais rester bloqué si
    // l'événement ne se déclenche pas pour une raison ou une autre.
    const safety = setTimeout(finish, 700);
  }
  welcomeLyricsBtn.addEventListener('click', () => {
    welcomeLyricsHint.classList.remove('visible');
    // Le doigt sur le disque n'a plus de raison d'être : si on a pu cliquer
    // sur l'icône des paroles, c'est que la lecture est déjà en cours.
    welcomeDisc.closest('.welcome-disc-wrap').classList.add('hint-dismissed');
    animateContentShift(true, () => {
      document.body.classList.add('login-modal-open');
      welcomeLyricsDialog.showModal();
      positionWelcomeLyricsDialog();
      welcomeLyricsActiveIndex = -1;
      updateWelcomeLyricsSync();
    });
  });
  // Fermeture animée en sens inverse : dialog.close() natif retire la
  // dialog du rendu INSTANTANÉMENT (pas d'exit transition possible via CSS
  // seul) — on joue donc l'animation de sortie NOUS-MÊMES (.closing) avant
  // d'appeler close() pour de vrai, y compris sur Échap (événement
  // 'cancel', intercepté pour ne jamais laisser le natif fermer sans elle).
  const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
  let welcomeLyricsClosing = false;
  function requestCloseWelcomeLyrics() {
    if (welcomeLyricsClosing || !welcomeLyricsDialog.open) return;
    welcomeLyricsClosing = true;
    if (reduceMotion) { welcomeLyricsDialog.close(); welcomeLyricsClosing = false; return; }
    welcomeLyricsDialog.classList.add('closing');
    setTimeout(() => {
      welcomeLyricsDialog.classList.remove('closing');
      welcomeLyricsClosing = false;
      welcomeLyricsDialog.close();
    }, 480);
  }
  welcomeLyricsClose.addEventListener('click', requestCloseWelcomeLyrics);
  welcomeLyricsDialog.addEventListener('click', event => {
    const rect = welcomeLyricsDialog.getBoundingClientRect();
    if (event.target === welcomeLyricsDialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) requestCloseWelcomeLyrics();
  });
  welcomeLyricsDialog.addEventListener('cancel', event => {
    event.preventDefault();
    requestCloseWelcomeLyrics();
  });
  welcomeLyricsDialog.addEventListener('close', () => {
    document.body.classList.remove('login-modal-open');
    animateContentShift(false);
  });

  loadWelcomeLabel();
  let welcomeStarting = false;
  // Fondu posé directement sur le volume de l'élément <audio>, SANS passer
  // par le Web Audio API (un GainNode donnait un fondu un peu plus doux,
  // mais Safari a un bug connu : après une sortie de veille du Mac,
  // l'AudioContext ne délivre parfois plus jamais de son, même quand
  // resume() réussit et que state repasse bien à "running" — le graphe
  // reste silencieusement cassé. Comme .createMediaElementSource() ne peut
  // être appelé qu'UNE fois par élément <audio>, impossible de reconstruire
  // proprement un nouveau graphe pour s'en remettre. Plus robuste de ne
  // jamais dépendre d'un contexte persistant pour un simple fondu
  // décoratif — le volume direct fonctionne de façon fiable dans tous les
  // cas, veille comprise.
  const updateWelcomeVolume = () => {
    welcomeAudio.volume = .45 * Math.min(1, welcomeAudio.currentTime / 10);
  };
  welcomeAudio.volume = 0;
  const fadeTimer = setInterval(updateWelcomeVolume, 50);
  function welcomePaused() {
    welcomeDisc.classList.remove('is-playing');
    welcomeDisc.setAttribute('aria-pressed', 'false');
    welcomeDisc.setAttribute('aria-label', 'Reprendre la musique');
  }
  async function playWelcome() {
    if (welcomeStarting) return;
    welcomeStarting = true;
    if (welcomeAudio.ended) welcomeAudio.currentTime = 0;
    updateWelcomeVolume();
    try { await welcomeAudio.play(); }
    catch (error) {
      welcomePaused();
      welcomeAudioStatus.textContent = error.name === 'NotAllowedError'
        ? 'Clique sur le disque pour écouter'
        : 'Aucun morceau disponible pour le moment';
    } finally { welcomeStarting = false; }
  }
  welcomeDisc.addEventListener('click', () => {
    welcomeDisc.closest('.welcome-disc-wrap').classList.add('hint-dismissed');
    if (welcomeAudio.paused) playWelcome();
    else welcomeAudio.pause();
  });
  welcomeAudio.addEventListener('playing', () => {
    welcomeAudioStatus.classList.remove('audio-error');
    welcomeDisc.classList.add('is-playing');
    welcomeDisc.setAttribute('aria-pressed', 'true');
    welcomeDisc.setAttribute('aria-label', 'Mettre la musique en pause');
    welcomeAudioStatus.textContent = 'Un clic sur le disque pour mettre en pause';
    maybeShowLyricsHint();
  });
  welcomeAudio.addEventListener('pause', () => {
    welcomePaused();
    welcomeAudioStatus.textContent = 'En pause · clique pour reprendre';
  });
  welcomeAudio.addEventListener('waiting', () => { welcomeDisc.classList.remove('is-playing'); });
  welcomeAudio.addEventListener('ended', () => {
    welcomePaused();
    welcomeAudioStatus.textContent = 'Morceau terminé · clique pour réécouter';
  });
  welcomeAudio.addEventListener('error', () => {
    welcomePaused();
    welcomeAudioStatus.classList.add('audio-error');
    welcomeAudioStatus.textContent = 'Aucun morceau disponible pour le moment';
  });
  window.addEventListener('pagehide', () => { welcomeAudio.pause(); });
  playWelcome();

</script>
</body>
</html>
