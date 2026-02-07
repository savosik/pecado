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

const WarehousesIndex = ({ filters }) => {
    const { warehouses } = usePage().props;
    const [deleteId, setDeleteId] = React.useState(null);

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
        createActionsColumn('admin.warehouses', (warehouse) => setDeleteId(warehouse.id)),
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
                    <Button as={Link} href={route("admin.warehouses.create")} colorPalette="blue">
                        <LuPlus /> Создать склад
                    </Button>
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
