import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Задержка перед пересчётом промо. Заведомо больше debounce количеств в
 * useCartStore (800 мс): сначала уходит POST с количеством, потом один
 * пересчёт на всю серию нажатий. Иначе long-press на «+» даёт десятки запросов.
 */
const REFRESH_DELAY_MS = 900;

const EMPTY_PROGRESS = { near_miss: [], achieved: [], max_visible: 3 };

/**
 * Промо по корзине: прогресс («доберите на X») и сами промо-строки.
 *
 * Обе части считает движок по серверной корзине, поэтому обновляются они
 * вместе, одним дебаунсом на серию изменений. Раньше строки приходили только
 * в Inertia-пропсах страницы — при работе через store пропсы не обновляются,
 * и блок «Промо-позиции» устаревал до перезагрузки.
 *
 * Держит предыдущие данные, пока летит новый запрос: мигающий блок над
 * корзиной раздражает сильнее, чем слегка устаревшее число, — поэтому
 * наружу отдаётся `loading` для приглушения, а не скелетон.
 *
 * @param {number|null} cartId — корзина, по которой считаем (страница корзины
 *   может показывать не активную корзину)
 * @param {Array} initialItems — промо-строки из пропсов страницы: показываем их
 *   сразу, не дожидаясь первого запроса
 */
export default function useCartPromotions(cartId, initialItems = []) {
    const [promotions, setPromotions] = useState(EMPTY_PROGRESS);
    const [promoItems, setPromoItems] = useState(initialItems);
    const [loading, setLoading] = useState(false);

    const timerRef = useRef(null);
    // Гонка: ответ на устаревший запрос не должен перетирать свежий
    const requestIdRef = useRef(0);

    const fetchNow = useCallback(async () => {
        if (!cartId) return;

        const requestId = ++requestIdRef.current;
        setLoading(true);

        // Оба запроса параллельно: это один логический пересчёт, и разъезд
        // между «доберите на X» и списком позиций читался бы как ошибка
        const [progress, items] = await Promise.allSettled([
            window.axios.get('/api/cart/promotions', { params: { cart_id: cartId } }),
            window.axios.get('/api/cart/promo-items', { params: { cart_id: cartId } }),
        ]);

        if (requestId !== requestIdRef.current) return;

        // Промо — вспомогательный блок: на ошибке молча оставляем прежние данные
        if (progress.status === 'fulfilled') {
            setPromotions(progress.value?.data || EMPTY_PROGRESS);
        }

        if (items.status === 'fulfilled') {
            setPromoItems(items.value?.data?.promo_items ?? []);
        }

        setLoading(false);
    }, [cartId]);

    const schedule = useCallback(() => {
        if (timerRef.current) clearTimeout(timerRef.current);
        timerRef.current = setTimeout(() => {
            timerRef.current = null;
            fetchNow();
        }, REFRESH_DELAY_MS);
    }, [fetchNow]);

    // Первая загрузка и смена корзины (переключение активной корзины
    // перезагружает страницу с другим cart.id)
    useEffect(() => {
        setPromotions(EMPTY_PROGRESS);
        fetchNow();
    }, [fetchNow]);

    // Любое изменение корзины — количество, удаление, очистка, импорт —
    // проходит через cart:changed, поэтому отдельных подписок не нужно
    useEffect(() => {
        window.addEventListener('cart:changed', schedule);

        return () => {
            window.removeEventListener('cart:changed', schedule);
            if (timerRef.current) clearTimeout(timerRef.current);
        };
    }, [schedule]);

    return { promotions, promoItems, setPromoItems, loading, refresh: fetchNow };
}
