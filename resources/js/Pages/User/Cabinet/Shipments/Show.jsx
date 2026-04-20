import { Box, Flex, HStack, VStack, Text, Badge, Card, Table, Separator, SimpleGrid } from '@chakra-ui/react';
import { Head, Link, usePage } from '@inertiajs/react';
import { LuArrowLeft, LuPackage, LuShoppingBag, LuTriangleAlert, LuMapPin, LuMessageSquare } from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';

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

    return (
        <CabinetLayout title={`Отгрузка ${shipment.number}`}>
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
                <Card.Header>
                    <Flex justify="space-between" align="center" flexWrap="wrap" gap="3">
                        <VStack align="start" gap="1">
                            <Text fontWeight="700" fontSize="xl">Отгрузка {shipment.number}</Text>
                        </VStack>
                        <Badge
                            colorPalette={STATUS_COLORS[shipment.status] || 'gray'}
                            variant="subtle" px="3" py="1" borderRadius="full" fontSize="sm"
                        >
                            {shipment.status_label}
                        </Badge>
                    </Flex>
                </Card.Header>
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
                            <Flex
                                align="end" gap="3" p="4" borderRadius="lg"
                                bg="pecado.50" _dark={{ bg: 'pecado.900/10', borderColor: 'pecado.800' }}
                                border="1px solid" borderColor="pecado.100"
                            >
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

            <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}>
                <Card.Header>
                    <HStack gap="2">
                        <LuPackage size={18} />
                        <Text fontWeight="600" fontSize="lg">Состав отгрузки ({shipment.items?.length || 0})</Text>
                    </HStack>
                </Card.Header>
                <Card.Body p={0}>
                    {/* Desktop */}
                    <Box overflowX="auto" display={{ base: 'none', md: 'block' }}>
                        <Table.Root bg={{ base: 'white', _dark: 'gray.800' }} size="sm">
                            <Table.Header>
                                <Table.Row bg={{ base: 'white', _dark: 'gray.800' }} _dark={{ bg: 'gray.800' }}>
                                    <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                    <Table.ColumnHeader>Заказ</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Кол-во</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="right">Базовая цена ({currencySymbol})</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Скидка</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Ваша цена ({currencySymbol})</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">НДС</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Итог ({currencySymbol})</Table.ColumnHeader>
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
                                        <Table.Cell textAlign="right">
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
                                                <VStack gap="0" align="end">
                                                    <Badge colorPalette="green" variant="subtle" size="xs">
                                                        -{combinedDiscount > 0 ? combinedDiscount.toFixed(2) : fmt(savingsConverted / grossConverted * 100)}%
                                                    </Badge>
                                                    {combinedDiscount > 0 && parseFloat(item.auto_discount_percent) > 0 && parseFloat(item.manual_discount_percent) > 0 && (
                                                        <Text fontSize="2xs" color="gray.400">
                                                            авт. {parseFloat(item.auto_discount_percent).toFixed(2)}% + руч. {parseFloat(item.manual_discount_percent).toFixed(2)}%
                                                        </Text>
                                                    )}
                                                </VStack>
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
                                            <Text fontFamily="mono" fontSize="sm" color="gray.500">
                                                {item.vat_rate != null ? `${item.vat_rate}%` : '—'}
                                            </Text>
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">
                                            <VStack gap="0" align="end">
                                                <Text fontFamily="mono" fontWeight="600">{fmt(totalConverted)}</Text>
                                                {shipment.currency_code !== currency?.code && (
                                                    <Text fontSize="xs" color="gray.400">{fmt(item.total)} {shipment.currency_code}</Text>
                                                )}
                                                {savingsConverted > 0 && (
                                                    <Text fontSize="xs" color="green.600" _dark={{ color: 'green.400' }}>
                                                        Экономия: {fmt(savingsConverted)}
                                                    </Text>
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
                                    <InfoBlock label="Базовая цена" value={`${fmt(basePriceConverted)} ${currencySymbol}`} mono />
                                    {hasDiscount && (
                                        <InfoBlock
                                            label="Скидка"
                                            value={`${combinedDiscount > 0 ? combinedDiscount.toFixed(2) : fmt(savingsConverted / grossConverted * 100)}%`}
                                        />
                                    )}
                                    <InfoBlock label="Ваша цена" value={`${fmt(effectivePriceConverted)} ${currencySymbol}`} mono />
                                    <InfoBlock
                                        label="НДС"
                                        value={item.vat_rate != null ? `${item.vat_rate}%` : '—'}
                                    />
                                    <Box>
                                        <Text fontSize="xs" color="gray.500" mb="1">Итог</Text>
                                        <Text fontWeight="700" fontFamily="mono">
                                            {fmt(totalConverted)} {currencySymbol}
                                        </Text>
                                        {savingsConverted > 0 && (
                                            <Text fontSize="xs" color="green.600" _dark={{ color: 'green.400' }}>
                                                Ваша выгода: {fmt(savingsConverted)} {currencySymbol}
                                            </Text>
                                        )}
                                    </Box>
                                </SimpleGrid>
                            </Box>
                            );
                        })}
                        {(!shipment.items || shipment.items.length === 0) && (
                            <Text color="gray.400" textAlign="center" py="4">Позиции отсутствуют</Text>
                        )}
                    </VStack>
                </Card.Body>
            </Card.Root>
        </CabinetLayout>
    );
}
