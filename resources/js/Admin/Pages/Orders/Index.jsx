import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { HStack, Badge, Button, Input, Box, VStack, Text, IconButton, Icon } from "@chakra-ui/react";
import { Head, usePage, router } from "@inertiajs/react";
import { LuPlus, LuFilter, LuX, LuTrash2, LuSearch } from "react-icons/lu";
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { DataTable } from "@/Admin/Components/DataTable";
import { PageHeader } from "@/Admin/Components/PageHeader";
import { ConfirmDialog } from "@/Admin/Components/ConfirmDialog";
import { toaster } from "@/components/ui/toaster";
import { Field } from "@/components/ui/field";
import { Select } from "@/components/ui/select";
import { Checkbox } from "@/components/ui/checkbox";
import { usePermission } from '@/Admin/hooks/usePermission';
import { DeleteAllButton, TrashedFilter } from '@/Admin/Components';
import { getOrderStatusColor } from '@/constants/orderStatus';

const getStatusColor = getOrderStatusColor;

const getTypeLabel = (type) => type === 'preorder' ? 'Предзаказ' : 'Со склада';
const getTypeColor = (type) => type === 'preorder' ? 'purple' : 'teal';

const OrdersIndex = ({ filters, statuses, types, companies, trashedCount }) => {
    const { orders } = usePage().props;
    const { can } = usePermission();
    const [deleteId, setDeleteId] = useState(null);
    const [forceDeleteId, setForceDeleteId] = useState(null);
    const [deleteAllDialogOpen, setDeleteAllDialogOpen] = useState(false);
    const [deleteAllProcessing, setDeleteAllProcessing] = useState(false);
    const [forceDeleteAllDialogOpen, setForceDeleteAllDialogOpen] = useState(false);
    const [forceDeleteAllProcessing, setForceDeleteAllProcessing] = useState(false);

    const isTrashed = !!filters?.trashed;

    const toggleTrashed = useCallback(() => {
        router.get(route('admin.orders.index'), { trashed: isTrashed ? undefined : 1 }, { preserveState: false });
    }, [isTrashed]);

    const openDeleteAllDialog = useCallback(() => setDeleteAllDialogOpen(true), []);
    const closeDeleteAllDialog = useCallback(() => setDeleteAllDialogOpen(false), []);
    const openForceDeleteAllDialog = useCallback(() => setForceDeleteAllDialogOpen(true), []);
    const closeForceDeleteAllDialog = useCallback(() => setForceDeleteAllDialogOpen(false), []);

    const confirmDeleteAll = useCallback(() => {
        setDeleteAllProcessing(true);
        router.delete(route('admin.bulk-delete-all', 'orders'), {
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
    }, []);

    const confirmForceDeleteAll = useCallback(() => {
        setForceDeleteAllProcessing(true);
        router.delete(route('admin.bulk-force-delete-all', 'orders'), {
            onSuccess: () => {
                toaster.create({ title: 'Окончательное удаление запущено в фоне', type: 'info' });
                setForceDeleteAllDialogOpen(false);
                setForceDeleteAllProcessing(false);
            },
            onError: () => {
                toaster.create({ title: 'Ошибка при запуске удаления', type: 'error' });
                setForceDeleteAllProcessing(false);
            },
        });
    }, []);
    const [selectedOrders, setSelectedOrders] = useState([]);
    const [showFilters, setShowFilters] = useState(false);
    const [bulkStatus, setBulkStatus] = useState("");
    const [localFilters, setLocalFilters] = useState({
        status:      filters?.status || "",
        type:        filters?.type || "",
        company_id:  filters?.company_id || "",
        date_from:   filters?.date_from || "",
        date_to:     filters?.date_to || "",
        amount_from: filters?.amount_from || "",
        amount_to:   filters?.amount_to || "",
    });

    const [searchInput, setSearchInput] = useState(filters?.search || "");
    const lastSentSearchRef = useRef(filters?.search || "");

    useEffect(() => {
        const next = (filters?.search || "");
        if (next !== lastSentSearchRef.current) {
            lastSentSearchRef.current = next;
            setSearchInput(next);
        }
    }, [filters?.search]);

    useEffect(() => {
        const trimmed = searchInput.trim();
        if (trimmed === (lastSentSearchRef.current || "")) {
            return;
        }
        const handle = setTimeout(() => {
            lastSentSearchRef.current = trimmed;
            router.get(route("admin.orders.index"), {
                ...filters,
                search: trimmed || undefined,
            }, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 350);
        return () => clearTimeout(handle);
    }, [searchInput, filters]);

    const handleClearSearch = useCallback(() => {
        setSearchInput("");
    }, []);

    const handleSort = useCallback((field, direction) => {
        router.get(route("admin.orders.index"), {
            ...filters,
            sort_by: field,
            sort_order: direction,
        }, {
            preserveState: true,
            replace: true,
        });
    }, [filters]);

    const handleApplyFilters = useCallback(() => {
        router.get(route("admin.orders.index"), {
            ...filters,
            ...localFilters,
        }, {
            preserveState: true,
            replace: true,
        });
    }, [filters, localFilters]);

    const handleResetFilters = useCallback(() => {
        setLocalFilters({
            status: "", type: "", company_id: "",
            date_from: "", date_to: "", amount_from: "", amount_to: "",
        });
        router.get(route("admin.orders.index"), {
            search: filters?.search,
            sort_by: filters?.sort_by,
            sort_order: filters?.sort_order,
        }, { preserveState: true, replace: true });
    }, [filters?.search, filters?.sort_by, filters?.sort_order]);

    const handleBulkStatusUpdate = useCallback(() => {
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
    }, [selectedOrders, bulkStatus]);

    const handleSelectAll = useCallback((checked) => {
        if (checked) {
            setSelectedOrders(orders.data.map(order => order.id));
        } else {
            setSelectedOrders([]);
        }
    }, [orders.data]);

    const handleSelectOrder = useCallback((orderId, checked) => {
        setSelectedOrders((prev) => (
            checked ? [...prev, orderId] : prev.filter(id => id !== orderId)
        ));
    }, []);

    const selectedOrdersSet = useMemo(() => new Set(selectedOrders), [selectedOrders]);

    const openDeleteDialog = useCallback((order) => setDeleteId(order.id), []);
    const openForceDeleteDialog = useCallback((order) => setForceDeleteId(order.id), []);
    const closeDeleteDialog = useCallback(() => setDeleteId(null), []);
    const closeForceDeleteDialog = useCallback(() => setForceDeleteId(null), []);
    const clearSelection = useCallback(() => setSelectedOrders([]), []);
    const toggleShowFilters = useCallback(() => setShowFilters((prev) => !prev), []);
    const goToCreateOrder = useCallback(() => router.visit(route("admin.orders.create")), []);

    const columns = useMemo(() => [
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
                    checked={selectedOrdersSet.has(order.id)}
                    onCheckedChange={(e) => handleSelectOrder(order.id, e.checked)}
                />
            ),
        },
        { label: "ID", key: "id", sortable: true },
        { label: "Номер сайта", key: "number", sortable: true, render: (value, order) => value || ("#" + order.id) },
        { label: "Номер 1С", key: "erp_number", sortable: false, render: (value) => value || "—" },
        {
            label: "Пользователь",
            key: "user",
            render: (_, order) => order.user?.name || order.user?.email || "—",
        },
        {
            label: "Компания",
            key: "company",
            render: (_, order) => order.company?.name || "—",
        },
        {
            label: "Тип",
            key: "type",
            render: (_, order) => (
                <Badge colorPalette={getTypeColor(order.type)} variant="subtle" size="sm">
                    {getTypeLabel(order.type)}
                </Badge>
            ),
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
                <Box>
                    <Text fontSize="sm" color="gray.600">{order.created_at || '—'}</Text>
                    {order.deleted_at && (
                        <Badge colorPalette="red" variant="subtle" size="xs" mt={0.5}>
                            Удалён: {order.deleted_at}
                        </Badge>
                    )}
                </Box>
            ),
        },
        {
            label: "Создано в 1С",
            key: "erp_created_at",
            sortable: false,
            render: (_, order) => (
                <Text fontSize="sm" color="gray.600">{order.erp_created_at || '—'}</Text>
            ),
        },
        isTrashed
            ? {
                key: 'actions',
                label: 'Действия',
                render: (_, order) => can('orders.delete') ? (
                    <IconButton
                        size="sm"
                        variant="ghost"
                        colorPalette="red"
                        aria-label="Удалить окончательно"
                        title="Удалить окончательно"
                        onClick={() => setForceDeleteId(order.id)}
                    >
                        <LuTrash2 />
                    </IconButton>
                ) : null,
            }
            : createActionsColumn('admin.orders', openDeleteDialog, { permissionPrefix: 'orders', showView: true }),
    ], [selectedOrders.length, orders.data, selectedOrdersSet, isTrashed, can, handleSelectAll, handleSelectOrder, openDeleteDialog]);

    const handleDelete = useCallback(() => {
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
    }, [deleteId]);

    const handleForceDelete = useCallback(() => {
        if (forceDeleteId) {
            router.delete(route("admin.orders.force-delete", forceDeleteId), {
                onSuccess: () => {
                    toaster.create({ description: "Заказ окончательно удалён", type: "success" });
                    setForceDeleteId(null);
                },
            });
        }
    }, [forceDeleteId]);

    return (
        <>
            <Head title="Заказы" />

            <PageHeader
                title="Заказы"
                actions={
                    <HStack>
                        <TrashedFilter
                            trashed={isTrashed}
                            trashedCount={trashedCount}
                            onToggle={toggleTrashed}
                        />
                        {isTrashed ? (
                            <DeleteAllButton
                                sectionLabel="удалённые заказы окончательно"
                                dialogOpen={forceDeleteAllDialogOpen}
                                onOpen={openForceDeleteAllDialog}
                                onClose={closeForceDeleteAllDialog}
                                onConfirm={confirmForceDeleteAll}
                                isLoading={forceDeleteAllProcessing}
                            />
                        ) : (
                            <DeleteAllButton
                                sectionLabel="заказы"
                                dialogOpen={deleteAllDialogOpen}
                                onOpen={openDeleteAllDialog}
                                onClose={closeDeleteAllDialog}
                                onConfirm={confirmDeleteAll}
                                isLoading={deleteAllProcessing}
                            />
                        )}
                        <Button
                            onClick={toggleShowFilters}
                            variant="outline"
                        >
                            <LuFilter /> {showFilters ? "Скрыть фильтры" : "Фильтры"}
                        </Button>
                        {!isTrashed && can('orders.create') && (
                            <Button
                                onClick={goToCreateOrder}
                                colorPalette="blue"
                            >
                                <LuPlus /> Создать заказ
                            </Button>
                        )}
                    </HStack>
                }
            />

            {/* Поиск */}
            <Box mb={4} position="relative">
                <Box
                    position="absolute"
                    top="50%"
                    left={3}
                    transform="translateY(-50%)"
                    color="fg.muted"
                    pointerEvents="none"
                    display="flex"
                    alignItems="center"
                >
                    <Icon as={LuSearch} boxSize={4} />
                </Box>
                <Input
                    value={searchInput}
                    onChange={(e) => setSearchInput(e.target.value)}
                    placeholder="Поиск по номеру, UUID, клиенту, компании, ИНН, товару…"
                    pl={9}
                    pr={searchInput ? 10 : 3}
                    aria-label="Поиск заказов"
                />
                {searchInput && (
                    <IconButton
                        aria-label="Очистить поиск"
                        variant="ghost"
                        size="xs"
                        position="absolute"
                        top="50%"
                        right={2}
                        transform="translateY(-50%)"
                        onClick={handleClearSearch}
                    >
                        <LuX />
                    </IconButton>
                )}
            </Box>

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

                            <Field label="Тип">
                                <Select.Root
                                    value={localFilters.type ? [localFilters.type] : []}
                                    onValueChange={(e) => setLocalFilters({ ...localFilters, type: e.value[0] || "" })}
                                >
                                    <Select.Trigger>
                                        <Select.ValueText placeholder="Все типы" />
                                    </Select.Trigger>
                                    <Select.Content>
                                        <Select.Item item="">Все типы</Select.Item>
                                        {types?.map((t) => (
                                            <Select.Item key={t.value} item={t.value}>
                                                {t.label}
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
                        {can('orders.edit') && (
                        <Button onClick={handleBulkStatusUpdate} colorPalette="blue">
                            Применить статус
                        </Button>
                        )}
                        <Button onClick={clearSelection} variant="ghost">
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
                onClose={closeDeleteDialog}
                onConfirm={handleDelete}
                title="Удаление заказа"
                description="Вы уверены, что хотите удалить этот заказ? Это действие нельзя отменить."
            />

            <ConfirmDialog
                open={!!forceDeleteId}
                onClose={closeForceDeleteDialog}
                onConfirm={handleForceDelete}
                title="Окончательное удаление заказа"
                description="Вы уверены, что хотите окончательно удалить этот заказ? Запись будет удалена безвозвратно."
                confirmLabel="Удалить окончательно"
                colorPalette="red"
            />
        </>
    );
};

OrdersIndex.layout = (page) => <AdminLayout>{page}</AdminLayout>;

export default OrdersIndex;
