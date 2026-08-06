import { useEffect, useRef, useState, useCallback } from 'react';
import { Head } from '@inertiajs/react';
import { Box, VStack, Heading, Text, Spinner, HStack, Accordion, Span, Badge, Tabs } from '@chakra-ui/react';
import { LuChartLine, LuLightbulb, LuLayoutGrid } from 'react-icons/lu';
import axios from 'axios';
import CabinetLayout from '../CabinetLayout';
import KpiGrid from './components/KpiGrid';
import InsightsPanel from './components/InsightsPanel';
import AbcXyzPanel from './components/AbcXyzPanel';
import TrendChart from './components/TrendChart';
import BreakdownSection from './components/BreakdownSection';
import FiltersBar from './components/FiltersBar';

const DEFAULT_FILTERS = {
    date_from: '',
    date_to: '',
    company_ids: [],
    brand_ids: [],
    category_ids: [],
    sku: '',
};

function buildParams(filters) {
    const params = {};
    if (filters.date_from) params.date_from = filters.date_from;
    if (filters.date_to) params.date_to = filters.date_to;
    if (filters.company_ids?.length) params['company_ids'] = filters.company_ids;
    if (filters.brand_ids?.length) params['brand_ids'] = filters.brand_ids;
    if (filters.category_ids?.length) params['category_ids'] = filters.category_ids;
    if (filters.sku) params.sku = filters.sku;
    return params;
}

