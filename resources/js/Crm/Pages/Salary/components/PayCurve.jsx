import { useState } from 'react';
import { Box, HStack, Text } from '@chakra-ui/react';
import {
    Area, CartesianGrid, ComposedChart, Line, ReferenceDot, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { fmtCompact, fmtFactor, fmtRub0 } from './format';

/**
 * «Сколько будет, если» — доход как функция выполнения плана и числа клиентов.
 *
 * Отвечает картинкой на вопрос «дожму до 100 % — сколько получу»: по оси X
 * рычаг, по Y — итог месяца, крупная точка «вы здесь», яркие риски порогов.
 * Каждая точка посчитана на сервере тем же калькулятором, что и текущая цифра.
 */
export default function PayCurve({ whatif }) {
    const [mode, setMode] = useState('revenue');

    const revenue = whatif?.revenue;
    const clients = whatif?.clients;
    const hasRevenue = (revenue?.points ?? []).length > 0;
    const hasClients = (clients?.points ?? []).length > 0;

    if (!hasRevenue && !hasClients) {
        return null;
    }

    const active = mode === 'revenue' && hasRevenue ? 'revenue' : 'clients';

    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <HStack justify="space-between" mb={3} flexWrap="wrap" gap={2}>
                <Text fontSize="xs" color="fg.muted" fontWeight="500">Сколько будет, если…</Text>
                {hasRevenue && hasClients && (
                    <SegmentedControl
                        size="xs"
                        value={active}
                        onValueChange={(e) => setMode(e.value)}
                        items={[
                            { value: 'revenue', label: 'дожму план' },
                            { value: 'clients', label: 'верну клиентов' },
                        ]}
                    />
                )}
            </HStack>

            {active === 'revenue' ? <RevenueChart data={revenue} /> : <ClientsChart data={clients} />}
        </Box>
    );
}

function RevenueChart({ data }) {
    const points = data.points.map((p) => ({ ...p, x: Math.round(p.share * 100) }));
    const current = data.current;
    const cap = data.cap;
    const target = data.target;
    const currentX = Math.round((current?.share ?? 0) * 100);
    const gain = target && current ? target.total - current.total : 0;

    return (
        <>
            <Box height="230px">
                <ResponsiveContainer width="100%" height="100%">
                    <ComposedChart data={points} margin={{ top: 12, right: 12, bottom: 0, left: 4 }}>
                        <defs>
                            <linearGradient id="payFill" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stopColor="var(--chakra-colors-blue-solid)" stopOpacity={0.22} />
                                <stop offset="100%" stopColor="var(--chakra-colors-blue-solid)" stopOpacity={0.02} />
                            </linearGradient>
                        </defs>
                        <CartesianGrid strokeDasharray="3 3" opacity={0.2} vertical={false} />
                        <XAxis dataKey="x" tick={{ fontSize: 11 }} tickFormatter={(v) => `${v}%`} interval="preserveStartEnd" minTickGap={24} />
                        <YAxis tick={{ fontSize: 11 }} tickFormatter={fmtCompact} width={74} />
                        <Tooltip content={<CurveTip xLabel="выполнение плана" xFormat={(v) => `${v} %`} />} />
                        <Area dataKey="total" stroke="none" fill="url(#payFill)" isAnimationActive={false} />
                        <Line dataKey="total" stroke="var(--chakra-colors-blue-solid)" strokeWidth={2.5} dot={false} isAnimationActive={false} />

                        <ReferenceLine x={100} stroke="var(--chakra-colors-green-solid)" strokeWidth={2}
                            label={{ value: 'план', position: 'top', fontSize: 11, fontWeight: 700, fill: 'var(--chakra-colors-green-fg)' }} />
                        {cap && (
                            <ReferenceLine x={Math.round(cap.share * 100)} stroke="var(--chakra-colors-orange-solid)" strokeWidth={2} strokeDasharray="4 3"
                                label={{ value: 'потолок', position: 'top', fontSize: 11, fontWeight: 700, fill: 'var(--chakra-colors-orange-fg)' }} />
                        )}
                        {current && (
                            <ReferenceDot x={currentX} y={current.total} r={7}
                                fill="var(--chakra-colors-blue-solid)" stroke="var(--chakra-colors-bg-panel)" strokeWidth={3}
                                label={{ value: 'сейчас', position: 'left', fontSize: 11, fontWeight: 700, fill: 'var(--chakra-colors-fg)' }} />
                        )}
                    </ComposedChart>
                </ResponsiveContainer>
            </Box>
            {gain > 1 && (
                <HStack justify="center" gap={2} fontSize="sm" mt={1}>
                    <Text color="fg.muted">дожать до плана →</Text>
                    <Text fontWeight="700" color="green.fg">+{fmtRub0(gain)}</Text>
                </HStack>
            )}
        </>
    );
}

