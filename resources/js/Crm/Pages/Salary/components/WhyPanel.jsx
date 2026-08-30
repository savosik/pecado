import { Box, HStack, Text, VStack } from '@chakra-ui/react';
import { fmtCompact, fmtFactor, fmtRub0, plural } from './format';

/**
 * Из чего сложился доход: неизменный оклад отдельно, премия отдельно.
 *
 * Главная мысль блока — премия не «начислена кем-то», а получилась из трёх
 * рычагов, и у каждого есть цена в рублях. Поэтому под каждой полосой стоит
 * не пояснение формулы, а ответ на вопрос «что мне с этим делать»: сколько
 * даст следующий шаг. Цифры шагов считает сервер (те же советы, что в блоке
 * «Что можно поднять») — своей арифметики страница не ведёт.
 */
export default function WhyPanel({ calculation }) {
    const kpi = calculation.kpi;
    const inputs = calculation.inputs;
    const components = calculation.breakdown?.components ?? [];

    const salary = Number(components.find((c) => c.key === 'salary')?.amount ?? 0);
    const bonus = Number(components.find((c) => c.key === 'kpi_bonus')?.amount ?? 0);

    if (!kpi || !kpi.base) {
        return null;
    }

    const base = Number(kpi.base);
    const ratio = inputs.plan > 0 ? inputs.revenue / inputs.plan : 0;
    const advice = calculation.forecast?.advice ?? [];
    const byKey = Object.fromEntries(advice.map((a) => [a.key, a]));

    const penaltyFactor = components
        .find((c) => c.key === 'kpi_bonus')?.children?.find((c) => c.key === 'discipline_penalty');
    const multiplierMeta = components
        .find((c) => c.key === 'kpi_bonus')?.children?.find((c) => c.key === 'active_clients')?.meta ?? {};
    const penalizedCount = Number(penaltyFactor?.meta?.penalized_count ?? 0);
    const stepGain = byKey.revenue_step?.gain ?? null;

    const rows = [];

    if (ratio < 1) {
        rows.push({
            key: 'plan',
            label: 'план не закрыт',
            value: base * (1 - ratio),
            sign: '−',
            tone: 'blue',
            fact: `${Math.round(ratio * 100)} % плана · не хватает ${fmtCompact(inputs.remaining)}`,
            action: byKey.plan_gap
                ? `дожать план → +${fmtRub0(byKey.plan_gap.gain)} к премии`
                : stepGain
                    ? `каждые 100 000 ₽ реализаций → +${fmtRub0(stepGain)}`
                    : null,
        });
    } else if (ratio > 1) {
        rows.push({
            key: 'over',
            label: 'сверх плана',
            value: base * (ratio - 1),
            sign: '+',
            tone: 'green',
            fact: `${Math.round(ratio * 100)} % плана`,
            action: stepGain ? `каждые следующие 100 000 ₽ → +${fmtRub0(stepGain)}` : null,
        });
    }

    const penaltyLoss = Math.max(0, (kpi.without_penalty ?? bonus) - bonus);
    if (penaltyLoss >= 1) {
        rows.push({
            key: 'penalty',
            label: 'клиенты платят с задержкой',
            value: penaltyLoss,
            sign: '−',
            tone: 'red',
            fact: `${penalizedCount} ${plural(penalizedCount, 'накладная закрыта', 'накладные закрыты', 'накладных закрыто')} позже срока · ${fmtCompact(kpi.penalty)} вычтено из выручки`,
            action: stepGain
                ? `каждые 100 000 ₽ просрочки — это −${fmtRub0(stepGain)} премии`
                : 'вычет уменьшает выручку до сравнения с планом',
        });
    }

    const multiplierLoss = Math.max(0, (kpi.without_multiplier ?? bonus) - bonus);
    if (multiplierLoss >= 1) {
        const next = multiplierMeta.next_step;
        rows.push({
            key: 'clients',
            label: 'часть клиентов не купила',
            value: multiplierLoss,
            sign: '−',
            tone: 'orange',
            fact: `${inputs.active_count} из ${inputs.planned_count} плановых · множитель ×${fmtFactor(multiplierMeta.multiplier)} вместо ×1`,
            action: next && byKey.active_clients
                ? `ещё ${next.clients_needed} ${plural(next.clients_needed, 'клиент', 'клиента', 'клиентов')} → ×${fmtFactor(next.multiplier)}, это +${fmtRub0(byKey.active_clients.gain)}`
                : next
                    ? `ещё ${next.clients_needed} ${plural(next.clients_needed, 'клиент', 'клиента', 'клиентов')} → множитель ×${fmtFactor(next.multiplier)}`
                    : null,
        });
    }

    rows.sort((a, b) => b.value - a.value);
    const maxValue = Math.max(1, ...rows.map((r) => r.value));
    const diff = bonus - base;

    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflow="hidden">
            <HStack justify="space-between" align="baseline" p={4} gap={3}>
                <Box>
                    <Text fontWeight="700">Ваш оклад</Text>
                    <Text fontSize="xs" color="fg.muted">фиксированная часть — не меняется от продаж</Text>
                </Box>
                <Text fontSize="xl" fontWeight="800" fontVariantNumeric="tabular-nums" whiteSpace="nowrap">
                    {fmtRub0(salary)}
                </Text>
            </HStack>

            <Box borderTopWidth="1px" borderColor="border" p={4}>
                <HStack justify="space-between" align="baseline" gap={3}>
                    <Box>
                        <Text fontWeight="700">Премия</Text>
                        <Text fontSize="xs" color="fg.muted">
                            переменная часть · базовая {fmtRub0(base)} · потолок {fmtRub0(kpi.max_amount)}
                        </Text>
                    </Box>
                    <Text fontSize="xl" fontWeight="800" fontVariantNumeric="tabular-nums" whiteSpace="nowrap" color={diff >= 0 ? 'green.fg' : undefined}>
                        {fmtRub0(bonus)}
                    </Text>
                </HStack>

                <Text fontSize="sm" mt={3}>
                    {Math.abs(diff) < 1
                        ? 'Премия равна базовой: план закрыт, клиенты на месте, задержек оплат нет.'
                        : diff > 0
                            ? <>Премия <Text as="span" fontWeight="700" color="green.fg">выше базовой на {fmtRub0(diff)}</Text> — вот что на неё повлияло:</>
                            : <>Премия <Text as="span" fontWeight="700" color="red.fg">ниже базовой на {fmtRub0(-diff)}</Text> — вот что её уменьшило:</>}
                </Text>

                <VStack align="stretch" gap={4} mt={4}>
                    {rows.map((r) => (
                        <Box key={r.key}>
                            <HStack justify="space-between" align="baseline" gap={3} mb="4px">
                                <Text fontSize="sm" fontWeight="600">{r.label}</Text>
                                <Text fontSize="sm" fontWeight="700" color={`${r.tone}.fg`} whiteSpace="nowrap" fontVariantNumeric="tabular-nums">
                                    {r.sign}{fmtRub0(r.value)}
                                </Text>
                            </HStack>

                            <Box h="8px" bg="bg.muted" borderRadius="full" overflow="hidden">
                                <Box h="100%" w={`${Math.max(3, (r.value / maxValue) * 100)}%`} bg={`${r.tone}.solid`} borderRadius="full" />
                            </Box>

                            <Text fontSize="xs" color="fg.muted" mt="4px">{r.fact}</Text>
                            {r.action && (
                                <Text fontSize="xs" color={`${r.tone}.fg`} fontWeight="600" mt="2px">{r.action}</Text>
                            )}
                        </Box>
                    ))}
                </VStack>
            </Box>

            <HStack justify="space-between" align="baseline" p={4} borderTopWidth="1px" borderColor="border" bg="bg.subtle" gap={3}>
                <Text fontWeight="700">Итого за месяц</Text>
                <Text fontSize="2xl" fontWeight="800" fontVariantNumeric="tabular-nums" whiteSpace="nowrap">
                    {fmtRub0(calculation.total)}
                </Text>
            </HStack>
        </Box>
    );
}
