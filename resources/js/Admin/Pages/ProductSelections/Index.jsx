import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, Text, IconButton, Button, Badge, Image } from '@chakra-ui/react';
import { LuPencil, LuTrash2, LuPlus } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ productSelections, filters }) {
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [itemToDelete, setItemToDelete] = useState(null);

    const handleSearch = (value) => {
        setSearchQuery(value);
        router.get(route('admin.product-selections.index'), {
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
        router.get(route('admin.product-selections.index'), {
            ...filters,
            sort_by: field,
            sort_order: newOrder,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handlePerPageChange = (perPage) => {
        router.get(route('admin.product-selections.index'), {
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
            router.delete(route('admin.product-selections.destroy', itemToDelete.id), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Удалено',
                        description: 'Подборка товаров успешно удалена',
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
            key: 'desktop_image_url',
            label: 'Изображение',
            render: (imageUrl) => (
                imageUrl ? (
                    <Image
                        src={imageUrl}
                        alt="Desktop"
                        boxSize="50px"
                        objectFit="cover"
                        borderRadius="md"
                    />
                ) : (
                    <Box w="50px" h="50px" bg="bg.muted" borderRadius="md" />
                )
            ),
        },
        {
            key: 'name',
            label: 'Название',
            sortable: true,
            render: (name, item) => (
                <Box>
                    <Text fontWeight="medium">{name || '—'}</Text>
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
            key: 'has_desktop',
            label: 'Desktop',
            render: (_, item) => (
                <Badge colorPalette={item.desktop_image_url ? 'green' : 'gray'}>
                    {item.desktop_image_url ? 'Да' : 'Нет'}
                </Badge>
            ),
        },
        {
            key: 'has_mobile',
            label: 'Mobile',
            render: (_, item) => (
                <Badge colorPalette={item.mobile_image_url ? 'green' : 'gray'}>
                    {item.mobile_image_url ? 'Да' : 'Нет'}
                </Badge>
            ),
        },
        {
            key: 'created_at',
            label: 'Создано',
            sortable: true,
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (_, item) => (
                <HStack gap={1}>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        onClick={() => router.visit(route('admin.product-selections.edit', item.id))}
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
        <AdminLayout
            breadcrumbs={[
                { label: 'Главная', href: route('admin.dashboard') },
                { label: 'Подборки товаров' },
            ]}
        >
            <Box p={6}>
                <PageHeader
                    title="Подборки товаров"
                    description="Управление подборками товаров для отображения на сайте"
                    actions={
                        <Button colorPalette="blue" onClick={() => router.visit(route('admin.product-selections.create'))}>
                            <LuPlus /> Создать подборку
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
                    data={productSelections.data}
                    columns={columns}
                    pagination={productSelections}
                    onSort={handleSort}
                    sortColumn={filters.sort_by}
                    sortDirection={filters.sort_order}
                    perPage={filters.per_page}
                    onPerPageChange={handlePerPageChange}
                />

                <ConfirmDialog
                    open={deleteDialogOpen}
                    onClose={() => setDeleteDialogOpen(false)}
                    onConfirm={confirmDelete}
                    title="Удалить подборку?"
                    description={`Вы уверены, что хотите удалить подборку "${itemToDelete?.name || 'без названия'}"?`}
                />
            </Box>
        </AdminLayout>
    );
}
