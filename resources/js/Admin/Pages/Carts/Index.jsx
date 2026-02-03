import React, { useState } from "react";
import { HStack, IconButton, Badge, Button, Input, Box, VStack, Text, SimpleGrid } from "@chakra-ui/react";
import { Head, usePage, router } from "@inertiajs/react";
import { LuTrash2, LuPencil, LuFilter, LuX, LuPlus } from "react-icons/lu";
import { AdminLayout } from "@/Admin/Layouts/AdminLayout";
import { DataTable } from "@/Admin/Components/DataTable";
import { PageHeader } from "@/Admin/Components/PageHeader";
import { ConfirmDialog } from "@/Admin/Components/ConfirmDialog";
import { toaster } from "@/components/ui/toaster";
import { Field } from "@/components/ui/field";
import { Checkbox } from "@/components/ui/checkbox";
import { ProductSelector } from "@/Admin/Components/ProductSelector";
import { EntitySelector } from "@/Admin/Components/EntitySelector";

const CartsIndex = ({ filters }) => {
    const { carts } = usePage().props;
    const [deleteId, setDeleteId] = useState(null);
    const [selectedCarts, setSelectedCarts] = useState([]);
    const [showBulkDeleteConfirm, setShowBulkDeleteConfirm] = useState(false);
    const [showFilters, setShowFilters] = useState(false);

    const [localFilters, setLocalFilters] = useState({
        date_from: filters?.date_from || "",
        date_to: filters?.date_to || "",
        items_min: filters?.items_min || "",
        items_max: filters?.items_max || "",
        product_id: filters?.product_id || null, // ID
        product: filters?.product || null, // Object
        amount_min: filters?.amount_min || "",
        amount_max: filters?.amount_max || "",
        user_id: filters?.user_id || null, // ID
        user: filters?.user || null, // Object
    });

    const handleSort = (field, direction) => {
        router.get(route("admin.carts.index"), {
            ...filters,
            sort_by: field,
            sort_order: direction,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleApplyFilters = () => {
        const { product, user, ...filtersToSend } = localFilters; // Exclude objects, send only IDs

        router.get(route("admin.carts.index"), {
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
            items_min: "",
            items_max: "",
            product_id: null,
            product: null,
            amount_min: "",
            amount_max: "",
            user_id: null,
            user: null,
        });
        router.get(route("admin.carts.index"), {
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
            setSelectedCarts(carts.data.map(cart => cart.id));
        } else {
            setSelectedCarts([]);
        }
    };

    const handleSelectCart = (cartId, checked) => {
        if (checked) {
            setSelectedCarts([...selectedCarts, cartId]);
        } else {
            setSelectedCarts(selectedCarts.filter(id => id !== cartId));
        }
    };

    const handleBulkDelete = () => {
        if (selectedCarts.length === 0) {
            toaster.create({
                description: "Выберите корзины для удаления",
                type: "warning",
            });
            return;
        }

        setShowBulkDeleteConfirm(true);
    };

    const confirmBulkDelete = () => {
        router.post(route("admin.carts.bulk-delete"), {
            cart_ids: selectedCarts,
        }, {
            onSuccess: () => {
                toaster.create({
                    description: "Корзины успешно удалены",
                    type: "success",
                });
                setSelectedCarts([]);
                setShowBulkDeleteConfirm(false);
            },
        });
    };

    const columns = [
        {
            label: (
                <Checkbox
                    checked={selectedCarts.length === carts.data.length && carts.data.length > 0}
                    onCheckedChange={(e) => handleSelectAll(e.checked)}
                />
            ),
            key: "select",
            render: (_, cart) => (
                <Checkbox
                    checked={selectedCarts.includes(cart.id)}
                    onCheckedChange={(e) => handleSelectCart(cart.id, e.checked)}
                />
            ),
        },
        { label: "ID", key: "id", sortable: true },
        { label: "Название", key: "name", sortable: true, render: (value) => value || "—" },
        {
            label: "Пользователь",
            key: "user",
            render: (_, cart) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm">{cart.user?.name || "—"}</Text>
                    {cart.user?.email && (
                        <Text fontSize="xs" color="fg.muted">{cart.user.email}</Text>
                    )}
                </VStack>
            ),
        },
        {
            label: "Позиций",
            key: "items_count",
            render: (_, cart) => (
                <Badge colorPalette={cart.items_count > 0 ? "blue" : "gray"}>
                    {cart.items_count}
                </Badge>
            ),
        },
        {
            label: "Единиц",
            key: "total_quantity",
            render: (value) => value || 0,
        },
        {
            label: "Сумма",
            key: "total_amount",
            render: (value) => `${value?.toFixed(2) || "0.00"} ₽`,
        },
        {
            label: "Создана",
            key: "created_at",
            sortable: true,
        },
        {
            label: "Обновлена",
            key: "updated_at",
            sortable: true,
        },
        {
            label: "Действия",
            key: "actions",
            render: (_, cart) => (
                <HStack gap={2}>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        onClick={() => router.visit(route("admin.carts.edit", cart.id))}
                        title="Редактировать"
                    >
                        <LuPencil />
                    </IconButton>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        colorPalette="red"
                        onClick={() => setDeleteId(cart.id)}
                        title="Удалить"
                    >
                        <LuTrash2 />
                    </IconButton>
                </HStack>
            ),
        },
    ];

    const handleDelete = () => {
        if (deleteId) {
            router.delete(route("admin.carts.destroy", deleteId), {
                onSuccess: () => {
                    toaster.create({
                        description: "Корзина успешно удалена",
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
                { label: "Корзины" },
            ]}
        >
            <Head title="Корзины" />

            <PageHeader
                title="Корзины"
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
                            onClick={() => router.visit(route("admin.carts.create"))}
                        >
                            <LuPlus /> Создать корзину
                        </Button>
                    </HStack>
                }
            />

            {/* Расширенные фильтры */}
            {/* Расширенные фильтры */}
            {showFilters && (
                <Box p={4} borderWidth="1px" borderRadius="md" mb={4}>
                    <VStack align="stretch" gap={4}>
                        <SimpleGrid columns={{ base: 1, md: 3, lg: 4 }} gap={4}>
                            <Field label="Дата создания (от)">
                                <Input
                                    type="date"
                                    value={localFilters.date_from}
                                    onChange={(e) => setLocalFilters({ ...localFilters, date_from: e.target.value })}
                                />
                            </Field>
                            <Field label="Дата создания (до)">
                                <Input
                                    type="date"
                                    value={localFilters.date_to}
                                    onChange={(e) => setLocalFilters({ ...localFilters, date_to: e.target.value })}
                                />
                            </Field>

                            <Field label="Сумма (от)">
                                <Input
                                    type="number"
                                    min="0"
                                    value={localFilters.amount_min || ""}
                                    onChange={(e) => setLocalFilters({ ...localFilters, amount_min: e.target.value })}
                                    placeholder="0"
                                />
                            </Field>
                            <Field label="Сумма (до)">
                                <Input
                                    type="number"
                                    min="0"
                                    value={localFilters.amount_max || ""}
                                    onChange={(e) => setLocalFilters({ ...localFilters, amount_max: e.target.value })}
                                    placeholder="∞"
                                />
                            </Field>

                            <Field label="Товар в корзине">
                                <ProductSelector
                                    mode="search"
                                    onSelect={(product) => setLocalFilters({ ...localFilters, product_id: product.id, product: product })}
                                    trigger={
                                        <Button variant="outline" width="100%" justifyContent="space-between" fontWeight="normal">
                                            {localFilters.product ? (
                                                <Box as="span" isTruncated>{localFilters.product.name}</Box>
                                            ) : (
                                                <Text color="fg.muted">Любой товар</Text>
                                            )}
                                        </Button>
                                    }
                                />
                                {localFilters.product_id && (
                                    <Button
                                        size="xs"
                                        variant="ghost"
                                        colorPalette="red"
                                        mt={1}
                                        onClick={() => setLocalFilters({ ...localFilters, product_id: null, product: null })}
                                    >
                                        Сбросить товар
                                    </Button>
                                )}
                            </Field>

                            <Field label="Пользователь">
                                <EntitySelector
                                    value={localFilters.user}
                                    onChange={(user) => setLocalFilters({ ...localFilters, user_id: user?.id, user: user })}
                                    searchUrl="admin.carts.search-users"
                                    placeholder="Поиск пользователя..."
                                    displayField="name"
                                />
                                {localFilters.user_id && (
                                    <Button
                                        size="xs"
                                        variant="ghost"
                                        colorPalette="red"
                                        mt={1}
                                        onClick={() => setLocalFilters({ ...localFilters, user_id: null, user: null })}
                                    >
                                        Сбросить пользователя
                                    </Button>
                                )}
                            </Field>

                            <Field label="Товаров (мин)">
                                <Input
                                    type="number"
                                    min="0"
                                    value={localFilters.items_min}
                                    onChange={(e) => setLocalFilters({ ...localFilters, items_min: e.target.value })}
                                    placeholder="0"
                                />
                            </Field>
                            <Field label="Товаров (макс)">
                                <Input
                                    type="number"
                                    min="0"
                                    value={localFilters.items_max}
                                    onChange={(e) => setLocalFilters({ ...localFilters, items_max: e.target.value })}
                                    placeholder="∞"
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
            {selectedCarts.length > 0 && (
                <Box p={4} borderWidth="1px" borderRadius="md" mb={4} bg="blue.50" _dark={{ bg: "blue.900" }}>
                    <HStack>
                        <Text>Выбрано: {selectedCarts.length}</Text>
                        <Button onClick={handleBulkDelete} colorPalette="red" size="sm">
                            <LuTrash2 /> Удалить выбранные
                        </Button>
                        <Button onClick={() => setSelectedCarts([])} variant="ghost" size="sm">
                            Отменить выбор
                        </Button>
                    </HStack>
                </Box>
            )}

            <DataTable
                columns={columns}
                data={carts.data}
                pagination={carts}
                searchPlaceholder="Поиск по ID, названию, пользователю..."
                onSort={handleSort}
                sortColumn={filters?.sort_by}
                sortDirection={filters?.sort_order}
            />

            <ConfirmDialog
                open={!!deleteId}
                onClose={() => setDeleteId(null)}
                onConfirm={handleDelete}
                title="Удаление корзины"
                description="Вы уверены, что хотите удалить эту корзину? Это действие нельзя отменить."
            />

            <ConfirmDialog
                open={showBulkDeleteConfirm}
                onClose={() => setShowBulkDeleteConfirm(false)}
                onConfirm={confirmBulkDelete}
                title="Массовое удаление корзин"
                description={`Вы уверены, что хотите удалить ${selectedCarts.length} корзин(ы)? Это действие нельзя отменить.`}
            />
        </AdminLayout>
    );
};

export default CartsIndex;
