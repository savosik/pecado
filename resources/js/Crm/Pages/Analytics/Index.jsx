import { useEffect, useRef, useState, useCallback } from 'react';
import { Head, usePage } from '@inertiajs/react';
import {
    Box, Flex, VStack, Text, Spinner, HStack, IconButton, Accordion, Span, Badge, Tabs,
} from '@chakra-ui/react';
import { LuChartLine, LuLightbulb, LuLayoutGrid, LuFilter, LuChevronRight } from 'react-icons/lu';
import axios from 'axios';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import BreakdownSection from '@/Pages/User/Cabinet/Analytics/components/BreakdownSection';
import InsightsPanel from '@/Pages/User/Cabinet/Analytics/components/InsightsPanel';
import AbcXyzPanel from '@/Pages/User/Cabinet/Analytics/components/AbcXyzPanel';
import KpiGrid from './components/KpiGrid';
import TrendChart from './components/TrendChart';
import FiltersBar from './components/FiltersBar';
import PresetsBar from './components/PresetsBar';

const DEFAULT_FILTERS = {
    date_from: '',
    date_to: '',
    manager_ids: [],
    partner_ids: [],
    company_ids: [],
    brand_ids: [],
    category_ids: [],
    product_ids: [],
    sku: '',
};

const COMPARE_LABELS = {
    prev_period: 'Предыдущий период',
    month: 'Прошлый месяц',
    quarter: 'Прошлый квартал',
    year: 'Прошлый год',
};

function compareLabel(mode, offset) {
    const base = COMPARE_LABELS[mode] || 'Сравнение';
    return offset > 1 ? `${base} × ${offset}` : base;
}

function buildParams(filters, compareMode, compareOffset) {
    const params = {};
    if (filters.date_from) params.date_from = filters.date_from;
    if (filters.date_to) params.date_to = filters.date_to;
    if (filters.manager_ids?.length) params['manager_ids'] = filters.manager_ids;
    if (filters.partner_ids?.length) params['partner_ids'] = filters.partner_ids;
    if (filters.company_ids?.length) params['company_ids'] = filters.company_ids;
    if (filters.brand_ids?.length) params['brand_ids'] = filters.brand_ids;
    if (filters.category_ids?.length) params['category_ids'] = filters.category_ids;
    if (filters.product_ids?.length) params['product_ids'] = filters.product_ids;
    if (filters.sku) params.sku = filters.sku;
    if (compareMode && compareMode !== 'none') {
        params.compare_mode = compareMode;
        params.compare_offset = compareOffset;
    }
    return params;
}

