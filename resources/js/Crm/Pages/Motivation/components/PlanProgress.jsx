import { Box, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { Alert } from '@/components/ui/alert';
import MetricHint from '@/Crm/Components/MetricHint';
import { fmtPercent, fmtRub } from '../../Salary/components/format';

/**
 * Где я по плану: полоса и четыре строки.
 *
 * «Оплата начинается после» вместо «порог» — сознательно: слово «порог»
 * читается как «пока не дойдёшь — не получишь ничего», а это неверно:
 * П2 и П3 начисляются с первого рубля. Подсказка рядом говорит об этом прямо.
 */
export default function PlanProgress({ plan, days }) {
    if (!plan || plan.amount === null || plan.amount === undefined) {
        return (
            <Alert
                status="warning"
                title="Личный план на месяц не утверждён"
            >
                Показатель П1 не начисляется, пока план не утверждён приказом. Продажи новым партнёрам и фокус-товары считаются.
            </Alert>
        );
    }

    const percent = Math.max(0, Math.min(1.5, plan.percent ?? 0));
    const thresholdShare = plan.amount > 0 ? Math.min(1, (plan.threshold ?? 0) / plan.amount) : 0;
    const reached = plan.to_threshold === 0;

    const rows = [
        { label: 'Личный план месяца', value: fmtRub(plan.amount, 0), hint: plan.reduced_by_absence
            ? `План уменьшен пропорционально отработанным дням: ${days?.worked ?? '—'} из ${days?.working_total ?? '—'} (п. 10.1). Исходный план ${fmtRub(plan.original, 0)}.`
            : null },
        { label: 'Оплата начинается после', value: fmtRub(plan.threshold, 0), hint: 'Порог оплаты — доля личного плана. Ниже него не начисляется только П1: продажи новым партнёрам и фокус-товары оплачиваются с первого рубля.' },
        { label: 'Отгружено базе с начала месяца', value: fmtRub(plan.shipped, 0), hint: 'Отгрузки закреплённой базы за вычетом возвратов. Отгрузки новым партнёрам сюда не входят — они в П2.' },
        { label: reached ? 'До конца плана осталось' : 'До начала оплаты осталось', value: fmtRub(reached ? plan.remaining : plan.to_threshold, 0), hint: null },
    ];

    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={{ base: 4, md: 5 }}>
            <HStack justify="space-between" mb={3} flexWrap="wrap" gap={2}>
                <Text fontWeight="700">Где я по плану</Text>
                <Text fontSize="sm" color={reached ? 'green.fg' : 'fg.muted'} fontWeight="600">
                    {fmtPercent(plan.percent ?? 0, 0)} плана
                </Text>
            </HStack>

            <Box position="relative" h="10px" bg="bg.muted" borderRadius="full" overflow="hidden" mb={1}>
                <Box position="absolute" left={0} top={0} bottom={0} w={`${Math.min(100, percent * 100)}%`} bg={reached ? 'green.solid' : 'blue.solid'} borderRadius="full" transition="width 0.4s" />
                <Box position="absolute" top={0} bottom={0} left={`${thresholdShare * 100}%`} w="2px" bg="fg" opacity={0.5} aria-hidden />
            </Box>
            <HStack justify="space-between" fontSize="xs" color="fg.subtle" mb={4}>
                <Text>0</Text>
                <Text>оплата начинается ↑</Text>
                <Text>план</Text>
            </HStack>

            <SimpleGrid columns={{ base: 1, sm: 2 }} gap={3}>
                {rows.map((row) => (
                    <VStack key={row.label} align="start" gap={0}>
                        <HStack gap={1} fontSize="xs" color="fg.muted">
                            <Text>{row.label}</Text>
                            {row.hint && <MetricHint text={row.hint} />}
                        </HStack>
                        <Text fontWeight="700" fontVariantNumeric="tabular-nums">{row.value}</Text>
                    </VStack>
                ))}
            </SimpleGrid>
        </Box>
    );
}
