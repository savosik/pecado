import { useState } from 'react';
import {
    Box, Flex, Text, Heading, Button, Table, Badge, Separator, Stack,
    Card, HStack, VStack, SimpleGrid, Image, Collapsible,
} from '@chakra-ui/react';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    LuArrowLeft, LuPackage, LuWarehouse,
    LuClock, LuUser, LuMessageSquare, LuBuilding2, LuMapPin, LuTruck, LuShoppingBag,
    LuPencilLine, LuArrowRightLeft, LuChevronDown, LuChevronUp,
    LuPlus, LuMinus, LuTrendingDown, LuTrendingUp, LuCalendar, LuFileSpreadsheet,
    LuSearch,
} from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Tooltip } from '@/components/ui/tooltip';

const STATUS_LABELS = {
    pending: 'Ожидает',
    confirmed: 'Подтверждён',
    ready_to_ship: 'К отгрузке',
    closed: 'Закрыт',
    deleted: 'Удалён',
};

const STATUS_COLORS = {
    pending: 'yellow',
    confirmed: 'blue',
    ready_to_ship: 'purple',
    closed: 'green',
    deleted: 'red',
};

const SHIPMENT_STATUS_COLORS = {
    new: 'blue',
    in_progress: 'orange',
    completed: 'green',
    cancelled: 'red',
};

const TYPE_LABELS = {
    standard: 'Стандартный',
    in_stock: 'Со склада',
    preorder: 'Предзаказ',
};

export default function OrderShow({ order }) {
    const { currency } = usePage().props;
    const currencySymbol = currency?.symbol ?? '₽';
    const fmt = (v) => Number(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const isPreorder = order.type === 'preorder';
    const typeLabel  = isPreorder ? 'Предзаказ' : 'Заказ со склада';
    const typeIcon   = isPreorder ? <LuPackage size={20} /> : <LuWarehouse size={20} />;
    const typeColor  = isPreorder ? 'orange' : 'green';
    const typeBadgeScheme = isPreorder ? 'purple' : 'teal';

    const createdAt = order.created_at
        ? new Date(order.created_at).toLocaleDateString('ru-RU', {
            year: 'numeric', month: 'long', day: 'numeric',
            hour: '2-digit', minute: '2-digit',
        })
        : order.created_at_formatted || '—';

    // Объединённый timeline
    const timelineEntries = buildTimeline(order.status_histories, order.change_logs);

    return (
        <CabinetLayout
            title={`Заказ ${order.number}`}
            actions={
                <Button asChild variant="outline" size="sm">
                    <Link href="/cabinet/orders">
                        <LuArrowLeft size={16} />
                        К списку
                    </Link>
                </Button>
            }
        >
            <Head title={`Заказ ${order.number} — Pecado`} />

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
                    <Text fontSize="sm" color="fg.muted">
                        Заказ {order.number} от {createdAt.split(',')[0]}
                    </Text>
                </Flex>

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
                                {order.delivery_address && (
                                    <HStack gap="2" align="start">
                                        <LuMapPin size={16} style={{ marginTop: 2, flexShrink: 0, color: 'var(--chakra-colors-gray-400)' }} />
                                        <Box>
                                            <Text fontSize="sm" color="fg.muted">{order.delivery_address}</Text>
                                        </Box>
                                    </HStack>
                                )}
                                {!order.company && !order.delivery_address && (
                                    <Text fontSize="sm" color="fg.muted">Нет данных</Text>
                                )}
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                </SimpleGrid>

                {/* ═══ Позиции заказа ═══ */}
                {order.items?.length > 0 && (() => {
                    const totalSavings = (order.items || []).reduce((acc, item) => {
                        const bp = parseFloat(item.base_price || 0);
                        const fp = parseFloat(item.final_price || item.price || 0);
                        if (bp > fp) acc += (bp - fp) * item.quantity;
                        return acc;
                    }, 0);

                    return (
                    <Box>
                        <Flex align="center" gap="2" flexWrap="wrap" mb="3">
                            {typeIcon}
                            <Text fontWeight="700" fontSize="md">Позиции ({order.items.length})</Text>
                            <Badge colorPalette={typeColor} variant="subtle" ml="1">
                                {order.items.reduce((s, it) => s + Number(it.quantity || 0), 0)} шт.
                            </Badge>
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
                                            <Table.Row key={item.id} bg="bg">
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
                                                            <Flex gap="1" mt="0.5">
                                                                {item.product?.brand?.name && (
                                                                    <Text fontSize="xs" color="fg.muted">{item.product.brand.name}</Text>
                                                                )}
                                                                {item.product?.sku && (
                                                                    <Text fontSize="xs" color="fg.muted">• {item.product.sku}</Text>
                                                                )}
                                                            </Flex>
                                                        </Box>
                                                    </HStack>
                                                </Table.Cell>
                                                <Table.Cell textAlign="center">{item.quantity}</Table.Cell>
                                                <Table.Cell textAlign="right">{fmt(basePrice)}</Table.Cell>
                                                <Table.Cell textAlign="right">{fmt(discountPct)}%</Table.Cell>
                                                <Table.Cell textAlign="right">{fmt(finalPrice)}</Table.Cell>
                                                <Table.Cell textAlign="right" fontWeight="600">{fmt(item.subtotal)}</Table.Cell>
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
                                                        <Badge
                                                            colorPalette={SHIPMENT_STATUS_COLORS[shipment.status] || 'gray'}
                                                            variant="subtle" fontSize="2xs" px="2" borderRadius="full"
                                                        >
                                                            {shipment.status_label}
                                                        </Badge>
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
 * Объединяет status_histories и change_logs в один массив, сортирует по дате.
 */
function buildTimeline(statusHistories = [], changeLogs = []) {
    const entries = [];

    for (const h of statusHistories) {
        entries.push({
            id: `status-${h.id}`,
            type: 'status_changed',
            created_at: h.created_at,
            created_at_human: h.created_at_human,
            data: h,
        });
    }

    for (const c of changeLogs) {
        entries.push({
            id: `change-${c.id}`,
            type: c.type,
            created_at: c.created_at,
            created_at_human: c.created_at_human,
            data: c,
        });
    }

    // Сортировка: новейшие сверху
    entries.sort((a, b) => {
        const da = a.created_at.split('.').reverse().join('-');
        const db = b.created_at.split('.').reverse().join('-');
        return db.localeCompare(da);
    });

    return entries;
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
                                            : entry.type === 'attributes_updated'
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

const SOURCE_LABELS_MAP = { erp: '1С', admin: 'Админ', system: 'Система' };
const SOURCE_COLORS_MAP = { erp: 'blue', admin: 'purple', system: 'gray' };

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
