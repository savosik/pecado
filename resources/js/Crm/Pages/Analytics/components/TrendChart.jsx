import { Box, HStack, Text, Badge } from '@chakra-ui/react';
import {
    AreaChart, Area, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer,
} from 'recharts';

const fmtMoney = (v) => Number(v ?? 0).toLocaleString('ru-RU', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
});
const fmtInt = (v) => Number(v ?? 0).toLocaleString('ru-RU');
const fmtCompact = (v) => {
    const n = Number(v ?? 0);
    const abs = Math.abs(n);
    const sign = n < 0 ? '-' : '';
    const trim = (x) => x.toFixed(1).replace(/\.0$/, '').replace('.', ',');
    if (abs >= 1_000_000_000) return `${sign}${trim(abs / 1_000_000_000)} млрд`;
    if (abs >= 1_000_000) return `${sign}${trim(abs / 1_000_000)} млн`;
    if (abs >= 1_000) return `${sign}${trim(abs / 1_000)} тыс`;
    return String(Math.round(n));
};

const bucketLabel = { day: 'по дням', week: 'по неделям', month: 'по месяцам' };

function formatPeriodTick(period, bucket) {
    const d = new Date(period);
    if (Number.isNaN(d.getTime())) return period;
    if (bucket === 'month') {
        return d.toLocaleDateString('ru-RU', { month: 'short', year: '2-digit' });
    }
    return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
}

function CustomTooltip({ active, payload, label, bucket, currencySymbol }) {
    if (!active || !payload || payload.length === 0) return null;
    const date = formatPeriodTick(label, bucket);
    return (
        <Box bg="bg" borderWidth="1px" borderColor="border" borderRadius="md" p={2} boxShadow="md">
            <Text fontSize="xs" color="fg.muted" mb={1}>{date}</Text>
            {payload.map((p) => (
                <HStack key={p.dataKey} gap={2} fontSize="sm">
                    <Box w="8px" h="8px" borderRadius="full" bg={p.color} />
                    <Text fontWeight="600">{p.name}:</Text>
                    <Text>
                        {p.dataKey === 'qty'
                            ? fmtInt(p.value)
                            : `${fmtMoney(p.value)} ${currencySymbol}`}
                    </Text>
                </HStack>
            ))}
        </Box>
    );
}

/**
 * Динамика отгрузок. При наличии previousSeries накладывает пунктиром
 * сумму за предыдущий сопоставимый период (выравнивание по позиции точки).
 */
export default function TrendChart({ timeSeries, currency, previousSeries = null }) {
    const points = timeSeries?.points ?? [];
    const bucket = timeSeries?.bucket ?? 'day';
    const symbol = currency?.symbol || '₽';
    const prevPoints = previousSeries?.points ?? [];
    const hasPrev = prevPoints.length > 0;

    if (points.length === 0) {
        return (
            <Box bg="bg.panel" borderRadius="xl" borderWidth="1px" borderColor="border" p={6} textAlign="center">
                <Text color="fg.muted">Нет данных за выбранный период</Text>
            </Box>
        );
    }

    // Выравниваем прошлый период по позиции точки (даты разные, сопоставляем по порядку).
    const data = points.map((pt, i) => ({
        ...pt,
        prevAmount: hasPrev ? (prevPoints[i]?.amount ?? null) : null,
    }));

    return (
        <Box bg="bg.panel" borderRadius="xl" borderWidth="1px" borderColor="border" p={4}>
            <HStack justify="space-between" mb={3}>
                <Text fontWeight="600">Динамика отгрузок ({bucketLabel[bucket] || ''})</Text>
                {hasPrev && <Badge colorPalette="gray" variant="subtle">сравнение с прошлым периодом</Badge>}
            </HStack>
            <Box w="100%" h="280px">
                <ResponsiveContainer>
                    <AreaChart data={data} margin={{ top: 5, right: 10, left: 0, bottom: 0 }}>
                        <defs>
                            <linearGradient id="crmAmountFill" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stopColor="#9e1b32" stopOpacity={0.4} />
                                <stop offset="100%" stopColor="#9e1b32" stopOpacity={0} />
                            </linearGradient>
                            <linearGradient id="crmQtyFill" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stopColor="#3b82f6" stopOpacity={0.3} />
                                <stop offset="100%" stopColor="#3b82f6" stopOpacity={0} />
                            </linearGradient>
                        </defs>
                        <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
                        <XAxis
                            dataKey="period"
                            tickFormatter={(v) => formatPeriodTick(v, bucket)}
                            fontSize={11}
                        />
                        <YAxis yAxisId="left" tickFormatter={fmtCompact} fontSize={11} width={70} />
                        <YAxis yAxisId="right" orientation="right" tickFormatter={fmtCompact} fontSize={11} width={60} />
                        <Tooltip content={<CustomTooltip bucket={bucket} currencySymbol={symbol} />} />
                        <Legend />
                        <Area
                            yAxisId="left"
                            type="monotone"
                            dataKey="amount"
                            name="Сумма"
                            stroke="#9e1b32"
                            strokeWidth={2}
                            fill="url(#crmAmountFill)"
                        />
                        {hasPrev && (
                            <Line
                                yAxisId="left"
                                type="monotone"
                                dataKey="prevAmount"
                                name="Сумма (прошлый период)"
                                stroke="#9ca3af"
                                strokeWidth={2}
                                strokeDasharray="5 4"
                                dot={false}
                                connectNulls
                            />
                        )}
                        <Area
                            yAxisId="right"
                            type="monotone"
                            dataKey="qty"
                            name="Штук"
                            stroke="#3b82f6"
                            strokeWidth={2}
                            fill="url(#crmQtyFill)"
                        />
                    </AreaChart>
                </ResponsiveContainer>
            </Box>
        </Box>
    );
}
