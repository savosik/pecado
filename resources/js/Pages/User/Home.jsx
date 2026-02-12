import {
    Box, Flex, Grid, GridItem, Text, Heading, Image, HStack,
    VStack, Badge, Button, Card, IconButton,
} from '@chakra-ui/react';
import { Link, Head } from '@inertiajs/react';
import UserLayout from './UserLayout';
import { LuHeart, LuShoppingCart, LuStar, LuChevronRight, LuArrowRight } from 'react-icons/lu';

// Mock product data
const mockProducts = [
    { id: 1, name: 'Massager Deluxe Pro', price: 4990, oldPrice: 6990, rating: 4.8, reviews: 124, brand: 'Satisfyer', image: null, badge: 'Хит' },
    { id: 2, name: 'Silk Touch Intimate', price: 2490, oldPrice: null, rating: 4.5, reviews: 87, brand: 'Lelo', image: null, badge: null },
    { id: 3, name: 'Pleasure Wave Set', price: 7990, oldPrice: 9990, rating: 4.9, reviews: 215, brand: 'We-Vibe', image: null, badge: '-20%' },
    { id: 4, name: 'Aroma Candle Rose', price: 1290, oldPrice: null, rating: 4.3, reviews: 56, brand: 'Shunga', image: null, badge: 'Новинка' },
    { id: 5, name: 'Lace Dreams Set', price: 3490, oldPrice: 4490, rating: 4.7, reviews: 143, brand: 'Obsessive', image: null, badge: null },
    { id: 6, name: 'Body Oil Exotic', price: 990, oldPrice: null, rating: 4.6, reviews: 92, brand: 'System JO', image: null, badge: null },
    { id: 7, name: 'Vibro Ring Plus', price: 1790, oldPrice: 2290, rating: 4.4, reviews: 73, brand: 'Durex', image: null, badge: '-22%' },
    { id: 8, name: 'Premium Lubricant', price: 690, oldPrice: null, rating: 4.2, reviews: 301, brand: 'System JO', image: null, badge: null },
];

function ProductCard({ product }) {
    // Generate a pastel gradient color based on product index
    const colors = [
        { from: 'pink.100', to: 'purple.100', dark: { from: 'pink.900/40', to: 'purple.900/40' } },
        { from: 'blue.100', to: 'teal.100', dark: { from: 'blue.900/40', to: 'teal.900/40' } },
        { from: 'orange.100', to: 'red.100', dark: { from: 'orange.900/40', to: 'red.900/40' } },
        { from: 'green.100', to: 'emerald.100', dark: { from: 'green.900/40', to: 'emerald.900/40' } },
    ];
    const color = colors[product.id % colors.length];

    return (
        <Card.Root
            overflow="hidden"
            borderRadius="xl"
            border="1px solid"
            borderColor="gray.100"
            _dark={{ borderColor: 'gray.700', bg: 'gray.800' }}
            transition="all 0.2s"
            _hover={{ shadow: 'lg', transform: 'translateY(-2px)', borderColor: 'pink.200' }}
        >
            {/* Image placeholder */}
            <Box
                position="relative"
                h="200px"
                bgGradient="to-br"
                gradientFrom={color.from}
                gradientTo={color.to}
                _dark={{ gradientFrom: color.dark.from, gradientTo: color.dark.to }}
                display="flex"
                alignItems="center"
                justifyContent="center"
            >
                <Text fontSize="3xl" opacity="0.4">📦</Text>

                {/* Badge */}
                {product.badge && (
                    <Badge
                        position="absolute"
                        top="3"
                        left="3"
                        colorPalette={product.badge === 'Новинка' ? 'green' : product.badge === 'Хит' ? 'orange' : 'red'}
                        fontSize="xs"
                        fontWeight="700"
                        borderRadius="md"
                        px="2"
                    >
                        {product.badge}
                    </Badge>
                )}

                {/* Favorite button */}
                <IconButton
                    aria-label="В избранное"
                    position="absolute"
                    top="3"
                    right="3"
                    size="sm"
                    variant="subtle"
                    colorPalette="gray"
                    bg="white/80"
                    _dark={{ bg: 'gray.800/80' }}
                    borderRadius="full"
                >
                    <LuHeart size={16} />
                </IconButton>
            </Box>

            <Card.Body p="4" gap="2">
                <Text fontSize="xs" color="gray.400" fontWeight="500" textTransform="uppercase" letterSpacing="0.05em">
                    {product.brand}
                </Text>
                <Text fontSize="sm" fontWeight="600" lineClamp="2" minH="40px">
                    {product.name}
                </Text>

                {/* Rating */}
                <HStack gap="1" mt="0.5">
                    <LuStar size={14} color="var(--chakra-colors-orange-400)" fill="var(--chakra-colors-orange-400)" />
                    <Text fontSize="xs" fontWeight="600">{product.rating}</Text>
                    <Text fontSize="xs" color="gray.400">({product.reviews})</Text>
                </HStack>

                {/* Price */}
                <Flex align="baseline" gap="2" mt="1">
                    <Text fontSize="lg" fontWeight="800" color="pink.500">
                        {product.price.toLocaleString()} ₽
                    </Text>
                    {product.oldPrice && (
                        <Text fontSize="sm" color="gray.400" textDecoration="line-through">
                            {product.oldPrice.toLocaleString()} ₽
                        </Text>
                    )}
                </Flex>

                {/* Cart Button */}
                <Button
                    size="sm"
                    colorPalette="pink"
                    variant="solid"
                    mt="2"
                    borderRadius="lg"
                    fontWeight="600"
                >
                    <LuShoppingCart size={14} />
                    В корзину
                </Button>
            </Card.Body>
        </Card.Root>
    );
}

