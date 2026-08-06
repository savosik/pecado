import { Head, router } from '@inertiajs/react';
import { Badge, Box, Flex, HStack, Input, Text, VStack } from '@chakra-ui/react';
import { LuDownload, LuEye } from 'react-icons/lu';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { ProductSelector } from '@/Admin/Components/ProductSelector';
import { Button } from '@/components/ui/button';
import MultiSelectFilter from '@/Crm/Components/MultiSelectFilter';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { useDocumentFilters } from '@/Crm/hooks/useDocumentFilters';

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
    partners = [],
    companies = [],
    managers = [],
    seesAll = false,
    selectedProducts = [],
}) {
    const { searchQuery, handleSearch, handleSort } = useResourceIndex(routeName, filters, {
        entityLabel: 'Документ',
    });

    const { apply, exportXlsx, reset, selected } = useDocumentFilters(routeName, filters);

    const hasFilters = Boolean(
        filters.search || filters.date_from || filters.date_to
        || filters.amount_from || filters.amount_to,
    ) || [
        'statuses', 'partner_ids', 'company_ids', 'manager_ids',
        'organization_ids', 'warehouse_ids', 'product_ids',
    ].some((key) => selected(key).length > 0);

    // 'none' — псевдо-значение «поле пустое»: документов без организации,
    // склада или контрагента в базе хватает, и отобрать их бывает нужно.
    const withNone = (options, label) => [{ id: 'none', name: label }, ...options];

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
                </HStack>

                <Flex gap={3} wrap="wrap" align="start">
                    <MultiSelectFilter
                        label="Статус"
                        options={statuses}
                        idKey="value"
                        labelKey="label"
                        allLabel="Все статусы"
                        selectedIds={selected('statuses')}
                        onChange={(values) => apply({ statuses: values })}
                        minW="180px"
                    />

                    <MultiSelectFilter
                        label="Партнёр"
                        options={partners}
                        allLabel="Все партнёры"
                        selectedIds={selected('partner_ids')}
                        onChange={(values) => apply({ partner_ids: values })}
                        minW="220px"
                    />

                    <MultiSelectFilter
                        label="Контрагент"
                        options={withNone(companies, 'Без контрагента')}
                        allLabel="Все контрагенты"
                        selectedIds={selected('company_ids')}
                        onChange={(values) => apply({ company_ids: values })}
                        minW="220px"
                    />

                    {seesAll && (
                        <MultiSelectFilter
                            label="Менеджер"
                            options={managers}
                            allLabel="Все менеджеры"
                            selectedIds={selected('manager_ids')}
                            onChange={(values) => apply({ manager_ids: values })}
                            minW="180px"
                        />
                    )}

                    {organizationsEnabled && (
                        <MultiSelectFilter
                            label="Организация"
                            options={withNone(organizations, 'Без организации')}
                            allLabel="Все организации"
                            selectedIds={selected('organization_ids')}
                            onChange={(values) => apply({ organization_ids: values })}
                            minW="200px"
                        />
                    )}

                    <MultiSelectFilter
                        label="Склад"
                        options={withNone(warehouses, 'Без склада')}
                        allLabel="Все склады"
                        selectedIds={selected('warehouse_ids')}
                        onChange={(values) => apply({ warehouse_ids: values })}
                        minW="200px"
                    />

                    <VStack align="stretch" gap={1} flex="1" minW="280px">
                        <Text fontSize="xs" color="fg.muted" fontWeight="500">
                            Товар в документе
                            {selectedProducts.length > 0 ? ` — выбрано ${selectedProducts.length}` : ''}
                        </Text>
                        <ProductSelector
                            mode="multi"
                            value={selectedProducts}
                            onChange={(items) => apply({ product_ids: items.map((item) => item.id) })}
                            searchRoute="crm.documents.products.search"
                            compactSelected
                        />
                    </VStack>
                </Flex>

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

                    <Button size="xs" variant="outline" onClick={exportXlsx} ml="auto">
                        <LuDownload /> XLSX
                    </Button>
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
