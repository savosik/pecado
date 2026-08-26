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
import { LuExternalLink, LuPackagePlus, LuTruck } from 'react-icons/lu';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Pagination } from '@/Admin/Components/Pagination';
import { Button } from '@/components/ui/button';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import MultiSelectFilter from '@/Crm/Components/MultiSelectFilter';
import RowActions from '@/shared/Panel/RowActions';
import { ConfirmDialog } from '@/shared/Panel/ConfirmDialog';
import { useConfirmDelete } from '@/shared/Panel/useConfirmDelete';
import { usePermission } from '@/shared/Panel/usePermission';
import { formatMoney, formatWeight } from '@/Wms/Components/deliveryFormat';

const SORT_OPTIONS = [
    { value: 'created_at', label: 'По дате создания' },
    { value: 'submitted_at', label: 'По дате передачи в ТК' },
    { value: 'number', label: 'По номеру' },
    { value: 'status', label: 'По статусу' },
    { value: 'delivery_cost', label: 'По стоимости' },
];

/** Плитка-счётчик, она же переключатель фильтра по статусу. */
function StatTile({ label, value, hint, tone, active, onClick }) {
    return (
        <Card.Root
            cursor={onClick ? 'pointer' : 'default'}
            onClick={onClick}
            borderColor={active ? 'colorPalette.solid' : 'border'}
            borderWidth={active ? '2px' : '1px'}
            colorPalette={tone}
            _hover={onClick ? { borderColor: 'colorPalette.solid' } : undefined}
        >
            <Card.Body py={3}>
                <Text fontSize="xs" color="fg.muted" lineClamp={1}>{label}</Text>
                <Text fontSize="2xl" fontWeight="bold">{value}</Text>
                {hint && <Text fontSize="xs" color="fg.muted" lineClamp={1}>{hint}</Text>}
            </Card.Body>
        </Card.Root>
    );
}

function TrackCell({ delivery }) {
    if (!delivery.provider_number) {
        return <Text fontSize="sm" color="fg.muted">—</Text>;
    }

    if (!delivery.tracking_url) {
        return <Text fontSize="sm" fontVariantNumeric="tabular-nums">{delivery.provider_number}</Text>;
    }

    return (
        <a href={delivery.tracking_url} target="_blank" rel="noopener noreferrer">
            <HStack gap={1}>
                <Text fontSize="sm" fontVariantNumeric="tabular-nums">{delivery.provider_number}</Text>
                <LuExternalLink size={12} />
            </HStack>
        </a>
    );
}

/**
 * Строка журнала карточкой — мобильный вариант: журнал открывают со складского
 * телефона, а таблица из девяти колонок там не читается.
 */
function DeliveryMobileCard({ delivery, actions }) {
    return (
        <Box borderWidth="1px" borderColor="border" borderRadius="md" p={3}>
            <VStack align="stretch" gap={2}>
                <HStack justify="space-between" align="start">
                    <Text fontSize="sm" fontWeight="bold">{delivery.number}</Text>
                    <Badge colorPalette={delivery.status_color} size="sm">{delivery.status_label}</Badge>
                </HStack>

                <Text fontSize="sm" lineClamp={2}>{delivery.client || 'Получатель не указан'}</Text>

                <HStack gap={4} flexWrap="wrap" fontSize="xs" color="fg.muted">
                    <Text>{delivery.created_label}</Text>
                    {delivery.recipient_city && <Text>{delivery.recipient_city}</Text>}
                    {delivery.provider_key && <Text>{delivery.provider_key}</Text>}
                </HStack>

                <HStack gap={4} flexWrap="wrap" fontSize="sm">
                    <Text><Text as="span" color="fg.muted">Мест: </Text>{delivery.places_count}</Text>
                    <Text><Text as="span" color="fg.muted">Вес: </Text>{formatWeight(delivery.weight)}</Text>
                    <Text><Text as="span" color="fg.muted">Доставка: </Text>{formatMoney(delivery.delivery_cost)}</Text>
                </HStack>

                {delivery.provider_number && <TrackCell delivery={delivery} />}
                {delivery.apiship_status_label && (
                    <Text fontSize="xs" color="fg.muted">{delivery.apiship_status_label}</Text>
                )}

                <RowActions {...actions} size="md" />
            </VStack>
        </Box>
    );
}

