import { useState } from 'react';
import {
    Box, Flex, HStack, VStack, Text, Badge, Button, Input, Table,
    Card, Stack, IconButton,
} from '@chakra-ui/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    LuFilter, LuX, LuArrowUpDown, LuArrowUp, LuArrowDown,
    LuEye, LuChevronLeft, LuChevronRight, LuSearch, LuTruck,
} from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Field } from '@/components/ui/field';
import { Select } from '@/components/ui/select';

const STATUS_COLORS = {
    new: 'blue',
    completed: 'green',
    cancelled: 'red',
    in_progress: 'orange',
};

export default function ShipmentsIndex({ filters, statuses }) {
    const { shipments, currency } = usePage().props;
    const currencySymbol = currency?.symbol ?? '₽';

    const [showFilters, setShowFilters] = useState(false);
    const [search, setSearch] = useState(filters?.search || '');
    const [localFilters, setLocalFilters] = useState({
        status: filters?.status || '',
        date_from: filters?.date_from || '',
        date_to: filters?.date_to || '',
        amount_from: filters?.amount_from || '',
        amount_to: filters?.amount_to || '',
    });

    const navigateWithParams = (params) => {
        router.get('/cabinet/shipments', { ...filters, ...params }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleSearch = (e) => {
        e.preventDefault();
        navigateWithParams({ search, page: 1 });
    };

    const handleSort = (field) => {
        const direction = filters?.sort_by === field && filters?.sort_order === 'asc' ? 'desc' : 'asc';
        navigateWithParams({ sort_by: field, sort_order: direction });
    };

    const handleApplyFilters = () => navigateWithParams({ ...localFilters, page: 1 });

    const handleResetFilters = () => {
        const reset = { status: '', date_from: '', date_to: '', amount_from: '', amount_to: '' };
        setLocalFilters(reset);
        navigateWithParams({ ...reset, search: '', page: 1 });
        setSearch('');
    };

    const handlePageChange = (page) => navigateWithParams({ page });

    const SortIcon = ({ field }) => {
        if (filters?.sort_by !== field) return <LuArrowUpDown size={14} />;
        return filters?.sort_order === 'asc' ? <LuArrowUp size={14} /> : <LuArrowDown size={14} />;
    };

    const SortableHeader = ({ field, children, ...props }) => (
        <Table.ColumnHeader
            cursor="pointer"
            onClick={() => handleSort(field)}
            _hover={{ color: 'pecado.500' }}
            transition="color 0.15s"
            {...props}
        >
            <HStack gap="1">
                <Text>{children}</Text>
                <SortIcon field={field} />
            </HStack>
        </Table.ColumnHeader>
    );

    const fmt = (v) => Number(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return (
        <CabinetLayout title="Мои отгрузки">
            <Head title="Мои отгрузки — Pecado" />

            {/* Поиск и кнопка фильтров */}
            <Flex gap="3" mb="4" direction={{ base: 'column', sm: 'row' }}>
                <Box as="form" onSubmit={handleSearch} flex="1">
                    <Flex gap="2">
                        <Input
                            placeholder="Поиск по UUID, ИНН, названию товара..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            size="sm"
                        />
                        <IconButton type="submit" variant="outline" size="sm" aria-label="Искать">
                            <LuSearch size={16} />
                        </IconButton>
                    </Flex>
                </Box>
                <Button
                    onClick={() => setShowFilters(!showFilters)}
                    variant="outline"
                    size="sm"
                    flexShrink="0"
                >
                    <LuFilter size={16} />
                    {showFilters ? 'Скрыть фильтры' : 'Фильтры'}
                </Button>
            </Flex>

            {/* Расширенные фильтры */}
            {showFilters && (
                <Card.Root bg="white" mb="4" borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
                    <Card.Body p="4">
                        <Stack gap="4">
                            <Flex gap="4" direction={{ base: 'column', md: 'row' }}>
                                <Field label="Статус" flex="1">
                                    <Select.Root
                                        value={localFilters.status ? [localFilters.status] : []}
                                        onValueChange={(e) => setLocalFilters({ ...localFilters, status: e.value[0] || '' })}
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Все статусы" />
                                        </Select.Trigger>
                                        <Select.Content>
                                            <Select.Item item="">Все статусы</Select.Item>
                                            {statuses?.map((s) => (
                                                <Select.Item key={s.value} item={s.value}>{s.label}</Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </Field>

                                <Field label="Дата от" flex="1">
                                    <Input
                                        type="date"
                                        size="sm"
                                        value={localFilters.date_from}
                                        onChange={(e) => setLocalFilters({ ...localFilters, date_from: e.target.value })}
                                    />
                                </Field>

                                <Field label="Дата до" flex="1">
                                    <Input
                                        type="date"
                                        size="sm"
                                        value={localFilters.date_to}
                                        onChange={(e) => setLocalFilters({ ...localFilters, date_to: e.target.value })}
                                    />
                                </Field>
                            </Flex>

                            <Flex gap="4" direction={{ base: 'column', md: 'row' }} align="end">
                                <Field label="Сумма от" flex="1">
                                    <Input
                                        type="number" step="0.01" size="sm"
                                        value={localFilters.amount_from}
                                        onChange={(e) => setLocalFilters({ ...localFilters, amount_from: e.target.value })}
                                        placeholder="0.00"
                                    />
                                </Field>

                                <Field label="Сумма до" flex="1">
                                    <Input
                                        type="number" step="0.01" size="sm"
                                        value={localFilters.amount_to}
                                        onChange={(e) => setLocalFilters({ ...localFilters, amount_to: e.target.value })}
                                        placeholder="0.00"
                                    />
                                </Field>

                                <HStack gap="2" flexShrink="0">
                                    <Button onClick={handleApplyFilters} colorPalette="pecado" size="sm">Применить</Button>
                                    <Button onClick={handleResetFilters} variant="outline" size="sm">
                                        <LuX size={14} /> Сбросить
                                    </Button>
                                </HStack>
                            </Flex>
                        </Stack>
                    </Card.Body>
                </Card.Root>
            )}

            {/* Таблица / пустое состояние */}
            {shipments.data.length === 0 ? (
                <Card.Root bg="white" borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
                    <Card.Body p="10" textAlign="center">
                        <VStack gap="3">
                            <Flex
                                align="center" justify="center" w="16" h="16"
                                borderRadius="full" bg="gray.100" _dark={{ bg: 'gray.700' }} mx="auto"
                            >
                                <LuTruck size={28} color="var(--chakra-colors-gray-400)" />
                            </Flex>
                            <Text fontWeight="600" fontSize="lg">Отгрузок пока нет</Text>
                            <Text color="gray.500" fontSize="sm">
                                Когда 1С создаст реализацию по вашему контрагенту, она появится здесь
                            </Text>
                        </VStack>
                    </Card.Body>
                </Card.Root>
            ) : (
                <>
                    {/* Desktop */}
                    <Card.Root bg="white"
                        display={{ base: 'none', md: 'block' }}
                        borderRadius="xl" border="1px solid" borderColor="gray.100"
                        _dark={{ borderColor: 'gray.700' }} overflow="hidden"
                    >
                        <Box overflowX="auto">
                            <Table.Root bg="white" size="sm">
                                <Table.Header>
                                    <Table.Row bg="white" _dark={{ bg: 'gray.800' }}>
                                        <SortableHeader field="id" w="80px">№</SortableHeader>
                                        <SortableHeader field="date">Дата</SortableHeader>
                                        <SortableHeader field="status">Статус</SortableHeader>
                                        <Table.ColumnHeader>Компания</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="center">Позиций</Table.ColumnHeader>
                                        <SortableHeader field="total_amount" textAlign="right">
                                            Сумма ({currencySymbol})
                                        </SortableHeader>
                                        <Table.ColumnHeader w="60px" />
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {shipments.data.map((shipment) => (
                                        <Table.Row
                                            key={shipment.id}
                                            _hover={{ bg: 'gray.50/50', _dark: { bg: 'gray.800/50' } }}
                                            transition="background 0.15s"
                                        >
                                            <Table.Cell>
                                                <Text fontWeight="600">#{shipment.id}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm" color="fg.muted">
                                                    {shipment.date ? new Date(shipment.date).toLocaleDateString('ru-RU') : '—'}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge
                                                    colorPalette={STATUS_COLORS[shipment.status] || 'gray'}
                                                    variant="subtle" borderRadius="full" px="2.5" fontSize="xs"
                                                >
                                                    {shipment.status_label}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm" color="fg.muted">{shipment.company?.name || '—'}</Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="center">
                                                <Text fontSize="sm">{shipment.items_count}</Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <VStack gap="0" align="end">
                                                    <Text fontWeight="600">
                                                        {fmt(shipment.total_converted)} {currencySymbol}
                                                    </Text>
                                                    {shipment.currency_code && shipment.currency_code !== currency?.code && (
                                                        <Text fontSize="xs" color="gray.400">
                                                            {fmt(shipment.total_amount)} {shipment.currency_code}
                                                        </Text>
                                                    )}
                                                </VStack>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Link href={`/cabinet/shipments/${shipment.id}`}>
                                                    <IconButton variant="ghost" size="xs" aria-label="Просмотр" colorPalette="pecado">
                                                        <LuEye size={16} />
                                                    </IconButton>
                                                </Link>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                    </Card.Root>

                    {/* Mobile */}
                    <VStack gap="3" display={{ base: 'flex', md: 'none' }}>
                        {shipments.data.map((shipment) => (
                            <Link key={shipment.id} href={`/cabinet/shipments/${shipment.id}`} style={{ width: '100%' }}>
                                <Card.Root bg="white"
                                    borderRadius="xl" border="1px solid" borderColor="gray.100"
                                    _dark={{ borderColor: 'gray.700' }}
                                    _hover={{ shadow: 'md', transform: 'translateY(-1px)' }}
                                    transition="all 0.2s" cursor="pointer"
                                >
                                    <Card.Body p="4">
                                        <Flex justify="space-between" align="center" mb="2">
                                            <Text fontWeight="700" fontSize="md">Отгрузка #{shipment.id}</Text>
                                            <Badge
                                                colorPalette={STATUS_COLORS[shipment.status] || 'gray'}
                                                variant="subtle" borderRadius="full" px="2.5" fontSize="xs"
                                            >
                                                {shipment.status_label}
                                            </Badge>
                                        </Flex>
                                        <Flex justify="space-between" align="center">
                                            <VStack gap="0" align="start">
                                                {shipment.company && (
                                                    <Text fontSize="xs" color="fg.muted">{shipment.company.name}</Text>
                                                )}
                                                <Text fontSize="xs" color="fg.muted">
                                                    {shipment.date ? new Date(shipment.date).toLocaleDateString('ru-RU') : '—'}
                                                </Text>
                                            </VStack>
                                            <VStack gap="0" align="end">
                                                <Text fontWeight="700" fontSize="md">
                                                    {fmt(shipment.total_converted)} {currencySymbol}
                                                </Text>
                                                {shipment.currency_code && shipment.currency_code !== currency?.code && (
                                                    <Text fontSize="xs" color="gray.400">
                                                        {fmt(shipment.total_amount)} {shipment.currency_code}
                                                    </Text>
                                                )}
                                            </VStack>
                                        </Flex>
                                    </Card.Body>
                                </Card.Root>
                            </Link>
                        ))}
                    </VStack>

                    {/* Пагинация */}
                    {shipments.last_page > 1 && (
                        <Flex justify="center" align="center" gap="2" mt="6">
                            <IconButton
                                variant="outline" size="sm"
                                onClick={() => handlePageChange(shipments.current_page - 1)}
                                disabled={shipments.current_page <= 1}
                                aria-label="Предыдущая страница"
                            >
                                <LuChevronLeft size={16} />
                            </IconButton>

                            {Array.from({ length: shipments.last_page }, (_, i) => i + 1)
                                .filter(page => {
                                    const cur = shipments.current_page;
                                    return page === 1 || page === shipments.last_page ||
                                        (page >= cur - 2 && page <= cur + 2);
                                })
                                .reduce((acc, page, idx, arr) => {
                                    if (idx > 0 && page - arr[idx - 1] > 1) acc.push('…' + page);
                                    acc.push(page);
                                    return acc;
                                }, [])
                                .map((page) => {
                                    if (typeof page === 'string') {
                                        return <Text key={page} px="1" color="fg.muted">…</Text>;
                                    }
                                    return (
                                        <Button
                                            key={page} size="sm" minW="9"
                                            variant={page === shipments.current_page ? 'solid' : 'outline'}
                                            colorPalette={page === shipments.current_page ? 'pecado' : 'gray'}
                                            onClick={() => handlePageChange(page)}
                                        >
                                            {page}
                                        </Button>
                                    );
                                })}

                            <IconButton
                                variant="outline" size="sm"
                                onClick={() => handlePageChange(shipments.current_page + 1)}
                                disabled={shipments.current_page >= shipments.last_page}
                                aria-label="Следующая страница"
                            >
                                <LuChevronRight size={16} />
                            </IconButton>

                            <Text fontSize="xs" color="fg.muted" ml="2">
                                Стр. {shipments.current_page} из {shipments.last_page}
                            </Text>
                        </Flex>
                    )}
                </>
            )}
        </CabinetLayout>
    );
}
