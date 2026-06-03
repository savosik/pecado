import React from "react";
import { Button } from "@chakra-ui/react";
import { Head, Link, usePage, router } from "@inertiajs/react";
import { LuPlus } from "react-icons/lu";
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { DataTable } from "@/Admin/Components/DataTable";
import { PageHeader } from "@/Admin/Components/PageHeader";
import { ConfirmDialog } from "@/Admin/Components/ConfirmDialog";
import { toaster } from "@/components/ui/toaster";
import { usePermission } from '@/Admin/hooks/usePermission';
import { DeleteAllButton } from '@/Admin/Components';

const WarehousesIndex = ({ filters }) => {
    const { warehouses } = usePage().props;
    const { can } = usePermission();
    const [deleteId, setDeleteId] = React.useState(null);
    const [deleteAllDialogOpen, setDeleteAllDialogOpen] = React.useState(false);
    const [deleteAllProcessing, setDeleteAllProcessing] = React.useState(false);
    const openDeleteAllDialog = () => setDeleteAllDialogOpen(true);
    const closeDeleteAllDialog = () => setDeleteAllDialogOpen(false);
    const confirmDeleteAll = () => {
        setDeleteAllProcessing(true);
        router.delete(route('admin.bulk-delete-all', 'warehouses'), {
            onSuccess: () => {
                toaster.create({ title: 'Все записи успешно удалены', type: 'success' });
                setDeleteAllDialogOpen(false);
                setDeleteAllProcessing(false);
            },
            onError: () => {
                toaster.create({ title: 'Ошибка при массовом удалении', type: 'error' });
                setDeleteAllProcessing(false);
            },
        });
    };

    const handleSort = (field, direction) => {
        router.get(route("admin.warehouses.index"), {
            ...filters,
            sort_by: field,
            sort_order: direction,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const columns = [
        { label: "ID", key: "id", sortable: true },
        { label: "Название", key: "name", sortable: true },
        { label: "Внешний ID", key: "external_id", sortable: true },
        createActionsColumn('admin.warehouses', (warehouse) => setDeleteId(warehouse.id), { permissionPrefix: 'warehouses', showView: true }),
    ];

    const handleDelete = () => {
        if (deleteId) {
            router.delete(route("admin.warehouses.destroy", deleteId), {
                onSuccess: () => {
                    toaster.create({
                        description: "Склад успешно удален",
                        type: "success",
                    });
                    setDeleteId(null);
                },
            });
        }
    };

    return (
        <>
            <Head title="Склады" />

            <PageHeader
                title="Склады"
                actions={
                    <>
                        <DeleteAllButton
                        sectionLabel="склады"
                        dialogOpen={deleteAllDialogOpen}
                        onOpen={openDeleteAllDialog}
                        onClose={closeDeleteAllDialog}
                        onConfirm={confirmDeleteAll}
                        isLoading={deleteAllProcessing}
                    />
                        {can('warehouses.create') && (
                    <Button as={Link} href={route("admin.warehouses.create")} colorPalette="blue">
                        <LuPlus /> Создать склад
                    </Button>
                    )}
                    </>
                }
            />

            <DataTable
                columns={columns}
                data={warehouses.data}
                pagination={warehouses}
                searchPlaceholder="Поиск складов..."
                onSort={handleSort}
                sortColumn={filters?.sort_by}
                sortDirection={filters?.sort_order}
            />

            <ConfirmDialog
                open={!!deleteId}
                onClose={() => setDeleteId(null)}
                onConfirm={handleDelete}
                title="Удаление склада"
                description="Вы уверены, что хотите удалить этот склад? Это действие нельзя отменить."
            />
        </>
    );
};

WarehousesIndex.layout = (page) => <AdminLayout>{page}</AdminLayout>;

export default WarehousesIndex;
