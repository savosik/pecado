import { useState } from 'react';
import { router } from '@inertiajs/react';
import { AdminLayout } from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, Badge, Text, IconButton, Button } from '@chakra-ui/react';
import { LuPencil, LuTrash2, LuPlus } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ sizeCharts, filters }) {
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [itemToDelete, setItemToDelete] = useState(null);

    const handleSearch = (value) => {
        setSearchQuery(value);
        router.get(route('admin.size-charts.index'), {
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
        router.get(route('admin.size-charts.index'), {
            ...filters,
            sort_by: field,
            sort_order: newOrder,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handlePerPageChange = (perPage) => {
        router.get(route('admin.size-charts.index'), {
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
            router.delete(route('admin.size-charts.destroy', itemToDelete.id), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Удалено',
                        description: 'Размерная сетка успешно удалена',
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
            key: 'name',
            label: 'Название',
            sortable: true,
            render: (name) => <Text fontWeight="medium">{name}</Text>,
        },
        {
            key: 'values',
            label: 'Размеры',
            render: (values) => (
                <HStack gap={1} flexWrap="wrap">
                    {values?.map((v, i) => (
                        <Badge key={i} variant="outline">{v?.size}</Badge>
                    ))}
                </HStack>
            ),
        },
        {
            key: 'brands',
            label: 'Бренды',
            render: (brands) => (
                <HStack gap={1} flexWrap="wrap">
                    {brands?.map((brand) => (
                        <Badge key={brand.id} colorPalette="blue" variant="solid">
                            {brand.name}
                        </Badge>
                    ))}
                    {(!brands || brands.length === 0) && <Text fontSize="xs" color="fg.muted">—</Text>}
                </HStack>
            ),
        },
        {
            key: 'created_at',
            label: 'Создана',
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
                        onClick={() => router.visit(route('admin.size-charts.edit', item.id))}
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
                    title="Размерные сетки"
                    description="Управление таблицами размеров"
                    actions={
                        <Button colorPalette="blue" onClick={() => router.visit(route('admin.size-charts.create'))}>
                            <LuPlus /> Создать сетку
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
                    data={sizeCharts.data}
                    columns={columns}
                    pagination={sizeCharts}
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
                    title="Удалить размерную сетку?"
                    description={`Вы уверены, что хотите удалить сетку "${itemToDelete?.name}"?`}
                />
            </Box>
        </AdminLayout>
    );
}
