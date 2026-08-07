import { Link } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import {
    Box, Text, Badge, Card, HStack, VStack, Table, Separator, SimpleGrid,
} from '@chakra-ui/react';
import { RelatedOrdersSection } from './Components/RelatedOrdersSection';
import EntityCrmPanel from '@/Crm/Components/EntityCrmPanel';
import PaymentScheduleBlock from '@/components/payments/PaymentScheduleBlock';

const CURRENCY_SYMBOLS = { RUB: '₽', KZT: '₸', BYN: 'Br' };

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

export default function Show({ shipment, related_orders, organizationsEnabled }) {
    const fmt = (v) =>
        parseFloat(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // Подсчёт суммы до всех скидок
    const totalBeforeDiscounts = shipment.items?.reduce((sum, item) => {
        return sum + parseFloat(item.price || 0) * parseInt(item.quantity || 0);
    }, 0) || 0;

    const totalAfterDiscounts = parseFloat(shipment.total_amount || 0);
    const totalDiscount = totalBeforeDiscounts - totalAfterDiscounts;

    return (
        <>
            <PageHeader
                title={`Реализация ${shipment.number || ("#" + shipment.id)}`}
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
                            <Text fontSize="xs" color="gray.500" mb={1}>Номер</Text>
                            <Text fontFamily="mono" fontSize="sm">{shipment.number || ("#" + shipment.id)}</Text>
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
                        <InfoRow label="Создано в 1С" value={shipment.erp_created_at || '—'} />
                        <InfoRow label="Изменено в 1С" value={shipment.erp_updated_at || '—'} />
                    </SimpleGrid>

                    <Separator my={4} />

                    <SimpleGrid columns={{ base: 1, md: 3 }} gap={6}>
                        <InfoRow label="ИНН контрагента" value={shipment.tax_id} />
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

                    {/* Организация и склад пришли из 1С: чьей накладной уехал товар
                        и откуда. Пустых строк не рисуем — у исторических реализаций
                        этих полей нет, и «—» на каждой карточке только шумел бы. */}
                    {organizationsEnabled && (shipment.organization || shipment.warehouse) && (
                        <>
                            <Separator my={4} />

                            <SimpleGrid columns={{ base: 1, md: 3 }} gap={6}>
                                {shipment.organization && (
                                    <Box>
                                        <Text fontSize="xs" color="gray.500" mb={1}>Организация</Text>
                                        <HStack gap={2}>
                                            <Text fontSize="sm">{shipment.organization.name}</Text>
                                            {shipment.organization.is_stub && (
                                                <Badge colorPalette="orange" variant="subtle" size="sm">
                                                    не заведена
                                                </Badge>
                                            )}
                                        </HStack>
                                    </Box>
                                )}
                                {shipment.warehouse && (
                                    <InfoRow label="Склад отгрузки" value={shipment.warehouse.name} />
                                )}
                            </SimpleGrid>
                        </>
                    )}
                </Card.Body>
            </Card.Root>

            {/* График оплаты из 1С — read-only, мастер документа остаётся 1С. */}
            {shipment.payment_schedule && (
                <Box mb={6}>
                    <PaymentScheduleBlock
                        schedule={shipment.payment_schedule}
                        currencySymbol={CURRENCY_SYMBOLS[shipment.currency_code] || shipment.currency_code || '₽'}
                    />
                </Box>
            )}

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
                                    <Table.ColumnHeader>Заказ</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Кол-во</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Баз. цена</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Авто-скидка</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Ручн. скидка</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Общ. скидка</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Ставка НДС</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Итог</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {shipment.items && shipment.items.length > 0 ? (
                                    shipment.items.map((item, index) => {
                                        const autoDiscount = parseFloat(item.auto_discount_percent) || 0;
                                        const manualDiscount = parseFloat(item.manual_discount_percent) || 0;
                                        const totalDiscountPercent = autoDiscount + manualDiscount - (autoDiscount * manualDiscount / 100);

                                        return (
                                            <Table.Row key={item.id || index}>
                                                <Table.Cell>
                                                    {item.product ? (
                                                        <Link href={route('admin.products.edit', item.product.id)}>
                                                            <VStack align="start" gap={0}>
                                                                <Text color="blue.600" _hover={{ textDecoration: 'underline' }} fontSize="sm">
                                                                    {item.product.name}
                                                                </Text>
                                                                <Text fontSize="xs" color="gray.500">
                                                                    SKU: {item.product.sku || '—'}
                                                                </Text>
                                                            </VStack>
                                                        </Link>
                                                    ) : (
                                                        <Text color="gray.500" fontSize="sm">Товар не найден</Text>
                                                    )}
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Text fontFamily="mono" fontSize="xs" color="blue.600">
                                                        {item.order_number || '—'}
                                                    </Text>
                                                </Table.Cell>
                                                <Table.Cell textAlign="right">
                                                    <Text fontFamily="mono">{item.quantity}</Text>
                                                </Table.Cell>
                                                <Table.Cell textAlign="right">
                                                    <Text fontFamily="mono">{fmt(item.price)}</Text>
                                                </Table.Cell>
                                                <Table.Cell textAlign="right">
                                                    {autoDiscount > 0 ? (
                                                        <Badge colorPalette="green" variant="subtle" size="sm">
                                                            −{autoDiscount.toFixed(2)}%
                                                        </Badge>
                                                    ) : (
                                                        <Text fontFamily="mono" color="gray.400">0%</Text>
                                                    )}
                                                </Table.Cell>
                                                <Table.Cell textAlign="right">
                                                    {manualDiscount > 0 ? (
                                                        <Badge colorPalette="orange" variant="subtle" size="sm">
                                                            −{manualDiscount.toFixed(2)}%
                                                        </Badge>
                                                    ) : (
                                                        <Text fontFamily="mono" color="gray.400">0%</Text>
                                                    )}
                                                </Table.Cell>
                                                <Table.Cell textAlign="right">
                                                    {totalDiscountPercent > 0 ? (
                                                        <Badge colorPalette="blue" variant="subtle" size="sm">
                                                            −{totalDiscountPercent.toFixed(2)}%
                                                        </Badge>
                                                    ) : (
                                                        <Text fontFamily="mono" color="gray.400">0%</Text>
                                                    )}
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
                                        );
                                    })
                                ) : (
                                    <Table.Row>
                                        <Table.Cell colSpan={9}>
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
                {shipment.items && shipment.items.length > 0 && (
                    <Card.Footer>
                        <VStack align="stretch" width="100%" gap={1}>
                            <HStack justify="space-between">
                                <Text color="fg.muted">Сумма до скидок:</Text>
                                <Text fontFamily="mono">{fmt(totalBeforeDiscounts)} {shipment.currency_code}</Text>
                            </HStack>
                            {totalDiscount > 0 && (
                                <HStack justify="space-between">
                                    <Text color="green.600">Общая скидка:</Text>
                                    <Text fontFamily="mono" color="green.600">−{fmt(totalDiscount)} {shipment.currency_code}</Text>
                                </HStack>
                            )}
                            <HStack justify="space-between">
                                <Text fontWeight="bold" fontSize="lg">Итого:</Text>
                                <Text fontFamily="mono" fontWeight="bold" fontSize="lg">
                                    {fmt(shipment.total_amount)} {shipment.currency_code}
                                </Text>
                            </HStack>
                        </VStack>
                    </Card.Footer>
                )}
            </Card.Root>

            {/* Связанные заказы */}
            {related_orders?.length > 0 && (
                <RelatedOrdersSection orders={related_orders} />
            )}

            <EntityCrmPanel entityType="shipment" entityId={shipment.id} />
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
