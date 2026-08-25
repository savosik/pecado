import { Head, router } from '@inertiajs/react';
import { Badge, Box, Flex, Grid, HStack, Text, VStack } from '@chakra-ui/react';
import { LuDownload, LuEye, LuX } from 'react-icons/lu';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Button } from '@/components/ui/button';
import MultiSelectFilter from '@/Crm/Components/MultiSelectFilter';
import AmountFilterInput from '@/Crm/Components/AmountFilterInput';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import FilterChips from '@/Crm/Components/FilterChips';
import PeriodFilter from '@/Crm/Components/PeriodFilter';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { useDocumentFilters } from '@/Crm/hooks/useDocumentFilters';

/** «1 платёж», «2 платежа», «5 платежей» — иначе итог читается как опечатка. */
const paymentsLabel = (count) => {
    const tail = count % 10;
    const teen = count % 100 >= 11 && count % 100 <= 14;

    if (!teen && tail === 1) return `${count} платёж`;
    if (!teen && tail >= 2 && tail <= 4) return `${count} платежа`;

    return `${count} платежей`;
};

/** ISO-дата из поля <input type="date"> — в человеческий вид для чипа. */
const humanDate = (value) => (value ? value.split('-').reverse().join('.') : '');

/**
 * Журнал платежей — раздел «Финансы».
 *
 * Отдельный компонент, а не параметризованный DocumentList: у платежа нет ни
 * позиций, ни статусов 1С, а фильтр по товару бессмыслен — зато есть направление
 * денег и состояние разнесения, которых нет у документов.
 *
 * Здесь только факт — проведённые платежи. План по дням живёт своим пунктом
 * меню («Календарь поступлений»), и переключателя между ними на странице нет:
 * навигация по разделу — одно меню слева.
 *
 * Читаем только: реквизиты и расшифровку ведёт 1С.
 */
