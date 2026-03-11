import {
    Box, Flex, Text, Heading, Button, Table, Badge, Separator, Stack,
    Card, HStack, VStack, SimpleGrid, Image,
} from '@chakra-ui/react';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    LuArrowLeft, LuPackage, LuWarehouse, LuShoppingBag,
    LuClock, LuUser, LuMessageSquare, LuBuilding2, LuMapPin, LuTruck,
} from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';

const STATUS_LABELS = {
    pending: 'Ожидает',
    processing: 'В обработке',
    shipped: 'Отправлен',
    delivered: 'Доставлен',
    cancelled: 'Отменён',
};

const STATUS_COLORS = {
    pending: 'yellow',
    processing: 'blue',
    shipped: 'purple',
    delivered: 'green',
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

    const instockChild = (order.children || []).find(c => c.type === 'in_stock');
    const preorderChild = (order.children || []).find(c => c.type === 'preorder');
    const hasChildren = instockChild || preorderChild;

    const createdAt = order.created_at
        ? new Date(order.created_at).toLocaleDateString('ru-RU', {
            year: 'numeric', month: 'long', day: 'numeric',
            hour: '2-digit', minute: '2-digit',
        })
        : order.created_at_formatted || '—';

    return (
        <CabinetLayout
            title={`Заказ #${order.id}`}
            actions={
                <Button asChild variant="outline" size="sm">
                    <Link href="/cabinet/orders">
                        <LuArrowLeft size={16} />
                        К списку
                    </Link>
                </Button>
            }
        >
            <Head title={`Заказ #${order.id} — Pecado`} />

            <Stack gap="5">
                {/* ═══ Статус ═══ */}
                <Flex align="center" gap="3" flexWrap="wrap">
                    {order.type === 'preorder' ? (
                        <Badge colorPalette="purple" variant="subtle" fontSize="sm" px="3" py="1" borderRadius="full">
                            Предзаказ
                        </Badge>
                    ) : (
                        <Badge colorPalette="gray" variant="outline" fontSize="sm" px="3" py="1" borderRadius="full">
                            Заказ
                        </Badge>
                    )}
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
                        UUID: {order.uuid?.substring(0, 8)}...
                    </Text>
                </Flex>

                {/* ═══ Информация о заказе ═══ */}
                <SimpleGrid columns={{ base: 1, lg: 2 }} gap="4">
                    <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
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
                                                    ({fmt(order.total_amount)} {order.currency_code})
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

                    <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
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
                                            {order.delivery_address.name && (
                                                <Text fontSize="sm" fontWeight="600">{order.delivery_address.name}</Text>
                                            )}
                                            <Text fontSize="sm" color="fg.muted">{order.delivery_address.address}</Text>
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

                {/* ═══ Товары со склада ═══ */}
                {instockChild && instockChild.items?.length > 0 && (
                    <ChildOrderTable
                        title="Товары со склада"
                        icon={<LuWarehouse size={20} />}
                        childOrder={instockChild}
                        currencySymbol={currencySymbol}
                        colorPalette="green"
                        fmt={fmt}
                    />
                )}

                {/* ═══ Товары по предзаказу ═══ */}
                {preorderChild && preorderChild.items?.length > 0 && (
                    <ChildOrderTable
                        title="Товары по предзаказу"
                        icon={<LuPackage size={20} />}
                        childOrder={preorderChild}
                        currencySymbol={currencySymbol}
                        colorPalette="orange"
                        fmt={fmt}
                    />
                )}

                {/* ═══ Все позиции (если нет children) ═══ */}
                {!hasChildren && order.items?.length > 0 && (
                    <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
                        <Card.Header p="4" pb="2">
                            <HStack gap="2">
                                <LuShoppingBag size={20} />
                                <Text fontWeight="700" fontSize="md">Позиции заказа ({order.items.length})</Text>
                            </HStack>
                        </Card.Header>
                        <Card.Body p="0">
                            <Box overflowX="auto">
                                <Table.Root size="sm">
                                    <Table.Header>
                                        <Table.Row bg="gray.50" _dark={{ bg: 'gray.800' }}>
                                            <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                            <Table.ColumnHeader w="90px" textAlign="center">Кол-во</Table.ColumnHeader>
                                            <Table.ColumnHeader w="130px" textAlign="right">Цена</Table.ColumnHeader>
                                            <Table.ColumnHeader w="130px" textAlign="right">Сумма</Table.ColumnHeader>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {order.items.map((item) => (
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
                                                                <Text fontWeight="500" fontSize="sm">
                                                                    {item.name}
                                                                </Text>
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
                                                <Table.Cell textAlign="right">
                                                    <Text fontWeight="500">{fmt(item.price)} {currencySymbol}</Text>
                                                </Table.Cell>
                                                <Table.Cell textAlign="right">
                                                    <Text fontWeight="600">{fmt(item.subtotal)} {currencySymbol}</Text>
                                                </Table.Cell>
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table.Root>
                            </Box>
                        </Card.Body>
                    </Card.Root>
                )}

                {/* ═══ Итого ═══ */}
                <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
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
                                        {fmt(order.total_amount)} {order.currency_code}
                                    </Text>
                                )}
                            </VStack>
                        </Flex>
                    </Card.Body>
                </Card.Root>

                {/* ═══ История статусов ═══ */}
                {order.status_histories && order.status_histories.length > 0 && (
                    <StatusHistoryTimeline histories={order.status_histories} />
                )}

                {/* ═══ Отгрузки по заказу ═══ */}
                {order.shipments && order.shipments.length > 0 && (
                    <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
                        <Card.Header p="4" pb="2">
                            <HStack gap="2">
                                <LuTruck size={20} />
                                <Text fontWeight="700" fontSize="md">
                                    Отгрузки по заказу ({order.shipments.length})
                                </Text>
                            </HStack>
                        </Card.Header>
                        <Card.Body p={0}>
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row bg="gray.50" _dark={{ bg: 'gray.800' }}>
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
                                            _hover={{ bg: 'gray.50', _dark: { bg: 'gray.800' } }}
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

function ChildOrderTable({ title, icon, childOrder, currencySymbol, colorPalette, fmt }) {
    const items = childOrder.items || [];
    const totalQty = items.reduce((s, it) => s + Number(it.quantity || 0), 0);

    return (
        <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
            <Card.Header p="4" pb="2">
                <Flex align="center" gap="2" flexWrap="wrap">
                    {icon}
                    <Text fontWeight="700" fontSize="md">{title}</Text>
                    <Badge colorPalette={colorPalette} variant="subtle" ml="1">
                        {totalQty} шт.
                    </Badge>
                    <Badge
                        colorPalette={STATUS_COLORS[childOrder.status] ?? 'gray'}
                        variant="subtle"
                        ml="auto"
                        borderRadius="full"
                    >
                        {childOrder.status_label || STATUS_LABELS[childOrder.status] || childOrder.status}
                    </Badge>
                </Flex>
            </Card.Header>
            <Card.Body p="0">
                <Box overflowX="auto">
                    <Table.Root size="sm">
                        <Table.Header>
                            <Table.Row bg="gray.50" _dark={{ bg: 'gray.800' }}>
                                <Table.ColumnHeader>Название</Table.ColumnHeader>
                                <Table.ColumnHeader w="90px" textAlign="center">Кол-во</Table.ColumnHeader>
                                <Table.ColumnHeader w="130px" textAlign="right">Цена ({currencySymbol})</Table.ColumnHeader>
                                <Table.ColumnHeader w="130px" textAlign="right">Сумма ({currencySymbol})</Table.ColumnHeader>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {items.map((item) => (
                                <Table.Row key={item.id}>
                                    <Table.Cell>
                                        {item.product?.slug ? (
                                            <Link href={`/products/${item.product.slug}`}>
                                                <Text fontWeight="500" lineClamp={1} _hover={{ color: 'pecado.500' }} transition="color 0.15s">
                                                    {item.product?.name || item.name || 'Товар'}
                                                </Text>
                                            </Link>
                                        ) : (
                                            <Text fontWeight="500" lineClamp={1}>
                                                {item.name || 'Товар'}
                                            </Text>
                                        )}
                                        <Flex gap="1" mt="0.5">
                                            {item.product?.brand?.name && (
                                                <Text fontSize="xs" color="fg.muted">{item.product.brand.name}</Text>
                                            )}
                                            {item.product?.sku && (
                                                <Text fontSize="xs" color="fg.muted">• {item.product.sku}</Text>
                                            )}
                                        </Flex>
                                    </Table.Cell>
                                    <Table.Cell textAlign="center">{item.quantity}</Table.Cell>
                                    <Table.Cell textAlign="right">
                                        <Text fontWeight="500">{fmt(item.price)}</Text>
                                    </Table.Cell>
                                    <Table.Cell textAlign="right">
                                        <Text fontWeight="600">{fmt(item.subtotal)}</Text>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>

                <Separator />

                <Box p="4">
                    <Flex justify="flex-end">
                        <Text fontSize="lg" fontWeight="bold">
                            {fmt(childOrder.total_amount)} {currencySymbol}
                        </Text>
                    </Flex>
                </Box>
            </Card.Body>
        </Card.Root>
    );
}

function StatusHistoryTimeline({ histories = [] }) {
    return (
        <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
            <Card.Header p="4" pb="2">
                <HStack gap="2">
                    <LuClock size={18} />
                    <Text fontWeight="700" fontSize="md">История изменения статусов</Text>
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
                        {histories.map((history, index) => (
                            <Box key={history.id} position="relative" pl="50px">
                                {/* Индикатор */}
                                <Box
                                    position="absolute"
                                    left="10px"
                                    top="2px"
                                    width="18px"
                                    height="18px"
                                    borderRadius="full"
                                    bg={index === 0 ? 'pecado.500' : 'gray.300'}
                                    border="3px solid"
                                    borderColor="white"
                                    _dark={{ borderColor: 'gray.800' }}
                                    zIndex={1}
                                />

                                <Stack gap={1}>
                                    <Text fontWeight="500" fontSize="sm">
                                        {history.old_status ? (
                                            <>
                                                <Box as="span" color="orange.600">
                                                    {history.old_status_label}
                                                </Box>
                                                {' → '}
                                                <Box as="span" color="green.600">
                                                    {history.new_status_label}
                                                </Box>
                                            </>
                                        ) : (
                                            <>
                                                Создан со статусом{' '}
                                                <Box as="span" color="blue.600" fontWeight="600">
                                                    {history.new_status_label}
                                                </Box>
                                            </>
                                        )}
                                    </Text>

                                    <HStack fontSize="xs" color="fg.muted" gap="1">
                                        <LuUser size={12} />
                                        <Text>{history.user_name}</Text>
                                        <Text>•</Text>
                                        <Text>{history.created_at_human}</Text>
                                    </HStack>

                                    {history.comment && (
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
                                                <Text>{history.comment}</Text>
                                            </HStack>
                                        </Box>
                                    )}
                                </Stack>
                            </Box>
                        ))}
                    </Stack>
                </Box>
            </Card.Body>
        </Card.Root>
    );
}
