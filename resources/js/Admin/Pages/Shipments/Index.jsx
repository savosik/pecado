import { useMemo, useState } from 'react';
import { router, Link } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog, DeleteAllButton, TrashedFilter } from '@/Admin/Components';
import {
    Box, Text, Badge, IconButton, HStack, VStack, Card,
    Input, Stack, Button, Flex, createListCollection,
} from '@chakra-ui/react';
import { LuFilter, LuX } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import { toaster } from '@/components/ui/toaster';
import { Field } from '@/components/ui/field';
import { Select } from '@/components/ui/select';

const STATUS_COLORS = {
    new: 'blue',
    completed: 'green',
    cancelled: 'red',
    in_progress: 'orange',
};

export default function Index({ shipments, filters, statuses, organizations, warehouses, organizationsEnabled, trashedCount }) {
    const {
        searchQuery,
        handleSearch,
        handleSort,
        deleteDialogOpen,
        entityToDelete,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
        deleteAllDialogOpen,
        deleteAllProcessing,
        openDeleteAllDialog,
        confirmDeleteAll,
        closeDeleteAllDialog,
    } = useResourceIndex('admin.shipments', filters, {
        entityLabel: 'Реализация',
    });

    const [forceDeleteId, setForceDeleteId] = useState(null);
    const [forceDeleteAllDialogOpen, setForceDeleteAllDialogOpen] = useState(false);
    const [forceDeleteAllProcessing, setForceDeleteAllProcessing] = useState(false);

    const isTrashed = !!filters?.trashed;

    const toggleTrashed = () => {
        router.get(route('admin.shipments.index'), { trashed: isTrashed ? undefined : 1 }, { preserveState: false });
    };

    const confirmForceDeleteAll = () => {
        setForceDeleteAllProcessing(true);
        router.delete(route('admin.bulk-force-delete-all', 'shipments'), {
            onSuccess: () => {
                toaster.create({ title: 'Окончательное удаление запущено в фоне', type: 'info' });
                setForceDeleteAllDialogOpen(false);
                setForceDeleteAllProcessing(false);
            },
            onError: () => {
                toaster.create({ title: 'Ошибка при запуске удаления', type: 'error' });
                setForceDeleteAllProcessing(false);
            },
        });
    };

    const handleForceDelete = () => {
        if (forceDeleteId) {
            router.delete(route('admin.shipments.force-delete', forceDeleteId), {
                onSuccess: () => {
                    toaster.create({ description: 'Реализация окончательно удалена', type: 'success' });
                    setForceDeleteId(null);
                },
            });
        }
    };

    const [showFilters, setShowFilters] = useState(false);
    const [localFilters, setLocalFilters] = useState({
        status: filters?.status ?? '',
        organization_id: filters?.organization_id ?? '',
        warehouse_id: filters?.warehouse_id ?? '',
        date_from: filters?.date_from ?? '',
        date_to: filters?.date_to ?? '',
        currency_code: filters?.currency_code ?? '',
    });

    // Коллекции для селектов фильтров: Chakra v3 требует collection у Select.Root
    // и объект (а не строку) в item — иначе выпадающий список не выбирается.
    const statusCollection = useMemo(() => createListCollection({
        items: [
            { label: 'Все статусы', value: '' },
            ...(statuses ?? []).map((s) => ({ label: s.label, value: String(s.value) })),
        ],
    }), [statuses]);

    const currencyCollection = useMemo(() => createListCollection({
        items: [
            { label: 'Все валюты', value: '' },
            { label: 'RUB (₽)', value: 'RUB' },
            { label: 'KZT (₸)', value: 'KZT' },
            { label: 'BYN (Br)', value: 'BYN' },
        ],
    }), []);

    const organizationCollection = useMemo(() => createListCollection({
        items: [
            { label: 'Все организации', value: '' },
            { label: 'Не указана', value: 'none' },
            ...(organizations ?? []).map((organization) => ({
                label: organization.is_stub ? `${organization.name} (не заведена)` : organization.name,
                value: String(organization.id),
            })),
        ],
    }), [organizations]);

    const warehouseCollection = useMemo(() => createListCollection({
        items: [
            { label: 'Все склады', value: '' },
            { label: 'Не указан', value: 'none' },
            ...(warehouses ?? []).map((warehouse) => ({ label: warehouse.name, value: String(warehouse.id) })),
        ],
    }), [warehouses]);

    const navigateWithParams = (params) => {
        router.get(route('admin.shipments.index'), {
            ...filters,
            ...params,
        }, { preserveState: true, replace: true });
    };

    const handleApplyFilters = () => {
        navigateWithParams({ ...localFilters, page: 1 });
    };

    const handleResetFilters = () => {
        const reset = { status: '', organization_id: '', warehouse_id: '', date_from: '', date_to: '', currency_code: '' };
        setLocalFilters(reset);
        navigateWithParams({ ...reset, page: 1 });
    };

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            render: (_, row) => <Box fontFamily="mono" fontSize="sm">{row.id}</Box>,
        },
        {
            key: 'number',
            label: 'Номер',
            render: (_, row) => (
                <Text fontSize="sm" fontFamily="mono">{row.number || ("#" + row.id)}</Text>
            ),
        },
        {
            key: 'tax_id',
            label: 'ИНН контрагента',
            render: (_, row) => (
                <Text fontSize="sm" fontFamily="mono">{row.tax_id || '—'}</Text>
            ),
        },
        {
            key: 'company',
            label: 'Компания',
            render: (_, row) => row.company ? (
                <Link href={route('admin.companies.edit', row.company.id)}>
                    <Text color="blue.600" _hover={{ textDecoration: 'underline' }} fontSize="sm">
                        {row.company.name}
                    </Text>
                </Link>
            ) : <Text color="gray.500" fontSize="sm">—</Text>,
        },
        // Организация и склад — наше юрлицо, от имени которого проведена реализация,
        // и площадка, с которой товар уехал. Колонки нет, пока функциональность
        // не включена флагом.
        ...(organizationsEnabled ? [{
            key: 'organization',
            label: 'Организация',
            render: (_, row) => (
                <Box>
                    {row.organization ? (
                        <HStack gap={1}>
                            <Text fontSize="sm">{row.organization.name}</Text>
                            {row.organization.is_stub && (
                                <Badge colorPalette="orange" variant="subtle" size="sm">не заведена</Badge>
                            )}
                        </HStack>
                    ) : <Text color="gray.500" fontSize="sm">—</Text>}
                    {row.warehouse && (
                        <Text fontSize="xs" color="gray.500">Склад: {row.warehouse.name}</Text>
                    )}
                </Box>
            ),
        }] : []),
        {
            key: 'date',
            label: 'Дата',
            sortable: true,
            render: (_, row) => (
                <Text fontSize="sm">
                    {row.date ? new Date(row.date).toLocaleDateString('ru-RU') : '—'}
                </Text>
            ),
        },
        {
            key: 'status',
            label: 'Статус',
            render: (_, row) => (
                <Badge colorPalette={STATUS_COLORS[row.status] || 'gray'}>
                    {row.status_label}
                </Badge>
            ),
        },
        {
            key: 'total_amount',
            label: 'Сумма',
            sortable: true,
            render: (_, row) => (
                <Box fontFamily="mono" fontWeight="medium">
                    {parseFloat(row.total_amount).toLocaleString('ru-RU', { minimumFractionDigits: 2 })}
                    {row.currency_code ? ` ${row.currency_code}` : ''}
                </Box>
            ),
        },
        {
            key: 'items_count',
            label: 'Позиций',
            render: (_, row) => <Text fontSize="sm" textAlign="center">{row.items_count}</Text>,
        },
        {
            key: 'created_at',
            label: 'Создано',
            sortable: true,
            render: (_, row) => (
                <Box>
                    <Text fontSize="sm" color="gray.600">{row.created_at}</Text>
                    {row.deleted_at && (
                        <Badge colorPalette="red" variant="subtle" size="xs" mt={0.5}>
                            Удалён: {row.deleted_at}
                        </Badge>
                    )}
                </Box>
            ),
        },
        {
            key: 'erp_created_at',
            label: 'Создано в 1С',
            sortable: false,
            render: (_, row) => (
                <Text fontSize="sm" color="gray.600">{row.erp_created_at || '—'}</Text>
            ),
        },
        isTrashed
            ? createActionsColumn('admin.shipments', (row) => setForceDeleteId(row.id), {
                showView: false,
                showEdit: false,
                permissionPrefix: 'shipments',
                deleteLabel: 'Удалить окончательно',
            })
            : createActionsColumn('admin.shipments', openDeleteDialog, {
                showEdit: false,
                permissionPrefix: 'shipments',
            }),
    ];

    return (
        <>
            <PageHeader title="Реализации"
                actions={
                    <HStack>
                        <TrashedFilter
                            trashed={isTrashed}
                            trashedCount={trashedCount}
                            onToggle={toggleTrashed}
                        />
                        {isTrashed ? (
                            <DeleteAllButton
                                sectionLabel="удалённые реализации окончательно"
                                dialogOpen={forceDeleteAllDialogOpen}
                                onOpen={() => setForceDeleteAllDialogOpen(true)}
                                onClose={() => setForceDeleteAllDialogOpen(false)}
                                onConfirm={confirmForceDeleteAll}
                                isLoading={forceDeleteAllProcessing}
                            />
                        ) : (
                            <DeleteAllButton
                                sectionLabel="реализации"
                                dialogOpen={deleteAllDialogOpen}
                                onOpen={openDeleteAllDialog}
                                onClose={closeDeleteAllDialog}
                                onConfirm={confirmDeleteAll}
                                isLoading={deleteAllProcessing}
                            />
                        )}
                    </HStack>
                }
            />

            {/* Поиск и фильтры */}
            <Flex gap="3" mb={4} direction={{ base: 'column', sm: 'row' }}>
                <Box flex="1">
                    <SearchInput
                        value={searchQuery}
                        onChange={handleSearch}
                        placeholder="Поиск по номеру, UUID, ИНН, компании или товару…"
                    />
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

            {showFilters && (
                <Card.Root mb={4}>
                    <Card.Body p={4}>
                        <Stack gap={4}>
                            <Flex gap={4} direction={{ base: 'column', md: 'row' }}>
                                <Field label="Статус" flex="1">
                                    <Select.Root
                                        collection={statusCollection}
                                        value={localFilters.status ? [localFilters.status] : []}
                                        onValueChange={(e) => setLocalFilters({ ...localFilters, status: e.value[0] || '' })}
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Все статусы" />
                                        </Select.Trigger>
                                        <Select.Content>
                                            {statusCollection.items.map((s) => (
                                                <Select.Item key={s.value} item={s}>{s.label}</Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </Field>

                                <Field label="Валюта" flex="1">
                                    <Select.Root
                                        collection={currencyCollection}
                                        value={localFilters.currency_code ? [localFilters.currency_code] : []}
                                        onValueChange={(e) => setLocalFilters({ ...localFilters, currency_code: e.value[0] || '' })}
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Все валюты" />
                                        </Select.Trigger>
                                        <Select.Content>
                                            {currencyCollection.items.map((option) => (
                                                <Select.Item key={option.value} item={option}>{option.label}</Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </Field>

                                {organizationsEnabled && (
                                    <Field label="Организация" flex="1">
                                        <Select.Root
                                            collection={organizationCollection}
                                            value={localFilters.organization_id ? [String(localFilters.organization_id)] : []}
                                            onValueChange={(e) => setLocalFilters({ ...localFilters, organization_id: e.value[0] || '' })}
                                        >
                                            <Select.Trigger>
                                                <Select.ValueText placeholder="Все организации" />
                                            </Select.Trigger>
                                            <Select.Content>
                                                {organizationCollection.items.map((option) => (
                                                    <Select.Item key={option.value} item={option}>
                                                        {option.label}
                                                    </Select.Item>
                                                ))}
                                            </Select.Content>
                                        </Select.Root>
                                    </Field>
                                )}

                                {organizationsEnabled && (
                                    <Field label="Склад отгрузки" flex="1">
                                        <Select.Root
                                            collection={warehouseCollection}
                                            value={localFilters.warehouse_id ? [String(localFilters.warehouse_id)] : []}
                                            onValueChange={(e) => setLocalFilters({ ...localFilters, warehouse_id: e.value[0] || '' })}
                                        >
                                            <Select.Trigger>
                                                <Select.ValueText placeholder="Все склады" />
                                            </Select.Trigger>
                                            <Select.Content>
                                                {warehouseCollection.items.map((option) => (
                                                    <Select.Item key={option.value} item={option}>
                                                        {option.label}
                                                    </Select.Item>
                                                ))}
                                            </Select.Content>
                                        </Select.Root>
                                    </Field>
                                )}
                            </Flex>

                            <Flex gap={4} direction={{ base: 'column', md: 'row' }} align="end">
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

                                <HStack gap="2" flexShrink="0">
                                    <Button onClick={handleApplyFilters} colorPalette="blue" size="sm">
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

            <DataTable
                data={shipments.data}
                columns={columns}
                pagination={shipments}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить реализацию?"
                description="Вы уверены, что хотите удалить эту реализацию? Это действие нельзя отменить."
            />

            <ConfirmDialog
                open={!!forceDeleteId}
                onClose={() => setForceDeleteId(null)}
                onConfirm={handleForceDelete}
                title="Окончательное удаление реализации"
                description="Вы уверены, что хотите окончательно удалить эту реализацию? Запись будет удалена безвозвратно."
                confirmLabel="Удалить окончательно"
                colorPalette="red"
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
