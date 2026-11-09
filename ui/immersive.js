/*
 * Interface système du téléphone.
 *
 * 1. Clavier : Chrome Android le pose PAR-DESSUS la page sans la réduire ;
 *    une fenêtre modale en bas (ou centrée) se retrouvait sous le clavier,
 *    champ de saisie compris. On publie la hauteur qu'il occupe dans la
 *    variable CSS --kb, et les modales remontent d'autant (ui/base.css,
 *    .modal-overlay dans capture.php).
 *
 * 2. Appli installée sur Android : barre de navigation du système masquée.
 * Le manifeste demande "display": "fullscreen" ; tant que Chrome n'a pas
 * mis à jour l'appli installée (ou s'il l'ignore), on passe en plein écran
 * au premier toucher, barre de navigation cachée. Elle revient le temps
 * d'un glissement depuis le bord de l'écran, comme dans un jeu ou une vidéo.
 * Sans effet dans un onglet de navigateur ordinaire, ni sur ordinateur.
 */
(function () {
  const vv = window.visualViewport;
  if (!vv) return;
  let last = -1;
  function keyboardInset() {
    const kb = Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop));
    if (kb === last) return;
    last = kb;
    // 50 px de marge au-dessus du clavier (barre de suggestions, confort).
    document.documentElement.style.setProperty('--kb', (kb > 0 ? kb + 50 : 0) + 'px');
    // Le champ en cours de saisie reste visible au-dessus du clavier.
    const el = document.activeElement;
    if (kb > 0 && el && /^(INPUT|TEXTAREA)$/.test(el.tagName)) requestAnimationFrame(() => el.scrollIntoView({ block: 'nearest' }));
  }
  vv.addEventListener('resize', keyboardInset);
  vv.addEventListener('scroll', keyboardInset);
  keyboardInset();
})();

(function () {
  const root = document.documentElement;
  if (!root.requestFullscreen) return;
  const installed = () => matchMedia('(display-mode: standalone)').matches;
  const touch = matchMedia('(pointer: coarse)').matches;
  if (!touch) return;

  function enter() {
    // Déjà en plein écran (manifeste appliqué, ou vidéo en cours) : rien à faire.
    if (!installed() || document.fullscreenElement || document.hidden) return;
    root.requestFullscreen({ navigationUI: 'hide' }).catch(() => {});
  }

  // Le plein écran exige un geste de l'utilisateur : chaque toucher le
  // rétablit s'il a été quitté (retour, changement d'appli, fin de vidéo).
  document.addEventListener('pointerup', enter, { passive: true, capture: true });
})();
