import { Box, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { fmtCompact, fmtRub0, plural } from './format';

/**
 * Три состояния дохода по дисциплине клиентов — тремя строками с барами.
 *
 * Раньше это была одна полоса с маркерами на абсолютных координатах: подписи
 * наезжали друг на друга и на края. Здесь ширину баров считает вёрстка,
 * пересечься нечему.
 */
export default function PenaltyScale({ scale }) {
    if (!scale) {
        return null;
    }

    const clean = Number(scale.clean ?? 0);
    const current = Number(scale.current ?? 0);
    const worst = Number(scale.worst ?? current);
    const lost = Number(scale.lost ?? 0);
    const risk = Math.max(0, current - worst);
    const max = Math.max(clean, current, worst, 1);

    if (lost < 1 && risk < 1) {
        return (
            <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                <Text fontSize="xs" color="fg.muted" fontWeight="500" mb={2}>Дисциплина клиентов</Text>
                <HStack gap={2}>
                    <Box w="10px" h="10px" borderRadius="full" bg="green.solid" />
                    <Text fontSize="sm">Все платят вовремя — премия не теряет ни рубля.</Text>
                </HStack>
            </Box>
        );
    }

    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <Text fontSize="xs" color="fg.muted" fontWeight="500" mb={4}>Дисциплина клиентов</Text>

            <VStack align="stretch" gap={3}>
                <Bar label="Если бы все платили в срок" value={clean} max={max} tone="green" />
                <Bar label="Сейчас" value={current} max={max} tone="blue" strong />
                {risk >= 1 && <Bar label="Если не соберу долги до конца месяца" value={worst} max={max} tone="orange" />}
            </VStack>

            <SimpleGrid columns={{ base: 1, sm: 2 }} gap={4} mt={5}>
                {lost >= 1 && (
                    <Fact tone="red" value={`−${fmtRub0(lost)}`} label="уже потеряно на задержках оплат" />
                )}
                {risk >= 1 && (
                    <Fact
                        tone="orange"
                        value={`−${fmtRub0(risk)}`}
                        label={`под угрозой: ${scale.at_risk_count} ${plural(scale.at_risk_count, 'накладная', 'накладные', 'накладных')} на ${fmtCompact(scale.at_risk_amount)}`}
                    />
                )}
            </SimpleGrid>
        </Box>
    );
}

const Bar = ({ label, value, max, tone, strong = false }) => (
    <Box>
        <HStack justify="space-between" align="baseline" mb="3px" gap={3}>
            <Text fontSize="sm" color={strong ? 'fg' : 'fg.muted'} fontWeight={strong ? 600 : 400}>{label}</Text>
            <Text fontSize="sm" fontWeight={strong ? 800 : 600} fontVariantNumeric="tabular-nums" whiteSpace="nowrap">
                {fmtRub0(value)}
            </Text>
        </HStack>
        <Box h={strong ? '12px' : '8px'} bg="bg.muted" borderRadius="full" overflow="hidden">
            <Box h="100%" w={`${Math.max(2, (value / max) * 100)}%`} bg={`${tone}.solid`} borderRadius="full" />
        </Box>
    </Box>
);

const Fact = ({ tone, value, label }) => (
    <HStack gap={2} align="start">
        <Box w="3px" alignSelf="stretch" bg={`${tone}.solid`} borderRadius="full" />
        <Box>
            <Text fontSize="lg" fontWeight="800" color={`${tone}.fg`} lineHeight="1.1">{value}</Text>
            <Text fontSize="xs" color="fg.muted">{label}</Text>
        </Box>
    </HStack>
);
