import { memo, useEffect, useRef, useState, useCallback } from 'react';
import { Box, Flex, Text, Image, Badge, IconButton } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuTrash2, LuWarehouse, LuTruck, LuImageOff } from 'react-icons/lu';
import QuantityControl from '@/components/common/QuantityControl';
import { Checkbox } from '@/components/ui/checkbox';
import { Tooltip } from '@/components/ui/tooltip';
import TagList from '@/components/product/TagList';
import { cartFrameProps, cartFrameTooltip, cartFrameState, splitQty } from '@/utils/cartFrame';
import { useLocalQuantity } from '@/hooks/useLocalQuantity';
import { useCartStore } from '@/stores/useCartStore';

/**
 * Mobile-карточка строки корзины. Визуально совпадает с каталоговым ProductListItem
 * (картинка 2:3, sku/бренд inline, название без жирного, теги),
 * плюс нижняя полоска: чекбокс + остатки в наличии/предзаказе + итого + удалить.
 */
function CartMobileCard({
    pid,
    product,
    priceRegular,
    priceDiscounted,
    maxTotal,
    isUnavailable,
    selected,
    hasPreorderItems,
    preorderShareFromServer,
    currencySymbol,
    onSetQty,
    onRemove,
    onToggle,
}) {
    const [totalQty, handleChange] = useLocalQuantity(pid, onSetQty);
    const serverSplit = useCartStore((s) => s.productSplits[pid]);

    const [shakeKey, setShakeKey] = useState(0);
    const triggerShake = useCallback(() => setShakeKey((k) => k + 1), []);

    const [flashing, setFlashing] = useState(false);
    const prevQtyRef = useRef(totalQty);
    const initRef = useRef(false);
    useEffect(() => {
        if (!initRef.current) {
            initRef.current = true;
            prevQtyRef.current = totalQty;
            return;
        }
        if (prevQtyRef.current === totalQty) return;
        prevQtyRef.current = totalQty;
        setFlashing(true);
        const t = setTimeout(() => setFlashing(false), 700);
        return () => clearTimeout(t);
    }, [totalQty]);

    const handleLimitReached = useCallback(() => {
        triggerShake();
    }, [triggerShake]);

    const [imageError, setImageError] = useState(false);

    if (totalQty <= 0) return null;

    const stockOnly = Math.max(0, maxTotal - preorderShareFromServer);
    const { instock: instockQty, preorder: preorderQty } =
        serverSplit && (serverSplit.instock + serverSplit.preorder) === totalQty
            ? serverSplit
            : splitQty(totalQty, stockOnly);
    const tip = cartFrameTooltip(instockQty, preorderQty);
    const tintState = cartFrameState(instockQty, preorderQty);

    const TINT_HOVER = {
        instock:  { _hover: { bg: 'green.50' },  _dark: { _hover: { bg: 'green.900/20' } } },
        preorder: { _hover: { bg: 'orange.50' }, _dark: { _hover: { bg: 'orange.900/20' } } },
    };
    const baseTint = tintState === 'mixed'
        ? { bg: 'bg', className: 'cart-card-mixed' }
        : { bg: 'bg', ...(TINT_HOVER[tintState] || { _hover: { bg: 'gray.50' } }) };
    const tintProps = selected
        ? { bg: 'pecado.50', _dark: { bg: 'pecado.900/30' } }
        : baseTint;

    const sumRegular = priceRegular * totalQty;
    const sumDiscounted = priceDiscounted * totalQty;
    const hasDiscount = priceRegular !== priceDiscounted;

    const counterBox = (
        <Box
            key={shakeKey}
            display="inline-block"
            borderWidth="2px"
            rounded="md"
            overflow="hidden"
            transition="border-color 220ms ease-out, box-shadow 220ms ease-out, background 220ms ease-out"
            animation={shakeKey ? 'cart-shake 360ms ease-in-out' : undefined}
            {...cartFrameProps(instockQty, preorderQty)}
        >
            <QuantityControl
                value={totalQty}
                onChange={handleChange}
                onLimitReached={handleLimitReached}
                min={1}
                max={maxTotal > 0 ? maxTotal : undefined}
                size="sm"
                outerBorder={false}
            />
        </Box>
    );

    const brandName = product?.brand?.name;
    const imageSrc = product?.thumbnail_url || product?.main_image_url || product?.thumbnail || product?.main_image;
    const showImage = imageSrc && !imageError;

    return (
        <Box
            position="relative"
            borderTopWidth="1px"
            borderBottomWidth="1px"
            borderLeftWidth={{ base: '0', md: '1px' }}
            borderRightWidth={{ base: '0', md: '1px' }}
            borderStyle="solid"
            borderColor="border"
            rounded={{ base: 'none', md: 'lg' }}
            mt={{ base: '-1px', md: '0' }}
            {...tintProps}
            boxShadow={flashing ? 'inset 0 0 0 2px var(--chakra-colors-green-500)' : undefined}
            transition="background-color 220ms ease-out, box-shadow 600ms ease-out"
            opacity={isUnavailable ? 0.5 : 1}
            overflow="hidden"
        >
            {/* Верхняя часть: картинка + контент (как в ProductListItem) */}
            <Flex
                direction="row"
                align="flex-start"
                p="2"
                gap="3"
            >
                {/* Изображение 2:3 */}
                <Box
                    flexShrink="0"
                    w={{ base: '90px', md: '120px' }}
                    h={{ base: '135px', md: '180px' }}
                    overflow="hidden"
                    borderRadius="md"
                    bg="gray.100"
                    _dark={{ bg: 'gray.700' }}
                    display="flex"
                    alignItems="center"
                    justifyContent="center"
                >
                    {showImage ? (
                        <Image
                            src={imageSrc}
                            alt=""
                            w="100%"
                            h="100%"
                            objectFit="cover"
                            onError={() => setImageError(true)}
                        />
                    ) : (
                        <Flex direction="column" align="center" gap="1" color="gray.400" _dark={{ color: 'gray.500' }}>
                            <LuImageOff size={20} />
                            <Text fontSize="2xs">Нет фото</Text>
                        </Flex>
                    )}
                </Box>

                {/* Контент */}
                <Flex flex="1" direction="column" gap="1" minW="0">
                    {/* SKU / Бренд */}
                    <Flex align="baseline" gap="1.5" flexWrap="wrap" rowGap="0">
                        {product?.sku && (
                            <Text fontSize="xs" fontWeight="700" color="gray.500" lineHeight="1.3">
                                {product.sku}
                            </Text>
                        )}
                        {brandName && product?.sku && (
                            <Text fontSize="xs" color="gray.300" lineHeight="1.3">/</Text>
                        )}
                        {brandName && (
                            <Text
                                fontSize="2xs"
                                textTransform="capitalize"
                                letterSpacing="wide"
                                color="gray.400"
                                lineHeight="1.3"
                            >
                                {brandName}
                            </Text>
                        )}
                    </Flex>

                    {/* Название */}
                    <Text
                        as={product?.slug ? Link : 'span'}
                        href={product?.slug ? `/products/${product.slug}` : undefined}
                        fontSize="13px"
                        fontWeight="400"
                        lineHeight="1.3"
                        _hover={product?.slug ? { textDecoration: 'underline' } : undefined}
                        display="-webkit-box"
                        css={{
                            WebkitLineClamp: 3,
                            WebkitBoxOrient: 'vertical',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                        }}
                    >
                        {product?.name || 'Товар недоступен'}
                    </Text>

                    {/* Теги (если есть) */}
                    {product?.tags && product.tags.length > 0 && (
                        <TagList tags={product.tags} maxVisible={3} />
                    )}

                    {isUnavailable && (
                        <Badge colorPalette="red" variant="subtle" fontSize="2xs" mt="1" alignSelf="flex-start">
                            Недоступен
                        </Badge>
                    )}

                    {/* Цена | (Остатки + Counter — единым блоком справа) */}
                    <Flex mt="2" align="center" gap="2" flexWrap="wrap" rowGap="2">
                        <Box>
                            <Text fontSize="2xs" color="fg.muted" lineHeight="1">
                                Цена
                            </Text>
                            {hasDiscount && (
                                <Text fontSize="xs" color="fg.muted" textDecoration="line-through" lineHeight="1.2">
                                    {priceRegular.toLocaleString('ru-RU')}
                                </Text>
                            )}
                            <Text fontWeight="600" fontSize="md" lineHeight="1.2">
                                {priceDiscounted.toLocaleString('ru-RU')}
                            </Text>
                        </Box>

                        <Flex ml="auto" direction="column" align="end" gap="1" flexShrink="0">
                            {tip ? (
                                <Tooltip content={tip} positioning={{ placement: 'top' }} openDelay={250} closeDelay={0}>
                                    {counterBox}
                                </Tooltip>
                            ) : counterBox}

                            {/* Остатки: иконки под counter-ом (скрываются, если всё со склада) */}
                            {(preorderQty > 0) && (
                                <Flex align="center" gap="2" fontSize="sm" fontWeight="600">
                                    {instockQty > 0 && (
                                        <Flex align="center" gap="1" color="green.600" aria-label={`В наличии: ${instockQty}`} title={`В наличии: ${instockQty}`}>
                                            <LuWarehouse size={16} />
                                            <Text>{instockQty}</Text>
                                        </Flex>
                                    )}
                                    <Flex align="center" gap="1" color="yellow.600" aria-label={`Предзаказ: ${preorderQty}`} title={`Предзаказ: ${preorderQty}`}>
                                        <LuTruck size={16} />
                                        <Text>{preorderQty}</Text>
                                    </Flex>
                                </Flex>
                            )}
                        </Flex>
                    </Flex>
                </Flex>
            </Flex>

            {/* Нижняя полоска: чекбокс + остатки + итого + удалить */}
            <Flex
                align="center"
                gap="2"
                px="2"
                py="1.5"
                borderTopWidth="1px"
                borderColor="border.muted"
                bg="bg.subtle"
                _dark={{ bg: 'gray.900/40' }}
                flexWrap="wrap"
                rowGap="1"
            >
                {/* Чекбокс */}
                <Box flexShrink="0">
                    <Checkbox
                        checked={selected}
                        onCheckedChange={(e) => onToggle(pid, !!e.checked)}
                    />
                </Box>

                {/* Итого + удалить */}
                <Flex ml="auto" align="center" gap="2" flexShrink="0">
                    <Box fontSize="sm" textAlign="right">
                        <Text as="span" fontSize="2xs" color="fg.muted" mr="1">Итого:</Text>
                        {hasDiscount && (
                            <Text as="span" fontSize="xs" color="fg.muted" textDecoration="line-through" mr="1">
                                {sumRegular.toLocaleString('ru-RU')}
                            </Text>
                        )}
                        <Text as="span" fontWeight="700">
                            {sumDiscounted.toLocaleString('ru-RU')} {currencySymbol}
                        </Text>
                    </Box>
                    <IconButton
                        aria-label="Удалить"
                        variant="ghost"
                        size="xs"
                        colorPalette="red"
                        onClick={() => onRemove(pid)}
                    >
                        <LuTrash2 size={14} />
                    </IconButton>
                </Flex>
            </Flex>
        </Box>
    );
}

export default memo(CartMobileCard);
