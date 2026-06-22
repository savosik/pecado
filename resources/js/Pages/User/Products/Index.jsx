import { useState, useCallback, useMemo, useRef } from 'react';
import { Box, Flex, Heading } from '@chakra-ui/react';
import { usePage } from '@inertiajs/react';
import UserLayout from '../UserLayout';
import SeoHead from '@/components/common/SeoHead';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import ProductBreadcrumbs from '@/components/product/ProductBreadcrumbs';
import CatalogHeader from './CatalogHeader';
import SeoTextBlock from '@/components/common/SeoTextBlock';
import CategoryChildrenChips from './CategoryChildrenChips';
import CatalogControls from './CatalogControls';
import SelectedFilters from './SelectedFilters';
import ProductGrid from './ProductGrid';
import ProductPagination from './ProductPagination';
import ProductFiltersSheet, { countActiveFilters } from './ProductFiltersSheet';
import useCatalogFilters from './hooks/useCatalogFilters';
import useCatalogProducts from './hooks/useCatalogProducts';
import usePriceIntervals from './hooks/usePriceIntervals';
import useCatalogFacets from './hooks/useCatalogFacets';

// Фильтры
import CollapsibleFilterCard from './filters/CollapsibleFilterCard';
import PriceFilter from './filters/PriceFilter';
import CategoryFilter from './filters/CategoryFilter';
import BrandFilter from './filters/BrandFilter';
import AttributeFilters from './filters/AttributeFilters';