export default function AnalyticsIndex({ initial, filterOptions }) {
    const [filters, setFilters] = useState({
        ...DEFAULT_FILTERS,
        ...(initial?.filters ?? {}),
    });
    const [data, setData] = useState(initial);
    const [loading, setLoading] = useState(false);
    const debounceRef = useRef(null);
    const isFirstRender = useRef(true);

    const reload = useCallback(async (nextFilters) => {
        setLoading(true);
        try {
            const res = await axios.get('/cabinet/analytics/data', {
                params: buildParams(nextFilters),
            });
            setData(res.data);
        } catch (e) {
            console.error('Не удалось загрузить аналитику', e);
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
            reload(filters);
        }, 300);
        return () => debounceRef.current && clearTimeout(debounceRef.current);
    }, [filters, reload]);

    const handleReset = () => setFilters(DEFAULT_FILTERS);

    const buildExportUrl = (section = null) => {
        const base = Object.entries(buildParams(filters)).flatMap(([k, v]) =>
            Array.isArray(v) ? v.map((x) => [`${k}[]`, x]) : [[k, v]]
        );
        if (section) base.push(['section', section]);
        return '/cabinet/analytics/export?' + new URLSearchParams(base).toString();
    };

    const handleExport = () => window.open(buildExportUrl(), '_blank');
    const exportSection = (section) => () => window.open(buildExportUrl(section), '_blank');

    const applyFilter = (patch) => setFilters((prev) => ({ ...prev, ...patch }));
    const brandLabelClick = (r) => r.brand_id ? () => applyFilter({ brand_ids: [r.brand_id] }) : null;
    const categoryLabelClick = (r) => {
        const id = Number(r.key);
        return Number.isFinite(id) && id > 0
            ? () => applyFilter({ category_ids: [id] })
            : null;
    };
    const contractorLabelClick = (r) => r.company_id
        ? () => applyFilter({ company_ids: [r.company_id] })
        : null;
    const productLabelHref = (r) => r.slug ? `/products/${r.slug}` : null;

    const currency = data?.currency ?? { code: 'RUB', symbol: '₽' };

    // Таблицы разрезов ограничены потолком UI; признак обрезки идёт с бэкенда,
    // чтобы подсказать про полную выгрузку в XLSX.
    const truncationOf = (key) => data?.truncation?.[key] ?? null;
    const countOf = (key, rows) => {
        const total = rows?.length ?? 0;
        return truncationOf(key)?.truncated ? `${total}+` : total;
    };

    return (
        <CabinetLayout
            title="Аналитика"
            actions={
                loading ? (
                    <HStack gap={2} color="fg.muted">
                        <Spinner size="sm" />
                        <Text fontSize="sm">Обновление…</Text>
                    </HStack>
                ) : null
            }
        >
            <Head title="Аналитика — Pecado" />

            <VStack align="stretch" gap={5}>
                <FiltersBar
                    filters={filters}
                    filterOptions={filterOptions}
                    onChange={setFilters}
                    onReset={handleReset}
                    onExport={handleExport}
                    loading={loading}
                />

                <Tabs.Root defaultValue="figures" variant="enclosed" size="md">
                    <Tabs.List>
                        <Tabs.Trigger value="figures">
                            <LuChartLine /> Цифры и графики
                        </Tabs.Trigger>
                        <Tabs.Trigger value="insights">
                            <LuLightbulb /> Подсказки
                            {(data?.insights?.pareto?.length || data?.insights?.rising?.length
                                || data?.insights?.falling?.length || data?.insights?.reorder?.length) ? (
                                <Badge size="xs" colorPalette="purple" ml={1}>есть</Badge>
                            ) : null}
                        </Tabs.Trigger>
                        <Tabs.Trigger value="abc-xyz">
                            <LuLayoutGrid /> ABC/XYZ
                        </Tabs.Trigger>
                    </Tabs.List>

                    <Tabs.Content value="figures" px={0} pt={4}>
                        <VStack align="stretch" gap={5}>
                            <KpiGrid metrics={data?.metrics ?? {}} currency={currency} />

                            <TrendChart timeSeries={data?.time_series} currency={currency} />

                            <Accordion.Root collapsible multiple defaultValue={['brands', 'categories', 'contractors', 'products']}>
                    {[
                        {
                            value: 'brands',
                            title: 'По брендам',
                            count: countOf('by_brand', data?.by_brand),
                            section: (
                                <BreakdownSection
                                    title="Бренд"
                                    rows={data?.by_brand ?? []}
                                    truncation={truncationOf('by_brand')}
                                    currency={currency}
                                    extraColumns={[
                                        { key: 'shipments', label: 'Поставок', render: (r) => r.shipments_count },
                                        { key: 'contractors', label: 'Контрагентов', render: (r) => r.contractors_count },
                                    ]}
                                    onExport={exportSection('brands')}
                                    getLabelClick={brandLabelClick}
                                />
                            ),
                        },
                        {
                            value: 'categories',
                            title: 'По категориям',
                            count: countOf('by_category', data?.by_category),
                            section: (
                                <BreakdownSection
                                    title="Категория"
                                    rows={data?.by_category ?? []}
                                    truncation={truncationOf('by_category')}
                                    currency={currency}
                                    extraColumns={[
                                        { key: 'shipments', label: 'Поставок', render: (r) => r.shipments_count },
                                        { key: 'contractors', label: 'Контрагентов', render: (r) => r.contractors_count },
                                    ]}
                                    onExport={exportSection('categories')}
                                    getLabelClick={categoryLabelClick}
                                />
                            ),
                        },
                        {
                            value: 'contractors',
                            title: 'По контрагентам',
                            count: countOf('by_contractor', data?.by_contractor),
                            section: (
                                <BreakdownSection
                                    title="Контрагент"
                                    rows={data?.by_contractor ?? []}
                                    truncation={truncationOf('by_contractor')}
                                    currency={currency}
                                    extraColumns={[
                                        { key: 'shipments', label: 'Поставок', render: (r) => r.shipments_count },
                                    ]}
                                    getLabelClick={contractorLabelClick}
                                    onExport={exportSection('contractors')}
                                />
                            ),
                        },
                        {
                            value: 'products',
                            title: 'По товарам',
                            count: countOf('by_product', data?.by_product),
                            section: (
                                <BreakdownSection
                                    title="Товар"
                                    rows={data?.by_product ?? []}
                                    truncation={truncationOf('by_product')}
                                    currency={currency}
                                    extraColumns={[
                                        { key: 'sku', label: 'Артикул', render: (r) => r.sku || '—' },
                                        { key: 'contractors', label: 'Контрагентов', render: (r) => r.contractors_count },
                                    ]}
                                    onExport={exportSection('products')}
                                    getLabelHref={productLabelHref}
                                />
                            ),
                        },
                    ].map((group) => (
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
                        <InsightsPanel
                            insights={data?.insights ?? {}}
                            currency={currency}
                            onApplyFilter={applyFilter}
                        />
                    </Tabs.Content>

                    <Tabs.Content value="abc-xyz" px={0} pt={4}>
                        <AbcXyzPanel
                            filters={filters}
                            onApplyFilter={applyFilter}
                        />
                    </Tabs.Content>
                </Tabs.Root>

                <Box pb={4}>
                    <Text fontSize="xs" color="fg.muted" textAlign="center">
                        Аналитика построена по реализациям (отгрузкам) из 1С. Возвраты в подсчёты не входят — см. раздел «Возвраты».
                    </Text>
                </Box>
            </VStack>
        </CabinetLayout>
    );
}
