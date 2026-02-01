import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, Text, IconButton, Button, Link } from '@chakra-ui/react';
import { LuPencil, LuTrash2, LuPlus, LuDownload, LuFile } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ certificates, filters }) {
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [itemToDelete, setItemToDelete] = useState(null);

    const handleSearch = (value) => {
        setSearchQuery(value);
        router.get(route('admin.certificates.index'), {
            search: value,
            per_page: filters.per_page,
            sort_by: filters.sort_by,
            sort_order: filters.sort_order,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleSort = (field) => {
        const newOrder = filters.sort_by === field && filters.sort_order === 'asc' ? 'desc' : 'asc';
        router.get(route('admin.certificates.index'), {
            ...filters,
            sort_by: field,
            sort_order: newOrder,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handlePerPageChange = (perPage) => {
        router.get(route('admin.certificates.index'), {
            ...filters,
            per_page: perPage,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleDelete = (item) => {
        setItemToDelete(item);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (itemToDelete) {
            router.delete(route('admin.certificates.destroy', itemToDelete.id), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Удалено',
                        description: 'Сертификат успешно удален',
                        type: 'success',
                    });
                    setDeleteDialogOpen(false);
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
            key: 'name',
            label: 'Название',
            sortable: true,
            render: (name, item) => (
                <Box>
                    <Text fontWeight="medium">{name}</Text>
                    {item.external_id && <Text fontSize="xs" color="fg.muted">ID: {item.external_id}</Text>}
                </Box>
            ),
        },
        {
            key: 'issued_at',
            label: 'Дата выдачи',
            sortable: true,
            render: (date) => date ? new Date(date).toLocaleDateString('ru-RU') : '—',
        },
        {
            key: 'media',
            label: 'Файлы',
            render: (media) => (
                <HStack gap={2}>
                    {media?.map(m => (
                        <IconButton
                            key={m.id}
                            size="xs"
                            variant="subtle"
                            as="a"
                            href={m.original_url}
                            target="_blank"
                            title={m.file_name}
                        >
                            <LuFile />
                        </IconButton>
                    ))}
                    {(!media || media.length === 0) && <Text fontSize="xs" color="fg.muted">Нет файлов</Text>}
                </HStack>
            ),
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (_, item) => (
                <HStack gap={1}>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        onClick={() => router.visit(route('admin.certificates.edit', item.id))}
                    >
                        <LuPencil />
                    </IconButton>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        colorPalette="red"
                        onClick={() => handleDelete(item)}
                    >
                        <LuTrash2 />
                    </IconButton>
                </HStack>
            ),
        },
    ];

    return (
        <AdminLayout>
            <Box p={6}>
                <PageHeader
                    title="Сертификаты"
                    description="Управление сертификатами соответствия"
                    actions={
                        <Button colorPalette="blue" onClick={() => router.visit(route('admin.certificates.create'))}>
                            <LuPlus /> Создать сертификат
                        </Button>
                    }
                />

                <Box mb={4}>
                    <SearchInput
                        value={searchQuery}
                        onChange={handleSearch}
                        placeholder="Поиск по названию или внешнему ID..."
                    />
                </Box>

                <DataTable
                    data={certificates.data}
                    columns={columns}
                    pagination={certificates}
                    onSort={handleSort}
                    sortBy={filters.sort_by}
                    sortOrder={filters.sort_order}
                    perPage={filters.per_page}
                    onPerPageChange={handlePerPageChange}
                />

                <ConfirmDialog
                    open={deleteDialogOpen}
                    onClose={() => setDeleteDialogOpen(false)}
                    onConfirm={confirmDelete}
                    title="Удалить сертификат?"
                    description={`Вы уверены, что хотите удалить сертификат "${itemToDelete?.name}"?`}
                />
            </Box>
        </AdminLayout>
    );
}
