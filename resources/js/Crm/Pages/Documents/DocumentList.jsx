import { Head, router } from '@inertiajs/react';
import { Badge, Box, Flex, Grid, HStack, Text, VStack } from '@chakra-ui/react';
import { LuDownload, LuEye, LuX } from 'react-icons/lu';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { ProductSelector } from '@/Admin/Components/ProductSelector';
import { Button } from '@/components/ui/button';
import MultiSelectFilter from '@/Crm/Components/MultiSelectFilter';
import AmountFilterInput from '@/Crm/Components/AmountFilterInput';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import FilterChips from '@/Crm/Components/FilterChips';
import MetricHint from '@/Crm/Components/MetricHint';
import PeriodFilter from '@/Crm/Components/PeriodFilter';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import { useDocumentFilters } from '@/Crm/hooks/useDocumentFilters';

/** «1 документ», «2 документа», «5 документов» — иначе итог читается как опечатка. */
const documentsLabel = (count) => {
    const tail = count % 10;
    const teen = count % 100 >= 11 && count % 100 <= 14;

    if (!teen && tail === 1) return `${count} документ`;
    if (!teen && tail >= 2 && tail <= 4) return `${count} документа`;

    return `${count} документов`;
};

/** ISO-дата из поля <input type="date"> — в человеческий вид для чипа. */
const humanDate = (value) => (value ? value.split('-').reverse().join('.') : '');

