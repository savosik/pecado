import React, { useState } from "react";
import { HStack, Badge, Button, Input, Box, VStack } from "@chakra-ui/react";
import { Head, usePage, router } from "@inertiajs/react";
import { LuPlus, LuFilter, LuX } from "react-icons/lu";
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { DataTable } from "@/Admin/Components/DataTable";
import { PageHeader } from "@/Admin/Components/PageHeader";
import { ConfirmDialog } from "@/Admin/Components/ConfirmDialog";
import { toaster } from "@/components/ui/toaster";
import { Field } from "@/components/ui/field";
import { Select } from "@/components/ui/select";
import { Checkbox } from "@/components/ui/checkbox";

const getStatusColor = (status) => {
    const colors = {
        pending: "yellow",
        processing: "blue",
        shipped: "purple",
        delivered: "green",
        cancelled: "red",
    };
    return colors[status] || "gray";
};

const OrdersIndex = ({ filters, statuses, companies }) => {
    const { orders } = usePage().props;
    const [deleteId, setDeleteId] = useState(null);
    const [selectedOrders, setSelectedOrders] = useState([]);
    const [showFilters, setShowFilters] = useState(false);
    const [bulkStatus, setBulkStatus] = useState("");
    const [localFilters, setLocalFilters] = useState({
        status: filters?.status || "",
        company_id: filters?.company_id || "",
        date_from: filters?.date_from || "",
        date_to: filters?.date_to || "",
        amount_from: filters?.amount_from || "",
        amount_to: filters?.amount_to || "",
    });

    const handleSort = (field, direction) => {
        router.get(route("admin.orders.index"), {
            ...filters,
            sort_by: field,
            sort_order: direction,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleApplyFilters = () => {
        router.get(route("admin.orders.index"), {
            ...filters,
            ...localFilters,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleResetFilters = () => {
        setLocalFilters({
            status: "",
            company_id: "",
            date_from: "",
            date_to: "",
            amount_from: "",
            amount_to: "",
        });
        router.get(route("admin.orders.index"), {
            search: filters?.search,
            sort_by: filters?.sort_by,
            sort_order: filters?.sort_order,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleBulkStatusUpdate = () => {
        if (selectedOrders.length === 0) {
            toaster.create({
                description: "Выберите заказы для обновления статуса",
                type: "warning",
            });
            return;
        }

        if (!bulkStatus) {
            toaster.create({
                description: "Выберите статус",
                type: "warning",
            });
            return;
        }

        router.post(route("admin.orders.bulk-status"), {
            order_ids: selectedOrders,
            status: bulkStatus,
        }, {
            onSuccess: () => {
                toaster.create({
                    description: "Статус успешно обновлён",
                    type: "success",
                });
                setSelectedOrders([]);
                setBulkStatus("");
            },
        });
    };

    const handleSelectAll = (checked) => {
        if (checked) {
            setSelectedOrders(orders.data.map(order => order.id));
        } else {
            setSelectedOrders([]);
        }
    };

    const handleSelectOrder = (orderId, checked) => {
        if (checked) {
            setSelectedOrders([...selectedOrders, orderId]);
        } else {
            setSelectedOrders(selectedOrders.filter(id => id !== orderId));
        }
    };

    const columns = [
        {
            label: (
                <Checkbox
                    checked={selectedOrders.length === orders.data.length && orders.data.length > 0}
                    onCheckedChange={(e) => handleSelectAll(e.checked)}
                />
            ),
            key: "select",
            render: (_, order) => (
                <Checkbox
                    checked={selectedOrders.includes(order.id)}
                    onCheckedChange={(e) => handleSelectOrder(order.id, e.checked)}
                />
            ),
        },
        { label: "ID", key: "id", sortable: true },
        { label: "UUID", key: "uuid", sortable: true, render: (value) => value?.substring(0, 8) + "..." },
        {
            label: "Пользователь",
            key: "user",
            render: (_, order) => order.user?.full_name || order.user?.email || "—",
        },
        {
            label: "Компания",
            key: "company",
            render: (_, order) => order.company?.name || "—",
        },
        {
            label: "Статус",
            key: "status",
            sortable: true,
            render: (_, order) => (
                <Badge colorPalette={getStatusColor(order.status)}>
                    {order.status_label}
                </Badge>
            ),
        },
        {
            label: "Сумма",
            key: "total_amount",
            sortable: true,
            render: (value, order) => `${value} ${order.currency_code || "₽"}`,
        },
        {
            label: "Дата",
            key: "created_at",
            sortable: true,
            render: (_, order) => (
                <Text fontSize="sm" color="gray.600">
                    {order.created_at ? new Date(order.created_at).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        createActionsColumn('admin.orders', (order) => setDeleteId(order.id)),
    ];

    const handleDelete = () => {
        if (deleteId) {
            router.delete(route("admin.orders.destroy", deleteId), {
                onSuccess: () => {
                    toaster.create({
                        description: "Заказ успешно удалён",
                        type: "success",
                    });
                    setDeleteId(null);
                },
            });
        }
    };

    return (
        <>
            <Head title="Заказы" />

            <PageHeader
                title="Заказы"
                actions={
                    <HStack>
                        <Button
                            onClick={() => setShowFilters(!showFilters)}
                            variant="outline"
                        >
                            <LuFilter /> {showFilters ? "Скрыть фильтры" : "Фильтры"}
                        </Button>
                        <Button
                            onClick={() => router.visit(route("admin.orders.create"))}
                            colorPalette="blue"
                        >
                            <LuPlus /> Создать заказ
                        </Button>
                    </HStack>
                }
            />

            {/* Расширенные фильтры */}
            {showFilters && (
                <Box p={4} borderWidth="1px" borderRadius="md" mb={4}>
                    <VStack align="stretch" gap={4}>
                        <HStack align="end" gap={4}>
                            <Field label="Статус">
                                <Select.Root
                                    value={localFilters.status ? [localFilters.status] : []}
                                    onValueChange={(e) => setLocalFilters({ ...localFilters, status: e.value[0] || "" })}
                                >
                                    <Select.Trigger>
                                        <Select.ValueText placeholder="Все статусы" />
                                    </Select.Trigger>
                                    <Select.Content>
                                        <Select.Item item="">Все статусы</Select.Item>
                                        {statuses?.map((status) => (
                                            <Select.Item key={status.value} item={status.value}>
                                                {status.label}
                                            </Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                            </Field>

                            <Field label="Компания">
                                <Select.Root
                                    value={localFilters.company_id ? [String(localFilters.company_id)] : []}
                                    onValueChange={(e) => setLocalFilters({ ...localFilters, company_id: e.value[0] || "" })}
                                >
                                    <Select.Trigger>
                                        <Select.ValueText placeholder="Все компании" />
                                    </Select.Trigger>
                                    <Select.Content>
                                        <Select.Item item="">Все компании</Select.Item>
                                        {companies?.map((company) => (
                                            <Select.Item key={company.id} item={String(company.id)}>
                                                {company.name}
                                            </Select.Item>
                                        ))}
                                    </Select.Content>
                                </Select.Root>
                            </Field>

                            <Field label="Дата от">
                                <Input
                                    type="date"
                                    value={localFilters.date_from}
                                    onChange={(e) => setLocalFilters({ ...localFilters, date_from: e.target.value })}
                                />
                            </Field>

                            <Field label="Дата до">
                                <Input
                                    type="date"
                                    value={localFilters.date_to}
                                    onChange={(e) => setLocalFilters({ ...localFilters, date_to: e.target.value })}
                                />
                            </Field>
                        </HStack>

                        <HStack align="end" gap={4}>
                            <Field label="Сумма от">
                                <Input
                                    type="number"
                                    step="0.01"
                                    value={localFilters.amount_from}
                                    onChange={(e) => setLocalFilters({ ...localFilters, amount_from: e.target.value })}
                                    placeholder="0.00"
                                />
                            </Field>

                            <Field label="Сумма до">
                                <Input
                                    type="number"
                                    step="0.01"
                                    value={localFilters.amount_to}
                                    onChange={(e) => setLocalFilters({ ...localFilters, amount_to: e.target.value })}
                                    placeholder="0.00"
                                />
                            </Field>

                            <Button onClick={handleApplyFilters} colorPalette="blue">
                                Применить
                            </Button>
                            <Button onClick={handleResetFilters} variant="outline">
                                <LuX /> Сбросить
                            </Button>
                        </HStack>
                    </VStack>
                </Box>
            )}

            {/* Bulk operations */}
            {selectedOrders.length > 0 && (
                <Box p={4} borderWidth="1px" borderRadius="md" mb={4} bg="blue.50">
                    <HStack>
                        <span>Выбрано: {selectedOrders.length}</span>
                        <Select.Root
                            value={bulkStatus ? [bulkStatus] : []}
                            onValueChange={(e) => setBulkStatus(e.value[0])}
                        >
                            <Select.Trigger width="200px">
                                <Select.ValueText placeholder="Выберите статус" />
                            </Select.Trigger>
                            <Select.Content>
                                {statuses?.map((status) => (
                                    <Select.Item key={status.value} item={status.value}>
                                        {status.label}
                                    </Select.Item>
                                ))}
                            </Select.Content>
                        </Select.Root>
                        <Button onClick={handleBulkStatusUpdate} colorPalette="blue">
                            Применить статус
                        </Button>
                        <Button onClick={() => setSelectedOrders([])} variant="ghost">
                            Отменить выбор
                        </Button>
                    </HStack>
                </Box>
            )}

            <DataTable
                columns={columns}
                data={orders.data}
                pagination={orders}
                searchPlaceholder="Поиск заказов..."
                onSort={handleSort}
                sortColumn={filters?.sort_by}
                sortDirection={filters?.sort_order}
            />

            <ConfirmDialog
                open={!!deleteId}
                onClose={() => setDeleteId(null)}
                onConfirm={handleDelete}
                title="Удаление заказа"
                description="Вы уверены, что хотите удалить этот заказ? Это действие нельзя отменить."
            />
        </>
    );
};

OrdersIndex.layout = (page) => <AdminLayout>{page}</AdminLayout>;

export default OrdersIndex;
