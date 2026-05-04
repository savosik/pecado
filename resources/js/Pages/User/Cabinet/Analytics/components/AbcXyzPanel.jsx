import { useEffect, useState, useMemo, useCallback } from 'react';
import {
    Box, HStack, VStack, Text, Heading, Badge, SimpleGrid, Table, Spinner,
    Button, Tabs, Input,
} from '@chakra-ui/react';
import { LuList, LuInfo, LuLayoutGrid, LuSearch } from 'react-icons/lu';
import axios from 'axios';

const fmtMoney = (v) => Number(v ?? 0).toLocaleString('ru-RU', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
});
const fmtPct = (v) => `${Number(v ?? 0).toLocaleString('ru-RU', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;

const DIMENSIONS = [
    { key: 'brand', label: 'Бренды' },
    { key: 'category', label: 'Категории' },
    { key: 'product', label: 'Товары' },
];

const CELL_COLORS = {
    AX: 'green', AY: 'green', AZ: 'orange',
    BX: 'teal', BY: 'blue', BZ: 'orange',
    CX: 'gray', CY: 'gray', CZ: 'red',
};

function MatrixGrid({ matrix, total }) {
    const cells = ['AX', 'AY', 'AZ', 'BX', 'BY', 'BZ', 'CX', 'CY', 'CZ'];

    return (
        <Box bg="bg.panel" borderRadius="xl" borderWidth="1px" borderColor="border" p={4}>
            <HStack justify="space-between" mb={3}>
                <Text fontWeight="600">Матрица 3×3 — распределение позиций</Text>
                <Text fontSize="sm" color="fg.muted">всего: {total}</Text>
            </HStack>

            <Box overflowX="auto">
                <SimpleGrid columns={4} gap={2} minW="420px">
                    <Box />
                    <Box textAlign="center" fontSize="xs" color="fg.muted" fontWeight="600">
                        X — стабильные
                    </Box>
                    <Box textAlign="center" fontSize="xs" color="fg.muted" fontWeight="600">
                        Y — переменные
                    </Box>
                    <Box textAlign="center" fontSize="xs" color="fg.muted" fontWeight="600">
                        Z — нестабильные
                    </Box>

                    {['A', 'B', 'C'].map((row) => (
                        <>
                            <Box
                                key={`label-${row}`}
                                textAlign="right"
                                fontSize="xs"
                                color="fg.muted"
                                fontWeight="600"
                                py={3}
                            >
                                {row} — {row === 'A' ? '80% оборота' : row === 'B' ? '+15%' : 'хвост'}
                            </Box>
                            {['X', 'Y', 'Z'].map((col) => {
                                const cell = `${row}${col}`;
                                const count = matrix[cell] ?? 0;
                                const color = CELL_COLORS[cell];
                                return (
                                    <Box
                                        key={cell}
                                        bg={`${color}.subtle`}
                                        color={`${color}.fg`}
                                        borderRadius="md"
                                        py={3}
                                        textAlign="center"
                                        opacity={count === 0 ? 0.55 : 1}
                                    >
                                        <Text fontSize="lg" fontWeight="700">{count}</Text>
                                        <Text fontSize="xs" opacity={0.85}>{cell}</Text>
                                    </Box>
                                );
                            })}
                        </>
                    ))}
                </SimpleGrid>
            </Box>
        </Box>
    );
}

function MethodBlock() {
    return (
        <Box bg="bg.subtle" borderRadius="md" p={4}>
            <VStack align="stretch" gap={3} fontSize="sm" color="fg.muted">
                <Box>
                    <Text fontWeight="600" color="fg" mb={1}>Период анализа</Text>
                    <Text>Берутся данные за последние 12 календарных месяцев — это даёт устойчивую картину спроса.
                        Фильтры на странице (период, контрагент, бренд) на этот раздел не влияют.</Text>
                </Box>
                <Box>
                    <Text fontWeight="600" color="fg" mb={1}>ABC — по доле в обороте</Text>
                    <Text>1. Сортируем объекты по сумме отгрузок за год.</Text>
                    <Text>2. A — верхняя часть, дающая в сумме до 80% оборота.</Text>
                    <Text>3. B — следующие, доводящие накопленную долю до 95%.</Text>
                    <Text>4. C — оставшийся «длинный хвост» (≤ 5%).</Text>
                </Box>
                <Box>
                    <Text fontWeight="600" color="fg" mb={1}>XYZ — по стабильности спроса</Text>
                    <Text>Для каждого объекта берутся помесячные суммы (12 точек) и считается коэффициент вариации
                        (CV = стандартное отклонение / среднее).</Text>
                    <Text>X — CV ≤ 25% (стабильно из месяца в месяц).</Text>
                    <Text>Y — CV ≤ 50% (с заметными колебаниями).</Text>
                    <Text>Z — CV &gt; 50% или активность меньше 3 месяцев из 12 (непредсказуемый, эпизодический спрос).</Text>
                </Box>
                <Box>
                    <Text fontWeight="600" color="fg" mb={1}>Как пользоваться</Text>
                    <Text>AX/AY — главные позиции: их out-of-stock больно бьёт по выручке.</Text>
                    <Text>AZ — высокий оборот при нестабильности: страховой запас и анализ причин (сезон, разовые сделки).</Text>
                    <Text>BX/CX — стабильные «середняки»: можно автоматизировать пополнение.</Text>
                    <Text>CZ — кандидаты на вывод из ассортимента или замену.</Text>
                </Box>
            </VStack>
        </Box>
    );
}

const labelLinkSx = {
    cursor: 'pointer',
    textDecoration: 'underline',
    textDecorationStyle: 'dotted',
    textUnderlineOffset: '3px',
    textDecorationColor: 'var(--chakra-colors-border)',
    _hover: { textDecorationStyle: 'solid', textDecorationColor: 'currentColor' },
};

function ClickableLabel({ row, dimension, onApplyFilter }) {
    if (dimension === 'product' && row.slug) {
        return (
            <Box as="a" href={`/products/${row.slug}`} {...labelLinkSx}>
                <Text fontSize="sm" lineClamp={2}>{row.label}</Text>
            </Box>
        );
    }

    let handler = null;
    if (dimension === 'brand' && row.extra_id && onApplyFilter) {
        handler = () => onApplyFilter({ brand_ids: [row.extra_id] });
    } else if (dimension === 'category') {
        const id = Number(row.key);
        if (Number.isFinite(id) && id > 0 && onApplyFilter) {
            handler = () => onApplyFilter({ category_ids: [id] });
        }
    }

    if (!handler) {
        return <Text fontSize="sm" lineClamp={2}>{row.label}</Text>;
    }
    return (
        <Box as="button" type="button" onClick={handler} textAlign="left" {...labelLinkSx}>
            <Text fontSize="sm" lineClamp={2}>{row.label}</Text>
        </Box>
    );
}

export default function AbcXyzPanel({ filters = {}, onApplyFilter }) {
    const [dimension, setDimension] = useState('brand');
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [search, setSearch] = useState('');
    const [cellFilter, setCellFilter] = useState(null);

    // Период намеренно не передаётся — ABC/XYZ всегда считается за 12 месяцев.
    // Передаём только бренд/категория/контрагент/артикул.
    const requestParams = useMemo(() => {
        const p = { dimension };
        if (filters.company_ids?.length) p['company_ids'] = filters.company_ids;
        if (filters.brand_ids?.length) p['brand_ids'] = filters.brand_ids;
        if (filters.category_ids?.length) p['category_ids'] = filters.category_ids;
        if (filters.sku) p.sku = filters.sku;
        return p;
    }, [dimension, filters.company_ids, filters.brand_ids, filters.category_ids, filters.sku]);

    const load = useCallback(async (params) => {
        setLoading(true);
        setError(null);
        try {
            const res = await axios.get('/cabinet/analytics/abc-xyz', { params });
            setData(res.data);
            setCellFilter(null);
        } catch (e) {
            setError('Не удалось загрузить ABC/XYZ-анализ');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load(requestParams);
    }, [requestParams, load]);

    const activeFiltersHint = useMemo(() => {
        const parts = [];
        if (filters.company_ids?.length) parts.push(`контрагентов: ${filters.company_ids.length}`);
        if (filters.brand_ids?.length) parts.push(`брендов: ${filters.brand_ids.length}`);
        if (filters.category_ids?.length) parts.push(`категорий: ${filters.category_ids.length}`);
        if (filters.sku) parts.push(`артикул: ${filters.sku}`);
        return parts.join(', ');
    }, [filters.company_ids, filters.brand_ids, filters.category_ids, filters.sku]);

    const rows = data?.rows ?? [];
    const matrix = data?.matrix ?? {};
    const symbol = data?.currency?.symbol ?? '₽';

    const visibleRows = useMemo(() => {
        let r = rows;
        if (cellFilter) r = r.filter((x) => x.cell === cellFilter);
        if (search.trim()) {
            const q = search.toLowerCase().trim();
            r = r.filter((x) =>
                String(x.label || '').toLowerCase().includes(q)
                || String(x.sku || '').toLowerCase().includes(q)
            );
        }
        return r;
    }, [rows, cellFilter, search]);

    return (
        <VStack align="stretch" gap={4}>
            <Box>
                <HStack gap={2} mb={1}>
                    <Heading size="lg">ABC / XYZ-анализ</Heading>
                    <Badge colorPalette="blue" size="sm">за последние 12 месяцев</Badge>
                </HStack>
                <Text color="fg.muted" fontSize="sm">
                    Классификация по двум осям: ABC — по доле в обороте, XYZ — по стабильности спроса.
                    Помогает понять, какие позиции — фундамент бизнеса, а какие — балласт.
                </Text>
                <Text color="fg.muted" fontSize="xs" mt={1}>
                    Период из фильтров не используется (расчёт всегда за 12 месяцев), остальные фильтры применяются{activeFiltersHint ? ` — ${activeFiltersHint}` : ''}.
                </Text>
            </Box>

            <HStack gap={2} wrap="wrap">
                {DIMENSIONS.map((d) => (
                    <Button
                        key={d.key}
                        size="sm"
                        variant={dimension === d.key ? 'solid' : 'outline'}
                        colorPalette={dimension === d.key ? 'pecado' : undefined}
                        onClick={() => setDimension(d.key)}
                        disabled={loading}
                    >
                        {d.label}
                    </Button>
                ))}
                {loading && (
                    <HStack gap={1} color="fg.muted">
                        <Spinner size="xs" /> <Text fontSize="sm">загрузка…</Text>
                    </HStack>
                )}
            </HStack>

            {error && (
                <Box p={3} bg="red.subtle" color="red.fg" borderRadius="md">{error}</Box>
            )}

            {data && (
                <>
                    <Tabs.Root defaultValue="overview" variant="line" size="sm">
                        <Tabs.List>
                            <Tabs.Trigger value="overview">
                                <LuLayoutGrid /> Матрица
                            </Tabs.Trigger>
                            <Tabs.Trigger value="table">
                                <LuList /> Таблица ({rows.length})
                            </Tabs.Trigger>
                            <Tabs.Trigger value="method">
                                <LuInfo /> Как считается
                            </Tabs.Trigger>
                        </Tabs.List>

                        <Tabs.Content value="overview" px={0} pt={4}>
                            <VStack align="stretch" gap={3}>
                                <MatrixGrid matrix={matrix} total={rows.length} />
                                <Text fontSize="xs" color="fg.muted">
                                    Кликни ячейку в таблице (вкладка «Таблица») или используй фильтр, чтобы посмотреть, кто куда попал.
                                </Text>
                            </VStack>
                        </Tabs.Content>

                        <Tabs.Content value="table" px={0} pt={4}>
                            <VStack align="stretch" gap={3}>
                                <HStack gap={2} wrap="wrap">
                                    <Box flex="1" minW="200px">
                                        <HStack gap={1}>
                                            <LuSearch size={14} />
                                            <Input
                                                size="sm"
                                                placeholder="Поиск по названию или артикулу"
                                                value={search}
                                                onChange={(e) => setSearch(e.target.value)}
                                                bg="bg"
                                            />
                                        </HStack>
                                    </Box>
                                    <HStack gap={1} wrap="wrap">
                                        {['AX', 'AY', 'AZ', 'BX', 'BY', 'BZ', 'CX', 'CY', 'CZ'].map((c) => (
                                            <Button
                                                key={c}
                                                size="xs"
                                                variant={cellFilter === c ? 'solid' : 'outline'}
                                                colorPalette={CELL_COLORS[c]}
                                                onClick={() => setCellFilter(cellFilter === c ? null : c)}
                                            >
                                                {c} ({matrix[c] ?? 0})
                                            </Button>
                                        ))}
                                        {cellFilter && (
                                            <Button size="xs" variant="ghost" onClick={() => setCellFilter(null)}>
                                                сбросить
                                            </Button>
                                        )}
                                    </HStack>
                                </HStack>

                                <Box bg="bg.panel" borderRadius="xl" borderWidth="1px" borderColor="border" overflowX="auto">
                                    <Table.Root size="sm" variant="line">
                                        <Table.Header>
                                            <Table.Row>
                                                <Table.ColumnHeader>Объект</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="center">ABC</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="center">XYZ</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="right">Доля</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="right">CV</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="right">Активных мес.</Table.ColumnHeader>
                                                <Table.ColumnHeader textAlign="right">Сумма за год</Table.ColumnHeader>
                                                <Table.ColumnHeader>Стратегия</Table.ColumnHeader>
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {visibleRows.length === 0 ? (
                                                <Table.Row>
                                                    <Table.Cell colSpan={8} textAlign="center" py={6} color="fg.muted">
                                                        Ничего не найдено
                                                    </Table.Cell>
                                                </Table.Row>
                                            ) : visibleRows.map((r) => {
                                                const color = CELL_COLORS[r.cell];
                                                return (
                                                    <Table.Row key={r.key}>
                                                        <Table.Cell>
                                                            <VStack align="start" gap={0}>
                                                                <ClickableLabel
                                                                    row={r}
                                                                    dimension={dimension}
                                                                    onApplyFilter={onApplyFilter}
                                                                />
                                                                {r.sku && (
                                                                    <Text fontSize="xs" color="fg.muted">арт. {r.sku}</Text>
                                                                )}
                                                            </VStack>
                                                        </Table.Cell>
                                                        <Table.Cell textAlign="center">
                                                            <Badge size="sm" colorPalette={r.abc === 'A' ? 'green' : r.abc === 'B' ? 'blue' : 'gray'}>
                                                                {r.abc}
                                                            </Badge>
                                                        </Table.Cell>
                                                        <Table.Cell textAlign="center">
                                                            <Badge size="sm" colorPalette={r.xyz === 'X' ? 'green' : r.xyz === 'Y' ? 'orange' : 'red'}>
                                                                {r.xyz}
                                                            </Badge>
                                                        </Table.Cell>
                                                        <Table.Cell textAlign="right">{fmtPct(r.share_pct)}</Table.Cell>
                                                        <Table.Cell textAlign="right">{fmtPct(r.cv_pct)}</Table.Cell>
                                                        <Table.Cell textAlign="right">{r.active_months}/12</Table.Cell>
                                                        <Table.Cell textAlign="right" fontWeight="600">
                                                            {fmtMoney(r.total_amount)} {symbol}
                                                        </Table.Cell>
                                                        <Table.Cell>
                                                            <Text fontSize="xs" color={`${color}.fg`}>{r.strategy}</Text>
                                                        </Table.Cell>
                                                    </Table.Row>
                                                );
                                            })}
                                        </Table.Body>
                                    </Table.Root>
                                </Box>
                            </VStack>
                        </Tabs.Content>

                        <Tabs.Content value="method" px={0} pt={4}>
                            <MethodBlock />
                        </Tabs.Content>
                    </Tabs.Root>
                </>
            )}
        </VStack>
    );
}
