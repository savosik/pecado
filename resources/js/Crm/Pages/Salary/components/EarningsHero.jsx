import { Badge, Box, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuLock, LuRefreshCw } from 'react-icons/lu';
import { useCountUp } from './useCountUp';
import { fmtAgo, fmtRub, fmtRub0 } from './format';

const STATUS_PALETTE = { draft: 'blue', approved: 'green', paid: 'gray' };

/**
 * Hero: одно число крупно — сколько заработано на эту минуту — и из чего оно.
 *
 * Число «доезжает» при обновлении; всё остальное статично. Замороженный месяц
 * подписан «Итог за …» и замком — чтобы не путать с живым черновиком.
 */
export default function EarningsHero({ calculation, monthLabel, refreshing }) {
    const total = useCountUp(calculation.total);
    const frozen = calculation.is_frozen;
    const components = calculation.breakdown?.components ?? [];
    const kpi = calculation.kpi;

    return (
        <Box
            bg="bg.panel"
            borderWidth="1px"
            borderColor="border"
            borderRadius="2xl"
            p={{ base: 5, md: 7 }}
            position="relative"
            overflow="hidden"
        >
            <Box
                position="absolute"
                inset={0}
                bgGradient="to-br"
                gradientFrom={`${STATUS_PALETTE[calculation.status] ?? 'blue'}.subtle`}
                gradientTo="transparent"
                opacity={0.6}
                pointerEvents="none"
            />

            <VStack align="stretch" gap={4} position="relative">
                <HStack justify="space-between" align="start" flexWrap="wrap" gap={2}>
                    <Text fontSize="sm" color="fg.muted">
                        {frozen ? `Итог за ${monthLabel.toLowerCase()}` : `Заработано на эту минуту · ${monthLabel.toLowerCase()}`}
                    </Text>
                    <HStack gap={2}>
                        <Badge colorPalette={STATUS_PALETTE[calculation.status] ?? 'blue'} variant="subtle" size="sm">
                            {frozen && <LuLock size={11} />}
                            {calculation.status_label}
                            {calculation.version > 1 && ` · версия ${calculation.version}`}
                        </Badge>
                    </HStack>
                </HStack>

                <Text
                    fontSize={{ base: '4xl', md: '6xl' }}
                    fontWeight="800"
                    lineHeight="1"
                    letterSpacing="-0.02em"
                    fontVariantNumeric="tabular-nums"
                >
                    {fmtRub(total, total % 1 === 0 ? 0 : 2)}
                </Text>

                <SimpleGrid columns={{ base: 2, md: components.length || 1 }} gap={3}>
                    {components.map((c) => (
                        <Box key={c.key} borderLeftWidth="3px" borderColor={c.amount < 0 ? 'red.solid' : 'blue.solid'} pl={3}>
                            <Text fontSize="xs" color="fg.muted">{c.label}</Text>
                            <Text fontWeight="700" fontVariantNumeric="tabular-nums">{fmtRub0(c.amount)}</Text>
                        </Box>
                    ))}
                </SimpleGrid>

                <HStack justify="space-between" flexWrap="wrap" gap={2} fontSize="xs" color="fg.subtle">
                    <HStack gap={1}>
                        {refreshing && <LuRefreshCw size={12} />}
                        <Text>
                            {frozen && calculation.approved_at
                                ? `Утверждено ${fmtAgo(calculation.approved_at)}`
                                : `Обновлено ${fmtAgo(calculation.computed_at)}`}
                        </Text>
                    </HStack>
                    {kpi && kpi.max_amount > 0 && !frozen && (
                        <Text>Потолок KPI-премии в этом месяце — {fmtRub0(kpi.max_amount)}</Text>
                    )}
                </HStack>
            </VStack>
        </Box>
    );
}
