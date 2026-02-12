import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, Text, Badge } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ faqs, filters }) {
    const {
        searchQuery,
        handleSearch,
        handleSort,
        deleteDialogOpen,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
    } = useResourceIndex('admin.faqs', filters, {
        entityLabel: 'FAQ',
    });

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            render: (_, row) => <Box fontFamily="mono" fontSize="sm">{row.id}</Box>,
        },
        {
            key: 'title',
            label: 'Вопрос',
            sortable: true,
            render: (_, row) => <Text fontWeight="semibold">{row.title}</Text>,
        },
        {
            key: 'sort_order',
            label: 'Порядок',
            sortable: true,
            render: (_, row) => <Text fontSize="sm">{row.sort_order}</Text>,
        },
        {
            key: 'is_published',
            label: 'Статус',
            sortable: true,
            render: (_, row) => (
                <Badge colorPalette={row.is_published ? 'green' : 'gray'} variant="subtle">
                    {row.is_published ? 'Опубликован' : 'Скрыт'}
                </Badge>
            ),
        },
        createActionsColumn('admin.faqs', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="FAQ"
                onCreate={() => router.visit(route('admin.faqs.create'))}
                createLabel="Создать FAQ"
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по вопросу или ответу..."
                />
            </Box>

            <DataTable
                data={faqs.data}
                columns={columns}
                pagination={faqs}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить FAQ?"
                description="Вы уверены, что хотите удалить этот FAQ? Это действие нельзя отменить."
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
