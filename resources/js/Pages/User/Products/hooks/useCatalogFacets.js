import { useState, useEffect, useRef, useMemo } from 'react';
import { buildApiParams } from '@/utils/compactFilters';

/**
 * useCatalogFacets — загрузка фасетных данных (бренды, категории, атрибуты) из API.
 *
 * Запрашивает /api/catalog/products/facets с текущими фильтрами.
 * Поддерживает AbortController для отмены предыдущих запросов.
 *
 * @param {{ filters: object }} options
 * @returns {{ facets: { brands: Array, categories: Array, attributes: Array } | null, loading: boolean }}
 */
export default function useCatalogFacets({ filters }) {
    const [facets, setFacets] = useState(null);
    const [loading, setLoading] = useState(true);
    const abortRef = useRef(null);

    // Стабилизируем зависимость: исключаем sort/per_page/page/view — они не влияют на фасеты
    const filtersKey = useMemo(() => {
        const { sort, per_page, page, ...rest } = filters;
        return JSON.stringify(rest);
    }, [filters]);

    useEffect(() => {
        if (abortRef.current) abortRef.current.abort();
        abortRef.current = new AbortController();

        setLoading(true);

        const params = buildApiParams(filters);
        // Убираем параметры, не влияющие на фасеты
        params.delete('sort');
        params.delete('per_page');
        params.delete('page');

        fetch(`/api/catalog/products/facets?${params.toString()}`, {
            signal: abortRef.current.signal,
        })
            .then((r) => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.json();
            })
            .then((data) => {
                setFacets(data);
                setLoading(false);
            })
            .catch((err) => {
                if (err.name !== 'AbortError') {
                    console.error('Ошибка загрузки фасетов:', err);
                    setLoading(false);
                }
            });

        return () => {
            if (abortRef.current) abortRef.current.abort();
        };
    }, [filtersKey]); // eslint-disable-line react-hooks/exhaustive-deps — filtersKey стабилизирует deps, sort/per_page/page исключены

    return { facets, loading };
}
