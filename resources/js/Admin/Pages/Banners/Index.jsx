import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { DataTable, PageHeader, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Button, HStack, Text, Image, Box, Badge } from '@chakra-ui/react';
import { LuPencil, LuTrash2 } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ banners, filters }) {
    const [search, setSearch] = useState(filters.search || '');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [bannerToDelete, setBannerToDelete] = useState(null);

    const handleSearch = (value) => {
        setSearch(value);
        router.get(route('admin.banners.index'),
            { ...filters, search: value, page: 1 },
            { preserveState: true, preserveScroll: true }
        );
    };

    const handleSort = (column) => {
        const newSortOrder = filters.sort_by === column && filters.sort_order === 'asc' ? 'desc' : 'asc';
        router.get(route('admin.banners.index'),
            { ...filters, sort_by: column, sort_order: newSortOrder },
            { preserveState: true, preserveScroll: true }
        );
    };

    const handleDelete = (banner) => {
        setBannerToDelete(banner);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (bannerToDelete) {
            router.delete(route('admin.banners.destroy', bannerToDelete.id), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Баннер успешно удален',
                        type: 'success',
                    });
                    setDeleteDialogOpen(false);
                    setBannerToDelete(null);
                },
            });
        }
    };

    const getLinkableType = (type) => {
        if (!type) return '—';
        const typeMap = {
            'App\\Models\\Product': 'Товар',
            'App\\Models\\Page': 'Страница',
            'App\\Models\\Article': 'Статья',
            'App\\Models\\Category': 'Категория',
            'App\\Models\\News': 'Новость',
            'App\\Models\\Promotion': 'Акция',
        };
        return typeMap[type] || type;
    };

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            width: '80px',
        },
        {
            key: 'title',
            label: 'Заголовок',
            sortable: true,
            render: (row) => <Text fontWeight="semibold">{row.title}</Text>,
        },
        {
            key: 'desktop_image',
            label: 'Desktop',
            width: '100px',
            render: (row) => (
                row.desktop_image ? (
                    <Image
                        src={row.desktop_image}
                        alt={row.title}
                        maxH="50px"
                        objectFit="cover"
                        borderRadius="md"
                    />
                ) : <Text fontSize="sm" color="gray.500">—</Text>
            ),
        },
        {
            key: 'mobile_image',
            label: 'Mobile',
            width: '100px',
            render: (row) => (
                row.mobile_image ? (
                    <Image
                        src={row.mobile_image}
                        alt={row.title}
                        maxH="50px"
                        objectFit="cover"
                        borderRadius="md"
                    />
                ) : <Text fontSize="sm" color="gray.500">—</Text>
            ),
        },
        {
            key: 'linkable',
            label: 'Ссылка',
            render: (row) => (
                <Box>
                    <Text fontSize="sm" fontWeight="medium">
                        {getLinkableType(row.linkable_type)}
                    </Text>
                    {row.linkable_name && (
                        <Text fontSize="xs" color="gray.600" _dark={{ color: 'gray.400' }}>
                            {row.linkable_name}
                        </Text>
                    )}
                </Box>
            ),
        },
        {
            key: 'is_active',
            label: 'Активность',
            sortable: true,
            width: '120px',
            render: (row) => (
                <Badge colorPalette={row.is_active ? 'green' : 'red'}>
                    {row.is_active ? 'Активен' : 'Неактивен'}
                </Badge>
            ),
        },
        {
            key: 'sort_order',
            label: 'Порядок',
            sortable: true,
            width: '100px',
        },
        {
            key: 'actions',
            label: 'Действия',
            width: '150px',
            render: (row) => (
                <HStack gap={2}>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => router.visit(route('admin.banners.edit', row.id))}
                    >
                        <LuPencil />
                        Изменить
                    </Button>
                    <Button
                        size="sm"
                        colorPalette="red"
                        variant="outline"
                        onClick={() => handleDelete(row)}
                    >
                        <LuTrash2 />
                    </Button>
                </HStack>
            ),
        },
    ];

    return (
        <AdminLayout>
            <PageHeader
                title="Баннеры"
                action={{
                    label: 'Создать баннер',
                    onClick: () => router.visit(route('admin.banners.create')),
                }}
            />

            <SearchInput
                value={search}
                onChange={handleSearch}
                placeholder="Поиск по заголовку..."
            />

            <DataTable
                columns={columns}
                data={banners.data}
                pagination={banners}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
                onConfirm={confirmDelete}
                title="Удалить баннер?"
                description={`Вы уверены, что хотите удалить баннер "${bannerToDelete?.title}"? Это действие нельзя отменить.`}
            />
        </AdminLayout>
    );
}
