import { useEffect, useMemo, useRef, useState } from 'react';
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
import SelectedFilters from '@/components/cabinet/SelectedFilters';
import MatchBadge from '@/components/cabinet/MatchBadge';
import ExportMenu from '@/components/cabinet/ExportMenu';
import { useSearchHistory } from '@/hooks/useSearchHistory';

const STATUS_COLORS = {
    pending: 'yellow',
    confirmed: 'green',
    ready_to_ship: 'purple',
    closed: 'blue',
    cancelled: 'red',
};

export default function ReturnsIndex({ filters, statuses, reasons, exportEnabled = false }) {
    const { returns } = usePage().props;
    const [showFilters, setShowFilters] = useState(false);
    const [search, setSearch] = useState(filters?.search || '');
    const initialStatus = Array.isArray(filters?.status)
        ? filters.status
        : (filters?.status ? [filters.status] : []);
    const initialReason = Array.isArray(filters?.reason)
        ? filters.reason
        : (filters?.reason ? [filters.reason] : []);
    const [localFilters, setLocalFilters] = useState({
        status: initialStatus,
        reason: initialReason,
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

    const { history: searchHistory, push: pushSearchHistory } = useSearchHistory('returns');

    // Debounce 400 мс на поле поиска (§ «Сквозные принципы» п.3, A-7).
    const lastSubmittedSearch = useRef(filters?.search || '');
    useEffect(() => {
        if (search === lastSubmittedSearch.current) return;
        const handle = setTimeout(() => {
            lastSubmittedSearch.current = search;
            pushSearchHistory(search);
            navigateWithParams({ search, page: 1 });
        }, 400);
        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const handleSearch = (e) => {
        e.preventDefault();
        lastSubmittedSearch.current = search;
        pushSearchHistory(search);
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
        const reset = {
            status: [], reason: [],
            date_from: '', date_to: '', amount_from: '', amount_to: '',
        };
        setLocalFilters(reset);
        navigateWithParams({ ...reset, search: '', page: 1 });
        setSearch('');
        lastSubmittedSearch.current = '';
    };

    const handlePageChange = (page) => {
        navigateWithParams({ page });
    };

    const activeFiltersCount = (() => {
        let count = 0;
        for (const k of ['status', 'reason']) {
            const v = filters?.[k];
            if (Array.isArray(v) ? v.length > 0 : !!v) count++;
        }
        for (const k of ['date_from', 'date_to', 'amount_from', 'amount_to']) {
            if (filters?.[k] !== null && filters?.[k] !== undefined && filters?.[k] !== '') count++;
        }
        return count;
    })();

    const filterFields = useMemo(() => [
        { key: 'search', label: 'Поиск', formatter: (v) => `«${v}»` },
        { key: 'status', label: 'Статус', formatter: (v) => statuses?.find((s) => s.value === v)?.label || v },
        { key: 'reason', label: 'Причина', formatter: (v) => reasons?.find((r) => r.value === v)?.label || v },
        { key: 'date_from', label: 'Дата от' },
        { key: 'date_to', label: 'Дата до' },
        { key: 'amount_from', label: 'Сумма от' },
        { key: 'amount_to', label: 'Сумма до' },
    ], [statuses, reasons]);

    const handleRemoveFilter = (key, value) => {
        const current = filters?.[key];
        let nextValue;
        if (Array.isArray(current)) {
            nextValue = current.filter((v) => String(v) !== String(value));
        } else {
            nextValue = '';
        }
        if (key === 'status' || key === 'reason') {
            setLocalFilters({ ...localFilters, [key]: Array.isArray(nextValue) ? nextValue : [] });
        } else if (key === 'search') {
            setSearch('');
            lastSubmittedSearch.current = '';
        } else if (Object.prototype.hasOwnProperty.call(localFilters, key)) {
            setLocalFilters({ ...localFilters, [key]: nextValue });
        }
        navigateWithParams({ [key]: nextValue, page: 1 });
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
                            placeholder="Поиск по номеру возврата, реализации, товару, бренду…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            size="sm"
                            list="returns-search-history"
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
                    {searchHistory.length > 0 && (
                        <datalist id="returns-search-history">
                            {searchHistory.map((item) => (
                                <option key={item} value={item} />
                            ))}
                        </datalist>
                    )}
                </Box>
                <Button
                    onClick={() => setShowFilters(!showFilters)}
                    variant={showFilters || activeFiltersCount > 0 ? 'subtle' : 'outline'}
                    colorPalette={showFilters || activeFiltersCount > 0 ? 'pecado' : 'gray'}
                    size="sm"
                    flexShrink="0"
                    aria-expanded={showFilters}
                >
                    <LuFilter size={16} />
                    {showFilters ? 'Скрыть фильтры' : 'Фильтры'}
                    {activeFiltersCount > 0 && (
                        <Badge colorPalette="pecado" variant="solid" borderRadius="full" fontSize="2xs" px="1.5" minW="4">
                            {activeFiltersCount}
                        </Badge>
                    )}
                </Button>
                {exportEnabled && (
                    <ExportMenu
                        basePath="/cabinet/returns/export"
                        filters={{ ...filters, search }}
                    />
                )}
            </Flex>

            {/* Расширенные фильтры */}
            {showFilters && (
                <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} mb="4" borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}>
                    <Card.Body p="4">
                        <Stack gap="4">
                            <Flex gap="4" direction={{ base: 'column', md: 'row' }}>
                                <Field label="Статусы" flex="1">
                                    <Select.Root
                                        multiple
                                        value={localFilters.status}
                                        onValueChange={(e) => setLocalFilters({ ...localFilters, status: e.value })}
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Все статусы">
                                                {(items) => items.length === 0 ? 'Все статусы' : `Выбрано: ${items.length}`}
                                            </Select.ValueText>
                                        </Select.Trigger>
                                        <Select.Content>
                                            {statuses?.map((s) => (
                                                <Select.Item key={s.value} item={s.value}>
                                                    {s.label}
                                                </Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </Field>

                                <Field label="Причины" flex="1">
                                    <Select.Root
                                        multiple
                                        value={localFilters.reason}
                                        onValueChange={(e) => setLocalFilters({ ...localFilters, reason: e.value })}
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Все причины">
                                                {(items) => items.length === 0 ? 'Все причины' : `Выбрано: ${items.length}`}
                                            </Select.ValueText>
                                        </Select.Trigger>
                                        <Select.Content>
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

            <SelectedFilters
                filters={{ ...filters, search }}
                fields={filterFields}
                onRemove={handleRemoveFilter}
                onResetAll={activeFiltersCount > 0 || search ? handleResetFilters : undefined}
            />

            {/* Таблица возвратов */}
            {returns.data.length === 0 ? (
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
                                            _hover={{ bg: 'gray.50/50', _dark: { bg: 'gray.800/50' } }}
                                            transition="background 0.15s"
                                        >
                                            <Table.Cell>
                                                <Text fontWeight="600" noOfLines={1} maxW="150px" title={ret.number}>
                                                    {ret.number}
                                                </Text>
                                                <MatchBadge
                                                    source={ret.match_source}
                                                    snippet={ret.match_snippet}
                                                    search={filters.search || ''}
                                                />
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
                                            <Text fontWeight="700" fontSize="md" noOfLines={1} pr="2">Возврат {ret.number}</Text>
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
                                        <MatchBadge
                                            source={ret.match_source}
                                            snippet={ret.match_snippet}
                                            search={filters.search || ''}
                                        />

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
