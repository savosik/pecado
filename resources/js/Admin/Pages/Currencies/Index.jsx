import { useState } from 'react';
import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, IconButton, Badge } from '@chakra-ui/react';
import { LuPencil, LuTrash2, LuPlus } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ currencies, filters }) {
    const [search, setSearch] = useState(filters.search || '');
    const [deleteId, setDeleteId] = useState(null);

    const handleSearch = (value) => {
        setSearch(value);
        router.get(route('admin.currencies.index'), { search: value }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleDelete = () => {
        if (deleteId) {
            router.delete(route('admin.currencies.destroy', deleteId), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Валюта успешно удалена',
                        type: 'success',
                    });
                    setDeleteId(null);
                },
                onError: () => {
                    toaster.create({
                        title: 'Ошибка при удалении валюты',
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
            key: 'code',
            label: 'Код',
            sortable: true,
            render: (row) => <Box fontWeight="semibold">{row.code}</Box>,
        },
        {
            key: 'name',
            label: 'Название',
            sortable: true,
        },
        {
            key: 'symbol',
            label: 'Символ',
            render: (row) => <Box fontWeight="semibold" fontSize="lg">{row.symbol}</Box>,
        },
        {
            key: 'is_base',
            label: 'Базовая',
            render: (row) => row.is_base ? (
                <Badge colorPalette="green">Да</Badge>
            ) : (
                <Badge colorPalette="gray">Нет</Badge>
            ),
        },
        {
            key: 'exchange_rate',
            label: 'Курс',
            sortable: true,
            render: (row) => <Box fontFamily="mono">{row.exchange_rate}</Box>,
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (row) => (
                <HStack gap={2}>
                    <IconButton
                        size="sm"
                        variant="ghost"
                        onClick={() => router.visit(route('admin.currencies.edit', row.id))}
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
                title="Валюты"
                action={{
                    label: 'Создать валюту',
                    icon: LuPlus,
                    onClick: () => router.visit(route('admin.currencies.create')),
                }}
            />

            <Box mb={4}>
                <SearchInput
                    value={search}
                    onChange={handleSearch}
                    placeholder="Поиск по коду, названию или символу..."
                />
            </Box>

            <DataTable
                data={currencies.data}
                columns={columns}
                pagination={currencies}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={(column, direction) => {
                    router.get(route('admin.currencies.index'), {
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
                title="Удалить валюту?"
                description="Вы уверены, что хотите удалить эту валюту? Это действие нельзя отменить."
            />
        </AdminLayout>
    );
}
