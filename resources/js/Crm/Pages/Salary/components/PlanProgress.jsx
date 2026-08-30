import { Box, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { ProgressBar, ProgressRoot } from '@/components/ui/progress';
import MetricHint from '@/Crm/Components/MetricHint';
import { fmtCompact, fmtFactor, fmtPercent, fmtRub0, plural } from './format';

const palette = (share) => {
    if (share === null || share === undefined) return 'gray';
    if (share < 0.7) return 'red';
    if (share < 0.95) return 'orange';

    return 'green';
};

function Tile({ title, hint, value, sub, share, note, children }) {
    const pct = share === null || share === undefined ? null : Math.max(0, Math.min(100, Math.round(share * 100)));

    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <HStack gap={1} mb={1}>
                <Text fontSize="xs" color="fg.muted" fontWeight="500">{title}</Text>
                {hint && <MetricHint text={hint} />}
            </HStack>
            <HStack justify="space-between" align="baseline" gap={2}>
                <Text fontSize="2xl" fontWeight="700" lineHeight="1.2" fontVariantNumeric="tabular-nums">{value}</Text>
                <Text fontSize="sm" color="fg.muted" whiteSpace="nowrap">{sub}</Text>
            </HStack>
            <HStack gap={2} mt={2}>
                <ProgressRoot flex="1" value={pct ?? 0} size="sm" colorPalette={palette(share)}>
                    <ProgressBar />
                </ProgressRoot>
                <Text fontSize="sm" fontWeight="600" color={`${palette(share)}.fg`} minW="48px" textAlign="right">
                    {pct === null ? '—' : `${pct} %`}
                </Text>
            </HStack>
            {note && <Text fontSize="xs" color="fg.muted" mt={2}>{note}</Text>}
            {children}
        </Box>
    );
}

/**
 * Две плитки-ответа: выполнение плана по выручке и удержание плановых клиентов.
 *
 * Обе отвечают на вопрос «где я сейчас» и подсказывают следующий шаг: сколько
 * не хватает до плана и сколько клиентов до следующей ступени множителя.
 */
export default function PlanProgress({ calculation, explanations }) {
    const inputs = calculation.inputs;
    const kpi = calculation.kpi;
    const multiplierMeta = (calculation.breakdown?.components ?? [])
        .find((c) => c.key === 'kpi_bonus')?.children?.find((c) => c.key === 'active_clients')?.meta;
    const next = multiplierMeta?.next_step;
    const days = inputs.working_days ?? {};

    return (
        <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
            <Tile
                title="План по выручке"
                hint={explanations?.revenue?.how_computed}
                value={fmtCompact(inputs.revenue)}
                sub={inputs.plan === null ? 'план не задан' : `из ${fmtCompact(inputs.plan)}`}
                share={inputs.percent}
                note={inputs.plan === null
                    ? 'Без плана KPI-премия не считается — план ставится на странице «Планы продаж».'
                    : inputs.remaining > 0
                        ? `До плана не хватает ${fmtRub0(inputs.remaining)}${days.left > 0 ? ` · осталось ${days.left} ${plural(days.left, 'рабочий день', 'рабочих дня', 'рабочих дней')}` : ''}`
                        : 'План выполнен — каждая следующая отгрузка поднимает премию до потолка'}
            >
                {kpi && kpi.penalty > 0 && (
                    <Text fontSize="xs" color="red.fg" mt={1}>
                        Штраф за дисциплину {fmtRub0(kpi.penalty)} — в расчёте выручка считается как {fmtCompact(kpi.adjusted)}
                    </Text>
                )}
            </Tile>

            <Tile
                title="Активные клиенты"
                hint={explanations?.active_clients?.how_computed}
                value={`${inputs.active_count} из ${inputs.planned_count}`}
                sub={multiplierMeta ? `множитель ${fmtFactor(multiplierMeta.multiplier)}` : ''}
                share={inputs.active_share}
                note={inputs.planned_count === 0
                    ? 'Плановые клиенты на месяц не назначены — множитель принят за 1,0.'
                    : next
                        ? `Ещё ${next.clients_needed} ${plural(next.clients_needed, 'клиент', 'клиента', 'клиентов')} с отгрузкой — и множитель ${fmtFactor(next.multiplier)} (порог ${fmtPercent(next.from_share, 0)})`
                        : 'Верхняя ступень лестницы — выше множитель не растёт'}
            />
        </SimpleGrid>
    );
}
