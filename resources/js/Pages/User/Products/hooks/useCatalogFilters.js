import { useState, useEffect, useCallback, useRef } from 'react';
import {
    parseCompactQuery,
    buildCompactQuery,
    DEFAULTS,
    ALIASES,
    getResponsiveDefaultView,
} from '@/utils/compactFilters';

/**
 * useCatalogFilters — управление состоянием фильтров каталога + синхронизация с URL.
 *
 * При инициализации парсит URL query string и мержит с initialFilters (из Inertia props).
 * При изменении фильтров — обновляет URL через history.replaceState.
 *
 * @param {{ initialFilters?: object, defaults?: object }} options
 *   defaults — дефолты страницы (sort/view/per_page/page); на поиске sort = relevance
 * @returns {{
 *   filters: object,
 *   view: string,
 *   setView: (view: string) => void,
 *   updateFilter: (key: string, value: any) => void,
 *   resetFilters: () => void,
 *   goToPage: (page: number) => void,
 * }}
 */
export default function useCatalogFilters({ initialFilters = {}, defaults = DEFAULTS } = {}) {
    // Дефолты страницы: каталог — newest, поиск — relevance
    const cfg = useRef({ ...DEFAULTS, ...defaults }).current;

    // Парсим URL один раз при монтировании
    const urlParsed = useRef(parseCompactQuery(window.location.search, cfg)).current;

    // Начальные фильтры: initialFilters (из Inertia) + URL-параметры
    const [filters, setFilters] = useState(() => ({
        ...initialFilters,
        ...urlParsed.filters,
    }));

    // Если в URL явно задан view — используем его. Иначе берём адаптивный дефолт
    // (на мобиле — 'list', на md+ — 'grid').
    const initialView = useRef(
        new URLSearchParams(window.location.search).get(ALIASES.view)
            ? urlParsed.view
            : getResponsiveDefaultView()
    ).current;
    const [view, setView] = useState(initialView);

    // ─── Синхронизация с URL ───
    // Важно: сохраняем history.state, чтобы не затереть кэш Inertia.
    // Также обновляем history.state.page.url, потому что при popstate
    // Inertia использует это значение для определения страницы.
    // Без этого кнопка «Назад» браузера теряет фильтры.
    useEffect(() => {
        const qs = buildCompactQuery(filters, view, cfg);
        const newUrl = qs
            ? `${window.location.pathname}?${qs}`
            : window.location.pathname;

        const state = window.history.state ? { ...window.history.state } : {};
        if (state.page) {
            state.page = { ...state.page, url: newUrl };
        }
        window.history.replaceState(state, '', newUrl);
    }, [filters, view, cfg]);

    // ─── Обновить один фильтр (page сбрасывается на 1) ───
    const updateFilter = useCallback((key, value) => {
        setFilters((prev) => ({ ...prev, [key]: value, page: 1 }));
    }, []);

    // ─── Обновить несколько фильтров (batch, page сбрасывается на 1) ───
    const updateFilters = useCallback((updates) => {
        setFilters((prev) => ({ ...prev, ...updates, page: 1 }));
    }, []);

    // ─── Сбросить все фильтры к начальным ───
    const resetFilters = useCallback(() => {
        setFilters({
            ...initialFilters,
            sort: cfg.sort,
            per_page: cfg.per_page,
            page: cfg.page,
        });
    }, [initialFilters, cfg]);

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
        updateFilters,
        resetFilters,
        goToPage,
    };
}
