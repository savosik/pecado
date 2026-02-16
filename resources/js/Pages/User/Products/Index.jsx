import { useState, useCallback, useMemo, useRef } from 'react';
import {
    Box, Button, Flex, Icon,
} from '@chakra-ui/react';
import { LuSlidersHorizontal } from 'react-icons/lu';
import { usePage } from '@inertiajs/react';
import UserLayout from '../UserLayout';
import SeoHead from '@/components/common/SeoHead';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import CatalogHeader from './CatalogHeader';
import CatalogControls from './CatalogControls';
import SelectedFilters from './SelectedFilters';
import ProductGrid from './ProductGrid';
import ProductPagination from './ProductPagination';
import ProductFiltersSheet, { countActiveFilters, FilterBadge } from './ProductFiltersSheet';
import useCatalogFilters from './hooks/useCatalogFilters';
import useCatalogProducts from './hooks/useCatalogProducts';
import usePriceIntervals from './hooks/usePriceIntervals';
import useCatalogFacets from './hooks/useCatalogFacets';

// Фильтры
import FilterBlock from './filters/FilterBlock';
import SearchFilter from './filters/SearchFilter';
import PriceFilter from './filters/PriceFilter';
import StockFilter from './filters/StockFilter';
import CategoryFilter from './filters/CategoryFilter';
import BrandFilter from './filters/BrandFilter';
import AttributeFilters from './filters/AttributeFilters';

const DEFAULT_PER_PAGE = 20;
const LS_INFINITE_SCROLL_KEY = 'catalog_infinite_scroll';

/**
 * Index — единая Inertia-страница каталога товаров.
 *
 * Принимает props: seo, initialFilters, breadcrumbs, brand, category, selection, sortOptions
 */
