import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import CategoryTree from './CategoryTree';
import { Box, HStack, Badge, Image, Text, Button, Group } from '@chakra-ui/react';
import { LuPlus, LuList, LuNetwork } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ categories, filters }) {
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
        navigate,
    } = useResourceIndex('admin.categories', filters, {
        entityLabel: 'Категория',
    });

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            width: '80px',
        },
        {
            key: 'icon',
            label: 'Иконка',
            render: (_, category) => (
                category?.media && category.media?.length > 0 ? (
                    <Image
                        src={category.media[0].original_url}
                        alt={category.name}
                        w="40px"
                        h="40px"
                        objectFit="cover"
                        borderRadius="md"
                    />
                ) : (
                    <Box
                        w="40px"
                        h="40px"
                        bg="bg.muted"
                        borderRadius="md"
                        display="flex"
                        alignItems="center"
                        justifyContent="center"
                    >
                        <Text fontSize="xs" color="fg.muted">—</Text>
                    </Box>
                )
            ),
        },
        {
            key: 'name',
            label: 'Название',
            sortable: true,
            render: (_, category) => (
                <Box>
                    <Text fontWeight="medium">{category.name}</Text>
                    {category.external_id && (
                        <Text fontSize="sm" color="fg.muted">ID: {category.external_id}</Text>
                    )}
                </Box>
            ),
        },
        {
            key: 'parent',
            label: 'Родительская категория',
            render: (_, category) => category?.parent?.name || '—',
        },
        {
            key: 'tags',
            label: 'Теги',
            render: (_, category) => (
                <HStack gap={1} flexWrap="wrap">
                    {category.tags?.slice(0, 3).map((tag) => (
                        <Badge key={tag.id} size="xs" colorPalette="purple" variant="solid">
                            {tag.name?.ru || tag.name || ''}
                        </Badge>
                    ))}
                    {category.tags?.length > 3 && (
                        <Badge size="xs" colorPalette="gray" variant="outline">
                            +{category.tags.length - 3}
                        </Badge>
                    )}
                </HStack>
            ),
        },
        {
            key: 'created_at',
            label: 'Создана',
            sortable: true,
            render: (_, category) => new Date(category.created_at).toLocaleDateString('ru-RU'),
        },
        createActionsColumn('admin.categories', openDeleteDialog),
    ];

    const viewMode = filters.view || 'list';

    const handleViewChange = (mode) => {
        if (mode === viewMode) return;
        navigate({
            ...filters,
            view: mode,
            page: 1,
        });
    };

    return (
        <>
            <PageHeader
                title="Категории"
                actions={
                    <HStack>
                        <Group attached>
                            <Button
                                size="sm"
                                variant={viewMode === 'list' ? 'solid' : 'outline'}
                                onClick={() => handleViewChange('list')}
                            >
                                <LuList /> Список
                            </Button>
                            <Button
                                size="sm"
                                variant={viewMode === 'tree' ? 'solid' : 'outline'}
                                onClick={() => handleViewChange('tree')}
                            >
                                <LuNetwork /> Дерево
                            </Button>
                        </Group>

                        <Button
                            colorPalette="blue"
                            onClick={() => router.visit(route('admin.categories.create'))}
                        >
                            <LuPlus /> Создать категорию
                        </Button>
                    </HStack>
                }
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
                    onChange={handleSearch}
                    placeholder="Поиск по названию, описанию..."
                />
            </Box>

            {viewMode === 'list' ? (
                <DataTable
                    data={categories.data}
                    columns={columns}
                    pagination={categories}
                    onSort={handleSort}
                    sortColumn={filters.sort_by}
                    sortDirection={filters.sort_order}
                    perPage={filters.per_page}
                    onPerPageChange={handlePerPageChange}
                />
            ) : (
                <CategoryTree
                    data={categories}
                    onDelete={openDeleteDialog}
                />
            )}

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить категорию?"
                description={`Вы уверены, что хотите удалить категорию "${entityToDelete?.name}"? Это действие нельзя отменить.`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