export default function DeliveriesIndex() {
    const { deliveries, filters, stats, options, sort, perPage, integrationEnabled } = usePage().props;
    const { can } = usePermission();

    const [search, setSearch] = useState(filters.search || '');

    // Тот же запрос, что и на странице отправки: заявки у перевозчика нет,
    // поэтому удаление просто возвращает реализации в список к доставке.
    const deliveryDelete = useConfirmDelete({
        onConfirm: (delivery) => router.delete(`/wms/deliveries/${delivery.id}`, { preserveScroll: true }),
        title: (delivery) => `Удалить отправку ${delivery?.number ?? ''}?`,
        description: 'Заявку перевозчику не передавали, поэтому удаление ни на что не влияет. Реализации вернутся в список к доставке.',
        cancelLabel: 'Оставить',
    });

    /** Один набор действий для строки таблицы и мобильной карточки. */
    const actionsFor = (delivery) => {
        const submitted = Boolean(delivery.apiship_order_id);

        return {
            view: { href: delivery.url || `/wms/deliveries/${delivery.id}` },
            edit: { href: `/wms/deliveries/${delivery.id}/edit`, permission: 'wms-deliveries.edit' },
            delete: {
                onClick: () => deliveryDelete.request(delivery),
                permission: 'wms-deliveries.edit',
                disabled: submitted ? 'Отправка передана в ApiShip' : false,
            },
        };
    };

    const applyFilters = (next) => {
        router.get('/wms/deliveries', {
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

    const hasRows = deliveries.data.length > 0;

    return (
        <>
            <Head title="Отправки в ТК — Склад" />
            <PageHeader
                title="Отправки в ТК"
                description="Сборка груза из реализаций, расчёт стоимости у перевозчиков, заявка и отслеживание доставки."
                actions={can('wms-deliveries.create') && (
                    <Button asChild size="sm">
                        <Link href="/wms/deliveries/create">
                            <LuPackagePlus /> Новая отправка
                        </Link>
                    </Button>
                )}
            />

            <VStack gap={4} align="stretch">
                {!integrationEnabled && (
                    <Card.Root borderColor="orange.400" borderWidth="1px">
                        <Card.Body py={3}>
                            <Text fontSize="sm">
                                Интеграция с ApiShip выключена: расчёт и передача заявок недоступны.
                                Отправки можно собирать, но перевозчику они не уйдут.
                            </Text>
                        </Card.Body>
                    </Card.Root>
                )}

                <SimpleGrid columns={{ base: 2, md: 4, xl: 9 }} gap={3}>
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
                        label="Доставка за месяц"
                        value={formatMoney(stats.cost_this_month)}
                        hint="по переданным заявкам"
                        tone="purple"
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
                                        placeholder="Номер отправки, трек-номер, клиент, город..."
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

                                <VStack align="stretch" gap={1} minW="230px">
                                    <Text fontSize="xs" color="fg.muted" fontWeight="500">Дата создания</Text>
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
                        </VStack>
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Body>
                        {!hasRows ? (
                            <VStack py={10} gap={2} color="fg.muted">
                                <LuTruck size={32} />
                                <Text fontSize="sm">Отправок не найдено</Text>
                                <Text fontSize="xs">
                                    Либо ни одна не подходит под фильтры, либо груз ещё не собирали.
                                </Text>
                            </VStack>
                        ) : (
                            <>
                                <Box display={{ base: 'block', lg: 'none' }}>
                                    <VStack align="stretch" gap={2}>
                                        {deliveries.data.map((delivery) => (
                                            <DeliveryMobileCard
                                                key={delivery.id}
                                                delivery={delivery}
                                                actions={actionsFor(delivery)}
                                            />
                                        ))}
                                    </VStack>
                                </Box>

                                <Box display={{ base: 'none', lg: 'block' }} overflowX="auto">
                                    <Table.Root size="sm" interactive>
                                        <Table.Header>
                                            <Table.Row>
                                                <Table.ColumnHeader>Номер</Table.ColumnHeader>
                                                <Table.ColumnHeader>Создана</Table.ColumnHeader>
                                                <Table.ColumnHeader>Статус</Table.ColumnHeader>
                                                <Table.ColumnHeader>Клиент</Table.ColumnHeader>
                                                <Table.ColumnHeader>Город</Table.ColumnHeader>
                                                <Table.ColumnHeader>Перевозчик</Table.ColumnHeader>
                                                <Table.ColumnHeader>Трек</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="end">Мест</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="end">Вес</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="end">Доставка</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="end">Действия</Table.ColumnHeader>
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {deliveries.data.map((delivery) => (
                                                <Table.Row key={delivery.id}>
                                                    <Table.Cell>
                                                        <Text fontSize="sm" fontWeight="medium">
                                                            {delivery.number}
                                                        </Text>
                                                        <Text fontSize="xs" color="fg.muted">
                                                            реализаций: {delivery.documents_count}
                                                        </Text>
                                                    </Table.Cell>
                                                    <Table.Cell fontSize="sm" whiteSpace="nowrap">{delivery.created_label}</Table.Cell>
                                                    <Table.Cell>
                                                        <Badge colorPalette={delivery.status_color} size="sm">
                                                            {delivery.status_label}
                                                        </Badge>
                                                        {delivery.apiship_status_label && (
                                                            <Text fontSize="xs" color="fg.muted" lineClamp={1}>
                                                                {delivery.apiship_status_label}
                                                            </Text>
                                                        )}
                                                    </Table.Cell>
                                                    <Table.Cell fontSize="sm" maxW="220px">
                                                        <Text lineClamp={2}>{delivery.client || '—'}</Text>
                                                    </Table.Cell>
                                                    <Table.Cell fontSize="sm">{delivery.recipient_city || '—'}</Table.Cell>
                                                    <Table.Cell fontSize="sm">
                                                        <HStack gap={1}>
                                                            <Text>{delivery.provider_key || '—'}</Text>
                                                            {delivery.is_manual && (
                                                                <Badge size="xs" variant="outline" colorPalette="gray">вручную</Badge>
                                                            )}
                                                        </HStack>
                                                        {delivery.tariff_name && (
                                                            <Text fontSize="xs" color="fg.muted" lineClamp={1}>
                                                                {delivery.tariff_name}
                                                            </Text>
                                                        )}
                                                    </Table.Cell>
                                                    <Table.Cell><TrackCell delivery={delivery} /></Table.Cell>
                                                    <Table.Cell textAlign="end" fontSize="sm" fontVariantNumeric="tabular-nums">
                                                        {delivery.places_count}
                                                    </Table.Cell>
                                                    <Table.Cell textAlign="end" fontSize="sm" fontVariantNumeric="tabular-nums">
                                                        {formatWeight(delivery.weight)}
                                                    </Table.Cell>
                                                    <Table.Cell textAlign="end" fontSize="sm" fontVariantNumeric="tabular-nums">
                                                        {formatMoney(delivery.delivery_cost)}
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <RowActions {...actionsFor(delivery)} size="sm" />
                                                    </Table.Cell>
                                                </Table.Row>
                                            ))}
                                        </Table.Body>
                                    </Table.Root>
                                </Box>

                                <Pagination
                                    pagination={deliveries}
                                    perPage={perPage}
                                    onPerPageChange={(value) => applyFilters({ per_page: value })}
                                    onPageChange={(page) => router.get('/wms/deliveries', {
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

            <ConfirmDialog {...deliveryDelete.dialogProps} />
        </>
    );
}

DeliveriesIndex.layout = (page) => <WmsLayout>{page}</WmsLayout>;
