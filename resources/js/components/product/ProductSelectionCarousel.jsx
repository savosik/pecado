import { useState, useMemo } from 'react';
import { Box, Flex, Text, Heading, Grid, GridItem, Button, Image, Stack } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import ProductCard from './ProductCard';
import ProductListItem from '@/Pages/User/Products/ProductListItem';

/**
 * ProductSelectionTabs — блок подборок товаров с табами.
 * Каждая подборка — отдельный таб, при клике показывает сетку товаров.
 *
 * @param {Object} props
 * @param {Array} props.selections — [{id, name, short_description, desktop_image, mobile_image, products[]}]
 */
export default function ProductSelectionTabs({ selections = [] }) {
    const [activeIndex, setActiveIndex] = useState(0);

    const activeSelection = useMemo(() => {
        const sel = selections[activeIndex] || null;
        if (!sel) return null;
        // Ограничиваем до 5 товаров (кратно 5 колонкам на xl)
        const maxProducts = 5;
        return {
            ...sel,
            products: sel.products?.slice(0, maxProducts) || [],
        };
    }, [selections, activeIndex]);

    if (!selections.length) return null;

    const hasBanner = activeSelection?.desktop_image || activeSelection?.mobile_image;
    const catalogUrl = activeSelection?.slug ? `/collections/${activeSelection.slug}` : null;

    return (
        <Box
            mb="10"
            borderRadius={{ base: '0', md: 'xl' }}
            borderTopWidth="1px"
            borderBottomWidth="1px"
            borderLeftWidth={{ base: '0', md: '1px' }}
            borderRightWidth={{ base: '0', md: '1px' }}
            borderStyle="solid"
            borderColor="border.muted"
            _dark={{ borderColor: 'gray.700', bg: 'gray.800/50' }}
            bg="bg"
            overflow="hidden"
        >
            {/* Заголовок */}
            <Box px={{ base: '3', md: '6' }} pt={{ base: '4', md: '5' }}>
                <Heading size={{ base: 'md', md: 'lg' }} fontWeight="bold" color="fg" mb="3">
                    Подборки
                </Heading>
            </Box>

            {/* Табы */}
            <Flex
                overflowX="auto"
                gap="1"
                px={{ base: '3', md: '6' }}
                pb="0"
                css={{
                    '&::-webkit-scrollbar': { display: 'none' },
                    scrollbarWidth: 'none',
                }}
            >
                {selections.map((sel, i) => (
                    <Button
                        key={sel.id}
                        onClick={() => setActiveIndex(i)}
                        variant="plain"
                        size="sm"
                        fontWeight="500"
                        fontSize="sm"
                        whiteSpace="nowrap"
                        flexShrink="0"
                        borderRadius="0"
                        px="4"
                        py="2"
                        color={i === activeIndex ? 'gray.800' : 'gray.400'}
                        bg="transparent"
                        borderBottom="2px solid"
                        borderBottomColor={i === activeIndex ? 'gray.800' : 'transparent'}
                        _dark={{
                            color: i === activeIndex ? 'gray.100' : 'gray.500',
                            borderBottomColor: i === activeIndex ? 'gray.100' : 'transparent',
                        }}
                        _hover={{
                            color: i === activeIndex ? 'gray.800' : 'gray.600',
                            _dark: { color: i === activeIndex ? 'gray.100' : 'gray.300' },
                        }}
                        transition="all 0.2s"
                    >
                        {sel.name}
                    </Button>
                ))}
            </Flex>

            {/* Описание подборки */}
            {activeSelection?.short_description && (
                <Text
                    fontSize="sm"
                    color="gray.400"
                    px={{ base: '3', md: '6' }}
                    pt="3"
                >
                    {activeSelection.short_description}
                </Text>
            )}

            {/* Баннер подборки (десктоп/мобильный) — ссылка на каталог */}
            {hasBanner && (
                <Box px={{ base: '0', md: '6' }} pt="4">
                    <Box
                        as={catalogUrl ? Link : 'div'}
                        href={catalogUrl || undefined}
                        borderRadius="lg"
                        overflow="hidden"
                        display="block"
                        _hover={catalogUrl ? { opacity: 0.9 } : undefined}
                        transition="opacity 0.2s"
                    >
                        {/* Мобильный баннер (на маленьких экранах) */}
                        {activeSelection.mobile_image && (
                            <Box
                                css={{ aspectRatio: '3 / 2' }}
                                display={{ base: 'block', md: 'none' }}
                                overflow="hidden"
                            >
                                <Image
                                    src={activeSelection.mobile_image}
                                    alt={activeSelection.name}
                                    w="100%"
                                    h="100%"
                                    objectFit="cover"
                                    loading="lazy"
                                />
                            </Box>
                        )}
                        {/* Десктопный баннер (на больших экранах) — в естественных пропорциях фото */}
                        {activeSelection.desktop_image && (
                            <Box
                                display={{ base: activeSelection.mobile_image ? 'none' : 'block', md: 'block' }}
                                overflow="hidden"
                            >
                                <Image
                                    src={activeSelection.desktop_image}
                                    alt={activeSelection.name}
                                    w="100%"
                                    h="auto"
                                    display="block"
                                    loading="lazy"
                                />
                            </Box>
                        )}
                        {/* Fallback: если есть только мобильный, показываем его и на десктопе */}
                        {!activeSelection.desktop_image && activeSelection.mobile_image && (
                            <Box
                                display={{ base: 'none', md: 'block' }}
                                overflow="hidden"
                            >
                                <Image
                                    src={activeSelection.mobile_image}
                                    alt={activeSelection.name}
                                    w="100%"
                                    h="auto"
                                    display="block"
                                    loading="lazy"
                                />
                            </Box>
                        )}
                    </Box>
                </Box>
            )}

            {/* Сетка товаров */}
            <Box px={{ base: '0', md: '6' }} py={{ base: '4', md: '5' }}>
                {activeSelection?.products?.length > 0 ? (
                    <>
                        {/* List view (base) */}
                        <Stack display={{ base: 'flex', md: 'none' }} gap="2">
                            {activeSelection.products.map((product) => (
                                <ProductListItem key={product.id} product={product} />
                            ))}
                        </Stack>

                        {/* Grid view (md+) */}
                        <Grid
                            display={{ base: 'none', md: 'grid' }}
                            templateColumns={{
                                md: 'repeat(3, 1fr)',
                                xl: 'repeat(5, 1fr)',
                            }}
                            gap={{ md: '4' }}
                        >
                            {activeSelection.products.map((product, index) => (
                                <GridItem
                                    key={product.id}
                                    h="100%"
                                    display={index === activeSelection.products.length - 1 && activeSelection.products.length % 2 !== 0
                                        ? { md: 'block' }
                                        : undefined
                                    }
                                >
                                    <ProductCard product={product} />
                                </GridItem>
                            ))}
                        </Grid>

                        {catalogUrl && (
                            <Flex justifyContent="center" mt="5">
                                <Button
                                    as={Link}
                                    href={catalogUrl}
                                    variant="outline"
                                    size="sm"
                                    borderColor="gray.300"
                                    color="gray.600"
                                    _dark={{ borderColor: 'gray.600', color: 'gray.300' }}
                                    _hover={{ bg: 'gray.50', _dark: { bg: 'gray.700' } }}
                                >
                                    Все товары подборки
                                </Button>
                            </Flex>
                        )}
                    </>
                ) : (
                    <Text textAlign="center" color="gray.400" py="8">
                        Нет товаров в этой подборке.
                    </Text>
                )}
            </Box>
        </Box>
    );
}
