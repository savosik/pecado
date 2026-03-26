import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { DataTable, PageHeader, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Text, Image, Box } from '@chakra-ui/react';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';

export default function Index({ pages, filters }) {
    const {
        searchQuery,
        handleSearch,
        handleSort,
        deleteDialogOpen,
        entityToDelete,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
    } = useResourceIndex('admin.pages', filters, {
        entityLabel: 'Страница',
    });

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            width: '80px',
        },
        {
            key: 'list_image',
            label: 'Фото',
            width: '70px',
            render: (_, row) => row.list_image ? (
                <Image src={row.list_image} alt={row.title} boxSize="40px" objectFit="cover" borderRadius="md" />
            ) : (
                <Box boxSize="40px" bg="bg.muted" borderRadius="md" />
            ),
        },
        {
            key: 'title',
            label: 'Заголовок',
            sortable: true,
            render: (_, row) => <Text fontWeight="semibold">{row.title}</Text>,
        },
        {
            key: 'slug',
            label: 'Slug',
            sortable: true,
            render: (_, row) => <Text fontFamily="mono" fontSize="sm" color="gray.600" _dark={{ color: 'gray.400' }}>{row.slug}</Text>,
        },
        {
            key: 'created_at',
            label: 'Дата создания',
            sortable: true,
            render: (_, row) => (
                <Text fontSize="sm" color="gray.600">
                    {row.created_at ? new Date(row.created_at).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        createActionsColumn('admin.pages', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Страницы"
                onCreate={() => router.visit(route('admin.pages.create'))}
                createLabel="Создать страницу"
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по заголовку, slug, содержимому..."
                />
            </Box>

            <DataTable
                columns={columns}
                data={pages.data}
                pagination={pages}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить страницу?"
                description={`Вы уверены, что хотите удалить страницу "${entityToDelete?.title}"? Это действие нельзя отменить.`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
