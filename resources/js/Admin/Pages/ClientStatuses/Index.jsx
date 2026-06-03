import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog, DeleteAllButton } from '@/Admin/Components';
import { Box, HStack, Image, Text, Button } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import { usePermission } from '@/Admin/hooks/usePermission';

export default function Index({ clientStatuses, filters }) {
    const { can } = usePermission();
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
        deleteAllDialogOpen,
        deleteAllProcessing,
        openDeleteAllDialog,
        confirmDeleteAll,
        closeDeleteAllDialog,
    } = useResourceIndex('admin.client-statuses', filters, {
        entityLabel: 'Статус клиента',
    });

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            width: '80px',
        },
        {
            key: 'image',
            label: 'Изображение',
            render: (_, item) => (
                item?.media && item.media?.length > 0 ? (
                    <Image
                        src={item.media[0].original_url}
                        alt={item.name}
                        w="40px"
                        h="40px"
                        objectFit="cover"
                        borderRadius="md"
                    />
                ) : (
                    <Box
                        w="40px"
                        h="40px"
                        bg="bg.muted"
                        borderRadius="md"
                        display="flex"
                        alignItems="center"
                        justifyContent="center"
                    >
                        <Text fontSize="xs" color="fg.muted">—</Text>
                    </Box>
                )
            ),
        },
        {
            key: 'name',
            label: 'Название',
            sortable: true,
            render: (_, item) => (
                <Box>
                    <Text fontWeight="medium">{item.name}</Text>
                    {item.external_id && (
                        <Text fontSize="sm" color="fg.muted">Внешний ИД: {item.external_id}</Text>
                    )}
                </Box>
            ),
        },
        {
            key: 'amount_from',
            label: 'Сумма от',
            sortable: true,
            render: (_, item) => (
                <Text fontSize="sm">
                    {item.amount_from ? `${Number(item.amount_from).toLocaleString('ru-RU')} ₽` : '—'}
                </Text>
            ),
        },
        {
            key: 'created_at',
            label: 'Создан',
            sortable: true,
            render: (_, row) => (
                <Text fontSize="sm" color="gray.600">
                    {row.created_at ? new Date(row.created_at).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        createActionsColumn('admin.client-statuses', openDeleteDialog, { permissionPrefix: 'client-statuses' , showView: true}),
    ];

    return (
        <>
            <PageHeader
                title="Статусы клиентов"
                description="Управление статусами клиентов"
                actions={
                    <>
                        <DeleteAllButton
                        sectionLabel="статусы клиентов"
                        dialogOpen={deleteAllDialogOpen}
                        onOpen={openDeleteAllDialog}
                        onClose={closeDeleteAllDialog}
                        onConfirm={confirmDeleteAll}
                        isLoading={deleteAllProcessing}
                    />
                        {can('client-statuses.create') && (
                    <Button
                        colorPalette="blue"
                        onClick={() => router.visit(route('admin.client-statuses.create'))}
                    >
                        <LuPlus /> Создать статус
                    </Button>
                    )}
                    </>
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
                data={clientStatuses.data}
                columns={columns}
                pagination={clientStatuses}
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
                title="Удалить статус клиента?"
                description={`Вы уверены, что хотите удалить статус "${entityToDelete?.name}"? Это действие нельзя отменить.`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