/**
 * Список документов 1С внутри CRM — заказы или реализации.
 *
 * Один компонент на оба списка: колонки и фильтры у них совпадают, различаются
 * заголовок, набор статусов и маршрут. Две копии разошлись бы на первой же
 * правке фильтров.
 *
 * Видимость обеспечивает сервер (скоуп партнёров актора), фронт ничего не прячет:
 * фильтрация на партнёре означала бы, что чужой документ приезжает в браузер
 * и просто не рисуется.
 *
 * Панель отбора устроена так же, как в журнале платежей: поиск и режим сверху,
 * справочники ровной сеткой, диапазоны снизу, активные фильтры чипами. Три
 * журнала одного раздела, отличающиеся раскладкой фильтров, читаются как три
 * разных продукта.
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
    totals = null,
    schedule = null,
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

    // 'none' — псевдо-значение «поле пустое»: документов без организации,
    // склада или контрагента в базе хватает, и отобрать их бывает нужно.
    const withNone = (options, label) => [{ id: 'none', name: label }, ...options];

    const companyOptions = withNone(companies, 'Без контрагента');
    const organizationOptions = withNone(organizations, 'Без организации');
    const warehouseOptions = withNone(warehouses, 'Без склада');

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
        ...chipsFor('statuses', statuses, 'Статус', 'value', 'label'),
        ...chipsFor('partner_ids', partners, 'Партнёр'),
        ...chipsFor('company_ids', companyOptions, 'Контрагент'),
        ...(seesAll ? chipsFor('manager_ids', managers, 'Менеджер') : []),
        ...(organizationsEnabled ? chipsFor('organization_ids', organizationOptions, 'Организация') : []),
        ...chipsFor('warehouse_ids', warehouseOptions, 'Склад'),
        // Товары приезжают отдельным пропсом с названиями: в отборе только id.
        ...selectedProducts.map((product) => ({
            key: `product:${product.id}`,
            label: 'Товар',
            value: product.name,
            onRemove: () => apply({
                product_ids: selectedProducts.filter((item) => item.id !== product.id).map((item) => item.id),
            }),
        })),
    ];

    // Период и сумма — диапазоны, поэтому одним чипом на пару полей.
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
            // Через handleSearch, а не apply: строку хранит локальное состояние
            // поля, и без него текст остался бы в инпуте после снятия чипа.
            onRemove: () => handleSearch(''),
        });
    }

    const hasFilters = chips.length > 0;

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
                        {/* Менеджер подписью, а не колонкой: вопрос «чей это
                            клиент» возникает в момент чтения имени партнёра. */}
                        <Text fontSize="10px" color="fg.muted">
                            {row.client.manager_name || 'без менеджера'}
                        </Text>
                    </VStack>
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

            {/* Панель отбора той же формы, что в журнале платежей: поиск,
                режим и выгрузка сверху; справочники сеткой; диапазоны снизу. */}
            <Box borderWidth="1px" borderRadius="lg" p={4} mb={3} bg="bg.panel">
                <VStack align="stretch" gap={3}>
                    <HStack gap={3} align="center" wrap="wrap">
                        <Box flex="1" minW="240px">
                            <SearchInput
                                value={searchQuery}
                                onChange={handleSearch}
                                placeholder="Номер, партнёр или товар…"
                            />
                        </Box>

                        <ScopeToggle section="documents" scope={filters.scope} available={seesAll} />

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
                            label="Статус"
                            options={statuses}
                            idKey="value"
                            labelKey="label"
                            allLabel="Все статусы"
                            selectedIds={selected('statuses')}
                            onChange={(values) => apply({ statuses: values })}
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
                            партнёров — подпись объясняет, почему список короче. */}
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

                        <MultiSelectFilter
                            label="Склад"
                            options={warehouseOptions}
                            allLabel="Все склады"
                            selectedIds={selected('warehouse_ids')}
                            onChange={(values) => apply({ warehouse_ids: values })}
                            minW="0"
                        />
                    </Grid>

                    {/* Товар — отдельной строкой: подсказки грузятся с сервера,
                        и контролу нужна вся ширина, а не треть сетки. */}
                    <VStack align="stretch" gap={1}>
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

            {/* Итог по всему отбору, а не по странице: «на сколько отгрузили
                за август» — вопрос, ради которого фильтр и открывают. */}
            {totals && totals.count > 0 && (
                <HStack gap={4} wrap="wrap" mb={3} px={1}>
                    <Text fontSize="sm" color="fg.muted">Найдено: {documentsLabel(totals.count)}</Text>

                    {totals.buckets.map((bucket) => (
                        <HStack key={bucket.currency} gap={2}>
                            <Text fontSize="xs" color="fg.muted">Сумма:</Text>
                            <Text fontSize="sm" fontWeight="600">{bucket.amount_label}</Text>
                            <MetricHint text="Сумма документов, попавших в отбор. Оплату не учитывает — это то, на сколько отгружено." />
                        </HStack>
                    ))}

                    {/* Оплата приходит из счётного ядра раздела «Финансы» — того же,
                        на котором стоят пульт и просрочка. Свой расчёт по документам
                        давал 44 млн «долга» против 11,5 млн реальных. */}
                    {(schedule?.buckets ?? []).map((bucket) => (
                        <HStack key={`schedule:${bucket.currency}`} gap={3}>
                            <HStack gap={2}>
                                <Text fontSize="xs" color="fg.muted">Оплачено:</Text>
                                <Text fontSize="sm" fontWeight="600" color="green.fg">{bucket.paid_label}</Text>
                                <MetricHint text="Сколько 1С зачла по этим документам: закрытые части плановых строк взаиморасчётов, включая авансы по заказам. По строке берётся не больше её суммы — переплата одной не гасит долг другой." />
                            </HStack>
                            <HStack gap={2}>
                                <Text fontSize="xs" color="fg.muted">Не оплачено:</Text>
                                <Text fontSize="sm" fontWeight="600" color="orange.fg">{bucket.unpaid_label}</Text>
                                <MetricHint text="Остаток по плановым строкам: сумма минус закрытая часть, ниже нуля не опускается. Это не весь долг партнёра — полный долг в «Балансах партнёров» и акте сверки, там же зачёты и корректировки." />
                            </HStack>
                        </HStack>
                    ))}

                    {schedule && (
                        <HStack gap={1.5}>
                            <Text fontSize="xs" color="fg.muted">
                                оплата — по данным взаиморасчётов 1С
                                {schedule.without_plan > 0
                                    ? `; без плана оплаты: ${schedule.without_plan} док.`
                                    : ''}
                            </Text>
                            <MetricHint text={schedule.without_plan > 0
                                ? `По ${schedule.without_plan} документам 1С не прислала плановых строк — их суммы не входят ни в «оплачено», ни в «не оплачено». Поэтому эти два числа не складываются в «сумму»: разница и есть такие документы.`
                                : 'Оплата считается по плановым строкам регистра взаиморасчётов, которые присылает 1С. Сайт ничего не досчитывает сам.'} />
                        </HStack>
                    )}
                </HStack>
            )}

            <DataTable
                data={pagination.data}
                columns={columns}
                pagination={pagination}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
                perPage={filters.per_page}
                onPerPageChange={(perPage) => apply({ per_page: perPage })}
                emptyMessage={hasFilters
                    ? 'Под текущий отбор документов нет — снимите часть фильтров выше'
                    : 'Документы не найдены'}
            />
        </>
    );
}

