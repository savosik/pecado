import { Box, Text, VStack } from '@chakra-ui/react';
import { ProgressBar, ProgressRoot } from '@/components/ui/progress';
import { Tooltip } from '@/components/ui/tooltip';

const money = (value) => new Intl.NumberFormat('ru-RU', {
    maximumFractionDigits: 0,
}).format(Math.round(value || 0));

/**
 * Цвет выполнения.
 *
 * Пороги грубые намеренно: колонка отвечает на вопрос «успеваем или нет»,
 * а точная цифра живёт в отчёте продаж.
 */
function palette(percent) {
    if (percent === null) return 'gray';
    if (percent < 70) return 'red';
    if (percent < 95) return 'orange';

    return 'green';
}

/**
 * Ячейка «План / факт» за текущий месяц.
 *
 * Факт приходит из ShipmentAnalyticsService — той же цифрой, что в /crm/analytics.
 * Считать его здесь вторым способом нельзя: расхождение этих чисел — баг.
 *
 * @param {{plan: number|null, fact: number, percent: number|null}|null} value
 */
export default function PlanFactCell({ value }) {
    if (!value) return null;

    const { plan, fact, percent } = value;

    if (plan === null) {
        return (
            <Tooltip content={`Факт за месяц: ${money(fact)} ₽. План на месяц не задан.`} openDelay={300}>
                <VStack align="start" gap={0.5}>
                    <Text fontSize="xs" color="fg.muted">без плана</Text>
                    <Text fontSize="10px" color="fg.muted">{money(fact)} ₽</Text>
                </VStack>
            </Tooltip>
        );
    }

    return (
        <Tooltip
            content={`Факт ${money(fact)} ₽ из плана ${money(plan)} ₽ за текущий месяц (отгрузки в рублях по дате документа 1С)`}
            openDelay={300}
        >
            <VStack align="stretch" gap={0.5} minW="110px">
                <Box>
                    <Text fontSize="xs" fontWeight="600" color={`${palette(percent)}.fg`}>
                        {percent === null ? '—' : `${percent}%`}
                    </Text>
                </Box>
                <ProgressRoot
                    value={Math.min(percent ?? 0, 100)}
                    size="xs"
                    colorPalette={palette(percent)}
                >
                    <ProgressBar />
                </ProgressRoot>
                <Text fontSize="10px" color="fg.muted">
                    {money(fact)} / {money(plan)} ₽
                </Text>
            </VStack>
        </Tooltip>
    );
}
