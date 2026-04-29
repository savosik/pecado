import { useMemo, useCallback } from 'react';
import { Box, Flex, Text, Table, Stack } from '@chakra-ui/react';
import { usePage } from '@inertiajs/react';
import { LuChevronUp, LuChevronDown } from 'react-icons/lu';
import { Checkbox } from '@/components/ui/checkbox';
import { useCartStore } from '@/stores/useCartStore';
import CartTableRow from './CartTableRow';
import CartMobileCard from './CartMobileCard';

// Единый стиль для всех заголовков колонок
const HEAD_STYLE = {
    fontSize: '2xs',
    fontWeight: 'medium',
    textTransform: 'uppercase',
    letterSpacing: '0.04em',
    color: 'fg.muted',
    whiteSpace: 'nowrap',
};

const SortableHeader = ({ label, columnKey, sortKey, sortDir, onSort, ...rest }) => (
    <Box
        as="button"
        type="button"
        onClick={() => onSort(columnKey)}
        display="inline-flex"
        alignItems="center"
        gap="1"
        cursor="pointer"
        _hover={{ color: 'fg' }}
        {...HEAD_STYLE}
        {...rest}
    >
        {label}
        {sortKey === columnKey && (
            sortDir === 'asc' ? <LuChevronUp size={12} /> : <LuChevronDown size={12} />
        )}
    </Box>
);

/**
 * Из items строит per-product объект с иммутабельными данными,
 * нужными для рендера строки. qty берётся из стора в самой строке.
 *
 * Приоритет цены — instock-вариант, иначе preorder. max_total одинаков
 * для обоих item_type (берётся из stock сервиса), на всякий случай — max.
 * isUnavailable = оба item_type помечены недоступными (или одного из них нет).
 */
function buildProductRows(items) {
    const byProduct = new Map();
    for (const it of items) {
        const pid = Number(it.product?.id);
        if (!pid) continue;
        if (!byProduct.has(pid)) {
            byProduct.set(pid, {
                pid,
                product: it.product,
                priceRegular: 0,
                priceDiscounted: 0,
                maxTotal: 0,
                instockShareFromServer: 0,
                preorderShareFromServer: 0,
                _instockSeen: false,
                _instockUnavail: false,
                _preorderSeen: false,
                _preorderUnavail: false,
                _priceFromInstock: false,
            });
        }
        const r = byProduct.get(pid);
        const priceR = Number(it.price_regular ?? it.price ?? 0);
        const priceD = Number(it.price_discounted ?? it.price ?? 0);
        const mt = Number(it.max_total ?? 0);
        if (mt > r.maxTotal) r.maxTotal = mt;

        if (it.item_type === 'instock') {
            r._instockSeen = true;
            r._instockUnavail = !!it.is_unavailable;
            r.instockShareFromServer = Number(it.quantity || 0);
            r.priceRegular = priceR;
            r.priceDiscounted = priceD;
            r._priceFromInstock = true;
        } else if (it.item_type === 'preorder') {
            r._preorderSeen = true;
            r._preorderUnavail = !!it.is_unavailable;
            r.preorderShareFromServer = Number(it.quantity || 0);
            if (!r._priceFromInstock) {
                r.priceRegular = priceR;
                r.priceDiscounted = priceD;
            }
        }
    }
    return Array.from(byProduct.values()).map((r) => {
        const instockUnavail = r._instockSeen ? r._instockUnavail : true;
        const preorderUnavail = r._preorderSeen ? r._preorderUnavail : true;
        return { ...r, isUnavailable: instockUnavail && preorderUnavail };
    });
}

/**
 * Сортирует rows. Для qty/instock/preorder/sum читает qty из стора.
 */
