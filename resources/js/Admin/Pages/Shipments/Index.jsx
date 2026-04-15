import { useState } from 'react';
import { router, Link } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog, DeleteAllButton } from '@/Admin/Components';
import {
    Box, Text, Badge, IconButton, HStack, VStack, Card,
    Input, Stack, Button, Flex,
} from '@chakra-ui/react';
import { LuEye, LuFilter, LuX } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import { Field } from '@/components/ui/field';
import { Select } from '@/components/ui/select';

const STATUS_COLORS = {
    new: 'blue',
    completed: 'green',
    cancelled: 'red',
    in_progress: 'orange',
};

export default function Index({ shipments, filters, statuses }) {
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

    const [showFilters, setShowFilters] = useState(false);
    const [localFilters, setLocalFilters] = useState({
        status: filters?.status ?? '',
        date_from: filters?.date_from ?? '',
        date_to: filters?.date_to ?? '',
        currency_code: filters?.currency_code ?? '',
    });

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
        const reset = { status: '', date_from: '', date_to: '', currency_code: '' };
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
            key: 'uuid',
            label: 'UUID',
            render: (_, row) => (
                <Link href={route('admin.shipments.show', row.id)}>
                    <Text color="blue.600" _hover={{ textDecoration: 'underline' }} fontSize="sm" fontFamily="mono">
                        {row.uuid?.substring(0, 8)}…
                    </Text>
                </Link>
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
                <Text fontSize="sm" color="gray.600">{row.created_at}</Text>
            ),
        },
        createActionsColumn('admin.shipments', openDeleteDialog, {
            showEdit: false,
            permissionPrefix: 'shipments',
            extraActions: (row) => (
                <IconButton
                    size="sm"
                    variant="ghost"
                    aria-label="Просмотр"
                    onClick={() => router.visit(route('admin.shipments.show', row.id))}
                >
                    <LuEye />
                </IconButton>
            ),
        }),
    ];

    return (
        <>
            <PageHeader title="Реализации" 
                actions={
                    <DeleteAllButton
                        sectionLabel="реализации"
                        dialogOpen={deleteAllDialogOpen}
                        onOpen={openDeleteAllDialog}
                        onClose={closeDeleteAllDialog}
                        onConfirm={confirmDeleteAll}
                        isLoading={deleteAllProcessing}
                    />
                }
            />

            {/* Поиск и фильтры */}
            <Flex gap="3" mb={4} direction={{ base: 'column', sm: 'row' }}>
                <Box flex="1">
                    <SearchInput
                        value={searchQuery}
                        onChange={handleSearch}
                        placeholder="Поиск по UUID, ИНН, компании или товару…"
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

                                <Field label="Валюта" flex="1">
                                    <Select.Root
                                        value={localFilters.currency_code ? [localFilters.currency_code] : []}
                                        onValueChange={(e) => setLocalFilters({ ...localFilters, currency_code: e.value[0] || '' })}
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Все валюты" />
                                        </Select.Trigger>
                                        <Select.Content>
                                            <Select.Item item="">Все валюты</Select.Item>
                                            <Select.Item item="RUB">RUB (₽)</Select.Item>
                                            <Select.Item item="KZT">KZT (₸)</Select.Item>
                                            <Select.Item item="BYN">BYN (Br)</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                </Field>
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
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
