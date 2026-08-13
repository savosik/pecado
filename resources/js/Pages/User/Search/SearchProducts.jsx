import { useState, useCallback, useMemo, useRef } from 'react';
import { Box, Flex, Heading, Text } from '@chakra-ui/react';
import { LuInfo, LuSearch } from 'react-icons/lu';

import CatalogControls, { SEARCH_STOCK_OPTIONS } from '@/Pages/User/Products/CatalogControls';
import CatalogFilterSidebar from '@/Pages/User/Products/CatalogFilterSidebar';
import ProductGrid from '@/Pages/User/Products/ProductGrid';
import ProductPagination from '@/Pages/User/Products/ProductPagination';
import SelectedFilters from '@/Pages/User/Products/SelectedFilters';
import ProductFiltersSheet, { countActiveFilters } from '@/Pages/User/Products/ProductFiltersSheet';
import useCatalogFilters from '@/Pages/User/Products/hooks/useCatalogFilters';
import useCatalogProducts from '@/Pages/User/Products/hooks/useCatalogProducts';
import useCatalogFacets from '@/Pages/User/Products/hooks/useCatalogFacets';
import usePriceIntervals from '@/Pages/User/Products/hooks/usePriceIntervals';
import EmptyState from '@/components/common/EmptyState';
import { pluralGoods } from '@/utils/plural';

const DEFAULT_PER_PAGE = 20;
const LS_INFINITE_SCROLL_KEY = 'search_infinite_scroll';

/** Дефолты страницы поиска: сортировка — порядок релевантности Meilisearch. */
const SEARCH_DEFAULTS = { sort: 'relevance' };

const PRODUCTS_ENDPOINT = '/api/search/products';
const FACETS_ENDPOINT = '/api/search/products/facets';
const PRICE_INTERVALS_ENDPOINT = '/api/search/products/price-intervals';

/**
 * SearchProducts — товарная часть страницы результатов поиска.
 *
 * Полный каталожный интерфейс (фильтры, фасеты, сортировки, вид, пагинация),
 * но выборка ограничена релевантной выдачей поиска по запросу `q`.
 *
 * @param {{
 *   q: string,
 *   initialFilters?: object,
 *   sortOptions?: Array<{ value: string, label: string }>,
 *   isAuthenticated?: boolean,
 *   currencySymbol?: string,
 *   currencyCode?: string,
 * }} props
 */