function sortRows(rows, sortKey, sortDir, quantities) {
    const getValue = (r) => {
        const totalQty = quantities[r.pid] || 0;
        switch (sortKey) {
            case 'name': return String(r.product?.name || '');
            case 'brand': return String(r.product?.brand?.name || '');
            case 'sku': return String(r.product?.sku || '');
            case 'qty': return totalQty;
            case 'instock': return Math.min(totalQty, Math.max(0, r.maxTotal - r.preorderShareFromServer));
            case 'preorder': return Math.max(0, totalQty - Math.max(0, r.maxTotal - r.preorderShareFromServer));
            case 'price': return r.priceDiscounted || r.priceRegular || 0;
            case 'sum': return r.priceDiscounted * totalQty;
            default: return 0;
        }
    };
    const arr = [...rows];
    arr.sort((a, b) => {
        const va = getValue(a);
        const vb = getValue(b);
        if (typeof va === 'string' || typeof vb === 'string') {
            const res = String(va).localeCompare(String(vb), 'ru', { sensitivity: 'base', numeric: true });
            return sortDir === 'asc' ? res : -res;
        }
        const res = va === vb ? 0 : va < vb ? -1 : 1;
        return sortDir === 'asc' ? res : -res;
    });
    return arr;
}

/**
 * Таблица товаров корзины. Делегирует рендер строк memo-обёрнутым
 * CartTableRow / CartMobileCard. Каждая строка сама подписывается на
 * свой qty в сторе — рендерится только она при изменении.
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

    // Стабильные данные товаров из items (имена, цены, max_total)
    const productRows = useMemo(() => buildProductRows(items), [items]);

    // Подписка на quantities — нужна только для сортировки и итогов в футере.
    const quantities = useCartStore((s) => s.quantities);

    const sortedRows = useMemo(
        () => sortRows(productRows, sortKey, sortDir, quantities),
        [productRows, sortKey, sortDir, quantities],
    );

    // Видимые ID — те, у кого qty > 0 (оптимистично скрываем удалённых).
    const visibleIds = useMemo(
        () => sortedRows.filter((r) => (quantities[r.pid] || 0) > 0).map((r) => r.pid),
        [sortedRows, quantities],
    );
    const allSelected = visibleIds.length > 0 && visibleIds.every((id) => selected.has(id));

    // Локальные итоги для футера desktop-таблицы
    const totals = useMemo(() => {
        let totalInstock = 0;
        let totalPreorder = 0;
        let totalAmountRegular = 0;
        let totalAmountDiscounted = 0;
        for (const r of productRows) {
            const qty = quantities[r.pid] || 0;
            if (qty <= 0) continue;
            const stockOnly = Math.max(0, r.maxTotal - r.preorderShareFromServer);
            const inS = Math.min(qty, stockOnly);
            totalInstock += inS;
            totalPreorder += qty - inS;
            totalAmountRegular += r.priceRegular * qty;
            totalAmountDiscounted += r.priceDiscounted * qty;
        }
        return { totalInstock, totalPreorder, totalAmountRegular, totalAmountDiscounted };
    }, [productRows, quantities]);

    const handleToggleAll = useCallback((checked) => onToggleAll(checked), [onToggleAll]);

    return (
        <Box>
            {/* ═══ Desktop table ═══ */}
            <Box
                display={{ base: 'none', lg: 'block' }}
                overflowX="auto"
                overflowY="auto"
                maxH="calc(100vh - 240px)"
            >
                <Table.Root size="sm">
                    <Table.Header
                        position="sticky"
                        top="0"
                        zIndex="5"
                    >
                        <Table.Row bg="gray.100" _dark={{ bg: 'gray.800' }}>
                            <Table.ColumnHeader w="40px" textAlign="center">
                                <Checkbox
                                    checked={allSelected}
                                    onCheckedChange={(e) => handleToggleAll(!!e.checked)}
                                />
                            </Table.ColumnHeader>
                            <Table.ColumnHeader minW="200px">
                                <SortableHeader label="Название" columnKey="name" sortKey={sortKey} sortDir={sortDir} onSort={onSort} />
                            </Table.ColumnHeader>
                            <Table.ColumnHeader w="150px" textAlign="center">
                                <SortableHeader label="Заказано, шт" columnKey="qty" sortKey={sortKey} sortDir={sortDir} onSort={onSort} />
                            </Table.ColumnHeader>
                            <Table.ColumnHeader w="90px" textAlign="center">
                                <SortableHeader label="В наличии" columnKey="instock" sortKey={sortKey} sortDir={sortDir} onSort={onSort} />
                            </Table.ColumnHeader>
                            {hasPreorderItems && (
                                <Table.ColumnHeader w="90px" textAlign="center">
                                    <SortableHeader label="Предзаказ" columnKey="preorder" sortKey={sortKey} sortDir={sortDir} onSort={onSort} />
                                </Table.ColumnHeader>
                            )}
                            <Table.ColumnHeader w="100px" textAlign="right" {...HEAD_STYLE}>Цена без скидки</Table.ColumnHeader>
                            <Table.ColumnHeader w="70px" textAlign="right" {...HEAD_STYLE}>Скидка</Table.ColumnHeader>
                            <Table.ColumnHeader w="100px" textAlign="right">
                                <SortableHeader label="Цена со скидкой" columnKey="price" sortKey={sortKey} sortDir={sortDir} onSort={onSort} />
                            </Table.ColumnHeader>
                            <Table.ColumnHeader w="110px" textAlign="right">
                                <SortableHeader label={`Сумма (${currencySymbol})`} columnKey="sum" sortKey={sortKey} sortDir={sortDir} onSort={onSort} />
                            </Table.ColumnHeader>
                            <Table.ColumnHeader w="50px" />
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {sortedRows.map((r) => (
                            <CartTableRow
                                key={`p:${r.pid}`}
                                pid={r.pid}
                                product={r.product}
                                priceRegular={r.priceRegular}
                                priceDiscounted={r.priceDiscounted}
                                maxTotal={r.maxTotal}
                                isUnavailable={r.isUnavailable}
                                instockShareFromServer={r.instockShareFromServer}
                                preorderShareFromServer={r.preorderShareFromServer}
                                selected={selected.has(r.pid)}
                                hasPreorderItems={hasPreorderItems}
                                onSetQty={onSetProductQuantity}
                                onRemove={onRemove}
                                onToggle={onToggleOne}
                            />
                        ))}

                        <Table.Row fontWeight="semibold" bg="gray.50" _dark={{ bg: 'gray.800' }} fontSize="xs">
                            <Table.Cell />
                            <Table.Cell>Итого</Table.Cell>
                            <Table.Cell textAlign="center">{totals.totalInstock + totals.totalPreorder}</Table.Cell>
                            <Table.Cell textAlign="center">{totals.totalInstock}</Table.Cell>
                            {hasPreorderItems && (
                                <Table.Cell textAlign="center">{totals.totalPreorder}</Table.Cell>
                            )}
                            <Table.Cell textAlign="right" color="fg.muted" textDecoration={totals.totalAmountRegular > totals.totalAmountDiscounted ? 'line-through' : undefined}>
                                {totals.totalAmountRegular.toLocaleString('ru-RU')}
                            </Table.Cell>
                            <Table.Cell textAlign="right" color="green.600" _dark={{ color: 'green.400' }}>
                                {totals.totalAmountRegular > totals.totalAmountDiscounted
                                    ? `−${(totals.totalAmountRegular - totals.totalAmountDiscounted).toLocaleString('ru-RU')}`
                                    : '—'}
                            </Table.Cell>
                            <Table.Cell />
                            <Table.Cell textAlign="right" fontWeight="bold" fontSize="sm">
                                {totals.totalAmountDiscounted.toLocaleString('ru-RU')} {currencySymbol}
                            </Table.Cell>
                            <Table.Cell />
                        </Table.Row>
                    </Table.Body>
                </Table.Root>
            </Box>

            {/* ═══ Mobile cards ═══ */}
            <Box display={{ base: 'block', lg: 'none' }} spaceY="3">
                {sortedRows.map((r) => (
                    <CartMobileCard
                        key={`m:${r.pid}`}
                        pid={r.pid}
                        product={r.product}
                        priceRegular={r.priceRegular}
                        priceDiscounted={r.priceDiscounted}
                        maxTotal={r.maxTotal}
                        isUnavailable={r.isUnavailable}
                        preorderShareFromServer={r.preorderShareFromServer}
                        currencySymbol={currencySymbol}
                        selected={selected.has(r.pid)}
                        hasPreorderItems={hasPreorderItems}
                        onSetQty={onSetProductQuantity}
                        onRemove={onRemove}
                        onToggle={onToggleOne}
                    />
                ))}
            </Box>
        </Box>
    );
}
