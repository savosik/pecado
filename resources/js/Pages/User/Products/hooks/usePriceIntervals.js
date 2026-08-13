import { useState, useEffect, useRef, useMemo } from 'react';
import { buildApiParams } from '@/utils/compactFilters';

/**
 * usePriceIntervals — загрузка ценовых интервалов из API.
 *
 * Запрашивает /api/catalog/products/price-intervals с текущими фильтрами
 * (кроме price_min/price_max, т.к. бэкенд их исключает).
 * Поддерживает AbortController для отмены предыдущих запросов.
 *
 * @param {{ filters: object, endpoint?: string }} options
 * @returns {{ priceData: { min: number, max: number, buckets: Array } | null, loading: boolean }}
 */
export default function usePriceIntervals({ filters, endpoint = '/api/catalog/products/price-intervals' }) {
    const [priceData, setPriceData] = useState(null);
    const [loading, setLoading] = useState(true);
    const abortRef = useRef(null);

    // Стабилизируем зависимость: сериализуем только ключи, влияющие на price-intervals
    // (price_min/price_max исключены, т.к. бэкенд их тоже убирает)
    const filtersKey = useMemo(() => {
        const { price_min, price_max, sort, per_page, page, ...rest } = filters;
        return JSON.stringify(rest);
    }, [filters]);

    useEffect(() => {
        if (abortRef.current) abortRef.current.abort();
        abortRef.current = new AbortController();

        setLoading(true);

        // Убираем ценовые фильтры из запроса (бэкенд тоже их убирает)
        const filtersWithoutPrice = { ...filters };
        delete filtersWithoutPrice.price_min;
        delete filtersWithoutPrice.price_max;

        const params = buildApiParams(filtersWithoutPrice);

        fetch(`${endpoint}?${params.toString()}`, {
            signal: abortRef.current.signal,
        })
            .then((r) => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.json();
            })
            .then((data) => {
                setPriceData(data);
                setLoading(false);
            })
            .catch((err) => {
                if (err.name !== 'AbortError') {
                    console.error('Ошибка загрузки ценовых интервалов:', err);
                    setLoading(false);
                }
            });

        return () => {
            if (abortRef.current) abortRef.current.abort();
        };
    }, [filtersKey, endpoint]); // eslint-disable-line react-hooks/exhaustive-deps

    return { priceData, loading };
}
