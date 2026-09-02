import { memo, useEffect, useRef, useState, useCallback } from 'react';
import { Box, Flex, Text, Table, Image, Badge, IconButton } from '@chakra-ui/react';
import { Link, usePage } from '@inertiajs/react';
import { LuTrash2 } from 'react-icons/lu';
import QuantityControl from '@/components/common/QuantityControl';
import { Checkbox } from '@/components/ui/checkbox';
import { Tooltip } from '@/components/ui/tooltip';
import { cartFrameProps, cartFrameTooltip, cartRowTint, splitQty } from '@/utils/cartFrame';
import { useLocalQuantity } from '@/hooks/useLocalQuantity';
import { useCartStore } from '@/stores/useCartStore';
import { formatMoney } from '@/utils/formatPrice';

/**
 * Desktop-строка таблицы корзины. Селективная подписка на стор по pid —
 * ререндерится только когда меняется именно этот товар.
 */
function CartTableRow({
    pid,
    product,
    priceRegular,
    priceDiscounted,
    maxTotal,
    isUnavailable,
    selected,
    hasPreorderItems,
    instockShareFromServer,
    preorderShareFromServer,
    onSetQty,
    onRemove,
    onToggle,
}) {
    // Срок поставки предзаказа — в подсказку к счётчику.
    const leadLabel = usePage().props.preorder?.lead_label ?? '';

    // Локальный qty с debounced пушем в store (220 мс) — счётчик летает,
    // итоги/футер обновляются только в конце серии.
    const [totalQty, handleChange] = useLocalQuantity(pid, onSetQty);

    // Серверный split per-pid (для согласованной цветной рамки между корзиной
    // и страницей товара/каталогом). Fallback — локальная оценка ниже.
    const serverSplit = useCartStore((s) => s.productSplits[pid]);

    // Локальный shake при превышении max_total
    const [shakeKey, setShakeKey] = useState(0);
    const triggerShake = useCallback(() => setShakeKey((k) => k + 1), []);

    // Локальный flash на 700 мс после изменения qty
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

    // Распределение между складом и предзаказом. Приоритет — серверный
    // split (точное состояние корзины), fallback — оценка от max_total
    // и preorder-доли из cartDetails.
    const stockOnly = Math.max(0, maxTotal - preorderShareFromServer);
    const { instock: instockQty, preorder: preorderQty } =
        serverSplit && (serverSplit.instock + serverSplit.preorder) === totalQty
            ? serverSplit
            : splitQty(totalQty, stockOnly);
    const tip = cartFrameTooltip(instockQty, preorderQty, leadLabel);

    const sumDiscounted = priceDiscounted * totalQty;
    const discountPercent = priceRegular > 0 && priceRegular !== priceDiscounted
        ? Math.round((1 - priceDiscounted / priceRegular) * 100)
        : 0;

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
        <Table.Row
            opacity={isUnavailable ? 0.5 : 1}
            {...(selected
                ? { bg: 'pecado.50', _hover: { bg: 'pecado.100' }, _dark: { bg: 'pecado.900/30', _hover: { bg: 'pecado.900/50' } } }
                : cartRowTint(instockQty, preorderQty))}
            boxShadow={flashing ? 'inset 0 0 0 2px var(--chakra-colors-green-500)' : undefined}
            transition="background-color 220ms ease-out, box-shadow 600ms ease-out"
        >
            <Table.Cell textAlign="center" verticalAlign="middle">
                <Checkbox
                    checked={selected}
                    onCheckedChange={(e) => onToggle(pid, !!e.checked)}
                    aria-label="Выбрать товар для действий"
                    title="Выбрать товар для действий"
                />
            </Table.Cell>
            <Table.Cell verticalAlign="middle">
                <Flex gap="2" align="center" minW="0">
                    {(product?.thumbnail_url || product?.main_image_url) && (
                        <Image
                            src={product.thumbnail_url || product.main_image_url}
                            alt={product?.name || ''}
                            h="28px"
                            w="20px"
                            objectFit="cover"
                            borderWidth="1px"
                            borderColor="border.muted"
                            flexShrink={0}
                        />
                    )}
                    <Box minW="0" flex="1">
                        <Box fontWeight="medium" lineHeight="1.25" overflow="hidden">
                            {product?.slug ? (
                                <Link href={`/products/${product.slug}`} style={{ display: 'block' }}>
                                    <Text fontSize="xs" _hover={{ textDecoration: 'underline' }} lineClamp={2}>
                                        {product?.name || 'Товар недоступен'}
                                    </Text>
                                </Link>
                            ) : (
                                <Text fontSize="xs" lineClamp={2}>{product?.name || 'Товар недоступен'}</Text>
                            )}
                        </Box>
                        <Text fontSize="2xs" color="fg.muted" lineClamp={1}>
                            {product?.brand?.name
                                ? (product?.sku ? `${product.brand.name} • ${product.sku}` : product.brand.name)
                                : (product?.sku || '—')}
                        </Text>
                        {isUnavailable && (
                            <Badge colorPalette="red" variant="subtle" fontSize="2xs" mt="0.5">Недоступен</Badge>
                        )}
                    </Box>
                </Flex>
            </Table.Cell>
            <Table.Cell textAlign="center" verticalAlign="middle">
                {tip ? (
                    <Tooltip content={tip} positioning={{ placement: 'top' }} openDelay={250} closeDelay={0}>
                        {counterBox}
                    </Tooltip>
                ) : counterBox}
            </Table.Cell>
            <Table.Cell textAlign="center" verticalAlign="middle" fontSize="xs">
                {instockQty}
            </Table.Cell>
            {hasPreorderItems && (
                <Table.Cell textAlign="center" verticalAlign="middle" fontSize="xs">
                    {preorderQty}
                </Table.Cell>
            )}
            <Table.Cell textAlign="right" verticalAlign="middle" fontSize="xs">
                {formatMoney(priceRegular)}
            </Table.Cell>
            <Table.Cell textAlign="right" verticalAlign="middle" fontSize="xs">
                {discountPercent > 0 ? `${discountPercent}%` : '—'}
            </Table.Cell>
            <Table.Cell textAlign="right" verticalAlign="middle" fontSize="xs">
                {formatMoney(priceDiscounted)}
            </Table.Cell>
            <Table.Cell textAlign="right" verticalAlign="middle" fontWeight="medium" fontSize="xs">
                {formatMoney(sumDiscounted)}
            </Table.Cell>
            <Table.Cell verticalAlign="middle">
                <IconButton
                    aria-label="Удалить"
                    variant="ghost"
                    size="xs"
                    colorPalette="red"
                    onClick={() => onRemove(pid)}
                >
                    <LuTrash2 size={14} />
                </IconButton>
            </Table.Cell>
        </Table.Row>
    );
}

export default memo(CartTableRow);
