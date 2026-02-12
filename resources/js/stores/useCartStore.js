/**
 * Zustand-стор для управления корзиной.
 *
 * Использование:
 *   import { useCartStore } from '@/stores/useCartStore';
 *
 *   const { init, getQuantity, getTotalQuantity, setQuantity, clear } = useCartStore();
 *   await init(); // вызвать один раз при монтировании (проверяет auth)
 */

import { create } from 'zustand';

/** Таймеры debounce для каждого product_id */
const debounceTimers = {};
const DEBOUNCE_MS = 500;

export const useCartStore = create((set, get) => ({
    /**
     * Элементы корзины: { [productId]: { cartItemId, quantity } }
     * @type {Object<number, { cartItemId: number, quantity: number }>}
     */
    items: {},

    /** Флаг, что данные загружены */
    loaded: false,

    /** Флаг загрузки */
    loading: false,

    /**
     * Инициализация корзины — загрузка текущего состояния с сервера.
     * Не делает ничего для гостей и если уже загружено.
     *
     * @param {object|null} user — объект auth.user из Inertia props (null для гостей)
     */
    init: async (user) => {
        if (!user || get().loaded || get().loading) return;

        set({ loading: true });

        try {
            const { data } = await window.axios.get('/api/cart/summary');
            const items = {};

            if (data?.items && Array.isArray(data.items)) {
                data.items.forEach((item) => {
                    items[item.product_id] = {
                        cartItemId: item.id,
                        quantity: item.quantity,
                    };
                });
            }

            set({ items, loaded: true, loading: false });
        } catch {
            set({ loading: false });
        }
    },

    /**
     * Получить количество конкретного товара в корзине.
     * @param {number} productId
     * @returns {number}
     */
    getQuantity: (productId) => {
        const item = get().items[Number(productId)];
        return item ? item.quantity : 0;
    },

    /**
     * Общее количество товаров в корзине.
     * @returns {number}
     */
    getTotalQuantity: () => {
        return Object.values(get().items).reduce(
            (sum, item) => sum + item.quantity,
            0
        );
    },

    /**
     * Общее количество позиций (уникальных товаров) в корзине.
     * @returns {number}
     */
    getTotalItems: () => {
        return Object.keys(get().items).length;
    },

    /**
     * Установить количество товара в корзине с debounced API-синхронизацией.
     * Если qty = 0, товар удаляется из корзины.
     * @param {number} productId
     * @param {number} qty
     */
    setQuantity: (productId, qty) => {
        const pid = Number(productId);
        const quantity = Math.max(0, Math.floor(qty));

        // Optimistic update
        set((state) => {
            const next = { ...state.items };

            if (quantity <= 0) {
                delete next[pid];
            } else {
                next[pid] = {
                    cartItemId: state.items[pid]?.cartItemId || null,
                    quantity,
                };
            }

            return { items: next };
        });

        // Debounced API sync
        if (debounceTimers[pid]) {
            clearTimeout(debounceTimers[pid]);
        }

        debounceTimers[pid] = setTimeout(async () => {
            delete debounceTimers[pid];

            try {
                const currentItem = get().items[pid];

                if (quantity <= 0) {
                    // Удаление: если был cartItemId — DELETE
                    const cartItemId = currentItem?.cartItemId;
                    if (cartItemId) {
                        await window.axios.delete(`/api/cart/items/${cartItemId}`);
                    }
                } else if (currentItem?.cartItemId) {
                    // Обновление существующего элемента
                    await window.axios.patch(
                        `/api/cart/items/${currentItem.cartItemId}`,
                        { quantity }
                    );
                } else {
                    // Добавление нового элемента
                    const { data } = await window.axios.post('/api/cart/items', {
                        product_id: pid,
                        quantity,
                    });

                    // Сохраняем cartItemId из ответа сервера
                    if (data?.id) {
                        set((state) => {
                            const next = { ...state.items };
                            if (next[pid]) {
                                next[pid] = { ...next[pid], cartItemId: data.id };
                            }
                            return { items: next };
                        });
                    }
                }
            } catch {
                // При ошибке — перезагрузка корзины с сервера
                set({ loaded: false });
                get().init();
            }
        }, DEBOUNCE_MS);
    },

    /**
     * Очистка всей корзины.
     */
    clear: async () => {
        // Отменяем все pending debounce
        Object.keys(debounceTimers).forEach((key) => {
            clearTimeout(debounceTimers[key]);
            delete debounceTimers[key];
        });

        const previousItems = { ...get().items };

        // Optimistic update
        set({ items: {} });

        try {
            await window.axios.delete('/api/cart/clear');
        } catch {
            // Rollback
            set({ items: previousItems });
        }
    },

    /**
     * Сброс стора (при logout).
     */
    reset: () => {
        Object.keys(debounceTimers).forEach((key) => {
            clearTimeout(debounceTimers[key]);
            delete debounceTimers[key];
        });
        set({ items: {}, loaded: false, loading: false });
    },
}));
