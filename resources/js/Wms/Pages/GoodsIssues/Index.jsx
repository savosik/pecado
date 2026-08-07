import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Badge,
    Box,
    Card,
    HStack,
    Input,
    SimpleGrid,
    Table,
    Text,
    VStack,
} from '@chakra-ui/react';
import { LuFileSpreadsheet, LuPackageSearch, LuTimer, LuTriangleAlert } from 'react-icons/lu';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Pagination } from '@/Admin/Components/Pagination';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import MultiSelectFilter from '@/Crm/Components/MultiSelectFilter';
import { usePermission } from '@/Admin/hooks/usePermission';

const SORT_OPTIONS = [
    { value: 'date', label: 'По дате документа' },
    { value: 'shipment_date', label: 'По дате отгрузки' },
    { value: 'status_changed_at', label: 'По дате смены статуса' },
    { value: 'number', label: 'По номеру' },
    { value: 'items_count', label: 'По числу позиций' },
    { value: 'packages_count', label: 'По числу мест' },
];

/** Плитка-счётчик, она же переключатель фильтра по статусу. */
function StatTile({ label, value, hint, tone, active, onClick }) {
    return (
        <Card.Root
            cursor="pointer"
            onClick={onClick}
            borderColor={active ? 'colorPalette.solid' : 'border'}
            borderWidth={active ? '2px' : '1px'}
            colorPalette={tone}
            _hover={{ borderColor: 'colorPalette.solid' }}
        >
            <Card.Body py={3}>
                <Text fontSize="xs" color="fg.muted" lineClamp={1}>{label}</Text>
                <Text fontSize="2xl" fontWeight="bold">{value}</Text>
                {hint && <Text fontSize="xs" color="fg.muted" lineClamp={1}>{hint}</Text>}
            </Card.Body>
        </Card.Root>
    );
}

function StatusBadge({ order }) {
    return (
        <HStack gap={1}>
            <Badge colorPalette={order.status_color} size="sm">{order.status_label}</Badge>
            {order.is_stale && (
                <Box title="Ордер висит в этом статусе дольше суток" color="orange.500" display="flex">
                    <LuTimer size={14} />
                </Box>
            )}
        </HStack>
    );
}

/**
 * Строка журнала карточкой — мобильный вариант: таблица из десяти колонок
 * на складском телефоне не читается, а горизонтальный скролл одной рукой не листают.
 */
function OrderMobileCard({ order }) {
    return (
        <Box borderWidth="1px" borderColor="border" borderRadius="md" p={3}>
            <VStack align="stretch" gap={2}>
                <HStack justify="space-between" align="start">
                    <Link href={order.url}>
                        <Text fontSize="sm" fontWeight="bold">{order.number}</Text>
                    </Link>
                    <StatusBadge order={order} />
                </HStack>

                <Text fontSize="sm" lineClamp={2}>{order.recipient}</Text>

                <HStack gap={4} flexWrap="wrap" fontSize="xs" color="fg.muted">
                    <Text>{order.date_label || '—'}</Text>
                    {order.warehouse && <Text>{order.warehouse}</Text>}
                    {order.responsible && <Text>{order.responsible}</Text>}
                </HStack>

                <HStack gap={4} flexWrap="wrap" fontSize="sm">
                    <Text><Text as="span" color="fg.muted">Позиций: </Text>{order.items_count}</Text>
                    <Text><Text as="span" color="fg.muted">Кол-во: </Text>{order.total_quantity}</Text>
                    <Text><Text as="span" color="fg.muted">Мест: </Text>{order.packages_count}</Text>
                </HStack>

                {order.unresolved_items_count > 0 && (
                    <HStack gap={1} color="orange.500" fontSize="xs">
                        <LuTriangleAlert size={12} />
                        <Text>{order.unresolved_items_count} позиций нет в каталоге сайта</Text>
                    </HStack>
                )}
            </VStack>
        </Box>
    );
}

