import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, Badge, Text, Button } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ attributes, filters }) {
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
    } = useResourceIndex('admin.attributes', filters, {
        entityLabel: 'Атрибут',
    });

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
            key: 'categories',
            label: 'Категории',
            render: (_, attr) => {
                const cats = attr.categories || [];
                if (cats.length === 0) return <Text color="fg.muted">—</Text>;
                return (
                    <Box display="flex" flexWrap="wrap" gap={1}>
                        {cats.map(c => (
                            <Badge key={c.id} colorPalette="purple" size="sm">{c.name}</Badge>
                        ))}
                    </Box>
                );
            },
        },
        createActionsColumn('admin.attributes', openDeleteDialog),
    ];

    return (
        <>
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
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить атрибут?"
                description={`Вы уверены, что хотите удалить атрибут "${entityToDelete?.name}"? Это действие нельзя отменить.`}
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
