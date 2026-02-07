import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, Badge } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ currencies, filters }) {
    const {
        searchQuery,
        handleSearch,
        handleSort,
        deleteDialogOpen,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
    } = useResourceIndex('admin.currencies', filters, {
        entityLabel: 'Валюта',
    });

    const columns = [
        {
            key: 'id',
            label: 'ID',
            sortable: true,
            render: (_, row) => <Box fontFamily="mono" fontSize="sm">{row.id}</Box>,
        },
        {
            key: 'code',
            label: 'Код',
            sortable: true,
            render: (_, row) => <Box fontWeight="semibold">{row.code}</Box>,
        },
        {
            key: 'name',
            label: 'Название',
            sortable: true,
        },
        {
            key: 'symbol',
            label: 'Символ',
            render: (_, row) => <Box fontWeight="semibold" fontSize="lg">{row.symbol}</Box>,
        },
        {
            key: 'is_base',
            label: 'Базовая',
            render: (_, row) => row.is_base ? (
                <Badge colorPalette="green">Да</Badge>
            ) : (
                <Badge colorPalette="gray">Нет</Badge>
            ),
        },
        {
            key: 'exchange_rate',
            label: 'Курс',
            sortable: true,
            render: (_, row) => <Box fontFamily="mono">{row.exchange_rate}</Box>,
        },
        createActionsColumn('admin.currencies', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Валюты"
                onCreate={() => router.visit(route('admin.currencies.create'))}
                createLabel="Создать валюту"
            />

            <Box mb={4}>
                <SearchInput
                    value={searchQuery}
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
                onSort={handleSort}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить валюту?"
                description="Вы уверены, что хотите удалить эту валюту? Это действие нельзя отменить."
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
