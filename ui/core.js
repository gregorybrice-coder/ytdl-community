/*
 * Moteur commun des nouvelles interfaces (Sillon, Orbite, Platine).
 *
 * Tout ce qui ne dépend PAS de l'habillage vit ici : appels au serveur,
 * modèle de bibliothèque, lecteur audio (normalisation, fondu enchaîné),
 * paroles, bio, sauvegarde de la position de lecture. Chaque habillage
 * (ui/sillon.js…) ne fait que brancher son DOM sur ces briques, de sorte que
 * les trois apparences partagent exactement le même comportement.
 *
 * Les endpoints PHP sont ceux de l'interface classique (capture.php),
 * appelés à l'identique : rien n'a été modifié côté serveur.
 */
(function () {
  'use strict';

  const BOOT = window.YTDL_BOOT || {};

  // ---------------------------------------------------------------- utilitaires

  function emitter() {
    const map = new Map();
    return {
      on(name, fn) { if (!map.has(name)) map.set(name, new Set()); map.get(name).add(fn); return () => map.get(name).delete(fn); },
      emit(name, payload) { (map.get(name) || []).forEach(fn => { try { fn(payload); } catch (err) { console.error(err); } }); },
    };
  }

  function fmtTime(s) {
    s = Math.max(0, Math.floor(Number(s) || 0));
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(sec).padStart(2, '0');
  }

  function clamp(v, a, b) { return Math.min(b, Math.max(a, v)); }

  // Mêmes clés localStorage que capture.php : passer d'une interface à
  // l'autre conserve lecture aléatoire, enchaînement, fondu et volume.
  const prefs = {
    bool(key, fallback) {
      try { const raw = localStorage.getItem(key); return raw === null ? fallback : raw === '1'; } catch (err) { return fallback; }
    },
    setBool(key, value) { try { localStorage.setItem(key, value ? '1' : '0'); } catch (err) {} },
    get(key, fallback) { try { const raw = localStorage.getItem(key); return raw === null ? fallback : raw; } catch (err) { return fallback; } },
    set(key, value) { try { localStorage.setItem(key, String(value)); } catch (err) {} },
  };

  // Identifiant d'appareil partagé avec capture.php (même clé, même cookie) :
  // la déduplication « déjà téléchargé depuis cet appareil » reste cohérente.
  let fallbackDeviceId = '';
  function deviceId() {
    try {
      const cookie = (document.cookie.match(/(?:^|; )ytdl_device_id=([^;]+)/) || [])[1];
      let id = localStorage.getItem('ytdl_device_id') || (cookie ? decodeURIComponent(cookie) : '');
      if (!id) id = 'dev-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
      localStorage.setItem('ytdl_device_id', id);
      document.cookie = 'ytdl_device_id=' + encodeURIComponent(id) + '; Max-Age=31536000; Path=/; SameSite=Lax';
      return id;
    } catch (err) {
      if (!fallbackDeviceId) fallbackDeviceId = 'dev-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
      return fallbackDeviceId;
    }
  }

  // Découpage « Artiste - Titre », identique à splitArtistTitle() de
  // capture.php (tirets simple, demi-cadratin et cadratin ; repli sur la
  // chaîne YouTube quand le titre ne contient pas d'artiste).
  function splitArtistTitle(track) {
    const full = ((track && track.title) || 'Sans titre').trim();
    const uploader = (track && track.uploader) ? String(track.uploader).trim() : '';
    const match = full.match(/^(.+?)\s+[-–—]\s+(.+)$/);
    if (match && match[1].trim() && match[2].trim()) return { artist: match[1].trim(), title: match[2].trim() };
    return { artist: uploader, title: full };
  }

  // ------------------------------------------------------------------- serveur

  class ApiError extends Error {
    constructor(message, status, data) { super(message); this.status = status; this.data = data; }
  }

  async function request(url, options) {
    let res;
    try {
      res = await fetch(url, Object.assign({ credentials: 'same-origin', headers: { Accept: 'application/json' } }, options));
    } catch (err) {
      throw new ApiError('Connexion au serveur impossible.', 0, null);
    }
    let data = null;
    try { data = await res.json(); } catch (err) {}
    if (res.status === 401) { window.location.href = 'index.php'; throw new ApiError('Session expirée.', 401, data); }
    if (!res.ok || (data && data.error)) {
      throw new ApiError((data && data.error) || ('Erreur ' + res.status), res.status, data);
    }
    return data;
  }

  const api = {
    get: (url) => request(url),
    json: (url, body) => request(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) }),
    form: (url, fields) => {
      const fd = new FormData();
      Object.keys(fields).forEach(k => fd.append(k, fields[k]));
      return request(url, { method: 'POST', body: fd });
    },
    streamUrl: (id) => 'library-play.php?id=' + encodeURIComponent(id),
    downloadUrl: (id) => 'library-play.php?id=' + encodeURIComponent(id) + '&download=1',
  };

  // -------------------------------------------------------------- bibliothèque

  const library = Object.assign(emitter(), {
    tracks: [],        // toutes les pistes (les miennes + partagées avec moi)
    usage: null,
    playlists: [],
    membership: {},    // history_id -> [playlist_id]
    deleted: new Set(), // titres supprimés ici, voir load()
    error: null,

    async load() {
      try {
        const data = await api.get('library.php');
        const own = data.tracks || [];
        const shared = (data.shared_tracks || []).map(t => Object.assign({}, t, { _shared: true }));
        const fresh = own.concat(shared);
        // Un sondage parti avant une suppression peut revenir après elle avec
        // l'ancienne liste : le titre réapparaissait. On écarte donc les
        // titres supprimés tant que le serveur les renvoie encore.
        const ids = new Set(fresh.map(t => Number(t.id)));
        this.deleted.forEach(id => { if (!ids.has(id)) this.deleted.delete(id); });
        this.tracks = fresh.filter(t => !this.deleted.has(Number(t.id))).map(decorate)
          .sort((a, b) => new Date(b.downloaded_at) - new Date(a.downloaded_at));
        this.usage = data.usage || null;
        this.error = null;
      } catch (err) {
        this.error = err.message;
      }
      this.emit('change');
      return this.tracks;
    },

    async loadPlaylists() {
      try {
        const data = await api.get('playlists.php');
        this.playlists = data.playlists || [];
        this.membership = data.membership || {};
      } catch (err) { /* liste vide : l'habillage affiche l'absence */ }
      this.emit('playlists');
      return this.playlists;
    },

    async playlistItems(id) {
      const data = await api.get('playlists.php?id=' + encodeURIComponent(id));
      const items = (data.playlist && data.playlist.items) || [];
      // Les items de playlist ne portent que l'essentiel : on les complète
      // avec la version bibliothèque quand elle est connue (note, durée…).
      const byId = new Map(this.tracks.map(t => [t.id, t]));
      return items.map(it => byId.get(Number(it.id)) || decorate(Object.assign({ category: 'music' }, it)));
    },

    byCategory(cat) {
      return this.tracks.filter(t => {
        if (cat === 'other') return t.category === 'other';
        if (t.category === 'other') return false;
        return cat === 'video' ? !t.isAudio : t.isAudio;
      });
    },

    find(id) { return this.tracks.find(t => t.id === Number(id)) || null; },

    async rate(track, rating) {
      const data = await api.json('library-rate.php', { id: track.id, rating });
      track.rating = Number(data.rating);
      this.emit('track', track);
    },

    async rename(track, title) {
      const data = await api.json('library-rename.php', { id: track.id, title });
      track.title = data.title;
      Object.assign(track, decorate(track));
      this.emit('track', track);
      return data;
    },

    async remove(track) {
      if (track._shared) await api.form('library-unshare.php', { id: track.id });
      else await api.form('library-delete.php', { id: track.id });
      this.deleted.add(track.id);
      this.tracks = this.tracks.filter(t => t.id !== track.id);
      this.emit('change');
    },

    async users(track) {
      const data = await api.get('users-list.php?history_id=' + encodeURIComponent(track.id));
      return data.users || [];
    },

    async share(track, userIds) {
      await api.json('library-share.php', { id: track.id, user_ids: userIds });
      track.shared_with = userIds.slice();
      track.shared_count = userIds.length;
      this.emit('track', track);
    },

    async setMembership(track, playlistIds) {
      const data = await api.json('playlist-update.php', { action: 'set_membership', history_id: track.id, playlist_ids: playlistIds });
      this.membership[track.id] = data.playlist_ids || playlistIds;
      await this.loadPlaylists();
    },

    async createPlaylist(name) {
      const data = await api.json('playlist-update.php', { action: 'create', name });
      await this.loadPlaylists();
      return data;
    },

    // Ajoute plusieurs morceaux en fin de playlist (ceux déjà présents sont
    // ignorés) ; renvoie le nombre réellement ajouté.
    async addToPlaylist(id, tracks) {
      const data = await api.json('playlist-update.php', { action: 'add_items', id, history_ids: tracks.map(t => t.id) });
      await this.loadPlaylists();
      return data.added || 0;
    },

    async renamePlaylist(id, name) {
      const data = await api.json('playlist-update.php', { action: 'rename', id, name });
      await this.loadPlaylists();
      return data;
    },

    async deletePlaylist(id) {
      await api.json('playlist-update.php', { action: 'delete', id });
      await this.loadPlaylists();
    },

    async removeFromPlaylist(id, track) {
      await api.json('playlist-update.php', { action: 'remove_item', id, history_id: track.id });
      const list = this.membership[track.id];
      if (list) this.membership[track.id] = list.filter(p => Number(p) !== Number(id));
      await this.loadPlaylists();
    },

    // Titres présents sur le serveur chez d'autres comptes (library-find-elsewhere.php).
    async findElsewhere(query, audioOnly) {
      try {
        const data = await api.get('library-find-elsewhere.php?' + new URLSearchParams({ q: query, audio_only: audioOnly ? '1' : '0' }));
        return data.results || [];
      } catch (err) { return []; }
    },

    async borrow(id) {
      await api.json('library-borrow.php', { id });
      await this.load();
    },

    // Sondage périodique (25 s, comme capture.php) : c'est ce qui fait
    // apparaître un partage reçu pendant que la page reste ouverte. Émet
    // 'news' avec les nouveautés par catégorie et les partages reçus.
    startPolling() {
      if (this.pollTimer) return;
      this.pollTimer = setInterval(async () => {
        if (document.hidden) return;
        const before = new Set(this.tracks.map(t => t.id));
        const previousError = this.error;
        await this.load();
        if (this.error || previousError) return;
        const fresh = this.tracks.filter(t => !before.has(t.id));
        if (!fresh.length) return;
        const byCat = { audio: 0, video: 0, other: 0 };
        fresh.forEach(t => { byCat[t.category === 'other' ? 'other' : t.isAudio ? 'audio' : 'video']++; });
        this.emit('news', { tracks: fresh, shared: fresh.filter(t => t._shared), byCat });
      }, 25000);
    },
  });

  // ----------------------------------------------------------------- compte

  const account = {
    setAvatar: (avatar) => api.json('set-avatar.php', { avatar }),
    setSkin: (skin) => api.json('set-skin.php', { skin }),
    requestEmailChange: (email) => api.json('request-email-change.php', { email }),
    ackNotification: (id) => api.json('notification-ack.php', { id }).catch(() => {}),

    pushSupported() { return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window; },
    async pushEnabled() {
      if (!this.pushSupported()) return false;
      try { const reg = await navigator.serviceWorker.ready; return !!(await reg.pushManager.getSubscription()); } catch (err) { return false; }
    },
    // Même séquence que enablePush()/disablePush() de capture.php.
    async setPush(enabled) {
      const reg = await navigator.serviceWorker.ready;
      const existing = await reg.pushManager.getSubscription();
      if (enabled && !existing) {
        if (Notification.permission === 'denied') throw new ApiError('Notifications bloquées : autorise-les dans les réglages du téléphone pour cette app.', 0);
        if (await Notification.requestPermission() !== 'granted') throw new ApiError('Permission refusée.', 0);
        const key = BOOT.vapidKey || '';
        const pad = '='.repeat((4 - key.length % 4) % 4);
        const raw = atob((key + pad).replace(/-/g, '+').replace(/_/g, '/'));
        const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: Uint8Array.from([...raw].map(c => c.charCodeAt(0))) });
        await api.json('push-subscribe.php', sub.toJSON());
      } else if (!enabled && existing) {
        const endpoint = existing.endpoint;
        await existing.unsubscribe();
        await api.json('push-unsubscribe.php', { endpoint });
      }
    },
  };
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('service-worker.js').catch(() => {});

  function decorate(t) {
    const parts = splitArtistTitle(t);
    t.id = Number(t.id);
    t.artist = parts.artist;
    t.name = parts.title;
    t.isAudio = Number(t.audio_only) === 1;
    t.duration = Number(t.duration_seconds) || 0;
    t.rating = Number(t.rating) || 0;
    // Tri alphabétique sur le titre du morceau (pas l'artiste), sans tenir
    // compte des guillemets, parenthèses ou points de suspension en tête.
    t.sortKey = (parts.title || t.title || '').toLowerCase().replace(/^[^\p{L}\p{N}]+/u, '');
    return t;
  }

  function sortTracks(list, mode) {
    if (!mode || mode === 'recent') return list.slice();
    const sorted = list.slice().sort((a, b) => a.sortKey.localeCompare(b.sortKey, 'fr', { sensitivity: 'base', numeric: true }));
    if (mode === 'desc') sorted.reverse();
    return sorted;
  }

  function filterTracks(list, query) {
    const q = (query || '').trim().toLowerCase();
    if (!q) return list;
    const words = q.split(/\s+/);
    return list.filter(t => {
      const hay = ((t.title || '') + ' ' + (t.uploader || '')).toLowerCase();
      return words.every(w => hay.includes(w));
    });
  }

  // ------------------------------------------------------------------- lecteur

  const CROSSFADE_MS = 900; // même durée que capture.php

  const player = Object.assign(emitter(), {
    queue: [],
    index: -1,
    order: [],           // ordre de lecture (mélangé ou non) : indices dans queue
    playing: false,
    shuffle: prefs.bool('ytdl_shuffle', false),
    autoNext: prefs.bool('ytdl_autonext', false),
    crossfade: prefs.bool('ytdl_crossfade', true),
    volume: clamp(parseInt(prefs.get('ytdl_volume', '100'), 10) || 0, 0, 100),
    playlistId: null,

    setQueue(list, startIndex, opts) {
      this.queue = list.slice();
      this.playlistId = (opts && opts.playlistId) || null;
      this.rebuildOrder(startIndex);
      this.load(startIndex, opts);
    },

    rebuildOrder(startIndex) {
      const idx = this.queue.map((_, i) => i);
      if (this.shuffle) {
        // Tirage pondéré par la note, comme weightedPick() de capture.php
        // (poids = étoiles + 1) : un 5 étoiles revient six fois plus souvent
        // qu'un titre non noté. Clé aléatoire u^(1/poids), triée décroissante.
        const key = idx.map(i => Math.pow(Math.random(), 1 / ((Number(this.queue[i].rating) || 0) + 1)));
        idx.sort((a, b) => key[b] - key[a]);
        // Le morceau choisi passe en tête : la suite est aléatoire, pas lui.
        const pos = idx.indexOf(startIndex);
        if (pos > 0) { idx.splice(pos, 1); idx.unshift(startIndex); }
      }
      this.order = idx;
    },

    upcoming(count) {
      const pos = this.order.indexOf(this.index);
      const out = [];
      for (let k = 1; k <= count && pos + k < this.order.length; k++) out.push(this.queue[this.order[pos + k]]);
      return out;
    },

    load(index, opts) {
      const track = this.queue[index];
      if (!track) return;
      const wasPlaying = this.playing;
      const autoplay = !opts || opts.autoplay !== false;
      // Pas de fondu enchaîné depuis l'écran verrouillé, le centre de
      // contrôle ou un casque (voir systemAction), ni page cachée : iOS y
      // refuse de démarrer le second lecteur, et « suivant » restait muet.
      const fade = this.crossfade && wasPlaying && autoplay && this.track && !(opts && opts.noFade)
        && !systemAction && !document.hidden;
      this.index = index;
      const cached = cache.take(track.id);
      const url = cached || api.streamUrl(track.id);

      if (fade) {
        const outgoing = elements[active];
        active = 1 - active;
        const incoming = elements[active];
        const startAt = opts && opts.startAt;
        setSource(incoming, url, startAt);
        applyGain(incoming, track.gain_db);
        setVol(incoming, 0);
        incoming.play().then(() => {
          if (elements[active] !== incoming) return;
          ramp(incoming, this.volume / 100, CROSSFADE_MS);
          ramp(outgoing, 0, CROSSFADE_MS);
          setTimeout(() => { if (elements[active] !== outgoing) { outgoing.pause(); setVol(outgoing, this.volume / 100); } }, CROSSFADE_MS + 40);
        }).catch(() => {
          // Second lecteur refusé (iOS : jamais lancé d'un geste) : le
          // morceau suivant repart sur le lecteur en cours, sans fondu.
          if (elements[active] !== incoming) return;
          cache.owned.delete(incoming);
          incoming.removeAttribute('src'); incoming.load();
          active = 1 - active;
          setSource(outgoing, url, startAt);
          applyGain(outgoing, track.gain_db);
          setVol(outgoing, this.volume / 100);
          outgoing.play().catch(() => { this.playing = false; this.emit('state'); });
        });
      } else {
        elements[1 - active].pause();
        const el = elements[active];
        setSource(el, url, opts && opts.startAt);
        applyGain(el, track.gain_db);
        setVol(el, this.volume / 100);
        if (autoplay) el.play().catch(() => { this.playing = false; this.emit('state'); });
      }
      this.playing = autoplay;
      this.emit('track', track);
      this.emit('state');
      updateMediaSession(track);
      // Position de départ connue (reprise) : c'est elle qu'on enregistre, pas
      // le 0 d'un fichier dont les métadonnées ne sont pas encore chargées.
      resumeAt = (opts && opts.startAt) || 0;
      saveState(true, resumeAt);
      cache.plan(track, cached);
    },

    // opts.fade (ms) : le volume monte depuis le silence au lieu de démarrer
    // net — c'est la reprise « en douceur » du tap sur le titre en cours.
    play(opts) {
      if (!this.track) return;
      resumeContext();
      const el = this.el, fade = opts && opts.fade;
      if (fade && el.paused) setVol(el, 0);
      // Promesse : vrai si la lecture a démarré, faux si le navigateur l'a
      // refusée (lecture automatique bloquée sans geste de l'utilisateur).
      return el.play().then(() => {
        this.playing = true; this.emit('state'); saveState(true);
        if (fade) ramp(el, this.volume / 100, fade);
        return true;
      }).catch(() => false);
    },
    // opts.fade (ms) : fondu vers le silence, puis pause. Un play() pendant le
    // fondu le rattrape (l'élément n'a pas encore été mis en pause).
    pause(opts) {
      const el = this.el, fade = opts && opts.fade;
      this.playing = false; this.emit('state');
      if (fade && !el.paused) {
        ramp(el, 0, fade);
        setTimeout(() => { if (!this.playing && this.el === el) { el.pause(); setVol(el, this.volume / 100); saveState(true); } }, fade + 40);
        return;
      }
      el.pause(); saveState(true);
    },
    toggle() { this.playing ? this.pause() : this.play(); },

    next(auto) {
      const pos = this.order.indexOf(this.index);
      if (pos < 0) return;
      if (pos + 1 >= this.order.length) {
        if (auto) { this.playing = false; this.emit('state'); return; }
        this.load(this.order[0]);
        return;
      }
      this.load(this.order[pos + 1]);
    },
    prev() {
      // Comme partout : au-delà de 3 s, « précédent » revient au début.
      if (this.currentTime > 3) { this.seek(0); return; }
      const pos = this.order.indexOf(this.index);
      if (pos > 0) this.load(this.order[pos - 1], { noFade: true });
      else this.seek(0);
    },
    seek(t) { const el = this.el; if (Number.isFinite(t)) el.currentTime = clamp(t, 0, this.duration || t); this.emit('time'); },

    nextTrack() {
      const pos = this.order.indexOf(this.index);
      return pos >= 0 && pos + 1 < this.order.length ? this.queue[this.order[pos + 1]] : null;
    },

    setShuffle(v) { this.shuffle = v; prefs.setBool('ytdl_shuffle', v); this.rebuildOrder(this.index); this.emit('options'); },
    setAutoNext(v) { this.autoNext = v; prefs.setBool('ytdl_autonext', v); this.emit('options'); },
    setCrossfade(v) { this.crossfade = v; prefs.setBool('ytdl_crossfade', v); this.emit('options'); },
    setVolume(v) { this.volume = clamp(Math.round(v), 0, 100); prefs.set('ytdl_volume', this.volume); setVol(this.el, this.volume / 100); this.emit('options'); },
  });

  // Accesseurs posés après coup : Object.assign les aurait évalués une fois
  // pour toutes au lieu de les recopier.
  Object.defineProperties(player, {
    track: { get() { return this.queue[this.index] || null; } },
    el: { get() { return elements[active]; } },
    currentTime: { get() { return this.el.currentTime || 0; } },
    duration: { get() { const d = this.el.duration; return Number.isFinite(d) && d > 0 ? d : (this.track ? this.track.duration : 0); } },
  });

  // Deux éléments <audio> en alternance : l'un s'éteint pendant que l'autre
  // monte, c'est le fondu enchaîné.
  const elements = [new Audio(), new Audio()];
  let active = 0;
  elements.forEach((el, i) => {
    el.preload = 'auto';
    el.addEventListener('timeupdate', () => { if (i === active) { player.emit('time'); saveState(false); } });
    el.addEventListener('loadedmetadata', () => { if (i === active) player.emit('time'); });
    el.addEventListener('play', () => { if (i === active && !player.playing) { player.playing = true; player.emit('state'); } });
    el.addEventListener('pause', () => { if (i === active && player.playing && !el.ended && el.currentTime > 0 && !fadingOut.has(el)) { player.playing = false; player.emit('state'); } });
    el.addEventListener('ended', () => {
      if (i !== active) return;
      if (player.autoNext) player.next(true);
      else { player.playing = false; player.emit('state'); }
    });
    el.addEventListener('error', () => {
      if (i !== active || !el.src) return;
      if (cache.swap(el)) return;
      player.emit('error', "Lecture impossible : le fichier n'est plus disponible.");
    });
    // Réseau qui flanche (métro, ascenseur) : si le flux reste bloqué plus
    // de STALL_GRACE_MS et qu'une copie complète du morceau est déjà en
    // mémoire, on bascule dessus à la même position — même parade que
    // capture.php.
    let stall = 0;
    const arm = () => { if (i !== active || stall) return; stall = setTimeout(() => { stall = 0; if (i === active && el.readyState < 3) cache.swap(el); }, STALL_GRACE_MS); };
    const disarm = () => { clearTimeout(stall); stall = 0; };
    el.addEventListener('waiting', arm);
    el.addEventListener('stalled', arm);
    el.addEventListener('playing', disarm);
    el.addEventListener('canplay', disarm);
  });
  const fadingOut = new Set();

  function setSource(el, url, startAt) {
    cache.releaseFor(el);
    if (url.startsWith('blob:')) cache.owned.set(el, url);
    el.src = url;
    if (startAt > 0) {
      const once = () => { el.removeEventListener('loadedmetadata', once); try { el.currentTime = startAt; } catch (err) {} };
      el.addEventListener('loadedmetadata', once);
    }
  }

  // Copies en mémoire du morceau en cours et du suivant (même principe que
  // trackCaches dans capture.php) : le suivant démarre instantanément, et le
  // morceau en cours survit à une coupure réseau.
  // Vrai pendant une commande venue du système : écran verrouillé, centre de
  // contrôle, casque (voir updateMediaSession et load()).
  let systemAction = false;
  const STALL_GRACE_MS = 1200;
  const PREFETCH_DELAY_MS = 4000;   // laisse la copie du morceau courant passer devant
  const CACHE_MAX_BYTES = 80 * 1024 * 1024;
  const cache = {
    slots: { current: null, next: null },
    owned: new Map(),   // <audio> -> URL blob qu'il lit (libérée quand il change de source)
    timer: 0,
    fetch(slot, track) {
      const prev = this.slots[slot];
      if (prev && prev.id === track.id) return;
      this.release(slot);
      const entry = { id: track.id, ctl: new AbortController(), url: null };
      this.slots[slot] = entry;
      fetch(api.streamUrl(track.id), { signal: entry.ctl.signal, credentials: 'same-origin' })
        .then(r => r.ok ? r.blob() : Promise.reject(new Error(String(r.status))))
        .then(blob => {
          if (this.slots[slot] !== entry) return;
          if (blob.size > CACHE_MAX_BYTES) { this.slots[slot] = null; return; }
          entry.url = URL.createObjectURL(blob);
        })
        .catch(() => { if (this.slots[slot] === entry) this.slots[slot] = null; });
    },
    release(slot) {
      const e = this.slots[slot]; if (!e) return;
      e.ctl.abort();
      if (e.url) URL.revokeObjectURL(e.url);
      this.slots[slot] = null;
    },
    // URL blob du morceau s'il a été préchargé ; le slot est alors vidé (la
    // copie appartient désormais à l'élément <audio> qui la lit).
    take(id) {
      for (const slot of ['next', 'current']) {
        const e = this.slots[slot];
        if (e && e.id === id && e.url) { this.slots[slot] = null; return e.url; }
      }
      return null;
    },
    plan(track, alreadyLocal) {
      clearTimeout(this.timer);
      if (alreadyLocal) this.release('current'); else this.fetch('current', track);
      this.release('next');
      this.timer = setTimeout(() => { const n = player.nextTrack(); if (n && n.isAudio !== false) this.fetch('next', n); }, PREFETCH_DELAY_MS);
    },
    swap(el) {
      const track = player.track;
      const e = this.slots.current;
      if (!track || !e || e.id !== track.id || !e.url) return false;
      const pos = el.currentTime, wasPlaying = player.playing;
      this.slots.current = null;
      setSource(el, e.url, pos);
      if (wasPlaying) el.play().catch(() => {});
      return true;
    },
    releaseFor(el) {
      const url = this.owned.get(el);
      if (url) { URL.revokeObjectURL(url); this.owned.delete(el); }
    },
  };

  // Normalisation de volume (gain_db calculé côté serveur à partir des LUFS)
  // appliquée par Web Audio, comme dans capture.php. Sans Web Audio, le son
  // reste au niveau brut du fichier : jamais bloquant.
  let ctx = null;
  const nodes = new Map();
  function graph(el) {
    if (nodes.has(el)) return nodes.get(el);
    let n = null;
    try {
      const Ctx = window.AudioContext || window.webkitAudioContext;
      if (Ctx) {
        if (!ctx) ctx = new Ctx();
        const src = ctx.createMediaElementSource(el);
        const norm = ctx.createGain(), vol = ctx.createGain();
        src.connect(norm).connect(vol).connect(ctx.destination);
        vol.gain.value = el.volume; el.volume = 1;
        n = { norm, vol };
      }
    } catch (err) { n = null; }
    nodes.set(el, n);
    return n;
  }
  function resumeContext() { if (ctx && ctx.state === 'suspended') ctx.resume().catch(() => {}); }
  function applyGain(el, gainDb) {
    const n = graph(el); if (!n) return;
    resumeContext();
    const db = Number(gainDb);
    n.norm.gain.value = Number.isFinite(db) ? Math.pow(10, db / 20) : 1;
  }
  function setVol(el, v) { const n = graph(el); if (n) { n.vol.gain.cancelScheduledValues(0); n.vol.gain.value = v; } else el.volume = v; }
  function ramp(el, target, ms) {
    const n = graph(el);
    if (target === 0) { fadingOut.add(el); setTimeout(() => fadingOut.delete(el), ms + 100); }
    if (n && ctx) {
      const now = ctx.currentTime;
      n.vol.gain.cancelScheduledValues(now);
      n.vol.gain.setValueAtTime(n.vol.gain.value, now);
      n.vol.gain.linearRampToValueAtTime(target, now + ms / 1000);
      return;
    }
    const from = el.volume, start = performance.now();
    const step = (t) => { const k = Math.min(1, (t - start) / ms); el.volume = from + (target - from) * k; if (k < 1) requestAnimationFrame(step); };
    requestAnimationFrame(step);
  }

  // Écran verrouillé / casque Bluetooth / touches multimédia.
  const fromSystem = (fn) => (...args) => { systemAction = true; try { return fn(...args); } finally { systemAction = false; } };
  function updateMediaSession(track) {
    if (!('mediaSession' in navigator) || !track) return;
    try {
      navigator.mediaSession.metadata = new MediaMetadata({
        title: track.name, artist: track.artist, album: 'ytdl',
        artwork: track.thumbnail ? [{ src: track.thumbnail }] : [],
      });
      const ms = navigator.mediaSession;
      ms.setActionHandler('play', fromSystem(() => player.play()));
      ms.setActionHandler('pause', fromSystem(() => player.pause()));
      ms.setActionHandler('previoustrack', fromSystem(() => player.prev()));
      ms.setActionHandler('nexttrack', fromSystem(() => player.next()));
      ms.setActionHandler('seekto', (e) => player.seek(e.seekTime));
    } catch (err) {}
  }

  // Position de lecture : même table (playback-state.php) et même clé locale
  // que capture.php, pour reprendre là où on s'était arrêté, sur n'importe
  // quel appareil et dans n'importe quelle interface.
  let lastSave = 0;
  // Position de reprise pas encore appliquée (métadonnées pas chargées, ou
  // page quittée sans avoir rien écouté) : c'est elle qu'on réenregistre,
  // jamais le 0 provisoire du lecteur.
  let resumeAt = 0;
  elements.forEach(el => el.addEventListener('playing', () => { resumeAt = 0; }));
  function saveState(force, position) {
    const track = player.track;
    if (!(position > 0) && resumeAt > 0 && !player.playing && player.currentTime < 1) position = resumeAt;
    if (!track) return;
    const now = Date.now();
    if (!force && now - lastSave < 10000) return;
    lastSave = now;
    const body = JSON.stringify({
      history_id: track.id, position_seconds: Math.max(0, position > 0 ? position : player.currentTime),
      device_id: deviceId(), is_playing: player.playing, playlist_id: player.playlistId,
    });
    try { localStorage.setItem('ytdl_last_media_' + (BOOT.id || 0), body); } catch (err) {}
    fetch('playback-state.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body, keepalive: true }).catch(() => {});
  }
  window.addEventListener('pagehide', () => saveState(true));
  document.addEventListener('visibilitychange', () => { if (document.hidden) saveState(true); });

  async function lastPlayback() {
    try {
      const data = await api.get('playback-state.php');
      return data.state || null;
    } catch (err) { return null; }
  }

  // ------------------------------------------------------------------ paroles

  const lyricsCache = new Map();
  const lyrics = {
    key(track) { return (track.artist + '\u0000' + track.name).toLowerCase(); },
    async get(track) {
      const key = this.key(track);
      if (lyricsCache.has(key)) return lyricsCache.get(key);
      const params = new URLSearchParams({ artist: track.artist || '', title: track.name || '' });
      if (track.duration) params.set('duration', track.duration);
      const p = api.get('lyrics.php?' + params.toString())
        .then(d => ({ lyrics: d.lyrics || null, synced: d.synced || null, offset: Number(d.offset) || 0, videoOffset: Number(d.video_offset) || 0 }))
        .catch(() => ({ lyrics: null, synced: null, offset: 0, videoOffset: 0 }));
      lyricsCache.set(key, p);
      return p;
    },
    // Indice de la ligne chantée : temps de lecture moins le décalage
    // manuel, même convention que capture.php.
    lineAt(entry, time, offset) {
      const lines = entry && entry.synced;
      if (!lines || !lines.length) return -1;
      const t = time - (offset || 0);
      let lo = 0, hi = lines.length - 1, ans = -1;
      while (lo <= hi) { const mid = (lo + hi) >> 1; if (lines[mid].time <= t) { ans = mid; lo = mid + 1; } else hi = mid - 1; }
      return ans;
    },
    // Synchro faite à la main (lyrics-manual-sync.php) : partagée avec tous
    // les comptes, comme dans l'interface classique.
    async saveManualSync(track, lines) {
      const data = await api.json('lyrics-manual-sync.php', { artist: track.artist, title: track.name, duration: track.duration || null, lines });
      const entry = await this.get(track);
      entry.synced = data.lines;
      if (!entry.lyrics) entry.lyrics = lines.map(l => l.text).join('\n');
      return entry;
    },
    async setOffset(track, offset, variant) {
      const data = await api.json('lyrics-offset.php', { artist: track.artist, title: track.name, offset, variant: variant || 'audio' });
      const entry = await this.get(track);
      if (variant === 'video') entry.videoOffset = Number(data.offset); else entry.offset = Number(data.offset);
      return Number(data.offset);
    },
  };

  const bioCache = new Map();
  function artistInfo(track) {
    const artist = track.artist || '';
    if (!artist) return Promise.resolve(null);
    if (!bioCache.has(artist)) {
      bioCache.set(artist, api.get('artist-info.php?artist=' + encodeURIComponent(artist) + '&title=' + encodeURIComponent(track.name || ''))
        .catch(() => null));
    }
    return bioCache.get(artist);
  }

  // ---------------------------------------------------------- téléchargements

  // Pipeline identique à capture.php : start.php → progress.php (sondé) →
  // library-save.php. `onProgress` reçoit {percent, label}.
  async function download(url, opts, onProgress) {
    const params = new URLSearchParams({ url, audio_only: opts.audioOnly ? '1' : '0', device_id: deviceId() });
    if (opts.category) params.set('category', opts.category);
    if (opts.titlePrefix) params.set('title_prefix', opts.titlePrefix);
    if (opts.force) params.set('force', '1');
    const start = await api.get('start.php?' + params.toString());
    if (start.alreadyDownloaded) return { duplicate: true, title: start.title, reason: start.reason };
    const jobId = start.jobId;
    if (onProgress) onProgress({ percent: 0, label: 'Préparation…', title: start.title });
    for (;;) {
      await new Promise(r => setTimeout(r, 700));
      const p = await api.get('progress.php?job=' + encodeURIComponent(jobId));
      if (p.phase === 'error') throw new ApiError(p.error || 'Le téléchargement a échoué.', 500, p);
      if (onProgress) onProgress({ percent: p.percent, label: p.label, title: p.title || start.title });
      if (p.phase === 'done') break;
    }
    const saved = await api.get('library-save.php?job=' + encodeURIComponent(jobId));
    return { ok: true, title: saved.title, historyId: saved.history_id };
  }

  async function youtubeSearch(q, limit) {
    const data = await api.get('youtube-search.php?' + new URLSearchParams(limit ? { q, limit } : { q }));
    return data.results || [];
  }

  // Playlist publique Spotify / Deezer / YouTube Music / Apple Music →
  // {name, tracks: [{artist, title, youtube_url?}]}.
  async function resolvePlaylist(url) {
    return api.get('playlist-import-resolve.php?url=' + encodeURIComponent(url));
  }

  window.Ytdl = {
    boot: BOOT, api, ApiError, prefs, deviceId, splitArtistTitle, fmtTime, clamp, emitter,
    normalize: applyGain,
    library, account, sortTracks, filterTracks, player, lyrics, artistInfo, lastPlayback, download, youtubeSearch, resolvePlaylist,
  };
})();
