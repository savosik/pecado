import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, IconButton, Text } from '@chakra-ui/react';
import { LuPencil, LuTrash2, LuPlus } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ faqs, filters }) {
    const [search, setSearch] = useState(filters.search || '');
    const [deleteId, setDeleteId] = useState(null);

    const handleSearch = (value) => {
        setSearch(value);
        router.get(route('admin.faqs.index'), { search: value }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleDelete = () => {
        if (deleteId) {
            router.delete(route('admin.faqs.destroy', deleteId), {
                onSuccess: () => {
                    toaster.create({
                        title: 'FAQ успешно удалён',
                        type: 'success',
                    });
                    setDeleteId(null);
                },
                onError: () => {
                    toaster.create({
                        title: 'Ошибка при удалении FAQ',
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
            label: 'Вопрос',
            sortable: true,
            render: (row) => <Text fontWeight="semibold">{row.title}</Text>,
        },
        {
            key: 'content',
            label: 'Ответ',
            render: (row) => (
                <Text noOfLines={2} fontSize="sm" color="gray.600">
                    {row.content}
                </Text>
            ),
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (row) => (
                <HStack gap={2}>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        onClick={() => router.visit(route('admin.faqs.edit', row.id))}
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
                title="FAQ"
                action={{
                    label: 'Создать FAQ',
                    icon: LuPlus,
                    onClick: () => router.visit(route('admin.faqs.create')),
                }}
            />

            <Box mb={4}>
                <SearchInput
                    value={search}
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
                onSort={(column, direction) => {
                    router.get(route('admin.faqs.index'), {
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
                title="Удалить FAQ?"
                description="Вы уверены, что хотите удалить этот FAQ? Это действие нельзя отменить."
            />
        </AdminLayout>
    );
}
