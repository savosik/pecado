import { Box, Flex, HStack, VStack, Text, Badge, Card, Table, Separator, SimpleGrid } from '@chakra-ui/react';
import { Head, Link, usePage } from '@inertiajs/react';
import { LuArrowLeft, LuPackage, LuShoppingBag, LuTriangleAlert } from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';

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
        <CabinetLayout title={`Отгрузка #${shipment.id}`}>
            <Head title={`Отгрузка #${shipment.id} — Pecado`} />

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
            <Card.Root mb={6} borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
                <Card.Header>
                    <Flex justify="space-between" align="center" flexWrap="wrap" gap="3">
                        <VStack align="start" gap="1">
                            <Text fontWeight="700" fontSize="xl">Отгрузка #{shipment.id}</Text>
                            <Text fontFamily="mono" fontSize="xs" color="gray.400">{shipment.uuid}</Text>
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
                        <InfoBlock label="ИНН контрагента" value={shipment.contractor_inn} mono />
                        <InfoBlock label="Компания" value={shipment.company?.name} />
                        <InfoBlock label="Валюта (1С)" value={shipment.currency_code} />
                    </SimpleGrid>

                    <Separator my={4} />

                    {/* Итоговая сумма */}
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
                        </Box>
                        {shipment.currency_code && shipment.currency_code !== currency?.code && (
                            <Text fontSize="sm" color="gray.400" pb="1">
                                ({fmt(shipment.total_amount)} {shipment.currency_code})
                            </Text>
                        )}
                    </Flex>
                </Card.Body>
            </Card.Root>

            {/* Связанные заказы */}
            {related_orders && related_orders.length > 0 && (
                <Card.Root mb={6} borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
                    <Card.Header>
                        <HStack gap="2">
                            <LuShoppingBag size={18} />
                            <Text fontWeight="600" fontSize="lg">Заказы по этой отгрузке ({related_orders.length})</Text>
                        </HStack>
                    </Card.Header>
                    <Card.Body p={0}>
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row bg="gray.50" _dark={{ bg: 'gray.800' }}>
                                    <Table.ColumnHeader>Номер заказа</Table.ColumnHeader>
                                    <Table.ColumnHeader>UUID</Table.ColumnHeader>
                                    <Table.ColumnHeader>Статус</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {related_orders.map((order) => (
                                    <Table.Row key={order.id} _hover={{ bg: 'gray.50', _dark: { bg: 'gray.800' } }}>
                                        <Table.Cell>
                                            <Link href={`/cabinet/orders/${order.id}`}>
                                                <Text color="pecado.600" _hover={{ textDecoration: 'underline' }} fontWeight="600">
                                                    {order.number || `#${order.id}`}
                                                </Text>
                                            </Link>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text fontFamily="mono" fontSize="xs" color="gray.400">
                                                {order.uuid?.substring(0, 12)}…
                                            </Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Badge variant="subtle" fontSize="xs">{order.status}</Badge>
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Card.Body>
                </Card.Root>
            )}

            {/* Просрочка по этой реализации */}
            {overdue_detail && (
                <Card.Root mb={6} borderRadius="xl" border="2px solid" borderColor="red.200" _dark={{ borderColor: 'red.800' }}>
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

            <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
                <Card.Header>
                    <HStack gap="2">
                        <LuPackage size={18} />
                        <Text fontWeight="600" fontSize="lg">Состав отгрузки ({shipment.items?.length || 0})</Text>
                    </HStack>
                </Card.Header>
                <Card.Body p={0}>
                    {/* Desktop */}
                    <Box overflowX="auto" display={{ base: 'none', md: 'block' }}>
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row bg="gray.50" _dark={{ bg: 'gray.800' }}>
                                    <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                    <Table.ColumnHeader>Заказ</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Кол-во</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Цена ({currencySymbol})</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Авт. скидка</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Руч. скидка</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">НДС</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Итог ({currencySymbol})</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {shipment.items?.map((item, idx) => (
                                    <Table.Row key={item.id || idx} _hover={{ bg: 'gray.50', _dark: { bg: 'gray.800' } }}>
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
                                            {item.order_uuid ? (
                                                <Text fontFamily="mono" fontSize="xs" color="blue.500">
                                                    {item.order_uuid.substring(0, 8)}…
                                                </Text>
                                            ) : <Text color="gray.300" fontSize="xs">—</Text>}
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">
                                            <Text fontFamily="mono">{item.quantity}</Text>
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">
                                            <VStack gap="0" align="end">
                                                <Text fontFamily="mono" fontSize="sm">{fmt(item.price_converted)}</Text>
                                                {shipment.currency_code !== currency?.code && (
                                                    <Text fontSize="xs" color="gray.400">{fmt(item.price)} {shipment.currency_code}</Text>
                                                )}
                                            </VStack>
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">
                                            <Text
                                                fontFamily="mono" fontSize="sm"
                                                color={parseFloat(item.auto_discount_percent) > 0 ? 'green.500' : 'gray.300'}
                                            >
                                                {parseFloat(item.auto_discount_percent).toFixed(2)}%
                                            </Text>
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">
                                            <Text
                                                fontFamily="mono" fontSize="sm"
                                                color={parseFloat(item.manual_discount_percent) > 0 ? 'orange.500' : 'gray.300'}
                                            >
                                                {parseFloat(item.manual_discount_percent).toFixed(2)}%
                                            </Text>
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">
                                            <Text fontFamily="mono" fontSize="sm" color="gray.500">
                                                {item.vat_rate != null ? `${item.vat_rate}%` : '—'}
                                            </Text>
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">
                                            <VStack gap="0" align="end">
                                                <Text fontFamily="mono" fontWeight="600">{fmt(item.total_converted)}</Text>
                                                {shipment.currency_code !== currency?.code && (
                                                    <Text fontSize="xs" color="gray.400">{fmt(item.total)} {shipment.currency_code}</Text>
                                                )}
                                            </VStack>
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Box>

                    {/* Mobile cards */}
                    <VStack gap="3" p="4" display={{ base: 'flex', md: 'none' }}>
                        {shipment.items?.map((item, idx) => (
                            <Box
                                key={item.id || idx}
                                w="100%" p="4" borderRadius="lg" border="1px solid"
                                borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}
                            >
                                <Text fontWeight="600" mb="2">
                                    {item.product?.name || 'Товар не найден'}
                                </Text>
                                <SimpleGrid columns={2} gap={3}>
                                    <InfoBlock label="Количество" value={item.quantity} />
                                    <InfoBlock label="Цена" value={`${fmt(item.price_converted)} ${currencySymbol}`} mono />
                                    <InfoBlock
                                        label="Авт. скидка"
                                        value={`${parseFloat(item.auto_discount_percent).toFixed(2)}%`}
                                    />
                                    <InfoBlock
                                        label="Руч. скидка"
                                        value={`${parseFloat(item.manual_discount_percent).toFixed(2)}%`}
                                    />
                                    <InfoBlock
                                        label="НДС"
                                        value={item.vat_rate != null ? `${item.vat_rate}%` : '—'}
                                    />
                                    <Box>
                                        <Text fontSize="xs" color="gray.500" mb="1">Итог</Text>
                                        <Text fontWeight="700" fontFamily="mono">
                                            {fmt(item.total_converted)} {currencySymbol}
                                        </Text>
                                    </Box>
                                </SimpleGrid>
                            </Box>
                        ))}
                        {(!shipment.items || shipment.items.length === 0) && (
                            <Text color="gray.400" textAlign="center" py="4">Позиции отсутствуют</Text>
                        )}
                    </VStack>
                </Card.Body>
            </Card.Root>
        </CabinetLayout>
    );
}
