import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, Text, IconButton, Button, Flex } from '@chakra-ui/react';
import { LuPencil, LuTrash2, LuPlus } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';


export default function Index({ productModels, filters }) {
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [modelToDelete, setModelToDelete] = useState(null);

    const handleSearch = (value) => {
        setSearchQuery(value);
        router.get(route('admin.product-models.index'), {
            ...filters,
            search: value,
            page: 1, // Reset page
        }, {
            preserveState: true,
            replace: true,
        });
    };



    const handleSort = (field) => {
        const newOrder = filters.sort_by === field && filters.sort_order === 'asc' ? 'desc' : 'asc';
        router.get(route('admin.product-models.index'), {
            ...filters,
            sort_by: field,
            sort_order: newOrder,
        }, { preserveState: true, replace: true });
    };

    const handlePerPageChange = (perPage) => {
        router.get(route('admin.product-models.index'), {
            ...filters,
            per_page: perPage,
        }, { preserveState: true, replace: true });
    };

    const handleDelete = (model) => {
        setModelToDelete(model);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (modelToDelete) {
            router.delete(route('admin.product-models.destroy', modelToDelete.id), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Модель удалена',
                        type: 'success',
                    });
                    setDeleteDialogOpen(false);
                    setModelToDelete(null);
                },
                onError: () => {
                    toaster.create({
                        title: 'Ошибка',
                        description: 'Не удалось удалить модель',
                        type: 'error',
                    });
                },
            });
        }
    };

    const columns = [
        { key: 'id', label: 'ID', sortable: true, width: '80px' },
        {
            key: 'name',
            label: 'Название',
            sortable: true,
            render: (_, model) => (
                <Box>
                    <Text fontWeight="medium">{model.name}</Text>
                    {model.code && <Text fontSize="xs" color="fg.muted">Код: {model.code}</Text>}
                </Box>
            )
        },

        {
            key: 'created_at',
            label: 'Создана',
            sortable: true,
            render: (_, model) => new Date(model.created_at).toLocaleDateString('ru-RU')
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (_, model) => (
                <HStack gap={1}>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        aria-label="Редактировать"
                        onClick={() => router.visit(route('admin.product-models.edit', model.id))}
                    >
                        <LuPencil />
                    </IconButton>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        colorPalette="red"
                        aria-label="Удалить"
                        onClick={() => handleDelete(model)}
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
                    title="Модели товаров"
                    description="Управление моделями товаров"
                    actions={
                        <Button
                            colorPalette="blue"
                            onClick={() => router.visit(route('admin.product-models.create'))}
                        >
                            <LuPlus /> Создать модель
                        </Button>
                    }
                />

                <Flex gap={4} mb={4} direction={{ base: 'column', md: 'row' }}>
                    <Box flex={1}>
                        <SearchInput
                            value={searchQuery}
                            onChange={handleSearch}
                            placeholder="Поиск по названию или коду..."
                        />
                    </Box>

                </Flex>

                <DataTable
                    data={productModels.data}
                    columns={columns}
                    pagination={productModels}
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
                    title="Удалить модель?"
                    description={`Вы уверены, что хотите удалить модель "${modelToDelete?.name}"?`}
                />
            </Box>
        </AdminLayout>
    );
}
