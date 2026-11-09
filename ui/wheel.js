/*
 * Molette radiale : une liste de titres disposée sur le bord d'un disque.
 *
 * Le composant ne sait rien de l'habillage : on lui donne une zone (élément
 * qui reçoit les gestes), une géométrie (centre, rayon, angle du « point de
 * lecture » où se trouve le titre en façade) et une fonction de rendu. Les
 * trois apparences s'en servent avec des réglages différents :
 *   Sillon  — demi-disque ancré au bord droit, façade à gauche (angle π)
 *   Orbite  — tiroir plein écran depuis le bord droit
 *   Platine — disque en bas de l'écran, façade en haut (angle −π/2)
 *
 * Seuls les titres visibles existent dans le DOM (une réserve d'une
 * vingtaine de boutons réutilisés), ce qui garde le défilement fluide même
 * avec plusieurs centaines de morceaux.
 *
 * Gestes : glisser (avec élan), molette de la souris quand le pointeur est
 * dans la zone, flèches du clavier quand la zone a le focus.
 */
(function () {
  'use strict';

  const POOL = 22;

  class RadialWheel {
    constructor(zone, opts) {
      this.zone = zone;
      this.opts = Object.assign({
        geometry: null,          // (w, h) => {cx, cy, r, front, step, dir, span, spanNext?, textWidth, orientation}
        render: null,            // (button, item, index) => void
        onPick: null,            // (index) => void — titre touché
        onFront: null,           // (index) => void — titre en façade a changé
        onSpin: null,            // (angle) => void — rotation visuelle du disque
        onInteract: null,        // () => void — l'utilisateur manipule le disque
        onLongPress: null,       // (index) => void — appui long sur un titre (ou clic droit)
      }, opts);
      this.items = [];
      this.scroll = 0;           // position flottante dans la liste (0 = premier titre en façade)
      this.vel = 0;
      this.raf = 0;
      this.target = null;
      this.front = -1;
      this.pool = [];
      this.layer = document.createElement('div');
      this.layer.className = 'wheel-items';
      zone.appendChild(this.layer);
      for (let i = 0; i < POOL; i++) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'wheel-item';
        b.tabIndex = -1;
        b.hidden = true;
        b.addEventListener('click', () => {
          if (this.suppressClick) return;
          const idx = Number(b.dataset.index);
          if (Number.isFinite(idx) && this.opts.onPick) this.opts.onPick(idx);
        });
        this.layer.appendChild(b);
        this.pool.push(b);
      }
      this.bind();
      this.resizeObserver = new ResizeObserver(() => this.measure());
      this.resizeObserver.observe(zone);
      this.measure();
    }

    measure() {
      const r = this.zone.getBoundingClientRect();
      this.w = r.width; this.h = r.height;
      this.g = this.opts.geometry(this.w, this.h);
      this.layout();
    }

    setItems(items, frontIndex) {
      this.items = items;
      cancelAnimationFrame(this.raf); this.raf = 0; this.vel = 0; this.target = null;
      this.scroll = Math.max(0, Math.min(items.length - 1, frontIndex || 0));
      this.front = -1;
      // Nouvelle liste : les boutons recyclés doivent tous être redessinés,
      // même ceux qui retombent sur le même indice.
      this.pool.forEach(b => { b.dataset.index = ''; });
      this.layout();
    }

    refresh() { this.layout(true); }

    // ----------------------------------------------------------------- rendu

    layout(forceRender) {
      const g = this.g; if (!g) return;
      const n = this.items.length;
      // spanNext : portée propre aux titres suivants (côté où la liste
      // arrive), pour qu'ils entrent par le bord au lieu d'apparaître en fondu.
      const spanNext = g.spanNext || g.span;
      const visible = Math.ceil(Math.max(g.span, spanNext) / g.step) + 1;
      const first = Math.max(0, Math.floor(this.scroll) - visible);
      const last = Math.min(n - 1, Math.ceil(this.scroll) + visible);
      let used = 0;
      for (let i = first; i <= last && used < POOL; i++) {
        const p = i - this.scroll;
        const th = g.front + p * g.step * (g.dir || 1);
        const lim = p > 0 ? spanNext : g.span;
        if (Math.abs(p * g.step) > lim) continue;
        const b = this.pool[used++];
        const x = g.cx + g.r * Math.cos(th), y = g.cy + g.r * Math.sin(th);
        const f = Math.max(0, 1 - Math.abs(p));
        const fade = Math.max(0, Math.min(1, (lim - Math.abs(p * g.step)) / (g.step * 1.6)));
        let rot;
        if (g.orientation === 'tangent') rot = th + Math.PI / 2;     // texte le long du bord
        else if (g.orientation === 'upright') rot = 0;                // texte toujours droit
        else rot = th - Math.PI;                                       // texte le long du rayon
        // Le point d'ancrage (bord gauche pour un texte rayonnant, centre
        // sinon) tombe exactement sur le cercle, et la rotation pivote autour.
        const anchor = g.orientation === 'radial' ? 'translate(0,-50%)' : 'translate(-50%,-50%)';
        b.style.transformOrigin = g.orientation === 'radial' ? '0 50%' : '50% 50%';
        b.style.transform = 'translate(' + x.toFixed(1) + 'px,' + y.toFixed(1) + 'px) ' + anchor +
          ' rotate(' + rot.toFixed(4) + 'rad) scale(' + (1 + (g.frontScale || 0.14) * f).toFixed(3) + ')';
        b.style.opacity = fade.toFixed(2);
        b.style.width = g.textWidth + 'px';
        b.classList.toggle('is-front', Math.abs(p) < 0.5);
        if (b.hidden) b.hidden = false;
        if (forceRender || b.dataset.index !== String(i)) {
          b.dataset.index = String(i);
          this.opts.render(b, this.items[i], i);
        }
      }
      for (let k = used; k < POOL; k++) if (!this.pool[k].hidden) { this.pool[k].hidden = true; this.pool[k].dataset.index = ''; }

      const front = n ? Math.max(0, Math.min(n - 1, Math.round(this.scroll))) : -1;
      if (front !== this.front) { this.front = front; if (this.opts.onFront) this.opts.onFront(front); }
      if (this.opts.onSpin) this.opts.onSpin(-this.scroll * g.step * (g.dir || 1));
    }

    // ------------------------------------------------------------ animation

    clampScroll(s, elastic) {
      const max = Math.max(0, this.items.length - 1);
      if (!elastic) return Math.max(0, Math.min(max, s));
      // Élastique aux extrémités : on peut tirer un peu, ça revient.
      if (s < 0) return s * 0.35;
      if (s > max) return max + (s - max) * 0.35;
      return s;
    }

    scrollTo(index, instant) {
      const target = Math.max(0, Math.min(this.items.length - 1, index));
      if (instant) { cancelAnimationFrame(this.raf); this.raf = 0; this.scroll = target; this.layout(); return; }
      this.animateTo(target);
    }

    animateTo(target) {
      cancelAnimationFrame(this.raf); this.vel = 0; this.target = target;
      let last = performance.now();
      const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      const step = (now) => {
        const dt = Math.min(32, now - last); last = now;
        this.scroll += (target - this.scroll) * (reduced ? 1 : 1 - Math.exp(-dt / 85));
        if (Math.abs(target - this.scroll) < 0.002) { this.scroll = target; this.raf = 0; this.target = null; this.layout(); return; }
        this.layout();
        this.raf = requestAnimationFrame(step);
      };
      this.raf = requestAnimationFrame(step);
    }

    coast() {
      let last = performance.now();
      const max = Math.max(0, this.items.length - 1);
      const step = (now) => {
        const dt = Math.min(32, now - last); last = now;
        const out = this.scroll < 0 || this.scroll > max;
        if (Math.abs(this.vel) > 0.0015 && !out) {
          this.vel *= Math.exp(-dt / 280);
          this.scroll += this.vel * dt;
          this.layout();
          this.raf = requestAnimationFrame(step);
        } else {
          this.raf = 0;
          this.animateTo(Math.max(0, Math.min(max, Math.round(this.scroll))));
        }
      };
      this.raf = requestAnimationFrame(step);
    }

    // ----------------------------------------------------------------- gestes

    point(e) {
      const r = this.zone.getBoundingClientRect();
      return { x: e.clientX - r.left, y: e.clientY - r.top };
    }
    angle(p) { return Math.atan2(p.y - this.g.cy, p.x - this.g.cx); }

    bind() {
      const z = this.zone;
      z.addEventListener('pointerdown', (e) => {
        if (e.button !== undefined && e.button !== 0) return;
        cancelAnimationFrame(this.raf); this.raf = 0; this.vel = 0;
        if (this.opts.onInteract) this.opts.onInteract();
        const p = this.point(e);
        this.drag = { a: this.angle(p), x: p.x, y: p.y, moved: false, t: performance.now(), id: e.pointerId };
        this.suppressClick = false;
        // Appui long sur un titre : son menu d'actions, sans le lancer.
        clearTimeout(this.pressTimer);
        const item = e.target.closest && e.target.closest('.wheel-item');
        if (item && this.opts.onLongPress && item.dataset.index !== '') {
          const idx = Number(item.dataset.index);
          this.pressTimer = setTimeout(() => {
            if (!this.drag || this.drag.moved) return;
            this.drag = null;
            this.suppressClick = true;
            setTimeout(() => { this.suppressClick = false; }, 600);
            if (navigator.vibrate) navigator.vibrate(12);
            this.opts.onLongPress(idx);
          }, 550);
        }
      });
      z.addEventListener('pointermove', (e) => {
        const d = this.drag; if (!d || e.pointerId !== d.id) return;
        const p = this.point(e);
        if (!d.moved) {
          if (Math.hypot(p.x - d.x, p.y - d.y) < 8) return;
          d.moved = true;
          clearTimeout(this.pressTimer);
          try { z.setPointerCapture(d.id); } catch (err) {}
          z.classList.add('is-dragging');
        }
        const a = this.angle(p);
        let da = a - d.a;
        if (da > Math.PI) da -= 2 * Math.PI;
        if (da < -Math.PI) da += 2 * Math.PI;
        d.a = a;
        const ds = -da / (this.g.step * (this.g.dir || 1));
        const now = performance.now(), dt = Math.max(1, now - d.t); d.t = now;
        this.vel = 0.6 * this.vel + 0.4 * (ds / dt);
        this.scroll = this.clampScroll(this.scroll + ds, true);
        this.layout();
      });
      const end = (e) => {
        clearTimeout(this.pressTimer);
        const d = this.drag; if (!d || (e && e.pointerId !== d.id)) return;
        this.drag = null;
        z.classList.remove('is-dragging');
        if (!d.moved) return;
        // Le clic qui suit un glisser ne doit pas lancer le titre survolé.
        this.suppressClick = true;
        setTimeout(() => { this.suppressClick = false; }, 80);
        this.coast();
      };
      z.addEventListener('pointerup', end);
      z.addEventListener('pointercancel', end);
      // Clic droit (PC) = appui long ; le menu du navigateur n'a rien à faire ici.
      z.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        const item = e.target.closest && e.target.closest('.wheel-item');
        if (item && this.opts.onLongPress && item.dataset.index !== '') this.opts.onLongPress(Number(item.dataset.index));
      });

      // Molette : un cran = un titre. Les pavés tactiles envoient des rafales
      // de petits deltas, d'où l'accumulation avant de compter un cran.
      let acc = 0, lastWheel = 0;
      z.addEventListener('wheel', (e) => {
        e.preventDefault();
        if (this.opts.onInteract) this.opts.onInteract();
        const now = performance.now();
        if (now - lastWheel > 250) acc = 0;
        lastWheel = now;
        acc += e.deltaMode === 1 ? e.deltaY * 33 : e.deltaY;
        const notches = Math.trunc(acc / 60);
        if (!notches) return;
        acc -= notches * 60;
        const base = this.target !== null ? this.target : Math.round(this.scroll);
        this.animateTo(Math.max(0, Math.min(this.items.length - 1, base + notches)));
      }, { passive: false });

      z.addEventListener('keydown', (e) => {
        if (this.opts.onInteract) this.opts.onInteract();
        const base = this.target !== null ? this.target : Math.round(this.scroll);
        const moves = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1, PageDown: 8, PageUp: -8 };
        if (e.key in moves) { e.preventDefault(); this.animateTo(Math.max(0, Math.min(this.items.length - 1, base + moves[e.key]))); }
        else if (e.key === 'Home') { e.preventDefault(); this.animateTo(0); }
        else if (e.key === 'End') { e.preventDefault(); this.animateTo(this.items.length - 1); }
        else if ((e.key === 'Enter' || e.key === ' ') && this.front >= 0 && e.target === z) { e.preventDefault(); if (this.opts.onPick) this.opts.onPick(this.front); }
      });
    }
  }

  window.RadialWheel = RadialWheel;
})();
