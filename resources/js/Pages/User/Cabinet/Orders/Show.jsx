import { useState, useCallback } from 'react';
import {
    Box, Flex, Text, Heading, Button, Table, Badge, Separator, Stack,
    Card, HStack, VStack, SimpleGrid, Image, Collapsible,
    Dialog, Portal, CloseButton,
} from '@chakra-ui/react';
import { Head, Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    LuArrowLeft, LuPackage, LuWarehouse, LuBadgePercent,
    LuClock, LuClock3, LuUser, LuMessageSquare, LuBuilding2, LuLandmark, LuMapPin, LuTruck, LuShoppingBag,
    LuPencilLine, LuArrowRightLeft, LuChevronDown, LuChevronUp,
    LuPlus, LuMinus, LuTrendingDown, LuTrendingUp, LuCalendar, LuFileSpreadsheet,
    LuSearch, LuStore, LuRepeat, LuShoppingCart, LuTrash2, LuTriangleAlert, LuBan, LuGift, LuSprout, LuFileText,
} from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Tooltip } from '@/components/ui/tooltip';
import { useCartStore } from '@/stores/useCartStore';
import { toastSuccess, toastError, toastInfo } from '@/utils/toast';
import {
    ORDER_STATUS_LABELS as STATUS_LABELS,
    ORDER_STATUS_COLORS as STATUS_COLORS,
} from '@/constants/orderStatus';
import { getOrderTypeLabel, getOrderTypeColor } from '@/constants/orderType';
import { buildOrderTimeline } from '@/utils/orderTimeline';

const SHIPMENT_STATUS_COLORS = {
    new: 'blue',
    in_progress: 'orange',
    completed: 'green',
    cancelled: 'red',
};

