import { useEffect, useMemo, useRef, useState, useCallback } from 'react';
import {
    Box, Flex, HStack, VStack, Text, Input, Button, Table, Spinner, Badge,
    Popover, Portal, Wrap, WrapItem, SegmentGroup,
} from '@chakra-ui/react';
import {
    LuChevronDown, LuSearch, LuDownload, LuTarget, LuPlus, LuX,
} from 'react-icons/lu';
import axios from 'axios';
import { Checkbox } from '@/components/ui/checkbox';
import { ProductSelector } from '@/Admin/Components/ProductSelector';

const DIMENSIONS = [
    { value: 'brand', label: 'Бренд' },
    { value: 'category', label: 'Категория' },
    { value: 'product', label: 'Товар' },
];

const WINDOWS = [
    { value: 'all', label: 'За всё время' },
    { value: 'months', label: 'За последние N мес.' },
    { value: 'period', label: 'За выбранный период' },
];

const fmtMoney = (v) => Number(v ?? 0).toLocaleString('ru-RU', {
    minimumFractionDigits: 2, maximumFractionDigits: 2,
});
const fmtInt = (v) => Number(v ?? 0).toLocaleString('ru-RU');
const fmtDate = (s) => (s ? new Date(s).toLocaleDateString('ru-RU') : '—');

// Плоский список категорий с отступами из дерева.
function flattenCategories(nodes, level = 0, acc = []) {
    (nodes || []).forEach((n) => {
        acc.push({ id: n.id, name: `${'  '.repeat(level)}${n.name}` });
        flattenCategories(n.children, level + 1, acc);
    });
    return acc;
}

// Одиночный выбор значения с поиском (бренд/категория).
function SingleSelect({ options, value, onChange, placeholder = 'Выберите…' }) {
    const [query, setQuery] = useState('');
    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return options;
        return options.filter((o) => String(o.name ?? '').toLowerCase().includes(q));
    }, [options, query]);
    const current = options.find((o) => String(o.id) === String(value));

    return (
        <Popover.Root positioning={{ sameWidth: true, placement: 'bottom-start' }}>
            <Popover.Trigger asChild>
                <Button variant="outline" size="sm" justifyContent="space-between" fontWeight="500" bg="bg" w="100%">
                    <Text lineClamp={1}>{current?.name?.trim() || placeholder}</Text>
                    <LuChevronDown />
                </Button>
            </Popover.Trigger>
            <Portal>
                <Popover.Positioner>
                    <Popover.Content>
                        <Popover.Body p={2}>
                            {options.length === 0 ? (
                                <Text fontSize="sm" color="fg.muted" p={2}>Нет вариантов</Text>
                            ) : (
                                <VStack align="stretch" gap={2}>
                                    <HStack gap={2} px={1}>
                                        <LuSearch size={14} />
                                        <Input size="xs" variant="flushed" placeholder="Поиск…" value={query} onChange={(e) => setQuery(e.target.value)} />
                                    </HStack>
                                    <Box maxH="280px" overflowY="auto">
                                        <VStack align="stretch" gap={0}>
                                            {filtered.map((opt) => (
                                                <Popover.CloseTrigger asChild key={opt.id}>
                                                    <Button
                                                        size="sm"
                                                        variant={String(opt.id) === String(value) ? 'subtle' : 'ghost'}
                                                        justifyContent="flex-start"
                                                        fontWeight="400"
                                                        onClick={() => onChange(opt.id)}
                                                        whiteSpace="pre"
                                                    >
                                                        <Text fontSize="sm" lineClamp={1}>{opt.name}</Text>
                                                    </Button>
                                                </Popover.CloseTrigger>
                                            ))}
                                        </VStack>
                                    </Box>
                                </VStack>
                            )}
                        </Popover.Body>
                    </Popover.Content>
                </Popover.Positioner>
            </Portal>
        </Popover.Root>
    );
}