export default function Index() {
    const {
        seo = {},
        initialFilters = {},
        breadcrumbs = null,
        sortOptions = [],
        appName = 'Pecado',
        pageDescription = null,
        auth,
    } = usePage().props;

    // Контекст страницы: скрываем фильтры, заданные контекстом
    const isAuthenticated = !!auth?.user;
    const isBrandPage = !!(initialFilters.brand_ids?.length);
    const isCategoryPage = !!(initialFilters.category_ids?.length || initialFilters.category_id);

    // ─── Хуки управления состоянием ───
    const {
        filters,
        view,
        setView,
        updateFilter,
        updateFilters,
        resetFilters,
        goToPage,
    } = useCatalogFilters({ initialFilters });

    // Ref для доступа к актуальным фильтрам без пересоздания коллбэков
    const filtersRef = useRef(filters);
    filtersRef.current = filters;

    const {
        products,
        meta,
        loading,
        loadingMore,
        error,
        loadMore,
    } = useCatalogProducts({ filters });

    const { priceData } = usePriceIntervals({ filters });
    const { facets } = useCatalogFacets({ filters });

    // ─── Infinite Scroll ───
    const [infiniteScroll, setInfiniteScroll] = useState(() => {
        try {
            return localStorage.getItem(LS_INFINITE_SCROLL_KEY) === '1';
        } catch {
            return false;
        }
    });

    // ─── Mobile Filters Drawer ───
    const [mobileFiltersOpen, setMobileFiltersOpen] = useState(false);
    const activeFilterCount = useMemo(() => countActiveFilters(filters), [filters]);
    const openMobileFilters = useCallback(() => setMobileFiltersOpen(true), []);
    const closeMobileFilters = useCallback(() => setMobileFiltersOpen(false), []);

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

    // ─── Обработчики фильтров ───
    const handleSearchChange = useCallback((q) => {
        updateFilter('q', q || undefined);
    }, [updateFilter]);

    const handlePriceChange = useCallback((min, max) => {
        updateFilters({ price_min: min || undefined, price_max: max || undefined });
    }, [updateFilters]);

    const handleStockChange = useCallback((mode) => {
        if (mode) {
            updateFilter('in_stock_mode', mode);
        } else {
            // «Все» — убираем фильтр наличия
            updateFilter('in_stock_mode', undefined);
        }
    }, [updateFilter]);

    const handleCategoriesChange = useCallback((ids) => {
        updateFilter('category_ids', ids.length > 0 ? ids : undefined);
    }, [updateFilter]);

    const handleBrandsChange = useCallback((ids) => {
        updateFilter('brand_ids', ids.length > 0 ? ids : undefined);
    }, [updateFilter]);

    const handleAttributeValuesChange = useCallback((ids) => {
        updateFilter('attribute_value_ids', ids.length > 0 ? ids : undefined);
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
        } else {
            updateFilter(key, undefined);
        }
    }, [updateFilter, updateFilters]);

    const handleResetAll = useCallback(() => {
        resetFilters();
    }, [resetFilters]);

    // ─── Кол-во skeleton-заглушек ───
    const skeletonCount = filters.per_page || DEFAULT_PER_PAGE;

    // ─── Sidebar content (мемоизация для предотвращения лишних ререндеров) ───
    const sidebarContent = useMemo(() => (
        <Box
            bg="white"
            borderRadius="xl"
            border="1px solid"
            borderColor="gray.100"
            _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}
            p="4"
        >
            {/* Поиск */}
            <FilterBlock
                title="Поиск"
                showClear={!!filters.q}
                onClear={() => handleSearchChange('')}
            >
                <SearchFilter
                    value={filters.q || ''}
                    onChange={handleSearchChange}
                />
            </FilterBlock>

            {/* Разделитель после «Поиск» — только если далее есть видимый блок */}
            {((!isCategoryPage && facets?.categories?.length > 0) || (!isBrandPage && facets?.brands?.length > 0) || isAuthenticated) && (
                <Box h="1px" bg="gray.100" _dark={{ bg: 'gray.700' }} my="1" />
            )}

            {/* Категории — скрываем на страницах конкретной категории */}
            {!isCategoryPage && facets?.categories && facets.categories.length > 0 && (
                <>
                    <FilterBlock
                        title="Категории"
                        showClear={!!(filters.category_ids?.length)}
                        onClear={() => handleCategoriesChange([])}
                    >
                        <CategoryFilter
                            categories={facets.categories}
                            selectedIds={filters.category_ids || []}
                            onChange={handleCategoriesChange}
                        />
                    </FilterBlock>
                    <Box h="1px" bg="gray.100" _dark={{ bg: 'gray.700' }} my="1" />
                </>
            )}

            {/* Бренды — скрываем на страницах конкретного бренда */}
            {!isBrandPage && facets?.brands && facets.brands.length > 0 && (
                <>
                    <FilterBlock
                        title="Бренды"
                        showClear={!!(filters.brand_ids?.length)}
                        onClear={() => handleBrandsChange([])}
                    >
                        <BrandFilter
                            brands={facets.brands}
                            selectedIds={filters.brand_ids || []}
                            onChange={handleBrandsChange}
                        />
                    </FilterBlock>
                    <Box h="1px" bg="gray.100" _dark={{ bg: 'gray.700' }} my="1" />
                </>
            )}

            {/* Цена — только для авторизованных */}
            {isAuthenticated && (
                <>
                    <FilterBlock
                        title="Цена"
                        showClear={!!(filters.price_min || filters.price_max)}
                        onClear={() => handlePriceChange('', '')}
                    >
                        <PriceFilter
                            priceMin={filters.price_min || ''}
                            priceMax={filters.price_max || ''}
                            priceData={priceData}
                            onPriceChange={handlePriceChange}
                        />
                    </FilterBlock>

                    <Box h="1px" bg="gray.100" _dark={{ bg: 'gray.700' }} my="1" />
                </>
            )}

            {/* Наличие — только для авторизованных */}
            {isAuthenticated && (
                <FilterBlock
                    title="Наличие"
                    showClear={!!filters.in_stock_mode}
                    onClear={() => handleStockChange('')}
                >
                    <StockFilter
                        value={filters.in_stock_mode || ''}
                        onChange={handleStockChange}
                    />
                </FilterBlock>
            )}

            {/* Атрибуты (динамические блоки) */}
            {facets?.attributes && facets.attributes.length > 0 && (
                <AttributeFilters
                    attributes={facets.attributes}
                    selectedValueIds={filters.attribute_value_ids || []}
                    onChange={handleAttributeValuesChange}
                />
            )}
        </Box>
    ), [filters, facets, priceData, isAuthenticated, isBrandPage, isCategoryPage, handleSearchChange, handleCategoriesChange, handleBrandsChange, handlePriceChange, handleStockChange, handleAttributeValuesChange]);

    // ─── Динамический SEO (поисковый запрос → title) ───
    const dynamicSeo = useMemo(() => {
        const base = { ...seo };
        if (filters.q) {
            base.title = `Поиск: ${filters.q} — ${appName}`;
        }
        return base;
    }, [seo, filters.q, appName]);

    return (
        <UserLayout>
            <SeoHead seo={dynamicSeo} />

            {/* Хлебные крошки */}
            {breadcrumbs && breadcrumbs.length > 0 && (
                <Breadcrumbs items={breadcrumbs} />
            )}

            {/* Заголовок + Контролы в одну строку */}
            <Flex align="center" justify="space-between" gap="4" flexWrap="wrap" mb="4">
                <CatalogHeader
                    h1={seo.h1 || 'Каталог товаров'}
                    total={meta?.total ?? null}
                    description={pageDescription || (!breadcrumbs ? seo.description : undefined)}
                />

                <Flex gap="2" align="center" flexWrap="wrap">
                    {/* Кнопка «Фильтры» — только мобильные */}
                    <Button
                        display={{ base: 'inline-flex', lg: 'none' }}
                        variant="outline"
                        size="sm"
                        onClick={openMobileFilters}
                        colorPalette="pink"
                    >
                        <Icon as={LuSlidersHorizontal} boxSize="4" />
                        Фильтры
                        <FilterBadge count={activeFilterCount} ml="1" />
                    </Button>

                    <CatalogControls
                        sort={filters.sort}
                        view={view}
                        perPage={filters.per_page}
                        sortOptions={sortOptions}
                        onSortChange={(value) => updateFilter('sort', value)}
                        onViewChange={setView}
                        onPerPageChange={(value) => updateFilter('per_page', value)}
                    />
                </Flex>
            </Flex>

            {/* Выбранные фильтры (чипы) */}
            <SelectedFilters
                filters={filters}
                facets={facets}
                lockedFilters={initialFilters}
                onRemoveFilter={handleRemoveFilter}
                onResetAll={handleResetAll}
            />

            {/* Контент */}
            <Flex gap="6">
                {/* Desktop Sidebar с фильтрами */}
                <Box
                    display={{ base: 'none', lg: 'block' }}
                    w="260px"
                    flexShrink="0"
                >
                    {sidebarContent}
                </Box>

                {/* Сетка товаров */}
                <Box flex="1" minW="0">
                    <ProductGrid
                        products={products}
                        view={view}
                        loading={loading}
                        error={error}
                        skeletonCount={skeletonCount}
                    />

                    {/* Пагинация */}
                    {!loading && !error && products.length > 0 && meta && (
                        <ProductPagination
                            meta={meta}
                            onPageChange={goToPage}
                            onLoadMore={loadMore}
                            loadingMore={loadingMore}
                            infiniteScroll={infiniteScroll}
                            onInfiniteScrollToggle={handleInfiniteScrollToggle}
                        />
                    )}
                </Box>
            </Flex>

            {/* Mobile Filters Drawer */}
            <ProductFiltersSheet
                open={mobileFiltersOpen}
                onClose={closeMobileFilters}
                totalProducts={meta?.total ?? null}
                activeCount={activeFilterCount}
                onResetAll={handleResetAll}
            >
                {sidebarContent}
            </ProductFiltersSheet>
        </UserLayout>
    );
}
