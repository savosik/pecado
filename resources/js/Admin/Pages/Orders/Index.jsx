import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { HStack, Badge, Button, Input, Box, VStack, Text, IconButton, Icon, createListCollection } from "@chakra-ui/react";
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
import { Tooltip } from "@/components/ui/tooltip";
import { Checkbox } from "@/components/ui/checkbox";
import { usePermission } from '@/Admin/hooks/usePermission';
import { DeleteAllButton, TrashedFilter } from '@/Admin/Components';
import { getOrderStatusColor } from '@/constants/orderStatus';
import { getOrderTypeShortLabel as getTypeLabel, getOrderTypeColor as getTypeColor } from '@/constants/orderType';

const getStatusColor = getOrderStatusColor;

/**
 * Селект фильтра. Chakra v3 требует collection у Select.Root и объект
 * (а не строку) в item — без этого выпадающий список не выбирается.
 */
const FilterSelect = ({ label, collection, value, onChange, placeholder }) => (
    <Field label={label}>
        <Select.Root
            collection={collection}
            value={[value ?? ""]}
            onValueChange={(e) => onChange(e.value[0] ?? "")}
        >
            <Select.Trigger>
                <Select.ValueText placeholder={placeholder} />
            </Select.Trigger>
            <Select.Content>
                {collection.items.map((item) => (
                    <Select.Item key={item.value} item={item}>
                        {item.label}
                    </Select.Item>
                ))}
            </Select.Content>
        </Select.Root>
    </Field>
);