export default function SearchProducts({
    q,
    initialFilters = {},
    sortOptions = [],
    isAuthenticated = false,
    currencySymbol = '₽',
    currencyCode = 'RUB',
}) {
    const {
        filters: rawFilters,
        view,
        setView,
        updateFilter,
        updateFilters,
        resetFilters,
        goToPage,
    } = useCatalogFilters({ initialFilters, defaults: SEARCH_DEFAULTS });

    // q задаётся страницей и не снимается фильтрами; currency_code — чтобы
    // хуки перезапрашивали данные при смене валюты.
    const filters = useMemo(
        () => ({ ...rawFilters, q, currency_code: currencyCode }),
        [rawFilters, q, currencyCode],
    );

    const filtersRef = useRef(filters);
    filtersRef.current = filters;

    const {
        products,
        meta,
        loading,
        loadingMore,
        error,
        loadMore,
    } = useCatalogProducts({ filters, endpoint: PRODUCTS_ENDPOINT });

    const { facets } = useCatalogFacets({ filters, endpoint: FACETS_ENDPOINT });
    const { priceData } = usePriceIntervals({ filters, endpoint: PRICE_INTERVALS_ENDPOINT });

    // ─── Infinite Scroll ───
    const [infiniteScroll, setInfiniteScroll] = useState(() => {
        try {
            return localStorage.getItem(LS_INFINITE_SCROLL_KEY) === '1';
        } catch {
            return false;
        }
    });

    const handleInfiniteScrollToggle = useCallback((enabled) => {
        setInfiniteScroll(enabled);
        try {
            if (enabled) {
                localStorage.setItem(LS_INFINITE_SCROLL_KEY, '1');
            } else {
                localStorage.removeItem(LS_INFINITE_SCROLL_KEY);
            }
        } catch {
            // localStorage недоступен
        }
    }, []);

    // ─── Мобильная шторка фильтров ───
    const [mobileFiltersOpen, setMobileFiltersOpen] = useState(false);
    // Сам запрос за фильтр не считаем — он контекст страницы, а не выбор пользователя
    const activeFilterCount = useMemo(() => countActiveFilters({ ...filters, q: undefined }), [filters]);
    const openMobileFilters = useCallback(() => setMobileFiltersOpen(true), []);
    const closeMobileFilters = useCallback(() => setMobileFiltersOpen(false), []);

    const handleStockChange = useCallback((mode) => {
        updateFilter('in_stock_mode', mode || undefined);
    }, [updateFilter]);

    const handleRemoveFilter = useCallback((key, value) => {
        const f = filtersRef.current;
        if (key === 'price_range') {
            updateFilters({ price_min: undefined, price_max: undefined });
        } else if (key === 'brand_ids' && value != null) {
            const newIds = (f.brand_ids || []).filter((id) => Number(id) !== Number(value));
            updateFilter('brand_ids', newIds.length > 0 ? newIds : undefined);
        } else if (key === 'category_ids' && value != null) {
            const newIds = (f.category_ids || []).filter((id) => Number(id) !== Number(value));
            updateFilter('category_ids', newIds.length > 0 ? newIds : undefined);
        } else if (key === 'attribute_value_ids' && value != null) {
            const newIds = (f.attribute_value_ids || []).filter((id) => Number(id) !== Number(value));
            updateFilter('attribute_value_ids', newIds.length > 0 ? newIds : undefined);
        } else if (key === 'attribute_inline_filters' && value != null) {
            const { attrId, rawValue } = value;
            const current = { ...(f.attribute_inline_filters || {}) };
            if (current[attrId]) {
                current[attrId] = current[attrId].filter((v) => v !== rawValue);
                if (current[attrId].length === 0) delete current[attrId];
            }
            updateFilter('attribute_inline_filters', Object.keys(current).length > 0 ? current : undefined);
        } else {
            updateFilter(key, undefined);
        }
    }, [updateFilter, updateFilters]);

    const sidebarContent = useMemo(() => (
        <CatalogFilterSidebar
            filters={filters}
            facets={facets}
            priceData={priceData}
            currencySymbol={currencySymbol}
            isAuthenticated={isAuthenticated}
            updateFilter={updateFilter}
            updateFilters={updateFilters}
        />
    ), [filters, facets, priceData, currencySymbol, isAuthenticated, updateFilter, updateFilters]);

    const total = meta?.total ?? null;
    const skeletonCount = filters.per_page || DEFAULT_PER_PAGE;
    const nothingFound = !loading && !error && products.length === 0;

    return (
        <Box mb="8">
            <Box px={{ base: '3', md: '0' }}>
                {/* Заголовок секции + контролы — как в каталоге */}
                <Flex align="center" justify="space-between" gap="4" flexWrap="wrap" mb="4">
                    <Flex align="baseline" gap="3" flexWrap="wrap">
                        <Heading as="h2" size={{ base: 'lg', md: 'xl' }} fontWeight="bold" color="fg">
                            Товары
                        </Heading>
                        {total != null && (
                            <Text fontSize="sm" color="gray.400" fontWeight="normal">
                                {total} {pluralGoods(total)}
                            </Text>
                        )}
                    </Flex>

                    <CatalogControls
                        sort={filters.sort}
                        view={view}
                        perPage={filters.per_page}
                        sortOptions={sortOptions}
                        onSortChange={(value) => updateFilter('sort', value)}
                        onViewChange={setView}
                        onPerPageChange={(value) => updateFilter('per_page', value)}
                        inStockMode={filters.in_stock_mode || ''}
                        onStockChange={handleStockChange}
                        stockOptions={SEARCH_STOCK_OPTIONS}
                        showStockFilter={isAuthenticated}
                        onOpenFilters={openMobileFilters}
                        activeFilterCount={activeFilterCount}
                    />
                </Flex>

                <SelectedFilters
                    filters={filters}
                    facets={facets}
                    lockedFilters={{ ...initialFilters, q }}
                    currencySymbol={currencySymbol}
                    onRemoveFilter={handleRemoveFilter}
                    onResetAll={resetFilters}
                />

                {/* Точного совпадения нет — показаны похожие товары */}
                {!loading && products.length > 0 && meta?.no_exact_match && (
                    <Flex
                        align="flex-start"
                        gap="3"
                        p="4"
                        mb="4"
                        bg="orange.50"
                        borderLeft="4px solid"
                        borderColor="orange.400"
                        borderRadius="md"
                        _dark={{ bg: 'orange.900/30', borderColor: 'orange.300' }}
                    >
                        <Box color="orange.500" mt="0.5" _dark={{ color: 'orange.300' }}>
                            <LuInfo size={20} />
                        </Box>
                        <Text fontWeight="semibold" color="fg">
                            Точного совпадения по запросу «{q}» не найдено
                        </Text>
                    </Flex>
                )}

                {/* Выдача упёрлась в потолок релевантных совпадений */}
                {!loading && meta?.capped && (
                    <Text fontSize="xs" color="fg.muted" mb="3">
                        Показаны только самые релевантные товары — уточните запрос или сузьте фильтры.
                    </Text>
                )}
            </Box>

            <Flex gap="6">
                {/* Сайдбар фильтров (десктоп) */}
                <Box display={{ base: 'none', lg: 'block' }} w="260px" flexShrink="0">
                    {sidebarContent}
                </Box>

                <Box flex="1" minW="0">
                    {nothingFound ? (
                        <Box px={{ base: '3', md: '0' }}>
                            <EmptyState
                                icon={LuSearch}
                                title="Товары не найдены"
                                description={
                                    activeFilterCount > 0
                                        ? 'Попробуйте снять часть фильтров или изменить запрос'
                                        : 'Попробуйте изменить запрос или использовать другие ключевые слова'
                                }
                                action={activeFilterCount > 0
                                    ? { label: 'Сбросить фильтры', onClick: resetFilters }
                                    : undefined}
                            />
                        </Box>
                    ) : (
                        <>
                            <ProductGrid
                                products={products}
                                view={view}
                                loading={loading}
                                error={error}
                                skeletonCount={skeletonCount}
                            />

                            {!loading && !error && products.length > 0 && meta && (
                                <Box px={{ base: '3', md: '0' }}>
                                    <ProductPagination
                                        meta={meta}
                                        onPageChange={goToPage}
                                        onLoadMore={loadMore}
                                        loadingMore={loadingMore}
                                        infiniteScroll={infiniteScroll}
                                        onInfiniteScrollToggle={handleInfiniteScrollToggle}
                                    />
                                </Box>
                            )}
                        </>
                    )}
                </Box>
            </Flex>

            {/* Шторка фильтров (мобила) */}
            <ProductFiltersSheet
                open={mobileFiltersOpen}
                onClose={closeMobileFilters}
                totalProducts={total}
                activeCount={activeFilterCount}
                onResetAll={resetFilters}
            >
                {sidebarContent}
            </ProductFiltersSheet>
        </Box>
    );
}
