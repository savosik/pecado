import { Box, Button, Flex, Grid, GridItem, Heading, Stack } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import ProductCard from './ProductCard';
import ProductListItem from '@/Pages/User/Products/ProductListItem';

/**
 * Блок товаров (горизонтальная сетка) — для новинок, бестселлеров и т.п.
 */
export default function ProductGrid({
    title,
    products = [],
    linkUrl,
    linkText = 'Смотреть все',
    maxItems = 5,
    columns = 5,
}) {
    if (!products || products.length === 0) return null;

    const visible = products.slice(0, maxItems);
    const isOdd = visible.length % 2 !== 0;

    return (
        <Box
            as="section"
            bg="bg"
            borderRadius={{ md: 'lg' }}
            borderTopWidth="1px"
            borderBottomWidth="1px"
            borderLeftWidth={{ base: '0', md: '1px' }}
            borderRightWidth={{ base: '0', md: '1px' }}
            borderStyle="solid"
            borderColor="border.muted"
            overflow="hidden"
        >
            {/* Заголовок */}
            <Box
                px={{ base: '3', md: '6' }}
                pt={{ base: '4', md: '5' }}
                pb={{ base: '2', md: '3' }}
            >
                <Heading as="h2" size={{ base: 'md', md: 'lg' }} fontWeight="bold" color="fg">
                    {title}
                </Heading>
            </Box>

            {/* Сетка товаров: на base — стек list-items, на md+ — grid с карточками */}
            <Box px={{ base: '0', md: '6' }} pb={{ base: '4', md: '5' }}>
                {/* List view (base) */}
                <Stack display={{ base: 'flex', md: 'none' }} gap="2">
                    {visible.map((product) => (
                        <ProductListItem key={product.id} product={product} />
                    ))}
                </Stack>

                {/* Grid view (md+) */}
                <Grid
                    display={{ base: 'none', md: 'grid' }}
                    templateColumns={{
                        md: 'repeat(3, minmax(0, 1fr))',
                        xl: `repeat(${columns}, minmax(0, 1fr))`,
                    }}
                    gap={{ md: '4' }}
                >
                    {visible.map((product, index) => (
                        <GridItem
                            key={product.id}
                            h="100%"
                            overflow="hidden"
                            display={
                                isOdd && index === visible.length - 1
                                    ? { md: 'block' }
                                    : undefined
                            }
                        >
                            <ProductCard product={product} />
                        </GridItem>
                    ))}
                </Grid>

                {/* Кнопка «Смотреть все» внизу по центру */}
                {linkUrl && (
                    <Flex justifyContent="center" mt="5">
                        <Button
                            as={Link}
                            href={linkUrl}
                            variant="outline"
                            size="sm"
                            borderColor="gray.300"
                            color="gray.600"
                            _dark={{ borderColor: 'gray.600', color: 'gray.300' }}
                            _hover={{ bg: 'gray.50', _dark: { bg: 'gray.700' } }}
                        >
                            {linkText}
                        </Button>
                    </Flex>
                )}
            </Box>
        </Box>
    );
}
