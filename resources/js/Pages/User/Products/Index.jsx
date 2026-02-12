import { useState } from 'react';
import {
    Box, Flex, Grid, GridItem, Text, Heading, HStack, VStack,
    Input, Button, Card, IconButton, Badge, Accordion, Span,
    Checkbox, Separator,
} from '@chakra-ui/react';
import { Head, Link } from '@inertiajs/react';
import UserLayout from '../UserLayout';
import {
    LuHeart, LuShoppingCart, LuStar, LuGrid2X2, LuLayoutList,
    LuChevronLeft, LuChevronRight, LuSlidersHorizontal, LuX,
} from 'react-icons/lu';

// Mock product data for catalog
const allProducts = [
    { id: 1, name: 'Massager Deluxe Pro', price: 4990, oldPrice: 6990, rating: 4.8, reviews: 124, brand: 'Satisfyer', badge: 'Хит' },
    { id: 2, name: 'Silk Touch Intimate', price: 2490, oldPrice: null, rating: 4.5, reviews: 87, brand: 'Lelo', badge: null },
    { id: 3, name: 'Pleasure Wave Set', price: 7990, oldPrice: 9990, rating: 4.9, reviews: 215, brand: 'We-Vibe', badge: '-20%' },
    { id: 4, name: 'Aroma Candle Rose', price: 1290, oldPrice: null, rating: 4.3, reviews: 56, brand: 'Shunga', badge: 'Новинка' },
    { id: 5, name: 'Lace Dreams Set', price: 3490, oldPrice: 4490, rating: 4.7, reviews: 143, brand: 'Obsessive', badge: null },
    { id: 6, name: 'Body Oil Exotic', price: 990, oldPrice: null, rating: 4.6, reviews: 92, brand: 'System JO', badge: null },
    { id: 7, name: 'Vibro Ring Plus', price: 1790, oldPrice: 2290, rating: 4.4, reviews: 73, brand: 'Durex', badge: '-22%' },
    { id: 8, name: 'Premium Lubricant', price: 690, oldPrice: null, rating: 4.2, reviews: 301, brand: 'System JO', badge: null },
    { id: 9, name: 'Fantasy Costume', price: 5990, oldPrice: null, rating: 4.6, reviews: 44, brand: 'Obsessive', badge: null },
    { id: 10, name: 'Couples Ring Set', price: 3290, oldPrice: 4190, rating: 4.5, reviews: 167, brand: 'We-Vibe', badge: '-21%' },
    { id: 11, name: 'Warming Gel 200ml', price: 890, oldPrice: null, rating: 4.1, reviews: 233, brand: 'Durex', badge: null },
    { id: 12, name: 'Silk Blindfold Deluxe', price: 1990, oldPrice: 2490, rating: 4.8, reviews: 89, brand: 'Lelo', badge: 'Хит' },
];

const filterCategories = [
    { name: 'Вибраторы', count: 234 },
    { name: 'Фаллоимитаторы', count: 156 },
    { name: 'Белье', count: 312 },
    { name: 'Косметика', count: 189 },
    { name: 'Аксессуары', count: 267 },
    { name: 'Подарочные наборы', count: 45 },
];

const filterBrands = [
    { name: 'Satisfyer', count: 89 },
    { name: 'Lelo', count: 67 },
    { name: 'We-Vibe', count: 54 },
    { name: 'Shunga', count: 43 },
    { name: 'Obsessive', count: 98 },
    { name: 'System JO', count: 76 },
    { name: 'Durex', count: 112 },
];

