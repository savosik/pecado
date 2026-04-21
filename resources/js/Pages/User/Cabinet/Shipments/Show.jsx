import { Box, Flex, HStack, VStack, Text, Badge, Card, Table, Separator, SimpleGrid } from '@chakra-ui/react';
import { Head, Link, usePage } from '@inertiajs/react';
import { LuArrowLeft, LuPackage, LuShoppingBag, LuTriangleAlert, LuMapPin, LuMessageSquare, LuInfo } from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Tooltip } from '@/components/ui/tooltip';

const ORDER_STATUS_COLORS = {
    pending: 'yellow',
    confirmed: 'blue',
    ready_to_ship: 'purple',
    closed: 'green',
    deleted: 'red',
};

const STATUS_COLORS = {
    new: 'blue',
    completed: 'green',
    cancelled: 'red',
    in_progress: 'orange',
};

function InfoBlock({ label, value, mono = false }) {
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="1">{label}</Text>
            <Text fontSize="sm" fontWeight="500" fontFamily={mono ? 'mono' : undefined}>{value || '—'}</Text>
        </Box>
    );
}

export default function ShipmentShow({ shipment, related_orders, overdue_detail }) {
    const { currency } = usePage().props;
    const currencySymbol = currency?.symbol ?? '₽';

    const fmt = (v) => Number(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const statusBadge = (
        <Badge
            colorPalette={STATUS_COLORS[shipment.status] || 'gray'}
            variant="subtle" px="3" py="1" borderRadius="full" fontSize="sm"
        >
            {shipment.status_label}
        </Badge>
    );

    return (
        <CabinetLayout title={`Отгрузка ${shipment.number}`} actions={statusBadge}>
            <Head title={`Отгрузка ${shipment.number} — Pecado`} />

            {/* Назад */}
            <Box mb="4">
                <Link href="/cabinet/shipments">
                    <HStack gap="1" color="pecado.600" _hover={{ textDecoration: 'underline' }} fontSize="sm">
                        <LuArrowLeft size={14} />
                        <Text>К списку отгрузок</Text>
                    </HStack>
                </Link>
            </Box>

            {/* Основная информация */}
            <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} mb={6} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}>
                <Card.Body>
                    <SimpleGrid columns={{ base: 2, sm: 3, md: 4 }} gap={5}>
                        <InfoBlock
                            label="Дата отгрузки"
                            value={shipment.date ? new Date(shipment.date).toLocaleDateString('ru-RU') : '—'}
                        />
                        <InfoBlock label="ИНН контрагента" value={shipment.tax_id} mono />
                        <InfoBlock label="Компания" value={shipment.company?.name} />
                        <InfoBlock label="Валюта (1С)" value={shipment.currency_code} />
                    </SimpleGrid>

                    <Separator my={4} />

                    {/* Итоговая сумма */}
                    {(() => {
                        const totalSavings = (shipment.items || []).reduce((acc, item) => {
                            const base = parseFloat(item.price_converted || 0) * item.quantity;
                            const total = parseFloat(item.total_converted || 0);
                            if (base > total + 0.01) acc += base - total;
                            return acc;
                        }, 0);
                        return (
                            <Flex align="end" gap="3">
                                <Box>
                                    <Text fontSize="xs" color="gray.500" mb="1">Итоговая сумма</Text>
                                    <Text fontWeight="800" fontSize="2xl" fontFamily="mono">
                                        {fmt(shipment.total_converted)} {currencySymbol}
                                    </Text>
                                    {totalSavings > 0 && (
                                        <Text fontSize="sm" color="green.600" _dark={{ color: 'green.400' }} mt="1">
                                            Ваша выгода: {fmt(totalSavings)} {currencySymbol}
                                        </Text>
                                    )}
                                </Box>
                                {shipment.currency_code && shipment.currency_code !== currency?.code && (
                                    <Text fontSize="sm" color="gray.400" pb="1">
                                        ({fmt(shipment.total_amount)} {shipment.currency_code})
                                    </Text>
                                )}
                            </Flex>
                        );
                    })()}
                </Card.Body>
            </Card.Root>

            {/* Связанные заказы */}
            {related_orders && related_orders.length > 0 && (
                <Box mb={6}>
                    <HStack gap="2" mb="3">
                        <LuShoppingBag size={18} />
                        <Text fontWeight="600" fontSize="lg">Заказы по этой отгрузке ({related_orders.length})</Text>
                    </HStack>
                    <VStack gap="2" align="stretch">
                        {related_orders.map((order) => (
                            <Link key={order.id} href={`/cabinet/orders/${order.id}`}>
                                <Box
                                    bg={{ base: 'white', _dark: 'gray.800' }}
                                    borderRadius="xl"
                                    border="1px solid"
                                    borderColor={{ base: 'gray.100', _dark: 'gray.700' }}
                                    p="4"
                                    _hover={{ borderColor: 'pecado.200', shadow: 'sm', _dark: { borderColor: 'pecado.700' } }}
                                    transition="all 0.15s"
                                    cursor="pointer"
                                >
                                    <Flex gap="4" align="start" justify="space-between">
                                        <Box flex="1" minW="0">
                                            <Flex gap="2" align="center" flexWrap="wrap" mb="1.5">
                                                <Text fontWeight="700" fontSize="md" fontFamily="mono" whiteSpace="nowrap" flexShrink="0">
                                                    {order.number}
                                                </Text>
                                                <Badge
                                                    colorPalette={order.type === 'preorder' ? 'purple' : 'gray'}
                                                    variant="subtle" fontSize="2xs" px="1.5"
                                                >
                                                    {order.type === 'preorder' ? 'Предзаказ' : 'Заказ'}
                                                </Badge>
                                                <Flex align="center" gap="1.5">
                                                    <Badge
                                                        colorPalette={ORDER_STATUS_COLORS[order.status] || 'gray'}
                                                        variant="subtle" fontSize="xs" borderRadius="full" px="2.5"
                                                    >
                                                        {order.status_label}
                                                    </Badge>
                                                    <Text fontSize="2xs" color="gray.400">{order.updated_at}</Text>
                                                </Flex>
                                            </Flex>

                                            <HStack gap="3" fontSize="xs" color="gray.500" flexWrap="wrap" mb={order.delivery_address || order.comment ? '1.5' : '0'}>
                                                {order.company && (
                                                    <Text fontWeight="500">{order.company.name}</Text>
                                                )}
                                                <Text>{order.items_count} {order.items_count === 1 ? 'позиция' : order.items_count < 5 ? 'позиции' : 'позиций'}</Text>
                                                {order.shipments_count > 0 && (
                                                    <Text>{order.shipments_count} {order.shipments_count === 1 ? 'отгрузка' : order.shipments_count < 5 ? 'отгрузки' : 'отгрузок'}</Text>
                                                )}
                                            </HStack>

                                            {order.delivery_address && (
                                                <HStack gap="1" fontSize="xs" color="gray.500" mb="1" minW="0">
                                                    <Box flexShrink="0" color="gray.400"><LuMapPin size={11} /></Box>
                                                    <Text noOfLines={1}>{order.delivery_address}</Text>
                                                </HStack>
                                            )}

                                            {order.comment && (
                                                <HStack gap="1" fontSize="xs" color="gray.400" minW="0">
                                                    <Box flexShrink="0"><LuMessageSquare size={11} /></Box>
                                                    <Text noOfLines={1} fontStyle="italic">{order.comment}</Text>
                                                </HStack>
                                            )}
                                        </Box>

                                        <VStack gap="0" align="end" flexShrink="0">
                                            <Text fontWeight="700" fontSize="lg" fontFamily="mono" whiteSpace="nowrap">
                                                {fmt(order.total_converted)} {currencySymbol}
                                            </Text>
                                            {order.currency_code && order.currency_code !== currency?.code && (
                                                <Text fontSize="xs" color="gray.400" whiteSpace="nowrap">
                                                    {fmt(order.total_amount)} {order.currency_code}
                                                </Text>
                                            )}
                                        </VStack>
                                    </Flex>
                                </Box>
                            </Link>
                        ))}
                    </VStack>
                </Box>
            )}

            {/* Просрочка по этой реализации */}
            {overdue_detail && (
                <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} mb={6} borderRadius="xl" border="2px solid" borderColor="red.200" _dark={{ borderColor: 'red.800' }}>
                    <Card.Body p={4}>
                        <HStack gap="3" align="start">
                            <Box color="red.500" flexShrink={0} pt={0.5}>
                                <LuTriangleAlert size={20} />
                            </Box>
                            <Box>
                                <Text fontWeight="700" color="red.700" _dark={{ color: 'red.300' }} mb={1}>
                                    Задолженность по этой отгрузке
                                </Text>
                                <HStack gap="4">
                                    <Box>
                                        <Text fontSize="xs" color="gray.500">Сумма просрочки</Text>
                                        <Text fontFamily="mono" fontWeight="700" color="red.600" _dark={{ color: 'red.400' }}>
                                            {fmt(overdue_detail.amount)} ₽
                                        </Text>
                                    </Box>
                                    <Box>
                                        <Text fontSize="xs" color="gray.500">Дата оплаты</Text>
                                        <Text fontFamily="mono" fontWeight="700" color="red.600" _dark={{ color: 'red.400' }}>
                                            {overdue_detail.due_date
                                                ? new Date(overdue_detail.due_date).toLocaleDateString('ru-RU')
                                                : '—'}
                                        </Text>
                                    </Box>
                                </HStack>
                            </Box>
                        </HStack>
                    </Card.Body>
                </Card.Root>
            )}

            <Box>
                <HStack gap="2" mb="3">
                    <LuPackage size={18} />
                    <Text fontWeight="600" fontSize="lg">Состав отгрузки ({shipment.items?.length || 0})</Text>
                </HStack>
                <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}>
                <Card.Body p={0}>
                    {/* Desktop */}
                    <Box overflowX="auto" display={{ base: 'none', md: 'block' }}>
                        <Table.Root bg={{ base: 'white', _dark: 'gray.800' }} size="sm">
                            <Table.Header>
                                <Table.Row bg={{ base: 'white', _dark: 'gray.800' }} _dark={{ bg: 'gray.800' }}>
                                    <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                    <Table.ColumnHeader>Заказ</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="center">Количество</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Цена без скидки</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Скидка</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Цена со скидкой</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Сумма</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {shipment.items?.map((item, idx) => {
                                    const basePriceConverted = parseFloat(item.price_converted || 0);
                                    const grossConverted = basePriceConverted * item.quantity;
                                    const totalConverted = parseFloat(item.total_converted || 0);
                                    const hasDiscount = grossConverted > totalConverted + 0.01;
                                    const effectivePriceConverted = item.quantity > 0 ? totalConverted / item.quantity : 0;
                                    const savingsConverted = hasDiscount ? grossConverted - totalConverted : 0;
                                    const combinedDiscount = parseFloat(item.auto_discount_percent || 0) + parseFloat(item.manual_discount_percent || 0);
                                    return (
                                    <Table.Row key={item.id || idx} _hover={{ bg: 'gray.50/50', _dark: { bg: 'gray.800/50' } }}>
                                        <Table.Cell>
                                            {item.product ? (
                                                <Link href={`/products/${item.product.slug}`}>
                                                    <Text color="pecado.600" _hover={{ textDecoration: 'underline' }} fontSize="sm">
                                                        {item.product.name}
                                                    </Text>
                                                </Link>
                                            ) : <Text color="gray.400" fontSize="sm">Товар не найден</Text>}
                                            {item.product?.sku && (
                                                <Text fontSize="xs" color="gray.400">СКУ: {item.product.sku}</Text>
                                            )}
                                        </Table.Cell>
                                        <Table.Cell>
                                            {item.order_id ? (
                                                <Link href={`/cabinet/orders/${item.order_id}`}>
                                                    <Text color="pecado.600" _hover={{ textDecoration: 'underline' }} fontSize="xs" fontWeight="500">
                                                        {item.order_number}
                                                    </Text>
                                                </Link>
                                            ) : <Text color="gray.300" fontSize="xs">—</Text>}
                                        </Table.Cell>
                                        <Table.Cell textAlign="center">
                                            <Text fontFamily="mono">{item.quantity}</Text>
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">
                                            <VStack gap="0" align="end">
                                                <Text fontFamily="mono" fontSize="sm">{fmt(basePriceConverted)}</Text>
                                                {shipment.currency_code !== currency?.code && (
                                                    <Text fontSize="xs" color="gray.400">{fmt(item.price)} {shipment.currency_code}</Text>
                                                )}
                                            </VStack>
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">
                                            {hasDiscount ? (
                                                <HStack gap="1" justify="flex-end" align="center">
                                                    {combinedDiscount > 0 && (
                                                        <Tooltip
                                                            showArrow
                                                            content={
                                                                <Box>
                                                                    <Text fontSize="xs">Авт.: {parseFloat(item.auto_discount_percent || 0).toFixed(2)}%</Text>
                                                                    <Text fontSize="xs">Руч.: {parseFloat(item.manual_discount_percent || 0).toFixed(2)}%</Text>
                                                                </Box>
                                                            }
                                                        >
                                                            <Box as="span" color="gray.400" cursor="help" display="inline-flex" aria-label="Состав скидки">
                                                                <LuInfo size={12} />
                                                            </Box>
                                                        </Tooltip>
                                                    )}
                                                    <Badge colorPalette="green" variant="subtle" size="xs">
                                                        -{combinedDiscount > 0 ? combinedDiscount.toFixed(2) : fmt(savingsConverted / grossConverted * 100)}%
                                                    </Badge>
                                                </HStack>
                                            ) : (
                                                <Text fontFamily="mono" fontSize="sm" color="gray.300">—</Text>
                                            )}
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">
                                            <VStack gap="0" align="end">
                                                <Text fontFamily="mono" fontSize="sm" fontWeight="500">{fmt(effectivePriceConverted)}</Text>
                                                {shipment.currency_code !== currency?.code && (
                                                    <Text fontSize="xs" color="gray.400">
                                                        {fmt(item.quantity > 0 ? parseFloat(item.total || 0) / item.quantity : 0)} {shipment.currency_code}
                                                    </Text>
                                                )}
                                            </VStack>
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">
                                            <VStack gap="0" align="end">
                                                <Text fontFamily="mono" fontWeight="600">{fmt(totalConverted)}</Text>
                                                {shipment.currency_code !== currency?.code && (
                                                    <Text fontSize="xs" color="gray.400">{fmt(item.total)} {shipment.currency_code}</Text>
                                                )}
                                            </VStack>
                                        </Table.Cell>
                                    </Table.Row>
                                    );
                                })}
                            </Table.Body>
                        </Table.Root>
                    </Box>

                    {/* Mobile cards */}
                    <VStack gap="3" p="4" display={{ base: 'flex', md: 'none' }}>
                        {shipment.items?.map((item, idx) => {
                            const basePriceConverted = parseFloat(item.price_converted || 0);
                            const totalConverted = parseFloat(item.total_converted || 0);
                            const grossConverted = basePriceConverted * item.quantity;
                            const hasDiscount = grossConverted > totalConverted + 0.01;
                            const effectivePriceConverted = item.quantity > 0 ? totalConverted / item.quantity : 0;
                            const savingsConverted = hasDiscount ? grossConverted - totalConverted : 0;
                            const combinedDiscount = parseFloat(item.auto_discount_percent || 0) + parseFloat(item.manual_discount_percent || 0);
                            return (
                            <Box
                                key={item.id || idx}
                                w="100%" p="4" borderRadius="lg" border="1px solid"
                                borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}
                            >
                                <Text fontWeight="600" mb="2">
                                    {item.product?.name || 'Товар не найден'}
                                </Text>
                                <SimpleGrid columns={2} gap={3}>
                                    <InfoBlock label="Количество" value={item.quantity} />
                                    <InfoBlock label="Цена без скидки" value={`${fmt(basePriceConverted)} ${currencySymbol}`} mono />
                                    {hasDiscount && (
                                        <Box>
                                            <Text fontSize="xs" color="gray.500" mb="1">Скидка</Text>
                                            <HStack gap="1" align="center">
                                                {combinedDiscount > 0 && (
                                                    <Tooltip
                                                        showArrow
                                                        content={
                                                            <Box>
                                                                <Text fontSize="xs">Авт.: {parseFloat(item.auto_discount_percent || 0).toFixed(2)}%</Text>
                                                                <Text fontSize="xs">Руч.: {parseFloat(item.manual_discount_percent || 0).toFixed(2)}%</Text>
                                                            </Box>
                                                        }
                                                    >
                                                        <Box as="span" color="gray.400" cursor="help" display="inline-flex" aria-label="Состав скидки">
                                                            <LuInfo size={12} />
                                                        </Box>
                                                    </Tooltip>
                                                )}
                                                <Text fontSize="sm" fontWeight="500">
                                                    {combinedDiscount > 0 ? combinedDiscount.toFixed(2) : fmt(savingsConverted / grossConverted * 100)}%
                                                </Text>
                                            </HStack>
                                        </Box>
                                    )}
                                    <InfoBlock label="Цена со скидкой" value={`${fmt(effectivePriceConverted)} ${currencySymbol}`} mono />
                                    <Box>
                                        <Text fontSize="xs" color="gray.500" mb="1">Сумма</Text>
                                        <Text fontWeight="700" fontFamily="mono">
                                            {fmt(totalConverted)} {currencySymbol}
                                        </Text>
                                    </Box>
                                </SimpleGrid>
                            </Box>
                            );
                        })}
                        {(!shipment.items || shipment.items.length === 0) && (
                            <Text color="gray.400" textAlign="center" py="4">Позиции отсутствуют</Text>
                        )}
                    </VStack>

                    {/* Итого/Экономия под таблицей */}
                    {shipment.items && shipment.items.length > 0 && (() => {
                        const totalSavings = shipment.items.reduce((acc, item) => {
                            const base = parseFloat(item.price_converted || 0) * item.quantity;
                            const total = parseFloat(item.total_converted || 0);
                            return base > total + 0.01 ? acc + (base - total) : acc;
                        }, 0);
                        return (
                            <Flex
                                justify="flex-end" p="4"
                                borderTop="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }}
                                _dark={{ borderColor: 'gray.700' }}
                            >
                                <VStack gap="1" align="end">
                                    <HStack gap="2">
                                        <Text fontSize="sm" color="gray.500">Итого:</Text>
                                        <Text fontWeight="700" fontFamily="mono">
                                            {fmt(shipment.total_converted)} {currencySymbol}
                                        </Text>
                                    </HStack>
                                    {totalSavings > 0 && (
                                        <HStack gap="2">
                                            <Text fontSize="sm" color="gray.500">Экономия:</Text>
                                            <Text fontWeight="700" fontFamily="mono" color="green.600" _dark={{ color: 'green.400' }}>
                                                {fmt(totalSavings)} {currencySymbol}
                                            </Text>
                                        </HStack>
                                    )}
                                </VStack>
                            </Flex>
                        );
                    })()}
                </Card.Body>
                </Card.Root>
            </Box>
        </CabinetLayout>
    );
}
