import { Head, Link, usePage } from '@inertiajs/react';
import {
    Badge,
    Box,
    Card,
    HStack,
    SimpleGrid,
    Table,
    Text,
    VStack,
} from '@chakra-ui/react';
import { LuArrowLeft, LuBox, LuTimer, LuTriangleAlert } from 'react-icons/lu';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';

/** Реквизит шапки: подпись сверху, значение снизу. Пустые не показываем вовсе. */
function Field({ label, value }) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    return (
        <Box>
            <Text fontSize="xs" color="fg.muted">{label}</Text>
            <Text fontSize="sm">{value}</Text>
        </Box>
    );
}

/**
 * Позиции одного заказа-распоряжения.
 *
 * Группировка по заказам не косметика: ордер собирают именно по распоряжениям,
 * и плоский список заставлял бы кладовщика сортировать строки глазами.
 *
 * `showCell` приходит снаружи, а не считается по своим строкам: колонка ячейки нужна
 * одинаковая во всех группах ордера, иначе таблицы разъезжаются по ширине.
 */
function OrderGroup({ group, showCell }) {
    return (
        <Card.Root>
            <Card.Body>
                <VStack align="stretch" gap={3}>
                    <HStack justify="space-between" flexWrap="wrap" gap={2}>
                        <HStack gap={2} flexWrap="wrap">
                            <Text fontSize="sm" fontWeight="bold">
                                {group.order_number
                                    ? `Заказ ${group.order_number}`
                                    : 'Без распоряжения'}
                            </Text>
                            {group.order_date_label && (
                                <Text fontSize="xs" color="fg.muted">от {group.order_date_label}</Text>
                            )}
                        </HStack>
                        {group.order_number && !group.is_known_order && (
                            <Badge colorPalette="gray" size="sm">Заказа нет на сайте</Badge>
                        )}
                    </HStack>

                    <Box overflowX="auto">
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader w="40px">N</Table.ColumnHeader>
                                    <Table.ColumnHeader>Номенклатура</Table.ColumnHeader>
                                    <Table.ColumnHeader>Артикул</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="end">Кол-во</Table.ColumnHeader>
                                    <Table.ColumnHeader>Ед. изм.</Table.ColumnHeader>
                                    {showCell && <Table.ColumnHeader>Ячейка</Table.ColumnHeader>}
                                    <Table.ColumnHeader textAlign="end">Место</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {group.items.map((item) => (
                                    <Table.Row key={item.id}>
                                        <Table.Cell fontSize="sm" color="fg.muted">{item.line_number}</Table.Cell>
                                        <Table.Cell fontSize="sm">
                                            <HStack gap={1} align="start">
                                                <Text lineClamp={2}>{item.product_name}</Text>
                                                {item.is_unresolved && (
                                                    <Box
                                                        color="orange.500"
                                                        title="Товара нет в каталоге сайта — наименование показано из 1С"
                                                        display="flex"
                                                        flexShrink={0}
                                                        mt={1}
                                                    >
                                                        <LuTriangleAlert size={12} />
                                                    </Box>
                                                )}
                                            </HStack>
                                        </Table.Cell>
                                        <Table.Cell fontSize="sm" color="fg.muted">{item.product_sku || '—'}</Table.Cell>
                                        <Table.Cell textAlign="end" fontSize="sm" fontVariantNumeric="tabular-nums">
                                            {item.quantity}
                                        </Table.Cell>
                                        <Table.Cell fontSize="sm" color="fg.muted">{item.unit || '—'}</Table.Cell>
                                        {showCell && (
                                            <Table.Cell fontSize="sm" whiteSpace="nowrap">
                                                {item.cell || '—'}
                                            </Table.Cell>
                                        )}
                                        <Table.Cell textAlign="end" fontSize="sm" color="fg.muted">
                                            {item.package_number ?? '—'}
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Box>
                </VStack>
            </Card.Body>
        </Card.Root>
    );
}

export default function GoodsIssueShow() {
    const { order } = usePage().props;

    // Колонку ячейки показываем, только если 1С их прислала: на складах без адресного
    // хранения она была бы столбцом прочерков.
    const showCell = order.groups.some((group) => group.items.some((item) => item.cell));

    return (
        <>
            <Head title={`Ордер ${order.number} — Склад`} />

            <PageHeader
                title={`Расходный ордер ${order.number}`}
                description={order.operation || 'Складской документ отгрузки из 1С'}
                actions={(
                    <Button asChild size="sm" variant="outline">
                        <Link href="/wms/goods-issues">
                            <LuArrowLeft /> К списку
                        </Link>
                    </Button>
                )}
            />

            <VStack gap={4} align="stretch">
                <Card.Root>
                    <Card.Body>
                        <VStack align="stretch" gap={4}>
                            <HStack gap={2} flexWrap="wrap">
                                <Badge colorPalette={order.status_color}>{order.status_label}</Badge>
                                {order.status_changed_label && (
                                    <Text fontSize="xs" color="fg.muted">
                                        с {order.status_changed_label}
                                    </Text>
                                )}
                                {order.is_stale && (
                                    <HStack gap={1} color="orange.500">
                                        <LuTimer size={14} />
                                        <Text fontSize="xs">Висит в статусе дольше суток</Text>
                                    </HStack>
                                )}
                                {order.unresolved_items_count > 0 && (
                                    <HStack gap={1} color="orange.500">
                                        <LuTriangleAlert size={14} />
                                        <Text fontSize="xs">
                                            {order.unresolved_items_count} позиций нет в каталоге сайта
                                        </Text>
                                    </HStack>
                                )}
                            </HStack>

                            <SimpleGrid columns={{ base: 2, md: 4 }} gap={4}>
                                <Field label="Дата документа" value={order.date_label} />
                                <Field label="Дата отгрузки" value={order.shipment_date_label} />
                                <Field label="Получатель" value={order.recipient} />
                                <Field label="ИНН" value={order.tax_id} />
                                <Field label="Склад" value={order.warehouse} />
                                <Field label="Организация" value={order.organization} />
                                <Field label="Ответственный" value={order.responsible} />
                                <Field label="Приоритет" value={order.priority_label} />
                                <Field label="Способ доставки" value={order.delivery_type_label} />
                                <Field label="Адрес доставки" value={order.delivery_address} />
                                <Field label="Порядок доставки" value={order.delivery_order} />
                                <Field label="Позиций" value={order.items_count} />
                                <Field label="Количество" value={order.total_quantity} />
                                <Field label="Мест" value={order.packages_count} />
                                <Field label="Создан в 1С" value={order.erp_created_label} />
                                <Field label="Изменён в 1С" value={order.erp_updated_label} />
                            </SimpleGrid>

                            {order.comment && (
                                <Box borderWidth="1px" borderColor="border" borderRadius="md" p={3}>
                                    <Text fontSize="xs" color="fg.muted">Комментарий</Text>
                                    <Text fontSize="sm">{order.comment}</Text>
                                </Box>
                            )}
                        </VStack>
                    </Card.Body>
                </Card.Root>

                {order.groups.map((group, index) => (
                    <OrderGroup
                        key={group.order_uuid || `no-order-${index}`}
                        group={group}
                        showCell={showCell}
                    />
                ))}

                <SimpleGrid columns={{ base: 1, lg: 2 }} gap={4}>
                    <Card.Root>
                        <Card.Body>
                            <VStack align="stretch" gap={3}>
                                <HStack gap={2}>
                                    <LuBox size={16} />
                                    <Text fontSize="sm" fontWeight="bold">
                                        Упаковочные листы ({order.packages_count})
                                    </Text>
                                </HStack>

                                {order.packages.length === 0 ? (
                                    <Text fontSize="sm" color="fg.muted">
                                        1С не прислала упаковочные листы по этому ордеру.
                                    </Text>
                                ) : (
                                    <Table.Root size="sm">
                                        <Table.Header>
                                            <Table.Row>
                                                <Table.ColumnHeader>Лист</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="end">Позиций</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="end">Вес</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="end">Объём</Table.ColumnHeader>
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {order.packages.map((pkg) => (
                                                <Table.Row key={pkg.number}>
                                                    <Table.Cell fontSize="sm">Упаковочный лист {pkg.number}</Table.Cell>
                                                    <Table.Cell textAlign="end" fontSize="sm">
                                                        {pkg.positions_count ?? '—'}
                                                    </Table.Cell>
                                                    <Table.Cell textAlign="end" fontSize="sm" color="fg.muted">
                                                        {pkg.weight ?? '—'}
                                                    </Table.Cell>
                                                    <Table.Cell textAlign="end" fontSize="sm" color="fg.muted">
                                                        {pkg.volume ?? '—'}
                                                    </Table.Cell>
                                                </Table.Row>
                                            ))}
                                        </Table.Body>
                                    </Table.Root>
                                )}
                            </VStack>
                        </Card.Body>
                    </Card.Root>

                    <Card.Root>
                        <Card.Body>
                            <VStack align="stretch" gap={3}>
                                <Text fontSize="sm" fontWeight="bold">История статусов</Text>

                                {order.history.length === 0 ? (
                                    <Text fontSize="sm" color="fg.muted">Переходов пока не было.</Text>
                                ) : (
                                    <VStack align="stretch" gap={2}>
                                        {order.history.map((entry) => (
                                            <HStack key={entry.id} justify="space-between" gap={3}>
                                                <Text fontSize="sm">
                                                    {entry.from_label
                                                        ? `${entry.from_label} → ${entry.to_label}`
                                                        : entry.to_label}
                                                </Text>
                                                <Text fontSize="xs" color="fg.muted" whiteSpace="nowrap">
                                                    {entry.changed_label}
                                                </Text>
                                            </HStack>
                                        ))}
                                    </VStack>
                                )}
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                </SimpleGrid>
            </VStack>
        </>
    );
}

GoodsIssueShow.layout = (page) => <WmsLayout>{page}</WmsLayout>;
