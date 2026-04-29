import { memo, useEffect, useRef, useState, useCallback } from 'react';
import { Box, Flex, Text, Image, Badge, IconButton } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuTrash2 } from 'react-icons/lu';
import QuantityControl from '@/components/common/QuantityControl';
import { Checkbox } from '@/components/ui/checkbox';
import { Tooltip } from '@/components/ui/tooltip';
import { cartFrameProps, cartFrameTooltip, cartFrameState, splitQty } from '@/utils/cartFrame';
import { useLocalQuantity } from '@/hooks/useLocalQuantity';
import { useCartStore } from '@/stores/useCartStore';

/**
 * Mobile-карточка строки корзины. Селективная подписка на стор по pid.
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

    // Серверный split per-pid (синхронизирован между корзиной/страницей товара)
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

    // ВАЖНО: все хуки до early-return, иначе React падает на «Rendered more hooks»
    const handleLimitReached = useCallback(() => {
        triggerShake();
    }, [triggerShake]);

    if (totalQty <= 0) return null;

    const stockOnly = Math.max(0, maxTotal - preorderShareFromServer);
    const { instock: instockQty, preorder: preorderQty } =
        serverSplit && (serverSplit.instock + serverSplit.preorder) === totalQty
            ? serverSplit
            : splitQty(totalQty, stockOnly);
    const tip = cartFrameTooltip(instockQty, preorderQty);
    const tintState = cartFrameState(instockQty, preorderQty);

    // Tint карточки появляется только при hover (на десктопе).
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

    return (
        <Box
            position="relative"
            borderWidth="1px"
            borderColor="border"
            rounded="lg"
            {...tintProps}
            boxShadow={flashing ? 'inset 0 0 0 2px var(--chakra-colors-green-500)' : undefined}
            transition="background-color 220ms ease-out, box-shadow 600ms ease-out"
            p="3"
            opacity={isUnavailable ? 0.5 : 1}
        >
            <Box position="absolute" top="2" right="2">
                <Checkbox
                    checked={selected}
                    onCheckedChange={(e) => onToggle(pid, !!e.checked)}
                />
            </Box>

            <Flex gap="3" align="flex-start">
                {(product?.thumbnail_url || product?.main_image_url) && (
                    <Image
                        src={product.thumbnail_url || product.main_image_url}
                        alt={product?.name || ''}
                        w="64px"
                        aspectRatio="2/3"
                        objectFit="cover"
                        rounded="sm"
                        borderWidth="1px"
                        borderColor="border.muted"
                        flexShrink={0}
                    />
                )}
                <Box flex="1" minW="0">
                    <Box pr="6">
                        <Text fontWeight="medium" lineHeight="snug" lineClamp={2}>
                            {product?.slug ? (
                                <Link href={`/products/${product.slug}`}>
                                    {product?.name || 'Товар недоступен'}
                                </Link>
                            ) : (
                                product?.name || 'Товар недоступен'
                            )}
                        </Text>
                        <Text fontSize="xs" color="fg.muted" lineClamp={1}>
                            {product?.brand?.name || '—'}
                            {product?.sku ? ` • ${product.sku}` : ''}
                        </Text>
                        {isUnavailable && (
                            <Badge colorPalette="red" variant="subtle" fontSize="2xs" mt="1">
                                Недоступен
                            </Badge>
                        )}
                    </Box>

                    <Flex mt="2" justify="space-between" align="center" gap="3">
                        <Box>
                            <Text fontSize="xs" color="fg.muted">
                                Цена ({currencySymbol})
                            </Text>
                            {priceRegular !== priceDiscounted && (
                                <Text fontSize="xs" color="fg.muted" textDecoration="line-through">
                                    {priceRegular.toLocaleString('ru-RU')}
                                </Text>
                            )}
                            <Text fontWeight="medium">
                                {priceDiscounted.toLocaleString('ru-RU')}
                            </Text>
                        </Box>
                        {tip ? (
                            <Tooltip content={tip} positioning={{ placement: 'top' }} openDelay={250} closeDelay={0}>
                                {counterBox}
                            </Tooltip>
                        ) : counterBox}
                    </Flex>

                    {hasPreorderItems && preorderQty > 0 && (
                        <Text fontSize="xs" color="orange.600" mt="1">
                            Предзаказ: {preorderQty} шт
                        </Text>
                    )}

                    <Flex mt="2" justify="space-between" align="center">
                        <Box fontSize="sm">
                            <Text as="span" color="fg.muted">
                                Сумма ({currencySymbol}):{' '}
                            </Text>
                            {sumRegular !== sumDiscounted && (
                                <Text as="span" color="fg.muted" textDecoration="line-through" mr="1">
                                    {sumRegular.toLocaleString('ru-RU')}
                                </Text>
                            )}
                            <Text as="span" fontWeight="medium">
                                {sumDiscounted.toLocaleString('ru-RU')}
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
                </Box>
            </Flex>
        </Box>
    );
}

export default memo(CartMobileCard);
