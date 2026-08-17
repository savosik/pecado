import { useEffect, useMemo, useRef, useState } from 'react';
import {
    Box, Flex, HStack, VStack, Text, Badge, Button, Input, InputGroup,
    Card, Stack, IconButton, createListCollection,
} from '@chakra-ui/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    LuFilter, LuX, LuArrowUpDown, LuArrowUp, LuArrowDown,
    LuChevronLeft, LuChevronRight, LuSearch, LuTruck, LuCalendar,
} from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Field } from '@/components/ui/field';
import { Select } from '@/components/ui/select';
import { MenuRoot, MenuTrigger, MenuContent, MenuItem } from '@/components/ui/menu';
import SelectedFilters from '@/components/cabinet/SelectedFilters';
import MatchBadge from '@/components/cabinet/MatchBadge';
import ExportMenu from '@/components/cabinet/ExportMenu';
import { useSearchHistory } from '@/hooks/useSearchHistory';

const STATUS_COLORS = {
    new: 'blue',
    in_progress: 'orange',
    completed: 'green',
    cancelled: 'red',
};

const PAYMENT_STATUS_COLORS = {
    unpaid: 'gray',
    partial: 'orange',
    paid: 'green',
    overpaid: 'purple',
};

export default function ShipmentsIndex({ filters, statuses, paymentStatuses = [], companies = [], exportEnabled = false, suggestion = null }) {
    const { shipments, currency } = usePage().props;
    const currencySymbol = currency?.symbol ?? '₽';

    const [showFilters, setShowFilters] = useState(false);
    const [search, setSearch] = useState(filters?.search || '');
    const initialStatus = Array.isArray(filters?.status)
        ? filters.status
        : (filters?.status ? [filters.status] : []);
    const initialPaymentStatus = Array.isArray(filters?.payment_status)
        ? filters.payment_status
        : (filters?.payment_status ? [filters.payment_status] : []);
    const [localFilters, setLocalFilters] = useState({
        status: initialStatus,
        payment_status: initialPaymentStatus,
        company_id: filters?.company_id || '',
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

    const { history: searchHistory, push: pushSearchHistory } = useSearchHistory('shipments');

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

    // search уходит вместе с фильтрами: если нажать «Применить» раньше, чем
    // сработал debounce, набранный текст иначе откатился бы.
    const handleApplyFilters = () => {
        lastSubmittedSearch.current = search;
        navigateWithParams({ ...localFilters, search, page: 1 });
    };

    const handleResetFilters = () => {
        const reset = { status: [], payment_status: [], company_id: '', date_from: '', date_to: '', amount_from: '', amount_to: '' };
        setLocalFilters(reset);
        navigateWithParams({ ...reset, search: '', order_uuid: null, brand_ids: [], page: 1 });
        setSearch('');
        lastSubmittedSearch.current = '';
    };

    const handlePageChange = (page) => navigateWithParams({ page });

    const fmt = (v) => Number(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const formatDate = (iso) => iso ? new Date(iso).toLocaleDateString('ru-RU') : null;

    const sortFields = [
        { value: 'date', label: 'Дата' },
        { value: 'id', label: 'Номер' },
        { value: 'status', label: 'Статус' },
        { value: 'total_amount', label: 'Сумма' },
    ];
    const activeSort = sortFields.find((f) => f.value === filters?.sort_by);
    const sortIsActive = !!activeSort;
    const SortIcon = sortIsActive
        ? (filters?.sort_order === 'asc' ? LuArrowUp : LuArrowDown)
        : LuArrowUpDown;
    const activeFiltersCount = (() => {
        let count = 0;
        const status = filters?.status;
        if (Array.isArray(status) ? status.length > 0 : !!status) count++;
        for (const k of ['company_id', 'date_from', 'date_to', 'amount_from', 'amount_to', 'order_uuid']) {
            if (filters?.[k] !== null && filters?.[k] !== undefined && filters?.[k] !== '') count++;
        }
        if (Array.isArray(filters?.brand_ids) && filters.brand_ids.length > 0) count++;
        return count;
    })();

    const paymentStatusCollection = useMemo(
        () => createListCollection({ items: paymentStatuses?.map((s) => ({ label: s.label, value: s.value })) ?? [] }),
        [paymentStatuses],
    );

    const statusCollection = useMemo(
        () => createListCollection({ items: statuses?.map((s) => ({ label: s.label, value: s.value })) ?? [] }),
        [statuses]
    );

    const companyCollection = useMemo(
        () => createListCollection({
            items: [{ label: 'Все контрагенты', value: '' }, ...(companies?.map((c) => ({ label: c.label, value: String(c.value) })) ?? [])],
        }),
        [companies],
    );

    const filterFields = useMemo(() => [
        { key: 'search', label: 'Поиск', formatter: (v) => `«${v}»` },
        { key: 'status', label: 'Статус', formatter: (v) => statuses?.find((s) => s.value === v)?.label || v },
        { key: 'payment_status', label: 'Оплата', formatter: (v) => paymentStatuses?.find((s) => s.value === v)?.label || v },
        { key: 'company_id', label: 'Контрагент', formatter: (v) => companies?.find((c) => String(c.value) === String(v))?.label || `#${v}` },
        { key: 'date_from', label: 'Дата от' },
        { key: 'date_to', label: 'Дата до' },
        { key: 'amount_from', label: 'Сумма от' },
        { key: 'amount_to', label: 'Сумма до' },
        { key: 'order_uuid', label: 'Заказ', formatter: (v) => `#${String(v).slice(0, 8)}` },
        { key: 'brand_ids', label: 'Бренд', formatter: (v) => `#${v}` },
    ], [statuses, paymentStatuses, companies]);

    const handleRemoveFilter = (key, value) => {
        const current = filters?.[key];
        let nextValue;
        if (Array.isArray(current)) {
            nextValue = current.filter((v) => String(v) !== String(value));
        } else {
            nextValue = '';
        }
        if (key === 'status' || key === 'payment_status') {
            setLocalFilters({ ...localFilters, [key]: Array.isArray(nextValue) ? nextValue : [] });
        } else if (key === 'search') {
            setSearch('');
            lastSubmittedSearch.current = '';
        } else if (Object.prototype.hasOwnProperty.call(localFilters, key)) {
            setLocalFilters({ ...localFilters, [key]: nextValue });
        }
        navigateWithParams({ [key]: nextValue, page: 1 });
    };

    return (
        <CabinetLayout title="Мои отгрузки">
            <Head title="Мои отгрузки — Pecado" />

            {/* Поиск + фильтры + сортировка — одной строкой */}
            <Flex gap="2" mb="4" align="center">
                <Box as="form" onSubmit={handleSearch} flex="1" minW="0">
                    <InputGroup startElement={<LuSearch size={16} />} flex="1">
                        <Input
                            placeholder="Поиск по номеру, ИНН, товару, бренду, артикулу, штрихкоду…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            size="sm"
                            list="shipments-search-history"
                        />
                    </InputGroup>
                    {searchHistory.length > 0 && (
                        <datalist id="shipments-search-history">
                            {searchHistory.map((item) => (
                                <option key={item} value={item} />
                            ))}
                        </datalist>
                    )}
                </Box>

                {/* Фильтры */}
                <Button
                    onClick={() => setShowFilters(!showFilters)}
                    variant={showFilters || activeFiltersCount > 0 ? 'subtle' : 'outline'}
                    colorPalette={showFilters || activeFiltersCount > 0 ? 'pecado' : 'gray'}
                    size="sm"
                    flexShrink="0"
                    aria-label="Фильтры"
                    aria-expanded={showFilters}
                >
                    <LuFilter size={16} />
                    <Box as="span" display={{ base: 'none', md: 'inline' }}>
                        {showFilters ? 'Скрыть фильтры' : 'Фильтры'}
                    </Box>
                    {activeFiltersCount > 0 && (
                        <Badge colorPalette="pecado" variant="solid" borderRadius="full" fontSize="2xs" px="1.5" minW="4">
                            {activeFiltersCount}
                        </Badge>
                    )}
                </Button>

                {exportEnabled && (
                    <ExportMenu
                        basePath="/cabinet/shipments/export"
                        filters={{ ...filters, search }}
                    />
                )}

                {/* Сортировка */}
                <MenuRoot positioning={{ placement: 'bottom-end' }}>
                    <MenuTrigger asChild>
                        <Button
                            variant={sortIsActive ? 'subtle' : 'outline'}
                            colorPalette={sortIsActive ? 'pecado' : 'gray'}
                            size="sm"
                            flexShrink="0"
                            aria-label="Сортировка"
                        >
                            <SortIcon size={16} />
                            <Box as="span" display={{ base: 'none', md: 'inline' }}>
                                {activeSort ? activeSort.label : 'Сортировка'}
                            </Box>
                        </Button>
                    </MenuTrigger>
                    <MenuContent>
                        {sortFields.map((f) => {
                            const isActive = filters?.sort_by === f.value;
                            const ActiveIcon = filters?.sort_order === 'asc' ? LuArrowUp : LuArrowDown;
                            return (
                                <MenuItem
                                    key={f.value}
                                    value={f.value}
                                    onClick={() => handleSort(f.value)}
                                >
                                    <Flex align="center" justify="space-between" w="100%" gap="3">
                                        <Text fontWeight={isActive ? '600' : '400'}>{f.label}</Text>
                                        {isActive && (
                                            <Box color="pecado.500" _dark={{ color: 'pecado.300' }}>
                                                <ActiveIcon size={14} />
                                            </Box>
                                        )}
                                    </Flex>
                                </MenuItem>
                            );
                        })}
                    </MenuContent>
                </MenuRoot>
            </Flex>

            {/* Расширенные фильтры */}
            {showFilters && (
                <Card.Root bg="bg" mb="4" borderRadius="xl" border="1px solid" borderColor="border.muted">
                    <Card.Body p="4">
                        <Stack gap="4">
                            <Flex gap="4" direction={{ base: 'column', md: 'row' }}>
                                <Field label="Статусы" flex="1">
                                    <Select.Root
                                        multiple
                                        collection={statusCollection}
                                        value={localFilters.status}
                                        onValueChange={(e) => setLocalFilters({ ...localFilters, status: e.value })}
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Все статусы">
                                                {localFilters.status.length === 0 ? 'Все статусы' : `Выбрано: ${localFilters.status.length}`}
                                            </Select.ValueText>
                                        </Select.Trigger>
                                        <Select.Content>
                                            {statusCollection.items.map((s) => (
                                                <Select.Item key={s.value} item={s}>{s.label}</Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </Field>

                                {/* Фильтр по оплате скрыт вместе с самими цифрами:
                                    пустой справочник приходит с бэка, когда раздел закрыт. */}
                                {paymentStatuses?.length > 0 && (
                                <Field label="Оплата" flex="1">
                                    <Select.Root
                                        multiple
                                        collection={paymentStatusCollection}
                                        value={localFilters.payment_status}
                                        onValueChange={(e) => setLocalFilters({ ...localFilters, payment_status: e.value })}
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Любая">
                                                {localFilters.payment_status.length === 0 ? 'Любая' : `Выбрано: ${localFilters.payment_status.length}`}
                                            </Select.ValueText>
                                        </Select.Trigger>
                                        <Select.Content>
                                            {paymentStatusCollection.items.map((s) => (
                                                <Select.Item key={s.value} item={s}>{s.label}</Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </Field>
                                )}

                                {companies.length > 0 && (
                                    <Field label="Контрагент" flex="1">
                                        <Select.Root
                                            collection={companyCollection}
                                            value={localFilters.company_id ? [localFilters.company_id] : []}
                                            onValueChange={(e) => setLocalFilters({ ...localFilters, company_id: e.value[0] || '' })}
                                        >
                                            <Select.Trigger>
                                                <Select.ValueText placeholder="Все контрагенты" />
                                            </Select.Trigger>
                                            <Select.Content>
                                                {companyCollection.items.map((c) => (
                                                    <Select.Item key={c.value} item={c}>{c.label}</Select.Item>
                                                ))}
                                            </Select.Content>
                                        </Select.Root>
                                    </Field>
                                )}

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

            <SelectedFilters
                filters={{ ...filters, search }}
                fields={filterFields}
                onRemove={handleRemoveFilter}
                onResetAll={activeFiltersCount > 0 || search ? handleResetFilters : undefined}
            />

            {/* Список отгрузок */}
            {shipments.data.length === 0 ? (
                <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted">
                    <Card.Body p="10" textAlign="center">
                        <VStack gap="3">
                            <Flex
                                align="center" justify="center" w="16" h="16"
                                borderRadius="full" bg="bg.muted" mx="auto"
                            >
                                <LuTruck size={28} color="var(--chakra-colors-gray-400)" />
                            </Flex>
                            <Text fontWeight="600" fontSize="lg">Отгрузок пока нет</Text>
                            <Text color="gray.500" fontSize="sm">
                                Когда 1С создаст реализацию по вашему контрагенту, она появится здесь
                            </Text>
                            {suggestion && (
                                <Text color="gray.500" fontSize="sm" whiteSpace="pre-line" mt="2">
                                    {suggestion}
                                </Text>
                            )}
                        </VStack>
                    </Card.Body>
                </Card.Root>
            ) : (
                <>
                    {/* Карточки-строки */}
                    <VStack gap="2" align="stretch">
                        {shipments.data.map((shipment) => (
                            <Link key={shipment.id} href={`/cabinet/shipments/${shipment.id}`}>
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
                                                    {/* TODO: пока у отгрузок единственный статус «Выполнена» — бейдж временно скрыт
                                                    <Badge
                                                        colorPalette={STATUS_COLORS[shipment.status] || 'gray'}
                                                        variant="subtle" fontSize="xs" borderRadius="full" px="2.5"
                                                    >
                                                        {shipment.status_label}
                                                    </Badge>
                                                    */}
                                                    {/* Оплата — производное поле, его считает 1С через
                                                        разнесение платежей. Клиенту это первый вопрос
                                                        к накладной, поэтому бейдж прямо в строке. */}
                                                    {shipment.payment_status && (
                                                        <Badge
                                                            colorPalette={PAYMENT_STATUS_COLORS[shipment.payment_status] || 'gray'}
                                                            variant="subtle" fontSize="xs" borderRadius="full" px="2.5"
                                                        >
                                                            {shipment.payment_status_label}
                                                        </Badge>
                                                    )}
                                                    {shipment.updated_at && (
                                                        <Text fontSize="2xs" color="gray.400">{shipment.updated_at}</Text>
                                                    )}
                                                </Flex>
                                            </Flex>

                                            <MatchBadge
                                                source={shipment.match_source}
                                                snippet={shipment.match_snippet}
                                                search={filters.search || ''}
                                            />

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
                                            {shipment.unpaid_amount > 0 && (
                                                <Text fontSize="xs" color="orange.500" whiteSpace="nowrap">
                                                    к оплате: {fmt(shipment.unpaid_amount)} {shipment.currency_code || currencySymbol}
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
