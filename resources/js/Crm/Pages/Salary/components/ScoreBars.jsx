import { Badge, Box, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { ProgressBar, ProgressRoot } from '@/components/ui/progress';
import { fmtCompact, fmtFactor, fmtPercent, plural } from './format';

/**
 * Два показателя месяца обычными прогресс-барами.
 *
 * Спидометры на самодельном SVG отсюда убраны: стрелка и подписи порогов
 * наезжали друг на друга. Здесь всё раскладывает вёрстка, а пороги показаны
 * тем, что их и объясняет, — ступенями лестницы в виде чипов: пройденные
 * закрашены, текущая обведена, следующая подписана «сколько осталось».
 */
export default function ScoreBars({ calculation }) {
    const inputs = calculation.inputs;
    const meta = (calculation.breakdown?.components ?? [])
        .find((c) => c.key === 'kpi_bonus')?.children?.find((c) => c.key === 'active_clients')?.meta ?? {};

    const planShare = inputs.percent ?? 0;
    const planTone = planShare >= 1 ? 'green' : planShare >= 0.8 ? 'blue' : 'orange';

    const ladder = meta.ladder ?? [];
    const share = inputs.active_share ?? 0;
    const current = meta.step?.from_share;
    const next = meta.next_step;

    return (
        <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
            <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                <HStack justify="space-between" align="baseline" mb={1}>
                    <Text fontSize="xs" color="fg.muted" fontWeight="500">План по выручке</Text>
                    <Text fontSize="2xl" fontWeight="800" color={`${planTone}.fg`} fontVariantNumeric="tabular-nums">
                        {Math.round(planShare * 100)} %
                    </Text>
                </HStack>

                <ProgressRoot value={Math.min(100, planShare * 100)} size="lg" colorPalette={planTone} mt={2}>
                    <ProgressBar />
                </ProgressRoot>

                <HStack justify="space-between" mt={2} fontSize="sm" flexWrap="wrap" gap={2}>
                    <Text color="fg.muted">
                        {fmtCompact(inputs.revenue)} {inputs.plan ? `из ${fmtCompact(inputs.plan)}` : '· плана нет'}
                    </Text>
                    {inputs.remaining > 0
                        ? <Text fontWeight="600">до плана {fmtCompact(inputs.remaining)}</Text>
                        : inputs.plan ? <Text fontWeight="600" color="green.fg">план закрыт</Text> : null}
                </HStack>
            </Box>

            <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                <HStack justify="space-between" align="baseline" mb={1}>
                    <Text fontSize="xs" color="fg.muted" fontWeight="500">Активные клиенты</Text>
                    <HStack gap={2} align="baseline">
                        <Text fontSize="2xl" fontWeight="800" fontVariantNumeric="tabular-nums">
                            {inputs.active_count} / {inputs.planned_count}
                        </Text>
                        <Badge colorPalette="purple" variant="subtle">×{fmtFactor(meta.multiplier)}</Badge>
                    </HStack>
                </HStack>

                <ProgressRoot value={Math.min(100, share * 100)} size="lg" colorPalette="purple" mt={2}>
                    <ProgressBar />
                </ProgressRoot>

                {ladder.length > 0 && (
                    <HStack gap={1} mt={3} flexWrap="wrap">
                        {ladder.map((s) => {
                            const isCurrent = current !== undefined && Math.abs(s.from_share - current) < 1e-9;
                            const reached = share + 1e-9 >= s.from_share;

                            return (
                                <VStack
                                    key={s.from_share}
                                    gap={0}
                                    px={2}
                                    py={1}
                                    borderRadius="md"
                                    borderWidth={isCurrent ? '2px' : '1px'}
                                    borderColor={isCurrent ? 'purple.solid' : 'border'}
                                    bg={reached ? 'purple.subtle' : 'transparent'}
                                    minW="58px"
                                >
                                    <Text fontSize="sm" fontWeight="700" color={reached ? 'purple.fg' : 'fg.muted'}>
                                        ×{fmtFactor(s.multiplier)}
                                    </Text>
                                    <Text fontSize="10px" color="fg.subtle">от {fmtPercent(s.from_share, 0)}</Text>
                                </VStack>
                            );
                        })}
                    </HStack>
                )}

                {next && (
                    <Text fontSize="sm" mt={2}>
                        <Text as="span" fontWeight="700">+{next.clients_needed}</Text>
                        <Text as="span" color="fg.muted"> {plural(next.clients_needed, 'клиент', 'клиента', 'клиентов')} → множитель ×{fmtFactor(next.multiplier)}</Text>
                    </Text>
                )}
            </Box>
        </SimpleGrid>
    );
}
