import { Badge, Box, HStack, SimpleGrid, Text } from '@chakra-ui/react';
import { LuFileWarning, LuTrendingUp, LuUsers } from 'react-icons/lu';
import { fmtSigned } from './format';

const KIND = {
    clients: { icon: LuUsers, palette: 'blue', label: 'клиенты' },
    invoice: { icon: LuFileWarning, palette: 'orange', label: 'оплата' },
    revenue: { icon: LuTrendingUp, palette: 'green', label: 'выручка' },
};

/**
 * Рычаги: что сделать и сколько это даст в рублях — по убыванию выигрыша.
 *
 * Каждый совет посчитан сервером тем же калькулятором с одной правкой входов,
 * поэтому цифра — не оценка «примерно», а ровно то, что изменится в премии.
 */
export default function LeverCards({ advice }) {
    const rows = advice ?? [];

    if (rows.length === 0) {
        return null;
    }

    return (
        <Box>
            <Text fontSize="xs" color="fg.muted" fontWeight="500" mb={2}>Что можно поднять</Text>
            <SimpleGrid columns={{ base: 1, md: 2, xl: 3 }} gap={3}>
                {rows.map((row) => {
                    const meta = KIND[row.kind] ?? KIND.revenue;
                    const Icon = meta.icon;

                    return (
                        <Box
                            key={row.key}
                            bg="bg.panel"
                            borderWidth="1px"
                            borderColor="border"
                            borderRadius="xl"
                            p={4}
                            borderTopWidth="3px"
                            borderTopColor={`${meta.palette}.solid`}
                        >
                            <HStack justify="space-between" align="start" gap={3}>
                                <HStack gap={2} align="start" minW={0}>
                                    <Box color={`${meta.palette}.fg`} mt="2px" flexShrink={0}><Icon size={16} /></Box>
                                    <Text fontWeight="600" fontSize="sm">{row.title}</Text>
                                </HStack>
                                <Badge colorPalette={meta.palette} variant="subtle" whiteSpace="nowrap" fontVariantNumeric="tabular-nums">
                                    {row.kind === 'invoice' ? `−${fmtSigned(row.gain).replace('+', '')}` : fmtSigned(row.gain)}
                                </Badge>
                            </HStack>
                            <Text fontSize="xs" color="fg.muted" mt={2}>{row.detail}</Text>
                        </Box>
                    );
                })}
            </SimpleGrid>
        </Box>
    );
}