export default function GoodsIssuesIndex() {
    const { orders, filters, stats, options, sort, perPage, staleHours } = usePage().props;
    const { can } = usePermission();
    const canExport = can('wms-goods-issues.export');

    const [search, setSearch] = useState(filters.search || '');

    const applyFilters = (next) => {
        router.get('/wms/goods-issues', {
            ...filters,
            sort: sort.by,
            direction: sort.order,
            per_page: perPage,
            ...next,
            page: 1,
        }, { preserveState: true, replace: true });
    };

    /** Клик по плитке статуса: повторный клик снимает фильтр. */
    const toggleStatus = (status) => {
        const selected = filters.statuses || [];
        const next = selected.length === 1 && selected[0] === status ? [] : [status];
        applyFilters({ statuses: next });
    };

    const exportUrl = `/wms/goods-issues/export?${new URLSearchParams(
        Object.entries({ ...filters, sort: sort.by, direction: sort.order })
            .flatMap(([key, value]) => Array.isArray(value)
                ? value.map((item) => [key + '[]', item])
                : [[key, value === true ? '1' : value === false ? '' : value]])
            .filter(([, value]) => value !== '' && value !== null && value !== undefined),
    ).toString()}`;

    const hasRows = orders.data.length > 0;

    return (
        <>
            <Head title="Расходные ордера — Склад" />
            <PageHeader
                title="Расходные ордера"
                description="Складские документы отгрузки из 1С: что в отборе, что на проверке, что готово к погрузке."
                actions={canExport && hasRows && (
                    <Button asChild size="sm" variant="outline">
                        <a href={exportUrl}>
                            <LuFileSpreadsheet /> Выгрузить в Excel
                        </a>
                    </Button>
                )}
            />

            <VStack gap={4} align="stretch">
                <SimpleGrid columns={{ base: 2, md: 4, xl: 8 }} gap={3}>
                    {stats.by_status.map((item) => (
                        <StatTile
                            key={item.value}
                            label={item.label}
                            value={item.count}
                            tone={item.color}
                            active={(filters.statuses || []).length === 1 && filters.statuses[0] === item.value}
                            onClick={() => toggleStatus(item.value)}
                        />
                    ))}
                    <StatTile
                        label="Зависшие"
                        value={stats.stale}
                        hint={`дольше ${staleHours} ч`}
                        tone={stats.stale > 0 ? 'orange' : 'gray'}
                        active={filters.stale}
                        onClick={() => applyFilters({ stale: !filters.stale })}
                    />
                    <StatTile
                        label="Нет в каталоге"
                        value={stats.unresolved}
                        hint="позиции без товара"
                        tone={stats.unresolved > 0 ? 'red' : 'gray'}
                        active={filters.unresolved}
                        onClick={() => applyFilters({ unresolved: !filters.unresolved })}
                    />
                </SimpleGrid>

                <Card.Root>
                    <Card.Body>
                        <VStack gap={3} align="stretch">
                            <HStack gap={2} flexWrap="wrap" align="end">
                                <Box flex="1" minW={{ base: '100%', md: '260px' }}>
                                    <SearchInput
                                        value={search}
                                        onChange={(value) => {
                                            setSearch(value);
                                            applyFilters({ search: value });
                                        }}
                                        placeholder="Номер ордера, заказа, получатель, товар..."
                                    />
                                </Box>

                                <MultiSelectFilter
                                    label="Статус"
                                    options={options.statuses}
                                    selectedIds={filters.statuses || []}
                                    onChange={(next) => applyFilters({ statuses: next })}
                                    idKey="value"
                                    labelKey="label"
                                    allLabel="Все статусы"
                                    minW="180px"
                                />

                                <MultiSelectFilter
                                    label="Склад"
                                    options={options.warehouses}
                                    selectedIds={filters.warehouse_ids || []}
                                    onChange={(next) => applyFilters({ warehouse_ids: next })}
                                    allLabel="Все склады"
                                    minW="180px"
                                    disabled={options.warehouses.length === 0}
                                />

                                <MultiSelectFilter
                                    label="Ответственный"
                                    options={options.responsibles}
                                    selectedIds={filters.responsibles || []}
                                    onChange={(next) => applyFilters({ responsibles: next })}
                                    allLabel="Все ответственные"
                                    minW="200px"
                                    disabled={options.responsibles.length === 0}
                                />
                            </HStack>

                            <HStack gap={2} flexWrap="wrap" align="end">
                                <VStack align="stretch" gap={1} minW="150px">
                                    <Text fontSize="xs" color="fg.muted" fontWeight="500">Приоритет</Text>
                                    <NativeSelectRoot size="sm">
                                        <NativeSelectField
                                            value={filters.priority || ''}
                                            onChange={(event) => applyFilters({ priority: event.target.value })}
                                        >
                                            <option value="">Любой</option>
                                            {options.priorities.map((item) => (
                                                <option key={item.value} value={item.value}>{item.label}</option>
                                            ))}
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                </VStack>

                                <VStack align="stretch" gap={1} minW="150px">
                                    <Text fontSize="xs" color="fg.muted" fontWeight="500">Доставка</Text>
                                    <NativeSelectRoot size="sm">
                                        <NativeSelectField
                                            value={filters.delivery_type || ''}
                                            onChange={(event) => applyFilters({ delivery_type: event.target.value })}
                                        >
                                            <option value="">Любая</option>
                                            {options.deliveryTypes.map((item) => (
                                                <option key={item.value} value={item.value}>{item.label}</option>
                                            ))}
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                </VStack>

                                <VStack align="stretch" gap={1} minW="230px">
                                    <Text fontSize="xs" color="fg.muted" fontWeight="500">Дата документа</Text>
                                    <HStack gap={1}>
                                        <Input
                                            size="sm"
                                            type="date"
                                            value={filters.date_from || ''}
                                            onChange={(event) => applyFilters({ date_from: event.target.value })}
                                        />
                                        <Input
                                            size="sm"
                                            type="date"
                                            value={filters.date_to || ''}
                                            onChange={(event) => applyFilters({ date_to: event.target.value })}
                                        />
                                    </HStack>
                                </VStack>

                                <VStack align="stretch" gap={1} minW="230px">
                                    <Text fontSize="xs" color="fg.muted" fontWeight="500">Дата отгрузки</Text>
                                    <HStack gap={1}>
                                        <Input
                                            size="sm"
                                            type="date"
                                            value={filters.ship_from || ''}
                                            onChange={(event) => applyFilters({ ship_from: event.target.value })}
                                        />
                                        <Input
                                            size="sm"
                                            type="date"
                                            value={filters.ship_to || ''}
                                            onChange={(event) => applyFilters({ ship_to: event.target.value })}
                                        />
                                    </HStack>
                                </VStack>

                                <VStack align="stretch" gap={1} minW="200px">
                                    <Text fontSize="xs" color="fg.muted" fontWeight="500">Сортировка</Text>
                                    <HStack gap={1}>
                                        <NativeSelectRoot size="sm">
                                            <NativeSelectField
                                                value={sort.by}
                                                onChange={(event) => applyFilters({ sort: event.target.value })}
                                            >
                                                {SORT_OPTIONS.map((item) => (
                                                    <option key={item.value} value={item.value}>{item.label}</option>
                                                ))}
                                            </NativeSelectField>
                                        </NativeSelectRoot>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => applyFilters({ direction: sort.order === 'asc' ? 'desc' : 'asc' })}
                                        >
                                            {sort.order === 'asc' ? '↑' : '↓'}
                                        </Button>
                                    </HStack>
                                </VStack>
                            </HStack>

                            <HStack gap={4} flexWrap="wrap">
                                <Checkbox
                                    size="sm"
                                    checked={!!filters.stale}
                                    onCheckedChange={() => applyFilters({ stale: !filters.stale })}
                                >
                                    <Text fontSize="sm">Только зависшие (дольше {staleHours} ч)</Text>
                                </Checkbox>
                                <Checkbox
                                    size="sm"
                                    checked={!!filters.unresolved}
                                    onCheckedChange={() => applyFilters({ unresolved: !filters.unresolved })}
                                >
                                    <Text fontSize="sm">С позициями без товара в каталоге</Text>
                                </Checkbox>
                            </HStack>
                        </VStack>
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Body>
                        {!hasRows ? (
                            <VStack py={10} gap={2} color="fg.muted">
                                <LuPackageSearch size={32} />
                                <Text fontSize="sm">Расходных ордеров не найдено</Text>
                                <Text fontSize="xs">
                                    Либо не подходит ни один под фильтры, либо 1С ещё не выгружала ордера на сайт.
                                </Text>
                            </VStack>
                        ) : (
                            <>
                                <Box display={{ base: 'block', lg: 'none' }}>
                                    <VStack align="stretch" gap={2}>
                                        {orders.data.map((order) => (
                                            <OrderMobileCard key={order.id} order={order} />
                                        ))}
                                    </VStack>
                                </Box>

                                <Box display={{ base: 'none', lg: 'block' }} overflowX="auto">
                                    <Table.Root size="sm" interactive>
                                        <Table.Header>
                                            <Table.Row>
                                                <Table.ColumnHeader>Номер</Table.ColumnHeader>
                                                <Table.ColumnHeader>Дата</Table.ColumnHeader>
                                                <Table.ColumnHeader>Отгрузка</Table.ColumnHeader>
                                                <Table.ColumnHeader>Статус</Table.ColumnHeader>
                                                <Table.ColumnHeader>Получатель</Table.ColumnHeader>
                                                <Table.ColumnHeader>Склад</Table.ColumnHeader>
                                                <Table.ColumnHeader>Ответственный</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="end">Позиций</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="end">Кол-во</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="end">Мест</Table.ColumnHeader>
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {orders.data.map((order) => (
                                                <Table.Row key={order.id}>
                                                    <Table.Cell>
                                                        <Link href={order.url}>
                                                            <Text fontSize="sm" fontWeight="medium" color="colorPalette.fg">
                                                                {order.number}
                                                            </Text>
                                                        </Link>
                                                        {order.unresolved_items_count > 0 && (
                                                            <HStack gap={1} color="orange.500">
                                                                <LuTriangleAlert size={11} />
                                                                <Text fontSize="xs">{order.unresolved_items_count} без товара</Text>
                                                            </HStack>
                                                        )}
                                                    </Table.Cell>
                                                    <Table.Cell fontSize="sm" whiteSpace="nowrap">{order.date_label || '—'}</Table.Cell>
                                                    <Table.Cell fontSize="sm" whiteSpace="nowrap">{order.shipment_date_label || '—'}</Table.Cell>
                                                    <Table.Cell>
                                                        <StatusBadge order={order} />
                                                        {order.status_changed_label && (
                                                            <Text fontSize="xs" color="fg.muted">{order.status_changed_label}</Text>
                                                        )}
                                                    </Table.Cell>
                                                    <Table.Cell fontSize="sm" maxW="260px">
                                                        <Text lineClamp={2}>{order.recipient}</Text>
                                                    </Table.Cell>
                                                    <Table.Cell fontSize="sm">{order.warehouse || '—'}</Table.Cell>
                                                    <Table.Cell fontSize="sm">{order.responsible || '—'}</Table.Cell>
                                                    <Table.Cell textAlign="end" fontSize="sm" fontVariantNumeric="tabular-nums">
                                                        {order.items_count}
                                                    </Table.Cell>
                                                    <Table.Cell textAlign="end" fontSize="sm" fontVariantNumeric="tabular-nums">
                                                        {order.total_quantity}
                                                    </Table.Cell>
                                                    <Table.Cell textAlign="end" fontSize="sm" fontVariantNumeric="tabular-nums">
                                                        {order.packages_count}
                                                    </Table.Cell>
                                                </Table.Row>
                                            ))}
                                        </Table.Body>
                                    </Table.Root>
                                </Box>

                                <Pagination
                                    pagination={orders}
                                    perPage={perPage}
                                    onPerPageChange={(value) => applyFilters({ per_page: value })}
                                    onPageChange={(page) => router.get('/wms/goods-issues', {
                                        ...filters,
                                        sort: sort.by,
                                        direction: sort.order,
                                        per_page: perPage,
                                        page,
                                    }, { preserveState: true, replace: true })}
                                />
                            </>
                        )}
                    </Card.Body>
                </Card.Root>
            </VStack>
        </>
    );
}

GoodsIssuesIndex.layout = (page) => <WmsLayout>{page}</WmsLayout>;
