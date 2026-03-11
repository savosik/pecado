import { useState } from 'react';
import {
    Box, Flex, HStack, VStack, Text, Badge, Button, Input, Table,
    Card, Stack, IconButton,
} from '@chakra-ui/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    LuFilter, LuX, LuArrowUpDown, LuArrowUp, LuArrowDown,
    LuEye, LuChevronLeft, LuChevronRight, LuSearch, LuRotateCcw, LuPlus,
} from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Field } from '@/components/ui/field';
import { Select } from '@/components/ui/select';

const STATUS_COLORS = {
    pending: 'yellow',
    approved: 'green',
    rejected: 'red',
    completed: 'blue',
};

export default function ReturnsIndex({ filters, statuses, reasons }) {
    const { returns } = usePage().props;
    const [showFilters, setShowFilters] = useState(false);
    const [search, setSearch] = useState(filters?.search || '');
    const [localFilters, setLocalFilters] = useState({
        status: filters?.status || '',
        reason: filters?.reason || '',
        date_from: filters?.date_from || '',
        date_to: filters?.date_to || '',
        amount_from: filters?.amount_from || '',
        amount_to: filters?.amount_to || '',
    });

    const navigateWithParams = (params) => {
        router.get('/cabinet/returns', {
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
        const reset = { status: '', reason: '', date_from: '', date_to: '', amount_from: '', amount_to: '' };
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
        <CabinetLayout
            title="Возвраты"
            actions={
                <Button asChild bg="#9e1b32" color="white" _hover={{ bg: '#7a1527' }} size="sm">
                    <Link href="/cabinet/returns/create">
                        <LuPlus size={16} /> Создать возврат
                    </Link>
                </Button>
            }
        >
            <Head title="Возвраты — Pecado" />

            {/* Поиск и кнопка фильтров */}
            <Flex gap="3" mb="4" direction={{ base: 'column', sm: 'row' }}>
                <Box as="form" onSubmit={handleSearch} flex="1">
                    <Flex gap="2">
                        <Input
                            placeholder="Поиск по номеру возврата..."
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
                <Card.Root mb="4" borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
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

                                <Field label="Причина" flex="1">
                                    <Select.Root
                                        value={localFilters.reason ? [localFilters.reason] : []}
                                        onValueChange={(e) => setLocalFilters({ ...localFilters, reason: e.value[0] || '' })}
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Все причины" />
                                        </Select.Trigger>
                                        <Select.Content>
                                            <Select.Item item="">Все причины</Select.Item>
                                            {reasons?.map((r) => (
                                                <Select.Item key={r.value} item={r.value}>
                                                    {r.label}
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
                                    <Button onClick={handleApplyFilters} bg="#9e1b32" color="white" _hover={{ bg: '#7a1527' }} size="sm">
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

            {/* Таблица возвратов */}
            {returns.data.length === 0 ? (
                <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
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
                                <LuRotateCcw size={28} color="var(--chakra-colors-gray-400)" />
                            </Flex>
                            <Text fontWeight="600" fontSize="lg">Возвратов пока нет</Text>
                            <Text color="gray.500" fontSize="sm">
                                Когда вы оформите возврат, он появится здесь
                            </Text>
                            <Button asChild bg="#9e1b32" color="white" _hover={{ bg: '#7a1527' }} size="sm" mt="2">
                                <Link href="/cabinet/returns/create">
                                    <LuPlus size={16} /> Создать возврат
                                </Link>
                            </Button>
                        </VStack>
                    </Card.Body>
                </Card.Root>
            ) : (
                <>
                    {/* Desktop table */}
                    <Card.Root
                        display={{ base: 'none', md: 'block' }}
                        borderRadius="xl"
                        border="1px solid"
                        borderColor="gray.100"
                        _dark={{ borderColor: 'gray.700' }}
                        overflow="hidden"
                    >
                        <Box overflowX="auto">
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row bg="gray.50" _dark={{ bg: 'gray.800' }}>
                                        <SortableHeader field="id" w="80px">№</SortableHeader>
                                        <SortableHeader field="status">Статус</SortableHeader>
                                        <Table.ColumnHeader>Причина</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="center">Позиций</Table.ColumnHeader>
                                        <SortableHeader field="total_amount" textAlign="right">Сумма</SortableHeader>
                                        <SortableHeader field="created_at">Дата</SortableHeader>
                                        <Table.ColumnHeader w="60px" />
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {returns.data.map((ret) => (
                                        <Table.Row
                                            key={ret.id}
                                            _hover={{ bg: 'gray.50', _dark: { bg: 'gray.800' } }}
                                            transition="background 0.15s"
                                        >
                                            <Table.Cell>
                                                <Text fontWeight="600">#{ret.id}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge
                                                    colorPalette={STATUS_COLORS[ret.status] || 'gray'}
                                                    variant="subtle"
                                                    borderRadius="full"
                                                    px="2.5"
                                                    fontSize="xs"
                                                >
                                                    {ret.status_label}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm" color="fg.muted">
                                                    {ret.primary_reason_label || '—'}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="center">
                                                <Text fontSize="sm">{ret.items_count}</Text>
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <Text fontWeight="600">{fmt(ret.total_amount)} ₽</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm" color="fg.muted">{ret.created_at}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Link href={`/cabinet/returns/${ret.id}`}>
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
                        {returns.data.map((ret) => (
                            <Link key={ret.id} href={`/cabinet/returns/${ret.id}`} style={{ width: '100%' }}>
                                <Card.Root
                                    borderRadius="xl"
                                    border="1px solid"
                                    borderColor="gray.100"
                                    _dark={{ borderColor: 'gray.700' }}
                                    _hover={{ shadow: 'md', transform: 'translateY(-1px)' }}
                                    transition="all 0.2s"
                                    cursor="pointer"
                                >
                                    <Card.Body p="4">
                                        <Flex justify="space-between" align="center" mb="2">
                                            <Text fontWeight="700" fontSize="md">Возврат #{ret.id}</Text>
                                            <Badge
                                                colorPalette={STATUS_COLORS[ret.status] || 'gray'}
                                                variant="subtle"
                                                borderRadius="full"
                                                px="2.5"
                                                fontSize="xs"
                                            >
                                                {ret.status_label}
                                            </Badge>
                                        </Flex>
                                        <Flex justify="space-between" align="center">
                                            <VStack gap="0" align="start">
                                                {ret.primary_reason_label && (
                                                    <Text fontSize="xs" color="fg.muted">{ret.primary_reason_label}</Text>
                                                )}
                                                <Text fontSize="xs" color="fg.muted">{ret.created_at}</Text>
                                            </VStack>
                                            <Text fontWeight="700" fontSize="md">
                                                {fmt(ret.total_amount)} ₽
                                            </Text>
                                        </Flex>
                                    </Card.Body>
                                </Card.Root>
                            </Link>
                        ))}
                    </VStack>

                    {/* Пагинация */}
                    {returns.last_page > 1 && (
                        <Flex justify="center" align="center" gap="2" mt="6">
                            <IconButton
                                variant="outline"
                                size="sm"
                                onClick={() => handlePageChange(returns.current_page - 1)}
                                disabled={returns.current_page <= 1}
                                aria-label="Предыдущая страница"
                            >
                                <LuChevronLeft size={16} />
                            </IconButton>

                            {Array.from({ length: returns.last_page }, (_, i) => i + 1)
                                .filter(page => {
                                    const current = returns.current_page;
                                    return page === 1 || page === returns.last_page ||
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
                                            variant={page === returns.current_page ? 'solid' : 'outline'}
                                            colorPalette={page === returns.current_page ? 'pecado' : 'gray'}
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
                                onClick={() => handlePageChange(returns.current_page + 1)}
                                disabled={returns.current_page >= returns.last_page}
                                aria-label="Следующая страница"
                            >
                                <LuChevronRight size={16} />
                            </IconButton>

                            <Text fontSize="xs" color="fg.muted" ml="2">
                                Стр. {returns.current_page} из {returns.last_page}
                            </Text>
                        </Flex>
                    )}
                </>
            )}
        </CabinetLayout>
    );
}
