import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, Text, IconButton, Button, Badge } from '@chakra-ui/react';
import { LuPencil, LuTrash2, LuPlus } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ users, filters }) {
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [itemToDelete, setItemToDelete] = useState(null);

    const handleSearch = (value) => {
        setSearchQuery(value);
        router.get(route('admin.users.index'), {
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
        router.get(route('admin.users.index'), {
            ...filters,
            sort_by: field,
            sort_order: newOrder,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handlePerPageChange = (perPage) => {
        router.get(route('admin.users.index'), {
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
            router.delete(route('admin.users.destroy', itemToDelete.id), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Удалено',
                        description: 'Пользователь успешно удален',
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
            label: 'Имя',
            sortable: true,
            render: (name, item) => (
                <Box>
                    <Text fontWeight="medium">{name}</Text>
                    {item.email && <Text fontSize="xs" color="fg.muted">{item.email}</Text>}
                </Box>
            ),
        },
        {
            key: 'phone',
            label: 'Телефон',
            render: (phone) => phone || '—',
        },
        {
            key: 'region',
            label: 'Регион',
            render: (region) => region?.name || '—',
        },
        {
            key: 'companies_count',
            label: 'Компаний',
            render: (count) => <Badge colorPalette="blue">{count || 0}</Badge>,
        },
        {
            key: 'is_admin',
            label: 'Админ',
            render: (isAdmin) => (
                <Badge colorPalette={isAdmin ? 'green' : 'gray'}>
                    {isAdmin ? 'Да' : 'Нет'}
                </Badge>
            ),
        },
        {
            key: 'created_at',
            label: 'Создан',
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
                        onClick={() => router.visit(route('admin.users.edit', item.id))}
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
                { label: 'Пользователи' },
            ]}
        >
            <Box p={6}>
                <PageHeader
                    title="Пользователи"
                    description="Управление пользователями системы"
                    actions={
                        <Button colorPalette="blue" onClick={() => router.visit(route('admin.users.create'))}>
                            <LuPlus /> Создать пользователя
                        </Button>
                    }
                />

                <Box mb={4}>
                    <SearchInput
                        value={searchQuery}
                        onChange={handleSearch}
                        placeholder="Поиск по имени, email, телефону..."
                    />
                </Box>

                <DataTable
                    data={users.data}
                    columns={columns}
                    pagination={users}
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
                    title="Удалить пользователя?"
                    description={`Вы уверены, что хотите удалить пользователя "${itemToDelete?.name}"? Все связанные данные (компании, адреса) также будут удалены.`}
                />
            </Box>
        </AdminLayout>
    );
}
