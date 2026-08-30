import { Box, Collapsible, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuChevronDown, LuTrendingUp } from 'react-icons/lu';
import {
    Area, CartesianGrid, ComposedChart, Line, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts';
import MetricHint from '@/Crm/Components/MetricHint';
import { fmtCompact, fmtRub0, fmtSigned } from './format';

const SCENARIO_TONE = {
    pessimistic: 'red',
    base: 'blue',
    optimistic: 'green',
    stretch: 'green',
    perfect: 'purple',
};

/**
 * Дальние ориентиры показываем приглушённо: они достижимы не действиями этой
 * недели, а хорошим месяцем целиком, и не должны спорить за внимание
 * с ближними сценариями.
 */
const DIMMED = ['stretch', 'perfect'];

/**
 * Прогноз на конец месяца: три плитки-сценария и кривая с коридором.
 *
 * Слева от «сегодня» — оценка накопленного дохода, справа — коридор между
 * пессимистичным и оптимистичным сценариями с базовой линией посередине.
 * Все сценарии посчитаны сервером тем же калькулятором на гипотетических входах.
 */
export default function ForecastChart({ forecast, current }) {
    if (!forecast?.scenarios) {
        return null;
    }

    const scenarios = ['pessimistic', 'base', 'optimistic', 'stretch', 'perfect']
        .map((k) => forecast.scenarios[k])
        .filter(Boolean);
    const curve = (forecast.curve ?? []).map((p) => ({
        ...p,
        bandWidth: p.low !== null && p.high !== null ? p.high - p.low : null,
    }));
    const today = curve.find((p) => p.is_today)?.label;
    const closed = forecast.basis?.closed;

    const baseTotal = forecast.scenarios?.base?.total;

    return (
        <Collapsible.Root bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflow="hidden">
            <Collapsible.Trigger asChild>
                <HStack
                    as="button"
                    type="button"
                    w="100%"
                    gap={3}
                    p={{ base: 4, md: 5 }}
                    textAlign="left"
                    cursor="pointer"
                    _hover={{ bg: 'bg.subtle' }}
                    transition="background 0.15s"
                >
                    <Box p={2.5} borderRadius="lg" bg="green.subtle" color="green.fg" display="flex" flexShrink={0}>
                        <LuTrendingUp size={22} />
                    </Box>

                    <Box flex="1" minW={0}>
                        <Text fontWeight="700" fontSize={{ base: 'sm', md: 'md' }}>
                            {closed ? 'Чем закончился месяц' : 'Сколько выйдет к концу месяца'}
                        </Text>
                        <Text fontSize="xs" color="fg.muted">
                            {closed
                                ? 'Итог зафиксирован'
                                : baseTotal !== undefined
                                    ? `При текущем темпе — ${fmtRub0(baseTotal)}. Пять сценариев: от несобранных долгов до предела месяца.`
                                    : 'Пять сценариев: от несобранных долгов до предела месяца.'}
                        </Text>
                    </Box>

                    <Box color="fg.muted" flexShrink={0} transition="transform 0.2s" css={{ '[data-state=open] &': { transform: 'rotate(180deg)' } }}>
                        <LuChevronDown size={20} />
                    </Box>
                </HStack>
            </Collapsible.Trigger>

            <Collapsible.Content>
                <Box px={{ base: 4, md: 5 }} pb={{ base: 4, md: 5 }} pt={4} borderTopWidth="1px" borderColor="border">
            <HStack gap={2} mb={1} flexWrap="wrap">
                <Text fontSize="xs" color="fg.muted" fontWeight="500">Как считаются сценарии</Text>
                <MetricHint text="Каждый сценарий посчитан той же формулой, что и текущая цифра: меняются только исходные данные — сколько отгружено, сколько клиентов купило и как заплатили по неоплаченным накладным." />
            </HStack>

            {!closed && forecast.current_total !== undefined && (
                <Text fontSize="xs" color="fg.subtle" mb={3}>
                    Если бы месяц закончился сегодня — {fmtRub0(forecast.current_total)}. Дальше зависит от того,
                    соберёте ли долги и сколько продадите за оставшиеся дни:
                </Text>
            )}

            {!closed && (
                <SimpleGrid columns={{ base: 1, md: 2, xl: 5 }} gap={4} mb={4}>
                    {scenarios.map((s) => {
                        const delta = forecast.current_total === undefined ? null : s.total - forecast.current_total;
                        const dimmed = DIMMED.includes(s.key);

                        return (
                            <Box
                                key={s.key}
                                borderWidth="1px"
                                borderColor="border"
                                borderTopWidth="3px"
                                borderTopColor={`${SCENARIO_TONE[s.key]}.solid`}
                                borderTopStyle={dimmed ? 'dashed' : 'solid'}
                                borderRadius="lg"
                                p={3}
                                opacity={dimmed ? 0.62 : 1}
                                bg={dimmed ? 'bg.subtle' : undefined}
                                transition="opacity 0.15s"
                                _hover={dimmed ? { opacity: 1 } : undefined}
                            >
                                <Text fontSize="sm" fontWeight="600">{s.label}</Text>

                                <HStack align="baseline" gap={2} mt={1} flexWrap="wrap">
                                    <Text fontSize="xl" fontWeight="800" fontVariantNumeric="tabular-nums">{fmtRub0(s.total)}</Text>
                                    {delta !== null && Math.abs(delta) >= 1 && (
                                        <Text fontSize="sm" fontWeight="700" color={delta > 0 ? 'green.fg' : 'red.fg'} whiteSpace="nowrap">
                                            {fmtSigned(delta)}
                                        </Text>
                                    )}
                                </HStack>

                                {s.hint && <Text fontSize="xs" color="fg.muted" mt={1}>{s.hint}</Text>}
                            </Box>
                        );
                    })}
                </SimpleGrid>
            )}

            {curve.length > 0 && (
                <Box height="220px">
                    <ResponsiveContainer width="100%" height="100%">
                        <ComposedChart data={curve} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
                            <defs>
                                <linearGradient id="salaryBand" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stopColor="var(--chakra-colors-blue-solid)" stopOpacity={0.25} />
                                    <stop offset="100%" stopColor="var(--chakra-colors-blue-solid)" stopOpacity={0.03} />
                                </linearGradient>
                            </defs>
                            <CartesianGrid strokeDasharray="3 3" opacity={0.25} vertical={false} />
                            <XAxis dataKey="label" tick={{ fontSize: 11 }} interval="preserveStartEnd" minTickGap={28} />
                            <YAxis tick={{ fontSize: 11 }} tickFormatter={fmtCompact} width={76} domain={['auto', 'auto']} />
                            <Tooltip content={<CurveTooltip />} />
                            <Area dataKey="low" stroke="none" fill="transparent" isAnimationActive={false} stackId="band" connectNulls />
                            <Area dataKey="bandWidth" stroke="none" fill="url(#salaryBand)" isAnimationActive={false} stackId="band" connectNulls />
                            <Line dataKey="earned" stroke="var(--chakra-colors-green-solid)" strokeWidth={2} dot={false} isAnimationActive={false} connectNulls={false} />
                            <Line dataKey="base" stroke="var(--chakra-colors-blue-solid)" strokeWidth={1.5} strokeDasharray="4 3" dot={false} isAnimationActive={false} connectNulls />
                            {today && !closed && (
                                <ReferenceLine
                                    x={today}
                                    stroke="var(--chakra-colors-fg-muted)"
                                    strokeDasharray="2 4"
                                    label={{ value: 'сегодня', position: 'insideTopLeft', fontSize: 10, fill: 'var(--chakra-colors-fg-muted)' }}
                                />
                            )}
                        </ComposedChart>
                    </ResponsiveContainer>
                </Box>
            )}

            <HStack gap={4} justify="center" mt={2} fontSize="10px" color="fg.muted" wrap="wrap">
                <Legend color="var(--chakra-colors-green-solid)" text="заработано" />
                <Legend color="var(--chakra-colors-blue-solid)" text="при текущем темпе" />
                <Legend color="var(--chakra-colors-blue-muted)" text="коридор сценариев" />
            </HStack>

                    {current && forecast.basis?.revenue_per_day && !closed && (
                        <VStack align="start" gap={0} mt={3}>
                            <Text fontSize="xs" color="fg.muted">
                                Темп: {fmtCompact(forecast.basis.revenue_per_day)} реализаций в рабочий день,
                                осталось {forecast.basis.working_days?.left ?? 0} из {forecast.basis.working_days?.total ?? 0}.
                            </Text>
                        </VStack>
                    )}
                </Box>
            </Collapsible.Content>
        </Collapsible.Root>
    );
}

const Legend = ({ color, text }) => (
    <HStack gap={1} whiteSpace="nowrap">
        <Box width="10px" height="2px" bg={color} borderRadius="full" />
        <Text>{text}</Text>
    </HStack>
);

function CurveTooltip({ active, payload, label }) {
    if (!active || !payload?.length) return null;

    const p = payload[0].payload;

    return (
        <Box bg="bg" borderWidth="1px" borderColor="border" borderRadius="md" p={2} boxShadow="md">
            <Text fontSize="xs" color="fg.muted" mb={1}>{label}</Text>
            {p.earned !== null && p.earned !== undefined && (
                <Text fontSize="sm" fontWeight="600">заработано {fmtRub0(p.earned)}</Text>
            )}
            {p.base !== null && p.base !== undefined && (
                <>
                    <Text fontSize="sm" fontWeight="600">при темпе {fmtRub0(p.base)}</Text>
                    <Text fontSize="10px" color="fg.muted">коридор {fmtCompact(p.low)} — {fmtCompact(p.high)}</Text>
                </>
            )}
        </Box>
    );
}
