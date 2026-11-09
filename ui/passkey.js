/*
 * Connexion par empreinte / visage / code de l'appareil (WebAuthn).
 *
 * Partagé par l'écran de connexion (index.php), l'interface classique
 * (capture.php) et les nouvelles interfaces (ui/app.js). Côté serveur :
 * webauthn.php. La biométrie reste dans le téléphone ; seule une signature
 * transite.
 *
 * Cet appareil retient en local (localStorage « ytdl_passkey ») la clé
 * qu'il a créée et le compte associé, pour proposer directement la bonne
 * empreinte sur l'écran de connexion.
 */
(function () {
  'use strict';

  const KEY = 'ytdl_passkey';
  const OFFER_KEY = 'ytdl_passkey_offer';

  const b64urlToBuf = (s) => {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    const bin = atob(s + '='.repeat((4 - s.length % 4) % 4));
    return Uint8Array.from(bin, c => c.charCodeAt(0)).buffer;
  };
  const bufToB64url = (buf) => {
    const bytes = new Uint8Array(buf);
    let bin = '';
    for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  };

  async function call(action, body) {
    const res = await fetch('webauthn.php?action=' + action + (body && body.query ? '&' + body.query : ''), body && body.data ? {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body.data),
    } : { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    let data = null;
    try { data = await res.json(); } catch (err) {}
    if (!res.ok || (data && data.error)) {
      const e = new Error((data && data.error) || 'Erreur ' + res.status);
      e.status = res.status;
      throw e;
    }
    return data;
  }

  function readLocal() {
    try { return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (err) { return null; }
  }
  function writeLocal(v) {
    try { if (v) localStorage.setItem(KEY, JSON.stringify(v)); else localStorage.removeItem(KEY); } catch (err) {}
  }

  // Message compréhensible pour les refus du navigateur.
  function explain(err) {
    if (!err) return 'Échec.';
    if (err.name === 'NotAllowedError') return 'Opération annulée ou délai dépassé.';
    if (err.name === 'InvalidStateError') return 'Cet appareil a déjà une empreinte enregistrée pour ce compte.';
    if (err.name === 'SecurityError') return "Connexion sécurisée (https) requise pour l'empreinte.";
    return err.message || String(err);
  }

  const Passkey = {
    explain,
    local: readLocal,

    // Capteur biométrique (ou code de l'appareil) utilisable par le site ?
    async available() {
      try {
        return !!(window.isSecureContext && window.PublicKeyCredential
          && PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable
          && await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable());
      } catch (err) { return false; }
    },

    // Compte connecté : crée la clé de cet appareil (le téléphone demande
    // l'empreinte) et l'enregistre côté serveur.
    async register(user) {
      const opts = await call('register-options');
      const pk = opts.publicKey;
      pk.challenge = b64urlToBuf(pk.challenge);
      pk.user.id = b64urlToBuf(pk.user.id);
      (pk.excludeCredentials || []).forEach(c => { c.id = b64urlToBuf(c.id); });
      const cred = await navigator.credentials.create({ publicKey: pk });
      const res = await call('register', { data: {
        clientDataJSON: bufToB64url(cred.response.clientDataJSON),
        attestationObject: bufToB64url(cred.response.attestationObject),
      } });
      writeLocal({ credentialId: res.credential_id, userId: user.id, name: user.name, avatar: user.avatar || '' });
      try { localStorage.removeItem(OFFER_KEY); } catch (err) {}
      return res;
    },

    // Écran de connexion : demande l'empreinte et ouvre la session.
    async login() {
      const local = readLocal();
      const opts = await call('login-options', { query: local ? 'credential=' + encodeURIComponent(local.credentialId) : '' });
      const pk = opts.publicKey;
      pk.challenge = b64urlToBuf(pk.challenge);
      (pk.allowCredentials || []).forEach(c => { c.id = b64urlToBuf(c.id); });
      const cred = await navigator.credentials.get({ publicKey: pk });
      try {
        return await call('login', { data: {
          id: bufToB64url(cred.rawId),
          clientDataJSON: bufToB64url(cred.response.clientDataJSON),
          authenticatorData: bufToB64url(cred.response.authenticatorData),
          signature: bufToB64url(cred.response.signature),
          userHandle: cred.response.userHandle ? bufToB64url(cred.response.userHandle) : null,
        } });
      } catch (err) {
        // Clé retirée côté serveur : on oublie aussi l'association locale.
        if (err.status === 404) writeLocal(null);
        throw err;
      }
    },

    // Retire la clé de cet appareil (serveur + mémoire locale).
    async disable() {
      const local = readLocal();
      if (local) await call('delete', { data: { credential_id: local.credentialId } }).catch(() => {});
      writeLocal(null);
    },

    // Après une connexion : propose une fois d'activer l'empreinte (si
    // l'appareil sait le faire et que ce n'est pas déjà fait). « Plus tard »
    // la repropose dans 30 jours, « Non merci » plus jamais sur cet appareil.
    async offer(user, onToast) {
      if (!user || !user.id) return;
      const local = readLocal();
      if (local && Number(local.userId) === Number(user.id)) return;
      let pref = null;
      try { pref = JSON.parse(localStorage.getItem(OFFER_KEY) || 'null'); } catch (err) {}
      if (pref && (pref.never || Date.now() < pref.until)) return;
      if (!(await this.available())) return;
      showOffer(user, onToast);
    },
  };

  function showOffer(user, onToast) {
    const box = document.createElement('div');
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-label', "Connexion par empreinte");
    box.style.cssText = 'position:fixed;left:50%;bottom:max(16px,env(safe-area-inset-bottom));transform:translateX(-50%);z-index:9999;'
      + 'width:min(92vw,440px);box-sizing:border-box;padding:18px 18px 14px;border-radius:20px;'
      + 'background:var(--panel,#1b1b1b);color:var(--text,#f3eee6);border:1px solid var(--line,rgba(255,255,255,.12));'
      + 'box-shadow:0 18px 60px rgba(0,0,0,.45);font:15px/1.45 var(--font-body,system-ui,sans-serif);';
    box.innerHTML = '<div style="display:flex;gap:14px;align-items:flex-start">'
      + '<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" style="flex:none;color:var(--accent,#ff8a1f)">'
      + '<path d="M12 11v3M8.5 8.5a5 5 0 0 1 8.5 3.5v1M7 12c0-1 .2-2 .7-2.8M17 16.5c-.3 1.5-.9 2.8-1.7 3.9M12 7a5 5 0 0 0-5 5v2c0 2 .5 3.8 1.5 5.3M15 14v.5c0 2-.4 3.8-1.2 5.3M10 14v1c0 1.6-.3 3.1-.9 4.4"/>'
      + '<path d="M4.6 9.2A8 8 0 0 1 19.4 9M3.5 13.5V12M20.5 12.5v.8"/></svg>'
      + '<div style="flex:1;min-width:0"><b style="display:block;font-size:16px;margin-bottom:2px">Te connecter plus vite ?</b>'
      + '<span style="opacity:.75">Active l’empreinte (ou le visage) de cet appareil : la prochaine fois, un simple toucher suffira, sans lien par e-mail.</span></div></div>'
      + '<div data-msg style="margin-top:8px;font-size:13px;opacity:.85"></div>'
      + '<div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;margin-top:10px">'
      + '<button type="button" data-act="never" style="height:40px;padding:0 14px;border-radius:20px;border:0;background:none;color:inherit;opacity:.7;font:inherit;cursor:pointer">Non merci</button>'
      + '<button type="button" data-act="later" style="height:40px;padding:0 14px;border-radius:20px;border:1px solid var(--line,rgba(255,255,255,.18));background:none;color:inherit;font:inherit;cursor:pointer">Plus tard</button>'
      + '<button type="button" data-act="yes" style="height:40px;padding:0 18px;border-radius:20px;border:0;background:var(--accent,#ff8a1f);color:var(--on-accent,#1a0f04);font:inherit;font-weight:700;cursor:pointer">Activer</button>'
      + '</div>';
    document.body.appendChild(box);
    const msg = box.querySelector('[data-msg]');
    const close = () => box.remove();
    box.querySelector('[data-act="never"]').onclick = () => {
      try { localStorage.setItem(OFFER_KEY, JSON.stringify({ never: true })); } catch (err) {}
      close();
    };
    box.querySelector('[data-act="later"]').onclick = () => {
      try { localStorage.setItem(OFFER_KEY, JSON.stringify({ until: Date.now() + 30 * 86400000 })); } catch (err) {}
      close();
    };
    const yes = box.querySelector('[data-act="yes"]');
    yes.onclick = async () => {
      yes.disabled = true; msg.textContent = 'Pose ton doigt sur le capteur…';
      try {
        await Passkey.register(user);
        close();
        if (onToast) onToast('Empreinte activée : elle te servira à te connecter sur cet appareil.');
      } catch (err) {
        msg.textContent = explain(err);
        yes.disabled = false;
      }
    };
  }

  window.YtdlPasskey = Passkey;
})();
