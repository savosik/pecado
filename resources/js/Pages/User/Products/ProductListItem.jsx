import { useState } from 'react';
import { Box, Flex, Text, IconButton, Skeleton } from '@chakra-ui/react';
import { Link, usePage } from '@inertiajs/react';
import { LuHeart, LuCheck, LuCircleX, LuClock3, LuBadgePercent, LuTag, LuGift } from 'react-icons/lu';
import ProductMiniGallery from '@/components/product/ProductMiniGallery';
import TagList from '@/components/product/TagList';
import CartQuantityControl from '@/components/product/CartQuantityControl';
import { useProductHelpers } from '@/hooks/useProductHelpers';
import { formatShelfLife } from '@/utils/product';

/**
 * ProductListItem — горизонтальная карточка товара для list-режима каталога.
 *
 * @param {{ product: Object, loading?: boolean }} props
 */
export default function ProductListItem({ product, loading = false }) {
    const {
        user, isFav, toggleFavorite, formatPrice,
        hasSale, isInStock, isPreorder, isDefectOnly, defectMinPrice, brandName,
        price, salePrice, discountPct,
    } = useProductHelpers(product);

    const [isImageHovered, setIsImageHovered] = useState(false);
    // Срок поставки предзаказа — рядом со статусом, до оранжевой рамки счётчика.
    const leadLabel = usePage().props.preorder?.lead_label ?? '';

    // Скелетон — горизонтальная заглушка
    if (loading) {
        return (
            <Flex
                border="1px solid"
                borderColor="border.muted"
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

    const stockBlock = (
        <Flex align="center" gap="1" fontSize="xs" fontWeight="500" flexShrink="0">
            {isInStock ? (
                <>
                    <LuCheck size={14} color="var(--chakra-colors-green-600)" />
                    <Text color="green.600" lineClamp="1">В наличии</Text>
                </>
            ) : isPreorder ? (
                <>
                    <LuClock3 size={14} color="var(--chakra-colors-orange-500)" />
                    <Text
                        color="orange.500"
                        lineClamp="1"
                        title={leadLabel ? `Нет на складе — закажем у поставщика, поставка ${leadLabel}` : undefined}
                    >
                        Предзаказ{leadLabel ? ` · ${leadLabel}` : ''}
                    </Text>
                </>
            ) : isDefectOnly ? (
                <>
                    <LuBadgePercent size={14} color="var(--chakra-colors-purple-500)" />
                    <Text color="purple.500" lineClamp="1" title="Остались только уценённые экземпляры">Уценка</Text>
                </>
            ) : (
                <>
                    <LuCircleX size={14} color="var(--chakra-colors-red-600)" />
                    <Text color="red.600" lineClamp="1">Нет в наличии</Text>
                </>
            )}
        </Flex>
    );

    return (
        <Flex
            border="1px solid"
            borderColor="border.muted"
            bg="bg"
            _dark={{ borderColor: 'gray.700', bg: 'gray.800' }}
            overflow="hidden"
            transition="all 0.2s"
            _hover={{ shadow: 'md' }}
            direction="row"
            align={{ base: 'flex-start', md: 'stretch' }}
            p={{ base: '2', md: '0' }}
            gap={{ base: '3', md: '0' }}
        >
            {/* Левая колонка: изображение + (на base) статус наличия под ним */}
            <Flex
                direction="column"
                flexShrink="0"
                gap="1.5"
                w={{ base: '90px', md: '150px' }}
            >
                <Box
                    position="relative"
                    w="100%"
                    h={{ base: '135px', md: '225px' }}
                    overflow="hidden"
                    borderRadius={{ base: 'md', md: '0' }}
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

                    {/* Маркер акции — тот же лиловый, что бейдж в сетке.
                        В списке стопки бейджей нет, поэтому значком. */}
                    {product.has_promotion && (
                        <Box
                            position="absolute"
                            top="2"
                            left="2"
                            color="purple.500"
                            pointerEvents="none"
                            title={product.promotion_name || 'Товар участвует в акции'}
                        >
                            <LuTag size={16} />
                        </Box>
                    )}

                    {/* Значки уценки и промо-позиции — колонкой, чтобы не перекрывались */}
                    {(product.has_defects || product.is_promo_reward) && (
                        <Box
                            position="absolute"
                            top="2"
                            right="2"
                            display="flex"
                            flexDirection="column"
                            alignItems="flex-end"
                            gap="1"
                            pointerEvents="none"
                        >
                            {product.has_defects && (
                                <Box color="purple.500" title="Есть уценённые экземпляры с дефектами">
                                    <LuBadgePercent size={18} />
                                </Box>
                            )}
                            {product.is_promo_reward && (
                                <Box color="pink.500" title="Этот товар можно получить по акции">
                                    <LuGift size={18} />
                                </Box>
                            )}
                        </Box>
                    )}
                </Box>

            </Flex>

            {/* Контент */}
            <Flex flex="1" p={{ base: '0', md: '4' }} direction="column" gap="1" minW="0">
                {/* Верхняя часть: SKU / Бренд + Избранное */}
                <Flex align="start" justify="space-between" gap="2">
                    <Flex
                        flex="1"
                        minW="0"
                        align="baseline"
                        gap="1.5"
                        flexWrap="wrap"
                        rowGap="0"
                    >
                        {product.sku && (
                            <Text fontSize="xs" fontWeight="700" color="gray.500" lineHeight="1.3">
                                {product.sku}
                            </Text>
                        )}
                        {brandName && product.sku && (
                            <Text fontSize="xs" color="gray.300" lineHeight="1.3">/</Text>
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
                                lineHeight="1.3"
                                onClick={(e) => e.stopPropagation()}
                            >
                                {brandName}
                            </Text>
                        ) : brandName ? (
                            <Text fontSize="2xs" textTransform="capitalize" letterSpacing="wide" color="gray.400" lineHeight="1.3">
                                {brandName}
                            </Text>
                        ) : null}
                    </Flex>
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
                            mt="-1"
                        >
                            <LuHeart size={16} fill={isFav ? 'currentColor' : 'none'} />
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
                >
                    {product.name}
                </Text>

                {/* Теги (с подмиксованными бейджами Новинка / Хит / Скидка) */}
                <TagList
                    tags={product.tags}
                    maxVisible={3}
                    prependBadges={[
                        product.is_new && { key: 'new', label: 'Новинка', colorPalette: 'green' },
                        product.is_bestseller && { key: 'best', label: 'Хит', colorPalette: 'orange' },
                        hasSale && discountPct && { key: 'sale', label: `−${Math.round(discountPct)}%`, colorPalette: 'red' },
                        product.is_marked && { key: 'marked', label: 'Маркировка', colorPalette: 'yellow' },
                        formatShelfLife(product.shelf_life) && { key: 'shelf', label: formatShelfLife(product.shelf_life), colorPalette: 'gray' },
                    ].filter(Boolean)}
                />

                {/* Нижняя секция: статус + цена в одной строке, корзина — отдельной строкой */}
                {user && (
                    <Flex
                        mt={{ base: '1', md: 'auto' }}
                        pt={{ base: '0', md: '2' }}
                        direction="column"
                        gap="2"
                    >
                        {/* Строка 1: статус наличия + цена */}
                        <Flex align="center" gap="2">
                            {stockBlock}

                            {/* Осталась только уценка — обычная цена зачёркнута,
                                вместо неё цена уценки цветом статуса «Уценка» */}
                            {isDefectOnly && defectMinPrice != null ? (
                                <Flex align="baseline" gap="2" flexShrink="0" ml="auto">
                                    <Text fontSize="lg" fontWeight="600" lineHeight="1" color="purple.500">
                                        от {formatPrice(defectMinPrice)}
                                    </Text>
                                    {price != null && (
                                        <Text fontSize="xs" color="gray.400" textDecoration="line-through" lineHeight="1">
                                            {formatPrice(hasSale ? salePrice : price)}
                                        </Text>
                                    )}
                                </Flex>
                            ) : price != null && (
                                <Flex align="baseline" gap="2" flexShrink="0" ml="auto">
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
                        </Flex>

                        {/* Уценка — еле заметная подсказка о минимальной цене партий.
                            Когда осталась только уценка, цена уже показана фиолетовым выше */}
                        {product.has_defects && defectMinPrice != null && !isDefectOnly && (
                            <Text fontSize="2xs" color="gray.400" lineClamp="1">
                                Есть на уценке от {formatPrice(defectMinPrice)}
                            </Text>
                        )}

                        {/* Строка 2: контролы количества */}
                        {(isInStock || isPreorder) && (hasSale ? salePrice : price) > 0 && (
                            <Box
                                w={{ base: '140px', md: '160px' }}
                                onClick={(e) => { e.preventDefault(); e.stopPropagation(); }}
                            >
                                <CartQuantityControl
                                    productId={product.id}
                                    stockQuantity={product.stock_quantity ?? 0}
                                    preorderQuantity={product.preorder_quantity ?? 0}
                                    size="sm"
                                    fullWidth
                                />
                            </Box>
                        )}
                    </Flex>
                )}
            </Flex>
        </Flex>
    );
}