function HeroBanner() {
    return (
        <Box
            position="relative"
            borderRadius="2xl"
            overflow="hidden"
            bgGradient="to-r"
            gradientFrom="pink.500"
            gradientTo="purple.600"
            p={{ base: '6', md: '12' }}
            minH={{ base: '200px', md: '280px' }}
            display="flex"
            alignItems="center"
            mb="8"
        >
            <Box maxW="500px" color="white" zIndex="1">
                <Badge colorPalette="yellow" mb="3" fontSize="xs" fontWeight="700">
                    Специальное предложение
                </Badge>
                <Heading size={{ base: '2xl', md: '4xl' }} fontWeight="900" mb="3" lineHeight="tight">
                    Весенняя распродажа до -40%
                </Heading>
                <Text fontSize={{ base: 'sm', md: 'md' }} mb="5" opacity="0.9">
                    Скидки на популярные товары проверенных брендов. Успейте купить по лучшей цене!
                </Text>
                <Button
                    as={Link}
                    href="/products"
                    size="lg"
                    bg="white"
                    color="pink.600"
                    fontWeight="700"
                    _hover={{ bg: 'gray.100' }}
                    borderRadius="xl"
                >
                    Смотреть каталог
                    <LuArrowRight />
                </Button>
            </Box>

            {/* Decorative circles */}
            <Box
                position="absolute"
                right="-40px"
                top="-40px"
                w="300px"
                h="300px"
                borderRadius="full"
                bg="white"
                opacity="0.08"
            />
            <Box
                position="absolute"
                right="80px"
                bottom="-60px"
                w="200px"
                h="200px"
                borderRadius="full"
                bg="white"
                opacity="0.05"
            />
        </Box>
    );
}

function ProductSection({ title, products, showViewAll = true }) {
    return (
        <Box mb="10">
            <Flex align="center" justify="space-between" mb="5">
                <Heading size="xl" fontWeight="800">
                    {title}
                </Heading>
                {showViewAll && (
                    <Button
                        as={Link}
                        href="/products"
                        variant="ghost"
                        colorPalette="pink"
                        size="sm"
                        fontWeight="600"
                    >
                        Смотреть все
                        <LuChevronRight />
                    </Button>
                )}
            </Flex>
            <Grid
                templateColumns={{ base: 'repeat(2, 1fr)', md: 'repeat(3, 1fr)', lg: 'repeat(4, 1fr)' }}
                gap={{ base: '3', md: '5' }}
            >
                {products.map((product) => (
                    <GridItem key={product.id}>
                        <ProductCard product={product} />
                    </GridItem>
                ))}
            </Grid>
        </Box>
    );
}

export default function Home() {
    const newProducts = mockProducts.slice(0, 4);
    const bestSellers = mockProducts.slice(4, 8);

    return (
        <UserLayout>
            <Head title="Pecado — Интернет-магазин для взрослых" />

            <HeroBanner />

            <ProductSection title="🆕 Новинки" products={newProducts} />
            <ProductSection title="🔥 Хиты продаж" products={bestSellers} />
        </UserLayout>
    );
}
