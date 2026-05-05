/**
 * Zustand-стор для управления корзиной.
 *
 * Возможности:
 *   - localStorage-кеш (мгновенная загрузка UI до ответа сервера)
 *   - Cross-tab sync через Storage API
 *   - Debounced sync через POST /api/cart/set-product-quantity (spillover)
 *   - Clamping: при ответе `clamped < requested` → коррекция
 *   - Server re-sync на Inertia navigate и кастомном событии cart:server-synced
 *   - Custom events: cart:changed, cart:clamped, cart:server-synced
 *     (раньше был ещё cart:spillover — убран, фронт сам clamp-ит)
 *
 * Использование:
 *   import { useCartStore } from '@/stores/useCartStore';
 *
 *   const qty = useCartStore((s) => s.quantities[productId] || 0);
 *   const total = useCartStore((s) => s.getTotalQuantity());
 *   useCartStore.getState().setQuantity(productId, 5);
 */

import { create } from 'zustand';
import { router } from '@inertiajs/react';
import { toastError } from '@/utils/toast';

const STORAGE_KEY = 'cart:qty:v1';
// Длиннее debounce комфортнее переживает long-press на +/-: накапливаем
// финальное значение и шлём один POST. Фронт уже clamp-ит до stock+preorder,
// так что spillover-коррекций сервера ждать не нужно.
const DEBOUNCE_MS = 800;

/** Таймеры debounce для каждого product_id */
const debounceTimers = {};

// ────────────────────────────────────────────
// localStorage helpers
// ────────────────────────────────────────────

function loadFromCache() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return {};
        return parseQuantitiesMap(JSON.parse(raw));
    } catch {
        return {};
    }
}

function saveToCache(quantities) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(quantities));
    } catch {
        // quota exceeded — ignore
    }
}

/**
 * Нормализация объекта {stringKey: value} → {numericKey: positiveInt}.
 * Используется при парсинге ответа API и localStorage.
 */
function parseQuantitiesMap(data) {
    const result = {};
    if (data && typeof data === 'object') {
        for (const [k, v] of Object.entries(data)) {
            const num = Number(v);
            if (num > 0) result[Number(k)] = num;
        }
    }
    return result;
}

/**
 * Парсит ответ /api/cart/active-quantities. Поддерживает обе формы:
 *   - старая: {pid: qty, ...}
 *   - новая:  {quantities: {pid: qty}, splits: {pid: {instock,preorder}}, totals: {...}}
 */
function parseSnapshot(data) {
    if (data && typeof data === 'object' && data.quantities && typeof data.quantities === 'object') {
        return {
            quantities: parseQuantitiesMap(data.quantities),
            splits: parseSplits(data.splits),
            totals: parseTotals(data.totals),
        };
    }
    return {
        quantities: parseQuantitiesMap(data),
        splits: {},
        totals: { total: 0, instock: 0, preorder: 0 },
    };
}

function parseSplits(data) {
    const result = {};
    if (data && typeof data === 'object') {
        for (const [k, v] of Object.entries(data)) {
            if (!v || typeof v !== 'object') continue;
            const instock = Math.max(0, Number(v.instock || 0));
            const preorder = Math.max(0, Number(v.preorder || 0));
            if (instock + preorder > 0) {
                result[Number(k)] = { instock, preorder };
            }
        }
    }
    return result;
}

function parseTotals(data) {
    if (!data || typeof data !== 'object') return { total: 0, instock: 0, preorder: 0 };
    return {
        total: Number(data.total_quantity ?? data.total ?? 0),
        instock: Number(data.instock_quantity ?? data.instock ?? 0),
        preorder: Number(data.preorder_quantity ?? data.preorder ?? 0),
    };
}

// ────────────────────────────────────────────
// Store
// ────────────────────────────────────────────

