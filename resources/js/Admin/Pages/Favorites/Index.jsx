import React, { useState } from "react";
import { HStack, Image, Text, Box, Button, Input, VStack, SimpleGrid } from "@chakra-ui/react";
import { Head, usePage, router } from "@inertiajs/react";
import { LuTrash2, LuPackage, LuFilter, LuX, LuPlus } from "react-icons/lu";
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { DataTable } from "@/Admin/Components/DataTable";
import { PageHeader } from "@/Admin/Components/PageHeader";
import { ConfirmDialog } from "@/Admin/Components/ConfirmDialog";
import { toaster } from "@/components/ui/toaster";
import { Field } from "@/components/ui/field";
import { Checkbox } from "@/components/ui/checkbox";
import { ProductSelector } from "@/Admin/Components/ProductSelector";
import { EntitySelector } from "@/Admin/Components/EntitySelector";

const FavoritesIndex = ({ filters }) => {
    const { favorites } = usePage().props;
    const [deleteId, setDeleteId] = useState(null);
    const [selectedItems, setSelectedItems] = useState([]);
    const [showBulkDeleteConfirm, setShowBulkDeleteConfirm] = useState(false);
    const [showFilters, setShowFilters] = useState(false);

    const [localFilters, setLocalFilters] = useState({
        date_from: filters?.date_from || "",
        date_to: filters?.date_to || "",
        product_id: filters?.product_id || null,
        product: filters?.product || null,
        user_id: filters?.user_id || null,
        user: filters?.user || null,
    });

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

    const handleApplyFilters = () => {
        const { product, user, ...filtersToSend } = localFilters;

        router.get(route("admin.favorites.index"), {
            ...filters,
            ...filtersToSend,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleResetFilters = () => {
        setLocalFilters({
            date_from: "",
            date_to: "",
            product_id: null,
            product: null,
            user_id: null,
            user: null,
        });
        router.get(route("admin.favorites.index"), {
            search: filters?.search,
            sort_by: filters?.sort_by,
            sort_order: filters?.sort_order,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleSelectAll = (checked) => {
        if (checked) {
            setSelectedItems(favorites.data.map(item => item.id));
        } else {
            setSelectedItems([]);
        }
    };

    const handleSelectItem = (itemId, checked) => {
        if (checked) {
            setSelectedItems([...selectedItems, itemId]);
        } else {
            setSelectedItems(selectedItems.filter(id => id !== itemId));
        }
    };

    const handleBulkDelete = () => {
        if (selectedItems.length === 0) {
            toaster.create({
                description: "Выберите записи для удаления",
                type: "warning",
            });
            return;
        }

        setShowBulkDeleteConfirm(true);
    };

    const confirmBulkDelete = () => {
        router.post(route("admin.favorites.bulk-delete"), {
            favorite_ids: selectedItems,
        }, {
            onSuccess: () => {
                toaster.create({
                    description: "Записи успешно удалены",
                    type: "success",
                });
                setSelectedItems([]);
                setShowBulkDeleteConfirm(false);
            },
        });
    };

    const columns = [
        {
            label: (
                <Checkbox
                    checked={selectedItems.length === favorites.data.length && favorites.data.length > 0}
                    onCheckedChange={(e) => handleSelectAll(e.checked)}
                />
            ),
            key: "select",
            render: (_, favorite) => (
                <Checkbox
                    checked={selectedItems.includes(favorite.id)}
                    onCheckedChange={(e) => handleSelectItem(favorite.id, e.checked)}
                />
            ),
        },
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
            render: (_, favorite) => (
                <Text fontSize="sm" color="gray.600">
                    {favorite.created_at ? new Date(favorite.created_at).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        createActionsColumn('admin.favorites', (favorite) => setDeleteId(favorite.id)),
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
        <>
            <Head title="Избранное" />

            <PageHeader
                title="Избранное пользователей"
                actions={
                    <HStack>
                        <Button
                            onClick={() => setShowFilters(!showFilters)}
                            variant="outline"
                        >
                            <LuFilter /> {showFilters ? "Скрыть фильтры" : "Фильтры"}
                        </Button>
                        <Button
                            colorPalette="blue"
                            onClick={() => router.visit(route("admin.favorites.create"))}
                        >
                            <LuPlus /> Добавить в избранное
                        </Button>
                    </HStack>
                }
            />

            {/* Расширенные фильтры */}
            {showFilters && (
                <Box p={4} borderWidth="1px" borderRadius="md" mb={4}>
                    <VStack align="stretch" gap={4}>
                        <SimpleGrid columns={{ base: 1, md: 2, lg: 4 }} gap={4}>
                            <Field label="Дата добавления (от)">
                                <Input
                                    type="date"
                                    value={localFilters.date_from}
                                    onChange={(e) => setLocalFilters({ ...localFilters, date_from: e.target.value })}
                                />
                            </Field>
                            <Field label="Дата добавления (до)">
                                <Input
                                    type="date"
                                    value={localFilters.date_to}
                                    onChange={(e) => setLocalFilters({ ...localFilters, date_to: e.target.value })}
                                />
                            </Field>

                            <Field label="Товар">
                                <ProductSelector
                                    mode="single"
                                    value={localFilters.product}
                                    onChange={(product) => setLocalFilters({
                                        ...localFilters,
                                        product_id: product ? product.id : null,
                                        product: product
                                    })}
                                />
                            </Field>

                            <Field label="Пользователь">
                                <EntitySelector
                                    value={localFilters.user}
                                    onChange={(user) => setLocalFilters({ ...localFilters, user_id: user?.id, user: user })}
                                    searchUrl="admin.favorites.search-users"
                                    placeholder="Поиск пользователя..."
                                    displayField="name"
                                />
                            </Field>
                        </SimpleGrid>

                        <HStack justify="flex-end" gap={4}>
                            <Button onClick={handleResetFilters} variant="outline">
                                <LuX /> Сбросить всё
                            </Button>
                            <Button onClick={handleApplyFilters} colorPalette="blue">
                                Применить фильтры
                            </Button>
                        </HStack>
                    </VStack>
                </Box>
            )}

            {/* Массовые действия */}
            {selectedItems.length > 0 && (
                <Box p={4} borderWidth="1px" borderRadius="md" mb={4} bg="blue.50" _dark={{ bg: "blue.900" }}>
                    <HStack>
                        <Text>Выбрано: {selectedItems.length}</Text>
                        <Button onClick={handleBulkDelete} colorPalette="red" size="sm">
                            <LuTrash2 /> Удалить выбранные
                        </Button>
                        <Button onClick={() => setSelectedItems([])} variant="ghost" size="sm">
                            Отменить выбор
                        </Button>
                    </HStack>
                </Box>
            )}

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

            <ConfirmDialog
                open={showBulkDeleteConfirm}
                onClose={() => setShowBulkDeleteConfirm(false)}
                onConfirm={confirmBulkDelete}
                title="Массовое удаление избранного"
                description={`Вы уверены, что хотите удалить ${selectedItems.length} записей? Это действие нельзя отменить.`}
            />
        </>
    );
};

FavoritesIndex.layout = (page) => <AdminLayout>{page}</AdminLayout>;

export default FavoritesIndex;
