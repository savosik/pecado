import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { DataTable, PageHeader, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Button, HStack, Text } from '@chakra-ui/react';
import { LuPencil, LuTrash2 } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ pages, filters }) {
    const [search, setSearch] = useState(filters.search || '');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [pageToDelete, setPageToDelete] = useState(null);

    const handleSearch = (value) => {
        setSearch(value);
        router.get(route('admin.pages.index'),
            { ...filters, search: value, page: 1 },
            { preserveState: true, preserveScroll: true }
        );
    };

    const handleSort = (column) => {
        const newSortOrder = filters.sort_by === column && filters.sort_order === 'asc' ? 'desc' : 'asc';
        router.get(route('admin.pages.index'),
            { ...filters, sort_by: column, sort_order: newSortOrder },
            { preserveState: true, preserveScroll: true }
        );
    };

    const handleDelete = (page) => {
        setPageToDelete(page);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (pageToDelete) {
            router.delete(route('admin.pages.destroy', pageToDelete.id), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Страница успешно удалена',
                        type: 'success',
                    });
                    setDeleteDialogOpen(false);
                    setPageToDelete(null);
                },
            });
        }
    };

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
            render: (row) => <Text fontWeight="semibold">{row.title}</Text>,
        },
        {
            key: 'slug',
            label: 'Slug',
            sortable: true,
            render: (row) => <Text fontFamily="mono" fontSize="sm" color="gray.600" _dark={{ color: 'gray.400' }}>{row.slug}</Text>,
        },
        {
            key: 'created_at',
            label: 'Дата создания',
            sortable: true,
            render: (row) => new Date(row.created_at).toLocaleDateString('ru-RU'),
        },
        {
            key: 'actions',
            label: 'Действия',
            width: '150px',
            render: (row) => (
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
                        onClick={() => handleDelete(row)}
                    >
                        <LuTrash2 />
                    </Button>
                </HStack>
            ),
        },
    ];

    return (
        <AdminLayout>
            <PageHeader
                title="Страницы"
                action={{
                    label: 'Создать страницу',
                    onClick: () => router.visit(route('admin.pages.create')),
                }}
            />

            <SearchInput
                value={search}
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
                onOpenChange={setDeleteDialogOpen}
                onConfirm={confirmDelete}
                title="Удалить страницу?"
                description={`Вы уверены, что хотите удалить страницу "${pageToDelete?.title}"? Это действие нельзя отменить.`}
            />
        </AdminLayout>
    );
}
