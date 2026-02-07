import { useState } from 'react';
import { router, Link } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, IconButton, Badge, Text } from '@chakra-ui/react';
import { LuPencil, LuTrash2, LuPlus } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ articles, filters }) {
    const [search, setSearch] = useState(filters.search || '');
    const [deleteId, setDeleteId] = useState(null);

    const handleSearch = (value) => {
        setSearch(value);
        router.get(route('admin.articles.index'), { search: value }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleDelete = () => {
        if (deleteId) {
            router.delete(route('admin.articles.destroy', deleteId), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Статья успешно удалена',
                        type: 'success',
                    });
                    setDeleteId(null);
                },
                onError: () => {
                    toaster.create({
                        title: 'Ошибка при удалении статьи',
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
            render: (row) => <Box fontFamily="mono" fontSize="sm">{row.id}</Box>,
        },
        {
            key: 'title',
            label: 'Заголовок',
            sortable: true,
            render: (row) => (
                <Box>
                    <Text fontWeight="semibold">{row.title}</Text>
                    <Text fontSize="sm" color="gray.600">{row.slug}</Text>
                </Box>
            ),
        },
        {
            key: 'tags',
            label: 'Теги',
            render: (row) => (
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
            render: (row) => <Text fontSize="sm">{row.tags_count || 0}</Text>,
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (row) => (
                <HStack gap={2}>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        onClick={() => router.visit(route('admin.articles.edit', row.id))}
                    >
                        <LuPencil />
                    </IconButton>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        colorPalette="red"
                        onClick={() => setDeleteId(row.id)}
                    >
                        <LuTrash2 />
                    </IconButton>
                </HStack>
            ),
        },
    ];

    return (
        <AdminLayout>
            <PageHeader
                title="Статьи"
                action={{
                    label: 'Создать статью',
                    icon: LuPlus,
                    onClick: () => router.visit(route('admin.articles.create')),
                }}
            />

            <Box mb={4}>
                <SearchInput
                    value={search}
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
                onSort={(column, direction) => {
                    router.get(route('admin.articles.index'), {
                        ...filters,
                        sort_by: column,
                        sort_order: direction,
                    }, {
                        preserveState: true,
                        replace: true,
                    });
                }}
            />

            <ConfirmDialog
                open={!!deleteId}
                onClose={() => setDeleteId(null)}
                onConfirm={handleDelete}
                title="Удалить статью?"
                description="Вы уверены, что хотите удалить эту статью? Это действие нельзя отменить."
            />
        </AdminLayout>
    );
}
