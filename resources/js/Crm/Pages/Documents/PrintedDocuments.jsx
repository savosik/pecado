import { Head } from '@inertiajs/react';
import { Badge, Box, Flex, HStack, Input, Text, VStack } from '@chakra-ui/react';
import { LuDownload, LuFileDown, LuX } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Button } from '@/components/ui/button';
import MultiSelectFilter from '@/Crm/Components/MultiSelectFilter';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { useDocumentFilters } from '@/Crm/hooks/useDocumentFilters';

const ROUTE_NAME = 'crm.printed-documents';

/**
 * Печатные формы документов из 1С внутри CRM (v16.1.0).
 *
 * Отдельный компонент, а не ещё один режим DocumentList: у печатной формы нет
 * ни статуса 1С, ни суммы, ни позиций, ни склада — то есть половины того, вокруг
 * чего построен тот список. Зато есть своё: вид формы и состояние файла.
 *
 * Раздел открывается менеджерам раньше клиентского: пока идёт первичная выгрузка,
 * именно здесь видно, что 1С прислала на самом деле. Поэтому показываются и формы
 * с проблемным файлом, и формы без контрагента — это и есть диагностика обмена.
 */
export default function PrintedDocuments({
    documents,
    filters,
    types = [],
    fileStatuses = [],
    organizations = [],
    organizationsEnabled = false,
    partners = [],
    companies = [],
    managers = [],
    seesAll = false,
}) {
    const { searchQuery, handleSearch, handleSort } = useResourceIndex(ROUTE_NAME, filters, {
        entityLabel: 'Документ',
    });

    const { apply, exportXlsx, reset, selected } = useDocumentFilters(ROUTE_NAME, filters);

    const hasFilters = Boolean(filters.search || filters.date_from || filters.date_to)
        || ['types', 'file_statuses', 'partner_ids', 'company_ids', 'manager_ids', 'organization_ids']
            .some((key) => selected(key).length > 0);

    // 'none' — псевдо-значение «поле пустое». Формы без контрагента отбирают
    // не из любопытства: это метрика качества обмена.
    const withNone = (options, label) => [{ id: 'none', name: label }, ...options];

    const columns = [
        {
            key: 'type',
            label: 'Вид документа',
            sortable: true,
            render: (_, row) => (
                <VStack align="start" gap={1}>
                    <Badge colorPalette={row.type_color || 'gray'} variant="subtle">
                        {row.type_label}
                    </Badge>
                    <Text fontSize="10px" color="fg.muted">{row.title}</Text>
                </VStack>
            ),
        },
        {
            key: 'number',
            label: 'Номер',
            sortable: true,
            render: (_, row) => <Text fontSize="sm" fontWeight="600">{row.number || '—'}</Text>,
        },
        {
            key: 'date',
            label: 'Дата',
            sortable: true,
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm" whiteSpace="nowrap">{row.date_label || '—'}</Text>
                    {/* Период есть только у форм за период: у акта сверки одна дата
                        ничего не говорит — два акта одного клиента различает именно он. */}
                    {row.period_label && (
                        <Text fontSize="10px" color="fg.muted" whiteSpace="nowrap">{row.period_label}</Text>
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
                : <Text fontSize="sm" color="orange.fg">не сопоставлен</Text>),
        },
        {
            key: 'company',
            label: 'Контрагент',
            render: (_, row) => (row.company
                ? <Text fontSize="sm">{row.company}</Text>
                // Без контрагента документ не увидит клиент — предупреждаем цветом,
                // а не прочерком: это не «пусто», а «не дойдёт до адресата».
                : <Text fontSize="sm" color="orange.fg">нет контрагента</Text>),
        },
        ...(organizationsEnabled ? [{
            key: 'organization',
            label: 'Продавец',
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
            key: 'base',
            label: 'Основание',
            render: (_, row) => (row.base
                ? (
                    <Box
                        as="a"
                        href={row.base.url}
                        fontSize="sm"
                        _hover={{ color: 'blue.fg', textDecoration: 'underline' }}
                    >
                        {row.base.label}
                    </Box>
                )
                : <Text fontSize="sm" color="fg.muted">—</Text>),
        },
        {
            key: 'file_status',
            label: 'Файл',
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Badge
                        colorPalette={row.file_status === 'stored' ? 'green' : 'orange'}
                        variant="subtle"
                    >
                        {row.file_status_label}
                    </Badge>
                    {(row.size_label || row.format_label) && (
                        <Text fontSize="10px" color="fg.muted">
                            {[row.format_label, row.size_label].filter(Boolean).join(' · ')}
                        </Text>
                    )}
                </VStack>
            ),
        },
        {
            key: 'actions',
            label: '',
            render: (_, row) => (row.download_url
                ? (
                    // Обычная ссылка, а не router.visit: сервер отдаёт файл,
                    // а Inertia ждёт JSON и такой ответ не поймёт.
                    <Button
                        size="xs"
                        variant="ghost"
                        as="a"
                        href={row.download_url}
                        aria-label="Скачать документ"
                    >
                        <LuFileDown />
                    </Button>
                )
                : null),
        },
    ];

    return (
        <>
            {/* «Печатные формы», а не «Документы»: группа меню в CRM уже называется
                «Документы» и содержит заказы, реализации и платежи. Пункт «Документы»
                внутри неё путал бы файл со своим документом-основанием. Клиенту
                в кабинете тот же раздел называется просто «Документы» — ему
                различать нечего, у него журналов 1С нет. */}
            <Head title="CRM — Печатные формы" />
            <PageHeader
                title="Печатные формы"
                description="Готовые печатные формы из 1С: счета, счета-фактуры, УПД, акты сверки в Excel. Здесь только читаются"
            />

            <VStack align="stretch" gap={3} mb={4}>
                <HStack gap={3} align="center" wrap="wrap">
                    <Box flex="1" minW="260px">
                        <SearchInput
                            value={searchQuery}
                            onChange={handleSearch}
                            placeholder="Номер или название документа..."
                        />
                    </Box>
                </HStack>

                <Flex gap={3} wrap="wrap" align="start">
                    <MultiSelectFilter
                        label="Вид документа"
                        options={types}
                        idKey="value"
                        labelKey="label"
                        allLabel="Все виды"
                        selectedIds={selected('types')}
                        onChange={(values) => apply({ types: values })}
                        minW="220px"
                    />

                    <MultiSelectFilter
                        label="Файл"
                        options={fileStatuses}
                        idKey="value"
                        labelKey="label"
                        allLabel="Любое состояние"
                        selectedIds={selected('file_statuses')}
                        onChange={(values) => apply({ file_statuses: values })}
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

                    <ScopeToggle section="documents" scope={filters.scope} available={seesAll} />

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
                            label="Продавец"
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

                    {/* Outline с крестиком: ghost-кнопка сброса рядом с полями
                        ввода выглядит подписью и её не замечают. */}
                    {hasFilters && (
                        <Button size="xs" variant="outline" colorPalette="red" onClick={reset}>
                            <LuX /> Сбросить
                        </Button>
                    )}

                    <Button size="xs" variant="outline" onClick={exportXlsx} ml="auto">
                        <LuDownload /> XLSX
                    </Button>
                </HStack>
            </VStack>

            <DataTable
                data={documents.data}
                columns={columns}
                pagination={documents}
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

PrintedDocuments.layout = (page) => <CrmLayout>{page}</CrmLayout>;
