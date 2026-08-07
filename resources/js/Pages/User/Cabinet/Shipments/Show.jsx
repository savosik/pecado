import { Box, Flex, HStack, VStack, Text, Badge, Card, Table, Separator, SimpleGrid, Image } from '@chakra-ui/react';
import { Head, Link, usePage } from '@inertiajs/react';
import { LuArrowLeft, LuPackage, LuShoppingBag, LuTriangleAlert, LuMapPin, LuMessageSquare, LuInfo, LuTruck, LuClock, LuFileSpreadsheet } from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Tooltip } from '@/components/ui/tooltip';
import PaymentScheduleBlock from '@/components/payments/PaymentScheduleBlock';
import { getOrderTypeShortLabel, getOrderTypeColor } from '@/constants/orderType';

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

const PAYMENT_STATUS_COLORS = {
    unpaid: 'gray',
    partial: 'orange',
    paid: 'green',
    overpaid: 'purple',
};

export default function ShipmentShow({ shipment, related_orders, overdue_detail }) {
    const { currency } = usePage().props;
    const currencySymbol = currency?.symbol ?? '₽';

    const fmt = (v) => Number(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // TODO: пока у отгрузок единственный статус «Выполнена» — бейдж временно скрыт
    // const statusBadge = (
    //     <Badge
    //         colorPalette={STATUS_COLORS[shipment.status] || 'gray'}
    //         variant="subtle" px="3" py="1" borderRadius="full" fontSize="sm"
    //     >
    //         {shipment.status_label}
    //     </Badge>
    // );

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
            <Card.Root bg="bg" mb={6} borderRadius="xl" border="1px solid" borderColor="border.muted">
                <Card.Body>
                    <SimpleGrid columns={{ base: 2, sm: 3, md: 4 }} gap={5}>
                        <InfoBlock
                            label="Дата отгрузки"
                            value={shipment.date ? new Date(shipment.date).toLocaleDateString('ru-RU') : '—'}
                        />
                        <InfoBlock label="ИНН контрагента" value={shipment.tax_id} mono />
                        <InfoBlock label="Компания" value={shipment.company?.name} />
                        {/* Продавец — наше юрлицо по накладной. Приходит из 1С;
                            пока не пришёл, блок не показываем. */}
                        {shipment.seller && (
                            <InfoBlock label="Продавец" value={shipment.seller.name} />
                        )}
                        <InfoBlock label="Валюта" value={shipment.currency_code} />
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

            {/* Просрочка по этой реализации */}
            {overdue_detail && (
                <Card.Root bg="bg" mb={6} borderRadius="xl" border="2px solid" borderColor="red.200" _dark={{ borderColor: 'red.800' }}>
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

            {/* Оплата этой отгрузки: чем закрыта и сколько осталось.
                Суммы приходят из 1С через разнесение платежей. */}
            {shipment.payment_status && (
                <Card.Root bg="bg" mb={6} borderRadius="xl" border="1px solid" borderColor="border.muted">
                    <Card.Body p={4}>
                        <Flex justify="space-between" align="center" flexWrap="wrap" gap="2" mb="3">
                            <Text fontWeight="700" fontSize="md">Оплата</Text>
                            <Badge
                                colorPalette={PAYMENT_STATUS_COLORS[shipment.payment_status] || 'gray'}
                                variant="subtle" px="3" py="1" borderRadius="full" fontSize="sm"
                            >
                                {shipment.payment_status_label}
                            </Badge>
                        </Flex>

                        <HStack gap="6" flexWrap="wrap" mb={shipment.payments?.length ? '4' : '0'}>
                            <Box>
                                <Text fontSize="xs" color="gray.500">Оплачено</Text>
                                <Text fontFamily="mono" fontWeight="700">
                                    {fmt(shipment.paid_amount)} {shipment.currency_code || currencySymbol}
                                </Text>
                            </Box>
                            <Box>
                                <Text fontSize="xs" color="gray.500">Остаток к оплате</Text>
                                <Text
                                    fontFamily="mono"
                                    fontWeight="700"
                                    color={shipment.unpaid_amount > 0 ? 'orange.600' : undefined}
                                    _dark={shipment.unpaid_amount > 0 ? { color: 'orange.400' } : undefined}
                                >
                                    {fmt(shipment.unpaid_amount)} {shipment.currency_code || currencySymbol}
                                </Text>
                            </Box>
                        </HStack>

                        {shipment.payments?.length > 0 ? (
                            <VStack gap="2" align="stretch">
                                {shipment.payments.map((payment) => (
                                    <Link key={payment.id} href={`/cabinet/payments/${payment.id}`}>
                                        <Flex
                                            justify="space-between"
                                            align="center"
                                            gap="3"
                                            flexWrap="wrap"
                                            border="1px solid"
                                            borderColor="border.muted"
                                            borderRadius="lg"
                                            px="3"
                                            py="2"
                                            _hover={{ borderColor: 'pecado.200', _dark: { borderColor: 'pecado.700' } }}
                                            transition="all 0.15s"
                                        >
                                            <HStack gap="3" flexWrap="wrap">
                                                <Text fontSize="sm" fontWeight="600" fontFamily="mono">
                                                    {payment.number || `#${payment.id}`}
                                                </Text>
                                                <Text fontSize="xs" color="gray.500">{payment.date}</Text>
                                                <Badge
                                                    colorPalette={payment.direction === 'out' ? 'red' : 'green'}
                                                    variant="subtle" fontSize="2xs" px="1.5"
                                                >
                                                    {payment.direction_label}
                                                </Badge>
                                            </HStack>
                                            <Text fontSize="sm" fontFamily="mono" fontWeight="600" whiteSpace="nowrap">
                                                {fmt(payment.amount)} {shipment.currency_code || currencySymbol}
                                            </Text>
                                        </Flex>
                                    </Link>
                                ))}
                            </VStack>
                        ) : (
                            <Text fontSize="sm" color="gray.500">
                                Платежей по этой отгрузке пока нет.
                            </Text>
                        )}
                    </Card.Body>
                </Card.Root>
            )}

            {/* График оплаты из 1С: план рядом с фактом. Приходит не по всем
                документам — блок сам себя скрывает, когда графика нет. */}
            {shipment.payment_schedule && (
                <Box mb={6}>
                    <PaymentScheduleBlock
                        schedule={shipment.payment_schedule}
                        currencySymbol={shipment.currency_code || currencySymbol}
                    />
                </Box>
            )}

            {/* Связанные заказы */}
            {related_orders && related_orders.length > 0 && (
                <Box mb={6}>
                    <HStack gap="2" mb="3">
                        <LuShoppingBag size={18} />
                        <Text fontWeight="600" fontSize="lg">Заказы по этой отгрузке ({related_orders.length})</Text>
                    </HStack>
                    <VStack gap="2" align="stretch">
                        {related_orders.map((order) => {
                            const itemsLabel = order.items_count === 1
                                ? 'позиция'
                                : order.items_count < 5 ? 'позиции' : 'позиций';
                            const shipmentsLabel = order.shipments_count === 1
                                ? 'отгрузка'
                                : order.shipments_count < 5 ? 'отгрузки' : 'отгрузок';
                            const hasDiscount = Number(order.original_total_converted || 0) > Number(order.total_converted || 0);
                            const isForeignCurrency = order.currency_code && order.currency_code !== currency?.code;

                            return (
                                <Link key={order.id} href={`/cabinet/orders/${order.id}`}>
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
                                        <Flex
                                            direction={{ base: 'column', md: 'row' }}
                                            gap={{ base: '3', md: '4' }}
                                            align={{ md: 'start' }}
                                            justify="space-between"
                                        >
                                            <Box flex="1" minW="0">
                                                {/* Строка 1: номер + бейджи + дата */}
                                                <Flex gap="2" align="center" flexWrap="wrap" mb="2">
                                                    <Text
                                                        fontWeight="700"
                                                        fontSize="md"
                                                        fontFamily="mono"
                                                        whiteSpace="nowrap"
                                                        color="gray.800"
                                                        _dark={{ color: 'gray.100' }}
                                                    >
                                                        {order.number}
                                                    </Text>
                                                    <Badge
                                                        colorPalette={getOrderTypeColor(order.type)}
                                                        variant="subtle" fontSize="2xs" px="2" borderRadius="full"
                                                    >
                                                        {getOrderTypeShortLabel(order.type)}
                                                    </Badge>
                                                    <Badge
                                                        colorPalette={ORDER_STATUS_COLORS[order.status] || 'gray'}
                                                        variant="subtle" fontSize="2xs" px="2" borderRadius="full"
                                                    >
                                                        {order.status_label}
                                                    </Badge>
                                                    <Tooltip
                                                        content={`Обновлён: ${order.erp_updated_at || '—'}`}
                                                        positioning={{ placement: 'top' }}
                                                        openDelay={250}
                                                    >
                                                        <Flex
                                                            align="center"
                                                            gap="1"
                                                            fontSize="sm"
                                                            color="gray.600"
                                                            _dark={{ color: 'gray.400' }}
                                                            fontWeight="500"
                                                        >
                                                            <LuClock size={12} />
                                                            <Text whiteSpace="nowrap">{order.erp_created_at}</Text>
                                                        </Flex>
                                                    </Tooltip>
                                                </Flex>

                                                {/* Строка 2: контрагент */}
                                                {order.company && (
                                                    <Text
                                                        fontSize="sm"
                                                        color="gray.600"
                                                        _dark={{ color: 'gray.400' }}
                                                        mb="2"
                                                        truncate
                                                    >
                                                        {order.company.name}
                                                    </Text>
                                                )}

                                                {/* Строка 3: бейджи количеств */}
                                                <Flex gap="2" align="center" flexWrap="wrap">
                                                    <Badge
                                                        variant="outline"
                                                        colorPalette="gray"
                                                        fontSize="2xs"
                                                        px="2" py="0.5"
                                                        borderRadius="full"
                                                        gap="1"
                                                    >
                                                        <LuPackage size={11} />
                                                        {order.items_count}&nbsp;{itemsLabel}
                                                    </Badge>
                                                    {order.shipments_count > 0 && (
                                                        <Badge
                                                            variant="outline"
                                                            colorPalette="gray"
                                                            fontSize="2xs"
                                                            px="2" py="0.5"
                                                            borderRadius="full"
                                                            gap="1"
                                                        >
                                                            <LuTruck size={11} />
                                                            {order.shipments_count}&nbsp;{shipmentsLabel}
                                                        </Badge>
                                                    )}
                                                </Flex>
                                            </Box>

                                            {/* Правая часть: сумма */}
                                            <VStack
                                                gap="0"
                                                align={{ base: 'start', md: 'end' }}
                                                flexShrink="0"
                                                w={{ base: '100%', md: 'auto' }}
                                            >
                                                {hasDiscount && (
                                                    <Text
                                                        fontSize="xs"
                                                        color="gray.400"
                                                        fontFamily="mono"
                                                        textDecoration="line-through"
                                                        whiteSpace="nowrap"
                                                    >
                                                        {fmt(order.original_total_converted)}&nbsp;{currencySymbol}
                                                    </Text>
                                                )}
                                                <Text
                                                    fontWeight="700"
                                                    fontSize="lg"
                                                    fontFamily="mono"
                                                    whiteSpace="nowrap"
                                                    color={hasDiscount ? 'pecado.600' : 'gray.800'}
                                                    _dark={{ color: hasDiscount ? 'pecado.300' : 'gray.100' }}
                                                >
                                                    {fmt(order.total_converted)}&nbsp;{currencySymbol}
                                                </Text>
                                                {isForeignCurrency && (
                                                    <Text fontSize="xs" color="gray.400" whiteSpace="nowrap">
                                                        {fmt(order.total_amount)}&nbsp;{order.currency_code}
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

            {shipment.items && shipment.items.length > 0 && (() => {
                const totalSavings = shipment.items.reduce((acc, item) => {
                    const base = parseFloat(item.price_converted || 0) * item.quantity;
                    const total = parseFloat(item.total_converted || 0);
                    return base > total + 0.01 ? acc + (base - total) : acc;
                }, 0);
                const totalQty = shipment.items.reduce((s, it) => s + Number(it.quantity || 0), 0);

                return (
                <Box>
                    <Flex align="center" gap="2" flexWrap="wrap" mb="3">
                        <LuPackage size={18} />
                        <Text fontWeight="700" fontSize="md">Состав отгрузки ({shipment.items.length})</Text>
                        <Badge colorPalette="gray" variant="subtle" ml="1">
                            {totalQty} шт.
                        </Badge>
                        <Box ml="auto">
                            <Tooltip content="Скачать в Excel (XLSX)" positioning={{ placement: 'top' }} openDelay={250}>
                                <Flex
                                    as="a"
                                    href={`/cabinet/shipments/${shipment.id}/items/export`}
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
                                    aria-label="Скачать состав отгрузки в Excel"
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
                                    <Table.ColumnHeader w="120px">Заказ</Table.ColumnHeader>
                                    <Table.ColumnHeader w="80px" textAlign="center">Кол-во</Table.ColumnHeader>
                                    <Table.ColumnHeader w="130px" textAlign="right">Цена без скидки</Table.ColumnHeader>
                                    <Table.ColumnHeader w="100px" textAlign="right">Скидка</Table.ColumnHeader>
                                    <Table.ColumnHeader w="130px" textAlign="right">Цена со скидкой</Table.ColumnHeader>
                                    <Table.ColumnHeader w="130px" textAlign="right">Сумма</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {shipment.items.map((item, idx) => {
                                    const basePriceConverted = parseFloat(item.price_converted || 0);
                                    const grossConverted = basePriceConverted * item.quantity;
                                    const totalConverted = parseFloat(item.total_converted || 0);
                                    const hasDiscount = grossConverted > totalConverted + 0.01;
                                    const effectivePriceConverted = item.quantity > 0 ? totalConverted / item.quantity : 0;
                                    const savingsConverted = hasDiscount ? grossConverted - totalConverted : 0;
                                    const combinedDiscount = parseFloat(item.auto_discount_percent || 0) + parseFloat(item.manual_discount_percent || 0);
                                    return (
                                        <Table.Row key={item.id || idx} bg="bg">
                                            <Table.Cell>
                                                <HStack gap="3">
                                                    {item.product?.image_url && (
                                                        <Image
                                                            src={item.product.image_url}
                                                            alt={item.product?.name || ''}
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
                                                                    {item.product?.name}
                                                                </Text>
                                                            </Link>
                                                        ) : (
                                                            <Text fontWeight="500" fontSize="sm" color="gray.400">
                                                                {item.product?.name || 'Товар не найден'}
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
                                            <Table.Cell>
                                                {item.order_id ? (
                                                    <Link href={`/cabinet/orders/${item.order_id}`}>
                                                        <Text color="pecado.600" _hover={{ textDecoration: 'underline' }} fontSize="xs" fontWeight="500" fontFamily="mono">
                                                            {item.order_number}
                                                        </Text>
                                                    </Link>
                                                ) : <Text color="gray.300" fontSize="xs">—</Text>}
                                            </Table.Cell>
                                            <Table.Cell textAlign="center">{item.quantity}</Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <VStack gap="0" align="end">
                                                    <Text fontSize="sm">{fmt(basePriceConverted)}</Text>
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
                                                    <Text fontSize="sm" color="gray.300">—</Text>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <VStack gap="0" align="end">
                                                    <Text fontSize="sm" fontWeight="500">{fmt(effectivePriceConverted)}</Text>
                                                    {shipment.currency_code !== currency?.code && (
                                                        <Text fontSize="xs" color="gray.400">
                                                            {fmt(item.quantity > 0 ? parseFloat(item.total || 0) / item.quantity : 0)} {shipment.currency_code}
                                                        </Text>
                                                    )}
                                                </VStack>
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <VStack gap="0" align="end">
                                                    <Text fontWeight="600">{fmt(totalConverted)}</Text>
                                                    {shipment.currency_code !== currency?.code && (
                                                        <Text fontSize="xs" color="gray.400">{fmt(item.total)} {shipment.currency_code}</Text>
                                                    )}
                                                </VStack>
                                            </Table.Cell>
                                        </Table.Row>
                                    );
                                })}
                            </Table.Body>
                            <Table.Footer>
                                <Table.Row bg="bg.subtle">
                                    <Table.Cell colSpan={7} p="4">
                                        <Flex justify="space-between" align="center" gap="3" flexWrap="wrap">
                                            <Flex align="center" gap="2">
                                                <LuShoppingBag size={20} />
                                                <Text fontWeight="700" fontSize="lg">Итого</Text>
                                            </Flex>
                                            <VStack gap="0" align="end">
                                                <Text fontSize="xl" fontWeight="800" whiteSpace="nowrap">
                                                    {fmt(shipment.total_converted)}&nbsp;{currencySymbol}
                                                </Text>
                                                {shipment.currency_code && shipment.currency_code !== currency?.code && (
                                                    <Text fontSize="xs" color="gray.400" whiteSpace="nowrap">
                                                        {fmt(shipment.total_amount)}&nbsp;{shipment.currency_code}
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
        </CabinetLayout>
    );
}
