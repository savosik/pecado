import { useEffect, useMemo, useRef, useState } from 'react';
import {
    Box, Flex, HStack, VStack, Text, Badge, Button, Input, InputGroup,
    Card, Stack, IconButton, createListCollection,
} from '@chakra-ui/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    LuFilter, LuX, LuArrowUpDown, LuArrowUp, LuArrowDown,
    LuChevronLeft, LuChevronRight, LuSearch, LuShoppingBag,
    LuPackage, LuTruck, LuClock, LuMapPin, LuStore,
} from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Field } from '@/components/ui/field';
import { Select } from '@/components/ui/select';
import { MenuRoot, MenuTrigger, MenuContent, MenuItem } from '@/components/ui/menu';
import { Tooltip } from '@/components/ui/tooltip';
import SelectedFilters from '@/components/cabinet/SelectedFilters';
import StatusQuickFilters from '@/components/cabinet/StatusQuickFilters';
import MatchBadge from '@/components/cabinet/MatchBadge';
import OrderCompositionBadge from '@/components/cabinet/OrderCompositionBadge';
import SavedSearches from '@/components/cabinet/SavedSearches';
import ExportMenu from '@/components/cabinet/ExportMenu';
import SubscriptionPanel from '@/components/cabinet/SubscriptionPanel';
import { useSearchHistory } from '@/hooks/useSearchHistory';
import { ORDER_STATUS_COLORS as STATUS_COLORS } from '@/constants/orderStatus';
import { getOrderTypeShortLabel, getOrderTypeColor } from '@/constants/orderType';

