// Service worker minimal, sans mise en cache : sa seule utilité est de satisfaire
// le critère d'installabilité de Chrome/Android (icône sur l'écran d'accueil).
// L'app est dynamique et protégée par session — la mettre en cache créerait plus
// de problèmes (contenu périmé, fuite de données entre utilisateurs du même
// appareil) qu'elle n'en résoudrait. Chaque requête part donc simplement au réseau.
self.addEventListener('install', function (event) {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function (event) {
    event.respondWith(fetch(event.request));
});
