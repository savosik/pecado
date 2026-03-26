import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, Text, Button, Badge } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ segments, filters }) {
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
    } = useResourceIndex('admin.partner-segments', filters, {
        entityLabel: 'Сегмент',
    });

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            width: '80px',
        },
        {
            key: 'name',
            label: 'Название',
            sortable: true,
            render: (name, item) => (
                <Box>
                    <Text fontWeight="medium">{name || '—'}</Text>
                    {item.uuid && <Text fontSize="xs" color="fg.muted">UUID: {item.uuid}</Text>}
                </Box>
            ),
        },
        {
            key: 'users_count',
            label: 'Партнёров',
            render: (count) => (
                <Badge colorPalette="green">{count || 0}</Badge>
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
        {
            key: 'updated_at',
            label: 'Обновлено',
            sortable: true,
            render: (_, row) => (
                <Text fontSize="sm" color="gray.600">
                    {row.updated_at ? new Date(row.updated_at).toLocaleString('ru-RU') : '—'}
                </Text>
            ),
        },
        createActionsColumn('admin.partner-segments', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Сегменты партнёров"
                description="Управление сегментами партнёров из 1С (US-12)"
                actions={
                    <Button colorPalette="blue" onClick={() => router.visit(route('admin.partner-segments.create'))}>
                        <LuPlus /> Создать сегмент
                    </Button>
                }
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по названию или UUID..."
                />
            </Box>

            <DataTable
                data={segments.data}
                columns={columns}
                pagination={segments}
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
                title="Удалить сегмент?"
                description={`Вы уверены, что хотите удалить сегмент "${entityToDelete?.name || 'без названия'}"? Привязки партнёров к сегменту также будут удалены.`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
