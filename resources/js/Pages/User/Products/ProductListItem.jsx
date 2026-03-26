import { useState } from 'react';
import { Box, Flex, Text, Badge, IconButton, Skeleton } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuHeart, LuCheck, LuCircleX, LuClock3 } from 'react-icons/lu';
import ProductMiniGallery from '@/components/product/ProductMiniGallery';
import TagList from '@/components/product/TagList';
import CartQuantityControl from '@/components/product/CartQuantityControl';
import { useProductHelpers } from '@/hooks/useProductHelpers';

/**
 * ProductListItem — горизонтальная карточка товара для list-режима каталога.
 *
 * @param {{ product: Object, loading?: boolean }} props
 */
export default function ProductListItem({ product, loading = false }) {
    const {
        user, isFav, toggleFavorite, formatPrice,
        hasSale, isInStock, isPreorder, brandName,
        price, salePrice, discountPct,
    } = useProductHelpers(product);

    const [isImageHovered, setIsImageHovered] = useState(false);

    // Скелетон — горизонтальная заглушка
    if (loading) {
        return (
            <Flex
                border="1px solid"
                borderColor={{ base: 'gray.100', _dark: 'gray.700' }}
                _dark={{ borderColor: 'gray.700', bg: 'gray.800' }}
                overflow="hidden"
                gap={{ base: '2', md: '4' }}
                direction="row"
            >
                {/* Изображение */}
                <Skeleton
                    w={{ base: '100px', md: '150px' }}
                    h={{ base: '150px', md: '225px' }}
                    flexShrink="0"
                />
                {/* Контент */}
                <Flex flex="1" p={{ base: '2', md: '4' }} direction="column" gap="2" justify="center" minW="0">
                    <Flex align="center" justify="space-between">
                        <Skeleton h="3" w="16" />
                        <Skeleton h="6" w="6" borderRadius="sm" />
                    </Flex>
                    <Skeleton h="4" w="80%" />
                    <Skeleton h="4" w="60%" />
                    <Flex align="center" gap="4" mt="2">
                        <Skeleton h="5" w="24" />
                        <Skeleton h="8" w="32" />
                    </Flex>
                </Flex>
            </Flex>
        );
    }

    return (
        <Flex
            border="1px solid"
            borderColor={{ base: 'gray.100', _dark: 'gray.700' }}
            bg={{ base: 'white', _dark: 'gray.800' }}
            _dark={{ borderColor: 'gray.700', bg: 'gray.800' }}
            overflow="hidden"
            transition="all 0.2s"
            _hover={{ shadow: 'md' }}
            direction="row"
        >
            {/* Изображение */}
            <Box
                position="relative"
                flexShrink="0"
                w={{ base: '100px', md: '150px' }}
                overflow="hidden"
                onMouseEnter={() => setIsImageHovered(true)}
                onMouseLeave={() => setIsImageHovered(false)}
            >
                <Link href={`/products/${product.slug}`}>
                    <ProductMiniGallery
                        product={product}
                        maxImages={4}
                        showMainImage
                        isHovered={isImageHovered}
                    />
                </Link>

                {/* Бейджи */}
                <Box position="absolute" top="2" left="2" display="flex" flexDirection="column" gap="1" pointerEvents="none">
                    {product.is_new && (
                        <Badge colorPalette="green" fontSize="2xs" fontWeight="700" borderRadius="md" px="2">
                            Новинка
                        </Badge>
                    )}
                    {product.is_bestseller && (
                        <Badge colorPalette="orange" fontSize="2xs" fontWeight="700" borderRadius="md" px="2">
                            Хит
                        </Badge>
                    )}
                    {hasSale && discountPct && (
                        <Badge colorPalette="red" fontSize="2xs" fontWeight="700" borderRadius="md" px="2">
                            −{Math.round(discountPct)}%
                        </Badge>
                    )}
                </Box>
            </Box>

            {/* Контент */}
            <Flex flex="1" p={{ base: '2', md: '4' }} direction="column" gap="1" minW="0">
                {/* Верхняя часть: SKU + Бренд + Избранное */}
                <Flex align="start" justify="space-between">
                    <Box flex="1" minW="0">
                        {product.sku && (
                            <Text fontSize="xs" fontWeight="700" color="gray.400">
                                {product.sku}
                            </Text>
                        )}
                        {brandName && product.brand_slug ? (
                            <Text
                                as={Link}
                                href={`/brands/${product.brand_slug}`}
                                fontSize="2xs"
                                textTransform="capitalize"
                                letterSpacing="wide"
                                color="gray.400"
                                _hover={{ textDecoration: 'underline' }}
                                display="block"
                                onClick={(e) => e.stopPropagation()}
                            >
                                {brandName}
                            </Text>
                        ) : brandName ? (
                            <Text fontSize="2xs" textTransform="capitalize" letterSpacing="wide" color="gray.400">
                                {brandName}
                            </Text>
                        ) : null}
                    </Box>
                    {user && (
                        <IconButton
                            aria-label="В избранное"
                            variant="ghost"
                            size="xs"
                            borderRadius="sm"
                            onClick={toggleFavorite}
                            color={isFav ? 'red.500' : 'gray.400'}
                            _hover={{ color: 'red.500' }}
                            minW="6"
                            h="6"
                        >
                            <LuHeart size={14} fill={isFav ? 'currentColor' : 'none'} />
                        </IconButton>
                    )}
                </Flex>

                {/* Название */}
                <Text
                    as={Link}
                    href={`/products/${product.slug}`}
                    fontSize="sm"
                    fontWeight="500"
                    _hover={{ textDecoration: 'underline' }}
                    display="-webkit-box"
                    css={{
                        WebkitLineClamp: 2,
                        WebkitBoxOrient: 'vertical',
                        overflow: 'hidden',
                        textOverflow: 'ellipsis',
                    }}
                >
                    {product.name}
                </Text>

                {/* Теги */}
                {product.tags && product.tags.length > 0 && (
                    <TagList tags={product.tags} maxVisible={3} />
                )}

                {/* Нижняя секция: наличие + цена + корзина */}
                {user && (
                    <Flex
                        mt="auto"
                        pt="2"
                        align={{ base: 'stretch', md: 'center' }}
                        direction={{ base: 'column', md: 'row' }}
                        gap={{ base: '2', md: '4' }}
                        flexWrap="wrap"
                    >
                        {/* Статус наличия */}
                        <Flex align="center" gap="1" fontSize="xs" fontWeight="500" flexShrink="0">
                            {isInStock ? (
                                <>
                                    <LuCheck size={14} color="var(--chakra-colors-green-600)" />
                                    <Text color="green.600" lineClamp="1">В наличии</Text>
                                </>
                            ) : isPreorder ? (
                                <>
                                    <LuClock3 size={14} color="var(--chakra-colors-orange-500)" />
                                    <Text color="orange.500" lineClamp="1">Предзаказ</Text>
                                </>
                            ) : (
                                <>
                                    <LuCircleX size={14} color="var(--chakra-colors-red-600)" />
                                    <Text color="red.600" lineClamp="1">Нет в наличии</Text>
                                </>
                            )}
                        </Flex>

                        {/* Цена */}
                        {price != null && (
                            <Flex align="baseline" gap="2" flexShrink="0">
                                <Text
                                    fontSize="lg"
                                    fontWeight="600"
                                    lineHeight="1"
                                    color={hasSale ? 'red.600' : undefined}
                                >
                                    {formatPrice(hasSale ? salePrice : price)}
                                </Text>
                                {hasSale && (
                                    <Text fontSize="xs" color="gray.400" textDecoration="line-through" lineHeight="1">
                                        {formatPrice(price)}
                                    </Text>
                                )}
                            </Flex>
                        )}

                        {/* Корзина */}
                        {(isInStock || isPreorder) && (hasSale ? salePrice : price) > 0 && (
                            <Box
                                w={{ base: '100%', md: '160px' }}
                                flexShrink="0"
                                onClick={(e) => { e.preventDefault(); e.stopPropagation(); }}
                            >
                                <CartQuantityControl productId={product.id} size="sm" />
                            </Box>
                        )}
                    </Flex>
                )}
            </Flex>
        </Flex>
    );
}
