// Service worker minimal — requis par Chrome/Android pour l'installabilité de
// la PWA et le partage natif (Web Share Target). Ne fait pas de cache hors
// ligne : l'appli a de toute façon besoin du serveur pour télécharger.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {}); // no-op : laisse passer toutes les requêtes réseau

// Notifications push (partages reçus). Le payload est le JSON envoyé par
// ytdl_send_push() côté serveur : { title, body, url }.
self.addEventListener('push', (event) => {
  let data = { title: 'ytdl', body: 'Nouvelle notification.' };
  try {
    if (event.data) data = Object.assign(data, event.data.json());
  } catch (err) { /* payload non-JSON, on garde le texte par défaut */ }

  event.waitUntil(
    self.registration.showNotification(data.title, {
      body: data.body,
      icon: '/ytdl/icon-192.png',
      badge: '/ytdl/icon-192.png',
      data: { url: data.url || '/ytdl/capture.php' },
    })
  );
});

// Clic sur la notification : focus un onglet déjà ouvert sur l'app plutôt
// que d'en ouvrir un nouveau, si possible.
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = event.notification.data && event.notification.data.url
    ? event.notification.data.url
    : '/ytdl/capture.php';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientsArr) => {
      // Sillon (sillon.php) compte aussi comme l'app déjà ouverte.
      const existing = clientsArr.find((c) => c.url.includes('/ytdl/capture.php') || c.url.includes('/ytdl/sillon.php'));
      if (existing) return existing.focus();
      return self.clients.openWindow(targetUrl);
    })
  );
});
