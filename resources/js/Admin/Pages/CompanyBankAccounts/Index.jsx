import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, Text, Button, Badge } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ bankAccounts, filters }) {
    const {
        searchQuery,
        handleSearch,
        handleSort,
        handlePerPageChange,
        deleteDialogOpen,
        entityToDelete,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
    } = useResourceIndex('admin.company-bank-accounts', filters, {
        entityLabel: 'Банковский счет',
    });

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            width: '80px',
        },
        {
            key: 'company',
            label: 'Компания',
            render: (company) => company ? (
                <Box>
                    <Text fontWeight="medium">{company.name}</Text>
                    {company.user && (
                        <Text fontSize="xs" color="fg.muted">
                            Пользователь: {company.user.name}
                        </Text>
                    )}
                </Box>
            ) : '—',
        },
        {
            key: 'bank_name',
            label: 'Банк',
            sortable: true,
        },
        {
            key: 'account_number',
            label: 'Номер счета',
            sortable: true,
        },
        {
            key: 'bank_bik',
            label: 'БИК',
            render: (bik) => bik || '—',
        },
        {
            key: 'is_primary',
            label: 'Основной',
            render: (isPrimary) => (
                <Badge colorPalette={isPrimary ? 'green' : 'gray'}>
                    {isPrimary ? 'Да' : 'Нет'}
                </Badge>
            ),
        },
        {
            key: 'created_at',
            label: 'Создан',
            sortable: true,
        },
        createActionsColumn('admin.company-bank-accounts', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Банковские счета компаний"
                description="Управление банковскими счетами"
                actions={
                    <Button colorPalette="blue" onClick={() => router.visit(route('admin.company-bank-accounts.create'))}>
                        <LuPlus /> Создать счет
                    </Button>
                }
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по банку, номеру счета..."
                />
            </Box>

            <DataTable
                data={bankAccounts.data}
                columns={columns}
                pagination={bankAccounts}
                onSort={handleSort}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                perPage={filters.per_page}
                onPerPageChange={handlePerPageChange}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить банковский счет?"
                description={`Вы уверены, что хотите удалить счет "${entityToDelete?.account_number}"?`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
