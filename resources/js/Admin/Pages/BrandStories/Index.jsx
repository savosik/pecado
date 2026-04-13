import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog, DeleteAllButton } from '@/Admin/Components';
import { Box, HStack, Badge, Text, Image } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ brandStories, filters }) {
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
    } = useResourceIndex('admin.brand-stories', filters, {
        entityLabel: 'Статья о бренде',
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
            key: 'brand',
            label: 'Бренд',
            render: (_, row) => (
                <Text fontSize="sm">
                    {row.brand ? row.brand.name : '—'}
                </Text>
            ),
        },
        {
            key: 'is_published',
            label: 'Статус',
            render: (_, row) => (
                <Badge colorPalette={row.is_published ? 'green' : 'gray'} variant="subtle">
                    {row.is_published ? 'Опубликован' : 'Скрыт'}
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
        createActionsColumn('admin.brand-stories', openDeleteDialog, { permissionPrefix: 'brand-stories' }),
    ];

    return (
        <>
            <PageHeader
                title="О брендах"
                createPermission="brand-stories.create"
                onCreate={() => router.visit(route('admin.brand-stories.create'))}
                createLabel="Создать статью о бренде"
            
                actions={
                    <DeleteAllButton
                        sectionLabel="истории брендов"
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
                data={brandStories.data}
                columns={columns}
                pagination={brandStories}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить статью о бренде?"
                description="Вы уверены, что хотите удалить эту статью о бренде? Это действие нельзя отменить."
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
