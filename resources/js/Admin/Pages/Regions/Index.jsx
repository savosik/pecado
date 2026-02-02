import React from "react";
import { HStack, Button, IconButton } from "@chakra-ui/react";
import { Head, Link, usePage, router } from "@inertiajs/react";
import { LuPlus, LuPencil, LuTrash2 } from "react-icons/lu";
import { AdminLayout } from "@/Admin/Layouts/AdminLayout";
import { DataTable } from "@/Admin/Components/DataTable";
import { PageHeader } from "@/Admin/Components/PageHeader";
import { ConfirmDialog } from "@/Admin/Components/ConfirmDialog";
import { toaster } from "@/components/ui/toaster";

const RegionsIndex = ({ filters }) => {
    const { regions } = usePage().props;
    const [deleteId, setDeleteId] = React.useState(null);

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
        {
            label: "Действия",
            key: "actions",
            render: (_value, region) => (
                <HStack gap={2}>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        onClick={() => router.visit(route("admin.regions.edit", region.id))}
                    >
                        <LuPencil />
                    </IconButton>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        colorPalette="red"
                        onClick={() => setDeleteId(region.id)}
                    >
                        <LuTrash2 />
                    </IconButton>
                </HStack>
            ),
        },
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
        <AdminLayout
            breadcrumbs={[
                { label: "Главная", href: route("admin.dashboard") },
                { label: "Регионы" },
            ]}
        >
            <Head title="Регионы" />

            <PageHeader
                title="Регионы"
                actions={
                    <Button as={Link} href={route("admin.regions.create")} colorPalette="blue">
                        <LuPlus /> Создать регион
                    </Button>
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
        </AdminLayout>
    );
};

export default RegionsIndex;
