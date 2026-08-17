self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(clients.claim()));
// Намеренно НЕ вызываем event.respondWith(): пустой fetch-обработчик
// сохраняет installability PWA, но не перехватывает запросы — браузер
// обрабатывает их нативно. Прежний сквозной `fetch(event.request)` при
// медленном ответе (напр. /admin/erp-bus ждёт RabbitMQ до 5с) реджектился
// и обрывал Inertia-навигацию ошибкой «Failed to fetch».
self.addEventListener('fetch', () => {});

// Web Push по задачам CRM (task-09): payload шлёт бэкенд
// (laravel-notification-channels/webpush) — {title, body, data: {url}, tag}.
self.addEventListener('push', (event) => {
    if (!event.data) {
        return;
    }

    let payload = {};
    try {
        payload = event.data.json();
    } catch {
        payload = { title: 'Pecado CRM', body: event.data.text() };
    }

    event.waitUntil(self.registration.showNotification(payload.title || 'Pecado CRM', {
        body: payload.body || '',
        icon: payload.icon || '/favicon.ico',
        // tag схлопывает повторы по одной задаче в одно уведомление.
        tag: payload.tag || undefined,
        data: payload.data || {},
    }));
});

// Клик: фокусируем открытую вкладку CRM или открываем карточку задачи.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = event.notification.data && event.notification.data.url
        ? event.notification.data.url
        : '/crm/tasks';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            const existing = windows.find((win) => win.url.includes('/crm'));

            if (existing) {
                existing.focus();

                return existing.navigate(url);
            }

            return clients.openWindow(url);
        }),
    );
});
