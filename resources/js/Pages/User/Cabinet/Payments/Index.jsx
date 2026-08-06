import { useEffect, useMemo, useRef, useState } from 'react';
import {
    Box, Flex, HStack, VStack, Text, Badge, Button, Input, InputGroup,
    Card, Stack, createListCollection,
} from '@chakra-ui/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    LuFilter, LuX, LuSearch, LuReceipt, LuCalendar,
    LuChevronLeft, LuChevronRight,
} from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Field } from '@/components/ui/field';
import { Select } from '@/components/ui/select';
import SelectedFilters from '@/components/cabinet/SelectedFilters';
import ExportMenu from '@/components/cabinet/ExportMenu';
import { useSearchHistory } from '@/hooks/useSearchHistory';

const ALLOCATION_COLORS = {
    allocated: 'green',
    partial: 'orange',
    advance: 'blue',
};

/**
 * Оплаты клиента. Только чтение: платёж заводит 1С.
 */
export default function PaymentsIndex({ filters, directions = [], allocationStatuses = [], exportEnabled = false }) {
    const { payments, currency } = usePage().props;
    const currencySymbol = currency?.symbol ?? '₽';

    const [showFilters, setShowFilters] = useState(false);
    const [search, setSearch] = useState(filters?.search || '');

    const asArray = (value) => (Array.isArray(value) ? value : (value ? [value] : []));

    const [localFilters, setLocalFilters] = useState({
        direction: asArray(filters?.direction),
        allocation_status: asArray(filters?.allocation_status),
        date_from: filters?.date_from || '',
        date_to: filters?.date_to || '',
        amount_from: filters?.amount_from || '',
        amount_to: filters?.amount_to || '',
    });

    const navigateWithParams = (params) => {
        router.get('/cabinet/payments', { ...filters, ...params }, {
            preserveState: true,
            replace: true,
        });
    };

    const { push: pushSearchHistory } = useSearchHistory('payments');

    // Debounce 400 мс — как в остальных разделах кабинета.
    const lastSubmittedSearch = useRef(filters?.search || '');
    useEffect(() => {
        if (search === lastSubmittedSearch.current) return undefined;

        const handle = setTimeout(() => {
            lastSubmittedSearch.current = search;
            pushSearchHistory(search);
            navigateWithParams({ search, page: 1 });
        }, 400);

        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const directionCollection = useMemo(
        () => createListCollection({ items: directions.map((d) => ({ label: d.label, value: d.value })) }),
        [directions],
    );

    const allocationCollection = useMemo(
        () => createListCollection({ items: allocationStatuses.map((a) => ({ label: a.label, value: a.value })) }),
        [allocationStatuses],
    );

    const filterFields = useMemo(() => [
        { key: 'search', label: 'Поиск', formatter: (v) => `«${v}»` },
        { key: 'direction', label: 'Направление', formatter: (v) => directions.find((d) => d.value === v)?.label || v },
        { key: 'allocation_status', label: 'Разнесение', formatter: (v) => allocationStatuses.find((a) => a.value === v)?.label || v },
        { key: 'date_from', label: 'Дата от' },
        { key: 'date_to', label: 'Дата до' },
        { key: 'amount_from', label: 'Сумма от' },
        { key: 'amount_to', label: 'Сумма до' },
    ], [directions, allocationStatuses]);

    const handleApplyFilters = () => navigateWithParams({ ...localFilters, page: 1 });

    const handleResetFilters = () => {
        const reset = {
            direction: [], allocation_status: [],
            date_from: '', date_to: '', amount_from: '', amount_to: '',
        };
        setLocalFilters(reset);
        navigateWithParams({ ...reset, search: '', page: 1 });
        setSearch('');
        lastSubmittedSearch.current = '';
    };

    const handleRemoveFilter = (key, value) => {
        const current = filters?.[key];
        const nextValue = Array.isArray(current)
            ? current.filter((item) => String(item) !== String(value))
            : '';

        if (key === 'direction' || key === 'allocation_status') {
            setLocalFilters({ ...localFilters, [key]: Array.isArray(nextValue) ? nextValue : [] });
        }
        if (key === 'search') {
            setSearch('');
            lastSubmittedSearch.current = '';
        }

        navigateWithParams({ [key]: nextValue, page: 1 });
    };

    const activeFiltersCount = [
        localFilters.direction, localFilters.allocation_status,
    ].reduce((sum, list) => sum + list.length, 0)
        + ['date_from', 'date_to', 'amount_from', 'amount_to'].filter((key) => filters?.[key]).length;

    const fmt = (v) => Number(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return (
        <CabinetLayout title="Оплаты">
            <Head title="Оплаты — Pecado" />

            <Flex gap="3" mb="4" direction={{ base: 'column', sm: 'row' }} align={{ sm: 'center' }}>
                <Box flex="1">
                    <InputGroup startElement={<LuSearch size={16} />}>
                        <Input
                            size="sm"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Номер платежа, номер по банку, УИП или номер отгрузки…"
                        />
                    </InputGroup>
                </Box>

                <HStack gap="2">
                    <Button onClick={() => setShowFilters(!showFilters)} variant="outline" size="sm">
                        <LuFilter size={16} />
                        {showFilters ? 'Скрыть фильтры' : 'Фильтры'}
                        {activeFiltersCount > 0 && (
                            <Badge colorPalette="pecado" variant="solid" borderRadius="full" fontSize="2xs" px="1.5" minW="4">
                                {activeFiltersCount}
                            </Badge>
                        )}
                    </Button>

                    {exportEnabled && <ExportMenu baseUrl="/cabinet/payments/export" params={filters} />}
                </HStack>
            </Flex>

            {showFilters && (
                <Card.Root bg="bg" mb="4" borderRadius="xl" border="1px solid" borderColor="border.muted">
                    <Card.Body p="4">
                        <Stack gap="4">
                            <Flex gap="4" direction={{ base: 'column', md: 'row' }}>
                                <Field label="Направление" flex="1">
                                    <Select.Root
                                        multiple
                                        collection={directionCollection}
                                        value={localFilters.direction}
                                        onValueChange={(e) => setLocalFilters({ ...localFilters, direction: e.value })}
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Все">
                                                {localFilters.direction.length === 0 ? 'Все' : `Выбрано: ${localFilters.direction.length}`}
                                            </Select.ValueText>
                                        </Select.Trigger>
                                        <Select.Content>
                                            {directionCollection.items.map((item) => (
                                                <Select.Item key={item.value} item={item}>{item.label}</Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </Field>

                                <Field label="Разнесение" flex="1">
                                    <Select.Root
                                        multiple
                                        collection={allocationCollection}
                                        value={localFilters.allocation_status}
                                        onValueChange={(e) => setLocalFilters({ ...localFilters, allocation_status: e.value })}
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Любое">
                                                {localFilters.allocation_status.length === 0 ? 'Любое' : `Выбрано: ${localFilters.allocation_status.length}`}
                                            </Select.ValueText>
                                        </Select.Trigger>
                                        <Select.Content>
                                            {allocationCollection.items.map((item) => (
                                                <Select.Item key={item.value} item={item}>{item.label}</Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </Field>

                                <Field label="Дата от" flex="1">
                                    <Input type="date" size="sm" value={localFilters.date_from}
                                        onChange={(e) => setLocalFilters({ ...localFilters, date_from: e.target.value })} />
                                </Field>

                                <Field label="Дата до" flex="1">
                                    <Input type="date" size="sm" value={localFilters.date_to}
                                        onChange={(e) => setLocalFilters({ ...localFilters, date_to: e.target.value })} />
                                </Field>
                            </Flex>

                            <Flex gap="4" direction={{ base: 'column', md: 'row' }} align="end">
                                <Field label="Сумма от" flex="1">
                                    <Input type="number" step="0.01" size="sm" value={localFilters.amount_from}
                                        onChange={(e) => setLocalFilters({ ...localFilters, amount_from: e.target.value })}
                                        placeholder="0.00" />
                                </Field>

                                <Field label="Сумма до" flex="1">
                                    <Input type="number" step="0.01" size="sm" value={localFilters.amount_to}
                                        onChange={(e) => setLocalFilters({ ...localFilters, amount_to: e.target.value })}
                                        placeholder="0.00" />
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

            <SelectedFilters
                filters={{ ...filters, search }}
                fields={filterFields}
                onRemove={handleRemoveFilter}
                onResetAll={activeFiltersCount > 0 || search ? handleResetFilters : undefined}
            />

            {payments.data.length === 0 ? (
                <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted">
                    <Card.Body p="10" textAlign="center">
                        <VStack gap="3">
                            <Flex align="center" justify="center" w="16" h="16" borderRadius="full" bg="bg.muted" mx="auto">
                                <LuReceipt size={28} color="var(--chakra-colors-gray-400)" />
                            </Flex>
                            <Text fontWeight="600" fontSize="lg">Оплат пока нет</Text>
                            <Text color="gray.500" fontSize="sm">
                                Когда ваш платёж будет проведён в 1С, он появится здесь
                            </Text>
                        </VStack>
                    </Card.Body>
                </Card.Root>
            ) : (
                <>
                    <VStack gap="2" align="stretch">
                        {payments.data.map((payment) => (
                            <Link key={payment.id} href={`/cabinet/payments/${payment.id}`}>
                                <Box
                                    bg="bg"
                                    borderRadius="xl"
                                    border="1px solid"
                                    borderColor="border.muted"
                                    p="4"
                                    _hover={{ borderColor: 'pecado.200', shadow: 'sm', _dark: { borderColor: 'pecado.700' } }}
                                    transition="all 0.15s"
                                    cursor="pointer"
                                >
                                    <Flex gap="4" align="start" justify="space-between">
                                        <Box flex="1" minW="0">
                                            <Flex gap="2" align="center" flexWrap="wrap" mb="1.5">
                                                <Text fontWeight="700" fontSize="md" fontFamily="mono" whiteSpace="nowrap">
                                                    {payment.number || `#${payment.id}`}
                                                </Text>
                                                <Badge
                                                    colorPalette={payment.direction === 'out' ? 'red' : 'green'}
                                                    variant="subtle" fontSize="2xs" px="1.5"
                                                >
                                                    {payment.direction_label}
                                                </Badge>
                                                <Badge
                                                    colorPalette={ALLOCATION_COLORS[payment.allocation_status] || 'gray'}
                                                    variant="subtle" fontSize="xs" borderRadius="full" px="2.5"
                                                >
                                                    {payment.allocation_status_label}
                                                </Badge>
                                            </Flex>

                                            <HStack gap="3" fontSize="xs" color="gray.500" flexWrap="wrap" mb="1.5">
                                                {payment.company_name && <Text fontWeight="500">{payment.company_name}</Text>}
                                                {payment.bank_number && <Text>по банку: {payment.bank_number}</Text>}
                                                <Text>
                                                    {payment.allocations_count === 0
                                                        ? 'без разнесения'
                                                        : `отгрузок: ${payment.allocations_count}`}
                                                </Text>
                                            </HStack>

                                            <HStack gap="1" fontSize="xs" color="gray.500" minW="0">
                                                <Box flexShrink="0" color="gray.400"><LuCalendar size={11} /></Box>
                                                <Text>Дата платежа: {payment.date_label}</Text>
                                            </HStack>
                                        </Box>

                                        <VStack gap="0" align="end" flexShrink="0">
                                            <Text fontWeight="700" fontSize="lg" fontFamily="mono" whiteSpace="nowrap">
                                                {fmt(payment.amount_converted)} {currencySymbol}
                                            </Text>
                                            {payment.currency_code && payment.currency_code !== currency?.code && (
                                                <Text fontSize="xs" color="gray.400" whiteSpace="nowrap">
                                                    {fmt(payment.amount)} {payment.currency_code}
                                                </Text>
                                            )}
                                            {payment.unallocated_amount > 0 && (
                                                <Text fontSize="xs" color="blue.500" whiteSpace="nowrap">
                                                    аванс: {fmt(payment.unallocated_amount)} {payment.currency_code || currencySymbol}
                                                </Text>
                                            )}
                                        </VStack>
                                    </Flex>
                                </Box>
                            </Link>
                        ))}
                    </VStack>

                    {payments.last_page > 1 && (
                        <Flex justify="center" align="center" gap="2" mt="6">
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={payments.current_page === 1}
                                onClick={() => navigateWithParams({ page: payments.current_page - 1 })}
                            >
                                <LuChevronLeft size={16} /> Назад
                            </Button>
                            <Text fontSize="sm" color="gray.500">
                                Страница {payments.current_page} из {payments.last_page}
                            </Text>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={payments.current_page === payments.last_page}
                                onClick={() => navigateWithParams({ page: payments.current_page + 1 })}
                            >
                                Вперёд <LuChevronRight size={16} />
                            </Button>
                        </Flex>
                    )}
                </>
            )}
        </CabinetLayout>
    );
}
