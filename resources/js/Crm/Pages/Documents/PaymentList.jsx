import { Head, router } from '@inertiajs/react';
import { Badge, Box, Flex, HStack, Input, Text, VStack } from '@chakra-ui/react';
import { LuDownload, LuEye, LuList, LuCalendarClock } from 'react-icons/lu';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Button } from '@/components/ui/button';
import MultiSelectFilter from '@/Crm/Components/MultiSelectFilter';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { useDocumentFilters } from '@/Crm/hooks/useDocumentFilters';

/**
 * Журнал платежей внутри CRM.
 *
 * Отдельный компонент, а не параметризованный DocumentList: у платежа нет ни
 * позиций, ни статусов 1С, а фильтр по товару бессмыслен — зато есть направление
 * денег и состояние разнесения, которых нет у документов.
 *
 * Читаем только: реквизиты и расшифровку ведёт 1С.
 */
export default function PaymentList({
    payments,
    filters,
    directions = [],
    allocationStatuses = [],
    organizations = [],
    organizationsEnabled = false,
    partners = [],
    companies = [],
    managers = [],
    seesAll = false,
}) {
    const { searchQuery, handleSearch, handleSort } = useResourceIndex('crm.payments', filters, {
        entityLabel: 'Платёж',
    });

    const { apply, exportXlsx, reset, selected } = useDocumentFilters('crm.payments', filters);

    const hasFilters = Boolean(
        filters.search || filters.date_from || filters.date_to
        || filters.amount_from || filters.amount_to,
    ) || [
        'directions', 'allocation_statuses', 'partner_ids',
        'company_ids', 'manager_ids', 'organization_ids',
    ].some((key) => selected(key).length > 0);

    // 'none' — псевдо-значение «поле пустое»: платежи без контрагента приезжают
    // из 1С раньше самого контрагента, и отобрать их бывает нужно.
    const withNone = (options, label) => [{ id: 'none', name: label }, ...options];

    const columns = [
        {
            key: 'number',
            label: 'Платёж',
            sortable: true,
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm" fontWeight="600">{row.number || `#${row.id}`}</Text>
                    {row.bank_number && (
                        <Text fontSize="10px" color="fg.muted">по банку: {row.bank_number}</Text>
                    )}
                </VStack>
            ),
        },
        {
            key: 'client',
            label: 'Партнёр',
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
            key: 'company',
            label: 'Контрагент',
            render: (_, row) => (
                <Text fontSize="sm" color={row.company ? undefined : 'fg.muted'}>
                    {row.company || 'не заведён'}
                </Text>
            ),
        },
        {
            key: 'date',
            label: 'Дата',
            sortable: true,
            render: (_, row) => <Text fontSize="sm" whiteSpace="nowrap">{row.date_label || '—'}</Text>,
        },
        {
            key: 'direction',
            label: 'Направление',
            render: (_, row) => (
                <Badge colorPalette={row.direction_color} variant="subtle">
                    {row.direction_label}
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
            key: 'amount',
            label: 'Сумма',
            sortable: true,
            render: (_, row) => (
                <Text fontSize="sm" fontWeight="600" whiteSpace="nowrap">{row.total_label}</Text>
            ),
        },
        {
            key: 'unallocated_amount',
            label: 'Разнесение',
            sortable: true,
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Badge
                        colorPalette={{ allocated: 'green', partial: 'orange', advance: 'blue' }[row.allocation_status] || 'gray'}
                        variant="subtle"
                    >
                        {row.allocation_status_label}
                    </Badge>
                    {row.has_advance && (
                        <Text fontSize="10px" color="fg.muted">аванс: {row.unallocated_label}</Text>
                    )}
                    <Text fontSize="10px" color="fg.muted">реализаций: {row.allocations_count}</Text>
                </VStack>
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
                    aria-label="Открыть платёж"
                >
                    <LuEye />
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title="CRM — Платежи" />
            <PageHeader
                title="Платежи"
                description="Поступления и возвраты из 1С с расшифровкой по реализациям"
            />

            <VStack align="stretch" gap={3} mb={4}>
                {/* Журнал — факт (проведённые платежи), календарь — план
                    по графику из 1С вместе с фактом по дням. */}
                <HStack gap={2} wrap="wrap">
                    <Button size="sm" variant="solid" colorPalette="pecado">
                        <LuList size={16} /> Журнал
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => router.get('/crm/payments/calendar')}>
                        <LuCalendarClock size={16} /> Календарь поступлений
                    </Button>
                </HStack>

                <HStack gap={3} align="center" wrap="wrap">
                    <Box flex="1" minW="260px">
                        <SearchInput
                            value={searchQuery}
                            onChange={handleSearch}
                            placeholder="Номер, номер по банку, УИП или партнёр..."
                        />
                    </Box>
                </HStack>

                <Flex gap={3} wrap="wrap" align="start">
                    <MultiSelectFilter
                        label="Направление"
                        options={directions}
                        idKey="value"
                        labelKey="label"
                        allLabel="Все направления"
                        selectedIds={selected('directions')}
                        onChange={(values) => apply({ directions: values })}
                        minW="180px"
                    />

                    <MultiSelectFilter
                        label="Разнесение"
                        options={allocationStatuses}
                        idKey="value"
                        labelKey="label"
                        allLabel="Любое"
                        selectedIds={selected('allocation_statuses')}
                        onChange={(values) => apply({ allocation_statuses: values })}
                        minW="200px"
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
                data={payments.data}
                columns={columns}
                pagination={payments}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
                perPage={filters.per_page}
                onPerPageChange={(perPage) => apply({ per_page: perPage })}
                emptyMessage="Платежи не найдены"
            />
        </>
    );
}
