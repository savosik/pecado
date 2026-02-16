import axios from 'axios';
import { toastError } from '@/utils/toast';

window.axios = axios;

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
            const target = event.target.closest('a');
            if (!target) return;
            const href = target.getAttribute('href') || '';
            const url = new URL(href, window.location.origin);
            if (url.origin !== window.location.origin) return;
            const match = url.pathname.match(/^\/products\/([^/]+)$/);
            if (!match) return;

            // На странице товара — обычная навигация
            if (window.location.pathname.match(/^\/products\/([^/]+)$/)) return;

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
            const slug = decodeURIComponent(match[1]);
            if (window.__prefetchProductQuickView) {
                window.__prefetchProductQuickView(slug);
            }
        } catch (_) { /* noop */ }
    }, true);
}