const DEFAULT_PER_PAGE = 20;
const LS_INFINITE_SCROLL_KEY = 'catalog_infinite_scroll';
const LS_ATTRIBUTES_OPEN_KEY = 'catalog_attributes_open';

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
        categoryTrail = null,
        categoryChildren = null,
        categorySiblings = null,
        sortOptions = [],
        appName = 'Pecado',
        pageDescription = null,
        pageIntro = null,
        auth,
        currency,
    } = usePage().props;

    // Категорийный листинг: короткий интро вверху, полный SEO-текст — блоком внизу.
    const isCategoryListing = !!(categoryTrail && categoryTrail.length > 0);

    const currencySymbol = currency?.symbol || '₽';
    const currencyCode = currency?.code || 'RUB';

    // Контекст страницы: скрываем фильтры, заданные контекстом
    const isAuthenticated = !!auth?.user;
    const isBrandPage = !!(initialFilters.brand_ids?.length);
    const isCategoryPage = !!(initialFilters.category_ids?.length || initialFilters.category_id);

    // ─── Хуки управления состоянием ───
    const {
        filters: rawFilters,
        view,
        setView,
        updateFilter,
        updateFilters,
        resetFilters,
        goToPage,
    } = useCatalogFilters({ initialFilters });

    // Добавляем currency_code в фильтры, чтобы API-хуки перезапрашивали данные при смене валюты
    const filters = useMemo(() => ({ ...rawFilters, currency_code: currencyCode }), [rawFilters, currencyCode]);

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

    const handleInlineAttributeChange = useCallback((inlineFilters) => {
        updateFilter('attribute_inline_filters', inlineFilters);
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

    const handleResetAll = useCallback(() => {
        resetFilters();
    }, [resetFilters]);

    // ─── Кол-во skeleton-заглушек ───
    const skeletonCount = filters.per_page || DEFAULT_PER_PAGE;

    // ─── Sidebar content (мемоизация для предотвращения лишних ререндеров) ───
    const sidebarContent = useMemo(() => (
        <Flex direction="column" gap="2">
            {!isCategoryPage && facets?.categories && facets.categories.length > 0 && (
                <CollapsibleFilterCard title="Категории" storageKey="catalog_filter_categories_open" defaultOpen>
                    <CategoryFilter
                        categories={facets.categories}
                        selectedIds={filters.category_ids || []}
                        onChange={handleCategoriesChange}
                    />
                </CollapsibleFilterCard>
            )}

            {!isBrandPage && facets?.brands && facets.brands.length > 0 && (
                <CollapsibleFilterCard title="Бренды" storageKey="catalog_filter_brands_open" defaultOpen>
                    <BrandFilter
                        brands={facets.brands}
                        selectedIds={filters.brand_ids || []}
                        onChange={handleBrandsChange}
                    />
                </CollapsibleFilterCard>
            )}

            {isAuthenticated && (
                <CollapsibleFilterCard title="Цена" storageKey="catalog_filter_price_open" defaultOpen>
                    <PriceFilter
                        priceMin={filters.price_min || ''}
                        priceMax={filters.price_max || ''}
                        priceData={priceData}
                        currencySymbol={currencySymbol}
                        onPriceChange={handlePriceChange}
                    />
                </CollapsibleFilterCard>
            )}

            {facets?.attributes && facets.attributes.length > 0 && (
                <CollapsibleFilterCard title="Характеристики" storageKey={LS_ATTRIBUTES_OPEN_KEY}>
                    <AttributeFilters
                        attributes={facets.attributes}
                        selectedValueIds={filters.attribute_value_ids || []}
                        selectedInlineFilters={filters.attribute_inline_filters || {}}
                        onChange={handleAttributeValuesChange}
                        onInlineChange={handleInlineAttributeChange}
                    />
                </CollapsibleFilterCard>
            )}
        </Flex>
    ), [filters, facets, priceData, isAuthenticated, isBrandPage, isCategoryPage, currencySymbol, handleCategoriesChange, handleBrandsChange, handlePriceChange, handleAttributeValuesChange, handleInlineAttributeChange]);

    // ─── Динамический SEO (поисковый запрос → title, noindex для фильтров) ───
    const dynamicSeo = useMemo(() => {
        const base = { ...seo };
        if (filters.q) {
            base.title = `Поиск: ${filters.q} — ${appName}`;
        }
        const isFiltered = !!(
            filters.price_min ||
            filters.price_max ||
            (filters.brand_ids?.length && !isBrandPage) ||
            (filters.category_ids?.length && !isCategoryPage) ||
            filters.attribute_value_ids?.length ||
            (filters.attribute_inline_filters && Object.keys(filters.attribute_inline_filters).length > 0) ||
            filters.in_stock_mode
        );
        if (isFiltered) {
            base.robots = 'noindex, follow';
        }
        return base;
    }, [seo, filters, appName, isBrandPage, isCategoryPage]);

    return (
        <UserLayout fluid>
            <SeoHead seo={dynamicSeo} />

            {/* Верхний блок: breadcrumbs, заголовок, controls, чипы — с боковыми отступами на мобиле */}
            <Box px={{ base: '3', md: '0' }}>
                {/* Хлебные крошки: на страницах категорий — с siblings dropdown */}
                {categoryTrail && categoryTrail.length > 0 ? (
                    <ProductBreadcrumbs
                        categoryTrail={categoryTrail}
                        productName={seo.h1 || ''}
                    />
                ) : breadcrumbs && breadcrumbs.length > 0 ? (
                    // Бренд-листинг: BreadcrumbList отдаётся сервером — клиентский дубль отключаем.
                    <Breadcrumbs items={breadcrumbs} jsonLd={false} />
                ) : null}

                {/* Заголовок + Контролы в одну строку */}
                <Flex align="center" justify="space-between" gap="4" flexWrap="wrap" mb="4">
                    <CatalogHeader
                        h1={seo.h1 || 'Каталог товаров'}
                        total={meta?.total ?? null}
                        description={isCategoryListing ? pageIntro : (isBrandPage ? undefined : (pageDescription || (!breadcrumbs ? seo.description : undefined)))}
                    />

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
                        showStockFilter={isAuthenticated}
                        onOpenFilters={openMobileFilters}
                        activeFilterCount={activeFilterCount}
                    />
                </Flex>

                {/* Подкатегории — компактный ряд чипов под H1 */}
                {categoryChildren && categoryChildren.length > 0 && (
                    <CategoryChildrenChips categories={categoryChildren} />
                )}

                {/* Выбранные фильтры (чипы) */}
                <SelectedFilters
                    filters={filters}
                    facets={facets}
                    lockedFilters={initialFilters}
                    currencySymbol={currencySymbol}
                    onRemoveFilter={handleRemoveFilter}
                    onResetAll={handleResetAll}
                />
            </Box>

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
                </Box>
            </Flex>

            {/* SEO-текст категории/бренда (полный) — посадочное место для текстов волн */}
            {(isCategoryListing || isBrandPage) && pageDescription && (
                <Box px={{ base: '3', md: '0' }}>
                    <SeoTextBlock html={pageDescription} title={seo.h1} />
                </Box>
            )}

            {/* Смежные категории — внутренняя перелинковка (F07) */}
            {isCategoryListing && categorySiblings && categorySiblings.length > 0 && (
                <Box mt="8" px={{ base: '3', md: '0' }}>
                    <Heading as="h2" size="md" mb="3" color="fg">
                        Смежные категории
                    </Heading>
                    <CategoryChildrenChips categories={categorySiblings} />
                </Box>
            )}

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
