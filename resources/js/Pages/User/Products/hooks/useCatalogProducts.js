import { useState, useEffect, useCallback, useRef } from 'react';
import { buildApiParams } from '@/utils/compactFilters';

/**
 * useCatalogProducts — загрузка товаров из API с AbortController.
 *
 * Автоматически перезагружает данные при изменении filters.
 * Поддерживает infinite scroll через loadMore().
 *
 * @param {{ filters: object }} options
 * @returns {{
 *   products: Array,
 *   meta: object|null,
 *   loading: boolean,
 *   loadingMore: boolean,
 *   error: string|null,
 *   loadMore: () => void,
 * }}
 */
export default function useCatalogProducts({ filters }) {
    const [products, setProducts] = useState([]);
    const [meta, setMeta] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [loadingMore, setLoadingMore] = useState(false);

    const abortRef = useRef(null);
    const loadMoreAbortRef = useRef(null);
    const loadingMoreRef = useRef(false);

    // ─── Загрузка товаров при смене фильтров ───
    useEffect(() => {
        if (abortRef.current) abortRef.current.abort();
        abortRef.current = new AbortController();

        setLoading(true);
        setError(null);

        const params = buildApiParams(filters);

        fetch(`/api/catalog/products?${params.toString()}`, {
            signal: abortRef.current.signal,
        })
            .then((r) => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.json();
            })
            .then((data) => {
                setProducts(data.data || []);
                setMeta(data.meta || null);
                setLoading(false);
            })
            .catch((err) => {
                if (err.name !== 'AbortError') {
                    console.error('Ошибка загрузки товаров:', err);
                    setError('Не удалось загрузить товары. Попробуйте обновить страницу.');
                    setLoading(false);
                }
            });

        return () => {
            if (abortRef.current) abortRef.current.abort();
            if (loadMoreAbortRef.current) loadMoreAbortRef.current.abort();
        };
    }, [filters]);

    // ─── Загрузить ещё (infinite scroll) ───
    const loadMore = useCallback(() => {
        if (!meta || meta.current_page >= meta.last_page || loadingMoreRef.current) {
            return;
        }

        if (loadMoreAbortRef.current) loadMoreAbortRef.current.abort();
        loadMoreAbortRef.current = new AbortController();

        loadingMoreRef.current = true;
        setLoadingMore(true);

        const nextPage = meta.current_page + 1;
        const nextFilters = { ...filters, page: nextPage };
        const params = buildApiParams(nextFilters);

        fetch(`/api/catalog/products?${params.toString()}`, {
            signal: loadMoreAbortRef.current.signal,
        })
            .then((r) => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.json();
            })
            .then((data) => {
                const newProducts = data.data || [];

                // Мердж с дедупликацией по id
                setProducts((prev) => {
                    const existingIds = new Set(prev.map((p) => p.id));
                    const unique = newProducts.filter((p) => !existingIds.has(p.id));
                    return [...prev, ...unique];
                });

                // Обновляем meta, сохраняя from от первой загрузки
                setMeta((prevMeta) => ({
                    ...(data.meta || {}),
                    from: prevMeta?.from ?? 1,
                }));

                loadingMoreRef.current = false;
                setLoadingMore(false);
            })
            .catch((err) => {
                if (err.name !== 'AbortError') {
                    console.error('Ошибка загрузки товаров:', err);
                    loadingMoreRef.current = false;
                    setLoadingMore(false);
                }
            });
    }, [meta, filters]);

    return {
        products,
        meta,
        loading,
        loadingMore,
        error,
        loadMore,
    };
}
