import { Head, router } from '@inertiajs/react';
import { Badge, Box, HStack, Input, Text, VStack } from '@chakra-ui/react';
import { LuEye } from 'react-icons/lu';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Button } from '@/components/ui/button';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';

/**
 * Список документов 1С внутри CRM — заказы или реализации.
 *
 * Один компонент на оба списка: колонки и фильтры у них совпадают, различаются
 * заголовок, набор статусов и маршрут. Две копии разошлись бы на первой же
 * правке фильтров.
 *
 * Видимость обеспечивает сервер (скоуп клиентов актора), фронт ничего не прячет:
 * фильтрация на клиенте означала бы, что чужой документ приезжает в браузер
 * и просто не рисуется.
 *
 * @param {string} routeName — 'crm.orders' | 'crm.shipments'
 * @param {object} pagination — Laravel-пагинатор с трансформированными строками
 */
export default function DocumentList({
    routeName,
    title,
    description,
    pagination,
    filters,
    statuses = [],
    organizations = [],
    organizationsEnabled = false,
    warehouses = [],
}) {
    const { searchQuery, handleSearch, handleSort } = useResourceIndex(routeName, filters, {
        entityLabel: 'Документ',
    });

    const apply = (patch) => {
        router.get(route(`${routeName}.index`), { ...filters, ...patch }, {
            preserveState: true,
            replace: true,
        });
    };

    const reset = () => {
        router.get(route(`${routeName}.index`), { per_page: filters.per_page }, {
            preserveState: false,
            replace: true,
        });
    };

    const hasFilters = Boolean(
        filters.search || filters.status || filters.organization_id || filters.warehouse_id
        || filters.date_from || filters.date_to || filters.amount_from || filters.amount_to,
    );

    const columns = [
        {
            key: 'erp_number',
            label: 'Документ',
            sortable: true,
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm" fontWeight="600">
                        {row.erp_number || row.number || `#${row.id}`}
                    </Text>
                    {row.erp_number && row.number && (
                        <Text fontSize="10px" color="fg.muted">сайт: {row.number}</Text>
                    )}
                </VStack>
            ),
        },
        {
            key: 'client',
            label: 'Клиент',
            render: (_, row) => (row.client
                ? (
                    <Box
                        as="a"
                        href={row.client.url}
                        fontSize="sm"
                        _hover={{ color: 'blue.fg', textDecoration: 'underline' }}
                    >
                        {row.client.name}
                    </Box>
                )
                : <Text fontSize="sm" color="fg.muted">—</Text>),
        },
        {
            key: 'erp_created_at',
            label: 'Дата',
            sortable: true,
            render: (_, row) => <Text fontSize="sm" whiteSpace="nowrap">{row.date_label || '—'}</Text>,
        },
        {
            key: 'status',
            label: 'Статус',
            render: (_, row) => (
                <Badge colorPalette={row.status_color || 'gray'} variant="subtle">
                    {row.status_label}
                </Badge>
            ),
        },
        ...(organizationsEnabled ? [{
            key: 'organization',
            label: 'Организация',
            render: (_, row) => (row.organization
                ? (
                    <Text fontSize="sm" color={row.organization.is_stub ? 'orange.fg' : undefined}>
                        {row.organization.name}
                        {row.organization.is_stub ? ' (не заведено)' : ''}
                    </Text>
                )
                : <Text fontSize="sm" color="fg.muted">—</Text>),
        }] : []),
        {
            key: 'warehouse',
            label: 'Склад',
            render: (_, row) => <Text fontSize="sm">{row.warehouse || '—'}</Text>,
        },
        {
            key: 'items_count',
            label: 'Позиций',
            render: (_, row) => <Text fontSize="sm" color="fg.muted">{row.items_count || '—'}</Text>,
        },
        {
            key: 'total_amount',
            label: 'Сумма',
            sortable: true,
            render: (_, row) => (
                <Text fontSize="sm" fontWeight="600" whiteSpace="nowrap">{row.total_label}</Text>
            ),
        },
        {
            key: 'actions',
            label: '',
            render: (_, row) => (
                <Button
                    size="xs"
                    variant="ghost"
                    onClick={() => router.visit(row.url)}
                    aria-label="Открыть документ"
                >
                    <LuEye />
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title={`CRM — ${title}`} />
            <PageHeader title={title} description={description} />

            <VStack align="stretch" gap={3} mb={4}>
                <HStack gap={3} align="center" wrap="wrap">
                    <Box flex="1" minW="260px">
                        <SearchInput
                            value={searchQuery}
                            onChange={handleSearch}
                            placeholder="Номер, клиент или товар..."
                        />
                    </Box>

                    <Box minW="200px">
                        <NativeSelectRoot size="sm">
                            <NativeSelectField
                                value={filters.status ?? ''}
                                onChange={(e) => apply({ status: e.target.value || undefined })}
                            >
                                <option value="">Все статусы</option>
                                {statuses.map((status) => (
                                    <option key={status.value} value={status.value}>{status.label}</option>
                                ))}
                            </NativeSelectField>
                        </NativeSelectRoot>
                    </Box>

                    {organizationsEnabled && (
                        <Box minW="200px">
                            <NativeSelectRoot size="sm">
                                <NativeSelectField
                                    value={filters.organization_id ?? ''}
                                    onChange={(e) => apply({ organization_id: e.target.value || undefined })}
                                >
                                    <option value="">Все организации</option>
                                    <option value="none">Без организации</option>
                                    {organizations.map((organization) => (
                                        <option key={organization.id} value={organization.id}>
                                            {organization.name}
                                        </option>
                                    ))}
                                </NativeSelectField>
                            </NativeSelectRoot>
                        </Box>
                    )}

                    <Box minW="180px">
                        <NativeSelectRoot size="sm">
                            <NativeSelectField
                                value={filters.warehouse_id ?? ''}
                                onChange={(e) => apply({ warehouse_id: e.target.value || undefined })}
                            >
                                <option value="">Все склады</option>
                                {warehouses.map((warehouse) => (
                                    <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>
                                ))}
                            </NativeSelectField>
                        </NativeSelectRoot>
                    </Box>
                </HStack>

                <HStack gap={3} align="center" wrap="wrap">
                    <HStack gap={2}>
                        <Text fontSize="xs" color="fg.muted" whiteSpace="nowrap">Период с</Text>
                        <Input
                            size="sm"
                            type="date"
                            width="160px"
                            value={filters.date_from ?? ''}
                            onChange={(e) => apply({ date_from: e.target.value || undefined })}
                        />
                        <Text fontSize="xs" color="fg.muted">по</Text>
                        <Input
                            size="sm"
                            type="date"
                            width="160px"
                            value={filters.date_to ?? ''}
                            onChange={(e) => apply({ date_to: e.target.value || undefined })}
                        />
                    </HStack>

                    <HStack gap={2}>
                        <Text fontSize="xs" color="fg.muted" whiteSpace="nowrap">Сумма от</Text>
                        <Input
                            size="sm"
                            type="number"
                            width="120px"
                            value={filters.amount_from ?? ''}
                            onChange={(e) => apply({ amount_from: e.target.value || undefined })}
                        />
                        <Text fontSize="xs" color="fg.muted">до</Text>
                        <Input
                            size="sm"
                            type="number"
                            width="120px"
                            value={filters.amount_to ?? ''}
                            onChange={(e) => apply({ amount_to: e.target.value || undefined })}
                        />
                    </HStack>

                    {hasFilters && (
                        <Button size="xs" variant="ghost" onClick={reset}>Сбросить</Button>
                    )}
                </HStack>
            </VStack>

            <DataTable
                data={pagination.data}
                columns={columns}
                pagination={pagination}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
                perPage={filters.per_page}
                onPerPageChange={(perPage) => apply({ per_page: perPage })}
                emptyMessage="Документы не найдены"
            />
        </>
    );
}
