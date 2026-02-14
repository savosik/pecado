import { useEffect, useState } from 'react';
import { Box, Flex, Text, Badge, IconButton, Skeleton } from '@chakra-ui/react';
import { Link, usePage } from '@inertiajs/react';
import { LuHeart, LuCheck, LuCircleX, LuClock3 } from 'react-icons/lu';
import ProductMiniGallery from './ProductMiniGallery';
import TagList from './TagList';
import CartQuantityControl from './CartQuantityControl';
import { useFavoritesStore } from '@/stores/useFavoritesStore';
import { LOGIN_URL } from '@/constants/user';

/**
 * ProductCard — карточка товара для каталога и подборок (по дизайну референса).
 *
 * @param {{ product: Object, loading?: boolean }} props
 */
export default function ProductCard({ product, loading = false }) {
    const { auth, currency } = usePage().props;
    const user = auth?.user || null;
    const currencySymbol = currency?.symbol || '₽';

    const [isFav, setIsFav] = useState(false);

    // Подписка на стор избранного
    useEffect(() => {
        if (!user || !product?.id) return;

        const store = useFavoritesStore.getState();
        store.loadOnce(user);
        setIsFav(store.isFavorite(product.id));

        const unsub = useFavoritesStore.subscribe((state) => {
            setIsFav(state.ids.has(Number(product.id)));
        });
        return unsub;
    }, [product?.id, user]);

    const toggleFavorite = async (e) => {
        e?.preventDefault?.();
        e?.stopPropagation?.();
        if (!user) {
            window.location.href = LOGIN_URL;
            return;
        }
        useFavoritesStore.getState().toggle(product.id);
    };

    // Скелетон
    if (loading) {
        return (
            <Box
                border="1px solid"
                borderColor="gray.100"
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

    const price = product.base_price;
    const salePrice = product.sale_price || null;
    const discountPct = product.discount_percentage || null;
    const hasSale = salePrice != null && salePrice < price;
    const stockQty = product.stock_quantity || 0;
    const preorderQty = product.preorder_quantity || 0;
    const isInStock = stockQty > 0;
    const isPreorder = !isInStock && preorderQty > 0;
    const brandName = product.brand_name || null;

    const formatPrice = (value) => {
        if (value === null || value === undefined) return '';
        return Number(value).toLocaleString('ru-RU') + ' ' + currencySymbol;
    };

    return (
        <Box
            border="1px solid"
            borderColor="gray.100"
            bg="white"
            _dark={{ borderColor: 'gray.700', bg: 'gray.800' }}
            overflow="hidden"
            transition="all 0.2s"
            _hover={{ shadow: 'md' }}
            h="100%"
            display="flex"
            flexDirection="column"
        >
            {/* Мини-галерея */}
            <Box position="relative">
                <Link href={`/products/${product.slug}`}>
                    <ProductMiniGallery product={product} maxImages={6} showMainImage />
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

            {/* Информация */}
            <Box p="3" flex="1" display="flex" flexDirection="column">
                <Box flex="1" spaceY="1">
                    {/* SKU + Избранное */}
                    <Flex align="start" justify="space-between">
                        <Box flex="1">
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
                    </Flex>

                    {/* Название */}
                    <Text
                        as={Link}
                        href={`/products/${product.slug}`}
                        fontSize="sm"
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
                        <Flex align="end" justify="space-between" gap="2">
                            {/* Статус наличия */}
                            <Flex align="center" gap="1" fontSize="xs" fontWeight="500">
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
                                <Flex direction="column" alignItems="flex-end" flexShrink="0">
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
                            <Box onClick={(e) => { e.preventDefault(); e.stopPropagation(); }}>
                                <CartQuantityControl productId={product.id} />
                            </Box>
                        )}
                    </Box>
                )}
            </Box>
        </Box>
    );
}
