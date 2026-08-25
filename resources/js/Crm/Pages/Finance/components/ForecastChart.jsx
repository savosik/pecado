import { Box, HStack, Text } from '@chakra-ui/react';
import {
    Area, CartesianGrid, ComposedChart, Line, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts';
import { formatCompact, formatRub } from './format';

/**
 * Кривая накопления поступлений: сколько денег будет собрано к каждому дню.
 *
 * Показана именно накопительная сумма, а не приход по дням: вопрос звучит
 * «сколько будет к такому-то числу», и складывать столбики в уме читателю
 * не нужно. Полоса между консервативным и оптимистичным сценарием — это и
 * есть «с какой вероятностью»: чем она шире, тем меньше определённости.
 *
 * Вертикальная черта отмечает конец графика от 1С: слева от неё прогноз
 * опирается на плановые строки, справа — только на ритм отгрузок, и путать
 * эти две природы нельзя.
 */
export default function ForecastChart({ curve, target, horizon }) {
    if (! curve?.length) {
        return null;
    }

    return (
        <Box>
            {/* Высота задаётся графику, а не блоку целиком: раньше легенда
                выпадала за пределы контейнера и наезжала на подписи оси. */}
            <Box height="240px">
                <ResponsiveContainer width="100%" height="100%">
                    <ComposedChart data={curve} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
                        <defs>
                            <linearGradient id="forecastBand" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stopColor="var(--chakra-colors-blue-solid)" stopOpacity={0.25} />
                                <stop offset="100%" stopColor="var(--chakra-colors-blue-solid)" stopOpacity={0.02} />
                            </linearGradient>
                        </defs>

                        <CartesianGrid strokeDasharray="3 3" opacity={0.25} vertical={false} />
                        <XAxis dataKey="label" tick={{ fontSize: 11 }} interval="preserveStartEnd" minTickGap={28} />
                        <YAxis tick={{ fontSize: 11 }} tickFormatter={formatCompact} width={70} />
                        <Tooltip content={<ForecastTooltip />} />

                        {/* Полоса неопределённости рисуется как разность двух областей:
                            нижняя невидима, верхняя закрашена — recharts не умеет
                            интервал одной серией. */}
                        <Area dataKey="low" stroke="none" fill="transparent" isAnimationActive={false} stackId="band" />
                        <Area dataKey="bandWidth" stroke="none" fill="url(#forecastBand)" isAnimationActive={false} stackId="band" />

                        <Line
                            dataKey="total"
                            stroke="var(--chakra-colors-blue-solid)"
                            strokeWidth={2}
                            dot={false}
                            isAnimationActive={false}
                        />
                        <Line
                            dataKey="expected"
                            stroke="var(--chakra-colors-green-solid)"
                            strokeWidth={1.5}
                            strokeDasharray="4 3"
                            dot={false}
                            isAnimationActive={false}
                        />

                        {horizon && (
                            <ReferenceLine
                                x={horizon}
                                stroke="var(--chakra-colors-fg-muted)"
                                strokeDasharray="2 4"
                                label={{ value: 'конец графика', position: 'insideTopLeft', fontSize: 10, fill: 'var(--chakra-colors-fg-muted)' }}
                            />
                        )}

                        {target && (
                            <ReferenceLine x={target} stroke="var(--chakra-colors-red-solid)" strokeWidth={1.5} />
                        )}
                    </ComposedChart>
                </ResponsiveContainer>
            </Box>

            <HStack gap={4} rowGap={1} justify="center" mt={2} fontSize="10px" color="fg.muted" wrap="wrap">
                <Legend color="var(--chakra-colors-blue-solid)" text="прогноз" />
                <Legend color="var(--chakra-colors-green-solid)" text="подтверждено графиком 1С" />
                <Legend color="var(--chakra-colors-blue-muted)" text="коридор сценариев" />
            </HStack>
        </Box>
    );
}

const Legend = ({ color, text }) => (
    <HStack gap={1} whiteSpace="nowrap">
        <Box width="10px" height="2px" bg={color} borderRadius="full" />
        <Text>{text}</Text>
    </HStack>
);

function ForecastTooltip({ active, payload, label }) {
    if (! active || ! payload?.length) {
        return null;
    }

    const point = payload[0].payload;

    return (
        <Box bg="bg" borderWidth="1px" borderColor="border" borderRadius="md" p={2} boxShadow="md">
            <Text fontSize="xs" color="fg.muted" mb={1}>к {label}</Text>
            <Text fontSize="sm" fontWeight="600">{formatRub(point.total)}</Text>
            <Text fontSize="10px" color="fg.muted">
                по графику {formatCompact(point.expected)} · от отгрузок {formatCompact(point.rhythm)}
            </Text>
            <Text fontSize="10px" color="fg.muted">
                коридор {formatCompact(point.low)} — {formatCompact(point.high)}
            </Text>
        </Box>
    );
}
