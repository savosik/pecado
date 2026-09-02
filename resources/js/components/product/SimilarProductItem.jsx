import { useState } from 'react';
import { Box, Flex, Text, IconButton, Badge } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuClock3, LuHeart, LuCheck, LuCircleX, LuImageOff, LuBadgePercent } from 'react-icons/lu';
import CartQuantityControl from '@/components/product/CartQuantityControl';
import { useProductHelpers } from '@/hooks/useProductHelpers';

/**
 * Горизонтальная карточка похожего товара — повторяет вид строки из
 * выпадающего списка поиска (SearchDropdown ProductItem).
 *
 * @param {{ product: Object }} props
 */
export default function SimilarProductItem({ product }) {
    const {
        user, isFav, toggleFavorite, formatPrice,
        hasSale, isInStock, isPreorder, isDefectOnly, brandName,
        price, salePrice, discountPct,
    } = useProductHelpers(product);

    const imageUrl = product.thumbnail || product.image_url || product.thumb_url || product.main_image;
    const [imageBroken, setImageBroken] = useState(false);
    const showPlaceholder = !imageUrl || imageBroken;

    return (
        <Flex
            align="flex-start"
            gap="2.5"
            px="3"
            py="1.5"
            borderBottom="1px solid"
            borderColor="border.muted"
            _hover={{ bg: 'gray.50' }}
            _dark={{ _hover: { bg: 'gray.700' } }}
            transition="background 0.15s"
        >
            {/* Миниатюра — пропорция 2:3 */}
            <Box
                as={Link}
                href={`/products/${product.slug}`}
                w={{ base: '48px', md: '56px' }}
                h={{ base: '72px', md: '84px' }}
                flexShrink="0"
                borderRadius="md"
                overflow="hidden"
                bg="gray.100"
                _dark={{ bg: 'gray.700' }}
                display="flex"
                alignItems="center"
                justifyContent="center"
            >
                {showPlaceholder ? (
                    <LuImageOff size={18} color="var(--chakra-colors-gray-400)" />
                ) : (
                    <Box
                        as="img"
                        src={imageUrl}
                        alt=""
                        loading="lazy"
                        w="100%"
                        h="100%"
                        objectFit="cover"
                        onError={() => setImageBroken(true)}
                    />
                )}
            </Box>

            {/* Информация */}
            <Flex flex="1" minW="0" direction="column" gap="0.5">
                {/* Артикул / бренд / бейджи + сердечко */}
                <Flex align="center" justify="space-between" gap="2">
                    <Flex flex="1" minW="0" align="center" gap="1.5" wrap="wrap" lineHeight="1">
                        {product.sku && (
                            <Text fontSize="xs" fontWeight="700" color="gray.500">
                                {product.sku}
                            </Text>
                        )}
                        {brandName && (
                            <>
                                {product.sku && <Text fontSize="xs" color="gray.300">/</Text>}
                                {product.brand_slug ? (
                                    <Text
                                        as={Link}
                                        href={`/brands/${product.brand_slug}`}
                                        fontSize="xs"
                                        color="gray.500"
                                        textTransform="capitalize"
                                        _hover={{ textDecoration: 'underline' }}
                                        lineClamp="1"
                                    >
                                        {brandName}
                                    </Text>
                                ) : (
                                    <Text
                                        fontSize="xs"
                                        color="gray.500"
                                        textTransform="capitalize"
                                        lineClamp="1"
                                    >
                                        {brandName}
                                    </Text>
                                )}
                            </>
                        )}
                        {product.is_bestseller && (
                            <Badge colorPalette="orange" fontSize="2xs" fontWeight="700" borderRadius="md" px="1">
                                Хит
                            </Badge>
                        )}
                        {product.is_new && (
                            <Badge colorPalette="green" fontSize="2xs" fontWeight="700" borderRadius="md" px="1">
                                Новинка
                            </Badge>
                        )}
                        {hasSale && discountPct && (
                            <Badge colorPalette="red" fontSize="2xs" fontWeight="700" borderRadius="md" px="1">
                                −{Math.round(discountPct)}%
                            </Badge>
                        )}
                        {product.is_marked && (
                            <Badge colorPalette="yellow" fontSize="2xs" fontWeight="700" borderRadius="md" px="1">
                                Маркировка
                            </Badge>
                        )}
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
                            minW="5"
                            h="5"
                            flexShrink="0"
                        >
                            <LuHeart size={13} fill={isFav ? 'currentColor' : 'none'} />
                        </IconButton>
                    )}
                </Flex>

                {/* Название */}
                <Text
                    as={Link}
                    href={`/products/${product.slug}`}
                    fontSize="sm"
                    fontWeight="500"
                    lineHeight="1.25"
                    display="-webkit-box"
                    css={{
                        WebkitLineClamp: 2,
                        WebkitBoxOrient: 'vertical',
                        overflow: 'hidden',
                    }}
                    _hover={{ textDecoration: 'underline' }}
                >
                    {product.name}
                </Text>

                {/* Нижняя строка: статус + цена + корзина — только для авторизованных */}
                {user && (
                    <Flex
                        align="center"
                        justify="space-between"
                        gap="2"
                        wrap="wrap"
                    >
                        {/* Статус наличия */}
                        <Flex align="center" gap="1" fontSize="xs" fontWeight="500" flexShrink="0">
                            {isInStock ? (
                                <>
                                    <LuCheck size={12} color="var(--chakra-colors-green-600)" />
                                    <Text color="green.600" lineClamp="1">В наличии</Text>
                                </>
                            ) : isPreorder ? (
                                <>
                                    <LuClock3 size={12} color="var(--chakra-colors-orange-500)" />
                                    <Text color="orange.500" lineClamp="1">Предзаказ</Text>
                                </>
                            ) : isDefectOnly ? (
                                <>
                                    <LuBadgePercent size={12} color="var(--chakra-colors-purple-500)" />
                                    <Text color="purple.500" lineClamp="1">Уценка</Text>
                                </>
                            ) : (
                                <>
                                    <LuCircleX size={12} color="var(--chakra-colors-red-600)" />
                                    <Text color="red.600" lineClamp="1">Нет в наличии</Text>
                                </>
                            )}
                        </Flex>

                        {/* Цена + компактный контрол корзины */}
                        <Flex align="center" gap="2" flexShrink="0">
                            {price != null && (
                                <Flex align="baseline" gap="1" lineHeight="1">
                                    {hasSale && (
                                        <Text fontSize="2xs" color="gray.400" textDecoration="line-through">
                                            {formatPrice(price)}
                                        </Text>
                                    )}
                                    <Text
                                        fontSize="sm"
                                        fontWeight="700"
                                        color={hasSale ? 'red.600' : undefined}
                                    >
                                        {formatPrice(hasSale ? salePrice : price)}
                                    </Text>
                                </Flex>
                            )}
                            {(isInStock || isPreorder) && (hasSale ? salePrice : price) > 0 && (
                                <Box onClick={(e) => { e.preventDefault(); e.stopPropagation(); }}>
                                    <CartQuantityControl
                                        productId={product.id}
                                        stockQuantity={product.stock_quantity ?? 0}
                                        preorderQuantity={product.preorder_quantity ?? 0}
                                        size="xs"
                                        variant="compact"
                                    />
                                </Box>
                            )}
                        </Flex>
                    </Flex>
                )}
            </Flex>
        </Flex>
    );
}
