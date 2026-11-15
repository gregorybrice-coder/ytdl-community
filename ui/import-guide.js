/* Guide pédagogique commun aux interfaces ytdl. Aucun appel réseau ni import. */
(() => {
  'use strict';
  let dialog, timer, step = 0, platform = 'Spotify', playing = false, opener;
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  const steps = [
    ['Copie le lien de ta playlist', () => `Dans ${platform}, ouvre une playlist publique et utilise le menu de partage pour copier son lien. Le libellé dépend de ton appareil.`],
    ['Colle le lien dans ytdl', () => 'Ouvre « Importer une playlist », puis colle le lien dans le champ prévu. Utilise le lien complet de la playlist, pas celui d’un titre.'],
    ['Lance l’analyse', () => 'Clique sur « Analyser ». ytdl lit la liste des titres de la playlist. Si le lien échoue, tu peux utiliser un export CSV contenant les titres et les artistes.'],
    ['Vérifie, puis importe', () => 'Vérifie les titres et les artistes, ajuste le nom de la playlist si besoin, puis clique sur « Importer ».'],
    ['Suis l’import dans ytdl', () => 'ytdl recherche les titres sur YouTube et les ajoute à une playlist locale. Tu peux fermer la fenêtre d’import, mais garde l’onglet ytdl ouvert. Consulte le bilan pour repérer les échecs.']
  ];
  const demoTracks = '<div class="yg-track">Premier soleil <span>Artiste démo</span></div><div class="yg-track">La traversée <span>Artiste démo</span></div><div class="yg-track">Nuit tranquille <span>Artiste démo</span></div>';
  function stopTimer() { clearTimeout(timer); timer = null; }
  function schedule() {
    stopTimer();
    if (playing && dialog.open) timer = setTimeout(() => {
      if (step < steps.length - 1) { step++; render(); }
      else { playing = false; renderControls(); }
    }, 8500);
  }
  function renderControls() {
    dialog.classList.toggle('yg-paused', !playing);
    dialog.querySelector('[data-yg="play"]').textContent = playing ? 'Pause' : 'Lecture';
    dialog.querySelector('[data-yg="play"]').setAttribute('aria-pressed', String(playing));
  }
  function render() {
    const link = platform === 'Spotify' ? 'open.spotify.com/playlist/…' : 'www.deezer.com/playlist/…';
    const scenes = [
      `<div class="yg-service">${platform} · Playlist publique</div><div class="yg-row"><span class="yg-cover">♫</span><strong>Mes découvertes</strong></div><div class="yg-label">Menu de partage</div><span class="yg-action">Copier le lien de la playlist</span><span class="yg-cursor" aria-hidden="true">↖</span>`,
      `<div class="yg-service">ytdl · Importer une playlist</div><div class="yg-label">Lien de playlist publique</div><div class="yg-field"><span class="yg-typed">https://${link}</span></div><span class="yg-action">Analyser</span>`,
      `<div class="yg-service">ytdl · Analyser</div><div class="yg-field">https://${link}</div><span class="yg-action">Analyser</span><span class="yg-cursor" aria-hidden="true">↖</span><div class="yg-label">Lecture de la liste des titres…</div>`,
      `<div class="yg-service">ytdl · Nom de la playlist</div><div class="yg-field">Mes découvertes</div>${demoTracks}<span class="yg-action">Importer (3)</span><span class="yg-cursor" aria-hidden="true">↖</span>`,
      `<div class="yg-service">ytdl · Import en cours</div><strong>Mes découvertes</strong><div class="yg-progress"><span></span></div>${demoTracks}<div class="yg-success">Après l’import : retrouve ta playlist dans ytdl.</div>`
    ];
    dialog.querySelector('.yg-stage').innerHTML = `<span class="yg-demo">Démonstration fictive</span><div class="yg-window" aria-hidden="true">${scenes[step]}</div>`;
    dialog.querySelector('.yg-count').textContent = `Étape ${step + 1} sur ${steps.length}`;
    dialog.querySelector('h3').textContent = steps[step][0];
    dialog.querySelector('.yg-description').textContent = steps[step][1]();
    dialog.querySelector('[data-yg="prev"]').disabled = step === 0;
    dialog.querySelector('[data-yg="next"]').textContent = step === steps.length - 1 ? 'Rejouer ↻' : 'Suivant →';
    dialog.querySelectorAll('[data-yg-step]').forEach((button, i) => {
      if (i === step) button.setAttribute('aria-current', 'step'); else button.removeAttribute('aria-current');
    });
    dialog.querySelectorAll('[data-yg-platform]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.ygPlatform === platform)));
    dialog.classList.toggle('yg-static', !playing);
    renderControls(); schedule();
  }
  function create() {
    dialog = document.createElement('dialog'); dialog.className = 'ytdl-guide';
    dialog.setAttribute('aria-labelledby', 'yg-title');
    dialog.innerHTML = `<header class="yg-head"><div><div class="yg-kicker">Le guide ytdl</div><h2 id="yg-title">Tes playlists, pas à pas.</h2></div><button class="yg-close" type="button" data-yg="close" aria-label="Fermer le guide">✕</button></header><div class="yg-platforms" role="group" aria-label="Plateforme à expliquer"><button type="button" data-yg-platform="Spotify">Spotify</button><button type="button" data-yg-platform="Deezer">Deezer</button></div><div class="yg-stage"></div><div class="yg-copy" aria-live="polite" aria-atomic="true"><div class="yg-count"></div><h3></h3><p class="yg-description"></p></div><nav class="yg-nav" aria-label="Commandes du guide"><button type="button" data-yg="prev">← Précédent</button><button type="button" data-yg="play" aria-pressed="false">Pause</button><button class="yg-next" type="button" data-yg="next">Suivant →</button></nav><div class="yg-dots" role="group" aria-label="Choisir une étape">${steps.map((s, i) => `<button type="button" data-yg-step="${i}" aria-label="Étape ${i + 1} : ${s[0]}"></button>`).join('')}</div><p class="yg-note">Un lien public est nécessaire. ytdl récupère la liste des titres, puis cherche leurs versions sur YouTube : certaines correspondances peuvent différer ou échouer. Aucun import n’est lancé par ce guide.</p>`;
    document.body.append(dialog);
    dialog.addEventListener('click', e => {
      const button = e.target.closest('button'); if (!button) return;
      if (button.dataset.ygPlatform) { platform = button.dataset.ygPlatform; render(); return; }
      if (button.hasAttribute('data-yg-step')) { step = Number(button.dataset.ygStep); playing = false; render(); return; }
      switch (button.dataset.yg) {
        case 'close': dialog.close(); return;
        case 'prev': step = Math.max(0, step - 1); playing = false; break;
        case 'next': step = (step + 1) % steps.length; playing = false; break;
        case 'play': playing = !playing; dialog.classList.remove('yg-static'); renderControls(); schedule(); return;
      }
      render();
    });
    // Le dialogue parent de l’import ne doit pas recevoir Échap.
    dialog.addEventListener('keydown', e => { if (e.key === 'Escape') e.stopPropagation(); });
    dialog.addEventListener('close', () => { stopTimer(); playing = false; if (opener && opener.isConnected) opener.focus(); });
    reduced.addEventListener('change', () => { if (reduced.matches && dialog.open) { playing = false; stopTimer(); renderControls(); } });
  }
  document.addEventListener('click', e => {
    const trigger = e.target.closest('[data-playlist-guide]'); if (!trigger) return;
    e.preventDefault(); opener = trigger;
    if (!dialog) create();
    step = 0; playing = !reduced.matches;
    if (!dialog.open) dialog.showModal();
    render(); dialog.querySelector('.yg-close').focus();
  });
})();
