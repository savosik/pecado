import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog, DeleteAllButton } from '@/Admin/Components';
import { Box, Image, Text, Button } from '@chakra-ui/react';
import { LuPlus, LuUserRound } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import { usePermission } from '@/Admin/hooks/usePermission';

export default function Index({ personalManagers, filters }) {
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
    } = useResourceIndex('admin.personal-managers', filters, {
        entityLabel: 'Персональный менеджер',
    });

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            width: '80px',
        },
        {
            key: 'photo',
            label: 'Фото',
            render: (_, item) => (
                item?.media && item.media?.length > 0 ? (
                    <Image
                        src={item.media[0].original_url}
                        alt={item.name}
                        w="44px"
                        h="44px"
                        objectFit="cover"
                        borderRadius="full"
                    />
                ) : (
                    <Box
                        w="44px"
                        h="44px"
                        bg="bg.muted"
                        borderRadius="full"
                        display="flex"
                        alignItems="center"
                        justifyContent="center"
                        color="fg.muted"
                    >
                        <LuUserRound size={20} />
                    </Box>
                )
            ),
        },
        {
            key: 'name',
            label: 'Имя',
            sortable: true,
            render: (_, item) => (
                <Text fontWeight="medium">{item.name}</Text>
            ),
        },
        {
            key: 'phone',
            label: 'Телефон',
            render: (_, item) => (
                <Text fontSize="sm" color="gray.700">{item.phone || '—'}</Text>
            ),
        },
        {
            key: 'email',
            label: 'Email',
            render: (_, item) => (
                <Text fontSize="sm" color="gray.700">{item.email || '—'}</Text>
            ),
        },
        {
            key: 'erp_uuid',
            label: 'UUID в 1С',
            render: (_, item) => (
                <Text fontSize="xs" color="gray.500" fontFamily="mono">{item.erp_uuid || '—'}</Text>
            ),
        },
        {
            key: 'users_count',
            label: 'Клиентов',
            render: (_, item) => (
                <Text fontSize="sm" color="gray.700">{item.users_count ?? 0}</Text>
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
        createActionsColumn('admin.personal-managers', openDeleteDialog, { permissionPrefix: 'personal-managers' }),
    ];

    return (
        <>
            <PageHeader
                title="Персональные менеджеры"
                description="Управление персональными менеджерами клиентов"
                actions={
                    <>
                        <DeleteAllButton
                            sectionLabel="персональных менеджеров"
                            dialogOpen={deleteAllDialogOpen}
                            onOpen={openDeleteAllDialog}
                            onClose={closeDeleteAllDialog}
                            onConfirm={confirmDeleteAll}
                            isLoading={deleteAllProcessing}
                        />
                        {can('personal-managers.create') && (
                            <Button
                                colorPalette="blue"
                                onClick={() => router.visit(route('admin.personal-managers.create'))}
                            >
                                <LuPlus /> Добавить менеджера
                            </Button>
                        )}
                    </>
                }
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по имени, телефону или email..."
                />
            </Box>

            <DataTable
                data={personalManagers.data}
                columns={columns}
                pagination={personalManagers}
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
                title="Удалить персонального менеджера?"
                description={`Вы уверены, что хотите удалить менеджера «${entityToDelete?.name}»? У привязанных к нему клиентов поле «Персональный менеджер» будет очищено. Это действие нельзя отменить.`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