const OrdersIndex = ({ filters, statuses, types, companies, organizations, warehouses, organizationsEnabled, trashedCount }) => {
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
        organization_id: filters?.organization_id || "",
        warehouse_id: filters?.warehouse_id || "",
        date_from:   filters?.date_from || "",
        date_to:     filters?.date_to || "",
        amount_from: filters?.amount_from || "",
        amount_to:   filters?.amount_to || "",
    });

    // Коллекции для селектов фильтров. «Не указана»/«Не указан» — рабочий
    // фильтр переходного периода, а не заглушка: таких заказов много.
    const statusCollection = useMemo(() => createListCollection({
        items: [
            { label: "Все статусы", value: "" },
            ...(statuses ?? []).map((status) => ({ label: status.label, value: status.value })),
        ],
    }), [statuses]);

    const typeCollection = useMemo(() => createListCollection({
        items: [
            { label: "Все типы", value: "" },
            ...(types ?? []).map((type) => ({ label: type.label, value: type.value })),
        ],
    }), [types]);

    const companyCollection = useMemo(() => createListCollection({
        items: [
            { label: "Все компании", value: "" },
            ...(companies ?? []).map((company) => ({ label: company.name, value: String(company.id) })),
        ],
    }), [companies]);

    const organizationCollection = useMemo(() => createListCollection({
        items: [
            { label: "Все организации", value: "" },
            { label: "Не указана", value: "none" },
            ...(organizations ?? []).map((organization) => ({
                label: organization.is_stub ? `${organization.name} (не заведена)` : organization.name,
                value: String(organization.id),
            })),
        ],
    }), [organizations]);

    const warehouseCollection = useMemo(() => createListCollection({
        items: [
            { label: "Все склады", value: "" },
            { label: "Не указан", value: "none" },
            ...(warehouses ?? []).map((warehouse) => ({ label: warehouse.name, value: String(warehouse.id) })),
        ],
    }), [warehouses]);

    const bulkStatusCollection = useMemo(() => createListCollection({
        items: (statuses ?? []).map((status) => ({ label: status.label, value: status.value })),
    }), [statuses]);

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
            status: "", type: "", company_id: "", organization_id: "", warehouse_id: "",
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
        // Организация и склад — решение 1С: на какое наше юрлицо проведён заказ
        // и откуда он уедет. Колонки нет, пока функциональность не включена флагом.
        ...(organizationsEnabled ? [{
            label: "Организация",
            key: "organization",
            render: (_, order) => (
                <Box>
                    {order.organization ? (
                        <HStack gap={1}>
                            <Text fontSize="sm">{order.organization.name}</Text>
                            {order.organization.is_stub && (
                                <Badge colorPalette="orange" variant="subtle" size="xs">не заведена</Badge>
                            )}
                        </HStack>
                    ) : (
                        <Text fontSize="sm" color="gray.500">—</Text>
                    )}
                    {order.warehouse && (
                        <Text fontSize="xs" color="gray.500">Склад: {order.warehouse.name}</Text>
                    )}
                </Box>
            ),
        }] : []),
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
                <HStack gap="1" flexWrap="wrap">
                    <Badge colorPalette={getStatusColor(order.status)}>
                        {order.status_label}
                    </Badge>
                    {/* v16.9.0 (res-12): резерв клиента. Из 1С такой заказ приходит
                        со статусом «Готов к отгрузке» — без бейджа менеджер решит,
                        что заказ завис, и полезет его двигать. */}
                    {order.reserve && (
                        <Tooltip content={`Резерв клиента до ${order.reserved_until || "—"}. Клиент согласовывает заказ сам — не редактировать и не двигать по статусам`}>
                            <Badge colorPalette="purple" variant="solid">
                                В резерве
                            </Badge>
                        </Tooltip>
                    )}
                </HStack>
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
            ? createActionsColumn('admin.orders', (order) => setForceDeleteId(order.id), {
                showView: false,
                showEdit: false,
                permissionPrefix: 'orders',
                deleteLabel: 'Удалить окончательно',
            })
            : createActionsColumn('admin.orders', openDeleteDialog, { permissionPrefix: 'orders' }),
    ], [selectedOrders.length, orders.data, selectedOrdersSet, isTrashed, organizationsEnabled, can, handleSelectAll, handleSelectOrder, openDeleteDialog]);

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
                            <FilterSelect
                                label="Статус"
                                collection={statusCollection}
                                placeholder="Все статусы"
                                value={localFilters.status}
                                onChange={(value) => setLocalFilters((prev) => ({ ...prev, status: value }))}
                            />

                            <FilterSelect
                                label="Тип"
                                collection={typeCollection}
                                placeholder="Все типы"
                                value={localFilters.type}
                                onChange={(value) => setLocalFilters((prev) => ({ ...prev, type: value }))}
                            />

                            <FilterSelect
                                label="Компания"
                                collection={companyCollection}
                                placeholder="Все компании"
                                value={localFilters.company_id ? String(localFilters.company_id) : ""}
                                onChange={(value) => setLocalFilters((prev) => ({ ...prev, company_id: value }))}
                            />

                            {/*
                                «Не указана» — не техническая заглушка, а рабочий фильтр:
                                в переходный период таких заказов много, и менеджеру
                                нужно уметь отобрать именно их.
                            */}
                            {organizationsEnabled && (
                                <FilterSelect
                                    label="Организация"
                                    collection={organizationCollection}
                                    placeholder="Все организации"
                                    value={localFilters.organization_id ? String(localFilters.organization_id) : ""}
                                    onChange={(value) => setLocalFilters((prev) => ({ ...prev, organization_id: value }))}
                                />
                            )}

                            {organizationsEnabled && (
                                <FilterSelect
                                    label="Склад отгрузки"
                                    collection={warehouseCollection}
                                    placeholder="Все склады"
                                    value={localFilters.warehouse_id ? String(localFilters.warehouse_id) : ""}
                                    onChange={(value) => setLocalFilters((prev) => ({ ...prev, warehouse_id: value }))}
                                />
                            )}

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
                            collection={bulkStatusCollection}
                            value={bulkStatus ? [bulkStatus] : []}
                            onValueChange={(e) => setBulkStatus(e.value[0] ?? "")}
                        >
                            <Select.Trigger width="200px">
                                <Select.ValueText placeholder="Выберите статус" />
                            </Select.Trigger>
                            <Select.Content>
                                {bulkStatusCollection.items.map((status) => (
                                    <Select.Item key={status.value} item={status}>
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
