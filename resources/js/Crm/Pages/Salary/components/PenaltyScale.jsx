import { Box, HStack, Text, VStack } from '@chakra-ui/react';
import { fmtCompact, fmtRub0, plural } from './format';

/**
 * Шкала дисциплины: сколько было бы без просрочек, сколько сейчас, сколько при худшем.
 *
 * Одна полоса вместо трёх абзацев: слева — потеря, которая уже случилась,
 * справа — та, что ещё может случиться. Цена вопроса подписана рублями,
 * а не процентами формулы.
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

    const min = Math.min(worst, current, clean);
    const max = Math.max(worst, current, clean);
    const span = Math.max(1, max - min);
    const pos = (v) => `${((v - min) / span) * 100}%`;

    const nothingHappened = lost < 1 && risk < 1;

    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <Text fontSize="xs" color="fg.muted" fontWeight="500" mb={4}>Финансовая дисциплина клиентов</Text>

            {nothingHappened ? (
                <HStack gap={2}>
                    <Box w="10px" h="10px" borderRadius="full" bg="green.solid" />
                    <Text fontSize="sm">Все платят вовремя — премия не теряет ни рубля.</Text>
                </HStack>
            ) : (
                <>
                    <Box position="relative" h="46px" mt={6} mb={2}>
                        {/* Полоса: потерянное — красным, под угрозой — оранжевым, чистое — зелёным */}
                        <Box position="absolute" left="0" right="0" top="14px" h="10px" borderRadius="full" bg="bg.muted" />
                        <Box position="absolute" left={pos(worst)} right={`calc(100% - ${pos(current)})`} top="14px" h="10px" bg="orange.solid" opacity={0.55} />
                        <Box position="absolute" left={pos(current)} right={`calc(100% - ${pos(clean)})`} top="14px" h="10px" bg="red.solid" opacity={0.55} />

                        <Marker at={pos(current)} color="blue.solid" label="сейчас" value={fmtRub0(current)} strong />
                        {lost >= 1 && <Marker at={pos(clean)} color="green.solid" label="без просрочек" value={fmtRub0(clean)} align="right" />}
                        {risk >= 1 && <Marker at={pos(worst)} color="orange.solid" label="если не соберу" value={fmtRub0(worst)} align="left" />}
                    </Box>

                    <HStack gap={5} mt={5} flexWrap="wrap">
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
                    </HStack>
                </>
            )}
        </Box>
    );
}

const Marker = ({ at, color, label, value, align = 'center', strong = false }) => (
    <VStack
        position="absolute"
        left={at}
        top="0"
        gap={0}
        transform={align === 'left' ? 'translateX(0)' : align === 'right' ? 'translateX(-100%)' : 'translateX(-50%)'}
        align={align === 'left' ? 'start' : align === 'right' ? 'end' : 'center'}
        minW="90px"
    >
        <Text fontSize="10px" color="fg.muted" whiteSpace="nowrap">{label}</Text>
        <Text fontSize="sm" fontWeight={strong ? 800 : 600} whiteSpace="nowrap" fontVariantNumeric="tabular-nums">{value}</Text>
        <Box w="2px" h="12px" bg={color} borderRadius="full" mt="1px" />
    </VStack>
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