function ClientsChart({ data }) {
    const points = data.points;
    const current = data.current;

    return (
        <>
            <Box height="230px">
                <ResponsiveContainer width="100%" height="100%">
                    <ComposedChart data={points} margin={{ top: 12, right: 12, bottom: 0, left: 4 }}>
                        <CartesianGrid strokeDasharray="3 3" opacity={0.2} vertical={false} />
                        <XAxis dataKey="active" tick={{ fontSize: 11 }} interval="preserveStartEnd" minTickGap={20} />
                        <YAxis tick={{ fontSize: 11 }} tickFormatter={fmtCompact} width={74} />
                        <Tooltip content={<CurveTip xLabel="активных клиентов" xFormat={(v) => v} />} />
                        <Line type="stepAfter" dataKey="total" stroke="var(--chakra-colors-purple-solid)" strokeWidth={2.5} dot={false} isAnimationActive={false} />

                        {(data.steps ?? []).slice(1).map((s) => (
                            <ReferenceLine
                                key={s.active}
                                x={s.active}
                                stroke={s.reached ? 'var(--chakra-colors-green-solid)' : 'var(--chakra-colors-fg-muted)'}
                                strokeWidth={2}
                                strokeDasharray={s.reached ? undefined : '4 3'}
                                label={{ value: `×${fmtFactor(s.multiplier)}`, position: 'top', fontSize: 11, fontWeight: 700, fill: s.reached ? 'var(--chakra-colors-green-fg)' : 'var(--chakra-colors-fg-muted)' }}
                            />
                        ))}
                        {current && (
                            <ReferenceDot x={current.active} y={current.total} r={7}
                                fill="var(--chakra-colors-purple-solid)" stroke="var(--chakra-colors-bg-panel)" strokeWidth={3}
                                label={{ value: 'сейчас', position: 'left', fontSize: 11, fontWeight: 700, fill: 'var(--chakra-colors-fg)' }} />
                        )}
                    </ComposedChart>
                </ResponsiveContainer>
            </Box>
            <HStack justify="center" gap={2} fontSize="sm" mt={1}>
                <Text color="fg.muted">каждая ступень — новый множитель премии</Text>
            </HStack>
        </>
    );
}

function CurveTip({ active, payload, label, xLabel, xFormat }) {
    if (!active || !payload?.length) return null;

    const p = payload[0].payload;

    return (
        <Box bg="bg" borderWidth="1px" borderColor="border" borderRadius="md" p={2} boxShadow="md">
            <Text fontSize="xs" color="fg.muted">{xLabel}: {xFormat(label)}</Text>
            <Text fontSize="sm" fontWeight="700">{fmtRub0(p.total)}</Text>
            {p.multiplier !== undefined && <Text fontSize="10px" color="fg.muted">множитель ×{fmtFactor(p.multiplier)}</Text>}
            {p.revenue !== undefined && <Text fontSize="10px" color="fg.muted">реализации {fmtCompact(p.revenue)}</Text>}
        </Box>
    );
}
