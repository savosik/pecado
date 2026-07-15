self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(clients.claim()));
// Намеренно НЕ вызываем event.respondWith(): пустой fetch-обработчик
// сохраняет installability PWA, но не перехватывает запросы — браузер
// обрабатывает их нативно. Прежний сквозной `fetch(event.request)` при
// медленном ответе (напр. /admin/erp-bus ждёт RabbitMQ до 5с) реджектился
// и обрывал Inertia-навигацию ошибкой «Failed to fetch».
self.addEventListener('fetch', () => {});
