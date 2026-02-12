/**
 * Хук-обёртка над Laravel-пагинацией для Inertia.
 *
 * Использование:
 *   const pagination = usePagination(products);  // products — результат paginate() из Laravel
 *   <ProductGrid items={pagination.items} />
 *   <Pagination
 *     currentPage={pagination.currentPage}
 *     lastPage={pagination.lastPage}
 *     onPageChange={pagination.onPageChange}
 *   />
 */

import { useMemo, useCallback } from 'react';
import { router } from '@inertiajs/react';

/**
 * @param {object} paginationData — объект Laravel paginate()
 * @param {object} [options]
 * @param {string} [options.paramName='page'] — имя query-параметра
 * @param {boolean} [options.preserveState=true] — сохранять состояние при навигации
 * @param {boolean} [options.preserveScroll=false] — сохранять позицию скролла
 * @param {string} [options.only] — загрузить только указанный prop (partial reload)
 */
export default function usePagination(paginationData, options = {}) {
    const {
        paramName = 'page',
        preserveState = true,
        preserveScroll = false,
        only,
    } = options;

    const items = paginationData?.data ?? [];
    const currentPage = paginationData?.current_page ?? 1;
    const lastPage = paginationData?.last_page ?? 1;
    const total = paginationData?.total ?? 0;
    const perPage = paginationData?.per_page ?? 15;
    const links = paginationData?.links ?? [];

    const isFirstPage = currentPage <= 1;
    const isLastPage = currentPage >= lastPage;

    /** Перейти на указанную страницу */
    const onPageChange = useCallback((page) => {
        if (page < 1 || page > lastPage || page === currentPage) return;

        const url = new URL(window.location.href);
        url.searchParams.set(paramName, String(page));

        const visitOptions = {
            preserveState,
            preserveScroll,
        };

        if (only) {
            visitOptions.only = Array.isArray(only) ? only : [only];
        }

        router.visit(url.toString(), visitOptions);
    }, [lastPage, currentPage, paramName, preserveState, preserveScroll, only]);

    /** Массив номеров страниц для рендеринга (с многоточием) */
    const pageNumbers = useMemo(() => {
        if (lastPage <= 7) {
            return Array.from({ length: lastPage }, (_, i) => i + 1);
        }

        const pages = new Set([1, lastPage]);
        for (let i = Math.max(2, currentPage - 1); i <= Math.min(lastPage - 1, currentPage + 1); i++) {
            pages.add(i);
        }

        const sorted = [...pages].sort((a, b) => a - b);
        const result = [];

        for (let i = 0; i < sorted.length; i++) {
            if (i > 0 && sorted[i] - sorted[i - 1] > 1) {
                result.push('...');
            }
            result.push(sorted[i]);
        }

        return result;
    }, [currentPage, lastPage]);

    return {
        items,
        currentPage,
        lastPage,
        total,
        perPage,
        links,
        isFirstPage,
        isLastPage,
        pageNumbers,
        onPageChange,
    };
}
