import { Box, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import Gauge from './Gauge';
import { fmtCompact, fmtFactor, plural } from './format';

/**
 * Два спидометра — «где я сейчас» без единой лишней фразы.
 *
 * Слева выполнение плана по выручке: цель 100 % и потолок премии отмечены
 * рисками. Справа активные клиенты: зоны шкалы — ступени лестницы множителя,
 * риски подписаны самим множителем, достигнутые — чёрным, недостигнутые серым.
 */
export default function GaugeRow({ calculation }) {
    const inputs = calculation.inputs;
    const kpi = calculation.kpi;
    const multiplierMeta = (calculation.breakdown?.components ?? [])
        .find((c) => c.key === 'kpi_bonus')?.children?.find((c) => c.key === 'active_clients')?.meta ?? {};

    const cap = Number(kpi?.cap ?? 2);
    const planShare = inputs.percent ?? 0;
    const planMax = Math.max(1.2, Math.min(cap, planShare * 1.15 || 1.2));

    const ladder = multiplierMeta.ladder ?? [];
    const planned = inputs.planned_count ?? 0;
    const active = inputs.active_count ?? 0;
    const clientsShare = inputs.active_share ?? 0;
    const clientsMax = Math.max(1, ...(ladder.map((s) => Number(s.from_share) || 0)), clientsShare) * 1.05;

    const zoneColor = (i, total) => {
        const step = 0.25 + (0.75 * i) / Math.max(1, total - 1);

        return `color-mix(in oklab, var(--chakra-colors-blue-solid) ${Math.round(step * 100)}%, var(--chakra-colors-bg-muted))`;
    };

    const clientSegments = ladder.map((s, i) => ({
        from: Number(s.from_share) || 0,
        to: i + 1 < ladder.length ? Number(ladder[i + 1].from_share) : clientsMax,
        color: zoneColor(i, ladder.length),
    }));

    const clientMarkers = ladder.slice(1).map((s) => ({
        value: Number(s.from_share),
        label: `×${fmtFactor(s.multiplier)}`,
        reached: clientsShare + 1e-9 >= Number(s.from_share),
        strong: true,
    }));

    const next = multiplierMeta.next_step;

    return (
        <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
            <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                <VStack gap={2}>
                    <Gauge
                        value={planShare}
                        max={planMax}
                        tone={planShare >= 1 ? 'green' : planShare >= 0.8 ? 'blue' : 'orange'}
                        segments={[
                            { from: 0, to: Math.min(1, planMax), color: 'var(--chakra-colors-blue-muted)' },
                            { from: Math.min(1, planMax), to: planMax, color: 'var(--chakra-colors-green-solid)' },
                        ]}
                        markers={[{ value: 1, label: 'план', reached: planShare >= 1, strong: true }]}
                        centerValue={`${Math.round(planShare * 100)} %`}
                        centerLabel="плана по выручке"
                        caption={inputs.plan ? `${fmtCompact(inputs.revenue)} из ${fmtCompact(inputs.plan)}` : 'план на месяц не задан'}
                    />
                    {inputs.remaining > 0 && (
                        <HStack gap={1} fontSize="sm">
                            <Text color="fg.muted">до плана</Text>
                            <Text fontWeight="700">{fmtCompact(inputs.remaining)}</Text>
                        </HStack>
                    )}
                </VStack>
            </Box>

            <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                <VStack gap={2}>
                    <Gauge
                        value={clientsShare}
                        max={clientsMax}
                        tone="purple"
                        segments={clientSegments}
                        markers={clientMarkers}
                        centerValue={`${active} / ${planned}`}
                        centerLabel="активных клиентов"
                        caption={`множитель ×${fmtFactor(multiplierMeta.multiplier)}`}
                    />
                    {next && (
                        <HStack gap={1} fontSize="sm" flexWrap="wrap" justify="center">
                            <Text fontWeight="700">+{next.clients_needed}</Text>
                            <Text color="fg.muted">
                                {plural(next.clients_needed, 'клиент', 'клиента', 'клиентов')} → ×{fmtFactor(next.multiplier)}
                            </Text>
                        </HStack>
                    )}
                </VStack>
            </Box>
        </SimpleGrid>
    );
}
