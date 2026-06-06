self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(clients.claim()));
self.addEventListener('fetch', (event) => {
    if (event.request.method === 'GET') {
        event.respondWith(fetch(event.request));
    }
});