export default function PaymentList({
    payments,
    filters,
    totals = null,
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

    // 'none' — псевдо-значение «поле пустое»: платежи без контрагента приезжают
    // из 1С раньше самого контрагента, и отобрать их бывает нужно.
    const withNone = (options, label) => [{ id: 'none', name: label }, ...options];

    const companyOptions = withNone(companies, 'Без контрагента');
    const organizationOptions = withNone(organizations, 'Без организации');

    /**
     * Чипы одного мультивыбора: подпись значения ищется в его же справочнике,
     * потому что в отборе лежат только идентификаторы.
     */
    const chipsFor = (key, options, label, idKey = 'id', labelKey = 'name') => selected(key).map((value) => ({
        key: `${key}:${value}`,
        label,
        value: options.find((option) => String(option[idKey]) === String(value))?.[labelKey] ?? String(value),
        onRemove: () => apply({ [key]: selected(key).filter((item) => String(item) !== String(value)) }),
    }));

    const chips = [
        ...chipsFor('directions', directions, 'Направление', 'value', 'label'),
        ...chipsFor('allocation_statuses', allocationStatuses, 'Разнесение', 'value', 'label'),
        ...chipsFor('partner_ids', partners, 'Партнёр'),
        ...chipsFor('company_ids', companyOptions, 'Контрагент'),
        ...(seesAll ? chipsFor('manager_ids', managers, 'Менеджер') : []),
        ...(organizationsEnabled ? chipsFor('organization_ids', organizationOptions, 'Организация') : []),
    ];

    // Период и сумма — диапазоны, поэтому одним чипом на пару полей: два чипа
    // «от» и «до» пришлось бы читать вместе, а снимать по отдельности.
    if (filters.date_from || filters.date_to) {
        chips.push({
            key: 'period',
            label: 'Период',
            value: [humanDate(filters.date_from) || '…', humanDate(filters.date_to) || '…'].join(' — '),
            onRemove: () => apply({ date_from: undefined, date_to: undefined }),
        });
    }

    if (filters.amount_from || filters.amount_to) {
        chips.push({
            key: 'amount',
            label: 'Сумма',
            value: [filters.amount_from || '0', filters.amount_to || '∞'].join(' — '),
            onRemove: () => apply({ amount_from: undefined, amount_to: undefined }),
        });
    }

    if (filters.search) {
        chips.push({
            key: 'search',
            label: 'Поиск',
            value: filters.search,
            // Через handleSearch, а не apply: строку поиска хранит локальное
            // состояние поля, и без него текст остался бы в инпуте после снятия чипа.
            onRemove: () => handleSearch(''),
        });
    }

    const hasFilters = chips.length > 0;

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
                    <VStack align="start" gap={0}>
                        <Box
                            as="a"
                            href={row.client.url}
                            fontSize="sm"
                            _hover={{ color: 'blue.fg', textDecoration: 'underline' }}
                        >
                            {row.client.name}
                        </Box>
                        {/* Менеджер подписью, а не колонкой: своя колонка на восемь
                            существующих не влезает, а вопрос «чей это клиент»
                            возникает ровно в момент чтения имени партнёра. */}
                        <Text fontSize="10px" color="fg.muted">
                            {row.client.manager_name || 'без менеджера'}
                        </Text>
                    </VStack>
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

            {/* Панель отбора одним блоком: поиск и режим сверху, справочники
                сеткой, диапазоны снизу. Раньше всё шло в три ряда вперемешку,
                и галочка «Только мои» оказывалась зажата между выпадающими
                списками — без подписи, но на их высоте. */}
            <Box borderWidth="1px" borderRadius="lg" p={4} mb={3} bg="bg.panel">
                <VStack align="stretch" gap={3}>
                    <HStack gap={3} align="center" wrap="wrap">
                        <Box flex="1" minW="240px">
                            <SearchInput
                                value={searchQuery}
                                onChange={handleSearch}
                                placeholder="Номер, номер по банку, УИП или партнёр…"
                            />
                        </Box>

                        <ScopeToggle section="finance" scope={filters.scope} available={seesAll} />

                        <Button size="sm" variant="outline" onClick={exportXlsx}>
                            <LuDownload /> XLSX
                        </Button>
                    </HStack>

                    <Grid
                        gap={3}
                        templateColumns={{
                            base: '1fr',
                            md: 'repeat(2, minmax(0, 1fr))',
                            xl: 'repeat(3, minmax(0, 1fr))',
                        }}
                    >
                        <MultiSelectFilter
                            label="Направление"
                            options={directions}
                            idKey="value"
                            labelKey="label"
                            allLabel="Все направления"
                            selectedIds={selected('directions')}
                            onChange={(values) => apply({ directions: values })}
                            minW="0"
                        />

                        <MultiSelectFilter
                            label="Разнесение"
                            options={allocationStatuses}
                            idKey="value"
                            labelKey="label"
                            allLabel="Любое"
                            selectedIds={selected('allocation_statuses')}
                            onChange={(values) => apply({ allocation_statuses: values })}
                            minW="0"
                        />

                        <MultiSelectFilter
                            label="Партнёр"
                            options={partners}
                            allLabel="Все партнёры"
                            selectedIds={selected('partner_ids')}
                            onChange={(values) => apply({ partner_ids: values })}
                            minW="0"
                        />

                        {/* Справочник контрагентов сервер сужает до юрлиц выбранных
                            партнёров — подпись объясняет, почему список короче,
                            чем был минуту назад. */}
                        <MultiSelectFilter
                            label={selected('partner_ids').length > 0 ? 'Контрагент (выбранных партнёров)' : 'Контрагент'}
                            options={companyOptions}
                            allLabel="Все контрагенты"
                            selectedIds={selected('company_ids')}
                            onChange={(values) => apply({ company_ids: values })}
                            minW="0"
                        />

                        {seesAll && (
                            <MultiSelectFilter
                                label="Менеджер"
                                options={managers}
                                allLabel="Все менеджеры"
                                selectedIds={selected('manager_ids')}
                                onChange={(values) => apply({ manager_ids: values })}
                                minW="0"
                            />
                        )}

                        {organizationsEnabled && (
                            <MultiSelectFilter
                                label="Организация"
                                options={organizationOptions}
                                allLabel="Все организации"
                                selectedIds={selected('organization_ids')}
                                onChange={(values) => apply({ organization_ids: values })}
                                minW="0"
                            />
                        )}
                    </Grid>

                    <Flex gap={4} wrap="wrap" align="center">
                        <PeriodFilter
                            from={filters.date_from}
                            to={filters.date_to}
                            onChange={(patch) => apply(patch)}
                        />

                        <HStack gap={2}>
                            <Text fontSize="xs" color="fg.muted" whiteSpace="nowrap">Сумма от</Text>
                            <AmountFilterInput
                                width="120px"
                                value={filters.amount_from}
                                onCommit={(value) => apply({ amount_from: value })}
                            />
                            <Text fontSize="xs" color="fg.muted">до</Text>
                            <AmountFilterInput
                                width="120px"
                                value={filters.amount_to}
                                onCommit={(value) => apply({ amount_to: value })}
                            />
                        </HStack>

                        {hasFilters && (
                            <Button size="xs" variant="outline" colorPalette="red" onClick={reset} ml="auto">
                                <LuX /> Сбросить всё
                            </Button>
                        )}
                    </Flex>
                </VStack>
            </Box>

            <FilterChips items={chips} onReset={reset} />

            {/* Итог по всему отбору, а не по странице: «сколько пришло за август»
                — вопрос, ради которого фильтр и открывают. Поступления и возвраты
                раздельно, валюты не складываются: курса на дату платежа сайт не знает. */}
            {totals && totals.count > 0 && (
                <HStack gap={4} wrap="wrap" mb={3} px={1}>
                    <Text fontSize="sm" color="fg.muted">Найдено: {paymentsLabel(totals.count)}</Text>

                    {totals.buckets.map((bucket) => (
                        <HStack key={`${bucket.currency}:${bucket.direction}`} gap={2}>
                            <Text fontSize="xs" color="fg.muted">{bucket.direction_label}:</Text>
                            <Text fontSize="sm" fontWeight="600">{bucket.amount_label}</Text>
                            <Text fontSize="10px" color="fg.muted">({bucket.count})</Text>
                        </HStack>
                    ))}
                </HStack>
            )}

            <DataTable
                data={payments.data}
                columns={columns}
                pagination={payments}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
                perPage={filters.per_page}
                onPerPageChange={(perPage) => apply({ per_page: perPage })}
                emptyMessage={hasFilters
                    ? 'Под текущий отбор платежей нет — снимите часть фильтров выше'
                    : 'Платежи не найдены'}
            />
        </>
    );
}
