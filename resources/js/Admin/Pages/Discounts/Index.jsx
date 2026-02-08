import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, Text, Button, Badge } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ discounts, filters }) {
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
    } = useResourceIndex('admin.discounts', filters, {
        entityLabel: 'Скидка',
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
                    <Text fontWeight="medium">{name || '—'}</Text>
                    {item.external_id && <Text fontSize="xs" color="fg.muted">ID: {item.external_id}</Text>}
                </Box>
            ),
        },
        {
            key: 'percentage',
            label: 'Процент',
            sortable: true,
            render: (percentage) => (
                <Badge colorPalette="green">{percentage}%</Badge>
            ),
        },
        {
            key: 'products_count',
            label: 'Товаров',
            render: (count) => (
                <Badge colorPalette="blue">{count || 0}</Badge>
            ),
        },
        {
            key: 'users_count',
            label: 'Пользователей',
            render: (count) => (
                <Badge colorPalette="purple">{count || 0}</Badge>
            ),
        },
        {
            key: 'is_posted',
            label: 'Опубликовано',
            sortable: true,
            render: (isPosted) => (
                <Badge colorPalette={isPosted ? 'green' : 'gray'}>
                    {isPosted ? 'Да' : 'Нет'}
                </Badge>
            ),
        },
        {
            key: 'created_at',
            label: 'Создано',
            sortable: true,
            render: (_, row) => (
                <Text fontSize="sm" color="gray.600">
                    {row.created_at ? new Date(row.created_at).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        createActionsColumn('admin.discounts', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Скидки"
                description="Управление скидками для товаров и пользователей"
                actions={
                    <Button colorPalette="blue" onClick={() => router.visit(route('admin.discounts.create'))}>
                        <LuPlus /> Создать скидку
                    </Button>
                }
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по названию или ID..."
                />
            </Box>

            <DataTable
                data={discounts.data}
                columns={columns}
                pagination={discounts}
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
                title="Удалить скидку?"
                description={`Вы уверены, что хотите удалить скидку "${entityToDelete?.name || 'без названия'}"?`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
