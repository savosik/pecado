import React, { useState } from "react";
import { HStack, Badge, Button, Input, Box, VStack, Text, IconButton } from "@chakra-ui/react";
import { Checkbox } from "@/components/ui/checkbox";
import { Head, usePage, router } from "@inertiajs/react";
import { LuPlus, LuFilter, LuX, LuTrash2 } from "react-icons/lu";
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { DataTable } from "@/Admin/Components/DataTable";
import { PageHeader } from "@/Admin/Components/PageHeader";
import { ConfirmDialog } from "@/Admin/Components/ConfirmDialog";
import { toaster } from "@/components/ui/toaster";
import { Field } from "@/components/ui/field";
import { Select } from "@/components/ui/select";
import { usePermission } from '@/Admin/hooks/usePermission';
import { DeleteAllButton, TrashedFilter } from '@/Admin/Components';
import { getReturnStatusColor as getStatusColor } from '@/constants/returnStatus';

const ReturnsIndex = ({ filters, statuses, reasons, trashedCount }) => {
    const { returns } = usePage().props;
    const { can } = usePermission();
    const [deleteId, setDeleteId] = useState(null);
    const [forceDeleteId, setForceDeleteId] = useState(null);
    const [deleteAllDialogOpen, setDeleteAllDialogOpen] = useState(false);
    const [deleteAllProcessing, setDeleteAllProcessing] = useState(false);
    const [forceDeleteAllDialogOpen, setForceDeleteAllDialogOpen] = useState(false);
    const [forceDeleteAllProcessing, setForceDeleteAllProcessing] = useState(false);
    const [selectedReturns, setSelectedReturns] = useState([]);
    const [showFilters, setShowFilters] = useState(false);
    const [bulkStatus, setBulkStatus] = useState("");
    const [localFilters, setLocalFilters] = useState({
        status: filters?.status || "",
        reason: filters?.reason || "",
        date_from: filters?.date_from || "",
        date_to: filters?.date_to || "",
        amount_from: filters?.amount_from || "",
        amount_to: filters?.amount_to || "",
    });

    const isTrashed = !!filters?.trashed;

    const toggleTrashed = () => {
        router.get(route('admin.returns.index'), { trashed: isTrashed ? undefined : 1 }, { preserveState: false });
    };

    const openDeleteAllDialog = () => setDeleteAllDialogOpen(true);
    const closeDeleteAllDialog = () => setDeleteAllDialogOpen(false);
    const confirmDeleteAll = () => {
        setDeleteAllProcessing(true);
        router.delete(route('admin.bulk-delete-all', 'returns'), {
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

    const confirmForceDeleteAll = () => {
        setForceDeleteAllProcessing(true);
        router.delete(route('admin.bulk-force-delete-all', 'returns'), {
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
    };

    const handleSort = (field, direction) => {
        router.get(route("admin.returns.index"), {
            ...filters,
            sort_by: field,
            sort_order: direction,
        }, { preserveState: true, replace: true });
    };

    const handleApplyFilters = () => {
        router.get(route("admin.returns.index"), {
            ...filters,
            ...localFilters,
        }, { preserveState: true, replace: true });
    };

    const handleResetFilters = () => {
        setLocalFilters({ status: "", reason: "", date_from: "", date_to: "", amount_from: "", amount_to: "" });
        router.get(route("admin.returns.index"), {
            search: filters?.search,
            sort_by: filters?.sort_by,
            sort_order: filters?.sort_order,
            trashed: isTrashed ? 1 : undefined,
        }, { preserveState: true, replace: true });
    };

    const handleBulkStatusUpdate = () => {
        if (selectedReturns.length === 0) {
            toaster.create({ description: "Выберите возвраты для обновления статуса", type: "warning" });
            return;
        }
        if (!bulkStatus) {
            toaster.create({ description: "Выберите статус", type: "warning" });
            return;
        }
        router.post(route("admin.returns.bulk-status"), {
            return_ids: selectedReturns,
            status: bulkStatus,
        }, {
            onSuccess: () => {
                toaster.create({ description: "Статус успешно обновлён", type: "success" });
                setSelectedReturns([]);
                setBulkStatus("");
            },
        });
    };

    const handleSelectAll = (checked) => {
        setSelectedReturns(checked ? returns.data.map(ret => ret.id) : []);
    };

    const handleSelectReturn = (returnId, checked) => {
        setSelectedReturns(checked
            ? [...selectedReturns, returnId]
            : selectedReturns.filter(id => id !== returnId)
        );
    };

    const columns = [
        {
            label: (
                <Checkbox
                    checked={selectedReturns.length === returns.data.length && returns.data.length > 0}
                    onCheckedChange={(e) => handleSelectAll(e.checked)}
                />
            ),
            key: "select",
            render: (_, returnItem) => (
                <Checkbox
                    checked={selectedReturns.includes(returnItem.id)}
                    onCheckedChange={(e) => handleSelectReturn(returnItem.id, e.checked)}
                />
            ),
        },
        { label: "ID", key: "id", sortable: true },
        { label: "Номер", key: "number", sortable: true, render: (value, row) => value || ("#" + row.id) },
        {
            label: "Пользователь",
            key: "user",
            render: (_, returnItem) => returnItem.user?.name || returnItem.user?.email || "—",
        },
        {
            label: "Статус",
            key: "status",
            sortable: true,
            render: (_, returnItem) => (
                <Badge colorPalette={getStatusColor(returnItem.status)}>
                    {returnItem.status_label}
                </Badge>
            ),
        },
        {
            label: "Причина",
            key: "primary_reason",
            render: (_, returnItem) => returnItem.primary_reason_label || "—",
        },
        {
            label: "Сумма",
            key: "total_amount",
            sortable: true,
            render: (value) => `${value || 0} ₽`,
        },
        {
            label: "Дата",
            key: "created_at",
            sortable: true,
            render: (_, returnItem) => (
                <Box>
                    <Text fontSize="sm" color="gray.600">
                        {returnItem.created_at || '—'}
                    </Text>
                    {returnItem.deleted_at && (
                        <Badge colorPalette="red" variant="subtle" size="xs" mt={0.5}>
                            Удалён: {returnItem.deleted_at}
                        </Badge>
                    )}
                </Box>
            ),
        },
        isTrashed
            ? {
                key: 'actions',
                label: 'Действия',
                render: (_, returnItem) => can('returns.delete') ? (
                    <IconButton
                        size="sm"
                        variant="ghost"
                        colorPalette="red"
                        aria-label="Удалить окончательно"
                        title="Удалить окончательно"
                        onClick={() => setForceDeleteId(returnItem.id)}
                    >
                        <LuTrash2 />
                    </IconButton>
                ) : null,
            }
            : createActionsColumn('admin.returns', (returnItem) => setDeleteId(returnItem.id), { permissionPrefix: 'returns' }),
    ];

    const handleDelete = () => {
        if (deleteId) {
            router.delete(route("admin.returns.destroy", deleteId), {
                onSuccess: () => {
                    toaster.create({ description: "Возврат успешно удалён", type: "success" });
                    setDeleteId(null);
                },
            });
        }
    };

    const handleForceDelete = () => {
        if (forceDeleteId) {
            router.delete(route("admin.returns.force-delete", forceDeleteId), {
                onSuccess: () => {
                    toaster.create({ description: "Возврат окончательно удалён", type: "success" });
                    setForceDeleteId(null);
                },
            });
        }
    };

    return (
        <>
            <Head title="Возвраты" />

            <PageHeader
                title="Возвраты"
                actions={
                    <HStack>
                        <TrashedFilter
                            trashed={isTrashed}
                            trashedCount={trashedCount}
                            onToggle={toggleTrashed}
                        />
                        {isTrashed ? (
                            <DeleteAllButton
                                sectionLabel="удалённые возвраты окончательно"
                                dialogOpen={forceDeleteAllDialogOpen}
                                onOpen={() => setForceDeleteAllDialogOpen(true)}
                                onClose={() => setForceDeleteAllDialogOpen(false)}
                                onConfirm={confirmForceDeleteAll}
                                isLoading={forceDeleteAllProcessing}
                            />
                        ) : (
                            <DeleteAllButton
                                sectionLabel="возвраты"
                                dialogOpen={deleteAllDialogOpen}
                                onOpen={openDeleteAllDialog}
                                onClose={closeDeleteAllDialog}
                                onConfirm={confirmDeleteAll}
                                isLoading={deleteAllProcessing}
                            />
                        )}
                        <Button onClick={() => setShowFilters(!showFilters)} variant="outline">
                            <LuFilter /> {showFilters ? "Скрыть фильтры" : "Фильтры"}
                        </Button>
                        {!isTrashed && can('returns.create') && (
                            <Button
                                onClick={() => router.visit(route("admin.returns.create"))}
                                colorPalette="blue"
                            >
                                <LuPlus /> Создать возврат
                            </Button>
                        )}
                    </HStack>
                }
            />

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

                            <Field label="Причина возврата">
                                <Select.Root
                                    value={localFilters.reason ? [localFilters.reason] : []}
                                    onValueChange={(e) => setLocalFilters({ ...localFilters, reason: e.value[0] || "" })}
                                >
                                    <Select.Trigger>
                                        <Select.ValueText placeholder="Все причины" />
                                    </Select.Trigger>
                                    <Select.Content>
                                        <Select.Item item="">Все причины</Select.Item>
                                        {reasons?.map((reason) => (
                                            <Select.Item key={reason.value} item={reason.value}>
                                                {reason.label}
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

                            <Button onClick={handleApplyFilters} colorPalette="blue">Применить</Button>
                            <Button onClick={handleResetFilters} variant="outline">
                                <LuX /> Сбросить
                            </Button>
                        </HStack>
                    </VStack>
                </Box>
            )}

            {selectedReturns.length > 0 && !isTrashed && (
                <Box p={4} borderWidth="1px" borderRadius="md" mb={4} bg="blue.50">
                    <HStack>
                        <span>Выбрано: {selectedReturns.length}</span>
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
                        {can('returns.edit') && (
                            <Button onClick={handleBulkStatusUpdate} colorPalette="blue">
                                Применить статус
                            </Button>
                        )}
                        <Button onClick={() => setSelectedReturns([])} variant="ghost">
                            Отменить выбор
                        </Button>
                    </HStack>
                </Box>
            )}

            <DataTable
                columns={columns}
                data={returns.data}
                pagination={returns}
                searchPlaceholder="Поиск возвратов..."
                onSort={handleSort}
                sortColumn={filters?.sort_by}
                sortDirection={filters?.sort_order}
            />

            <ConfirmDialog
                open={!!deleteId}
                onClose={() => setDeleteId(null)}
                onConfirm={handleDelete}
                title="Удаление возврата"
                description="Вы уверены, что хотите удалить этот возврат? Это действие нельзя отменить."
            />

            <ConfirmDialog
                open={!!forceDeleteId}
                onClose={() => setForceDeleteId(null)}
                onConfirm={handleForceDelete}
                title="Окончательное удаление возврата"
                description="Вы уверены, что хотите окончательно удалить этот возврат? Запись будет удалена безвозвратно."
                confirmLabel="Удалить окончательно"
                colorPalette="red"
            />
        </>
    );
};

ReturnsIndex.layout = (page) => <AdminLayout>{page}</AdminLayout>;

export default ReturnsIndex;
