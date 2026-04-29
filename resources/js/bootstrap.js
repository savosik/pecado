import axios from 'axios';
import { router } from '@inertiajs/react';
import { toastError } from '@/utils/toast';

window.axios = axios;

// Подавляем встроенный Inertia error-iframe — если сервер всё-таки вернёт
// не-Inertia ответ (nginx 502, прокси-сбой), показываем тост вместо
// модалки с сырым HTML/JSON. Нормальные 404/500/503 рендерятся как
// Inertia ErrorPage через bootstrap/app.php.
router.on('invalid', (event) => {
    event.preventDefault();
    toastError('Не удалось открыть страницу', 'Попробуйте ещё раз через минуту.');
});

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

window.axios.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error?.response?.status;

        if (status === 401) {
            window.location.href = '/login';
        } else if (status === 403) {
            toastError('Доступ запрещён', 'У вас нет прав для выполнения этого действия');
        } else if (status >= 500) {
            toastError('Ошибка сервера', 'Что-то пошло не так. Попробуйте позже');
        }

        return Promise.reject(error);
    }
);

// ─── QuickView: глобальный перехват кликов по ссылкам товаров ───
if (typeof document !== 'undefined') {
    document.addEventListener('click', (event) => {
        try {
            // Если клик по кнопке внутри ссылки (стрелки галереи, точки) — не перехватываем
            const clickedButton = event.target.closest('button, [role="button"]');
            const target = event.target.closest('a');
            if (!target) return;
            if (clickedButton && target.contains(clickedButton)) return;

            const href = target.getAttribute('href') || '';
            const url = new URL(href, window.location.origin);
            if (url.origin !== window.location.origin) return;
            const match = url.pathname.match(/^\/products\/([^/]+)$/);
            if (!match) return;

            // Служебные разделы каталога — не перехватываем (обычная навигация)
            const CATALOG_SECTIONS = ['novinki', 'bestsellery', 'favorites'];
            if (CATALOG_SECTIONS.includes(match[1])) return;

            // На детальной странице товара — обычная навигация (флаг ставит Pages/User/Products/Show.jsx)
            if (window.__isProductDetailPage) return;

            // Модификаторы и средняя кнопка — не перехватываем
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

            // Сохраняем позицию клика для анимации
            if (typeof event.clientX === 'number' && typeof event.clientY === 'number') {
                window.__lastClickPosition = { x: event.clientX, y: event.clientY };
            }

            event.preventDefault();
            const slug = decodeURIComponent(match[1]);
            if (window.__openProductQuickView) {
                window.__openProductQuickView(slug);
            } else {
                window.location.href = href;
            }
        } catch (_) { /* noop */ }
    }, true);

    // Prefetch при наведении
    document.addEventListener('mouseover', (event) => {
        try {
            const target = event.target.closest('a');
            if (!target) return;
            const href = target.getAttribute('href') || '';
            const url = new URL(href, window.location.origin);
            if (url.origin !== window.location.origin) return;
            const match = url.pathname.match(/^\/products\/([^/]+)$/);
            if (!match) return;
            // Служебные разделы каталога — не делаем prefetch
            const CATALOG_SECTIONS = ['novinki', 'bestsellery', 'favorites'];
            if (CATALOG_SECTIONS.includes(match[1])) return;
            const slug = decodeURIComponent(match[1]);
            if (window.__prefetchProductQuickView) {
                window.__prefetchProductQuickView(slug);
            }
        } catch (_) { /* noop */ }
    }, true);
}
