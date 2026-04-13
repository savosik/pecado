import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, ConfirmDialog, DeleteAllButton } from '@/Admin/Components';
import { Button, Badge, Text } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import { usePermission } from '@/Admin/hooks/usePermission';

export default function Index({ roles, filters }) {
    const { can } = usePermission();
    const {
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
    } = useResourceIndex('admin.roles', filters, {
        entityLabel: 'Роль',
    });

    const columns = [
        { key: 'id', label: 'ID', width: '80px' },
        {
            key: 'name',
            label: 'Название',
            render: (name) => (
                <Text fontWeight="medium">
                    {name === 'super-admin' ? '🛡️ ' : ''}{name}
                </Text>
            ),
        },
        {
            key: 'permissions_count',
            label: 'Прав',
            render: (count) => <Badge colorPalette="blue">{count || 0}</Badge>,
        },
        {
            key: 'users_count',
            label: 'Пользователей',
            render: (count) => <Badge colorPalette="green">{count || 0}</Badge>,
        },
        createActionsColumn('admin.roles', openDeleteDialog, { permissionPrefix: 'roles' }),
    ];

    return (
        <>
            <PageHeader
                title="Роли"
                description="Управление ролями и правами доступа"
                actions={
                    <>
                        <DeleteAllButton
                        sectionLabel="роли"
                        dialogOpen={deleteAllDialogOpen}
                        onOpen={openDeleteAllDialog}
                        onClose={closeDeleteAllDialog}
                        onConfirm={confirmDeleteAll}
                        isLoading={deleteAllProcessing}
                    />
                        {can('roles.create') && (
                    <Button colorPalette="blue" onClick={() => router.visit(route('admin.roles.create'))}>
                        <LuPlus /> Создать роль
                    </Button>
                    )}
                    </>
                }
            />

            <DataTable
                data={roles.data}
                columns={columns}
                pagination={roles}
                perPage={filters.per_page}
                onPerPageChange={handlePerPageChange}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить роль?"
                description={`Вы уверены, что хотите удалить роль «${entityToDelete?.name}»? Все пользователи потеряют права, связанные с этой ролью.`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
