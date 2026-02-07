import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { DataTable, PageHeader, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Button, HStack, Text } from '@chakra-ui/react';
import { LuPencil, LuTrash2 } from 'react-icons/lu';
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
            render: (_, row) => new Date(row.created_at).toLocaleDateString('ru-RU'),
        },
        {
            key: 'actions',
            label: 'Действия',
            width: '150px',
            render: (_, row) => (
                <HStack gap={2}>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => router.visit(route('admin.pages.edit', row.id))}
                    >
                        <LuPencil />
                        Изменить
                    </Button>
                    <Button
                        size="sm"
                        colorPalette="red"
                        variant="outline"
                        onClick={() => openDeleteDialog(row)}
                    >
                        <LuTrash2 />
                    </Button>
                </HStack>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                title="Страницы"
                onCreate={() => router.visit(route('admin.pages.create'))}
                createLabel="Создать страницу"
            />

            <SearchInput
                value={searchQuery}
                onChange={handleSearch}
                placeholder="Поиск по заголовку, slug, содержимому..."
            />

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
