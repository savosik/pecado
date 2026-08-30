import { Box, HStack, Text } from '@chakra-ui/react';
import { Bar, BarChart, Cell, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { fmtCompact, fmtRub0, fmtSigned } from './format';

const COLOR = {
    plus: 'var(--chakra-colors-blue-solid)',
    minus: 'var(--chakra-colors-red-solid)',
    total: 'var(--chakra-colors-green-solid)',
};

/**
 * Водопад дохода: оклад → +KPI без штрафа → −штраф → +доп. доход → итого.
 *
 * Показываем не «какая премия», а «какой она была бы без штрафа и сколько
 * штраф отнял»: это единственный способ увидеть цену просроченной оплаты
 * в рублях, а не в процентах формулы. Все шаги — из snapshot-а, фронт
 * ничего не считает сам, кроме позиций столбиков.
 */
export default function IncomeWaterfall({ calculation }) {
    const steps = buildSteps(calculation);

    if (steps.length < 2) {
        return null;
    }

    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <Text fontSize="xs" color="fg.muted" fontWeight="500" mb={3}>Из чего складывается доход</Text>
            <Box height="220px">
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={steps} margin={{ top: 18, right: 8, bottom: 0, left: 0 }} barCategoryGap="22%">
                        <XAxis dataKey="label" tick={{ fontSize: 11 }} interval={0} />
                        <YAxis hide domain={[0, 'dataMax']} />
                        <Tooltip content={<WaterfallTooltip />} cursor={{ fill: 'var(--chakra-colors-bg-muted)' }} />
                        <Bar dataKey="offset" stackId="w" fill="transparent" isAnimationActive={false} />
                        <Bar dataKey="size" stackId="w" isAnimationActive={false} radius={[4, 4, 0, 0]}>
                            {steps.map((s) => <Cell key={s.key} fill={COLOR[s.kind]} />)}
                            <LabelList dataKey="labelValue" position="top" fontSize={11} fill="var(--chakra-colors-fg)" />
                        </Bar>
                    </BarChart>
                </ResponsiveContainer>
            </Box>
            <HStack gap={4} justify="center" mt={2} fontSize="10px" color="fg.muted" wrap="wrap">
                <Legend color={COLOR.plus} text="прибавляет" />
                <Legend color={COLOR.minus} text="отнимает" />
                <Legend color={COLOR.total} text="итог" />
            </HStack>
        </Box>
    );
}

function buildSteps(calculation) {
    const components = calculation.breakdown?.components ?? [];
    const kpi = calculation.kpi;
    const rows = [];

    components.forEach((c) => {
        if (c.key === 'kpi_bonus' && kpi) {
            const withoutPenalty = kpi.without_penalty ?? c.amount;
            rows.push({ key: 'kpi_gross', label: 'KPI без штрафа', delta: withoutPenalty, kind: 'plus' });
            if (kpi.penalty > 0 && withoutPenalty - c.amount > 0.005) {
                rows.push({ key: 'penalty', label: 'Штраф', delta: c.amount - withoutPenalty, kind: 'minus' });
            }
            return;
        }

        if (Math.abs(c.amount ?? 0) < 0.005 && c.key !== 'salary') {
            return;
        }

        rows.push({ key: c.key, label: c.label, delta: c.amount ?? 0, kind: c.amount < 0 ? 'minus' : 'plus' });
    });

    let running = 0;
    const steps = rows.map((row) => {
        const start = running;
        running += row.delta;
        const low = Math.min(start, running);

        return {
            ...row,
            offset: low,
            size: Math.abs(row.delta),
            labelValue: fmtSigned(row.delta),
            running,
        };
    });

    steps.push({
        key: 'total',
        label: 'Итого',
        delta: running,
        kind: 'total',
        offset: 0,
        size: Math.max(0, running),
        labelValue: fmtRub0(running),
        running,
    });

    return steps;
}

const Legend = ({ color, text }) => (
    <HStack gap={1} whiteSpace="nowrap">
        <Box width="10px" height="10px" bg={color} borderRadius="sm" />
        <Text>{text}</Text>
    </HStack>
);

function WaterfallTooltip({ active, payload }) {
    if (!active || !payload?.length) return null;

    const step = payload[0].payload;

    return (
        <Box bg="bg" borderWidth="1px" borderColor="border" borderRadius="md" p={2} boxShadow="md">
            <Text fontSize="xs" color="fg.muted">{step.label}</Text>
            <Text fontSize="sm" fontWeight="600">{step.kind === 'total' ? fmtRub0(step.delta) : fmtSigned(step.delta)}</Text>
            {step.kind !== 'total' && (
                <Text fontSize="10px" color="fg.muted">нарастающим итогом {fmtCompact(step.running)}</Text>
            )}
        </Box>
    );
}
