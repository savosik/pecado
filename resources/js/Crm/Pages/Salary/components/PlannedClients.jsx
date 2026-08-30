import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { fmtCompact } from './format';

/**
 * Плановые клиенты месяца: кто уже купил и кто ещё нет.
 *
 * Сначала — те, кого ещё нет в активных: это список на обзвон. Сумма отгрузок
 * и план по каждому — из снимка, тот же источник, что у колонки «План / факт»
 * в списке партнёров.
 */
export default function PlannedClients({ calculation }) {
    const rows = [...(calculation.inputs?.planned_clients ?? [])];

    if (rows.length === 0) {
        return null;
    }

    rows.sort((a, b) => (a.fact > 0) - (b.fact > 0) || b.fact - a.fact || a.name.localeCompare(b.name, 'ru'));
    const inactive = rows.filter((r) => !(r.fact > 0)).length;

    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <HStack justify="space-between" mb={3} flexWrap="wrap" gap={2}>
                <Text fontSize="xs" color="fg.muted" fontWeight="500">Плановые клиенты</Text>
                {inactive > 0 && (
                    <Text fontSize="xs" color="orange.fg">без отгрузки — {inactive}</Text>
                )}
            </HStack>
            <VStack align="stretch" gap={1} maxH="360px" overflowY="auto">
                {rows.map((r) => {
                    const active = r.fact > 0;

                    return (
                        <HStack key={r.id} justify="space-between" gap={3} py={1} fontSize="sm">
                            <HStack gap={2} minW={0}>
                                <Box w="8px" h="8px" borderRadius="full" bg={active ? 'green.solid' : 'border'} flexShrink={0} />
                                <Text lineClamp={1}>{r.name}</Text>
                            </HStack>
                            <HStack gap={2} whiteSpace="nowrap">
                                <Text fontVariantNumeric="tabular-nums" color={active ? undefined : 'fg.muted'}>
                                    {active ? fmtCompact(r.fact) : '—'}
                                </Text>
                                {r.plan !== null && r.plan !== undefined && (
                                    <Text fontSize="xs" color="fg.muted">из {fmtCompact(r.plan)}</Text>
                                )}
                                {!active && <Badge size="xs" variant="subtle" colorPalette="orange">ждём</Badge>}
                            </HStack>
                        </HStack>
                    );
                })}
            </VStack>
        </Box>
    );
}
