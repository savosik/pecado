/**
 * Zustand-стор для управления избранными товарами.
 *
 * Использование:
 *   import { useFavoritesStore } from '@/stores/useFavoritesStore';
 *
 *   const { isFavorite, toggle, loadOnce, getCount } = useFavoritesStore();
 *   await loadOnce(); // вызвать один раз при монтировании (проверяет auth)
 *   toggle(productId); // добавить/удалить из избранного
 */

import { create } from 'zustand';

export const useFavoritesStore = create((set, get) => ({
    /** @type {Set<number>} ID товаров в избранном */
    ids: new Set(),

    /** Флаг, что данные уже загружены */
    loaded: false,

    /** Флаг загрузки */
    loading: false,

    /**
     * Загрузка ID избранных с сервера (одноразово).
     * Не делает ничего для гостей и если уже загружено.
     *
     * @param {object|null} user — объект auth.user из Inertia props (null для гостей)
     */
    loadOnce: async (user) => {
        if (!user || get().loaded || get().loading) return;

        set({ loading: true });

        try {
            const { data } = await window.axios.get('/api/favorites/ids');
            set({
                ids: new Set(data?.product_ids || []),
                loaded: true,
                loading: false,
            });
        } catch {
            set({ loading: false });
        }
    },

    /**
     * Проверить, находится ли товар в избранном.
     * @param {number} id — ID товара
     * @returns {boolean}
     */
    isFavorite: (id) => get().ids.has(Number(id)),

    /**
     * Количество товаров в избранном.
     * @returns {number}
     */
    getCount: () => get().ids.size,

    /**
     * Добавить/удалить товар из избранного (optimistic update).
     * @param {number} productId
     */
    toggle: async (productId) => {
        const pid = Number(productId);
        const was = get().isFavorite(pid);

        // Optimistic update
        set((state) => {
            const next = new Set(state.ids);
            was ? next.delete(pid) : next.add(pid);
            return { ids: next };
        });

        try {
            await window.axios.post(`/api/favorites/${pid}/toggle`);
        } catch {
            // Rollback при ошибке
            set((state) => {
                const next = new Set(state.ids);
                was ? next.add(pid) : next.delete(pid);
                return { ids: next };
            });
        }
    },

    /**
     * Сброс стора (при logout).
     */
    reset: () => set({ ids: new Set(), loaded: false, loading: false }),
}));
