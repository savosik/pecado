import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, Badge, Image, Text, IconButton, Button } from '@chakra-ui/react';
import { LuPencil, LuTrash2, LuPlus } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ products, filters }) {
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [productToDelete, setProductToDelete] = useState(null);

    const handleSearch = (value) => {
        setSearchQuery(value);
        router.get(route('admin.products.index'), {
            search: value,
            per_page: filters.per_page,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleSort = (field) => {
        const newOrder = filters.sort_by === field && filters.sort_order === 'asc' ? 'desc' : 'asc';

        router.get(route('admin.products.index'), {
            ...filters,
            sort_by: field,
            sort_order: newOrder,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handlePerPageChange = (perPage) => {
        router.get(route('admin.products.index'), {
            ...filters,
            per_page: perPage,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleDelete = (product) => {
        setProductToDelete(product);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (productToDelete) {
            router.delete(route('admin.products.destroy', productToDelete.id), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Товар удалён',
                        description: 'Товар успешно удалён из системы',
                        type: 'success',
                    });
                    setDeleteDialogOpen(false);
                    setProductToDelete(null);
                },
                onError: () => {
                    toaster.create({
                        title: 'Ошибка',
                        description: 'Не удалось удалить товар',
                        type: 'error',
                    });
                },
            });
        }
    };

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
                    <Text fontWeight="medium">{product.name}</Text>
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
            key: 'categories',
            label: 'Категории',
            render: (_, product) => (
                <HStack gap={1} flexWrap="wrap">
                    {product.categories?.slice(0, 2).map((category) => (
                        <Badge key={category.id} size="sm" colorPalette="blue">
                            {category.name}
                        </Badge>
                    ))}
                    {product.categories?.length > 2 && (
                        <Badge size="sm" colorPalette="gray">
                            +{product.categories.length - 2}
                        </Badge>
                    )}
                </HStack>
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
        {
            key: 'created_at',
            label: 'Создан',
            sortable: true,
            render: (_, product) => new Date(product.created_at).toLocaleDateString('ru-RU'),
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (_, product) => (
                <HStack gap={1}>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        aria-label="Редактировать"
                        onClick={() => router.visit(route('admin.products.edit', product.id))}
                    >
                        <LuPencil />
                    </IconButton>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        colorPalette="red"
                        aria-label="Удалить"
                        onClick={() => handleDelete(product)}
                    >
                        <LuTrash2 />
                    </IconButton>
                </HStack>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                title="Товары"
                actions={
                    <Button
                        colorPalette="blue"
                        onClick={() => router.visit(route('admin.products.create'))}
                    >
                        <LuPlus /> Создать товар
                    </Button>
                }
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по названию, SKU, коду..."
                />
            </Box>

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
                onClose={() => setDeleteDialogOpen(false)}
                onConfirm={confirmDelete}
                title="Удалить товар?"
                description={`Вы уверены, что хотите удалить товар "${productToDelete?.name}"? Это действие нельзя отменить.`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
