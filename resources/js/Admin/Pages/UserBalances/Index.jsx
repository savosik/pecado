import { useState } from 'react';
import { router, Link } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog } from '@/Admin/Components';
import { Box, HStack, IconButton, Text } from '@chakra-ui/react';
import { LuPencil, LuTrash2, LuPlus } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function Index({ balances, currencies, filters }) {
    const [search, setSearch] = useState(filters.search || '');
    const [deleteId, setDeleteId] = useState(null);
    const [currencyFilter, setCurrencyFilter] = useState(filters.currency_id || '');

    const handleSearch = (value) => {
        setSearch(value);
        router.get(route('admin.user-balances.index'), {
            search: value,
            currency_id: currencyFilter
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleCurrencyFilter = (e) => {
        const value = e.target.value;
        setCurrencyFilter(value);
        router.get(route('admin.user-balances.index'), {
            search,
            currency_id: value
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const handleDelete = () => {
        if (deleteId) {
            router.delete(route('admin.user-balances.destroy', deleteId), {
                onSuccess: () => {
                    toaster.create({
                        title: 'Баланс успешно удалён',
                        type: 'success',
                    });
                    setDeleteId(null);
                },
                onError: () => {
                    toaster.create({
                        title: 'Ошибка при удалении баланса',
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
            key: 'user',
            label: 'Пользователь',
            render: (row) => (
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
            render: (row) => (
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
            render: (row) => (
                <Box fontFamily="mono" fontWeight="medium">
                    {parseFloat(row.balance).toFixed(2)} {row.currency.symbol}
                </Box>
            ),
        },
        {
            key: 'overdue_debt',
            label: 'Просроченная задолженность',
            render: (row) => row.overdue_debt ? (
                <Box fontFamily="mono" color="red.600" fontWeight="medium">
                    {parseFloat(row.overdue_debt).toFixed(2)} {row.currency.symbol}
                </Box>
            ) : (
                <Text color="gray.500">—</Text>
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
                        onClick={() => router.visit(route('admin.user-balances.edit', row.id))}
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
                title="Балансы пользователей"
                action={{
                    label: 'Создать баланс',
                    icon: LuPlus,
                    onClick: () => router.visit(route('admin.user-balances.create')),
                }}
            />

            <Box mb={4}>
                <HStack gap={4}>
                    <Box flex={1}>
                        <SearchInput
                            value={search}
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
                onSort={(column, direction) => {
                    router.get(route('admin.user-balances.index'), {
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
                title="Удалить баланс?"
                description="Вы уверены, что хотите удалить этот баланс? Это действие нельзя отменить."
            />
        </AdminLayout>
    );
}
