import { useState } from 'react';
import { router, Link } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, Text } from '@chakra-ui/react';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ balances, filters }) {
    const {
        searchQuery,
        handleSearch,
        handleSort,
        deleteDialogOpen,
        entityToDelete,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
    } = useResourceIndex('admin.user-balances', filters, {
        entityLabel: 'Баланс',
    });

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
                <Link href={route('admin.users.edit', row.user.id)}>
                    <Text color="blue.600" _hover={{ textDecoration: 'underline' }}>
                        {row.user.name}
                    </Text>
                </Link>
            ),
        },
        {
            key: 'balance',
            label: 'Баланс',
            sortable: true,
            render: (_, row) => (
                <Box fontFamily="mono" fontWeight="medium">
                    {parseFloat(row.balance).toLocaleString('ru-RU', { minimumFractionDigits: 2 })} ₽
                </Box>
            ),
        },
        {
            key: 'overdue_debt',
            label: 'Просроченная задолженность',
            render: (_, row) => parseFloat(row.overdue_debt) > 0 ? (
                <Box fontFamily="mono" color="red.600" fontWeight="medium">
                    {parseFloat(row.overdue_debt).toLocaleString('ru-RU', { minimumFractionDigits: 2 })} ₽
                </Box>
            ) : (
                <Text color="gray.500">—</Text>
            ),
        },
        {
            key: 'balance_erp_updated_at',
            label: 'Обновлено в 1С',
            sortable: true,
            render: (_, row) => (
                <Text fontSize="sm" color="gray.600">
                    {row.balance_erp_updated_at ? new Date(row.balance_erp_updated_at).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        {
            key: 'updated_at',
            label: 'Дата обновления',
            sortable: true,
            render: (_, row) => (
                <Text fontSize="sm" color="gray.600">
                    {row.updated_at ? new Date(row.updated_at).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        createActionsColumn('admin.user-balances', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Балансы пользователей"
                onCreate={() => router.visit(route('admin.user-balances.create'))}
                createLabel="Создать баланс"
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по имени или email пользователя..."
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
                title="Удалить баланс?"
                description="Вы уверены, что хотите удалить этот баланс? Это действие нельзя отменить."
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
