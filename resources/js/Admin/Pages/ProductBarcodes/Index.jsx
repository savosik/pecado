import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, Text, Button } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ barcodes, filters }) {
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
    } = useResourceIndex('admin.product-barcodes', filters, {
        entityLabel: 'Штрихкод',
    });

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            width: '80px',
        },
        {
            key: 'barcode',
            label: 'Штрихкод',
            sortable: true,
            render: (barcode) => <Text fontWeight="mono" fontSize="md">{barcode}</Text>,
        },
        {
            key: 'product',
            label: 'Товар',
            render: (_, item) => (
                <Box>
                    <Text fontWeight="medium">{item.product?.name || '—'}</Text>
                </Box>
            ),
        },
        {
            key: 'created_at',
            label: 'Добавлен',
            sortable: true,
            render: (date) => new Date(date).toLocaleDateString('ru-RU'),
        },
        createActionsColumn('admin.product-barcodes', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Штрихкоды"
                description="Управление штрихкодами товаров"
                actions={
                    <Button colorPalette="blue" onClick={() => router.visit(route('admin.product-barcodes.create'))}>
                        <LuPlus /> Добавить штрихкод
                    </Button>
                }
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по штрихкоду или товару..."
                />
            </Box>

            <DataTable
                data={barcodes.data}
                columns={columns}
                pagination={barcodes}
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
                title="Удалить штрихкод?"
                description={`Вы уверены, что хотите удалить штрихкод "${entityToDelete?.barcode}"?`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
