import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, Text, IconButton, Button, Badge } from '@chakra-ui/react';
import { LuPencil, LuTrash2, LuPlus } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ companies, filters }) {
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [itemToDelete, setItemToDelete] = useState(null);

    const handleSearch = (value) => {
        setSearchQuery(value);
        router.get(route('admin.companies.index'), {
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
        router.get(route('admin.companies.index'), {
            ...filters,
            sort_by: field,
            sort_order: newOrder,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handlePerPageChange = (perPage) => {
        router.get(route('admin.companies.index'), {
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
            router.delete(route('admin.companies.destroy', itemToDelete.id), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Удалено',
                        description: 'Компания успешно удалена',
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
                    <Text fontWeight="medium">{name}</Text>
                    {item.legal_name && (
                        <Text fontSize="xs" color="fg.muted">{item.legal_name}</Text>
                    )}
                </Box>
            ),
        },
        {
            key: 'user',
            label: 'Пользователь',
            render: (user) => user ? (
                <Text
                    cursor="pointer"
                    color="blue.600"
                    _hover={{ textDecoration: 'underline' }}
                    onClick={() => router.visit(route('admin.users.edit', user.id))}
                >
                    {user.name}
                </Text>
            ) : '—',
        },
        {
            key: 'country',
            label: 'Страна',
            render: (country) => country || '—',
        },
        {
            key: 'tax_id',
            label: 'ИНН',
            render: (taxId) => taxId || '—',
        },
        {
            key: 'bank_accounts_count',
            label: 'Счетов',
            render: (count) => <Badge colorPalette="blue">{count || 0}</Badge>,
        },
        {
            key: 'created_at',
            label: 'Создана',
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
                        onClick={() => router.visit(route('admin.companies.edit', item.id))}
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
                { label: 'Компании' },
            ]}
        >
            <Box p={6}>
                <PageHeader
                    title="Компании"
                    description="Управление компаниями пользователей"
                    actions={
                        <Button colorPalette="blue" onClick={() => router.visit(route('admin.companies.create'))}>
                            <LuPlus /> Создать компанию
                        </Button>
                    }
                />

                <Box mb={4}>
                    <SearchInput
                        value={searchQuery}
                        onChange={handleSearch}
                        placeholder="Поиск по названию, юр. названию, ИНН..."
                    />
                </Box>

                <DataTable
                    data={companies.data}
                    columns={columns}
                    pagination={companies}
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
                    title="Удалить компанию?"
                    description={`Вы уверены, что хотите удалить компанию "${itemToDelete?.name}"? Все банковские счета компании также будут удалены.`}
                />
            </Box>
        </AdminLayout>
    );
}