// Выбор измерения + значения (используется в условиях «не покупали» / «при этом покупали»).
function DimensionValuePicker({ dimension, value, onDimensionChange, onValueChange, brands, categories }) {
    const flatCats = useMemo(() => flattenCategories(categories), [categories]);
    return (
        <Flex gap={2} wrap="wrap" align="center">
            <SegmentGroup.Root
                size="xs"
                value={dimension}
                onValueChange={(e) => { onDimensionChange(e.value); onValueChange(null); }}
            >
                <SegmentGroup.Indicator />
                {DIMENSIONS.map((d) => (
                    <SegmentGroup.Item key={d.value} value={d.value}>
                        <SegmentGroup.ItemText>{d.label}</SegmentGroup.ItemText>
                        <SegmentGroup.ItemHiddenInput />
                    </SegmentGroup.Item>
                ))}
            </SegmentGroup.Root>
            <Box flex="1" minW="220px">
                {dimension === 'brand' && (
                    <SingleSelect options={brands} value={value} onChange={onValueChange} placeholder="Выберите бренд" />
                )}
                {dimension === 'category' && (
                    <SingleSelect options={flatCats} value={value} onChange={onValueChange} placeholder="Выберите категорию" />
                )}
                {dimension === 'product' && (
                    <ProductSelector
                        mode="single"
                        value={value ? { id: value.id, name: value.name } : null}
                        onChange={(p) => onValueChange(p ? { id: p.id, name: p.name } : null)}
                        searchRoute="crm.products.search"
                    />
                )}
            </Box>
        </Flex>
    );
}

// Из состояния измерения/значения получаем числовой id для API.
function valueId(dimension, value) {
    if (value == null) return 0;
    if (dimension === 'product') return Number(value.id || 0);
    return Number(value || 0);
}

// Клик по наименованию добавляет субъект в общий фильтр отчёта — единый паттерн
// со всеми разбивками (бренд/категория/контрагент и т.п.).
const labelLinkSx = {
    cursor: 'pointer',
    textAlign: 'left',
    textDecoration: 'underline',
    textDecorationStyle: 'dotted',
    textUnderlineOffset: '3px',
    textDecorationColor: 'var(--chakra-colors-border)',
    _hover: { textDecorationStyle: 'solid', textDecorationColor: 'currentColor' },
};

