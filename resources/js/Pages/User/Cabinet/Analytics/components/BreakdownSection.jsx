import { Box, HStack, Text, Table, Button, Tabs, Wrap, WrapItem, Badge } from '@chakra-ui/react';
import { LuDownload, LuTable, LuChartPie, LuX } from 'react-icons/lu';
import {
    PieChart, Pie, Cell, ResponsiveContainer, Tooltip, Legend,
} from 'recharts';
import { colorAt } from './usePalette.js';

const fmtInt = (v) => Number(v ?? 0).toLocaleString('ru-RU');
const fmtMoney = (v) => Number(v ?? 0).toLocaleString('ru-RU', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

function aggregateForPie(rows, limit = 10) {
    if (rows.length <= limit) return rows.map((r, i) => ({ ...r, fill: colorAt(i) }));
    const top = rows.slice(0, limit).map((r, i) => ({ ...r, fill: colorAt(i) }));
    const rest = rows.slice(limit);
    const restAmount = rest.reduce((acc, r) => acc + Number(r.amount || 0), 0);
    const restQty = rest.reduce((acc, r) => acc + Number(r.qty || 0), 0);
    if (restAmount > 0) {
        top.push({
            key: '__rest__',
            label: `Прочее (${rest.length})`,
            amount: restAmount,
            qty: restQty,
            fill: '#9ca3af',
        });
    }
    return top;
}

const labelLinkSx = {
    cursor: 'pointer',
    textDecoration: 'underline',
    textDecorationStyle: 'dotted',
    textUnderlineOffset: '3px',
    textDecorationColor: 'var(--chakra-colors-border)',
    _hover: { textDecorationStyle: 'solid', textDecorationColor: 'currentColor' },
};

function LabelCell({ row, getLabelHref, getLabelClick }) {
    const href = getLabelHref?.(row);
    if (href) {
        return (
            <Box as="a" href={href} {...labelLinkSx}>
                <Text lineClamp={2}>{row.label}</Text>
            </Box>
        );
    }
    const click = getLabelClick?.(row);
    if (click) {
        return (
            <Box as="button" type="button" onClick={click} textAlign="left" {...labelLinkSx}>
                <Text lineClamp={2}>{row.label}</Text>
            </Box>
        );
    }
    return <Text lineClamp={2}>{row.label}</Text>;
}

function TagsRow({ tags }) {
    if (!tags || tags.length === 0) return null;
    return (
        <Wrap gap={1.5} mb={3}>
            {tags.map((t) => (
                <WrapItem key={t.key}>
                    <Badge variant="subtle" colorPalette="gray" borderRadius="full" px={2} py={1}>
                        <HStack gap={1}>
                            <Text fontSize="xs" lineClamp={1} maxW="220px">{t.label}</Text>
                            <Box
                                as="button"
                                type="button"
                                onClick={t.onRemove}
                                display="inline-flex"
                                color="fg.muted"
                                _hover={{ color: 'fg' }}
                                aria-label={`Убрать фильтр: ${t.label}`}
                            >
                                <LuX size={12} />
                            </Box>
                        </HStack>
                    </Badge>
                </WrapItem>
            ))}
        </Wrap>
    );
}

export default function BreakdownSection({
    title,
    rows = [],
    currency,
    extraColumns = [],
    getLabelHref,
    getLabelClick,
    onExport,
    selectedTags = [],
}) {
    const symbol = currency?.symbol || '₽';
    const pieData = aggregateForPie(rows);
    const totalAmount = rows.reduce((acc, r) => acc + Number(r.amount || 0), 0);

    if (rows.length === 0) {
        return (
            <Box bg="bg.panel" borderRadius="xl" borderWidth="1px" borderColor="border" p={4}>
                <TagsRow tags={selectedTags} />
                <Box p={4} textAlign="center">
                    <Text color="fg.muted">Нет данных за выбранный период</Text>
                </Box>
            </Box>
        );
    }

    return (
        <Box bg="bg.panel" borderRadius="xl" borderWidth="1px" borderColor="border" p={3}>
            <TagsRow tags={selectedTags} />
            <Tabs.Root defaultValue="table" variant="line" size="sm">
                <HStack justify="space-between" mb={3} wrap="wrap" gap={2}>
                    <Tabs.List>
                        <Tabs.Trigger value="table">
                            <LuTable />
                            Таблица
                        </Tabs.Trigger>
                        <Tabs.Trigger value="chart">
                            <LuChartPie />
                            Диаграмма
                        </Tabs.Trigger>
                    </Tabs.List>
                    {onExport && (
                        <Button size="xs" variant="outline" onClick={onExport}>
                            <LuDownload /> XLSX
                        </Button>
                    )}
                </HStack>

                <Tabs.Content value="table" px={0}>
                    <Box overflowX="auto">
                        <Table.Root size="sm" variant="line">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>{title}</Table.ColumnHeader>
                                    {extraColumns.map((c) => (
                                        <Table.ColumnHeader key={c.key} textAlign="right">
                                            {c.label}
                                        </Table.ColumnHeader>
                                    ))}
                                    <Table.ColumnHeader textAlign="right">Штук</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Сумма, {symbol}</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Доля</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {rows.map((r) => {
                                    const share = totalAmount > 0 ? (Number(r.amount) / totalAmount) * 100 : 0;
                                    return (
                                        <Table.Row key={r.key}>
                                            <Table.Cell>
                                                <LabelCell
                                                    row={r}
                                                    getLabelHref={getLabelHref}
                                                    getLabelClick={getLabelClick}
                                                />
                                            </Table.Cell>
                                            {extraColumns.map((c) => (
                                                <Table.Cell key={c.key} textAlign="right">
                                                    {c.render(r)}
                                                </Table.Cell>
                                            ))}
                                            <Table.Cell textAlign="right">{fmtInt(r.qty)}</Table.Cell>
                                            <Table.Cell textAlign="right" fontWeight="600">{fmtMoney(r.amount)}</Table.Cell>
                                            <Table.Cell textAlign="right" color="fg.muted">{share.toFixed(1)}%</Table.Cell>
                                        </Table.Row>
                                    );
                                })}
                            </Table.Body>
                        </Table.Root>
                    </Box>
                </Tabs.Content>

                <Tabs.Content value="chart" px={0}>
                    <Box w="100%" h={{ base: '360px', md: '460px' }}>
                        <ResponsiveContainer>
                            <PieChart>
                                <Pie
                                    data={pieData}
                                    dataKey="amount"
                                    nameKey="label"
                                    cx="50%"
                                    cy="50%"
                                    outerRadius="75%"
                                    innerRadius="40%"
                                    label={({ percent }) => `${(percent * 100).toFixed(1)}%`}
                                    labelLine={false}
                                >
                                    {pieData.map((entry, idx) => (
                                        <Cell key={entry.key ?? idx} fill={entry.fill} />
                                    ))}
                                </Pie>
                                <Tooltip
                                    formatter={(value, name) => [`${fmtMoney(value)} ${symbol}`, name]}
                                />
                                <Legend
                                    layout="vertical"
                                    align="right"
                                    verticalAlign="middle"
                                    wrapperStyle={{ fontSize: '12px', maxWidth: '40%' }}
                                    formatter={(value) => {
                                        const v = String(value);
                                        return v.length > 32 ? `${v.slice(0, 32)}…` : v;
                                    }}
                                />
                            </PieChart>
                        </ResponsiveContainer>
                    </Box>
                </Tabs.Content>
            </Tabs.Root>
        </Box>
    );
}
