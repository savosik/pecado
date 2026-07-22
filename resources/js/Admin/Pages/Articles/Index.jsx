import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog, DeleteAllButton } from '@/Admin/Components';
import { Box, HStack, Badge, Text, Image } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ articles, filters }) {
    const {
        searchQuery,
        handleSearch,
        handleSort,
        deleteDialogOpen,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
        deleteAllDialogOpen,
        deleteAllProcessing,
        openDeleteAllDialog,
        confirmDeleteAll,
        closeDeleteAllDialog,
    } = useResourceIndex('admin.articles', filters, {
        entityLabel: 'Статья',
    });

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            render: (_, row) => <Box fontFamily="mono" fontSize="sm">{row.id}</Box>,
        },
        {
            key: 'list_image',
            label: 'Фото',
            width: '70px',
            render: (_, row) => row.list_image ? (
                <Image src={row.list_image} alt={row.title} boxSize="40px" objectFit="cover" borderRadius="md" />
            ) : (
                <Box boxSize="40px" bg="bg.muted" borderRadius="md" />
            ),
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
            key: 'is_published',
            label: 'Статус',
            render: (_, row) => (
                <Badge colorPalette={row.is_published ? 'green' : 'gray'} variant="subtle">
                    {row.is_published ? 'Активна' : 'Скрыта'}
                </Badge>
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
        createActionsColumn('admin.articles', openDeleteDialog, { permissionPrefix: 'articles' , showView: true}),
    ];

    return (
        <>
            <PageHeader
                title="Статьи"
                createPermission="articles.create"
                onCreate={() => router.visit(route('admin.articles.create'))}
                createLabel="Создать статью"
            
                actions={
                    <DeleteAllButton
                        sectionLabel="статьи"
                        dialogOpen={deleteAllDialogOpen}
                        onOpen={openDeleteAllDialog}
                        onClose={closeDeleteAllDialog}
                        onConfirm={confirmDeleteAll}
                        isLoading={deleteAllProcessing}
                    />
                }
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по заголовку, описанию..."
                />
            </Box>

            <DataTable
                data={articles.data}
                columns={columns}
                pagination={articles}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить статью?"
                description="Вы уверены, что хотите удалить эту статью? Это действие нельзя отменить."
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
