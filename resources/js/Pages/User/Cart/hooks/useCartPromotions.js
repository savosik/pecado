import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Задержка перед пересчётом промо. Заведомо больше debounce количеств в
 * useCartStore (800 мс): сначала уходит POST с количеством, потом один
 * пересчёт на всю серию нажатий. Иначе long-press на «+» даёт десятки запросов.
 */
const REFRESH_DELAY_MS = 900;

const EMPTY = { near_miss: [], achieved: [], max_visible: 3 };

/**
 * Прогресс акций по корзине.
 *
 * Держит предыдущие данные, пока летит новый запрос: мигающий блок над
 * корзиной раздражает сильнее, чем слегка устаревшее число, — поэтому
 * наружу отдаётся `loading` для приглушения, а не скелетон.
 *
 * @param {number|null} cartId — корзина, по которой считаем (страница корзины
 *   может показывать не активную корзину)
 */
export default function useCartPromotions(cartId) {
    const [data, setData] = useState(EMPTY);
    const [loading, setLoading] = useState(false);

    const timerRef = useRef(null);
    // Гонка: ответ на устаревший запрос не должен перетирать свежий
    const requestIdRef = useRef(0);

    const fetchNow = useCallback(async () => {
        if (!cartId) return;

        const requestId = ++requestIdRef.current;
        setLoading(true);

        try {
            const { data: payload } = await window.axios.get('/api/cart/promotions', {
                params: { cart_id: cartId },
            });

            if (requestId === requestIdRef.current) {
                setData(payload || EMPTY);
            }
        } catch {
            // Промо — вспомогательный блок: молча оставляем прежние данные
        } finally {
            if (requestId === requestIdRef.current) {
                setLoading(false);
            }
        }
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
        setData(EMPTY);
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

    return { promotions: data, loading, refresh: fetchNow };
}
