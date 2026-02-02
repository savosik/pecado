import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import CategoryTree from './CategoryTree';
import { Box, HStack, Badge, Image, Text, IconButton, Button, Group } from '@chakra-ui/react';
import { LuPencil, LuTrash2, LuPlus, LuList, LuNetwork } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ categories, filters }) {
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [categoryToDelete, setCategoryToDelete] = useState(null);

    const handleSearch = (value) => {
        setSearchQuery(value);
        router.get(route('admin.categories.index'), {
            search: value,
            per_page: filters.per_page,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleSort = (field) => {
        const newOrder = filters.sort_by === field && filters.sort_order === 'asc' ? 'desc' : 'asc';

        router.get(route('admin.categories.index'), {
            ...filters,
            sort_by: field,
            sort_order: newOrder,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handlePerPageChange = (perPage) => {
        router.get(route('admin.categories.index'), {
            ...filters,
            per_page: perPage,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleDelete = (category) => {
        setCategoryToDelete(category);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (categoryToDelete) {
            router.delete(route('admin.categories.destroy', categoryToDelete.id), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Категория удалена',
                        description: 'Категория успешно удалена из системы',
                        type: 'success',
                    });
                    setDeleteDialogOpen(false);
                    setCategoryToDelete(null);
                },
                onError: () => {
                    toaster.create({
                        title: 'Ошибка',
                        description: 'Не удалось удалить категорию',
                        type: 'error',
                    });
                },
            });
        }
    };

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
        {
            key: 'actions',
            label: 'Действия',
            render: (_, category) => (
                <HStack gap={1}>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        aria-label="Редактировать"
                        onClick={() => router.visit(route('admin.categories.edit', category.id))}
                    >
                        <LuPencil />
                    </IconButton>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        colorPalette="red"
                        aria-label="Удалить"
                        onClick={() => handleDelete(category)}
                    >
                        <LuTrash2 />
                    </IconButton>
                </HStack>
            ),
        },
    ];

    const viewMode = filters.view || 'list';

    const handleViewChange = (mode) => {
        if (mode === viewMode) return;

        router.get(route('admin.categories.index'), {
            ...filters,
            view: mode,
            // Сбрасываем пагинацию при смене вида, но сохраняем поиск
            page: 1,
        }, {
            preserveState: true,
            replace: true,
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
                    onDelete={handleDelete}
                />
            )}

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={() => setDeleteDialogOpen(false)}
                onConfirm={confirmDelete}
                title="Удалить категорию?"
                description={`Вы уверены, что хотите удалить категорию "${categoryToDelete?.name}"? Это действие нельзя отменить.`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
