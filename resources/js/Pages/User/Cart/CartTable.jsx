import { useMemo, useCallback } from 'react';
import {
    Box, Flex, Text, Table, Image, Badge, IconButton, HStack,
} from '@chakra-ui/react';
import { Link, usePage } from '@inertiajs/react';
import { LuTrash2, LuChevronUp, LuChevronDown } from 'react-icons/lu';
import QuantityControl from '@/components/common/QuantityControl';
import { Checkbox } from '@/components/ui/checkbox';

// ── FIX #3: SortableHeader вынесен за пределы компонента ──
const SortableHeader = ({ label, columnKey, sortKey, sortDir, onSort, ...rest }) => (
    <Box
        as="button"
        type="button"
        onClick={() => onSort(columnKey)}
        display="inline-flex"
        alignItems="center"
        gap="1"
        cursor="pointer"
        fontWeight="medium"
        fontSize="xs"
        textTransform="uppercase"
        letterSpacing="wide"
        color="fg.muted"
        _hover={{ color: 'fg' }}
        {...rest}
    >
        {label}
        {sortKey === columnKey && (
            sortDir === 'asc' ? <LuChevronUp size={14} /> : <LuChevronDown size={14} />
        )}
    </Box>
);

// ── FIX #4: вычисление данных строки вынесено в функцию ──
function computeRowData(row, pickPricePair) {
    const pid = Number(row.product?.id);
    const instockQty = Number(row.instock?.quantity || 0);
    const preorderQty = Number(row.preorder?.quantity || 0);
    const totalQty = instockQty + preorderQty;
    const { regular: priceRegular, discounted: priceDiscounted } = pickPricePair(row);
    const sumRegular =
        Number(row.instock?.total_amount_regular ?? row.instock?.total_amount ?? 0) +
        Number(row.preorder?.total_amount_regular ?? row.preorder?.total_amount ?? 0);
    const sumDiscounted =
        Number(row.instock?.total_amount_discounted ?? row.instock?.total_amount ?? 0) +
        Number(row.preorder?.total_amount_discounted ?? row.preorder?.total_amount ?? 0);
    // FIX #8: correct unavailable logic when one type is missing
    const isUnavailable =
        (row.instock ? !!row.instock.is_unavailable : true) &&
        (row.preorder ? !!row.preorder.is_unavailable : true);

    const discountPercent = priceRegular > 0 && priceRegular !== priceDiscounted
        ? Math.round((1 - priceDiscounted / priceRegular) * 100)
        : 0;
    const savingsTotal = sumRegular - sumDiscounted;

    return { pid, instockQty, preorderQty, totalQty, priceRegular, priceDiscounted, sumRegular, sumDiscounted, isUnavailable, discountPercent, savingsTotal };
}

/**
 * Таблица товаров корзины с desktop-таблицей и мобильными карточками.
 *
 * @param {{
 *   items: Array,
 *   selected: Set<number>,
 *   onToggleAll: (checked: boolean) => void,
 *   onToggleOne: (id: number, checked: boolean) => void,
 *   sortKey: string,
 *   sortDir: 'asc' | 'desc',
 *   onSort: (key: string) => void,
 *   onSetProductQuantity: (productId: number, qty: number) => void,
 *   onRemove: (productId: number) => void,
 *   hasPreorderItems: boolean,
 * }} props
 */
