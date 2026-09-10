import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { LuLock, LuRefreshCw } from 'react-icons/lu';
import { useCountUp } from '../../Salary/components/useCountUp';
import { fmtAgo, fmtRub } from '../../Salary/components/format';

const STATUS_PALETTE = { draft: 'blue', approved: 'green', paid: 'gray' };

/**
 * Доход за месяц: одно число крупно, с плавным досчётом при обновлении.
 *
 * Под ним — когда пересчитано и признак «предварительно» либо «утверждено».
 * Замороженный месяц подписан замком, чтобы не путать с живым черновиком.
 */
export default function IncomeHero({ calculation, monthLabel, refreshing }) {
    const total = useCountUp(calculation.total);
    const frozen = calculation.frozen;
    const palette = STATUS_PALETTE[calculation.status] ?? 'blue';

    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="2xl" p={{ base: 5, md: 7 }} position="relative" overflow="hidden">
            <Box position="absolute" inset={0} bgGradient="to-br" gradientFrom={`${palette}.subtle`} gradientTo="transparent" opacity={0.6} pointerEvents="none" />

            <VStack align="stretch" gap={4} position="relative">
                <HStack justify="space-between" align="start" flexWrap="wrap" gap={2}>
                    <Text fontSize="sm" color="fg.muted">
                        {frozen ? `Итог за ${monthLabel.toLowerCase()}` : `Заработано на эту минуту · ${monthLabel.toLowerCase()}`}
                    </Text>
                    <Badge colorPalette={palette} variant="subtle" size="sm">
                        {frozen && <LuLock size={11} />}
                        {frozen ? calculation.status_label : 'Предварительно'}
                        {calculation.version > 1 && ` · версия ${calculation.version}`}
                    </Badge>
                </HStack>

                <Text fontSize={{ base: '4xl', md: '6xl' }} fontWeight="800" lineHeight="1" letterSpacing="-0.02em" fontVariantNumeric="tabular-nums">
                    {fmtRub(total, 0)}
                </Text>

                <HStack gap={2} color="fg.subtle" fontSize="xs" flexWrap="wrap">
                    {refreshing ? <LuRefreshCw size={12} className="spin" /> : null}
                    <Text>
                        {frozen
                            ? `Утверждено ${fmtAgo(calculation.approved_at)}`
                            : `Пересчитано ${fmtAgo(calculation.computed_at)}`}
                    </Text>
                    {calculation.variable_part?.capped && (
                        <Badge colorPalette="purple" variant="subtle" size="xs">Достигнут предельный размер переменной части</Badge>
                    )}
                    {calculation.variable_part?.floored && (
                        <Badge colorPalette="orange" variant="subtle" size="xs">Вычет превысил показатели — переменная часть 0 ₽</Badge>
                    )}
                </HStack>
            </VStack>
        </Box>
    );
}
