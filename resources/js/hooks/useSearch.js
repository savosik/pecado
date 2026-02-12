/**
 * Хук для поиска: debounced запрос к /api/search/suggestions,
 * управление dropdown, click-outside, навигация по Enter.
 *
 * Использование:
 *   const search = useSearch();
 *   <div ref={search.containerRef}>
 *     <input value={search.query} onChange={e => search.setQuery(e.target.value)} onFocus={search.onFocus} />
 *     {search.open && search.hasResults && <Dropdown results={search.results} />}
 *   </div>
 */

import { useEffect, useMemo, useRef, useState, useCallback } from 'react';
import { router } from '@inertiajs/react';

const DEBOUNCE_MS = 300;
const MIN_QUERY_LENGTH = 2;
const SUGGESTIONS_LIMIT = 5;

const EMPTY_RESULTS = [];

export default function useSearch() {
    const [query, setQuery] = useState('');
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    const [results, setResults] = useState(EMPTY_RESULTS);
    const [error, setError] = useState(null);

    const containerRef = useRef(null);
    const abortRef = useRef(null);

    const hasResults = useMemo(() => results.length > 0, [results]);

    // Click-outside → закрыть dropdown
    useEffect(() => {
        function handleClickOutside(e) {
            if (containerRef.current && !containerRef.current.contains(e.target)) {
                setOpen(false);
            }
        }
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    // Debounced запрос к API
    useEffect(() => {
        if (abortRef.current) {
            abortRef.current.abort();
        }

        if (!query || query.trim().length < MIN_QUERY_LENGTH) {
            setResults(EMPTY_RESULTS);
            setLoading(false);
            setError(null);
            return;
        }

        setLoading(true);
        setError(null);

        const controller = new AbortController();
        abortRef.current = controller;

        const timeoutId = setTimeout(async () => {
            try {
                const response = await window.axios.get('/api/search/suggestions', {
                    params: { q: query, limit: SUGGESTIONS_LIMIT },
                    signal: controller.signal,
                });
                setResults(response.data || EMPTY_RESULTS);
            } catch (e) {
                if (e.name !== 'AbortError' && e.code !== 'ERR_CANCELED') {
                    setError(e.message ?? 'Ошибка поиска');
                    setResults(EMPTY_RESULTS);
                }
            } finally {
                setLoading(false);
            }
        }, DEBOUNCE_MS);

        return () => clearTimeout(timeoutId);
    }, [query]);

    /** Открыть dropdown при фокусе */
    const onFocus = useCallback(() => {
        setOpen(true);
    }, []);

    /** Перейти на страницу поиска (по Enter) */
    const submitSearch = useCallback(() => {
        if (query.trim().length >= MIN_QUERY_LENGTH) {
            setOpen(false);
            router.visit(`/search?q=${encodeURIComponent(query.trim())}`);
        }
    }, [query]);

    /** Очистить поиск */
    const clearSearch = useCallback(() => {
        setQuery('');
        setResults(EMPTY_RESULTS);
        setOpen(false);
        setError(null);
    }, []);

    return {
        // state
        query,
        setQuery,
        loading,
        open,
        setOpen,
        results,
        error,
        hasResults,
        // refs
        containerRef,
        // actions
        onFocus,
        submitSearch,
        clearSearch,
    };
}
