import { router, Link, Head } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import {
    Box,
    Text,
    Badge,
    HStack,
    Input,
    Table,
    IconButton,
    Flex,
    SimpleGrid,
    Card,
} from '@chakra-ui/react';
import { LuRefreshCw, LuCircleCheck, LuCircleX, LuFlaskConical, LuUndo2 } from 'react-icons/lu';
import RowActions from '@/shared/Panel/RowActions';
import { useState, useCallback } from 'react';

/**
 * Оформление статусов отправки предзаказа поставщику.
 */
export const STATUS_META = {
    success: { palette: 'green', label: 'Отправлен', icon: LuCircleCheck },
    testmode: { palette: 'blue', label: 'Тестовый режим', icon: LuFlaskConical },
    rollback: { palette: 'orange', label: 'Откат (не создан)', icon: LuUndo2 },
    failed: { palette: 'red', label: 'Ошибка', icon: LuCircleX },
};

const Pagination = ({ data }) => {
    if (data.last_page <= 1) return null;

    return (
        <HStack justify="center" gap={2} mt={4}>
            {data.links.map((link, i) => {
                const label = link.label.replace('&laquo;', '«').replace('&raquo;', '»');

                if (!link.url) {
                    return (
                        <Text key={i} fontSize="sm" color="fg.muted" px={2}>
                            {label}
                        </Text>
                    );
                }

                return (
                    <Box
                        key={i}
                        as="button"
                        px={3}
                        py={1}
                        fontSize="sm"
                        borderRadius="md"
                        bg={link.active ? 'blue.500' : 'transparent'}
                        color={link.active ? 'white' : 'fg'}
                        _hover={{ bg: link.active ? 'blue.600' : 'bg.muted' }}
                        onClick={() => router.visit(link.url, { preserveScroll: true })}
                    >
                        {label}
                    </Box>
                );
            })}
        </HStack>
    );
};

const StatCard = ({ label, value, palette }) => (
    <Card.Root size="sm">
        <Card.Body>
            <Text fontSize="xs" color="fg.muted">{label}</Text>
            <Text fontSize="2xl" fontWeight="bold" color={palette ? `${palette}.500` : undefined}>
                {value}
            </Text>
        </Card.Body>
    </Card.Root>
);

