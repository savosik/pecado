import { Box, HStack, Text, VStack } from '@chakra-ui/react';
import { fmtCompact, fmtRub0 } from './format';

/**
 * «Могло быть — но пока так, потому что…» одной картинкой.
 *
 * Полная премия сверху, ниже — полосы потерь с ценой в рублях, внизу — итог.
 * Ширина полосы пропорциональна потере, поэтому видно не только «что мешает»,
 * а что мешает БОЛЬШЕ. Ни одной формулы: формулы живут в подробностях.
 */
export default function WhyPanel({ calculation }) {
    const kpi = calculation.kpi;
    const inputs = calculation.inputs;

    if (!kpi || !kpi.base) {
        return null;
    }

    const base = Number(kpi.base);
    const amount = Number((calculation.breakdown?.components ?? []).find((c) => c.key === 'kpi_bonus')?.amount ?? 0);
    const ratio = inputs.plan > 0 ? inputs.revenue / inputs.plan : 0;

    const penaltyFactor = (calculation.breakdown?.components ?? [])
        .find((c) => c.key === 'kpi_bonus')?.children?.find((c) => c.key === 'discipline_penalty');
    const penalizedCount = Number(penaltyFactor?.meta?.penalized_count ?? 0);

    const losses = [];

    if (ratio < 1) {
        losses.push({
            key: 'plan',
            label: 'план не закрыт',
            hint: `${Math.round(ratio * 100)} % — не хватает ${fmtCompact(inputs.remaining)}`,
            value: base * (1 - ratio),
            tone: 'blue',
        });
    }

    const penaltyLoss = Math.max(0, (kpi.without_penalty ?? amount) - amount);
    if (penaltyLoss >= 1) {
        losses.push({
            key: 'penalty',
            label: 'оплаты с задержкой',
            hint: `${penalizedCount} накл. закрыты позже срока`,
            value: penaltyLoss,
            tone: 'red',
        });
    }

    const multiplierLoss = Math.max(0, (kpi.without_multiplier ?? amount) - amount);
    if (multiplierLoss >= 1) {
        losses.push({
            key: 'clients',
            label: 'клиенты не купили',
            hint: `${inputs.active_count} из ${inputs.planned_count} плановых`,
            value: multiplierLoss,
            tone: 'orange',
        });
    }

    const bonus = ratio > 1 ? base * (ratio - 1) : 0;
    const maxLoss = Math.max(1, ...losses.map((l) => l.value), bonus);

    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <HStack justify="space-between" align="baseline" mb={4} flexWrap="wrap" gap={2}>
                <Text fontSize="xs" color="fg.muted" fontWeight="500">Почему столько</Text>
                <HStack gap={2} fontSize="xs" color="fg.muted">
                    <Text>потолок месяца</Text>
                    <Text fontWeight="700" color="fg">{fmtRub0(kpi.max_amount)}</Text>
                </HStack>
            </HStack>

            <Row label="Полная премия" hint="план закрыт, все платят в срок, клиенты на месте" value={fmtRub0(base)} strong />

            <VStack align="stretch" gap={3} my={4} pl={1}>
                {bonus >= 1 && (
                    <LossBar label="сверх плана" hint={`${Math.round(ratio * 100)} % выполнения`} value={bonus} maxValue={maxLoss} tone="green" sign="+" />
                )}
                {losses.map((l) => (
                    <LossBar key={l.key} label={l.label} hint={l.hint} value={l.value} maxValue={maxLoss} tone={l.tone} sign="−" />
                ))}
                {losses.length === 0 && bonus < 1 && (
                    <Text fontSize="sm" color="green.fg">Ничего не мешает — премия полная.</Text>
                )}
            </VStack>

            <Row label="Сейчас" value={fmtRub0(amount)} strong tone={amount >= base ? 'green' : undefined} />
        </Box>
    );
}

const Row = ({ label, hint, value, strong, tone }) => (
    <HStack justify="space-between" align="baseline" gap={3}>
        <Box>
            <Text fontSize="sm" fontWeight={strong ? 700 : 500}>{label}</Text>
            {hint && <Text fontSize="10px" color="fg.muted">{hint}</Text>}
        </Box>
        <Text
            fontSize={strong ? 'xl' : 'md'}
            fontWeight={strong ? 800 : 600}
            fontVariantNumeric="tabular-nums"
            color={tone ? `${tone}.fg` : undefined}
        >
            {value}
        </Text>
    </HStack>
);

const LossBar = ({ label, hint, value, maxValue, tone, sign }) => (
    <Box>
        <HStack justify="space-between" align="baseline" gap={3} mb="3px">
            <Text fontSize="sm" color="fg.muted">{label}</Text>
            <Text fontSize="sm" fontWeight="700" color={`${tone}.fg`} whiteSpace="nowrap" fontVariantNumeric="tabular-nums">
                {sign}{fmtRub0(value)}
            </Text>
        </HStack>
        <Box h="8px" bg="bg.muted" borderRadius="full" overflow="hidden">
            <Box h="100%" w={`${Math.min(100, (value / maxValue) * 100)}%`} bg={`${tone}.solid`} borderRadius="full" />
        </Box>
        {hint && <Text fontSize="10px" color="fg.subtle" mt="2px">{hint}</Text>}
    </Box>
);
