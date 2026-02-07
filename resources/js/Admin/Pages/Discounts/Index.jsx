import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, Text, IconButton, Button, Badge } from '@chakra-ui/react';
import { LuPencil, LuTrash2, LuPlus } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ discounts, filters }) {
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [itemToDelete, setItemToDelete] = useState(null);

    const handleSearch = (value) => {
        setSearchQuery(value);
        router.get(route('admin.discounts.index'), {
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
        router.get(route('admin.discounts.index'), {
            ...filters,
            sort_by: field,
            sort_order: newOrder,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handlePerPageChange = (perPage) => {
        router.get(route('admin.discounts.index'), {
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
            router.delete(route('admin.discounts.destroy', itemToDelete.id), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Удалено',
                        description: 'Скидка успешно удалена',
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
            render: (name, item) => (
                <Box>
                    <Text fontWeight="medium">{name || '—'}</Text>
                    {item.external_id && <Text fontSize="xs" color="fg.muted">ID: {item.external_id}</Text>}
                </Box>
            ),
        },
        {
            key: 'percentage',
            label: 'Процент',
            sortable: true,
            render: (percentage) => (
                <Badge colorPalette="green">{percentage}%</Badge>
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
            key: 'users_count',
            label: 'Пользователей',
            render: (count) => (
                <Badge colorPalette="purple">{count || 0}</Badge>
            ),
        },
        {
            key: 'is_posted',
            label: 'Опубликовано',
            sortable: true,
            render: (isPosted) => (
                <Badge colorPalette={isPosted ? 'green' : 'gray'}>
                    {isPosted ? 'Да' : 'Нет'}
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
                        onClick={() => router.visit(route('admin.discounts.edit', item.id))}
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
                { label: 'Скидки' },
            ]}
        >
            <Box p={6}>
                <PageHeader
                    title="Скидки"
                    description="Управление скидками для товаров и пользователей"
                    actions={
                        <Button colorPalette="blue" onClick={() => router.visit(route('admin.discounts.create'))}>
                            <LuPlus /> Создать скидку
                        </Button>
                    }
                />

                <Box mb={4}>
                    <SearchInput
                        value={searchQuery}
                        onChange={handleSearch}
                        placeholder="Поиск по названию или ID..."
                    />
                </Box>

                <DataTable
                    data={discounts.data}
                    columns={columns}
                    pagination={discounts}
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
                    title="Удалить скидку?"
                    description={`Вы уверены, что хотите удалить скидку "${itemToDelete?.name || 'без названия'}"?`}
                />
            </Box>
        </AdminLayout>
    );
}
