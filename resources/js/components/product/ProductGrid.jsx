import { Box, Button, Flex, Grid, GridItem, Heading } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import ProductCard from './ProductCard';

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
            bg="white"
            _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}
            borderRadius={{ md: 'lg' }}
            border="1px solid"
            borderColor="gray.100"
            overflow="hidden"
        >
            {/* Заголовок */}
            <Box
                px={{ base: '4', md: '6' }}
                pt={{ base: '4', md: '5' }}
                pb={{ base: '2', md: '3' }}
            >
                <Heading as="h2" fontSize={{ base: 'lg', md: 'xl' }} fontWeight="700">
                    {title}
                </Heading>
            </Box>

            {/* Сетка товаров */}
            <Box px={{ base: '4', md: '6' }} pb={{ base: '4', md: '5' }}>
                <Grid
                    templateColumns={{
                        base: 'repeat(2, 1fr)',
                        md: 'repeat(3, 1fr)',
                        xl: `repeat(${columns}, 1fr)`,
                    }}
                    gap={{ base: '3', md: '4' }}
                >
                    {visible.map((product, index) => (
                        <GridItem
                            key={product.id}
                            h="100%"
                            display={
                                isOdd && index === visible.length - 1
                                    ? { base: 'none', md: 'block' }
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