export default function SupplierPreordersIndex({ requests, filters, stats, settings }) {
    const [status, setStatus] = useState(filters.status || '');
    const [search, setSearch] = useState(filters.search || '');
    const [dateFrom, setDateFrom] = useState(filters.date_from || '');
    const [dateTo, setDateTo] = useState(filters.date_to || '');

    const applyFilters = useCallback((overrides = {}) => {
        const params = {
            status: overrides.status !== undefined ? overrides.status : status,
            search: overrides.search !== undefined ? overrides.search : search,
            date_from: overrides.date_from !== undefined ? overrides.date_from : dateFrom,
            date_to: overrides.date_to !== undefined ? overrides.date_to : dateTo,
        };

        Object.keys(params).forEach((key) => {
            if (!params[key]) delete params[key];
        });

        router.get(route('admin.supplier-preorders.index'), params, {
            preserveState: true,
            preserveScroll: true,
        });
    }, [status, search, dateFrom, dateTo]);

    const handleSearchChange = useCallback((e) => {
        const value = e.target.value;
        setSearch(value);
        clearTimeout(window._supplierPreorderSearchTimeout);
        window._supplierPreorderSearchTimeout = setTimeout(() => {
            applyFilters({ search: value });
        }, 500);
    }, [applyFilters]);

    return (
        <>
            <Head title="Предзаказы поставщику" />

            <Flex justify="space-between" align="center" mb={6}>
                <PageHeader title="Предзаказы поставщику" />
                <IconButton
                    size="sm"
                    variant="outline"
                    aria-label="Обновить"
                    onClick={() => router.reload({ preserveScroll: true })}
                >
                    <LuRefreshCw />
                </IconButton>
            </Flex>

            {/* Текущий режим интеграции */}
            <HStack gap={2} mb={4} wrap="wrap">
                <Badge colorPalette={settings.enabled ? 'green' : 'gray'} variant="subtle">
                    {settings.enabled ? 'Отправка включена' : 'Отправка выключена'}
                </Badge>
                <Badge colorPalette="purple" variant="subtle">
                    Склад: {settings.stock}
                </Badge>
                {settings.testmode && (
                    <Badge colorPalette="blue" variant="subtle">
                        Тестовый режим (заказы у поставщика не создаются)
                    </Badge>
                )}
                {settings.rollback_on_warnings && (
                    <Badge colorPalette="orange" variant="subtle">
                        Откат при нехватке остатка
                    </Badge>
                )}
            </HStack>

            <SimpleGrid columns={{ base: 2, md: 5 }} gap={3} mb={6}>
                <StatCard label="Всего попыток" value={stats.total} />
                <StatCard label="Отправлено" value={stats.success} palette="green" />
                <StatCard label="Тестовых" value={stats.testmode} palette="blue" />
                <StatCard label="Откатов" value={stats.rollback} palette="orange" />
                <StatCard label="Ошибок" value={stats.failed} palette="red" />
            </SimpleGrid>

            {/* Фильтры */}
            <Flex gap={3} mb={4} wrap="wrap">
                <Input
                    placeholder="Номер заказа или id у поставщика"
                    value={search}
                    onChange={handleSearchChange}
                    size="sm"
                    maxW="280px"
                />
                <Box
                    as="select"
                    value={status}
                    onChange={(e) => {
                        setStatus(e.target.value);
                        applyFilters({ status: e.target.value });
                    }}
                    borderWidth="1px"
                    borderRadius="md"
                    px={3}
                    py={1}
                    fontSize="sm"
                    bg="transparent"
                    minW="180px"
                >
                    <option value="">Все статусы</option>
                    <option value="success">Отправлен</option>
                    <option value="testmode">Тестовый режим</option>
                    <option value="rollback">Откат (не создан)</option>
                    <option value="failed">Ошибка</option>
                </Box>
                <Input
                    type="date"
                    value={dateFrom}
                    onChange={(e) => {
                        setDateFrom(e.target.value);
                        applyFilters({ date_from: e.target.value });
                    }}
                    size="sm"
                    maxW="160px"
                />
                <Input
                    type="date"
                    value={dateTo}
                    onChange={(e) => {
                        setDateTo(e.target.value);
                        applyFilters({ date_to: e.target.value });
                    }}
                    size="sm"
                    maxW="160px"
                />
            </Flex>

            {requests.data.length === 0 ? (
                <Box textAlign="center" py={10} color="fg.muted">
                    <Text fontSize="lg" fontWeight="medium">Нет записей</Text>
                    <Text fontSize="sm" mt={2}>
                        Здесь появятся отправки предзаказов поставщику вместе с его ответами
                    </Text>
                </Box>
            ) : (
                <>
                    <Box overflowX="auto">
                        <Table.Root size="sm" striped>
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader w="60px">#</Table.ColumnHeader>
                                    <Table.ColumnHeader>Предзаказ</Table.ColumnHeader>
                                    <Table.ColumnHeader>Статус</Table.ColumnHeader>
                                    <Table.ColumnHeader>Склад</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Позиций</Table.ColumnHeader>
                                    <Table.ColumnHeader>Заказ у поставщика</Table.ColumnHeader>
                                    <Table.ColumnHeader>Ответ</Table.ColumnHeader>
                                    <Table.ColumnHeader>Дата</Table.ColumnHeader>
                                    <Table.ColumnHeader w="60px"></Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {requests.data.map((item) => {
                                    const meta = STATUS_META[item.status] ?? STATUS_META.failed;
                                    const shortage = Object.keys(item.warnings?.shortage ?? {}).length;
                                    const unknown = (item.warnings?.unknown_items ?? []).length;

                                    return (
                                        <Table.Row key={item.id}>
                                            <Table.Cell>
                                                <Text fontFamily="mono" fontSize="xs" color="fg.muted">
                                                    {item.id}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Link href={route('admin.orders.show', item.order_id)}>
                                                    <Text fontFamily="mono" fontSize="sm" color="blue.500">
                                                        {item.order_number || `#${item.order_id}`}
                                                    </Text>
                                                </Link>
                                                {item.attempt > 1 && (
                                                    <Text fontSize="xs" color="fg.muted">
                                                        попытка {item.attempt}
                                                    </Text>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge colorPalette={meta.palette} size="sm" variant="subtle">
                                                    <HStack gap={1}>
                                                        {meta.icon({ size: 12 })}
                                                        <Text>{item.status_label}</Text>
                                                    </HStack>
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm">{item.stock}</Text>
                                                {item.testmode && (
                                                    <Text fontSize="xs" color="fg.muted">тест</Text>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <Text fontFamily="mono" fontSize="sm">{item.items_count}</Text>
                                                {item.skipped_count > 0 && (
                                                    <Text fontSize="xs" color="orange.500">
                                                        без кода: {item.skipped_count}
                                                    </Text>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontFamily="mono" fontSize="sm">
                                                    {item.supplier_order_id || '—'}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                {item.error_message ? (
                                                    <Text fontSize="xs" color="red.500" truncate maxW="240px" title={item.error_message}>
                                                        {item.error_message}
                                                    </Text>
                                                ) : (shortage > 0 || unknown > 0) ? (
                                                    <Text fontSize="xs" color="orange.500">
                                                        {shortage > 0 && `нехватка: ${shortage}`}
                                                        {shortage > 0 && unknown > 0 && ', '}
                                                        {unknown > 0 && `неизв. коды: ${unknown}`}
                                                    </Text>
                                                ) : (
                                                    <Text fontSize="xs" color="fg.muted">без замечаний</Text>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm" color="fg.muted">{item.created_at}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <RowActions size="xs" view={{ href: route('admin.supplier-preorders.show', item.id) }} />
                                            </Table.Cell>
                                        </Table.Row>
                                    );
                                })}
                            </Table.Body>
                        </Table.Root>
                    </Box>
                    <Pagination data={requests} />
                </>
            )}
        </>
    );
}

SupplierPreordersIndex.layout = (page) => <AdminLayout>{page}</AdminLayout>;
