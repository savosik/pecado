import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, Text, Badge, Button } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ attributeGroups, filters }) {
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
    } = useResourceIndex('admin.attribute-groups', filters, {
        entityLabel: 'Группа атрибутов',
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
            render: (_, row) => <Text fontWeight="semibold">{row.name}</Text>,
        },
        {
            key: 'attributes_count',
            label: 'Атрибутов',
            render: (val) => <Badge colorPalette="blue">{val}</Badge>,
        },
        createActionsColumn('admin.attribute-groups', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Группы атрибутов"
                description="Логическая группировка характеристик товаров"
                actions={
                    <Button
                        colorPalette="blue"
                        onClick={() => router.visit(route('admin.attribute-groups.create'))}
                    >
                        <LuPlus /> Создать группу
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
                data={attributeGroups.data}
                columns={columns}
                pagination={attributeGroups}
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
                title="Удалить группу атрибутов?"
                description={`Вы уверены, что хотите удалить группу "${entityToDelete?.name}"? Атрибуты не будут удалены, только связь с группой.`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
