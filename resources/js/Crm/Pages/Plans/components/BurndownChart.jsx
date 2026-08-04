import { Box, HStack, Text } from '@chakra-ui/react';
import {
    CartesianGrid, Legend, Line, LineChart, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts';
import { useColorModeValue } from '@/components/ui/color-mode';

const fmtMoney = (v) => Number(v ?? 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 });

const fmtCompact = (v) => {
    const n = Number(v ?? 0);
    const abs = Math.abs(n);
    const sign = n < 0 ? '−' : '';
    const trim = (x) => x.toFixed(1).replace(/\.0$/, '').replace('.', ',');
    if (abs >= 1_000_000) return `${sign}${trim(abs / 1_000_000)} млн`;
    if (abs >= 1_000) return `${sign}${trim(abs / 1_000)} тыс`;

    return String(Math.round(n));
};

const fmtDay = (date) => {
    const d = new Date(date);

    return Number.isNaN(d.getTime())
        ? date
        : d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
};

function makeTooltip() {
    return function BurndownTooltip({ active, payload, label }) {
        if (!active || !payload || payload.length === 0) return null;

        return (
            <Box bg="bg" borderWidth="1px" borderColor="border" borderRadius="md" p={2} boxShadow="md">
                <Text fontSize="xs" color="fg.muted" mb={1}>{fmtDay(label)}</Text>
                {payload.map((p) => (
                    <HStack key={p.dataKey} gap={2} fontSize="sm">
                        <Box w="8px" h="8px" borderRadius="full" bg={p.color} />
                        <Text color="fg.muted">{p.name}:</Text>
                        <Text fontWeight="600" color="fg">{fmtMoney(p.value)} ₽</Text>
                    </HStack>
                ))}
                <Text fontSize="xs" color="fg.muted" mt={1}>
                    Продано с начала месяца: {fmtMoney(payload[0]?.payload?.fact_cumulative)} ₽
                </Text>
            </Box>
        );
    };
}

/**
 * Burndown месяца: сколько осталось добрать до плана.
 *
 * Идеальная линия — равномерное списание плана по дням, фактическая — реальный
 * остаток на конец каждого дня. Дни после сегодняшнего не рисуются: остаток,
 * протянутый горизонталью до конца месяца, читается как «работа встала».
 *
 * Пересечение нуля — план перевыполнен, поэтому ось уходит в минус, а не
 * обрезается по нулю.
 */
export default function BurndownChart({ points = [], plan = null }) {
    // Кирпичный бренд-цвет на тёмной подложке даёт контраст 2.2:1 — линию
    // становится не видно, поэтому в тёмной теме берётся осветлённый шаг.
    const actualColor = useColorModeValue('#9e1b32', '#e05a72');
    const idealColor = useColorModeValue('#6b7280', '#9ca3af');
    const gridColor = useColorModeValue('#e5e7eb', '#3f3f46');
    const axisColor = useColorModeValue('#6b7280', '#a1a1aa');
    const Tip = makeTooltip();

    if (plan === null) {
        return (
            <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={6} textAlign="center">
                <Text color="fg.muted" fontSize="sm">
                    План на этот месяц не задан — сгорать нечему. Поставьте план на вкладке «Ввод планов».
                </Text>
            </Box>
        );
    }

    if (points.length === 0) {
        return (
            <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={6} textAlign="center">
                <Text color="fg.muted" fontSize="sm">Месяц ещё не начался — данных для графика нет.</Text>
            </Box>
        );
    }

    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <Text fontWeight="600" mb={1}>Сколько осталось добрать</Text>
            <Text fontSize="xs" color="fg.muted" mb={3}>
                Пунктир — равномерный темп по плану, сплошная — реальный остаток. Ниже пунктира значит идём с опережением.
            </Text>
            <Box w="100%" h="280px">
                <ResponsiveContainer>
                    <LineChart data={points} margin={{ top: 5, right: 12, left: 0, bottom: 0 }}>
                        <CartesianGrid strokeDasharray="3 3" stroke={gridColor} vertical={false} />
                        <XAxis
                            dataKey="date"
                            tickFormatter={fmtDay}
                            fontSize={11}
                            stroke={axisColor}
                            tickLine={false}
                        />
                        <YAxis
                            tickFormatter={fmtCompact}
                            fontSize={11}
                            width={70}
                            stroke={axisColor}
                            tickLine={false}
                            axisLine={false}
                        />
                        <ReferenceLine y={0} stroke={axisColor} strokeWidth={1} />
                        <Tooltip content={<Tip />} cursor={{ stroke: axisColor, strokeWidth: 1 }} />
                        <Legend iconType="plainline" />
                        <Line
                            type="linear"
                            dataKey="ideal_remaining"
                            name="Идеальный темп"
                            stroke={idealColor}
                            strokeWidth={2}
                            strokeDasharray="6 4"
                            dot={false}
                            isAnimationActive={false}
                        />
                        <Line
                            type="linear"
                            dataKey="actual_remaining"
                            name="Осталось добрать"
                            stroke={actualColor}
                            strokeWidth={2}
                            dot={false}
                            isAnimationActive={false}
                        />
                    </LineChart>
                </ResponsiveContainer>
            </Box>
        </Box>
    );
}
