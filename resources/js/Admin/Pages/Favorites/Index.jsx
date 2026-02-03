import React from "react";
import { HStack, IconButton, Image, Text, Box } from "@chakra-ui/react";
import { Head, usePage, router } from "@inertiajs/react";
import { LuTrash2, LuPackage } from "react-icons/lu";
import { AdminLayout } from "@/Admin/Layouts/AdminLayout";
import { DataTable } from "@/Admin/Components/DataTable";
import { PageHeader } from "@/Admin/Components/PageHeader";
import { ConfirmDialog } from "@/Admin/Components/ConfirmDialog";
import { toaster } from "@/components/ui/toaster";

const FavoritesIndex = ({ filters }) => {
    const { favorites } = usePage().props;
    const [deleteId, setDeleteId] = React.useState(null);

    const handleSort = (field, direction) => {
        router.get(route("admin.favorites.index"), {
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
        {
            label: "Товар",
            key: "product",
            render: (_, favorite) => (
                <HStack gap={3}>
                    {favorite.product?.image_url ? (
                        <Image
                            src={favorite.product.image_url}
                            alt={favorite.product.name}
                            boxSize="40px"
                            objectFit="cover"
                            borderRadius="md"
                        />
                    ) : (
                        <Box
                            boxSize="40px"
                            bg="bg.muted"
                            borderRadius="md"
                            display="flex"
                            alignItems="center"
                            justifyContent="center"
                        >
                            <LuPackage />
                        </Box>
                    )}
                    <Text>{favorite.product?.name || "Товар удалён"}</Text>
                </HStack>
            ),
        },
        {
            label: "Пользователь",
            key: "user",
            render: (_, favorite) => (
                <Box>
                    <Text>{favorite.user?.name || "—"}</Text>
                    {favorite.user?.email && (
                        <Text color="fg.muted" fontSize="xs">{favorite.user.email}</Text>
                    )}
                </Box>
            ),
        },
        {
            label: "Дата добавления",
            key: "created_at",
            sortable: true,
        },
        {
            label: "Действия",
            key: "actions",
            render: (_, favorite) => (
                <IconButton
                    size="sm"
                    variant="ghost"
                    colorPalette="red"
                    onClick={() => setDeleteId(favorite.id)}
                >
                    <LuTrash2 />
                </IconButton>
            ),
        },
    ];

    const handleDelete = () => {
        if (deleteId) {
            router.delete(route("admin.favorites.destroy", deleteId), {
                onSuccess: () => {
                    toaster.create({
                        description: "Запись избранного удалена",
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
                { label: "Избранное" },
            ]}
        >
            <Head title="Избранное" />

            <PageHeader title="Избранное пользователей" />

            <DataTable
                columns={columns}
                data={favorites.data}
                pagination={favorites}
                searchPlaceholder="Поиск по пользователю или товару..."
                onSort={handleSort}
                sortColumn={filters?.sort_by}
                sortDirection={filters?.sort_order}
            />

            <ConfirmDialog
                open={!!deleteId}
                onClose={() => setDeleteId(null)}
                onConfirm={handleDelete}
                title="Удаление из избранного"
                description="Вы уверены, что хотите удалить эту запись? Это действие нельзя отменить."
            />
        </AdminLayout>
    );
};

export default FavoritesIndex;