export default function OrderShow({ order }) {
    const { currency, config, preorder: preorderTerms } = usePage().props;
    const documentsEnabled = !!config?.documents_enabled;
    const leadLabel = preorderTerms?.lead_label ?? '';
    const currencySymbol = currency?.symbol ?? '₽';
    const fmt = (v) => Number(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const isPreorder = order.type === 'preorder';
    const isDefect   = order.type === 'defect';
    const typeLabel  = getOrderTypeLabel(order.type);
    const typeIcon   = {
        defect: <LuBadgePercent size={20} />,
        preorder: <LuPackage size={20} />,
        promo: <LuGift size={20} />,
        promo_sample: <LuSprout size={20} />,
    }[order.type] ?? <LuWarehouse size={20} />;
    const typeColor  = { defect: 'red', preorder: 'orange', promo: 'blue', promo_sample: 'gray' }[order.type] ?? 'green';
    const typeBadgeScheme = getOrderTypeColor(order.type);

    const createdAt = order.created_at_formatted || '—';

    // Объединённый timeline
    const timelineEntries = buildOrderTimeline(order.status_histories, order.change_logs);

    // ─── Повторить заказ ───
    const cartTotal = useCartStore((s) => s.cartTotals.total);
    // Кол-во товаров заказа с привязкой к каталогу (только их можно повторить).
    // Считаем уникальные товары, а не строки: при недоборе строка дробится
    // на активную и отменённую, и повтор всё равно сложит их количество.
    const repeatableCount = new Set(
        (order.items || [])
            .filter((it) => it.product?.id && Number(it.quantity) > 0)
            .map((it) => it.product.id),
    ).size;
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [repeating, setRepeating] = useState(false);

    const doRepeat = useCallback(async (mode) => {
        setRepeating(true);
        try {
            const { data } = await axios.post(`/cabinet/orders/${order.id}/repeat`, { mode });

            // Синхронизируем стор корзины (бейджи в шапке, количества).
            await useCartStore.getState()._serverSync();

            const added = Number(data?.added_count ?? 0);
            const skipped = Number(data?.skipped_count ?? 0);

            if (added > 0) {
                toastSuccess('Заказ повторён', data.message || `Позиции добавлены в корзину: ${added}.`);
            } else {
                toastInfo('Нечего добавить', data?.message || 'В заказе нет позиций, доступных для повтора.');
            }
            if (skipped > 0) {
                toastInfo('Часть позиций пропущена', `Товаров вне каталога: ${skipped}.`);
            }
        } catch (err) {
            toastError('Ошибка', err?.response?.data?.message || 'Не удалось повторить заказ.');
        } finally {
            setRepeating(false);
            setConfirmOpen(false);
        }
    }, [order.id]);

    const handleRepeatClick = useCallback(() => {
        if (cartTotal > 0) {
            setConfirmOpen(true);
        } else {
            doRepeat('merge');
        }
    }, [cartTotal, doRepeat]);

    return (
        <CabinetLayout
            title={`Заказ ${order.number}`}
            actions={
                <HStack gap="2">
                    {repeatableCount > 0 && (
                        <Button
                            colorPalette="pecado"
                            size="sm"
                            onClick={handleRepeatClick}
                            loading={repeating}
                        >
                            <LuRepeat size={16} />
                            Повторить заказ
                        </Button>
                    )}
                    {/* Печатные формы по этому заказу: счёт на оплату, договор.
                        Ссылка ведёт в раздел с уже наложенным отбором — карточки
                        документа у нас нет, показывать нечего кроме файла. */}
                    {documentsEnabled && (
                        <Button asChild variant="outline" size="sm">
                            <Link href={`/cabinet/documents?order_id=${order.id}`}>
                                <LuFileText size={16} />
                                Документы
                            </Link>
                        </Button>
                    )}
                    <Button asChild variant="outline" size="sm">
                        <Link href="/cabinet/orders">
                            <LuArrowLeft size={16} />
                            К списку
                        </Link>
                    </Button>
                </HStack>
            }
        >
            <Head title={`Заказ ${order.number} — Pecado`} />

            <RepeatOrderDialog
                open={confirmOpen}
                onClose={() => !repeating && setConfirmOpen(false)}
                busy={repeating}
                onMerge={() => doRepeat('merge')}
                onReplace={() => doRepeat('replace')}
            />

            <Stack gap="5">
                {/* ═══ Тип заказа + статус ═══ */}
                <Flex align="center" gap="3" flexWrap="wrap">
                    <Badge
                        colorPalette={typeBadgeScheme}
                        variant="subtle"
                        fontSize="sm"
                        px="3"
                        py="1"
                        borderRadius="full"
                    >
                        {typeLabel}
                    </Badge>
                    <Badge
                        colorPalette={STATUS_COLORS[order.status] ?? 'gray'}
                        variant="subtle"
                        fontSize="sm"
                        px="3"
                        py="1"
                        borderRadius="full"
                    >
                        {STATUS_LABELS[order.status] ?? order.status}
                    </Badge>
                    <Badge
                        colorPalette="gray"
                        variant="outline"
                        fontSize="sm"
                        px="3"
                        py="1"
                        borderRadius="full"
                        gap="1"
                    >
                        {order.delivery_method === 'pickup' ? <LuStore size={14} /> : <LuMapPin size={14} />}
                        {order.delivery_method_label ?? (order.delivery_method === 'pickup' ? 'Самовывоз' : 'Доставка')}
                    </Badge>
                    <Text fontSize="sm" color="fg.muted">
                        Заказ {order.number} от {createdAt.split(' ')[0]}
                    </Text>
                </Flex>

                {/* Предзаказ: клиент должен видеть, что это ожидание поставки, а не задержка отгрузки */}
                {isPreorder && (
                    <Flex
                        align="flex-start"
                        gap="2"
                        bg="orange.subtle"
                        borderWidth="1px"
                        borderColor="orange.muted"
                        rounded="lg"
                        px="4"
                        py="3"
                        color="orange.fg"
                    >
                        <Box pt="0.5" flexShrink={0}><LuClock3 size={16} /></Box>
                        <Text fontSize="sm">
                            Товары этого заказа заказаны у поставщика — на складе их не было.
                            {leadLabel ? ` Ориентировочная поставка — ${leadLabel} с даты заказа.` : ''}
                            {' '}Отгрузим отдельно, как только товар поступит.
                        </Text>
                    </Flex>
                )}

                {/* ═══ Информация о заказе ═══ */}
                <SimpleGrid columns={{ base: 1, lg: 2 }} gap="4">
                    <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted">
                        <Card.Header p="4" pb="2">
                            <Text fontWeight="700" fontSize="md">Информация о заказе</Text>
                        </Card.Header>
                        <Card.Body p="4" pt="0">
                            <VStack align="stretch" gap="3">
                                <InfoRow label="Дата" value={createdAt} />
                                <InfoRow
                                    label="Сумма"
                                    value={
                                        <>
                                            {fmt(order.total_converted)} {currencySymbol}
                                            {order.currency_code && order.currency_code !== currency?.code && (
                                                <Text as="span" fontSize="xs" color="gray.400" ml="1">
                                                    ({fmt(order.total_amount)} {order.currency_code})
                                                </Text>
                                            )}
                                        </>
                                    }
                                    bold
                                />
                                {order.comment && <InfoRow label="Комментарий" value={order.comment} />}
                                {order.manager_comment && <InfoRow label="Комментарий для менеджера" value={order.manager_comment} />}
                                {order.warehouse_comment && <InfoRow label="Комментарий для склада" value={order.warehouse_comment} />}
                            </VStack>
                        </Card.Body>
                    </Card.Root>

                    <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted">
                        <Card.Header p="4" pb="2">
                            <Text fontWeight="700" fontSize="md">Реквизиты</Text>
                        </Card.Header>
                        <Card.Body p="4" pt="0">
                            <VStack align="stretch" gap="3">
                                {order.company && (
                                    <HStack gap="2" align="start">
                                        <LuBuilding2 size={16} style={{ marginTop: 2, flexShrink: 0, color: 'var(--chakra-colors-gray-400)' }} />
                                        <Box>
                                            <Text fontSize="sm" fontWeight="600">{order.company.name}</Text>
                                            {order.company.legal_name && (
                                                <Text fontSize="xs" color="fg.muted">{order.company.legal_name}</Text>
                                            )}
                                            {order.company.tax_id && (
                                                <Text fontSize="xs" color="fg.muted">ИНН: {order.company.tax_id}</Text>
                                            )}
                                        </Box>
                                    </HStack>
                                )}
                                {/*
                                    Продавец — наше юрлицо, на которое проведён заказ.
                                    Приходит из 1С; пока не пришло, блока просто нет.
                                */}
                                {order.seller && (
                                    <HStack gap="2" align="start">
                                        <LuLandmark size={16} style={{ marginTop: 2, flexShrink: 0, color: 'var(--chakra-colors-gray-400)' }} />
                                        <Box>
                                            <Text fontSize="xs" color="fg.muted">Продавец</Text>
                                            <Text fontSize="sm" fontWeight="600">{order.seller.name}</Text>
                                            {order.seller.legal_name && (
                                                <Text fontSize="xs" color="fg.muted">{order.seller.legal_name}</Text>
                                            )}
                                            {order.seller.tax_id && (
                                                <Text fontSize="xs" color="fg.muted">ИНН: {order.seller.tax_id}</Text>
                                            )}
                                        </Box>
                                    </HStack>
                                )}
                                {order.delivery_method === 'pickup' ? (
                                    <HStack gap="2" align="start">
                                        <LuStore size={16} style={{ marginTop: 2, flexShrink: 0, color: 'var(--chakra-colors-gray-400)' }} />
                                        <Box>
                                            <Text fontSize="sm" fontWeight="600">Самовывоз</Text>
                                            <Text fontSize="xs" color="fg.muted">Заказ забирается со склада самостоятельно</Text>
                                        </Box>
                                    </HStack>
                                ) : order.delivery_address && (
                                    <HStack gap="2" align="start">
                                        <LuMapPin size={16} style={{ marginTop: 2, flexShrink: 0, color: 'var(--chakra-colors-gray-400)' }} />
                                        <Box>
                                            <Text fontSize="sm" color="fg.muted">{order.delivery_address}</Text>
                                        </Box>
                                    </HStack>
                                )}
                                {!order.company && !order.delivery_address && order.delivery_method !== 'pickup' && (
                                    <Text fontSize="sm" color="fg.muted">Нет данных</Text>
                                )}
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                </SimpleGrid>

                {/* ═══ Позиции заказа ═══ */}
                {order.items?.length > 0 && (() => {
                    // Отменённые в 1С строки (недобор) не участвуют ни в сумме
                    // заказа, ни в счётчиках: заказ на них не выставлен
                    const activeItems = (order.items || []).filter((it) => !it.cancelled);
                    const cancelledCount = (order.items || []).length - activeItems.length;

                    const totalSavings = activeItems.reduce((acc, item) => {
                        const bp = parseFloat(item.base_price || 0);
                        const fp = parseFloat(item.final_price || item.price || 0);
                        if (bp > fp) acc += (bp - fp) * item.quantity;
                        return acc;
                    }, 0);

                    return (
                    <Box>
                        <Flex align="center" gap="2" flexWrap="wrap" mb="3">
                            {typeIcon}
                            <Text fontWeight="700" fontSize="md">Позиции ({activeItems.length})</Text>
                            <Badge colorPalette={typeColor} variant="subtle" ml="1">
                                {activeItems.reduce((s, it) => s + Number(it.quantity || 0), 0)} шт.
                            </Badge>
                            {cancelledCount > 0 && (
                                <Tooltip
                                    content="Товара не хватило на складе — эти позиции отменены в 1С и в сумму заказа не входят"
                                    positioning={{ placement: 'top' }}
                                    openDelay={250}
                                >
                                    <Badge colorPalette="gray" variant="subtle">
                                        {cancelledCount}&nbsp;отменено
                                    </Badge>
                                </Tooltip>
                            )}
                            <Box ml="auto">
                                <Tooltip content="Скачать в Excel (XLSX)" positioning={{ placement: 'top' }} openDelay={250}>
                                    <Flex
                                        as="a"
                                        href={`/cabinet/orders/${order.id}/items/export`}
                                        align="center"
                                        gap="1.5"
                                        h="8"
                                        px="3"
                                        borderRadius="md"
                                        fontSize="sm"
                                        fontWeight="500"
                                        color="green.600"
                                        _dark={{ color: 'green.400' }}
                                        _hover={{ bg: 'green.50', _dark: { bg: 'green.900/30' } }}
                                        transition="background 0.15s"
                                        aria-label="Скачать состав заказа в Excel"
                                    >
                                        <LuFileSpreadsheet size={16} />
                                        <Text>Скачать</Text>
                                    </Flex>
                                </Tooltip>
                            </Box>
                        </Flex>
                        <Box
                            overflowX="auto"
                            bg="bg"
                            borderRadius="xl"
                            border="1px solid"
                            borderColor="border.muted"

                        >
                            <Table.Root bg="bg" size="sm">
                                <Table.Header>
                                    <Table.Row bg="bg" _dark={{ bg: 'gray.800' }}>
                                        <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                        <Table.ColumnHeader w="80px" textAlign="center">Кол-во</Table.ColumnHeader>
                                        <Table.ColumnHeader w="130px" textAlign="right">Цена без скидки</Table.ColumnHeader>
                                        <Table.ColumnHeader w="80px" textAlign="right">Скидка</Table.ColumnHeader>
                                        <Table.ColumnHeader w="130px" textAlign="right">Цена со скидкой</Table.ColumnHeader>
                                        <Table.ColumnHeader w="130px" textAlign="right">Сумма</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {order.items.map((item) => {
                                        const finalPrice = parseFloat(item.final_price || item.price || 0);
                                        const rawBasePrice = parseFloat(item.base_price || 0);
                                        const rawDiscountPct = parseFloat(item.discount_percent || 0);
                                        const hasDiscount = rawBasePrice > 0 && finalPrice > 0 && rawBasePrice > finalPrice;
                                        const basePrice = hasDiscount ? rawBasePrice : finalPrice;
                                        const discountPct = hasDiscount ? rawDiscountPct : 0;
                                        return (
                                            <Table.Row
                                                key={item.id}
                                                bg="bg"
                                                opacity={item.cancelled ? 0.55 : 1}
                                            >
                                                <Table.Cell>
                                                    <HStack gap="3">
                                                        {item.product?.image_url && (
                                                            <Image
                                                                src={item.product.image_url}
                                                                alt={item.name}
                                                                w="10"
                                                                h="10"
                                                                objectFit="contain"
                                                                borderRadius="md"
                                                                flexShrink="0"
                                                                bg="gray.50"
                                                            />
                                                        )}
                                                        <Box>
                                                            {item.product?.slug ? (
                                                                <Link href={`/products/${item.product.slug}`}>
                                                                    <Text fontWeight="500" fontSize="sm" _hover={{ color: 'pecado.500' }} transition="color 0.15s">
                                                                        {item.product?.name || item.name}
                                                                    </Text>
                                                                </Link>
                                                            ) : (
                                                                <Tooltip
                                                                    content="Товар не привязан к каталогу. Открыть поиск по названию"
                                                                    positioning={{ placement: 'top' }}
                                                                    openDelay={300}
                                                                >
                                                                    <Link href={`/search?q=${encodeURIComponent(item.name || '')}`}>
                                                                        <HStack gap="1" align="center">
                                                                            <Text fontWeight="500" fontSize="sm" _hover={{ color: 'pecado.500' }} transition="color 0.15s">
                                                                                {item.name}
                                                                            </Text>
                                                                            <Box color="fg.muted">
                                                                                <LuSearch size={12} />
                                                                            </Box>
                                                                        </HStack>
                                                                    </Link>
                                                                </Tooltip>
                                                            )}
                                                            <Flex gap="1" mt="0.5" align="center" flexWrap="wrap">
                                                                {item.product?.brand?.name && (
                                                                    <Text fontSize="xs" color="fg.muted">{item.product.brand.name}</Text>
                                                                )}
                                                                {item.product?.sku && (
                                                                    <Text fontSize="xs" color="fg.muted">• {item.product.sku}</Text>
                                                                )}
                                                                {item.cancelled && (
                                                                    <Badge colorPalette="gray" variant="subtle" size="xs">
                                                                        Отменена — нет в наличии
                                                                    </Badge>
                                                                )}
                                                            </Flex>
                                                        </Box>
                                                    </HStack>
                                                </Table.Cell>
                                                <Table.Cell textAlign="center">{item.quantity}</Table.Cell>
                                                <Table.Cell textAlign="right">{fmt(basePrice)}</Table.Cell>
                                                <Table.Cell textAlign="right">{fmt(discountPct)}%</Table.Cell>
                                                <Table.Cell textAlign="right">{fmt(finalPrice)}</Table.Cell>
                                                <Table.Cell
                                                    textAlign="right"
                                                    fontWeight="600"
                                                    textDecoration={item.cancelled ? 'line-through' : undefined}
                                                    color={item.cancelled ? 'fg.muted' : undefined}
                                                >
                                                    {fmt(item.subtotal)}
                                                </Table.Cell>
                                            </Table.Row>
                                        );
                                    })}
                                </Table.Body>
                                <Table.Footer>
                                    <Table.Row bg="bg.subtle">
                                        <Table.Cell colSpan={6} p="4">
                                            <Flex justify="space-between" align="center" gap="3" flexWrap="wrap">
                                                <Flex align="center" gap="2">
                                                    <LuShoppingBag size={20} />
                                                    <Text fontWeight="700" fontSize="lg">Итого</Text>
                                                </Flex>
                                                <VStack gap="0" align="end">
                                                    <Text fontSize="xl" fontWeight="800" whiteSpace="nowrap">
                                                        {fmt(order.total_converted)}&nbsp;{currencySymbol}
                                                    </Text>
                                                    {order.currency_code && order.currency_code !== currency?.code && (
                                                        <Text fontSize="xs" color="gray.400" whiteSpace="nowrap">
                                                            {fmt(order.total_amount)}&nbsp;{order.currency_code}
                                                        </Text>
                                                    )}
                                                    {totalSavings > 0 && (
                                                        <Badge colorPalette="green" variant="subtle" size="sm" mt="1">
                                                            Сумма скидки: {fmt(totalSavings)}&nbsp;{currencySymbol}
                                                        </Badge>
                                                    )}
                                                    {/* v15.16.0: предоплата из расшифровки платежей 1С.
                                                        Показываем и остаток к оплате — иначе клиент не поймёт,
                                                        сколько ещё должен */}
                                                    {Number(order.prepaid_amount) > 0 && (
                                                        <>
                                                            <Badge colorPalette="blue" variant="subtle" size="sm" mt="1">
                                                                Предоплата: {fmt(order.prepaid_converted)}&nbsp;{currencySymbol}
                                                            </Badge>
                                                            {Number(order.total_converted) - Number(order.prepaid_converted) > 0.01 && (
                                                                <Text fontSize="xs" color="fg.muted" mt="1">
                                                                    Остаток: {fmt(Number(order.total_converted) - Number(order.prepaid_converted))}&nbsp;{currencySymbol}
                                                                </Text>
                                                            )}
                                                        </>
                                                    )}
                                                </VStack>
                                            </Flex>
                                        </Table.Cell>
                                    </Table.Row>
                                </Table.Footer>
                            </Table.Root>
                        </Box>
                    </Box>
                    );
                })()}

                {/* ═══ Единый timeline: статусы + изменения ═══ */}
                {timelineEntries.length > 0 && (
                    <OrderTimeline entries={timelineEntries} />
                )}

                {/* ═══ Отгрузки по заказу ═══ */}
                {order.shipments && order.shipments.length > 0 && (
                    <Box>
                        <HStack gap="2" mb="3">
                            <LuTruck size={20} />
                            <Text fontWeight="700" fontSize="md">
                                Отгрузки по заказу ({order.shipments.length})
                            </Text>
                        </HStack>
                        <VStack gap="2" align="stretch">
                            {order.shipments.map((shipment) => {
                                const itemsLabel = shipment.items_count === 1
                                    ? 'позиция'
                                    : shipment.items_count < 5 ? 'позиции' : 'позиций';
                                const totalConverted = shipment.total_converted ?? shipment.total_amount;
                                const isForeignCurrency = shipment.currency_code && shipment.currency_code !== currency?.code;
                                const formatDate = (iso) => iso ? new Date(iso).toLocaleDateString('ru-RU') : null;

                                return (
                                    <Link key={shipment.id} href={`/cabinet/shipments/${shipment.id}`}>
                                        <Box
                                            bg="bg"
                                            borderRadius="xl"
                                            border="1px solid"
                                            borderColor="border.muted"
                                            p="4"
                                            _hover={{ borderColor: 'pecado.200', shadow: 'sm', _dark: { borderColor: 'pecado.700' } }}
                                            transition="all 0.15s"
                                            cursor="pointer"
                                        >
                                            <Flex gap="4" align="start" justify="space-between">
                                                <Box flex="1" minW="0">
                                                    {/* Строка 1: номер + бейджи + updated_at */}
                                                    <Flex gap="2" align="center" flexWrap="wrap" mb="1.5">
                                                        <Text
                                                            fontWeight="700"
                                                            fontSize="md"
                                                            fontFamily="mono"
                                                            whiteSpace="nowrap"
                                                            flexShrink="0"
                                                            color="gray.800"
                                                            _dark={{ color: 'gray.100' }}
                                                        >
                                                            {shipment.number}
                                                        </Text>
                                                        <Badge
                                                            colorPalette="cyan"
                                                            variant="subtle" fontSize="2xs" px="2" borderRadius="full"
                                                        >
                                                            Отгрузка
                                                        </Badge>
                                                        {/* TODO: пока у отгрузок единственный статус «Выполнена» — бейдж временно скрыт
                                                        <Badge
                                                            colorPalette={SHIPMENT_STATUS_COLORS[shipment.status] || 'gray'}
                                                            variant="subtle" fontSize="2xs" px="2" borderRadius="full"
                                                        >
                                                            {shipment.status_label}
                                                        </Badge>
                                                        */}
                                                        {shipment.updated_at && (
                                                            <Text fontSize="2xs" color="gray.400" whiteSpace="nowrap">
                                                                {shipment.updated_at}
                                                            </Text>
                                                        )}
                                                    </Flex>

                                                    {/* Строка 2: позиции */}
                                                    <HStack gap="3" fontSize="xs" color="gray.500" flexWrap="wrap" mb={shipment.date ? '1.5' : '0'}>
                                                        <Text>
                                                            {shipment.items_count}&nbsp;{itemsLabel}
                                                        </Text>
                                                    </HStack>

                                                    {/* Строка 3: дата отгрузки */}
                                                    {shipment.date && (
                                                        <HStack gap="1" fontSize="xs" color="gray.500" minW="0">
                                                            <Box flexShrink="0" color="gray.400"><LuCalendar size={11} /></Box>
                                                            <Text noOfLines={1}>Дата отгрузки: {formatDate(shipment.date)}</Text>
                                                        </HStack>
                                                    )}
                                                </Box>

                                                {/* Правая часть: сумма */}
                                                <VStack gap="0" align="end" flexShrink="0">
                                                    <Text fontWeight="700" fontSize="lg" fontFamily="mono" whiteSpace="nowrap">
                                                        {fmt(totalConverted)}&nbsp;{currencySymbol}
                                                    </Text>
                                                    {isForeignCurrency && (
                                                        <Text fontSize="xs" color="gray.400" whiteSpace="nowrap">
                                                            {fmt(shipment.total_amount)}&nbsp;{shipment.currency_code}
                                                        </Text>
                                                    )}
                                                </VStack>
                                            </Flex>
                                        </Box>
                                    </Link>
                                );
                            })}
                        </VStack>
                    </Box>
                )}
            </Stack>
        </CabinetLayout>
    );
}

/* ═══════════════════════════════════════════════════════════════════════════
   Вспомогательные компоненты
   ═══════════════════════════════════════════════════════════════════════════ */

function InfoRow({ label, value, bold }) {
    return (
        <Flex gap="2" direction={{ base: 'column', sm: 'row' }}>
            <Text fontWeight="600" minW="130px" color="fg.muted" fontSize="sm">
                {label}:
            </Text>
            <Text fontSize="sm" fontWeight={bold ? '700' : '400'}>{value}</Text>
        </Flex>
    );
}

/**
 * Диалог выбора действия при повторе заказа, когда корзина не пуста.
 */
function RepeatOrderDialog({ open, onClose, busy, onMerge, onReplace }) {
    return (
        <Dialog.Root
            open={open}
            onOpenChange={({ open: isOpen }) => !isOpen && onClose?.()}
            size="sm"
        >
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>
                                <HStack gap="2">
                                    <LuRepeat size={18} />
                                    <Text>Повторить заказ</Text>
                                </HStack>
                            </Dialog.Title>
                            <Dialog.CloseTrigger asChild>
                                <CloseButton size="sm" onClick={onClose} disabled={busy} />
                            </Dialog.CloseTrigger>
                        </Dialog.Header>

                        <Dialog.Body>
                            <Text fontSize="sm" color="fg.muted">
                                В корзине уже есть товары. Добавить позиции заказа к текущей корзине
                                или сначала очистить её?
                            </Text>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <Stack gap="2" w="full">
                                <Button
                                    colorPalette="pecado"
                                    onClick={onMerge}
                                    loading={busy}
                                    w="full"
                                >
                                    <LuShoppingCart size={16} />
                                    Добавить к текущей корзине
                                </Button>
                                <Button
                                    variant="outline"
                                    colorPalette="red"
                                    onClick={onReplace}
                                    disabled={busy}
                                    w="full"
                                >
                                    <LuTrash2 size={16} />
                                    Очистить и добавить
                                </Button>
                            </Stack>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}

/**
 * Единый timeline — история статусов и изменений заказа.
 */
function OrderTimeline({ entries = [] }) {
    return (
        <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted">
            <Card.Header p="4" pb="2">
                <HStack gap="2">
                    <LuClock size={18} />
                    <Text fontWeight="700" fontSize="md">История заказа</Text>
                    <Badge variant="subtle" colorPalette="gray" fontSize="2xs">{entries.length}</Badge>
                </HStack>
            </Card.Header>
            <Card.Body p="4" pt="2">
                <Box position="relative">
                    {/* Вертикальная линия */}
                    <Box
                        position="absolute"
                        left="18px"
                        top="20px"
                        bottom="20px"
                        width="2px"
                        bg="gray.200"
                        _dark={{ bg: 'gray.600' }}
                    />

                    <Stack gap={5}>
                        {entries.map((entry, index) => (
                            <Box key={entry.id} position="relative" pl="50px">
                                {/* Индикатор */}
                                <Box
                                    position="absolute"
                                    left="10px"
                                    top="2px"
                                    width="18px"
                                    height="18px"
                                    borderRadius="full"
                                    bg={index === 0
                                        ? (entry.type === 'items_updated'
                                            ? 'orange.500'
                                            : (entry.type === 'attributes_updated' || entry.type === 'api_shortfall')
                                                ? 'purple.500'
                                                : 'pecado.500')
                                        : 'gray.300'
                                    }
                                    border="3px solid"
                                    borderColor="white"

                                    zIndex={1}
                                />

                                {entry.type === 'status_changed' && <StatusEntry entry={entry} />}
                                {entry.type === 'items_updated' && <ItemsChangedEntry entry={entry} />}
                                {entry.type === 'attributes_updated' && <AttributesChangedEntry entry={entry} />}
                                {entry.type === 'api_shortfall' && <ApiShortfallEntry entry={entry} />}
                            </Box>
                        ))}
                    </Stack>
                </Box>
            </Card.Body>
        </Card.Root>
    );
}

/**
 * Запись о смене статуса.
 */
function StatusEntry({ entry }) {
    const h = entry.data;
    return (
        <Stack gap={1}>
            <HStack gap="1.5">
                <LuArrowRightLeft size={14} style={{ color: 'var(--chakra-colors-blue-500)', flexShrink: 0 }} />
                <Text fontWeight="500" fontSize="sm">
                    {h.old_status ? (
                        <>
                            <Box as="span" color="orange.600">{h.old_status_label}</Box>
                            {' → '}
                            <Box as="span" color="green.600">{h.new_status_label}</Box>
                        </>
                    ) : (
                        <>
                            Создан со статусом{' '}
                            <Box as="span" color="blue.600" fontWeight="600">{h.new_status_label}</Box>
                        </>
                    )}
                </Text>
            </HStack>

            <HStack fontSize="xs" color="fg.muted" gap="1">
                <LuUser size={12} />
                <Text>{h.user_name}</Text>
                <Text>•</Text>
                <Text>{h.created_at_human}</Text>
            </HStack>

            {h.comment && (
                <Box
                    fontSize="sm"
                    bg="gray.50"
                    _dark={{ bg: 'gray.700' }}
                    p={3}
                    borderRadius="md"
                    borderLeftWidth="3px"
                    borderLeftColor="pecado.400"
                    mt={1}
                >
                    <HStack align="start" gap={2}>
                        <LuMessageSquare size={14} style={{ marginTop: '2px', flexShrink: 0 }} />
                        <Text>{h.comment}</Text>
                    </HStack>
                </Box>
            )}
        </Stack>
    );
}

/**
 * Запись об изменении позиций — с expandable деталями.
 */
function ItemsChangedEntry({ entry }) {
    const [expanded, setExpanded] = useState(false);
    const c = entry.data;
    const changes = c.changes || {};
    const fmt = (v) => Number(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const hasDetails = (changes.added?.length > 0) || (changes.removed?.length > 0) || (changes.modified?.length > 0);

    return (
        <Stack gap={1}>
            <HStack gap="1.5">
                <LuPencilLine size={14} style={{ color: 'var(--chakra-colors-orange-500)', flexShrink: 0 }} />
                <Text fontWeight="600" fontSize="sm" color="orange.700" _dark={{ color: 'orange.300' }}>
                    Состав заказа изменён
                </Text>
                <SourceBadge source={c.source} userName={c.user_name} />
            </HStack>

            {/* Сумма до/после */}
            {c.old_total != null && c.new_total != null && Math.abs(c.old_total - c.new_total) > 0.01 && (
                <HStack fontSize="sm" gap="1.5">
                    {c.new_total < c.old_total
                        ? <LuTrendingDown size={14} style={{ color: 'var(--chakra-colors-red-500)' }} />
                        : <LuTrendingUp size={14} style={{ color: 'var(--chakra-colors-green-500)' }} />
                    }
                    <Text color="fg.muted">Сумма:</Text>
                    <Text fontWeight="600" textDecoration="line-through" color="fg.muted">{fmt(c.old_total)} ₽</Text>
                    <Text>→</Text>
                    <Text fontWeight="700" color={c.new_total < c.old_total ? 'red.600' : 'green.600'}>
                        {fmt(c.new_total)} ₽
                    </Text>
                </HStack>
            )}

            <HStack fontSize="xs" color="fg.muted" gap="1">
                <LuClock size={12} />
                <Text>{c.created_at}</Text>
                <Text>•</Text>
                <Text>{c.created_at_human}</Text>
            </HStack>

            {/* Expandable details */}
            {hasDetails && (
                <Box mt={1}>
                    <Button
                        variant="ghost"
                        size="xs"
                        onClick={() => setExpanded(!expanded)}
                        color="pecado.600"
                        _hover={{ bg: 'pecado.50', _dark: { bg: 'gray.700' } }}
                    >
                        {expanded ? <LuChevronUp size={14} /> : <LuChevronDown size={14} />}
                        {expanded ? 'Скрыть подробности' : 'Подробности'}
                    </Button>

                    {expanded && (
                        <Box
                            mt={2}
                            bg="gray.50"
                            _dark={{ bg: 'gray.700' }}
                            borderRadius="lg"
                            p={3}
                            fontSize="sm"
                        >
                            <Stack gap={2}>
                                {changes.added?.map((item, i) => (
                                    <HStack key={`add-${i}`} gap="2" align="start">
                                        <Box color="green.500" mt="1"><LuPlus size={14} /></Box>
                                        <Text>
                                            <Box as="span" fontWeight="600">«{item.product_name}»</Box>
                                            {' — '}кол-во: {item.quantity}, цена: {fmt(item.price)} ₽
                                        </Text>
                                    </HStack>
                                ))}

                                {changes.removed?.map((item, i) => (
                                    <HStack key={`rem-${i}`} gap="2" align="start">
                                        <Box color="red.500" mt="1"><LuMinus size={14} /></Box>
                                        <Text>
                                            <Box as="span" fontWeight="600" textDecoration="line-through">«{item.product_name}»</Box>
                                            {' — '}удалён из заказа
                                        </Text>
                                    </HStack>
                                ))}

                                {changes.modified?.map((item, i) => (
                                    <HStack key={`mod-${i}`} gap="2" align="start">
                                        <Box color="orange.500" mt="1"><LuPencilLine size={14} /></Box>
                                        <Box>
                                            <Text fontWeight="600">«{item.product_name}»</Text>
                                            <Stack gap={0.5} ml="2" mt="0.5">
                                                {item.changes?.quantity && (
                                                    <Text fontSize="xs" color="fg.muted">
                                                        Кол-во: {item.changes.quantity.old} → {item.changes.quantity.new}
                                                    </Text>
                                                )}
                                                {item.changes?.discount_percent && (
                                                    <Text fontSize="xs" color="fg.muted">
                                                        Корректировка цены: {item.changes.discount_percent.old}% → {item.changes.discount_percent.new}%
                                                    </Text>
                                                )}
                                                {item.changes?.final_price && (
                                                    <Text fontSize="xs" color="fg.muted">
                                                        Цена: {fmt(item.changes.final_price.old)} → {fmt(item.changes.final_price.new)} ₽
                                                    </Text>
                                                )}
                                                {item.changes?.base_price && (
                                                    <Text fontSize="xs" color="fg.muted">
                                                        Базовая цена: {fmt(item.changes.base_price.old)} → {fmt(item.changes.base_price.new)} ₽
                                                    </Text>
                                                )}
                                            </Stack>
                                        </Box>
                                    </HStack>
                                ))}
                            </Stack>
                        </Box>
                    )}
                </Box>
            )}
        </Stack>
    );
}

/**
 * Запись о недостаче при приёме заказа по API — позиции, которые клиент
 * запросил, но которые не были приняты полностью из-за отсутствия остатков.
 */
function ApiShortfallEntry({ entry }) {
    const c = entry.data;
    const changes = c.changes || {};
    const notAccepted = changes.not_accepted || [];
    const partial = changes.partial || [];

    return (
        <Stack gap={1}>
            <HStack gap="1.5">
                <LuTriangleAlert size={14} style={{ color: 'var(--chakra-colors-purple-500)', flexShrink: 0 }} />
                <Text fontWeight="600" fontSize="sm" color="purple.700" _dark={{ color: 'purple.300' }}>
                    Заказ по API принят не в полном объёме
                </Text>
                <SourceBadge source={c.source} userName={c.user_name} />
            </HStack>

            <HStack fontSize="xs" color="fg.muted" gap="1">
                <LuClock size={12} />
                <Text>{c.created_at}</Text>
                <Text>•</Text>
                <Text>{c.created_at_human}</Text>
            </HStack>

            <Box mt={1} bg="gray.50" _dark={{ bg: 'gray.700' }} borderRadius="lg" p={3} fontSize="sm">
                <Stack gap={2}>
                    {notAccepted.map((item, i) => (
                        <HStack key={`na-${i}`} gap="2" align="start">
                            <Box color="purple.500" mt="1"><LuBan size={14} /></Box>
                            <Text>
                                <Box as="span" fontWeight="600" textDecoration="line-through">«{item.product_name}»</Box>
                                {' — '}не принят (запрошено {item.requested})
                            </Text>
                        </HStack>
                    ))}
                    {partial.map((item, i) => (
                        <HStack key={`pa-${i}`} gap="2" align="start">
                            <Box color="purple.500" mt="1"><LuArrowRightLeft size={14} /></Box>
                            <Text>
                                <Box as="span" fontWeight="600">«{item.product_name}»</Box>
                                {' — '}принят частично: запрошено {item.requested}, принято {item.fulfilled}
                            </Text>
                        </HStack>
                    ))}
                </Stack>
            </Box>
        </Stack>
    );
}

const SOURCE_LABELS_MAP = { erp: '1С', admin: 'Админ', system: 'Система', api: 'API' };
const SOURCE_COLORS_MAP = { erp: 'blue', admin: 'purple', system: 'gray', api: 'purple' };

function SourceBadge({ source, userName }) {
    if (!source) return null;
    const label = SOURCE_LABELS_MAP[source] ?? source;
    const color = SOURCE_COLORS_MAP[source] ?? 'gray';
    return (
        <HStack gap="1" fontSize="xs" color="fg.muted">
            <Badge variant="subtle" colorPalette={color} fontSize="2xs">{label}</Badge>
            {userName && (
                <HStack gap="0.5">
                    <LuUser size={12} />
                    <Text>{userName}</Text>
                </HStack>
            )}
        </HStack>
    );
}

/**
 * Запись об изменении атрибутов заказа (компания, адрес, комментарий и т.д.).
 */
function AttributesChangedEntry({ entry }) {
    const [expanded, setExpanded] = useState(false);
    const c = entry.data;
    const attributes = c.changes?.attributes || {};
    const fields = Object.keys(attributes);

    const formatValue = (v) => {
        if (v === null || v === undefined || v === '') return '—';
        return String(v);
    };

    return (
        <Stack gap={1}>
            <HStack gap="1.5">
                <LuPencilLine size={14} style={{ color: 'var(--chakra-colors-purple-500)', flexShrink: 0 }} />
                <Text fontWeight="600" fontSize="sm" color="purple.700" _dark={{ color: 'purple.300' }}>
                    Изменены данные заказа
                </Text>
                <SourceBadge source={c.source} userName={c.user_name} />
            </HStack>

            <Text fontSize="xs" color="fg.muted">
                {fields.map((f) => attributes[f].label).join(', ')}
            </Text>

            <HStack fontSize="xs" color="fg.muted" gap="1">
                <LuClock size={12} />
                <Text>{c.created_at}</Text>
                <Text>•</Text>
                <Text>{c.created_at_human}</Text>
            </HStack>

            {fields.length > 0 && (
                <Box mt={1}>
                    <Button
                        variant="ghost"
                        size="xs"
                        onClick={() => setExpanded(!expanded)}
                        color="pecado.600"
                        _hover={{ bg: 'pecado.50', _dark: { bg: 'gray.700' } }}
                    >
                        {expanded ? <LuChevronUp size={14} /> : <LuChevronDown size={14} />}
                        {expanded ? 'Скрыть подробности' : 'Подробности'}
                    </Button>

                    {expanded && (
                        <Box
                            mt={2}
                            bg="gray.50"
                            _dark={{ bg: 'gray.700' }}
                            borderRadius="lg"
                            p={3}
                            fontSize="sm"
                        >
                            <Stack gap={2}>
                                {fields.map((field) => {
                                    const a = attributes[field];
                                    const oldLabel = a.old_label ?? formatValue(a.old);
                                    const newLabel = a.new_label ?? formatValue(a.new);
                                    return (
                                        <HStack key={field} gap="2" align="start">
                                            <Box color="purple.500" mt="1"><LuPencilLine size={14} /></Box>
                                            <Box>
                                                <Text fontWeight="600">{a.label}</Text>
                                                <Text fontSize="xs" color="fg.muted">
                                                    <Box as="span" textDecoration="line-through">{oldLabel}</Box>
                                                    {' → '}
                                                    <Box as="span" fontWeight="600">{newLabel}</Box>
                                                </Text>
                                            </Box>
                                        </HStack>
                                    );
                                })}
                            </Stack>
                        </Box>
                    )}
                </Box>
            )}
        </Stack>
    );
}
