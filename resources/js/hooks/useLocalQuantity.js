import { useCallback, useEffect, useRef, useState } from 'react';
import { useCartStore } from '@/stores/useCartStore';

const PROPAGATE_MS = 220;

/**
 * Локальное qty со store-синхронизацией, debounced на 220 мс.
 *
 * Используется в строках корзины: пользователь жмёт +/- → счётчик в строке
 * обновляется МГНОВЕННО (только эта строка ререндерится), а остальные
 * подписчики стора (CartTable footer, CartSummary, CartHeader) узнают об
 * изменении через ~220 мс — после завершения серии кликов.
 *
 * @param {number} pid product_id
 * @param {(pid: number, qty: number) => void} onSetQty внешний апплаер (обычно идёт в useCartStore.setQuantity)
 * @returns {[number, (v: number) => void]} [текущее видимое qty, setter]
 */
export function useLocalQuantity(pid, onSetQty) {
    const storeQty = useCartStore((s) => s.quantities[pid] || 0);
    const [localQty, setLocalQty] = useState(storeQty);
    const timerRef = useRef(null);

    // Синхронизация со стором — только когда нет ожидающей локальной правки.
    // Иначе значение из стора затрёт пользовательский ввод посередине серии.
    useEffect(() => {
        if (timerRef.current) return;
        setLocalQty(storeQty);
    }, [storeQty]);

    const setQty = useCallback((v) => {
        setLocalQty(v);
        if (timerRef.current) clearTimeout(timerRef.current);
        timerRef.current = setTimeout(() => {
            timerRef.current = null;
            onSetQty(pid, v);
        }, PROPAGATE_MS);
    }, [onSetQty, pid]);

    // cleanup
    useEffect(() => () => {
        if (timerRef.current) {
            clearTimeout(timerRef.current);
            timerRef.current = null;
        }
    }, []);

    return [localQty, setQty];
}
