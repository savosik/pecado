import { useState } from 'react';
import {
    Box, Flex, HStack, VStack, Text, Badge, Button, Input,
    Card, Stack, IconButton,
} from '@chakra-ui/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    LuFilter, LuX, LuArrowUpDown, LuArrowUp, LuArrowDown,
    LuChevronLeft, LuChevronRight, LuSearch, LuTruck, LuCalendar,
} from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Field } from '@/components/ui/field';
import { Select } from '@/components/ui/select';

const STATUS_COLORS = {
    new: 'blue',
    in_progress: 'orange',
    completed: 'green',
    cancelled: 'red',
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

    const fmt = (v) => Number(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const formatDate = (iso) => iso ? new Date(iso).toLocaleDateString('ru-RU') : null;

    const SortButton = ({ field, children }) => {
        const active = filters?.sort_by === field;
        const Icon = active
            ? (filters?.sort_order === 'asc' ? LuArrowUp : LuArrowDown)
            : LuArrowUpDown;
        return (
            <Button
                variant={active ? 'subtle' : 'ghost'}
                colorPalette={active ? 'pecado' : 'gray'}
                size="xs"
                onClick={() => handleSort(field)}
                gap="1"
            >
                {children}
                <Icon size={12} />
            </Button>
        );
    };

    return (
        <CabinetLayout title="Мои отгрузки">
            <Head title="Мои отгрузки — Pecado" />

            {/* Поиск и кнопка фильтров */}
            <Flex gap="3" mb="4" direction={{ base: 'column', sm: 'row' }}>
                <Box as="form" onSubmit={handleSearch} flex="1">
                    <Flex gap="2">
                        <Input
                            placeholder="Поиск по номеру, ИНН, названию товара..."
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

            {/* Список отгрузок */}
            {shipments.data.length === 0 ? (
                <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}>
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
                    {/* Сортировка */}
                    <HStack gap="1" mb="3" px="1" flexWrap="wrap">
                        <Text fontSize="xs" color="gray.400" mr="1">Сортировка:</Text>
                        <SortButton field="id">Номер</SortButton>
                        <SortButton field="date">Дата</SortButton>
                        <SortButton field="status">Статус</SortButton>
                        <SortButton field="total_amount">Сумма</SortButton>
                    </HStack>

                    {/* Карточки-строки */}
                    <VStack gap="2" align="stretch">
                        {shipments.data.map((shipment) => (
                            <Link key={shipment.id} href={`/cabinet/shipments/${shipment.id}`}>
                                <Box
                                    bg={{ base: 'white', _dark: 'gray.800' }}
                                    borderRadius="xl"
                                    border="1px solid"
                                    borderColor={{ base: 'gray.100', _dark: 'gray.700' }}
                                    p="4"
                                    _hover={{ borderColor: 'pecado.200', shadow: 'sm', _dark: { borderColor: 'pecado.700' } }}
                                    transition="all 0.15s"
                                    cursor="pointer"
                                >
                                    <Flex gap="4" align="start" justify="space-between">
                                        {/* Левая часть: номер, статус, мета */}
                                        <Box flex="1" minW="0">
                                            {/* Строка 1: номер + статус + updated_at */}
                                            <Flex gap="2" align="center" flexWrap="wrap" mb="1.5">
                                                <Text fontWeight="700" fontSize="md" fontFamily="mono" whiteSpace="nowrap" flexShrink="0">
                                                    {shipment.number}
                                                </Text>
                                                <Badge
                                                    colorPalette="gray"
                                                    variant="subtle" fontSize="2xs" px="1.5"
                                                >
                                                    Отгрузка
                                                </Badge>
                                                <Flex align="center" gap="1.5">
                                                    <Badge
                                                        colorPalette={STATUS_COLORS[shipment.status] || 'gray'}
                                                        variant="subtle" fontSize="xs" borderRadius="full" px="2.5"
                                                    >
                                                        {shipment.status_label}
                                                    </Badge>
                                                    {shipment.updated_at && (
                                                        <Text fontSize="2xs" color="gray.400">{shipment.updated_at}</Text>
                                                    )}
                                                </Flex>
                                            </Flex>

                                            {/* Строка 2: компания, позиции */}
                                            <HStack gap="3" fontSize="xs" color="gray.500" flexWrap="wrap" mb={shipment.date ? '1.5' : '0'}>
                                                {shipment.company && (
                                                    <Text fontWeight="500">{shipment.company.name}</Text>
                                                )}
                                                <Text>
                                                    {shipment.items_count} {shipment.items_count === 1 ? 'позиция' : shipment.items_count < 5 ? 'позиции' : 'позиций'}
                                                </Text>
                                            </HStack>

                                            {/* Строка 3: дата отгрузки */}
                                            {shipment.date && (
                                                <HStack gap="1" fontSize="xs" color="gray.500" minW="0">
                                                    <Box flexShrink="0" color="gray.400"><LuCalendar size={11} /></Box>
                                                    <Text noOfLines={1}>Дата отгрузки: {formatDate(shipment.date)}</Text>
                                                </HStack>
                                            )}
                                        </Box>

                                        {/* Правая часть: сумма */}
                                        <VStack gap="0" align="end" flexShrink="0">
                                            <Text fontWeight="700" fontSize="lg" fontFamily="mono" whiteSpace="nowrap">
                                                {fmt(shipment.total_converted)} {currencySymbol}
                                            </Text>
                                            {shipment.currency_code && shipment.currency_code !== currency?.code && (
                                                <Text fontSize="xs" color="gray.400" whiteSpace="nowrap">
                                                    {fmt(shipment.total_amount)} {shipment.currency_code}
                                                </Text>
                                            )}
                                        </VStack>
                                    </Flex>
                                </Box>
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
                                    if (idx > 0 && page - arr[idx - 1] > 1) acc.push('...' + page);
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
