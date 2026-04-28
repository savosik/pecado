import { useCallback, useEffect, useState } from 'react';

const MIN_QUERY_LENGTH = 2;

const storageKey = (section) => `cabinet.search.${section}.history`;

function readStorage(key) {
    if (typeof window === 'undefined') return [];
    try {
        const raw = window.localStorage.getItem(key);
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed.filter((item) => typeof item === 'string') : [];
    } catch {
        return [];
    }
}

function writeStorage(key, value) {
    if (typeof window === 'undefined') return;
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // localStorage недоступен (приватный режим, переполнение) — молча игнорируем
    }
}

function removeStorage(key) {
    if (typeof window === 'undefined') return;
    try {
        window.localStorage.removeItem(key);
    } catch {
        // ignore
    }
}

/**
 * useSearchHistory — клиентская история поисковых запросов в localStorage,
 * раздельно по разделу кабинета (orders / returns / shipments / ...).
 *
 * Контракт § «Сквозные принципы» п.3: храним только запросы длиной ≥ 2 символов,
 * последние `limit` штук, без дубликатов (новый поднимается наверх).
 *
 * @param {string} section идентификатор раздела (например `orders`, `returns`).
 * @param {number} [limit=10] максимальное количество записей.
 * @returns {{ history: string[], push: (q: string) => void, clear: () => void }}
 */
export function useSearchHistory(section, limit = 10) {
    const key = storageKey(section);
    const [history, setHistory] = useState([]);

    useEffect(() => {
        setHistory(readStorage(key));
    }, [key]);

    const push = useCallback(
        (query) => {
            const trimmed = String(query ?? '').trim();
            if (trimmed.length < MIN_QUERY_LENGTH) return;

            setHistory((prev) => {
                const filtered = prev.filter((item) => item !== trimmed);
                const next = [trimmed, ...filtered].slice(0, Math.max(1, limit));
                writeStorage(key, next);
                return next;
            });
        },
        [key, limit],
    );

    const clear = useCallback(() => {
        removeStorage(key);
        setHistory([]);
    }, [key]);

    return { history, push, clear };
}

export default useSearchHistory;
