import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, Text, Button, Badge, Image } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ product_selections, filters }) {
    const {
        searchQuery,
        handleSearch,
        handleSort,
        handlePerPageChange,
        deleteDialogOpen,
        entityToDelete,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
    } = useResourceIndex('admin.product-selections', filters, {
        entityLabel: 'Подборка',
    });

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            width: '80px',
        },
        {
            key: 'desktop_image_url',
            label: 'Изображение',
            render: (imageUrl) => (
                imageUrl ? (
                    <Image
                        src={imageUrl}
                        alt="Desktop"
                        boxSize="50px"
                        objectFit="cover"
                        borderRadius="md"
                    />
                ) : (
                    <Box w="50px" h="50px" bg="bg.muted" borderRadius="md" />
                )
            ),
        },
        {
            key: 'name',
            label: 'Название',
            sortable: true,
        },
        {
            key: 'products_count',
            label: 'Товаров',
            render: (count) => (
                <Badge colorPalette="blue">{count || 0}</Badge>
            ),
        },
        {
            key: 'created_at',
            label: 'Создано',
            sortable: true,
            render: (_, row) => (
                <Text fontSize="sm" color="gray.600">
                    {row.created_at ? new Date(row.created_at).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        createActionsColumn('admin.product-selections', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Подборки товаров"
                description="Управление подборками товаров для отображения на сайте"
                actions={
                    <Button colorPalette="blue" onClick={() => router.visit(route('admin.product-selections.create'))}>
                        <LuPlus /> Создать подборку
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
                data={product_selections.data}
                columns={columns}
                pagination={product_selections}
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
                title="Удалить подборку?"
                description={`Вы уверены, что хотите удалить подборку "${entityToDelete?.name || 'без названия'}"?`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
