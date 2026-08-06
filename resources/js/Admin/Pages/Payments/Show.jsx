import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import {
    Box, Text, Badge, Card, HStack, Table, SimpleGrid, Textarea, Button, Flex,
} from '@chakra-ui/react';
import { Field } from '@/components/ui/field';
import { toaster } from '@/components/ui/toaster';
import EntityCrmPanel from '@/Crm/Components/EntityCrmPanel';

const ALLOCATION_COLORS = {
    allocated: 'green',
    partial: 'orange',
    advance: 'blue',
};

const PAYMENT_STATUS_COLORS = {
    paid: 'green',
    partial: 'orange',
    unpaid: 'gray',
    overpaid: 'purple',
};

function InfoRow({ label, value }) {
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            <Text fontSize="sm" fontWeight="500">{value || '—'}</Text>
        </Box>
    );
}

export default function Show({ payment, organizationsEnabled }) {
    const fmt = (value) =>
        parseFloat(value || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const money = (value) => `${fmt(value)}${payment.currency_code ? ` ${payment.currency_code}` : ''}`;

    // Комментарий — единственное поле платежа, которое ведёт сайт.
    const { data, setData, patch, processing, isDirty } = useForm({
        comment: payment.comment ?? '',
    });

    const saveComment = (event) => {
        event.preventDefault();
        patch(route('admin.payments.comment', payment.id), {
            preserveScroll: true,
            onSuccess: () => toaster.create({ description: 'Комментарий сохранён', type: 'success' }),
        });
    };

    return (
        <>
            <PageHeader
                title={`Платёж ${payment.number || `#${payment.id}`}`}
                description="Реквизиты ведёт 1С — на сайте они только для чтения"
                backUrl={route('admin.payments.index')}
                backLabel="К списку"
            />

            <SimpleGrid columns={{ base: 1, md: 3 }} gap={4} mb={6}>
                <Card.Root>
                    <Card.Body>
                        <Text fontSize="xs" color="gray.500" mb={1}>Сумма документа</Text>
                        <Text fontSize="xl" fontWeight="bold" fontFamily="mono">{money(payment.amount)}</Text>
                        <Badge colorPalette={payment.direction === 'out' ? 'red' : 'green'} variant="subtle" mt={2}>
                            {payment.direction_label}
                        </Badge>
                    </Card.Body>
                </Card.Root>
                <Card.Root>
                    <Card.Body>
                        <Text fontSize="xs" color="gray.500" mb={1}>Разнесено по реализациям</Text>
                        <Text fontSize="xl" fontWeight="bold" fontFamily="mono">{money(payment.allocated_amount)}</Text>
                        <Badge colorPalette={ALLOCATION_COLORS[payment.allocation_status] || 'gray'} variant="subtle" mt={2}>
                            {payment.allocation_status_label}
                        </Badge>
                    </Card.Body>
                </Card.Root>
                <Card.Root>
                    <Card.Body>
                        <Text fontSize="xs" color="gray.500" mb={1}>Нераспределённый остаток (аванс)</Text>
                        <Text fontSize="xl" fontWeight="bold" fontFamily="mono">{money(payment.unallocated_amount)}</Text>
                        <Text fontSize="xs" color="gray.500" mt={2}>
                            {payment.unallocated_amount > 0
                                ? 'Деньги не привязаны к отгрузкам'
                                : 'Платёж разнесён полностью'}
                        </Text>
                    </Card.Body>
                </Card.Root>
            </SimpleGrid>

            <Card.Root mb={6}>
                <Card.Header>
                    <Text fontWeight="semibold" fontSize="lg">Реквизиты документа</Text>
                </Card.Header>
                <Card.Body>
                    <SimpleGrid columns={{ base: 2, md: 4 }} gap={6}>
                        <InfoRow label="Номер в 1С" value={payment.number} />
                        <InfoRow label="Дата документа" value={payment.date} />
                        <InfoRow label="Тип документа" value={payment.document_type} />
                        <InfoRow label="Операция" value={payment.operation_name} />
                        <InfoRow label="Номер по банку" value={payment.bank_number} />
                        <InfoRow label="Дата по банку" value={payment.bank_date} />
                        <Box>
                            <Text fontSize="xs" color="gray.500" mb="0.5">Проведено банком</Text>
                            {payment.bank_confirmed ? (
                                <Badge colorPalette="green" variant="subtle">
                                    Да{payment.bank_confirmed_at ? ` · ${payment.bank_confirmed_at}` : ''}
                                </Badge>
                            ) : (
                                <Badge colorPalette="gray" variant="subtle">Нет</Badge>
                            )}
                        </Box>
                        <InfoRow label="УИП" value={payment.uip} />
                        <InfoRow label="Счёт организации" value={payment.organization_account} />
                        <InfoRow label="Банк организации" value={payment.organization_bank_name} />
                        <InfoRow label="Счёт плательщика" value={payment.payer_account} />
                        <InfoRow label="Банк плательщика" value={payment.payer_bank_name} />
                        <InfoRow label="ИНН плательщика" value={payment.tax_id} />
                        <InfoRow label="UUID документа" value={payment.uuid} />
                        <InfoRow label="Создан в 1С" value={payment.erp_created_at} />
                        <InfoRow label="Изменён в 1С" value={payment.erp_updated_at} />
                    </SimpleGrid>

                    {payment.purpose && (
                        <Box mt={6}>
                            <Text fontSize="xs" color="gray.500" mb="0.5">Назначение платежа</Text>
                            <Text fontSize="sm">{payment.purpose}</Text>
                        </Box>
                    )}
                </Card.Body>
            </Card.Root>

            <Card.Root mb={6}>
                <Card.Header>
                    <Text fontWeight="semibold" fontSize="lg">Стороны</Text>
                </Card.Header>
                <Card.Body>
                    <SimpleGrid columns={{ base: 1, md: 3 }} gap={6}>
                        <Box>
                            <Text fontSize="xs" color="gray.500" mb="0.5">Плательщик (контрагент)</Text>
                            {payment.company ? (
                                <Link href={route('admin.companies.edit', payment.company.id)}>
                                    <Text fontSize="sm" color="blue.600" _hover={{ textDecoration: 'underline' }}>
                                        {payment.company.name}
                                    </Text>
                                </Link>
                            ) : (
                                <Box>
                                    <Text fontSize="sm" color="gray.500">Контрагент ещё не заведён на сайте</Text>
                                    <Text fontSize="xs" color="gray.400" fontFamily="mono">{payment.contractor_uuid}</Text>
                                </Box>
                            )}
                        </Box>
                        <Box>
                            <Text fontSize="xs" color="gray.500" mb="0.5">Партнёр</Text>
                            {payment.user ? (
                                <Link href={route('admin.users.edit', payment.user.id)}>
                                    <Text fontSize="sm" color="blue.600" _hover={{ textDecoration: 'underline' }}>
                                        {payment.user.name}
                                    </Text>
                                </Link>
                            ) : (
                                <Text fontSize="sm" color="gray.500">Не определён — клиент не увидит платёж в кабинете</Text>
                            )}
                        </Box>
                        {organizationsEnabled && (
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Организация-получатель</Text>
                                {payment.organization ? (
                                    <HStack gap={1}>
                                        <Text fontSize="sm">{payment.organization.name}</Text>
                                        {payment.organization.is_stub && (
                                            <Badge colorPalette="orange" variant="subtle" size="sm">не заведена</Badge>
                                        )}
                                    </HStack>
                                ) : <Text fontSize="sm" color="gray.500">—</Text>}
                            </Box>
                        )}
                    </SimpleGrid>
                </Card.Body>
            </Card.Root>

            <Card.Root mb={6}>
                <Card.Header>
                    <Text fontWeight="semibold" fontSize="lg">Расшифровка платежа</Text>
                </Card.Header>
                <Card.Body>
                    {payment.allocations?.length ? (
                        <Box overflowX="auto">
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>№</Table.ColumnHeader>
                                        <Table.ColumnHeader>Реализация</Table.ColumnHeader>
                                        <Table.ColumnHeader>Дата</Table.ColumnHeader>
                                        <Table.ColumnHeader>Заказ</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="end">Сумма реализации</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="end">Разнесено</Table.ColumnHeader>
                                        <Table.ColumnHeader>Оплата реализации</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {payment.allocations.map((allocation) => (
                                        <Table.Row key={allocation.id}>
                                            <Table.Cell>{allocation.line_number ?? '—'}</Table.Cell>
                                            <Table.Cell>
                                                {allocation.shipment ? (
                                                    <Link href={route('admin.shipments.show', allocation.shipment.id)}>
                                                        <Text fontSize="sm" color="blue.600" fontFamily="mono" _hover={{ textDecoration: 'underline' }}>
                                                            {allocation.shipment.number || `#${allocation.shipment.id}`}
                                                        </Text>
                                                    </Link>
                                                ) : (
                                                    <Box>
                                                        <Badge colorPalette="orange" variant="subtle" size="sm">
                                                            Реализация ещё не пришла из 1С
                                                        </Badge>
                                                        <Text fontSize="xs" color="gray.400" fontFamily="mono">
                                                            {allocation.shipment_uuid}
                                                        </Text>
                                                    </Box>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm">{allocation.shipment?.date || '—'}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm" fontFamily="mono">{allocation.order_number || '—'}</Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="end" fontFamily="mono">
                                                {allocation.shipment ? fmt(allocation.shipment.total_amount) : '—'}
                                            </Table.Cell>
                                            <Table.Cell textAlign="end" fontFamily="mono" fontWeight="medium">
                                                {fmt(allocation.amount)}
                                            </Table.Cell>
                                            <Table.Cell>
                                                {allocation.shipment ? (
                                                    <Badge
                                                        colorPalette={PAYMENT_STATUS_COLORS[allocation.shipment.payment_status] || 'gray'}
                                                        variant="subtle"
                                                    >
                                                        {allocation.shipment.payment_status_label}
                                                    </Badge>
                                                ) : '—'}
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                    ) : (
                        <Text fontSize="sm" color="gray.500">
                            Платёж не разнесён по реализациям — вся сумма числится авансом.
                        </Text>
                    )}
                </Card.Body>
            </Card.Root>

            <Card.Root mb={6}>
                <Card.Header>
                    <Text fontWeight="semibold" fontSize="lg">Комментарий</Text>
                </Card.Header>
                <Card.Body>
                    <form onSubmit={saveComment}>
                        <Field
                            label="Заметка сотрудника"
                            helperText="Локальное поле: в 1С не уходит и из 1С не перезаписывается."
                        >
                            <Textarea
                                value={data.comment}
                                onChange={(event) => setData('comment', event.target.value)}
                                rows={3}
                                placeholder="Например: платёж уточняли по телефону, разнесение подтвердил бухгалтер"
                            />
                        </Field>
                        <Flex mt={3}>
                            <Button type="submit" colorPalette="blue" size="sm" loading={processing} disabled={!isDirty}>
                                Сохранить
                            </Button>
                        </Flex>
                    </form>
                </Card.Body>
            </Card.Root>

            <EntityCrmPanel entityType="payment" entityId={payment.id} />
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