export default function GapPanel({ filterOptions, seesAll, period, onApplyFilter }) {
    const brands = filterOptions?.brands || [];
    const categories = filterOptions?.categories || [];

    const [subject, setSubject] = useState('partner');
    const [excludeDimension, setExcludeDimension] = useState('brand');
    const [excludeValue, setExcludeValue] = useState(null);
    const [excludeWindow, setExcludeWindow] = useState('all');
    const [excludeMonths, setExcludeMonths] = useState(6);
    const [includeEnabled, setIncludeEnabled] = useState(false);
    const [includeDimension, setIncludeDimension] = useState('category');
    const [includeValue, setIncludeValue] = useState(null);
    const [includeDormant, setIncludeDormant] = useState(false);

    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);
    const debounceRef = useRef(null);

    const excludeId = valueId(excludeDimension, excludeValue);
    const includeId = includeEnabled ? valueId(includeDimension, includeValue) : 0;

    const buildParams = useCallback(() => {
        const params = {
            date_from: period?.date_from || '',
            date_to: period?.date_to || '',
            subject,
            exclude_dimension: excludeDimension,
            exclude_value: excludeId,
            exclude_window: excludeWindow,
            exclude_months: excludeMonths,
            include_dormant: includeDormant ? 1 : 0,
        };
        if (includeEnabled && includeId > 0) {
            params.include_dimension = includeDimension;
            params.include_value = includeId;
        }
        return params;
    }, [period, subject, excludeDimension, excludeId, excludeWindow, excludeMonths,
        includeDormant, includeEnabled, includeDimension, includeId]);

    useEffect(() => {
        if (excludeId <= 0) { setData(null); return undefined; }
        if (debounceRef.current) clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(async () => {
            setLoading(true);
            try {
                const res = await axios.get('/crm/analytics/gap', { params: buildParams() });
                setData(res.data);
            } catch (e) {
                console.error('Не удалось загрузить gap-анализ', e);
            } finally {
                setLoading(false);
            }
        }, 400);
        return () => debounceRef.current && clearTimeout(debounceRef.current);
    }, [excludeId, buildParams]);

    const buildExportUrl = () => {
        const p = buildParams();
        const base = Object.entries(p).map(([k, v]) => [k, v]);
        return '/crm/analytics/gap/export?' + new URLSearchParams(base).toString();
    };

    const subjectLabel = subject === 'partner' ? 'Партнёр' : 'Контрагент';
    const rows = data?.rows ?? [];

    // Клик по наименованию ставит субъект фильтром отчёта (партнёр → partner_ids,
    // контрагент → company_ids). Контрагенты без company_id (только ИНН) — не кликабельны.
    const labelClick = (r) => {
        if (!onApplyFilter || !r.id) return null;
        return () => onApplyFilter(subject === 'partner' ? { partner_ids: [r.id] } : { company_ids: [r.id] });
    };

    return (
        <VStack align="stretch" gap={4}>
            <Box bg="bg.panel" borderRadius="xl" borderWidth="1px" borderColor="border" p={4}>
                <VStack align="stretch" gap={4}>
                    <HStack gap={2}>
                        <LuTarget size={18} />
                        <Text fontWeight="600">Кто ещё не покупает</Text>
                        <Text fontSize="sm" color="fg.muted">— поиск возможностей для кросс-продаж</Text>
                    </HStack>

                    <Flex gap={2} align="center" wrap="wrap">
                        <Text fontSize="sm" color="fg.muted" minW="90px">Показать</Text>
                        <SegmentGroup.Root size="sm" value={subject} onValueChange={(e) => setSubject(e.value)}>
                            <SegmentGroup.Indicator />
                            <SegmentGroup.Item value="partner">
                                <SegmentGroup.ItemText>Партнёров</SegmentGroup.ItemText>
                                <SegmentGroup.ItemHiddenInput />
                            </SegmentGroup.Item>
                            <SegmentGroup.Item value="contractor">
                                <SegmentGroup.ItemText>Контрагентов</SegmentGroup.ItemText>
                                <SegmentGroup.ItemHiddenInput />
                            </SegmentGroup.Item>
                        </SegmentGroup.Root>
                    </Flex>

                    <VStack align="stretch" gap={2}>
                        <Text fontSize="sm" fontWeight="500">Не покупали</Text>
                        <DimensionValuePicker
                            dimension={excludeDimension}
                            value={excludeValue}
                            onDimensionChange={setExcludeDimension}
                            onValueChange={setExcludeValue}
                            brands={brands}
                            categories={categories}
                        />
                        <Flex gap={2} align="center" wrap="wrap">
                            <Text fontSize="xs" color="fg.muted">Окно проверки:</Text>
                            <Popover.Root positioning={{ placement: 'bottom-start' }}>
                                <Popover.Trigger asChild>
                                    <Button size="xs" variant="outline">
                                        {WINDOWS.find((w) => w.value === excludeWindow)?.label} <LuChevronDown />
                                    </Button>
                                </Popover.Trigger>
                                <Portal>
                                    <Popover.Positioner>
                                        <Popover.Content width="240px">
                                            <Popover.Body p={1}>
                                                <VStack align="stretch" gap={0}>
                                                    {WINDOWS.map((w) => (
                                                        <Popover.CloseTrigger asChild key={w.value}>
                                                            <Button size="sm" variant={w.value === excludeWindow ? 'subtle' : 'ghost'} justifyContent="flex-start" onClick={() => setExcludeWindow(w.value)}>
                                                                {w.label}
                                                            </Button>
                                                        </Popover.CloseTrigger>
                                                    ))}
                                                </VStack>
                                            </Popover.Body>
                                        </Popover.Content>
                                    </Popover.Positioner>
                                </Portal>
                            </Popover.Root>
                            {excludeWindow === 'months' && (
                                <Input
                                    type="number" size="xs" w="80px" min={1} max={60} value={excludeMonths}
                                    onChange={(e) => setExcludeMonths(Math.max(1, Math.min(60, parseInt(e.target.value || '6', 10))))}
                                    bg="bg"
                                />
                            )}
                        </Flex>
                    </VStack>

                    {includeEnabled ? (
                        <VStack align="stretch" gap={2} borderTopWidth="1px" borderColor="border" pt={3}>
                            <HStack justify="space-between">
                                <Text fontSize="sm" fontWeight="500">При этом покупали (за всё время)</Text>
                                <Button size="xs" variant="ghost" onClick={() => { setIncludeEnabled(false); setIncludeValue(null); }}>
                                    <LuX /> Убрать
                                </Button>
                            </HStack>
                            <DimensionValuePicker
                                dimension={includeDimension}
                                value={includeValue}
                                onDimensionChange={setIncludeDimension}
                                onValueChange={setIncludeValue}
                                brands={brands}
                                categories={categories}
                            />
                        </VStack>
                    ) : (
                        <Button size="xs" variant="ghost" alignSelf="flex-start" onClick={() => setIncludeEnabled(true)}>
                            <LuPlus /> Добавить условие «при этом покупали»
                        </Button>
                    )}

                    <Checkbox size="sm" checked={includeDormant} onCheckedChange={(e) => setIncludeDormant(!!e.checked)}>
                        <Text fontSize="sm">Включить спящих (весь закреплённый пул, а не только активных за период)</Text>
                    </Checkbox>
                </VStack>
            </Box>

            {excludeId <= 0 ? (
                <Box p={6} textAlign="center" color="fg.muted">
                    <Text>Выберите {DIMENSIONS.find((d) => d.value === excludeDimension)?.label.toLowerCase()}, чтобы увидеть, кто его ещё не покупает.</Text>
                </Box>
            ) : (
                <Box bg="bg.panel" borderRadius="xl" borderWidth="1px" borderColor="border" p={3}>
                    <HStack justify="space-between" mb={3} wrap="wrap" gap={2}>
                        <HStack gap={2} wrap="wrap">
                            {data?.summary && (
                                <>
                                    <Badge colorPalette="gray">В базе: {fmtInt(data.summary.base)}</Badge>
                                    <Badge colorPalette="green">Покупают: {fmtInt(data.summary.bought)}</Badge>
                                    <Badge colorPalette="orange">Не покупают: {fmtInt(data.summary.gap)}</Badge>
                                </>
                            )}
                            {loading && <Spinner size="sm" />}
                        </HStack>
                        <Button size="xs" variant="outline" onClick={() => window.open(buildExportUrl(), '_blank')} disabled={rows.length === 0}>
                            <LuDownload /> XLSX
                        </Button>
                    </HStack>

                    {rows.length === 0 ? (
                        <Box p={4} textAlign="center">
                            <Text color="fg.muted">{loading ? 'Загрузка…' : 'Никого не нашлось — все выбранное уже покупают.'}</Text>
                        </Box>
                    ) : (
                        <Box overflowX="auto">
                            <Table.Root size="sm" variant="line">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>{subjectLabel}</Table.ColumnHeader>
                                        {seesAll && <Table.ColumnHeader>Менеджер</Table.ColumnHeader>}
                                        <Table.ColumnHeader textAlign="right">Оборот за период, ₽</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Поставок</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Последняя покупка</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {rows.map((r) => {
                                        const click = labelClick(r);
                                        return (
                                        <Table.Row key={r.key}>
                                            <Table.Cell>
                                                {click ? (
                                                    <Box as="button" type="button" onClick={click} {...labelLinkSx}>
                                                        <Text lineClamp={2}>{r.label}</Text>
                                                    </Box>
                                                ) : (
                                                    <Text lineClamp={2}>{r.label}</Text>
                                                )}
                                            </Table.Cell>
                                            {seesAll && <Table.Cell><Text color="fg.muted" fontSize="sm">{r.manager || '—'}</Text></Table.Cell>}
                                            <Table.Cell textAlign="right" fontWeight="600">{fmtMoney(r.amount)}</Table.Cell>
                                            <Table.Cell textAlign="right">{fmtInt(r.shipments_count)}</Table.Cell>
                                            <Table.Cell textAlign="right" color="fg.muted">{fmtDate(r.last_purchase_at)}</Table.Cell>
                                        </Table.Row>
                                        );
                                    })}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                    )}
                </Box>
            )}
        </VStack>
    );
}
