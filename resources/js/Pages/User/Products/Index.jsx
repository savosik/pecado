import { useState, useCallback } from 'react';
import {
    Box, Flex, Text,
} from '@chakra-ui/react';
import { Head, usePage } from '@inertiajs/react';
import UserLayout from '../UserLayout';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import CatalogHeader from './CatalogHeader';
import CatalogControls from './CatalogControls';
import ProductGrid from './ProductGrid';
import ProductPagination from './ProductPagination';
import useCatalogFilters from './hooks/useCatalogFilters';
import useCatalogProducts from './hooks/useCatalogProducts';

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
    } = usePage().props;

    // ─── Хуки управления состоянием ───
    const {
        filters,
        view,
        setView,
        updateFilter,
        goToPage,
    } = useCatalogFilters({ initialFilters });

    const {
        products,
        meta,
        loading,
        loadingMore,
        error,
        loadMore,
    } = useCatalogProducts({ filters });

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

    // ─── Кол-во skeleton-заглушек ───
    const skeletonCount = filters.per_page || DEFAULT_PER_PAGE;

    return (
        <UserLayout>
            <Head title={seo.title || 'Каталог товаров'}>
                {seo.description && <meta name="description" content={seo.description} />}
            </Head>

            {/* Хлебные крошки */}
            {breadcrumbs && breadcrumbs.length > 0 && (
                <Breadcrumbs items={breadcrumbs} />
            )}

            {/* Заголовок */}
            <CatalogHeader
                h1={seo.h1 || 'Каталог товаров'}
                total={meta?.total ?? null}
                description={!breadcrumbs ? seo.description : undefined}
            />

            {/* Контролы: сортировка + вид + per_page */}
            <CatalogControls
                sort={filters.sort}
                view={view}
                perPage={filters.per_page}
                sortOptions={sortOptions}
                onSortChange={(value) => updateFilter('sort', value)}
                onViewChange={setView}
                onPerPageChange={(value) => updateFilter('per_page', value)}
            />

            {/* Контент */}
            <Flex gap="6">
                {/* Desktop Sidebar — placeholder для будущих фильтров (FE-06/07) */}
                <Box
                    display={{ base: 'none', lg: 'block' }}
                    w="240px"
                    flexShrink="0"
                >
                    <Box
                        bg="white"
                        borderRadius="xl"
                        border="1px solid"
                        borderColor="gray.100"
                        _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}
                        p="4"
                    >
                        <Text fontSize="sm" fontWeight="700" mb="2">
                            Фильтры
                        </Text>
                        <Text fontSize="xs" color="gray.400">
                            Фильтры будут добавлены в следующих спринтах
                        </Text>
                    </Box>
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
        </UserLayout>
    );
}
