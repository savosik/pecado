import { useState, useCallback, useEffect, useRef } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, EntitySelector, ConfirmDialog, DeleteAllButton } from '@/Admin/Components';
import { Box, Text, Button, HStack, Badge, SimpleGrid } from '@chakra-ui/react';
import { LuDownload, LuPlus } from 'react-icons/lu';
import RowActions from '@/shared/Panel/RowActions';
import { toaster } from '@/components/ui/toaster';
import { usePermission } from '@/Admin/hooks/usePermission';

export default function Index({ prices, filters, stats, filterLabels }) {
    const { can } = usePermission();
    const [partnerId, setPartnerId] = useState(filters.partner_id || null);
    const [deleteAllDialogOpen, setDeleteAllDialogOpen] = useState(false);
    const [deleteAllProcessing, setDeleteAllProcessing] = useState(false);
    const pollingRef = useRef(null);

    const stopPolling = useCallback(() => {
        if (pollingRef.current) {
            clearInterval(pollingRef.current);
            pollingRef.current = null;
        }
    }, []);

    const startPolling = useCallback(() => {
        if (pollingRef.current) return;
        pollingRef.current = setInterval(async () => {
            try {
                const response = await fetch(route('admin.bulk-delete-status', 'individual-prices'));
                const data = await response.json();
                if (data.status === 'completed') {
                    stopPolling();
                    setDeleteAllProcessing(false);
                    toaster.create({ title: data.message || 'Все записи успешно удалены', type: 'success' });
                    router.reload();
                } else if (data.status === 'failed') {
                    stopPolling();
                    setDeleteAllProcessing(false);
                    toaster.create({ title: data.message || 'Ошибка при удалении', type: 'error' });
                }
            } catch { /* ignore network errors */ }
        }, 2000);
    }, [stopPolling]);

    useEffect(() => () => stopPolling(), [stopPolling]);

    const openDeleteAllDialog = () => setDeleteAllDialogOpen(true);
    const closeDeleteAllDialog = () => setDeleteAllDialogOpen(false);
    const confirmDeleteAll = () => {
        setDeleteAllProcessing(true);
        setDeleteAllDialogOpen(false);
        router.delete(route('admin.bulk-delete-all', 'individual-prices'), {
            onSuccess: () => {
                toaster.create({ title: 'Удаление запущено в фоне', description: 'Страница обновится автоматически', type: 'info' });
                startPolling();
            },
            onError: () => {
                toaster.create({ title: 'Ошибка при запуске удаления', type: 'error' });
                setDeleteAllProcessing(false);
            },
        });
    };
    const [productId, setProductId] = useState(filters.product_id || null);
    const [warehouseId, setWarehouseId] = useState(filters.warehouse_id || null);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [priceToDelete, setPriceToDelete] = useState(null);

    const canExport = !!partnerId || !!productId;

    const navigate = useCallback((params) => {
        router.get(route('admin.individual-prices.index'), params, {
            preserveState: true,
            replace: true,
        });
    }, []);

    const handleFilterChange = useCallback((key, value) => {
        const newFilters = {
            partner_id: partnerId,
            product_id: productId,
            warehouse_id: warehouseId,
            sort_by: filters.sort_by,
            sort_order: filters.sort_order,
            per_page: filters.per_page,
            [key]: value,
        };

        Object.keys(newFilters).forEach(k => {
            if (!newFilters[k]) delete newFilters[k];
        });

        navigate(newFilters);
    }, [partnerId, productId, warehouseId, filters, navigate]);

    const handlePartnerChange = (item) => {
        const id = item?.id || null;
        setPartnerId(id);
        handleFilterChange('partner_id', id);
    };

    const handleProductChange = (item) => {
        const id = item?.id || null;
        setProductId(id);
        handleFilterChange('product_id', id);
    };

    const handleWarehouseChange = (item) => {
        const id = item?.id || null;
        setWarehouseId(id);
        handleFilterChange('warehouse_id', id);
    };

    const handleSort = (field, direction) => {
        navigate({
            ...filters,
            sort_by: field,
            sort_order: direction,
        });
    };

    const handlePerPageChange = (perPage) => {
        navigate({
            ...filters,
            per_page: perPage,
        });
    };

    const handleExport = () => {
        if (!canExport) return;
        const params = new URLSearchParams();
        if (partnerId) params.set('partner_id', partnerId);
        if (productId) params.set('product_id', productId);
        if (warehouseId) params.set('warehouse_id', warehouseId);
        window.open(`${route('admin.individual-prices.export')}?${params.toString()}`, '_blank');
    };

    const handleEdit = (row) => {
        router.visit(route('admin.individual-prices.edit', {
            partner_id: row.partner_id,
            product_id: row.product_id,
            warehouse_id: row.warehouse_id,
        }));
    };

    const openDeleteDialog = (row) => {
        setPriceToDelete(row);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (!priceToDelete) return;
        router.delete(route('admin.individual-prices.destroy'), {
            data: {
                partner_id: priceToDelete.partner_id,
                product_id: priceToDelete.product_id,
                warehouse_id: priceToDelete.warehouse_id,
            },
            onSuccess: () => {
                toaster.create({ title: 'Цена удалена', type: 'success' });
                setDeleteDialogOpen(false);
                setPriceToDelete(null);
            },
            onError: () => {
                toaster.create({ title: 'Ошибка при удалении', type: 'error' });
            },
        });
    };

    const columns = [
        {
            key: 'partner_name',
            label: 'Партнёр',
            sortable: false,
            render: (name, row) => (
                <Box>
                    <Text fontWeight="medium" fontSize="sm">{name}</Text>
                    {row.partner_email && (
                        <Text fontSize="xs" color="fg.muted">{row.partner_email}</Text>
                    )}
                </Box>
            ),
        },
        {
            key: 'product_name',
            label: 'Товар',
            sortable: false,
            render: (name, row) => (
                <Box>
                    <Text fontWeight="medium" fontSize="sm">{name}</Text>
                    {row.product_sku && (
                        <Text fontSize="xs" color="fg.muted">{row.product_sku}</Text>
                    )}
                </Box>
            ),
        },
        {
            key: 'warehouse_name',
            label: 'Склад',
            sortable: false,
            render: (name) => <Text fontSize="sm">{name}</Text>,
        },
        {
            key: 'price',
            label: 'Цена',
            sortable: true,
            render: (price) => (
                <Badge colorPalette="green" variant="subtle" px={2} py={1} borderRadius="md" fontSize="sm" fontWeight="bold">
                    {Number(price).toLocaleString('ru-RU', { minimumFractionDigits: 2 })} ₽
                </Badge>
            ),
        },
        {
            key: 'updated_at',
            label: 'Обновлено',
            sortable: true,
            render: (date) => (
                <Text fontSize="sm" color="fg.muted">
                    {date ? new Date(date).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (_, row) => (
                <RowActions
                    size="xs"
                    edit={{ onClick: () => handleEdit(row), permission: 'individual-prices.edit' }}
                    delete={{ onClick: () => openDeleteDialog(row), permission: 'individual-prices.delete' }}
                />
            ),
        },
    ];

    return (
        <>
            <PageHeader
                title="Индивидуальные цены"
                description="Управление индивидуальными ценами партнёров"
                actions={
                    <>
                        <DeleteAllButton
                        sectionLabel="индивидуальные цены"
                        dialogOpen={deleteAllDialogOpen}
                        onOpen={openDeleteAllDialog}
                        onClose={closeDeleteAllDialog}
                        onConfirm={confirmDeleteAll}
                        isLoading={deleteAllProcessing}
                    />
                        {<HStack gap={3}>
                        <HStack gap={2}>
                            <Badge colorPalette="blue" variant="subtle" px={2} py={1}>
                                {stats?.total_prices?.toLocaleString('ru-RU') || 0} цен
                            </Badge>
                            <Badge colorPalette="purple" variant="subtle" px={2} py={1}>
                                {stats?.total_partners || 0} партнёров
                            </Badge>
                        </HStack>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={handleExport}
                            disabled={!canExport}
                            title={!canExport ? 'Выберите партнёра или товар для экспорта' : ''}
                        >
                            <LuDownload /> Экспорт CSV
                        </Button>
                        {can('individual-prices.create') && (
                        <Button
                            size="sm"
                            colorPalette="blue"
                            onClick={() => router.visit(route('admin.individual-prices.create'))}
                        >
                            <LuPlus /> Создать
                        </Button>
                        )}
                    </HStack>}
                    </>
                }
            />

            {/* Фильтры */}
            <SimpleGrid columns={{ base: 1, md: 3 }} gap={4} mb={4}>
                <Box>
                    <Text fontSize="sm" fontWeight="medium" mb={1} color="fg.muted">Партнёр</Text>
                    <EntitySelector
                        value={partnerId}
                        onChange={handlePartnerChange}
                        searchUrl={route('admin.individual-prices.search-partners')}
                        searchParams={{ only_with_prices: true }}
                        placeholder="Поиск по имени или email..."
                        displayField="label"
                        initialDisplay={filterLabels?.partner}
                        renderItem={(item) => (
                            <>
                                <Text fontWeight="medium">{item.label}</Text>
                                {item.email && <Text fontSize="xs" color="fg.muted">{item.email}</Text>}
                            </>
                        )}
                    />
                </Box>
                <Box>
                    <Text fontSize="sm" fontWeight="medium" mb={1} color="fg.muted">Товар</Text>
                    <EntitySelector
                        value={productId}
                        onChange={handleProductChange}
                        searchUrl={route('admin.individual-prices.search-products')}
                        searchParams={{ only_with_prices: true }}
                        placeholder="Поиск по названию или артикулу..."
                        displayField="label"
                        initialDisplay={filterLabels?.product}
                        renderItem={(item) => (
                            <>
                                <Text fontWeight="medium">{item.label}</Text>
                                {item.sku && <Text fontSize="xs" color="fg.muted">SKU: {item.sku}</Text>}
                            </>
                        )}
                    />
                </Box>
                <Box>
                    <Text fontSize="sm" fontWeight="medium" mb={1} color="fg.muted">Склад</Text>
                    <EntitySelector
                        value={warehouseId}
                        onChange={handleWarehouseChange}
                        searchUrl={route('admin.individual-prices.search-warehouses')}
                        searchParams={{ only_with_prices: true }}
                        placeholder="Поиск по названию склада..."
                        displayField="label"
                        initialDisplay={filterLabels?.warehouse}
                    />
                </Box>
            </SimpleGrid>

            {!canExport && (
                <Box mb={3} px={3} py={2} bg="bg.subtle" borderRadius="md" borderWidth="1px" borderColor="border.muted">
                    <Text fontSize="sm" color="fg.muted">
                        💡 Для экспорта CSV выберите партнёра или товар в фильтрах
                    </Text>
                </Box>
            )}

            <DataTable
                data={prices.data}
                columns={columns}
                pagination={prices}
                onSort={handleSort}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                perPage={filters.per_page}
                onPerPageChange={handlePerPageChange}
                emptyMessage="Нет данных. Индивидуальные цены появятся после импорта из 1С."
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={() => { setDeleteDialogOpen(false); setPriceToDelete(null); }}
                onConfirm={confirmDelete}
                title="Удалить цену?"
                description={priceToDelete
                    ? `Удалить индивидуальную цену ${Number(priceToDelete.price).toLocaleString('ru-RU')} ₽ для "${priceToDelete.partner_name}" на товар "${priceToDelete.product_name}"?`
                    : ''
                }
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
