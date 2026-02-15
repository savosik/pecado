import { Box, Flex, Grid, GridItem, Text } from '@chakra-ui/react';
import { LuSearchX, LuTriangleAlert } from 'react-icons/lu';
import ProductGridItem from './ProductGridItem';
import ProductListItem from './ProductListItem';

/**
 * ProductGrid — компонент-обёртка отображения товаров в каталоге.
 *
 * Управляет переключением между grid/list отображением,
 * skeleton-заглушками при загрузке, empty state и ошибками.
 *
 * @param {{
 *   products: Array,
 *   view: 'grid' | 'list',
 *   loading: boolean,
 *   error: string | null,
 *   skeletonCount?: number,
 * }} props
 */
export default function ProductGrid({
    products = [],
    view = 'grid',
    loading = false,
    error = null,
    skeletonCount = 12,
}) {
    // ─── Skeleton ───
    if (loading) {
        const count = Math.min(skeletonCount, 12);

        if (view === 'list') {
            return (
                <Flex direction="column" gap={{ base: '3', md: '4' }}>
                    {Array.from({ length: count }).map((_, i) => (
                        <ProductListItem key={`skeleton-list-${i}`} loading />
                    ))}
                </Flex>
            );
        }

        return (
            <Grid
                templateColumns={{
                    base: 'repeat(2, 1fr)',
                    md: 'repeat(3, 1fr)',
                    xl: 'repeat(4, 1fr)',
                }}
                gap={{ base: '3', md: '4' }}
            >
                {Array.from({ length: count }).map((_, i) => (
                    <GridItem key={`skeleton-grid-${i}`}>
                        <ProductGridItem loading />
                    </GridItem>
                ))}
            </Grid>
        );
    }

    // ─── Error state ───
    if (error) {
        return (
            <Flex
                direction="column"
                align="center"
                justify="center"
                py="16"
                gap="3"
            >
                <LuTriangleAlert size={48} color="var(--chakra-colors-red-400)" />
                <Text fontSize="lg" fontWeight="600" color="red.500">
                    Ошибка загрузки
                </Text>
                <Text fontSize="sm" color="gray.400" textAlign="center">
                    {error}
                </Text>
            </Flex>
        );
    }

    // ─── Empty state ───
    if (products.length === 0) {
        return (
            <Flex
                direction="column"
                align="center"
                justify="center"
                py="16"
                gap="3"
            >
                <LuSearchX size={48} color="var(--chakra-colors-gray-300)" />
                <Text fontSize="lg" fontWeight="600" color="gray.500">
                    Ничего не найдено
                </Text>
                <Text fontSize="sm" color="gray.400" textAlign="center">
                    Попробуйте изменить параметры поиска
                </Text>
            </Flex>
        );
    }

    // ─── List view ───
    if (view === 'list') {
        return (
            <Flex direction="column" gap={{ base: '3', md: '4' }}>
                {products.map((product) => (
                    <ProductListItem key={product.id} product={product} />
                ))}
            </Flex>
        );
    }

    // ─── Grid view (default) ───
    return (
        <Grid
            templateColumns={{
                base: 'repeat(2, 1fr)',
                md: 'repeat(3, 1fr)',
                xl: 'repeat(4, 1fr)',
            }}
            gap={{ base: '3', md: '4' }}
        >
            {products.map((product) => (
                <GridItem key={product.id}>
                    <ProductGridItem product={product} />
                </GridItem>
            ))}
        </Grid>
    );
}
