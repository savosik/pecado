import { useState } from 'react';
import { router, Link } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, Text } from '@chakra-ui/react';
import { LuPlus } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';

export default function Index({ balances, currencies, filters }) {
    const [currencyFilter, setCurrencyFilter] = useState(filters.currency_id || '');

    const {
        searchQuery,
        handleSearch,
        handleSort,
        deleteDialogOpen,
        entityToDelete,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
    } = useResourceIndex('admin.user-balances', filters, {
        entityLabel: 'Баланс',
    });

    const handleCurrencyFilter = (e) => {
        const value = e.target.value;
        setCurrencyFilter(value);
        router.get(route('admin.user-balances.index'), {
            search: searchQuery,
            currency_id: value
        }, {
            preserveState: true,
            replace: true,
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
            key: 'user',
            label: 'Пользователь',
            render: (_, row) => (
                <Link href={route('admin.users.edit', row.user.id)}>
                    <Text color="blue.600" _hover={{ textDecoration: 'underline' }}>
                        {row.user.name}
                    </Text>
                </Link>
            ),
        },
        {
            key: 'currency',
            label: 'Валюта',
            render: (_, row) => (
                <Box>
                    <Text fontWeight="semibold">{row.currency.code}</Text>
                    <Text fontSize="sm" color="gray.600">{row.currency.symbol}</Text>
                </Box>
            ),
        },
        {
            key: 'balance',
            label: 'Баланс',
            sortable: true,
            render: (_, row) => (
                <Box fontFamily="mono" fontWeight="medium">
                    {parseFloat(row.balance).toFixed(2)} {row.currency.symbol}
                </Box>
            ),
        },
        {
            key: 'overdue_debt',
            label: 'Просроченная задолженность',
            render: (_, row) => row.overdue_debt ? (
                <Box fontFamily="mono" color="red.600" fontWeight="medium">
                    {parseFloat(row.overdue_debt).toFixed(2)} {row.currency.symbol}
                </Box>
            ) : (
                <Text color="gray.500">—</Text>
            ),
        },
        createActionsColumn('admin.user-balances', openDeleteDialog),
    ];

    return (
        <>
            <PageHeader
                title="Балансы пользователей"
                onCreate={() => router.visit(route('admin.user-balances.create'))}
                createLabel="Создать баланс"
            />

            <Box mb={4}>
                <HStack gap={4}>
                    <Box flex={1}>
                        <SearchInput
                            value={searchQuery}
                            onChange={handleSearch}
                            placeholder="Поиск по имени или email пользователя..."
                        />
                    </Box>
                    <Box w="200px">
                        <select
                            value={currencyFilter}
                            onChange={handleCurrencyFilter}
                            style={{
                                width: '100%',
                                padding: '0.5rem',
                                borderRadius: '0.375rem',
                                border: '1px solid var(--chakra-colors-border)'
                            }}
                        >
                            <option value="">Все валюты</option>
                            {currencies.map((currency) => (
                                <option key={currency.id} value={currency.id}>
                                    {currency.code} - {currency.name}
                                </option>
                            ))}
                        </select>
                    </Box>
                </HStack>
            </Box>

            <DataTable
                data={balances.data}
                columns={columns}
                pagination={balances}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить баланс?"
                description="Вы уверены, что хотите удалить этот баланс? Это действие нельзя отменить."
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
