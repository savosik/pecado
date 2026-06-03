import { router } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog, DeleteAllButton } from '@/Admin/Components';
import { Box, Badge, Button } from '@chakra-ui/react';
import { LuPlus, LuRefreshCw } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ currencies, filters }) {
    const [updatingRates, setUpdatingRates] = useState(false);

    const {
        searchQuery,
        handleSearch,
        handleSort,
        deleteDialogOpen,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
        deleteAllDialogOpen,
        deleteAllProcessing,
        openDeleteAllDialog,
        confirmDeleteAll,
        closeDeleteAllDialog,
    } = useResourceIndex('admin.currencies', filters, {
        entityLabel: 'Валюта',
    });

    const handleUpdateRates = () => {
        setUpdatingRates(true);
        router.post(route('admin.currencies.update-rates'), {}, {
            onFinish: () => setUpdatingRates(false),
        });
    };

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
            key: 'official_rate',
            label: 'Офиц. курс (НБ)',
            sortable: true,
            render: (_, row) => row.official_rate ? (
                <Box fontFamily="mono">{row.official_rate}</Box>
            ) : (
                <Box fontSize="sm" color="fg.muted">—</Box>
            ),
        },
        {
            key: 'rate_coefficient',
            label: 'Коэфф. (1С)',
            sortable: true,
            render: (_, row) => <Box fontFamily="mono">{row.rate_coefficient}</Box>,
        },
        {
            key: 'exchange_rate',
            label: 'Итог. курс',
            sortable: true,
            render: (_, row) => <Box fontFamily="mono">{row.exchange_rate}</Box>,
        },
        {
            key: 'exchange_rate_date',
            label: 'Дата курса',
            sortable: true,
            render: (_, row) => row.exchange_rate_date ? (
                <Box fontSize="sm">{new Date(row.exchange_rate_date).toLocaleDateString('ru-RU')}</Box>
            ) : (
                <Box fontSize="sm" color="fg.muted">—</Box>
            ),
        },
        createActionsColumn('admin.currencies', openDeleteDialog, { permissionPrefix: 'currencies' , showView: true}),
    ];

    return (
        <>
            <PageHeader
                title="Валюты"
                createPermission="currencies.create"
                onCreate={() => router.visit(route('admin.currencies.create'))}
                createLabel="Создать валюту"
                actions={
                    <>
                        <DeleteAllButton
                        sectionLabel="валюты"
                        dialogOpen={deleteAllDialogOpen}
                        onOpen={openDeleteAllDialog}
                        onClose={closeDeleteAllDialog}
                        onConfirm={confirmDeleteAll}
                        isLoading={deleteAllProcessing}
                    />
                        {<Button
                        onClick={handleUpdateRates}
                        loading={updatingRates}
                        loadingText="Обновление..."
                        colorPalette="teal"
                        variant="outline"
                    >
                        <LuRefreshCw />
                        Обновить курсы
                    </Button>}
                    </>
                }
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
