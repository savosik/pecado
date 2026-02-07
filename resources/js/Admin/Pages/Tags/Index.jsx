import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, Text } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ tags, filters }) {
    const {
        searchQuery,
        handleSearch,
        handleSort,
        deleteDialogOpen,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
    } = useResourceIndex('admin.tags', filters, {
        entityLabel: 'Тег',
    });

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            render: (_, row) => <Box fontFamily="mono" fontSize="sm">{row.id}</Box>,
        },
        {
            key: 'name',
            label: 'Название',
            sortable: true,
            render: (_, row) => <Text fontWeight="semibold">{row.display_name}</Text>,
        },
        {
            key: 'type',
            label: 'Тип',
            render: (_, row) => <Text fontSize="sm">{row.type || '—'}</Text>,
        },
        {
            key: 'order_column',
            label: 'Порядок',
            sortable: true,
            render: (_, row) => <Text fontSize="sm">{row.order_column}</Text>,
        },
        createActionsColumn('admin.tags', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Теги"
                onCreate={() => router.visit(route('admin.tags.create'))}
                createLabel="Создать тег"
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по названию..."
                />
            </Box>

            <DataTable
                data={tags.data}
                columns={columns}
                pagination={tags}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить тег?"
                description="Вы уверены, что хотите удалить этот тег? Это действие нельзя отменить."
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
