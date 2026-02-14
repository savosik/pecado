import { Head } from '@inertiajs/react';
import { Box, Grid, GridItem, Heading, Text, Flex } from '@chakra-ui/react';
import { LuHeart } from 'react-icons/lu';
import UserLayout from '../UserLayout';
import ProductCard from '@/components/product/ProductCard';
import Pagination from '@/components/common/Pagination';
import EmptyState from '@/components/common/EmptyState';
import usePagination from '@/hooks/usePagination';
import { pluralize } from '@/utils/pluralize';

/**
 * Страница «Избранное» — отображает список избранных товаров пользователя.
 */
export default function FavoritesIndex({ favorites }) {
    const pagination = usePagination(favorites);
    const { items, currentPage, lastPage, total, pageNumbers, onPageChange } = pagination;

    return (
        <UserLayout>
            <Head title="Избранное" />

            <Box spaceY="6">
                {/* Заголовок */}
                <Box>
                    <Flex align="baseline" gap="2">
                        <Heading as="h1" fontSize={{ base: 'xl', md: '2xl' }} fontWeight="700">
                            Избранное
                        </Heading>
                        {total > 0 && (
                            <Text fontSize="sm" color="gray.500">
                                {total} {pluralize(total, 'товар', 'товара', 'товаров')}
                            </Text>
                        )}
                    </Flex>
                </Box>

                {/* Товары или пустое состояние */}
                {items.length > 0 ? (
                    <>
                        <Grid
                            templateColumns={{
                                base: 'repeat(2, 1fr)',
                                md: 'repeat(3, 1fr)',
                                lg: 'repeat(4, 1fr)',
                                xl: 'repeat(5, 1fr)',
                            }}
                            gap={{ base: '3', md: '4' }}
                        >
                            {items.map((product) => (
                                <GridItem key={product.id} h="100%">
                                    <ProductCard product={product} />
                                </GridItem>
                            ))}
                        </Grid>

                        <Pagination
                            currentPage={currentPage}
                            lastPage={lastPage}
                            pageNumbers={pageNumbers}
                            onPageChange={onPageChange}
                            total={total}
                        />
                    </>
                ) : (
                    <EmptyState
                        icon={LuHeart}
                        title="Список избранного пуст"
                        description="Добавляйте товары в избранное, нажимая на ❤ на карточке товара"
                        action={{ label: 'Перейти в каталог', href: '/' }}
                    />
                )}
            </Box>
        </UserLayout>
    );
}
