import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, SearchInput, DataTable, ConfirmDialog } from '@/Admin/Components';
import { Badge, Box } from '@chakra-ui/react';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';

export default function Index({ stories, filters }) {
    const {
        searchQuery,
        handleSearch,
        handleSort,
        deleteDialogOpen,
        entityToDelete,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
    } = useResourceIndex('admin.stories', filters, {
        entityLabel: 'Сторис',
    });

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
        },
        {
            key: 'name',
            label: 'Название',
            sortable: true,
        },
        {
            key: 'slug',
            label: 'Slug',
            sortable: true,
        },
        {
            key: 'slides_count',
            label: 'Слайдов',
            sortable: true,
            render: (_, story) => story.slides_count || 0,
        },
        {
            key: 'is_active',
            label: 'Активность',
            sortable: true,
            render: (_, story) => (
                <Badge colorPalette={story.is_active ? 'green' : 'gray'}>
                    {story.is_active ? 'Активен' : 'Неактивен'}
                </Badge>
            ),
        },
        {
            key: 'sort_order',
            label: 'Порядок',
            sortable: true,
        },
        createActionsColumn('admin.stories', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Сторис"
                description="Управление историями (stories)"
                onCreate={() => router.visit(route('admin.stories.create'))}
                createLabel="Создать сторис"
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по названию..."
                />
            </Box>

            <DataTable
                columns={columns}
                data={stories.data}
                pagination={stories}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить сторис?"
                description={`Вы уверены, что хотите удалить сторис "${entityToDelete?.name}"? Все слайды также будут удалены. Это действие нельзя отменить.`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
