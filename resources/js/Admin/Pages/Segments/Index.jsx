import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, Text, IconButton, Button, Image } from '@chakra-ui/react';
import { LuPencil, LuTrash2, LuPlus } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ segments, filters }) {
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [itemToDelete, setItemToDelete] = useState(null);

    const handleSearch = (value) => {
        setSearchQuery(value);
        router.get(route('admin.segments.index'), {
            search: value,
            per_page: filters.per_page,
            sort_by: filters.sort_by,
            sort_order: filters.sort_order,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleSort = (field) => {
        const newOrder = filters.sort_by === field && filters.sort_order === 'asc' ? 'desc' : 'asc';
        router.get(route('admin.segments.index'), {
            ...filters,
            sort_by: field,
            sort_order: newOrder,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handlePerPageChange = (perPage) => {
        router.get(route('admin.segments.index'), {
            ...filters,
            per_page: perPage,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleDelete = (item) => {
        setItemToDelete(item);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (itemToDelete) {
            router.delete(route('admin.segments.destroy', itemToDelete.id), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Удалено',
                        description: 'Сегмент успешно удален',
                        type: 'success',
                    });
                    setDeleteDialogOpen(false);
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
            key: 'image',
            label: 'Баннер (desktop)',
            render: (_, item) => {
                const url = item.media?.[0]?.original_url;
                return url ? (
                    <Image
                        src={url}
                        alt={item.name}
                        h="40px"
                        borderRadius="md"
                        objectFit="cover"
                        maxW="120px"
                    />
                ) : (
                    <Box h="40px" w="100px" bg="bg.muted" borderRadius="md" />
                );
            },
        },
        {
            key: 'name',
            label: 'Название',
            sortable: true,
            render: (name) => <Text fontWeight="medium">{name}</Text>,
        },
        {
            key: 'created_at',
            label: 'Создан',
            sortable: true,
            render: (date) => new Date(date).toLocaleDateString('ru-RU'),
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (_, item) => (
                <HStack gap={1}>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        onClick={() => router.visit(route('admin.segments.edit', item.id))}
                    >
                        <LuPencil />
                    </IconButton>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        colorPalette="red"
                        onClick={() => handleDelete(item)}
                    >
                        <LuTrash2 />
                    </IconButton>
                </HStack>
            ),
        },
    ];

    return (
        <AdminLayout>
            <Box p={6}>
                <PageHeader
                    title="Сегменты"
                    description="Управление коллекциями и баннерами сегментов"
                    actions={
                        <Button colorPalette="blue" onClick={() => router.visit(route('admin.segments.create'))}>
                            <LuPlus /> Создать сегмент
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
                    data={segments.data}
                    columns={columns}
                    pagination={segments}
                    onSort={handleSort}
                    sortBy={filters.sort_by}
                    sortOrder={filters.sort_order}
                    perPage={filters.per_page}
                    onPerPageChange={handlePerPageChange}
                />

                <ConfirmDialog
                    open={deleteDialogOpen}
                    onClose={() => setDeleteDialogOpen(false)}
                    onConfirm={confirmDelete}
                    title="Удалить сегмент?"
                    description={`Вы уверены, что хотите удалить сегмент "${itemToDelete?.name}"?`}
                />
            </Box>
        </AdminLayout>
    );
}
