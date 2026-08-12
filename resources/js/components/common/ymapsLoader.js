const YMAPS_VERSION = '2.1';

let loaderPromise = null;

/**
 * Одноразовая загрузка SDK Яндекс.Карт.
 *
 * Промис кэшируется на модуль: на странице может быть несколько карт, а второй
 * тег <script> с тем же SDK ломает уже инициализированные экземпляры.
 */
export function loadYmaps(apiKey) {
    if (typeof window === 'undefined') {
        return Promise.reject(new Error('SSR'));
    }
    if (window.ymaps && window.ymaps.Map) {
        return Promise.resolve(window.ymaps);
    }
    if (loaderPromise) return loaderPromise;

    loaderPromise = new Promise((resolve, reject) => {
        const existing = document.querySelector('script[data-ymaps]');
        const onReady = () => {
            if (!window.ymaps) {
                reject(new Error('ymaps SDK не доступен после загрузки'));
                return;
            }
            window.ymaps.ready(() => resolve(window.ymaps));
        };
        if (existing) {
            existing.addEventListener('load', onReady);
            existing.addEventListener('error', () => reject(new Error('Не удалось загрузить SDK Яндекс.Карт')));
            return;
        }
        const script = document.createElement('script');
        script.src = `https://api-maps.yandex.ru/${YMAPS_VERSION}/?apikey=${encodeURIComponent(apiKey)}&lang=ru_RU`;
        script.async = true;
        script.dataset.ymaps = '1';
        script.addEventListener('load', onReady);
        script.addEventListener('error', () => reject(new Error('Не удалось загрузить SDK Яндекс.Карт')));
        document.head.appendChild(script);
    }).catch((err) => {
        loaderPromise = null;
        throw err;
    });

    return loaderPromise;
}
