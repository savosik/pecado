import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, SearchInput, DataTable, ConfirmDialog } from '@/Admin/Components';
import { Button, Badge, HStack } from '@chakra-ui/react';
import { LuPlus, LuPencil, LuTrash2 } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ stories, filters }) {
    const [search, setSearch] = useState(filters.search || '');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [storyToDelete, setStoryToDelete] = useState(null);

    const handleSearch = (value) => {
        setSearch(value);
        router.get(route('admin.stories.index'),
            { ...filters, search: value, page: 1 },
            { preserveState: true, preserveScroll: true }
        );
    };

    const handleEdit = (story) => {
        router.visit(route('admin.stories.edit', story.id));
    };

    const handleDelete = (story) => {
        setStoryToDelete(story);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (storyToDelete) {
            router.delete(route('admin.stories.destroy', storyToDelete.id), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Сторис успешно удалён',
                        type: 'success',
                    });
                    setDeleteDialogOpen(false);
                    setStoryToDelete(null);
                },
            });
        }
    };

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
        },
        {
            key: 'name',
            label: 'Название',
            sortable: true,
        },
        {
            key: 'slug',
            label: 'Slug',
            sortable: true,
        },
        {
            key: 'slides_count',
            label: 'Слайдов',
            sortable: true,
            render: (story) => story.slides_count || 0,
        },
        {
            key: 'is_active',
            label: 'Активность',
            sortable: true,
            render: (story) => (
                <Badge colorPalette={story.is_active ? 'green' : 'gray'}>
                    {story.is_active ? 'Активен' : 'Неактивен'}
                </Badge>
            ),
        },
        {
            key: 'sort_order',
            label: 'Порядок',
            sortable: true,
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (story) => (
                <HStack gap={2}>
                    <Button
                        size="sm"
                        colorPalette="blue"
                        onClick={() => handleEdit(story)}
                    >
                        <LuPencil />
                        Редактировать
                    </Button>
                    <Button
                        size="sm"
                        colorPalette="red"
                        variant="outline"
                        onClick={() => handleDelete(story)}
                    >
                        <LuTrash2 />
                    </Button>
                </HStack>
            ),
        },
    ];

    return (
        <AdminLayout>
            <PageHeader
                title="Сторис"
                description="Управление историями (stories)"
                action={
                    <Button
                        colorPalette="blue"
                        onClick={() => router.visit(route('admin.stories.create'))}
                    >
                        <LuPlus />
                        Создать сторис
                    </Button>
                }
            />

            <SearchInput
                value={search}
                onChange={handleSearch}
                placeholder="Поиск по названию..."
                mb={6}
            />

            <DataTable
                columns={columns}
                data={stories.data}
                pagination={stories}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={(column, direction) => {
                    router.get(route('admin.stories.index'), {
                        ...filters,
                        sort_by: column,
                        sort_order: direction,
                    });
                }}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
                onConfirm={confirmDelete}
                title="Удалить сторис?"
                description={`Вы уверены, что хотите удалить сторис "${storyToDelete?.name}"? Все слайды также будут удалены. Это действие нельзя отменить.`}
            />
        </AdminLayout>
    );
}