export default function CrmAnalyticsIndex() {
    const { initial, filterOptions, seesAll, presets: initialPresets } = usePage().props;

    const [filters, setFilters] = useState({ ...DEFAULT_FILTERS, ...(initial?.filters ?? {}) });
    const [products, setProducts] = useState([]);
    const [compareMode, setCompareMode] = useState('none');
    const [compareOffset, setCompareOffset] = useState(1);
    const [presets, setPresets] = useState(initialPresets ?? []);
    const [data, setData] = useState(initial);
    const [loading, setLoading] = useState(false);
    const [panelOpen, setPanelOpen] = useState(() => {
        if (typeof window === 'undefined') return true;
        return window.localStorage.getItem('crmAnalyticsFiltersOpen') !== '0';
    });
    useEffect(() => {
        try {
            window.localStorage.setItem('crmAnalyticsFiltersOpen', panelOpen ? '1' : '0');
        } catch { /* localStorage может быть недоступен */ }
    }, [panelOpen]);
    const debounceRef = useRef(null);
    const isFirstRender = useRef(true);

    const reload = useCallback(async (nextFilters, mode, offset) => {
        setLoading(true);
        try {
            const res = await axios.get('/crm/analytics/data', {
                params: buildParams(nextFilters, mode, offset),
            });
            setData(res.data);
        } catch (e) {
            console.error('Не удалось загрузить отчёт', e);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }
        if (debounceRef.current) clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => {
            reload(filters, compareMode, compareOffset);
        }, 500);
        return () => debounceRef.current && clearTimeout(debounceRef.current);
    }, [filters, compareMode, compareOffset, reload]);

    const handleReset = () => {
        setProducts([]);
        setCompareMode('none');
        setCompareOffset(1);
        setFilters(DEFAULT_FILTERS);
    };

    const handleProductsChange = (nextProducts) => {
        setProducts(nextProducts);
        setFilters((prev) => ({ ...prev, product_ids: nextProducts.map((p) => p.id) }));
    };

    // Пресеты фильтров (персональные, бэкенд)
    const handleSavePreset = async (name) => {
        try {
            const payload = { filters, products, compareMode, compareOffset };
            const res = await axios.post('/crm/analytics/presets', { name, payload });
            setPresets((prev) => [res.data, ...prev]);
        } catch (e) {
            console.error('Не удалось сохранить пресет', e);
        }
    };
    const handleApplyPreset = (preset) => {
        const p = preset?.payload ?? {};
        setProducts(Array.isArray(p.products) ? p.products : []);
        setFilters({ ...DEFAULT_FILTERS, ...(p.filters ?? {}) });
        setCompareMode(p.compareMode ?? 'none');
        setCompareOffset(p.compareOffset ?? 1);
    };
    const handleDeletePreset = async (id) => {
        try {
            await axios.delete(`/crm/analytics/presets/${id}`);
            setPresets((prev) => prev.filter((x) => x.id !== id));
        } catch (e) {
            console.error('Не удалось удалить пресет', e);
        }
    };

    const buildExportUrl = () => {
        const base = Object.entries(buildParams(filters, 'none', 1)).flatMap(([k, v]) =>
            Array.isArray(v) ? v.map((x) => [`${k}[]`, x]) : [[k, v]]
        );
        return '/crm/analytics/export?' + new URLSearchParams(base).toString();
    };
    const handleExport = () => window.open(buildExportUrl(), '_blank');

    const applyFilter = (patch) => setFilters((prev) => ({ ...prev, ...patch }));
    const brandLabelClick = (r) => (r.brand_id ? () => applyFilter({ brand_ids: [r.brand_id] }) : null);
    const categoryLabelClick = (r) => {
        const id = Number(r.key);
        return Number.isFinite(id) && id > 0 ? () => applyFilter({ category_ids: [id] }) : null;
    };
    const contractorLabelClick = (r) => (r.company_id ? () => applyFilter({ company_ids: [r.company_id] }) : null);
    const partnerLabelClick = (r) => (r.partner_id ? () => applyFilter({ partner_ids: [r.partner_id] }) : null);
    const managerLabelClick = (r) => (r.manager_id ? () => applyFilter({ manager_ids: [r.manager_id] }) : null);
    // Клик по товару ставит его единственным фильтром по товарам (как бренд/категория),
    // а не уводит на карточку.
    const productLabelClick = (r) => (r.product_id
        ? () => handleProductsChange([{ id: r.product_id, name: r.label, sku: r.sku }])
        : null);

    // Теги активного фильтра у заголовка каждой таблицы — крестик убирает
    // одно значение из соответствующего фильтра.
    const removeId = (key, id) => applyFilter({
        [key]: (filters[key] || []).filter((x) => String(x) !== String(id)),
    });
    const idTags = (ids, opts, key) => (ids || []).map((id) => ({
        key: `${key}:${id}`,
        label: (opts || []).find((o) => String(o.id) === String(id))?.name || `#${id}`,
        onRemove: () => removeId(key, id),
    }));
    const flatCategoryNames = (() => {
        const map = {};
        const walk = (nodes) => (nodes || []).forEach((n) => { map[n.id] = n.name; walk(n.children); });
        walk(filterOptions?.categories);
        return map;
    })();
    const managerTags = idTags(filters.manager_ids, filterOptions?.managers, 'manager_ids');
    const partnerTags = idTags(filters.partner_ids, filterOptions?.partners, 'partner_ids');
    const brandTags = idTags(filters.brand_ids, filterOptions?.brands, 'brand_ids');
    const contractorTags = idTags(filters.company_ids, filterOptions?.companies, 'company_ids');
    const categoryTags = (filters.category_ids || []).map((id) => ({
        key: `category:${id}`,
        label: flatCategoryNames[id] || `#${id}`,
        onRemove: () => removeId('category_ids', id),
    }));
    const productTags = products.map((p) => ({
        key: `product:${p.id}`,
        label: p.name || p.label || `#${p.id}`,
        onRemove: () => handleProductsChange(products.filter((x) => x.id !== p.id)),
    }));

    const currency = data?.currency ?? { code: 'RUB', symbol: '₽' };
    const comparison = data?.comparison ?? null;

    const managerGroup = seesAll ? [{
        value: 'managers',
        title: 'По менеджерам',
        count: data?.by_manager?.length ?? 0,
        section: (
            <BreakdownSection
                title="Менеджер"
                rows={data?.by_manager ?? []}
                currency={currency}
                extraColumns={[
                    { key: 'clients', label: 'Клиентов', render: (r) => r.clients_count },
                    { key: 'shipments', label: 'Поставок', render: (r) => r.shipments_count },
                    { key: 'contractors', label: 'Контрагентов', render: (r) => r.contractors_count },
                ]}
                getLabelClick={managerLabelClick}
                selectedTags={managerTags}
            />
        ),
    }] : [];

    const groups = [
        ...managerGroup,
        {
            value: 'brands',
            title: 'По брендам',
            count: data?.by_brand?.length ?? 0,
            section: (
                <BreakdownSection
                    title="Бренд"
                    rows={data?.by_brand ?? []}
                    currency={currency}
                    extraColumns={[
                        { key: 'shipments', label: 'Поставок', render: (r) => r.shipments_count },
                        { key: 'contractors', label: 'Контрагентов', render: (r) => r.contractors_count },
                    ]}
                    getLabelClick={brandLabelClick}
                    selectedTags={brandTags}
                />
            ),
        },
        {
            value: 'categories',
            title: 'По категориям',
            count: data?.by_category?.length ?? 0,
            section: (
                <BreakdownSection
                    title="Категория"
                    rows={data?.by_category ?? []}
                    currency={currency}
                    extraColumns={[
                        { key: 'shipments', label: 'Поставок', render: (r) => r.shipments_count },
                        { key: 'contractors', label: 'Контрагентов', render: (r) => r.contractors_count },
                    ]}
                    getLabelClick={categoryLabelClick}
                    selectedTags={categoryTags}
                />
            ),
        },
        {
            value: 'partners',
            title: 'По партнёрам',
            count: data?.by_partner?.length ?? 0,
            section: (
                <BreakdownSection
                    title="Партнёр"
                    rows={data?.by_partner ?? []}
                    currency={currency}
                    extraColumns={[
                        { key: 'shipments', label: 'Поставок', render: (r) => r.shipments_count },
                        { key: 'contractors', label: 'Контрагентов', render: (r) => r.contractors_count },
                    ]}
                    getLabelClick={partnerLabelClick}
                    selectedTags={partnerTags}
                />
            ),
        },
        {
            value: 'contractors',
            title: 'По контрагентам',
            count: data?.by_contractor?.length ?? 0,
            section: (
                <BreakdownSection
                    title="Контрагент"
                    rows={data?.by_contractor ?? []}
                    currency={currency}
                    extraColumns={[
                        { key: 'shipments', label: 'Поставок', render: (r) => r.shipments_count },
                    ]}
                    getLabelClick={contractorLabelClick}
                    selectedTags={contractorTags}
                />
            ),
        },
        {
            value: 'products',
            title: 'По товарам',
            count: data?.by_product?.length ?? 0,
            section: (
                <BreakdownSection
                    title="Товар"
                    rows={data?.by_product ?? []}
                    currency={currency}
                    extraColumns={[
                        { key: 'sku', label: 'Артикул', render: (r) => r.sku || '—' },
                        { key: 'contractors', label: 'Контрагентов', render: (r) => r.contractors_count },
                    ]}
                    getLabelClick={productLabelClick}
                    selectedTags={productTags}
                />
            ),
        },
    ];

    const defaultOpen = groups.map((g) => g.value);

    return (
        <>
            <Head title="Отчёты продаж — CRM" />

            <Flex direction={{ base: 'column', md: 'row' }} align="flex-start" gap={4}>
                {panelOpen ? (
                    <Box
                        as="aside"
                        flexShrink={0}
                        w={{ base: '100%', md: '300px' }}
                        position={{ md: 'sticky' }}
                        top={{ md: '72px' }}
                        h={{ md: 'calc(100dvh - 88px)' }}
                        zIndex={3}
                    >
                        <FiltersBar
                            filters={filters}
                            filterOptions={filterOptions}
                            onChange={setFilters}
                            onReset={handleReset}
                            onExport={handleExport}
                            loading={loading}
                            seesAll={seesAll}
                            products={products}
                            onProductsChange={handleProductsChange}
                            compareMode={compareMode}
                            compareOffset={compareOffset}
                            onCompareModeChange={setCompareMode}
                            onCompareOffsetChange={setCompareOffset}
                            onCollapse={() => setPanelOpen(false)}
                        />
                    </Box>
                ) : (
                    <Box flexShrink={0} position={{ md: 'sticky' }} top={{ md: '72px' }} zIndex={3}>
                        <IconButton
                            variant="outline"
                            size="sm"
                            onClick={() => setPanelOpen(true)}
                            aria-label="Показать фильтры"
                            title="Показать фильтры"
                        >
                            <LuFilter />
                            <LuChevronRight />
                        </IconButton>
                    </Box>
                )}

                <Box flex="1" minW="0" w={{ base: '100%', md: 'auto' }}>
                    <PageHeader
                        title="Отчёты продаж"
                        description={seesAll ? 'Продажи всего отдела по данным отгрузок 1С' : 'Продажи ваших клиентов по данным отгрузок 1С'}
                    />

                    <PresetsBar
                        presets={presets}
                        onApply={handleApplyPreset}
                        onDelete={handleDeletePreset}
                        onSave={handleSavePreset}
                        loading={loading}
                    />

                    <VStack align="stretch" gap={5}>
                        {loading && (
                            <HStack gap={2} color="fg.muted">
                                <Spinner size="sm" />
                                <Text fontSize="sm">Обновление…</Text>
                            </HStack>
                        )}

                        <Tabs.Root defaultValue="figures" variant="enclosed" size="md">
                    <Tabs.List>
                        <Tabs.Trigger value="figures">
                            <LuChartLine /> Цифры и графики
                        </Tabs.Trigger>
                        <Tabs.Trigger value="insights">
                            <LuLightbulb /> Подсказки
                        </Tabs.Trigger>
                        <Tabs.Trigger value="abc-xyz">
                            <LuLayoutGrid /> ABC/XYZ
                        </Tabs.Trigger>
                    </Tabs.List>

                    <Tabs.Content value="figures" px={0} pt={4}>
                        <VStack align="stretch" gap={5}>
                            <KpiGrid metrics={data?.metrics ?? {}} currency={currency} deltas={comparison?.deltas ?? null} />

                            {comparison && (
                                <Text fontSize="xs" color="fg.muted">
                                    Сравнение: {compareLabel(comparison.mode, comparison.offset)} ({comparison.period.date_from} — {comparison.period.date_to})
                                </Text>
                            )}

                            <TrendChart
                                timeSeries={data?.time_series}
                                currency={currency}
                                previousSeries={comparison?.time_series ?? null}
                            />

                            <Accordion.Root collapsible multiple defaultValue={defaultOpen}>
                                {groups.map((group) => (
                                    <Accordion.Item key={group.value} value={group.value}>
                                        <Accordion.ItemTrigger py={3}>
                                            <Span flex="1" textAlign="left" fontWeight="600" fontSize="md">
                                                {group.title}
                                            </Span>
                                            <Badge size="sm" colorPalette="gray" mr={2}>{group.count}</Badge>
                                            <Accordion.ItemIndicator />
                                        </Accordion.ItemTrigger>
                                        <Accordion.ItemContent>
                                            <Accordion.ItemBody pt={2} pb={4}>
                                                {group.section}
                                            </Accordion.ItemBody>
                                        </Accordion.ItemContent>
                                    </Accordion.Item>
                                ))}
                            </Accordion.Root>
                        </VStack>
                    </Tabs.Content>

                    <Tabs.Content value="insights" px={0} pt={4}>
                        <InsightsPanel insights={data?.insights ?? {}} currency={currency} onApplyFilter={applyFilter} />
                    </Tabs.Content>

                    <Tabs.Content value="abc-xyz" px={0} pt={4}>
                        <AbcXyzPanel filters={filters} onApplyFilter={applyFilter} endpoint="/crm/analytics/abc-xyz" />
                    </Tabs.Content>
                </Tabs.Root>

                        <Box pb={4}>
                            <Text fontSize="xs" color="fg.muted" textAlign="center">
                                Отчёт построен по реализациям (отгрузкам) из 1С. Даты — по дате документа 1С. Суммы в рублях. Возвраты не учитываются.
                            </Text>
                        </Box>
                    </VStack>
                </Box>
            </Flex>
        </>
    );
}

CrmAnalyticsIndex.layout = (page) => <CrmLayout>{page}</CrmLayout>;
