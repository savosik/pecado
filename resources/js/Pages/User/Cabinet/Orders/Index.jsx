import { useState } from 'react';
import {
    Box, Flex, HStack, VStack, Text, Badge, Button, Input, Table,
    Card, Stack, IconButton,
} from '@chakra-ui/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    LuFilter, LuX, LuArrowUpDown, LuArrowUp, LuArrowDown,
    LuEye, LuChevronLeft, LuChevronRight, LuSearch, LuShoppingBag,
} from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Field } from '@/components/ui/field';
import { Select } from '@/components/ui/select';

const STATUS_COLORS = {
    pending: 'yellow',
    confirmed: 'blue',
    ready_to_ship: 'purple',
    closed: 'green',
};

export default function OrdersIndex({ filters, statuses, types }) {
    const { orders, currency } = usePage().props;
    const currencySymbol = currency?.symbol ?? '₽';
    const [showFilters, setShowFilters] = useState(false);
    const [search, setSearch] = useState(filters?.search || '');
    const [localFilters, setLocalFilters] = useState({
        status: filters?.status || '',
        type: filters?.type || '',
        date_from: filters?.date_from || '',
        date_to: filters?.date_to || '',
        amount_from: filters?.amount_from || '',
        amount_to: filters?.amount_to || '',
    });

    const navigateWithParams = (params) => {
        router.get('/cabinet/orders', {
            ...filters,
            ...params,
        }, {
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

    const handleApplyFilters = () => {
        navigateWithParams({ ...localFilters, page: 1 });
    };

    const handleResetFilters = () => {
        const reset = { status: '', type: '', date_from: '', date_to: '', amount_from: '', amount_to: '' };
        setLocalFilters(reset);
        navigateWithParams({ ...reset, search: '', page: 1 });
        setSearch('');
    };

    const handlePageChange = (page) => {
        navigateWithParams({ page });
    };

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
        <CabinetLayout title="Мои заказы">
            <Head title="Мои заказы — Pecado" />

            {/* Поиск и кнопка фильтров */}
            <Flex gap="3" mb="4" direction={{ base: 'column', sm: 'row' }}>
                <Box as="form" onSubmit={handleSearch} flex="1">
                    <Flex gap="2">
                        <Input
                            placeholder="Поиск по номеру заказа..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            size="sm"
                        />
                        <IconButton
                            type="submit"
                            variant="outline"
                            size="sm"
                            aria-label="Искать"
                        >
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
                <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} mb="4" borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}>
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
                                                <Select.Item key={s.value} item={s.value}>
                                                    {s.label}
                                                </Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </Field>

                                <Field label="Тип" flex="1">
                                    <Select.Root
                                        value={localFilters.type ? [localFilters.type] : []}
                                        onValueChange={(e) => setLocalFilters({ ...localFilters, type: e.value[0] || '' })}
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Все типы" />
                                        </Select.Trigger>
                                        <Select.Content>
                                            <Select.Item item="">Все типы</Select.Item>
                                            {types?.map((t) => (
                                                <Select.Item key={t.value} item={t.value}>
                                                    {t.label}
                                                </Select.Item>
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
                                        type="number"
                                        step="0.01"
                                        size="sm"
                                        value={localFilters.amount_from}
                                        onChange={(e) => setLocalFilters({ ...localFilters, amount_from: e.target.value })}
                                        placeholder="0.00"
                                    />
                                </Field>

                                <Field label="Сумма до" flex="1">
                                    <Input
                                        type="number"
                                        step="0.01"
                                        size="sm"
                                        value={localFilters.amount_to}
                                        onChange={(e) => setLocalFilters({ ...localFilters, amount_to: e.target.value })}
                                        placeholder="0.00"
                                    />
                                </Field>

                                <HStack gap="2" flexShrink="0">
                                    <Button onClick={handleApplyFilters} colorPalette="pecado" size="sm">
                                        Применить
                                    </Button>
                                    <Button onClick={handleResetFilters} variant="outline" size="sm">
                                        <LuX size={14} /> Сбросить
                                    </Button>
                                </HStack>
                            </Flex>
                        </Stack>
                    </Card.Body>
                </Card.Root>
            )}

            {/* Таблица заказов */}
            {orders.data.length === 0 ? (
                <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}>
                    <Card.Body p="10" textAlign="center">
                        <VStack gap="3">
                            <Flex
                                align="center"
                                justify="center"
                                w="16"
                                h="16"
                                borderRadius="full"
                                bg="gray.100"
                                _dark={{ bg: 'gray.700' }}
                                mx="auto"
                            >
                                <LuShoppingBag size={28} color="var(--chakra-colors-gray-400)" />
                            </Flex>
                            <Text fontWeight="600" fontSize="lg">Заказов пока нет</Text>
                            <Text color="gray.500" fontSize="sm">
                                Когда вы оформите заказ, он появится здесь
                            </Text>
                        </VStack>
                    </Card.Body>
                </Card.Root>
            ) : (
                <>
                    {/* Desktop table */}
                    <Card.Root bg={{ base: 'white', _dark: 'gray.800' }}
                        display={{ base: 'none', md: 'block' }}
                        borderRadius="xl"
                        border="1px solid"
                        borderColor={{ base: 'gray.100', _dark: 'gray.700' }}
                        _dark={{ borderColor: 'gray.700' }}
                        overflow="hidden"
                    >
                        <Box overflowX="auto">
                            <Table.Root bg={{ base: 'white', _dark: 'gray.800' }} size="sm">
                                <Table.Header>
                                    <Table.Row bg={{ base: 'white', _dark: 'gray.800' }} _dark={{ bg: 'gray.800' }}>
                                        <SortableHeader field="id" w="80px">№</SortableHeader>
                                        <SortableHeader field="status">Статус</SortableHeader>
                                        <Table.ColumnHeader>Компания</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="center">Позиций</Table.ColumnHeader>
                                        <SortableHeader field="total_amount" textAlign="right">Сумма ({currencySymbol})</SortableHeader>
                                        <SortableHeader field="created_at">Дата</SortableHeader>
                                        <Table.ColumnHeader w="60px" />
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {orders.data.map((order) => (
                                        <Table.Row
                                            key={order.id}
                                            _hover={{ bg: 'gray.50/50', _dark: { bg: 'gray.800/50' } }}
                                            transition="background 0.15s"
                                        >
                                            <Table.Cell>
                                                <VStack gap="1" align="start">
                                                    <Text fontWeight="600">#{order.id}</Text>
                                                    {order.type === 'preorder' ? (
                                                        <Badge colorPalette="purple" variant="subtle" fontSize="2xs" px="1.5">Предзаказ</Badge>
                                                    ) : (
                                                        <Badge colorPalette="gray" variant="subtle" fontSize="2xs" px="1.5">Заказ</Badge>
                                                    )}
                                                </VStack>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge
                                                    colorPalette={STATUS_COLORS[order.status] || 'gray'}
                                                    variant="subtle"
                                                    borderRadius="full"
                                                    px="2.5"
                                                    fontSize="xs"
                                                >
                                                    {order.status_label}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm" color="fg.muted">{order.company?.name || '—'}</Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="center">
                                                <Text fontSize="sm">{order.items_count}</Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <VStack gap="0" align="end">
                                                    <Text fontWeight="600">
                                                        {fmt(order.total_converted)} {currencySymbol}
                                                    </Text>
                                                    {order.currency_code && order.currency_code !== currency?.code && (
                                                        <Text fontSize="xs" color="gray.400">
                                                            {fmt(order.total_amount)} {order.currency_code}
                                                        </Text>
                                                    )}
                                                </VStack>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm" color="fg.muted">{order.created_at}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Link href={`/cabinet/orders/${order.id}`}>
                                                    <IconButton
                                                        variant="ghost"
                                                        size="xs"
                                                        aria-label="Просмотр"
                                                        colorPalette="pecado"
                                                    >
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

                    {/* Mobile cards */}
                    <VStack gap="3" display={{ base: 'flex', md: 'none' }}>
                        {orders.data.map((order) => (
                            <Link key={order.id} href={`/cabinet/orders/${order.id}`} style={{ width: '100%' }}>
                                <Card.Root bg={{ base: 'white', _dark: 'gray.800' }}
                                    borderRadius="xl"
                                    border="1px solid"
                                    borderColor={{ base: 'gray.100', _dark: 'gray.700' }}
                                    _dark={{ borderColor: 'gray.700' }}
                                    _hover={{ shadow: 'md', transform: 'translateY(-1px)' }}
                                    transition="all 0.2s"
                                    cursor="pointer"
                                >
                                    <Card.Body p="4">
                                        <Flex justify="space-between" align="center" mb="2">
                                            <HStack gap="2">
                                                <Text fontWeight="700" fontSize="md">Заказ #{order.id}</Text>
                                                {order.type === 'preorder' && (
                                                    <Badge colorPalette="purple" variant="subtle" fontSize="2xs">Предзаказ</Badge>
                                                )}
                                            </HStack>
                                            <Badge
                                                colorPalette={STATUS_COLORS[order.status] || 'gray'}
                                                variant="subtle"
                                                borderRadius="full"
                                                px="2.5"
                                                fontSize="xs"
                                            >
                                                {order.status_label}
                                            </Badge>
                                        </Flex>
                                        <Flex justify="space-between" align="center">
                                            <VStack gap="0" align="start">
                                                {order.company && (
                                                    <Text fontSize="xs" color="fg.muted">{order.company.name}</Text>
                                                )}
                                                <Text fontSize="xs" color="fg.muted">{order.created_at}</Text>
                                            </VStack>
                                            <VStack gap="0" align="end">
                                                <Text fontWeight="700" fontSize="md">
                                                    {fmt(order.total_converted)} {currencySymbol}
                                                </Text>
                                                {order.currency_code && order.currency_code !== currency?.code && (
                                                    <Text fontSize="xs" color="gray.400">
                                                        {fmt(order.total_amount)} {order.currency_code}
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
                    {orders.last_page > 1 && (
                        <Flex justify="center" align="center" gap="2" mt="6">
                            <IconButton
                                variant="outline"
                                size="sm"
                                onClick={() => handlePageChange(orders.current_page - 1)}
                                disabled={orders.current_page <= 1}
                                aria-label="Предыдущая страница"
                            >
                                <LuChevronLeft size={16} />
                            </IconButton>

                            {Array.from({ length: orders.last_page }, (_, i) => i + 1)
                                .filter(page => {
                                    const current = orders.current_page;
                                    return page === 1 || page === orders.last_page ||
                                        (page >= current - 2 && page <= current + 2);
                                })
                                .reduce((acc, page, idx, arr) => {
                                    if (idx > 0 && page - arr[idx - 1] > 1) {
                                        acc.push('...' + page);
                                    }
                                    acc.push(page);
                                    return acc;
                                }, [])
                                .map((page) => {
                                    if (typeof page === 'string') {
                                        return <Text key={page} px="1" color="fg.muted">…</Text>;
                                    }
                                    return (
                                        <Button
                                            key={page}
                                            size="sm"
                                            variant={page === orders.current_page ? 'solid' : 'outline'}
                                            colorPalette={page === orders.current_page ? 'pecado' : 'gray'}
                                            onClick={() => handlePageChange(page)}
                                            minW="9"
                                        >
                                            {page}
                                        </Button>
                                    );
                                })}

                            <IconButton
                                variant="outline"
                                size="sm"
                                onClick={() => handlePageChange(orders.current_page + 1)}
                                disabled={orders.current_page >= orders.last_page}
                                aria-label="Следующая страница"
                            >
                                <LuChevronRight size={16} />
                            </IconButton>

                            <Text fontSize="xs" color="fg.muted" ml="2">
                                Стр. {orders.current_page} из {orders.last_page}
                            </Text>
                        </Flex>
                    )}
                </>
            )}
        </CabinetLayout>
    );
}
