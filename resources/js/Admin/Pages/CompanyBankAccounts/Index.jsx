import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, Text, IconButton, Button, Badge } from '@chakra-ui/react';
import { LuPencil, LuTrash2, LuPlus } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ bankAccounts, filters }) {
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [itemToDelete, setItemToDelete] = useState(null);

    const handleSearch = (value) => {
        setSearchQuery(value);
        router.get(route('admin.company-bank-accounts.index'), {
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
        router.get(route('admin.company-bank-accounts.index'), {
            ...filters,
            sort_by: field,
            sort_order: newOrder,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handlePerPageChange = (perPage) => {
        router.get(route('admin.company-bank-accounts.index'), {
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
            router.delete(route('admin.company-bank-accounts.destroy', itemToDelete.id), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Удалено',
                        description: 'Банковский счет успешно удален',
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
            key: 'company',
            label: 'Компания',
            render: (company) => company ? (
                <Box>
                    <Text fontWeight="medium">{company.name}</Text>
                    {company.user && (
                        <Text fontSize="xs" color="fg.muted">
                            Пользователь: {company.user.name}
                        </Text>
                    )}
                </Box>
            ) : '—',
        },
        {
            key: 'bank_name',
            label: 'Банк',
            sortable: true,
        },
        {
            key: 'account_number',
            label: 'Номер счета',
            sortable: true,
        },
        {
            key: 'bank_bik',
            label: 'БИК',
            render: (bik) => bik || '—',
        },
        {
            key: 'is_primary',
            label: 'Основной',
            render: (isPrimary) => (
                <Badge colorPalette={isPrimary ? 'green' : 'gray'}>
                    {isPrimary ? 'Да' : 'Нет'}
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
                        onClick={() => router.visit(route('admin.company-bank-accounts.edit', item.id))}
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
                { label: 'Банковские счета' },
            ]}
        >
            <Box p={6}>
                <PageHeader
                    title="Банковские счета компаний"
                    description="Управление банковскими счетами"
                    actions={
                        <Button colorPalette="blue" onClick={() => router.visit(route('admin.company-bank-accounts.create'))}>
                            <LuPlus /> Создать счет
                        </Button>
                    }
                />

                <Box mb={4}>
                    <SearchInput
                        value={searchQuery}
                        onChange={handleSearch}
                        placeholder="Поиск по банку, номеру счета..."
                    />
                </Box>

                <DataTable
                    data={bankAccounts.data}
                    columns={columns}
                    pagination={bankAccounts}
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
                    title="Удалить банковский счет?"
                    description={`Вы уверены, что хотите удалить счет "${itemToDelete?.account_number}"?`}
                />
            </Box>
        </AdminLayout>
    );
}
