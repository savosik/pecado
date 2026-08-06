import { useState } from 'react';
import { router, Link } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DataTable, SearchInput, ConfirmDialog, TrashedFilter } from '@/Admin/Components';
import {
    Box, Text, Badge, IconButton, HStack, Card,
    Input, Stack, Button, Flex,
} from '@chakra-ui/react';
import { LuEye, LuFilter, LuX, LuTrash2, LuRotateCcw } from 'react-icons/lu';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { createActionsColumn } from '@/Admin/helpers/createActionsColumn';
import { toaster } from '@/components/ui/toaster';
import { Field } from '@/components/ui/field';
import { Select } from '@/components/ui/select';

const ALLOCATION_COLORS = {
    allocated: 'green',
    partial: 'orange',
    advance: 'blue',
};

const money = (value, currency) =>
    `${parseFloat(value ?? 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}${currency ? ` ${currency}` : ''}`;

export default function Index({ payments, filters, directions, allocationStatuses, organizations, organizationsEnabled, trashedCount }) {
    const {
        searchQuery,
        handleSearch,
        handleSort,
        deleteDialogOpen,
        entityToDelete,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,
    } = useResourceIndex('admin.payments', filters, {
        entityLabel: 'Платёж',
    });

    const [forceDeleteId, setForceDeleteId] = useState(null);
    const [showFilters, setShowFilters] = useState(false);

    const isTrashed = !!filters?.trashed;

    const toggleTrashed = () => {
        router.get(route('admin.payments.index'), { trashed: isTrashed ? undefined : 1 }, { preserveState: false });
    };

    const handleForceDelete = () => {
        if (!forceDeleteId) return;

        router.delete(route('admin.payments.force-delete', forceDeleteId), {
            onSuccess: () => {
                toaster.create({ description: 'Платёж окончательно удалён', type: 'success' });
                setForceDeleteId(null);
            },
        });
    };

    const handleRestore = (id) => {
        router.post(route('admin.payments.restore', id), {}, {
            onSuccess: () => toaster.create({ description: 'Платёж восстановлен', type: 'success' }),
        });
    };

    const [localFilters, setLocalFilters] = useState({
        direction: filters?.direction ?? '',
        allocation_status: filters?.allocation_status ?? '',
        organization_id: filters?.organization_id ?? '',
        bank_confirmed: filters?.bank_confirmed ?? '',
        currency_code: filters?.currency_code ?? '',
        date_from: filters?.date_from ?? '',
        date_to: filters?.date_to ?? '',
        amount_from: filters?.amount_from ?? '',
        amount_to: filters?.amount_to ?? '',
    });

    const navigateWithParams = (params) => {
        router.get(route('admin.payments.index'), { ...filters, ...params }, { preserveState: true, replace: true });
    };

    const handleApplyFilters = () => navigateWithParams({ ...localFilters, page: 1 });

    const handleResetFilters = () => {
        const reset = {
            direction: '', allocation_status: '', organization_id: '', bank_confirmed: '',
            currency_code: '', date_from: '', date_to: '', amount_from: '', amount_to: '',
        };
        setLocalFilters(reset);
        navigateWithParams({ ...reset, page: 1 });
    };

    const columns = [
        {
            key: 'number',
            label: 'Номер',
            sortable: true,
            render: (_, row) => (
                <Link href={route('admin.payments.show', row.id)}>
                    <Text color="blue.600" _hover={{ textDecoration: 'underline' }} fontSize="sm" fontFamily="mono">
                        {row.number || `#${row.id}`}
                    </Text>
                </Link>
            ),
        },
        {
            key: 'date',
            label: 'Дата',
            sortable: true,
            render: (_, row) => <Text fontSize="sm">{row.date || '—'}</Text>,
        },
        {
            key: 'direction',
            label: 'Направление',
            render: (_, row) => (
                <Badge colorPalette={row.direction === 'out' ? 'red' : 'green'} variant="subtle">
                    {row.direction_label}
                </Badge>
            ),
        },
        {
            key: 'company',
            label: 'Плательщик',
            render: (_, row) => (
                <Box>
                    {row.company ? (
                        <Link href={route('admin.companies.edit', row.company.id)}>
                            <Text color="blue.600" _hover={{ textDecoration: 'underline' }} fontSize="sm">
                                {row.company.name}
                            </Text>
                        </Link>
                    ) : (
                        <Text color="gray.500" fontSize="sm">Контрагент не заведён</Text>
                    )}
                    {row.user && <Text fontSize="xs" color="gray.500">{row.user.name}</Text>}
                </Box>
            ),
        },
        ...(organizationsEnabled ? [{
            key: 'organization',
            label: 'Организация',
            render: (_, row) => row.organization ? (
                <HStack gap={1}>
                    <Text fontSize="sm">{row.organization.name}</Text>
                    {row.organization.is_stub && (
                        <Badge colorPalette="orange" variant="subtle" size="sm">не заведена</Badge>
                    )}
                </HStack>
            ) : <Text color="gray.500" fontSize="sm">—</Text>,
        }] : []),
        {
            key: 'amount',
            label: 'Сумма',
            sortable: true,
            render: (_, row) => (
                <Box fontFamily="mono" fontWeight="medium">{money(row.amount, row.currency_code)}</Box>
            ),
        },
        {
            key: 'unallocated_amount',
            label: 'Разнесение',
            sortable: true,
            render: (_, row) => (
                <Box>
                    <Badge colorPalette={ALLOCATION_COLORS[row.allocation_status] || 'gray'} variant="subtle">
                        {row.allocation_status_label}
                    </Badge>
                    {row.unallocated_amount > 0 && (
                        <Text fontSize="xs" color="gray.500" mt={0.5}>
                            Аванс: {money(row.unallocated_amount, row.currency_code)}
                        </Text>
                    )}
                    <Text fontSize="xs" color="gray.500">Реализаций: {row.allocations_count}</Text>
                </Box>
            ),
        },
        {
            key: 'bank_number',
            label: 'По банку',
            render: (_, row) => (
                <Box>
                    <Text fontSize="sm" fontFamily="mono">{row.bank_number || '—'}</Text>
                    {row.bank_confirmed && (
                        <Badge colorPalette="green" variant="subtle" size="xs">проведён</Badge>
                    )}
                </Box>
            ),
        },
        {
            key: 'created_at',
            label: 'Загружен',
            sortable: true,
            render: (_, row) => (
                <Box>
                    <Text fontSize="sm" color="gray.600">{row.created_at}</Text>
                    {row.deleted_at && (
                        <Badge colorPalette="red" variant="subtle" size="xs" mt={0.5}>
                            Удалён: {row.deleted_at}
                        </Badge>
                    )}
                </Box>
            ),
        },
        isTrashed
            ? {
                key: 'actions',
                label: 'Действия',
                render: (_, row) => (
                    <HStack gap={1}>
                        <IconButton
                            size="sm"
                            variant="ghost"
                            aria-label="Восстановить"
                            title="Восстановить"
                            onClick={() => handleRestore(row.id)}
                        >
                            <LuRotateCcw />
                        </IconButton>
                        <IconButton
                            size="sm"
                            variant="ghost"
                            colorPalette="red"
                            aria-label="Удалить окончательно"
                            title="Удалить окончательно"
                            onClick={() => setForceDeleteId(row.id)}
                        >
                            <LuTrash2 />
                        </IconButton>
                    </HStack>
                ),
            }
            : createActionsColumn('admin.payments', openDeleteDialog, {
                showEdit: false,
                permissionPrefix: 'payments',
                extraActions: (row) => (
                    <IconButton
                        size="sm"
                        variant="ghost"
                        aria-label="Просмотр"
                        onClick={() => router.visit(route('admin.payments.show', row.id))}
                    >
                        <LuEye />
                    </IconButton>
                ),
            }),
    ];

    return (
        <>
            <PageHeader
                title="Платежи"
                description="Платёжные документы из 1С. Реквизиты только для чтения — их ведёт учётная система."
                actions={
                    <TrashedFilter
                        trashed={isTrashed}
                        trashedCount={trashedCount}
                        onToggle={toggleTrashed}
                    />
                }
            />

            <Flex gap="3" mb={4} direction={{ base: 'column', sm: 'row' }}>
                <Box flex="1">
                    <SearchInput
                        value={searchQuery}
                        onChange={handleSearch}
                        placeholder="Поиск по номеру, номеру по банку, УИП, ИНН или контрагенту…"
                    />
                </Box>
                <Button onClick={() => setShowFilters(!showFilters)} variant="outline" size="sm" flexShrink="0">
                    <LuFilter size={16} />
                    {showFilters ? 'Скрыть фильтры' : 'Фильтры'}
                </Button>
            </Flex>

            {showFilters && (
                <Card.Root mb={4}>
                    <Card.Body p={4}>
                        <Stack gap={4}>
                            <Flex gap={4} direction={{ base: 'column', md: 'row' }}>
                                <Field label="Направление" flex="1">
                                    <Select.Root
                                        value={localFilters.direction ? [localFilters.direction] : []}
                                        onValueChange={(e) => setLocalFilters({ ...localFilters, direction: e.value[0] || '' })}
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Все направления" />
                                        </Select.Trigger>
                                        <Select.Content>
                                            <Select.Item item="">Все направления</Select.Item>
                                            {directions?.map((option) => (
                                                <Select.Item key={option.value} item={option.value}>{option.label}</Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </Field>

                                <Field label="Разнесение" flex="1">
                                    <Select.Root
                                        value={localFilters.allocation_status ? [localFilters.allocation_status] : []}
                                        onValueChange={(e) => setLocalFilters({ ...localFilters, allocation_status: e.value[0] || '' })}
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Любое" />
                                        </Select.Trigger>
                                        <Select.Content>
                                            <Select.Item item="">Любое</Select.Item>
                                            {allocationStatuses?.map((option) => (
                                                <Select.Item key={option.value} item={option.value}>{option.label}</Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </Field>

                                <Field label="Проведён банком" flex="1">
                                    <Select.Root
                                        value={localFilters.bank_confirmed !== '' ? [String(localFilters.bank_confirmed)] : []}
                                        onValueChange={(e) => setLocalFilters({ ...localFilters, bank_confirmed: e.value[0] ?? '' })}
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Неважно" />
                                        </Select.Trigger>
                                        <Select.Content>
                                            <Select.Item item="">Неважно</Select.Item>
                                            <Select.Item item="1">Проведён</Select.Item>
                                            <Select.Item item="0">Не проведён</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                </Field>

                                <Field label="Валюта" flex="1">
                                    <Select.Root
                                        value={localFilters.currency_code ? [localFilters.currency_code] : []}
                                        onValueChange={(e) => setLocalFilters({ ...localFilters, currency_code: e.value[0] || '' })}
                                    >
                                        <Select.Trigger>
                                            <Select.ValueText placeholder="Все валюты" />
                                        </Select.Trigger>
                                        <Select.Content>
                                            <Select.Item item="">Все валюты</Select.Item>
                                            <Select.Item item="RUB">RUB (₽)</Select.Item>
                                            <Select.Item item="KZT">KZT (₸)</Select.Item>
                                            <Select.Item item="BYN">BYN (Br)</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                </Field>

                                {organizationsEnabled && (
                                    <Field label="Организация" flex="1">
                                        <Select.Root
                                            value={localFilters.organization_id ? [String(localFilters.organization_id)] : []}
                                            onValueChange={(e) => setLocalFilters({ ...localFilters, organization_id: e.value[0] || '' })}
                                        >
                                            <Select.Trigger>
                                                <Select.ValueText placeholder="Все организации" />
                                            </Select.Trigger>
                                            <Select.Content>
                                                <Select.Item item="">Все организации</Select.Item>
                                                <Select.Item item="none">Не указана</Select.Item>
                                                {organizations?.map((organization) => (
                                                    <Select.Item key={organization.id} item={String(organization.id)}>
                                                        {organization.is_stub ? `${organization.name} (не заведена)` : organization.name}
                                                    </Select.Item>
                                                ))}
                                            </Select.Content>
                                        </Select.Root>
                                    </Field>
                                )}
                            </Flex>

                            <Flex gap={4} direction={{ base: 'column', md: 'row' }} align="end">
                                <Field label="Дата от" flex="1">
                                    <Input type="date" size="sm" value={localFilters.date_from}
                                        onChange={(e) => setLocalFilters({ ...localFilters, date_from: e.target.value })} />
                                </Field>
                                <Field label="Дата до" flex="1">
                                    <Input type="date" size="sm" value={localFilters.date_to}
                                        onChange={(e) => setLocalFilters({ ...localFilters, date_to: e.target.value })} />
                                </Field>
                                <Field label="Сумма от" flex="1">
                                    <Input type="number" step="0.01" size="sm" value={localFilters.amount_from}
                                        onChange={(e) => setLocalFilters({ ...localFilters, amount_from: e.target.value })} />
                                </Field>
                                <Field label="Сумма до" flex="1">
                                    <Input type="number" step="0.01" size="sm" value={localFilters.amount_to}
                                        onChange={(e) => setLocalFilters({ ...localFilters, amount_to: e.target.value })} />
                                </Field>

                                <HStack gap="2" flexShrink="0">
                                    <Button onClick={handleApplyFilters} colorPalette="blue" size="sm">Применить</Button>
                                    <Button onClick={handleResetFilters} variant="outline" size="sm">
                                        <LuX size={14} /> Сбросить
                                    </Button>
                                </HStack>
                            </Flex>
                        </Stack>
                    </Card.Body>
                </Card.Root>
            )}

            <DataTable
                data={payments.data}
                columns={columns}
                pagination={payments}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
            />

            <ConfirmDialog
                open={deleteDialogOpen}
                onClose={closeDeleteDialog}
                onConfirm={confirmDelete}
                title="Удалить платёж?"
                description="Платёж будет помечен удалённым, оплата связанных реализаций пересчитается. Его можно восстановить из корзины."
            />

            <ConfirmDialog
                open={!!forceDeleteId}
                onClose={() => setForceDeleteId(null)}
                onConfirm={handleForceDelete}
                title="Окончательное удаление платежа"
                description="Платёж и его расшифровка будут удалены безвозвратно. Восстановить их сможет только повторная выгрузка из 1С."
                confirmLabel="Удалить окончательно"
                colorPalette="red"
            />
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
