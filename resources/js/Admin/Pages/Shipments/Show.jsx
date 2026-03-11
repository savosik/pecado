import { Link } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import {
    Box, Text, Badge, Card, HStack, VStack, Table, Separator, SimpleGrid,
} from '@chakra-ui/react';

const STATUS_COLORS = {
    new: 'blue',
    completed: 'green',
    cancelled: 'red',
    in_progress: 'orange',
};

function InfoRow({ label, value }) {
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            <Text fontSize="sm" fontWeight="500">{value || '—'}</Text>
        </Box>
    );
}

export default function Show({ shipment, related_orders }) {
    const fmt = (v) =>
        parseFloat(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2 });

    return (
        <>
            <PageHeader
                title={`Реализация #${shipment.id}`}
                backUrl={route('admin.shipments.index')}
                backLabel="К списку"
            />

            {/* Основная информация */}
            <Card.Root mb={6}>
                <Card.Header>
                    <Text fontWeight="semibold" fontSize="lg">Информация о реализации</Text>
                </Card.Header>
                <Card.Body>
                    <SimpleGrid columns={{ base: 2, md: 4 }} gap={6}>
                        <Box>
                            <Text fontSize="xs" color="gray.500" mb={1}>UUID</Text>
                            <Text fontFamily="mono" fontSize="xs" wordBreak="break-all">{shipment.uuid}</Text>
                        </Box>
                        <Box>
                            <Text fontSize="xs" color="gray.500" mb={1}>Статус</Text>
                            <Badge colorPalette={STATUS_COLORS[shipment.status] || 'gray'}>
                                {shipment.status_label}
                            </Badge>
                        </Box>
                        <InfoRow label="Дата" value={shipment.date ? new Date(shipment.date).toLocaleDateString('ru-RU') : '—'} />
                        <InfoRow label="Валюта" value={shipment.currency_code} />
                        <Box>
                            <Text fontSize="xs" color="gray.500" mb={1}>Итоговая сумма</Text>
                            <Text fontFamily="mono" fontWeight="bold" fontSize="lg">
                                {fmt(shipment.total_amount)} {shipment.currency_code}
                            </Text>
                        </Box>
                        <InfoRow label="Создано" value={shipment.created_at} />
                    </SimpleGrid>

                    <Separator my={4} />

                    <SimpleGrid columns={{ base: 1, md: 3 }} gap={6}>
                        <InfoRow label="ИНН контрагента" value={shipment.contractor_inn} />
                        <Box>
                            <Text fontSize="xs" color="gray.500" mb={1}>Компания</Text>
                            {shipment.company ? (
                                <Link href={route('admin.companies.edit', shipment.company.id)}>
                                    <Text color="blue.600" _hover={{ textDecoration: 'underline' }} fontSize="sm">
                                        {shipment.company.name}
                                    </Text>
                                </Link>
                            ) : <Text color="gray.500" fontSize="sm">—</Text>}
                        </Box>
                        <Box>
                            <Text fontSize="xs" color="gray.500" mb={1}>Пользователь</Text>
                            {shipment.user ? (
                                <Link href={route('admin.users.edit', shipment.user.id)}>
                                    <Text color="blue.600" _hover={{ textDecoration: 'underline' }} fontSize="sm">
                                        {shipment.user.name}
                                    </Text>
                                </Link>
                            ) : <Text color="gray.500" fontSize="sm">—</Text>}
                        </Box>
                    </SimpleGrid>
                </Card.Body>
            </Card.Root>

            {/* Позиции */}
            <Card.Root mb={6}>
                <Card.Header>
                    <Text fontWeight="semibold" fontSize="lg">Позиции ({shipment.items?.length || 0})</Text>
                </Card.Header>
                <Card.Body p={0}>
                    <Box overflowX="auto">
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                    <Table.ColumnHeader>Заказ (UUID)</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Кол-во</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Цена</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Авто-скидка %</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Ручн. скидка %</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Ставка НДС</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Итог</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {shipment.items && shipment.items.length > 0 ? (
                                    shipment.items.map((item, index) => (
                                        <Table.Row key={item.id || index}>
                                            <Table.Cell>
                                                {item.product ? (
                                                    <Link href={route('admin.products.edit', item.product.id)}>
                                                        <Text color="blue.600" _hover={{ textDecoration: 'underline' }} fontSize="sm">
                                                            {item.product.name}
                                                        </Text>
                                                    </Link>
                                                ) : (
                                                    <Text color="gray.500" fontSize="sm">Товар не найден</Text>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell>
                                                {item.order_uuid ? (
                                                    <Text fontFamily="mono" fontSize="xs" color="blue.600">
                                                        {item.order_uuid.substring(0, 8)}…
                                                    </Text>
                                                ) : <Text color="gray.400" fontSize="xs">—</Text>}
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <Text fontFamily="mono">{item.quantity}</Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <Text fontFamily="mono">{fmt(item.price)}</Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <Text fontFamily="mono" color={parseFloat(item.auto_discount_percent) > 0 ? 'green.600' : 'gray.400'}>
                                                    {parseFloat(item.auto_discount_percent).toFixed(2)}%
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <Text fontFamily="mono" color={parseFloat(item.manual_discount_percent) > 0 ? 'orange.600' : 'gray.400'}>
                                                    {parseFloat(item.manual_discount_percent).toFixed(2)}%
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <Text fontFamily="mono" fontSize="sm">
                                                    {item.vat_rate != null ? `${item.vat_rate}%` : '—'}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <Text fontFamily="mono" fontWeight="medium">{fmt(item.total)}</Text>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))
                                ) : (
                                    <Table.Row>
                                        <Table.Cell colSpan={8}>
                                            <Text textAlign="center" color="gray.500" py={4}>
                                                Позиции отсутствуют
                                            </Text>
                                        </Table.Cell>
                                    </Table.Row>
                                )}
                            </Table.Body>
                        </Table.Root>
                    </Box>
                </Card.Body>
            </Card.Root>

            {/* Связанные заказы */}
            {related_orders && related_orders.length > 0 && (
                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Связанные заказы ({related_orders.length})</Text>
                    </Card.Header>
                    <Card.Body p={0}>
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>ID</Table.ColumnHeader>
                                    <Table.ColumnHeader>Номер</Table.ColumnHeader>
                                    <Table.ColumnHeader>UUID</Table.ColumnHeader>
                                    <Table.ColumnHeader>Статус</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {related_orders.map((order) => (
                                    <Table.Row key={order.id}>
                                        <Table.Cell>
                                            <Link href={route('admin.orders.show', order.id)}>
                                                <Text color="blue.600" _hover={{ textDecoration: 'underline' }} fontWeight="600">
                                                    #{order.id}
                                                </Text>
                                            </Link>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text fontFamily="mono" fontSize="sm">{order.number}</Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text fontFamily="mono" fontSize="xs" color="gray.500">
                                                {order.uuid?.substring(0, 8)}…
                                            </Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Badge>{order.status}</Badge>
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Card.Body>
                </Card.Root>
            )}
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
