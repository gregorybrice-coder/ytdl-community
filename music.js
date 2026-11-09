(() => {
  'use strict';
  const byId = id => document.getElementById(id);
  const nav = [...document.querySelectorAll('[data-music-target]')];
  const goToLibrary = () => byId('library-tabs').scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth',block:'start'});
  nav.forEach(button => button.addEventListener('click', () => {
    const target = button.dataset.musicTarget;
    if (target === 'playlists' || target === 'import') {
      byId(target === 'playlists' ? 'playlist-fab' : 'download-fab').click();
      return;
    }
    if (target === 'home') window.scrollTo({top:0,behavior:'smooth'});
    else { byId('tab-' + target).click(); goToLibrary(); }
    document.querySelectorAll('.music-nav').forEach(item => {
      item.classList.toggle('active',item === button);
      item.setAttribute('aria-current',item === button ? 'page' : 'false');
    });
  }));
  // Le bandeau "Playlist au hasard" (une vraie playlist de l'utilisateur,
  // pas une sélection aléatoire de morceaux) est piloté depuis capture.php,
  // qui possède déjà toutes les données de playlists — voir renderHomeShelf().
  ['audio','video'].forEach(kind=>byId('tab-'+kind).addEventListener('click',()=>{
    document.querySelectorAll('.music-nav').forEach(item=>{
      const active=item.dataset.musicTarget===kind;
      item.classList.toggle('active',active);item.setAttribute('aria-current',active?'page':'false');
    });
  }));
})();

(() => {
  const overlay = document.getElementById('video-modal');
  const video = document.getElementById('video-player');
  const header = overlay.querySelector('.video-modal-header');
  const fullscreen = document.getElementById('video-fullscreen-btn');
  let timer;
  let editModeActive = false;
  function reveal() {
    clearTimeout(timer);
    overlay.classList.remove('video-controls-hidden');
    header.inert = false;
    // En pause, rien ne masque la vue : pas de raison de faire disparaître
    // le bouton "Créer une synchro"/"Les paroles commencent ici" pendant que
    // l'utilisateur écoute/lit avant de cliquer — seule la LECTURE en cours
    // justifie ce fondu (dégager l'image), jamais une pause qui peut durer.
    if (overlay.classList.contains('visible') && !editModeActive && !video.paused) {
      timer = setTimeout(() => {
        overlay.classList.add('video-controls-hidden');
        header.inert = true;
      }, 2000);
    }
  }
  // Synchro manuelle des paroles (voir startManualLyricsSync, capture.php) :
  // le minutage exact (durée écoulée, dans .video-modal-header) doit rester
  // lisible tout le temps de la calibration, jamais fondu après 2s d'inactivité
  // souris comme en lecture normale.
  window.ytdlSetVideoEditMode = active => {
    editModeActive = !!active;
    reveal();
  };
  overlay.addEventListener('pointerdown', reveal, {passive:true});
  overlay.addEventListener('pointermove', event => { if (event.pointerType === 'mouse') reveal(); });
  overlay.addEventListener('keydown', event => {
    reveal();
    if (event.key === 'Escape' && !document.fullscreenElement) {
      overlay.classList.remove('video-immersive');
    }
  });
  // Reveal before keyboard focus can enter a temporarily hidden control group.
  document.addEventListener('keydown', event => {
    if (event.key === 'Tab' && overlay.classList.contains('visible')) reveal();
  });
  ['play','pause','ended','loadedmetadata'].forEach(event => video.addEventListener(event,reveal));
  new MutationObserver(() => {
    const immersive = overlay.classList.contains('video-immersive');
    fullscreen.title = immersive ? 'Quitter le plein écran' : 'Plein écran';
    fullscreen.setAttribute('aria-label',fullscreen.title);
    if (!overlay.classList.contains('visible')) {
      clearTimeout(timer);header.inert=false;
    }
  }).observe(overlay,{attributes:true,attributeFilter:['class']});
})();

// Infobulle stylisée à la place du title natif : sur tout élément qui porte
// un title, au survol souris ou au focus clavier. Le title est déplacé vers
// data-tip au premier survol (sinon le navigateur afficherait la sienne en
// double) ; aria-label, lui, reste en place pour les lecteurs d'écran.
(() => {
  const tip = document.createElement('div');
  tip.className = 'ui-tooltip';
  tip.setAttribute('role', 'tooltip');
  document.body.appendChild(tip);
  let current = null;
  let hideTimer = null;

  function textFor(el) {
    if (el.hasAttribute('title')) {
      el.dataset.tip = el.getAttribute('title');
      el.removeAttribute('title');
    }
    return el.dataset.tip || '';
  }

  function show(el) {
    const text = textFor(el);
    if (!text) return;
    clearTimeout(hideTimer);
    current = el;
    tip.textContent = text;
    tip.classList.remove('below');
    const r = el.getBoundingClientRect();
    const margin = 8;
    // Mesure une fois le texte posé, puis place au-dessus de l'élément —
    // ou en dessous s'il n'y a pas la place (haut de page, barre sticky).
    const w = tip.offsetWidth, h = tip.offsetHeight;
    let top = r.top - h - margin;
    if (top < 6) { top = r.bottom + margin; tip.classList.add('below'); }
    let left = r.left + r.width / 2 - w / 2;
    left = Math.max(6, Math.min(left, window.innerWidth - w - 6));
    // La pointe reste centrée sur l'élément même quand la bulle a été
    // rabattue à l'intérieur de l'écran.
    tip.style.setProperty('--tip-x', (r.left + r.width / 2 - left) + 'px');
    tip.style.left = left + 'px';
    tip.style.top = top + 'px';
    tip.classList.add('visible');
  }

  function hide() {
    current = null;
    tip.classList.remove('visible');
  }

  document.addEventListener('pointerover', event => {
    if (event.pointerType !== 'mouse') return;
    const el = event.target.closest('[title], [data-tip]');
    if (!el || el === current) return;
    show(el);
  }, { passive: true });
  document.addEventListener('pointerout', event => {
    if (!current) return;
    const el = event.target.closest('[data-tip]');
    if (el !== current) return;
    // Petit délai : passer d'un bouton à son voisin ne fait pas clignoter.
    hideTimer = setTimeout(hide, 60);
  }, { passive: true });
  document.addEventListener('focusin', event => {
    const el = event.target.closest('[title], [data-tip]');
    if (el && el.matches(':focus-visible')) show(el);
  });
  document.addEventListener('focusout', hide);
  // Un clic, un défilement ou une touche : la bulle a fait son travail.
  document.addEventListener('pointerdown', hide, { passive: true, capture: true });
  document.addEventListener('scroll', hide, { passive: true, capture: true });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') hide(); });
})();
