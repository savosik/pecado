import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, Badge, Image, Text, IconButton, Button } from '@chakra-ui/react';
import { LuPencil, LuTrash2, LuPlus } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ brands, filters }) {
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [brandToDelete, setBrandToDelete] = useState(null);

    const handleSearch = (value) => {
        setSearchQuery(value);
        router.get(route('admin.brands.index'), {
            search: value,
            per_page: filters.per_page,
            sort_by: filters.sort_by,
            sort_order: filters.sort_order,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleSort = (field) => {
        const newOrder = filters.sort_by === field && filters.sort_order === 'asc' ? 'desc' : 'asc';

        router.get(route('admin.brands.index'), {
            ...filters,
            sort_by: field,
            sort_order: newOrder,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handlePerPageChange = (perPage) => {
        router.get(route('admin.brands.index'), {
            ...filters,
            per_page: perPage,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleDelete = (brand) => {
        setBrandToDelete(brand);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (brandToDelete) {
            router.delete(route('admin.brands.destroy', brandToDelete.id), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Бренд удален',
                        description: 'Бренд успешно удален из системы',
                        type: 'success',
                    });
                    setDeleteDialogOpen(false);
                    setBrandToDelete(null);
                },
                onError: () => {
                    toaster.create({
                        title: 'Ошибка',
                        description: 'Не удалось удалить бренд',
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
            key: 'logo',
            label: 'Логотип',
            render: (_, brand) => (
                brand?.media && brand.media?.length > 0 ? (
                    <Image
                        src={brand.media[0].original_url}
                        alt={brand.name}
                        w="40px"
                        h="40px"
                        objectFit="cover"
                        borderRadius="md"
                    />
                ) : (
                    <Box
                        w="40px"
                        h="40px"
                        bg="bg.muted"
                        borderRadius="md"
                        display="flex"
                        alignItems="center"
                        justifyContent="center"
                    >
                        <Text fontSize="xs" color="fg.muted">—</Text>
                    </Box>
                )
            ),
        },
        {
            key: 'name',
            label: 'Название',
            sortable: true,
            render: (_, brand) => (
                <Box>
                    <Text fontWeight="medium">{brand.name}</Text>
                    {brand.external_id && (
                        <Text fontSize="sm" color="fg.muted">ID: {brand.external_id}</Text>
                    )}
                </Box>
            ),
        },
        {
            key: 'parent',
            label: 'Родительский бренд',
            render: (_, brand) => brand?.parent?.name || '—',
        },
        {
            key: 'tags',
            label: 'Теги',
            render: (_, brand) => (
                <HStack gap={1} flexWrap="wrap">
                    {brand.tags?.slice(0, 3).map((tag) => (
                        <Badge key={tag.id} size="xs" colorPalette="purple" variant="solid">
                            {tag.name?.ru || tag.name || ''}
                        </Badge>
                    ))}
                    {brand.tags?.length > 3 && (
                        <Badge size="xs" colorPalette="gray" variant="outline">
                            +{brand.tags.length - 3}
                        </Badge>
                    )}
                </HStack>
            ),
        },
        {
            key: 'created_at',
            label: 'Создан',
            sortable: true,
            render: (_, brand) => new Date(brand.created_at).toLocaleDateString('ru-RU'),
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (_, brand) => (
                <HStack gap={1}>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        aria-label="Редактировать"
                        onClick={() => router.visit(route('admin.brands.edit', brand.id))}
                    >
                        <LuPencil />
                    </IconButton>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        colorPalette="red"
                        aria-label="Удалить"
                        onClick={() => handleDelete(brand)}
                    >
                        <LuTrash2 />
                    </IconButton>
                </HStack>
            ),
        },
    ];

    return (
        <AdminLayout>
            <Box p={6}>
                <PageHeader
                    title="Бренды"
                    description="Управление брендами и производителями"
                    actions={
                        <Button
                            colorPalette="blue"
                            onClick={() => router.visit(route('admin.brands.create'))}
                        >
                            <LuPlus /> Создать бренд
                        </Button>
                    }
                />

                <Box mb={4}>
                    <SearchInput
                        value={searchQuery}
                        onChange={handleSearch}
                        placeholder="Поиск по названию..."
                    />
                </Box>

                <DataTable
                    data={brands.data}
                    columns={columns}
                    pagination={brands}
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
                    title="Удалить бренд?"
                    description={`Вы уверены, что хотите удалить бренд "${brandToDelete?.name}"? Это действие нельзя отменить.`}
                />
            </Box>
        </AdminLayout>
    );
}
