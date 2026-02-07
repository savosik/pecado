import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { DataTable, PageHeader, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Button, HStack, Text, Image, Box, Badge } from '@chakra-ui/react';
import { LuPencil, LuTrash2 } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';

export default function Index({ banners, filters }) {
    const {
        searchQuery,
        handleSearch,
        handleSort,
        deleteDialogOpen,
        entityToDelete,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
    } = useResourceIndex('admin.banners', filters, {
        entityLabel: 'Баннер',
    });

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
            render: (_, row) => <Text fontWeight="semibold">{row.title}</Text>,
        },
        {
            key: 'desktop_image',
            label: 'Desktop',
            width: '100px',
            render: (_, row) => (
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
            render: (_, row) => (
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
            render: (_, row) => (
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
            render: (_, row) => (
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
            render: (_, row) => (
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
                        onClick={() => openDeleteDialog(row)}
                    >
                        <LuTrash2 />
                    </Button>
                </HStack>
            ),
        },
    ];

    return (
        <>
            <PageHeader
                title="Баннеры"
                onCreate={() => router.visit(route('admin.banners.create'))}
                createLabel="Создать баннер"
            />

            <SearchInput
                value={searchQuery}
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
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить баннер?"
                description={`Вы уверены, что хотите удалить баннер "${entityToDelete?.title}"? Это действие нельзя отменить.`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
