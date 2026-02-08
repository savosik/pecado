import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, Text, Button, Flex } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ productModels, filters }) {
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
    } = useResourceIndex('admin.product-models', filters, {
        entityLabel: 'Модель',
    });

    const columns = [
        { key: 'id', label: 'ID', sortable: true, width: '80px' },
        {
            key: 'name',
            label: 'Название',
            sortable: true,
            render: (_, model) => (
                <Box>
                    <Text fontWeight="medium">{model.name}</Text>
                    {model.code && <Text fontSize="xs" color="fg.muted">Код: {model.code}</Text>}
                </Box>
            )
        },
        {
            key: 'created_at',
            label: 'Создана',
            sortable: true,
            render: (_, row) => (
                <Text fontSize="sm" color="gray.600">
                    {row.created_at ? new Date(row.created_at).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        createActionsColumn('admin.product-models', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Модели товаров"
                description="Управление моделями товаров"
                actions={
                    <Button
                        colorPalette="blue"
                        onClick={() => router.visit(route('admin.product-models.create'))}
                    >
                        <LuPlus /> Создать модель
                    </Button>
                }
            />

            <Flex gap={4} mb={4} direction={{ base: 'column', md: 'row' }}>
                <Box flex={1}>
                    <SearchInput
                        value={searchQuery}
                        onChange={handleSearch}
                        placeholder="Поиск по названию или коду..."
                    />
                </Box>
            </Flex>

            <DataTable
                data={productModels.data}
                columns={columns}
                pagination={productModels}
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
                title="Удалить модель?"
                description={`Вы уверены, что хотите удалить модель "${entityToDelete?.name}"?`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
