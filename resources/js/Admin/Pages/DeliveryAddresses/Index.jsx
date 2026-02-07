import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, Text, Button } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ deliveryAddresses, filters }) {
    const {
        searchQuery,
        handleSearch,
        handleSort,
        handlePerPageChange,
        deleteDialogOpen,
        entityToDelete,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
    } = useResourceIndex('admin.delivery-addresses', filters, {
        entityLabel: 'Адрес доставки',
    });

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            width: '80px',
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
            key: 'name',
            label: 'Название',
            sortable: true,
            render: (name) => <Text fontWeight="medium">{name}</Text>,
        },
        {
            key: 'address',
            label: 'Адрес',
            render: (address) => (
                <Text maxW="400px" noOfLines={2}>
                    {address}
                </Text>
            ),
        },
        {
            key: 'created_at',
            label: 'Создан',
            sortable: true,
        },
        createActionsColumn('admin.delivery-addresses', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Адреса доставки"
                description="Управление адресами доставки пользователей"
                actions={
                    <Button colorPalette="blue" onClick={() => router.visit(route('admin.delivery-addresses.create'))}>
                        <LuPlus /> Создать адрес
                    </Button>
                }
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по названию, адресу..."
                />
            </Box>

            <DataTable
                data={deliveryAddresses.data}
                columns={columns}
                pagination={deliveryAddresses}
                onSort={handleSort}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                perPage={filters.per_page}
                onPerPageChange={handlePerPageChange}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить адрес доставки?"
                description={`Вы уверены, что хотите удалить адрес "${entityToDelete?.name}"?`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
