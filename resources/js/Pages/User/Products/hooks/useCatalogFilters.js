import { useState, useEffect, useCallback, useRef } from 'react';
import { parseCompactQuery, buildCompactQuery, DEFAULTS } from '@/utils/compactFilters';

/**
 * useCatalogFilters — управление состоянием фильтров каталога + синхронизация с URL.
 *
 * При инициализации парсит URL query string и мержит с initialFilters (из Inertia props).
 * При изменении фильтров — обновляет URL через history.replaceState.
 *
 * @param {{ initialFilters?: object }} options
 * @returns {{
 *   filters: object,
 *   view: string,
 *   setView: (view: string) => void,
 *   updateFilter: (key: string, value: any) => void,
 *   resetFilters: () => void,
 *   goToPage: (page: number) => void,
 * }}
 */
export default function useCatalogFilters({ initialFilters = {} } = {}) {
    // Парсим URL один раз при монтировании
    const urlParsed = useRef(parseCompactQuery(window.location.search)).current;

    // Начальные фильтры: initialFilters (из Inertia) + URL-параметры
    const [filters, setFilters] = useState(() => ({
        ...initialFilters,
        ...urlParsed.filters,
    }));

    const [view, setView] = useState(urlParsed.view);

    // ─── Синхронизация с URL ───
    useEffect(() => {
        const qs = buildCompactQuery(filters, view);
        const newUrl = qs
            ? `${window.location.pathname}?${qs}`
            : window.location.pathname;
        window.history.replaceState(null, '', newUrl);
    }, [filters, view]);

    // ─── Обновить один фильтр (page сбрасывается на 1) ───
    const updateFilter = useCallback((key, value) => {
        setFilters((prev) => ({ ...prev, [key]: value, page: 1 }));
    }, []);

    // ─── Сбросить все фильтры к начальным ───
    const resetFilters = useCallback(() => {
        setFilters({
            ...initialFilters,
            sort: DEFAULTS.sort,
            per_page: DEFAULTS.per_page,
            page: DEFAULTS.page,
        });
    }, [initialFilters]);

    // ─── Перейти на страницу ───
    const goToPage = useCallback((page) => {
        setFilters((prev) => ({ ...prev, page }));
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }, []);

    return {
        filters,
        view,
        setView,
        updateFilter,
        resetFilters,
        goToPage,
    };
}
