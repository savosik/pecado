import { useState } from 'react';
import {
    Box, Flex, HStack, VStack, Text, Badge, Button, Input,
    Card, Stack, IconButton,
} from '@chakra-ui/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    LuFilter, LuX, LuArrowUpDown, LuArrowUp, LuArrowDown,
    LuChevronLeft, LuChevronRight, LuSearch, LuShoppingBag,
    LuMapPin, LuMessageSquare,
} from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Field } from '@/components/ui/field';
import { Select } from '@/components/ui/select';

const STATUS_COLORS = {
    pending: 'yellow',
    confirmed: 'blue',
    ready_to_ship: 'purple',
    closed: 'green',
    deleted: 'red',
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

    const fmt = (v) => Number(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

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

            {/* Список заказов */}
            {orders.data.length === 0 ? (
                <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}>
                    <Card.Body p="10" textAlign="center">
                        <VStack gap="3">
                            <Flex
                                align="center" justify="center"
                                w="16" h="16" borderRadius="full"
                                bg="gray.100" _dark={{ bg: 'gray.700' }} mx="auto"
                            >
                                <LuShoppingBag size={28} color="var(--chakra-colors-gray-400)" />
                            </Flex>
                            <Text fontWeight="600" fontSize="lg">Заказов пока нет</Text>
                            <Text color="gray.500" fontSize="sm">Когда вы оформите заказ, он появится здесь</Text>
                        </VStack>
                    </Card.Body>
                </Card.Root>
            ) : (
                <>
                    {/* Сортировка */}
                    <HStack gap="1" mb="3" px="1" flexWrap="wrap">
                        <Text fontSize="xs" color="gray.400" mr="1">Сортировка:</Text>
                        <SortButton field="id">Номер</SortButton>
                        <SortButton field="created_at">Дата</SortButton>
                        <SortButton field="status">Статус</SortButton>
                        <SortButton field="total_amount">Сумма</SortButton>
                    </HStack>

                    {/* Карточки-строки */}
                    <VStack gap="2" align="stretch">
                        {orders.data.map((order) => (
                            <Link key={order.id} href={`/cabinet/orders/${order.id}`}>
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
                                            {/* Строка 1: номер + бейджи */}
                                            <Flex gap="2" align="center" flexWrap="wrap" mb="1.5">
                                                <Text fontWeight="700" fontSize="md" fontFamily="mono" whiteSpace="nowrap" flexShrink="0">
                                                    {order.number}
                                                </Text>
                                                <Badge
                                                    colorPalette={order.type === 'preorder' ? 'purple' : 'gray'}
                                                    variant="subtle" fontSize="2xs" px="1.5"
                                                >
                                                    {order.type === 'preorder' ? 'Предзаказ' : 'Заказ'}
                                                </Badge>
                                                <Flex align="center" gap="1.5">
                                                    <Badge
                                                        colorPalette={STATUS_COLORS[order.status] || 'gray'}
                                                        variant="subtle" fontSize="xs" borderRadius="full" px="2.5"
                                                    >
                                                        {order.status_label}
                                                    </Badge>
                                                    <Text fontSize="2xs" color="gray.400">{order.updated_at}</Text>
                                                </Flex>
                                            </Flex>

                                            {/* Строка 2: компания, позиции, отгрузки */}
                                            <HStack gap="3" fontSize="xs" color="gray.500" flexWrap="wrap" mb={order.delivery_address || order.comment ? '1.5' : '0'}>
                                                {order.company && (
                                                    <Text fontWeight="500">{order.company.name}</Text>
                                                )}
                                                <Text>{order.items_count} {order.items_count === 1 ? 'позиция' : order.items_count < 5 ? 'позиции' : 'позиций'}</Text>
                                                {order.shipments_count > 0 && (
                                                    <Text>{order.shipments_count} {order.shipments_count === 1 ? 'отгрузка' : order.shipments_count < 5 ? 'отгрузки' : 'отгрузок'}</Text>
                                                )}
                                            </HStack>

                                            {/* Строка 3: адрес */}
                                            {order.delivery_address && (
                                                <HStack gap="1" fontSize="xs" color="gray.500" mb="1" minW="0">
                                                    <Box flexShrink="0" color="gray.400"><LuMapPin size={11} /></Box>
                                                    <Text noOfLines={1}>{order.delivery_address}</Text>
                                                </HStack>
                                            )}

                                            {/* Строка 4: комментарий */}
                                            {order.comment && (
                                                <HStack gap="1" fontSize="xs" color="gray.400" minW="0">
                                                    <Box flexShrink="0"><LuMessageSquare size={11} /></Box>
                                                    <Text noOfLines={1} fontStyle="italic">{order.comment}</Text>
                                                </HStack>
                                            )}
                                        </Box>

                                        {/* Правая часть: сумма */}
                                        <VStack gap="0" align="end" flexShrink="0">
                                            {Number(order.original_total_converted || 0) > Number(order.total_converted || 0) && (
                                                <Text
                                                    fontSize="xs"
                                                    color="gray.400"
                                                    fontFamily="mono"
                                                    textDecoration="line-through"
                                                    whiteSpace="nowrap"
                                                >
                                                    {fmt(order.original_total_converted)} {currencySymbol}
                                                </Text>
                                            )}
                                            <Text fontWeight="700" fontSize="lg" fontFamily="mono" whiteSpace="nowrap">
                                                {fmt(order.total_converted)} {currencySymbol}
                                            </Text>
                                            {order.currency_code && order.currency_code !== currency?.code && (
                                                <Text fontSize="xs" color="gray.400" whiteSpace="nowrap">
                                                    {fmt(order.total_amount)} {order.currency_code}
                                                </Text>
                                            )}
                                        </VStack>
                                    </Flex>
                                </Box>
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
