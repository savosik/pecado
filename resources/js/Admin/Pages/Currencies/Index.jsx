import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, Badge, Button } from '@chakra-ui/react';
import { LuPlus, LuRefreshCw } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import { useState } from 'react';

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
            key: 'exchange_rate',
            label: 'Курс',
            sortable: true,
            render: (_, row) => <Box fontFamily="mono">{row.exchange_rate}</Box>,
        },
        {
            key: 'correction_factor',
            label: 'Коэфф. коррекции',
            sortable: true,
            render: (_, row) => <Box fontFamily="mono">{row.correction_factor}</Box>,
        },
        createActionsColumn('admin.currencies', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Валюты"
                onCreate={() => router.visit(route('admin.currencies.create'))}
                createLabel="Создать валюту"
                actions={
                    <Button
                        onClick={handleUpdateRates}
                        loading={updatingRates}
                        loadingText="Обновление..."
                        colorPalette="teal"
                        variant="outline"
                    >
                        <LuRefreshCw />
                        Обновить курсы
                    </Button>
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
