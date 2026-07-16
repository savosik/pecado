import { useEffect, useRef, useState, useCallback } from 'react';
import { Head, usePage } from '@inertiajs/react';
import {
    Box, VStack, Text, Spinner, HStack, Accordion, Span, Badge, Tabs,
} from '@chakra-ui/react';
import { LuChartLine, LuLightbulb, LuLayoutGrid } from 'react-icons/lu';
import axios from 'axios';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import BreakdownSection from '@/Pages/User/Cabinet/Analytics/components/BreakdownSection';
import InsightsPanel from '@/Pages/User/Cabinet/Analytics/components/InsightsPanel';
import AbcXyzPanel from '@/Pages/User/Cabinet/Analytics/components/AbcXyzPanel';
import KpiGrid from './components/KpiGrid';
import TrendChart from './components/TrendChart';
import FiltersBar from './components/FiltersBar';

const DEFAULT_FILTERS = {
    date_from: '',
    date_to: '',
    manager_ids: [],
    company_ids: [],
    brand_ids: [],
    category_ids: [],
    product_ids: [],
    sku: '',
};

function buildParams(filters, compare) {
    const params = {};
    if (filters.date_from) params.date_from = filters.date_from;
    if (filters.date_to) params.date_to = filters.date_to;
    if (filters.manager_ids?.length) params['manager_ids'] = filters.manager_ids;
    if (filters.company_ids?.length) params['company_ids'] = filters.company_ids;
    if (filters.brand_ids?.length) params['brand_ids'] = filters.brand_ids;
    if (filters.category_ids?.length) params['category_ids'] = filters.category_ids;
    if (filters.product_ids?.length) params['product_ids'] = filters.product_ids;
    if (filters.sku) params.sku = filters.sku;
    if (compare) params.compare = 1;
    return params;
}

export default function CrmAnalyticsIndex() {
    const { initial, filterOptions, seesAll } = usePage().props;

    const [filters, setFilters] = useState({ ...DEFAULT_FILTERS, ...(initial?.filters ?? {}) });
    const [products, setProducts] = useState([]);
    const [compare, setCompare] = useState(false);
    const [data, setData] = useState(initial);
    const [loading, setLoading] = useState(false);
    const debounceRef = useRef(null);
    const isFirstRender = useRef(true);

    const reload = useCallback(async (nextFilters, nextCompare) => {
        setLoading(true);
        try {
            const res = await axios.get('/crm/analytics/data', {
                params: buildParams(nextFilters, nextCompare),
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
            reload(filters, compare);
        }, 500);
        return () => debounceRef.current && clearTimeout(debounceRef.current);
    }, [filters, compare, reload]);

    const handleReset = () => {
        setProducts([]);
        setCompare(false);
        setFilters(DEFAULT_FILTERS);
    };

    const handleProductsChange = (nextProducts) => {
        setProducts(nextProducts);
        setFilters((prev) => ({ ...prev, product_ids: nextProducts.map((p) => p.id) }));
    };

    const buildExportUrl = () => {
        const base = Object.entries(buildParams(filters, false)).flatMap(([k, v]) =>
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
    const managerLabelClick = (r) => (r.manager_id ? () => applyFilter({ manager_ids: [r.manager_id] }) : null);
    const productLabelHref = (r) => (r.slug ? `/products/${r.slug}` : null);

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
                    getLabelHref={productLabelHref}
                />
            ),
        },
    ];

    const defaultOpen = groups.map((g) => g.value);

    return (
        <>
            <Head title="Отчёты продаж — CRM" />
            <PageHeader
                title="Отчёты продаж"
                description={seesAll ? 'Продажи всего отдела по данным отгрузок 1С' : 'Продажи ваших клиентов по данным отгрузок 1С'}
            />

            <VStack align="stretch" gap={5}>
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
                    compare={compare}
                    onCompareChange={setCompare}
                />

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
                                    Сравнение с периодом {comparison.period.date_from} — {comparison.period.date_to}
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
        </>
    );
}

CrmAnalyticsIndex.layout = (page) => <CrmLayout>{page}</CrmLayout>;
