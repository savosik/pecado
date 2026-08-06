import { Box, Flex, HStack, Text, Badge, Card, Table, SimpleGrid } from '@chakra-ui/react';
import { Head, Link, usePage } from '@inertiajs/react';
import { LuArrowLeft, LuReceipt } from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';

const ALLOCATION_COLORS = {
    allocated: 'green',
    partial: 'orange',
    advance: 'blue',
};

const PAYMENT_STATUS_COLORS = {
    unpaid: 'gray',
    partial: 'orange',
    paid: 'green',
    overpaid: 'purple',
};

function InfoRow({ label, value }) {
    if (!value) return null;

    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            <Text fontSize="sm" fontWeight="500">{value}</Text>
        </Box>
    );
}

/**
 * Карточка оплаты в кабинете. Реквизиты приходят из 1С и не редактируются.
 */
export default function PaymentShow({ payment }) {
    const { currency } = usePage().props;
    const currencySymbol = currency?.symbol ?? '₽';

    const fmt = (v) => Number(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const money = (v) => `${fmt(v)} ${payment.currency_code || currencySymbol}`;

    return (
        <CabinetLayout title={`Оплата ${payment.number || `#${payment.id}`}`}>
            <Head title={`Оплата ${payment.number || payment.id} — Pecado`} />

            <Link href="/cabinet/payments">
                <HStack gap="1" color="gray.500" fontSize="sm" mb="4" _hover={{ color: 'pecado.600' }}>
                    <LuArrowLeft size={16} />
                    <Text>Все оплаты</Text>
                </HStack>
            </Link>

            <Card.Root bg="bg" mb={6} borderRadius="xl" border="1px solid" borderColor="border.muted">
                <Card.Body p={4}>
                    <Flex justify="space-between" align="start" flexWrap="wrap" gap="3" mb="4">
                        <HStack gap="3">
                            <Box color="pecado.500"><LuReceipt size={22} /></Box>
                            <Box>
                                <Text fontWeight="700" fontSize="lg" fontFamily="mono">
                                    {payment.number || `#${payment.id}`}
                                </Text>
                                <Text fontSize="sm" color="gray.500">{payment.date_label}</Text>
                            </Box>
                        </HStack>

                        <HStack gap="2" flexWrap="wrap">
                            <Badge
                                colorPalette={payment.direction === 'out' ? 'red' : 'green'}
                                variant="subtle" px="3" py="1" borderRadius="full"
                            >
                                {payment.direction_label}
                            </Badge>
                            <Badge
                                colorPalette={ALLOCATION_COLORS[payment.allocation_status] || 'gray'}
                                variant="subtle" px="3" py="1" borderRadius="full"
                            >
                                {payment.allocation_status_label}
                            </Badge>
                        </HStack>
                    </Flex>

                    <SimpleGrid columns={{ base: 1, md: 3 }} gap="4">
                        <Box>
                            <Text fontSize="xs" color="gray.500">Сумма платежа</Text>
                            <Text fontFamily="mono" fontWeight="700" fontSize="lg">{money(payment.amount)}</Text>
                            {payment.currency_code && payment.currency_code !== currency?.code && (
                                <Text fontSize="xs" color="gray.400">
                                    {fmt(payment.amount_converted)} {currencySymbol}
                                </Text>
                            )}
                        </Box>
                        <Box>
                            <Text fontSize="xs" color="gray.500">Разнесено по отгрузкам</Text>
                            <Text fontFamily="mono" fontWeight="700" fontSize="lg">{money(payment.allocated_amount)}</Text>
                        </Box>
                        <Box>
                            <Text fontSize="xs" color="gray.500">Нераспределённый остаток (аванс)</Text>
                            <Text
                                fontFamily="mono"
                                fontWeight="700"
                                fontSize="lg"
                                color={payment.unallocated_amount > 0 ? 'blue.600' : undefined}
                                _dark={payment.unallocated_amount > 0 ? { color: 'blue.400' } : undefined}
                            >
                                {money(payment.unallocated_amount)}
                            </Text>
                        </Box>
                    </SimpleGrid>
                </Card.Body>
            </Card.Root>

            <Card.Root bg="bg" mb={6} borderRadius="xl" border="1px solid" borderColor="border.muted">
                <Card.Body p={4}>
                    <Text fontWeight="700" fontSize="md" mb="3">Реквизиты платежа</Text>
                    <SimpleGrid columns={{ base: 2, md: 4 }} gap="4">
                        <InfoRow label="Тип документа" value={payment.document_type} />
                        <InfoRow label="Операция" value={payment.operation_name} />
                        <InfoRow label="Номер по банку" value={payment.bank_number} />
                        <InfoRow label="Дата по банку" value={payment.bank_date} />
                        <InfoRow label="Счёт получателя" value={payment.organization_account} />
                        <InfoRow label="Банк получателя" value={payment.organization_bank_name} />
                        <InfoRow label="Ваш счёт" value={payment.payer_account} />
                        <InfoRow label="Ваш банк" value={payment.payer_bank_name} />
                        <InfoRow label="УИП" value={payment.uip} />
                        <InfoRow label="Ваша организация" value={payment.company?.name} />
                        {payment.seller && <InfoRow label="Получатель" value={payment.seller.name} />}
                    </SimpleGrid>

                    {payment.purpose && (
                        <Box mt="4">
                            <Text fontSize="xs" color="gray.500" mb="0.5">Назначение платежа</Text>
                            <Text fontSize="sm">{payment.purpose}</Text>
                        </Box>
                    )}
                </Card.Body>
            </Card.Root>

            <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted">
                <Card.Body p={4}>
                    <Text fontWeight="700" fontSize="md" mb="3">
                        Отгрузки, закрытые этим платежом ({payment.allocations.length})
                    </Text>

                    {payment.allocations.length === 0 ? (
                        <Text fontSize="sm" color="gray.500">
                            Платёж пока не разнесён по отгрузкам — вся сумма числится авансом.
                        </Text>
                    ) : (
                        <Box overflowX="auto">
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Отгрузка</Table.ColumnHeader>
                                        <Table.ColumnHeader>Дата</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="end">Сумма отгрузки</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="end">Зачтено</Table.ColumnHeader>
                                        <Table.ColumnHeader>Оплата отгрузки</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {payment.allocations.map((allocation) => (
                                        <Table.Row key={allocation.id}>
                                            <Table.Cell>
                                                {allocation.shipment ? (
                                                    <Link href={`/cabinet/shipments/${allocation.shipment.id}`}>
                                                        <Text fontSize="sm" fontFamily="mono" color="pecado.600" textDecoration="underline">
                                                            {allocation.shipment.number}
                                                        </Text>
                                                    </Link>
                                                ) : (
                                                    <Text fontSize="sm" color="gray.500">Отгрузка ещё не загружена</Text>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm">
                                                    {allocation.shipment?.date
                                                        ? new Date(allocation.shipment.date).toLocaleDateString('ru-RU')
                                                        : '—'}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="end" fontFamily="mono">
                                                {allocation.shipment ? fmt(allocation.shipment.total_amount) : '—'}
                                            </Table.Cell>
                                            <Table.Cell textAlign="end" fontFamily="mono" fontWeight="600">
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
                    )}
                </Card.Body>
            </Card.Root>

        </CabinetLayout>
    );
}