export default function CartTable({
    items,
    selected,
    onToggleAll,
    onToggleOne,
    sortKey,
    sortDir,
    onSort,
    onSetProductQuantity,
    onRemove,
    hasPreorderItems,
}) {
    const { currency } = usePage().props;
    const currencySymbol = currency?.symbol ?? '₽';

    // ── Merge items by product_id ───────────────
    const rows = useMemo(() => {
        const byProduct = new Map();
        for (const it of items) {
            const pid = Number(it.product?.id);
            if (!pid) continue;
            if (!byProduct.has(pid)) {
                byProduct.set(pid, { product: it.product, instock: null, preorder: null });
            }
            if (it.item_type === 'instock') byProduct.get(pid).instock = it;
            if (it.item_type === 'preorder') byProduct.get(pid).preorder = it;
        }
        return Array.from(byProduct.values());
    }, [items]);

    // ── Price helpers ───────────────────────────
    const pickPricePair = useCallback((row) => {
        const item = row.instock || row.preorder;
        if (item) {
            return {
                regular: Number(item.price_regular ?? item.price ?? 0),
                discounted: Number(item.price_discounted ?? item.price ?? 0),
            };
        }
        return { regular: 0, discounted: 0 };
    }, []);

    // ── Sorting ─────────────────────────────────
    const sortedRows = useMemo(() => {
        const getSortValue = (row) => {
            switch (sortKey) {
                case 'name':
                    return String(row.product?.name || '');
                case 'brand':
                    return String(row.product?.brand?.name || '');
                case 'sku':
                    return String(row.product?.sku || '');
                case 'qty':
                    return Number((row.instock?.quantity || 0) + (row.preorder?.quantity || 0));
                case 'instock':
                    return Number(row.instock?.quantity || 0);
                case 'preorder':
                    return Number(row.preorder?.quantity || 0);
                case 'price': {
                    const pair = pickPricePair(row);
                    return Number(pair.discounted || pair.regular || 0);
                }
                case 'sum': {
                    const total =
                        Number(row.instock?.total_amount_discounted ?? row.instock?.total_amount ?? 0) +
                        Number(row.preorder?.total_amount_discounted ?? row.preorder?.total_amount ?? 0);
                    return total;
                }
                default:
                    return 0;
            }
        };

        const arr = [...rows];
        arr.sort((a, b) => {
            const va = getSortValue(a);
            const vb = getSortValue(b);
            if (typeof va === 'string' || typeof vb === 'string') {
                const res = String(va).localeCompare(String(vb), 'ru', { sensitivity: 'base', numeric: true });
                return sortDir === 'asc' ? res : -res;
            }
            const res = va === vb ? 0 : va < vb ? -1 : 1;
            return sortDir === 'asc' ? res : -res;
        });
        return arr;
    }, [rows, sortKey, sortDir, pickPricePair]);

    // ── Selection helpers ───────────────────────
    const allIds = useMemo(
        () => sortedRows.map((r) => Number(r.product?.id)).filter(Boolean),
        [sortedRows],
    );
    const allSelected = selected.size > 0 && allIds.every((id) => selected.has(id));

    // ── Totals ──────────────────────────────────
    const totals = useMemo(() => {
        let totalInstock = 0;
        let totalPreorder = 0;
        let totalAmountRegular = 0;
        let totalAmountDiscounted = 0;

        rows.forEach((row) => {
            totalInstock += Number(row.instock?.quantity || 0);
            totalPreorder += Number(row.preorder?.quantity || 0);
            totalAmountRegular +=
                Number(row.instock?.total_amount_regular ?? row.instock?.total_amount ?? 0) +
                Number(row.preorder?.total_amount_regular ?? row.preorder?.total_amount ?? 0);
            totalAmountDiscounted +=
                Number(row.instock?.total_amount_discounted ?? row.instock?.total_amount ?? 0) +
                Number(row.preorder?.total_amount_discounted ?? row.preorder?.total_amount ?? 0);
        });

        return { totalInstock, totalPreorder, totalAmountRegular, totalAmountDiscounted };
    }, [rows]);

    return (
        <Box>
            {/* ═══ Desktop table ═══ */}
            <Box
                display={{ base: 'none', lg: 'block' }}
                overflowX="auto"
            >
                <Table.Root size="sm">
                    <Table.Header>
                        <Table.Row bg="gray.100" _dark={{ bg: 'gray.800' }}>
                            <Table.ColumnHeader w="40px" textAlign="center">
                                <Checkbox
                                    checked={allSelected}
                                    onCheckedChange={(e) => onToggleAll(!!e.checked)}
                                />
                            </Table.ColumnHeader>
                            <Table.ColumnHeader minW="200px">
                                <SortableHeader label="Название" columnKey="name" sortKey={sortKey} sortDir={sortDir} onSort={onSort} />
                            </Table.ColumnHeader>
                            <Table.ColumnHeader w="180px" textAlign="center">
                                <SortableHeader label="Заказано, шт" columnKey="qty" sortKey={sortKey} sortDir={sortDir} onSort={onSort} />
                            </Table.ColumnHeader>
                            <Table.ColumnHeader w="120px" textAlign="center">
                                <SortableHeader label="В наличии" columnKey="instock" sortKey={sortKey} sortDir={sortDir} onSort={onSort} />
                            </Table.ColumnHeader>
                            {hasPreorderItems && (
                                <Table.ColumnHeader w="120px" textAlign="center">
                                    <SortableHeader label="Предзаказ" columnKey="preorder" sortKey={sortKey} sortDir={sortDir} onSort={onSort} />
                                </Table.ColumnHeader>
                            )}
                            <Table.ColumnHeader w="120px">
                                <SortableHeader label={`Цена (${currencySymbol})`} columnKey="price" sortKey={sortKey} sortDir={sortDir} onSort={onSort} />
                            </Table.ColumnHeader>
                            <Table.ColumnHeader w="120px">
                                <SortableHeader label={`Сумма (${currencySymbol})`} columnKey="sum" sortKey={sortKey} sortDir={sortDir} onSort={onSort} />
                            </Table.ColumnHeader>
                            <Table.ColumnHeader w="50px" />
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {sortedRows.map((row, idx) => {
                            const data = computeRowData(row, pickPricePair);

                            return (
                                <Table.Row key={`p:${data.pid}`} opacity={data.isUnavailable ? 0.5 : 1}>
                                    <Table.Cell textAlign="center" verticalAlign="middle">
                                        <Checkbox
                                            checked={selected.has(data.pid)}
                                            onCheckedChange={(e) => onToggleOne(data.pid, !!e.checked)}
                                        />
                                    </Table.Cell>
                                    <Table.Cell verticalAlign="middle">
                                        <Flex gap="2" align="center" minW="0">
                                            {(row.product?.thumbnail_url || row.product?.main_image_url) && (
                                                <Image
                                                    src={row.product.thumbnail_url || row.product.main_image_url}
                                                    alt={row.product?.name || ''}
                                                    h="36px"
                                                    w="24px"
                                                    objectFit="cover"
                                                    borderWidth="1px"
                                                    borderColor="border.muted"
                                                    flexShrink={0}
                                                />
                                            )}
                                            <Box minW="0" flex="1">
                                                <Box fontWeight="medium" lineHeight="tight" overflow="hidden">
                                                    {row.product?.slug ? (
                                                        <Link
                                                            href={`/products/${row.product.slug}`}
                                                            style={{ display: 'block' }}
                                                        >
                                                            <Text _hover={{ textDecoration: 'underline' }} lineClamp={2}>
                                                                {row.product?.name || 'Товар недоступен'}
                                                            </Text>
                                                        </Link>
                                                    ) : (
                                                        <Text lineClamp={2}>{row.product?.name || 'Товар недоступен'}</Text>
                                                    )}
                                                </Box>
                                                <Text fontSize="xs" color="fg.muted" lineClamp={1}>
                                                    {row.product?.brand?.name
                                                        ? (row.product?.sku
                                                            ? `${row.product.brand.name} • ${row.product.sku}`
                                                            : row.product.brand.name)
                                                        : (row.product?.sku || '—')}
                                                </Text>
                                                {data.isUnavailable && (
                                                    <Badge colorPalette="red" variant="subtle" fontSize="2xs" mt="1">Недоступен</Badge>
                                                )}
                                            </Box>
                                        </Flex>
                                    </Table.Cell>
                                    <Table.Cell textAlign="center" verticalAlign="middle">
                                        <QuantityControl
                                            value={data.totalQty}
                                            onChange={(v) => onSetProductQuantity(data.pid, v)}
                                            min={0}
                                            size="md"
                                        />
                                    </Table.Cell>
                                    <Table.Cell textAlign="center" verticalAlign="middle">
                                        <Text>{data.instockQty}</Text>
                                    </Table.Cell>
                                    {hasPreorderItems && (
                                        <Table.Cell textAlign="center" verticalAlign="middle">
                                            <Text>{data.preorderQty}</Text>
                                        </Table.Cell>
                                    )}
                                    <Table.Cell verticalAlign="middle">
                                        {data.priceRegular !== data.priceDiscounted && (
                                            <Text fontSize="xs" color="fg.muted" textDecoration="line-through">
                                                {data.priceRegular.toLocaleString('ru-RU')}
                                            </Text>
                                        )}
                                        {data.discountPercent > 0 && (
                                            <Badge colorPalette="green" variant="subtle" size="xs" mb="0.5">
                                                -{data.discountPercent}%
                                            </Badge>
                                        )}
                                        <Text>{data.priceDiscounted.toLocaleString('ru-RU')}</Text>
                                    </Table.Cell>
                                    <Table.Cell verticalAlign="middle">
                                        {data.sumRegular !== data.sumDiscounted && (
                                            <Text fontSize="xs" color="fg.muted" textDecoration="line-through">
                                                {data.sumRegular.toLocaleString('ru-RU')}
                                            </Text>
                                        )}
                                        <Text fontWeight="medium">{data.sumDiscounted.toLocaleString('ru-RU')}</Text>
                                        {data.savingsTotal > 0 && (
                                            <Text fontSize="xs" color="green.600" _dark={{ color: 'green.400' }}>
                                                Экономия: {data.savingsTotal.toLocaleString('ru-RU')}
                                            </Text>
                                        )}
                                    </Table.Cell>
                                    <Table.Cell verticalAlign="middle">
                                        <IconButton
                                            aria-label="Удалить"
                                            variant="ghost"
                                            size="xs"
                                            colorPalette="red"
                                            onClick={() => onRemove(data.pid)}
                                        >
                                            <LuTrash2 size={14} />
                                        </IconButton>
                                    </Table.Cell>
                                </Table.Row>
                            );
                        })}

                        {/* ── Footer totals ── */}
                        <Table.Row fontWeight="semibold" bg="gray.50" _dark={{ bg: 'gray.800' }}>
                            <Table.Cell />
                            <Table.Cell>Итого</Table.Cell>
                            <Table.Cell textAlign="center">
                                {totals.totalInstock + totals.totalPreorder}
                            </Table.Cell>
                            <Table.Cell textAlign="center">{totals.totalInstock}</Table.Cell>
                            {hasPreorderItems && (
                                <Table.Cell textAlign="center">{totals.totalPreorder}</Table.Cell>
                            )}
                            <Table.Cell />
                            <Table.Cell>
                                {totals.totalAmountRegular !== totals.totalAmountDiscounted && (
                                    <Text fontSize="xs" color="fg.muted" textDecoration="line-through">
                                        {totals.totalAmountRegular.toLocaleString('ru-RU')}
                                    </Text>
                                )}
                                <Text fontWeight="semibold">
                                    {totals.totalAmountDiscounted.toLocaleString('ru-RU')}
                                </Text>
                                {totals.totalAmountRegular > totals.totalAmountDiscounted && (
                                    <Text fontSize="xs" color="green.600" _dark={{ color: 'green.400' }}>
                                        Ваша выгода: {(totals.totalAmountRegular - totals.totalAmountDiscounted).toLocaleString('ru-RU')} {currencySymbol}
                                    </Text>
                                )}
                            </Table.Cell>
                            <Table.Cell />
                        </Table.Row>
                    </Table.Body>
                </Table.Root>
            </Box>

            {/* ═══ Mobile cards ═══ */}
            <Box display={{ base: 'block', lg: 'none' }} spaceY="3">
                {sortedRows.map((row) => {
                    const data = computeRowData(row, pickPricePair);

                    return (
                        <Box
                            key={`m:${data.pid}`}
                            position="relative"
                            borderWidth="1px"
                            borderColor="border"
                            rounded="lg"
                            bg="bg"
                            p="3"
                            opacity={data.isUnavailable ? 0.5 : 1}
                        >
                            {/* Checkbox top-right */}
                            <Box position="absolute" top="2" right="2">
                                <Checkbox
                                    checked={selected.has(data.pid)}
                                    onCheckedChange={(e) => onToggleOne(data.pid, !!e.checked)}
                                />
                            </Box>

                            <Flex gap="3" align="flex-start">
                                {(row.product?.thumbnail_url || row.product?.main_image_url) && (
                                    <Image
                                        src={row.product.thumbnail_url || row.product.main_image_url}
                                        alt={row.product?.name || ''}
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
                                    {/* Name & meta */}
                                    <Box pr="6">
                                        <Text fontWeight="medium" lineHeight="snug" lineClamp={2}>
                                            {row.product?.slug ? (
                                                <Link href={`/products/${row.product.slug}`}>
                                                    {row.product?.name || 'Товар недоступен'}
                                                </Link>
                                            ) : (
                                                row.product?.name || 'Товар недоступен'
                                            )}
                                        </Text>
                                        <Text fontSize="xs" color="fg.muted" lineClamp={1}>
                                            {row.product?.brand?.name || '—'}
                                            {row.product?.sku ? ` • ${row.product.sku}` : ''}
                                        </Text>
                                        {data.isUnavailable && (
                                            <Badge colorPalette="red" variant="subtle" fontSize="2xs" mt="1">
                                                Недоступен
                                            </Badge>
                                        )}
                                    </Box>

                                    {/* Price & quantity row */}
                                    <Flex mt="2" justify="space-between" align="center" gap="3">
                                        <Box>
                                            <Text fontSize="xs" color="fg.muted">
                                                Цена ({currencySymbol})
                                            </Text>
                                            {data.priceRegular !== data.priceDiscounted && (
                                                <HStack gap="1" align="center">
                                                    <Text fontSize="xs" color="fg.muted" textDecoration="line-through">
                                                        {data.priceRegular.toLocaleString('ru-RU')}
                                                    </Text>
                                                    <Badge colorPalette="green" variant="subtle" size="xs">
                                                        -{data.discountPercent}%
                                                    </Badge>
                                                </HStack>
                                            )}
                                            <Text fontWeight="medium">
                                                {data.priceDiscounted.toLocaleString('ru-RU')}
                                            </Text>
                                        </Box>
                                        <QuantityControl
                                            value={data.totalQty}
                                            onChange={(v) => onSetProductQuantity(data.pid, v)}
                                            min={0}
                                            size="sm"
                                        />
                                    </Flex>

                                    {/* Preorder info */}
                                    {hasPreorderItems && data.preorderQty > 0 && (
                                        <Text fontSize="xs" color="orange.600" mt="1">
                                            Предзаказ: {data.preorderQty} шт
                                        </Text>
                                    )}

                                    {/* Total & delete */}
                                    <Flex mt="2" justify="space-between" align="center">
                                        <Box fontSize="sm">
                                            <Text as="span" color="fg.muted">
                                                Сумма ({currencySymbol}):{' '}
                                            </Text>
                                            {data.sumRegular !== data.sumDiscounted && (
                                                <Text as="span" color="fg.muted" textDecoration="line-through" mr="1">
                                                    {data.sumRegular.toLocaleString('ru-RU')}
                                                </Text>
                                            )}
                                            <Text as="span" fontWeight="medium">
                                                {data.sumDiscounted.toLocaleString('ru-RU')}
                                            </Text>
                                            {data.savingsTotal > 0 && (
                                                <Text fontSize="xs" color="green.600" _dark={{ color: 'green.400' }} mt="0.5">
                                                    Ваша выгода: {data.savingsTotal.toLocaleString('ru-RU')} {currencySymbol}
                                                </Text>
                                            )}
                                        </Box>
                                        <IconButton
                                            aria-label="Удалить"
                                            variant="ghost"
                                            size="xs"
                                            colorPalette="red"
                                            onClick={() => onRemove(data.pid)}
                                        >
                                            <LuTrash2 size={14} />
                                        </IconButton>
                                    </Flex>
                                </Box>
                            </Flex>
                        </Box>
                    );
                })}
            </Box>
        </Box>
    );
}