export default function OrdersIndex({ filters, statuses, statusTotal = 0, types, companies = [], presetsEnabled = false, exportEnabled = false, suggestion = null }) {
    const { orders, currency } = usePage().props;
    const currencySymbol = currency?.symbol ?? '₽';
    const [showFilters, setShowFilters] = useState(false);
    const [search, setSearch] = useState(filters?.search || '');
    // Статусы, выбранные на сервере (истина для быстрых фильтров), — в отличие от
    // localFilters.status, который меняется до нажатия «Применить».
    const selectedStatuses = Array.isArray(filters?.status)
        ? filters.status
        : (filters?.status ? [filters.status] : []);
    const [localFilters, setLocalFilters] = useState({
        status: selectedStatuses,
        type: filters?.type || '',
        company_id: filters?.company_id || '',
        date_from: filters?.date_from || '',
        date_to: filters?.date_to || '',
        amount_from: filters?.amount_from || '',
        amount_to: filters?.amount_to || '',
        items_count_from: filters?.items_count_from ?? '',
        items_count_to: filters?.items_count_to ?? '',
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

    const { history: searchHistory, push: pushSearchHistory } = useSearchHistory('orders');

    // Debounce 400 мс для поля поиска (§ «Сквозные принципы» п.3, A-7).
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
            status: [], type: '', company_id: '',
            date_from: '', date_to: '', amount_from: '', amount_to: '',
            items_count_from: '', items_count_to: '',
        };
        setLocalFilters(reset);
        navigateWithParams({ ...reset, search: '', brand_ids: [], product_id: null, page: 1 });
        setSearch('');
        lastSubmittedSearch.current = '';
    };

    const handlePageChange = (page) => {
        navigateWithParams({ page });
    };

    // Быстрые фильтры по статусу: применяются сразу, множественный выбор (ИЛИ).
    const handleToggleStatus = (value) => {
        const next = selectedStatuses.includes(value)
            ? selectedStatuses.filter((v) => v !== value)
            : [...selectedStatuses, value];
        setLocalFilters((prev) => ({ ...prev, status: next }));
        navigateWithParams({ status: next, page: 1 });
    };

    const handleResetStatus = () => {
        if (selectedStatuses.length === 0) return;
        setLocalFilters((prev) => ({ ...prev, status: [] }));
        navigateWithParams({ status: [], page: 1 });
    };

    const fmt = (v) => Number(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const sortFields = [
        { value: 'erp_created_at', label: 'Дата' },
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
        for (const k of ['type', 'company_id', 'date_from', 'date_to', 'amount_from', 'amount_to', 'items_count_from', 'items_count_to', 'product_id']) {
            if (filters?.[k] !== null && filters?.[k] !== undefined && filters?.[k] !== '') count++;
        }
        if (Array.isArray(filters?.brand_ids) && filters.brand_ids.length > 0) count++;
        return count;
    })();

    const quickStatusItems = useMemo(
        () => (statuses ?? []).map((s) => ({
            value: s.value,
            label: s.label,
            count: s.count ?? 0,
            colorPalette: STATUS_COLORS[s.value] || 'gray',
        })),
        [statuses]
    );

    // Chakra UI v3 Select требует collection — без неё клики по опциям
    // не регистрируются. Собираем коллекции из props сервера.
    const statusCollection = useMemo(
        () => createListCollection({ items: statuses?.map((s) => ({ label: s.label, value: s.value })) ?? [] }),
        [statuses]
    );
    const typeCollection = useMemo(
        () => createListCollection({
            items: [{ label: 'Все типы', value: '' }, ...(types?.map((t) => ({ label: t.label, value: t.value })) ?? [])],
        }),
        [types]
    );
    const companyCollection = useMemo(
        () => createListCollection({
            items: [{ label: 'Все контрагенты', value: '' }, ...(companies?.map((c) => ({ label: c.label, value: String(c.value) })) ?? [])],
        }),
        [companies]
    );

    const filterFields = useMemo(() => [
        { key: 'search', label: 'Поиск', formatter: (v) => `«${v}»` },
        // Статус намеренно отсутствует: выбранные статусы и так видны в быстрых
        // фильтрах над списком, снимаются там же — дублировать их чипами не нужно.
        { key: 'type', label: 'Тип', formatter: (v) => types?.find((t) => t.value === v)?.label || v },
        { key: 'company_id', label: 'Контрагент', formatter: (v) => companies?.find((c) => String(c.value) === String(v))?.label || `#${v}` },
        { key: 'date_from', label: 'Дата от' },
        { key: 'date_to', label: 'Дата до' },
        { key: 'amount_from', label: 'Сумма от' },
        { key: 'amount_to', label: 'Сумма до' },
        { key: 'items_count_from', label: 'Позиций от' },
        { key: 'items_count_to', label: 'Позиций до' },
        { key: 'brand_ids', label: 'Бренд', formatter: (v) => `#${v}` },
        { key: 'product_id', label: 'Товар', formatter: (v) => `#${v}` },
    ], [types, companies]);

    const handleRemoveFilter = (key, value) => {
        const current = filters?.[key];
        let nextValue;
        if (Array.isArray(current)) {
            nextValue = current.filter((v) => String(v) !== String(value));
        } else {
            nextValue = '';
        }
        if (key === 'search') {
            setSearch('');
            lastSubmittedSearch.current = '';
        } else if (Object.prototype.hasOwnProperty.call(localFilters, key)) {
            setLocalFilters({ ...localFilters, [key]: nextValue });
        }
        navigateWithParams({ [key]: nextValue, page: 1 });
    };

    return (
        <CabinetLayout title="Мои заказы">
            <Head title="Мои заказы — Pecado" />

            {/* Поиск + фильтры + сортировка — одной строкой */}
            <Flex gap="2" mb="4" align="center">
                <Box as="form" onSubmit={handleSearch} flex="1" minW="0">
                    <InputGroup startElement={<LuSearch size={16} />} flex="1">
                        <Input
                            placeholder="Поиск по номеру, товару, бренду, артикулу, ИНН, контрагенту…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            size="sm"
                            list="orders-search-history"
                        />
                    </InputGroup>
                    {searchHistory.length > 0 && (
                        <datalist id="orders-search-history">
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

                {presetsEnabled && (
                    <SavedSearches
                        section="orders"
                        current={{ ...filters, search }}
                        basePath="/cabinet/orders"
                    />
                )}

                {exportEnabled && (
                    <ExportMenu
                        basePath="/cabinet/orders/export"
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

            {/* Быстрые фильтры по статусу */}
            <StatusQuickFilters
                items={quickStatusItems}
                selected={selectedStatuses}
                total={statusTotal}
                onToggle={handleToggleStatus}
                onReset={handleResetStatus}
            />

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
                                                {localFilters.status.length === 0
                                                    ? 'Все статусы'
                                                    : `Выбрано: ${localFilters.status.length}`}
                                            </Select.ValueText>
                                        </Select.Trigger>
                                        <Select.Content>
                                            {statusCollection.items.map((s) => (
                                                <Select.Item key={s.value} item={s}>
                                                    {s.label}
                                                </Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </Field>

                                <Field label="Тип" flex="1">
                                    <Select.Root
                                        collection={typeCollection}
                                        value={localFilters.type ? [localFilters.type] : []}
                                        onValueChange={(e) => setLocalFilters({ ...localFilters, type: e.value[0] || '' })}
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Все типы" />
                                        </Select.Trigger>
                                        <Select.Content>
                                            {typeCollection.items.map((t) => (
                                                <Select.Item key={t.value} item={t}>
                                                    {t.label}
                                                </Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </Field>

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
                                                    <Select.Item key={c.value} item={c}>
                                                        {c.label}
                                                    </Select.Item>
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

                                <Field label="Позиций от" flex="1">
                                    <Input
                                        type="number"
                                        min="0"
                                        size="sm"
                                        value={localFilters.items_count_from}
                                        onChange={(e) => setLocalFilters({ ...localFilters, items_count_from: e.target.value })}
                                        placeholder="0"
                                    />
                                </Field>

                                <Field label="Позиций до" flex="1">
                                    <Input
                                        type="number"
                                        min="0"
                                        size="sm"
                                        value={localFilters.items_count_to}
                                        onChange={(e) => setLocalFilters({ ...localFilters, items_count_to: e.target.value })}
                                        placeholder="0"
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

            <SelectedFilters
                filters={{ ...filters, search }}
                fields={filterFields}
                onRemove={handleRemoveFilter}
                onResetAll={activeFiltersCount > 0 || search ? handleResetFilters : undefined}
            />

            {/* Список заказов */}
            {orders.data.length === 0 ? (
                <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted">
                    <Card.Body p="10" textAlign="center">
                        <VStack gap="3">
                            <Flex
                                align="center" justify="center"
                                w="16" h="16" borderRadius="full"
                                bg="bg.muted" mx="auto"
                            >
                                <LuShoppingBag size={28} color="var(--chakra-colors-gray-400)" />
                            </Flex>
                            <Text fontWeight="600" fontSize="lg">Заказов пока нет</Text>
                            <Text color="gray.500" fontSize="sm">Когда вы оформите заказ, он появится здесь</Text>
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
                        {orders.data.map((order) => {
                            const itemsLabel = order.items_count === 1
                                ? 'позиция'
                                : order.items_count < 5 ? 'позиции' : 'позиций';
                            const shipmentsLabel = order.shipments_count === 1
                                ? 'отгрузка'
                                : order.shipments_count < 5 ? 'отгрузки' : 'отгрузок';
                            const hasDiscount = Number(order.original_total_converted || 0) > Number(order.total_converted || 0);
                            const isForeignCurrency = order.currency_code && order.currency_code !== currency?.code;
                            const isPreorder = order.type === 'preorder';
                            const isDefect = order.type === 'defect';
                            // Промо-заказ может быть целиком бесплатным — это норма
                            const isFree = Number(order.total_converted || 0) <= 0;
                            const typeText = getOrderTypeShortLabel(order.type);
                            // Обычный заказ подписан нейтрально-серым, остальные — цветом типа
                            const typeTone = order.type && order.type !== 'order' ? getOrderTypeColor(order.type) : null;
                            const isPickup = order.delivery_method === 'pickup';

                            return (
                                <Link key={order.id} href={`/cabinet/orders/${order.id}`}>
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
                                        <Flex
                                            direction={{ base: 'column', md: 'row' }}
                                            gap={{ base: '3', md: '4' }}
                                            align={{ md: 'start' }}
                                            justify="space-between"
                                        >
                                            {/* Левая часть */}
                                            <Box flex="1" minW="0">
                                                {/* Мета-строка: дата/время + тип (без бейджа) */}
                                                <Flex gap="2" align="center" flexWrap="wrap" mb="1.5" fontSize="xs">
                                                    <Tooltip
                                                        content={`Обновлён: ${order.erp_updated_at || '—'}`}
                                                        positioning={{ placement: 'top' }}
                                                        openDelay={250}
                                                    >
                                                        <Flex
                                                            align="center"
                                                            gap="1"
                                                            color="gray.500"
                                                            _dark={{ color: 'gray.400' }}
                                                            fontWeight="500"
                                                        >
                                                            <LuClock size={13} />
                                                            <Text whiteSpace="nowrap">{order.erp_created_at}</Text>
                                                        </Flex>
                                                    </Tooltip>
                                                    <Text as="span" color="gray.300" _dark={{ color: 'gray.600' }}>•</Text>
                                                    <Text
                                                        as="span"
                                                        whiteSpace="nowrap"
                                                        fontWeight="600"
                                                        color={typeTone ? `${typeTone}.600` : 'gray.500'}
                                                        _dark={{ color: typeTone ? `${typeTone}.300` : 'gray.400' }}
                                                    >
                                                        {typeText}
                                                    </Text>
                                                </Flex>

                                                {/* Строка заголовка: номер + крупный статус */}
                                                <Flex gap="2.5" align="center" flexWrap="wrap" mb="1.5">
                                                    <Text
                                                        fontWeight="700"
                                                        fontSize="lg"
                                                        fontFamily="mono"
                                                        whiteSpace="nowrap"
                                                        color="gray.800"
                                                        _dark={{ color: 'gray.100' }}
                                                    >
                                                        {order.number}
                                                    </Text>
                                                    <Badge
                                                        colorPalette={STATUS_COLORS[order.status] || 'gray'}
                                                        variant="subtle"
                                                        fontSize="xs"
                                                        fontWeight="600"
                                                        px="2.5" py="1"
                                                        borderRadius="full"
                                                    >
                                                        {order.status_label}
                                                    </Badge>
                                                </Flex>

                                                <MatchBadge
                                                    source={order.match_source}
                                                    snippet={order.match_snippet}
                                                    search={filters.search || ''}
                                                />

                                                {/* Контрагент */}
                                                {order.company && (
                                                    <Text
                                                        fontSize="sm"
                                                        color="gray.600"
                                                        _dark={{ color: 'gray.400' }}
                                                        mb="2"
                                                        truncate
                                                    >
                                                        {order.company.name}
                                                    </Text>
                                                )}

                                                {/* Нижняя строка: позиции + способ доставки + отгрузки */}
                                                <Flex gap="2" align="center" flexWrap="wrap">
                                                    <Badge
                                                        variant="outline"
                                                        colorPalette="gray"
                                                        fontSize="2xs"
                                                        px="2" py="0.5"
                                                        borderRadius="full"
                                                        gap="1"
                                                    >
                                                        <LuPackage size={11} />
                                                        {order.items_count}&nbsp;{itemsLabel}
                                                    </Badge>
                                                    <Badge
                                                        variant="outline"
                                                        colorPalette="gray"
                                                        fontSize="2xs"
                                                        px="2" py="0.5"
                                                        borderRadius="full"
                                                        gap="1"
                                                    >
                                                        {isPickup ? <LuStore size={11} /> : <LuMapPin size={11} />}
                                                        {order.delivery_method_label}
                                                    </Badge>
                                                    <OrderCompositionBadge changes={order.composition_changes} />
                                                    {order.shipments_count > 0 && (
                                                        <Badge
                                                            variant="outline"
                                                            colorPalette="gray"
                                                            fontSize="2xs"
                                                            px="2" py="0.5"
                                                            borderRadius="full"
                                                            gap="1"
                                                        >
                                                            <LuTruck size={11} />
                                                            {order.shipments_count}&nbsp;{shipmentsLabel}
                                                        </Badge>
                                                    )}
                                                </Flex>
                                            </Box>

                                            {/* Правая часть: сумма */}
                                            <VStack
                                                gap="0"
                                                align={{ base: 'start', md: 'end' }}
                                                flexShrink="0"
                                                w={{ base: '100%', md: 'auto' }}
                                            >
                                                {hasDiscount && (
                                                    <Text
                                                        fontSize="xs"
                                                        color="gray.400"
                                                        fontFamily="mono"
                                                        textDecoration="line-through"
                                                        whiteSpace="nowrap"
                                                    >
                                                        {fmt(order.original_total_converted)}&nbsp;{currencySymbol}
                                                    </Text>
                                                )}
                                                <Text
                                                    fontWeight="700"
                                                    fontSize="lg"
                                                    fontFamily={isFree ? undefined : 'mono'}
                                                    whiteSpace="nowrap"
                                                    color={hasDiscount ? 'pecado.600' : 'gray.800'}
                                                    _dark={{ color: hasDiscount ? 'pecado.300' : 'gray.100' }}
                                                >
                                                    {/* «0 ₽» у промо-заказа читается как ошибка загрузки */}
                                                    {isFree ? 'Бесплатно' : `${fmt(order.total_converted)}\u00A0${currencySymbol}`}
                                                </Text>
                                                {isForeignCurrency && (
                                                    <Text fontSize="xs" color="gray.400" whiteSpace="nowrap">
                                                        {fmt(order.total_amount)}&nbsp;{order.currency_code}
                                                    </Text>
                                                )}
                                            </VStack>
                                        </Flex>
                                    </Box>
                                </Link>
                            );
                        })}
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

            <SubscriptionPanel
                section="orders"
                title="Подписка на изменения заказов"
                description="Добавьте email-адреса, которые будут получать письма при любом изменении ваших заказов (состав, количество, суммы). Удобно подключить бухгалтера или менеджера."
            />
        </CabinetLayout>
    );
}
