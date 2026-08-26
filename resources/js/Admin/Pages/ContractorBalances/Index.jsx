import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog, DeleteAllButton } from '@/Admin/Components';
import { Box, Text, Badge, IconButton, HStack } from '@chakra-ui/react';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { usePermission } from '@/Admin/hooks/usePermission';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ balances, filters }) {
    const { can } = usePermission();
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
    } = useResourceIndex('admin.contractor-balances', filters, {
        entityLabel: 'Баланс контрагента',
    });

    const fmt = (v) => Number(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            render: (_, row) => <Box fontFamily="mono" fontSize="sm">{row.id}</Box>,
        },
        {
            key: 'user',
            label: 'Пользователь',
            render: (_, row) => (
                <Text fontSize="sm" fontWeight="medium">{row.user?.name || '—'}</Text>
            ),
        },
        {
            key: 'company',
            label: 'Компания',
            render: (_, row) => (
                <Text fontSize="sm">{row.company?.name || '—'}</Text>
            ),
        },
        {
            key: 'tax_id',
            label: 'ИНН',
            sortable: true,
            render: (_, row) => (
                <Box fontFamily="mono" fontSize="sm">{row.tax_id}</Box>
            ),
        },
        {
            key: 'current_balance',
            label: 'Баланс (руб.)',
            sortable: true,
            render: (_, row) => (
                <Box
                    fontFamily="mono"
                    fontWeight="medium"
                    color={parseFloat(row.current_balance) < 0 ? 'red.600' : 'green.600'}
                    _dark={{ color: parseFloat(row.current_balance) < 0 ? 'red.400' : 'green.400' }}
                >
                    {fmt(row.current_balance)} ₽
                </Box>
            ),
        },
        {
            key: 'overdue_debt',
            label: 'Просрочка (руб.)',
            sortable: true,
            render: (_, row) => parseFloat(row.overdue_debt) > 0 ? (
                <Badge colorPalette="red" variant="subtle">
                    {fmt(row.overdue_debt)} ₽
                </Badge>
            ) : (
                <Text color="gray.500" fontSize="sm">—</Text>
            ),
        },
        {
            key: 'balance_erp_updated_at',
            label: 'Обновлено из 1С',
            sortable: true,
            render: (_, row) => (
                <Text fontSize="sm" color="gray.600">
                    {row.balance_erp_updated_at
                        ? new Date(row.balance_erp_updated_at).toLocaleString('ru-RU')
                        : '—'}
                </Text>
            ),
        },
        createActionsColumn('admin.contractor-balances', openDeleteDialog, { permissionPrefix: 'contractor-balances' }),
    ];

    return (
        <>
            <PageHeader
                title="Балансы контрагентов"
                createPermission="contractor-balances.create"
                createHref={route('admin.contractor-balances.create')}
                createLabel="Создать баланс"
            
                actions={
                    <DeleteAllButton
                        sectionLabel="балансы контрагентов"
                        dialogOpen={deleteAllDialogOpen}
                        onOpen={openDeleteAllDialog}
                        onClose={closeDeleteAllDialog}
                        onConfirm={confirmDeleteAll}
                        isLoading={deleteAllProcessing}
                    />
                }
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по ИНН, пользователю, компании..."
                />
            </Box>

            <DataTable
                data={balances.data}
                columns={columns}
                pagination={balances}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить баланс контрагента?"
                description="Вы уверены, что хотите удалить этот баланс? Это действие нельзя отменить."
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
