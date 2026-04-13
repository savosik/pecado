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

const RegionsIndex = ({ filters }) => {
    const { regions } = usePage().props;
    const { can } = usePermission();
    const [deleteId, setDeleteId] = React.useState(null);
    const [deleteAllDialogOpen, setDeleteAllDialogOpen] = React.useState(false);
    const [deleteAllProcessing, setDeleteAllProcessing] = React.useState(false);
    const openDeleteAllDialog = () => setDeleteAllDialogOpen(true);
    const closeDeleteAllDialog = () => setDeleteAllDialogOpen(false);
    const confirmDeleteAll = () => {
        setDeleteAllProcessing(true);
        router.delete(route('admin.bulk-delete-all', 'regions'), {
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
        router.get(route("admin.regions.index"), {
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
        {
            label: "Основные склады",
            key: "primary_warehouses",
            render: (warehouses) => (
                <div style={{ display: "flex", flexWrap: "wrap", gap: "4px" }}>
                    {warehouses?.map((w) => (
                        <span
                            key={w.id}
                            style={{
                                padding: "2px 6px",
                                backgroundColor: "#e2e8f0",
                                borderRadius: "4px",
                                fontSize: "12px",
                            }}
                        >
                            {w.name}
                        </span>
                    ))}
                </div>
            ),
        },
        {
            label: "Склады предзаказа",
            key: "preorder_warehouses",
            render: (warehouses) => (
                <div style={{ display: "flex", flexWrap: "wrap", gap: "4px" }}>
                    {warehouses?.map((w) => (
                        <span
                            key={w.id}
                            style={{
                                padding: "2px 6px",
                                backgroundColor: "#edf2f7",
                                borderRadius: "4px",
                                fontSize: "12px",
                            }}
                        >
                            {w.name}
                        </span>
                    ))}
                </div>
            ),
        },
        createActionsColumn('admin.regions', (region) => setDeleteId(region.id), { permissionPrefix: 'regions' }),
    ];

    const handleDelete = () => {
        if (deleteId) {
            router.delete(route("admin.regions.destroy", deleteId), {
                onSuccess: () => {
                    toaster.create({
                        description: "Регион успешно удален",
                        type: "success",
                    });
                    setDeleteId(null);
                },
            });
        }
    };

    return (
        <>
            <Head title="Регионы" />

            <PageHeader
                title="Регионы"
                actions={
                    <>
                        <DeleteAllButton
                        sectionLabel="регионы"
                        dialogOpen={deleteAllDialogOpen}
                        onOpen={openDeleteAllDialog}
                        onClose={closeDeleteAllDialog}
                        onConfirm={confirmDeleteAll}
                        isLoading={deleteAllProcessing}
                    />
                        {can('regions.create') && (
                    <Button as={Link} href={route("admin.regions.create")} colorPalette="blue">
                        <LuPlus /> Создать регион
                    </Button>
                    )}
                    </>
                }
            />

            <DataTable
                columns={columns}
                data={regions.data}
                pagination={regions}
                searchPlaceholder="Поиск регионов..."
                onSort={handleSort}
                sortColumn={filters?.sort_by}
                sortDirection={filters?.sort_order}
            />

            <ConfirmDialog
                open={!!deleteId}
                onClose={() => setDeleteId(null)}
                onConfirm={handleDelete}
                title="Удаление региона"
                description="Вы уверены, что хотите удалить этот регион? Это действие нельзя отменить."
            />
        </>
    );
};

RegionsIndex.layout = (page) => <AdminLayout>{page}</AdminLayout>;

export default RegionsIndex;
