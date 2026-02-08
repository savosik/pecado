import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, Text, Button, Badge } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ companies, filters }) {
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
    } = useResourceIndex('admin.companies', filters, {
        entityLabel: 'Компания',
    });

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            width: '80px',
        },
        {
            key: 'name',
            label: 'Название',
            sortable: true,
            render: (name, item) => (
                <Box>
                    <Text fontWeight="medium">{name}</Text>
                    {item.legal_name && (
                        <Text fontSize="xs" color="fg.muted">{item.legal_name}</Text>
                    )}
                </Box>
            ),
        },
        {
            key: 'user',
            label: 'Пользователь',
            render: (user) => user ? (
                <Text
                    cursor="pointer"
                    color="blue.600"
                    _hover={{ textDecoration: 'underline' }}
                    onClick={() => router.visit(route('admin.users.edit', user.id))}
                >
                    {user.full_name}
                </Text>
            ) : '—',
        },
        {
            key: 'country',
            label: 'Страна',
            render: (country) => country || '—',
        },
        {
            key: 'tax_id',
            label: 'ИНН',
            render: (taxId) => taxId || '—',
        },
        {
            key: 'bank_accounts_count',
            label: 'Счетов',
            render: (count) => <Badge colorPalette="blue">{count || 0}</Badge>,
        },
        {
            key: 'created_at',
            label: 'Создана',
            sortable: true,
            render: (_, row) => (
                <Text fontSize="sm" color="gray.600">
                    {row.created_at ? new Date(row.created_at).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        createActionsColumn('admin.companies', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Компании"
                description="Управление компаниями пользователей"
                actions={
                    <Button colorPalette="blue" onClick={() => router.visit(route('admin.companies.create'))}>
                        <LuPlus /> Создать компанию
                    </Button>
                }
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по названию, юр. названию, ИНН..."
                />
            </Box>

            <DataTable
                data={companies.data}
                columns={columns}
                pagination={companies}
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
                title="Удалить компанию?"
                description={`Вы уверены, что хотите удалить компанию "${entityToDelete?.name}"? Все банковские счета компании также будут удалены.`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
