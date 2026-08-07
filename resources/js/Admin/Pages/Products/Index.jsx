import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog, DeleteAllButton } from '@/Admin/Components';
import { Box, HStack, Badge, Image, Text, Button } from '@chakra-ui/react';
import { LuPlus, LuDownload } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import { usePermission } from '@/Admin/hooks/usePermission';
import ProductsFilterPanel from './Components/ProductsFilterPanel';

export default function Index({ products, filters, can_view_cost: canViewCost = false }) {
    const { can } = usePermission();
    const {
        searchQuery,
        setSearchQuery,
        deleteDialogOpen,
        entityToDelete,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
        deleteAllDialogOpen,
        deleteAllProcessing,
        openDeleteAllDialog,
        confirmDeleteAll,
        closeDeleteAllDialog,
        navigate,
    } = useResourceIndex('admin.products', filters, {
        entityLabel: 'Товар',
    });

    // Полный набор применённых фильтров (для навигации и URL экспорта).
    const filterParams = {
        search: filters.search || undefined,
        sort_by: filters.sort_by,
        sort_order: filters.sort_order,
        per_page: filters.per_page,
        brands: filters.brands?.length ? filters.brands : undefined,
        categories: filters.categories?.length ? filters.categories : undefined,
        tags: filters.tags?.length ? filters.tags : undefined,
        images: filters.images || undefined,
        description_filter: filters.description_filter || undefined,
        hidden: filters.hidden || undefined,
        price_min: filters.price_min || undefined,
        price_max: filters.price_max || undefined,
        flags: filters.flags?.length ? filters.flags : undefined,
        stock: filters.stock || undefined,
    };

    // Применить изменения фильтров поверх текущих (со сбросом пагинации).
    const applyFilters = (changes) => {
        navigate({ ...filterParams, ...changes });
    };

    const clearFilters = () => {
        navigate({
            sort_by: filters.sort_by,
            sort_order: filters.sort_order,
            per_page: filters.per_page,
        });
    };

    // Сортировка и смена размера страницы — поверх applyFilters, чтобы не терять фильтры.
    const handleSort = (field, direction) => {
        const newDirection = direction || (
            filters.sort_by === field && filters.sort_order === 'asc' ? 'desc' : 'asc'
        );
        applyFilters({ sort_by: field, sort_order: newDirection });
    };

    const handlePerPageChange = (perPage) => {
        applyFilters({ per_page: perPage });
    };

    const exportUrl = route('admin.products.export', filterParams);

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            width: '80px',
        },
        {
            key: 'image',
            label: 'Изображение',
            render: (_, product) => (
                product?.media && product.media?.length > 0 ? (
                    <Image
                        src={product.media[0].original_url}
                        alt={product.name}
                        w="50px"
                        h="75px"
                        objectFit="cover"
                        borderRadius="md"
                    />
                ) : (
                    <Box
                        w="50px"
                        h="75px"
                        bg="bg.muted"
                        borderRadius="md"
                        display="flex"
                        alignItems="center"
                        justifyContent="center"
                    >
                        <Text fontSize="xs" color="fg.muted">Нет фото</Text>
                    </Box>
                )
            ),
        },
        {
            key: 'name',
            label: 'Название',
            sortable: true,
            render: (_, product) => (
                <Box>
                    <HStack gap={2}>
                        <Text fontWeight="medium">{product.name}</Text>
                        {product.hidden && (
                            <Badge size="xs" colorPalette="red">Скрыт</Badge>
                        )}
                    </HStack>
                    {product.sku && (
                        <Text fontSize="sm" color="fg.muted">SKU: {product.sku}</Text>
                    )}
                </Box>
            ),
        },
        {
            key: 'brand',
            label: 'Бренд',
            render: (_, product) => product?.brand?.name || '—',
        },
        {
            key: 'category',
            label: 'Категория',
            render: (_, product) => (
                product.category?.name ? (
                    <Badge size="sm" colorPalette="blue">
                        {product.category.name}
                    </Badge>
                ) : (
                    <Text fontSize="sm" color="fg.muted">—</Text>
                )
            ),
        },
        {
            key: 'tags',
            label: 'Теги',
            render: (_, product) => (
                <HStack gap={1} flexWrap="wrap">
                    {product.tags?.slice(0, 3).map((tag) => (
                        <Badge key={tag.id} size="xs" colorPalette="purple" variant="solid">
                            {tag.name?.ru || tag.name || ''}
                        </Badge>
                    ))}
                    {product.tags?.length > 3 && (
                        <Badge size="xs" colorPalette="gray" variant="outline">
                            +{product.tags.length - 3}
                        </Badge>
                    )}
                </HStack>
            ),
        },
        {
            key: 'base_price',
            label: 'Цена',
            sortable: true,
            render: (_, product) => `${parseFloat(product.base_price).toFixed(2)} ₽`,
        },
        ...(canViewCost ? [{
            key: 'cost_price',
            label: 'Себестоимость',
            render: (_, product) => (
                product.cost_price != null
                    ? `${parseFloat(product.cost_price).toFixed(2)} ₽`
                    : '—'
            ),
        }] : []),
        {
            key: 'created_at',
            label: 'Создан',
            sortable: true,
            render: (_, row) => (
                <Text fontSize="sm" color="gray.600">
                    {row.created_at ? new Date(row.created_at).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        createActionsColumn('admin.products', openDeleteDialog, { permissionPrefix: 'products', showView: true }),
    ];

    return (
        <>
            <PageHeader
                title="Товары"
                actions={
                    <>
                        <DeleteAllButton
                        sectionLabel="товары"
                        dialogOpen={deleteAllDialogOpen}
                        onOpen={openDeleteAllDialog}
                        onClose={closeDeleteAllDialog}
                        onConfirm={confirmDeleteAll}
                        isLoading={deleteAllProcessing}
                    />
                        <Button
                            variant="outline"
                            colorPalette="green"
                            asChild
                        >
                            <a href={exportUrl}>
                                <LuDownload /> Экспорт в Excel
                            </a>
                        </Button>
                        {can('products.create') && (
                    <Button
                        colorPalette="blue"
                        onClick={() => router.visit(route('admin.products.create'))}
                    >
                        <LuPlus /> Создать товар
                    </Button>
                    )}
                    </>
                }
            />

            <HStack mb={4} gap={4} align="center" flexWrap="wrap">
                <Box flex="1" minW="240px">
                    <SearchInput
                        value={searchQuery}
                        onChange={(value) => {
                            setSearchQuery(value);
                            applyFilters({ search: value || undefined });
                        }}
                        placeholder="Поиск по названию, SKU, коду..."
                    />
                </Box>
                <ProductsFilterPanel
                    filters={filters}
                    onApply={applyFilters}
                    onClear={clearFilters}
                />
            </HStack>

            <DataTable
                data={products.data}
                columns={columns}
                pagination={products}
                onSort={handleSort}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                perPage={filters.per_page}
                onPerPageChange={handlePerPageChange}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить товар?"
                description={`Вы уверены, что хотите удалить товар "${entityToDelete?.name}"? Это действие нельзя отменить.`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
