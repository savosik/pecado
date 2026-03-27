import { useState, useCallback } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, EntitySelector } from '@/Admin/Components';
import { Box, Text, Button, HStack, Badge, SimpleGrid } from '@chakra-ui/react';
import { LuDownload, LuBanknote } from 'react-icons/lu';

export default function Index({ prices, filters, stats, filterLabels }) {
    const [partnerId, setPartnerId] = useState(filters.partner_id || null);
    const [productId, setProductId] = useState(filters.product_id || null);
    const [warehouseId, setWarehouseId] = useState(filters.warehouse_id || null);

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

        // Удаляем пустые
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
        const params = new URLSearchParams();
        if (partnerId) params.set('partner_id', partnerId);
        if (productId) params.set('product_id', productId);
        if (warehouseId) params.set('warehouse_id', warehouseId);
        window.open(`${route('admin.individual-prices.export')}?${params.toString()}`, '_blank');
    };

    const columns = [
        {
            key: 'partner_name',
            label: 'Партнёр',
            sortable: true,
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
            sortable: true,
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
            sortable: true,
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
    ];

    return (
        <>
            <PageHeader
                title="Индивидуальные цены"
                description="Просмотр индивидуальных цен партнёров"
                actions={
                    <HStack gap={3}>
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
                        >
                            <LuDownload /> Экспорт CSV
                        </Button>
                    </HStack>
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
                        placeholder="Поиск по названию склада..."
                        displayField="label"
                        initialDisplay={filterLabels?.warehouse}
                    />
                </Box>
            </SimpleGrid>

            <DataTable
                data={prices.data}
                columns={columns}
                pagination={prices}
                onSort={handleSort}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                perPage={filters.per_page}
                onPerPageChange={handlePerPageChange}
                emptyMessage="Нет данных. Выберите партнёра для просмотра цен."
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
