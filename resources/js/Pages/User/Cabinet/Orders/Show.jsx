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
    LuPlus, LuMinus, LuTrendingDown, LuTrendingUp,
} from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';

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
                    <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}>
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

                    <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}>
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
                {order.items?.length > 0 && (
                    <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}>
                        <Card.Header p="4" pb="2">
                            <Flex align="center" gap="2" flexWrap="wrap">
                                {typeIcon}
                                <Text fontWeight="700" fontSize="md">Позиции ({order.items.length})</Text>
                                <Badge colorPalette={typeColor} variant="subtle" ml="1">
                                    {order.items.reduce((s, it) => s + Number(it.quantity || 0), 0)} шт.
                                </Badge>
                            </Flex>
                        </Card.Header>
                        <Card.Body p="0">
                            <Box overflowX="auto">
                                <Table.Root bg={{ base: 'white', _dark: 'gray.800' }} size="sm">
                                    <Table.Header>
                                        <Table.Row bg={{ base: 'white', _dark: 'gray.800' }} _dark={{ bg: 'gray.800' }}>
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
                                            <Table.Row key={item.id}>
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
                                                                <Text fontWeight="500" fontSize="sm">{item.name}</Text>
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
                                </Table.Root>
                            </Box>
                        </Card.Body>
                    </Card.Root>
                )}

                {/* ═══ Итого ═══ */}
                {(() => {
                    const totalSavings = (order.items || []).reduce((acc, item) => {
                        const bp = parseFloat(item.base_price || 0);
                        const fp = parseFloat(item.final_price || item.price || 0);
                        if (bp > fp) acc += (bp - fp) * item.quantity;
                        return acc;
                    }, 0);
                    return (
                        <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}>
                            <Card.Body p="4">
                                <Flex justify="space-between" align="center">
                                    <Flex align="center" gap="2">
                                        <LuShoppingBag size={20} />
                                        <Text fontWeight="700" fontSize="lg">Итого</Text>
                                    </Flex>
                                    <VStack gap="0" align="end">
                                        <Text fontSize="xl" fontWeight="800">
                                            {fmt(order.total_converted)} {currencySymbol}
                                        </Text>
                                        {order.currency_code && order.currency_code !== currency?.code && (
                                            <Text fontSize="xs" color="gray.400">
                                                {fmt(order.total_amount)} {order.currency_code}
                                            </Text>
                                        )}
                                        {totalSavings > 0 && (
                                            <HStack gap="1" mt="1">
                                                <Badge colorPalette="green" variant="subtle" size="sm">
                                                    Сумма скидки: {fmt(totalSavings)} {currencySymbol}
                                                </Badge>
                                            </HStack>
                                        )}
                                    </VStack>
                                </Flex>
                            </Card.Body>
                        </Card.Root>
                    );
                })()}

                {/* ═══ Единый timeline: статусы + изменения ═══ */}
                {timelineEntries.length > 0 && (
                    <OrderTimeline entries={timelineEntries} />
                )}

                {/* ═══ Отгрузки по заказу ═══ */}
                {order.shipments && order.shipments.length > 0 && (
                    <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}>
                        <Card.Header p="4" pb="2">
                            <HStack gap="2">
                                <LuTruck size={20} />
                                <Text fontWeight="700" fontSize="md">
                                    Отгрузки по заказу ({order.shipments.length})
                                </Text>
                            </HStack>
                        </Card.Header>
                        <Card.Body p={0}>
                            <Table.Root bg={{ base: 'white', _dark: 'gray.800' }} size="sm">
                                <Table.Header>
                                    <Table.Row bg={{ base: 'white', _dark: 'gray.800' }} _dark={{ bg: 'gray.800' }}>
                                        <Table.ColumnHeader>Дата</Table.ColumnHeader>
                                        <Table.ColumnHeader>Статус</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="center">Позиций</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Сумма</Table.ColumnHeader>
                                        <Table.ColumnHeader w="60px" />
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {order.shipments.map((shipment) => (
                                        <Table.Row
                                            key={shipment.id}
                                            _hover={{ bg: 'gray.50/50', _dark: { bg: 'gray.800/50' } }}
                                        >
                                            <Table.Cell>
                                                <Text fontSize="sm">
                                                    {shipment.date
                                                        ? new Date(shipment.date).toLocaleDateString('ru-RU')
                                                        : '—'}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge variant="subtle" fontSize="xs">
                                                    {shipment.status_label}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell textAlign="center">
                                                <Text fontSize="sm">{shipment.items_count}</Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <Text fontFamily="mono" fontWeight="600" fontSize="sm">
                                                    {fmt(shipment.total_amount)} {shipment.currency_code}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Link href={`/cabinet/shipments/${shipment.id}`}>
                                                    <Text
                                                        color="pecado.600"
                                                        fontSize="xs"
                                                        _hover={{ textDecoration: 'underline' }}
                                                    >
                                                        Открыть
                                                    </Text>
                                                </Link>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Card.Body>
                    </Card.Root>
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
        <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}>
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
                                    _dark={{ borderColor: 'gray.800' }}
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
