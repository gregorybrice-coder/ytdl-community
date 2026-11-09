/*
 * Habillage « Sillon » : branche le DOM de sillon.php sur le moteur commun
 * (ui/core.js) et la molette (ui/wheel.js).
 */
(function () {
  'use strict';

  const Y = window.Ytdl;
  const { player, library, lyrics, fmtTime, prefs, boot } = Y;
  const $ = (id) => document.getElementById(id);
  const app = $('app');

  // Petit constructeur DOM : tout le texte passe par textContent, jamais par
  // innerHTML (les titres viennent de YouTube, donc de n'importe qui).
  function h(tag, attrs, ...children) {
    const el = document.createElement(tag);
    if (attrs) for (const k in attrs) {
      const v = attrs[k];
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
      else if (k === 'html') el.innerHTML = v; // réservé aux icônes statiques
      else el.setAttribute(k, v === true ? '' : v);
    }
    for (const c of children.flat()) if (c !== null && c !== undefined && c !== false) el.append(c.nodeType ? c : String(c));
    return el;
  }

  const ICON = {
    trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg>',
    edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4L19 9l-4-4L4 16z"/></svg>',
    clip: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="15" height="14" rx="2"/><path d="m17 10 5-3v10l-5-3"/></svg>',
    share: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/></svg>',
    list: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h13M3 12h13M3 18h8"/><path d="M18 14v8M14 18h8"/></svg>',
    download: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 10l5 5 5-5M4 20h16"/></svg>',
    out: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h11"/></svg>',
    gear: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1 7 17M17 7l2.1-2.1"/></svg>',
    sync: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h10M4 12h7M4 18h10"/><circle cx="18" cy="15" r="3"/><path d="M18 9v3"/></svg>',
    play: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M7 4v16l13-8z"/></svg>',
    shuffle: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5M4 20 21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/></svg>',
    classic: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/></svg>',
  };

  // ------------------------------------------------------------------ état

  const CATS = { audio: 'Morceaux', video: 'Clips', list: 'Playlists', other: 'Hors musique' };
  const SORTS = ['recent', 'asc', 'desc'];
  const SORT_LABEL = { recent: 'Récents', asc: 'A → Z', desc: 'Z → A' };
  const state = {
    cat: prefs.get('ytdl_sillon_cat', 'audio'),
    sort: prefs.get('ytdl_sillon_sort', 'recent'),
    query: '',
    frozen: false,       // recherche validée (Entrée ou loupe) : le disque garde les résultats
    playlist: null,      // { id, name, tracks } quand on est dans une playlist
    items: [],           // ce que montre la molette
    view: 'lyrics',
    matches: [],         // indices (dans items) des titres trouvés par la recherche
    matchSet: new Set(),
  };
  if (!CATS[state.cat]) state.cat = 'audio';
  if (!SORT_LABEL[state.sort]) state.sort = 'recent';

  // --------------------------------------------------------------- molette

  const zone = $('wheel-zone');
  const discRot = $('disc-rot');
  const desktop = window.matchMedia('(min-width: 960px)');

  // Forme du disque selon l'apparence. Toutes partagent le même fonctionnement
  // (gestes, tap pour lancer / mettre en pause, retour automatique…) ; seuls
  // la position du disque et le sens des titres changent.
  const SKIN = boot.skin || 'sillon';
  // Titres suivants (ceux qui arrivent par le bas) visibles jusqu'au bord
  // du disque : ils y entrent dès le premier geste au lieu d'apparaître en
  // fondu à mi-hauteur. Jamais au-delà du bas du cercle (quart de tour).
  function nextSpan(g) { return Math.min(Math.PI / 2 - 0.05, g.span + 2 * g.step); }

  const GEOMETRY = {
    // Vinyle ancré au bord droit, titres le long des rayons, aiguille à
    // gauche. PC : calé sur la hauteur de la colonne de droite. Téléphone et
    // tablette : presque toute la largeur du bas de l'écran, quitte à être
    // rogné en haut et en bas — gros titres, gros geste, sous le pouce.
    sillon(w, hgt, desk) {
      const D = desk ? Math.max(120, Math.min(460, hgt * 0.47)) : Math.max(140, Math.min(440, w * 0.92, hgt * 1.15));
      const r = D * 0.96, spacing = desk ? 70 : (w >= 640 ? 64 : 56);
      const g = {
        cx: w + D * (desk ? 0.06 : 0.1), cy: hgt / 2, r, D,
        front: Math.PI,
        dir: -1, // titres suivants sous l'aiguille, comme une liste qu'on fait monter
        step: Math.min(0.3, Math.max(0.14, spacing / r)),
        span: Math.min(1.25, Math.asin(Math.min(1, (hgt / 2 - (desk ? 24 : 14)) / r))),
        textWidth: Math.round(Math.min(r * (desk ? 0.62 : 0.58), 280)),
        orientation: 'radial', frontScale: 0.14,
      };
      g.spanNext = nextSpan(g);
      return g;
    },
    // Même mécanique que Sillon, anneaux d'hologramme et titres un peu plus
    // espacés (police plus large).
    orbite(w, hgt, desk) {
      const g = GEOMETRY.sillon(w, hgt, desk);
      g.step = Math.min(0.32, g.step * 1.12);
      g.frontScale = 0.18;
      g.spanNext = nextSpan(g);
      return g;
    },
    // Héros : vinyle à droite comme Sillon, voisins un peu plus espacés
    // (police de manga haute).
    heros(w, hgt, desk) {
      const g = GEOMETRY.sillon(w, hgt, desk);
      g.step = Math.min(0.32, g.step * 1.08);
      g.frontScale = 0.2;
      g.spanNext = nextSpan(g);
      return g;
    },
    // Galaxie : la spirale, même lecture que Sillon (titres le long des
    // rayons), disque un peu plus grand pour laisser voir les bras.
    galaxie(w, hgt, desk) {
      const g = GEOMETRY.sillon(w, hgt, desk);
      g.frontScale = 0.16;
      return g;
    },
    // Arcade, Nature, Muse : cadrage de Sillon (titres le long des rayons).
    arcade(w, hgt, desk) { const g = GEOMETRY.sillon(w, hgt, desk); g.frontScale = 0.1; return g; },
    nature(w, hgt, desk) { return GEOMETRY.sillon(w, hgt, desk); },
    aurore(w, hgt, desk) { const g = GEOMETRY.sillon(w, hgt, desk); g.frontScale = 0.16; return g; },
    muse(w, hgt, desk) { const g = GEOMETRY.sillon(w, hgt, desk); g.frontScale = 0.16; return g; },
    // Bauhaus, Carnet, Laiton : même cadrage que Sillon.
    bauhaus(w, hgt, desk) { const g = GEOMETRY.sillon(w, hgt, desk); g.frontScale = 0.14; return g; },
    carnet(w, hgt, desk) { const g = GEOMETRY.sillon(w, hgt, desk); g.frontScale = 0.18; return g; },
    laiton(w, hgt, desk) { const g = GEOMETRY.sillon(w, hgt, desk); g.frontScale = 0.16; return g; },
    // Néo-rétro : vinyle à droite.
    retro(w, hgt, desk) {
      const g = GEOMETRY.sillon(w, hgt, desk);
      g.frontScale = 0.16;
      return g;
    },
    // Platine : même cadrage que Sillon (titres le long des rayons, le plus
    // de place possible pour les lire) ; seul l'habillage irisé change.
    platine(w, hgt, desk) { return GEOMETRY.sillon(w, hgt, desk); },
  };

  const wheel = new window.RadialWheel(zone, {
    geometry(w, hgt) {
      const g = GEOMETRY[SKIN](w, hgt, desktop.matches);
      placeDeck(g, w);
      return g;
    },
    render(btn, item, index) {
      btn.textContent = '';
      if (item.kind === 'playlist') {
        btn.append(h('b', null, item.name), h('i', null, item.count + ' titre' + (item.count > 1 ? 's' : '')));
      } else {
        btn.append(h('b', null, item.name), h('i', null, item._shared ? (item.artist || '—') + ' · partagé' : (item.artist || '—')));
      }
      btn.classList.toggle('is-match', state.matchSet.has(index));
      const isCurrent = !!(player.track && item.id === player.track.id && item.kind !== 'playlist');
      btn.classList.toggle('is-current', isCurrent);
      btn.classList.toggle('is-paused', isCurrent && !player.playing);
      btn.setAttribute('aria-label', item.kind === 'playlist' ? 'Ouvrir la playlist ' + item.name
        : isCurrent ? (player.playing ? 'Mettre en pause ' : 'Reprendre ') + item.title : 'Lire ' + item.title);
    },
    onPick: pick,
    onFront: onFront,
    onInteract: armReturnHome,
    onLongPress(index) {
      const item = state.items[index];
      if (!item) return;
      if (item.kind === 'playlist') { openPlaylist(item); return; }
      openMore(item);
    },
    onSpin(angle) { discRot.style.transform = 'rotate(' + angle.toFixed(4) + 'rad)'; },
  });

  // Retour automatique : 5 s après la dernière manipulation du disque, le
  // titre en cours revient se placer devant l'aiguille. Rien ne bouge
  // pendant une recherche, ni si le titre en cours n'est pas dans la liste
  // affichée (autre catégorie, autre playlist).
  const RETURN_HOME_MS = 5000;
  let returnTimer = 0;
  function armReturnHome() {
    clearTimeout(returnTimer);
    returnTimer = setTimeout(returnHome, RETURN_HOME_MS);
  }
  function returnHome() {
    if (wheel.drag || wheel.raf) { armReturnHome(); return; }
    if (!player.track || (state.query && !state.frozen)) return;
    const i = state.items.findIndex(it => it.kind !== 'playlist' && it.id === player.track.id);
    if (i >= 0 && i !== wheel.front) wheel.scrollTo(i);
  }

  function placeDeck(g, w) {
    const disc = $('disc');
    disc.style.left = (g.cx - g.D) + 'px';
    disc.style.top = (g.cy - g.D) + 'px';
    disc.style.width = disc.style.height = (g.D * 2) + 'px';
    // Aiguille juste à l'extérieur du titre en façade, orientée le long du
    // rayon (horizontale pour Sillon, verticale pour Platine).
    const needle = $('needle');
    const nr = g.r + (g.orientation === 'tangent' ? 42 : 10);
    needle.style.left = (g.cx + nr * Math.cos(g.front)) + 'px';
    needle.style.top = (g.cy + nr * Math.sin(g.front)) + 'px';
    needle.style.transform = 'translate(-50%, -50%) rotate(' + g.front + 'rad)';
    zone.dataset.orient = g.orientation;
    $('deck-letter').style.left = Math.max(40, g.cx - g.D * 0.62) + 'px';
    // Le titre en vedette s'arrête juste avant l'aiguille, quelle que soit
    // la largeur de l'écran.
    const deckLeft = zone.getBoundingClientRect().left - app.getBoundingClientRect().left;
    app.style.setProperty('--hero-max', Math.round(deckLeft + g.cx - g.r - 14) + 'px');
  }
  desktop.addEventListener('change', () => { placeHub(); wheel.measure(); });

  // Catégories : sur PC, en ligne dans la barre du bas, à droite de la barre
  // de progression ; sur téléphone et tablette, en colonne à gauche du
  // disque, au-dessus d'aléatoire et réglages (voir base.css).
  const hubHome = $('hub').nextElementSibling;
  function placeHub() {
    const hub = $('hub');
    if (desktop.matches) document.querySelector('.transport .progress').after(hub);
    else hubHome.before(hub);
  }
  placeHub();

  let letterTimer = 0;
  function onFront(index) {
    const item = state.items[index];
    const letterEl = $('deck-letter');
    if (item && wheel.raf) {
      const key = (item.kind === 'playlist' ? item.name : (item.sortKey || item.name || '')).trim();
      letterEl.textContent = state.sort === 'recent' ? '' : (key[0] || '').toUpperCase();
      letterEl.classList.add('on');
      clearTimeout(letterTimer);
      letterTimer = setTimeout(() => letterEl.classList.remove('on'), 450);
    }
    updateCount(index);
    if (!player.track) showNow(item && item.kind !== 'playlist' ? item : null, true);
  }

  function updateCount(index) {
    const n = state.items.length;
    if (state.query.trim() && state.matches.length) {
      const k = state.matches.indexOf(index);
      $('deck-count').textContent = (k >= 0 ? 'Résultat ' + (k + 1) : '—') + ' / ' + state.matches.length;
      return;
    }
    $('deck-count').textContent = n ? (Math.max(0, index) + 1) + ' / ' + n : '';
  }

  // Liste complète selon la catégorie, la playlist et le tri (sans recherche).
  function baseItems() {
    if (state.cat === 'list' && !state.playlist) {
      return library.playlists
        .map(p => ({ kind: 'playlist', id: p.id, name: p.name, count: p.item_count, sortKey: p.name.toLowerCase() }));
    }
    const base = state.playlist ? state.playlist.tracks : library.byCategory(state.cat);
    return state.playlist ? base.slice() : Y.sortTracks(base, state.sort);
  }

  // Liste montrée par la molette : pendant une recherche, uniquement les
  // titres trouvés (voir searchItems), quel que soit le tri.
  function computeItems() {
    const base = baseItems();
    return state.query.trim() ? searchItems(base, state.query) : base;
  }

  // Comparaison sans casse ni accents (« Beyonce » trouve « Beyoncé »).
  const fold = (s) => String(s || '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
  const words = (s) => fold(s).split(/[^\p{L}\p{N}]+/u).filter(Boolean);
  const hasAll = (hay, ws) => ws.every(w => hay.includes(w));

  // L'artiste correspond si chaque mot saisi commence un mot de son nom
  // (« cranberries » trouve « The Cranberries », « did » trouve déjà
  // « Dido » pendant la frappe), ou figure dans le nom de la chaîne YouTube
  // (« DidoVEVO », « TheCranberriesTV »).
  function artistMatches(t, ws) {
    const names = words(t.artist);
    const channel = fold(t.uploader).replace(/[^\p{L}\p{N}]+/gu, '');
    return ws.every(w => names.some(n => n.startsWith(w)) || (channel && channel.includes(w)));
  }

  // Recherche dans la bibliothèque. « ; » sépare plusieurs recherches dont
  // on réunit les résultats, groupe par groupe dans l'ordre de la saisie :
  // « Dido;Cranberries » donne tous les titres de Dido, puis tous ceux des
  // Cranberries. Pour chaque groupe, si des titres ont un artiste qui
  // correspond, on ne garde qu'eux ; sinon on cherche dans les titres.
  // Dans un groupe, les titres gardent l'ordre du tri choisi.
  function searchItems(list, query) {
    const out = [], seen = new Set();
    for (const part of String(query).split(';')) {
      const ws = words(part);
      if (!ws.length) continue;
      let hits = list.filter(it => it.kind !== 'playlist' && artistMatches(it, ws));
      if (!hits.length) {
        hits = list.filter(it => hasAll(fold(it.kind === 'playlist' ? it.name
          : (it.title || '') + ' ' + (it.uploader || '') + ' ' + (it.artist || '')), ws));
      }
      for (const it of hits) {
        const key = (it.kind || '') + ':' + it.id;
        if (!seen.has(key)) { seen.add(key); out.push(it); }
      }
    }
    return out;
  }

  // File de lecture d'un titre lancé depuis le disque : les titres affichés ;
  // pendant une recherche en cours de saisie, les résultats d'abord, puis le
  // reste de la liste pour que la lecture continue après eux.
  // Recherche validée : uniquement les résultats, comme une playlist.
  function playOrder(keep) {
    const shown = state.items.filter(t => t.kind !== 'playlist' && keep(t));
    if (!state.query.trim() || state.frozen) return shown;
    const ids = new Set(shown.map(t => t.id));
    return shown.concat(baseItems().filter(t => t.kind !== 'playlist' && keep(t) && !ids.has(t.id)));
  }

  // Toucher « Résultat k / n » passe au résultat suivant.
  $('deck-count').addEventListener('click', () => stepMatch(1));

  function stepMatch(dir) {
    if (!state.matches.length) return;
    const k = state.matches.indexOf(wheel.target !== null ? wheel.target : wheel.front);
    const n = state.matches.length;
    const nextK = k < 0 ? 0 : (k + dir + n) % n;
    wheel.scrollTo(state.matches[nextK]);
  }

  function paintEmpty() {
    const empty = $('deck-empty');
    const q = state.query.trim();
    if (library.error) { empty.replaceChildren(h('p', null, library.error)); empty.hidden = false; return; }
    if (q && !state.matches.length) {
      empty.replaceChildren(h('p', null, 'Aucun titre ne correspond à « ' + q + ' »' + (baseItems().length ? ' ici.' : '.')));
      // On propose de chercher sur le serveur et sur YouTube (l'ex-« trouver
      // ailleurs » de l'interface classique).
      if (state.cat !== 'list') empty.append(h('button', { type: 'button', class: 'btn primary', onclick: () => openAdd({ query: q }) }, 'Chercher ailleurs'));
      empty.hidden = false;
      return;
    }
    if (!state.items.length) {
      empty.replaceChildren(h('p', null, state.cat === 'list' && !state.playlist ? 'Aucune playlist pour l\'instant.'
        : state.playlist ? 'Cette playlist est vide.'
        : 'Rien ici pour l\'instant. Ajoute un titre avec le bouton +.'));
      empty.hidden = false;
      return;
    }
    empty.hidden = true;
  }

  // Recalcule la liste du disque. keepFront : garder devant l'aiguille le
  // même titre s'il est toujours là ; sinon le titre en cours, ou le premier
  // résultat pendant une recherche.
  function refreshWheel(keepFront) {
    const previous = keepFront ? state.items[wheel.front] : null;
    state.items = computeItems();
    const searching = !!state.query.trim();
    let front = 0;
    const kept = previous ? state.items.findIndex(it => it.id === previous.id && it.kind === previous.kind) : -1;
    if (kept >= 0) front = kept;
    else if (player.track && (!searching || state.frozen)) {
      const i = state.items.findIndex(it => it.kind !== 'playlist' && it.id === player.track.id);
      if (i >= 0) front = i;
    }
    wheel.setItems(state.items, front);
    // Pendant une recherche, tout ce qui reste sur le disque est un résultat.
    state.matches = searching ? state.items.map((it, i) => i) : [];
    state.matchSet = new Set(state.matches);
    document.querySelector('.deck').classList.toggle('is-filtering', searching);
    $('deck-save-search').hidden = !(searching && state.items.some(it => it.kind !== 'playlist'));
    wheel.refresh();
    paintEmpty();

    document.querySelectorAll('#hub button').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.cat === state.cat)));
    $('deck-back').hidden = !state.playlist;
    if (state.playlist) $('deck-back').querySelector('span').textContent = state.playlist.name;
    // Le tri ne s'applique qu'aux titres de la bibliothèque (une playlist
    // garde son ordre, la liste des playlists aussi).
    $('deck-sorts').hidden = !!state.playlist || state.cat === 'list';
    document.querySelectorAll('#deck-sorts button').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.sort === state.sort)));
    $('deck-plmenu').hidden = !state.playlist;
    updateCount(wheel.front);
  }

  async function pick(index) {
    const item = state.items[index];
    if (!item) return;
    if (item.kind === 'playlist') { openPlaylist(item); return; }
    if (index !== wheel.front) wheel.scrollTo(index);
    if (!item.isAudio) { const clips = playOrder(t => !t.isAudio); endSearchAfterPick(item); openVideo(item, clips); return; }
    // Retoucher le titre en cours : pause en fondu, puis reprise au tap
    // suivant. Le disque tient ainsi lieu de bouton lecture.
    if (player.track && player.track.id === item.id) {
      if (player.playing) player.pause({ fade: 350 }); else player.play({ fade: 350 });
      return;
    }
    const tracks = playOrder(t => t.isAudio);
    endSearchAfterPick(item);
    player.setQueue(tracks, tracks.indexOf(item), { playlistId: state.playlist ? state.playlist.id : null });
  }

  async function openPlaylist(item) {
    try {
      const tracks = await library.playlistItems(item.id);
      state.playlist = { id: item.id, name: item.name, tracks };
      state.query = ''; state.frozen = false; beforeSearch = null; $('search-input').value = ''; syncSearchUi();
      refreshWheel(false);
      wheel.scrollTo(0, true);
    } catch (err) { toast(err.message); }
  }

  $('deck-back').addEventListener('click', () => { state.playlist = null; refreshWheel(false); });
  $('hub').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-cat]'); if (!b) return;
    state.cat = b.dataset.cat; state.playlist = null;
    prefs.set('ytdl_sillon_cat', state.cat);
    // Revenir à la bibliothèque libère une recherche figée.
    if (state.query || searchInput.value) {
      clearTimeout(searchTimer);
      state.query = ''; state.frozen = false; beforeSearch = null;
      searchInput.value = ''; syncSearchUi();
    }
    if (state.cat === 'list') library.loadPlaylists().then(() => refreshWheel(false));
    refreshWheel(false);
  });
  $('deck-sorts').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-sort]');
    if (!b || b.dataset.sort === state.sort) return;
    state.sort = b.dataset.sort;
    prefs.set('ytdl_sillon_sort', state.sort);
    refreshWheel(false);
  });

  // ---------------------------------------------------------- recherche

  const searchInput = $('search-input');
  // Ce que montrait le disque avant la recherche (catégorie, playlist, titre
  // devant l'aiguille) : effacer la saisie y ramène exactement.
  let beforeSearch = null;
  function syncSearchUi() {
    // Sur téléphone, la croix reste affichée tant que la recherche est
    // ouverte : sans texte, elle referme la barre.
    $('search-clear').hidden = !searchInput.value && !(app.classList.contains('is-searching') && !desktop.matches);
  }
  function rememberView() {
    const item = state.items[wheel.front];
    beforeSearch = { cat: state.cat, playlist: state.playlist, id: item ? item.id : null, kind: item ? item.kind : null };
  }
  function restoreView() {
    const saved = beforeSearch;
    beforeSearch = null;
    state.query = '';
    state.frozen = false;
    refreshWheel(false);
    if (saved && saved.cat === state.cat && saved.playlist === state.playlist && saved.id !== null) {
      const i = state.items.findIndex(it => it.id === saved.id && it.kind === saved.kind);
      if (i >= 0) wheel.scrollTo(i, true);
    }
  }
  let searchTimer = 0;
  searchInput.addEventListener('input', () => {
    if (!state.query && searchInput.value && !beforeSearch) rememberView();
    syncSearchUi();
    clearTimeout(searchTimer);
    state.frozen = false; // la saisie change : il faudra revalider
    searchTimer = setTimeout(() => {
      if (!searchInput.value) { restoreView(); return; }
      state.query = searchInput.value;
      refreshWheel(false);
    }, 120);
  });
  // Valider la recherche (Entrée ou loupe) fige les résultats sur le disque :
  // lancer un titre ne les efface plus. On en sort en effaçant la saisie, en
  // revenant à la bibliothèque (boutons de catégorie) ou en ouvrant une playlist.
  function freezeSearch() {
    const q = searchInput.value;
    if (!q.trim()) return;
    clearTimeout(searchTimer);
    if (!state.query && !beforeSearch) rememberView();
    state.query = q;
    state.frozen = true;
    refreshWheel(true);
    if (!desktop.matches) searchInput.blur();
  }
  $('search-go').addEventListener('click', (e) => {
    e.preventDefault();
    if (!searchInput.value.trim()) { searchInput.focus(); return; }
    freezeSearch();
  });
  searchInput.addEventListener('keydown', (e) => {
    // Entrée : valide et fige la recherche (sur téléphone, referme aussi le
    // clavier). Sur PC, une fois la recherche figée, Entrée passe au résultat
    // suivant (Maj+Entrée : précédent). On lance un titre en le touchant.
    if (e.key === 'Enter') {
      e.preventDefault();
      if (state.frozen && state.query === searchInput.value) {
        if (desktop.matches) stepMatch(e.shiftKey ? -1 : 1); else searchInput.blur();
      } else freezeSearch();
    }
    if (e.key === 'Escape') closeSearch();
  });
  // Un titre lancé depuis des résultats non validés met fin à la recherche :
  // la barre se vide et le disque redevient normal, en restant sur le titre
  // lancé (pas de retour à la position d'avant la recherche). Une recherche
  // figée, elle, reste en place.
  function endSearchAfterPick(item) {
    if (state.frozen || (!state.query && !searchInput.value)) return;
    clearTimeout(searchTimer);
    searchInput.value = '';
    state.query = '';
    beforeSearch = null;
    refreshWheel(false);
    const i = state.items.findIndex(it => it.id === item.id && it.kind === item.kind);
    if (i >= 0) wheel.scrollTo(i, true);
    if (!desktop.matches) {
      app.classList.remove('is-searching');
      $('search-toggle').setAttribute('aria-expanded', 'false');
      searchInput.blur();
    }
    syncSearchUi();
  }
  function clearSearch() {
    clearTimeout(searchTimer);
    searchInput.value = '';
    restoreView();
    syncSearchUi();
  }
  $('search-clear').addEventListener('click', () => {
    if (!searchInput.value && !desktop.matches) { closeSearch(); return; }
    clearSearch();
    searchInput.focus();
  });
  $('search-toggle').addEventListener('click', () => {
    if (app.classList.contains('is-searching')) closeSearch();
    else { app.classList.add('is-searching'); $('search-toggle').setAttribute('aria-expanded', 'true'); syncSearchUi(); searchInput.focus(); }
  });
  function closeSearch() {
    app.classList.remove('is-searching');
    $('search-toggle').setAttribute('aria-expanded', 'false');
    if (searchInput.value || state.query) clearSearch();
    syncSearchUi();
  }


  // ------------------------------------------- recherche → playlist

  // Les résultats affichés deviennent une playlist. Un seul artiste : elle
  // prend son nom ; sinon on demande un nom. Si une playlist porte déjà ce
  // nom, on propose de fusionner (ajout des titres qui n'y sont pas) ou de
  // renommer.
  $('deck-save-search').addEventListener('click', saveSearchAsPlaylist);

  const findPlaylist = (name) => library.playlists.find(p => fold(p.name).trim() === fold(name).trim());
  const titles = (n) => n + ' titre' + (n > 1 ? 's' : '');

  async function saveSearchAsPlaylist() {
    const tracks = state.items.filter(it => it.kind !== 'playlist');
    if (!tracks.length) return;
    try { await library.loadPlaylists(); } catch (err) { toast(err.message); return; }
    const artists = new Set(tracks.map(t => fold(t.artist).trim()));
    const single = artists.size === 1 ? (tracks[0].artist || '').trim() : '';
    if (!single) { askPlaylistName(tracks, ''); return; }
    const existing = findPlaylist(single);
    if (existing) askMergeOrRename(existing, tracks);
    else await createPlaylistWith(single, tracks);
  }

  function askPlaylistName(tracks, initial) {
    const name = h('input', { class: 'field', placeholder: 'Nom de la playlist', maxlength: '80', autocomplete: 'off', enterkeyhint: 'done' });
    name.value = initial;
    const save = h('button', { type: 'button', class: 'btn primary' }, 'Créer la playlist');
    const go = async () => {
      const v = name.value.trim().replace(/\s+/g, ' ');
      if (!v) { name.focus(); return; }
      const existing = findPlaylist(v);
      if (existing) { askMergeOrRename(existing, tracks); return; }
      save.disabled = true;
      if (!(await createPlaylistWith(v, tracks))) save.disabled = false;
    };
    save.addEventListener('click', go);
    name.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); go(); } });
    openSheet('Enregistrer en playlist', h('p', null, titles(tracks.length) + ' de la recherche « ' + state.query.trim() + ' ».'),
      name, h('div', { class: 'row-btns' }, save));
    setTimeout(() => name.focus(), 60);
  }

  function askMergeOrRename(existing, tracks) {
    const merge = h('button', { type: 'button', class: 'btn primary' }, 'Fusionner');
    const rename = h('button', { type: 'button', class: 'btn' }, 'Renommer');
    merge.addEventListener('click', async () => {
      merge.disabled = rename.disabled = true;
      try {
        const added = await library.addToPlaylist(existing.id, tracks);
        closeSheet();
        toast(added ? titles(added) + ' ajouté' + (added > 1 ? 's' : '') + ' à « ' + existing.name + ' »'
          : 'Ces titres étaient déjà tous dans « ' + existing.name + ' »');
        if (state.cat === 'list') refreshWheel(true);
      } catch (err) { toast(err.message); merge.disabled = rename.disabled = false; }
    });
    // Renommer : nom proposé « Massive Attack 2 », ou le suivant libre.
    rename.addEventListener('click', () => {
      let k = 2;
      while (findPlaylist(existing.name + ' ' + k)) k++;
      askPlaylistName(tracks, existing.name + ' ' + k);
    });
    openSheet('Playlist existante',
      h('p', null, 'Tu as déjà une playlist « ' + existing.name + ' » (' + titles(existing.item_count) + '). '
        + 'Ajouter ces ' + titles(tracks.length) + ' à cette playlist, ou en créer une nouvelle sous un autre nom ?'),
      h('div', { class: 'row-btns' }, merge, rename));
  }

  async function createPlaylistWith(name, tracks) {
    try {
      const created = await library.createPlaylist(name);
      await library.addToPlaylist(Number(created.id), tracks);
      closeSheet();
      toast('Playlist « ' + created.name + ' » créée (' + titles(tracks.length) + ')');
      if (state.cat === 'list') refreshWheel(true);
      return true;
    } catch (err) { toast(err.message); return false; }
  }

  // ------------------------------------------------------- titre affiché

  let shownTrack = null;
  let resumeToastFor = null; // titre proposé par la bannière de reprise
  // Le titre mis en avant est celui qui joue ; tant que rien ne joue, c'est
  // celui qui se trouve devant l'aiguille (aperçu, en plus pâle).
  function showNow(track, preview) {
    shownTrack = track;
    document.querySelector('.hero').classList.toggle('is-preview', !!preview);
    $('now-artist').textContent = track ? (track.artist || '—') : '';
    $('now-title').textContent = track ? track.name : (library.tracks.length ? 'Choisis un titre' : 'Bienvenue');
    const meta = $('now-meta');
    meta.textContent = '';
    if (track) {
      meta.append((track.category === 'other' ? 'HORS MUSIQUE' : track.isAudio ? 'MORCEAU' : 'CLIP') + (track.duration ? ' · ' + fmtTime(track.duration) : ''));
      if (track._shared && track.shared_by_name) meta.append(' · ', h('span', { class: 'shared' }, 'partagé par ' + track.shared_by_name));
    }
    paintRating(track);
    const dl = $('act-download');
    if (track) dl.href = Y.api.downloadUrl(track.id); else dl.removeAttribute('href');
    document.querySelectorAll('.now-actions .ib, .now-rating button').forEach(b => { b.style.visibility = track ? '' : 'hidden'; });
    fitTitle();
    paintLabel(track);
  }

  // Titre aussi grand que possible sans dépasser trois lignes.
  function fitTitle() {
    const el = $('now-title');
    el.style.fontSize = '';
    let size = parseFloat(getComputedStyle(el).fontSize);
    const min = desktop.matches ? 44 : 28;
    let guard = 0;
    // Interligne serré (.86) : les jambages dépassent un peu de la boîte, d'où
    // la marge d'un quart de corps avant de conclure à une ligne de trop.
    const maxLines = 2;
    while (guard++ < 30 && size > min && (el.scrollHeight > size * (0.86 * maxLines + 0.25) || el.scrollWidth > el.clientWidth + 2)) {
      size -= 3; el.style.fontSize = size + 'px';
    }
  }
  window.addEventListener('resize', () => requestAnimationFrame(fitTitle));

  function paintLabel(track) {
    const label = document.querySelector('.disc-label');
    if (track && track.thumbnail) {
      label.style.backgroundImage = 'radial-gradient(circle, transparent 0 8%, rgba(0,0,0,.25) 9%, transparent 30%), url("' + track.thumbnail.replace(/"/g, '%22') + '")';
      label.classList.add('has-art');
    } else {
      label.style.backgroundImage = '';
      label.classList.remove('has-art');
    }
  }

  // preview : note montrée pendant un geste ou un survol (sans l'enregistrer),
  // l'étoile visée scintille.
  function paintRating(track, preview) {
    const r = preview !== undefined ? preview : (track ? track.rating : 0);
    document.querySelectorAll('#now-rating button').forEach(b => {
      const n = Number(b.dataset.rating);
      b.classList.toggle('on', n <= r);
      b.classList.toggle('is-spark', preview !== undefined && n === preview);
      b.setAttribute('aria-checked', String(n === (track ? track.rating : 0)));
    });
  }

  async function saveRating(track, n) {
    const old = track.rating;
    track.rating = n; paintRating(track);
    if (n === old) return;
    try { await library.rate(track, n); } catch (err) { track.rating = old; paintRating(track); toast(err.message); }
  }

  // Notation au geste : le doigt (ou la souris, bouton appuyé) se pose sur
  // une étoile et peut glisser à gauche ou à droite ; l'étoile visée
  // scintille et la note s'enregistre quand on relâche. Glisser au-delà de
  // la première étoile vers la gauche efface la note ; un simple appui sur
  // l'étoile de la note actuelle l'efface aussi. Sur PC, le survol fait
  // scintiller l'étoile sous le curseur.
  const ratingEl = $('now-rating');
  const starButtons = () => [...ratingEl.querySelectorAll('button[data-rating]')];
  function starAt(x) {
    const stars = starButtons();
    const first = stars[0].getBoundingClientRect();
    if (x < first.left + first.width * 0.25) return 0;
    let n = 1;
    stars.forEach((b, i) => { if (x >= b.getBoundingClientRect().left) n = i + 1; });
    return n;
  }
  let rateGesture = null; // { id, track, start, n, moved }
  ratingEl.addEventListener('pointerdown', (e) => {
    if (!shownTrack || (e.pointerType === 'mouse' && e.button !== 0)) return;
    const n = Math.max(1, starAt(e.clientX));
    rateGesture = { id: e.pointerId, track: shownTrack, start: n, n, x: e.clientX, moved: false };
    try { ratingEl.setPointerCapture(e.pointerId); } catch (err) {}
    paintRating(shownTrack, n);
    if (e.pointerType !== 'mouse' && navigator.vibrate) navigator.vibrate(8);
  });
  ratingEl.addEventListener('pointermove', (e) => {
    if (!shownTrack) return;
    if (!rateGesture) {
      // Survol à la souris, sans bouton appuyé.
      if (e.pointerType === 'mouse') paintRating(shownTrack, Math.max(1, starAt(e.clientX)));
      return;
    }
    if (e.pointerId !== rateGesture.id) return;
    if (Math.abs(e.clientX - rateGesture.x) > 6) rateGesture.moved = true;
    const n = starAt(e.clientX);
    if (n === rateGesture.n) return;
    rateGesture.n = n;
    paintRating(rateGesture.track, n);
    if (e.pointerType !== 'mouse' && navigator.vibrate) navigator.vibrate(6);
  });
  ratingEl.addEventListener('pointerup', (e) => {
    const g = rateGesture;
    if (!g || e.pointerId !== g.id) return;
    rateGesture = null;
    // Simple appui sur l'étoile de la note actuelle : efface la note.
    const n = !g.moved && g.n === g.start && g.track.rating === g.n ? 0 : g.n;
    if (g.track !== shownTrack) { paintRating(shownTrack); return; }
    saveRating(g.track, n);
    if (e.pointerType === 'mouse') paintRating(g.track, Math.max(1, starAt(e.clientX)));
  });
  ratingEl.addEventListener('pointercancel', () => { rateGesture = null; paintRating(shownTrack); });
  ratingEl.addEventListener('pointerleave', (e) => { if (!rateGesture && e.pointerType === 'mouse') paintRating(shownTrack); });
  // Clavier (Entrée / Espace sur une étoile) : le geste ci-dessus ne s'applique pas.
  ratingEl.addEventListener('click', (e) => {
    if (e.detail !== 0) return; // clic issu d'un pointeur : déjà traité au relâchement
    const b = e.target.closest('button[data-rating]'); if (!b || !shownTrack) return;
    const n = Number(b.dataset.rating);
    saveRating(shownTrack, shownTrack.rating === n ? 0 : n);
  });

  // ------------------------------------------------------------- lecture

  player.on('track', (track) => {
    if (resumeToastFor && resumeToastFor !== track) { $('toast').hidden = true; resumeToastFor = null; clearTimeout(resumeTimer); }
    showNow(track, false);
    wheel.refresh();
    const i = state.items.findIndex(it => it.kind !== 'playlist' && it.id === track.id);
    // Saut direct quand le titre est loin (reprise, recherche) : faire défiler
    // des centaines de sillons n'apprendrait rien.
    if (i >= 0 && i !== wheel.front && !wheel.drag) wheel.scrollTo(i, Math.abs(i - wheel.front) > 12);
    loadLyrics(track);
    if (state.view === 'bio' || desktop.matches) loadBio(track);
    else bioFor = null;
    renderQueue();
    document.title = track.name + (track.artist ? ' · ' + track.artist : '') + ' — ytdl+';
  });
  player.on('state', () => {
    wheel.refresh(); // ♪ / ❚❚ devant le titre en cours
    app.classList.toggle('is-playing', player.playing);
    $('ctl-play').setAttribute('aria-label', player.playing ? 'Pause' : 'Lecture');
  });
  player.on('time', paintTime);
  player.on('options', paintOptions);
  player.on('error', (msg) => toast(msg));

  function paintTime() {
    const d = player.duration, t = seekPreview !== null ? seekPreview : player.currentTime;
    $('fill').style.width = d ? (100 * Math.min(1, t / d)).toFixed(2) + '%' : '0';
    $('t-elapsed').textContent = fmtTime(t);
    $('t-remaining').textContent = d ? '-' + fmtTime(Math.max(0, d - t)) : '0:00';
    $('bar').setAttribute('aria-valuenow', d ? Math.round(100 * t / d) : 0);
    $('bar').setAttribute('aria-valuetext', fmtTime(t));
    syncLyrics(t);
  }

  function paintOptions() {
    $('ctl-shuffle').setAttribute('aria-pressed', String(player.shuffle));
    $('deck-shuffle').setAttribute('aria-pressed', String(player.shuffle));
    renderQueue();
  }

  $('ctl-play').addEventListener('click', () => {
    if (!player.track) { const i = wheel.front; if (state.items[i]) pick(i); return; }
    player.toggle();
  });
  $('ctl-next').addEventListener('click', () => player.next());
  $('ctl-prev').addEventListener('click', () => player.prev());
  // Aléatoire et réglages existent en deux exemplaires : dans la barre de
  // lecture (PC) et à gauche du disque (téléphone, où la barre se réduit à la
  // progression).
  ['ctl-shuffle', 'deck-shuffle'].forEach(id => $(id).addEventListener('click', () => { player.setShuffle(!player.shuffle); toast(player.shuffle ? 'Lecture aléatoire activée' : 'Lecture aléatoire désactivée', 1400); }));
  ['ctl-options', 'deck-options'].forEach(id => $(id).addEventListener('click', openOptions));

  // Barre de progression : glisser pour se déplacer dans le morceau.
  let seekPreview = null;
  const bar = $('bar');
  function barTime(e) { const r = bar.getBoundingClientRect(); return Y.clamp((e.clientX - r.left) / r.width, 0, 1) * player.duration; }
  bar.addEventListener('pointerdown', (e) => {
    if (!player.track) return;
    bar.setPointerCapture(e.pointerId); bar.classList.add('is-seeking');
    seekPreview = barTime(e); paintTime();
  });
  bar.addEventListener('pointermove', (e) => { if (seekPreview !== null) { seekPreview = barTime(e); paintTime(); } });
  const endSeek = (e) => { if (seekPreview === null) return; const t = barTime(e); seekPreview = null; bar.classList.remove('is-seeking'); player.seek(t); };
  bar.addEventListener('pointerup', endSeek);
  bar.addEventListener('pointercancel', () => { seekPreview = null; bar.classList.remove('is-seeking'); paintTime(); });
  bar.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowRight') { e.preventDefault(); player.seek(player.currentTime + 5); }
    if (e.key === 'ArrowLeft') { e.preventDefault(); player.seek(player.currentTime - 5); }
  });

  // Espace = lecture/pause (hors champs de saisie et hors boutons).
  document.addEventListener('keydown', (e) => {
    if (e.key !== ' ' || e.target.closest('input, textarea, button, dialog, [role="slider"], .wheel-zone')) return;
    e.preventDefault(); player.toggle();
  });

  // --------------------------------------------------------------- onglets

  document.querySelector('.tabs').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-view]'); if (!b) return;
    setView(b.dataset.view);
  });
  function setView(v) {
    state.view = v;
    document.querySelectorAll('.tabs button').forEach(b => b.setAttribute('aria-selected', String(b.dataset.view === v)));
    document.querySelectorAll('.view').forEach(el => el.classList.toggle('is-active', el.dataset.view === v));
    if (v === 'bio' && player.track) loadBio(player.track);
    if (v === 'lyrics') centerLyric(true);
  }

  // --------------------------------------------------------------- paroles

  let lyricsEntry = null, lyricsFor = null, activeLine = -2;
  const lyricsEl = $('lyrics');
  async function loadLyrics(track) {
    lyricsFor = track; lyricsEntry = null; activeLine = -2;
    lyricsEl.className = 'lyrics';
    lyricsEl.replaceChildren(h('p', { class: 'empty' }, 'Recherche des paroles…'));
    $('lyrics-tools').hidden = true;
    const entry = await lyrics.get(track);
    if (lyricsFor !== track) return;
    lyricsEntry = entry;
    if (entry.synced && entry.synced.length) {
      lyricsEl.replaceChildren(...entry.synced.map((line, i) => h('p', { 'data-i': i }, line.text || '♪')));
      $('lyrics-tools').hidden = false;
      paintOffset();
      syncLyrics(player.currentTime);
    } else if (entry.lyrics) {
      lyricsEl.classList.add('is-plain');
      lyricsEl.replaceChildren(...entry.lyrics.split(/\n/).map(l => h('p', null, l || ' ')));
    } else {
      lyricsEl.replaceChildren(h('p', { class: 'empty' }, 'Pas de paroles trouvées pour ce titre.'));
    }
  }
  function syncLyrics(t) {
    if (!lyricsEntry || !lyricsEntry.synced) return;
    const i = lyrics.lineAt(lyricsEntry, t, lyricsEntry.offset);
    if (i === activeLine) return;
    activeLine = i;
    lyricsEl.querySelectorAll('p').forEach((p, k) => { p.classList.toggle('on', k === i); p.classList.toggle('past', k < i); });
    centerLyric(false);
  }
  let userScrolledAt = 0;
  lyricsEl.parentElement.addEventListener('wheel', () => { userScrolledAt = Date.now(); }, { passive: true });
  lyricsEl.parentElement.addEventListener('touchmove', () => { userScrolledAt = Date.now(); }, { passive: true });
  function centerLyric(force) {
    if (!force && Date.now() - userScrolledAt < 4000) return; // on laisse lire tranquillement
    const p = lyricsEl.querySelector('p.on'); if (!p) return;
    const box = lyricsEl.parentElement;
    box.scrollTo({ top: p.offsetTop - box.clientHeight * 0.32, behavior: force ? 'auto' : 'smooth' });
  }
  lyricsEl.addEventListener('click', (e) => {
    const p = e.target.closest('p[data-i]'); if (!p || !lyricsEntry || !lyricsEntry.synced) return;
    const line = lyricsEntry.synced[Number(p.dataset.i)];
    player.seek(line.time + (lyricsEntry.offset || 0));
    userScrolledAt = 0;
  });
  function paintOffset() {
    const o = (lyricsEntry && lyricsEntry.offset) || 0;
    $('lyr-offset').textContent = o ? (o > 0 ? '+' : '') + o.toFixed(1).replace('.', ',') + ' s' : 'calé';
  }
  let offsetTimer = 0;
  function nudgeOffset(newOffset) {
    if (!lyricsEntry || !lyricsFor) return;
    lyricsEntry.offset = Math.round(newOffset * 10) / 10;
    activeLine = -2; paintOffset(); syncLyrics(player.currentTime);
    clearTimeout(offsetTimer);
    const track = lyricsFor, value = lyricsEntry.offset;
    offsetTimer = setTimeout(() => { lyrics.setOffset(track, value, 'audio').catch(err => toast(err.message)); }, 700);
  }
  $('lyr-toggle').addEventListener('click', () => {
    const open = $('lyrics-tools').classList.toggle('is-open');
    $('lyr-toggle').setAttribute('aria-expanded', String(open));
  });
  $('lyr-minus').addEventListener('click', () => nudgeOffset(((lyricsEntry && lyricsEntry.offset) || 0) - 0.5));
  $('lyr-plus').addEventListener('click', () => nudgeOffset(((lyricsEntry && lyricsEntry.offset) || 0) + 0.5));
  // « Caler ici » : même geste que dans l'interface classique — on appuie au
  // moment où la première ligne commence.
  $('lyr-sync-now').addEventListener('click', () => {
    if (!lyricsEntry || !lyricsEntry.synced || !lyricsEntry.synced.length) return;
    nudgeOffset(player.currentTime - lyricsEntry.synced[0].time);
    toast('Paroles recalées sur la première ligne', 1600);
  });

  // ------------------------------------------------------------------- bio

  let bioFor = null;
  async function loadBio(track) {
    if (bioFor === track) return;
    bioFor = track;
    const el = $('bio');
    el.replaceChildren(h('p', { class: 'empty' }, track.artist ? 'Recherche de la bio…' : 'Artiste inconnu.'));
    if (!track.artist) return;
    const info = await Y.artistInfo(track);
    if (bioFor !== track) return;
    if (!info || !info.extract) { el.replaceChildren(h('p', { class: 'empty' }, 'Pas de bio trouvée pour ' + track.artist + '.')); return; }
    el.replaceChildren(
      h('div', { class: 'bio-head' },
        info.thumbnail ? h('img', { src: info.thumbnail, alt: '' }) : null,
        h('div', { class: 'bio-name' }, info.wiki_title || track.artist)),
      h('p', null, info.extract),
      info.wiki_url ? h('p', null, h('a', { href: info.wiki_url, target: '_blank', rel: 'noopener' }, 'Lire la suite sur Wikipédia'))
        : info.has_more ? h('p', null, h('button', { type: 'button', class: 'bio-more', onclick: (e) => expandBio(e.currentTarget, track) }, 'Lire la suite…'))
        : null,
    );
  }

  // Wikipédia ne répond plus pour cette page : l'article complet, archivé en
  // base (artist-bio.php), se déroule sous l'extrait, intertitres compris.
  async function expandBio(btn, track) {
    btn.disabled = true; btn.textContent = 'Chargement…';
    try {
      const data = await Y.api.get('artist-bio.php?artist=' + encodeURIComponent(track.artist));
      if (bioFor !== track) return;
      const nodes = [];
      for (const block of String(data.full_text || '').split(/\n+/)) {
        const line = block.trim();
        if (!line) continue;
        const heading = line.match(/^(=+)\s*(.+?)\s*=+$/);
        nodes.push(heading ? h(heading[1].length <= 2 ? 'h4' : 'h5', null, heading[2]) : h('p', null, line));
      }
      $('bio').replaceChildren($('bio').firstChild, ...nodes);
    } catch (err) { btn.disabled = false; btn.textContent = 'Lire la suite…'; toast(err.message); }
  }

  // ----------------------------------------------------------------- file

  function renderQueue() {
    const list = player.upcoming(15);
    const el = $('queue');
    if (!player.track) { el.replaceChildren(h('li', { class: 'empty' }, 'Lance un titre : la suite apparaîtra ici.')); return; }
    if (!list.length) { el.replaceChildren(h('li', { class: 'empty' }, player.autoNext ? 'Fin de la liste.' : 'Enchaînement automatique désactivé (voir les options de lecture).')); return; }
    el.replaceChildren(...list.map((t, k) => h('li', null, h('button', { type: 'button', onclick: () => player.load(player.queue.indexOf(t)) },
      h('span', { class: 'n' }, k + 1),
      h('span', { class: 'tt' }, h('b', null, t.name), h('i', null, t.artist || '—')),
      h('span', { class: 'd' }, t.duration ? fmtTime(t.duration) : '')))));
  }

  // ---------------------------------------------------------------- toasts

  let toastTimer = 0;
  function toast(message, ms, action) {
    const el = $('toast');
    el.replaceChildren(h('span', null, message));
    if (action) el.append(h('button', { type: 'button', class: 'btn primary', onclick: () => { el.hidden = true; action.run(); } }, action.label));
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.hidden = true; }, ms || (action ? 8000 : 3200));
  }

  // ---------------------------------------------------------------- feuilles

  const sheet = $('sheet');
  function openSheet(title, ...content) {
    $('sheet-title').textContent = title;
    $('sheet-body').replaceChildren(...content);
    $('sheet-body').scrollTop = 0; // une nouvelle feuille s'ouvre toujours en haut
    if (!sheet.open) sheet.showModal();
    return $('sheet-body');
  }
  function closeSheet() { if (sheet.open) sheet.close(); }
  $('sheet-close').addEventListener('click', closeSheet);
  sheet.addEventListener('click', (e) => { if (e.target === sheet) closeSheet(); }); // clic sur le fond

  function confirmSheet(title, text, label, danger) {
    return new Promise(resolve => {
      let done = false;
      const finish = (v) => { if (done) return; done = true; sheet.removeEventListener('close', onClose); closeSheet(); resolve(v); };
      const onClose = () => finish(false);
      // Sur téléphone, la feuille de confirmation remplace le menu sous le
      // doigt : un tap qui « traverse » pouvait tomber aussitôt sur Annuler.
      // Les boutons ignorent donc les touchers des 400 premières ms.
      const openedAt = performance.now();
      const guard = (v) => () => { if (performance.now() - openedAt > 400) finish(v); };
      openSheet(title, h('p', null, text), h('div', { class: 'row-btns' },
        h('button', { type: 'button', class: 'btn', onclick: guard(false) }, 'Annuler'),
        h('button', { type: 'button', class: 'btn ' + (danger ? 'danger' : 'primary'), onclick: guard(true) }, label)));
      sheet.addEventListener('close', onClose);
    });
  }

  // Actions sur le titre affiché.
  $('act-playlist').addEventListener('click', () => shownTrack && openPlaylistPicker(shownTrack));
  $('act-share').addEventListener('click', () => shownTrack && openShare(shownTrack));
  $('act-more').addEventListener('click', () => shownTrack && openMore(shownTrack));

  async function openPlaylistPicker(track) {
    const body = openSheet('Playlists', h('p', { class: 'empty' }, 'Chargement…'));
    await library.loadPlaylists();
    const current = new Set((library.membership[track.id] || []).map(Number));
    const boxes = library.playlists.map(p => {
      const input = h('input', { type: 'checkbox', value: p.id });
      input.checked = current.has(p.id);
      return h('label', { class: 'check' }, input, h('span', null, p.name), h('small', null, p.item_count));
    });
    const name = h('input', { class: 'field', placeholder: 'Nouvelle playlist', maxlength: '80' });
    const save = h('button', { type: 'button', class: 'btn primary' }, 'Enregistrer');
    save.addEventListener('click', async () => {
      save.disabled = true;
      try {
        const ids = boxes.map(l => l.querySelector('input')).filter(i => i.checked).map(i => Number(i.value));
        if (name.value.trim()) { const created = await library.createPlaylist(name.value.trim()); ids.push(Number(created.id)); }
        await library.setMembership(track, ids);
        closeSheet(); toast('Playlists mises à jour');
        if (state.cat === 'list') refreshWheel(true);
      } catch (err) { toast(err.message); save.disabled = false; }
    });
    body.replaceChildren(h('p', null, track.title),
      boxes.length ? h('div', null, boxes) : h('p', { class: 'empty' }, 'Aucune playlist pour l\'instant.'),
      h('h3', null, 'Créer'), name, h('div', { class: 'row-btns' }, save));
  }

  async function openShare(track) {
    const body = openSheet(track._shared ? 'Repartager' : 'Partager', h('p', { class: 'empty' }, 'Chargement…'));
    let users;
    try { users = await library.users(track); } catch (err) { body.replaceChildren(h('p', null, err.message)); return; }
    const current = new Set((track.shared_with || []).map(Number));
    const boxes = users.map(u => {
      const input = h('input', { type: 'checkbox', value: u.id });
      input.checked = current.has(Number(u.id));
      return h('label', { class: 'check' }, input, u.avatar ? h('img', { src: 'avatars/' + encodeURIComponent(u.avatar), alt: '' }) : null, h('span', null, u.name));
    });
    const save = h('button', { type: 'button', class: 'btn primary' }, 'Partager');
    save.addEventListener('click', async () => {
      save.disabled = true;
      try {
        await library.share(track, boxes.map(l => l.querySelector('input')).filter(i => i.checked).map(i => Number(i.value)));
        closeSheet(); toast('Partage enregistré');
      } catch (err) { toast(err.message); save.disabled = false; }
    });
    body.replaceChildren(h('p', null, track.title),
      boxes.length ? h('div', null, boxes) : h('p', { class: 'empty' }, 'Personne d\'autre sur ce serveur pour l\'instant.'),
      h('div', { class: 'row-btns' }, save));
  }

  function openMore(track) {
    const items = [];
    items.push(h('li', null, h('a', { href: Y.api.downloadUrl(track.id), download: '', html: ICON.download }, 'Télécharger le fichier')));
    if (!track.isAudio) items.push(h('li', null, h('button', { type: 'button', html: ICON.clip, onclick: () => { closeSheet(); openVideo(track, library.byCategory('video')); } }, 'Voir le clip')));
    items.push(h('li', null, h('button', { type: 'button', html: ICON.list, onclick: () => openPlaylistPicker(track) }, 'Ajouter à une playlist')));
    if (state.playlist && state.playlist.tracks.includes(track)) {
      const pl = state.playlist;
      items.push(h('li', null, h('button', { type: 'button', html: ICON.list, onclick: () => removeFromCurrentPlaylist(track, pl) }, 'Retirer de « ' + pl.name + ' »')));
    }
    if (track.isAudio && player.track === track) {
      items.push(h('li', null, h('button', { type: 'button', html: ICON.sync, onclick: () => startAudioSync(track) }, 'Synchroniser les paroles à la main')));
    }
    // Morceau ou clip, à moi ou partagé avec moi (on peut alors le repartager).
    items.push(h('li', null, h('button', { type: 'button', html: ICON.share, onclick: () => openShare(track) }, track._shared ? 'Repartager' : 'Partager')));
    if (boot.isAdmin && !track._shared) items.push(h('li', null, h('button', { type: 'button', html: ICON.edit, onclick: () => openRename(track) }, 'Renommer')));
    items.push(h('li', null, h('button', { type: 'button', class: 'danger', html: ICON.trash, onclick: () => removeTrack(track) }, track._shared ? 'Retirer de mes partages' : 'Supprimer')));
    openSheet(track.name, h('p', null, track.artist || ''), h('ul', { class: 'menu' }, items));
  }

  async function removeFromCurrentPlaylist(track, pl) {
    try {
      await library.removeFromPlaylist(pl.id, track);
      pl.tracks = pl.tracks.filter(t => t !== track);
      closeSheet();
      refreshWheel(true);
      toast('Retiré de « ' + pl.name + ' »');
    } catch (err) { toast(err.message); }
  }

  function openRename(track) {
    const input = h('input', { class: 'field', value: track.title, maxlength: '300' });
    const save = h('button', { type: 'button', class: 'btn primary' }, 'Renommer');
    const go = async () => {
      if (!input.value.trim()) return;
      save.disabled = true;
      try {
        const res = await library.rename(track, input.value.trim());
        closeSheet(); toast(res.lyrics_found_now ? 'Renommé — paroles trouvées' : 'Renommé');
        if (player.track === track) showNow(track, false);
        refreshWheel(true);
      } catch (err) { toast(err.message); save.disabled = false; }
    };
    save.addEventListener('click', go);
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') go(); });
    openSheet('Renommer', h('p', null, 'Format conseillé : « Artiste - Titre ».'), input, h('div', { class: 'row-btns' }, save));
    setTimeout(() => input.focus(), 50);
  }

  async function removeTrack(track) {
    const ok = await confirmSheet(track._shared ? 'Retirer' : 'Supprimer',
      track._shared ? 'Retirer « ' + track.title + ' » de tes partages ? La personne qui te l\'a partagé le garde.'
        : 'Supprimer définitivement « ' + track.title + ' » de ta bibliothèque ?',
      track._shared ? 'Retirer' : 'Supprimer', true);
    if (!ok) return;
    try {
      await library.remove(track);
      // Retiré aussi de la playlist affichée et de la file de lecture.
      if (state.playlist) state.playlist.tracks = state.playlist.tracks.filter(t => t.id !== track.id);
      if (player.track && player.track.id === track.id) player.next();
      refreshWheel(true);
      toast(track._shared ? 'Retiré de tes partages' : 'Supprimé');
    } catch (err) { toast(err.message); }
  }

  function openOptions() {
    const sw = (label, checked, onchange) => {
      const input = h('input', { type: 'checkbox' }); input.checked = checked;
      input.addEventListener('change', () => onchange(input.checked));
      return h('label', { class: 'switch' }, h('span', null, label), input);
    };
    const vol = h('input', { type: 'range', class: 'range', min: '0', max: '100', value: String(player.volume), 'aria-label': 'Volume' });
    vol.addEventListener('input', () => player.setVolume(Number(vol.value)));
    const sortBtns = SORTS.map(s => h('button', { type: 'button', class: 'btn' + (state.sort === s ? ' primary' : ''), onclick: () => { state.sort = s; prefs.set('ytdl_sillon_sort', s); refreshWheel(false); openOptions(); } }, SORT_LABEL[s]));
    openSheet('Lecture',
      sw('Lecture aléatoire', player.shuffle, v => player.setShuffle(v)),
      sw('Enchaîner automatiquement', player.autoNext, v => { player.setAutoNext(v); renderQueue(); }),
      sw('Fondu enchaîné', player.crossfade, v => player.setCrossfade(v)),
      h('h3', null, 'Volume'), vol,
      h('h3', null, 'Ordre du disque'), h('div', { class: 'row-btns' }, sortBtns));
  }

  // --------------------------------------------------------------- profil

  const SKINS = [
    { id: 'bauhaus', label: 'Bauhaus', ready: true },
    { id: 'carnet', label: 'Carnet', ready: true },
    { id: 'laiton', label: 'Laiton', ready: true },
    { id: 'aurore', label: 'Aurore', ready: true },
    { id: 'sillon', label: 'Sillon', swatch: 'radial-gradient(circle at 70% 50%, #ff8a1f 0 18%, #121110 19%)', ready: true },
    { id: 'orbite', label: 'Orbite', swatch: 'linear-gradient(135deg, #04060f 40%, #3be8ff 70%, #ff4fb0)', ready: true },
    { id: 'platine', label: 'Platine', swatch: 'conic-gradient(#ffd1c2, #e6d9ff, #b9ecff, #e9fff1, #ffd1c2)', ready: true },
    { id: 'heros', label: 'Héros', swatch: 'radial-gradient(circle, #111 1.2px, transparent 1.6px) 0 0 / 7px 7px, linear-gradient(135deg, #fff8e7 45%, #00a86b 46% 70%, #e8322b 71%)', ready: true },
    { id: 'galaxie', label: 'Galaxie', swatch: 'radial-gradient(circle at 70% 40%, #ff7ad9 0, transparent 30%), radial-gradient(circle at 30% 60%, #5b8cff 0, transparent 35%), #05030f', ready: true },
    { id: 'arcade', label: 'Arcade', ready: true },
    { id: 'nature', label: 'Nature', ready: true },
    { id: 'muse', label: 'Muse', ready: true },
    { id: 'retro', label: 'Néo-rétro', swatch: 'linear-gradient(180deg, #ffb703 0 25%, #fb8500 25% 50%, #ff5d8f 50% 75%, #2ec4b6 75%)', ready: true },
    { id: 'sombre', label: 'Sombre', swatch: 'linear-gradient(135deg, #101010, #ff844b)', classic: true },
    { id: 'clair', label: 'Clair', swatch: 'linear-gradient(135deg, #f4f6fb, #d1602a)', classic: true },
    { id: 'ambre', label: 'Ambre', swatch: 'linear-gradient(135deg, #2b1d10, #ffb347)', classic: true },
    { id: 'kawaii', label: 'Kawaii', swatch: 'linear-gradient(135deg, #fff0f6, #ff9fbb)', classic: true },
  ];

  // Nouveautés annoncées par le serveur (table notifications) : l'avatar
  // brille tant qu'elles n'ont pas été vues, comme dans l'interface classique.
  const pendingNews = (boot.notifications || []).slice();
  function paintPending() { $('profile-btn').classList.toggle('is-pending', pendingNews.length > 0); }
  paintPending();

  $('profile-btn').addEventListener('click', openProfile);
  function openProfile() {
    const parts = [];

    if (pendingNews.length) {
      parts.push(h('h3', null, 'Nouveautés'), h('ul', { class: 'news' }, pendingNews.map(n => h('li', null, h('b', null, n.title || 'Nouveauté'), h('p', null, n.message || '')))));
      // Vues : on les acquitte tout de suite, l'éclat de l'avatar s'éteint.
      pendingNews.splice(0).forEach(n => Y.account.ackNotification(n.id));
      paintPending();
    }

    // Avatar : enregistré dès le choix.
    let currentAvatar = boot.avatar;
    const avatarBtns = (boot.avatars || []).map(file => h('button', {
      type: 'button', 'aria-pressed': String(file === currentAvatar), 'aria-label': 'Avatar ' + file.replace(/\D/g, ''),
      onclick: async (e) => {
        const btn = e.currentTarget;
        if (file === currentAvatar) return;
        try {
          await Y.account.setAvatar(file);
          currentAvatar = boot.avatar = file;
          avatarBtns.forEach(b => b.setAttribute('aria-pressed', String(b === btn)));
          const ring = $('usage-ring');
          ring.replaceChildren(h('img', { src: 'avatars/' + encodeURIComponent(file), alt: '' }));
          toast('Avatar mis à jour', 1600);
        } catch (err) { toast(err.message); }
      },
    }, h('img', { src: 'avatars/' + encodeURIComponent(file), alt: '' })));
    if (avatarBtns.length) parts.push(h('h3', null, 'Avatar'), h('div', { class: 'avatars' }, avatarBtns));

    // E-mail : rien ne change avant la confirmation envoyée à l'adresse actuelle.
    const email = h('input', { class: 'field', type: 'email', placeholder: 'nouvelle@adresse.fr', autocomplete: 'email', inputmode: 'email' });
    const emailBtn = h('button', { type: 'button', class: 'btn' }, 'Changer d\'adresse');
    emailBtn.addEventListener('click', async () => {
      if (!email.value.trim()) { email.focus(); return; }
      emailBtn.disabled = true;
      try {
        const res = await Y.account.requestEmailChange(email.value.trim());
        email.value = '';
        toast('Lien de confirmation envoyé à ' + (res.sent_to || 'ton adresse actuelle') + '.', 6000);
      } catch (err) { toast(err.message); }
      emailBtn.disabled = false;
    });
    parts.push(h('h3', null, 'Adresse e-mail'), h('p', null, 'Actuelle : ' + (boot.email || '—')), email,
      h('p', { style: 'font-size:13px' }, 'Un lien de confirmation part vers ton adresse actuelle : rien ne change tant que tu ne l\'as pas ouvert.'),
      h('div', { class: 'row-btns' }, emailBtn));

    // Connexion rapide : empreinte / visage de cet appareil (WebAuthn).
    if (window.YtdlPasskey) {
      const pkBox = h('div', null);
      parts.push(h('h3', null, 'Connexion par empreinte'), pkBox);
      const paintPasskey = async () => {
        const P = window.YtdlPasskey;
        if (!(await P.available())) { pkBox.replaceChildren(h('p', null, "Cet appareil (ou ce navigateur) ne propose pas d'empreinte ni de reconnaissance du visage.")); return; }
        const local = P.local();
        const mine = local && Number(local.userId) === Number(boot.id);
        const btn = h('button', { type: 'button', class: 'btn' + (mine ? ' danger' : ' primary') }, mine ? 'Désactiver sur cet appareil' : 'Activer sur cet appareil');
        btn.addEventListener('click', async () => {
          btn.disabled = true;
          try {
            if (mine) { await P.disable(); toast('Empreinte désactivée sur cet appareil.'); }
            else { await P.register({ id: boot.id, name: boot.name, avatar: boot.avatar }); toast('Empreinte activée : elle te servira à te connecter sur cet appareil.'); }
          } catch (err) { toast(P.explain(err)); }
          paintPasskey();
        });
        pkBox.replaceChildren(h('p', null, mine ? 'Activée : sur l\'écran de connexion, touche le logo d\'empreinte.' : 'Connecte-toi d\'un simple toucher, sans lien par e-mail.'), h('div', { class: 'row-btns' }, btn));
      };
      paintPasskey();
    }

    // Notifications push des partages reçus.
    const push = h('input', { type: 'checkbox' });
    push.disabled = !Y.account.pushSupported();
    Y.account.pushEnabled().then(v => { push.checked = v; });
    push.addEventListener('change', async () => {
      const want = push.checked;
      push.disabled = true;
      try { await Y.account.setPush(want); toast(want ? 'Notifications activées' : 'Notifications désactivées', 1800); }
      catch (err) { push.checked = !want; toast(err.message || 'Échec du réglage des notifications.'); }
      push.disabled = false;
    });
    parts.push(h('h3', null, 'Notifications'), h('label', { class: 'switch' }, h('span', null, push.disabled ? 'Notifications non prises en charge sur cet appareil' : 'Me notifier des partages reçus'), push));

    // Stockage, détaillé par catégorie (ex-fenêtre « Stockage »).
    const u = library.usage;
    if (u && u.quota_bytes) {
      const pct = Math.min(100, Math.round(100 * u.total_bytes / u.quota_bytes));
      const row = (label, bytes, count) => [h('span', null, label), h('span', null, (count || 0) + ' fichier' + (count > 1 ? 's' : '')), h('span', null, fmtBytes(bytes))];
      parts.push(h('h3', null, 'Stockage'), h('div', { class: 'usage' }, h('i', { style: 'width:' + pct + '%' })),
        h('p', null, fmtBytes(u.total_bytes) + ' sur ' + fmtBytes(u.quota_bytes) + ' (' + pct + ' %)'),
        h('div', { class: 'storage-rows' }, row('Morceaux', u.audio_bytes, u.audio_count), row('Clips', u.video_bytes, u.video_count), row('Hors musique', u.other_bytes, u.other_count)));
    }

    // Apparence : aperçu de l'actuelle, la galerie complète s'ouvre à part.
    const currentSkin = SKINS.find(s => s.id === SKIN) || SKINS[0];
    parts.push(h('h3', null, 'Apparence'), h('button', { type: 'button', class: 'skin-current', onclick: openSkinGallery },
      h('img', { src: skinPreview(currentSkin.id), alt: '' }),
      h('span', null, h('b', null, currentSkin.label), h('i', null, 'Changer d\'apparence'))));

    const links = [
      boot.isAdmin ? h('li', null, h('a', { href: 'admin.php', html: ICON.gear }, 'Administration')) : null,
      h('li', null, h('a', { href: 'capture.php?classic=1', html: ICON.classic }, 'Ouvrir l\'interface classique')),
      h('li', null, h('a', { href: 'logout.php', html: ICON.out }, 'Se déconnecter')),
    ].filter(Boolean);
    parts.push(h('h3', null, 'Compte'), h('ul', { class: 'menu' }, links));
    openSheet(boot.name, ...parts);
  }
  function skinPreview(id) { return 'ui/skins/previews/' + id + '.jpg?v=10'; }

  // Galerie des apparences : vraies captures d'écran (ui/skins/previews/),
  // dans une feuille à part, plus large que le profil sur PC.
  function openSkinGallery() {
    const pick = async (s) => {
      if (s.id === SKIN) { closeSheet(); return; }
      // Enregistré sur le compte (tous les appareils) avant de changer de page.
      try { await Y.account.setSkin(s.id); } catch (err) { toast(err.message); return; }
      prefs.set('ytdl_skin', s.id);
      // La recherche en cours suit vers la nouvelle apparence (pas vers
      // l'interface classique, qui ne la connaît pas).
      try {
        if (state.query.trim() && !s.classic) sessionStorage.setItem(CARRY_SEARCH, JSON.stringify({ query: state.query, frozen: state.frozen, cat: state.cat }));
        else sessionStorage.removeItem(CARRY_SEARCH);
      } catch (err) {}
      window.location.href = s.classic ? 'capture.php' : s.id + '.php';
    };
    const card = (s) => h('button', { type: 'button', class: 'skin-card', 'aria-current': String(s.id === SKIN), onclick: () => pick(s) },
      h('img', { src: skinPreview(s.id), alt: 'Aperçu de l\'apparence ' + s.label, loading: 'lazy' }),
      h('span', null, s.label));
    const fresh = SKINS.filter(s => !s.classic), classic = SKINS.filter(s => s.classic);
    openSheet('Apparence',
      h('h3', null, 'Nouvelles interfaces'), h('div', { class: 'skin-gallery' }, fresh.map(card)),
      h('h3', null, 'Interface classique'), h('div', { class: 'skin-gallery' }, classic.map(card)));
    sheet.classList.add('is-wide');
    sheet.addEventListener('close', () => sheet.classList.remove('is-wide'), { once: true });
  }

  function fmtBytes(b) {
    b = Number(b) || 0;
    if (b >= 1e9) return (b / 1e9).toFixed(1).replace('.', ',') + ' Go';
    if (b >= 1e6) return Math.round(b / 1e6) + ' Mo';
    return Math.round(b / 1e3) + ' ko';
  }
  function paintUsage() {
    const u = library.usage;
    if (u && u.quota_bytes) $('usage-ring').style.setProperty('--usage', Math.min(100, 100 * u.total_bytes / u.quota_bytes).toFixed(1) + '%');
  }

  // ------------------------------------------------------- ajouter un titre

  const YT_URL = /https?:\/\/(?:www\.|m\.|music\.)?(?:youtube\.com\/(?:watch\?[^\s]*v=|shorts\/)|youtu\.be\/)[\w-]+[^\s]*/gi;
  const preview = new Audio();
  let previewBtn = null, previewTimer = 0;
  function stopPreview() {
    clearTimeout(previewTimer);
    preview.pause();
    if (previewBtn) { previewBtn.classList.remove('is-on'); previewBtn.textContent = '▶'; }
    previewBtn = null;
  }
  preview.addEventListener('ended', stopPreview);
  // Aperçu de 30 s d'un résultat YouTube (preview.php), comme dans capture.php.
  function togglePreview(btn, videoId) {
    if (previewBtn === btn) { stopPreview(); return; }
    stopPreview();
    if (player.playing) player.pause();
    previewBtn = btn;
    btn.textContent = '…';
    preview.src = 'preview.php?id=' + encodeURIComponent(videoId);
    preview.play().then(() => {
      if (previewBtn !== btn) return;
      btn.textContent = '❚❚'; btn.classList.add('is-on');
      previewTimer = setTimeout(stopPreview, 30000);
    }).catch(() => { if (previewBtn === btn) { stopPreview(); toast('Aperçu indisponible pour ce titre.'); } });
  }
  sheet.addEventListener('close', stopPreview);

  $('add-btn').addEventListener('click', () => openAdd());
  const jobs = [];
  // opts.urls : liens à proposer d'emblée (partage depuis le téléphone) ;
  // opts.query : recherche à lancer d'emblée (depuis une recherche vide).
  function openAdd(opts) {
    opts = opts || {};
    const input = h('input', { class: 'field', placeholder: 'Lien(s) YouTube ou recherche', autocomplete: 'off', enterkeyhint: 'search' });
    const results = h('ul', { class: 'results' });
    const elsewhere = h('div', null);
    const jobsEl = h('div', null);
    const device = h('input', { type: 'checkbox' });
    device.checked = prefs.get('ytdl_download_to_device', '1') !== '0';
    device.addEventListener('change', () => prefs.set('ytdl_download_to_device', device.checked ? '1' : '0'));

    const dlButtons = (url, defaultTitle) => {
      const box = h('span', { class: 'row-btns', style: 'margin:0' });
      const run = (o) => startJob(url, Object.assign({ toDevice: device.checked }, o), jobsEl, defaultTitle);
      const main = () => box.replaceChildren(
        h('button', { type: 'button', class: 'btn primary', onclick: () => run({ audioOnly: true }) }, 'MP3'),
        h('button', { type: 'button', class: 'btn', onclick: () => run({ audioOnly: false }) }, 'Clip'),
        h('button', { type: 'button', class: 'btn', title: 'Hors musique : podcast, documentaire, tutoriel…', onclick: other }, 'Autre…'));
      // Hors musique : mêmes trois sortes que le menu « … » de capture.php.
      const other = () => box.replaceChildren(
        h('button', { type: 'button', class: 'btn', onclick: () => run({ audioOnly: true, category: 'other' }) }, 'Podcast'),
        h('button', { type: 'button', class: 'btn', onclick: () => run({ audioOnly: false, category: 'other', titlePrefix: '[Docu] ' }) }, 'Docu'),
        h('button', { type: 'button', class: 'btn', onclick: () => run({ audioOnly: false, category: 'other', titlePrefix: '[Tuto] ' }) }, 'Tuto'),
        h('button', { type: 'button', class: 'btn', 'aria-label': 'Retour', onclick: main }, '←'));
      main();
      return box;
    };

    // Un ou plusieurs liens collés : une ligne par lien, titre et vignette
    // récupérés (title.php) pour vérifier avant de lancer.
    const showUrls = (urls) => {
      results.replaceChildren(...urls.map(url => {
        const title = h('b', null, url);
        const sub = h('i', null, 'Vérification…');
        const img = h('img', { alt: '' });
        api('title.php?url=' + encodeURIComponent(url)).then(d => {
          title.textContent = d.title || url;
          sub.textContent = [d.uploader, d.duration].filter(Boolean).join(' · ');
          if (d.thumbnail) img.src = d.thumbnail;
        }).catch(err => { sub.textContent = err.message; });
        return h('li', null, img, h('span', { class: 'tt' }, title, sub), dlButtons(url));
      }));
      if (urls.length > 1) {
        results.append(h('li', null, h('span', { class: 'tt' }, h('i', null, urls.length + ' liens')),
          h('span', { class: 'row-btns', style: 'margin:0' }, h('button', { type: 'button', class: 'btn primary', onclick: () => urls.forEach(u => startJob(u, { audioOnly: true, toDevice: device.checked }, jobsEl)) }, 'Tout en MP3'))));
      }
    };

    let searchSeq = 0;
    const go = async () => {
      const v = input.value.trim(); if (!v) return;
      stopPreview();
      const urls = v.match(YT_URL);
      if (urls) { elsewhere.replaceChildren(); showUrls([...new Set(urls)]); return; }
      const seq = ++searchSeq;
      results.replaceChildren(h('li', null, h('span', { class: 'tt' }, h('i', null, 'Recherche sur YouTube…'))));
      // Déjà sur le serveur chez quelqu'un d'autre ? Inutile de le
      // retélécharger : on l'emprunte (library-borrow.php).
      Promise.all([library.findElsewhere(v, true), library.findElsewhere(v, false)]).then(([a, b]) => {
        if (seq !== searchSeq) return;
        const found = a.concat(b);
        elsewhere.replaceChildren(...(found.length ? [h('h3', null, 'Déjà sur le serveur'), h('ul', { class: 'results' }, found.map(r => {
          const add = h('button', { type: 'button', class: 'btn primary' }, 'Ajouter');
          add.addEventListener('click', async () => {
            add.disabled = true; add.textContent = 'Ajout…';
            try {
              await library.borrow(r.id);
              add.closest('li').remove();
              refreshWheel(true); paintUsage();
              toast('« ' + (r.title || 'Titre') + ' » ajouté à ta bibliothèque');
            } catch (err) { add.disabled = false; add.textContent = 'Ajouter'; toast(err.message); }
          });
          return h('li', null, h('img', { src: r.thumbnail || '', alt: '', loading: 'lazy' }),
            h('span', { class: 'tt' }, h('b', null, r.title || '(sans titre)'), h('i', null, 'Chez ' + (r.owner_name || '?'))),
            h('span', { class: 'row-btns', style: 'margin:0' }, add));
        }))] : []));
      });
      try {
        const list = await Y.youtubeSearch(v);
        if (seq !== searchSeq) return;
        results.replaceChildren(...(list.length ? list.map(r => {
          const pv = h('button', { type: 'button', class: 'btn preview-btn', 'aria-label': 'Écouter un aperçu de 30 secondes', title: 'Aperçu (30 s)' }, '▶');
          pv.addEventListener('click', () => togglePreview(pv, r.id));
          const btns = dlButtons(r.url, r.title);
          btns.prepend(pv);
          return h('li', null,
            h('img', { src: r.thumbnail, alt: '', loading: 'lazy' }),
            h('span', { class: 'tt' }, h('b', null, r.title), h('i', null, (r.uploader || '') + (r.duration ? ' · ' + r.duration : ''))),
            btns);
        }) : [h('li', null, h('span', { class: 'tt' }, h('i', null, 'Aucun résultat.')))]));
      } catch (err) { results.replaceChildren(h('li', null, h('span', { class: 'tt' }, h('i', null, err.message)))); }
    };
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); go(); } });
    input.addEventListener('paste', () => setTimeout(() => { if (input.value.match(YT_URL)) go(); }, 0));
    jobs.forEach(j => jobsEl.append(j.el));
    openSheet('Ajouter', input,
      h('div', { class: 'row-btns' },
        h('button', { type: 'button', class: 'btn primary', onclick: go }, 'Chercher'),
        h('button', { type: 'button', class: 'btn', onclick: () => openImport() }, 'Importer une playlist')),
      h('label', { class: 'switch' }, h('span', null, 'Envoyer aussi le fichier sur cet appareil'), device),
      jobsEl, elsewhere, results);
    if (opts.urls && opts.urls.length) { input.value = opts.urls.join(' '); showUrls(opts.urls); }
    else if (opts.query) { input.value = opts.query; go(); }
    else setTimeout(() => input.focus(), 60);
  }
  function api(url) { return Y.api.get(url); }

  function saveToDevice(id) {
    const a = h('a', { href: Y.api.downloadUrl(id), download: '' });
    document.body.append(a); a.click(); a.remove();
  }

  async function startJob(url, opts, container, knownTitle) {
    const label = h('span', null, 'Démarrage…');
    const fill = h('i');
    const title = h('b', null, knownTitle || url);
    const el = h('div', { class: 'job' }, title, label, h('div', { class: 'jbar' }, fill));
    const job = { el, running: true };
    jobs.push(job);
    container.prepend(el);
    const done = () => { job.running = false; setTimeout(() => { el.remove(); jobs.splice(jobs.indexOf(job), 1); }, 4000); };
    const onProgress = (p) => { title.textContent = p.title || knownTitle || url; label.textContent = p.label || ''; fill.style.width = (p.percent || 0) + '%'; };
    try {
      let res = await Y.download(url, opts, onProgress);
      if (res.duplicate) {
        const why = { library: 'est déjà dans ta bibliothèque.', elsewhere: 'existe déjà sur le serveur (accès ajouté à ta bibliothèque).', device: 'a déjà été téléchargé depuis cet appareil.' };
        const force = await confirmSheet('Déjà là', '« ' + res.title + ' » ' + (why[res.reason] || 'a déjà été téléchargé.') + ' Le télécharger quand même ?', 'Télécharger quand même', false);
        if (!force) { label.textContent = 'Ignoré : déjà présent.'; if (res.reason === 'elsewhere') library.load().then(() => refreshWheel(true)); done(); return; }
        openAdd(); // la feuille de confirmation a remplacé celle-ci : on la rouvre, la tâche y reprend sa place
        res = await Y.download(url, Object.assign({}, opts, { force: true }), onProgress);
      }
      label.textContent = 'Ajouté à ta bibliothèque';
      fill.style.width = '100%';
      await library.load();
      refreshWheel(true);
      paintUsage();
      if (opts.toDevice && res.historyId) saveToDevice(res.historyId);
      const added = library.find(res.historyId);
      // Fenêtre « Ajouter » encore ouverte et plus rien en cours : elle se
      // referme et le titre se lance (morceau sur le disque, clip dans le
      // lecteur vidéo). Si on l'a fermée entre-temps, on n'interrompt rien :
      // le message propose simplement de l'écouter.
      const waiting = sheet.open && el.isConnected;
      done();
      if (added && waiting && !jobs.some(j => j.running)) { closeSheet(); playAdded(added); return; }
      toast('« ' + (res.title || 'Titre') + ' » ajouté', 0, added ? { label: added.isAudio ? 'Écouter' : 'Voir', run: () => playAdded(added) } : null);
    } catch (err) {
      label.textContent = err.message;
      fill.style.background = 'var(--danger)';
      done();
    }
  }

  // Lance un titre tout juste ajouté : clip dans le lecteur vidéo ; morceau
  // posé devant l'aiguille dans sa catégorie, la lecture continuant ensuite
  // avec le reste de la bibliothèque.
  function playAdded(track) {
    const cat = track.category === 'other' ? 'other' : (track.isAudio ? 'audio' : 'video');
    if (state.cat !== cat || state.playlist || state.query) {
      state.cat = cat; state.playlist = null; prefs.set('ytdl_sillon_cat', cat);
      clearTimeout(searchTimer); searchInput.value = ''; state.query = ''; state.frozen = false; beforeSearch = null; syncSearchUi();
      refreshWheel(false);
    }
    const i = state.items.findIndex(it => it.kind !== 'playlist' && it.id === track.id);
    if (i >= 0) wheel.scrollTo(i);
    if (!track.isAudio) { openVideo(track, state.items.filter(t => t.kind !== 'playlist' && !t.isAudio)); return; }
    const tracks = state.items.filter(t => t.kind !== 'playlist' && t.isAudio);
    player.setQueue(tracks, Math.max(0, tracks.indexOf(track)));
  }

  // ------------------------------------------------------ import de playlist

  // Même déroulé que setupPlaylistImport() de capture.php : on analyse un
  // lien public (Spotify, Deezer, YouTube Music, Apple Music) ou un CSV, on
  // crée une playlist locale par groupe, puis chaque titre est cherché sur
  // YouTube, téléchargé en MP3 et rangé dans sa playlist. L'import tourne en
  // fond : la feuille peut être fermée, une pastille montre l'avancement.
  const imp = { groups: [], running: false, done: 0, total: 0, rows: new Map(), startBytes: null, chip: null };

  function openImport() {
    if (imp.running || imp.groups.length) { renderImport(); return; }
    renderImport();
  }

  function renderImport() {
    const status = h('p', null, '');
    const parts = [];
    if (!imp.running) {
      const url = h('input', { class: 'field', placeholder: 'https://open.spotify.com/playlist/…', autocomplete: 'off' });
      const analyze = h('button', { type: 'button', class: 'btn primary' }, 'Analyser');
      analyze.addEventListener('click', async () => {
        if (!url.value.trim()) { url.focus(); return; }
        analyze.disabled = true; status.textContent = 'Analyse de la playlist…';
        try {
          const data = await Y.resolvePlaylist(url.value.trim());
          if (!data.tracks || !data.tracks.length) throw new Error('Aucun titre trouvé dans cette playlist.');
          imp.groups = [{ name: data.name || 'Playlist importée', tracks: data.tracks }];
          renderImport();
        } catch (err) { status.textContent = err.message; analyze.disabled = false; }
      });
      const file = h('input', { type: 'file', accept: '.csv,text/csv' });
      const drop = h('label', { class: 'drop' }, file, h('b', null, 'Glisse ton fichier CSV ici'), h('span', null, 'ou touche pour le choisir — export Spotify, Deezer…'));
      const onFile = async (f) => {
        if (!f) return;
        try { imp.groups = parseImportCsv(await f.text(), f.name); renderImport(); }
        catch (err) { status.textContent = err.message; }
      };
      file.addEventListener('change', () => onFile(file.files[0]));
      ['dragenter', 'dragover'].forEach(t => drop.addEventListener(t, e => { e.preventDefault(); drop.classList.add('is-over'); }));
      ['dragleave', 'drop'].forEach(t => drop.addEventListener(t, e => { e.preventDefault(); drop.classList.remove('is-over'); }));
      drop.addEventListener('drop', e => onFile(e.dataTransfer.files && e.dataTransfer.files[0]));
      parts.push(h('p', null, 'Spotify, Deezer, YouTube Music ou Apple Music : la playlist doit être publique. Amazon Music n\'est pas pris en charge.'),
        url, h('div', { class: 'row-btns' }, analyze), drop, status);
    } else {
      parts.push(h('p', null, 'Import en cours : ' + imp.done + ' / ' + imp.total + '. Tu peux fermer cette fenêtre, il continue en fond.'));
    }

    if (imp.groups.length) {
      const single = imp.groups.length === 1;
      let nameInput = null;
      if (single && !imp.running) {
        nameInput = h('input', { class: 'field', value: imp.groups[0].name, maxlength: '80', 'aria-label': 'Nom de la playlist' });
        parts.push(h('h3', null, 'Nom de la playlist'), nameInput);
      }
      const list = h('ul', { class: 'imp' });
      imp.groups.forEach((g, gi) => {
        if (!single) list.append(h('h4', null, g.name + ' (' + g.tracks.length + ')'));
        g.tracks.forEach((t, ti) => {
          const key = gi + ':' + ti;
          let row = imp.rows.get(key);
          if (!row) {
            row = h('li', null, h('span', { class: 'tt' }, h('b', null, t.title || '(titre inconnu)'), h('i', null, t.artist || '')), h('span', { class: 'st' }, 'en attente'));
            imp.rows.set(key, row);
          }
          list.append(row);
        });
      });
      const total = imp.groups.reduce((n, g) => n + g.tracks.length, 0);
      if (!imp.running) {
        const start = h('button', { type: 'button', class: 'btn primary' }, 'Importer (' + total + ')');
        start.addEventListener('click', () => {
          if (single && nameInput && nameInput.value.trim()) imp.groups[0].name = nameInput.value.trim();
          runImport();
          closeSheet();
        });
        const reset = h('button', { type: 'button', class: 'btn', onclick: () => { imp.groups = []; imp.rows.clear(); renderImport(); } }, 'Autre playlist');
        parts.push(h('div', { class: 'row-btns' }, start, reset));
      }
      parts.push(list);
    }
    openSheet('Importer une playlist', ...parts);
  }

  // Colonnes reconnues comme dans capture.php (titre / artiste / playlist).
  function parseImportCsv(text, fileName) {
    text = text.replace(/^﻿/, '');
    const rows = []; let row = [], field = '', q = false;
    for (let i = 0; i < text.length; i++) {
      const c = text[i];
      if (q) { if (c === '"') { if (text[i + 1] === '"') { field += '"'; i++; } else q = false; } else field += c; }
      else if (c === '"') q = true;
      else if (c === ',') { row.push(field); field = ''; }
      else if (c === '\n' || c === '\r') { if (c === '\r' && text[i + 1] === '\n') i++; row.push(field); field = ''; rows.push(row); row = []; }
      else field += c;
    }
    if (field !== '' || row.length) { row.push(field); rows.push(row); }
    const clean = rows.filter(r => !(r.length === 1 && r[0] === ''));
    if (clean.length < 2) throw new Error('Fichier CSV vide ou illisible.');
    const head = clean[0].map(x => x.trim().toLowerCase());
    const col = (names) => { for (const n of names) { const i = head.indexOf(n); if (i !== -1) return i; } return -1; };
    const ti = col(['track name', 'title', 'track', 'song', 'nom du titre', 'titre']);
    const ai = col(['artist name', 'artist', 'artiste']);
    const pi = col(['playlist name', 'playlist', 'album']);
    if (ti === -1) throw new Error('Colonne de titre introuvable dans ce CSV.');
    const groups = new Map();
    for (const r of clean.slice(1)) {
      const title = (r[ti] || '').trim(); if (!title) continue;
      const key = (pi !== -1 ? (r[pi] || '').trim() : '') || fileName.replace(/\.csv$/i, '');
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key).push({ artist: ai !== -1 ? (r[ai] || '').trim() : '', title });
    }
    if (!groups.size) throw new Error('Aucun titre trouvé dans ce fichier.');
    return Array.from(groups, ([name, tracks]) => ({ name, tracks }));
  }

  function paintImportChip() {
    if (!imp.running) { if (imp.chip) { imp.chip.remove(); imp.chip = null; } return; }
    if (!imp.chip) {
      imp.chip = h('button', { type: 'button', class: 'import-chip', 'aria-label': 'Import de playlist en cours — afficher', onclick: () => renderImport() }, h('span'));
      document.body.append(imp.chip);
    }
    const pct = imp.total ? Math.round(100 * imp.done / imp.total) : 0;
    imp.chip.style.setProperty('--pct', pct + '%');
    imp.chip.firstChild.textContent = pct + '%';
  }

  async function runImport() {
    if (imp.running) return;
    imp.running = true; imp.done = 0;
    imp.total = imp.groups.reduce((n, g) => n + g.tracks.length, 0);
    imp.startBytes = library.usage ? library.usage.total_bytes : null;
    paintImportChip();
    const failed = [];
    for (let gi = 0; gi < imp.groups.length; gi++) {
      const g = imp.groups[gi];
      let playlistId = null;
      try { playlistId = Number((await library.createPlaylist(g.name)).id); }
      catch (err) { toast('Impossible de créer la playlist « ' + g.name + ' ».'); imp.done += g.tracks.length; paintImportChip(); continue; }
      for (let ti = 0; ti < g.tracks.length; ti++) {
        const t = g.tracks[ti];
        const row = imp.rows.get(gi + ':' + ti) || h('li');
        const st = row.querySelector('.st') || h('span');
        row.classList.add('is-active');
        try {
          st.textContent = 'recherche…';
          let url = t.youtube_url || null;
          if (!url) {
            const q = [t.artist, t.title].filter(Boolean).join(' ');
            const first = q ? (await Y.youtubeSearch(q, 1))[0] : null;
            url = first ? first.url : null;
          }
          if (!url) throw new Error('introuvable');
          const res = await Y.download(url, { audioOnly: true }, (p) => { st.textContent = Math.round(p.percent || 0) + ' %'; });
          let historyId = res.historyId || null;
          await library.load();
          if (res.duplicate) {
            const match = library.tracks.find(x => x.title === res.title);
            historyId = match ? match.id : null;
          }
          if (!historyId) throw new Error('échec');
          await library.loadPlaylists();
          const wanted = Array.from(new Set([...(library.membership[historyId] || []).map(Number), playlistId]));
          await Y.api.json('playlist-update.php', { action: 'set_membership', history_id: historyId, playlist_ids: wanted });
          row.classList.add('is-done');
          st.textContent = res.duplicate ? 'déjà présent' : 'ajouté';
        } catch (err) {
          row.classList.add('is-failed');
          st.textContent = 'introuvable';
          failed.push(t);
        }
        row.classList.remove('is-active');
        imp.done++;
        paintImportChip();
      }
    }
    imp.running = false;
    paintImportChip();
    paintUsage();
    await library.loadPlaylists();
    refreshWheel(true);
    const ok = imp.total - failed.length;
    const u = library.usage;
    const consumed = u && imp.startBytes !== null ? Math.max(0, u.total_bytes - imp.startBytes) : null;
    const summary = [h('p', null, ok + ' morceau' + (ok > 1 ? 'x' : '') + ' importé' + (ok > 1 ? 's' : '') + ' sur ' + imp.total + (failed.length ? ' (' + failed.length + ' échec' + (failed.length > 1 ? 's' : '') + ')' : '') + '.')];
    if (u && u.quota_bytes) summary.push(h('p', null, (consumed !== null ? fmtBytes(consumed) + ' consommés — ' : '') + fmtBytes(Math.max(0, u.quota_bytes - u.total_bytes)) + ' restants.'));
    if (failed.length) summary.push(h('h3', null, 'Introuvables'), h('ul', { class: 'imp' }, failed.map(t => h('li', null, h('span', { class: 'tt' }, h('b', null, t.title), h('i', null, t.artist || ''))))));
    imp.groups = []; imp.rows.clear();
    openSheet('Import terminé', ...summary);
  }

  // ------------------------------------------------------ synchro manuelle

  // Même méthode que capture.php : on écoute depuis le début et on appuie
  // (bouton ou barre d'espace) au moment où chaque ligne commence. La synchro
  // enregistrée profite ensuite à tous les comptes.
  function syncSession(track, entry, media, container, onExit) {
    const lines = (entry.lyrics || (entry.synced || []).map(l => l.text).join('\n')).split('\n').map(l => l.trim()).filter(Boolean);
    if (!lines.length) { toast('Pas de paroles à synchroniser pour ce titre.'); return null; }
    const times = new Array(lines.length).fill(null);
    let index = 0, ready = false, active = true;
    media.pause(); media.time = 0;

    const progress = h('div', { class: 'sync-progress' });
    const current = h('div', { class: 'sync-current' });
    const next = h('div', { class: 'sync-next' });
    const mark = h('button', { type: 'button', class: 'sync-mark' });
    const pause = h('button', { type: 'button' }, 'Pause');
    const undo = h('button', { type: 'button' }, 'Corriger la dernière ligne');
    const skip = h('button', { type: 'button' }, 'Ligne non chantée : ignorer');
    const save = h('button', { type: 'button', class: 'btn primary' }, 'Enregistrer');
    const cancel = h('button', { type: 'button' }, 'Quitter sans enregistrer');

    function render() {
      const done = index >= lines.length;
      progress.textContent = done ? 'Synchro terminée' : 'Ligne ' + (index + 1) + ' sur ' + lines.length;
      current.textContent = done ? 'Toutes les lignes sont traitées.' : lines[index];
      next.textContent = done ? 'Enregistre pour appliquer cette synchro : elle sera partagée avec les autres comptes.' : 'Ensuite : ' + (lines[index + 1] || 'dernière ligne');
      mark.disabled = done;
      mark.textContent = !ready ? 'Démarrer depuis le début' : media.paused ? 'Reprendre la lecture' : 'Cette ligne commence maintenant · Espace';
      pause.disabled = !ready || done;
      pause.textContent = media.paused ? 'Reprendre' : 'Pause';
      undo.disabled = index === 0;
      skip.disabled = done || !ready;
      save.hidden = !done;
      save.disabled = !times.some(t => t !== null);
      if (done) media.pause();
    }
    function doMark() {
      if (!active || index >= lines.length) return;
      if (!ready || media.paused) { ready = true; media.play(); setTimeout(render, 150); return; }
      const t = media.time;
      const prev = times.slice(0, index).filter(x => x !== null).pop();
      if (prev !== undefined && t <= prev) { toast('Avance après la dernière ligne calée, ou corrige une ligne précédente.'); return; }
      times.fill(null, index); times[index] = t; index++;
      render();
    }
    mark.addEventListener('click', doMark);
    pause.addEventListener('click', () => { media.paused ? media.play() : media.pause(); setTimeout(render, 150); });
    undo.addEventListener('click', () => {
      if (index === 0) return;
      index--;
      const t = times[index];
      media.pause();
      if (t !== null) media.time = Math.max(0, t - 3);
      times.fill(null, index);
      render();
    });
    skip.addEventListener('click', () => { if (index < lines.length) { index++; render(); } });
    const onKey = (e) => {
      if (!active || e.code !== 'Space' || e.ctrlKey || e.altKey || e.metaKey || /INPUT|TEXTAREA|SELECT/.test(e.target.tagName)) return;
      e.preventDefault(); e.stopPropagation();
      if (!e.repeat) doMark();
    };
    document.addEventListener('keydown', onKey, true);
    const exit = (saved) => { if (!active) return; active = false; document.removeEventListener('keydown', onKey, true); onExit(saved); };
    cancel.addEventListener('click', () => exit(null));
    save.addEventListener('click', async () => {
      const out = lines.map((text, i) => ({ time: times[i], text })).filter(l => l.time !== null);
      save.disabled = true; save.textContent = 'Enregistrement…';
      try {
        const updated = await lyrics.saveManualSync(track, out);
        toast('Synchro enregistrée : elle profite maintenant à tout le monde.');
        exit(updated);
      } catch (err) { toast("Échec de l'enregistrement de la synchro."); save.disabled = false; save.textContent = 'Enregistrer'; }
    });
    media.onChange = render;
    container.replaceChildren(h('div', { class: 'sync' }, progress, current, next, mark,
      h('div', { class: 'sync-row' }, pause, undo), h('div', { class: 'sync-row' }, skip, cancel), save));
    render();
    return { exit };
  }

  async function startAudioSync(track) {
    const entry = await lyrics.get(track);
    const media = {
      get time() { return player.currentTime; }, set time(v) { player.seek(v); },
      get paused() { return !player.playing; }, play() { player.play(); }, pause() { player.pause(); },
    };
    const body = openSheet('Synchro des paroles', h('p', null, ''));
    let session = null;
    // Pause/lecture depuis l'écran verrouillé ou un casque : l'affichage suit.
    const offState = player.on('state', () => { if (media.onChange) media.onChange(); });
    const onClose = () => { if (session) session.exit(null); };
    sheet.addEventListener('close', onClose, { once: true });
    session = syncSession(track, entry, media, body, (saved) => {
      sheet.removeEventListener('close', onClose);
      offState();
      session = null;
      if (sheet.open) closeSheet();
      if (saved && player.track === track) loadLyrics(track);
    });
    if (!session) { offState(); closeSheet(); }
  }

  // ------------------------------------------------------------------ clip

  const video = $('video');
  let videoList = [], videoEntry = null, videoTrack = null, videoSync = null;
  function openVideo(track, list) {
    player.pause();
    stopPreview();
    if (videoSync) videoSync.exit(null);
    videoList = list && list.length ? list : [track];
    videoTrack = track;
    $('video-artist').textContent = track.artist || '';
    $('video-title').textContent = track.name;
    $('video-download').href = Y.api.downloadUrl(track.id);
    video.src = Y.api.streamUrl(track.id);
    Y.normalize(video, track.gain_db);
    $('video-layer').hidden = false;
    video.play().catch(() => {});
    videoEntry = null;
    $('video-lyrics').textContent = '';
    paintVideoTools();
    const i = videoList.indexOf(track);
    $('video-prev').disabled = i <= 0;
    $('video-next').disabled = i < 0 || i >= videoList.length - 1;
    lyrics.get(track).then(entry => { if (videoTrack === track) { videoEntry = entry; paintVideoTools(); } });
  }
  function paintVideoTools() {
    const synced = !!(videoEntry && videoEntry.synced && videoEntry.synced.length);
    $('video-offset-box').hidden = !synced || !!videoSync;
    $('video-sync').hidden = !(videoEntry && (videoEntry.lyrics || synced)) || !!videoSync;
    $('video-sync').textContent = synced ? 'Refaire la synchro' : 'Synchroniser les paroles';
    $('video-tools').hidden = !!videoSync;
    $('video-lyrics').hidden = !!videoSync;
    $('video-syncbox').hidden = !videoSync;
    const o = (videoEntry && videoEntry.videoOffset) || 0;
    $('video-lyr-offset').textContent = o ? (o > 0 ? '+' : '') + o.toFixed(1).replace('.', ',') + ' s' : 'calé';
  }
  function closeVideo() {
    if (videoSync) videoSync.exit(null);
    video.pause(); video.removeAttribute('src'); video.load();
    $('video-layer').hidden = true; videoTrack = null;
  }
  $('video-close').addEventListener('click', closeVideo);
  // Partager le clip affiché, comme un morceau depuis le titre en cours.
  $('video-share').addEventListener('click', () => { if (videoTrack) openShare(videoTrack); });
  $('video-prev').addEventListener('click', () => { const i = videoList.indexOf(videoTrack); if (i > 0) openVideo(videoList[i - 1], videoList); });
  $('video-next').addEventListener('click', () => { const i = videoList.indexOf(videoTrack); if (i >= 0 && i + 1 < videoList.length) openVideo(videoList[i + 1], videoList); });
  video.addEventListener('timeupdate', () => {
    if (!videoEntry || !videoEntry.synced || videoSync) return;
    const i = lyrics.lineAt(videoEntry, video.currentTime, videoEntry.videoOffset);
    $('video-lyrics').textContent = i >= 0 ? videoEntry.synced[i].text : '';
  });
  ['play', 'pause'].forEach(ev => video.addEventListener(ev, () => { if (videoSync && videoMedia.onChange) videoMedia.onChange(); }));
  video.addEventListener('ended', () => {
    const i = videoList.indexOf(videoTrack);
    if (!videoSync && player.autoNext && i >= 0 && i + 1 < videoList.length) openVideo(videoList[i + 1], videoList);
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('video-layer').hidden && !videoSync) closeVideo(); });

  // Décalage propre au clip (variant « video » de lyrics-offset.php) : un clip
  // commence souvent par une intro absente du fichier audio.
  let videoOffsetTimer = 0;
  function nudgeVideoOffset(value) {
    if (!videoEntry || !videoTrack) return;
    videoEntry.videoOffset = Math.round(value * 10) / 10;
    paintVideoTools();
    clearTimeout(videoOffsetTimer);
    const track = videoTrack, v = videoEntry.videoOffset;
    videoOffsetTimer = setTimeout(() => lyrics.setOffset(track, v, 'video').catch(err => toast(err.message)), 700);
  }
  $('video-lyr-minus').addEventListener('click', () => nudgeVideoOffset(((videoEntry && videoEntry.videoOffset) || 0) - 0.5));
  $('video-lyr-plus').addEventListener('click', () => nudgeVideoOffset(((videoEntry && videoEntry.videoOffset) || 0) + 0.5));
  $('video-lyr-now').addEventListener('click', () => {
    if (!videoEntry || !videoEntry.synced || !videoEntry.synced.length) return;
    nudgeVideoOffset(video.currentTime - videoEntry.synced[0].time);
    toast('Paroles du clip recalées sur la première ligne', 1600);
  });
  const videoMedia = {
    get time() { return video.currentTime; }, set time(v) { video.currentTime = v; },
    get paused() { return video.paused; }, play() { video.play().catch(() => {}); }, pause() { video.pause(); },
  };
  $('video-sync').addEventListener('click', () => {
    if (!videoEntry || !videoTrack) return;
    const track = videoTrack;
    videoSync = syncSession(track, videoEntry, videoMedia, $('video-syncbox'), (saved) => {
      videoSync = null;
      if (saved && videoTrack === track) videoEntry = saved;
      paintVideoTools();
    });
    paintVideoTools();
  });

  // -------------------------------------------------------- playlists

  $('deck-plmenu').addEventListener('click', () => {
    const pl = state.playlist; if (!pl) return;
    const audio = pl.tracks.filter(t => t.isAudio);
    const items = [
      h('li', null, h('button', { type: 'button', html: ICON.play, onclick: () => { closeSheet(); if (player.shuffle) player.setShuffle(false); if (audio.length) player.setQueue(audio, 0, { playlistId: pl.id }); } }, 'Tout lire')),
      h('li', null, h('button', { type: 'button', html: ICON.shuffle, onclick: () => { closeSheet(); player.setShuffle(true); if (audio.length) { const i = Math.floor(Math.random() * audio.length); player.setQueue(audio, i, { playlistId: pl.id }); } } }, 'Lecture aléatoire')),
      h('li', null, h('button', { type: 'button', html: ICON.edit, onclick: () => renamePlaylist(pl) }, 'Renommer')),
      h('li', null, h('button', { type: 'button', class: 'danger', html: ICON.trash, onclick: () => deletePlaylist(pl) }, 'Supprimer la playlist')),
    ];
    openSheet(pl.name, h('p', null, pl.tracks.length + ' titre' + (pl.tracks.length > 1 ? 's' : '')), h('ul', { class: 'menu' }, items));
  });

  function renamePlaylist(pl) {
    const input = h('input', { class: 'field', value: pl.name, maxlength: '80' });
    const save = h('button', { type: 'button', class: 'btn primary' }, 'Renommer');
    const go = async () => {
      if (!input.value.trim()) return;
      save.disabled = true;
      try {
        const res = await library.renamePlaylist(pl.id, input.value.trim());
        pl.name = res.name || input.value.trim();
        closeSheet(); refreshWheel(true); toast('Playlist renommée');
      } catch (err) { toast(err.message); save.disabled = false; }
    };
    save.addEventListener('click', go);
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') go(); });
    openSheet('Renommer la playlist', input, h('div', { class: 'row-btns' }, save));
    setTimeout(() => input.focus(), 50);
  }

  async function deletePlaylist(pl) {
    const ok = await confirmSheet('Supprimer', 'Supprimer la playlist « ' + pl.name + ' » ? Les titres restent dans ta bibliothèque.', 'Supprimer', true);
    if (!ok) return;
    try {
      await library.deletePlaylist(pl.id);
      state.playlist = null;
      refreshWheel(false);
      toast('Playlist supprimée');
    } catch (err) { toast(err.message); }
  }

  // Découvrir : une de tes playlists au hasard, posée sur le disque (le
  // rayon « Playlist au hasard » de l'accueil classique).
  $('deck-dice').addEventListener('click', async () => {
    await library.loadPlaylists();
    const pool = library.playlists.filter(p => p.item_count > 0 && (!state.playlist || p.id !== state.playlist.id));
    if (!pool.length) { toast('Crée d\'abord une playlist pour découvrir au hasard.'); return; }
    const p = pool[Math.floor(Math.random() * pool.length)];
    state.cat = 'list'; prefs.set('ytdl_sillon_cat', 'list');
    await openPlaylist({ id: p.id, name: p.name });
    const audio = state.playlist ? state.playlist.tracks.filter(t => t.isAudio) : [];
    toast('Au hasard : « ' + p.name + ' »', 0, audio.length ? { label: 'Tout lire', run: () => player.setQueue(audio, 0, { playlistId: p.id }) } : null);
  });

  // ------------------------------------------------------------ nouveautés

  const badges = { audio: 0, video: 0, other: 0 };
  function paintBadges() {
    document.querySelectorAll('#hub button[data-cat]').forEach(b => {
      const n = badges[b.dataset.cat] || 0;
      const el = b.querySelector('.badge');
      if (!el) return;
      el.hidden = !n;
      el.textContent = n > 9 ? '9+' : String(n);
    });
  }
  $('hub').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-cat]');
    if (b && badges[b.dataset.cat]) { badges[b.dataset.cat] = 0; paintBadges(); }
  });
  library.on('news', (news) => {
    if (news.shared.length === 1) toast((news.shared[0].shared_by_name || 'Quelqu’un') + ' t’a partagé « ' + news.shared[0].title + ' ».', 6000);
    else if (news.shared.length > 1) toast(news.shared.length + ' nouveaux fichiers partagés avec toi.', 6000);
    Object.keys(news.byCat).forEach(cat => { if (cat !== state.cat || state.playlist) badges[cat] += news.byCat[cat]; });
    paintBadges();
    paintUsage();
    if (!wheel.drag && !wheel.raf) refreshWheel(true);
  });

  // -------------------------------------------------------------- démarrage

  // Recherche apportée depuis une autre apparence (voir openSkinGallery) :
  // reprise telle quelle, figée ou non, une seule fois.
  const CARRY_SEARCH = 'ytdl_carry_search';
  function restoreCarriedSearch() {
    let carried = null;
    try { carried = JSON.parse(sessionStorage.getItem(CARRY_SEARCH) || 'null'); sessionStorage.removeItem(CARRY_SEARCH); } catch (err) {}
    if (!carried || !String(carried.query || '').trim()) return;
    if (CATS[carried.cat] && carried.cat !== 'list') state.cat = carried.cat;
    searchInput.value = state.query = String(carried.query);
    state.frozen = !!carried.frozen;
    if (!desktop.matches) { app.classList.add('is-searching'); $('search-toggle').setAttribute('aria-expanded', 'true'); }
    syncSearchUi();
  }

  async function start() {
    showNow(null, true);
    await Promise.all([library.load(), library.loadPlaylists()]);
    paintUsage();
    restoreCarriedSearch();
    refreshWheel(false);
    paintOptions();
    renderQueue();
    library.startPolling();

    // Liens reçus par le partage natif du téléphone : directement proposés
    // au téléchargement. L'adresse est nettoyée pour qu'un rechargement ne
    // les repropose pas.
    if (boot.sharedUrls && boot.sharedUrls.length) {
      if (location.search) history.replaceState(null, '', location.pathname);
      openAdd({ urls: boot.sharedUrls });
    }

    // Connexion par empreinte : proposée une fois après la connexion, si
    // l'appareil le permet (voir ui/passkey.js).
    if (window.YtdlPasskey) setTimeout(() => window.YtdlPasskey.offer({ id: boot.id, name: boot.name, avatar: boot.avatar }, (m) => toast(m, 4000)), 2500);

    // Reprise : même règle que l'interface classique (checkPlaybackResume).
    //  - même appareil, morceau en lecture au moment de partir : on repart
    //    directement là où on en était ; bannière seulement si le navigateur
    //    bloque la lecture automatique ;
    //  - même appareil, morceau en pause : remis en place, en pause, sans rien
    //    proposer (une pause volontaire ne se relance pas toute seule) ;
    //  - autre appareil : bannière « Reprendre ? », acceptée d'office au bout
    //    de 5 s si on ne fait rien.
    const last = await Y.lastPlayback();
    const track = last && library.find(last.history_id);
    if (track && track.isAudio) {
      const pos = Number(last.position_seconds) || 0;
      // Dans sa playlist si on écoutait une playlist, sinon dans la bibliothèque.
      let queue = null, index = -1;
      if (last.playlist_id) {
        try {
          const items = (await library.playlistItems(last.playlist_id)).filter(t => t.isAudio);
          index = items.findIndex(t => t.id === track.id);
          if (index >= 0) queue = items;
        } catch (err) {}
      }
      if (!queue && state.frozen && state.query.trim()) {
        // Recherche figée reprise d'une autre apparence : la file reste celle
        // des résultats, comme avant le changement.
        const results = searchItems(Y.sortTracks(library.byCategory(track.category === 'other' ? 'other' : 'audio'), state.sort), state.query)
          .filter(t => t.isAudio);
        if (results.includes(track)) { queue = results; index = results.indexOf(track); }
      }
      if (!queue) {
        queue = Y.sortTracks(library.byCategory(track.category === 'other' ? 'other' : 'audio'), state.sort);
        index = queue.indexOf(track);
      }
      player.setQueue(queue, index, { autoplay: false, startAt: pos, playlistId: queue && last.playlist_id && index >= 0 ? last.playlist_id : null });
      paintTime();

      const offerResume = () => {
        if (boot.sharedUrls && boot.sharedUrls.length) return;
        resumeToastFor = track;
        toast('Reprendre « ' + track.name + ' » à ' + fmtTime(pos) + ' ?', 0, { label: 'Reprendre', run: () => player.play() });
        clearTimeout(resumeTimer);
        resumeTimer = setTimeout(() => {
          if (resumeToastFor !== track || player.playing) return;
          $('toast').hidden = true;
          resumeToastFor = null;
          player.play();
        }, 5000);
      };
      if (last.device_id === Y.deviceId()) {
        if (Number(last.is_playing) && !(await player.play())) offerResume();
      } else if (pos > 5) {
        offerResume();
      }
    }
  }
  let resumeTimer = 0;
  start();
})();
