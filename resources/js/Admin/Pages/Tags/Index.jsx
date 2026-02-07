import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, IconButton, Text } from '@chakra-ui/react';
import { LuPencil, LuTrash2, LuPlus } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ tags, filters }) {
    const [search, setSearch] = useState(filters.search || '');
    const [deleteId, setDeleteId] = useState(null);

    const handleSearch = (value) => {
        setSearch(value);
        router.get(route('admin.tags.index'), { search: value }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleDelete = () => {
        if (deleteId) {
            router.delete(route('admin.tags.destroy', deleteId), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Тег успешно удалён',
                        type: 'success',
                    });
                    setDeleteId(null);
                },
                onError: () => {
                    toaster.create({
                        title: 'Ошибка при удалении тега',
                        type: 'error',
                    });
                },
            });
        }
    };

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            render: (row) => <Box fontFamily="mono" fontSize="sm">{row.id}</Box>,
        },
        {
            key: 'name',
            label: 'Название',
            sortable: true,
            render: (row) => <Text fontWeight="semibold">{row.display_name}</Text>,
        },
        {
            key: 'type',
            label: 'Тип',
            render: (row) => <Text fontSize="sm">{row.type || '—'}</Text>,
        },
        {
            key: 'order_column',
            label: 'Порядок',
            sortable: true,
            render: (row) => <Text fontSize="sm">{row.order_column}</Text>,
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (row) => (
                <HStack gap={2}>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        onClick={() => router.visit(route('admin.tags.edit', row.id))}
                    >
                        <LuPencil />
                    </IconButton>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        colorPalette="red"
                        onClick={() => setDeleteId(row.id)}
                    >
                        <LuTrash2 />
                    </IconButton>
                </HStack>
            ),
        },
    ];

    return (
        <AdminLayout>
            <PageHeader
                title="Теги"
                action={{
                    label: 'Создать тег',
                    icon: LuPlus,
                    onClick: () => router.visit(route('admin.tags.create')),
                }}
            />

            <Box mb={4}>
                <SearchInput
                    value={search}
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
                onSort={(column, direction) => {
                    router.get(route('admin.tags.index'), {
                        ...filters,
                        sort_by: column,
                        sort_order: direction,
                    }, {
                        preserveState: true,
                        replace: true,
                    });
                }}
            />

            <ConfirmDialog
                open={!!deleteId}
                onClose={() => setDeleteId(null)}
                onConfirm={handleDelete}
                title="Удалить тег?"
                description="Вы уверены, что хотите удалить этот тег? Это действие нельзя отменить."
            />
        </AdminLayout>
    );
}
