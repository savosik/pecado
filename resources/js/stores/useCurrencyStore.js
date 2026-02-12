/**
 * Zustand-стор для управления валютой пользователя.
 *
 * Использование:
 *   import { useCurrencyStore } from '@/stores/useCurrencyStore';
 *
 *   const { current, currencies, switchCurrency } = useCurrencyStore();
 *   switchCurrency('USD'); // переключает валюту и перезагружает страницу
 */

import { create } from 'zustand';
import { router } from '@inertiajs/react';

export const useCurrencyStore = create((set, get) => ({
    /** Код текущей валюты пользователя (напр. 'RUB') */
    current: null,

    /** Список доступных валют: [{ code, name, symbol }] */
    currencies: [],

    /** Флаг, что данные загружены */
    loaded: false,

    /** Флаг, что идёт переключение */
    switching: false,

    /**
     * Инициализация стора из shared Inertia props или API.
     * Вызывать один раз при монтировании приложения.
     * @param {{ code: string }} currentCurrency — текущая валюта из props
     * @param {Array<{ code: string, name: string, symbol: string }>} availableCurrencies — список валют
     */
    init: (currentCurrency, availableCurrencies = []) => {
        if (get().loaded) return;

        set({
            current: currentCurrency?.code || null,
            currencies: availableCurrencies,
            loaded: true,
        });
    },

    /**
     * Загрузка списка валют с сервера (если не было инициализации через props).
     */
    loadCurrencies: async () => {
        try {
            const { data } = await window.axios.get('/api/currencies');
            set({ currencies: data?.currencies || [] });
        } catch {
            // Молча игнорируем ошибку загрузки валют
        }
    },

    /**
     * Переключение валюты.
     * POST → сервер сохраняет currency_id → стор обновляется → router.reload().
     * @param {string} code — код валюты (напр. 'USD')
     */
    switchCurrency: async (code) => {
        if (get().switching || get().current === code) return;

        const previousCode = get().current;
        set({ switching: true, current: code });

        try {
            await window.axios.post('/api/currency/switch', { code });
            // Перезагрузка страницы для обновления цен
            router.reload({
                onFinish: () => {
                    set({ switching: false });
                },
            });
        } catch {
            // Rollback
            set({ current: previousCode, switching: false });
        }
    },

    /**
     * Сброс стора (при logout).
     */
    reset: () => set({
        current: null,
        currencies: [],
        loaded: false,
        switching: false,
    }),
}));