export const useCartStore = create((set, get) => ({
    /**
     * Карта количеств: { [productId]: totalQty }
     * @type {Object<number, number>}
     */
    quantities: {},

    /**
     * Агрегаты корзины (для бейджей в шапке/мобильном меню).
     * Обновляются при init/sync с сервера и в ответе на set-product-quantity.
     * @type {{ total: number, instock: number, preorder: number }}
     */
    cartTotals: { total: 0, instock: 0, preorder: 0 },

    /**
     * Per-product split на текущий момент по серверным данным:
     * { [pid]: { instock, preorder } }. Используется для цветной рамки
     * counter везде в проекте — данные согласованы между корзиной и
     * карточкой товара. Обновляется при init/sync и после ответа на
     * set-product-quantity.
     * @type {Object<number, { instock: number, preorder: number }>}
     */
    productSplits: {},

    /** Флаг, что данные загружены с сервера */
    loaded: false,

    /** Флаг загрузки */
    loading: false,

    /**
     * Set product_id, для которых идёт API-запрос.
     * @type {Set<number>}
     */
    syncing: new Set(),

    /**
     * Инициализация корзины.
     * 1. Мгновенно загружает из localStorage
     * 2. Запрашивает сервер (GET /api/cart/active-quantities)
     * 3. Синхронизирует стор
     *
     * @param {object|null} user — auth.user из Inertia props (null для гостей)
     */
    init: async (user) => {
        if (!user || get().loading) return;

        // Если уже загружено — не повторять, но re-sync допускается через _serverSync
        if (get().loaded) return;

        // Мгновенная загрузка из кеша
        const cached = loadFromCache();
        if (Object.keys(cached).length > 0) {
            set({ quantities: cached });
        }

        set({ loading: true });

        try {
            const { data } = await window.axios.get('/api/cart/active-quantities');
            const { quantities, splits, totals } = parseSnapshot(data);

            set({ quantities, productSplits: splits, cartTotals: totals, loaded: true, loading: false });
            saveToCache(quantities);
        } catch {
            // При ошибке оставляем кешированные данные
            set({ loaded: true, loading: false });
        }
    },

    /**
     * Получить количество конкретного товара в корзине.
     * @param {number} productId
     * @returns {number}
     */
    getQuantity: (productId) => {
        return get().quantities[Number(productId)] || 0;
    },

    /**
     * Общее количество товаров в корзине.
     * @returns {number}
     */
    getTotalQuantity: () => {
        return Object.values(get().quantities).reduce((sum, qty) => sum + qty, 0);
    },

    /**
     * Общее количество позиций (уникальных товаров) в корзине.
     * @returns {number}
     */
    getTotalItems: () => {
        return Object.keys(get().quantities).length;
    },

    /**
     * Проверить, синхронизируется ли товар с сервером.
     * @param {number} productId
     * @returns {boolean}
     */
    isSyncing: (productId) => {
        return get().syncing.has(Number(productId));
    },

    /**
     * Установить количество товара в корзине с debounced API-синхронизацией.
     * Использует POST /api/cart/set-product-quantity (spillover API).
     *
     * @param {number} productId
     * @param {number} qty
     */
    setQuantity: (productId, qty) => {
        const pid = Number(productId);
        const quantity = Math.max(0, Math.floor(qty));

        // Optimistic update
        set((state) => {
            const next = { ...state.quantities };

            if (quantity <= 0) {
                delete next[pid];
            } else {
                next[pid] = quantity;
            }

            saveToCache(next);
            return { quantities: next };
        });

        // Dispatch cart:changed event
        window.dispatchEvent(new CustomEvent('cart:changed'));

        // Debounced API sync
        if (debounceTimers[pid]) {
            clearTimeout(debounceTimers[pid]);
        }

        debounceTimers[pid] = setTimeout(async () => {
            delete debounceTimers[pid];

            // Добавить в syncing
            set((state) => {
                const next = new Set(state.syncing);
                next.add(pid);
                return { syncing: next };
            });

            try {
                const { data } = await window.axios.post('/api/cart/set-product-quantity', {
                    product_id: pid,
                    quantity,
                });

                // Race condition guard: за время debounce + сетевого запроса
                // пользователь мог наклацать новое значение (новый optimistic
                // update в state.quantities). Если так — не перетираем qty
                // и split серверным ответом, иначе цифра «откатится» назад.
                // Свежее значение всё равно догонится следующим POST.
                const stillCurrent = () => (useCartStore.getState().quantities[pid] || 0) === quantity;

                // Clamping: сервер может вернуть меньше, чем запрошено
                if (data && typeof data.clamped === 'number' && stillCurrent()) {
                    const clamped = data.clamped;

                    set((state) => {
                        const next = { ...state.quantities };

                        if (clamped <= 0) {
                            delete next[pid];
                        } else {
                            next[pid] = clamped;
                        }

                        saveToCache(next);
                        return { quantities: next };
                    });

                    // Если clamped < requested — уведомить UI о коррекции.
                    // Распределение по preorder больше не диспатчится: фронт
                    // и так локально clamp-ит до stock+preorder, и шум событий
                    // на каждое нажатие "+" замедлял UX.
                    if (clamped < quantity) {
                        window.dispatchEvent(new CustomEvent('cart:clamped', {
                            detail: {
                                productId: pid,
                                requested: quantity,
                                clamped,
                                maxTotal: data.max_total,
                                instock: data.instock,
                                preorder: data.preorder,
                            },
                        }));
                    }
                }

                // Сервер прислал свежие totals — обновляем агрегаты для бейджей.
                // Можно обновлять всегда: totals — агрегаты по корзине, не зависят
                // от pending optimistic-обновлений по конкретному pid.
                if (data && data.cart_totals) {
                    set({ cartTotals: parseTotals(data.cart_totals) });
                }

                // Per-pid split — обновляем только если qty не успело уйти вперёд,
                // иначе цветная рамка counter будет «прыгать» (split не соответствует
                // текущему qty).
                if (data && (typeof data.instock === 'number' || typeof data.preorder === 'number') && stillCurrent()) {
                    const inS = Math.max(0, Number(data.instock || 0));
                    const pre = Math.max(0, Number(data.preorder || 0));
                    set((state) => {
                        const splits = { ...state.productSplits };
                        if (inS + pre > 0) {
                            splits[pid] = { instock: inS, preorder: pre };
                        } else {
                            delete splits[pid];
                        }
                        return { productSplits: splits };
                    });
                }

                // Dispatch cart:changed и cart:server-synced
                window.dispatchEvent(new CustomEvent('cart:changed'));
                window.dispatchEvent(new CustomEvent('cart:server-synced'));
            } catch {
                // При ошибке — перезагрузка корзины с сервера
                toastError('Ошибка синхронизации', 'Не удалось сохранить изменения. Данные обновлены с сервера.');
                get()._serverSync();
            } finally {
                // Убрать из syncing
                set((state) => {
                    const next = new Set(state.syncing);
                    next.delete(pid);
                    return { syncing: next };
                });
            }
        }, DEBOUNCE_MS);
    },

    /**
     * Bulk-установка количеств для набора товаров. Один POST вместо N
     * параллельных запросов — на бэке всё в одной транзакции, поэтому нет
     * шанса упереться в дедлок InnoDB на cart_items одной корзины.
     *
     * Возвращает summary { updated, clamped: [{productId, requested, clamped, instock, preorder, maxTotal}] }.
     *
     * @param {Object<number, number>} quantitiesMap — карта productId → qty
     * @returns {Promise<{updated: number, clamped: Array}>}
     */
    setQuantitiesBulk: async (quantitiesMap) => {
        const entries = Object.entries(quantitiesMap || {})
            .map(([pid, qty]) => [Number(pid), Math.max(0, Math.floor(Number(qty)))])
            .filter(([pid]) => pid > 0);
        if (entries.length === 0) {
            return { updated: 0, clamped: [] };
        }

        // Снимаем pending debounce по этим pid — bulk их перекрывает.
        for (const [pid] of entries) {
            if (debounceTimers[pid]) {
                clearTimeout(debounceTimers[pid]);
                delete debounceTimers[pid];
            }
        }

        // Optimistic update
        set((state) => {
            const next = { ...state.quantities };
            for (const [pid, qty] of entries) {
                if (qty <= 0) {
                    delete next[pid];
                } else {
                    next[pid] = qty;
                }
            }
            saveToCache(next);
            return { quantities: next };
        });
        window.dispatchEvent(new CustomEvent('cart:changed'));

        // Отметить syncing
        set((state) => {
            const next = new Set(state.syncing);
            for (const [pid] of entries) next.add(pid);
            return { syncing: next };
        });

        try {
            const { data } = await window.axios.post('/api/cart/set-products-quantity', {
                items: entries.map(([product_id, quantity]) => ({ product_id, quantity })),
            });

            const requestedMap = Object.fromEntries(entries);
            const responseItems = (data && data.items && typeof data.items === 'object')
                ? Object.values(data.items)
                : [];
            const clampedReport = [];

            // Обновляем локальное qty и splits ровно тем, что подтвердил сервер.
            set((state) => {
                const nextQ = { ...state.quantities };
                const nextSplits = { ...state.productSplits };
                for (const it of responseItems) {
                    const pid = Number(it.product_id);
                    if (!pid) continue;
                    const clamped = Number(it.clamped || 0);
                    const inS = Math.max(0, Number(it.instock || 0));
                    const pre = Math.max(0, Number(it.preorder || 0));
                    if (clamped <= 0) {
                        delete nextQ[pid];
                    } else {
                        nextQ[pid] = clamped;
                    }
                    if (inS + pre > 0) {
                        nextSplits[pid] = { instock: inS, preorder: pre };
                    } else {
                        delete nextSplits[pid];
                    }
                    const requested = requestedMap[pid] ?? clamped;
                    if (clamped < requested) {
                        clampedReport.push({
                            productId: pid,
                            requested,
                            clamped,
                            instock: inS,
                            preorder: pre,
                            maxTotal: Number(it.max_total || (inS + pre)),
                        });
                    }
                }
                saveToCache(nextQ);
                return { quantities: nextQ, productSplits: nextSplits };
            });

            if (data && data.cart_totals) {
                set({ cartTotals: parseTotals(data.cart_totals) });
            }

            window.dispatchEvent(new CustomEvent('cart:changed'));
            window.dispatchEvent(new CustomEvent('cart:server-synced'));

            return { updated: responseItems.length, clamped: clampedReport };
        } catch (err) {
            toastError('Ошибка синхронизации', 'Не удалось сохранить изменения. Данные обновлены с сервера.');
            await get()._serverSync();
            throw err;
        } finally {
            set((state) => {
                const next = new Set(state.syncing);
                for (const [pid] of entries) next.delete(pid);
                return { syncing: next };
            });
        }
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

        const previousQuantities = { ...get().quantities };

        // Optimistic update
        set({ quantities: {}, syncing: new Set() });
        saveToCache({});
        window.dispatchEvent(new CustomEvent('cart:changed'));

        try {
            await window.axios.delete('/api/cart/clear');
            window.dispatchEvent(new CustomEvent('cart:server-synced'));
        } catch {
            // Rollback
            toastError('Ошибка', 'Не удалось очистить корзину.');
            set({ quantities: previousQuantities });
            saveToCache(previousQuantities);
            window.dispatchEvent(new CustomEvent('cart:changed'));
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
        set({
            quantities: {},
            productSplits: {},
            cartTotals: { total: 0, instock: 0, preorder: 0 },
            loaded: false,
            loading: false,
            syncing: new Set(),
        });
        try {
            localStorage.removeItem(STORAGE_KEY);
        } catch {
            // ignore
        }
    },

    /**
     * Принудительная синхронизация с сервером.
     * @private
     */
    _serverSync: async () => {
        try {
            const { data } = await window.axios.get('/api/cart/active-quantities');
            const { quantities, splits, totals } = parseSnapshot(data);

            set({ quantities, productSplits: splits, cartTotals: totals, loaded: true });
            saveToCache(quantities);
            window.dispatchEvent(new CustomEvent('cart:changed'));
        } catch {
            // ignore
        }
    },
}));

// ────────────────────────────────────────────
// Cross-tab sync
// ────────────────────────────────────────────

if (typeof window !== 'undefined') {
    // Есть ли локальные изменения в полёте, которые ещё не отправлены на
    // сервер (debounce-окно) или ждут ответа? Если да — пропускаем
    // принудительный _serverSync, чтобы он не перетёр свежий optimistic
    // update «старым» серверным значением. Догонится следующим POST из
    // setQuantity.
    const hasPendingLocalUpdates = () => {
        if (Object.keys(debounceTimers).length > 0) return true;
        const state = useCartStore.getState();
        return state.syncing.size > 0;
    };

    window.addEventListener('storage', (e) => {
        if (e.key !== STORAGE_KEY) return;

        let quantities;
        try {
            quantities = parseQuantitiesMap(JSON.parse(e.newValue || '{}'));
        } catch {
            return;
        }

        // Если в этой вкладке есть pending updates — другая вкладка не должна
        // перетирать наше qty. Sync разойдётся после ответа на наш POST.
        if (hasPendingLocalUpdates()) return;

        // Quantities обновляем мгновенно из localStorage (другая вкладка).
        useCartStore.setState({ quantities });
        window.dispatchEvent(new CustomEvent('cart:changed'));

        // А productSplits и cartTotals localStorage не несёт — догоняем
        // фоновым server-sync, чтобы цветная рамка counter и бейдж шапки
        // тоже отражали изменения из другой вкладки.
        const state = useCartStore.getState();
        if (state.loaded) state._serverSync();
    });

    // Server re-sync на Inertia navigate
    router.on('navigate', () => {
        const state = useCartStore.getState();
        if (!state.loaded) return;
        if (hasPendingLocalUpdates()) return;
        state._serverSync();
    });
}
