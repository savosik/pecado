import { useState, useMemo } from 'react';
import { Box, Flex, Text, Heading, Grid, GridItem, Button, Image } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import ProductCard from './ProductCard';

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
    const catalogUrl = activeSelection?.slug ? `/products?selection=${activeSelection.slug}` : null;

    return (
        <Box
            mb="10"
            borderRadius="xl"
            border="1px solid"
            borderColor="gray.100"
            _dark={{ borderColor: 'gray.700', bg: 'gray.800/50' }}
            bg="white"
            overflow="hidden"
        >
            {/* Заголовок */}
            <Box px={{ base: '4', md: '6' }} pt={{ base: '4', md: '5' }}>
                <Heading size="xl" fontWeight="800" mb="3">
                    Подборки
                </Heading>
            </Box>

            {/* Табы */}
            <Flex
                overflowX="auto"
                gap="1"
                px={{ base: '4', md: '6' }}
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
                    px={{ base: '4', md: '6' }}
                    pt="3"
                >
                    {activeSelection.short_description}
                </Text>
            )}

            {/* Баннер подборки (десктоп/мобильный) — ссылка на каталог */}
            {hasBanner && (
                <Box px={{ base: '4', md: '6' }} pt="4">
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
                            <Image
                                src={activeSelection.mobile_image}
                                alt={activeSelection.name}
                                w="100%"
                                h={{ base: '120px', sm: '140px' }}
                                objectFit="cover"
                                loading="lazy"
                                display={{ base: 'block', md: 'none' }}
                            />
                        )}
                        {/* Десктопный баннер (на больших экранах) */}
                        {activeSelection.desktop_image && (
                            <Image
                                src={activeSelection.desktop_image}
                                alt={activeSelection.name}
                                w="100%"
                                h={{ md: '140px', lg: '160px' }}
                                objectFit="cover"
                                loading="lazy"
                                display={{ base: activeSelection.mobile_image ? 'none' : 'block', md: 'block' }}
                            />
                        )}
                        {/* Fallback: если есть только мобильный, показываем его и на десктопе */}
                        {!activeSelection.desktop_image && activeSelection.mobile_image && (
                            <Image
                                src={activeSelection.mobile_image}
                                alt={activeSelection.name}
                                w="100%"
                                h={{ md: '140px', lg: '160px' }}
                                objectFit="cover"
                                loading="lazy"
                                display={{ base: 'none', md: 'block' }}
                            />
                        )}
                    </Box>
                </Box>
            )}

            {/* Сетка товаров */}
            <Box px={{ base: '4', md: '6' }} py={{ base: '4', md: '5' }}>
                {activeSelection?.products?.length > 0 ? (
                    <>
                        <Grid
                            templateColumns={{
                                base: 'repeat(2, 1fr)',
                                md: 'repeat(3, 1fr)',
                                xl: 'repeat(5, 1fr)',
                            }}
                            gap={{ base: '3', md: '4' }}
                        >
                            {activeSelection.products.map((product, index) => (
                                <GridItem
                                    key={product.id}
                                    h="100%"
                                    display={index === activeSelection.products.length - 1 && activeSelection.products.length % 2 !== 0
                                        ? { base: 'none', md: 'block' }
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
