import { memo, useCallback, useState } from 'react';
import { Box, Flex, Text, Badge, IconButton, Skeleton } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuHeart, LuCheck, LuCircleX, LuClock3, LuWarehouse, LuTruck, LuPackageX, LuCopy, LuTag, LuBadgePercent } from 'react-icons/lu';
import ProductMiniGallery from './ProductMiniGallery';
import TagList from './TagList';
import CartQuantityControl from './CartQuantityControl';
import { useProductHelpers } from '@/hooks/useProductHelpers';
import { formatShelfLife } from '@/utils/product';
import { toaster } from '@/components/ui/toaster';

/**
 * ProductCard — карточка товара для каталога и подборок (по дизайну референса).
 *
 * @param {{ product: Object, loading?: boolean }} props
 */
function ProductCard({ product, loading = false }) {
    const {
        user, isFav, toggleFavorite, formatPrice,
        hasSale, isInStock, isPreorder, brandName,
        price, salePrice, discountPct,
    } = useProductHelpers(product);

    // product может быть undefined в режиме скелетона (loading) — optional chaining
    // обязателен, иначе падаем ещё до раннего return скелетона.
    const shelfLife = formatShelfLife(product?.shelf_life);

    const [isImageHovered, setIsImageHovered] = useState(false);
    const handleMouseEnter = useCallback(() => setIsImageHovered(true), []);
    const handleMouseLeave = useCallback(() => setIsImageHovered(false), []);
    const stopPropagation = useCallback((e) => e.stopPropagation(), []);
    const preventAndStop = useCallback((e) => {
        e.preventDefault();
        e.stopPropagation();
    }, []);
    const copySku = useCallback(async (e) => {
        e.preventDefault();
        e.stopPropagation();
        const sku = product?.sku;
        if (!sku) return;
        try {
            await navigator.clipboard.writeText(sku);
            toaster.create({ title: `Артикул ${sku} скопирован`, type: 'success', duration: 2000 });
        } catch {
            toaster.create({ title: 'Не удалось скопировать', type: 'error' });
        }
    }, [product?.sku]);

    // Скелетон
    if (loading) {
        return (
            <Box
                border="1px solid"
                borderColor="border.muted"
                _dark={{ borderColor: 'gray.700', bg: 'gray.800' }}
                overflow="hidden"
                h="100%"
                display="flex"
                flexDirection="column"
            >
                <Skeleton css={{ aspectRatio: '2 / 3' }} w="100%" />
                <Box p="4" flex="1" display="flex" flexDirection="column">
                    <Box flex="1" spaceY="1">
                        <Flex align="center" justify="space-between">
                            <Skeleton h="3" w="16" />
                            <Skeleton h="6" w="6" borderRadius="sm" />
                        </Flex>
                        <Skeleton h="3" w="20" />
                        <Box spaceY="2">
                            <Skeleton h="4" w="100%" />
                            <Skeleton h="4" w="75%" />
                        </Box>
                    </Box>
                    <Box mt="auto" spaceY="2" pt="2">
                        <Flex align="end" justify="space-between" gap="2">
                            <Skeleton h="4" w="20" />
                            <Skeleton h="5" w="16" />
                        </Flex>
                        <Skeleton h="8" w="100%" />
                    </Box>
                </Box>
            </Box>
        );
    }

    return (
        <Box
            border="1px solid"
            borderColor="border.muted"
            bg="bg"
            _dark={{ borderColor: 'gray.700', bg: 'gray.800' }}
            overflow="hidden"
            transition="all 0.2s"
            _hover={{ shadow: 'md' }}
            h="100%"
            display="flex"
            flexDirection="column"
        >
            {/* Мини-галерея */}
            <Box
                position="relative"
                onMouseEnter={handleMouseEnter}
                onMouseLeave={handleMouseLeave}
            >
                {/* Акцентная полоса — товар участвует в акции */}
                {product.has_promotion && (
                    <Box
                        position="absolute"
                        top="0"
                        left="0"
                        right="0"
                        h="3px"
                        zIndex="1"
                        pointerEvents="none"
                        bgGradient="to-r"
                        gradientFrom="purple.400"
                        gradientTo="pink.400"
                    />
                )}
                <Link href={`/products/${product.slug}`}>
                    <ProductMiniGallery product={product} maxImages={6} showMainImage isHovered={isImageHovered} />
                </Link>

                {/* Бейджи */}
                <Box position="absolute" top="2" left="2" display="flex" flexDirection="column" gap="1" pointerEvents="none">
                    {product.has_promotion && (
                        <Badge colorPalette="purple" fontSize="2xs" fontWeight="700" borderRadius="md" px="2" display="inline-flex" alignItems="center" gap="1">
                            <LuTag size={10} />
                            Акция
                        </Badge>
                    )}
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
                    {product.is_marked && (
                        <Badge colorPalette="yellow" fontSize="2xs" fontWeight="700" borderRadius="md" px="2">
                            Маркировка
                        </Badge>
                    )}
                    {shelfLife && (
                        <Badge colorPalette="gray" variant="subtle" fontSize="2xs" fontWeight="700" borderRadius="md" px="2" title="Срок годности">
                            {shelfLife}
                        </Badge>
                    )}
                </Box>

                {/* Уценка — небольшой лиловый значок в углу (в цвет бейджа «Есть
                    товары с уценкой» в карточке товара). Не в общей стопке слева. */}
                {product.has_defects && (
                    <Box
                        position="absolute"
                        top="2"
                        right="2"
                        color="purple.500"
                        pointerEvents="none"
                        title="Есть уценённые экземпляры с дефектами"
                    >
                        <LuBadgePercent size={18} />
                    </Box>
                )}
            </Box>

            {/* Информация */}
            <Box p={{ base: '2', md: '3' }} flex="1" display="flex" flexDirection="column">
                <Box flex="1" spaceY="1">
                    {/* SKU + Избранное */}
                    <Flex align="start" justify="space-between" gap="2" minW="0">
                        <Box flex="1" minW="0">
                            {product.sku && (
                                <Flex
                                    as="button"
                                    type="button"
                                    align="center"
                                    gap="1"
                                    fontSize="xs"
                                    fontWeight="700"
                                    color="gray.400"
                                    cursor="pointer"
                                    onClick={copySku}
                                    aria-label={`Копировать артикул ${product.sku}`}
                                    _hover={{ color: 'gray.600', _dark: { color: 'gray.200' } }}
                                    transition="color 0.15s"
                                    maxW="100%"
                                    minW="0"
                                >
                                    <Text as="span" truncate>{product.sku}</Text>
                                    <Box as="span" opacity="0.6" flexShrink="0"><LuCopy size={11} /></Box>
                                </Flex>
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
                                    onClick={stopPropagation}
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
                        fontSize="13px"
                        fontWeight="400"
                        lineHeight="1.3"
                        _hover={{ textDecoration: 'underline' }}
                        display="-webkit-box"
                        css={{
                            WebkitLineClamp: 3,
                            WebkitBoxOrient: 'vertical',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                        }}
                        mt="1"
                        mb="1"
                    >
                        {product.name}
                    </Text>

                    {/* Теги */}
                    {product.tags && product.tags.length > 0 && (
                        <TagList tags={product.tags} maxVisible={2} />
                    )}
                </Box>

                {/* Нижняя секция — только для авторизованных */}
                {user && (
                    <Box mt="auto" spaceY="2" pt="2">
                        {/* Статус + Цена */}
                        <Flex align="end" justify="space-between" gap="2" minW="0" flexWrap="wrap">
                            {/* Статус наличия */}
                            <Flex align="center" gap="1" fontSize="xs" fontWeight="500">
                                {isInStock ? (
                                    <>
                                        <Box display={{ base: 'inline-flex', md: 'none' }} aria-label="В наличии" title="В наличии">
                                            <LuWarehouse size={16} color="var(--chakra-colors-green-600)" />
                                        </Box>
                                        <Box display={{ base: 'none', md: 'inline-flex' }}>
                                            <LuCheck size={14} color="var(--chakra-colors-green-600)" />
                                        </Box>
                                        <Text color="green.600" lineClamp="1" display={{ base: 'none', md: 'block' }}>В наличии</Text>
                                    </>
                                ) : isPreorder ? (
                                    <>
                                        <Box display={{ base: 'inline-flex', md: 'none' }} aria-label="Предзаказ" title="Предзаказ">
                                            <LuTruck size={16} color="var(--chakra-colors-yellow-500)" />
                                        </Box>
                                        <Box display={{ base: 'none', md: 'inline-flex' }}>
                                            <LuClock3 size={14} color="var(--chakra-colors-orange-500)" />
                                        </Box>
                                        <Text color="orange.500" lineClamp="1" display={{ base: 'none', md: 'block' }}>Предзаказ</Text>
                                    </>
                                ) : (
                                    <>
                                        <Box display={{ base: 'inline-flex', md: 'none' }} aria-label="Нет в наличии" title="Нет в наличии">
                                            <LuPackageX size={16} color="var(--chakra-colors-red-600)" />
                                        </Box>
                                        <Box display={{ base: 'none', md: 'inline-flex' }}>
                                            <LuCircleX size={14} color="var(--chakra-colors-red-600)" />
                                        </Box>
                                        <Text color="red.600" lineClamp="1" display={{ base: 'none', md: 'block' }}>Нет в наличии</Text>
                                    </>
                                )}
                            </Flex>

                            {/* Цена */}
                            {price != null && (
                                <Flex direction="column" alignItems="flex-end" minW="0">
                                    {hasSale && (
                                        <Text fontSize="xs" color="gray.400" textDecoration="line-through" lineHeight="1">
                                            {formatPrice(price)}
                                        </Text>
                                    )}
                                    <Text
                                        fontSize="lg"
                                        fontWeight="600"
                                        lineHeight="1"
                                        color={hasSale ? 'red.600' : undefined}
                                    >
                                        {formatPrice(hasSale ? salePrice : price)}
                                    </Text>
                                </Flex>
                            )}
                        </Flex>

                        {/* Корзина — скрываем, если нет в наличии и не предзаказ, или цена 0 */}
                        {(isInStock || isPreorder) && (hasSale ? salePrice : price) > 0 && (
                            <Box onClick={preventAndStop}>
                                <CartQuantityControl
                                    productId={product.id}
                                    stockQuantity={product.stock_quantity ?? 0}
                                    preorderQuantity={product.preorder_quantity ?? 0}
                                    size="sm"
                                    fullWidth
                                />
                            </Box>
                        )}
                    </Box>
                )}
            </Box>
        </Box>
    );
}

export default memo(ProductCard);
