import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import {
    Box, Text, Badge, Card, Stack, SimpleGrid, Table, HStack, VStack, Button,
} from '@chakra-ui/react';
import { Link, router } from '@inertiajs/react';
import { LuPencil } from 'react-icons/lu';

const fmt = (v) => Number(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// Подпись реализации: номер из 1С + дата документа. Часть полей может быть пустой —
// 1С не всегда присылает номер, поэтому подпись собирается из того, что есть.
const shipmentLabel = (shipment) => {
    const number = shipment.erp_number || shipment.number;
    const date = shipment.date ? new Date(shipment.date).toLocaleDateString('ru-RU') : null;

    if (number && date) return `№ ${number} от ${date}`;
    if (number) return `№ ${number}`;
    if (date) return `Реализация от ${date}`;

    return `Реализация #${shipment.id}`;
};

export default function Show({
    balance,
    organizationBalances = [],
    organizationsEnabled = false,
    canViewShipments = false,
}) {
    const hasOverdue = parseFloat(balance.overdue_debt) > 0;

    return (
        <>
            <PageHeader
                title={`Баланс контрагента: ${balance.tax_id}`}
                backUrl={route('admin.contractor-balances.index')}
                actions={
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => router.visit(route('admin.contractor-balances.edit', balance.id))}
                    >
                        <LuPencil /> Редактировать
                    </Button>
                }
            />

            <Stack gap={6}>
                {/* Основная информация */}
                <Card.Root>
                    <Card.Header pb={2}>
                        <Text fontWeight="700" fontSize="md">Основная информация</Text>
                    </Card.Header>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 1, md: 2, lg: 3 }} gap={5}>
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb={1}>Пользователь</Text>
                                {balance.user ? (
                                    <Link href={route('admin.users.edit', balance.user.id)}>
                                        <Text color="blue.600" _hover={{ textDecoration: 'underline' }} fontWeight="medium">
                                            {balance.user.name}
                                        </Text>
                                    </Link>
                                ) : <Text>—</Text>}
                            </Box>
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb={1}>Компания</Text>
                                {balance.company ? (
                                    <Link href={route('admin.companies.edit', balance.company.id)}>
                                        <Text color="blue.600" _hover={{ textDecoration: 'underline' }}>
                                            {balance.company.name}
                                        </Text>
                                    </Link>
                                ) : <Text color="gray.400" fontSize="sm">Не найдена в системе</Text>}
                            </Box>
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb={1}>ИНН контрагента</Text>
                                <Text fontFamily="mono" fontWeight="medium">{balance.tax_id}</Text>
                            </Box>
                            {balance.contractor_uuid && (
                                <Box>
                                    <Text fontSize="xs" color="gray.500" mb={1}>UUID (1С)</Text>
                                    <Text fontFamily="mono" fontSize="sm" color="gray.600">{balance.contractor_uuid}</Text>
                                </Box>
                            )}
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb={1}>Текущий баланс</Text>
                                <Text
                                    fontFamily="mono"
                                    fontWeight="700"
                                    fontSize="lg"
                                    color={parseFloat(balance.current_balance) < 0 ? 'red.600' : 'green.600'}
                                    _dark={{ color: parseFloat(balance.current_balance) < 0 ? 'red.400' : 'green.400' }}
                                >
                                    {fmt(balance.current_balance)} ₽
                                </Text>
                            </Box>
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb={1}>Просроченная задолженность</Text>
                                {hasOverdue ? (
                                    <Text fontFamily="mono" fontWeight="700" fontSize="lg" color="red.600" _dark={{ color: 'red.400' }}>
                                        {fmt(balance.overdue_debt)} ₽
                                    </Text>
                                ) : (
                                    <Text color="green.600" fontWeight="medium">Нет</Text>
                                )}
                            </Box>
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb={1}>Обновлено из 1С</Text>
                                <Text fontSize="sm">
                                    {balance.balance_erp_updated_at
                                        ? new Date(balance.balance_erp_updated_at).toLocaleString('ru-RU')
                                        : '—'}
                                </Text>
                            </Box>
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {/*
                    Разрез по нашим организациям. Показываем, только когда 1С прислала
                    детализацию: иначе блок с единственной строкой «не указана» выглядит
                    как поломка. Суммы разных юрлиц не складываем — взаимозачёт делает 1С.
                */}
                {organizationsEnabled && organizationBalances.length > 0 && (
                    <Card.Root>
                        <Card.Header pb={2}>
                            <HStack justify="space-between">
                                <Text fontWeight="700" fontSize="md">Задолженность по нашим организациям</Text>
                                <Badge colorPalette="gray" variant="subtle">{organizationBalances.length}</Badge>
                            </HStack>
                        </Card.Header>
                        <Card.Body>
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Организация</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="end">Баланс</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="end">Просрочено</Table.ColumnHeader>
                                        <Table.ColumnHeader>Актуально на</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {organizationBalances.map((row) => (
                                        <Table.Row key={row.id}>
                                            <Table.Cell>
                                                <HStack gap={2}>
                                                    <Text fontSize="sm">{row.organization_name || '—'}</Text>
                                                    {row.is_stub && (
                                                        <Badge colorPalette="orange" variant="subtle" size="sm">не заведена</Badge>
                                                    )}
                                                </HStack>
                                            </Table.Cell>
                                            <Table.Cell textAlign="end">
                                                <Text
                                                    fontSize="sm"
                                                    fontFamily="mono"
                                                    color={parseFloat(row.current_balance) < 0 ? 'red.600' : undefined}
                                                >
                                                    {fmt(row.current_balance)} ₽
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="end">
                                                <Text fontSize="sm" fontFamily="mono" color={parseFloat(row.overdue_debt) > 0 ? 'red.600' : 'gray.400'}>
                                                    {fmt(row.overdue_debt)} ₽
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="xs" color="gray.500">{row.balance_erp_updated_at || '—'}</Text>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Card.Body>
                    </Card.Root>
                )}

                {/* Детализация просрочки */}
                <Card.Root>
                    <Card.Header pb={2}>
                        <HStack justify="space-between">
                            <Text fontWeight="700" fontSize="md">Детализация просрочки по реализациям</Text>
                            {balance.overdue_details?.length > 0 && (
                                <Badge colorPalette="red" variant="subtle">{balance.overdue_details.length} реализ.</Badge>
                            )}
                        </HStack>
                    </Card.Header>
                    <Card.Body>
                        {!balance.overdue_details || balance.overdue_details.length === 0 ? (
                            <Text color="gray.400" fontSize="sm" py={4} textAlign="center">
                                Просроченных реализаций нет
                            </Text>
                        ) : (
                            <Box overflowX="auto">
                                <Table.Root size="sm">
                                    <Table.Header>
                                        <Table.Row bg="bg.subtle">
                                            <Table.ColumnHeader>Реализация</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">Сумма просрочки (₽)</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">Дата оплаты</Table.ColumnHeader>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {balance.overdue_details.map((detail) => (
                                            <Table.Row key={detail.id}>
                                                <Table.Cell>
                                                    {detail.shipment ? (
                                                        <VStack align="start" gap={0}>
                                                            {canViewShipments ? (
                                                                <Link href={route('admin.shipments.show', detail.shipment.id)}>
                                                                    <Text
                                                                        fontSize="sm"
                                                                        fontWeight="medium"
                                                                        color="blue.600"
                                                                        _dark={{ color: 'blue.300' }}
                                                                        _hover={{ textDecoration: 'underline' }}
                                                                    >
                                                                        {shipmentLabel(detail.shipment)}
                                                                    </Text>
                                                                </Link>
                                                            ) : (
                                                                <Text fontSize="sm" fontWeight="medium">
                                                                    {shipmentLabel(detail.shipment)}
                                                                </Text>
                                                            )}
                                                            <Text fontFamily="mono" fontSize="2xs" color="gray.400">
                                                                {detail.shipment_uuid}
                                                            </Text>
                                                        </VStack>
                                                    ) : (
                                                        <VStack align="start" gap={0}>
                                                            <Text fontFamily="mono" fontSize="sm" color="gray.600">
                                                                {detail.shipment_uuid}
                                                            </Text>
                                                            <Text fontSize="2xs" color="gray.400">
                                                                Реализация ещё не пришла из 1С
                                                            </Text>
                                                        </VStack>
                                                    )}
                                                </Table.Cell>
                                                <Table.Cell textAlign="right">
                                                    <Text fontFamily="mono" fontWeight="medium" color="red.600" _dark={{ color: 'red.400' }}>
                                                        {fmt(detail.amount)} ₽
                                                    </Text>
                                                </Table.Cell>
                                                <Table.Cell textAlign="right">
                                                    <Text fontSize="sm">
                                                        {detail.due_date
                                                            ? new Date(detail.due_date).toLocaleDateString('ru-RU')
                                                            : '—'}
                                                    </Text>
                                                </Table.Cell>
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table.Root>
                            </Box>
                        )}
                    </Card.Body>
                </Card.Root>
            </Stack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