function CatalogProductCard({ product }) {
    const colors = [
        { from: 'pink.100', to: 'purple.100' },
        { from: 'blue.100', to: 'teal.100' },
        { from: 'orange.100', to: 'red.100' },
        { from: 'green.100', to: 'emerald.100' },
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
            <Box
                position="relative"
                h="180px"
                bgGradient="to-br"
                gradientFrom={color.from}
                gradientTo={color.to}
                display="flex"
                alignItems="center"
                justifyContent="center"
            >
                <Text fontSize="3xl" opacity="0.4">📦</Text>

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

                <IconButton
                    aria-label="В избранное"
                    position="absolute"
                    top="3"
                    right="3"
                    size="sm"
                    variant="subtle"
                    bg="white/80"
                    _dark={{ bg: 'gray.800/80' }}
                    colorPalette="gray"
                    borderRadius="full"
                >
                    <LuHeart size={14} />
                </IconButton>
            </Box>

            <Card.Body p="3" gap="1.5">
                <Text fontSize="xs" color="gray.400" fontWeight="500" textTransform="uppercase" letterSpacing="0.05em">
                    {product.brand}
                </Text>
                <Text fontSize="sm" fontWeight="600" lineClamp="2" minH="36px">
                    {product.name}
                </Text>

                <HStack gap="1">
                    <LuStar size={12} color="var(--chakra-colors-orange-400)" fill="var(--chakra-colors-orange-400)" />
                    <Text fontSize="xs" fontWeight="600">{product.rating}</Text>
                    <Text fontSize="xs" color="gray.400">({product.reviews})</Text>
                </HStack>

                <Flex align="baseline" gap="2">
                    <Text fontSize="md" fontWeight="800" color="pink.500">
                        {product.price.toLocaleString()} ₽
                    </Text>
                    {product.oldPrice && (
                        <Text fontSize="xs" color="gray.400" textDecoration="line-through">
                            {product.oldPrice.toLocaleString()} ₽
                        </Text>
                    )}
                </Flex>

                <Button
                    size="xs"
                    colorPalette="pink"
                    variant="solid"
                    mt="1"
                    borderRadius="lg"
                    fontWeight="600"
                >
                    <LuShoppingCart size={12} />
                    В корзину
                </Button>
            </Card.Body>
        </Card.Root>
    );
}

function FilterSidebar() {
    return (
        <Box bg="white" borderRadius="xl" border="1px solid" borderColor="gray.100" p="4" _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}>
            <Accordion.Root collapsible multiple defaultValue={['categories', 'brands', 'price']}>
                {/* Categories */}
                <Accordion.Item value="categories">
                    <Accordion.ItemTrigger py="3" px="0">
                        <Span flex="1" fontWeight="700" fontSize="sm">Категории</Span>
                        <Accordion.ItemIndicator />
                    </Accordion.ItemTrigger>
                    <Accordion.ItemContent>
                        <Accordion.ItemBody px="0" pb="3">
                            <VStack align="stretch" gap="1">
                                {filterCategories.map((cat) => (
                                    <Flex key={cat.name} align="center" justify="space-between" py="1" px="1" borderRadius="md" _hover={{ bg: 'gray.50' }} _dark={{ _hover: { bg: 'gray.700' } }} cursor="pointer">
                                        <Text fontSize="sm">{cat.name}</Text>
                                        <Text fontSize="xs" color="gray.400">{cat.count}</Text>
                                    </Flex>
                                ))}
                            </VStack>
                        </Accordion.ItemBody>
                    </Accordion.ItemContent>
                </Accordion.Item>

                {/* Brands */}
                <Accordion.Item value="brands">
                    <Accordion.ItemTrigger py="3" px="0">
                        <Span flex="1" fontWeight="700" fontSize="sm">Бренды</Span>
                        <Accordion.ItemIndicator />
                    </Accordion.ItemTrigger>
                    <Accordion.ItemContent>
                        <Accordion.ItemBody px="0" pb="3">
                            <VStack align="stretch" gap="1.5">
                                {filterBrands.map((brand) => (
                                    <HStack key={brand.name} gap="2">
                                        <Checkbox.Root size="sm">
                                            <Checkbox.HiddenInput />
                                            <Checkbox.Control />
                                            <Checkbox.Label>
                                                <Text fontSize="sm">{brand.name}</Text>
                                            </Checkbox.Label>
                                        </Checkbox.Root>
                                        <Text fontSize="xs" color="gray.400" ml="auto">{brand.count}</Text>
                                    </HStack>
                                ))}
                            </VStack>
                        </Accordion.ItemBody>
                    </Accordion.ItemContent>
                </Accordion.Item>

                {/* Price */}
                <Accordion.Item value="price">
                    <Accordion.ItemTrigger py="3" px="0">
                        <Span flex="1" fontWeight="700" fontSize="sm">Цена</Span>
                        <Accordion.ItemIndicator />
                    </Accordion.ItemTrigger>
                    <Accordion.ItemContent>
                        <Accordion.ItemBody px="0" pb="3">
                            <HStack gap="2">
                                <Input placeholder="От" size="sm" type="number" borderRadius="md" />
                                <Text color="gray.400">—</Text>
                                <Input placeholder="До" size="sm" type="number" borderRadius="md" />
                            </HStack>
                            <Button size="xs" mt="3" variant="outline" colorPalette="pink" w="100%" borderRadius="md">
                                Применить
                            </Button>
                        </Accordion.ItemBody>
                    </Accordion.ItemContent>
                </Accordion.Item>
            </Accordion.Root>
        </Box>
    );
}

