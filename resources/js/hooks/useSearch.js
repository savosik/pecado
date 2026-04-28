/**
 * Хук useSearch — управление состоянием поиска.
 *
 * Возможности:
 *  - Debounced запрос к GET /search (JSON) с AbortController
 *  - Результаты по секциям: products, categories, brands, articles, news
 *  - История поиска: загрузка, удаление одной записи, очистка всей
 *  - Переключатель «Включая отсутствующие» (клиентская фильтрация)
 *  - Блокировка скролла при открытом дропдауне на мобильном
 *  - Медиа-запрос для мобильного режима (< 640px)
 *  - Глобальный флаг window.__searchOpen
 *
 * Использование:
 *   const search = useSearch(user);
 *   <div ref={search.containerRef}>
 *     <input value={search.query} onChange={e => search.setQuery(e.target.value)} onFocus={search.onFocus} />
 *     {search.open && <SearchDropdown {...search} />}
 *   </div>
 */

import { useEffect, useMemo, useRef, useState, useCallback } from 'react';
import { router } from '@inertiajs/react';

const DEBOUNCE_MS = 250;
const MIN_QUERY_LENGTH = 2;
const SEARCH_LIMIT = 10;

const EMPTY_RESULTS = Object.freeze({
    products: [],
    categories: [],
    brands: [],
    articles: [],
    news: [],
});

/**
 * @param {object|null} user — объект текущего пользователя (auth.user) или null
 */
export default function useSearch(user = null) {
    const [query, setQuery] = useState('');
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    const [results, setResults] = useState(EMPTY_RESULTS);
    const [history, setHistory] = useState([]);
    const [error, setError] = useState(null);
    const [includeUnavailable, setIncludeUnavailable] = useState(false);
    const [isSmall, setIsSmall] = useState(false);

    const containerRef = useRef(null);
    const abortRef = useRef(null);
    const debounceRef = useRef(null);

    // ──────────────────────────────────────────────────────────
    // Computed
    // ──────────────────────────────────────────────────────────

    const hasResults = useMemo(() => {
        if (!results || results === EMPTY_RESULTS) return false;
        return Object.values(results).some((arr) => Array.isArray(arr) && arr.length > 0);
    }, [results]);

    // ──────────────────────────────────────────────────────────
    // Media query — мобильный режим (< 640px)
    // ──────────────────────────────────────────────────────────

    useEffect(() => {
        const mql = window.matchMedia('(max-width: 639px)');
        setIsSmall(mql.matches);

        function onChange(e) {
            setIsSmall(e.matches);
        }
        mql.addEventListener('change', onChange);
        return () => mql.removeEventListener('change', onChange);
    }, []);

    // ──────────────────────────────────────────────────────────
    // Click-outside → закрыть дропдаун
    // ──────────────────────────────────────────────────────────

    useEffect(() => {
        function handleClickOutside(e) {
            // На мобильном overlay закрывается кнопкой, а не click-outside
            if (isSmall) return;
            if (containerRef.current && !containerRef.current.contains(e.target)) {
                setOpen(false);
            }
        }
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, [isSmall]);

    // ──────────────────────────────────────────────────────────
    // Блокировка скролла при открытом дропдауне (только мобильный)
    // ──────────────────────────────────────────────────────────

    useEffect(() => {
        window.__searchOpen = open;

        if (open && isSmall) {
            document.documentElement.style.overflow = 'hidden';
        } else {
            document.documentElement.style.overflow = '';
        }
        return () => {
            document.documentElement.style.overflow = '';
            window.__searchOpen = false;
        };
    }, [open, isSmall]);

    // ──────────────────────────────────────────────────────────
    // Debounced API-запрос к GET /search (JSON)
    // ──────────────────────────────────────────────────────────

    useEffect(() => {
        // Отмена предыдущего запроса
        if (abortRef.current) {
            abortRef.current.abort();
            abortRef.current = null;
        }

        // Отмена предыдущего таймера
        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
            debounceRef.current = null;
        }

        if (!query || query.trim().length < MIN_QUERY_LENGTH) {
            setResults(EMPTY_RESULTS);
            setLoading(false);
            setError(null);
            return;
        }

        setLoading(true);
        setError(null);

        debounceRef.current = setTimeout(async () => {
            const controller = new AbortController();
            abortRef.current = controller;

            try {
                const response = await window.axios.get('/search', {
                    params: {
                        q: query.trim(),
                        limit: SEARCH_LIMIT,
                        include_unavailable: 1,
                    },
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });

                const data = response.data;
                setResults(data?.results ?? EMPTY_RESULTS);
            } catch (e) {
                if (e.name !== 'AbortError' && e.code !== 'ERR_CANCELED') {
                    setError(e.response?.data?.message ?? e.message ?? 'Ошибка поиска');
                    setResults(EMPTY_RESULTS);
                }
            } finally {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            }
        }, DEBOUNCE_MS);

        return () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
            if (abortRef.current) {
                abortRef.current.abort();
                abortRef.current = null;
            }
        };
    }, [query]);

    // ──────────────────────────────────────────────────────────
    // История поиска (только для авторизованных)
    // ──────────────────────────────────────────────────────────

    /** Загрузить историю поиска */
    const loadHistory = useCallback(async () => {
        if (!user) return;
        try {
            const response = await window.axios.get('/api/search/history');
            setHistory(response.data ?? []);
        } catch {
            // Молча игнорируем ошибки загрузки истории
        }
    }, [user]);

    /** Удалить одну запись из истории */
    const deleteHistoryItem = useCallback(async (id) => {
        if (!user) return;
        try {
            await window.axios.delete(`/api/search/history/${id}`);
            setHistory((prev) => prev.filter((item) => item.id !== id));
        } catch {
            // Молча игнорируем
        }
    }, [user]);

    /** Очистить всю историю */
    const clearAllHistory = useCallback(async () => {
        if (!user) return;
        try {
            await window.axios.delete('/api/search/history');
            setHistory([]);
        } catch {
            // Молча игнорируем
        }
    }, [user]);

    // ──────────────────────────────────────────────────────────
    // Actions
    // ──────────────────────────────────────────────────────────

    /** Открыть дропдаун при фокусе, загрузить историю */
    const onFocus = useCallback(() => {
        setOpen(true);
        if (user && !query.trim()) {
            loadHistory();
        }
    }, [user, query, loadHistory]);

    /** Перейти на страницу полных результатов (по Enter) */
    const submitSearch = useCallback(() => {
        const trimmed = query.trim();
        if (trimmed.length >= MIN_QUERY_LENGTH) {
            setOpen(false);
            router.visit(`/search?q=${encodeURIComponent(trimmed)}`);
        }
    }, [query]);

    /** Очистить поиск */
    const clearSearch = useCallback(() => {
        setQuery('');
        setResults(EMPTY_RESULTS);
        setOpen(false);
        setError(null);
    }, []);

    /** Переключить «Включая отсутствующие» */
    const toggleIncludeUnavailable = useCallback(() => {
        setIncludeUnavailable((prev) => !prev);
    }, []);

    // ──────────────────────────────────────────────────────────
    // Return
    // ──────────────────────────────────────────────────────────

    return {
        // state
        query,
        setQuery,
        loading,
        open,
        setOpen,
        results,
        history,
        error,
        isSmall,
        includeUnavailable,
        hasResults,
        // refs
        containerRef,
        // actions
        onFocus,
        submitSearch,
        clearSearch,
        toggleIncludeUnavailable,
        loadHistory,
        deleteHistoryItem,
        clearAllHistory,
    };
}
