import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, Text, Button, Badge } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ promotions, filters }) {
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
    } = useResourceIndex('admin.promotions', filters, {
        entityLabel: 'Акция',
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
                    {item.meta_title && <Text fontSize="xs" color="fg.muted">{item.meta_title}</Text>}
                </Box>
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
            key: 'created_at',
            label: 'Создано',
            sortable: true,
        },
        createActionsColumn('admin.promotions', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Акции"
                description="Управление рекламными акциями"
                actions={
                    <Button colorPalette="blue" onClick={() => router.visit(route('admin.promotions.create'))}>
                        <LuPlus /> Создать акцию
                    </Button>
                }
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по названию..."
                />
            </Box>

            <DataTable
                data={promotions.data}
                columns={columns}
                pagination={promotions}
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
                title="Удалить акцию?"
                description={`Вы уверены, что хотите удалить акцию "${entityToDelete?.name}"?`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