function Pagination() {
    const [currentPage, setCurrentPage] = useState(1);
    const totalPages = 5;

    return (
        <Flex align="center" justify="center" gap="1" mt="8">
            <IconButton
                aria-label="Предыдущая"
                variant="ghost"
                size="sm"
                disabled={currentPage === 1}
                onClick={() => setCurrentPage(Math.max(1, currentPage - 1))}
            >
                <LuChevronLeft />
            </IconButton>
            {Array.from({ length: totalPages }, (_, i) => i + 1).map((page) => (
                <Button
                    key={page}
                    size="sm"
                    variant={page === currentPage ? 'solid' : 'ghost'}
                    colorPalette={page === currentPage ? 'pink' : 'gray'}
                    borderRadius="lg"
                    minW="9"
                    fontWeight="600"
                    onClick={() => setCurrentPage(page)}
                >
                    {page}
                </Button>
            ))}
            <IconButton
                aria-label="Следующая"
                variant="ghost"
                size="sm"
                disabled={currentPage === totalPages}
                onClick={() => setCurrentPage(Math.min(totalPages, currentPage + 1))}
            >
                <LuChevronRight />
            </IconButton>
        </Flex>
    );
}

export default function Index() {
    const [view, setView] = useState('grid');

    return (
        <UserLayout>
            <Head title="Каталог товаров — Pecado" />

            {/* Page Header */}
            <Box mb="6">
                <Flex direction={{ base: 'column', lg: 'row' }} align={{ lg: 'end' }} justify="space-between" gap="3">
                    <Box>
                        <Heading size="2xl" fontWeight="900" mb="1">
                            Каталог товаров
                            <Text as="sup" fontSize="xs" color="gray.400" fontWeight="normal" ml="2">
                                {allProducts.length} товаров
                            </Text>
                        </Heading>
                        <Text fontSize="sm" color="gray.500">
                            Широкий ассортимент товаров для удовольствия и здоровья
                        </Text>
                    </Box>
                    <HStack gap="2">
                        <HStack gap="0.5" bg="gray.100" borderRadius="lg" p="0.5" _dark={{ bg: 'gray.800' }}>
                            <IconButton
                                aria-label="Сетка"
                                size="sm"
                                variant={view === 'grid' ? 'solid' : 'ghost'}
                                colorPalette={view === 'grid' ? 'pink' : 'gray'}
                                borderRadius="md"
                                onClick={() => setView('grid')}
                            >
                                <LuGrid2X2 size={14} />
                            </IconButton>
                            <IconButton
                                aria-label="Список"
                                size="sm"
                                variant={view === 'list' ? 'solid' : 'ghost'}
                                colorPalette={view === 'list' ? 'pink' : 'gray'}
                                borderRadius="md"
                                onClick={() => setView('list')}
                            >
                                <LuLayoutList size={14} />
                            </IconButton>
                        </HStack>
                    </HStack>
                </Flex>
            </Box>

            {/* Content */}
            <Flex gap="6">
                {/* Desktop Sidebar */}
                <Box display={{ base: 'none', lg: 'block' }} w="240px" flexShrink="0">
                    <FilterSidebar />
                </Box>

                {/* Product Grid */}
                <Box flex="1" minW="0">
                    {/* Active filters */}
                    <HStack gap="2" mb="4" flexWrap="wrap">
                        <Badge colorPalette="pink" variant="subtle" borderRadius="full" px="3" py="1" fontSize="xs">
                            Satisfyer
                            <Box as="span" ml="1" cursor="pointer" opacity="0.6" _hover={{ opacity: 1 }}>✕</Box>
                        </Badge>
                        <Badge colorPalette="pink" variant="subtle" borderRadius="full" px="3" py="1" fontSize="xs">
                            от 1000 ₽
                            <Box as="span" ml="1" cursor="pointer" opacity="0.6" _hover={{ opacity: 1 }}>✕</Box>
                        </Badge>
                    </HStack>

                    <Grid
                        templateColumns={{
                            base: 'repeat(2, 1fr)',
                            md: 'repeat(3, 1fr)',
                            xl: 'repeat(4, 1fr)',
                        }}
                        gap={{ base: '3', md: '4' }}
                    >
                        {allProducts.map((product) => (
                            <GridItem key={product.id}>
                                <CatalogProductCard product={product} />
                            </GridItem>
                        ))}
                    </Grid>

                    <Pagination />
                </Box>
            </Flex>
        </UserLayout>
    );
}
