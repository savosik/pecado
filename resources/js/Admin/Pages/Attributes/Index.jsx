import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, Badge, Text, IconButton, Button } from '@chakra-ui/react';
import { LuPencil, LuTrash2, LuPlus } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ attributes, filters }) {
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [attributeToDelete, setAttributeToDelete] = useState(null);

    const handleSearch = (value) => {
        setSearchQuery(value);
        router.get(route('admin.attributes.index'), {
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

        router.get(route('admin.attributes.index'), {
            ...filters,
            sort_by: field,
            sort_order: newOrder,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handlePerPageChange = (perPage) => {
        router.get(route('admin.attributes.index'), {
            ...filters,
            per_page: perPage,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleDelete = (attribute) => {
        setAttributeToDelete(attribute);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (attributeToDelete) {
            router.delete(route('admin.attributes.destroy', attributeToDelete.id), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Атрибут удален',
                        description: 'Атрибут успешно удален из системы',
                        type: 'success',
                    });
                    setDeleteDialogOpen(false);
                    setAttributeToDelete(null);
                },
                onError: () => {
                    toaster.create({
                        title: 'Ошибка',
                        description: 'Не удалось удалить атрибут',
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
            key: 'name',
            label: 'Название',
            sortable: true,
            render: (_, attr) => (
                <Box>
                    <Text fontWeight="medium">{attr.name}</Text>
                    <Text fontSize="sm" color="fg.muted">{attr.slug}</Text>
                </Box>
            ),
        },
        {
            key: 'type',
            label: 'Тип',
            sortable: true,
            render: (type) => {
                const types = {
                    string: 'Строка',
                    number: 'Число',
                    boolean: 'Логический',
                    select: 'Выбор',
                };
                return <Badge colorPalette="blue">{types[type] || type}</Badge>;
            },
        },
        {
            key: 'values_count',
            label: 'Значений',
            render: (_, attr) => attr.type === 'select' ? attr.values_count : '—',
        },
        {
            key: 'is_filterable',
            label: 'Фильтр',
            render: (val) => val ? <Badge colorPalette="green">Да</Badge> : <Badge colorPalette="gray">Нет</Badge>,
        },
        {
            key: 'sort_order',
            label: 'Сортировка',
            sortable: true,
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (_, attr) => (
                <HStack gap={1}>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        aria-label="Редактировать"
                        onClick={() => router.visit(route('admin.attributes.edit', attr.id))}
                    >
                        <LuPencil />
                    </IconButton>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        colorPalette="red"
                        aria-label="Удалить"
                        onClick={() => handleDelete(attr)}
                    >
                        <LuTrash2 />
                    </IconButton>
                </HStack>
            ),
        },
    ];

    return (
        <AdminLayout
            breadcrumbs={[
                { label: 'Главная', href: route('admin.dashboard') },
                { label: 'Атрибуты' },
            ]}
        >
            <Box p={6}>
                <PageHeader
                    title="Атрибуты"
                    description="Управление характеристиками товаров"
                    actions={
                        <Button
                            colorPalette="blue"
                            onClick={() => router.visit(route('admin.attributes.create'))}
                        >
                            <LuPlus /> Создать атрибут
                        </Button>
                    }
                />

                <Box mb={4}>
                    <SearchInput
                        value={searchQuery}
                        onChange={handleSearch}
                        placeholder="Поиск по названию..."
                    />
                </Box>

                <DataTable
                    data={attributes.data}
                    columns={columns}
                    pagination={attributes}
                    onSort={handleSort}
                    sortColumn={filters.sort_by}
                    sortDirection={filters.sort_order}
                    perPage={filters.per_page}
                    onPerPageChange={handlePerPageChange}
                />

                <ConfirmDialog
                    open={deleteDialogOpen}
                    onClose={() => setDeleteDialogOpen(false)}
                    onConfirm={confirmDelete}
                    title="Удалить атрибут?"
                    description={`Вы уверены, что хотите удалить атрибут "${attributeToDelete?.name}"? Это действие нельзя отменить.`}
                />
            </Box>
        </AdminLayout>
    );
}
