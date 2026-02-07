import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, Badge, Text } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ news, filters }) {
    const {
        searchQuery,
        handleSearch,
        handleSort,
        deleteDialogOpen,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
    } = useResourceIndex('admin.news', filters, {
        entityLabel: 'Новость',
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
            label: 'Заголовок',
            sortable: true,
            render: (_, row) => (
                <Box>
                    <Text fontWeight="semibold">{row.title}</Text>
                    <Text fontSize="sm" color="gray.600">{row.slug}</Text>
                </Box>
            ),
        },
        {
            key: 'tags',
            label: 'Теги',
            render: (_, row) => (
                <HStack gap={1} flexWrap="wrap">
                    {row.tag_list && row.tag_list.length > 0 ? (
                        row.tag_list.map((tag, index) => (
                            <Badge key={index} size="sm" colorPalette="blue">
                                {tag}
                            </Badge>
                        ))
                    ) : (
                        <Text fontSize="sm" color="gray.500">—</Text>
                    )}
                </HStack>
            ),
        },
        {
            key: 'tags_count',
            label: 'Кол-во тегов',
            sortable: true,
            render: (_, row) => <Text fontSize="sm">{row.tags_count || 0}</Text>,
        },
        createActionsColumn('admin.news', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Новости"
                onCreate={() => router.visit(route('admin.news.create'))}
                createLabel="Создать новость"
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по заголовку, описанию..."
                />
            </Box>

            <DataTable
                data={news.data}
                columns={columns}
                pagination={news}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить новость?"
                description="Вы уверены, что хотите удалить эту новость? Это действие нельзя отменить."
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
